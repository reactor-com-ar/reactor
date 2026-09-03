<?php

declare(strict_types=1);

/**
 * Conexion de un dispositivo: serie horaria del nivel de senal reportado
 * por el propio equipo (esquema real en db/schema.sql -> `senales`).
 *
 *   GET api/dispositivo_conexion.php?id=N[&horas=24] -> serie + resumen
 *
 * MODULO DE SOLO LECTURA. `senales` es el historial inmutable de mensajes
 * del equipo: la escribe el motor MQTT, nunca el panel. Cualquier metodo
 * distinto de GET corta con 405.
 *
 * ALCANCE: el dispositivo tiene que ser del dominio de la sesion
 * (requireDominioId()). Sin esa comprobacion, un id a mano expondria la
 * telemetria de un equipo de otro cliente.
 *
 * LA SERIE TIENE UN PUNTO POR HORA, REPORTE O NO EL EQUIPO. Las horas sin
 * mediciones viajan con `dbm = null`: son parte del dato -- que un equipo
 * deje de informar tres horas es exactamente lo que hay que poder ver --
 * y el front las dibuja como un corte en la linea, no como una
 * interpolacion entre los dos extremos.
 *
 * DE DONDE SALE EL NIVEL: `senales` no tiene columna de senal -- el valor
 * viaja adentro de `mensaje`, en el protocolo de etiquetas `CLAVE=valor`
 * separadas por `|` que hablan los equipos. Hay dos formas, las dos
 * entrantes (`sentido = 'E'`):
 *
 *   1. `REP=CNX|CNX=23|LAT=86|WSN=-65|WIP=192.168.188.132|IDT=...`
 *      El equipo reporta, y de paso informa su senal en `WSN` (WiFi
 *      Signal, en dBm). Tambien aparece en `REP=INI` (arranque). Es la
 *      forma habitual: ~16.700 de cada 300.000 senales entrantes.
 *   2. `RET=WSN|VAL=-94|IDT=...`
 *      Respuesta a un pedido explicito del nivel de senal. El valor va en
 *      `VAL`. Es rara pero es literalmente "el nivel informado", asi que
 *      cuenta igual. OJO: `VAL` solo es senal en esta forma -- en
 *      `REP=SNS|CNL=1|VAL=4.1` es la lectura de un sensor.
 *
 * ESCALA: -10 dBm = 100% y -90 dBm = 0%, la misma que aplica
 * `cDispositivo::senal2porcentaje()` del legacy
 * (reactor-api/framework/subframework.php). No inventar otra: el mismo
 * equipo tiene que leerse igual en el panel y en el back office viejo.
 *
 * ESCALA DE LA TABLA (medido en reactor_dev, 2026-09-02): `senales` tiene
 * ~863K filas vivas, entran ~90K por semana y un solo dispositivo puede
 * aportar 23K en 7 dias. Los unicos indices son la PK y las FKs: `fecha`
 * NO esta indexada, asi que filtrar por rango de fechas sobre el indice de
 * `dispositivo` obliga a mirar fila por fila todo el historial del equipo
 * (348K filas / 1,6 s en el peor caso medido). Por eso la consulta lleva
 * ademas una cota por PK -- ver senalesPisoPorFecha() en lib/senales.php.
 */

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/senales.php';

/** Extremos de la escala en dBm: `senalMaximo` / `senalMinimo` del legacy. */
const SENAL_MAXIMO = -10;   // 100%
const SENAL_MINIMO = -90;   //   0%

/** Ventanas ofrecidas al front, en horas. La serie siempre es horaria. */
const HORAS_OPCIONES = [24, 48, 168];
const HORAS_DEFECTO  = 24;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido: la conexion es de solo lectura', 405);
    }
    handleGet((int) ($_GET['id'] ?? 0), (int) ($_GET['horas'] ?? HORAS_DEFECTO));
} catch (Throwable $e) {
    json_error('Error al procesar la conexion del dispositivo: ' . $e->getMessage(), 500);
}

