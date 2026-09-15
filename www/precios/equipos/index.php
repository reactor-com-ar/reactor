<?php

declare(strict_types=1);

/**
 * Lista de precios de los dispositivos Reactor.
 *
 * Port de `reactor-www/precios/equipos/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Precios de Equipos');
wwwDescripcion('Lista de precios de los dispositivos Reactor.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Equipos</h1>
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
                        <li><a href="#!">Equipos</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- PRICING
        ================================================== -->
<section class="about-style-06">
    <div class="container">
        <div class="row align-items-xxl-center mt-n2-9">

            <div class="col-lg-5 mt-2-9 wow fadeIn" data-wow-delay="200ms">
                <div class="about-wrapper-image">
                    <div class="position-relative">
                        <img class="border-radius-4" src="/precios/equipos/equipo.jpg" alt="">
                    </div>
                </div>
            </div>

            <div class="col-lg-7 mt-2-9 wow fadeIn" data-wow-delay="400ms">
                <div class="ps-lg-10">
                    <h2 class="mb-1-6">Equipo Reactor</h2>
                    <p class="mb-1-9 mb-md-2-2 lead">El equipo mas recomendable para la mayoría de los casos es el Controlador Reactor CE-D4CE de 4 canales.</p>

                    <div class="d-flex about-box-info">
                        <div class="flex-shrink-0 icon-box">
                            <i class="fas fa-toolbox"></i>
                        </div>
                        <div class="flex-grow-1 ms-4 align-self-center">
                            <h4 class="mb-1 h5">Autoinstalable</h4>
                            <p class="mb-0">Puede ser instalado por cualquier persona con nociones de electricidad.</p>
                        </div>
                    </div>

                    <div class="d-flex about-box-info">
                        <div class="flex-shrink-0 icon-box">
                            <i class="fas fa-wifi"></i>
                        </div>
                        <div class="flex-grow-1 ms-4 align-self-center">
                            <h4 class="mb-1 h5">Conexión WiFi</h4>
                            <p class="mb-0">El equipo se conecta a la red WiFi b/g/n disponible.</p>
                        </div>
                    </div>

                    <div class="d-flex about-box-info">
                        <div class="flex-shrink-0 icon-box">
                            <i class="fas fa-plug"></i>
                        </div>
                        <div class="flex-grow-1 ms-4 align-self-center">
                            <h4 class="mb-1 h5">Alimentación</h4>
                            <p class="mb-0">El equipo se conecta a la red eléctrica convencional.</p>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
