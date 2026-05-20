<?php

declare(strict_types=1);

// La herramienta de Migraciones lista los archivos .sql de
// cloud/sql/migrations/ y los ejecuta en orden, registrando cada uno en
// el ledger `migraciones` (db/schema.sql). Operacion doblemente
// idempotente: el runner saltea los archivos con success=1 y, ademas,
// cada archivo .sql esta escrito en forma idempotente (CREATE TABLE IF
// NOT EXISTS / IF (...) THEN ALTER, etc.).
//
// Endpoints:
//   GET  api/migraciones.php           -> JSON: { migraciones: [...], pendientes: N }
//   POST api/migraciones.php?run=1     -> Server-Sent Events: stream del progreso
//
// El POST emite eventos SSE tipo:
//   event: log    { level: 'info'|'ok'|'warn'|'err'|'skip', msg: '...' }
//   event: file   { archivo, status: 'skip'|'ok'|'err', duracion_ms, error }
//   event: done   { total, aplicadas, salteadas, fallidas }

require __DIR__ . '/bootstrap.php';

const MIGRATIONS_DIR = __DIR__ . '/../sql/migrations';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        handleList();
    } elseif ($method === 'POST') {
        handleRun();
    } else {
        json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    // En modo stream no podemos cambiar headers: si ya empezamos a emitir
    // SSE, mandamos el error como evento y cerramos.
    if (headers_sent()) {
        sseEmit('log', ['level' => 'err', 'msg' => 'Error fatal: ' . $e->getMessage()]);
        sseEmit('done', ['total' => 0, 'aplicadas' => 0, 'salteadas' => 0, 'fallidas' => 1]);
        exit;
    }
    json_error('Error en migraciones: ' . $e->getMessage(), 500);
}

/**
 * Devuelve el listado de archivos .sql ordenado por nombre, anotado con
 * el ledger `migraciones` (si existe la tabla). Si la tabla todavia no
 * existe (entorno nuevo), devuelve todos como pendientes.
 */
function handleList(): void
{
    $archivos = listarArchivos();

    $ledger = [];
    if (existeLedger()) {
        $stmt = db()->query(
            'SELECT archivo, hash_sha256, ejecutado_at, duracion_ms, success, error
             FROM migraciones'
        );
        foreach ($stmt->fetchAll() as $row) {
            $ledger[$row['archivo']] = $row;
        }
    }

    $items = [];
    $pendientes = 0;
    foreach ($archivos as $archivo) {
        $row = $ledger[$archivo] ?? null;
        $aplicada = $row !== null && (int) $row['success'] === 1;
        if (!$aplicada) $pendientes++;

        $items[] = [
            'archivo'      => $archivo,
            'aplicada'     => $aplicada,
            'ejecutado_at' => $row['ejecutado_at'] ?? null,
            'duracion_ms'  => $row !== null ? (int) $row['duracion_ms'] : null,
            'success'      => $row !== null ? (int) $row['success'] : null,
            'error'        => $row['error'] ?? null,
        ];
    }

    json_ok([
        'migraciones' => $items,
        'pendientes'  => $pendientes,
        'total'       => count($items),
    ]);
}

/**
 * Ejecuta las migraciones pendientes en orden y stream-ea cada paso al
 * cliente via SSE. Idempotente: archivos ya marcados con success=1 se
 * saltean.
 */
