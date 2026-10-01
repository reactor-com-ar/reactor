<?php

declare(strict_types=1);

/**
 * IMPUTACION DE UN COBRO DE MERCADOPAGO: `GET|POST /v4/mercadopago/inputar`
 *
 *   mod = pago                  (unico modo, igual que v2)
 *   fac = <id del comprobante>  (int positivo)
 *   ope = <payment_id de MP>    (opcional; v2 no lo aceptaba -- ver mas abajo)
 *   [key = <clave>]             (opcional; ver "Como se autentica")
 *
 * Es el port de `reactor-api/v2/mercadopago/imputar.php` del legacy, que es
 * **por donde entra TODO cobro online**: marca la prefactura como Cancelada y
 * emite el recibo. Lo que v2 hacia llamando a `cComprobante::pagoRegistrar()`
 * de `reactor-api/framework/subframework.php`, aca esta escrito contra PDO.
 *
 * NO LO LLAMA MERCADOPAGO: lo llama el cobrador de Databox cuando su propio
 * webhook confirma un pago aprobado. Este repo sale al cobrador por
 * `www/comprobante/pagar.php`, que redirige y no recibe nada -- la vuelta del
 * navegador con `?res=A` es un parametro que escribe cualquiera, y por eso NO
 * es la que imputa. La que imputa es esta.
 *
 * ------------------------------------------------------------------------
 * QUE ESCRIBE, EN UNA SOLA TRANSACCION
 * ------------------------------------------------------------------------
 *
 *   1. `comprobantes` -- INSERT del recibo, clonando la factura: talonario de
 *      Recibo, numero nuevo de ese talonario, `medio` = MercadoPago, estado
 *      Cancelado, emision de hoy y vencimiento a 7 dias.
 *   2. `comprobantesrenglones` -- los renglones de la factura, copiados.
 *   3. `talonarios`.`serie` -- el contador del talonario de recibos, adelantado.
 *   4. `comprobantes`.`estado` de la FACTURA -> `'3'` (Cancelado).
 *   5. `contratos`.`situacion` + `dominios`.`situacion` -- el recalculo de mora
 *      (ver "La situacion del cliente").
 *
 * NO ESCRIBE EN `pagos`, igual que el legacy: `pagoRegistrar()` tampoco lo
 * hacia -- eso es `cPago::registrar()`, otro camino. Y es coherente con el
 * resto del repo, que ignora esa tabla: la mora, la baja por deuda y la
 * situacion del dominio cuentan todas sobre `comprobantes`.`estado`.
 *
 * ------------------------------------------------------------------------
 * LOS SEIS CAMPOS QUE EL LEGACY ESCRIBE Y ACA NO EXISTEN
 * ------------------------------------------------------------------------
 *
 * `pagoRegistrar()` copia del talonario al comprobante `origen`, `empresa`,
 * `tipo`, `subtipo`, `punto` y `fiscal`. ESAS COLUMNAS YA NO ESTAN en
 * `comprobantes`: migraron a `talonarios`, y por eso se leen por el JOIN (es lo
 * que `comprobantesvista` expone como `talonarioTipo`, `talonarioPunto`, ...).
 * Es codigo muerto contra el esquema actual -- el mismo motivo por el que
 * `cloud/api/comprobantes_accion.php` no porto esta funcion-- asi que no se
 * replican: alcanza con que el recibo apunte al talonario correcto.
 *
 * ------------------------------------------------------------------------
 * COMO SE AUTENTICA
 * ------------------------------------------------------------------------
 *
 * Con una clave OPCIONAL guardada en `parametros`, bajo la variable
 * `mercadopago.webhook.secret`:
 *
 *   - Con valor cargado, el que llama la manda en el header
 *     `X-Reactor-Api-Key` o en `?key=`, y se compara con `hash_equals()`
 *     -- tiempo constante, para que el error no se pueda adivinar byte por byte.
 *   - Vacia o sin cargar, se acepta a cualquiera.
 *
 * El default abierto NO es un olvido: es lo que hace v2, que no valida nada, y
 * cambiarlo de entrada dejaria los cobros afuera hasta que alguien toque el
 * caller de Databox. Queda escrito que **la URL abierta puede cancelar una
 * factura y consumir numeracion fiscal del talonario de recibos**: cerrarla es
 * cargar una fila en `parametros` y pasarsela al cobrador, sin deploy.
 *
 * No va por el JWT de cloud -- quien llama es un servidor, no una persona con
 * sesion-- ni por el HMAC de `cloud/api/pago_imputado.php`, que exige firmar el
 * cuerpo y hoy ni siquiera tiene su `PAGO_WEBHOOK_SECRET` en los `.env`.
 *
 * ------------------------------------------------------------------------
 * LO QUE SE REPLICA AUNQUE SEA DISCUTIBLE: `estado < 3`
 * ------------------------------------------------------------------------
 *
 * v2 imputa cuando `estado < 3`, o sea que acepta tambien Anulado (`'0'`) y
 * Preparacion (`'1'`), no solo Pendiente (`'2'`). **Se replica tal cual, por
 * decision explicita.** Las consecuencias, para que esten escritas:
 *
 *   - A una factura ANULADA se le emite recibo igual (hay 132 prefacturas en
 *     ese estado).
 *   - A una en PREPARACION tambien, y esas tienen `serie = 0`: el recibo sale
 *     con numero, pero acusa el cobro de un documento que nunca se emitio.
 *   - Ninguno de los dos casos llega por el camino normal: el boton de pago de
 *     `www/` sale solo para prefacturas Pendientes con numero y total mayor a
 *     cero (los cuatro candados de `comprobantePagable()`). Para alcanzar esos
 *     estados hay que pegarle a esta URL a mano.
 *
 * El unico corte que SI se agrega es el 404: v2 llamaba `leer($factura)` sin
 * mirar si existia y seguia de largo, asi que un `fac` inventado terminaba
 * clonando lo que hubiera quedado en el objeto. Eso no es un criterio, es un
 * bug -- un comprobante que no existe no se puede cobrar.
 *
 * ------------------------------------------------------------------------
 * LA SITUACION DEL CLIENTE: PAGAR REHABILITA EN EL ACTO
 * ------------------------------------------------------------------------
 *
 * `pagoRegistrar()` cierra llamando a `cDominio::situacionDetectar()`, que
 * escribe `dominios`.`situacion` con OTRAS reglas: Normal es atraso de hasta un
 * dia (`$intimar`, una propiedad de clase), mira los talonarios 38 y 48
 * hardcodeados, toma el primer vencimiento por `id` en vez del `MIN()` y solo
 * atiende al contrato que cuelga de `dominios`.`contrato` (32 de 148 dominios).
 *
 * Aca el recalculo lo hace `recalcularSituacionTrasPago()` de
 * `cloud/api/contratos_situacion_lib.php`, que es el mismo codigo que corre el
 * barrido de las 04:00, la tarea del dia 15 y los dos items `Actualizar
 * situacion` de las fichas. **Y EN LA MISMA TRANSACCION que la imputacion**: un
 * recibo emitido con el dominio todavia Suspendido son dos afirmaciones
 * distintas sobre el mismo cliente.
 *
 * AL PONER ESTE ENDPOINT EN PRODUCCION HAY QUE COMENTAR EL BLOQUE
 * `// actualiza siguacion` DE `pagoRegistrar()` EN EL LEGACY. Con los dos vivos,
 * `dominios`.`situacion` tiene dos escritores que no coinciden y gana el ultimo
 * que corrio. Mismo trato que el robot de los planes.
 *
 * UN FALLO DEL RECALCULO NO VOLTEA EL COBRO. El pago es el hecho; la situacion
 * es una consecuencia que la tarea de las 04:00 recalcula desde cero todos los
 * dias. Se commitea igual, queda el suceso en `error` y la respuesta lo dice.
 *
 * ------------------------------------------------------------------------
 * LAS TRES DIFERENCIAS DE FORMA CON v2
 * ------------------------------------------------------------------------
 *
 *   1. **CONTESTA JSON.** v2 declaraba `Content-Type: application/json` y
 *      despues hacia `echo 'Pago imputado correctamente'`, o sea texto pelado
 *      con la cabecera equivocada. El texto exacto de v2 viaja igual, en
 *      `mensaje`, para quien lo este matcheando.
 *   2. **UN `mod` DESCONOCIDO ES UN 400.** En v2 el `switch` no tiene `default`:
 *      contestaba 200 con el cuerpo vacio, que del otro lado se lee como que
 *      salio bien.
 *   3. **ACEPTA `ope`** (el `payment_id` de MercadoPago) y lo guarda en
 *      `comprobantes`.`comentarios` del recibo y en el suceso. Va en
 *      `comentarios` y NO en `observaciones` a proposito: la hoja A4 imprime
 *      `observaciones`, asi que el rastro interno del pago terminaria en el
 *      papel del cliente.
 *
 * Y UNA DE FONDO: TODO CORRE EN UNA TRANSACCION CON LOS CONTADORES BLOQUEADOS.
 * El legacy lee `talonarios`.`serie`, le suma uno y guarda, sin lock: dos
 * imputaciones simultaneas emiten dos recibos con el MISMO numero fiscal. Es el
 * mismo `SELECT ... FOR UPDATE` con el que ya toman numero `accionAutorizar()` y
 * `facturarEmitir()`.
 */

