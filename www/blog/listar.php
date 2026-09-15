<?php

declare(strict_types=1);

/**
 * Listado del blog. Es la página de `/blog`, `/blog/noticias`, `/blog/notas` y
 * `/blog/buscar/<termino>` — las cuatro entran acá con distintos parámetros,
 * que resuelve `blog/.htaccess`.
 *
 * Port de `reactor-www/blog/listar.php`, con el SQL armado por
 * `lib/entradas.php` en vez de concatenado a mano.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$buscado   = wwwEntrada('bus');
$categoria = wwwEntradaEntera('cat');

// El título de la página dice qué se está viendo. El legacy ponía siempre
// "Blog Reactor", así que la pestaña era igual filtrando o no.
$alias = $categoria > 0 ? entradasAliasDeId($categoria) : '';
if ($buscado !== '') {
    wwwTitulo('Blog · ' . $buscado);
} elseif ($alias !== '') {
    wwwTitulo('Blog · ' . $alias);
} else {
    wwwTitulo('Blog');
}
wwwDescripcion('Novedades, notas y noticias sobre IoT, automatización y los productos Reactor.');

// Las notas con fecha posterior a hoy están programadas: no se listan.
$notas = entradasListar(ENTRADAS_BLOG, $categoria, $buscado, false, 60);

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Blog <?= e(wwwNombre()) ?></h1>
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
                        <li><a href="/blog">Blog</a></li>
                        <?php if ($alias !== ''): ?>
                            <li><a href="#!"><?= e($alias) ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BLOG GRID
================================================== -->
<style>
    .card::after {
        content: '';
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        height: 100px;
        background: linear-gradient(to top,
                rgba(255, 255, 255, 1) 0%,
                rgba(255, 255, 255, 0) 100%);
        pointer-events: none;
        z-index: 1;
    }
</style>

<section class="pt-0">
    <div class="container">
        <div class="row pt-2">

            <div class="col-md-8">
                <div class="row">
                    <?php if ($notas === []): ?>
                        <div class="col-md-12 col-lg-12 mb-4 wow fadeIn" data-wow-delay="200ms">
                            <article class="card card-style7 text-center" style="padding: 100px;">
                                <?php if ($buscado !== ''): ?>
                                    No hay resultados para <b><?= e($buscado) ?></b>.
                                <?php else: ?>
                                    Todavía no hay entradas publicadas en esta sección.
                                <?php endif; ?>
                            </article>
                        </div>
                    <?php else: ?>
                        <?php foreach ($notas as $nota):
                            $destino      = '/blog/' . rawurlencode((string) $nota['uuid']);
                            $idCategoria  = $nota['categoria'] === null ? null : (int) $nota['categoria'];
                            $aliasNota    = entradasAliasDeId($idCategoria);
                            $urlCategoria = entradasUrlCategoria(ENTRADAS_BLOG, '/blog', $idCategoria);
                            $imagen       = entradaImagen($nota, 'miniatura');
                        ?>
                            <div class="col-md-6 col-lg-6 mb-4 wow fadeIn" data-wow-delay="200ms">
                                <article class="card card-style7">
                                    <?php if ($imagen !== ''): ?>
                                        <div class="blog-image">
                                            <a href="<?= e($destino) ?>">
                                                <img src="<?= e($imagen) ?>" alt="<?= e((string) $nota['titulo']) ?>" loading="lazy">
                                            </a>
                                            <?php if (trim((string) $nota['volanta']) !== ''): ?>
                                                <div class="date">
                                                    <a href="<?= e($destino) ?>"><?= e((string) $nota['volanta']) ?></a>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endif; ?>
                                    <div class="card-body" style="height: 400px; overflow: hidden;">
                                        <h5 class="mb-0"><a href="<?= e($destino) ?>"><?= e((string) $nota['titulo']) ?></a></h5>
                                        <ul class="blog-meta-list">
                                            <li><span class="ti-tag pe-2 text-primary align-middle"></span><a href="<?= e($destino) ?>"><?= e(entradaFecha($nota['fecha'])) ?></a></li>
                                            <?php if ($aliasNota !== ''): ?>
                                                <li><span class="ti-tag pe-2 text-primary align-middle"></span><a href="<?= e($urlCategoria) ?>"><?= e($aliasNota) ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                        <p class="card-text"><?= e((string) $nota['bajada']) ?></p>
                                    </div>
                                </article>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
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
