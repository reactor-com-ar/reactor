<?php

declare(strict_types=1);

// Helpers compartidos por los endpoints del Sincronizador de tablas
// (api/sincronizador_tables.php + api/sincronizador_run.php).
//
// La herramienta copia una tabla entera de un entorno al otro preservando los
// IDs de origen, asi que necesita DOS conexiones vivas en el mismo proceso:
// la del entorno propio y la del otro. Las credenciales salen de los dos
// archivos `.env.*` que docker-compose.yml bind-montea read-only en
// /var/www/ (`.env.development` y `.env.production`, lineas 28-29).
//
// IMPORTANTE: `env.php` ya cargo el `.env` del entorno ACTIVO en getenv() /
// $_ENV / constantes. Leer el del otro entorno con ese loader pisaria la
// conexion del panel, asi que aca se parsea a un array plano y se descarta:
// `sincParseEnv()` no tiene efectos secundarios.

/**
 * Corta con 403 JSON si el panel no esta corriendo en desarrollo.
 *
 * Se llama en el primer statement util de cada endpoint, DESPUES de
 * requireAuth() (asi un no autenticado ni siquiera aprende en que entorno
 * esta parado el panel) y ANTES de emitir cualquier byte distinto del JSON
 * de error -- en el endpoint SSE, antes de mandar los headers del stream.
 *
 * Motivo: este proceso tiene a mano las credenciales de los DOS entornos.
 * En dev el operador ya las tiene localmente; en prod el panel esta expuesto
 * a internet y no debe existir un camino desde ahi hacia dev ni al reves.
 */
function asertarSoloDev(): void
{
    $env = strtolower((string) (defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'unknown')));
    if ($env !== 'development') {
        http_response_code(403);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(
            ['ok' => false, 'error' => 'El sincronizador solo funciona en el panel de desarrollo.'],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }
}

/** `dev` | `prod` y nada mas. */
function sincAmbienteValido(string $ambiente): bool
{
    return $ambiente === 'dev' || $ambiente === 'prod';
}

/** Etiqueta larga del ambiente, la misma que usa APP_ENV. */
function sincAmbienteNombre(string $ambiente): string
{
    return $ambiente === 'prod' ? 'production' : 'development';
}

/**
 * Ruta absoluta del archivo de credenciales del ambiente pedido.
 *
 * __DIR__ = cloud/api/lib -> dirname(..., 3) = raiz del repo, que dentro del
 * contenedor es /var/www (un nivel arriba de los docroots). Es la misma
 * resolucion que hace `api/bootstrap.php` para encontrar `env.php`.
 */
function sincEnvPath(string $ambiente): string
{
    return dirname(__DIR__, 3) . '/.env.' . sincAmbienteNombre($ambiente);
}

/**
 * Parsea un archivo `.env` a un array `clave => valor`.
 *
 * Copia deliberada del parser de `env.php`, SIN sus efectos secundarios: no
 * toca putenv() / $_ENV / $_SERVER ni define constantes. Eso es justamente lo
 * que permite leer el `.env` del otro entorno sin pisar la conexion del panel.
 */
function sincParseEnv(string $path): array
{
    if (!is_readable($path)) {
        return [];
    }
    $out = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        [$k, $v] = array_pad(explode('=', $line, 2), 2, '');
        $k = trim($k);
        $v = trim($v);
        if ($k === '') {
            continue;
        }
        if (strlen($v) >= 2 && (($v[0] === '"' && $v[-1] === '"') || ($v[0] === "'" && $v[-1] === "'"))) {
            $v = substr($v, 1, -1);
        }
        $out[$k] = $v;
    }
    return $out;
}

/**
 * Metadata publicable del ambiente: lo que se muestra en el subtitulo del
 * modal. NUNCA devuelve DB_USER ni DB_PASS.
 */
function sincEntornoInfo(string $ambiente): array
{
    $vars = sincParseEnv(sincEnvPath($ambiente));
    return [
        'ambiente' => $ambiente,
        'nombre'   => sincAmbienteNombre($ambiente),
        'host'     => (string) ($vars['DB_HOST'] ?? ''),
        'database' => (string) ($vars['DB_NAME'] ?? ''),
    ];
}

/**
 * Abre una conexion fresca contra el ambiente pedido.
 *
 * No cachea: cada corrida abre y cierra las suyas, y la corrida siguiente
 * puede querer el sentido contrario. Timeout corto para fallar rapido si el
 * otro entorno no esta alcanzable (prod es una RDS remota: desde dev se llega
 * por internet y puede no haber ruta).
 *
 * `SET time_zone` replica lo que hace `db()` en bootstrap.php, y aca ademas es
 * necesario para la copia: una columna TIMESTAMP se lee convertida a la zona
 * de la sesion y se escribe convertida de vuelta. Con las dos sesiones en la
 * misma zona el round-trip es exacto; con zonas distintas la tabla destino
 * quedaria corrida unas horas sin que nada avise.
 */
function sincPdo(string $ambiente): PDO
{
    $vars = sincParseEnv(sincEnvPath($ambiente));
    if (($vars['DB_HOST'] ?? '') === '' || ($vars['DB_NAME'] ?? '') === '') {
        throw new RuntimeException(
            "No se encontraron credenciales de BD para el entorno '" . sincAmbienteNombre($ambiente)
            . "' (" . sincEnvPath($ambiente) . ")."
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        $vars['DB_HOST'],
        (int) ($vars['DB_PORT'] ?? 3306),
        $vars['DB_NAME']
    );

    $pdo = new PDO($dsn, (string) ($vars['DB_USER'] ?? ''), (string) ($vars['DB_PASS'] ?? ''), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_TIMEOUT            => 10,
    ]);
    $pdo->exec("SET time_zone = '-03:00'");

    return $pdo;
}

/**
 * El nombre de tabla se interpola literal en DDL/DML (`SHOW CREATE TABLE`,
 * `TRUNCATE`, `INSERT INTO`, `ALTER TABLE`), donde no hay placeholders
 * posibles. Esta regex es el unico control: bloquea backticks, espacios,
 * puntos y cualquier cosa que rompa la interpolacion.
 */
function sincNombreTablaValido(string $tabla): bool
{
    return (bool) preg_match('/^[A-Za-z0-9_]{1,64}$/', $tabla);
}
