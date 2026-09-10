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

### Las dos invitaciones: las dos crean Operadores

Son el **mismo circuito** (tabla `invitaciones`, mismo canal de correo por
Databox, misma pantalla de aceptación) emitido desde dos lados, y desde el
07/09/2026 **dan de alta exactamente lo mismo**. Lo único que cambia es dónde se
emite y quién puede hacerlo:

| | `panel/` | `app/` |
|---|---|---|
| dónde se emite | Usuarios → *+ Nuevo usuario* / Invitaciones | *Mi Dominio* → *Invitar un Usuario* |
| quién puede emitirla | cualquier perfil que entre al panel (`tipo = 'A'`) | el permiso `invitacion` |
| a dónde enlaza el correo | `panel.reactor.com.ar/invitacion/` | `app.reactor.com.ar/invitacion/` |
| `tipo` del perfil que crea | **`O` (Operador)** | **`O` (Operador)** |
| `operacion` / `invitacion` / `facturacion` | `1` / `1` / `0` | `1` / `1` / `0` |
| `registrante` del perfil que crea | `invitaciones.emisor` | `invitaciones.emisor` |
| `panel` del perfil que crea | panel de id más baja habilitado del dominio | ídem |

- **UNA INVITACIÓN DA DE ALTA UN OPERADOR, SE EMITA DONDE SE EMITA.** Hasta el
  07/09/2026 la del panel creaba `A`, con este argumento: al panel sólo entra un
  Administrador, así que una invitación emitida desde ahí sólo podía significar
  alta administrativa. Se cambió por decisión explícita. El argumento que queda
  en pie es el que ya valía para la app y ahora vale para las dos: **`tipo` la
  lee el sistema legacy —que sí reparte permisos con ella—, así que poner `A`
  desde una invitación le abre al invitado el back office viejo entero sin que
  nadie lo haya decidido.**
- **CONSECUENCIA: la invitación del panel ya no da acceso al panel.** Quien la
  acepta opera la app; si entra a `panel.reactor.com.ar` el login lo rebota con
  `motivo=perfil`, porque el gate sigue siendo `perfiles.tipo = 'A'`
  ([panel/lib/acceso.php](panel/lib/acceso.php)). **Ningún camino fabrica ya un
  Administrador sin que alguien lo decida a mano**: se otorga desde el alta de
  `cloud` (que elige el `tipo`) o editando el perfil en Usuarios. Y **si un
  dominio se queda sin ningún Administrador, el único desbloqueo es `cloud`** —
  el mismo patrón que ya rige para los tres permisos del perfil.
- **Las dos resuelven los mismos tres casos** al aceptar: sin cuenta → se crea
  `usuarios` + `perfiles`; con cuenta y sin perfil → sólo el perfil, sin tocarle
  contraseña ni dominio activo; con perfil habilitado en ese dominio → **no se
  hace nada** y la invitación se cierra igual.
- **El celular que completa el invitado son EXACTAMENTE 10 DÍGITOS Y NADA MÁS**
  (07/09/2026): sin espacios, guiones, paréntesis, puntos ni el signo `+`. Es el
  formato argentino sin el `0` de la característica y sin el `15` (`2644123456`),
  y es lo que tiene el **100%** de los datos — las 351 filas no vacías de
  `usuarios`.`celular` y las 33 de `invitaciones`.`celular`, ninguna con un
  caracter que no sea un dígito. Antes se admitía `+ ( ) - .` y espacios con un
  mínimo de 8 dígitos, así que un `+54 9 264 412-3456` quedaba escrito distinto
  de las otras 384 filas: **la columna es texto y nadie normaliza al leer**, así
  que dos formas del mismo número no se cruzan ni se buscan igual (el buscador de
  Invitaciones hace `LIKE` sobre la columna cruda). El largo sale de
  `CELULAR_DIGITOS`, declarada en cada `invitacion/aceptar.php` — copias
  idénticas, como todo lo que comparten las dos apps sin compartir docroot.
