-- `perfiles` gana `registrante`: el id del usuario que dio de alta ESE ACCESO.
-- Va entre `panel` y `habilitado`, que es el final de la fila, donde ya viven
-- los datos de administracion del perfil y no los que describen que puede hacer.
--
-- ES EL ESPEJO DE `usuarios`.`registrante`, la columna que ya existe desde el
-- sistema historico y que las tres apps escriben en el alta de una cuenta
-- (`usuarioAlta()` en `lib/usuarios_alta.php`). La misma pregunta —"¿quien dio
-- de alta esto?"— se podia responder para la CUENTA y no para el ACCESO, y son
-- dos altas distintas: una cuenta se crea una sola vez y despues acumula
-- perfiles en varios dominios, cada uno otorgado por alguien distinto y en otro
-- momento. Con solo `usuarios`.`registrante`, el perfil numero dos de una
-- persona no tenia autor.
--
-- POR ESO LA INVITACION ESCRIBE LAS DOS COLUMNAS, PERO NO SIEMPRE. Al aceptar
-- una invitacion hay dos casos y se resuelven distinto:
--
--   la persona NO tenia cuenta -> se crean `usuarios` Y `perfiles`, y el emisor
--                                 de la invitacion queda en el `registrante` de
--                                 las dos filas.
--   la persona YA tenia cuenta -> solo se crea el perfil, y el emisor queda
--                                 unicamente en el `registrante` del perfil. La
--                                 cuenta NO se toca: la creo otra persona, en
--                                 otro momento y quiza en otro dominio, y
--                                 pisarle el dato seria reescribir un hecho.
--
-- MISMO TIPO Y MISMA FK QUE `usuarios`.`registrante`: `int NULL` con FK a
-- `usuarios`(`id`) `ON DELETE SET NULL ON UPDATE RESTRICT`.
--
-- EL `SET NULL` NO ES UN DETALLE. El resto del esquema es `RESTRICT`, pero acá
-- apunta a quien REGISTRO, no a quien pertenece: si el dia de mañana se borra
-- esa cuenta, los accesos que otorgo tienen que sobrevivir sin dueño de autoria
-- —siguen siendo accesos validos de OTRAS personas— y con `RESTRICT` habria que
-- reasignarlos uno por uno antes de poder borrarla. Borrar un usuario ya obliga
-- a borrar antes TODOS sus perfiles (`fk_perfiles_usuario` es `RESTRICT`);
-- sumarle los perfiles ajenos que alguna vez otorgo lo volveria impracticable.
--
-- NULL ES UN VALOR VALIDO, y no un dato faltante que haya que completar: es
-- "no se sabe quien lo registro". Lo van a tener las 2.227 filas que ya existen
-- —ver abajo— y tambien cualquier perfil que en el futuro cree un proceso sin
-- una persona detras.
--
-- ------------------------------------------------------------------------
-- NO HAY BACKFILL, Y ES A PROPOSITO
-- ------------------------------------------------------------------------
--
-- Al reves que `20260907_1000`, esta migracion NO siembra las filas existentes,
-- porque el dato no se puede reconstruir sin inventarlo:
--
--   * `perfiles` no guarda cuando se creo la fila (no hay `registrado` ni
--     `created_at`), asi que no hay como emparejarla con nada por fecha.
--   * `invitaciones` no apunta al perfil que produjo al aceptarse: la unica
--     relacion posible seria adivinar por `(dominio, correo)`, y una persona
--     puede tener varias invitaciones al mismo dominio y hasta varios perfiles
--     ahi (8 pares `(usuario, dominio)` con mas de uno).
--   * `usuarios`.`registrante` tampoco sirve de fuente: dice quien creo la
--     CUENTA, que es justamente el dato que esta columna NO duplica. Copiarlo
--     le atribuiria al que dio de alta la cuenta todos los accesos que otras
--     personas le fueron otorgando despues, en dominios que quiza ni conoce.
--
-- Un `registrante` inventado es peor que un `NULL`: el `NULL` se lee como "no
-- se sabe" y el otro se lee como un hecho. La columna arranca vacia y se va
-- llenando desde hoy, con los perfiles que se creen de acá en adelante.
--
-- IDEMPOTENTE con el patron `SET @s / PREPARE` del resto de las migraciones de
-- este repo: `ADD COLUMN IF NOT EXISTS` no existe en MySQL 8 (dev) aunque si en
-- MariaDB 10.11 (prod), asi que se pregunta a information_schema.
--
-- Sin `USE <base>`: la conexion PDO ya selecciona la del entorno (CLAUDE.md).


-- 1. La columna, detras de `panel`, o sea entre `panel` y `habilitado`.
SET @s = (SELECT IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles' AND COLUMN_NAME = 'registrante'),
    'DO 0',
    'ALTER TABLE `perfiles` ADD COLUMN `registrante` int DEFAULT NULL COMMENT ''id del usuario que dio de alta este perfil'' AFTER `panel`'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;

-- 2. La FK. El indice `fk_perfiles_registrante` lo crea el propio
--    `ADD CONSTRAINT` — no hace falta un `ADD KEY` aparte.
--
--    No hace falta convertir ningun `0` a NULL antes (el problema que resolvio
--    `20260814_3100`): la columna nace vacia en TODAS las filas, asi que no hay
--    valor previo que pueda violar la restriccion. Los `0` que si podrian
--    llegar los ataja el codigo, que escribe `?: null` como ya hace
--    `usuarioAlta()` con `usuarios`.`registrante`.
SET @s = (SELECT IF(EXISTS(
        SELECT 1 FROM information_schema.TABLE_CONSTRAINTS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'perfiles'
           AND CONSTRAINT_NAME = 'fk_perfiles_registrante'),
    'DO 0',
    'ALTER TABLE `perfiles` ADD CONSTRAINT `fk_perfiles_registrante`
        FOREIGN KEY (`registrante`) REFERENCES `usuarios` (`id`)
        ON DELETE SET NULL ON UPDATE RESTRICT'));
PREPARE st FROM @s; EXECUTE st; DEALLOCATE PREPARE st;
