<?php

declare(strict_types=1);

/**
 * Lista de precios de los planes Developer: `/precios/planes/developer`.
 *
 * Port de `reactor-www/precios/planes/developer.php`. Comparte la tabla con
 * Standard (`_tabla.php`); lo único distinto es el tipo de plan que se lista y
 * que acá el escalón lo marcan las peticiones a la API, no los usuarios.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/planes.php';

wwwTitulo('Planes Developer');
wwwDescripcion('Precios de los planes Developer de Reactor, por cantidad de peticiones a la API.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Planes Developer</h1>
                </div>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="breadcrumb">
                    <ul>
                        <li><a href="/">Inicio</a></li>
                        <li><a href="/precios">Precios</a></li>
                        <li><a href="/precios/planes">Planes</a></li>
                        <li><a href="#!">Developer</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$tablaTipo   = PLANES_DEVELOPER;
$tablaVolver = '/precios/planes';
$tablaRuta   = '/precios/planes/developer';
require __DIR__ . '/_tabla.php';
?>

<section class="bg-primary py-lg-6">
    <div class="container">
        <div class="row align-items-center justify-content-center">
            <div class="col-lg-12 text-center text-lg-start">
                <h3 class="h5 text-white">Documentación de la API</h3>
                <p class="text-white mb-0">La referencia completa de endpoints, autenticación y ejemplos está en el portal de desarrolladores.</p>
                <div style="margin-top: 10px;">
                    <a href="https://dev.reactor.com.ar" target="_blank" rel="noopener" class="butn secondary white-hover small">Ir a dev.reactor.com.ar</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
