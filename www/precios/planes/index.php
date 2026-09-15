<?php

declare(strict_types=1);

/**
 * Los planes vigentes: `/precios/planes`.
 *
 * Port de `reactor-www/precios/planes/index.php`. Las dos tarjetas —Basic y
 * Standard— siguen siendo texto fijo porque son la propuesta comercial, no una
 * lista de precios; el detalle con los importes de verdad está en
 * `/precios/planes/standard`, que sí sale de la base.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Planes');
wwwDescripcion('Planes Reactor para edificios, barrios, clubes, colegios y más. Basic gratuito y Standard por usuario.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Planes</h1>
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
                        <li><a href="#!">Planes</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- PRICING
================================================== -->
<section class="pt-0">
    <div class="container">
        <div class="verticaltab tab-style1 resp-vtabs hor_1" style="display: block; width: 100%; margin: 0px;">
            <div class="row align-items-center">
                <div class="col-lg-4 text-center text-lg-start mb-2-5 mb-md-6 mb-lg-0 wow fadeIn" data-wow-delay="200ms">
                    <div class="d-table w-100 h-100">
                        <div class="d-table-cell align-middle">
                            <span class="text-primary text-uppercase font-weight-600 mb-2 d-block">Planes Vigentes</span>
                            <h2 class="mb-1-6 mb-lg-1-9 font-weight-500">Hacé tu entorno<br><strong>+ inteligente</strong></h2>
                            <span class="mb-md-1-6 mb-lg-1-9 d-block">Conocé nuestros planes que se adaptan a todos los escenarios como edificios, barrios, clubes, colegios y más.</span>
                        </div>
                    </div>
                </div>
                <div class="col-lg-8 wow fadeIn" data-wow-delay="400ms">
                    <div class="ps-lg-1-9">
                        <div class="row align-items-center my-3">

                            <div class="col-md-6 mb-1-9 mb-md-0">
                                <div class="card card-style2">
                                    <div class="card-header">
                                        <h3 class="text-dark mb-3 font-weight-500">Basic</h3>
                                        <span class="d-block">
                                            <span class="display-14 display-sm-12 display-md-10 display-xl-8 text-dark font-weight-500">
                                                <span class="align-top fs-5">U$D</span>0
                                            </span>
                                            <span class="d-block text-muted display-30 display-sm-29 alt-font">Por mes</span>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-style">
                                            <li><i class="fas fa-check"></i>Hasta 10 usuarios</li>
                                            <li><i class="fas fa-check"></i>App Reactor ilimitada</li>
                                            <li><i class="fas fa-check"></i>Plataforma Reactor ilimitada</li>
                                            <li><i class="fas fa-check"></i>Soporte técnico ilimitado</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <div class="col-md-6">
                                <div class="card card-style2">
                                    <div class="card-header">
                                        <h3 class="text-dark mb-3 font-weight-500">Standard</h3>
                                        <span class="d-block">
                                            <span class="display-14 display-sm-12 display-md-10 display-xl-8 text-dark font-weight-500">
                                                <span class="align-top fs-5">U$D</span>10
                                            </span>
                                            <span class="d-block text-muted display-30 display-sm-29 alt-font">Por mes</span>
                                        </span>
                                    </div>
                                    <div class="card-body">
                                        <ul class="list-style">
                                            <li><i class="fas fa-check"></i>Desde 10 usuarios</li>
                                            <li><i class="fas fa-check"></i>App Reactor ilimitada</li>
                                            <li><i class="fas fa-check"></i>Plataforma Reactor ilimitada</li>
                                            <li><i class="fas fa-check"></i>Soporte técnico ilimitado</li>
                                            <li><i class="fas fa-check"></i>Funciones especiales</li>
                                        </ul>
                                        <div class="d-grid">
                                            <a href="/precios/planes/standard" class="butn">VER PLANES STANDARD</a>
                                        </div>
                                    </div>
                                </div>
                            </div>

                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="bg-primary py-lg-6">
    <div class="container">
        <div class="row align-items-center justify-content-center">
            <div class="col-lg-12 text-center text-lg-start">
                <h3 class="h5 text-white">Planes Developer</h3>
                <p class="text-white mb-0">Conocé nuestros planes para desarrolladores que le permitirán a tus aplicaciones controlar el mundo físico fácilmente.</p>
                <div style="margin-top: 10px;">
                    <a href="/precios/planes/developer" class="butn secondary white-hover small">Planes Developer</a>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
