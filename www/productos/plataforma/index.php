<?php

declare(strict_types=1);

/**
 * Reactor Control: la plataforma de gestion y programacion de dispositivos IoT.
 *
 * Port de `reactor-www/productos/plataforma/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Plataforma');
wwwDescripcion('Reactor Control: la plataforma de gestion y programacion de dispositivos IoT.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Plataforma</h1>
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
                        <li><a href="#!">Plataforma</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="about-style-11" style="padding-top: 0px;">
    <div class="container">
        <div class="row align-items-xl-center">
            <div class="col-lg-5 mb-1-6 mb-sm-1-9 mb-lg-0 wow fadeIn" data-wow-delay="200ms">
                <div class="about-image-01">
                    <img src="/img/content/about-05.jpg" alt="...">
                </div>
            </div>
            <div class="col-lg-7 wow fadeIn" data-wow-delay="400ms">
                <div class="ps-lg-1-6 ps-xl-10">
                    <h2 class="mb-1-6"><strong class="text-primary font-weight-700">Reactor</strong> Control</h2>
                    <div class="bg-very-light-gray mb-1-9 mb-sm-2-2 borders-start border-width-3 p-3 border-primary w-lg-90 w-xl-85">
                        <p class="mb-0">Es una plataforma que permite gestionar toda su red de dispositivos Reactor, además de controlar los permisos de los usuarios para usar cada dispositivo.</p>
                    </div>
                    <div class="row w-lg-90 w-lg-85">
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Windows/Android/iOS Compatible
                            </div>
                        </div>
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Control de permisos de usuarios
                            </div>
                        </div>
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Configuración remota de dispositivos
                            </div>
                        </div>
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Creación de tareas programadas para dispositivos
                            </div>
                        </div>
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Monitor de salud de dispositivos con alertas por falla
                            </div>
                        </div>
                        <div class="col-sm-12 mb-3">
                            <div class="about-box-wrapper">
                                <i class="ti-check pe-2 text-primary align-middle"></i>Integración con sistemas externos mediante API Rest
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