- **Lo que llega mal se rechaza, no se limpia en silencio.** El formulario le saca
  los separadores mientras se tipea (quitar un guion deja **el mismo** número),
  pero **nunca recorta**: cortar `+5492644123456` en el dígito 10 daría
  `5492644123`, un número que no es de nadie y que la persona no tendría cómo
  notar. El sobrante queda a la vista y lo corta `validarDatosInvitado()`
  diciendo cuántos dígitos van. **El filtro del navegador no es el control** —los
  dos formularios llevan `novalidate`, así que el `pattern` tampoco—: lo único
  que corre siempre es la validación del servidor, con `ctype_digit()` y no una
  expresión regular (`/^[0-9]+$/` da por buena una cadena terminada en salto de
  línea).
- **Ese tercer caso ya no se resuelve distinto en cada una**: las dos se quedan
  con **cualquier** perfil habilitado del dominio, cualquiera sea su `tipo`
  (`perfilAsegurado()` en [panel/invitacion/aceptar.php](panel/invitacion/aceptar.php),
  `appPerfilAsegurado()` en [app/lib/perfiles.php](app/lib/perfiles.php)). El
  panel buscaba uno **de Administrador** mientras creaba `A`; **seguir
  filtrando por `A` ahora que crea `O` le agregaría un segundo Operador idéntico
  a cada persona que ya tuviera el suyo**, una fila por invitación. Y el perfil
  que se reutiliza **no se reescribe**: bajar a Operador a quien ya era
  Administrador ahí le sacaría el panel —y el back office viejo, que lee la misma
  columna— desde una pantalla que nadie abrió para eso.
- **Las dos terminan mandando a la APP, y con la sesión ya abierta cuando la
  cuenta es nueva.** El botón `Ingresar` de la pantalla final va a
  `app.reactor.com.ar` en las dos — el del panel apuntaba a su propio
  `login.php`, que desde que la invitación crea Operadores sólo podía rebotar al
  invitado con `motivo=perfil`. Cómo se abre la sesión cambia según el host:
  - **`app/`** corre en el mismo dominio, así que llama a `appSesionAbrir()` —
    el mismo punto que el login por contraseña— y el botón entra directo a `/`.
  - **`panel/`** no puede escribir la cookie de otro host, así que emite un
    **enlace de un solo uso** en `enlaces_acceso` con `destino = 'app'` y el
    botón va a `app.reactor.com.ar/acceso?t=…`, que lo canjea
    [app/acceso.php](app/acceso.php) abriendo la sesión con la misma función. Es
    el circuito que ya existía para los enlaces mágicos de cloud. La alternativa
    —emitir la cookie sobre `.reactor.com.ar`— se la entregaría también a `cloud`
    y al panel.
- **La sesión SÓLO se abre para la cuenta recién creada, y no es una limitación
  técnica.** La credencial de esa pantalla es el `uuid` del enlace, y **ese uuid
  lo ve el emisor**: el listado de Invitaciones del panel lo muestra como
  `Identificador`. Si aceptar abriera sesión también sobre una cuenta que ya
  existía, cualquiera que pueda invitar tendría un secuestro de cuenta servido —
  invita al correo de una cuenta existente (que puede ser Administradora de otros
  dominios), copia el uuid de su propio listado, abre el enlace él mismo y entra
  como esa persona. Con la cuenta nueva no hay nada que secuestrar: la contraseña
  se acaba de generar y está impresa en esa misma pantalla. A quien ya tenía
  cuenta se le sigue pidiendo **su** contraseña, que es lo que la pantalla le
  dice.
- **Las dos le asignan al perfil nuevo todos los paneles habilitados del
  dominio.** No es un extra: `perfiles_paneles` no tiene fallback, así que sin
  esas filas la persona entra a una app vacía.
- **Y las dos le siembran `perfiles.panel`** —la columna singular, que es la
  *memoria* del último panel abierto— con el **panel de id más baja habilitado
  del dominio** (`panelInicialDelDominio()` en el panel,
  `appPanelInicialDelDominio()` en la app; 07/09/2026). Antes nacía en `NULL` y
  `app` resolvía el panel de arranque en cada conexión.
  **El criterio es el mismo `dominio = :d AND habilitado = 1` con el que se
  llena `perfiles_paneles`**, y ahí está la gracia: el panel sembrado siempre es
  uno de los permitidos, que es la invariante que `app` mantiene y que
  `olvidarPanelSinPermiso()` protege. Cambiar un criterio sin el otro hace nacer
  al perfil recordando un panel que no puede abrir.
  **Queda en `NULL` si el dominio no tiene ningún panel habilitado** — ese perfil
  tampoco recibe filas en `perfiles_paneles`, así que el `NULL` es el síntoma y
  no la causa. Nunca `0`: es FK contra `paneles`.
  **Ojo, no coincide necesariamente con lo que `app` elegiría sola**:
  `appPanelesDelDominio()` ordena **por nombre**. Se siembra por id porque es
  estable frente a un renombre.
