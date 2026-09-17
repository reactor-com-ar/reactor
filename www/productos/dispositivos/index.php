<?php

declare(strict_types=1);

/**
 * `/productos/dispositivos` es el catálogo. Se incluye en vez de redirigir a
 * `/productos/dispositivos/listar`, igual que en `/blog` y `/tecnicos`: la
 * sección tiene una sola URL pública.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

require __DIR__ . '/listar.php';
