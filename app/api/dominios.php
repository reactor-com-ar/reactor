<?php

declare(strict_types=1);

/**
 * Dominios entre los que el usuario puede moverse (modal "Cambiar de Dominio").
 *
 * GET  -> lista los perfiles del usuario y marca cuál está activo.
 * POST -> cambia de dominio. Body: {"perfil": <id>}.
 *
 * Port de `reactor-app/cambiar/dominio.php`. La lista NO sale de `dominios`
 * sino de los PERFILES del usuario:
 *
 *     select id from perfiles where usuario=<usuarioId> and habilitado='1'
 *     order by nombre, id
 *
 * ...y de cada perfil se muestra el nombre de SU dominio. Consecuencias:
 *
 *   - Un mismo nombre puede aparecer varias veces: son perfiles distintos
 *     sobre dominios homonimos. Por eso cada opcion se identifica por el id
 *     del perfil, no por el nombre.
 *   - El orden es por `perfiles.nombre` (del estilo "Administrador en X"),
 *     no por el nombre del dominio. Se respeta para que la lista salga en el
 *     mismo orden que en la app vieja.
 *   - Elegir una opcion cambia el PERFIL activo (`usuarios.perfil`), que es
 *     lo que despues define el dominio de toda la sesion.
 *
 * QUE PASA AL CAMBIAR — es "reiniciar la sesion con los valores nuevos", y son
 * cuatro escrituras, las mismas que hace el legacy:
 *
 *   1. `usuarios.perfil` (y `usuarios.dominio`, ver abajo): el dato durable.
 *   2. `perfiles.panel`, si el perfil elegido no tenia panel: hay que darle
 *      uno o la pantalla siguiente queda vacia.
 *   3. El token se reemite con los claims `per`/`dom`/`pan` nuevos. Es el
 *      equivalente de reescribir `sesionPerfil` / `sesionDominio` /
 *      `sesionPanel` en el `$_SESSION` del legacy.
 *   4. El front recarga, y con el panel nuevo ya resuelto la pantalla se
 *      arma con los controles del dominio al que se acaba de entrar.
 *
 * `usuarios.dominio` no lo escribe el legacy —deriva el dominio de
 * `perfiles.dominio` en cada arranque de sesion— pero si lo escribe `panel/`,
 * que lo lee de ahi. Las tres apps comparten la tabla `usuarios`, asi que se
 * escriben las dos columnas para que ninguna quede leyendo un dominio viejo.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function responder(int $status, array $cuerpo): never
{
    http_response_code($status);
    echo json_encode($cuerpo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$sesion = appUser();
if ($sesion === null) {
    responder(401, ['ok' => false, 'error' => 'Sesión vencida. Volvé a ingresar.']);
}

$metodo = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

if ($metodo === 'POST') {
    cambiarDominio($sesion);
}

if ($metodo !== 'GET') {
    header('Allow: GET, POST');
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

try {
    // El perfil marcado como actual es el de la SESION (claim del token,
    // revalidado), el mismo que decide el dominio del resto de la pantalla.
    $ctx = appContextoSesion($sesion);

    $stmt = db()->prepare(
        'SELECT p.id AS perfil, p.dominio, d.nombre
         FROM perfiles p
         LEFT JOIN dominios d ON d.id = p.dominio
         WHERE p.usuario = :u AND p.habilitado = \'1\'
         ORDER BY p.nombre, p.id'
    );
    $stmt->execute([':u' => (int) $sesion['id']]);

    $dominios = [];
    foreach ($stmt->fetchAll() as $r) {
        $nombre = trim((string) ($r['nombre'] ?? ''));
        $dominios[] = [
            'perfil' => (int) $r['perfil'],
            'nombre' => $nombre !== '' ? $nombre : '(sin nombre)',
            // Se compara por perfil: con nombres repetidos, hacerlo por nombre
            // marcaria varias opciones como la actual.
            'actual' => ((int) $r['perfil']) === $ctx['perfil'],
        ];
    }

    responder(200, ['ok' => true, 'dominios' => $dominios]);
} catch (Throwable $_) {
    responder(500, ['ok' => false, 'error' => 'No se pudieron leer los dominios.']);
}

/**
 * Cambia el dominio activo pasando la sesión a otro perfil del usuario.
 *
 * Equivale al bloque `if ($oFormulario->getpost('pro') != '')` de
 * `reactor-app/cambiar/dominio.php`, con una diferencia de fondo:
 *
 * **El perfil tiene que ser DEL USUARIO LOGUEADO.** El legacy hace
 * `$xPerfil->leer($perfil); $xUsuario->leer($xPerfil->usuario);` — o sea, lee
 * de quién es ese perfil y le cambia el dominio A ESE USUARIO. Con el uuid de
 * un perfil ajeno, el request le mueve la sesión a otra cuenta. Acá se filtra
 * por `perfiles.usuario`, así que un id que no sea propio devuelve 403 y no
 * escribe nada.
 */
