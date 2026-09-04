<?php

declare(strict_types=1);

/**
 * Invitacion recibida: la pantalla a la que llega el enlace del correo.
 *
 * Porta reactor-app/invitacion/index.php del legacy (que se sigue sirviendo
 * desde app.reactor.com.ar para las invitaciones que salieron por WhatsApp;
 * aca no se toca). Diferencias con el original:
 *
 *  - `abierta` se sella SOLO la primera vez. El legacy lo reescribe en cada
 *    visita, asi que su columna termina siendo "ultima apertura" y no la
 *    primera, que es lo que el listado del panel dice mostrar.
 *  - No se muestra el celular del destinatario: en este circuito todavia no
 *    lo tenemos (se pide al aceptar). En su lugar se muestra el correo, que
 *    es a donde llego la invitacion.
 */

require __DIR__ . '/_layout.php';

$uuid = (string) ($_GET['uid'] ?? '');
$inv  = invitacionPorUuid($uuid);

if ($inv === null) {
    invitacionCorte(
        'Invitación no encontrada',
        'El enlace no corresponde a ninguna invitación. Verificá que lo hayas copiado completo.'
    );
}

// Primera apertura: se sella y no se vuelve a tocar.
$abierta = trim((string) ($inv['abierta'] ?? ''));
if ($abierta === '' || str_starts_with($abierta, '1500-01-01') || str_starts_with($abierta, '0000-00-00')) {
    $sello = db()->prepare('UPDATE invitaciones SET abierta = NOW() WHERE id = :id');
    $sello->execute([':id' => (int) $inv['id']]);
}

$motivo = invitacionMotivoNoVigente($inv);
if ($motivo !== null) {
    invitacionCorte('Invitación no disponible', $motivo);
}

$dominio = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . (int) $inv['dominio']);
$emisor  = trim((string) ($inv['emisor_nombre']  ?? '')) ?: 'Un usuario del dominio';
$uid     = e((string) $inv['uuid']);

$cuerpo = '
    <p class="inv-lead">
        Recibiste una invitación para sumarte a un dominio de Reactor y operar sus dispositivos.
    </p>

    <div class="inv-datos">'
        . invitacionDato('Dominio',  $dominio)
        . invitacionDato('Te invitó', $emisor)
        . invitacionDato('Correo',   (string) $inv['correo'])
        . invitacionDato('Emitida',  invitacionFechaLarga((string) $inv['emitida']))
    . '</div>

    <div class="inv-acciones">
        <form method="post" action="rechazar">
            <input type="hidden" name="uid" value="' . $uid . '">
            <button type="submit" class="btn btn-alt">
                <i class="fa-solid fa-xmark"></i> Rechazar
            </button>
        </form>
        <a class="btn btn-primary" href="aceptar?uid=' . $uid . '">
            <i class="fa-solid fa-check"></i> Aceptar
        </a>
    </div>
';

invitacionLayout('Invitación recibida', $cuerpo);
