<?php

declare(strict_types=1);

/**
 * ABM de `roles` (db/schema.sql). Columnas reales: id, nombre, habilitado,
 * descripcion. Los permisos del rol viven en `roles_permisos`.
 *
 * LA TABLA ADELGAZO MUCHO EL 06/09/2026. Hasta esa fecha `roles` era el modelo
 * de permisos del sistema historico y arrastraba cinco columnas mas, que la
 * migracion `20260906_1300_permisos_solo_cloud.sql` elimino:
 *
 *   `sistema`   repartia el rol entre tres productos (A/C/P). Los permisos son
 *               de cloud y de nadie mas, asi que no hay nada que repartir.
 *   `nivel`     'A'/'O' del menu legacy.
 *   `menus`     lista de ids de `menus` en un varchar.
 *   `accesos`   lista de ids de una tabla `accesos` QUE NUNCA EXISTIO en este
 *               esquema.
 *   `permisos`  lista de ids de `permisos` en un varchar, con el formato
 *               `(1001)(1004)`. La reemplazo `roles_permisos`.
 *
 * CON ESO SE FUE TODA LA MAQUINARIA DE LOS IDS COLGADOS. Mientras los permisos
 * vivian en un varchar sin integridad referencial, un rol podia referenciar un
 * permiso inexistente (los 8 roles con datos apuntaban a 1101-1105) y el ABM
 * tenia que mostrarlos, admitirlos y conservarlos para no borrarlos de oficio.
 * `roles_permisos` tiene FK contra `permisos`.`id`: un id que no existe no
 * entra, y punto.
 *
 * BORRAR UN ROL lo bloquea `controladores_roles`.`rol`, una FK
 * `ON DELETE RESTRICT`. No se resuelve por nuestra cuenta: sacarle el acceso a
 * un controlador es una decision de negocio y no una limpieza. `?impacto=1` la
 * cuenta antes de abrir el modal y el DELETE repite la validacion.
 *
 * YA NADA FUERA DE CLOUD DEPENDE DE ESTA TABLA. `panel` y `app` la unian por
 * `perfiles`.`rol` para mostrar el nombre del rol; esa columna se elimino el
 * 06/09/2026 (`20260906_1500_perfiles_sin_rol.sql`) y con ella la ultima
 * lectura de `roles` fuera de cloud. `roles` es hoy un catalogo privado de
 * cloud, usado por `roles_permisos` y `controladores_roles`.
 */

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            // ?impacto=1&id=N devuelve lo que bloquea el borrado.
            if (isset($_GET['impacto'])) handleImpacto();
            else                         handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar roles: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Controladores por rol: es el dato que decide si el rol se puede borrar,
    // asi que viaja en el listado y no solo en `?impacto=1`. Subconsulta y no
    // LEFT JOIN + GROUP BY: es una sola cuenta y asi el SELECT no cambia de
    // forma si manana vuelve a haber dos dependencias.
    $stmt = db()->query(
        'SELECT r.id, r.nombre, r.habilitado, r.descripcion,
                (SELECT COUNT(*) FROM controladores_roles cr WHERE cr.rol = r.id) AS controladores_count
           FROM roles r
          ORDER BY r.habilitado DESC, r.nombre ASC, r.id ASC'
    );

    $puente = permisosAsignados();

    $roles = array_map(static function (array $r) use ($puente): array {
        $id = (int) $r['id'];
        return [
            'id'                  => $id,
            'nombre'              => (string) ($r['nombre'] ?? ''),
            'activo'              => esHabilitado($r['habilitado'] ?? 0),
            'descripcion'         => (string) ($r['descripcion'] ?? ''),
            'permisos'            => $puente[$id] ?? [],
            'controladores_count' => (int) ($r['controladores_count'] ?? 0),
        ];
    }, $stmt->fetchAll());

    $resumen = ['total' => count($roles), 'habilitados' => 0, 'deshabilitados' => 0, 'sin_permisos' => 0];
    foreach ($roles as $r) {
        if ($r['activo']) $resumen['habilitados']++;
        else              $resumen['deshabilitados']++;
        if (!$r['permisos']) $resumen['sin_permisos']++;
    }

    json_ok([
        'roles'     => $roles,
        'resumen'   => $resumen,
        // El catalogo viaja con el listado: el selector del modal de
        // Alta/Edicion lo necesita y son 115 filas, nada que justifique un
        // segundo request.
        'catalogos' => ['permisos' => array_values(catalogoPermisos())],
    ]);
}

