-- Migracion idempotente: introduce la tabla `senales`, que registra los
-- mensajes que cada dispositivo IoT publica al broker MQTT. Cada fila es
-- una senal individual ya recibida y persistida por el ingestor MQTT
-- (componente que vive fuera de este modulo).
--
-- Modelo conservador: cada senal queda asociada a un dispositivo
-- (FK `dispositivo_id`) y opcionalmente a un canal logico del mismo
-- (`canal_id` + `canal_label`, derivados del `/config.json` que vive en
-- el firmware). Guardamos topic original, tipo, valor y el payload JSON
-- crudo para poder inspeccionar mensajes desconocidos sin migrar la
-- tabla. Las senales son inmutables: solo se insertan y se consultan.

USE reactor_dev;

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

-- Seed demo: algunas senales recientes asociadas a los dispositivos
-- seed para que la vista no se vea vacia. Mezcla lecturas de sensores,
-- estados de actuadores, un evento y un error.
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