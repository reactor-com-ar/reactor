<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();         break;
        case 'PUT':    handleUpdateConfig(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar dispositivos: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $stmt = db()->query(
        'SELECT d.id, d.uid, d.nombre, d.tipo, d.ubicacion, d.estado,
                d.config_json, d.last_seen_at, d.created_at,
                d.dominio_id, dom.nombre AS dominio_nombre
         FROM dispositivos d
         JOIN dominios dom ON dom.id = d.dominio_id
         ORDER BY d.estado = "error" DESC, d.estado = "online" DESC, d.nombre ASC'
    );

    $dispositivos = array_map(static function (array $r): array {
        $r['dominio_id']  = (int) $r['dominio_id'];
        $r['config_json'] = $r['config_json'] !== null
            ? json_decode($r['config_json'], true)
            : null;
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'   => count($dispositivos),
        'online'  => 0,
        'offline' => 0,
        'error'   => 0,
    ];

    foreach ($dispositivos as $d) {
        $resumen[$d['estado']] = ($resumen[$d['estado']] ?? 0) + 1;
    }

    json_ok([
        'resumen'      => $resumen,
        'dispositivos' => $dispositivos,
    ]);
}

function handleUpdateConfig(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);

    if ($id <= 0) json_error('Id invalido', 422);
    if (!array_key_exists('config_json', $in)) json_error('Falta config_json', 422);

    $config = $in['config_json'];

    // Permitir null (limpiar config) o cualquier estructura JSON serializable.
    if ($config !== null) {
        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) json_error('config_json no es serializable', 422);
        if (strlen($encoded) > 65535) json_error('config_json supera 64 KB', 422);
    } else {
        $encoded = null;
    }

    $stmt = db()->prepare('UPDATE dispositivos SET config_json = :c WHERE id = :id');
    $stmt->execute([':c' => $encoded, ':id' => $id]);

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM dispositivos WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Dispositivo no encontrado', 404);
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
