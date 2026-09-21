<?php

declare(strict_types=1);

/**
 * Búsqueda por texto libre de los listados que filtran EN LA BASE.
 *
 * Es la contraparte SQL del método que `assets/js/app.js` aplica del lado del
 * navegador (`terminosBusqueda()` / `coincideBusqueda()`), y tiene que dar el
 * mismo resultado: los dos buscadores son el mismo buscador para quien lo usa,
 * y si difieren, un módulo encuentra lo que el otro no.
 *
 * Las cuatro garantías:
 *
 *   1. ACENTOS. No importan, y en las DOS puntas: `gonzalez` encuentra a
 *      `González` y al revés. Acá NO hay que plegar nada — lo hace la
 *      collation. Todas las columnas del esquema son `utf8mb4_..._ci`
 *      (`db/schema.sql`), y las `_ci` de utf8mb4 comparan `a` = `á`, `n` = `ñ`
 *      y `u` = `ü`. Verificado contra el motor: `SELECT "María" LIKE "%maria%"`
 *      y `SELECT "Niño" LIKE "%nino%"` dan 1.
 *      **Si alguna columna quedara con una collation acento-sensible
 *      (`..._as_cs`, `..._bin`), esta función deja de cumplir la garantía para
 *      esa columna** y lo que hay que arreglar es la collation, no la query.
 *   2. MAYÚSCULAS. Indistintas, por lo mismo: la collation es `_ci`.
 *   3. PEDAZOS DE PALABRA. `LIKE '%termino%'`, sin anclar.
 *   4. TÉRMINOS SUELTOS Y EN CUALQUIER ORDEN. Lo tipeado se parte por espacios.
 *
 * LOS TÉRMINOS SE CRUZAN CON Y; CADA TÉRMINO SE BUSCA EN TODAS LAS COLUMNAS CON
 * O. O sea: `(col1 LIKE :q0 OR col2 LIKE :q0) AND (col1 LIKE :q1 OR ...)`.
 * Agregar una palabra tiene que ACOTAR el resultado — con la O al revés el
 * segundo término lo agranda y el buscador empeora justo cuando más se lo
 * necesita. Es la misma regla que el front.
 *
 * `LIKE '%...%'` no usa índice: es un scan. Está bien sobre el universo ya
 * acotado del listado (el dominio de la sesión, la ventana por id); si la tabla
 * es de millones de filas, la búsqueda va DESPUÉS de esa cota y no en su lugar.
 *
 * Es copia idéntica de `cloud/lib/busqueda.php`, como `habilitado.php` y
 * `permisos.php`: las apps no comparten docroot.
 */

/**
 * Lo tipeado, partido en términos. Devuelve [] si no hay nada que buscar, que
 * es lo que hace que la consulta vacía no filtre.
 *
 * @return list<string>
 */
function busquedaTerminos(string $consulta): array
{
    $terminos = preg_split('/\s+/', trim($consulta), -1, PREG_SPLIT_NO_EMPTY);

    return $terminos === false ? [] : $terminos;
}

/**
 * Condiciones `AND` (una por término) y sus parámetros, para meter en el
 * `WHERE` de un listado.
 *
 * @param string        $consulta  Lo que se tipeó en el buscador.
 * @param list<string>  $columnas  Columnas calificadas: `['u.nombre', 'd.uuid']`.
 *                                 Van interpoladas en el SQL, así que salen del
 *                                 código y NUNCA del request.
 * @param string        $prefijo   Prefijo de los placeholders, por si el mismo
 *                                 query usa la función más de una vez.
 *
 * @return array{0: list<string>, 1: array<string, string>}
 *         `[$condiciones, $params]`. Con la consulta vacía, las dos vienen
 *         vacías y el llamador no agrega nada al `WHERE`.
 */
function busquedaWhere(string $consulta, array $columnas, string $prefijo = 'q'): array
{
    $condiciones = [];
    $params      = [];

    if ($columnas === []) {
        return [$condiciones, $params];
    }

    foreach (busquedaTerminos($consulta) as $i => $termino) {
        $valor = '%' . busquedaEscapar($termino) . '%';

        // UN PLACEHOLDER POR TERMINO **Y POR COLUMNA**, aunque el valor sea el
        // mismo en todas: con EMULATE_PREPARES=false, PDO no admite repetir un
        // nombre dentro del statement y corta con SQLSTATE[HY093] "Invalid
        // parameter number" (verificado contra el motor del proyecto, PHP
        // 8.2 + MySQL 8.0). Reusar `:q0` en las cuatro columnas seria lo
        // natural y es justamente lo que no compila.
        $ors = [];
        foreach ($columnas as $j => $columna) {
            $ph            = ':' . $prefijo . $i . '_' . $j;
            $ors[]         = $columna . ' LIKE ' . $ph;
            $params[$ph]   = $valor;
        }

        $condiciones[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [$condiciones, $params];
}

/**
 * Escapa los comodines de `LIKE` del término.
 *
 * Quien busca `50%` quiere el texto "50%", no "50 seguido de cualquier cosa", y
 * un `_` suelto matchearía todo. El `\` va primero porque es el escape mismo.
 * MySQL lo interpreta sin `ESCAPE` explícito: `\` es el caracter de escape por
 * defecto de `LIKE`.
 */
function busquedaEscapar(string $termino): string
{
    return addcslashes($termino, '\\%_');
}
