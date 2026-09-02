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
    json_error('Error al procesar adopciones: ' . $e->getMessage(), 500);
}

// NOTA sobre el centinela de fecha: el esquema histórico no usa NULL para
// "todavía no pasó". Las adopciones en curso llevan `liberado` =
// '1500-01-01 00:00:00' (y `liberador` sí queda en NULL). Las consultas de
// abajo cortan en '1900-01-01': todo lo anterior se normaliza a NULL en la
// respuesta para que el front muestre "Sin liberar" y no un 1/1/1500, y para
// que el KPI de liberadas no cuente las que siguen vigentes. Mismo espíritu
// que el centinela 0 de las FKs en el resto del repo.

/**
 * Listado de adopciones (db/schema.sql -> tabla `adopciones`).
 *
 * Campos reales: id, dispositivo, dominio, adoptado, adoptador, liberado,
 * liberador, vigente. Registra el ciclo de vida de un dispositivo dentro de
 * un dominio: `adoptador` es el usuario que lo adoptó (en `adoptado`) y
 * `liberador` el que lo liberó después (en `liberado`); mientras la adopción
 * sigue en curso `liberado`/`liberador` vienen sin valor real.
 *
 * Las FKs `dispositivo`, `dominio`, `adoptador` y `liberador` se resuelven
 * con LEFT JOIN para exponer nombre/uuid/login en el listado. `usuarios` se
 * joinea dos veces (ua = adoptador, ul = liberador).
 *
 * `vigente` es varchar(1) en el esquema histórico y en los datos reales toma
 * '1' (en curso) / '0' (liberada): se normaliza al booleano `activa` con el
 * mismo criterio que usuarios.habilitado (S / 1 / Y).
 */
function handleList(): void
{
    $dispositivo = isset($_GET['dispositivo']) ? (int) $_GET['dispositivo'] : 0;
    $dominio     = isset($_GET['dominio'])     ? (int) $_GET['dominio']     : 0;
    $limit       = isset($_GET['limit'])       ? (int) $_GET['limit']       : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    // Filtro opcional por vigencia: 'S' (en curso) / 'N' (ya liberadas).
    $vigente = isset($_GET['vigente']) ? strtoupper(trim((string) $_GET['vigente'])) : '';
    if ($vigente !== 'S' && $vigente !== 'N') $vigente = '';

    $sql = "SELECT a.id, a.dispositivo, a.dominio, a.adoptador, a.liberador, a.vigente,
                   CASE WHEN a.adoptado < '1900-01-01' THEN NULL ELSE a.adoptado END AS adoptado,
                   CASE WHEN a.liberado < '1900-01-01' THEN NULL ELSE a.liberado END AS liberado,
                   d.uuid                    AS dispositivo_uuid,
                   d.nombre                  AS dispositivo_nombre,
                   COALESCE(dom.nombre, '—') AS dominio_nombre,
                   ua.nombre                 AS adoptador_nombre,
                   ua.usuario                AS adoptador_login,
                   ul.nombre                 AS liberador_nombre,
                   ul.usuario                AS liberador_login
            FROM adopciones a
            LEFT JOIN dispositivos d   ON d.id   = a.dispositivo
            LEFT JOIN dominios     dom ON dom.id = a.dominio
            LEFT JOIN usuarios     ua  ON ua.id  = a.adoptador
            LEFT JOIN usuarios     ul  ON ul.id  = a.liberador";

    $where  = [];
    $params = [];
    if ($dispositivo > 0) {
        $where[]        = 'a.dispositivo = :did';
        $params[':did'] = $dispositivo;
    }
    if ($dominio > 0) {
        $where[]         = 'a.dominio = :dom';
        $params[':dom']  = $dominio;
    }
    if ($vigente === 'S') {
        $where[] = "UPPER(a.vigente) IN ('S', '1', 'Y')";
    } elseif ($vigente === 'N') {
        $where[] = "(a.vigente IS NULL OR UPPER(a.vigente) NOT IN ('S', '1', 'Y'))";
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    // Sin índice sobre `adoptado`: se ordena por PK (equivalente cronológico)
    // y se acota con LIMIT antes de aplicar el resto de los filtros client-side.
    $sql .= ' ORDER BY a.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $adopciones = array_map(static function (array $r): array {
        $r['id']          = (int) $r['id'];
        $r['dispositivo'] = $r['dispositivo'] !== null ? (int) $r['dispositivo'] : null;
        $r['dominio']     = $r['dominio']     !== null ? (int) $r['dominio']     : null;
        $r['adoptador']   = $r['adoptador']   !== null ? (int) $r['adoptador']   : null;
        $r['liberador']   = $r['liberador']   !== null ? (int) $r['liberador']   : null;
        $r['activa']      = in_array(strtoupper((string) ($r['vigente'] ?? '')), ['S', '1', 'Y'], true);
        return $r;
    }, $stmt->fetchAll());

    $total = (int) db()->query('SELECT COUNT(*) FROM adopciones')->fetchColumn();

    $vigentes = (int) db()->query(
        "SELECT COUNT(*) FROM adopciones WHERE UPPER(vigente) IN ('S', '1', 'Y')"
    )->fetchColumn();

    // Sólo las que tienen fecha de liberación real (descarta el centinela 1500).
    $liberadas = (int) db()->query(
        "SELECT COUNT(*) FROM adopciones WHERE liberado IS NOT NULL AND liberado >= '1900-01-01'"
    )->fetchColumn();

    $dispositivosAdoptados = (int) db()->query(
        'SELECT COUNT(DISTINCT dispositivo) FROM adopciones WHERE dispositivo IS NOT NULL'
    )->fetchColumn();

    $resumen = [
        'total'        => $total,
        'vigentes'     => $vigentes,
        'liberadas'    => $liberadas,
        'dispositivos' => $dispositivosAdoptados,
    ];

    json_ok([
        'resumen'    => $resumen,
        'adopciones' => $adopciones,
        'limit'      => $limit,
    ]);
}
