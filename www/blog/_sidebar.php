<?php

declare(strict_types=1);

/**
 * La barra lateral del blog: buscador, categorías y últimos posts.
 *
 * En el legacy eran tres archivos (`buscar.php`, `categorias.php`,
 * `ultimas.php`) que el listado y la ficha incluían por separado, cada uno con
 * su propio `require_once` del framework. Acá van juntos porque siempre salen
 * juntos y en el mismo orden, y porque separados invitaban a lo que pasó: las
 * dos copias del buscador (la del blog y la de ayuda) son idénticas salvo por
 * una URL.
 *
 * Quien incluye puede definir antes:
 *   $sidebarBuscado  texto que quedó en la caja de búsqueda
 *   $sidebarExcepto  id de la entrada que se está leyendo, para no ofrecerla
 *                    entre los "últimos posts"
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$sidebarBuscado = $sidebarBuscado ?? '';
$sidebarExcepto = $sidebarExcepto ?? 0;

$sidebarCategorias = entradasCategorias(ENTRADAS_BLOG);
$sidebarUltimas    = entradasUltimas(ENTRADAS_BLOG, (int) $sidebarExcepto, 5);
?>

<div class="widget search wow fadeIn" data-wow-delay="200ms">
    <h6 class="widget-title">Buscar</h6>
    <!-- GET normal a `/blog/listar`: el legacy lo resolvia con un `onsubmit`
         que armaba la URL en JS, asi que sin JavaScript el buscador no hacia
         nada y el Enter recargaba la pagina vacia. -->
    <form action="/blog/listar" method="get" class="search-bar">
        <input type="search" name="bus" placeholder="Ingresar aquí ..." value="<?= e($sidebarBuscado) ?>" />
        <button type="submit" class="btn-newsletter" aria-label="Buscar"><i class="far fa-paper-plane"></i></button>
    </form>
</div>

<div class="widget wow fadeIn" data-wow-delay="600ms">
    <h6 class="widget-title">Categorias</h6>
    <ul class="list-style9">
        <li><a href="/blog">Todo</a><span><?= e((string) entradasCantidad(ENTRADAS_BLOG)) ?></span></li>
        <?php foreach ($sidebarCategorias as $categoria): ?>
            <li>
                <a href="/blog/<?= e(rawurlencode(entradasSlug($categoria['alias']))) ?>"><?= e($categoria['alias']) ?></a>
                <span><?= e((string) $categoria['cantidad']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>

<?php if ($sidebarUltimas !== []): ?>
    <div class="widget wow fadeIn" data-wow-delay="800ms">
        <h6 class="widget-title">Ultimos Posts</h6>
        <div class="blog-post-carousel owl-carousel owl-theme">
            <?php foreach ($sidebarUltimas as $nota):
                $destino = '/blog/' . rawurlencode((string) $nota['uuid']);
            ?>
                <div>
                    <div class="image-box">
                        <a href="<?= e($destino) ?>">
                            <img src="<?= e(entradaMiniatura($nota, 636, 444)) ?>" alt="<?= e((string) $nota['titulo']) ?>" loading="lazy">
                        </a>
                        <?php if (trim((string) $nota['volanta']) !== ''): ?>
                            <h6><?= e((string) $nota['volanta']) ?></h6>
                        <?php endif; ?>
                    </div>
                    <div class="post-content">
                        <a href="<?= e($destino) ?>" class="display-30 mb-2 d-block text-muted"><?= e(entradaFecha($nota['fecha'])) ?></a>
                        <h6><a href="<?= e($destino) ?>"><?= e((string) $nota['titulo']) ?></a></h6>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>
