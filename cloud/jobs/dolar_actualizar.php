<?php

declare(strict_types=1);

/**
 * Actualiza la cotizacion del dolar contra el microservicio de Databox.
 *
 * Escribe las DOS filas de `parametros` que el resto del sistema ya lee:
 *
 *   articulos.dolar.cotizacion  -> el numero (ej. "1540.00")
 *   articulos.dolar.actualizado -> cuando se corrio (ej. "2026-09-30 06:00:03")
 *
 * REEMPLAZA AL ROBOT DEL LEGACY (`reactor-api/robot/articulosActualizar.php`),
 * QUE DEJO DE CORRER. No es una estimacion: al 30/09/2026 el parametro de
 * produccion seguia en 1530.00 con fecha 2026-09-05, veinticinco dias viejo.
 * Mientras tanto `articulos` en dolares, el cotizador de planes de `www` y el
 * sellado de comprobantes seguian valorizando con esa cotizacion.
 *
 * SE GUARDA `venta`, NO `compra`, y no es una preferencia: esta verificado
 * contra el historico. Al 2026-05-19 el microservicio devuelve
 * `compra 1370 / venta 1420` y la fila de `parametros` de esa misma fecha vale
 * exactamente `1420.00`. Ademas es la lectura correcta del negocio: la columna
 * valoriza articulos IMPORTADOS (`compra = importacion * cotizacion`), o sea el
 * precio al que se COMPRAN dolares, que es la punta `venta` del mercado.
 *
 * ESTE JOB NO RECALCULA LOS ARTICULOS: lo hace `articulos_recalcular.php` una
 * hora despues, a las 07:00. La separacion es deliberada y sobrevivio al cambio
 * de decision del 30/09/2026 (hasta esa fecha no habia recalculo en masa; ver
 * el encabezado de ese archivo). El motivo de que sean dos tareas y no una: si
 * Databox se cae, la cotizacion no se mueve, ESTA tarea queda en `error` y el
 * recalculo corre igual una hora mas tarde y sale `ok` con 0 filas tocadas —no
 * hay nada que mover—. Fusionadas, un fallo de red del microservicio dejaria
 * sin correr el recalculo, que no depende de la red.
 *
 * El orden tambien importa: invertido, el recalculo de cada dia aplicaria la
 * cotizacion del dia anterior y el numero nuevo recien llegaria a los precios
 * 24 horas mas tarde.
 */

require_once __DIR__ . '/_bootstrap.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: el Visor de sucesos es
// donde se ve, de un vistazo, que la cotizacion se viene moviendo todos los dias.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

/** Ultima cotizacion registrada. Sin `?fecha=`: ver `cotizacionRemota()`. */
const DOLAR_URL = 'https://api.databox.net.ar/v4/dolarhoy/cotizacion';

/** Timeout total del GET. El microservicio sirve una fila: responde rapido. */
const DOLAR_TIMEOUT = 15;

/** `parametros`.`variable`, los mismos nombres que lee `articulos_lib.php`. */
const DOLAR_PARAMETRO_COTIZACION = 'articulos.dolar.cotizacion';
const DOLAR_PARAMETRO_ACTUALIZADO = 'articulos.dolar.actualizado';

/**
 * Banda de cordura de la cotizacion.
 *
 * No es una validacion de negocio sino un piso y un techo de lo fisicamente
 * posible: lo unico que tiene que cortar es un `0`, un negativo o un numero
 * absurdo. Un `0` es el peor de todos — `cotizacionDolar()` devuelve 0 cuando
 * el parametro no es numerico y con eso la tabla entera en dolares pasa a
 * costar $ 0,00.
 */
const DOLAR_MINIMO = 1.0;
const DOLAR_MAXIMO = 1000000.0;

/** Salto contra el valor anterior a partir del cual se avisa (no se corta). */
const DOLAR_SALTO_AVISO = 10.0;

/** Antiguedad de la cotizacion devuelta a partir de la cual se avisa, en dias. */
const DOLAR_DIAS_AVISO = 7;

