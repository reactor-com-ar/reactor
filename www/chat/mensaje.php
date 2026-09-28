<?php

declare(strict_types=1);

/**
 * El endpoint del chat: `POST /chat/mensaje`, JSON de ida y de vuelta.
 *
 * El `.htaccess` de la raíz ya resuelve `/chat/mensaje` -> `chat/mensaje.php`,
 * así que no hay ruta que agregar. ES UN ARCHIVO SUELTO Y NO UN `index.php` DE
 * CARPETA a propósito: a una carpeta Apache le manda un 301 a la URL con barra
 * final, y en un POST el navegador reintenta en GET y pierde el cuerpo — el
 * mismo motivo por el que el formulario de contacto apunta con barra.
 *
 * EL NAVEGADOR MANDA DOS COSAS: el `uuid` de la conversación (vacío la primera
 * vez) y el texto nuevo. El historial NO viaja en el POST: se arma acá desde
 * `conversaciones_mensajes`. Si viajara, cualquiera podría reescribir lo que "ya
 * se dijo" —incluidas las reglas del sistema— y hacernos pagar el contexto que
 * se le antoje.
 *
 * TODO LO QUE SALE DE ACÁ ES JSON, incluidos los errores. La burbuja muestra el
 * campo `error` tal cual, así que esos textos son para leer: no hay pantalla de
 * error atrás.
 *
 * ORDEN DE LOS CONTROLES, y por qué es ése:
 *
 *   1. método y origen      descartan lo que no es la burbuja del sitio.
 *   2. chat prendido        sin key no se hace nada.
 *   3. largo del mensaje    barato, y evita contar contra el cupo un texto que
 *                           igual se iba a rechazar.
 *   4. cupo diario global   antes que el de IP: es el que protege la factura.
 *   5. cupo por IP
 *   6. cupo de la conversación (lo toma el UPDATE condicional)
 *
 * Recién después se le habla a OpenAI. Cada control que se corra para abajo es
 * un peso más de gasto por request rechazado.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/chat.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/conversaciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** Contesta y corta. */
function chatSalida(array $datos, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Un error para mostrar en la burbuja. `derivar` prende el botón de WhatsApp. */
function chatError(string $mensaje, int $codigo, bool $derivar = false): never
{
    chatSalida(['ok' => false, 'error' => $mensaje, 'derivar' => $derivar], $codigo);
}

// ---------------------------------------------------------------------------
// 1. Método y origen.
// ---------------------------------------------------------------------------
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    chatError('El chat sólo responde a POST.', 405);
}

// El request tiene que venir de una página del propio sitio. NO ES EL CONTROL
// DE ACCESO —quien escribe el POST a mano pone la cabecera que quiera— pero
// saca todo lo automático y, sobre todo, impide que otro sitio embeba la
// burbuja y nos facture las consultas de sus visitantes. Lo que de verdad frena
// el gasto son los cupos de más abajo.
$hostPropio = strtolower((string) ($_SERVER['HTTP_HOST'] ?? ''));
$origen     = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
$hostOrigen = strtolower((string) parse_url($origen, PHP_URL_HOST));

if ($hostOrigen === '' || $hostPropio === '' || $hostOrigen !== preg_replace('/:\d+$/', '', $hostPropio)) {
    chatError('El chat sólo funciona desde el sitio de Reactor.', 403);
}

// ---------------------------------------------------------------------------
// 2. ¿Está prendido?
// ---------------------------------------------------------------------------
if (!chatActivo()) {
    chatError('El asistente no está disponible en este momento.', 503, true);
}

// ---------------------------------------------------------------------------
// 3. Lo que llegó.
// ---------------------------------------------------------------------------
$entrada = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($entrada)) {
    chatError('No se entendió el pedido.', 400);
}

$texto  = trim((string) ($entrada['texto'] ?? ''));
$uuid   = trim((string) ($entrada['uuid'] ?? ''));
$pagina = trim((string) ($entrada['pagina'] ?? ''));

if ($texto === '') {
    chatError('Escribí tu consulta.', 400);
}

