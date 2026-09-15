<?php

declare(strict_types=1);

/**
 * Cotizador de planes a medida: `/precios/planes/cotizador`.
 *
 * Port de `reactor-www/precios/planes/cotizador.php`. La cuenta vive en
 * `planesCotizar()`.
 *
 * SE FUE EL ENDPOINT AJAX. El legacy calculaba en `cotizadorAjax.php` y metía
 * el resultado con `$('#monitor').load(...)`, así que sin JavaScript el
 * cotizador mostraba una caja vacía y nada más. Acá el cálculo lo hace esta
 * misma página con lo que llega por GET: funciona sin JS, la cotización queda
 * en una URL que se puede compartir o guardar, y hay un archivo menos.
 *
 * El JS que quedó es de comodidad: reenvía el formulario al cambiar el número,
 * para que se sienta igual de vivo que antes.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/planes.php';

$usuarios = wwwEntradaEntera('usuarios', PLANES_COTIZADOR_DESDE);
$cotizado = planesCotizar($usuarios);

wwwTitulo('Cotizador de Planes');
wwwDescripcion('Calculá en línea el valor de un plan Reactor a medida según la cantidad de usuarios.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Cotizador Online</h1>
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
                        <li><a href="/precios">Precios</a></li>
                        <li><a href="/precios/planes">Planes</a></li>
                        <li><a href="#!">Cotizador</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<section class="pt-0">
    <div class="container">

        <div class="section-heading4">
            <h2>Calculá el valor de tu plan a medida</h2>
        </div>

        <div class="row mt-0 justify-content-center">
            <div class="col-md-6">
                <div class="blog-sidebar">
                    <div class="widget">
                        <div class="widget-title">
                            <h4 class="h5">Plan a Medida</h4>
                        </div>
                        <div class="widget-body">
                            <div class="widget-product-calculate">
                                <form action="/precios/planes/cotizador" method="get" id="cotizador">
                                    <div class="quform-elements">
                                        <div class="row">

                                            <div class="col-md-12">
                                                <div class="quform-element form-group">
                                                    <label for="usuarios">Usuarios</label>
                                                    <div class="quform-input">
                                                        <input class="form-control" id="usuarios" type="number" name="usuarios"
                                                               min="<?= e((string) PLANES_COTIZADOR_DESDE) ?>" step="100"
                                                               value="<?= e((string) $cotizado['usuarios']) ?>">
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <div class="quform-element form-group">
                                                    <label for="montoUnitario">Precio Unitario por Usuario</label>
                                                    <div class="quform-input">
                                                        <input class="form-control" id="montoUnitario" type="text" value="<?= e(planesMoneda($cotizado['unitario'])) ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <div class="quform-element form-group">
                                                    <label for="montoUsd">Precio Total del Plan USD</label>
                                                    <div class="quform-input">
                                                        <input class="form-control" id="montoUsd" type="text" value="<?= e(planesMoneda($cotizado['usd'])) ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <div class="quform-element form-group">
                                                    <label for="montoArs">Precio Total del Plan ARS</label>
                                                    <div class="quform-input">
                                                        <input class="form-control" id="montoArs" type="text" value="<?= e(planesMoneda($cotizado['ars'])) ?>" readonly>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="col-md-12">
                                                <noscript>
                                                    <button class="butn" type="submit">Calcular</button>
                                                </noscript>
                                            </div>

                                        </div>
                                    </div>
                                </form>

                                <div class="details pt-2">
                                    <hr>
                                    <?php if ($cotizado['usuarios'] < PLANES_COTIZADOR_DESDE): ?>
                                        Desde <b><?= e((string) PLANES_COTIZADOR_DESDE) ?> usuarios</b>. Para menos,
                                        mirá los <a href="/precios/planes/standard">planes Standard</a>.
                                    <?php elseif ($cotizado['dolar'] > 0): ?>
                                        Cotización dólar <b><?= e(planesMoneda($cotizado['dolar'], 'ARS ')) ?></b>
                                        <?php if (planesDolarActualizado() !== ''): ?>
                                            <span class="text-muted">· actualizada el <?= e(date('d/m/Y', (int) strtotime(planesDolarActualizado()))) ?></span>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="text-muted">La cotización del dólar no está disponible, así que el total en pesos no se puede calcular.</span>
                                    <?php endif; ?>
                                </div>

                            </div>
                        </div>
                    </div>

                    <div>
                        <a href="/precios/planes/standard" class="butn lg" style="margin-top: 15px;"><span>Volver</span></a>
                    </div>

                </div>
            </div>
        </div>
    </div>
</section>

<script>
    // Recalcular al cambiar el numero, sin que haga falta apretar nada. Si no
    // hay JS el <noscript> de arriba deja el boton "Calcular" y el formulario
    // se manda como cualquier otro.
    document.getElementById('usuarios').addEventListener('change', function () {
        document.getElementById('cotizador').submit();
    });
</script>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
