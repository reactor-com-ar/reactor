<?php

declare(strict_types=1);

/**
 * Comprobantes: la ficha que se abre desde el correo y el botón de pago.
 *
 * Port de lo que en el legacy vivía repartido entre `reactor-www/comprobante/`
 * y las clases `cComprobante` / `cTalonario` / `cEmpresa` / `cCliente` del
 * framework viejo. Acá es una consulta sola contra PDO y un puñado de
 * formateadores; nada del framework se trajo.
 *
 * EL `uuid` ES LA ÚNICA CREDENCIAL, Y ESO ORDENA TODO LO DEMÁS. Este docroot no
 * tiene login: quien tenga el enlace ve el comprobante entero —razón social,
 * CUIT, domicilio, correo, celular y el detalle de lo facturado—. Es el diseño
 * del legacy y es lo que hace que el correo funcione: se manda un enlace y se
 * abre sin pedir nada. Las tres consecuencias que hay que respetar:
 *
 *   - **El uuid se valida por FORMA antes de tocar la base**
 *     (`comprobanteUuidValido()`): 16 caracteres alfanuméricos en mayúscula,
 *     que es exactamente lo que genera `comprobanteUuidLibre()` en cloud y lo
 *     que tienen los 2.332 comprobantes emitidos (mínimo 16, máximo 16, cero
 *     vacíos). No es defensa contra inyección —para eso están los placeholders—
 *     sino contra el barrido: un uuid mal formado se contesta 404 sin consultar.
 *   - **Las páginas que lo muestran van `noindex`.** Un comprobante indexado es
 *     el domicilio y el CUIT de un cliente en los resultados de Google. El
 *     legacy no lo ponía.
 *   - **No hay enumeración posible**: 36^16 combinaciones y el uuid no es
 *     correlativo. Por eso NO hace falta cupo por IP acá, a diferencia del chat.
 *
 * NO SE ESCRIBE NADA. El sitio público lee el comprobante y redirige al
 * cobrador; quien marca la factura como cancelada es el circuito de pagos del
 * back office, no esta pantalla.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pagina.php';   // wwwBaseUrl(), para el retorno del cobrador

/* ------------------------------------------------------------------ códigos */

/**
 * `comprobantes`.`estado` es varchar(1) con cuatro valores y transiciones
 * propias — Preparación -> Pendiente -> Cancelado, y Anulado como salida. NO es
 * la bandera `habilitado` ni una variante suya, así que se compara como STRING:
 * '0' (Anulado) es un valor real y con un `empty()` de por medio se confunde
 * con "sin estado". Son los mismos cuatro de `cloud/api/comprobantes_lib.php`.
 */
const COMPROBANTE_ANULADO     = '0';
const COMPROBANTE_PREPARACION = '1';
const COMPROBANTE_PENDIENTE   = '2';
const COMPROBANTE_CANCELADO   = '3';

/**
 * `talonarios`.`tipo`. Sólo se paga la prefactura ('F'), que es lo que el
 * sistema le emite al cliente: los otros seis tipos son presupuestos, remitos,
 * recibos y notas de crédito, que no se cobran desde acá.
 */
const COMPROBANTE_TIPO_PREFACTURA = 'F';

/** Claves de `combos` con los textos de los códigos cortos. */
const COMBO_TALONARIO_TIPO     = '$xTalonario->tipo';
const COMBO_COMPROBANTE_ESTADO = '$xComprobante->estado';
const COMBO_COMPROBANTE_CONDICION = '$xComprobante->condicion';

/**
 * Último recurso si `combos` no tiene cargada la clave. Sin esto, una fila
 * borrada del back office dejaría la hoja sin decir qué tipo de comprobante es.
 */
const COMPROBANTE_COMBOS_FALLBACK = [
    COMBO_TALONARIO_TIPO     => [
        'P' => 'Presupuesto', 'D' => 'Pedido', 'F' => 'Prefactura', 'T' => 'Factura',
        'R' => 'Recibo', 'M' => 'Remito', 'N' => 'Nota de Crédito',
    ],
    COMBO_COMPROBANTE_ESTADO => [
        '0' => 'Anulado', '1' => 'Preparación', '2' => 'Pendiente', '3' => 'Cancelado',
    ],
];

