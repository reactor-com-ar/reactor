<?php

declare(strict_types=1);

/**
 * Quienes somos y que hacemos en Reactor.
 *
 * Port de `reactor-www/nosotros/acerca/index.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Acerca Nuestro');
wwwDescripcion('Quienes somos y que hacemos en Reactor.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Acerca Nuestro</h1>
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
                        <li><a href="#!">Acerca Nuestro</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pt-0">
    <div class="container">
        <div class="row mt-0">

            <div class="col-lg-4 order-2 order-lg-1">
                <div class="service-details-sidebar pe-lg-1-6 pe-xl-1-9">
                    <aside class="widget widget-address">
                        <h4 class="widget-title">Contáctanos</h4>
                        <ul class="address-info">
                            <li>
                                <a href="tel:1163099315">
                                    <i class="ti-mobile text-primary me-3 fs-5 align-middle"></i>+54 1163099315</a>
                            </li>
                            <li>
                                <a href="mailto:info@reactor.com.ar">
                                    <i class="ti-email text-primary me-3 fs-5 align-middle"></i>info@reactor.com.ar
                                </a>
                            </li>
                        </ul>
                    </aside>
                    <aside class="widget widget-contact">
                        <h4 class="widget-title">Escríbenos</h4>
                        <div class="widget-body">
                            <form class="quform" action="quform/contact.php" method="post" enctype="multipart/form-data" onclick="">
                                <div class="quform-elements">
                                    <div class="row">

                                        <!-- Begin Text input element -->
                                        <div class="col-md-12">
                                            <div class="quform-element form-group">
                                                <div class="quform-input">
                                                    <input class="form-control" id="nombre" type="text" name="nombre" placeholder="Tu nombre" />
                                                </div>
                                            </div>

                                        </div>
                                        <!-- End Text input element -->

                                        <!-- Begin Text input element -->
                                        <div class="col-md-12">
                                            <div class="quform-element form-group">
                                                <div class="quform-input">
                                                    <input class="form-control" id="correo" type="text" name="correo" placeholder="Tu correo" />
                                                </div>
                                            </div>
                                        </div>
                                        <!-- End Text input element -->

                                        <!-- Begin Text input element -->
                                        <div class="col-md-12">
                                            <div class="quform-element form-group">
                                                <div class="quform-input">
                                                    <input class="form-control" id="celular" type="text" name="celular" placeholder="Tu celular" />
                                                </div>
                                            </div>

                                        </div>
                                        <!-- End Text input element -->

                                        <!-- Begin Textarea element -->
                                        <div class="col-md-12">
                                            <div class="quform-element form-group">
                                                <div class="quform-input">
                                                    <textarea class="form-control" id="mensaje" name="mensaje" rows="3" placeholder="Deja tu mensaje"></textarea>
                                                </div>
                                            </div>
                                        </div>
                                        <!-- End Textarea element -->

                                        <!-- Begin Submit button -->
                                        <div class="col-md-12">
                                            <div class="quform-submit-inner d-grid">
                                                <button class="butn sm" type="submit"><span>Enviar</span></button>
                                            </div>
                                            <div class="quform-loading-wrap text-start"><span class="quform-loading"></span></div>
                                        </div>
                                        <!-- End Submit button -->

                                    </div>
                                </div>
                            </form>
                        </div>
                    </aside>

                </div>
            </div>

            <div class="col-lg-8 order-1 order-lg-2 mb-2-6 mb-lg-0">
                <img class="mb-2-6 tilt rounded" src="/nosotros/acerca/cabecera.jpg" alt="...">

                <div class="row mb-2-6">
                    <div class="col-lg-12">
                        <h3 class="h4 mb-3">Quienes Somos</h3>
                        <p class="w-95">
                            Reactor esta formada por un equipo de expertos dedicado a I+D (investigación y Desarrollo) de nuevas tecnologías en hardware y software capaces de gestionar servicios mediante IOT sobre de ciudades enteras desde su plataforma de control.
                        </p>
                    </div>
                </div>

                <div class="row mb-2-6">
                    <div class="col-lg-12">
                        <h3 class="h4 mb-3">Investigación & Desarrollo</h3>
                        <p class="w-95">
                            En REACTOR, nos especializamos en el desarrollo de un ecosistema de tecnologías basado en hardware y software, diseñado para convertir en inteligente cualquier artefacto eléctrico o electrónico existente.
                        </p>

                        <p class="w-95 mb-3">
                            Nuestra plataforma se fundamenta en el concepto de Internet Industrial de las Cosas (IIOT), permitiendo la integración y automatización de dispositivos eléctricos para optimizar su funcionamiento y reducir la intervención humana en tareas monótonas.
                        </p>

                        <p class="w-95 mb-3">
                            Contamos con una Plataforma de Control Central, desde la cual los usuarios pueden operar sus dispositivos distribuidos geográficamente como si estuvieran juntos. A través de variables como tiempo, temperatura, iluminación y movimiento, es posible programar acciones automatizadas y recibir alertas en tiempo real.
                        </p>

                        <p class="w-95 mb-3">
                            Además, ofrecemos asesoramiento ilimitado para distribuidores, instaladores y usuarios, asegurando una implementación sencilla y eficiente. Nuestros dispositivos pueden ser controlados desde celulares, computadoras, pantallas táctiles e incluso mediante comandos de voz con Google Assistant o Google Home.
                        </p>

                        <p class="w-95 mb-3">
                            En REACTOR, trabajamos para hacer que la tecnología sea accesible, intuitiva y eficiente, mejorando la interacción entre los dispositivos y facilitando la vida de nuestros usuarios.
                        </p>

                    </div>
                </div>

            </div>
        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
