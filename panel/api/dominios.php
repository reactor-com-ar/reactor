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
 * QUIEN DEFINE LA DISPONIBILIDAD: `perfiles`, y SOLO los de rol Administrador.
 * Una cuenta puede pasar a un dominio si existe una fila habilitada
 * `perfiles(usuario, dominio)` con `rol` en PANEL_ROLES_ADMIN — la misma regla
 * con la que entra al panel, porque el selector no puede ofrecer un destino que
 * despues el gate de lib/acceso.php va a rechazar. Un dominio donde la cuenta
 * es Operadora simplemente no aparece.
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

// HABILITADO y PANEL_ROLES_ADMIN viven en lib/, y ya llegan
// por bootstrap.php: la regla de quien puede entrar y la de que se lista tienen
// que salir del mismo lugar o se desincronizan.

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

    // Un perfil deshabilitado es un acceso revocado y uno que no es de
    // Administrador no habilita el panel: ninguno de los dos se lista. El
    // DOMINIO deshabilitado si se lista y se puede elegir -- como en el legacy,
    // que no mira `dominios.habilitado`: hoy 95 de 148 dominios estan en 0 y
    // bloquearlos le sacaria al usuario accesos que viene usando. Va con badge,
    // no oculto. La consulta es perfilesAdministrador() de lib/acceso.php, la
    // misma que usa el login para elegir con que perfil arranca la sesion.
    $perfiles = array_map(
        static fn (array $p): array => $p + ['actual' => $p['perfil'] === $perfilActual],
        perfilesAdministrador($usuarioId)
    );

    // Ya no se lista el dominio activo sin perfil propio. Existia porque
    // `usuarios.dominio` lo puede asignar el back office interno sin crear fila
    // en `perfiles` (el usuario 3 esta asi en `OSSE San Juan`), y se mostraba
    // para que la sesion en curso no faltara de la lista. Con el gate de rol esa
    // sesion ya no puede existir: sin perfil de Administrador en el dominio
    // activo, requireAdministrador() no la deja llegar hasta aca.

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

    // El perfil TIENE que ser del usuario logueado, estar habilitado y ser de
    // rol Administrador. Sin el filtro por `p.usuario`, un id a mano mueve la
    // sesion a cualquier dominio del sistema; sin el filtro por `p.rol`, mueve
    // la sesion a un dominio donde la cuenta es Operadora — que es justo lo que
    // el selector deja de ofrecer, y esconderlo de la lista no alcanza como
    // control. Los dos filtros son el limite entre esto y una escalada.
    $stmt = db()->prepare(
        'SELECT p.id, p.dominio
         FROM perfiles p
         INNER JOIN dominios d ON d.id = p.dominio
         WHERE p.id = :p
           AND p.usuario = :u
           AND p.habilitado = :hab
           AND p.rol IN (' . panelRolesAdminSql() . ')
         LIMIT 1'
    );
    $stmt->execute([':p' => $perfilId, ':u' => $usuarioId, ':hab' => HABILITADO]);
    $perfil = $stmt->fetch();

    if (!$perfil) {
        json_error('Ese perfil no está disponible para tu cuenta: al panel solo se entra con perfil de Administrador.', 403);
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
    if (!esHabilitado($cuenta['habilitado'] ?? 0)) {
        json_error('El usuario esta deshabilitado', 403);
    }

    // Asienta la seleccion en la cuenta (cPerfil::cargar() del legacy escribe
    // `usuarios.perfil`; `usuarios.dominio` lo agrega el panel, ver cabecera).
    // Es el mismo helper con el que el login elige el perfil de arranque.
    panelPerfilActivoAsentar($usuarioId, (int) $perfil['id'], (int) $perfil['dominio']);

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
