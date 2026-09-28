<?php

declare(strict_types=1);

/**
 * Comparador DB — diff estructural entre las bases de desarrollo y produccion.
 *
 * Compara, tabla por tabla, columnas (tipo / nullable / default / extra),
 * indices (columnas + unicidad) y foreign keys (columnas + tabla y columnas
 * referenciadas). Se apoya enteramente en `information_schema`: es
 * **estrictamente de lectura** en los dos lados, no hace SELECT de datos, no
 * ejecuta DDL y no escribe nada en ninguna de las dos bases.
 *
 * Solo se activa cuando APP_ENV=development. En cualquier otro entorno
 * responde 403: correrlo desde produccion implicaria tener credenciales de
 * desarrollo en un proceso de prod y no tiene sentido de negocio.
 *
 * Protocolo: NO devuelve JSON. Devuelve `text/plain` con una linea por evento
 * y cierra siempre —incluso en los caminos de error— con la linea marcador
 * `___END___ {json}`. El frontend lee el ReadableStream y pinta el log en vivo.
 *
 * Metodo: POST (sin body). No es GET a proposito: asi ningun prefetch /
 * preview del navegador dispara una comparacion contra produccion.
 *
 * Diagnostica y nada mas: no propone ALTER TABLE ni sincroniza esquemas. Un
 * drift se corrige con una migracion explicita en el Migrador DB.
 */

// `bootstrap.php` se incluye SOLO por `db()` —la conexion al entorno propio,
// que el comparador reusa en vez de abrir la suya— y por eso opta fuera de su
// `requireAuth()`: ese middleware contesta JSON o un Location de redirect y
// las dos cosas rompen el stream. La sesion se chequea abajo con `authUser()`.
define('CLOUD_API_PUBLIC', true);
require __DIR__ . '/bootstrap.php';

// Credenciales del otro entorno. Las funciones son las mismas que usa el
// Sincronizador de tablas: los dos leen los `.env.*` que docker-compose.yml
// bind-montea read-only en /var/www/. La skill pide un mini-parser inline para
// acotar la superficie, pero en este repo esa superficie ya existe y esta en
// este mismo `api/lib/` detras de la misma guardia dev-only — una cuarta copia
// del parser de `.env` seria duplicacion, no aislamiento.
require __DIR__ . '/lib/sincronizador.php';

// `bootstrap.php` prende display_errors en desarrollo, que es justo donde corre
// esta herramienta: un warning inline se coleria como una linea mas del stream
// y el frontend lo pintaria como log. Los errores salen por `[FAIL]`.
ini_set('display_errors', '0');
error_reporting(E_ALL);

set_time_limit(300);
ini_set('memory_limit', '256M');

// Cero buffering: el log tiene que llegar linea por linea mientras corre.
ini_set('zlib.output_compression', '0');
ini_set('implicit_flush', '1');
ob_implicit_flush(true);
while (ob_get_level() > 0) { @ob_end_flush(); }
if (function_exists('apache_setenv')) { @apache_setenv('no-gzip', '1'); }

// `bootstrap.php` ya mando `Content-Type: application/json`; estos header()
// lo reemplazan porque todavia no salio un solo byte de cuerpo.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('X-Accel-Buffering: no');   // nginx buffereia por default

/** Emite una linea del stream. Todo lo que sale del endpoint pasa por aca. */
function emit(string $line): void
{
    echo $line . "\n";
    @flush();
}

/**
 * Emite el marcador de fin. Ningun camino puede terminar sin llamar a esto:
 * sin el marcador el frontend queda mostrando "respuesta incompleta".
 */
