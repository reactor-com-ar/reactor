<?php

declare(strict_types=1);

/**
 * Cliente del microservicio de correo de Databox (POST /v4/aws/mensajes).
 *
 * Reemplaza el camino que usaba el legacy para las invitaciones
 * (acDatafly::mensajeEncolar('W', ...) -> WhatsApp via whapi): ahora la
 * invitacion viaja por correo. El legacy NO se toca y sigue mandando por
 * WhatsApp desde reactor-app; los dos caminos conviven sobre la misma tabla.
 *
 * AUTENTICACION: Bearer con `DATABOX_APIKEY`, que ya vive en los .env del
 * repo y env.php define como constante. No se hardcodea en ningun lado.
 *
 * SLUGS: los valores por defecto son los mismos que usaba el legacy en
 * reactor-api/framework/dataframework.env (proyecto `reactor`, canal
 * `databox`, plantilla `reactor`, remite info@reactor.com.ar), para que los
 * correos del panel salgan por la misma cuenta SES y con la misma identidad
 * visual que los del sistema viejo. Cada uno se puede pisar agregando la
 * constante correspondiente al .env sin tocar codigo.
 *
 * PLANTILLA: con `plantilla_slug` el microservicio arma el mensaje antes de
 * encolarlo -- `remitente`, `remite` y `formato` salen de la plantilla, y
 * `asunto` / `cuerpo` reemplazan los marcadores {asunto} / {cuerpo}. Si la
 * plantilla no estuviera dada de alta en v4, basta con dejar
 * DATABOX_PLANTILLA vacia en el .env: el helper cae al modo sin plantilla y
 * manda remitente/remite/formato explicitos.
 *
 * ERRORES: esta funcion NUNCA lanza. Devuelve ['ok' => bool, ...] para que
 * el que llama decida que hacer -- en el alta de invitaciones, por ejemplo,
 * un fallo de envio revierte la fila en vez de dejarla huerfana.
 */

// Idempotente (guard SECRETS_LOADED): define DATABOX_APIKEY y los overrides.
require_once dirname(__DIR__, 2) . '/env.php';

const DATABOX_MENSAJES_URL = 'https://api.databox.net.ar/v4/aws/mensajes';

/** Timeout total del POST. El microservicio solo encola: responde rapido. */
const DATABOX_TIMEOUT = 15;

/**
 * Valor de configuracion: la constante del .env si existe y no esta vacia,
 * si no el default heredado del legacy.
 */
function databoxOpcion(string $constante, string $default): string
{
    if (!defined($constante)) {
        return $default;
    }
    $valor = trim((string) constant($constante));
    // Una constante definida pero vacia es una decision explicita del .env
    // (ej. DATABOX_PLANTILLA= para desactivar la plantilla), no un descuido:
    // se respeta el vacio en vez de caer al default.
    return $valor;
}

/**
 * Encola un correo en el microservicio.
 *
 * @param array{destino:string,asunto:string,cuerpo:string,destinatario?:string,prioridad?:int,tags?:string} $mensaje
 * @return array{ok:bool,id:?int,error:?string}
 */
function databoxCorreoEncolar(array $mensaje): array
{
    if (!defined('DATABOX_APIKEY') || trim((string) DATABOX_APIKEY) === '') {
        return ['ok' => false, 'id' => null, 'error' => 'Falta configurar DATABOX_APIKEY en el .env'];
    }

    $destino = trim((string) ($mensaje['destino'] ?? ''));
    if ($destino === '') {
        return ['ok' => false, 'id' => null, 'error' => 'El destino del correo esta vacio'];
    }

    $plantilla = databoxOpcion('DATABOX_PLANTILLA', 'reactor');

    $payload = [
        'proyecto_slug' => databoxOpcion('DATABOX_PROYECTO', 'reactor'),
        'canal_slug'    => databoxOpcion('DATABOX_CANAL', 'databox'),
        'destino'       => $destino,
        'asunto'        => (string) ($mensaje['asunto'] ?? ''),
        'cuerpo'        => (string) ($mensaje['cuerpo'] ?? ''),
        'prioridad'     => (int) ($mensaje['prioridad'] ?? 4),
        // `remitente` / `remite` / `formato` los pisa la plantilla cuando hay
        // una; se mandan igual para que el modo sin plantilla funcione sin
        // ningun otro cambio.
        'remitente'     => databoxOpcion('DATABOX_REMITENTE', 'Reactor'),
        'remite'        => databoxOpcion('DATABOX_REMITE', 'info@reactor.com.ar'),
        'formato'       => 'html',
    ];

    if ($plantilla !== '') {
        $payload['plantilla_slug'] = $plantilla;
    }
    if (trim((string) ($mensaje['destinatario'] ?? '')) !== '') {
        $payload['destinatario'] = trim((string) $mensaje['destinatario']);
    }
    if (trim((string) ($mensaje['tags'] ?? '')) !== '') {
        $payload['tags'] = trim((string) $mensaje['tags']);
    }

    $ch = curl_init(DATABOX_MENSAJES_URL);
    if ($ch === false) {
        return ['ok' => false, 'id' => null, 'error' => 'No se pudo inicializar la conexion con Databox'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . DATABOX_APIKEY,
        ],
        CURLOPT_TIMEOUT        => DATABOX_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $respuesta = curl_exec($ch);
    $estado    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errCurl   = curl_error($ch);
    // Sin curl_close(): desde PHP 8.0 el handle es un objeto que se libera
    // solo, y la funcion quedo como no-op deprecada.
    unset($ch);

    if ($respuesta === false) {
        return ['ok' => false, 'id' => null, 'error' => 'No se pudo contactar al servicio de correo' . ($errCurl !== '' ? ': ' . $errCurl : '')];
    }

    $body = json_decode((string) $respuesta, true);
    if (!is_array($body)) {
        return ['ok' => false, 'id' => null, 'error' => 'El servicio de correo devolvio una respuesta ilegible (HTTP ' . $estado . ')'];
    }
    if ($estado < 200 || $estado >= 300 || ($body['ok'] ?? false) !== true) {
        $detalle = trim((string) ($body['error'] ?? ''));
        return [
            'ok'    => false,
            'id'    => null,
            'error' => 'El servicio de correo rechazo el envio (HTTP ' . $estado . ')' . ($detalle !== '' ? ': ' . $detalle : ''),
        ];
    }

    $id = $body['data']['id'] ?? null;

    return ['ok' => true, 'id' => $id !== null ? (int) $id : null, 'error' => null];
}