function cambiarDominio(array $sesion): never
{
    $crudo   = file_get_contents('php://input');
    $entrada = json_decode($crudo !== false ? $crudo : '', true);
    if (!is_array($entrada)) {
        responder(400, ['ok' => false, 'error' => 'Pedido inválido.']);
    }

    $perfilId = (int) ($entrada['perfil'] ?? 0);
    if ($perfilId <= 0) {
        responder(400, ['ok' => false, 'error' => 'Falta el perfil.']);
    }

    try {
        $usuarioId = (int) $sesion['id'];

        // El control de acceso: perfil propio y habilitado.
        $perfil = appPerfilHabilitado($perfilId, $usuarioId);
        if ($perfil === null) {
            responder(403, ['ok' => false, 'error' => 'Ese dominio no está disponible para tu cuenta.']);
        }

        // Se reemite el token, así que se revalida la cuenta igual que en el
        // login: si la deshabilitaron después de emitir el token vigente, la
        // sesión no se renueva por esta vía.
        $cuenta = appUsuarioVigente($usuarioId);
        if ($cuenta === null) {
            responder(403, ['ok' => false, 'error' => 'Tu cuenta ya no está habilitada.']);
        }

        // Qué panel abre el dominio nuevo. `appPanelesDelDominio()` devuelve el
        // recordado por el perfil si sigue siendo válido, y si no el primero
        // de la lista — la misma regla de `cPanel::perfil2id()`.
        $paneles = appPanelesDelDominio($perfil['dominio'], $perfil['panel']);
        $panel   = $paneles['activo'];

        db()->beginTransaction();

        db()->prepare('UPDATE usuarios SET perfil = :p, dominio = :d WHERE id = :u')
            ->execute([':p' => $perfil['perfil'], ':d' => $perfil['dominio'], ':u' => $usuarioId]);

        // El legacy sólo escribe `perfiles.panel` cuando venía en 0. Acá se
        // escribe también cuando el panel recordado dejó de ser válido (lo
        // deshabilitaron, o lo movieron de dominio): en ese caso la columna
        // quedaría apuntando a un panel que ya no se puede abrir, y el próximo
        // ingreso tendría que volver a resolverlo por descarte.
        if ($panel > 0 && $panel !== $perfil['panel']) {
            db()->prepare('UPDATE perfiles SET panel = :pa WHERE id = :p')
                ->execute([':pa' => $panel, ':p' => $perfil['perfil']]);
        }

        db()->commit();

        // "Reiniciar la sesión sin pedir credenciales" = firmar de nuevo sobre
        // la misma cookie, con el alcance nuevo.
        appSesionEmitir($cuenta, [
            'per' => $perfil['perfil'],
            'dom' => $perfil['dominio'],
            'pan' => $panel,
        ]);

        responder(200, [
            'ok'      => true,
            'perfil'  => $perfil['perfil'],
            'dominio' => $perfil['dominio'],
            'nombre'  => $perfil['nombre'],
            'panel'   => $panel,
            // El front recarga: la pantalla del panel la arma el servidor con
            // los controles del dominio nuevo.
            'panelNombre' => $paneles['nombre'],
        ]);
    } catch (Throwable $_) {
        if (db()->inTransaction()) {
            db()->rollBack();
        }
        responder(500, ['ok' => false, 'error' => 'No se pudo cambiar de dominio.']);
    }
}
