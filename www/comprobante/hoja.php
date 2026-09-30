<?php

declare(strict_types=1);

/**
 * La hoja del comprobante: `/comprobante/hoja?uuid=<uuid>`.
 *
 * Con `?imprimir=1` abre sola el diálogo de impresión, que es de donde sale el
 * PDF. Es lo que hacen los dos botones del visor y los dos atajos publicados
 * (`/comprobante/abrir` y `/comprobante/descargar`).
 *
 * POR QUÉ ES HTML Y NO UN PDF GENERADO. El legacy armaba el PDF con **dompdf**
 * (`reactor-api/framework/dompdf.php`), y el camino era una vuelta entera: la
 * página pedía por HTTP su propia `hoja?id=<id cifrado>` con `file_get_contents`,
 * le pasaba el HTML a dompdf y devolvía el binario. Acá no hay composer ni
 * `vendor/` en ninguna de las cuatro apps, y la imagen `php:8.2-apache` del repo
 * no trae `gd` —que dompdf necesita para el JPG del membrete—, así que traerlo
 * era sumar una dependencia y un cambio de Dockerfile para reproducir un
 * documento que el navegador ya sabe imprimir. La hoja se dibuja con CSS de
 * impresión y "Descargar" abre el diálogo: Guardar como PDF da el mismo papel.
 *
 * Lo que se pierde respecto del legacy es el NOMBRE del archivo, que ahí lo
 * fijaba `cComprobante::archivo()`. Se compensa con el `<title>`, que es lo que
 * los navegadores proponen al guardar: sale igual, "PREFACTURA - 001-007290 -
 * 2026-09-15".
 *
 * EL MEMBRETE ES UNA IMAGEN DE FONDO Y LAS POSICIONES SON ABSOLUTAS, igual que
 * en el legacy: `fondo.jpg` es una A4 completa con la banda gris del encabezado,
 * los recuadros blancos y la franja del pie, y cada bloque de texto tiene que
 * caer adentro del recuadro que le corresponde. Las coordenadas son las del
 * legacy trasladadas al origen de la página — ahí estaban tomadas contra el
 * margen de 43px que dompdf le ponía al body, así que cada una lleva ese
 * desplazamiento ya sumado. **Mover un bloque sin mirar la imagen lo saca del
 * recuadro.**
 *
 * NO SE INDEXA: el uuid es la única credencial. Esta página no pasa por
 * `sistema/cabeza.php` —es una hoja, no una página del sitio— así que la meta va
 * escrita acá.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/comprobantes.php';

$uuid        = wwwEntrada('uuid');
$comprobante = comprobantePorUuid($uuid);

if ($comprobante === null) {
    wwwNoEncontrado('Ese comprobante no existe o el enlace ya no es válido.', '/');
}

/**
 * A partir de cuántos renglones se aprieta el interlineado de la tabla.
 *
 * Con el interlineado normal entran 20 renglones en los 610px que el membrete
 * deja entre el encabezado y los recuadros del pie. El 21 empieza a montarse
 * encima de Observaciones — pasa de verdad: el comprobante más largo de la base
 * tiene 27. Ver `.cuerpo` en el CSS.
 */
const CUERPO_RENGLONES_DENSO = 20;

$renglones = comprobanteRenglones((int) $comprobante['id']);
$fondo     = comprobanteFondo($comprobante);
$archivo   = comprobanteArchivo($comprobante);
$imprimir  = wwwEntrada('imprimir') !== '';

