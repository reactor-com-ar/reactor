<?php

declare(strict_types=1);

/**
 * Actividad: listado de `registros` (esquema real en db/schema.sql).
 *
 *   GET api/actividad.php        -> listado + resumen + catalogos
 *   GET api/actividad.php?id=N   -> un registro (todos los campos)
 *
 * MODULO DE SOLO LECTURA. `registros` es la bitacora del sistema: la
 * escriben el motor y las apps, nunca el panel. Por eso este endpoint no
 * expone POST / PUT / DELETE -- cualquier otro metodo corta con 405.
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()),
 * incluido el lookup por id, para que nadie lea un registro de otro
 * dominio pasando un id a mano.
 *
 * SENTIDO: 'S' = salida (accion que un usuario mando al dispositivo) y
 * 'E' = entrada (lo que reporto el equipo). El legacy
 * (reactor-app/dominio/actividad.php) lista solo 'S'; ese es el default
 * del modulo, pero el filtro permite ver las entradas o ambas.
 *
 * ESCALA (medido en reactor_dev, 2026-09-01): `registros` tiene ~2,95M
 * filas y un solo dominio puede aportar 770K. La tabla solo tiene indices
 * por PK y por las FKs (dominio, usuario, dispositivo, canal): `fecha` no
 * esta indexada y no existe un indice compuesto (dominio, id). Con
 * `ORDER BY id DESC LIMIT 100`, MySQL recorre la PK hacia atras filtrando
 * fila por fila, asi que una busqueda sin resultados o un rango de fechas
 * vacio barren la tabla entera: 14-15 s medidos. Por eso el listado corre
 * SIEMPRE dentro de una ventana de ids recientes (VENTANAS), igual que el
 * patron ya usado con `senales`. Con la ventana por defecto el mismo peor
 * caso baja a 0,16 s.
 */

require __DIR__ . '/bootstrap.php';

/**
 * Solo se puede ordenar por la PK. `fecha`, `usuario` y `dispositivo`
 * obligan a un filesort sobre todas las filas del dominio (5,5 s medidos)
 * y no aportan: `id` es AUTO_INCREMENT, o sea el mismo orden cronologico.
 */
const ORDEN_VALIDO = ['id'];
const MAX_LIMITE   = 1000;

/**
 * Ventanas de busqueda ofrecidas al front, en cantidad de ids hacia atras
 * desde MAX(id). 0 = sin ventana (recorre toda la tabla; lento, pero es
 * la unica forma de llegar al historial viejo mientras no exista el
 * indice (dominio, id)).
 */
const VENTANAS        = [200000, 1000000, 0];
const VENTANA_DEFECTO = 200000;

/**
 * Ventana fija para los contadores "hoy" / "ultimas 24 h". Es
 * independiente de la que elija el usuario: esos numeros solo miran datos
 * recientes y no hay motivo para pagar un barrido completo. Al ritmo
 * actual, 200K ids cubren ~40 dias de trafico global, con margen de sobra
 * sobre las 24 h que necesitan.
 */
