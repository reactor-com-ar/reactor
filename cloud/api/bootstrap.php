<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

require_once __DIR__ . '/auth.php';
reactor_session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Por defecto todo endpoint que incluya bootstrap.php exige sesion.
// Los endpoints publicos (login, logout) definen CLOUD_API_PUBLIC antes
// del require para optar fuera.
if (!defined('CLOUD_API_PUBLIC')) {
    reactor_require_auth_json();
}

function env(string $key, ?string $default = null): string
{
    $v = getenv($key);
    if ($v === false || $v === '') {
        if ($default === null) {
            throw new RuntimeException("Variable de entorno '$key' no esta definida (revisar .env.development / .env.production cargado por docker-compose).");
        }
        return $default;
    }
    return $v;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        env('DB_HOST'),
        (int) env('DB_PORT', '3306'),
        env('DB_NAME')
    );

    $pdo = new PDO($dsn, env('DB_USER'), env('DB_PASS', ''), [
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
