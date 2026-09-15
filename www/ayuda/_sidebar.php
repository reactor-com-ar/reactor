<?php

declare(strict_types=1);

/**
 * Barra lateral de la sección Ayuda: buscador y categorías.
 *
 * No lleva el carrusel de "últimos posts" que sí tiene el blog: los artículos
 * de ayuda no traen imagen cargada —ninguno de los 17— así que el carrusel
 * saldría vacío.
 *
 * Quien incluye puede definir antes:
 *   $sidebarBuscado  texto que quedó en la caja de búsqueda
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$sidebarBuscado    = $sidebarBuscado ?? '';
$sidebarCategorias = entradasCategorias(ENTRADAS_AYUDA);
?>

<div class="widget search wow fadeIn" data-wow-delay="200ms">
    <h6 class="widget-title">Buscar</h6>
    <form action="/ayuda/listar" method="get" class="search-bar">
        <input type="search" name="bus" placeholder="Ingresar aquí ..." value="<?= e($sidebarBuscado) ?>" />
        <button type="submit" class="btn-newsletter" aria-label="Buscar"><i class="far fa-paper-plane"></i></button>
    </form>
</div>

<div class="widget wow fadeIn" data-wow-delay="600ms">
    <h6 class="widget-title">Categorias</h6>
    <ul class="list-style9">
        <li><a href="/ayuda">Todo</a><span><?= e((string) entradasCantidad(ENTRADAS_AYUDA)) ?></span></li>
        <?php foreach ($sidebarCategorias as $categoria): ?>
            <li>
                <!-- El slug de ayuda es la primera palabra: "Preguntas Frecuentes" -> /ayuda/preguntas -->
                <a href="/ayuda/<?= e(rawurlencode(entradasSlug($categoria['alias'], true))) ?>"><?= e($categoria['alias']) ?></a>
                <span><?= e((string) $categoria['cantidad']) ?></span>
            </li>
        <?php endforeach; ?>
    </ul>
</div>
