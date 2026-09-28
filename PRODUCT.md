# Reactor — cómo funciona el sistema

Documento de producto: **qué es Reactor, quién lo usa y cómo se mueve un dato
desde que alguien aprieta un botón en el celular hasta que un relé cambia de
estado** — y al revés. No es un manual de estilo ni un plan de trabajo: describe
el sistema tal como funciona hoy.

Los detalles de implementación que no se deducen del código viven en los
CLAUDE.md de cada app ([CLAUDE.md](CLAUDE.md), [panel/CLAUDE.md](panel/CLAUDE.md),
[cloud/CLAUDE.md](cloud/CLAUDE.md), [www/CLAUDE.md](www/CLAUDE.md)) y el diseño
visual en [cloud/DESIGN.md](cloud/DESIGN.md). Acá va el funcionamiento.

---

## 1. Qué es Reactor

Reactor es una **plataforma IoT de control remoto**: equipos instalados en la
calle (portones, luces, bombas, sensores de apertura, temperatura) que se
comandan y se monitorean desde el celular.

El producto se vende por **dominio** — la unidad de facturación y de aislamiento:
un consorcio, un barrio privado, un comercio. Dentro de un dominio hay
dispositivos, usuarios y paneles de operación. Un usuario puede tener acceso a
varios dominios y moverse entre ellos.

En números de producción: ~147 dominios, ~2.030 cuentas de usuario, ~2.060
perfiles habilitados, ~250 dispositivos repartidos entre dos brokers MQTT, ~2,95 M
de filas en `registros` y ~725 K señales vivas.

El sistema tiene cuatro caras, y cada una atiende a un público distinto:

| Cara | Para quién |
|---|---|
| **App** | el usuario final: los botones que operan los equipos |
| **Panel** | el administrador del dominio: administra *su* dominio |
| **Cloud** | los operadores de Reactor: administran la plataforma entera |
| **Sitio público** | cualquiera: marca, planes, blog, ayuda, técnicos |

---

## 2. Las seis puertas (docroots)

Un solo contenedor Apache sirve cuatro sitios, **ruteando por puerto** (no por
`Host`), con el mismo número adentro y afuera del contenedor y el mismo número
en desarrollo y en producción ([docker/vhosts.conf](docker/vhosts.conf),
[docker/ports.conf](docker/ports.conf)).

| Puerto | Carpeta | Producción | Qué es | Quién entra |
|---|---|---|---|---|
| 8086 | [cloud/](cloud/) | `cloud.reactor.com.ar` | Back office de Reactor | Operadores de Reactor (`controladores`) |
| 8087 | [panel/](panel/) | `panel.reactor.com.ar` | Back office del cliente | Administradores del dominio (`perfiles.tipo = 'A'`) |
| 8115 | [app/](app/) | `app.reactor.com.ar` | PWA del usuario final | Cualquier perfil habilitado |
| 8134 | [www/](www/) | `www.reactor.com.ar` + apex | Sitio público | Cualquiera, sin login |
| — | [api/](api/) | — | Reservado: futura API pública. Hoy es un stub con `.htaccess` y `supervisor.php` | — |
| — | [robot/](robot/) | — | Reservado, mismo estado que `api/` | — |

Más dos componentes que no son web:

| Componente | Qué hace |
|---|---|
| [motor/](motor/) | Worker Python (`reactor-motor`) suscripto al broker MQTT: escucha lo que reportan los equipos |
| [firmware/](firmware/) | Sketches Arduino para ESP8266 y ESP32 — el software que corre adentro de los equipos |

Alias de producción que resuelven al mismo vhost: `pwa.`, `newapp.` y `webapp.`
apuntan a `app`; `reactor.com.ar` (apex) apunta a `www`. `control.` no se proxea:
nginx lo redirige con 301 a `panel.`.

Cada docroot tiene su `version.txt`, que el deploy estampa con `1.0.<timestamp>`
y que se usa como cache-bust (`?v=…`) de todo el CSS y el JS. Tocar un asset sin
subir `version.txt` deja al navegador sirviendo la copia vieja.

---

## 3. El modelo de datos, en una pasada

```
clientes ──< contratos ──< dominios ──< paneles ──< controles ──< botones
                              │                         │            │
                              ├──< dispositivos ──< canales <─────────┘
                              │         │
                              │         └── transceptor (broker MQTT), chip (SIM),
                              │             modelo, producto, adopcion
                              ├──< perfiles >── usuarios
                              │       └──< perfiles_paneles
                              ├──< chips
                              ├──< invitaciones
                              └──< registros        (quién apretó qué)

dispositivos ──< senales     (qué se mandó y qué contestó el equipo)
```

**Los cinco conceptos que hay que entender:**

- **`dominios`** — el inquilino. Todo se filtra por él. Tiene `situacion`
  (`'1'` normal, `'2'` limitado, `'3'` suspendido) y contadores denormalizados
  (`usuarios`, `dispositivos`, `chips`).
- **`dispositivos`** — el equipo físico. Su `identidad` es el topic MQTT, su
  `transceptor` dice a qué broker está conectado, su `enlace` dice si está en
  línea y `latido`/`conexion` cuándo se lo vio por última vez. La flota está
  **repartida entre dos brokers** (242 equipos en uno, 8 en otro), así que no hay
  un único host que los alcance a todos.
- **`canales`** — cada salida o entrada del equipo (un relé, un sensor).
  `canales.estado` es **la única fuente de verdad del tablero**, y la escribe el
  motor MQTT cuando el equipo reporta. `modulos.tipo` separa actuador (`'A'`) de
  sensor (`'S'`).
- **`paneles` → `controles` → `botones`** — la pantalla que ve el usuario. Un
  panel agrupa controles (uno por dispositivo, con nombre y color), y cada
  control tiene hasta 10 botones. `botones.accion` es `'1'` encender, `'0'`
  apagar, `'2'` invertir.
