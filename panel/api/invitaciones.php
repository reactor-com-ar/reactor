<?php

declare(strict_types=1);

/**
 * Invitaciones: listado y alta de `invitaciones` (esquema en db/schema.sql).
 *
 *   GET  api/invitaciones.php        -> listado + resumen + catalogos
 *   GET  api/invitaciones.php?id=N   -> una invitacion (todos los campos)
 *   POST api/invitaciones.php        -> alta + envio del correo
 *
 * SIN EDICION NI BAJA. Igual que reactor-panel/invitaciones/listar.php, la
 * invitacion la emite el usuario y el destinatario es quien la acepta o la
 * rechaza: no hay nada que editar desde el panel. PUT y DELETE cortan con 405.
 *
 * ALTA: el unico dato que se pide es el correo, porque es el destino del
 * mensaje. El nombre y el celular del invitado se capturan recien cuando
 * acepta (invitacion/aceptar.php), que es donde el legacy tambien los pedia.
 * Es la misma forma del alta legacy (reactor-app/dominio/invitar.php pedia
 * un solo campo, el celular) con el canal invertido.
 *
 * ENVIO: por correo, via el microservicio de Databox (lib/databox.php). El
 * legacy manda por WhatsApp desde reactor-app y NO se toca: los dos caminos
 * conviven sobre la misma tabla. El enlace apunta a panel.reactor.com.ar.
 *
 * ATOMICIDAD: el alta y el envio van en una transaccion. Si el correo no se
 * pudo encolar se revierte el INSERT: una fila pendiente que el destinatario
 * nunca recibio es peor que no tener fila, porque nadie sabe que hay que
 * reintentar.
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()),
 * incluido el lookup por id, para que nadie lea una invitacion de otro
 * dominio pasando un id a mano. El legacy hacia lo mismo con
 * $oSesion->leer('sesionDominio').
 *
 * ESTADO: `invitaciones.estado` es un varchar(1) con los codigos del legacy
 * (1 pendiente, 3 aceptada, 2 rechazada, 0 anulada). El texto sale de la
 * tabla `combos` con la clave '$xInvitacion->estado' -- la misma convencion
 * que usaba comboTraducir() -- y ESTADOS_FALLBACK cubre el caso de que esa
 * fila no este cargada, para que la UI nunca muestre un codigo pelado.
 *
 * ESCALA (medido en reactor_dev, 2026-09-01): la tabla tiene 33 filas vivas
 * (AUTO_INCREMENT en 1033) con indice por dominio. No hace falta la ventana
 * por id que si necesitan `registros` y `senales`.
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/invitaciones.php';

const ORDEN_VALIDO = ['id', 'emitida', 'abierta', 'nombre'];
const MAX_LIMITE   = 1000;

/** Clave de `combos` con los textos de `invitaciones.estado`. */
const COMBO_ESTADO = '$xInvitacion->estado';