/* ------------------------------------------------------- arranque y entorno */

date_default_timezone_set('America/Argentina/Buenos_Aires');

/**
 * Primer candidato legible, o se corta diciendo donde se busco.
 *
 * ESTE DOCROOT NO COMPARTE ARBOL CON LOS OTROS CUATRO. `cloud/` se monta en
 * `/var/www/html`, `panel/` en `/var/www/panel`, y `api/` colgara de su propio
 * punto, asi que `dirname(__DIR__, n)` no alcanza para encontrar ni el loader
 * de entorno ni los lib de cloud: la ruta relativa que vale en el repo no vale
 * adentro del contenedor. Por eso se prueban las dos y no se elige una.
 */
function rutaPrimera(array $candidatos, string $que): string
{
    foreach ($candidatos as $ruta) {
        if (is_readable($ruta)) return $ruta;
    }

    throw new RuntimeException(
        'No encontre ' . $que . '. Probe: ' . implode(' | ', $candidatos)
    );
}

/** El loader de entorno compartido: define APP_ENV + DB_*. */
function rutaEnv(): string
{
    return rutaPrimera([
        dirname(__DIR__, 3) . '/env.php',   // arbol del repo
        '/var/www/env.php',                 // contenedor (un nivel arriba de los docroots)
    ], 'el loader de entorno (env.php)');
}

