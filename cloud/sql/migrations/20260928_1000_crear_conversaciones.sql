-- Tablas `conversaciones` y `conversaciones_mensajes`: el chat con IA de la
-- burbuja flotante del sitio publico (`www/chat/mensaje.php`).
--
-- EL HISTORIAL SE GUARDA PORQUE EL SERVIDOR LO NECESITA, no para archivarlo.
-- Cada turno del chat le manda al modelo la conversacion entera, y esa
-- conversacion se arma ACA y no con lo que mande el navegador: si el historial
-- viajara en el POST, cualquiera podria reescribirle al modelo lo que ya se
-- dijo —incluyendo lo que el sistema le prohibio contestar— y encima hacernos
-- pagar el contexto que se le antoje. El navegador manda el `uuid` de la
-- conversacion y el texto nuevo; todo lo demas sale de estas dos tablas.
--
-- LOS TRES CUPOS QUE FRENAN EL GASTO, y por que cada pieza esta:
--
--   `mensajes`            contador de turnos de la persona en la conversacion.
--                         El tope se aplica con `UPDATE ... SET mensajes =
--                         mensajes + 1 WHERE uuid = ? AND mensajes < N`, que
--                         valida y cuenta en la MISMA sentencia — mismo candado
--                         que `enlaces_acceso`.`usos`. Con un SELECT previo, dos
--                         POST simultaneos del ultimo turno pasarian los dos.
--   `origen` + `actividad` cupo por IP por hora. El indice esta para esa cuenta.
--   `fecha` del mensaje    cupo diario global, que es el freno de ultima
--                         instancia: si alguien descubre el endpoint y lo usa de
--                         proxy gratis de GPT, lo que lo corta es este COUNT.
--
-- ESTE ES EL PRIMER DOCROOT SIN LOGIN QUE ESCRIBE EN UNA TABLA POR PEDIDO DE
-- CUALQUIERA. `tecnicos` ya lo hacia, pero una fila de mas ahi cuesta una fila
-- de mas; aca cada INSERT dispara una llamada paga a un tercero. Por eso los
-- cupos son parte del esquema y no una decision de la aplicacion.
--
-- NO SE GUARDA EL PROMPT DE SISTEMA, que se arma de nuevo en cada turno con los
-- articulos de ayuda que matchean la pregunta: son varios KB por mensaje y se
-- pueden reconstruir. Lo que si se guarda es `contexto`, con los `uuid` de las
-- entradas que se le pasaron al modelo — que es lo que hace falta para entender
-- de donde saco una respuesta.
--
-- `rol` VA EN INGLES a proposito, y es la excepcion que ya admite el repo para
-- los valores de ENUM: son literalmente los roles que espera la API de OpenAI
-- (`user` / `assistant`), asi que traducirlos obligaria a un mapeo de ida y
-- vuelta en cada turno para no ganar nada. Los NOMBRES de tabla y columna si van
-- en espanol, como el resto del esquema.
--
-- `system` no esta en el ENUM porque no se guarda ninguna fila con ese rol (ver
-- arriba). Si algun dia hace falta, se agrega AL FINAL: `ORDER BY rol` ordena
-- por el indice interno y no por el texto.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `conversaciones` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    -- Lo unico que viaja al navegador. No es secreto —quien lo tiene solo puede
    -- seguir su propia conversacion— pero es aleatorio para que nadie pueda leer
    -- la de otro tipeando un id contiguo.
    `uuid`       CHAR(36)     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `iniciada`   DATETIME     NOT NULL,
    -- Ultimo mensaje. Es lo que ordena el listado y lo que acota el cupo por IP.
    `actividad`  DATETIME     NOT NULL,
    -- Turnos de la PERSONA (no del asistente): es lo que se cobra y lo que se
    -- topea. Ver el candado del UPDATE en la cabecera.
    `mensajes`   INT          NOT NULL DEFAULT 0,
    `origen`     VARCHAR(45)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `agente`     VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    -- Desde que pagina se abrio la burbuja. Sirve para saber que seccion genera
    -- consultas, que es la mitad del valor de tener esto guardado.
    `pagina`     VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- El lookup de cada turno entra por aca: es la unica busqueda de la tabla.
    UNIQUE KEY `uq_conversaciones_uuid` (`uuid`),
    -- Para el cupo por IP (COUNT sobre una ventana de una hora).
    KEY `ix_conversaciones_origen` (`origen`, `actividad`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `conversaciones_mensajes` (
    `id`             INT          NOT NULL AUTO_INCREMENT,
    `conversacion`   INT          NOT NULL,
    `fecha`          DATETIME     NOT NULL,
    `rol`            ENUM('user','assistant') NOT NULL,
    `texto`          MEDIUMTEXT   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    -- Las tres columnas de abajo son NULL en las filas de la persona: solo
    -- aplican a la respuesta del modelo.
    `modelo`         VARCHAR(60)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    -- `uuid` de las entradas de ayuda que se le pasaron al modelo, separados por
    -- coma. Es lo que permite reconstruir de donde salio una respuesta sin
    -- guardar el prompt entero.
    `contexto`       VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `tokens_entrada` INT          NULL DEFAULT NULL,
    `tokens_salida`  INT          NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- Reconstruir la conversacion para el turno siguiente. El `id` en el indice
    -- es el que da el orden: dos mensajes del mismo segundo tienen la misma
    -- `fecha` y `ORDER BY fecha` los devolveria en cualquier orden.
    KEY `ix_conversaciones_mensajes_conversacion` (`conversacion`, `id`),
    -- Cupo diario global.
    KEY `ix_conversaciones_mensajes_fecha` (`fecha`),
    -- CASCADE y no RESTRICT: los mensajes son el detalle de su conversacion y no
    -- significan nada sueltos, igual que `comprobantesrenglones`.
    CONSTRAINT `fk_conversaciones_mensajes_conversacion` FOREIGN KEY (`conversacion`) REFERENCES `conversaciones` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
