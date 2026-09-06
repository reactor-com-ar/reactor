# reactor

El esquema de base de datos de referencia para todo el repositorio es [db/schema.sql](db/schema.sql). Consultarlo antes de proponer queries, endpoints, modelos o cambios que toquen datos: nombres de tablas, columnas, tipos, charsets y relaciones deben coincidir con lo definido ahí. Si una funcionalidad requiere una tabla o columna que no existe en `db/schema.sql`, proponer primero la modificación al esquema antes de escribir código que la asuma.

## La bandera `habilitado`: 0 y 1, nada más

Toda columna llamada `habilitado` —en cualquier tabla y para cualquier app del
repo (`panel`, `cloud`, `app`, ...)— es `tinyint(1) NOT NULL DEFAULT 0` y tiene
**exactamente dos valores posibles**:

| valor | significado |
|---|---|
| `1` | habilitado |
| `0` | deshabilitado |

No hay `NULL`, no hay `'S'`/`'N'`, no hay cadena vacía. Lo unificó la migración
[cloud/sql/migrations/20260905_2200_habilitado_tinyint_0_1.sql](cloud/sql/migrations/20260905_2200_habilitado_tinyint_0_1.sql),
que descubre las columnas de `information_schema` en vez de enumerarlas: una
tabla nueva con esa columna queda cubierta por el mismo criterio.

Reglas al escribir código:

- **Leer con `esHabilitado($fila['habilitado'])`** y **escribir con
  `valorHabilitado($entrada)`** o con las constantes `HABILITADO` /
  `DESHABILITADO`. Viven en `lib/habilitado.php` de cada app —
  [panel/lib/habilitado.php](panel/lib/habilitado.php),
  [cloud/lib/habilitado.php](cloud/lib/habilitado.php),
  [app/lib/habilitado.php](app/lib/habilitado.php) — son copias idénticas
  porque las tres apps no comparten docroot.
- **Nunca comparar contra strings** (`=== '1'`, `in_array($h, ['S','1','Y'])`) ni
  bindear el booleano de PHP: PDO manda `false` como cadena vacía.
- **En SQL va el entero**: `WHERE habilitado = 1`, no `= '1'`. Y "no habilitado"
  es `= 0`, sin `COALESCE` ni `IS NULL`: la columna es `NOT NULL`.
- Una columna `habilitado` **nueva** se crea ya como `tinyint(1) NOT NULL
  DEFAULT 0`.

## `perfiles.tipo`: `A` y `O`, nada más

`perfiles`.`tipo` es `ENUM('A','O') NOT NULL DEFAULT 'O'`:

| valor | significado |
|---|---|
| `A` | Administrador |
| `O` | Operador |

Sin `NULL` y sin cadena vacía. Lo fijó
[cloud/sql/migrations/20260905_2300_perfiles_tipo_a_o.sql](cloud/sql/migrations/20260905_2300_perfiles_tipo_a_o.sql),
que llevó a `O` las 22 filas que no tenían ninguno de los dos valores (roles
internos de Reactor y el perfil centinela `id = 0`) por ser el menos
privilegiado — el mismo criterio con el que `habilitado` manda a `0` lo que no
reconoce.

- **Escribir con `PERFIL_TIPO_ADMINISTRADOR` / `PERFIL_TIPO_OPERADOR`** de
  [panel/lib/acceso.php](panel/lib/acceso.php), no con la letra suelta.
- **`tipo` NO es el control de acceso y NO está alineada con `rol`.** Hay 48
  perfiles con rol Administrador y `tipo = 'O'`, y 10 al revés. El panel gatea
  por `perfiles.rol`; el sistema legacy —fuera de este repo— lee `tipo`.
  **No derivar una de la otra**: sería repartir permisos, no normalizar datos.
- Si algún día se agrega un valor al `ENUM`, va **al final**: `ORDER BY tipo`
  ordena por el índice interno, no por el texto.
