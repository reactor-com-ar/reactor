-- Tabla `recuperaciones`: enlaces de un solo uso para restablecer la
-- contrasena desde el login del panel (panel/recuperar/). Cada fila es un
-- pedido: se emite al solicitar el enlace, se consume al guardar la
-- contrasena nueva y no se borra nunca (queda como rastro de auditoria).
--
-- POR QUE UNA TABLA NUEVA Y NO `activaciones` / `autenticaciones`:
-- las dos existen en el esquema historico y ninguna la usa el codigo de
-- este repo (grep vacio). `activaciones` es generica (objeto/identidad) y
-- no tiene vencimiento propio ni indice por token; `autenticaciones` no
-- distingue el motivo de la emision. Colgar el reset de cualquiera de las
-- dos mezclaria datos vivos con tablas muertas del sistema viejo.
--
-- `token` GUARDA EL SHA-256 DEL TOKEN, NO EL TOKEN. El valor que viaja en
-- el enlace del correo son 32 bytes aleatorios en base64url (43 chars) y
-- no queda escrito en ningun lado: quien lea la base no puede armar un
-- enlace valido. Es la unica pieza de auth del panel que no se guarda de
-- forma reversible -- `usuarios.contrasena` si lo es (cifrado historico),
-- pero eso ya esta decidido y no es motivo para repetirlo aca.
--
-- `expira` es una columna y no un calculo sobre `solicitada`: el TTL puede
-- cambiar y los enlaces ya emitidos tienen que conservar el suyo.
--
-- FK CON ON DELETE CASCADE, a diferencia del RESTRICT que usa casi todo el
-- esquema: un token sin su usuario no vale nada, y con RESTRICT esta tabla
-- se sumaria a la lista de cosas que hay que borrar a mano antes de
-- eliminar una cuenta desde el BackOffice (que ya arrastra `perfiles`).
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite correrlo tanto en
-- entornos nuevos como existentes sin efecto en el segundo caso.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno
-- via DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `recuperaciones` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `usuario`    INT          NOT NULL,
    `token`      CHAR(64)     CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `correo`     VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `origen`     VARCHAR(45)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `solicitada` DATETIME     NOT NULL,
    `expira`     DATETIME     NOT NULL,
    `usada`      DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- El lookup del enlace entra por aca: es la unica busqueda de la tabla.
    UNIQUE KEY `uq_recuperaciones_token` (`token`),
    -- Los dos indices siguientes son para el cupo por hora (COUNT sobre una
    -- ventana), no para listados.
    KEY `ix_recuperaciones_usuario` (`usuario`, `solicitada`),
    KEY `ix_recuperaciones_origen` (`origen`, `solicitada`),
    CONSTRAINT `fk_recuperaciones_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
