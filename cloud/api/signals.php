<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido', 405);
    }
    handleList();
} catch (Throwable $e) {
    json_error('Error al procesar senales: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $dispositivoId = isset($_GET['dispositivo_id']) ? (int) $_GET['dispositivo_id'] : 0;
    $limit         = isset($_GET['limit']) ? (int) $_GET['limit'] : 500;
    if ($limit <= 0 || $limit > 2000) $limit = 500;

    $sql = 'SELECT s.id, s.dispositivo_id, s.topic, s.canal_id, s.canal_label,
                   s.tipo, s.valor, s.payload, s.recibido_at, s.created_at,
                   d.uid AS dispositivo_uid, d.nombre AS dispositivo_nombre,
                   dom.id AS dominio_id, dom.nombre AS dominio_nombre
            FROM senales s
            JOIN dispositivos d ON d.id = s.dispositivo_id
            JOIN dominios dom   ON dom.id = d.dominio_id';

    $params = [];
    if ($dispositivoId > 0) {
        $sql .= ' WHERE s.dispositivo_id = :did';
        $params[':did'] = $dispositivoId;
    }
    $sql .= ' ORDER BY s.recibido_at DESC, s.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $senales = array_map(static function (array $r): array {
        $r['id']             = (int) $r['id'];
        $r['dispositivo_id'] = (int) $r['dispositivo_id'];
        $r['dominio_id']     = (int) $r['dominio_id'];
        $r['canal_id']       = $r['canal_id'] !== null ? (int) $r['canal_id'] : null;
        return $r;
    }, $stmt->fetchAll());

    $desde24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
    $total    = (int) db()->query('SELECT COUNT(*) FROM senales')->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM senales WHERE recibido_at >= :t');
    $stmt->execute([':t' => $desde24h]);
    $ultimas24h = (int) $stmt->fetchColumn();

    $errores = (int) db()->query("SELECT COUNT(*) FROM senales WHERE tipo = 'error'")->fetchColumn();
    $dispositivosActivos = (int) db()->query(
        'SELECT COUNT(DISTINCT dispositivo_id) FROM senales WHERE recibido_at >= "' . $desde24h . '"'
    )->fetchColumn();

    $resumen = [
        'total'                => $total,
        'ultimas_24h'          => $ultimas24h,
        'errores'              => $errores,
        'dispositivos_activos' => $dispositivosActivos,
    ];

    json_ok([
        'resumen' => $resumen,
        'senales' => $senales,
        'limit'   => $limit,
    ]);
}
