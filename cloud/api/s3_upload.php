<?php

declare(strict_types=1);

// Explorador S3 — upload.
// POST multipart api/s3_upload.php  { archivo, prefix, nombre? }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/s3.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

// Shutdown handler para que un fatal siga devolviendo JSON.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'Fatal: ' . $err['message']]);
    }
});

try {
    if (!isset($_FILES['archivo']) || $_FILES['archivo']['error'] !== UPLOAD_ERR_OK) {
        json_error('Debe adjuntarse un archivo', 400);
    }
    $file = $_FILES['archivo'];
    if ($file['size'] > 20 * 1024 * 1024) {
        json_error('El archivo supera el limite de 20 MB', 413);
    }

    $prefix = ltrim((string) ($_POST['prefix'] ?? ''), '/');
    if ($prefix !== '' && substr($prefix, -1) !== '/') $prefix .= '/';

    $nombre = s3SanearNombre((string) ($_POST['nombre'] ?? $file['name']));
    if ($nombre === '') json_error('Nombre invalido', 400);

    $content = (string) file_get_contents($file['tmp_name']);
    // MIME real (no confiar en el header del cliente).
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string) $finfo->buffer($content) ?: 'application/octet-stream';

    $key = $prefix . $nombre;
    s3PutObject($key, $content, $mime);

    json_ok([
        'bucket'       => AWS_S3_BUCKET,
        'key'          => $key,
        'url'          => s3PublicUrl($key),
        'size'         => strlen($content),
        'content_type' => $mime,
    ]);
} catch (Throwable $e) {
    json_error('S3 upload: ' . $e->getMessage(), 500);
}