- **Y las dos anotan al emisor como `registrante` del perfil** — ver la sección
  siguiente. En el tercer caso (ya tenía perfil) no se anota nada: no se creó
  ningún acceso.

## Las dos recuperaciones de contraseña: mismo circuito, dos logins

`panel/recuperar/` y `app/recuperar/` (esta última, del 10/09/2026) son el
**mismo circuito**: la tabla `recuperaciones`
([cloud/sql/migrations/20260905_2100_crear_recuperaciones.sql](cloud/sql/migrations/20260905_2100_crear_recuperaciones.sql)),
un enlace por correo de un solo uso, 32 bytes de CSPRNG de los que en la base
queda **sólo el SHA-256**, 60 minutos de vigencia en la columna `expira` y cupo
de 3 por cuenta / 10 por IP por hora. La lógica vive en el `lib/recuperacion.php`
de cada app — [panel/lib/recuperacion.php](panel/lib/recuperacion.php),
[app/lib/recuperacion.php](app/lib/recuperacion.php) —, copias adaptadas y no
idénticas, como todo lo que comparten las apps sin compartir docroot. **Las dos
reemplazan al legacy** (`reactor-app/sesion/recuperar.php`), que mandaba **la
contraseña** en el cuerpo del mail.

Las reglas de la sección "Recuperación de contraseña" de
[panel/CLAUDE.md](panel/CLAUDE.md) valen para las dos —respuesta neutra siempre,
`NOW()` de la base y nunca el reloj de PHP, transacción alta+envío, el candado
`usada IS NULL AND expira > NOW()`, los 36 caracteres del varchar(50)—. Lo que
cambia es de qué login cuelga cada una:

| | `panel/` | `app/` |
|---|---|---|
| de dónde se entra | `¿Olvidaste tu contraseña?` del login | botón **Recuperar contraseña** en `sesion/contrasena.php` |
| se busca por | `usuario` o `correo` | **`celular` o `correo`** |
| a dónde enlaza el correo | `panel.reactor.com.ar/recuperar/restablecer` | `app.reactor.com.ar/recuperar/restablecer` |
| al guardar la contraseña | manda al login | **abre la sesión y entra a la app** |

- **CADA UNA BUSCA LA CUENTA CON EL CRITERIO DE SU PROPIO LOGIN**, y no es un
  detalle: `panel/api/login.php` entra por `usuarios.usuario` y
  `app/sesion/iniciar.php` por `celular` O `correo` (el criterio de
  `cUsuario::usuario2id()`, ver la sección de ese archivo). Copiar el criterio
  del panel a la app haría que la recuperación cayera en una fila **distinta** de
  la que después resuelve el login: la persona le cambiaría la contraseña a una
  cuenta con la que no entra. Ninguna de las dos columnas tiene `UNIQUE`, así que
  las dos desempatan igual — la primera por id.
- **La de `app` abre la sesión al guardar, y eso NO contradice la regla de la
  invitación** ("la sesión SÓLO se abre para la cuenta recién creada"). Lo que
  esa regla protege es que el `uuid` de la invitación **lo ve el emisor** en el
  listado del panel, así que abrir sesión sobre una cuenta ajena sería un
  secuestro servido. Acá la credencial salió por correo **a la casilla de la
  propia cuenta** y en la base no queda ni siquiera guardada — sólo su hash —,
  así que nadie del sistema puede verla; y quien la tiene **acaba de elegir la
  contraseña**, con lo cual entraría igual tipeándola. La sesión no le da nada
  que no tenga.
