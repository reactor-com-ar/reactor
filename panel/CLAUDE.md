# panel

BackOffice administrativo de Reactor. Vive junto a `cloud/` en el mismo repo y
comparte con él la infraestructura de auth: tabla `usuarios`, cifrado legacy
(`api/legacy_crypto.php`, clave global `0123456789`), APP_KEY_CLOUD y la cookie
`reactor_cloud_token`. Un usuario logueado en cloud queda logueado en panel
sin re-ingresar (siempre que compartan dominio raíz o corran sobre el mismo
host en dev).

## Dominios

- Prod: `panel.reactor.com.ar` (nginx proxea al puerto 8087 del contenedor
  `reactor-apache`). **Es el único punto de entrada válido a futuro.**
- Prod, **temporal**: `control.reactor.com.ar` **no sirve el panel: redirige**
  con un `301` a `panel.reactor.com.ar` conservando path y query string. Nunca
  llega a Apache — lo resuelve nginx. Existe sólo para la transición desde el
  legacy y se elimina cuando termine, sacándolo de `PANEL_DOMAIN_ALIASES` en
  [scripts/aprovisionar_server.sh](../scripts/aprovisionar_server.sh) (que lo
  usa para el bloque de redirect y para el certificado) y reemitiendo el cert
  sin ese `-d`.
  El redirect va dentro de `location /` y **no** a nivel `server`: nginx evalúa
  los `return` del contexto server antes de elegir el `location`, así que un
  `return` suelto redirigiría también el desafío ACME y voltearía la renovación
  del certificado — que es el mismo para los 7 dominios.
- Dev: `http://localhost:8087`.

**No acoplar nada a `control.`**, justamente porque se va: la cookie de sesión
es host-only a propósito (no se amplió a `.reactor.com.ar`, así que la sesión
no se comparte entre los dos dominios) y los correos de invitación enlazan
siempre a `panel.` — ver `panelBaseUrl()` en
[lib/invitaciones.php](lib/invitaciones.php), que fija el dominio en producción
en vez de derivarlo del `Host`.

## Reglas de shell (obligatorias — skill `crear_backoffice`)

- **Layout en tres zonas**: sidebar 220px a la izquierda + topbar 60px arriba
  + `.content` con padding 24px y scroll vertical propio.
