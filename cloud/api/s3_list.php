<?php

declare(strict_types=1);

// Explorador S3 — listado por prefijo.
// GET api/s3_list.php?prefix=X&token=Y

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/s3.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $prefix = ltrim((string) ($_GET['prefix'] ?? ''), '/');
    $token  = (string) ($_GET['token']  ?? '');

    $res = s3ListObjects($prefix, $token !== '' ? $token : null);

    // Filtrar el marker de carpeta (key === prefix) del listado de objects.
    $objects = array_values(array_filter($res['objects'], fn($o) => $o['key'] !== $prefix));

    $objects = array_map(function ($o) {
        return [
            'key'           => $o['key'],
            'size'          => $o['size'],
            'last_modified' => $o['last_modified'],
            'url'           => s3PublicUrl($o['key']),
        ];
    }, $objects);

    json_ok([
        'bucket'     => AWS_S3_BUCKET,
        'region'     => AWS_REGION,
        'prefix'     => $prefix,
        'folders'    => $res['folders'],
        'objects'    => $objects,
        'truncated'  => $res['truncated'],
        'next_token' => $res['next_token'],
    ]);
} catch (Throwable $e) {
    json_error('S3 list: ' . $e->getMessage(), 500);
}
