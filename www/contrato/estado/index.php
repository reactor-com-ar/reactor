<?php

declare(strict_types=1);

/**
 * Estado de cuenta de un contrato: `/contrato/estado?uid=<uuid>`.
 *
 * ES UNA URL PUBLICADA Y NO SE CAMBIA: es la que Reactor le manda al cliente para
 * que vea qué debe y pague sin iniciar sesión —no hay login en este docroot—, y
 * la que sirve de libre deuda cuando no debe nada. El parámetro se llama `uid`
 * por lo mismo. Mismo criterio que `/instaladores` y `/ayuda/preguntas`.
 *
 * Port de `reactor-www/contrato/estado/index.php` con seis cambios, y el primero
 * es el pedido:
 *
 *   1. **EL PAGO PASA POR LA PUERTA QUE TIENE LOS CANDADOS.** El legacy traía su
 *      propio `contrato/estado/pagar.php` —24 líneas que volvían a armar la URL
 *      del cobrador— y era la TERCERA copia de ese armado en el sitio, cada una
 *      con sus propios chequeos: ninguno acá, dos en `comprobante/pagar.php`,
 *      cuatro en `pagar/index.php`. Acá el botón es un POST a
 *      `/comprobante/pagar`, que es la única puerta al cobrador y la que corre
 *      `comprobantePagable()`. Ese `pagar.php` local no se portó: no habría
 *      agregado una ruta, habría agregado una cuarta variante de los candados.
 *   2. **EL BOTÓN DE PAGO ES UN POST, NO UN `<a href>`.** Un GET que crea una
 *      preferencia de cobro del otro lado lo dispara solo cualquier cosa que siga
 *      los enlaces de la página: el prefetch del navegador, el antivirus del
 *      correo, el preview del cliente de mail. Acá eso importa más que en el
 *      visor: **esta página tiene hasta 21 botones de pago a la vez** (el contrato
 *      67813620), así que un solo prefetch abre veintiuna operaciones. Es el mismo
 *      motivo por el que `Aceptar` de las invitaciones dejó de ser un enlace.
 *   3. **LA DEUDA SE CUENTA UNA SOLA VEZ.** El legacy contaba el encabezado con
 *      `talonarioTipo='F' AND talonarioFiscal='0'` y listaba abajo con
 *      `talonarioTipo='F'`, así que el texto podía anunciar una cantidad y la
 *      lista mostrar otra. Ahora las dos consultas usan `CONTRATO_TIPOS_DEUDA`,
 *      que es el criterio de los dos jobs de mora.
 *   4. **SE DICE POR QUÉ UNA FACTURA NO SE PUEDE PAGAR**, con
 *      `comprobanteMotivoNoPagable()`. De los 113 pendientes con contrato hay 11
 *      que no son pagables —5 sin número de serie y 6 en $ 0,00, repartidos en 6
 *      contratos—: el legacy les dibujaba el botón igual y la persona terminaba en
 *      un error de MercadoPago, del otro lado, después de irse del sitio.
 *   5. **NO SE INVENTA UN CÓDIGO DE VALIDACIÓN.** El original cerraba el
 *      documento con "Código de validación: " y un `aleatorio(111111, 999999)`:
 *      un número que no se guardaba en ninguna parte, que cambiaba en cada
 *      recarga y que no validaba nada. En un papel que el cliente puede presentar
 *      como libre deuda, eso es peor que no poner nada. Lo reemplaza el dato que
 *      sí verifica: el número de contrato, que es consultable en esta misma URL.
 *   6. **LOS DATOS VACÍOS NO SE IMPRIMEN.** La frase era un `echo` con los cinco
 *      campos siempre, así que de los 64 clientes de la base los 39 sin domicilio
 *      leían "domicilio <b></b>, " y los 8 sin celular, lo mismo.
 *
 * NO SE INDEXA (`wwwRobots(false)`): lo que muestra es el titular, su domicilio,
 * su celular y cuánto debe. El legacy no ponía la meta.
 *
 * NO SE ESCRIBE NADA EN LA BASE — ver la cabecera de `lib/contratos.php`.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/contratos.php';

$contrato = contratoPorUuid(wwwEntrada('uid'));

if ($contrato === null) {
    // Mismo texto para el uid mal formado, el inexistente y el borrado: los tres
    // son "acá no hay nada" y distinguirlos le confirmaría a quien prueba
    // identificadores cuáles existen. Es lo que ya hace el visor del comprobante
    // y acá pesa más, porque son 8 dígitos (ver `lib/contratos.php`).
    wwwNoEncontrado('Ese contrato no existe o el enlace ya no es válido.', '/');
}

$resumen  = contratoDeudaResumen((int) $contrato['id']);
$facturas = contratoDeuda((int) $contrato['id']);

// "Debe" se decide por CANTIDAD y no por importe, igual que el legacy y por un
// motivo que hoy se puede medir: el contrato 27045294 tiene 4 facturas pendientes
// que suman $ 0,00. Decidir por el total lo declararía libre de deuda mientras el
// job del día 15 lo sigue viendo con cuatro pendientes vencidas, o sea mientras
// camina hacia la baja. La cantidad es lo que mira la mora.
$debe     = $resumen['cantidad'] > 0;
$ocultas  = max(0, $resumen['cantidad'] - count($facturas));

$titular   = contratoTitular($contrato);
$dominio   = trim((string) ($contrato['dominio_nombre'] ?? ''));
$celular   = trim((string) ($contrato['cliente_celular'] ?? ''));
$domicilio = trim((string) ($contrato['cliente_domicilio'] ?? ''));
$activo    = esHabilitado($contrato['habilitado']);

// La situación del DOMINIO y no la del contrato: es la columna que lee
// `app/index.php` para cortar los controles, o sea la que el cliente está viendo
// en su teléfono. La del contrato es el detalle interno de la mora y no se
// publica. Va traducida con el combo, nunca el número pelado.
$situacion = trim((string) ($contrato['dominio_situacion'] ?? ''));

// ¿El servicio está recortado por la mora? Sólo se dice de un contrato VIGENTE:
// sobre uno rescindido la frase sobraría y encima prometería algo falso —que al
// pagar se restablece solo—, cuando volver a dar de alta un contrato es una
// decisión que toma una persona (no hay contraparte automática de la baja).
$limitado = $activo
    && ($situacion === CONTRATO_SITUACION_LIMITADO || $situacion === CONTRATO_SITUACION_SUSPENDIDO);

// La vuelta del cobrador, con el mismo cartel que pinta el visor.
$resultado = comprobanteResultadoPago(wwwEntrada('res'));

wwwRobots(false);
wwwTitulo('Estado de Cuenta' . ($titular !== '' ? ' - ' . $titular : ''));

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>

<style>
    /* Renglón de una factura. Es el `.factura-fila` del legacy con el borde
       izquierdo de color, que es lo que separa una factura de la siguiente
       cuando hay veintiuna seguidas. */
    .estado-factura {
        background-color: rgba(0, 0, 0, 0.03);
        border-left: 4px solid #C11313;
        border-radius: 5px;
        padding: 14px 18px;
        margin-top: 12px;
    }

    .estado-factura.estado-impaga {
        border-left-color: #6c757d;
    }

    .estado-factura .estado-monto {
        font-size: 1.25em;
        font-weight: 700;
        color: #C11313;
    }

    .estado-factura .butn {
        margin: 5px 0 0 4px;
    }

    .estado-nota {
        color: #6c757d;
        font-size: 0.95em;
    }

    .estado-aviso {
        border-radius: 6px;
        padding: 14px 18px;
        margin-bottom: 22px;
        border-left: 5px solid;
    }

    .estado-aviso.ok {
        background: #e8f6ec;
        border-color: #1f8a3b;
        color: #14621f;
    }

    .estado-aviso.error {
        background: #fdeaea;
        border-color: #C11313;
        color: #8c1010;
    }

    .estado-aviso.aviso {
        background: #fff6e5;
        border-color: #d98c00;
        color: #7a4f00;
    }

    .estado-total {
        font-size: 1.1em;
    }

    /* El documento se imprime —es el libre deuda— y los botones no son parte del
       papel. El legacy imprimía la página entera con los botones adentro. */
    @media print {
        .estado-sinpapel {
            display: none !important;
        }
    }
