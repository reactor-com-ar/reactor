<?php

declare(strict_types=1);

/**
 * Da de baja los contratos con deuda vencida hace MAS DE SEIS MESES.
 *
 * Corre el DIA 15 DE CADA MES A LAS 09:00 por el Programador de tareas
 * (migracion `20261001_1300`).
 *
 * La baja es la MISMA que hace la ficha del ABM: `contratoBaja()` de
 * `api/contratos_estado_lib.php`, o sea cuatro columnas de dos tablas en una
 * transaccion --
 *
 *   contratos.habilitado -> 0        dominios.habilitado -> 0
 *   contratos.baja       -> hoy      dominios.situacion  -> '3' Suspendido
 *
 * -- y eso incluye lo mas grave que hace esta tarea: APAGARLE LA APP AL CLIENTE.
 * `app/index.php` lee `dominios`.`situacion` y con '3' no dibuja NINGUN control
 * de operacion. Esta tarea no es un recalculo que la corrida siguiente corrige:
 * revertirla es que alguien entre a la ficha y le de de alta.
 *
 * De ahi salen todas las guardas de abajo: el tope por corrida, el `.log` que
 * nombra uno por uno los dominios que se quedan sin servicio y con que valores
 * venian, el suceso en `alerta`, y la revalidacion de la deuda con la fila ya
 * bloqueada.
 *
 * ------------------------------------------------------------------------
 * QUE ES "UNA FACTURA VENCIDA HACE MAS DE SEIS MESES"
 * ------------------------------------------------------------------------
 *
 * Un contrato HABILITADO con al menos un comprobante que cumpla las tres cosas:
 *
 *   1. ESTA PENDIENTE (`comprobantes`.`estado` = '2').
 *   2. Es de un talonario que DOCUMENTA DEUDA (`talonarios`.`tipo` IN ('F','T'),
 *      Prefactura y Factura).
 *   3. Su `vencimiento` es ANTERIOR a `CURDATE() - INTERVAL 6 MONTH`, con los
 *      `NULL` y el centinela afuera.
 *
 * PENDIENTE NO ES OPCIONAL, Y ES LA DECISION QUE MAS CAMBIA EL RESULTADO. "Una
 * factura cuyo vencimiento es superior a seis meses" leido sin el estado da
 * CUALQUIER factura vieja, cobrada incluida: en desarrollo eso son 24 de los 26
 * contratos habilitados -- o sea que la tarea daria de baja a casi todos los
 * clientes de Reactor, la mayoria al dia, por tener una factura del año pasado
 * que ya pagaron. Con el estado son 3. Una factura vencida que ya se cobro no es
 * deuda, y dar de baja por mora es dar de baja por deuda.
 *
 * LOS DOS PRIMEROS FILTROS NO SE DECLARAN ACA: salen de
 * `api/contratos_situacion_lib.php` (`DEUDA_ESTADO_PENDIENTE`, `DEUDA_TIPOS`,
 * `DEUDA_SIN_FECHA`), que es donde este repo define "deuda" y de donde la leen
 * tambien el barrido de las 04:00 y los dos `Actualizar situacion` de las
 * fichas. ESCRITO UNA VEZ A PROPOSITO: con una copia por camino, un contrato
 * podria quedar Suspendido por un comprobante que este job no considera deuda, o
 * irse de baja sin haber pasado nunca por Suspendido.
 *
 * Las constantes llevan el prefijo `DEUDA_` y no se llaman `ESTADO_PENDIENTE` /
 * `FECHA_GENESIS` porque `api/contratos_accion.php` carga ese lib junto con
 * `comprobantes_lib.php` y `contratos_facturar_lib.php`, que ya declaran esos
 * dos nombres.
 *
 * SEIS MESES SON SEIS MESES DE CALENDARIO (`INTERVAL 6 MONTH`) y no 180 dias:
 * es lo que significa "seis meses" en un ciclo de facturacion mensual, y ademas
 * no se corre con los meses de 31 dias. "SUPERIOR a seis meses" es estricto
 * (`<`), asi que el comprobante que vence justo seis meses antes de hoy todavia
 * no cuenta -- ese dia entra al dia siguiente.
 *
 * LA FECHA DE CORTE LA CALCULA LA BASE, nunca el reloj de PHP, y se lee UNA vez
 * para toda la corrida: el contenedor corre en UTC, la conexion fija
 * `SET time_zone = '-03:00'`, y de este valor depende si a un cliente se le
 * corta el servicio. Con dos lecturas, una corrida que cruzara la medianoche
 * usaria dos cortes distintos.
 *
 * EL CENTINELA `'1500-01-01'` SE EXCLUYE EN EL `WHERE`, no despues. Es el "sin
 * fecha" del sistema historico: si entra, esta a ~190.000 dias del corte y el
 * contrato se va de baja por un comprobante al que nadie le cargo el
 * vencimiento. Hoy no hay ninguna pendiente F/T asi (verificado, 0 filas), asi
 * que la guarda no cambia ningun resultado: existe para el dia que alguien
 * guarde un comprobante sin fecha.
 *
 * ------------------------------------------------------------------------
 * RECIBO (`R`) QUEDA AFUERA, Y ACA SI CAMBIA UN RESULTADO
 * ------------------------------------------------------------------------
 *
 * `contratos_situacion_recalcular.php` deja Recibo afuera de `DEUDA_TIPOS` y
 * documenta que es el caso incomodo: DESIGN.md §33-quater.1 dice que hay
 * clientes cuyo talonario de facturacion ES de Recibo. Alla esta verificado que
 * no cambia ni un resultado. ACA SI LO CAMBIA: son 3 contratos con `R` afuera y
 * 4 con `R` adentro. El que sobra es el 143, con el Recibo 5652 pendiente desde
 * el 2023-04-18 -- 1.262 dias, $ 1.032,24 --.
 *
 * Se deja afuera igual, por dos razones: el criterio de "deuda" tiene que ser el
 * mismo que el del job de las 04:00 --si no, un contrato puede quedar Suspendido
 * por un comprobante que el otro job no mira, o de baja sin haber pasado nunca
 * por Suspendido--, y porque lo que esta en juego es cortarle el servicio a un
 * cliente. PERO NO SE SALTEA EN SILENCIO: la corrida los cuenta aparte y los
 * lista en el `.log` y en el suceso ("quedaria(n) de baja si contara Recibo"),
 * que es lo que hace que la decision se pueda revisar con el dato a la vista. El
 * dia que se decida contarlos, el cambio es agregar 'R' a `DEUDA_TIPOS` -- en
 * los dos jobs.
 *
 * ------------------------------------------------------------------------
 * CORRERLO DOS VECES NO DA DE BAJA DOS VECES, Y NO POR UNA MARCA
 * ------------------------------------------------------------------------
 *
 * No hay columna "ya se proceso": la idempotencia sale de que LA BAJA CAMBIA EL
 * ESTADO QUE DEFINE A UN CANDIDATO. El `WHERE` arranca con `habilitado = 1`, asi
 * que el contrato recien dado de baja ya no aparece en la lista de la corrida
 * siguiente. Mismo mecanismo que `contratos_facturar.php` con `facturar`.
 *
 * Y SE REVALIDA DENTRO DE LA TRANSACCION, con la fila bloqueada: que siga
 * habilitado --lo chequea `contratoBaja()`-- y QUE LA DEUDA SIGA AHI. Entre que
 * el job arma la lista y le llega el turno a un contrato, alguien pudo haber
 * cobrado esa factura desde Comprobantes: cancelarla la saca de
 * `estado = '2'` y el contrato deja de ser candidato. Sin ese segundo chequeo se
 * le cortaria el servicio a un cliente que acaba de pagar.
 *
 * ------------------------------------------------------------------------
 * UNA TRANSACCION POR CONTRATO
 * ------------------------------------------------------------------------
 *
 * Igual que `contratos_facturar.php` y al reves de las otras cuatro tareas, por
 * dos razones propias de esto:
 *
 * 1. CADA BAJA ES UNA DECISION SOBRE UN CLIENTE DISTINTO. Si el tercero falla,
 *    lo correcto es que los dos anteriores queden dados de baja -- la deuda que
 *    los puso ahi no desaparece porque otro contrato tenga un dato roto.
 * 2. EL LOCK DE `dominios` SE SUELTA ENSEGUIDA. Una transaccion global lo
 *    mantendria sobre cada dominio tocado hasta el commit final, y `dominios` es
 *    una tabla que `app/` lee en cada request de cada usuario.
 *
 * Una corrida interrumpida deja parte aplicada, y esta asumido: la corrida
 * siguiente retoma por los que quedaron, que es la idempotencia de arriba. Por
 * eso tambien el `ORDER BY` por antiguedad de la deuda -- si se corta, lo
 * aplicado es lo mas atrasado.
 *
 * ------------------------------------------------------------------------
 * EL DIA 15 A LAS 09:00
 * ------------------------------------------------------------------------
 *
 * EL DIA 15 porque es la mitad del mes: lo mas lejos posible del dia 1, que es
 * cuando `contratos_facturar.php` emite el abono de todos los contratos
 * facturables. Un comprobante recien emitido nace sin vencer
 * (`VENCIMIENTO_DIAS` = 7), asi que no puede disparar una baja ni corriendo el
 * mismo dia; separarlas es para que la factura del mes y el corte de servicio no
 * caigan juntos en la misma mañana sobre el mismo cliente.
 *
 * A LAS 09:00 y no de madrugada a proposito: es horario de oficina, asi que si
 * la corrida corta el servicio de alguien hay gente mirando. No cuelga de la
 * cadena diaria (06:00 cotizacion -> 07:00 precios -> 08:00 planes) ni la
 * necesita: lee `comprobantes`.`estado` y `.vencimiento`, dos columnas que
 * ninguna de las tres escribe.
 *
 * LO QUE SI IMPORTA DEL ORDEN es que las 04:00 de ese mismo dia ya corrieron:
 * `contratos_situacion_recalcular.php` dejo a estos contratos en Suspendido
 * hace meses (el umbral de alla son 30 dias de atraso y el de aca son seis
 * meses), asi que lo que esta tarea hace es ESCALAR una suspension que ya
 * estaba, no estrenar una.
 *
 * `cron_expr` SE EVALUA EN HORA DE ARGENTINA, por el
 * `date_default_timezone_set()` de `_scheduler.php` (DESIGN.md §33). Sobre un
 * deploy sin ese fix, `0 9 15 * *` dispararia a las 06:00.
 *
 * ------------------------------------------------------------------------
 * QUE NO HACE
 * ------------------------------------------------------------------------
 *
 * - NO DA DE ALTA NADA. Es de una sola direccion a proposito: si el cliente
 *   paga, el contrato NO vuelve solo. Volver a prender un dominio es una
 *   decision que toma una persona mirando por que se apago, desde `Dar de alta`
 *   de la ficha. La tarea de las 04:00 si es simetrica --recalcula la mora todos
 *   los dias y un dominio vuelve a Normal cuando se cancela la deuda-- pero
 *   aquello mueve una columna derivada y esto da de baja un contrato.
 * - NO COBRA, no imputa pagos, no anula ni cancela comprobantes: no escribe una
 *   sola columna de `comprobantes`.
 * - NO MANDA NADA POR CORREO. El cliente se entera porque la app deja de
 *   mostrarle los controles. Avisarle es otro camino y hoy no existe.
 * - NO toca `contratos`.`situacion` ni recalcula la mora: eso es de la tarea de
 *   las 04:00. Lo que si pasa es que el contrato dado de baja SALE de su
 *   recorrido --solo mira habilitados-- y su dominio se queda sin ningun
 *   contrato habilitado, asi que el Suspendido que escribe esta tarea no se lo
 *   pisa nadie. Esa es la razon de que la baja se sostenga sola.
 * - NO filtra por `dominios`.`habilitado`, igual que las otras tareas de
 *   contratos: lo que se da de baja es el contrato, y hay contratos habilitados
 *   con el dominio ya apagado (el 156).
 * - NO mira `contratos`.`tolerancia`, la fecha de gracia que se carga a mano en
 *   el ABM. Mismo motivo que en el job de las 04:00: nada en este repo la lee
 *   todavia y engancharla aca seria inventar una regla de perdon de mora que
 *   nadie pidio. Si algun dia entra, hay que decidir primero que significa su
 *   centinela.
 */

