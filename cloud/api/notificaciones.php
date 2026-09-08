<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * Valor de `notificaciones`.`leida` que significa "ya la vio".
 *
 * Va ACA ARRIBA y no al pie del archivo: `const` en el cuerpo de un script se
 * define cuando la ejecucion llega a la linea, no se eleva como las funciones,
 * y `handleList()` se llama antes.
 */
const NOTIFICACION_LEIDA = 2;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method !== 'GET') {
        // Modulo read-only: la tabla la escribe un proceso del sistema legacy
        // que esta FUERA de este repositorio (el que genera los avisos
        // "Dispositivo X Online/Offline"). Cloud la mira, no la produce.
        json_error('Metodo no permitido', 405);
    }
    handleList();
} catch (Throwable $e) {
    json_error('Error al procesar notificaciones: ' . $e->getMessage(), 500);
}

/**
 * Listado de notificaciones (db/schema.sql -> tabla `notificaciones`).
 *
 * Campos reales: id, dominio, usuario, fecha, icono, mensaje, destino, leida,
 * visible. Es la tabla que alimenta el modal "Notificaciones" de `app`; aca se
 * ve entera y de todos los dominios, que es lo que cloud aporta sobre esa
 * pantalla: `app/api/notificaciones.php` sirve las 50 ultimas del dominio de la
 * sesion y nada mas.
 *
 * ESTE LISTADO NO MARCA COMO LEIDO, y es la diferencia importante con el de
 * `app`. Alla listar sella `leida = 2` sobre lo que acaba de mostrar, y como la
 * fila es del dominio (no del usuario), el primero que abre el modal les saca
 * el destaque a todos los demas. Si cloud hiciera lo mismo, un controlador
 * mirando el listado dejaria de "nuevas" las notificaciones de un dominio que
 * ninguno de sus usuarios vio todavia -- estaria tocando lo que ve el cliente
 * desde una pantalla que se abrio para auditar. Por eso el endpoint es GET y
 * solo GET.
 *
 * "SIN DESTINATARIO" ES `NULL` O 0, Y LOS DOS SIGNIFICAN LO MISMO. La migracion
 * `20260907_1300` paso a NULL los 68.711 ceros que habia (el centinela del
 * repo), pero el productor sigue fuera de este repositorio y sigue escribiendo
 * 0, y la columna no tiene FK que se lo impida. El filtro por destinatario
 * acepta los dos, igual que el lector de `app`.
 *
 * EL ORDEN ES POR `id` AUNQUE SE LEA COMO CRONOLOGICO. `fecha` no tiene indice
 * y `id` crece con ella (medido: 0 inversiones en las 68.717 filas, tambien
 * dentro de cada dominio). Ademas `fk_notificaciones_dominio` es en InnoDB
 * `(dominio, id)`, asi que filtrar por dominio y ordenar por id sale del indice
 * con `Backward index scan` y sin filesort.
 *
 * LA VENTANA POR `id` DE `registros` / `senales` NO HACE FALTA ACA: son 68.717
 * filas, no 3 millones. Medido en dev: el listado de 100 con los dos LEFT JOIN
 * tarda 2,5 ms y el COUNT(*) 5,8 ms.
 */
