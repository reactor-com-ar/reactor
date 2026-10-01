<?php

declare(strict_types=1);

/**
 * Repreciar los articulos en dolares con la cotizacion vigente.
 *
 * Recorre `articulos` WHERE `moneda` = 'D' y reescribe las dos columnas
 * derivadas con la cuenta de siempre (`articuloPrecios()` de
 * `api/articulos_lib.php`):
 *
 *     compra = importacion * cotizacion
 *     venta  = compra + (compra * margen / 100)
 *
 * Corre todos los dias a las 07:00 por el Programador de tareas, UNA HORA
 * DESPUES de `dolar_actualizar.php`, que es el que mueve el parametro con el
 * que este job reprecia. El orden importa: invertido, el recalculo del dia
 * aplicaria la cotizacion de ayer y el parametro nuevo recien se veria en los
 * precios 24 horas mas tarde. La hora separada —y no los dos jobs en uno— es
 * lo que hace que si Databox se cae, la cotizacion no se mueva y el recalculo
 * siga siendo un no-op en vez de arrastrar al error de otra tarea.
 *
 * ESTE JOB REVIERTE UNA DECISION QUE ESTUVO TOMADA AL REVES, y queda dicho
 * porque el repo entero la documentaba: hasta el 30/09/2026 mover la cotizacion
 * y repreciar eran dos cosas a proposito, y repreciar era SOLO una accion de
 * fila con confirmacion (`api/articulos_accion.php?accion=recalcular`), con
 * este argumento: cambia el abono de contratos vivos, o sea que es plata y
 * alguien tiene que mirarlo. El argumento sigue siendo cierto y es la razon de
 * todo lo que hay abajo —los bloqueos, el rastro fila por fila, el conteo de
 * contratos afectados—, pero la decision es la contraria: el precio de un
 * articulo importado se sigue del dolar todos los dias, y dejarlo clavado hasta
 * que alguien se acuerde de tocar la fila es tambien una decision de plata,
 * tomada por omision. Al 30/09/2026 convivian CINCO cotizaciones implicitas en
 * la tabla (1370, 1375, 1380, 1415 y 1450) contra un parametro en 1540: 70 de
 * las 79 filas en dolares vendian a una cotizacion vieja.
 *
 * LA ACCION DE FILA NO SE VA. Sigue siendo la que se usa cuando hay que
 * repreciar YA —recien cargada una fila, o movida la cotizacion a mano— y es la
 * unica que muestra los cuatro numeros antes de escribir. Este job es el piso
 * diario, no su reemplazo.
 *
 * NO TOCA LOS ARTICULOS EN PESOS, y no es una omision: ahi `compra` se carga a
 * mano y `venta` ya sale de ella en cada guardado del ABM, asi que un recalculo
 * no tendria de donde mover nada. La cotizacion no participa.
 */

require_once __DIR__ . '/_bootstrap.php';

/**
 * LA CUENTA DE PRECIOS ES UNA SOLA Y VIVE EN `api/articulos_lib.php`.
 *
 * Esa libreria la escribio para la web y por eso resuelve la conexion con
 * `db()`, que declara `api/bootstrap.php` — un archivo que manda headers HTTP y
 * llama a `requireAuth()`, o sea que no se puede incluir desde un proceso CLI.
 * La salida es declarar `db()` aca apuntando al PDO del job: cuesta estas seis
 * lineas y evita lo unico que no se puede permitir, que es una segunda copia de
 * `articuloPrecios()`. Con dos copias, el precio que anuncia la pantalla de
 * recalcular y el que escribe este job dejan de ser el mismo numero el dia que
 * alguien toque una sola de las dos.
 *
 * No hay riesgo de redeclarar: `api/bootstrap.php` no entra nunca por este
 * camino (corta con 403 si no es CLI... porque no llega a cargarse).
 */
function db(): PDO
{
    return _jobsPdo();
}

require_once __DIR__ . '/../api/articulos_lib.php';

// El bootstrap carga este helper solo en el camino de error. Se requiere aca
// porque el job tambien deja rastro cuando sale bien: repreciar la lista de
// precios es exactamente el tipo de cosa que hay que poder ubicar en el tiempo
// desde el Visor de sucesos, sin ir a buscar el .log de la ejecucion.
$_sucesos = __DIR__ . '/../api/lib/sucesos.php';
if (is_file($_sucesos)) require_once $_sucesos;

