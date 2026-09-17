<?php

declare(strict_types=1);

/**
 * Mapa del sitio para los buscadores: `/sitemap.xml` (lo reescribe el
 * .htaccess) y también `/sitemap.php`.
 *
 * SE GENERA, NO ES UN ARCHIVO FIJO. El legacy tenía un `sitemap.xml` estático
 * de 13 KB escrito a mano: cada nota nueva del blog había que agregarla ahí, así
 * que quedó desactualizado y listaba URLs que ya no existen. Acá las páginas
 * fijas están en una lista y las de contenido salen de la misma consulta que
 * usan los listados, así que publicar una entrada la mete en el mapa sin que
 * nadie se acuerde de hacerlo.
 *
 * No entran: `/search/results` (una URL por término tipeado), las pantallas de
 * aviso y los atajos de marca (`/whatsapp`, `/facebook`), que son redirecciones
 * a otros dominios.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

header('Content-Type: application/xml; charset=utf-8');

$base = wwwBaseUrl();

/** Páginas fijas: ruta => prioridad. */
$fijas = [
    '/'                            => '1.0',
    '/productos/dispositivos'      => '0.8',
    '/productos/aplicacion'        => '0.8',
    '/productos/plataforma'        => '0.8',
    '/precios'                     => '0.7',
    '/precios/planes'              => '0.7',
    '/precios/planes/standard'     => '0.6',
    '/precios/planes/developer'    => '0.6',
    '/precios/planes/cotizador'    => '0.6',
    '/precios/equipos'             => '0.6',
    '/ayuda'                       => '0.8',
    '/ayuda/preguntas'             => '0.6',
    '/ayuda/tutoriales'            => '0.6',
    '/tecnicos'                    => '0.7',
    '/tecnicos/registro'           => '0.5',
    '/blog'                        => '0.8',
    '/blog/noticias'               => '0.6',
    '/blog/notas'                  => '0.6',
    '/nosotros/acerca'             => '0.5',
    '/nosotros/historia'           => '0.5',
    '/nosotros/contacto'           => '0.6',
    '/privacidad'                  => '0.3',
];

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

foreach ($fijas as $ruta => $prioridad) {
    echo "  <url>\n";
    echo '    <loc>' . e($base . $ruta) . "</loc>\n";
    echo '    <priority>' . e($prioridad) . "</priority>\n";
    echo "  </url>\n";
}

// Las notas del blog (sin las programadas a futuro) y los artículos de ayuda.
foreach (
    [
        [ENTRADAS_BLOG,  '/blog/',  false, '0.6'],
        [ENTRADAS_AYUDA, '/ayuda/', true,  '0.5'],
    ] as [$raiz, $prefijo, $futuras, $prioridad]
) {
    foreach (entradasListar($raiz, 0, '', $futuras, 200) as $fila) {
        $fecha = $fila['fecha'] === null ? null : strtotime((string) $fila['fecha']);
        echo "  <url>\n";
        echo '    <loc>' . e($base . $prefijo . rawurlencode((string) $fila['uuid'])) . "</loc>\n";
        if ($fecha !== false && $fecha !== null) {
            echo '    <lastmod>' . e(date('Y-m-d', $fecha)) . "</lastmod>\n";
        }
        echo '    <priority>' . e($prioridad) . "</priority>\n";
        echo "  </url>\n";
    }
}

echo '</urlset>' . "\n";
