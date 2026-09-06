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

## Bump de version.txt

Al tocar cualquier archivo bajo `panel/assets/css/` o `panel/assets/js/` hay
que incrementar `panel/version.txt` o el browser sirve caché vieja
(los assets se cargan con `?v=<contenido de version.txt>`).

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
su cuenta. Hoy lo usan los dos caminos de alta que existen:

- `api/usuarios.php` → `handleCreate()` (alta manual del BackOffice)
- `invitacion/aceptar.php` (alta al aceptar una invitación)

Reglas que la función garantiza, y que por eso no hay que repetir en los llamadores:

- Recibe la contraseña **en claro** y la cifra adentro con el cifrado legacy
  (`api/legacy_crypto.php`). Ningún camino elige su propio cifrado.
- **Forma fija del alta.** Todo usuario nace con estos valores, sin importar lo
  que mande el llamador:

  | columna | valor |
  |---|---|
  | `autenticacion` | `'F'` |
  | `habilitado` | `'1'` |
  | `perfiles` | `0` |
  | `dominios` | `''` |
  | `paneles` | `''` |
  | `panel` | `NULL` |

  Están declarados como `USUARIO_*_INICIAL` en el mismo archivo. Las plurales
  **no** son el par de las singulares: `perfil` y `dominio` sí reciben el id real
  que se les pase. `roles` arranca en `''` salvo que el llamador mande otro valor.
- Resto de los defaults: `uuid` aleatorio y `registrado = NOW()`.
- **Consecuencia:** el campo *habilitado* del formulario no tiene efecto al crear
  (siempre nace `'1'`); recién se respeta al editar.
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

El pie del modal tampoco lleva el botón ☰ de "Más acciones" que sí tienen los
otros módulos: sus opciones (copiar identificador / MAC / coordenadas,
habilitar-deshabilitar) están todas en el menú contextual de la fila, y dos de
las tres de copiar apuntaban a campos que la ficha ya no muestra. El pie queda
en `Cerrar` + `Editar`.

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
  `api/usuarios.php?id=N` y `api/dispositivos.php?id=N`, que ya tienen la
  ficha curada — pero los dos filtran por **`dominio`, que en esas tablas es
  el dominio ACTUAL**: `usuarios.dominio` es el dominio *activo* de la cuenta
  (cambia cada vez que la persona usa *Cambiar dominio*) y
  `dispositivos.dominio` es el dueño *de turno* (liberar mueve el equipo al
  dominio 1). Un registro de hace seis meses puede apuntar a un usuario que
  desde entonces se pasó a otro dominio o a un equipo que se liberó, y esas
  dos consultas devolverían **404 sobre actividad perfectamente válida**. El
  control de acceso ya lo dio `r.dominio = :dom` en el registro.
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

- **El pie del modal de Consultar es sólo `Cerrar`.** No lleva el botón ☰ de
  "Más acciones" que sí tienen otros módulos: sus tres opciones (filtrar por
  este usuario, filtrar por este dispositivo, copiar detalle) ya viven en el
  menú contextual de la fila, que es desde donde se abre el modal. Tampoco hay
  acción primaria: `api/actividad.php` sólo responde `GET`.
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
- **El perfil nuevo va con `rol` y `panel` en `NULL`, no en `0`.** El legacy
  (`cPerfil::nuevo()`) escribe `0`, que con las FK declaradas en
  `db/schema.sql` ya no es un valor válido. `perfiles.habilitado` es
  `'1'`/`'0'`, mientras que `usuarios.habilitado` es `'S'`/`'N'` — no
  confundirlos.
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
- **El pie es sólo `Cerrar`**: sin acción primaria (una invitación emitida no
  se edita) y **sin el botón ☰ de "Más acciones"** — filtrar por emisor,
  filtrar por estado y copiar ya viven en el menú contextual de la fila, que
  es desde donde se abre el modal. Mismo recorte que Actividad y Usuarios.

### Usuarios → el alta es una invitación, y no hay edición

El módulo se recortó a **consultar, habilitar/deshabilitar y eliminar**
(03/09/2026). Reglas que no se deducen del esquema:

- **`+ Nuevo usuario` no da de alta: invita.** El botón abre
  `formInvitacion()`, el mismo modal del módulo Invitaciones —un solo campo, el
  correo— y el `POST api/invitaciones.php` encola el envío. La cuenta la crea
  el propio invitado al aceptar. **Si esa persona ya está registrada no se
  duplica el usuario**: `perfilAsegurado()` en `invitacion/aceptar.php` le
  agrega el perfil de este dominio y le deja intactos la contraseña y el
  dominio activo. Por eso el alta no necesita backend propio — el circuito de
  invitaciones ya resuelve los dos casos.
- **No queda ningún camino a `formUsuario()`.** Se le sacó `Editar` al menú
  contextual de la fila y también el botón primario del modal de Consultar, y
  el alta pasó a ser la invitación: la función quedó **sin call sites**, y con
  ella el `POST` / `PUT` de `api/usuarios.php`, que siguen en el endpoint pero
  no los usa ninguna pantalla — salvo el `PUT`, que dispara `toggleUsuario()`.
- **`handleUpdate()` reescribe la fila entera**: el payload tiene que mandar
  `roles` y `habilitado` tomados del registro que trae el `GET`, o el guardado
  borra los roles y deshabilita la cuenta. `toggleUsuario()` ya lo hace así y
  `formUsuario()` también — tenerlo presente si alguna vez se repone la
  edición.
