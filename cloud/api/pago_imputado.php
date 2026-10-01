<?php

declare(strict_types=1);

/**
 * AVISO DE PAGO IMPUTADO: recalcula la situacion del cliente que acaba de pagar.
 *
 *   POST /api/pago_imputado.php
 *   X-Reactor-Firma: <hmac-sha256 del cuerpo con PAGO_WEBHOOK_SECRET>
 *   {"comprobante": 5652}            // o {"contrato": 143} / {"dominio": 101}
 *
 * QUIEN LO LLAMA ES EL WEBHOOK DE MERCADOPAGO DEL SISTEMA LEGACY,
 * `reactor-api/v2/mercadopago/imputar.php`, que es donde entran los pagos: ese
 * archivo marca la factura como Cancelada (`cComprobante::pagoRegistrar()`,
 * estado 3) y emite el recibo. Lo que NO puede hacer es calcular la mora con las
 * reglas de este repo, asi que avisa y la cuenta la hace este endpoint.
 *
 * ------------------------------------------------------------------------
 * POR QUE UN ENDPOINT Y NO UNA LINEA MAS EN EL LEGACY
 * ------------------------------------------------------------------------
 *
 * El legacy YA recalcula la situacion despues de imputar: `pagoRegistrar()` de
 * `framework/subframework.php` cierra llamando a
 * `cDominio::situacionDetectar()`. Pero esa cuenta NO es la de este repo, y las
 * diferencias no son de detalle:
 *
 *   - LAS BANDAS SON OTRAS. `situacionDetectar()` compara contra
 *     `$zContrato->intimar`, que es una propiedad de clase con valor 1 -- no una
 *     columna, `contratos` no tiene ni `intimar` ni `suspender` --. O sea que
 *     alla "Normal" es un atraso de hasta UN dia y de 2 a 30 es Limitado. La
 *     clase declara ademas `$limitar = 15`, que es el umbral que el codigo
 *     deberia usar y NO USA en ningun lado: es un bug viejo, no un criterio.
 *     Aca Normal es menos de 15 dias (`UMBRAL_LIMITADO`).
 *   - MIRA DOS TALONARIOS HARDCODEADOS (38 y 48) en vez de los tipos `F` y `T`,
 *     asi que ignora cualquier talonario de factura que se cree de ahora en mas.
 *   - TOMA EL PRIMER `vencimiento` POR `id`, no el `MIN()`: con comprobantes
 *     cargados fuera de orden, el atraso sale del que no es.
 *   - SOLO ATIENDE AL CONTRATO QUE CUELGA DE `dominios`.`contrato`, una columna
 *     que hoy tienen 32 de los 148 dominios. Los otros 116 no se recalculan
 *     nunca por ese camino.
 *   - NO ESCRIBE `contratos`.`situacion`, que el legacy no conoce.
 *
 * Con los dos caminos vivos, `dominios`.`situacion` tiene dos escritores que no
 * coinciden, y gana el ultimo que corrio. POR ESO, AL ENGANCHAR ESTE ENDPOINT
 * HAY QUE NEUTRALIZAR EL RECALCULO DEL LEGACY -- comentar el bloque
 * `// actualiza siguacion` de `pagoRegistrar()` --. Si no, una imputacion deja al
 * dominio como lo dejo `situacionDetectar()` y este endpoint lo vuelve a mover.
 *
 * ------------------------------------------------------------------------
 * COMO SE AUTENTICA
 * ------------------------------------------------------------------------
 *
 * NO con el JWT de cloud: quien llama es un servidor, no una persona con sesion.
 * Va con HMAC-SHA256 del cuerpo crudo contra `PAGO_WEBHOOK_SECRET` del `.env`,
 * comparado con `hash_equals()` -- comparacion en tiempo constante, para que el
 * error no se pueda adivinar byte por byte.
 *
 * NO SE ACEPTA UN SECRETO VACIO NI FALTANTE: sin la constante definida el
 * endpoint responde 503 y no hace nada. Un webhook que se abre solo cuando falta
 * la configuracion es una puerta sin llave, y lo que hay del otro lado es
 * devolverle el servicio a un cliente suspendido.
 *
 * ES IDEMPOTENTE Y NO ESCRIBE PAGOS. No inserta en `pagos` ni toca
 * `comprobantes`: eso ya lo hizo el legacy, que es el unico que sabe lo que
 * MercadoPago aprobo. Esto solo RECALCULA a partir de lo que quedo en la base,
 * asi que llamarlo dos veces por el mismo pago da el mismo resultado.
 */

