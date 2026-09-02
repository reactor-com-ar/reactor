<?php

declare(strict_types=1);

/**
 * ABM de `dispositivos` (esquema real en db/schema.sql).
 *
 *   GET    api/dispositivos.php                     -> listado + resumen + catalogos
 *   GET    api/dispositivos.php?id=N                -> un registro (todos los campos)
 *   POST   api/dispositivos.php?accion=adoptar      -> adoptar por numero de serie
 *   POST   api/dispositivos.php?accion=liberar&id=N -> liberar
 *   PUT    api/dispositivos.php                     -> modificacion
 *
 * NO HAY ALTA NI BAJA. El cliente no da de alta ni borra equipos: los
 * fabrica Reactor y el panel solo los ADOPTA (entran al dominio) y los
 * LIBERA (vuelven al pool). Ese es todo el ciclo de vida que ve el cliente.
 * Ademas, cualquier equipo que haya estado en servicio tiene historial en
 * `adopciones`, `canales`, `botones`, `controles`, `etiquetas` y `usos`,
 * todas con FK ON DELETE RESTRICT, asi que el DELETE fallaba con 1451.
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()). Ningun
 * query corre sin ese filtro, ni siquiera el lookup por id.
 *
 * CAMPOS DE TELEMETRIA: `enlace`, `ip`, `senal`, `firmware`, `inicio`,
 * `conexion`, `latido`, `inicios`, `conexiones`, `latidos`, `adoptado`,
 * `adopcion`, `monitoreoUltimo` y `monitoreoSiguiente` los escribe el propio
 * equipo (o el motor de monitoreo). Se devuelven para consultar pero el alta y
 * la modificacion NUNCA los tocan: editarlos a mano corrompe el estado
 * operativo del dispositivo.
 */

require __DIR__ . '/bootstrap.php';

const ORDEN_VALIDO = ['id', 'uuid', 'nombre', 'latido', 'conexion', 'instalacion', 'fabricacion'];
const MAX_LIMITE   = 1000;

/**
 * Dominio "pool" al que vuelven los equipos liberados. No es un dominio de
 * cliente: es el estante de los que no estan asignados a nadie (102 equipos
 * en dev, `dominios.id = 1`, `nombre = 'Liberado'`, `habilitado = 0`). Es
 * como lo modela el sistema historico: `dispositivos.dominio` no admite
 * "sin dominio" en la practica (0 filas en NULL), el sin-dominio se
 * representa con esta fila.
 */
const DOMINIO_LIBERADO = 1;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
            break;
        case 'POST':
            switch ($_GET['accion'] ?? '') {
                case 'adoptar': handleAdoptar(); break;
                case 'liberar': handleLiberar(); break;
                default:
                    json_error('Accion no soportada: los dispositivos se incorporan adoptandolos por numero de serie', 400);
            }
            break;
        case 'PUT':    handleUpdate(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar dispositivos: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q      = trim((string) ($_GET['q']      ?? ''));
    $codigo = (int)         ($_GET['codigo'] ?? 0);
    $modelo = (int)         ($_GET['modelo'] ?? 0);
    $enlace = (string)      ($_GET['enlace'] ?? 'todos');
    $estado = (string)      ($_GET['estado'] ?? 'todos');
    $limite = (int)         ($_GET['limite'] ?? 100);
    $orden  = (string)      ($_GET['orden']  ?? 'id');
    $dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)         $limite = 100;
    if ($limite > MAX_LIMITE) $limite = MAX_LIMITE;
    if (!in_array($orden, ORDEN_VALIDO, true)) $orden = 'id';

    $where  = ['d.dominio = :dom'];
    $params = [':dom' => $dominio];

    if ($codigo > 0) {
        $where[]        = 'd.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($modelo > 0) {
        $where[]        = 'd.modelo = :mod';
        $params[':mod'] = $modelo;
    }
    if ($enlace === 'online') {
        $where[] = 'd.enlace = 1';
    } elseif ($enlace === 'offline') {
        $where[] = '(d.enlace IS NULL OR d.enlace <> 1)';
    }
    if ($estado === 'habilitados') {
        $where[] = 'd.habilitado = 1';
    } elseif ($estado === 'deshabilitados') {
        $where[] = '(d.habilitado IS NULL OR d.habilitado <> 1)';
    }
    if ($q !== '') {
        // Un placeholder por columna: con EMULATE_PREPARES=false, PDO no admite
        // repetir el mismo nombre en un statement (SQLSTATE HY093).
        $columnas = ['d.uuid', 'd.nombre', 'd.mac', 'd.ip', 'd.serial', 'd.identidad'];
        $ors      = [];
        foreach ($columnas as $i => $columna) {
            $ors[]               = $columna . ' LIKE :q' . $i;
            $params[':q' . $i]   = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    $sql = 'SELECT d.id, d.uuid, d.nombre, d.mac, d.ip, d.senal, d.firmware,
                   d.habilitado, d.enlace, d.monitoreo, d.latido, d.conexion,
                   d.instalacion, d.modelo, d.agente, d.chip,
                   m.nombre AS modelo_nombre,
                   a.nombre AS agente_nombre
            FROM dispositivos d
            LEFT JOIN modelos m ON m.id = d.modelo
            LEFT JOIN agentes a ON a.id = d.agente
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY d.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $dispositivos = array_map('mapDispositivo', $stmt->fetchAll());

    // Resumen sobre el dominio completo, no sobre la pagina devuelta.
    $res = db()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN habilitado = 1 THEN 1 ELSE 0 END) AS habilitados,
                SUM(CASE WHEN enlace     = 1 THEN 1 ELSE 0 END) AS enlazados
         FROM dispositivos WHERE dominio = :dom'
    );
    $res->execute([':dom' => $dominio]);
    $r = $res->fetch() ?: ['total' => 0, 'habilitados' => 0, 'enlazados' => 0];

    json_ok([
        'dispositivos' => $dispositivos,
        'catalogos'    => catalogos(),
        'resumen'      => [
            'total'       => (int) $r['total'],
            'habilitados' => (int) $r['habilitados'],
            'enlazados'   => (int) $r['enlazados'],
            'mostrados'   => count($dispositivos),
        ],
    ]);
}

