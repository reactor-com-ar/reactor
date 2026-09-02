<?php

declare(strict_types=1);

/**
 * Comprobantes del contrato del dominio: facturas y recibos.
 *
 *   GET api/comprobantes.php?tipo=F        -> listado de facturas + resumen
 *   GET api/comprobantes.php?tipo=R        -> listado de recibos + resumen
 *   GET api/comprobantes.php?tipo=F&id=N   -> un comprobante + sus renglones
 *
 * MODULO DE SOLO LECTURA. Portado de reactor-panel/comprobantes/listar.php
 * (+ consultar.php para el detalle): el panel del cliente mira lo que se le
 * emitio, no lo edita. Cualquier otro metodo corta con 405.
 *
 * ALCANCE: el legacy acotaba por `contrato` (el del dominio de la sesion), no
 * por cliente -- un cliente puede tener varios dominios y cada uno se factura
 * por su contrato. Se replica: `dominios.contrato` del dominio de la sesion
 * (requireDominioId()), incluido el lookup por id, para que nadie lea un
 * comprobante de otro dominio pasando un id a mano. Si el dominio no tiene
 * contrato el endpoint corta con 409, como el mensaje "El dominio no tiene
 * contrato asignado" del legacy (hoy 116 de 148 dominios estan en ese caso).
 *
 * TIPO: el legacy hardcodeaba `talonario = 48` (facturas) y `talonario = 49`
 * (recibos) -- los talonarios de Alfatec. Eso deja sin ver sus comprobantes a
 * los dominios facturados con los talonarios de Wescom (38 y 43), que existen
 * y estan asociados a contratos vivos. Aca se filtra por `talonarios.tipo`:
 *   F -> 'F' (prefactura) + 'T' (factura fiscal)
 *   R -> 'R' (recibo)
 * El nombre del tipo sale de `combos` ('$xTalonario->tipo') y, como en el
 * legacy, "Prefactura" se muestra como "Factura": para el cliente es su
 * factura, la distincion es interna.
 *
 * ESTADO: se devuelven SIEMPRE solo los comprobantes en estado 2 (Pendiente)
 * y 3 (Cancelado) -- misma regla dura que el legacy ("estado=2 or estado=3").
 * Los borradores (1, Preparacion) y los anulados (0) no se le muestran al
 * cliente. El filtro por estado de la UI elige dentro de esos dos.
 *
 * ESCALA (medido en reactor_dev, 2026-09-01): 2.215 comprobantes vivos de los
 * talonarios en juego, con indice por `contrato`. No hace falta la ventana
 * por id que si necesitan `registros` y `senales`.
 */

require __DIR__ . '/bootstrap.php';

const ORDEN_VALIDO = ['id', 'emision', 'vencimiento', 'total', 'serie'];
const MAX_LIMITE   = 1000;
const MAX_RENGLONES = 200;

/** Tipos de talonario que entran en cada solapa del panel. */
const TIPOS = [
    'F' => ['F', 'T'],
    'R' => ['R'],
];

/** Estados que el cliente puede ver (2 Pendiente, 3 Cancelado). */
const ESTADOS_VISIBLES = ['2', '3'];

/** Claves de `combos` con los textos de tipo de talonario y estado. */
const COMBO_TIPO   = '$xTalonario->tipo';
const COMBO_ESTADO = '$xComprobante->estado';

/** Ultimo recurso si `combos` no tiene cargadas las claves de arriba. */
const TIPOS_FALLBACK   = ['F' => 'Factura', 'T' => 'Factura', 'R' => 'Recibo'];
const ESTADOS_FALLBACK = [
    ['valor' => '2', 'texto' => 'Pendiente'],
    ['valor' => '3', 'texto' => 'Cancelado'],
];

/**
 * Visor publico de comprobantes. Hoy lo sirve el sitio legacy: es el unico
 * lugar donde vive el PDF, este repo todavia no tiene su propio visor. Cuando
 * exista, se cambia aca y nada mas.
 */
