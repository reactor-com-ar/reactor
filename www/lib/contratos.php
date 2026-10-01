<?php

declare(strict_types=1);

/**
 * El estado de cuenta de un contrato: lo que necesita `/contrato/estado?uid=`.
 *
 * Port de `reactor-www/contrato/estado/` (`index.php` + `pagar.php`), donde todo
 * vivía dentro de la página: dos consultas crudas por concatenación contra la
 * vista `comprobantesvista`, las clases `cContrato` / `cCliente` / `cDominio` del
 * framework viejo y un `pagar.php` propio que armaba la URL del cobrador por
 * segunda vez. Acá la página sólo dibuja y el pago pasa por la MISMA puerta que
 * el comprobante (`/comprobante/pagar`), que es la que tiene los candados.
 *
 * EL `uuid` DEL CONTRATO ES LA ÚNICA CREDENCIAL, igual que en el comprobante, y
 * esta página muestra MÁS que aquélla: el titular, su domicilio, su celular, el
 * nombre del dominio, cuánto debe y el detalle factura por factura. Las reglas
 * que salen de eso son las tres de `lib/comprobantes.php` —validar la forma antes
 * de tocar la base, `noindex`, y no escribir nada— más una advertencia que acá
 * NO se puede repetir:
 *
 *   **LA FUERZA BRUTA ACÁ NO ES IMPOSIBLE, Y EN EL COMPROBANTE SÍ.**
 *   `comprobantes`.`uuid` son 16 alfanuméricos (36^16 ≈ 8e24 combinaciones) y por
 *   eso el CLAUDE.md de `www/` dice, con razón, que no hace falta cupo por IP.
 *   `contratos`.`uuid` son **8 dígitos** —verificado: las 50 filas tienen
 *   exactamente 8, todas numéricas y todas distintas—, o sea 10^8 combinaciones
 *   con 50 válidas adentro. Son diecisiete órdenes de magnitud menos. El uuid no
 *   es correlativo (36682741, 66993031, 09669239), así que no se adivina de una,
 *   pero un barrido sostenido encuentra contratos. **No heredar el argumento del
 *   comprobante para esta página**: ver la sección de `www/CLAUDE.md`, donde
 *   queda anotado como pendiente con el tope por IP como la salida.
 *
 * NO SE ESCRIBE NADA EN LA BASE, igual que el comprobante. Que una factura pase a
 * Cancelada lo decide el circuito de pagos del back office al conciliar, no esta
 * pantalla: volver del cobrador con `res=A` NO la saca de la lista de pendientes.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/pagina.php';       // wwwBaseUrl(), para la URL de retorno
require_once __DIR__ . '/habilitado.php';   // esHabilitado(), para `contratos`.`habilitado`
require_once __DIR__ . '/comprobantes.php'; // los candados del pago y los formateadores

/* ------------------------------------------------------------------ códigos */

/**
 * Largo exacto de `contratos`.`uuid`. La columna es `varchar(8)` y las 50 filas
 * tienen los 8 dígitos puestos, ninguna vacía y ninguna repetida.
 */
const CONTRATO_UUID_LARGO = 8;

/**
 * Tipos de talonario que documentan DEUDA: Prefactura ('F') y Factura ('T').
 *
 * ES EL MISMO CRITERIO QUE LOS DOS JOBS DE MORA —
 * `cloud/jobs/contratos_situacion_recalcular.php` (04:00) y
 * `cloud/jobs/contratos_baja_morosos.php` (día 15)— y eso es el punto entero de
 * la constante: lo que esta página llama deuda tiene que ser lo mismo que hace
 * que un dominio quede Limitado o Suspendido. Si acá sumara menos, el cliente
 * leería "libre deuda" en una pantalla que explica por qué le cortaron el
 * servicio en la otra.
 *
 * **EL LEGACY TENÍA DOS CRITERIOS EN LA MISMA PÁGINA Y NO COINCIDÍAN.** El
 * encabezado contaba `talonarioTipo='F' AND talonarioFiscal='0'` y el listado de
 * abajo `talonarioTipo='F'` a secas, así que el texto podía anunciar una
 * cantidad y la lista mostrar otra. El filtro por `fiscal` se fue: hoy de los
 * pendientes con contrato **ninguno** sale de un talonario fiscal —la única
 * prefactura fiscal pendiente de la base no tiene contrato— así que alinearse con
 * el criterio del sistema no cambia un solo número, y deja de haber dos.
 */
