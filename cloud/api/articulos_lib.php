<?php

declare(strict_types=1);

/**
 * Piezas compartidas por `articulos.php` (listado / ABM) y
 * `articulos_accion.php` (recalcular los precios de una fila).
 *
 * Estan en un archivo aparte y no duplicadas en los dos por lo mismo que
 * `comprobantes_lib.php`: LA CUENTA DE PRECIOS ES UNA SOLA. El ABM la aplica al
 * guardar y la accion la aplica sin tocar nada mas; dos copias de
 * `articuloPrecios()` son dos formas de que el precio que muestra la
 * previsualizacion no sea el que despues queda escrito.
 *
 * LA CUENTA, QUE ES EL CORAZON DEL MODULO. Sale de `cArticulo::recalcular()`
 * del sistema historico y son dos renglones:
 *
 *     si moneda = 'D':  compra = importacion * cotizacion del dolar
 *     siempre:          venta  = compra + (compra * margen / 100)
 *
 * O sea que de las cuatro columnas de plata sólo DOS se cargan a mano
 * (`importacion` y `margen`), una es derivada a medias (`compra`: en dolares la
 * calcula la cotizacion, en pesos se escribe) y la ultima es derivada siempre
 * (`venta`). Verificado contra la base: las 106 filas cumplen
 * `venta = compra * (1 + margen/100)` al centavo.
 *
 * LA COTIZACION NO ES UNA CONSTANTE NI UNA COLUMNA: vive en
 * `parametros`.`articulos.dolar.cotizacion` y la mueve la tarea
 * `cloud/jobs/dolar_actualizar.php` todos los dias a las 06:00, con la punta
 * `venta` del microservicio de Databox.
 *
 * ESA TAREA NO RECALCULA LOS ARTICULOS, y el robot del legacy al que reemplaza
 * (`reactor-api/robot/articulosActualizar.php`) si lo hacia: recorria la tabla
 * entera llamando a `recalcular()`. Acá mover la cotizacion y repreciar son dos
 * cosas distintas a proposito — la segunda le cambia el abono a contratos vivos
 * y por eso es una accion con nombre, que muestra los cuatro numeros y los
 * planes afectados antes de confirmar.
 *
 * Consecuencia: las filas en dolares tienen cotizaciones implicitas distintas
 * entre si —1370, 1375, 1380, 1415 y 1450 al 30/09/2026— segun cuando se las
 * toco por ultima vez. NO estan desactualizadas por error, es el estado normal
 * de la tabla entre dos recalculos.
 */

/** Claves de `combos` con los textos de los codigos cortos de `articulos`. */
const COMBO_TIPO        = '$xArticulo->tipo';
const COMBO_MONEDA      = '$xArticulo->moneda';
const COMBO_VISIBILIDAD = '$xArticulo->visibilidad';
const COMBO_HABILITADO  = '$xArticulo->habilitado';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_TIPO        => ['P' => 'Producto', 'S' => 'Servicio', 'C' => 'Componente'],
    COMBO_MONEDA      => ['D' => 'Dolar',    'P' => 'Peso'],
    COMBO_VISIBILIDAD => ['0' => 'Privado',  '1' => 'Publico'],
    COMBO_HABILITADO  => ['1' => 'Si',       '0' => 'No'],
];

/**
 * Los dos valores de `articulos`.`moneda` (varchar(1)).
 *
 * NO es una bandera de dos estados como `habilitado`: decide de donde sale
 * `compra`, asi que se compara como STRING y contra la constante. Hoy son 79
 * filas en dolares y 27 en pesos.
 */
const MONEDA_DOLAR = 'D';
const MONEDA_PESO  = 'P';

/** `parametros`.`variable` con la cotizacion y con cuando se actualizo. */
const PARAMETRO_COTIZACION       = 'articulos.dolar.cotizacion';
const PARAMETRO_COTIZACION_FECHA = 'articulos.dolar.actualizado';

/**
 * Tope de `decimal(10,2)`.
 *
 * Se chequea ANTES del INSERT y no se recorta: MySQL en modo estricto corta con
 * "Out of range value", que es un 500 sin nombre de campo, y en modo laxo
 * guarda 99999999.99 en silencio -- un precio inventado. Con la cotizacion en
 * 1420 alcanza una importacion de 70.423 USD para pasarse.
 */
const DECIMAL_MAX = 99999999.99;

/**
 * Valor de un parametro del sistema historico, cacheado por request.
 *
 * `parametros` es la tabla del legacy (`variable` / `valor` / `comentario`), no
 * la de claves del Editor de parametros de cloud.
 */
function parametroValor(string $variable): string
{
    static $cache = [];
    if (isset($cache[$variable])) return $cache[$variable];

    $stmt = db()->prepare('SELECT valor FROM parametros WHERE variable = :v LIMIT 1');
    $stmt->execute([':v' => $variable]);

    return $cache[$variable] = trim((string) ($stmt->fetchColumn() ?: ''));
}

/**
 * Cotizacion del dolar con la que se valorizan los articulos importados.
 *
 * Devuelve 0 si el parametro no esta o no es un numero, y el llamador lo
 * bloquea: con una cotizacion en cero toda la tabla en dolares pasaria a costar
 * $ 0,00, que es peor que no recalcular.
 */
function cotizacionDolar(): float
{
    $valor = parametroValor(PARAMETRO_COTIZACION);
    if ($valor === '' || !is_numeric($valor)) return 0.0;

    return (float) $valor;
}

/**
 * Los dos precios derivados, con la cuenta de `cArticulo::recalcular()`.
 *
 * `$compra` sólo se respeta en pesos: en dolares la pisa la cotizacion, que es
 * exactamente lo que hace el legacy en cada guardado.
 */
function articuloPrecios(string $moneda, float $importacion, float $compra, float $margen): array
{
    if ($moneda === MONEDA_DOLAR) {
        $compra = $importacion * cotizacionDolar();
    }

    $venta = $compra + ($compra * $margen / 100);

    // Se redondea aca y no se deja que lo haga la columna para que el numero
    // que devuelve la previsualizacion sea, al centavo, el que queda escrito.
    return ['compra' => round($compra, 2), 'venta' => round($venta, 2)];
}

/** Tabla plana valor -> texto de un combo del sistema historico. */
function combo(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) return $cache[$clave];

    $stmt = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, id ASC');
    $stmt->execute([':c' => $clave]);

    $textos = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') continue;
        $textos[$valor] = (string) ($r['texto'] ?? '');
    }

    if ($textos === []) $textos = COMBOS_FALLBACK[$clave] ?? [];

    return $cache[$clave] = $textos;
}

/** El mismo combo, como lista ordenada para poblar un <select>. */
function comboLista(string $clave): array
{
    $items = [];
    foreach (combo($clave) as $valor => $texto) {
        $items[] = ['valor' => (string) $valor, 'texto' => $texto];
    }
    return $items;
}

/**
 * Id de una FK del sistema historico, donde el 0 significa "sin asignar" igual
 * que el NULL (ver el criterio del `0` centinela en `db/schema.sql`).
 */
function idOrNull(mixed $v): ?int
{
    $id = (int) $v;
    return $id > 0 ? $id : null;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
