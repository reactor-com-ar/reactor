<?php

declare(strict_types=1);

/**
 * Control de acceso del panel.
 *
 * REGLA DURA: al panel entra SOLO una cuenta con un perfil de ADMINISTRADOR
 * habilitado en el dominio de la sesion. El Operador queda afuera, y no es un
 * caso de borde: es el perfil mas comun por lejos (1659 de 2063 habilitados).
 *
 * EL CRITERIO ES `perfiles`.`tipo` = 'A', y es el unico que queda. Hasta el
 * 06/09/2026 era `perfiles`.`rol IN (101)`, que era mas confiable porque las dos
 * columnas estaban desalineadas y `rol` era la buena. Ese mismo dia `rol` se
 * elimino (`20260906_1500_perfiles_sin_rol.sql`) y el panel quedo sin gate: por
 * un rato entro cualquier perfil habilitado. `tipo` es lo que sobrevivio de esa
 * distincion, asi que es por donde se gatea ahora.
 *
 * LO QUE ESO CAMBIA, medido antes de aplicarlo:
 *
 *   perfiles habilitados ....... 2063   ->  entran 404 (tipo = 'A')
 *   cuentas distintas .......... 1958   ->  entran 356
 *
 * Y UN DOMINIO SE QUEDA SIN NADIE QUE LO ADMINISTRE: `Camino al Puente Viejo`
 * (#160) tiene 33 perfiles habilitados y los 33 son Operador. Hay que ponerle
 * `tipo = 'A'` a alguno o nadie va a poder entrar a administrarlo.
 *
 * LO QUE SIGUE EN PIE, y no es poco:
 *
 *   - El perfil tiene que estar HABILITADO. Revocar un acceso sigue siendo poner
 *     `perfiles.habilitado = 0`, y tiene efecto en el request siguiente.
 *   - El perfil tiene que ser DE ESA CUENTA y DE ESE DOMINIO. La comparacion de
 *     dominio no es redundante: hay 13 cuentas cuyo `usuarios.perfil` apunta a un
 *     perfil de un dominio distinto del de `usuarios.dominio`, y sin compararlo
 *     entrarian a operar sobre un dominio que no es el suyo.
 *   - Se resuelve SIEMPRE contra la base, nunca contra un claim del JWT: el token
 *     dura 12 h y lo puede haber emitido cloud (que no firma estos claims).
 *
 * DONDE SE APLICA:
 *   - api/bootstrap.php  -> todo endpoint que no sea PANEL_API_PUBLIC.
 *   - index.php          -> el shell de la SPA.
 *   - login.php          -> decide si la sesion vigente puede saltar al panel.
 *   - api/login.php      -> corta el ingreso y elige el perfil con el que entrar.
 *   - api/dominios.php   -> el selector "Cambiar dominio".
 *
 * Y UN SEGUNDO ESCALON, YA ADENTRO: los permisos del perfil
 * (`panelPermisosDeSesion()` / `requirePermisoPanel()`, mas abajo). El gate dice
 * si la cuenta entra; los permisos dicen que pantallas ve una vez adentro. Del
 * panel el unico que gatea algo es `facturacion` -> el agrupador "Cuenta"
 * (Facturas / Recibos / Facturacion); los otros dos son de `app`.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/sesion.php';
// HABILITADO (1) / DESHABILITADO (0): el unico par de valores que admite
// `perfiles.habilitado` -- y toda otra columna con ese nombre.
require_once __DIR__ . '/habilitado.php';
// Los tres permisos del perfil (`operacion` / `invitacion` / `facturacion`).
// Del panel solo gatea `facturacion`; los otros dos son de `app`, pero el
// catalogo es uno solo y vive en un archivo unico.
require_once __DIR__ . '/permisos.php';

/**
 * Los DOS unicos valores de `perfiles`.`tipo`.
 *
 * La columna es `ENUM('A','O') NOT NULL DEFAULT 'O'` desde
 * 20260905_2300_perfiles_tipo_a_o.sql: no hay NULL, no hay cadena vacia y no
 * hay una tercera letra.
 *
 * ES EL GATE DEL PANEL desde el 06/09/2026: `A` entra, `O` no. Antes el gate
 * era `perfiles`.`rol` y `tipo` solo la leia el sistema legacy; al eliminarse
 * `rol`, esta columna quedo como la unica que distingue administrador de
 * operador. Se escribe con estas constantes, nunca con la letra suelta.
 */
const PERFIL_TIPO_ADMINISTRADOR = 'A';
const PERFIL_TIPO_OPERADOR      = 'O';

