<?php

declare(strict_types=1);

/**
 * Precios de equipos y planes Reactor.
 *
 * Port de `reactor-www/precios/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Precios');
wwwDescripcion('Precios de equipos y planes Reactor.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="top-position1 pt-0 bg-very-light-gray">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Precios</h1>
                </div>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row ">
            <div class="col-lg-12">
                <div class="breadcrumb">
                    <ul>
                        <li><a href="/">Inicio</a></li>
                        <li><a href="#!">Precios</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CLIENTS
        ================================================== -->
<section class="bg-very-light-gray" style="padding-top: 0px !important;">
    <div class="container">
        <div class="section-heading4 wow fadeIn" data-wow-delay="200ms">
            <h2>Precios de Equipos y Planes</h2>
        </div>
        <div class="row g-0">

            <div class="col-sm-12 col-md-6 wow fadeIn" data-wow-delay="400ms">
                <a href="/precios/equipos" style="display:block">
                    <div class="client-style-01">
                        <i class="fas fa-cubes fa-4x"></i>
                        <span class="client-overlay"></span>
                    </div>
                </a>
            </div>

            <div class="col-sm-12 col-md-6 wow fadeIn" data-wow-delay="200ms">
                <a href="/precios/planes" style="display:block">
                    <div class="client-style-01">
                        <i class="fas fa-cloud fa-4x"></i>
                        <span class="client-overlay"></span>
                    </div>
                </a>
            </div>

        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
