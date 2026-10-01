<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
// La facturacion entera -- contexto, renglones, emision y avance del ciclo --
// vive en el lib y la comparte con `jobs/contratos_facturar.php`, la tarea que
// factura el 1 de cada mes. El lib arrastra `comprobantes_lib.php`.
require_once __DIR__ . '/contratos_facturar_lib.php';

/**
 * Acciones de negocio sobre un contrato: `?accion=<nombre>`.
 *
 *   facturar  Emite el comprobante del periodo y adelanta el ciclo del contrato.
 *   baja      Marca la fecha de baja y deshabilita el contrato.
 *
 * Van en un endpoint aparte y NO como un `PUT` sobre `contratos.php` por la
 * misma razon que `comprobantes_accion.php`: ninguna de las dos es "guardar los
 * campos que mandaste". Cada una es una transicion con su propia precondicion y
 * `facturar` ademas escribe en tres tablas (`comprobantes`,
 * `comprobantesrenglones`, `talonarios`) antes de tocar el contrato. Mezclarlas
 * con la edicion haria que un payload con `habilitado` adentro pudiera saltearse
 * la precondicion.
 *
 * EL VERBO DISTINGUE PREVISUALIZAR DE EJECUTAR: `GET` devuelve lo que la accion
 * VA a hacer y `POST` la hace. Es el mismo reparto que ya usa el borrado de
 * contratos (`GET contratos.php?impacto=1&id=N` y despues el `DELETE`), y la
 * unica forma de que la pantalla de confirmacion muestre numeros reales --
 * cliente, talonario, renglones e importes-- en vez de una frase generica.
 * `baja` no tiene `GET`: no hay nada que calcular, el `confirmDialog` alcanza.
 *
 * LAS PRECONDICIONES SE LEEN DE LA BASE EN CADA REQUEST, nunca de lo que manda
 * el front, y el `POST` las vuelve a chequear DENTRO de la transaccion con el
 * contrato bloqueado. El front esconde lo que no corresponde, pero esconder el
 * boton no es el control (CLAUDE.md).
 *
 * ESTE ENDPOINT YA NO ES EL UNICO QUE FACTURA. Desde el 01/10/2026 lo hace
 * tambien `jobs/contratos_facturar.php`, el 1 de cada mes a las 09:00, sobre
 * todos los contratos facturables. Los dos emiten el MISMO comprobante porque
 * los dos llaman a `facturarContexto()` + `facturarEmitir()` de
 * `contratos_facturar_lib.php`: lo que queda aca es HTTP -- leer el id, traducir
 * los bloqueos a un 409 y devolver el JSON --. Esta accion sigue siendo la que
 * se usa para facturar YA, fuera de ciclo, y la unica que muestra los numeros
 * antes de escribir.
 *
 * LO QUE NO SE PORTO de `cContrato`:
 *
 *   - `deshabilitar()`, que ademas de dar de baja apaga TODOS los contratos del
 *     dominio y le borra a `dominios`.`contrato` la referencia. El menu del back
 *     office viejo NO lo llama: "Dar baja" es `editar.php?mod=baj`, que escribe
 *     `baja` y `habilitado` y nada mas. Se porta lo que el menu hacia: dejar al
 *     dominio sin contrato vigente es otra decision, y no la que ofrece una
 *     pantalla que dice "dar de baja este contrato".
 *   - El resto (el fallback al plan 100, las columnas que migraron al talonario)
 *     esta documentado en la cabecera de `contratos_facturar_lib.php`, que es
 *     donde vive ahora la portacion de `cContrato::facturar()`.
 */

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        switch ($accion) {
            case 'facturar': previoFacturar(); break;
            default:
                json_error('Accion desconocida o sin previsualizacion', 400);
        }
    } elseif ($method === 'POST') {
        switch ($accion) {
            case 'facturar': accionFacturar(); break;
            case 'baja':     accionBaja();     break;
            default:
                json_error('Accion desconocida', 400);
        }
    } else {
        json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al ejecutar la accion: ' . $e->getMessage(), 500);
}

/** Id del contrato sobre el que opera la accion. */
function contratoPedido(): int
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);
    return $id;
}

/* -------------------------------------------------------------- facturar */

/**
 * Previsualizacion: que comprobante saldria si se facturara ahora.
 *
 * Devuelve la MISMA cuenta que despues hace el `POST` -- las dos llaman a
 * `facturarContexto()` --, mas los bloqueos y los avisos. Un bloqueo impide
 * emitir y el front no dibuja el boton; un aviso se muestra y deja seguir.
 */
