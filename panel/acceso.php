<?php

declare(strict_types=1);

/**
 * Canje de un enlace magico: abre sesion en el panel como el usuario del token,
 * sin pedirle la contrasena. Los emite cloud (Usuarios -> Consultar -> Acciones).
 *
 * GET /acceso?t=<token>
 *
 * ES UNA PAGINA PUBLICA: no puede exigir sesion, porque su razon de ser es
 * crearla. Todo el control esta en el token.
 *
 * EL CANJE ES UN `UPDATE` CONDICIONAL, NO UN SELECT + UPDATE. La fila se marca
 * usada con `WHERE usada IS NULL AND expira > NOW()` en la MISMA sentencia que
 * la valida: si dos requests llegan con el mismo enlace, solo una afecta filas y
 * la otra rebota. Con un SELECT previo las dos pasarian el chequeo antes de que
 * ninguna escribiera.
 *
 * SE REVALIDA TODO, aunque cloud ya lo haya hecho al emitir. Entre la emision y
 * el uso pasan hasta 15 minutos y en el medio la cuenta se pudo deshabilitar o
 * quedarse sin perfiles. El enlace prueba QUIEN es, no que siga pudiendo entrar.
 *
 * `destino = 'panel'` VA EN EL WHERE: un enlace emitido para `app` no abre el
 * panel. Sin esa condicion, el enlace del proyecto menos privilegiado serviria
 * para entrar al mas privilegiado.
 *
 * Las fechas se comparan SIEMPRE contra `NOW()` de la base y nunca contra el
 * reloj de PHP: son dos relojes distintos (PHP en UTC, la sesion de MySQL en
 * -03:00) y `expira` la escribio la base. Es el mismo drift documentado en
 * lib/recuperacion.php.
 */

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/jwt.php';
require_once __DIR__ . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sesion.php';
require_once __DIR__ . '/lib/acceso.php';
require_once __DIR__ . '/lib/habilitado.php';
require_once __DIR__ . '/lib/publico.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$token = trim((string) ($_GET['t'] ?? ''));

try {
    $usuarioId = canjearEnlace($token);
} catch (Throwable $e) {
    $usuarioId = 0;
}

if ($usuarioId <= 0) {
    rechazar('Este enlace de acceso no es válido, ya se usó o venció.');
}

// --- La cuenta, revalidada ---------------------------------------------
$stmt = db()->prepare('SELECT id, usuario, nombre, correo, habilitado FROM usuarios WHERE id = :id LIMIT 1');
$stmt->execute([':id' => $usuarioId]);
$cuenta = $stmt->fetch();

if (!$cuenta || !esHabilitado($cuenta['habilitado'] ?? 0)) {
    rechazar('La cuenta de este enlace está deshabilitada.');
}

// --- El perfil con el que arranca, igual que en api/login.php -----------
// `usuarios.perfil` es el ultimo que la persona uso en CUALQUIERA de los
// sistemas que comparten `usuarios`, asi que puede ser de otro dominio. Si no
// sirve, se toma el primero habilitado y se asienta -- sin esto la sesion nace
// denegada y el panel rebota al login para siempre.
$habiles = perfilesHabilitados($usuarioId);
if ($habiles === []) {
    rechazar('Esta cuenta no tiene un perfil habilitado en ningún dominio.');
}

$alcance = sessionCuentaDesdeDb($usuarioId);
if (!esPerfilValido($usuarioId, (int) ($alcance['perfil'] ?? 0), (int) ($alcance['dominio'] ?? 0))) {
    panelPerfilActivoAsentar($usuarioId, $habiles[0]['perfil'], $habiles[0]['dominio']);
    $alcance = sessionCuentaDesdeDb($usuarioId);
}

// --- Sesion --------------------------------------------------------------
// El payload es el MISMO que arma api/login.php: si los dos caminos firmaran
// claims distintos, una sesion abierta por enlace se comportaria distinto de
// una abierta por contrasena y el bug seria imposible de encontrar.
jwt_cookie_set(jwt_sign([
    'id'             => (int) $cuenta['id'],
    'usuario'        => (string) $cuenta['usuario'],
    'nombre'         => (string) $cuenta['nombre'],
    'correo'         => (string) ($cuenta['correo'] ?? ''),
    'dominio'        => $alcance['dominio']        ?? null,
    'dominio_nombre' => $alcance['dominio_nombre'] ?? '',
    'perfil'         => $alcance['perfil']         ?? null,
    'perfil_nombre'  => $alcance['perfil_nombre']  ?? '',
], JWT_TTL));

try {
    db()->prepare('UPDATE usuarios SET ingresado = NOW() WHERE id = :id')
        ->execute([':id' => $usuarioId]);
} catch (Throwable $_) { /* no bloquea el ingreso */ }

header('Location: /');
exit;

// -----------------------------------------------------------------------

/**
 * Consume el token y devuelve el id del usuario, o 0 si no sirve.
 *
 * El `UPDATE` es el candado: marca y valida en una sola sentencia. Si afecta 0
 * filas, el enlace no existe, ya se uso, vencio o es de otro destino -- y no se
 * distingue cual a proposito, porque el que lo trae no tiene por que enterarse.
 */
function canjearEnlace(string $token): int
{
    if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
        return 0;
    }

    $hash = hash('sha256', $token);
    $ip   = mb_substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45) ?: null;

    $upd = db()->prepare(
        'UPDATE enlaces_acceso
            SET usada = NOW(), origen_uso = :ip
          WHERE token = :t
            AND destino = "panel"
            AND usada IS NULL
            AND expira > NOW()'
    );
    $upd->execute([':t' => $hash, ':ip' => $ip]);

    if ($upd->rowCount() === 0) {
        return 0;
    }

    $sel = db()->prepare('SELECT usuario FROM enlaces_acceso WHERE token = :t LIMIT 1');
    $sel->execute([':t' => $hash]);

    return (int) ($sel->fetchColumn() ?: 0);
}

/**
 * Pantalla de rechazo con la tarjeta publica del panel. Reusa `publicoCorte()`,
 * el mismo corte que ya muestran las invitaciones y las recuperaciones cuando
 * el enlace no sirve: para el visitante los tres fallan igual.
 */
function rechazar(string $mensaje): void
{
    publicoCorte(
        'Acceso no válido',
        $mensaje,
        'bad',
        '<div class="inv-acciones"><a class="btn btn-primary" href="/login">Ir al login</a></div>'
    );
    exit;
}
