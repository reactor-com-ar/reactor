<?php

declare(strict_types=1);

/**
 * ABM de `dispositivos` (esquema real en db/schema.sql).
 *
 *   GET    api/dispositivos.php             -> listado + resumen (vista de tabla)
 *   GET    api/dispositivos.php?id=N        -> un registro con TODAS sus columnas
 *   GET    api/dispositivos.php?catalogos=1 -> solo los catalogos (modal de alta)
 *   POST   api/dispositivos.php             -> alta
 *   PUT    api/dispositivos.php             -> modificacion
 *   DELETE api/dispositivos.php?id=N        -> baja
 *
 * ALCANCE: cloud es el back office INTERNO de Reactor, no el panel del
 * cliente: no se acota por dominio y expone las 35 columnas de la tabla,
 * incluidas las de telemetria (`enlace`, `ip`, `senal`, `firmware`, los
 * contadores, las fechas de conexion/latido y `monitoreoUltimo` /
 * `monitoreoSiguiente`). Normalmente las escribe el equipo o el motor de
 * monitoreo; aca se pueden corregir a mano porque es justamente la
 * herramienta con la que se arregla un dato roto. En `panel/` esos mismos
 * campos son de solo lectura -- ahi el que edita es el cliente.
 *
 * EL LISTADO MAPEA A NOMBRES DE UI. `handleList()` devuelve `uid`, `tipo`,
 * `ubicacion` y `estado`, que NO son columnas: son derivaciones para la
 * tabla y los filtros (ver el comentario del query). El alta y la
 * modificacion, en cambio, hablan el esquema real: `uuid`, `coordenadas`,
 * `habilitado`, `enlace`, etc.
 */

require __DIR__ . '/bootstrap.php';

/** Columnas de texto editables, con su largo maximo segun el esquema. */
const TEXTOS = [
    'uuid'             => 16,
    'nombre'           => 255,
    'firmware'         => 50,
    'mac'              => 50,
    'ip'               => 50,
    'senal'            => 50,
    'serial'           => 50,
    'identidad'        => 50,
    'llave'            => 50,
    'monitoreoCorreos' => 1000,
    'coordenadas'      => 255,
    'indicadores'      => 1000,
];

/** Columnas FK: campo del payload => tabla a la que apunta. */
const REFERENCIAS = [
    'agente'      => 'agentes',
    'modelo'      => 'modelos',
    'producto'    => 'productos',
    'transceptor' => 'transceptores',
    'chip'        => 'chips',
    'adopcion'    => 'adopciones',
];

/** Columnas smallint que en la practica son booleanas. */
const BANDERAS = ['habilitado', 'adoptado', 'enlace', 'monitoreo'];

/** Columnas int no negativas (contadores y limites). */
const ENTEROS = ['senalesLimite', 'inicios', 'conexiones', 'latidos', 'monitoreoIntervalo'];

