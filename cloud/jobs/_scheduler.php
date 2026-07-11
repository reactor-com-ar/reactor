<?php

declare(strict_types=1);

// Scheduler minutal del Programador de tareas (skill §5).
// Invocado por cron cada minuto. Barre huérfanos, evalúa cron_expr,
// dispara los jobs que matchean.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('scheduler: solo por CLI');
}

require_once __DIR__ . '/../../env.php';

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST, (int) DB_PORT, DB_NAME
);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$pdo->exec("SET time_zone = '-03:00'");

$logDir = '/var/log/reactor/cloud';
$ejecDir = $logDir . '/ejecuciones';
@mkdir($ejecDir, 0755, true);

// ---- 1) Watchdog: barrer huérfanos ----
$stmt = $pdo->query(
    "SELECT e.id, e.pid, e.inicio, t.timeout_seg
       FROM tareas_cron_ejecuciones e
       JOIN tareas_cron t ON t.id = e.tarea_id
      WHERE e.estado = 'corriendo'
        AND TIMESTAMPDIFF(SECOND, e.inicio, NOW()) > t.timeout_seg * 2"
);
foreach ($stmt->fetchAll() as $huerfano) {
    $pid = (int) ($huerfano['pid'] ?? 0);
    if ($pid > 0 && function_exists('posix_kill')) {
        if (@posix_kill($pid, 0)) @posix_kill($pid, 9);
    }
    $pdo->prepare(
        "UPDATE tareas_cron_ejecuciones
            SET fin = NOW(), estado = 'killed', exit_code = 137,
                mensaje = CONCAT('watchdog: killed (', TIMESTAMPDIFF(SECOND, inicio, NOW()), 's)')
          WHERE id = :id AND estado = 'corriendo'"
    )->execute([':id' => (int) $huerfano['id']]);
    $pdo->prepare(
        "UPDATE tareas_cron t
            JOIN tareas_cron_ejecuciones e ON e.tarea_id = t.id
            SET t.ultimo_estado = 'killed'
          WHERE e.id = :id"
    )->execute([':id' => (int) $huerfano['id']]);
}

// ---- 2) Leer tareas activas ----
$tareas = $pdo->query(
    'SELECT id, nombre, script, cron_expr, overlap, timeout_seg
       FROM tareas_cron WHERE activo = 1'
)->fetchAll();

$ahora = new DateTime('now');

// ---- 3) Evaluar y disparar ----
foreach ($tareas as $t) {
    if (!cronMatch((string) $t['cron_expr'], $ahora)) continue;

    if ($t['overlap'] === 'skip') {
        $chk = $pdo->prepare(
            "SELECT COUNT(*) FROM tareas_cron_ejecuciones
              WHERE tarea_id = :tid AND estado = 'corriendo'"
        );
        $chk->execute([':tid' => (int) $t['id']]);
        if ((int) $chk->fetchColumn() > 0) {
            fwrite(STDERR, sprintf("[%s] skip: %s ya está corriendo\n", date('c'), $t['nombre']));
            continue;
        }
    }

    dispararTarea($pdo, $t, $ejecDir);
}

function dispararTarea(PDO $pdo, array $tarea, string $ejecDir): void
{
    $tid = (int) $tarea['id'];
    $ins = $pdo->prepare(
        "INSERT INTO tareas_cron_ejecuciones (tarea_id, inicio, estado, disparo)
         VALUES (:tid, NOW(), 'corriendo', 'scheduler')"
    );
    $ins->execute([':tid' => $tid]);
    $eid = (int) $pdo->lastInsertId();

    $logPath = $ejecDir . '/' . $eid . '.log';

    $pdo->prepare('UPDATE tareas_cron_ejecuciones SET log_path = :p WHERE id = :id')
        ->execute([':p' => $logPath, ':id' => $eid]);

    $pdo->prepare(
        "UPDATE tareas_cron
            SET ultimo_run = NOW(), ultimo_estado = 'corriendo', ultimo_error = NULL
          WHERE id = :id"
    )->execute([':id' => $tid]);

    $encabezado = sprintf(
        "── Ejecucion #%d de \"%s\" (%s) ──\n" .
        "── Script: %s ──\n" .
        "── Timeout: %ds  |  Disparo: scheduler  |  Inicio: %s ──\n\n",
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
        $pdo->prepare('UPDATE tareas_cron_ejecuciones SET pid = :p WHERE id = :id')
            ->execute([':p' => $pid, ':id' => $eid]);
    }
}

// ---- 4) Parser cron (skill §5.6) ----
// Sintaxis: minuto hora dia-mes mes dia-semana.
// Cada campo admite: asterisco, N, asterisco-barra-N, N-M, N,M,O
// (NOTA: no escribimos el patrón asterisco-barra-N literal aquí porque
// cerraría el comentario y rompería el parse de PHP).
function cronMatch(string $expr, DateTime $t): bool
{
    $partes = preg_split('/\s+/', trim($expr));
    if (count($partes) !== 5) return false;
    return cronCampoMatch($partes[0], (int) $t->format('i'), 0, 59)
        && cronCampoMatch($partes[1], (int) $t->format('G'), 0, 23)
        && cronCampoMatch($partes[2], (int) $t->format('j'), 1, 31)
        && cronCampoMatch($partes[3], (int) $t->format('n'), 1, 12)
        && cronCampoMatch($partes[4], (int) $t->format('w'), 0, 6);
}

function cronCampoMatch(string $campo, int $val, int $min, int $max): bool
{
    foreach (explode(',', $campo) as $token) {
        $step = 1;
        $range = $token;
        if (strpos($token, '/') !== false) {
            [$range, $stepStr] = explode('/', $token, 2);
            $step = max(1, (int) $stepStr);
        }
        if ($range === '*') { $from = $min; $to = $max; }
        elseif (strpos($range, '-') !== false) {
            [$a, $b] = explode('-', $range, 2);
            $from = (int) $a; $to = (int) $b;
        } else {
            $from = $to = (int) $range;
        }
        for ($i = $from; $i <= $to; $i += $step) {
            if ($i === $val) return true;
        }
    }
    return false;
}
