-- Migracion idempotente: introduce la tabla `chips`, registro de las
-- tarjetas SIM utilizadas en la plataforma. Toda SIM va ligada a un
-- dominio (el agrupador logico del operador, igual que los dispositivos).
--
-- Identificadores fisicos de la SIM:
--   - `iccid`  : 19-20 digitos grabados en la SIM (unico mundial).
--   - `numero` : linea telefonica asignada por el operador (puede portarse).
-- Ambos quedan UNIQUE para evitar duplicados al cargar el mismo chip dos veces.

USE reactor_dev;

CREATE TABLE IF NOT EXISTS chips (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    dominio_id  INT UNSIGNED NOT NULL,
    numero      VARCHAR(30)  NOT NULL,
    iccid       VARCHAR(22)  NOT NULL,
    operador    VARCHAR(60)  NOT NULL,
    apn         VARCHAR(120) DEFAULT NULL,
    plan        VARCHAR(120) DEFAULT NULL,
    estado      ENUM('activo','inactivo','suspendido') NOT NULL DEFAULT 'activo',
    notas       VARCHAR(500) DEFAULT NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_chips_iccid  (iccid),
    UNIQUE KEY uq_chips_numero (numero),
    INDEX idx_chips_dominio_id (dominio_id),
    INDEX idx_chips_estado     (estado),
    INDEX idx_chips_operador   (operador),
    CONSTRAINT fk_chips_dominio
        FOREIGN KEY (dominio_id) REFERENCES dominios(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

INSERT IGNORE INTO chips (dominio_id, numero, iccid, operador, apn, plan, estado, notas) VALUES
    (2, '+54 9 11 4555-1010', '8954420000000010101', 'Movistar', 'internet.movistar.com.ar', 'M2M 500 MB',  'activo',     'Asignada al sensor de temperatura sala A'),
    (3, '+54 9 11 4555-2020', '8954310000000020202', 'Claro',    'igprs.claro.com.ar',      'M2M 1 GB',    'activo',     'Camara IP entrada'),
    (1, '+54 9 11 4555-3030', '8954320000000030303', 'Personal', 'internet.personal.com',   'Linea M2M',   'inactivo',   'Repuesto, sin asignar todavia'),
    (3, '+54 9 11 4555-4040', '8954420000000040404', 'Movistar', 'internet.movistar.com.ar', 'M2M 500 MB', 'suspendido', 'Suspendida por falta de pago');
