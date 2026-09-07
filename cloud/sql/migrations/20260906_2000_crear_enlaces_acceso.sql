-- Tabla `enlaces_acceso`: enlaces de un solo uso que abren sesion en `panel` o
-- en `app` como un usuario dado, sin pedirle la contrasena. Los emite un
-- operador desde cloud (Usuarios -> Consultar -> Acciones).
--
-- ESTO ES SUPLANTACION DE IDENTIDAD, Y HAY QUE DIMENSIONARLO. Quien tenga el
-- enlace entra a la cuenta ajena con todo lo que esa cuenta puede hacer: en
-- `panel`, al dominio del perfil activo; en `app`, a sus paneles y sus
-- dispositivos. No es un "ver como" de solo lectura -- es la sesion real. Por
-- eso la tabla guarda quien lo emitio, cuando, desde que IP y cuando se uso:
-- si algo pasa, la unica forma de reconstruirlo es esta tabla.
--
-- LO QUE ACOTA EL RIESGO, y por que cada pieza esta:
--
--   token de un solo uso     `usada` se sella en el mismo UPDATE que lo valida
--                            (`WHERE usada IS NULL AND expira > NOW()`), asi que
--                            dos personas con el mismo enlace no entran las dos.
--   vida corta               15 minutos. No es un enlace para guardar: es para
--                            usarlo ahora o pedir otro.
--   se guarda el SHA-256     lo que viaja en la URL son 32 bytes de CSPRNG en
--                            base64url y NO queda escrito en ningun lado. Quien
--                            lea la base no puede fabricar un enlace valido.
--                            Mismo criterio que `recuperaciones`.
--   atado al destino         un enlace de `app` no abre `panel` ni al reves. Sin
--                            esta columna, el enlace mas facil de conseguir
--                            serviria para entrar al mas privilegiado.
--
-- POR QUE NO SE REUSA `recuperaciones`. Se le parece —token hasheado, un solo
-- uso, vencimiento— pero lleva a otra cosa: alla el enlace abre un formulario
-- para ELEGIR una contrasena nueva, y aca abre la sesion directamente. Meterlos
-- en la misma tabla haria que un token de recuperacion sirviera para entrar sin
-- cambiar nada, que es exactamente lo que esa pantalla evita.
--
-- `emisor` NO TIENE FK, a proposito. Hoy es un `usuarios`.`id` porque el login
-- de cloud todavia valida contra `usuarios`; cuando pase a `controladores` va a
-- ser un id de otra tabla. Una FK habria que soltarla ese dia y las filas
-- viejas quedarian apuntando a la tabla equivocada en silencio. Es un dato de
-- auditoria: se guarda tambien `emisor_tabla` para saber que significa el id.
--
-- LAS FILAS NO SE BORRAN NUNCA: el rastro de quien pidio entrar a que cuenta es
-- justamente lo que hay que conservar. La FK a `usuarios` es CASCADE porque un
-- enlace sin su usuario no puede consumirse; si esa cuenta se elimina, el
-- rastro se va con ella igual que el resto de sus datos.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `enlaces_acceso` (
    `id`            INT                       NOT NULL AUTO_INCREMENT,
    `usuario`       INT                       NOT NULL,
    `destino`       ENUM('panel','app')       NOT NULL,
    `token`         CHAR(64)                  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `emisor`        INT                       NULL DEFAULT NULL,
    `emisor_tabla`  VARCHAR(20)               CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `emitido`       DATETIME                  NOT NULL,
    `expira`        DATETIME                  NOT NULL,
    `usada`         DATETIME                  NULL DEFAULT NULL,
    `origen`        VARCHAR(45)               CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `origen_uso`    VARCHAR(45)               CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- El lookup del enlace entra por aca: es la unica busqueda de la tabla.
    UNIQUE KEY `uq_enlaces_acceso_token` (`token`),
    -- Para el cupo por usuario (COUNT sobre una ventana) y para auditar.
    KEY `ix_enlaces_acceso_usuario` (`usuario`, `emitido`),
    CONSTRAINT `fk_enlaces_acceso_usuario` FOREIGN KEY (`usuario`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
