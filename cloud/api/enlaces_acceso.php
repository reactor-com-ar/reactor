<?php

declare(strict_types=1);

/**
 * Emision y configuracion de enlaces magicos: una URL que abre sesion en `panel`
 * o en `app` como el usuario indicado, sin pedirle la contrasena.
 *
 * POST api/enlaces_acceso  { usuario_id, destino: 'panel'|'app' }
 *   -> { id, url, destino, usuario, emitido, expira, usos, usos_max, ... }
 * PUT  api/enlaces_acceso  { id, expira, usos_max }
 *   -> el mismo estado, ya actualizado
 *
 * ES SUPLANTACION DE IDENTIDAD. Quien reciba la URL entra a la cuenta ajena con
 * todo lo que esa cuenta puede hacer -- no es un "ver como" de solo lectura. La
 * tabla `enlaces_acceso` guarda quien lo emitio, cuando y desde que IP; ver el
 * encabezado de `20260906_2000_crear_enlaces_acceso.sql`.
 *
 * EL TOKEN SE MUESTRA UNA SOLA VEZ. En la base va su SHA-256, asi que ni este
 * endpoint ni ninguna pantalla pueden volver a mostrarlo: si se pierde, se
 * emite otro. Es lo que hace que leer la base no alcance para entrar a ninguna
 * cuenta. **Por eso el `PUT` existe**: sin el, cambiar la vigencia o el cupo
 * obligaria a emitir otro enlace y a descartar el que ya se copio.
 *
 * EL `PUT` NO REGALA NINGUN PRIVILEGIO NUEVO: quien puede llamarlo ya puede
 * emitir un enlace nuevo para la misma cuenta con los valores que quiera (y el
 * cupo por hora lo sigue acotando). Por eso no pide ser el emisor de la fila:
 * en cloud quien opera es Reactor sobre el sistema entero.
 *
 * LAS VALIDACIONES DE ACA NO SON EL CONTROL. `panel/acceso.php` y
 * `app/acceso.php` vuelven a comprobar todo al consumir el enlace: entre la
 * emision y el uso puede pasar una hora —o los dias que le ponga el operador— y
 * en el medio la cuenta se pudo deshabilitar. Lo de aca es para no entregar un
 * enlace que se sabe que no va a funcionar.
 *
 * TODAS LAS FECHAS SALEN DE `NOW()` DE LA BASE, nunca del reloj de PHP: son dos
 * relojes distintos y el que compara el vencimiento al canjear es el de la base.
 * PHP solo se usa para VALIDAR EL FORMATO de lo que llega del formulario.
 */

require __DIR__ . '/bootstrap.php';

/**
 * Vida del enlace recien emitido: 60 minutos.
 *
 * Fueron 15 hasta el 21/09/2026, con el argumento de que no es un enlace para
 * guardar sino para usar ahora. Sigue siendo cierto, pero ahora es un DEFAULT y
 * no el unico valor: el operador ajusta la vigencia en la misma pantalla que le
 * muestra el enlace, asi que el numero de aca solo tiene que cubrir el caso
 * comun (generarlo y mandarlo por un chat) sin obligar a tocar nada.
 */
const ENLACE_MINUTOS = 60;

/**
 * Tope de vigencia: 30 dias.
 *
 * No es un numero tecnico, es hasta donde llega la excusa "lo necesito abierto
 * un rato mas". Sigue siendo un tope y no "sin vencimiento": un enlace de sesion
 * que no caduca es una contrasena que ademas viaja en una URL — se archiva en un
 * chat, queda en el historial del navegador y en el log del proxy.
 *
 * De aca sale el `max` del campo de fecha del modal (via `expira_max`, que lo
 * calcula la base) y el corte del `PUT`. Un solo lugar: si se toca el numero, se
 * mueven los dos.
 */
const ENLACE_MINUTOS_MAX = 30 * 24 * 60;

/** Cupo de canjes por defecto: uno, que es lo que la tabla hizo siempre. */
const ENLACE_USOS = 1;

/**
 * Tope de canjes. Cien es holgado para el caso que justifica la columna —un
 * enlace que dos o tres personas abren desde equipos distintos— y sigue siendo
 * un numero, no "ilimitado": un enlace sin tope no se distingue de una cuenta
 * compartida.
 */
const ENLACE_USOS_MAX = 100;

