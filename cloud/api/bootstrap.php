<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Secretos compartidos del repo: define APP_ENV + DB_* + APP_KEY_CLOUD como constantes.
require_once dirname(__DIR__, 2) . '/env.php';
require_once dirname(__DIR__) . '/lib/auth_check.php';
require_once dirname(__DIR__) . '/lib/habilitado.php';
// Catalogo de los tres permisos de `perfiles` (operacion / invitacion /
// facturacion). Va en el bootstrap y no en profiles.php porque describe una
// entidad de la base, no un endpoint: la ficha del perfil la muestran dos
// modulos (Perfiles y la solapa Perfiles de Usuarios).
require_once dirname(__DIR__) . '/lib/permisos.php';

if (APP_ENV !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(0);
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Por defecto todo endpoint que incluya bootstrap.php exige JWT valido.
// Los endpoints publicos (login, logout) definen CLOUD_API_PUBLIC antes
// del require para optar fuera.
if (!defined('CLOUD_API_PUBLIC')) {
    requireAuth();
}

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

function json_ok(mixed $data = null, int $status = 200): void
{
    http_response_code($status);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function json_error(string $message, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
