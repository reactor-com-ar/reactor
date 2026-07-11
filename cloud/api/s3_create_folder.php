<?php

declare(strict_types=1);

// Explorador S3 — crear "carpeta" (objeto vacío con key terminada en /).
// POST json api/s3_create_folder.php  { prefix, nombre }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/s3.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

try {
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $prefix = ltrim((string) ($body['prefix'] ?? ''), '/');
    if ($prefix !== '' && substr($prefix, -1) !== '/') $prefix .= '/';

    $nombre = s3SanearNombre((string) ($body['nombre'] ?? ''));
    if ($nombre === '') json_error('Nombre invalido', 400);

    $key = $prefix . $nombre . '/';
    s3PutObject($key, '', 'application/x-directory');

    json_ok(['key' => $key]);
} catch (Throwable $e) {
    json_error('S3 create_folder: ' . $e->getMessage(), 500);
}
