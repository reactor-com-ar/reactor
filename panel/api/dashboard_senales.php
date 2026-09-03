<?php

declare(strict_types=1);

/**
 * Uso por dispositivo: cuantas respuestas registro cada equipo del dominio,
 * dia por dia, en la ventana movil de los ultimos 30 dias.
 *
 *   GET api/dashboard_senales.php -> {dias, series, resumen, ...}
 *
 * Alimenta el grafico de lineas del Dashboard. MODULO DE SOLO LECTURA:
 * `senales` es el historial inmutable que escribe el motor MQTT, nunca el
 * panel. Cualquier metodo distinto de GET corta con 405.
 *
 * QUE CUENTA COMO USO: SOLO los mensajes que empiezan con `RET=`. `senales`
 * mezcla varias familias de mensajes en la misma tabla y la mayoria no es
 * uso del equipo sino ruido de fondo del protocolo -- en la ventana medida,
 * de 70.290 filas de un dominio solo 17.516 son `RET=`:
 *
 *   REP=LAT / REP=CNX / REP=INI   latido, conexion y arranque: los manda el
 *                                 equipo solo, este o no en uso.
 *   REP=SNS / REP=CAP / REP=CEN   reportes periodicos de sensores.
 *   CMD=...  (sentido 'S')        la orden que SALE hacia el equipo.
 *   RET=...  (sentido 'E')        la respuesta del equipo a esa orden.
 *
 * `RET=` es la unica familia que prueba que alguien opero el equipo y que el
 * equipo contesto, asi que es la que mide uso. Se cuenta la respuesta y no
 * el `CMD=` que la provoca para no contar dos veces la misma interaccion, y
 * porque el `CMD=` sin respuesta es una orden que no llego a destino.
 *
 * Al ser todas entrantes no hace falta filtrar por `sentido`: el prefijo ya
 * lo determina.
 *
 * ALCANCE: `senales` NO tiene columna `dominio` -- el unico camino al
 * inquilino es `senales.dispositivo -> dispositivos.dominio`. Por eso el
 * endpoint resuelve primero los equipos del dominio de la sesion
 * (requireDominioId()) y despues agrega solo por esos ids: sin ese paso el
 * grafico mostraria el trafico de otros clientes.
 *
 * CADA DIA ES UN CONTEO, NO UNA MEDICION. Un dia sin respuestas vale 0, no
 * "sin dato": el equipo estuvo y nadie lo uso, que es exactamente lo que el
 * grafico tiene que mostrar. Por eso -- a diferencia de la pestaña Conexion
 * de Dispositivos, donde las horas sin reporte son un corte en la linea --
 * aca la serie no lleva huecos y se rellena con ceros.
 *
 * SOLO SE DEVUELVEN LOS EQUIPOS CON USO en la ventana. Un dominio con 13
 * equipos de los que 4 respondieron produce 4 series, no 13 lineas planas
 * pisandose en el cero. Cuantos equipos quedaron afuera viaja en `resumen`
 * para que el front lo pueda decir.
 *
 * TOPE DE SERIES DIBUJADAS: la paleta es de la familia del rojo institucional
 * y sólo tiene SERIES_MAXIMO ranuras que se distinguen entre si (ver el
 * comentario de la paleta en style.css §15b). Los equipos que sobran se suman
 * en una serie agregada "otros", que va aparte del array `series`.
 *
 * Pero `series` viaja COMPLETO igual, con `slot = null` en los que no
 * entraron: el grafico dibuja los primeros y el agregado, y la vista de
 * tabla lista equipo por equipo. Asi el tope de colores no esconde ningun
 * dato -- solo decide que se dibuja con linea propia. Importa: el dominio
 * mas grande de los datos actuales tiene 10 equipos con actividad.
 *
 * COSTO (medido en reactor_dev, 2026-09-03): 0,04 s la busqueda del piso +
 * 0,08 s la agregacion del dominio mas cargado (13 equipos / 72K senales en
 * la ventana). Sin la cota por PK la misma agregacion recorre todo el
 * historial de esos equipos -- ver lib/senales.php.
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/senales.php';

/** Ancho de la ventana, en dias. El ultimo dia es el de hoy, incompleto. */
const DIAS_VENTANA = 30;

/**
 * Cuantos equipos se dibujan con linea de color propio. Es el tamaño exacto
 * de la paleta del front (6 ranuras verificadas), no un numero redondo: la
 * ranura 7 no existe. La paleta es de la familia del rojo institucional
 * -- rojo, durazno, amarillo, blanco, rosa y oro -- y esa restriccion es la
 * que la deja en 6: fuera de esas seis, dos calidos cualesquiera se
 * confunden entre si bajo daltonismo (ver style.css §15b).
 */
