<?php

declare(strict_types=1);

/**
 * Contexto de sesion del panel.
 *
 * Al iniciar sesion (api/login.php) se capturan de la cuenta del usuario los
 * datos que definen su alcance dentro del panel y se guardan como claims del
 * JWT. El mas importante es `dominio` (columna `usuarios.dominio`): TODO
 * listado, alta y edicion del panel se acota a ese dominio.
 *
 * El token se comparte con cloud (misma cookie y misma APP_KEY_CLOUD). Un
 * token emitido por cloud NO trae estos claims, asi que sessionContext()
 * cae a la base y resuelve los datos desde `usuarios` una sola vez por
 * request. `origen` indica de donde salieron: 'token' o 'db'.
 */

require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/db.php';

/**
 * Datos de la cuenta vigentes para esta sesion.
 * Devuelve null si no hay sesion valida.
 */
function sessionContext(): ?array
{
    static $ctx    = null;
    static $cached = false;
    if ($cached) {
        return $ctx;
    }
    $cached = true;

    $claims = authUser();
    if ($claims === null) {
        return $ctx;
    }

    $ctx = [
        'id'             => (int) ($claims['id'] ?? 0),
        'usuario'        => (string) ($claims['usuario'] ?? ''),
        'nombre'         => (string) ($claims['nombre'] ?? ''),
        'correo'         => (string) ($claims['correo'] ?? ''),
        'dominio'        => isset($claims['dominio']) ? (int) $claims['dominio'] : null,
        'dominio_nombre' => (string) ($claims['dominio_nombre'] ?? ''),
        'perfil'         => isset($claims['perfil']) ? (int) $claims['perfil'] : null,
        'perfil_nombre'  => (string) ($claims['perfil_nombre'] ?? ''),
        'roles'          => (string) ($claims['roles'] ?? ''),
        'origen'         => array_key_exists('dominio', $claims) ? 'token' : 'db',
    ];

    // Token sin los claims de alcance (emitido por cloud o anterior a este
    // cambio): se completan contra la base para no dejar la sesion sin dominio.
    if ($ctx['origen'] === 'db' && $ctx['id'] > 0) {
        $ctx = array_merge($ctx, sessionCuentaDesdeDb($ctx['id']));
    }

    return $ctx;
}

/**
 * Lee de `usuarios` los datos de alcance de la cuenta. Se usa en el login
 * (para armar los claims) y como fallback de sessionContext().
 */
function sessionCuentaDesdeDb(int $usuarioId): array
{
    $stmt = db()->prepare(
        'SELECT u.dominio, d.nombre AS dominio_nombre,
                u.perfil,  p.nombre AS perfil_nombre,
                u.roles
         FROM usuarios u
         LEFT JOIN dominios d ON d.id = u.dominio
         LEFT JOIN perfiles p ON p.id = u.perfil
         WHERE u.id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $usuarioId]);
    $row = $stmt->fetch();
    if (!$row) {
        return [];
    }

    return [
        'dominio'        => $row['dominio'] !== null ? (int) $row['dominio'] : null,
        'dominio_nombre' => (string) ($row['dominio_nombre'] ?? ''),
        'perfil'         => $row['perfil'] !== null ? (int) $row['perfil'] : null,
        'perfil_nombre'  => (string) ($row['perfil_nombre'] ?? ''),
        'roles'          => (string) ($row['roles'] ?? ''),
    ];
}

/**
 * Dominio con el que se filtra la informacion del panel.
 * null = la cuenta no tiene dominio asignado (NO significa "ver todo").
 */
function sessionDominioId(): ?int
{
    $ctx = sessionContext();
    $id  = $ctx['dominio'] ?? null;
    return ($id !== null && $id > 0) ? $id : null;
}

/**
 * Igual que sessionDominioId() pero corta el request si no hay dominio.
 * Es la forma correcta de arrancar cualquier endpoint que devuelva o
 * modifique datos del dominio: nunca se consulta sin filtro.
 */
function requireDominioId(): int
{
    $id = sessionDominioId();
    if ($id === null) {
        json_error('La cuenta no tiene un dominio asignado. Pedile a un administrador que te asigne uno.', 409);
    }
    return $id;
}
