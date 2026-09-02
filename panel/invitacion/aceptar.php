<?php

declare(strict_types=1);

/**
 * Aceptacion de una invitacion. Porta reactor-app/invitacion/aceptar.php.
 *
 * QUE SE PIDE: nombre, apellido y celular. El correo NO se pregunta — es el
 * dato con el que se emitio la invitacion y llego hasta aca, asi que ya lo
 * tenemos. Es el espejo del legacy, que emitia por celular y por eso pedia
 * nombre y correo al aceptar.
 *
 * `usuarios` no tiene columna `apellido` (ver db/schema.sql): son dos campos
 * en el formulario porque es lo que la persona espera completar, pero se
 * guardan concatenados en `nombre`, que es como el legacy y el resto del
 * panel leen el nombre completo. No se toca el esquema por esto.
 *
 * DOS CAMINOS, como en el legacy:
 *   - La persona ya tiene cuenta (el correo o el usuario ya existen): no se
 *     crea nada ni se le cambia la contrasena, solo se le da el perfil en
 *     este dominio y entra con las credenciales que ya usaba.
 *   - No tiene cuenta: se crea `usuarios` + `perfiles` y se le entrega una
 *     contrasena generada.
 *
 * A DIFERENCIA DEL LEGACY, el camino "ya tiene cuenta" TAMBIEN cierra la
 * invitacion (estado 3). El legacy da el acceso pero deja la fila en
 * pendiente para siempre, y esas pendientes eternas son las que ensucian el
 * listado del panel.
 */

require __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/api/legacy_crypto.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuid   = (string) ($_POST['uid'] ?? $_GET['uid'] ?? '');

$inv = invitacionPorUuid($uuid);
if ($inv === null) {
    invitacionCorte(
        'Invitación no encontrada',
        'El enlace no corresponde a ninguna invitación. Verificá que lo hayas copiado completo.'
    );
}

$motivo = invitacionMotivoNoVigente($inv);
if ($motivo !== null) {
    invitacionCorte('Invitación no disponible', $motivo);
}

$dominioId     = (int) $inv['dominio'];
$dominioNombre = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . $dominioId);
$correo        = strtolower(trim((string) $inv['correo']));

$error    = '';
$nombre   = '';
$apellido = '';
$celular  = '';

if ($metodo === 'POST') {
    $nombre   = trim((string) ($_POST['nombre']   ?? ''));
    $apellido = trim((string) ($_POST['apellido'] ?? ''));
    $celular  = trim((string) ($_POST['celular']  ?? ''));

    $error = validarDatosInvitado($nombre, $apellido, $celular);

    if ($error === '') {
        $resultado = aceptarInvitacion($inv, $nombre, $apellido, $celular);
        invitacionLayout('Bienvenido', pantallaBienvenida($resultado, $dominioNombre));
    }
}

/* ------------------------------------------------------------------ */
/* Formulario                                                          */
/* ------------------------------------------------------------------ */

$cuerpo = '
    <p class="inv-lead">
        Estás a un paso de sumarte a <strong>' . e($dominioNombre) . '</strong>.
        Completá tus datos para crear tu acceso.
    </p>

    <form method="post" class="login-form" novalidate>
        <input type="hidden" name="uid" value="' . e((string) $inv['uuid']) . '">

        <div class="form-group">
            <label for="inv-nombre">Nombre</label>
            <input type="text" id="inv-nombre" name="nombre" maxlength="60"
                   value="' . e($nombre) . '" autocomplete="given-name" autofocus required>
        </div>

        <div class="form-group">
            <label for="inv-apellido">Apellido</label>
            <input type="text" id="inv-apellido" name="apellido" maxlength="60"
                   value="' . e($apellido) . '" autocomplete="family-name" required>
        </div>

        <div class="form-group">
            <label for="inv-celular">Celular</label>
            <input type="tel" id="inv-celular" name="celular" maxlength="15"
                   value="' . e($celular) . '" autocomplete="tel" required>
        </div>

        <div class="form-group">
            <label for="inv-correo">Correo</label>
            <input type="email" id="inv-correo" value="' . e($correo) . '" readonly
                   title="Es el correo al que se envió la invitación">
        </div>

        ' . ($error !== '' ? '<div class="inv-note inv-note-bad">' . e($error) . '</div>' : '') . '

        <div class="inv-acciones">
            <a class="btn btn-alt" href="./?uid=' . e((string) $inv['uuid']) . '">
                <i class="fa-solid fa-chevron-left"></i> Volver
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-check"></i> Aceptar
            </button>
        </div>
    </form>
';

invitacionLayout('Completá tus datos', $cuerpo);

