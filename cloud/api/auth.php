<?php

declare(strict_types=1);

// Sesion-based auth. STACK.md menciona JWT pero hasta que se construya
// `lib/jwt.php` y se decida la rotacion de tokens nos quedamos con
// sesiones nativas de PHP: HttpOnly, SameSite=Lax, sin dependencias
// externas y reusable desde index.php / login.php / endpoints JSON.

function reactor_session_start(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;

    $cookieParams = [
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
    // PHP >= 7.3 acepta el array. En 8.2 (stack actual) es estandar.
    session_set_cookie_params($cookieParams);
    session_name('REACTOR_CLOUD_SID');
    session_start();
}

function reactor_current_user(): ?array
{
    reactor_session_start();
    $u = $_SESSION['user'] ?? null;
    return is_array($u) ? $u : null;
}

function reactor_login_user(array $user): void
{
    reactor_session_start();
    // Regenerar ID al loguear evita fixation: si el cliente trajo un
    // PHPSESSID viejo, lo descartamos y emitimos uno nuevo asociado al
    // usuario.
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'      => (int) ($user['id'] ?? 0),
        'usuario' => (string) ($user['usuario'] ?? ''),
        'nombre'  => (string) ($user['nombre']  ?? ''),
        'correo'  => (string) ($user['correo']  ?? ''),
    ];
    $_SESSION['login_at'] = time();
}

function reactor_logout_user(): void
{
    reactor_session_start();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params['path']   ?? '/',
            $params['domain'] ?? '',
            (bool) ($params['secure']   ?? false),
            (bool) ($params['httponly'] ?? true)
        );
    }
    session_destroy();
}

// Para endpoints JSON: corta el request con 401 si no hay sesion.
function reactor_require_auth_json(): array
{
    $u = reactor_current_user();
    if ($u === null) {
        if (!headers_sent()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'error' => 'No autenticado'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    return $u;
}
