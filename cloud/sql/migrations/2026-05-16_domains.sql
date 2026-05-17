-- Migracion idempotente: introduce el concepto de "dominio" como agrupador
-- logico de dispositivos. Todos los dispositivos quedan ligados a un dominio.

USE reactor_dev;

CREATE TABLE IF NOT EXISTS dominios (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(120) NOT NULL UNIQUE,
    descripcion VARCHAR(255) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO dominios (id, nombre, descripcion) VALUES
    (1, 'General',            'Dominio por defecto para dispositivos sin clasificar'),
    (2, 'Planta baja',        'Sensores y actuadores ubicados en el nivel de planta baja'),
    (3, 'Acceso y perimetro', 'Camaras, controles de acceso y sensores perimetrales');

-- Columna dispositivos.dominio_id + indice + FK (solo si todavia no existen).
-- En entornos viejos la tabla puede llamarse "devices"; la migracion
-- 2026-05-16_rename_to_spanish.sql se encarga del rename.
SET @hasCol := (SELECT COUNT(*) FROM information_schema.columns
    WHERE table_schema = DATABASE() AND table_name = 'dispositivos' AND column_name = 'dominio_id');

SET @sql := IF(@hasCol = 0,
    'ALTER TABLE dispositivos
        ADD COLUMN dominio_id INT UNSIGNED NOT NULL DEFAULT 1 AFTER uid,
        ADD INDEX idx_dominio_id (dominio_id),
        ADD CONSTRAINT fk_dispositivos_dominio
            FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT',
    'SELECT "dominio_id ya existe, skip" AS info');

PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
