<?php

declare(strict_types=1);

// Logout es idempotente: no requiere sesion previa.
define('CLOUD_API_PUBLIC', true);

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    json_error('Metodo no permitido', 405);
}

reactor_logout_user();
json_ok(['logout' => true]);
