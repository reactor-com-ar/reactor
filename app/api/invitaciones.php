<?php

declare(strict_types=1);

/**
 * Invitar un usuario al dominio activo.
 *
 *   POST api/invitaciones  {"correo": "..."}  -> alta en `invitaciones` + correo
 *
 * SOLO POST. La app no lista invitaciones ni las edita: quien las quiera ver
 * las tiene en el modulo Invitaciones del panel. El listado del legacy
 * (`reactor-app/dominio/invitar.php` mostraba las emitidas) no se porta porque
 * esta pantalla es un modal de un solo campo.
 *
 * ES EL MISMO ALTA QUE `panel/api/invitaciones.php` -> handleCreate(), sobre la
 * misma tabla y con el mismo canal de salida (correo por Databox v4). Las dos
 * diferencias —a donde apunta el enlace y que perfil se crea al aceptar— viven
 * en lib/invitaciones.php y lib/perfiles.php, no aca.
 *
 * EL UNICO DATO QUE SE PIDE ES EL CORREO, porque es el destino del mensaje. El
 * nombre y el celular del invitado se capturan recien cuando acepta
 * (invitacion/aceptar.php). Es la misma forma del alta legacy, que pedia un
 * solo campo (el celular, que era el destino de WhatsApp) con el canal invertido.
 *
 * ATOMICIDAD: el alta y el envio van en una transaccion. Si el correo no se
 * pudo encolar se revierte el INSERT: una fila pendiente que el destinatario
 * nunca recibio es peor que no tener fila, porque nadie sabe que hay que
 * reintentar. Por eso el modal espera la respuesta en vez de cerrarse optimista.
 *
 * PERMISO `invitacion`: se corta aca ademas de esconder el item del menu en
 * index.php. Esconder un boton no es un control de acceso — el endpoint se
 * puede pedir a mano — y el permiso se resuelve contra la base en cada request
 * y nunca contra un claim del token, que en esta app dura un año.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';
require_once dirname(__DIR__) . '/lib/invitaciones.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

/** `invitaciones.correo` es varchar(100). */
const CORREO_MAX = 100;

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

if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
    header('Allow: POST');
    responder(405, ['ok' => false, 'error' => 'Método no permitido.']);
}

try {
    $ctx = appContextoSesion($sesion);

    if (!appPuede($ctx, 'invitacion')) {
        responder(403, ['ok' => false, 'error' => 'Tu perfil no tiene permiso para invitar usuarios.']);
    }
    if ($ctx['dominio'] <= 0) {
        responder(409, ['ok' => false, 'error' => 'Tu cuenta no tiene un dominio activo.']);
    }

    $emisor = (int) ($sesion['id'] ?? 0);
    if ($emisor <= 0) {
        responder(409, ['ok' => false, 'error' => 'No se pudo identificar quién envía la invitación.']);
    }

    $raw  = file_get_contents('php://input');
    $body = ($raw === false || $raw === '') ? [] : json_decode($raw, true);
    if (!is_array($body)) {
        responder(400, ['ok' => false, 'error' => 'No se entendió el pedido.']);
    }

    $correo = strtolower(trim((string) ($body['correo'] ?? '')));

    if ($correo === '') {
        responder(422, ['ok' => false, 'error' => 'Escribí el correo de la persona que querés invitar.']);
    }
    if (mb_strlen($correo) > CORREO_MAX) {
        responder(422, ['ok' => false, 'error' => 'El correo no puede superar los ' . CORREO_MAX . ' caracteres.']);
    }
    if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
        responder(422, ['ok' => false, 'error' => 'Ese correo no parece válido.']);
    }

    $dominio = (int) $ctx['dominio'];

    // Si esa persona ya entra a este dominio, la invitacion no tiene efecto: al
    // aceptarla el flujo devolveria el perfil que ya tiene y no crearia nada
    // (appPerfilAsegurado()). Mejor decirlo ahora que gastar un correo y dejar
    // una pendiente que no le suma acceso a nadie.
    $ya = db()->prepare(
        'SELECT u.id
           FROM usuarios u
           JOIN perfiles p ON p.usuario = u.id AND p.dominio = :dom AND p.habilitado = 1
          WHERE LOWER(u.correo) = :correo
          LIMIT 1'
    );
    $ya->execute([':dom' => $dominio, ':correo' => $correo]);
    if ($ya->fetchColumn()) {
        responder(409, ['ok' => false, 'error' => 'Esa persona ya tiene acceso a este dominio.']);
    }

    $pdo  = db();
    $uuid = invitacionUuidNuevo($pdo);

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO invitaciones
                (uuid, dominio, emisor, nombre, celular, correo, emitida, abierta, estado)
             VALUES
                (:uuid, :dom, :emisor, NULL, NULL, :correo, NOW(), :abierta, :estado)'
        );
        $stmt->execute([
            ':uuid'    => $uuid,
            ':dom'     => $dominio,
            ':emisor'  => $emisor,
            ':correo'  => $correo,
            // El legacy no usa NULL para "todavia no se abrio": usa el
            // centinela historico, y las pantallas lo leen como vacio.
            ':abierta' => INVITACION_SIN_FECHA,
            ':estado'  => INVITACION_PENDIENTE,
        ]);

        $envio = invitacionEnviarCorreo([
            'uuid'           => $uuid,
            'dominio'        => $dominio,
            'dominio_nombre' => (string) ($ctx['nombre'] ?? ''),
            // El nombre de la cuenta puede venir vacio, asi que `??` no alcanza
            // para caer al usuario de login.
            'emisor_nombre'  => trim((string) ($sesion['nombre'] ?? '')) !== ''
                ? (string) $sesion['nombre']
                : (string) ($sesion['usuario'] ?? ''),
            'nombre'         => '',
            'correo'         => $correo,
        ]);

        if (!$envio['ok']) {
            $pdo->rollBack();
            responder(502, [
                'ok'    => false,
                'error' => 'No se pudo enviar la invitación. Probá de nuevo en un rato.',
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    responder(201, ['ok' => true, 'correo' => $correo]);
} catch (Throwable $e) {
    responder(500, ['ok' => false, 'error' => 'No se pudo enviar la invitación.']);
}
