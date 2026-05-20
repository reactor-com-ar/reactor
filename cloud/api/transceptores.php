<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

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
    json_error('Error al procesar transceptores: ' . $e->getMessage(), 500);
}

/**
 * Listado de transceptores (db/schema.sql -> tabla `transceptores`).
 *
 * Campos reales: id, nombre, host, puerto, usuario, contrasena, entrada.
 * No hay timestamps ni estado. `senales.transceptor` es FK logica (sin
 * constraint) a `transceptores.id`; se cuenta por LEFT JOIN para mostrar
 * cuantas senales referencian a cada transceptor y bloquear el delete
 * cuando hay registros asociados.
 *
 * `contrasena` no se expone nunca en la respuesta: solo se devuelve un
 * booleano `tiene_contrasena`. En el modal de edicion, dejar el campo
 * vacio mantiene la contrasena actual.
 */
function handleList(): void
{
    $stmt = db()->query(
        "SELECT t.id,
                t.nombre,
                t.host,
                t.puerto,
                t.usuario,
                t.entrada,
                CASE WHEN t.contrasena IS NULL OR t.contrasena = ''
                     THEN 0 ELSE 1 END                 AS tiene_contrasena,
                COALESCE(s.cnt, 0)                     AS senales_count
         FROM transceptores t
         LEFT JOIN (
             SELECT transceptor, COUNT(*) AS cnt
             FROM senales
             WHERE transceptor IS NOT NULL
             GROUP BY transceptor
         ) s ON s.transceptor = t.id
         ORDER BY t.nombre ASC, t.id ASC"
    );

    $transceptores = array_map(static function (array $r): array {
        $r['id']                = (int) $r['id'];
        $r['senales_count']     = (int) $r['senales_count'];
        $r['tiene_contrasena']  = (bool) $r['tiene_contrasena'];
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'            => count($transceptores),
        'con_credenciales' => 0,
        'con_senales'      => 0,
    ];
    foreach ($transceptores as $t) {
        if ($t['tiene_contrasena'] && $t['usuario']) {
            $resumen['con_credenciales']++;
        }
        if ($t['senales_count'] > 0) {
            $resumen['con_senales']++;
        }
    }

    json_ok([
        'resumen'       => $resumen,
        'transceptores' => $transceptores,
    ]);
}

function handleCreate(): void
{
    $data = validateTransceptorPayload(readJson(), false);

    $stmt = db()->prepare(
        'INSERT INTO transceptores (nombre, host, puerto, usuario, contrasena, entrada)
         VALUES (:n, :h, :p, :u, :c, :e)'
    );
    $stmt->execute([
        ':n' => $data['nombre'],
        ':h' => $data['host'],
        ':p' => $data['puerto'],
        ':u' => $data['usuario'],
        ':c' => $data['contrasena'],
        ':e' => $data['entrada'],
    ]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $data = validateTransceptorPayload($in, true);

    // contrasena: si llega vacia/no provista, mantener la actual.
    if ($data['contrasena'] === null) {
        $stmt = db()->prepare(
            'UPDATE transceptores
                SET nombre = :n, host = :h, puerto = :p,
                    usuario = :u, entrada = :e
              WHERE id = :id'
        );
        $stmt->execute([
            ':n'  => $data['nombre'],
            ':h'  => $data['host'],
            ':p'  => $data['puerto'],
            ':u'  => $data['usuario'],
            ':e'  => $data['entrada'],
            ':id' => $id,
        ]);
    } else {
        $stmt = db()->prepare(
            'UPDATE transceptores
                SET nombre = :n, host = :h, puerto = :p,
                    usuario = :u, contrasena = :c, entrada = :e
              WHERE id = :id'
        );
        $stmt->execute([
            ':n'  => $data['nombre'],
            ':h'  => $data['host'],
            ':p'  => $data['puerto'],
            ':u'  => $data['usuario'],
            ':c'  => $data['contrasena'],
            ':e'  => $data['entrada'],
            ':id' => $id,
        ]);
    }

    // La tabla `transceptores` es MyISAM y rowCount() devuelve 0 cuando el
    // UPDATE no cambia ningun valor. Verificar existencia por separado para
    // poder devolver 404 cuando corresponda sin falsos negativos.
    $exists = db()->prepare('SELECT 1 FROM transceptores WHERE id = :id');
    $exists->execute([':id' => $id]);
    if (!$exists->fetchColumn()) json_error('Transceptor no encontrado', 404);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    // FK logica (sin constraint en el esquema): bloquear el borrado a mano
    // si hay senales que apuntan a este transceptor.
    $ref = db()->prepare('SELECT COUNT(*) FROM senales WHERE transceptor = :id');
    $ref->execute([':id' => $id]);
    $cnt = (int) $ref->fetchColumn();
    if ($cnt > 0) {
        json_error(
            "No se puede eliminar: el transceptor tiene {$cnt} senal(es) asociada(s).",
            409
        );
    }

    $stmt = db()->prepare('DELETE FROM transceptores WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Transceptor no encontrado', 404);

    json_ok(['id' => $id]);
}

function validateTransceptorPayload(array $in, bool $isUpdate): array
{
    $nombre  = trim((string) ($in['nombre']  ?? ''));
    $host    = trim((string) ($in['host']    ?? ''));
    $puerto  = trim((string) ($in['puerto']  ?? ''));
    $usuario = trim((string) ($in['usuario'] ?? ''));
    $entrada = trim((string) ($in['entrada'] ?? ''));

    // En edicion, contrasena vacia/ausente => mantener la actual (handleUpdate
    // omite la columna). En alta, contrasena vacia => NULL (sin contrasena).
    $contrasenaRaw = $in['contrasena'] ?? '';
    $contrasena    = is_string($contrasenaRaw) ? trim($contrasenaRaw) : '';

    if ($nombre === '')              json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 255)    json_error('El nombre no puede superar 255 caracteres', 422);
    if ($host === '')                json_error('El host es obligatorio', 422);
    if (mb_strlen($host)    > 255)   json_error('El host no puede superar 255 caracteres', 422);
    if ($puerto === '')              json_error('El puerto es obligatorio', 422);
    if (!ctype_digit($puerto) || (int) $puerto < 1 || (int) $puerto > 65535) {
        json_error('El puerto debe ser un numero entre 1 y 65535', 422);
    }
    if (mb_strlen($usuario) > 255)   json_error('El usuario no puede superar 255 caracteres', 422);
    if (mb_strlen($entrada) > 255)   json_error('La entrada no puede superar 255 caracteres', 422);
    if (mb_strlen($contrasena) > 255)
        json_error('La contrasena no puede superar 255 caracteres', 422);

    // En edicion, null se interpreta como "no tocar la columna" (handleUpdate
    // omite el SET de contrasena). En alta, null se persiste como NULL.
    $contrasenaOut = $contrasena === '' ? null : $contrasena;

    return [
        'nombre'     => $nombre,
        'host'       => $host,
        'puerto'     => $puerto,
        'usuario'    => $usuario === '' ? null : $usuario,
        'contrasena' => $contrasenaOut,
        'entrada'    => $entrada === '' ? null : $entrada,
    ];
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