- **Menú contextual de la fila**: `Consultar` → `Habilitar` / `Deshabilitar` →
  separador → `Eliminar`. Se aparta del orden del skill `abm_design`, que
  intercala `Editar` antes de la baja.
- **El modal de Consultar usa `wide: 'xl'`** (`.modal-xl`, 1040px = el doble
  del ancho base), no el `modal-wide` de 880px de los dumps y las tablas.
- **El pie de Consultar es sólo `Cerrar`**: sin botón ☰ de "Más acciones"
  —copiar usuario / correo y habilitar-deshabilitar viven en el menú de la
  fila— y sin acción primaria `Editar`.
- **La ficha no muestra `id`, `autenticacion`, `roles`, `panel` ni `dominio`.**
  El id ya encabeza el modal (`Consultar usuario #N`); `autenticacion`, `roles`
  y `panel` son internos del sistema histórico; y `dominio` sólo puede tener un
  valor, porque el panel filtra todo por el dominio de la sesión — el mismo
  criterio con el que se excluyó de Dispositivos → General.
- **Abre con `Identificador` (el `uuid`) y cierra con `Estado`.** El uuid ocupa
  la ranura donde estaba `Código`, con ese rótulo y no "UUID". Son 10 tarjetas,
  **todas `half`**: cinco renglones que cierran de a dos. `Nombre` dejó de ser
  `full` al sacar `dominio` — con 10 campos la fila entera desbalanceaba la
  grilla, y a 1040px de modal media tarjeta le sobra ancho. **Agregar o quitar
  un campo deja la cuenta impar** y estira la última a todo el ancho, que se
  lee como un destaque deliberado (mismo criterio que Dispositivos → General).

### Módulos de ficha única

No todo módulo es un ABM. Cuando el dominio tiene **un solo registro** de ese
recurso (hoy: Dominio y Facturación, la ficha fiscal de `clientes` a la que
apunta `dominios.cliente`) no hay listado ni alta ni baja: se consulta —y, si
el recurso es del cliente, se edita— en la propia pantalla. Estructura fija:

- Tarjeta de ayuda del skill `abm_design` arriba y, debajo, la ficha. **Sin
  toolbar**: a diferencia de los ABM, estas pantallas no llevan el botón ícono
  `Refrescar` sobre la tarjeta — la ficha se carga sola al entrar (y al
  guardar, en las editables), así que el botón sólo agregaba ruido.
- **Una sola tarjeta grande** (`.form-card`, CSS §8e) con toda la ficha. El
  título va en `.form-card-head`; adentro, cada campo es una **tarjeta chica
  más oscura** (`.view-card`, las mismas del modal de Consultar de los ABM),
  media o full según el largo del valor.
- **Pie de la tarjeta** (`.form-card-foot`, acciones a la derecha) **sólo en
  las fichas editables**: en modo lectura, `Editar`; al entrar en edición las
  mismas tarjetas cambian el valor por un control **sin mover la
  distribución**, y el pie pasa a `Cancelar` + `Guardar`. En edición la
  tarjeta es un `<form>`, así Enter guarda como el submit del legacy. Las
  fichas de sólo lectura (Dominio) no llevan pie: sin acciones, la línea
  divisoria queda vacía.

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

- **La disponibilidad la define `perfiles`, no `usuarios.dominio`**: la
  cuenta puede pasar a un dominio si existe una fila habilitada
  `perfiles(usuario, dominio)`. `usuarios.dominio` es sólo el dominio
  **activo** — el que viaja en el JWT y por el que filtra todo el panel — y
  puede no tener perfil propio (el usuario 3 está en `OSSE San Juan` sin
  fila en `perfiles`), así que se lista igual, primero y **no elegible**: sin
  perfil no hay nada que asentar en la cuenta.
- **Una fila por perfil, no por dominio**: lo que se elige es un perfil. La
  misma cuenta puede tener varios en el mismo dominio con distinto rol (el
  usuario 3 tiene cuatro en `Reactor`), y `usuarios.perfil` guarda cuál se
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
- **El filtro por `p.usuario` en el `POST` es el control de acceso**: sin él,
  un id de perfil a mano mueve la sesión a cualquier dominio del sistema.
- **`perfiles.habilitado` es `'1'` / `'0'`**, no `'S'` / `'N'` como
  `usuarios.habilitado`. Un perfil deshabilitado es un acceso revocado y no
  se lista.
- **El dominio deshabilitado se lista y se puede elegir**, con badge: el
  legacy no mira `dominios.habilitado` y hoy 95 de 148 dominios están en 0,
  así que bloquearlos le sacaría al usuario accesos que viene usando.
- **No se filtra por `perfiles.tipo`** aunque el legacy sí (`tipo="A"`, con
  el mensaje "Requiere rol de administrador"): `tipo` y `rol` están
  desalineados en los datos (el perfil 456 es `tipo='O'` con `rol=101`
  Administrador) y este panel no gatea el login por tipo en ningún otro
  lado. Si alguna vez hace falta restringirlo, el criterio confiable es
  `rol`, no `tipo`.
- El rol (`roles.nombre`, "Administrador") es la etiqueta de la fila, no
  `perfiles.nombre` ("Administrador en Reactor"), que repite el nombre del
  dominio que ya encabeza la tarjeta.
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