try {
    $pdo = _jobsPdo();

    $remota = cotizacionRemota();
    $venta  = $remota['venta'];
    anotarLog(sprintf(
        'Databox: id %d | fecha %s | compra %s | venta %s',
        $remota['id'], $remota['fecha'],
        number_format($remota['compra'], 2, '.', ''),
        number_format($venta, 2, '.', '')
    ));

    $anterior = (float) parametroActual($pdo, DOLAR_PARAMETRO_COTIZACION);

    // Los dos parametros se escriben o no se escribe ninguno: una cotizacion sin
    // su fecha —o una fecha de hoy sobre el numero de la semana pasada— es peor
    // que no haber actualizado, porque las tres pantallas que la muestran
    // ("Cotización $X · actualizada el …") afirmarian algo falso.
    $pdo->beginTransaction();
    parametroGuardar($pdo, DOLAR_PARAMETRO_COTIZACION, number_format($venta, 2, '.', ''));
    // `NOW()` DE LA BASE Y NO EL RELOJ DE PHP, como en todo el repo: es la misma
    // conexion que fija `SET time_zone = '-03:00'`, asi que la cadena queda en
    // hora de Argentina y con el formato que ya tenian las filas del legacy.
    parametroGuardar($pdo, DOLAR_PARAMETRO_ACTUALIZADO, null);
    $pdo->commit();

    $actualizado = parametroActual($pdo, DOLAR_PARAMETRO_ACTUALIZADO);
    anotarLog(sprintf(
        'Guardado: %s -> %s (%s%%) | %s = %s',
        number_format($anterior, 2, '.', ''),
        number_format($venta, 2, '.', ''),
        $anterior > 0 ? number_format((($venta - $anterior) / $anterior) * 100, 2, '.', '') : 's/d',
        DOLAR_PARAMETRO_ACTUALIZADO, $actualizado
    ));

    $resumen = sprintf(
        'dolar: %s -> %s (venta, cotizacion del %s)',
        number_format($anterior, 2, '.', ''),
        number_format($venta, 2, '.', ''),
        $remota['fecha']
    );

    // Los avisos NO cortan el job: el numero vino bien formado y el sistema esta
    // mejor con el que sin el. Lo que hacen es dejar rastro en el Visor de
    // sucesos de los dos casos en que el dato puede ser correcto y aun asi
    // enganiar a quien lo mira.
    $avisos = [];
    if ($anterior > 0) {
        $salto = abs((($venta - $anterior) / $anterior) * 100);
        if ($salto > DOLAR_SALTO_AVISO) {
            $avisos[] = sprintf('salto del %s%% contra el valor anterior', number_format($salto, 2, '.', ''));
        }
    }
    $dias = antiguedadEnDias($remota['fecha']);
    if ($dias !== null && $dias > DOLAR_DIAS_AVISO) {
        // El endpoint sin `?fecha=` devuelve la ULTIMA fila registrada, asi que
        // si el microservicio dejara de cargar cotizaciones seguiria contestando
        // 200 con un numero viejo y el job lo escribiria como si fuera de hoy.
        $avisos[] = sprintf('la cotizacion devuelta tiene %d dias (%s)', $dias, $remota['fecha']);
    }

    if (function_exists('registrarSuceso')) {
        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            $avisos === [] ? 'info' : 'alerta',
            $resumen . ($avisos === [] ? '' : ' | AVISO: ' . implode('; ', $avisos))
        );
    }
    foreach ($avisos as $aviso) anotarLog('AVISO: ' . $aviso);

    marcarEjecucionOk($resumen);

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    anotarLog('ERROR: ' . $e->getMessage());
    marcarEjecucionError($e);
    throw $e;
}

/**
 * La ultima cotizacion del microservicio, validada.
 *
 * SE PIDE SIN `?fecha=` A PROPOSITO. Con la fecha de hoy el endpoint contesta
 * **404** los sabados, domingos y feriados —verificado: `?fecha=2026-09-05`
 * devuelve "Cotizacion no encontrada"—, asi que el job fallaria dos de cada
 * siete corridas por algo que no es una falla. Sin fecha devuelve la ultima
 * registrada, que es justamente la del ultimo dia habil.
 *
 * @return array{id:int,fecha:string,compra:float,venta:float}
 */
