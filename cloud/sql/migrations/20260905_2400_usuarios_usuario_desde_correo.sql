-- `usuarios`.`usuario` vacio se completa con el correo; si tampoco hay correo,
-- con el celular. La fila que ya tiene un login NO se toca.
--
-- POR QUE. `usuarios.usuario` es la credencial con la que se entra al panel y a
-- cloud: los dos hacen `WHERE usuario = :u` (panel/api/login.php,
-- cloud/api/login.php). Desde el 18/06/2025 20:52 el alta de cara al cliente
-- dejo de escribir esa columna -- la ultima cuenta con login es la #2479
-- (18/06/2025 18:05) y la primera sin login la #2480, dos horas y media
-- despues --, asi que las cuentas creadas a partir de ahi no pueden ingresar a
-- ninguno de los dos por mas que existan, esten habilitadas y tengan
-- contrasena guardada (139 de las 140 la tienen).
--
-- No es que esas cuentas esten a medio crear: estan llenas del OTRO lado de la
-- convencion. El mundo de cara al cliente identifica a la persona por correo o
-- celular -- app/sesion/iniciar.php: `WHERE celular = :cel OR correo = :cor` --
-- y nunca necesito `usuario`. Esta migracion no inventa un dato: copia al
-- campo que leen panel y cloud el mismo valor con el que la persona ya entra a
-- la app.
--
-- POR QUE EL CORREO Y NO OTRA COSA. Es lo que hacia el alta vieja: de las 1.943
-- cuentas donde `usuario` y `correo` existen, en 1.926 son el mismo valor
-- (99,1%). El celular queda de segunda opcion para la cuenta sin correo -- en
-- desarrollo no lo necesita ninguna (139 de 140 tienen correo), pero produccion
-- tiene mas filas y el alta pide uno u otro.
--
-- ESTADO ANTES DE MIGRAR (medido en desarrollo, 2.083 usuarios)
--
--   sin `usuario`                    140
--     con correo                     139   -> se copia el correo
--     sin correo, con celular          0   -> se copiaria el celular
--     sin correo y sin celular         1   -> NO SE TOCA (no hay que copiar)
--
-- LO QUE NO HACE
--
--   - No toca ninguna fila que ya tenga algo en `usuario`, ni siquiera para
--     normalizarla. La condicion del WHERE es "esta vacio", no "difiere del
--     correo": hay 17 cuentas viejas cuyo login no es su correo y son validas.
--   - No escribe cadena vacia sobre NULL: la fila sin correo y sin celular
--     queda exactamente como esta. Eso es lo que ademas hace la migracion
--     IDEMPOTENTE -- despues de correr, ninguna fila cumple las dos
--     condiciones a la vez, asi que reaplicarla no actualiza nada.
--   - No desduplica (ver abajo) ni arregla el origen: el alta de cara al
--     cliente sigue sin escribir la columna, asi que las filas nuevas van a
--     volver a nacer sin login hasta que se corrija alla.
--
-- COLISIONES QUE DEJA, Y POR QUE SE DEJAN. `usuarios.usuario` no tiene UNIQUE
-- en la base y nunca lo tuvo -- la unicidad la valida el panel al dar de alta,
-- no el motor --, asi que la copia no puede fallar, pero deja 9 valores
-- repetidos en desarrollo:
--
--   7 pares repetidos DENTRO del grupo que se rellena (las dos filas nacieron
--   sin login con el mismo correo): 2601/2602, 2565/2566, 2549/2550,
--   2513/2514, 2524/2525, 2569/2581, 2610/2611.
--
--   2 que chocan con un login que YA existia: la #2481 (gfmedina702003@
--   hotmail.com, ya usado por la #417) y la #2484 (nmerida@ossesanjuan.com.ar,
--   ya usado por la #1041).
--
-- Los dos logins resuelven con `LIMIT 1`, asi que en cada par entra una sola de
-- las filas y la otra queda inalcanzable por login -- que es exactamente lo que
-- le pasa hoy a las 140, o sea que ninguna queda peor que antes. Elegir cual
-- sobrevive, fusionarlas o descartar la duplicada es una decision de negocio
-- caso por caso y no entra en una migracion: quedan listadas aca para revisarlas.
--
-- (Los 9 son los que agrega esta migracion. La tabla ya traia un par repetido
-- de antes, `fabioconejero7@gmail.com` en las filas 12 y 79, que no tiene nada
-- que ver con esto: quien cuente los duplicados despues de migrar va a ver 10.)
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).

UPDATE `usuarios`
   SET `usuario` = CASE
           WHEN TRIM(COALESCE(`correo`, '')) <> '' THEN TRIM(`correo`)
           ELSE TRIM(COALESCE(`celular`, ''))
       END
 WHERE (`usuario` IS NULL OR TRIM(`usuario`) = '')
   AND (TRIM(COALESCE(`correo`, '')) <> '' OR TRIM(COALESCE(`celular`, '')) <> '');
