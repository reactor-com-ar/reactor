CREATE DATABASE IF NOT EXISTS reactor_dev
    DEFAULT CHARACTER SET utf8mb4
    DEFAULT COLLATE utf8mb4_unicode_ci;

USE reactor_dev;

CREATE TABLE IF NOT EXISTS devices (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    uid           VARCHAR(64)  NOT NULL UNIQUE,
    name          VARCHAR(120) NOT NULL,
    type          VARCHAR(60)  NOT NULL,
    location      VARCHAR(120) DEFAULT NULL,
    status        ENUM('online','offline','error') NOT NULL DEFAULT 'offline',
    last_seen_at  DATETIME     DEFAULT NULL,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_status (status),
    INDEX idx_type   (type)
) ENGINE=InnoDB;

INSERT INTO devices (uid, name, type, location, status, last_seen_at) VALUES
('RX-0001', 'Sensor de temperatura sala A',  'temperature', 'Planta baja - Sala A', 'online',  NOW()),
('RX-0002', 'Sensor de humedad sala A',      'humidity',    'Planta baja - Sala A', 'online',  NOW()),
('RX-0003', 'Actuador HVAC',                 'actuator',    'Planta baja - Sala A', 'offline', NOW() - INTERVAL 2 HOUR),
('RX-0004', 'Camara IP entrada',             'camera',      'Acceso principal',     'online',  NOW()),
('RX-0005', 'Medidor de consumo trifasico',  'power-meter', 'Tablero general',      'error',   NOW() - INTERVAL 15 MINUTE),
('RX-0006', 'Sensor de puerta deposito',     'door',        'Deposito 1',           'offline', NOW() - INTERVAL 1 DAY);
