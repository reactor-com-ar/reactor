<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/comprobantes_lib.php';

/**
 * Listado, ficha y ABM de `comprobantes` (db/schema.sql).
 *
 * Un comprobante es la cabecera de un documento comercial —factura,
 * prefactura, recibo, remito...— y sus renglones viven en
 * `comprobantesrenglones` (FK CASCADE). El TIPO, el SUBTIPO, el PUNTO DE VENTA
 * y la EMPRESA no son columnas suyas: salen del `talonario`, que es lo que le
 * da la numeracion. Por eso el listado hace el JOIN y no hay un `tipo` propio
 * que mantener sincronizado.
 *
 * QUE SE PUEDE ESCRIBIR, Y POR QUE TAN POCO. Este NO es un ABM de "todos los
 * campos" como Contratos o Talonarios, y la diferencia es deliberada -- es la
 * misma que impone el back office viejo:
 *
 *   - El ALTA pide UNICAMENTE el talonario y deja el comprobante en
 *     `Preparacion` (`nuevo.php` del legacy hace exactamente eso). El resto se
 *     completa despues, con la ficha ya abierta.
 *   - La EDICION solo existe mientras el comprobante esta en `Preparacion`, y
 *     solo sobre los doce campos del cliente, las fechas y las notas. Un
 *     comprobante Pendiente o Cancelado ya se emitio: cambiarle la razon social
 *     o la fecha de emision seria reescribir un documento entregado.
 *   - `subtotal` / `iva` / `total` NO se escriben nunca a mano: los recalcula
 *     `comprobanteTotalizar()` a partir de los renglones, igual que
 *     `cComprobante::totalizar()`. Un total escrito a dedo se desincroniza de
 *     sus propios renglones y no hay forma de notarlo desde la pantalla.
 *   - `serie` la asigna `autorizar` tomando el proximo numero del talonario, y
 *     `caenro` / `caevto` / `caeres` vienen de AFIP. Pisar la serie a mano es
 *     emitir dos comprobantes con el mismo numero fiscal.
 *   - `uuid` es la credencial del visor publico
 *     (reactor.com.ar/comprobante/visor?uuid=...) y se genera en el alta.
 *
 * Todo eso SI se muestra en la ficha: la restriccion es de escritura, no de
 * lectura. Lo que no se puede editar se explica en la pantalla en vez de
 * aparecer deshabilitado sin motivo.
 *
 * BORRAR. `comprobantesrenglones` es CASCADE y se va con la cabecera -- el
 * endpoint igual los borra explicitamente y en una transaccion, para poder
 * informar cuantos fueron. `pagos`.`comprobante` es RESTRICT y BLOQUEA: un
 * comprobante con pagos registrados no se borra.
 */

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if     (isset($_GET['detalle'])) handleDetalle();
            elseif (isset($_GET['impacto'])) handleImpacto();
            else                             handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar comprobantes: ' . $e->getMessage(), 500);
}

/**
 * Listado.
 *
 * A diferencia de Contratos (50 filas) y Talonarios (18), `comprobantes` tiene
 * 2.326 y crece con cada facturacion: el listado NO se trae entero para
 * filtrar en el front. Los filtros de columna y el limite van al SQL; la
 * busqueda rapida sigue siendo client-side sobre la ventana traida, igual que
 * en Registros y Señales.
 *
 * Ojo con el orden de las dos cosas: por eso el modal de Filtros manda todo al
 * backend y el buscador de la toolbar aclara que busca "en los resultados".
 */