const CONTRATO_TIPOS_DEUDA = [COMPROBANTE_TIPO_PREFACTURA, 'T'];

/**
 * Tope de facturas que se listan de una. El legacy cortaba en 24 **sin decirlo**,
 * y el máximo de hoy es 21 pendientes en un contrato (el 67813620), así que el
 * corte no se notaba. Acá el resumen se calcula sobre TODA la deuda con un
 * agregado aparte y la página avisa cuántas quedaron sin dibujar: un tope que se
 * come filas en silencio hace que el total no cierre con la lista y el cliente no
 * tiene cómo saber por qué.
 */
const CONTRATO_DEUDA_LISTADO = 60;

/**
 * `dominios`.`situacion`, que es la columna que corta el servicio de verdad
 * (`app/index.php` la lee: con `3` no dibuja ningún control). Los textos salen
 * del mismo combo que traducen `cloud/api/dominios.php` y
 * `panel/api/dominio.php` — el número pelado no se muestra nunca.
 */
const COMBO_DOMINIO_SITUACION = '$xDominio->situacion';

const CONTRATO_SITUACION_NORMAL     = '1';
const CONTRATO_SITUACION_LIMITADO   = '2';
const CONTRATO_SITUACION_SUSPENDIDO = '3';

/** Último recurso si `combos` no tiene la clave, igual que en comprobantes. */
const CONTRATO_SITUACION_FALLBACK = [
    CONTRATO_SITUACION_NORMAL     => 'Normal',
    CONTRATO_SITUACION_LIMITADO   => 'Limitado',
    CONTRATO_SITUACION_SUSPENDIDO => 'Suspendido',
];

/* -------------------------------------------------------------------- datos */

/**
 * ¿Tiene el `uid` la forma que genera el sistema? 8 dígitos, nada más.
 *
 * Se valida ANTES de tocar la base, como el uuid del comprobante, y con
 * `ctype_digit()` y no con una expresión regular: `/^[0-9]+$/` da por buena una
 * cadena terminada en salto de línea. Es el mismo criterio con el que se valida
 * el celular de las invitaciones y el porcentaje de `contratos`.`promo`.
 */
function contratoUuidValido(string $uuid): bool
{
    return strlen($uuid) === CONTRATO_UUID_LARGO && ctype_digit($uuid);
}

/**
 * El contrato con el titular y el dominio, o `null`.
 *
 * Los dos JOIN son LEFT y no INNER: las dos columnas son nullable y un contrato
 * al que le falta el cliente cargado no puede hacer desaparecer el estado de
 * cuenta —hoy las 50 filas tienen los dos, pero el esquema no lo exige y la
 * pantalla sabe arreglarse sin ellos.
 */
