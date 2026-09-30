<?php

declare(strict_types=1);

/**
 * Ficha pública de un comprobante: `/comprobante/visor?uuid=<uuid>`.
 *
 * ES LA URL QUE LLEVA EL CORREO. La arma `accionCorreo()` en
 * `cloud/api/comprobantes_accion.php` —el botón "Abrir" del mail— y también la
 * ofrecen como "Compartir" el back office viejo y el panel legacy. O sea que es
 * una URL publicada, que la gente tiene en su casilla, y NO SE CAMBIA.
 *
 * Port de `reactor-www/comprobante/visor.php` con tres diferencias:
 *
 *   - **Muestra el total y el estado.** El legacy listaba identificador, tipo,
 *     número y emisión, y nada más: la persona tenía que abrir el PDF para
 *     saber cuánto debía y no había forma de saber si ya lo había pagado.
 *   - **Dice por qué NO se puede pagar.** El legacy escondía el botón y no
 *     ponía nada en su lugar, así que quien abría un correo de una factura ya
 *     paga veía la misma pantalla que quien abría una impagable por un error.
 *   - **Recibe el resultado del cobrador** por `res`, y lo muestra arriba. En el
 *     legacy ese cartel vivía en `pagar.php` y era código muerto: la vuelta de
 *     MercadoPago apuntaba acá, no allá, así que nunca se dibujaba.
 *
 * NO SE INDEXA (`wwwRobots(false)`): el uuid es la única credencial y lo que se
 * muestra son los datos fiscales de un cliente.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/comprobantes.php';

$uuid        = wwwEntrada('uuid');
$comprobante = comprobantePorUuid($uuid);

if ($comprobante === null) {
    // Mismo texto para el uuid mal formado, el inexistente y el borrado: los
    // tres son "acá no hay nada" y distinguirlos le confirmaría a quien prueba
    // identificadores cuáles existen.
    wwwNoEncontrado('Ese comprobante no existe o el enlace ya no es válido.', '/');
}

$tipo      = comprobanteTexto(COMBO_TALONARIO_TIPO, $comprobante['talonario_tipo']);
$numero    = comprobanteNumero($comprobante['talonario_punto'], $comprobante['serie']);
$estado    = comprobanteTexto(COMBO_COMPROBANTE_ESTADO, $comprobante['estado']);
$pagable   = comprobantePagable($comprobante);
$resultado = comprobanteResultadoPago(wwwEntrada('res'));

$hoja = '/comprobante/hoja?uuid=' . rawurlencode($comprobante['uuid']);

wwwRobots(false);
wwwTitulo(trim($tipo . ' ' . (string) $numero) ?: 'Comprobante');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<style>
    .comprobante-ficha {
        max-width: 640px;
        margin: 0 auto;
    }

    .comprobante-ficha .butn {
        margin: 5px 4px 0 0;
    }

    /* Renglón dato/valor: la etiqueta chica y gris, el valor con peso. Se
       apilan en pantallas angostas porque el correo se abre casi siempre en el
       teléfono. */
    .comprobante-dato {
        display: flex;
        justify-content: space-between;
        gap: 12px;
        padding: 9px 0;
        border-bottom: 1px solid #eee;
    }

    .comprobante-dato:last-child {
        border-bottom: 0;
    }

    .comprobante-dato dt {
        color: #6c757d;
        font-weight: 400;
        margin: 0;
    }

    .comprobante-dato dd {
        margin: 0;
        text-align: right;
        font-weight: 600;
        word-break: break-word;
    }

    .comprobante-total {
        font-size: 1.5em;
        color: #C11313;
    }

    .comprobante-aviso {
        border-radius: 6px;
        padding: 14px 18px;
        margin-bottom: 22px;
        border-left: 5px solid;
    }

    .comprobante-aviso.ok {
        background: #e8f6ec;
        border-color: #1f8a3b;
        color: #14621f;
    }

    .comprobante-aviso.error {
        background: #fdeaea;
        border-color: #C11313;
        color: #8c1010;
    }

    .comprobante-aviso.aviso {
        background: #fff6e5;
        border-color: #d98c00;
        color: #7a4f00;
    }

    .comprobante-nota {
        color: #6c757d;
        font-size: 0.95em;
        margin: 18px 0 0;
    }