/**
 * Un archivo de `cloud/api/`.
 *
 * SE INCLUYE EL LIB DE CLOUD EN VEZ DE COPIARLO, y es la regla mas firme de
 * todo este archivo: el calculo de la situacion esta escrito UNA vez. Con una
 * copia por camino, este endpoint dejaria al cliente en una situacion y el
 * barrido de las 04:00 escribiria otra a la mañana siguiente.
 */
function rutaCloudApi(string $archivo): string
{
    return rutaPrimera([
        dirname(__DIR__, 3) . '/cloud/api/' . $archivo,
        '/var/www/html/api/' . $archivo,
    ], 'el archivo de cloud/api/ "' . $archivo . '"');
}

require_once rutaEnv();

// El log de actividad (`sucesos_log`). Va ANTES del lib de situacion porque ese
// resuelve `registrarSuceso` con `function_exists()`: sin esto el recalculo
// escribe bien y no deja rastro de haberlo hecho.
require_once rutaCloudApi('lib/sucesos.php');
require_once rutaCloudApi('contratos_situacion_lib.php');

if (defined('APP_ENV') && APP_ENV !== 'production') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    // Esta URL la puede pedir cualquiera: un warning impreso publica las rutas
    // del servidor y los nombres de las columnas.
    ini_set('display_errors', '0');
    error_reporting(0);
}

/* ----------------------------------------------------------------- catalogo */

/**
 * El talonario con el que se emite el recibo. Hardcodeado, igual que el legacy
 * (`$zTalonario->leer(49)`).
 *
 * ES EL DE UNA EMPRESA SOLA -- 49 es "Alfatec - Recibo - X - 001", y hay otro de
 * Recibo que es el de Wescom (43). O sea que el cobro de una prefactura de
 * Wescom (talonario 38) sale acusado con un recibo de Alfatec. **Es lo que esta
 * corriendo en produccion**: los ultimos 12 recibos con contrato salieron todos
 * del 49, para contratos de las dos empresas. No se "arregla" derivandolo de
 * `talonarios`.`empresa` de la factura porque eso cambiaria de golpe con que
 * numeracion fiscal se emite -- es una decision contable, no un refactor.
 *
 * Si algun dia se decide por empresa, el cambio es una fila en `parametros`
 * (como `comprobante.abono.talonario.facturar`, que vale 23) o un lookup por
 * `empresa` + `tipo = 'R'`, y entra SOLO aca.
 */
const TALONARIO_RECIBO = 49;

/** `medios`.`id` de MercadoPago. El `$medio = 20` de v2. */
const MEDIO_MERCADOPAGO = 20;

