<?php

declare(strict_types=1);

/**
 * Emision de enlaces magicos: una URL de un solo uso que abre sesion en `panel`
 * o en `app` como el usuario indicado, sin pedirle la contrasena.
 *
 * POST api/enlaces_acceso  { usuario_id, destino: 'panel'|'app' }
 *   -> { url, destino, expira, minutos }
 *
 * ES SUPLANTACION DE IDENTIDAD. Quien reciba la URL entra a la cuenta ajena con
 * todo lo que esa cuenta puede hacer -- no es un "ver como" de solo lectura. La
 * tabla `enlaces_acceso` guarda quien lo emitio, cuando y desde que IP; ver el
 * encabezado de `20260906_2000_crear_enlaces_acceso.sql`.
 *
 * EL TOKEN SE MUESTRA UNA SOLA VEZ. En la base va su SHA-256, asi que ni este
 * endpoint ni ninguna pantalla pueden volver a mostrarlo: si se pierde, se
 * emite otro. Es lo que hace que leer la base no alcance para entrar a ninguna
 * cuenta.
 *
 * LAS DOS VALIDACIONES DE ACA NO SON EL CONTROL. `panel/acceso.php` y
 * `app/acceso.php` vuelven a comprobar todo al consumir el enlace: entre la
 * emision y el uso pueden pasar 15 minutos, y en el medio la cuenta se pudo
 * deshabilitar. Lo de aca es para no entregar un enlace que se sabe que no va a
 * funcionar.
 */

require __DIR__ . '/bootstrap.php';

/** Vida del enlace. Corta a proposito: no es para guardar, es para usar ahora. */
const ENLACE_MINUTOS = 15;

/** Cupo por usuario y por hora, para que un bug o un dedo pesado no emita cien. */
const ENLACE_CUPO_HORA = 10;

const ENLACE_DESTINOS = ['panel', 'app'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'POST') json_error('Metodo no permitido', 405);
    handleCrear();
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
    $ins = db()->prepare(
        'INSERT INTO enlaces_acceso
            (usuario, destino, token, emisor, emisor_tabla, emitido, expira, origen)
         VALUES
            (:u, :d, :t, :em, :et, NOW(), DATE_ADD(NOW(), INTERVAL :min MINUTE), :ip)'
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
        ':ip'  => origenRequest(),
    ]);

    $id  = (int) db()->lastInsertId();
    $exp = db()->prepare('SELECT expira FROM enlaces_acceso WHERE id = :id');
    $exp->execute([':id' => $id]);

    json_ok([
        'url'      => baseDeDestino($destino) . '/acceso?t=' . $token,
        'destino'  => $destino,
        'usuario'  => ['id' => (int) $usuario['id'], 'nombre' => (string) $usuario['nombre']],
        'expira'   => (string) $exp->fetchColumn(),
        'minutos'  => ENLACE_MINUTOS,
    ], 201);
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
