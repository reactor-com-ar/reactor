<?php

declare(strict_types=1);

/**
 * Portada del sitio.
 *
 * Port de `reactor-www/index.php`. El HTML y las clases del theme son los
 * mismos; lo dinámico —las últimas notas del blog— sale de `lib/entradas.php`.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Internet Of Things');
wwwDescripcion('Plataforma de control IOT: dispositivos, aplicación móvil y nube para automatizar, programar y monitorear a distancia.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';

/**
 * Las seis capacidades enlazan todas a la MISMA entrada —"Características de un
 * sistema IoT", `uuid = e5f9df8bicjsa18q`— cada una a un ancla distinta dentro
 * de su cuerpo.
 *
 * La primera apunta a `#beneficio` y no a `#global` como en el legacy: el
 * cuerpo de esa entrada tiene las anclas `beneficio`, `seguro`, `monitoreado`,
 * `programable`, `autoinstalable` y `asistencia`. `global` no existe, así que
 * ese "Leer más" abría la página arriba de todo en vez de en su sección.
 */
$capacidades = [
    ['beneficio',      'fas fa-map-marked-alt', 'Global',         'Controla dispositivos IoT en cualquier parte del mundo.',        '200ms'],
    ['seguro',         'fas fa-user-shield',    'Seguro',         'Controla los usuarios que tienen acceso a cada dispositivo',     '800ms'],
    ['monitoreado',    'fas fa-eye',            'Monitoreado',    'Monitorea de forma centralizada el estado de tus dispositivos.', '600ms'],
    ['programable',    'fas fa-code',           'Programable',    'Crea secuencias y programas a medida para automatizar tareas.',  '400ms'],
    ['autoinstalable', 'fas fa-tools',          'Autoinstalable', 'Instalable por cualquier persona con nociones de electricidad.', '1200ms'],
    ['asistencia',     'fab fa-whatsapp',       'Asistencia',     'Soporte online ilimitado para atender consultas de usuarios.',   '1000ms'],
];
$infoUuid = 'e5f9df8bicjsa18q';
?>

<div class="top-position1 z-index-1">
    <div class="common-banner">
        <div class="owl-carousel owl-theme w-100">
            <div class="text-start item bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
                <div class="container h-100">
                    <div class="d-table h-100 w-100">
                        <div class="d-table-cell align-middle">
                            <div class="w-95 w-md-85 w-lg-70 w-xxl-65 mt-5 mt-sm-0">
                                <div class="border-start border-primary border-width-4 ps-4 mb-2-5">
                                    <span class="text-primary display-22 display-sm-17 display-lg-12 text-animations" data-in-effect="fadeInRight">Plataforma de</span>
                                    <h1 class="text-white display-17 display-sm-12 display-md-10 display-lg-6 display-xl-3 font-weight-400">Control <span class="font-weight-600">IOT</span></h1>
                                </div>
                                <a href="/productos/dispositivos" class="butn mb-2 mb-sm-0">Ver Dispositivos</a>
                                <a href="/productos/plataforma" class="butn white secondary-hover ms-1 ms-sm-3">Ver Plataforma</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- INFO
================================================== -->
<section class="info-style-01">
    <div class="container">
        <div class="row g-lg-2 g-xl-4 mb-3 mb-md-4">

            <div class="col-lg-4 mb-1-9 mb-md-2-2 mb-lg-0 wow fadeIn" data-wow-delay="200ms">
                <div class="media info-wrapper transition-move rounded mt-2 mt-lg-0">
                    <div class="align-self-center">
                        <i class="fas fa-user fs-1 text-primary"></i>
                    </div>
                    <div class="media-body borders-start border-color-extra-light-gray ps-4 ms-4">
                        <h4 class="h5">Control de Usuarios</h4>
                        <p class="mb-0">Gestión de<br>permisos</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 wow fadeIn" data-wow-delay="600ms">
                <div class="media info-wrapper transition-move rounded mt-2 mt-lg-0">
                    <div class="align-self-center">
                        <i class="fas fa-cloud fs-1 text-primary"></i>
                    </div>
                    <div class="media-body borders-start border-color-extra-light-gray ps-4 ms-4">
                        <h4 class="h5">Plataforma Cloud</h4>
                        <p class="mb-0">Control<br>centralizado</p>
                    </div>
                </div>
            </div>

            <div class="col-lg-4 mb-1-9 mb-md-2-2 mb-lg-0 wow fadeIn" data-wow-delay="400ms">
                <div class="media info-wrapper transition-move rounded mt-2 mt-lg-0">
                    <div class="align-self-center">
                        <i class="fas fa-clock fs-1 text-primary"></i>
                    </div>
                    <div class="media-body borders-start border-color-extra-light-gray ps-4 ms-4">
                        <h4 class="h5">Tareas Programadas</h4>
                        <p class="mb-0">Control<br>programado</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- ABOUTUS
