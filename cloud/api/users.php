<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once dirname(__DIR__) . '/lib/usuarios_alta.php';

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
    // `perfiles`.`registrante` (20260907_1100) es la otra mitad del par: la de
    // arriba cuenta las CUENTAS que dio de alta y ésta los ACCESOS que otorgó.
    // Son accesos de OTRAS personas —no los suyos, que van en DEP_ELIMINA por
    // `perfiles`.`usuario`—, así que sobreviven al borrado y sólo pierden la
    // autoría. Por eso la FK es `SET NULL`: con `RESTRICT` habría que
    // reasignarlos uno por uno antes de poder borrar la cuenta.
    ['perfiles',     'registrante', 'Perfiles que registró'],
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
    // Esquema real (db/schema.sql -> tabla `usuarios`): correo, habilitado,
    // ingresado, registrado. Se aliasan a los nombres que ya usa el front
    // (email, last_login_at, created_at) para no tocar el JS.
    //
    // YA NO HAY ROL. `usuarios`.`roles` se elimino el 06/09/2026 junto con el
    // resto del alcance historico (`20260906_1900_usuarios_sin_legacy.sql`):
    // los roles pasaron a ser un concepto de cloud (`controladores_roles`) y un
    // usuario final no tiene uno. Lo que define su alcance son sus `perfiles`.
    // `usuario` viaja en el listado —no como la contrasena, que sale de a un id
    // por handleCredencial()— porque no es un secreto: es el nombre con el que
    // la persona entra, y lo consumen los dos modales (Consultar y Edicion) sin
    // pedir nada extra.
    $stmt = db()->query(
        "SELECT id,
                correo     AS email,
                nombre,
                usuario,
                celular,
                habilitado,
                ingresado  AS last_login_at,
                registrado AS created_at
         FROM usuarios
         ORDER BY habilitado DESC, nombre ASC"
    );

    $usuarios = array_map(static function (array $r): array {
        $r['activo'] = esHabilitado($r['habilitado'] ?? 0);
        unset($r['habilitado']);
        return $r;
    }, $stmt->fetchAll());

    $resumen = ['total' => count($usuarios), 'activos' => 0, 'inactivos' => 0];
    foreach ($usuarios as $u) {
        if ($u['activo']) $resumen['activos']++;
        else              $resumen['inactivos']++;
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
    $usuario  = credencialPedida($in, $email);
    $password = (string) ($in['password'] ?? '');
    // `activo` llega del toggle del formulario pero no se usa en el alta:
    // `habilitado` es una constante (1). Se respeta al editar.

    validarComunes($email, $nombre, $celular);
    validarUsuario($usuario);
    if ($password === '')          json_error('La contrasena es obligatoria', 422);
    if (mb_strlen($password) < 6)  json_error('La contrasena debe tener al menos 6 caracteres', 422);
    // `usuarios.contrasena` es varchar(50) y el cifrado legacy es base64:
    // 36 chars de texto plano ya ocupan 48. Se corta antes para no truncar.
    if (mb_strlen($password) > 32) json_error('La contrasena no puede superar 32 caracteres', 422);

    validarDuplicados($email, $usuario);

    // El INSERT no se hace aca: `usuarioAlta()` es el canal unico de alta de
    // cloud. Es quien cifra la contrasena y quien fija las constantes de alta
    // (autenticacion, habilitado, perfiles, dominios, paneles) -- por eso no se
    // le pasa `habilitado`: al crear siempre nace 1. El toggle Activo del
    // formulario recien tiene efecto al editar.
    // `registrante` VA EN NULL Y NO EN EL ID DE LA SESION. Es una FK contra
    // `usuarios`.`id`, y desde que el login de cloud valida contra
    // `controladores` (07/09/2026) quien esta logueado NO es una fila de
    // `usuarios`: escribir su id ahi apuntaria a otra persona real que casualmente
    // tiene ese numero. Un alta hecha desde cloud no la registro ningun usuario
    // — la registro Reactor —, y eso es exactamente lo que dice el NULL.
    $id = usuarioAlta(db(), [
        'nombre'      => $nombre,
        // El formulario lo trae explicito, y llega igual al correo salvo que
        // alguien lo haya editado: el campo `Usuario` del modal espeja el email
        // mientras no se lo toque. Si el request no lo manda —cliente viejo—,
        // `credencialPedida()` cae al correo, que es la convencion de siempre.
        'usuario'     => $usuario,
        'contrasena'  => $password,
        'correo'      => $email,
        'celular'     => $celular === '' ? null : $celular,
        'registrante' => null,
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
    $activo   = isset($in['activo']) ? (bool) $in['activo'] : true;
    $usuario  = credencialPedida($in, $email);
    $password = (string) ($in['password'] ?? '');

    if ($id <= 0) json_error('Id invalido', 422);
    validarComunes($email, $nombre, $celular);
    validarUsuario($usuario);

    if ($password !== '') {
        if (mb_strlen($password) < 6)  json_error('La contrasena debe tener al menos 6 caracteres', 422);
        // Mismo tope que el alta: `contrasena` es varchar(50) y el cifrado
        // legacy es base64, asi que el texto plano no puede pasar de 32 chars.
        if (mb_strlen($password) > 32) json_error('La contrasena no puede superar 32 caracteres', 422);
    }

    $prev = db()->prepare('SELECT id FROM usuarios WHERE id = :id');
    $prev->execute([':id' => $id]);
    if ($prev->fetchColumn() === false) json_error('Usuario no encontrado', 404);

    validarDuplicados($email, $usuario, $id);

    // Nombres reales de db/schema.sql: correo, habilitado, contrasena.
    // El front manda email/activo/password (ver el alias de handleList()).
    //
    // `usuario` SE ESCRIBE SIEMPRE CON LO QUE MANDA EL FORMULARIO. Hasta el
    // 15/09/2026 la columna no se editaba: el modal no la mostraba y este
    // endpoint le arrastraba el correo nuevo cuando la credencial vieja
    // coincidia con el correo viejo o estaba vacia (2066 de las 2083 filas),
    // dejando en paz las 17 con credencial propia. El arrastre resolvia el caso
    // correcto pero a ciegas — quien cambiaba el email no tenia como saber que
    // estaba cambiando tambien la credencial de ingreso, ni como NO cambiarla.
    // Ahora el campo esta a la vista al lado de la contrasena y sigue al email
    // solo mientras nadie lo edite (ver openUserModal() en assets/js/app.js), asi
    // que la decision se toma en la pantalla y aca se obedece.
    $sql    = 'UPDATE usuarios SET correo = :e, nombre = :n, celular = :c, habilitado = :a, usuario = :us';
    $params = [
        ':e'  => $email,
        ':n'  => $nombre,
        ':c'  => $celular === '' ? null : $celular,
        // `habilitado` es tinyint(1) NOT NULL con dos valores: 1 y 0. Se
        // escribe el entero, nunca el booleano de PHP (PDO lo bindearia como
        // cadena vacia). Ver lib/habilitado.php.
        ':a'  => valorHabilitado($activo),
        ':us' => $usuario,
        ':id' => $id,
    ];

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

    json_ok([
        'usuario'    => $usr,
        // SIEMPRE false desde el 07/09/2026, y la clave se conserva porque el
        // front la lee. Antes comparaba el id de la sesion contra este usuario
        // para impedir que alguien se borrara a si mismo; ahora el login valida
        // contra `controladores`, asi que quien esta logueado nunca es una fila
        // de `usuarios` y la comparacion no solo sobra: seria un falso positivo
        // que bloquea borrar al usuario cuyo id coincide con el del controlador.
        'es_propio'  => false,
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

    // ACA HABIA UN "no podes eliminar tu propio usuario". Se saco el 07/09/2026,
    // cuando el login paso a validar contra `controladores`: quien esta logueado
    // dejo de ser una fila de `usuarios`, asi que el caso que protegia —quedarse
    // sin sesion por borrarse— ya no existe, y la comparacion de ids habria
    // bloqueado borrar al usuario cuyo id coincidiera con el del controlador.
    // El equivalente vivo es `garantizarQueQuedaAlguien()` en
    // api/controladores.php, que impide vaciar la lista de quienes entran.

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

function validarComunes(string $email, string $nombre, string $celular): void
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
}

/**
 * Lee la credencial de ingreso del request, con el correo como respaldo.
 *
 * SE NORMALIZA A MINUSCULAS igual que el correo. `usuarios`.`usuario` es
 * utf8mb4_unicode_ci, asi que el login ya compara sin distinguir mayusculas y
 * guardar la variante tipeada no cambiaria quien entra: lo unico que haria es
 * romper la igualdad byte a byte con `correo` que tienen 2065 de las 2083 filas.
 *
 * EL RESPALDO NO ES DECORATIVO. Si el request no trae `usuario` —un navegador
 * con el JS viejo en cache, o cualquier cliente que siga mandando el payload
 * anterior— se usa el correo, que es exactamente lo que escribian el alta y el
 * arrastre de handleUpdate() antes de que la columna fuera editable. Sin esto un
 * PUT viejo dejaria la cuenta sin credencial y sin acceso.
 */
function credencialPedida(array $in, string $email): string
{
    $usuario = strtolower(trim((string) ($in['usuario'] ?? '')));

    return $usuario !== '' ? $usuario : $email;
}

function validarUsuario(string $usuario): void
{
    if ($usuario === '')               json_error('El usuario es obligatorio', 422);
    // `usuarios`.`usuario` es varchar(100). El email se valida a 120 por
    // historia, pero la credencial no puede pasar del ancho de su columna o
    // MySQL la truncaria y la persona quedaria afuera.
    if (mb_strlen($usuario) > 100)     json_error('El usuario no puede superar 100 caracteres', 422);
    // Ninguna de las 2083 filas tiene un espacio, y con razon: el login compara
    // la columna contra lo tipeado tal cual (`WHERE usuario = :u`), asi que un
    // espacio invisible al final es una cuenta que no entra y nadie sabe por que.
    if (preg_match('/\s/u', $usuario)) json_error('El usuario no puede tener espacios', 422);
}

/**
 * Corta si otra fila ya tiene ese correo o esa credencial de ingreso.
 *
 * `usuarios` no tiene UNIQUE sobre ninguna de las dos columnas, asi que el motor
 * nunca tira 1062 y el duplicado se busca a mano. Se compara en minusculas y sin
 * espacios de borde porque asi es como entran los dos datos por el formulario.
 *
 * SON DOS PREGUNTAS DISTINTAS Y POR ESO SON DOS MENSAJES: desde que `usuario` se
 * edita aparte, el correo puede chocar con el de otro sin que la credencial
 * choque con nada, y al reves. Antes se comparaba el correo contra las dos
 * columnas —tenia sentido cuando la credencial era siempre el correo— y el unico
 * mensaje posible hablaba del email.
 *
 * $excluirId es el id que se esta editando; en el alta va 0, que no existe.
 */
function validarDuplicados(string $email, string $usuario, int $excluirId = 0): void
{
    $stmt = db()->prepare(
        'SELECT LOWER(TRIM(correo)) AS correo, LOWER(TRIM(usuario)) AS usuario
           FROM usuarios
          WHERE id <> :id
            AND (LOWER(TRIM(correo)) = :c OR LOWER(TRIM(usuario)) = :u)
          LIMIT 2'
    );
    $stmt->execute([':id' => $excluirId, ':c' => $email, ':u' => $usuario]);

    $choques = $stmt->fetchAll();
    if (!$choques) return;

    foreach ($choques as $fila) {
        if ($fila['correo'] === $email) json_error('Ya existe un usuario con ese email', 409);
    }

    json_error('Ya existe un usuario con esa credencial de ingreso', 409);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
