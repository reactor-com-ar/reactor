<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const DISPOSITIVO_ESTADOS = ['online', 'offline', 'error'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
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

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $data = validateDevicePayload($in);

    try {
        $stmt = db()->prepare(
            'UPDATE dispositivos
                SET uid = :uid, dominio_id = :dom, nombre = :nom, tipo = :tipo,
                    ubicacion = :ubi, estado = :est, config_json = :cfg
              WHERE id = :id'
        );
        $stmt->execute([
            ':uid'  => $data['uid'],
            ':dom'  => $data['dominio_id'],
            ':nom'  => $data['nombre'],
            ':tipo' => $data['tipo'],
            ':ubi'  => $data['ubicacion'],
            ':est'  => $data['estado'],
            ':cfg'  => $data['config_json'],
            ':id'   => $id,
        ]);
    } catch (PDOException $e) {
        if ((int) ($e->errorInfo[1] ?? 0) === 1062) {
            json_error('Ya existe un dispositivo con ese UID', 409);
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM dispositivos WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Dispositivo no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM dispositivos WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Dispositivo no encontrado', 404);

    json_ok(['id' => $id]);
}

function validateDevicePayload(array $in): array
{
    $uid        = trim((string) ($in['uid']       ?? ''));
    $dominio_id = (int)         ($in['dominio_id'] ?? 0);
    $nombre     = trim((string) ($in['nombre']    ?? ''));
    $tipo       = trim((string) ($in['tipo']      ?? ''));
    $ubicacion  = trim((string) ($in['ubicacion'] ?? ''));
    $estado     = trim((string) ($in['estado']    ?? 'offline'));

    if ($uid === '')                 json_error('El UID es obligatorio', 422);
    if (mb_strlen($uid) > 64)        json_error('El UID no puede superar 64 caracteres', 422);
    if ($dominio_id <= 0)            json_error('Elegi un dominio', 422);
    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)    json_error('El nombre no puede superar 120 caracteres', 422);
    if ($tipo === '')                json_error('El tipo es obligatorio', 422);
    if (mb_strlen($tipo) > 60)       json_error('El tipo no puede superar 60 caracteres', 422);
    if (mb_strlen($ubicacion) > 120) json_error('La ubicacion no puede superar 120 caracteres', 422);
    if (!in_array($estado, DISPOSITIVO_ESTADOS, true))
        json_error('Estado invalido', 422);

    $exists = db()->prepare('SELECT 1 FROM dominios WHERE id = :id');
    $exists->execute([':id' => $dominio_id]);
    if (!$exists->fetchColumn()) json_error('El dominio no existe', 422);

    if (!array_key_exists('config_json', $in)) json_error('Falta config_json', 422);
    $config = $in['config_json'];
    if ($config !== null) {
        $encoded = json_encode($config, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($encoded === false)       json_error('config_json no es serializable', 422);
        if (strlen($encoded) > 65535) json_error('config_json supera 64 KB', 422);
    } else {
        $encoded = null;
    }

    return [
        'uid'         => $uid,
        'dominio_id'  => $dominio_id,
        'nombre'      => $nombre,
        'tipo'        => $tipo,
        'ubicacion'   => $ubicacion === '' ? null : $ubicacion,
        'estado'      => $estado,
        'config_json' => $encoded,
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