/** Dígitos del punto de venta y del correlativo en el número impreso. */
const COMPROBANTE_PUNTO_DIGITOS = 3;
const COMPROBANTE_SERIE_DIGITOS = 6;

/** Largo exacto del `uuid`, el que genera `cCadena::aleatoria(16, '0A')`. */
const COMPROBANTE_UUID_LARGO = 16;

/** Carpeta donde se resuelve `talonarios`.`fondo`, que guarda sólo el nombre. */
const COMPROBANTE_FONDO_BASE = '/comprobante/';

/* -------------------------------------------------------------------- datos */

/** ¿Tiene el uuid la forma que genera el sistema? 16 alfanuméricos en mayúscula. */
function comprobanteUuidValido(string $uuid): bool
{
    return strlen($uuid) === COMPROBANTE_UUID_LARGO
        && ctype_alnum($uuid)
        && strtoupper($uuid) === $uuid;
}

/**
 * El comprobante con todo lo que la hoja necesita, o `null`.
 *
 * Una sola consulta con los tres LEFT JOIN: el talonario da el tipo, el punto y
 * el pie de página; la empresa, los datos fiscales de quien emite; el cliente,
 * el nombre de fantasía. Los tres son LEFT y no INNER a propósito — hay 104
 * comprobantes sin `cliente` (se factura contra `razon`, el texto libre) y un
 * comprobante con talonario suelto no puede desaparecer de la vista del cliente
 * por un dato que le falta al sistema.
 */
function comprobantePorUuid(string $uuid): ?array
{
    if (!comprobanteUuidValido($uuid)) {
        return null;
    }

    $sql = db()->prepare(
        'SELECT c.id, c.uuid, c.serie, c.caenro, c.caevto, c.emision, c.vencimiento,
                c.cliente, c.razon, c.condicion, c.cuit, c.domicilio, c.correo,
                c.celular, c.subtotal, c.iva, c.total, c.observaciones, c.estado,
                t.tipo    AS talonario_tipo,
                t.subtipo AS talonario_subtipo,
                t.punto   AS talonario_punto,
                t.correo  AS talonario_correo,
                t.web     AS talonario_web,
                t.fondo   AS talonario_fondo,
                t.terminos AS talonario_terminos,
                e.razon     AS empresa_razon,
                e.condicion AS empresa_condicion,
                e.cuit      AS empresa_cuit,
                e.iibb      AS empresa_iibb,
                e.inicio    AS empresa_inicio,
                cl.nombre AS cliente_nombre,
                cl.razon  AS cliente_razon
           FROM comprobantes c
           LEFT JOIN talonarios t  ON t.id  = c.talonario
           LEFT JOIN empresas   e  ON e.id  = t.empresa
           LEFT JOIN clientes   cl ON cl.id = c.cliente
          WHERE c.uuid = :uuid
          LIMIT 1'
    );
    $sql->execute([':uuid' => $uuid]);

    $fila = $sql->fetch();

    return $fila === false ? null : $fila;
}

/** Los renglones, en el orden en que se imprimen. */
function comprobanteRenglones(int $id): array
{
    $sql = db()->prepare(
        'SELECT cantidad, detalle, unitario, monto
           FROM comprobantesrenglones
          WHERE comprobante = :id
          ORDER BY orden ASC, id ASC'
    );
    $sql->execute([':id' => $id]);

    return $sql->fetchAll();
}

/**
 * Tabla plana valor -> texto de un combo del sistema histórico.
 *
 * Es el `$oCampo->comboTraducir()` del legacy, que hacía una consulta POR
 * VALOR: la hoja traduce cuatro códigos y eran cuatro viajes a la base. Acá se
 * trae el combo entero de una y se cachea por request.
 */
function comprobanteCombo(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) {
        return $cache[$clave];
    }

    $textos = [];
    try {
        $sql = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, id ASC');
        $sql->execute([':c' => $clave]);
        foreach ($sql->fetchAll() as $fila) {
            $valor = trim((string) ($fila['valor'] ?? ''));
            if ($valor === '') continue;
            $textos[$valor] = (string) ($fila['texto'] ?? '');
        }
    } catch (Throwable $e) {
        $textos = [];
    }

    if ($textos === []) {
        $textos = COMPROBANTE_COMBOS_FALLBACK[$clave] ?? [];
    }

    return $cache[$clave] = $textos;
}