function emitFin(bool $ok, ?array $resumen, ?string $error = null): void
{
    $payload = ['ok' => $ok];
    if ($resumen !== null) $payload['resumen'] = $resumen;
    if ($error   !== null) $payload['error']   = $error;
    echo '___END___ ' . json_encode($payload, JSON_UNESCAPED_UNICODE) . "\n";
    @flush();
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') exit;

// Auth. Se resuelve con `authUser()` y no con `requireAuth()` a proposito (ver
// el comentario del require de arriba). El 401 tambien sale como linea [FAIL]
// + marcador de fin, para que el operador lo lea en la consola del modal.
// Va ANTES de tocar el `.env.production`: un no autenticado no provoca
// siquiera el file() sobre las credenciales del otro entorno.
if (!authUser()) {
    http_response_code(401);
    emit('[FAIL] Sesion vencida. Volve a iniciar sesion y reintenta.');
    emitFin(false, null, 'Sesion vencida. Volve a iniciar sesion y reintenta.');
    exit;
}

// Guardia de entorno. No se usa `asertarSoloDev()` del lib compartido: esa
// responde JSON y cortaria el stream a la mitad; aca el 403 tiene que salir
// por el mismo canal que todo lo demas.
$appEnv = strtolower((string) (defined('APP_ENV') ? APP_ENV : (getenv('APP_ENV') ?: 'unknown')));
if ($appEnv !== 'development') {
    http_response_code(403);
    emit('[FAIL] Esta herramienta solo se puede usar desde el entorno de desarrollo.');
    emitFin(false, null, 'Esta herramienta solo se puede usar desde el entorno de desarrollo.');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    emit('[FAIL] Metodo no soportado: la comparacion se dispara con POST.');
    emitFin(false, null, 'Metodo no soportado: la comparacion se dispara con POST.');
    exit;
}

/**
 * Tablas base del esquema, ordenadas por nombre.
 */
function compararDb_tablas(PDO $db, string $esquema): array
{
    $st = $db->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = :db AND TABLE_TYPE = 'BASE TABLE'
          ORDER BY TABLE_NAME"
    );
    $st->execute([':db' => $esquema]);
    return array_map('strval', array_column($st->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME'));
}

/** true si la conexion apunta a un MariaDB (y no a un MySQL). */
function compararDb_esMariaDb(PDO $db): bool
{
    return stripos((string) $db->query('SELECT VERSION()')->fetchColumn(), 'mariadb') !== false;
}

/*
 * ---------------------------------------------------------------------------
 * Normalizacion entre motores.
 *
 * Los dos lados de este proyecto NO corren el mismo motor: desarrollo es MySQL
 * 8.0 y produccion MariaDB 10.11. Los dos describen el MISMO esquema con
 * textos distintos en `information_schema`, y sin traducirlos el comparador
 * marca como diferente casi todo: medido sobre las 1.049 columnas comunes,
 * 409 difieren solo por el ancho de display de los enteros y 864 solo por como
 * cada motor devuelve el default. Quedaban 45 columnas "identicas" de 1.049 y
 * 0 tablas identicas de 120 — un reporte que no se puede leer.
 *
 * Lo que se normaliza son representaciones, no semantica: ninguna de las tres
 * traducciones puede tapar una diferencia real de esquema.
 * ---------------------------------------------------------------------------
 */

/**
 * Normaliza COLUMN_TYPE.
 *
 * MySQL 8.0.19+ dejo de reportar el ancho de display de los enteros
 * (`int(11)` -> `int`); MariaDB 10.11 lo sigue reportando. El ancho no cambia
 * ni el almacenamiento ni el rango — es cosmetico —, asi que se saca de los
 * dos lados. Se saca SOLO de los tipos enteros: en `varchar(255)`,
 * `decimal(10,2)` o `char(3)` el numero si es semantico.
 */
function compararDb_normTipo(string $t): string
{
    $t = strtolower(trim($t));
    return (string) preg_replace('/\b(tinyint|smallint|mediumint|int|integer|bigint)\(\d+\)/', '$1', $t);
}

/**
 * Normaliza COLUMN_DEFAULT.
 *
 * MariaDB 10.2+ devuelve el default como TEXTO DE EXPRESION: el default NULL
 * vuelve como la cadena `NULL` de cuatro caracteres y un literal de texto
 * vuelve entrecomillado con las comillas internas duplicadas. MySQL 8 devuelve
 * el valor crudo. El desentrecomillado se aplica SOLO del lado MariaDB
 * (`$expr`): en MySQL un default que de verdad valga `'foo'` con comillas
 * incluidas es un valor legitimo y no hay que tocarlo.
 *
 * Al final, los dos lados pasan por el mismo doblado de funciones:
 * `CURRENT_TIMESTAMP` (MySQL) y `current_timestamp()` (MariaDB) son la misma
 * cosa escrita distinta.
 */
function compararDb_normDefault(?string $d, bool $expr): ?string
{
    if ($d === null) return null;

    if ($expr) {
        if ($d === 'NULL') return null;                   // default NULL
        $n = strlen($d);
        if ($n >= 2 && $d[0] === "'" && $d[$n - 1] === "'") {
            // Literal de texto: se devuelve tal cual quedo, sin bajar a
            // minusculas — el contenido de un default es case-sensitive.
            return str_replace("''", "'", substr($d, 1, -1));
        }
    }

    $low = strtolower(trim($d));
    if (preg_match('/^(current_timestamp|now|curdate|curtime|utc_timestamp)\s*(\(\s*\))?$/', $low)) {
        return (string) preg_replace('/\s*\(\s*\)$/', '', $low);
    }
    return $d;
}

/**
 * Normaliza EXTRA.
 *
 * MySQL 8 agrega el marcador `DEFAULT_GENERATED` para decir "el default es una
 * expresion"; MariaDB no lo reporta. No es un atributo de la columna sino una
 * nota sobre el default, que ya se compara por su cuenta.
 */
function compararDb_normExtra(string $e): string
{
    $e = strtolower(trim($e));
    $e = str_replace('default_generated', '', $e);
    $e = (string) preg_replace('/\(\s*\)/', '', $e);      // current_timestamp() -> current_timestamp
    return trim((string) preg_replace('/\s+/', ' ', $e));
}

/**
 * Columnas de TODO el esquema en una sola consulta: `tabla => {col => ...}`.
 *
 * Se trae el esquema entero de una y no tabla por tabla porque el otro lado es
 * el RDS de produccion: con 129 tablas, una consulta por tabla son cientos de
 * round-trips sobre un enlace remoto que ya tarda ~1,5 s solo en conectarse.
 *
 * Los cuatro campos salen YA normalizados: lo que se guarda es lo que se
 * compara y lo que despues se muestra en el side-by-side del log, asi que el
 * operador ve exactamente el texto sobre el que se tomo la decision.
 */
function compararDb_columnas(PDO $db, string $esquema, bool $expr): array
{
    $st = $db->prepare(
        'SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = :db
          ORDER BY TABLE_NAME, ORDINAL_POSITION'
    );
    $st->execute([':db' => $esquema]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['TABLE_NAME']][$r['COLUMN_NAME']] = [
            'tipo'     => compararDb_normTipo((string) $r['COLUMN_TYPE']),
            'nullable' => (string) $r['IS_NULLABLE'],
            'default'  => compararDb_normDefault($r['COLUMN_DEFAULT'], $expr),
            'extra'    => compararDb_normExtra((string) $r['EXTRA']),
        ];
    }
    return $out;
}

/** Indices de todo el esquema: `tabla => {indice => {unico, columnas}}`. */
function compararDb_indices(PDO $db, string $esquema): array
{
    $st = $db->prepare(
        'SELECT TABLE_NAME, INDEX_NAME, MIN(NON_UNIQUE) AS NON_UNIQUE,
                GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS COLS
           FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = :db
          GROUP BY TABLE_NAME, INDEX_NAME
          ORDER BY TABLE_NAME, INDEX_NAME'
    );
    $st->execute([':db' => $esquema]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['TABLE_NAME']][$r['INDEX_NAME']] = [
            'unico'    => ((int) $r['NON_UNIQUE']) === 0,
            'columnas' => (string) $r['COLS'],
        ];
    }
    return $out;
}

