<?php

declare(strict_types=1);

/**
 * Cierra la sesión y vuelve al login. Equivalente a
 * `reactor-app/sesion/cerrar.php` (que llamaba a `cAcceso::cerrar()`).
 *
 * `appSesionCerrar()` borra también la cookie legacy `sesionToken`: si quedara
 * viva, la próxima carga la adoptaría de nuevo y el usuario nunca saldría.
 */

require_once dirname(__DIR__) . '/lib/auth.php';

appSesionCerrar();

header('Location: /sesion/iniciar');
exit;
