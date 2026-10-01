<?php

declare(strict_types=1);

/**
 * LA FACTURACION DE UN CONTRATO, EN UN SOLO LUGAR.
 *
 * Es `cContrato::facturar()` portada, y la comparten los DOS caminos que emiten
 * el comprobante del abono:
 *
 *   `api/contratos_accion.php?accion=facturar`  a mano, desde la ficha del ABM
 *   `jobs/contratos_facturar.php`               el 1 de cada mes a las 09:00
 *
 * Vive en un archivo aparte por la misma razon que `comprobantes_lib.php`: es
 * UNA regla -- a quien se le factura, con que talonario, que renglones, con que
 * fechas y como avanza el ciclo del contrato -- y dos copias son dos formas de
 * que el comprobante que emite el operador y el que emite el job dejen de ser el
 * mismo documento. Acá eso no seria un detalle de estilo: son facturas, con
 * numeracion fiscal, mandadas a un cliente.
 *
 * ------------------------------------------------------------------------
 * SE PUEDE INCLUIR DESDE CLI, Y POR ESO NO HABLA HTTP
 * ------------------------------------------------------------------------
 *
 * Ninguna funcion de este archivo llama a `json_error()` ni a `json_ok()`: los
 * bloqueos se devuelven como texto en `facturarContexto()['bloqueos']` y los
 * errores de emision salen por excepcion. Quien la incluye decide como se
 * cuentan -- el endpoint los convierte en un 409 y el job los anota en el `.log`
 * y sigue con el contrato siguiente.
 *
 * Resuelve la conexion con `db()`, que declara `api/bootstrap.php` para la web y
 * el propio job para CLI (mismo truco que `articulos_recalcular.php` con
 * `articulos_lib.php`): son seis lineas contra una segunda copia de
 * `facturarContexto()`.
 *
 * LA TRANSACCION LA ABRE EL LLAMADOR, no `facturarEmitir()`. El endpoint emite
 * un comprobante por request; el job emite decenas y necesita UNA TRANSACCION
 * POR CONTRATO -- ver el encabezado de `jobs/contratos_facturar.php`.
 *
 * ------------------------------------------------------------------------
 * LO QUE NO SE PORTO de `cContrato`
 * ------------------------------------------------------------------------
 *
 *   - El fallback `if ($this->plan == 0) $this->plan = 100;` de `facturar()`.
 *     Ademas de facturar un plan que nadie eligio, el `modificar()` del final se
 *     lo GUARDABA al contrato: emitir una factura le cambiaba el plan. Aca un
 *     contrato sin plan es un bloqueo con nombre, que es lo que el operador
 *     tiene que resolver. Hoy los 26 contratos habilitados tienen plan.
 *   - Las columnas `empresa`, `tipo`, `subtipo`, `punto` y `fiscal` que
 *     `facturar()` copiaba del talonario a `comprobantes`: YA NO EXISTEN en la
 *     tabla, migraron al talonario (por eso `comprobantesvista` las expone como
 *     `talonarioTipo`, `talonarioPunto`, ...). Lo mismo con `localidad`,
 *     `provincia` y `pais` del cliente.
 */

require_once __DIR__ . '/comprobantes_lib.php';
require_once dirname(__DIR__) . '/lib/habilitado.php';

/** "Sin fecha" del sistema historico. Mismos valores que en `contratos.php`. */
const FECHA_GENESIS     = '1500-01-01';
const FECHA_APOCALIPSIS = '2500-01-01';

/* La cotizacion que se sella sale de `cotizacionDelDia()`, en
   `comprobantes_lib.php`: es la MISMA fila de `parametros` y la misma lectura
   que usan el alta manual y el Duplicar. Vivia duplicada en
   `contratos_accion.php` -- con su propia constante `PARAMETRO_COTIZACION` --
   hasta que los tres caminos que crean un comprobante pasaron a sellarla, que es
   cuando dos copias de la misma regla empiezan a poder discrepar.

   ACA SE SELLA PARA CUALQUIER TIPO DE TALONARIO y no solo para Prefactura y
   Factura (`cotizacionAlCrear()`): es lo que viene haciendo desde el legacy y
   lo que tienen las 424 filas con cotizacion de la base -- 234 Prefacturas y
   190 Recibos, todas con `contrato`. Acotarlo ahora le sacaria la cotizacion a
   los contratos que facturan contra un talonario de Recibo, que son reales. */

