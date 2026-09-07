<?php

declare(strict_types=1);

/**
 * ABM de `controladores`: las personas que pueden entrar a Reactor Cloud.
 *
 * `controladores` NO es `usuarios`. `usuarios` son los clientes finales, que
 * entran a `app` y a `panel`; los controladores operan la plataforma y entran
 * solo a cloud. Las dos listas no se cruzan y ninguna de las dos deriva de la
 * otra: por eso la tabla no tiene FK contra `usuarios` (ver el encabezado de
 * cloud/sql/migrations/20260906_1000_crear_controladores.sql).
 *
 * LA CONTRASENA ES UN HASH BCRYPT, NO EL CIFRADO HISTORICO DE REACTOR. La
 * diferencia con api/users.php es deliberada: `usuarios.contrasena` es
 * reversible porque hay miles de filas ya escritas asi y un sistema legacy
 * afuera de este repo que las lee, mientras que esta tabla nace hoy y solo la
 * lee cloud. Consecuencia practica: aca NO existe el endpoint `?credencial=1`
 * que api/users.php usa para precargar la contrasena vigente en el modal — un
 * hash no se puede deshacer. Solo se puede fijar una nueva.
 *
 * ALCANCE: lo puede llamar cualquier sesion autenticada de cloud, igual que el
 * resto de los endpoints del panel (bootstrap.php exige JWT valido y nada mas).
 * Cuando el login de cloud pase a leer esta tabla, este endpoint es el primero
 * que necesita un control por rol: quien puede editar controladores puede
 * darse acceso a si mismo.
 */

require __DIR__ . '/bootstrap.php';

/** Minimo de la contrasena. Mas alto que los 6 de `usuarios` porque estas
 *  credenciales abren el backoffice entero, no un dominio. */
const CONTROLADOR_PASS_MIN = 8;

/** Tope duro: bcrypt ignora en silencio todo lo que pase de 72 BYTES. Sin este
 *  chequeo, dos contrasenas distintas que compartan los primeros 72 bytes
 *  validarian igual y el operador no tendria forma de enterarse. */
const CONTROLADOR_PASS_MAX_BYTES = 72;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar controladores: ' . $e->getMessage(), 500);
}

/**
 * Listado completo (la tabla es chica por naturaleza: son los operadores de la
 * plataforma, no una poblacion de clientes) + resumen para las stat-cards.
 * `contrasena` NO viaja nunca al front, ni siquiera hasheada.
 */
