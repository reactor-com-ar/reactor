<?php

declare(strict_types=1);

/**
 * Red de seguridad de `/ayuda/`, igual que `blog/index.php`: normalmente el
 * .htaccess sirve `listar.php` directo y este archivo no corre.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

require __DIR__ . '/listar.php';
