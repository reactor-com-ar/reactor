<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

const ROLES_VALIDOS = ['admin', 'operador', 'lectura'];

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

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $row = db()->prepare('SELECT rol FROM usuarios WHERE id = :id');
    $row->execute([':id' => $id]);
    $rol = $row->fetchColumn();
    if ($rol === false) json_error('Usuario no encontrado', 404);

    if ($rol === 'admin') {
        $admins = (int) db()->query("SELECT COUNT(*) FROM usuarios WHERE rol = 'admin' AND activo = 1")->fetchColumn();
        if ($admins <= 1) {
            json_error('No se puede eliminar al unico administrador activo', 409);
        }
    }

    $stmt = db()->prepare('DELETE FROM usuarios WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Usuario no encontrado', 404);

    json_ok(['id' => $id]);
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
