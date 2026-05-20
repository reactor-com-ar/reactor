<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido', 405);
    }
    handleStats();
} catch (Throwable $e) {
    json_error('Error al obtener estadisticas: ' . $e->getMessage(), 500);
}

/**
 * Estadisticas agregadas de senales para el grafico en tiempo real del
 * dashboard.
 *
 *   GET /api/signals_stats.php
 *
 * Devuelve 1440 buckets de 1 minuto cubriendo las ultimas 24 horas
 * (ventana movil que termina en el minuto en curso), cada uno con la
 * cantidad de senales recibidas. Arreglo siempre de 1440 elementos en
 * orden cronologico ascendente; minutos sin senales con `count = 0`.
 *
 * == Estrategia de cache ==
 *
 * `senales` (db/schema.sql) tiene ~35M filas en MyISAM sin indice sobre
 * `fecha`. Un GROUP BY por minuto sobre 24 h haria full scan en cada
 * poll. Como las senales son inmutables (solo se insertan), el count de
 * cualquier minuto pasado es estable de por vida => se materializa en
 * `senales_por_minuto` (PK = minuto, ver migracion
 * 2026-05-20_senales_por_minuto.sql).
 *
 * Flujo por poll:
 *
 *   1. Leer `MAX(minuto)` de `senales_por_minuto`.
 *   2. Si faltan minutos cerrados desde ese punto hasta el minuto
 *      anterior al actual, agregarlos:
 *        - GROUP BY sobre `senales` filtrado por `id > pivot`
 *          (pivot = MAX(id) - LOOKBACK, para evitar full scan). El
 *          LOOKBACK se dimensiona segun cuantos minutos faltan, para
 *          que el bootstrap de 24 h no se quede corto y el poll tibio
 *          no escanee de mas.
 *        - INSERT IGNORE de los counts + zero-fill de minutos vacios.
 *   3. Leer los 1439 minutos cerrados desde el cache (PK range,
 *      instantaneo).
 *   4. Contar EN VIVO el minuto en curso (no se cachea, es volatil) con
 *      un pivot por PK chico (lookback fijo).
 *   5. Mezclar y devolver.
 *
 * Tras el bootstrap inicial (1 sola corrida pesada de 24 h sobre
 * `senales`), cada poll solo cuenta el minuto en curso + agrega ~0/1
 * fila al cache. El historico nunca mas se re-escanea.
 */