function handleGet(int $id, int $horas): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }
    if (!in_array($horas, HORAS_OPCIONES, true)) {
        $horas = HORAS_DEFECTO;
    }

    // El dispositivo tiene que existir DENTRO del dominio de la sesion.
    $own = db()->prepare(
        'SELECT id, uuid, nombre, senal, enlace, conexion, latido
           FROM dispositivos WHERE id = :id AND dominio = :dom LIMIT 1'
    );
    $own->execute([':id' => $id, ':dom' => $dominio]);
    $dispositivo = $own->fetch();
    if (!$dispositivo) {
        json_error('Dispositivo no encontrado en este dominio', 404);
    }

    // La ventana arranca al principio de una hora y termina al final de la
    // hora en curso: asi el ultimo punto es "lo que va de esta hora" y no
    // un tramo cortado en un minuto arbitrario.
    $ahora   = new DateTimeImmutable('now');
    $hasta   = $ahora->setTime((int) $ahora->format('H'), 0, 0);
    $desde   = $hasta->modify('-' . ($horas - 1) . ' hours');
    $desdeDb = $desde->format('Y-m-d H:i:s');

    $muestras = muestras($id, $desdeDb, senalesPisoPorFecha($desdeDb));
    $serie    = serie($muestras, $desde, $horas);

    json_ok([
        'dispositivo' => [
            'id'       => (int) $dispositivo['id'],
            'uuid'     => (string) ($dispositivo['uuid']   ?? ''),
            'nombre'   => (string) ($dispositivo['nombre'] ?? ''),
            'senal'    => nivel(nivelActual($dispositivo['senal'] ?? null)),
            'enlace'   => (int) ($dispositivo['enlace'] ?? 0) === 1,
            'conexion' => (string) ($dispositivo['conexion'] ?? ''),
            'latido'   => (string) ($dispositivo['latido']   ?? ''),
        ],
        'serie'   => $serie,
        'resumen' => resumen($muestras, $serie),
        'escala'  => ['maximo' => SENAL_MAXIMO, 'minimo' => SENAL_MINIMO],
        'horas'   => $horas,
        'desde'   => $desdeDb,
        'hasta'   => $hasta->modify('+1 hour')->format('Y-m-d H:i:s'),
        'opciones' => HORAS_OPCIONES,
    ]);
}