function handleGet(int $id): void
{
    $dominio = requireDominioId();
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $stmt = db()->prepare(
        'SELECT d.*,
                m.nombre  AS modelo_nombre,
                a.nombre  AS agente_nombre,
                p.nombre  AS producto_nombre,
                t.nombre  AS transceptor_nombre,
                dom.nombre AS dominio_nombre,
                c.telefono AS chip_telefono,
                c.serie    AS chip_serie
         FROM dispositivos d
         LEFT JOIN modelos       m   ON m.id   = d.modelo
         LEFT JOIN agentes       a   ON a.id   = d.agente
         LEFT JOIN productos     p   ON p.id   = d.producto
         LEFT JOIN transceptores t   ON t.id   = d.transceptor
         LEFT JOIN dominios      dom ON dom.id = d.dominio
         LEFT JOIN chips         c   ON c.id   = d.chip
         WHERE d.id = :id AND d.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Dispositivo no encontrado en este dominio', 404);
    }

    json_ok(['dispositivo' => mapDispositivo($row)]);
}

/* ------------------------------------------------------------------ */
/* Adopcion / Modificacion / Liberacion                                */
/* ------------------------------------------------------------------ */

/**
 * Adoptar: incorpora al dominio de la sesion un equipo que este en el pool
 * `Liberado`, buscandolo por su numero de serie. Es la unica forma de sumar un
 * dispositivo — reemplaza al alta, porque el cliente no fabrica equipos.
 *
 * Es la inversa exacta de handleLiberar(): abre una fila en `adopciones`
 * (`vigente = '1'`, `liberado` con el centinela '1500-01-01 00:00:00') y mueve
 * el dispositivo al dominio con `adoptado = 1`, `adopcion` = la fila nueva y
 * `habilitado = 1`, para que quede operativo sin un segundo paso.
 *
 * `serial` NO es unico en la tabla y no hay UNIQUE que lo impida (3 repetidos
 * en dev, uno de ellos con las dos filas en el pool), asi que la busqueda
 * puede traer varias: si mas de una esta libre no se adivina cual.
 *
 * El UPDATE final lleva `AND dominio = DOMINIO_LIBERADO` a proposito: si entre
 * el SELECT y el UPDATE otra cuenta adopto el mismo equipo, afecta 0 filas y
 * la transaccion se deshace en lugar de robarselo.
 */