- **`perfiles`** — el acceso de *una cuenta* a *un dominio*. No es un rol global:
  la misma persona puede ser Administrador en un dominio y Operador en otro, con
  permisos distintos en cada uno. Es la tabla que gobierna todo el control de
  acceso de `panel` y `app`.

### Dos historiales, que no son lo mismo

| tabla | qué registra | quién escribe |
|---|---|---|
| `senales` | el mensaje que viajó por MQTT (`CMD=…` saliente, `REP=…`/`RET=…` entrante) | la app al publicar; el motor al recibir |
| `registros` | **quién** apretó qué botón, en qué dominio y sobre qué canal | sólo las apps, al operar |

`senales` no tiene columna `dominio`: el único camino al inquilino es
`senales.dispositivo → dispositivos.dominio`. Ninguna de las dos tiene índice por
`fecha`, así que **toda consulta se acota primero por PK** (`id > MAX(id) - ventana`)
y recién después filtra — sin eso una búsqueda vacía tarda segundos.

### Convenciones transversales del esquema

- **`habilitado` es `tinyint(1) NOT NULL DEFAULT 0` y vale 0 o 1, nada más.**
  Se lee con `esHabilitado()` y se escribe con `valorHabilitado()`; en SQL va el
  entero. Vale para toda columna con ese nombre, en todas las tablas y todas las
  apps.
- **El `0` es un centinela de "sin asignar", no una referencia.** Las migraciones
  de FK tuvieron que convertirlo a `NULL` antes de poder declarar las constraints.
- Hay otros centinelas vivos: `adopciones.liberado = '1500-01-01'` significa
  "todavía en curso".
- Todo es **InnoDB + utf8mb4_unicode_ci**. Esa collation es lo que hace que la
  búsqueda ignore acentos y mayúsculas sin que la query haga nada.
- Producción es MariaDB 10.11 sobre RDS (base `reactor`); desarrollo es MySQL 8.0
  (base `reactor_dev`, copia de producción).

---

## 4. El circuito principal: apretar un botón

Es el corazón del producto. Lo implementa [app/api/boton.php](app/api/boton.php).

```
  [celular]                    [app 8115]                  [broker MQTT]      [equipo]
      │                             │                            │                │
      │  POST api/boton {boton:N}   │                            │                │
      ├────────────────────────────>│                            │                │
      │                             │ 1. ¿sesión válida?         │                │
      │                             │ 2. ¿permiso `operacion`?   │                │
      │                             │ 3. botón→canal→dispositivo │                │
      │                             │    filtrado por dominio    │                │
      │                             │ 4. ¿canal y equipo habilitados?             │
      │                             │ 5. CMD=CEN|CNL=3           │                │
      │                             ├───────────────────────────>│                │
      │                             │    topic: $<identidad>     ├───────────────>│
      │                             │ 6. INSERT senales (S)      │                │
      │                             │ 7. INSERT registros        │                │
      │  {ok:true, accion:'1'}      │                            │                │
      │<────────────────────────────┤                            │                │
      │                             │                            │   REP=CEN      │
      │                             │                     [motor MQTT] <──────────┤
      │                             │                            │                │
      │                             │              UPDATE canales.estado           │
      │  GET api/canales (sondeo)   │                            │                │
      ├────────────────────────────>│                            │                │
      │  estado real del canal      │                            │                │
      │<────────────────────────────┤                            │                │
```

**Las cuatro decisiones que definen el comportamiento:**

1. **El endpoint no toca `canales.estado`.** Y es a propósito: si lo escribiera
   al mandar la orden, un equipo desconectado se vería encendido en la pantalla
   sin que la luz haya cambiado. El estado lo escribe el motor **recién cuando el
   equipo reporta que ejecutó**. El tablero dice la verdad o no dice nada.
2. **La señal se registra DESPUÉS de publicar.** Si el broker no contesta, no
   queda escrito que la orden salió, y el usuario ve el error (502).
3. **El broker sale de `transceptores`, no del entorno.** Cada equipo dice a qué
   broker está conectado. La variable `MQTT_ORIGEN` permite redirigir todo al
   broker de las `MQTT_*` (`env`) — que es el **default en desarrollo**, porque la
   base de dev es copia de producción y un botón apretado desde una máquina de
   pruebas le encendería la luz a un cliente real.
4. **El publisher MQTT es propio, de ~280 líneas** ([app/lib/mqtt.php](app/lib/mqtt.php)):
   CONNECT + PUBLISH QoS 0 + DISCONNECT sobre `stream_socket_client()`, sin
   Composer y sin extensiones. Lee el CONNACK — sin eso, unas credenciales mal
   puestas se verían como un envío exitoso.

**El protocolo de los equipos:**

| mensaje | sentido | significado |
|---|---|---|
| `CMD=CEN\|CNL=<n>` | saliente (`S`) | encender el canal n |
| `CMD=CAP\|CNL=<n>` | saliente (`S`) | apagar el canal n |
| `RET=…` | entrante (`E`) | respuesta del equipo a una orden |
| `REP=CEN` / `REP=CAP` | entrante | el equipo confirma el cambio de estado |
| `REP=LAT` / `REP=CNX` / `REP=INI` | entrante | latido, conexión y arranque |
| `REP=SNS` / `REP=CAP` / `REP=CEN` | entrante | reportes periódicos de sensores |

El topic es `$` + `dispositivos.identidad` al publicar, y se guarda **sin el `$`**
en `senales.topic`.

Por eso el gráfico "Uso por dispositivo" del panel cuenta **sólo `CMD=`**: el
resto de la tabla es telemetría que llega sin que nadie toque el equipo. Y cuenta
la orden y no la respuesta, porque lo que mide es cuánto se *operó* el equipo — un
equipo que dejó de contestar tiene que seguir apareciendo como usado.

