<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido', 405);
    }
    handleLive();
} catch (Throwable $e) {
    json_error('Error en feed en vivo: ' . $e->getMessage(), 500);
}

/**
 * Feed liviano de senales para el dashboard.
 *
 *   GET /api/signals_live.php?since_id=N&limit=20
 *
 * Devuelve las senales con id > since_id, ordenadas por id DESC, hasta
 * `limit` filas. No incluye resumen ni COUNTs: pensado para polling
 * agresivo (500 ms) desde la card "Senales en vivo" del dashboard.
 *
 * Mismos JOINs que signals.php (db/schema.sql -> tabla `senales`) para
 * resolver nombres de dispositivo, dominio y transceptor. El WHERE
 * sobre la PK + LIMIT mantiene el costo acotado aunque la tabla no
 * tenga indices secundarios.
 */
function handleLive(): void
{
    $sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
    if ($sinceId < 0) $sinceId = 0;

    $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
    if ($limit <= 0 || $limit > 100) $limit = 20;

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
            LEFT JOIN transceptores t   ON t.id   = s.transceptor
            WHERE s.id > :since
            ORDER BY s.id DESC
            LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute([':since' => $sinceId]);

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

    $lastId = $sinceId;
    foreach ($senales as $s) {
        if ($s['id'] > $lastId) $lastId = $s['id'];
    }

    json_ok([
        'senales' => $senales,
        'last_id' => $lastId,
    ]);
}
