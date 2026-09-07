-- Tabla puente `perfiles_paneles`: a que paneles de su dominio puede entrar un
-- perfil desde `app`. Los paneles son las pantallas con botones que comandan
-- los dispositivos IoT (`paneles` -> `controles` -> `botones`).
--
-- NACE VACIA, Y ESO ES UNA DECISION MEDIDA. Existe `perfiles`.`paneles`, un
-- varchar(100) del sistema historico con la lista `(1177)(1231)` y valor en
-- 2020 de las 2227 filas, que parece el candidato obvio a sembrar. No lo es:
-- de los 2375 pares (perfil, panel) que contiene,
--
--     2368  apuntan a un panel que NO EXISTE en `paneles`
--        2  apuntan a un panel de OTRO dominio
--        5  son validos (existen y son del dominio del perfil)
--
-- Cinco pares repartidos en cuatro perfiles. Sembrar eso no migra nada: deja
-- una tabla que parece tener datos y no los tiene, y le quita los paneles a los
-- otros 2016 perfiles que hoy los ven. La columna vieja se deja donde esta
-- (nadie en este repo la lee) y esta tabla arranca de cero.
--
-- SIN FILAS SIGNIFICA "TODOS LOS PANELES DEL DOMINIO", NO "NINGUNO". Es la
-- unica semantica que no rompe nada: hoy `app` muestra todos los paneles
-- habilitados del dominio a cualquiera (`appPanelesDelDominio()` filtra por
-- `paneles.dominio`, nunca por perfil), asi que con la tabla vacia el
-- comportamiento queda identico al de antes de esta migracion. La restriccion
-- es opt-in: recien cuando un perfil tiene al menos una fila, `app` lo acota a
-- esas. La contracara es que NO se puede expresar "este perfil no ve ningun
-- panel" — vaciar la seleccion vuelve a "todos". El editor lo dice en pantalla.
--
-- LAS DOS FKs SON `ON DELETE CASCADE`. Una fila de esta tabla no significa nada
-- sin sus dos puntas, y ninguna de las dos bajas queda desprotegida por eso:
-- borrar un perfil ya se confirma desde el panel (y arrastra sus `sesiones`), y
-- los paneles no se borran desde este repo. Es distinto del RESTRICT de
-- `controladores_roles`.`rol`, donde lo que estaba en juego era el acceso de una
-- persona a todo el backoffice.
--
-- UNIQUE (perfil, panel): el mismo permiso dos veces no significa nada distinto
-- de tenerlo una vez.
--
-- INDICE PROPIO SOBRE `panel`: el UNIQUE arranca por `perfil`, asi que no sirve
-- para la consulta inversa ("quien puede entrar a este panel").
--
-- NO SE VALIDA EN LA BASE que el panel sea del mismo dominio que el perfil --
-- no hay forma de expresarlo con una FK. Lo valida `cloud/api/profiles.php` en
-- cada escritura, y el selector del editor solo ofrece los del dominio.
--
-- Idempotente: CREATE TABLE IF NOT EXISTS.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

CREATE TABLE IF NOT EXISTS `perfiles_paneles` (
    `id`       INT      NOT NULL AUTO_INCREMENT,
    `perfil`   INT      NOT NULL,
    `panel`    INT      NOT NULL,
    `asignado` DATETIME NOT NULL,
    PRIMARY KEY (`id`) USING BTREE,
    UNIQUE KEY `uq_perfiles_paneles` (`perfil`, `panel`),
    KEY `ix_perfiles_paneles_panel` (`panel`),
    CONSTRAINT `fk_perfiles_paneles_perfil` FOREIGN KEY (`perfil`) REFERENCES `perfiles` (`id`) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT `fk_perfiles_paneles_panel`  FOREIGN KEY (`panel`)  REFERENCES `paneles`  (`id`) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC;