// Es un webhook server-to-server: no hay cookie ni JWT que validar.
// `bootstrap.php` exige sesion salvo que esto este definido ANTES del require.
const CLOUD_API_PUBLIC = true;

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/contratos_situacion_lib.php';
require_once __DIR__ . '/lib/sucesos.php';

/** Cabecera donde viaja la firma del cuerpo. */
const FIRMA_HEADER = 'HTTP_X_REACTOR_FIRMA';

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        json_error('Metodo no permitido', 405);
    }

    $cuerpo = (string) file_get_contents('php://input');
    asertarFirma($cuerpo);

    $in = json_decode($cuerpo, true);
    if (!is_array($in)) json_error('Cuerpo invalido: se espera un JSON', 422);

    $dominio = resolverDominio($in);
    if ($dominio === null) {
        // No es un error del que llama: hay comprobantes sin contrato y
        // contratos sin dominio. No hay a quien recalcularle la situacion.
        json_ok(['recalculado' => false, 'motivo' => 'El pago no corresponde a ningun dominio']);
    }

    $r = recalcularSituacionTrasPago(db(), $dominio, descripcionOrigen($in));

    // Un fallo del recalculo NO es un 500 para el legacy: el pago ya esta
    // imputado del otro lado y reintentar el webhook no lo arregla. Se contesta
    // 200 con el detalle, queda el suceso en `error`, y la tarea de las 04:00
    // corrige la situacion al dia siguiente.
    json_ok([
        'recalculado' => $r['ok'],
        'dominio'     => $dominio,
        'situacion'   => $r['dominio'],
        'cambios'     => $r['cambios'],
        'error'       => $r['error'],
    ]);
} catch (Throwable $e) {
    json_error('Error al procesar el aviso de pago: ' . $e->getMessage(), 500);
}

/**
 * Valida la firma del cuerpo, o corta.
 *
 * `hash_equals()` y no `===`: la comparacion tiene que ser en tiempo constante o
 * el atacante aprende el prefijo correcto midiendo cuanto tarda el rechazo.
 */
function asertarFirma(string $cuerpo): void
{
    if (!defined('PAGO_WEBHOOK_SECRET') || trim((string) PAGO_WEBHOOK_SECRET) === '') {
        json_error('El webhook de pagos no esta configurado', 503);
    }

    $firma = trim((string) ($_SERVER[FIRMA_HEADER] ?? ''));
    if ($firma === '') json_error('Falta la firma', 401);

    $esperada = hash_hmac('sha256', $cuerpo, (string) PAGO_WEBHOOK_SECRET);

    if (!hash_equals($esperada, $firma)) json_error('Firma invalida', 401);
}

/**
 * A que dominio hay que recalcularle la situacion.
 *
 * Se acepta cualquiera de las tres referencias porque el legacy tiene a mano la
 * del comprobante (`fac`) y las otras dos ahorran un viaje cuando se las conoce.
 * La cadena es siempre la misma: comprobante -> contrato -> dominio.
 */
function resolverDominio(array $in): ?int
{
    $dominio = (int) ($in['dominio'] ?? 0);
    if ($dominio > 0) return $dominio;

    $contrato = (int) ($in['contrato'] ?? 0);

    if ($contrato <= 0) {
        $comprobante = (int) ($in['comprobante'] ?? 0);
        if ($comprobante <= 0) json_error('Mandá `comprobante`, `contrato` o `dominio`', 422);

        $stmt = db()->prepare('SELECT contrato FROM comprobantes WHERE id = :id');
        $stmt->execute([':id' => $comprobante]);
        $fila = $stmt->fetch();
        if ($fila === false) json_error('El comprobante no existe', 404);

        $contrato = (int) ($fila['contrato'] ?? 0);
        if ($contrato <= 0) return null;
    }

    $stmt = db()->prepare('SELECT dominio FROM contratos WHERE id = :id');
    $stmt->execute([':id' => $contrato]);
    $fila = $stmt->fetch();
    if ($fila === false) json_error('El contrato no existe', 404);

    $dominio = (int) ($fila['dominio'] ?? 0);

    return $dominio > 0 ? $dominio : null;
}

/** De donde vino el aviso, para el suceso. */
function descripcionOrigen(array $in): string
{
    foreach (['comprobante', 'contrato', 'dominio'] as $clave) {
        $v = (int) ($in[$clave] ?? 0);
        if ($v > 0) return sprintf('tras la imputación de un pago (%s #%d)', $clave, $v);
    }

    return 'tras la imputación de un pago';
}
