<?php

declare(strict_types=1);

/**
 * ABM de `permisos` (db/schema.sql): el catalogo de permisos de Reactor Cloud.
 * Columnas reales: id, slug, nombre, descripcion.
 *
 * `slug` ES EL IDENTIFICADOR CON EL QUE EL CODIGO VA A PEDIR EL PERMISO cuando
 * se implemente el control de acceso: `puede('usuarios.consultar')`. `nombre`
 * es la ruta legible del menu y no sirve para eso — tiene mayusculas, espacios,
 * acentos y un separador de tres caracteres. Es UNIQUE y obligatorio.
 *
 * EL SLUG NO SE REGENERA AL RENOMBRAR. Se propone desde el nombre en el alta
 * (lo hace el front), pero una vez guardado es un identificador: si se
 * recalculara al editar el nombre, cambiarle el texto a un permiso romperia en
 * silencio todas las condiciones que ya lo referencian. Renombrar y re-slugear
 * son dos decisiones distintas y el formulario las deja separadas.
 *
 * LOS PERMISOS SON DE CLOUD Y DE NADIE MAS. Hasta el 06/09/2026 la tabla tenia
 * una columna `sistema` (A = Reactor Admin, C = Reactor Control, P = Reactor
 * App) que repartia cada permiso entre los tres productos del sistema
 * historico. Ese reparto se elimino con la migracion
 * `20260906_1300_permisos_solo_cloud.sql`: no hay permisos de otros sistemas,
 * asi que no hay nada que distinguir.
 *
 * `nombre` NO es una etiqueta libre: es la ruta del permiso dentro del menu,
 * con ` > ` de separador (`Usuarios > Consultar`, `Dispositivos > Dispositivo
 * > Editar`). El front la usa para ordenar y buscar, asi que el separador es
 * parte del formato y no cosmetica.
 *
 * QUIEN CONSUME LOS PERMISOS: `roles_permisos`, la tabla puente con FK contra
 * `permisos`.`id`. Borrar un permiso limpia sus filas solo, por
 * `ON DELETE CASCADE` — pero el modal de baja igual muestra a que roles va a
 * tocar antes de confirmar, porque perder un permiso les cambia el alcance.
 * Hasta el 06/09/2026 habia ademas un varchar `roles`.`permisos` con la lista
 * de ids que este endpoint tenia que reescribir a mano; esa columna ya no
 * existe y con ella se fue toda esa maquinaria.
 */

require __DIR__ . '/bootstrap.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            // ?impacto=1&id=N devuelve que roles quedan tocados por el borrado.
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
    json_error('Error al procesar permisos: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // Cuantos roles usan cada permiso, de una sola pasada indexada sobre la
    // tabla puente. Antes esto era parsear el varchar de los 10 roles en PHP.
    $usos = [];
    foreach (db()->query('SELECT permiso, COUNT(*) AS n FROM roles_permisos GROUP BY permiso') as $r) {
        $usos[(int) $r['permiso']] = (int) $r['n'];
    }

    $stmt = db()->query(
        'SELECT id, slug, nombre, descripcion
           FROM permisos
          ORDER BY slug ASC, id ASC'
    );

    $permisos = array_map(static function (array $r) use ($usos): array {
        return [
            'id'          => (int) $r['id'],
            'slug'        => (string) ($r['slug'] ?? ''),
            'nombre'      => (string) ($r['nombre'] ?? ''),
            'descripcion' => (string) ($r['descripcion'] ?? ''),
            'roles_count' => $usos[(int) $r['id']] ?? 0,
        ];
    }, $stmt->fetchAll());

    $resumen = ['total' => count($permisos), 'en_uso' => 0, 'sin_uso' => 0];
    foreach ($permisos as $p) {
        if ($p['roles_count'] > 0) $resumen['en_uso']++;
        else                       $resumen['sin_uso']++;
    }

    json_ok(['permisos' => $permisos, 'resumen' => $resumen]);
}

