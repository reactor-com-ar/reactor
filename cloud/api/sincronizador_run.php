<?php

declare(strict_types=1);

// Sincronizador de tablas — corrida (SSE).
// GET api/sincronizador_run.php?origen=dev|prod&destino=dev|prod&tabla=<nombre>
//
// Emite `data: {"type":"info|warn|error|success|done","msg":"..."}\n\n` por
// cada linea de la terminal del modal. El evento `done` cierra la corrida.
//
// Este endpoint NO usa bootstrap.php: ese envia `Content-Type: application/json`
// y aca el cuerpo es un stream `text/event-stream`. El auth se hace a mano,
// igual que en api/tareas_ejecucion_stream.php.

// Un warning inline romperia el parser SSE del cliente: el `data:` quedaria
// con HTML adelante y JSON.parse fallaria en cada linea.
ini_set('display_errors', '0');
error_reporting(E_ALL);

require_once dirname(__DIR__, 2) . '/env.php';
require_once dirname(__DIR__) . '/lib/auth_check.php';
require_once __DIR__ . '/lib/sincronizador.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') exit;

// requireAuth() ANTES que asertarSoloDev(): un no autenticado ni siquiera
// aprende en que entorno esta parado el panel.
requireAuth();
asertarSoloDev();

// ---------------------------------------------------------------- SSE setup
// A partir de aca ya no se puede responder con otro Content-Type: todo error
// sale como evento `error` + `done{ok:false}` dentro del stream.
@ini_set('zlib.output_compression', '0');
@ini_set('output_buffering', '0');
@ini_set('implicit_flush', '1');
while (ob_get_level() > 0) { @ob_end_clean(); }
ob_implicit_flush(true);
set_time_limit(0);
ignore_user_abort(false);

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');   // nginx (prod) no buferea el stream
header('Connection: keep-alive');

function sse(string $type, string $msg, array $extra = []): void
{
    $payload = array_merge(['type' => $type, 'msg' => $msg], $extra);
    echo 'data: ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n\n";
    @flush();
}

function sseFin(string $type, string $msg, bool $ok): never
{
    sse($type, $msg);
    sse('done', $ok ? 'Fin.' : 'Abortado.', ['ok' => $ok]);
    exit;
}

const SINC_LOTE = 200;   // filas por INSERT masivo
const SINC_RAYA = '----------------------------------------';
const SINC_DOBLE = '========================================';

// ------------------------------------------------------- validacion de input
// Los parametros se validan con el stream YA abierto: el cliente tiene una
// sola forma de enterarse de cualquier problema (linea roja + `done`).
$origen  = (string) ($_GET['origen']  ?? '');
$destino = (string) ($_GET['destino'] ?? '');
$tabla   = (string) ($_GET['tabla']   ?? '');

if (!sincAmbienteValido($origen))  sseFin('error', 'Origen invalido: se espera "dev" o "prod".', false);
if (!sincAmbienteValido($destino)) sseFin('error', 'Destino invalido: se espera "dev" o "prod".', false);
if ($origen === $destino)          sseFin('error', 'Origen y destino son el mismo entorno.', false);
if (!sincNombreTablaValido($tabla)) {
    sseFin('error', 'Nombre de tabla invalido: solo letras, numeros y guion bajo (hasta 64).', false);
}

$infoOrigen  = sincEntornoInfo($origen);
$infoDestino = sincEntornoInfo($destino);

sse('info', SINC_DOBLE);
sse('info', 'Sincronizador de tablas');
sse('info', SINC_DOBLE);
sse('info', 'Tabla:    ' . $tabla);
sse('info', 'Origen:   ' . $infoOrigen['nombre']  . '  (' . $infoOrigen['host']  . ' / ' . $infoOrigen['database']  . ')');
sse('info', 'Destino:  ' . $infoDestino['nombre'] . '  (' . $infoDestino['host'] . ' / ' . $infoDestino['database'] . ')');
sse('info', SINC_RAYA);

$pdoOrigen  = null;
$pdoDestino = null;