function handleList(): void
{
    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    $where  = [];
    $params = [];

    $porId = static function (string $get, string $col) use (&$where, &$params): void {
        $v = isset($_GET[$get]) ? (int) $_GET[$get] : 0;
        if ($v > 0) {
            $where[]              = "c.{$col} = :{$get}";
            $params[":{$get}"]    = $v;
        }
    };
    $porId('id',        'id');
    $porId('talonario', 'talonario');
    $porId('contrato',  'contrato');
    $porId('cliente',   'cliente');
    $porId('medio',     'medio');

    // `estado` es varchar(1) y '0' es un valor real (Anulado), asi que el
    // filtro se compara contra '' y no con empty(): con empty() no se podrian
    // pedir los anulados, que son 149.
    $estado = isset($_GET['estado']) ? trim((string) $_GET['estado']) : '';
    if ($estado !== '') {
        $where[]           = 'c.estado = :estado';
        $params[':estado'] = $estado;
    }

    // El tipo y la empresa no son columnas del comprobante: se filtran sobre
    // el talonario que le da la numeracion.
    $tipo = isset($_GET['tipo']) ? trim((string) $_GET['tipo']) : '';
    if ($tipo !== '') {
        $where[]         = 't.tipo = :tipo';
        $params[':tipo'] = $tipo;
    }
    $empresa = isset($_GET['empresa']) ? (int) $_GET['empresa'] : 0;
    if ($empresa > 0) {
        $where[]            = 't.empresa = :empresa';
        $params[':empresa'] = $empresa;
    }

    $razon = isset($_GET['razon']) ? trim((string) $_GET['razon']) : '';
    if ($razon !== '') {
        $where[]          = 'c.razon LIKE :razon';
        $params[':razon'] = '%' . $razon . '%';
    }

    foreach ([['emisionDesde', 'emision', '>='], ['emisionHasta', 'emision', '<='],
              ['vtoDesde', 'vencimiento', '>='], ['vtoHasta', 'vencimiento', '<=']] as [$get, $col, $op]) {
        $v = isset($_GET[$get]) ? trim((string) $_GET[$get]) : '';
        if ($v !== '') {
            $where[]        = "c.{$col} {$op} :{$get}";
            $params[":{$get}"] = $v;
        }
    }

    $sqlWhere = $where ? (' WHERE ' . implode(' AND ', $where)) : '';

    $stmt = db()->prepare(
        'SELECT ' . COMPROBANTE_COLUMNAS . '
         FROM comprobantes c
         LEFT JOIN talonarios t  ON t.id  = c.talonario
         LEFT JOIN empresas   em ON em.id = t.empresa
         LEFT JOIN clientes   cl ON cl.id = c.cliente
         LEFT JOIN medios     me ON me.id = c.medio'
        . $sqlWhere .
        ' ORDER BY c.id DESC LIMIT ' . $limit
    );
    $stmt->execute($params);

    $comprobantes = array_map('comprobanteSalida', $stmt->fetchAll());

    // Totales de LA CONSULTA, no de la ventana: el pie del listado legacy
    // suma lo que trajo el LIMIT, y con 100 de 2.326 ese numero no significa
    // nada. Se cuenta con el mismo WHERE y sin el limite.
    $tot = db()->prepare(
        'SELECT COUNT(*) AS filas, COALESCE(SUM(c.total), 0) AS total
         FROM comprobantes c
         LEFT JOIN talonarios t ON t.id = c.talonario'
        . $sqlWhere
    );
    $tot->execute($params);
    $totales = $tot->fetch() ?: ['filas' => 0, 'total' => 0];

    json_ok([
        'resumen'      => resumen(),
        // Cuantos matchean el filtro y cuanto suman, contra cuantos se
        // trajeron: el front avisa si la ventana recorto.
        'consulta'     => [
            'filas'    => (int) $totales['filas'],
            'total'    => (float) $totales['total'],
            'traidos'  => count($comprobantes),
            'limite'   => $limit,
        ],
        'comprobantes' => $comprobantes,
        'catalogos'    => catalogos(),
    ]);
}

/** KPIs de la cabecera: son del universo entero, no de la consulta. */
function resumen(): array
{
    $r = db()->query(
        "SELECT COUNT(*) AS total,
                SUM(estado = '1') AS preparacion,
                SUM(estado = '2') AS pendientes,
                SUM(estado = '3') AS cancelados,
                SUM(estado = '0') AS anulados,
                COALESCE(SUM(CASE WHEN estado = '2' THEN total ELSE 0 END), 0) AS monto_pendiente
         FROM comprobantes"
    )->fetch() ?: [];

    return [
        'total'           => (int)   ($r['total']       ?? 0),
        'preparacion'     => (int)   ($r['preparacion'] ?? 0),
        'pendientes'      => (int)   ($r['pendientes']  ?? 0),
        'cancelados'      => (int)   ($r['cancelados']  ?? 0),
        'anulados'        => (int)   ($r['anulados']    ?? 0),
        'monto_pendiente' => (float) ($r['monto_pendiente'] ?? 0),
    ];
}