- **Se abre con `appSesionAbrir()`, el mismo punto que el login por contraseña**,
  y por eso `app/` puede lo que `panel/` no: corre en el mismo host, así que
  escribe la cookie directo. El panel, para mandar a alguien a la app, tiene que
  emitir un enlace de un solo uso en `enlaces_acceso` (ver "Las dos
  invitaciones"). Si la cuenta se deshabilitó entre el enlace y el POST, la
  pantalla **cae sola al botón que manda al login**: la contraseña ya quedó
  guardada igual.
- **El correo es obligatorio y en `app/` eso muerde de verdad.** A la app se
  entra por celular, así que hay cuentas sin correo cargado y ésas **no pueden
  recuperar**: caen en la misma pantalla neutra que todo lo demás. No es un caso
  de borde a resolver acá — sin correo no hay a dónde mandar el enlace.
- **El campo viene precargado con lo que se tipeó en el paso 1 del login**, que
  sale de la cookie firmada del login pendiente (`appLoginPendiente()['ing']`) y
  **no de la querystring**: pasarlo por la URL publicaría el celular o el correo
  de la persona en la barra del navegador y en el historial.
- **La pantalla pide la contraseña dos veces y no usa el ojito** que sí tiene el
  modal de Mi Cuenta, por lo mismo que en el panel: se abre desde un enlace de
  correo (puede ser una máquina prestada) y un error de tipeo deja a la persona
  afuera de la cuenta que acaba de recuperar, con el enlace ya consumido.
- **Cambiar la contraseña NO cierra las otras sesiones abiertas.** El token de
  `app` es stateless y dura un año; no hay nada en él que se pueda invalidar
  desde la base. Es la misma limitación que ya documenta el panel.

## `perfiles.registrante`: quién otorgó el acceso

Columna de `perfiles` entre `panel` y `habilitado`, creada por
[cloud/sql/migrations/20260907_1100_perfiles_registrante.sql](cloud/sql/migrations/20260907_1100_perfiles_registrante.sql).
`int NULL` con FK a `usuarios`(`id`) `ON DELETE SET NULL`. Guarda **el id del
usuario que dio de alta ese perfil**, sea por invitación o a mano desde cloud.

- **Es el espejo de `usuarios`.`registrante`, no un duplicado.** Esa columna
  dice quién creó la **cuenta**; ésta, quién otorgó **este acceso**. Son dos
  altas distintas: una cuenta se crea una sola vez y después acumula perfiles en
  varios dominios, cada uno otorgado por alguien distinto y en otro momento.
- **Al aceptar una invitación se escriben las dos columnas, o sólo una:**

  | caso | `usuarios.registrante` | `perfiles.registrante` |
  |---|---|---|
  | la persona **no** tenía cuenta | emisor de la invitación | emisor de la invitación |
  | la persona **ya** tenía cuenta | *no se toca* | emisor de la invitación |
  | ya tenía perfil habilitado ahí | *no se toca* | *no se crea nada* |

  La cuenta que ya existía la registró otra persona, en otro momento y quizá en
  otro dominio: pisarle el dato sería reescribir un hecho. El acceso, en cambio,
  lo está otorgando esta invitación.
- **`NULL` es un valor válido y significa "no se sabe".** Lo tienen las 2.227
  filas que ya existían: la migración **no** las siembra a propósito —`perfiles`
  no guarda cuándo se creó la fila y `invitaciones` no apunta al perfil que
  produjo, así que el dato no se puede reconstruir sin inventarlo. Se llena de
  acá en adelante.
- **Se escribe con `?: null`, nunca con el entero pelado**: es una FK y el `0`
  del sistema histórico ya no es un valor válido. Mismo criterio con el que
  `usuarioAlta()` escribe `usuarios`.`registrante`.
- **La FK es `ON DELETE SET NULL` y no `RESTRICT`** como casi todo el esquema:
  apunta a quien *registró*, no a quien *pertenece*. Borrar un usuario ya obliga
  a borrar antes todos sus perfiles; sumarle los perfiles **ajenos** que alguna
  vez otorgó lo volvería impracticable.
- **Los tres caminos que crean perfiles ya la escriben**: el alta de cloud
  ([cloud/api/profiles.php](cloud/api/profiles.php)) con el usuario de la sesión
  —es el único alta *con* sesión—, y las dos invitaciones con
  `invitaciones.emisor`, que corren sin sesión.