const SERIES_MAXIMO = 6;

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        json_error('Metodo no permitido: el uso por dispositivo es de solo lectura', 405);
    }
    handleGet();
} catch (Throwable $e) {
    json_error('Error al obtener el uso por dispositivo: ' . $e->getMessage(), 500);
}

function handleGet(): void
{
    $dominio = requireDominioId();

    // La ventana arranca al principio de un dia y termina al final de hoy:
    // asi el ultimo punto es "lo que va del dia" y no un tramo cortado en
    // una hora arbitraria.
    $hoy   = (new DateTimeImmutable('now'))->setTime(0, 0, 0);
    $desde = $hoy->modify('-' . (DIAS_VENTANA - 1) . ' days');
    $hasta = $hoy->modify('+1 day');

    $dias      = dias($desde);
    $equipos   = equiposDelDominio($dominio);
    $conteos   = $equipos === []
        ? []
        : conteosPorDiaYEquipo(array_keys($equipos), $desde->format('Y-m-d H:i:s'), $hasta->format('Y-m-d H:i:s'));

    $series = series($conteos, $equipos, $dias);

    json_ok([
        'dias'    => $dias,
        'series'  => $series['series'],
        'otros'   => $series['otros'],
        'resumen' => [
            'dias'             => DIAS_VENTANA,
            'total'            => $series['total'],
            'maximo'           => $series['maximo'],
            'equipos_dominio'  => count($equipos),
            'equipos_activos'  => $series['activos'],
            // Ultima señal de vida conocida de la flota. Sale de las columnas
            // que mantiene el motor (`latido` / `conexion`), NO de un MAX()
            // sobre `senales`: ese MAX no se puede acotar por PK -- justamente
            // se busca lo mas nuevo, que puede ser muy viejo -- y obligaria a
            // recorrer el historial completo de cada equipo. Es lo que el
            // front muestra cuando la ventana sale vacia.
            'ultima_actividad' => $series['activos'] === 0 ? ultimaActividad($equipos) : null,
        ],
        'desde' => $desde->format('Y-m-d H:i:s'),
        'hasta' => $hasta->format('Y-m-d H:i:s'),
    ]);
}

/** Las DIAS_VENTANA fechas de la ventana, de la mas vieja a la mas nueva. */
function dias(DateTimeImmutable $desde): array
{
    $dias = [];
    for ($i = 0; $i < DIAS_VENTANA; $i++) {
        $dias[] = $desde->modify('+' . $i . ' days')->format('Y-m-d');
    }
    return $dias;
}

/**
 * Equipos del dominio, indexados por id. Se traen todos (no solo los que
 * reportaron): hacen falta para poner nombre a cada serie, para contar
 * cuantos quedaron sin actividad y para el ultimo latido de la flota.
 */
function equiposDelDominio(int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT id, nombre, uuid, latido, conexion
           FROM dispositivos
          WHERE dominio = :dom
          ORDER BY id ASC'
    );
    $stmt->execute([':dom' => $dominio]);

    $equipos = [];
    foreach ($stmt->fetchAll() as $fila) {
        $equipos[(int) $fila['id']] = [
            'id'       => (int) $fila['id'],
            'nombre'   => trim((string) ($fila['nombre'] ?? '')),
            'uuid'     => (string) ($fila['uuid'] ?? ''),
            'latido'   => (string) ($fila['latido']   ?? ''),
            'conexion' => (string) ($fila['conexion'] ?? ''),
        ];
    }

    return $equipos;
}

/**
 * Respuestas (`RET=`) por (dispositivo, dia) dentro de la ventana.
 *
 * El `IN` explicito de los equipos del dominio es lo que hace barata la
 * consulta: ataca `fk_senales_dispositivo`, que en InnoDB es (dispositivo,
 * id), asi que el `id >= :piso` recorta cada rango del indice por su propio
 * prefijo. Dejarselo al optimizador con un JOIN contra `dispositivos` lo
 * habilita a elegir el otro plan -- barrer la PK entera desde el piso -- que
 * para un dominio chico es leer cientos de miles de filas ajenas.
 *
 * El `LIKE 'RET=%'` va anclado al principio a proposito: no es un comodin
 * por los dos lados. Filtra sobre filas que el indice ya trajo, asi que
 * cuesta CPU y no lecturas -- y ademas descarta ~3 de cada 4, que es trabajo
 * de agregacion que no se hace.
 */
function conteosPorDiaYEquipo(array $ids, string $desde, string $hasta): array
{
    $piso   = senalesPisoPorFecha($desde);
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    $stmt = db()->prepare(
        "SELECT dispositivo, DATE(fecha) AS dia, COUNT(*) AS cantidad
           FROM senales
          WHERE dispositivo IN ($marcas)
            AND id >= ?
            AND fecha >= ?
            AND fecha <  ?
            AND mensaje LIKE 'RET=%'
          GROUP BY dispositivo, DATE(fecha)"
    );
    $stmt->execute([...array_values($ids), $piso, $desde, $hasta]);

    $conteos = [];
    foreach ($stmt->fetchAll() as $fila) {
        $conteos[(int) $fila['dispositivo']][(string) $fila['dia']] = (int) $fila['cantidad'];
    }

    return $conteos;
}

