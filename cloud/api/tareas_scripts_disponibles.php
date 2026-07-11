<?php

declare(strict_types=1);

// Programador de tareas — listado de scripts disponibles (skill §7.5).
// Escanea cloud/jobs/ y filtra los que arrancan con `_` (infra) y los que
// no son .php. Los path relativos al root del monorepo.

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $jobsDir  = realpath(__DIR__ . '/../jobs');
    $repoRoot = realpath(__DIR__ . '/../..');
    if ($jobsDir === false) { json_ok([]); }

    $scripts = [];
    foreach (scandir($jobsDir) ?: [] as $f) {
        if ($f === '.' || $f === '..')            continue;
        if ($f[0] === '_')                        continue;
        if (substr($f, -4) !== '.php')            continue;
        $abs = $jobsDir . DIRECTORY_SEPARATOR . $f;
        if (!is_file($abs))                       continue;
        $rel = substr($abs, strlen($repoRoot) + 1);
        // Windows -> POSIX.
        $rel = str_replace('\\', '/', $rel);
        $scripts[] = $rel;
    }
    sort($scripts, SORT_NATURAL);
    json_ok($scripts);
} catch (Throwable $e) {
    json_error('scripts_disponibles: ' . $e->getMessage(), 500);
}
