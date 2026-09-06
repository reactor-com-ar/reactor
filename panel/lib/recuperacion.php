<?php

declare(strict_types=1);

/**
 * Recuperacion de contrasena por enlace de correo.
 *
 * Lo usan las dos paginas publicas de recuperar/ (pedir el enlace y
 * restablecer), que no pasan por api/bootstrap.php porque justamente el
 * que las abre no tiene sesion.
 *
 * QUE REEMPLAZA: el legacy resolvia esto en reactor-app/sesion/recuperar.php
 * mandando LA CONTRASENA en el cuerpo del mail (puede hacerlo porque
 * `usuarios.contrasena` es cifrado reversible, no hash). Aca sale un enlace
 * de un solo uso y la persona elige una contrasena nueva: la contrasena
 * vieja no viaja por correo, el enlace vence y se puede invalidar. El legacy
 * NO se toca y sigue funcionando sobre la misma tabla `usuarios`.
 *
 * TODAS LAS FECHAS SE COMPARAN CONTRA EL RELOJ DE LA BASE (`NOW()`), nunca
 * contra uno de PHP. Los dos relojes no estan alineados (PHP en UTC, la
 * sesion de MySQL en -03:00; ver panel/CLAUDE.md) y ademas estas paginas no
 * pasan por api/bootstrap.php, que es el unico lugar que llama a
 * date_default_timezone_set(). Un enlace de 60 minutos evaluado con el
 * reloj equivocado nace vencido o dura cuatro horas.
 */

require_once dirname(__DIR__, 2) . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/databox.php';
require_once __DIR__ . '/base_url.php';
require_once dirname(__DIR__) . '/api/legacy_crypto.php';

/** Vigencia del enlace. Se guarda en `recuperaciones.expira` al emitir. */
const RECUPERACION_TTL_MINUTOS = 60;

/** Bytes del token. 32 -> 43 chars en base64url, dentro del char(64) del hash. */
const RECUPERACION_TOKEN_BYTES = 32;

/** Cupo por cuenta y por IP en la ultima hora. Ver recuperacionCupo(). */
const RECUPERACION_CUPO_CUENTA = 3;
const RECUPERACION_CUPO_ORIGEN = 10;

/**
 * Limites de la contrasena nueva, los mismos que app/api/contrasena.php:
 * `usuarios.contrasena` es varchar(50) y guarda el base64 del cifrado
 * legacy (4*ceil(n/3) chars para n bytes). Con n=36 son 48 y entra; con
 * n=37 son 52 y MySQL la truncaria, dejando a la persona afuera de su
 * cuenta justo despues de "recuperarla".
 */
const RECUPERACION_CONTRASENA_MIN = 6;
const RECUPERACION_CONTRASENA_MAX = 36;

/** Valores de `usuarios.habilitado` que dejan entrar, igual que api/login.php. */
const RECUPERACION_HABILITADOS = ['S', '1', 'Y'];

/* ------------------------------------------------------------------ */
/* Token                                                               */
/* ------------------------------------------------------------------ */

/**
 * Token nuevo: 32 bytes de CSPRNG en base64url (43 chars, sin relleno).
 * Es la unica credencial del enlace, asi que no se deriva de nada del
 * usuario ni se acorta.
 */
function recuperacionTokenNuevo(): string
{
    return rtrim(strtr(base64_encode(random_bytes(RECUPERACION_TOKEN_BYTES)), '+/', '-_'), '=');
}

/**
 * Lo que se guarda en `recuperaciones.token`. El token en claro no queda
 * escrito en ningun lado: quien lea la base no puede armar un enlace valido.
 * Sin salt ni algoritmo lento a proposito -- son 256 bits aleatorios, no una
 * contrasena: no hay diccionario que atacar.
 */
function recuperacionHash(string $token): string
{
    return hash('sha256', $token);
}

/** Enlace que recibe la persona por correo. */
function recuperacionUrl(string $token): string
{
    return panelBaseUrl() . '/recuperar/restablecer?t=' . rawurlencode($token);
}

/** Forma valida de un token, para cortar antes de tocar la base. */
function recuperacionTokenValido(string $token): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_\-]{43}$/', $token);
}

/**
 * IP del solicitante, para el cupo. Se lee de REMOTE_ADDR y no de
 * X-Forwarded-For: el header lo pone el cliente y falsearlo saltearia el
 * cupo. En produccion nginx proxea al contenedor, asi que puede ser la del
 * proxy y el cupo por origen queda global -- por eso es el flojo (10/hora)
 * y el que de verdad protege una cuenta es el de cuenta (3/hora).
 */
function recuperacionOrigen(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
    return $ip === '' ? null : mb_substr($ip, 0, 45);
}

/* ------------------------------------------------------------------ */
/* Emision                                                             */
/* ------------------------------------------------------------------ */