/**
 * Arma las series: una por equipo con actividad, ordenadas de mayor a menor
 * total. Las primeras SERIES_MAXIMO llevan ranura de color; el resto viaja
 * con `slot = null` y ademas sumado en `otros`.
 *
 * `slot` lo asigna el BACKEND a proposito: es lo unico que ata un color a un
 * equipo. Si lo eligiera el front por posicion en el array, cualquier
 * reordenamiento repintaria las lineas y el que aprendio "el Comedor es
 * rojo" leeria mal el grafico. `otros` va sin slot -- es gris, no una
 * ranura mas.
 */
function series(array $conteos, array $equipos, array $dias): array
{
    $armadas = [];
    foreach ($conteos as $id => $porDia) {
        $valores = array_map(static fn(string $d): int => $porDia[$d] ?? 0, $dias);
        $total   = array_sum($valores);
        if ($total <= 0) {
            continue;
        }
        $armadas[] = [
            'id'      => $id,
            'nombre'  => nombreEquipo($equipos[$id] ?? null, $id),
            'uuid'    => (string) ($equipos[$id]['uuid'] ?? ''),
            'total'   => $total,
            'valores' => $valores,
        ];
    }

    // Mayor a menor total y, a igual total, por id: el orden tiene que ser
    // estable entre dos cargas o los colores bailan.
    usort($armadas, static fn(array $a, array $b): int => [$b['total'], $a['id']] <=> [$a['total'], $b['id']]);

    $series = [];
    foreach ($armadas as $i => $s) {
        $series[] = $s + ['slot' => $i < SERIES_MAXIMO ? $i + 1 : null];
    }

    // Agregado de los que no entraron en la paleta. Se manda aparte de
    // `series` (y no como una fila mas) porque no es un equipo: la tabla
    // lista los equipos uno por uno y no tiene que mostrarlo.
    $resto = array_slice($armadas, SERIES_MAXIMO);
    $otros = null;
    if ($resto !== []) {
        $sumadas = array_fill(0, count($dias), 0);
        foreach ($resto as $s) {
            foreach ($s['valores'] as $i => $v) {
                $sumadas[$i] += $v;
            }
        }
        $otros = [
            'nombre'  => count($resto) . ' equipos mas',
            'equipos' => count($resto),
            'total'   => array_sum($sumadas),
            'valores' => $sumadas,
        ];
    }

    // El maximo es el tope del eje Y, asi que se calcula sobre lo que
    // efectivamente se DIBUJA: las series con ranura mas el agregado. Sobre
    // todas las series daria un tope mas alto que ninguna linea alcanza.
    $maximo = 0;
    foreach (array_slice($series, 0, SERIES_MAXIMO) as $s) {
        $maximo = max($maximo, ...$s['valores']);
    }
    if ($otros !== null) {
        $maximo = max($maximo, ...$otros['valores']);
    }

    return [
        'series'  => $series,
        'otros'   => $otros,
        'activos' => count($armadas),
        'total'   => array_sum(array_map(static fn(array $s): int => $s['total'], $armadas)),
        'maximo'  => $maximo,
    ];
}

/**
 * `dispositivos.nombre` viene de la carga manual del dueño anterior y hay
 * equipos con el campo vacio. En ese caso la serie se rotula con el uuid
 * (el identificador corto que el usuario ve en el listado) y, si tampoco
 * hay, con el id.
 */
function nombreEquipo(?array $equipo, int $id): string
{
    $nombre = trim((string) ($equipo['nombre'] ?? ''));
    if ($nombre !== '') {
        return $nombre;
    }
    $uuid = trim((string) ($equipo['uuid'] ?? ''));
    return $uuid !== '' ? $uuid : "Equipo #$id";
}

/** La mas reciente entre todos los `latido` / `conexion` de la flota, o null. */
function ultimaActividad(array $equipos): ?string
{
    $ultima = '';
    foreach ($equipos as $e) {
        foreach ([$e['latido'], $e['conexion']] as $fecha) {
            // El sistema historico usa '0000-00-00' y el centinela '1500-01-01'
            // en lugar de NULL; ninguno es una fecha real de actividad.
            if ($fecha === '' || str_starts_with($fecha, '0000-') || str_starts_with($fecha, '1500-')) {
                continue;
            }
            if ($fecha > $ultima) {
                $ultima = $fecha;
            }
        }
    }

    return $ultima !== '' ? $ultima : null;
}
