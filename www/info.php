<?php

declare(strict_types=1);

/**
 * Páginas de información sueltas: `/info?uuid=<slug>`.
 *
 * Son entradas que no cuelgan del blog ni de la ayuda —`categoria` en NULL—,
 * pensadas para enlazar desde otras páginas. La portada usa esta ruta para las
 * seis "Capacidades del Sistema": las seis apuntan a la misma entrada y cada
 * una a un ancla distinta dentro del cuerpo.
 *
 * `/info` NO FUNCIONABA EN EL SITIO ACTUAL. El legacy tiene a la vez un
 * `info.php` y una carpeta `info/`, y Apache resuelve primero el directorio: sin
 * `index.php` adentro, `www.reactor.com.ar/info?uuid=...` contesta 403. O sea
 * que los seis "Leer más" de la portada no llevan a ninguna parte hoy. Acá no se
 * portó esa carpeta —lo que tenía (`info/instalar.php`, `info/pagos.php`) no lo
 * enlaza ninguna página del sitio— así que la ruta queda libre y funciona.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$info = entradaSuelta(wwwEntrada('uuid'));
if ($info === null) {
    wwwNoEncontrado('Esa página de información no existe o dejó de estar publicada.', '/');
}

wwwTitulo((string) $info['titulo'], false);
wwwDescripcion((string) $info['bajada']);
wwwModificada((string) $info['fecha']);

$imagen = entradaImagen($info, 'imagen');
if ($imagen !== '') {
    wwwMiniatura($imagen);
}

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Información</h1>
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
                        <li><a href="#!">Información</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTENIDO
================================================== -->
<section class="pt-0 blog-detail">
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-12 mb-2-9 mb-lg-0">
                <div class="posts-wrapper">

                    <?php if ($imagen !== ''): ?>
                        <img src="<?= e(entradaMiniatura($info, 856, 454)) ?>" alt="<?= e((string) $info['titulo']) ?>">
                    <?php endif; ?>

                    <div class="post-content wow fadeIn" data-wow-delay="200ms">
                        <div class="post-meta">
                            <h2 class="h3"><?= e((string) $info['titulo']) ?></h2>
                            <hr>
                        </div>
                        <div class="row mb-2-2">
                            <?php if (trim((string) $info['bajada']) !== ''): ?>
                                <div class="col-md-12">
                                    <p class="w-95"><b><?= nl2br(e((string) $info['bajada'])) ?></b></p>
                                </div>
                            <?php endif; ?>
                            <div class="col-md-12">
                                <?php
                                // HTML del back office; va sin escapar igual que
                                // en el blog y en la ayuda. Acá además trae las
                                // anclas (`id="seguro"`, `id="programable"`, ...)
                                // a las que enlaza la portada.
                                echo $info['cuerpo'];
                                ?>
                            </div>
                        </div>
                        <div class="separator"></div>
                        <div class="row g-0 align-items-center">
                            <?php
                            // El legacy armaba acá la URL a mano y apuntaba a
                            // `/noticias/consultar?uuid=...`, una ruta que no
                            // existe: compartir esta página mandaba a un 404.
                            $compartirColumna = 'col-md-12';
                            require $_SERVER['DOCUMENT_ROOT'] . '/sistema/compartir.php';
                            ?>
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