- **Chrome rojo institucional (#C11313, `--primary`)**: sidebar y topbar
  pintados sólidos en `var(--primary)`. Los hijos usan `#fff` y opacidades de
  blanco, **no** `--text` / `--muted` / `--border`.
- **Cabecera del sidebar = sólo logo**, centrado a 24px de alto. Sin texto
  "Reactor / Panel" adjunto.
- **SIN indicador de versión al pie del sidebar.** No agregar
  `.sidebar-footer` con versión ni ninguna variante — regla dura del skill.
  La versión vive en `version.txt` (cache-bust) y, si algún día se necesita
  exponerla, en una herramienta interna del módulo Administración.
- **Sidebar con sólo dos niveles**: categoría (`.nav-group-wrap`) →
  sub-ítem (`.nav-sub-item`). Ícono obligatorio en ambos niveles.
- **El menú arranca completamente desplegado**: cada `.nav-group-wrap` del
  markup lleva `open`. Al agregar una categoría nueva, agregarle `open`
  también. Colapsar es una acción del usuario y no se persiste.
- **La categoría es un rótulo, no un ítem** (jerarquía portada de
  `cas/admin`): `.nav-group-toggle` va en `.7rem`, `font-weight: 800`,
  mayúscula y `rgba(255,255,255,.6)`; **sin fondo en hover** (sólo aclara a
  `#fff`). El peso visual del menú vive en los sub-ítems, que son lo único
  navegable.
- **`.nav-sub` sin fondo ni bordes propios**: el bloque de sub-ítems se apoya
  directo sobre el rojo del chrome. La jerarquía la marca la sangría de
  44px, no un recuadro oscuro.
- **Hover y activo del menú en blanco translúcido**, no en negro:
  `rgba(255,255,255,.10)` y `rgba(255,255,255,.18)`. Sobre rojo el negro
  ensucia y engorda el menú; el blanco lo aclara (es el análogo de
  `--primary-soft` de `cas/admin`, invertido para fondo rojo).
- **El realce es una pastilla, no una franja de lado a lado**: `.nav-item`
  lleva `margin: 2px 10px` + `border-radius: 10px`, así el fondo queda
  despegado 10px de los dos bordes del sidebar. El padding horizontal (13px)
  compensa el margen para que el texto no se mueva, y `.nav-sub-item` usa
  `padding-left: 34px` para conservar la sangría de 44px. El activo **no**
  lleva `border-left` blanco: con la pastilla despegada la barra quedaba
  cortada por el radio; lo marcan el fondo más fuerte y `font-weight: 600`.
  Por lo mismo `.nav-group-toggle` va con `width: auto` (un `100%` sumado a
  los márgenes desbordaría el sidebar).
- **Íconos del sidebar en FontAwesome solid, no emojis.** Cada nivel lleva
  `<i class="fa-solid fa-<nombre> nav-icon"></i>`. Esto se aparta del skill
  `crear_backoffice` (que pide emoji) por decisión explícita del proyecto:
  el emoji renderiza distinto en cada SO y no hereda el color del chrome.
  Al agregar un módulo, elegir el ícono de `assets/fontawesome/icons.json`
  y verificar que exista en solid (`"c"` contiene `s`).
- **Topbar con una sola acción**: el botón de usuario arriba a la derecha.
  Ninguna acción global adicional en la topbar — todo lo demás vive dentro
  de cada pantalla. El dropdown de ese botón es el único lugar donde viven
  las acciones de la cuenta, en este orden: **Cambiar dominio** (ícono
  `fa-recycle`, abre el modal con los dominios disponibles), **Mi cuenta**
  (modal de la ficha del usuario) y **Cerrar sesión**.
- **Tema único oscuro**. No hay modo claro, no hay toggle, no hay
  `data-theme`.
- **Un solo archivo CSS**: `assets/css/style.css`. No fragmentar.
- **Sin build step, sin librerías UI pesadas**. Sólo FontAwesome (icon set) y
  CSS/JS vanilla.
- **FontAwesome autohospedado**, no CDN: el paquete Pro 6.5.1 vive en
  `assets/fontawesome/` y `index.php` / `login.php` enlazan `all.min.css` +
  las cuatro hojas `sharp-*` desde ahí. Cache-bust propio por `filemtime`,
  independiente de `version.txt`. Ver `assets/fontawesome/README.md`.

## Modales: formato único (obligatorio — skill `abm_design`)

**Todos** los modales del panel salen de `openModal()` en `assets/js/app.js`, y
todos tienen la misma forma: **barra de título pintada en `--primary`** y más
baja (`.modal-header-primary`, `padding: 10px 24px`), **barra de acciones
debajo** con todos los botones (`.modal-menubar`) y **sin footer**.

- **La salida va primera y es el único botón neutro** (`btn-ghost`); el resto va
  en `btn-primary`. El rótulo lo hereda de lo que hacía en el footer: `Cerrar`
  en los modales de consulta, `Cancelar` en formularios, filtros y
  confirmaciones.
- **Los call sites siguen pasando `footerHtml` / `primaryHtml`** — los nombres
  quedaron por compatibilidad, pero su contenido va a la barra. **No hace falta
  agregarles `btn-sm`**: la barra normaliza el tamaño por CSS, así ningún call
  site se olvida y todos los botones quedan del mismo alto.
- **Las acciones extra van en un desplegable `Acciones`** que abre el
  `openRowMenu()` flotante (`position: fixed`), no un dropdown absoluto: el
  `.modal` lleva `overflow: hidden` y recortaría cualquier hijo absoluto. El
  trigger tiene que llamar a `stopPropagation()` o el handler global lo cierra
  en el mismo click que lo abre. **Un menú de un solo ítem no se justifica**: si
  la ficha tiene una acción sola, va directa a la barra (es el caso de `Editar`
  en Consultar dispositivo).
- **La única excepción es `confirmarBaja()`**, que pasa `confirmacion: true` y
  conserva la forma vieja —cabecera neutra y los dos botones abajo—: una
  confirmación es una pregunta, no una pantalla, y su botón rojo se queda lejos
  de la salida a propósito.
- **Sobre el primario los hijos van en `#fff`** y opacidades de blanco, nunca
  `--text` / `--muted` / `--border`. Es la misma regla del sidebar y la topbar.
- El scroll sigue siendo **del cuerpo**, nunca del modal entero: la barra de
  título y la de acciones quedan fijas (ya estaba así en el panel desde el
  arranque; `cloud/` se alineó después).

## Bump de version.txt

Al tocar cualquier archivo bajo `panel/assets/css/` o `panel/assets/js/` hay
que incrementar `panel/version.txt` o el browser sirve caché vieja
(los assets se cargan con `?v=<contenido de version.txt>`).

## La bandera `habilitado` (obligatorio — regla de todo el repo)

`habilitado` tiene **dos valores y nada más**: `1` habilitado, `0`
deshabilitado. Vale para *toda* columna con ese nombre — `perfiles`,
`usuarios`, `dominios`, `dispositivos`, `canales`, `botones`, `controles`,
`paneles`... — y para las tres apps que comparten la base. La regla completa
está en el [CLAUDE.md raíz](../CLAUDE.md); acá van las consecuencias para el
panel.

- **El criterio único vive en [lib/habilitado.php](lib/habilitado.php)**:
  constantes `HABILITADO` / `DESHABILITADO` y las funciones `esHabilitado()`
  (leer) y `valorHabilitado()` (escribir). Llega a todo endpoint por
  `api/bootstrap.php` → `lib/acceso.php`; las páginas públicas lo requieren por
  su cuenta (`lib/recuperacion.php`, `lib/usuarios_alta.php`).
- **No comparar contra strings ni bindear booleanos.** Antes cada módulo se
  defendía con su propio criterio —`in_array($h, ['S','1','Y'])` en el login,
  `COALESCE($h, '') <> '1'` en el listado de perfiles, `$h IS NULL OR $h <> 1`
  en dispositivos— y en los bordes no coincidían: la misma fila se veía
  habilitada en una pantalla y deshabilitada en otra. Y PDO bindea el `false`
  de PHP como **cadena vacía**, que contra la columna vieja (`varchar(1)`)
  quedaba escrita en la base como un tercer valor.
- **"No habilitado" es `= 0`**, no `<> 1` ni `IS NULL`: la columna es `NOT NULL`
  desde `20260905_2200_habilitado_tinyint_0_1.sql`. Los `COALESCE` defensivos
  se sacaron a propósito.
- **`perfiles.habilitado` y `usuarios.habilitado` ya no se codifican distinto**,
  pero **siguen significando cosas distintas**: el del perfil es el acceso a
  *este* dominio y el de la cuenta es la cuenta entera. Ver el módulo Usuarios.

## `perfiles.tipo`: sólo `A` y `O` (obligatorio)

`ENUM('A','O') NOT NULL DEFAULT 'O'` desde
`20260905_2300_perfiles_tipo_a_o.sql` — `A` Administrador, `O` Operador. Ni
`NULL` ni cadena vacía: las 22 filas que estaban así (roles internos de Reactor
y el perfil centinela `id = 0`) quedaron en `O`, el menos privilegiado, con el
mismo criterio que `habilitado`.

- **Se escribe con `PERFIL_TIPO_ADMINISTRADOR` / `PERFIL_TIPO_OPERADOR`** de
  [lib/acceso.php](lib/acceso.php). Hoy el único que la escribe es
  `invitacion/aceptar.php`.
- **NO es el gate de acceso, y hoy no gatea nada.** Antes no lo era porque el
  gate era `perfiles.rol`; desde que esa columna se eliminó (06/09/2026) el
  panel no filtra por privilegio en absoluto — ver la sección siguiente. `tipo`
  se conserva porque **la lee el sistema legacy**, y ahí sí decide: por eso
  `invitacion/aceptar.php` la sigue escribiendo en `A`.
- **Es el único rastro que queda de la distinción Administrador / Operador**
  dentro de `perfiles`, y por eso ocupó el lugar de `Rol` en las fichas de
  panel y de cloud. Que se muestre no significa que reparta permisos.

## Acceso: sólo Administradores (obligatorio)

**Al panel entra únicamente una cuenta con un perfil de Administrador habilitado
en el dominio activo.** El Operador queda afuera, y no es un caso de borde: es el
perfil más común por lejos. La regla vive entera en
[lib/acceso.php](lib/acceso.php) y todo lo demás la consume desde ahí.

**EL CRITERIO ES `perfiles.tipo = 'A'`, y es el único que queda.** Hasta el
06/09/2026 era `perfiles.rol IN (101)` — y era el bueno, porque las dos columnas
estaban desalineadas en los datos. Ese mismo día `rol` se eliminó
(`20260906_1500_perfiles_sin_rol.sql`) y el panel quedó **sin gate** por un rato:
entraba cualquier perfil habilitado. `tipo` es lo que sobrevivió de esa
distinción, así que es por donde se gatea ahora.

Medido al restaurar el gate:

| | sin gate | con `tipo = 'A'` |
|---|---|---|
| perfiles habilitados que entran | 2.063 | **404** |
| cuentas distintas | 1.958 | **356** |

**Un dominio se queda sin nadie que lo administre:** `Camino al Puente Viejo`
(#160) tiene 33 perfiles habilitados y los 33 son Operador. Hay que ponerle
`tipo = 'A'` a alguno o nadie va a poder entrar a administrarlo.

**Lo que sigue en pie:**

- **El perfil tiene que estar habilitado.** Revocar un acceso sigue siendo poner
  `perfiles.habilitado = 0`, y tiene efecto en el request siguiente.
- **El perfil tiene que ser de esa cuenta y de ese dominio.** La comparación de
  dominio no es redundante: hay 13 cuentas cuyo `usuarios.perfil` apunta a un
  perfil de un dominio **distinto** del de `usuarios.dominio`, y sin compararlo
  entrarían a operar sobre un dominio que no es el suyo.
- **El gate se resuelve contra la base en cada request, nunca contra un claim
  del JWT.** El token dura 12 h y lo puede haber emitido `cloud/` (que comparte
  la cookie), así que bajar un perfil a Operador o deshabilitarlo tiene efecto en
  el request siguiente y no al vencer el token.
- **Dónde se aplica**: `api/bootstrap.php` (todo endpoint que no declare
  `PANEL_API_PUBLIC`), `index.php` (el shell), `login.php`, `api/login.php` y
  `acceso.php` (el canje de un enlace mágico). El gate va en el bootstrap y **no**
  endpoint por endpoint para que un módulo nuevo nazca cerrado.
- **El login elige el perfil de arranque.** `usuarios.perfil` es el último que la
  persona usó *en cualquiera* de los sistemas que comparten `usuarios`, así que
  puede ser un Operador o un perfil de otro dominio. Si el que trae no sirve,
  `api/login.php` toma el primero de `perfilesHabilitados()` y lo asienta con
  `panelPerfilActivoAsentar()`. **Sin esto la sesión nace denegada y el login
  rebota para siempre.**
- **El rechazo se ve, no se traga.** `api/login.php` corta con 403 *después* de
  validar la contraseña, para que el mensaje distinga "no sos vos" de "no tenés
  permiso". Para una pantalla, `requirePerfilValido()` redirige a
  `/login?motivo=perfil` y `login.php` muestra el aviso en la tarjeta.
- **La API devuelve 403 con `motivo: 'perfil'`**, no un 403 pelado. Es lo que le
  permite a `app.js` distinguir este caso —la sesión ya no sirve, hay que volver
  al login— de los 403 de negocio.

**Los tres caminos que otorgan acceso tienen que respetar el gate**, y los tres
lo hacen:

- `invitacion/aceptar.php` crea el perfil con `tipo = 'A'`, y **busca uno de
  Administrador** para reutilizar: si la persona ya tenía uno de Operador ahí
  (porque usa `reactor-app`), ese no habilita el panel y se le agrega uno nuevo.
- `cloud/api/enlaces_acceso.php` **no emite** un enlace de panel para quien no
  tenga un perfil de Administrador habilitado: devuelve 409 en vez de entregar
  algo que va a rebotar.
- `acceso.php` **revalida** al canjear, aunque cloud ya lo haya hecho: entre la
  emisión y el uso pasan hasta 15 minutos y el perfil se pudo bajar a Operador.

**Nombres de las funciones**: `perfilesHabilitados()`, `esPerfilValido()`,
`sessionTienePerfilValido()`, `requirePerfilValido()` y
`PANEL_MENSAJE_SIN_PERFIL`. Son genéricos porque el criterio ya cambió una vez
(de `rol` a `tipo`) y puede volver a cambiar; lo que no cambia es su contrato —
*"¿este perfil habilita el panel?"*.

## Permisos del perfil: el segundo escalón (obligatorio)

El gate de arriba dice si la cuenta **entra**. Los tres permisos de `perfiles`
—`operacion`, `invitacion`, `facturacion`, columnas desde el 07/09/2026— dicen
qué ve **una vez adentro**. La regla completa y el catálogo compartido con las
otras dos apps están en el [CLAUDE.md raíz](../CLAUDE.md) y en
[lib/permisos.php](lib/permisos.php); acá van las consecuencias para el panel.

- **Del panel gatea uno solo: `facturacion` → el agrupador `Cuenta` entero**
  (Facturas / Recibos / Facturación). `operacion` e `invitacion` son de `app`;
  viajan igual porque el catálogo es único y porque el panel es donde se
  administran.
- **Se aplica en las dos capas.** `index.php` omite el agrupador completo del
  sidebar —no cada ítem: una categoría vacía se lee como un menú roto— y
  `app.js` **borra las tres rutas del router**, así el hash pegado a mano cae en
  Dashboard. El corte real es `requirePermisoPanel('facturacion')` al tope de
  [api/comprobantes.php](api/comprobantes.php) y
  [api/facturacion.php](api/facturacion.php).
- **El 403 lleva `motivo: 'permiso'`, no `'perfil'`**, y la diferencia importa:
  `'perfil'` significa *"la sesión ya no sirve, volvé al login"* y el front actúa
  en consecuencia; `'permiso'` significa *"la sesión está bien, esta pantalla no
  es tuya"* y volver al login no arreglaría nada. Mandarlos por el mismo camino
  echaría de la sesión a quien no tiene facturación cada vez que toca Facturas.
- **`panelPermisosDeSesion()` repite las cuatro condiciones del gate** (perfil
  habilitado, de esa cuenta, de ese dominio y `tipo = 'A'`) en vez de buscar el
  perfil por id: un perfil que no pasa el gate no tiene permisos que informar, y
  leerlos igual dejaría una segunda definición de *"el perfil de la sesión"* que
  podría discrepar de la primera. Cacheado por request, como `sessionContext()`.
- **`sesion.permisos` viaja inyectado en `index.php`** junto al resto del
  contexto, y el front lo lee con `puede(<permiso>)`. Si el tag no vino, los tres
  quedan en `false`: sin dato no hay permiso.
- **`invitacion/aceptar.php` crea el perfil con `facturacion = 0`.** Ese alta
  corre **sin sesión** —la credencial es el uuid del enlace— así que con `1`
  cualquier administrador sin facturación tendría el camino servido: invita a una
  cuenta que controle, la acepta, y ya hay un perfil con el permiso en el
  dominio. El corte del `PUT` quedaría en decoración. `operacion` e `invitacion`
  sí nacen en `1`.


## Contexto de sesión y filtrado por dominio (obligatorio)

Al iniciar sesión (`api/login.php`) se capturan de la cuenta los datos de
alcance y se guardan como claims del JWT: `dominio` (columna
`usuarios.dominio` **en ese momento**), `dominio_nombre`, `perfil`,
`perfil_nombre` y `roles`.

- `lib/sesion.php` expone `sessionContext()`, `sessionDominioId()` y
  `requireDominioId()`. Los endpoints usan **`requireDominioId()`** y
  filtran **todos** sus queries por ese dominio — incluido el lookup por
  id, para que nadie lea ni escriba un registro de otro dominio pasando
  un id a mano.
- Un token emitido por `cloud/` no trae estos claims (cloud no los firma).
  `sessionContext()` cae a la base y los resuelve desde `usuarios`; el
  campo `origen` dice si vinieron del token (`token`) o de la BD (`db`).
- El front recibe el contexto inyectado por `index.php` en
  `<script id="panel-sesion">` y lo lee en `app.js` como `sesion`. Es sólo
  para mostrar (ej. de qué dominio es el listado) — **el filtro real
  siempre lo aplica el backend**.
- `dominio = null` significa "cuenta sin dominio asignado", **no** "ver
  todo": `requireDominioId()` corta con 409.

## Alta de usuarios (canal único — obligatorio)

Todo `INSERT` sobre `usuarios` del panel pasa por **`usuarioAlta()`** en
[lib/usuarios_alta.php](lib/usuarios_alta.php). Ningún archivo arma el INSERT por
su cuenta. Hoy lo usa **el único camino de alta que queda**:

- `invitacion/aceptar.php` (alta al aceptar una invitación)

*(Hubo un segundo, `api/usuarios.php` → `handleCreate()`, el alta manual del
BackOffice. Se eliminó al mudar el módulo Usuarios a `perfiles` el 05/09/2026:
un alta de `usuarios` dentro del endpoint que administra `perfiles` sólo podía
confundir. La función se conserva igual porque el día que vuelva un alta, entra
por acá.)*

Reglas que la función garantiza, y que por eso no hay que repetir en los llamadores:

- Recibe la contraseña **en claro** y la cifra adentro con el cifrado legacy
  (`api/legacy_crypto.php`). Ningún camino elige su propio cifrado.
- **Forma fija del alta.** Todo usuario nace con estos valores, sin importar lo
  que mande el llamador:

  | columna | valor |
  |---|---|
  | `autenticacion` | `'F'` |
  | `habilitado` | `1` (`HABILITADO`) |
  | `perfiles` | `0` |
  | `dominios` | `''` |
  | `paneles` | `''` |
  | `panel` | `NULL` |

  Están declarados como `USUARIO_*_INICIAL` en el mismo archivo. Las plurales
  **no** son el par de las singulares: `perfil` y `dominio` sí reciben el id real
  que se les pase. `roles` arranca en `''` salvo que el llamador mande otro valor.
- Resto de los defaults: `uuid` aleatorio y `registrado = NOW()`.
- **Consecuencia:** el campo *habilitado* del formulario no tiene efecto al crear
  (siempre nace `1`); recién se respeta al editar.
- Cuando el perfil se asigna **después** del alta (caso invitación: `perfiles.usuario`
  exige que el usuario ya exista), se usa `usuarioPerfilActivo()`. Toca sólo
  `perfil`; `perfiles` se queda en 0. No hacer el `UPDATE` a mano.

Si el alta necesita una columna nueva, se agrega en la función y la reciben los dos
caminos. Cloud tiene su propio canal equivalente en `cloud/lib/usuarios_alta.php`
(son separados porque no comparten docroot).

## Páginas públicas (sin sesión)

Son las únicas pantallas que se sirven sin JWT. Hoy hay dos familias —
`invitacion/` (ver / aceptar / rechazar) y `recuperar/` (pedir enlace /
restablecer)— y **todas comparten el shell de
[lib/publico.php](lib/publico.php)**: la tarjeta roja del login, el
`X-Robots-Tag: noindex` y el `Cache-Control: no-store`. No pasan por
`api/bootstrap.php`, así que **tampoco pasan por el único
`date_default_timezone_set()` del panel** — ver la regla del reloj más abajo.

- **Las rutas de assets del shell son `../`**, o sea que sirve para páginas
  ubicadas **un nivel** bajo el docroot. Una más profunda tendría que pasar su
  propio prefijo.
- **Las clases CSS conservan el prefijo `inv-`** (`.inv-card`, `.inv-lead`,
  `.inv-note`, `.inv-dato`, `.inv-acciones`) aunque ya no sean sólo de
  invitaciones: son las de la tarjeta pública en general. Renombrarlas es
  tocar cuatro pantallas para nada. CSS §19.
- `invitacion/_layout.php` quedó como **alias** de las funciones `publico*`
  con los nombres históricos, para no tocar las tres pantallas que ya los
  usaban.
- `panelBaseUrl()` se mudó a [lib/base_url.php](lib/base_url.php): la usan dos
  circuitos que no tienen nada que ver entre sí (el enlace de invitación y el
  de recuperación) y los dos necesitan la misma regla de host fijo en
  producción.

### Recuperación de contraseña (`recuperar/`)

Enlace de un solo uso por correo. El login lleva a `recuperar/` con el link
`¿Olvidaste tu contraseña?` (`.login-alt`, CSS §18), la persona pide el enlace
en `recuperar/index.php` y elige la contraseña nueva en
`recuperar/restablecer.php?t=<token>`. La lógica vive en
[lib/recuperacion.php](lib/recuperacion.php) y los pedidos en la tabla
`recuperaciones` (migración
`cloud/sql/migrations/20260905_2100_crear_recuperaciones.sql`).

**Reemplaza al legacy, no lo copia.** `reactor-app/sesion/recuperar.php`
mandaba **la contraseña** en el cuerpo del mail —puede hacerlo porque
`usuarios.contrasena` es cifrado reversible, no hash—. Acá viaja un enlace: la
contraseña vieja no sale por correo, el enlace vence y se puede invalidar. El
legacy no se toca y sigue funcionando sobre la misma tabla `usuarios`.

Reglas que no se deducen del esquema:

- **En la base se guarda el SHA-256 del token, no el token.** Lo que viaja en
  el enlace son 32 bytes de CSPRNG en base64url (43 chars) y no queda escrito
  en ningún lado: quien lea la base no puede armar un enlace válido. Sin salt
  ni algoritmo lento a propósito — son 256 bits aleatorios, no una contraseña:
  no hay diccionario que atacar.
- **Todas las fechas se comparan contra `NOW()` de la base, nunca contra un
  reloj de PHP.** Es el mismo drift documentado en los gráficos de señal (PHP
  en UTC, la sesión de MySQL en -03:00) y acá pega más fuerte: estas páginas no
  pasan por `api/bootstrap.php`, que es lo único que fija la zona horaria de
  PHP en el panel. Con el reloj equivocado un enlace de 60 minutos nace vencido
  o dura cuatro horas. Por eso `expira` se calcula con `DATE_ADD(NOW(), ...)`,
  la vigencia se resuelve en el `SELECT` (`r.expira > NOW() AS vigente`) y el
  cupo se cuenta con `DATE_SUB(NOW(), INTERVAL 1 HOUR)`.
- **`expira` es una columna y no un cálculo sobre `solicitada`**: el TTL puede
  cambiar y los enlaces ya emitidos tienen que conservar el suyo.
- **La respuesta del formulario es siempre la misma**, exista o no la cuenta.
  Los cuatro caminos que no mandan nada —cuenta inexistente, deshabilitada, sin
  correo cargado y cupo agotado— terminan en la misma pantalla que el envío
  exitoso: es un formulario público de un BackOffice y distinguirlos lo
  convertiría en un verificador de usuarios y correos del sistema.
- **La única excepción es que se caiga el microservicio de correo**, y ahí sí
  se muestra el error. Callar una falla de infraestructura deja a la persona
  esperando un mail que nunca va a llegar; la pista que da sobre la existencia
  de la cuenta sólo aparece durante una caída y no vale ese precio.
- **La fila y el envío van en una transacción**, igual que el alta de
  invitaciones: un token que nadie recibió no le sirve a nadie y además consume
  cupo.
- **Cupo de la última hora: 3 por cuenta y 10 por IP.** El de cuenta es el que
  de verdad protege a alguien de recibir veinte correos; el de IP es flojo
  a propósito porque en producción nginx proxea al contenedor y `REMOTE_ADDR`
  puede ser la del proxy para todos. **Se lee `REMOTE_ADDR` y no
  `X-Forwarded-For`**: ese header lo pone el cliente y falsearlo saltearía el
  cupo.
- **Usar un enlace cierra todos los pedidos abiertos de esa cuenta**, no sólo
  el que se usó: si alguien pidió tres, los otros dos dejan de servir.
- **El `UPDATE` de consumo lleva `usada IS NULL AND expira > NOW()` en el
  `WHERE`** — es el candado contra el doble envío (dos pestañas, un reintento
  del navegador). Si no afecta ninguna fila, no se toca la contraseña.
- **El formulario pide la contraseña dos veces y no usa el ojito** que sí tiene
  `app/`. La pantalla se abre desde un enlace de correo, así que puede terminar
  en una máquina prestada; y si se tipea mal, la persona queda afuera de la
  cuenta que acaba de recuperar con el enlace ya consumido.
- **El máximo son 36 caracteres**, igual que en `app/api/contrasena.php`:
  `usuarios.contrasena` es varchar(50) y guarda el base64 del cifrado legacy
  (4*ceil(n/3)). Con 37 caracteres son 52 y MySQL la truncaría.
- **La búsqueda acepta usuario o correo** (`usuario = :u OR LOWER(correo) = :c`,
  primera por id): quien perdió la contraseña no tiene por qué acordarse de con
  cuál entra, y `usuarios` no tiene `UNIQUE` en ninguna de las dos columnas.
- **La FK de `recuperaciones` es `ON DELETE CASCADE`**, a diferencia del
  `RESTRICT` de casi todo el esquema: un token sin su usuario no vale nada, y
  con `RESTRICT` esta tabla se sumaría a la lista de cosas que hay que borrar a
  mano antes de eliminar una cuenta (que ya arrastra `perfiles`).
- **Cambiar la contraseña NO cierra las sesiones abiertas.** El JWT es
  stateless (12 h de TTL) y no hay nada en el token que se pueda invalidar
  desde la base. Si alguna vez hace falta, el camino es un claim de versión en
  el JWT contra una columna de `usuarios`, no tocar esta pantalla.

## Módulos

El shell está pensado para poblarse por módulos. Cada nuevo módulo se agrega
como sub-ítem del sidebar (dentro de una categoría con emoji) y registra su
renderer en `routes` de `assets/js/app.js`. Los módulos ABM, el módulo
Herramientas y sus utilidades tienen sus propias skills dedicadas
(`abm_design`, `crear_modulo_herramientas`, etc.) — respetalas cuando
implementes cada uno.

### Dashboard → gráfico "Uso por dispositivo"

`api/dashboard_senales.php` + `renderDashboard()` agregan debajo de las stat
cards un gráfico de líneas multi-serie: una línea por dispositivo del dominio,
sobre una ventana que elige el usuario (24 h / 7 / 15 / 30 días, **por defecto
30 días** — ver "Selector de período" más abajo). SVG dibujado a mano, como el
de Conexión. Reglas que no se deducen del esquema:

- **Sólo cuentan los mensajes que empiezan con `CMD=`, que son salientes.**
  `senales` mezcla varias familias en la misma tabla y la mayoría no es uso
  del equipo: `REP=LAT` / `REP=CNX` / `REP=INI` son latido, conexión y
  arranque (llegan igual sin que nadie toque el equipo), `REP=SNS` /
  `REP=CAP` / `REP=CEN` son reportes periódicos de sensores, y `RET=…`
  (sentido `'E'`) es la respuesta del equipo. `CMD=…` es la **orden** que
  sale hacia el equipo: en la ventana medida son 18.064 de 71.789 filas de un
  dominio, todas con `sentido = 'S'`. El filtro cambia el ranking, no sólo la
  escala: un equipo puede ser el 2º en señales totales y el 8º en uso real.
- **Se cuenta la orden (`CMD=`) y no la respuesta (`RET=`).** Lo que el
  gráfico mide es cuánto se **operó** el equipo, y eso es una acción de la
  plataforma sobre el equipo, no del equipo sobre la plataforma. Contar
  `RET=` haría que un equipo que dejó de contestar apareciera como "sin uso"
  cuando en realidad se lo siguió comandando — que es justo lo que hay que
  ver. En la ventana medida la diferencia son 195 mensajes (18.064 `CMD=`
  contra 17.869 `RET=`): órdenes que no obtuvieron respuesta.
- Al ser todas salientes **no hace falta filtrar por `sentido`**: el prefijo
  ya lo determina (verificado: las 18.064 son `'S'`).
- **Un día sin comandos vale 0, no "sin dato".** Es la diferencia de fondo
  con el gráfico de Conexión: allá el eje Y es una *medición* y las horas sin
  reporte son un corte en la línea; acá es un *conteo* y que nadie haya usado
  el equipo es el dato. Las líneas van enteras, sin huecos.
- **La paleta tiene SEIS ranuras y el tope no es negociable** (`style.css`
  §15b). Es de la familia del rojo institucional por decisión de marca —
  rojo, durazno, amarillo, blanco, rosa y oro, sin azules ni verdes — y
  dentro de una familia cálida el tono casi no varía: lo que separa una serie
  de otra es la **luminosidad**, y sólo entran seis escalones. Medido: sumar
  un naranja medio (`#e8801a`) junto al oro (`#b39400`) los deja en ΔE 1,8
  bajo daltonismo, indistinguibles. Verificada con el validador de la skill
  `dataviz` contra el fondo real del plot y sobre **todos** los pares (las
  líneas se cruzan): daltonismo ΔE 8,3 ≥ 8, visión normal ΔE 16,2 ≥ 15,
  contraste ≥ 3:1. Incumple a propósito "banda de luminosidad" y "piso de
  croma" — la primera da por sentado que separa el tono, y el blanco tiene
  croma 0 por definición.
- **La ranura la asigna el backend (`series[].slot`), no el orden del array**:
  el color sigue al equipo. Si lo eligiera el front por índice, cualquier
  reordenamiento repintaría las líneas.
- **La identidad de la serie la lleva sólo la referencia de arriba**, más el
  tooltip y la vista de tabla. **No hay rótulos al final de las líneas**:
  reservarles una canaleta a la derecha dejaba un vacío que se leía como si
  al gráfico le faltaran días. Si alguna vez se vuelven a querer, hay que
  sumarle el ancho del rótulo a `padR`, no dibujarlos sobre el área del plot.
- **Los rótulos del eje X se cuentan desde el último día hacia atrás**, no
  desde el primero. Si el paso no divide justo a la ventana (30 días con paso
  2 sí, pero no es garantía), contando desde el principio el último rótulo
  cae días antes del final y el gráfico se lee como si le faltara el tramo
  más reciente, que es justo el que más se mira. El costo es que el día más
  viejo puede quedar sin rótulo, que importa mucho menos.
- **El tope de colores decide qué se dibuja, no qué se informa.** `series`
  viaja completo, con `slot = null` en los que no entraron, y además un
  agregado `otros` (gris, no es una ranura). El gráfico dibuja las seis + el
  agregado; **la vista de tabla lista los equipos uno por uno**, así ninguna
  cifra queda escondida. El dominio más grande de los datos actuales tiene 10
  equipos con uso.
- **Sólo se devuelven los equipos con uso en la ventana**: 13 equipos de los
  que 4 responden son 4 series, no 13 líneas planas pisándose en el cero.
  Cuántos quedaron afuera viaja en `resumen` y el encabezado lo dice
  ("10 de 13 dispositivos").
- **`senales` no tiene columna `dominio`**: el único camino al inquilino es
  `senales.dispositivo → dispositivos.dominio`. Se resuelven primero los
  equipos del dominio y después se agrega con un `IN` explícito sobre esos
  ids. El `IN` no es cosmético: ataca `fk_senales_dispositivo` — que en InnoDB
  es `(dispositivo, id)` — así que el `id >= :piso` recorta cada rango por su
  propio prefijo. Con un `JOIN` contra `dispositivos` el optimizador puede
  elegir el otro plan (barrer la PK entera desde el piso), que para un dominio
  chico es leer cientos de miles de filas ajenas.
- **El rango se acota por PK, igual que en Conexión** (`senalesPisoPorFecha()`,
  ahora en `lib/senales.php` y compartida con `api/dispositivo_conexion.php`).
  Costo medido en dev: 0,04 s la búsqueda del piso + 0,07 s la agregación del
  dominio más cargado.
- **La ventana termina hoy, así que en dev el gráfico sale vacío**: la base de
  desarrollo tiene datos hasta el 19/05/2026. Por eso el estado vacío no es un
  cartel genérico — dice cuántos equipos tiene el dominio y **cuándo fue la
  última actividad conocida**, que sale de `dispositivos.latido` / `.conexion`
  y no de un `MAX(fecha)` sobre `senales`: ese `MAX` no se puede acotar por PK
  (justamente busca lo más nuevo, que puede ser muy viejo) y recorrería el
  historial completo de cada equipo.
- **Dashboard hace dos cargas independientes**, no una: el inventario es
  instantáneo y el gráfico agrega cientos de miles de filas. Unirlas haría
  esperar a los números de arriba sin necesidad.
- **El botón ☰ del encabezado va al módulo Actividad** (`<a href="#/actividad">`,
  no un `<button>` cableado: lo resuelve el router por hash, así que además
  sirve para abrir el módulo en una pestaña nueva). **No hay vista de tabla**
  — existió hasta el 03/09/2026 y se descartó por pedido explícito. Con eso
  las cifras exactas del gráfico quedan sólo en el tooltip; el sustituto es
  Actividad, que lista los registros de fondo. Si alguna vez hace falta el
  detalle numérico del gráfico, el payload ya trae la serie de **cada** equipo
  (`series` completo, con `slot = null` en los que no entraron a la paleta):
  no hay que tocar el endpoint, sólo volver a dibujar la tabla.

**Selector de período (24 h / 7 / 15 / 30 días, por defecto 30 días).** Chips en
el encabezado de la tarjeta, `?ventana=24h|7d|15d|30d`. Reglas propias:

- **La granularidad la decide la ventana, no un parámetro aparte**: 24 h se
  agrupa por **hora** (24 puntos, eje en `HH:00`) y las ventanas en días por
  **día** (7 / 15 / 30 puntos, eje en `DD/MM`). Agrupar 24 h por día daría un
  gráfico de un solo punto y 30 días por hora daría 720 puntos ilegibles; por
  eso la unidad viaja dentro de `VENTANAS` y el front no puede combinarlas
  mal. Por lo mismo el payload habla de `puntos` / `granularidad` y no de
  `dias`.
- **La expresión de `GROUP BY` sale de una lista cerrada, no del pedido**: va
  interpolada en el SQL (`DATE(fecha)` o `DATE_FORMAT(...)`), así que un valor
  de afuera sería inyección. Y tiene que producir **exactamente** las mismas
  claves que arma `puntos()` en PHP, o el emparejamiento falla en silencio y
  la serie queda toda en cero.
- **Una ventana desconocida cae en la de defecto, no corta con 4xx**: el front
  sólo manda claves de `opciones`, así que llegar con otra cosa es una URL a
  mano y no vale romperle la pantalla al usuario. El front después lee
  `resumen.ventana` para marcar el chip que corresponde a lo que se está
  viendo, no el que se clickeó.
- **Los chips los arma el front con `opciones` del backend**, no con una lista
  propia: dos listas se desincronizan y el front terminaría ofreciendo una
  ventana que el endpoint no sabe servir.
- **La ventana por defecto está declarada dos veces** — `usoVentana` en
  `app.js` y `VENTANA_DEFECTO` en el endpoint — y **tienen que decir lo
  mismo**. El front la manda siempre explícita, así que la del backend sólo
  entra en juego si se pega la URL del endpoint a mano; pero si se cambia una
  sola, el chip marcado deja de coincidir con lo que se sirve.
- **Al cambiar de ventana se atenúa el gráfico que ya está** (`.uso-cargando`)
  en vez de reemplazarlo por un cartel de "cargando": así no salta el alto de
  la página. Los chips quedan al 100% para que se vea cuál se acaba de elegir.
- **`etiqueta` y `periodo` son dos strings distintos** ("últimos 7 días" para
  el encabezado suelto, "los últimos 7 días" para meter en una oración). El
  artículo no se pega en el front porque el género cambia con la unidad —
  *las* últimas 24 horas contra *los* últimos 7 días — y esa concordancia no
  es lógica de presentación.
- Costo medido en dev (dominio de 13 equipos): 0,04 s de búsqueda del piso
  —constante, no depende de la ventana— más 0,004 s (24 h) a 0,06 s (30 días)
  de agregación.

**El "ahora" de la ventana sale de la base (`SELECT NOW()`), no de PHP**, por
la misma razón que en la pestaña Conexión: los dos relojes no están alineados
(PHP en UTC, la sesión de MySQL en -03:00, medido el 03/09/2026) y
`senales.fecha` la escribe la base. Con el reloj de PHP la ventana se corre 3
horas hacia adelante, los últimos puntos caen en el futuro y salen siempre en
cero, así que el gráfico se lee como si los equipos hubieran dejado de
responder.

### Dispositivos → modal Consultar → pestaña General

La ficha **no muestra todo lo que devuelve el endpoint**: son 16 campos de los
~35 que sirve `api/dispositivos.php`. Quedan fuera a propósito:

- **`id`**: no hay tarjeta `Código`. El id ya encabeza el modal
  (`Consultar dispositivo #N`), así que repetirlo adentro es ruido.
- **`dominio`**: el panel filtra todo por el dominio de la sesión, así que la
  columna sólo puede tener un valor y repetirlo en cada ficha no informa nada.
- **Catálogos que administra Reactor y el cliente no elige**: `agente`,
  `transceptor` y `chip`. Se conservan `modelo` y `producto`, que son los que
  identifican el equipo para el usuario.
- **Credenciales del equipo**: `identidad` y `llave`. Son secretos de
  aprovisionamiento MQTT, no datos que el cliente tenga que leer ni copiar;
  el equipo se identifica por `uuid` (`Identificador`) y `serial` (`Serie`).
- **Provisión y adopción**: `senalesLimite`, `fabricacion`, `instalacion`,
  `adoptado` y `adopcion`. Son del ciclo de vida interno — el mismo criterio
  por el que `Editar` sólo expone `nombre`.
- **Monitoreo completo**: `monitoreo`, `monitoreoIntervalo`,
  `monitoreoUltimo`, `monitoreoSiguiente` y `monitoreoCorreos`.
- **`conexion`**: no hay tarjeta `Última conexión`. La actividad reciente la
  cuenta `Último latido`, que llega solo y es el que de verdad dice si el
  equipo sigue vivo.
- **`coordenadas` e `indicadores`**: strings crudos del sistema histórico, sin
  formato ni mapa que los haga legibles.

**La paridad de la grilla es parte del diseño, no un accidente.** `.view-grid`
es flex con `flex-grow: 1` (CSS §11), así que una tarjeta sola en su renglón
se estira al 100% — se lee como un campo destacado a propósito cuando en
realidad es el sobrante de una cuenta impar. Los 16 campos son **par**, así
que van todos en `view-card-half` y la ficha cierra en ocho renglones parejos,
del primero (`Identificador` + `Nombre`) al último (`Latidos` + `Firmware`).
**Agregar o quitar un solo campo rompe eso**: si la lista queda impar hay que
marcar una tarjeta `view-card-full` en una ranura **impar** (para que los
bloques de arriba y de abajo sigan cerrando de a dos), no simplemente editar
la lista y dejar que se estire la última.

La barra de acciones tampoco lleva el desplegable `Acciones` que sí tienen
otros módulos: sus opciones (copiar identificador / MAC / coordenadas,
habilitar-deshabilitar) están todas en el menú contextual de la fila, y dos de
las tres de copiar apuntaban a campos que la ficha ya no muestra. La barra
queda en `Cerrar` + `Editar`.

### Dispositivos → modal Consultar → pestaña Conexión

`api/dispositivo_conexion.php` + `vistaConexion()` agregan al modal de
Consultar una segunda pestaña con la **serie temporal** del nivel de señal
(la primera, **General**, es la ficha de datos del equipo). Es un gráfico de
línea dibujado a mano en SVG: eje X = tiempo, eje Y = nivel en dBm, un punto
por hora, con selector de período (24 h / 48 h / 7 días). Reglas que no se
deducen del esquema:

- **EL EQUIPO NO INFORMA SU NIVEL EN CADA MENSAJE, y esa es la clave de todo
  el gráfico.** `WSN` viaja **sólo** en `REP=CNX` (reconexión), `REP=INI`
  (arranque) y `RET=WSN` (respuesta a un pedido explícito). Medido sobre un
  equipo real en dev: `REP=CNX` 575 mensajes / 575 con nivel, `REP=INI` 88 /
  88, pero **`REP=SNS` 125 / 0 y `REP=LAT` 51 / 0** — ni el latido ni los
  reportes de sensores lo llevan (`REP=LAT|LAT=32|IDT=…`,
  `REP=SNS|CNL=2|VAL=1|IDT=…`: no hay ningún campo de señal ahí). Un equipo
  con enlace estable puede pasar el día entero mandando mensajes sin informar
  el nivel **ni una vez**. Por eso "hay señales todo el día en Actividad pero
  el gráfico muestra 3 puntos" **no es un bug del filtro**: son mensajes que
  no traen el dato. Ampliar el `LIKE` no sirve — no hay nada más que buscar.
- **El nivel SE ARRASTRA: la hora sin lectura propia hereda la última
  conocida** (`estimado = true` + `origen`, la hora de la que salió el
  valor). No es relleno cosmético: el nivel no cambia porque nadie lo mida,
  así que la última lectura es la mejor estimación disponible. Es lo que
  convierte 3 puntos sueltos en una línea legible.
- **El arrastre se siembra con la última lectura ANTERIOR a la ventana**
  (`ultimaAntes()`, hasta **7 días** hacia atrás). Sin eso, un equipo estable
  que informó por última vez antes del período empieza el gráfico en el aire.
  Más de una semana no se busca: un nivel de hace 10 días no dice nada del
  actual, y ahí la línea arranca recién en la primera lectura real.
- **La estimación se muestra como estimación, y por tres vías a la vez**: el
  tramo arrastrado va **punteado y más tenue** (`.senal-linea-est`), esas
  horas **no llevan punto** (los puntos son sólo las horas medidas) y el
  tooltip dice `sin reporte · último nivel conocido: -61 dBm · … (de <hora>)`.
  Si el tramo estimado se dibujara igual que el medido, un equipo que informó
  dos veces en el día se vería idéntico a uno que informó cada hora — que es
  exactamente lo que no puede pasar. **Al tocar el gráfico, mantener las
  tres.**
- **`resumen.horas_con_dato` cuenta horas MEDIDAS (`muestras > 0`), no horas
  dibujadas.** Desde el arrastre, `dbm !== null` es casi toda la ventana:
  contarlo así diría "24 de 24 horas con reporte" de un equipo que informó
  tres veces. La tarjeta **Cobertura** es lo único que pone en números cuánto
  de la línea es estimación.
- **Los puntos son las horas medidas, en las tres ventanas.** En 7 días son
  hasta ~168 y se achican (r 3 en vez de 3,5) para que no se empasten. Al
  pasar el mouse, el punto de esa hora se repite agrandado
  (`.senal-punto-activo`, r 5,5, con anillo oscuro para despegarlo del trazo)
  — **sólo si la hora fue medida**: sobre una hora estimada el tooltip lo
  aclara y no aparece ninguna marca.
- **Los tramos de la línea se agrupan por tipo, no se dibujan uno por uno**:
  se juntan los segmentos consecutivos sólidos o punteados en una sola
  polilínea cada uno. 168 `<line>` sueltas pierden los empalmes redondeados y
  llenan el DOM sin necesidad. Un tramo es sólido sólo si **sus dos extremos**
  son lecturas reales.
- **El hover lo capturan columnas invisibles de alto completo** (`.senal-hit`,
  una por hora), no los círculos. Apuntarle a un punto de radio 3 con el mouse
  es imposible, y en 7 días hay 168: la columna da el dato con sólo estar a la
  altura correcta del eje X.
- **El tooltip se acota al ancho del plot.** Va centrado sobre su columna
  (`translate(-50%)`), así que en las horas de los extremos se salía por el
  costado: como `.modal-body` tiene `overflow-y: auto`, CSS le vuelve `auto`
  también al eje X, y aparecía una **barra de scroll horizontal** en el modal
  con el texto cortado. `activarConexion()` acota el centro contra
  `plot.width` (midiendo el tooltip en `visibility: hidden`, porque con el
  atributo `hidden` es `display: none` y `offsetWidth` da 0), y
  `.senal-plot` lleva `overflow-x: clip` de red de contención —
  **`clip` y no `hidden`**: `hidden` cortaría también el desborde vertical,
  que es el normal del tooltip porque se dibuja arriba del punto.
- **El valor de la hora es el promedio de esa hora**, no una muestra: un
  equipo activo informa decenas de veces por hora. `minimo` / `maximo` van
  aparte, para el tooltip. En cambio `promedio` / `mejor` / `peor` del
  resumen salen de las **mediciones crudas**, no de los promedios horarios —
  una hora con 40 lecturas y otra con 1 no pesan igual, y el mínimo real se
  perdería dentro del promedio de su hora.
- **El nivel no es una columna: viaja adentro de `senales.mensaje`**, en el
  protocolo de etiquetas `CLAVE=valor` separadas por `|`. Hay dos formas, las
  dos entrantes (`sentido = 'E'`): `REP=CNX|…|WSN=-65|…` (y `REP=INI`), que es
  la habitual, y `RET=WSN|VAL=-94|…`, la respuesta a un pedido explícito.
  **`RET=WSN|…` no contiene la subcadena `WSN=`**, así que el filtro necesita
  los dos `LIKE`. Y `VAL` sólo es señal en esa forma: en
  `REP=SNS|CNL=1|VAL=4.1` es la lectura de un sensor.
- **La escala es la del legacy** (`cDispositivo::senal2porcentaje()`):
  -10 dBm = 100% y -90 dBm = 0%, lineal, o sea 20 dBm cada 25 puntos. No
  inventar otra — el mismo equipo tiene que leerse igual acá y en el back
  office viejo. La conversión vive **sólo en el endpoint**: el front recibe
  `{dbm, porcentaje}` ya resuelto y no repite la fórmula.
- **Las bandas de calidad caen en números redondos de esa escala**: 50% es
  exactamente -50 dBm y 25% exactamente -70 dBm, los dos cortes clásicos de
  señal WiFi. Por eso son tres (Buena / Regular / Débil), van de **fondo como
  zonas horizontales** (no pintando la línea, que es una sola serie) y las
  guías del eje Y caen en -90 / -70 / -50 / -30 / -10.
- **El color nunca es el único portador del significado**: la posición
  vertical, los rótulos del eje, la referencia de zonas y el tooltip dicen lo
  mismo. Es un requisito de accesibilidad, no decoración — verde y ámbar son
  indistinguibles con daltonismo protán.
- **El rango se acota por PK con una búsqueda binaria, no por fecha sola**
  (`senalesPisoPorFecha()`, en `lib/senales.php` — se comparte con el gráfico
  del Dashboard): `senales` no tiene índice por `fecha`, así que filtrar
  por rango sobre el índice de `dispositivo` obliga a mirar fila por fila
  todo el historial del equipo (348K filas / 1,6 s en el peor caso medido en
  dev). Como `fecha` crece junto con `id`, ~25 sondas por clave primaria
  acotan la tabla de 35M ids y el `WHERE` arranca cerca de la ventana: 7 días
  del equipo más cargado bajan a 0,13 s, con el mismo resultado exacto que el
  SQL sin cota (verificado). Al piso se le resta un margen de 2.000 ids
  porque la monotonía `fecha`/`id` no la garantiza nada: pasarse hacia atrás
  sólo cuesta scan, quedarse corto perdería mediciones.
- **La ventana termina en la hora en curso**, así que en dev el gráfico sale
  vacío: la base de desarrollo es una copia con datos hasta mayo de 2026.
- **El "ahora" de la ventana sale de la base (`SELECT NOW()`), no de PHP.**
  Los dos relojes **no** están alineados: medido el 03/09/2026, PHP corre en
  **UTC** y la sesión de MySQL en **-03:00**. Como `senales.fecha` la escribe
  la base, armar la ventana con `new DateTimeImmutable('now')` la corría 3
  horas hacia adelante: las últimas 3 horas caían en el futuro, salían
  siempre vacías, y la línea terminaba antes del borde derecho del gráfico —
  se leía como si el equipo hubiera dejado de reportar hace 3 horas cuando
  estaba reportando normalmente. Comparar contra el mismo reloj que escribe la
  columna es lo único que lo evita de raíz, aunque después se alinee la zona
  horaria del contenedor. **El drift sigue ahí**: cualquier endpoint nuevo que
  compare fechas de la base contra un `now()` de PHP tiene el mismo bug
  (empezando por `api/dashboard_senales.php`, que todavía usa el reloj de PHP
  para su ventana de 30 días — ahí 3 horas sobre 30 días casi no se nota, pero
  el patrón está mal igual).
- **La pestaña se carga recién al abrirla** (y sólo una vez): la consulta es
  cara y la mayoría de las consultas al dispositivo no la miran. Cambiar de
  período sí vuelve a pedir.
- **`dispositivos.senal` es varchar y arrastra valores escritos a mano**
  ("-59dB alta", 2 de 250 filas en dev): se toma el entero con signo del
  principio, no `is_numeric()` sobre el texto entero.

### Dispositivos → modal Nuevo dispositivo = adoptar

El módulo **no tiene alta**. El cliente no fabrica equipos: los fabrica Reactor
y el panel sólo los adopta. El botón `+ Nuevo dispositivo` abre un modal con
**un solo campo, el número de serie**, y su acción primaria dice `Continuar`
(`POST api/dispositivos.php?accion=adoptar`, body `{serial}`). Reglas que no se
deducen del esquema:

- **Es la inversa exacta de Liberar**: abre una fila en `adopciones`
  (`vigente = '1'`, `adoptado = NOW()`, `adoptador` = usuario de la sesión,
  `liberado` con el centinela `'1500-01-01 00:00:00'`) y mueve el dispositivo
  al dominio con `adoptado = 1`, `adopcion` = la fila nueva y `habilitado = 1`,
  para que quede operativo sin un segundo paso.
- **Sólo se puede adoptar lo que está en el pool** (`dominio = 1`, `Liberado`).
  Si el serial existe pero está en otro dominio, 409 con el mensaje que
  corresponda — se distingue "ya está en tu cuenta" de "es de otra cuenta",
  porque el genérico confunde cuando el equipo es propio.
- **`serial` NO es único y no hay UNIQUE que lo impida**: hay 3 repetidos en
  dev y uno de ellos tiene **las dos filas en el pool**. Si la búsqueda trae
  más de un equipo libre no se adivina cuál: 409 pidiendo contactar a Reactor.
- **El `UPDATE` final lleva `AND dominio = 1`**: es el candado contra la
  carrera de dos cuentas adoptando el mismo equipo. Si afecta 0 filas se
  deshace la transacción en lugar de robárselo al que llegó primero.
- **Antes de insertar se cierran las adopciones vigentes** del equipo. Un
  equipo del pool no debería tener ninguna abierta, pero los datos traen de
  todo (24 filas del pool siguen con `adoptado = 1`).
- **El segundo modal, `Dispositivo adoptado`, es informativo**: cuando se abre,
  la adopción ya está registrada por el `POST`. No tiene acción primaria, sólo
  `Cerrar`, y muestra qué equipo entró.
- **La adopción no toca `nombre`**: el equipo conserva el que traía del dueño
  anterior y se renombra desde `Editar` — que es justamente el único campo que
  ese modal edita.
- Al desaparecer el formulario de alta, `catalogos()` quedó con **un solo
  catálogo, `modelos`** (el del filtro del listado). Los de `agentes`,
  `productos`, `transceptores` y `chips` eran 4 queries por cada carga del
  listado para selects que ya no existen.

### Dispositivos → modal Editar

Alta y edición comparten `formDispositivo()` pero **no comparten formulario**:

- **En edición el usuario ve un solo campo, `nombre`** (y el botón dice
  `Guardar`, no "Guardar cambios"). El resto de la ficha —identificador de
  fábrica, catálogos (modelo / producto / agente / transceptor / chip), MAC,
  serie, identidad, llave, fechas, límite de señales, monitoreo, coordenadas e
  indicadores— la administra Reactor, no el cliente, y se carga en el alta.
- **`habilitado` no está en el modal**: se cambia desde `Habilitar` /
  `Deshabilitar` del menú contextual de la fila (`toggleDispositivo()`).
- **El PUT reescribe la fila entera**, así que el payload de edición parte de
  `payloadDispositivo(d)` —el registro tal como vino del `GET`— y pisa nada
  más `nombre`. Si el form deja de mandar un campo y el payload no lo
  arrastra, ese campo se borra en la base.
- El modal de edición **no** es `wide`: con un solo campo, los 880px del alta
  quedaban vacíos.

### Dispositivos → Liberar (no hay baja)

El menú contextual de la fila **no ofrece Eliminar**: ofrece `Liberar`
(`POST api/dispositivos.php?accion=liberar&id=N`). Un dispositivo no se borra
nunca. Reglas que no se deducen del esquema:

- **El "sin dominio" no es NULL: es el dominio 1, `Liberado`** — un dominio
  pool con `habilitado = 0` donde esperan los equipos sin dueño (102 en dev).
  Ningún dispositivo tiene `dominio IS NULL` en la base, así que liberar
  **mueve** la fila a ese dominio (`DOMINIO_LIBERADO` en el endpoint).
- **Liberar cierra las adopciones vigentes**: `adopciones.liberado = NOW()`,
  `liberador` = usuario de la sesión, `vigente = '0'`. `vigente` es
  `'1'` / `'0'` (varchar(1)), y `liberado` **no usa NULL**: las filas abiertas
  llevan el centinela `'1500-01-01 00:00:00'` del sistema histórico.
- **Las adopciones se buscan por `adopciones.dispositivo`, no por
  `dispositivos.adopcion`**: ese puntero no es confiable — hay equipos con
  hasta 3 filas `vigente='1'` a la vez (202, 244, 287, 310) y una misma fila
  de adopción apuntada por dos dispositivos (55779 ← 424 y 425). Se cierran
  **todas** las vigentes del equipo.
- **El equipo queda `habilitado = 0`, `adoptado = 0` y `adopcion = NULL`**:
  es el estado del 96% del pool (98 de 102) y evita que un equipo sin dueño
  siga operando. `adoptado` arrastra basura (24 filas del pool siguen en 1),
  así que el marcador confiable de "liberado" es `dominio = 1`.
- **Las dos escrituras van en una transacción**: media adopción cerrada con
  el dispositivo todavía en el dominio deja el equipo en un estado que
  ninguna pantalla sabe leer.
- **No existe `DELETE`** en el endpoint. Además de que la baja no es lo que
  el negocio quiere, cualquier equipo que estuvo en servicio tiene historial
  en `adopciones`, `canales`, `botones`, `controles`, `etiquetas` y `usos`,
  todas con FK `ON DELETE RESTRICT`: el `DELETE` fallaba con 1451.
- El front reusa `confirmarBaja()` pasándole `{ label: 'Liberar' }` — el
  helper acepta el rótulo del botón rojo para acciones irreversibles que no
  son una baja.

### Actividad → modal Consultar: tres pestañas (General / Usuario / Dispositivo)

El modal muestra el registro en **General** y agrega una ficha por cada
entidad que el registro referencia. Reglas que no se deducen del esquema:

- **Las fichas las arma `api/actividad.php?id=N` con los mismos `LEFT JOIN`
  del registro, y ésa es la decisión de fondo.** Lo natural sería pedirlas a
  los endpoints de cada módulo, que ya tienen la ficha curada — pero ninguno
  sirve: `api/usuarios.php?id=N` **ya no recibe un id de usuario sino de
  perfil** (el módulo administra `perfiles`, ver más abajo), y
  `api/dispositivos.php?id=N` filtra por **`dominio`, que en esa tabla es el
  dueño *de turno*** (liberar mueve el equipo al dominio 1). Un registro de
  hace seis meses puede apuntar a un equipo que se liberó, y esa consulta
  devolvería **404 sobre actividad perfectamente válida**. El control de acceso
  ya lo dio `r.dominio = :dom` en el registro.
- **El corte de "no hay ficha" mira `u.id` / `d.id`, no `r.usuario` /
  `r.dispositivo`**: esas columnas arrastran el centinela `0` del sistema
  histórico además de `NULL` (ver `project_perfiles_centinela_cero`), y con
  los dos el `LEFT JOIN` no resuelve. El `id` del lado unido es el único
  indicador confiable de que la fila existe.
- **Las tres solapas están siempre**, aunque el registro no tenga usuario o
  dispositivo: ahí el panel muestra un estado vacío explícito. Que una
  pestaña aparezca y desaparezca según la fila haría saltar el modal y
  dejaría al usuario sin saber si falta la pestaña o si no hay dato.
- **Las tres fichas tienen 8 campos — par a propósito.** `.view-grid` es flex
  con `flex-grow: 1` (CSS §11): con la cuenta impar la última tarjeta se
  estira al 100% y se lee como un campo destacado deliberado. Mismo criterio
  que Dispositivos → General y Usuarios → Consultar.
- **General perdió cuatro campos** (03/09/2026): `UUID del dispositivo`,
  `Número de canal`, `Correo` y `Dominio`. Los dos primeros y el correo no se
  perdieron — pasaron a las pestañas nuevas (`Identificador` del equipo,
  `Correo` del usuario). `Dominio` no vuelve en ningún lado: el panel filtra
  todo por el dominio de la sesión, así que la columna sólo puede tener un
  valor (el mismo criterio con el que se excluyó de Dispositivos → General y
  de Usuarios → Consultar).
- **El modal pasó a `wide: true`** (880px): con tres solapas y tarjetas al
  50%, los 520px del ancho base quedaban apretados. Es el mismo ancho del
  otro modal con pestañas, Consultar dispositivo.
- **Ninguna de las dos fichas se carga bajo demanda**, a diferencia de
  Dispositivo → Conexión: vienen en el mismo `GET` porque son cinco `JOIN`
  por PK sobre una sola fila (3,7 ms medidos en dev), no una agregación.
- El conmutador de solapas es `montarPestanas(backdrop, onMostrar)`,
  compartido con el modal de Dispositivos. `onMostrar` es opcional y sólo lo
  usan los paneles que se piden al abrirse.

### Actividad → la ventana de búsqueda es fija y el modal de Consultar no tiene ☰

Dos recortes de UI del 03/09/2026 sobre `renderActividad()`:

- **La barra de acciones del modal de Consultar es sólo `Cerrar`.** No lleva
  el desplegable `Acciones` que sí tienen otros módulos: sus tres opciones
  (filtrar por este usuario, filtrar por este dispositivo, copiar detalle) ya
  viven en el menú contextual de la fila, que es desde donde se abre el modal.
  Tampoco hay acción primaria: `api/actividad.php` sólo responde `GET`.
- **"Ventana de búsqueda" salió del modal de Filtros, pero la ventana sigue
  existiendo.** `actividad.ventana` queda clavada en 200.000 en
  `ACTIVIDAD_DEFAULTS` y **se sigue mandando en cada `GET`** — sacarla del
  payload devolvería la búsqueda vacía de 14 s sobre los ~3M de `registros`
  (ver `project_registros_scale`). Lo que se quitó es la posibilidad de
  **ampliarla** desde la UI, que era justamente la opción cara. Como el select
  ya no está, la línea `#ac-ventana-nota` bajo la tarjeta de ayuda dejó de
  decir "ampliá la ventana desde Filtros" y ahora manda a **buscar por
  código**, que es el único camino al historial viejo (el backend resuelve el
  lookup por id sin ventana). `api/actividad.php` conserva su lista `VENTANAS`
  y sigue validando contra ella: el día que haga falta reponer el selector,
  el backend ya lo soporta.
- Al sacar el select, `Dispositivo` quedaba solo en un `.filters-grid` de dos
  columnas con media columna vacía a la derecha, así que pasó a ser un
  `.form-group` suelto a todo el ancho.

### Comprobantes (Facturas y Recibos)

`api/comprobantes.php` + `renderComprobantes()` sirven **las dos pantallas**
con el mismo código (`?tipo=F` / `?tipo=R`). Reglas que no se deducen del
esquema:

- **El alcance es el contrato, no el cliente**: se filtra por
  `dominios.contrato` del dominio de la sesión (un cliente puede tener varios
  dominios y cada uno se factura por su contrato). Sin contrato → 409, que la
  UI muestra como mensaje informativo, no como error.
- **El tipo sale de `talonarios.tipo`**, no de ids de talonario: `F`+`T` para
  Facturas y `R` para Recibos. El legacy hardcodeaba `talonario = 48 / 49`
  (los de Alfatec) y por eso los dominios facturados con los talonarios de
  Wescom (38 / 43) veían el listado vacío.
- **Sólo se muestran los estados 2 (Pendiente) y 3 (Cancelado)**, regla dura
  del backend igual que en el legacy: los borradores (1) y los anulados (0)
  no se le muestran al cliente. El filtro de la UI elige dentro de esos dos.
- **"Prefactura" se muestra como "Factura"** (`str_replace` heredado del
  legacy): para el cliente es su factura, el matiz es interno.
- Del detalle se excluyen a propósito los campos internos: `comentarios`,
  `cotizacion` y `talonarios.nombre` (identifica la empresa emisora).
- El PDF lo sirve **el visor público del sitio legacy**
  (`VISOR_BASE` en el endpoint, `https://www.reactor.com.ar/comprobante/`):
  este repo todavía no tiene visor propio. Cuando exista, se cambia esa
  constante y nada más.

### Invitaciones (alta, envío por correo y páginas públicas)

`api/invitaciones.php` (listado + `POST` de alta), `lib/invitaciones.php`,
`lib/databox.php` y las tres páginas públicas de `invitacion/` portan el
ciclo completo de `cInvitacion` del legacy
(`reactor-api/framework/subframework.php`). **El legacy no se toca**: sigue
emitiendo por WhatsApp desde `reactor-app` y resolviendo en
`app.reactor.com.ar/invitacion/`. Los dos circuitos conviven sobre las
mismas tablas. Reglas que no se deducen del esquema:

- **El canal es correo, no WhatsApp.** El envío va por el microservicio de
  Databox (`POST https://api.databox.net.ar/v4/aws/mensajes`, Bearer con
  `DATABOX_APIKEY` del `.env`). Los slugs por defecto son los mismos que
  usaba el legacy en `reactor-api/framework/dataframework.env` — proyecto
  `reactor`, canal `databox`, plantilla `reactor`, remite
  `info@reactor.com.ar` — para que los correos salgan por la misma cuenta
  SES e identidad visual. Se pisan con `DATABOX_PROYECTO` / `DATABOX_CANAL`
  / `DATABOX_PLANTILLA` / `DATABOX_REMITENTE` / `DATABOX_REMITE` en el
  `.env`; `DATABOX_PLANTILLA=` vacía desactiva la plantilla y manda
  remitente/remite/formato explícitos.
- **El alta pide un solo campo, el correo.** Es el destino del mensaje.
  Nombre y celular los completa el invitado al aceptar — el espejo exacto
  del alta legacy, que pedía sólo el celular porque era el destino de
  WhatsApp, y capturaba nombre y correo en la aceptación.
- **El alta y el envío van en una transacción**: si el microservicio no
  aceptó el mensaje se revierte el `INSERT`. Una invitación pendiente que
  nadie recibió es peor que ninguna fila, porque nada indica que hay que
  reintentar. Por eso el modal del front espera la respuesta del `POST` en
  vez de cerrarse optimista.
- **La URL base de producción es fija** (`https://panel.reactor.com.ar`,
  `panelBaseUrl()`), **no se deriva del `Host`**: el enlace viaja dentro de
  un correo, y un `Host` falseado mandaría a los invitados a otro dominio.
  En desarrollo sí se deriva de la request. `PANEL_BASE_URL` en el `.env`
  pisa las dos.
- **`invitacion/` es lo único del panel que se sirve sin sesión.** No pasa
  por `api/bootstrap.php` (que exige JWT) sino por `invitacion/_layout.php`,
  que reusa la tarjeta roja del login. La credencial es el `uuid` del
  enlace, igual que en el legacy, pero se genera con `random_int` (CSPRNG) y
  se verifica que no exista: `invitaciones.uuid` no tiene `UNIQUE` en la base.
- **`abierta` se sella sólo la primera vez.** El legacy la reescribe en cada
  visita, así que su columna termina siendo "última apertura" y no la
  primera, que es lo que el listado dice mostrar.
- **Rechazar es `POST`, no un link.** En el legacy es un `<a href>` y
  cualquier prefetch (antivirus de correo, preview del cliente de mail) puede
  rechazar una invitación que la persona nunca vio.
- **La aceptación cierra la invitación en los dos caminos.** Si la persona ya
  tenía cuenta, el legacy le da el perfil pero deja la fila en pendiente para
  siempre; acá pasa a estado 3 igual. Esas pendientes eternas son las que
  ensucian el listado.
- **A una cuenta que ya existía no se le toca la contraseña ni el dominio
  activo**: sólo se le agrega el perfil, y el dominio nuevo le aparece en
  *Cambiar dominio*. Pisarle `usuarios.dominio` la sacaría del dominio en el
  que está trabajando.
- **No hay columna `apellido`** en `usuarios` ni en `invitaciones`: el
  formulario de aceptación pide nombre y apellido por separado porque es lo
  que la persona espera completar, pero se guardan concatenados en `nombre`.
  No se modificó el esquema por esto.
- **El perfil se crea con `tipo = 'A'`** y nombre `Administrador en <dominio>`.
  Ya no lleva rol: `perfiles.rol` se eliminó el 06/09/2026. `tipo` se sigue
  escribiendo en `'A'` porque la lee el sistema legacy, y una invitación emitida
  desde el panel sigue significando alta administrativa allá — pero **dentro del
  panel no habilita nada**, porque el acceso ya no mira ni el rol ni el tipo.
- **Y con `operacion = 1`, `invitacion = 1` y `facturacion = 0`.** Los dos
  primeros por el mismo motivo que los paneles: un perfil que naciera sin ellos
  no podría usar la app. El tercero en `0` **a propósito** — este alta corre sin
  sesión, así que con `1` sería la puerta trasera para fabricarse el permiso que
  el `PUT` sólo deja otorgar a quien ya lo tiene. Ver "Permisos del perfil".
- **`perfilAsegurado()` busca un perfil de ADMINISTRADOR, no "cualquier perfil
  del dominio".** Si la persona ya tenía uno de Operador ahí (porque usa
  `reactor-app`), ese no habilita el panel —el gate es `perfiles.tipo = 'A'`—
  así que se le **agrega** uno nuevo en vez de reescribirle el que ya tiene.
  Mutar el `tipo` de una fila existente le cambiaría el acceso en el sistema
  legacy desde acá.
- **`tipo` acompaña al rol.** Son dos columnas para lo mismo y el legacy lee
  `tipo`; dejarlas en desacuerdo es exactamente como nacieron los 58 perfiles
  inconsistentes que hoy tiene la base (48 con rol Administrador y `tipo = 'O'`,
  10 al revés). Al **crear** hay que ponerlas de acuerdo; a las que ya están
  torcidas **no se las toca** — ver "`perfiles.tipo`: sólo `A` y `O`".
- **`panel` va en `NULL`, no en `0`.** El legacy (`cPerfil::nuevo()`) escribe
  `0`, que con las FK declaradas en `db/schema.sql` ya no es un valor válido.
  `perfiles.habilitado` se escribe con `HABILITADO` (el entero 1), igual que
  `usuarios.habilitado`: las dos columnas tienen el mismo par de valores
  (ver "La bandera `habilitado`" más abajo).
- **La contraseña inicial se genera y se muestra en pantalla, además de
  mandarse por correo.** No hay pantalla de "definir contraseña" y la columna
  guarda la contraseña de forma reversible (cifrado histórico), así que es el
  mismo criterio del legacy en `reactor-app/sesion/recuperar.php`. Si el
  correo de credenciales falla, la cuenta **no** se revierte: ya es válida, y
  la persona está mirando la pantalla que se las muestra.

#### Invitaciones → el listado

Las columnas son `Identificador` / `Emitida` / `Emisor` / `Destinatario` /
`Estado` / `Acciones` (03/09/2026):

- **No hay columna `Código`.** El id no se muestra en ninguna parte del
  módulo —tampoco en el modal— porque la invitación se identifica por su
  `uuid`, que es lo que viaja en el enlace del correo. El filtro por código
  sigue estando en el modal de Filtros: es el atajo para el soporte, no una
  columna.
- **`Emisor` y `Destinatario` se pintan con la misma celda** (`celdaPersona()`):
  nombre arriba en `.td-nombre` (blanco, 600) y debajo correo y celular en
  `.td-id` (tenue, monoespaciada). Lo único que cambia es de dónde salen los
  datos — el emisor los toma de `usuarios` por el `LEFT JOIN` y el
  destinatario de la propia `invitaciones`. Que las dos columnas se lean
  igual es el punto: son las dos personas de la misma fila.
- **El emisor ya no muestra `usuarios.usuario`** (la cuenta de acceso) como
  segunda línea. Queda **sólo de reemplazo del nombre** cuando
  `usuarios.nombre` viene vacío, para que la celda no arranque sin encabezado.
  En los datos de dev la cuenta es el propio correo, así que mostrarla
  repetía la línea de abajo.
- **El `SELECT` del listado trae `u.correo` y `u.celular`**, que antes eran
  exclusivos del `GET` por id. `mapInvitacion()` los pasa por la lista de
  extras (`array_key_exists`), así que la consulta que no los pide sigue sin
  la clave.
- **La búsqueda rápida cubre lo que se ve**: al aparecer el correo y el
  celular del emisor en la columna, se sumaron `u.correo` y `u.celular` al
  `OR` del filtro `q`. Un dato visible que no se puede buscar se lee como un
  buscador roto.

#### Invitaciones → modal Consultar

La ficha son **9 campos en dos bloques** separados por una divisoria
(`.view-sep`, CSS §11b): arriba la invitación (`Estado` / `Dominio` /
`Emisor` / `Identificador`) y abajo a quién fue y cuándo (`Nombre` /
`Correo` / `Celular` / `Emitida` / `Abierta`). Recortes del 03/09/2026
respecto de lo que devuelve `api/invitaciones.php`:

- **El título es `Consultar invitación` a secas, sin el `#id`** que sí
  llevan los otros modales de consulta del panel, y tampoco hay tarjeta
  `Código`: en este módulo el id no se muestra en ningún lado. El
  identificador visible es el `uuid`, que ocupa media tarjeta al lado de
  `Emisor`.
- **El emisor se muestra con un solo campo, su nombre.** Salieron
  `Cuenta del emisor` (`usuarios.usuario`) y `Correo del emisor`: identifican
  internamente a una cuenta del propio dominio, que ya se consulta desde
  Usuarios.
- **`Destinatario` pasó a llamarse `Nombre`**, y `Correo` va antes que
  `Celular` — el correo es el canal por el que sale la invitación
  (el celular lo completa el invitado al aceptar, así que en las pendientes
  está vacío).
- **La divisoria obliga a mirar la paridad por bloque, no sobre el total.**
  El de arriba son cuatro tarjetas media y cierra solo; el de abajo son
  cinco, así que `Nombre` va `view-card-full` en la **ranura impar** y deja
  `Correo` + `Celular` y `Emitida` + `Abierta` en dos renglones parejos.
  Sin eso, la grilla flex estira la última tarjeta del bloque y se lee como
  un destaque deliberado. **Agregar o quitar un campo obliga a rehacer esa
  cuenta en el bloque que se tocó.**
- **La barra es sólo `Cerrar`**: sin acción primaria (una invitación emitida
  no se edita) y **sin el desplegable `Acciones`** — filtrar por emisor,
  filtrar por estado y copiar ya viven en el menú contextual de la fila, que
  es desde donde se abre el modal. Mismo recorte que Actividad y Usuarios.

### Usuarios → la fila es un PERFIL, no una cuenta

**El módulo lista `perfiles`, no `usuarios`** (05/09/2026). Lo que muestra es
quién tiene acceso a este dominio, y eso vive en `perfiles`, no en
`usuarios.dominio`.

- **`usuarios.dominio` es el dominio ACTIVO de la cuenta** —el último que la
  persona usó, en *cualquiera* de los sistemas que comparten la tabla— y no la
  lista de dominios a los que puede entrar. Es la misma columna que reescribe
  *Cambiar dominio* (`panelPerfilActivoAsentar()`). Filtrar `usuarios` por ella
  escondía a todo el que estuviera trabajando en otro lado: medido en dev,
  `Patio San Ignacio` tiene **152 perfiles y sólo 15 cuentas con ese dominio
  activo** — 137 accesos invisibles —, y `Consorcio Palermo` 126 contra 60. Es
  el mismo razonamiento ya escrito para *Cambiar dominio* ("`usuarios.dominio`
  es sólo el dominio activo y no es la lista de dominios permitidos"), aplicado
  al listado.
- **El `Estado` de la fila es `perfiles.habilitado`, no `usuarios.habilitado`.**
  Son dos cosas distintas y en los datos están desalineadas: 154 perfiles
  deshabilitados pertenecen a cuentas habilitadas y 18 al revés. El de la cuenta
  viaja igual (`usuario_habilitado`) y se muestra en el modal de Consultar, como
  tarjeta aparte: una cuenta deshabilitada no entra ni con el perfil habilitado,
  y un perfil deshabilitado sólo cierra **este** dominio.
- **El estado se escribe y se compara con `HABILITADO` / `DESHABILITADO`** de
  [lib/habilitado.php](lib/habilitado.php) (los enteros 1 y 0), nunca con un
  literal ni con un booleano de PHP — ver "La bandera `habilitado`" más abajo.
- **Una persona puede aparecer varias veces**: son 8 los pares
  `(usuario, dominio)` con más de un perfil. La fila se identifica por el
  **`Código` = id del perfil**, no por el usuario.
- **Ya no hay columna `Rol` en el listado** (06/09/2026): se eliminó
  `perfiles.rol`. El listado quedó en `Código / Usuario / Nombre / Correo /
  Celular / Estado / Último ingreso / Acciones`, y el filtro por rol se fue del
  modal de Filtros junto con la opción `Rol` del selector de orden.
- **El modal de Consultar muestra `Tipo` en la ranura que ocupaba `Rol`.** No es
  un reemplazo conceptual —`tipo` es el `ENUM('A','O')` que lee el legacy, no un
  rol— pero es el único atributo del perfil que queda además del nombre, y la
  ficha tenía que seguir siendo par (12 tarjetas, seis renglones).
- **Hay filas sin cuenta y se muestran igual.** Unos pocos perfiles tienen
  `usuario` NULL o con el centinela `0`. El JOIN con `usuarios` es **LEFT** a
  propósito: con INNER esas filas desaparecían del listado, y una fila que no le
  sirve a nadie y además no se puede ver es una que nadie puede limpiar.
  **No usar `perfiles.tipo` para rellenar el rol que falta** — las dos columnas
  están desalineadas (ver "Acceso: sólo Administradores").

### Usuarios → las tres acciones trabajan sobre el perfil

`Habilitar` / `Deshabilitar` / `Eliminar` operan sobre el **acceso**, nunca
sobre la cuenta. Reglas que no se deducen del esquema:

- **Eliminar borra el perfil y deja la cuenta intacta**: la persona pierde el
  acceso a *este* dominio y conserva usuario, contraseña y los accesos que tenga
  en otros. El texto de la confirmación lo dice explícitamente, porque la fila
  muestra los datos personales y "Eliminar" se lee como "borrar a la persona".
- **Antes del `DELETE` hay que borrar las filas de `sesiones` de ese perfil.**
  `fk_sesiones_perfil` es `ON DELETE RESTRICT` y **1.917 de los 2.227 perfiles
  de dev tienen alguna**, así que sin ese paso la baja fallaba con 1451 en el
  86% de las filas. Una sesión del sistema histórico que apunta a un perfil que
  ya no existe no se puede retomar: borrarlas es cerrar el acceso que se acaba
  de quitar. Las dos escrituras van en una transacción.
- **`usuarios.perfil` NO se toca**: su FK es `ON DELETE SET NULL` y la base lo
  resuelve sola. Si el perfil borrado era el activo de esa cuenta, el login
  elige otro (`api/login.php` → `perfilesHabilitados()`).
- **Sobre el perfil de la propia sesión sólo quedan `Consultar` y `Editar`.**
  Deshabilitarlo o borrarlo cierra el panel en el request siguiente —el gate se
  resuelve contra la base en cada request, no contra el token—, así que el
  backend corta con 409 y el menú de la fila directamente no ofrece esas dos
  acciones (`es_propio`, que lo calcula el backend). Como efecto lateral el
  dominio nunca se queda sin acceso: el perfil de la sesión siempre sobrevive.
  **Editarlo sí se puede**, porque `tipo` y los paneles no gatean el panel — el
  editor deja bloqueado el switch de estado, que es lo único que podría
  cerrarle la puerta a la sesión.
- **El `PUT` acepta `{id, tipo?, habilitado?, paneles?, operacion?, invitacion?,
  facturacion?}`, todos opcionales e independientes.** Lo que no viene, no se
  toca: se relee de la fila y se reescribe igual. El menú de la fila sigue
  mandando sólo `{id, habilitado}` (el toggle) y el editor manda el resto. Se
  distingue con `array_key_exists` y **no** con `isset`, para que un `null`
  cuente como "vino vacío" y no como "no vino". Sigue sin reescribir la fila
  entera: no hay ningún otro campo que el guardado pueda pisar.
- **Los tres permisos sólo los mueve un perfil que ya los tenga**, y es la única
  regla del endpoint que mira a *quien edita* en vez de a la fila editada. Sin
  ella ninguno significaría nada: un administrador se daría a sí mismo el que le
  falta en dos clicks. **El corte es sobre el CAMBIO**, igual que el del estado
  del perfil propio — guardar la ficha con los permisos tal como están pasa, así
  quien no tiene ninguno igual puede editar `tipo` y los paneles.
  **Valía sólo para `facturacion` hasta el 07/09/2026.** Los otros dos quedaban
  libres con el argumento de que son de `app` y quien administra el dominio
  reparte el acceso a la app aunque él no la use; no se sostuvo — repartir lo que
  uno no tiene es exactamente la escalada que el corte evita, y que el permiso se
  ejerza en otra pantalla no cambia quién lo está regalando.
- **Nadie quedó sin poder otorgar al ampliarlo.** Medido el 07/09/2026: los 404
  perfiles que pasan el gate del panel tienen los tres permisos en `1` (es el
  backfill de `20260907_1000`), así que ninguno de los 120 dominios con
  administrador se quedó sin quien reparta. La regla recién muerde cuando alguien
  revoca un permiso, y **el desbloqueo siempre es `cloud`**, que no lleva esta
  restricción: ahí quien edita es Reactor sobre el sistema entero.
- **El corte del 409 es sobre el CAMBIO de estado, no sobre el perfil entero.**
  Guardar el propio perfil con el estado tal como está no es un cambio y pasa;
  lo que se rechaza es apagarlo. Sin esa distinción, editarle los paneles al
  perfil de la sesión sería imposible.
- **No hay `POST`.** `handleCreate()` se eliminó del endpoint: un alta de
  `usuarios` dentro del endpoint que administra `perfiles` sólo podía confundir.
  El alta sigue siendo la invitación (abajo).

### Usuarios → el alta es una invitación, la edición es del perfil

El módulo se recortó a **consultar, habilitar/deshabilitar y eliminar**
(03/09/2026) y recuperó la **edición del perfil** el 06/09/2026 (ver la sección
siguiente). Lo que sigue sin existir es el alta y la edición de la *persona*.
Reglas que no se deducen del esquema:

- **`+ Nuevo usuario` no da de alta: invita.** El botón abre
  `formInvitacion()`, el mismo modal del módulo Invitaciones —un solo campo, el
  correo— y el `POST api/invitaciones.php` encola el envío. La cuenta la crea
  el propio invitado al aceptar. **Si esa persona ya está registrada no se
  duplica el usuario**: `perfilAsegurado()` en `invitacion/aceptar.php` le
  agrega el perfil de este dominio y le deja intactos la contraseña y el
  dominio activo. Por eso el alta no necesita backend propio — el circuito de
  invitaciones ya resuelve los dos casos.
- **Invitar desde el panel es dar de alta a un administrador.** El perfil que
  se otorga al aceptar es de rol Administrador, porque es el único que habilita
  el panel (ver "Acceso: sólo Administradores"). No hay forma de invitar a
  alguien "sólo para mirar": el panel no tiene niveles de permiso internos.
- **No hay formulario de la PERSONA, y no lo va a haber.** `formUsuario()` —el
  que editaba la fila entera de `usuarios`— se borró al mudar el módulo a
  `perfiles` (05/09/2026) y no volvió: los datos de la persona (nombre, correo,
  celular, contraseña) son de **su cuenta** y los administra su dueño. Lo que se
  repuso el 06/09/2026 es el editor **del perfil**, que es otra cosa y toca
  otras tres columnas.
- **Menú contextual de la fila**: `Consultar` → `Habilitar` / `Deshabilitar` →
  separador → `Editar` → `Eliminar`. Es el orden del skill `abm_design`. Sobre
  el perfil de la propia sesión quedan `Consultar` y `Editar` (ver arriba).
- **El modal de Consultar usa `wide: 'xl'`** (`.modal-xl`, 1040px = el doble
  del ancho base), no el `modal-wide` de 880px de los dumps y las tablas.
- **La barra de Consultar es `Cerrar` + `Editar`**, con `Editar` como botón
  **directo** y no dentro de un desplegable `Acciones`: es la única acción de la
  ficha, y un menú de un solo ítem no se justifica (misma regla que Consultar
  dispositivo). Copiar usuario / correo y habilitar-deshabilitar siguen viviendo
  en el menú contextual de la fila.
- **La ficha no muestra `id`, `autenticacion`, `roles`, `panel`, `dominio` ni
  `perfiles.tipo`.** El id ya encabeza el modal (`Consultar perfil #N`);
  `autenticacion`, `roles` y `panel` son internos del sistema histórico;
  `dominio` sólo puede tener un valor, porque el panel filtra todo por el
  dominio de la sesión —el mismo criterio con el que se excluyó de
  Dispositivos → General—; y `tipo` es la columna desalineada con `rol`, que
  mostrarla sólo invita a leerla como el rol verdadero.
- **`General` abre por el acceso (`Perfil` + `Tipo`) y cierra por los dos
  estados** (`Estado del perfil` + `Estado de la cuenta`), que van juntos al
  final porque es donde se leen comparados: son independientes y ninguno de los
  dos alcanza por sí solo para saber si la persona entra. En el medio van los
  datos de contacto, `Identificador` (el `uuid` de la **cuenta**, con ese rótulo
  y no "UUID") y las fechas. Son 12 tarjetas, **todas `half`**: seis renglones
  que cierran de a dos. **Agregar o quitar un campo deja la cuenta impar** y
  estira la última a todo el ancho, que se lee como un destaque deliberado
  (mismo criterio que Dispositivos → General).

### Usuarios → modal Consultar perfil (pestañas General / Permisos / Paneles)

`verUsuario()` (07/09/2026). Las **tres solapas del editor, en modo lectura**:
`General` con la ficha de arriba, `Permisos` con los tres de `perfiles` y
`Paneles` con `perfiles_paneles` contra el catálogo del dominio.

- **Hasta el 07/09/2026 era una sola grilla** con los permisos abajo de una
  `.view-sep` (como en Consultar invitación). Con la solapa cada bloque se mira
  por separado y **la paridad de cada uno se resuelve sola**: ya no hay que
  contar medias tarjetas a los dos lados de una divisoria.
- **Las tres pestañas salen del MISMO `GET ?id=N`** que ya usaba el editor —trae
  la ficha, `paneles` del perfil y `catalogos.paneles` del dominio—, así que
  ninguna se carga bajo demanda: no hay una segunda consulta que ahorrar (a
  diferencia de Dispositivo → Conexión). Lo único que cambió en el front es que
  `verUsuario()` se queda con la respuesta entera y no sólo con `.perfil`.
- **Los rótulos y los íconos son los del editor** (`General` / `Permisos` /
  `Paneles`): es la misma ficha en lectura y en edición, y si no coincidieran se
  leerían como dos pantallas distintas. Se cablean con `montarPestanas()`.
- **Las tres solapas están siempre**, aunque el perfil no tenga ningún permiso o
  el dominio ningún panel: ahí va un estado vacío explícito (`.paneles-vacio`
  dentro de una `.paneles-lista`, la misma caja del editor). Que una pestaña
  aparezca y desaparezca según la fila hace saltar el modal y deja al que mira
  sin saber si falta la pestaña o si no hay dato — mismo criterio que Actividad.
  **Es la diferencia con el EDITOR**, donde `Permisos` no existe si no hay
  ninguno para otorgar: allá la solapa vacía no sería un dato de la fila sino una
  pantalla sin nada que hacer.
- **`Permisos` lista SÓLO los que el perfil tiene.** El que no tiene no aparece
  —no hay fila "Deshabilitado"—: la pestaña es lo que ese acceso *puede hacer*, y
  un renglón negativo ocupa el mismo lugar que uno que habilita algo sin agregar
  nada. Sin ninguno queda el estado vacío, que es lo que distingue "no tiene
  permisos" de "la pestaña no cargó".
- **No se filtra por `puede()`**, a diferencia del editor. Allá se esconde el
  permiso que la sesión no tiene porque nadie otorga lo que no tiene; acá no se
  otorga nada, y esconderlo ocultaría un dato de la fila que se está consultando.
- **El texto de cada permiso sale de `PERMISOS_PERFIL`**, el mismo catálogo que
  dibuja el editor. Antes la ficha tenía su propia copia de la frase que dice qué
  abre cada uno; dos copias se desincronizan solas. Van **todas `full`** porque
  la lista tiene largo variable (cero a tres) y ninguna cuenta de paridad se
  sostiene.
- **`Paneles` lista el catálogo ENTERO, habilitados y no** — es la inversa de
  `Permisos` y es a propósito. El catálogo son los paneles de **un** dominio (el
  más grande de la base tiene 7) y lo que importa es **contra qué se recorta** el
  acceso: mostrando sólo los permitidos, un perfil con dos de siete se lee igual
  que uno con dos de dos. Los permisos, en cambio, son tres claves fijas que ya
  se conocen de memoria.
- **El badge de la tarjeta de panel habla del PERMISO DEL PERFIL, no del estado
  del panel**: `catalogoPaneles()` ya devuelve sólo los paneles habilitados del
  dominio (dar permiso sobre uno apagado no significa nada, porque `app` no lo
  lista igual).
- **Con el catálogo impar, la primera tarjeta va `full`** para que las que siguen
  cierren de a dos. Es la ranura impar que documenta Dispositivos → General,
  pero resuelta **en tiempo de dibujo** (`catalogo.length % 2 === 1 && i === 0`)
  porque acá el largo depende del dominio y no se puede fijar en el código.
- **No se marca el `Último abierto`** que sí muestra el editor. Ese badge existe
  allá porque avisa qué se rompe al destildar esa fila (`perfiles.panel` se va a
  `NULL`); acá no se destilda nada.

### Usuarios → modal Editar perfil (pestañas General / Permisos / Paneles)

`formPerfil()` + el `PUT` de `api/usuarios.php` (06/09/2026; la pestaña
`Permisos`, el 07/09/2026). Es el port del editor de cloud
(`openProfileModal()` + `cloud/api/profiles.php`), con las diferencias que impone
el alcance por dominio del panel.

- **No edita ningún dato de la persona: `tipo`, `habilitado`, los tres permisos
  y los paneles.** Es la línea que separa este modal del `formUsuario()` que se
  borró: los datos personales son de la cuenta, y el `nombre` / `uuid` del perfil
  los escribe la invitación que lo creó.
- **Los permisos tienen pestaña propia y no se suman a `General`.** Dos de los
  tres (`operacion`, `invitacion`) son de `app` y no del panel, así que meterlos
  junto a `tipo` y `habilitado` los haría leer como atributos de esta pantalla.
  La pestaña los agrupa y le pone a cada uno qué abre y dónde.
- **El permiso que la sesión no tiene NO SE DIBUJA** (07/09/2026). La lista sale
  de `PERMISOS_PERFIL` filtrada por `puede()`, así que quien edita sólo ve los
  que su propio perfil tiene. Antes se mostraba el switch bloqueado con el motivo
  debajo — un switch apagado que no se puede tocar sólo invita a pelearse con él.
- **Es la excepción a "visible y bloqueado"**, que sigue valiendo para el
  `habilitado` del perfil propio (más abajo). La diferencia es de quién es el
  dato que falta: ahí el switch se queda porque el dato es **del perfil que se
  está mirando** y esconderlo haría dudar de si falta el dato; acá lo que falta
  es **del que mira**, y no es información sobre la fila editada.
- **Sin ningún permiso que otorgar, la pestaña no existe**: ni la solapa ni el
  panel. Una solapa vacía se lee como una pantalla rota — el mismo criterio con
  el que `index.php` omite el agrupador `Cuenta` entero en vez de vaciarlo.
- **La pestaña no lleva texto introductorio.** Tuvo un `.form-nota` que explicaba
  que los permisos son independientes del estado y del tipo; se sacó por pedido
  explícito. Las tres filas ya dicen qué abre cada una.
- **Viajan sólo los permisos dibujados**, y los que no están no se tocan (el
  `PUT` relee de la fila lo que no viene). Hasta el 07/09/2026 iban los tres
  siempre —el bloqueado mandaba su valor sin cambios— para que el payload no
  dependiera de quién edita; desde que el switch no existe, ese valor lo
  inventaría el front en vez de elegirlo alguien, y si la fila cambió entre el
  `GET` y el `PUT` lo mandaría viejo y se comería un 403 por un cambio que nadie
  pidió.
- **El payload se arma DENTRO del handler de `Guardar`**, no al abrir el modal:
  leer los controles antes mandaría siempre los valores iniciales.
- **Las filas de permiso reusan el markup de la lista de paneles**
  (`.paneles-lista` + `.toggle-switch.panel-item`): es la misma forma y duplicar
  CSS para tres filas no compra nada. Lo único que se agregó es
  `.panel-item-nombre .muted { display: block }`, porque el segundo renglón acá
  es una frase y no el `<code>` con el id.
- **El usuario va como campo deshabilitado.** Da el contexto de *qué* acceso se
  está editando; no es un campo del formulario. Mismo recurso que usa cloud, que
  ahí muestra los selects de usuario y dominio en `disabled`.
- **NO se muestra el dominio, a diferencia de cloud.** Allá el listado cruza
  todos los dominios y el dato distingue una fila de otra; acá el panel filtra
  todo por el dominio de la sesión, así que la columna sólo puede tener un valor
  — el mismo criterio con el que se excluyó de la ficha de Consultar y de
  Dispositivos → General.
- **Las tres pestañas se llaman igual que en cloud** (`General` / `Permisos` /
  `Paneles`): es la misma ficha en dos productos, y si los rótulos no coinciden
  se leen como pantallas distintas. Se cablean con `montarPestanas()`, el helper
  que ya usan Dispositivos y Actividad.
- **`tipo` lleva una nota que aclara que no reparte permisos.** Es el `ENUM('A','O')`
  que lee el sistema histórico; ofrecerlo en un formulario sin decirlo invita a
  leerlo como el rol verdadero (ver "`perfiles.tipo`: sólo `A` y `O`").
- **El switch de `habilitado` va bloqueado sobre el perfil de la propia sesión**,
  visible y no escondido: un campo que desaparece según la fila hace dudar de si
  falta el dato o falta el permiso. El backend igual corta con 409 — la UI no es
  el control de acceso.
- **La lista de paneles es un switch por fila, sin buscador.** No reusa ningún
  selector con filtro a propósito: el catálogo son los paneles de **un** dominio
  y el más grande de la base tiene 7 (dominio 216), así que un buscador sobre
  siete filas es ruido. `Seleccionar todo` / `Deseleccionar todo` operan sobre
  toda la lista — sin buscador no hay nada oculto que puedan pisar por sorpresa.
- **El `input` va PEGADO al `.toggle-track`.** El CSS del switch (§11h) pinta el
  estado con `input:checked + .toggle-track`: cualquier nodo entre los dos lo
  deja siempre apagado. Es el error fácil de cometer al reordenar el markup.
- **Sin ningún panel tildado el perfil no ve NINGUNO**, y el modal lo dice. El
  permiso es explícito y no hay atajo a "todos" — lo hubo mientras
  `perfiles_paneles` estaba vacía, y la siembra de `20260906_1700` (3.598 filas)
  lo volvió innecesario.
- **El guardado sincroniza por diferencia, no borra y reinserta.**
  `perfiles_paneles.asignado` es la fecha en que se dio ese permiso, y reescribir
  la fila entera en cada `Guardar` la volvería la fecha del último guardado.
  Mismo criterio que las otras tablas puente del repo.
- **El catálogo de paneles viaja en el `GET ?id=N`, no en el listado.** Son los
  paneles de un solo dominio y el listado ni los usa: mandarlos por fila sería
  repetirlos hasta 1.000 veces.
- **Un id de panel que no esté en el catálogo del dominio se rechaza con 422.**
  Inexistente, deshabilitado o de otro dominio son el mismo caso desde acá. La
  base no puede expresar "el panel tiene que ser del dominio del perfil" con una
  FK, así que ésta es la única defensa: sin ella, un id a mano en el payload le
  daría a un perfil acceso al panel de otro cliente.
- **El error de guardado no cierra el modal**: lo que rebota son validaciones del
  backend, y cerrarlo perdería lo que se acaba de elegir.

#### `perfiles.panel` (singular) es una MEMORIA, y el editor la toca de rebote

No confundirla con `perfiles_paneles`. **`perfiles.panel` guarda el último panel
que ese perfil abrió en `app`, y es también el que `app` le reabre al
conectarse.** La escribe `app/api/paneles.php` cada vez que la persona cambia de
panel, y sólo con uno que ya pasó por `appPanelesDelDominio()` — o sea que la
invariante que `app` mantiene es *"`panel` es siempre uno de los permitidos"*.

**Este endpoint es el único que puede romperla**, porque es el único que revoca
paneles desde afuera de `app`. Por eso el guardado la limpia
(`olvidarPanelSinPermiso()`):

- **Si el guardado le quita el permiso sobre el panel recordado, `panel` va a
  `NULL`.** Sin eso queda un puntero a algo que el perfil ya no puede abrir.
- **A `NULL` y no al primero de la lista.** Es una memoria, no una preferencia:
  elegirle uno sería inventarle al perfil una decisión que nadie tomó. Para la
  persona no cambia nada — `appPanelesDelDominio()` ya cae al primer panel
  permitido cuando el recordado no está en la lista, y con `panel` vacío hace
  exactamente lo mismo—, así que lo único que se gana es no dejar un dato que
  miente.
- **`NULL` y no `0`**: con las FK declaradas en `db/schema.sql`, el `0` del
  legacy ya no es un valor válido. Mismo criterio con el que lo escribe
  `panel/invitacion/aceptar.php`.
- **La UI lo marca con el badge `Último abierto`** sobre la fila que corresponde,
  y la nota de la pestaña avisa que destildarla borra esa memoria. **No es una
  tercera opción del switch**: es información sobre qué pasa si se destilda esa
  fila, no algo que se elija desde acá.

### Módulos de ficha única

No todo módulo es un ABM. Cuando el dominio tiene **un solo registro** de ese
recurso (hoy: Dominio y Facturación, la ficha fiscal de `clientes` a la que
apunta `dominios.cliente`) no hay listado ni alta ni baja: se consulta en la
propia pantalla y, si el recurso es del cliente, se edita en un modal.
Estructura fija:

- Tarjeta de ayuda del skill `abm_design` arriba y, debajo, la ficha. **Sin
  toolbar**: a diferencia de los ABM, estas pantallas no llevan el botón ícono
  `Refrescar` sobre la tarjeta — la ficha se carga sola al entrar (y al
  guardar, en las editables), así que el botón sólo agregaba ruido.
- **Una sola tarjeta grande** (`.form-card`, CSS §8e) con toda la ficha. El
  título va en `.form-card-head`; adentro, cada campo es una **tarjeta chica
  más oscura** (`.view-card`, las mismas del modal de Consultar de los ABM),
  media o full según el largo del valor.
- **Pie de la tarjeta** (`.form-card-foot`, acciones a la derecha) **sólo en
  las fichas editables**, y con un solo botón: `Editar`. Las fichas de sólo
  lectura (Dominio) no llevan pie: sin acciones, la línea divisoria queda
  vacía.
- **La pantalla NUNCA se convierte en formulario: `Editar` abre un modal**
  (06/09/2026). La ficha se queda en lectura detrás, y al guardar se repinta
  con lo que devolvió el `PUT`. Hasta esa fecha la edición era en la propia
  pantalla —las mismas tarjetas cambiaban el valor por un control y el pie
  pasaba a `Cancelar` + `Guardar`—, y era la única pantalla del panel donde
  editar no abría un modal: sin el fondo gris ni el marco, lo único que
  distinguía lectura de edición era la forma de los controles, y el
  formulario no tenía un límite visual que dijera que estaba abierto.
- **El formulario del modal usa `.form-group` / `.form-row`, como todos los
  demás modales de edición del panel** (Editar dispositivo, Invitar usuario).
  **No reusa las `.view-card` de la ficha**: hasta el 06/09/2026 lo hacía
  —`editCard()` armaba la misma tarjeta oscura con un control adentro— y era la
  única pantalla del panel donde un input venía envuelto en una caja. La
  tarjeta oscura es del **modo consulta**; el modo edición son label + control
  sobre el fondo del modal. `editCard()` se eliminó al no quedarle call sites.
- **El `<form>` lleva `.form-stack`** (flex column, `gap: 16px`): es hijo único
  del `.modal-body`, así que el `gap` del body no separa nada y sin esa clase
  los `form-group` de adentro quedan pegados.
- **El botón `Guardar` va en la barra de acciones del modal, que `openModal()`
  dibuja FUERA del `<form>`**: por eso lleva `form="fc-form"` (el atributo de
  HTML5 que le da dueño a un control externo). Sin eso el submit no dispara y
  habría que duplicar el guardado en un listener de click. Enter dentro de
  cualquier campo entra por el mismo camino.
- **El error de guardado se muestra dentro del modal y el modal no se cierra**:
  lo que rebota son validaciones del backend (CUIT, razón social) y cerrarlo
  perdería lo tipeado.

El endpoint **no acepta ningún id**: resuelve el registro desde el dominio de
la sesión, para que no se pueda leer ni escribir la ficha de otro dominio.
Expone `GET` + `PUT` si la ficha es del cliente (Facturación) y sólo `GET` si
el recurso lo administra Reactor (Dominio).

#### Dominio

`api/dominio.php` + `renderDominio()` portan
`reactor-panel/dominio/inicio.php`, la ficha del dominio con el que está
conectada la sesión. Reglas que no se deducen del esquema:

- **Es de sólo lectura**: el alta y la edición del dominio son del back
  office interno (`reactor-admin`), no del cliente. El endpoint no tiene
  `PUT` ni `DELETE`.
- **No se porta "Desconectar dominio"** del legacy: dejaba la sesión sin
  dominio, y acá `requireDominioId()` corta con 409 en ese estado. Sí se
  porta "Conectar" — ver abajo.

#### Cambiar dominio (`api/dominios.php`)

Porta `reactor-panel/sesion/cambiar.php` + `cPerfil::cargar()` del legacy
(`reactor-api/framework/subframework.php`). `GET` lista, `POST {perfil}`
cambia. Reglas que no se deducen del esquema:

- **La disponibilidad la define `perfiles`, y sólo los de tipo Administrador.**
  La cuenta puede pasar a un dominio si existe una fila habilitada
  `perfiles(usuario, dominio)` con `tipo = 'A'`. Es la misma regla con la que
  entra al panel (ver
  "Acceso: sólo Administradores"), porque el selector no puede ofrecer un
  destino que después el gate vaya a rechazar: un dominio donde la cuenta es
  Operadora simplemente no aparece. La consulta es `perfilesHabilitados()` de
  [lib/acceso.php](lib/acceso.php), la misma que usa el login para elegir con qué
  perfil arranca la sesión. `usuarios.dominio` es sólo el dominio **activo** — el
  que viaja en el JWT y por el que filtra todo el panel — y no es la lista de
  dominios permitidos.
- **Ya no se lista el dominio activo sin perfil propio.** Existía porque
  `usuarios.dominio` lo puede asignar el back office interno sin crear fila en
  `perfiles` (el usuario 3 está así en `OSSE San Juan`), y se mostraba —
  primero y no elegible— para que la sesión en curso no faltara de la lista.
  Con el gate resolviéndose contra `perfiles`, esa sesión no puede existir:
  `requirePerfilValido()` no la deja llegar al endpoint. **Todas las filas son
  elegibles**, así que el front perdió la rama del `<div>` no clickeable.
- **Una fila por perfil, no por dominio**: lo que se elige es un perfil. La
  misma cuenta puede tener varios en el mismo dominio (el usuario 3 tiene cuatro
  en `Reactor`), y `usuarios.perfil` guarda cuál se
  eligió, así que agrupar por dominio dejaría el click ambiguo. Es como
  lista el legacy.
- **Qué se asienta**: `usuarios.perfil` (último perfil) y `usuarios.dominio`
  (último dominio). El legacy escribe **sólo `perfil`** porque deriva el
  dominio de `perfiles.dominio` en cada arranque de sesión; el panel lo lee
  de `usuarios.dominio`, así que hay que escribir las dos para que las dos
  lecturas coincidan.
- **"Reiniciar la sesión sin credenciales" = reemitir el JWT**: el `POST`
  revalida la cuenta como el login (`usuarios.habilitado`), firma un token
  nuevo sobre la misma cookie y el front recarga. No hay sesión PHP que
  reescribir, a diferencia del legacy.
- **Los filtros por `p.usuario` y `p.rol` en el `POST` son el control de
  acceso**: sin el primero, un id de perfil a mano mueve la sesión a cualquier
  dominio del sistema; sin el segundo, la mueve a un dominio donde la cuenta es
  Operadora. Esconder el perfil de la lista **no alcanza**: los dos filtros son
  el límite entre esto y una escalada.
- **`perfiles.habilitado` es `1` / `0`**, igual que `usuarios.habilitado`. Un
  perfil deshabilitado es un acceso revocado y no se lista.
- **El dominio deshabilitado se lista y se puede elegir**, con badge: el
  legacy no mira `dominios.habilitado` y hoy 95 de 148 dominios están en 0,
  así que bloquearlos le sacaría al usuario accesos que viene usando.
- La etiqueta de la fila es `perfiles.nombre` ("Administrador en Reactor").
  Hasta el 06/09/2026 era el nombre del rol, que describía mejor la fila sin
  repetir el dominio; al eliminarse `perfiles.rol` quedó esto o nada.
- **No se porta el manejo de `perfiles.panel`** que hace el legacy al
  cambiar (asignarle un panel del dominio si está en 0): este panel no usa
  la tabla `paneles` en ninguna pantalla.
- **Los contadores se cuentan, no se leen**: `usuarios` / `dispositivos` /
  `chips` salen de `COUNT(*)`, no de las columnas cacheadas
  `dominios.usuarios` / `.dispositivos` / `.chips` que sí usaba el legacy —
  las mantiene el sistema viejo y están desfasadas (el dominio 2 declara 18
  usuarios y tiene 5). Misma decisión que `api/dashboard.php`.
- **La situación se traduce por `combos`** con la clave
  `'$xDominio->situacion'` (1 Normal / 2 Limitado / 3 Suspendido), igual que
  `comboTraducir()` en el legacy, con fallback en el endpoint. El tono del
  badge lo elige el front (`success` / `warn` / `danger`).
- **La ficha no muestra el id del dominio en ningún lado**: no hay campo
  `Código` y la cabecera de la tarjeta es sólo el título `Datos del dominio`,
  **sin `.form-card-hint`** (a diferencia de Facturación). Tampoco se muestra
  `Número`. Son datos internos del sistema viejo que al cliente no le dicen
  nada, y el dominio activo ya se identifica por su nombre en el propio campo
  `Nombre` y en el botón de usuario de la topbar.
- Las dos filas usan `.view-card-third` (CSS §11b), la variante de tres
  tarjetas por fila que se agregó para este módulo: arriba
  `Nombre` / `Situación` / `Estado` y abajo el inventario
  (`Usuarios` / `Dispositivos` / `Chips`).
