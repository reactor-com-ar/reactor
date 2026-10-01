<?php

declare(strict_types=1);

/**
 * LA MORA: que situacion le corresponde a un contrato y a su dominio, en un solo
 * lugar.
 *
 * Lo comparten los CUATRO caminos que calculan esto:
 *
 *   - `jobs/contratos_situacion_recalcular.php`, la tarea de las 04:00, que
 *     barre TODOS los contratos habilitados del sistema.
 *   - `api/contratos_accion.php?accion=situacion`, el `Actualizar situacion` de
 *     la ficha y del menu de fila de Contratos.
 *   - `api/dominios_accion.php?accion=situacion`, el mismo item en Dominios.
 *   - `api/pago_imputado.php`, el webhook que avisa un cobro de MercadoPago:
 *     pagar la deuda tiene que sacar al dominio de Suspendido EN EL ACTO, no al
 *     otro dia a las 04:00.
 *
 * Los tres ultimos son la MISMA cuenta de la tarea acotada a un dominio. Que las
 * reglas vivan aca es lo unico que impide que la pantalla diga una situacion y
 * el job escriba otra a las 04:00 de la mañana siguiente -- y esa situacion es
 * la que decide si un cliente ve o no los controles de la app.
 *
 * LA TABLA `pagos` NO ENTRA EN ESTE CALCULO. Es del sistema historico y este
 * repo todavia no la tiene en cuenta para nada: la deuda se cuenta sobre
 * `comprobantes`.`estado`, asi que lo que rehabilita a un cliente es que su
 * comprobante deje de estar Pendiente y no que aparezca una fila en `pagos`.
 *
 * ------------------------------------------------------------------------
 * LAS REGLAS, QUE SON LAS DEL JOB
 * ------------------------------------------------------------------------
 *
 * De los dias de atraso de la deuda mas antigua sale la situacion:
 *
 *   atraso < 15 dias   -> '1' Normal
 *   15 a 29 dias       -> '2' Limitado
 *   30 dias o mas      -> '3' Suspendido
 *
 * Sin pendientes, o con la mas antigua todavia sin vencer, es '1' Normal.
 *
 * CADA BANDA SE QUEDA CON SU LIMITE INFERIOR. El enunciado original da las
 * bandas solapadas ("0 a 15", "15 a 30", "30 o mas"), asi que los dias 15 y 30
 * estan en dos a la vez y hay que elegir. Se resuelve por el unico borde que el
 * enunciado SI define solo: "30 dias o mas" es Suspendido, o sea que el 30 es de
 * la banda de arriba; por simetria el 15 tambien.
 *
 *   dia 14 -> '1'   dia 15 -> '2'   dia 29 -> '2'   dia 30 -> '3'
 *
 * Son dos comparaciones `>=` en orden descendente y no un `switch` de rangos:
 * asi no hay forma de escribir un hueco entre dos bandas.
 *
 * ------------------------------------------------------------------------
 * QUE CUENTA COMO DEUDA
 * ------------------------------------------------------------------------
 *
 * `comprobantes`.`estado` = '2' (Pendiente) con `talonarios`.`tipo` IN
 * ('F','T') (Prefactura y Factura), con `vencimiento` utilizable.
 *
 * - EL ESTADO SE COMPARA COMO STRING porque la columna es `varchar(1)` y '0' es
 *   un valor real: con un cast a int de por medio, Anulado y "sin estado" se
 *   confunden. De los cuatro estados ('0' Anulado, '1' Preparacion, '2'
 *   Pendiente, '3' Cancelado) solo Pendiente es deuda: Preparacion todavia no
 *   tiene numero (`serie` = 0), Anulado no existe y Cancelado ya se cobro.
 * - LOS OTROS CINCO TIPOS DE TALONARIO no documentan deuda (`P` Presupuesto,
 *   `D` Pedido, `R` Recibo, `M` Remito, `N` Nota de Credito). Sin el filtro la
 *   cuenta seria otra y no por poco: de los 209 comprobantes pendientes de la
 *   base, 72 son Presupuestos y 16 Remitos.
 * - EL CASO INCOMODO ES RECIBO (`R`), y queda afuera a sabiendas: DESIGN.md
 *   §33-quater.1 dice que hay clientes cuyo talonario de facturacion ES de
 *   Recibo. Esta verificado que no cambia ningun resultado de ESTE calculo (el
 *   unico Recibo pendiente con contrato es el 5652 del contrato 143, que ya cae
 *   en '3' por sus prefacturas antes de mirarlo), pero SI cambia el de
 *   `contratos_baja_morosos.php`. Por eso es una constante y no una lista
 *   escrita adentro del SQL: el dia que se decida contarlo, se agrega 'R' aca y
 *   los tres caminos cambian juntos.
 * - EL CENTINELA `'1500-01-01'` SE EXCLUYE EN EL `WHERE`, no despues. Es el "sin
 *   fecha" del sistema historico: si entra, `MIN()` se queda con el y `DATEDIFF`
 *   da ~190.000 dias, o sea Suspendido para cualquier contrato que tenga uno.
 *   Las pendientes sin fecha utilizable se cuentan aparte y se avisan, porque un
 *   contrato cuyas pendientes fueran TODAS asi pareceria no tener deuda.
 *
 * EL ATRASO LO CUENTA LA BASE, NO PHP (`DATEDIFF(CURDATE(), MIN(vencimiento))`),
 * sobre la misma conexion que fija `SET time_zone = '-03:00'`. Es la regla del
 * repo para toda comparacion de fechas y aca decide si un cliente queda
 * suspendido. UN VENCIMIENTO FUTURO DA ATRASO NEGATIVO y eso es Normal, no un
 * error: el comprobante esta emitido y pendiente, pero todavia no vencio.
 *
 * ------------------------------------------------------------------------
 * LA SITUACION DEL DOMINIO ES LA PEOR DE SUS CONTRATOS HABILITADOS
 * ------------------------------------------------------------------------
 *
 * Un dominio puede tener mas de un contrato habilitado --hoy ninguno lo tiene,
 * pero nada del esquema lo impide-- y entonces hay que elegir. GANA LA PEOR: con
 * un contrato impago el cliente NO esta al dia por mas que el otro si, y
 * quedarse con la mejor dejaria operando a quien debe plata con solo abrirle un
 * contrato nuevo al lado.
 *
 * Y POR ESO `situacionRecalcularDominio()` RECALCULA TODOS LOS CONTRATOS
 * HABILITADOS DEL DOMINIO, aunque se haya entrado por uno solo desde la ficha de
 * Contratos: para saber que le toca al dominio hay que mirar a los hermanos, y
 * si se los mira hay que escribir lo que se calculo -- si no, `contratos`.
 * `situacion` del hermano quedaria diciendo una cosa y `dominios`.`situacion`
 * estaria calculada con otra. La pantalla lista uno por uno los que toco.
 *
 * ------------------------------------------------------------------------
 * NO HABLA HTTP NI ESCRIBE LOGS
 * ------------------------------------------------------------------------
 *
 * Ninguna funcion llama a `json_error()`, a `json_ok()` ni a `anotarLog()`, y
 * ninguna usa `db()`: el PDO llega por parametro y la transaccion la abre el
 * llamador. Es lo que lo hace incluible desde los dos endpoints y desde el job.
 * Su unica dependencia es `contratos_estado_lib.php`, de donde salen los codigos
 * de situacion y sus textos -- las mismas constantes con las que `Dar de baja`
 * escribe `'3'`.
 *
 * Las constantes de deuda llevan el prefijo `DEUDA_` y no se llaman
 * `ESTADO_PENDIENTE` / `FECHA_GENESIS` a proposito: `api/contratos_accion.php`
 * carga este lib junto con `comprobantes_lib.php` y `contratos_facturar_lib.php`,
 * que ya declaran esos dos nombres. Mismo motivo por el que
 * `contratos_estado_lib.php` llama `BAJA_SIN_FECHA` al centinela apocalipsis.
 */

