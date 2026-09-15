<?php

declare(strict_types=1);

/**
 * Ficha de un artículo de ayuda: `/ayuda/<slug>`.
 *
 * Port de `reactor-www/ayuda/articulo.php`, con los mismos arreglos que la
 * ficha del blog: 404 cuando el slug no existe y búsqueda acotada a la rama de
 * ayuda, para que el slug de una nota del blog no se renderice acá.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$articulo = entradaPorUuid(wwwEntrada('uid'), ENTRADAS_AYUDA);
if ($articulo === null) {
    wwwNoEncontrado('Ese artículo de ayuda no existe o dejó de estar publicado.', '/ayuda');
}

wwwTitulo($articulo['titulo'] . ' | ' . wwwNombre(), false);
wwwDescripcion((string) ($articulo['bajada'] !== '' ? $articulo['bajada'] : $articulo['titulo']));
wwwModificada((string) $articulo['fecha']);

$idCategoria  = $articulo['categoria'] === null ? null : (int) $articulo['categoria'];
$alias        = entradasAliasDeId($idCategoria);
// `true`: en ayuda el slug es la primera palabra del alias (ver entradasSlug()).
$urlCategoria = entradasUrlCategoria(ENTRADAS_AYUDA, '/ayuda', $idCategoria, true);

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
                        <?php if ($alias !== '' && $urlCategoria !== '/ayuda'): ?>
                            <li><a href="<?= e($urlCategoria) ?>"><?= e($alias) ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ARTICULO
================================================== -->
<section class="pt-0 blog-detail">
    <div class="container">
        <div class="row mt-3">

            <div class="col-lg-8 mb-2-9 mb-lg-0">
                <div class="posts-wrapper">
                    <div class="post-content wow fadeIn" data-wow-delay="200ms">
                        <div class="post-meta">
                            <h2 class="h3"><?= e((string) $articulo['titulo']) ?></h2>
                            <hr>
                        </div>
                        <div class="row mb-2-2">
                            <div class="col-md-12">
                                <?php
                                // HTML del back office, igual que en el blog: va
                                // sin escapar a propósito porque trae el formato
                                // del artículo (listas, capturas, enlaces).
                                echo $articulo['cuerpo'];
                                ?>
                            </div>
                        </div>
                        <div class="separator"></div>
                        <div class="row g-0 align-items-center">
                            <?php
                            $compartirColumna = 'col-md-12';
                            require $_SERVER['DOCUMENT_ROOT'] . '/sistema/compartir.php';
                            ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-lg-4">
                <div class="blog-sidebar ps-lg-1-6 ps-xl-1-9">
                    <?php require __DIR__ . '/_sidebar.php'; ?>
                </div>
            </div>

        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