const VENTANA_CONTADORES = 200000;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido: la actividad es de solo lectura', 405);
    }
    isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
} catch (Throwable $e) {
    json_error('Error al procesar la actividad: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q           = trim((string) ($_GET['q']           ?? ''));
    $codigo      = (int)         ($_GET['codigo']      ?? 0);
    $usuario     = (int)         ($_GET['usuario']     ?? 0);
    $dispositivo = (int)         ($_GET['dispositivo'] ?? 0);
    $sentido     = strtoupper(trim((string) ($_GET['sentido'] ?? 'S')));
    $desde       = fechaFiltro($_GET['desde'] ?? '', false);
    $hasta       = fechaFiltro($_GET['hasta'] ?? '', true);
    $ventana     = (int)         ($_GET['ventana']     ?? VENTANA_DEFECTO);
    $limite      = (int)         ($_GET['limite']      ?? 100);
    $orden       = (string)      ($_GET['orden']       ?? 'id');
    $dir         = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)         $limite = 100;
    if ($limite > MAX_LIMITE) $limite = MAX_LIMITE;
    if (!in_array($orden,   ORDEN_VALIDO, true)) $orden   = 'id';
    if (!in_array($ventana, VENTANAS,     true)) $ventana = VENTANA_DEFECTO;
    if ($sentido !== 'S' && $sentido !== 'E')    $sentido = '';

    $maxId = maxId();

    $where  = ['r.dominio = :dom'];
    $params = [':dom' => $dominio];

    // Acota el barrido de la PK. Con `codigo` no hace falta: el filtro por
    // id ya es un lookup puntual, y aplicar la ventana escondería registros
    // viejos que el usuario esta buscando explicitamente por numero.
    if ($codigo > 0) {
        $where[]        = 'r.id = :cod';
        $params[':cod'] = $codigo;
    } elseif ($ventana > 0) {
        $where[]         = 'r.id > :vmin';
        $params[':vmin'] = max(0, $maxId - $ventana);
    }

    if ($usuario > 0) {
        $where[]        = 'r.usuario = :usr';
        $params[':usr'] = $usuario;
    }
    if ($dispositivo > 0) {
        $where[]        = 'r.dispositivo = :dis';
        $params[':dis'] = $dispositivo;
    }
    if ($sentido !== '') {
        $where[]         = 'r.sentido = :sent';
        $params[':sent'] = $sentido;
    }
    if ($desde !== null) {
        $where[]          = 'r.fecha >= :desde';
        $params[':desde'] = $desde;
    }
    if ($hasta !== null) {
        $where[]          = 'r.fecha <= :hasta';
        $params[':hasta'] = $hasta;
    }
    // Un placeholder por ocurrencia: la conexion usa ATTR_EMULATE_PREPARES
    // = false (lib/db.php) y con prepares nativos un nombre repetido tira
    // "Invalid parameter number".
    if ($q !== '') {
        $where[] = '(u.nombre LIKE :q1 OR u.usuario LIKE :q2 OR d.nombre LIKE :q3
                     OR d.uuid LIKE :q4 OR c.nombre LIKE :q5 OR r.estado LIKE :q6)';
        foreach (['q1', 'q2', 'q3', 'q4', 'q5', 'q6'] as $ph) {
            $params[':' . $ph] = '%' . $q . '%';
        }
    }

    $sql = 'SELECT r.id, r.fecha, r.sentido, r.estado,
                   r.usuario, r.dispositivo, r.canal,
                   u.nombre AS usuario_nombre,
                   u.usuario AS usuario_login,
                   d.nombre AS dispositivo_nombre,
                   d.uuid   AS dispositivo_uuid,
                   c.nombre AS canal_nombre,
                   c.canal  AS canal_numero
            FROM registros r
            LEFT JOIN usuarios     u ON u.id = r.usuario
            LEFT JOIN dispositivos d ON d.id = r.dispositivo
            LEFT JOIN canales      c ON c.id = r.canal
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY r.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $filas = array_map('mapRegistro', $stmt->fetchAll());

    json_ok([
        'actividad' => $filas,
        'catalogos' => catalogos($dominio),
        'resumen'   => resumen($dominio, $maxId, count($filas)),
        'ventanas'  => VENTANAS,
    ]);
}