function handleList(): void
{
    $stmt = db()->query(
        'SELECT id, nombre, correo, celular, habilitado, registrado, ingresado
           FROM controladores
          ORDER BY habilitado DESC, nombre ASC'
    );

    // Los roles asignados salen de una segunda consulta y no de un JOIN con
    // GROUP_CONCAT: son dos tablas chicas, y con el JOIN habria que volver a
    // partir la cadena en PHP para armar el array que espera el selector.
    $rolesPorControlador = rolesAsignados();

    $controladores = array_map(static function (array $r) use ($rolesPorControlador): array {
        $id = (int) $r['id'];
        // El front trabaja con un booleano `activo`; la columna sigue siendo
        // el tinyint de dos valores que fija lib/habilitado.php.
        $r['activo'] = esHabilitado($r['habilitado'] ?? 0);
        unset($r['habilitado']);
        $r['roles'] = $rolesPorControlador[$id] ?? [];
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'      => count($controladores),
        'activos'    => 0,
        'inactivos'  => 0,
        'sin_acceso' => 0,   // habilitados que todavia nunca ingresaron
        'sin_roles'  => 0,   // habilitados sin ningun rol asignado
    ];
    foreach ($controladores as $c) {
        if ($c['activo']) $resumen['activos']++;
        else              $resumen['inactivos']++;
        if ($c['activo'] && empty($c['ingresado'])) $resumen['sin_acceso']++;
        if ($c['activo'] && !$c['roles'])           $resumen['sin_roles']++;
    }

    json_ok([
        'controladores' => $controladores,
        'resumen'       => $resumen,
        // El catalogo de roles viaja con el listado: el selector del modal de
        // Alta/Edicion lo necesita y son 10 filas.
        'catalogos'     => ['roles' => array_values(catalogoRoles())],
    ]);
}

/** controlador -> [{id, nombre, activo}], para el listado y la ficha. */
function rolesAsignados(?int $controlador = null): array
{
    $sql = 'SELECT cr.controlador, r.id, r.nombre, r.habilitado
              FROM controladores_roles cr
              JOIN roles r ON r.id = cr.rol';
    $params = [];
    if ($controlador !== null) {
        $sql .= ' WHERE cr.controlador = :c';
        $params[':c'] = $controlador;
    }
    $sql .= ' ORDER BY r.nombre ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $porControlador = [];
    foreach ($stmt->fetchAll() as $r) {
        $porControlador[(int) $r['controlador']][] = [
            'id'     => (int) $r['id'],
            'nombre' => (string) $r['nombre'],
            'activo' => esHabilitado($r['habilitado'] ?? 0),
        ];
    }

    return $porControlador;
}

function handleCreate(): void
{
    $in = readJson();

    $datos    = validarComunes($in);
    $roles    = validarRoles($in['roles'] ?? []);
    $password = (string) ($in['password'] ?? '');
    validarPassword($password, true);

    // `correo` tiene UNIQUE en la tabla, pero el duplicado se chequea igual
    // antes del INSERT: asi el operador recibe "ya existe un controlador con
    // ese correo" y no un 1062 traducido a "error al procesar".
    if (correoTomado($datos['correo'], 0)) {
        json_error('Ya existe un controlador con ese correo', 409);
    }

    // La fila y sus roles van en una transaccion: un controlador a medio
    // asignar es peor que ninguno, porque nada indica que hay que completarlo.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO controladores (nombre, correo, celular, contrasena, habilitado, registrado)
             VALUES (:n, :c, :cel, :p, :h, NOW())'
        );
        $stmt->execute([
            ':n'   => $datos['nombre'],
            ':c'   => $datos['correo'],
            ':cel' => $datos['celular'],
            ':p'   => password_hash($password, PASSWORD_DEFAULT),
            // Entero, nunca el booleano de PHP: PDO bindea `false` como cadena
            // vacia. Ver lib/habilitado.php.
            ':h'   => valorHabilitado($datos['activo']),
        ]);

        $id = (int) $pdo->lastInsertId();
        sincronizarRoles($pdo, $id, $roles);

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

    $datos    = validarComunes($in);
    $roles    = validarRoles($in['roles'] ?? []);
    $password = (string) ($in['password'] ?? '');
    // En edicion la contrasena es opcional: vacia significa "no cambiarla".
    if ($password !== '') validarPassword($password, false);

    $prev = db()->prepare('SELECT id FROM controladores WHERE id = :id');
    $prev->execute([':id' => $id]);
    if (!$prev->fetchColumn()) json_error('Controlador no encontrado', 404);

    if (correoTomado($datos['correo'], $id)) {
        json_error('Ya existe un controlador con ese correo', 409);
    }

    $habilitado = valorHabilitado($datos['activo']);
    if ($habilitado === DESHABILITADO) {
        // Deshabilitar al ultimo controlador activo deja a cloud sin nadie que
        // pueda entrar, y la unica salida seria un UPDATE a mano contra la
        // base. Ver `garantizarQueQuedaAlguien()`.
        garantizarQueQuedaAlguien($id, 'deshabilitar');
    }

    $sql    = 'UPDATE controladores
                  SET nombre = :n, correo = :c, celular = :cel, habilitado = :h';
    $params = [
        ':n'   => $datos['nombre'],
        ':c'   => $datos['correo'],
        ':cel' => $datos['celular'],
        ':h'   => $habilitado,
        ':id'  => $id,
    ];

    if ($password !== '') {
        $sql          .= ', contrasena = :p';
        $params[':p']  = password_hash($password, PASSWORD_DEFAULT);
    }

    $sql .= ' WHERE id = :id';

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare($sql)->execute($params);
        sincronizarRoles($pdo, $id, $roles);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id]);
}