require_once __DIR__ . '/contratos_estado_lib.php';

/**
 * Dias de atraso a partir de los cuales arranca cada banda.
 *
 * Cada banda se queda con su limite inferior: 15 ya es Limitado y 30 ya es
 * Suspendido (ver el encabezado).
 */
const UMBRAL_LIMITADO   = 15;
const UMBRAL_SUSPENDIDO = 30;

/** `comprobantes`.`estado` = Pendiente, el unico estado que es deuda. */
const DEUDA_ESTADO_PENDIENTE = '2';

/**
 * Los tipos de `talonarios`.`tipo` cuyo comprobante documenta una deuda:
 * `F` Prefactura y `T` Factura. Es la decision mas revisable del calculo --
 * ver "el caso incomodo es Recibo" en el encabezado.
 */
const DEUDA_TIPOS = ['F', 'T'];

/** El "sin fecha" del sistema historico (`cTiempo::genesis()`). */
const DEUDA_SIN_FECHA = '1500-01-01';

/**
 * La situacion que corresponde a un atraso en dias.
 *
 * `null` es "no hay deuda fechable" --ningun pendiente, o ninguno con fecha
 * usable-- y da Normal. Un atraso negativo es un comprobante emitido que todavia
 * no vencio, y tambien da Normal.
 */
