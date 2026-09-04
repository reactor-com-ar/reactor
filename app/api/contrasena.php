<?php

declare(strict_types=1);

/**
 * Contraseña del propio usuario logueado.
 *
 *   GET  -> devuelve la contraseña actual EN CLARO, para precargarla en el
 *           modal detrás del ojo.
 *   POST -> la reemplaza por una nueva.
 *
 * Es lo ÚNICO que la app deja modificar de la cuenta: nombre, correo y celular
 * son de solo lectura acá (se cambian por soporte / backoffice).
 *
 * El id sale SIEMPRE de la sesión, nunca del body: un usuario solo puede leer
 * y cambiar su propia contraseña.
 *
 * SOBRE DEVOLVER LA CONTRASEÑA EN CLARO
 *
 *   `usuarios.contrasena` no es un hash sino un cifrado reversible (ver
 *   lib/legacy_crypto.php), así que el valor original se puede recuperar. El
 *   legacy ya se apoyaba en eso: `sesion/recuperar.php` mandaba la contraseña
 *   por mail/WhatsApp en texto plano. Acá viaja solo por la sesión del propio
 *   dueño y con `Cache-Control: no-store`.
 *
 *   Consecuencia: el POST ya no pide la contraseña anterior. No sería una
 *   defensa real teniéndola visible en la misma pantalla; el control es tener
 *   la sesión abierta.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/legacy_crypto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/**
 * `usuarios.contrasena` es varchar(50) y guarda el base64 del cifrado legacy:
 * 4*ceil(n/3) chars para n bytes de contraseña. Con n=36 son 48 chars (entra);
 * con n=37 son 52 y MySQL la truncaría, dejando al usuario afuera de su cuenta.
 */
const CONTRASENA_MAX = 36;
const CONTRASENA_MIN = 6;

function salir(int $status, array $cuerpo): never
{
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sesion = appUser();
if ($sesion === null) {
    salir(401, ['ok' => false, 'error' => 'Sesión vencida. Volvé a ingresar.']);
}

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo !== 'GET' && $metodo !== 'POST') {
    salir(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

$id = (int) $sesion['id'];

try {
    $stmt = db()->prepare('SELECT contrasena FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    $guardada = (string) ($stmt->fetchColumn() ?: '');

    if ($metodo === 'GET') {
        salir(200, ['ok' => true, 'contrasena' => reactor_legacy_desencriptar($guardada)]);
    }

    $raw  = file_get_contents('php://input');
    $body = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($body)) $body = [];

    $nueva = (string) ($body['nueva'] ?? '');

    if ($nueva === '') {
        salir(422, ['ok' => false, 'error' => 'Escribí una contraseña.']);
    }
    if (strlen($nueva) < CONTRASENA_MIN) {
        salir(422, ['ok' => false, 'error' => 'La contraseña debe tener al menos ' . CONTRASENA_MIN . ' caracteres.']);
    }
    if (strlen($nueva) > CONTRASENA_MAX) {
        salir(422, ['ok' => false, 'error' => 'La contraseña no puede superar los ' . CONTRASENA_MAX . ' caracteres.']);
    }

    $cifrada = reactor_legacy_encriptar($nueva);
    if ($guardada !== '' && hash_equals($guardada, $cifrada)) {
        salir(422, ['ok' => false, 'error' => 'Esa ya es tu contraseña actual.']);
    }

    $upd = db()->prepare('UPDATE usuarios SET contrasena = :c WHERE id = :id');
    $upd->execute([':c' => $cifrada, ':id' => $id]);

    salir(200, ['ok' => true]);
} catch (Throwable $e) {
    salir(500, ['ok' => false, 'error' => 'No se pudo cambiar la contraseña.']);
}