const VISOR_BASE = 'https://www.reactor.com.ar/comprobante/';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido: los comprobantes son de solo lectura', 405);
    }
    $tipo = tipoPedido();
    isset($_GET['id']) ? handleGet($tipo, (int) $_GET['id']) : handleList($tipo);
} catch (Throwable $e) {
    json_error('Error al procesar los comprobantes: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(string $tipo): void
{
    $contrato = requireContratoId();

    $q      = trim((string) ($_GET['q']      ?? ''));
    $codigo = (int)         ($_GET['codigo'] ?? 0);
    $numero = trim((string) ($_GET['numero'] ?? ''));
    $estado = trim((string) ($_GET['estado'] ?? ''));
    $desde  = fechaFiltro($_GET['desde'] ?? '');
    $hasta  = fechaFiltro($_GET['hasta'] ?? '');
    $limite = (int)         ($_GET['limite'] ?? 100);
    $orden  = (string)      ($_GET['orden']  ?? 'id');
    $dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)         $limite = 100;
    if ($limite > MAX_LIMITE) $limite = MAX_LIMITE;
    if (!in_array($orden, ORDEN_VALIDO, true)) $orden = 'id';
    if (!in_array($estado, ESTADOS_VISIBLES, true)) $estado = '';

    [$tiposSql, $tiposParams] = tiposEnSql($tipo);

    $where  = ['c.contrato = :con', 't.tipo IN (' . $tiposSql . ')',
               "c.estado IN ('" . implode("','", ESTADOS_VISIBLES) . "')"];
    $params = array_merge([':con' => $contrato], $tiposParams);

    if ($codigo > 0) {
        $where[]        = 'c.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($numero !== '') {
        // Se busca por el numero tal cual se muestra (con o sin ceros a la
        // izquierda): 3340 y 003340 encuentran la misma serie.
        $where[]        = 'c.serie = :ser';
        $params[':ser'] = (int) ltrim($numero, '0');
    }
    if ($estado !== '') {
        $where[]        = 'c.estado = :est';
        $params[':est'] = $estado;
    }
    if ($desde !== null) {
        $where[]          = 'c.emision >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta !== null) {
        $where[]          = 'c.emision <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($q !== '') {
        // Un placeholder por columna: con EMULATE_PREPARES=false (lib/db.php)
        // PDO no admite repetir el mismo nombre en un statement (HY093).
        $ors = [];
        foreach (['c.razon', 'c.cuit', 'c.caenro', 'c.uuid'] as $n => $columna) {
            $ors[]             = $columna . ' LIKE :q' . $n;
            $params[':q' . $n] = '%' . $q . '%';
        }
        if (ctype_digit($q)) {
            $ors[]           = 'c.serie = :qser';
            $params[':qser'] = (int) $q;
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    $sql = 'SELECT ' . columnas() . '
            FROM comprobantes c
            JOIN talonarios t ON t.id = c.talonario
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $filas = array_map('mapComprobante', $stmt->fetchAll());

    json_ok([
        'tipo'         => $tipo,
        'comprobantes' => $filas,
        'estados'      => estados()['opciones'],
        'resumen'      => resumen($contrato, $tipo, count($filas)),
    ]);
}

function handleGet(string $tipo, int $id): void
{
    $contrato = requireContratoId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    [$tiposSql, $tiposParams] = tiposEnSql($tipo);

    // Se dejan afuera a proposito los campos internos: `comentarios` (notas
    // de administracion), `cotizacion` (dolar del mes) y `talonarios.nombre`
    // (identifica la empresa emisora y el talonario). Nada de eso va impreso
    // en el comprobante que recibe el cliente. `observaciones` si va.
    $sql = 'SELECT ' . columnas() . ', c.condicion, c.domicilio, c.correo, c.celular,
                   c.observaciones, c.caevto
            FROM comprobantes c
            JOIN talonarios t ON t.id = c.talonario
            WHERE c.id = :id AND c.contrato = :con
              AND t.tipo IN (' . $tiposSql . ')
              AND c.estado IN (\'' . implode("','", ESTADOS_VISIBLES) . '\')
            LIMIT 1';

    $stmt = db()->prepare($sql);
    $stmt->execute(array_merge([':id' => $id, ':con' => $contrato], $tiposParams));
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Comprobante no encontrado para este dominio', 404);
    }

    $comprobante = mapComprobante($row);

    // Campos que solo trae el detalle (modal de Consulta).
    $texto = static fn(string $k): string => trim((string) ($row[$k] ?? ''));
    $comprobante += [
        'condicion'       => $texto('condicion'),
        'condicion_texto' => condiciones()[$texto('condicion')] ?? '',
        'domicilio'       => $texto('domicilio'),
        'correo'          => $texto('correo'),
        'celular'         => $texto('celular'),
        'observaciones'   => $texto('observaciones'),
        'caevto'          => $texto('caevto'),
    ];

    json_ok([
        'comprobante' => $comprobante,
        'renglones'   => renglones($id),
    ]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Solapa pedida: 'F' (facturas) o 'R' (recibos). */
function tipoPedido(): string
{
    $tipo = strtoupper(trim((string) ($_GET['tipo'] ?? '')));
    if (!array_key_exists($tipo, TIPOS)) {
        json_error('Tipo de comprobante invalido: se espera F (facturas) o R (recibos)', 422);
    }
    return $tipo;
}

/** Lista de placeholders para el IN de tipos de talonario. */
function tiposEnSql(string $tipo): array
{
    $sql    = [];
    $params = [];
    foreach (TIPOS[$tipo] as $i => $t) {
        $sql[]                = ':t' . $i;
        $params[':t' . $i]    = $t;
    }
    return [implode(', ', $sql), $params];
}

/** Columnas comunes del listado y del detalle. */
function columnas(): string
{
    return 'c.id, c.uuid, c.serie, c.emision, c.vencimiento, c.razon, c.cuit,
            c.subtotal, c.iva, c.total, c.estado, c.caenro,
            t.tipo AS talonario_tipo, t.subtipo AS talonario_subtipo,
            t.punto AS talonario_punto, t.fiscal AS talonario_fiscal';
}

/**
 * Contrato con el que se filtran los comprobantes. Corta el request si el
 * dominio de la sesion no tiene uno asignado.
 */
function requireContratoId(): int
{
    $dominio = requireDominioId();

    $stmt = db()->prepare('SELECT contrato FROM dominios WHERE id = :dom LIMIT 1');
    $stmt->execute([':dom' => $dominio]);
    $contrato = (int) ($stmt->fetchColumn() ?: 0);

    if ($contrato <= 0) {
        json_error('El dominio todavia no tiene un contrato asignado, asi que no hay comprobantes para mostrar.', 409);
    }
    return $contrato;
}

/** Textos de un combo del legacy, como tabla plana valor -> texto. */
function comboTextos(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }

    $stmt = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, texto ASC');
    $stmt->execute([':c' => $clave]);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor !== '') {
            $out[$valor] = (string) ($r['texto'] ?? '');
        }
    }
    return $cache[$clave] = $out;
}

/**
 * Nombre del tipo de talonario. Como en el legacy, "Prefactura" se muestra
 * "Factura": el cliente recibe una factura, el matiz es interno.
 */
function tiposTexto(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $textos = comboTextos(COMBO_TIPO) ?: TIPOS_FALLBACK;
    foreach ($textos as $k => $v) {
        $textos[$k] = str_replace('Prefactura', 'Factura', $v);
    }
    return $cache = $textos;
}

/** Opciones del filtro de estado + tabla plana codigo -> texto. */
function estados(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $textos   = comboTextos(COMBO_ESTADO);
    $opciones = [];
    foreach (ESTADOS_VISIBLES as $valor) {
        if (isset($textos[$valor])) {
            $opciones[] = ['valor' => $valor, 'texto' => $textos[$valor]];
        }
    }
    if ($opciones === []) {
        $opciones = ESTADOS_FALLBACK;
    }

    $planos = [];
    foreach ($opciones as $o) {
        $planos[$o['valor']] = $o['texto'];
    }

    return $cache = ['opciones' => $opciones, 'textos' => $planos];
}

/** Textos de la condicion fiscal que quedo congelada en el comprobante. */
function condiciones(): array
{
    return comboTextos('$xComprobante->condicion');
}

/** Contadores e importes del contrato completo, no de la pagina devuelta. */
function resumen(int $contrato, string $tipo, int $mostrados): array
{
    [$tiposSql, $tiposParams] = tiposEnSql($tipo);

    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN c.estado = '2' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN c.estado = '3' THEN 1 ELSE 0 END) AS cancelados,
                SUM(CASE WHEN c.estado = '2' THEN c.total ELSE 0 END) AS importe_pendiente,
                SUM(c.total) AS importe_total
         FROM comprobantes c
         JOIN talonarios t ON t.id = c.talonario
         WHERE c.contrato = :con AND t.tipo IN (" . $tiposSql . ")
           AND c.estado IN ('" . implode("','", ESTADOS_VISIBLES) . "')"
    );
    $stmt->execute(array_merge([':con' => $contrato], $tiposParams));
    $r = $stmt->fetch() ?: [];

    return [
        'total'             => (int)   ($r['total']             ?? 0),
        'pendientes'        => (int)   ($r['pendientes']        ?? 0),
        'cancelados'        => (int)   ($r['cancelados']        ?? 0),
        'importe_pendiente' => (float) ($r['importe_pendiente'] ?? 0),
        'importe_total'     => (float) ($r['importe_total']     ?? 0),
        'mostrados'         => $mostrados,
    ];
}

