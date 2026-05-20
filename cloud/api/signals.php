<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido', 405);
    }
    handleList();
} catch (Throwable $e) {
    json_error('Error al procesar senales: ' . $e->getMessage(), 500);
}

/**
 * Listado de senales (db/schema.sql -> tabla `senales`).
 *
 * Campos reales: id, serie, fecha, sentido, transceptor, dispositivo,
 * canal, topic, mensaje, estado. El campo `dispositivo` es FK a
 * `dispositivos.id`; `dispositivos.dominio` es FK a `dominios.id`;
 * `transceptor` es FK a `transceptores.id`. Sin indices en columnas
 * distintas de la PK -- los filtros se aplican luego del ORDER BY id
 * DESC + LIMIT, y se sugiere agregar indices por `dispositivo` y
 * `fecha` si el volumen crece.
 */
function handleList(): void
{
    $dispositivo = isset($_GET['dispositivo']) ? (int) $_GET['dispositivo'] : 0;
    $limit       = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    $sql = 'SELECT s.id, s.serie, s.fecha, s.sentido, s.transceptor,
                   s.dispositivo, s.canal, s.topic, s.mensaje, s.estado,
                   d.uuid                    AS dispositivo_uuid,
                   d.nombre                  AS dispositivo_nombre,
                   d.dominio                 AS dominio_id,
                   COALESCE(dom.nombre, "—") AS dominio_nombre,
                   t.nombre                  AS transceptor_nombre
            FROM senales s
            LEFT JOIN dispositivos  d   ON d.id   = s.dispositivo
            LEFT JOIN dominios      dom ON dom.id = d.dominio
            LEFT JOIN transceptores t   ON t.id   = s.transceptor';

    $params = [];
    if ($dispositivo > 0) {
        $sql .= ' WHERE s.dispositivo = :did';
        $params[':did'] = $dispositivo;
    }
    $sql .= ' ORDER BY s.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $senales = array_map(static function (array $r): array {
        $r['id']          = (int) $r['id'];
        $r['serie']       = $r['serie']       !== null ? (int) $r['serie']       : null;
        $r['transceptor'] = $r['transceptor'] !== null ? (int) $r['transceptor'] : null;
        $r['dispositivo'] = $r['dispositivo'] !== null ? (int) $r['dispositivo'] : null;
        $r['canal']       = $r['canal']       !== null ? (int) $r['canal']       : null;
        $r['estado']      = $r['estado']      !== null ? (int) $r['estado']      : null;
        $r['dominio_id']  = $r['dominio_id']  !== null ? (int) $r['dominio_id']  : null;
        return $r;
    }, $stmt->fetchAll());

    $desde24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
    $desdeHoy = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');

    $total = (int) db()->query('SELECT COUNT(*) FROM senales')->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM senales WHERE fecha >= :t');
    $stmt->execute([':t' => $desde24h]);
    $ultimas24h = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM senales WHERE fecha >= :t');
    $stmt->execute([':t' => $desdeHoy]);
    $hoy = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(DISTINCT dispositivo) FROM senales WHERE fecha >= :t');
    $stmt->execute([':t' => $desde24h]);
    $dispositivosActivos = (int) $stmt->fetchColumn();

    $resumen = [
        'total'                => $total,
        'ultimas_24h'          => $ultimas24h,
        'hoy'                  => $hoy,
        'dispositivos_activos' => $dispositivosActivos,
    ];

    json_ok([
        'resumen' => $resumen,
        'senales' => $senales,
        'limit'   => $limit,
    ]);
}
