<?php

declare(strict_types=1);

/**
 * Perfil del usuario logueado.
 *
 * GET api/perfil.php -> datos de `usuarios` para el id que viaja en el JWT.
 * Solo lectura: el modal de Perfil del panel es informativo.
 */

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Metodo no permitido', 405);
}

$auth = authUser();
$id   = (int) ($auth['id'] ?? 0);
if ($id <= 0) {
    json_error('Sesion invalida', 401);
}

try {
    // MyISAM sin FKs: los nombres de dominio / perfil se resuelven por LEFT JOIN
    // y pueden venir NULL si el id apunta a un registro borrado.
    $stmt = db()->prepare(
        'SELECT u.id, u.uuid, u.nombre, u.usuario, u.correo, u.celular,
                u.habilitado, u.registrado, u.ingresado,
                u.dominio AS dominio_id, d.nombre AS dominio_nombre,
                u.perfil  AS perfil_id,  p.nombre AS perfil_nombre
         FROM usuarios u
         LEFT JOIN dominios d ON d.id = u.dominio
         LEFT JOIN perfiles p ON p.id = u.perfil
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    if (!$row) {
        json_error('El usuario ya no existe', 404);
    }

    $habilitado = strtoupper(trim((string) ($row['habilitado'] ?? '')));

    json_ok([
        'id'             => (int) $row['id'],
        'uuid'           => (string) ($row['uuid'] ?? ''),
        'nombre'         => (string) ($row['nombre'] ?? ''),
        'usuario'        => (string) ($row['usuario'] ?? ''),
        'correo'         => (string) ($row['correo'] ?? ''),
        'celular'        => (string) ($row['celular'] ?? ''),
        'habilitado'     => in_array($habilitado, ['S', '1', 'Y'], true),
        'dominio_id'     => $row['dominio_id'] !== null ? (int) $row['dominio_id'] : null,
        'dominio_nombre' => (string) ($row['dominio_nombre'] ?? ''),
        'perfil_id'      => $row['perfil_id'] !== null ? (int) $row['perfil_id'] : null,
        'perfil_nombre'  => (string) ($row['perfil_nombre'] ?? ''),
        'registrado'     => (string) ($row['registrado'] ?? ''),
        'ingresado'      => (string) ($row['ingresado'] ?? ''),
    ]);
} catch (Throwable $e) {
    json_error('Error al obtener el perfil: ' . $e->getMessage(), 500);
}
