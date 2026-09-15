<?php

declare(strict_types=1);

/**
 * Listado de la sección Ayuda: `/ayuda`, `/ayuda/preguntas`,
 * `/ayuda/tutoriales` y `/ayuda/buscar/<termino>`.
 *
 * Port de `reactor-www/ayuda/listar.php`. A diferencia del blog, acá NO se
 * filtra por fecha: un tutorial no se "programa", y el legacy tampoco lo hacía.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$buscado   = wwwEntrada('bus');
$categoria = wwwEntradaEntera('cat');
$alias     = $categoria > 0 ? entradasAliasDeId($categoria) : '';

if ($buscado !== '') {
    wwwTitulo('Ayuda · ' . $buscado);
} elseif ($alias !== '') {
    wwwTitulo('Ayuda · ' . $alias);
} else {
    wwwTitulo('Ayuda');
}
wwwDescripcion('Tutoriales y preguntas frecuentes sobre los dispositivos, la aplicación y la plataforma Reactor.');

$articulos = entradasListar(ENTRADAS_AYUDA, $categoria, $buscado, true, 100);

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Ayuda</h1>
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
                        <li><a href="/ayuda">Ayuda</a></li>
                        <?php if ($alias !== ''): ?>
                            <li><a href="#!"><?= e($alias) ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
    .card-body {
        padding: 40px;
    }

    .card-row {
        margin-bottom: 20px;
        padding-bottom: 20px;
        border-bottom: 1px dotted rgba(0, 0, 0, 0.25);
    }

    .card-row:last-child {
        margin-bottom: 0;
        padding-bottom: 0;
        border-bottom: 0;
    }

    .card-text {
        font-size: smaller;
    }

    .category {
        padding-left: 7px;
        padding-right: 7px;
        padding-top: 2px;
        padding-bottom: 2px;
        color: white;
        border-radius: 5px;
        font-size: x-small;
        display: inline;
    }
</style>

<section class="pt-0">
    <div class="container">
        <div class="row pt-2">

            <div class="col-lg-8 mb-2-9 mb-lg-0">
                <article class="card card-style7 card-body">
                    <?php if ($articulos === []): ?>
                        <div class="text-center" style="padding: 50px;">
                            <?php if ($buscado !== ''): ?>
                                No hay resultados para <b><?= e($buscado) ?></b>.
                            <?php else: ?>
                                Todavía no hay artículos publicados en esta sección.
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <?php foreach ($articulos as $articulo):
                            $destino    = '/ayuda/' . rawurlencode((string) $articulo['uuid']);
                            $aliasNota  = entradasAliasDeId($articulo['categoria'] === null ? null : (int) $articulo['categoria']);
                        ?>
                            <div class="card-row">
                                <?php if ($aliasNota !== ''): ?>
                                    <div class="category bg-primary"><?= e($aliasNota) ?></div>
                                <?php endif; ?>
                                <h5 class="mb-0" style="margin-top: 8px; margin-bottom: 8px !important;">
                                    <a href="<?= e($destino) ?>"><?= e((string) $articulo['titulo']) ?></a>
                                </h5>
                                <p class="card-text"><?= e((string) $articulo['bajada']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>
            </div>

            <div class="col-lg-4">
                <div class="blog-sidebar ps-lg-1-6 ps-xl-1-9">
                    <?php
                    $sidebarBuscado = $buscado;
                    require __DIR__ . '/_sidebar.php';
                    ?>
                </div>
            </div>

        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
