<?php

declare(strict_types=1);

/**
 * Control de acceso por rol del panel.
 *
 * REGLA DURA: al panel solo entra una cuenta con perfil de **Administrador** en
 * el dominio activo. El Operador queda afuera, y no es un caso de borde: es el
 * rol mas comun por lejos (medido en dev: 1.470 perfiles habilitados de Operador
 * contra 383 de Administrador), asi que la regla recorta el panel de ~2.065
 * cuentas habilitadas a ~368.
 *
 * QUEDAN AFUERA TAMBIEN los roles que no son Operador pero tampoco
 * Administrador: `Tecnico`, y los internos de Reactor (`Desarrollador`,
 * `Director Tecnico`, `Director Comercial`, `Contador`, `Tecnico Instalador`).
 * Son ~20 perfiles en total y varias de esas personas tienen ademas un perfil
 * de Administrador, con el que si entran. Si alguno tiene que poder entrar por
 * su propio rol, se agrega su id a PANEL_ROLES_ADMIN y no hay nada mas que
 * tocar: la lista es el unico lugar donde se decide.
 *
 * EL CRITERIO ES `perfiles.rol`, NO `perfiles.tipo`. El legacy gatea por
 * `tipo = 'A'` y las dos columnas estan desalineadas en los datos: 39 perfiles
 * con rol Administrador llevan `tipo = 'O'` y 8 con rol Operador llevan
 * `tipo = 'A'`. Gatear por `tipo` dejaria afuera a 39 administradores reales y
 * adentro a 8 operadores. Es la misma conclusion que ya estaba escrita en
 * panel/CLAUDE.md ("el criterio confiable es `rol`, no `tipo`").
 *
 * SE MIRA EL PERFIL ACTIVO, NO "algun perfil de la cuenta". La sesion tiene un
 * perfil (`usuarios.perfil`) y un dominio (`usuarios.dominio`), y el dominio es
 * el que filtra TODA la informacion del panel. Por eso el chequeo exige las
 * cuatro cosas juntas: que el perfil sea de esta cuenta, que este habilitado,
 * que tenga rol de administrador y que su `dominio` sea el de la sesion.
 * La ultima no es redundante: en la base hay 13 cuentas cuyo `usuarios.perfil`
 * apunta a un perfil de administrador de un dominio DISTINTO del que tienen en
 * `usuarios.dominio`. Sin comparar el dominio, esas 13 entrarian a un dominio
 * donde no son administradoras.
 *
 * DONDE SE APLICA:
 *   - api/bootstrap.php  -> todo endpoint que no sea PANEL_API_PUBLIC.
 *   - index.php          -> el shell de la SPA.
 *   - login.php          -> decide si la sesion vigente puede saltar al panel.
 *   - api/login.php      -> corta el ingreso y elige el perfil con el que entrar.
 *   - api/dominios.php   -> el selector "Cambiar dominio" solo ofrece dominios
 *                           donde la cuenta es administradora.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sesion.php';
// HABILITADO (1) / DESHABILITADO (0): el unico par de valores que admite
// `perfiles.habilitado` -- y toda otra columna con ese nombre.
require_once __DIR__ . '/habilitado.php';

/**
 * Los DOS unicos valores de `perfiles`.`tipo`.
 *
 * La columna es `ENUM('A','O') NOT NULL DEFAULT 'O'` desde
 * 20260905_2300_perfiles_tipo_a_o.sql: no hay NULL, no hay cadena vacia y no
 * hay una tercera letra. Antes 22 filas estaban en NULL o en '' -- todas de
 * roles internos de Reactor -- y quedaron en 'O', el menos privilegiado.
 *
 * NO SON EL GATE DEL PANEL. El acceso lo decide `perfiles`.`rol` contra
 * PANEL_ROLES_ADMIN (ver la cabecera): `tipo` y `rol` estan desalineados en los
 * datos y el que manda es `rol`. Estas constantes existen para ESCRIBIR la
 * columna -- hoy solo la aceptacion de invitaciones -- y para no volver a tipear
 * la letra suelta.
 */
