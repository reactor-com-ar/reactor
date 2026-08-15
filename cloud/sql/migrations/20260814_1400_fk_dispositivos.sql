-- Claves foraneas de `dispositivos`.
--
--   dispositivos.agente      -> agentes.id        ON DELETE RESTRICT
--   dispositivos.dominio     -> dominios.id       ON DELETE RESTRICT
--   dispositivos.transceptor -> transceptores.id  ON DELETE RESTRICT
--   dispositivos.modelo      -> modelos.id        ON DELETE RESTRICT
--   dispositivos.producto    -> productos.id      ON DELETE RESTRICT
--   dispositivos.chip        -> chips.id          ON DELETE RESTRICT
--   dispositivos.adopcion    -> adopciones.id     ON DELETE RESTRICT
--
-- Continua 20260814_1300_fk_dominios.sql. Idempotente y portable dev/prod.
--
--
-- POR QUE LAS SIETE CON RESTRICT
--
--   Todas son referencias estructurales o de catalogo: a que dominio pertenece
--   el equipo, que agente lo atiende, que hardware lo compone (transceptor,
--   chip), que modelo y producto es, y bajo que adopcion se dio de alta.
--   Ninguna es un puntero de conveniencia al "ultimo usado" como
--   `usuarios`.`perfil` / `panel`, que por eso llevaron SET NULL. Borrar un
--   modelo, un producto o un dominio que todavia tiene equipos asociados debe
--   bloquearse, no vaciarse en silencio.
--
--   `adopcion` es el caso mas discutible -- apunta a un registro historico de
--   alta, no a una dependencia estructural, y con SET NULL tambien seria
--   defendible. Se deja RESTRICT por consistencia y porque es lo conservador:
--   pasar de RESTRICT a SET NULL despues es trivial, al reves implica haber
--   perdido punteros en el medio.
--
--
-- LIMPIEZA PREVIA -- censo (prod / dev, sobre 252 / 250 dispositivos):
--
--   agente      : 171 / 171 ceros,  0 huerfanos
--   dominio     :   0 /   0 ceros,  0 huerfanos   -> LIMPIA, no se toca nada
--   transceptor :   0 /   0 ceros,  0 huerfanos   -> LIMPIA, no se toca nada
--   modelo      :   0 /   0 ceros,  0 huerfanos   -> LIMPIA, no se toca nada
--   producto    :   0 /   0 ceros,  0 huerfanos   -> LIMPIA, no se toca nada
--   chip        : 246 / 244 ceros,  0 huerfanos
--   adopcion    : 117 / 115 ceros,  0 huerfanos
--
--   CERO huerfanos reales en las siete columnas, en los dos entornos: no se
--   descarta ni un solo valor. Lo unico que cambia es la representacion de
--   "sin asignar", de 0 a NULL, en agente / chip / adopcion.
--
--   Los UPDATE de las cuatro columnas limpias quedan igualmente escritos como
--   defensa ante drift (la base es live y el censo se tomo antes de aplicar),
--   pero se espera que afecten 0 filas.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- ---------------------------------------------------------------------------
-- 1. Neutralizar referencias invalidas (aca: solo ceros centinela).
-- ---------------------------------------------------------------------------

UPDATE `dispositivos` d SET d.`agente` = NULL
 WHERE d.`agente` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `agentes` x WHERE x.`id` = d.`agente`);

UPDATE `dispositivos` d SET d.`dominio` = NULL
 WHERE d.`dominio` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `dominios` x WHERE x.`id` = d.`dominio`);

UPDATE `dispositivos` d SET d.`transceptor` = NULL
 WHERE d.`transceptor` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `transceptores` x WHERE x.`id` = d.`transceptor`);

UPDATE `dispositivos` d SET d.`modelo` = NULL
 WHERE d.`modelo` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `modelos` x WHERE x.`id` = d.`modelo`);

UPDATE `dispositivos` d SET d.`producto` = NULL
 WHERE d.`producto` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `productos` x WHERE x.`id` = d.`producto`);

UPDATE `dispositivos` d SET d.`chip` = NULL
 WHERE d.`chip` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `chips` x WHERE x.`id` = d.`chip`);

UPDATE `dispositivos` d SET d.`adopcion` = NULL
 WHERE d.`adopcion` IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM `adopciones` x WHERE x.`id` = d.`adopcion`);


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

CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_agente',      'agente',      'agentes',       'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_dominio',     'dominio',     'dominios',      'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_transceptor', 'transceptor', 'transceptores', 'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_modelo',      'modelo',      'modelos',       'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_producto',    'producto',    'productos',     'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_chip',        'chip',        'chips',         'RESTRICT');
CALL _reactor_agregar_fk('dispositivos', 'fk_dispositivos_adopcion',    'adopcion',    'adopciones',    'RESTRICT');

DROP PROCEDURE _reactor_agregar_fk;
