<?php

declare(strict_types=1);

// Helpers compartidos por los endpoints del Migrador DB
// (api/migraciones.php + api/migraciones_get.php + api/migraciones_apply.php).

/** Directorio absoluto de los .sql versionados. */
function migracionesDir(): string
{
    // __DIR__ = cloud/api/lib  ->  ../.. = cloud  ->  /sql/migrations
    return dirname(__DIR__, 2) . '/sql/migrations';
}

/**
 * Valida que el nombre sea un basename plano de .sql sin componentes de
 * ruta. Bloquea path traversal (../), archivos ocultos y extensiones
 * distintas de .sql.
 */
function nombreMigracionValido(string $nombre): bool
{
    if ($nombre === '' || strlen($nombre) > 255) return false;
    if (basename($nombre) !== $nombre)            return false;
    return (bool) preg_match('/^[A-Za-z0-9._\-]+\.sql$/', $nombre);
}

/**
 * Asegura que la tabla `migraciones` existe con el esquema del Migrador
 * DB (id / nombre / hash / aplicada). Si existe con el esquema legacy
 * (archivo / hash_sha256 / ejecutado_at / duracion_ms / success / error),
 * lo transiciona en el lugar preservando los datos. Idempotente: correr
 * multiples veces despues de la transicion es no-op.
 *
 * Esto permite que un entorno viejo con la tabla previa se auto-migre al
 * primer GET de listado del Migrador DB.
 */
function asegurarTablaMigraciones(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS `migraciones` (
            `id`       INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
            `nombre`   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
            `hash`     VARCHAR(64)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
            `aplicada` DATETIME     NULL DEFAULT NULL,
            PRIMARY KEY (`id`) USING BTREE
         ) ENGINE = InnoDB CHARACTER SET = utf8mb4 COLLATE = utf8mb4_unicode_ci ROW_FORMAT = Dynamic"
    );

    // Detectar columnas legacy y transicionar. Todas las operaciones son
    // idempotentes: si las columnas legacy ya no existen (o el nuevo
    // esquema ya esta completo), no se ejecuta nada.
    $cols = columnasDeTabla($pdo, 'migraciones');

    // 1) Agregar columnas nuevas faltantes (por si la tabla existia con
    //    esquema legacy y ninguna de las nuevas estaba). El CREATE de
    //    arriba las cubre solo cuando la tabla no existia; si ya existia
    //    con el schema viejo, no las trae.
    if (!in_array('nombre', $cols, true)) {
        $pdo->exec("ALTER TABLE `migraciones` ADD COLUMN `nombre` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `id`");
        $cols[] = 'nombre';
    }
    if (!in_array('hash', $cols, true)) {
        $pdo->exec("ALTER TABLE `migraciones` ADD COLUMN `hash` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER `nombre`");
        $cols[] = 'hash';
    }
    if (!in_array('aplicada', $cols, true)) {
        $pdo->exec("ALTER TABLE `migraciones` ADD COLUMN `aplicada` DATETIME NULL DEFAULT NULL AFTER `hash`");
        $cols[] = 'aplicada';
    }

    // 2) Copiar datos legacy a las columnas nuevas si aun existen las
    //    columnas viejas. UPDATE con condicion "IS NULL" para no pisar
    //    datos ya migrados.
    if (in_array('archivo', $cols, true)) {
        $pdo->exec("UPDATE `migraciones` SET `nombre` = `archivo` WHERE `nombre` IS NULL OR `nombre` = ''");
    }
    if (in_array('hash_sha256', $cols, true)) {
        $pdo->exec("UPDATE `migraciones` SET `hash` = `hash_sha256` WHERE `hash` IS NULL OR `hash` = ''");
    }
    if (in_array('ejecutado_at', $cols, true)) {
        $pdo->exec("UPDATE `migraciones` SET `aplicada` = `ejecutado_at` WHERE `aplicada` IS NULL");
    }

    // 3) Drop de indices legacy (el UNIQUE de `archivo` desaparece con la
    //    columna, pero por si el orden del ALTER falla lo pedimos antes).
    $indices = indicesDeTabla($pdo, 'migraciones');
    if (in_array('uq_archivo', $indices, true)) {
        $pdo->exec("ALTER TABLE `migraciones` DROP INDEX `uq_archivo`");
    }
    if (in_array('idx_ejecutado_at', $indices, true)) {
        $pdo->exec("ALTER TABLE `migraciones` DROP INDEX `idx_ejecutado_at`");
    }

    // 4) Drop de columnas legacy una vez que sus datos se copiaron.
    foreach (['archivo', 'hash_sha256', 'ejecutado_at', 'duracion_ms', 'success', 'error'] as $col) {
        if (in_array($col, $cols, true)) {
            $pdo->exec("ALTER TABLE `migraciones` DROP COLUMN `$col`");
        }
    }
}

/**
 * Devuelve la lista de columnas de una tabla en la DB actual. Vacia si
 * la tabla no existe.
 */
function columnasDeTabla(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $tabla]);
    return array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'COLUMN_NAME'));
}

/** Devuelve la lista de indices (por nombre) de una tabla. */
function indicesDeTabla(PDO $pdo, string $tabla): array
{
    $stmt = $pdo->prepare(
        'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS
          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
    );
    $stmt->execute([':t' => $tabla]);
    return array_map('strval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'INDEX_NAME'));
}
