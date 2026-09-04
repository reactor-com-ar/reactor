<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const ROLES_VALIDOS = ['admin', 'operador', 'lectura'];

/**
 * Dependencias reales de `usuarios`, tomadas de db/schema.sql y verificadas
 * contra information_schema. Son 14 FKs en 13 tablas, con tres comportamientos
 * distintos que el modal de confirmación tiene que saber distinguir:
 *
 *   DEP_BLOQUEA     ON DELETE RESTRICT que NO limpiamos nosotros. Reasignar una
 *                   cartera a otro ejecutivo es una decisión de negocio, así que
 *                   el borrado se rechaza y se le pide al operador que la mueva.
 *   DEP_ELIMINA     filas que desaparecen. `perfiles` y `sesiones` son RESTRICT
 *                   y las borra handleDelete() en orden; `carritos` y
 *                   `carritositems` son CASCADE y los borra la propia FK;
 *                   `invitaciones` es SET NULL en la FK pero igual se borra a
 *                   mano (una invitación sin emisor no tiene sentido: nadie
 *                   puede responderla ni auditarla).
 *   DEP_DESVINCULA  ON DELETE SET NULL: la fila sobrevive y queda sin usuario.
 *
 * Cada entrada es [tabla, columna, etiqueta]. Todas las columnas están
 * indexadas (son el lado hijo de una FK), así que los COUNT son baratos incluso
 * sobre `registros` (2,95M filas) y `sesiones` (487K).
 */
const DEP_BLOQUEA = [
    ['carteras', 'ejecutivo', 'Carteras donde figura como ejecutivo'],
];
const DEP_ELIMINA = [
    ['perfiles',      'usuario', 'Perfiles de acceso'],
    ['sesiones',      'usuario', 'Sesiones registradas'],
    ['invitaciones',  'emisor',  'Invitaciones enviadas'],
    ['carritos',      'usuario', 'Carritos de compra'],
    ['carritositems', 'usuario', 'Ítems de carrito'],
];
const DEP_DESVINCULA = [
    ['registros',    'usuario',     'Registros del historial'],
    ['sucesos',      'usuario',     'Sucesos del panel'],
    ['adopciones',   'adoptador',   'Adopciones donde figura como adoptador'],
    ['adopciones',   'liberador',   'Adopciones donde figura como liberador'],
    ['llaves',       'generador',   'Llaves generadas'],
    ['casos',        'autor',       'Casos abiertos'],
    ['mensajes',     'usuario',     'Mensajes'],
    ['usuarios',     'registrante', 'Usuarios que registró'],
];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            // ?impacto=1&id=N devuelve el detalle de lo que arrastra el borrado,
            // para que el front lo muestre antes de confirmar.
            if (isset($_GET['impacto'])) handleImpacto();
            else                          handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar usuarios: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Esquema real (db/schema.sql -> tabla `usuarios`): correo, roles, habilitado,
    // ingresado, registrado. Se aliasan a los nombres que ya usa el front (email, rol,
    // last_login_at, created_at) para no tocar el JS.
    $stmt = db()->query(
        "SELECT id,
                correo     AS email,
                nombre,
                celular,
                roles      AS rol,
                habilitado,
                ingresado  AS last_login_at,
                registrado AS created_at
         FROM usuarios
         ORDER BY habilitado DESC, nombre ASC"
    );

    $usuarios = array_map(static function (array $r): array {
        $hab = strtoupper((string) ($r['habilitado'] ?? ''));
        $r['activo'] = in_array($hab, ['S', '1', 'Y'], true);
        unset($r['habilitado']);
        return $r;
    }, $stmt->fetchAll());

    $resumen = [
        'total'     => count($usuarios),
        'activos'   => 0,
        'admins'    => 0,
        'operador'  => 0,
        'lectura'   => 0,
    ];
    foreach ($usuarios as $u) {
        if ($u['activo']) $resumen['activos']++;
        if ($u['rol'] === 'admin')    $resumen['admins']++;
        if ($u['rol'] === 'operador') $resumen['operador']++;
        if ($u['rol'] === 'lectura')  $resumen['lectura']++;
    }

    json_ok(['usuarios' => $usuarios, 'resumen' => $resumen]);
}

