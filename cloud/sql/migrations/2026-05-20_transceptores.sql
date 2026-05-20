-- Migracion idempotente: introduce la tabla `transceptores` en el dev DB
-- (`reactor_dev`) reflejando la definicion de la base legacy
-- (`db/schema.sql`), para que el modulo ABM de transceptores tenga sobre
-- que operar.
--
-- En la legacy esta tabla es MyISAM y guarda los gateways/conectores
-- (host:puerto + credenciales + identificador de entrada) que emiten
-- las senales hacia los dispositivos. `senales.transceptor` la apunta
-- como FK logica (sin constraint).
--
-- En entornos donde la tabla ya exista (legacy importada) la migracion
-- no hace nada gracias al IF NOT EXISTS + INSERT IGNORE.

USE reactor_dev;

CREATE TABLE IF NOT EXISTS transceptores (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nombre      VARCHAR(255) DEFAULT NULL,
    host        VARCHAR(255) DEFAULT NULL,
    puerto      VARCHAR(255) DEFAULT NULL,
    usuario     VARCHAR(255) DEFAULT NULL,
    contrasena  VARCHAR(255) DEFAULT NULL,
    entrada     VARCHAR(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT IGNORE INTO transceptores (id, nombre, host, puerto, usuario, contrasena, entrada) VALUES
    (1, 'Gateway principal',  'mqtt.reactor.local', '1883', 'reactor',  'reactor', 'reactor/in'),
    (2, 'Gateway respaldo',   'mqtt.reactor.local', '1884', 'reactor',  'reactor', 'reactor/backup'),
    (3, 'Pasarela SMS',       'sms.reactor.local',  '2525', 'sms-svc',  NULL,      NULL);