/** Cupo por usuario y por hora, para que un bug o un dedo pesado no emita cien. */
const ENLACE_CUPO_HORA = 10;

const ENLACE_DESTINOS = ['panel', 'app'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'POST') {
        handleCrear();
    } elseif ($method === 'PUT') {
        handleConfigurar();
    }
    json_error('Metodo no permitido', 405);
} catch (Throwable $e) {
    json_error('Error al generar el enlace: ' . $e->getMessage(), 500);
}

function handleCrear(): void
{
    $in        = readJson();
    $usuarioId = (int) ($in['usuario_id'] ?? 0);
    $destino   = strtolower(trim((string) ($in['destino'] ?? '')));

    if ($usuarioId <= 0) json_error('Usuario invalido', 422);
    if (!in_array($destino, ENLACE_DESTINOS, true)) json_error('Destino invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre, usuario, habilitado FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $usuarioId]);
    $usuario = $stmt->fetch();
    if (!$usuario) json_error('Usuario no encontrado', 404);

    // `usuarios.habilitado` es tinyint(1) con dos valores. Un enlace para una
    // cuenta deshabilitada no serviria: los dos consumidores la rechazan.
    if (!esHabilitado($usuario['habilitado'])) {
        json_error('La cuenta esta deshabilitada: no se puede generar un acceso', 409);
    }

    // Para `panel` hace falta ademas un perfil habilitado DE TIPO ADMINISTRADOR:
    // es su gate (panel/lib/acceso.php), y un Operador rebotaria al login apenas
    // usara el enlace. Darselo igual seria entregar algo que no funciona.
    if ($destino === 'panel' && !tienePerfilAdministrador($usuarioId)) {
        json_error('El usuario no tiene un perfil de Administrador habilitado: el panel es exclusivo para administradores', 409);
    }

    if (emitidosUltimaHora($usuarioId) >= ENLACE_CUPO_HORA) {
        json_error('Se generaron demasiados enlaces para este usuario en la ultima hora', 429);
    }

    // 32 bytes de CSPRNG en base64url: 43 chars sin nada que escapar en una URL.
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    $actual = authUser();

    // `expira` se calcula con NOW() de la BASE y no con el reloj de PHP: son dos
    // relojes distintos (PHP en UTC, la sesion de MySQL en -03:00) y quien
    // compara el vencimiento al consumir tambien usa el de la base. Con el de
    // PHP el enlace naceria vencido o duraria tres horas de mas.
    //
    // `usos` arranca en 0 y `usos_max` en el default. Los dos son columnas y no
    // constantes del codigo porque el operador los ajusta despues, desde la
    // misma pantalla que le muestra el enlace (`PUT`).
    $ins = db()->prepare(
        'INSERT INTO enlaces_acceso
            (usuario, destino, token, emisor, emisor_tabla, emitido, expira, usos_max, usos, origen)
         VALUES
            (:u, :d, :t, :em, :et, NOW(), DATE_ADD(NOW(), INTERVAL :min MINUTE), :max, 0, :ip)'
    );
    $ins->execute([
        ':u'   => $usuarioId,
        ':d'   => $destino,
        ':t'   => hash('sha256', $token),
        ':em'  => (int) ($actual['id'] ?? 0) ?: null,
        // Ese dia llego: desde el 07/09/2026 el login de cloud valida contra
        // `controladores`, asi que `emisor` es un id de esa tabla. Las filas
        // emitidas antes siguen diciendo 'usuarios' y por eso siguen siendo
        // legibles — para eso existe esta columna.
        ':et'  => 'controladores',
        ':min' => ENLACE_MINUTOS,
        ':max' => ENLACE_USOS,
        ':ip'  => origenRequest(),
    ]);

    $id = (int) db()->lastInsertId();

    json_ok(estadoEnlace($id) + [
        // El token viaja UNA SOLA VEZ, en esta respuesta y en ninguna otra: en
        // la base quedo su SHA-256. `estadoEnlace()` —que es lo que devuelve
        // tambien el PUT— no puede reponerlo.
        'url'     => baseDeDestino($destino) . '/acceso?t=' . $token,
        'usuario' => ['id' => (int) $usuario['id'], 'nombre' => (string) $usuario['nombre']],
    ], 201);
}

/**
 * Cambia la vigencia y el cupo de canjes de un enlace YA EMITIDO.
 *
 * El token no se toca: sigue valiendo el mismo, que es el punto — la pantalla
 * que llama a esto es la que lo esta mostrando, y emitir otro obligaria a
 * descartar el que el operador quiza ya copio.
 *
 * NO SE EXIGE QUE EL ENLACE SIGA VIGENTE. Subirle el cupo a uno agotado o
 * estirarle la fecha a uno vencido lo revive, y esta bien que asi sea: es la
 * misma pantalla, el mismo operador y el mismo token que tiene delante. Lo que
 * no puede es saltarse los topes.
 */
function handleConfigurar(): void
{
    $in      = readJson();
    $id      = (int) ($in['id'] ?? 0);
    $usosMax = (int) ($in['usos_max'] ?? 0);
    $expira  = fechaNormalizada((string) ($in['expira'] ?? ''));

    if ($id <= 0)   json_error('Enlace invalido', 422);
    if ($expira === null) json_error('La fecha de vigencia no es valida', 422);
    if ($usosMax < 1 || $usosMax > ENLACE_USOS_MAX) {
        json_error('Los usos permitidos van de 1 a ' . ENLACE_USOS_MAX, 422);
    }

    $sel = db()->prepare('SELECT id FROM enlaces_acceso WHERE id = :id');
    $sel->execute([':id' => $id]);
    if (!$sel->fetchColumn()) json_error('Enlace no encontrado', 404);

    // La ventana se mide contra el reloj de la BASE, igual que el canje: un
    // `new DateTimeImmutable('now')` de PHP correria el limite tres horas.
    // La fecha va dos veces porque con `EMULATE_PREPARES => false` cada
    // placeholder se usa una sola vez.
    $chk = db()->prepare(
        'SELECT (:e1 <= NOW()) AS pasado,
                (:e2 > DATE_ADD(NOW(), INTERVAL :tope MINUTE)) AS excede'
    );
    $chk->execute([':e1' => $expira, ':e2' => $expira, ':tope' => ENLACE_MINUTOS_MAX]);
    $ventana = $chk->fetch() ?: ['pasado' => 1, 'excede' => 0];

    if ((int) $ventana['pasado'] === 1) {
        json_error('La vigencia tiene que ser posterior a ahora', 422);
    }
    if ((int) $ventana['excede'] === 1) {
        json_error('La vigencia no puede superar los ' . (ENLACE_MINUTOS_MAX / 60 / 24) . ' dias', 422);
    }

    $upd = db()->prepare('UPDATE enlaces_acceso SET expira = :e, usos_max = :m WHERE id = :id');
    $upd->execute([':e' => $expira, ':m' => $usosMax, ':id' => $id]);

    json_ok(estadoEnlace($id));
}

/**
 * Lo que las dos pantallas necesitan saber del enlace, SIN el token.
 *
 * `ahora` y `expira_max` viajan porque el formulario los usa de `min` y `max`
 * del campo de fecha: el navegador del operador y la sesion de MySQL no tienen
 * por que estar en la misma zona horaria, y el limite que vale es el de la base.
 */
function estadoEnlace(int $id): array
{
    $stmt = db()->prepare(
        'SELECT id, destino, emitido, expira, usos, usos_max, usada,
                NOW() AS ahora,
                DATE_ADD(NOW(), INTERVAL :tope MINUTE) AS expira_max,
                (expira > NOW() AND usos < usos_max) AS vigente
           FROM enlaces_acceso
          WHERE id = :id'
    );
    $stmt->execute([':id' => $id, ':tope' => ENLACE_MINUTOS_MAX]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Enlace no encontrado', 404);

    return [
        'id'         => (int) $fila['id'],
        'destino'    => (string) $fila['destino'],
        'emitido'    => (string) $fila['emitido'],
        'expira'     => (string) $fila['expira'],
        'usos'       => (int) $fila['usos'],
        'usos_max'   => (int) $fila['usos_max'],
        'usada'      => $fila['usada'] !== null ? (string) $fila['usada'] : null,
        'vigente'    => (int) $fila['vigente'] === 1,
        'ahora'      => (string) $fila['ahora'],
        'expira_max' => (string) $fila['expira_max'],
        'usos_tope'  => ENLACE_USOS_MAX,
        'minutos'    => ENLACE_MINUTOS,
    ];
}

/**
 * `Y-m-d H:i:s` para la base, o `null` si lo que llego no es una fecha.
 *
 * Acepta las dos formas que puede mandar el formulario: la del `datetime-local`
 * (`2026-09-21T15:30`, con o sin segundos) y la de la base (`2026-09-21
 * 15:30:00`). NO SE CONVIERTE DE ZONA: lo que el operador ve en el campo es la
 * hora de la base —que es la que despues compara el canje—, asi que
 * reinterpretarla contra la zona del navegador la correria.
 *
 * Se valida con `createFromFormat` + `getLastErrors()` y no con `strtotime()`,
 * que le da por buena cualquier cosa que se le parezca ("+1 day", "now") y
 * ademas resuelve contra el reloj de PHP.
 */
function fechaNormalizada(string $valor): ?string
{
    $valor = trim($valor);
    if ($valor === '') return null;

    foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $formato) {
        $fecha   = DateTimeImmutable::createFromFormat($formato, $valor);
        $errores = DateTimeImmutable::getLastErrors();
        $malas   = is_array($errores) ? ($errores['warning_count'] + $errores['error_count']) : 0;

        if ($fecha instanceof DateTimeImmutable && $malas === 0) {
            // Los segundos van a 0 cuando el formato no los trae: sin esto
            // `createFromFormat` los completa con los del reloj de PHP, asi que
            // el mismo `15:30` del campo quedaria guardado con 41 segundos.
            return str_contains($formato, ':s')
                ? $fecha->format('Y-m-d H:i:s')
                : $fecha->format('Y-m-d H:i') . ':00';
        }
    }

    return null;
}

/**
 * ¿La cuenta tiene con que entrar al panel?
 *
 * `tipo = 'A'` es el gate del panel desde que se elimino `perfiles`.`rol`
 * (ver panel/lib/acceso.php). Se repite aca y no se importa la funcion del panel
 * porque los dos proyectos no comparten docroot -- igual que pasa con
 * `lib/habilitado.php` y con `lib/usuarios_alta.php`.
 */
function tienePerfilAdministrador(int $usuarioId): bool
{
    $stmt = db()->prepare(
        "SELECT 1 FROM perfiles WHERE usuario = :u AND habilitado = 1 AND tipo = 'A' LIMIT 1"
    );
    $stmt->execute([':u' => $usuarioId]);

    return (bool) $stmt->fetchColumn();
}

function emitidosUltimaHora(int $usuarioId): int
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM enlaces_acceso
          WHERE usuario = :u AND emitido > DATE_SUB(NOW(), INTERVAL 1 HOUR)'
    );
    $stmt->execute([':u' => $usuarioId]);

    return (int) $stmt->fetchColumn();
}

