<?php

declare(strict_types=1);

/**
 * Uso por dispositivo: cuantos comandos recibio cada equipo del dominio,
 * punto por punto, en una ventana movil que elige el usuario.
 *
 *   GET api/dashboard_senales.php[?ventana=24h|7d|15d|30d]
 *       -> {puntos, granularidad, series, resumen, ...}
 *
 * LA GRANULARIDAD LA DECIDE LA VENTANA, no un parametro aparte: 24 h se
 * agrupa por HORA (24 puntos) y las ventanas en dias por DIA (7 / 15 / 30
 * puntos). Agrupar 24 h por dia daria un grafico de un solo punto, y agrupar
 * 30 dias por hora daria 720 puntos ilegibles. Por eso `VENTANAS` lleva la
 * unidad adentro y el front no puede combinarlas mal.
 *
 * LA VENTANA SE MIDE CON EL RELOJ DE LA BASE (`SELECT NOW()`), no con el de
 * PHP: los dos no estan alineados -- PHP corre en UTC y la sesion de MySQL
 * en -03:00 (medido 03/09/2026) -- y `senales.fecha` la escribe la base. Con
 * el reloj de PHP la ventana se corre 3 horas hacia adelante, los ultimos
 * puntos caen en el futuro y salen siempre en cero, asi que el grafico se lee
 * como si los equipos hubieran dejado de responder. Es el mismo problema (y
 * la misma solucion) que en api/dispositivo_conexion.php.
 *
 * Alimenta el grafico de lineas del Dashboard. MODULO DE SOLO LECTURA:
 * `senales` es el historial inmutable que escribe el motor MQTT, nunca el
 * panel. Cualquier metodo distinto de GET corta con 405.
 *
 * QUE CUENTA COMO USO: SOLO los mensajes que empiezan con `CMD=`. `senales`
 * mezcla varias familias de mensajes en la misma tabla y la mayoria no es
 * uso del equipo sino ruido de fondo del protocolo -- en la ventana medida,
 * de 71.789 filas de un dominio solo 18.064 son `CMD=`:
 *
 *   REP=LAT / REP=CNX / REP=INI   latido, conexion y arranque: los manda el
 *                                 equipo solo, este o no en uso.
 *   REP=SNS / REP=CAP / REP=CEN   reportes periodicos de sensores.
 *   CMD=...  (sentido 'S')        la ORDEN que sale hacia el equipo.
 *   RET=...  (sentido 'E')        la respuesta del equipo a esa orden.
 *
 * Se cuenta la ORDEN (`CMD=`) y no la respuesta (`RET=`): lo que el grafico
 * mide es cuanto se OPERO el equipo, y eso es una accion de la plataforma
 * sobre el equipo, no del equipo sobre la plataforma. Contar `RET=` haria
 * que un equipo que dejo de contestar apareciera como "sin uso" cuando en
 * realidad se lo siguio comandando -- que es justo lo que hay que ver. En la
 * ventana medida la diferencia es de 195 mensajes (18.064 `CMD=` contra
 * 17.869 `RET=`): ordenes que no obtuvieron respuesta.
 *
 * Al ser todas salientes no hace falta filtrar por `sentido`: el prefijo ya
 * lo determina (medido: las 18.064 son `sentido = 'S'`).
 *
 * ALCANCE: `senales` NO tiene columna `dominio` -- el unico camino al
 * inquilino es `senales.dispositivo -> dispositivos.dominio`. Por eso el
 * endpoint resuelve primero los equipos del dominio de la sesion
 * (requireDominioId()) y despues agrega solo por esos ids: sin ese paso el
 * grafico mostraria el trafico de otros clientes.
 *
 * CADA DIA ES UN CONTEO, NO UNA MEDICION. Un dia sin comandos vale 0, no
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

/**
 * Ventanas ofrecidas al front. `unidad` fija la granularidad del agrupamiento
 * y `cantidad` cuantos puntos tiene la serie. El ultimo punto siempre es el
 * que esta en curso (la hora o el dia de hoy), asi que va incompleto.
 */
// `etiqueta` va suelta en el encabezado ("últimos 7 días · 2.872 comandos")
// y `periodo` va dentro de una oracion ("...no registró uso en los últimos 7
// dias"). Son dos strings y no uno con el articulo pegado en el front porque
// el genero cambia con la unidad -- "LAS ultimas 24 horas" contra "LOS
// ultimos 7 dias" -- y esa concordancia no es logica de presentacion.
const VENTANAS = [
    '24h' => ['corta' => '24 h',    'etiqueta' => 'últimas 24 horas', 'periodo' => 'las últimas 24 horas', 'unidad' => 'hora', 'cantidad' => 24],
    '7d'  => ['corta' => '7 días',  'etiqueta' => 'últimos 7 días',   'periodo' => 'los últimos 7 días',   'unidad' => 'dia',  'cantidad' => 7],
    '15d' => ['corta' => '15 días', 'etiqueta' => 'últimos 15 días',  'periodo' => 'los últimos 15 días',  'unidad' => 'dia',  'cantidad' => 15],
    '30d' => ['corta' => '30 días', 'etiqueta' => 'últimos 30 días',  'periodo' => 'los últimos 30 días',  'unidad' => 'dia',  'cantidad' => 30],
];
const VENTANA_DEFECTO = '30d';

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
    handleGet((string) ($_GET['ventana'] ?? VENTANA_DEFECTO));
} catch (Throwable $e) {
    json_error('Error al obtener el uso por dispositivo: ' . $e->getMessage(), 500);
}

