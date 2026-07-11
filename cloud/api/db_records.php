<?php

declare(strict_types=1);

// Explorador DB — últimos registros de una tabla.
// GET api/db_records.php?tabla=X&limite=N
//   ->  { database, tabla, pk[], auto_inc[], nullable[], columnas[], limite, total, registros[] }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/db_explorer.php';

const DB_EXP_MAX_STRING_LEN = 500;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $pdo   = db();
    $tabla = trim((string) ($_GET['tabla'] ?? ''));
    dbExpRequerirTabla($pdo, $tabla);

    $limite = isset($_GET['limite']) ? (int) $_GET['limite'] : 50;
    if ($limite < 1 || $limite > 500) $limite = 50;

    $meta = dbExpMetadatosTabla($pdo, $tabla);
    $tq   = dbExpQuoteIdent($tabla);

    // ORDER BY PK DESC si existe; si no, sin ORDER BY.
    $order = '';
    if ($meta['pk']) {
        $parts = array_map(fn($c) => dbExpQuoteIdent($c) . ' DESC', $meta['pk']);
        $order = 'ORDER BY ' . implode(', ', $parts);
    }

    // LIMIT como entero validado (algunos drivers rompen si va como bind).
    $sql  = "SELECT * FROM {$tq} {$order} LIMIT {$limite}";
    $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

    // Truncar strings largos con sufijo explícito.
    $registros = array_map(function ($r) {
        foreach ($r as $k => $v) {
            if (is_string($v) && strlen($v) > DB_EXP_MAX_STRING_LEN) {
                $r[$k] = substr($v, 0, DB_EXP_MAX_STRING_LEN) . '… (truncado)';
            }
        }
        return $r;
    }, $rows);

    $total = (int) $pdo->query("SELECT COUNT(*) FROM {$tq}")->fetchColumn();

    json_ok([
        'database'  => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'tabla'     => $tabla,
        'pk'        => $meta['pk'],
        'auto_inc'  => $meta['auto_inc'],
        'nullable'  => $meta['nullable'],
        'columnas'  => $meta['columnas'],
        'limite'    => $limite,
        'total'     => $total,
        'registros' => $registros,
    ]);
} catch (Throwable $e) {
    json_error('Error al leer registros: ' . $e->getMessage(), 500);
}