/** Texto unico del rechazo: lo comparten la API, el shell y el login. */
const PANEL_MENSAJE_SIN_PERFIL =
    'El panel es exclusivo para cuentas con perfil de Administrador.';

/**
 * Perfiles con los que la cuenta puede trabajar en el panel: los habilitados
 * DE TIPO ADMINISTRADOR. Es la fuente unica de "a que dominios puede entrar":
 * la usa el selector Cambiar dominio y tambien el login para elegir con cual
 * arrancar la sesion. Si devuelve vacio, la cuenta no entra al panel.
 *
 * Devuelve una fila por PERFIL, no por dominio: la misma cuenta puede tener
 * varios perfiles en el mismo dominio y `usuarios.perfil` guarda cual se eligio.
 *
 * `dominios.habilitado` viaja como dato pero NO filtra: el legacy nunca lo miro
 * y hoy 95 de 148 dominios estan en 0, asi que excluirlos le sacaria accesos que
 * la gente viene usando. Se muestra con badge, no se oculta.
 *
 * @return list<array{perfil:int,dominio:int,nombre:string,perfil_nombre:string,habilitado:int}>
 */
function perfilesHabilitados(int $usuarioId): array
{
    if ($usuarioId <= 0) {
        return [];
    }

    // Sin JOIN contra `roles`: el perfil ya no tiene rol. La etiqueta de la fila
    // pasa a ser `perfiles.nombre` ("Administrador en Reactor"), que repite el
    // dominio pero es lo unico que queda para describir el perfil.
    $stmt = db()->prepare(
        'SELECT p.id AS perfil_id, p.nombre AS perfil_nombre,
                d.id AS dominio_id, d.nombre AS dominio_nombre, d.habilitado
         FROM perfiles p
         INNER JOIN dominios d ON d.id = p.dominio
         WHERE p.usuario = :u
           AND p.habilitado = :hab
           AND p.tipo = :tipo
         ORDER BY d.nombre ASC, p.id ASC'
    );
    $stmt->execute([':u' => $usuarioId, ':hab' => HABILITADO, ':tipo' => PERFIL_TIPO_ADMINISTRADOR]);

    $perfiles = [];
    foreach ($stmt->fetchAll() as $r) {
        $perfiles[] = [
            'perfil'        => (int) $r['perfil_id'],
            'dominio'       => (int) $r['dominio_id'],
            'nombre'        => trim((string) ($r['dominio_nombre'] ?? '')),
            'perfil_nombre' => trim((string) ($r['perfil_nombre'] ?? '')),
            // `dominios.habilitado` es tinyint(1) NOT NULL: siempre 0 o 1.
            'habilitado'    => (int) $r['habilitado'],
        ];
    }

    return $perfiles;
}

/**
 * ¿Ese perfil habilita el panel para esa cuenta en ese dominio?
 *
 * Las CUATRO condiciones son necesarias — ver la cabecera. En particular:
 *   - `p.dominio = :d`: sin el, las 13 cuentas cuyo perfil activo es de otro
 *     dominio entrarian a operar sobre un dominio que no es el suyo.
 *   - `p.tipo = 'A'`: es el gate. Sin el entra cualquier Operador.
 */
