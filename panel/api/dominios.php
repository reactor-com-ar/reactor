<?php

declare(strict_types=1);

/**
 * Dominios disponibles para el usuario logueado, y cambio de dominio.
 *
 *   GET  api/dominios.php                  -> perfiles de la cuenta, uno por dominio/rol
 *   POST api/dominios.php {"perfil": <id>} -> pasa la sesion a ese perfil
 *
 * Alimenta el modal "Cambiar dominio" del menu de usuario. Porta
 * reactor-panel/sesion/cambiar.php + cPerfil::cargar() del legacy
 * (reactor-api/framework/subframework.php).
 *
 * UNA FILA POR PERFIL, NO POR DOMINIO: lo que se elige es un perfil, no un
 * dominio suelto. La misma cuenta puede tener varios perfiles en el mismo
 * dominio con distinto rol (el usuario 3 tiene cuatro en `Reactor`:
 * Desarrollador, Administrador, Director Comercial y Tecnico Instalador) y
 * `usuarios.perfil` guarda cual se eligio, asi que la fila tiene que
 * identificar al perfil sin ambiguedad. Es como lista el legacy.
 *
 * QUIEN DEFINE LA DISPONIBILIDAD: `perfiles`. Una cuenta puede pasar a un
 * dominio si existe una fila habilitada `perfiles(usuario, dominio)`.
 * `usuarios.dominio` NO es la lista de dominios permitidos: es el dominio
 * ACTIVO, el que viaja en el JWT y por el que filtra todo el panel.
 *
 * QUE SE ASIENTA AL CAMBIAR: `usuarios.perfil` (ultimo perfil elegido) y
 * `usuarios.dominio` (ultimo dominio). El legacy escribe solo `perfil` porque
 * deriva el dominio del perfil en cada arranque de sesion (subframework.php
 * linea 99: `sesionDominio` sale de `$zPerfil->dominio`); el panel lo lee de
 * `usuarios.dominio`, asi que hay que escribir las dos columnas para que las
 * dos lecturas coincidan.
 *
 * NO se porta el manejo de `perfiles.panel` que hace el legacy al cambiar
 * (asignarle un panel del dominio si esta en 0): este panel no usa la tabla
 * `paneles` en ninguna pantalla.
 */

require __DIR__ . '/bootstrap.php';

/** Valor de `perfiles.habilitado` para un acceso vigente (varchar(1), no 'S'/'N'). */
const PERFIL_HABILITADO = '1';

$auth      = authUser();
$usuarioId = (int) ($auth['id'] ?? 0);
if ($usuarioId <= 0) {
    json_error('Sesion invalida', 401);
}