function handleStats(): void
{
    // Buckets totales de la ventana: 24 h * 60 min.
    $winMinutes = 1440;

    // Lookback fijo para el conteo del minuto en curso. ~50 senales/seg
    // sostenidas durante 60 min => 200k IDs es holgado para 1 solo
    // minuto.
    $livePkLookback = 200_000;

    // Heuristica de IDs/min para dimensionar el lookback del fillRange:
    // ~3500 ids/min (~58/seg sostenidas) cubre tasas realistas con
    // margen. Si el gap a fillear son N minutos, escaneamos N * 3500
    // IDs hacia atras. En poll tibio el gap es 0 o 1 => lookback chico.
    // En el bootstrap inicial (gap = 1440 min) escala a ~5M, que sigue
    // siendo mucho menos que los 35M de la tabla y es un PK range scan.
    $idsPerMinuteHeuristic = 3500;

    $now      = new DateTimeImmutable('now');
    $endMin   = $now->setTime((int) $now->format('H'), (int) $now->format('i'), 0);
    $startMin = $endMin->sub(new DateInterval('PT' . ($winMinutes - 1) . 'M'));

    $db = db();

    // --- 1) Detectar el rango de minutos a fillear ---
    $cacheMax = $db->query('SELECT MAX(minuto) FROM senales_por_minuto')->fetchColumn();
    $cacheMax = $cacheMax !== false && $cacheMax !== null
        ? new DateTimeImmutable((string) $cacheMax)
        : null;

    // Solo cacheamos minutos *cerrados*: hasta el minuto anterior al
    // actual. El minuto en curso nunca entra al cache.
    $fillEnd = $endMin->sub(new DateInterval('PT1M'));

    // Punto de partida del fill: o el primero que falta despues del
    // cache, o el inicio de la ventana de 24 h si el cache esta
    // detras. No tiene sentido cachear minutos anteriores a `startMin`
    // (no los miramos en el grafico).
    if ($cacheMax !== null && $cacheMax >= $startMin) {
        $fillStart = $cacheMax->add(new DateInterval('PT1M'));
    } else {
        $fillStart = $startMin;
    }

    if ($fillStart <= $fillEnd) {
        // Minutos en el gap (al menos 1 porque pasamos el if).
        $gapMinutes = (int) (($fillEnd->getTimestamp() - $fillStart->getTimestamp()) / 60) + 1;
        // Lookback proporcional al gap, con piso de 200k para gaps chicos.
        $fillPkLookback = max(200_000, $gapMinutes * $idsPerMinuteHeuristic);
        fillRange($db, $fillStart, $fillEnd, $fillPkLookback);
    }

    // --- 2) Leer los minutos cerrados desde el cache ---
    $stmt = $db->prepare(
        'SELECT minuto, cantidad
         FROM senales_por_minuto
         WHERE minuto BETWEEN :desde AND :hasta'
    );
    $stmt->execute([
        ':desde' => $startMin->format('Y-m-d H:i:s'),
        ':hasta' => $fillEnd->format('Y-m-d H:i:s'),
    ]);
    $cached = [];
    foreach ($stmt->fetchAll() as $row) {
        $cached[(string) $row['minuto']] = (int) $row['cantidad'];
    }

    // --- 3) Contar EN VIVO el minuto en curso ---
    $maxId = (int) $db->query('SELECT MAX(id) FROM senales')->fetchColumn();
    $pivot = max(0, $maxId - $livePkLookback);

    $stmt = $db->prepare(
        'SELECT COUNT(*) FROM senales
         WHERE id > :pivot AND fecha >= :desde AND fecha <= :hasta'
    );
    $stmt->execute([
        ':pivot' => $pivot,
        ':desde' => $endMin->format('Y-m-d H:i:s'),
        ':hasta' => $endMin->format('Y-m-d H:i:59'),
    ]);
    $currentCount = (int) $stmt->fetchColumn();

    // --- 4) Ensamblar los buckets en orden cronologico ---
    $buckets = [];
    $cursor  = $startMin;
    for ($i = 0; $i < $winMinutes; $i++) {
        $key       = $cursor->format('Y-m-d H:i:00');
        $isCurrent = $cursor == $endMin;
        $count     = $isCurrent
            ? $currentCount
            : ($cached[$key] ?? 0);
        $buckets[] = [
            'minuto' => $cursor->format('H:i'),
            'fecha'  => $key,
            'count'  => $count,
        ];
        $cursor = $cursor->add(new DateInterval('PT1M'));
    }

    $total  = array_sum(array_column($buckets, 'count'));
    $maxVal = $buckets ? max(array_column($buckets, 'count')) : 0;
    $avg    = $total > 0 ? round($total / $winMinutes, 2) : 0.0;

    json_ok([
        'buckets'   => $buckets,
        'total'     => $total,
        'max'       => $maxVal,
        'avg'       => $avg,
        'desde'     => $startMin->format('Y-m-d H:i:s'),
        'hasta'     => $endMin->format('Y-m-d H:i:59'),
        'generated' => $now->format('Y-m-d H:i:s'),
    ]);
}

/**
 * Calcula y persiste los counts por minuto del rango [from, to] (ambos
 * inclusive, minutos cerrados) en `senales_por_minuto`. Minutos sin
 * senales se insertan con cantidad=0 para que no se reintenten en cada
 * poll. Acota el scan sobre `senales` con un pivot por PK.
 */
function fillRange(PDO $db, DateTimeImmutable $from, DateTimeImmutable $to, int $pkLookback): void
{
    $maxId = (int) $db->query('SELECT MAX(id) FROM senales')->fetchColumn();
    $pivot = max(0, $maxId - $pkLookback);

    $stmt = $db->prepare(
        'SELECT DATE_FORMAT(fecha, "%Y-%m-%d %H:%i:00") AS minuto,
                COUNT(*) AS cnt
         FROM senales
         WHERE id > :pivot
           AND fecha >= :desde AND fecha <= :hasta
         GROUP BY minuto'
    );
    $stmt->execute([
        ':pivot' => $pivot,
        ':desde' => $from->format('Y-m-d H:i:s'),
        ':hasta' => $to->format('Y-m-d H:i:59'),
    ]);

    $counts = [];
    foreach ($stmt->fetchAll() as $row) {
        $counts[(string) $row['minuto']] = (int) $row['cnt'];
    }

    // INSERT IGNORE: si por un race condition otra request ya escribio
    // el mismo minuto, no rompemos. La PK es `minuto`, asi que el dup
    // se descarta silenciosamente.
    $ins = $db->prepare(
        'INSERT IGNORE INTO senales_por_minuto (minuto, cantidad)
         VALUES (:minuto, :cantidad)'
    );
    $cursor = $from;
    while ($cursor <= $to) {
        $key = $cursor->format('Y-m-d H:i:00');
        $ins->execute([
            ':minuto'   => $key,
            ':cantidad' => $counts[$key] ?? 0,
        ]);
        $cursor = $cursor->add(new DateInterval('PT1M'));
    }
}
