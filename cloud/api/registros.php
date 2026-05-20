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
    json_error('Error al procesar registros: ' . $e->getMessage(), 500);
}

/**
 * Listado de registros (db/schema.sql -> tabla `registros`).
 *
 * Campos reales: id, fecha, sentido, usuario, dominio, dispositivo,
 * canal, estado. Las FKs `dispositivo`, `dominio` y `usuario` se
 * resuelven con LEFT JOIN para exponer nombre/uuid en el listado.
 * Sin indices en columnas distintas de la PK -- los filtros se aplican
 * luego del ORDER BY id DESC + LIMIT.
 */
function handleList(): void
{
    $dispositivo = isset($_GET['dispositivo']) ? (int) $_GET['dispositivo'] : 0;
    $limit       = isset($_GET['limit']) ? (int) $_GET['limit'] : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    $sql = 'SELECT r.id, r.fecha, r.sentido, r.usuario, r.dominio,
                   r.dispositivo, r.canal, r.estado,
                   d.uuid                    AS dispositivo_uuid,
                   d.nombre                  AS dispositivo_nombre,
                   COALESCE(dom.nombre, "—") AS dominio_nombre,
                   u.nombre                  AS usuario_nombre,
                   u.usuario                 AS usuario_login
            FROM registros r
            LEFT JOIN dispositivos d   ON d.id   = r.dispositivo
            LEFT JOIN dominios     dom ON dom.id = r.dominio
            LEFT JOIN usuarios     u   ON u.id   = r.usuario';

    $params = [];
    if ($dispositivo > 0) {
        $sql .= ' WHERE r.dispositivo = :did';
        $params[':did'] = $dispositivo;
    }
    $sql .= ' ORDER BY r.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $registros = array_map(static function (array $r): array {
        $r['id']          = (int) $r['id'];
        $r['usuario']     = $r['usuario']     !== null ? (int) $r['usuario']     : null;
        $r['dominio']     = $r['dominio']     !== null ? (int) $r['dominio']     : null;
        $r['dispositivo'] = $r['dispositivo'] !== null ? (int) $r['dispositivo'] : null;
        $r['canal']       = $r['canal']       !== null ? (int) $r['canal']       : null;
        return $r;
    }, $stmt->fetchAll());

    $desde24h = (new DateTimeImmutable('-24 hours'))->format('Y-m-d H:i:s');
    $desdeHoy = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');

    $total = (int) db()->query('SELECT COUNT(*) FROM registros')->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM registros WHERE fecha >= :t');
    $stmt->execute([':t' => $desde24h]);
    $ultimas24h = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(*) FROM registros WHERE fecha >= :t');
    $stmt->execute([':t' => $desdeHoy]);
    $hoy = (int) $stmt->fetchColumn();

    $stmt = db()->prepare('SELECT COUNT(DISTINCT dispositivo) FROM registros WHERE fecha >= :t');
    $stmt->execute([':t' => $desde24h]);
    $dispositivosActivos = (int) $stmt->fetchColumn();

    $resumen = [
        'total'                => $total,
        'ultimas_24h'          => $ultimas24h,
        'hoy'                  => $hoy,
        'dispositivos_activos' => $dispositivosActivos,
    ];

    json_ok([
        'resumen'   => $resumen,
        'registros' => $registros,
        'limit'     => $limit,
    ]);
}