/**
 * Ficha completa: la cabecera, sus renglones y los pagos que tiene registrados.
 *
 * Los renglones NO viajan en el listado -- son 6.694 filas para 2.320
 * comprobantes y solo se miran de a uno. Se piden al abrir la ficha.
 */
function handleDetalle(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(
        'SELECT ' . COMPROBANTE_COLUMNAS . '
         FROM comprobantes c
         LEFT JOIN talonarios t  ON t.id  = c.talonario
         LEFT JOIN empresas   em ON em.id = t.empresa
         LEFT JOIN clientes   cl ON cl.id = c.cliente
         LEFT JOIN medios     me ON me.id = c.medio
         WHERE c.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Comprobante no encontrado', 404);

    json_ok([
        'comprobante' => comprobanteSalida($fila),
        'renglones'   => comprobanteRenglones($id),
        'pagos'       => comprobantePagos($id),
    ]);
}

/** Pagos registrados contra el comprobante. */
function comprobantePagos(int $id): array
{
    $stmt = db()->prepare(
        'SELECT p.id, p.fecha, p.dominio, p.contrato, p.monto, p.medio,
                p.operacion, p.estado,
                me.nombre  AS medio_nombre,
                dom.nombre AS dominio_nombre
         FROM pagos p
         LEFT JOIN medios   me  ON me.id  = p.medio
         LEFT JOIN dominios dom ON dom.id = p.dominio
         WHERE p.comprobante = :id
         ORDER BY p.id DESC'
    );
    $stmt->execute([':id' => $id]);

    return array_map(static function (array $r): array {
        $estado = trim((string) ($r['estado'] ?? ''));
        return [
            'id'             => (int) $r['id'],
            'fecha'          => $r['fecha'],
            'dominio'        => idOrNull($r['dominio']),
            'dominio_nombre' => trim((string) ($r['dominio_nombre'] ?? '')),
            'contrato'       => idOrNull($r['contrato']),
            'monto'          => $r['monto'] === null ? null : (float) $r['monto'],
            'medio'          => idOrNull($r['medio']),
            'medio_nombre'   => trim((string) ($r['medio_nombre'] ?? '')),
            'operacion'      => trim((string) ($r['operacion'] ?? '')),
            'estado'         => $estado,
            'estado_texto'   => combo(COMBO_PAGO_ESTADO)[$estado] ?? '',
        ];
    }, $stmt->fetchAll());
}

/** Catalogos de los desplegables de Filtros, Alta y las acciones. */
function catalogos(): array
{
    $talonarios = array_map(static fn(array $r): array => [
        'id'         => (int) $r['id'],
        'nombre'     => trim((string) ($r['nombre'] ?? '')),
        'tipo'       => trim((string) ($r['tipo']   ?? '')),
        'fiscal'     => trim((string) ($r['fiscal'] ?? '')),
        'estado'     => (int) $r['estado'] === 1 ? 1 : 0,
    ], db()->query(
        'SELECT id, nombre, tipo, fiscal, estado FROM talonarios ORDER BY estado DESC, nombre ASC'
    )->fetchAll());

    $empresas = array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => trim((string) ($r['nombre'] ?? '')),
    ], db()->query('SELECT id, nombre FROM empresas ORDER BY nombre ASC')->fetchAll());

    // Solo los medios habilitados para el alta de un pago, pero TODOS para el
    // filtro: hay comprobantes viejos con medios que ya no se usan y el
    // listado tiene que poder acotarse por ellos igual.
    $medios = array_map(static fn(array $r): array => [
        'id'         => (int) $r['id'],
        'nombre'     => trim((string) ($r['nombre'] ?? '')),
        'habilitado' => (int) $r['estado'] === 1 ? 1 : 0,
    ], db()->query('SELECT id, nombre, estado FROM medios ORDER BY estado DESC, nombre ASC')->fetchAll());

    return [
        'talonarios' => $talonarios,
        'empresas'   => $empresas,
        'medios'     => $medios,
        'tipos'      => comboLista(COMBO_TALONARIO_TIPO),
        'estados'    => comboLista(COMBO_ESTADO),
        'condiciones'=> comboLista(COMBO_CONDICION),
        'ivas'       => comboLista(COMBO_RENGLON_IVA),
        // El visor publico del comprobante vive fuera de cloud; la base la
        // arma el front para los tres links de Imprimir.
        'visor_base' => VISOR_BASE,
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * `pagos` BLOQUEA (FK RESTRICT y ademas es plata cobrada);
 * `comprobantesrenglones` se elimina con la cabecera (FK CASCADE).
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $cab = db()->prepare(
        'SELECT c.id, c.uuid, c.razon, c.total, c.estado, c.serie, t.punto, t.nombre AS talonario_nombre
         FROM comprobantes c
         LEFT JOIN talonarios t ON t.id = c.talonario
         WHERE c.id = :id'
    );
    $cab->execute([':id' => $id]);
    $com = $cab->fetch();
    if (!$com) json_error('Comprobante no encontrado', 404);

    $deps = comprobanteDependencias($id);

    $bloqueos = [];
    if ($deps['pagos'] > 0) {
        $bloqueos[] = ['label' => 'Pagos registrados contra este comprobante', 'cantidad' => $deps['pagos']];
    }

    $elimina = [];
    if ($deps['renglones'] > 0) {
        $elimina[] = ['label' => 'Renglones del comprobante', 'cantidad' => $deps['renglones']];
    }

    json_ok([
        'comprobante' => [
            'id'     => (int) $com['id'],
            'numero' => comprobanteNumero($com['punto'] ?? null, $com['serie'] ?? null),
            'razon'  => trim((string) ($com['razon'] ?? '')),
            'total'  => $com['total'] === null ? null : (float) $com['total'],
            'estado' => trim((string) ($com['estado'] ?? '')),
        ],
        'bloqueos'   => $bloqueos,
        'elimina'    => $elimina,
        // Nada queda sin la referencia: las dos dependencias o bloquean o se
        // van con la cabecera. La clave viaja porque el modal la lee.
        'desvincula' => [],
    ]);
}

