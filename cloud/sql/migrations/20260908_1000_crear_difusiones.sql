-- Tablas `difusiones` y `difusiones_destinatarios`: los envios masivos de
-- correo que un controlador dispara desde cloud (Comunicacion -> Difusion).
--
-- SON DOS TABLAS Y NO UNA PORQUE UNA DIFUSION NO ES UN CORREO: es un correo por
-- cada destinatario, y cada uno se manda por separado -- el microservicio de
-- Databox (`POST /v4/aws/mensajes`, el mismo canal de las invitaciones) recibe
-- un solo `destino` por llamada. Sin la tabla hija no habria forma de decir
-- cuales salieron y cuales no, que es justamente lo que hay que poder mirar
-- cuando alguien pregunta "le llego a Fulano?".
--
-- POR QUE NO SE REUSA `correos`. Existe una tabla `correos` en el esquema
-- (remitente / remite / destinatario / destino / asunto / cuerpo / plantilla /
-- encolado / enviado / envio): es la cola de salida del sistema historico, la
-- ultima fila es de octubre de 2023 y son 16 en total. No tiene con que
-- agrupar los mensajes de una misma campana ni quien la disparo, asi que
-- meterle las difusiones adentro obligaria a inventarle esas columnas y a
-- compartir tabla con un productor que esta fuera de este repo. Se deja como
-- esta.
--
-- LA LISTA DE DESTINATARIOS SE CONGELA AL CREAR LA DIFUSION, no se resuelve al
-- enviar. Se inserta una fila por destinatario dentro de la misma transaccion
-- del alta, con el correo y el nombre COPIADOS de `usuarios`. Dos motivos:
--
--   1. La audiencia es una decision del operador, tomada en el momento en que
--      confirma el envio contra un numero que vio en pantalla. Si la consulta
--      se repitiera al enviar, un alta hecha entre medio recibiria un correo
--      que nadie decidio mandarle y el total dejaria de coincidir.
--   2. El envio se drena por lotes (ver abajo), asi que la consulta se
--      repetiria muchas veces: con la lista congelada cada lote toma las
--      pendientes y no hay forma de mandar dos veces al mismo.
--
-- El correo se copia ademas de la FK porque es lo que se le mando: si esa
-- cuenta despues lo cambia o se elimina, el historial tiene que seguir
-- diciendo a que direccion salio. Mismo criterio con el que `comprobantes`
-- guarda los datos del cliente en vez de resolverlos por FK al imprimir.
--
-- EL ALCANCE POR DOMINIO SE RESUELVE POR `perfiles`, NO POR `usuarios.dominio`
-- (lo aplica el endpoint, no esta tabla, pero la columna `dominio` de aca es la
-- que lo registra). `usuarios.dominio` es el dominio ACTIVO -- el ultimo que la
-- persona uso en cualquiera de los sistemas que comparten la tabla -- y no la
-- lista de dominios a los que puede entrar. Medido: el dominio 135 tiene 148
-- cuentas con ese dominio activo y 143 con perfil habilitado ahi, y las dos
-- listas no son una subconjunto de la otra (el 241 da 140 contra 141). Es el
-- mismo razonamiento ya escrito para el modulo Usuarios del panel.
--
-- `dominio` NULL SIGNIFICA "TODOS LOS DOMINIOS" y es un valor valido, no un
-- dato faltante: es la difusion global. Por eso la FK es ON DELETE SET NULL --
-- si el dominio se elimina, la difusion que se le mando queda como historial
-- sin dominio, no se borra ni bloquea la baja. Lo mismo que hace
-- `notificaciones` con su propia FK a `dominios`.
--
-- `emisor` ES UN `controladores`.`id`, no un `usuarios`.`id`: a cloud se entra
-- con una cuenta de esa tabla desde el 07/09/2026 (`cloud/api/login.php`). La
-- FK es ON DELETE SET NULL con el mismo criterio que `perfiles`.`registrante`:
-- apunta a quien DISPARO el envio, no a quien pertenece, y con RESTRICT dar de
-- baja a un controlador obligaria a borrar antes todas las difusiones que
-- mando alguna vez.
--
-- LOS CONTADORES (`destinatarios` / `enviados` / `fallidos`) SON UN CACHE de lo
-- que la tabla hija ya dice, y estan a proposito: el listado muestra las tres
-- cifras por fila y resolverlas con subconsultas seria un COUNT por difusion
-- por render. Los escribe el mismo endpoint que cierra cada lote, en la misma
-- transaccion que actualiza las filas hijas, contando sobre la hija -- nunca
-- sumandole 1 a lo que habia, que es como se desincronizan estos contadores.
--
-- `estado` de la difusion:
--   pendiente   creada, todavia no salio ninguno
--   enviando    hay al menos uno mandado y todavia quedan pendientes
--   enviada     no queda ninguno pendiente (con o sin fallidos)
--   cancelada   el operador la corto; las pendientes se quedan pendientes y
--               el drenaje se niega a seguir
--
-- `estado` del destinatario: pendiente / enviado / fallido. `fallido` guarda el
-- motivo que devolvio el microservicio en `error`, que es lo unico que despues
-- explica por que a esa persona no le llego.
--
-- LOS ESTADOS SON ENUM Y NO UNA BANDERA `habilitado`: son tres y cuatro valores
-- excluyentes, no un si/no. La regla de `habilitado` (tinyint 1/0) no aplica
-- aca, y por eso ninguna columna se llama asi. Si algun dia se agrega un valor,
-- va AL FINAL del ENUM: `ORDER BY estado` ordena por el indice interno.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).

