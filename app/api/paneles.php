<?php

declare(strict_types=1);

/**
 * Paneles del dominio activo (modal "Cambiar de Panel").
 *
 * GET  -> lista los paneles del dominio y marca cual esta abierto.
 * POST -> cambia el panel abierto. Body: {"panel": <id>}.
 *
 * Port de `reactor-app/cambiar/panel.php`. Misma consulta para la lista:
 *
 *     select id from paneles
 *     where (dominio=<sesionDominio>) and (habilitado='1')
 *     order by nombre
 *
 * ...y de cada uno se muestra `paneles.nombre`.
 *
 * CUAL ESTA ABIERTO: `perfiles.panel` guarda el ultimo panel que uso ESE
 * perfil (no el usuario, no el dominio), y es lo que el legacy reescribia al
 * elegir uno. Si viene vacio — perfil que nunca abrio ninguno — se marca el
 * primero de la lista, que es el que abriria `cPanel::perfil2id()`.
 *
 * DONDE QUEDA EL CAMBIO — en los DOS lados, igual que el legacy:
 *
 *   1. `perfiles.panel`, la columna (`$xPerfil->panel = ...; ->modificar()`).
 *   2. El claim `pan` del token de sesion, que se reemite. Es el analogo de
 *      `$oSesion->escribir('sesionPanel', ...)`: esta app no tiene sesion de
 *      servidor, asi que el estado de sesion viaja adentro del JWT.
 *
 * Los dos, y no uno solo, por la misma razon que el legacy: la columna es el
 * dato durable (sobrevive al cierre de sesion y es lo que lee el proximo
 * login) y el token es el estado de la sesion en curso. Ver
 * `appContextoSesion()` en lib/contexto.php por la precedencia entre los dos
 * y por que el claim se revalida en cada request en vez de creerse a ciegas.
 *
 * NOTA: en el legacy el icono del pager ni siquiera aparece en la topbar
 * cuando el dominio tiene un solo panel (`if ($xPanel->cantidad(...) > 1)`).
 * Hoy son 134 de 144 dominios con un unico panel. Aca el boton se muestra
 * siempre; el endpoint devuelve `total` por si despues se quiere replicar
 * ese ocultamiento.
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
    cambiarPanel($sesion);
}

if ($metodo !== 'GET') {
    header('Allow: GET, POST');
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

try {
    // `appContextoSesion()` es la que aplica la precedencia token -> base, la
    // misma que usa la franja del encabezado de index.php. Con
    // `appDominioActivo()` a secas, el GET marcaria como actual el panel de la
    // columna y la franja mostraria el del token: dos respuestas distintas a
    // "cual esta abierto" en la misma pantalla.
    $ctx = appContextoSesion($sesion);
    if ($ctx['dominio'] <= 0) {
        responder(200, ['ok' => true, 'dominio' => '', 'total' => 0, 'paneles' => []]);
    }

    $res = appPanelesDelDominio($ctx['dominio'], $ctx['panel']);

    responder(200, [
        'ok'      => true,
        'dominio' => $ctx['nombre'],
        'total'   => count($res['paneles']),
        'paneles' => $res['paneles'],
    ]);
} catch (Throwable $e) {
    responder(500, ['ok' => false, 'error' => 'No se pudieron leer los paneles.']);
}

/**
 * Cambia el panel abierto del perfil activo.
 *
 * Equivale al bloque `if ($oFormulario->getpost('pro') != '')` del legacy, con
 * una diferencia deliberada: **se valida que el panel sea del dominio de la
 * sesion**. El legacy solo comprobaba que el uuid resolviera a un id
 * (`uuid2id() > 0`) y escribia, asi que un uuid de otro dominio dejaba
 * `perfiles.panel` apuntando a un panel ajeno — y ese panel es el que despues
 * decide que controles se muestran. Aca el id se busca contra la lista de
 * paneles habilitados del dominio activo, que es la misma que sirve el GET.
 */
function cambiarPanel(array $sesion): never
{
    $crudo   = file_get_contents('php://input');
    $entrada = json_decode($crudo !== false ? $crudo : '', true);
    if (!is_array($entrada)) {
        responder(400, ['ok' => false, 'error' => 'Pedido inválido.']);
    }

    // El front manda `paneles.id` (es lo que sirve el GET). El legacy usaba el
    // uuid porque viajaba en la URL de un <a>; aca va en el body de un POST.
    $panel = (int) ($entrada['panel'] ?? 0);
    if ($panel <= 0) {
        responder(400, ['ok' => false, 'error' => 'Falta el panel.']);
    }

    try {
        $ctx = appContextoSesion($sesion);
        if ($ctx['perfil'] <= 0) {
            responder(409, ['ok' => false, 'error' => 'Tu cuenta no tiene un perfil activo.']);
        }

        $res = appPanelesDelDominio($ctx['dominio'], $ctx['panel']);

        $elegido = null;
        foreach ($res['paneles'] as $p) {
            if ($p['id'] === $panel) {
                $elegido = $p;
                break;
            }
        }
        if ($elegido === null) {
            responder(404, ['ok' => false, 'error' => 'Ese panel no es de este dominio.']);
        }

        // 1) El dato durable: `perfiles.panel` del perfil activo. Igual que
        //    `$xPerfil->panel = $xPanel->id; $xPerfil->modificar();`.
        $stmt = db()->prepare('UPDATE perfiles SET panel = :panel WHERE id = :perfil');
        $stmt->execute([':panel' => $panel, ':perfil' => $ctx['perfil']]);

        // 2) El estado de sesion: se reemite el token con el `pan` nuevo. Es
        //    el `$oSesion->escribir('sesionPanel', ...)` del legacy. Va DESPUES
        //    del UPDATE para no dejar una cookie que prometa un panel que no se
        //    llego a guardar.
        appSesionEmitir($sesion, [
            'per' => $ctx['perfil'],
            'dom' => $ctx['dominio'],
            'pan' => $panel,
        ]);

        responder(200, [
            'ok'     => true,
            'panel'  => $elegido['id'],
            'nombre' => $elegido['nombre'],
        ]);
    } catch (Throwable $_) {
        responder(500, ['ok' => false, 'error' => 'No se pudo cambiar el panel.']);
    }
}
