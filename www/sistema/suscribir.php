<?php

declare(strict_types=1);

/**
 * Alta al boletín semanal. Es el destino del formulario del pie.
 *
 * A DÓNDE VA LA DIRECCIÓN. El legacy la mandaba a una lista de Mailjet
 * (`$aMailjet->suscriptorAgregar(103, $correo)`) con el cliente de Mailjet que
 * vivía en el framework viejo. Ese cliente no está en este repo y traerlo sería
 * exactamente la librería PHP que no se quiere arrastrar, así que la suscripción
 * entra por el mismo canal que las otras dos altas públicas del sitio: el CRM de
 * Databox, con un asunto que la identifica. Queda en el mismo lugar donde el
 * equipo ya ve los contactos y no hace falta una integración más.
 *
 * (Si en algún momento se quiere volver a una lista de correo de verdad, el
 * punto a cambiar es sólo éste: el formulario del pie y la pantalla de
 * confirmación no cambian.)
 *
 * SÓLO ACEPTA POST. Con GET se podía dar de alta a cualquiera desde una URL
 * —basta con que alguien la comparta o la meta en un `<img src>`— y encima los
 * buscadores la seguían y suscribían direcciones al pasar.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/prospectos.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    wwwIr('/');
}

$correo = wwwEntrada('correo');

if ($correo === '' || filter_var($correo, FILTER_VALIDATE_EMAIL) === false) {
    wwwAviso(
        'Ese correo no parece válido. Revisalo y probá de nuevo.',
        '/',
        'Revisá el correo'
    );
}

$registro = prospectoRegistrar([
    // El CRM pide un nombre y acá no hay más que la dirección: se usa la parte
    // de antes de la arroba, que es lo único que la persona dio.
    'nombre'  => explode('@', $correo)[0],
    'correo'  => $correo,
    'asunto'  => 'Suscripción al boletín',
    'mensaje' => 'Alta al boletín semanal desde el pie del sitio público.',
]);

if (!$registro['ok']) {
    error_log('[suscribir] no se pudo registrar la suscripcion: ' . (string) $registro['error']);
    wwwAviso(
        'Ocurrió un error al registrar tu suscripción. Intentalo nuevamente más tarde.',
        '/',
        'No pudimos suscribirte'
    );
}

wwwAviso(
    'Muchas gracias por suscribirte a nuestro boletín. Estaremos en contacto.',
    '/',
    '¡Listo!'
);
