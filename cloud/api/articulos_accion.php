<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/articulos_lib.php';

/**
 * Acciones de negocio sobre un articulo: `?accion=<nombre>`.
 *
 *   recalcular  Vuelve a valorizar el articulo con la cotizacion vigente.
 *
 * Es el `Actualizar` del menu `Acciones` del back office viejo
 * (`articulos/consultar.php` -> `editar?mod=act`), que hace exactamente
 * `leer()` + `recalcular()` + `modificar()`. Va en un endpoint aparte y no como
 * un `PUT` sobre `articulos.php` por lo que manda `ABM.md`: no es "guardar los
 * campos que mandaste" -- no manda ninguno --, es una transicion que reescribe
 * dos columnas derivadas a partir de un parametro del sistema.
 *
 * EL VERBO DISTINGUE PREVISUALIZAR DE EJECUTAR: `GET` devuelve lo que la accion
 * VA a hacer --los dos precios de antes y los de despues, con la cotizacion con
 * la que salen-- y `POST` la hace. Los dos resuelven la cuenta con la MISMA
 * funcion (`articuloPrecios()` de `articulos_lib.php`), que es lo unico que
 * garantiza que el numero que anuncia la pantalla sea el que queda escrito.
 *
 * LA PRECONDICION SE LEE DE LA BASE EN CADA REQUEST y el `POST` la vuelve a
 * chequear: sin cotizacion cargada un articulo en dolares quedaria en $ 0,00 y
 * el modal ni siquiera ofrece el boton. Esconder el boton no es el control
 * (CLAUDE.md).
 *
 * POR QUE NO HAY UN "RECALCULAR TODO". El robot del sistema historico
 * (`reactor-api/robot/articulosActualizar.php`) recorre la tabla entera y sigue
 * siendo el que lo hace en masa. Esta accion es la de UNA fila, que es la que
 * ofrece la pantalla de la que se porto: un boton que reescriba el precio de
 * los 106 articulos desde un menu de fila no es la misma decision.
 */

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        switch ($accion) {
            case 'recalcular': previoRecalcular(); break;
            default:
                json_error('Accion desconocida o sin previsualizacion', 400);
        }
    } elseif ($method === 'POST') {
        switch ($accion) {
            case 'recalcular': accionRecalcular(); break;
            default:
                json_error('Accion desconocida', 400);
        }
    } else {
        json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al ejecutar la accion: ' . $e->getMessage(), 500);
}

/** Id del articulo sobre el que opera la accion. */
function articuloPedido(): int
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);
    return $id;
}

/** La fila con lo que hace falta para la cuenta. */
function articuloLeer(int $id): array
{
    $stmt = db()->prepare(
        'SELECT id, nombre, moneda, importacion, compra, margen, venta
         FROM articulos WHERE id = :id'
    );
    $stmt->execute([':id' => $id]);
    $r = $stmt->fetch();
    if (!$r) json_error('Articulo no encontrado', 404);

    return $r;
}

/**
 * Lo que la accion va a escribir, mas los bloqueos y los avisos.
 *
 * `bloqueos` y `avisos` son dos listas distintas y no dos tonos de la misma
 * (DESIGN.md §15.2): el primero impide seguir, el segundo es lo que conviene
 * mirar antes de confirmar -- y aca lo que hay que mirar es que el abono de los
 * planes que facturan este articulo cambia con el precio.
 */