/** Ultimo recurso si `combos` no tiene cargada la clave de arriba. */
const ESTADOS_FALLBACK = [
    ['valor' => '1', 'texto' => 'Pendiente'],
    ['valor' => '3', 'texto' => 'Aceptada'],
    ['valor' => '2', 'texto' => 'Rechazada'],
    ['valor' => '0', 'texto' => 'Anulada'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
            break;
        case 'POST':
            handleCreate();
            break;
        default:
            json_error('Metodo no permitido: la invitacion no se edita ni se elimina', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar las invitaciones: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q      = trim((string) ($_GET['q']      ?? ''));
    $codigo = (int)         ($_GET['codigo'] ?? 0);
    $uuid   = trim((string) ($_GET['uuid']   ?? ''));
    $emisor = (int)         ($_GET['emisor'] ?? 0);
    $estado = trim((string) ($_GET['estado'] ?? ''));
    $desde  = fechaFiltro($_GET['desde'] ?? '', false);
    $hasta  = fechaFiltro($_GET['hasta'] ?? '', true);
    $limite = (int)         ($_GET['limite'] ?? 100);
    $orden  = (string)      ($_GET['orden']  ?? 'id');
    $dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)         $limite = 100;
    if ($limite > MAX_LIMITE) $limite = MAX_LIMITE;
    if (!in_array($orden, ORDEN_VALIDO, true)) $orden = 'id';

    $estados = estadosInvitacion();
    if ($estado !== '' && !array_key_exists($estado, $estados['textos'])) {
        $estado = '';
    }

    $where  = ['i.dominio = :dom'];
    $params = [':dom' => $dominio];

    if ($codigo > 0) {
        $where[]        = 'i.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($uuid !== '') {
        $where[]         = 'i.uuid LIKE :uuid';
        $params[':uuid'] = '%' . $uuid . '%';
    }
    if ($emisor > 0) {
        $where[]        = 'i.emisor = :emi';
        $params[':emi'] = $emisor;
    }
    if ($estado !== '') {
        $where[]        = 'i.estado = :est';
        $params[':est'] = $estado;
    }
    if ($desde !== null) {
        $where[]          = 'i.emitida >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta !== null) {
        $where[]          = 'i.emitida <= :hasta';
        $params[':hasta'] = $hasta;
    }
    if ($q !== '') {
        // Un placeholder por columna: con EMULATE_PREPARES=false (lib/db.php)
        // PDO no admite repetir el mismo nombre en un statement (HY093).
        $ors = [];
        // Se busca por todo lo que el listado muestra, incluidos el correo y
        // el celular del emisor: si estan en la columna, tienen que encontrarse.
        foreach (['i.uuid', 'i.nombre', 'i.celular', 'i.correo',
                  'u.nombre', 'u.usuario', 'u.correo', 'u.celular'] as $n => $columna) {
            $ors[]             = $columna . ' LIKE :q' . $n;
            $params[':q' . $n] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    // El listado muestra al emisor con sus tres datos de contacto, igual que
    // al destinatario, asi que el JOIN trae tambien correo y celular.
    $sql = 'SELECT i.id, i.uuid, i.dominio, i.emisor, i.nombre, i.celular,
                   i.correo, i.emitida, i.abierta, i.estado,
                   u.nombre  AS emisor_nombre,
                   u.usuario AS emisor_login,
                   u.correo  AS emisor_correo,
                   u.celular AS emisor_celular
            FROM invitaciones i
            LEFT JOIN usuarios u ON u.id = i.emisor
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY i.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $filas = array_map(
        static fn(array $r): array => mapInvitacion($r, $estados['textos']),
        $stmt->fetchAll()
    );

    json_ok([
        'invitaciones' => $filas,
        'estados'      => $estados['opciones'],
        'catalogos'    => ['emisores' => emisores($dominio)],
        'resumen'      => resumen($dominio, count($filas)),
    ]);
}

function handleGet(int $id): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $stmt = db()->prepare(
        'SELECT i.id, i.uuid, i.dominio, i.emisor, i.nombre, i.celular,
                i.correo, i.emitida, i.abierta, i.estado,
                u.nombre  AS emisor_nombre,
                u.usuario AS emisor_login,
                u.correo  AS emisor_correo,
                d.nombre  AS dominio_nombre
         FROM invitaciones i
         LEFT JOIN usuarios u ON u.id = i.emisor
         LEFT JOIN dominios d ON d.id = i.dominio
         WHERE i.id = :id AND i.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Invitacion no encontrada en este dominio', 404);
    }

    json_ok(['invitacion' => mapInvitacion($row, estadosInvitacion()['textos'])]);
}

/* ------------------------------------------------------------------ */
/* Alta + envio                                                        */
/* ------------------------------------------------------------------ */

function handleCreate(): void
{
    $dominio = requireDominioId();
    $ctx     = sessionContext() ?? [];
    $emisor  = (int) ($ctx['id'] ?? 0);
    if ($emisor <= 0) {
        json_error('No se pudo identificar al emisor de la invitacion', 409);
    }

    $in     = readJson();
    $correo = strtolower(trim((string) ($in['correo'] ?? '')));

    if ($correo === '') {
        json_error('El correo es obligatorio', 422);
    }
    if (mb_strlen($correo) > 100) {
        json_error('El correo no puede superar 100 caracteres', 422);
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_error('El correo no es valido', 422);
    }

    // Si esa persona ya entra a este dominio, la invitacion no tiene efecto:
    // al aceptarla el flujo cortaria igual. Mejor decirlo ahora que gastar un
    // correo y dejar una pendiente que nunca se va a poder cerrar.
    $ya = db()->prepare(
        "SELECT u.id
         FROM usuarios u
         JOIN perfiles p ON p.usuario = u.id AND p.dominio = :dom AND p.habilitado = '1'
         WHERE LOWER(u.correo) = :correo
         LIMIT 1"
    );
    $ya->execute([':dom' => $dominio, ':correo' => $correo]);
    if ($ya->fetchColumn()) {
        json_error('Ese correo ya tiene acceso a este dominio', 409);
    }

    $pdo  = db();
    $uuid = invitacionUuidNuevo($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO invitaciones
                (uuid, dominio, emisor, nombre, celular, correo, emitida, abierta, estado)
             VALUES
                (:uuid, :dom, :emisor, NULL, NULL, :correo, NOW(), :abierta, :estado)'
        );
        $stmt->execute([
            ':uuid'    => $uuid,
            ':dom'     => $dominio,
            ':emisor'  => $emisor,
            ':correo'  => $correo,
            // El legacy no usa NULL para "todavia no se abrio": usa el
            // centinela historico, y las dos pantallas lo leen como vacio.
            ':abierta' => INVITACION_SIN_FECHA,
            ':estado'  => INVITACION_PENDIENTE,
        ]);
        $id = (int) $pdo->lastInsertId();

        $envio = invitacionEnviarCorreo([
            'uuid'           => $uuid,
            'dominio'        => $dominio,
            'dominio_nombre' => (string) ($ctx['dominio_nombre'] ?? ''),
            // sessionContext() siempre trae la clave 'nombre' (a veces vacia),
            // asi que ?? no alcanza para caer al login.
            'emisor_nombre'  => trim((string) ($ctx['nombre'] ?? '')) !== ''
                ? (string) $ctx['nombre']
                : (string) ($ctx['usuario'] ?? ''),
            'nombre'         => '',
            'correo'         => $correo,
        ]);

        if (!$envio['ok']) {
            $pdo->rollBack();
            json_error('No se pudo enviar la invitacion. ' . ($envio['error'] ?? ''), 502);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_ok(['id' => $id, 'uuid' => $uuid, 'correo' => $correo], 201);
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

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Opciones del combo de estado + tabla plana codigo -> texto.
 * Se lee una sola vez por request.
 */
function estadosInvitacion(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = db()->prepare(
        'SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, texto ASC'
    );
    $stmt->execute([':c' => COMBO_ESTADO]);

    $opciones = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') {
            continue;
        }
        $opciones[] = ['valor' => $valor, 'texto' => (string) ($r['texto'] ?? '')];
    }
    if ($opciones === []) {
        $opciones = ESTADOS_FALLBACK;
    }

    $textos = [];
    foreach ($opciones as $o) {
        $textos[$o['valor']] = $o['texto'];
    }

    return $cache = ['opciones' => $opciones, 'textos' => $textos];
}

/**
 * Usuarios que emitieron alguna invitacion del dominio. Alimenta el select
 * del modal de filtros: mas corto y mas util que el padron completo.
 */
function emisores(int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT u.id, u.nombre, u.usuario
         FROM invitaciones i
         JOIN usuarios u ON u.id = i.emisor
         WHERE i.dominio = :dom
         ORDER BY u.nombre ASC, u.usuario ASC'
    );
    $stmt->execute([':dom' => $dominio]);

    return array_map(static function (array $r): array {
        $nombre = trim((string) ($r['nombre'] ?? ''));
        if ($nombre === '') {
            $nombre = trim((string) ($r['usuario'] ?? ''));
        }
        return [
            'id'     => (int) $r['id'],
            'nombre' => $nombre !== '' ? $nombre : '#' . (int) $r['id'],
        ];
    }, $stmt->fetchAll());
}

/** Contadores del dominio completo (no de la pagina devuelta). */
function resumen(int $dominio, int $mostrados): array
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN estado = '1' THEN 1 ELSE 0 END) AS pendientes,
                SUM(CASE WHEN estado = '3' THEN 1 ELSE 0 END) AS aceptadas,
                SUM(CASE WHEN estado = '2' THEN 1 ELSE 0 END) AS rechazadas,
                SUM(CASE WHEN estado = '0' THEN 1 ELSE 0 END) AS anuladas
         FROM invitaciones WHERE dominio = :dom"
    );
    $stmt->execute([':dom' => $dominio]);
    $r = $stmt->fetch() ?: [];

    return [
        'total'      => (int) ($r['total']      ?? 0),
        'pendientes' => (int) ($r['pendientes'] ?? 0),
        'aceptadas'  => (int) ($r['aceptadas']  ?? 0),
        'rechazadas' => (int) ($r['rechazadas'] ?? 0),
        'anuladas'   => (int) ($r['anuladas']   ?? 0),
        'mostrados'  => $mostrados,
    ];
}