/** Columnas datetime. */
const FECHAS = [
    'fabricacion', 'instalacion', 'inicio', 'conexion', 'latido',
    'monitoreoUltimo', 'monitoreoSiguiente',
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['id']))        { handleGet((int) $_GET['id']); }
            elseif (isset($_GET['catalogos'])) { json_ok(['catalogos' => catalogos()]); }
            else                           { handleList(); }
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar dispositivos: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Esquema real (db/schema.sql -> tabla `dispositivos`): no hay `uid`, `tipo`,
    // `ubicacion` ni `estado` (string). Mapeos / derivaciones para la tabla:
    //   uid          -> uuid
    //   tipo         -> modelos.nombre          (lo mas parecido a un "tipo")
    //   ubicacion    -> coordenadas
    //   estado       -> derivado de habilitado/enlace (smallint):
    //                      habilitado <> 1            -> 'error'
    //                      habilitado = 1 AND enlace=1 -> 'online'
    //                      resto                      -> 'offline'
    //   last_seen_at -> latido
    //   created_at   -> COALESCE(instalacion, fabricacion)
    //   dominio_id   -> dominio
    $stmt = db()->query(
        "SELECT d.id,
                d.uuid                                 AS uid,
                d.nombre,
                d.serial,
                COALESCE(m.nombre, '')                 AS tipo,
                d.coordenadas                          AS ubicacion,
                CASE
                    WHEN d.habilitado <> 1 THEN 'error'
                    WHEN d.enlace = 1      THEN 'online'
                    ELSE 'offline'
                END                                    AS estado,
                d.latido                               AS last_seen_at,
                COALESCE(d.instalacion, d.fabricacion) AS created_at,
                d.dominio                              AS dominio_id,
                COALESCE(dom.nombre, '—')              AS dominio_nombre
         FROM dispositivos d
         LEFT JOIN dominios dom ON dom.id = d.dominio
         LEFT JOIN modelos  m   ON m.id   = d.modelo
         ORDER BY FIELD(
                      CASE
                          WHEN d.habilitado <> 1 THEN 'error'
                          WHEN d.enlace = 1      THEN 'online'
                          ELSE 'offline'
                      END,
                      'error', 'online', 'offline'
                  ),
                  d.nombre ASC"
    );

    $dispositivos = array_map(static function (array $r): array {
        $r['dominio_id'] = (int) $r['dominio_id'];
        return $r;
    }, $stmt->fetchAll());

    // Resumen del dashboard: cuenta solo dispositivos habilitados.
    // `estado === 'error'` proviene de `habilitado <> 1` (deshabilitados), no
    // de una condicion de falla real, asi que esos quedan fuera del total.
    $resumen = ['total' => 0, 'online' => 0, 'offline' => 0];

    foreach ($dispositivos as $d) {
        if ($d['estado'] === 'error') continue;
        $resumen['total']++;
        $resumen[$d['estado']]++;
    }

    json_ok([
        'resumen'      => $resumen,
        'dispositivos' => $dispositivos,
    ]);
}

/** Un registro con las 35 columnas + el nombre resuelto de cada FK. */
function handleGet(int $id): void
{
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(
        'SELECT d.*,
                dom.nombre AS dominio_nombre,
                a.nombre   AS agente_nombre,
                m.nombre   AS modelo_nombre,
                p.nombre   AS producto_nombre,
                t.nombre   AS transceptor_nombre,
                c.telefono AS chip_telefono,
                c.serie    AS chip_serie
           FROM dispositivos d
           LEFT JOIN dominios      dom ON dom.id = d.dominio
           LEFT JOIN agentes       a   ON a.id   = d.agente
           LEFT JOIN modelos       m   ON m.id   = d.modelo
           LEFT JOIN productos     p   ON p.id   = d.producto
           LEFT JOIN transceptores t   ON t.id   = d.transceptor
           LEFT JOIN chips         c   ON c.id   = d.chip
          WHERE d.id = :id
          LIMIT 1'
    );
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Dispositivo no encontrado', 404);

    json_ok([
        'dispositivo' => mapDispositivo($row),
        'catalogos'   => catalogos(),
    ]);
}