/* ------------------------------------------------------------------ */
/* Logica                                                              */
/* ------------------------------------------------------------------ */

/** Devuelve el mensaje de error, o '' si los datos estan bien. */
function validarDatosInvitado(string $nombre, string $apellido, string $celular): string
{
    if ($nombre === '' || $apellido === '' || $celular === '') {
        return 'Completá los tres campos para continuar.';
    }
    // `usuarios.nombre` es varchar(100) y guarda "Nombre Apellido".
    if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) {
        return 'El nombre y el apellido no pueden superar 60 caracteres cada uno.';
    }
    if (mb_strlen($celular) > 15) {
        return 'El celular no puede superar 15 caracteres.';
    }
    if (!preg_match('/^[+0-9\s().-]+$/', $celular)) {
        return 'El celular solo admite números y los signos + ( ) - .';
    }
    if (preg_match_all('/\d/', $celular) < 8) {
        return 'El celular parece incompleto: ingresá el número con característica.';
    }
    return '';
}

/**
 * Cierra la invitacion y deja a la persona con acceso al dominio.
 * Todo va en una transaccion: media aceptacion (usuario sin perfil, o
 * invitacion cerrada sin cuenta) es un estado que ninguna pantalla sabe leer.
 *
 * @return array{usuario:string,contrasena:?string,nueva:bool,correo_ok:bool}
 */
