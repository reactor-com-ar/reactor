<?php

declare(strict_types=1);

/**
 * Búsqueda por texto libre, el método único del proyecto (skill
 * `buscador_avanzado`). Lo que tipea una persona se parte en términos y cada
 * término se busca como subcadena en todas las columnas declaradas:
 *
 *   - los términos se cruzan con Y (agregar una palabra ACOTA el resultado),
 *   - cada término se busca en todas las columnas con O,
 *   - van sueltos y en cualquier orden: `plan gratis` encuentra "gratis" y
 *     "plan" aunque estén separados y al revés,
 *   - los acentos y las mayúsculas no importan, en las dos puntas, y eso sale
 *     gratis de la collation `utf8mb4_unicode_ci` de la base: `LIKE '%nino%'`
 *     encuentra `Niño` sin que haya que plegar nada acá.
 *
 * SE RESUELVE EN EL `WHERE`, NUNCA FILTRANDO UN ARRAY EN PHP NI EN JAVASCRIPT.
 * Un filtro sobre lo ya traído sólo ve la ventana que se trajo, así que contesta
 * "no hay resultados" sobre filas que existen.
 *
 * POR QUÉ ESTÁ ACÁ Y NO DENTRO DE `entradas.php`. Hoy el único que lo usa es el
 * chat (`lib/chat.php`), para elegir qué artículos de ayuda le pasa al modelo, y
 * ahí la diferencia es la que decide si el chat contesta o no: una pregunta
 * entera —"¿cómo configuro el wifi del dispositivo?"— buscada como una sola
 * cadena no coincide con ningún artículo, y partida en términos encuentra los
 * que hablan de wifi y de configuración.
 *
 * `entradasListar()` todavía busca la frase entera con un solo LIKE, que es lo
 * que hace `/search/results`. Convertirlo es un cambio de comportamiento del
 * buscador público —encuentra más— y va aparte de esto.
 */

/** Lo tipeado, partido en términos. `[]` si no hay nada que buscar. */
function busquedaTerminos(string $consulta): array
{
    $terminos = preg_split('/\s+/', trim($consulta), -1, PREG_SPLIT_NO_EMPTY);

    return $terminos === false ? [] : $terminos;
}

/**
 * Condiciones AND (una por término) y sus parámetros, listos para el `WHERE`.
 *
 * `$columnas` sale del CÓDIGO y nunca del request: va interpolada en el SQL, así
 * que un nombre de columna que llegara de afuera sería inyección.
 *
 * @return array{0: string[], 1: array<string,string>}
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

        // UN PLACEHOLDER POR TÉRMINO Y POR COLUMNA, aunque el valor sea el
        // mismo: con `ATTR_EMULATE_PREPARES => false` —que es como está `db()`—
        // PDO mapea cada nombre a UNA posición, así que reusar `:q0` en varias
        // columnas corta con SQLSTATE[HY093] "Invalid parameter number".
        $ors = [];
        foreach ($columnas as $j => $columna) {
            $ph          = ':' . $prefijo . $i . '_' . $j;
            $ors[]       = $columna . ' LIKE ' . $ph;
            $params[$ph] = $valor;
        }
        $condiciones[] = '(' . implode(' OR ', $ors) . ')';
    }

    return [$condiciones, $params];
}

/**
 * Escapa los comodines de `LIKE`. El `\` va primero: es el escape mismo.
 *
 * Sin esto, buscar `100%` devuelve todo lo que empiece con `100` y un `_` suelto
 * matchea cualquier caracter.
 */
function busquedaEscapar(string $termino): string
{
    return addcslashes($termino, '\\%_');
}
