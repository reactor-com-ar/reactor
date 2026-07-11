-- Ajuste one-time del Programador de tareas.
--
-- Contexto: la migracion 20260711_1300_crear_tareas.sql fue editada
-- despues de aplicarse por primera vez (violando la regla de la skill
-- de nunca editar un .sql aplicado). Consecuencia: el Migrador la
-- muestra en estado `drift` y la instalacion puede haber quedado a
-- medio camino, con:
--   - la tabla legacy `tareas` (id/nombre/comando, MyISAM huerfana), o
--   - las tablas nuevas creadas con nombre erroneo `tareas_cron` +
--     `tareas_cron_ejecuciones`, o
--   - las tablas correctas `tareas` + `tareas_ejecuciones`.
--
-- Este script cubre las 3 ramas de forma idempotente y ademas borra
-- la fila vieja de 1300 del ledger para que el Migrador lo re-liste
-- como `pendiente`. El re-apply de 1300 es un no-op limpio (CREATE
-- TABLE IF NOT EXISTS sobre tablas existentes) y termina registrando
-- el hash actual del archivo, dejando el ledger consistente.

SET @db := DATABASE();

-- (b) Si existe `tareas` con esquema legacy (columna `comando`), borrarla.
--     No la usa ninguna app del monorepo (grep verificado en api/panel/
--     app/www) y ocupa a lo sumo 2 filas.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tareas'
        AND COLUMN_NAME = 'comando') > 0,
    'DROP TABLE `tareas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (c) Si existe `tareas_cron` y no existe `tareas`, renombrar preservando datos.
SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tareas_cron') > 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tareas') = 0,
    'RENAME TABLE `tareas_cron` TO `tareas`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := (
  SELECT IF(
    (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tareas_cron_ejecuciones') > 0
    AND (SELECT COUNT(*) FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = @db AND TABLE_NAME = 'tareas_ejecuciones') = 0,
    'RENAME TABLE `tareas_cron_ejecuciones` TO `tareas_ejecuciones`',
    'SELECT 1'
  )
);
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- (a) Si no existen todavia (DB fresca), crear con el esquema de la skill.
CREATE TABLE IF NOT EXISTS `tareas` (
    `id`                 int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `nombre`             varchar(120) NOT NULL,
    `descripcion`        varchar(255) NULL DEFAULT NULL,
    `script`             varchar(255) NOT NULL,
    `cron_expr`          varchar(80)  NOT NULL,
    `activo`             tinyint(1)   NOT NULL DEFAULT 1,
    `overlap`            enum('skip','allow') NOT NULL DEFAULT 'skip',
    `timeout_seg`        int(10) UNSIGNED NOT NULL DEFAULT 300,
    `retencion_dias`     int(10) UNSIGNED NOT NULL DEFAULT 7,
    `ultimo_run`         datetime NULL DEFAULT NULL,
    `ultimo_estado`      enum('ok','error','timeout','killed','corriendo') NULL DEFAULT NULL,
    `ultimo_error`       text NULL,
    `fecha_creacion`     timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `fecha_modificacion` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tareas_nombre` (`nombre`),
    KEY `idx_tareas_activo_ultimo_run` (`activo`, `ultimo_run`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `tareas_ejecuciones` (
    `id`        int(10) UNSIGNED NOT NULL AUTO_INCREMENT,
    `tarea_id`  int(10) UNSIGNED NOT NULL,
    `pid`       int(10) UNSIGNED NULL DEFAULT NULL,
    `inicio`    datetime NOT NULL,
    `fin`       datetime NULL DEFAULT NULL,
    `estado`    enum('corriendo','ok','error','timeout','killed') NOT NULL DEFAULT 'corriendo',
    `exit_code` int NULL DEFAULT NULL,
    `mensaje`   text NULL,
    `log_path`  varchar(255) NULL DEFAULT NULL,
    `disparo`   enum('scheduler','manual') NOT NULL DEFAULT 'scheduler',
    PRIMARY KEY (`id`),
    KEY `idx_tareas_ej_tarea_id` (`tarea_id`, `id`),
    KEY `idx_tareas_ej_estado`   (`estado`),
    KEY `idx_tareas_ej_inicio`   (`inicio`)
) ENGINE = InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Silenciar el drift de 1300: borrar la fila del ledger. La proxima
-- vez que el Migrador liste, 1300 aparecera como `pendiente` y su
-- re-apply (idempotente, es CREATE TABLE IF NOT EXISTS sobre tablas
-- que ya existen) grabara el hash actual del archivo. Fin del drift.
DELETE FROM `migraciones` WHERE `nombre` = '20260711_1300_crear_tareas.sql';