function situacionPorAtraso(?int $dias): string
{
    if ($dias === null)             return SITUACION_NORMAL;
    if ($dias >= UMBRAL_SUSPENDIDO) return SITUACION_SUSPENDIDO;
    if ($dias >= UMBRAL_LIMITADO)   return SITUACION_LIMITADO;

    return SITUACION_NORMAL;
}

/**
 * La PEOR de dos situaciones, que es la que le toca al dominio.
 *
 * Los tres codigos son '1' < '2' < '3' y estan ordenados de menor a peor, asi
 * que `max()` sobre el string alcanza; se escribe con nombre igual para que la
 * regla se lea y no haya que deducirla de una comparacion de caracteres.
 */
function peorSituacion(string $a, string $b): string
{
    return max($a, $b);
}

/**
 * La deuda de UN contrato, resuelta por la base en una sola fila.
 *
 * `MIN(vencimiento)` es el vencimiento de la mas antigua y `DATEDIFF` el atraso
 * en dias. Los tres contadores no deciden nada, se informan:
 *
 *   `pendientes`       las que entran al calculo (con fecha utilizable)
 *   `sin_fecha`        las excluidas por `NULL` o por el centinela
 *   `pendientes_otros` las pendientes de los otros cinco tipos de talonario, que
 *                      NO son deuda -- esta para poder explicar por que un
 *                      contrato con comprobantes pendientes sale Normal
 *
 * El `IN (...)` se arma con placeholders desde `DEUDA_TIPOS` y no se interpola:
 * es la unica forma de que la lista siga siendo un dato.
 *
 * @return array{pendientes:int, sin_fecha:int, pendientes_otros:int, venc_min:?string, dias:?int, situacion:string}
 */