try {
    // ------------------------------------------------------------ conexiones
    sse('info', 'Conectando al origen (' . $infoOrigen['nombre'] . ')...');
    $pdoOrigen = sincPdo($origen);
    sse('success', 'Conexion con origen OK.');

    sse('info', 'Conectando al destino (' . $infoDestino['nombre'] . ')...');
    $pdoDestino = sincPdo($destino);
    sse('success', 'Conexion con destino OK.');

    sse('info', SINC_RAYA);

    // --------------------------------------------- existencia + conteo origen
    $q = $pdoOrigen->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND TABLE_TYPE = 'BASE TABLE'"
    );
    $q->execute([':db' => $infoOrigen['database'], ':t' => $tabla]);
    if ((int) $q->fetchColumn() === 0) {
        sseFin('error', "La tabla `$tabla` no existe en el origen ({$infoOrigen['database']}).", false);
    }

    // El UNICO COUNT(*) de la herramienta: una sola tabla, ya elegida, con la
    // corrida en marcha. Sirve para el `Progreso: X / TOTAL` de cada lote.
    $total = (int) $pdoOrigen->query("SELECT COUNT(*) FROM `$tabla`")->fetchColumn();
    sse('info', "Filas en origen: $total");

    // Columnas y columna auto_increment (para reajustar la secuencia al final).
    $cols = [];
    $colAuto = null;
    $q = $pdoOrigen->prepare(
        'SELECT COLUMN_NAME, EXTRA FROM INFORMATION_SCHEMA.COLUMNS
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION'
    );
    $q->execute([':db' => $infoOrigen['database'], ':t' => $tabla]);
    foreach ($q->fetchAll() as $c) {
        $cols[] = (string) $c['COLUMN_NAME'];
        if (stripos((string) $c['EXTRA'], 'auto_increment') !== false) {
            $colAuto = (string) $c['COLUMN_NAME'];
        }
    }
    if (!$cols) {
        sseFin('error', "No se pudieron leer las columnas de `$tabla`.", false);
    }
    sse('info', 'Columnas: ' . count($cols) . ' (' . implode(', ', $cols) . ')');

    sse('info', SINC_RAYA);

    // ------------------------------------------------- asegurar tabla destino
    $q = $pdoDestino->prepare(
        "SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :t AND TABLE_TYPE = 'BASE TABLE'"
    );
    $q->execute([':db' => $infoDestino['database'], ':t' => $tabla]);
    $existeEnDestino = (int) $q->fetchColumn() > 0;

    // Los chequeos se apagan antes del DDL y de la copia: hay tablas que
    // referencian a otras que todavia no se sincronizaron, y las UNIQUE
    // encarecen el insert masivo. Se reactivan en el `finally`.
    $pdoDestino->exec('SET FOREIGN_KEY_CHECKS = 0');
    $pdoDestino->exec('SET UNIQUE_CHECKS = 0');

    if (!$existeEnDestino) {
        sse('info', "La tabla no existe en destino: creandola con el DDL del origen...");
        $ddlRow = $pdoOrigen->query("SHOW CREATE TABLE `$tabla`")->fetch(PDO::FETCH_NUM);
        $ddl    = (string) ($ddlRow[1] ?? '');
        if ($ddl === '') {
            sseFin('error', "No se pudo obtener el DDL de `$tabla` en el origen.", false);
        }
        // Dev corre MySQL 8.0 y prod MariaDB 10.11: el DDL se ejecuta literal
        // y si el otro motor no lo acepta, el error del driver se ve tal cual
        // en la terminal. No se reescribe nada -- adivinar equivalencias entre
        // motores es como se corrompe un esquema en silencio.
        $pdoDestino->exec($ddl);
        sse('success', 'Tabla creada en destino.');
    } else {
        // TRUNCATE y no DELETE: reinicia el AUTO_INCREMENT, es mas rapido y
        // descarta cualquier residuo -- que es la unica forma de garantizar
        // que los IDs del origen no colisionen. No dispara triggers
        // AFTER DELETE; asumido para una herramienta de operador.
        $pdoDestino->exec("TRUNCATE TABLE `$tabla`");
        sse('success', 'Tabla destino vaciada (TRUNCATE).');
    }

    sse('info', SINC_RAYA);

    // --------------------------------------------------------- copia por lotes
    $copiadas = 0;
    $errores  = 0;

    if ($total === 0) {
        sse('info', 'La tabla de origen esta vacia: no hay filas que copiar.');
    } else {
        $colsSql = '`' . implode('`, `', $cols) . '`';
        $fila1   = '(' . implode(', ', array_fill(0, count($cols), '?')) . ')';

        // Cache de prepares por cantidad de filas del lote: hay a lo sumo dos
        // tamanos distintos (los lotes llenos y la cola).
        $prepLote = [];
        $prepUna  = $pdoDestino->prepare("INSERT INTO `$tabla` ($colsSql) VALUES $fila1");

        // Cursor NO bufferado: `senales` tiene ~725K filas y bufferarlas
        // enteras en RAM antes de empezar a insertar voltea el proceso.
        $pdoOrigen->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        $cursor = $pdoOrigen->query("SELECT $colsSql FROM `$tabla`");

        $lote = [];
        $volcar = function () use (&$lote, &$copiadas, &$errores, &$prepLote, $prepUna, $pdoDestino, $tabla, $colsSql, $fila1, $cols) {
            if (!$lote) return;
            $n = count($lote);
            if (!isset($prepLote[$n])) {
                $prepLote[$n] = $pdoDestino->prepare(
                    "INSERT INTO `$tabla` ($colsSql) VALUES " . implode(', ', array_fill(0, $n, $fila1))
                );
            }
            $planos = [];
            foreach ($lote as $f) {
                foreach ($cols as $c) $planos[] = $f[$c];
            }
            try {
                $prepLote[$n]->execute($planos);
                $copiadas += $n;
            } catch (Throwable) {
                // Un lote roto no aborta la corrida: se reintenta fila por fila
                // para aislar cual(es) fallan y seguir con las demas.
                foreach ($lote as $f) {
                    try {
                        $prepUna->execute(array_map(static fn($c) => $f[$c], $cols));
                        $copiadas++;
                    } catch (Throwable $e2) {
                        $errores++;
                        $v   = $f[$cols[0]] ?? null;
                        $ref = $v === null ? 'NULL' : (string) $v;
                        sse('error', "Fila {$cols[0]}=$ref: " . $e2->getMessage());
                    }
                }
            }
            $lote = [];
        };

        while (($fila = $cursor->fetch()) !== false) {
            $lote[] = $fila;
            if (count($lote) >= SINC_LOTE) {
                $volcar();
                sse('info', "Progreso: $copiadas / $total");
                if (connection_aborted()) {
                    // El operador cerro la pestana: la tabla queda parcial y
                    // el proximo TRUNCATE la limpia al reintentar.
                    $cursor->closeCursor();
                    exit;
                }
            }
        }
        $volcar();
        $cursor->closeCursor();
        sse('info', "Progreso: $copiadas / $total");
    }

    sse('info', SINC_RAYA);

    // ------------------------------------------ reajuste de AUTO_INCREMENT
    if ($colAuto !== null) {
        try {
            $max  = (int) $pdoDestino->query("SELECT COALESCE(MAX(`$colAuto`), 0) FROM `$tabla`")->fetchColumn();
            $next = $max + 1;
            $pdoDestino->exec("ALTER TABLE `$tabla` AUTO_INCREMENT = $next");
            sse('info', "AUTO_INCREMENT de `$colAuto` reajustado a $next.");
        } catch (Throwable $e) {
            sse('warn', 'No se pudo reajustar el AUTO_INCREMENT: ' . $e->getMessage());
        }
    }

    // ------------------------------------------------------------- resumen
    if ($errores > 0) {
        sse('warn', "$copiadas / $total filas copiadas, $errores con error.");
        sse('done', 'Fin con errores.', ['ok' => false]);
    } else {
        sse('success', "$copiadas / $total filas copiadas.");
        sse('done', 'Fin.', ['ok' => true]);
    }
} catch (Throwable $e) {
    // Catch de tope: ningun stacktrace HTML se cuela en el stream.
    sse('error', $e->getMessage());
    sse('done', 'Abortado.', ['ok' => false]);
} finally {
    if ($pdoDestino instanceof PDO) {
        try {
            $pdoDestino->exec('SET UNIQUE_CHECKS = 1');
            $pdoDestino->exec('SET FOREIGN_KEY_CHECKS = 1');
        } catch (Throwable) { /* la conexion se cierra igual al terminar */ }
    }
    $pdoOrigen  = null;
    $pdoDestino = null;
}
