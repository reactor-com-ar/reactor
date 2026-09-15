<?php

declare(strict_types=1);

/**
 * Red de seguridad de `/blog/`.
 *
 * Normalmente no corre: `blog/.htaccess` sirve `listar.php` directamente para
 * la raíz de la sección. Está para el caso de que mod_rewrite no esté activo o
 * el .htaccess no se lea, donde Apache cae al DirectoryIndex y sin este archivo
 * mostraría un 403.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

require __DIR__ . '/listar.php';