/**
 * Banda de cordura de la cotizacion, la misma que valida `dolar_actualizar.php`.
 *
 * No es una validacion de negocio sino el piso y el techo de lo fisicamente
 * posible. Lo que tiene que cortar es un `0`: `cotizacionDolar()` devuelve 0
 * cuando el parametro falta o no es numerico, y con eso este job pondria la
 * tabla entera en dolares a $ 0,00 — incluido el abono de los contratos que
 * facturan esos articulos. Es el mismo bloqueo que ya tiene la accion de fila,
 * y aca pesa mas porque no hay nadie mirando la pantalla.
 */
const ARTICULOS_COTIZACION_MINIMA = 1.0;
const ARTICULOS_COTIZACION_MAXIMA = 1000000.0;

/**
 * Antiguedad del parametro a partir de la cual se avisa, en dias.
 *
 * ESTE JOB ES EL CANARIO DE `dolar_actualizar.php`. Si la tarea de las 06:00
 * falla, el parametro se queda quieto y el recalculo de las 07:00 no tiene nada
 * que mover: sale `ok` con 0 filas tocadas, que es indistinguible de un dia en
 * que el dolar no se movio. Dos dias de atraso ya son dos corridas fallidas de
 * la otra tarea, y eso se dice.
 */
const ARTICULOS_DIAS_AVISO = 2;