function handleAdoptar(): void
{
    $dominio = requireDominioId();
    $serial  = trim((string) (readJson()['serial'] ?? ''));

    if ($serial === '') {
        json_error('Ingresa el numero de serie del equipo', 422);
    }
    if (mb_strlen($serial) > 50) {
        json_error('El numero de serie no puede superar 50 caracteres', 422);
    }

    $stmt = db()->prepare(
        'SELECT d.id, d.uuid, d.nombre, d.serial, d.dominio, m.nombre AS modelo_nombre
         FROM dispositivos d
         LEFT JOIN modelos m ON m.id = d.modelo
         WHERE d.serial = :s
         ORDER BY d.id ASC'
    );
    $stmt->execute([':s' => $serial]);
    $filas = $stmt->fetchAll();

    if (!$filas) {
        json_error('No encontramos ningun equipo con ese numero de serie. Revisa la etiqueta del dispositivo.', 404);
    }

    $libres = array_values(array_filter(
        $filas,
        static fn(array $r): bool => (int) $r['dominio'] === DOMINIO_LIBERADO
    ));

    if (!$libres) {
        // Existe, pero no esta disponible. El mensaje distingue si ya es tuyo:
        // "ya esta adoptado por otra cuenta" confunde cuando el equipo es propio.
        $propio = array_filter($filas, static fn(array $r): bool => (int) $r['dominio'] === $dominio);
        json_error(
            $propio
                ? 'Ese equipo ya esta en tu cuenta.'
                : 'Ese equipo ya esta adoptado por otra cuenta. Pedile al titular que lo libere para poder adoptarlo.',
            409
        );
    }
    if (count($libres) > 1) {
        json_error('Hay mas de un equipo con ese numero de serie. Contactate con Reactor para que lo resuelvan.', 409);
    }

    $disp    = $libres[0];
    $id      = (int) $disp['id'];
    $usuario = (int) (sessionContext()['id'] ?? 0);

    db()->beginTransaction();
    try {
        // Un equipo del pool no deberia tener adopciones abiertas, pero los
        // datos traen de todo (24 filas del pool siguen con adoptado = 1): se
        // cierran antes para no dejarlo con dos vigentes a la vez.
        $cerrar = db()->prepare(
            "UPDATE adopciones SET liberado = NOW(), vigente = '0'
              WHERE dispositivo = :id AND vigente = '1'"
        );
        $cerrar->execute([':id' => $id]);

        $ins = db()->prepare(
            "INSERT INTO adopciones (dispositivo, dominio, adoptado, adoptador, liberado, vigente)
             VALUES (:disp, :dom, NOW(), :usr, '1500-01-01 00:00:00', '1')"
        );
        $ins->execute([':disp' => $id, ':dom' => $dominio, ':usr' => $usuario > 0 ? $usuario : null]);
        $adopcion = (int) db()->lastInsertId();

        $upd = db()->prepare(
            'UPDATE dispositivos
                SET dominio = :dom, adoptado = 1, adopcion = :ad, habilitado = 1
              WHERE id = :id AND dominio = :libre'
        );
        $upd->execute([':dom' => $dominio, ':ad' => $adopcion, ':id' => $id, ':libre' => DOMINIO_LIBERADO]);

        if ($upd->rowCount() === 0) {
            db()->rollBack();
            json_error('Otra cuenta adopto ese equipo recien. Volve a intentar con otro numero de serie.', 409);
        }

        db()->commit();
    } catch (Throwable $e) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        throw $e;
    }

    json_ok([
        'id'            => $id,
        'uuid'          => (string) ($disp['uuid']          ?? ''),
        'nombre'        => (string) ($disp['nombre']        ?? ''),
        'serial'        => (string) ($disp['serial']        ?? ''),
        'modelo_nombre' => (string) ($disp['modelo_nombre'] ?? ''),
    ], 201);
}

function handleUpdate(): void
{
    $dominio = requireDominioId();
    $in      = readJson();
    $id      = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    // El registro tiene que existir DENTRO del dominio de la sesion.
    $own = db()->prepare('SELECT id FROM dispositivos WHERE id = :id AND dominio = :dom LIMIT 1');
    $own->execute([':id' => $id, ':dom' => $dominio]);
    if (!$own->fetchColumn()) {
        json_error('Dispositivo no encontrado en este dominio', 404);
    }

    $datos = validar($in, $dominio, $id);

    $stmt = db()->prepare(
        'UPDATE dispositivos
            SET uuid = :uuid, nombre = :nombre, agente = :agente, modelo = :modelo,
                producto = :producto, transceptor = :transceptor, chip = :chip,
                mac = :mac, serial = :serial, identidad = :identidad, llave = :llave,
                habilitado = :habilitado, senalesLimite = :senalesLimite,
                fabricacion = :fabricacion, instalacion = :instalacion,
                monitoreo = :monitoreo, monitoreoIntervalo = :monitoreoIntervalo,
                monitoreoCorreos = :monitoreoCorreos, coordenadas = :coordenadas,
                indicadores = :indicadores
          WHERE id = :id AND dominio = :dom'
    );
    $stmt->execute(array_merge($datos, [':id' => $id, ':dom' => $dominio]));

    json_ok(['id' => $id]);
}