function deudaDeContrato(PDO $pdo, int $contrato): array
{
    $ph   = implode(',', array_fill(0, count(DEUDA_TIPOS), '?'));
    $stmt = $pdo->prepare(
        "SELECT
            SUM(co.estado = ? AND t.tipo IN ($ph) AND co.vencimiento IS NOT NULL AND co.vencimiento <> ?) AS pendientes,
            SUM(co.estado = ? AND t.tipo IN ($ph) AND (co.vencimiento IS NULL OR co.vencimiento = ?))     AS sin_fecha,
            SUM(co.estado = ? AND (t.tipo IS NULL OR t.tipo NOT IN ($ph)))                                AS pendientes_otros,
            MIN(CASE WHEN co.estado = ? AND t.tipo IN ($ph) AND co.vencimiento IS NOT NULL AND co.vencimiento <> ?
                     THEN co.vencimiento END)                                                             AS venc_min,
            DATEDIFF(CURDATE(), MIN(CASE WHEN co.estado = ? AND t.tipo IN ($ph) AND co.vencimiento IS NOT NULL AND co.vencimiento <> ?
                     THEN co.vencimiento END))                                                            AS dias
           FROM comprobantes co
           LEFT JOIN talonarios t ON t.id = co.talonario
          WHERE co.contrato = ?"
    );

    // El orden de los parametros sigue al del SQL. El tercer bloque
    // (`pendientes_otros`) no compara contra el centinela: cuenta comprobantes
    // de otros tipos, sin mirar la fecha.
    $args = [];
    foreach ([1, 2, 3, 4, 5] as $bloque) {
        $args[] = DEUDA_ESTADO_PENDIENTE;
        foreach (DEUDA_TIPOS as $t) $args[] = $t;
        if ($bloque !== 3) $args[] = DEUDA_SIN_FECHA;
    }
    $args[] = $contrato;

    $stmt->execute($args);
    $d = $stmt->fetch() ?: [];

    $dias = $d['dias'] === null ? null : (int) $d['dias'];

    return [
        'pendientes'       => (int) ($d['pendientes']       ?? 0),
        'sin_fecha'        => (int) ($d['sin_fecha']        ?? 0),
        'pendientes_otros' => (int) ($d['pendientes_otros'] ?? 0),
        'venc_min'         => $d['venc_min'] ?? null,
        'dias'             => $dias,
        'situacion'        => situacionPorAtraso($dias),
    ];
}

/**
 * Recalcula la situacion de TODOS los contratos habilitados de un dominio y la
 * del dominio, que es la peor de las de ellos.
 *
 * Es la cuenta de la tarea de las 04:00 acotada a un dominio, y la usan los dos
 * `Actualizar situacion` -- el de Contratos y el de Dominios --. Con
 * `$escribir = false` no toca nada y sirve de previsualizacion; con `true`
 * escribe, y la TRANSACCION LA ABRE EL LLAMADOR.
 *
 * EL ORDEN DE LOS LOCKS ES `contratos` Y DESPUES `dominios`, el mismo que toman
 * el job de las 04:00 y `contratoBaja()`. Dos escrituras encimadas se esperan en
 * vez de abrazarse.
 *
 * SI EL DOMINIO NO TIENE NINGUN CONTRATO HABILITADO NO SE ESCRIBE NADA, ni
 * siquiera un Normal: es la misma regla del job --un dominio sin contrato vivo
 * no tiene mora que mirar y pisarle la `situacion` seria opinar sobre un cliente
 * del que este calculo no sabe nada--. La pantalla lo dice con el aviso, no con
 * un numero inventado.
 *
 * @return array{
 *   dominio: array|null, contratos: array<int, array>, avisos: list<string>,
 *   escrito: bool, contratos_cambiados: int
 * }
 */
