-- Migracion idempotente: agrega la columna `celular` a usuarios.
-- Texto libre porque conviven formatos (+54 9 ..., 011-..., internacionales),
-- VARCHAR(30) alcanza para los formatos mas largos.

USE reactor_dev;

SET @hasCol := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'usuarios' AND column_name = 'celular');

SET @sql := IF(@hasCol = 0,
    'ALTER TABLE usuarios ADD COLUMN celular VARCHAR(30) DEFAULT NULL AFTER nombre',
    'SELECT "usuarios.celular ya existe" AS info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