/** Texto de un código corto, o el código crudo si el combo no lo tiene. */
function comprobanteTexto(string $clave, mixed $valor): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return '';

    return comprobanteCombo($clave)[$v] ?? $v;
}

/* ---------------------------------------------------------------- presentar */

/**
 * Número impreso: `punto-serie` con 3 y 6 dígitos ("001-007290").
 *
 * Con `serie = 0` devuelve `null` y NO "001-000000": el cero significa que el
 * comprobante todavía no se autorizó, o sea que no tiene número. Imprimir un
 * cero relleno lo haría parecer el comprobante número cero de la serie. Es la
 * misma regla —y el mismo formato— que `comprobanteNumero()` de cloud.
 */
function comprobanteNumero(mixed $punto, mixed $serie): ?string
{
    $s = (int) $serie;
    if ($s <= 0) return null;

    return str_pad((string) (int) $punto, COMPROBANTE_PUNTO_DIGITOS, '0', STR_PAD_LEFT)
        . '-' . str_pad((string) $s, COMPROBANTE_SERIE_DIGITOS, '0', STR_PAD_LEFT);
}

/**
 * Importe con punto de miles y coma decimal, como `$oNumero->moneda()`.
 * El prefijo va explícito: la hoja imprime "$ " y el concepto del cobrador no.
 */
function comprobanteMoneda(mixed $monto, string $prefijo = '$ '): string
{
    return $prefijo . number_format((float) $monto, 2, ',', '.');
}

/**
 * Fecha `AAAA-MM-DD` de la base en `dd/mm/aaaa`, o '' si está vacía o es un
 * centinela del sistema histórico.
 *
 * El `'1500-01-01'` no es una fecha, es el "sin valor" del legacy —la misma
 * marca que dejó en las filas de `mensajes`—: imprimirlo daría un vencimiento
 * en el siglo XVI en medio de una factura.
 */
function comprobanteFecha(mixed $fecha, string $formato = 'd/m/Y'): string
{
    $v = trim((string) ($fecha ?? ''));
    if ($v === '' || str_starts_with($v, '1500-01-01') || str_starts_with($v, '0000-00-00')) {
        return '';
    }

    $d = date_create($v);

    return $d === false ? '' : $d->format($formato);
}

/**
 * Nombre del archivo, el mismo que armaba `cComprobante::archivo()`:
 * "PREFACTURA - 001-007290 - 2026-09-15.pdf".
 *
 * Lo usa la hoja como `<title>`, que es lo que el navegador propone al guardar
 * como PDF desde el diálogo de impresión. Sin él el archivo saldría llamándose
 * como la URL.
 */
function comprobanteArchivo(array $c, string $extension = ''): string
{
    $tipo   = comprobanteTexto(COMBO_TALONARIO_TIPO, $c['talonario_tipo'] ?? '');
    $numero = comprobanteNumero($c['talonario_punto'] ?? null, $c['serie'] ?? null);
    $fecha  = comprobanteFecha($c['emision'] ?? null, 'Y-m-d');

    $partes = array_filter([$tipo !== '' ? $tipo : 'COMPROBANTE', $numero, $fecha]);

    return mb_strtoupper(implode(' - ', $partes)) . $extension;
}

/** Cómo se llama el cliente en la hoja: la razón del comprobante manda. */
function comprobanteCliente(array $c): string
{
    $razon = trim((string) ($c['razon'] ?? ''));

    // Con cliente cargado el legacy imprimía "Razón (Nombre de fantasía)", y la
    // razón que usa es la del CLIENTE, no la congelada en el comprobante.
    if ((int) ($c['cliente'] ?? 0) > 0) {
        $clienteRazon  = trim((string) ($c['cliente_razon']  ?? ''));
        $clienteNombre = trim((string) ($c['cliente_nombre'] ?? ''));

        if ($clienteRazon !== '' && $clienteNombre !== '' && $clienteRazon !== $clienteNombre) {
            return $clienteRazon . ' (' . $clienteNombre . ')';
        }
        if ($clienteRazon !== '')  return $clienteRazon;
        if ($clienteNombre !== '') return $clienteNombre;
    }

    return $razon;
}

