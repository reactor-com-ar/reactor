<?php

declare(strict_types=1);

/**
 * Canje de un enlace magico: abre sesion en la app como el usuario del token,
 * sin pedirle la contrasena ni el codigo. Los emite cloud
 * (Usuarios -> Consultar -> Acciones).
 *
 * GET /acceso?t=<token>
 *
 * ES UNA PAGINA PUBLICA: no puede exigir sesion, porque su razon de ser es
 * crearla. Todo el control esta en el token.
 *
 * EL CANJE ES UN `UPDATE` CONDICIONAL, NO UN SELECT + UPDATE. La fila se marca
 * usada con `WHERE usada IS NULL AND expira > NOW()` en la MISMA sentencia que
 * la valida: si dos requests llegan con el mismo enlace, solo una afecta filas.
 * Con un SELECT previo las dos pasarian el chequeo antes de que ninguna
 * escribiera.
 *
 * `destino = 'app'` VA EN EL WHERE: un enlace emitido para `panel` no abre la
 * app ni al reves.
 *
 * LA SESION LA ABRE `appSesionAbrir()`, el mismo punto que usan el login por
 * contrasena, el login por codigo y la adopcion del token legacy. No se firma
 * la cookie a mano: esa funcion es la que resuelve el ALCANCE de la sesion
 * (`per` / `dom` / `pan`), y una sesion abierta por enlace tiene que quedar
 * indistinguible de una abierta por contrasena.
 */

require_once dirname(__DIR__) . '/env.php';
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/jwt.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/habilitado.php';

header('X-Robots-Tag: noindex, nofollow');
header('Cache-Control: no-store');

$token = trim((string) ($_GET['t'] ?? ''));

try {
    $usuarioId = appCanjearEnlace($token);
} catch (Throwable $e) {
    $usuarioId = 0;
}

$error = '';

if ($usuarioId <= 0) {
    $error = 'Este enlace de acceso no es válido, ya se usó o venció.';
} else {
    // Se revalida aunque cloud lo haya hecho al emitir: entre la emision y el
    // uso pasan hasta 15 minutos, y en el medio la cuenta se pudo deshabilitar.
    // El enlace prueba QUIEN es, no que siga pudiendo entrar.
    $usuario = appUsuarioVigente($usuarioId);

    if ($usuario === null || !esHabilitado($usuario['habilitado'] ?? 0)) {
        $error = 'La cuenta de este enlace está deshabilitada.';
    } else {
        appSesionAbrir($usuario);
        header('Location: ./');
        exit;
    }
}

/**
 * Consume el token y devuelve el id del usuario, o 0 si no sirve.
 *
 * Si el `UPDATE` afecta 0 filas el enlace no existe, ya se uso, vencio o es de
 * otro destino -- y no se distingue cual a proposito: quien lo trae no tiene por
 * que enterarse de en cual de los cuatro casos cayo.
 */
function appCanjearEnlace(string $token): int
{
    if ($token === '' || !preg_match('/^[A-Za-z0-9_\-]{20,64}$/', $token)) {
        return 0;
    }

    $hash = hash('sha256', $token);
    $ip   = mb_substr(trim((string) ($_SERVER['REMOTE_ADDR'] ?? '')), 0, 45) ?: null;

    // Las fechas se comparan contra `NOW()` de la BASE y nunca contra el reloj
    // de PHP: son dos relojes distintos y `expira` la escribio la base.
    $upd = db()->prepare(
        'UPDATE enlaces_acceso
            SET usada = NOW(), origen_uso = :ip
          WHERE token = :t
            AND destino = "app"
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

// A esta altura siempre hay error: el camino feliz salio por el redirect.
// Se reusa `sesionPantalla()`, la misma tarjeta de las tres pantallas de
// sesion, para que un enlace fallido se vea igual que cualquier otro rechazo.
require __DIR__ . '/sesion/_layout.php';
sesionPantalla(
    'Acceso no válido',
    '<a class="btn btn-primary" href="sesion/iniciar">Ingresar con mi usuario</a>',
    $error
);