function handleGet(int $id): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $stmt = db()->prepare(
        'SELECT r.*,
                u.nombre   AS usuario_nombre,
                u.usuario  AS usuario_login,
                u.correo   AS usuario_correo,
                d.nombre   AS dispositivo_nombre,
                d.uuid     AS dispositivo_uuid,
                c.nombre   AS canal_nombre,
                c.canal    AS canal_numero,
                c.uuid     AS canal_uuid,
                dom.nombre AS dominio_nombre
         FROM registros r
         LEFT JOIN usuarios     u   ON u.id   = r.usuario
         LEFT JOIN dispositivos d   ON d.id   = r.dispositivo
         LEFT JOIN canales      c   ON c.id   = r.canal
         LEFT JOIN dominios     dom ON dom.id = r.dominio
         WHERE r.id = :id AND r.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Registro de actividad no encontrado en este dominio', 404);
    }

    json_ok(['registro' => mapRegistro($row)]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** MAX(id) de la tabla: base de todas las ventanas. Es instantaneo (PK). */
function maxId(): int
{
    static $max = null;
    if ($max === null) {
        $max = (int) db()->query('SELECT MAX(id) FROM registros')->fetchColumn();
    }
    return $max;
}

/** Normaliza una fila de `registros` para el front. */
function mapRegistro(array $r): array
{
    $texto  = static fn(string $k): string => (string) ($r[$k] ?? '');
    $entero = static function (string $k) use ($r): ?int {
        return array_key_exists($k, $r) && $r[$k] !== null ? (int) $r[$k] : null;
    };

    $out = [
        'id'                 => (int) $r['id'],
        'fecha'              => $texto('fecha'),
        'sentido'            => $texto('sentido'),
        'estado'             => $texto('estado'),
        'usuario'            => $entero('usuario'),
        'usuario_nombre'     => $texto('usuario_nombre'),
        'usuario_login'      => $texto('usuario_login'),
        'dispositivo'        => $entero('dispositivo'),
        'dispositivo_nombre' => $texto('dispositivo_nombre'),
        'dispositivo_uuid'   => $texto('dispositivo_uuid'),
        'canal'              => $entero('canal'),
        'canal_nombre'       => $texto('canal_nombre'),
        'canal_numero'       => $entero('canal_numero'),
    ];

    // Campos que solo trae el GET por id (modal de Consulta).
    foreach (['usuario_correo', 'canal_uuid', 'dominio_nombre'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = (string) ($r[$extra] ?? '');
        }
    }
    if (array_key_exists('dominio', $r)) {
        $out['dominio'] = $entero('dominio');
    }

    return $out;
}

/**
 * Contadores del dominio completo (no de la pagina devuelta).
 *
 * `total` sale del indice por dominio sin tocar la tabla. "hoy" y
 * "ultimas 24 h", en cambio, leen `fecha`, que no esta indexada: si se
 * las dejara correr sobre todo el dominio serian 9,5 s. Por eso van
 * acotadas a VENTANA_CONTADORES ids recientes -- exactas mientras esa
 * ventana cubra mas de 24 h de altas, que hoy cubre por amplio margen.
 */
function resumen(int $dominio, int $maxId, int $mostrados): array
{
    $total = db()->prepare('SELECT COUNT(*) FROM registros WHERE dominio = :dom');
    $total->execute([':dom' => $dominio]);

    $stmt = db()->prepare(
        'SELECT SUM(CASE WHEN fecha >= :hoy THEN 1 ELSE 0 END) AS hoy,
                SUM(CASE WHEN fecha >= :h24 THEN 1 ELSE 0 END) AS ultimas24h
         FROM registros WHERE id > :vmin AND dominio = :dom'
    );
    $stmt->execute([
        ':dom'  => $dominio,
        ':vmin' => max(0, $maxId - VENTANA_CONTADORES),
        ':hoy'  => (new DateTimeImmutable('today'))->format('Y-m-d H:i:s'),
        ':h24'  => (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s'),
    ]);
    $r = $stmt->fetch() ?: [];

    return [
        'total'       => (int) $total->fetchColumn(),
        'hoy'         => (int) ($r['hoy']        ?? 0),
        'ultimas_24h' => (int) ($r['ultimas24h'] ?? 0),
        'mostrados'   => $mostrados,
    ];
}

/**
 * Catalogos que alimentan los selects del modal de filtros. Ambos van
 * acotados al dominio de la sesion.
 */
function catalogos(int $dominio): array
{
    $usuarios = db()->prepare(
        'SELECT id, nombre, usuario FROM usuarios WHERE dominio = :dom ORDER BY nombre ASC'
    );
    $usuarios->execute([':dom' => $dominio]);

    $dispositivos = db()->prepare(
        'SELECT id, nombre, uuid FROM dispositivos WHERE dominio = :dom ORDER BY nombre ASC'
    );
    $dispositivos->execute([':dom' => $dominio]);

    $etiqueta = static function (array $r, string $alternativa): array {
        $nombre = trim((string) ($r['nombre'] ?? ''));
        if ($nombre === '') $nombre = trim((string) ($r[$alternativa] ?? ''));
        return [
            'id'     => (int) $r['id'],
            'nombre' => $nombre !== '' ? $nombre : '#' . (int) $r['id'],
        ];
    };

    return [
        'usuarios'     => array_map(static fn(array $r): array => $etiqueta($r, 'usuario'), $usuarios->fetchAll()),
        'dispositivos' => array_map(static fn(array $r): array => $etiqueta($r, 'uuid'),    $dispositivos->fetchAll()),
    ];
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
