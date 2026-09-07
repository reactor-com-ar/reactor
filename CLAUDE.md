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

- **Escribir con `PERFIL_TIPO_ADMINISTRADOR` / `PERFIL_TIPO_OPERADOR`**, no con
  la letra suelta. Las declaran [panel/lib/acceso.php](panel/lib/acceso.php) y
  [app/lib/perfiles.php](app/lib/perfiles.php) — duplicadas, como todo lo que
  comparten las tres apps sin compartir docroot.
- **`tipo` NO es el control de acceso y NO está alineada con `rol`.** Hay 48
  perfiles con rol Administrador y `tipo = 'O'`, y 10 al revés. El panel gatea
  por `perfiles.rol`; el sistema legacy —fuera de este repo— lee `tipo`.
  **No derivar una de la otra**: sería repartir permisos, no normalizar datos.
- Si algún día se agrega un valor al `ENUM`, va **al final**: `ORDER BY tipo`
  ordena por el índice interno, no por el texto.

## Los tres permisos del perfil: `operacion`, `invitacion`, `facturacion`

Columnas de `perfiles` entre `tipo` y `panel`, creadas por
[cloud/sql/migrations/20260907_1000_perfiles_permisos_operacion_invitacion_facturacion.sql](cloud/sql/migrations/20260907_1000_perfiles_permisos_operacion_invitacion_facturacion.sql).
**Son banderas `habilitado` aunque no se llamen así** — `tinyint(1) NOT NULL
DEFAULT 0`, dos valores y nada más — así que valen para ellas todas las reglas
de la sección anterior: se leen con `esHabilitado()`, se escriben con
`valorHabilitado()`, en SQL va el entero y "sin permiso" es `= 0`.

| permiso | dónde vale | qué abre |
|---|---|---|
| `operacion` | `app.reactor.com.ar` | Ver y usar los paneles de operación (los controles del dominio). |
| `invitacion` | `app.reactor.com.ar` | El ítem *Invitar un Usuario*. |
| `facturacion` | `panel.reactor.com.ar` | El agrupador *Cuenta* entero (Facturas / Recibos / Facturación). |

- **El catálogo vive en `lib/permisos.php`** de cada app —
  [panel/lib/permisos.php](panel/lib/permisos.php),
  [cloud/lib/permisos.php](cloud/lib/permisos.php),
  [app/lib/permisos.php](app/lib/permisos.php) — son copias idénticas, igual que
  `habilitado.php`, porque las tres apps no comparten docroot. `perfilPermisos()`
  convierte una fila de `perfiles` en los tres booleanos; `perfilSinPermisos()`
  los deja en `false`, que es lo que corresponde cuando no hay perfil.
- **Son permisos DEL PERFIL, no de la cuenta.** La misma persona puede tener
  facturación en un dominio y no tenerla en otro: decide el perfil con el que la
  sesión está parada, igual que `habilitado`.
- **No se derivan de `tipo` ni lo reemplazan.** `tipo` es lo que lee el legacy y
  lo que gatea la *entrada* al panel; estos tres gatean pantallas concretas una
  vez adentro. Un Administrador puede no tener facturación y un Operador puede
  tener operación — es el caso normal. Adivinar un permiso mirando `tipo` es el
  mismo error que ya documenta la sección anterior sobre `tipo` y el difunto
  `rol`.
- **Esconder el botón no es el control de acceso.** Cada permiso se aplica en
  las dos capas: la UI no dibuja lo que no corresponde y el endpoint corta igual
  (`requirePermisoPanel()` en [panel/lib/acceso.php](panel/lib/acceso.php),
  `appPuede()` en [app/lib/contexto.php](app/lib/contexto.php)). Se resuelven
  **contra la base en cada request**, nunca contra un claim del JWT: revocar un
  permiso tiene efecto en el request siguiente y no al vencer el token.