/** Medio de pago con el que nace el comprobante del abono (`medios`.`id`). */
const MEDIO_ABONO = 1;

/** Dias entre la emision y el vencimiento del comprobante del abono. */
const VENCIMIENTO_DIAS = 7;

/**
 * Largo de cada columna de `comprobantes` que se copia del cliente.
 *
 * Se chequean ANTES de abrir el INSERT y no se recortan: el comprobante es el
 * documento que ve el cliente, y una razon social cortada a la mitad es un dato
 * mal emitido, no un dato prolijo. Hoy el maximo real es 54 sobre 250.
 */
const LARGO_CLIENTE = [
    'razon'     => 250,
    'domicilio' => 250,
    'correo'    => 100,
    'celular'   => 100,
    'condicion' => 2,
    'cuit'      => 50,
];

/* ------------------------------------------------- quien es "facturable" */

/**
 * EL CRITERIO DE "FACTURABLE", EN SQL Y EN UN SOLO LUGAR.
 *
 * Es el atajo `Facturables` del listado de Contratos, que a su vez es el filtro
 * del back office viejo (`listar?frd=<genesis>&frh=<hoy>&hab=1`):
 *
 *   contrato HABILITADO  +  con fecha de proxima facturacion YA CUMPLIDA
 *
 * `contratos`.`facturar` es la fecha del proximo abono. Los dos centinelas del
 * sistema historico NO son fechas y se excluyen en el `WHERE`, no despues: el
 * genesis (`1500-01-01`) significa "no hay fecha", y facturarlo escribiria el
 * periodo 1500-01 en el comprobante del cliente. Hoy hay un contrato habilitado
 * asi.
 *
 * SE COMPARA CONTRA `CURDATE()` DE LA BASE, nunca contra el reloj de PHP: es la
 * misma regla que el resto del repo, y aca decide si a un cliente se le emite un
 * comprobante o no. El contenedor corre en UTC y la conexion fija
 * `SET time_zone = '-03:00'`.
 *
 * OJO, ESTA ESCRITO DOS VECES Y LAS DOS TIENEN QUE MOVERSE JUNTAS.
 * `api/contratos.php` calcula la misma bandera en PHP sobre la fila ya
 * normalizada (`$fila['facturable'] = habilitado === 1 && facturar !== null &&
 * facturar <= $hoy`, donde `fechaSalida()` ya mando los centinelas a `null`), y
 * no puede incluir este archivo: declara su propio `combo()`, `idOrNull()` y
 * `FECHA_GENESIS`, que colisionarian con los de `comprobantes_lib.php`. Es la
 * misma duplicacion-por-construccion que `lib/habilitado.php` entre las tres
 * apps. Si el criterio cambia, cambia en los dos lados o la tarjeta del listado
 * anuncia una cantidad y el job factura otra.
 */
const FACTURABLE_WHERE =
    'habilitado = 1
     AND facturar IS NOT NULL
     AND facturar NOT IN (:genesis, :apocalipsis)
     AND facturar <= CURDATE()';

/** Los dos centinelas que `FACTURABLE_WHERE` necesita bindeados. */
function facturableParams(): array
{
    return [':genesis' => FECHA_GENESIS, ':apocalipsis' => FECHA_APOCALIPSIS];
}