/**
 * Previsualiza el borrado. La dependencia bloquea; no hay nada que se elimine
 * en cascada salvo las filas de `roles_permisos`, que no son un dato propio
 * sino la asignacion que se esta borrando.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre FROM roles WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $rol = $stmt->fetch();
    if (!$rol) json_error('Rol no encontrado', 404);

    $bloqueos = [];
    foreach (dependenciasDelRol($id) as $dep) {
        if ($dep['cantidad'] > 0) $bloqueos[] = $dep;
    }

    json_ok([
        'rol'      => ['id' => (int) $rol['id'], 'nombre' => (string) $rol['nombre']],
        'bloqueos' => $bloqueos,
    ]);
}

/**
 * La FK `ON DELETE RESTRICT` que apunta a `roles`.
 *
 * QUEDA UNA SOLA. Hasta el 06/09/2026 eran dos: `perfiles`.`rol` tambien
 * bloqueaba, y era la que de verdad frenaba los borrados (el rol Operador tenia
 * 1589 perfiles). Esa columna se elimino con
 * `20260906_1500_perfiles_sin_rol.sql`, asi que hoy un rol solo esta protegido
 * por los controladores que lo tengan asignado -- y eso significa que los 10
 * roles heredados del legacy pasaron a ser borrables.
 *
 * `etiqueta` es el renglon del modal; `sustantivo` es la misma dependencia
 * metida en la frase del 409. Se declaran las dos porque una etiqueta de titulo
 * en minusculas ("1 controladores con este rol") no arma una oracion.
 */
function dependenciasDelRol(int $id): array
{
    return [
        ['tabla' => 'controladores_roles', 'etiqueta' => 'Controladores con este rol', 'sustantivo' => 'controlador(es)', 'cantidad' => contarPorRol('controladores_roles', $id)],
    ];
}

/** COUNT sobre `rol`, el lado hijo de una FK indexada. */
function contarPorRol(string $tabla, int $id): int
{
    static $cache = [];
    $clave = $tabla . '.' . $id;
    if (isset($cache[$clave])) return $cache[$clave];

    // La tabla es un literal del propio archivo, no entrada del usuario.
    $stmt = db()->prepare("SELECT COUNT(*) FROM `$tabla` WHERE `rol` = :id");
    $stmt->execute([':id' => $id]);

    return $cache[$clave] = (int) $stmt->fetchColumn();
}

function handleCreate(): void
{
    $datos = validarPayload(readJson());

    // La fila y sus permisos van en una transaccion: un rol a medio asignar
    // reparte accesos que nadie pidio.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO roles (nombre, habilitado, descripcion) VALUES (:n, :h, :d)'
        );
        $stmt->execute([
            ':n' => $datos['nombre'],
            // Entero, nunca el booleano de PHP. Ver lib/habilitado.php.
            ':h' => valorHabilitado($datos['activo']),
            ':d' => $datos['descripcion'],
        ]);

        $id = (int) $pdo->lastInsertId();
        sincronizarPermisos($pdo, $id, $datos['permisos']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM roles WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Rol no encontrado', 404);

    $datos = validarPayload($in);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'UPDATE roles SET nombre = :n, habilitado = :h, descripcion = :d WHERE id = :id'
        );
        $stmt->execute([
            ':n'  => $datos['nombre'],
            ':h'  => valorHabilitado($datos['activo']),
            ':d'  => $datos['descripcion'],
            ':id' => $id,
        ]);

        sincronizarPermisos($pdo, $id, $datos['permisos']);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    // Se repite la validacion del modal: el desglose es informativo, no un
    // permiso (ABM.md §Eliminar). Sin esto el DELETE moriria con un 1451 del
    // motor, que el front muestra como "error al procesar".
    $partes = [];
    foreach (dependenciasDelRol($id) as $dep) {
        if ($dep['cantidad'] > 0) $partes[] = $dep['cantidad'] . ' ' . $dep['sustantivo'];
    }
    if ($partes) {
        json_error(
            'No se puede eliminar el rol: lo tienen asignado ' . implode(' y ', $partes) . '. '
            . 'Sacaselo primero.',
            409
        );
    }

    // `roles_permisos` es CASCADE: las asignaciones del rol se van solas.
    $stmt = db()->prepare('DELETE FROM roles WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Rol no encontrado', 404);

    json_ok(['id' => $id]);
}

