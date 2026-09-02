# panel

BackOffice administrativo de Reactor. Vive junto a `cloud/` en el mismo repo y
comparte con él la infraestructura de auth: tabla `usuarios`, cifrado legacy
(`api/legacy_crypto.php`, clave global `0123456789`), APP_KEY_CLOUD y la cookie
`reactor_cloud_token`. Un usuario logueado en cloud queda logueado en panel
sin re-ingresar (siempre que compartan dominio raíz o corran sobre el mismo
host en dev).

## Dominios

- Prod: `panel.reactor.com.ar` (nginx proxea al puerto 8087 del contenedor
  `reactor-apache`).
- Dev: `http://localhost:8087`.

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

## Módulos

El shell está pensado para poblarse por módulos. Cada nuevo módulo se agrega
como sub-ítem del sidebar (dentro de una categoría con emoji) y registra su
renderer en `routes` de `assets/js/app.js`. Los módulos ABM, el módulo
Herramientas y sus utilidades tienen sus propias skills dedicadas
(`abm_design`, `crear_modulo_herramientas`, etc.) — respetalas cuando
implementes cada uno.

### Dispositivos → modal Consultar → pestaña Conexión

`api/dispositivo_conexion.php` + `vistaConexion()` agregan al modal de
Consultar una segunda pestaña con la serie del nivel de señal del equipo
(la primera, **General**, es la ficha completa de siempre). Reglas que no se
deducen del esquema:

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
- **Las bandas caen en números redondos de esa escala**: 50% es exactamente
  -50 dBm y 25% exactamente -70 dBm, los dos cortes clásicos de señal WiFi.
  Por eso son tres (Buena / Regular / Débil) y por eso las guías del gráfico
  van en -70 / -50 / -30.
- **El color de la barra nunca es el único portador**: cada fila lleva además
  el ícono de la banda y el valor en dBm, y el largo ya dice lo mismo. Es un
  requisito de accesibilidad, no decoración — verde y ámbar son
  indistinguibles con daltonismo protán.
- **La consulta va acotada a las últimas 5.000 señales *de ese dispositivo*,
  no a una ventana global de ids**: `senales` sólo tiene PK y FKs, así que el
  `LIKE` sobre `mensaje` se resuelve fila por fila. Sin la cota, el equipo más
  cargado (348K señales en dev) tardaba 1,6 s cuando no reportaba señal; con
  ella, 0,05 s. La ventana es relativa al equipo para que también sirva a los
  que reportan poco y hace meses.
- **La pestaña se carga recién al abrirla** (y sólo una vez): la consulta es
  cara y la mayoría de las consultas al dispositivo no la miran.
- **`dispositivos.senal` es varchar y arrastra valores escritos a mano**
  ("-59dB alta", 2 de 250 filas en dev): se toma el entero con signo del
  principio, no `is_numeric()` sobre el texto entero.

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
