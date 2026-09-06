<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Claves de `combos` con los textos de los codigos cortos de `dominios`.
 * Son las mismas que usaba comboTraducir() en el legacy, y las mismas que ya
 * lee panel/api/dominio.php: el codigo tiene que leerse igual en las tres
 * pantallas.
 */
const COMBO_SITUACION        = '$xDominio->situacion';
const COMBO_AUTOADMINISTRADO = '$xDominio->autoadministrado';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_SITUACION        => ['1' => 'Normal', '2' => 'Limitado', '3' => 'Suspendido'],
    COMBO_AUTOADMINISTRADO => ['1' => 'Si',     '0' => 'No'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar dominios: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Devuelve la fila entera de `dominios` (db/schema.sql), no un recorte: el
    // modal de Consultar los muestra todos. Ojo con lo que NO existe en el
    // esquema real, por mas que el modal de Alta/Edicion siga escribiendolos:
    // no hay `descripcion`, `created_at` ni `updated_at`. Y la FK en
    // `dispositivos`, `chips`, `perfiles`, `usos` y `paneles` es la columna
    // `dominio` -- no `dominio_id`.
    //
    // Los cinco contadores se CALCULAN, no se leen de las columnas cacheadas
    // `dominios`.`usuarios` / `.dispositivos` / `.chips` / `.usos` / `.paneles`:
    // ese cache lo mantiene a mano el sistema legacy y esta desfasado en 23 de
    // los 148 dominios -- el alta suma y la baja no resta (el dominio 2 declara
    // 18 usuarios y tiene 13), y en `usos` la diferencia es de otro orden (el
    // dominio 1 declara 0 y tiene 1.294). Es la misma decision que ya toman
    // panel/api/dominio.php y panel/api/dashboard.php. El COUNT es barato: las
    // cinco FKs tienen indice y la consulta entera corre en ~25 ms.
    //
    // Subconsultas correlacionadas y no cinco LEFT JOIN: con JOINs las filas se
    // multiplican entre si (10 dispositivos x 3 chips = 30 filas) y cada COUNT
    // devolveria el producto en vez de su propio total. Los dos LEFT JOIN que
    // si estan son 1:1 por PK, asi que no multiplican nada.
    //
    // COUNT(DISTINCT p.usuario) porque un usuario puede tener mas de un perfil
    // en el mismo dominio -- hoy hay 8 pares (usuario, dominio) repetidos, que
    // sin el DISTINCT se contarian dos veces.
    $stmt = db()->query(
        'SELECT d.id,
                d.uuid,
                d.nombre,
                d.numero,
                d.agente,
                ag.nombre AS agente_nombre,
                d.cliente,
                cl.nombre AS cliente_nombre,
                d.contrato,
                d.autoadministrado,
                d.situacion,
                d.habilitado,
                (SELECT COUNT(DISTINCT p.usuario) FROM perfiles     p   WHERE p.dominio   = d.id) AS usuarios_count,
                (SELECT COUNT(*)                  FROM dispositivos dev WHERE dev.dominio = d.id) AS dispositivos_count,
                (SELECT COUNT(*)                  FROM chips        c   WHERE c.dominio   = d.id) AS chips_count,
                (SELECT COUNT(*)                  FROM usos         u   WHERE u.dominio   = d.id) AS usos_count,
                (SELECT COUNT(*)                  FROM paneles      pa  WHERE pa.dominio  = d.id) AS paneles_count
         FROM dominios d
         LEFT JOIN agentes  ag ON ag.id = d.agente
         LEFT JOIN clientes cl ON cl.id = d.cliente
         ORDER BY d.nombre ASC'
    );

    $dominios = array_map(static function (array $r): array {
        $situacion       = trim((string) ($r['situacion'] ?? ''));
        $autoadministrado = trim((string) ($r['autoadministrado'] ?? ''));

        return [
            'id'                     => (int) $r['id'],
            'uuid'                   => trim((string) ($r['uuid'] ?? '')),
            'nombre'                 => trim((string) ($r['nombre'] ?? '')),
            'numero'                 => trim((string) ($r['numero'] ?? '')),
            // El 0 de estas FKs es el centinela "sin asignar" del sistema
            // historico, no un id: se normaliza a null como el NULL real.
            'agente'                 => idOrNull($r['agente']),
            'agente_nombre'          => trim((string) ($r['agente_nombre'] ?? '')),
            'cliente'                => idOrNull($r['cliente']),
            'cliente_nombre'         => trim((string) ($r['cliente_nombre'] ?? '')),
            'contrato'               => idOrNull($r['contrato']),
            'autoadministrado'       => $autoadministrado,
            'autoadministrado_texto' => combo(COMBO_AUTOADMINISTRADO)[$autoadministrado] ?? '',
            'situacion'              => $situacion,
            'situacion_texto'        => combo(COMBO_SITUACION)[$situacion] ?? '',
            // `dominios`.`habilitado` es tinyint(1) NOT NULL: ya es 0 o 1.
            'habilitado'             => esHabilitado($r['habilitado']) ? 1 : 0,
            'usuarios_count'         => (int) $r['usuarios_count'],
            'dispositivos_count'     => (int) $r['dispositivos_count'],
            'chips_count'            => (int) $r['chips_count'],
            'usos_count'             => (int) $r['usos_count'],
            'paneles_count'          => (int) $r['paneles_count'],
        ];
    }, $stmt->fetchAll());

    json_ok(['dominios' => $dominios]);
}

