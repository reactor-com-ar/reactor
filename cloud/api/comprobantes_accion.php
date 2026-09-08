<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/comprobantes_lib.php';
require_once dirname(__DIR__) . '/lib/databox.php';

/**
 * Acciones de negocio sobre un comprobante: `?accion=<nombre>`.
 *
 *   autorizar  Preparacion -> Pendiente, tomando el proximo numero del talonario.
 *   anular     Pendiente   -> Anulado.
 *   duplicar   Clona cabecera y renglones en un comprobante nuevo, en Preparacion.
 *   correo     Encola por Databox el link del visor al correo del comprobante.
 *   pago       Registra una fila en `pagos` contra el comprobante.
 *
 * Van en un endpoint aparte y NO como un `PUT` sobre `comprobantes.php` porque
 * ninguna es "guardar los campos que mandaste": cada una es una transicion con
 * su propia precondicion, y varias tocan mas de una tabla. Mezclarlas con la
 * edicion haria que un payload con `estado` adentro pudiera saltearse la
 * precondicion.
 *
 * LAS PRECONDICIONES SE LEEN DE LA BASE EN CADA REQUEST, nunca de lo que
 * manda el front. `comprobanteSalida()` sirve los mismos booleanos
 * (`puede_autorizar`, `puede_anular`, ...) para que la UI no dibuje lo que no
 * corresponde, pero el corte real esta aca: es el mismo criterio de los tres
 * permisos del perfil en CLAUDE.md -- esconder el boton no es el control.
 *
 * LO QUE NO SE PORTO: `cComprobante::pagoRegistrar()`, que ademas de crear el
 * pago emitia el recibo automaticamente. Escribe `origen`, `empresa`, `tipo`,
 * `subtipo`, `punto` y `fiscal` sobre `comprobantes`, y ESAS COLUMNAS YA NO
 * EXISTEN -- migraron al talonario (por eso `comprobantesvista` las expone como
 * `talonarioTipo`, `talonarioPunto`, ...). Es codigo muerto contra el esquema
 * actual, asi que `pago` registra el pago y nada mas; emitir el recibo se hace
 * duplicando sobre el talonario de recibos, que es una accion visible.
 */

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_error('Metodo no permitido', 405);
    }

    switch ($accion) {
        case 'autorizar': accionAutorizar(); break;
        case 'anular':    accionAnular();    break;
        case 'duplicar':  accionDuplicar();  break;
        case 'correo':    accionCorreo();    break;
        case 'pago':      accionPago();      break;
        default:
            json_error('Accion desconocida', 400);
    }
} catch (Throwable $e) {
    json_error('Error al ejecutar la accion: ' . $e->getMessage(), 500);
}

/** Id del comprobante sobre el que opera la accion. */
function comprobantePedido(): int
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);
    return $id;
}

/**
 * Autorizar: asigna el numero y pasa a Pendiente.
 *
 * Es `cComprobante::autorizar()` con dos agregados que el legacy resuelve
 * afuera o no resuelve:
 *
 *   - Exige razon social, como hace `editar.php` antes de llamar a autorizar
 *     ("No se puede autorizar un comprobante sin razon social").
 *   - Exige al menos un renglon: un comprobante sin renglones tiene total 0 y
 *     consumiria un numero de la serie para no decir nada.
 *
 * EL NUMERO SE TOMA EN UNA TRANSACCION CON `SELECT ... FOR UPDATE` sobre el
 * talonario. El legacy lee la serie, le suma uno y guarda, sin bloqueo: dos
 * autorizaciones simultaneas leen el mismo valor y emiten dos comprobantes con
 * el MISMO numero fiscal. Con el lock, la segunda espera y toma el siguiente.
 *
 * Y solo se asigna si `serie` es 0, igual que el legacy: reautorizar un
 * comprobante que ya tiene numero no le da uno nuevo.
 */
