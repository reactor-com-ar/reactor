<?php

declare(strict_types=1);

/**
 * Cabecera común: el `<head>` entero, la barra de navegación y la apertura del
 * wrapper. La cierra `sistema/pie.php`.
 *
 * Se incluye DESPUÉS de fijar las metas de la página:
 *
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
 *   wwwTitulo('Blog');
 *   require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
 *
 * Es el port de `sistema/cabeza.php` del legacy. El HTML y las clases del theme
 * son los mismos —de ahí sale el aspecto del sitio— y lo que cambia es de dónde
 * salen los datos: de `lib/pagina.php` en vez del objeto global `$oPagina`.
 *
 * Arreglos respecto del original, todos visibles:
 *   - `og:url` estaba vacío y `og:description` repetía el título. Ahora hay
 *     canónica y descripción de verdad, que es lo que muestra la tarjeta al
 *     compartir un enlace por WhatsApp.
 *   - `<meta name="author">` decía "Rreactor", con dos erres.
 *   - El buscador de la barra apuntaba a `search.html`, que no existe: el
 *     formulario no devolvía nada. Ahora va a `/search/results`.
 *   - Los assets llevan `?v=` con el contenido de `version.txt` en vez del
 *     `?rnd=478` fijo, que nunca cambiaba y dejaba el CSS viejo cacheado.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/analytics.php';

$wwwVersion = wwwVersion();
?>
<!DOCTYPE html>
<html lang="es">

<head>

    <title><?= e(wwwTitulo()) ?></title>

    <!-- metas -->
    <meta charset="utf-8">
    <meta name="author" content="Reactor">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="keywords" content="reactor, control iot, internet de las cosas, automatizacion, domotica">
    <meta name="description" content="<?= e(wwwDescripcion()) ?>">
    <meta name="last-modified" content="<?= e(wwwModificada()) ?>">
    <link rel="canonical" href="<?= e(wwwCanonica()) ?>">

    <!-- vista previa al compartir -->
    <meta property="og:title" content="<?= e(wwwTitulo()) ?>">
    <meta property="og:description" content="<?= e(wwwDescripcion()) ?>">
    <meta property="og:image" content="<?= e(wwwMiniatura()) ?>">
    <meta property="og:url" content="<?= e(wwwCanonica()) ?>">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="<?= e(wwwNombre()) ?>">
    <meta name="twitter:card" content="summary_large_image">

    <!-- favicons -->
    <link rel="shortcut icon" href="/favicon/android-icon-192x192.png">
    <link rel="apple-touch-icon" sizes="57x57" href="/favicon/apple-icon-57x57.png">
    <link rel="apple-touch-icon" sizes="60x60" href="/favicon/apple-icon-60x60.png">
    <link rel="apple-touch-icon" sizes="72x72" href="/favicon/apple-icon-72x72.png">
    <link rel="apple-touch-icon" sizes="76x76" href="/favicon/apple-icon-76x76.png">
    <link rel="apple-touch-icon" sizes="114x114" href="/favicon/apple-icon-114x114.png">
    <link rel="apple-touch-icon" sizes="120x120" href="/favicon/apple-icon-120x120.png">
    <link rel="apple-touch-icon" sizes="144x144" href="/favicon/apple-icon-144x144.png">
    <link rel="apple-touch-icon" sizes="152x152" href="/favicon/apple-icon-152x152.png">
    <link rel="apple-touch-icon" sizes="180x180" href="/favicon/apple-icon-180x180.png">
    <link rel="icon" type="image/png" sizes="192x192" href="/favicon/android-icon-192x192.png">
    <link rel="icon" type="image/png" sizes="32x32" href="/favicon/favicon-32x32.png">
    <link rel="icon" type="image/png" sizes="96x96" href="/favicon/favicon-96x96.png">
    <link rel="icon" type="image/png" sizes="16x16" href="/favicon/favicon-16x16.png">
    <link rel="manifest" href="/manifest.json?v=<?= e($wwwVersion) ?>">
    <meta name="msapplication-TileColor" content="#ffffff">
    <meta name="msapplication-TileImage" content="/favicon/ms-icon-144x144.png">

    <!-- plugins -->
    <link rel="stylesheet" href="/css/plugins.css?v=<?= e($wwwVersion) ?>">

    <!-- search css -->
    <link rel="stylesheet" href="/search/search.css?v=<?= e($wwwVersion) ?>">

    <!-- quform css -->
    <link rel="stylesheet" href="/quform/css/base.css?v=<?= e($wwwVersion) ?>">

    <!-- theme core css -->
    <link rel="stylesheet" href="/css/styles-red.css?v=<?= e($wwwVersion) ?>">

    <!-- custom css -->
    <link rel="stylesheet" href="/css/custom.css?v=<?= e($wwwVersion) ?>">

    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@fortawesome/fontawesome-free@6.0.0-beta2/css/all.min.css">

    <?php wwwAnalytics(); ?>

</head>

<body>

    <!-- MAIN WRAPPER
    ================================================== -->
    <div class="main-wrapper">

        <!-- HEADER
        ================================================== -->
        <header class="header-style1 menu_area-light">

            <div class="navbar-default border-bottom border-color-light-white">

                <!-- start top search -->
                <div class="top-search bg-primary">
                    <div class="container-fluid px-lg-1-6 px-xl-2-5 px-xxl-2-9">
                        <form class="search-form" action="/search/results" method="GET" accept-charset="utf-8">
                            <div class="input-group">
                                <span class="input-group-addon cursor-pointer">
                                    <button class="search-form_submit fas fa-search text-white" type="submit"></button>
                                </span>
                                <input type="text" class="search-form_input form-control" name="s" autocomplete="off" placeholder="Escribe &amp; presiona enter...">
                                <span class="input-group-addon close-search mt-1"><i class="fas fa-times"></i></span>
                            </div>
                        </form>
                    </div>
                </div>
                <!-- end top search -->

                <div class="container-fluid px-lg-1-6 px-xl-2-5 px-xxl-2-9">
                    <div class="row align-items-center">
                        <div class="col-12">
                            <div class="menu_area alt-font">
                                <nav class="navbar navbar-expand-lg navbar-light p-0">

                                    <div class="navbar-header navbar-header-custom">
                                        <!-- start logo -->
                                        <a href="/" class="navbar-brand h-default"><img id="logo" src="/img/logos/logo-inner.png" alt="<?= e(wwwNombre()) ?>"></a>
                                        <!-- end logo -->
                                    </div>

                                    <div class="navbar-toggler"></div>

                                    <?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/menu.php'; ?>

                                    <!-- start attribute navigation -->
                                    <div class="attr-nav align-items-lg-center ms-lg-auto main-font">
                                        <ul>
                                            <li class="search"><a href="#!"><i class="fas fa-search"></i></a></li>
                                        </ul>
                                    </div>
                                    <!-- end attribute navigation -->

                                </nav>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </header>
