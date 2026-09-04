<?php

declare(strict_types=1);

/**
 * Historial de notificaciones del usuario (modal "Notificaciones").
 *
 * Port de `reactor-app/notificaciones/listar.php`. Misma consulta:
 *
 *     select * from notificaciones where usuario=<usuarioId>
 *     order by id desc limit 50
 *
 * DOS COSAS QUE EL LEGACY HACE MAL Y ACA ESTAN CORREGIDAS
 *
 *   1. El texto. `listar.php` imprime `$xNotificacion->asunto` y `->cuerpo`,
 *      pero esas columnas NO existen: la tabla tiene `mensaje`. Por eso en la
 *      pantalla vieja solo se ve la fecha y el resto sale en blanco. Aca se
 *      muestra `mensaje`, que es donde esta el texto de verdad (68.717 filas,
 *      todas con contenido).
 *
 *   2. El marcado de "nueva". El legacy pone en negrita cuando `leida == 1`,
 *      pero en la base no hay ninguna fila con ese valor: son 0 (nueva) o 2
 *      (leida, que es lo que escribe `cNotificacion::leidas()`). Aca se
 *      considera nueva todo lo que no sea 2, asi las nuevas efectivamente se
 *      destacan.
 *
 * `icono` guarda el nombre pelado del glifo ('plug' en las 68.717 filas), no
 * una clase completa, asi que el legacy renderizaba `<i class="plug">` — nada.
 * Aca se le antepone `fa-solid fa-` cuando hace falta.
 *
 * EFECTO DE BORDE: igual que el legacy, listar marca todo como leido. Se hace
 * DESPUES de armar la respuesta, para que en esta pasada las nuevas todavia se
 * vean destacadas.
 */

require_once dirname(__DIR__) . '/lib/auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const NOTIFICACIONES_LIMITE = 50;

/** Valor de `notificaciones.leida` que significa "ya la vio". */
const NOTIFICACION_LEIDA = 2;

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

$id = (int) $sesion['id'];

try {
    $stmt = db()->prepare(
        'SELECT fecha, icono, mensaje, leida
         FROM notificaciones
         WHERE usuario = :u
         ORDER BY id DESC
         LIMIT ' . NOTIFICACIONES_LIMITE
    );
    $stmt->execute([':u' => $id]);

    $notificaciones = [];
    foreach ($stmt->fetchAll() as $r) {
        $icono = trim((string) ($r['icono'] ?? ''));
        if ($icono === '') {
            $icono = 'fa-solid fa-circle-info';
        } elseif (!str_contains($icono, 'fa-')) {
            $icono = 'fa-solid fa-' . $icono;
        }

        $notificaciones[] = [
            'fecha'   => (string) ($r['fecha'] ?? ''),
            'mensaje' => (string) ($r['mensaje'] ?? ''),
            'icono'   => $icono,
            'nueva'   => ((int) ($r['leida'] ?? 0)) !== NOTIFICACION_LEIDA,
        ];
    }

    $respuesta = ['ok' => true, 'notificaciones' => $notificaciones];

    // Marcar como leidas (mismo efecto que `cNotificacion::leidas()`).
    if ($notificaciones !== []) {
        try {
            $upd = db()->prepare('UPDATE notificaciones SET leida = :l WHERE usuario = :u AND leida <> :l2');
            $upd->execute([':l' => NOTIFICACION_LEIDA, ':u' => $id, ':l2' => NOTIFICACION_LEIDA]);
        } catch (Throwable $_) { /* no bloquea la lectura */ }
    }

    responder(200, $respuesta);
} catch (Throwable $e) {
    responder(500, ['ok' => false, 'error' => 'No se pudieron leer las notificaciones.']);
}
