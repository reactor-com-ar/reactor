<?php

declare(strict_types=1);

/**
 * La franja "Conocé las novedades" con las últimas seis notas del blog. La
 * incluye la portada, justo antes del pie.
 *
 * El legacy pedía `categoria in (110, 117, 118)` y la 117 no existe en
 * `entradascategorias`: sobraba desde siempre. Acá se pide la rama del blog
 * entera (`ENTRADAS_BLOG` y sus hijos), que da lo mismo hoy y además incorpora
 * sola cualquier categoría que se agregue.
 *
 * Las notas con fecha futura quedan afuera: están programadas, no publicadas.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$novedades = entradasListar(ENTRADAS_BLOG, 0, '', false, 6);

if ($novedades !== []):
?>
<!-- NOVEDADES
================================================== -->
<style>
    .card {
        margin-bottom: 30px;
    }

    /* Difuminado al pie de cada tarjeta: las bajadas son de largo dispar y sin
       esto el texto queda cortado de golpe contra el borde. */
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

<section class="bg-very-light-gray">
    <div class="container">

        <div class="section-heading4 wow fadeIn" data-wow-delay="200ms">
            <a href="/blog">
                <span>Nuestro Blog</span>
            </a>
            <h2>Conocé las <strong>novedades</strong></h2>
        </div>

        <div class="row g-xl-5 mt-n1-9">

            <?php foreach ($novedades as $nota):
                $destino   = '/blog/' . rawurlencode((string) $nota['uuid']);
                $idCategoria = $nota['categoria'] === null ? null : (int) $nota['categoria'];
                $alias     = entradasAliasDeId($idCategoria);
                $categoria = entradasUrlCategoria(ENTRADAS_BLOG, '/blog', $idCategoria);
                $imagen    = entradaImagen($nota, 'miniatura');
            ?>
                <div class="col-md-6 col-lg-4 mt-1-9 wow fadeIn" data-wow-delay="200ms">
                    <article class="card card-style7">
                        <?php if ($imagen !== ''): ?>
                            <a href="<?= e($destino) ?>">
                                <img src="<?= e($imagen) ?>" alt="<?= e((string) $nota['titulo']) ?>" loading="lazy">
                            </a>
                        <?php endif; ?>
                        <div class="card-body" style="height: 400px; overflow: hidden;">
                            <ul class="list-style1">
                                <li><a href="<?= e($destino) ?>"><?= e(entradaFecha($nota['fecha'])) ?></a></li>
                                <?php if ($alias !== ''): ?>
                                    <li><a href="<?= e($categoria) ?>"><?= e($alias) ?></a></li>
                                <?php endif; ?>
                            </ul>
                            <h5 class="mb-3"><a href="<?= e($destino) ?>"><?= e((string) $nota['titulo']) ?></a></h5>
                            <p class="card-text"><?= e((string) $nota['bajada']) ?></p>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>

        </div>
    </div>
</section>
<?php endif; ?>
