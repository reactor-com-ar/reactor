<?php

declare(strict_types=1);

/**
 * Cliente del CRM de Databox (POST /v4/datarocket/prospectos).
 *
 * Un POST da de alta tres cosas de una: el prospecto (la persona o empresa), la
 * oportunidad en el embudo y la interacción que la abre. Por eso `embudo`,
 * `asunto` y `mensaje` viajan juntos y los tres son obligatorios.
 *
 * Es el mismo endpoint y el mismo contrato que usaba el sitio legacy desde
 * `dcProspecto` — se reescribió acá, no se copió, para no arrastrar el
 * framework viejo. Los formularios públicos (contacto, registro de
 * técnicos) dejan el lead donde el equipo comercial ya mira.
 *
 * AUTENTICACIÓN: Bearer con `DATABOX_APIKEY`, la misma constante que usa
 * `databox.php` para el correo. El legacy la leía de la tabla `parametros`
 * (`databox.apikey`); acá sale del .env, como en el resto del repo.
 *
 * NUNCA LANZA. Devuelve `['ok' => bool, 'error' => ?string]` y quien llama
 * decide. En los formularios públicos un fallo del CRM NO puede voltear el
 * envío: el visitante ya completó el formulario y lo que importa es que su
 * consulta llegue, no dónde quedó anotada.
 */

require_once dirname(__DIR__, 2) . '/env.php';

const PROSPECTOS_URL = 'https://api.databox.net.ar/v4/datarocket/prospectos';

/** Timeout total del POST. */
const PROSPECTOS_TIMEOUT = 15;

/** Embudo al que van todas las consultas del sitio público. */
const PROSPECTOS_EMBUDO = 'reactor-ventas';

/**
 * Registra una consulta en el CRM.
 *
 * @param array{nombre:string,correo?:string,celular?:string,asunto:string,mensaje:string} $consulta
 * @return array{ok:bool,error:?string}
 */
function prospectoRegistrar(array $consulta): array
{
    if (!defined('DATABOX_APIKEY') || trim((string) DATABOX_APIKEY) === '') {
        return ['ok' => false, 'error' => 'Falta configurar DATABOX_APIKEY en el .env'];
    }

    $datos = [
        'tipo'           => 'persona',
        'persona_nombre' => trim((string) ($consulta['nombre'] ?? '')),
        'embudo'         => PROSPECTOS_EMBUDO,
        'asunto'         => trim((string) ($consulta['asunto'] ?? '')),
        'mensaje'        => trim((string) ($consulta['mensaje'] ?? '')),
        'canal'          => 'web',
        'origen'         => 'Web',
    ];
    foreach (['correo', 'celular'] as $campo) {
        $valor = trim((string) ($consulta[$campo] ?? ''));
        if ($valor !== '') {
            $datos[$campo] = $valor;
        }
    }

    $resultado = prospectoEnviar($datos);

    // La base histórica arrastra celulares repetidos en varios prospectos.
    // Cuando el celular no alcanza para identificar a uno solo, el
    // microservicio contesta 409 y pide datos de uno solo: se reintenta
    // identificando por correo nada más, con el teléfono metido en el mensaje
    // para que no se pierda el dato.
    if (!$resultado['ok'] && $resultado['codigo'] === 409 && isset($datos['celular'])) {
        $datos['mensaje'] = trim($datos['mensaje'] . ' (celular informado en el formulario: ' . $datos['celular'] . ')');
        unset($datos['celular']);
        $resultado = prospectoEnviar($datos);
    }

    return ['ok' => $resultado['ok'], 'error' => $resultado['error']];
}

/**
 * Un POST al microservicio.
 *
 * @return array{ok:bool,codigo:int,error:?string}
 */
function prospectoEnviar(array $datos): array
{
    // json_encode() devuelve false si algún campo trae bytes que no son UTF-8
    // válido (texto pegado desde Word, por ejemplo). Sin este control el POST
    // viajaría con el cuerpo vacío y el 400 de vuelta no diría nada del origen.
    $cuerpo = json_encode($datos, JSON_UNESCAPED_UNICODE);
    if ($cuerpo === false) {
        return ['ok' => false, 'codigo' => 0, 'error' => 'No se pudo armar el JSON: ' . json_last_error_msg()];
    }

    $ch = curl_init(PROSPECTOS_URL);
    if ($ch === false) {
        return ['ok' => false, 'codigo' => 0, 'error' => 'No se pudo inicializar la conexion con el CRM'];
    }

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $cuerpo,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . DATABOX_APIKEY,
        ],
        CURLOPT_TIMEOUT        => PROSPECTOS_TIMEOUT,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $respuesta = curl_exec($ch);
    $codigo    = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $errCurl   = curl_error($ch);
    // Sin curl_close(): desde PHP 8.0 el handle es un objeto que se libera solo
    // y la función quedó como no-op deprecada.
    unset($ch);

    if ($respuesta === false) {
        return ['ok' => false, 'codigo' => 0, 'error' => 'No se pudo contactar al CRM' . ($errCurl !== '' ? ': ' . $errCurl : '')];
    }

    $body = json_decode((string) $respuesta, true);

    // 201 = prospecto nuevo, 200 = prospecto existente reutilizado; los dos son
    // éxito, así que lo que manda es el `ok` del cuerpo y no el código HTTP.
    if (!is_array($body) || ($body['ok'] ?? false) !== true) {
        $detalle = is_array($body) ? trim((string) ($body['error'] ?? '')) : '';
        error_log('[prospecto] el CRM rechazo la consulta. http: ' . $codigo . ' | respuesta: ' . substr((string) $respuesta, 0, 500));

        return [
            'ok'     => false,
            'codigo' => $codigo,
            'error'  => 'El CRM rechazo la consulta (HTTP ' . $codigo . ')' . ($detalle !== '' ? ': ' . $detalle : ''),
        ];
    }

    return ['ok' => true, 'codigo' => $codigo, 'error' => null];
}