try {
    $pdo = _jobsPdo();

    $cotizacion = cotizacionDolar();
    $fechaParam = parametroValor(PARAMETRO_COTIZACION_FECHA);

    // Se valida ANTES de abrir la transaccion y se corta con excepcion: con una
    // cotizacion invalida no hay nada que recalcular, y lo peor que puede hacer
    // este job es escribir.
    if ($cotizacion < ARTICULOS_COTIZACION_MINIMA || $cotizacion > ARTICULOS_COTIZACION_MAXIMA) {
        throw new RuntimeException(sprintf(
            'La cotizacion de `%s` (%s) esta fuera de la banda %s..%s: no se recalcula nada',
            PARAMETRO_COTIZACION,
            $cotizacion > 0 ? number_format($cotizacion, 2, '.', '') : 'vacia o no numerica',
            number_format(ARTICULOS_COTIZACION_MINIMA, 2, '.', ''),
            number_format(ARTICULOS_COTIZACION_MAXIMA, 2, '.', '')
        ));
    }

    anotarLog(sprintf(
        'Cotizacion %s (parametro %s, actualizado %s)',
        number_format($cotizacion, 2, '.', ''),
        PARAMETRO_COTIZACION,
        $fechaParam !== '' ? $fechaParam : 's/d'
    ));

    $tocados   = [];   // id => [nombre, antes, despues]
    $salteados = [];   // [id, nombre, motivo]
    $iguales   = 0;
    $total     = 0;

    // TODAS LAS FILAS EN UNA SOLA TRANSACCION, y no una por una con commit al
    // final de cada vuelta. La lista de precios es una: si el proceso se muere a
    // mitad, media tabla a 1540 y media a 1380 es un estado que nadie eligio —y
    // `cContrato::facturar()` cobra `articulos`.`venta` tal cual, asi que un
    // contrato facturado en esa ventana saldria con el precio de la mitad que
    // toco. Son 79 filas: el lock dura milisegundos y son las 07:00.
    $pdo->beginTransaction();

    // `FOR UPDATE` por lo mismo que en la accion de fila: entre leer y escribir
    // alguien puede estar guardando el articulo desde el ABM —que recalcula en
    // cada guardado— y la cuenta tiene que salir de lo que hay ahora.
    // `moneda` no tiene indice, asi que esto bloquea el scan de las 106 filas.
    // A esta hora y a esta escala no es un problema; con la tabla diez veces mas
    // grande habria que indexar `moneda` antes que cambiar el lock.
    $stmt = $pdo->prepare(
        'SELECT id, nombre, moneda, importacion, compra, margen, venta
           FROM articulos
          WHERE moneda = :d
          ORDER BY id
          FOR UPDATE'
    );
    $stmt->execute([':d' => MONEDA_DOLAR]);
    $filas = $stmt->fetchAll();
    $total = count($filas);

    anotarLog(sprintf('%d articulos en %s', $total, MONEDA_DOLAR));

    $update = $pdo->prepare('UPDATE articulos SET compra = :compra, venta = :venta WHERE id = :id');

    foreach ($filas as $r) {
        $id          = (int) $r['id'];
        $nombre      = trim((string) ($r['nombre'] ?? ''));
        $importacion = (float) $r['importacion'];
        $compra      = (float) $r['compra'];
        $margen      = (float) $r['margen'];
        $venta       = (float) $r['venta'];

        // LA MISMA FUNCION QUE EL ABM Y QUE LA ACCION DE FILA. Resuelve la
        // cotizacion por `cotizacionDolar()`, que cachea el parametro por
        // proceso: las 79 filas quedan repreciadas con EL MISMO numero, aunque
        // alguien mueva el parametro a mitad de corrida. Una lista de precios
        // con dos cotizaciones adentro es justamente lo que este job existe
        // para deshacer.
        $nuevo = articuloPrecios((string) $r['moneda'], $importacion, $compra, $margen);

        // Se saltea y se reporta, NO se recorta. `decimal(10,2)` corta en
        // 99.999.999,99: con `STRICT_TRANS_TABLES` el UPDATE revienta —y se
        // llevaria la transaccion entera, o sea las 78 filas sanas— y sin modo
        // estricto guardaria el tope en silencio, que es un precio inventado.
        if ($nuevo['compra'] > DECIMAL_MAX || $nuevo['venta'] > DECIMAL_MAX) {
            $salteados[] = [$id, $nombre, sprintf(
                'el precio resultante (%s) supera el maximo de la columna',
                number_format(max($nuevo['compra'], $nuevo['venta']), 2, '.', '')
            )];
            continue;
        }

        // UNA FILA QUE TENIA PRECIO NO SE MANDA A CERO SIN QUE ALGUIEN LO VEA.
        // Pasa cuando `importacion` quedo en NULL o en 0 con `compra` cargada:
        // la cuenta da $ 0,00 y la fila pasaria a venderse gratis. La accion de
        // fila escribe ese 0 porque antes se lo muestra a quien confirma; acá no
        // hay nadie, asi que se saltea y se avisa. Al 30/09/2026 las 9 filas en
        // dolares sin `importacion` ya estan en 0 (dos son planes Free, que
        // valen 0 de verdad), asi que hoy este bloqueo no desvia ninguna.
        if ($nuevo['compra'] <= 0.0 && $nuevo['venta'] <= 0.0 && ($compra > 0.0 || $venta > 0.0)) {
            $salteados[] = [$id, $nombre, sprintf(
                'quedaria en $ 0,00 viniendo de compra %s / venta %s (importacion %s)',
                number_format($compra, 2, '.', ''),
                number_format($venta, 2, '.', ''),
                number_format($importacion, 2, '.', '')
            )];
            continue;
        }

        // El centavo es el umbral porque `articuloPrecios()` ya redondeo a dos
        // decimales: una diferencia menor es ruido de punto flotante, no un
        // precio distinto. Las filas que no cambian no se escriben — asi
        // `fecha_modificacion` de la tabla (y el rastro del .log) hablan de los
        // precios que de verdad se movieron.
        if (abs($nuevo['compra'] - $compra) <= 0.01 && abs($nuevo['venta'] - $venta) <= 0.01) {
            $iguales++;
            continue;
        }

        $update->execute([
            ':compra' => $nuevo['compra'],
            ':venta'  => $nuevo['venta'],
            ':id'     => $id,
        ]);

        $tocados[$id] = [$nombre, ['compra' => $compra, 'venta' => $venta], $nuevo];

        anotarLog(sprintf(
            '  #%d %s | compra %s -> %s | venta %s -> %s (%s%%)',
            $id,
            $nombre !== '' ? mb_substr($nombre, 0, 50) : '(sin nombre)',
            number_format($compra, 2, '.', ''),
            number_format($nuevo['compra'], 2, '.', ''),
            number_format($venta, 2, '.', ''),
            number_format($nuevo['venta'], 2, '.', ''),
            $venta > 0
                ? number_format((($nuevo['venta'] - $venta) / $venta) * 100, 2, '.', '')
                : 's/d'
        ));
    }

    $pdo->commit();

    foreach ($salteados as [$id, $nombre, $motivo]) {
        anotarLog(sprintf('  SALTEADO #%d %s: %s', $id, $nombre !== '' ? $nombre : '(sin nombre)', $motivo));
    }

    // Los planes que facturan estos articulos cobran `articulos`.`venta` como
    // abono (`cContrato::facturar()`), asi que mover el precio les cambio el
    // abono a todos los contratos que cuelgan de ellos. La accion de fila lo
    // dice ANTES de confirmar, en el recuadro de avisos; un job no tiene a quien
    // decirselo antes, asi que lo deja contado despues. Es el numero por el que
    // alguien va a venir a preguntar.
    $impacto = impactoEnPlanes($pdo, array_keys($tocados));

    anotarLog(sprintf(
        'Resumen: %d repreciados | %d sin cambios | %d salteados | de %d en dolares',
        count($tocados), $iguales, count($salteados), $total
    ));
    if ($impacto['planes'] > 0) {
        anotarLog(sprintf(
            'Abono afectado: %d planes / %d contratos facturan alguno de los articulos repreciados',
            $impacto['planes'], $impacto['contratos']
        ));
    }

    $resumen = sprintf(
        'articulos: %d repreciados a cotizacion %s (%d sin cambios, %d salteados, de %d en dolares)%s',
        count($tocados),
        number_format($cotizacion, 2, '.', ''),
        $iguales,
        count($salteados),
        $total,
        $impacto['planes'] > 0
            ? sprintf(' | afecta el abono de %d planes / %d contratos', $impacto['planes'], $impacto['contratos'])
            : ''
    );

    // Los avisos NO cortan: lo que se escribio se escribio bien. Lo que hacen es
    // dejar rastro de los dos casos en que la corrida sale `ok` y aun asi hay
    // algo que mirar.
    $avisos = [];
    foreach ($salteados as [$id, $nombre, $motivo]) {
        $avisos[] = sprintf('#%d %s: %s', $id, $nombre !== '' ? $nombre : '(sin nombre)', $motivo);
    }
    $dias = antiguedadDelParametro($fechaParam);
    if ($dias !== null && $dias > ARTICULOS_DIAS_AVISO) {
        $avisos[] = sprintf(
            'la cotizacion tiene %d dias (%s): revisar la tarea `dolar_actualizar`',
            $dias, $fechaParam
        );
    }

    if (function_exists('registrarSuceso')) {
        registrarSuceso(
            $pdo,
            'cron/' . jobNombre(),
            $avisos === [] ? 'info' : 'alerta',
            // `sucesos_log`.`detalle` es TEXT, pero un job que saltea 70 filas
            // no tiene que escribir un parrafo de 70 renglones en el Visor: el
            // detalle fila por fila ya esta en el .log de la ejecucion.
            $resumen . ($avisos === [] ? '' : ' | AVISO: ' . implode('; ', array_slice($avisos, 0, 5)))
                     . (count($avisos) > 5 ? sprintf(' (+%d mas en el log)', count($avisos) - 5) : '')
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
 * Cuantos planes y cuantos contratos cambian de abono por lo que se reprecio.
 *
 * @param int[] $ids Articulos efectivamente escritos.
 * @return array{planes:int,contratos:int}
 */
function impactoEnPlanes(PDO $pdo, array $ids): array
{
    if ($ids === []) return ['planes' => 0, 'contratos' => 0];

    // Placeholders posicionales y no un `implode` de los ids: salen casteados a
    // entero de una fila de la base y no de una entrada, pero la regla del repo
    // es que en un `IN` van placeholders.
    $marcas = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $pdo->prepare(
        "SELECT COUNT(DISTINCT p.id) AS planes, COUNT(c.id) AS contratos
           FROM planes p
           LEFT JOIN contratos c ON c.plan = p.id
          WHERE p.articulo IN ($marcas)"
    );
    $stmt->execute(array_values($ids));
    $r = $stmt->fetch() ?: [];

    return [
        'planes'    => (int) ($r['planes'] ?? 0),
        'contratos' => (int) ($r['contratos'] ?? 0),
    ];
}

/** Dias desde que `dolar_actualizar` escribio el parametro, o null si no se lee. */
function antiguedadDelParametro(string $fecha): ?int
{
    if ($fecha === '') return null;
    $d = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $fecha);
    if ($d === false) {
        // El legacy escribia la misma columna, asi que puede venir sin hora.
        $d = DateTimeImmutable::createFromFormat('!Y-m-d', substr($fecha, 0, 10));
    }
    if ($d === false) return null;

    return (int) $d->diff(new DateTimeImmutable('now'))->days;
}
