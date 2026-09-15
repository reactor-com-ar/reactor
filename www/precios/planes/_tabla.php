<?php

declare(strict_types=1);

/**
 * La tabla de planes con el conmutador de moneda. La usan `standard.php` y
 * `developer.php`, que en el legacy eran dos archivos con el mismo código y
 * sólo cambiaban la letra del `tipo` y el título.
 *
 * Quien incluye define antes:
 *   $tablaTipo    PLANES_STANDARD o PLANES_DEVELOPER
 *   $tablaVolver  a dónde va el botón "Volver"
 *   $tablaRuta    URL de esta misma página, para el enlace que cambia la moneda
 *
 * EL CONMUTADOR DE MONEDA ESTABA ROTO en el legacy: el encabezado enlazaba a
 * `todos?mon=ars` y `todos.php` no existe en ninguna carpeta, así que tocar
 * "Precio USD" daba 404 y no había forma de ver los precios en pesos. Ahora
 * apunta a la misma página.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/planes.php';

$tablaTipo   = $tablaTipo ?? PLANES_STANDARD;
$tablaVolver = $tablaVolver ?? '/precios/planes';
$tablaRuta   = $tablaRuta ?? '/precios/planes';

// Sólo hay dos monedas: cualquier otra cosa en `?mon=` cae a dólares.
$moneda = strtolower(wwwEntrada('mon')) === 'ars' ? 'ars' : 'usd';
$otra   = $moneda === 'usd' ? 'ars' : 'usd';

$planes = planesListar($tablaTipo);
?>
<section class="pt-0">
    <div class="container">
        <div class="row justify-content-center">
            <div class="col-lg-9">

                <div class="mb-6 mb-lg-8 position-relative elements-block">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>Plan</th>
                                    <th class="text-end">
                                        <a href="<?= e($tablaRuta) ?>?mon=<?= e($otra) ?>" title="Ver los precios en <?= e(strtoupper($otra)) ?>">
                                            Precio <?= e(strtoupper($moneda)) ?>
                                        </a>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if ($planes === []): ?>
                                    <tr>
                                        <td colspan="2" class="text-center" style="padding: 40px;">No hay planes publicados en este momento.</td>
                                    </tr>
                                <?php endif; ?>
                                <?php foreach ($planes as $plan): ?>
                                    <tr>
                                        <td><?= e((string) $plan['nombre']) ?></td>
                                        <td class="text-end"><?= e(planesMoneda($plan[$moneda])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>

                        <?php if ($moneda === 'ars' && planesDolar() > 0): ?>
                            <p class="display-30 text-muted mb-0">
                                Precios en pesos calculados con una cotización de
                                <b><?= e(planesMoneda(planesDolar(), 'ARS ')) ?></b> por dólar.
                            </p>
                        <?php endif; ?>

                        <div>
                            <a href="<?= e($tablaVolver) ?>" class="butn lg" style="margin-top: 15px;"><span>Volver</span></a>
                            <a href="/precios/planes/cotizador" class="butn butn-style1 gray float-end" style="margin-top: 15px;"><span>Cotizador</span></a>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>
</section>
