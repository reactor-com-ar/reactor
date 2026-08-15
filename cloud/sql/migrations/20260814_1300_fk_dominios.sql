-- Claves foraneas de `dominios`.
--
--   dominios.agente   -> agentes.id     ON DELETE RESTRICT
--   dominios.cliente  -> clientes.id    ON DELETE RESTRICT
--   dominios.contrato -> contratos.id   ON DELETE RESTRICT
--
-- Continua 20260814_1200_fk_usuarios.sql. Idempotente y portable dev/prod.
--
-- Las tablas padre son `agentes`, `clientes` y `contratos`, en PLURAL.
--
--
-- POR QUE LAS TRES CON RESTRICT
--
--   Son relaciones estructurales: un dominio pertenece a un cliente, tiene un
--   agente asignado y se rige por un contrato. No son punteros de conveniencia
--   al "ultimo usado" como `usuarios`.`perfil` / `panel`, que por eso llevaron
--   SET NULL en la migracion anterior. Aca borrar un cliente o un contrato que
--   todavia tiene dominios colgando debe bloquearse, no vaciarse en silencio.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev, sobre 151 / 148 dominios):
--
--   agente   : 0 / 0 ceros, 0 / 0 huerfanos   -> LIMPIA, no se toca ningun dato
--   cliente  : 0 / 0 ceros, 0 / 0 huerfanos   -> LIMPIA, no se toca ningun dato
--   contrato : 113 / 113 ceros + 3 / 3 huerfanos
--
--   Los 151 dominios de produccion apuntan a un agente y un cliente validos,
--   sin una sola excepcion. Los UPDATE de esas dos columnas quedan igualmente
--   escritos como defensa ante drift -- la base es live y el censo se tomo
--   antes de aplicar -- pero se espera que afecten 0 filas.
--
--   Los 3 huerfanos de `contrato` son dominios reales cuyo contrato fue
--   borrado: id 145 "Electronica San Juan" -> contrato 122, id 169
--   "Condominio Piedras Blancas" -> 123, id 192 "Patio San Ignacio NORTE Con
--   GARAGE" -> 131. Los tres numeros caen dentro del rango vigente de
--   `contratos` (103-163), o sea que existieron y se eliminaron. No hay forma
--   de reconstruirlos, asi que el puntero se NULea.
--
--   Mismo patron `NOT EXISTS` de las migraciones anteriores: el 0 no existe en
--   el padre, con lo que ceros y huerfanos caen en la misma condicion.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas.
-- ---------------------------------------------------------------------------

UPDATE `dominios` d SET d.`agente` = NULL
 WHERE d.`agente` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `agentes` x WHERE x.`id` = d.`agente`);

UPDATE `dominios` d SET d.`cliente` = NULL
 WHERE d.`cliente` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `clientes` x WHERE x.`id` = d.`cliente`);

UPDATE `dominios` d SET d.`contrato` = NULL
 WHERE d.`contrato` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `contratos` x WHERE x.`id` = d.`contrato`);


-- ---------------------------------------------------------------------------
-- 2. Alta de las constraints, solo si no existen ya.
-- ---------------------------------------------------------------------------

DROP PROCEDURE IF EXISTS _reactor_agregar_fk;
CREATE PROCEDURE _reactor_agregar_fk(
    IN p_tabla   VARCHAR(64),
    IN p_nombre  VARCHAR(64),
    IN p_columna VARCHAR(64),
    IN p_padre   VARCHAR(64),
    IN p_on_del  VARCHAR(16)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND TABLE_NAME        = p_tabla
           AND CONSTRAINT_NAME   = p_nombre
           AND CONSTRAINT_TYPE   = 'FOREIGN KEY'
    ) THEN
        SET @s = CONCAT('ALTER TABLE `', p_tabla, '` ADD CONSTRAINT `', p_nombre,
                        '` FOREIGN KEY (`', p_columna, '`) REFERENCES `', p_padre,
                        '` (`id`) ON DELETE ', p_on_del, ' ON UPDATE RESTRICT');
        PREPARE st FROM @s;
        EXECUTE st;
        DEALLOCATE PREPARE st;
    END IF;
END;

CALL _reactor_agregar_fk('dominios', 'fk_dominios_agente',   'agente',   'agentes',   'RESTRICT');
CALL _reactor_agregar_fk('dominios', 'fk_dominios_cliente',  'cliente',  'clientes',  'RESTRICT');
CALL _reactor_agregar_fk('dominios', 'fk_dominios_contrato', 'contrato', 'contratos', 'RESTRICT');

DROP PROCEDURE _reactor_agregar_fk;