/** Fondo del talonario resuelto a una URL del sitio, o '' si no hay archivo. */
function comprobanteFondo(array $c): string
{
    $fondo = trim((string) ($c['talonario_fondo'] ?? ''));
    if ($fondo === '') return '';

    // `talonarios`.`fondo` guarda SÓLO el nombre del archivo ("fondo.jpg") — en
    // el legacy lo resolvía el chroot de dompdf contra
    // `/var/www/reactor-www/comprobante/`. Acá se resuelve contra la misma
    // carpeta del sitio, y sólo si el archivo existe: una hoja sin membrete se
    // lee igual, una que pide una imagen que no está queda con el ícono roto.
    $fondo = basename($fondo);
    if (!is_file($_SERVER['DOCUMENT_ROOT'] . COMPROBANTE_FONDO_BASE . $fondo)) {
        return '';
    }

    return COMPROBANTE_FONDO_BASE . $fondo;
}

/* --------------------------------------------------------------------- pago */

/**
 * Cobrador de Databox. El mismo endpoint del legacy: arma la preferencia de
 * MercadoPago con las credenciales de la cuenta `reactor` —que viven allá, no
 * acá— y devuelve al comprador a `ret` cuando termina.
 */
const PAGO_ENDPOINT = 'https://api.databox.net.ar/v2/mercadopago/pagar';

/** Cuenta de Databox contra la que se cobra. */
const PAGO_CUENTA = 'reactor';

/**
 * ¿Se puede pagar este comprobante?
 *
 * Los cuatro candados, y los cuatro importan:
 *
 *   1. ES UNA PREFACTURA ('F'). Es lo que el sistema le emite al cliente para
 *      cobrarle. Un recibo ya es el acuse de un pago hecho y un presupuesto no
 *      es una deuda: mandarlos al cobrador es cobrar dos veces o cobrar algo
 *      que nadie debe.
 *   2. ESTÁ PENDIENTE ('2'). En Preparación es un borrador que todavía se
 *      edita, Cancelado YA SE PAGÓ y Anulado no existe. De los 1.222
 *      comprobantes tipo 'F' de la base, 969 están cancelados: sin este
 *      candado, reabrir un correo viejo vuelve a cobrar una factura paga.
 *   3. EL TOTAL ES MAYOR A CERO. Hay prefacturas en 0,00 —la última emitida lo
 *      está— y MercadoPago rechaza una preferencia de importe cero con un error
 *      suyo, del otro lado, después de que la persona ya se fue del sitio.
 *   4. ESTÁ AUTORIZADO, o sea tiene número de serie. Un comprobante sin número
 *      no se le puede mostrar a nadie como comprobante de pago.
 *
 * DOS DE LOS CUATRO SON NUEVOS RESPECTO DE `comprobante/pagar.php` DEL LEGACY,
 * que no chequeaba nada: leía el comprobante y redirigía. Los tenía sólo la otra
 * puerta, `pagar/index.php`, que sí exigía 'F' y estado 2 — o sea que la misma
 * factura era pagable dos veces según por qué enlace se entrara. Acá las dos
 * puertas usan esta función.
 */
function comprobantePagable(array $c): bool
{
    return trim((string) ($c['talonario_tipo'] ?? '')) === COMPROBANTE_TIPO_PREFACTURA
        && trim((string) ($c['estado'] ?? '')) === COMPROBANTE_PENDIENTE
        && (float) ($c['total'] ?? 0) > 0
        && (int) ($c['serie'] ?? 0) > 0;
}

/**
 * Por qué NO se puede pagar, en criollo. Es lo que ve la persona que abre un
 * correo viejo: decirle "ya está paga" es una respuesta; un botón que no está
 * y ningún texto, no.
 */