require_once __DIR__ . '/_bootstrap.php';

/**
 * LA BAJA ES UNA SOLA Y VIVE EN `api/contratos_estado_lib.php`; QUE CUENTA COMO
 * DEUDA ES UNO SOLO Y VIVE EN `api/contratos_situacion_lib.php`.
 *
 * El primero lo comparte este job con `api/contratos_accion.php?accion=baja`, la
 * accion de la ficha. El segundo, con el barrido de las 04:00 y con los dos
 * `Actualizar situacion` de las fichas -- de ahi salen
 * `DEUDA_ESTADO_PENDIENTE`, `DEUDA_TIPOS` y `DEUDA_SIN_FECHA`, que son los tres
 * filtros con los que este job decide quien tiene deuda vencida. Se incluye el
 * de situacion, que ya arrastra el de estado.
 *
 * A diferencia de `contratos_facturar_lib.php`, no hace falta declarar `db()` ni
 * `json_error()` aca: los dos libs reciben el PDO por parametro y no hablan
 * HTTP, que es justamente lo que los hace incluibles desde CLI.
 */
require_once __DIR__ . '/../api/contratos_situacion_lib.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: dar de baja un contrato le
// corta el servicio a un cliente y tiene que poder ubicarse en el tiempo desde
// el Visor de sucesos sin ir a buscar el .log de la ejecucion.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

