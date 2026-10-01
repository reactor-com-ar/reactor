<?php

declare(strict_types=1);

/**
 * Emite el comprobante del abono de TODOS los contratos facturables.
 *
 * Corre el DIA 1 DE CADA MES A LAS 09:00 por el Programador de tareas
 * (migracion `20261001_1200`). Es la unica tarea del repo que crea comprobantes.
 *
 * ------------------------------------------------------------------------
 * QUIEN ES "FACTURABLE": EL MISMO FILTRO DEL LISTADO, NO UNO PARECIDO
 * ------------------------------------------------------------------------
 *
 * El criterio es literalmente el atajo `Facturables` del menu `Listar` de
 * Contratos -- que a su vez es el filtro del back office viejo
 * (`listar?frd=<genesis>&frh=<hoy>&hab=1`):
 *
 *     habilitado = 1  AND  facturar <= CURDATE()  AND  facturar no es centinela
 *
 * Esta escrito UNA sola vez, en `FACTURABLE_WHERE` de
 * `api/contratos_facturar_lib.php`, para que el numero que anuncia la pantalla y
 * la cantidad que factura este job no puedan separarse. Lo que el job NO hace es
 * recibir una lista de ids: abre la query cada vez, porque entre que alguien
 * mira la pantalla y las 09:00 del 1 el conjunto puede haber cambiado.
 *
 * ------------------------------------------------------------------------
 * CORRERLO DOS VECES NO FACTURA DOS VECES, Y NO POR UNA MARCA
 * ------------------------------------------------------------------------
 *
 * No hay columna "ya se facturo este mes" ni bandera de corrida: la idempotencia
 * sale de que FACTURAR CAMBIA EL ESTADO QUE DEFINE A UN FACTURABLE.
 * `facturarEmitir()` adelanta `contratos`.`facturar` un mes, asi que el contrato
 * recien emitido deja de cumplir el `WHERE` y la segunda corrida ni lo ve. Una
 * marca aparte podria desincronizarse de la fecha; esto no.
 *
 * Y SE VUELVE A CHEQUEAR DENTRO DE LA TRANSACCION, con la fila bloqueada
 * (`esFacturable()`), no solo en el `SELECT` inicial. Entre que el job arma la
 * lista y le llega el turno a un contrato, alguien pudo haberlo facturado a mano
 * desde la ficha del ABM -- son el mismo codigo y el mismo minuto es posible.
 * Sin ese segundo chequeo, el contrato se facturaria dos veces: una por cada
 * camino.
 *
 * ------------------------------------------------------------------------
 * UN SOLO PERIODO POR CONTRATO POR CORRIDA, Y LOS ATRASADOS SE REPORTAN
 * ------------------------------------------------------------------------
 *
 * Un contrato puede venir atrasado varios meses -- hoy, en desarrollo, 23 de los
 * 24 facturables tienen `facturar` en junio o julio --. Emitirle un comprobante
 * por cada periodo pendiente hasta alcanzarlo dejaria CINCO facturas de golpe en
 * la casilla de un cliente, de madrugada y sin que nadie lo haya decidido. El
 * job emite el periodo mas viejo y nada mas, y al cierre LISTA UNO POR UNO los
 * contratos que siguen facturables, con cuantos periodos les faltan.
 *
 * Es la decision mas probable de revisar del job entero, y por eso es una
 * constante (`PERIODOS_POR_CORRIDA`) y no un `break` escrito en el medio del
 * bucle. Subirla hace que la corrida alcance a los atrasados sola.
 *
 * ------------------------------------------------------------------------
 * UNA TRANSACCION POR CONTRATO, Y NO UNA PARA TODA LA CORRIDA
 * ------------------------------------------------------------------------
 *
 * Es la diferencia de fondo con las otras cuatro tareas, que abren una sola
 * transaccion y adentro recorren todo. Tres razones, y las tres son especificas
 * de facturar:
 *
 * 1. CADA COMPROBANTE ES UN DOCUMENTO INDEPENDIENTE. Si el contrato 19 falla, lo
 *    correcto es que los 18 ya emitidos queden emitidos -- son facturas validas,
 *    con su numero tomado. Una transaccion global los tiraria a todos por una
 *    fila mal cargada.
 * 2. LA SERIE DEL TALONARIO ES UN CONTADOR COMPARTIDO Y SE BLOQUEA. Los 24
 *    contratos facturables de hoy usan EL MISMO talonario: con una transaccion
 *    global, esa fila queda bloqueada desde el primer comprobante hasta el
 *    commit final, y cualquiera que intente autorizar o emitir algo desde el
 *    panel en esos segundos se queda esperando.
 * 3. UN ROLLBACK GLOBAL NO DEVOLVERIA LOS NUMEROS. Vuelve atras `talonarios`.
 *    `serie`, si -- pero el numero fiscal ya se pudo haber mostrado o mandado.
 *
 * El precio es que una corrida interrumpida deja parte facturada, y esta
 * asumido: la corrida siguiente retoma por los que quedaron, que es exactamente
 * la idempotencia de arriba. Por eso tambien el `ORDER BY facturar ASC`: si se
 * corta, lo emitido es lo mas atrasado.
 *
 * ------------------------------------------------------------------------
 * EL 1 A LAS 09:00: ES LA COLA DE LA CADENA DIARIA
 * ------------------------------------------------------------------------
 *
 * Las otras tareas de negocio corren todos los dias y en este orden:
 *
 *   04:00  contratos_situacion_recalcular   la mora (no entra en la cadena)
 *   06:00  dolar_actualizar                 escribe la cotizacion
 *   07:00  articulos_recalcular             reprecia `articulos`.`venta`
 *   08:00  contratos_plan_recalcular        reasigna `contratos`.`plan`
 *
 * Facturar LEE las dos puntas de esa cadena -- `contratos`.`plan` para saber que
 * plan cobrar y `articulos`.`venta` para saber cuanto -- y no escribe ninguna.
 * Las 09:00 del 1 la dejan corriendo DESPUES de que las dos quedaron frescas: el
 * comprobante sale con el plan que corresponde al tamaño de hoy y al precio de
 * hoy. Invertirlas facturaria el mes nuevo con la lista de precios del mes
 * anterior, que es plata.
 *
 * `cron_expr` SE EVALUA EN HORA DE ARGENTINA, por el
 * `date_default_timezone_set()` de `_scheduler.php` (DESIGN.md §33). Sobre un
 * deploy sin ese fix, `0 9 1 * *` dispararia a las 06:00.
 *
 * ------------------------------------------------------------------------
 * QUE NO HACE
 * ------------------------------------------------------------------------
 *
 * - NO RECALCULA EL PLAN NI EL ABONO. Lee `contratos`.`plan` y
 *   `articulos`.`venta` tal como estan, igual que la accion de la ficha. Que
 *   emitir una factura le cambie el plan al contrato es justamente lo primero
 *   que este repo se nego a portar del legacy (el fallback `if ($plan == 0)
 *   $plan = 100`). El plan lo mueve la tarea de las 08:00.
 * - NO MANDA NADA POR CORREO. Deja `contratos`.`remitir` en `'1'`, que es la
 *   marca de "hay estado de cuenta nuevo para enviar" y lo que ya hacia la
 *   accion de la ficha. Quien envia es otro camino.
 * - NO COBRA, no imputa pagos, no cancela comprobantes. Emite y numera.
 * - NO toca `contratos`.`situacion` ni `dominios`.`situacion`: la mora la
 *   calcula la tarea de las 04:00. Un comprobante recien emitido nace sin vencer
 *   (`VENCIMIENTO_DIAS` = 7), asi que no mueve ninguna de las dos.
 * - NO filtra por `dominios`.`habilitado`, igual que las otras tareas de
 *   contratos: lo que se factura es el contrato, y hay contratos habilitados con
 *   el dominio apagado (el 156).
 */

