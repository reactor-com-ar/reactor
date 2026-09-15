<?php

declare(strict_types=1);

/**
 * La vidriera de instaladores certificados: `/instaladores`.
 *
 * Port de `reactor-www/instaladores/listar.php`. Los enlaces de WhatsApp ahora
 * se arman con `instaladorWhatsapp()`, que les completa el prefijo del país —
 * en el legacy salían con los 10 dígitos pelados y `wa.me/2644711573` no abre
 * ninguna conversación, así que el botón de 10 de los 12 publicados no servía.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/instaladores.php';

wwwTitulo('Instaladores');
wwwDescripcion('Instaladores certificados Reactor: encontrá quién puede instalarte y configurarte los dispositivos.');

$instaladores = instaladoresListar();
$saludo       = rawurlencode('Hola, me interesa contactar con un instalador Reactor');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<style>
    .client-img {
        position: relative;
        display: inline-block;
    }

    .testimonial-box {
        position: relative;
        padding-top: 20px !important;
        border-top: 45px solid #f83f2a;
        border-top-left-radius: 10px;
        border-top-right-radius: 10px;
        margin-top: 50px;
        min-height: 260px;
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .testimonial-box .client-img {
        position: absolute;
        /* La foto monta sobre la franja roja del borde superior. */
        top: -75px;
        left: 50%;
        transform: translateX(-50%);
    }

    .testimonial-box .client-img img {
        border: 5px solid #fff;
        box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
    }
</style>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0 bg-very-light-gray">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Instaladores</h1>
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
                        <li><a href="#!">Instaladores</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- LISTADO
================================================== -->
<section class="bg-very-light-gray" style="padding-top: 0px !important;">
    <div class="container">
        <div class="section-heading2 wow fadeInDown" data-wow-delay="100ms">
            <span>Instaladores Certificados</span>
            <h2>Instaladores <strong class="text-primary"><?= e(wwwNombre()) ?></strong></h2>
        </div>

        <div class="row">

            <?php if ($instaladores === []): ?>
                <div class="col-12 text-center" style="padding: 60px 0;">
                    <p class="mb-0">Todavía no hay instaladores publicados. Si instalás equipos Reactor, registrate abajo.</p>
                </div>
            <?php endif; ?>

            <?php foreach ($instaladores as $instalador):
                $whatsapp  = instaladorWhatsapp($instalador['celular']);
                $correo    = trim((string) $instalador['correo']);
                $ubicacion = instaladorUbicacion($instalador);
            ?>
                <div class="col-lg-4">
                    <div class="testimonial-box text-center bg-white">
                        <div class="client-img">
                            <!-- El legacy abria este <a> y nunca lo cerraba, asi que el
                                 enlace se comia el resto de la tarjeta: nombre, actividad
                                 y los tres botones de contacto quedaban dentro del ancla y
                                 tocar cualquiera de ellos llevaba a la ficha. -->
                            <a href="/instaladores/consultar?uid=<?= e(rawurlencode((string) $instalador['uuid'])) ?>">
                                <img src="<?= e(INSTALADORES_AVATAR) ?>" class="rounded-circle" style="width: 100px; height: 100px;" alt="<?= e((string) $instalador['nombre']) ?>" loading="lazy">
                            </a>
                        </div>
                        <div class="p-4">
                            <h4 class="h5 mb-1"><?= e((string) $instalador['nombre']) ?></h4>
                            <span class="d-block text-muted mb-1"><?= e((string) $instalador['actividad']) ?></span>
                            <?php if ($ubicacion !== ''): ?>
                                <span class="d-block text-muted display-30 mb-3"><i class="fas fa-map-marker-alt pe-2"></i><?= e($ubicacion) ?></span>
                            <?php else: ?>
                                <span class="d-block mb-3"></span>
                            <?php endif; ?>
                            <div>
                                <ul class="social-box">
                                    <?php if ($whatsapp !== ''): ?>
                                        <li>
                                            <a href="https://wa.me/<?= e($whatsapp) ?>?text=<?= e($saludo) ?>" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a>
                                        </li>
                                        <li>
                                            <a href="tel:+<?= e($whatsapp) ?>" aria-label="Llamar"><i class="fas fa-phone"></i></a>
                                        </li>
                                    <?php endif; ?>
                                    <?php if ($correo !== ''): ?>
                                        <li>
                                            <a href="mailto:<?= e($correo) ?>" aria-label="Correo"><i class="far fa-envelope"></i></a>
                                        </li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

        <div class="text-center">
            <a href="/instaladores/registro" class="butn lg" style="margin-top: 100px;"><span>Registro de Instaladores</span></a>
        </div>

    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