CREATE TABLE IF NOT EXISTS `difusiones` (
    `id`             INT           NOT NULL AUTO_INCREMENT,
    `asunto`         VARCHAR(200)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `cuerpo`         TEXT          CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    -- NULL = todos los dominios. Ver arriba: es un valor, no un faltante.
    `dominio`        INT           NULL DEFAULT NULL,
    `estado`         ENUM('pendiente','enviando','enviada','cancelada')
                                   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                                   NOT NULL DEFAULT 'pendiente',
    -- Cache de lo que dice `difusiones_destinatarios`. Ver arriba.
    `destinatarios`  INT           NOT NULL DEFAULT 0,
    `enviados`       INT           NOT NULL DEFAULT 0,
    `fallidos`       INT           NOT NULL DEFAULT 0,
    `emisor`         INT           NULL DEFAULT NULL,
    `creada`         DATETIME      NOT NULL,
    -- Momento en que se cerro el envio (ya no quedan pendientes).
    `terminada`      DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    KEY `fk_difusiones_dominio` (`dominio`),
    KEY `fk_difusiones_emisor` (`emisor`),
    CONSTRAINT `fk_difusiones_dominio` FOREIGN KEY (`dominio`) REFERENCES `dominios` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT `fk_difusiones_emisor`  FOREIGN KEY (`emisor`)  REFERENCES `controladores` (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;

CREATE TABLE IF NOT EXISTS `difusiones_destinatarios` (
    `id`        INT           NOT NULL AUTO_INCREMENT,
    `difusion`  INT           NOT NULL,
    -- La cuenta a la que se le mando. NULL si esa cuenta se elimino despues:
    -- la fila sobrevive porque `correo` guarda a donde salio de verdad.
    `usuario`   INT           NULL DEFAULT NULL,
    `correo`    VARCHAR(100)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `nombre`    VARCHAR(100)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `estado`    ENUM('pendiente','enviado','fallido')
                              CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
                              NOT NULL DEFAULT 'pendiente',
    `error`     VARCHAR(255)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `enviado`   DATETIME      NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- El drenaje pide "las N pendientes de esta difusion" una vez por lote: es
    -- la unica consulta caliente de la tabla y entra entera por este indice.
    KEY `ix_difusiones_dest_difusion_estado` (`difusion`, `estado`, `id`),
    KEY `fk_difusiones_dest_usuario` (`usuario`),
    -- CASCADE y no RESTRICT: la fila hija no significa nada sin su difusion, y
    -- borrar una difusion es borrar la campana entera. Es el mismo criterio de
    -- `recuperaciones` con `usuarios`.
    CONSTRAINT `fk_difusiones_dest_difusion` FOREIGN KEY (`difusion`) REFERENCES `difusiones` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_difusiones_dest_usuario`  FOREIGN KEY (`usuario`)  REFERENCES `usuarios`   (`id`) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
