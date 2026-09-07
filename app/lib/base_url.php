<?php

declare(strict_types=1);

/**
 * URL base publica de la app end-user.
 *
 * Es el gemelo de `panelBaseUrl()` (panel/lib/base_url.php) y existe por el
 * mismo motivo: la usa un enlace que viaja DENTRO de un correo — el de la
 * invitacion — asi que no se puede derivar del `Host` de la request en
 * produccion. Un `Host` falseado mandaria a los invitados a otro dominio.
 *
 * Es una copia adaptada y no un include del archivo del panel porque las tres
 * apps no comparten docroot, igual que pasa con `habilitado.php`, `permisos.php`
 * y `usuarios_alta.php`.
 */

require_once dirname(__DIR__, 2) . '/env.php';

/**
 * En produccion es fija (`https://app.reactor.com.ar`): es el dominio al que
 * apunta el DNS desde el 05/09/2026, y `pwa.` quedo solo como alias. En
 * desarrollo si se deriva de la request (localhost:8115, el puerto del vhost de
 * la app), porque ahi no hay host fijo. `APP_BASE_URL` en el .env pisa las dos.
 */
function appBaseUrl(): string
{
    if (defined('APP_BASE_URL') && trim((string) APP_BASE_URL) !== '') {
        return rtrim(trim((string) APP_BASE_URL), '/');
    }
    if (APP_ENV === 'production') {
        return 'https://app.reactor.com.ar';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost:8115';
    }
    $esHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    return ($esHttps ? 'https' : 'http') . '://' . $host;
}