---

## 5. Autenticación: tres sistemas distintos, a propósito

No hay un SSO. Cada app tiene su propia clave (`APP_KEY_<NOMBRE>`) para que un
leak en una no comprometa las sesiones de las otras.

| | `cloud` | `panel` | `app` |
|---|---|---|---|
| tabla | `controladores` | `usuarios` | `usuarios` |
| credencial | `correo` | `usuario` o `correo` | **`celular` o `correo`** |
| cookie | `reactor_cloud_token` | `reactor_cloud_token` (compartida) | `reactor_app_token` |
| firma | `APP_KEY_CLOUD` | `APP_KEY_CLOUD` | `APP_KEY_APP` |
| TTL | 12 h | 12 h | **1 año** |
| login | 1 paso | 1 paso | **2 pasos** |

- **Todos son JWT HS256 stateless**, con una implementación propia de ~70 líneas
  en el `lib/jwt.php` de cada app. Sin Composer. Stateless significa que **no hay
  forma de revocar un token antes de su `exp`** salvo rotar la clave.
- **`cloud` y `panel` comparten cookie**: quien se loguea en uno queda logueado en
  el otro. Pero `cloud` autentica contra `controladores` (los operadores de
  Reactor) y `panel` contra `usuarios` (los clientes), así que un token de cloud
  lleva `src: 'ctl'` y `authUser()` de cloud rechaza el que no lo traiga.
- **El login de `app` son dos pasos** porque hay dos modos de autenticación en la
  base: `'F'` fija (contraseña, ~2.050 cuentas) y `'T'` temporal (código de 6
  dígitos, ~29 cuentas). El primer paso identifica la cuenta y el segundo decide
  la pantalla.
- **La sesión de `app` dura un año y se renueva en cada visita.** Es una PWA
  instalada en el celular: una sesión que se cierra sola sería una regresión de
  producto.
- **Las contraseñas usan un cifrado reversible**, no un hash. `usuarios.contrasena`
  es `varchar(50)` y guarda el base64 del cifrado, lo que impone el **máximo de 36
  caracteres** en toda pantalla que la escriba.

**Los gates se resuelven contra la base en cada request, nunca contra un claim
del JWT.** Revocar un acceso tiene efecto en el request siguiente y no al vencer
el token — que puede faltar 12 h, o un año en el caso de `app`.

---

## 6. Autorización: cuatro escalones

### Escalón 1 — ¿la cuenta está viva?
`usuarios.habilitado = 1`. Deshabilitar la cuenta la saca de las tres apps.

### Escalón 2 — ¿hay un perfil habilitado en este dominio?
El dominio de la sesión **no sale de `usuarios.dominio`** sino del perfil activo:
`usuarios.perfil → perfiles.dominio`. Si el perfil recordado no sirve, se cae al
primero habilitado. Un perfil deshabilitado es cómo se revoca un acceso.

> Ojo con una trampa del esquema: nada impide que `usuarios.perfil` quede
> apuntando al perfil de **otra** persona (la FK sólo exige que el perfil exista).
> Por eso toda consulta del perfil activo agrega `p.usuario = :u`.

### Escalón 3 — ¿entra a esta app?
- **`panel`**: sólo `perfiles.tipo = 'A'` (Administrador). El Operador queda
  afuera, y es el perfil más común por lejos — medido: sin gate entraban 2.063
  perfiles, con gate entran 404.
- **`app`**: cualquier perfil habilitado.
- **`cloud`**: estar en `controladores` con `habilitado = 1`. **Hoy es todo o
  nada**: los roles ya se asignan (`controladores_roles`, `roles_permisos`) pero
  ningún endpoint los mira todavía.

### Escalón 4 — ¿qué pantallas ve adentro?
Tres banderas en `perfiles`, con las mismas reglas que `habilitado`:

| permiso | dónde vale | qué abre |
|---|---|---|
| `operacion` | `app` | los paneles de control (la franja, los controles, los botones) |
| `invitacion` | `app` | el ítem *Invitar un Usuario* |
| `facturacion` | `panel` | el agrupador *Cuenta* entero |

**Cada permiso se aplica en dos capas y la UI no es el control de acceso**: la
pantalla no dibuja lo que no corresponde *y* el endpoint corta igual. Sin
`operacion`, `api/boton.php` devuelve 403 aunque el botón esté escondido.

**Un Administrador puede no tener facturación y un Operador puede tener
operación.** `tipo` y los tres permisos son cosas distintas y no se derivan uno
del otro. En los datos: 48 perfiles con rol Administrador tienen `tipo = 'O'` y 10
al revés.

**Ninguno de los tres se otorga sin tenerlo** — pero sólo en `panel`, donde quien
edita es un cliente administrando su propio dominio. En `cloud` no hay esa
restricción, y **ése es el único desbloqueo** cuando un dominio se queda sin nadie
que pueda repartir un permiso (o sin ningún Administrador: pasa hoy con el dominio
*Camino al Puente Viejo*, cuyos 33 perfiles son todos Operador).

**El 403 lleva un motivo, y la diferencia importa**: `motivo: 'perfil'` significa
*"la sesión ya no sirve, volvé al login"*; `motivo: 'permiso'` significa *"la
sesión está bien, esta pantalla no es tuya"*. Mandarlos por el mismo camino echaría
de la sesión a quien toca Facturas sin tener el permiso.

---

## 7. Los circuitos de acceso

### 7.1 Invitaciones — dos puertas, un solo circuito

Misma tabla (`invitaciones`), mismo canal de correo, misma pantalla de
aceptación, emitida desde dos lados:

