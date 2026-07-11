<?php

declare(strict_types=1);

// Programador de tareas — disparo manual.
// POST api/tareas_ejecutar.php  {tarea_id}
//   -> { ejecucion_id, pid }

require __DIR__ . '/bootstrap.php';

$sHelper = __DIR__ . '/lib/sucesos.php';
if (is_file($sHelper)) require_once $sHelper;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'POST') json_error('metodo_no_soportado', 405);

try {
    $raw  = (string) file_get_contents('php://input');
    $body = $raw !== '' ? json_decode($raw, true) : [];
    if (!is_array($body)) json_error('body_invalido', 400);

    $tareaId = (int) ($body['tarea_id'] ?? 0);
    if ($tareaId <= 0) json_error('tarea_id_requerido', 400);

    $pdo = db();
    $stmt = $pdo->prepare('SELECT * FROM tareas WHERE id = :id');
    $stmt->execute([':id' => $tareaId]);
    $tarea = $stmt->fetch();
    if (!$tarea) json_error('tarea_no_encontrada', 404);

    if ($tarea['overlap'] === 'skip') {
        $chk = $pdo->prepare("SELECT COUNT(*) FROM tareas_ejecuciones WHERE tarea_id=:tid AND estado='corriendo'");
        $chk->execute([':tid' => $tareaId]);
        if ((int) $chk->fetchColumn() > 0) json_error('ya_esta_corriendo', 409);
    }

    $ejecDir = '/var/log/reactor/cloud/ejecuciones';
    @mkdir($ejecDir, 0755, true);

    $ins = $pdo->prepare(
        "INSERT INTO tareas_ejecuciones (tarea_id, inicio, estado, disparo)
         VALUES (:tid, NOW(), 'corriendo', 'manual')"
    );
    $ins->execute([':tid' => $tareaId]);
    $eid = (int) $pdo->lastInsertId();

    $logPath = $ejecDir . '/' . $eid . '.log';
    $pdo->prepare('UPDATE tareas_ejecuciones SET log_path=:p WHERE id=:id')
        ->execute([':p' => $logPath, ':id' => $eid]);

    $pdo->prepare("UPDATE tareas SET ultimo_run=NOW(), ultimo_estado='corriendo', ultimo_error=NULL WHERE id=:id")
        ->execute([':id' => $tareaId]);

    $encabezado = sprintf(
        "── Ejecucion #%d de \"%s\" (%s) ──\n" .
        "── Script: %s ──\n" .
        "── Timeout: %ds  |  Disparo: manual  |  Inicio: %s ──\n\n",
        $eid, $tarea['nombre'], $tarea['cron_expr'],
        $tarea['script'], (int) $tarea['timeout_seg'], date('c')
    );
    @file_put_contents($logPath, $encabezado);

    $repoRoot  = realpath(__DIR__ . '/../..');
    $scriptAbs = $repoRoot . '/' . $tarea['script'];

    $cmd = sprintf(
        'EJECUCION_ID=%d timeout --signal=TERM --kill-after=10s %ds ' .
        'stdbuf -oL -eL php %s >> %s 2>&1 & echo $!',
        $eid, (int) $tarea['timeout_seg'],
        escapeshellarg($scriptAbs),
        escapeshellarg($logPath)
    );
    $pid = (int) trim((string) shell_exec($cmd));
    if ($pid > 0) {
        $pdo->prepare('UPDATE tareas_ejecuciones SET pid=:p WHERE id=:id')
            ->execute([':p' => $pid, ':id' => $eid]);
    }

    if (function_exists('registrarSuceso')) {
        registrarSuceso($pdo, 'cron/scheduler', 'info',
            'Ejecucion #' . $eid . ' de tarea "' . $tarea['nombre'] . '" disparada manualmente.');
    }

    json_ok(['ejecucion_id' => $eid, 'pid' => $pid]);
} catch (Throwable $e) {
    if (function_exists('registrarSuceso')) {
        try { registrarSuceso(db(), 'cron/tareas_ejecutar', 'error', $e->getMessage()); }
        catch (Throwable $_) {}
    }
    json_error('Error al disparar: ' . $e->getMessage(), 500);
}