function cotizacionRemota(): array
{
    if (!defined('DATABOX_APIKEY') || trim((string) DATABOX_APIKEY) === '') {
        throw new RuntimeException('Falta configurar DATABOX_APIKEY en el .env');
    }

    $ch = curl_init(DOLAR_URL);
    if ($ch === false) {
        throw new RuntimeException('No se pudo inicializar la conexion con Databox');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Accept: application/json',
            'Authorization: Bearer ' . DATABOX_APIKEY,
        ],
        CURLOPT_TIMEOUT        => DOLAR_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $respuesta = curl_exec($ch);
    $estado    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errCurl   = curl_error($ch);
    unset($ch);

    if ($respuesta === false) {
        throw new RuntimeException('No se pudo contactar a Databox' . ($errCurl !== '' ? ': ' . $errCurl : ''));
    }

    $body = json_decode((string) $respuesta, true);
    if (!is_array($body)) {
        throw new RuntimeException('Databox devolvio una respuesta ilegible (HTTP ' . $estado . ')');
    }
    if ($estado < 200 || $estado >= 300 || ($body['ok'] ?? false) !== true) {
        $detalle = trim((string) ($body['error'] ?? ''));
        throw new RuntimeException(
            'Databox rechazo el pedido (HTTP ' . $estado . ')' . ($detalle !== '' ? ': ' . $detalle : '')
        );
    }

    $data = $body['data'] ?? null;
    if (!is_array($data)) {
        throw new RuntimeException('Databox contesto ok pero sin `data`');
    }

    // `venta` es nullable segun el contrato del microservicio, asi que se valida
    // antes de tocar la base: escribir una cotizacion invalida es exactamente lo
    // que este chequeo existe para impedir.
    $venta = $data['venta'] ?? null;
    if ($venta === null || !is_numeric($venta)) {
        throw new RuntimeException('La cotizacion de venta vino vacia o no numerica');
    }
    $venta = (float) $venta;
    if ($venta < DOLAR_MINIMO || $venta > DOLAR_MAXIMO) {
        throw new RuntimeException(sprintf(
            'La cotizacion de venta (%s) esta fuera de la banda %s..%s',
            number_format($venta, 2, '.', ''),
            number_format(DOLAR_MINIMO, 2, '.', ''),
            number_format(DOLAR_MAXIMO, 2, '.', '')
        ));
    }

    return [
        'id'     => (int) ($data['id'] ?? 0),
        'fecha'  => trim((string) ($data['fecha'] ?? '')),
        'compra' => is_numeric($data['compra'] ?? null) ? (float) $data['compra'] : 0.0,
        'venta'  => $venta,
    ];
}

/** Valor actual de un parametro, o '' si la fila no esta. */
function parametroActual(PDO $pdo, string $variable): string
{
    $stmt = $pdo->prepare('SELECT valor FROM parametros WHERE variable = :v ORDER BY id LIMIT 1');
    $stmt->execute([':v' => $variable]);

    return trim((string) ($stmt->fetchColumn() ?: ''));
}

/**
 * Escribe un parametro. Con `$valor === null` guarda el `NOW()` de la base.
 *
 * ES UN `SELECT` DEL ID Y DESPUES UN `UPDATE`, no un `INSERT ... ON DUPLICATE
 * KEY`: `parametros` es la tabla del legacy y `variable` NO tiene UNIQUE, asi
 * que el upsert insertaria una fila duplicada en vez de pisar la que existe —y
 * `parametroValor()` se queda con la primera, que seria la vieja para siempre.
 * El `INSERT` es solo el camino de la base que todavia no tiene la fila.
 */
function parametroGuardar(PDO $pdo, string $variable, ?string $valor): void
{
    $expr = $valor === null ? "DATE_FORMAT(NOW(), '%Y-%m-%d %H:%i:%s')" : ':valor';
    $args = $valor === null ? [] : [':valor' => $valor];

    $stmt = $pdo->prepare('SELECT id FROM parametros WHERE variable = :v ORDER BY id LIMIT 1');
    $stmt->execute([':v' => $variable]);
    $id = (int) ($stmt->fetchColumn() ?: 0);

    if ($id > 0) {
        $pdo->prepare("UPDATE parametros SET valor = $expr WHERE id = :id")
            ->execute($args + [':id' => $id]);
        return;
    }

    $pdo->prepare("INSERT INTO parametros (variable, valor, comentario) VALUES (:v, $expr, :c)")
        ->execute($args + [':v' => $variable, ':c' => 'Cotización ultima actualización']);
}

/** Dias entre la fecha de la cotizacion y hoy, o null si no se puede leer. */
function antiguedadEnDias(string $fecha): ?int
{
    if ($fecha === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $fecha);
    if ($d === false) return null;

    return (int) $d->diff(new DateTimeImmutable('today'))->days;
}
