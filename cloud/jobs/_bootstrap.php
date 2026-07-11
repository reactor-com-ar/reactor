<?php

declare(strict_types=1);

// Bootstrap común de los jobs del Programador de tareas (skill §4).
// Todo job PHP arranca con `require_once __DIR__ . '/_bootstrap.php';`.
// Expone marcarEjecucionOk / marcarEjecucionError / anotarLog / ejecucionId.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('jobs: solo por CLI');
}

// Line-buffering forzado para que el streaming SSE muestre el log en vivo.
@ob_implicit_flush(true);
while (ob_get_level() > 0) @ob_end_flush();

// Reusar el helper de conexión que ya usa el resto del admin cloud.
require_once __DIR__ . '/../../env.php';
$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    DB_HOST, (int) DB_PORT, DB_NAME
);
$_jobsPdo = new PDO($dsn, DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
]);
$_jobsPdo->exec("SET time_zone = '-03:00'");

$_jobsEjecucionId = (int) ($_SERVER['EJECUCION_ID'] ?? getenv('EJECUCION_ID') ?: 0);
$_jobsNombre      = pathinfo(__FILE__, PATHINFO_FILENAME);
if (isset($_SERVER['argv'][0])) {
    $_jobsNombre = pathinfo($_SERVER['argv'][0], PATHINFO_FILENAME);
}

function ejecucionId(): int
{
    global $_jobsEjecucionId;
    return $_jobsEjecucionId;
}

function jobNombre(): string
{
    global $_jobsNombre;
    return $_jobsNombre;
}

function anotarLog(string $linea): void
{
    echo '[' . date('H:i:s') . '] ' . $linea . PHP_EOL;
}

function _jobsPdo(): PDO
{
    global $_jobsPdo;
    return $_jobsPdo;
}

/** Cierra la fila si sigue en 'corriendo'. Idempotente. */
function _cerrarEjecucion(string $estado, int $exit, ?string $mensaje): void
{
    $eid = ejecucionId();
    if ($eid <= 0) return;
    try {
        $pdo = _jobsPdo();
        $stmt = $pdo->prepare(
            "UPDATE tareas_cron_ejecuciones
                SET fin = NOW(), estado = :e, exit_code = :x, mensaje = :m
              WHERE id = :id AND estado = 'corriendo'"
        );
        $stmt->execute([
            ':e'  => $estado,
            ':x'  => $exit,
            ':m'  => $mensaje !== null ? mb_substr($mensaje, 0, 1000) : null,
            ':id' => $eid,
        ]);
        if ($stmt->rowCount() > 0) {
            // Reflejar en snapshot.
            $pdo->prepare(
                'UPDATE tareas_cron t
                    JOIN tareas_cron_ejecuciones e ON e.tarea_id = t.id
                    SET t.ultimo_estado = :e,
                        t.ultimo_error  = :m
                  WHERE e.id = :id'
            )->execute([':e' => $estado, ':m' => $mensaje, ':id' => $eid]);
        }
    } catch (Throwable $ex) {
        error_log('bootstrap _cerrarEjecucion fallo: ' . $ex->getMessage());
    }
}

function marcarEjecucionOk(?string $mensaje = null): void
{
    _cerrarEjecucion('ok', 0, $mensaje);
}

function marcarEjecucionError(Throwable $e): void
{
    $msg = get_class($e) . ': ' . $e->getMessage();
    _cerrarEjecucion('error', 1, $msg);
    // Registrar en sucesos_log si el helper está disponible.
    $sHelper = __DIR__ . '/../api/lib/sucesos.php';
    if (is_file($sHelper)) {
        require_once $sHelper;
        if (function_exists('registrarSuceso')) {
            registrarSuceso(_jobsPdo(), 'cron/' . jobNombre(), 'error', $msg);
        }
    }
}

// Handler de SIGTERM: cuando timeout de coreutils dispara, cerrar la fila
// inmediatamente como 'timeout' (sin esto queda en 'corriendo' hasta el
// próximo watchdog del scheduler).
if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal')) {
    pcntl_async_signals(true);
    pcntl_signal(SIGTERM, function () {
        _cerrarEjecucion('timeout', 124, 'SIGTERM recibido (timeout de coreutils)');
        exit(143);
    });
}

// Shutdown handler para fatales — última línea de defensa.
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        _cerrarEjecucion('error', 1, 'FATAL ' . $err['message'] . ' at ' . $err['file'] . ':' . $err['line']);
    }
});