/**
 * Los contratos facturables HOY, de la fecha mas atrasada a la mas reciente.
 *
 * Devuelve `[id, facturar]` y no la fila entera a proposito: lo que se factura
 * se vuelve a leer DENTRO de la transaccion y con la fila bloqueada
 * (`contratoFila($id, true)`), asi que cualquier columna que viajara desde aca
 * podria estar vencida al momento de emitir.
 *
 * `ORDER BY facturar` para que, si la corrida se corta a la mitad, lo que quedo
 * emitido sean los periodos mas viejos -- los que mas tiempo llevan sin
 * cobrarse. El `id` desempata para que dos corridas recorran lo mismo en el
 * mismo orden.
 */
function contratosFacturables(): array
{
    $stmt = db()->prepare(
        'SELECT id, facturar FROM contratos WHERE ' . FACTURABLE_WHERE .
        ' ORDER BY facturar ASC, id ASC'
    );
    $stmt->execute(facturableParams());

    return $stmt->fetchAll();
}

/**
 * Si ESTE contrato sigue siendo facturable, con la fila ya en la mano.
 *
 * Misma regla que `FACTURABLE_WHERE` pero sobre la fila bloqueada, que es donde
 * de verdad importa: entre que el job lista los candidatos y que llega a
 * emitirle a uno, alguien pudo haberlo facturado desde la ficha del ABM. La
 * fecha de corte se pasa desde afuera (`CURDATE()` de la base) para no estrenar
 * un segundo reloj.
 */
function esFacturable(array $con, string $hoy): bool
{
    if (!esHabilitado($con['habilitado'] ?? 0)) return false;

    $facturar = fechaReal($con['facturar'] ?? null);

    return $facturar !== null && $facturar <= $hoy;
}

/* --------------------------------------------------------- el contexto */

/**
 * Todo lo que necesita saber "facturar": a quien, con que talonario, que
 * renglones y con que fechas. La usan la previsualizacion, la emision a mano y
 * el job, asi que lo que muestra la pantalla es literalmente lo que se va a
 * escribir -- y lo que escribe el job es lo mismo que escribiria un operador.
 *
 * `bloqueos` son las razones por las que NO se puede emitir; `avisos`, las que
 * conviene mirar antes de hacerlo. La funcion no corta por su cuenta: junta
 * todo y deja que el llamador decida (el GET los muestra, el POST los rechaza,
 * el job saltea el contrato y lo reporta).
 */