const PERFIL_TIPO_ADMINISTRADOR = 'A';
const PERFIL_TIPO_OPERADOR      = 'O';

/**
 * Roles de `roles` que habilitan el panel. Hoy uno solo: 101 = Administrador.
 * Ver la cabecera antes de agregar otro.
 */
const PANEL_ROLES_ADMIN = [101];

/** Texto unico del rechazo: lo comparten la API, el shell y el login. */
const PANEL_MENSAJE_SIN_ROL =
    'El panel es exclusivo para cuentas con perfil de Administrador.';

/**
 * Lista de ids para el `IN (...)` del SQL.
 *
 * Va interpolada y no como placeholders porque el numero de elementos cambia
 * con la constante; el `intval` es lo que garantiza que no haya nada mas que
 * enteros del propio codigo (PANEL_ROLES_ADMIN nunca viene del request).
 */
function panelRolesAdminSql(): string
{
    return implode(',', array_map('intval', PANEL_ROLES_ADMIN));
}

/**
 * Rol con el que se crea un perfil nuevo que tiene que habilitar el panel.
 *
 * PANEL_ROLES_ADMIN puede tener varios ids (todos dan acceso); para ESCRIBIR
 * hay que elegir uno solo, y es el primero. Lo usa la aceptacion de
 * invitaciones, que es el unico camino del panel que crea perfiles.
 */
function panelRolAdminPorDefecto(): int
{
    return (int) PANEL_ROLES_ADMIN[0];
}

/**
 * Perfiles con los que la cuenta puede trabajar en el panel: habilitados y con
 * rol de administrador. Es la fuente unica de "a que dominios puede entrar":
 * la usa el selector Cambiar dominio y tambien el login para elegir con cual
 * arrancar la sesion.
 *
 * Devuelve una fila por PERFIL, no por dominio: la misma cuenta puede tener
 * varios perfiles en el mismo dominio y `usuarios.perfil` guarda cual se eligio.
 *
 * `dominios.habilitado` viaja como dato pero NO filtra: el legacy nunca lo miro
 * y hoy 95 de 148 dominios estan en 0, asi que excluirlos le sacaria accesos que
 * la gente viene usando. Se muestra con badge, no se oculta.
 *
 * @return list<array{perfil:int,dominio:int,nombre:string,rol:string,habilitado:int}>
 */
function perfilesAdministrador(int $usuarioId): array
{
    if ($usuarioId <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        'SELECT p.id AS perfil_id, p.nombre AS perfil_nombre,
                d.id AS dominio_id, d.nombre AS dominio_nombre, d.habilitado,
                r.nombre AS rol_nombre
         FROM perfiles p
         INNER JOIN dominios d ON d.id = p.dominio
         INNER JOIN roles    r ON r.id = p.rol
         WHERE p.usuario = :u
           AND p.habilitado = :hab
           AND p.rol IN (' . panelRolesAdminSql() . ')
         ORDER BY d.nombre ASC, p.id ASC'
    );
    $stmt->execute([':u' => $usuarioId, ':hab' => HABILITADO]);

    $perfiles = [];
    foreach ($stmt->fetchAll() as $r) {
        $perfiles[] = [
            'perfil'     => (int) $r['perfil_id'],
            'dominio'    => (int) $r['dominio_id'],
            'nombre'     => trim((string) ($r['dominio_nombre'] ?? '')),
            // El rol ("Administrador") describe mejor la fila que
            // `perfiles.nombre` ("Administrador en Reactor"), que repite el
            // nombre del dominio que ya encabeza la tarjeta.
            'rol'        => trim((string) ($r['rol_nombre'] ?? '')) ?: trim((string) ($r['perfil_nombre'] ?? '')),
            // `dominios.habilitado` es tinyint(1) NOT NULL: siempre 0 o 1.
            'habilitado' => (int) $r['habilitado'],
        ];
    }

    return $perfiles;
}

