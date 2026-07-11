<?php

declare(strict_types=1);

// Logout es idempotente: no requiere sesion previa.
define('PANEL_API_PUBLIC', true);

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metodo no permitido', 405);
}

jwt_cookie_clear();
json_ok(['logout' => true]);