/**
 * Cuenta que corresponde a lo que se tipeo, o null.
 *
 * Se busca por `usuario` o por `correo`, porque la persona que perdio la
 * contrasena no tiene por que acordarse de con cual entra (en las cuentas
 * creadas por invitacion son el mismo valor). Como `usuarios` no tiene
 * UNIQUE en ninguna de las dos columnas, se toma la primera por id -- el
 * mismo criterio que invitacion/aceptar.php.
 */
function recuperacionUsuarioPorIdentificador(string $identificador): ?array
{
    $id = trim($identificador);
    if ($id === '' || mb_strlen($id) > 100) {
        return null;
    }

    // Un placeholder por columna: con EMULATE_PREPARES=false PDO no admite
    // repetir el mismo nombre en un statement (HY093).
    $stmt = db()->prepare(
        'SELECT id, nombre, usuario, correo, habilitado
           FROM usuarios
          WHERE usuario = :u OR LOWER(correo) = :c
          ORDER BY id
          LIMIT 1'
    );
    $stmt->execute([':u' => $id, ':c' => mb_strtolower($id)]);

    return $stmt->fetch() ?: null;
}

/** Misma regla que api/login.php: sin esto no hay nada que recuperar. */
function recuperacionCuentaActiva(array $usuario): bool
{
    $habilitado = strtoupper(trim((string) ($usuario['habilitado'] ?? '')));
    return in_array($habilitado, RECUPERACION_HABILITADOS, true);
}

/**
 * Cupo de la ultima hora. Frena el uso del formulario como maquina de
 * mandar correos a una casilla ajena (y, de paso, la fuerza bruta de
 * tokens: cada pedido genera uno solo).
 *
 * La ventana se cuenta con NOW() de la base por lo dicho arriba del reloj.
 */
