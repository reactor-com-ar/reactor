-- `controladores`.`contrasena` deja de ser un hash bcrypt y pasa al cifrado
-- historico de Reactor (XOR sumativa + base64, clave global '0123456789'), el
-- mismo que ya usa `usuarios`.`contrasena` y que vive en
-- cloud/api/legacy_crypto.php.
--
-- POR QUE. El modal de Controladores tiene un ojo en el campo Contrasena, pero
-- no tenia nada que revelar: con bcrypt el campo abria vacio y el ojo solo
-- servia para releer lo que uno estaba tipeando. El pedido es que el modal se
-- comporte como el de Usuarios —la contrasena vigente precargada en puntos, y
-- el ojo la muestra— y eso exige poder deshacer lo guardado. Un hash no se
-- deshace; el cifrado legacy si.
--
-- LO QUE SE PIERDE, DICHO SIN VUELTAS. La clave del cifrado legacy es la
-- constante '0123456789' y esta escrita en el repo, asi que a partir de esta
-- migracion la columna es texto plano para cualquiera que lea la base: un dump,
-- una replica, o el propio Explorador DB de cloud. Y estas no son credenciales
-- de un dominio: son las que abren el backoffice entero. Se decidio asi a
-- cambio de que el ojo funcione y de quedar consistente con `usuarios`; queda
-- anotado aca para que dentro de seis meses el motivo no haya que adivinarlo.
--
-- LAS FILAS EXISTENTES NO SE CONVIERTEN PORQUE NO SE PUEDE. Los dos hashes que
-- hay son bcrypt ($2y$10$..., 60 chars) y son irreversibles: no existe forma de
-- recuperar el plano para volver a cifrarlo. Por eso la migracion FIJA una
-- contrasena nueva para los dos controladores en vez de migrarlos, y el valor
-- lo eligio el operador: '654654654'.
--
--   'b2VlaGhoa2tr' = reactor_legacy_encriptar('654654654')
--
-- Los dos tienen que cambiarla despues del primer ingreso.
--
-- EL TIPO DE LA COLUMNA NO SE TOCA. Sigue siendo varchar(255) NOT NULL: le
-- sobra lugar (el cifrado de 9 chars ocupa 12) y achicarla obligaria a un
-- segundo ALTER el dia que se vuelva atras. `usuarios`.`contrasena` es
-- varchar(50) por herencia del sistema historico, no por diseno.

UPDATE `controladores`
   SET `contrasena` = 'b2VlaGhoa2tr'
 WHERE `contrasena` LIKE '$2y$%'
    OR `contrasena` LIKE '$2a$%'
    OR `contrasena` LIKE '$2b$%';
