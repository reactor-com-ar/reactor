-- `usuarios`.`dominio`, `.perfil` y `.panel` vacios se completan a partir de un
-- perfil de la propia cuenta. La columna que ya tiene un valor NO se toca.
--
-- QUE SON ESAS TRES COLUMNAS. Son la memoria de donde estaba trabajando la
-- persona la ultima vez: con que perfil, en que dominio y con que panel
-- abierto. No son permisos -- los permisos viven en `perfiles`, una fila por
-- acceso -- sino el "volve a dejarme donde estaba" del proximo ingreso. Por eso
-- se pueden derivar: si estan vacias, cualquier perfil de la cuenta describe un
-- lugar valido donde dejarla parada.
--
-- ESTADO ANTES DE MIGRAR (medido en desarrollo, 2.083 usuarios)
--
--   sin `panel`     1.998        sin las tres          38
--   sin `perfil`    1.173        con las tres          52
--   sin `dominio`     177        les falta alguna   2.031
--
-- DE DONDE SALE CADA VALOR. La regla no se invento aca: es la misma con la que
-- `appDominioActivo()` (app/lib/contexto.php, port de `cAcceso::controlar()`)
-- resuelve el contexto en cada request, y la misma con la que
-- `appPanelesDelDominio()` elige que panel abrir.
--
--   perfil   El primer perfil HABILITADO de la cuenta, `ORDER BY nombre, id`
--            -- el orden del legacy, no uno nuevo.
--   dominio  `perfiles.dominio` del perfil ya resuelto. Nunca de otro lado: el
--            dominio es un atributo del acceso, no de la cuenta.
--   panel    El que ese perfil ya recordaba (`perfiles.panel`) si sigue siendo
--            un panel habilitado del dominio; si no, el primero habilitado del
--            dominio por nombre, que es el que abriria `cPanel::perfil2id()`.
--            Vale la pena preferir el recordado: hay 937 perfiles con panel y
--            los 937 apuntan a un panel habilitado de su propio dominio, o sea
--            que el dato es confiable y es MAS especifico que el default.
--
-- LOS TRES PASOS VAN EN ESTE ORDEN Y NO ES CASUAL: cada uno consume lo que
-- dejo el anterior. Primero el perfil, despues el dominio DEL perfil recien
-- escrito, y por ultimo el panel DEL dominio recien escrito. Resolverlos por
-- separado contra la base original dejaria combinaciones incoherentes -- el
-- dominio de un perfil y el panel de otro -- que es justo el estado que
-- app/api/paneles.php describe como bug ("`perfiles.panel` apuntando a un panel
-- ajeno").
--
-- LA COHERENCIA MANDA SOBRE EL RELLENO. Si la cuenta ya recuerda un dominio, el
-- perfil que se le escribe tiene que ser de ESE dominio: la condicion
-- `p.dominio = u.dominio` del paso 1 no es un detalle. Sin ella, 111 cuentas
-- que recuerdan un dominio donde ya no tienen ningun perfil habilitado se
-- llevarian el id de un perfil de otro dominio, y esa combinacion es
-- exactamente la que `esPerfilAdministrador()` rechaza (panel/lib/acceso.php:
-- "hay 13 cuentas cuyo `usuarios.perfil` apunta a un perfil de administrador de
-- un dominio DISTINTO"). Preferimos dejar la columna vacia antes que llenarla
-- con algo que todos los lectores van a descartar. Esas 111 no pierden nada:
-- `appDominioActivo()` ya las resuelve por su segunda rama.
--
-- ELEGIR EXIGE PERFIL HABILITADO; DERIVAR NO. Los dos primeros pasos no hacen
-- lo mismo y por eso no filtran igual:
--
--   El paso 1 ELIGE un perfil en nombre de la cuenta, asi que solo puede
--   elegir uno usable: `p.habilitado = 1`. Todo lector lo exige
--   -- `appDominioActivo()`, `esPerfilAdministrador()`, `perfilesAdministrador()` --
--   y escribir uno deshabilitado seria fabricar una memoria que nadie honra.
--   Por eso 81 cuentas que solo tienen perfiles deshabilitados quedan sin
--   `perfil`.
--
--   El paso 2 no elige nada: DERIVA el dominio del perfil que la cuenta YA
--   recordaba, lo haya escrito quien lo haya escrito. Ahi no corresponde
--   filtrar por habilitado, porque el dato no es una recomendacion nuestra
--   sino el registro de donde estuvo la persona, y que despues le hayan
--   deshabilitado ese perfil no cambia donde estuvo. En desarrollo el paso 2
--   completo 139 filas desde un perfil preexistente (y 34 desde uno que acababa
--   de elegir el paso 1); de todas las filas de la tabla cuyo perfil recordado
--   esta deshabilitado hoy son 42, inertes en cualquier caso: un perfil en
--   `habilitado = 0` no abre ni el panel ni la app, con dominio cargado o sin el.
--
-- LO QUE QUEDA SIN COMPLETAR, Y POR QUE
--
--   194 cuentas sin `perfil`: 111 recuerdan un dominio donde no les queda
--       ningun perfil habilitado (ver arriba), 81 solo tienen perfiles
--       deshabilitados y 2 no tienen ninguno (una es el centinela `id = 0`).
--     4 cuentas sin `dominio`: son las 2 que no tienen ningun perfil mas 2 que
--       solo tienen perfiles deshabilitados y ademas no recordaban dominio.
--     5 cuentas sin `panel`: las 4 anteriores (sin dominio no hay de donde
--       sacarlo) mas una cuyo dominio -- `ServAguaCluaca` -- no tiene ni un
--       panel habilitado. Son 4 dominios en esa situacion; el panel no se
--       inventa.
--
-- LO QUE ESTA MIGRACION NO ARREGLA, Y NO ES SUYO. La tabla trae incoherencias
-- previas entre las tres columnas que siguen igual despues de correr, porque
-- todas viven en filas donde las dos columnas ya tenian valor:
--
--   51 cuentas cuyo `usuarios.perfil` apunta a un perfil de un dominio
--      DISTINTO del que recuerda `usuarios.dominio` (el caso que documenta
--      panel/lib/acceso.php, ahi contado solo sobre perfiles de administrador).
--    2 cuentas cuyo `usuarios.panel` es de otro dominio que el recordado.
--
-- Ninguna la introduce esta migracion, y se puede demostrar sin comparar
-- contra un backup: los pasos 1 y 2 SIEMPRE terminan con
-- `usuarios.dominio = perfil.dominio` -- el paso 1 lo exige en el WHERE cuando
-- ya habia dominio, y el paso 2 lo iguala cuando no --, asi que toda fila donde
-- hoy difieren quedo fuera de los dos. Lo mismo con el panel: el paso 3 solo
-- escribe paneles cuyo `dominio` es el de la cuenta.
--
-- IDEMPOTENTE: los tres pasos filtran por "esta vacio", asi que una segunda
-- corrida no actualiza ninguna fila. Tampoco escribe NULL sobre NULL: cada paso
-- lleva un EXISTS/JOIN que exige que haya algo que copiar.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).

-- ------------------------------------------------------------------
-- 1. `perfil`: el primero habilitado, respetando el dominio recordado.
-- ------------------------------------------------------------------
UPDATE `usuarios` u
   SET u.`perfil` = (
           SELECT p.`id`
             FROM `perfiles` p
            WHERE p.`usuario` = u.`id`
              AND p.`habilitado` = 1
              AND p.`dominio` IS NOT NULL
              AND (u.`dominio` IS NULL OR u.`dominio` = 0 OR p.`dominio` = u.`dominio`)
            ORDER BY p.`nombre`, p.`id`
            LIMIT 1)
 WHERE (u.`perfil` IS NULL OR u.`perfil` = 0)
   AND EXISTS (
           SELECT 1
             FROM `perfiles` p
            WHERE p.`usuario` = u.`id`
              AND p.`habilitado` = 1
              AND p.`dominio` IS NOT NULL
              AND (u.`dominio` IS NULL OR u.`dominio` = 0 OR p.`dominio` = u.`dominio`));

-- ------------------------------------------------------------------
-- 2. `dominio`: el del perfil (el que ya tenia o el que dejo el paso 1).
-- ------------------------------------------------------------------
UPDATE `usuarios` u
 INNER JOIN `perfiles` p ON p.`id` = u.`perfil`
   SET u.`dominio` = p.`dominio`
 WHERE (u.`dominio` IS NULL OR u.`dominio` = 0)
   AND p.`dominio` IS NOT NULL;

-- ------------------------------------------------------------------
-- 3. `panel`: el que el perfil recordaba, o el primero del dominio.
-- ------------------------------------------------------------------
UPDATE `usuarios` u
   SET u.`panel` = COALESCE(
           (SELECT pa.`id`
              FROM `paneles` pa
             WHERE pa.`id` = (SELECT p.`panel` FROM `perfiles` p WHERE p.`id` = u.`perfil`)
               AND pa.`dominio` = u.`dominio`
               AND pa.`habilitado` = 1),
           (SELECT pa.`id`
              FROM `paneles` pa
             WHERE pa.`dominio` = u.`dominio`
               AND pa.`habilitado` = 1
             ORDER BY pa.`nombre`, pa.`id`
             LIMIT 1))
 WHERE (u.`panel` IS NULL OR u.`panel` = 0)
   AND u.`dominio` IS NOT NULL
   AND u.`dominio` > 0
   AND EXISTS (
           SELECT 1
             FROM `paneles` pa
            WHERE pa.`dominio` = u.`dominio`
              AND pa.`habilitado` = 1);
