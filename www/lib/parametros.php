<?php

declare(strict_types=1);

/**
 * Lectura de la tabla `parametros` (id / variable / valor / comentario).
 *
 * Reemplaza a `$oParametro->valorLeer()` del framework legacy. El sitio usa
 * cuatro filas, todas con prefijo `web.`:
 *
 *   web.nombre    Reactor              nombre de marca, va en cada <title>
 *   web.dominio   reactor.com.ar
 *   web.portada   /img/bg/bg-12.jpg    fondo de la banda de título interior
 *   web.contexto  102
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

/**
 * Imagen de fondo de la banda de título de las páginas interiores.
 *
 * El default no es decorativo: si `web.portada` no estuviera, la banda quedaría
 * con `data-background=""` y el JS del theme le pondría un fondo vacío sobre el
 * overlay oscuro, o sea un rectángulo negro arriba de cada página interior.
 */
function wwwPortada(): string
{
    return wwwParametro('web.portada', '/img/bg/bg-12.jpg');
}
