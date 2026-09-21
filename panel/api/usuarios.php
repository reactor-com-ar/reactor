<?php

declare(strict_types=1);

/**
 * Modulo Usuarios: ABM de los PERFILES del dominio (esquema en db/schema.sql).
 *
 *   GET    api/usuarios.php            -> listado + resumen
 *   GET    api/usuarios.php?id=N       -> un perfil (con los datos de su cuenta,
 *                                         sus paneles y el catalogo del dominio)
 *   PUT    api/usuarios.php            -> {id, tipo?, habilitado?, paneles?,
 *                                          operacion?, invitacion?, facturacion?}
 *   DELETE api/usuarios.php?id=N       -> elimina el PERFIL (el acceso), no la cuenta
 *
 * LA FILA ES UN PERFIL, NO UN USUARIO, y esa es la decision de fondo. Lo que el
 * modulo lista es quien tiene acceso a este dominio, y eso vive en `perfiles`:
 * `usuarios.dominio` es el dominio ACTIVO de la cuenta —el ultimo que la persona
 * uso, en cualquiera de los sistemas que comparten la tabla— y no la lista de
 * dominios a los que puede entrar. Filtrar `usuarios` por `dominio` escondia a
 * todo el que estuviera trabajando en otro lado: medido en dev, el dominio
 * `Patio San Ignacio` tiene 152 perfiles y solo 15 cuentas con ese dominio
 * activo (137 accesos invisibles); en el conjunto de la base son 2.227 perfiles.
 *
 * POR LO MISMO EL ESTADO DE LA FILA ES `perfiles.habilitado`, no
 * `usuarios.habilitado`: son dos cosas distintas y en los datos estan
 * desalineadas (154 perfiles deshabilitados de cuentas habilitadas y 18 al
 * reves). El de la cuenta viaja igual como dato aparte (`usuario_habilitado`),
 * porque una cuenta deshabilitada no entra ni con el perfil habilitado.
 *
 * LAS DOS COLUMNAS SON `tinyint(1) NOT NULL DEFAULT 0` y admiten UN SOLO par de
 * valores: 1 = habilitado, 0 = deshabilitado. Nada de 'S'/'N', NULL ni cadena
 * vacia -- ver 20260905_2200_habilitado_tinyint_0_1.sql.
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()). Ningun
 * query corre sin ese filtro, ni siquiera el lookup por id.
 *
 * CREDENCIALES: `contrasena` y `clave` nunca se devuelven. La contrasena se
 * guarda con el cifrado historico (reactor_legacy_encriptar), que es
 * reversible con la clave global — exponerla seria filtrarla en claro.
 *
 * NO HAY ALTA. El alta es una invitacion (POST api/invitaciones.php: la cuenta y
 * el perfil los crea el propio invitado al aceptar).
 *
 * LA EDICION NO TOCA NINGUN DATO DE LA PERSONA: `tipo`, `habilitado`, los tres
 * permisos (`operacion` / `invitacion` / `facturacion`) y los paneles. Los datos
 * personales (nombre, correo, celular, contrasena) son de la CUENTA y los
 * administra su duenio; el `nombre` y el `uuid` del perfil los escribe quien lo
 * crea (la invitacion) y no se reeditan. Por eso el PUT no reescribe la fila
 * entera: no hay ningun otro campo que el guardado pueda pisar.
 *
 * TODOS LOS CAMPOS SON OPCIONALES E INDEPENDIENTES. `toggleUsuario()` del front
 * sigue mandando solo `{id, habilitado}` desde el menu de la fila, y el editor
 * manda el resto. Lo que no viene, no se toca -- por eso se distingue con
 * `array_key_exists` y no con `isset`: `null` tiene que llegar como "vino vacio"
 * y no como "no vino".
 *
 * LOS TRES PERMISOS SOLO LOS OTORGA QUIEN YA LOS TIENE, y es la unica regla de
 * este endpoint que mira al que edita en vez de a la fila editada. Sin ella
 * ninguno significaria nada: cualquier administrador se daria a si mismo el que
 * le falta en dos clicks (o a un tercero, que es lo mismo con un paso mas).
 * Valia solo para `facturacion` hasta el 07/09/2026 -- ver el detalle en
 * handleUpdate(). Ver requirePermisoPanel() / panelPuede() en lib/acceso.php.
 *
 * PANELES DEL PERFIL (`perfiles_paneles`). Dice a que paneles del dominio puede
 * entrar el perfil desde `app`. **El permiso es explicito: sin filas, el perfil
 * no ve NINGUN panel** -- no hay fallback a "todos". Lo hubo mientras la tabla
 * estaba vacia, y la siembra de 20260906_1700 (3.598 filas, los 2.225 perfiles
 * con dominio) lo volvio innecesario. Mismo criterio que cloud/api/profiles.php.
 *
 * `perfiles.panel` (SINGULAR) ES OTRA COSA, y el editor la toca de rebote: es la
 * MEMORIA del ultimo panel que ese perfil abrio en `app`, y tambien el que `app`
 * le reabre al conectarse. La escribe `app/api/paneles.php` al cambiar de panel,
 * siempre con uno ya permitido. Revocar el permiso sobre el panel recordado la
 * dejaria apuntando a algo que el perfil no puede abrir, asi que el guardado la
 * limpia -- ver olvidarPanelSinPermiso().
 *
 * `perfiles.paneles` -- el varchar(100) del sistema historico con el formato
 * `(1177)(1231)` -- YA NO EXISTE: la elimino
 * `20260906_1800_perfiles_sin_permisos_ni_paneles.sql` junto con
 * `perfiles.permisos`. Nunca se leyo desde aca y no se perdio nada: de sus
 * 2.375 pares, 2.368 apuntaban a un panel inexistente. La fuente es la tabla
 * puente y nada mas.
 */

