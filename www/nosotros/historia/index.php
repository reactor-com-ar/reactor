<?php

declare(strict_types=1);

/**
 * La historia de Reactor, desde el primer dispositivo hasta la plataforma actual.
 *
 * Port de `reactor-www/nosotros/historia/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Nuestra Historia');
wwwDescripcion('La historia de Reactor, desde el primer dispositivo hasta la plataforma actual.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Nuestra Historia</h1>
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
                        <li><a href="#!">Nosotros</a></li>
                        <li><a href="#!">Historia</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pt-0">
    <div class="container">

        <div class="section-heading4">
            <span>Historia</span>
            <h2>Nuestra Historia</h2>
        </div>

        <div class="row align-items-center justify-content-center mb-2-9 mb-lg-10 wow fadeIn" data-wow-delay="200ms">
            <div class="col-lg-5 mb-1-6 mb-lg-0">
                <img src="/img/content/process-05.jpg" alt="..." class="rounded tilt">
            </div>
            <div class="col-lg-5">
                <div class="ps-lg-2-2 ps-xl-6">
                    <div class="process-wrapper">
                        <div class="process-details">
                            <h4 class="process-title">DISPOSITIVOS & APP</h4>
                            <span class="number">2016</span>
                        </div>
                        <div class="divider"></div>
                        <p>
                            Inicia el proyecto de IOT que permite convertir en inteligente cualquier dispositivo eléctrico existente, pasando de controlarse manualmente a controlarse remotamente desde una app.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row align-items-center justify-content-center mb-2-9 mb-lg-10 wow fadeIn" data-wow-delay="400ms">
            <div class="col-lg-5 order-2 order-lg-1">
                <div class="pe-lg-2-2 pe-xl-6">
                    <div class="process-wrapper">
                        <div class="process-details">
                            <h4 class="process-title">Plataforma Reactor</h4>
                            <span class="number">2018</span>
                        </div>
                        <div class="divider"></div>
                        <p>
                            Nace la plataforma Reactor Control que permite gestionar y controlar todos los dispositivos de forma remota. Ahora la gestión de usuarios permite la implementacion en grandes organizaciones.
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 order-1 order-lg-2 mb-1-6 mb-lg-0">
                <img src="/img/content/process-06.jpg" alt="..." class="rounded tilt">
            </div>
        </div>

        <div class="row align-items-center justify-content-center mb-2-9 mb-lg-10 wow fadeIn" data-wow-delay="600ms">
            <div class="col-lg-5 mb-1-6 mb-lg-0">
                <img src="/img/content/process-07.jpg" alt="..." class="rounded tilt">
            </div>
            <div class="col-lg-5">
                <div class="ps-lg-2-2 ps-xl-6">
                    <div class="process-wrapper">
                        <div class="process-details">
                            <h4 class="process-title">INTEROPERABILIDAD</h4>
                            <span class="number">2020</span>
                        </div>
                        <div class="divider"></div>
                        <p>
                            Lanza la interfaz de integración mediante API Rest que permite que los dispositivos sean controlados desde aplicaciones de terceros.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <div class="row align-items-center justify-content-center mb-2-9 mb-lg-10 wow fadeIn" data-wow-delay="800ms">
            <div class="col-lg-5 order-2 order-lg-1">
                <div class="pe-lg-2-2 pe-xl-6">
                    <div class="process-wrapper">
                        <div class="process-details">
                            <h4 class="process-title">CONSOLIDACION</h4>
                            <span class="number">2024</span>
                        </div>
                        <div class="divider"></div>
                        <p>
El sistema se consolida en el mercado de las organizaciones y empresas con decenas o cientos usuarios permitiendoles gestionar sus permisos sobre los disposirivos.  
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-lg-5 order-1 order-lg-2 mb-1-6 mb-lg-0">
                <img src="/img/content/process-08.jpg" alt="..." class="rounded tilt">
            </div>
        </div>

        <div class="row align-items-center justify-content-center mb-2-9 mb-lg-10 wow fadeIn" data-wow-delay="1000ms">
            <div class="col-lg-5 mb-1-6 mb-lg-0">
                <img src="/img/content/process-09.jpg" alt="..." class="rounded tilt">
            </div>
            <div class="col-lg-5">
                <div class="ps-lg-2-2 ps-xl-6">
                    <div class="process-wrapper">
                        <div class="process-details">
                            <h4 class="process-title">DISPOSITIVOS 4G/LTE</h4>
                            <span class="number">2025</span>
                        </div>
                        <div class="divider"></div>
                        <p>
                            Lanzamiento de la nueva plataforma de control y de la nueva app de control de dispositivos. Lanzamiento del primer dispositivo 4G/LTE del mercado.
                        </p>
                    </div>
                </div>
            </div>
        </div>

    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
