<?php

declare(strict_types=1);

/**
 * El pago: `/comprobante/pagar`.
 *
 * Arma la URL del cobrador de Databox y redirige. Las credenciales de
 * MercadoPago viven allá y no acá —este sitio no firma nada, no ve una tarjeta y
 * no toca la base—: lo único que hace es decir QUÉ comprobante se paga, por
 * cuánto, y a dónde volver.
 *
 * Port de `reactor-www/comprobante/pagar.php` con cuatro cambios, y tres de los
 * cuatro son el "correctamente" del pedido:
 *
 *   1. **VALIDA ANTES DE COBRAR.** El original leía el comprobante y redirigía,
 *      sin mirar nada: cualquier uuid mandaba a MercadoPago a cobrar. Un recibo
 *      —que es el acuse de un pago YA hecho— tenía botón, y una factura
 *      cancelada también, así que reabrir un correo viejo la volvía a cobrar.
 *      De las 1.222 prefacturas de la base hay 969 canceladas. Los cuatro
 *      candados están en `comprobantePagable()`, que es el mismo que usa el
 *      visor para decidir si dibuja el botón y el mismo que usa `/pagar/`: el
 *      chequeo del servidor es el control, esconder el botón no.
 *   2. **NINGUNA PÁGINA DE ESTE SITIO LO ENLAZA YA CON UN `<a href>`.** El botón
 *      del visor es un POST. Era `<a href="pagar?uuid=…&mod=i">`, o sea un GET
 *      que creaba una preferencia de cobro del otro lado, y eso lo dispara solo
 *      cualquier cosa que siga los enlaces de una página sin que nadie los
 *      toque: el prefetch del navegador, el antivirus del correo, el preview
 *      del cliente de mail. Es el mismo motivo por el que `Aceptar` de las
 *      invitaciones dejó de ser un enlace.
 *      **El GET se sigue atendiendo igual**, y no es un descuido: el enlace
 *      viejo está en correos ya enviados y en las pantallas del legacy, que
 *      siguen vivas. Lo que se sacó es la URL del HTML —que es lo que un
 *      prefetch encuentra—, no la ruta. Quien llega por ahí paga lo mismo, y
 *      pasa por los mismos candados del punto 1.
 *   3. **EL CONCEPTO SALE BIEN.** Ver `comprobanteUrlPago()`: el legacy leía un
 *      `$xComprobante->punto` que no existe y todas las preferencias salieron
 *      diciendo "Factura 000-3349".
 *   4. **LA VUELTA DEL COBRADOR SE MUESTRA.** El original tenía una rama que
 *      leía `res` y anunciaba "Su pago fue procesado correctamente", pero era
 *      código muerto: el `ret` que mandaba apuntaba al visor, no acá, así que
 *      nadie la alcanzaba nunca. Ahora el `ret` lleva el `res` al visor, que
 *      pinta el cartel Y el estado actualizado de la factura en la misma
 *      pantalla. La rama vieja se conserva igual —un `res` que llegue a este
 *      archivo se reenvía al visor— porque no sabemos con qué forma exacta
 *      contesta Databox y las dos terminan en el mismo lugar.
 *
 * NO SE ESCRIBE NADA EN LA BASE. Quien marca la factura como cancelada es el
 * circuito de pagos del back office cuando concilia; esta pantalla sólo deriva.
 * Por eso volver acá con `res=A` NO significa que el comprobante ya figure
 * pago: el estado que muestra el visor es el de la base, que es la verdad.
 *
 * ---
 *
 * **ES LA ÚNICA PUERTA AL COBRADOR, Y DESDE EL 01/10/2026 TIENE DOS ENTRADAS**: el
 * botón del visor y los botones *Pagar* del estado de cuenta
 * (`/contrato/estado?uid=`). El estado de cuenta NO trae su propio armado de la
 * URL de pago —el legacy sí: `contrato/estado/pagar.php`, una tercera copia con
 * cero chequeos— justamente para que los cuatro candados de
 * `comprobantePagable()` se apliquen una vez y valgan para las dos entradas.
 *
 * Lo único que cambia entre ellas es A DÓNDE SE VUELVE, y eso lo decide `volver`:
 * un interruptor de un valor, no una URL. Ver `$retorno` más abajo.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/comprobantes.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/contratos.php';

$uuid = wwwEntrada('uuid');

// Vuelta del cobrador. Se reconoce por `res` y no por `mod`, que es como lo
// decidía el legacy: ahí el default de `mod` caía en la rama del resultado, así
// que entrar a `/comprobante/pagar?uuid=X` sin `mod=i` contestaba "Hubo un
// resultado inesperado" en vez de arrancar el pago.
$resultado = wwwEntrada('res');
if ($resultado !== '') {
    wwwIr(comprobanteUrlVisor($uuid, $resultado));
}

$comprobante = comprobantePorUuid($uuid);
if ($comprobante === null) {
    wwwNoEncontrado('Ese comprobante no existe o el enlace ya no es válido.', '/');
}

// El motivo en criollo y no un 403 pelado: quien llega acá es alguien que abrió
// un correo y apretó un botón, y lo que necesita saber es si tiene que pagar o
// no. El visor ya lo dice con el estado a la vista.
if (!comprobantePagable($comprobante)) {
    wwwAviso(
        comprobanteMotivoNoPagable($comprobante),
        comprobanteUrlVisor($comprobante['uuid']),
        'No hay nada que pagar'
    );
}

/**
 * A dónde vuelve la persona cuando el cobrador termina.
 *
 * **DEL REQUEST SE LEE UN INTERRUPTOR, NUNCA UNA URL.** `volver=estado` dice
 * "vengo del estado de cuenta"; cuál es ese estado de cuenta lo resuelve
 * `contratoUuidDeComprobante()` contra la base, desde el comprobante que se está
 * pagando. Aceptar la URL de retorno tal como llega sería un redirect abierto
 * firmado por nosotros y servido **desde la pasarela de pago**, que es el mejor
 * lugar posible para plantar una pantalla falsa de cobro: la persona llega ahí
 * después de tipear los datos de su tarjeta, viniendo de MercadoPago, y lo que
 * vea lo va a leer como parte del trámite. El legacy lo armaba así en
 * `contrato/estado/pagar.php`, aunque ahí la URL era fija y no venía del request.
 *
 * El default sigue siendo el visor, que es de donde viene el botón del correo: es
 * la pantalla que muestra el estado de ESE comprobante. Y es también el fallback
 * cuando `volver=estado` llega sobre un comprobante que no cuelga de ningún
 * contrato —hay 5 pendientes así— porque entonces no hay estado de cuenta al que
 * volver.
 */
$retorno = comprobanteUrlVisor($comprobante['uuid']);

if (wwwEntrada('volver') === 'estado') {
    $contrato = contratoUuidDeComprobante((int) $comprobante['id']);
    if ($contrato !== '') {
        $retorno = contratoUrlEstado($contrato);
    }
}

wwwIr(comprobanteUrlPago($comprobante, $retorno));
