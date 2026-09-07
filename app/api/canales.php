<?php

declare(strict_types=1);

/**
 * Estado en vivo de los canales del panel abierto (el display de cada control).
 *
 * GET. Devuelve, por control, sus canales con el estado actual y si el equipo
 * está en línea.
 *
 * Port de `reactor-app/panel/monitor.php`, con dos cambios de forma:
 *
 *   - **Devuelve JSON, no HTML.** El legacy imprime los `<div>` ya armados y
 *     el front los mete con `$(...).load()`. Eso obliga a que el servidor sepa
 *     de clases CSS y deja la pantalla a merced de cualquier cosa que se
 *     imprima de más.
 *   - **Una llamada para todo el panel, no una por control.** `monitor.php` se
 *     pide una vez POR CONTROL Y POR SEGUNDO: un panel de 6 controles son 6
 *     requests por segundo, cada uno con su arranque de PHP, su sesión y sus
 *     consultas. Acá es una sola.
 *
 * EL ESTADO NO SALE DE ACÁ NI SE CALCULA: se lee de `canales.estado`, que
 * mantiene el motor Python (`reactor-api/motor/inicio.py`) cuando el equipo
 * reporta. La app no escucha el broker y no escribe esa columna.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';
require_once dirname(__DIR__) . '/lib/controles.php';

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

try {
    $ctx = appContextoSesion($sesion);

    // PERMISO `operacion`: sin él no hay tablero que monitorear. Se responde con
    // la lista VACÍA y no con un 403 porque este endpoint lo llama un sondeo de
    // un segundo: un 403 repetido llenaría la consola del navegador y el log del
    // servidor con un error que no es un error. Es el mismo criterio con el que
    // se contesta cuando no hay panel abierto, dos líneas más abajo. La pantalla
    // que dibuja los controles ya está cerrada por index.php, así que en la
    // práctica el sondeo ni siquiera arranca.
    if (!appPuede($ctx, 'operacion') || $ctx['dominio'] <= 0 || $ctx['panel'] <= 0) {
        responder(200, ['ok' => true, 'controles' => []]);
    }

    $controles = appControlesDelPanel($ctx['panel'], $ctx['dominio']);

    // Sólo lo que cambia con el tiempo. El nombre del control, sus botones y
    // sus iconos ya están en la página: remandarlos cada segundo sería
    // multiplicar el payload por nada.
    $salida = [];
    foreach ($controles as $c) {
        $salida[] = [
            'id'          => $c['id'],
            'online'      => $c['online'],
            'estadoTexto' => $c['estadoTexto'],
            'senal'       => $c['senal'],
            'color'       => $c['color'],
            'canales'     => $c['canales'],
        ];
    }

    responder(200, ['ok' => true, 'controles' => $salida]);
} catch (Throwable $_) {
    responder(500, ['ok' => false, 'error' => 'No se pudo leer el estado de los canales.']);
}