function calculoRecalcular(int $id): array
{
    $r = articuloLeer($id);

    $moneda      = trim((string) ($r['moneda'] ?? ''));
    $importacion = (float) $r['importacion'];
    $compra      = (float) $r['compra'];
    $margen      = (float) $r['margen'];
    $venta       = (float) $r['venta'];

    $cotizacion = cotizacionDolar();
    $nuevo      = articuloPrecios($moneda, $importacion, $compra, $margen);

    $bloqueos = [];
    if ($moneda === MONEDA_DOLAR && $cotizacion <= 0) {
        $bloqueos[] = [
            'label'    => 'No hay cotizacion del dolar cargada en parametros (' . PARAMETRO_COTIZACION . ')',
            'cantidad' => 0,
        ];
    }
    if ($nuevo['compra'] > DECIMAL_MAX || $nuevo['venta'] > DECIMAL_MAX) {
        $bloqueos[] = [
            'label'    => 'El precio resultante supera el maximo de la columna',
            'cantidad' => 0,
        ];
    }

    $cambia = abs($nuevo['compra'] - $compra) > 0.01 || abs($nuevo['venta'] - $venta) > 0.01;

    $avisos = [];
    if (!$cambia) {
        $avisos[] = ['label' => 'Los precios ya estan al dia: recalcular no los va a cambiar', 'cantidad' => 0];
    }

    // Los planes que facturan este articulo cobran `articulos`.`venta` como
    // abono (`cContrato::facturar()`), asi que tocar el precio les cambia el
    // abono a todos los contratos que cuelgan de ellos. Es plata: va dicho antes
    // de confirmar, con los numeros.
    $planes = db()->prepare(
        'SELECT p.id, p.nombre,
                (SELECT COUNT(*) FROM contratos c WHERE c.plan = p.id) AS contratos_count
         FROM planes p WHERE p.articulo = :id ORDER BY p.orden ASC, p.id ASC'
    );
    $planes->execute([':id' => $id]);
    $planesAfectados = array_map(static fn(array $p): array => [
        'id'              => (int) $p['id'],
        'nombre'          => trim((string) ($p['nombre'] ?? '')),
        'contratos_count' => (int) $p['contratos_count'],
    ], $planes->fetchAll());

    if ($cambia && $planesAfectados !== []) {
        $contratos = array_sum(array_column($planesAfectados, 'contratos_count'));
        $avisos[] = [
            'label'    => 'Planes que facturan este articulo como abono',
            'cantidad' => count($planesAfectados),
        ];
        if ($contratos > 0) {
            $avisos[] = ['label' => 'Contratos que cobran esos planes', 'cantidad' => $contratos];
        }
    }

    return [
        'articulo' => [
            'id'           => (int) $r['id'],
            'nombre'       => trim((string) ($r['nombre'] ?? '')),
            'moneda'       => $moneda,
            'moneda_texto' => combo(COMBO_MONEDA)[$moneda] ?? '',
            'importacion'  => $importacion,
            'margen'       => $margen,
        ],
        'cotizacion'       => $cotizacion,
        'cotizacion_fecha' => parametroValor(PARAMETRO_COTIZACION_FECHA),
        'antes'  => ['compra' => $compra,           'venta' => $venta],
        'despues'=> ['compra' => $nuevo['compra'],  'venta' => $nuevo['venta']],
        'cambia' => $cambia,
        'planes' => $planesAfectados,
        'bloqueos' => $bloqueos,
        'avisos'   => $avisos,
    ];
}

function previoRecalcular(): void
{
    json_ok(calculoRecalcular(articuloPedido()));
}

function accionRecalcular(): void
{
    $id = articuloPedido();

    db()->beginTransaction();
    try {
        // La cuenta se rehace DENTRO de la transaccion y con la fila bloqueada:
        // entre la previsualizacion y el click alguien pudo editar el articulo
        // o mover la cotizacion, y lo que se escribe tiene que salir de lo que
        // hay ahora, no de lo que mostro la pantalla.
        $lock = db()->prepare('SELECT id FROM articulos WHERE id = :id FOR UPDATE');
        $lock->execute([':id' => $id]);
        if (!$lock->fetchColumn()) {
            db()->rollBack();
            json_error('Articulo no encontrado', 404);
        }

        $calculo = calculoRecalcular($id);
        if ($calculo['bloqueos'] !== []) {
            db()->rollBack();
            json_error($calculo['bloqueos'][0]['label'], 409);
        }

        $stmt = db()->prepare('UPDATE articulos SET compra = :compra, venta = :venta WHERE id = :id');
        $stmt->execute([
            ':compra' => $calculo['despues']['compra'],
            ':venta'  => $calculo['despues']['venta'],
            ':id'     => $id,
        ]);

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) db()->rollBack();
        throw $e;
    }

    json_ok([
        'id'     => $id,
        'nombre' => $calculo['articulo']['nombre'],
        'compra' => $calculo['despues']['compra'],
        'venta'  => $calculo['despues']['venta'],
    ]);
}
