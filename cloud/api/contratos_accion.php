<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
// La facturacion entera -- contexto, renglones, emision y avance del ciclo --
// vive en el lib y la comparte con `jobs/contratos_facturar.php`, la tarea que
// factura el 1 de cada mes. El lib arrastra `comprobantes_lib.php`.
require_once __DIR__ . '/contratos_facturar_lib.php';
// La transicion de estado comercial entera -- las cuatro columnas de `contratos`
// y `dominios` que mueven `baja` y `alta` -- vive en su propio lib y lo comparte
// con `jobs/contratos_baja_morosos.php`, la tarea del dia 15.
require_once __DIR__ . '/contratos_estado_lib.php';
// El calculo de la mora -- bandas, que cuenta como deuda y la cuenta que las
// traduce -- vive en su propio lib y lo comparten el barrido de las 04:00, la
// tarea del dia 15 y los dos `Actualizar situacion` (este y el de Dominios).
require_once __DIR__ . '/contratos_situacion_lib.php';
// `baja` y `alta` apagan y prenden el dominio de un cliente: cada una deja un
// suceso para que el movimiento se pueda ubicar en el tiempo desde el Visor sin
// ir a buscar quien lo hizo.
require_once __DIR__ . '/lib/sucesos.php';

/**
 * Acciones de negocio sobre un contrato: `?accion=<nombre>`.
 *
 *   facturar  Emite el comprobante del periodo y adelanta el ciclo del contrato.
 *   baja      Deshabilita el contrato y su dominio, y lo deja Suspendido.
 *   alta      Habilita el contrato y su dominio, y lo deja en situacion Normal.
 *   situacion Recalcula la mora de este contrato y la de su dominio.
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
 * `baja` y `alta` no tienen `GET`: no hay nada que calcular, el `confirmDialog`
 * alcanza.
 *
 * `baja` Y `alta` SON LA MISMA TRANSICION AL REVES, y las dos escriben en DOS
 * tablas (ver "el estado comercial" mas abajo). Nunca estan disponibles las dos
 * sobre el mismo contrato: una exige `habilitado = 1` y la otra `= 0`.
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
 *   - `deshabilitar()`, que ademas apaga TODOS los contratos del dominio y le
 *     borra a `dominios`.`contrato` la referencia. Ninguna de las dos cosas se
 *     hace aca: apagar los contratos de al lado es decidir sobre contratos que
 *     nadie abrio, y borrar la referencia deja al dominio sin contrato vigente
 *     -- otra decision, y no la que ofrece una pantalla que dice "dar de baja
 *     este contrato". Lo que si se hace, y el legacy no, es apagar el DOMINIO:
 *     ver abajo.
 *   - El resto (el fallback al plan 100, las columnas que migraron al talonario)
 *     esta documentado en la cabecera de `contratos_facturar_lib.php`, que es
 *     donde vive ahora la portacion de `cContrato::facturar()`.
 *
 * ------------------------------------------------------------------------
 * EL ESTADO COMERCIAL: `baja` / `alta` ESCRIBEN EL CONTRATO **Y** EL DOMINIO
 * ------------------------------------------------------------------------
 *
 * Las dos acciones mueven cuatro columnas de dos tablas, y las cuatro en la
 * MISMA transaccion:
 *
 *                      | baja                  | alta
 *   -------------------+-----------------------+------------------------
 *   contratos.habilitado | 0                   | 1
 *   contratos.baja       | hoy                 | el centinela apocalipsis
 *   dominios.habilitado  | 0                   | 1
 *   dominios.situacion   | '3' Suspendido      | '1' Normal
 *
 * EN UNA SOLA TRANSACCION PORQUE ES UNA SOLA AFIRMACION. Escribir el contrato y
 * no el dominio deja al cliente con el contrato dado de baja y la app operando
 * -- dos cosas distintas sobre el mismo cliente --, que es exactamente el estado
 * que `jobs/contratos_situacion_recalcular.php` existe para no producir.
 *
 * LO QUE CORTA EL SERVICIO ES `situacion`, NO `habilitado`. `app/index.php` lee
 * `dominios`.`situacion` y con '3' no dibuja NINGUN control de operacion; de
 * `dominios`.`habilitado` no mira nada -- esa es la bandera administrativa que
 * gobierna los listados de `cloud` y `panel`. O sea que el renglon que le apaga
 * la app al cliente es el cuarto de la tabla de arriba.
 *
 * `contratos`.`situacion` NO SE TOCA, ni en la baja ni en el alta. La escribe
 * una sola tarea (`contratos_situacion_recalcular.php`, 04:00) y sigue siendo
 * asi: es la mora calculada, no el estado comercial que se decide aca. En un
 * contrato dado de baja queda el ultimo valor que tuvo, que es el dato historico
 * -- el mismo criterio con el que ese job no toca los deshabilitados.
 *
 * Y POR ESO ESTA ESCRITURA NO ES PARA SIEMPRE: a las 04:00 del dia siguiente el
 * job recalcula la mora de los contratos habilitados y puede volver a mover
 * `dominios`.`situacion`. Es lo correcto -- el job mira la deuda real --, pero
 * hay que leerlo asi: estas dos acciones fijan el estado de HOY y el job lo
 * revisa manana. Un alta sobre un contrato con facturas vencidas vuelve a
 * Suspendido en la corrida siguiente, y un dominio con otro contrato habilitado
 * al dia vuelve a Normal despues de una baja (gana la peor situacion de sus
 * contratos habilitados; un contrato dado de baja ya no cuenta para ese calculo,
 * y un dominio sin ningun contrato habilitado el job no lo toca -- o sea que la
 * suspension de una baja se sostiene sola).
 *
 * `alta` LIMPIA `contratos`.`baja` y eso no es un extra: la columna significa
 * "cuando se dio de baja este contrato", asi que un contrato habilitado con una
 * fecha de baja cargada son dos afirmaciones que se contradicen, y la ficha la
 * mostraria. Se escribe el centinela apocalipsis ('2500-01-01'), que es el "no
 * se dio de baja" que pone `cContrato::nuevo()` y el que `fechaReal()` traduce a
 * `null` al salir -- no se borra a `NULL` ni se deja en `0000-00-00`.
 *
 * EL ORDEN DE LOS LOCKS ES `contratos` Y DESPUES `dominios`, el mismo que toma
 * `contratos_situacion_recalcular.php`. Dos escrituras encimadas -- esta accion
 * y la corrida de las 04:00 -- se esperan en vez de abrazarse.
 */

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        switch ($accion) {
            case 'facturar':  previoFacturar();  break;
            case 'situacion': previoSituacion(); break;
            default:
                json_error('Accion desconocida o sin previsualizacion', 400);
        }
    } elseif ($method === 'POST') {
        switch ($accion) {
            case 'facturar':  accionFacturar();  break;
            case 'baja':      accionBaja();      break;
            case 'alta':      accionAlta();      break;
            case 'situacion': accionSituacion(); break;
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

/* ----------------------------------------------------------- baja / alta */

/**
 * Dar de baja: contrato deshabilitado con fecha de baja de hoy, y su dominio
 * deshabilitado y Suspendido.
 *
 * El contrato es `editar.php?mod=baj` del back office viejo -- NO
 * `cContrato::deshabilitar()`, que ademas apaga los otros contratos del dominio
 * y le borra la referencia --. El dominio es la parte que el legacy no hacia:
 * ver "el estado comercial" en la cabecera.
 *
 * LA TRANSICION NO VIVE ACA: la hace `contratoBaja()` de
 * `contratos_estado_lib.php`, que comparte con la tarea del dia 15
 * (`jobs/contratos_baja_morosos.php`). Lo que queda en esta funcion es HTTP --
 * leer el id, abrir la transaccion, traducir la precondicion a un 409 y devolver
 * el JSON --. Esa es la razon del lib: dos copias son dos formas de que la baja
 * que hace el operador y la que hace el job dejen de apagar lo mismo.
 *
 * LA PRECONDICION SE REVALIDA CON EL CONTRATO BLOQUEADO (`FOR UPDATE`), igual
 * que `facturar`: dos clicks seguidos no dan dos bajas, porque el segundo entra
 * cuando la bandera ya esta en 0 y el lib lanza.
 */
function accionBaja(): void
{
    $hoy = (new DateTimeImmutable('today'))->format('Y-m-d');
    $r   = estadoTransicion('baja', static fn(PDO $pdo, array $con): array => contratoBaja($pdo, $con, $hoy));

    json_ok($r);
}

/**
 * Dar de alta: contrato habilitado y sin fecha de baja, y su dominio habilitado
 * y en situacion Normal. Es `baja` al reves, columna por columna.
 *
 * La transicion es `contratoAlta()` del mismo lib. El job del dia 15 NO la usa
 * -- da de baja y nada mas --: volver a prender un dominio es una decision que
 * toma una persona mirando por que se habia apagado.
 */
function accionAlta(): void
{
    json_ok(estadoTransicion('alta', static fn(PDO $pdo, array $con): array => contratoAlta($pdo, $con)));
}

/**
 * El envoltorio HTTP que comparten `baja` y `alta`: transaccion, lock, traduccion
 * de la precondicion a un 409 y suceso.
 *
 * Esta escrito una vez porque las dos acciones son la MISMA transicion al reves
 * y todo lo que las rodea es identico; lo unico que cambia es que funcion del lib
 * se llama adentro.
 *
 * EL `RuntimeException` DEL LIB ES LA PRECONDICION Y SALE 409, no 500: es "el
 * contrato ya esta como pedis", o sea el otro camino gano la carrera. Cualquier
 * otra falla (PDO, FK) sube al `catch` de arriba del archivo y sale 500.
 */
function estadoTransicion(string $accion, callable $aplicar): array
{
    $id  = contratoPedido();
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $con = contratoEstadoFila($pdo, $id);
        if (!$con) { $pdo->rollBack(); json_error('Contrato no encontrado', 404); }

        try {
            $r = $aplicar($pdo, $con);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_error($e->getMessage(), 409);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    sucesoEstadoContrato($pdo, $accion, $id, $r);

    return ['id' => $id, 'baja' => $r['baja'], 'dominio' => $r['dominio']];
}

/**
 * Deja constancia de la transicion en el Visor de sucesos.
 *
 * Sube a `alerta` cuando el dominio QUEDA SUSPENDIDO viniendo de otra cosa: eso
 * es un cliente que entra a la app y no encuentra ningun control, y tiene que
 * verse sin ir a buscarlo. El resto es `info`. El valor anterior va escrito al
 * lado por el mismo motivo que en el job de las 04:00: revertir a mano es leer
 * este renglon. El texto del dominio lo arma el lib, asi que el suceso de la
 * ficha y la linea del `.log` de la tarea del dia 15 dicen lo mismo.
 */
function sucesoEstadoContrato(PDO $pdo, string $accion, int $id, array $r): void
{
    if (!function_exists('registrarSuceso')) return;

    $corte = dominioQuedoSinServicio($r['dominio']);

    registrarSuceso($pdo, 'contratos', $corte ? 'alerta' : 'info', sprintf(
        'Contrato #%d dado de %s (%s). %s.',
        $id,
        $accion,
        $r['baja'] === null ? 'sin fecha de baja' : 'fecha de baja ' . $r['baja'],
        dominioEstadoTexto($r['dominio'])
    ));
}

/* ------------------------------------------------------------- situacion */

/**
 * `Actualizar situacion`: recalcula la mora de este contrato y la de su dominio.
 *
 * ES LA CUENTA DE LA TAREA DE LAS 04:00 ACOTADA A UN DOMINIO, y literalmente el
 * mismo codigo: `situacionRecalcularDominio()` de `contratos_situacion_lib.php`.
 * De ahi salen las bandas (15 / 30 dias), que cuenta como deuda y la regla de
 * que el dominio se queda con la PEOR situacion de sus contratos habilitados.
 * Si la pantalla tuviera su propia copia, diria una situacion y el job
 * escribiria otra a las 04:00 de la mañana siguiente.
 *
 * EL VERBO SEPARA PREVISUALIZAR DE EJECUTAR, igual que `facturar`: el `GET`
 * devuelve lo que se VA a escribir --con los dias de atraso y el detalle de la
 * deuda que lo explican-- y el `POST` lo escribe. Los dos llaman a la misma
 * funcion con el flag `$escribir`, que es lo unico que garantiza que lo que
 * muestra la pantalla sea lo que despues queda en la base.
 *
 * Y NO ES UN LUJO: el resultado puede ser '3' Suspendido, o sea dejar al cliente
 * sin ningun control de operacion en la app. Un item de menu que apaga un
 * servicio sin decir antes que lo va a hacer no es una accion, es una trampa.
 *
 * NO TOCA `habilitado` NI `baja`, ni del contrato ni del dominio: eso es de
 * `baja` / `alta`. Un dominio dado de baja que vuelve a Normal por esta accion
 * sigue deshabilitado -- la mora dice cuanto debe, el estado comercial dice si
 * el contrato esta vivo, y son dos cosas distintas.
 *
 * SOLO SOBRE CONTRATOS HABILITADOS, que es la misma regla del job: un contrato
 * dado de baja no tiene mora que administrar y su `situacion` se queda con el
 * ultimo valor que tuvo, que es el dato historico.
 */
function previoSituacion(): void
{
    json_ok(situacionDeContrato(false));
}

function accionSituacion(): void
{
    json_ok(situacionDeContrato(true));
}

/**
 * El cuerpo que comparten la previsualizacion y la ejecucion.
 *
 * Con `$escribir = true` abre transaccion y bloquea las filas; con `false` no
 * abre nada y solo lee. El `FOR UPDATE` del camino de escritura toma primero
 * `contratos` y despues `dominios`, que es el orden del job y el de
 * `contratoBaja()`.
 */
function situacionDeContrato(bool $escribir): array
{
    $id  = contratoPedido();
    $pdo = db();

    if (!$escribir) {
        $con = contratoFila($id);
        if (!$con) json_error('Contrato no encontrado', 404);
        asertarContratoHabilitado($con);

        $dominio = (int) ($con['dominio'] ?? 0);
        $r = $dominio > 0
            ? situacionRecalcularDominio($pdo, $dominio, false)
            : situacionRecalcularContratoSuelto($pdo, $con, false);

        return $r + ['contrato' => $id];
    }

    try {
        $pdo->beginTransaction();

        $con = contratoEstadoFila($pdo, $id);
        if (!$con) { $pdo->rollBack(); json_error('Contrato no encontrado', 404); }

        try {
            asertarContratoHabilitado($con);
        } catch (RuntimeException $e) {
            $pdo->rollBack();
            json_error($e->getMessage(), 409);
        }

        $dominio = (int) ($con['dominio'] ?? 0);
        $r = $dominio > 0
            ? situacionRecalcularDominio($pdo, $dominio, true)
            : situacionRecalcularContratoSuelto($pdo, $con, true);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    sucesoSituacion($pdo, 'contratos', $r, sprintf('desde el contrato #%d', $id));

    return $r + ['contrato' => $id];
}

/**
 * Un contrato dado de baja no tiene mora que administrar: es la misma regla con
 * la que el job de las 04:00 solo recorre `habilitado = 1`. Recalcularlo aca le
 * pisaria el dato historico con una afirmacion sobre hoy que nadie le pidio.
 */
function asertarContratoHabilitado(array $con): void
{
    if (!esHabilitado($con['habilitado'] ?? 0)) {
        throw new RuntimeException(
            'El contrato esta dado de baja: no tiene situacion que administrar. '
            . 'Para volver a calcularsela hay que darlo de alta primero.'
        );
    }
}