function aceptarInvitacion(array $inv, string $nombre, string $apellido, string $celular): array
{
    $pdo           = db();
    $dominioId     = (int) $inv['dominio'];
    $dominioNombre = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . $dominioId);
    $correo        = strtolower(trim((string) $inv['correo']));
    $completo      = $nombre . ' ' . $apellido;

    $pdo->beginTransaction();
    try {
        // La invitacion se cierra con el estado en el WHERE: si dos envios
        // simultaneos entran juntos, solo uno crea la cuenta.
        $cerrar = $pdo->prepare(
            'UPDATE invitaciones
                SET nombre = :nombre, celular = :celular, estado = :aceptada
              WHERE id = :id AND estado = :pendiente'
        );
        $cerrar->execute([
            ':nombre'    => $completo,
            ':celular'   => $celular,
            ':aceptada'  => INVITACION_ACEPTADA,
            ':pendiente' => INVITACION_PENDIENTE,
            ':id'        => (int) $inv['id'],
        ]);
        if ($cerrar->rowCount() === 0) {
            $pdo->rollBack();
            invitacionCorte('Invitación no disponible', 'Esta invitación ya fue resuelta.');
        }

        // Un placeholder por columna: con EMULATE_PREPARES=false PDO no
        // admite repetir el mismo nombre en un statement (HY093).
        $busca = $pdo->prepare(
            'SELECT id FROM usuarios
              WHERE usuario = :u OR LOWER(correo) = :c
              ORDER BY id LIMIT 1'
        );
        $busca->execute([':u' => $correo, ':c' => $correo]);
        $usuarioId = (int) ($busca->fetchColumn() ?: 0);

        $contrasena = null;
        $nueva      = $usuarioId === 0;

        if ($nueva) {
            $contrasena = contrasenaGenerada();
            $alta = $pdo->prepare(
                'INSERT INTO usuarios
                    (uuid, nombre, usuario, autenticacion, contrasena, correo, celular,
                     habilitado, registrante, registrado, dominio)
                 VALUES
                    (:uuid, :nombre, :usuario, :auth, :contrasena, :correo, :celular,
                     :habilitado, :registrante, NOW(), :dominio)'
            );
            $alta->execute([
                ':uuid'        => bin2hex(random_bytes(8)),
                ':nombre'      => $completo,
                ':usuario'     => $correo,
                // 'L' = login con contrasena, igual que el alta del panel.
                ':auth'        => 'L',
                ':contrasena'  => reactor_legacy_encriptar($contrasena),
                ':correo'      => $correo,
                ':celular'     => $celular,
                // 'S' es lo que valida api/login.php (junto con '1' y 'Y').
                ':habilitado'  => 'S',
                ':registrante' => (int) $inv['emisor'] ?: null,
                ':dominio'     => $dominioId,
            ]);
            $usuarioId = (int) $pdo->lastInsertId();
        }

        $perfilId = perfilAsegurado($pdo, $usuarioId, $dominioId, $dominioNombre);

        // Solo a la cuenta nueva se le fija el perfil activo. A una cuenta que
        // ya existia no se le mueve el dominio con el que esta trabajando: el
        // acceso nuevo le aparece en "Cambiar dominio".
        if ($nueva) {
            $pdo->prepare('UPDATE usuarios SET perfil = :p WHERE id = :id')
                ->execute([':p' => $perfilId, ':id' => $usuarioId]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // El correo va DESPUES del commit y su resultado no revierte nada: el
    // acceso ya existe y es valido. Si el envio falla, la contrasena se
    // muestra igual en pantalla, que es donde esta la persona ahora mismo.
    $correoOk = true;
    if ($nueva && $contrasena !== null) {
        $envio = databoxCorreoEncolar([
            'destino'      => $correo,
            'destinatario' => $completo,
            'asunto'       => 'Tu acceso a ' . $dominioNombre,
            'cuerpo'       => invitacionCuerpoCredenciales($dominioNombre, $correo, $contrasena, panelBaseUrl() . '/login.php'),
            'prioridad'    => 4,
            'tags'         => 'invitacion-credenciales',
        ]);
        $correoOk = (bool) $envio['ok'];
    }

    return [
        'usuario'    => $correo,
        'contrasena' => $contrasena,
        'nueva'      => $nueva,
        'correo_ok'  => $correoOk,
    ];
}

/**
 * Perfil del usuario en el dominio, creandolo si no lo tenia.
 * Espeja cPerfil::registrar() del legacy: tipo 'O' (Operador) y habilitado.
 * `rol` y `panel` quedan en NULL y no en 0 — con las FK declaradas, el 0 del
 * sistema viejo ya no es un valor valido (ver db/schema.sql).
 */
function perfilAsegurado(PDO $pdo, int $usuarioId, int $dominioId, string $dominioNombre): int
{
    $busca = $pdo->prepare(
        'SELECT id FROM perfiles WHERE usuario = :u AND dominio = :d ORDER BY id LIMIT 1'
    );
    $busca->execute([':u' => $usuarioId, ':d' => $dominioId]);
    $id = (int) ($busca->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $alta = $pdo->prepare(
        'INSERT INTO perfiles (uuid, nombre, usuario, dominio, tipo, habilitado)
         VALUES (:uuid, :nombre, :usuario, :dominio, :tipo, :habilitado)'
    );
    $alta->execute([
        ':uuid'       => bin2hex(random_bytes(8)),
        ':nombre'     => mb_substr('Operador en ' . $dominioNombre, 0, 255),
        ':usuario'    => $usuarioId,
        ':dominio'    => $dominioId,
        ':tipo'       => 'O',
        // perfiles.habilitado es '1'/'0', no 'S'/'N' como usuarios.habilitado.
        ':habilitado' => '1',
    ]);

    return (int) $pdo->lastInsertId();
}

/**
 * Contrasena inicial. Sin caracteres ambiguos (0/O, 1/l/I): se lee de un
 * correo y se tipea a mano. 10 chars -> 16 en base64, dentro del varchar(50)
 * de `usuarios.contrasena`.
 */
function contrasenaGenerada(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $tope     = strlen($alfabeto) - 1;
    $out      = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alfabeto[random_int(0, $tope)];
    }
    return $out;
}

/** @param array{usuario:string,contrasena:?string,nueva:bool,correo_ok:bool} $r */
function pantallaBienvenida(array $r, string $dominioNombre): string
{
    $ingresar = '<div class="inv-acciones" style="margin-top:4px">
            <a class="btn btn-primary" href="' . e(panelBaseUrl()) . '/login.php">
                <i class="fa-solid fa-right-to-bracket"></i> Ingresar
            </a>
        </div>';

    if (!$r['nueva']) {
        return '<div class="inv-note inv-note-ok">Ya tenés acceso a <strong>'
             . e($dominioNombre) . '</strong>.</div>'
             . '<p class="inv-lead">Ingresá con las credenciales que ya usabas: el dominio nuevo '
             . 'te va a aparecer en <strong>Cambiar dominio</strong>.</p>'
             . $ingresar;
    }

    $aviso = $r['correo_ok']
        ? '<p class="inv-lead">Te mandamos estos datos por correo a <strong>' . e($r['usuario']) . '</strong>.</p>'
        : '<div class="inv-note inv-note-bad">No pudimos enviarte el correo con los datos de acceso. '
          . 'Anotálos antes de cerrar esta página.</div>';

    return '<div class="inv-note inv-note-ok">Tu cuenta en <strong>' . e($dominioNombre)
         . '</strong> ya está activa.</div>'
         . '<div class="inv-datos">'
         . invitacionDato('Usuario', $r['usuario'])
         . invitacionDato('Contraseña', (string) $r['contrasena'])
         . '</div>'
         . $aviso
         . '<p class="inv-lead">Te recomendamos cambiarla después del primer ingreso.</p>'
         . $ingresar;
}