function facturarContexto(array $con): array
{
    $bloqueos = [];
    $avisos   = [];

    if (!esHabilitado($con['habilitado'])) {
        $bloqueos[] = 'El contrato esta deshabilitado: solo se factura un contrato habilitado.';
    }

    // Periodo: es `contratos`.`facturar`, la fecha del proximo abono. Un
    // centinela no es "hoy", es "no hay fecha": facturarlo escribiria el
    // periodo 1500-01 en el comprobante y dejaria `facturar` en 1500-02-01.
    $periodo = fechaReal($con['facturar'] ?? null);
    if ($periodo === null) {
        $bloqueos[] = 'El contrato no tiene fecha de facturacion: sin ella no hay periodo que facturar.';
    }

    $dominio = filaPorId('dominios', idOrNull($con['dominio']));
    if ($dominio === null) {
        $bloqueos[] = 'El contrato no tiene dominio: el comprobante lo nombra en su primer renglon.';
    }

    // EL CLIENTE SALE DEL DOMINIO, NO DEL CONTRATO, igual que en el legacy
    // (`$zDominio->leer(...); $zCliente->leer($zDominio->cliente);`). Son la
    // misma persona en 48 de los 50 contratos; en los otros dos no, y por eso
    // la diferencia se avisa abajo en vez de resolverse en silencio.
    $cliente = $dominio === null ? null : filaPorId('clientes', idOrNull($dominio['cliente']));
    if ($dominio !== null && $cliente === null) {
        $bloqueos[] = 'El dominio "' . ($dominio['nombre'] ?? '') . '" no tiene cliente: no hay a quien facturarle.';
    }

    $talonario = $cliente === null ? null : filaPorId('talonarios', idOrNull($cliente['talonario']));
    if ($cliente !== null && $talonario === null) {
        $bloqueos[] = 'El cliente "' . ($cliente['nombre'] ?? '') . '" no tiene talonario: no hay de donde sacar la numeracion.';
    }

    $plan = filaPorId('planes', idOrNull($con['plan']));
    if ($plan === null) {
        $bloqueos[] = 'El contrato no tiene plan: el abono sale del articulo del plan.';
    }

    $articulo = $plan === null ? null : filaPorId('articulos', idOrNull($plan['articulo']));
    if ($plan !== null && $articulo === null) {
        $bloqueos[] = 'El plan "' . ($plan['nombre'] ?? '') . '" no tiene articulo: no hay precio que facturar.';
    }

    // Los datos del cliente se copian al comprobante y ahi las columnas son mas
    // cortas: lo que no entra se rechaza, no se recorta (ver LARGO_CLIENTE).
    if ($cliente !== null) {
        foreach (LARGO_CLIENTE as $campo => $max) {
            $valor = trim((string) ($cliente[$campo] ?? ''));
            if (mb_strlen($valor) > $max) {
                $bloqueos[] = "El campo \"{$campo}\" del cliente tiene " . mb_strlen($valor) .
                    " caracteres y en el comprobante entran {$max}: corregilo antes de facturar.";
            }
        }
    }

    /* ---- avisos ---- */

    $contratoCliente = idOrNull($con['cliente']);
    if ($cliente !== null && $contratoCliente !== null && (int) $cliente['id'] !== $contratoCliente) {
        $otro = filaPorId('clientes', $contratoCliente);
        $avisos[] = 'El comprobante sale a nombre de "' . ($cliente['nombre'] ?? '') .
            '", el cliente del dominio, y no de "' . ($otro['nombre'] ?? ('#' . $contratoCliente)) .
            '", que es el que tiene cargado el contrato.';
    }
    if ($talonario !== null && (int) ($talonario['estado'] ?? 0) !== 1) {
        $avisos[] = 'El talonario "' . ($talonario['nombre'] ?? '') . '" no esta habilitado.';
    }

    /* ---- renglones ---- */

    $hoy          = new DateTimeImmutable('today');
    $periodoTexto = $periodo === null ? null : substr($periodo, 0, 7);
    $renglones    = [];

    if ($periodo !== null && $dominio !== null && $articulo !== null) {
        // Renglon rotulo del dominio: monto 0, es el encabezado del periodo.
        $renglones[] = renglon(
            'Dominio ' . trim((string) ($dominio['nombre'] ?? '')) . ' | Período ' . $periodoTexto
        );

        // Renglon del abono: el articulo del plan, a precio de lista.
        $renglones[] = renglon(
            trim((string) ($articulo['nombre'] ?? '')),
            (int) $articulo['id'],
            (float) ($articulo['iva']   ?? 0),
            (float) ($articulo['venta'] ?? 0)
        );

        // Renglon de la promocion. `contratos`.`promo` SE LEE COMO PORCENTAJE,
        // que es lo que hace el legacy, y ESA CUENTA NO CAMBIO NUNCA: ni cuando
        // la columna paso a ser FK contra `promociones` (migracion
        // `20260929_1100`, cuyo id ERA el porcentaje) ni cuando esa tabla se
        // borro y la columna volvio a ser un numero pelado de 1 a 100
        // (`20260930_1000`). Ver PROMO en `contratos.php`.
        // Desde que el ABM la escribe, este renglon SI puede salir -- hasta
        // entonces las 50 filas tenian `promo` en NULL y nunca aparecia.
        $promo = (int) ($con['promo'] ?? 0);
        $desde = trim((string) ($con['desde'] ?? ''));
        $hasta = trim((string) ($con['hasta'] ?? ''));
        $hoyStr = $hoy->format('Y-m-d');
        if ($promo > 0 && $desde !== '' && $hasta !== '' && $desde <= $hoyStr && $hoyStr <= $hasta) {
            $monto = round(((float) ($articulo['venta'] ?? 0) * $promo) / 100, 2);
            $renglones[] = renglon('Promoción Descuento ' . $promo . '%', null, 21.0, $monto, -$monto);
        }

        // Renglones de las lineas celulares que Reactor factura por el dominio.
        foreach (chipsDelDominio((int) $dominio['id']) as $chip) {
            $telefono = trim((string) ($chip['telefono'] ?? ''));
            $renglones[] = renglon('Línea ' . $telefono . ' | Abono ' . $periodoTexto);

            $artChip = idOrNull($chip['articulo']);
            if ($artChip === null) {
                // El legacy leia un articulo inexistente y metia el renglon
                // igual, con lo que quedara en el objeto. Aca la linea se
                // factura sin su renglon de precio y se avisa.
                $avisos[] = 'La línea ' . $telefono . ' no tiene articulo asignado: se factura en 0.';
                continue;
            }
            $renglones[] = renglon(
                trim((string) ($chip['articulo_nombre'] ?? '')),
                $artChip,
                (float) ($chip['articulo_iva']   ?? 0),
                (float) ($chip['articulo_venta'] ?? 0)
            );
        }
    }

    return [
        'dominio'      => $dominio,
        'cliente'      => $cliente,
        'talonario'    => $talonario,
        'plan'         => $plan,
        'articulo'     => $articulo,
        'periodo'      => $periodo,
        'periodoTexto' => $periodoTexto,
        // Un mes exacto con `DateInterval('P1M')`, la misma cuenta de
        // `cTiempo::sumar($fecha, 'm', 1)` -- desbordes de fin de mes incluidos.
        // En la practica `facturar` siempre cae el dia 1 (el ABM del legacy la
        // pasa por `inicio($x, 'm')`), asi que el desborde no llega a darse.
        'siguiente'    => $periodo === null
            ? null
            : (new DateTimeImmutable($periodo))->add(new DateInterval('P1M'))->format('Y-m-d'),
        'emision'      => $hoy->format('Y-m-d'),
        'vencimiento'  => $hoy->modify('+' . VENCIMIENTO_DIAS . ' days')->format('Y-m-d'),
        'cotizacion'   => cotizacionDelDia(),
        'medio'        => medioAbono(),
        'renglones'    => $renglones,
        'bloqueos'     => $bloqueos,
        'avisos'       => $avisos,
    ];
}