/**
 * Cuantos meses de atraso hacen falta para dar de baja.
 *
 * Es el enunciado de la tarea y la constante mas probable de revisar: subirla
 * hace la baja mas tolerante, bajarla la hace mas agresiva. Van como meses de
 * calendario a `INTERVAL n MONTH` (ver el encabezado).
 */
const MESES_DE_ATRASO = 6;

/**
 * Tope de bajas de una corrida. Es un fusible, no una regla de negocio.
 *
 * Hoy son 3 candidatos de 26 contratos habilitados. Si una corrida se encuentra
 * con decenas, algo paso que este job no entiende --una migracion que movio
 * `comprobantes`.`estado`, una restauracion de backup, un talonario mal
 * clasificado-- y lo que corresponde es CORTAR SIN TOCAR NADA y avisar, no
 * apagarle la app a medio padron y despues contarlo. A diferencia del tope de
 * `contratos_facturar.php`, este se chequea antes de la primera transaccion:
 * ahi lo ya emitido eran facturas validas, aca lo ya aplicado son clientes sin
 * servicio.
 */
const TOPE_BAJAS = 10;

try {
    $pdo = _jobsPdo();

    // La fecha de corte sale de la BASE y no de PHP, y se lee UNA vez para toda
    // la corrida (ver el encabezado).
    $corte = (string) $pdo->query(
        'SELECT DATE_SUB(CURDATE(), INTERVAL ' . MESES_DE_ATRASO . ' MONTH)'
    )->fetchColumn();
    $hoy   = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();

    anotarLog(sprintf(
        'Corte: deuda pendiente vencida antes del %s (mas de %d meses al %s)',
        $corte, MESES_DE_ATRASO, $hoy
    ));

    $candidatos = contratosConDeudaVencida($pdo, $corte);
    $total      = count($candidatos);

    // Los que entrarian si Recibo contara como deuda. No se dan de baja: se
    // informan, para que la decision se pueda revisar con el dato a la vista
    // (ver el encabezado).
    $porRecibo = contratosConDeudaVencida($pdo, $corte, soloOtrosTipos: true);

    anotarLog(sprintf('%d contrato(s) con deuda vencida hace mas de %d meses', $total, MESES_DE_ATRASO));

    if ($total > TOPE_BAJAS) {
        throw new RuntimeException(sprintf(
            '%d contratos supera el tope de %d de una corrida: NO SE DIO DE BAJA NINGUNO. '
            . 'Revisar `comprobantes`.`estado` y `.vencimiento` antes de volver a correr la tarea',
            $total, TOPE_BAJAS
        ));
    }

    $bajas      = [];   // [id, dominio, dias, pendientes]
    $salteados  = [];   // [id, motivo]
    $fallados   = [];   // [id, error]
    $sinServicio = [];  // [id, dominio, texto]

    foreach ($candidatos as $cand) {
        $id = (int) $cand['id'];

        // UNA TRANSACCION POR CONTRATO (ver el encabezado). El `catch` revierte
        // SOLO este contrato y deja seguir la corrida.
        try {
            $pdo->beginTransaction();

            // `FOR UPDATE`: serializa contra la accion de la ficha y contra las
            // otras tareas de contratos, que bloquean las mismas filas.
            $con = contratoEstadoFila($pdo, $id);
            if ($con === null) {
                $pdo->rollBack();
                $salteados[] = [$id, 'el contrato ya no existe'];
                continue;
            }

            // EL SEGUNDO CHEQUEO, el que evita cortarle el servicio a alguien que
            // acaba de pagar: la lista se armo antes y en el medio pudieron
            // haberle cancelado la factura desde Comprobantes.
            $deuda = deudaVencidaDe($pdo, $id, $corte);
            if ($deuda['pendientes'] === 0) {
                $pdo->rollBack();
                $salteados[] = [$id, 'ya no tiene deuda vencida: la cancelaron entre el listado y el turno'];
                continue;
            }

            // `contratoBaja()` lanza si el contrato ya no esta habilitado -- lo
            // dio de baja el operador desde la ficha mientras corria esto.
            try {
                $r = contratoBaja($pdo, $con, $hoy);
            } catch (RuntimeException $e) {
                $pdo->rollBack();
                $salteados[] = [$id, $e->getMessage() . ' Lo dio de baja otro camino'];
                continue;
            }

            $pdo->commit();

            $dom = $r['dominio'];
            $bajas[] = [$id, $dom === null ? null : $dom['id'], $deuda['dias'], $deuda['pendientes']];

            anotarLog(sprintf(
                '  #%d | %d pendiente(s) vencida(s), la mas antigua el %s (%d dias) | baja %s | %s',
                $id, $deuda['pendientes'], (string) $deuda['venc_min'], $deuda['dias'], $hoy,
                dominioEstadoTexto($dom)
            ));

            if (dominioQuedoSinServicio($dom)) {
                $sinServicio[] = [$id, $dom['id'], dominioEstadoTexto($dom)];
            }
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $fallados[] = [$id, $e->getMessage()];
            anotarLog(sprintf('  ERROR #%d: %s', $id, $e->getMessage()));
        }
    }

    $resumen = sprintf(
        'contratos: %d dados de baja (%d salteados, %d con error, de %d con deuda vencida hace mas de %d meses)%s',
        count($bajas), count($salteados), count($fallados), $total, MESES_DE_ATRASO,
        $sinServicio === [] ? '' : sprintf(' | %d dominio(s) quedaron SIN SERVICIO', count($sinServicio))
    );

    anotarLog(sprintf(
        'Resumen: %d bajas | %d salteados | %d con error | de %d candidatos',
        count($bajas), count($salteados), count($fallados), $total
    ));

    foreach ($salteados as [$id, $motivo]) {
        anotarLog(sprintf('SALTEADO #%d: %s', $id, $motivo));
    }

    // LOS DOMINIOS QUE SE QUEDARON SIN APP, uno por uno y al pie. Es el efecto
    // real de la corrida y la linea que contesta "a quien se le corto el
    // servicio hoy"; el valor anterior va escrito al lado para que revertir a
    // mano sea leer este renglon. Mismo criterio que el job de las 04:00.
    foreach ($sinServicio as [$id, , $texto]) {
        anotarLog(sprintf('SIN SERVICIO: por el contrato #%d | %s', $id, $texto));
    }

    // Y LOS QUE NO SE TOCARON POR NO CONTAR RECIBO. No es un aviso de error: es
    // el limite declarado del criterio, escrito para que no parezca que la
    // corrida miro todo (ver el encabezado).
    foreach ($porRecibo as $p) {
        anotarLog(sprintf(
            'NO SE TOCA #%d: su deuda vencida es de un talonario que no es Prefactura ni Factura '
            . '(%d pendiente(s), la mas antigua el %s, %d dias). Quedaria de baja si DEUDA_TIPOS contara Recibo',
            (int) $p['id'], (int) $p['pendientes'], (string) $p['venc_min'], (int) $p['dias']
        ));
    }

    // El suceso sube a `alerta` cuando hay algo que mirar, y "algo que mirar"
    // incluye el caso normal de esta tarea: un dominio sin servicio NO es un
    // error, pero es lo mas grave que hace y tiene que verse en el Visor sin
    // abrir el .log de la ejecucion.
    $hayQueMirar = $sinServicio !== [] || $salteados !== [] || $fallados !== [] || $porRecibo !== [];

    if (function_exists('registrarSuceso')) {
        $detalle = [];
        foreach ($sinServicio as [$id, $domId]) $detalle[] = sprintf('#%d -> dominio #%d sin servicio', $id, $domId);
        foreach ($salteados   as [$id, $motivo]) $detalle[] = sprintf('#%d salteado: %s', $id, $motivo);
        foreach ($fallados    as [$id, $error])  $detalle[] = sprintf('#%d error: %s', $id, $error);
        foreach ($porRecibo   as $p)             $detalle[] = sprintf('#%d no se toca: deuda de otro tipo de talonario', (int) $p['id']);

        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            $hayQueMirar ? 'alerta' : 'info',
            // El detalle contrato por contrato ya esta en el .log de la
            // ejecucion: al Visor va el resumen y los primeros renglones.
            $resumen . ($detalle === [] ? '' : ' | ' . implode('; ', array_slice($detalle, 0, 5)))
                     . (count($detalle) > 5 ? sprintf(' (+%d mas en el log)', count($detalle) - 5) : '')
        );
    }

    // UNA CORRIDA CON ERRORES NO SALE `ok`. A diferencia de los salteos --que son
    // carreras ganadas por el otro camino y el job reporta sin cortar--, un error
    // es una excepcion que nadie previo: tiene que verse en rojo en el
    // Programador. Lo ya aplicado queda aplicado: cada baja cerro su transaccion.
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
 * Los contratos habilitados con al menos una pendiente vencida antes del corte,
 * del atraso mas viejo al mas nuevo.
 *
 * `$soloOtrosTipos` invierte el filtro de talonario: devuelve los que
 * calificarian si la deuda se contara sobre los OTROS cinco tipos (Recibo entre
 * ellos) y NO sobre `DEUDA_TIPOS`. Esos no se dan de baja -- se informan (ver el
 * encabezado). Se resuelve con el mismo SQL y no con una consulta aparte para
 * que las dos listas no puedan separarse.
 *
 * `ORDER BY` por antiguedad de la deuda: si la corrida se corta a la mitad, lo
 * aplicado es lo mas atrasado.
 *
 * El `IN (...)` se arma con placeholders desde `DEUDA_TIPOS` y no se interpola:
 * es la unica forma de que la lista siga siendo un dato.
 */