function handleList(): void
{
    $dominio = isset($_GET['dominio']) ? (int) $_GET['dominio'] : 0;
    $limit   = isset($_GET['limit'])   ? (int) $_GET['limit']   : 100;
    if ($limit <= 0 || $limit > 2000) $limit = 100;

    // Filtro por estado de lectura. `leida` vale 0 (nueva) o 2 (leida) en los
    // datos reales: NO hay ninguna fila en 1, aunque el legacy pinte en negrita
    // ese valor. Se considera nueva todo lo que no sea 2, que es lo mismo que
    // hace `app/api/notificaciones.php`.
    $estado = isset($_GET['estado']) ? strtolower(trim((string) $_GET['estado'])) : '';
    if ($estado !== 'nuevas' && $estado !== 'leidas') $estado = '';

    // Filtro por destinatario: las del dominio (sin destinatario) contra las
    // dirigidas a una cuenta. No es un select de usuarios como en Adopciones a
    // proposito: solo 6 de las 68.717 filas tienen destinatario, asi que la
    // pregunta util no es "cual" sino "tiene o no".
    $destinatario = isset($_GET['destinatario']) ? strtolower(trim((string) $_GET['destinatario'])) : '';
    if ($destinatario !== 'dominio' && $destinatario !== 'usuario') $destinatario = '';

    $sql = 'SELECT n.id, n.dominio, n.usuario, n.fecha, n.icono, n.mensaje,
                   n.destino, n.leida, n.visible,
                   dom.nombre AS dominio_nombre,
                   u.nombre   AS usuario_nombre,
                   u.usuario  AS usuario_login
            FROM notificaciones n
            LEFT JOIN dominios dom ON dom.id = n.dominio
            LEFT JOIN usuarios u   ON u.id   = n.usuario';

    $where  = [];
    $params = [];
    if ($dominio > 0) {
        $where[]        = 'n.dominio = :dom';
        $params[':dom'] = $dominio;
    }
    if ($estado === 'nuevas') {
        $where[] = '(n.leida IS NULL OR n.leida <> ' . NOTIFICACION_LEIDA . ')';
    } elseif ($estado === 'leidas') {
        $where[] = 'n.leida = ' . NOTIFICACION_LEIDA;
    }
    if ($destinatario === 'dominio') {
        $where[] = '(n.usuario IS NULL OR n.usuario = 0)';
    } elseif ($destinatario === 'usuario') {
        $where[] = '(n.usuario IS NOT NULL AND n.usuario <> 0)';
    }
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);

    $sql .= ' ORDER BY n.id DESC LIMIT ' . $limit;

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $notificaciones = array_map(static function (array $r): array {
        $r['id']      = (int) $r['id'];
        $r['dominio'] = $r['dominio'] !== null ? (int) $r['dominio'] : null;
        // El 0 del legacy se normaliza a null en la RESPUESTA aunque siga en la
        // base: para el front "sin destinatario" es un solo caso.
        $usuario      = $r['usuario'] !== null ? (int) $r['usuario'] : null;
        $r['usuario'] = ($usuario === null || $usuario === 0) ? null : $usuario;
        $r['leida']   = $r['leida']   !== null ? (int) $r['leida']   : null;
        $r['visible'] = $r['visible'] !== null ? (int) $r['visible'] : null;
        $r['nueva']   = ((int) ($r['leida'] ?? 0)) !== NOTIFICACION_LEIDA;
        // `icono` guarda el glifo pelado ('plug' en las 68.717 filas), no una
        // clase completa: el legacy renderizaba `<i class="plug">`, o sea nada.
        // Se completa aca y no en el front para que la regla viva en un solo
        // lado -- es la misma que aplica `app/api/notificaciones.php`.
        $r['icono_clase'] = notificacionIconoClase($r['icono']);
        return $r;
    }, $stmt->fetchAll());

    $total = (int) db()->query('SELECT COUNT(*) FROM notificaciones')->fetchColumn();

    $nuevas = (int) db()->query(
        'SELECT COUNT(*) FROM notificaciones WHERE leida IS NULL OR leida <> ' . NOTIFICACION_LEIDA
    )->fetchColumn();

    $dominios = (int) db()->query(
        'SELECT COUNT(DISTINCT dominio) FROM notificaciones WHERE dominio IS NOT NULL'
    )->fetchColumn();

    $resumen = [
        'total'    => $total,
        'nuevas'   => $nuevas,
        'leidas'   => $total - $nuevas,
        'dominios' => $dominios,
    ];

    json_ok([
        'resumen'         => $resumen,
        'notificaciones'  => $notificaciones,
        'limit'           => $limit,
    ]);
}

/**
 * Clase de FontAwesome para el glifo guardado en `notificaciones`.`icono`.
 *
 * La columna guarda el nombre pelado ('plug'), no la clase: se le antepone
 * `fa-solid fa-` salvo que ya venga con el prefijo. Sin icono, un generico.
 */
function notificacionIconoClase(?string $icono): string
{
    $icono = trim((string) $icono);
    if ($icono === '')                    return 'fa-solid fa-circle-info';
    if (str_contains($icono, 'fa-'))      return $icono;
    return 'fa-solid fa-' . $icono;
}
