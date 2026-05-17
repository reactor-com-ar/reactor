<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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
    json_error('Error al procesar dominios: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $stmt = db()->query(
        'SELECT d.id, d.name, d.description, d.created_at, d.updated_at,
                COUNT(dev.id) AS device_count
         FROM domains d
         LEFT JOIN devices dev ON dev.domain_id = d.id
         GROUP BY d.id
         ORDER BY d.name ASC'
    );

    $domains = array_map(static function (array $r): array {
        $r['device_count'] = (int) $r['device_count'];
        return $r;
    }, $stmt->fetchAll());

    json_ok(['domains' => $domains]);
}

function handleCreate(): void
{
    $in   = readJson();
    $name = trim((string) ($in['name'] ?? ''));
    $desc = trim((string) ($in['description'] ?? ''));

    if ($name === '')                json_error('El nombre es obligatorio', 422);
    if (mb_strlen($name) > 120)      json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('INSERT INTO domains (name, description) VALUES (:n, :d)');
        $stmt->execute([':n' => $name, ':d' => $desc === '' ? null : $desc]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un dominio con ese nombre', 409);
        }
        throw $e;
    }

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in   = readJson();
    $id   = (int) ($in['id'] ?? 0);
    $name = trim((string) ($in['name'] ?? ''));
    $desc = trim((string) ($in['description'] ?? ''));

    if ($id <= 0)                    json_error('Id invalido', 422);
    if ($name === '')                json_error('El nombre es obligatorio', 422);
    if (mb_strlen($name) > 120)      json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('UPDATE domains SET name = :n, description = :d WHERE id = :id');
        $stmt->execute([':n' => $name, ':d' => $desc === '' ? null : $desc, ':id' => $id]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un dominio con ese nombre', 409);
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM domains WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Dominio no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $count = (int) db()->query('SELECT COUNT(*) FROM devices WHERE domain_id = ' . $id)->fetchColumn();
    if ($count > 0) {
        json_error("No se puede eliminar: el dominio tiene $count dispositivo(s) asociado(s)", 409);
    }

    $stmt = db()->prepare('DELETE FROM domains WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Dominio no encontrado', 404);

    json_ok(['id' => $id]);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