function handleCreate(): void
{
    $in     = readJson();
    $nombre = trim((string) ($in['nombre']      ?? ''));
    $desc   = trim((string) ($in['descripcion'] ?? ''));

    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)    json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('INSERT INTO dominios (nombre, descripcion) VALUES (:n, :d)');
        $stmt->execute([':n' => $nombre, ':d' => $desc === '' ? null : $desc]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un dominio con ese nombre', 409);
        }
        throw $e;
    }

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in     = readJson();
    $id     = (int) ($in['id'] ?? 0);
    $nombre = trim((string) ($in['nombre']      ?? ''));
    $desc   = trim((string) ($in['descripcion'] ?? ''));

    if ($id <= 0)                    json_error('Id invalido', 422);
    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)    json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($desc) > 255)      json_error('La descripcion no puede superar 255 caracteres', 422);

    try {
        $stmt = db()->prepare('UPDATE dominios SET nombre = :n, descripcion = :d WHERE id = :id');
        $stmt->execute([':n' => $nombre, ':d' => $desc === '' ? null : $desc, ':id' => $id]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un dominio con ese nombre', 409);
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM dominios WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Dominio no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0)    json_error('Id invalido', 422);
    if ($id === 1)   json_error('No se puede eliminar el dominio por defecto "General"', 409);

    $pdo = db();

    $exists = $pdo->prepare('SELECT 1 FROM dominios WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) json_error('Dominio no encontrado', 404);

    try {
        $pdo->beginTransaction();

        // Reasignar dispositivos y chips al dominio por defecto "General" (id = 1).
        $pdo->prepare('UPDATE dispositivos SET dominio_id = 1 WHERE dominio_id = :id')
            ->execute([':id' => $id]);
        $pdo->prepare('UPDATE chips        SET dominio_id = 1 WHERE dominio_id = :id')
            ->execute([':id' => $id]);

        // Los perfiles son permisos por dominio: si el dominio desaparece, se borran.
        $pdo->prepare('DELETE FROM perfiles WHERE dominio_id = :id')
            ->execute([':id' => $id]);

        $pdo->prepare('DELETE FROM dominios WHERE id = :id')
            ->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id]);
}

/**
 * Tabla plana valor -> texto de un combo del sistema historico.
 * Se lee una sola vez por request y por clave.
 */
function combo(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }

    $stmt = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC');
    $stmt->execute([':c' => $clave]);

    $textos = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') continue;
        $textos[$valor] = (string) ($r['texto'] ?? '');
    }

    if ($textos === []) {
        $textos = COMBOS_FALLBACK[$clave] ?? [];
    }

    return $cache[$clave] = $textos;
}

/**
 * Id de una FK del sistema historico, donde el 0 significa "sin asignar"
 * igual que el NULL (ver el criterio del `0` centinela en db/schema.sql).
 */
function idOrNull(mixed $v): ?int
{
    $id = (int) $v;
    return $id > 0 ? $id : null;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
