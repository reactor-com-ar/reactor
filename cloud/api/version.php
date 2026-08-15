<?php
declare(strict_types=1);

define('CLOUD_API_PUBLIC', true);
require_once __DIR__ . '/bootstrap.php';

$file    = dirname(__DIR__) . '/version.txt';
$version = is_readable($file) ? trim((string) file_get_contents($file)) : 'dev';
json_ok(['version' => $version]);