function situacionRecalcularDominio(PDO $pdo, int $dominio, bool $escribir): array
{
    $avisos = [];

    // Los contratos PRIMERO, que es el orden de locks (ver arriba). Sin joins a
    // proposito: en MySQL un `FOR UPDATE` sobre un `LEFT JOIN` bloquea tambien
    // las filas de las tablas unidas, que este calculo solo lee.
    $sel = $pdo->prepare(
        'SELECT id, situacion FROM contratos WHERE dominio = :d AND habilitado = 1 ORDER BY id'
        . ($escribir ? ' FOR UPDATE' : '')
    );
    $sel->execute([':d' => $dominio]);
    $filas = $sel->fetchAll();

    $selDom = $pdo->prepare(
        'SELECT id, nombre, situacion FROM dominios WHERE id = :id' . ($escribir ? ' FOR UPDATE' : '')
    );
    $selDom->execute([':id' => $dominio]);
    $dom = $selDom->fetch() ?: null;

    $upd    = $pdo->prepare('UPDATE contratos SET situacion = :s WHERE id = :id');
    $updDom = $pdo->prepare('UPDATE dominios  SET situacion = :s WHERE id = :id');

    $contratos = [];
    $peor      = null;
    $cambiados = 0;

    foreach ($filas as $f) {
        $id    = (int) $f['id'];
        $deuda = deudaDeContrato($pdo, $id);
        $vieja = $f['situacion'] === null ? null : trim((string) $f['situacion']);
        $nueva = $deuda['situacion'];

        $peor = $peor === null ? $nueva : peorSituacion($peor, $nueva);

        // Una pendiente sin fecha utilizable no se puede fechar, asi que no
        // entra al `MIN()`: el contrato puede salir Normal teniendo deuda real.
        if ($deuda['sin_fecha'] > 0) {
            $avisos[] = sprintf(
                'El contrato #%d tiene %d pendiente(s) sin vencimiento usable (NULL o el centinela %s) que no entran al cálculo%s.',
                $id, $deuda['sin_fecha'], DEUDA_SIN_FECHA,
                $deuda['pendientes'] === 0
                    ? ' — queda Normal por falta de fecha, no por falta de deuda'
                    : ''
            );
        }

        $cambio = $vieja !== $nueva;
        if ($cambio) $cambiados++;

        // El `NULL` inicial SI cuenta como cambio: pasar de "no se sabe" a un
        // codigo es escribir un dato que antes no estaba.
        if ($escribir && $cambio) {
            $upd->execute([':s' => $nueva, ':id' => $id]);
        }

        $contratos[] = [
            'id'               => $id,
            'situacion_actual' => $vieja,
            'situacion_nueva'  => $nueva,
            'cambio'           => $cambio,
            'deuda'            => $deuda,
        ];
    }

    $domSalida = null;

    if ($dom === null) {
        // FK RESTRICT de por medio, asi que no deberia pasar.
        $avisos[] = sprintf('El dominio #%d no existe.', $dominio);
    } elseif ($peor === null) {
        $avisos[] = 'El dominio no tiene ningún contrato habilitado: no hay mora que calcular y su situación queda como está.';
        $domSalida = [
            'id'               => (int) $dom['id'],
            'nombre'           => trim((string) ($dom['nombre'] ?? '')),
            'situacion_actual' => $dom['situacion'] === null ? null : trim((string) $dom['situacion']),
            'situacion_nueva'  => null,
            'cambio'           => false,
        ];
    } else {
        $vieja  = $dom['situacion'] === null ? null : trim((string) $dom['situacion']);
        $cambio = $vieja !== $peor;

        if ($escribir && $cambio) {
            $updDom->execute([':s' => $peor, ':id' => (int) $dom['id']]);
        }

        $domSalida = [
            'id'               => (int) $dom['id'],
            'nombre'           => trim((string) ($dom['nombre'] ?? '')),
            'situacion_actual' => $vieja,
            'situacion_nueva'  => $peor,
            'cambio'           => $cambio,
        ];
    }

    return [
        'dominio'             => $domSalida,
        'contratos'           => $contratos,
        'avisos'              => $avisos,
        'escrito'             => $escribir,
        'contratos_cambiados' => $cambiados,
    ];
}

/**
 * Lo mismo para un contrato SIN dominio: solo su propia `contratos`.`situacion`.
 *
 * Hoy los 50 contratos tienen dominio, pero el `0` del sistema historico es "sin
 * asignar" y no una referencia. Sin dominio no hay nada que propagar.
 *
 * @return array{dominio:null, contratos:array<int, array>, avisos:list<string>, escrito:bool, contratos_cambiados:int}
 */
