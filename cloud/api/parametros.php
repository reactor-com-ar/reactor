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
    json_error('Error al procesar parametros: ' . $e->getMessage(), 500);
}

/**
 * Listado de parametros (db/schema.sql -> tabla `parametros`).
 * Columnas reales: id, variable, valor, comentario.
 */
function handleList(): void
{
    $stmt = db()->query(
        'SELECT id, variable, valor, comentario
         FROM parametros
         ORDER BY variable ASC, id ASC'
    );
    json_ok(['parametros' => $stmt->fetchAll()]);
}

function handleCreate(): void
{
    $data = validarPayload(readJson());

    $stmt = db()->prepare(
        'INSERT INTO parametros (variable, valor, comentario)
         VALUES (:var, :val, :com)'
    );
    $stmt->execute([
        ':var' => $data['variable'],
        ':val' => $data['valor'],
        ':com' => $data['comentario'],
    ]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $data = validarPayload($in);

    $stmt = db()->prepare(
        'UPDATE parametros
            SET variable = :var, valor = :val, comentario = :com
          WHERE id = :id'
    );
    $stmt->execute([
        ':var' => $data['variable'],
        ':val' => $data['valor'],
        ':com' => $data['comentario'],
        ':id'  => $id,
    ]);

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM parametros WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Parametro no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM parametros WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Parametro no encontrado', 404);

    json_ok(['id' => $id]);
}

function validarPayload(array $in): array
{
    $variable   = trim((string) ($in['variable']   ?? ''));
    $valor      = trim((string) ($in['valor']      ?? ''));
    $comentario = trim((string) ($in['comentario'] ?? ''));

    if ($variable === '')             json_error('La variable es obligatoria', 422);
    if (mb_strlen($variable)   > 255) json_error('La variable no puede superar 255 caracteres', 422);
    if (mb_strlen($valor)      > 255) json_error('El valor no puede superar 255 caracteres', 422);
    if (mb_strlen($comentario) > 1024) json_error('El comentario no puede superar 1024 caracteres', 422);

    return [
        'variable'   => $variable,
        'valor'      => $valor      === '' ? null : $valor,
        'comentario' => $comentario === '' ? null : $comentario,
    ];
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
