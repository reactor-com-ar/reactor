<?php

declare(strict_types=1);

/**
 * Buscador del sitio: `/search/results?s=<termino>`. Es el destino de la lupa
 * de la barra superior.
 *
 * ESTO NO ES UN PORT, ES UN REEMPLAZO. El `search/results.php` del legacy
 * recorría el DISCO con `opendir()` y `strpos()` sobre el código fuente de cada
 * archivo, de modo que "encontraba" páginas por lo que dijera su PHP y no por
 * lo que el visitante viera. En la práctica nunca funcionó: el formulario de la
 * barra apuntaba a `search.html`, un archivo que no existe, así que la búsqueda
 * no llegaba ni a ejecutarse — hoy `www.reactor.com.ar/search/results?s=iot`
 * devuelve la página de demostración del theme.
 *
 * Lo que busca esta versión es lo que el visitante entiende por contenido del
 * sitio: las entradas del blog y los artículos de ayuda, por título, volanta,
 * bajada y cuerpo. Es la misma consulta que ya usan los dos listados, así que
 * no hay un segundo criterio de búsqueda que mantener.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/entradas.php';

$termino = wwwEntrada('s');

$resultados = [];
if ($termino !== '') {
    foreach (
        [
            [ENTRADAS_BLOG,  'Blog',  '/blog/'],
            [ENTRADAS_AYUDA, 'Ayuda', '/ayuda/'],
        ] as [$raiz, $seccion, $base]
    ) {
        foreach (entradasListar($raiz, 0, $termino, $raiz !== ENTRADAS_BLOG, 30) as $fila) {
            $resultados[] = [
                'seccion' => $seccion,
                'titulo'  => (string) $fila['titulo'],
                'bajada'  => (string) $fila['bajada'],
                'fecha'   => entradaFecha($fila['fecha']),
                'destino' => $base . rawurlencode((string) $fila['uuid']),
            ];
        }
    }
}

wwwTitulo($termino !== '' ? 'Búsqueda · ' . $termino : 'Búsqueda');
wwwDescripcion('Buscá en las notas del blog y en los artículos de ayuda de Reactor.');

// Una página de resultados no aporta nada a un buscador y genera una URL nueva
// por cada término que alguien tipee.
header('X-Robots-Tag: noindex, follow');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Búsqueda</h1>
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
                        <li><a href="#!">Búsqueda</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<style>
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

    .category {
        padding: 2px 7px;
        color: white;
        border-radius: 5px;
        font-size: x-small;
        display: inline;
    }
</style>

<section class="pt-0">
    <div class="container">
        <div class="row justify-content-center pt-2">
            <div class="col-lg-9">

                <form action="/search/results" method="get" class="mb-2-5">
                    <div class="input-group">
                        <input type="search" name="s" class="form-control" placeholder="¿Qué estás buscando?" value="<?= e($termino) ?>" autofocus>
                        <button class="butn" type="submit">Buscar</button>
                    </div>
                </form>

                <article class="card card-style7" style="padding: 40px;">
                    <?php if ($termino === ''): ?>
                        <div class="text-center" style="padding: 40px;">
                            Escribí qué estás buscando y te mostramos las notas y artículos que coincidan.
                        </div>
                    <?php elseif ($resultados === []): ?>
                        <div class="text-center" style="padding: 40px;">
                            No hay resultados para <b><?= e($termino) ?></b>.
                            Probá con otra palabra, o mirá el <a href="/blog">blog</a> y la <a href="/ayuda">ayuda</a>.
                        </div>
                    <?php else: ?>
                        <p class="text-muted display-30">
                            <?= e((string) count($resultados)) ?>
                            <?= count($resultados) === 1 ? 'resultado' : 'resultados' ?>
                            para <b><?= e($termino) ?></b>
                        </p>
                        <?php foreach ($resultados as $resultado): ?>
                            <div class="card-row">
                                <div class="category bg-primary"><?= e($resultado['seccion']) ?></div>
                                <h5 class="mb-0" style="margin-top: 8px; margin-bottom: 8px !important;">
                                    <a href="<?= e($resultado['destino']) ?>"><?= e($resultado['titulo']) ?></a>
                                </h5>
                                <p class="display-30 mb-0"><?= e($resultado['bajada']) ?></p>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </article>

            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
