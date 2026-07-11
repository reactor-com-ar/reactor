-- Tablas `tareas` + `tareas_ejecuciones` para el Programador de tareas
-- (herramienta cloud, skill crear_programador_de_tareas §2).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite correr esta migracion
-- tanto en entornos nuevos como existentes sin efecto en el segundo caso.
--
-- Si el entorno ya tenia una version previa (rename a `tareas_cron` que
-- fue un error mio) o la tabla legacy `tareas` id/nombre/comando,
-- la migracion complementaria 20260711_1400_ajustar_tareas.sql hace la
-- transicion en el lugar.

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
