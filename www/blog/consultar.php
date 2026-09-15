<?php

declare(strict_types=1);

/**
 * Ficha de una entrada del blog: `/blog/<slug>`.
 *
 * Port de `reactor-www/blog/consultar.php`. Cambios de fondo:
 *   - Un slug que no existe devuelve 404 en vez de dibujar la plantilla vacía.
 *   - La entrada se busca ACOTADA A LA RAMA DEL BLOG, así que el slug de un
 *     artículo de ayuda no se renderiza acá con el breadcrumb equivocado.
 *   - Las metas de Open Graph salen de la entrada, así que al compartirla por
 *     WhatsApp aparece su título e imagen y no los genéricos del sitio.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$nota = entradaPorUuid(wwwEntrada('uid'), ENTRADAS_BLOG);
if ($nota === null) {
    wwwNoEncontrado('Esa entrada del blog no existe o dejó de estar publicada.', '/blog');
}

// El título va sin el "Reactor | " adelante: los títulos de las notas ya son
// largos y la marca los empujaría fuera de lo que muestra la pestaña. Se suma
// al final, que es como lo armaba el legacy.
wwwTitulo($nota['titulo'] . ' | ' . wwwNombre(), false);
wwwDescripcion((string) $nota['bajada']);
wwwModificada((string) $nota['fecha']);

$imagen = entradaImagen($nota, 'imagen');
if ($imagen !== '') {
    wwwMiniatura($imagen);
}

$idCategoria  = $nota['categoria'] === null ? null : (int) $nota['categoria'];
$alias        = entradasAliasDeId($idCategoria);
$urlCategoria = entradasUrlCategoria(ENTRADAS_BLOG, '/blog', $idCategoria);
$etiquetas    = entradaEtiquetas($nota);

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1><?= e($alias !== '' ? $alias : 'Blog') ?></h1>
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
                        <?php if ($alias !== '' && $urlCategoria !== '/blog'): ?>
                            <li><a href="<?= e($urlCategoria) ?>"><?= e($alias) ?></a></li>
                        <?php endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- BLOG DETAIL
================================================== -->
<section class="pt-0 blog-detail">
    <div class="container">
        <div class="row mt-3">

            <div class="col-lg-8 mb-2-9 mb-lg-0">
                <div class="posts-wrapper">

                    <?php if ($imagen !== ''): ?>
                        <img src="<?= e($imagen) ?>" alt="<?= e((string) $nota['titulo']) ?>">
                    <?php endif; ?>

                    <div class="post-content wow fadeIn" data-wow-delay="200ms">
                        <div class="post-meta">
                            <h2 class="h3"><?= e((string) $nota['titulo']) ?></h2>
                            <ul class="meta-list">
                                <li class="second"><i class="ti-calendar pe-2 text-primary align-middle"></i><?= e(entradaFecha($nota['fecha'])) ?></li>
                                <?php if (trim((string) $nota['autor']) !== ''): ?>
                                    <li><i class="ti-user pe-2 text-primary align-middle"></i><?= e((string) $nota['autor']) ?></li>
                                <?php endif; ?>
                            </ul>
                        </div>
                        <div class="row mb-2-2">
                            <?php if (trim((string) $nota['bajada']) !== ''): ?>
                                <div class="col-md-12">
                                    <p class="w-95"><b><?= nl2br(e((string) $nota['bajada'])) ?></b></p>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-12">
                                <?php
                                // El cuerpo SÍ sale sin escapar: es HTML que el
                                // back office guarda con formato (negritas,
                                // listas, enlaces, imágenes). Escaparlo mostraría
                                // las etiquetas en pantalla. La confianza está
                                // puesta en el editor, que es interno — igual que
                                // en el sitio legacy.
                                echo $nota['cuerpo'];
                                ?>
                            </div>
                        </div>
                        <div class="separator"></div>
                        <div class="row g-0 align-items-center">

                            <div class="col-md-6 col-xs-12 mb-1-6 mb-md-0">
                                <?php if ($etiquetas !== []): ?>
                                    <div class="tags">
                                        <h6 class="mb-3">Tags</h6>
                                        <ul class="blog-tags">
                                            <?php foreach ($etiquetas as $etiqueta): ?>
                                                <li><a href="/blog/listar?bus=<?= e(rawurlencode($etiqueta)) ?>"><?= e(ucwords($etiqueta)) ?></a></li>
                                            <?php endforeach; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>
                            </div>

                            <?php
                            $compartirColumna = 'col-md-6';
                            require $_SERVER['DOCUMENT_ROOT'] . '/sistema/compartir.php';
                            ?>

                        </div>
                    </div>

                </div>
            </div>

            <div class="col-lg-4">
                <div class="blog-sidebar ps-lg-1-6 ps-xl-1-9">
                    <?php
                    $sidebarExcepto = (int) $nota['id'];
                    require __DIR__ . '/_sidebar.php';
                    ?>
                </div>
            </div>

        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