/** Mediciones crudas del equipo dentro de la ventana, de la mas vieja a la mas nueva. */
function muestras(int $dispositivo, string $desde, int $piso): array
{
    // Las dos formas del protocolo. `RET=WSN|...` NO contiene "WSN=", asi
    // que no alcanza con el primer LIKE: hacen falta los dos.
    $stmt = db()->prepare(
        'SELECT id, fecha, mensaje
           FROM senales
          WHERE dispositivo = :dis
            AND id >= :piso
            AND fecha >= :desde
            AND sentido = \'E\'
            AND (mensaje LIKE \'%WSN=%\' OR mensaje LIKE \'RET=WSN|%\')
          ORDER BY id ASC'
    );
    $stmt->execute([':dis' => $dispositivo, ':piso' => $piso, ':desde' => $desde]);

    $muestras = [];
    foreach ($stmt->fetchAll() as $fila) {
        $mensaje = (string) ($fila['mensaje'] ?? '');
        $dbm     = nivelDelMensaje($mensaje);
        if ($dbm === null) {
            continue;   // El LIKE puede pescar un mensaje raro; se descarta.
        }
        $muestras[] = ['fecha' => (string) ($fila['fecha'] ?? ''), 'dbm' => $dbm];
    }

    return $muestras;
}

/**
 * Serie horaria completa: un punto por cada hora de la ventana, tenga
 * mediciones o no. El valor de la hora es el PROMEDIO de lo que reporto el
 * equipo en esa hora (un equipo activo informa decenas de veces por hora);
 * `minimo` y `maximo` viajan aparte para poder mostrar la dispersion sin
 * ensuciar la linea.
 */
function serie(array $muestras, DateTimeImmutable $desde, int $horas): array
{
    // Las mediciones se agrupan por su hora ('Y-m-d H:00:00').
    $porHora = [];
    foreach ($muestras as $m) {
        $clave = substr($m['fecha'], 0, 13) . ':00:00';
        $porHora[$clave][] = $m['dbm'];
    }

    $serie = [];
    for ($i = 0; $i < $horas; $i++) {
        $hora   = $desde->modify('+' . $i . ' hours');
        $clave  = $hora->format('Y-m-d H:00:00');
        $lote   = $porHora[$clave] ?? [];
        $vacia  = $lote === [];
        $prom   = $vacia ? null : (int) round(array_sum($lote) / count($lote));

        $serie[] = [
            'hora'       => $clave,
            'dbm'        => $prom,
            'porcentaje' => $prom === null ? null : porcentaje($prom),
            'minimo'     => $vacia ? null : min($lote),
            'maximo'     => $vacia ? null : max($lote),
            'muestras'   => count($lote),
        ];
    }

    return $serie;
}

/**
 * Nivel en dBm que informa un mensaje entrante, o null si no informa
 * ninguno. Primero la forma explicita (`RET=WSN|VAL=...`) y despues la
 * etiqueta `WSN` que viaja dentro de los reportes.
 */
function nivelDelMensaje(string $mensaje): ?int
{
    if (preg_match('/(?:^|\|)RET=WSN\|(?:.*?\|)?VAL=(-?\d+)/', $mensaje, $m)) {
        return (int) $m[1];
    }
    if (preg_match('/(?:^|\|)WSN=(-?\d+)/', $mensaje, $m)) {
        return (int) $m[1];
    }
    return null;
}

/**
 * dBm -> % de la escala del producto. Es `senal2porcentaje()` del legacy
 * escrito de forma directa: 0% en SENAL_MINIMO, 100% en SENAL_MAXIMO y
 * lineal en el medio (cada 20 dBm son 25 puntos), con recorte en los
 * extremos porque hay equipos que reportan fuera de rango.
 *
 * La conversion vive SOLO aca: el front recibe los dos numeros ya
 * calculados y no repite la formula, para que no puedan desalinearse.
 */
function porcentaje(int $dbm): int
{
    $rango = SENAL_MAXIMO - SENAL_MINIMO;           // 80
    $pct   = (int) round((($dbm - SENAL_MINIMO) * 100) / $rango);
    return max(0, min(100, $pct));
}

/** Nivel listo para mostrar: dBm crudo + su equivalente en la escala. */
function nivel(?int $dbm): ?array
{
    return $dbm === null ? null : ['dbm' => $dbm, 'porcentaje' => porcentaje($dbm)];
}

/**
 * `dispositivos.senal` es varchar y arrastra valores escritos a mano del
 * sistema viejo ("-59dB alta"), ademas de vacios. Se toma el entero con
 * signo del principio y se ignora el resto; si no hay numero, no hay nivel.
 */
function nivelActual(mixed $valor): ?int
{
    return preg_match('/^\s*(-?\d+)/', (string) ($valor ?? ''), $m) ? (int) $m[1] : null;
}

/**
 * Estadisticas de la ventana. `promedio` / `mejor` / `peor` salen de las
 * mediciones crudas, no de los promedios horarios: una hora con 40 lecturas
 * y otra con 1 no pesan igual, y el minimo real se perderia dentro del
 * promedio de su hora.
 */
function resumen(array $muestras, array $serie): array
{
    $conDato = count(array_filter($serie, static fn(array $p): bool => $p['dbm'] !== null));

    if ($muestras === []) {
        return [
            'muestras' => 0, 'horas' => count($serie), 'horas_con_dato' => 0,
            'promedio' => null, 'mejor' => null, 'peor' => null,
        ];
    }

    $niveles = array_column($muestras, 'dbm');

    return [
        'muestras'       => count($muestras),
        'horas'          => count($serie),
        'horas_con_dato' => $conDato,
        'promedio'       => nivel((int) round(array_sum($niveles) / count($niveles))),
        'mejor'          => nivel(max($niveles)),
        'peor'           => nivel(min($niveles)),
    ];
}