/**
 * Roles afectados por borrar un permiso. Informativo: la limpieza la hace la FK
 * (`ON DELETE CASCADE`), pero el operador tiene que ver a quien le cambia el
 * alcance antes de confirmar (ABM.md §Eliminar).
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre FROM permisos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $permiso = $stmt->fetch();
    if (!$permiso) json_error('Permiso no encontrado', 404);

    json_ok([
        'permiso' => ['id' => (int) $permiso['id'], 'nombre' => (string) $permiso['nombre']],
        // Lo que se conserva sin la referencia: los roles sobreviven, solo
        // pierden este permiso. No hay nada que se elimine en cascada salvo la
        // propia fila de `roles_permisos`.
        'roles'   => rolesQueUsan($id),
    ]);
}

function handleCreate(): void
{
    $datos = validarPayload(readJson());

    // El slug tiene UNIQUE en la tabla, pero el duplicado se chequea igual antes
    // del INSERT: asi el operador recibe un mensaje que nombra el conflicto y no
    // un 1062 traducido a "error al procesar".
    if (slugTomado($datos['slug'], 0)) {
        json_error('Ya existe un permiso con el slug «' . $datos['slug'] . '»', 409);
    }

    $stmt = db()->prepare('INSERT INTO permisos (slug, nombre, descripcion) VALUES (:s, :n, :d)');
    $stmt->execute([':s' => $datos['slug'], ':n' => $datos['nombre'], ':d' => $datos['descripcion']]);

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $datos = validarPayload($in);

    if (slugTomado($datos['slug'], $id)) {
        json_error('Ya existe un permiso con el slug «' . $datos['slug'] . '»', 409);
    }

    $stmt = db()->prepare('UPDATE permisos SET slug = :s, nombre = :n, descripcion = :d WHERE id = :id');
    $stmt->execute([':s' => $datos['slug'], ':n' => $datos['nombre'], ':d' => $datos['descripcion'], ':id' => $id]);

    if ($stmt->rowCount() === 0) {
        $existe = db()->prepare('SELECT 1 FROM permisos WHERE id = :id');
        $existe->execute([':id' => $id]);
        if (!$existe->fetchColumn()) json_error('Permiso no encontrado', 404);
    }

    json_ok(['id' => $id]);
}

function slugTomado(string $slug, int $exceptoId): bool
{
    $stmt = db()->prepare('SELECT id FROM permisos WHERE slug = :s AND id <> :id LIMIT 1');
    $stmt->execute([':s' => $slug, ':id' => $exceptoId]);

    return (bool) $stmt->fetchColumn();
}

/**
 * Borra el permiso. Las filas de `roles_permisos` se van solas por la FK
 * `ON DELETE CASCADE`: no hace falta transaccion ni limpieza manual.
 */
function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM permisos WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Permiso no encontrado', 404);

    json_ok(['id' => $id]);
}

/** Roles que tienen este permiso, para el desglose del borrado. */
function rolesQueUsan(int $idPermiso): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.nombre
           FROM roles_permisos rp
           JOIN roles r ON r.id = rp.rol
          WHERE rp.permiso = :p
          ORDER BY r.nombre ASC'
    );
    $stmt->execute([':p' => $idPermiso]);

    return array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => (string) $r['nombre'],
    ], $stmt->fetchAll());
}

function validarPayload(array $in): array
{
    $slug        = strtolower(trim((string) ($in['slug']        ?? '')));
    $nombre      = trim((string) ($in['nombre']      ?? ''));
    $descripcion = trim((string) ($in['descripcion'] ?? ''));

    if ($nombre === '')            json_error('El nombre es obligatorio', 422);
    if (mb_strlen($nombre) > 255)  json_error('El nombre no puede superar 255 caracteres', 422);
    if (mb_strlen($descripcion) > 255)
        json_error('La descripcion no puede superar 255 caracteres', 422);

    // El slug se valida estricto porque va a terminar escrito a mano dentro de
    // una condicion: minusculas, digitos, `.` de jerarquia y `-` de separador de
    // palabras. Nada de acentos, espacios ni mayusculas — un identificador que
    // hay que recordar como se acentuaba no es un identificador.
    if ($slug === '')             json_error('El slug es obligatorio', 422);
    if (strlen($slug) > 150)      json_error('El slug no puede superar 150 caracteres', 422);
    if (!preg_match('/^[a-z0-9]+([.-][a-z0-9]+)*$/', $slug)) {
        json_error('El slug solo admite minusculas, numeros, puntos y guiones, sin empezar ni terminar en separador (ej.: usuarios.consultar)', 422);
    }

    return [
        'slug'        => $slug,
        'nombre'      => $nombre,
        'descripcion' => $descripcion === '' ? null : $descripcion,
    ];
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
