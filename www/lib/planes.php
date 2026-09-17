<?php

declare(strict_types=1);

/**
 * Los planes publicados y sus precios.
 *
 * `planes` guarda el nombre y el orden; el PRECIO no está ahí sino en
 * `articulos`, al que cada plan apunta con `planes`.`articulo`. De ese artículo
 * salen las dos monedas:
 *
 *   articulos.importacion -> el precio en dólares
 *   articulos.venta       -> el precio en pesos
 *
 * No son "costo" y "precio final" a pesar de cómo se llaman: `importacion` es
 * lo que el sitio publica como USD y `venta` lo que publica como ARS. Lo fija
 * el legacy y se respeta, porque los números que ve el cliente tienen que ser
 * los mismos que factura el sistema viejo.
 *
 * `planes`.`tipo` separa las familias:
 *
 *   'S'  Standard   por cantidad de usuarios   -> /precios/planes/standard
 *   'D'  Developer  por peticiones a la API    -> /precios/planes/developer
 *   'T'  (interno, no se publica en el sitio)
 *
 * `planes`.`habilitado` SÍ es la bandera del repo (tinyint 0/1), así que va con
 * `esHabilitado()` y con el entero en el SQL — ver `lib/habilitado.php`. Ojo con
 * confundirla con `entradas`.`visibilidad` o `tecnicos`.`aprobacion`, que
 * son varchar y se comparan contra la cadena.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/habilitado.php';
require_once __DIR__ . '/parametros.php';

const PLANES_STANDARD  = 'S';
const PLANES_DEVELOPER = 'D';

/**
 * Los planes publicados de una familia, con los dos precios ya resueltos.
 *
 * El JOIN es LEFT y el precio cae a 0 cuando el plan no tiene artículo
 * asociado: es lo que hacía el legacy y es lo correcto para el plan gratuito,
 * que existe justamente para valer cero.
 */
function planesListar(string $tipo): array
{
    $sql = db()->prepare(
        'SELECT p.id, p.nombre, p.descripcion, p.orden, p.usuarios, p.dispositivos, p.usos,
                COALESCE(a.importacion, 0) AS usd,
                COALESCE(a.venta, 0)       AS ars
           FROM planes p
           LEFT JOIN articulos a ON a.id = p.articulo
          WHERE p.tipo = :tipo AND p.habilitado = :hab
          ORDER BY p.orden, p.nombre'
    );
    $sql->execute([':tipo' => $tipo, ':hab' => HABILITADO]);

    return $sql->fetchAll();
}

/**
 * Cotización del dólar con la que el sitio pasa de USD a ARS.
 *
 * Sale del parámetro `articulos.dolar.cotizacion`, el mismo que usa el sistema
 * de facturación, y lo actualiza una tarea — `articulos.dolar.actualizado` dice
 * cuándo fue la última vez.
 *
 * El default de 0 es deliberado: si el parámetro faltara, el cotizador muestra
 * 0 en la columna de pesos en vez de un precio inventado con una cotización de
 * fantasía.
 */
function planesDolar(): float
{
    return (float) wwwParametro('articulos.dolar.cotizacion', '0');
}

/** Cuándo se actualizó por última vez la cotización, o '' si no consta. */
function planesDolarActualizado(): string
{
    return wwwParametro('articulos.dolar.actualizado');
}

/**
 * Formatea un importe como lo hacía `$oNumero->moneda()`: dos decimales, punto
 * para los miles y coma para los decimales.
 */
function planesMoneda(float|string $monto, string $prefijo = ''): string
{
    return $prefijo . number_format((float) $monto, 2, ',', '.');
}

/** Desde cuántos usuarios el plan a medida deja de ser gratuito. */
const PLANES_COTIZADOR_DESDE = 200;

/** A partir de cuántos usuarios el precio unitario toca su piso. */
const PLANES_COTIZADOR_HASTA = 1000;

/** Precio por usuario en el escalón de entrada (200 usuarios). */
const PLANES_COTIZADOR_UNITARIO_MAXIMO = 0.15;

/** Piso del precio por usuario: no baja de acá por muchos usuarios que haya. */
const PLANES_COTIZADOR_UNITARIO_MINIMO = 0.05;

/**
 * Cotiza un plan a medida por cantidad de usuarios.
 *
 * El precio por usuario BAJA a medida que suben los usuarios: arranca en USD
 * 0,15 con 200 usuarios y desciende en línea recta hasta USD 0,05 con 1.000,
 * donde toca piso. Por debajo de 200 usuarios el plan a medida no se cotiza —
 * ese tramo lo cubren los planes Standard de la lista.
 *
 * (El comentario del legacy decía que el unitario "sube hasta 0.05 con 1000
 * usuarios". No sube: baja. La cuenta siempre fue un descuento por volumen; lo
 * que estaba mal escrito era la frase. También había dos variables muertas,
 * `$unitarioInicial` y `$unitarioFactor`, de una fórmula anterior que ya no se
 * usaba.)
 *
 * @return array{usuarios:int,unitario:float,usd:float,ars:float,dolar:float}
 */
function planesCotizar(int $usuarios): array
{
    $usuarios = max(0, $usuarios);

    if ($usuarios < PLANES_COTIZADOR_DESDE) {
        $unitario = 0.0;
    } else {
        $pendiente = (PLANES_COTIZADOR_UNITARIO_MINIMO - PLANES_COTIZADOR_UNITARIO_MAXIMO)
            / (PLANES_COTIZADOR_HASTA - PLANES_COTIZADOR_DESDE);
        $unitario  = PLANES_COTIZADOR_UNITARIO_MAXIMO
            + (($usuarios - PLANES_COTIZADOR_DESDE) * $pendiente);
        $unitario  = max(PLANES_COTIZADOR_UNITARIO_MINIMO, $unitario);
    }

    $dolar = planesDolar();
    $usd   = $usuarios * $unitario;

    return [
        'usuarios' => $usuarios,
        'unitario' => $unitario,
        'usd'      => $usd,
        'ars'      => $usd * $dolar,
        'dolar'    => $dolar,
    ];
}
