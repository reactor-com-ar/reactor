<?php

declare(strict_types=1);

// Programador de tareas — historial de ejecuciones + POST accion:'detener'.
// GET api/tareas_ejecuciones.php?tarea_id=N&estado=...&limite=M
// GET api/tareas_ejecuciones.php?id=N       -> detalle de una ejecucion
// POST api/tareas_ejecuciones.php  {id, accion:'detener'}
// Tabla real: `tareas_ejecuciones` (el .php mantiene su nombre por URL).

require __DIR__ . '/bootstrap.php';

$sHelper = __DIR__ . '/lib/sucesos.php';
if (is_file($sHelper)) require_once $sHelper;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
try {
    if ($method === 'OPTIONS') exit;
    if ($method === 'GET')  handleGetEjecuciones();
    elseif ($method === 'POST') handlePostEjecuciones();
    else json_error('metodo_no_soportado', 405);
} catch (Throwable $e) {
    if (function_exists('registrarSuceso')) {
        try { registrarSuceso(db(), 'cron/tareas_ejecuciones.' . strtolower($method), 'error', $e->getMessage()); }
        catch (Throwable $_) {}
    }
    json_error('Error en tareas_ejecuciones: ' . $e->getMessage(), 500);
}

function normalizarEjec(array $r): array
{
    return [
        'id'                => (int) ($r['id'] ?? 0),
        'tarea_id'          => (int) ($r['tarea_id'] ?? 0),
        'pid'               => $r['pid'] !== null ? (int) $r['pid'] : null,
        'inicio'            => $r['inicio'] ?? null,
        'fin'               => $r['fin']    ?? null,
        'estado'            => (string) ($r['estado'] ?? ''),
        'exit_code'         => $r['exit_code'] !== null ? (int) $r['exit_code'] : null,
        'mensaje'           => $r['mensaje'] ?? null,
        'log_path'          => $r['log_path'] ?? null,
        'disparo'           => (string) ($r['disparo'] ?? ''),
        'tarea_nombre'      => $r['tarea_nombre']   ?? null,
        'tarea_script'      => $r['tarea_script']   ?? null,
        'tarea_cron_expr'   => $r['tarea_cron_expr']?? null,
        'timeout_seg'       => $r['timeout_seg'] !== null ? (int) $r['timeout_seg'] : null,
    ];
}

function handleGetEjecuciones(): void
{
    $pdo = db();
    $id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id > 0) {
        $stmt = $pdo->prepare(
            'SELECT e.*, t.nombre AS tarea_nombre, t.script AS tarea_script,
                    t.cron_expr AS tarea_cron_expr, t.timeout_seg
               FROM tareas_ejecuciones e
               JOIN tareas t ON t.id = e.tarea_id
              WHERE e.id = :id'
        );
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch();
        if (!$row) json_error('ejecucion_no_encontrada', 404);
        json_ok(normalizarEjec($row));
    }

    $tareaId = (int) ($_GET['tarea_id'] ?? 0);
    if ($tareaId <= 0) json_error('tarea_id_requerido', 400);
    $estado  = (string) ($_GET['estado'] ?? '');
    $limite  = isset($_GET['limite']) ? (int) $_GET['limite'] : 100;
    if ($limite < 1 || $limite > 500) $limite = 100;

    $where  = ['e.tarea_id = :tid'];
    $params = [':tid' => $tareaId];
    if (in_array($estado, ['corriendo', 'ok', 'error', 'timeout', 'killed'], true)) {
        $where[] = 'e.estado = :es'; $params[':es'] = $estado;
    }
    $sqlWhere = 'WHERE ' . implode(' AND ', $where);
    $stmt = $pdo->prepare(
        "SELECT e.*, t.nombre AS tarea_nombre
           FROM tareas_ejecuciones e
           JOIN tareas t ON t.id = e.tarea_id
           $sqlWhere
           ORDER BY e.id DESC LIMIT $limite"
    );
    $stmt->execute($params);
    json_ok(['ejecuciones' => array_map('normalizarEjec', $stmt->fetchAll())]);
}

function handlePostEjecuciones(): void
{
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $accion = (string) ($body['accion'] ?? '');
    if ($accion !== 'detener') json_error('accion_no_soportada', 400);

    $id  = (int) ($body['id'] ?? 0);
    if ($id <= 0) json_error('missing_id', 400);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, pid, estado FROM tareas_ejecuciones WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();
    if (!$row) json_error('ejecucion_no_encontrada', 404);
    if ($row['estado'] !== 'corriendo') json_error('not_running', 409);

    // 1) Marcar killed ANTES de la señal (para evitar carrera con SIGTERM del hijo).
    $mensaje = 'Detenido manualmente ' . date('c');
    $upd = $pdo->prepare(
        "UPDATE tareas_ejecuciones SET fin=NOW(), estado='killed', exit_code=143, mensaje=:m
          WHERE id=:id AND estado='corriendo'"
    );
    $upd->execute([':m' => $mensaje, ':id' => $id]);
    $pdo->prepare(
        "UPDATE tareas t JOIN tareas_ejecuciones e ON e.tarea_id=t.id
            SET t.ultimo_estado='killed'
          WHERE e.id=:id"
    )->execute([':id' => $id]);

    // 2) Enviar señales al proceso.
    $killed = false;
    $pid    = (int) ($row['pid'] ?? 0);
    if ($pid > 0 && function_exists('posix_kill')) {
        @posix_kill($pid, 15);
        for ($i = 0; $i < 20; $i++) {
            usleep(100_000);
            if (!@posix_kill($pid, 0)) { $killed = true; break; }
        }
        if (!$killed && @posix_kill($pid, 0)) {
            @posix_kill($pid, 9);
            $killed = true;
        }
    }

    if (function_exists('registrarSuceso')) {
        registrarSuceso($pdo, 'cron/scheduler', 'alerta',
            'Ejecucion #' . $id . ' detenida manualmente (pid=' . $pid . ').');
    }

    json_ok(['killed' => $killed]);
}
