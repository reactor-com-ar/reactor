<?php

declare(strict_types=1);

/**
 * Snapshot del entorno del lado servidor para el modal "Entorno" del panel.
 *
 * GET api/entorno.php -> secciones de pares clave/valor:
 *   aplicacion | sesion | php | cookies | servidor | variables
 *
 * SEGURIDAD: env.php vuelca TODO el .env en $_SERVER/$_ENV (incluye DB_PASS y
 * APP_KEY_CLOUD). Por eso nunca se dumpea $_SERVER crudo: se arma una lista
 * blanca de claves y todo valor cuyo nombre matchee SENSITIVE_RE se enmascara.
 * El nombre de la variable si se muestra — util para diagnosticar, inocuo.
 */

require __DIR__ . '/bootstrap.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Metodo no permitido', 405);
}

const SENSITIVE_RE = '/(PASS|PWD|SECRET|KEY|TOKEN|CREDENTIAL|AUTH)/i';

/** Enmascara el valor dejando una pista de longitud para poder diagnosticar. */
function mask(string $value): string
{
    if ($value === '') {
        return '';
    }
    return '•••••••• (' . strlen($value) . ' chars)';
}

function maskIfSensitive(string $name, string $value): string
{
    return preg_match(SENSITIVE_RE, $name) ? mask($value) : $value;
}

function fmtTs(mixed $ts): string
{
    $n = (int) $ts;
    return $n > 0 ? date('d/m/Y H:i:s', $n) : '';
}

$auth = authUser() ?? [];

/* ---------- aplicacion ---------- */
$versionFile = dirname(__DIR__) . '/version.txt';
$aplicacion  = [
    'APP_ENV'      => (string) APP_ENV,
    'App'          => 'panel',
    'Version'      => is_file($versionFile) ? trim((string) file_get_contents($versionFile)) : '',
    'DB_HOST'      => defined('DB_HOST') ? (string) DB_HOST : '',
    'DB_PORT'      => defined('DB_PORT') ? (string) DB_PORT : '',
    'DB_NAME'      => defined('DB_NAME') ? (string) DB_NAME : '',
    'DB_USER'      => defined('DB_USER') ? (string) DB_USER : '',
];

try {
    $aplicacion['DB version'] = (string) db()->query('SELECT VERSION()')->fetchColumn();
    $aplicacion['DB now']     = (string) db()->query('SELECT NOW()')->fetchColumn();
} catch (Throwable $e) {
    $aplicacion['DB version'] = 'error: ' . $e->getMessage();
}

/* ---------- sesion (JWT) ---------- */
$sesion = [
    'Mecanismo'      => 'JWT HS256 stateless (sin sesiones PHP nativas)',
    'Cookie'         => JWT_COOKIE_NAME,
    'TTL'            => JWT_TTL . ' s (' . round(JWT_TTL / 3600, 1) . ' h)',
    'Token presente' => isset($_COOKIE[JWT_COOKIE_NAME]) ? 'si' : 'no',
    'session_status' => session_status() === PHP_SESSION_ACTIVE ? 'activa' : 'inactiva',
    '$_SESSION'      => empty($_SESSION) ? '(vacio)' : json_encode(array_keys($_SESSION)),
];
foreach ($auth as $claim => $value) {
    $label = 'claim.' . $claim;
    if ($claim === 'iat' || $claim === 'exp') {
        $sesion[$label] = $value . '  (' . fmtTs($value) . ')';
        continue;
    }
    $sesion[$label] = is_scalar($value) ? (string) $value : json_encode($value);
}
if (isset($auth['exp'])) {
    $restan = (int) $auth['exp'] - time();
    $sesion['Expira en'] = $restan > 0 ? gmdate('H:i:s', $restan) : 'expirado';
}

