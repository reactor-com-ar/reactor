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
    config_json   JSON         DEFAULT NULL,
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
    celular       VARCHAR(30)  DEFAULT NULL,
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
INSERT IGNORE INTO usuarios (email, nombre, celular, password_hash, rol, activo) VALUES
('admin@reactor.com.ar',     'Administrador',  '+54 9 11 1234-5678', 'placeholder', 'admin',    1),
('operador@reactor.com.ar',  'Operador Demo',  '+54 9 11 2345-6789', 'placeholder', 'operador', 1),
('lectura@reactor.com.ar',   'Solo lectura',   NULL,                 'placeholder', 'lectura',  1);

CREATE TABLE IF NOT EXISTS perfiles (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    usuario_id  INT UNSIGNED NOT NULL,
    dominio_id  INT UNSIGNED NOT NULL,
    rol         ENUM('admin','operador') NOT NULL DEFAULT 'operador',
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_dominio (usuario_id, dominio_id),
    INDEX idx_usuario_id (usuario_id),
    INDEX idx_dominio_id (dominio_id),
    INDEX idx_rol (rol),
    CONSTRAINT fk_perfiles_usuario
        FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
    CONSTRAINT fk_perfiles_dominio
        FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- Seed: admin con perfil admin en todos los dominios; operador con
-- perfil operador en planta baja y acceso/perimetro.
INSERT IGNORE INTO perfiles (usuario_id, dominio_id, rol)
SELECT u.id, d.id, 'admin'
FROM usuarios u CROSS JOIN dominios d
WHERE u.email = 'admin@reactor.com.ar';

INSERT IGNORE INTO perfiles (usuario_id, dominio_id, rol)
SELECT u.id, d.id, 'operador'
FROM usuarios u CROSS JOIN dominios d
WHERE u.email = 'operador@reactor.com.ar'
  AND d.nombre IN ('Planta baja', 'Acceso y perimetro');

CREATE TABLE IF NOT EXISTS senales (
    id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dispositivo_id INT UNSIGNED NOT NULL,
    topic          VARCHAR(255) NOT NULL,
    canal_id       INT UNSIGNED DEFAULT NULL,
    canal_label    VARCHAR(120) DEFAULT NULL,
    tipo           VARCHAR(60)  NOT NULL,
    valor          VARCHAR(255) DEFAULT NULL,
    payload        JSON         DEFAULT NULL,
    recibido_at    DATETIME     NOT NULL,
    created_at     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_dispositivo_recibido (dispositivo_id, recibido_at),
    INDEX idx_recibido_at          (recibido_at),
    INDEX idx_tipo                 (tipo),
    CONSTRAINT fk_senales_dispositivo
        FOREIGN KEY (dispositivo_id) REFERENCES dispositivos(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO senales (dispositivo_id, topic, canal_id, canal_label, tipo, valor, payload, recibido_at)
SELECT d.id, CONCAT('reactor/', d.uid, '/data'), 4, 'temp_ambiente', 'lectura', '23.4',
       JSON_OBJECT('canal_id', 4, 'label', 'temp_ambiente', 'unidad', 'C', 'valor', 23.4),
       NOW() - INTERVAL 2 MINUTE
FROM dispositivos d WHERE d.uid = 'RX-0001';

INSERT IGNORE INTO senales (dispositivo_id, topic, canal_id, canal_label, tipo, valor, payload, recibido_at)
SELECT d.id, CONCAT('reactor/', d.uid, '/data'), 1, 'humedad_relativa', 'lectura', '58',
       JSON_OBJECT('canal_id', 1, 'label', 'humedad_relativa', 'unidad', '%', 'valor', 58),
       NOW() - INTERVAL 5 MINUTE
FROM dispositivos d WHERE d.uid = 'RX-0002';

INSERT IGNORE INTO senales (dispositivo_id, topic, canal_id, canal_label, tipo, valor, payload, recibido_at)
SELECT d.id, CONCAT('reactor/', d.uid, '/state'), 1, 'rele_1', 'estado', 'on',
       JSON_OBJECT('canal_id', 1, 'label', 'rele_1', 'estado', 'on'),
       NOW() - INTERVAL 10 MINUTE
FROM dispositivos d WHERE d.uid = 'RX-0003';

INSERT IGNORE INTO senales (dispositivo_id, topic, canal_id, canal_label, tipo, valor, payload, recibido_at)
SELECT d.id, CONCAT('reactor/', d.uid, '/event'), 3, 'puerta_principal', 'evento', 'open',
       JSON_OBJECT('canal_id', 3, 'label', 'puerta_principal', 'evento', 'open'),
       NOW() - INTERVAL 30 MINUTE
FROM dispositivos d WHERE d.uid = 'RX-0006';

INSERT IGNORE INTO senales (dispositivo_id, topic, canal_id, canal_label, tipo, valor, payload, recibido_at)
SELECT d.id, CONCAT('reactor/', d.uid, '/error'), NULL, NULL, 'error', 'sensor_disconnected',
       JSON_OBJECT('mensaje', 'No hay respuesta del sensor durante 5 ciclos', 'codigo', 'E_NO_RESPONSE'),
       NOW() - INTERVAL 15 MINUTE
FROM dispositivos d WHERE d.uid = 'RX-0005';

-- Ledger de la herramienta de Migraciones (cloud > Herramientas > Migraciones).
-- Registra cada archivo de cloud/sql/migrations/ que ya fue aplicado; el
-- runner saltea los archivos con success=1 para garantizar idempotencia.
CREATE TABLE IF NOT EXISTS migraciones (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    archivo      VARCHAR(255) NOT NULL,
    hash_sha256  CHAR(64)     NOT NULL,
    ejecutado_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    duracion_ms  INT UNSIGNED NOT NULL DEFAULT 0,
    success      TINYINT(1)   NOT NULL DEFAULT 1,
    error        TEXT         DEFAULT NULL,
    UNIQUE KEY uq_archivo (archivo),
    INDEX idx_ejecutado_at (ejecutado_at)
) ENGINE=InnoDB;