function situacionRecalcularContratoSuelto(PDO $pdo, array $con, bool $escribir): array
{
    $id    = (int) $con['id'];
    $deuda = deudaDeContrato($pdo, $id);
    $vieja = $con['situacion'] === null ? null : trim((string) $con['situacion']);
    $nueva = $deuda['situacion'];
    $cambio = $vieja !== $nueva;

    if ($escribir && $cambio) {
        $pdo->prepare('UPDATE contratos SET situacion = :s WHERE id = :id')
            ->execute([':s' => $nueva, ':id' => $id]);
    }

    return [
        'dominio'   => null,
        'contratos' => [[
            'id'               => $id,
            'situacion_actual' => $vieja,
            'situacion_nueva'  => $nueva,
            'cambio'           => $cambio,
            'deuda'            => $deuda,
        ]],
        'avisos'              => ['El contrato no tiene dominio asignado: sólo se recalculó su propia situación.'],
        'escrito'             => $escribir,
        'contratos_cambiados' => $cambio ? 1 : 0,
    ];
}

/** El atraso en palabras, con el signo explicito cuando todavia no vencio. */
function textoAtraso(?int $dias): string
{
    if ($dias === null) return 'sin fecha';
    if ($dias < 0)      return sprintf('vence en %d día(s)', -$dias);
    if ($dias === 0)    return 'vence hoy';

    return sprintf('%d día(s) de atraso', $dias);
}

/**
 * EL RECALCULO QUE DISPARA UN COBRO, en su propia transaccion.
 *
 * Lo llama `api/pago_imputado.php`, el webhook que avisa la imputacion de
 * MercadoPago -- hoy el UNICO camino por el que un cobro llega a este repo.
 *
 * COBRAR PUEDE DEVOLVERLE EL SERVICIO A UN CLIENTE: si lo que se acaba de pagar
 * era la deuda que lo tenia en Suspendido, el dominio vuelve a Normal y la app
 * le dibuja otra vez los controles. Eso es exactamente lo que hace esta funcion,
 * con las MISMAS reglas que la tarea de las 04:00 -- no hay una cuenta "para
 * cuando pagan" distinta de la cuenta de todos los dias.
 *
 * LO QUE DISPARA ESTO NO ES UNA FILA EN `pagos`: es que el comprobante haya
 * dejado de estar Pendiente. `pagos` es una tabla del sistema historico que este
 * repo TODAVIA NO TIENE EN CUENTA para nada --la mora se cuenta sobre
 * `comprobantes`.`estado`--, asi que `api/comprobantes_accion.php?accion=pago`,
 * que la escribe, NO llama a esta funcion: hacerlo ataria la rehabilitacion de
 * un cliente a un dato que el resto del sistema ignora.
 *
 * NO LANZA. El pago ya esta registrado cuando esto corre y es un hecho
 * independiente: que el recalculo falle no puede deshacerlo ni devolver un 500 a
 * quien acaba de cobrar. El fallo se reporta en el valor de retorno y queda en
 * el Visor; la corrida de las 04:00 lo corrige igual.
 *
 * SE ACOPLA A LA TRANSACCION DEL LLAMADOR SI YA HAY UNA ABIERTA, y solo abre la
 * suya cuando no la hay. `beginTransaction()` sobre una conexion que ya esta en
 * transaccion lanza, asi que sin esta guarda la funcion seria inutilizable desde
 * cualquier endpoint que envuelva el cobro -- y lo peor es COMO fallaria: el
 * `catch` de abajo haria `rollBack()` sobre la transaccion AJENA, tirando abajo
 * el pago que el llamador acababa de escribir. Cuando se acopla no commitea: el
 * commit es de quien abrio.
 *
 * @return array{ok:bool, cambios:int, dominio:?string, error:?string}
 */
