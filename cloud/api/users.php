<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/usuarios_alta.php';

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
            // ?credencial=1&id=N devuelve la contrasena en claro de un usuario.
            if (isset($_GET['impacto']))         handleImpacto();
            elseif (isset($_GET['credencial']))  handleCredencial();
            else                                 handleList();
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

/**
 * Devuelve la contrasena EN CLARO de un usuario.
 *
 * El modal de edicion la precarga para que el campo abra con la contrasena real
 * en puntos y el ojo pueda revelarla. Es posible porque el cifrado legacy de
 * Reactor es reversible (XOR sumativa + base64 con clave global), no un hash:
 * ver cloud/api/legacy_crypto.php.
 *
 * Se sirve de a un id, y NO dentro de handleList(), a proposito: asi el listado
 * no viaja con las credenciales de todos los usuarios en cada refresco y la
 * contrasena solo sale cuando alguien abre ese usuario puntual.
 *
 * Alcance: lo puede llamar cualquier usuario autenticado, igual que el resto de
 * users.php -- que ya permite crear, editar y borrar usuarios sin mirar el rol.
 * Si mas adelante se agrega control por rol, este endpoint es el primero que lo
 * necesita.
 */
function handleCredencial(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT contrasena FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('Usuario no encontrado', 404);

    json_ok(['password' => reactor_legacy_desencriptar((string) ($row['contrasena'] ?? ''))]);
}

function handleCreate(): void
{
    $in       = readJson();
    $email    = strtolower(trim((string) ($in['email']    ?? '')));
    $nombre   = trim((string) ($in['nombre']   ?? ''));
    $celular  = trim((string) ($in['celular']  ?? ''));
    $rol      = trim((string) ($in['rol']      ?? 'operador'));
    $password = (string) ($in['password'] ?? '');
    // `activo` llega del toggle del formulario pero no se usa en el alta:
    // `habilitado` es una constante ('1'). Se respeta al editar.

    validarComunes($email, $nombre, $celular, $rol);
    if ($password === '')          json_error('La contrasena es obligatoria', 422);
    if (mb_strlen($password) < 6)  json_error('La contrasena debe tener al menos 6 caracteres', 422);
    // `usuarios.contrasena` es varchar(50) y el cifrado legacy es base64:
    // 36 chars de texto plano ya ocupan 48. Se corta antes para no truncar.
    if (mb_strlen($password) > 32) json_error('La contrasena no puede superar 32 caracteres', 422);

    // La tabla no tiene UNIQUE sobre `usuario` ni sobre `correo`, asi que el
    // duplicado se valida aca en vez de esperar el 1062 del motor.
    $dup = db()->prepare('SELECT id FROM usuarios WHERE usuario = :u OR LOWER(correo) = :c LIMIT 1');
    $dup->execute([':u' => $email, ':c' => $email]);
    if ($dup->fetchColumn()) {
        json_error('Ya existe un usuario con ese email', 409);
    }

    // El INSERT no se hace aca: `usuarioAlta()` es el canal unico de alta de
    // cloud. Es quien cifra la contrasena y quien fija las constantes de alta
    // (autenticacion, habilitado, perfiles, dominios, paneles) -- por eso no se
    // le pasa `habilitado`: al crear siempre nace '1'. El toggle Activo del
    // formulario recien tiene efecto al editar.
    $actual = authUser();
    $id     = usuarioAlta(db(), [
        'nombre'      => $nombre,
        // Cloud no pide un nombre de usuario aparte: la credencial es el correo.
        'usuario'     => $email,
        'contrasena'  => $password,
        'correo'      => $email,
        'celular'     => $celular === '' ? null : $celular,
        'roles'       => $rol,
        'registrante' => (int) ($actual['id'] ?? 0),
    ]);

    json_ok(['id' => $id], 201);
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

    if ($password !== '') {
        if (mb_strlen($password) < 6)  json_error('La contrasena debe tener al menos 6 caracteres', 422);
        // Mismo tope que el alta: `contrasena` es varchar(50) y el cifrado
        // legacy es base64, asi que el texto plano no puede pasar de 32 chars.
        if (mb_strlen($password) > 32) json_error('La contrasena no puede superar 32 caracteres', 422);
    }

    $prev = db()->prepare('SELECT usuario, correo FROM usuarios WHERE id = :id');
    $prev->execute([':id' => $id]);
    $actual = $prev->fetch();
    if (!$actual) json_error('Usuario no encontrado', 404);

    // La tabla no tiene UNIQUE sobre `usuario` ni sobre `correo` (igual que en el
    // alta), asi que el duplicado se valida aca: el motor nunca tira 1062.
    $dup = db()->prepare(
        'SELECT id FROM usuarios
         WHERE id <> :id AND (LOWER(correo) = :c OR LOWER(usuario) = :u)
         LIMIT 1'
    );
    $dup->execute([':id' => $id, ':c' => $email, ':u' => $email]);
    if ($dup->fetchColumn()) {
        json_error('Ya existe un usuario con ese email', 409);
    }

    // Nombres reales de db/schema.sql: correo, roles, habilitado, contrasena.
    // El front manda email/rol/activo/password (ver el alias de handleList()).
    $sql    = 'UPDATE usuarios SET correo = :e, nombre = :n, celular = :c, roles = :r, habilitado = :a';
    $params = [
        ':e'  => $email,
        ':n'  => $nombre,
        ':c'  => $celular === '' ? null : $celular,
        ':r'  => $rol,
        // `habilitado` es varchar(1) y login.php acepta ['S','1','Y']: se escribe
        // '1', que es la convencion de la tabla.
        ':a'  => $activo ? '1' : '0',
        ':id' => $id,
    ];

    // `usuario` es la credencial de login (api/login.php hace WHERE usuario = :u)
    // y el alta de cloud la crea igual al correo. Se la arrastra en dos casos,
    // medidos sobre los 2083 usuarios de la tabla:
    //
    //   - Coincide con el correo anterior (1926 filas): es la convencion del
    //     alta. Sin arrastrarla, cambiar el email dejaria al usuario sin acceso.
    //   - Esta vacia (140 filas): hoy esos usuarios no pueden loguearse con
    //     ninguna credencial, asi que completarla los recupera.
    //
    // Las 17 restantes tienen un nombre de usuario propio, distinto del correo:
    // esas NO se tocan, o se les romperia el login que ya usan.
    $usuarioPrevio = strtolower(trim((string) $actual['usuario']));
    if ($usuarioPrevio === '' || $usuarioPrevio === strtolower(trim((string) $actual['correo']))) {
        $sql          .= ', usuario = :us';
        $params[':us'] = $email;
    }

    if ($password !== '') {
        // Cifrado legacy de Reactor, el mismo que valida el login. NO bcrypt:
        // ver cloud/api/legacy_crypto.php.
        $sql          .= ', contrasena = :p';
        $params[':p']  = reactor_legacy_encriptar($password);
    }

    $sql .= ' WHERE id = :id';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

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
