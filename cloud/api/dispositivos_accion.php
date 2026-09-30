<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/lib/sucesos.php';

/**
 * Acciones de negocio sobre dispositivos: `?accion=<nombre>`.
 *
 *   fabricar   Da de alta un equipo nuevo y le crea los canales que su modelo declara.
 *
 *   GET  ?accion=fabricar&catalogo=1     -> catalogos del asistente (modelos con
 *                                           sus canales resueltos, productos en
 *                                           venta, dominios) + valores de fabrica.
 *   GET  ?accion=fabricar&credenciales=1 -> serie / identidad / llave nuevas.
 *   GET  ?accion=fabricar&modelo=&...    -> previsualizacion: que dispositivo y que
 *                                           canales se van a crear, con sus avisos
 *                                           y bloqueos.
 *   POST ?accion=fabricar                -> lo crea, en una transaccion.
 *
 * LAS CREDENCIALES LAS GENERA EL SERVIDOR, no el navegador, y por eso tienen su
 * propio `GET`: el largo, el alfabeto y --sobre todo-- que la serie no este ya
 * tomada como identificador son reglas de la base, no de la pantalla. El boton
 * "Regenerar" del asistente vuelve a pedirlas aca.
 *
 * POR QUE NO ES EL `POST` DE `dispositivos.php`. Fabricar no es "guardar los
 * campos que mandaste": escribe en DOS tablas (`dispositivos` y `canales`),
 * deriva la mitad de lo que guarda (el nombre sale del modelo + el serial, el
 * uuid ES el serial, las credenciales se generan) y la cantidad de canales y su
 * modulo los decide el modelo elegido, no el formulario. El `POST` del ABM
 * sigue existiendo para quien necesite crear una fila a mano con las 35
 * columnas; este endpoint es el alta normal.
 *
 * EL VERBO DISTINGUE PREVISUALIZAR DE EJECUTAR, igual que en
 * `contratos_accion.php`: `GET` devuelve exactamente lo que el `POST` va a
 * escribir --las dos puntas llaman a `fabricarContexto()`-- y por eso el ultimo
 * paso del asistente puede mostrar los canales reales con su modulo resuelto en
 * vez de una frase generica.
 *
 * DE DONDE SALE LA LOGICA. Es el porte de `cDispositivo::crear()` del sistema
 * historico (`reactor_legacy/reactor-api/framework/subframework.php`), que es lo
 * que corre hoy `reactor-admin/dispositivos/nuevo.php`. Se porta lo que hace y
 * se corrigen tres cosas que alla rompen:
 *
 *   - `chip = 0` y `adopcion = 0`. `cDispositivo::nuevo()` los deja en 0, que
 *     desde la migracion `20260814_1400_fk_dispositivos.sql` ya no es un valor
 *     valido: `chips` arranca en el id 279 y `adopciones` en el 55558, asi que
 *     la FK rechaza el INSERT entero ("Cannot add or update a child row").
 *     Aca van NULL, que es lo que significan y lo que ya tienen las 453 filas.
 *   - El modulo del canal se lee en DOS formatos. `modelos`.`canalN` convive en
 *     `MDL=11|TIP=A|...` (el nuevo) y en `11|A|D|RELAY|15|||` (el viejo, en los
 *     modelos 102, 107, 109, 111 y 112). El legacy parte por `|` y despues por
 *     `=`, asi que del formato viejo saca cadena vacia y le deja el canal sin
 *     modulo sin decir nada. Ver `moduloDeParametros()`.
 *   - Un modulo que no existe en `modulos` (el `MDL=0` del modelo 131, o los
 *     `canalN` vacios de los modelos 132 y 133) no se escribe: el canal se crea
 *     igual --el equipo fisico tiene esa entrada-- pero con `modulo` en NULL y
 *     el asistente lo AVISA en el paso de confirmacion. El legacy escribia el 0
 *     y volvia a chocar contra la FK de `canales`.`modulo`.
 */

/* ------------------------------------------------------------------ */
/* Valores de fabrica                                                  */
/* ------------------------------------------------------------------ */