function recalcularSituacionTrasPago(PDO $pdo, int $dominio, string $desde): array
{
    $propia = !$pdo->inTransaction();

    try {
        if ($propia) $pdo->beginTransaction();
        $r = situacionRecalcularDominio($pdo, $dominio, true);
        if ($propia) $pdo->commit();
    } catch (Throwable $e) {
        // Solo se revierte lo que esta funcion abrio. Si la transaccion es del
        // llamador, el error sube por el valor de retorno y decide el.
        if ($propia && $pdo->inTransaction()) $pdo->rollBack();

        if (function_exists('registrarSuceso')) {
            registrarSuceso($pdo, 'cobros', 'error', sprintf(
                'No se pudo recalcular la situación del dominio #%d %s: %s. '
                . 'El pago quedó registrado; la tarea de las 04:00 lo corrige.',
                $dominio, $desde, $e->getMessage()
            ));
        }

        return ['ok' => false, 'cambios' => 0, 'dominio' => null, 'error' => $e->getMessage()];
    }

    sucesoSituacion($pdo, 'cobros', $r, $desde);

    $dom = $r['dominio'];

    return [
        'ok'      => true,
        'cambios' => $r['contratos_cambiados'] + ($dom !== null && $dom['cambio'] ? 1 : 0),
        'dominio' => $dom === null || $dom['situacion_nueva'] === null
            ? null
            : SITUACION_TEXTOS[$dom['situacion_nueva']] ?? $dom['situacion_nueva'],
        'error'   => null,
    ];
}

/**
 * Deja constancia de un recalculo manual en el Visor de sucesos.
 *
 * Lo llaman los DOS `Actualizar situacion` --el de Contratos y el de Dominios--
 * y por eso vive aca: si cada endpoint armara su propio texto, el mismo hecho se
 * leeria distinto en el Visor segun desde que pantalla se lo hubiera disparado.
 * El barrido de las 04:00 NO lo usa: registra un resumen de la corrida entera,
 * que es otra cosa.
 *
 * SUBE A `alerta` CUANDO EL DOMINIO QUEDA SUSPENDIDO VINIENDO DE OTRA COSA. Es
 * el unico resultado de este recalculo por el que alguien va a venir a
 * preguntar: un cliente que entra a la app y no encuentra ningun control. El
 * valor anterior va escrito al lado, por el mismo motivo que en la baja --
 * revertir a mano es leer este renglon.
 *
 * NO ESCRIBE NADA CUANDO NO CAMBIO NADA. Un recalculo que confirma lo que ya
 * habia no es un hecho que haya que poder ubicar en el tiempo, y este item se
 * puede tocar muchas veces seguidas sobre la misma ficha: anotarlos todos
 * llenaria el Visor de ruido y taparia los que si movieron algo.
 */
function sucesoSituacion(PDO $pdo, string $origen, array $r, string $desde): void
{
    if (!function_exists('registrarSuceso')) return;

    $dom      = $r['dominio'];
    $domMovio = $dom !== null && $dom['cambio'];

    if (!$domMovio && $r['contratos_cambiados'] === 0) return;

    $corte = $domMovio
        && $dom['situacion_nueva'] === SITUACION_SUSPENDIDO
        && $dom['situacion_actual'] !== SITUACION_SUSPENDIDO;

    $partes = [sprintf('Situación recalculada %s.', $desde)];

    foreach ($r['contratos'] as $c) {
        if (!$c['cambio']) continue;
        $partes[] = sprintf(
            'Contrato #%d: %s -> %s (%s).',
            $c['id'],
            situacionTexto($c['situacion_actual']),
            situacionTexto($c['situacion_nueva']),
            textoAtraso($c['deuda']['dias'])
        );
    }

    if ($domMovio) {
        $partes[] = sprintf(
            'Dominio #%d %s: %s -> %s.%s',
            $dom['id'],
            $dom['nombre'] !== '' ? $dom['nombre'] : '(sin nombre)',
            situacionTexto($dom['situacion_actual']),
            situacionTexto($dom['situacion_nueva']),
            $corte ? ' SIN SERVICIO: la app deja de mostrarle los controles.' : ''
        );
    }

    registrarSuceso($pdo, $origen, $corte ? 'alerta' : 'info', implode(' ', $partes));
}