/* ---------- php ---------- */
$php = [
    'PHP version'         => PHP_VERSION,
    'SAPI'                => PHP_SAPI,
    'date.timezone'       => date_default_timezone_get(),
    'Hora del servidor'   => date('d/m/Y H:i:s'),
    'Offset UTC'          => date('P'),
    'memory_limit'        => (string) ini_get('memory_limit'),
    'max_execution_time'  => (string) ini_get('max_execution_time'),
    'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
    'post_max_size'       => (string) ini_get('post_max_size'),
    'display_errors'      => (string) ini_get('display_errors'),
    'Extensiones'         => implode(', ', get_loaded_extensions()),
];

/* ---------- cookies vistas por el servidor (incluye HttpOnly) ---------- */
$cookies = [];
foreach ($_COOKIE as $name => $value) {
    $value = is_scalar($value) ? (string) $value : json_encode($value);
    // El JWT es credencial: se enmascara aunque el nombre no matchee el regex.
    $cookies[$name] = ($name === JWT_COOKIE_NAME)
        ? mask($value)
        : maskIfSensitive($name, $value);
}
if ($cookies === []) {
    $cookies['(sin cookies)'] = '';
}

/* ---------- request / servidor (lista blanca) ---------- */
$serverKeys = [
    'SERVER_SOFTWARE', 'SERVER_NAME', 'SERVER_ADDR', 'SERVER_PORT',
    'HTTP_HOST', 'HTTPS', 'REQUEST_SCHEME', 'REQUEST_METHOD', 'REQUEST_URI',
    'SCRIPT_NAME', 'DOCUMENT_ROOT', 'REMOTE_ADDR', 'REMOTE_PORT',
    'HTTP_USER_AGENT', 'HTTP_ACCEPT_LANGUAGE', 'HTTP_REFERER',
    'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED_PROTO', 'HTTP_X_REAL_IP',
];
$servidor = ['Hostname' => (string) gethostname()];
foreach ($serverKeys as $k) {
    if (isset($_SERVER[$k]) && is_scalar($_SERVER[$k])) {
        $servidor[$k] = (string) $_SERVER[$k];
    }
}

/* ---------- variables de entorno ---------- */
// getenv() sin argumentos trae TODO el environment del proceso; $_ENV solo se
// puebla segun variables_order, asi que se mergean los dos para no perder las
// que inyecta docker-compose. Las sensibles van enmascaradas.
$rawEnv    = getenv();
$variables = [];
foreach (array_merge(is_array($rawEnv) ? $rawEnv : [], $_ENV) as $name => $value) {
    if (!is_scalar($value)) {
        continue;
    }
    $variables[(string) $name] = maskIfSensitive((string) $name, (string) $value);
}
ksort($variables);
if ($variables === []) {
    $variables['(sin variables)'] = '';
}

/* ---------- constantes de la app (lo que define env.php) ---------- */
$constantes = [];
foreach ((get_defined_constants(true)['user'] ?? []) as $name => $value) {
    if (!is_scalar($value)) {
        continue;
    }
    $constantes[(string) $name] = maskIfSensitive(
        (string) $name,
        is_bool($value) ? ($value ? 'true' : 'false') : (string) $value
    );
}
ksort($constantes);

json_ok([
    // `id` es el contrato con el front: define en que orden se intercalan
    // estas secciones con las que arma el navegador (cookies, storages).
    'secciones' => [
        ['id' => 'aplicacion', 'titulo' => 'Aplicación',           'items' => $aplicacion],
        ['id' => 'sesion',     'titulo' => 'Sesión (JWT)',         'items' => $sesion],
        ['id' => 'cookies',    'titulo' => 'Cookies (servidor)',   'items' => $cookies],
        ['id' => 'php',        'titulo' => 'PHP',                  'items' => $php],
        ['id' => 'servidor',   'titulo' => 'Request / Servidor',   'items' => $servidor],
        ['id' => 'variables',  'titulo' => 'Variables de entorno', 'items' => $variables],
        ['id' => 'constantes', 'titulo' => 'Constantes de la app', 'items' => $constantes],
    ],
]);