/**
 * Deja la asignacion de roles igual a `$roles`, tocando SOLO lo que cambia.
 *
 * No es un DELETE + INSERT de todo: `asignado` es la fecha en que se le dio ese
 * rol a esa persona, y reescribir la fila entera en cada guardado la volveria
 * la fecha del ultimo `Guardar` -- o sea, un dato inutil.
 */
function sincronizarRoles(PDO $pdo, int $controlador, array $roles): void
{
    $stmt = $pdo->prepare('SELECT rol FROM controladores_roles WHERE controlador = :c');
    $stmt->execute([':c' => $controlador]);

    $previos = array_map('intval', array_column($stmt->fetchAll(), 'rol'));
    $agregar = array_values(array_diff($roles, $previos));
    $quitar  = array_values(array_diff($previos, $roles));

    if ($quitar) {
        // Interpolar la lista es seguro: son enteros, ya casteados. Con
        // placeholders habria que armar la cadena de `?` igual.
        $ids = implode(',', array_map('intval', $quitar));
        $pdo->prepare("DELETE FROM controladores_roles WHERE controlador = :c AND rol IN ($ids)")
            ->execute([':c' => $controlador]);
    }

    if ($agregar) {
        $ins = $pdo->prepare(
            'INSERT INTO controladores_roles (controlador, rol, asignado) VALUES (:c, :r, NOW())'
        );
        foreach ($agregar as $rol) {
            $ins->execute([':c' => $controlador, ':r' => $rol]);
        }
    }
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    // Sin FKs contra esta tabla no hay desglose de impacto que mostrar (ABM.md
    // §4.3): la baja no arrastra filas de ningun lado. Lo unico que puede salir
    // mal es quedarse sin controladores.
    garantizarQueQuedaAlguien($id, 'eliminar');

    $stmt = db()->prepare('DELETE FROM controladores WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Controlador no encontrado', 404);

    json_ok(['id' => $id]);
}

/**
 * Rechaza la operacion si dejaria a cloud sin un solo controlador habilitado.
 *
 * Es la contracara del requisito "solo los controladores entran a cloud": con
 * la lista vacia no entra nadie, y recuperarse exigiria un INSERT a mano contra
 * la base de produccion. Mientras el login siga leyendo `usuarios` el escenario
 * no se puede dar todavia, pero la regla vive aca desde el principio para que
 * el corte del paso siguiente no dependa de acordarse de agregarla.
 */
function garantizarQueQuedaAlguien(int $id, string $accion): void
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM controladores WHERE habilitado = 1 AND id <> :id'
    );
    $stmt->execute([':id' => $id]);

    if ((int) $stmt->fetchColumn() > 0) return;

    // Si el propio registro no esta habilitado, sacarlo no cambia nada: el
    // sistema ya estaba sin controladores y el bloqueo seria ruido.
    $propio = db()->prepare('SELECT habilitado FROM controladores WHERE id = :id');
    $propio->execute([':id' => $id]);
    $fila = $propio->fetch();
    if ($fila === false || !esHabilitado($fila['habilitado'])) return;

    json_error(
        'No se puede ' . $accion . ' al unico controlador habilitado: cloud quedaria sin nadie que pueda ingresar. '
        . 'Cargá o habilitá otro controlador primero.',
        409
    );
}

function correoTomado(string $correo, int $exceptoId): bool
{
    $stmt = db()->prepare(
        'SELECT id FROM controladores WHERE correo = :c AND id <> :id LIMIT 1'
    );
    $stmt->execute([':c' => $correo, ':id' => $exceptoId]);

    return (bool) $stmt->fetchColumn();
}