/**
 * Los tres ids que `cDispositivo::nuevo()` tiene hardcodeados y que el alta del
 * back office viejo nunca pregunta. Se declaran con nombre y se VALIDAN contra
 * la base antes de crear: si alguno dejara de existir, el asistente lo dice como
 * bloqueo en vez de tirar un error de foreign key.
 */
const FABRICA_AGENTE      = 2; // `agentes`.`id`       -> Reactor
const FABRICA_DOMINIO     = 2; // `dominios`.`id`      -> Reactor (el stock de fabrica)
const FABRICA_TRANSCEPTOR = 8; // `transceptores`.`id` -> Principal (iot.reactor.com.ar)

/** "Sin fecha" del sistema historico. Lo que `cTiempo::genesis()` devuelve. */
const FABRICA_GENESIS = '1500-01-01 00:00:00';

/** Largo de serial / identidad / llave, y alfabeto: `cCadena::aleatoria(16, '1A')`. */
const CREDENCIAL_LARGO    = 16;
const CREDENCIAL_ALFABETO = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

/** Canales que un modelo puede declarar (`modelos`.`canal1` .. `canal8`). */
const CANALES_MAXIMO = 8;

/** Registros que guarda cada canal nuevo: `cCanal::nuevo()`. */
const CANAL_REGISTROS_LIMITE = 1000;

/** Intentos de generar un identificador libre antes de darse por vencido. */
const UUID_INTENTOS = 20;

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        switch ($accion) {
            case 'fabricar':
                if (isset($_GET['catalogo']))          { json_ok(fabricarCatalogo()); }
                elseif (isset($_GET['credenciales']))  { json_ok(credencialesNuevas()); }
                else                                   { previoFabricar(); }
                break;
            default:
                json_error('Accion desconocida o sin previsualizacion', 400);
        }
    } elseif ($method === 'POST') {
        switch ($accion) {
            case 'fabricar': accionFabricar(); break;
            default:
                json_error('Accion desconocida', 400);
        }
    } else {
        json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al ejecutar la accion: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Catalogo del asistente                                              */
/* ------------------------------------------------------------------ */

/**
 * Lo que el asistente necesita para armar el paso 1, en un solo request.
 *
 * Los modelos vienen con sus canales YA RESUELTOS --numero, modulo, nombre del
 * modulo y componente-- para que elegir un modelo muestre en el acto lo que se
 * va a crear sin volver al servidor. La resolucion la hace la misma funcion que
 * despues usa el `POST`, asi que lo que se ve es lo que se escribe.
 */
function fabricarCatalogo(): array
{
    $modulos = modulosPorId();

    $modelos = db()->query(
        'SELECT id, nombre, alias, canales,
                canal1, canal2, canal3, canal4, canal5, canal6, canal7, canal8
           FROM modelos
          ORDER BY nombre ASC'
    )->fetchAll();

    $modelos = array_map(static function (array $m) use ($modulos): array {
        $canales = canalesDelModelo($m, $modulos);
        return [
            'id'       => (int) $m['id'],
            'nombre'   => trim((string) ($m['nombre'] ?? '')),
            'alias'    => trim((string) ($m['alias'] ?? '')),
            'canales'  => $canales,
            // Cuantos de esos canales van a quedar sin modulo. El front lo usa
            // para marcar el modelo en la lista antes de que el operador llegue
            // al paso de confirmacion.
            'sinModulo' => count(array_filter($canales, static fn(array $c): bool => $c['modulo'] === null)),
        ];
    }, $modelos);

    // Solo los productos EN VENTA (`productos`.`estado` = 2, combo
    // `$xProducto->estado`), que es lo que ofrece el alta del back office viejo:
    // un equipo no se fabrica contra un producto discontinuado ni contra uno
    // que todavia esta en desarrollo.
    $productos = db()->query(
        "SELECT id, nombre FROM productos WHERE estado = '2' ORDER BY nombre ASC"
    )->fetchAll();

    $dominios = db()->query('SELECT id, nombre FROM dominios ORDER BY nombre ASC')->fetchAll();

    return [
        'modelos'   => $modelos,
        'productos' => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'nombre' => trim((string) ($r['nombre'] ?? '')),
        ], $productos),
        'dominios'  => array_map(static fn(array $r): array => [
            'id' => (int) $r['id'], 'nombre' => trim((string) ($r['nombre'] ?? '')),
        ], $dominios),
        'fabrica'   => fabricaValores(),
    ];
}

