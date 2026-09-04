<?php

declare(strict_types=1);

/**
 * Historial de actividad del dominio activo (modal "Actividad").
 *
 * Port de `reactor-app/dominio/actividad.php`. Misma consulta:
 *
 *     select * from registros
 *     where sentido='S' and dominio=<sesionDominio>
 *     order by id desc limit 50
 *
 * `sentido='S'` = salida, o sea las ordenes que se mandaron a los equipos
 * (lo que el usuario "hizo"); las de entrada no van a esta pantalla.
 *
 * SOBRE LA PERFORMANCE — LEER ANTES DE "OPTIMIZAR"
 *
 *   `registros` tiene ~3M filas y la regla general del repo es acotar por
 *   `id > MAX(id) - VENTANA` antes de filtrar. Acá NO corresponde:
 *
 *     - El indice `fk_registros_dominio (dominio)` es, en InnoDB, fisicamente
 *       `(dominio, id)` porque los indices secundarios llevan la PK adosada.
 *       El EXPLAIN da `ref` + `Backward index scan`, sin filesort: 4-19 ms
 *       incluso en el dominio mas grande (770K filas).
 *     - Poner la ventana ROMPE el resultado: un dominio cuya ultima actividad
 *       es vieja cae fuera de la ventana y devuelve 0 filas, mostrando un
 *       historial vacio que en realidad existe. Medido: dominio 161 pasa de
 *       50 filas a 0.
 *
 *   Los nombres de usuario / dispositivo / canal se traen con LEFT JOIN en la
 *   misma consulta; el legacy hacia un `id2nombre()` por fila (N+1).
 */

require_once dirname(__DIR__) . '/lib/auth.php';
require_once dirname(__DIR__) . '/lib/contexto.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

const ACTIVIDAD_LIMITE = 50;

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
    // El dominio por el que se filtra sale del contexto de SESION (claim del
    // token, revalidado), no de `appDominioActivo()` a secas: si no, este
    // listado podria estar mostrando el dominio de `usuarios.perfil` mientras
    // el encabezado muestra el del token.
    $ctx = appContextoSesion($sesion);
    if ($ctx['dominio'] <= 0) {
        responder(200, ['ok' => true, 'dominio' => '', 'eventos' => []]);
    }

    $stmt = db()->prepare(
        'SELECT r.fecha, r.estado,
                u.nombre  AS usuario,
                di.nombre AS dispositivo,
                c.nombre  AS canal
         FROM registros r
         LEFT JOIN usuarios     u  ON u.id  = r.usuario
         LEFT JOIN dispositivos di ON di.id = r.dispositivo
         LEFT JOIN canales      c  ON c.id  = r.canal
         WHERE r.sentido = \'S\' AND r.dominio = :d
         ORDER BY r.id DESC
         LIMIT ' . ACTIVIDAD_LIMITE
    );
    $stmt->execute([':d' => $ctx['dominio']]);

    $eventos = [];
    foreach ($stmt->fetchAll() as $r) {
        // Mismo mapeo de `estado` que el switch del legacy.
        $estado = (string) ($r['estado'] ?? '');
        switch ($estado) {
            case '0':
                $texto = 'Apagado';
                $icono = 'fa-solid fa-toggle-off';
                $tono  = 'off';
                break;
            case '1':
                $texto = 'Encendido';
                $icono = 'fa-solid fa-toggle-on';
                $tono  = 'on';
                break;
            default:
                $texto = $estado;
                $icono = 'fa-solid fa-volume-low';
                $tono  = 'otro';
                break;
        }

        $eventos[] = [
            'fecha'       => (string) ($r['fecha'] ?? ''),
            // El legacy mostraba vacio cuando el registro no tenia usuario
            // (105K filas con `usuario` en NULL: son ordenes automaticas).
            'usuario'     => ($r['usuario']     ?? '') !== '' ? (string) $r['usuario']     : '(sin asignar)',
            'dispositivo' => ($r['dispositivo'] ?? '') !== '' ? (string) $r['dispositivo'] : '(sin asignar)',
            'canal'       => ($r['canal']       ?? '') !== '' ? (string) $r['canal']       : '(sin asignar)',
            'texto'       => $texto,
            'icono'       => $icono,
            'tono'        => $tono,
        ];
    }

    responder(200, ['ok' => true, 'dominio' => $ctx['nombre'], 'eventos' => $eventos]);
} catch (Throwable $e) {
    responder(500, ['ok' => false, 'error' => 'No se pudo leer la actividad.']);
}
