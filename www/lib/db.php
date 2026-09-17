<?php

declare(strict_types=1);

/**
 * Conexión PDO del sitio público. Copia adaptada de `app/lib/db.php` — mismo
 * patrón que `cloud/api/bootstrap.php`: una sola instancia por request y las
 * credenciales desde el `env.php` del repo.
 *
 * Es una copia y no un include porque las cuatro apps no comparten docroot,
 * igual que pasa con `habilitado.php` o `permisos.php`.
 *
 * EL SITIO PÚBLICO ESCRIBE EN UNA SOLA TABLA: `tecnicos`, cuando alguien
 * completa el formulario de registro. Todo lo demás —entradas, categorías,
 * parámetros— lo carga el back office y acá sólo se lee. Vale tenerlo presente
 * al agregar una página: este docroot es el único al que le pega cualquiera de
 * internet sin estar logueado.
 */

require_once dirname(__DIR__, 2) . '/env.php';

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