function handleCreate(): void
{
    $in       = readJson();
    $email    = strtolower(trim((string) ($in['email']    ?? '')));
    $nombre   = trim((string) ($in['nombre']   ?? ''));
    $celular  = trim((string) ($in['celular']  ?? ''));
    $rol      = trim((string) ($in['rol']      ?? 'operador'));
    $activo   = isset($in['activo']) ? (bool) $in['activo'] : true;
    $password = (string) ($in['password'] ?? '');

    validarComunes($email, $nombre, $celular, $rol);
    if ($password === '')               json_error('La contrasena es obligatoria', 422);
    if (mb_strlen($password) < 6)       json_error('La contrasena debe tener al menos 6 caracteres', 422);

    $hash = password_hash($password, PASSWORD_BCRYPT);

    try {
        $stmt = db()->prepare(
            'INSERT INTO usuarios (email, nombre, celular, password_hash, rol, activo)
             VALUES (:e, :n, :c, :p, :r, :a)'
        );
        $stmt->execute([
            ':e' => $email,
            ':n' => $nombre,
            ':c' => $celular === '' ? null : $celular,
            ':p' => $hash,
            ':r' => $rol,
            ':a' => $activo ? 1 : 0,
        ]);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un usuario con ese email', 409);
        }
        throw $e;
    }

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in       = readJson();
    $id       = (int) ($in['id'] ?? 0);
    $email    = strtolower(trim((string) ($in['email']    ?? '')));
    $nombre   = trim((string) ($in['nombre']   ?? ''));
    $celular  = trim((string) ($in['celular']  ?? ''));
    $rol      = trim((string) ($in['rol']      ?? 'operador'));
    $activo   = isset($in['activo']) ? (bool) $in['activo'] : true;
    $password = (string) ($in['password'] ?? '');

    if ($id <= 0) json_error('Id invalido', 422);
    validarComunes($email, $nombre, $celular, $rol);

    if ($password !== '' && mb_strlen($password) < 6) {
        json_error('La contrasena debe tener al menos 6 caracteres', 422);
    }

    $sql    = 'UPDATE usuarios SET email = :e, nombre = :n, celular = :c, rol = :r, activo = :a';
    $params = [
        ':e'  => $email,
        ':n'  => $nombre,
        ':c'  => $celular === '' ? null : $celular,
        ':r'  => $rol,
        ':a'  => $activo ? 1 : 0,
        ':id' => $id,
    ];

    if ($password !== '') {
        $sql           .= ', password_hash = :p';
        $params[':p']   = password_hash($password, PASSWORD_BCRYPT);
    }

    $sql .= ' WHERE id = :id';

    try {
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
    } catch (PDOException $e) {
        if ((int) $e->errorInfo[1] === 1062) {
            json_error('Ya existe un usuario con ese email', 409);
        }
        throw $e;
    }

    if ($stmt->rowCount() === 0) {
        $exists = db()->prepare('SELECT 1 FROM usuarios WHERE id = :id');
        $exists->execute([':id' => $id]);
        if (!$exists->fetchColumn()) json_error('Usuario no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

/**
 * Previsualiza el borrado: cuenta, tabla por tabla, qué se elimina, qué queda
 * huérfano y qué lo bloquea. No modifica nada. El front lo consume para armar
 * el modal de confirmación detallado.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre, usuario, correo FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $usr = $stmt->fetch();
    if (!$usr) json_error('Usuario no encontrado', 404);

    $usr['id'] = (int) $usr['id'];

    $actual = authUser();

    json_ok([
        'usuario'    => $usr,
        // Borrarse a uno mismo invalida la sesión en curso: el front lo bloquea
        // en el modal y handleDelete() lo vuelve a rechazar del lado servidor.
        'es_propio'  => $actual !== null && (int) ($actual['id'] ?? 0) === $id,
        'bloqueos'   => contarDependencias($id, DEP_BLOQUEA),
        'elimina'    => contarDependencias($id, DEP_ELIMINA),
        'desvincula' => contarDependencias($id, DEP_DESVINCULA),
    ]);
}

/**
 * Cuenta las filas que apuntan a $id en cada [tabla, columna, etiqueta] de
 * $defs. Devuelve sólo las que tienen al menos una fila, para que el modal no
 * se llene de ceros.
 */
function contarDependencias(int $id, array $defs): array
{
    $out = [];
    foreach ($defs as [$tabla, $columna, $label]) {
        $n = contarFilas($tabla, $columna, $id);
        if ($n > 0) {
            $out[] = ['tabla' => $tabla, 'columna' => $columna, 'label' => $label, 'cantidad' => $n];
        }
    }
    return $out;
}

function contarFilas(string $tabla, string $columna, int $id): int
{
    // $tabla y $columna salen exclusivamente de las constantes DEP_* de este
    // archivo (nunca del request), por eso se interpolan sin escapar.
    $stmt = db()->prepare("SELECT COUNT(*) FROM `$tabla` WHERE `$columna` = :id");
    $stmt->execute([':id' => $id]);

    return (int) $stmt->fetchColumn();
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === false) json_error('Usuario no encontrado', 404);

    // Un usuario no puede borrarse a si mismo: perderia la sesion en curso y el
    // JWT seguiria siendo valido 12 h apuntando a un id inexistente.
    $actual = authUser();
    if ($actual !== null && (int) ($actual['id'] ?? 0) === $id) {
        json_error('No podes eliminar tu propio usuario', 409);
    }

    // Bloqueos duros: los dejamos al operador en vez de resolverlos por él.
    foreach (DEP_BLOQUEA as [$tabla, $columna, $label]) {
        $n = contarFilas($tabla, $columna, $id);
        if ($n > 0) {
            json_error(
                sprintf('No se puede eliminar: %s (%d). Reasignalas a otro usuario antes de borrarlo.', $label, $n),
                409
            );
        }
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        // El orden importa: `sesiones.perfil` es RESTRICT contra `perfiles`, asi
        // que las sesiones salen primero. Se incluyen las sesiones que apunten a
        // un perfil de este usuario aunque la sesion sea de otro.
        $sql = 'DELETE FROM sesiones
                WHERE usuario = :id
                   OR perfil IN (SELECT id FROM perfiles WHERE usuario = :id2)';
        $del = $pdo->prepare($sql);
        $del->execute([':id' => $id, ':id2' => $id]);
        $sesiones = $del->rowCount();

        // Al borrar los perfiles, `usuarios.perfil` (SET NULL) se limpia solo en
        // cualquier usuario que estuviera apuntando a alguno de ellos.
        $del = $pdo->prepare('DELETE FROM perfiles WHERE usuario = :id');
        $del->execute([':id' => $id]);
        $perfiles = $del->rowCount();

        // Las invitaciones se borran explicitamente: la FK es SET NULL, asi que
        // sin este DELETE quedarian huerfanas en vez de desaparecer. `invitaciones`
        // no es padre de ninguna otra tabla, por eso no condiciona el orden.
        $del = $pdo->prepare('DELETE FROM invitaciones WHERE emisor = :id');
        $del->execute([':id' => $id]);
        $invitaciones = $del->rowCount();

        // `carritos` / `carritositems` caen por CASCADE; el resto de las FKs
        // quedan en NULL por SET NULL. Nada mas que hacer a mano.
        $del = $pdo->prepare('DELETE FROM usuarios WHERE id = :id');
        $del->execute([':id' => $id]);
        if ($del->rowCount() === 0) {
            $pdo->rollBack();
            json_error('Usuario no encontrado', 404);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok([
        'id'           => $id,
        'perfiles'     => $perfiles,
        'sesiones'     => $sesiones,
        'invitaciones' => $invitaciones,
    ]);
}

function validarComunes(string $email, string $nombre, string $celular, string $rol): void
{
    if ($email === '')                          json_error('El email es obligatorio', 422);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) json_error('El email no es valido', 422);
    if (mb_strlen($email) > 120)                json_error('El email no puede superar 120 caracteres', 422);
    if ($nombre === '')                         json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 120)               json_error('El nombre no puede superar 120 caracteres', 422);
    if (mb_strlen($celular) > 30)               json_error('El celular no puede superar 30 caracteres', 422);
    if ($celular !== '' && !preg_match('/^[+0-9\s().-]+$/', $celular)) {
        json_error('El celular solo puede contener numeros, espacios y los signos + ( ) - .', 422);
    }
    if (!in_array($rol, ROLES_VALIDOS, true))   json_error('Rol invalido', 422);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
