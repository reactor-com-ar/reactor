-- Siembra de `perfiles_paneles`: todo perfil arranca con acceso a TODOS los
-- paneles habilitados de SU dominio.
--
-- La tabla se creo vacia en `20260906_1600_crear_perfiles_paneles.sql`,
-- apoyandose en que "sin filas" significa "todos los del dominio". Esta
-- migracion vuelve ese permiso EXPLICITO: cada par (perfil, panel) queda como
-- una fila real, que es lo que permite despues quitar paneles de a uno desde el
-- editor en vez de tener que enumerarlos todos la primera vez.
--
-- EL JOIN ES LA GARANTIA DE QUE NADIE CRUZA DE DOMINIO. La condicion
-- `pa.dominio = pf.dominio` es lo unico que decide que panel le toca a que
-- perfil; no hay forma de que esta consulta produzca un par de dominios
-- distintos. Es importante porque la base NO puede expresar esa regla con una
-- FK — la valida `cloud/api/profiles.php` en cada escritura, y aca la impone la
-- forma del SELECT.
--
-- SOLO PANELES `habilitado = 1`, que es exactamente lo que ofrece el selector
-- del editor (`catalogoPaneles()` filtra igual). Si sembrara tambien los
-- deshabilitados, el editor no los mostraria y el primer `Guardar` de ese perfil
-- borraria esas filas sin que nadie lo pida. Hoy da lo mismo —los 162 paneles
-- estan habilitados— pero el criterio tiene que quedar escrito para cuando no
-- lo esten.
--
-- NUMEROS MEDIDOS ANTES DE APLICARLA:
--
--   perfiles ......................... 2227  (1 con dominio 0, el centinela)
--   paneles habilitados .............. 162   (0 deshabilitados)
--   filas que produce ................ 3598
--   perfiles que quedan con permisos . 2225
--   perfiles que quedan sin ninguna .. 2     (su dominio no tiene paneles)
--
-- Los 2 que quedan sin filas no pierden nada: su dominio no tiene paneles, asi
-- que "todos" y "ninguno" son la misma lista vacia.
--
-- ES UNA SIEMBRA DE UNA SOLA VEZ, NO UNA REGLA. Un panel creado despues de esta
-- migracion NO queda asignado automaticamente a los perfiles que ya existian:
-- hay que tildarlo en cada perfil, o volver a correr este INSERT a mano. Y un
-- perfil creado despues tampoco nace con filas — pero ese caso sigue cubierto
-- por la semantica "sin filas = todos" (el alta de cloud ademas los pre-tilda).
--
-- Idempotente: `INSERT IGNORE` contra el UNIQUE `(perfil, panel)`. Correrla dos
-- veces no duplica nada, y correrla despues de que alguien quito paneles a mano
-- SE LOS DEVUELVE — es lo que hace, no un efecto raro, pero conviene saberlo
-- antes de reaplicarla.
--
-- No usar `USE <db>` aca: la conexion PDO ya selecciona la DB del entorno via
-- DB_NAME (`reactor` en prod, `reactor_dev` en dev).

INSERT IGNORE INTO `perfiles_paneles` (`perfil`, `panel`, `asignado`)
SELECT pf.`id`, pa.`id`, NOW()
  FROM `perfiles` pf
  JOIN `paneles`  pa
    ON pa.`dominio` = pf.`dominio`
   AND pa.`habilitado` = 1
 WHERE pf.`dominio` IS NOT NULL
   AND pf.`dominio` > 0;