/* --------------------------------------------------------- la emision */

/**
 * Emite el comprobante del periodo y adelanta el ciclo del contrato.
 *
 * ARRANCA CON UNA TRANSACCION YA ABIERTA Y CON EL CONTRATO BLOQUEADO -- `$con`
 * tiene que venir de `contratoFila($id, true)` --, y NO commitea: eso es del
 * llamador. El endpoint emite uno por request; el job emite decenas y necesita
 * que cada comprobante se cierre por su cuenta.
 *
 * Es `cContrato::facturar()` portada, con tres diferencias que no son de estilo:
 *
 *   1. TODO PASA EN UNA TRANSACCION. El legacy inserta la cabecera, los
 *      renglones, totaliza, numera y recien despues guarda el contrato, cada
 *      cosa con su propio commit implicito: si algo falla en el medio queda un
 *      comprobante a medio emitir con el numero ya consumido.
 *   2. EL NUMERO SE TOMA CON `SELECT ... FOR UPDATE` sobre el talonario, igual
 *      que `comprobantes_accion.php?accion=autorizar`. El legacy lee la serie,
 *      le suma uno y guarda sin bloqueo: dos facturaciones simultaneas emiten
 *      dos comprobantes con el MISMO numero fiscal. Con el job esto deja de ser
 *      teorico: los 24 contratos facturables de hoy comparten UN talonario.
 *   3. El comprobante nace en Preparacion con `serie = 0` y pasa a Pendiente al
 *      numerarse, en vez de nacer ya en Pendiente y numerarse despues. El estado
 *      final es identico; lo que cambia es que no existe un instante en el que
 *      haya un comprobante "pendiente" sin numero.
 *
 * @throws RuntimeException si el talonario desaparecio entre el contexto y el
 *         numerado. Corta la transaccion del llamador, que es lo correcto: sin
 *         talonario no hay numero y un comprobante sin numero no se emite.
 */