================================================== -->
<section class="about-style-01">
    <div class="container">
        <div class="row align-items-center">

            <div class="col-lg-6 mb-1-9 mb-sm-10 mb-lg-12 mb-lg-0 wow fadeIn" data-wow-delay="200ms">
                <div class="position-relative">
                    <div class="about-image1 tilt text-center text-sm-start w-100">
                        <img class="border border-width-9 border-color-white rounded" src="/img/content/about-03.jpg" alt="Control IoT">
                    </div>
                    <div class="about-image2 tilt d-none d-sm-inline-block">
                        <img src="/img/content/about-04.jpg" class="rounded" alt="Monitoreo IoT">
                    </div>
                </div>
            </div>

            <div class="col-lg-6 wow fadeIn" data-wow-delay="400ms">
                <div class="ps-lg-2-5 ps-xl-6">
                    <h2 class="mb-1-6 mb-md-1-9 font-weight-500"><strong>Internet de las Cosas</strong><br>Control y Automatización</h2>
                    <p class="mb-1-9 mb-md-2-2 lead">
                        La tecnología IoT permite controlar y monitorear dispositivos desde cualquier lugar del mundo, brindando eficiencia y seguridad a hogares, organizaciones e industrias.
                    </p>
                    <div class="row align-items-center">

                        <div class="col-lg-6 borders-bottom border-color-extra-light-gray mb-1-6 mb-md-1-9 pb-4">
                            <div class="media">
                                <i class="fas fa-network-wired about-icon-list me-3"></i>
                                <div class="media-body">
                                    <div class="align-self-center">
                                        <h4 class="h6">Conectividad</h4>
                                        <p class="display-30 mb-0">Conexión WiFi para control remoto.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 borders-bottom border-color-extra-light-gray mb-1-6 mb-md-1-9 pb-4">
                            <div class="media">
                                <i class="fas fa-shield-alt about-icon-list me-3"></i>
                                <div class="media-body">
                                    <div class="align-self-center">
                                        <h4 class="h6">Seguridad</h4>
                                        <p class="display-30 mb-0">Comunicación encriptada y segura.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6 borders-bottom border-lg-0 border-color-extra-light-gray mb-1-6 mb-md-1-9 mb-lg-0 pb-4 pb-lg-0">
                            <div class="media">
                                <i class="fas fa-cogs about-icon-list me-3"></i>
                                <div class="media-body">
                                    <div class="align-self-center">
                                        <h4 class="h6">Automatización</h4>
                                        <p class="display-30 mb-0">Programación de tareas y eventos.</p>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-lg-6">
                            <div class="media">
                                <i class="fas fa-mobile-alt about-icon-list me-3"></i>
                                <div class="media-body">
                                    <div class="align-self-center">
                                        <h4 class="h6">Control Móvil</h4>
                                        <p class="display-30 mb-0">App móvil para control remoto</p>
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

<!-- CARACTERISTICAS
================================================== -->
<section class="bg-very-light-gray">
    <div class="container">
        <div class="section-heading4 wow fadeIn" data-wow-delay="200ms">
            <span>CARACTERÍSTICAS DEL SISTEMA</span>
            <h2>Ventajas de <strong>Reactor Cloud</strong></h2>
        </div>
        <div class="row mt-n1-9">

            <div class="col-md-6 col-lg-4 mt-1-9 wow fadeIn" data-wow-delay="400ms">
                <div class="card card-style4">
                    <div class="image-box">
                        <img src="/img/content/caracteristicas-app.jpg" alt="Reactor App">
                        <div class="hover-box">
                            <div class="hover-content">
                                <p class="text">
                                    <b>Reactor App</b> permite a los usuarios controlar desde su smartphone los dispositivo IOT autorizados.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div>
                            <h4 class="h5">Reactor APP</h4>
                            <a href="/productos/aplicacion" class="read-more">Leer más</a>
                        </div>
                        <div class="icon">
                            <i class="fas fa-wifi"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 mt-1-9 wow fadeIn" data-wow-delay="200ms">
                <div class="card card-style4">
                    <div class="image-box">
                        <img src="/img/content/caracteristicas-cloud.jpg" alt="Reactor Control">
                        <div class="hover-box">
                            <div class="hover-content">
                                <p class="text">
                                    <b>Reactor Control</b> es la plataforma de gestion de gestion y programación de dispositivos.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div>
                            <h4 class="h5">Reactor Control</h4>
                            <a href="/productos/plataforma" class="read-more">Leer más</a>
                        </div>
                        <div class="icon">
                            <i class="fas fa-cloud"></i>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-4 mt-1-9 wow fadeIn" data-wow-delay="600ms">
                <div class="card card-style4">
                    <div class="image-box">
                        <img src="/img/content/caracteristicas-api.jpg" alt="Reactor API">
                        <div class="hover-box">
                            <div class="hover-content">
                                <p class="text">
                                    API REST completa para <b>integración</b> con otros sistemas y <b>automatización</b> mediante reglas personalizadas.
                                </p>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <div>
                            <h4 class="h5">Reactor API</h4>
                            <a href="https://dev.reactor.com.ar" target="_blank" rel="noopener" class="read-more">Leer más</a>
                        </div>
                        <div class="icon">
                            <i class="fas fa-code"></i>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- COMO TRABAJAMOS