function handleCreate(): void
{
    $datos = validateDevicePayload(readJson(), null);

    $columnas = array_keys($datos);
    $stmt = db()->prepare(
        'INSERT INTO dispositivos (' . implode(', ', $columnas) . ')
         VALUES (:' . implode(', :', $columnas) . ')'
    );
    $stmt->execute(parametros($datos));

    json_ok(['id' => (int) db()->lastInsertId()]);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $exists = db()->prepare('SELECT 1 FROM dispositivos WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) json_error('Dispositivo no encontrado', 404);

    $datos = validateDevicePayload($in, $id);

    $sets = array_map(static fn(string $c): string => $c . ' = :' . $c, array_keys($datos));
    $stmt = db()->prepare('UPDATE dispositivos SET ' . implode(', ', $sets) . ' WHERE id = :id');
    $stmt->execute(parametros($datos) + [':id' => $id]);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM dispositivos WHERE id = :id');
    try {
        $stmt->execute([':id' => $id]);
    } catch (PDOException $e) {
        // Media docena de tablas (adopciones, canales, botones, controles,
        // etiquetas, usos) referencian dispositivos con ON DELETE RESTRICT.
        if ((int) ($e->errorInfo[1] ?? 0) === 1451) {
            json_error(
                'No se puede eliminar: el dispositivo tiene historial o configuracion asociada. Deshabilitalo en lugar de borrarlo.',
                409
            );
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) json_error('Dispositivo no encontrado', 404);

    json_ok(['id' => $id]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Fila completa normalizada para los modales de consulta y edicion. */
function mapDispositivo(array $r): array
{
    $out = ['id' => (int) $r['id']];

    foreach (array_keys(TEXTOS) as $campo) {
        $out[$campo] = (string) ($r[$campo] ?? '');
    }
    foreach (FECHAS as $campo) {
        // La base legacy tiene fechas cero: se muestran como "sin fecha".
        $valor = (string) ($r[$campo] ?? '');
        $out[$campo] = str_starts_with($valor, '0000-00-00') ? '' : $valor;
    }
    foreach (array_merge(array_keys(REFERENCIAS), ENTEROS, ['dominio']) as $campo) {
        $out[$campo] = $r[$campo] === null ? null : (int) $r[$campo];
    }
    foreach (BANDERAS as $campo) {
        $out[$campo] = (int) ($r[$campo] ?? 0) === 1;
    }

    foreach (['dominio_nombre', 'agente_nombre', 'modelo_nombre',
              'producto_nombre', 'transceptor_nombre'] as $campo) {
        $out[$campo] = (string) ($r[$campo] ?? '');
    }
    $out['chip_nombre'] = chipNombre($r['chip'] ?? null, $r['chip_telefono'] ?? null, $r['chip_serie'] ?? null);

    return $out;
}

/** Etiqueta legible de un chip: telefono, si no serie, si no el id. */
function chipNombre(mixed $id, mixed $telefono, mixed $serie): string
{
    if ($id === null) return '';
    $tel = trim((string) ($telefono ?? ''));
    if ($tel !== '') return $tel;
    $ser = trim((string) ($serie ?? ''));
    if ($ser !== '') return $ser;
    return '#' . (int) $id;
}

/**
 * Catalogos de los selects del alta / edicion. Todos son chicos (el mas
 * grande, `adopciones`, tiene 225 filas), asi que van enteros y sin paginar.
 */
function catalogos(): array
{
    $simple = static function (string $tabla): array {
        $stmt = db()->query('SELECT id, nombre FROM ' . $tabla . ' ORDER BY nombre ASC');
        return array_map(static fn(array $r): array => [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
        ], $stmt->fetchAll());
    };

    $chips = db()->query('SELECT id, telefono, serie FROM chips ORDER BY telefono ASC, serie ASC');

    // `adopciones` no tiene `nombre`: se etiqueta con la fecha de adopcion
    // para que la lista sea elegible a ojo.
    $adopciones = db()->query(
        'SELECT id, adoptado, vigente FROM adopciones ORDER BY id DESC'
    );

    return [
        'dominios'      => $simple('dominios'),
        'agentes'       => $simple('agentes'),
        'modelos'       => $simple('modelos'),
        'productos'     => $simple('productos'),
        'transceptores' => $simple('transceptores'),
        'chips'         => array_map(static fn(array $r): array => [
            'id'     => (int) $r['id'],
            'nombre' => chipNombre($r['id'], $r['telefono'], $r['serie']),
        ], $chips->fetchAll()),
        'adopciones'    => array_map(static function (array $r): array {
            $fecha = (string) ($r['adoptado'] ?? '');
            $fecha = str_starts_with($fecha, '0000-00-00') ? '' : substr($fecha, 0, 10);
            return [
                'id'     => (int) $r['id'],
                'nombre' => '#' . (int) $r['id']
                          . ($fecha !== '' ? ' · ' . $fecha : '')
                          . ((string) ($r['vigente'] ?? '') === '1' ? ' · vigente' : ''),
            ];
        }, $adopciones->fetchAll()),
    ];
}

/**
 * Valida el payload completo y devuelve `columna => valor` listo para el
 * INSERT / UPDATE. Corta con 422 al primer problema.
 */
function validateDevicePayload(array $in, ?int $idActual): array
{
    $datos = [];

    foreach (TEXTOS as $campo => $max) {
        $valor = trim((string) ($in[$campo] ?? ''));
        if (mb_strlen($valor) > $max) {
            json_error("El campo $campo no puede superar $max caracteres", 422);
        }
        $datos[$campo] = $valor === '' ? null : $valor;
    }

    if ($datos['uuid'] === null)   json_error('El identificador (UUID) es obligatorio', 422);
    if ($datos['nombre'] === null) json_error('El nombre es obligatorio', 422);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $datos['uuid'])) {
        json_error('El identificador solo admite letras, numeros y . _ -', 422);
    }

    // `uuid` identifica al equipo fisico y tiene que ser unico en toda la
    // tabla. La DB no tiene UNIQUE, asi que se valida aca.
    $dup = db()->prepare('SELECT id FROM dispositivos WHERE uuid = :u AND id <> :id LIMIT 1');
    $dup->execute([':u' => $datos['uuid'], ':id' => $idActual ?? 0]);
    if ($dup->fetchColumn()) {
        json_error('Ya existe un dispositivo con ese identificador', 409);
    }

    foreach (preg_split('/[,;\s]+/', (string) $datos['monitoreoCorreos'], -1, PREG_SPLIT_NO_EMPTY) ?: [] as $correo) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            json_error("El correo de monitoreo \"$correo\" no es valido", 422);
        }
    }

    // El dominio es obligatorio: ningun dispositivo vive fuera de uno (los
    // que no tienen dueño estan en el dominio pool, `Liberado`).
    $dominio = (int) ($in['dominio'] ?? 0);
    if ($dominio <= 0) json_error('Elegi un dominio', 422);
    $existe = db()->prepare('SELECT 1 FROM dominios WHERE id = :id');
    $existe->execute([':id' => $dominio]);
    if (!$existe->fetchColumn()) json_error('El dominio no existe', 422);
    $datos['dominio'] = $dominio;

    foreach (REFERENCIAS as $campo => $tabla) {
        $datos[$campo] = referencia($in, $campo, $tabla);
    }
    foreach (BANDERAS as $campo) {
        $datos[$campo] = !empty($in[$campo]) ? 1 : 0;
    }
    foreach (ENTEROS as $campo) {
        $datos[$campo] = enteroPositivo($in, $campo);
    }
    foreach (FECHAS as $campo) {
        $datos[$campo] = fechaHora($in, $campo);
    }

    return $datos;
}