require __DIR__ . '/bootstrap.php';

/**
 * Columnas ordenables -> expresion SQL. Es un mapa y no una lista porque la
 * fila sale de tres tablas: el valor va interpolado en el ORDER BY, asi que
 * nunca puede salir del request.
 */
const ORDEN_VALIDO = [
    'id'         => 'p.id',
    'usuario'    => 'u.usuario',
    'nombre'     => 'u.nombre',
    'correo'     => 'u.correo',
    'registrado' => 'u.registrado',
    'ingresado'  => 'u.ingresado',
];

const MAX_LIMITE = 1000;

// HABILITADO (1) y DESHABILITADO (0) viven en lib/habilitado.php, que llega
// por el bootstrap. Son los DOS unicos valores de la columna en toda la base.

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
            break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar perfiles: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q      = trim((string) ($_GET['q']      ?? ''));
    $codigo = (int)         ($_GET['codigo'] ?? 0);
    $estado = (string)      ($_GET['estado'] ?? 'todos');
    $limite = (int)         ($_GET['limite'] ?? 100);
    $orden  = (string)      ($_GET['orden']  ?? 'id');
    $dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)          $limite = 100;
    if ($limite > MAX_LIMITE)  $limite = MAX_LIMITE;
    if (!array_key_exists($orden, ORDEN_VALIDO)) $orden = 'id';

    $where  = ['p.dominio = :dom'];
    $params = [':dom' => $dominio];

    if ($codigo > 0) {
        $where[]        = 'p.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($estado === 'habilitados') {
        $where[]         = 'p.habilitado = :hab';
        $params[':hab']  = HABILITADO;
    } elseif ($estado === 'deshabilitados') {
        // Sin COALESCE: la columna es NOT NULL, asi que "no habilitado" es
        // exactamente 0 y no hace falta cubrir el NULL.
        $where[]         = 'p.habilitado = :hab';
        $params[':hab']  = DESHABILITADO;
    }
    // Busqueda por texto libre: metodo unico de `lib/busqueda.php`. Los
    // terminos se cruzan con Y y cada uno se busca en todas las columnas con O,
    // sin acentos y sin distinguir mayusculas. La consulta vacia no agrega nada.
    [$condiciones, $busq] = busquedaWhere(
        $q,
        ['u.usuario', 'u.nombre', 'u.correo', 'u.celular', 'p.nombre']
    );
    $where  = array_merge($where, $condiciones);
    $params = array_merge($params, $busq);

    // LEFT JOIN y no INNER: hay perfiles sin cuenta (`perfiles.usuario` NULL o
    // con el centinela 0). Con INNER se esconderian, y esconder una fila que
    // ademas no le sirve a nadie es justamente lo que impide limpiarla.
    $sql = 'SELECT p.id, p.uuid, p.nombre AS perfil_nombre, p.habilitado,
                   p.tipo,
                   u.id AS usuario_id, u.uuid AS usuario_uuid, u.nombre, u.usuario,
                   u.correo, u.celular, u.habilitado AS usuario_habilitado,
                   u.registrado, u.ingresado
            FROM perfiles p
            LEFT JOIN usuarios u ON u.id = p.usuario
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY ' . ORDEN_VALIDO[$orden] . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $filas = array_map('mapPerfil', $stmt->fetchAll());

    // Resumen sobre el dominio completo, no sobre la pagina devuelta.
    $res = db()->prepare(
        'SELECT COUNT(*) AS total,
                SUM(CASE WHEN habilitado = :hab THEN 1 ELSE 0 END) AS habilitados
         FROM perfiles WHERE dominio = :dom'
    );
    $res->execute([':dom' => $dominio, ':hab' => HABILITADO]);
    $r = $res->fetch() ?: ['total' => 0, 'habilitados' => 0];

    json_ok([
        'perfiles' => $filas,
        'resumen'  => [
            'total'          => (int) $r['total'],
            'habilitados'    => (int) $r['habilitados'],
            'deshabilitados' => (int) $r['total'] - (int) $r['habilitados'],
            'mostrados'      => count($filas),
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
        'SELECT p.id, p.uuid, p.nombre AS perfil_nombre, p.habilitado,
                p.tipo, p.dominio, p.panel,
                p.operacion, p.invitacion, p.facturacion,
                u.id AS usuario_id, u.uuid AS usuario_uuid, u.nombre, u.usuario,
                u.correo, u.celular, u.habilitado AS usuario_habilitado,
                u.autenticacion, u.registrado, u.ingresado,
                d.nombre AS dominio_nombre,
                g.nombre AS registrante_nombre
         FROM perfiles p
         LEFT JOIN usuarios u ON u.id = p.usuario
         LEFT JOIN dominios d ON d.id = p.dominio
         LEFT JOIN usuarios g ON g.id = u.registrante
         WHERE p.id = :id AND p.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Perfil no encontrado en este dominio', 404);
    }

    $perfil            = mapPerfil($row);
    $perfil['paneles'] = panelesDelPerfil($id);
    // `perfiles.panel` es la MEMORIA del ultimo panel que ese perfil abrio en
    // `app`, y tambien el que `app` reabre al conectarse con el. Viaja para que
    // el editor pueda marcarlo en la lista: quitarle el permiso sobre ese panel
    // borra la memoria, y eso hay que poder verlo antes de guardar.
    $perfil['panel'] = (int) ($row['panel'] ?? 0);

    // El catalogo va con la ficha y no en el listado: son los paneles de UN
    // dominio (el de la sesion) y el mas grande de la base tiene 7, asi que
    // mandarlo en cada fila del listado seria repetirlo hasta 1.000 veces para
    // una pantalla que ni lo usa.
    json_ok([
        'perfil'    => $perfil,
        'catalogos' => ['paneles' => array_values(catalogoPaneles($dominio))],
    ]);
}

/* ------------------------------------------------------------------ */
/* Habilitar / Deshabilitar / Baja                                     */
/* ------------------------------------------------------------------ */

/**
 * Unico UPDATE del modulo. Toca `tipo`, `habilitado`, los tres permisos y
 * `perfiles_paneles`, y nada mas — ver la cabecera: el resto de la fila no es de
 * esta pantalla.
 *
 * TODOS LOS CAMPOS SON OPCIONALES. El menu de la fila manda solo
 * `{id, habilitado}` (el toggle) y el editor manda el resto; lo que no viene se
 * relee de la fila y se reescribe igual. Se detecta con `array_key_exists` y no
 * con `isset` para que un `null` cuente como "vino vacio" y no como "no vino".
 */
function handleUpdate(): void
{
    $dominio = requireDominioId();
    $in      = readJson();
    $id      = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $tocaEstado  = array_key_exists('habilitado', $in);
    $tocaTipo    = array_key_exists('tipo', $in);
    $tocaPaneles = array_key_exists('paneles', $in);
    $tocaPermiso = [];
    foreach (perfilPermisosClaves() as $permiso) {
        $tocaPermiso[$permiso] = array_key_exists($permiso, $in);
    }
    if (!$tocaEstado && !$tocaTipo && !$tocaPaneles && !in_array(true, $tocaPermiso, true)) {
        json_error('No hay nada para guardar', 422);
    }

    $fila = perfilDelDominio($id, $dominio);

    // Se escribe el ENTERO 1 o 0, nunca un booleano de PHP: PDO bindea `false`
    // como cadena vacia y en la columna vieja (varchar) eso dejaba un tercer
    // valor que ninguna pantalla sabia leer. Hoy la columna es tinyint NOT NULL
    // y el motor lo rechazaria, pero el binding correcto es el entero.
    $habilitado = $tocaEstado
        ? (!empty($in['habilitado']) ? HABILITADO : DESHABILITADO)
        : (int) $fila['habilitado'];

    // Deshabilitar el perfil con el que se esta trabajando cierra el panel en el
    // request siguiente: el gate de acceso lo resuelve contra la base, no contra
    // el token (lib/acceso.php). Es lo que ademas garantiza que el dominio nunca
    // se quede sin ningun acceso.
    //
    // EL CORTE ES SOBRE EL CAMBIO DE ESTADO, NO SOBRE EL PERFIL ENTERO: `tipo` y
    // los paneles no gatean el panel (el gate solo mira `habilitado`), asi que
    // uno puede editar los suyos. Guardar el propio perfil con el estado tal
    // como esta tampoco es un cambio y pasa — lo que se rechaza es apagarlo.
    if ($habilitado !== (int) $fila['habilitado'] && esPerfilDeLaSesion($id)) {
        json_error('No podes cambiar el estado de tu propio perfil', 409);
    }

    $tipo    = $tocaTipo    ? validarTipo($in['tipo'])                : (string) $fila['tipo'];
    $paneles = $tocaPaneles ? validarPaneles($in['paneles'], $dominio) : null;

    // LOS PERMISOS. Cada uno se resuelve por separado: el que no vino se relee
    // de la fila y se reescribe igual, como el resto de los campos.
    //
    // NINGUNO DE LOS TRES SE OTORGA NI SE QUITA SIN TENERLO. Es la unica regla
    // del endpoint que mira al que edita en vez de a la fila editada: sin ella,
    // un administrador sin un permiso se lo daria a si mismo (o a un tercero,
    // que es lo mismo con un paso mas) y el permiso no significaria nada.
    //
    // Hasta el 07/09/2026 la regla era solo de `facturacion` — los otros dos
    // quedaban libres porque son de `app` y quien administra el dominio reparte
    // el acceso a la app aunque el no la use. El argumento no se sostuvo: repartir
    // lo que uno no tiene es exactamente la escalada que el corte evita, y que el
    // permiso se ejerza en otra pantalla no cambia quien lo esta regalando.
    //
    // EL CORTE ES SOBRE EL CAMBIO, no sobre el guardado, igual que el del estado
    // del perfil propio: guardar la ficha con los permisos tal como estan pasa
    // siempre, asi quien no tiene ninguno igual puede editar `tipo` y los paneles.
    // El front ni siquiera los manda —no dibuja el switch que no puede otorgar—,
    // asi que llegar aca con un cambio prohibido es un payload armado a mano.
    $permisos = [];
    foreach (perfilPermisosClaves() as $permiso) {
        $previo = valorHabilitado($fila[$permiso] ?? 0);
        $nuevo  = $tocaPermiso[$permiso] ? valorHabilitado($in[$permiso]) : $previo;

        if ($nuevo !== $previo && !panelPuede($permiso)) {
            $etiqueta = PERFIL_PERMISOS[$permiso]['etiqueta'] ?? $permiso;
            json_error('Solo un perfil con permiso de ' . $etiqueta . ' puede otorgarlo o quitarlo', 403);
        }

        $permisos[$permiso] = $nuevo;
    }

    // La fila y sus paneles van juntos: un perfil con el estado nuevo y los
    // paneles viejos es un acceso que nadie configuro.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE perfiles
                SET tipo = :t, habilitado = :hab,
                    operacion = :op, invitacion = :inv, facturacion = :fac
              WHERE id = :id AND dominio = :dom'
        )->execute([
            ':t'   => $tipo,
            ':hab' => $habilitado,
            ':op'  => $permisos['operacion'],
            ':inv' => $permisos['invitacion'],
            ':fac' => $permisos['facturacion'],
            ':id'  => $id,
            ':dom' => $dominio,
        ]);

        if ($paneles !== null) {
            sincronizarPaneles($pdo, $id, $paneles);
            olvidarPanelSinPermiso($pdo, $id, (int) $fila['panel'], $paneles);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_ok([
        'id'          => $id,
        'habilitado'  => $habilitado === HABILITADO,
        'operacion'   => $permisos['operacion']   === HABILITADO,
        'invitacion'  => $permisos['invitacion']  === HABILITADO,
        'facturacion' => $permisos['facturacion'] === HABILITADO,
    ]);
}

/**
 * Elimina el PERFIL: la persona pierde el acceso a este dominio y conserva la
 * cuenta, la contrasena y los accesos que tenga en otros dominios.
 *
 * Antes hay que borrar sus filas de `sesiones`: `fk_sesiones_perfil` es
 * ON DELETE RESTRICT y 1.917 de los 2.227 perfiles de dev tienen alguna, asi
 * que sin esto la baja fallaba con 1451 en la enorme mayoria de las filas. Una
 * sesion del sistema historico que apunta a un perfil que ya no existe no se
 * puede retomar, asi que borrarlas es cerrar el acceso que se acaba de quitar.
 *
 * `usuarios.perfil` NO se toca aca: su FK es ON DELETE SET NULL y la base lo
 * resuelve sola. Si el perfil borrado era el activo de esa cuenta, el login
 * elige otro (api/login.php -> perfilesHabilitados()).
 */
function handleDelete(): void
{
    $dominio = requireDominioId();
    $id      = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    perfilDelDominio($id, $dominio);
    if (esPerfilDeLaSesion($id)) {
        json_error('No podes eliminar tu propio perfil', 409);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM sesiones WHERE perfil = :id')->execute([':id' => $id]);

        $stmt = $pdo->prepare('DELETE FROM perfiles WHERE id = :id AND dominio = :dom');
        $stmt->execute([':id' => $id, ':dom' => $dominio]);
        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            json_error('Perfil no encontrado en este dominio', 404);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    json_ok(['id' => $id]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * Normaliza una fila del listado para el front. Nunca incluye credenciales.
 *
 * `habilitado` es SIEMPRE el del perfil; el de la cuenta viaja aparte en
 * `usuario_habilitado`.
 */
function mapPerfil(array $r): array
{
    $out = [
        'id'                 => (int) $r['id'],
        'uuid'               => (string) ($r['uuid'] ?? ''),
        'perfil_nombre'      => (string) ($r['perfil_nombre'] ?? ''),
        'habilitado'         => (int) ($r['habilitado'] ?? 0) === HABILITADO,
        'usuario_id'         => !empty($r['usuario_id']) ? (int) $r['usuario_id'] : null,
        'usuario_uuid'       => (string) ($r['usuario_uuid'] ?? ''),
        'usuario'            => (string) ($r['usuario'] ?? ''),
        'nombre'             => (string) ($r['nombre'] ?? ''),
        'correo'             => (string) ($r['correo'] ?? ''),
        'celular'            => (string) ($r['celular'] ?? ''),
        // `usuarios.habilitado` es el mismo tinyint(1) 0/1 que el del perfil:
        // las dos filas con 'S'/'N' del sistema historico las normalizo
        // 20260905_2200_habilitado_tinyint_0_1.sql.
        'usuario_habilitado' => (int) ($r['usuario_habilitado'] ?? 0) === HABILITADO,
        'registrado'         => (string) ($r['registrado'] ?? ''),
        'ingresado'          => (string) ($r['ingresado'] ?? ''),
        // Le permite al front no ofrecer las acciones que el backend va a
        // rechazar (deshabilitar / eliminar el perfil de la propia sesion).
        'es_propio'          => esPerfilDeLaSesion((int) $r['id']),
    ];

    // Campos que solo trae el GET por id (modal de Consulta).
    foreach (['tipo', 'dominio_nombre', 'registrante_nombre', 'autenticacion'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = (string) ($r[$extra] ?? '');
        }
    }

    // Los tres permisos, tambien solo en el GET por id. Viajan como BOOLEANOS y
    // no como el 1/0 crudo, igual que `habilitado`: el front no tiene que
    // repetir el criterio de lectura de la columna.
    foreach (perfilPermisosClaves() as $permiso) {
        if (array_key_exists($permiso, $r)) {
            $out[$permiso] = esHabilitado($r[$permiso]);
        }
    }

    return $out;
}

/* ------------------------------------------------------------------ */
/* Paneles del perfil (`perfiles_paneles`)                             */
/* ------------------------------------------------------------------ */

/**
 * Paneles HABILITADOS del dominio, indexados por id. Solo los habilitados: dar
 * permiso sobre un panel apagado no significa nada, porque `app` no lo lista
 * igual.
 *
 * Acotado al dominio de la sesion, que es lo que vuelve innecesaria la
 * comprobacion cross-dominio que si hace cloud: un panel de otro cliente
 * directamente no esta en el catalogo, asi que `validarPaneles()` lo rechaza
 * por el mismo camino que un id inventado.
 */
function catalogoPaneles(int $dominio): array
{
    static $cache = [];
    if (isset($cache[$dominio])) {
        return $cache[$dominio];
    }

    $stmt = db()->prepare(
        'SELECT id, nombre FROM paneles
         WHERE dominio = :dom AND habilitado = :hab
         ORDER BY nombre ASC, id ASC'
    );
    $stmt->execute([':dom' => $dominio, ':hab' => HABILITADO]);

    $catalogo = [];
    foreach ($stmt->fetchAll() as $r) {
        $catalogo[(int) $r['id']] = [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
        ];
    }

    return $cache[$dominio] = $catalogo;
}

/** Ids de panel asignados al perfil. */
function panelesDelPerfil(int $id): array
{
    $stmt = db()->prepare('SELECT panel FROM perfiles_paneles WHERE perfil = :p ORDER BY panel ASC');
    $stmt->execute([':p' => $id]);

    return array_map('intval', array_column($stmt->fetchAll(), 'panel'));
}

/**
 * Ids validos para ese dominio. Un id que no este en el catalogo se rechaza con
 * 422: puede ser inexistente, estar deshabilitado o ser de otro dominio, y los
 * tres casos son lo mismo desde aca — un permiso que esta pantalla no puede dar.
 * Sin esta validacion, un id a mano en el payload le daria a un perfil acceso al
 * panel de otro cliente (la base no puede expresar esa restriccion con una FK).
 */
function validarPaneles(mixed $entrada, int $dominio): array
{
    if (!is_array($entrada)) {
        return [];
    }

    $catalogo = catalogoPaneles($dominio);
    $ids      = [];
    foreach ($entrada as $v) {
        $panel = (int) $v;
        if ($panel <= 0) {
            continue;
        }
        if (!isset($catalogo[$panel])) {
            json_error('El panel #' . $panel . ' no existe, esta deshabilitado o es de otro dominio', 422);
        }
        $ids[] = $panel;
    }

    return array_values(array_unique($ids));
}

/**
 * Deja `perfiles_paneles` igual a `$paneles` para ese perfil, tocando SOLO lo
 * que cambia. No borra y reinserta a proposito: `asignado` es la fecha en que se
 * dio ese permiso, y reescribir la fila entera en cada guardado la volveria la
 * fecha del ultimo `Guardar`. Mismo criterio que las otras tablas puente del
 * repo (`sincronizarPaneles()` en cloud/api/profiles.php).
 */
function sincronizarPaneles(PDO $pdo, int $perfil, array $paneles): void
{
    $stmt = $pdo->prepare('SELECT panel FROM perfiles_paneles WHERE perfil = :p');
    $stmt->execute([':p' => $perfil]);

    $previos = array_map('intval', array_column($stmt->fetchAll(), 'panel'));
    $agregar = array_values(array_diff($paneles, $previos));
    $quitar  = array_values(array_diff($previos, $paneles));

    if ($quitar) {
        // Interpolar la lista es seguro: son enteros, ya casteados por
        // validarPaneles() y comprobados contra el catalogo del dominio.
        $ids = implode(',', array_map('intval', $quitar));
        $pdo->prepare("DELETE FROM perfiles_paneles WHERE perfil = :p AND panel IN ($ids)")
            ->execute([':p' => $perfil]);
    }

    if ($agregar) {
        $ins = $pdo->prepare(
            'INSERT INTO perfiles_paneles (perfil, panel, asignado) VALUES (:p, :pa, NOW())'
        );
        foreach ($agregar as $panel) {
            $ins->execute([':p' => $perfil, ':pa' => $panel]);
        }
    }
}

/**
 * Borra la memoria del ultimo panel si el guardado le quito el permiso.
 *
 * `perfiles.panel` NO es una columna administrativa: es el ultimo panel que ese
 * perfil abrio en `app`, y ademas el que `app` le reabre al conectarse. La
 * escribe `app/api/paneles.php` cada vez que la persona cambia de panel, y solo
 * con panels que ya paso por `appPanelesDelDominio()` -- o sea que la invariante
 * que `app` mantiene es "`panel` es siempre uno de los permitidos".
 *
 * ESTE ENDPOINT ES EL UNICO QUE PUEDE ROMPERLA, porque es el unico que revoca
 * paneles desde afuera de `app`. Sin esto, destildar el panel recordado deja el
 * puntero apuntando a algo que el perfil ya no puede abrir.
 *
 * SE LIMPIA A NULL Y NO AL PRIMERO DE LA LISTA. Es una memoria, no una
 * preferencia: elegirle uno seria inventarle al perfil una decision que nadie
 * tomo. Para la persona no cambia nada — `appPanelesDelDominio()` ya cae al
 * primer panel permitido cuando el recordado no esta en la lista
 * (app/lib/contexto.php), y con `panel` en 0 hace exactamente lo mismo —, asi
 * que lo unico que se gana es no dejar un puntero que miente. NULL y no 0 es el
 * mismo criterio con el que lo escribe `panel/invitacion/aceptar.php`: con las
 * FK declaradas en db/schema.sql, el 0 del legacy ya no es un valor valido.
 */
function olvidarPanelSinPermiso(PDO $pdo, int $id, int $recordado, array $paneles): void
{
    if ($recordado <= 0 || in_array($recordado, $paneles, true)) {
        return;
    }

    $pdo->prepare('UPDATE perfiles SET panel = NULL WHERE id = :id')->execute([':id' => $id]);
}

/** `tipo` es ENUM('A','O') NOT NULL: dos letras y nada mas (lib/acceso.php). */
function validarTipo(mixed $valor): string
{
    $tipo = strtoupper(trim((string) $valor));
    if ($tipo !== PERFIL_TIPO_ADMINISTRADOR && $tipo !== PERFIL_TIPO_OPERADOR) {
        json_error('Tipo invalido', 422);
    }

    return $tipo;
}

/** Corta con 404 si el perfil no es de este dominio. Devuelve la fila. */
function perfilDelDominio(int $id, int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT id, usuario, tipo, panel, habilitado,
                operacion, invitacion, facturacion
         FROM perfiles WHERE id = :id AND dominio = :dom LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Perfil no encontrado en este dominio', 404);
    }

    return $row;
}

/** ¿Es el perfil con el que esta trabajando la sesion en curso? */
function esPerfilDeLaSesion(int $id): bool
{
    $ctx = sessionContext() ?? [];

    return $id > 0 && $id === (int) ($ctx['perfil'] ?? 0);
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
