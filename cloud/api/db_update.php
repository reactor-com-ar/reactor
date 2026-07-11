<?php

declare(strict_types=1);

// Explorador DB — UPDATE de una celda.
// POST api/db_update.php  {tabla, columna, pk:{...}, valor}
//   ->  { filas_afectadas, valor_guardado }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/db_explorer.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

try {
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $pdo     = db();
    $tabla   = trim((string) ($body['tabla']   ?? ''));
    $columna = trim((string) ($body['columna'] ?? ''));
    $pk      = is_array($body['pk'] ?? null) ? $body['pk'] : [];
    $valor   = array_key_exists('valor', $body) ? $body['valor'] : null;

    dbExpRequerirTabla($pdo, $tabla);
    dbExpRequerirColumna($pdo, $tabla, $columna);

    $meta = dbExpMetadatosTabla($pdo, $tabla);
    if (!$meta['pk']) {
        json_error('La tabla no tiene PK — no es posible editar registros individuales.', 409);
    }
    if (in_array($columna, $meta['pk'], true)) {
        json_error('No se puede editar una columna PK.', 409);
    }
    if (in_array($columna, $meta['auto_inc'], true)) {
        json_error('No se puede editar una columna auto_increment.', 409);
    }
    if ($valor === null && !in_array($columna, $meta['nullable'], true)) {
        json_error('La columna no permite NULL.', 409);
    }

    // PK completa: las claves recibidas tienen que matchear exactamente.
    $pkRecibidas = array_keys($pk);
    sort($pkRecibidas);
    $pkEsperadas = $meta['pk'];
    sort($pkEsperadas);
    if ($pkRecibidas !== $pkEsperadas) {
        json_error('PK incompleta. Se esperaba: ' . implode(', ', $meta['pk']), 400);
    }

    // Cada valor de PK debe ser escalar (no array/objeto).
    foreach ($pk as $k => $v) {
        if (is_array($v) || is_object($v)) json_error('Valor de PK invalido para ' . $k, 400);
    }

    $tq  = dbExpQuoteIdent($tabla);
    $cq  = dbExpQuoteIdent($columna);
    $whereParts = [];
    $binds = [':val' => $valor];
    $i = 0;
    foreach ($meta['pk'] as $col) {
        $ph = ':pk' . $i++;
        $whereParts[] = dbExpQuoteIdent($col) . ' = ' . $ph;
        $binds[$ph]   = $pk[$col];
    }
    $where = implode(' AND ', $whereParts);

    $stmt = $pdo->prepare("UPDATE {$tq} SET {$cq} = :val WHERE {$where} LIMIT 1");
    $stmt->execute($binds);
    $filas = $stmt->rowCount();

    // Releer el valor canónico (el motor pudo haber casteado).
    unset($binds[':val']);
    $sel = $pdo->prepare("SELECT {$cq} FROM {$tq} WHERE {$where} LIMIT 1");
    $sel->execute($binds);
    $valorGuardado = $sel->fetchColumn();
    if ($valorGuardado === false) $valorGuardado = null;

    json_ok([
        'filas_afectadas' => $filas,
        'valor_guardado'  => $valorGuardado,
    ]);
} catch (Throwable $e) {
    json_error('Error al actualizar celda: ' . $e->getMessage(), 500);
}