function contratoPorUuid(string $uuid): ?array
{
    if (!contratoUuidValido($uuid)) {
        return null;
    }

    $sql = db()->prepare(
        'SELECT k.id, k.uuid, k.habilitado, k.alta, k.baja, k.situacion,
                cl.nombre    AS cliente_nombre,
                cl.razon     AS cliente_razon,
                cl.celular   AS cliente_celular,
                cl.domicilio AS cliente_domicilio,
                cl.localidad AS cliente_localidad,
                cl.correo    AS cliente_correo,
                d.nombre     AS dominio_nombre,
                d.situacion  AS dominio_situacion,
                d.habilitado AS dominio_habilitado
           FROM contratos k
           LEFT JOIN clientes cl ON cl.id = k.cliente
           LEFT JOIN dominios d  ON d.id  = k.dominio
          WHERE k.uuid = :uuid
          LIMIT 1'
    );
    $sql->execute([':uuid' => $uuid]);

    $fila = $sql->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Los `:tN` del `IN (...)` de la deuda, generados desde la constante.
 *
 * Se arma en vez de escribirse a mano para que agregar `'R'` a
 * `CONTRATO_TIPOS_DEUDA` —el cambio que ya tiene anotado el job de las 04:00 si
 * algún día se decide contar los Recibos— alcance para que las dos consultas de
 * acá lo tomen. Un `IN ('F','T')` literal es la forma de que la constante diga
 * una cosa y el SQL haga otra.
 *
 * @return array{0: string, 1: array<string, string>} El fragmento y sus valores.
 */
function contratoTiposDeudaSql(): array
{
    $marcas  = [];
    $valores = [];

    foreach (CONTRATO_TIPOS_DEUDA as $indice => $tipo) {
        $marca             = ':tipo' . $indice;
        $marcas[]          = $marca;
        $valores[$marca]   = $tipo;
    }

    return [implode(', ', $marcas), $valores];
}

/**
 * Cuántas facturas debe y por cuánto, sobre TODA la deuda del contrato.
 *
 * Va aparte del listado a propósito: el listado tiene tope
 * (`CONTRATO_DEUDA_LISTADO`) y el resumen es el que decide si la página dice
 * "Estado de Cuenta" o "Libre Deuda". Calcularlo contando las filas dibujadas
 * haría que un contrato con más facturas que el tope anunciara una deuda menor
 * que la real, que es justo la plata que no se reclama.
 *
 * @return array{cantidad: int, total: float}
 */
function contratoDeudaResumen(int $id): array
{
    [$marcas, $valores] = contratoTiposDeudaSql();

    $sql = db()->prepare(
        'SELECT COUNT(*) AS cantidad, COALESCE(SUM(c.total), 0) AS total
           FROM comprobantes c
           JOIN talonarios t ON t.id = c.talonario
          WHERE c.contrato = :id
            AND c.estado   = :estado
            AND t.tipo IN (' . $marcas . ')'
    );
    $sql->execute($valores + [':id' => $id, ':estado' => COMPROBANTE_PENDIENTE]);

    $fila = $sql->fetch() ?: [];

    return [
        'cantidad' => (int) ($fila['cantidad'] ?? 0),
        'total'    => (float) ($fila['total'] ?? 0),
    ];
}

/**
 * Las facturas pendientes, de la más nueva a la más vieja y con tope.
 *
 * Trae las columnas que necesitan `comprobantePagable()` y
 * `comprobanteMotivoNoPagable()` —`talonario_tipo`, `estado`, `total`, `serie`—
 * con los mismos alias que `comprobantePorUuid()`, así que las filas de acá se
 * le pueden pasar a esas dos funciones tal como salen. Es lo que hace que el
 * botón de esta página y el del visor decidan con el mismo código.
 */
function contratoDeuda(int $id): array
{
    [$marcas, $valores] = contratoTiposDeudaSql();

    $sql = db()->prepare(
        'SELECT c.id, c.uuid, c.serie, c.emision, c.vencimiento, c.total, c.estado,
                t.tipo  AS talonario_tipo,
                t.punto AS talonario_punto
           FROM comprobantes c
           JOIN talonarios t ON t.id = c.talonario
          WHERE c.contrato = :id
            AND c.estado   = :estado
            AND t.tipo IN (' . $marcas . ')
          ORDER BY c.vencimiento ASC, c.id ASC
          LIMIT ' . CONTRATO_DEUDA_LISTADO
    );
    $sql->execute($valores + [':id' => $id, ':estado' => COMPROBANTE_PENDIENTE]);

    return $sql->fetchAll();
}

/* ---------------------------------------------------------------- presentar */

/**
 * Cómo se llama el titular en el estado de cuenta.
 *
 * **La razón social manda y el nombre de fantasía va entre paréntesis**, que es
 * exactamente lo que hace `comprobanteCliente()` en la hoja de la factura. El
 * legacy imprimía acá sólo `clientes`.`nombre`, así que el mismo cliente se
 * llamaba "Barrio Aimara" en este documento y "CONSORCIO PROPIETARIOS AIMARA
 * BARRIO PRIVADO (Barrio Aimara)" en su factura. Son los dos documentos que se
 * le muestran al cliente: tienen que nombrarlo igual.
 */
function contratoTitular(array $k): string
{
    $razon  = trim((string) ($k['cliente_razon']  ?? ''));
    $nombre = trim((string) ($k['cliente_nombre'] ?? ''));

    if ($razon !== '' && $nombre !== '' && $razon !== $nombre) {
        return $razon . ' (' . $nombre . ')';
    }

    return $razon !== '' ? $razon : $nombre;
}

/** Texto de un código de `situacion`, o '' si no hay valor. */
function contratoSituacionTexto(mixed $valor): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return '';

    // El combo del sistema histórico primero; el fallback sólo si la fila no está
    // cargada. Mismo mecanismo que `comprobanteTexto()`, que es de donde sale la
    // tabla cacheada por request.
    $texto = comprobanteCombo(COMBO_DOMINIO_SITUACION)[$v] ?? '';

    return $texto !== '' ? $texto : (CONTRATO_SITUACION_FALLBACK[$v] ?? $v);
}

