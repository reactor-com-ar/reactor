<?php

declare(strict_types=1);

/**
 * ABM de `chips` (esquema real en db/schema.sql).
 *
 *   GET    api/chips.php            -> listado + resumen + combos + articulos
 *   GET    api/chips.php?id=N       -> un registro (todos los campos visibles)
 *   POST   api/chips.php            -> alta
 *   PUT    api/chips.php            -> modificacion
 *   DELETE api/chips.php?id=N       -> baja
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()). Ningun
 * query corre sin ese filtro, ni siquiera el lookup por id.
 *
 * COMBOS: `compania`, `plan`, `responsable` y `pais` se guardan como codigos
 * cortos (una o dos letras). El texto sale de la tabla `combos`, con la clave
 * '$xChip-><campo>' — la misma convencion que usaba comboTraducir() en el
 * legacy. Los valores validos para escribir salen de ahi, no de una lista
 * hardcodeada: si se agrega una compania nueva a `combos`, el ABM la toma.
 *
 * FECHAS: el legacy escribia 1500-01-01 como "sin fecha". Se normaliza a
 * vacio al salir y a NULL al entrar, para no arrastrar el centinela.
 */

require __DIR__ . '/bootstrap.php';

const ORDEN_VALIDO = ['id', 'telefono', 'titular', 'serie', 'compania', 'registrado', 'vencimiento'];
const MAX_LIMITE   = 1000;

/** Campo del ABM -> clave en `combos`. */
const COMBOS_CHIP = [
    'compania'    => '$xChip->compania',
    'plan'        => '$xChip->plan',
    'responsable' => '$xChip->responsable',
    'pais'        => '$xChip->pais',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar chips: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q           = trim((string) ($_GET['q']           ?? ''));
    $codigo      = (int)         ($_GET['codigo']      ?? 0);
    $compania    = trim((string) ($_GET['compania']    ?? ''));
    $plan        = trim((string) ($_GET['plan']        ?? ''));
    $responsable = trim((string) ($_GET['responsable'] ?? ''));
    $estado      = (string)      ($_GET['estado']      ?? 'todos');
    $limite      = (int)         ($_GET['limite']      ?? 100);
    $orden       = (string)      ($_GET['orden']       ?? 'id');
    $dir         = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)         $limite = 100;
    if ($limite > MAX_LIMITE) $limite = MAX_LIMITE;
    if (!in_array($orden, ORDEN_VALIDO, true)) $orden = 'id';

    $where  = ['c.dominio = :dom'];
    $params = [':dom' => $dominio];

    if ($codigo > 0) {
        $where[]        = 'c.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($compania !== '') {
        $where[]         = 'c.compania = :comp';
        $params[':comp'] = $compania;
    }
    if ($plan !== '') {
        $where[]         = 'c.plan = :plan';
        $params[':plan'] = $plan;
    }
    if ($responsable !== '') {
        $where[]         = 'c.responsable = :resp';
        $params[':resp'] = $responsable;
    }
    if ($estado === 'habilitados') {
        $where[] = 'c.estado = 1';
    } elseif ($estado === 'deshabilitados') {
        $where[] = '(c.estado IS NULL OR c.estado <> 1)';
    }
    if ($q !== '') {
        // Un placeholder por columna: con EMULATE_PREPARES=false, PDO no admite
        // repetir el mismo nombre en un statement (SQLSTATE HY093).
        $ors = [];
        foreach (['c.telefono', 'c.serie', 'c.titular', 'c.comentario'] as $i => $columna) {
            $ors[]             = $columna . ' LIKE :q' . $i;
            $params[':q' . $i] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    $sql = 'SELECT c.id, c.dominio, c.titular, c.responsable, c.pais, c.telefono,
                   c.serie, c.compania, c.plan, c.datos, c.mensajes, c.articulo,
                   c.registrado, c.recargado, c.vencimiento, c.estado, c.comentario,
                   a.nombre AS articulo_nombre, a.marca AS articulo_marca
            FROM chips c
            LEFT JOIN articulos a ON a.id = c.articulo
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY c.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $combos = combosChip();
    $chips  = array_map(static fn(array $r): array => mapChip($r, $combos), $stmt->fetchAll());

    // Resumen sobre el dominio completo, no sobre la pagina devuelta.
    $res = db()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN estado = 1 THEN 1 ELSE 0 END) AS habilitados
         FROM chips WHERE dominio = :dom'
    );
    $res->execute([':dom' => $dominio]);
    $r = $res->fetch() ?: ['total' => 0, 'habilitados' => 0];

    json_ok([
        'chips'     => $chips,
        'combos'    => $combos['opciones'],
        'articulos' => articulosDisponibles(),
        'resumen'   => [
            'total'          => (int) $r['total'],
            'habilitados'    => (int) $r['habilitados'],
            'deshabilitados' => (int) $r['total'] - (int) $r['habilitados'],
            'mostrados'      => count($chips),
        ],
    ]);
}

