-- Tabla puente `controladores_roles`: que roles tiene asignado cada
-- controlador de Reactor Cloud. Depende de
-- `20260906_1000_crear_controladores.sql`, que crea la tabla del lado
-- izquierdo; el orden alfabetico de los archivos ya las aplica en ese orden.
--
-- POR QUE UNA TABLA PUENTE Y NO UNA COLUMNA. `roles` es una relacion N a N: un
-- controlador puede tener varios roles y un rol lo tienen varios controladores.
-- Las dos alternativas que hay en el esquema historico son peores:
--
--   - Una columna `rol` en `controladores`, como `perfiles`.`rol`. Deja un solo
--     rol por persona, y el catalogo ya separa "Administrador" de "Director
--     Tecnico" / "Contador" como cosas acumulables.
--   - Un varchar con la lista de ids entre parentesis, como `roles`.`permisos`.
--     Es el formato que el legacy impone en las columnas que EL lee, y por eso
--     `roles` lo conserva; pero esta tabla la lee unicamente cloud, asi que no
--     hay ninguna razon para nacer con un formato que no se puede indexar, no
--     tiene integridad referencial y hay que parsear con una regex.
--
-- FK AL CONTROLADOR CON ON DELETE CASCADE. Una asignacion sin su controlador no
-- vale nada, y con RESTRICT esta tabla se sumaria a la lista de cosas que hay
-- que borrar a mano antes de dar de baja a alguien. Es el mismo criterio de
-- `recuperaciones` -> `usuarios`.
--
-- FK AL ROL CON ON DELETE RESTRICT, y a proposito distinta de la anterior.
-- Borrar un rol que alguien tiene asignado no es una limpieza: es sacarle el
-- acceso a esa persona sin decirselo a nadie. Es el mismo criterio que ya aplica
-- `perfiles`.`rol`, y obliga a que el modulo Roles cuente tambien esta tabla en
-- su desglose de impacto -- si no, el DELETE muere con un 1451 del motor en vez
-- de con el mensaje que explica que hay que hacer.
--
-- UNIQUE (controlador, rol): la misma asignacion dos veces no significa nada
-- distinto de tenerla una vez, y sin la restriccion el ABM podria duplicarla
-- con dos guardados seguidos.
--
-- INDICE PROPIO SOBRE `rol`: el UNIQUE arranca por `controlador`, asi que no
-- sirve para la consulta inversa ("quien tiene este rol"), que es justo la que
-- necesita el desglose de impacto del modulo Roles.
--
-- SIN COLUMNA `habilitado`: la asignacion existe o no existe. El estado vive en
-- `controladores`.`habilitado` (la persona) y en `roles`.`habilitado` (el rol);
-- una tercera bandera aca solo agregaria un estado mas que mantener de acuerdo.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS permite correrlo en entornos nuevos y
-- existentes sin efecto en el segundo caso.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `controladores_roles` (
    `id`          INT      NOT NULL AUTO_INCREMENT,
    `controlador` INT      NOT NULL,
    `rol`         INT      NOT NULL,
    `asignado`    DATETIME NOT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `uq_controladores_roles` (`controlador`, `rol`),
    KEY `ix_controladores_roles_rol` (`rol`),
    CONSTRAINT `fk_controladores_roles_controlador` FOREIGN KEY (`controlador`) REFERENCES `controladores` (`id`) ON DELETE CASCADE  ON UPDATE RESTRICT,
    CONSTRAINT `fk_controladores_roles_rol`         FOREIGN KEY (`rol`)         REFERENCES `roles`         (`id`) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