/** `comprobantes`.`estado` = Cancelado. Se compara como string: la columna es varchar(1). */
const ESTADO_CANCELADO = '3';

/**
 * El estado desde el que v2 imputa: cualquiera MENOR a 3.
 *
 * Numerico y no una lista de strings porque es literalmente el `<` del legacy
 * (`if ($xComprobante->estado < 3)`), con el casteo que hace PHP: un `estado`
 * en NULL -- hay una fila asi -- tambien pasa.
 */
const ESTADO_TOPE_IMPUTABLE = 3;

/** Dias que `cComprobante::duplicar()` le pone de vencimiento al duplicado. */
const RECIBO_VENCIMIENTO_DIAS = 7;

/** Largo del `uuid`, el mismo que genera `cCadena::aleatoria(16, '0A')`. */
const UUID_LARGO = 16;

/** `parametros`.`variable` con la clave de este webhook. Vacia = abierto. */
const PARAMETRO_CLAVE = 'mercadopago.webhook.secret';

/** Header alternativo a `?key=`, para no dejar la clave en los access logs. */
const HEADER_CLAVE = 'HTTP_X_REACTOR_API_KEY';

/** `sucesos_log`.`origen` de todo lo que deja este endpoint. */
const SUCESO_ORIGEN = 'api/mercadopago/inputar';

/** Los tres textos de v2, palabra por palabra. */
const MSG_OK        = 'Pago imputado correctamente';
const MSG_YA_COBRADA = 'Factura previamente cobrada';
const MSG_FAC_INVALIDA = 'Número de factura inválido';

/* ------------------------------------------------------------------ cabeceras */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
// CORS abierto como en v2. No afloja nada que el chequeo de la clave no cubra:
// quien arma el request desde un servidor manda las cabeceras que quiera, y lo
// que el navegador respeta es irrelevante para un webhook server-to-server.
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Origin, X-Requested-With, Content-Type, Accept, X-Reactor-Api-Key');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    return;
}

/* ---------------------------------------------------------------- el request */

try {
    // GET y POST, los dos: v2 leia con `getpost()` y el cobrador de Databox
    // llama por GET.
    $metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($metodo, ['GET', 'POST'], true)) {
        responder(405, false, 'Metodo no permitido', 'alerta', "Metodo {$metodo} rechazado.");
    }

    asertarClave();

    $entrada = array_merge($_GET, $_POST);
    $modo    = trim((string) ($entrada['mod'] ?? ''));
    $factura = (int) ($entrada['fac'] ?? 0);
    $ope     = textoAcotado((string) ($entrada['ope'] ?? ''), 100);

    if ($modo !== 'pago') {
        responder(
            400,
            false,
            'Modo no soportado: usar mod=pago',
            'alerta',
            sprintf('Modo "%s" no soportado (solo "pago"). fac=%d ope=%s', $modo, $factura, $ope)
        );
    }

    // `$oNumero->cero()` + `if ($factura > 0)` de v2.
    if ($factura <= 0) {
        responder(
            400,
            false,
            MSG_FAC_INVALIDA,
            'alerta',
            sprintf('fac invalido ("%s"). ope=%s', (string) ($entrada['fac'] ?? ''), $ope)
        );
    }

    imputar($factura, $ope);
} catch (Throwable $e) {
    error_log('api/v4/mercadopago/inputar.php: ' . $e->getMessage());
    responder(
        500,
        false,
        'Error al imputar el pago',
        'error',
        sprintf(
            'Excepcion no controlada: %s en %s:%d',
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        )
    );
}

/* ------------------------------------------------------------- la imputacion */

/**
 * Marca la factura como Cancelada y emite el recibo. `pagoRegistrar()` portada.
 *
 * TODO VA EN UNA TRANSACCION, el recalculo de situacion incluido: o el cliente
 * queda con la factura paga, el recibo emitido y el servicio devuelto, o queda
 * como estaba. Un commit parcial deja a alguien con el recibo en la mano y el
 * porton sin responder.
 *
 * EL ORDEN DE LOS LOCKS ES comprobante -> talonario -> contratos -> dominios.
 * Los dos ultimos los toma el lib de situacion y son los mismos, en el mismo
 * orden, que toma el barrido de las 04:00: asi las dos escrituras se esperan en
 * vez de abrazarse.
 */