/** Campos que valen igual en alta y en edicion. Devuelve los valores ya limpios. */
function validarComunes(array $in): array
{
    $nombre  = trim((string) ($in['nombre']  ?? ''));
    // El correo es la credencial de login, asi que se normaliza a minusculas
    // antes de guardarlo: el UNIQUE de la tabla distingue mayusculas y sin esto
    // "Ana@x.com" y "ana@x.com" serian dos controladores distintos.
    $correo  = strtolower(trim((string) ($in['correo']  ?? '')));
    $celular = trim((string) ($in['celular'] ?? ''));
    $activo  = isset($in['activo']) ? (bool) $in['activo'] : false;

    if ($nombre === '')            json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 100)  json_error('El nombre no puede superar 100 caracteres', 422);

    if ($correo === '')            json_error('El correo es obligatorio', 422);
    if (mb_strlen($correo) > 100)  json_error('El correo no puede superar 100 caracteres', 422);
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL))
        json_error('Ingresa un correo valido', 422);

    if ($celular !== '') {
        if (mb_strlen($celular) > 30) json_error('El celular no puede superar 30 caracteres', 422);
        if (!preg_match('/^[+0-9\s().-]+$/', $celular))
            json_error('El celular solo admite numeros, espacios y los signos + ( ) - .', 422);
    }

    return [
        'nombre'  => $nombre,
        'correo'  => $correo,
        'celular' => $celular === '' ? null : $celular,
        'activo'  => $activo,
    ];
}

function validarPassword(string $password, bool $obligatoria): void
{
    if ($password === '') {
        if ($obligatoria) json_error('La contrasena es obligatoria', 422);
        return;
    }

    if (mb_strlen($password) < CONTROLADOR_PASS_MIN) {
        json_error('La contrasena debe tener al menos ' . CONTROLADOR_PASS_MIN . ' caracteres', 422);
    }
    // strlen(), no mb_strlen(): el limite de bcrypt se cuenta en bytes, y un
    // caracter acentuado ocupa dos.
    if (strlen($password) > CONTROLADOR_PASS_MAX_BYTES) {
        json_error('La contrasena no puede superar ' . CONTROLADOR_PASS_MAX_BYTES . ' bytes', 422);
    }
}

/**
 * Ids de rol seleccionados. A diferencia de las listas de `roles` (que son ids
 * dentro de un varchar y admiten colgados), aca hay una FK real contra
 * `roles`.`id`: un id que no exista rebota con 422 en vez de morir con un 1452
 * del motor a mitad de la transaccion.
 */
function validarRoles(mixed $entrada): array
{
    if (!is_array($entrada)) return [];

    $catalogo = catalogoRoles();
    $ids      = [];
    foreach ($entrada as $v) {
        $id = (int) $v;
        if ($id <= 0) continue;
        if (!isset($catalogo[$id])) json_error('El rol #' . $id . ' no existe', 422);
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/**
 * Catalogo de roles para el selector. Se ofrecen TODOS, habilitados o no: un
 * rol deshabilitado que alguien ya tiene asignado tiene que poder verse y
 * quitarse, y esconderlo lo volveria una asignacion invisible. El front los
 * marca; el estado real vive en `roles`.`habilitado`.
 */
function catalogoRoles(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (db()->query('SELECT id, nombre, habilitado FROM roles ORDER BY nombre ASC, id ASC') as $r) {
        $activo = esHabilitado($r['habilitado'] ?? 0);

        $cache[(int) $r['id']] = [
            'id'     => (int) $r['id'],
            'nombre' => (string) ($r['nombre'] ?? ''),
            // Linea secundaria del item en el selector. Desde que `roles` no
            // tiene `nivel` ni `sistema`, lo unico que queda por aclarar es que
            // el rol este apagado.
            'extra'  => $activo ? '' : 'deshabilitado',
            'activo' => $activo,
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
