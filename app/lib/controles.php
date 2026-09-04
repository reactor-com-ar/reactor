<?php

declare(strict_types=1);

/**
 * Controles, botones y canales del panel abierto.
 *
 * Port de los bucles de `reactor-app/panel/index.php`, que arma la pantalla
 * con tres consultas anidadas dentro de un `for`:
 *
 *     select * from controles where (panel=N and habilitado=1) order by orden,id limit 50
 *     select * from botones   where (control=N and habilitado="1") order by orden,id limit 10
 *     select * from canales   where (dispositivo=N and habilitado="1") order by id
 *
 * Acá son tres consultas en total, no tres por control: el legacy hace una
 * lectura por fila (`$xControl->leer()`, `$xDispositivo->leer()`, y adentro
 * otra por cada botón y cada canal), que en un panel de 6 controles con 4
 * botones son más de 50 viajes a la base para pintar una pantalla.
 */

require_once __DIR__ . '/db.php';

/** Color del display cuando el control no define uno (`colores` id 100). */
const APP_COLOR_PREDETERMINADO = '#EBC300';

/** Color del display apagado — el "gris" del legacy (`colores` id 105). */
const APP_COLOR_GRIS = '#9e9e9e';

/** Escala de señal del legacy (`cDispositivo::senal2porcentaje`). */
const APP_SENAL_MAXIMA = -10;   // dBm = 100%
const APP_SENAL_MINIMA = -90;   // dBm = 0%

/**
 * Todo lo que necesita la pantalla del panel, en una sola estructura.
 *
 * @return list<array{
 *     id:int, uuid:string, nombre:string, color:string, online:bool,
 *     habilitado:bool, estadoTexto:string, senal:?int, dispositivo:int,
 *     canales:list<array{id:int, n:int, on:bool, valor:string, sensor:bool}>,
 *     botones:list<array{id:int, uuid:string, icono:string, texto:string, accion:string}>
 * }>
 */
function appControlesDelPanel(int $panel, int $dominio): array
{
    if ($panel <= 0 || $dominio <= 0) {
        return [];
    }

    // 1) Controles del panel + el dispositivo de cada uno.
    //
    // El `c.dominio = :dom` es control de acceso, no una comodidad: sin él, un
    // id de panel de otro cliente pintaría sus controles. El legacy no lo
    // filtra porque confía en que el panel salió de la sesión.
    $stmt = db()->prepare(
        'SELECT c.id, c.uuid, c.nombre, c.dispositivo,
                co.codigo AS color,
                d.habilitado AS d_habilitado, d.enlace, d.senal
         FROM controles c
         LEFT JOIN colores      co ON co.id = c.color
         LEFT JOIN dispositivos d  ON d.id  = c.dispositivo
         WHERE c.panel = :panel AND c.dominio = :dom AND c.habilitado = 1
         ORDER BY c.orden, c.id
         LIMIT 50'
    );
    $stmt->execute([':panel' => $panel, ':dom' => $dominio]);
    $filas = $stmt->fetchAll();
    if (!$filas) {
        return [];
    }

    $idsControl     = array_map(static fn (array $r): int => (int) $r['id'], $filas);
    $idsDispositivo = array_values(array_unique(array_filter(
        array_map(static fn (array $r): int => (int) $r['dispositivo'], $filas)
    )));

    $botones = appBotonesDeControles($idsControl);
    $canales = appCanalesDeDispositivos($idsDispositivo);

    $salida = [];
    foreach ($filas as $r) {
        $id          = (int) $r['id'];
        $dispositivo = (int) $r['dispositivo'];

        // Los tres estados del legacy, en el mismo orden de precedencia:
        // deshabilitado gana sobre desconectado, y desconectado sobre online.
        $habilitado = (string) ($r['d_habilitado'] ?? '') === '1';
        $online     = $habilitado && (string) ($r['enlace'] ?? '') === '1';

        if (!$habilitado) {
            $estadoTexto = 'Deshabilitado';
        } elseif (!$online) {
            $estadoTexto = 'Desconectado';
        } else {
            $estadoTexto = '';
        }

        $color = trim((string) ($r['color'] ?? ''));
        $salida[] = [
            'id'          => $id,
            'uuid'        => (string) ($r['uuid'] ?? ''),
            'nombre'      => trim((string) ($r['nombre'] ?? '')) ?: '(sin nombre)',
            // El display se apaga a gris cuando el equipo no está operativo,
            // sin importar el color que tenga configurado el control.
            'color'       => $online ? ($color !== '' ? $color : APP_COLOR_PREDETERMINADO) : APP_COLOR_GRIS,
            'online'      => $online,
            'habilitado'  => $habilitado,
            'estadoTexto' => $estadoTexto,
            'senal'       => $online ? appSenalPorcentaje((string) ($r['senal'] ?? '')) : null,
            'dispositivo' => $dispositivo,
            'canales'     => $canales[$dispositivo] ?? [],
            'botones'     => $botones[$id] ?? [],
        ];
    }

    return $salida;
}