function handleGet(int $id): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $stmt = db()->prepare(
        'SELECT c.id, c.dominio, c.titular, c.responsable, c.pais, c.telefono,
                c.serie, c.compania, c.plan, c.datos, c.mensajes, c.articulo,
                c.registrado, c.recargado, c.vencimiento, c.estado, c.comentario,
                a.nombre AS articulo_nombre, a.marca AS articulo_marca,
                d.nombre AS dominio_nombre
         FROM chips c
         LEFT JOIN articulos a ON a.id = c.articulo
         LEFT JOIN dominios  d ON d.id = c.dominio
         WHERE c.id = :id AND c.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Chip no encontrado en este dominio', 404);
    }

    json_ok(['chip' => mapChip($row, combosChip())]);
}

/* ------------------------------------------------------------------ */
/* Alta / Modificacion / Baja                                          */
/* ------------------------------------------------------------------ */

function handleCreate(): void
{
    $dominio = requireDominioId();
    $datos   = validar(readJson(), $dominio, null);

    $stmt = db()->prepare(
        'INSERT INTO chips
            (dominio, titular, responsable, pais, telefono, serie, compania, plan,
             datos, mensajes, articulo, registrado, recargado, vencimiento,
             estado, comentario)
         VALUES
            (:dominio, :titular, :responsable, :pais, :telefono, :serie, :compania, :plan,
             :datos, :mensajes, :articulo, :registrado, :recargado, :vencimiento,
             :estado, :comentario)'
    );
    $stmt->execute($datos + [':dominio' => $dominio]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $dominio = requireDominioId();
    $in      = readJson();
    $id      = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    // El registro tiene que existir DENTRO del dominio de la sesion.
    $own = db()->prepare('SELECT id FROM chips WHERE id = :id AND dominio = :dom LIMIT 1');
    $own->execute([':id' => $id, ':dom' => $dominio]);
    if (!$own->fetchColumn()) {
        json_error('Chip no encontrado en este dominio', 404);
    }

    $datos = validar($in, $dominio, $id);

    $stmt = db()->prepare(
        'UPDATE chips
            SET titular = :titular, responsable = :responsable, pais = :pais,
                telefono = :telefono, serie = :serie, compania = :compania,
                plan = :plan, datos = :datos, mensajes = :mensajes,
                articulo = :articulo, registrado = :registrado,
                recargado = :recargado, vencimiento = :vencimiento,
                estado = :estado, comentario = :comentario
          WHERE id = :id AND dominio = :dom'
    );
    $stmt->execute($datos + [':id' => $id, ':dom' => $dominio]);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $dominio = requireDominioId();
    $id      = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $stmt = db()->prepare('DELETE FROM chips WHERE id = :id AND dominio = :dom');
    $stmt->execute([':id' => $id, ':dom' => $dominio]);

    if ($stmt->rowCount() === 0) {
        json_error('Chip no encontrado en este dominio', 404);
    }

    json_ok(['id' => $id]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Opciones de los campos con combo + tabla plana para traducir codigo -> texto.
 * Se lee una sola vez por request.
 */
function combosChip(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $claves = array_values(COMBOS_CHIP);
    $marks  = implode(',', array_fill(0, count($claves), '?'));

    $stmt = db()->prepare(
        "SELECT combo, valor, texto FROM combos
          WHERE combo IN ($marks)
          ORDER BY combo ASC, orden ASC, texto ASC"
    );
    $stmt->execute($claves);

    $porClave = array_flip(COMBOS_CHIP);           // '$xChip->compania' => 'compania'
    $opciones = array_fill_keys(array_keys(COMBOS_CHIP), []);
    $textos   = array_fill_keys(array_keys(COMBOS_CHIP), []);

    foreach ($stmt->fetchAll() as $r) {
        $campo = $porClave[$r['combo']] ?? null;
        if ($campo === null) {
            continue;
        }
        $valor = (string) ($r['valor'] ?? '');
        $texto = (string) ($r['texto'] ?? '');

        $opciones[$campo][]      = ['valor' => $valor, 'texto' => $texto];
        $textos[$campo][$valor]  = $texto;
    }

    return $cache = ['opciones' => $opciones, 'textos' => $textos];
}

/** Catalogo de articulos para el select de Alta/Edicion (no se filtra por dominio: es global). */
function articulosDisponibles(): array
{
    $stmt = db()->query('SELECT id, marca, nombre FROM articulos ORDER BY nombre ASC, id ASC');

    return array_map(static function (array $r): array {
        $marca  = trim((string) ($r['marca']  ?? ''));
        $nombre = trim((string) ($r['nombre'] ?? ''));
        return [
            'id'       => (int) $r['id'],
            'etiqueta' => trim($nombre . ($marca !== '' ? " ($marca)" : '')) ?: ('#' . (int) $r['id']),
        ];
    }, $stmt->fetchAll());
}

/** Normaliza una fila de `chips` para el front, con los combos ya traducidos. */
function mapChip(array $r, array $combos): array
{
    $textos = $combos['textos'];
    $cod    = static fn(string $campo): string => (string) ($r[$campo] ?? '');
    $label  = static fn(string $campo): string => $textos[$campo][(string) ($r[$campo] ?? '')] ?? '';

    $articulo = trim((string) ($r['articulo_nombre'] ?? ''));
    $marca    = trim((string) ($r['articulo_marca']  ?? ''));

    $out = [
        'id'                 => (int) $r['id'],
        'titular'            => (string) ($r['titular'] ?? ''),
        'telefono'           => (string) ($r['telefono'] ?? ''),
        'serie'              => (string) ($r['serie'] ?? ''),
        'compania'           => $cod('compania'),
        'compania_texto'     => $label('compania'),
        'plan'               => $cod('plan'),
        'plan_texto'         => $label('plan'),
        'responsable'        => $cod('responsable'),
        'responsable_texto'  => $label('responsable'),
        'pais'               => $cod('pais'),
        'pais_texto'         => $label('pais'),
        'datos'              => isset($r['datos'])    && $r['datos']    !== null ? (int) $r['datos']    : null,
        'mensajes'           => isset($r['mensajes']) && $r['mensajes'] !== null ? (int) $r['mensajes'] : null,
        'articulo'           => isset($r['articulo']) && $r['articulo'] !== null ? (int) $r['articulo'] : null,
        'articulo_nombre'    => $articulo . ($articulo !== '' && $marca !== '' ? " ($marca)" : ''),
        'registrado'         => fechaSalida($r['registrado']  ?? null),
        'recargado'          => fechaSalida($r['recargado']   ?? null),
        'vencimiento'        => fechaSalida($r['vencimiento'] ?? null),
        'estado'             => isset($r['estado']) && $r['estado'] !== null ? (int) $r['estado'] : null,
        'habilitado'         => (int) ($r['estado'] ?? 0) === 1,
        'comentario'         => (string) ($r['comentario'] ?? ''),
        'dominio'            => isset($r['dominio']) && $r['dominio'] !== null ? (int) $r['dominio'] : null,
    ];

    // Solo lo trae el GET por id (modal de Consulta).
    if (array_key_exists('dominio_nombre', $r)) {
        $out['dominio_nombre'] = (string) ($r['dominio_nombre'] ?? '');
    }

    return $out;
}

/**
 * Fecha lista para el front. El legacy usaba 1500-01-01 (y MySQL viejo
 * 0000-00-00) como "sin fecha": ambos salen como vacio.
 */
function fechaSalida(?string $valor): string
{
    $s = trim((string) $valor);
    if ($s === '' || !preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) {
        return '';
    }
    return (int) $m[1] < 1900 ? '' : substr($s, 0, 10);
}

/** 'YYYY-MM-DD' -> misma cadena; vacio -> NULL. Corta con 422 si no es una fecha real. */
function fechaEntrada(mixed $valor, string $etiqueta): ?string
{
    $s = trim((string) ($valor ?? ''));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)
        || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error("La fecha de $etiqueta no es valida", 422);
    }
    return $s;
}

/** Codigo de combo valido (o vacio). Corta con 422 si no esta en `combos`. */
function comboValido(array $combos, string $campo, mixed $valor, string $etiqueta): ?string
{
    $s = trim((string) ($valor ?? ''));
    if ($s === '') {
        return null;
    }
    if (!array_key_exists($s, $combos['textos'][$campo] ?? [])) {
        json_error("El valor de $etiqueta no es valido", 422);
    }
    return $s;
}

/** Entero opcional >= 0. Vacio -> NULL. */
function enteroOpcional(mixed $valor, string $etiqueta): ?int
{
    $s = trim((string) ($valor ?? ''));
    if ($s === '') {
        return null;
    }
    if (!preg_match('/^\d+$/', $s)) {
        json_error("El campo $etiqueta debe ser un numero entero", 422);
    }
    return (int) $s;
}

/**
 * Valida y normaliza el payload de alta/edicion. Devuelve el array de
 * parametros listo para el INSERT/UPDATE. Corta con 422/409 si algo falla.
 */
function validar(array $in, int $dominio, ?int $idActual): array
{
    $combos = combosChip();

    $telefono   = trim((string) ($in['telefono']   ?? ''));
    $serie      = trim((string) ($in['serie']      ?? ''));
    $titular    = trim((string) ($in['titular']    ?? ''));
    $comentario = trim((string) ($in['comentario'] ?? ''));

    if ($telefono === '')             json_error('El telefono es obligatorio', 422);
    if (mb_strlen($telefono) > 30)    json_error('El telefono no puede superar 30 caracteres', 422);
    if (!preg_match('/^[+0-9\s().-]+$/', $telefono)) {
        json_error('El telefono solo admite numeros, espacios y los signos + ( ) - .', 422);
    }
    if (mb_strlen($serie) > 32)       json_error('La serie no puede superar 32 caracteres', 422);
    if ($serie !== '' && !preg_match('/^[A-Za-z0-9]+$/', $serie)) {
        json_error('La serie (ICCID) solo admite letras y numeros', 422);
    }
    if (mb_strlen($titular) > 255)    json_error('El titular no puede superar 255 caracteres', 422);
    if (mb_strlen($comentario) > 255) json_error('El comentario no puede superar 255 caracteres', 422);

    // Sin UNIQUE en la tabla: el duplicado se controla aca, dentro del dominio.
    duplicado('telefono', $telefono, $dominio, $idActual, 'Ya existe un chip con ese telefono en este dominio');
    if ($serie !== '') {
        duplicado('serie', $serie, $dominio, $idActual, 'Ya existe un chip con esa serie en este dominio');
    }

    $articulo = enteroOpcional($in['articulo'] ?? '', 'articulo');
    if ($articulo !== null && $articulo > 0) {
        $chk = db()->prepare('SELECT id FROM articulos WHERE id = :a LIMIT 1');
        $chk->execute([':a' => $articulo]);
        if (!$chk->fetchColumn()) {
            json_error('El articulo indicado no existe', 422);
        }
    } else {
        $articulo = null;
    }

    return [
        ':titular'     => $titular === '' ? null : $titular,
        ':responsable' => comboValido($combos, 'responsable', $in['responsable'] ?? '', 'responsable de pago'),
        ':pais'        => comboValido($combos, 'pais',        $in['pais']        ?? '', 'pais'),
        ':telefono'    => $telefono,
        ':serie'       => $serie === '' ? null : $serie,
        ':compania'    => comboValido($combos, 'compania',    $in['compania']    ?? '', 'compania'),
        ':plan'        => comboValido($combos, 'plan',        $in['plan']        ?? '', 'plan'),
        ':datos'       => enteroOpcional($in['datos']    ?? '', 'datos'),
        ':mensajes'    => enteroOpcional($in['mensajes'] ?? '', 'mensajes'),
        ':articulo'    => $articulo,
        ':registrado'  => fechaEntrada($in['registrado']  ?? '', 'registrado'),
        ':recargado'   => fechaEntrada($in['recargado']   ?? '', 'recargado'),
        ':vencimiento' => fechaEntrada($in['vencimiento'] ?? '', 'vencimiento'),
        ':estado'      => !empty($in['habilitado']) ? 1 : 0,
        ':comentario'  => $comentario === '' ? null : $comentario,
    ];
}

/** Corta con 409 si otro chip del mismo dominio ya usa ese valor. */
function duplicado(string $campo, string $valor, int $dominio, ?int $idActual, string $mensaje): void
{
    $stmt = db()->prepare(
        "SELECT id FROM chips WHERE $campo = :v AND dominio = :dom AND id <> :id LIMIT 1"
    );
    $stmt->execute([':v' => $valor, ':dom' => $dominio, ':id' => $idActual ?? 0]);
    if ($stmt->fetchColumn()) {
        json_error($mensaje, 409);
    }
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Body JSON invalido', 400);
    }
    return $data;
}
