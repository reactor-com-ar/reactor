<?php

declare(strict_types=1);

// Programador de tareas — listado de scripts disponibles (skill §7.5).
// Escanea cloud/jobs/ y filtra los que arrancan con `_` (infra) y los que
// no son .php. Los path relativos al root del monorepo.
//
// LA RUTA SE ARMA, NO SE DERIVA DEL FILESYSTEM. Es siempre `cloud/jobs/<x>.php`
// porque el directorio escaneado es siempre `<cloud>/jobs`. Derivarla restando
// la raiz del monorepo (`realpath(__DIR__ . '/../..')`) daba la ruta correcta en
// produccion —el repo entero vive en `/opt/app/reactor/`— y `html/jobs/<x>.php`
// en desarrollo, donde `docker-compose.yml` monta `./cloud` directamente como
// docroot (`/var/www/html`) y adentro del contenedor no existe la raiz del
// monorepo. O sea: el combo del modal ofrecia un valor que no coincidia con el
// que tiene guardado la tarea, y guardarlo dejaba la fila apuntando a una ruta
// que no significa nada del otro lado. Es el mismo desajuste que resuelve
// `lib/tareas_script.php` para el lanzamiento (DESIGN.md §33).

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $jobsDir = realpath(__DIR__ . '/../jobs');
    if ($jobsDir === false) { json_ok([]); }

    $scripts = [];
    foreach (scandir($jobsDir) ?: [] as $f) {
        if ($f === '.' || $f === '..')            continue;
        if ($f[0] === '_')                        continue;
        if (substr($f, -4) !== '.php')            continue;
        if (!is_file($jobsDir . DIRECTORY_SEPARATOR . $f)) continue;
        $scripts[] = 'cloud/jobs/' . $f;
    }
    sort($scripts, SORT_NATURAL);
    json_ok($scripts);
} catch (Throwable $e) {
    json_error('scripts_disponibles: ' . $e->getMessage(), 500);
}