/** Foreign keys de todo el esquema: `tabla => {fk => {columnas, ref_tabla, ref_cols}}`. */
function compararDb_fks(PDO $db, string $esquema): array
{
    $st = $db->prepare(
        'SELECT TABLE_NAME, CONSTRAINT_NAME,
                GROUP_CONCAT(COLUMN_NAME            ORDER BY ORDINAL_POSITION) AS COLS,
                MIN(REFERENCED_TABLE_NAME)                                     AS REF_TABLA,
                GROUP_CONCAT(REFERENCED_COLUMN_NAME ORDER BY ORDINAL_POSITION) AS REF_COLS
           FROM information_schema.KEY_COLUMN_USAGE
          WHERE TABLE_SCHEMA = :db AND REFERENCED_TABLE_NAME IS NOT NULL
          GROUP BY TABLE_NAME, CONSTRAINT_NAME
          ORDER BY TABLE_NAME, CONSTRAINT_NAME'
    );
    $st->execute([':db' => $esquema]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[$r['TABLE_NAME']][$r['CONSTRAINT_NAME']] = [
            'columnas'  => (string) $r['COLS'],
            'ref_tabla' => (string) $r['REF_TABLA'],
            'ref_cols'  => (string) $r['REF_COLS'],
        ];
    }
    return $out;
}

/** Descripcion legible de una columna, para el side-by-side del log. */
function compararDb_descCol(array $c): string
{
    $def = $c['default'] === null ? 'NULL' : "'" . $c['default'] . "'";
    $s   = $c['tipo'] . ' ' . ($c['nullable'] === 'YES' ? 'NULL' : 'NOT NULL') . ' DEFAULT ' . $def;
    if (($c['extra'] ?? '') !== '') $s .= ' ' . $c['extra'];
    return $s;
}