function handleRun(): void
{
    // Headers SSE + desactivar buffering en cualquier capa que se pueda.
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/event-stream; charset=utf-8');
    header('Cache-Control: no-cache, no-store');
    header('X-Accel-Buffering: no');                // nginx
    @ini_set('zlib.output_compression', '0');
    @ini_set('output_buffering', '0');
    @ini_set('implicit_flush', '1');
    if (function_exists('apache_setenv')) {
        @apache_setenv('no-gzip', '1');
    }

    // Aflojamos el limite por si alguna migracion grande tarda.
    @set_time_limit(0);
    ignore_user_abort(false);

    sseEmit('log', ['level' => 'info', 'msg' => '$ reactor-migrate --run']);
    sseEmit('log', ['level' => 'info', 'msg' => 'Conectando a la base de datos...']);

    try {
        $pdo = db();
        $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
        sseEmit('log', ['level' => 'ok', 'msg' => 'Conectado a `' . $dbName . '`']);
    } catch (Throwable $e) {
        sseEmit('log', ['level' => 'err', 'msg' => 'No se pudo conectar: ' . $e->getMessage()]);
        sseEmit('done', ['total' => 0, 'aplicadas' => 0, 'salteadas' => 0, 'fallidas' => 1]);
        return;
    }

    sseEmit('log', ['level' => 'info', 'msg' => 'Asegurando ledger `migraciones`...']);
    asegurarLedger($pdo);
    sseEmit('log', ['level' => 'ok', 'msg' => 'Ledger listo.']);

    $archivos = listarArchivos();
    sseEmit('log', [
        'level' => 'info',
        'msg'   => 'Descubiertos ' . count($archivos) . ' archivo(s) en cloud/sql/migrations/',
    ]);

    $aplicadas  = 0;
    $salteadas  = 0;
    $fallidas   = 0;

    foreach ($archivos as $archivo) {
        $ruta = MIGRATIONS_DIR . '/' . $archivo;
        $sql  = @file_get_contents($ruta);
        if ($sql === false) {
            sseEmit('log', ['level' => 'err', 'msg' => 'No se pudo leer ' . $archivo]);
            sseEmit('file', ['archivo' => $archivo, 'status' => 'err', 'error' => 'lectura']);
            $fallidas++;
            continue;
        }
        $hash = hash('sha256', $sql);

        // Idempotencia: si ya esta aplicada con exito, saltear.
        $stmt = $pdo->prepare(
            'SELECT hash_sha256, success FROM migraciones WHERE archivo = :a'
        );
        $stmt->execute([':a' => $archivo]);
        $prev = $stmt->fetch();
        if ($prev && (int) $prev['success'] === 1) {
            $msg = 'SKIP  ' . $archivo;
            if ($prev['hash_sha256'] !== $hash) {
                $msg .= '  (warning: el contenido cambio respecto a la aplicacion original)';
                sseEmit('log', ['level' => 'warn', 'msg' => $msg]);
            } else {
                sseEmit('log', ['level' => 'skip', 'msg' => $msg]);
            }
            sseEmit('file', ['archivo' => $archivo, 'status' => 'skip']);
            $salteadas++;
            continue;
        }

        sseEmit('log', ['level' => 'info', 'msg' => '----> Aplicando ' . $archivo]);

        $t0 = microtime(true);
        try {
            // PDO::exec soporta multi-statement contra MySQL y es lo
            // necesario para los .sql con SET @x / PREPARE / EXECUTE.
            $pdo->exec($sql);
            $dur = (int) round((microtime(true) - $t0) * 1000);

            $upsert = $pdo->prepare(
                'INSERT INTO migraciones (archivo, hash_sha256, duracion_ms, success, error)
                 VALUES (:a, :h, :d, 1, NULL)
                 ON DUPLICATE KEY UPDATE
                    hash_sha256  = VALUES(hash_sha256),
                    ejecutado_at = CURRENT_TIMESTAMP,
                    duracion_ms  = VALUES(duracion_ms),
                    success      = 1,
                    error        = NULL'
            );
            $upsert->execute([':a' => $archivo, ':h' => $hash, ':d' => $dur]);

            sseEmit('log', ['level' => 'ok', 'msg' => '   OK (' . $dur . ' ms)']);
            sseEmit('file', ['archivo' => $archivo, 'status' => 'ok', 'duracion_ms' => $dur]);
            $aplicadas++;
        } catch (Throwable $e) {
            $dur = (int) round((microtime(true) - $t0) * 1000);
            $err = $e->getMessage();

            // Registrar el fallo en el ledger para que se reintente la
            // proxima vez (success=0 no cuenta como aplicada).
            try {
                $upsert = $pdo->prepare(
                    'INSERT INTO migraciones (archivo, hash_sha256, duracion_ms, success, error)
                     VALUES (:a, :h, :d, 0, :err)
                     ON DUPLICATE KEY UPDATE
                        hash_sha256  = VALUES(hash_sha256),
                        ejecutado_at = CURRENT_TIMESTAMP,
                        duracion_ms  = VALUES(duracion_ms),
                        success      = 0,
                        error        = VALUES(error)'
                );
                $upsert->execute([':a' => $archivo, ':h' => $hash, ':d' => $dur, ':err' => $err]);
            } catch (Throwable $_) { /* sigamos */ }

            sseEmit('log', ['level' => 'err', 'msg' => '   ERROR (' . $dur . ' ms): ' . $err]);
            sseEmit('file', [
                'archivo' => $archivo,
                'status'  => 'err',
                'duracion_ms' => $dur,
                'error'   => $err,
            ]);
            $fallidas++;
            // Politica: detener al primer fallo. Las migraciones son ordenadas
            // y suelen depender de la anterior; reintentar a ciegas las
            // siguientes solo agregaria ruido.
            sseEmit('log', ['level' => 'warn', 'msg' => 'Detenido por error. Las pendientes siguientes no se intentan.']);
            break;
        }
    }

    sseEmit('log', ['level' => 'info', 'msg' => '']);
    sseEmit('log', ['level' => 'info', 'msg' => '== Resumen ==']);
    sseEmit('log', ['level' => 'ok',   'msg' => 'Aplicadas: ' . $aplicadas]);
    sseEmit('log', ['level' => 'skip', 'msg' => 'Salteadas: ' . $salteadas]);
    sseEmit('log', ['level' => ($fallidas > 0 ? 'err' : 'info'), 'msg' => 'Fallidas:  ' . $fallidas]);
    sseEmit('done', [
        'total'     => count($archivos),
        'aplicadas' => $aplicadas,
        'salteadas' => $salteadas,
        'fallidas'  => $fallidas,
    ]);
}