/**
 * Liberar: saca al dispositivo del dominio y lo deja disponible para que otra
 * cuenta lo adopte. NO es una baja — el equipo y todo su historial siguen
 * existiendo.
 *
 * Son dos escrituras, en una transaccion:
 *   1. Se cierran las adopciones vigentes del equipo (`liberado` = ahora,
 *      `liberador` = quien lo libero, `vigente` = '0'). Se cierran TODAS y se
 *      buscan por `adopciones.dispositivo`, no por `dispositivos.adopcion`:
 *      los datos traen equipos con hasta 3 filas vigentes a la vez y una
 *      misma fila de adopcion apuntada por dos dispositivos, asi que ese
 *      puntero no sirve para saber cual cerrar.
 *   2. El dispositivo pasa al dominio `Liberado`, se le limpia el estado de
 *      adopcion (`adoptado = 0`, `adopcion = NULL`) y queda `habilitado = 0`:
 *      es como esta el 96% del pool (98 de 102 en dev) y evita que un equipo
 *      sin dueño siga operando.
 *
 * `adopciones.liberado` no admite NULL en la practica: las filas abiertas
 * llevan el centinela '1500-01-01 00:00:00' del sistema historico.
 */
function handleLiberar(): void
{
    $dominio = requireDominioId();
    $id      = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }
    if ($dominio === DOMINIO_LIBERADO) {
        json_error('El dispositivo ya esta liberado', 409);
    }

    // Tiene que existir DENTRO del dominio de la sesion: sin este filtro un id
    // a mano liberaria el equipo de otro cliente.
    $own = db()->prepare('SELECT id FROM dispositivos WHERE id = :id AND dominio = :dom LIMIT 1');
    $own->execute([':id' => $id, ':dom' => $dominio]);
    if (!$own->fetchColumn()) {
        json_error('Dispositivo no encontrado en este dominio', 404);
    }

    $usuario = (int) (sessionContext()['id'] ?? 0);

    db()->beginTransaction();
    try {
        $cerrar = db()->prepare(
            "UPDATE adopciones
                SET liberado = NOW(), liberador = :usr, vigente = '0'
              WHERE dispositivo = :id AND vigente = '1'"
        );
        $cerrar->execute([':usr' => $usuario > 0 ? $usuario : null, ':id' => $id]);
        $cerradas = $cerrar->rowCount();

        $upd = db()->prepare(
            'UPDATE dispositivos
                SET dominio = :libre, adoptado = 0, adopcion = NULL, habilitado = 0
              WHERE id = :id AND dominio = :dom'
        );
        $upd->execute([':libre' => DOMINIO_LIBERADO, ':id' => $id, ':dom' => $dominio]);

        db()->commit();
    } catch (Throwable $e) {
        db()->rollBack();
        throw $e;
    }

    json_ok(['id' => $id, 'adopcionesCerradas' => $cerradas]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Normaliza una fila de `dispositivos` para el front. */
function mapDispositivo(array $r): array
{
    $texto = static fn(string $k): string => (string) ($r[$k] ?? '');
    $entero = static function (string $k) use ($r): ?int {
        return array_key_exists($k, $r) && $r[$k] !== null ? (int) $r[$k] : null;
    };

    $out = [
        'id'            => (int) $r['id'],
        'uuid'          => $texto('uuid'),
        'nombre'        => $texto('nombre'),
        'mac'           => $texto('mac'),
        'ip'            => $texto('ip'),
        'senal'         => $texto('senal'),
        'firmware'      => $texto('firmware'),
        'habilitado'    => (int) ($r['habilitado'] ?? 0) === 1,
        'enlace'        => (int) ($r['enlace']     ?? 0) === 1,
        'monitoreo'     => (int) ($r['monitoreo']  ?? 0) === 1,
        'latido'        => $texto('latido'),
        'conexion'      => $texto('conexion'),
        'instalacion'   => $texto('instalacion'),
        'modelo'        => $entero('modelo'),
        'agente'        => $entero('agente'),
        'chip'          => $entero('chip'),
        'modelo_nombre' => $texto('modelo_nombre'),
        'agente_nombre' => $texto('agente_nombre'),
    ];

    // Campos que solo trae el GET por id (modales de Consulta y Edicion).
    foreach (['serial', 'identidad', 'llave', 'coordenadas', 'indicadores',
              'monitoreoCorreos', 'fabricacion', 'inicio', 'monitoreoUltimo',
              'monitoreoSiguiente', 'producto_nombre', 'transceptor_nombre',
              'dominio_nombre'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = (string) ($r[$extra] ?? '');
        }
    }
    foreach (['producto', 'transceptor', 'dominio', 'senalesLimite', 'inicios',
              'conexiones', 'latidos', 'adopcion', 'monitoreoIntervalo'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = $entero($extra);
        }
    }
    if (array_key_exists('adoptado', $r)) {
        $out['adoptado'] = (int) ($r['adoptado'] ?? 0) === 1;
    }
    if (array_key_exists('chip_telefono', $r)) {
        $out['chip_nombre'] = chipNombre($r['chip'] ?? null, $r['chip_telefono'] ?? null, $r['chip_serie'] ?? null);
    }

    return $out;
}

/** Etiqueta legible de un chip: telefono, si no serie, si no el id. */
function chipNombre(mixed $id, mixed $telefono, mixed $serie): string
{
    if ($id === null) return '';
    $tel = trim((string) ($telefono ?? ''));
    if ($tel !== '') return $tel;
    $ser = trim((string) ($serie ?? ''));
    if ($ser !== '') return $ser;
    return '#' . (int) $id;
}

/**
 * Catalogos del modulo. Hoy es uno solo: `modelos`, que alimenta el filtro del
 * listado. Los de `agentes`, `productos`, `transceptores` y `chips` se
 * quitaron junto con el formulario de alta — eran 4 queries por cada carga del
 * listado para selects que ya no existen (la edicion solo toca `nombre`).
 * `modelos` es global, no tiene columna `dominio`.
 */
function catalogos(): array
{
    $stmt = db()->query('SELECT id, nombre FROM modelos ORDER BY nombre ASC');

    return [
        'modelos' => array_map(static fn(array $r): array => [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
        ], $stmt->fetchAll()),
    ];
}

/**
 * Valida y normaliza el payload de la edicion. Corta con 422 si algo falla.
 * Devuelve el array de parametros PDO listo para el UPDATE.
 *
 * Sigue validando la ficha entera aunque el modal solo edite `nombre`: el PUT
 * reescribe la fila completa y el front le manda de vuelta los campos que leyo
 * del GET, asi que tienen que revalidarse igual.
 */
function validar(array $in, int $dominio, ?int $idActual): array
{
    $uuid        = trim((string) ($in['uuid']        ?? ''));
    $nombre      = trim((string) ($in['nombre']      ?? ''));
    $mac         = trim((string) ($in['mac']         ?? ''));
    $serial      = trim((string) ($in['serial']      ?? ''));
    $identidad   = trim((string) ($in['identidad']   ?? ''));
    $llave       = trim((string) ($in['llave']       ?? ''));
    $coordenadas = trim((string) ($in['coordenadas'] ?? ''));
    $indicadores = trim((string) ($in['indicadores'] ?? ''));
    $correos     = trim((string) ($in['monitoreoCorreos'] ?? ''));

    if ($uuid === '')              json_error('El identificador (UUID) es obligatorio', 422);
    if (mb_strlen($uuid) > 16)     json_error('El identificador no puede superar 16 caracteres', 422);
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $uuid)) {
        json_error('El identificador solo admite letras, numeros y . _ -', 422);
    }
    if ($nombre === '')            json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 255)  json_error('El nombre no puede superar 255 caracteres', 422);
    foreach ([['MAC', $mac, 50], ['serial', $serial, 50], ['identidad', $identidad, 50],
              ['llave', $llave, 50], ['coordenadas', $coordenadas, 255],
              ['indicadores', $indicadores, 1000]] as [$etiqueta, $valor, $max]) {
        if (mb_strlen($valor) > $max) {
            json_error("El campo $etiqueta no puede superar $max caracteres", 422);
        }
    }
    if (mb_strlen($correos) > 1000) {
        json_error('Los correos de monitoreo no pueden superar 1000 caracteres', 422);
    }
    foreach (preg_split('/[,;\s]+/', $correos, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $correo) {
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            json_error("El correo de monitoreo \"$correo\" no es valido", 422);
        }
    }

    // `uuid` identifica al equipo fisico: unico en toda la tabla, no solo
    // dentro del dominio. La DB no tiene UNIQUE, asi que se valida aca.
    $dup = db()->prepare('SELECT id FROM dispositivos WHERE uuid = :u AND id <> :id LIMIT 1');
    $dup->execute([':u' => $uuid, ':id' => $idActual ?? 0]);
    if ($dup->fetchColumn()) {
        json_error('Ya existe un dispositivo con ese identificador', 409);
    }

    // Catalogos globales: solo se verifica que el id exista.
    $agente      = referencia($in, 'agente',      'agentes');
    $modelo      = referencia($in, 'modelo',      'modelos');
    $producto    = referencia($in, 'producto',    'productos');
    $transceptor = referencia($in, 'transceptor', 'transceptores');

    // El chip, en cambio, tiene dominio propio y tiene que ser del mismo.
    $chip = (int) ($in['chip'] ?? 0);
    if ($chip > 0) {
        $stmt = db()->prepare('SELECT id FROM chips WHERE id = :c AND dominio = :dom LIMIT 1');
        $stmt->execute([':c' => $chip, ':dom' => $dominio]);
        if (!$stmt->fetchColumn()) {
            json_error('El chip no pertenece a este dominio', 422);
        }
    }

    return [
        ':uuid'               => $uuid,
        ':nombre'             => $nombre,
        ':agente'             => $agente,
        ':modelo'             => $modelo,
        ':producto'           => $producto,
        ':transceptor'        => $transceptor,
        ':chip'               => $chip > 0 ? $chip : null,
        ':mac'                => $mac         === '' ? null : $mac,
        ':serial'             => $serial      === '' ? null : $serial,
        ':identidad'          => $identidad   === '' ? null : $identidad,
        ':llave'              => $llave       === '' ? null : $llave,
        ':coordenadas'        => $coordenadas === '' ? null : $coordenadas,
        ':indicadores'        => $indicadores === '' ? null : $indicadores,
        ':monitoreoCorreos'   => $correos     === '' ? null : $correos,
        ':habilitado'         => !empty($in['habilitado']) ? 1 : 0,
        ':monitoreo'          => !empty($in['monitoreo'])  ? 1 : 0,
        ':senalesLimite'      => enteroPositivo($in, 'senalesLimite'),
        ':monitoreoIntervalo' => enteroPositivo($in, 'monitoreoIntervalo'),
        ':fabricacion'        => fechaHora($in, 'fabricacion'),
        ':instalacion'        => fechaHora($in, 'instalacion'),
    ];
}

