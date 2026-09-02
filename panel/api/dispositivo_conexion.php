<?php

declare(strict_types=1);

/**
 * Conexion de un dispositivo: serie del nivel de senal reportado por el
 * propio equipo (esquema real en db/schema.sql -> `senales`).
 *
 *   GET api/dispositivo_conexion.php?id=N -> muestras + resumen + escala
 *
 * MODULO DE SOLO LECTURA. `senales` es el historial inmutable de mensajes
 * del equipo: la escribe el motor MQTT, nunca el panel. Cualquier metodo
 * distinto de GET corta con 405.
 *
 * ALCANCE: el dispositivo tiene que ser del dominio de la sesion
 * (requireDominioId()). Sin esa comprobacion, un id a mano expondria la
 * telemetria de un equipo de otro cliente.
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
 * ~863K filas vivas y un solo dispositivo puede aportar 348K. Los unicos
 * indices son la PK y las FKs, asi que el LIKE sobre `mensaje` se resuelve
 * fila por fila: sobre el dispositivo mas cargado, un equipo que no
 * reporta senal barria sus 348K filas (1,6 s medidos). Por eso la busqueda
 * corre dentro de una ventana de las ultimas MUESTRAS_VENTANA senales DE
 * ESE DISPOSITIVO (0,05 s medidos). La ventana es relativa al equipo, no
 * global: asi tambien funciona para los que reportan poco y hace meses.
 */

require __DIR__ . '/bootstrap.php';

/** Extremos de la escala en dBm: `senalMaximo` / `senalMinimo` del legacy. */
const SENAL_MAXIMO = -10;   // 100%
const SENAL_MINIMO = -90;   //   0%

/** Cuantas senales del dispositivo se miran hacia atras (acota el LIKE). */
const MUESTRAS_VENTANA = 5000;

/** Cuantas mediciones devuelve como maximo (barras del grafico). */
const MUESTRAS_LIMITE = 60;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        json_error('Metodo no permitido: la conexion es de solo lectura', 405);
    }
    handleGet((int) ($_GET['id'] ?? 0));
} catch (Throwable $e) {
    json_error('Error al procesar la conexion del dispositivo: ' . $e->getMessage(), 500);
}

function handleGet(int $id): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    // El dispositivo tiene que existir DENTRO del dominio de la sesion.
    $own = db()->prepare(
        'SELECT id, uuid, nombre, senal, enlace, conexion
           FROM dispositivos WHERE id = :id AND dominio = :dom LIMIT 1'
    );
    $own->execute([':id' => $id, ':dom' => $dominio]);
    $dispositivo = $own->fetch();
    if (!$dispositivo) {
        json_error('Dispositivo no encontrado en este dominio', 404);
    }

    $muestras = muestras($id);

    json_ok([
        'dispositivo' => [
            'id'       => (int) $dispositivo['id'],
            'uuid'     => (string) ($dispositivo['uuid']   ?? ''),
            'nombre'   => (string) ($dispositivo['nombre'] ?? ''),
            'senal'    => nivel(nivelActual($dispositivo['senal'] ?? null)),
            'enlace'   => (int) ($dispositivo['enlace'] ?? 0) === 1,
            'conexion' => (string) ($dispositivo['conexion'] ?? ''),
        ],
        'muestras' => $muestras,
        'resumen'  => resumen($muestras),
        'escala'   => ['maximo' => SENAL_MAXIMO, 'minimo' => SENAL_MINIMO],
        'ventana'  => MUESTRAS_VENTANA,
        'limite'   => MUESTRAS_LIMITE,
    ]);
}

/**
 * Ultimas mediciones de senal del dispositivo, de la mas reciente a la mas
 * vieja (el mismo orden que todos los listados del panel).
 *
 * Se resuelve en dos pasos a proposito. El primero toma los ids de las
 * ultimas MUESTRAS_VENTANA senales del equipo: es un range scan puro sobre
 * el indice (dispositivo, id) -- las secundarias de InnoDB llevan la PK
 * detras -- y corta solo. Ese piso entra como cota en el segundo, que ya
 * si aplica los LIKE. Sin el piso, un equipo sin mediciones obliga a
 * recorrer todo su historial.
 */
function muestras(int $dispositivo): array
{
    $piso = db()->prepare(
        'SELECT MIN(id) FROM (
             SELECT id FROM senales WHERE dispositivo = :dis ORDER BY id DESC LIMIT ' . MUESTRAS_VENTANA . '
         ) AS ventana'
    );
    $piso->execute([':dis' => $dispositivo]);
    $desde = (int) $piso->fetchColumn();
    if ($desde <= 0) {
        return [];
    }

    // Las dos formas del protocolo. `RET=WSN|...` NO contiene "WSN=", asi
    // que no alcanza con el primer LIKE: hacen falta los dos.
    $stmt = db()->prepare(
        'SELECT id, fecha, mensaje
           FROM senales
          WHERE dispositivo = :dis
            AND id >= :desde
            AND sentido = \'E\'
            AND (mensaje LIKE \'%WSN=%\' OR mensaje LIKE \'RET=WSN|%\')
          ORDER BY id DESC
          LIMIT ' . MUESTRAS_LIMITE
    );
    $stmt->execute([':dis' => $dispositivo, ':desde' => $desde]);

    $muestras = [];
    foreach ($stmt->fetchAll() as $fila) {
        $mensaje = (string) ($fila['mensaje'] ?? '');
        $dbm     = nivelDelMensaje($mensaje);
        if ($dbm === null) {
            continue;   // El LIKE puede pescar un mensaje raro; se descarta.
        }
        $muestras[] = [
            'id'         => (int) $fila['id'],
            'fecha'      => (string) ($fila['fecha'] ?? ''),
            'dbm'        => $dbm,
            'porcentaje' => porcentaje($dbm),
            'reporte'    => reporteDelMensaje($mensaje),
        ];
    }

    return $muestras;
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

/** Tipo de reporte que trajo la medicion: 'CNX', 'INI', 'WSN'... */
function reporteDelMensaje(string $mensaje): string
{
    if (preg_match('/(?:^|\|)(?:REP|RET)=([A-Z]{2,4})/', $mensaje, $m)) {
        return $m[1];
    }
    return '';
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

/** Estadisticas de la serie devuelta (no de todo el historial). */
function resumen(array $muestras): array
{
    if ($muestras === []) {
        return [
            'muestras' => 0,    'promedio' => null,
            'mejor'    => null, 'peor'     => null, 'desde' => '', 'hasta' => '',
        ];
    }

    $niveles = array_column($muestras, 'dbm');

    // `muestras` viene de la mas reciente a la mas vieja: el periodo
    // cubierto arranca en la ultima fila y termina en la primera.
    return [
        'muestras' => count($muestras),
        'promedio' => nivel((int) round(array_sum($niveles) / count($niveles))),
        'mejor'    => nivel(max($niveles)),
        'peor'     => nivel(min($niveles)),
        'desde'    => (string) $muestras[count($muestras) - 1]['fecha'],
        'hasta'    => (string) $muestras[0]['fecha'],
    ];
}
