<?php

declare(strict_types=1);

/**
 * Lista de precios de los planes Standard: `/precios/planes/standard`.
 *
 * Port de `reactor-www/precios/planes/standard.php`. La tabla —igual a la de
 * Developer salvo por el tipo de plan— vive en `_tabla.php`.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/planes.php';

wwwTitulo('Planes Standard');
wwwDescripcion('Precios de los planes Standard de Reactor, por cantidad de usuarios.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Planes Standard</h1>
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
                        <li><a href="/precios/planes">Planes</a></li>
                        <li><a href="#!">Standard</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<?php
$tablaTipo   = PLANES_STANDARD;
$tablaVolver = '/precios/planes';
$tablaRuta   = '/precios/planes/standard';
require __DIR__ . '/_tabla.php';
?>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
