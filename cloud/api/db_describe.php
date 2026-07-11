<?php

declare(strict_types=1);

// Explorador DB — describe de una tabla (schema de columnas).
// GET api/db_describe.php?tabla=X  ->  { database, tabla, columnas[] }

require __DIR__ . '/bootstrap.php';
require __DIR__ . '/lib/db_explorer.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $pdo   = db();
    $tabla = trim((string) ($_GET['tabla'] ?? ''));
    dbExpRequerirTabla($pdo, $tabla);

    $stmt = $pdo->prepare(
        "SELECT ORDINAL_POSITION AS posicion,
                COLUMN_NAME       AS nombre,
                COLUMN_TYPE       AS tipo,
                IS_NULLABLE       AS nullable,
                COLUMN_KEY        AS clave,
                COLUMN_DEFAULT    AS predeterminado,
                EXTRA             AS extra,
                COALESCE(COLUMN_COMMENT, '') AS comentario
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION"
    );
    $stmt->execute([':t' => $tabla]);
    $columnas = array_map(function ($r) {
        return [
            'posicion'       => (int) $r['posicion'],
            'nombre'         => (string) $r['nombre'],
            'tipo'           => (string) $r['tipo'],
            'nullable'       => (string) $r['nullable'],
            'clave'          => (string) $r['clave'],
            'predeterminado' => $r['predeterminado'] !== null ? (string) $r['predeterminado'] : null,
            'extra'          => (string) $r['extra'],
            'comentario'     => (string) $r['comentario'],
        ];
    }, $stmt->fetchAll());

    json_ok([
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'tabla'    => $tabla,
        'columnas' => $columnas,
    ]);
} catch (Throwable $e) {
    json_error('Error al describir tabla: ' . $e->getMessage(), 500);
}