function esPerfilValido(int $usuarioId, int $perfilId, int $dominioId): bool
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
           AND p.tipo = :tipo
         LIMIT 1'
    );
    $stmt->execute([
        ':p'    => $perfilId,
        ':u'    => $usuarioId,
        ':d'    => $dominioId,
        ':hab'  => HABILITADO,
        ':tipo' => PERFIL_TIPO_ADMINISTRADOR,
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
function sessionTienePerfilValido(): bool
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

    $es = esPerfilValido(
        (int) ($ctx['id']      ?? 0),
        (int) ($ctx['perfil']  ?? 0),
        (int) ($ctx['dominio'] ?? 0)
    );

    return $es;
}

/**
 * Corta el request si la sesion no tiene un perfil valido. Es el equivalente de
 * requireAuth() un escalon mas arriba y responde igual segun el Accept: 403
 * JSON para la API, redirect al login para una pantalla.
 *
 * El JSON lleva `motivo: 'perfil'` ademas del texto. Es lo que le permite al
 * front distinguir este 403 —la sesion ya no sirve, hay que volver al login— de
 * los 403 de negocio que si son un error de la pantalla ("El usuario esta
 * deshabilitado", "Ese perfil no esta disponible para tu cuenta").
 */
function requirePerfilValido(): array
{
    $ctx = sessionContext();
    if ($ctx !== null && sessionTienePerfilValido()) {
        return $ctx;
    }

    if (_wants_json()) {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'     => false,
            'error'  => PANEL_MENSAJE_SIN_PERFIL,
            'motivo' => 'perfil',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    header('Location: /login?motivo=perfil');
    exit;
}

/* ------------------------------------------------------------------ */
/* Permisos del perfil de la sesion                                     */
/* ------------------------------------------------------------------ */

/**
 * Los tres permisos del perfil con el que trabaja la sesion.
 *
 * ES UN SEGUNDO ESCALON, NO UN REEMPLAZO DEL GATE. `requirePerfilValido()`
 * decide si la cuenta ENTRA al panel (perfil habilitado, de esa cuenta, de ese
 * dominio y `tipo = 'A'`); esto decide, ya adentro, que pantallas ve. Por eso la
 * consulta repite las cuatro condiciones del gate en vez de buscar el perfil por
 * id a secas: un perfil que no pasa el gate no tiene permisos que informar, y
 * leerlos igual dejaria una segunda definicion de "el perfil de la sesion" que
 * podria discrepar de la primera.
 *
 * SE RESUELVE CONTRA LA BASE, NUNCA CONTRA UN CLAIM DEL JWT — misma razon que
 * el gate: el token dura 12 h y lo puede haber emitido cloud, asi que quitar
 * `facturacion` tiene efecto en el request siguiente y no al vencer el token.
 * Cacheado por request, como sessionContext() y sessionTienePerfilValido().
 *
 * SIN PERFIL VALIDO DEVUELVE LOS TRES EN false, no un error: quien llama es una
 * pantalla que ya paso por el gate, y el fallo cerrado es el unico default
 * seguro para un permiso.
 *
 * @return array{operacion:bool, invitacion:bool, facturacion:bool}
 */
function panelPermisosDeSesion(): array
{
    static $cached = false;
    static $permisos = null;
    if ($cached) {
        return $permisos;
    }
    $cached   = true;
    $permisos = perfilSinPermisos();

    $ctx = sessionContext();
    if ($ctx === null) {
        return $permisos;
    }

    $stmt = db()->prepare(
        'SELECT p.operacion, p.invitacion, p.facturacion
         FROM perfiles p
         WHERE p.id = :p
           AND p.usuario = :u
           AND p.dominio = :d
           AND p.habilitado = :hab
           AND p.tipo = :tipo
         LIMIT 1'
    );
    $stmt->execute([
        ':p'    => (int) ($ctx['perfil']  ?? 0),
        ':u'    => (int) ($ctx['id']      ?? 0),
        ':d'    => (int) ($ctx['dominio'] ?? 0),
        ':hab'  => HABILITADO,
        ':tipo' => PERFIL_TIPO_ADMINISTRADOR,
    ]);

    $row = $stmt->fetch();

    return $permisos = $row ? perfilPermisos($row) : $permisos;
}

/** ¿La sesion tiene ese permiso? `$permiso` es una clave de PERFIL_PERMISOS. */
function panelPuede(string $permiso): bool
{
    return panelPermisosDeSesion()[$permiso] ?? false;
}

/**
 * Corta el request si a la sesion le falta ese permiso.
 *
 * Responde con 403 y `motivo: 'permiso'` — DISTINTO del `motivo: 'perfil'` de
 * requirePerfilValido(), y la diferencia importa: 'perfil' significa "la sesion
 * ya no sirve, volve al login" y el front actua en consecuencia; 'permiso'
 * significa "la sesion esta bien, esta pantalla no es tuya" y volver al login no
 * arreglaria nada. Mandar los dos casos por el mismo camino haria que a quien no
 * tiene facturacion se lo eche de la sesion cada vez que toca Facturas.
 */
function requirePermisoPanel(string $permiso): void
{
    if (panelPuede($permiso)) {
        return;
    }

    $etiqueta = PERFIL_PERMISOS[$permiso]['etiqueta'] ?? $permiso;

    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'ok'     => false,
        'error'  => 'Tu perfil no tiene el permiso de ' . $etiqueta . '.',
        'motivo' => 'permiso',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
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
 * cuando el que traia la cuenta no sirve para el dominio activo).
 */
function panelPerfilActivoAsentar(int $usuarioId, int $perfilId, int $dominioId): void
{
    db()->prepare('UPDATE usuarios SET perfil = :p, dominio = :d WHERE id = :u')
        ->execute([':p' => $perfilId, ':d' => $dominioId, ':u' => $usuarioId]);
}
