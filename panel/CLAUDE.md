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
  sub-ítem (`.nav-sub-item`). Emoji obligatorio en ambos niveles.
- **Topbar con una sola acción**: el botón de usuario arriba a la derecha
  (dropdown con "Cerrar sesión"). Ninguna acción global adicional en la
  topbar — todo lo demás vive dentro de cada pantalla.
- **Tema único oscuro**. No hay modo claro, no hay toggle, no hay
  `data-theme`.
- **Un solo archivo CSS**: `assets/css/style.css`. No fragmentar.
- **Sin build step, sin librerías UI pesadas**. Sólo FontAwesome CDN (icon
  set) y CSS/JS vanilla.

## Bump de version.txt

Al tocar cualquier archivo bajo `panel/assets/css/` o `panel/assets/js/` hay
que incrementar `panel/version.txt` o el browser sirve caché vieja
(los assets se cargan con `?v=<contenido de version.txt>`).

## Módulos

El shell está pensado para poblarse por módulos. Cada nuevo módulo se agrega
como sub-ítem del sidebar (dentro de una categoría con emoji) y registra su
renderer en `routes` de `assets/js/app.js`. Los módulos ABM, el módulo
Herramientas y sus utilidades tienen sus propias skills dedicadas
(`abm_design`, `crear_modulo_herramientas`, etc.) — respetalas cuando
implementes cada uno.