/** Renglones del comprobante, en el orden en que se imprimen. */
function renglones(int $comprobante): array
{
    $stmt = db()->prepare(
        'SELECT id, orden, cantidad, detalle, iva, unitario, monto
         FROM comprobantesrenglones
         WHERE comprobante = :cpb
         ORDER BY orden ASC, id ASC
         LIMIT ' . MAX_RENGLONES
    );
    $stmt->execute([':cpb' => $comprobante]);

    return array_map(static fn(array $r): array => [
        'id'       => (int) $r['id'],
        'cantidad' => $r['cantidad'] !== null ? (float) $r['cantidad'] : null,
        'detalle'  => trim((string) ($r['detalle'] ?? '')),
        'iva'      => $r['iva']      !== null ? (float) $r['iva']      : null,
        'unitario' => $r['unitario'] !== null ? (float) $r['unitario'] : null,
        'monto'    => $r['monto']    !== null ? (float) $r['monto']    : null,
    ], $stmt->fetchAll());
}

/** Normaliza una fila de `comprobantes` (ya joineada con `talonarios`). */
function mapComprobante(array $r): array
{
    $texto  = static fn(string $k): string => trim((string) ($r[$k] ?? ''));
    $estado = $texto('estado');
    $uuid   = $texto('uuid');

    $tipo    = $texto('talonario_tipo');
    $subtipo = $texto('talonario_subtipo');
    $punto   = str_pad((string) (int) ($r['talonario_punto'] ?? 0), 3, '0', STR_PAD_LEFT);
    $serie   = str_pad((string) (int) ($r['serie'] ?? 0),           6, '0', STR_PAD_LEFT);
    $nombre  = tiposTexto()[$tipo] ?? $tipo;

    return [
        'id'           => (int) $r['id'],
        'uuid'         => $uuid,
        'numero'       => trim($nombre . ' ' . $punto . '-' . $serie),
        // Con el subtipo (A / B / X): es lo que muestra el encabezado del PDF.
        'numero_largo' => trim($nombre . ' ' . $subtipo . ' ' . $punto . '-' . $serie),
        'serie'        => (int) ($r['serie'] ?? 0),
        'punto'        => $punto,
        'tipo'         => $tipo,
        'tipo_texto'   => $nombre,
        'subtipo'      => $subtipo,
        'fiscal'       => trim((string) ($r['talonario_fiscal'] ?? '')) === '1',
        'emision'      => fechaSalida($r['emision']     ?? null),
        'vencimiento'  => fechaSalida($r['vencimiento'] ?? null),
        'razon'        => $texto('razon'),
        'cuit'         => $texto('cuit'),
        'subtotal'     => $r['subtotal'] !== null ? (float) $r['subtotal'] : null,
        'iva'          => $r['iva']      !== null ? (float) $r['iva']      : null,
        'total'        => $r['total']    !== null ? (float) $r['total']    : null,
        'estado'       => $estado,
        'estado_texto' => estados()['textos'][$estado] ?? '',
        'caenro'       => $texto('caenro'),
        'enlaces'      => $uuid === '' ? null : [
            'compartir'  => VISOR_BASE . 'visor?uuid='     . rawurlencode($uuid),
            'abrir'      => VISOR_BASE . 'abrir?uuid='     . rawurlencode($uuid),
            'descargar'  => VISOR_BASE . 'descargar?uuid=' . rawurlencode($uuid),
        ],
    ];
}

/**
 * Fecha lista para el front. El legacy usaba 1500-01-01 (y MySQL viejo
 * 0000-00-00) como "sin fecha": ambos salen como vacio.
 */
function fechaSalida(?string $valor): string
{
    $s = trim((string) $valor);
    if ($s === '' || !preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m)) {
        return '';
    }
    return (int) $m[1] < 1900 ? '' : $s;
}

/** Fecha del filtro de rango ('YYYY-MM-DD', input date). Vacio => null. */
function fechaFiltro(mixed $valor): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m)) {
        json_error('La fecha del filtro no es valida', 422);
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error('La fecha del filtro no existe en el calendario', 422);
    }
    return $valor;
}
