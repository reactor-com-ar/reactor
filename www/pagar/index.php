<?php

declare(strict_types=1);

/**
 * El enlace corto de pago: `/pagar/?uid=<uuid>`.
 *
 * ES UNA URL QUE EL SISTEMA NUEVO YA EMITE. La arma `comprobanteSalida()` en
 * `cloud/api/comprobantes_lib.php` (la constante `PAGO_BASE`) y la abre el ítem
 * *Botón de pago* del menú de Comercial → Comprobantes; del otro lado la
 * enlazaba `reactor-www/pagar/index.php`. Hasta ahora ese ítem apuntaba a una
 * ruta que en este sitio no existía.
 *
 * Es el mismo comprobante que el visor y por eso MANDA AL VISOR en vez de saltar
 * al cobrador como hacía el legacy. Tres motivos:
 *
 *   - **Quien lo abre desde cloud es un operador**, que lo que necesita es ver
 *     el comprobante —y copiar el enlace para mandárselo a alguien—, no caer
 *     parado en una pantalla de MercadoPago.
 *   - **Quien lo recibe es un cliente**, y pagar sin ver qué se está pagando no
 *     es un atajo: es un formulario de cobro sin contexto. El visor muestra el
 *     total, el detalle y el estado, con el botón al lado.
 *   - **Un GET no crea una preferencia de cobro.** Saltar al cobrador desde un
 *     enlace hace que el prefetch del navegador o el preview del cliente de
 *     correo abran una operación del otro lado sin que nadie la haya pedido. En
 *     el visor el pago sale por POST.
 *
 * El `uid` se lleva a mayúsculas como en el legacy: los uuid son alfanuméricos
 * en mayúscula y quien tipea el enlace a mano no tiene por qué saberlo.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/comprobantes.php';

// El legacy leía `uid` acá y `uuid` en `/comprobante/`. Se aceptan los dos: son
// la misma cosa con dos nombres y hay enlaces publicados de las dos formas.
$uuid = mb_strtoupper(wwwEntrada('uid') !== '' ? wwwEntrada('uid') : wwwEntrada('uuid'));

if (!comprobanteUuidValido($uuid)) {
    wwwNoEncontrado('Ese comprobante no existe o el enlace ya no es válido.', '/');
}

wwwIr(comprobanteUrlVisor($uuid));