================================================== -->
<section class="bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-13.jpg">
    <div class="container">
        <div class="section-heading4 wow fadeIn" data-wow-delay="200ms">
            <span>Como Trabajamos</span>
            <h2 class="text-white">Como implementar el<br><strong>Sistema IoT</strong></h2>
        </div>
        <div class="row mt-n1-9">

            <div class="col-md-6 col-lg-3 mt-1-9 wow fadeIn" data-wow-delay="200ms">
                <div class="process-style1">
                    <div class="line"></div>
                    <div class="number-wrap">
                        <div class="number-inner-wrap">
                            <div class="number">1</div>
                        </div>
                    </div>
                    <div class="process-content">
                        <h5 class="h6">Planificación</h5>
                        <p class="mb-0">Planifica la implementación del sistema IoT en tu industria.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3 mt-1-9 wow fadeIn" data-wow-delay="400ms">
                <div class="process-style1">
                    <div class="line"></div>
                    <div class="number-wrap">
                        <div class="number-inner-wrap">
                            <div class="number">2</div>
                        </div>
                    </div>
                    <div class="process-content">
                        <h5 class="h6">Instalación</h5>
                        <p class="mb-0">Instala los dispositivos IoT en los puntos estratégicos.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3 mt-1-9 wow fadeIn" data-wow-delay="600ms">
                <div class="process-style1">
                    <div class="line"></div>
                    <div class="number-wrap">
                        <div class="number-inner-wrap">
                            <div class="number">3</div>
                        </div>
                    </div>
                    <div class="process-content">
                        <h5 class="h6">Configuración</h5>
                        <p class="mb-0">Configura los dispositivos y la plataforma cloud.</p>
                    </div>
                </div>
            </div>

            <div class="col-md-6 col-lg-3 mt-1-9 wow fadeIn" data-wow-delay="800ms">
                <div class="process-style1">
                    <div class="number-wrap">
                        <div class="number-inner-wrap">
                            <div class="number">4</div>
                        </div>
                    </div>
                    <div class="process-content">
                        <h5 class="h6">Control</h5>
                        <p class="mb-0">Controla y monitorea los dispositivos en tiempo real.</p>
                    </div>
                </div>
            </div>

        </div>
    </div>
</section>

<!-- CAPACIDADES
================================================== -->
<section>
    <div class="container">
        <div class="section-heading4 wow fadeIn" data-wow-delay="200ms">
            <span>Características Técnicas</span>
            <h2>Capacidades del Sistema</h2>
        </div>
        <div class="row mt-n1-9 number-ordered">

            <?php foreach ($capacidades as [$ancla, $icono, $titulo, $texto, $demora]): ?>
                <div class="col-md-6 col-lg-4 mt-1-9 wow fadeIn" data-wow-delay="<?= e($demora) ?>">
                    <div class="card card-style1">
                        <div class="card-body">
                            <div class="mb-3">
                                <i class="<?= e($icono) ?> fs-1 text-primary"></i>
                            </div>
                            <h4 class="h5 mb-3"><?= e($titulo) ?></h4>
                            <p class="w-90"><?= e($texto) ?></p>
                            <a href="/info?uuid=<?= e($infoUuid) ?>#<?= e($ancla) ?>" class="read-more">Leer más</a>
                            <div class="service-counter number-ordered-item"></div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>
    </div>
</section>

<?php
require $_SERVER['DOCUMENT_ROOT'] . '/secciones/noticias.php';
require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php';
