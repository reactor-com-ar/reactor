<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $stmt = db()->query(
        'SELECT d.id, d.uid, d.name, d.type, d.location, d.status,
                d.last_seen_at, d.created_at,
                d.domain_id, dom.name AS domain_name
         FROM devices d
         JOIN domains dom ON dom.id = d.domain_id
         ORDER BY d.status = "error" DESC, d.status = "online" DESC, d.name ASC'
    );

    $devices = array_map(static function (array $r): array {
        $r['domain_id'] = (int) $r['domain_id'];
        return $r;
    }, $stmt->fetchAll());

    $summary = [
        'total'   => count($devices),
        'online'  => 0,
        'offline' => 0,
        'error'   => 0,
    ];

    foreach ($devices as $d) {
        $summary[$d['status']] = ($summary[$d['status']] ?? 0) + 1;
    }

    json_ok([
        'summary' => $summary,
        'devices' => $devices,
    ]);
} catch (Throwable $e) {
    json_error('No se pudieron obtener los dispositivos: ' . $e->getMessage(), 500);
}