- **Ninguno de los tres se otorga sin tenerlo** — pero sólo en `panel`
  ([panel/api/usuarios.php](panel/api/usuarios.php)), donde quien edita es un
  cliente administrando su propio dominio. En `cloud` no hay esa restricción:
  ahí quien edita es Reactor sobre el sistema entero, y es el único desbloqueo
  cuando un dominio se queda sin nadie que pueda repartir un permiso. Hasta el
  07/09/2026 la regla valía sólo para `facturacion`; los otros dos quedaban
  libres por ser de `app`, y eso no es una diferencia: repartir lo que uno no
  tiene es la misma escalada, se ejerza en la pantalla que se ejerza.
  **En `panel` el permiso que la sesión no tiene ni siquiera se dibuja** — el
  modal de editar perfil lo omite en vez de mostrarlo bloqueado —, y el endpoint
  corta con 403 igual: la UI no es el control de acceso.
  Por lo mismo el perfil que crea `panel/invitacion/aceptar.php` nace con
  `facturacion = 0`: ese alta corre sin sesión, y con `1` cualquier administrador
  tendría el camino servido para fabricarse el permiso.
- **Quien crea un perfil tiene que darle permisos**, igual que con
  `perfiles_paneles`: el default de la columna es `0` y un perfil sin
  `operacion` no puede usar la app. Los **tres** caminos que los crean ya lo
  hacen: el alta de cloud pre-tilda `operacion` e `invitación`, y las dos
  invitaciones —la del panel y la de la app— los insertan en `1` con
  `facturacion` en `0`.

### Las dos invitaciones: la del panel crea Administradores, la de la app Operadores

Son el **mismo circuito** (tabla `invitaciones`, mismo canal de correo por
Databox, misma pantalla de aceptación) emitido desde dos lados, y lo único que
cambia es a quién dan de alta:

| | `panel/` | `app/` |
|---|---|---|
| dónde se emite | Usuarios → *+ Nuevo usuario* / Invitaciones | *Mi Dominio* → *Invitar un Usuario* |
| quién puede emitirla | cualquier perfil que entre al panel (`tipo = 'A'`) | el permiso `invitacion` |
| a dónde enlaza el correo | `panel.reactor.com.ar/invitacion/` | `app.reactor.com.ar/invitacion/` |
| `tipo` del perfil que crea | `A` (Administrador) | **`O` (Operador)** |
| `operacion` / `invitacion` / `facturacion` | `1` / `1` / `0` | `1` / `1` / `0` |

- **El `tipo` no es simetría rota, es la razón de ser de cada una.** Al panel
  sólo entra un Administrador, así que su invitación sólo puede significar alta
  administrativa; la de la app significa "sumate a operar los equipos", y ahí el
  Administrador no hace falta. Y como `tipo` la lee el sistema legacy —que sí
  reparte permisos con ella—, poner `A` desde la app le abriría al invitado el
  back office viejo entero sin que nadie lo decidiera.
- **Las dos resuelven los mismos tres casos** al aceptar: sin cuenta → se crea
  `usuarios` + `perfiles`; con cuenta y sin perfil → sólo el perfil, sin tocarle
  contraseña ni dominio activo; con perfil habilitado en ese dominio → **no se
  hace nada** y la invitación se cierra igual.
- La diferencia fina está en ese tercer caso: el panel busca un perfil **de
  Administrador** y agrega uno si el que hay es Operador (porque el suyo es el
  `tipo` que abre la puerta), y la app se queda con **cualquier** perfil
  habilitado que encuentre (`appPerfilAsegurado()` en
  [app/lib/perfiles.php](app/lib/perfiles.php)) — reescribirlo le cambiaría el
  acceso en el panel y en el legacy desde una pantalla que no administra nada.
- **Las dos le asignan al perfil nuevo todos los paneles habilitados del
  dominio.** No es un extra: `perfiles_paneles` no tiene fallback, así que sin
  esas filas la persona entra a una app vacía.