require_once __DIR__ . '/_bootstrap.php';

/**
 * LA FACTURACION ES UNA SOLA Y VIVE EN `api/contratos_facturar_lib.php`.
 *
 * Esa libreria la comparte este job con `api/contratos_accion.php?accion=
 * facturar`, y resuelve la conexion con `db()` -- que declara
 * `api/bootstrap.php` para la web, un archivo que manda headers HTTP y llama a
 * `requireAuth()`, o sea que no se puede incluir desde CLI --. La salida es
 * declarar `db()` aca apuntando al PDO del job, igual que hace
 * `articulos_recalcular.php` con `articulos_lib.php`.
 *
 * `json_error()` esta por lo mismo: `comprobanteUuidLibre()` la llama cuando
 * agota los 20 intentos de generar un uuid libre, y en la web eso corta el
 * request con un 500. Desde CLI tiene que ser una excepcion -- la agarra el
 * `catch` de abajo, revierte la transaccion de ESE contrato y sigue con el
 * siguiente. Un `exit` ahi dejaria la fila de `tareas_ejecuciones` colgada en
 * `corriendo` hasta que la barra el watchdog.
 *
 * No hay riesgo de redeclarar: `api/bootstrap.php` no entra nunca por este
 * camino.
 */
function db(): PDO
{
    return _jobsPdo();
}

