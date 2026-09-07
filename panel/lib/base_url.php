<?php

declare(strict_types=1);

/**
 * URL base publica del panel.
 *
 * Vive suelta (y no dentro de lib/invitaciones.php, que es de donde salio)
 * porque la necesitan dos circuitos que no tienen nada que ver entre si:
 * el enlace de una invitacion y el de una recuperacion de contrasena. Los
 * dos viajan dentro de un correo, asi que comparten la misma regla.
 */

require_once dirname(__DIR__, 2) . '/env.php';

/**
 * En produccion es fija (`https://panel.reactor.com.ar`) a proposito: el
 * enlace viaja dentro de un mail, asi que derivarlo del Host de la request
 * dejaria que un Host falseado mandara a la gente a otro dominio.
 * En desarrollo si se deriva de la request (localhost:8087), porque ahi no
 * existe un host fijo. `PANEL_BASE_URL` en el .env pisa las dos.
 */
function panelBaseUrl(): string
{
    if (defined('PANEL_BASE_URL') && trim((string) PANEL_BASE_URL) !== '') {
        return rtrim(trim((string) PANEL_BASE_URL), '/');
    }
    if (APP_ENV === 'production') {
        return 'https://panel.reactor.com.ar';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost:8087';
    }
    $esHttps = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off');

    return ($esHttps ? 'https' : 'http') . '://' . $host;
}

/**
 * URL base publica de la APP end-user (`app.reactor.com.ar`), vista desde el
 * panel.
 *
 * El panel necesita construir URLs de OTRO dominio desde que la invitacion da de
 * alta Operadores (07/09/2026): quien la acepta no entra al BackOffice, asi que
 * el boton `Ingresar` de la pantalla de bienvenida tiene que mandarlo a la app.
 *
 * Es una copia de `baseDeDestino('app')` de cloud/api/enlaces_acceso.php y de
 * `appBaseUrl()` de app/lib/base_url.php — las tres apps no comparten docroot,
 * igual que pasa con `habilitado.php`, `permisos.php` y `usuarios_alta.php`.
 * **En dev se cambia el puerto y no el nombre del host**: los tres proyectos
 * viven en el mismo host y solo los separa el puerto (8115 el de la app).
 * `APP_BASE_URL` en el .env pisa las dos ramas.
 */
function panelAppBaseUrl(): string
{
    if (defined('APP_BASE_URL') && trim((string) APP_BASE_URL) !== '') {
        return rtrim(trim((string) APP_BASE_URL), '/');
    }
    if (APP_ENV === 'production') {
        return 'https://app.reactor.com.ar';
    }

    $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !preg_match('/^[A-Za-z0-9.\-]+(:\d+)?$/', $host)) {
        $host = 'localhost';
    }

    return 'http://' . explode(':', $host)[0] . ':8115';
}
