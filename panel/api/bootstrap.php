<?php

declare(strict_types=1);

date_default_timezone_set('America/Argentina/Buenos_Aires');

// Secretos compartidos del repo: define APP_ENV + DB_* + APP_KEY_CLOUD como constantes.
require_once dirname(__DIR__, 2) . '/env.php';
require_once dirname(__DIR__) . '/lib/auth_check.php';
require_once dirname(__DIR__) . '/lib/db.php';
require_once dirname(__DIR__) . '/lib/sesion.php';

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
// Los endpoints publicos (login, logout) definen PANEL_API_PUBLIC antes
// del require para optar fuera.
if (!defined('PANEL_API_PUBLIC')) {
    requireAuth();
}

// db() vive en lib/db.php: la comparten los endpoints, index.php y lib/sesion.php.

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
