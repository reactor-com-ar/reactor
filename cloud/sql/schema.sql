CREATE DATABASE IF NOT EXISTS reactor_dev
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

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

CREATE TABLE IF NOT EXISTS dispositivos (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid           VARCHAR(64)  NOT NULL UNIQUE,
    dominio_id    INT UNSIGNED NOT NULL,
    nombre        VARCHAR(120) NOT NULL,
    tipo          VARCHAR(60)  NOT NULL,
    ubicacion     VARCHAR(120) DEFAULT NULL,
    estado        ENUM('online','offline','error') NOT NULL DEFAULT 'offline',
    last_seen_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_estado     (estado),
    INDEX idx_tipo       (tipo),
    INDEX idx_dominio_id (dominio_id),
    CONSTRAINT fk_dispositivos_dominio
        FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO dispositivos (uid, dominio_id, nombre, tipo, ubicacion, estado, last_seen_at) VALUES
('RX-0001', 2, 'Sensor de temperatura sala A',  'temperature', 'Planta baja - Sala A', 'online',  NOW()),
('RX-0002', 2, 'Sensor de humedad sala A',      'humidity',    'Planta baja - Sala A', 'online',  NOW()),
('RX-0003', 2, 'Actuador HVAC',                 'actuator',    'Planta baja - Sala A', 'offline', NOW() - INTERVAL 2 HOUR),
('RX-0004', 3, 'Camara IP entrada',             'camera',      'Acceso principal',     'online',  NOW()),
('RX-0005', 1, 'Medidor de consumo trifasico',  'power-meter', 'Tablero general',      'error',   NOW() - INTERVAL 15 MINUTE),
('RX-0006', 3, 'Sensor de puerta deposito',     'door',        'Deposito 1',           'offline', NOW() - INTERVAL 1 DAY);

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

-- Seed: usuarios demo para que el ABM no aparezca vacio en desarrollo.
-- password_hash es un placeholder (no verifica con password_verify); el modulo
-- de Usuarios permite resetear la clave desde la UI.
INSERT IGNORE INTO usuarios (email, nombre, password_hash, rol, activo) VALUES
('admin@reactor.com.ar',     'Administrador',  'placeholder', 'admin',    1),
('operador@reactor.com.ar',  'Operador Demo',  'placeholder', 'operador', 1),
('lectura@reactor.com.ar',   'Solo lectura',   'placeholder', 'lectura',  1);
