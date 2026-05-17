-- Migracion idempotente: pasa el esquema a nombres en espanol.
--   domains   -> dominios
--   devices   -> dispositivos
--   columnas de negocio:  name/description/domain_id/type/location/status
--                         -> nombre/descripcion/dominio_id/tipo/ubicacion/estado
-- En entornos nuevos las tablas ya se crean en espanol via schema.sql,
-- asi que cada paso valida si hay algo que renombrar antes de ejecutar.

USE reactor_dev;

-- ---------- 1. Renombrar tablas ----------
SET @oldDomains := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'domains');
SET @newDominios := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dominios');
SET @sql := IF(@oldDomains = 1 AND @newDominios = 0,
    'RENAME TABLE domains TO dominios',
    'SELECT "domains ya renombrado o inexistente" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @oldDevices := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'devices');
SET @newDispositivos := (SELECT COUNT(*) FROM information_schema.tables
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos');
SET @sql := IF(@oldDevices = 1 AND @newDispositivos = 0,
    'RENAME TABLE devices TO dispositivos',
    'SELECT "devices ya renombrado o inexistente" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 2. Renombrar columnas de dominios ----------
SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dominios' AND column_name = 'name');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dominios CHANGE COLUMN name nombre VARCHAR(120) NOT NULL',
    'SELECT "dominios.nombre OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dominios' AND column_name = 'description');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dominios CHANGE COLUMN description descripcion VARCHAR(255) DEFAULT NULL',
    'SELECT "dominios.descripcion OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------- 3. Renombrar columnas de dispositivos ----------
-- Para renombrar domain_id hace falta soltar la FK previa, renombrar la
-- columna y volver a crear la FK con el nuevo nombre.
SET @hasFk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND constraint_name = 'fk_devices_domain');
SET @sql := IF(@hasFk = 1,
    'ALTER TABLE dispositivos DROP FOREIGN KEY fk_devices_domain',
    'SELECT "FK vieja fk_devices_domain no existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasIdx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND index_name = 'idx_domain_id');
SET @sql := IF(@hasIdx = 1,
    'ALTER TABLE dispositivos DROP INDEX idx_domain_id',
    'SELECT "indice viejo idx_domain_id no existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'domain_id');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dispositivos CHANGE COLUMN domain_id dominio_id INT UNSIGNED NOT NULL',
    'SELECT "dispositivos.dominio_id OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Recrear indice + FK con nombre nuevo si todavia no existen
SET @hasIdx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND index_name = 'idx_dominio_id');
SET @sql := IF(@hasIdx = 0,
    'ALTER TABLE dispositivos ADD INDEX idx_dominio_id (dominio_id)',
    'SELECT "idx_dominio_id ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasFk := (SELECT COUNT(*) FROM information_schema.table_constraints
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND constraint_name = 'fk_dispositivos_dominio');
SET @sql := IF(@hasFk = 0,
    'ALTER TABLE dispositivos
        ADD CONSTRAINT fk_dispositivos_dominio
            FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT',
    'SELECT "fk_dispositivos_dominio ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Resto de columnas de dispositivos
SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'name');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dispositivos CHANGE COLUMN name nombre VARCHAR(120) NOT NULL',
    'SELECT "dispositivos.nombre OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'type');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dispositivos CHANGE COLUMN type tipo VARCHAR(60) NOT NULL',
    'SELECT "dispositivos.tipo OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'location');
SET @sql := IF(@hasOld = 1,
    'ALTER TABLE dispositivos CHANGE COLUMN location ubicacion VARCHAR(120) DEFAULT NULL',
    'SELECT "dispositivos.ubicacion OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- estado: cambiar nombre y reindexar
SET @hasOldIdx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND index_name = 'idx_status');
SET @sql := IF(@hasOldIdx = 1,
    'ALTER TABLE dispositivos DROP INDEX idx_status',
    'SELECT "indice viejo idx_status no existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasOld := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'status');
SET @sql := IF(@hasOld = 1,
    "ALTER TABLE dispositivos CHANGE COLUMN status estado ENUM('online','offline','error') NOT NULL DEFAULT 'offline'",
    'SELECT "dispositivos.estado OK" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @hasIdx := (SELECT COUNT(*) FROM information_schema.statistics
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos'
      AND index_name = 'idx_estado');
SET @sql := IF(@hasIdx = 0,
    'ALTER TABLE dispositivos ADD INDEX idx_estado (estado)',
    'SELECT "idx_estado ya existe" AS info');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