| | desde `panel/` | desde `app/` |
|---|---|---|
| dónde | Usuarios → *+ Nuevo usuario* | *Mi Dominio* → *Invitar un Usuario* |
| quién | cualquier perfil que entre al panel | el permiso `invitacion` |
| crea | **Operador** (`tipo = 'O'`) | **Operador** |
| permisos | `operacion` 1 / `invitacion` 1 / `facturacion` 0 | ídem |

**Una invitación da de alta un Operador, se emita donde se emita**, y por lo tanto
**la invitación del panel no da acceso al panel**: quien la acepta opera la app.
Ningún camino automático fabrica un Administrador — se otorga desde el alta de
`cloud` o editando el perfil en Usuarios.

Al aceptar hay tres casos:

| caso | qué pasa |
|---|---|
| sin cuenta | se crean `usuarios` + `perfiles`, **y se abre la sesión** |
| con cuenta, sin perfil acá | sólo el perfil. No se toca contraseña ni dominio activo |
| ya tenía perfil habilitado | **no se hace nada**, y la pantalla dice *"Ya tenías acceso"* |

**A quien ya está registrado no se le piden datos**: la cuenta se busca *antes* de
dibujar el formulario. Sin cuenta se piden los tres campos; con cuenta incompleta,
sólo lo que falta; con cuenta completa, nada — se resuelve en el mismo request. Y
de la cuenta existente se completan **sólo las columnas vacías**, con un `WHERE`
que las exige vacías: el `uuid` del enlace **lo ve el emisor** en su propio
listado, así que dejar sobrescribir un celular o un correo ajeno entregaría las
dos puertas del login de `app` y las dos vías de recuperación.

Por la misma razón **la sesión sólo se abre para la cuenta recién creada**. Si se
abriera sobre una cuenta que ya existía, cualquiera que pueda invitar tendría un
secuestro servido: invita al correo de una cuenta existente, copia el uuid de su
listado y abre el enlace él mismo.

Otros detalles que no se deducen del esquema:
- **`Aceptar` es un POST**, no un `<a href>`: ese request puede resolver la
  invitación sin preguntar nada, y un prefetch (antivirus de correo, preview del
  cliente de mail, crawler) la aceptaría sin que la persona la haya abierto.
- **El celular son exactamente 10 dígitos y nada más** (`2644123456`). Es lo que
  tiene el 100% de los datos. Lo que llega mal **se rechaza, no se recorta**:
  cortar `+5492644123456` en el dígito 10 daría un número que no es de nadie.
- **El perfil nuevo recibe todos los paneles habilitados del dominio**
  (`perfiles_paneles` no tiene fallback: sin esas filas la persona entra a una app
  vacía) y se le siembra `perfiles.panel` con el de id más baja.
- **Las dos mandan a la app.** `app/` abre la sesión directo; `panel/` no puede
  escribir la cookie de otro host, así que emite un **enlace de un solo uso** en
  `enlaces_acceso` y el botón va a `app.reactor.com.ar/acceso?t=…`.

### 7.2 Recuperación de contraseña

`panel/recuperar/` y `app/recuperar/` son el mismo circuito sobre la tabla
`recuperaciones`: 32 bytes de CSPRNG por correo, de los que **en la base queda
sólo el SHA-256**, 60 minutos de vigencia, cupo de 3 por cuenta y 10 por IP por
hora.

- **Cada una busca la cuenta con el criterio de su propio login** — `panel` por
  `usuario`/`correo`, `app` por `celular`/`correo`. Copiar el criterio haría que la
  recuperación cayera en una fila distinta de la que después resuelve el login.
- **La respuesta del formulario es siempre la misma**, exista o no la cuenta: es un
  formulario público y distinguirlos lo convertiría en un verificador de usuarios.
  La única excepción es que se caiga el microservicio de correo — callar eso deja a
  la persona esperando un mail que nunca va a llegar.
- **Todas las fechas se comparan contra `NOW()` de la base**, nunca contra el reloj
  de PHP: PHP corre en UTC y la sesión de MySQL en -03:00, y estas páginas ni
  siquiera pasan por el bootstrap que fija la zona horaria.
- **El consumo es un `UPDATE` condicional** con `usada IS NULL AND expira > NOW()`
  en el `WHERE` — es el candado contra el doble envío.
- **Usar un enlace cierra todos los pedidos abiertos de esa cuenta.**
- **Cambiar la contraseña no cierra las sesiones abiertas**: el token es stateless.
- **En `app` el correo muerde de verdad**: a la app se entra por celular, así que
  hay cuentas sin correo cargado y ésas no pueden recuperar.
- **La de `app` abre la sesión al guardar** y la del panel manda al login. No es
  una asimetría caprichosa: `app` corre en el host de su propia cookie, y la
  credencial salió por correo a la casilla de la propia cuenta, así que quien la
  tiene acaba de elegir la contraseña y entraría igual tipeándola.

### 7.3 Enlaces de acceso (los "enlaces mágicos")

`enlaces_acceso` es la URL que abre sesión en `panel` o en `app` **como otra
persona, sin su contraseña**. La emite `cloud` para soporte.

- **El cupo es `usos < usos_max`**, no "un solo uso". Quien emite elige los dos
  límites: 60 minutos y 1 uso por defecto, hasta 30 días y 100 usos.
- **El candado es un `UPDATE` condicional**: `SET usos = usos + 1 … WHERE usos <
  usos_max AND expira > NOW()` valida y cuenta en la misma sentencia, dentro del
  lock de fila de InnoDB. Con un `SELECT` previo, dos canjes simultáneos del último
  uso pasarían los dos.
- **El `destino` (`panel`/`app`) va en el `WHERE`**, que es lo que impide que un
  enlace de app abra el panel.
- **`cloud` no emite** un enlace de panel para quien no tenga perfil de
  Administrador (devuelve 409 en vez de entregar algo que va a rebotar), y
  `acceso.php` **revalida al canjear**: entre la emisión y el uso el perfil se pudo
  bajar a Operador.

