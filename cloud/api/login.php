<?php

declare(strict_types=1);

/**
 * Login de Reactor Cloud. AUTENTICA CONTRA `controladores`, NO CONTRA
 * `usuarios`.
 *
 * Hasta el 07/09/2026 leia `usuarios`, que es la tabla de los clientes finales
 * —los que entran a `app` y a `panel`—, y eso significaba que cualquier cuenta
 * habilitada de cualquier dominio podia entrar al backoffice entero: el
 * Explorador DB, el de S3, el Migrador y el Programador de tareas incluidos. El
 * modulo Controladores existia desde el 06/09 justamente para ser esta lista, y
 * lo unico que faltaba era que el login la leyera.
 *
 * LA CREDENCIAL ES EL CORREO. `controladores` no tiene columna `usuario`: tiene
 * `correo` con UNIQUE, y api/controladores.php lo normaliza a minusculas al
 * guardarlo. Aca se aplica el mismo strtolower() antes de buscar, porque el
 * UNIQUE distingue mayusculas y sin esto "Ana@x.com" no encontraria la fila que
 * se guardo como "ana@x.com".
 *
 * LA COMPARACION ES CONTRA EL CIFRADO LEGACY, con un fallback a bcrypt para las
 * filas que todavia no migro
 * cloud/sql/migrations/20260907_1200_controladores_contrasena_legacy.sql. El
 * fallback no es una concesion de diseno: es lo que evita que el orden entre
 * deploy y migracion deje a cloud sin nadie que pueda entrar.
 */

// Endpoint publico: no exige sesion previa.
define('CLOUD_API_PUBLIC', true);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/legacy_crypto.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metodo no permitido', 405);
}

try {
    $raw  = file_get_contents('php://input');
    $body = $raw === false || $raw === '' ? [] : json_decode($raw, true);
    if (!is_array($body)) $body = [];

    // `usuario` se sigue aceptando como alias de `correo`: es la clave que
    // mandaba el formulario viejo, y un login.php cacheado en el navegador no
    // tiene por que tirar un 422 despues del deploy.
    $correo     = strtolower(trim((string) ($body['correo'] ?? $body['usuario'] ?? '')));
    $contrasena = (string)                  ($body['contrasena'] ?? '');

    if ($correo === '' || $contrasena === '') {
        json_error('Correo y contrasena son obligatorios', 422);
    }

    $stmt = db()->prepare(
        'SELECT id, nombre, correo, contrasena, habilitado
           FROM controladores
          WHERE correo = :c
          LIMIT 1'
    );
    $stmt->execute([':c' => $correo]);
    $row = $stmt->fetch();

    if (!$row) {
        // Mensaje generico: no exponer si fallo el correo o la contrasena.
        json_error('Correo o contrasena incorrectos', 401);
    }

    // `controladores.habilitado` es tinyint(1) NOT NULL: entra solo con 1.
    if (!esHabilitado($row['habilitado'])) {
        json_error('El controlador esta deshabilitado', 403);
    }

    $guardada = (string) ($row['contrasena'] ?? '');

    if ($guardada === '') {
        json_error('Correo o contrasena incorrectos', 401);
    }

    if (preg_match('/^\$2[aby]\$/', $guardada) === 1) {
        // Fila sin migrar: sigue siendo bcrypt. password_verify ya es
        // constant-time.
        $ok = password_verify($contrasena, $guardada);
    } else {
        // hash_equals para comparacion constant-time (mitiga timing side-channels).
        $ok = hash_equals($guardada, reactor_legacy_encriptar($contrasena));
    }

    if (!$ok) {
        json_error('Correo o contrasena incorrectos', 401);
    }

    // `src` marca de que tabla salio este token. Lo exige authUser(), y es lo
    // que mata las sesiones emitidas contra `usuarios` antes de este cambio en
    // vez de dejarlas vivir las 12 h que les quedan de TTL: si el motivo del
    // cambio es que esa gente no tenia que estar adentro, esperar medio dia no
    // es cerrar la puerta.
    //
    // La clave `usuario` se conserva ademas de `correo` porque index.php la usa
    // como fallback del nombre a mostrar.
    $controladorPayload = [
        'id'      => (int) $row['id'],
        'src'     => 'ctl',
        'correo'  => (string) $row['correo'],
        'usuario' => (string) $row['correo'],
        'nombre'  => (string) ($row['nombre'] ?? ''),
    ];

    jwt_cookie_set(jwt_sign($controladorPayload, JWT_TTL));

    // Persistir el ultimo ingreso. Falla silenciosamente si la columna
    // estuviera ausente en alguna BD vieja: no bloquea el login.
    try {
        $upd = db()->prepare('UPDATE controladores SET ingresado = NOW() WHERE id = :id');
        $upd->execute([':id' => (int) $row['id']]);
    } catch (Throwable $_) { /* noop */ }

    json_ok(['usuario' => $controladorPayload]);
} catch (Throwable $e) {
    json_error('Error al procesar el login: ' . $e->getMessage(), 500);
}