function handleGet(string $clave): void
{
    $dominio = requireDominioId();

    // Una ventana desconocida no es un error: cae en la de defecto. El front
    // manda solo claves de `opciones`, asi que llegar aca con otra cosa es
    // una URL escrita a mano y no vale cortarle la pantalla al usuario.
    $clave   = isset(VENTANAS[$clave]) ? $clave : VENTANA_DEFECTO;
    $ventana = VENTANAS[$clave];

    // El "ahora" sale de la BASE, no de PHP -- ver la cabecera del archivo.
    $ahora = new DateTimeImmutable((string) db()->query('SELECT NOW()')->fetchColumn());

    // La ventana cierra al final del punto en curso, no en un instante
    // arbitrario: asi el ultimo punto es "lo que va de esta hora / de hoy".
    if ($ventana['unidad'] === 'hora') {
        $hasta = $ahora->setTime((int) $ahora->format('H'), 0, 0)->modify('+1 hour');
        $desde = $hasta->modify('-' . $ventana['cantidad'] . ' hours');
    } else {
        $hasta = $ahora->setTime(0, 0, 0)->modify('+1 day');
        $desde = $hasta->modify('-' . $ventana['cantidad'] . ' days');
    }

    $puntos  = puntos($desde, $ventana);
    $equipos = equiposDelDominio($dominio);
    $conteos = $equipos === []
        ? []
        : conteosPorPuntoYEquipo(
            array_keys($equipos),
            $desde->format('Y-m-d H:i:s'),
            $hasta->format('Y-m-d H:i:s'),
            $ventana['unidad']
        );

    $series = series($conteos, $equipos, $puntos);

    json_ok([
        'puntos'       => $puntos,
        'granularidad' => $ventana['unidad'],
        'series'       => $series['series'],
        'otros'        => $series['otros'],
        // El front arma los chips con esto en vez de tener su propia lista:
        // una sola definicion de las ventanas, y no dos que se desincronizan.
        'opciones'     => array_map(
            static fn(string $k): array => ['clave' => $k, 'corta' => VENTANAS[$k]['corta']],
            array_keys(VENTANAS)
        ),
        'resumen' => [
            'ventana'          => $clave,
            'etiqueta'         => $ventana['etiqueta'],
            'periodo'          => $ventana['periodo'],
            'puntos'           => $ventana['cantidad'],
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

/**
 * Las claves de los puntos de la ventana, de la mas vieja a la mas nueva.
 * Tienen que salir del MISMO formato que agrupa el SQL o el emparejamiento
 * `clave -> conteo` falla en silencio y la serie queda toda en cero.
 */
function puntos(DateTimeImmutable $desde, array $ventana): array
{
    $hora   = $ventana['unidad'] === 'hora';
    $paso   = $hora ? 'hours' : 'days';
    $patron = $hora ? 'Y-m-d H:00:00' : 'Y-m-d';

    $puntos = [];
    for ($i = 0; $i < $ventana['cantidad']; $i++) {
        $puntos[] = $desde->modify("+$i $paso")->format($patron);
    }
    return $puntos;
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
 * Comandos (`CMD=`) por (dispositivo, punto) dentro de la ventana.
 *
 * El `IN` explicito de los equipos del dominio es lo que hace barata la
 * consulta: ataca `fk_senales_dispositivo`, que en InnoDB es (dispositivo,
 * id), asi que el `id >= :piso` recorta cada rango del indice por su propio
 * prefijo. Dejarselo al optimizador con un JOIN contra `dispositivos` lo
 * habilita a elegir el otro plan -- barrer la PK entera desde el piso -- que
 * para un dominio chico es leer cientos de miles de filas ajenas.
 *
 * El `LIKE 'CMD=%'` va anclado al principio a proposito: no es un comodin
 * por los dos lados. Filtra sobre filas que el indice ya trajo, asi que
 * cuesta CPU y no lecturas -- y ademas descarta ~3 de cada 4, que es trabajo
 * de agregacion que no se hace.
 */
function conteosPorPuntoYEquipo(array $ids, string $desde, string $hasta, string $unidad): array
{
    $piso   = senalesPisoPorFecha($desde);
    $marcas = implode(',', array_fill(0, count($ids), '?'));

    // La expresion de agrupamiento sale de una lista cerrada, NO del pedido:
    // va interpolada en el SQL y un valor de afuera seria inyeccion. Tiene
    // que producir exactamente las mismas claves que puntos().
    $expr = $unidad === 'hora' ? "DATE_FORMAT(fecha, '%Y-%m-%d %H:00:00')" : 'DATE(fecha)';

    $stmt = db()->prepare(
        "SELECT dispositivo, $expr AS punto, COUNT(*) AS cantidad
           FROM senales
          WHERE dispositivo IN ($marcas)
            AND id >= ?
            AND fecha >= ?
            AND fecha <  ?
            AND mensaje LIKE 'CMD=%'
          GROUP BY dispositivo, punto"
    );
    $stmt->execute([...array_values($ids), $piso, $desde, $hasta]);

    $conteos = [];
    foreach ($stmt->fetchAll() as $fila) {
        $conteos[(int) $fila['dispositivo']][(string) $fila['punto']] = (int) $fila['cantidad'];
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
function series(array $conteos, array $equipos, array $puntos): array
{
    $armadas = [];
    foreach ($conteos as $id => $porPunto) {
        $valores = array_map(static fn(string $p): int => $porPunto[$p] ?? 0, $puntos);
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
        $sumadas = array_fill(0, count($puntos), 0);
        foreach ($resto as $s) {
            foreach ($s['valores'] as $i => $v) {
                $sumadas[$i] += $v;
            }
        }
        $otros = [
            'nombre'  => count($resto) === 1 ? '1 equipo más' : count($resto) . ' equipos más',
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