function facturarEmitir(array $con, array $ctx): array
{
    $pdo       = db();
    $cliente   = $ctx['cliente'];
    $talonario = $ctx['talonario'];

    // Cabecera: nace en Preparacion y sin numero. `medio` es el 1 del
    // legacy y `cotizacion` la del parametro, sellada al momento de emitir.
    $ins = $pdo->prepare(
        "INSERT INTO comprobantes
             (uuid, talonario, serie, caenro, caevto, caeres, emision, vencimiento,
              contrato, cliente, razon, condicion, cuit, domicilio, correo, celular,
              subtotal, iva, total, cotizacion, observaciones, comentarios, medio, estado)
         VALUES
             (:uuid, :talonario, 0, '', '', '', :emision, :vencimiento,
              :contrato, :cliente, :razon, :condicion, :cuit, :domicilio, :correo, :celular,
              0, 0, 0, :cotizacion, '', '', :medio, '1')"
    );
    $ins->execute([
        ':uuid'        => comprobanteUuidLibre(),
        ':talonario'   => (int) $talonario['id'],
        ':emision'     => $ctx['emision'],
        ':vencimiento' => $ctx['vencimiento'],
        ':contrato'    => (int) $con['id'],
        ':cliente'     => (int) $cliente['id'],
        ':razon'       => trim((string) ($cliente['razon']     ?? '')),
        ':condicion'   => trim((string) ($cliente['condicion'] ?? '')),
        ':cuit'        => trim((string) ($cliente['cuit']      ?? '')),
        ':domicilio'   => trim((string) ($cliente['domicilio'] ?? '')),
        ':correo'      => trim((string) ($cliente['correo']    ?? '')),
        ':celular'     => trim((string) ($cliente['celular']   ?? '')),
        ':cotizacion'  => $ctx['cotizacion'],
        ':medio'       => $ctx['medio'],
    ]);

    $comprobante = (int) $pdo->lastInsertId();

    // Renglones, en el orden en que los arma `facturarContexto()`.
    // `orden` se autonumera desde 0 (mismo criterio que el alta manual de
    // `comprobantes_renglones.php`): el legacy los dejaba todos en 0 y el
    // orden impreso dependia del AUTO_INCREMENT.
    $insRen = $pdo->prepare(
        "INSERT INTO comprobantesrenglones
             (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
         VALUES (:cpb, :orden, 1, :articulo, :detalle, :iva, :unitario, :monto, '1')"
    );
    foreach ($ctx['renglones'] as $orden => $r) {
        $insRen->execute([
            ':cpb'      => $comprobante,
            ':orden'    => $orden,
            ':articulo' => $r['articulo'],
            ':detalle'  => $r['detalle'],
            ':iva'      => $r['iva'],
            ':unitario' => $r['unitario'],
            ':monto'    => $r['monto'],
        ]);
    }

    $totales = comprobanteTotalizar($comprobante);

    // Numero: el talonario es el contador compartido.
    $tal = $pdo->prepare('SELECT serie FROM talonarios WHERE id = :id FOR UPDATE');
    $tal->execute([':id' => (int) $talonario['id']]);
    $serieTal = $tal->fetchColumn();
    if ($serieTal === false) {
        throw new RuntimeException('El talonario del cliente no existe');
    }

    $serie = ((int) $serieTal) + 1;
    $pdo->prepare('UPDATE talonarios SET serie = :s WHERE id = :id')
        ->execute([':s' => $serie, ':id' => (int) $talonario['id']]);

    $pdo->prepare("UPDATE comprobantes SET serie = :s, estado = :e WHERE id = :id")
        ->execute([':s' => $serie, ':e' => ESTADO_PENDIENTE, ':id' => $comprobante]);

    // Ciclo del contrato: el periodo que se acaba de facturar pasa a
    // `facturado`, `facturar` avanza un mes y `remitir` queda en 1 para que
    // la rutina de envio mande el estado de cuenta nuevo.
    //
    // ESTE `UPDATE` ES LO QUE HACE IDEMPOTENTE AL JOB: al adelantar `facturar`
    // un mes, el contrato deja de cumplir `FACTURABLE_WHERE` -- salvo que
    // viniera atrasado mas de un periodo, que es el caso que el job reporta en
    // vez de resolver solo.
    $pdo->prepare(
        "UPDATE contratos
            SET facturado = :facturado, facturar = :facturar, remitir = '1'
          WHERE id = :id"
    )->execute([
        ':facturado' => $ctx['periodo'],
        ':facturar'  => $ctx['siguiente'],
        ':id'        => (int) $con['id'],
    ]);

    return [
        'comprobante' => $comprobante,
        'serie'       => $serie,
        'numero'      => comprobanteNumero($talonario['punto'] ?? null, $serie),
        'totales'     => $totales,
        'facturado'   => $ctx['periodo'],
        'facturar'    => $ctx['siguiente'],
    ];
}

/* --------------------------------------------------------------- helpers */

/** Un renglon del comprobante que se va a emitir, con la forma del INSERT. */
function renglon(
    string $detalle,
    ?int $articulo = null,
    float $iva = 0.0,
    float $unitario = 0.0,
    ?float $monto = null
): array {
    return [
        'articulo' => $articulo,
        'detalle'  => $detalle,
        'iva'      => round($iva, 2),
        'unitario' => round($unitario, 2),
        'monto'    => round($monto ?? $unitario, 2),
    ];
}

/**
 * Lineas celulares que Reactor le factura al dominio: las de responsable 'R'
 * (Reactor) y estado 1, ordenadas por telefono. Es el mismo criterio del
 * legacy; las de responsable del cliente las paga el cliente por su cuenta.
 */
function chipsDelDominio(int $dominio): array
{
    $stmt = db()->prepare(
        "SELECT c.telefono, c.articulo,
                a.nombre AS articulo_nombre, a.iva AS articulo_iva, a.venta AS articulo_venta
         FROM chips c
         LEFT JOIN articulos a ON a.id = c.articulo
         WHERE c.dominio = :d AND c.responsable = 'R' AND c.estado = 1
         ORDER BY c.telefono ASC, c.id ASC"
    );
    $stmt->execute([':d' => $dominio]);

    return $stmt->fetchAll();
}

/** La fila completa del contrato, opcionalmente bloqueada para la transaccion. */
function contratoFila(int $id, bool $bloquear = false): ?array
{
    $stmt = db()->prepare('SELECT * FROM contratos WHERE id = :id' . ($bloquear ? ' FOR UPDATE' : ''));
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

/**
 * Una fila por id, o null. El nombre de la tabla NO viene de afuera: lo fijan
 * los llamadores de este archivo.
 */
function filaPorId(string $tabla, ?int $id): ?array
{
    if ($id === null) return null;

    $stmt = db()->prepare("SELECT * FROM {$tabla} WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();

    return $fila ?: null;
}

/** Una fecha de la base, o null si es un centinela del sistema historico. */
function fechaReal(mixed $valor): ?string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '' || $v === '0000-00-00') return null;
    if ($v === FECHA_GENESIS || $v === FECHA_APOCALIPSIS) return null;

    return $v;
}

/**
 * Medio de pago del comprobante del abono. Es el 1 hardcodeado del legacy, pero
 * chequeado: `comprobantes`.`medio` es FK RESTRICT y un id que no existe haria
 * fallar el INSERT con un error del motor en vez de nacer sin medio.
 */
function medioAbono(): ?int
{
    $stmt = db()->prepare('SELECT 1 FROM medios WHERE id = :id');
    $stmt->execute([':id' => MEDIO_ABONO]);

    return $stmt->fetchColumn() ? MEDIO_ABONO : null;
}
