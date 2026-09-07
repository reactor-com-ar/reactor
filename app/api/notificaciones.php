<?php

declare(strict_types=1);

/**
 * Historial de notificaciones del DOMINIO activo (modal "Notificaciones").
 *
 * Port de `reactor-app/notificaciones/listar.php`, que consultaba:
 *
 *     select * from notificaciones where usuario=<usuarioId>
 *     order by id desc limit 50
 *
 * ESE FILTRO ESTABA MAL Y ACA YA NO SE REPLICA
 *
 *   `notificaciones` es una tabla DEL DOMINIO: tiene las dos columnas, pero de
 *   las 68.717 filas 68.711 no tienen destinatario y solo llevan `dominio`.
 *   Filtrar por `usuario` fallaba en las dos direcciones a la vez:
 *
 *     - MOSTRABA DE MAS. Las 6 filas que si tenian `usuario` son todas de la
 *       misma cuenta y de CINCO dominios distintos, asi que el modal listaba
 *       avisos de dominios que no eran el que la sesion tiene abierto.
 *     - MOSTRABA DE MENOS. Las 68.711 notificaciones reales del dominio -- las
 *       que la persona entra a ver -- no aparecian nunca.
 *
 *   El criterio ahora es el del dominio activo, y de ese dominio: las que no
 *   tienen destinatario (son para todos los que lo operan) mas las dirigidas a
 *   esta cuenta. El dominio sale del contexto de SESION y no de
 *   `usuarios.dominio`, igual que en `actividad.php` y por la misma razon: si
 *   no, el listado podria mostrar un dominio distinto del que dice el
 *   encabezado.
 *
 * "SIN DESTINATARIO" ES `NULL` O 0, Y LOS DOS TIENEN QUE ENTRAR
 *
 *   La migracion `20260907_1300` paso a NULL los 68.711 ceros que habia, que es
 *   la convencion del repo para el centinela de "sin asignar". Pero el que
 *   escribe estas filas es un proceso del legacy que esta FUERA de este
 *   repositorio y sigue poniendo 0, y la columna no tiene FK que se lo impida.
 *   Exigir `IS NULL` a secas dejaria fuera del modal todo lo que se genere
 *   despues del deploy. Se aceptan los dos y se documenta el porque en la
 *   migracion.
 *
 * EL ORDEN ES CRONOLOGICO AUNQUE DIGA `id`
 *
 *   `ORDER BY id DESC` y `ORDER BY fecha DESC` dan exactamente lo mismo en esta
 *   tabla: medido, 0 inversiones entre las dos columnas en las 68.717 filas, y
 *   0 tambien dentro de cada dominio. Se ordena por `id` porque el indice
 *   `fk_notificaciones_dominio` es, en InnoDB, fisicamente `(dominio, id)` -- los
 *   indices secundarios llevan la PK adosada --, asi que el EXPLAIN da `ref` +
 *   `Backward index scan` sin filesort. Con `fecha`, que no tiene indice, el
 *   dominio mas grande (15.516 filas) ordenaria en memoria para devolver 50.
 *   Mismo razonamiento que `actividad.php`.
 *
 * DOS COSAS QUE EL LEGACY HACE MAL Y ACA SIGUEN CORREGIDAS
 *
 *   1. El texto. `listar.php` imprime `$xNotificacion->asunto` y `->cuerpo`,
 *      pero esas columnas NO existen: la tabla tiene `mensaje`. Por eso en la
 *      pantalla vieja solo se ve la fecha y el resto sale en blanco. Aca se
 *      muestra `mensaje`, que es donde esta el texto de verdad.
 *
 *   2. El marcado de "nueva". El legacy pone en negrita cuando `leida == 1`,
 *      pero en la base no hay ninguna fila con ese valor: son 0 (nueva) o 2
 *      (leida, que es lo que escribe `cNotificacion::leidas()`). Aca se
 *      considera nueva todo lo que no sea 2, asi las nuevas efectivamente se
 *      destacan.
 *
 * `icono` guarda el nombre pelado del glifo ('plug' en las 68.717 filas), no
 * una clase completa, asi que el legacy renderizaba `<i class="plug">` -- nada.
 * Aca se le antepone `fa-solid fa-` cuando hace falta.
 *
 * EFECTO DE BORDE: igual que el legacy, listar marca como leido. Se hace
 * DESPUES de armar la respuesta, para que en esta pasada las nuevas todavia se
 * vean destacadas. Ahora marca lo mismo que se acaba de mostrar, o sea tambien
 * las filas del dominio, y eso ALCANZA A TODO EL DOMINIO: `leida` es una
 * columna de la fila y la fila es compartida, asi que el primero que abre el
 * modal les saca el destaque a los demas. No es una eleccion sino la unica
 * lectura posible del esquema actual -- para tener "leido por persona" haria
 * falta una tabla aparte. La alternativa, marcar solo las dirigidas, deja las
 * del dominio destacadas para siempre y vacia de sentido la marca.
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const NOTIFICACIONES_LIMITE = 50;

/** Valor de `notificaciones.leida` que significa "ya la vio". */
const NOTIFICACION_LEIDA = 2;

/**
 * "Sin destinatario, es para todo el dominio" o "dirigida a esta cuenta".
 *
 * El `usuario = 0` esta de mas despues de `20260907_1300` para las filas que ya
 * existian, pero no para las que el legacy siga insertando. Ver la cabecera.
 */
const NOTIFICACIONES_DESTINATARIO = '(n.usuario IS NULL OR n.usuario = 0 OR n.usuario = :u)';

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
    $ctx = appContextoSesion($sesion);
    $dominio = (int) $ctx['dominio'];
    if ($dominio <= 0) {
        // Sin dominio no hay a que asociar las notificaciones. Mismo criterio
        // que `actividad.php`: lista vacia, no un error.
        responder(200, ['ok' => true, 'notificaciones' => []]);
    }

    $stmt = db()->prepare(
        'SELECT n.fecha, n.icono, n.mensaje, n.leida
         FROM notificaciones n
         WHERE n.dominio = :d AND ' . NOTIFICACIONES_DESTINATARIO . '
         ORDER BY n.id DESC
         LIMIT ' . NOTIFICACIONES_LIMITE
    );
    $stmt->execute([':d' => $dominio, ':u' => $id]);

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

    // Marcar como leidas (mismo efecto que `cNotificacion::leidas()`), con el
    // mismo alcance que el listado: lo que se mostro es lo que se marca.
    if ($notificaciones !== []) {
        try {
            $upd = db()->prepare(
                'UPDATE notificaciones n
                    SET n.leida = :l
                  WHERE n.dominio = :d AND ' . NOTIFICACIONES_DESTINATARIO . '
                    AND n.leida <> :l2'
            );
            $upd->execute([
                ':l'  => NOTIFICACION_LEIDA,
                ':d'  => $dominio,
                ':u'  => $id,
                ':l2' => NOTIFICACION_LEIDA,
            ]);
        } catch (Throwable $_) { /* no bloquea la lectura */ }
    }

    responder(200, $respuesta);
} catch (Throwable $e) {
    responder(500, ['ok' => false, 'error' => 'No se pudieron leer las notificaciones.']);
}