/**
 * URL publica del proyecto destino.
 *
 * Misma regla que `panelBaseUrl()` del panel: en produccion es FIJA, porque un
 * `Host` falseado mandaria el enlace a otro dominio; en desarrollo se deriva del
 * host de la request, cambiandole el puerto (cloud 8086 -> panel 8087 / app
 * 8115). `PANEL_BASE_URL` / `APP_BASE_URL` en el .env pisan las dos.
 */
function baseDeDestino(string $destino): string
{
    $constante = $destino === 'panel' ? 'PANEL_BASE_URL' : 'APP_BASE_URL';
    if (defined($constante) && trim((string) constant($constante)) !== '') {
        return rtrim(trim((string) constant($constante)), '/');
    }

    if (APP_ENV === 'production') {
        return $destino === 'panel'
            ? 'https://panel.reactor.com.ar'
            : 'https://app.reactor.com.ar';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }
    // Se queda el nombre y se cambia el puerto: en dev los tres proyectos viven
    // en el mismo host y solo los separa el puerto.
    $nombre = explode(':', $host)[0];
    $puerto = $destino === 'panel' ? '8087' : '8115';

    return 'http://' . $nombre . ':' . $puerto;
}

/** IP de quien pidio el enlace. `REMOTE_ADDR` y no `X-Forwarded-For`: ese
 *  header lo pone el cliente y falsearlo ensuciaria la auditoria. */
function origenRequest(): ?string
{
    $ip = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));

    return $ip !== '' ? mb_substr($ip, 0, 45) : null;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