/**
 * Botones de varios controles, agrupados por control.
 *
 * `iconos.codigo` guarda la clase de FontAwesome ya armada ("fas fa-car",
 * "far fa-lightbulb"). Son clases de FA5 y la app carga FA6, que las sigue
 * entendiendo por su capa de compatibilidad.
 *
 * @param list<int> $controles
 * @return array<int, list<array{id:int, uuid:string, icono:string, texto:string, accion:string}>>
 */
function appBotonesDeControles(array $controles): array
{
    if (!$controles) {
        return [];
    }

    $marcas = implode(',', array_fill(0, count($controles), '?'));
    $stmt = db()->prepare(
        'SELECT b.id, b.uuid, b.control, b.texto, b.accion, i.codigo AS icono
         FROM botones b
         LEFT JOIN iconos i ON i.id = b.icono
         WHERE b.control IN (' . $marcas . ') AND b.habilitado = \'1\'
         ORDER BY b.control, b.orden, b.id'
    );
    $stmt->execute($controles);

    // El legacy corta en 10 botones por control (`limit 10`). Como acá los
    // botones de todos los controles vienen en una sola consulta, el corte se
    // aplica al agrupar.
    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $control = (int) $r['control'];
        if (count($out[$control] ?? []) >= 10) {
            continue;
        }
        $out[$control][] = [
            'id'     => (int) $r['id'],
            'uuid'   => (string) ($r['uuid'] ?? ''),
            'icono'  => trim((string) ($r['icono'] ?? '')),
            'texto'  => trim((string) ($r['texto'] ?? '')),
            'accion' => (string) ($r['accion'] ?? ''),
        ];
    }

    return $out;
}

/**
 * Canales de varios dispositivos, agrupados por dispositivo.
 *
 * `modulos.tipo` separa actuador ('A') de sensor ('S'): el actuador se pinta
 * como pastilla encendida/apagada con el número de canal, y el sensor muestra
 * su valor. Es la misma bifurcación de `panel/monitor.php`.
 *
 * ESTE ESTADO LO ESCRIBE EL MOTOR PYTHON, no la app. `canales.estado` lo
 * actualiza `reactor-api/motor/inicio.py` cuando el equipo reporta
 * (`REP=CEN` / `REP=CAP` / `REP=SNS`). La app sólo lee.
 *
 * @param list<int> $dispositivos
 * @return array<int, list<array{id:int, n:int, on:bool, valor:string, sensor:bool}>>
 */
function appCanalesDeDispositivos(array $dispositivos): array
{
    if (!$dispositivos) {
        return [];
    }

    $marcas = implode(',', array_fill(0, count($dispositivos), '?'));
    $stmt = db()->prepare(
        'SELECT c.id, c.dispositivo, c.canal, c.estado, m.tipo
         FROM canales c
         LEFT JOIN modulos m ON m.id = c.modulo
         WHERE c.dispositivo IN (' . $marcas . ') AND c.habilitado = 1
         ORDER BY c.dispositivo, c.id'
    );
    $stmt->execute($dispositivos);

    $out = [];
    foreach ($stmt->fetchAll() as $r) {
        $estado = trim((string) ($r['estado'] ?? ''));
        $out[(int) $r['dispositivo']][] = [
            'id'     => (int) $r['id'],
            'n'      => (int) $r['canal'],
            'on'     => $estado === '1',
            'valor'  => $estado,
            'sensor' => (string) ($r['tipo'] ?? '') === 'S',
        ];
    }

    return $out;
}

/**
 * Nivel de señal en porcentaje, con la escala del legacy.
 *
 * -10 dBm = 100% y -90 dBm = 0%, lineal. `dispositivos.senal` es varchar y
 * arrastra valores escritos a mano ("-59dB alta"), así que se toma el entero
 * con signo del principio en vez de validar la cadena entera.
 */
function appSenalPorcentaje(string $senal): ?int
{
    if (!preg_match('/-?\d+/', $senal, $m)) {
        return null;
    }

    $dbm = (int) $m[0];
    $pct = (int) round((($dbm - APP_SENAL_MINIMA) * 100) / (APP_SENAL_MAXIMA - APP_SENAL_MINIMA));

    return max(0, min(100, $pct));
}
