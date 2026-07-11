<?php

declare(strict_types=1);

// Cleanup nocturno de logs de tareas (skill §5b).
// Respeta la retencion_dias de cada tarea; borra archivo + fila en cascada.

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cleanup: solo por CLI');
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

$t0 = microtime(true);
$borradosFilas   = 0;
$borradosArch    = 0;
$sinArch         = 0;
$errores         = 0;

try {
    $stmt = $pdo->query(
        "SELECT e.id, e.log_path
           FROM tareas_cron_ejecuciones e
           JOIN tareas_cron t ON t.id = e.tarea_id
          WHERE e.estado != 'corriendo'
            AND TIMESTAMPDIFF(DAY, e.inicio, NOW()) > t.retencion_dias
          ORDER BY e.id"
    );
    $del = $pdo->prepare('DELETE FROM tareas_cron_ejecuciones WHERE id = :id');
    foreach ($stmt->fetchAll() as $row) {
        $lp = (string) ($row['log_path'] ?? '');
        if ($lp !== '' && is_file($lp)) {
            if (@unlink($lp)) $borradosArch++;
            else              $errores++;
        } else {
            $sinArch++;
        }
        try {
            $del->execute([':id' => (int) $row['id']]);
            $borradosFilas++;
        } catch (Throwable $_) { $errores++; }
    }
} catch (Throwable $e) {
    fwrite(STDERR, 'cleanup fallo: ' . $e->getMessage() . "\n");
    $errores++;
}

$dur = number_format(microtime(true) - $t0, 2);
$linea = sprintf(
    '[%s] cleanup: %d filas | %d archivos | %d sin archivo | %d errores | %ss',
    date('c'), $borradosFilas, $borradosArch, $sinArch, $errores, $dur
);
echo $linea . "\n";

if ($borradosFilas > 0 || $errores > 0) {
    $sHelper = __DIR__ . '/../api/lib/sucesos.php';
    if (is_file($sHelper)) {
        require_once $sHelper;
        if (function_exists('registrarSuceso')) {
            registrarSuceso($pdo, 'cron/cleanup_logs',
                $errores > 0 ? 'alerta' : 'info', $linea);
        }
    }
}
