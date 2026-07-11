<?php

declare(strict_types=1);

// Endpoint publico: no exige sesion previa.
define('PANEL_API_PUBLIC', true);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/legacy_crypto.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metodo no permitido', 405);
}

try {
    $raw  = file_get_contents('php://input');
    $body = $raw === false || $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) $body = [];

    $usuario    = trim((string) ($body['usuario']    ?? ''));
    $contrasena = (string)        ($body['contrasena'] ?? '');

    if ($usuario === '' || $contrasena === '') {
        json_error('Usuario y contrasena son obligatorios', 422);
    }

    // Lookup por `usuario`. El cifrado historico no es por usuario sino
    // global (clave fija '0123456789'), asi que comparamos el resultado
    // de encriptar la contrasena tipeada contra la columna `contrasena`.
    $stmt = db()->prepare(
        'SELECT id, nombre, usuario, contrasena, habilitado, correo
         FROM usuarios
         WHERE usuario = :u
         LIMIT 1'
    );
    $stmt->execute([':u' => $usuario]);
    $row = $stmt->fetch();

    if (!$row) {
        // Mensaje generico: no exponer si fallo el usuario o la contrasena.
        json_error('Usuario o contrasena incorrectos', 401);
    }

    $habilitado = strtoupper(trim((string) ($row['habilitado'] ?? '')));
    if (!in_array($habilitado, ['S', '1', 'Y'], true)) {
        json_error('El usuario esta deshabilitado', 403);
    }

    $stored   = (string) ($row['contrasena'] ?? '');
    $expected = reactor_legacy_encriptar($contrasena);

    // hash_equals para comparacion constant-time (mitiga timing side-channels).
    if ($stored === '' || !hash_equals($stored, $expected)) {
        json_error('Usuario o contrasena incorrectos', 401);
    }

    $usuarioPayload = [
        'id'      => (int) $row['id'],
        'usuario' => (string) $row['usuario'],
        'nombre'  => (string) $row['nombre'],
        'correo'  => (string) ($row['correo'] ?? ''),
    ];

    jwt_cookie_set(jwt_sign($usuarioPayload, JWT_TTL));

    // Persistir el ultimo ingreso. Falla silenciosamente si la columna
    // estuviera ausente en alguna BD vieja: no bloquea el login.
    try {
        $upd = db()->prepare('UPDATE usuarios SET ingresado = NOW() WHERE id = :id');
        $upd->execute([':id' => (int) $row['id']]);
    } catch (Throwable $_) { /* noop */ }

    json_ok(['usuario' => $usuarioPayload]);
} catch (Throwable $e) {
    json_error('Error al procesar el login: ' . $e->getMessage(), 500);
}