/**
 * ¿Ese perfil habilita el panel para esa cuenta en ese dominio?
 *
 * Las cuatro condiciones son necesarias — ver la cabecera. En particular el
 * `p.dominio = :d`: sin el, las 13 cuentas cuyo perfil activo es de otro
 * dominio entrarian a un dominio donde no son administradoras.
 */
function esPerfilAdministrador(int $usuarioId, int $perfilId, int $dominioId): bool
{
    if ($usuarioId <= 0 || $perfilId <= 0 || $dominioId <= 0) {
        return false;
    }

    $stmt = db()->prepare(
        'SELECT 1
         FROM perfiles p
         WHERE p.id = :p
           AND p.usuario = :u
           AND p.dominio = :d
           AND p.habilitado = :hab
           AND p.rol IN (' . panelRolesAdminSql() . ')
         LIMIT 1'
    );
    $stmt->execute([
        ':p'   => $perfilId,
        ':u'   => $usuarioId,
        ':d'   => $dominioId,
        ':hab' => HABILITADO,
    ]);

    return (bool) $stmt->fetchColumn();
}

/**
 * ¿La sesion en curso cumple la regla de acceso?
 *
 * Se resuelve SIEMPRE contra la base y no contra un claim del JWT: el token
 * dura 12 h y lo puede haber emitido cloud (que no firma estos claims), asi que
 * un perfil revocado tiene que dejar de servir en el request siguiente y no al
 * vencer el token. Cacheado por request, como sessionContext().
 */
function sessionEsAdministrador(): bool
{
    static $cached = false;
    static $es     = false;
    if ($cached) {
        return $es;
    }
    $cached = true;

    $ctx = sessionContext();
    if ($ctx === null) {
        return $es;
    }

    $es = esPerfilAdministrador(
        (int) ($ctx['id']      ?? 0),
        (int) ($ctx['perfil']  ?? 0),
        (int) ($ctx['dominio'] ?? 0)
    );

    return $es;
}

/**
 * Corta el request si la sesion no es de administrador. Es el equivalente de
 * requireAuth() un escalon mas arriba y responde igual segun el Accept: 403
 * JSON para la API, redirect al login para una pantalla.
 *
 * El JSON lleva `motivo: 'rol'` ademas del texto. Es lo que le permite al front
 * distinguir este 403 —la sesion ya no sirve, hay que volver al login— de los
 * 403 de negocio que si son un error de la pantalla ("El usuario esta
 * deshabilitado", "Ese perfil no esta disponible para tu cuenta").
 */
function requireAdministrador(): array
{
    $ctx = sessionContext();
    if ($ctx !== null && sessionEsAdministrador()) {
        return $ctx;
    }

    if (_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => false,
            'error'  => PANEL_MENSAJE_SIN_ROL,
            'motivo' => 'rol',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /login?motivo=rol');
    exit;
}

/**
 * Asienta en la cuenta el perfil con el que sigue la sesion.
 *
 * Se escriben las DOS columnas. El legacy escribe solo `perfil` porque deriva
 * el dominio de `perfiles.dominio` en cada arranque de sesion
 * (subframework.php); el panel lo lee de `usuarios.dominio`, asi que las dos
 * tienen que quedar de acuerdo o las dos lecturas se contradicen.
 *
 * Lo usan el cambio de dominio y el login (que elige un perfil de administrador
 * cuando el que traia la cuenta no habilita el panel).
 */
function panelPerfilActivoAsentar(int $usuarioId, int $perfilId, int $dominioId): void
{
    db()->prepare('UPDATE usuarios SET perfil = :p, dominio = :d WHERE id = :u')
        ->execute([':p' => $perfilId, ':d' => $dominioId, ':u' => $usuarioId]);
}