</style>

<!-- PAGE TITLE
        ================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Estado de Cuenta</h1>
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
                        <li><a href="#!">Contrato</a></li>
                        <li><a href="#!">Estado</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- ESTADO DE CUENTA
        ================================================== -->
<section class="pt-0 blog-detail">
    <div class="container">
        <div class="row mt-3">
            <div class="col-lg-12 mb-2-9 mb-lg-0">
                <div class="posts-wrapper">

                    <?php if ($resultado !== null): ?>
                        <div class="estado-aviso <?= e($resultado['tono']) ?>">
                            <?= e($resultado['texto']) ?>
                            <?php if ($debe): ?>
                                <?php
                                // EL AVISO MÁS IMPORTANTE DE LA PÁGINA, y es nuevo.
                                // Este sitio no escribe en la base: la factura pasa a
                                // Cancelada cuando el back office concilia, no cuando
                                // MercadoPago aprueba. O sea que quien acaba de pagar
                                // vuelve acá y VE SU FACTURA TODAVÍA EN LA LISTA, con el
                                // botón Pagar al lado. En el legacy el `ret` también
                                // volvía a esta página y nada decía nada: la trampa
                                // estaba servida y lo que cuesta es que el cliente pague
                                // dos veces.
                                ?>
                                <div class="mt-2">
                                    <strong>El pago puede tardar un rato en figurar acá.</strong>
                                    Si la factura que acabás de abonar sigue apareciendo abajo, no la pagues de nuevo:
                                    se acredita cuando se procesa el cobro.
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <div class="post-content wow fadeIn" data-wow-delay="200ms">
                        <div class="post-meta">
                            <h2 class="h3"><?= $debe ? 'Estado de Cuenta' : 'Libre Deuda' ?></h2>
                        </div>
                        <div class="row">
                            <div class="col-md-12">

                                <?php
                                // La frase formal del legacy, armada por pedazos para que
                                // los campos vacíos no dejen un "domicilio <b></b>, " en
                                // medio del documento.
                                //
                                // OJO AL ESCAPADO: cada pedazo entra con `e()` acá abajo y
                                // el `implode` se imprime SIN escapar, porque lo que queda
                                // adentro son los `<b>` que pone esta misma página. Es la
                                // única salida sin `e()` del archivo y es por eso.
                                $datos = ['el contrato número <b>#' . e((string) $contrato['uuid']) . '</b>'];
                                if ($dominio !== '')   $datos[] = 'dominio <b>' . e($dominio) . '</b>';
                                if ($titular !== '')   $datos[] = 'titular <b>' . e($titular) . '</b>';
                                if ($celular !== '')   $datos[] = 'celular <b>' . e($celular) . '</b>';
                                if ($domicilio !== '') $datos[] = 'domicilio <b>' . e($domicilio) . '</b>';
                                ?>

                                <p>
                                    Por la presente se informa que al día
                                    <b><?= e(date('d/m/Y')) ?></b>,
                                    <?= implode(', ', $datos) ?>,
                                    <?php if ($debe): ?>
                                        tiene una deuda pendiente con Reactor (Alfatec SRL) de
                                        <b><?= e((string) $resumen['cantidad']) ?></b>
                                        factura<?= $resumen['cantidad'] === 1 ? '' : 's' ?> por
                                        <b class="estado-total"><?= e(comprobanteMoneda($resumen['total'])) ?></b>.
                                        <span class="estado-sinpapel">
                                            Puede abonarlas con los botones <b>Pagar</b> que están más abajo, en cada factura.
                                        </span>
                                    <?php else: ?>
                                        no tiene ningún tipo de deuda pendiente con Reactor (Alfatec SRL).
                                    <?php endif; ?>
                                    <?php
                                    // TRES CIERRES Y NO DOS, y el del medio es el que
                                    // arregla una contradicción que el legacy no podía
                                    // tener porque `dominios`.`situacion` no se leía acá.
                                    // `contratos`.`habilitado` dice si el contrato está
                                    // vigente y `dominios`.`situacion` si el servicio está
                                    // operando: son dos hechos distintos, pero con dos
                                    // cierres el contrato 09669239 —vigente, dominio
                                    // Suspendido— leía "Su servicio está activo" y dos
                                    // renglones después "situación Suspendido". Para el
                                    // cliente eso no son dos columnas, es una página que
                                    // se contradice sobre si le funciona el portón.
                                    ?>
                                    <?php if (!$activo): ?>
                                        Su contrato <b>#<?= e((string) $contrato['uuid']) ?></b> se encuentra rescindido.
                                    <?php elseif ($limitado): ?>
                                        Su contrato <b>#<?= e((string) $contrato['uuid']) ?></b> está vigente.
                                    <?php else: ?>
                                        Su servicio está activo.
                                    <?php endif; ?>
                                </p>

                                <?php if ($limitado): ?>
                                    <?php
                                    // Por qué la app está como está. Es `dominios`.`situacion`,
                                    // la columna que lee `app/index.php`, y decirlo acá es
                                    // la mitad útil del estado de cuenta: el cliente que
                                    // entró porque dejó de abrirle el portón encuentra en la
                                    // misma pantalla el motivo y el botón para resolverlo.
                                    // No es un trinquete: la tarea de las 04:00 recalcula
                                    // todos los días, así que al cancelar la deuda el
                                    // servicio vuelve solo.
                                    ?>
                                    <p class="estado-nota">
                                        Su servicio figura en situación
                                        <b><?= e(contratoSituacionTexto($situacion)) ?></b>
                                        por la deuda vencida.
                                        <?php if ($situacion === CONTRATO_SITUACION_SUSPENDIDO): ?>
                                            Al acreditarse el pago se restablece automáticamente.
                                        <?php else: ?>
                                            Si la deuda sigue sin regularizarse, el servicio se suspende.
                                        <?php endif; ?>
                                    </p>
                                <?php endif; ?>

                                <p class="estado-nota mb-0">
                                    <small>
                                        Fecha de emisión: <?= e(date('H:i d/m/Y')) ?>
                                        <br>
                                        Este documento se verifica en
                                        <?= e(contratoUrlEstado((string) $contrato['uuid'])) ?>
                                    </small>
                                </p>

                                <hr>

                                <div class="estado-sinpapel">
                                    <a href="/" class="butn lg border" style="margin-top: 15px;">
                                        <span><i class="fa fa-times" aria-hidden="true"></i> Cerrar</span>
                                    </a>
                                    <button type="button" class="butn lg" style="margin-top: 15px;" onclick="window.print()">
                                        <span><i class="fa fa-print" aria-hidden="true"></i> Imprimir</span>
                                    </button>
                                </div>

                            </div>
                        </div>
                    </div>

                    <?php if ($debe): ?>
                        <div class="post-content wow fadeIn" data-wow-delay="200ms">
                            <div class="post-meta">
                                <h2 class="h3">Facturas Pendientes</h2>
                            </div>
                            <div class="row">
                                <div class="col-md-12">

                                    <?php foreach ($facturas as $factura): ?>
                                        <?php
                                        // Las filas de `contratoDeuda()` traen los mismos
                                        // alias que `comprobantePorUuid()`, así que las dos
                                        // funciones del pago deciden acá con el MISMO código
                                        // que en el visor y que en el endpoint. Esconder el
                                        // botón no es el control: `/comprobante/pagar` vuelve
                                        // a chequear lo mismo del lado del servidor.
                                        $pagable = comprobantePagable($factura);
                                        $numero  = comprobanteNumero($factura['talonario_punto'], $factura['serie']);
                                        $hoja    = '/comprobante/hoja?uuid=' . rawurlencode((string) $factura['uuid']);
                                        ?>
                                        <div class="estado-factura<?= $pagable ? '' : ' estado-impaga' ?>">
                                            <div class="row align-items-center">

                                                <div class="col-md-7">
                                                    Factura:
                                                    <b><?= $numero !== null ? e($numero) : 'sin número asignado' ?></b>
                                                    <br>
                                                    Emisión: <b><?= e(comprobanteFecha($factura['emision'])) ?></b>
                                                    <?php if (comprobanteFecha($factura['vencimiento']) !== ''): ?>
                                                        <br>
                                                        Vencimiento: <b><?= e(comprobanteFecha($factura['vencimiento'])) ?></b>
                                                    <?php endif; ?>
                                                    <br>
                                                    Monto: <span class="estado-monto"><?= e(comprobanteMoneda($factura['total'])) ?></span>
                                                </div>

                                                <div class="col-md-5 text-md-end">
                                                    <a href="<?= e($hoja) ?>" class="butn lg" target="_blank" rel="noopener">
                                                        <span><i class="fa fa-eye" aria-hidden="true"></i> Abrir</span>
                                                    </a>
                                                    <?php if ($pagable): ?>
                                                        <form method="post" action="/comprobante/pagar" class="d-inline estado-sinpapel">
                                                            <input type="hidden" name="uuid" value="<?= e((string) $factura['uuid']) ?>">
                                                            <?php
                                                            // El interruptor del retorno, y es un
                                                            // interruptor y NO una URL: a dónde se
                                                            // vuelve lo resuelve el endpoint contra
                                                            // la base. Ver
                                                            // `contratoUuidDeComprobante()`.
                                                            ?>
                                                            <input type="hidden" name="volver" value="estado">
                                                            <button type="submit" class="butn lg">
                                                                <span><i class="fa fa-handshake" aria-hidden="true"></i> Pagar</span>
                                                            </button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>

                                            </div>

                                            <?php if (!$pagable): ?>
                                                <div class="estado-nota mt-2">
                                                    <?= e(comprobanteMotivoNoPagable($factura)) ?>
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>

                                    <?php if ($ocultas > 0): ?>
                                        <?php
                                        // UN TOPE QUE SE COME FILAS SE DICE. El resumen de
                                        // arriba cuenta toda la deuda y esta lista tiene
                                        // tope, así que sin este renglón el total no cerraría
                                        // con lo que se ve y el cliente no tendría cómo
                                        // saber por qué. El legacy cortaba en 24 en silencio.
                                        ?>
                                        <p class="estado-nota mt-3 mb-0">
                                            Hay <b><?= e((string) $ocultas) ?></b>
                                            factura<?= $ocultas === 1 ? '' : 's' ?>
                                            pendiente<?= $ocultas === 1 ? '' : 's' ?> más que no se muestran acá.
                                            Escribinos por <a href="/whatsapp" target="_blank" rel="noopener">WhatsApp</a>
                                            y las regularizamos juntos.
                                        </p>
                                    <?php endif; ?>

                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="estado-nota text-center">
                        ¿Dudas con tu cuenta? Escribinos por
                        <a href="/whatsapp" target="_blank" rel="noopener">WhatsApp</a>
                        o a <a href="mailto:info@reactor.com.ar">info@reactor.com.ar</a>.
                    </p>

                </div>
            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