/** Valida que el id apunte a una fila existente del catalogo. 0/vacio = NULL. */
function referencia(array $in, string $campo, string $tabla): ?int
{
    $id = (int) ($in[$campo] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $stmt = db()->prepare('SELECT id FROM ' . $tabla . ' WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) {
        json_error("El $campo seleccionado no existe", 422);
    }
    return $id;
}

function enteroPositivo(array $in, string $campo): ?int
{
    $valor = $in[$campo] ?? '';
    if ($valor === '' || $valor === null) {
        return null;
    }
    $n = (int) $valor;
    if ($n < 0) {
        json_error("El campo $campo no puede ser negativo", 422);
    }
    return $n;
}

/** Acepta 'YYYY-MM-DDTHH:MM' (input datetime-local), 'YYYY-MM-DD HH:MM:SS' o 'YYYY-MM-DD'. */
function fechaHora(array $in, string $campo): ?string
{
    $valor = trim((string) ($in[$campo] ?? ''));
    // La base legacy tiene fechas cero: se tratan como "sin fecha", no como error.
    if ($valor === '' || str_starts_with($valor, '0000-00-00')) {
        return null;
    }
    $valor = str_replace('T', ' ', $valor);
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})(?: (\d{2}):(\d{2})(?::(\d{2}))?)?$/', $valor, $m)) {
        json_error("La fecha de $campo no es valida", 422);
    }
    if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
        json_error("La fecha de $campo no existe en el calendario", 422);
    }
    return sprintf('%s-%s-%s %s:%s:%s', $m[1], $m[2], $m[3], $m[4] ?? '00', $m[5] ?? '00', $m[6] ?? '00');
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') {
        return [];
    }
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        json_error('Body JSON invalido', 400);
    }
    return $data;
}
