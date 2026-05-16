<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

try {
    $stmt = db()->query(
        'SELECT id, uid, name, type, location, status, last_seen_at, created_at
         FROM devices
         ORDER BY status = "error" DESC, status = "online" DESC, name ASC'
    );

    $devices = $stmt->fetchAll();

    $summary = [
        'total'   => count($devices),
        'online'  => 0,
        'offline' => 0,
        'error'   => 0,
    ];

    foreach ($devices as $d) {
        $summary[$d['status']] = ($summary[$d['status']] ?? 0) + 1;
    }

    json_response([
        'summary' => $summary,
        'devices' => $devices,
    ]);
} catch (Throwable $e) {
    json_error('No se pudieron obtener los dispositivos: ' . $e->getMessage(), 500);
}