function accionAutorizar(): void
{
    $id  = comprobantePedido();
    $pdo = db();

    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'SELECT c.estado, c.serie, c.razon, c.talonario
             FROM comprobantes c WHERE c.id = :id FOR UPDATE'
        );
        $stmt->execute([':id' => $id]);
        $com = $stmt->fetch();
        if (!$com) { $pdo->rollBack(); json_error('Comprobante no encontrado', 404); }

        $estado = trim((string) $com['estado']);
        if ($estado !== ESTADO_PREPARACION) {
            $pdo->rollBack();
            json_error(
                'Solo se autoriza un comprobante en Preparacion. Este esta en "' .
                (combo(COMBO_ESTADO)[$estado] ?? 'sin estado') . '".',
                409
            );
        }
        if (trim((string) $com['razon']) === '') {
            $pdo->rollBack();
            json_error('No se puede autorizar un comprobante sin razon social', 422);
        }

        $ren = $pdo->prepare('SELECT COUNT(*) FROM comprobantesrenglones WHERE comprobante = :id');
        $ren->execute([':id' => $id]);
        if ((int) $ren->fetchColumn() === 0) {
            $pdo->rollBack();
            json_error('No se puede autorizar un comprobante sin renglones', 422);
        }

        $serie     = (int) $com['serie'];
        $talonario = idOrNull($com['talonario']);

        if ($serie === 0) {
            if ($talonario === null) {
                $pdo->rollBack();
                json_error('El comprobante no tiene talonario: no hay de donde sacar el numero', 422);
            }

            // FOR UPDATE sobre el talonario: es el contador compartido.
            $tal = $pdo->prepare('SELECT serie FROM talonarios WHERE id = :id FOR UPDATE');
            $tal->execute([':id' => $talonario]);
            $serieTal = $tal->fetchColumn();
            if ($serieTal === false) {
                $pdo->rollBack();
                json_error('El talonario del comprobante no existe', 422);
            }

            $serie = ((int) $serieTal) + 1;
            $pdo->prepare('UPDATE talonarios SET serie = :s WHERE id = :id')
                ->execute([':s' => $serie, ':id' => $talonario]);
        }

        $pdo->prepare('UPDATE comprobantes SET serie = :s, estado = :e WHERE id = :id')
            ->execute([':s' => $serie, ':e' => ESTADO_PENDIENTE, ':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $com = comprobanteCabecera($id);

    json_ok([
        'id'     => $id,
        'serie'  => $serie,
        'numero' => comprobanteNumero($com['punto'] ?? null, $serie),
    ]);
}

/** Anular: Pendiente -> Anulado. No libera el numero, igual que el legacy. */
function accionAnular(): void
{
    $id  = comprobantePedido();
    $com = comprobanteCabecera($id);

    $estado = trim((string) $com['estado']);
    if ($estado !== ESTADO_PENDIENTE) {
        json_error(
            'Solo se anula un comprobante Pendiente. Este esta en "' .
            (combo(COMBO_ESTADO)[$estado] ?? 'sin estado') . '".',
            409
        );
    }

    db()->prepare('UPDATE comprobantes SET estado = :e WHERE id = :id')
        ->execute([':e' => ESTADO_ANULADO, ':id' => $id]);

    json_ok(['id' => $id]);
}

/**
 * Duplicar: clona cabecera y renglones en un comprobante nuevo.
 *
 * Es `cComprobante::duplicar()`: uuid nuevo, emision hoy, vencimiento a 7
 * dias, `serie` en 0 (el duplicado NO hereda el numero: es un borrador que
 * todavia no consume nada de la serie) y estado `Preparacion`. Los totales se
 * recalculan desde los renglones copiados en vez de copiarse, asi el duplicado
 * nace consistente aunque el original tuviera el total desfasado.
 *
 * `caenro` / `caevto` / `caeres` NO se copian: el CAE es de la autorizacion de
 * AFIP del comprobante original y no vale para otro.
 */
function accionDuplicar(): void
{
    $id  = comprobantePedido();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT * FROM comprobantes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $com = $stmt->fetch();
    if (!$com) json_error('Comprobante no encontrado', 404);

    $hoy = new DateTimeImmutable('today');

    try {
        $pdo->beginTransaction();

        $ins = $pdo->prepare(
            "INSERT INTO comprobantes
                 (uuid, talonario, serie, caenro, caevto, caeres, emision, vencimiento,
                  contrato, cliente, razon, condicion, cuit, domicilio, correo, celular,
                  subtotal, iva, total, cotizacion, observaciones, comentarios, medio, estado)
             VALUES
                 (:uuid, :talonario, 0, '', '', '', :emision, :vencimiento,
                  :contrato, :cliente, :razon, :condicion, :cuit, :domicilio, :correo, :celular,
                  0, 0, 0, :cotizacion, :observaciones, :comentarios, :medio, '1')"
        );
        $ins->execute([
            ':uuid'          => comprobanteUuidLibre(),
            ':talonario'     => idOrNull($com['talonario']),
            ':emision'       => $hoy->format('Y-m-d'),
            ':vencimiento'   => $hoy->modify('+7 days')->format('Y-m-d'),
            ':contrato'      => idOrNull($com['contrato']),
            ':cliente'       => idOrNull($com['cliente']),
            ':razon'         => (string) ($com['razon']         ?? ''),
            ':condicion'     => (string) ($com['condicion']     ?? ''),
            ':cuit'          => (string) ($com['cuit']          ?? ''),
            ':domicilio'     => (string) ($com['domicilio']     ?? ''),
            ':correo'        => (string) ($com['correo']        ?? ''),
            ':celular'       => (string) ($com['celular']       ?? ''),
            ':cotizacion'    => $com['cotizacion'] === null ? 0 : (float) $com['cotizacion'],
            ':observaciones' => (string) ($com['observaciones'] ?? ''),
            ':comentarios'   => (string) ($com['comentarios']   ?? ''),
            ':medio'         => idOrNull($com['medio']),
        ]);

        $nuevo = (int) $pdo->lastInsertId();

        // Copia de renglones en una sola sentencia: sin traerlos a PHP no hay
        // forma de que se pierda uno en el medio.
        $pdo->prepare(
            'INSERT INTO comprobantesrenglones
                 (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
             SELECT :nuevo, orden, cantidad, articulo, detalle, iva, unitario, monto, estado
             FROM comprobantesrenglones
             WHERE comprobante = :viejo
             ORDER BY orden ASC, id ASC'
        )->execute([':nuevo' => $nuevo, ':viejo' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    $totales = comprobanteTotalizar($nuevo);

    json_ok(['id' => $nuevo, 'origen' => $id, 'totales' => $totales], 201);
}

/**
 * Enviar por correo el link del visor.
 *
 * Sale por el mismo canal Databox que usa Difusion (`lib/databox.php`), asi
 * que el correo llega con la identidad visual del resto del sistema. El legacy
 * mandaba lo mismo por `awsses` desde `dDatarocket`.
 *
 * `databoxCorreoEncolar()` NUNCA lanza: devuelve `ok => false`, y aca eso se
 * traduce a un 502 con el motivo, en vez de un "enviado" que no fue.
 */
function accionCorreo(): void
{
    $id  = comprobantePedido();
    $com = comprobanteCabecera($id);

    $correo = trim((string) ($com['correo'] ?? ''));
    if ($correo === '') {
        json_error('No hay correo registrado en el comprobante', 422);
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_error("El correo del comprobante no es valido: \"{$correo}\"", 422);
    }

    $tipo   = combo(COMBO_TALONARIO_TIPO)[trim((string) ($com['tipo'] ?? ''))] ?? 'Comprobante';
    $numero = comprobanteNumero($com['punto'] ?? null, $com['serie'] ?? null);
    $razon  = trim((string) ($com['razon'] ?? ''));
    $url    = VISOR_BASE . '/visor?uuid=' . rawurlencode(trim((string) $com['uuid']));

    $asunto = $numero !== null ? "{$tipo} {$numero}" : $tipo;
    $cuerpo = 'Hola ' . htmlspecialchars($razon !== '' ? $razon : 'cliente', ENT_QUOTES, 'UTF-8')
        . ', aquí tienes tu comprobante para descargar:<br><br>'
        . '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '" target="_blank" '
        . 'style="background-color:#C11313;color:#ffffff;padding:10px 20px;'
        . 'text-decoration:none;display:inline-block;border-radius:3px">Abrir</a>';

    $res = databoxCorreoEncolar([
        'destino'      => $correo,
        'destinatario' => $razon,
        'asunto'       => $asunto,
        'cuerpo'       => $cuerpo,
        'prioridad'    => 4,
    ]);

    if (!($res['ok'] ?? false)) {
        json_error('No se pudo encolar el correo: ' . ($res['error'] ?? 'error desconocido'), 502);
    }

    json_ok(['id' => $id, 'destino' => $correo, 'mensaje' => $res['id'] ?? null]);
}

/**
 * Registrar un pago contra el comprobante.
 *
 * Es el alta prellenada de `/pagos/editar?mod=nue&cpb=N` del legacy: fecha
 * ahora, `dominio` sacado del contrato del comprobante, `contrato` y
 * `comprobante` del propio comprobante, `monto` = total (editable) y estado
 * `Pendiente` -- imputarlo es otro paso, que vive en el modulo de pagos.
 *
 * NO cambia el estado del comprobante. En el legacy eso lo hacia `imputar`, y
 * a traves de `pagoRegistrar()`, que no se porto (ver la cabecera del archivo).
 */
function accionPago(): void
{
    $id  = comprobantePedido();
    $in  = readJson();
    $com = comprobanteCabecera($id);

    $estado = trim((string) $com['estado']);
    if ($estado !== ESTADO_PENDIENTE) {
        json_error(
            'Solo se registra un pago contra un comprobante Pendiente. Este esta en "' .
            (combo(COMBO_ESTADO)[$estado] ?? 'sin estado') . '".',
            409
        );
    }

    $medio = fkOpcional($in['medio'] ?? null, 'medios', 'El medio de pago');
    if ($medio === null) json_error('Elegi el medio de pago', 422);

    // Sin monto explicito se usa el total del comprobante, como el legacy
    // (`registrar()` con `$monto = -1`).
    $montoRaw = trim((string) ($in['monto'] ?? ''));
    $monto = $montoRaw === ''
        ? (float) ($com['total'] ?? 0)
        : decimalNoNegativo($montoRaw, 'El monto');
    if ($monto <= 0) json_error('El monto tiene que ser mayor a cero', 422);

    $operacion = textoLimitado($in['operacion'] ?? '', 100, 'El numero de operacion');

    // El dominio no cuelga del comprobante sino de su contrato. Sin contrato
    // queda NULL y no en 0: `pagos`.`dominio` es FK RESTRICT.
    $dominio  = null;
    $contrato = idOrNull($com['contrato']);
    if ($contrato !== null) {
        $stmt = db()->prepare('SELECT dominio FROM contratos WHERE id = :id');
        $stmt->execute([':id' => $contrato]);
        $dominio = idOrNull($stmt->fetchColumn());
    }

    $stmt = db()->prepare(
        "INSERT INTO pagos (fecha, dominio, contrato, comprobante, monto, medio, operacion, estado)
         VALUES (:fecha, :dominio, :contrato, :cpb, :monto, :medio, :operacion, '1')"
    );
    $stmt->execute([
        ':fecha'     => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ':dominio'   => $dominio,
        ':contrato'  => $contrato,
        ':cpb'       => $id,
        ':monto'     => $monto,
        ':medio'     => $medio,
        ':operacion' => $operacion,
    ]);

    json_ok(['id' => (int) db()->lastInsertId(), 'comprobante' => $id, 'monto' => $monto], 201);
}
