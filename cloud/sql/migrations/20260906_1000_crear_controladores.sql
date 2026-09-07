-- Tabla `controladores`: las personas que pueden entrar a Reactor Cloud.
--
-- POR QUE UNA TABLA NUEVA Y NO UNA COLUMNA EN `usuarios`. Cloud y el resto del
-- repo atienden a dos poblaciones que no se cruzan:
--
--   `usuarios`      -> clientes finales. Entran a `app` y a `panel`, tienen
--                      dominio, perfiles, dispositivos, carritos, adopciones.
--                      Son ~2100 filas y las crea el negocio.
--   `controladores` -> quienes operan la plataforma. Entran SOLO a cloud.
--
-- Un flag `es_controlador` en `usuarios` haria que cada alta de cliente sea a un
-- UPDATE de distancia de tener acceso al backoffice, y que la pregunta "quien
-- puede entrar a cloud" haya que contestarla filtrando 2100 filas en vez de
-- leyendo una tabla de 5. Con tablas separadas el acceso a cloud no es un
-- atributo que se pueda encender por accidente: es pertenecer a otra tabla.
-- Esa separacion es el requisito, no una consecuencia: ningun usuario comun
-- puede entrar a cloud, y ningun controlador puede entrar a `panel` ni a `app`.
--
-- SIN FOREIGN KEYS, y a proposito. `controladores` no cuelga de `usuarios`: si
-- colgara, borrar un cliente podria arrastrar (o bloquear) a un operador de la
-- plataforma. Tampoco cuelga de `dominios`: un controlador ve todo cloud, no un
-- dominio. Es una isla en el esquema y esa es exactamente la propiedad que se
-- busca.
--
-- `contrasena` GUARDA UN HASH BCRYPT (`password_hash()`), NO el cifrado
-- historico de Reactor. `usuarios.contrasena` es reversible (XOR sumativa +
-- base64 con clave global, ver cloud/api/legacy_crypto.php) porque hay 2083
-- filas escritas asi y un sistema legacy afuera de este repo que las lee; nada
-- de eso aplica a una tabla que nace hoy y que solo lee cloud. Por eso la
-- columna es varchar(255) y no varchar(50): un hash bcrypt son 60 chars, y el
-- margen deja lugar a algoritmos mas largos sin otra migracion.
-- Consecuencia visible: el ABM NO puede mostrar la contrasena vigente (el ABM
-- de Usuarios si lo hace, via `?credencial=1`). Solo se puede fijar una nueva.
--
-- `correo` es la credencial de login, por eso va UNIQUE y NOT NULL: dos filas
-- con el mismo correo harian ambiguo el `WHERE correo = :c` del login que viene
-- en el paso siguiente.
--
-- `habilitado` es tinyint(1) NOT NULL DEFAULT 0 con dos valores (1 y 0), igual
-- que en toda la base desde `20260905_2200_habilitado_tinyint_0_1.sql`. Se
-- escribe con `valorHabilitado()` y se lee con `esHabilitado()` (lib/habilitado.php).
-- El DEFAULT 0 es deliberado: una fila insertada a mano, sin pasar por el ABM,
-- nace SIN acceso.
--
-- `ingresado` queda NULL hasta el paso siguiente (login de cloud contra esta
-- tabla). Se crea ya para no volver a migrar cuando ese login exista, y para
-- que el ABM pueda mostrar "nunca ingreso" desde el primer dia.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite correrlo en entornos nuevos y
-- existentes sin efecto en el segundo caso.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `controladores` (
    `id`         INT          NOT NULL AUTO_INCREMENT,
    `nombre`     VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `correo`     VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `celular`    VARCHAR(30)  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
    `contrasena` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `habilitado` TINYINT(1)   NOT NULL DEFAULT 0,
    `registrado` DATETIME     NOT NULL,
    `ingresado`  DATETIME     NULL DEFAULT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    -- El login del paso siguiente entra por aca, y el UNIQUE es ademas la
    -- garantia de que la credencial identifica a una sola persona.
    UNIQUE KEY `uq_controladores_correo` (`correo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
