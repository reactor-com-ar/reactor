<?php

declare(strict_types=1);

// Programador de tareas — SSE streaming del log de una ejecucion.
// GET api/tareas_ejecucion_stream.php?id=N
//
// Emite `data: <linea>\n\n` por cada línea del archivo .log a medida que
// aparece. Al cerrar la fila en DB, emite `event: end\ndata: <estado>\n\n`.

// Este endpoint NO usa bootstrap.php estándar (que envía Content-Type: application/json).
// Definimos CLOUD_API_PUBLIC=false y hacemos el auth check y bootstrap manualmente.
require_once dirname(__DIR__, 2) . '/env.php';
require_once dirname(__DIR__) . '/lib/auth_check.php';
requireAuth();

// SSE headers.
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store');
header('X-Accel-Buffering: no');
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
set_time_limit(0);
ignore_user_abort(false);

$id = (int) ($_GET['id'] ?? 0);
if ($id <= 0) {
    echo "event: end\ndata: bad_request\n\n";
    exit;
}

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
               DB_HOST, (int) DB_PORT, DB_NAME);
$pdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);

$stmt = $pdo->prepare('SELECT log_path, estado FROM tareas_cron_ejecuciones WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();
if (!$row) {
    echo "event: end\ndata: not_found\n\n";
    exit;
}
$logPath = (string) ($row['log_path'] ?? '');

if ($logPath === '' || !is_file($logPath)) {
    echo "data: (log no disponible: archivo rotado o aun no creado)\n\n";
    echo "event: end\ndata: " . $row['estado'] . "\n\n";
    flush();
    exit;
}

$fp = @fopen($logPath, 'rb');
if (!$fp) {
    echo "event: end\ndata: io_error\n\n";
    exit;
}

$leftover = '';
$lastPoll = time();

while (!connection_aborted()) {
    $chunk = fread($fp, 8192);
    if ($chunk !== '' && $chunk !== false) {
        $leftover .= $chunk;
        $lines = explode("\n", $leftover);
        $leftover = array_pop($lines);
        foreach ($lines as $line) {
            if (strlen($line) > 8192) $line = substr($line, 0, 8192);
            echo 'data: ' . $line . "\n\n";
        }
        flush();
    } else {
        // Cada ~1s: chequear si la fila sigue corriendo.
        if (time() - $lastPoll >= 1) {
            $lastPoll = time();
            try {
                $stmt->execute([':id' => $id]);
                $r = $stmt->fetch();
                $estado = $r['estado'] ?? 'unknown';
                if ($estado !== 'corriendo') {
                    if ($leftover !== '') {
                        echo 'data: ' . substr($leftover, 0, 8192) . "\n\n";
                        $leftover = '';
                    }
                    echo "event: end\ndata: {$estado}\n\n";
                    flush();
                    break;
                }
                echo ": keepalive\n\n";
                flush();
            } catch (Throwable $e) {
                echo "event: end\ndata: db_error\n\n";
                flush();
                break;
            }
        }
        usleep(500_000);
    }
}
fclose($fp);