/** Normaliza una fila de `invitaciones` para el front. */
function mapInvitacion(array $r, array $textos): array
{
    $texto  = static fn(string $k): string => trim((string) ($r[$k] ?? ''));
    $estado = $texto('estado');

    $out = [
        'id'            => (int) $r['id'],
        'uuid'          => $texto('uuid'),
        'emisor'        => isset($r['emisor']) && $r['emisor'] !== null ? (int) $r['emisor'] : null,
        'emisor_nombre' => $texto('emisor_nombre'),
        'emisor_login'  => $texto('emisor_login'),
        'nombre'        => $texto('nombre'),
        'celular'       => $texto('celular'),
        'correo'        => $texto('correo'),
        'emitida'       => fechaSalida($r['emitida'] ?? null),
        'abierta'       => fechaSalida($r['abierta'] ?? null),
        'estado'        => $estado,
        'estado_texto'  => $textos[$estado] ?? '',
        'dominio'       => isset($r['dominio']) && $r['dominio'] !== null ? (int) $r['dominio'] : null,
    ];

    // Campos que no traen las dos consultas: `emisor_celular` es del listado
    // y `dominio_nombre` del GET por id (modal de Consulta).
    foreach (['emisor_correo', 'emisor_celular', 'dominio_nombre'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = $texto($extra);
        }
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
    if ($s === '' || !preg_match('/^(\d{4})-\d{2}-\d{2}/', $s, $m)) {
        return '';
    }
    return (int) $m[1] < 1900 ? '' : $s;
}

/**
 * Fecha del filtro de rango: acepta 'YYYY-MM-DD' (input date) o
 * 'YYYY-MM-DDTHH:MM'. `$finDelDia` completa 23:59:59 cuando solo vino la
 * fecha, para que "hasta" incluya el dia entero. Vacio => null.
 */
function fechaFiltro(mixed $valor, bool $finDelDia): ?string
{
    $valor = trim((string) $valor);
    if ($valor === '') {
        return null;
    }
    $valor = str_replace('T', ' ', $valor);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $valor, $m)) {
        json_error('La fecha del filtro no es valida', 422);
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error('La fecha del filtro no existe en el calendario', 422);
    }
    if (!isset($m[4])) {
        return sprintf('%s-%s-%s %s', $m[1], $m[2], $m[3], $finDelDia ? '23:59:59' : '00:00:00');
    }
    return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4], $m[5], $m[6] ?? '00');
}
