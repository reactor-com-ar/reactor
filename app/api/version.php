<?php
declare(strict_types=1);

/*
 * Endpoint minimo para el banner de nueva version del front. Devuelve
 * `{ ok: true, version: "<contenido de version.txt>" }`. Es publico (no
 * requiere auth, no toca DB) porque solo expone la version del artefacto
 * frontend.
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$file    = dirname(__DIR__) . '/version.txt';
$version = is_readable($file) ? trim((string) file_get_contents($file)) : 'dev';

echo json_encode(['ok' => true, 'version' => $version]);