// Se rechaza, no se recorta. Cortar en el caracter 600 manda al modelo media
// pregunta y le hace contestar otra cosa, sin que la persona entienda por qué.
if (mb_strlen($texto) > CHAT_MENSAJE_MAXIMO) {
    chatError('El mensaje es muy largo: probá con menos de ' . CHAT_MENSAJE_MAXIMO . ' caracteres.', 400);
}

// ---------------------------------------------------------------------------
// 4 y 5. Los cupos que no dependen de la conversación.
// ---------------------------------------------------------------------------
try {
    if (conversacionesDelDia() >= CHAT_TOPE_DIARIO) {
        chatError('El asistente atendió muchas consultas hoy. Escribinos por WhatsApp y te respondemos.', 429, true);
    }

    if (conversacionesPorOrigen() >= CHAT_TOPE_ORIGEN) {
        chatError('Llegaste al límite de consultas por hora. Escribinos por WhatsApp y seguimos por ahí.', 429, true);
    }

    // -----------------------------------------------------------------------
    // 6. La conversación y su cupo.
    // -----------------------------------------------------------------------
    if ($uuid === '') {
        $nueva = conversacionAbrir($pagina);
        $uuid  = $nueva['uuid'];
    }

    $conversacion = conversacionTomarTurno($uuid, CHAT_TOPE_CONVERSACION);

    if ($conversacion === null) {
        // Dos motivos distintos con dos respuestas distintas: un uuid que no
        // existe es una conversación vieja que ya no está (o inventada) y se
        // resuelve arrancando de nuevo; una sin cupo ya fue larga y lo que
        // corresponde es pasarla a una persona.
        $sinCupo = conversacionExiste($uuid);

        chatError(
            $sinCupo
                ? 'Esta conversación ya fue larga. Seguila por WhatsApp así te atendemos mejor.'
                : 'La conversación expiró. Recargá la página y escribinos de nuevo.',
            429,
            $sinCupo
        );
    }

    conversacionMensajeAlta($conversacion['id'], 'user', $texto);

    // El historial ya trae el mensaje que se acaba de guardar, así que la
    // pregunta viaja una sola vez.
    $uuidsContexto = [];
    $mensajes      = array_merge(
        [['role' => 'system', 'content' => chatSistema($texto, $uuidsContexto)]],
        conversacionHistorial($conversacion['id'])
    );
} catch (Throwable $e) {
    error_log('[chat] fallo la base: ' . $e->getMessage());
    chatError('No pudimos procesar la consulta. Escribinos por WhatsApp.', 500, true);
}

// ---------------------------------------------------------------------------
// Y recién ahora, la llamada paga.
// ---------------------------------------------------------------------------
$respuesta = chatResponder($mensajes);

if (!$respuesta['ok']) {
    // El motivo real ya quedó en el log de `chatResponder()`. Afuera va un
    // mensaje neutro: la respuesta de OpenAI puede decir que la cuota se agotó o
    // que la key venció, y eso no se publica.
    chatSalida([
        'ok'      => false,
        'uuid'    => $uuid,
        'error'   => 'No pude contestarte ahora mismo. Escribinos por WhatsApp y te ayudamos.',
        'derivar' => true,
    ], 502);
}

try {
    conversacionMensajeAlta(
        $conversacion['id'],
        'assistant',
        $respuesta['texto'],
        $respuesta['modelo'],
        implode(',', $uuidsContexto),
        $respuesta['tokens_entrada'],
        $respuesta['tokens_salida']
    );
} catch (Throwable $e) {
    // La respuesta ya está: se la mostramos igual. Perder el registro es peor
    // que perder la respuesta, pero no tanto como para tirarle un error a quien
    // preguntó.
    error_log('[chat] no se pudo guardar la respuesta: ' . $e->getMessage());
}

chatSalida([
    'ok'       => true,
    'uuid'     => $uuid,
    'texto'    => $respuesta['texto'],
    'restante' => max(0, CHAT_TOPE_CONVERSACION - $conversacion['mensajes']),
]);