function contratosConDeudaVencida(PDO $pdo, string $corte, bool $soloOtrosTipos = false): array
{
    $ph    = implode(',', array_fill(0, count(DEUDA_TIPOS), '?'));
    $tipos = $soloOtrosTipos
        ? "(t.tipo IS NULL OR t.tipo NOT IN ($ph))"
        : "t.tipo IN ($ph)";

    $stmt = $pdo->prepare(
        "SELECT c.id,
                COUNT(*)                                  AS pendientes,
                MIN(co.vencimiento)                       AS venc_min,
                DATEDIFF(CURDATE(), MIN(co.vencimiento))  AS dias
           FROM contratos c
           JOIN comprobantes co ON co.contrato = c.id
           LEFT JOIN talonarios t ON t.id = co.talonario
          WHERE c.habilitado = 1
            AND co.estado = ?
            AND {$tipos}
            AND co.vencimiento IS NOT NULL
            AND co.vencimiento <> ?
            AND co.vencimiento < ?
          GROUP BY c.id
          ORDER BY MIN(co.vencimiento) ASC, c.id ASC"
    );

    $args = [DEUDA_ESTADO_PENDIENTE];
    foreach (DEUDA_TIPOS as $t) $args[] = $t;
    $args[] = DEUDA_SIN_FECHA;
    $args[] = $corte;

    $stmt->execute($args);

    return $stmt->fetchAll();
}

