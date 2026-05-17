-- Migracion idempotente: agrega la columna `config_json` a dispositivos.
-- Es el JSON libre que el operador edita desde el modulo de Dispositivos
-- (sin schema fijo: la validacion del contenido la hace el firmware).
-- Tipo JSON nativo de MySQL 8: validacion sintactica al insertar/actualizar,
-- y permite indexar/consultar campos internos mas adelante si hace falta.

USE reactor_dev;

SET @hasCol := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'config_json');

SET @sql := IF(@hasCol = 0,
    'ALTER TABLE dispositivos ADD COLUMN config_json JSON DEFAULT NULL AFTER estado',
    'SELECT "dispositivos.config_json ya existe" AS info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