/**
 * Los valores fijos del alta, con su nombre resuelto.
 *
 * Viajan al front para MOSTRARLOS en la confirmacion. Un dispositivo nace con
 * agente y transceptor puestos y el asistente no los pregunta; no decir cuales
 * son deja al operador creyendo que quedaron vacios.
 */
function fabricaValores(): array
{
    $nombre = static function (string $tabla, int $id): string {
        $stmt = db()->prepare('SELECT nombre FROM ' . $tabla . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $valor = $stmt->fetchColumn();
        return $valor === false ? '' : trim((string) $valor);
    };

    return [
        'agente'             => ['id' => FABRICA_AGENTE,      'nombre' => $nombre('agentes', FABRICA_AGENTE)],
        'transceptor'        => ['id' => FABRICA_TRANSCEPTOR, 'nombre' => $nombre('transceptores', FABRICA_TRANSCEPTOR)],
        'dominio'            => ['id' => FABRICA_DOMINIO,     'nombre' => $nombre('dominios', FABRICA_DOMINIO)],
        'credencialLargo'    => CREDENCIAL_LARGO,
        'registrosLimite'    => CANAL_REGISTROS_LIMITE,
    ];
}

/* ------------------------------------------------------------------ */
/* fabricar                                                            */
/* ------------------------------------------------------------------ */

/** Previsualizacion: el dispositivo y los canales que saldrian de crear ahora. */
function previoFabricar(): void
{
    $ctx = fabricarContexto([
        'modelo'    => $_GET['modelo']    ?? null,
        'producto'  => $_GET['producto']  ?? null,
        'dominio'   => $_GET['dominio']   ?? null,
        'serial'    => $_GET['serial']    ?? null,
        'identidad' => $_GET['identidad'] ?? null,
        'llave'     => $_GET['llave']     ?? null,
    ]);

    json_ok(fabricarSalida($ctx));
}

/** Crea el dispositivo y sus canales. Todo o nada. */
function accionFabricar(): void
{
    $ctx = fabricarContexto(readJson());

    if ($ctx['bloqueos']) {
        json_error($ctx['bloqueos'][0], 422);
    }

    $disp    = $ctx['dispositivo'];
    $canales = $ctx['canales'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // El identificador se vuelve a chequear ACA, no solo en el contexto: el
        // `GET` de previsualizacion pudo correr hace un minuto y entre medio
        // otro operador puede haber fabricado con el mismo. La tabla no tiene
        // UNIQUE, asi que el control es este.
        if (uuidTomado($disp['uuid'])) {
            $pdo->rollBack();
            json_error('Ya existe un dispositivo con el identificador ' . $disp['uuid'], 409);
        }

        $columnas = array_keys($disp);
        $stmt = $pdo->prepare(
            'INSERT INTO dispositivos (' . implode(', ', $columnas) . ')
             VALUES (:' . implode(', :', $columnas) . ')'
        );
        $stmt->execute(parametrosPdo($disp));

        $id = (int) $pdo->lastInsertId();

        $insCanal = $pdo->prepare(
            'INSERT INTO canales
                    (uuid, dispositivo, nombre, canal, modulo, estado, usos, usoDiario,
                     usoMensual, usoAcumulado, usado, registrosGuardar, registrosLimite,
                     habilitado, configuracion, opciones, reacciones)
             VALUES (:uuid, :dispositivo, :nombre, :canal, :modulo, :estado, 0, 0,
                     0, 0, :usado, 0, :registrosLimite,
                     1, :configuracion, :opciones, :reacciones)'
        );

        foreach ($canales as $c) {
            $insCanal->execute([
                ':uuid'            => $c['uuid'],
                ':dispositivo'     => $id,
                ':nombre'          => $c['nombre'],
                ':canal'           => $c['canal'],
                ':modulo'          => $c['modulo'],
                ':estado'          => '0',
                ':usado'           => FABRICA_GENESIS,
                ':registrosLimite' => CANAL_REGISTROS_LIMITE,
                ':configuracion'   => '',
                ':opciones'        => '',
                ':reacciones'      => '',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    registrarSuceso(
        $pdo,
        'dispositivos',
        'info',
        sprintf(
            'Dispositivo fabricado #%d %s (identificador %s) con %d canal(es).',
            $id,
            $disp['nombre'],
            $disp['uuid'],
            count($canales)
        )
    );

    json_ok([
        'id'      => $id,
        'uuid'    => $disp['uuid'],
        'nombre'  => $disp['nombre'],
        'canales' => count($canales),
    ]);
}

/**
 * Todo lo que la accion necesita saber, resuelto contra la base.
 *
 * La comparten `GET` y `POST` para que la pantalla de confirmacion muestre
 * exactamente lo que se va a escribir. Devuelve la fila de `dispositivos` lista
 * para el INSERT, la de cada canal, y las dos listas que el front dibuja:
 * `bloqueos` (impiden crear y esconden el boton) y `avisos` (se muestran y
 * dejan seguir).
 */
function fabricarContexto(array $in): array
{
    $bloqueos = [];
    $avisos   = [];

    /* ---- modelo ---- */
    $modeloId = (int) ($in['modelo'] ?? 0);
    $modelo   = null;
    if ($modeloId <= 0) {
        $bloqueos[] = 'Elegi un modelo.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, nombre, alias, canales,
                    canal1, canal2, canal3, canal4, canal5, canal6, canal7, canal8
               FROM modelos WHERE id = :id LIMIT 1'
        );
        $stmt->execute([':id' => $modeloId]);
        $modelo = $stmt->fetch() ?: null;
        if ($modelo === null) $bloqueos[] = 'El modelo elegido no existe.';
    }

    /* ---- producto ---- */
    $productoId = (int) ($in['producto'] ?? 0);
    $producto   = null;
    if ($productoId <= 0) {
        $bloqueos[] = 'Elegi un producto.';
    } else {
        $stmt = db()->prepare('SELECT id, nombre, estado FROM productos WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $productoId]);
        $producto = $stmt->fetch() ?: null;
        if ($producto === null) {
            $bloqueos[] = 'El producto elegido no existe.';
        } elseif ((string) $producto['estado'] !== '2') {
            // No bloquea: el catalogo del asistente ya ofrece solo los que
            // estan en venta, asi que llegar aca con otro significa que alguien
            // lo cambio de estado entre medio o que se pidio a proposito.
            $avisos[] = 'El producto "' . trim((string) $producto['nombre']) . '" no esta en venta.';
        }
    }

    /* ---- dominio ---- */
    $dominioId = (int) ($in['dominio'] ?? 0);
    if ($dominioId <= 0) $dominioId = FABRICA_DOMINIO;
    $stmt = db()->prepare('SELECT id, nombre FROM dominios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $dominioId]);
    $dominio = $stmt->fetch() ?: null;
    if ($dominio === null) $bloqueos[] = 'El dominio elegido no existe.';

    /* ---- los tres fijos ---- */
    $fabrica = [];
    foreach ([
        ['agentes',       FABRICA_AGENTE,      'agente',      'El agente de fabrica'],
        ['transceptores', FABRICA_TRANSCEPTOR, 'transceptor', 'El transceptor de fabrica'],
    ] as [$tabla, $id, $clave, $rotulo]) {
        $stmt = db()->prepare('SELECT id, nombre FROM ' . $tabla . ' WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $fila = $stmt->fetch() ?: null;
        if ($fila === null) {
            $bloqueos[] = $rotulo . ' (#' . $id . ') no existe en la base.';
            $fabrica[$clave] = ['id' => $id, 'nombre' => ''];
        } else {
            $fabrica[$clave] = ['id' => (int) $fila['id'], 'nombre' => trim((string) $fila['nombre'])];
        }
    }

    /* ---- credenciales ---- */
    // La serie que no vino se genera LIBRE: tambien va a ser el identificador.
    $serial    = credencial($in, 'serial',    $bloqueos, 'La serie', 'serialLibre');
    $identidad = credencial($in, 'identidad', $bloqueos, 'La identidad');
    $llave     = credencial($in, 'llave',     $bloqueos, 'La llave');

    // `uuid` ES el serial: lo hace `cDispositivo::crear()` y es lo que el
    // firmware reporta. `dispositivos`.`uuid` es varchar(16) y la credencial
    // mide 16, asi que entra justo; un serial escrito a mano mas largo no.
    $uuid = $serial;
    if ($uuid !== '' && mb_strlen($uuid) > 16) {
        $bloqueos[] = 'La serie no puede superar 16 caracteres: tambien es el identificador del equipo.';
    }
    if ($uuid !== '' && uuidTomado($uuid)) {
        $bloqueos[] = 'Ya existe un dispositivo con el identificador ' . $uuid . '.';
    }

    /* ---- canales ---- */
    $modulos = modulosPorId();
    $canales = $modelo === null ? [] : canalesDelModelo($modelo, $modulos);

    if ($modelo !== null && !$canales) {
        $avisos[] = 'El modelo no declara canales: el dispositivo se crea sin ninguno.';
    }

    $sinModulo = array_values(array_filter($canales, static fn(array $c): bool => $c['modulo'] === null));
    if ($sinModulo) {
        $avisos[] = count($sinModulo) === 1
            ? 'El canal ' . $sinModulo[0]['canal'] . ' del modelo no tiene un modulo valido: se crea sin modulo y hay que asignarselo despues.'
            : 'Hay ' . count($sinModulo) . ' canales sin modulo valido en el modelo: se crean sin modulo y hay que asignarselos despues.';
    }

    /* ---- la fila lista para el INSERT ---- */
    $modeloNombre = $modelo === null ? '' : trim((string) ($modelo['nombre'] ?? ''));
    $nombre       = $modeloNombre === '' ? $serial : $modeloNombre . ' | ' . $serial;

    $dispositivo = [
        'uuid'        => $uuid,
        'agente'      => $fabrica['agente']['id'],
        'dominio'     => $dominioId,
        'nombre'      => $nombre,
        'transceptor' => $fabrica['transceptor']['id'],
        'modelo'      => $modeloId ?: null,
        'producto'    => $productoId ?: null,
        'firmware'    => null,
        'mac'         => null,
        'ip'          => null,
        'senal'       => null,
        'serial'      => $serial,
        'identidad'   => $identidad,
        'llave'       => $llave,
        // NULL y no 0: ver la cabecera del archivo. Un equipo recien fabricado
        // no tiene chip asignado ni adopcion, y 0 no es una fila de esas tablas.
        'chip'               => null,
        'habilitado'         => 1,
        'senalesLimite'      => 0,
        'fabricacion'        => date('Y-m-d H:i:s'),
        'adoptado'           => 1,
        'adopcion'           => null,
        'instalacion'        => FABRICA_GENESIS,
        'inicio'             => FABRICA_GENESIS,
        'conexion'           => FABRICA_GENESIS,
        'latido'             => FABRICA_GENESIS,
        'inicios'            => 0,
        'conexiones'         => 0,
        'latidos'            => 0,
        'enlace'             => 0,
        'monitoreo'          => 0,
        'monitoreoIntervalo' => 0,
        'monitoreoCorreos'   => null,
        'monitoreoUltimo'    => FABRICA_GENESIS,
        'monitoreoSiguiente' => FABRICA_GENESIS,
        'coordenadas'        => null,
        'indicadores'        => null,
    ];

    // El nombre de cada canal sale del nombre del dispositivo, igual que en
    // `cDispositivo::crear()`: `CE-D4CO | <serial> | Canal 1`.
    $usados = [];
    foreach ($canales as $i => $c) {
        $canales[$i]['nombre'] = $nombre . ' | Canal ' . $c['canal'];
        $canales[$i]['uuid']   = uuidCanalLibre($usados);
    }

    return [
        'modelo'      => $modelo,
        'producto'    => $producto,
        'dominio'     => $dominio,
        'fabrica'     => $fabrica,
        'dispositivo' => $dispositivo,
        'canales'     => $canales,
        'bloqueos'    => $bloqueos,
        'avisos'      => $avisos,
    ];
}

/** El contexto, en la forma que consume el asistente. */
function fabricarSalida(array $ctx): array
{
    $d = $ctx['dispositivo'];

    return [
        'modelo' => $ctx['modelo'] === null ? null : [
            'id'     => (int) $ctx['modelo']['id'],
            'nombre' => trim((string) ($ctx['modelo']['nombre'] ?? '')),
            'alias'  => trim((string) ($ctx['modelo']['alias'] ?? '')),
        ],
        'producto' => $ctx['producto'] === null ? null : [
            'id'     => (int) $ctx['producto']['id'],
            'nombre' => trim((string) ($ctx['producto']['nombre'] ?? '')),
        ],
        'dominio' => $ctx['dominio'] === null ? null : [
            'id'     => (int) $ctx['dominio']['id'],
            'nombre' => trim((string) ($ctx['dominio']['nombre'] ?? '')),
        ],
        'fabrica'     => $ctx['fabrica'],
        'dispositivo' => [
            'uuid'        => $d['uuid'],
            'nombre'      => $d['nombre'],
            'serial'      => $d['serial'],
            'identidad'   => $d['identidad'],
            'llave'       => $d['llave'],
            'fabricacion' => $d['fabricacion'],
            'habilitado'  => true,
        ],
        'canales'  => array_map(static fn(array $c): array => [
            'canal'          => $c['canal'],
            'modulo'         => $c['modulo'],
            'modulo_nombre'  => $c['modulo_nombre'],
            'componente'     => $c['componente'],
            'nombre'         => $c['nombre'],
        ], $ctx['canales']),
        'bloqueos' => $ctx['bloqueos'],
        'avisos'   => $ctx['avisos'],
    ];
}

/* ------------------------------------------------------------------ */
/* Canales del modelo                                                  */
/* ------------------------------------------------------------------ */

/**
 * Los canales que declara un modelo, con su modulo ya resuelto.
 *
 * `modelos`.`canales` dice CUANTOS y `canal1` .. `canal8` dicen COMO es cada
 * uno. El numero se acota a las columnas que existen (8): un modelo con
 * `canales` mas alto pediria un `canal9` que no hay.
 */
function canalesDelModelo(array $modelo, array $modulos): array
{
    $cantidad = (int) ($modelo['canales'] ?? 0);
    if ($cantidad < 0)              $cantidad = 0;
    if ($cantidad > CANALES_MAXIMO) $cantidad = CANALES_MAXIMO;

    $salida = [];
    for ($n = 1; $n <= $cantidad; $n++) {
        $parametros = (string) ($modelo['canal' . $n] ?? '');
        $moduloId   = moduloDeParametros($parametros);

        // Un id que no esta en `modulos` no se escribe: `canales`.`modulo` tiene
        // FK a esa tabla y guardarlo rompe el INSERT entero.
        if ($moduloId !== null && !isset($modulos[$moduloId])) $moduloId = null;

        $salida[] = [
            'canal'         => $n,
            'modulo'        => $moduloId,
            'modulo_nombre' => $moduloId === null ? '' : $modulos[$moduloId]['nombre'],
            'componente'    => $moduloId === null ? '' : $modulos[$moduloId]['componente'],
            'nombre'        => '',   // lo completa fabricarContexto() con el del dispositivo
            'uuid'          => '',   // idem
        ];
    }

    return $salida;
}

/**
 * El id de modulo que esconde un `modelos`.`canalN`, en cualquiera de los dos
 * formatos que conviven en la tabla:
 *
 *   MDL=11|TIP=A|MOD=D|OPE=D|CMP=RELAY 10A|PN1=05|...   (nuevo)
 *   11|A|D|RELAY|15|||                                   (viejo)
 *
 * El legacy hace `extraer(extraer($p,'|',0), '=', 1)`, que del formato viejo
 * devuelve cadena vacia porque ahi el primer segmento no tiene `=`. Aca se
 * contempla: se toma el primer segmento y, si trae `=`, el lado derecho.
 *
 * Devuelve NULL para "sin modulo" --el `MDL=0`, el `0|||...` y el `canalN`
 * vacio--, que es lo que significan y lo unico que la FK acepta.
 */
function moduloDeParametros(string $parametros): ?int
{
    $parametros = trim($parametros);
    if ($parametros === '') return null;

    $primero = explode('|', $parametros)[0];
    if (str_contains($primero, '=')) {
        $primero = substr($primero, strpos($primero, '=') + 1);
    }

    $primero = trim($primero);
    if ($primero === '' || !ctype_digit($primero)) return null;

    $id = (int) $primero;

    return $id > 0 ? $id : null;
}

/** `modulos` entera, indexada por id. Son 21 filas: va completa y una sola vez. */
function modulosPorId(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (db()->query('SELECT id, nombre, componente FROM modulos') as $r) {
        $cache[(int) $r['id']] = [
            'nombre'     => trim((string) ($r['nombre'] ?? '')),
            'componente' => trim((string) ($r['componente'] ?? '')),
        ];
    }

    return $cache;
}

/* ------------------------------------------------------------------ */
/* Credenciales                                                        */
/* ------------------------------------------------------------------ */

/**
 * Serie / identidad / llave: la que vino del formulario o una nueva.
 *
 * El asistente las genera y las manda, asi que el caso normal es validarlas;
 * generarlas aca es el respaldo para quien llame al endpoint sin pasarlas.
 */
function credencial(array $in, string $campo, array &$bloqueos, string $rotulo, ?string $generador = null): string
{
    $valor = trim((string) ($in[$campo] ?? ''));
    if ($valor === '') {
        return $generador === null ? cadenaAleatoria(CREDENCIAL_LARGO) : $generador();
    }

    if (mb_strlen($valor) > 50) {
        $bloqueos[] = $rotulo . ' no puede superar 50 caracteres.';
    }
    if (!preg_match('/^[A-Za-z0-9._-]+$/', $valor)) {
        $bloqueos[] = $rotulo . ' solo admite letras, numeros y . _ -';
    }

    return $valor;
}

/**
 * Un juego nuevo de credenciales para el asistente.
 *
 * La SERIE sale garantizada libre porque tambien va a ser el identificador del
 * equipo; identidad y llave no identifican nada y no se chequean, igual que en
 * el sistema historico.
 */
function credencialesNuevas(): array
{
    return [
        'serial'    => serialLibre(),
        'identidad' => cadenaAleatoria(CREDENCIAL_LARGO),
        'llave'     => cadenaAleatoria(CREDENCIAL_LARGO),
    ];
}

/** Una serie que todavia no este usada como `dispositivos`.`uuid`. */
function serialLibre(): string
{
    for ($i = 0; $i < UUID_INTENTOS; $i++) {
        $serial = cadenaAleatoria(CREDENCIAL_LARGO);
        if (!uuidTomado($serial)) return $serial;
    }

    throw new RuntimeException('No se pudo generar una serie libre');
}

/** Cadena aleatoria de mayusculas: el `cCadena::aleatoria($largo, '1A')` del legacy. */
function cadenaAleatoria(int $largo): string
{
    $salida = '';
    $tope   = strlen(CREDENCIAL_ALFABETO) - 1;
    for ($i = 0; $i < $largo; $i++) {
        $salida .= CREDENCIAL_ALFABETO[random_int(0, $tope)];
    }
    return $salida;
}

/** true si ya hay un dispositivo con ese identificador. `uuid` no tiene UNIQUE. */
function uuidTomado(string $uuid): bool
{
    $stmt = db()->prepare('SELECT 1 FROM dispositivos WHERE uuid = :u LIMIT 1');
    $stmt->execute([':u' => $uuid]);
    return (bool) $stmt->fetchColumn();
}

/**
 * Un `canales`.`uuid` que no este usado, ni en la base ni entre los canales que
 * esta misma alta va a crear (`$usados` acumula los ya repartidos).
 */
function uuidCanalLibre(array &$usados): string
{
    $stmt = db()->prepare('SELECT 1 FROM canales WHERE uuid = :u LIMIT 1');

    for ($i = 0; $i < UUID_INTENTOS; $i++) {
        $uuid = cadenaAleatoria(CREDENCIAL_LARGO);
        if (isset($usados[$uuid])) continue;
        $stmt->execute([':u' => $uuid]);
        if ($stmt->fetchColumn()) continue;
        $usados[$uuid] = true;
        return $uuid;
    }

    throw new RuntimeException('No se pudo generar un identificador libre para los canales');
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** `columna => valor` a `:columna => valor` para PDO. */
function parametrosPdo(array $datos): array
{
    $out = [];
    foreach ($datos as $columna => $valor) {
        $out[':' . $columna] = $valor;
    }
    return $out;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
