<?php

declare(strict_types=1);

// Helpers compartidos por los endpoints del Explorador DB.
// Todos los identificadores (tabla, columna) se validan contra
// INFORMATION_SCHEMA de la base activa ANTES de meterlos en SQL.

/** Nombre de la BD activa (`SELECT DATABASE()`). */
function dbExpDatabase(PDO $pdo): string
{
    return (string) $pdo->query('SELECT DATABASE()')->fetchColumn();
}

/** Nombres de todas las BASE TABLE de la BD activa. */
function dbExpTablasValidas(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
    );
    $stmt->execute();
    return array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'TABLE_NAME'));
}

/** Columnas (nombres) de una tabla de la BD activa. */
function dbExpColumnasValidas(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([':t' => $tabla]);
    return array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
}

/** Escape de identificador para backticks MySQL (dobla el backtick interior). */
function dbExpQuoteIdent(string $ident): string
{
    return '`' . str_replace('`', '``', $ident) . '`';
}

/** Valida que $tabla exista; si no, corta con 404. */
function dbExpRequerirTabla(PDO $pdo, string $tabla): void
{
    if ($tabla === '' || !in_array($tabla, dbExpTablasValidas($pdo), true)) {
        json_error('La tabla no existe en esta base', 404);
    }
}

/** Valida que $columna exista en $tabla; si no, corta con 404. */
function dbExpRequerirColumna(PDO $pdo, string $tabla, string $columna): void
{
    if ($columna === '' || !in_array($columna, dbExpColumnasValidas($pdo, $tabla), true)) {
        json_error('La columna no existe en esta tabla', 404);
    }
}

/**
 * Metadatos de columnas de una tabla: pk / auto_inc / nullable / orden.
 * Devuelve un array asociativo:
 *   ['pk'=>[...], 'auto_inc'=>[...], 'nullable'=>[...], 'columnas'=>[...ordenadas...]]
 */
function dbExpMetadatosTabla(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME, COLUMN_KEY, EXTRA, IS_NULLABLE
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
          ORDER BY ORDINAL_POSITION'
    );
    $stmt->execute([':t' => $tabla]);
    $pk = $ai = $nu = $cols = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = (string) $r['COLUMN_NAME'];
        $cols[] = $c;
        if ($r['COLUMN_KEY'] === 'PRI')                                  $pk[]  = $c;
        if (stripos((string) $r['EXTRA'], 'auto_increment') !== false)   $ai[]  = $c;
        if ($r['IS_NULLABLE'] === 'YES')                                 $nu[]  = $c;
    }
    return ['pk' => $pk, 'auto_inc' => $ai, 'nullable' => $nu, 'columnas' => $cols];
}
