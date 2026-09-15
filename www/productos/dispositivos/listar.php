<?php

declare(strict_types=1);

/**
 * Catálogo de dispositivos: `/productos/dispositivos`.
 *
 * Port de `reactor-www/productos/dispositivos/listar.php`. El catálogo sigue
 * saliendo de `dispositivos.json`, al lado de este archivo: son cuatro equipos
 * que cambian una vez por año, y meterlos en una tabla sólo para eso agregaría
 * una migración y una pantalla de ABM que nadie pidió.
 *
 * LAS TARJETAS AHORA ENLAZAN A ALGÚN LADO. En el legacy el modelo y el nombre
 * eran `<a href="#!">`, o sea dos enlaces que no iban a ninguna parte. Había
 * carpetas con la ficha de tres de los equipos, pero esas páginas piden nueve
 * imágenes cada una (`content-01.jpg`, `service-07.jpg`, ...) que no existen ni
 * en el repositorio legacy ni en el servidor —`www.reactor.com.ar/productos/
 * dispositivos/CE-B1COC/` responde 404 hoy—, así que publicarlas habría sumado
 * tres páginas con los huecos a la vista.
 *
 * Lo que sí existe y sirve es la HOJA DE DATOS en PDF de cada equipo, que está
 * en la carpeta del modelo. La tarjeta enlaza a ésa cuando la hay, y cuando no
 * —el CE-M1OC no tiene— queda sin enlace en vez de con uno roto.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Dispositivos');
wwwDescripcion('Dispositivos Reactor: controladores de encendido y monitores de apertura para automatizar instalaciones.');

/**
 * Lee el catálogo. Si el JSON estuviera roto o faltara, la página se dibuja
 * igual con el catálogo vacío en vez de tirar 500: es una página pública y el
 * resto del contenido —cabecera, menú, pie— sigue sirviendo.
 */
function dispositivosCatalogo(): array
{
    $archivo = __DIR__ . '/dispositivos.json';
    if (!is_file($archivo)) {
        error_log('[dispositivos] falta dispositivos.json');
        return [];
    }

    $datos = json_decode((string) file_get_contents($archivo), true);
    if (!is_array($datos) || !isset($datos['dispositivos']) || !is_array($datos['dispositivos'])) {
        error_log('[dispositivos] dispositivos.json ilegible: ' . json_last_error_msg());
        return [];
    }

    return $datos['dispositivos'];
}

/**
 * Ruta pública de la hoja de datos de un modelo, o '' si no hay.
 *
 * El modelo se valida contra una lista blanca de caracteres antes de tocar el
 * disco: viene de un JSON que hoy es de confianza, pero `is_file()` con un
 * valor sin limpiar es exactamente la forma de que un `../` termine leyendo
 * fuera de la carpeta.
 */
function dispositivoHoja(string $modelo): string
{
    if (!preg_match('/^[A-Za-z0-9\-]{1,40}$/', $modelo)) {
        return '';
    }

    $relativa = '/productos/dispositivos/' . $modelo . '/' . $modelo . '.pdf';

    return is_file($_SERVER['DOCUMENT_ROOT'] . $relativa) ? $relativa : '';
}

$dispositivos = dispositivosCatalogo();

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Dispositivos</h1>
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
                        <li><a href="#!">Productos</a></li>
                        <li><a href="#!">Dispositivos</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CATALOGO
================================================== -->
<section class="pt-0">
    <div class="container">
        <div class="section-heading4">
            <span>Productos</span>
            <h2>Dispositivos</h2>
        </div>
        <div class="row mt-n2-9">

            <?php foreach ($dispositivos as $dispositivo):
                $modelo    = trim((string) ($dispositivo['modelo'] ?? ''));
                $nombre    = trim((string) ($dispositivo['nombre'] ?? ''));
                $tipo      = trim((string) ($dispositivo['tipo'] ?? ''));
                $miniatura = trim((string) ($dispositivo['miniatura'] ?? ''));
                $hoja      = dispositivoHoja($modelo);
            ?>
                <div class="col-md-6 col-lg-3 mt-2-9 wow fadeIn" data-wow-delay="200ms">
                    <article class="card card-style7">
                        <?php if ($miniatura !== ''): ?>
                            <div class="blog-image">
                                <img src="/productos/dispositivos/<?= e(rawurlencode($miniatura)) ?>" alt="<?= e($nombre) ?>" loading="lazy">
                                <?php if ($tipo !== ''): ?>
                                    <div class="date"><a href="#!"><?= e($tipo) ?></a></div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        <div class="card-body" style="height: 200px;">
                            <h5><?= e($modelo) ?></h5>
                            <h6 class="text-muted display-30"><?= e($nombre) ?></h6>
                            <?php if ($hoja !== ''): ?>
                                <a href="<?= e($hoja) ?>" target="_blank" rel="noopener" class="read-more">
                                    <i class="far fa-file-pdf pe-1"></i>Hoja de datos
                                </a>
                            <?php endif; ?>
                        </div>
                    </article>
                </div>
            <?php endforeach; ?>

            <?php if ($dispositivos === []): ?>
                <div class="col-12 text-center" style="padding: 60px 0;">
                    <p class="mb-0">El catálogo no está disponible en este momento.</p>
                </div>
            <?php endif; ?>

        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