/** Descripcion legible de un indice. */
function compararDb_descIdx(array $i): string
{
    return ($i['unico'] ? 'UNIQUE' : 'INDEX') . ' (' . $i['columnas'] . ')';
}

/** Descripcion legible de una foreign key. */
function compararDb_descFk(array $f): string
{
    return '(' . $f['columnas'] . ') -> ' . $f['ref_tabla'] . '(' . $f['ref_cols'] . ')';
}

$DEV  = 'desarrollo';
$PROD = 'produccion';

try {
    // Llaves alrededor de la variable: `$PROD…` se interpolaria como una
    // variable llamada `PROD…`, porque PHP acepta bytes >= 0x80 dentro de un
    // identificador y los tres del `…` (E2 80 A6) entran en esa clase.
    emit("=== Comparacion de bases {$DEV} <-> {$PROD} ===");
    emit("Conectando a {$PROD}…");

    // Lado desarrollo: el helper del proyecto, con las constantes que `env.php`
    // ya cargo desde `.env.development`.
    $devDb   = db();
    $devInfo = sincEntornoInfo('dev');

    // Lado produccion: credenciales releidas del `.env.production` en cada
    // corrida — no se cachean ni se persisten en ningun lado.
    $prodInfo = sincEntornoInfo('prod');
    $prodDb   = sincPdo('prod');

    // Lo unico del otro entorno que aparece en el log es base @ host.
    // El password no se emite nunca, ni aca ni en los mensajes de error.
    emit("{$DEV}:  {$devInfo['database']}  @ {$devInfo['host']}");
    emit("{$PROD}: {$prodInfo['database']} @ {$prodInfo['host']}");

    $devExpr  = compararDb_esMariaDb($devDb);
    $prodExpr = compararDb_esMariaDb($prodDb);
    if ($devExpr !== $prodExpr) {
        // Que los motores difieran es lo normal en este proyecto, no una
        // anomalia: se avisa para que el operador sepa que el reporte pasa por
        // la normalizacion y no lea "identica" como "byte por byte igual".
        emit('       Los dos lados corren motores distintos (MySQL <-> MariaDB):');
        emit('       se normalizan ancho de display de enteros, formato del');
        emit('       DEFAULT y el marcador DEFAULT_GENERATED antes de comparar.');
    }
    emit('');

    emit('-- Listando tablas --');
    $devTbl  = compararDb_tablas($devDb,  $devInfo['database']);
    $prodTbl = compararDb_tablas($prodDb, $prodInfo['database']);
    emit("{$DEV}:  " . count($devTbl)  . ' tablas.');
    emit("{$PROD}: " . count($prodTbl) . ' tablas.');
    emit('');

    $soloDev  = array_values(array_diff($devTbl, $prodTbl));
    $soloProd = array_values(array_diff($prodTbl, $devTbl));
    $enAmbas  = array_values(array_intersect($devTbl, $prodTbl));

    if (!$soloDev && !$soloProd) {
        emit('[OK]   Las dos bases tienen exactamente las mismas tablas.');
    } else {
        if ($soloDev) {
            emit("[FAIL] Tablas que estan solo en {$DEV} (" . count($soloDev) . '):');
            foreach ($soloDev as $t) emit('        . ' . $t);
        }
        if ($soloProd) {
            emit("[FAIL] Tablas que estan solo en {$PROD} (" . count($soloProd) . '):');
            foreach ($soloProd as $t) emit('        . ' . $t);
        }
    }
    emit('');

    // Un barrido por area y por lado (6 consultas en total) en vez de cuatro
    // por tabla: contra el RDS remoto la diferencia es de minutos a segundos.
    $devCols  = compararDb_columnas($devDb,  $devInfo['database'],  $devExpr);
    $prodCols = compararDb_columnas($prodDb, $prodInfo['database'], $prodExpr);
    $devIdx   = compararDb_indices($devDb,   $devInfo['database']);
    $prodIdx  = compararDb_indices($prodDb,  $prodInfo['database']);
    $devFks   = compararDb_fks($devDb,       $devInfo['database']);
    $prodFks  = compararDb_fks($prodDb,      $prodInfo['database']);

    $n = count($enAmbas);
    emit("-- Comparando estructura tabla por tabla ($n) --");

    $iguales = 0;
    $difs    = 0;
    $i       = 0;

    foreach ($enAmbas as $tabla) {
        $i++;
        emit("[$i/$n] $tabla …");

        $dc = $devCols[$tabla]  ?? [];
        $pc = $prodCols[$tabla] ?? [];
        $di = $devIdx[$tabla]   ?? [];
        $pi = $prodIdx[$tabla]  ?? [];
        $df = $devFks[$tabla]   ?? [];
        $pf = $prodFks[$tabla]  ?? [];

        $diffs = [];

        foreach (array_diff(array_keys($dc), array_keys($pc)) as $col) {
            $diffs[] = "[WARN] columna '$col' solo en {$DEV}";
            $diffs[] = '    ' . compararDb_descCol($dc[$col]);
        }
        foreach (array_diff(array_keys($pc), array_keys($dc)) as $col) {
            $diffs[] = "[WARN] columna '$col' solo en {$PROD}";
            $diffs[] = '    ' . compararDb_descCol($pc[$col]);
        }
        foreach (array_intersect(array_keys($dc), array_keys($pc)) as $col) {
            // Los cuatro campos se comparan como tupla, no de a uno: lo que
            // interesa es "esta columna difiere", y el detalle va al lado.
            if ($dc[$col] !== $pc[$col]) {
                $diffs[] = "[WARN] columna '$col' difiere";
                $diffs[] = "    {$DEV}:  " . compararDb_descCol($dc[$col]);
                $diffs[] = "    {$PROD}: " . compararDb_descCol($pc[$col]);
            }
        }

        foreach (array_diff(array_keys($di), array_keys($pi)) as $idx) {
            $diffs[] = "[WARN] indice '$idx' solo en {$DEV} " . compararDb_descIdx($di[$idx]);
        }
        foreach (array_diff(array_keys($pi), array_keys($di)) as $idx) {
            $diffs[] = "[WARN] indice '$idx' solo en {$PROD} " . compararDb_descIdx($pi[$idx]);
        }
        foreach (array_intersect(array_keys($di), array_keys($pi)) as $idx) {
            if ($di[$idx] !== $pi[$idx]) {
                $diffs[] = "[WARN] indice '$idx' difiere";
                $diffs[] = "    {$DEV}:  " . compararDb_descIdx($di[$idx]);
                $diffs[] = "    {$PROD}: " . compararDb_descIdx($pi[$idx]);
            }
        }

        foreach (array_diff(array_keys($df), array_keys($pf)) as $fk) {
            $diffs[] = "[WARN] FK '$fk' solo en {$DEV} " . compararDb_descFk($df[$fk]);
        }
        foreach (array_diff(array_keys($pf), array_keys($df)) as $fk) {
            $diffs[] = "[WARN] FK '$fk' solo en {$PROD} " . compararDb_descFk($pf[$fk]);
        }
        foreach (array_intersect(array_keys($df), array_keys($pf)) as $fk) {
            if ($df[$fk] !== $pf[$fk]) {
                $diffs[] = "[WARN] FK '$fk' difiere";
                $diffs[] = "    {$DEV}:  " . compararDb_descFk($df[$fk]);
                $diffs[] = "    {$PROD}: " . compararDb_descFk($pf[$fk]);
            }
        }

        if (!$diffs) {
            $iguales++;
            emit('        [OK] identica.');
            continue;
        }

        $difs++;
        // Las lineas de detalle (las que arrancan con 4 espacios) no llevan
        // prefijo propio: cuelgan del [WARN] de arriba.
        $cuantas = count(array_filter($diffs, static fn($d) => str_starts_with($d, '[')));
        emit("        $cuantas diferencia(s):");
        foreach ($diffs as $d) emit('          ' . $d);
    }

    emit('');
    emit('=== Resumen ===');
    emit('Tablas identicas:          ' . $iguales);
    emit('Tablas con diferencias:    ' . $difs);
    emit("Tablas solo en {$DEV}:  "    . count($soloDev));
    emit("Tablas solo en {$PROD}: "    . count($soloProd));

    $hayDiff = $difs > 0 || $soloDev || $soloProd;
    emitFin(!$hayDiff, [
        'iguales'    => $iguales,
        'diferentes' => $difs,
        'solo_dev'   => count($soloDev),
        'solo_prod'  => count($soloProd),
    ]);
} catch (Throwable $e) {
    // Red final: cualquier excepcion sale como linea legible + marcador de
    // fin, para que el frontend no se quede esperando un stream que ya murio.
    emit('[FAIL] ' . $e->getMessage());
    emitFin(false, null, $e->getMessage());
}
