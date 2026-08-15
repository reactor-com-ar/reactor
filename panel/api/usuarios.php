<?php

declare(strict_types=1);

/**
 * ABM de `usuarios` (esquema real en db/schema.sql).
 *
 *   GET    api/usuarios.php            -> listado + resumen + perfiles del dominio
 *   GET    api/usuarios.php?id=N       -> un registro (todos los campos visibles)
 *   POST   api/usuarios.php            -> alta
 *   PUT    api/usuarios.php            -> modificacion
 *   DELETE api/usuarios.php?id=N       -> baja
 *
 * ALCANCE: todo se acota al dominio de la sesion (requireDominioId()). Ningun
 * query corre sin ese filtro, ni siquiera el lookup por id.
 *
 * CREDENCIALES: `contrasena` y `clave` nunca se devuelven. La contrasena se
 * guarda con el cifrado historico (reactor_legacy_encriptar), que es
 * reversible con la clave global — exponerla seria filtrarla en claro.
 */

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/legacy_crypto.php';

const ORDEN_VALIDO = ['id', 'usuario', 'nombre', 'correo', 'registrado', 'ingresado'];
const MAX_LIMITE   = 1000;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            isset($_GET['id']) ? handleGet((int) $_GET['id']) : handleList();
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

/* ------------------------------------------------------------------ */
/* Listado                                                            */
/* ------------------------------------------------------------------ */