/**
 * Deja `roles_permisos` igual a `$permisos` para ese rol, tocando SOLO lo que
 * cambia — mismo criterio que `sincronizarRoles()` en api/controladores.php:
 * `asignado` es la fecha en que el rol recibio ese permiso, y reescribir todo
 * en cada guardado la volveria la fecha del ultimo `Guardar`.
 */
function sincronizarPermisos(PDO $pdo, int $rol, array $permisos): void
{
    $stmt = $pdo->prepare('SELECT permiso FROM roles_permisos WHERE rol = :r');
    $stmt->execute([':r' => $rol]);

    $previos = array_map('intval', array_column($stmt->fetchAll(), 'permiso'));
    $agregar = array_values(array_diff($permisos, $previos));
    $quitar  = array_values(array_diff($previos, $permisos));

    if ($quitar) {
        // Interpolar la lista es seguro: son enteros, ya casteados.
        $ids = implode(',', array_map('intval', $quitar));
        $pdo->prepare("DELETE FROM roles_permisos WHERE rol = :r AND permiso IN ($ids)")
            ->execute([':r' => $rol]);
    }

    if ($agregar) {
        $ins = $pdo->prepare('INSERT INTO roles_permisos (rol, permiso, asignado) VALUES (:r, :p, NOW())');
        foreach ($agregar as $permiso) {
            $ins->execute([':r' => $rol, ':p' => $permiso]);
        }
    }
}

/** rol -> [ids de permiso]. Una sola consulta indexada para todo el listado. */
function permisosAsignados(): array
{
    $porRol = [];
    foreach (db()->query('SELECT rol, permiso FROM roles_permisos ORDER BY permiso ASC') as $r) {
        $porRol[(int) $r['rol']][] = (int) $r['permiso'];
    }

    return $porRol;
}

function validarPayload(array $in): array
{
    $nombre      = trim((string) ($in['nombre']      ?? ''));
    $descripcion = trim((string) ($in['descripcion'] ?? ''));
    $activo      = isset($in['activo']) ? (bool) $in['activo'] : false;

    if ($nombre === '')            json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 255)  json_error('El nombre no puede superar 255 caracteres', 422);
    if (mb_strlen($descripcion) > 1000)
        json_error('La descripcion no puede superar 1000 caracteres', 422);

    return [
        'nombre'      => $nombre,
        'activo'      => $activo,
        'permisos'    => validarPermisos($in['permisos'] ?? []),
        'descripcion' => $descripcion === '' ? null : $descripcion,
    ];
}

/**
 * Ids de permiso seleccionados. Hay FK real contra `permisos`.`id`, asi que un
 * id que no exista rebota con 422 en vez de morir con un 1452 del motor a mitad
 * de la transaccion.
 */
function validarPermisos(mixed $entrada): array
{
    if (!is_array($entrada)) return [];

    $catalogo = catalogoPermisos();
    $ids      = [];
    foreach ($entrada as $v) {
        $id = (int) $v;
        if ($id <= 0) continue;
        if (!isset($catalogo[$id])) json_error('El permiso #' . $id . ' no existe', 422);
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/** id -> fila, para la validacion y para el catalogo que viaja al front. */
function catalogoPermisos(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (db()->query('SELECT id, nombre FROM permisos ORDER BY nombre ASC, id ASC') as $r) {
        $cache[(int) $r['id']] = [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
        ];
    }

    return $cache;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