### 7.4 Registrante: quién otorgó el acceso

`usuarios.registrante` dice quién creó la **cuenta**; `perfiles.registrante`, quién
otorgó **este acceso**. Son dos altas distintas: una cuenta se crea una sola vez y
después acumula perfiles en varios dominios, cada uno otorgado por alguien distinto.
A una cuenta que ya existía **no se le pisa** el registrante: sería reescribir un
hecho. `NULL` es válido y significa "no se sabe" — lo tienen las 2.227 filas
anteriores a la migración, porque el dato no se puede reconstruir sin inventarlo.

---

## 8. Qué hace cada app

### 8.1 `app/` — la PWA del usuario final

Es el producto que ve el cliente. PHP renderizado en el servidor, sin framework,
instalable como PWA.

**La pantalla**: una franja con dominio y panel activos, y abajo un control por
dispositivo. Cada control muestra un display con el nombre y el color configurado,
el nivel de señal en porcentaje (escala `-10 dBm = 100%`, `-90 dBm = 0%`), las
pastillas de sus canales (encendido/apagado para actuadores, valor para sensores) y
hasta 10 botones.

- **El display se apaga a gris** cuando el equipo no está operativo, sin importar
  el color configurado. Tres estados con precedencia: deshabilitado > desconectado
  > online.
- **El sondeo es uno solo para todo el panel** (`api/canales.php`), no uno por
  control: un panel de 6 controles sondeados por separado son 6 requests por
  segundo, cada uno con su arranque de PHP y sus consultas.
- **Si la situación del dominio es `'3'` (suspendido) no se dibuja ningún
  control.** Con `'2'` (limitado) se muestra la advertencia pero el servicio anda.
  Cualquier valor inesperado se comporta como normal: ante un dato raro conviene
  dejar operar, no bloquear.
- **Sin permiso `operacion` no se consulta nada**: la app se abre igual (Mi Cuenta,
  Mi Dominio, Ajustes, Instalar) pero no se dibuja ni un control. Se gatea la
  operación, no la entrada — cerrar la app entera dejaría a la persona sin poder
  ver su cuenta ni cerrar sesión.
- **Los botones de la topbar sólo aparecen si hay entre qué elegir**: *Cambiar de
  Panel* y *Cambiar de Dominio* se esconden con una sola opción, que es el caso
  normal (98% de las cuentas tienen un solo perfil, 93% de los dominios un solo
  panel).

**El menú**: Inicio · Mi Cuenta (Mi Usuario / Entorno / Cerrar Sesión) · Mi Dominio
(Detalles / Actividad / Invitar un Usuario) · Ajustes (Notificaciones / Mesa de
Ayuda) · Instalar. Los dos agrupadores del dominio no se dibujan si la cuenta no
tiene ningún perfil habilitado.

**El service worker** ([app/serviceworker.js](app/serviceworker.js)) usa **red
primero** para navegaciones y datos, **cache primero** para los estáticos
versionados. `api/` **no se cachea nunca**: cachearlo sería mostrar un tablero
mentiroso, una luz apagada que figura encendida. Los estáticos sí, porque viajan
con `?v=<version.txt>` y cada deploy cambia la URL — cachearlos por URL exacta no
puede servir nada viejo y saca de la ruta crítica los dos recursos que bloquean el
render.

**Dos banners**, porque en `display: standalone` no hay barra de direcciones ni
pull-to-refresh y el usuario no tiene ningún gesto para recargar: el de nueva
versión (arriba) y el de "sin conexión con Reactor" (abajo, fijo), que aparece
cuando el panel lleva 20 segundos sin un sondeo bueno.

### 8.2 `panel/` — el back office del cliente

El Administrador de un dominio administra **su** dominio. Todo se filtra por
`requireDominioId()`, incluido el lookup por id — para que nadie lea ni escriba un
registro de otro dominio pasando un id a mano.

| Categoría | Módulos |
|---|---|
| General | Dashboard |
| Inventario | Dominio · Usuarios · Dispositivos · Chips |
| Historial | Actividad · Invitaciones |
| Cuenta *(gateado por `facturacion`)* | Facturas · Recibos · Facturación |

- **Dashboard**: stat cards + gráfico de líneas multi-serie "Uso por dispositivo"
  (SVG dibujado a mano), con selector de ventana (24 h / 7 / 15 / 30 días). Seis
  colores como tope duro, en la familia del rojo institucional por decisión de
  marca; el séptimo equipo y los siguientes van a un agregado gris.
- **Usuarios**: **la fila es un perfil, no una cuenta.** El alta es una invitación;
  la edición es del perfil (tipo, permisos, paneles).
- **Dispositivos**: el alta es **adoptar** un equipo y la baja es **liberarlo** — no
  hay borrado.
- **El `Código` (el id) no se muestra en ningún módulo**: es una PK interna y no
  identifica nada que el cliente reconozca. Cada módulo tiene su identificador
  legible (`uuid`, `usuario`, `telefono`, `numero`). Lo que sí se conserva es
  *Ordenar por → Código*, que es el criterio de orden y no un dato.

### 8.3 `cloud/` — el back office de Reactor

Administra la plataforma entera, sin filtro de dominio. SPA con router por hash, un
solo `index.php`, un solo `style.css`, un solo `app.js`, sin build step.

| Categoría | Módulos |
|---|---|
| Inicio | Dashboard |
| Propiedad | Dominios · Usuarios · Perfiles · Técnicos |
| Inventario | Dispositivos · Chips · Transceptores |
| Comercial | Contratos · Comprobantes · Talonarios |
| Comunicación | Notificaciones · Difusión |
| Registros | Señales · Historial de registros · Alertas · Adopciones |
| Seguridad | Controladores · Roles · Permisos |
| Administración | Herramientas |

