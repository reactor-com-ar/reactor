<?php

declare(strict_types=1);

/**
 * Modulo Usuarios: ABM de los PERFILES del dominio (esquema en db/schema.sql).
 *
 *   GET    api/usuarios.php            -> listado + resumen + roles del dominio
 *   GET    api/usuarios.php?id=N       -> un perfil (con los datos de su cuenta)
 *   PUT    api/usuarios.php            -> {id, habilitado}: habilita / deshabilita
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
 * NO HAY ALTA NI EDICION. El alta es una invitacion (POST api/invitaciones.php:
 * la cuenta y el perfil los crea el propio invitado al aceptar) y los datos de
 * la persona los administra su cuenta, no este modulo.
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
    'rol'        => 'r.nombre',
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
    $rol    = (int)         ($_GET['rol']    ?? 0);
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
    if ($rol > 0) {
        $where[]        = 'p.rol = :rol';
        $params[':rol'] = $rol;
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
    if ($q !== '') {
        // Un placeholder por columna: con EMULATE_PREPARES=false, PDO no admite
        // repetir el mismo nombre en un statement (SQLSTATE HY093).
        $ors = [];
        foreach (['u.usuario', 'u.nombre', 'u.correo', 'u.celular', 'p.nombre'] as $i => $columna) {
            $ors[]             = $columna . ' LIKE :q' . $i;
            $params[':q' . $i] = '%' . $q . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }

    // LEFT JOIN y no INNER: hay perfiles sin cuenta (`perfiles.usuario` NULL o
    // con el centinela 0) y perfiles sin rol (145 en dev). Con INNER se
    // esconderian, y esconder una fila que ademas no le sirve a nadie es
    // justamente lo que impide limpiarla.
    $sql = 'SELECT p.id, p.uuid, p.nombre AS perfil_nombre, p.habilitado,
                   p.rol, p.tipo, r.nombre AS rol_nombre,
                   u.id AS usuario_id, u.uuid AS usuario_uuid, u.nombre, u.usuario,
                   u.correo, u.celular, u.habilitado AS usuario_habilitado,
                   u.registrado, u.ingresado
            FROM perfiles p
            LEFT JOIN usuarios u ON u.id = p.usuario
            LEFT JOIN roles    r ON r.id = p.rol
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
        'roles'    => rolesDelDominio($dominio),
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
                p.rol, p.tipo, p.dominio, r.nombre AS rol_nombre,
                u.id AS usuario_id, u.uuid AS usuario_uuid, u.nombre, u.usuario,
                u.correo, u.celular, u.habilitado AS usuario_habilitado,
                u.autenticacion, u.registrado, u.ingresado,
                d.nombre AS dominio_nombre,
                g.nombre AS registrante_nombre
         FROM perfiles p
         LEFT JOIN usuarios u ON u.id = p.usuario
         LEFT JOIN roles    r ON r.id = p.rol
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

    json_ok(['perfil' => mapPerfil($row)]);
}

/* ------------------------------------------------------------------ */
/* Habilitar / Deshabilitar / Baja                                     */
/* ------------------------------------------------------------------ */

/**
 * Unico UPDATE del modulo: toca `perfiles.habilitado` y nada mas.
 *
 * No reescribe la fila entera a proposito — los datos del perfil (nombre, rol,
 * tipo) los administra Reactor y los de la persona son de su cuenta, asi que
 * un PUT con payload completo solo podia romper cosas que esta pantalla no
 * muestra.
 */
function handleUpdate(): void
{
    $dominio = requireDominioId();
    $in      = readJson();
    $id      = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }
    if (!array_key_exists('habilitado', $in)) {
        json_error('Falta el estado', 422);
    }

    perfilDelDominio($id, $dominio);
    // Deshabilitar el perfil con el que se esta trabajando cierra el panel en
    // el request siguiente: el gate de acceso lo resuelve contra la base, no
    // contra el token (lib/acceso.php).
    if (esPerfilDeLaSesion($id)) {
        json_error('No podes cambiar el estado de tu propio perfil', 409);
    }

    // Se escribe el ENTERO 1 o 0, nunca un booleano de PHP: PDO bindea `false`
    // como cadena vacia y en la columna vieja (varchar) eso dejaba un tercer
    // valor que ninguna pantalla sabia leer. Hoy la columna es tinyint NOT NULL
    // y el motor lo rechazaria, pero el binding correcto es el entero.
    $habilitado = !empty($in['habilitado']) ? HABILITADO : DESHABILITADO;

    $stmt = db()->prepare(
        'UPDATE perfiles SET habilitado = :hab WHERE id = :id AND dominio = :dom'
    );
    $stmt->execute([':hab' => $habilitado, ':id' => $id, ':dom' => $dominio]);

    json_ok(['id' => $id, 'habilitado' => $habilitado === HABILITADO]);
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
 * elige otro (api/login.php -> perfilesAdministrador()).
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
        'rol'                => isset($r['rol']) && $r['rol'] !== null ? (int) $r['rol'] : null,
        'rol_nombre'         => (string) ($r['rol_nombre'] ?? ''),
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

    return $out;
}

/** Corta con 404 si el perfil no es de este dominio. Devuelve la fila. */
function perfilDelDominio(int $id, int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT id, usuario, habilitado FROM perfiles WHERE id = :id AND dominio = :dom LIMIT 1'
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

/**
 * Roles presentes entre los perfiles del dominio — el catalogo del filtro.
 *
 * Sale de `perfiles` y no de la tabla `roles` entera: ofrecer un rol que no
 * tiene ninguna fila en este dominio solo da un listado vacio.
 */
function rolesDelDominio(int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT DISTINCT r.id, r.nombre
         FROM perfiles p
         INNER JOIN roles r ON r.id = p.rol
         WHERE p.dominio = :dom
         ORDER BY r.nombre ASC'
    );
    $stmt->execute([':dom' => $dominio]);

    return array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => (string) ($r['nombre'] ?? ''),
    ], $stmt->fetchAll());
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
