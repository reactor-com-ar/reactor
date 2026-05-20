-- Migracion idempotente: introduce `senales_por_minuto`, una tabla de
-- agregado que materializa la cantidad de senales recibidas en cada
-- minuto cerrado del historico.
--
-- Motivacion: `senales` (db/schema.sql) tiene ~35M filas en MyISAM y solo
-- indice de PK. Cualquier query que cuente senales por fecha hace full
-- scan (cuelga). Como las senales son inmutables (solo se insertan), el
-- count de un minuto pasado nunca cambia => se puede cachear de por vida.
--
-- Patron de uso (cloud/api/signals_stats.php):
--   1) En cada poll, leer 60 filas de esta tabla por rango de PK (rapido).
--   2) Detectar los minutos faltantes desde `MAX(minuto)` hasta el minuto
--      cerrado anterior al actual y agregarlos via GROUP BY sobre senales
--      (acotado por `id > MAX(id) - LOOKBACK` para evitar full scan).
--   3) El minuto en curso NO se cachea (es volatil) -- siempre se cuenta
--      en vivo.
--
-- Una vez tibio el cache, cada poll solo re-cuenta el minuto en curso y
-- (eventualmente) agrega 1 fila nueva por minuto que transcurre. El
-- escaneo completo de 60 min sobre `senales` ocurre una sola vez (bootstrap).

-- No usar `USE <db>` aca: la conexion PDO de cloud/api/bootstrap.php ya
-- selecciona la DB del entorno via DB_NAME (`reactor` en prod,
-- `reactor_dev` en dev). Hardcodear `USE reactor_dev` rompia el runner en
-- prod con SQLSTATE[42000] 1049 Unknown database 'reactor_dev'.

CREATE TABLE IF NOT EXISTS senales_por_minuto (
    minuto       DATETIME     NOT NULL PRIMARY KEY,
    cantidad     INT UNSIGNED NOT NULL DEFAULT 0,
    generado_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
                              ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
