<?php

declare(strict_types=1);

/**
 * Ficha de un instalador: `/instaladores/consultar?uid=<uuid>`.
 *
 * Port de `reactor-www/instaladores/consultar.php`. Como el listado, sólo
 * muestra a los que están aprobados Y publicados: la consulta de
 * `instaladorPorUuid()` filtra por las dos columnas, así que con el uuid de
 * alguien que pidió no aparecer no se puede sacar su teléfono de acá.
 *
 * La ubicación sale de `provincia_` / `pais_`, las columnas de TEXTO. El legacy
 * la resolvía con `$xProvincia->id2nombre()` sobre las columnas de id y, cuando
 * estaban vacías —que es casi siempre—, imprimía una coma suelta.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/instaladores.php';

$instalador = instaladorPorUuid(wwwEntrada('uid'));
if ($instalador === null) {
    wwwNoEncontrado('Ese instalador no existe o ya no figura en el listado.', '/instaladores');
}

wwwTitulo('Instalador ' . $instalador['nombre']);
wwwDescripcion('Instalador certificado Reactor: ' . $instalador['nombre'] . '. ' . (string) $instalador['actividad']);

$whatsapp  = instaladorWhatsapp($instalador['celular']);
$correo    = trim((string) $instalador['correo']);
$ubicacion = instaladorUbicacion($instalador);
$saludo    = rawurlencode('Hola, me interesa contactar con un instalador Reactor');

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
        box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
    }

    .testimonial-box .client-img {
        position: absolute;
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
                    <h1>Instalador</h1>
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
                        <li><a href="/instaladores">Instaladores</a></li>
                        <li><a href="#!"><?= e((string) $instalador['nombre']) ?></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FICHA
================================================== -->
<section class="bg-very-light-gray" style="padding-top: 0px !important;">
    <div class="container">
        <div class="section-heading2 wow fadeInDown" data-wow-delay="100ms">
            <span>Instalador Certificado</span>
            <h2>Instalador <strong class="text-primary"><?= e(wwwNombre()) ?></strong></h2>
        </div>

        <div class="row">
            <div class="col-lg-12">
                <div class="testimonial-box text-center bg-white" style="height: auto;">
                    <div class="client-img">
                        <img src="<?= e(INSTALADORES_AVATAR) ?>" class="rounded-circle" style="width: 100px; height: 100px;" alt="<?= e((string) $instalador['nombre']) ?>">
                    </div>
                    <div class="p-4">

                        <h4 class="h5 mb-1"><?= e((string) $instalador['nombre']) ?></h4>
                        <span class="d-block text-muted"><?= e((string) $instalador['actividad']) ?></span>
                        <?php if ($ubicacion !== ''): ?>
                            <span class="d-block text-muted mb-3"><?= e($ubicacion) ?></span>
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

                        <div class="text-center text-muted" style="font-size:x-small; padding-top:20px;">
                            <hr>
                            <b>Importante</b>: Recuerde que Reactor cubre la visita técnica si el problema es una falla en un dispositivo en garantía. Caso contrario los honorarios de la visita se deben acordar con el técnico asignado.
                        </div>

                    </div>
                </div>
            </div>
        </div>

    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