try {
    switch ($_SERVER['REQUEST_METHOD'] ?? 'GET') {
        case 'GET':  listarPerfiles($usuarioId);  break;
        case 'POST': cambiarDominio($usuarioId);  break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar los dominios: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* GET: perfiles disponibles                                           */
/* ------------------------------------------------------------------ */

function listarPerfiles(int $usuarioId): void
{
    $ctx           = sessionContext() ?? [];
    $dominioActual = sessionDominioId();
    $perfilActual  = isset($ctx['perfil']) ? (int) $ctx['perfil'] : 0;

    // Un perfil deshabilitado es un acceso revocado y no se lista. El DOMINIO
    // deshabilitado si se lista y se puede elegir -- como en el legacy, que no
    // mira `dominios.habilitado`: hoy 95 de 148 dominios estan en 0 y bloquearlos
    // le sacaria al usuario accesos que viene usando. Va con badge, no oculto.
    $stmt = db()->prepare(
        'SELECT p.id AS perfil_id, p.nombre AS perfil_nombre,
                d.id AS dominio_id, d.nombre AS dominio_nombre, d.habilitado,
                r.nombre AS rol_nombre
         FROM perfiles p
         INNER JOIN dominios d ON d.id = p.dominio
         LEFT JOIN  roles    r ON r.id = p.rol
         WHERE p.usuario = :u AND p.habilitado = :hab
         ORDER BY d.nombre ASC, p.id ASC'
    );
    $stmt->execute([':u' => $usuarioId, ':hab' => PERFIL_HABILITADO]);

    $perfiles  = [];
    $enDominio = [];
    foreach ($stmt->fetchAll() as $r) {
        $enDominio[(int) $r['dominio_id']] = true;

        $perfiles[] = [
            'perfil'     => (int) $r['perfil_id'],
            'dominio'    => (int) $r['dominio_id'],
            'nombre'     => trim((string) ($r['dominio_nombre'] ?? '')),
            // El rol ("Administrador") describe mejor la fila que
            // `perfiles.nombre` ("Administrador en Reactor"), que repite el
            // nombre del dominio que ya encabeza la tarjeta.
            'rol'        => trim((string) ($r['rol_nombre'] ?? '')) ?: trim((string) ($r['perfil_nombre'] ?? '')),
            'habilitado' => normalizarHabilitado($r['habilitado']),
            'actual'     => (int) $r['perfil_id'] === $perfilActual,
        ];
    }

    // El dominio activo sin perfil propio existe: `usuarios.dominio` lo asigna
    // el back office interno y no exige fila en `perfiles` (el usuario 3 esta
    // en `OSSE San Juan` sin perfil). Se lista igual -- primero, porque queda
    // fuera del orden alfabetico -- para que la sesion en curso no falte, pero
    // sin `perfil` no es elegible: no hay nada que asentar en la cuenta.
    if ($dominioActual !== null && !isset($enDominio[$dominioActual])) {
        $stmt = db()->prepare('SELECT id, nombre, habilitado FROM dominios WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $dominioActual]);
        if ($row = $stmt->fetch()) {
            array_unshift($perfiles, [
                'perfil'     => null,
                'dominio'    => (int) $row['id'],
                'nombre'     => trim((string) ($row['nombre'] ?? '')),
                'rol'        => '',
                'habilitado' => normalizarHabilitado($row['habilitado']),
                'actual'     => false,
            ]);
        }
    }

    json_ok([
        'dominio_actual' => $dominioActual,
        'perfil_actual'  => $perfilActual > 0 ? $perfilActual : null,
        'perfiles'       => $perfiles,
    ]);
}

/* ------------------------------------------------------------------ */
/* POST: cambio de dominio                                             */
/* ------------------------------------------------------------------ */

function cambiarDominio(int $usuarioId): void
{
    $raw = file_get_contents('php://input');
    $in  = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($in)) {
        json_error('Body JSON invalido', 400);
    }

    $perfilId = (int) ($in['perfil'] ?? 0);
    if ($perfilId <= 0) {
        json_error('Perfil invalido', 422);
    }

    // El perfil TIENE que ser del usuario logueado y estar habilitado: sin el
    // filtro por `p.usuario`, un id a mano mueve la sesion a cualquier dominio
    // del sistema. Es el unico control que separa esto de una escalada.
    $stmt = db()->prepare(
        'SELECT p.id, p.dominio
         FROM perfiles p
         INNER JOIN dominios d ON d.id = p.dominio
         WHERE p.id = :p AND p.usuario = :u AND p.habilitado = :hab
         LIMIT 1'
    );
    $stmt->execute([':p' => $perfilId, ':u' => $usuarioId, ':hab' => PERFIL_HABILITADO]);
    $perfil = $stmt->fetch();

    if (!$perfil) {
        json_error('Ese perfil no esta disponible para tu cuenta', 403);
    }

    // Se reemite el token, asi que se revalida la cuenta como en el login: si
    // la deshabilitaron despues de emitir el token vigente, la sesion no se
    // renueva por esta via.
    $stmt = db()->prepare('SELECT id, usuario, nombre, correo, habilitado FROM usuarios WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $usuarioId]);
    $cuenta = $stmt->fetch();

    if (!$cuenta) {
        json_error('El usuario ya no existe', 404);
    }
    if (!in_array(strtoupper(trim((string) ($cuenta['habilitado'] ?? ''))), ['S', '1', 'Y'], true)) {
        json_error('El usuario esta deshabilitado', 403);
    }

    // Asienta la seleccion en la cuenta (cPerfil::cargar() del legacy escribe
    // `usuarios.perfil`; `usuarios.dominio` lo agrega el panel, ver cabecera).
    $upd = db()->prepare('UPDATE usuarios SET perfil = :p, dominio = :d WHERE id = :u');
    $upd->execute([
        ':p' => (int) $perfil['id'],
        ':d' => (int) $perfil['dominio'],
        ':u' => $usuarioId,
    ]);

    // "Reiniciar la sesion sin pedir credenciales" = reemitir el JWT con los
    // claims de alcance nuevos, sobre la misma cookie. sessionCuentaDesdeDb()
    // relee `usuarios` y ya devuelve el dominio y el perfil recien escritos.
    $alcance = sessionCuentaDesdeDb($usuarioId);

    $payload = [
        'id'             => (int) $cuenta['id'],
        'usuario'        => (string) $cuenta['usuario'],
        'nombre'         => (string) $cuenta['nombre'],
        'correo'         => (string) ($cuenta['correo'] ?? ''),
        'dominio'        => $alcance['dominio']        ?? null,
        'dominio_nombre' => $alcance['dominio_nombre'] ?? '',
        'perfil'         => $alcance['perfil']         ?? null,
        'perfil_nombre'  => $alcance['perfil_nombre']  ?? '',
        'roles'          => $alcance['roles']          ?? '',
    ];

    jwt_cookie_set(jwt_sign($payload, JWT_TTL));

    json_ok(['usuario' => $payload]);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/**
 * `dominios.habilitado` es smallint y admite NULL: se normaliza a 1/0 para que
 * el front no tenga que distinguir NULL de 0 (ambos son "no"), igual que
 * dominio.php.
 */
function normalizarHabilitado(mixed $valor): int
{
    return ((int) ($valor ?? 0)) === 1 ? 1 : 0;
}