function json_error(string $message, int $status = 400): void
{
    throw new RuntimeException($message . ' (' . $status . ')');
}

require_once __DIR__ . '/../api/contratos_facturar_lib.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: emitir facturas es LO MAS
// grave que hace una tarea de este repo y tiene que poder ubicarse en el tiempo
// desde el Visor de sucesos sin ir a buscar el .log de la ejecucion.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

/**
 * Cuantos periodos se le emiten como maximo a un mismo contrato por corrida.
 *
 * UNO. Un contrato atrasado se pone al dia a razon de un periodo por mes, y los
 * que quedan facturables se listan al cierre para que alguien decida (ver el
 * encabezado). Es la decision mas revisable del job: subirla a 12 hace que la
 * corrida alcance sola a cualquier atraso de hasta un año.
 */
const PERIODOS_POR_CORRIDA = 1;

/**
 * Tope de comprobantes de una corrida. Es un fusible, no una regla de negocio.
 *
 * Hoy son 24 contratos facturables de 50. Si una corrida se encuentra con
 * cientos, algo paso que este job no entiende -- una migracion que puso
 * `facturar` en una fecha vieja, una restauracion de backup -- y lo que
 * corresponde es cortar y avisar, no emitir cientos de facturas y despues
 * contarlo. Lo que ya se emitio queda emitido (una transaccion por contrato) y
 * la corrida sale con error para que se vea en el Programador.
 */
const TOPE_COMPROBANTES = 200;

