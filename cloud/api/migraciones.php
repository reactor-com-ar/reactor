<?php

declare(strict_types=1);

// Migrador DB — endpoint de listado.
// Cruza los .sql del directorio de migraciones contra el ledger `migraciones`.
// GET api/migraciones.php  ->  { database, env, items[] }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/migraciones.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $pdo = db();
    asegurarTablaMigraciones($pdo);

    $database = (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
    $env      = strtolower((string) (defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'unknown')));

    $dir      = migracionesDir();
    $archivos = [];
    if (is_dir($dir)) {
        foreach (glob($dir . '/*.sql') ?: [] as $ruta) {
            $archivos[] = basename($ruta);
        }
        sort($archivos, SORT_STRING);
    }

    $ledger = [];
    foreach ($pdo->query('SELECT id, nombre, hash, aplicada FROM migraciones') as $row) {
        if ($row['nombre'] === null || $row['nombre'] === '') continue;
        $ledger[$row['nombre']] = $row;
    }

    $items = [];
    foreach ($archivos as $nombre) {
        $ruta        = $dir . '/' . $nombre;
        $contenido   = (string) @file_get_contents($ruta);
        $hashActual  = hash('sha256', $contenido);
        $tamano      = strlen($contenido);
        $registro    = $ledger[$nombre] ?? null;
        $aplicada    = $registro['aplicada'] ?? null;
        $hashLedger  = $registro['hash']     ?? null;

        $items[] = [
            'id'         => $registro !== null ? (int) $registro['id'] : null,
            'nombre'     => $nombre,
            'hash'       => $hashActual,
            'tamano'     => $tamano,
            'aplicada'   => $aplicada,
            'hash_drift' => $registro !== null && $hashLedger !== null && $hashLedger !== $hashActual,
            'estado'     => $registro !== null ? 'aplicada' : 'pendiente',
        ];
    }

    json_ok([
        'database' => $database,
        'env'      => $env,
        'items'    => $items,
    ]);
} catch (Throwable $e) {
    json_error('Error al listar migraciones: ' . $e->getMessage(), 500);
}