**Herramientas** es una grilla de tarjetas con las utilidades transversales:
Comparador DB *(sólo dev)* · Editor de parámetros · Explorador DB · Explorador S3 ·
Migrador DB · Programador de tareas · Sincronizador de tablas *(sólo dev)* · Visor
de sucesos.

El **Programador de tareas** es un cron con UI: tabla `tareas` + `tareas_ejecuciones`,
un scheduler minutal ([cloud/jobs/_scheduler.php](cloud/jobs/_scheduler.php))
disparado desde `/etc/cron.d/reactor-cloud`, watchdog de huérfanos, streaming del
log en vivo por SSE y limpieza nocturna por `retencion_dias`. **El deploy no lo
instala**: requiere cronie + pcntl + el directorio de logs, puestos una sola vez al
aprovisionar el servidor.

### 8.4 `www/` — el sitio público

**El único docroot sin login**, y eso cambia todo: cada archivo lo puede pedir
cualquiera de internet, sin sesión y sin límite de intentos.

- Todo lo que llega del request se escapa al imprimirlo. La única excepción
  deliberada es `entradas.cuerpo`, HTML cargado desde el back office.
- Los dos formularios (registro de técnicos y contacto) llevan **trampa de miel** +
  tiempo mínimo de 3 segundos, y **el bot detectado ve la misma pantalla de éxito
  que una persona**.
- El contenido (blog, ayuda, info) sale de `entradas`, **pedido por rama** de
  `entradascategorias`: una categoría nueva cargada en el back office aparece sola.
- Los técnicos se publican sólo con `aprobacion = '2'` **Y** `visibilidad = '1'` —
  la aprobación lo habilita, la visibilidad decide si sale en la vidriera. Filtrar
  por una sola publicaría gente que pidió no aparecer.

**La burbuja de chat con IA:**

- **Sin `OPENAI_APIKEY` no se dibuja nada** — es cómo se apaga sin deployar, y por
  qué en desarrollo la esquina está vacía.
- **Cada mensaje es plata, y eso ordena todo el diseño.** Cuatro frenos en orden:
  tope de caracteres, cupo de la conversación (`UPDATE` condicional sobre
  `conversaciones.mensajes`), cupo por IP por hora y **cupo diario global** — el
  único que sigue valiendo cuando el abusador rota IP. El quinto no está en el
  repo: el tope de gasto de la cuenta de OpenAI.
- **El historial no viaja en el POST**: el navegador manda el `uuid` y el texto
  nuevo, y la charla se rearma desde `conversaciones_mensajes`. Si viajara,
  cualquiera podría reescribir "lo que ya se dijo" —las reglas del sistema
  incluidas— y hacernos pagar el contexto que se le antoje.
- **Lo que el modelo sabe se arma en cada turno y sale del sitio**: los planes de
  `planes` + `articulos`, los artículos de `entradas`. Un precio escrito en el
  prompt quedaría viejo el día que alguien lo cambia en el back office.
- **La respuesta se pinta con `createTextNode()`, nunca con `innerHTML`.**
- **El chat no ve ninguna cuenta** y el prompt se lo dice tres veces: no hay sesión
  en este docroot, así que deriva a `app.reactor.com.ar` o a WhatsApp.

---

## 9. El motor MQTT y los dispositivos

### El motor

Un worker Python con `paho-mqtt` + `PyMySQL` suscripto al broker. Es el que cierra
el circuito de la operación: cuando un equipo reporta (`REP=CEN` / `REP=CAP` /
`REP=SNS`) escribe `canales.estado`, que es lo que después lee el tablero, y
mantiene `dispositivos.enlace` / `.latido` / `.conexion`, que es lo que decide si
un control se muestra en línea o en gris.

**Las apps no escuchan el broker**: publican órdenes y leen esas columnas. Toda la
recepción pasa por acá.

[motor/main.py](motor/main.py) de este repo está en **estado esqueleto**: conecta,
valida MySQL al arrancar, loguea cada mensaje por stdout y no persiste — la
persistencia está marcada con TODO.

### El firmware

[firmware/esp8266/](firmware/esp8266/) y [firmware/esp32/](firmware/esp32/) — el
segundo es port directo del primero, con la misma estructura de config, el mismo
schema MQTT y el mismo flujo de boot.

Ciclo de vida de cada arranque:

1. Monta LittleFS (lo formatea si el mount falla) y calcula su `uid` desde la MAC.
2. Lee `/config.json` — **un único archivo** con `uid`, `version`, `wifi`, `mqtt`,
   `users` y `channels`. Si falta o es inválido, lo regenera con los defaults
   compilados.
3. Sincroniza `uid` y `version` contra la realidad (MAC del chip y
   `FIRMWARE_VERSION`) y reescribe el JSON si difieren.
4. Aplica los canales al hardware (`pinMode` + valor inicial). Los pines 6–11 se
   ignoran: están conectados al flash SPI interna.
5. Conecta WiFi en modo estación; timeout 20 s y reintento cada 5 s **sin
   reiniciar el chip**.
6. Conecta al broker con `clientId = uid`.
7. Se suscribe a `reactor/<uid>/config` (QoS 1) para recibir la lista de canales
   publicada por el cloud, y la persiste en el mismo JSON.
8. Levanta un panel HTTP en el puerto 80 con Basic Auth para ver estado y editar
   la config.

Hasta 8 canales por dispositivo. **Todo el comportamiento es resiliente**: una
caída de red o de broker no reinicia el chip, sólo dispara reintentos.

**El JSON entero es la unidad de sincronización** con el cloud: se publica y se
recibe como un snapshot atómico, sin orquestar múltiples topics ni estados
intermedios. Una sola escritura en flash = un solo punto de verdad consistente.