try {
    $pdo = _jobsPdo();

    // La fecha de corte sale de la BASE y no de PHP, que es la regla del repo
    // para toda comparacion de fechas: el contenedor corre en UTC, la conexion
    // fija `SET time_zone = '-03:00'` y de este valor depende si a un cliente se
    // le emite un comprobante. Se lee UNA vez y se usa en los dos chequeos -- el
    // del `SELECT` y el de adentro de cada transaccion -- para que la corrida no
    // cambie de dia a la mitad.
    $hoy = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

    $candidatos = contratosFacturables();
    $total      = count($candidatos);

    anotarLog(sprintf('%d contratos facturables al %s', $total, $hoy));

    if ($total > TOPE_COMPROBANTES) {
        throw new RuntimeException(sprintf(
            '%d contratos facturables supera el tope de %d de una corrida: no se emite nada. '
            . 'Revisar `contratos`.`facturar` antes de volver a correr la tarea',
            $total, TOPE_COMPROBANTES
        ));
    }

    $emitidos  = [];   // [id, dominio, numero, total, periodo]
    $salteados = [];   // [id, motivo]
    $fallados  = [];   // [id, error]
    $avisos    = [];
    $importe   = 0.0;

    foreach ($candidatos as $cand) {
        $id = (int) $cand['id'];

        for ($periodo = 0; $periodo < PERIODOS_POR_CORRIDA; $periodo++) {
            // UNA TRANSACCION POR CONTRATO (ver el encabezado). El `catch` de
            // adentro revierte SOLO este contrato y deja seguir la corrida: un
            // cliente con un dato mal cargado no puede impedir que se le
            // facture a los otros 23.
            try {
                $pdo->beginTransaction();

                // `FOR UPDATE`: serializa contra la accion de la ficha y contra
                // las otras dos tareas de contratos, que bloquean las mismas
                // filas. El segundo que entra ya ve `facturar` adelantado.
                $con = contratoFila($id, true);
                if ($con === null) {
                    // FK RESTRICT de por medio, asi que no deberia pasar: seria
                    // un contrato borrado entre el SELECT y el lock.
                    $pdo->rollBack();
                    $salteados[] = [$id, 'el contrato ya no existe'];
                    break;
                }

                // EL SEGUNDO CHEQUEO, el que de verdad evita facturar dos veces:
                // la lista se armo antes y alguien pudo haber facturado este
                // contrato a mano en el medio.
                if (!esFacturable($con, $hoy)) {
                    $pdo->rollBack();
                    if ($periodo === 0) {
                        $salteados[] = [$id, sprintf(
                            'ya no es facturable (habilitado=%s, facturar=%s): lo facturo otro camino',
                            (int) ($con['habilitado'] ?? 0),
                            textoFecha($con['facturar'] ?? null)
                        )];
                    }
                    break;
                }

                $ctx = facturarContexto($con);

                // Un bloqueo es un dato mal cargado -- sin plan, sin talonario,
                // una razon social que no entra en la columna --: el contrato se
                // saltea y se reporta. Es lo mismo que hace la pantalla cuando
                // no dibuja el boton, salvo que aca no hay nadie a quien
                // mostrarselo en el momento.
                if ($ctx['bloqueos'] !== []) {
                    $pdo->rollBack();
                    $salteados[] = [$id, implode(' ', $ctx['bloqueos'])];
                    break;
                }

                $r = facturarEmitir($con, $ctx);

                $pdo->commit();

                $dominio  = $ctx['dominio'];
                $nombre   = textoNombre($dominio['nombre'] ?? null);
                $monto    = (float) ($r['totales']['total'] ?? 0);
                $importe += $monto;

                $emitidos[] = [$id, $nombre, $r['numero'], $monto, $r['facturado']];

                anotarLog(sprintf(
                    '  #%d %s | periodo %s | %s %s | $ %s | facturar %s -> %s',
                    $id,
                    $nombre,
                    substr((string) $r['facturado'], 0, 7),
                    trim((string) ($ctx['talonario']['nombre'] ?? '')),
                    $r['numero'] ?? 's/n',
                    number_format($monto, 2, '.', ''),
                    (string) $r['facturado'],
                    (string) $r['facturar']
                ));

                // Los avisos del contexto (cliente del dominio distinto del del
                // contrato, talonario deshabilitado, linea sin articulo) NO
                // bloquean -- la pantalla los muestra y deja seguir -- pero son
                // cosas que alguien tiene que mirar en un comprobante ya emitido.
                foreach ($ctx['avisos'] as $aviso) {
                    $avisos[] = sprintf('#%d: %s', $id, $aviso);
                }
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $fallados[] = [$id, $e->getMessage()];
                anotarLog(sprintf('  ERROR #%d: %s', $id, $e->getMessage()));
                break;
            }
        }
    }

    // LOS QUE SIGUEN FACTURABLES DESPUES DE LA CORRIDA. Con
    // `PERIODOS_POR_CORRIDA` = 1 son los contratos atrasados mas de un periodo,
    // mas los que se saltearon o fallaron. Se vuelve a preguntar a la base en
    // vez de deducirlo: es la misma consulta con la que arranco, asi que el
    // numero del cierre y el del arranque son comparables.
    $pendientes = contratosFacturables();

    $resumen = sprintf(
        'contratos: %d facturados por $ %s (%d salteados, %d con error, de %d facturables)%s',
        count($emitidos),
        number_format($importe, 2, '.', ''),
        count($salteados),
        count($fallados),
        $total,
        $pendientes === [] ? '' : sprintf(' | quedan %d facturables', count($pendientes))
    );

    anotarLog(sprintf(
        'Resumen: %d comprobantes emitidos | %d salteados | %d con error | de %d facturables',
        count($emitidos), count($salteados), count($fallados), $total
    ));
    anotarLog(sprintf('Importe emitido: $ %s', number_format($importe, 2, '.', '')));

    foreach ($salteados as [$id, $motivo]) {
        anotarLog(sprintf('SALTEADO #%d: %s', $id, $motivo));
    }

    // Uno por uno y con cuantos periodos les faltan: es la lista por la que
    // alguien va a venir a preguntar, y la unica forma de enterarse de que un
    // contrato lleva meses sin facturarse (ver el encabezado).
    foreach ($pendientes as $p) {
        $atraso = periodosDeAtraso((string) $p['facturar'], $hoy);
        anotarLog(sprintf(
            'SIGUE FACTURABLE: #%d | facturar %s | %s',
            (int) $p['id'],
            (string) $p['facturar'],
            $atraso <= 1
                ? 'un periodo pendiente'
                : sprintf('%d periodos pendientes', $atraso)
        ));
    }

    foreach ($avisos as $aviso) anotarLog('AVISO: ' . $aviso);

    // El suceso sube a `alerta` en los tres casos en que la corrida sale `ok` y
    // aun asi hay algo que mirar: un contrato que no se pudo facturar, uno que
    // fallo, o uno que quedo facturable. Los tres son plata que no se emitio.
    $hayQueMirar = $salteados !== [] || $fallados !== [] || $pendientes !== [] || $avisos !== [];

    if (function_exists('registrarSuceso')) {
        $detalle = [];
        foreach ($salteados as [$id, $motivo]) $detalle[] = sprintf('#%d salteado: %s', $id, $motivo);
        foreach ($fallados  as [$id, $error])  $detalle[] = sprintf('#%d error: %s', $id, $error);
        foreach ($pendientes as $p)            $detalle[] = sprintf('#%d sigue facturable', (int) $p['id']);

        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            $hayQueMirar ? 'alerta' : 'info',
            // El detalle comprobante por comprobante ya esta en el .log de la
            // ejecucion: al Visor va el resumen y los primeros avisos.
            $resumen . ($detalle === [] ? '' : ' | ' . implode('; ', array_slice($detalle, 0, 5)))
                     . (count($detalle) > 5 ? sprintf(' (+%d mas en el log)', count($detalle) - 5) : '')
        );
    }

    // UNA CORRIDA CON ERRORES NO SALE `ok`. A diferencia de los salteos --que
    // son datos mal cargados y el job reporta sin cortar--, un error es una
    // excepcion que nadie previo: tiene que verse en rojo en el Programador.
    // Lo ya emitido queda emitido: cada comprobante cerro su propia transaccion.
    if ($fallados !== []) {
        throw new RuntimeException(sprintf(
            '%s | %d contrato(s) fallaron: %s',
            $resumen,
            count($fallados),
            implode('; ', array_map(
                static fn(array $f): string => sprintf('#%d %s', $f[0], $f[1]),
                array_slice($fallados, 0, 5)
            ))
        ));
    }

    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    anotarLog('ERROR: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

/**
 * Cuantos periodos mensuales van desde `facturar` hasta hoy, con el mismo
 * criterio con el que `facturarEmitir()` avanza la fecha: meses de calendario.
 *
 * El dia 1 cuenta como periodo completo (`facturar` cae siempre el dia 1), asi
 * que un contrato con `facturar` = hoy tiene exactamente un periodo pendiente.
 */
function periodosDeAtraso(string $facturar, string $hoy): int
{
    $desde = DateTimeImmutable::createFromFormat('!Y-m-d', substr($facturar, 0, 10));
    $hasta = DateTimeImmutable::createFromFormat('!Y-m-d', substr($hoy, 0, 10));
    if ($desde === false || $hasta === false) return 1;

    $d = $desde->diff($hasta);

    return max(1, ($d->y * 12) + $d->m + 1);
}

/** Una fecha de la base para el log, o el marcador de "sin fecha". */
function textoFecha(mixed $valor): string
{
    $v = trim((string) ($valor ?? ''));

    return $v === '' ? 'sin fecha' : $v;
}

/** Un nombre de la base para el log, o un marcador cuando viene vacio. */
function textoNombre(mixed $nombre): string
{
    $n = trim((string) ($nombre ?? ''));

    return $n !== '' ? mb_substr($n, 0, 60) : '(sin nombre)';
}
