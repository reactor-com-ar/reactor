<?php

declare(strict_types=1);

// Explorador DB — listado de tablas de la BD activa.
// GET api/db_tables.php  ->  { database, env, tablas[] }

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') json_error('metodo_no_soportado', 405);

try {
    $pdo = db();
    $env = strtolower((string) (defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'unknown')));
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME AS nombre,
                TABLE_ROWS AS filas_aprox,
                ENGINE     AS engine,
                COALESCE(TABLE_COMMENT, '') AS comentario
           FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME ASC"
    );
    $stmt->execute();
    $tablas = array_map(function ($r) {
        return [
            'nombre'      => (string) $r['nombre'],
            'filas_aprox' => $r['filas_aprox'] !== null ? (int) $r['filas_aprox'] : null,
            'engine'      => $r['engine'] !== null ? (string) $r['engine'] : '',
            'comentario'  => (string) $r['comentario'],
        ];
    }, $stmt->fetchAll());

    json_ok([
        'database' => (string) $pdo->query('SELECT DATABASE()')->fetchColumn(),
        'env'      => $env,
        'tablas'   => $tablas,
    ]);
} catch (Throwable $e) {
    json_error('Error al listar tablas: ' . $e->getMessage(), 500);
}
