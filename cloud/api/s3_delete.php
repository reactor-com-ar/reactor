<?php

declare(strict_types=1);

// Explorador S3 — delete (archivo o carpeta recursiva).
// POST json api/s3_delete.php  { key, recursivo }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/s3.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

try {
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $key       = ltrim((string) ($body['key'] ?? ''), '/');
    $recursivo = !empty($body['recursivo']);

    // Hard guard.
    if ($key === '' || $key === '/' || $key === '*') {
        json_error('Operacion no permitida', 400);
    }

    $eliminados = 0;
    $errores    = [];

    $esCarpeta = substr($key, -1) === '/';
    if ($esCarpeta && $recursivo) {
        $todos = s3ListAllUnderPrefix($key);
        foreach ($todos as $k) {
            try { s3DeleteObject($k); $eliminados++; }
            catch (Throwable $e) { $errores[] = ['key' => $k, 'error' => $e->getMessage()]; }
        }
        // Marker de carpeta (si sobrevivió porque no estaba entre los listados).
        try { s3DeleteObject($key); $eliminados++; } catch (Throwable $_) {}
    } else {
        s3DeleteObject($key);
        $eliminados = 1;
    }

    json_ok([
        'key'        => $key,
        'eliminados' => $eliminados,
        'errores'    => $errores,
    ]);
} catch (Throwable $e) {
    json_error('S3 delete: ' . $e->getMessage(), 500);
}