---

## 10. Integraciones externas

Ninguna vive en este repo, y todas fallan sin voltear la página.

| Salida | Qué hace | Dónde |
|---|---|---|
| **Databox · correo** | `POST /v4/aws/mensajes` — invitaciones, recuperaciones, difusión | `lib/databox.php` de cada app |
| **Databox · CRM** | `POST /v4/datarocket/prospectos` — contacto, registro de técnicos, boletín | [www/lib/prospectos.php](www/lib/prospectos.php) |
| **OpenAI** | el chat con IA de la burbuja | [www/lib/chat.php](www/lib/chat.php) |
| **media server** | las imágenes de entradas y técnicos | `media.reactor.com.ar` |
| **Google** | GA4 + Ads (dos propiedades distintas: sitio y app) | `lib/analytics.php` |
| **AWS S3** | el Explorador S3 de Herramientas | [cloud/api/lib/s3.php](cloud/api/lib/s3.php) |
| **EMQX** | los brokers MQTT | `transceptores` |

- **El alta de invitación y el envío van en una transacción**: un enlace que nadie
  recibió no le sirve a nadie y además consume cupo.
- **OpenAI es la única que se cobra por request**, y por eso todo lo que la rodea
  (cupos, chequeo de `Origin`, tope de caracteres) sale de ahí.
- **Si el CRM falla, lo que se hace depende de si hay respaldo local**: en el
  registro de técnicos la fila ya quedó guardada, así que el fallo va al log y la
  persona ve el éxito; en contacto el CRM es el destino final, así que hay que
  decirlo.

---

## 11. Stack e infraestructura

### Reglas duras

- **PHP 8.2 plano.** Sin Composer, sin namespaces, sin ORM. `require_once`
  explícito.
- **JavaScript vanilla.** Sin React/Vue, sin bundler, sin `node_modules`.
- **Sin build step**: lo que está en disco es exactamente lo que se sirve.
- **Un solo CSS y un solo JS por app.**
- Respuestas de API siempre `{ok: true, data: …}` o `{ok: false, error: '…'}`.
- FontAwesome 6.5.1 Pro **autohospedado**, nunca CDN — un `<link>` de otro origen
  bloquea el primer paint, y eso fue una de las causas de la pantalla en blanco de
  la PWA. Cache-busteado por `filemtime`, no por `version.txt`: FA cambia una vez
  por año y `version.txt` sube en cada deploy.

### Configuración

[env.php](env.php) es el loader compartido: **auto-detecta el entorno** mirando qué
`.env.*` existe (si hay `.env.production` y no hay `.env.development` →
producción), y publica todo lo del archivo como **constantes globales** además de
`getenv()`/`$_ENV`/`$_SERVER`. Precedencia: lo que ya inyectó docker-compose gana
sobre el archivo. **Sin defaults**: lo que falte revienta con "undefined constant",
que dice exactamente qué agregar.

Claves publicadas: `APP_ENV`, una `APP_KEY_<APP>` por docroot, `DB_*`, `MQTT_*` +
`MQTT_ORIGEN`, `EMQX_DASHBOARD_PASS`, `AWS_*`, `DATABOX_APIKEY`, `OPENAI_APIKEY` +
`OPENAI_MODELO`, `GA_MEASUREMENT_ID`, `WWW_GA_MEASUREMENT_ID`, `WWW_GOOGLE_ADS_ID`.

Los `.env.*` están en `.gitignore` y **nunca se commitean, ni se loguean, ni se
imprimen**.

### Docker

[docker-compose.yml](docker-compose.yml) levanta dos servicios:

- **`reactor-apache`** — imagen `php:8.2-apache` + `pdo_mysql` + `pcntl` +
  `rewrite`/`headers`/`expires`. Los cuatro docroots van **bind-monteados**, así
  que un cambio de código queda vivo sin rebuild. `env.php` y los `.env.*` se
  montan un nivel arriba de los docroots para que sean compartibles.
- **`reactor-motor`** — el worker Python.

En desarrollo, **MySQL y EMQX son externos** (stack `herramientas`, vía
`host.docker.internal`). En producción, RDS + un EMQX dedicado.

Los `ports.conf` y `vhosts.conf` van **COPY, no montados**: editarlos en el repo
no alcanza, hay que rebuildear la imagen.

### Deploy

[scripts/deploy.sh](scripts/deploy.sh) sube a `paloalto.reactor.com.ar` (Amazon
Linux, `ec2-user`) por rsync sobre SSH. Tres modos:

| modo | qué hace |
|---|---|
| (default) | sólo sube archivos. **No reinicia nada** — los docroots son bind mounts, el código nuevo queda vivo apenas termina el rsync |
| `--restart` | sube + `up -d --force-recreate` |
| `--rebuild` | sube + reconstruye la imagen + recrea |

Empieza estampando `1.0.<timestamp>` en los cuatro `version.txt`, y al final avisa
si detectó un cambio que sí requiere `--restart` o `--rebuild`.

**Cosas que el deploy no hace** y hay que saber:

- **No regenera `docker-compose.prod.yml`**: ese archivo lo escribe
  [scripts/aprovisionar_server.sh](scripts/aprovisionar_server.sh), así que un
  cambio a su heredoc queda stale en el servidor hasta re-aprovisionar.
- **No toca el cron** del Programador de tareas.
- **No aplica migraciones**: se aplican desde el Migrador DB de Herramientas.
- buildx en producción puede reusar layers y **no rebuildear** aunque el
  Dockerfile haya cambiado; a veces hace falta `--no-cache` a mano.

**El banner de nueva versión** es lo que cierra el círculo: el front pollea
`api/version.php` cada 60 s y lo compara con `document.body.dataset.version`; si
difiere, muestra la barra con "Actualizar ahora".

### Certificados y proxy