function recuperacionCupo(int $usuarioId, ?string $origen): bool
{
    $porCuenta = db()->prepare(
        'SELECT COUNT(*) FROM recuperaciones
          WHERE usuario = :u AND solicitada > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $porCuenta->execute([':u' => $usuarioId]);
    if ((int) $porCuenta->fetchColumn() >= RECUPERACION_CUPO_CUENTA) {
        return false;
    }

    if ($origen === null) {
        return true;
    }

    $porOrigen = db()->prepare(
        'SELECT COUNT(*) FROM recuperaciones
          WHERE origen = :o AND solicitada > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $porOrigen->execute([':o' => $origen]);

    return (int) $porOrigen->fetchColumn() < RECUPERACION_CUPO_ORIGEN;
}

/**
 * Emite el enlace y lo manda por correo.
 *
 * La fila y el envio van en una transaccion, igual que el alta de
 * invitaciones: un token que nadie recibio no le sirve a nadie y ademas
 * consume cupo. Si el microservicio no acepto el mensaje, se revierte.
 *
 * @return array{ok:bool,error:?string}
 */
function recuperacionEmitir(array $usuario): array
{
    $correo = trim((string) ($usuario['correo'] ?? ''));
    if ($correo === '') {
        // Cuenta sin correo: no hay a donde mandar el enlace. El llamador
        // igual muestra el mensaje neutro (ver recuperar/index.php).
        return ['ok' => false, 'error' => 'La cuenta no tiene un correo cargado'];
    }

    $pdo    = db();
    $token  = recuperacionTokenNuevo();
    $nombre = trim((string) ($usuario['nombre'] ?? ''));

    $pdo->beginTransaction();
    try {
        $alta = $pdo->prepare(
            'INSERT INTO recuperaciones (usuario, token, correo, origen, solicitada, expira)
             VALUES (:usuario, :token, :correo, :origen, NOW(), DATE_ADD(NOW(), INTERVAL :ttl MINUTE))'
        );
        $alta->execute([
            ':usuario' => (int) $usuario['id'],
            ':token'   => recuperacionHash($token),
            ':correo'  => $correo,
            ':origen'  => recuperacionOrigen(),
            ':ttl'     => RECUPERACION_TTL_MINUTOS,
        ]);

        $envio = databoxCorreoEncolar([
            'destino'      => $correo,
            'destinatario' => $nombre,
            'asunto'       => 'Recuperá tu contraseña de Reactor Panel',
            'cuerpo'       => recuperacionCuerpoCorreo($nombre, recuperacionUrl($token)),
            'prioridad'    => 3,
            'tags'         => 'recuperacion',
        ]);

        if (!$envio['ok']) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => (string) $envio['error']];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Cuerpo HTML del correo.
 *
 * Es un fragmento, no un documento: la plantilla `reactor` de Databox lo
 * inserta en {cuerpo} y aporta el encabezado y el pie. Si se desactiva la
 * plantilla (DATABOX_PLANTILLA= en el .env) el fragmento igual se lee bien.
 *
 * El enlace va tambien como texto plano ademas del <a>: los clientes que
 * bloquean el HTML dejan la persona sin nada que copiar.
 */
function recuperacionCuerpoCorreo(string $nombre, string $url): string
{
    $hola    = $nombre !== '' ? '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>' : '';
    $urlHtml = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

    return $hola
         . '<p>Recibimos un pedido para restablecer la contraseña de tu cuenta de '
         . '<strong>Reactor Panel</strong>.</p>'
         . '<p>Para elegir una contraseña nueva, abrí este enlace:</p>'
         . '<p><a href="' . $urlHtml . '">' . $urlHtml . '</a></p>'
         . '<p>El enlace vence en ' . RECUPERACION_TTL_MINUTOS . ' minutos y se puede usar una sola vez.</p>'
         . '<p>Si no lo pediste, podés ignorar este mensaje: tu contraseña actual sigue siendo válida.</p>';
}

/* ------------------------------------------------------------------ */
/* Uso                                                                 */
/* ------------------------------------------------------------------ */

/**
 * Lee el pedido por el token del enlace, o null si no existe.
 *
 * `vigente` y `activa` los resuelve el SELECT, no PHP: la comparacion de
 * `expira` tiene que ir contra NOW() de la base.
 */
function recuperacionPorToken(string $token): ?array
{
    if (!recuperacionTokenValido($token)) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT r.id, r.usuario, r.correo, r.solicitada, r.expira, r.usada,
                (r.expira > NOW()) AS vigente,
                u.nombre AS usuario_nombre, u.usuario AS cuenta, u.habilitado
           FROM recuperaciones r
           JOIN usuarios u ON u.id = r.usuario
          WHERE r.token = :t
          LIMIT 1'
    );
    $stmt->execute([':t' => recuperacionHash($token)]);

    return $stmt->fetch() ?: null;
}

/**
 * Motivo por el que un enlace no se puede usar, o null si sirve.
 * Centralizado para que la pantalla del formulario y la del POST den el
 * mismo mensaje.
 */
function recuperacionMotivoNoVigente(array $rec): ?string
{
    if (trim((string) ($rec['usada'] ?? '')) !== '') {
        return 'Este enlace ya se usó para cambiar la contraseña. Pedí uno nuevo si necesitás volver a cambiarla.';
    }
    if (!(int) ($rec['vigente'] ?? 0)) {
        return 'Este enlace venció. Los enlaces duran ' . RECUPERACION_TTL_MINUTOS . ' minutos: pedí uno nuevo.';
    }
    if (!recuperacionCuentaActiva(['habilitado' => $rec['habilitado'] ?? ''])) {
        return 'La cuenta está deshabilitada. Comunicate con Reactor.';
    }
    return null;
}

/**
 * Guarda la contrasena nueva y cierra el enlace. Todo en una transaccion:
 * el enlace marcado sin la contrasena cambiada deja a la persona sin
 * ninguno de los dos accesos.
 *
 * El `WHERE usada IS NULL AND expira > NOW()` es el candado contra el doble
 * envio (dos pestanas, un reintento del navegador): si no afecta ninguna
 * fila, el enlace ya se habia consumido y no se toca la contrasena.
 *
 * Al final se cierran los OTROS pedidos abiertos de la misma cuenta: si
 * alguien pidio tres enlaces, usar uno invalida los dos que quedaron dando
 * vueltas en la casilla.
 */
function recuperacionConsumir(array $rec, string $contrasena): bool
{
    $pdo = db();

    $pdo->beginTransaction();
    try {
        $cerrar = $pdo->prepare(
            'UPDATE recuperaciones SET usada = NOW()
              WHERE id = :id AND usada IS NULL AND expira > NOW()'
        );
        $cerrar->execute([':id' => (int) $rec['id']]);

        if ($cerrar->rowCount() === 0) {
            $pdo->rollBack();
            return false;
        }

        $guardar = $pdo->prepare('UPDATE usuarios SET contrasena = :c WHERE id = :id');
        $guardar->execute([
            ':c'  => reactor_legacy_encriptar($contrasena),
            ':id' => (int) $rec['usuario'],
        ]);

        $resto = $pdo->prepare(
            'UPDATE recuperaciones SET usada = NOW()
              WHERE usuario = :u AND usada IS NULL'
        );
        $resto->execute([':u' => (int) $rec['usuario']]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return true;
}

/**
 * Mensaje de error de la contrasena nueva, o '' si esta bien.
 * El largo se mide en bytes (strlen) y no en caracteres: el limite real es
 * el varchar(50) de la columna, que cuenta lo que ocupa el base64 del
 * cifrado.
 */
function recuperacionValidarContrasena(string $nueva, string $repetida): string
{
    if ($nueva === '' || $repetida === '') {
        return 'Completá los dos campos.';
    }
    if ($nueva !== $repetida) {
        return 'Las dos contraseñas no coinciden.';
    }
    if (strlen($nueva) < RECUPERACION_CONTRASENA_MIN) {
        return 'La contraseña debe tener al menos ' . RECUPERACION_CONTRASENA_MIN . ' caracteres.';
    }
    if (strlen($nueva) > RECUPERACION_CONTRASENA_MAX) {
        return 'La contraseña no puede superar los ' . RECUPERACION_CONTRASENA_MAX . ' caracteres.';
    }
    if (trim($nueva) === '') {
        return 'La contraseña no puede ser sólo espacios.';
    }
    return '';
}
