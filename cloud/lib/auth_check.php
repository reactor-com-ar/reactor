<?php
/**
 * Guard de autenticación para cloud.
 *
 *  - requireAuth(): si no hay JWT válido devuelve 401 JSON o redirige a /login.php
 *    según el Accept del request.
 *  - authUser(): devuelve el payload del token o null. Cacheado por request.
 *  - jwt_cookie_set / jwt_cookie_clear: helpers para emitir / limpiar la cookie.
 *
 * El token vive en la cookie `reactor_cloud_token`, HttpOnly siempre, Secure en prod.
 */

require_once __DIR__ . '/jwt.php';

const JWT_COOKIE_NAME = 'reactor_cloud_token';
const JWT_TTL         = 60 * 60 * 12; // 12 h

function authUser(): ?array
{
    static $cached = false;
    static $user   = null;
    if ($cached) {
        return $user;
    }
    $cached = true;

    $token = $_COOKIE[JWT_COOKIE_NAME] ?? '';
    if ($token === '') {
        return $user;
    }
    $payload = jwt_verify($token);
    if (!$payload) {
        return $user;
    }
    $user = $payload;
    return $user;
}

function _wants_json(): bool
{
    $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
    $xrw    = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
    if (stripos($accept, 'application/json') !== false) return true;
    if (strcasecmp($xrw, 'XMLHttpRequest') === 0)        return true;
    // Endpoints bajo /api/ siempre responden JSON
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    if (str_contains($uri, '/api/')) return true;
    return false;
}

function requireAuth(): array
{
    $u = authUser();
    if ($u !== null) {
        return $u;
    }
    if (_wants_json()) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'No autenticado.']);
        exit;
    }
    header('Location: /login.php');
    exit;
}

function jwt_cookie_set(string $token): void
{
    $secure = (defined('APP_ENV') && APP_ENV === 'production');
    setcookie(JWT_COOKIE_NAME, $token, [
        'expires'  => time() + JWT_TTL,
        'path'     => '/',
        'secure'   => $secure,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function jwt_cookie_clear(): void
{
    setcookie(JWT_COOKIE_NAME, '', [
        'expires'  => time() - 3600,
        'path'     => '/',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}