function listarArchivos(): array
{
    if (!is_dir(MIGRATIONS_DIR)) return [];
    $out = [];
    foreach (scandir(MIGRATIONS_DIR) ?: [] as $f) {
        if ($f === '.' || $f === '..') continue;
        if (substr($f, -4) !== '.sql') continue;
        $out[] = $f;
    }
    sort($out, SORT_STRING);
    return $out;
}

function existeLedger(): bool
{
    $stmt = db()->prepare(
        "SELECT COUNT(*) FROM information_schema.tables
          WHERE table_schema = DATABASE() AND table_name = 'migraciones'"
    );
    $stmt->execute();
    return (int) $stmt->fetchColumn() === 1;
}

/**
 * Crea la tabla `migraciones` si todavia no existe. Se llama al
 * principio del run para soportar el bootstrap de entornos nuevos donde
 * el ledger aun no fue creado por ninguna migracion previa.
 */
function asegurarLedger(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS migraciones (
            id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            archivo      VARCHAR(255) NOT NULL,
            hash_sha256  CHAR(64)     NOT NULL,
            ejecutado_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
            duracion_ms  INT UNSIGNED NOT NULL DEFAULT 0,
            success      TINYINT(1)   NOT NULL DEFAULT 1,
            error        TEXT         DEFAULT NULL,
            UNIQUE KEY uq_archivo (archivo),
            INDEX idx_ejecutado_at (ejecutado_at)
         ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'
    );
}

/**
 * Emite un evento SSE y fuerza el flush. Cada mensaje queda en el
 * formato:  event: <nombre>\n data: <json>\n\n
 */
function sseEmit(string $event, array $payload): void
{
    echo 'event: ' . $event . "\n";
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
    @flush();
}