nginx en el host termina TLS y proxea cada subdominio a su puerto. **Un solo
certificado cubre los 9 dominios**, y la renovación corre por **systemd, no por
cron** — `crond` está inactivo en ese host y `/etc/cron.d` nunca se ejecuta.

Detalle que costó caro: Apache no sabe que la request original era HTTPS (nginx le
habla HTTP plano), así que cualquier redirect con `[R]` de mod_rewrite salía como
`http://` y cambiaba de origen. Está resuelto en [app/.htaccess](app/.htaccess).

---

## 12. Convenciones que atraviesan todo el repo

### La duplicación es deliberada

`habilitado.php`, `permisos.php`, `busqueda.php`, `usuarios_alta.php`, `jwt.php`,
`databox.php` están **copiados en cada app**, no compartidos. La razón: las apps
**no comparten docroot**, y un `require` que salga del docroot no es servible. Si
se cambia una copia, hay que cambiar la otra — está anotado en la cabecera de cada
archivo.

### La búsqueda por texto libre

Una sola implementación (`lib/busqueda.php`), y **siempre en SQL, nunca filtrando
un array en JavaScript**. Cuatro garantías:

1. Los acentos no importan, **en las dos puntas** (`gonzalez` ↔ `González`, `nino`
   ↔ `Niño`).
2. Mayúsculas y minúsculas indistintas.
3. Se buscan pedazos de palabra, no prefijos.
4. Los términos van sueltos y en cualquier orden: `mari gon` encuentra a
   `María González`.

Reglas que no se deducen del código:

- **Los términos se cruzan con Y; cada término se busca en todas las columnas con
  O.** Agregar una palabra tiene que **acotar**. Al revés, el buscador empeora justo
  cuando más se lo necesita.
- **Los acentos los pliega la collation, no la query.** Si una columna quedara con
  una collation acento-sensible, lo que hay que arreglar es la collation.
- **Un placeholder por término y por columna**, aunque el valor sea el mismo: con
  prepares nativos, PDO mapea cada nombre a una posición.
- **Los comodines de `LIKE` se escapan**: quien busca `50%` quiere ese texto.
- **Las columnas salen del código, nunca del request** — un nombre de columna que
  llegue de afuera es inyección.
- `LIKE '%…%'` **no usa índice**: va siempre *después* de la cota por dominio o por
  ventana de ids, nunca en su lugar.

### El reloj

**Todas las fechas se comparan contra `NOW()` de la base, nunca contra el reloj de
PHP.** PHP corre en UTC y la sesión de MySQL en `-03:00`: con el reloj equivocado,
un enlace de 60 minutos nace vencido o dura cuatro horas, y las ventanas de los
gráficos se corren 3 horas hacia adelante dejando los últimos puntos en cero — que
se lee como si los equipos hubieran dejado de responder.

### Los modales

Todos salen de `openModal()` y tienen la misma forma: **barra de título pintada en
`--primary`**, **barra de acciones debajo** con todos los botones, **sin footer**.
La salida va primera y es el único botón neutro. La única excepción es
`confirmarBaja()`: una confirmación es una pregunta, no una pantalla, y su botón
rojo se queda lejos de la salida a propósito.

### La identidad visual

Tema **único oscuro**, sin modo claro y sin toggle. Chrome (sidebar + topbar) en
rojo institucional `#C11313`; contenido en grises. Sobre el rojo, los hijos van en
`#fff` y opacidades de blanco. Las series de los gráficos van en la familia del
rojo (rojo, durazno, amarillo, blanco, rosa, oro) — seis y no más, porque dentro de
una familia cálida lo que separa una serie de otra es la luminosidad y sólo entran
seis escalones.

---

## 13. Estado actual y qué falta

**Funcionando de punta a punta:** operación de equipos desde la PWA, los tres
logins, los cuatro escalones de autorización, las dos invitaciones, las dos
recuperaciones, los enlaces de acceso, el back office del cliente, el back office
de Reactor con sus ocho herramientas, el sitio público con chat IA, el deploy y el
banner de versión.

**Lo que está a medias o pendiente:**

| Qué | Estado |
|---|---|
| **Motor MQTT de este repo** | esqueleto: conecta y loguea, no persiste |
| **Login `autenticacion = 'T'`** | el código de 6 dígitos se encola en `mensajes` y no hay worker que lo consuma: esas ~29 cuentas no pueden completar el login por ese camino |
| **Roles y permisos de `cloud`** | las tablas existen y se asignan, pero **ningún endpoint los mira**: entrar a cloud es todo o nada |
| **`api/` y `robot/`** | docroots reservados, stubs vacíos |
| **Módulo Alertas de `cloud`** | ruta registrada con un stub |
| **Buscador público de `www`** | sigue con un solo `LIKE` de la frase entera; no usa el método del proyecto |
| **Dominio sin Administrador** | *Camino al Puente Viejo* (#160) tiene 33 perfiles, los 33 Operador: nadie puede administrarlo salvo desde `cloud` |

**Riesgos operativos conocidos:**

- **`senales` y `registros` no tienen índice por `fecha`.** Toda consulta tiene que
  acotarse por PK primero. Sin eso, una búsqueda vacía sobre `registros` tarda 14 s.
- **Los JWT son stateless**: no hay forma de revocar un token antes de su `exp`
  salvo rotar la clave de la app. En `app` eso es un año.
- **La base de desarrollo es copia de producción**, incluidos los `transceptores`.
  `MQTT_ORIGEN=env` es lo que impide que una prueba encienda la luz de un cliente.
- **Las contraseñas usan cifrado reversible.** Quien lea la base las lee todas.
- **Borrar un usuario obliga a borrar antes todos sus perfiles** (`usuarios` y
  `perfiles` se referencian mutuamente y casi todo el esquema es `RESTRICT`).