/** `columna => valor` a `:columna => valor` para PDO. */
function parametros(array $datos): array
{
    $out = [];
    foreach ($datos as $columna => $valor) {
        $out[':' . $columna] = $valor;
    }
    return $out;
}

/**
 * Valida que el id apunte a una fila existente del catalogo. 0 / vacio =>
 * NULL: en esta base el 0 es el centinela historico de "sin asignar", no
 * una referencia, y dejarlo pasar rompe la foreign key.
 */
function referencia(array $in, string $campo, string $tabla): ?int
{
    $id = (int) ($in[$campo] ?? 0);
    if ($id <= 0) return null;

    $stmt = db()->prepare('SELECT 1 FROM ' . $tabla . ' WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) json_error("El $campo seleccionado no existe", 422);

    return $id;
}

function enteroPositivo(array $in, string $campo): ?int
{
    $valor = $in[$campo] ?? '';
    if ($valor === '' || $valor === null) return null;

    $n = (int) $valor;
    if ($n < 0) json_error("El campo $campo no puede ser negativo", 422);

    return $n;
}

/** Acepta 'YYYY-MM-DDTHH:MM' (input datetime-local), 'YYYY-MM-DD HH:MM:SS' o 'YYYY-MM-DD'. */
function fechaHora(array $in, string $campo): ?string
{
    $valor = trim((string) ($in[$campo] ?? ''));
    // La base legacy tiene fechas cero: se tratan como "sin fecha", no como error.
    if ($valor === '' || str_starts_with($valor, '0000-00-00')) return null;

    $valor = str_replace('T', ' ', $valor);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $valor, $m)) {
        json_error("La fecha de $campo no es valida", 422);
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error("La fecha de $campo no existe en el calendario", 422);
    }

    return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
