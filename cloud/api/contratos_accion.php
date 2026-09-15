<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/comprobantes_lib.php';

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
 * LO QUE NO SE PORTO de `cContrato`:
 *
 *   - `deshabilitar()`, que ademas de dar de baja apaga TODOS los contratos del
 *     dominio y le borra a `dominios`.`contrato` la referencia. El menu del back
 *     office viejo NO lo llama: "Dar baja" es `editar.php?mod=baj`, que escribe
 *     `baja` y `habilitado` y nada mas. Se porta lo que el menu hacia: dejar al
 *     dominio sin contrato vigente es otra decision, y no la que ofrece una
 *     pantalla que dice "dar de baja este contrato".
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

/** "Sin fecha" del sistema historico. Mismos valores que en `contratos.php`. */
const FECHA_GENESIS     = '1500-01-01';
const FECHA_APOCALIPSIS = '2500-01-01';

/** `parametros`.`variable` con la cotizacion que se sella en el comprobante. */
const PARAMETRO_COTIZACION = 'articulos.dolar.cotizacion';

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
 * Es `cContrato::facturar()` portada, con tres diferencias que no son de estilo:
 *
 *   1. TODO PASA EN UNA TRANSACCION. El legacy inserta la cabecera, los
 *      renglones, totaliza, numera y recien despues guarda el contrato, cada
 *      cosa con su propio commit implicito: si algo falla en el medio queda un
 *      comprobante a medio emitir con el numero ya consumido.
 *   2. EL NUMERO SE TOMA CON `SELECT ... FOR UPDATE` sobre el talonario, igual
 *      que `comprobantes_accion.php?accion=autorizar`. El legacy lee la serie,
 *      le suma uno y guarda sin bloqueo: dos facturaciones simultaneas emiten
 *      dos comprobantes con el MISMO numero fiscal.
 *   3. El comprobante nace en Preparacion con `serie = 0` y pasa a Pendiente al
 *      numerarse, en vez de nacer ya en Pendiente y numerarse despues. El estado
 *      final es identico; lo que cambia es que no existe un instante en el que
 *      haya un comprobante "pendiente" sin numero.
 */
function accionFacturar(): void
{
    $id  = contratoPedido();
    $pdo = db();

    try {
        $pdo->beginTransaction();

        // FOR UPDATE sobre el contrato: es lo que impide que dos clicks
        // seguidos emitan dos veces el mismo periodo. El segundo espera, y
        // cuando entra ya ve `facturar` adelantado un mes.
        $con = contratoFila($id, true);
        if (!$con) { $pdo->rollBack(); json_error('Contrato no encontrado', 404); }

        $ctx = facturarContexto($con);
        if ($ctx['bloqueos'] !== []) {
            $pdo->rollBack();
            json_error('No se puede facturar. ' . implode(' ', $ctx['bloqueos']), 409);
        }

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
            $pdo->rollBack();
            json_error('El talonario del cliente no existe', 422);
        }

        $serie = ((int) $serieTal) + 1;
        $pdo->prepare('UPDATE talonarios SET serie = :s WHERE id = :id')
            ->execute([':s' => $serie, ':id' => (int) $talonario['id']]);

        $pdo->prepare("UPDATE comprobantes SET serie = :s, estado = :e WHERE id = :id")
            ->execute([':s' => $serie, ':e' => ESTADO_PENDIENTE, ':id' => $comprobante]);

        // Ciclo del contrato: el periodo que se acaba de facturar pasa a
        // `facturado`, `facturar` avanza un mes y `remitir` queda en 1 para que
        // la rutina de envio mande el estado de cuenta nuevo.
        $pdo->prepare(
            "UPDATE contratos
                SET facturado = :facturado, facturar = :facturar, remitir = '1'
              WHERE id = :id"
        )->execute([
            ':facturado' => $ctx['periodo'],
            ':facturar'  => $ctx['siguiente'],
            ':id'        => $id,
        ]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok([
        'contrato'    => $id,
        'comprobante' => $comprobante,
        'serie'       => $serie,
        'numero'      => comprobanteNumero($talonario['punto'] ?? null, $serie),
        'totales'     => $totales,
        'facturado'   => $ctx['periodo'],
        'facturar'    => $ctx['siguiente'],
        'avisos'      => $ctx['avisos'],
    ], 201);
}

/**
 * Todo lo que necesita saber "facturar": a quien, con que talonario, que
 * renglones y con que fechas. La usan la previsualizacion y la emision, asi que
 * lo que muestra la pantalla es literalmente lo que se va a escribir.
 *
 * `bloqueos` son las razones por las que NO se puede emitir; `avisos`, las que
 * conviene mirar antes de hacerlo. La funcion no corta por su cuenta: junta
 * todo y deja que el llamador decida (el GET los muestra, el POST los rechaza).
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
        // que es lo que hace el legacy y lo que ofrece su combo (0, 10, ... 100)
        // aunque el esquema la declare FK contra `articulos`. Por eso el ABM no
        // la escribe (ver PROMO en `contratos.php`): aca solo se lee, y con las
        // 50 filas en NULL este renglon no sale nunca.
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
        'cotizacion'   => cotizacion(),
        'medio'        => medioAbono(),
        'renglones'    => $renglones,
        'bloqueos'     => $bloqueos,
        'avisos'       => $avisos,
    ];
}

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

/* --------------------------------------------------------------- helpers */

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
 * Cotizacion del dolar que se sella en el comprobante.
 *
 * NO convierte nada: el legacy factura `articulos`.`venta` tal cual y guarda la
 * cotizacion al lado como referencia de cuando se emitio. Sin el parametro
 * cargado va 0, que es lo que devolvia `cParametro::valorLeer()`.
 */
function cotizacion(): float
{
    $stmt = db()->prepare('SELECT valor FROM parametros WHERE variable = :v ORDER BY id ASC LIMIT 1');
    $stmt->execute([':v' => PARAMETRO_COTIZACION]);
    $valor = $stmt->fetchColumn();

    return $valor === false ? 0.0 : round((float) str_replace(',', '.', (string) $valor), 2);
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