function comprobanteMotivoNoPagable(array $c): string
{
    $tipo   = trim((string) ($c['talonario_tipo'] ?? ''));
    $estado = trim((string) ($c['estado'] ?? ''));

    if ($tipo !== COMPROBANTE_TIPO_PREFACTURA) {
        $nombre = comprobanteTexto(COMBO_TALONARIO_TIPO, $tipo);

        return $nombre !== ''
            ? 'Este comprobante es un ' . mb_strtolower($nombre) . ' y no se paga en línea.'
            : 'Este comprobante no se paga en línea.';
    }

    return match ($estado) {
        COMPROBANTE_CANCELADO   => 'Esta factura ya está paga. No hace falta que hagas nada más.',
        COMPROBANTE_ANULADO     => 'Esta factura fue anulada, así que no hay nada que pagar.',
        COMPROBANTE_PREPARACION => 'Esta factura todavía no fue emitida. Vas a poder pagarla cuando te llegue.',
        default => (float) ($c['total'] ?? 0) <= 0
            ? 'Esta factura no tiene importe a pagar.'
            : 'Esta factura no se puede pagar en línea en este momento.',
    };
}

/**
 * URL del cobrador para este comprobante.
 *
 * Los cinco parámetros son los del legacy y `fct` NO SE TOCA: es el id del
 * comprobante y es con lo que Databox concilia el pago del otro lado. Cambiarlo
 * —por el uuid, por el número impreso— deja los pagos sin imputar.
 *
 * EL CONCEPTO SÍ SE ARREGLÓ. El legacy lo armaba con `$xComprobante->punto`, una
 * propiedad que `cComprobante` NO declara —el punto de venta está en
 * `talonarios`—, así que `rellenar('', 3)` devolvía '000' y todas las
 * preferencias salieron diciendo "Factura 000-3349". Acá va el número de verdad,
 * el mismo que imprime la hoja y que muestra el listado del back office.
 *
 * @param string $retorno A dónde vuelve el comprador al terminar.
 */
function comprobanteUrlPago(array $c, string $retorno): string
{
    $numero = comprobanteNumero($c['talonario_punto'] ?? null, $c['serie'] ?? null) ?? '';
    $fecha  = comprobanteFecha($c['emision'] ?? null);

    $concepto = trim('Factura ' . $numero . ($fecha !== '' ? ' (' . $fecha . ')' : ''));

    return PAGO_ENDPOINT . '?' . http_build_query([
        'cta' => PAGO_CUENTA,
        'fct' => (int) $c['id'],
        'mnt' => number_format((float) $c['total'], 2, '.', ''),
        'cpt' => $concepto,
        'ret' => $retorno,
    ], '', '&', PHP_QUERY_RFC3986);
}

/** URL absoluta del visor de un comprobante. Es a donde vuelve el cobrador. */
function comprobanteUrlVisor(string $uuid, string $resultado = ''): string
{
    $parametros = ['uuid' => $uuid];
    if ($resultado !== '') {
        $parametros['res'] = $resultado;
    }

    return wwwBaseUrl() . '/comprobante/visor?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

/**
 * Los tres resultados que puede devolver el cobrador, y qué se muestra de cada
 * uno. Son los mismos códigos de la rama `res` de `comprobante/pagar.php`.
 *
 * `tono` es la clase de color del cartel del visor.
 */
const PAGO_RESULTADOS = [
    'A' => ['tono' => 'ok',    'texto' => 'Tu pago fue procesado correctamente. ¡Muchas gracias!'],
    'R' => ['tono' => 'error', 'texto' => 'Hubo un error al procesar el pago y no se cobró nada. Podés intentarlo de nuevo.'],
];

/** Mensaje del resultado del cobro, o `null` si no vino ninguno o no se reconoce. */
function comprobanteResultadoPago(string $resultado): ?array
{
    $r = mb_strtoupper(trim($resultado));
    if ($r === '') return null;

    return PAGO_RESULTADOS[$r]
        // Cualquier otro código es un resultado que este sitio no conoce: se lo
        // trata como pendiente y NO como aprobado. El legacy decía "resultado
        // inesperado" y mandaba al listado; acá se queda en el comprobante, que
        // es donde la persona puede ver el estado real y reintentar.
        ?? ['tono' => 'aviso', 'texto' => 'No pudimos confirmar el resultado del pago. Revisá el estado de la factura más abajo.'];
}
