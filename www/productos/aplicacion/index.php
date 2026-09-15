<?php

declare(strict_types=1);

/**
 * Reactor App: controla, automatiza y monitorea tus dispositivos IoT desde el celular.
 *
 * Port de `reactor-www/productos/aplicacion/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Aplicación');
wwwDescripcion('Reactor App: controla, automatiza y monitorea tus dispositivos IoT desde el celular.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="bg-very-light-gray top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Aplicación</h1>
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
                        <li><a href="#!">Productos</a></li>
                        <li><a href="#!">Aplicación</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- INTRO -->
<section class="bg-very-light-gray pb-lg-0 service-style-01" style="padding-top: 0px;">
    <style>
        .text-white {
            color: white;
        }
    </style>

    <div class="container mb-10">
        <div class="text-center mb-2-5 mb-lg-6 mb-xl-8">
            <h2 class="mb-3 text-capitalize"><strong class="text-primary font-weight-700">Reactor</strong> APP</h2>
            <p class="mb-0 w-md-75 w-lg-60 w-xl-50 w-xxl-40 mx-auto">Una aplicación inteligente para el control total de tus dispositivos IoT. Gestiona, automatiza y monitorea todos tus dispositivos desde cualquier lugar.</p>
        </div>
        <div class="row align-items-end justify-content-around">
            <div class="col-lg-4">

                <div class="borders-bottom border-color-extra-light-gray pb-1-9 pb-lg-2-5 mb-1-9 mb-lg-2-5">
                    <div class="service-box">
                        <i class="fas fa-mobile-alt fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Control Remoto</a></h4>
                    <p class="mb-0">Control total de dispositivos<br>desde cualquier ubicación.</p>
                </div>

                <div class="borders-bottom border-color-extra-light-gray pb-1-9 pb-lg-2-5 mb-1-9 mb-lg-2-5">
                    <div class="service-box">
                        <i class="fas fa-tasks fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Automatización</a></h4>
                    <p class="mb-0">Crea rutinas y automatizaciones<br>para tus dispositivos IoT.</p>
                </div>

                <div class="borders-bottom border-lg-bottom-0 border-color-extra-light-gray pb-1-6 pb-md-1-9 pb-lg-0 mb-1-6 mb-md-1-9 mb-lg-0">
                    <div class="service-box">
                        <i class="fas fa-chart-line fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Estadísticas</a></h4>
                    <p class="mb-0">Seguimiento y análisis<br>de consumo y rendimiento.</p>
                </div>

            </div>

            <div class="col-lg-4 text-center d-lg-block" style="margin-bottom: 50px;">
                <a href="https://webapp.reactor.com.ar" target="_blank">
                    <img src="/img/content/aplicacion.png" alt="Reactor" class="img-fluid" width="400" height="400">
                </a>
            </div>

            <div class="col-lg-4">

                <div class="borders-bottom border-color-extra-light-gray pb-1-9 pb-lg-2-5 mb-1-9 mb-lg-2-5">
                    <div class="service-box">
                        <i class="fas fa-shield-alt fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Seguridad</a></h4>
                    <p class="mb-0">Conexión cifrada y protección<br>avanzada de dispositivos.</p>
                </div>

                <div class="borders-bottom border-color-extra-light-gray pb-1-9 pb-lg-2-5 mb-1-9 mb-lg-2-5">
                    <div class="service-box">
                        <i class="fas fa-clock fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Programación</a></h4>
                    <p class="mb-0">Programa acciones y eventos<br>para tus dispositivos.</p>
                </div>

                <div>
                    <div class="service-box">
                        <i class="fas fa-bell fa-2x text-white"></i>
                    </div>
                    <h4 class="h5 mb-3"><a href="#!">Notificaciones</a></h4>
                    <p class="mb-0">Alertas instantáneas sobre<br>el estado de tus dispositivos.</p>
                </div>

            </div>
        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
