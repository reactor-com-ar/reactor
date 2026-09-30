<?php

declare(strict_types=1);

/**
 * `/comprobante/descargar?uuid=<uuid>` -> la hoja, con el diálogo de impresión
 * ya abierto.
 *
 * ES UNA URL PUBLICADA, igual que `abrir.php`: la enlazan el back office viejo y
 * el panel legacy, que siguen vivos del otro lado de la transición.
 *
 * En el legacy devolvía el PDF como adjunto (`Content-Disposition: attachment`).
 * Acá el PDF lo hace el navegador desde el diálogo de impresión —"Guardar como
 * PDF"—, así que "descargar" es la hoja con `imprimir=1`. El nombre del archivo
 * que propone el diálogo es el `<title>` de la hoja, que es exactamente el que
 * armaba `cComprobante::archivo()`.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwIr('/comprobante/hoja?uuid=' . rawurlencode(wwwEntrada('uuid')) . '&imprimir=1');
