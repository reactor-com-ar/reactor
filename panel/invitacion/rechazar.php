<?php

declare(strict_types=1);

/**
 * Rechazo de una invitacion. Porta reactor-app/invitacion/rechazar.php.
 *
 * Solo POST: en el legacy el rechazo es un <a href> y cualquier prefetch
 * (el antivirus del correo, el preview del cliente de mail, el navegador)
 * puede dispararlo sin que la persona lo haya elegido. Un GET no cambia
 * estado; se limita a devolver al invitado a la ficha.
 */

require __DIR__ . '/_layout.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuid   = (string) ($_POST['uid'] ?? $_GET['uid'] ?? '');

if ($metodo !== 'POST') {
    header('Location: ./?uid=' . rawurlencode($uuid));
    exit;
}

$inv = invitacionPorUuid($uuid);
if ($inv === null) {
    invitacionCorte(
        'Invitación no encontrada',
        'El enlace no corresponde a ninguna invitación. Verificá que lo hayas copiado completo.'
    );
}

$motivo = invitacionMotivoNoVigente($inv);
if ($motivo !== null) {
    invitacionCorte('Invitación no disponible', $motivo);
}

// El filtro por estado en el propio UPDATE evita que dos envios simultaneos
// (doble click, reintento) pisen una invitacion ya resuelta.
$stmt = db()->prepare(
    'UPDATE invitaciones SET estado = :rechazada WHERE id = :id AND estado = :pendiente'
);
$stmt->execute([
    ':rechazada' => INVITACION_RECHAZADA,
    ':pendiente' => INVITACION_PENDIENTE,
    ':id'        => (int) $inv['id'],
]);

invitacionCorte(
    'Invitación rechazada',
    'Listo, rechazaste la invitación. No se creó ninguna cuenta y no vas a recibir más avisos por este enlace.',
    'ok'
);