$tipo   = comprobanteTexto(COMBO_TALONARIO_TIPO, $comprobante['talonario_tipo']);
$numero = comprobanteNumero($comprobante['talonario_punto'], $comprobante['serie']);
$visor  = '/comprobante/visor?uuid=' . rawurlencode($comprobante['uuid']);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <!-- El título es lo que el navegador propone como nombre al guardar como
         PDF desde el diálogo de impresión, así que es el nombre de archivo que
         armaba `cComprobante::archivo()` en el legacy. -->
    <title><?= e($archivo) ?></title>
    <link rel="shortcut icon" href="/favicon/android-icon-192x192.png">

    <style>
        /* ---------------------------------------------------- la hoja en sí */

        /* Tamaño de la página impresa. El margen en 0 porque el membrete llega
           hasta el borde del papel: con el margen por defecto del navegador, la
           banda gris del encabezado quedaría flotando adentro de un marco
           blanco. */
        @page {
            size: A4;
            margin: 0;
        }

        /* 789 x 1118 px a 96 dpi son 208,8 x 295,9 mm: entra en una A4
           (210 x 297) sin desbordar a una segunda hoja en blanco, que es lo que
           pasa si se redondea para arriba. Son las mismas medidas con las que
           el legacy dibujaba el fondo. */
        .hoja {
            position: relative;
            width: 789px;
            height: 1118px;
            margin: 0 auto;
            background-color: #fff;
            background-repeat: no-repeat;
            background-size: 789px 1118px;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            font-size: 11.5px;
            line-height: 1.5;
            color: #2b2b2b;
            overflow: hidden;
        }

        .hoja>div {
            position: absolute;
        }

        /* La letra del comprobante (A / B / X), en el recuadro chico que el
           membrete deja arriba al centro. */
        .letra {
            left: 375px;
            top: 0;
            width: 40px;
            height: 40px;
            text-align: center;
            font-size: 26px;
            font-weight: bold;
            line-height: 42px;
        }

        /* Tipo, número y datos fiscales de quien emite: el recuadro blanco de
           la derecha del encabezado. */
        .fiscal {
            left: 443px;
            top: 10px;
            width: 295px;
            height: 140px;
        }

        .fiscal .fiscal-tipo {
            font-size: 21px;
            font-weight: bold;
            text-transform: uppercase;
        }

        .fiscal .fiscal-numero {
            font-size: 14px;
            font-weight: bold;
        }

        .fiscal .fiscal-empresa {
            font-size: 10.5px;
            line-height: 1.45;
        }

        /* Datos del cliente, en la banda blanca de abajo del encabezado. */
        .cabeza-izquierda {
            left: 23px;
            top: 195px;
            width: 480px;
            height: 140px;
            overflow: hidden;
        }

        /* Fechas, CAE e identificador, alineados a la derecha de esa banda. */
        .cabeza-derecha {
            left: 503px;
            top: 195px;
            width: 265px;
            height: 140px;
            text-align: right;
        }

        /* Los renglones.

           EL ALTO DISPONIBLE ES FIJO: de `top: 363px` hasta los recuadros del
           pie, que arrancan en 973px, o sea 610px para el encabezado de la
           tabla, los renglones y el total. No es una preferencia estética —el
           membrete dibuja los recuadros ahí— así que lo que se pase se monta
           encima de Observaciones.

           `overflow: visible` A PROPÓSITO: si algo se desborda tiene que verse.
           Recortar en silencio un renglón es esconder lo que se facturó, y
           nadie lo notaría hasta que el cliente reclame. Que no se desborde lo
           resuelve `.denso` de acá abajo, no un recorte. */
        .cuerpo {
            left: 23px;
            top: 363px;
            width: 745px;
            overflow: visible;
        }

        /* COMPROBANTES LARGOS: se aprieta el renglón, no se corta la lista.
           Lo pone PHP cuando hay más de CUERPO_RENGLONES_DENSO renglones. Con
           el interlineado normal, los 27 renglones del comprobante más largo de
           la base (un presupuesto de 2021 con tres sistemas detallados) tapaban
           los dos recuadros del pie y dejaban el "Total ARS" encima de
           Observaciones. Así entran esos 27 y bastante más. */
        .cuerpo.denso {
            font-size: 10px;
        }

        .cuerpo.denso th,
        .cuerpo.denso td {
            padding: 0 3px;
            line-height: 1.35;
        }

        .cuerpo.denso .totales {
            margin-top: 6px;
            font-size: 11.5px;
            line-height: 1.5;
        }

        .cuerpo table {
            width: 100%;
            border-collapse: collapse;
        }

        .cuerpo th,
        .cuerpo td {
            padding: 2px 3px;
            vertical-align: top;
        }

        .cuerpo thead th {
            border-bottom: 1px solid #b5b5b5;
            font-weight: bold;
        }

        .col-cantidad {
            width: 90px;
            text-align: right;
        }

        .col-unitario,
        .col-monto {
            width: 100px;
            text-align: right;
        }

        .col-detalle {
            text-align: left;
        }

        .sin-detalle {
            text-align: center;
            padding: 30px 0 !important;
            color: #6c6c6c;
        }

        .totales {
            margin-top: 10px;
            padding-top: 6px;
            border-top: 1px solid #b5b5b5;
            text-align: right;
            font-size: 12.5px;
            line-height: 1.8;
        }

        /* Los dos recuadros blancos del pie. */
        .terminos,
        .observaciones {
            top: 973px;
            width: 360px;
            height: 100px;
            font-size: 9.5px;
            line-height: 1.45;
            overflow: hidden;
        }

        .terminos {
            left: 23px;
        }

        .observaciones {
            left: 413px;
        }

        /* La franja oscura del borde inferior: el texto va en blanco encima. */
        .contacto {
            left: 23px;
            top: 1091px;
            width: 745px;
            height: 20px;
            text-align: center;
            color: #fff;
            font-size: 11.5px;
        }

        /* ------------------------------------------------ la barra de arriba */

        body {
            margin: 0;
            background: #f0f0f0;
        }

        .barra {
            position: sticky;
            top: 0;
            z-index: 10;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            justify-content: center;
            padding: 12px;
            background: #3f3f3f;
            color: #fff;
            font-family: Verdana, Geneva, Tahoma, sans-serif;
            font-size: 13px;
        }

        .barra a,
        .barra button {
            display: inline-block;
            padding: 8px 16px;
            border: 0;
            border-radius: 4px;
            background: #C11313;
            color: #fff;
            font: inherit;
            text-decoration: none;
            cursor: pointer;
        }

        .barra a.secundario {
            background: #6c6c6c;
        }

        .marco {
            padding: 20px 10px 40px;
        }

        .marco .hoja {
            box-shadow: 0 2px 14px rgba(0, 0, 0, .25);
        }

        /* En el teléfono la hoja mide más que la pantalla: se encoge para que
           entre entera en vez de obligar a arrastrarla de lado. `zoom` no es
           estándar pero lo entienden todos los motores actuales, y si alguno lo
           ignora lo único que pasa es que hay scroll horizontal.
           El corte son los 789px de la hoja más los 10px de padding de cada
           lado: a partir de ahí entra sola y encogerla sería achicar sin
           motivo. */
        @media (max-width: 809px) {
            .marco .hoja {
                zoom: .46;
            }
        }

        /* ------------------------------------------------------- impresión */

        @media print {

            body {
                background: #fff;
            }

            .barra {
                display: none;
            }

            .marco {
                padding: 0;
            }

            .marco .hoja {
                box-shadow: none;
                zoom: 1;
            }
        }

        /* EL MEMBRETE ES UN FONDO, Y LOS FONDOS NO SE IMPRIMEN SALVO QUE SE PIDA.
           Sin esto el navegador manda la hoja al papel sin la banda gris, sin el
           logo y sin la franja del pie —y el texto blanco de `.contacto` queda
           invisible sobre blanco—. La regla va fuera del `@media print` porque
           Chrome la quiere aplicada al elemento, no sólo al imprimir. */
        .hoja {
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
    </style>
</head>

<body>

    <div class="barra">
        <span><?= e(trim($tipo . ' ' . (string) $numero)) ?></span>
        <button type="button" onclick="window.print()">Imprimir o guardar como PDF</button>
        <a class="secundario" href="<?= e($visor) ?>">Volver al comprobante</a>
    </div>

    <div class="marco">
        <div class="hoja"<?= $fondo !== '' ? ' style="background-image:url(\'' . e($fondo) . '\')"' : '' ?>>

            <div class="letra"><?= e($comprobante['talonario_subtipo'] ?? '') ?></div>

            <div class="fiscal">
                <div class="fiscal-tipo"><?= e($tipo) ?></div>
                <?php if ($numero !== null): ?>
                    <div class="fiscal-numero">Nº <?= e($numero) ?></div>
                <?php endif; ?>
                <div class="fiscal-empresa">
                    <?= e($comprobante['empresa_razon'] ?? '') ?><br>
                    <?= e($comprobante['empresa_condicion'] ?? '') ?><br>
                    Nº CUIT: <?= e($comprobante['empresa_cuit'] ?? '') ?><br>
                    <?php if (trim((string) ($comprobante['empresa_iibb'] ?? '')) !== ''): ?>
                        Nº IIBB: <?= e($comprobante['empresa_iibb']) ?><br>
                    <?php endif; ?>
                    <?php if (trim((string) ($comprobante['empresa_inicio'] ?? '')) !== ''): ?>
                        Inicio de Actividades: <?= e($comprobante['empresa_inicio']) ?>
                    <?php endif; ?>
                </div>
            </div>

            <div class="cabeza-izquierda">
                Cliente: <b><?= e(comprobanteCliente($comprobante)) ?></b><br>
                Domicilio: <b><?= e($comprobante['domicilio'] ?? '') ?></b><br>
                Correo: <b><?= e($comprobante['correo'] ?? '') ?></b><br>
                Celular: <b><?= e($comprobante['celular'] ?? '') ?></b><br>
                Condición Fiscal: <b><?= e(comprobanteTexto(COMBO_COMPROBANTE_CONDICION, $comprobante['condicion'])) ?></b><br>
                CUIT: <b><?= e($comprobante['cuit'] ?? '') ?></b>
            </div>

            <div class="cabeza-derecha">
                Emisión: <b><?= e(comprobanteFecha($comprobante['emision'])) ?></b><br>
                <?php if (comprobanteFecha($comprobante['vencimiento']) !== ''): ?>
                    Vencimiento: <b><?= e(comprobanteFecha($comprobante['vencimiento'])) ?></b><br>
                <?php endif; ?>
                <?php if (trim((string) ($comprobante['caenro'] ?? '')) !== ''): ?>
                    CAE Nro: <b><?= e($comprobante['caenro']) ?></b><br>
                    CAE Vto: <b><?= e($comprobante['caevto']) ?></b><br>
                <?php endif; ?>
                Identificador: <b><?= e($comprobante['uuid']) ?></b>
            </div>

            <div class="cuerpo<?= count($renglones) > CUERPO_RENGLONES_DENSO ? ' denso' : '' ?>">
                <table>
                    <thead>
                        <tr>
                            <th class="col-cantidad">Cantidad</th>
                            <th class="col-detalle">Detalle</th>
                            <th class="col-unitario">Unitario</th>
                            <th class="col-monto">Monto</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($renglones as $renglon): ?>
                            <tr>
                                <td class="col-cantidad"><?= e(comprobanteMoneda($renglon['cantidad'], '')) ?></td>
                                <td class="col-detalle"><?= e($renglon['detalle'] ?? '') ?></td>
                                <td class="col-unitario"><?= e(comprobanteMoneda($renglon['unitario'], '')) ?></td>
                                <td class="col-monto"><?= e(comprobanteMoneda($renglon['monto'], '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($renglones === []): ?>
                            <tr>
                                <td colspan="4" class="sin-detalle">No hay detalle</td>
                            </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="totales">
                    Total ARS: <b><?= e(comprobanteMoneda($comprobante['total'], '')) ?></b>
                </div>
            </div>

            <div class="terminos">
                <?php if (trim((string) ($comprobante['talonario_terminos'] ?? '')) !== ''): ?>
                    <?= nl2br(e($comprobante['talonario_terminos'])) ?>
                <?php endif; ?>
            </div>

            <div class="observaciones">
                <?php if (trim((string) ($comprobante['observaciones'] ?? '')) !== ''): ?>
                    Observaciones: <b><?= nl2br(e($comprobante['observaciones'])) ?></b>
                <?php endif; ?>
            </div>

            <div class="contacto">
                <?= e(trim(trim((string) ($comprobante['talonario_correo'] ?? '')) . ' | ' . trim((string) ($comprobante['talonario_web'] ?? '')), ' |')) ?>
            </div>

        </div>
    </div>

    <?php if ($imprimir): ?>
        <script>
            // "Descargar" llega acá con `?imprimir=1`. El diálogo se abre despues
            // del `load` y no en el `DOMContentLoaded`: si se dispara antes de que
            // baje `fondo.jpg`, Chrome imprime la hoja sin membrete.
            window.addEventListener('load', function() {
                window.print();
            });
        </script>
    <?php endif; ?>

</body>

</html>