</style>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0 bg-very-light-gray">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-portada.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Comprobante</h1>
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
                        <li><a href="#!">Comprobante</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FICHA
================================================== -->
<section class="bg-very-light-gray" style="padding-top: 0px !important;">
    <div class="container">
        <div class="comprobante-ficha">

            <?php if ($resultado !== null): ?>
                <div class="comprobante-aviso <?= e($resultado['tono']) ?>">
                    <?= e($resultado['texto']) ?>
                </div>
            <?php endif; ?>

            <div class="bg-white box-shadow2 p-1-6 p-sm-1-9">

                <div class="text-center mb-4">
                    <h3 class="mb-1"><?= e(trim($tipo . ' ' . (string) $numero)) ?></h3>
                    <span class="text-muted"><?= e($comprobante['empresa_razon'] ?? '') ?></span>
                </div>

                <dl class="mb-0">
                    <div class="comprobante-dato">
                        <dt>Cliente</dt>
                        <dd><?= e(comprobanteCliente($comprobante)) ?></dd>
                    </div>
                    <div class="comprobante-dato">
                        <dt>Emisión</dt>
                        <dd><?= e(comprobanteFecha($comprobante['emision'])) ?></dd>
                    </div>
                    <?php if (comprobanteFecha($comprobante['vencimiento']) !== ''): ?>
                        <div class="comprobante-dato">
                            <dt>Vencimiento</dt>
                            <dd><?= e(comprobanteFecha($comprobante['vencimiento'])) ?></dd>
                        </div>
                    <?php endif; ?>
                    <div class="comprobante-dato">
                        <dt>Estado</dt>
                        <dd><?= e($estado) ?></dd>
                    </div>
                    <div class="comprobante-dato">
                        <dt>Identificador</dt>
                        <dd><?= e($comprobante['uuid']) ?></dd>
                    </div>
                    <div class="comprobante-dato">
                        <dt>Total</dt>
                        <dd class="comprobante-total"><?= e(comprobanteMoneda($comprobante['total'])) ?></dd>
                    </div>
                </dl>

                <div class="text-center mt-4">
                    <a href="<?= e($hoja) ?>" class="butn" target="_blank" rel="noopener">
                        <i class="fa fa-eye" aria-hidden="true"></i> Abrir
                    </a>
                    <a href="<?= e($hoja) ?>&amp;imprimir=1" class="butn" target="_blank" rel="noopener">
                        <i class="fa fa-download" aria-hidden="true"></i> Descargar
                    </a>
                    <?php if ($pagable): ?>
                        <?php
                        // El pago arranca con un POST y no con un `<a href>`, que
                        // es lo que tenía el legacy. Un GET que redirige al
                        // cobrador lo dispara cualquier cosa que siga los
                        // enlaces de la página sin que nadie la haya tocado —el
                        // prefetch del navegador, el preview del cliente de
                        // correo—, y del otro lado eso es una preferencia de
                        // MercadoPago creada sola. Es el mismo motivo por el que
                        // `Aceptar` de las invitaciones dejó de ser un enlace.
                        ?>
                        <form method="post" action="/comprobante/pagar" class="d-inline">
                            <input type="hidden" name="uuid" value="<?= e($comprobante['uuid']) ?>">
                            <button type="submit" class="butn">
                                <i class="fa fa-handshake" aria-hidden="true"></i> Pagar
                            </button>
                        </form>
                    <?php endif; ?>
                </div>

                <?php if (!$pagable): ?>
                    <p class="comprobante-nota text-center mb-0">
                        <?= e(comprobanteMotivoNoPagable($comprobante)) ?>
                    </p>
                <?php endif; ?>

            </div>

            <p class="comprobante-nota text-center">
                ¿Dudas con este comprobante? Escribinos por
                <a href="/whatsapp" target="_blank" rel="noopener">WhatsApp</a>
                o a <a href="mailto:<?= e($comprobante['talonario_correo'] ?? 'info@reactor.com.ar') ?>"><?= e($comprobante['talonario_correo'] ?? 'info@reactor.com.ar') ?></a>.
            </p>

        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