function imputar(int $factura, string $ope): void
{
    $pdo = db();

    $pdo->beginTransaction();

    try {
        // 1. La factura, bloqueada. El lock es sobre ESTA fila porque es la que
        //    decide si hay algo que cobrar: sin el, dos avisos del mismo pago
        //    pasan los dos el chequeo de estado y emiten dos recibos.
        $stmt = $pdo->prepare('SELECT * FROM comprobantes WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => $factura]);
        $fac = $stmt->fetch();

        if ($fac === false) {
            $pdo->rollBack();
            responder(
                404,
                false,
                MSG_FAC_INVALIDA,
                'alerta',
                sprintf('El comprobante #%d no existe. ope=%s', $factura, $ope)
            );
        }

        // 2. `if ($xComprobante->estado < 3)` de v2, con el mismo casteo.
        //    Cancelado (y cualquier cosa por encima) es "ya cobrada", que es la
        //    respuesta idempotente: el cobrador reintenta y no duplica el recibo.
        if ((int) $fac['estado'] >= ESTADO_TOPE_IMPUTABLE) {
            $pdo->rollBack();
            responder(
                200,
                true,
                MSG_YA_COBRADA,
                'alerta',
                sprintf(
                    'Imputacion duplicada: el comprobante #%d ya estaba en estado "%s". '
                    . 'Se responde OK sin emitir otro recibo. ope=%s',
                    $factura,
                    (string) $fac['estado'],
                    $ope
                ),
                ['factura' => $factura, 'ya_cobrada' => true]
            );
        }

        // 3. El talonario de recibos, bloqueado: es el contador compartido.
        $stmt = $pdo->prepare('SELECT id, punto, serie FROM talonarios WHERE id = :id FOR UPDATE');
        $stmt->execute([':id' => TALONARIO_RECIBO]);
        $tal = $stmt->fetch();

        if ($tal === false) {
            // Sin talonario no hay numeracion, y un recibo sin numero no es un
            // recibo. Se corta antes de tocar la factura.
            $pdo->rollBack();
            responder(
                500,
                false,
                'No se puede emitir el recibo',
                'error',
                sprintf(
                    'El talonario de recibos #%d no existe: no hay de donde sacar el numero. '
                    . 'Comprobante #%d NO imputado. ope=%s',
                    TALONARIO_RECIBO,
                    $factura,
                    $ope
                )
            );
        }

        $serie = ((int) $tal['serie']) + 1;

        // 4. El recibo: la factura clonada. Lo que cambia respecto del original
        //    es lo que `duplicar()` reescribe (uuid, emision, vencimiento) mas
        //    lo que `pagoRegistrar()` pisa despues (talonario, serie, medio,
        //    estado).
        //
        //    LA COTIZACION SE COPIA TAL CUAL y no se vuelve a sellar: el recibo
        //    es de talonario `R`, que no es de los tipos que la sellan, y es lo
        //    que hace `duplicar()`. Son los 190 recibos con cotizacion que ya
        //    tiene la base.
        $hoy = new DateTimeImmutable('today');

        $ins = $pdo->prepare(
            "INSERT INTO comprobantes
                 (uuid, talonario, serie, caenro, caevto, caeres, emision, vencimiento,
                  contrato, cliente, razon, condicion, cuit, domicilio, correo, celular,
                  subtotal, iva, total, cotizacion, observaciones, comentarios, medio, estado)
             VALUES
                 (:uuid, :talonario, :serie, '', '', '', :emision, :vencimiento,
                  :contrato, :cliente, :razon, :condicion, :cuit, :domicilio, :correo, :celular,
                  :subtotal, :iva, :total, :cotizacion, :observaciones, :comentarios, :medio, :estado)"
        );
        $ins->execute([
            ':uuid'          => uuidLibre($pdo),
            ':talonario'     => (int) $tal['id'],
            ':serie'         => $serie,
            ':emision'       => $hoy->format('Y-m-d'),
            ':vencimiento'   => $hoy->modify('+' . RECIBO_VENCIMIENTO_DIAS . ' days')->format('Y-m-d'),
            // El `0` de estas FK es el centinela "sin asignar" del sistema
            // historico, no un id: va NULL, o el INSERT choca contra el RESTRICT.
            ':contrato'      => idOrNull($fac['contrato']),
            ':cliente'       => idOrNull($fac['cliente']),
            ':razon'         => (string) ($fac['razon']     ?? ''),
            ':condicion'     => (string) ($fac['condicion'] ?? ''),
            ':cuit'          => (string) ($fac['cuit']      ?? ''),
            ':domicilio'     => (string) ($fac['domicilio'] ?? ''),
            ':correo'        => (string) ($fac['correo']    ?? ''),
            ':celular'       => (string) ($fac['celular']   ?? ''),
            // Los importes se copian: el recibo acusa el cobro de ESTE total.
            ':subtotal'      => $fac['subtotal'],
            ':iva'           => $fac['iva'],
            ':total'         => $fac['total'],
            ':cotizacion'    => $fac['cotizacion'],
            ':observaciones' => (string) ($fac['observaciones'] ?? ''),
            ':comentarios'   => comentariosRecibo((string) ($fac['comentarios'] ?? ''), $ope),
            ':medio'         => MEDIO_MERCADOPAGO,
            ':estado'        => ESTADO_CANCELADO,
        ]);

        $recibo = (int) $pdo->lastInsertId();

        // 5. Los renglones, en una sola sentencia: sin traerlos a PHP no hay
        //    forma de que se pierda uno en el medio.
        $pdo->prepare(
            'INSERT INTO comprobantesrenglones
                 (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
             SELECT :nuevo, orden, cantidad, articulo, detalle, iva, unitario, monto, estado
             FROM comprobantesrenglones
             WHERE comprobante = :viejo
             ORDER BY orden ASC, id ASC'
        )->execute([':nuevo' => $recibo, ':viejo' => $factura]);

        // 6. El talonario se queda con el numero que se acaba de usar.
        $pdo->prepare('UPDATE talonarios SET serie = :s WHERE id = :id')
            ->execute([':s' => $serie, ':id' => (int) $tal['id']]);

        // 7. La factura pasa a Cancelada. SOLO el estado: v2 hacia `leer($id)`
        //    antes de guardar, asi que el resto de las columnas -- el `medio`
        //    sobre todo -- quedaban como estaban.
        $pdo->prepare('UPDATE comprobantes SET estado = :e WHERE id = :id')
            ->execute([':e' => ESTADO_CANCELADO, ':id' => $factura]);

        // 8. La situacion del cliente, con las reglas de ESTE repo.
        $dominio   = dominioDeContrato($pdo, idOrNull($fac['contrato']));
        $situacion = null;

        if ($dominio !== null) {
            $situacion = recalcularSituacionTrasPago(
                $pdo,
                $dominio,
                sprintf('tras la imputación de un pago (comprobante #%d)', $factura)
            );
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    responder(
        200,
        true,
        MSG_OK,
        ($situacion !== null && !$situacion['ok']) ? 'error' : 'info',
        detalleDelExito($factura, $fac, $recibo, $serie, (int) $tal['punto'], $dominio, $situacion, $ope),
        [
            'factura'       => $factura,
            'recibo'        => $recibo,
            'recibo_serie'  => $serie,
            'recibo_numero' => numeroImpreso($tal['punto'] ?? null, $serie),
            'medio'         => MEDIO_MERCADOPAGO,
            'operacion'     => $ope !== '' ? $ope : null,
            'dominio'       => $dominio,
            // `null` cuando el comprobante no cuelga de ningun contrato: no hay
            // a quien recalcularle la mora, y no es un error.
            'situacion'     => $situacion === null ? null : [
                'recalculada' => $situacion['ok'],
                'dominio'     => $situacion['dominio'],
                'cambios'     => $situacion['cambios'],
                'error'       => $situacion['error'],
            ],
        ]
    );
}

/* ----------------------------------------------------------------- piezas */

/**
 * La clave del webhook, si esta configurada.
 *
 * `hash_equals()` y no `===`: la comparacion tiene que ser en tiempo constante
 * o el prefijo correcto se aprende midiendo cuanto tarda el rechazo.
 */
function asertarClave(): void
{
    $esperada = trim((string) parametro(PARAMETRO_CLAVE, ''));

    // Vacia = abierto, como v2. Ver "Como se autentica" en la cabecera.
    if ($esperada === '') return;

    $recibida = (string) ($_SERVER[HEADER_CLAVE] ?? ($_GET['key'] ?? $_POST['key'] ?? ''));

    if (!hash_equals($esperada, $recibida)) {
        responder(
            401,
            false,
            'No autorizado',
            'alerta',
            sprintf(
                'Llamada rechazada: clave %s. fac=%s',
                $recibida === '' ? 'ausente' : 'invalida',
                (string) ($_GET['fac'] ?? $_POST['fac'] ?? '')
            )
        );
    }
}

/**
 * Responde JSON, deja el suceso y termina.
 *
 * Las dos cosas en la misma funcion porque son la misma decision: todo lo que
 * este endpoint contesta queda registrado, y el que lo mira despues en el Visor
 * de sucesos necesita ver lo que se le dijo al cobrador. Un camino de salida
 * sin suceso es un cobro que paso sin dejar rastro.
 *
 * LA CLAVE NUNCA SE ESCRIBE en el detalle -- ni cuando es la invalida: esa tabla
 * guarda las filas para siempre, y es el mismo criterio por el que el codigo de
 * verificacion del login de `app` dejo de guardarse en `mensajes`.
 */
function responder(
    int $status,
    bool $ok,
    string $mensaje,
    string $tipoSuceso,
    string $detalle,
    array $extra = []
): void {
    $cuerpo = json_encode(
        ['ok' => $ok, 'mensaje' => $mensaje] + $extra,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    // `registrarSuceso()` nunca lanza: si la tabla no esta, va a error_log y
    // sigue. El cobro no se cae porque falle el log.
    registrarSuceso(db(), SUCESO_ORIGEN, $tipoSuceso, sprintf(
        "%s\n\nRequest: %s\n\nRespuesta: %s",
        $detalle,
        json_encode(snapshotRequest(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $cuerpo
    ));

    http_response_code($status);
    echo $cuerpo;
    exit;
}

/**
 * De donde vino el request, para el suceso.
 *
 * Sirve para ubicar a quien le pega mal a la URL -- un integrador probando a
 * mano, el cobrador mandando un `fac` que no es--. De `key` se dice si vino o
 * no, nunca el valor.
 */
function snapshotRequest(): array
{
    $query = $_GET;
    $post  = $_POST;
    unset($query['key'], $post['key']);

    return [
        'metodo' => $_SERVER['REQUEST_METHOD'] ?? '?',
        'uri'    => $_SERVER['REQUEST_URI']    ?? '?',
        'ip'     => $_SERVER['REMOTE_ADDR']    ?? '?',
        'ua'     => mb_substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? '?'), 0, 120),
        'query'  => $query,
        'post'   => $post,
        'clave'  => (($_SERVER[HEADER_CLAVE] ?? '') !== '' || ($_GET['key'] ?? $_POST['key'] ?? '') !== '')
            ? '(recibida)'
            : '(ausente)',
    ];
}

/** El renglon que queda en el Visor de sucesos cuando el cobro entra bien. */
function detalleDelExito(
    int $factura,
    array $fac,
    int $recibo,
    int $serie,
    int $punto,
    ?int $dominio,
    ?array $situacion,
    string $ope
): string {
    $partes = [sprintf(
        'Pago imputado sobre el comprobante #%d (%s, total %s). Recibo #%d emitido N° %s con el talonario #%d.',
        $factura,
        trim((string) ($fac['razon'] ?? '')) !== '' ? (string) $fac['razon'] : '(sin razón social)',
        number_format((float) ($fac['total'] ?? 0), 2, ',', '.'),
        $recibo,
        numeroImpreso($punto, $serie) ?? (string) $serie,
        TALONARIO_RECIBO
    )];

    if ($ope !== '') {
        $partes[] = sprintf('Operación MercadoPago: %s.', $ope);
    }

    if ($dominio === null) {
        // Hay comprobantes sin contrato y contratos sin dominio: no hay a quien
        // recalcularle la mora. No es un error, pero se dice.
        $partes[] = 'No cuelga de ningún dominio: no hubo situación que recalcular.';
    } elseif ($situacion !== null && !$situacion['ok']) {
        $partes[] = sprintf(
            'NO SE PUDO RECALCULAR la situación del dominio #%d: %s. '
            . 'El cobro quedó imputado; la tarea de las 04:00 lo corrige.',
            $dominio,
            (string) $situacion['error']
        );
    } elseif ($situacion !== null && $situacion['cambios'] === 0) {
        $partes[] = sprintf('La situación del dominio #%d no cambió.', $dominio);
    }

    // El detalle de QUE cambio lo escribe el propio lib de situacion, en su
    // suceso aparte: duplicarlo aca seria contar el mismo hecho dos veces con
    // dos redacciones.
    return implode(' ', $partes);
}

/**
 * El `comentarios` del recibo: lo que traia la factura, mas el rastro del pago.
 *
 * VA EN `comentarios` Y NO EN `observaciones` porque la hoja A4 imprime
 * `observaciones` -- el `payment_id` de MercadoPago es rastro interno, no algo
 * que tenga que salir en el papel del cliente.
 */
function comentariosRecibo(string $heredado, string $ope): string
{
    if ($ope === '') return $heredado;

    $linea = sprintf('Cobro imputado por MercadoPago (operación %s).', $ope);

    return $heredado === '' ? $linea : $heredado . "\n" . $linea;
}

/** El dominio al que pertenece el contrato, o null. La cadena del legacy. */
function dominioDeContrato(PDO $pdo, ?int $contrato): ?int
{
    if ($contrato === null) return null;

    $stmt = $pdo->prepare('SELECT dominio FROM contratos WHERE id = :id');
    $stmt->execute([':id' => $contrato]);

    return idOrNull($stmt->fetchColumn());
}

/**
 * 16 caracteres alfanumericos en mayuscula que no use ningun comprobante.
 *
 * Es `cCadena::aleatoria(16, '0A')` con dos cambios: `random_int()` en vez del
 * `rand()` del legacy, y el chequeo de que no este tomado -- la columna no tiene
 * UNIQUE, asi que una colision no la frena la base, y el `uuid` es la unica
 * credencial del visor publico.
 */
function uuidLibre(PDO $pdo): string
{
    $alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $stmt     = $pdo->prepare('SELECT 1 FROM comprobantes WHERE uuid = :u');

    for ($intento = 0; $intento < 20; $intento++) {
        $uuid = '';
        for ($i = 0; $i < UUID_LARGO; $i++) {
            $uuid .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $stmt->execute([':u' => $uuid]);
        if (!$stmt->fetchColumn()) return $uuid;
    }

    throw new RuntimeException('No pude generar un uuid libre para el recibo en 20 intentos');
}

/**
 * Numero impreso: `punto-serie` con 3 y 6 digitos ("001-003143").
 *
 * Misma funcion que `comprobanteNumero()` de `cloud/api/comprobantes_lib.php`,
 * copiada porque ese lib llama a `db()` y a `json_error()` y no se puede
 * incluir desde aca. Es cosmetico -- va en la respuesta y en el suceso--, no un
 * calculo: si se despega del de cloud, el numero se lee distinto en dos
 * pantallas, pero no cambia lo que quedo escrito.
 */
function numeroImpreso(mixed $punto, mixed $serie): ?string
{
    $s = (int) $serie;
    if ($s <= 0) return null;

    return str_pad((string) (int) $punto, 3, '0', STR_PAD_LEFT)
        . '-' . str_pad((string) $s, 6, '0', STR_PAD_LEFT);
}

/** Id de una FK del sistema historico, donde el `0` significa "sin asignar". */
function idOrNull(mixed $v): ?int
{
    $id = (int) $v;
    return $id > 0 ? $id : null;
}

/** Texto de entrada recortado: lo que llega de afuera no define el largo. */
function textoAcotado(string $v, int $max): string
{
    return mb_substr(trim($v), 0, $max);
}

/**
 * Un parametro runtime de `parametros`.
 *
 * La columna es `variable`, NO `clave`: es la tabla del sistema historico, la
 * misma de la que `comprobantes_lib.php` lee `articulos.dolar.cotizacion`.
 * Devuelve el default si no esta cargado -- o si la consulta falla, porque la
 * clave de este webhook es opcional y un error de lectura no puede volverse un
 * portazo con el cobro del otro lado.
 */
function parametro(string $variable, ?string $default = null): ?string
{
    static $cache = [];
    if (array_key_exists($variable, $cache)) return $cache[$variable];

    try {
        $stmt = db()->prepare('SELECT valor FROM parametros WHERE variable = :v ORDER BY id ASC LIMIT 1');
        $stmt->execute([':v' => $variable]);
        $valor = $stmt->fetchColumn();
        $cache[$variable] = $valor === false ? $default : (string) $valor;
    } catch (Throwable $_) {
        error_log('api/v4/mercadopago/inputar.php: no pude leer el parametro ' . $variable);
        $cache[$variable] = $default;
    }

    return $cache[$variable];
}

/**
 * El PDO. Mismas opciones que `cloud/api/bootstrap.php`, incluido el
 * `SET time_zone`: las fechas que escribe este endpoint tienen que leerse igual
 * desde las otras apps.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo !== null) return $pdo;

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        DB_HOST,
        (int) DB_PORT,
        DB_NAME
    );

    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    $pdo->exec("SET time_zone = '-03:00'");

    return $pdo;
}
