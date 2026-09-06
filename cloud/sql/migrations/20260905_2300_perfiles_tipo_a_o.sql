-- `perfiles`.`tipo` pasa a tener DOS valores posibles y ninguno mas:
--   'A' = Administrador      'O' = Operador
--
-- Es la misma decision que 20260905_2200 tomo para `habilitado`, aplicada a la
-- otra bandera de `perfiles`: la columna deja de admitir NULL y cadena vacia,
-- que no significan nada y que cada lector interpretaba a su manera.
--
-- ESTADO ANTES DE MIGRAR (medido en desarrollo, 2.227 filas)
--
--   'O'      1.761
--   'A'        444
--   NULL        16
--   ''           6
--
--   Las 22 filas sin valor reconocible NO estan repartidas al azar: 21 son
--   roles internos de Reactor -- Tecnico (7), Tecnico Instalador (4), Director
--   Tecnico (3), Contador (3), Director Comercial (2), Desarrollador (2) --,
--   casi todas del dominio 2, y la 22a es el PERFIL CENTINELA `id = 0` que creo
--   20260814_3100. O sea: son los perfiles que no son ni Administrador ni
--   Operador, que son justamente los dos unicos valores que la columna sabe
--   expresar. Por eso el problema no se arregla agregando letras: se arregla
--   eligiendo cual de las dos les corresponde.
--
-- LAS 22 VAN A 'O', NO A 'A'. Es el menos privilegiado de los dos, el mismo
-- criterio con el que 20260905_2200 mando a 0 todo lo que no reconocia: un
-- valor que ninguna convencion define no puede otorgar acceso. Y no le saca
-- nada a nadie -- el sistema legacy gatea por `tipo = 'A'`, asi que NULL y ''
-- ya fallaban ese chequeo antes de esta migracion.
--
-- LO QUE ESTA MIGRACION NO HACE: alinear `tipo` con `rol`. Las dos columnas
-- estan desalineadas a proposito en los datos -- 48 perfiles con rol
-- Administrador llevan `tipo = 'O'` y 10 con rol Operador llevan `tipo = 'A'` --
-- y derivar `tipo` de `rol` le daria acceso de administrador en el legacy a 48
-- cuentas que hoy no lo tienen. Eso es un cambio de permisos, no una limpieza
-- de tipos, y no entra en una migracion de esquema. El panel ya resolvio el
-- problema por otro lado: gatea por `rol` y no mira `tipo` (panel/lib/acceso.php).
--
-- POR QUE ENUM Y NO varchar(1) + CHECK. Es el patron que ya usa el esquema en
-- las tablas nacidas en este repo (`tareas`.`overlap`, `tareas`.`ultimo_estado`,
-- `tareas_ejecuciones`.`estado` / `disparo`) y no hay ni un CHECK declarado en
-- toda la base. Ademas deja los dos valores escritos en `db/schema.sql`, que es
-- el documento de referencia del repo. La comparacion sigue siendo por string,
-- asi que el `WHERE tipo = 'A'` del legacy -- que vive fuera de este
-- repositorio -- no se entera del cambio.
--
--   Ojo: un ENUM protege de verdad mientras el modo SQL sea estricto. Dev corre
--   con STRICT_TRANS_TABLES (verificado) y es el default de MariaDB 10.11 en
--   prod; sin el, un valor invalido se guardaria como '' en vez de fallar.
--
-- ORDEN DEL ENUM: 'A' primero y 'O' despues, que ademas de ser el orden en que
-- se leen coincide con el alfabetico. Un `ORDER BY tipo` ordena por el indice
-- interno del ENUM, no por el texto, asi que si algun dia se agrega un valor
-- hay que agregarlo AL FINAL o cambia el orden de listados que ya existen.
--
-- IDEMPOTENTE: el UPDATE corrido sobre la columna ya migrada deja 'A' en 'A' y
-- 'O' en 'O', y el ALTER contra el tipo que ya esta es un no-op. Reaplicarla no
-- cambia datos y no falla.
--
-- VISTAS: `perfilesvista` expone esta columna y hereda el tipo sola, igual que
-- paso con `habilitado`.
--
-- Sin `USE <base>`: la conexion ya selecciona la base (CLAUDE.md).


-- 1. Contenido: 'A' se conserva (en cualquier caja y con espacios) y TODO lo
--    demas -- NULL, '', y cualquier letra que no sea A -- pasa a 'O'.
UPDATE `perfiles`
   SET `tipo` = CASE WHEN UPPER(TRIM(COALESCE(`tipo`, ''))) = 'A' THEN 'A' ELSE 'O' END;

-- 2. Tipo: dos valores y ninguna ausencia. El default 'O' hace que una fila
--    insertada sin la columna nazca como Operador, no en NULL.
ALTER TABLE `perfiles`
  MODIFY `tipo` ENUM('A', 'O') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci
         NOT NULL DEFAULT 'O';
