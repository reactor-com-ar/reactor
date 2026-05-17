-- Migracion idempotente: crea la tabla `usuarios` y la siembra con tres
-- cuentas demo. En entornos nuevos la tabla ya se crea via schema.sql, asi
-- que CREATE TABLE IF NOT EXISTS y INSERT IGNORE hacen que esta migracion
-- sea segura de re-ejecutar.

USE reactor_dev;

CREATE TABLE IF NOT EXISTS usuarios (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    email         VARCHAR(120) NOT NULL UNIQUE,
    nombre        VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    rol           ENUM('admin','operador','lectura') NOT NULL DEFAULT 'operador',
    activo        TINYINT(1)   NOT NULL DEFAULT 1,
    last_login_at DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_rol    (rol),
    INDEX idx_activo (activo)
) ENGINE=InnoDB;

INSERT IGNORE INTO usuarios (email, nombre, password_hash, rol, activo) VALUES
('admin@reactor.com.ar',     'Administrador',  'placeholder', 'admin',    1),
('operador@reactor.com.ar',  'Operador Demo',  'placeholder', 'operador', 1),
('lectura@reactor.com.ar',   'Solo lectura',   'placeholder', 'lectura',  1);