/**
 * Alta. Pide UNICAMENTE el talonario, como `nuevo.php` del legacy.
 *
 * El resto de la cabecera nace con los defaults de `cComprobante::nuevo()`:
 * emision hoy, vencimiento a 10 dias, estado `Preparacion` y serie 0 -- la
 * serie la asigna recien `autorizar`, asi que un borrador no consume numero.
 */
function handleCreate(): void
{
    $in        = readJson();
    $talonario = fkOpcional($in['talonario'] ?? null, 'talonarios', 'El talonario');
    if ($talonario === null) json_error('Elegi un talonario', 422);

    $hoy = new DateTimeImmutable('today');

    $stmt = db()->prepare(
        "INSERT INTO comprobantes
             (uuid, talonario, serie, caenro, caevto, caeres, emision, vencimiento,
              contrato, cliente, razon, condicion, cuit, domicilio, correo, celular,
              subtotal, iva, total, cotizacion, observaciones, comentarios, medio, estado)
         VALUES
             (:uuid, :talonario, 0, '', '', '', :emision, :vencimiento,
              NULL, NULL, '', '', '', '', '', '',
              0, 0, 0, 0, '', '', NULL, '1')"
    );
    $stmt->execute([
        ':uuid'        => comprobanteUuidLibre(),
        ':talonario'   => $talonario,
        ':emision'     => $hoy->format('Y-m-d'),
        ':vencimiento' => $hoy->modify('+10 days')->format('Y-m-d'),
    ]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

/**
 * Edicion de la cabecera: los doce campos del legacy y ni uno mas.
 *
 * Solo en `Preparacion` -- ver la cabecera del archivo. El chequeo se hace
 * contra la base y no contra lo que mande el front: la UI esconde el boton,
 * pero esconder el boton no es el control de acceso.
 */
function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT estado FROM comprobantes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Comprobante no encontrado', 404);

    if (trim((string) $fila['estado']) !== ESTADO_PREPARACION) {
        json_error(
            'Solo se puede editar un comprobante en Preparacion. Este esta en "' .
            (combo(COMBO_ESTADO)[trim((string) $fila['estado'])] ?? 'sin estado') . '".',
            409
        );
    }

    $cliente = fkOpcional($in['cliente'] ?? null, 'clientes', 'El cliente');

    $razon     = textoLimitado($in['razon']     ?? '', 250,  'La razon social');
    $domicilio = textoLimitado($in['domicilio'] ?? '', 250,  'El domicilio');
    $cuit      = textoLimitado($in['cuit']      ?? '', 50,   'El CUIT');
    $celular   = textoLimitado($in['celular']   ?? '', 100,  'El celular');
    $obs       = textoLimitado($in['observaciones'] ?? '', 2000, 'Las observaciones');
    $com       = textoLimitado($in['comentarios']   ?? '', 2000, 'Los comentarios');

    $correo = trim((string) ($in['correo'] ?? ''));
    if ($correo !== '') {
        if (mb_strlen($correo) > 100)                    json_error('El correo no puede superar 100 caracteres', 422);
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) json_error('El correo no es valido', 422);
    }

    $condicion = trim((string) ($in['condicion'] ?? ''));
    if ($condicion !== '') {
        if (mb_strlen($condicion) > 2) json_error('La condicion fiscal no puede superar 2 caracteres', 422);
        $cat = combo(COMBO_CONDICION);
        if ($cat !== [] && !isset($cat[$condicion])) {
            json_error("La condicion fiscal \"{$condicion}\" no esta en el catalogo", 422);
        }
    }

    $emision     = fechaOpcional($in['emision']     ?? null, 'emision');
    $vencimiento = fechaOpcional($in['vencimiento'] ?? null, 'vencimiento');
    if ($emision !== null && $vencimiento !== null && $vencimiento < $emision) {
        json_error('El vencimiento no puede ser anterior a la emision', 422);
    }

    $cotizacion = decimalNoNegativo($in['cotizacion'] ?? null, 'La cotizacion');

    $upd = db()->prepare(
        'UPDATE comprobantes
            SET cliente       = :cliente,
                razon         = :razon,
                domicilio     = :domicilio,
                correo        = :correo,
                celular       = :celular,
                condicion     = :condicion,
                cuit          = :cuit,
                emision       = :emision,
                vencimiento   = :vencimiento,
                cotizacion    = :cotizacion,
                observaciones = :observaciones,
                comentarios   = :comentarios
          WHERE id = :id'
    );
    $upd->execute([
        ':cliente'       => $cliente,
        ':razon'         => $razon,
        ':domicilio'     => $domicilio,
        ':correo'        => $correo,
        ':celular'       => $celular,
        ':condicion'     => $condicion,
        ':cuit'          => $cuit,
        ':emision'       => $emision,
        ':vencimiento'   => $vencimiento,
        ':cotizacion'    => $cotizacion,
        ':observaciones' => $obs,
        ':comentarios'   => $com,
        ':id'            => $id,
    ]);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $pdo = db();

    $existe = $pdo->prepare('SELECT 1 FROM comprobantes WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Comprobante no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite la validacion.
    $deps = comprobanteDependencias($id);
    if ($deps['pagos'] > 0) {
        json_error(
            "No se puede eliminar: el comprobante tiene {$deps['pagos']} pago(s) registrado(s). " .
            'Anulalos primero desde la ficha.',
            409
        );
    }

    try {
        $pdo->beginTransaction();

        // La FK es CASCADE, pero se borra explicito para poder informar
        // cuantos renglones se fueron -- y para no depender del motor, que en
        // prod es MariaDB y en dev MySQL.
        $ren = $pdo->prepare('DELETE FROM comprobantesrenglones WHERE comprobante = :id');
        $ren->execute([':id' => $id]);
        $renglones = $ren->rowCount();

        $pdo->prepare('DELETE FROM comprobantes WHERE id = :id')->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id, 'renglones' => $renglones]);
}
