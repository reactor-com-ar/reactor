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
    // Esquema real (db/schema.sql -> tabla `dominios`): no existen `descripcion`,
    // `created_at` ni `updated_at`. La FK en `dispositivos` es la columna `dominio`
    // (no `dominio_id`). Se aliasan los campos faltantes a NULL para no tocar el JS.
    $stmt = db()->query(
        'SELECT d.id,
                d.nombre,
                NULL          AS descripcion,
                NULL          AS created_at,
                NULL          AS updated_at,
                COUNT(dev.id) AS dispositivos_count
         FROM dominios d
         LEFT JOIN dispositivos dev ON dev.dominio = d.id
         GROUP BY d.id, d.nombre
         ORDER BY d.nombre ASC'
    );

    $dominios = array_map(static function (array $r): array {
        $r['dispositivos_count'] = (int) $r['dispositivos_count'];
        return $r;
    }, $stmt->fetchAll());

    json_ok(['dominios' => $dominios]);
}

function handleCreate(): void
{
    $in     = readJson();
    $nombre = trim((string) ($in['nombre']      ?? ''));
    $desc   = trim((string) ($in['descripcion'] ?? ''));

    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)    json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('INSERT INTO dominios (nombre, descripcion) VALUES (:n, :d)');
        $stmt->execute([':n' => $nombre, ':d' => $desc === '' ? null : $desc]);
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
    $in     = readJson();
    $id     = (int) ($in['id'] ?? 0);
    $nombre = trim((string) ($in['nombre']      ?? ''));
    $desc   = trim((string) ($in['descripcion'] ?? ''));

    if ($id <= 0)                    json_error('Id invalido', 422);
    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)    json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('UPDATE dominios SET nombre = :n, descripcion = :d WHERE id = :id');
        $stmt->execute([':n' => $nombre, ':d' => $desc === '' ? null : $desc, ':id' => $id]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un dominio con ese nombre', 409);
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM dominios WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Dominio no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0)    json_error('Id invalido', 422);
    if ($id === 1)   json_error('No se puede eliminar el dominio por defecto "General"', 409);

    $pdo = db();

    $exists = $pdo->prepare('SELECT 1 FROM dominios WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) json_error('Dominio no encontrado', 404);

    try {
        $pdo->beginTransaction();

        // Reasignar dispositivos y chips al dominio por defecto "General" (id = 1).
        $pdo->prepare('UPDATE dispositivos SET dominio_id = 1 WHERE dominio_id = :id')
            ->execute([':id' => $id]);
        $pdo->prepare('UPDATE chips        SET dominio_id = 1 WHERE dominio_id = :id')
            ->execute([':id' => $id]);

        // Los perfiles son permisos por dominio: si el dominio desaparece, se borran.
        $pdo->prepare('DELETE FROM perfiles WHERE dominio_id = :id')
            ->execute([':id' => $id]);

        $pdo->prepare('DELETE FROM dominios WHERE id = :id')
            ->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

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
