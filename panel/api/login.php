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

    // `usuarios.habilitado` es tinyint(1) NOT NULL: entra solo con 1.
    if (!esHabilitado($row['habilitado'])) {
        json_error('El usuario esta deshabilitado', 403);
    }

    $stored   = (string) ($row['contrasena'] ?? '');
    $expected = reactor_legacy_encriptar($contrasena);

    // hash_equals para comparacion constant-time (mitiga timing side-channels).
    if ($stored === '' || !hash_equals($stored, $expected)) {
        json_error('Usuario o contrasena incorrectos', 401);
    }

    // GATE DE ROL: al panel solo entra quien tiene perfil de Administrador
    // (lib/acceso.php). Se corta aca, con la contrasena ya validada, para que
    // el mensaje distinga "no sos vos" de "no tenes permiso" — el usuario ve
    // por que no entra en vez de un "usuario o contrasena incorrectos" que lo
    // manda a probar contrasenas que estan bien.
    $usuarioId = (int) $row['id'];
    $admin     = perfilesAdministrador($usuarioId);

    if ($admin === []) {
        json_error(
            PANEL_MENSAJE_SIN_ROL . ' Tu cuenta no tiene ese perfil en ningún dominio: '
            . 'pedile a un administrador que te lo asigne.',
            403
        );
    }

    // Datos de alcance capturados al iniciar sesion: `dominio` es el que la
    // cuenta tiene asignado EN ESE MOMENTO (usuarios.dominio) y es el que
    // filtra toda la informacion del panel durante la sesion. Ver lib/sesion.php.
    $cuenta = sessionCuentaDesdeDb($usuarioId);

    // El perfil que trae la cuenta es el ULTIMO que uso, en cualquiera de los
    // sistemas que comparten `usuarios` — puede ser un Operador, o un perfil de
    // otro dominio. Si no habilita el panel se arranca en el primer dominio
    // donde si es administradora, y se asienta como en Cambiar dominio. Sin
    // esto la sesion nace denegada y el login rebota para siempre: son 247 de
    // las 368 cuentas administradoras las que hoy tienen el perfil activo en
    // otra cosa.
    if (!esPerfilAdministrador($usuarioId, (int) ($cuenta['perfil'] ?? 0), (int) ($cuenta['dominio'] ?? 0))) {
        panelPerfilActivoAsentar($usuarioId, $admin[0]['perfil'], $admin[0]['dominio']);
        $cuenta = sessionCuentaDesdeDb($usuarioId);
    }

    $usuarioPayload = [
        'id'             => $usuarioId,
        'usuario'        => (string) $row['usuario'],
        'nombre'         => (string) $row['nombre'],
        'correo'         => (string) ($row['correo'] ?? ''),
        'dominio'        => $cuenta['dominio']        ?? null,
        'dominio_nombre' => $cuenta['dominio_nombre'] ?? '',
        'perfil'         => $cuenta['perfil']         ?? null,
        'perfil_nombre'  => $cuenta['perfil_nombre']  ?? '',
        'roles'          => $cuenta['roles']          ?? '',
    ];

    jwt_cookie_set(jwt_sign($usuarioPayload, JWT_TTL));

    // Persistir el ultimo ingreso. Falla silenciosamente si la columna
    // estuviera ausente en alguna BD vieja: no bloquea el login.
    try {
        $upd = db()->prepare('UPDATE usuarios SET ingresado = NOW() WHERE id = :id');
        $upd->execute([':id' => $usuarioId]);
    } catch (Throwable $_) { /* noop */ }

    json_ok(['usuario' => $usuarioPayload]);
} catch (Throwable $e) {
    json_error('Error al procesar el login: ' . $e->getMessage(), 500);
}
