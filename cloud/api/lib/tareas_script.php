<?php

declare(strict_types=1);

/**
 * Resuelve `tareas`.`script` a una ruta absoluta del contenedor.
 *
 * Lo usan los DOS lados que lanzan un job —`jobs/_scheduler.php` (el tick
 * minutal) y `api/tareas_ejecutar.php` (el "Ejecutar ahora" del menu de fila)—
 * y tiene que ser una sola funcion: si los dos resolvieran distinto, una tarea
 * andaria a mano y no por cron, o al reves.
 *
 * POR QUE NO ALCANZA CON `<raiz del monorepo>/<script>`. La columna guarda la
 * ruta relativa a la raiz del monorepo (`cloud/jobs/dolar_actualizar.php`) —es
 * lo que lista `api/tareas_scripts_disponibles.php`— y eso resuelve en
 * produccion, donde el repo entero vive en `/opt/app/reactor/`. En desarrollo
 * no: `docker-compose.yml` monta `./cloud` directamente como docroot
 * (`/var/www/html`), asi que adentro del contenedor NO EXISTE la raiz del
 * monorepo y `/var/www/cloud/jobs/...` no es ningun archivo. El sintoma es un
 * `.log` que dice "Could not open input file" y una fila que queda en
 * `corriendo` hasta que la barre el watchdog.
 *
 * Por eso hay un segundo intento que resuelve contra la carpeta de `cloud`,
 * que es la unica que los dos entornos tienen. El primero sigue siendo el de
 * la raiz del monorepo: es el que vale en produccion y el que hay que
 * conservar si algun dia una tarea apunta a un script de otra app del repo.
 */

/**
 * @param string $script valor de `tareas`.`script`, relativo a la raiz del repo
 * @param string $dirApp SUBCARPETA DIRECTA de `cloud` — `__DIR__` del que
 *                       llama: `cloud/jobs` o `cloud/api`. De ahi cuelgan los
 *                       dos intentos (`/../..` y `/..`).
 */
function tareaScriptAbs(string $script, string $dirApp): string
{
    $script = ltrim(str_replace('\\', '/', $script), '/');

    $repoRoot = realpath($dirApp . '/../..');
    if ($repoRoot !== false) {
        $abs = $repoRoot . '/' . $script;
        if (is_file($abs)) return $abs;
    }

    // Fallback de desarrollo: `cloud/jobs/x.php` -> `<cloud>/jobs/x.php`.
    $cloudRoot = realpath($dirApp . '/..');
    if ($cloudRoot !== false) {
        $abs = $cloudRoot . '/' . preg_replace('#^cloud/#', '', $script);
        if (is_file($abs)) return $abs;
    }

    // Ninguno existe: se devuelve la forma canonica igual. El error tiene que
    // salir de `php` y quedar escrito en el .log de la ejecucion —con el nombre
    // del archivo que no encontro—, no de un `throw` silencioso aca.
    return ($repoRoot !== false ? $repoRoot : $dirApp) . '/' . $script;
}