function previoFacturar(): void
{
    $id  = contratoPedido();
    $con = contratoFila($id);
    if (!$con) json_error('Contrato no encontrado', 404);

    $ctx = facturarContexto($con);

    $talonario = $ctx['talonario'];
    $proxima   = $talonario === null ? null : ((int) $talonario['serie']) + 1;

    json_ok([
        'contrato' => [
            'id'   => (int) $con['id'],
            'uuid' => trim((string) ($con['uuid'] ?? '')),
        ],
        // Como queda el ciclo del contrato DESPUES de emitir. Va en su propia
        // clave y no junto a los datos del contrato para que no se lea como el
        // valor actual: el periodo que se factura pasa a `facturado` y
        // `facturar` avanza un mes.
        'resultado' => [
            'facturado' => $ctx['periodo'],
            'facturar'  => $ctx['siguiente'],
        ],
        'dominio' => $ctx['dominio'] === null ? null : [
            'id'     => (int) $ctx['dominio']['id'],
            'nombre' => trim((string) ($ctx['dominio']['nombre'] ?? '')),
        ],
        'cliente' => $ctx['cliente'] === null ? null : [
            'id'        => (int) $ctx['cliente']['id'],
            'nombre'    => trim((string) ($ctx['cliente']['nombre'] ?? '')),
            'razon'     => trim((string) ($ctx['cliente']['razon'] ?? '')),
            'cuit'      => trim((string) ($ctx['cliente']['cuit']  ?? '')),
            'correo'    => trim((string) ($ctx['cliente']['correo'] ?? '')),
            'condicion' => trim((string) ($ctx['cliente']['condicion'] ?? '')),
        ],
        'talonario' => $talonario === null ? null : [
            'id'              => (int) $talonario['id'],
            'nombre'          => trim((string) ($talonario['nombre'] ?? '')),
            'tipo_texto'      => combo(COMBO_TALONARIO_TIPO)[trim((string) ($talonario['tipo'] ?? ''))] ?? '',
            'proxima_serie'   => $proxima,
            // Indicativo: lo definitivo lo toma el POST con el talonario
            // bloqueado. Si entre medio se emite otro comprobante, este se
            // lleva el siguiente y no el que dice la pantalla.
            'proximo_numero'  => comprobanteNumero($talonario['punto'] ?? null, $proxima),
        ],
        'periodo'     => $ctx['periodoTexto'],
        'emision'     => $ctx['emision'],
        'vencimiento' => $ctx['vencimiento'],
        'cotizacion'  => $ctx['cotizacion'],
        'renglones'   => $ctx['renglones'],
        'totales'     => comprobanteTotalesDe($ctx['renglones']),
        'bloqueos'    => $ctx['bloqueos'],
        'avisos'      => $ctx['avisos'],
    ]);
}

/**
 * Emite el comprobante del periodo y adelanta el ciclo del contrato.
 *
 * La emision entera es `facturarEmitir()`; lo que queda aca es la transaccion,
 * el `FOR UPDATE` sobre el contrato -- que es lo que impide que dos clicks
 * seguidos emitan dos veces el mismo periodo: el segundo espera, y cuando entra
 * ya ve `facturar` adelantado un mes -- y la traduccion a HTTP.
 */
function accionFacturar(): void
{
    $id  = contratoPedido();
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $con = contratoFila($id, true);
        if (!$con) { $pdo->rollBack(); json_error('Contrato no encontrado', 404); }

        $ctx = facturarContexto($con);
        if ($ctx['bloqueos'] !== []) {
            $pdo->rollBack();
            json_error('No se puede facturar. ' . implode(' ', $ctx['bloqueos']), 409);
        }

        $emitido = facturarEmitir($con, $ctx);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok([
        'contrato'    => $id,
        'comprobante' => $emitido['comprobante'],
        'serie'       => $emitido['serie'],
        'numero'      => $emitido['numero'],
        'totales'     => $emitido['totales'],
        'facturado'   => $emitido['facturado'],
        'facturar'    => $emitido['facturar'],
        'avisos'      => $ctx['avisos'],
    ], 201);
}

/* ------------------------------------------------------------------ baja */

/**
 * Dar de baja: fecha de baja hoy y contrato deshabilitado.
 *
 * Es `editar.php?mod=baj` del back office viejo, que es lo que hacia el item
 * "Dar baja" del menu -- NO `cContrato::deshabilitar()`, que ademas apaga los
 * otros contratos del dominio y le borra la referencia (ver la cabecera).
 *
 * `baja` es una columna `date`: se escribe la fecha de hoy y no `NOW()`, que es
 * lo que termina guardando el legacy al mandarle un datetime a un `date`.
 */
function accionBaja(): void
{
    $id  = contratoPedido();
    $con = contratoFila($id);
    if (!$con) json_error('Contrato no encontrado', 404);

    if (!esHabilitado($con['habilitado'])) {
        json_error('El contrato ya esta dado de baja.', 409);
    }

    $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');

    db()->prepare('UPDATE contratos SET baja = :baja, habilitado = 0 WHERE id = :id')
        ->execute([':baja' => $hoy, ':id' => $id]);

    json_ok(['id' => $id, 'baja' => $hoy]);
}
