<?php

declare(strict_types=1);

/**
 * Conexion PDO compartida del panel.
 *
 * Vive en lib/ (y no en api/bootstrap.php) porque tambien la necesitan
 * index.php y lib/sesion.php, que no pasan por el bootstrap de la API.
 * Requiere que env.php ya este cargado (define DB_*).
 */

if (!function_exists('db')) {
    function db(): PDO
    {
        static $pdo = null;
        if ($pdo !== null) {
            return $pdo;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            DB_HOST,
            (int) DB_PORT,
            DB_NAME
        );

        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec("SET time_zone = '-03:00'");

        return $pdo;
    }
}
