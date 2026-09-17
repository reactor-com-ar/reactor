<?php

declare(strict_types=1);

/**
 * `/tecnicos` es el listado. Se incluye en vez de redirigir a
 * `/tecnicos/listar` para que la sección tenga una sola URL pública, igual
 * que `/blog` y `/ayuda`.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

require __DIR__ . '/listar.php';
