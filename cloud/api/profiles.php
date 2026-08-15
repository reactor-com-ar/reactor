<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const ROLES_PERFIL = ['admin', 'operador'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar perfiles: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $usuarioId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;

    $sql = 'SELECT p.id, p.usuario_id, p.dominio_id, p.rol,
                   p.created_at, p.updated_at,
                   u.nombre AS usuario_nombre, u.email AS usuario_email, u.activo AS usuario_activo,
                   d.nombre AS dominio_nombre
            FROM perfiles p
            JOIN usuarios u ON u.id = p.usuario_id
            JOIN dominios d ON d.id = p.dominio_id';
    $params = [];
    if ($usuarioId > 0) {
        $sql .= ' WHERE p.usuario_id = :uid';
        $params[':uid'] = $usuarioId;
    }
    $sql .= ' ORDER BY u.nombre ASC, d.nombre ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $perfiles = array_map(static function (array $r): array {
        $r['usuario_id']     = (int) $r['usuario_id'];
        $r['dominio_id']     = (int) $r['dominio_id'];
        $r['usuario_activo'] = (int) $r['usuario_activo'] === 1;
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'     => count($perfiles),
        'admin'     => 0,
        'operador'  => 0,
    ];
    foreach ($perfiles as $p) {
        if ($p['rol'] === 'admin')    $resumen['admin']++;
        if ($p['rol'] === 'operador') $resumen['operador']++;
    }

    json_ok(['perfiles' => $perfiles, 'resumen' => $resumen]);
}

function handleCreate(): void
{
    $in         = readJson();
    $usuario_id = (int) ($in['usuario_id'] ?? 0);
    $dominio_id = (int) ($in['dominio_id'] ?? 0);
    $rol        = trim((string) ($in['rol'] ?? 'operador'));

    validarPerfil($usuario_id, $dominio_id, $rol);

    try {
        $stmt = db()->prepare(
            'INSERT INTO perfiles (usuario_id, dominio_id, rol) VALUES (:u, :d, :r)'
        );
        $stmt->execute([':u' => $usuario_id, ':d' => $dominio_id, ':r' => $rol]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ese usuario ya tiene un perfil en ese dominio', 409);
        }
        if ((int) $e->errorInfo[1] === 1452) {
            json_error('Usuario o dominio inexistente', 422);
        }
        throw $e;
    }

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in  = readJson();
    $id  = (int) ($in['id'] ?? 0);
    $rol = trim((string) ($in['rol'] ?? ''));

    if ($id <= 0) json_error('Id invalido', 422);
    if (!in_array($rol, ROLES_PERFIL, true)) json_error('Rol invalido', 422);

    $stmt = db()->prepare('UPDATE perfiles SET rol = :r WHERE id = :id');
    $stmt->execute([':r' => $rol, ':id' => $id]);

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM perfiles WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Perfil no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM perfiles WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Perfil no encontrado', 404);

    json_ok(['id' => $id]);
}

function validarPerfil(int $usuario_id, int $dominio_id, string $rol): void
{
    if ($usuario_id <= 0) json_error('Usuario invalido', 422);
    if ($dominio_id <= 0) json_error('Dominio invalido', 422);
    if (!in_array($rol, ROLES_PERFIL, true)) json_error('Rol invalido', 422);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
