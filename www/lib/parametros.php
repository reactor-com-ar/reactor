<?php

declare(strict_types=1);

/**
 * Lectura de la tabla `parametros` (id / variable / valor / comentario).
 *
 * Reemplaza a `$oParametro->valorLeer()` del framework legacy. De las cuatro
 * filas con prefijo `web.` que tiene la tabla, el sitio lee TRES:
 *
 *   web.nombre    Reactor          nombre de marca, va en cada <title>
 *   web.dominio   reactor.com.ar
 *   web.contexto  102
 *
 * LA CUARTA, `web.portada`, YA NO SE LEE (28/09/2026). Era el fondo de la banda
 * de título de las páginas interiores y hoy está escrito a mano en cada una
 * (`/img/bg/bg-portada.jpg`), por decisión explícita: no hace falta que sea
 * dinámico. La fila sigue en la tabla y el back office viejo la sigue
 * mostrando, así que ojo — **editarla ahí no cambia ninguna cabecera**. Para
 * cambiar el fondo se reemplaza el archivo o se editan las páginas.
 *
 * OJO: esta tabla NO es la del "Editor de parámetros" de los paneles nuevos,
 * que tiene columnas `clave` / `valor` / `descripcion`. Ésta es la histórica,
 * con `variable` / `valor` / `comentario`, y es la que llena el back office
 * viejo. Son dos esquemas distintos con el mismo nombre de tabla en proyectos
 * distintos; acá manda `db/schema.sql`.
 *
 * SE LEEN TODAS DE UNA. Son cuatro filas y una página puede pedir tres, así
 * que una consulta por parámetro serían tres viajes a la base para 200 bytes.
 * La caché es por request, no persistente: un cambio en el back office se ve
 * en el request siguiente.
 */

require_once __DIR__ . '/db.php';

/**
 * Valor de un parámetro, o `$default` si la fila no existe o está vacía.
 *
 * El default importa: si la base no responde con la fila —porque todavía no se
 * cargó, o porque alguien la borró— la página tiene que seguir dibujándose. Un
 * sitio público que tira 500 por un parámetro de presentación faltante es peor
 * que uno que muestra el valor de fábrica.
 */
function wwwParametro(string $variable, string $default = ''): string
{
    static $cache = null;

    if ($cache === null) {
        $cache = [];
        try {
            $filas = db()->query('SELECT variable, valor FROM parametros')->fetchAll();
            foreach ($filas as $fila) {
                $cache[(string) $fila['variable']] = (string) $fila['valor'];
            }
        } catch (Throwable $e) {
            // Queda la caché vacía: todo cae a los defaults de cada llamada.
            $cache = [];
        }
    }

    $valor = trim((string) ($cache[$variable] ?? ''));

    return $valor !== '' ? $valor : $default;
}

/** Nombre de marca. Va en el `<title>` de todas las páginas. */
function wwwNombre(): string
{
    return wwwParametro('web.nombre', 'Reactor');
}
