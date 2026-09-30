<?php

declare(strict_types=1);

/**
 * `/comprobante/abrir?uuid=<uuid>` -> la hoja.
 *
 * ES UNA URL PUBLICADA Y POR ESO EXISTE ESTE ARCHIVO. La enlazan el back office
 * viejo (`reactor-admin/comprobantes/consultar.php`), el panel legacy
 * (`reactor-panel/comprobantes/consultar.php`) y la ficha de contrato de
 * `reactor-www` — las tres, del otro lado de la transición y todavía vivas. Sin
 * esto, el botón "Abrir" de esas tres pantallas pasa a 404.
 *
 * En el legacy devolvía el PDF inline; acá manda a la hoja, que es el mismo
 * documento en HTML (ver la cabecera de `hoja.php`). Se redirige en vez de
 * dibujar para que el comprobante tenga UNA sola URL canónica: la hoja también
 * se abre desde el visor, y con dos rutas que pintan lo mismo cualquier arreglo
 * hay que acordarse de hacerlo dos veces.
 *
 * El 302 y no un 301: el destino de este atajo puede cambiar —el día que la
 * hoja se sirva como PDF de verdad, por ejemplo— y un 301 se lo queda el
 * navegador para siempre.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwIr('/comprobante/hoja?uuid=' . rawurlencode(wwwEntrada('uuid')));