function handleList(): void
{
    $dominio = requireDominioId();

    $q      = trim((string) ($_GET['q']      ?? ''));
    $codigo = (int)         ($_GET['codigo'] ?? 0);
    $perfil = (int)         ($_GET['perfil'] ?? 0);
    $estado = (string)      ($_GET['estado'] ?? 'todos');
    $limite = (int)         ($_GET['limite'] ?? 100);
    $orden  = (string)      ($_GET['orden']  ?? 'id');
    $dir    = strtolower((string) ($_GET['dir'] ?? 'desc')) === 'asc' ? 'ASC' : 'DESC';

    if ($limite <= 0)          $limite = 100;
    if ($limite > MAX_LIMITE)  $limite = MAX_LIMITE;
    if (!in_array($orden, ORDEN_VALIDO, true)) $orden = 'id';

    $where  = ['u.dominio = :dom'];
    $params = [':dom' => $dominio];

    if ($codigo > 0) {
        $where[]        = 'u.id = :cod';
        $params[':cod'] = $codigo;
    }
    if ($perfil > 0) {
        $where[]         = 'u.perfil = :perf';
        $params[':perf'] = $perfil;
    }
    if ($estado === 'habilitados') {
        $where[] = "UPPER(COALESCE(u.habilitado,'')) IN ('S','1','Y')";
    } elseif ($estado === 'deshabilitados') {
        $where[] = "UPPER(COALESCE(u.habilitado,'')) NOT IN ('S','1','Y')";
    }
    if ($q !== '') {
        $where[]      = '(u.usuario LIKE :q OR u.nombre LIKE :q OR u.correo LIKE :q OR u.celular LIKE :q)';
        $params[':q'] = '%' . $q . '%';
    }

    $sql = 'SELECT u.id, u.uuid, u.nombre, u.usuario, u.correo, u.celular,
                   u.habilitado, u.registrado, u.ingresado, u.roles,
                   u.perfil, p.nombre AS perfil_nombre
            FROM usuarios u
            LEFT JOIN perfiles p ON p.id = u.perfil
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY u.' . $orden . ' ' . $dir . '
            LIMIT ' . $limite;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $usuarios = array_map('mapUsuario', $stmt->fetchAll());

    // Resumen sobre el dominio completo, no sobre la pagina devuelta.
    $res = db()->prepare(
        "SELECT COUNT(*) AS total,
                SUM(CASE WHEN UPPER(COALESCE(habilitado,'')) IN ('S','1','Y') THEN 1 ELSE 0 END) AS habilitados
         FROM usuarios WHERE dominio = :dom"
    );
    $res->execute([':dom' => $dominio]);
    $r = $res->fetch() ?: ['total' => 0, 'habilitados' => 0];

    json_ok([
        'usuarios' => $usuarios,
        'perfiles' => perfilesDelDominio($dominio),
        'resumen'  => [
            'total'          => (int) $r['total'],
            'habilitados'    => (int) $r['habilitados'],
            'deshabilitados' => (int) $r['total'] - (int) $r['habilitados'],
            'mostrados'      => count($usuarios),
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
        'SELECT u.id, u.uuid, u.nombre, u.usuario, u.correo, u.celular,
                u.habilitado, u.autenticacion, u.registrante, u.registrado,
                u.ingresado, u.roles, u.perfil, u.dominio, u.panel,
                p.nombre AS perfil_nombre,
                d.nombre AS dominio_nombre,
                r.nombre AS registrante_nombre
         FROM usuarios u
         LEFT JOIN perfiles p ON p.id = u.perfil
         LEFT JOIN dominios d ON d.id = u.dominio
         LEFT JOIN usuarios r ON r.id = u.registrante
         WHERE u.id = :id AND u.dominio = :dom
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':dom' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        json_error('Usuario no encontrado en este dominio', 404);
    }

    json_ok(['usuario' => mapUsuario($row)]);
}

/* ------------------------------------------------------------------ */
/* Alta / Modificacion / Baja                                          */
/* ------------------------------------------------------------------ */

function handleCreate(): void
{
    $dominio = requireDominioId();
    $in      = readJson();

    $datos      = validar($in, $dominio, null);
    $contrasena = (string) ($in['contrasena'] ?? '');
    if ($contrasena === '') {
        json_error('La contrasena es obligatoria', 422);
    }
    validarContrasena($contrasena);

    $ctx  = sessionContext() ?? [];
    $stmt = db()->prepare(
        'INSERT INTO usuarios
            (uuid, nombre, usuario, contrasena, correo, celular, habilitado,
             perfil, dominio, roles, registrante, registrado, autenticacion)
         VALUES
            (:uuid, :nombre, :usuario, :contrasena, :correo, :celular, :habilitado,
             :perfil, :dominio, :roles, :registrante, NOW(), :autenticacion)'
    );
    $stmt->execute([
        ':uuid'          => bin2hex(random_bytes(8)),
        ':nombre'        => $datos['nombre'],
        ':usuario'       => $datos['usuario'],
        ':contrasena'    => reactor_legacy_encriptar($contrasena),
        ':correo'        => $datos['correo'],
        ':celular'       => $datos['celular'],
        ':habilitado'    => $datos['habilitado'],
        ':perfil'        => $datos['perfil'],
        ':dominio'       => $dominio,
        ':roles'         => $datos['roles'],
        ':registrante'   => (int) ($ctx['id'] ?? 0) ?: null,
        ':autenticacion' => 'L',
    ]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
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
    $own = db()->prepare('SELECT id FROM usuarios WHERE id = :id AND dominio = :dom LIMIT 1');
    $own->execute([':id' => $id, ':dom' => $dominio]);
    if (!$own->fetchColumn()) {
        json_error('Usuario no encontrado en este dominio', 404);
    }

    $datos      = validar($in, $dominio, $id);
    $contrasena = (string) ($in['contrasena'] ?? '');

    $sql = 'UPDATE usuarios
               SET nombre = :nombre, usuario = :usuario, correo = :correo,
                   celular = :celular, habilitado = :habilitado,
                   perfil = :perfil, roles = :roles';
    $params = [
        ':nombre'     => $datos['nombre'],
        ':usuario'    => $datos['usuario'],
        ':correo'     => $datos['correo'],
        ':celular'    => $datos['celular'],
        ':habilitado' => $datos['habilitado'],
        ':perfil'     => $datos['perfil'],
        ':roles'      => $datos['roles'],
        ':id'         => $id,
        ':dom'        => $dominio,
    ];

    // Contrasena vacia = no se toca.
    if ($contrasena !== '') {
        validarContrasena($contrasena);
        $sql                  .= ', contrasena = :contrasena';
        $params[':contrasena'] = reactor_legacy_encriptar($contrasena);
    }

    $sql .= ' WHERE id = :id AND dominio = :dom';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $dominio = requireDominioId();
    $id      = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) {
        json_error('Codigo invalido', 422);
    }

    $ctx = sessionContext() ?? [];
    if ($id === (int) ($ctx['id'] ?? 0)) {
        json_error('No podes eliminar tu propio usuario', 409);
    }

    $stmt = db()->prepare('DELETE FROM usuarios WHERE id = :id AND dominio = :dom');
    $stmt->execute([':id' => $id, ':dom' => $dominio]);

    if ($stmt->rowCount() === 0) {
        json_error('Usuario no encontrado en este dominio', 404);
    }

    json_ok(['id' => $id]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Normaliza una fila de `usuarios` para el front. Nunca incluye credenciales. */
function mapUsuario(array $r): array
{
    $hab = strtoupper(trim((string) ($r['habilitado'] ?? '')));

    $out = [
        'id'            => (int) $r['id'],
        'uuid'          => (string) ($r['uuid'] ?? ''),
        'nombre'        => (string) ($r['nombre'] ?? ''),
        'usuario'       => (string) ($r['usuario'] ?? ''),
        'correo'        => (string) ($r['correo'] ?? ''),
        'celular'       => (string) ($r['celular'] ?? ''),
        'habilitado'    => in_array($hab, ['S', '1', 'Y'], true),
        'perfil'        => isset($r['perfil']) && $r['perfil'] !== null ? (int) $r['perfil'] : null,
        'perfil_nombre' => (string) ($r['perfil_nombre'] ?? ''),
        'roles'         => (string) ($r['roles'] ?? ''),
        'registrado'    => (string) ($r['registrado'] ?? ''),
        'ingresado'     => (string) ($r['ingresado'] ?? ''),
    ];

    // Campos que solo trae el GET por id (modal de Consulta).
    foreach (['autenticacion', 'dominio_nombre', 'registrante_nombre'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = (string) ($r[$extra] ?? '');
        }
    }
    foreach (['dominio', 'registrante', 'panel'] as $extra) {
        if (array_key_exists($extra, $r)) {
            $out[$extra] = $r[$extra] !== null ? (int) $r[$extra] : null;
        }
    }

    return $out;
}

function perfilesDelDominio(int $dominio): array
{
    $stmt = db()->prepare(
        'SELECT id, nombre FROM perfiles WHERE dominio = :dom ORDER BY nombre ASC'
    );
    $stmt->execute([':dom' => $dominio]);

    return array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => (string) ($r['nombre'] ?? ''),
    ], $stmt->fetchAll());
}

/** Valida y normaliza el payload de alta/edicion. Corta con 422 si algo falla. */
function validar(array $in, int $dominio, ?int $idActual): array
{
    $nombre  = trim((string) ($in['nombre']  ?? ''));
    $usuario = trim((string) ($in['usuario'] ?? ''));
    $correo  = trim((string) ($in['correo']  ?? ''));
    $celular = trim((string) ($in['celular'] ?? ''));
    $roles   = trim((string) ($in['roles']   ?? ''));
    $perfil  = (int) ($in['perfil'] ?? 0);

    if ($nombre === '')             json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 100)   json_error('El nombre no puede superar 100 caracteres', 422);
    if ($usuario === '')            json_error('El usuario es obligatorio', 422);
    if (mb_strlen($usuario) > 100)  json_error('El usuario no puede superar 100 caracteres', 422);
    if (!preg_match('/^[A-Za-z0-9._@-]+$/', $usuario)) {
        json_error('El usuario solo admite letras, numeros y . _ - @', 422);
    }
    if ($correo !== '' && !filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        json_error('El correo no es valido', 422);
    }
    if (mb_strlen($correo) > 100)   json_error('El correo no puede superar 100 caracteres', 422);
    if (mb_strlen($celular) > 15)   json_error('El celular no puede superar 15 caracteres', 422);
    if ($celular !== '' && !preg_match('/^[+0-9\s().-]+$/', $celular)) {
        json_error('El celular solo admite numeros, espacios y los signos + ( ) - .', 422);
    }
    if (mb_strlen($roles) > 255)    json_error('Los roles no pueden superar 255 caracteres', 422);

    // `usuario` es la credencial de login: unico en toda la tabla, no solo
    // dentro del dominio. La DB no tiene UNIQUE, asi que se valida aca.
    $dup = db()->prepare('SELECT id FROM usuarios WHERE usuario = :u AND id <> :id LIMIT 1');
    $dup->execute([':u' => $usuario, ':id' => $idActual ?? 0]);
    if ($dup->fetchColumn()) {
        json_error('Ya existe un usuario con ese nombre de usuario', 409);
    }

    // El perfil tiene que pertenecer al mismo dominio.
    if ($perfil > 0) {
        $chk = db()->prepare('SELECT id FROM perfiles WHERE id = :p AND dominio = :dom LIMIT 1');
        $chk->execute([':p' => $perfil, ':dom' => $dominio]);
        if (!$chk->fetchColumn()) {
            json_error('El perfil no pertenece a este dominio', 422);
        }
    }

    return [
        'nombre'     => $nombre,
        'usuario'    => $usuario,
        'correo'     => $correo === '' ? null : $correo,
        'celular'    => $celular === '' ? null : $celular,
        'roles'      => $roles === '' ? null : $roles,
        'perfil'     => $perfil > 0 ? $perfil : null,
        'habilitado' => !empty($in['habilitado']) ? 'S' : 'N',
    ];
}

function validarContrasena(string $contrasena): void
{
    if (mb_strlen($contrasena) < 4) {
        json_error('La contrasena debe tener al menos 4 caracteres', 422);
    }
    // `usuarios.contrasena` es varchar(50) y el cifrado legacy es base64:
    // 36 chars de texto plano ya ocupan 48. Se corta antes para no truncar.
    if (mb_strlen($contrasena) > 32) {
        json_error('La contrasena no puede superar 32 caracteres', 422);
    }
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
