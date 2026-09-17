<?php

declare(strict_types=1);

/**
 * El menú principal. Lo incluye `sistema/cabeza.php` dentro del `<nav>`.
 *
 * ES UNA LISTA FIJA, a propósito. Podría salir de la base —hay tablas de
 * contenido de sobra— pero el menú de un sitio de ocho páginas no cambia sin
 * que alguien lo decida, y tenerlo acá hace que se lea de un vistazo qué
 * secciones existen. Es lo mismo que hacía el legacy.
 *
 * El `style="display: none;"` del `<ul>` no es un descuido: `js/nav-menu.js` lo
 * muestra al terminar de armar el menú responsive. Sin él, en pantallas chicas
 * la lista aparece desplegada y en crudo por un instante antes de que el JS la
 * acomode. Si se saca, vuelve el parpadeo.
 *
 * `marcarSeccion()` agrega `active` al ítem de la sección en la que estamos,
 * que es lo que pinta el theme. El legacy no lo hacía: en el sitio actual
 * ningún ítem del menú queda resaltado, ni siquiera estando adentro.
 */

/** true si el request está dentro de esa sección. */
function menuActivo(string ...$prefijos): bool
{
    $path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);

    foreach ($prefijos as $prefijo) {
        // `/` sólo matchea la home exacta; el resto matchea la sección entera,
        // así que `/blog/una-nota` también prende "Blog".
        if ($prefijo === '/') {
            if ($path === '/' || $path === '/index.php') {
                return true;
            }
            continue;
        }
        if ($path === $prefijo || str_starts_with($path, rtrim($prefijo, '/') . '/')) {
            return true;
        }
    }

    return false;
}

/** ` class="active"` o cadena vacía, para pegar dentro del `<li>`. */
function menuClase(string ...$prefijos): string
{
    return menuActivo(...$prefijos) ? ' class="active"' : '';
}
?>
<!-- menu area -->
<ul class="navbar-nav ms-auto" id="nav" style="display: none;">

    <li<?= menuClase('/') ?>>
        <a href="/">Inicio</a>
    </li>

    <li<?= menuClase('/productos', '/precios') ?>>
        <a href="#!">Productos</a>
        <ul>
            <li><a href="/productos/dispositivos">Dispositivos Reactor</a></li>
            <li><a href="/productos/aplicacion">Aplicación Reactor</a></li>
            <li><a href="/productos/plataforma">Plataforma Reactor</a></li>
            <li><a href="/precios/planes">Precios</a></li>
        </ul>
    </li>

    <li<?= menuClase('/ayuda', '/tecnicos') ?>>
        <a href="#!">Soporte</a>
        <ul>
            <li><a href="/ayuda">Ayuda</a></li>
            <li><a href="/tecnicos">Técnicos</a></li>
            <li><a href="https://dev.reactor.com.ar" target="_blank" rel="noopener">Desarrolladores</a></li>
            <li><a href="https://wa.me/5491163099315" target="_blank" rel="noopener">Atención al Cliente</a></li>
        </ul>
    </li>

    <li<?= menuClase('/blog') ?>>
        <a href="#!">Blog</a>
        <ul>
            <li><a href="/blog">Todo</a></li>
            <li><a href="/blog/noticias">Noticias</a></li>
            <li><a href="/blog/notas">Notas</a></li>
        </ul>
    </li>

    <li<?= menuClase('/nosotros') ?>>
        <a href="#!">Nosotros</a>
        <ul>
            <li><a href="/nosotros/acerca">Acerca Nuestro</a></li>
            <li><a href="/nosotros/historia">Nuestra Historia</a></li>
            <li><a href="/nosotros/contacto">Contacto</a></li>
        </ul>
    </li>

</ul>
<!-- end menu area -->