/**
 * URL absoluta del estado de cuenta. Es a donde vuelve el cobrador cuando el
 * pago arrancó desde esta página.
 *
 * El parámetro se llama `uid` y no `uuid` porque es la URL que Reactor ya le
 * mandó a sus clientes (`/contrato/estado?uid=36682741`) y las URLs publicadas no
 * se cambian — el mismo criterio de `/instaladores` y `/ayuda/preguntas`.
 *
 * **Con la barra final**, que no es cosmético: `/contrato/estado` es un
 * directorio y Apache contesta un 301 a la versión con barra. En un GET sería un
 * salto de más, pero esta URL viaja como `ret` al cobrador y de vuelta, así que
 * el salto se paga en cada pago.
 */
function contratoUrlEstado(string $uuid, string $resultado = ''): string
{
    $parametros = ['uid' => $uuid];
    if ($resultado !== '') {
        $parametros['res'] = $resultado;
    }

    return wwwBaseUrl() . '/contrato/estado/?' . http_build_query($parametros, '', '&', PHP_QUERY_RFC3986);
}

/**
 * El `uuid` del contrato de un comprobante, o '' si no tiene.
 *
 * ES LO QUE HACE QUE EL RETORNO DEL COBRADOR SEA SEGURO. `/comprobante/pagar`
 * recibe del formulario un interruptor (`volver=estado`) y **nunca una URL**: a
 * dónde se vuelve lo resuelve esta consulta, contra la base, desde el
 * comprobante que se está pagando. Aceptar la URL de retorno del request sería
 * un redirect abierto firmado por nosotros y servido desde la pasarela de pago,
 * que es el mejor lugar posible para plantar una pantalla falsa de cobro.
 *
 * Devuelve '' —y entonces se vuelve al visor— cuando el comprobante no cuelga de
 * ningún contrato: de los 118 pendientes de tipo 'F' hay 5 así, y el 'P' y el
 * 'M' no tienen contrato nunca.
 */
function contratoUuidDeComprobante(int $comprobante): string
{
    $sql = db()->prepare(
        'SELECT k.uuid
           FROM comprobantes c
           JOIN contratos k ON k.id = c.contrato
          WHERE c.id = :id
          LIMIT 1'
    );
    $sql->execute([':id' => $comprobante]);

    $uuid = (string) ($sql->fetchColumn() ?: '');

    return contratoUuidValido($uuid) ? $uuid : '';
}