/**
 * La deuda vencida de UN contrato, releida con la fila ya bloqueada.
 *
 * Es el mismo criterio de `contratosConDeudaVencida()` acotado a un id: existe
 * para el segundo chequeo de adentro de la transaccion (ver el encabezado). No
 * se reusa la fila del listado a proposito -- esa se leyo antes del lock, que es
 * justo lo que este chequeo desconfia.
 *
 * @return array{pendientes:int, venc_min:?string, dias:?int}
 */
function deudaVencidaDe(PDO $pdo, int $id, string $corte): array
{
    $ph   = implode(',', array_fill(0, count(DEUDA_TIPOS), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(*)                                 AS pendientes,
                MIN(co.vencimiento)                      AS venc_min,
                DATEDIFF(CURDATE(), MIN(co.vencimiento)) AS dias
           FROM comprobantes co
           LEFT JOIN talonarios t ON t.id = co.talonario
          WHERE co.contrato = ?
            AND co.estado = ?
            AND t.tipo IN ($ph)
            AND co.vencimiento IS NOT NULL
            AND co.vencimiento <> ?
            AND co.vencimiento < ?"
    );

    $args = [$id, DEUDA_ESTADO_PENDIENTE];
    foreach (DEUDA_TIPOS as $t) $args[] = $t;
    $args[] = DEUDA_SIN_FECHA;
    $args[] = $corte;

    $stmt->execute($args);
    $d = $stmt->fetch() ?: [];

    return [
        'pendientes' => (int) ($d['pendientes'] ?? 0),
        'venc_min'   => $d['venc_min'] ?? null,
        'dias'       => $d['dias'] === null ? null : (int) $d['dias'],
    ];
}
