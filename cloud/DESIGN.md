# Sistema de diseño — Cloud

Este archivo es la **especificación del lenguaje visual** de la
aplicación **cloud** (panel de administración de Reactor, plataforma
IoT). Aplica solo a esta carpeta `cloud/`; cualquier otra aplicación
del repositorio tiene su propio sistema de diseño y no debe mezclarse
con éste.

## Cómo usar este archivo

- Está referenciado desde `cloud/CLAUDE.md` como `@DESIGN.md`, así que Claude Code lo carga automáticamente en cada sesión que toque archivos de `cloud/`.
- Es **autocontenido**: tokens, layout, componentes y reglas están todos acá.
- Aplica a **cualquier pantalla de cloud** (login, dashboard, listados con tabla, ABMs, formularios, configuración, detalle, modales, etc.), no solo al dashboard.
- Todos los estilos viven en un único archivo: `cloud/assets/css/style.css`. No fragmentar en módulos.
- Si necesitás un componente nuevo que no está acá, derivalo de los tokens; no inventes paletas, radios ni sombras nuevas. Una vez validado, agregalo a este archivo.

---

## 1. Tokens de diseño (variables CSS)

Definí esto en `:root`. Reemplazá todos los hexadecimales sueltos por estas variables.

Cloud tiene **un único tema** oscuro, organizado en **dos zonas cromáticas bien separadas**:

1. **Chrome de la app** (sidebar vertical + topbar horizontal) — pintados de plano en el rojo institucional **`#C11313`**. Forman una "L" roja continua que enmarca toda la pantalla y aporta la identidad de marca a la primera vista.
2. **Área de contenido** (cards, modales, inputs, tablas, dropdowns) — grises oscuros neutros. El rojo institucional reaparece dentro de esta zona solo como **acento**: botones primarios, focus ring, links activos, "ver más", chips activos, valores numéricos destacados.

**No hay modo claro ni toggle de tema.**

```css
:root {
  --bg:        #1a1a1a;   /* fondo del área de contenido (gris oscuro) */
  --surface:   #242526;   /* topbar, cards, inputs, dropdowns */
  --border:    #383838;   /* bordes sutiles en zona gris */
  --row-hover: #2d2e2f;   /* hover de filas de tabla */
  --primary:   #C11313;   /* rojo institucional (sidebar + topbar + acentos) */
  --primary-h: #8e0e0e;   /* hover más oscuro */
  --danger:    #e62a2a;   /* acciones destructivas */
  --success:   #22c55e;
  --warn:      #f59e0b;
  --info:      #3b82f6;
  --purple:    #8b5cf6;
  --text:      #f0f0f0;   /* texto principal sobre gris */
  --muted:     #9ca0a4;   /* labels / texto atenuado */
  --radius:    10px;
  --shadow:    0 1px 4px rgba(0,0,0,.45);
  --shadow-lg: 0 8px 32px rgba(0,0,0,.65);
}
```

**Reglas:**
- **Tema único.** No usar `data-theme`, no inventar tema claro, no agregar toggle de tema en la UI.
- **Dos zonas cromáticas, sin mezcla.** El chrome (sidebar + topbar) son las *únicas* superficies rojas sólidas. Cards, modales y contenido viven sobre `--bg` / `--surface` en grises. No pintar cards ni modales de rojo.
- Color de marca: `var(--primary)` (`#C11313`). Fuera del chrome se usa solo como **acento**: acciones primarias, focus ring, links activos, "ver más", chips activos, valores numéricos clave.
- Dentro del chrome rojo (sidebar y topbar), los hijos (`.sidebar-logo-title`, `.nav-item`, `.topbar-title`, `.topbar-username`, `.btn-ghost`, etc.) **no usan `--text` / `--muted` / `--border`**: usan blanco (`#fff`) y negros translúcidos (`rgba(0,0,0,.18-.28)`) porque el contraste se calcula contra el rojo, no contra el gris. Ver §4 y §5.
- `--danger` (`#e62a2a`) es un rojo más brillante reservado para acciones destructivas. No mezclarla con `--primary`.
- Radios: **10px** en cards / inputs / botones (`var(--radius)`), **14px** en modales, **99px** en badges y toasts.
- Sombras: profundas para destacar sobre el fondo gris oscuro. `var(--shadow)` en cards / topbar, `var(--shadow-lg)` en modales y dropdowns.
- Tipografía: `-apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif`.

## 2. Reset & base

```css
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body {
  font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
  background: var(--bg); color: var(--text); min-height: 100vh;
}
```

---

## 3. Layout principal

Estructura obligatoria de toda pantalla de cloud:

```html
<div class="layout">
  <aside class="sidebar"> … </aside>
  <div class="main">
    <div class="topbar"> … </div>
    <div class="content"> … </div>
  </div>
</div>
```

```css
.layout  { display: flex; min-height: 100vh; }
.sidebar { width: 220px; background: var(--surface);
           border-right: 1px solid var(--border);
           display: flex; flex-direction: column; flex-shrink: 0; }
.main    { flex: 1; display: flex; flex-direction: column; overflow: hidden; }
.topbar  { background: var(--surface); border-bottom: 1px solid var(--border);
           padding: 0 24px; height: 60px;
           display: flex; align-items: center; justify-content: space-between;
           box-shadow: var(--shadow); }
.content { flex: 1; padding: 24px; overflow-y: auto; }
```

Justo antes de `.layout`, el `<body>` incluye el banner de nueva
versión (ver §3-bis). Cuando está oculto no ocupa espacio.

## 3-bis. Banner de nueva versión

Barra azul (`--info`, `#3b82f6`) full-width que aparece al tope del
`<body>` cuando `cloud/version.txt` cambió respecto al valor que la
página cargó al abrirse. Empuja el chrome hacia abajo (no es overlay)
y se muestra en todas las rutas — su lugar es el `<body>`, por
fuera de `.layout`.

Markup obligatorio (siempre presente en `index.php`, arranca oculto):

```html
<body data-version="<?= htmlspecialchars($cacheBust) ?>">
  <div class="version-banner" id="version-banner" role="status" hidden>
    <span class="version-banner-text">Hay una nueva versión disponible.</span>
    <button type="button" class="version-banner-btn" id="version-banner-btn">Actualizar ahora</button>
  </div>
  <div class="layout"> … </div>
</body>
```

Reglas visuales:

- **Fondo `var(--info)`, texto `#fff`, alto 44 px, `justify-content: center`.**
- Botón blanco con texto `var(--info)`, `border-radius: 6px`, sin
  borde. Al hover, fondo `#e5e7eb`.
- Texto exacto: `Hay una nueva versión disponible.`
- Botón exacto: `Actualizar ahora`.

Interacción (implementada en `app.js`):

- Al cargar la página, el body trae `data-version="<contenido de version.txt>"`.
- Cada 60 s, `fetch('api/version.php')` compara con esa baseline.
- Si difiere: se quita el atributo `hidden` del banner y se agrega
  `body.has-banner`, que ajusta `.layout` a `min-height: calc(100vh - 44px)`
  para no generar scroll extra.
- El botón dispara `window.location.reload()`.
- Una vez mostrado, se deja de pollear (la nueva versión ya está
  detectada, no hace falta seguir chequeando).

Reglas duras:

- **No** convertir el banner en overlay `fixed`: siempre empuja el
  contenido.
- **No** cambiar los textos ("Hay una nueva versión disponible." y
  "Actualizar ahora") sin actualizar también `panel/`, que usa el
  mismo mecanismo.
- **No** usar un color distinto de `--info`: es el único acento
  cromático de la app que compite con el rojo institucional, así se
  reconoce inmediatamente como "aviso del sistema" y no como parte
  del chrome de marca.

## 4. Sidebar

El sidebar está pintado de plano en `var(--primary)` (`#C11313`). Por eso sus elementos hijos **no usan los tokens `--text` / `--muted` / `--border`**: usan `#fff` para texto y `rgba(0,0,0,.18-.28)` para hover / activo / bordes. El contraste se calcula contra el rojo, no contra el gris del resto de la app.

```css
.sidebar       { background: var(--primary);             /* rojo institucional */
                 border-right: 1px solid rgba(0,0,0,.25); }

.sidebar-logo  { height: 60px;                /* coincide con la altura de .topbar */
                 padding: 0 20px; border-bottom: 1px solid rgba(0,0,0,.2);
                 display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sidebar-logo-mark  { display: block; width: auto; height: 24px;
                      max-width: 100%; object-fit: contain; }   /* <img src="assets/img/reactor_white.png"> */

.sidebar-nav   { padding: 8px 0 12px; flex: 1; }
.sidebar-footer{ padding: 10px 20px; font-size: .75rem; color: rgba(255,255,255,.7);
                 border-top: 1px solid rgba(0,0,0,.2); text-align: center;
                 letter-spacing: .03em; font-family: monospace; }

.nav-item { display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; font-size: .9rem; color: rgba(255,255,255,.85);
            cursor: pointer; border-left: 3px solid transparent;
            transition: background .15s, color .15s; text-decoration: none; }
.nav-item:hover  { background: rgba(0,0,0,.18); color: #fff; }
.nav-item.active { background: rgba(0,0,0,.28); color: #fff;
                   border-left-color: #fff; font-weight: 600; }
.nav-icon { font-size: 1.1rem; width: 20px; text-align: center; }

/* Grupos colapsables
 * Viven dentro del rojo, asi que NO usan --text / --muted / --border:
 * texto en blanco translucido, las bandas internas son negro translucido
 * (mas oscuras que el rojo de fondo para indicar nidificacion).
 */
.nav-group-wrap                       { display: block; }
.nav-group-toggle                     { width: 100%; background: none; border: none;
                                        text-align: left; cursor: pointer;
                                        font-family: inherit; color: rgba(255,255,255,.85); }
.nav-group-label                      { flex: 1; }
.nav-group-arrow                      { margin-left: auto; font-size: 1rem; font-weight: 700;
                                        line-height: 1; color: rgba(255,255,255,.7);
                                        transition: transform .2s; }
.nav-group-wrap.open .nav-group-arrow { transform: rotate(45deg); }   /* + → × */
.nav-sub                              { display: none; background: rgba(0,0,0,.18);
                                        border-top: 1px solid rgba(0,0,0,.2);
                                        border-bottom: 1px solid rgba(0,0,0,.2); }
.nav-group-wrap.open .nav-sub         { display: block; }
.nav-sub-item                         { padding-left: 44px; font-size: .85rem; }
.nav-sub-item.active                  { background: rgba(0,0,0,.32); }
```

**Los agrupadores, en orden:** `Inicio` (`fa-house`) · `Propiedad` (`fa-building`) · `Inventario` (`fa-boxes-stacked`) · `Comercial` (`fa-briefcase`) · `Comunicación` (`fa-tower-broadcast`) · `Registros` (`fa-clipboard-list`) · `Seguridad` (`fa-shield-halved`) · `Administración` (`fa-screwdriver-wrench`). **El orden es el del ciclo de vida de un dominio** —quién es, qué equipos tiene, qué contrató, qué se le dijo, qué generó— y no alfabético ni por frecuencia de uso: por eso `Comercial` va inmediatamente después de `Inventario`, y `Comunicación` entre `Comercial` y `Registros` (08/09/2026). Cada `data-group` del HTML tiene que coincidir con el `group` que la ruta declara en `routes` (`app.js`), que es lo que `openGroup()` usa para dejar abierto el agrupador correcto al navegar.

**`Comunicación` agrupa los dos sentidos del mismo eje** y por eso es un agrupador y no dos ítems sueltos: `Notificaciones` (`fa-comment-dots`) es lo que el **sistema** le dice al dominio —los avisos que la app muestra en su modal, generados por un proceso del legacy que está fuera de este repo— y `Difusión` (`fa-paper-plane`) es lo que **Reactor** le dice a la gente por correo. Los dos íconos se eligieron para no chocar con los del resto del menú: `fa-bell` ya es `Alertas` y `fa-comments` se confundía con el del propio agrupador. Al agregar un ítem acá, verificar el glifo en `assets/fontawesome/icons.json` —tiene que estar en la familia solid (`"c"` contiene `s`)— y que no esté ya en uso.

**Patrón:** la cabecera del sidebar contiene **solo el logo** centrado — `<img src="assets/img/reactor_white.png" class="sidebar-logo-mark">` a 32 px de alto, sin texto "REACTOR / cloud" adjunto. La celda completa (`.sidebar-logo`) mide **60 px de alto** para empatar exactamente con la altura del topbar (§5), de manera que el corte horizontal entre chrome y contenido sea una línea continua entre sidebar y main. Debajo, los items de primer nivel pueden ser navegación directa (`<a class="nav-item">`) o **grupos colapsables** (`.nav-group-wrap` con un `<button class="nav-group-toggle">` que aloja un `.nav-sub` con uno o más `.nav-sub-item`). El glifo `+` del toggle rota 45° al abrir (queda como `×`). Cuando el JS navega a una sub-ruta debe agregar la clase `open` al grupo correspondiente para que el sub-menú permanezca visible. Footer con versión en monospace. **No** introducir tokens grises ni `--text` / `--muted` / `--border` dentro del sidebar (tampoco del topbar — ver §5): textos en `#fff` u opacidades de blanco; bandas internas y estados en negros translúcidos.

```html
<nav class="sidebar-nav">
  <a href="#/dashboard" class="nav-item active">
    <i class="fa-solid fa-gauge-high nav-icon"></i> Dashboard
  </a>

  <div class="nav-group-wrap" data-group="inventario">
    <button type="button" class="nav-item nav-group-toggle">
      <i class="fa-solid fa-boxes-stacked nav-icon"></i>
      <span class="nav-group-label">Inventario</span>
      <span class="nav-group-arrow">+</span>
    </button>
    <div class="nav-sub">
      <a href="#/dispositivos" class="nav-item nav-sub-item">
        <i class="fa-solid fa-microchip nav-icon"></i> Dispositivos
      </a>
    </div>
  </div>
</nav>
```

## 5. Topbar

El topbar comparte el rojo institucional con el sidebar (`background: var(--primary)`). Por eso sus hijos siguen la misma regla del §4: `#fff` u opacidades de blanco para texto, negros translúcidos para estados. **No** usar `--text` / `--muted` / `--border` dentro del topbar — esos tokens están calibrados para gris.

**Contenido fijo:** a la izquierda el `hamburger` (solo en mobile) + `.topbar-title` con el nombre de la vista; a la derecha, **únicamente** el `.topbar-username` con su `.user-dropdown`. No se agregan acciones globales al lado del nombre de usuario — en particular **no hay botón "Refrescar" en el topbar**: cada módulo/herramienta expone su propio refresco en su toolbar o en el header de su card.

El `.user-dropdown`, en cambio, se despliega *bajo* el topbar sobre el área gris del contenido, así que sí usa los tokens grises normales.

```css
.topbar          { background: var(--primary);
                   border-bottom: 1px solid rgba(0,0,0,.25); }

.topbar-title    { font-size: 1rem; font-weight: 600; flex: 1; color: #fff; }
.topbar-user     { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.topbar-username { background: none; border: none; cursor: pointer;
                   font-size: .85rem; color: rgba(255,255,255,.85);
                   display: flex; align-items: center; gap: 4px;
                   padding: 6px 10px; border-radius: 8px;
                   transition: background .15s, color .15s; }
.topbar-username:hover { background: rgba(0,0,0,.18); color: #fff; }

/* Botones del topbar — ghost adaptado a fondo rojo */
.topbar .btn-ghost       { color: rgba(255,255,255,.85);
                           border-color: rgba(0,0,0,.25);
                           background: transparent; }
.topbar .btn-ghost:hover { background: rgba(0,0,0,.18); color: #fff; }
.topbar .hamburger       { color: #fff; }

/* Dropdown — se renderiza sobre el área gris, usa tokens normales */
.user-dropdown   { display: none; position: absolute; right: 0; top: calc(100% + 6px);
                   background: var(--surface); border: 1px solid var(--border);
                   border-radius: 10px; box-shadow: var(--shadow-lg);
                   min-width: 160px; overflow: hidden; z-index: 200; }
.user-dropdown.open { display: block; }
```

## 6. Botones

```css
.btn { padding: 8px 16px; border-radius: var(--radius); border: none;
       font-size: .88rem; font-weight: 600; cursor: pointer;
       display: inline-flex; align-items: center; gap: 6px;
       transition: background .15s, transform .1s; }
.btn:active        { transform: scale(.97); }
.btn-primary       { background: var(--primary); color: #fff; }
.btn-primary:hover { background: var(--primary-h); }
.btn-danger        { background: var(--danger); color: #fff; }
.btn-danger:hover  { background: #c91515; }
.btn-secondary     { background: var(--surface); color: var(--text);
                     border: 1px solid var(--border); }
.btn-secondary:hover { background: var(--bg); }
.btn-ghost         { background: transparent; color: var(--muted);
                     border: 1px solid var(--border); }
.btn-ghost:hover   { background: var(--bg); color: var(--text); }
.btn-sm            { padding: 5px 12px; font-size: .8rem; }
.btn-icon-only     { padding-left: 9px; padding-right: 9px; }
.btn-icon-sm       { background: none; border: none; cursor: pointer;
                     padding: 4px 8px; border-radius: 6px; font-size: .85rem; }
.btn-icon-sm:hover { background: var(--bg); }
```

**Regla:** una sola acción primaria por pantalla o modal. El resto son `secondary` o `ghost`. `danger` solo para destruir / eliminar.

**`.btn-icon-only` vs `.btn-icon-sm`** — los dos son botones sin texto y no son intercambiables:

- `.btn-icon-only` es un **modificador de `.btn`**: conserva fondo, borde y alto de la variante que lo acompaña, y sólo achica el padding horizontal para que la caja quede cuadrada. Se usa cuando el botón convive con otro `.btn` con texto y los dos tienen que leerse como un par — el `Refrescar` al lado de `Filtros` en la toolbar (§9). El padding vertical lo sigue poniendo `.btn-sm`: es lo que garantiza que queden alineados al píxel, así que **no se pisa**.
- `.btn-icon-sm` es un botón **suelto y sin chrome** (sin fondo ni borde), para íconos que flotan sobre una cabecera o una tarjeta — la × de un modal, el refrescar de una card del dashboard.

Los dos llevan `title` y `aria-label`: sin texto, es lo único que los nombra.

**Única excepción:** la barra de acciones del modal (§21-bis), donde todos los botones menos `Cerrar` van en `btn-primary` — ahí el rojo separa la salida del resto, no marca jerarquía.

## 7. Inputs, selects, textareas

```css
input[type=text], input[type=number], input[type=url], input[type=tel],
input[type=email], input[type=date], input[type=time], input[type=datetime-local],
input[type=password], input[type=search], select, textarea {
  border: 1px solid var(--border); border-radius: var(--radius);
  padding: 8px 12px; font-size: .88rem; background: var(--surface);
  color: var(--text); outline: none; transition: border .15s; font-family: inherit;
}
input:focus, select:focus, textarea:focus {
  border-color: var(--primary); box-shadow: 0 0 0 3px rgba(193,19,19,.25);
}
input:disabled, select:disabled, textarea:disabled,
input[readonly], textarea[readonly] {
  color: var(--muted); background: var(--bg); cursor: not-allowed; opacity: .75;
}
textarea { resize: vertical; min-height: 60px; }

.field-error   { margin-top: 4px; font-size: .78rem; color: var(--danger); }
.input-invalid { border-color: var(--danger) !important;
                 box-shadow: 0 0 0 2px rgba(239,68,68,.18); }
```

## 8. Formularios

```css
.form-row    { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
.form-row-3  { grid-template-columns: repeat(3, 1fr); }
.form-row-4  { grid-template-columns: repeat(4, 1fr); }
.form-group  { display: flex; flex-direction: column; gap: 5px; }
.form-group label {
  font-size: .8rem; font-weight: 600; color: var(--muted);
}
.form-group input,
.form-group select,
.form-group textarea { width: 100%; }
```

**Regla:** label arriba (no inline), `.78–.8rem`, color `var(--muted)`. Validación con `.input-invalid` + `.field-error` debajo.

### 8.1 Secciones de formulario

Para formularios largos —los que editan todas las columnas de una tabla, como
el de **Dispositivos** (35 campos)— los campos se agrupan en secciones con un
rótulo con ícono.

```css
.form-section        { display: flex; flex-direction: column; gap: 12px; }
.form-section + .form-section { margin-top: 4px; }
.form-section-title  {
  display: flex; align-items: center; gap: 8px;
  font-size: .72rem; font-weight: 800;
  text-transform: uppercase; letter-spacing: .06em;
  color: var(--muted);
  padding-bottom: 8px; border-bottom: 1px solid var(--border);
}
.form-section-title i { font-size: .8rem; opacity: .8; }
.form-section-btn {
  margin-left: auto;
  text-transform: none; letter-spacing: normal;
}
```

```html
<div class="form-section">
  <div class="form-section-title"><i class="fa-solid fa-sitemap"></i>Asignación</div>
  <div class="form-row"> … </div>
</div>

<!-- Con acción en el rótulo -->
<div class="form-section">
  <div class="form-section-title">
    <i class="fa-solid fa-key"></i>Credenciales
    <button class="btn btn-sm btn-secondary form-section-btn">Regenerar</button>
  </div>
  …
</div>
```

**Reglas:**

- **El separador es el título, no un recuadro.** La sección no lleva fondo ni
  borde propio: adentro de un modal, encajar una caja dentro de otra ensucia
  la jerarquía. La única línea es la de abajo del rótulo.
- El rótulo usa la tipografía de rótulo de sección (`.72rem`, `800`,
  mayúsculas), la misma familia visual que el toggle de categoría del sidebar.
- **Ícono obligatorio**, FontAwesome solid, a `.8rem` y `opacity: .8` — lo
  suficiente para anclar la sección sin competir con los labels.
- **La misma agrupación se usa en Consultar y en Editar** del mismo recurso,
  para que los dos modales se lean igual y el ojo encuentre cada campo en el
  mismo lugar.
- **La acción de la sección va DENTRO del rótulo, con `.form-section-btn`** —
  no en una fila propia arriba de los campos. `margin-left: auto` la manda
  contra el borde derecho y las dos reglas tipográficas se cancelan porque el
  rótulo va en versalitas y un botón no. La usa el *Regenerar* de las
  credenciales del asistente de Dispositivos (§43). **Es una sola clase y no
  una por módulo** —la acción que rellena los campos de esa sección—, aunque
  hoy tenga un solo usuario: con un nombre por módulo, el segundo que la
  necesite copia el que encuentre primero.
- **Sólo para la acción que llena la sección.** Un botón que hace otra cosa
  —navegar, borrar, abrir otro módulo— compite con el rótulo y no va acá.
- **Y antes de agregar el botón, ver si el campo no lo resuelve solo.** El
  *Elegir de clientes* del editor de Comprobantes (§25-quinquies) vivía acá
  hasta que el campo `Cliente` pasó a ser el autocompletar de §34-bis: con el
  combo en el formulario, el rótulo, el botón y el campo de id sobraban los
  tres. Un botón que rellena campos es la salida cuando el dato **no** tiene
  un campo propio donde elegirlo; cuando lo tiene, es un rodeo.

### 8.2 Ayuda del campo (el `?` con globito)

Cuando un campo necesita una explicación, va **el signo de pregunta al lado del
rótulo** y el texto en un globito que se abre al pasar el mouse — no una
`.form-nota` debajo del control.

```html
<div class="form-group">
  <label for="con-promo">
    Promoción (%)
    <span class="field-help" tabindex="0" role="note">
      <i class="fa-regular fa-circle-question"></i>
      <span class="field-help-tip">El descuento se aplica al abono del plan…</span>
    </span>
  </label>
  <input type="number" id="con-promo" min="0" max="100" step="5" placeholder="Sin promoción">
</div>
```

Lo arma `ayudaDeCampo(html)`, que devuelve sólo el markup: el hover y el foco
son CSS y no hay nada que cablear.

**Reglas:**

- **Reemplaza a la `.form-nota` bajo el control, no la acompaña.** Los campos
  van de a dos por renglón (`.form-row` es un grid de dos columnas): una nota de
  tres líneas debajo de uno empuja hacia abajo sólo su mitad y deja los dos
  controles desalineados. La nota al pie sigue valiendo para lo que habla del
  formulario entero y no de un campo — en Contratos, la de las fechas centinela.
- **El texto va en el HTML, no en un `title=`.** El tooltip nativo del sistema
  tarda casi un segundo, no se puede dar estilo, no admite `<strong>` y no
  aparece nunca al navegar con teclado. (El `title=` sí se sigue usando para
  rótulos de un botón de ícono, que es otra cosa: nombrar el control, no
  explicarlo.)
- **ABRE HACIA ABAJO.** El `.modal-body` scrollea (`overflow-y: auto`), así que
  un globito que sube por encima del primer renglón queda recortado contra el
  borde del modal. Y se ancla al borde **izquierdo** del ícono en vez de
  centrarse, para que crezca hacia adentro del formulario.
- **En la ÚLTIMA COLUMNA del `.form-row` se ancla al borde derecho**
  (`.form-row > *:last-child .field-help-tip { left: auto; right: 0 }`), y eso
  no es estética: anclado a la izquierda el globito mide hasta 280 px y se sale
  del modal, que al ser `overflow-y: auto` se vuelve contenedor de scroll en los
  **dos** ejes — o sea que aparece una **barra horizontal** en un formulario que
  entra holgado. Pasó con `Orden` en la tercera columna de *Editar plan*
  (`scrollWidth` 831 contra 760 de ancho). La regla mira la **posición en la
  grilla** y no una clase que ponga quien llama: un campo se mueve de columna al
  reordenar el formulario y nadie se acordaría de cambiarle el modificador. Con
  `:last-child` la misma línea cubre `.form-row`, `.form-row-3` y `.form-row-4`.
- **`pointer-events: none` en el globito**: cae encima del campo de abajo y sin
  eso se come sus clicks.
- **Lleva `tabindex="0"`** y el CSS engancha `:focus-visible` además de `:hover`:
  sin eso la ayuda no existe para quien no usa mouse.
- **El texto es literal del código.** `ayudaDeCampo()` inyecta el HTML tal cual
  —lleva `<strong>`—, así que nunca se le pasa un dato de la base.
- **Y por eso mismo un DATO nunca va en un globito.** El globito es para lo que
  **explica** el campo, no para lo que lo **completa**: un dato cambia con el
  campo y hay que poder mirarlo sin ir a buscarlo con el mouse, así que va como
  `.form-nota` debajo. El caso que lo fijó fue el nombre del cliente bajo
  `Cliente (ID)` en el editor de Comprobantes (§25-quinquies) — y la salida
  mejor terminó siendo otra: el campo pasó a ser el autocompletar de §34-bis,
  que escribe el nombre **adentro** del propio campo y no necesita ninguna
  línea de apoyo. Un dato que hay que explicar debajo del campo suele ser señal
  de que el control muestra el id y no la cosa.

### 8.3 Un campo a todo el ancho

`.form-row` es un **grid de dos columnas fijas**: un único hijo ocupa nada más
que la primera y deja la mitad derecha vacía. Un campo que tiene que ocupar el
100 % **sale del `.form-row`** y va suelto dentro de la `.form-section`, que es
flex column con el mismo `gap`. Lo usa `Nombre` en Editar artículo, que lleva un
texto largo (`CE-M1OC | Control de Encendido Minibox 1 Canal`).

El `<div class="form-group"></div>` vacío como relleno es lo contrario: sirve
cuando el campo **sí** ocupa media fila y se quiere dejar el hueco a la derecha
(`Tipo` en Editar contrato).

**El ancho sale de lo que el campo ADMITE, no de lo que significa.** `Promoción`
estuvo a todo el ancho mientras fue un desplegable con el texto de cada promo
("Descuento 15 %"); desde que es un campo numérico de tres dígitos pasó a un
tercio de renglón, junto a las dos fechas de vigencia (§8.4). Un campo
importante y corto se lee peor estirado: el cursor queda a quince centímetros
del rótulo.

### 8.4 Campos que son un solo dato: van en el mismo renglón

Cuando dos o tres controles **no se entienden por separado**, van juntos en un
`.form-row` (dos) o `.form-row-3` (tres) aunque el formulario tenga lugar de
sobra para apilarlos. El caso canónico es **`Promoción (%)` + `Promo vigente
desde` + `Promo vigente hasta`** en Editar contrato, un tercio cada uno:

- **El criterio es que uno sin los otros no hace nada.** `contratos_accion.php`
  sólo agrega el renglón del descuento si `desde <= hoy <= hasta`, así que un
  porcentaje sin fechas es un campo cargado que no se aplica nunca. Puestos en
  renglones distintos hay que acordarse de mirar el de abajo; en el mismo
  renglón la fila entera se lee de una.
- **No es "agrupar lo parecido".** `Registro` y `Firma` son dos fechas y van de
  a dos por otro motivo (entran holgadas), pero cada una vale por sí sola. Lo de
  acá es más fuerte: el renglón es **el dato**, partido en los controles que
  hacen falta para escribirlo.
- **Hasta tres por renglón**, que es lo que `.form-row-3` deja legible en el
  ancho del modal; con cuatro los rótulos se cortan. `Actual` / `Mínimo` /
  `Recomendado` —el stock en Editar artículo— es el otro ejemplo vivo.

## 9. Toolbar (filtros + búsqueda + acciones)

Patrón normativo para el encabezado de cualquier listado ABM (ver `ABM.md` §2).

- **Zona izquierda**: input de búsqueda rápida (`.search-wrap > .search-input`) + botón `Filtros` (`btn-secondary` con `fa-filter`) + botón `Refrescar` (mismo `btn-secondary`, pero `btn-icon-only` con `fa-rotate` y sin texto). El botón `Filtros` abre el Modal de Filtros (§23-bis), que es la fuente completa de filtros del módulo. La búsqueda rápida es sólo un atajo.
- **Zona derecha**: una sola acción primaria `+ Nuevo <entidad>` (`btn-primary`).

No hay chips de filtro inline en listados ABM nuevos (`.filter-chip` queda como utilitario legacy).

```html
<div class="toolbar">
  <div class="toolbar-left">
    <div class="search-wrap">
      <input type="search" id="dev-quick" class="search-input"
             placeholder="Buscar UID, serial, nombre, tipo, ubicación…">
      <button type="button" class="search-clear" data-act="quick-clear" title="Limpiar búsqueda">×</button>
    </div>
    <button type="button" class="btn btn-secondary btn-sm" id="dev-filters">
      <i class="fa-solid fa-filter"></i> Filtros
    </button>
    <button type="button" class="btn btn-secondary btn-sm btn-icon-only"
            id="dev-refresh" title="Refrescar" aria-label="Refrescar listado">
      <i class="fa-solid fa-rotate"></i>
    </button>
  </div>
  <div class="toolbar-right">
    <button type="button" class="btn btn-primary btn-sm" id="dev-new">
      <i class="fa-solid fa-plus"></i> Nuevo dispositivo
    </button>
  </div>
</div>
```

```css
.toolbar       { display: flex; align-items: center; gap: 12px;
                 flex-wrap: wrap; margin-bottom: 20px; }
.toolbar-left  { display: flex; align-items: center; gap: 10px;
                 flex: 1; flex-wrap: wrap; }
.toolbar-right { display: flex; gap: 10px; }

.search-wrap        { position: relative; display: inline-flex; align-items: center; }
.search-wrap .search-input { width: 240px; padding-right: 28px; }
.search-clear       { position: absolute; right: 6px; background: none; border: none;
                      cursor: pointer; color: var(--muted); font-size: 1.1rem;
                      padding: 2px 4px; border-radius: 50%; transition: color .15s; }
.search-clear:hover { color: var(--text); }

/* Refrescar: mismo `btn-secondary btn-sm` que Filtros, sin texto. Solo se pisa
   el padding horizontal, asi los dos botones quedan del mismo alto (§6). */
.btn-icon-only      { padding-left: 9px; padding-right: 9px; }
```

**Reglas:**
- El `placeholder` del input de búsqueda rápida lista los campos sobre los que opera la búsqueda (UID / nombre / tipo / ubicación, operador / nº / ICCID / notas, etc.) — **y son exactamente los campos que la búsqueda mira**: buscar sobre una columna que la pantalla no anuncia devuelve filas que el operador no puede explicar.
- **LA BÚSQUEDA POR TEXTO SE RESUELVE EN SQL, EN LOS 18 LISTADOS.** El texto libre —el de la toolbar y el del Modal de Filtros— viaja como `?q=` y lo resuelve `busquedaWhere()` de `lib/busqueda.php` (`ABM.md` § "Cómo busca el texto libre"): sin acentos en ninguna de las dos puntas (`gonzalez` ↔ `González`, `nino` ↔ `Niño`), sin distinguir mayúsculas, por **pedazos** de palabra y con los términos **sueltos y en cualquier orden** (`mari gon` encuentra a `María González`). Los términos se cruzan con Y y cada uno se busca en todas las columnas con O, que es la misma regla del combo con buscador (§34-bis): agregar una palabra **acota** el resultado, nunca lo agranda. Ningún módulo arma su propio filtro de texto, y ninguno lo filtra en el navegador: un filtro JS sólo ve lo que se trajo, así que contestaría "no hay resultados" sobre filas que existen.
  - El cableado sale del helper compartido `wireBuscadorSql()`: debounce de 300 ms, token de carrera para descartar la respuesta que llega tarde, y **la `stats-bar` no se repinta** (los KPIs son del universo, no de la consulta — ver abajo).
- El resto de los filtros sí se aplica al `input`/`change` event sin re-fetch (filtrado client-side por defecto). Señales y Registros son casos mixtos: filtran client-side sobre la última página descargada, pero re-fetchean cuando cambian los parámetros `?dispositivo=` o `?limit=` server-side.
- El botón `Filtros` es secundario, no primario — la acción primaria del listado es siempre `+ Nuevo <entidad>`, una sola por pantalla (ver §6).
- **`Listar` es el desplegable de filtros rápidos, y va ANTES de `Filtros`** (`abmToolbar({ listar: true })` + `wireListarMenu(idPrefix, itemsFn)`). Mismo `btn btn-secondary btn-sm` que `Filtros`, con `fa-list` y el `fa-caret-down .menubar-caret` que ya usa el desplegable de la barra de acciones del modal (§21-bis); el menú lo abre `openRowMenu()`, así flota en vez de quedar recortado. **Ofrece exactamente los mismos atajos que las stat cards (§12.1) y los aplica por la misma función** — no una copia del criterio: con dos, tocar el mismo atajo en la tarjeta y en el menú podría dar listas distintas y la tarjeta quedaría desmintiendo al menú. Lo lleva **el módulo que tiene atajos**, hoy sólo Contratos.
  - **No reemplaza a las tarjetas ni ellas a él.** La tarjeta dice *cuántos hay* y se toca sabiendo el número; el menú se abre sin apuntarle a nada y nombra los atajos en una lista. Un módulo con atajos merece los dos: llegar al subconjunto no puede depender de acertarle a una tarjeta, ni de abrir `Filtros` y armarlo campo por campo, que es lo que el desplegable viene a sacar del medio.
  - **El orden y los separadores son los del menú `Listar` del back office viejo** (`reactor-admin/contratos/listar.php`): *Todos* — *Habilitados / Deshabilitados* — *Facturables / Remisibles*. Se respetan porque es el agrupamiento con el que la gente ya trabaja, no por nostalgia: los dos pares separados son "por estado" y "por lo que hay que hacerles".
- **`Refrescar` va inmediatamente a la derecha de `Filtros`, sin texto** (`btn-icon-only` con `fa-rotate`, `title` + `aria-label` "Refrescar listado"). No lleva rótulo porque el ícono de recarga es universal y porque en la zona izquierda compite con la búsqueda rápida, que es lo que el usuario tiene que encontrar primero; comparte variante con `Filtros` para que se lean como un par y no como una acción suelta.
- **Refrescar re-renderiza el módulo entero, no sólo las filas.** Vuelve a pedir todos los `fetch` del `render*()` —listado, catálogos y **stat cards**—: refrescar sólo la tabla dejaría los KPIs contando lo viejo arriba de datos nuevos, que es peor que no refrescar. Por eso está implementado como `navigate()` y no como un re-fetch local del `applyAndRender()`.
- **Y conserva los filtros y la búsqueda rápida vigentes.** `navigate()` arranca cada vista de cero, así que el módulo se deja su propio `state` preparado antes de re-renderizar (`refrescarVista(route, state)` → `tomarEstadoVista(route, defaults)` en el `render*()`; mismo criterio de un solo uso y misma-ruta que `pendingDominioFilter`, §21-bis.1). Un refresh que limpia los filtros no refresca: cambia de pantalla.
- Los tres pedazos salen del helper compartido `abmToolbar()` / `wireRefresh(idPrefix, route, state)`, así que ningún módulo dibuja ni cablea el suyo.
- Si el módulo es read-only (señales, registros, adopciones, alertas), se omite el botón `+ Nuevo` y la toolbar colapsa a sólo búsqueda rápida + Filtros. El helper `abmToolbar` lo soporta nativamente pasando `newLabel: null`.
- `.filter-chip` queda en CSS como utilitario suelto, pero **no se usa en listados ABM nuevos**.

## 10. Tablas

```html
<div class="table-card">
  <table>
    <thead><tr><th>…</th></tr></thead>
    <tbody>…</tbody>
  </table>
</div>
```

```css
.table-card { background: var(--surface); border: 1px solid var(--border);
              border-radius: var(--radius); box-shadow: var(--shadow);
              overflow-x: auto; overflow-y: hidden; }

table       { width: 100%; border-collapse: collapse; font-size: .88rem; }
thead tr    { background: var(--bg); }
th          { padding: 10px 14px; text-align: left;
              font-size: .75rem; text-transform: uppercase; letter-spacing: .05em;
              color: var(--muted); font-weight: 600;
              border-bottom: 1px solid var(--border); white-space: nowrap; }
td          { padding: 10px 14px; border-bottom: 1px solid var(--border);
              vertical-align: middle; }
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover { background: var(--row-hover); }

.actions     { display: flex; gap: 4px; }
.table-empty { text-align: center; padding: 48px 24px; color: var(--muted); }

.td-img      { width: 44px; height: 44px; object-fit: cover;
               border-radius: 8px; border: 1px solid var(--border); }
.td-nombre   { font-weight: 600; }
.td-id       { color: var(--muted); font-size: .8rem; }
```

**Un atributo de un dato que ya está en la celda se dibuja como ÍCONO pegado a
ese dato, no como columna nueva.** En Contratos, `plan_modo` va a la derecha del
nombre del plan dentro de `Tipo / Plan`: candado si es `fijo` —queda el plan
pactado aunque el dominio crezca— y reciclado si es `dinamico`, o sea que sigue
al consumo (`.plan-modo-icon`). Cuatro reglas:

- **Es un ícono porque es un adjetivo del dato, no otro dato.** Una columna
  propia obliga a cruzar la fila entera para saber de qué plan habla, y suma
  ancho a un listado que ya scrollea en horizontal.
- **Los dos estados se dibujan, no sólo uno.** Un ícono que aparece y desaparece
  se lee como "tiene algo raro"; dos íconos distintos se leen como una elección.
- **Lo que distingue un estado del otro es la FORMA del ícono, no el color.** Los
  dos van en `--muted`, el mismo gris del texto al que acompañan: son un
  atributo de ese dato y no un estado que compita con él. Pintar uno de los dos
  lo convierte en una alerta que nadie pidió — y en una columna entera de íconos
  de color no se distingue nada.
- **Si el dato base falta, el ícono tampoco va.** Un candado al lado de "Sin
  plan" prometería que hay algo fijado.

**UN ESTADO SÍ ES COLUMNA PROPIA, y no contradice la regla de arriba.** La
distinción es qué tipo de dato es, no cuánto ocupa: `plan_modo` es un **adjetivo
del plan** —no se entiende sin saber de qué plan habla, así que va pegado a él—
mientras que un **estado** es un dato por derecho propio, se lee solo y es
justamente lo que alguien viene a barrer de arriba abajo. El precedente es
`habilitado`, que es columna con badge en todos los listados del módulo desde
siempre.

Por eso **`Situación` es una columna**, con el badge de §11 y los tres tonos de
`badgeSituacion()` (`1` Normal verde · `2` Limitado ámbar · `3` Suspendido rojo):

| listado | columnas nuevas | de dónde sale |
|---|---|---|
| **Dominios** | `Situación` · `Habilitado` | `dominios`.`situacion` y `.habilitado` |
| **Contratos** | `Situación` | **`dominios`.`situacion`** — la del dominio del contrato |

- **Van las dos últimas antes de `Acciones`**, con `Habilitado` pegado a
  `Acciones`: es el orden que ya tenían Contratos y el resto de los listados, y
  mover la bandera rompería la lectura de columna que la gente ya tiene hecha.
- **EN CONTRATOS LA SITUACIÓN ES LA DEL DOMINIO, no una columna de
  `contratos`.** El endpoint ya traía `dominio_situacion` y se le agregó
  `dominio_situacion_texto`; el código corto lo traduce **el backend** contra
  `combos` (`'$xDominio->situacion'`), nunca una tabla de textos en el front
  (ABM.md). **Ojo con `contratos`.`situacion`**, que existe desde la migración
  `20261001_1000` y es **otra cosa** —la mora que calcula el job de las 04:00,
  §33-septies—: hoy no se muestra en ninguna pantalla, y el día que se muestre
  necesita un rótulo que la distinga de ésta.
- **Un contrato sin dominio dibuja la raya**, no un badge: sin dominio no hay
  situación que mostrar. Hoy no hay ninguno (53 de 53 tienen dominio), igual que
  los 148 dominios tienen `situacion` cargada — las dos ramas existen para el
  dato futuro, no para el actual.

## 11. Badges

Los badges usan fondo translúcido sobre el rojo oscuro de la app — no fondos pasteles sólidos (no contrastarían bien con `--surface`).

```css
.badge         { display: inline-block; padding: 2px 10px;
                 border-radius: 99px; font-size: .75rem; font-weight: 600; }
.badge-info    { background: rgba(59,130,246,.18); color: #93c5fd; }
.badge-success { background: rgba(34,197,94,.18);  color: #86efac; }
.badge-danger  { background: rgba(230,42,42,.2);   color: #f5a8a8; }
.badge-warn    { background: rgba(245,158,11,.18); color: #fcd34d; }
```

**DENTRO DE UN MODAL DE CONSULTAR (§25) EL AZUL ES EL ENLACE Y NADA MÁS.** La
pastilla `badge-info` de una ficha significa *esto lleva a otra ficha* —o sea
que además es `.badge-link`, un `<button>`— y ninguna otra cosa. Todo lo que ahí
es informativo y no lleva a ningún lado va en **`badge-success`**: el tipo, los
contadores de filas relacionadas, cualquier píldora neutra.

- **El motivo es que en una grilla de tarjetas todas las píldoras se ven a la
  vez.** Con contadores, tipos y enlaces del mismo azul no hay forma de saber
  cuál se puede clickear salvo pasando el mouse por todas; reservado el color,
  la única azul de la ficha es la que se puede abrir.
- **Los estados conservan su color, que es semántico y no decorativo**:
  `Habilitado` sigue en `badge-success`, `Deshabilitado` en `badge-danger` y los
  avisos en `badge-warn`. Pintarlos de verde por esta regla diría lo contrario
  de lo que pasa.
- **La regla vale de la ficha para adentro.** Fuera de los modales de Consultar
  —el badge de la BD del Migrador, los chips de entorno, los estados de una
  herramienta— el azul sigue siendo informativo: ahí no compite con ningún
  enlace. Hoy la aplica **Consultar plan** (§42); el resto de las fichas todavía
  tiene contadores en azul.

## 12. Stat cards (resúmenes numéricos)

Para cualquier pantalla que muestre métricas, incluido dashboard.

```html
<div class="stats-bar">
  <div class="stat-card">
    <span class="stat-label">Pedidos hoy</span>
    <span class="stat-value orange">128</span>
  </div>
</div>
```

```css
.stats-bar  { display: flex; gap: 14px; margin-bottom: 20px; flex-wrap: wrap; }
.stat-card  { background: var(--surface); border: 1px solid var(--border);
              border-radius: var(--radius); padding: 14px 20px;
              display: flex; flex-direction: column; gap: 2px;
              flex: 1; min-width: 120px; }
.stat-label { font-size: .75rem; color: var(--muted);
              text-transform: uppercase; letter-spacing: .04em; }
.stat-value { font-size: 1.5rem; font-weight: 700; }
.stat-value.green  { color: var(--success); }
.stat-value.orange { color: var(--primary); }
.stat-value.red    { color: var(--danger); }
.stat-value.warn   { color: var(--warn); }
```

**`.orange` NO es ámbar: apunta a `--primary`, el rojo institucional.** El ámbar
de verdad es `.warn` (`--warn`), y existe para las tarjetas que son el desglose
de un estado que la tabla ya pinta con un badge — ahí el tono tiene que ser el
mismo de los dos lados. *Situación Limitado* usa `badge-warn` en la celda; con
`.orange` la tarjeta habría dicho rojo mientras la celda decía ámbar, y serían
dos códigos de color para un solo dato.

Si la stat-card es clickeable, agregale `.dash-link`:

```css
.dash-link { cursor: pointer; transition: opacity .15s; }
.dash-link:hover { opacity: .75; }
.stat-card.dash-link:hover { background: var(--bg); }
```

### 12.1 Las stat cards como filtros rápidos

**Una tarjeta que cuenta un subconjunto del listado que está abajo es el filtro
rápido de ese subconjunto.** En **Contratos** las cinco —Total, Habilitados,
**Situación Normal**, **Situación Limitado**, **Situación Suspendido**— dejan el
listado en lo que anuncian. La idea sale del menú `Listar` del back office viejo
(`reactor-admin/contratos/listar.php`).

**Las tres de situación son el desglose de `Habilitados`, NO de `total`.** La
situación es la del **dominio** del contrato (la columna de §10) y **se cuenta
sólo sobre los habilitados**: un contrato dado de baja no tiene situación que
atender, y contarlo inflaría los tres números con filas que nadie va a mirar.

**Los dos lados llevan el mismo `habilitado = 1`, y eso no es opcional**: el
contador (`$resumen['situacion']` en `api/contratos.php`) y el atajo
(`ATAJOS_CONTRATOS`, que arma `{ estado: '1', situacion: 'N' }`). Si uno filtra
por habilitado y el otro no, la tarjeta dice un número y la lista muestra otro —
que es exactamente lo que la regla de abajo prohíbe. Se cambian juntos o no se
cambian.

Un contrato sin dominio no tiene situación y no entra en ninguna de las tres —
hoy no hay ninguno, así que los tres suman `Habilitados`; el backend no fuerza
esa suma.

**EL MENÚ `Listar` ES EL SUPERCONJUNTO, NO EL ESPEJO.** Hasta que las tarjetas
pasaron a mostrar el desglose por situación eran las mismas cinco entradas de los
dos lados. Ahora el menú tiene **ocho**: `Deshabilitados`, `Facturables` y
`Remisibles` salieron de las tarjetas y **siguen vivos ahí**, que es lo que
impide que un cambio de tarjetas se lleve puesta una forma de listar. Lo que no
puede pasar es lo inverso —una tarjeta sin entrada de menú—, porque entonces
habría un atajo que sólo existe mientras esa tarjeta esté a la vista.

**Los mismos atajos tienen un segundo acceso: el desplegable `Listar` de la
toolbar** (§9). La tarjeta y el ítem del menú son el mismo atajo por dos
caminos, y se aplican por **una sola función** (`aplicarAtajo()`): el catálogo
del menú (`MENU_LISTAR_CONTRATOS`) dice qué se dibuja y en qué orden, pero el
estado de cada atajo sale siempre de `ATAJOS_CONTRATOS`, que es lo que leen
también las tarjetas.

**Un atajo nuevo que filtre por un campo exige el campo en el Modal de Filtros**
(ABM.md §1.3): por eso `Situación` se sumó ahí, pegada a `Habilitado`. Sin ese
campo el listado queda acotado sin que la pantalla lo explique ni se pueda
limpiar. Sus opciones salen del catálogo del endpoint (`situaciones`, que es
`comboLista('$xDominio->situacion')`), nunca de una lista escrita en el front.

```html
<div class="stat-card dash-link" data-atajo="habilitados" role="button" tabindex="0"
     title="Ver sólo los habilitados">
```

**Reglas:**

- **El atajo REEMPLAZA el estado de la vista, no se suma a él.** Se parte de los
  defaults y se aplica el atajo encima. Es la condición para que signifique
  algo: la tarjeta dice `28` y tocarla tiene que mostrar 28 filas. Conservando
  el dominio o el texto que hubiera en el buscador, el número y la lista dirían
  cosas distintas y no habría forma de saber cuál manda. **Eso incluye limpiar
  el buscador rápido** — y como ese texto se resuelve en el servidor (`ABM.md`),
  hay que volver a pedir, no sólo re-renderizar.
- **El atajo se arma con filtros que el Modal de Filtros YA tiene.** Así abrirlo
  después de tocar una tarjeta muestra por qué la lista viene acotada, y
  `Limpiar` la desarma — es la misma regla de §21-bis.1. Si un atajo necesitara
  una condición que el modal no ofrece, primero va el filtro al modal.
- **Una fecha que el atajo necesita la calcula el BACKEND y viaja en el
  resumen**, nunca `new Date()` en el navegador. `Facturables` es
  `facturar <= hoy`, y ese `hoy` tiene que ser el mismo con el que el endpoint
  contó la tarjeta: a las 21 h de Buenos Aires `toISOString()` ya devuelve
  mañana, y el atajo mostraría una cantidad distinta de la que se acaba de tocar.
- **`role="button"` + `tabindex="0"` + `Enter` / `Espacio`.** Un `<div>` que
  responde al click y no al teclado es un botón que no existe para quien no usa
  mouse.
- **No toda stat card es un atajo.** Sólo la que cuenta un subconjunto *de ese
  mismo listado*. Un total que no se puede expresar como filtro de la tabla de
  abajo se queda quieto.

## 13. Dashboard grid (solo en pantalla de dashboard)

```css
.dash-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 4px; }
@media (max-width: 768px) { .dash-grid { grid-template-columns: 1fr; } }

.dash-table-header { padding: 14px 20px 10px;
                     font-weight: 600; font-size: .95rem;
                     border-bottom: 1px solid var(--border);
                     display: flex; align-items: center; justify-content: space-between; }
.dash-ver-mas      { font-size: .78rem; font-weight: 500; color: var(--primary); }
```

### 13.1 Feed "Últimas señales" (dashboard)

Card ubicada **dentro** del `.dash-grid` de 2 columnas, en la columna
derecha (a la izquierda vive "Últimos registros"). Polling al endpoint liviano
`api/signals_live.php?since_id=…&limit=5` cada **500 ms**, manteniendo un
buffer rotativo de las últimas 5 señales (las nuevas entran arriba y las
viejas caen al pasar 5).

Estructura:

```html
<div class="table-card dash-live-card" id="live-feed-card">
  <div class="dash-table-header">
    <span><i class="fa-solid fa-signal-stream"></i> Últimas señales</span>
    <div class="dash-live-controls">
      <span class="dash-live-status" id="live-feed-status">
        <span class="live-dot"></span> En vivo · 500 ms
      </span>
      <button class="btn-icon-sm" id="live-feed-toggle" title="Pausar">
        <i class="fa-solid fa-pause"></i>
      </button>
      <a href="#/signals" class="dash-ver-mas">Ver todas →</a>
    </div>
  </div>
  <div id="live-feed-body">…tabla compacta de 4 columnas…</div>
</div>
```

Columnas de la tabla (compacta, sin acciones): **Hora · Dispositivo ·
Sentido · Mensaje**. La columna *Hora* se muestra en dos líneas: la fecha
(`dd/MM/aaaa`) arriba y la hora (`HH:MM:SS`) debajo, ambas en estilo
`td-id` (muted/compacto) para no robar peso visual al feed. La columna
*Dispositivo* muestra sólo el nombre (sin el UUID debajo) por espacio. La
columna *Sentido* en este feed se renderiza sólo como ícono (en vez del
badge usado en `#/signals`): `fa-upload` en `var(--success)` (verde) para
`S` (saliente) y `fa-download` en `var(--info)` (azul) para `E`
(entrante), con `title` accesible.

Reglas de interacción:

- **Pausa por hover**: al pasar el mouse sobre la card, el polling se
  congela (para poder leer una señal sin que el feed la desplace) y se
  reanuda al salir.
- **Pausa manual**: el botón pausa/play (`#live-feed-toggle`) congela el
  feed de forma persistente. El estado se refleja en `#live-feed-status`
  (texto + apagado del punto verde mediante `.live-paused`).
- **Cleanup al navegar**: el timer se registra en `activeViewCleanup`
  global y se limpia automáticamente al cambiar de ruta. Si la card sale
  del DOM por cualquier motivo, el `tick()` también se autodescarta.
- **Errores transitorios**: silenciados. Polling de 500 ms recupera
  rápido; sólo se marcaría si el fallo pasara a ser persistente.

```css
.dash-live-controls { display: flex; align-items: center; gap: 12px; }
.dash-live-status   { display: inline-flex; align-items: center; gap: 6px;
                      font-size: .72rem; color: var(--muted);
                      text-transform: uppercase; letter-spacing: .04em;
                      font-weight: 500; }
.live-dot           { width: 8px; height: 8px; border-radius: 50%;
                      background: var(--success);
                      animation: live-pulse 1.4s infinite; }
.live-paused .live-dot { background: var(--muted); animation: none; box-shadow: none; }

@keyframes live-pulse {
  0%   { box-shadow: 0 0 0 0   rgba(34,197,94,.55); }
  70%  { box-shadow: 0 0 0 8px rgba(34,197,94,0);   }
  100% { box-shadow: 0 0 0 0   rgba(34,197,94,0);   }
}

.live-feed-table tbody tr.is-new { animation: live-row-flash 1s ease-out; }
@keyframes live-row-flash {
  0%   { background-color: rgba(34,197,94,.18); }
  100% { background-color: transparent; }
}
```

### 13.1-bis Gráfico "Señales por minuto · últimas 24 h" (dashboard)

Card de **ancho completo** ubicada **por encima** del `.dash-grid` de 2
columnas (entre la `.stats-bar` y la grid de "Últimos registros" / "Últimas
señales"). Muestra un gráfico de línea con la cantidad de señales recibidas
por minuto en las últimas **24 horas** (1440 buckets de 1 minuto). Polling
al endpoint `api/signals_stats.php` cada **1 min**.

**Endpoint.** `GET /api/signals_stats.php` devuelve siempre **1440 buckets
de 1 minuto** en orden cronológico ascendente (más viejo → más nuevo),
anclados al **minuto en curso** (ventana móvil `[now-1439min, now]`). Los
minutos sin señales se devuelven con `count: 0` — el front no rellena
huecos. Cada bucket trae `{ minuto: "HH:MM", fecha: "YYYY-MM-DD HH:MM:00",
count: N }`. La respuesta también incluye `total`, `max` y `avg` (señales
por minuto promediadas sobre la ventana de 24 h) ya calculados para
alimentar las métricas del header sin sumar en el cliente.

**Cache materializado** (tabla `senales_por_minuto`, ver migración
`2026-05-20_senales_por_minuto.sql`). `senales` tiene ~35M filas en MyISAM
y sólo índice de PK; un GROUP BY por minuto sobre las últimas 24 h haría
full scan en cada poll. Como las señales son **inmutables** (sólo se
insertan), el count de cualquier minuto pasado es estable de por vida,
así que se materializa en una tabla aparte (`PRIMARY KEY (minuto)`). Flujo
por poll:

1. Leer `MAX(minuto)` del cache.
2. Si faltan minutos cerrados entre ese máximo y el minuto anterior al
   actual, calcularlos con un GROUP BY acotado por `id > MAX(id) -
   LOOKBACK` sobre `senales` e insertarlos con `INSERT IGNORE` (zero-fill
   incluido para que un minuto vacío no se recompute en el próximo poll).
   El `LOOKBACK` se dimensiona en proporción al gap a fillear
   (`gapMinutes × 3500 ids/min`, piso 200k), así el bootstrap inicial de
   24 h escanea ~5M IDs en un único PK-range scan y los polls tibios
   sólo escanean unos pocos miles.
3. Leer los 1439 minutos cerrados del cache por rango de PK (instantáneo).
4. Contar **en vivo** el minuto en curso (es volátil, nunca se cachea)
   con un pivot por PK fijo (lookback chico, 200k).
5. Ensamblar los 1440 buckets.

Tras el bootstrap inicial (una única corrida que recorre 24 h de
`senales`), cada poll sólo cuenta el minuto en curso + agrega 0 o 1 fila
nueva al cache. El histórico nunca más se re-escanea.

**Render.** SVG inline **sin librería externa** (mantiene el bundle limpio
y la curva de carga inmediata). Un `viewBox="0 0 1200 220"` con
`preserveAspectRatio="none"` para que el SVG escale fluido al ancho del
contenedor. Un `<path>` (`.dash-chart-line`) une 1440 vértices — uno por
minuto — centrados en su slot; debajo, un segundo `<path>`
(`.dash-chart-area`) con el mismo recorrido cerrado contra el eje X
rellena el área con la primaria al 15 % de opacidad.

A esta densidad (1440 puntos sobre ~1150 px de ancho) **no se renderizan
círculos individuales ni hit-area por minuto**: cada slot mide ~0,8 px y
los marcadores saturarían el render sin aportar información. La línea
funciona como sparkline y el resumen del header (Total · Pico · Prom)
concentra las métricas del período.

- **Color**: línea y área en `var(--primary)` (rojo institucional
  `#C11313`, consistente con el tema único oscuro/rojo).
- **Eje Y**: 4 líneas horizontales dashed (`stroke-dasharray: 2 3`) con
  labels a la izquierda. El tope se calcula con `niceCeil()` (1, 5, 10, o
  el "lindo" siguiente — 1.5/2/3/5 × 10ⁿ) para que el máximo no quede en
  un número raro como 47.
- **Eje X**: ticks en los minutos cerrados que caen en
  `00:00 / 04:00 / 08:00 / 12:00 / 16:00 / 20:00` (hasta 6 ticks dentro de
  la ventana de 24 h, con sus índices descubiertos escaneando los
  buckets). Si la card es muy angosta el SVG sigue escalando porque está
  bajo `preserveAspectRatio="none"`.

**Header.** Reutiliza `.dash-table-header` con tres bloques en
`.dash-live-controls`:

- `.dash-chart-summary` con tres métricas (**Total · Pico · Prom**),
  calculadas server-side y refrescadas en cada tick.
- `.dash-live-status` con el indicador `.live-dot` + texto `En vivo · 1 min`
  (mismo componente que §13.1, comparte `.live-paused` para el estado de
  error).
- Botón `#signals-chart-refresh` para forzar un fetch manual (el ícono
  gira mientras está fetching).

**Polling.**

- Tick cada **1 min** (`TICK_MS = 60_000`). Los buckets del endpoint son
  de 1 minuto anclados al minuto en curso, así que tickear más rápido no
  aporta resolución; no se justifica polling más agresivo como el feed
  (500 ms) ni el monitor (100 ms).
- Re-render completo del SVG en cada tick (no lógica incremental).
- Guard `fetching` evita requests encolados si el server tarda más que el
  tick (improbable, pero defensivo).
- **Cleanup al navegar**: el timer se registra en `activeViewCleanup` y
  se limpia al cambiar de ruta. Si la card sale del DOM, el `tick()`
  también se autodescarta.
- **Errores**: el status pasa a `Error · reintentando` + se aplica
  `.live-paused` (apaga el punto pulsante). Al primer tick exitoso vuelve
  a `En vivo · 1 min`.

Estructura:

```html
<div class="table-card dash-chart-card" id="signals-chart-card">
  <div class="dash-table-header">
    <span><i class="fa-solid fa-chart-line"></i> Señales por minuto · últimas 24 h</span>
    <div class="dash-live-controls">
      <span class="dash-chart-summary" id="signals-chart-summary">
        <span class="dash-chart-metric">
          <span class="dash-chart-metric-label">Total</span>
          <strong id="signals-chart-total">123</strong>
        </span>
        <span class="dash-chart-metric">
          <span class="dash-chart-metric-label">Pico</span>
          <strong id="signals-chart-max">12</strong>
        </span>
        <span class="dash-chart-metric">
          <span class="dash-chart-metric-label">Prom</span>
          <strong id="signals-chart-avg">2,05</strong>
        </span>
      </span>
      <span class="dash-live-status" id="signals-chart-status">
        <span class="live-dot"></span> En vivo · 1 min
      </span>
      <button class="btn-icon-sm" id="signals-chart-refresh" title="Refrescar">
        <i class="fa-solid fa-arrows-rotate"></i>
      </button>
    </div>
  </div>
  <div class="dash-chart-body" id="signals-chart-body">
    <svg class="dash-chart-svg" viewBox="0 0 1200 220" preserveAspectRatio="none">
      …grid + 60 barras + ticks X…
    </svg>
  </div>
</div>
```

```css
.dash-chart-card  { margin-bottom: 20px; }
.dash-chart-body  { padding: 14px 18px 8px; }
.dash-chart-svg   { width: 100%; height: 220px; display: block; }

.dash-chart-grid       { stroke: var(--border); stroke-width: 1;
                         stroke-dasharray: 2 3; opacity: .7; }
.dash-chart-axis-label { fill: var(--muted); font-size: 10px; }

.dash-chart-bar      { fill: var(--primary); transition: fill .15s ease; }
.dash-chart-bar-zero { fill: var(--border); opacity: .55; }

.dash-chart-bar-hit  { fill: transparent; }            /* hit-area por slot */
.dash-chart-bar-g:hover .dash-chart-bar      { fill: var(--primary-h); }
.dash-chart-bar-g:hover .dash-chart-bar-zero { fill: var(--muted); opacity: .9; }
```

### 13.2 Monitor en tiempo real (modal de Señales)

Modal accesible desde el listado `#/signals` mediante el botón **Monitor en
tiempo real** (`#sig-monitor`) anclado en la zona derecha del toolbar
(usando el slot `extraRight` de `abmToolbar`, ver §9). Es un **log tipo
consola / terminal** para mirar las señales que van ingresando.

Comparte el endpoint (`signals_live.php`) y las reglas de **pausa por
hover** + **botón pausa/play** con el feed del dashboard (§13.1), pero el
**polling es mucho más agresivo: 100 ms** (vs. 500 ms del dashboard) para
que se sienta "en tiempo real". A 100 ms el ojo humano no percibe latencia
y el guard `fetching` evita que se encolen requests si el server tarda más
que el tick. La estética y el layout también son distintos: no es una
tabla, es un panel oscuro monoespaciado al estilo `tail -f`.

**Ancho.** El modal usa una variante propia más ancha que `modal-wide`
(`max-width: 1000px`) porque cada línea contiene timestamp + ID + sentido
+ dispositivo + topic + mensaje en una sola fila sin envolver.
**Importante:** la regla CSS se declara como `.modal.signals-monitor-modal`
(compound, especificidad 20) — el selector simple `.signals-monitor-modal`
no le gana al `.modal { max-width: 520px }` de §14 porque éste está más
abajo en el archivo y tienen la misma especificidad.

**Layout y colores (tipo consola).**

- Body del modal con `background: #0a0a0a` y `padding: 0` (el log ocupa
  todo).
- `.signals-monitor-console`: contenedor scrollable de `height: 65vh`,
  font monoespaciada (`ui-monospace, SFMono-Regular, Menlo, Consolas`),
  `font-size: .8rem`, color base `#d4d4d4` sobre `#0a0a0a`. Scrollbar
  custom oscura (track `#0a0a0a`, thumb `#2a2a2a`).
- Empty state: `$ esperando señales…` con un cursor parpadeante
  (`.signals-monitor-caret`, bloque blanco que blinkea cada 1 s).
- Cada señal es una `.log-line` (flex, `white-space: nowrap`) con spans
  pintados al estilo ANSI:
  - `.log-ts`     `#6b7280` (gris muteado) — `YYYY-MM-DD HH:MM:SS`
  - `.log-id`     `#60a5fa` bold (azul) — `#1234`
  - `.log-arrow.log-in`   `#34d399` (verde) — ` IN` (sentido E)
  - `.log-arrow.log-out`  `#fbbf24` (ámbar) — `OUT` (sentido S)
  - `.log-device` `#e5e7eb` (claro)
  - `.log-topic`  `#67e8f9` (cian)
  - `.log-msg`    `#d4d4d4` con `flex:1` + `text-overflow: ellipsis`
  - `.log-sep`    `#3a3a3a` (separadores `│`)
- Hover sobre una línea: fondo `#1a1a1a`, cursor pointer.
- Nuevas líneas: animación `.is-new` con flash verde (`monitor-line-flash`).

**Orden y scroll.**

- Buffer cronológico ascendente: las **nuevas señales se appendean al
  fondo** (como en una terminal real), no al tope.
- **Auto-scroll incondicional al pie**: cuando llegan nuevas líneas el
  scroll siempre baja al fondo. La pausa por hover ya cubre el caso
  "quiero leer sin que se mueva": al pasar el mouse sobre la consola el
  polling se congela, así que mientras estés sobre una línea no aparecen
  nuevas y no se te mueve la vista.
- Buffer rotativo de **250 líneas**; el primer tick semilla la consola con
  el histórico para que arranque llena en vez de con "Esperando señales…".

**Interacción.**

- Click sobre cualquier línea abre el modal de detalle existente
  (`openSignalViewModal`), reutilizando el Consultar del listado.
- **Pausa por hover** sólo sobre `.signals-monitor-console`, no sobre el
  header — hover sobre el botón de pausa no debe pausar el feed.
- **Cleanup al cerrar el modal** (no usa `activeViewCleanup`: el modal se
  monta sobre la misma vista, no hay navegación).

Estructura:

```html
<div class="modal-backdrop open">
  <div class="modal signals-monitor-modal">
    <div class="modal-header">
      <div class="modal-title">
        Monitor en tiempo real
        <span class="dash-live-status" id="sig-monitor-status">
          <span class="live-dot"></span> En vivo · 100 ms
        </span>
      </div>
      <div class="signals-monitor-controls">
        <button class="btn-icon-sm" id="sig-monitor-toggle" title="Pausar">
          <i class="fa-solid fa-pause"></i>
        </button>
        <button class="btn-icon-sm" data-act="close">×</button>
      </div>
    </div>
    <div class="modal-body signals-monitor-body">
      <div class="signals-monitor-console">
        <div class="log-line is-new" data-id="1234">
          <span class="log-ts">2026-05-20 14:32:01</span>
          <span class="log-sep">│</span>
          <span class="log-id">#1234</span>
          <span class="log-sep">│</span>
          <span class="log-arrow log-in"> IN</span>
          <span class="log-sep">│</span>
          <span class="log-device">uid-abc Nombre dispositivo</span>
          <span class="log-sep">│</span>
          <span class="log-topic">topic/foo</span>
          <span class="log-sep">│</span>
          <span class="log-msg">{"k":"v"}</span>
        </div>
        …
      </div>
    </div>
    <div class="modal-footer">
      <span class="signals-monitor-footer-info">
        <i class="fa-solid fa-terminal"></i>
        <strong>N</strong> de <strong>200</strong> líneas · click sobre una línea para ver detalle
      </span>
      <button class="btn btn-ghost" data-act="close">Cerrar</button>
    </div>
  </div>
</div>
```

## 14. Modales

```html
<div class="modal-backdrop open">
  <div class="modal">
    <div class="modal-header">
      <div class="modal-title">Título</div>
      <button class="btn-icon-sm">×</button>
    </div>
    <div class="modal-body">…</div>
    <div class="modal-footer">
      <button class="btn btn-ghost">Cancelar</button>
      <button class="btn btn-primary">Guardar</button>
    </div>
  </div>
</div>
```

```css
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.45);
                  display: flex; align-items: center; justify-content: center;
                  padding: 24px;
                  z-index: 100; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal          { background: var(--surface); border-radius: 14px;
                  width: 100%; max-width: 520px; max-height: 100%; overflow: hidden;
                  display: flex; flex-direction: column;
                  box-shadow: var(--shadow-lg);
                  transform: scale(.96) translateY(12px); transition: transform .2s; }
.modal-backdrop.open .modal { transform: scale(1) translateY(0); }
.modal-header   { padding: 20px 24px 16px; border-bottom: 1px solid var(--border);
                  display: flex; align-items: center; justify-content: space-between; }
.modal-title    { font-size: 1rem; font-weight: 700;
                  display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.modal-subtitle { font-size: .8rem; font-weight: 500; color: var(--muted); }
.modal-body     { padding: 20px 24px; display: flex; flex-direction: column; gap: 16px;
                  flex: 1 1 auto; min-height: 0; overflow-y: auto; }
.modal-header, .modal-menubar, .modal-footer { flex: 0 0 auto; }
.modal-footer   { padding: 16px 24px; border-top: 1px solid var(--border);
                  display: flex; gap: 10px; justify-content: flex-end; }

/* Variante ancha para editores monoespaciados (JSON, logs, etc.). */
.modal.modal-wide { max-width: 760px; }
```

**El hueco contra el borde de la pantalla es 24px en los cuatro lados, y lo
pone el `padding` del backdrop — no un `margin` del modal.** Es un solo número
del que salen los cuatro lados, así que no pueden desalinearse. El modal se
acota con `max-height: 100%`, que es 100% del backdrop **con su padding ya
descontado**; por eso el hueco de arriba y abajo da exactamente el mismo valor
que el de los costados.

No usar `max-height: 90vh` (era la regla anterior): ata el hueco vertical a un
porcentaje de la pantalla, que no tiene relación con el padding lateral. Medido
en un viewport de 904px de alto, dejaba **45px arriba y abajo contra 16px a los
costados** — y el desajuste cambia con cada tamaño de pantalla, así que no hay
valor de `vh` que los empareje. Mismo criterio en `panel/` y en `app/`.

**El que scrollea es el cuerpo, nunca el modal entero.** El `.modal` es un
contenedor flex vertical con `overflow: hidden`; el `.modal-body` lleva
`flex: 1 1 auto; min-height: 0; overflow-y: auto` y las tres franjas
—`.modal-header`, `.modal-menubar` y `.modal-footer`— van con `flex: 0 0 auto`.
Así la barra de título y la de acciones quedan **fijas** y sólo se desplaza el
contenido.

- Con `overflow-y: auto` en el `.modal` (la regla anterior) la cabecera se iba
  hacia arriba al scrollear: el usuario perdía de vista el botón de salida justo
  cuando más contenido había, que es cuando más lo necesita.
- **`min-height: 0` en el cuerpo no es opcional.** Un hijo flex no baja de su
  alto de contenido por defecto (`min-height: auto`), así que sin esa línea el
  `overflow-y` nunca se activa: el body empuja, el modal crece hasta el tope y
  la cabecera se va igual. Es el error clásico de este patrón.
- `overflow: hidden` en el `.modal` además **recorta el scroll del cuerpo contra
  el radio de las esquinas**, así la barra no se dibuja sobre el redondeo.
- Los modales de Herramientas que ya traían este layout propio
  (`.db-exp-modal`, `.s3-exp-modal`) quedan igual: sus reglas locales repiten lo
  que ahora es el default y no chocan.

**Variantes:**
- `.modal-wide`: aumenta el `max-width` a 760px. Usar **solo** cuando el contenido sea un editor monoespaciado (JSON, logs, payloads) que necesita ancho real para no envolver — ver §23. Los formularios normales se quedan en el ancho base de 520px.
- `.modal-xwide`: 1100px. Un solo módulo la usa y **de a pares**: el Consultar y el Editar de Comprobantes (§25-quater). La condición es la misma que habilita `.modal-wide` — que el ancho **compre layout**: la ficha pone dos paneles de datos lado a lado y una grilla de renglones de seis columnas, que en 760px colapsan a una columna y a dos líneas por renglón. No usarla para formularios comunes.
- `.modal-subtitle`: chip secundario al lado del título (mismo bloque `.modal-title`) para identificar el recurso editado, por ejemplo `Configuración JSON · Nombre · <code>UID</code>`. No reemplaza al título, lo complementa.
- `.modal-header-primary`: pinta la barra de título en `var(--primary)`, el mismo rojo del chrome (sidebar + topbar). Va **junto a** `.modal-header`, no en su lugar.

```css
.modal-header-primary { background: var(--primary); border-bottom: none; color: #fff;
                        border-radius: 14px 14px 0 0; padding: 10px 24px; }
.modal-header-primary .modal-title       { color: #fff; }
.modal-header-primary .modal-subtitle    { color: rgba(255,255,255,.75); }
.modal-header-primary .btn-icon-sm       { color: #fff; }
.modal-header-primary .btn-icon-sm:hover { background: rgba(255,255,255,.18); color: #fff; }
```

  - **Sobre el rojo los hijos usan `#fff` y opacidades de blanco**, nunca `--text` / `--muted` / `--border`: esos tokens están calibrados contra el gris del modal y sobre el rojo se ensucian. Misma regla que el chrome (§4 y §5).
  - **El `border-bottom` se saca y el radio superior se repite en el header**: con el fondo pintado, la línea divisoria sobra y la esquina redondeada ahora la dibuja el header, no el `.modal`.
  - **Es más baja que el header normal**: `padding: 10px 24px` contra los `20px 24px 16px` del base, para que junto a la barra de acciones de §21-bis se lean como una sola cabecera en vez de dos bloques apilados. Ojo: **no comparte el valor con esa barra** — la de acciones va en `14px` porque lleva botones; acá es texto y con `10px` alcanza.
  - **Va siempre junto a la barra de acciones de §21-bis**, nunca solo: el bloque rojo (título) y la barra gris (acciones) forman una sola cabecera, y el rojo sin la barra debajo queda como un adorno suelto. Lo llevan los modales de un mismo módulo **de a pares** —Consultar y Alta/Edición— para que consultar y editar no se vean como dos pantallas de sistemas distintos. Un `confirmDialog` **no** lo lleva: es una alerta, no una ficha, y **tampoco lo lleva el modal de borrado con desglose de impacto** (§15.1) — es la misma alerta con más letra chica, y su botón rojo se queda abajo a propósito, lejos de la salida. Uso actual: Dominios, Usuarios y Perfiles (los tres con Consultar + Alta / Edición), más el modal de Filtros compartido.

## 15. Confirm dialog (alerta de confirmación)

Para "¿Seguro que querés borrar?" y similares.

```css
.confirm-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,.5);
                    display: flex; align-items: center; justify-content: center;
                    z-index: 150; opacity: 0; pointer-events: none; transition: opacity .15s; }
.confirm-backdrop.open { opacity: 1; pointer-events: all; }
.confirm-box     { background: var(--surface); border-radius: 14px;
                   padding: 28px 28px 20px; max-width: 380px;
                   width: calc(100% - 32px); box-shadow: var(--shadow-lg);
                   transform: scale(.95); transition: transform .15s; }
.confirm-backdrop.open .confirm-box { transform: scale(1); }
.confirm-title   { font-weight: 700; margin-bottom: 8px; }
.confirm-msg     { font-size: .88rem; color: var(--muted); margin-bottom: 20px; }
.confirm-actions { display: flex; gap: 10px; justify-content: flex-end; }
```

**El rótulo y el tono del botón de confirmar son parámetros, no literales.**
`confirmDialog(title, message, onConfirm, opts)` acepta `opts.label` (default
`Eliminar`) y `opts.tono` (default `danger`). Estuvieron hardcodeados mientras el
diálogo se usó sólo para bajas, y la primera acción no destructiva que lo llamó
—generar un enlace de acceso— apareció con un botón rojo que decía **Eliminar**.

- **El rojo se reserva para lo destructivo.** Una acción que no borra nada pasa
  `tono: 'primary'`; que exista la confirmación ya comunica el peso.
- **El rótulo nombra la acción, no la genérica.** `Generar acceso a Panel`, no
  `Aceptar`: el botón tiene que poder leerse solo, sin el título.
- **Y tampoco nombra otra acción.** Lo destructivo que no es una baja conserva el
  rojo pero pasa su propio `label`: *Anular comprobante* dice **Anular**, porque
  anular no borra la fila —el número queda consumido y el comprobante sigue
  listado— y un botón que dijera `Eliminar` estaría prometiendo otra cosa. El
  default sólo sirve cuando la acción efectivamente es eliminar.
- Los call sites de baja no pasan `opts` y siguen en `Eliminar` / rojo.

### 15.1 Confirmación de borrado con desglose de impacto

`confirmDialog` sólo acepta un string, así que **no sirve para bajas que arrastran
dependencias en varias tablas**. Cuando eliminar un registro borra, huerfaniza o
choca contra filas de otras tablas, la confirmación va en un `.modal` normal (§14)
que muestra el detalle real, pedido al backend antes de abrirlo. Hoy lo usa
**Usuarios** (`openUserDeleteModal` + `GET api/users.php?impacto=1&id=N`).

Estructura del cuerpo, en este orden:

1. `.del-lead` — a quién se está por borrar (nombre, `<code>#id</code>`, email).
2. `.del-blocker` — recuadro rojo, sólo si el borrado **no puede** hacerse. Lista
   la causa y qué tiene que hacer el operador. Mientras haya un bloqueo, el botón
   de confirmar **no se renderiza**: queda sólo `Cancelar`.
3. `.del-section` con título `.del-danger` — **"Se eliminarán junto con el
   usuario"**: filas que desaparecen (borrado explícito o `ON DELETE CASCADE`).
4. `.del-section` con título `.del-warn` — **"Se conservarán, sin la referencia
   a este usuario"**: filas que sobreviven con la FK en NULL
   (`ON DELETE SET NULL`). **No dice "sin usuario asociado"**, que es lo que
   decía hasta el 07/09/2026: dos de las filas del grupo son `registrante`
   —los usuarios y los perfiles que esta persona dio de alta— y pertenecen a
   OTRA gente, así que conservan su propio usuario y sólo pierden la autoría.
   Bajo el título viejo, `Perfiles que registró · 12` se leía como si el
   borrado fuera a dejar doce accesos ajenos sin dueño.
5. `.del-warning` — "Esta acción no se puede deshacer", sólo si no hay bloqueo.

Cada ítem es un `.del-item` con la etiqueta a la izquierda y un `.badge` con la
cantidad a la derecha (`badge-danger` para lo que se borra, `badge-warn` para lo
que queda huérfano). **Las secciones con cantidad 0 no se muestran** — el backend
ya filtra los ceros. Si no hay ninguna dependencia se muestra `.del-empty`.

En qué grupo cae cada tabla es una **decisión de producto, no la regla de la FK**:
una FK `ON DELETE SET NULL` puede igual ir en "se eliminarán" si el backend la
borra a mano. Es el caso de `invitaciones` al borrar un usuario — una invitación
sin emisor no se puede responder ni auditar, así que se elimina explícitamente.
Lo que el modal muestra es siempre **lo que va a pasar de verdad**.

```css
.del-lead        { font-size: .88rem; line-height: 1.5; }
.del-section-title { font-size: .75rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .04em; display: flex; align-items: center; gap: 7px; }
.del-section-title.del-danger { color: #f5a8a8; }
.del-section-title.del-warn   { color: #fcd34d; }
.del-item        { display: flex; align-items: center; justify-content: space-between;
                   gap: 12px; padding: 7px 12px; border-radius: 8px; font-size: .85rem;
                   background: color-mix(in srgb, var(--surface) 90%, #000); }
.del-blocker     { display: flex; gap: 10px; font-size: .85rem; line-height: 1.5;
                   padding: 12px 14px; border-radius: var(--radius);
                   background: rgba(230,42,42,.12); border: 1px solid rgba(230,42,42,.35); }
.del-blocker.del-aviso     { background: rgba(245,158,11,.12); border-color: rgba(245,158,11,.35); }
.del-blocker.del-aviso > i { color: var(--warn); }
.del-warning     { display: flex; align-items: center; gap: 8px;
                   font-size: .82rem; color: var(--muted); }
```

**`.del-blocker` es "no se puede / mirá esto", no "borrado".** El nombre viene de
donde nació, pero la caja —ícono a la izquierda, título en negrita y lista de
motivos— es la misma en cualquier acción que se confirme con datos del backend, y
por eso la reusa §15.2. La variante ámbar `.del-aviso` es para lo que **no**
bloquea: mismo recuadro, otro color. Se agregó como modificador y no como clase
propia porque con dos definiciones enteras los dos recuadros se despegan al
primer retoque.

### 15.2 Confirmación con previsualización (acciones que escriben)

`confirmDialog` alcanza cuando lo que la acción va a hacer se puede decir en una
frase. **Cuando no —cuando la acción emite un documento, consume un número de una
serie o escribe en varias tablas— la confirmación muestra lo que va a escribir**,
pedido al backend antes de abrirla. Es §15.1 dado vuelta: allá el backend informa
lo que se va a *destruir*, acá lo que se va a *crear*.

Hoy lo usa **Contratos → Facturar** (`openContratoFacturarModal` +
`GET api/contratos_accion.php?accion=facturar&id=N`).

**El verbo separa previsualizar de ejecutar:** `GET` devuelve lo que la acción va
a hacer, `POST` la hace. Los dos resuelven la cuenta con **la misma función del
backend**, que es lo único que garantiza que el total que anuncia la pantalla sea
el que después queda en la base.

Estructura del cuerpo, en este orden:

1. `.del-lead` — qué se va a emitir y qué queda cambiado después (el período, el
   `<code>#id</code>`, la fecha a la que avanza el contrato).
2. `.del-blocker` — recuadro rojo con los **bloqueos**. Mientras haya uno, el
   botón de confirmar **no se renderiza**: queda sólo `Cancelar`.
3. `.del-blocker.del-aviso` — recuadro ámbar con los **avisos**: lo que conviene
   mirar y no impide seguir (por ejemplo, que el comprobante salga a nombre de un
   cliente distinto del que tiene cargado el contrato). Bloqueo y aviso son dos
   listas distintas del backend, no dos tonos de la misma.
4. Las tarjetas de §25 con la cabecera del documento (a quién, con qué talonario,
   qué número y entre qué fechas).
5. Un `.ficha-bloque` (§25-bis) con la tabla de renglones y sus totales, idéntico
   al de la ficha del comprobante — así lo que se previsualiza y lo que después se
   consulta se leen igual.
6. `.form-nota` al pie con lo que no se puede deshacer (acá: que el número lo toma
   el talonario recién al emitir, así que el de la pantalla es estimado, y que
   anular no lo devuelve a la serie).

**El número estimado se rotula como estimado.** Lo definitivo lo toma el `POST`
con el talonario bloqueado (`SELECT … FOR UPDATE`): si entre la previsualización y
el click se emite otro comprobante, éste se lleva el siguiente. Mostrarlo sin la
aclaración sería prometer un número fiscal que la pantalla no puede garantizar.

## 16. Toasts (notificaciones efímeras)

```css
.toast { position: fixed; bottom: 24px; left: 50%;
         transform: translateX(-50%) translateY(16px);
         background: #0d0d0d; color: var(--text);
         border: 1px solid var(--border);
         padding: 10px 20px; border-radius: 99px;
         font-size: .88rem; opacity: 0; pointer-events: none;
         transition: opacity .2s, transform .2s;
         z-index: 200; white-space: nowrap;
         box-shadow: var(--shadow-lg); }
.toast.show  { opacity: 1; transform: translateX(-50%) translateY(0); }
.toast.error { background: var(--danger); }
```

## 17. Toggle switches

```css
.toggle-switch { display: flex; align-items: center; gap: 12px;
                 cursor: pointer; user-select: none; }
.toggle-switch input { display: none; }
.toggle-track  { width: 44px; height: 24px; border-radius: 12px;
                 background: var(--border); position: relative;
                 transition: background .2s; }
.toggle-switch input:checked + .toggle-track { background: var(--primary); }
.toggle-thumb  { position: absolute; top: 3px; left: 3px;
                 width: 18px; height: 18px; border-radius: 50%;
                 background: #fff; transition: transform .2s;
                 box-shadow: 0 1px 4px rgba(0,0,0,.2); }
.toggle-switch input:checked + .toggle-track .toggle-thumb { transform: translateX(20px); }
.toggle-label  { font-size: .9rem; color: var(--text); }
```

## 18. Spinner y estados de carga

```css
.spin { display: inline-block; width: 28px; height: 28px;
        border: 3px solid var(--border); border-top-color: var(--primary);
        border-radius: 50%; animation: spin .7s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
```

Para celdas / tarjetas en carga: `<tr><td colspan="N" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`.

## 19. Responsive / mobile

```css
.hamburger        { display: none; background: none; border: none; cursor: pointer;
                    font-size: 1.4rem; color: var(--text);
                    padding: 4px 8px; margin-right: 8px; line-height: 1; }
.sidebar-overlay  { display: none; position: fixed; inset: 0;
                    background: rgba(0,0,0,.45); z-index: 199; }
.sidebar-overlay.active { display: block; }

@media (max-width: 768px) {
  .hamburger { display: inline-flex; align-items: center; }
  .sidebar   { position: fixed; top: 0; left: 0; height: 100vh; z-index: 200;
               transform: translateX(-100%); transition: transform .25s; }
  .sidebar.open { transform: translateX(0); }
  .form-row, .form-row-3, .form-row-4 { grid-template-columns: 1fr; }
}
```

## 20. Iconografía

**Un solo sistema de iconos en toda la app: FontAwesome 6 Pro, sin emojis.** Nav, headers de cards, tiles de Herramientas, headers de modal, botones de acción y estados vacíos usan todos `<i class="fa-solid fa-<nombre>"></i>`. El emoji quedó descartado por dos motivos: renderiza distinto en cada sistema operativo (y en varios sale a color, chocando con el rojo del chrome) y **no hereda `color` ni `font-size` del contexto**, así que no se puede teñir de blanco sobre el sidebar ni de `--danger` en un estado de error.

- **Paquete autohospedado, no CDN.** El paquete Pro 6.5.1 vive en `assets/fontawesome/`; `index.php` y `login.php` enlazan `all.min.css` + las cuatro hojas `sharp-*` desde ahí, con cache-bust propio por `filemtime` (independiente de `version.txt`, para que un bump de assets no rebaje los ~350 KB de la fuente). Ver `assets/fontawesome/README.md`. Es la misma copia que usa `panel/`.
- **Al agregar un módulo o herramienta**, elegir el ícono de `assets/fontawesome/icons.json` y verificar que exista en solid (la clave `"c"` de la entrada contiene `s`). Al disponer de la licencia Pro también sirven los íconos sin `"f": 1` — hoy el único en uso es `fa-signal-stream` (Señales).
- **Dónde el ícono NO puede ir**, porque el destino es texto plano y no admite markup: el `placeholder` de un `<input>`, el texto de un `<option>`, el `value` de un `<textarea>` y cualquier asignación a `textContent`. En esos casos el aviso se redacta en palabras (`"— falta: no está en cloud/jobs/"`), no se sustituye por un glifo Unicode.
- **`×` y `+` no son iconografía y se quedan como están**: el `×` de los botones de cierre y de `search-clear`, y el `+` de `.nav-group-arrow` (que rota 45° al abrir). Son glifos tipográficos del layout, no íconos semánticos, y ya están dimensionados por CSS.

## 21. Menú de acciones (dropdown dentro de modal)

Patrón para agrupar acciones secundarias en modales de consulta ("ver detalle") sin saturar el footer con botones sueltos. El trigger se ancla a la izquierda del footer (`margin-right: auto`) y el botón de cierre primario queda a la derecha. El dropdown vive sobre el área gris, así que usa los tokens normales (`--surface`, `--border`, `--text`, `--muted`) — nunca rojo de fondo.

```html
<div class="modal-footer">
  <div class="action-menu action-menu-up" style="margin-right:auto">
    <button class="btn btn-secondary" data-act="menu-toggle">
      <i class="fa-solid fa-ellipsis"></i> Acciones
    </button>
    <div class="action-menu-dropdown" role="menu">
      <button class="action-menu-item" role="menuitem">
        <i class="fa-solid fa-pencil"></i> Editar
      </button>
      <button class="action-menu-item" role="menuitem">
        <i class="fa-regular fa-copy"></i> Copiar
      </button>
      <div class="action-menu-divider"></div>
      <button class="action-menu-item danger" role="menuitem">
        <i class="fa-solid fa-trash"></i> Eliminar
      </button>
    </div>
  </div>
  <button class="btn btn-ghost">Cerrar</button>
</div>
```

```css
.action-menu          { position: relative; display: inline-block; }
.action-menu-dropdown { display: none; position: absolute; left: 0; top: calc(100% + 6px);
                        background: var(--surface); border: 1px solid var(--border);
                        border-radius: 10px; box-shadow: var(--shadow-lg);
                        min-width: 220px; overflow: hidden; z-index: 110; }
.action-menu.open .action-menu-dropdown { display: block; }
.action-menu-up .action-menu-dropdown   { top: auto; bottom: calc(100% + 6px); }

.action-menu-item     { display: flex; align-items: center; gap: 10px; width: 100%;
                        padding: 10px 16px; font-size: .85rem; color: var(--text);
                        background: none; border: none; cursor: pointer;
                        text-align: left; font-family: inherit;
                        transition: background .15s, color .15s; }
.action-menu-item:hover        { background: var(--bg); color: var(--primary); }
.action-menu-item.danger:hover { background: var(--bg); color: var(--danger); }
.action-menu-item i            { width: 16px; text-align: center; color: var(--muted); }
.action-menu-item:hover i      { color: inherit; }
.action-menu-divider           { height: 1px; background: var(--border); margin: 4px 0; }
```

**Reglas:**
- Trigger: `btn btn-secondary` con `<i class="fa-solid fa-ellipsis"></i> Acciones`. No usar `btn-primary` — la acción primaria del modal (si la hubiera) sigue siendo otra.
- Iconos: FontAwesome 6 (no emojis dentro del dropdown — está en zona densa y los emojis varían de tamaño entre sistemas).
- Usar `.action-menu-up` cuando el contenedor esté cerca del borde inferior (típico en footer de modal) para que el dropdown se abra hacia arriba.
- Cerrar al click fuera del menú y al hacer click en cualquier `.action-menu-item`.
- Acciones destructivas con la clase `danger`, separadas del resto por `.action-menu-divider`.
- Un solo dropdown abierto a la vez.
- **Alternativa sin desplegar:** cuando las acciones del modal son pocas y todas caben a la vista, usar la barra de acciones de §21-bis en lugar de este dropdown. Los dos patrones no conviven en el mismo modal.

## 21-bis. Barra de acciones del modal (menubar)

Variante de §21 para modales de consulta: en vez de un único dropdown
"Acciones" escondido en el footer, el modal lleva una **barra horizontal de
botones pegada debajo del header**. El modal entonces **no lleva footer** —
`Cerrar` es el primer botón de la barra, a la izquierda, y el `×` del header
sigue estando.

La barra va **debajo de una barra de título en primario** (`.modal-header-primary`, §14): el bloque rojo y la barra gris se leen como una sola cabecera de ficha.

La barra mezcla **dos tipos de botón**, y esa mezcla es el punto del patrón:

| tipo | para qué | forma |
|---|---|---|
| **directo** | una sola acción, se ejecuta en el click | `btn btn-sm` sin caret |
| **desplegable** | un grupo de acciones de la misma familia | `btn btn-sm btn-primary` + `<i class="fa-caret-down menubar-caret">` |

```html
<div class="modal modal-wide">
  <div class="modal-header modal-header-primary">
    <div class="modal-title">Consultar dominio</div>
    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
  </div>
  <div class="modal-menubar" role="toolbar" aria-label="Acciones del dominio">
    <button class="btn btn-sm btn-ghost" data-act="close">
      <i class="fa-solid fa-xmark"></i> Cerrar
    </button>
    <button class="btn btn-sm btn-primary" data-menu="listar">
      <i class="fa-solid fa-list"></i> Listar
      <i class="fa-solid fa-caret-down menubar-caret"></i>
    </button>
    <button class="btn btn-sm btn-primary" data-menu="acciones">
      <i class="fa-solid fa-bolt"></i> Acciones
      <i class="fa-solid fa-caret-down menubar-caret"></i>
    </button>
  </div>
  <div class="modal-body">…</div>
</div>
```

```css
.modal-menubar     { padding: 14px 24px; border-bottom: 1px solid var(--border);
                     background: color-mix(in srgb, var(--surface) 40%, var(--bg));
                     display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.modal-menubar .btn { white-space: nowrap; }
.modal-menubar .btn-ghost       { background: var(--surface); color: var(--text); }
.modal-menubar .btn-ghost:hover { background: var(--row-hover); color: var(--text); }
.modal-menubar-end  { margin-left: auto; }
.menubar-caret      { font-size: .7em; opacity: .65; margin-left: 2px; }
```

**Los desplegables no usan el dropdown absoluto de §21**: lo abre
`openRowMenu(items, botón)`, el mismo menú flotante del listado. Es
`position: fixed` con clampeo a viewport, así que **no lo recorta el
`overflow-y: auto` del modal** — un `.action-menu-dropdown` absoluto sí queda
cortado cuando el menú es más largo que lo que resta de modal. De paso hereda
gratis el cierre por click afuera / `Esc` / scroll y el estilo de
`.action-menu-item` (divisores y `danger` incluidos).

Helpers en `app.js`: `menubarMenu(key, label, icon)` dibuja el trigger y
`wireMenubarMenu(scope, key, itemsFn)` lo cablea. `itemsFn` arma los items en
el momento del click con el mismo formato que el menú de fila
(`{ act, label, icon, danger?, onSelect }`, `{ divider: true }`).

**Reglas:**
- **Orden fijo:** la salida primero (`btn-ghost`) y después el resto, todos con el mismo `gap`. **Sin línea separadora entre medio**: el contraste entre el ghost y el rojo ya distingue la salida del resto, y una divisoria vertical sólo suma ruido en una barra de dos o tres botones.
- **La salida siempre es directa** — nunca queda a dos clicks. Se llama `Cerrar` en los modales de consulta y `Cancelar` en los de formulario, que es lo que hacía cada uno en el footer que reemplaza.
- **En los modales de Alta / Edición la barra reemplaza al footer igual**, con la acción primaria como botón directo: `Cancelar` (`btn-ghost`) + `Guardar` (`btn-primary`, ícono `fa-floppy-disk`). Sin desplegables — un formulario tiene una sola cosa que hacer.
- **El rótulo del primario es `Guardar` en los dos modos, sin variantes** ("Guardar cambios", "Crear dominio"): el título del modal ya dice si es alta o edición, y el botón sólo tiene que nombrar la acción. Un rótulo que cambia con el modo obliga a leerlo dos veces para confirmar que hace lo mismo de siempre.
- **Los desplegables se agrupan por familia, no por conveniencia**: `Listar` son navegaciones a otros listados acotados a este registro; `Acciones` es lo que opera sobre el registro. Si un menú junta cosas que no comparten familia, van en dos.
- Dentro de cada menú vale el orden de §21: **la destructiva al final, precedida por divisor y con `danger: true`**. Nunca como botón directo de la barra — es la acción más cara de deshacer y no va a un click de distancia.
- **La salida se rellena con `--surface`, el gris del cuerpo del modal** — no se deja transparente. La franja es `--bg`: un `btn-ghost` sin fondo se apoya directo sobre el gris más oscuro y se lee como un hueco al lado de los rojos. El hover sube un escalón (`--row-hover`) en vez de bajar a `--bg`, que es el color de la propia franja y haría desaparecer el botón justo cuando el mouse está encima.
- **Color: `Cerrar` es el único neutro (`btn-ghost`); todo el resto va en `btn-primary`.** Es una excepción declarada a la regla de §6 ("una sola acción primaria por modal") y sólo vale dentro de `.modal-menubar`: acá el rojo no marca jerarquía entre acciones sino que separa **la salida** de **lo que el modal sabe hacer**. Nada de `btn-secondary` en esta barra, y nada de `btn-danger`: la destructiva vive dentro del menú `Acciones`, no en la barra.
- Botones en `btn-sm`: la barra es zona densa y no compite con el contenido.
- **`padding: 14px 24px`, más que los `10px 24px` del header en primario.** La barra lleva botones (`btn-sm`, ~30px de alto) y con 10px quedaban casi tocando el rojo de arriba y la divisoria de abajo; el título es texto y con 10px ya respira. Los dos valores **no se mueven juntos**: el aire vertical de cada franja se calibra contra lo que tiene adentro. La separación *entre* botones sigue siendo el `gap: 8px`, que no cambia.
- Mismos íconos FontAwesome que el menú contextual de la fila y que el sidebar (los ítems de `Listar` usan el ícono del módulo destino), para que la acción se reconozca igual desde cualquier lado.
- La barra **envuelve** (`flex-wrap`) en pantallas angostas; no se scrollea horizontalmente ni se colapsa en un solo dropdown.
- **El fondo es un gris intermedio entre los dos tokens que ya conviven en el modal**: `color-mix(in srgb, var(--surface) 40%, var(--bg))` ≈ `#1e1e1f`. Queda un escalón más claro que `--bg` (`#1a1a1a`) y todavía más oscuro que los dos grises del cuerpo — las tarjetas de consulta (`#202122`) y el fondo del modal / los inputs (`--surface`, `#242526`). Así la franja se despega del header y del body sin sumar otra línea divisoria y sin repetir ningún tono del contenido.
- **La mezcla es entre dos tokens, nunca contra `#000`.** Un `color-mix(--surface X%, #000)` fija un tono que no existe en el sistema y hay que recalcularlo a mano en cada cambio de tema; mezclando `--surface` con `--bg`, el intermedio sigue solo a la paleta. La escala de grises, de claro a oscuro: `--border` → `--row-hover` → `--surface` → **franja** → `--bg`.
- Uso actual: **Dominios** (`openDomainViewModal` + `openDomainModal`), **Usuarios** (`openUserViewModal` + `openUserModal`), **Perfiles** (`openProfileViewModal` + `openProfileModal`), **Contratos** (`openContratoViewModal` + `openContratoModal` + `openContratoDeleteModal` + `openContratoFacturarModal`), **Talonarios** (`openTalonarioViewModal` + `openTalonarioModal` + `openTalonarioDeleteModal`), **Comprobantes** (`openComprobanteViewModal` + `openComprobanteNuevoModal` + `openComprobanteEditModal` + `openRenglonModal` + `openPagoModal` + `openComprobanteDeleteModal`) y **el modal de Filtros de todos los módulos** (`openFiltersModal`, el helper compartido). Los dos modales de cada módulo llevan la misma cabecera — título en primario + barra — para que consultar y editar no se vean como dos pantallas de sistemas distintos. Contratos suma el tercero: **el modal de borrado con desglose (§15.1) lleva la misma cabecera**, y su barra de acciones es la que **omite el botón de confirmar** cuando el impacto trae bloqueos.

### 21-bis.1 "Listar": saltar a otro módulo ya filtrado

Los ítems de `Listar` no navegan pelados: dejan pedido un filtro que el módulo
destino consume al renderizar. En `app.js`, `pedirFiltroDominio(route, id)`
guarda `{ route, id }` y navega; el renderer del destino abre con
`tomarFiltroDominio('<route>')` y, si hay pedido, lo vuelca en `state.dominio`
antes del primer `applyAndRender()`.

Hay un par por entidad: `pedirFiltroUsuario(route, campo, id)` /
`tomarFiltroUsuario(route)` hace lo mismo para el usuario. **Ése lleva además el
`campo`**, porque la misma persona entra por columnas distintas según el
destino: `usuario` en Perfiles, y `adoptador` o `liberador` en Adopciones — son
dos preguntas distintas sobre el mismo usuario y el menú las ofrece por
separado.

- **El pedido se consume siempre y sólo aplica si la ruta coincide.** Si el usuario se desvía a otra pantalla, se descarta en vez de filtrar un listado equivocado más tarde.
- **Sólo entran al menú los módulos que ya tienen filtro propio por esa entidad** (para dominio: Contratos, Dispositivos, Chips, Perfiles, Señales, Registros, Adopciones, Notificaciones, Difusión; para usuario: Perfiles y Adopciones; para contrato: Contratos —desde Comprobantes— y Comprobantes —desde Contratos—; para comprobante: Comprobantes, desde Contratos). Un módulo sin ese filtro no se agrega al menú "para que quede completo".
- **`Consultar contrato` lista un solo destino y está bien así.** Comprobantes es el único módulo con filtro por contrato, y el menú se dibuja igual que si tuviera ocho: un desplegable de un ítem no es un botón directo disfrazado — `Listar` significa lo mismo en los cuatro modales que lo tienen, y degradarlo a botón acá obligaría a reconocer la familia por la forma en vez de por el rótulo. `Pagos` no entra porque no es un módulo sino una pestaña del comprobante.
- **El mismo par sirve para llevar al registro que una acción acaba de crear**, no sólo para los ítems de `Listar`: `Contratos → Facturar` termina con `pedirFiltroComprobante('comprobantes', res.comprobante)`, que es a donde llevaba el back office viejo después de facturar. La regla no cambia — el filtro tiene que existir en el Modal de Filtros del destino (ahí, `Código`) — y por eso el pedido se vuelca en `state.id` **antes** del fetch: Comprobantes filtra en el servidor, así que un recorte posterior mostraría "las 100 últimas de todos" y el comprobante recién emitido podría no estar entre ellas.
- **El destino puede volcar el pedido en el filtro que le corresponda, que no siempre se llama igual.** `Contratos → Ver dominio` usa el mismo `pedirFiltroDominio`, pero en **Dominios** el dominio no es una FK sino la fila misma: `renderDominios()` lo vuelca en `state.codigo`. Lo que no cambia es la regla — el filtro usado **tiene que existir en el Modal de Filtros del destino**, para que se vea por qué la lista viene acotada y se pueda limpiar.
- **Si el endpoint sabe filtrar, el filtro va en el fetch inicial**, no sólo client-side: en tablas grandes (`registros`, `senales`, `adopciones`) recortar después de traer la ventana muestra "las N últimas de todos" filtradas, que es casi nada. Adopciones manda `?dominio=`; Señales y Registros no tienen el parámetro en la API y filtran sobre la ventana, igual que su propio modal de Filtros. `adoptador` / `liberador` tampoco existen en la API de Adopciones: ésos filtran client-side, como ya lo hace el modal de Filtros de ese módulo.

## 22. Lista de datos (vista de consulta)

Para modales de "ver detalle" donde se muestran pares label/valor de solo lectura, sin inputs. Reutiliza la tipografía de las labels de `.form-group` para que la vista de consulta y la de edición se sientan coherentes lado a lado.

```html
<dl class="data-list">
  <div class="data-row">
    <dt class="data-label">ID</dt>
    <dd class="data-value"><code>#42</code></dd>
  </div>
  <div class="data-row">
    <dt class="data-label">Nombre</dt>
    <dd class="data-value">Planta Norte</dd>
  </div>
  <div class="data-row">
    <dt class="data-label">Descripción</dt>
    <dd class="data-value muted">Sin descripción</dd>
  </div>
</dl>
```

```css
.data-list  { display: flex; flex-direction: column; gap: 14px; }
.data-row   { display: flex; flex-direction: column; gap: 4px; }
.data-label { font-size: .75rem; font-weight: 600;
              text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
.data-value { font-size: .9rem; color: var(--text);
              word-break: break-word; white-space: pre-wrap; }
.data-value.muted { color: var(--muted); font-style: italic; }
.data-value code  { font-family: monospace; font-size: .85rem;
                    background: var(--bg); border: 1px solid var(--border);
                    border-radius: 6px; padding: 2px 8px; }
```

**Reglas:**
- Va dentro de `.modal-body` (no como reemplazo de `.form-group`, que sigue siendo para inputs).
- Valores vacíos / nulos usan `.data-value.muted` con texto tipo "Sin descripción", "—" o similar, en cursiva muteada.
- Identificadores (IDs, UIDs, hashes cortos) van envueltos en `<code>` para diferenciarse del texto libre.
- Si la lista crece más de 8 pares, dividirla en secciones con subtítulos pequeños (`<h4>` `.form-group label`-equivalentes) en lugar de hacer scroll largo.

## 23. ABM: header del módulo

Todo listado ABM arranca con un header obligatorio (ver `ABM.md` §1.1): **título** de la entidad en plural + **subtítulo** descriptivo en una sola frase. Va antes de KPIs (si los hay) y de la toolbar.

```html
<div class="module-header">
  <h1 class="module-title">Dispositivos</h1>
  <p class="module-subtitle">Inventario de dispositivos conectados a la plataforma, su dominio asignado y su última actividad.</p>
</div>
```

```css
.module-header   { margin-bottom: 18px; }
.module-title    { font-size: 1.35rem; font-weight: 700; color: var(--text);
                   margin: 0 0 4px; line-height: 1.2; }
.module-subtitle { font-size: .88rem; color: var(--muted); margin: 0; line-height: 1.4; }
```

**Reglas:**
- El título usa la entidad en plural (`Dispositivos`, `Chips`, `Transceptores`, `Dominios`, `Usuarios`, `Perfiles`).
- El subtítulo es una sola frase explicando qué muestra el módulo. No reemplaza al título — lo complementa.
- El bloque se renderiza **antes** de los KPI cards (§12) y la toolbar (§9). El topbar (§5) sigue mostrando el nombre de la pantalla; el header del módulo aporta contexto adicional sobre el área de contenido.

## 23-bis. ABM: Modal de Filtros

Form completo de filtros del listado (ver `ABM.md` §3). Se abre desde el botón `Filtros` de la toolbar (§9) y centraliza todos los filtros del módulo: la búsqueda rápida del toolbar es un atajo, este modal es la fuente completa.

```html
<div class="modal-backdrop open">
  <div class="modal">
    <div class="modal-header modal-header-primary">
      <div class="modal-title">Filtros</div>
      <button class="btn-icon-sm" data-act="close">×</button>
    </div>
    <div class="modal-menubar" role="toolbar" aria-label="Acciones de los filtros">
      <button class="btn btn-sm btn-ghost"   data-act="close"><i class="fa-solid fa-xmark"></i> Cancelar</button>
      <button class="btn btn-sm btn-primary" data-act="clear"><i class="fa-solid fa-eraser"></i> Limpiar</button>
      <button class="btn btn-sm btn-primary" data-act="apply"><i class="fa-solid fa-check"></i> Aplicar</button>
    </div>
    <div class="modal-body">
      <div class="filters-grid">
        <div class="form-group">
          <label for="dev-fm-codigo">Código</label>
          <input type="number" id="dev-fm-codigo" min="1" placeholder="ID exacto">
        </div>
        <div class="form-group">
          <label for="dev-fm-texto">Buscar (UID / nombre / tipo / ubicación)</label>
          <input type="search" id="dev-fm-texto" placeholder="Texto libre">
        </div>
        <div class="form-group">
          <label for="dev-fm-dominio">Dominio</label>
          <select id="dev-fm-dominio">…</select>
        </div>
        <div class="form-group">
          <label for="dev-fm-estado">Estado</label>
          <select id="dev-fm-estado">…</select>
        </div>
        <div class="form-group">
          <label for="dev-fm-limit">Límite</label>
          <input type="number" id="dev-fm-limit" min="1" max="1000" value="100">
        </div>
        <div class="form-group"></div>
        <div class="form-group">
          <label for="dev-fm-orden">Ordenar por</label>
          <select id="dev-fm-orden">…</select>
        </div>
        <div class="form-group">
          <label for="dev-fm-dir">Dirección</label>
          <select id="dev-fm-dir">
            <option value="desc">Descendente</option>
            <option value="asc">Ascendente</option>
          </select>
        </div>
      </div>
    </div>
  </div>
</div>
```

```css
.filters-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px 16px; }
@media (max-width: 640px) { .filters-grid { grid-template-columns: 1fr; } }
```

**Reglas (ver `ABM.md` §3 para la versión normativa):**
- **Primer campo `Código`** (`type="number"`, label `Código`). Es el ID exacto de la entidad.
- En el medio, los **filtros propios del recurso** (selects de estado / rol / dominio, texto libre, etc.). Las ids llevan el prefijo del módulo + `-fm-` (filter modal) para evitar choques con los inputs de los modales de edición.
- **Antepenúltimo bloque `Límite`** (`type="number"`, default `100`). Modifica cuántas filas se muestran en el listado.
- **Últimos campos `Ordenar por` + `Dirección`** (`desc` por default). La grilla los pone uno al lado del otro. El select de `Ordenar por` debe incluir al menos la opción `Código` (`value="id"`).
- **Barra de acciones** (§21-bis) en orden **Cancelar → Limpiar → Aplicar**, sin footer: la salida primero en `btn-ghost` y las otras dos en `btn-primary`, las tres directas — este modal no tiene desplegables. `Limpiar` solo resetea los campos del modal a sus defaults (no aplica ni cierra). `Cancelar` cierra sin aplicar. `Aplicar` lee los valores, actualiza el estado del listado y cierra el modal.
- **El modal de Filtros lo dibuja un único helper compartido** (`openFiltersModal()` en `app.js`): cada módulo aporta sólo el `bodyHtml` de sus campos. Por eso los doce listados tienen exactamente la misma cabecera y la misma barra, y migrarlo al formato nuevo fue un solo cambio — no hay una copia por módulo que se pueda quedar atrás.
- **Ancho: 520px por defecto, `wide: true` para los módulos con muchos filtros** (760px, la clase `.modal-wide` de §14). En el modal ancho la grilla pasa sola de dos a tres columnas (§26), así el ancho extra compra layout y no aire — sin eso el modal queda igual de largo, sólo con los campos estirados. Hoy lo usa **Perfiles**, que además de los seis filtros comunes suma uno por permiso. **Es opt-in por módulo y sólo toca el `max-width`**: la cabecera, la barra de acciones y el orden de los campos no cambian, que es donde vive la regla de que todos los Filtros se lean iguales. Un módulo no se pasa a ancho "para emparejar" — se pasa cuando sus campos no entran.
- Filtrado **client-side por defecto** (un único array en memoria por módulo): el cambio de filtros re-renderiza la tabla sin re-fetch.
- La búsqueda rápida del toolbar (§9) escribe en la misma propiedad `state.texto` que el campo `Buscar` del modal — abrir el modal pre-rellena el input con lo que haya tipeado el usuario.
- **Caso mixto (señales, registros):** los filtros `Dispositivo` y `Límite` viajan al backend en la query string (`?dispositivo=&limit=`); cambiar cualquiera de los dos dispara un re-fetch. El resto de los filtros (texto, dominio, sentido, estado, usuario, código) se aplican client-side sobre el array ya descargado.

## 24. ABM: columnas de acción del listado

Cada ícono va en **su propia columna** al final de la tabla, en el orden fijo **Consultar → Editar → Eliminar**. Las columnas son angostas (ancho automático) y centradas.

```html
<table>
  <thead>
    <tr>
      <th>Código</th>
      <th>Nombre</th>
      <th>…</th>
      <th class="action-col"></th>
      <th class="action-col"></th>
      <th class="action-col"></th>
    </tr>
  </thead>
  <tbody>
    <tr>
      <td><span class="td-id">#42</span></td>
      <td class="td-nombre">Planta Norte</td>
      <td>…</td>
      <td class="action-col">
        <button class="btn-icon-sm" data-act="view"   title="Consultar"><i class="fa-solid fa-eye"></i></button>
      </td>
      <td class="action-col">
        <button class="btn-icon-sm" data-act="edit"   title="Editar"><i class="fa-solid fa-pencil"></i></button>
      </td>
      <td class="action-col">
        <button class="btn-icon-sm" data-act="delete" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
      </td>
    </tr>
  </tbody>
</table>
```

```css
th.action-col, td.action-col { width: 1%; white-space: nowrap; text-align: center;
                               padding-left: 4px; padding-right: 4px; }
```

**Reglas:**
- **Primera columna del listado siempre `Código`** (título `Código`, no `ID`). Renderiza el ID prefijado con `#` en `.td-id`.
- **Tres columnas de acción al final, una por icono**, siempre en este orden: Consultar (`fa-eye`), Editar (`fa-pencil`), Eliminar (`fa-trash`). Los `<th>` correspondientes van vacíos. No reemplazar por un dropdown de "Acciones".
- Cada `<td>` de acción tiene la clase `action-col` para forzar ancho mínimo y centrado.
- Los módulos **read-only** (eventos, logs, señales) usan solo la columna **Consultar**; no incluyen Editar / Eliminar.
- Tooltip exacto (`title="Consultar" / "Editar" / "Eliminar"`) para que el ícono sea legible sin contexto.
- **Click izquierdo en la fila → acción por defecto (Consultar).** Opt-in por módulo: `<tr class="row-clickable">` (§10) + listener de `click` en la fila que abre el modal de Consultar. El botón hamburguesa hace `stopPropagation()` para no dispararlo. Activo en todos los listados ABM: **Dominios, Dispositivos, Chips, Transceptores, Señales, Registros, Adopciones, Usuarios, Perfiles, Controladores, Roles y Permisos** (más la solapa Perfiles del modal de Consultar de Usuarios). El click derecho sigue abriendo el menú contextual completo.

## 25. ABM: tarjetas de consulta (read-only)

El modal **Consultar** muestra TODOS los campos del registro como tarjetas read-only — no como `dl.data-list` (ese patrón es para listas internas, ver §22). Cada campo es un `<div class="view-card">` con esquinas redondeadas y **fondo exactamente 10% más oscuro** que `--surface`. Este valor está fijado por `ABM.md` y no se varía por módulo.

```html
<div class="modal modal-wide">
  <div class="modal-header">
    <div class="modal-title">Consultar dispositivo</div>
    <button class="btn-icon-sm">×</button>
  </div>
  <div class="modal-body">
    <div class="view-grid">
      <div class="view-card view-card-half">
        <div class="view-card-label">Código</div>
        <div class="view-card-value"><code>#42</code></div>
      </div>
      <div class="view-card view-card-half">
        <div class="view-card-label">Estado</div>
        <div class="view-card-value"><span class="badge badge-success">Online</span></div>
      </div>
      <div class="view-card view-card-full">
        <div class="view-card-label">Configuración (JSON)</div>
        <div class="view-card-value"><pre>{ "channels": [ … ] }</pre></div>
      </div>
    </div>
  </div>
  <div class="modal-footer">
    <button class="btn btn-ghost">Cerrar</button>
  </div>
</div>
```

```css
.view-grid    { display: flex; flex-wrap: wrap; gap: 12px; }
.view-card    { background: color-mix(in srgb, var(--surface) 90%, #000);
                border-radius: var(--radius); padding: 12px 14px;
                display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.view-card-half { flex: 1 1 calc(50% - 6px); }
.view-card-full { flex: 1 1 100%; }
.view-card-row  { flex-direction: row; align-items: center;
                  justify-content: space-between; gap: 12px; }
.view-card-main { display: flex; flex-direction: column; gap: 4px; min-width: 0; }
.view-card-row > .badge { flex: none; }
.view-card-label { font-size: .75rem; font-weight: 600;
                   text-transform: uppercase; letter-spacing: .04em; color: var(--muted); }
.view-card-value { font-size: .9rem; color: var(--text);
                   word-break: break-word; white-space: pre-wrap; }
.view-card-value pre { margin: 0; font-family: monospace; font-size: .82rem;
                       background: var(--bg); border: 1px solid var(--border);
                       border-radius: 6px; padding: 10px 12px;
                       overflow-x: auto; white-space: pre-wrap; }
@media (max-width: 640px) { .view-card-half { flex: 1 1 100%; } }
```

**Reglas (ver `ABM.md`):**
- Va dentro de un **`.modal.modal-wide`** (760 px) — las tarjetas necesitan respirar a lo ancho.
- **Fondo de la tarjeta fijo**: `color-mix(in srgb, var(--surface) 90%, #000)`. No se sustituye por `--bg`, ni por otra mezcla — la regla del 10% más oscuro está en `ABM.md`.
- **50% de ancho** (`view-card-half`) para valores cortos: códigos, números, fechas, estados, booleanos, IDs.
- **100% de ancho** (`view-card-full`) para valores largos: descripciones, observaciones, direcciones completas, JSON, payloads MQTT.
- **Variante en fila** (`view-card-row`, siempre sobre una `view-card-full`) para las **listas de "esto lo tiene / esto no"**: el nombre a la izquierda dentro de un `.view-card-main` —con su glosa debajo, en `muted`— y la **píldora de estado a la derecha**, contra el borde de la tarjeta. La usan las pestañas `Permisos` y `Paneles` de Consultar perfil. El motivo es la lectura en columna: la pregunta que se le hace a esas pestañas no es qué dice cada tarjeta sino **cuáles están prendidas**, y con los badges alineados en la misma `x` se contesta de un vistazo; apilados arrancan donde termine el rótulo de arriba y hay que leer tarjeta por tarjeta. **No es la forma por defecto** — un campo cualquiera va apilado, como el resto de §25.
  - `min-width: 0` en `.view-card-main` y `flex: none` en el badge: sin los dos, una glosa larga empuja la píldora fuera de la tarjeta o la parte en dos renglones.
  - Los rótulos del estado son **`Habilitado` / `Deshabilitado`** (`badge-success` / `badge-danger`), los mismos que la tarjeta `Estado` de `General`: la bandera `habilitado` es 1 o 0 y nada más, así que los fija el helper `viewCardEstado()` y no cada llamador.
- El modal de Consultar muestra **todos los campos** de la entidad (no solo los del listado). Es la única vista donde el usuario ve la fila completa sin pasar al modo edición.
- Valores nulos / vacíos van como `<span class="muted">—</span>` o `<span class="muted">Sin descripción</span>` dentro del `view-card-value`.
- JSON / payloads dentro de `<pre>`; no usar `.json-editor` (es para edición, no para read-only).

**Pestañas dentro del modal de Consultar (opcional).** Cuando la entidad tiene relaciones importantes con otras tablas (ej.: usuarios ↔ perfiles), el modal de Consultar puede dividirse en pestañas usando `.modal-tabs` / `.modal-tab` / `.modal-tabpanel`. La primera pestaña se llama siempre **`General`** y contiene el `view-grid` con todos los campos de la entidad; las pestañas siguientes muestran cada relación en una tabla `.table-card` de solo lectura (sin columna `Acciones`, sin menú contextual — las acciones se hacen desde el módulo de la relación, no desde acá). El primer ejemplo es Consultar usuario → `General` + `Perfiles` (lista de dominios y rol por dominio). Cada pestaña de relación lazy-loads su contenido en el primer click al tab correspondiente para no penalizar la apertura del modal.

**Las pestañas también valen para el modal de Alta/Edición**, no sólo para Consultar. El formulario de Perfiles las usa: `General` con los campos de la entidad, `Permisos` con las tres banderas de la fila y `Paneles` con el selector de `perfiles_paneles`. Dos reglas propias de ese caso:

- **Las tres pestañas del módulo se llaman igual** (`General` / `Permisos` / `Paneles` en Consultar, Nuevo y Editar): son la misma ficha en tres modos, y si los rótulos no coinciden se leen como pantallas distintas.
- **Si la validación falla en un campo que está en otra pestaña, hay que traer al operador a esa pestaña ANTES de enfocar.** Enfocar un input dentro de un `.modal-tabpanel[hidden]` no hace nada: el error se marca donde no se ve y el modal parece no responder al Guardar. Por eso `wireModalTabs()` devuelve su función `mostrar(nombre)` — no alcanza con cablear los clicks.

**Las pestañas también sirven para partir una ficha larga en dos mitades temáticas**, aunque no haya ninguna relación con otra tabla. Es lo que hace **Contratos** (`General` / `Facturación`), en Consultar y en Alta/Edición:

- **`Facturación` arranca en el `plan` y se lleva todo lo que sigue** — abono, promo y su vigencia, las fechas de vida del contrato (`registro`, `firma`, `alta`, `baja`) y las del ciclo (`facturado`, `facturar`, `remitir`). **`General` es de quién es el contrato**: dominio, cliente, identificador, habilitado y tipo.
- **Cinco campos se quedan en `General` contra ese criterio**, y no es una excepción caprichosa: `Tolerancia`, `Facturable`, `Remitido`, `Comprobantes` y `Pagos` no son la condición comercial pactada sino **el seguimiento del contrato** — en qué estado está hoy y cuánto se le emitió. Son lo que se mira al abrir la ficha, no al revisar el plan.
- **El corte es el mismo en los dos modales.** Es §14 aplicado a las pestañas: consultar y editar el mismo registro no pueden verse como dos pantallas de sistemas distintos, así que `Tolerancia` y `Remitido` están en `General` también en el formulario, bajo la sección `Seguimiento`.
- **La cuenta de tarjetas de §25 se hace POR PESTAÑA, no sobre el total.** Cada `view-grid` es su propio flex: los `half` tienen que ser pares **dentro de cada panel** y cada `full` caer después de un renglón cerrado de ese panel. Contratos queda 10 `half` + 1 `full` en General y 12 `half` en Facturación.
- **Una nota que habla de los campos de las dos pestañas va afuera de los paneles**, al pie del `.modal-body`. En Contratos es la de las fechas centinela (`1500-01-01` / `2500-01-01`): hay fechas en las dos solapas y repetirla en cada panel sería ruido.

**Un campo que no se edita desde esta pantalla se dibuja como `<input readonly>`, no como un `<select>` deshabilitado ni como un select que no se puede cambiar.** En **Editar contrato** son `Dominio` y `Cliente`: muestran el nombre en un input de sólo lectura, mientras que el **Alta** los sigue dibujando como selects. Cuatro reglas:

- **El motivo es de datos, no de interfaz.** De quién es el contrato no se cambia desde el ABM: la facturación ya emitida cuelga del contrato por FK, así que moverlo de dominio le cambiaría el titular a comprobantes que ya se entregaron. En el alta, en cambio, hay que elegirlos — y elegir el dominio precarga el cliente.
- **Un `<select disabled>` no sirve**: se lee como un desplegable roto y además el navegador no lo manda en el submit. El input con el nombre dice lo mismo sin prometer una elección que no existe.
- **El id sigue viajando en el payload**, tomado de la fila que se está editando. El endpoint reescribe las dos columnas en el `UPDATE`, así que omitirlas las borraría.
- **El foco inicial se corre al primer campo editable** (en Contratos, `Identificador`): abrir un formulario con el cursor en un campo que no se puede tipear se lee como que el modal no tomó el foco.

El cableado sale de **`wireModalTabs(scope, onShow)`**, el helper compartido: recibe el `.modal-backdrop`, alterna `active` y `hidden` por `data-tab` / `data-panel`, y devuelve `mostrar(nombre)`. El `onShow` opcional corre en cada cambio y es lo que usa Consultar usuario para cargar su solapa recién al abrirla.

**Un campo de la ficha que nombra otra entidad se dibuja como pastilla clickeable** (`.badge.badge-link`, §11) y abre la ficha de esa entidad apilada encima. Hoy lo usan `Usuario` y `Dominio` en Consultar perfil y `Artículo que factura el abono` en Consultar plan (§42). Cuatro reglas:

- **Se dibuja con `<button>`, no con `<a>`**: no navega a ninguna URL, monta un modal. El `<a href="#">` obligaría a cancelar el evento y ensuciaría el historial.
- **Es azul, y en la ficha el azul es sólo eso** (§11): las píldoras informativas del mismo modal —contadores, tipos— van en `badge-success`, o no hay forma de saber cuál se puede clickear sin pasar el mouse por todas.
- **Sin id no hay pastilla.** Un perfil puede apuntar a un usuario borrado o traer el centinela `0`; ahí el campo se degrada a texto plano —o a un guión si tampoco hay nombre— en vez de dejar un botón que no lleva a ningún lado.
- **El objeto se resuelve al clickear, no al dibujar la ficha, y por eso el handler es async.** En Perfiles sale del catálogo ya cargado y si no está se pide (`catalogosPerfiles()`): quien llega desde Consultar usuario → solapa Perfiles no pasó por el módulo Perfiles. En Planes se pide siempre el listado de Artículos, porque la ficha del artículo necesita la fila completa y el catálogo del desplegable sólo trae id y nombre. **Si la fila ya no está se avisa con un `toast` y no se abre nada** — el id pudo borrarse entre que se abrió la ficha y se hizo el click.

Las filas de una pestaña de relación son **clickeables** (`<tr class="row-clickable" data-id="…">`, §10): el click izquierdo abre el **modal de Consultar de la entidad relacionada**, apilado encima del modal actual (`.modal-backdrop` comparte `z-index:100`, así que el último montado queda arriba). Al cerrarlo, el modal de origen sigue abierto y con la pestaña activa. Se reutiliza el mismo `openXxxViewModal()` que usa el módulo de la relación — no se duplica el markup de tarjetas. Ejemplo vigente: Consultar usuario → pestaña `Perfiles` → click en una fila → **Consultar perfil**.

## 25-bis. Bloques de detalle dentro de una ficha

Para las entidades que tienen **hijos que se miran junto con la cabecera**: los renglones y los pagos de un comprobante. Una tarjeta de §25 muestra **un campo**; esto es una **tabla completa embebida** en el modal de Consultar, con su encabezado y su acción propia.

```html
<div class="ficha-bloque">
  <div class="ficha-bloque-head">
    <span><i class="fa-solid fa-list-ul"></i> Renglones</span>
    <button class="btn btn-sm btn-primary" data-ren="new">
      <i class="fa-solid fa-plus"></i> Agregar renglón
    </button>
  </div>
  <table class="ficha-tabla">
    <thead><tr><th class="td-num">#</th><th>Detalle</th><th class="td-num">Monto</th></tr></thead>
    <tbody>…</tbody>
    <tfoot><tr><td colspan="2" class="td-num"><strong>Total</strong></td>
               <td class="td-num"><strong>$ 27.500,00</strong></td></tr></tfoot>
  </table>
</div>
```

- **Comparte el fondo de `.view-card`** (`--surface` un 10 % más oscuro): se tiene que leer como parte del mismo bloque read-only, no como una tabla flotando sobre el modal.
- **Scroll horizontal propio** (`overflow-x` en `.ficha-bloque`). El modal nunca scrollea de costado — regla §19.
- **`.td-num` para toda columna de números**: alineada a la derecha y `font-variant-numeric: tabular-nums`. Sin eso, una columna de importes no se puede comparar de un vistazo porque los dígitos no caen en la misma posición.
- **La acción del encabezado aparece sólo cuando corresponde.** En Comprobantes los botones de renglón existen únicamente en `Preparación`; en el resto de los estados el hueco lo ocupa una glosa en `muted` que dice por qué (`Sólo se editan en Preparación`). Un botón deshabilitado sin explicación se lee como un bug.
- **El `tfoot` lleva los totales derivados**, con una `.form-nota` debajo aclarando que los calcula el servidor. Es la contracara de que no sean editables: si no se dice, un campo que no se puede tocar parece roto.

## 25-ter. Pie de listado con totales de la consulta

`.table-foot` va **debajo de la tabla del listado**, no dentro de ella, y dice **cuántas filas matchean el filtro y cuánto suman** — no cuántas se están viendo.

```html
<div class="table-foot">
  <span>2326 comprobante(s) · Total $ 35.611.023,88</span>
  <span class="muted">Se muestran los 100 más recientes — subí el <strong>Límite</strong> en Filtros para ver más</span>
</div>
```

- **Sólo lo usan los módulos que filtran en el servidor** (hoy Comprobantes). En los que se traen la tabla entera, la ventana *es* la consulta y el pie no agregaría nada.
- **El aviso de recorte es obligatorio cuando `filas > traidos`.** Un total de 35 millones arriba de 100 filas, sin decir que hay 2.226 más, se lee como que la tabla miente.
- **Con la búsqueda rápida activa el pie se recalcula sobre lo que se ve.** El buscador de la toolbar filtra la ventana en el navegador; dejar ahí los totales del servidor pondría un número que no corresponde a ninguna de las dos cosas.

## 25-quater. Ficha con formato de comprobante

**Es la excepción a §25, y es una sola: Comprobantes.** El resto de los Consultar del panel son una grilla de tarjetas —un campo por tarjeta— y eso funciona mientras los campos se miren de a uno. Un comprobante pasa los veinte campos y no se mira así: lo primero que se busca es **qué documento es, cuánto dice y si está autorizado**, y con veinte tarjetas iguales esas tres cosas quedan al mismo nivel que la cotización del dólar.

Así que la ficha tiene **encabezado + pestañas**, y las tarjetas de §25 siguen valiendo puertas adentro de las pestañas que muestran campos sueltos.

```html
<div class="modal modal-xwide">
  <div class="modal-header modal-header-primary">
    <div class="modal-title"><i class="fa-solid fa-file-invoice"></i>
      Comprobante <span class="modal-subtitle">#7706</span></div>
  </div>
  <div class="modal-menubar">…Cerrar · Imprimir ▾ · Acciones ▾…</div>
  <div class="modal-body">

    <div class="ficha-hero">
      <div>
        <div class="ficha-hero-talonario">Alfatec - Prefactura - X - 001</div>
        <div class="ficha-hero-doc">
          <span class="ficha-hero-tipo">Prefactura X</span>
          <span class="ficha-hero-nro">001-003411</span>
        </div>
        <div class="ficha-hero-meta">#7706 · UUID <code>BWK1FBBXPL5XY6JQ</code></div>
        <div class="ficha-hero-total-block">
          <div class="ficha-hero-total-label">Total</div>
          <div class="ficha-hero-total">$ 22.425,00</div>
        </div>
      </div>
      <div class="ficha-hero-side">
        <div><span class="badge badge-success">Cancelado</span></div>
        <div class="ficha-hero-fechas">
          <div><span class="muted">Emisión:</span> 31/7/2026</div>
          <div><span class="muted">Vencimiento:</span> 7/8/2026</div>
        </div>
      </div>
    </div>

    <div class="modal-tabs" role="tablist">…General · Cuerpo · Detalles…</div>

    <div class="modal-tabpanel" data-panel="general">
      <div class="ficha-panel-grid">
        <div class="ficha-panel-col">
          <div class="ficha-panel-label">Datos fiscales</div>
          <div class="ficha-panel">
            <div class="ficha-linea"><span class="ficha-linea-rot">Talonario:</span> …</div>
            <div class="ficha-linea"><span class="ficha-linea-rot">CUIT:</span>
              <span class="muted">Sin dato</span></div>
          </div>
        </div>
        <div class="ficha-panel-col">…Cliente…</div>
      </div>
    </div>
  </div>
</div>
```

- **El encabezado dice la identidad y el importe, nada más.** Talonario arriba en versalitas, tipo + número en monoespaciado grande, `#id · UUID` en chico, y el total en `--primary`. A la derecha el badge de estado y las dos fechas. Lo que está acá **no se repite** en las tarjetas de abajo: repetir el total en una tarjeta lo devolvería al montón que el encabezado vino a sacar.
- **El total va en monoespaciado y en el color primario.** Es el único número de la pantalla que se lee sin buscarlo, y eso es deliberado.
- **`.ficha-panel` es la contracara de §25, no su reemplazo.** Una tarjeta por campo sirve cuando los campos se miran de a uno; los datos fiscales y los del cliente se leen **como bloque** ("¿a quién se le facturó?", "¿con qué CAE?"), y ahí ocho tarjetas sueltas son ruido. El rótulo del bloque va **afuera**, arriba. Fuera de esos dos bloques —o sea en la pestaña Detalles, que son campos sueltos— se vuelve a §25.
- **El vacío se dice, no se deja en blanco**: `Sin dato` en cursiva `--muted`, el mismo criterio de §25. Una etiqueta con nada al lado no distingue "no tiene" de "no se cargó".
- **Las tres pestañas tienen un criterio, no son cajones**: *General* es a quién y con qué respaldo fiscal; *Cuerpo* es qué se cobra (renglones + totales + observaciones, que son el texto que se **imprime**); *Detalles* es el resto del expediente (cliente, contrato, medio, cotización, comentarios internos). Las tres existen siempre — una pestaña que aparece y desaparece según los datos hace dudar de dónde estaba lo que se vio recién.
- **La ficha NO tiene pestaña *Pagos*** (30/09/2026). La tuvo, y se sacó: es el **documento** lo que la ficha muestra —a quién se le emitió, qué dice y qué se imprime—, mientras que lo cobrado contra él es otra entidad, con su propio módulo. Un pago **no cambia nada de lo que la ficha muestra**: no mueve el total ni ninguno de los renglones, sólo cancela el comprobante, y eso ya lo dice el badge de estado del encabezado. Registrar uno sigue estando donde estaba —*Acciones → Registrar pago*, sólo con el comprobante Pendiente—, así que no se perdió ningún camino. **Y con la pestaña se fue también `pagos` de la respuesta de `comprobantes?detalle=1`**: era un `SELECT` por cada apertura de ficha que ya no lee nadie.
- **La pestaña abierta sobrevive al repintado.** La ficha se redibuja entera tras cada cambio de renglón y los botones de renglón viven en *Cuerpo*: sin recordarla, agregar un renglón devolvería al operador a *General* cada vez.
- **Los totales van a la derecha, en `.ficha-totales`**, no en el `tfoot` de la tabla. Es donde se busca un total en cualquier factura, y deja la grilla de renglones leyéndose como lo que es: el detalle. Las Observaciones van **al lado** y no en Detalles, porque son el texto que se imprime junto a los renglones.
- **La respuesta del CAE se esconde detrás de un "Ver".** Es el XML del rechazo de AFIP cuando falla: adentro de una línea de panel taparía todo lo demás. El overlay que la muestra se monta **fuera** del backdrop de la ficha, para no destruirla al abrirlo.
- **El Editar del módulo repite el chrome**: mismo `.modal-xwide`, mismo título con `<i class="fa-solid fa-file-invoice">` + `.modal-subtitle` con el `#id`. Es la regla de §14 —consultar y editar el mismo registro no se pueden ver como dos pantallas de sistemas distintos— aplicada también al ancho. Sus pestañas y su grilla de líneas son §25-quinquies.
- **La pestaña *Cuerpo* de la ficha sigue editando renglones de a uno** (`openRenglonModal()`), y eso **no** es un duplicado del editor: ahí se ven y se escriben el `orden` y el `artículo`, que la grilla del editor conserva pero no muestra. Es el camino para corregir un renglón suelto sin abrir el formulario entero.

## 25-quinquies. Editor de comprobante: tres pestañas y la grilla de líneas

**El editor del módulo Comprobantes** (`openComprobanteEditModal()`). Es un `.modal-xwide` con el chrome de §25-quater y **tres pestañas con un solo Guardar**:

| pestaña | qué tiene | por qué ahí |
|---|---|---|
| *General* | la sección `Comprobante`: emisión / vencimiento / cotización, y abajo el cliente | son los datos del documento: se completan mirándose entre sí |
| *Líneas* | la grilla editable de renglones, Observaciones y la caja de totales | Observaciones es el texto que se **imprime debajo de los renglones**, así que se escribe mirándolos — el mismo criterio con el que la ficha las pone al lado de los totales (§25-quater) |
| *Detalles* | Comentarios | son internos, no salen impresos: mezclarlos con lo que se imprime es el error que la separación viene a evitar |

- **La validación salta a la pestaña del campo que falló** antes de enfocarlo: marcar un error en un campo invisible se lee como un Guardar que no responde. En la grilla además el error dice **qué línea** (`Línea 3: el detalle es obligatorio`), porque un borde rojo entre ocho filas obliga a buscarlo.
- **`Cotización USD/ARS` llega ya cargada y se puede corregir.** La sella el `INSERT` que creó el comprobante, con la cotización del día (§33-quater.1); el campo queda editable para el caso de una emisión que tiene que decir otra cosa, y lo que se tipee ahí es lo que se guarda. El editor **no la vuelve a sellar** al guardar — si lo hiciera, corregir un domicilio le pisaría la cotización al comprobante.
- **Los campos del cliente van de a dos por renglón**, `Razón social` con `Domicilio` y `Correo` con `Celular` y `CUIT` con `Condición`. Sólo `Cliente` queda a todo el ancho (§8.3), porque arriba de los demás es el que los rellena. Con los seis apilados la sección medía dos pantallas y obligaba a scrollear para ver si faltaba algo.
- **La ayuda de cada campo es el globito de §8.2, no una `.form-nota` debajo.** Es la regla general aplicada acá: con los campos de a dos, una nota bajo uno empuja sólo su mitad y deja los dos controles desalineados — que es exactamente lo que pasaba con `Sin razón social…` contra `Domicilio`. Llevan `?`: `Cliente`, `Razón social`, `Correo` y `Comentarios`.
- **Los renglones se guardan CON la cabecera, no de a uno.** La grilla se sincroniza entera con un `PATCH` a `comprobantes_renglones`, que resuelve altas, bajas y cambios **en una transacción** y totaliza una sola vez al final. Con N llamadas sueltas, la tercera de ocho que falla deja el comprobante a medio guardar y los totales ya movidos por las dos que pasaron.
- **Son dos requests, cabecera primero y grilla después**, porque son dos entidades con endpoints propios. El orden no es indistinto: si falla el `PATCH`, lo que quedó guardado es exactamente lo que se ve en pantalla y el toast dice que las líneas no se guardaron. Al revés —grilla primero— un fallo dejaría los totales movidos contra una cabecera vieja.
- **El `orden` impreso es la posición en la grilla**, y no se manda aparte: los renglones se imprimen por `orden, id`, así que dejar que el número y la posición se elijan por separado permite que no coincidan. Se renumera 0..N al guardar — cambia el valor, no la secuencia.
- **El `monto` no se deriva de cantidad × unitario**, que es la invariante del endpoint (los descuentos van en negativo y los encabezados en cero). La grilla lo resuelve así: **se autocompleta mientras nadie lo toque**, escribir en el campo lo vuelve manual para siempre, y un renglón que **llega** con un monto distinto de la multiplicación nace manual — si no, tocarle la cantidad a un descuento le borraría el signo. Cuando diverge aparece `.linea-sugerencia` en `--warn` —no en `--danger`, porque **no es un error**— que al clickearse lo iguala.
- **Los totales se recalculan mientras se tipea, con la MISMA cuenta que el servidor**: total = Σ monto, y el IVA se **desagrega** de cada monto sólo en las alícuotas que `comprobanteTotalesDe()` desagrega (10,5 y 21). Cualquier otra fórmula haría que la caja anuncie un total y el Guardar persista otro.
- **Guardar SIEMPRE retotaliza, aunque no se haya tocado una línea**, porque el `PATCH` sale igual y todo `PATCH` termina en `comprobanteTotalizar()`. Efecto práctico: un comprobante cuyos totales guardados no coincidan con sus renglones queda corregido al primer guardado, aunque sólo se haya editado el domicilio. **No es un caso teórico ni masivo**: de 2.332 comprobantes hay 4 con esa diferencia y **uno solo en Preparación**, que es el único estado editable. Corregirlo es lo que pide la invariante 2 de `comprobantes_renglones.php` — el total tiene que salir de sus renglones —, así que se deja pasar en vez de detectarlo y avisar.
- **`Cliente` ES el combo con buscador de §34-bis — un campo, no un botón.** Se escribe ahí y las coincidencias salen mientras se tipea; al elegir una (con Enter o con el mouse) se vuelcan los seis campos de abajo. Son 64 clientes que ya viajan en `catalogos`, así que la búsqueda es en memoria y no hay un request por tecla.
  - **Reemplazó a tres piezas, y las tres sobraban**: un `Cliente (ID)` numérico, su `.form-nota` con el nombre que resolvía ese id, y un botón `Elegir de clientes` en el rótulo de la sección que desplegaba este mismo combo aparte. Eran dos controles y una línea de apoyo para **un** dato, y el que se veía primero era el id — que no se lo sabe nadie. Con el combo, el nombre se escribe adentro del campo y el id queda en el `<input type="hidden">` que lee el Guardar (§34-bis).
  - **Y por eso la sección `Cliente` desapareció**: su rótulo con la línea separadora no agrupaba nada que el campo no agrupe ya, y partía en dos lo que se completa de un solo movimiento. Los campos quedaron en la sección `Comprobante`, donde además es donde viven — `razon`, `domicilio`, `correo`, `celular`, `cuit` y `condicion` son **columnas de `comprobantes`**, no del cliente: son los datos **con los que se emitió**, copiados al emitir para que el documento no cambie si mañana el cliente se muda.
  - **Escribir encima suelta el cliente pero NO borra los seis campos.** Es la regla de §34-bis (texto nuevo con id viejo es el estado que hace guardar algo distinto de lo que se lee) más la mitad de acá: un comprobante puede emitirse a alguien que no está en `clientes`, así que soltar el id no es motivo para borrarle la razón social.
  - **El foco se queda en `Cliente` al elegir.** Lo mandaba a `Razón social` cuando era un picker que se cerraba solo; con el combo fijo en el formulario, saltar sacaría del campo a quien está eligiendo y volver exigiría Shift+Tab.
- **El editor no explica los campos que no tiene.** Hubo una `.form-nota` bajo las fechas que aclaraba que el talonario, el número, el CAE, los totales y el estado no se editan ahí. Se sacó: el formulario ya lo dice no mostrándolos, y un párrafo sobre campos ausentes ocupa lugar permanente arriba de los que sí están. Dónde se asignan sigue dicho donde se hace — el número y el CAE los pone `Autorizar` contra AFIP, y los totales los recalcula el servidor con los renglones en cada guardado.
- **La condición fiscal del cliente NO se copia a ciegas.** `clientes.condicion` y `comprobantes.condicion` son **dos catálogos distintos** —el del cliente tiene `EX` y el del comprobante `RE`—, así que un código que no está en el combo del comprobante se deja como estaba y se avisa por toast. Escribirlo igual guardaría una condición que después ninguna pantalla sabe mostrar.
- **Quitar una línea no pregunta**; la baja desde la ficha sí. No es una inconsistencia: acá la fila no se borra de la base hasta el Guardar, así que Cancelar la trae de vuelta — en la ficha el botón escribe en el acto.
- **La grilla vacía se dice con una fila de la tabla**, no con un bloque debajo del `<thead>`: con un `<div>` la tabla pierde el borde y se lee como si no hubiera cargado.

## 26. Editor JSON (textarea monoespaciado)

Para pantallas que necesitan editar un blob JSON crudo (configuración de dispositivos, payloads, plantillas). No es un editor con syntax-highlighting — es un `<textarea>` con fuente monoespaciada, sin envoltura de línea y con utilidades de formateo + validación al guardar.

Va siempre dentro de un `.modal.modal-wide` (ver §14) para que el JSON respire a lo ancho. La validación es solo sintáctica del lado del cliente (`JSON.parse` + try/catch) y vuelve a validarse en el backend; no se imponen schemas en la UI.

```html
<div class="modal-backdrop open">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title">
        Configuración JSON
        <span class="modal-subtitle">Sensor A · <code>RX-0001</code></span>
      </div>
      <button class="btn-icon-sm">×</button>
    </div>
    <div class="modal-body">
      <div class="form-group">
        <label for="cfg">JSON libre. La validación de la estructura la hace el firmware al recibirla.</label>
        <textarea id="cfg" class="json-editor" spellcheck="false" autocomplete="off"></textarea>
        <div class="field-error" style="display:none"></div>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" style="margin-right:auto">
        <i class="fa-solid fa-wand-magic-sparkles"></i> Formatear
      </button>
      <button class="btn btn-ghost">Cancelar</button>
      <button class="btn btn-primary">Guardar</button>
    </div>
  </div>
</div>
```

```css
.json-editor {
    font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
    font-size: .82rem;
    line-height: 1.5;
    min-height: 360px;
    max-height: 60vh;
    white-space: pre;       /* sin word-wrap: el JSON no se envuelve */
    overflow: auto;         /* scroll horizontal si la linea es larga */
    tab-size: 2;
}
```

**Reglas:**
- Modal en variante `.modal-wide` (760px). Si el contenido cabe en 520px no es un caso de editor JSON: usá inputs comunes.
- Textarea con clase `.json-editor`. Heredá los tokens normales de `input/textarea` (§7) — solo cambia tipografía, alto y `white-space`.
- Botón **Formatear** a la izquierda del footer (`margin-right:auto`, ghost). Re-serializa el contenido con `JSON.stringify(v, null, 2)`. Si el JSON está roto, mostrar el error y no formatear.
- Validar al guardar: parse, marcar `input-invalid` + `.field-error` con el mensaje del error de parseo. No deshabilitar el botón Guardar hasta que el contenido sea válido — el usuario tiene que poder intentarlo y ver el error.
- Aceptar **textarea vacío = JSON nulo** (limpiar configuración). Documentarlo en el label si aplica.
- No usar resaltado de sintaxis ni librerías tipo Monaco/CodeMirror: contradice §1 del STACK (sin build step, sin librerías UI pesadas).
- El editor JSON puede convivir con `.form-group` de campos normales dentro del mismo `.modal-wide` (ej.: "Editar dispositivo" combina dominio / estado / UID / tipo / nombre / ubicación + `Configuración` JSON). En ese caso el JSON va como **último `.form-group`** del cuerpo, después del resto de los inputs, y el botón **Formatear** sigue alineado a la izquierda del footer con `margin-right:auto`.

## 27-bis. Pantalla de login

`login.php` es la **única vista de cloud que vive fuera de la SPA** y no tiene chrome (no hay sidebar ni topbar). El usuario aterriza acá cuando no hay sesión, ingresa credenciales y, si el login es correcto, el backend abre la sesión y el navegador es redirigido a `index.php`.

**La credencial es el correo de un controlador, no un usuario.** El primer campo se rotula `Correo` y es un `type="email"` — no `Usuario` / `type="text"` como hasta el 07/09/2026, cuando el login autenticaba contra `usuarios` (los clientes de app y panel). `controladores` no tiene columna `usuario`: la identidad es `correo`, con UNIQUE y normalizado a minúsculas. El subtítulo acompaña el cambio ("Ingresá con tu correo para continuar"). Ver el encabezado de `api/login.php`.

Esta pantalla es la **única excepción** a la regla "el rojo solo aparece como acento" (§1). El card del login va **pintado completo en `var(--primary)`** — mismo rojo institucional que sidebar y topbar — porque la pantalla *es* la marca: lo primero que ve el usuario antes de entrar a la app. La regla del rojo como acento aplica solo cuando hay zona gris alrededor (cards / modales sobre `--bg`). Acá no hay zona gris dentro del card, así que el card sigue las reglas de §4-§5 (chrome rojo) en lugar de las de §14 (modales).

Reglas visuales:

- **Card pintada en rojo institucional**: `background: var(--primary)`, `border: 1px solid rgba(0,0,0,.25)`, `border-radius: 14px` (mismo radio que `.modal` §14), `box-shadow: var(--shadow-lg)`. Ancho `max-width: 360px`.
- **Fondo de la pantalla** sobre `var(--bg)` (gris). El rojo se concentra en la card; el fondo permanece neutro para que el card "flote" sin sangrar contra el viewport.
- **Tipografía**: dentro del card se usan `#fff` y opacidades de blanco (`.78-.88`) — **no** `--text` / `--muted` / `--border` (esos tokens son para zona gris). El logo va centrado arriba sin banner interno (la card entera ya es roja).
- **Inputs sobre rojo**: fondo `rgba(0,0,0,.22)`, borde `rgba(0,0,0,.35)`, texto `#fff`, placeholder `rgba(255,255,255,.55)`. `focus` ring en blanco (`border-color: #fff; box-shadow: 0 0 0 3px rgba(255,255,255,.22)`) en lugar del rojo habitual (que sería invisible sobre rojo).
- **Botón primario invertido**: dentro de `.login-page` el `.btn-primary` se invierte a `background: #fff; color: var(--primary)`. Sobre fondo rojo, un botón rojo desaparecería; el blanco con texto rojo es el patrón de máximo contraste y queda visualmente como el CTA esperado.
- **Error de credenciales**: banda blanca sobre fondo negro translúcido (`rgba(0,0,0,.28)` con borde `rgba(0,0,0,.35)`), no `.field-error` por defecto (que es rojo `--danger` y se pierde sobre el rojo de fondo).
- **Sin footer**: la pantalla de login no muestra versión ni leyendas — el card queda minimal (logo + título + subtítulo + form). La versión solo se muestra en la SPA (sidebar-footer §4).

```html
<body class="login-page">
  <main class="login-shell">
    <section class="login-card">
      <div class="login-brand">
        <img src="assets/img/reactor_white.png" alt="Reactor" class="login-logo">
      </div>
      <h1 class="login-title">Reactor Cloud</h1>
      <p class="login-subtitle">Ingresá con tu correo para continuar.</p>
      <form class="login-form" id="login-form">
        <div class="form-group">
          <label for="login-correo">Correo</label>
          <input type="email" id="login-correo" name="correo" autocomplete="username" autofocus required>
        </div>
        <div class="form-group">
          <label for="login-contrasena">Contraseña</label>
          <input type="password" id="login-contrasena" name="contrasena" autocomplete="current-password" required>
        </div>
        <div class="field-error login-error" id="login-error" hidden></div>
        <button type="submit" class="btn btn-primary login-submit">
          <i class="fa-solid fa-right-to-bracket"></i> <span>Ingresar</span>
        </button>
      </form>
    </section>
  </main>
</body>
```

```css
body.login-page { background: var(--bg); }

.login-shell    { min-height: 100vh; display: flex;
                  align-items: center; justify-content: center; padding: 24px; }
.login-card     { width: 100%; max-width: 360px;
                  background: var(--primary); border: 1px solid rgba(0,0,0,.25);
                  border-radius: 14px; box-shadow: var(--shadow-lg);
                  padding: 28px 28px 24px;
                  display: flex; flex-direction: column; gap: 16px;
                  color: #fff; }
.login-brand    { display: flex; align-items: center; justify-content: center; padding: 4px 0 0; }
.login-logo     { display: block; height: 44px; max-width: 80%; object-fit: contain; }
.login-title    { font-size: 1.2rem; font-weight: 700; text-align: center; color: #fff; }
.login-subtitle { font-size: .85rem; color: rgba(255,255,255,.78); text-align: center; margin-top: -8px; }

.login-form .form-group label { color: rgba(255,255,255,.88); }
.login-form input[type=text],
.login-form input[type=password] {
    background: rgba(0,0,0,.22);
    border: 1px solid rgba(0,0,0,.35);
    color: #fff;
}
.login-form input::placeholder { color: rgba(255,255,255,.55); }
.login-form input:focus {
    border-color: #fff;
    box-shadow: 0 0 0 3px rgba(255,255,255,.22);
}

.login-error {
    color: #fff;
    background: rgba(0,0,0,.28);
    border: 1px solid rgba(0,0,0,.35);
    border-radius: var(--radius);
    padding: 8px 12px;
    font-size: .82rem;
}

.login-page .btn-primary       { background: #fff; color: var(--primary); }
.login-page .btn-primary:hover { background: rgba(255,255,255,.9); color: var(--primary-h); }

.login-submit { justify-content: center; padding: 10px 16px; margin-top: 4px; }
```

**Cuándo NO usar este patrón**: cualquier flujo que requiera más de un par de campos (registro, recuperación de contraseña, MFA) deja de ser un card chico — pasa a ser un modal con su propio header (§14) o un módulo con `module-header` (§23). En esos casos vale la regla habitual (card gris sobre `--bg`, rojo solo como acento), no esta excepción.

## 27. Tile grid (menú de navegación / lanzadores)

Grilla de **tarjetas-botón** para pantallas que funcionan como menú de aterrizaje (por ejemplo Herramientas, donde cada tile lanza una utilidad de testing o navega a una sub-pantalla). No es para datos numéricos: para eso está `.stat-card` (§12).

```html
<div class="tile-grid">
  <button type="button" class="tile-card">
    <span class="tile-icon"><i class="fa-solid fa-microchip"></i></span>
    <span class="tile-title">Simulador de señales</span>
    <span class="tile-desc">Genera y envía señales sintéticas para probar la ingesta.</span>
  </button>
  <a href="#/tools/webhooks" class="tile-card">
    <span class="tile-icon"><i class="fa-solid fa-upload"></i></span>
    <span class="tile-title">Test de webhooks</span>
    <span class="tile-desc">Envía payloads JSON a un endpoint externo.</span>
  </a>
</div>
```

```css
.tile-grid  { display: grid;
              grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
              gap: 16px; }
.tile-card  { background: var(--surface); border: 1px solid var(--border);
              border-radius: var(--radius); padding: 20px;
              display: flex; flex-direction: column; gap: 6px;
              text-align: left; cursor: pointer; text-decoration: none;
              color: var(--text); font-family: inherit;
              transition: border-color .15s, background .15s, transform .1s; }
.tile-card:hover  { border-color: var(--primary); background: var(--row-hover); }
.tile-card:active { transform: scale(.98); }
.tile-icon  { font-size: 1.6rem; line-height: 1; margin-bottom: 4px; }
.tile-title { font-weight: 600; font-size: .95rem; color: var(--text); }
.tile-desc  { font-size: .8rem; color: var(--muted); }
```

**Reglas:**
- El tile puede ser `<a href="#/<ruta>">` (cuando navega a otra pantalla) o `<button type="button">` (cuando dispara una acción in-situ, por ejemplo abrir un modal o ejecutar un test).
- Estructura interna: emoji-icono (§20) + título corto + descripción breve. La descripción es opcional si el título alcanza.
- Hover marca el borde en `--primary` para reforzar que es clickeable. El tile vive en zona gris, no se pinta de rojo sólido — el rojo entra solo como acento (§1).
- Columna mínima 220px con `auto-fill`: el grid se acomoda solo desde una sola tarjeta hasta varias por fila.
- No anidar `tile-grid`s ni mezclar `tile-card` con `stat-card` en el mismo contenedor: cada uno tiene su semántica.

## 28. Herramientas: Editor de parámetros

Utilidad de **Herramientas** (§27) que gestiona la tabla `parametros` (`id / variable / valor / comentario`, esquema legacy compartido con las apps históricas de Reactor — no la tocamos, solo la editamos). El tile es `fa-puzzle-piece` / **Editor de parámetros**. Cada fila es una variable runtime que otras partes del sistema leen para configurarse sin redeploy.

Sigue el patrón "modal-gestor + sub-modal de form" común a las utilidades ABM chicas del panel, pero con dos desviaciones respecto de un ABM normal (`ABM.md`):

1. **Sin modal de Consulta separado.** El modelo es plano (3 campos que ya se ven en la tabla) — abrir un modal solo para releerlos sería ruido.
2. **Row click → Edición** (no Consulta). Diferencia con la regla general `ABM.md §1.3` de que el click abre Consulta.

Estas dos desviaciones vienen de la skill `crear_editor_de_parametros` y se aplican solo a este tipo de herramienta (configuración key/value flat). Para cualquier entidad de dominio con >4 campos, seguir usando el patrón ABM estándar.

```html
<div class="modal-backdrop open">
  <div class="modal" style="max-width:880px">
    <div class="modal-header">
      <div class="modal-title">
        [fa-puzzle-piece] Editor de parámetros
        <span class="modal-subtitle">15 parámetros</span>
      </div>
      <button class="btn-icon-sm" data-act="close">×</button>
    </div>
    <div class="modal-body">
      <div class="toolbar" style="margin-bottom:0">
        <div class="toolbar-left">
          <div class="search-wrap">
            <input class="search-input" type="search" placeholder="Buscar variable, valor, comentario…">
            <button class="search-clear">×</button>
          </div>
          <button class="btn btn-ghost btn-sm" data-act="refresh"><i class="fa-solid fa-rotate"></i></button>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary btn-sm" data-act="new">
            <i class="fa-solid fa-plus"></i> Nuevo parámetro
          </button>
        </div>
      </div>
      <div class="table-card">
        <table> … Código / Variable / Valor / Comentario / Acciones (`fa-bars`) … </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" data-act="close">Cerrar</button>
    </div>
  </div>
</div>
```

**Reglas:**
- **Modal 880px inline** (`style="max-width:880px"`). No hay `.editor-parametros-*` — los anchos específicos van inline.
- **Toolbar interna**: buscador rápido client-side + botón refrescar (ghost, ícono) a la izquierda; botón primario `+ Nuevo parámetro` a la derecha. Sin botón `Filtros` — dataset chico.
- **Búsqueda client-side sobre el cache**: el endpoint devuelve el listado completo (`SELECT * FROM parametros ORDER BY variable`). El filtro por substring vive en el front (debounce 150ms).
- **Sin Consulta.** La columna `Acciones` tiene un único botón hamburguesa (`fa-bars`) que abre el menú contextual con el orden fijo del skill: **Editar · Copiar variable · --- · Eliminar** (Eliminar al final con `danger:true`). Sin ícono de "ver".
- **Row click → Editar**: `<tr class="row-clickable">` con listener que ignora clicks originados en `<button>` (para no conflictuar con el botón hamburguesa). Reusa el estilo del §Visor de sucesos.
- **Sub-modal de Alta/Edición**: `.modal` chico (max-width 560px) con 3 campos: `Variable` (input monospace, `maxlength:255`), `Valor` (textarea monospace, `maxlength:255`), `Comentario` (input opcional, `maxlength:1024`). Validación client-side de `Variable` con regex `^[A-Za-z0-9_.\-]+$` — feedback inmediato en `.field-error`. El backend re-valida longitudes (`api/parametros.php`).
- **Focus del form**: en alta, `Variable` (autofoco + select); en edición, `Valor` (la variable ya suele ser conocida — el usuario viene a cambiar el valor).
- **"Copiar variable"** en el menú contextual: la variable es el token que se pega en código (`getParametro('smtp_host')` o equivalente), ahorra alt-tab. Usa el helper `copyToClipboard()`.
- **Eliminación**: `confirmDialog` estándar (§15) sobre el modal-gestor.
- **Footer del gestor**: un único botón `Cerrar` ghost. No hay acción primaria — el CRUD se ejerce desde el listado.
- **ESC en cascada**: menú contextual (row-menu) → sub-modal de form → modal-gestor.
- **Cuándo NO usar este patrón**: si la entidad tiene >4 campos, si la tabla crece a cientos de filas, o si necesita filtros por estado/fechas. Esos casos van como módulo ABM regular en el sidebar (ver §23).

## 29. Herramientas: Migrador DB

Utilidad de **Herramientas** (§27) que lista los archivos `.sql` de `cloud/sql/migrations/` cruzados contra el ledger `migraciones` de la BD del entorno actual, y permite aplicarlos uno por uno o en lote. Reemplaza al antiguo "Migraciones" (consola SSE) por un modelo más discreto — cada migración se ve, se previsualiza y se aplica con un click.

El tile vive en el `tile-grid` de Herramientas (`fa-scroll` / **Migrador DB**). Al click abre un **modal ancho** (max-width **960px**) con dos badges en el header (nombre de la BD activa + entorno coloreado por `APP_ENV`), toolbar con refrescar + resumen textual (`N archivos · M aplicadas · K pendientes`) + botón primario **Aplicar todas las pendientes**, y una tabla con columnas `Estado / Archivo / Tamaño / Hash / Aplicada / Acciones`. La tabla vive dentro de un `.table-card` con `max-height:52vh; overflow-y:auto` y los `<th>` con `position:sticky; top:0; background:var(--bg)` — solo scrollea la lista.

Las filas **no son clickeables** — las acciones se disparan desde los botones explícitos de la columna `Acciones` (**Ver SQL** siempre; **Aplicar** solo cuando el estado es pendiente). "Ver SQL" abre un segundo modal (`.modal-wide`) con el contenido del archivo en un `<textarea class="json-editor" readonly>`; si la migración está pendiente, el footer del preview trae el botón `Aplicar` inline.

Cada `apply` (individual o masivo) pasa por un confirm reforzado en producción: título con `fa-triangle-exclamation`, copy con `(PRODUCCIÓN)`, label `Aplicar en prod` y CTA rojo (`btn-danger`). En `development` es `btn-primary` con label `Aplicar`. Los toasts de error usan `toast(msg, { error:true, duration:10000 })` porque los mensajes crudos del motor SQL suelen ser largos.

```html
<div class="modal-backdrop open">
  <div class="modal" style="max-width:960px">
    <div class="modal-header">
      <div class="modal-title">
        [fa-scroll] Migrador DB
        <span class="badge badge-info" style="font-family:monospace">reactor_dev</span>
        <span class="badge badge-success" style="font-family:monospace">development</span>
      </div>
      <button class="btn-icon-sm" data-act="close">×</button>
    </div>
    <div class="modal-body">
      <div class="toolbar" style="margin-bottom:0">
        <div class="toolbar-left">
          <button class="btn btn-ghost btn-sm" data-act="refresh"><i class="fa-solid fa-rotate"></i></button>
          <span style="font-size:.82rem;color:var(--muted)">4 archivos · 3 aplicadas · 1 pendiente</span>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary btn-sm" data-act="apply-all">Aplicar 1 pendiente</button>
        </div>
      </div>
      <div class="table-card" style="max-height:52vh;overflow-y:auto">
        <table> … Estado / Archivo / Tamaño / Hash / Aplicada / Acciones … </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" data-act="close">Cerrar</button>
    </div>
  </div>
</div>
```

**Reglas:**
- **Modal 960px inline** (`style="max-width:960px"`): no crear una clase `.migrador-*`. Los anchos específicos de esta herramienta viven como inline styles.
- **Doble badge en header**: `badge-info` con nombre de BD (monospace) + badge coloreado por entorno: `badge-success` en dev, `badge-danger` en prod, `badge-warn` en cualquier otro valor.
- **Sin CSS propio**: la herramienta reusa `.modal-backdrop`, `.modal`, `.modal-wide` (para el preview), `.toolbar`, `.table-card`, `.badge*`, `.json-editor`, `.btn*` ya definidos. Solo agrega `tbody tr.row-clickable { cursor: pointer }` (que no usa el migrador pero sí el visor de sucesos, §30).
- **Orden del listado**: pendientes arriba en orden ascendente (mismo orden en que se aplican); aplicadas debajo por `id` DESC (última aplicada arriba). No ordenar aplicadas por nombre — una migración de fecha vieja aplicada tarde tiene que aparecer arriba, no en el medio.
- **Estado**: badge `badge-info` para `pendiente`, `badge-success` para `aplicada`, `badge-warn` con `fa-triangle-exclamation` para `aplicada` con `hash_drift` (el archivo cambió después de aplicarse).
- **Hash**: se muestran los primeros 8 chars, con el hash completo en el `title` del `<td>`.
- **Sin CSS propio** para el preview: `.modal-wide` (760px) + `.json-editor` para el textarea SQL; botón `Aplicar` solo visible cuando la migración está pendiente.
- **Confirm reforzado en prod**: usar el helper local `confirmarMigrador(titulo, msg, ctaLabel, danger, onOk)` — `confirmDialog` estándar hardcodea el label "Eliminar" y no sirve acá.
- **Cierre durante corrida masiva**: bloqueado con toast (`Hay una migración en curso`). El backdrop de listado ignora clicks; el ESC solo cierra el preview, no el listado, mientras `_migradorAplicando` esté activo.
- **UNA MIGRACIÓN APLICADA NO SE EDITA — NI SUS COMENTARIOS.** El ledger guarda el SHA-256 del archivo entero, así que cambiar una sola línea `--` la marca `drift` en todos los entornos donde ya corrió, y el Migrador **no tiene forma de reconocer el hash nuevo**: no puede reaplicarla (corta con 409 `ya fue aplicada`) ni aceptar el cambio. El aviso queda ahí hasta que alguien toque `migraciones.hash` a mano. Pasó el 06/09/2026 con `20260906_1600` y `20260906_1700`: se corrigió la prosa de dos migraciones ya aplicadas en producción y hubo que revertir el texto al que se había aplicado para limpiarlo. **Si lo que decía la migración quedó viejo, la corrección va en el código y en este documento, no en el `.sql`** — el archivo describe el cambio en el momento en que se hizo, no el comportamiento actual.
- **Cuándo NO usar este patrón**: si la migración implica progreso multi-paso visible (log de 100 líneas por archivo), un modal-consola SSE sería más apropiado. El Migrador DB apuesta a que cada `.sql` es corto y se aplica en <1s — para eso alcanza con el toast final.

## 30. Herramientas: Visor de sucesos

Utilidad de **Herramientas** (§27) que muestra el log de actividad de los módulos del panel cloud (`sucesos_log` — no confundir con la tabla legacy `sucesos` compartida con las apps históricas). El panel solo lee: la escritura vive en `api/lib/sucesos.php` (`registrarSuceso()`), invocada desde el resto de los endpoints cuando pasa algo notable.

El tile es `fa-newspaper` / **Visor de sucesos**. El modal es **ancho** (max-width **1100px**) con toolbar que combina buscador rápido + **chips de filtro por tipo** (Todos · Info · Alerta · Error, en ese orden, con Error último) + rango de fechas (`Desde` / `Hasta`) + selector de `Límite` (100 / 200 / 500 / 1000 / 2000, default 200) + refrescar. La tabla lista `ID / Fecha / Origen / Tipo / Detalle`; las filas son clickeables (`row-clickable`) y abren un **modal de detalle** (max-width 780px) con Fecha + Tipo (ícono + etiqueta) en `form-row`, Origen en fila propia, y Detalle en un `<textarea readonly>` monoespaciado grande.

**Iconos + colores por tipo** (fijos, no cambiar por proyecto):
- **Info** → `fa-circle-info` en `var(--info)`.
- **Alerta** → `fa-triangle-exclamation` en `var(--warn)`.
- **Error** → `fa-circle-exclamation` en `var(--danger)`.

Se usan en los chips, en la celda `Tipo` del listado y en el modal de detalle.

```html
<div class="modal-backdrop open">
  <div class="modal" style="max-width:1100px">
    <div class="modal-header">
      <div class="modal-title">
        [fa-newspaper] Visor de sucesos
        <span class="modal-subtitle">200 de 12.345 registros</span>
      </div>
      <button class="btn-icon-sm" data-act="close">×</button>
    </div>
    <div class="modal-body">
      <div class="toolbar" style="margin-bottom:0">
        <div class="toolbar-left">
          <div class="search-wrap"><input class="search-input" type="search" placeholder="Buscar origen, detalle…"></div>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <button class="filter-chip active">Todos</button>
            <button class="filter-chip"><i class="fa-solid fa-circle-info" style="color:var(--info)"></i> Info</button>
            <button class="filter-chip"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warn)"></i> Alerta</button>
            <button class="filter-chip"><i class="fa-solid fa-circle-exclamation" style="color:var(--danger)"></i> Error</button>
          </div>
          <label>Desde <input type="date"></label>
          <label>Hasta <input type="date"></label>
          <label>Límite <select>…</select></label>
          <button class="btn btn-ghost btn-sm"><i class="fa-solid fa-rotate"></i></button>
        </div>
      </div>
      <div class="table-card">
        <table> … ID / Fecha / Origen / Tipo / Detalle … </table>
      </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" data-act="close">Cerrar</button>
    </div>
  </div>
</div>
```

**Reglas:**
- **Read-only end to end.** No hay botones de alta / edición / borrado. El endpoint `api/sucesos.php` rechaza con 405 cualquier método que no sea `GET`. Si el usuario necesita purgar la tabla, es otra herramienta (que no existe todavía — por ahora se hace vía DB directa).
- **Orden fijo `id DESC`** (más recientes arriba). No exponer control de orden en la UI.
- **Chips excluyentes**: sólo un chip activo a la vez. Todos · Info · Alerta · Error, en ese orden — Error último porque visualmente pesa más y es la categoría menos frecuente. No hay multi-select.
- **Selector de límite explícito** en vez de paginación: el visor es para ojear, no para recorrer. Si el usuario necesita más de 2000, afinar filtros.
- **Truncado del detalle en el listado**: `max-width:0; overflow:hidden; text-overflow:ellipsis; white-space:nowrap` en la celda + `title="<detalle>"` para tooltip completo al hover. Click en la fila abre el modal de detalle con el textarea grande.
- **Doble modal**: el modal de listado queda abierto **debajo** del modal de detalle. Al cerrar el detalle, el listado sigue visible y preserva filtros + scroll. ESC cierra en cascada (detalle → listado).
- **Footer del detalle con botón `Copiar`**: a la izquierda (`margin-right:auto`) va un botón ghost con `fa-copy` y label "Copiar" que serializa el suceso completo (`id / fecha / origen / tipo con label + código / detalle envuelto en triples backticks`) y lo copia al portapapeles vía `copyToClipboard()`. El formato está pensado para pegarse directo en un asistente de programación — normaliza `\r\n → \n`, no incluye HTML ni el ícono del tipo. A la derecha va el botón ghost `Cerrar` habitual.
- **Sin CSS propio**: reusa `.modal-backdrop`, `.modal`, `.toolbar`, `.search-wrap`, `.filter-chip`, `.table-card`, `.form-row`, `.form-group`, `.json-editor`, `.td-id`, `.badge*` y `tbody tr.row-clickable`. Los anchos (`max-width:1100px` en listado, `780px` en detalle) van inline sobre `.modal`.
- **`tipo` NUNCA es null en el JSON**: el backend fuerza `'info'` ante valores inválidos o vacíos, para que el front siempre pinte el ícono correcto sin condicionales extra.
- **Escritura desde otros módulos**: `require_once __DIR__ . '/lib/sucesos.php'` (o su equivalente) + `registrarSuceso($pdo, 'NombreCorto', 'info'|'error'|'alerta', 'texto')`. El helper swallowea sus propios errores: un fallo en el log nunca debe romper el flujo del caller.

## 31. Herramientas: Explorador DB

Utilidad de **Herramientas** que recorre las tablas de la BD del entorno activo. Modal a pantalla casi completa (`max-width:1080px; height:calc(100vh - 64px)`), dos vistas: **listado de tablas** (tabla clickeable con `Tabla / Filas (aprox.) / Engine`) y **detalle** con tabs `Registros` (default) y `Campos`. Breadcrumbs `<db> / <tabla>` navegan entre vistas.

**Pestaña Registros**: selector de `Límite` (10 / 50 / 100 / 200 / 500, default 50), buscador client-side sobre lo cargado, orden fijo `PK DESC`. **Doble click** en celdas editables abre editor inline con `fa-check` / `fa-xmark` y `fa-ban` (NULL) cuando la columna lo permite. Se bloquea la edición de columnas PK, `auto_increment` y de tablas sin PK — visualmente con `cursor:not-allowed` y color muteado.

**Pestaña Campos**: `# / Campo / Tipo / Null / Clave / Default / Extra`. Badges: `PRI→PK warn`, `UNI→UQ info`, `MUL→IDX`. `Default null` como `NULL` muteado; `Extra` y valores no nulos en `<code>`.

**Endpoints** (`api/db_tables.php`, `api/db_describe.php`, `api/db_records.php`, `api/db_update.php`): siempre validan identificadores contra `INFORMATION_SCHEMA` antes de meterlos en SQL. `update` es solo POST, PK obligatoria, rechaza editar PK/auto_increment/NULL sobre NOT NULL, relee el valor guardado (por si el motor casteó) y lo devuelve como `valor_guardado`.

**Estilo local**: reusa `.modal`, `.badge*`, `.table-card`, `.spin`. La única clase específica del módulo es la cascada `.db-exp-*` documentada en el bloque §30 de `style.css`.

## 32. Herramientas: Explorador S3

Utilidad de **Herramientas** que navega el bucket S3 del entorno activo como un file manager. Modal `max-width:980px; height:calc(100vh - 24px)`; header con badge `badge-info` del bucket activo; toolbar con **breadcrumbs `raíz / ...`** a la izquierda y a la derecha, en orden estricto: **Refrescar · Buscador · Subir · Nueva carpeta**. Tabla `[ícono] / Nombre / Tamaño / Modificado / Acciones` con la fila `..` cuando hay `prefix` activo, ordenada por `Modificado DESC`.

**Menú contextual** (usa `openRowMenu` estándar): `Abrir / Descargar` · `Copiar URL pública` · --- · `Eliminar` (rojo). Los dos primeros se ocultan para carpetas. **Eliminar carpeta** siempre pasa por `confirmDialog` con copy explícito de "TODO su contenido de forma recursiva".

**Thumbnails** 28×28 para archivos de imagen (`jpg/jpeg/png/gif/webp/bmp/svg/avif`) con `loading="lazy"` y fallback a `fa-file-image` `onerror`.

**Backend**: 4 endpoints (`api/s3_list.php`, `api/s3_upload.php`, `api/s3_create_folder.php`, `api/s3_delete.php`) apoyados en el helper `api/lib/s3.php` que firma SigV4 desde cero (sin AWS SDK). Las 4 variables `AWS_ACCESS_KEY_ID`, `AWS_SECRET_ACCESS_KEY`, `AWS_REGION`, `AWS_S3_BUCKET` viven en el `.env*` del entorno (canónicas — no usar `AWS_DEFAULT_REGION`). **Upload límite 20 MB**, MIME detectado del contenido real.

## 33. Herramientas: Programador de tareas

Utilidad de **Herramientas** que administra procesos automáticos programables. La fuente de verdad de qué corre y cuándo vive en la tabla `tareas` (nombre canónico de la skill); el cron del sistema tiene **una única línea** que invoca el scheduler cada minuto. La tabla legacy `tareas` (id/nombre/comando, MyISAM huérfana sin referencias en `api/panel/app/www`) fue eliminada del `schema.sql`; la migración `20260711_1300_crear_tareas.sql` es idempotente y cubre tres escenarios: DB fresca, DB con la legacy todavía presente (drop + create) y DB que aplicó el intento previo con nombre `tareas_cron` (rename de vuelta a `tareas` preservando datos).

**Modal de listado** (`max-width:1080px`): toolbar con buscador + chips `Todas / Activas / Inactivas` + `+ Nueva tarea`; tabla `Código / Nombre / Cron / Estado / Última corrida / Activa / Acciones`. Toggle inline en la columna `Activa`. Click en fila abre el **modal de Ejecuciones**; menú contextual con `Ver ejecuciones · Ejecutar ahora · Activar/Desactivar · Editar · Eliminar`.

**Modal de Alta/Edición** (`max-width:640px`): `Nombre`, `Descripción` opcional, `Script` (`<select>` poblado desde `api/tareas_scripts_disponibles.php`), `Expresión cron` (monospace) con botón `fa-sliders` que abre el **Constructor de cron** (5 selects modo + input + preview + descripción en español), `Timeout`, `Si ya está corriendo` (`Saltar` / `Ejecutar`), `Retención (días)`, `Estado`.

**Modal de Ejecuciones** (`max-width:1000px`): chips por estado (`Todas / Corriendo / OK / Error / Timeout / Killed`) + tabla `Código / Inicio / Duración / Estado / Disparo / Mensaje / Acciones`. Click en fila abre el **modal Terminal** con streaming SSE.

**Modal Terminal** (`max-width:960px`): `<pre class="terminal-live">` a fondo `#0d1117`, badge de estado en vivo, footer con `Auto-scroll` (toggle), `Detener` (visible mientras el SSE sigue abierto) y `Cerrar`. La conexión SSE emite `event: end` al cerrar la fila; el badge muta a `ok / error / timeout / killed`.

**Backend**: 5 endpoints (`tareas.php`, `tareas_ejecuciones.php`, `tareas_ejecutar.php`, `tareas_ejecucion_stream.php`, `tareas_scripts_disponibles.php`). Dos tablas: `tareas` (catálogo) + `tareas_ejecuciones` (historial), ambas con el nombre canónico de la skill. `DELETE` de una tarea corta en cascada (rechaza si hay una ejecución corriendo, borra los `.log` de disco, luego historial + fila). **Logging de errores**: cada `catch (Throwable $e)` de los endpoints llama a `registrarSuceso($pdo, 'cron/<endpoint>', 'error', $e->getMessage())` para que las fallas aparezcan en el Visor de sucesos (`sucesos_log`).

**Infraestructura de jobs** (`cloud/jobs/`): `_scheduler.php` (tick minutal), `_bootstrap.php` (runtime común con `marcarEjecucionOk/Error`, `anotarLog`, `ejecucionId`), `_cleanup_logs.php` (cleanup nocturno por `retencion_dias`), `.htaccess` (`Require all denied`), `crontab` (versionado; se instala en `/etc/cron.d/reactor-cloud`). Cada ejecución tiene su propio `.log` en `/var/log/reactor/cloud/ejecuciones/<id>.log`.

**Jobs de negocio**: `dolar_actualizar.php` (§33-quater), `articulos_recalcular.php` (§33-quinquies) y `contratos_plan_recalcular.php` (§33-sexies), que corren **en ese orden todos los días** — 06:00 la cotización, 07:00 el recálculo de los precios que salen de ella, 08:00 la reasignación de los planes que se cobran con esos precios. Cada eslabón falla por su cuenta: el de abajo corre igual con el dato que haya.

**Reglas de la infraestructura:**
- **`cron_expr` SE EVALÚA EN HORA DE ARGENTINA.** `cronMatch()` compara contra `new DateTime('now')`, o sea contra el reloj de PHP, y **el contenedor corre en UTC** (`docker/Dockerfile` no fija `TZ`, a diferencia de `motor/Dockerfile`). Por eso los tres entrypoints de `cloud/jobs/` —`_scheduler.php`, `_bootstrap.php` y `_cleanup_logs.php`— abren con `date_default_timezone_set('America/Argentina/Buenos_Aires')`, igual que `api/bootstrap.php` para la web. Sin esa línea `0 6 * * *` dispara a las 03:00, y además `anotarLog()` estampa horas UTC en el `.log` mientras la base escribe `inicio` / `fin` en `-03:00` (`SET time_zone = '-03:00'`): el mismo evento con dos horas distintas según dónde se lo mire.
- **La ruta del script la resuelve una sola función**, `tareaScriptAbs()` (`api/lib/tareas_script.php`), y la usan **las dos** puertas que lanzan un job: el tick minutal y el `Ejecutar ahora` de `api/tareas_ejecutar.php`. Si resolvieran distinto, una tarea andaría a mano y no por cron, o al revés. `tareas`.`script` guarda la ruta **relativa a la raíz del monorepo** (`cloud/jobs/x.php`, que es lo que lista `tareas_scripts_disponibles.php`) y eso sólo resuelve en producción, donde el repo entero vive en `/opt/app/reactor/`: **en desarrollo `docker-compose.yml` monta `./cloud` directamente como docroot** (`/var/www/html`), así que adentro del contenedor no existe la raíz del monorepo. De ahí el segundo intento contra la carpeta de `cloud`, la única que los dos entornos tienen. El síntoma de no tenerlo era un `.log` con `Could not open input file` y la fila colgada en `corriendo` hasta que la barría el watchdog — o sea **el Programador entero inutilizable en desarrollo**.
- **`tareas`.`ultimo_error` sólo lleva texto cuando la corrida NO terminó bien.** `marcarEjecucionOk()` también recibe un mensaje —el resumen de lo que hizo— y volcarlo ahí dejaría una tarea sana mostrando `último error: dolar: 1420.00 -> 1540.00`. El resumen de una corrida buena vive en `tareas_ejecuciones`.`mensaje`, que es la columna que lo describe.

**Requisitos de despliegue** (una sola vez al aprovisionar el server, ver `cloud/jobs/crontab`):
1. `cronie` instalado.
2. Extensión PHP `pcntl` habilitada (para el handler de SIGTERM del bootstrap).
3. Directorio `/var/log/reactor/cloud/ejecuciones/` con owner `www-data:www-data` y `chmod 755`.
4. Copiar `cloud/jobs/crontab` a `/etc/cron.d/reactor-cloud` (`chown root:root`, `chmod 644`).

Estos pasos se documentan también en el propio `cloud/jobs/crontab` y quedan pendientes del script de aprovisionar del server (no forman parte del deploy).

## 33-bis. Herramientas: Sincronizador de tablas

Utilidad de **Herramientas** (§27) que copia **una tabla entera** de un entorno al otro **preservando los IDs de origen**, con la salida en vivo por SSE en una terminal embebida. El tile es `fa-arrows-rotate` / **Sincronizador de tablas**.

**Un solo modal** (`.modal-wide`, 760px) y nada más: no hay listado, ni menú contextual, ni segundo modal. Sigue el estándar de modales del proyecto (§21-bis) — `modal-header modal-header-primary`, `.modal-menubar` con `Cerrar` (ghost) + `Sincronizar` (primary, **disabled** hasta que haya origen y tabla), **sin `.modal-footer`**.

El cuerpo son cuatro bloques en orden fijo: `form-row` con **Origen** (`<select>`) y **Destino** (`<input readonly>`, autopoblado con el entorno contrario); **Tabla** (`<select>`, deshabilitado hasta que haya origen); `<pre class="terminal-log">` con el log; y la nota al pie que explica la copia destructiva. El subtítulo del header muestra `host · database · N tablas` del origen elegido.

**Etiquetas de entorno**: `"Entorno (nombreDeBD)"` — `Desarrollo (reactor_dev)` / `Producción (reactor)` — sin descripciones extra (nada de "RDS", "réplica", etc.). El nombre de la BD sale de parsear los dos `.env`, sin abrir ninguna conexión.

**Endpoints** (`api/sincronizador_tables.php` JSON + `api/sincronizador_run.php` SSE), los dos detrás de `requireAuth()` y de `asertarSoloDev()`, apoyados en `api/lib/sincronizador.php`. Sin tabla en la base: la herramienta no guarda nada de sus corridas.

**Reglas:**
- **Solo corre en desarrollo, y lo decide el server.** `asertarSoloDev()` responde **403 JSON** antes de emitir un solo byte de stream. El motivo es que este proceso tiene a mano las credenciales de **los dos** entornos, y ese camino no puede existir en un panel expuesto a internet. El tile además lleva `soloDev: true` y no se dibuja en producción (mismo mecanismo que el Comparador DB, §33-ter) — **eso no es el control de acceso**, es no ofrecer un botón que sólo puede terminar en 403.
- **Las credenciales cruzadas salen de los `.env` bind-monteados** (`docker-compose.yml` líneas 28-29 montan `.env.development` **y** `.env.production` read-only en `/var/www/`). `sincParseEnv()` es una copia del parser de `env.php` **sin sus efectos secundarios**: no toca `putenv()` / `$_ENV` ni define constantes, porque hacerlo le pisaría la conexión al panel que está corriendo.
- **EL COMBO DE TABLAS LISTA, NO CUENTA.** Una sola query a `INFORMATION_SCHEMA.TABLES` pidiendo **sólo `TABLE_NAME`** y filtrando `TABLE_TYPE = 'BASE TABLE'` (las vistas no son sincronizables). Ninguna cantidad de filas en las etiquetas: ni `COUNT(*)` por tabla, ni `TABLE_ROWS`, ni `SHOW TABLE STATUS`. Con **129 tablas** —y la mitad de los round-trips contra la RDS de prod por internet— el primer paso de la herramienta tardaría decenas de segundos en dibujarse. El único `COUNT(*)` es el del `run`, sobre la tabla ya elegida y con la terminal mostrando en qué anda.
- **Copia destructiva, siempre.** Si la tabla existe en destino se le hace `TRUNCATE` (no `DELETE`: reinicia el `AUTO_INCREMENT`, es más rápido y garantiza que los IDs del origen no colisionen); si no existe, se crea con el `SHOW CREATE TABLE` del origen. **No hay modo "append"** — esto no es un mergeador.
- **El DDL se ejecuta literal.** Dev corre MySQL 8.0 y prod MariaDB 10.11: si el otro motor no acepta el `CREATE TABLE`, el error del driver se ve tal cual en la terminal. No se reescribe nada — adivinar equivalencias entre motores es como se corrompe un esquema en silencio.
- **Lotes de 200 filas** con `FOREIGN_KEY_CHECKS = 0` + `UNIQUE_CHECKS = 0` durante la corrida, y cursor **no bufferado** en el `SELECT` del origen (`senales` tiene ~725K filas: bufferarlas voltea el proceso). Si un lote falla, se reintenta **fila por fila** para aislar la rota: cada error puntual es una línea roja y la corrida **sigue**.
- **Las dos conexiones fijan `SET time_zone = '-03:00'`**, igual que `db()`. No es cosmético: una columna `TIMESTAMP` se lee convertida a la zona de la sesión y se escribe convertida de vuelta — con zonas distintas la tabla destino quedaría corrida unas horas sin que nada avise.
- **Confirm reforzado cuando el destino es prod**: se reusa `confirmarMigrador()` (§29) con título `Sincronizar a PRODUCCIÓN`, copy con el nombre de la tabla, label `Copiar a prod` y `danger:true`.
- **Cierre bloqueado durante la corrida**: el botón `Cerrar`, el click en el backdrop y ESC muestran el toast `Esperá a que termine la sincronización` en vez de cerrar. Los dos selectores y el botón quedan `disabled` hasta el evento `done`. Para cortar de verdad hay que cerrar la pestaña — el server lo detecta y termina, y la tabla queda parcial hasta el próximo `TRUNCATE`.
- **Sin rollback transversal**: la copia no va en transacción (el `TRUNCATE` hace commit implícito). Si falla a mitad, el destino queda parcialmente copiado; el operador reintenta y el `TRUNCATE` siguiente lo limpia.
- **`.terminal-log` es una clase nueva del CSS global** (§38 de `style.css`), no del módulo. **No es `.terminal-live`** del Programador de tareas (§33): aquella vuelca texto plano en gris sobre `#0d1117`; ésta pinta cada línea según el `type` del evento — verde por defecto, `success` verde brillante en negrita con `✓`, `warn` rojo suave con `⚠`, `error` rojo brillante en negrita con `✗`. Alto fijo 320px con scroll interno.
- **Las líneas se appendean con `textContent`, nunca `innerHTML`**: vienen del server y pueden traer el mensaje crudo del motor SQL.
- **El nombre de la tabla se valida en el server** con `^[A-Za-z0-9_]{1,64}$` antes de interpolarlo: va literal en `SHOW CREATE TABLE`, `TRUNCATE`, `INSERT INTO` y `ALTER TABLE`, donde no hay placeholders posibles.
- **Nada de credenciales en las respuestas**: `sincEntornoInfo()` devuelve `host` y `database` y nunca `DB_USER` / `DB_PASS`.
- **Origen y destino no pueden ser el mismo entorno** — el server corta con línea roja antes de tocar nada.

## 33-ter. Herramientas: Comparador DB

Utilidad de **Herramientas** (§27) que compara la **estructura** de la base de desarrollo contra la de producción y reporta las diferencias en vivo. Es **estrictamente de lectura** en los dos lados: se apoya entera en `information_schema`, no hace `SELECT` de datos, no ejecuta DDL y no escribe nada. El tile es `fa-code-compare` / **Comparador DB**.

**Un solo modal** (max-width **960px**) siguiendo el estándar (§21-bis): header primario, `.modal-menubar` con `Cerrar` (ghost) + `Comparar` + `Copiar log`, **sin footer**. El cuerpo es una nota breve, un `<pre class="terminal-log terminal-log-diff">` con el log y una línea `.run-status` al pie que muestra la última línea recibida mientras corre y el resumen coloreado al terminar.

**Un solo endpoint**, `POST api/comparar_db.php`, que devuelve `text/plain` en streaming — una línea por evento — y cierra **siempre** con `___END___ {json}`. No hay `GET list` ni `cancel`.

**Reglas:**
- **Dev-only en dos capas, las dos obligatorias.** El backend responde **403** con línea `[FAIL]` + `___END___` si `APP_ENV !== 'development'`. Y en el frontend la tarjeta **no se dibuja** en producción: `toolsCatalog` marca la herramienta con `soloDev: true` y `renderTools()` la filtra contra `<body data-env>`, que escribe `index.php`. **No es `display:none`** — la tarjeta no está en el DOM. La defensa real es el 403; el filtro es para que la grilla no ofrezca lo que el server va a rechazar.
- **El `data-tool-idx` indexa la lista YA filtrada.** Con el catálogo crudo, en producción cada tarjeta abriría la herramienta siguiente a la suya.
- **El auth es `authUser()`, no `requireAuth()`**, y no es un descuido: `requireAuth()` contesta JSON o un `Location` de redirect, y las dos cosas rompen el stream. El 401 sale por el mismo canal que todo lo demás. Va **antes** de tocar el `.env.production`: un no autenticado no provoca siquiera el `file()` sobre las credenciales del otro entorno.
- **`bootstrap.php` se incluye con `CLOUD_API_PUBLIC`** — sólo por `db()`, que es la conexión al entorno propio que la herramienta reusa en vez de abrir la suya — y hay que **volver a apagar `display_errors`** después: bootstrap lo prende en desarrollo, que es justo donde corre esta herramienta, y un warning inline se colaría como una línea más del stream.
- **`emit()` es la única salida y `emitFin()` el único final.** Ningún `echo` suelto (rompe el newline o el flush) y ningún `exit` sin `___END___` antes (el frontend queda esperando).
- **Buffering apagado a mano**: `ob_end_flush()` en loop, `implicit_flush`, `zlib.output_compression=0`, `apache_setenv('no-gzip')` y `X-Accel-Buffering: no`.
- **POST y no GET** a propósito: así ningún prefetch / preview del navegador dispara una comparación contra producción.
- **Seis consultas de estructura, no cuatro por tabla.** El esquema entero de cada lado se trae de un barrido por área (columnas / índices / FKs) — con 129 tablas, una consulta por tabla son cientos de round-trips contra un RDS remoto que ya tarda ~1,5 s sólo en conectarse. Con el barrido la corrida completa tarda **3,4 s**.
- **LOS DOS LADOS NO CORREN EL MISMO MOTOR, Y SIN NORMALIZAR EL REPORTE NO SE PUEDE LEER.** Dev es MySQL 8.0 y prod MariaDB 10.11; los dos describen el mismo esquema con textos distintos en `information_schema`. Medido sobre las 1.049 columnas comunes: **409 difieren sólo por el ancho de display de los enteros** (`int` en MySQL 8.0.19+ ↔ `int(11)` en MariaDB) y **864 sólo por cómo cada motor devuelve el default** (MariaDB lo da como *texto de expresión*: el default NULL vuelve como la cadena `NULL` de cuatro caracteres y un literal vuelve entrecomillado). Sin traducir eso el comparador reportaba **0 tablas idénticas de 120** y 3.140 líneas de log; con la normalización reporta **120 idénticas** en 278 líneas. Se normalizan tres cosas y nada más: ancho de display de enteros (sólo de los tipos enteros — en `varchar(255)` o `decimal(10,2)` el número sí es semántico), formato del `DEFAULT`, y el marcador `DEFAULT_GENERATED` que MySQL agrega al `EXTRA`. **Son representaciones, no semántica**: `varchar(50)` vs `varchar(100)`, `int` vs `bigint`, default `0` vs `1`, default NULL vs el literal `'NULL'` y `auto_increment` vs vacío siguen dando distinto.
- **El desentrecomillado del default se aplica SÓLO del lado MariaDB.** En MySQL un default que de verdad valga `'foo'` con las comillas incluidas es un valor legítimo.
- **Lo que se muestra es lo que se comparó**: el side-by-side imprime los valores ya normalizados, no los crudos. Si el log dijera una cosa y la comparación usara otra, el operador no podría auditar la decisión.
- **Cuando los motores difieren, el log lo dice** en la cabecera. Que difieran es lo normal en este proyecto, no una anomalía — el aviso es para que nadie lea "idéntica" como "byte por byte igual".
- **Cuatro prefijos y ninguno más**: `[OK]` verde · `[WARN]` ámbar · `[FAIL]` rojo · `[SKIP]` gris. El frontend detecta con `\[(OK|SKIP|WARN|FAIL)\]` y colorea la línea entera. Las líneas narrativas (títulos, contadores, `[i/N] tabla…`) van sin prefijo, en gris.
- **El escape va ANTES de detectar el prefijo**: un nombre de tabla o columna con `<` no puede inyectar HTML en el `<pre>`.
- **El marcador `___END___` no se pinta ni se copia** — es protocolo, no log. Si el stream termina sin él, el status pinta *"Respuesta incompleta del servidor"* en rojo: eso atrapa al reverse proxy que corta el stream y al `exit` prematuro del backend.
- **Un chunk de red no es una línea.** El buffer acumula lo que entrega el `TextDecoder` y se corta por `\n`; nunca asumir que un `read()` trae líneas completas.
- **Cierre bloqueado durante la corrida** (botón, backdrop y ESC): hay dos PDO abiertos, uno contra el RDS de producción. **Sin `AbortController`** — abortar un `fetch` a mitad de un `information_schema` deja conexiones colgadas hasta el `set_time_limit`.
- **`Copiar log` se alimenta del array de líneas crudas**, no del texto del `<pre>` (que ya tiene los `<span>` de color encima). Sirve a mitad de corrida.
- **Sin persistencia**: no crea tablas, no cachea el resultado entre corridas, cada apertura del modal parte del placeholder.
- **Diagnostica y nada más.** No propone `ALTER TABLE`, no sincroniza esquemas, no genera migraciones. Un drift se corrige con un `.sql` explícito y versionado en el Migrador DB (§29).
- **CSS**: reusa la caja de `.terminal-log` (§38) con la variante `.terminal-log-diff` (§39), que cambia sólo lo que tiene que cambiar — neutro gris en vez del verde del sincronizador (en un diff casi todas las líneas son narrativas y pintarlas de verde dice "todo bien" sobre un log lleno de diferencias) y alto elástico hasta `52vh` en vez del alto fijo.

## 33-quater. Job: cotización del dólar

`cloud/jobs/dolar_actualizar.php`, que corre **todos los días a las 06:00** por el Programador de tareas (§33). Pide la cotización al microservicio de Databox (`GET https://api.databox.net.ar/v4/dolarhoy/cotizacion`, `Authorization: Bearer DATABOX_APIKEY` — la misma key del canal de correo) y escribe las dos filas de `parametros` que el sistema ya leía: `articulos.dolar.cotizacion` y `articulos.dolar.actualizado`. La tarea se da de alta con la migración `20260930_1100_tarea_dolar_actualizar.sql`.

**Reemplaza a un robot del legacy que dejó de correr** (`reactor-api/robot/articulosActualizar.php`). No es una estimación: al 30/09/2026 producción seguía en `1530.00` con fecha `2026-09-05`, veinticinco días vieja, mientras con ese número se valorizaban los artículos importados, el cotizador de planes de `www` y la cotización que se sella en cada comprobante.

- **SE GUARDA `venta`, NO `compra`, y está verificado contra el histórico.** Al 2026-05-19 el microservicio devuelve `compra 1370 / venta 1420` y la fila de `parametros` de esa misma fecha vale exactamente `1420.00`. Coincide además con la lectura del negocio: la columna valoriza artículos **importados** (`compra = importacion * cotizacion`, §15.2), o sea el precio al que se **compran** dólares, que es la punta `venta` del mercado. Elegir `compra` serían ~3 % menos en todos los precios en dólares.
- **ESTE JOB NO RECALCULA LOS ARTÍCULOS: lo hace el de las 07:00** (§33-quinquies). Son dos tareas y no una a propósito. Si Databox se cae, la cotización no se mueve, esta tarea queda en `error` y el recálculo corre igual una hora más tarde y sale `ok` con 0 filas tocadas — no hay nada que mover. Fusionadas, un fallo de red del microservicio dejaría sin correr el recálculo, que no depende de la red. Hasta el 30/09/2026 el recálculo en masa **no existía** y este bullet decía por qué; ver §33-quinquies para el cambio de decisión.
- **Se pide SIN `?fecha=`.** Con la fecha de hoy el endpoint contesta **404** los sábados, domingos y feriados (verificado: `?fecha=2026-09-05` → *"Cotizacion no encontrada"*), así que el job fallaría dos de cada siete corridas por algo que no es una falla. Sin fecha devuelve la última registrada, que es la del último día hábil.
- **Los dos parámetros se escriben en una transacción, o no se escribe ninguno.** Una cotización sin su fecha —o una fecha de hoy sobre el número de la semana pasada— es peor que no haber actualizado: las tres pantallas que la muestran dicen *"Cotización $X · actualizada el …"* y estarían afirmando algo falso.
- **`actualizado` lo estampa el `NOW()` de la base, nunca el reloj de PHP**, sobre la misma conexión que fija `SET time_zone = '-03:00'`. Guarda **cuándo se corrió**, que es la semántica que ya tenían las filas del legacy (`09:00:03`, `20:00:04` son horas de corrida, no fechas de cotización).
- **Se valida antes de tocar la base y se corta con excepción**: `venta` es nullable en el contrato del microservicio, y un `0` es el peor valor posible — `cotizacionDolar()` devuelve `0` cuando el parámetro no es numérico, y con eso **toda la tabla en dólares pasa a costar $ 0,00**. La banda `1..1000000` no es una regla de negocio: es el piso y el techo de lo físicamente posible.
- **Es un `SELECT` del id y después un `UPDATE`, no un upsert.** `parametros` es la tabla del legacy y `variable` **no tiene UNIQUE**, así que un `INSERT ... ON DUPLICATE KEY` insertaría una fila duplicada en vez de pisar la que existe — y `parametroValor()` se queda con la primera, que sería la vieja para siempre.
- **Deja rastro en el Visor de sucesos también cuando sale bien** (`cron/dolar_actualizar`), con el valor anterior, el nuevo y la fecha de la cotización. Sube a `alerta` —sin cortar: el número vino bien formado y el sistema está mejor con él que sin él— en los dos casos en que el dato puede ser correcto y aun así engañar a quien lo mira: **salto mayor al 10 %** contra el valor anterior, y **cotización devuelta de más de 7 días**. Lo segundo importa porque el endpoint sin `?fecha=` devuelve la última fila registrada: si Databox dejara de cargar cotizaciones seguiría contestando `200` con un número viejo y el job lo escribiría como si fuera de hoy.

### 33-quater.1. Dónde se sella esa cotización: al crear el comprobante

`comprobantes`.`cotizacion` es **la cotización del día en que se emitió el documento**, guardada al lado del total como referencia. No convierte nada — el comprobante factura `articulos`.`venta` tal cual.

**Los tres caminos que crean un comprobante la sellan en el `INSERT`**, con los tres helpers de `api/comprobantes_lib.php` (`cotizacionDelDia()`, `cotizacionAlCrear()`, `cotizacionAlDuplicar()`):

| camino | archivo | Prefactura / Factura | el resto de los tipos |
|---|---|---|---|
| Alta manual (*+ Nuevo comprobante*) | `api/comprobantes.php` | cotización del día | `0` |
| **Duplicar** | `api/comprobantes_accion.php` | cotización del día | se copia la del original |
| **Facturar un contrato** | `api/contratos_accion.php` | cotización del día | cotización del día |

- **Al crear y no después.** Tomarla al *autorizar* —o peor, al imprimir— la volvería la cotización de otro día, y la fila ya dice `emision`. Editar el comprobante no la vuelve a sellar: el campo queda a mano en el editor (§25-quinquies) y lo que se tipea ahí manda.
- **DUPLICAR NO LA HEREDA en Prefactura ni Factura.** El duplicado nace con emisión de **hoy**; copiarle la del original —que puede ser de hace meses— le dejaría al lado un número que no fue el de ninguno de sus dos días. Es el mismo criterio con el que ya no hereda ni el número ni el CAE: se copia **qué** se factura, no cuándo se facturó la vez pasada.
- **En los demás tipos Duplicar la copia tal cual, y no es una inconsistencia: es no borrar.** Mandarlos a `0` por no estar en `TIPOS_CON_COTIZACION` le sacaría al duplicado un dato que el original **tenía cargado** — los 190 Recibos con cotización son reales, todos emitidos facturando un contrato. Sellar donde no había nada y borrar donde sí había son dos cosas distintas.
- **Sale del parámetro, no de un GET a Databox.** El job de arriba lo deja fresco todos los días; pedírselo al microservicio en cada alta metería una llamada HTTP con 15 s de timeout dentro del camino de creación, y con Databox caído **no se podría crear un comprobante** — peor que crearlo con la cotización de ayer. Además es la misma fila que valorizó los artículos que se están facturando: con dos fuentes, el renglón diría un precio calculado con una cotización y la cabecera mostraría otra.
- **Sólo Prefactura (`F`) y Factura (`T`) en los dos caminos manuales.** Los otros cinco tipos de `talonarios`.`tipo` —`P` Presupuesto, `D` Pedido, `R` Recibo, `M` Remito, `N` Nota de Crédito— nacen en `0`: la cotización es la referencia del día en que se le puso precio a lo que se factura, y eso pasa al emitir el documento de la deuda. El tipo se resuelve **contra la base**, por el talonario, y no contra lo que mande el front — que en esos dos caminos no manda ninguno.
- **Facturar un contrato sella para cualquier tipo, y es a propósito.** Es lo que viene haciendo desde el legacy y lo que respaldan las 424 filas con cotización de la base: **234 Prefacturas y 190 Recibos, todas con `contrato`**. Hay clientes cuyo talonario de facturación es de Recibo; acotarlo ahora les sacaría la cotización a contratos vivos.
- **Sin el parámetro cargado va `0`**, que es lo que devolvía `cParametro::valorLeer()` del legacy y lo que ya tienen las 1.908 filas sin cotización. Un `0` se lee como *"no se selló"*; inventar un número, no.

## 33-quinquies. Job: recálculo de precios en dólares

`cloud/jobs/articulos_recalcular.php`, que corre **todos los días a las 07:00** por el Programador de tareas (§33), **una hora después** del job de la cotización (§33-quater). Recorre `articulos` con `moneda = 'D'` y reescribe `compra` y `venta` con la cotización vigente. La tarea se da de alta con la migración `20260930_1200_tarea_articulos_recalcular.sql`.

**REVIERTE UNA DECISIÓN QUE ESTUVO TOMADA AL REVÉS, y queda dicho porque el repo entero la documentaba.** Hasta el 30/09/2026 mover la cotización y repreciar eran dos cosas a propósito, y repreciar era **sólo** la acción de fila con confirmación (§41), con este argumento: cambia el abono de contratos vivos, o sea que es plata y alguien tiene que mirarlo. El argumento sigue siendo cierto —es la razón de todo lo que sigue— pero la decisión es la contraria: **el precio de un artículo importado se sigue del dólar todos los días, y dejarlo clavado hasta que alguien se acuerde de tocar la fila también es una decisión de plata, tomada por omisión.** Al 30/09/2026 convivían **cinco** cotizaciones implícitas en la tabla (1370, 1375, 1380, 1415, 1450) contra un parámetro en 1540: **70 de las 79 filas en dólares vendían a una cotización vieja**, entre 6 % y 12 % por debajo.

- **LA ACCIÓN DE FILA NO SE VA.** Sigue siendo la que se usa cuando hay que repreciar *ya* —recién cargada una fila, o movida la cotización a mano— y es la única que muestra los cuatro números antes de escribir. Este job es el piso diario, no su reemplazo. Lo que cambia en §41 es que la stat card `Precio viejo` pasa a ser un indicador de **hoy** (lo que se tocó después de las 07:00), no el estado de fondo de la tabla.
- **LA CUENTA ES LA MISMA FUNCIÓN, y eso obliga a una rareza que vale la pena.** `articuloPrecios()` vive en `api/articulos_lib.php`, que resuelve la conexión con `db()` — declarada en `api/bootstrap.php`, un archivo que manda headers HTTP y llama a `requireAuth()`, o sea que no se puede incluir desde CLI. El job **declara su propio `db()`** apuntando al PDO del bootstrap de jobs y después requiere la librería. Cuesta seis líneas y evita lo único que no se puede permitir: una segunda copia de la fórmula, que el día que alguien toque una sola de las dos haría que el precio que anuncia la pantalla de recalcular y el que escribe el job dejen de ser el mismo número.
- **TODAS LAS FILAS EN UNA SOLA TRANSACCIÓN**, con `SELECT … FOR UPDATE` sobre las filas en dólares. La lista de precios es una: si el proceso se muere a mitad, media tabla a 1540 y media a 1380 es un estado que **nadie eligió** — y `cContrato::facturar()` cobra `articulos`.`venta` tal cual, así que un contrato facturado en esa ventana saldría con el precio de la mitad que alcanzó a tocarse. Son ~80 filas: el lock dura milisegundos y son las 07:00. `moneda` no tiene índice, así que el `FOR UPDATE` bloquea el scan de las 106 filas; con la tabla diez veces más grande habría que indexar `moneda` antes que cambiar el lock.
- **La cotización se lee UNA VEZ y se valida ANTES de abrir la transacción.** `cotizacionDolar()` cachea el parámetro por proceso, así que las ~80 filas quedan repreciadas con **el mismo** número aunque alguien mueva el parámetro a mitad de corrida — una lista de precios con dos cotizaciones adentro es justamente lo que el job existe para deshacer. Fuera de la banda `1..1000000` corta con excepción y **no escribe nada**: `cotizacionDolar()` devuelve `0` cuando el parámetro falta o no es numérico, y con eso el job pondría la tabla entera en dólares a **$ 0,00**, incluido el abono de los contratos que facturan esos artículos. Es el mismo bloqueo que ya tiene la acción de fila, y acá pesa más porque no hay nadie mirando la pantalla.
- **Dos guardas por fila que la acción de fila no necesita, porque ahí hay alguien mirando:**
  - **Una fila que tenía precio no se manda a cero.** Pasa cuando `importacion` quedó en `NULL` o en `0` con `compra` cargada: la cuenta da `$ 0,00` y la fila pasaría a venderse gratis. La acción de fila escribe ese `0` porque antes se lo muestra a quien confirma; el job la saltea y la reporta. Al 30/09/2026 las 9 filas en dólares sin `importacion` **ya están en 0** —dos son planes Free, que valen 0 de verdad—, así que hoy esta guarda no desvía ninguna: existe para la fila futura a la que alguien le borre la importación.
  - **Lo que se pasa de `decimal(10,2)` se saltea y se reporta, no se recorta.** Con `STRICT_TRANS_TABLES` el `UPDATE` revienta y **se llevaría la transacción entera**, o sea las 79 filas sanas; sin modo estricto guardaría `99999999.99` en silencio, que es un precio inventado.
- **Las filas que no cambian no se escriben.** El umbral es el centavo porque `articuloPrecios()` ya redondeó a dos decimales: menos que eso es ruido de punto flotante, no un precio distinto. Así el `.log` habla sólo de los precios que de verdad se movieron, y una segunda corrida del mismo día es un no-op verificable (`0 repreciados | 79 sin cambios`).
- **Cuenta el abono afectado, después de escribir.** Los planes que facturan esos artículos cobran `articulos`.`venta` como abono, así que mover el precio les cambió el abono a todos los contratos que cuelgan de ellos. La acción de fila lo dice **antes** de confirmar; un job no tiene a quién decírselo antes, así que lo deja contado después —`29 planes / 40 contratos` en la primera corrida—, que es el número por el que alguien va a venir a preguntar.
- **El `.log` de cada ejecución lista fila por fila el antes, el después y el delta**, y la retención es de **30 días** y no de los 7 del default: `articulos` **no guarda historial de precios**, así que ese `.log` es el único lugar donde queda escrito que un artículo pasó de X a Y y cuándo.
- **ES EL CANARIO DE LA TAREA DE LA COTIZACIÓN.** Si la de las 06:00 falla, el parámetro se queda quieto y el recálculo de las 07:00 no tiene nada que mover: sale `ok` con 0 filas tocadas, **indistinguible de un día en que el dólar no se movió**. Por eso mira `articulos.dolar.actualizado` y sube a `alerta` cuando tiene más de **2 días** — dos corridas fallidas de la otra tarea.
- **Deja rastro en el Visor de sucesos también cuando sale bien** (`cron/articulos_recalcular`), con los cuatro contadores y la cotización aplicada. Sube a `alerta` —sin cortar: lo que se escribió se escribió bien— cuando alguna guarda salteó una fila o cuando la cotización está vieja. El detalle fila por fila **no** va al suceso (sólo los 5 primeros avisos y un `+N más en el log`): un job que saltea 70 filas no tiene que escribir un párrafo de 70 renglones en el Visor.

## 33-sexies. Job: recálculo de planes dinámicos

`cloud/jobs/contratos_plan_recalcular.php`, que corre **todos los días a las 08:00** por el Programador de tareas (§33), **una hora después** del recálculo de precios (§33-quinquies). Recorre `contratos` con `habilitado = 1` **y `plan_modo = 'dinamico'`**, cuenta los usuarios del dominio y le escribe el plan habilitado más chico de la misma familia que los cubra. La tarea se da de alta con la migración `20260930_1300_tarea_contratos_plan_recalcular.sql`.

**ESCRIBE UNA SOLA COLUMNA, `contratos`.`plan`.** No toca `facturado`, `facturar` ni `remitir` —el ciclo de facturación es de `api/contratos_accion.php?accion=facturar` y de nadie más—, no factura, y no escribe `dominios`.`usuarios` ni ninguna otra columna de `dominios`. Si el plan cambia, el comprobante del período siguiente sale con el abono nuevo porque `facturar` lee el plan en el momento de emitir (§15.2).

**ES LA PIEZA QUE LE DA EFECTO A `contratos`.`plan_modo`.** La columna existe desde la migración `20260929_1100` y hasta este job **no hacía nada**: se elegía en el ABM, se guardaba, se mostraba en el listado y en la ficha, y ningún camino del sistema la leía. **El día que se aplica no cambia nada**: las 50 filas están en `fijo` (el default del ENUM, elegido justamente para que un `ALTER` no le cambiara el comportamiento a ningún contrato vivo), así que la primera corrida sale `ok` con *0 contratos evaluados*. Y está verificado que tampoco movería ninguno si se pasaran los 26 contratos habilitados a `dinamico`: **los 26 ya están parados exactamente en el plan que les corresponde**.

**Reemplaza a `reactor-api/robot/contratosActualizar.php`** (crontab del legacy, 05:45), que es un `for` sobre `cContrato::actualizar()` → `cPlan::detectar($dominio->usuarios)`. Cinco diferencias, ninguna de estilo — cada una tapa un camino por el que el robot viejo movía plata sin que nadie lo decidiera:

- **NO HAY FALLBACK AL PLAN FREE.** `cPlan::detectar()` cierra con `if ($plan == 0) $plan = 100; // plan free`: al dominio que **no entra en ningún plan** —porque creció por encima del cupo más grande— le asigna el plan gratuito. Hoy el techo Standard es 200 usuarios; el día que un dominio llegue a 201, ese `if` lo pasa de pagar el abono más caro a no pagar nada, de madrugada y sin dejar rastro. Acá se saltea y se reporta **como aviso**, porque es un contrato que está facturando menos de lo que le toca. Es la misma decisión que ya tomó `api/contratos_accion.php` al portar `cContrato::facturar()`, que traía el mismo `if`.
- **LOS CANDIDATOS SE ACOTAN AL `tipo` DEL PLAN QUE EL CONTRATO YA TIENE.** `detectar()` consulta `where (N<=usuarios) and (habilitado='1') order by usuarios limit 1` **sin mirar `planes`.`tipo`**, y con el catálogo de hoy eso reparte planes de otra familia: los **ocho** planes Developer habilitados tienen `usuarios = 10`, igual que el Standard Free. Para un dominio de 10 usuarios o menos el `limit 1` empata nueve filas y el ganador lo decide el motor — un dominio Standard puede terminar facturando *Plan Developer de 50001 a 100000 peticiones*. Con `tipo = 'S'` los cupos son 10, 20, 30, 40, 50, 100, 150 y 200: todos distintos, así que el mínimo es uno solo.
- **UN EMPATE EN EL CUPO MÍNIMO NO SE DESEMPATA: SE SALTEA.** El ancla por `tipo` alcanza para Standard, pero no es una propiedad del esquema —nada impide cargar mañana dos planes Standard con el mismo cupo— y para Developer el empate **es el estado actual**. Elegir por `id` o por `orden` sería inventar un criterio comercial dentro de un job. Consecuencia buscada: **un contrato Developer en modo dinámico no se mueve y lo dice**, que es correcto porque Developer se tarifa por `usos`.
- **LOS USUARIOS SE CUENTAN, NO SE LEEN DE `dominios`.`usuarios`.** `detectar()` recibe esa columna *cache*, que este repo ya declaró no confiable: `api/dominios.php`, `panel/api/dominio.php` y `panel/api/dashboard.php` calculan los cinco contadores en vez de leerlos, porque el cache está desfasado en **23 de los 148** dominios (el alta suma y la baja no resta). Tarifar sobre esa columna ataría el abono a que siga corriendo `dominiosActualizar.php` del legacy —el andamio que este repo está desarmando—, y cuando un robot del legacy se muere lo hace en silencio: `articulosActualizar.php` dejó la cotización clavada veinticinco días y nadie se enteró. **Se usa `COUNT(DISTINCT p.usuario)`, la misma expresión que la pantalla**: no es lo mismo que el `COUNT(id)` del cache, porque una persona puede tener más de un perfil en el mismo dominio (hoy hay 8 pares repetidos; el dominio 2 declara 18 y tiene 13 personas). Si el job tarifara por perfiles mientras la pantalla muestra personas, **la misma palabra diría dos números y el abono saldría del que nadie ve**.
- **EL PLAN DESTINO TIENE QUE PODER FACTURARSE.** Un plan sin `articulo` bloquea el facturar del contrato (*"El plan no tiene articulo: no hay precio que facturar"*). Hoy los 22 planes habilitados tienen artículo, así que esta guarda no desvía ninguno: existe para que un plan a medio cargar no deje un contrato sin poder emitir.

**PENDIENTE DE INFRAESTRUCTURA, y no lo resuelve este job: hay que comentar la línea del robot viejo.** `reactor-api/robot/contratosActualizar.php` está **sin comentar** en el crontab del legacy (`reactor-api/cron/jobs`, 05:45), un renglón después de `dominiosActualizar` — que está demostrablemente vivo: los 53 dominios habilitados tienen los cuatro contadores frescos mientras 17 de los 95 deshabilitados están desfasados, que es exactamente el `WHERE habilitado = 1` de ese robot. **Mientras los dos corran, el viejo manda sobre los contratos `fijo`**: reasigna el plan de *todos* los habilitados porque `plan_modo` es de este repo y el legacy no la conoce. El job de las 08:00 corre después y arregla los `dinamico`, pero un contrato que alguien puso en `fijo` seguiría moviéndose a las 05:45. **`fijo` no significa nada hasta que esa línea se comente.**

**Reglas propias:**
- **UN PLAN CON CUPO `-1` (ILIMITADO) NUNCA ES CANDIDATO, por más que la semántica de la columna diga lo contrario.** `p.usuarios >= N` lo deja afuera, igual que el `N <= usuarios` de `detectar()`. Admitirlo sería peor: `-1` es el mínimo de `ORDER BY usuarios`, así que un plan ilimitado **ganaría siempre**, sobre cualquier cupo acotado y para cualquier dominio. Los seis planes Telemetry habilitados tienen `usuarios = -1` y es correcto que ninguno salga de ahí: Telemetry se tarifa por `usos`. Un `usuarios` en `NULL` queda afuera por la misma comparación.
- **EL PLAN ACTUAL TIENE QUE ESTAR HABILITADO**, y es la condición que pone el límite del job. Los 11 planes deshabilitados son los viejos (Edificio / Ciudad / Casa Inteligente, Anónimo, Reactor Ilimitado) y tienen contratos colgados **a propósito**: el plan se retiró de la venta y esos contratos se quedaron con su precio. Meterlos en la escalera Standard les cambiaría el abono por una decisión que nadie tomó. Los once tienen además `tipo` vacío, así que no habría familia a la que acotar los candidatos.
- **TODO EN UNA SOLA TRANSACCIÓN, con los contratos bloqueados** (`SELECT … FOR UPDATE`). El lock es lo que serializa esto contra `accion=facturar`, que abre con `FOR UPDATE` sobre el contrato y después arma los renglones leyendo el plan: sin él, una facturación que arrancó con el plan viejo podría emitir el comprobante con el abono viejo **después** de que la corrida lo cambió, y el renglón ya quedó escrito.
- **El `SELECT … FOR UPDATE` va sin joins.** En MySQL un `FOR UPDATE` sobre un `LEFT JOIN` bloquea también las filas de `planes` y `articulos`, que este job sólo lee. El contexto se trae después, sin lock. `plan_modo` y `habilitado` no tienen índice, así que el lock abarca el scan de las 50 filas; a esta escala y a las 08:00 no es un problema.
- **No filtra por `dominios`.`habilitado`.** Lo que se factura es el contrato, y hay contratos habilitados con el dominio apagado (el 156). El flag del dominio nunca gateó nada acá — el legacy tampoco lo miraba.
- **Lo que no cambia no se escribe**, igual que §33-quinquies: así el `.log` habla sólo de los planes que de verdad se movieron y una segunda corrida del mismo día es un no-op verificable.
- **El `.log` informa el abono antes y después, contrato por contrato, y la suma al pie.** Es plata: la línea `Abono mensual: $ X -> $ Y (+$ Z)` es el número por el que alguien va a venir a preguntar. **Sólo se suman los contratos con los dos abonos cargados**: con un `NULL` de un lado el delta no es cero, es desconocido, y sumarlo como cero daría un total que parece exacto y no lo es.
- **El `.log` anota los dos conteos que NO deciden nada** cuando difieren del que sí: `dominios.usuarios` (el cache del legacy — es donde se ve si ese robot todavía respira) y el conteo de usuarios habilitados (la pregunta que sigue a *"cuántos usuarios tiene este dominio"*). Ninguno de los dos entra en la cuenta del cupo.
- **Retención de 30 días** y no los 7 del default, por el mismo motivo que §33-quinquies: `contratos` **no guarda historial de planes**, así que ese `.log` es el único lugar donde queda escrito que un contrato pasó del plan X al plan Y, con cuántos usuarios y con qué cambio de abono.
- **Deja rastro en el Visor de sucesos también cuando sale bien** (`cron/contratos_plan_recalcular`), con los tres contadores y el delta de abono. Sube a `alerta` —sin cortar: lo que se escribió se escribió bien— cuando salteó algún contrato, porque cada salteo es un contrato que **no** quedó en el plan que le corresponde.

## 33-septies. Job: recálculo de situación de contratos (mora)

`cloud/jobs/contratos_situacion_recalcular.php`, que corre **todos los días a las 04:00** por el Programador de tareas (§33). Recorre `contratos` con `habilitado = 1`, cuenta sus facturas/prefacturas **pendientes**, toma el **vencimiento de la más antigua** y de los días de atraso sale `contratos`.`situacion`. La columna la crea la migración `20261001_1000_contratos_situacion.sql` y la tarea se da de alta con `20261001_1100_tarea_contratos_situacion_recalcular.sql`.

| atraso de la pendiente más antigua | `situacion` |
|---|---|
| sin pendientes, o todavía sin vencer, o menos de 15 días | `'1'` Normal |
| 15 a 29 días | `'2'` Limitado |
| 30 días o más | `'3'` Suspendido |

**ESCRIBE DOS COLUMNAS, Y LAS DOS EN LA MISMA TRANSACCIÓN**: `contratos`.`situacion` (una por contrato habilitado) y **`dominios`.`situacion`, que es la que corta el servicio** — `app/index.php` no dibuja ningún control de operación con `'3'`, y con `'2'` agrega el aviso *"su cuenta pronto será suspendida"* dejando los controles. La de `contratos` no la lee nadie: es el detalle por contrato y el rastro de por qué el dominio quedó como quedó. No factura, no anula, no cancela: no toca una sola columna de `comprobantes` —el ciclo de facturación es de `api/contratos_accion.php` y de nadie más—.

**HASTA EL 01/10/2026 NO TOCABA `dominios` A PROPÓSITO, y la decisión se revirtió por pedido explícito.** El argumento de entonces —suspender clientes es una decisión de negocio, no de un recálculo— sigue siendo la razón de las guardas: el `.log` nombra uno por uno los dominios que se quedan sin servicio **y con qué valor venían** (revertir a mano es leer ese renglón), el suceso sube a `alerta` cuando pasa, y la escritura es condicional. Pero escribir una sola columna dejaba el contrato en Suspendido y el dominio operando: dos afirmaciones distintas sobre el mismo cliente, y una pantalla donde la `Situación` del listado no se movía nunca.

**NO ENTRA EN LA CADENA DE LAS 06:00 / 07:00 / 08:00, y las 04:00 son por eso.** Las otras tres tareas diarias son una cadena cuyo orden es parte del diseño (cotización → precios → planes, §33-quater a §33-sexies) porque cada una lee lo que la anterior escribió. Ésta no se engancha ni arriba ni abajo: lee `comprobantes`.`estado` y `.vencimiento`, dos columnas que ninguna de las tres escribe, y escribe una que ninguna de las tres lee. Corriendo a las 04:00 o a las 09:00 el resultado es idéntico, así que se elige la hora más vacía del día. **Lo que sí importa es que sea diaria**: la situación envejece sola —un comprobante cruza el día 15 o el 30 sin que nadie toque la base— así que no hay evento del sistema al que colgarla. Emitir un comprobante nuevo no vence nada.

**LA PRIMERA CORRIDA ESCRIBE LAS 26 FILAS, y no le corta el servicio a nadie.** A diferencia de §33-sexies —que el primer día sale `ok` con 0 contratos evaluados porque las 50 filas están en `plan_modo = 'fijo'`— acá la columna nace en `NULL` ("todavía no se calculó") y pasar de `NULL` a un código es un cambio real. Verificado en desarrollo al 01/10/2026: **18 Normal · 0 Limitado · 8 Suspendido**, con los ocho vencidos hace 146, 146, 146, 146, 162, 207, 235 y 417 días. La banda de Limitado **sale vacía**: hoy no hay ningún contrato con un atraso de entre 15 y 29 días.

- **SI UN DOMINIO TIENE VARIOS CONTRATOS HABILITADOS, GANA LA PEOR SITUACIÓN** (`peorSituacion()`). Hoy ninguno tiene dos, pero nada del esquema lo impide: con un contrato impago el cliente **no** está al día por más que el otro sí, y quedarse con la mejor dejaría operando a quien debe plata con sólo abrirle un contrato nuevo al lado.
- **NO ES UN TRINQUETE: si la deuda se paga, el dominio vuelve solo.** El job recalcula desde cero todos los días, así que un dominio en `'3'` pasa a `'1'` en la corrida siguiente a que se cancelen sus pendientes — verificado en los dos sentidos. Nadie tiene que destrabarlo a mano.
- **Sólo toca dominios con al menos un contrato habilitado** (en desarrollo, 26 de 148). Un dominio sin contrato vivo no tiene mora que mirar y pisarle la `situacion` sería opinar sobre un cliente del que este job no sabe nada: su valor queda como esté, puesto a mano o por el back office viejo.
- **El orden de los locks es `contratos` y después `dominios`, siempre por `id` ascendente.** Dos corridas encimadas —que el `overlap = skip` de la tarea ya evita— tomarían los locks en el mismo orden y se esperarían en vez de abrazarse.
- **Es la columna gemela de `dominios`.`situacion` y por eso tiene su misma forma**: `varchar(1)` nullable, los mismos tres códigos y el mismo catálogo de textos (`combos` con la clave `'$xDominio->situacion'`, 1 Normal / 2 Limitado / 3 Suspendido). **No hay `'$xContrato->situacion'` en `combos`**: el sistema histórico no tiene esta noción a nivel contrato, así que la columna es nueva de verdad y no el rescate de algo que el legacy ya escribía.
- **NACE EN `NULL` Y NO EN `'1'`.** Sembrar `'1'` sería que un `ALTER` **afirme** que los 50 contratos están al día, y es falso: ocho de los 26 habilitados están vencidos hace 146 días o más. `NULL` significa "todavía no se calculó", igual que el `NULL` de `perfiles.registrante` significa "no se sabe".
- **QUÉ CUENTA COMO DEUDA: `comprobantes.estado = '2'` (Pendiente) y `talonarios.tipo IN ('F','T')`** (Prefactura y Factura). Preparación queda afuera a propósito —ese comprobante no se autorizó, tiene `serie = 0`, o sea que no tiene número y nadie puede reclamarlo—; Anulado y Cancelado tampoco, que uno no existe y el otro ya se cobró. El filtro por tipo no es cosmético: de los 209 comprobantes pendientes de la base, **72 son Presupuestos y 16 Remitos**, documentos que no se cobran.
- **EL CASO INCÓMODO ES RECIBO (`R`), Y QUEDA AFUERA A SABIENDAS.** §33-quater.1 dice que facturar un contrato sella cotización *"para cualquier tipo"* porque **"hay clientes cuyo talonario de facturación es de Recibo"**, y lo respaldan 959 Recibos con `contrato` ya cancelados: para esos clientes el documento de la deuda **es** un Recibo y este job no lo está mirando. Está verificado que hoy no cambia ni un resultado —el único Recibo pendiente con contrato es el 5652 del contrato 143, que ya cae en `'3'` por sus prefacturas (162 días) antes de mirarlo; con el Recibo serían 1.262—. Si algún día se decide contarlos, el cambio es agregar `'R'` a la constante `TIPOS_DEUDA`: por eso es una constante y no una lista escrita adentro del SQL.
- **LOS BORDES DE LAS BANDAS: CADA UNA SE QUEDA CON SU LÍMITE INFERIOR.** El enunciado da las bandas solapadas ("0 a 15", "15 a 30", "30 o más"), así que los días 15 y 30 están en dos a la vez y hay que elegir. Se resuelve por el único borde que el enunciado sí define solo —*"30 días o más"* es Suspendido, o sea que el 30 es de la banda de arriba— y por simetría el 15 también: **día 14 → `'1'`, día 15 → `'2'`, día 29 → `'2'`, día 30 → `'3'`** (los cuatro verificados contra la base). Son dos `>=` en orden descendente y no un `switch` de rangos, así no hay forma de escribir un hueco entre dos bandas.
- **UN VENCIMIENTO FUTURO DA ATRASO NEGATIVO Y ESO ES NORMAL, no un error**: el comprobante está emitido y pendiente pero todavía no venció (hoy, el contrato 154, que vence seis días adelante).
- **EL ATRASO LO CUENTA LA BASE** (`DATEDIFF(CURDATE(), MIN(vencimiento))`), nunca el reloj de PHP, sobre la conexión que fija `SET time_zone = '-03:00'`. El contenedor corre en UTC y a las 04:00 de Argentina allá son las 07:00 del mismo día; el criterio es el de siempre porque son dos relojes que pueden separarse y de acá sale si un cliente queda suspendido.
- **LO QUE NO SE PUEDE FECHAR NO ENTRA EN EL `MIN()`, y se avisa.** Dos valores se excluyen en el `WHERE` y no después: el `NULL` —que `MIN()` ya ignora, pero entonces un contrato con todos sus pendientes sin fecha parecería no tener deuda— y **el centinela `'1500-01-01'`** (`cTiempo::genesis()`), que es el que de verdad muerde: sin excluirlo `MIN()` se queda con él y `DATEDIFF` da ~190.000 días, o sea **Suspendido para cualquier contrato que tenga uno**. Hoy no hay ninguno entre los pendientes F/T (verificado, 0 filas), así que la guarda no desvía nada: existe para el día que alguien guarde un comprobante sin vencimiento. El aviso lo dice con todas las letras — *"el contrato queda Normal por falta de fecha, no por falta de deuda"*.
- **NO LEE `contratos`.`tolerancia`**, la fecha de gracia que se carga a mano en el ABM y la única columna del contrato que podría pisar este cálculo. Queda afuera porque el enunciado no la menciona y porque **nada en este repo la lee todavía**: engancharla sería inventar una regla de perdón que nadie pidió, y perdonar mora es plata. Los 26 contratos habilitados tienen una cargada (14 con el centinela `'1500-01-01'`), así que si algún día entra al cálculo hay que decidir primero qué significa ese centinela.
- **NO filtra por `dominios`.`habilitado`**, igual que §33-sexies: lo que se factura es el contrato, y hay contratos habilitados con el dominio apagado (el 156).
- **NO toca los contratos deshabilitados** (24 de las 50 filas). Un contrato dado de baja no tiene mora que administrar, y su `situacion` se queda con el último valor que tuvo —o en `NULL` si nunca se calculó—, que es el dato histórico y no una afirmación sobre hoy.
- **TODO EN UNA SOLA TRANSACCIÓN, con los contratos bloqueados** (`SELECT … FOR UPDATE`, sin joins para no bloquear las tablas que sólo se leen). Serializa contra `accion=facturar`, que abre con `FOR UPDATE` sobre el contrato. `habilitado` no tiene índice, así que el lock abarca el scan de las 50 filas; a esta escala y a las 04:00 no es un problema. La consulta de deuda por contrato cae sobre el índice `fk_comprobantes_contrato`.
- **Lo que no cambia no se escribe**, igual que §33-quinquies y §33-sexies: el `.log` habla sólo de las situaciones que de verdad se movieron y una segunda corrida del mismo día es un no-op verificable (*0 situaciones cambiadas | 26 sin cambios*). **El `NULL` inicial sí cuenta como cambio**: pasar de "no se sabe" a `'1'` es escribir un dato que antes no estaba.
- **El `.log` lista al pie los que entran a Suspendido, uno por uno**, con el atraso y de dónde venían. Es el movimiento por el que alguien va a venir a preguntar: son los contratos que dejaron de estar al día, no una estadística. Y explica por qué un contrato con comprobantes pendientes sale Normal — anota aparte los pendientes de los otros cinco tipos de talonario, que no son deuda.
- **Retención de 30 días** y no los 7 del default, por el mismo motivo que las otras dos tareas que escriben plata: `contratos` **no guarda historial de situaciones**, así que ese `.log` es el único lugar donde queda escrito que un contrato pasó a Suspendido, con cuántos días de atraso y sobre qué comprobante.
- **Deja rastro en el Visor de sucesos también cuando sale bien** (`cron/contratos_situacion_recalcular`), con los contadores y el reparto. Sube a `alerta` —sin cortar: lo que se escribió se escribió bien— cuando algún pendiente no se pudo fechar, porque eso es deuda que el cálculo no vio.

**PENDIENTE, y no lo resuelve este job: la columna todavía no se ve ni se usa.** `api/contratos.php` enumera sus columnas una por una en el `SELECT`, el `INSERT` y el `UPDATE`, así que `situacion` **no se filtra sola** al JSON del ABM: no sale en el listado, ni en la ficha, ni en los filtros. Es la misma situación en la que estuvo `plan_modo` entre su migración y su tarea. Mostrarla es una decisión de UI aparte —y si se muestra, va **traducida** con el badge de `'$xDominio->situacion'` que ya usan Dominios en cloud y el panel (ABM.md), nunca el número pelado.

## 34. Selector de ids (Roles y Controladores)

Control de modal de Alta/Edición para elegir un subconjunto de un catálogo de decenas de opciones. Lo usan dos campos, en dos módulos, y los dos guardan el dato en una **tabla puente con FK**:

| módulo | campo | tabla puente | catálogo |
|---|---|---|---|
| Roles | permisos | `roles_permisos` | `permisos` (113) |
| Controladores | roles | `controladores_roles` | `roles` (10) |
| Perfiles | paneles | `perfiles_paneles` | `paneles` del dominio del perfil |

```html
<div class="form-group">
  <label for="rol-permisos-search">Permisos</label>
  <div class="id-picker" id="rol-permisos">
    <div class="id-picker-toolbar">
      <input type="search" id="rol-permisos-search" placeholder="Buscar permiso…">
      <span class="id-picker-count" data-role="count">12 de 113</span>
      <button type="button" class="btn btn-sm btn-ghost" data-act="ninguno">Limpiar</button>
    </div>
    <div class="id-picker-list">
      <div class="id-picker-group">
        <span>Permisos</span>
        <span class="id-picker-group-actions">
          <button type="button" class="btn btn-sm btn-ghost" data-act="grupo-todos">Todos</button>
          <button type="button" class="btn btn-sm btn-ghost" data-act="grupo-ninguno">Ninguno</button>
        </span>
      </div>
      <label class="id-picker-item" data-busca="usuarios > consultar 1005">
        <input type="checkbox" value="1005" checked>
        <span class="id-picker-item-text">Usuarios &gt; Consultar <code>#1005</code></span>
      </label>
    </div>
  </div>
</div>
```

```css
.id-picker         { border: 1px solid var(--border); border-radius: var(--radius);
                     background: var(--bg); overflow: hidden; }
.id-picker-toolbar { display: flex; gap: 8px; align-items: center;
                     padding: 8px 10px; border-bottom: 1px solid var(--border); }
.id-picker-list    { max-height: 240px; overflow-y: auto; padding: 4px 0; }
.id-picker-group   { display: flex; align-items: center; gap: 8px; padding: 8px 12px 4px;
                     font-size: .72rem; font-weight: 700; text-transform: uppercase;
                     letter-spacing: .04em; color: var(--muted); }
.id-picker-item    { display: flex; gap: 9px; align-items: flex-start;
                     padding: 5px 12px; font-size: .85rem; cursor: pointer; }
.id-picker-item:hover { background: var(--row-hover); }
.id-picker .id-picker-item input { width: auto; flex: 0 0 auto; margin-top: 3px; }
.id-picker-item-text  { flex: 1 1 auto; min-width: 0; word-break: break-word; }
```

**Reglas:**

- **Es una lista plana, sin grupos.** Los tuvo hasta el 06/09/2026, cuando agrupaba por `sistema` — la columna que repartía roles y permisos entre los tres productos del sistema histórico (`A` Reactor Admin, `C` Reactor Control, `P` Reactor App). Ese concepto se eliminó: los permisos son de cloud y de nadie más, así que no queda ningún eje por el cual agrupar. La cabecera que quedó es una sola y existe nada más que para alojar `Todos` / `Ninguno`.
- **Tampoco hay grupo `Sin catálogo`, y ya no puede haberlo.** Mientras los permisos de un rol vivían en un varchar sin integridad referencial (`roles`.`permisos`, con el formato `(1001)(1004)`), un rol podía referenciar un permiso inexistente y el selector tenía que mostrarlos, admitirlos y conservarlos para no borrarlos de oficio. Las dos listas viven hoy en tablas puente con FK: un id que no existe no entra, y el backend lo rechaza con 422.
- **La lista lleva `max-height` propio.** El `.modal-body` ya tiene su scroll; sin la cota el modal crecería 113 filas y la barra de acciones (§21-bis) quedaría a un scroll de distancia.
- **El checkbox lleva `width: auto` explícito y el selector va con dos clases.** El control vive dentro de un `.form-group`, y `.form-group input` fuerza `width: 100%` (§7): sobre un checkbox eso lo estira a lo ancho de la fila y deja el texto exprimido a una letra por renglón contra el borde derecho, más una barra de scroll horizontal. La regla usa `.id-picker .id-picker-item input` para ganar por especificidad (0,2,1 contra 0,1,1) y no por orden de aparición en la hoja.
- **El buscador esconde ítems y `Todos` / `Ninguno` operan sólo sobre lo visible.** Con un filtro activo, tildar lo que no se ve es una sorpresa.
- **El contador (`N de M`) es del selector entero**, no del filtro: es lo que se va a guardar.
- **`extraKey` elige la línea secundaria del ítem**, que se pinta como texto tenue. Hoy la usa sólo el selector de roles, para marcar los que están deshabilitados — se ofrecen igual, porque un rol apagado que alguien ya tiene asignado tiene que poder verse y quitarse; esconderlo lo volvería una asignación invisible.
- **El selector de Perfiles se acota al dominio y se rearma al cambiarlo.** Los paneles de un dominio no son los de otro, así que en el alta el control se re-renderiza entero cada vez que cambia el campo Dominio (que es un combo con buscador, §34-bis), descartando la selección previa (que el backend rechazaría con 422). Mientras no haya dominio elegido muestra *"Elegí primero un dominio"* en vez de una caja vacía — y **limpiar el campo vuelve a ese estado**, porque el combo avisa el cambio también cuando queda vacío.
- **La siembra de `20260906_1700` dejó a los 2225 perfiles existentes con todos los paneles de su dominio tildados explícitamente** (3598 filas). Por eso el alta pre-tilda todos los del dominio elegido, y por eso `panel/invitacion/aceptar.php` los inserta al crear el perfil: un perfil que naciera sin filas no podría usar la app.
- **Ahí, no tildar nada significa "ningún panel".** El permiso es explícito y no hay fallback a "todos" — lo hubo mientras la tabla estaba vacía, y la siembra lo volvió innecesario. El selector lleva una línea debajo que lo dice, y tanto la celda del listado como la tarjeta de la ficha marcan ese estado en `badge-warn`, porque casi siempre es un error de carga y no una decisión.
- **El guardado sincroniza por diferencia, no borra y reinserta.** `asignado` es la fecha en que se dio esa asignación, y reescribir la fila entera en cada guardado la volvería la fecha del último `Guardar`. Vale para los dos módulos (`sincronizarPermisos()` en `api/roles.php`, `sincronizarRoles()` en `api/controladores.php`).

---


## 34-bis. Combo con buscador (autocompletar)

Campo de texto que **filtra un catálogo mientras se escribe** y guarda el id de lo elegido. Reemplaza al `<select>` cuando el catálogo es largo y la opción **no se reconoce por su nombre**.

| módulo | campo | catálogo | se busca por |
|---|---|---|---|
| Perfiles (Alta) | Usuario | `usuarios` (~2.000) | nombre · correo · celular |
| Perfiles (Alta) | Dominio | `dominios` (~700) | nombre · número · uuid |
| Comprobantes (Editar) | Cliente | `clientes` (64) | nombre · razón social · CUIT · correo |

```html
<div class="form-group">
  <label for="prf-usuario-q">Usuario</label>
  <div class="combo" data-combo="prf-usuario">
    <input type="text" class="combo-input" id="prf-usuario-q"
           role="combobox" aria-expanded="false" aria-autocomplete="list"
           autocomplete="off" autocapitalize="none" spellcheck="false"
           placeholder="Nombre, correo o celular — ej.: mari 264">
    <input type="hidden" id="prf-usuario" value="">
    <button type="button" class="combo-clear" data-act="combo-clear" aria-label="Limpiar" hidden>×</button>
  </div>
  <div class="field-error" id="prf-usuario-err" style="display:none"></div>
</div>

<!-- Colgado del <body>, no del modal -->
<div class="combo-pop" role="listbox" style="position:fixed; left:…; top:…; width:…">
  <button type="button" class="combo-item is-active" data-idx="0" role="option" aria-selected="true">
    <span class="combo-item-titulo"><mark class="combo-hit">Mari</mark>ana Gómez</span>
    <span class="combo-item-detalle">mariana@ejemplo.com · <mark class="combo-hit">264</mark>4123456</span>
  </button>
  <div class="combo-pie">y 37 más — seguí escribiendo para acotar</div>
</div>
```

```css
.combo              { position: relative; display: block; }
.combo .combo-input { width: 100%; padding-right: 34px; }
.combo-clear        { position: absolute; right: 6px; top: 50%; transform: translateY(-50%);
                      background: none; border: none; cursor: pointer; color: var(--muted); }
.combo-pop          { position: fixed; z-index: 200; background: var(--surface);
                      border: 1px solid var(--border); border-radius: var(--radius);
                      box-shadow: var(--shadow-lg); max-height: 260px; overflow-y: auto; padding: 4px 0; }
.combo-item         { display: flex; flex-direction: column; gap: 2px; width: 100%;
                      text-align: left; padding: 7px 12px; background: none; border: none;
                      cursor: pointer; font: inherit; color: var(--text); }
.combo-item:hover,
.combo-item.is-active { background: var(--row-hover); }
.combo-item-titulo    { font-size: .88rem; }
.combo-item-detalle   { font-size: .76rem; color: var(--muted); }
.combo-hit            { background: rgba(193,19,19,.45); color: #fff; border-radius: 3px; }
.combo-vacio,
.combo-pie            { padding: 10px 12px; font-size: .8rem; color: var(--muted); text-align: center; }
.combo-pie            { border-top: 1px solid var(--border); margin-top: 4px; }
```

**Reglas:**

- **No reemplaza al `<select>` en general.** Un catálogo corto y cerrado —`Tipo`, `Estado`, `Sentido`— se lee mejor desplegado: ahí un campo de texto obliga a escribir lo que se podía ver. El combo entra cuando el catálogo es largo **y** el nombre no alcanza para identificar la opción. Los ~2.000 usuarios de Perfiles cumplen las dos: hay homónimos, y un `<option>` sólo deja ver el nombre.
- **Contra un campo de id, en cambio, entra siempre.** El `Cliente` de Comprobantes (§25-quinquies) son 64 filas —catálogo corto— y el combo igual es la salida: lo que reemplaza no es un `<select>` sino un `<input type="number">` donde había que tipear el id de memoria. La comparación no es "desplegado contra escrito", es "el nombre contra un número que no se sabe nadie".
- **LOS TÉRMINOS SE CRUZAN CON `Y`; CADA UNO SE BUSCA EN TODOS LOS CAMPOS CON `O`.** Escribir `mari 264` pide las Marías/Marianos que **además** tengan `264` en alguno de sus campos (típicamente el celular), no la unión de las dos búsquedas. Es lo que hace que el control sirva para desambiguar entre homónimos, que es para lo que está. Con la `O` al revés el segundo término **agranda** el resultado en vez de acotarlo, y el buscador empeora justo cuando más se lo necesita.
- **Las claves son las del buscador rápido del módulo dueño del catálogo**, no una lista nueva: Usuarios busca por correo + nombre + celular (§9) y Dominios por nombre + número + uuid. Si el combo buscara por otros campos, el mismo texto daría resultados distintos según la pantalla.
- **El teléfono se compara también dígito contra dígito.** En la base un celular son diez dígitos y nada más (`CLAUDE.md`), pero quien busca lo escribe como lo tiene anotado: `264-412`, `(264) 412`. Si el término tiene dígitos, se lo compara además contra los dígitos de cada clave — **por clave y no concatenados**, que pegando `…12` con `34…` aparecería un `1234` que no está en ningún campo.
- **Se resalta lo que coincidió y el detalle muestra las otras claves.** Buscando en tres campos a la vez, una fila que entró por el celular es indistinguible de una que entró por el nombre: sin el segundo renglón y sin las marcas, el resultado no se puede confirmar. El resaltado **escapa por segmento**, nunca la cadena entera antes de buscar — `&` se vuelve `&amp;` y los índices dejan de apuntar al mismo lugar.
- **La normalización va caracter por caracter** y sólo reemplaza cuando el resultado sigue midiendo uno. Así `maria` encuentra a `María` **y** el índice de la coincidencia sigue siendo el mismo en la cadena original, que es lo que necesita el resaltado. Un `.normalize('NFD')` sobre la cadena entera separa el acento en un caracter aparte y corre todo lo que viene después.
- **Filtra en el navegador, no contra el backend.** Los dos catálogos ya viajan enteros con el listado del módulo, así que un endpoint de búsqueda agregaría un request por tecla para datos que están en memoria. El índice normalizado se arma **una sola vez** al cablear el control: rehacerlo en cada tecla son 6.000 normalizaciones por pulsación.
- **El desplegable cuelga del `<body>` y se posiciona en coordenadas de viewport**, igual que el menú contextual de las filas (§24): dentro de un modal lo recortaría el `overflow-y` del `.modal-body`. Pero **se reposiciona al scrollear en vez de cerrarse** como hace aquél — acá el foco está en el campo y cerrar la lista mientras se escribe deja al control mudo. Si no entra abajo y sí arriba, se da vuelta.
- **Cierra por click afuera, no por `blur`.** El `blur` cae también al arrastrar la barra de scroll de la lista, y ahí cerrarla es lo contrario de lo que se pidió.
- **Escribir invalida lo elegido.** El id viejo conviviendo con un texto nuevo es exactamente el estado que hace guardar algo distinto de lo que se lee en pantalla. Y **salir sin elegir no deja lo tipeado escrito**: un texto sin id abajo se lee como una selección hecha, y el `Guardar` cortaría con *"Elegí un usuario de la lista"* sobre un campo que parece lleno.
- **Se listan hasta 50 filas y el resto se dice.** *"y N más — seguí escribiendo para acotar"*: 50 de 2.000 recortadas en silencio se leen como *"no hay más"* y nadie sigue escribiendo.
- **El input oculto lleva el id del campo** (`prf-usuario`) y el visible el sufijo `-q`. Así el resto del formulario lo lee igual que leía al `<select>` que reemplaza, y el `#…-err` de la validación no cambia. El `<label>` apunta al **visible**, que es donde se escribe.
- **Teclado obligatorio**: `↓`/`↑` recorren, `Enter` elige la fila marcada —y **sólo si hay una marcada**, para no tragarse la tecla—, `Esc` cierra la lista **sin cerrar el modal** (el evento no se propaga) y `Tab` sale normalizando el texto.
- **El foco por código no abre la lista.** El formulario enfoca el primer campo al abrir el modal, y ahí el desplegable saldría mal puesto: el modal entra con una transición de `transform`, así que el rectángulo que se mediría es el del campo todavía corrido y en escala. Además el modal abriría tapado por 50 filas que nadie pidió. Abren el click y la primera tecla.
- **Hay que destruirlo al cerrar el modal.** El desplegable cuelga del `<body>` y los listeners de scroll/resize son globales: sacar el `.modal-backdrop` no alcanza para llevárselos.

---


## 35. Lista de paneles del perfil

Control de la pestaña **Paneles** del modal de Alta/Edición de Perfiles: un switch por panel del dominio, y nada más.

```html
<div id="prf-paneles-wrap" class="paneles-wrap">
  <div class="paneles-lista">
    <label class="toggle-switch panel-item">
      <span class="panel-item-nombre">AMERICANA</span>
      <input type="checkbox" value="218" checked>
      <span class="toggle-track"><span class="toggle-thumb"></span></span>
    </label>
  </div>
</div>
```

```css
.paneles-wrap      { display: flex; flex-direction: column; gap: 12px; }
.paneles-lista     { border: 1px solid var(--border); border-radius: var(--radius);
                     background: var(--bg); overflow: hidden; }
.paneles-lista .panel-item               { padding: 9px 12px; }
.paneles-lista .panel-item + .panel-item { border-top: 1px solid var(--border); }
.paneles-lista .panel-item:hover         { background: var(--row-hover); }
.panel-item-nombre { flex: 1 1 auto; min-width: 0; font-size: .88rem;
                     color: var(--text); word-break: break-word; }
.paneles-vacio     { padding: 16px 12px; text-align: center;
                     color: var(--muted); font-size: .85rem; }
```

**Reglas:**

- **NO reusa el selector de ids (§34), y es a propósito.** Ese control existe para catálogos de decenas de opciones —115 permisos, 122 menús— y por eso trae buscador y contador. Acá se listan los paneles de **un** dominio: el más grande de la base tiene 7. Un buscador sobre siete filas es ruido, y el switch comunica mejor que un checkbox que se está prendiendo o apagando un permiso.
- **La fila ES el `.toggle-switch`**: el `label` envuelve nombre + `input` + track, así hereda el `display:flex`, el `cursor:pointer` y el pintado del §17. Esta sección sólo agrega el reparto horizontal — `flex: 1` en el nombre es lo que empuja el switch al borde derecho, y `min-width: 0` deja que un nombre largo corte en vez de desbordar.
- **El `input` va pegado al `.toggle-track`.** El CSS del switch pinta el estado con `input:checked + .toggle-track`: cualquier nodo entre los dos deja el switch siempre apagado. Es el error fácil de cometer al reordenar el markup.
- **Sin `Seleccionar todo` / `Deseleccionar todo`** (07/09/2026, pedido explícito). Existieron y operaban sobre toda la lista; se sacaron porque son dos botones sobre a lo sumo 7 filas, donde tildar a mano cuesta lo mismo. Con ellos se fueron el `.paneles-acciones` del control y el `wirePanelesLista()` que los cableaba: **el control ya no necesita cablearse**, los switches son `<label>` + `<input>` y se leen recién al guardar (`readPanelesLista()`).
- **La lista está acotada al dominio del perfil** y se re-renderiza entera cuando cambia el campo Dominio en el alta (un combo con buscador, §34-bis). Un panel de otro dominio no es una opción válida y el backend lo rechaza con 422.
- **El `gap` vertical vive en `.paneles-wrap`**, no en márgenes de los hijos: el wrap se re-renderiza entero al cambiar de dominio y así el ritmo no depende de qué se haya dibujado adentro. Hoy le queda un solo hijo, así que no separa nada — pero el wrap sigue siendo el nodo que se re-renderiza y el que consultan `montarPaneles()` y `readPanelesLista()`.
- **La fila es sólo el nombre del panel.** Llevó un `<code>#218</code>` al lado hasta el 07/09/2026 y se sacó por pedido, junto con el de la ficha (§35.1): el id no es un dato que ayude a elegir, y el `value` del checkbox lo sigue llevando — que es lo único que necesita el guardado. **El panel sin nombre cae a `Panel #id`**, porque el `<code>` era lo único que lo identificaba.

### 35.1 Pestaña Paneles de Consultar

En **Consultar perfil** la misma lista es read-only y va con las **tarjetas en
fila de §25** (`view-card-full view-card-row`): el nombre del panel a la
izquierda y a la derecha la píldora `Habilitado` / `Deshabilitado`. Es el mismo
formato que la pestaña `Permisos` (§35-bis) — las dos contestan qué tiene
prendido este perfil.

```html
<div class="view-card view-card-full view-card-row">
  <div class="view-card-main">
    <div class="view-card-label">Tablero de bombas</div>
  </div>
  <span class="badge badge-success">Habilitado</span>
</div>
```

**Reglas:**

- **Se listan TODOS los paneles del dominio, no sólo los asignados.** Antes iban los asignados como badges `badge-info` sueltas y ahí *"le faltan dos"* no se ve: sin las filas apagadas no hay con qué comparar. La píldora en `Deshabilitado` dice lo que el perfil **no** ve, que es justo lo que se mira antes de mandar a editar.
- **El universo es el catálogo del dominio UNIDO a lo que el perfil ya tiene**, nunca sólo el catálogo. `catalogoPaneles()` trae únicamente paneles con `habilitado = 1`, así que un panel asignado que después se deshabilitó desaparecería de la ficha aunque siga en `perfiles_paneles`. Y si la ficha se abrió desde Consultar usuario → solapa Perfiles, el módulo Perfiles nunca se renderizó y el catálogo puede estar vacío: la unión es lo único que deja algo en pantalla, ahí con `#id` por nombre.
- **Sin tarjeta madre que envuelva la lista.** El rótulo `Paneles en la app (N)` repetía el nombre de la pestaña y encerraba las filas en un segundo marco; la cuenta la dicen las píldoras. Si el dominio no tiene ningún panel habilitado sí va una tarjeta sola con `<span class="badge badge-warn">Ninguno</span>` y el porqué — eso es lo que un listado vacío no comunica.
- **Sin segundo renglón con el `#id`.** La tarjeta llevó `<code>#12</code>` bajo el nombre hasta el 07/09/2026 y se sacó por pedido: el id del panel no es un dato del acceso, y con la píldora a la derecha la fila se lee de un renglón. **El `#id` sobrevive sólo como nombre de reemplazo** (`Panel #12`) en las filas que salieron de la unión y no del catálogo — el único caso donde no hay nombre. `viewCardEstado()` omite el `.view-card-value` entero cuando no hay glosa, en vez de dejar un div vacío que el `gap` separaría igual.

---

## 35-bis. Pestaña Permisos del perfil

Los **tres permisos** de `perfiles` — `operacion`, `invitacion` y `facturacion`, columnas nuevas desde la migración `20260907_1000` — tienen pestaña propia en los tres modales del módulo Perfiles: `Permisos`, entre `General` y `Paneles`.

Cada uno abre algo concreto **en otra app del repo**:

| permiso | dónde vale | qué abre |
|---|---|---|
| `operacion` | app.reactor.com.ar | Ver y usar los paneles de operación (los controles del dominio). |
| `invitacion` | app.reactor.com.ar | El ítem *Invitar un Usuario*. |
| `facturacion` | panel.reactor.com.ar | El agrupador *Cuenta* entero (Facturas / Recibos / Facturación). |

**Reglas:**

- **Pestaña propia, no tarjetas nuevas en `General`.** Dos de los tres permisos son de la app y el tercero del panel: mezclarlos con el usuario y el dominio los haría leer como atributos de cloud. Y `General` tiene ocho tarjetas justo por paridad (§25) — cualquier agregado la rompe.
- **En Consultar va una `view-card-full` por permiso, no tres medias.** Con tres tarjetas media, la grilla flex estira la última a todo el ancho y se lee como un destaque deliberado. Las full además dejan lugar para decir en la misma línea qué abre cada permiso, que es el dato que vuelve entendible una lista de permisos de otro producto.
- **Y va en la variante EN FILA** (`view-card-row`, §25): nombre del permiso a la izquierda, píldora `Habilitado` / `Deshabilitado` a la derecha, y la frase de qué abre bajo el nombre —del lado izquierdo, porque es la glosa del permiso y no la del estado—. **Es el mismo formato que la pestaña `Paneles`** (§35.1): las dos contestan la misma pregunta —qué tiene prendido este perfil— y resolverla con dos formas distintas obliga a releer la segunda.
- **La glosa de la ficha NO lleva el host donde vale el permiso.** Cerraba con `(app.reactor.com.ar)` y ahí el dominio no aporta: la ficha se abre para ver qué tiene este perfil, no para averiguar en qué máquina se ejerce, y tres hosts repetidos en tres renglones seguidos le comen la atención a la frase que sí se lee. **`donde` sigue en `PERMISOS_PERFIL` y no se toca**: lo usan el `title` de la columna del listado (§35-bis.1), donde el ícono suelto no distingue `app` de `panel`, y la `.form-nota` del modal de Filtros (§35-bis.2). Ahí el dato es lo único que desambigua; en la ficha convive con el nombre y la frase.
- **En Alta/Edición se reusa la lista de switches de §35** (`.paneles-lista` + `.toggle-switch.panel-item`). Es la misma forma —una lista corta y cerrada de cosas que se prenden y se apagan— y duplicar el CSS para tres filas no compra nada. El `input` va **pegado** al `.toggle-track`, igual que allá.
- **El segundo renglón de la fila es una frase, no un `<code>` con el id.** Por eso `.panel-item-nombre .muted` va en `display:block`: sin eso los tres permisos quedan en una línea sola y la frase se lee como parte del título.
- **El alta pre-tilda `operacion` e `invitacion` y deja `facturacion` apagada.** Es exactamente el reparto con el que la migración sembró los 2.227 perfiles que ya existían (2.227 / 2.227 / 444) y el mismo criterio por el que el alta pre-tilda todos los paneles del dominio: un perfil que naciera sin permisos sería un perfil que no puede usar la app.
- **Cloud los otorga sin restricción**, a diferencia del panel. Allá `facturacion` sólo la puede dar un perfil que ya la tenga, porque quien edita es un cliente administrando su propio dominio y sin ese corte se la daría a sí mismo. Acá quien edita es Reactor sobre el sistema entero: si ya puede crear el perfil y elegirle el dominio, pedirle además que tenga el permiso que reparte no protege nada.
- **No se derivan de `tipo` ni lo reemplazan.** `tipo` (`A`/`O`) es lo que lee el sistema legacy y lo que gatea la *entrada* al panel; estos tres gatean pantallas concretas una vez adentro. Un Administrador puede no tener facturación y un Operador puede tener operación — es el caso normal, no el raro.

### 35-bis.1 Columna `Permisos` del listado

Los mismos tres permisos aparecen en el listado del módulo como una columna
propia, **entre `Tipo` y `Paneles`** — el lugar que ya ocupan en la ficha y en
las pestañas de los modales, así el orden es el mismo se mire donde se mire.

```html
<td>
  <span class="td-permisos">
    <i class="fa-solid fa-sliders"             title="Operación — app.reactor.com.ar"></i>
    <i class="fa-solid fa-file-invoice-dollar" title="Facturación — panel.reactor.com.ar"></i>
  </span>
</td>
```

```css
.td-permisos   { display: flex; gap: 10px; color: var(--muted); font-size: .95rem; }
.td-permisos i { cursor: default; }
```

**Reglas:**

- **Un ícono por permiso OTORGADO y nada por el denegado.** No hay versión apagada ni tachada: al lado de otro ícono del mismo gris, un ícono tenue se lee como "tiene algo" y no como "no lo tiene". La ausencia es el dato. Un perfil sin ninguno de los tres muestra `<span class="muted">—</span>`, como cualquier celda vacía del sistema.
- **Van en `--muted`, el gris de `.td-id`, y no en los colores de los badges.** La fila ya lleva tres badges de color (Dominio, Tipo, Estado) y un cuarto bloque coloreado la vuelve ilegible. Estas son marcas de lectura rápida, no estados que compitan con ellos.
- **Cada ícono dibuja lo que el permiso ABRE**, no el permiso en abstracto: `fa-sliders` los controles del panel de operación, `fa-user-plus` el alta de una persona, `fa-file-invoice-dollar` el comprobante. Con tres íconos grises en la misma celda esa es la única pista de cuál es cuál antes de leer el tooltip.
- **`title` obligatorio con permiso + dónde vale** (`Operación — app.reactor.com.ar`), el mismo par que muestra la ficha de Consultar: un ícono suelto no distingue si abre `app` o `panel`, y sin eso la columna miente por omisión.
- **Los íconos salen de `PERMISOS_PERFIL`**, el catálogo único de `app.js` que ya alimenta la ficha y el formulario. Un permiso nuevo se agrega ahí con su `icono` y aparece en las tres vistas a la vez.

### 35-bis.2 Filtros por permiso

El modal de Filtros de Perfiles lleva **un select por permiso**, en la fila
siguiente a `Dominio / Tipo / Estado` y antes de `Límite / Ordenar por /
Dirección`. Por eso el módulo abre sus Filtros en modo ancho (`wide: true`,
§23-bis): con tres campos más, en dos columnas el modal se vuelve una lista
larga que hay que scrollear.

La grilla queda en cuatro filas de tres, sin los `.form-group` vacíos que antes
rellenaban las dos columnas:

| | | |
|---|---|---|
| Código | Buscar | Usuario |
| Dominio | Tipo | Estado |
| Operación | Invitación | Facturación |
| Límite | Ordenar por | Dirección |

**Reglas:**

- **Tres estados por permiso: `Todos` / `Con permiso` / `Sin permiso`.** La negativa no es un lujo simétrico: "qué perfiles quedaron sin operación" es la consulta que encuentra los errores de carga, y un perfil sin `operacion` no puede usar la app.
- **Varios permisos filtrados se cruzan con Y, no con O.** "Quién tiene operación y facturación" es la pregunta que se hace sobre permisos; para consultar uno solo alcanza con dejar los otros dos en `Todos`.
- **El label lleva el mismo ícono que la columna del listado** (§35-bis.1). Es lo que ata el filtro a lo que se ve después en la tabla; sin eso son dos vocabularios para lo mismo.
- **Debajo del select va en `.form-nota` en qué app vale el permiso.** El mismo dato que da la ficha de Consultar: sin él, `Operación` y `Facturación` en la misma columna se leen como si las dos fueran de cloud.
- **Los tres selects se generan desde `PERMISOS_PERFIL`**, igual que la columna y la ficha — incluido el estado del filtro (`permisosFiltroDefaults()`). Un permiso nuevo en el catálogo aparece solo en el modal, en el estado y en el filtrado; no hay tres claves escritas a mano que se puedan olvidar.

---


## 36. Comunicación · Notificaciones

Listado **read-only** de la tabla `notificaciones`, la misma que alimenta el modal *Notificaciones* de `app`. Lo que cloud agrega es el alcance: allá se ven las 50 últimas del dominio de la sesión, acá la tabla entera y los 83 dominios que la usan (68.717 filas al 08/09/2026).

El módulo **no aporta componentes nuevos** salvo el glifo de la fila. Todo lo demás sale de las piezas ya documentadas: `moduleHeader()` (§23), `abmToolbar()` sin `+ Nuevo` (§9), tabla estándar (§10), badges (§11), Modal de Filtros compartido (§23-bis) y tarjetas de consulta (§25).

```css
/* El glifo que la fila trae en `notificaciones`.`icono`, delante del mensaje.
   Va en --muted a propósito: identifica el tipo de aviso, no compite con el
   texto. El ancho fijo alinea todos los mensajes en la misma columna. */
.noti-icono { color: var(--muted); margin-right: 6px; width: 14px; text-align: center; }
```

**Reglas:**

- **CONSULTAR ACÁ NO MARCA COMO LEÍDO, y es la regla que no se puede romper.** El listado de `app` sella `leida = 2` sobre lo que muestra, y como la fila es **del dominio** (no de la persona), el primero que abre el modal les saca el destaque a todos los demás. Si cloud hiciera lo mismo, auditar una notificación se la marcaría como vista a un cliente que todavía no la vio. Por eso `api/notificaciones.php` responde **GET y sólo GET**: no es una omisión, es el control.
- **Sin alta, edición ni baja.** La tabla la escribe un proceso del sistema legacy que está **fuera de este repositorio** (el que genera los avisos "Dispositivo X Online/Offline"). El menú de la fila trae sólo `Consultar`, como Señales, Registros y Adopciones.
- **La columna `icono` guarda el glifo pelado** (`plug` en las 68.717 filas), no una clase: el legacy renderizaba `<i class="plug">`, o sea nada. El prefijo `fa-solid fa-` lo pone **el backend** (`icono_clase`) y no el front, para que la regla viva en un solo lado — es la misma que aplica `app/api/notificaciones.php`.
- **"Sin destinatario" se muestra como `Todo el dominio`, no como un guion.** El 99,99% de las filas (68.711 de 68.717) no tiene destinatario, y eso **no es un dato faltante**: son las notificaciones del dominio, para todos los que lo operan. Un `—` las leería como incompletas.
- **El filtro `Destinatario` es *tiene o no*, no un select de cuentas** (a diferencia de Adopciones). Sólo 6 filas tienen destinatario, así que "cuál" no es una pregunta que alguien se haga.
- **`Destino` y `Visible` se muestran en Consultar aunque vengan vacías o constantes** (destino `''` en todas, visible `1` en todas). Son columnas de la tabla: esconderlas haría que la ficha no coincida con lo que el Explorador DB muestra de la misma fila.
- **Ocho tarjetas media + `Mensaje` full en la ranura 9** (impar). Agregar o quitar un campo obliga a rehacer esa cuenta (§25).
- **Sin ventana por `id`** como la de Registros o Señales: son 68.717 filas, no 3 millones. Medido en dev, el listado de 100 con los dos `LEFT JOIN` tarda 2,5 ms. El orden es por `id` —no por `fecha`, que no tiene índice— y con filtro por dominio sale de `fk_notificaciones_dominio`, que en InnoDB es `(dominio, id)`, con `Backward index scan` y sin filesort.

---

## 37. Comunicación · Difusión

Envíos de correo a los usuarios del sistema. Es el **único módulo de cloud que produce un efecto fuera de la plataforma**: lo que sale no se puede dar de baja. Todo el diseño de la pantalla está puesto al servicio de eso — que el operador vea a cuántos le va a llegar antes de confirmar, y que después pueda saber a quién le llegó y a quién no.

Escribe `difusiones` (la campaña) y `difusiones_destinatarios` (una fila por persona), creadas por `cloud/sql/migrations/20260908_1000_crear_difusiones.sql`. El canal es el **mismo que el de las invitaciones**: el microservicio de Databox vía `lib/databox.php`, copia idéntica de la del panel y la de la app.

### 37.1 Barra de progreso

```css
/* Aparece en dos tamaños y es EL MISMO componente: en la celda `Progreso` del
   listado (compacta) y en el modal de envío (.dif-progreso-lg), donde se mueve
   en vivo. Que sean la misma pieza es el punto: lo que se ve durante el envío
   tiene que ser lo mismo que después queda en la fila. */
.dif-progreso          { display: flex; flex-direction: column; gap: 4px; min-width: 130px; }
.dif-progreso-barra    { height: 6px; background: var(--bg); border-radius: 999px;
                         overflow: hidden; border: 1px solid var(--border); }
/* `width` la escribe el JS. La transición es lo que hace legible el avance por
   lotes: sin ella la barra salta de a 20 destinatarios y se lee como un
   parpadeo, no como progreso. */
.dif-progreso-relleno  { height: 100%; background: var(--primary); width: 0;
                         transition: width .3s ease; }
.dif-progreso-cifras   { display: flex; justify-content: space-between; gap: 8px;
                         font-size: .75rem; color: var(--muted); font-family: monospace; }
.dif-progreso-fallidos { color: var(--danger); }
.dif-progreso-lg                      { min-width: 0; gap: 8px; margin: 14px 0; }
.dif-progreso-lg .dif-progreso-barra  { height: 12px; }
.dif-progreso-lg .dif-progreso-cifras { font-size: .82rem; }
```

### 37.2 Bloque de audiencia (modal de alta)

```css
/* El número que el operador confirma. Se destaca porque es el dato que decide
   el envío — no un hint del formulario. */
.dif-audiencia        { background: var(--bg); border: 1px solid var(--border);
                        border-radius: var(--radius); padding: 12px 14px;
                        display: flex; flex-direction: column; gap: 6px; }
.dif-audiencia-total  { display: flex; align-items: baseline; gap: 8px;
                        font-size: .85rem; color: var(--muted); }
.dif-audiencia-numero { font-size: 1.5rem; font-weight: 700; color: var(--text); line-height: 1; }
.dif-audiencia-nota   { font-size: .78rem; color: var(--muted); line-height: 1.45;
                        word-break: break-word; }
```

### 37.3 Pestaña Destinatarios (modal de consulta)

```css
/* Buscador + chips por estado arriba de la tabla: la misma forma de la toolbar
   del listado (§9), en chico. */
.dif-dest-toolbar { display: flex; align-items: center; justify-content: space-between;
                    gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.dif-dest-chips   { display: flex; gap: 6px; flex-wrap: wrap; }
.dif-dest-error   { color: var(--danger); font-size: .78rem; word-break: break-word; }
/* El cuerpo del correo se muestra tal como se escribió: los saltos de línea son
   parte del mensaje que salió, así que `pre-wrap` y no un párrafo reflowado. */
.dif-cuerpo       { white-space: pre-wrap; word-break: break-word; line-height: 1.5; }
.dif-envio-asunto { font-size: .95rem; font-weight: 600; color: var(--text); word-break: break-word; }
.dif-envio-error  { color: var(--danger); font-weight: 600; }
```

### 37.4 Reglas

- **LA LISTA DE DESTINATARIOS SE CONGELA AL CREAR LA DIFUSIÓN.** El alta inserta una fila por persona —con el correo y el nombre **copiados** de `usuarios`— dentro de la misma transacción. El número que el operador confirmó es el que se guarda: si la consulta se repitiera al enviar, un alta hecha entre medio recibiría un correo que nadie decidió mandarle. Y el correo se copia además de la FK porque **es lo que se le mandó**: si esa cuenta después lo cambia, el historial tiene que seguir diciendo a qué dirección salió.
- **EL ALCANCE POR DOMINIO SALE DE `perfiles`, NO DE `usuarios.dominio`.** Esa columna es el dominio **activo** —el último que la persona usó en cualquiera de los sistemas que comparten la tabla— y no la lista de dominios a los que puede entrar. Medido en dev: el dominio 135 tiene 148 cuentas con ese dominio activo y 143 con perfil habilitado ahí, y **ninguna de las dos listas contiene a la otra** (el 241 da 140 contra 141). Es el mismo razonamiento con el que el módulo Usuarios del panel lista su gente.
- **`dominio = NULL` significa "todos los dominios" y es un valor, no un faltante.** Por eso el alcance global se pinta con badge (`Todos los dominios`) y no con un guion, y por eso la FK es `ON DELETE SET NULL`: si el dominio se elimina, la campaña queda como historial sin dominio.
- **EL ENVÍO SE DRENA POR LOTES Y LO MANEJA EL NAVEGADOR** (`POST ?enviar=1`, 20 por request; el bucle vive en `openDifusionEnvioModal()`). Tres razones, en orden: **(1)** son hasta 2.055 correos —uno por destinatario, porque el microservicio recibe un `destino` por llamada— y una sola request se come cualquier timeout; **(2)** cron no sirve como camino silencioso, porque el Programador de tareas depende de cronie + `/etc/cron.d` instalados al aprovisionar y el deploy no los toca: una difusión colgada de él podría no salir nunca con la pantalla diciendo "enviando"; **(3)** así es **reanudable**, que es lo que de verdad importa.
- **El estado es la tabla, no la memoria del navegador.** Cada lote devuelve los contadores **contados sobre la tabla hija** (nunca `enviados + N`, que se desincroniza en cuanto dos pestañas drenan la misma campaña), y cada destinatario se marca **apenas vuelve su llamada**, no al cerrar el lote. Por eso cortar a la mitad no repite envíos y `Reanudar envío` sigue por donde iba.
- **CERRAR EL MODAL DE PROGRESO NO CANCELA NADA**: sólo corta el bucle. Cancelar es una acción explícita del menú de la fila que cambia el estado en la base, y **deja las pendientes pendientes** — cancelar no es descartar la lista, es dejar de mandar. Lo que faltaba queda visible en la ficha, que es la única forma de saber a quién no le llegó.
- **Un destino que rebota no corta el lote.** `databoxCorreoEncolar()` nunca lanza: devuelve `['ok' => false, ...]`, esa fila queda `fallido` **con el motivo guardado** y el bucle sigue. Cortar en el primer error dejaría el resto sin mandar por una dirección mal escrita.
- **Se deduplica por correo en minúsculas y se descarta lo que no es una dirección.** La misma persona puede tener varias cuentas (10 correos repetidos en dev) y sin el filtro recibiría el mismo mensaje dos veces; y una fila tiene `robertomaguero.se@gmail`, sin dominio de primer nivel. **Cuántas quedaron afuera se muestra**, no se descarta en silencio: si no, el total de la pantalla no cierra con el de la base y nadie sabe por qué.
- **NO HAY EDICIÓN: el endpoint no tiene `PUT`.** Una difusión emitida es lo que la gente recibió; reescribirla dejaría el historial diciendo algo distinto de lo que salió. Por eso el modal de alta es sólo de alta y su acción primaria dice **`Enviar`** y no `Guardar` (única excepción al rótulo de §21-bis para formularios): una difusión guardada y sin mandar no le sirve a nadie.
- **El envío pasa por `confirmDialog` con el número a la vista**, en tono `primary` y no `danger` (§15): no borra nada, pero es irreversible y le llega a miles de personas, y el alcance se elige con un select que es fácil de dejar en `Todos los dominios` sin querer.
- **EL CUERPO SE ESCAPA: el HTML que se pegue se ve tal cual, no se interpreta.** El operador escribe texto; los párrafos se arman por línea en blanco y los saltos sueltos con `<br>`. Aceptar HTML crudo dejaría que un `<div>` sin cerrar rompa la plantilla de Databox para los 2.000 destinatarios, y no hay pantalla de previsualización que lo muestre antes. El día que haga falta formato va un editor que **produzca** el HTML, no el permiso de pegarlo.
- **Consultar son dos pestañas** (§25 para las tarjetas, y el conmutador es `wireModalTabs()`): `General` con la campaña —**diez tarjetas media entre dos full** (`Asunto` arriba, `Mensaje` abajo): cinco renglones que cierran de a dos, y agregar un campo deja la cuenta impar— y `Destinatarios`, que es la razón de ser del módulo cuando algo sale mal. Esa segunda trae **buscador y chips por estado** porque la respuesta a *"¿le llegó a Fulano?"* está entre hasta 2.000 filas.
- **La ficha se pide por id (`GET ?id=N`), no viaja en el listado**: serían hasta 2.000 filas por difusión repetidas en cada render de la tabla.
- **La baja va con el modal de desglose** (§15.1) aunque no haya bloqueos: la única FK que apunta a `difusiones` es CASCADE, pero la baja se lleva el registro de a quién le llegó. Y **una difusión `enviando` no se borra**: primero se cancela, o el operador se queda sin la lista que justamente hay que mirar después de interrumpir un envío.
- **`emisor` es un `controladores`.`id`**, no un `usuarios`.`id`: a cloud se entra con una cuenta de esa tabla desde el 07/09/2026. La FK es `ON DELETE SET NULL` con el mismo criterio que `perfiles`.`registrante` — apunta a quien **disparó** el envío, no a quien pertenece.

---

## 38. Propiedad · Técnicos

ABM de `tecnicos`: la red de técnicos instaladores que publica el sitio público (`www/tecnicos/`, ver `www/CLAUDE.md`). Cuelga de **Propiedad**, debajo de `Perfiles`, con `fa-user-helmet-safety`.

Es el **lado de escritura de una tabla que este repo sólo sabía leer**: `www` inserta solicitudes desde un formulario abierto en internet —que nacen sin aprobar y sin publicar a propósito— y aprobarlas pasaba por el back office viejo, que está fuera del repositorio. Este módulo lo reemplaza.

El módulo **no aporta componentes nuevos**: sale entero de las piezas ya documentadas — `moduleHeader()` (§23), `abmToolbar()` (§9), tabla estándar (§10), badges (§11), secciones de formulario (§8.1), toggle (§17), Modal de Filtros compartido (§23-bis) y tarjetas de consulta (§25).

**Reglas:**

- **PUBLICADO ES LA CONJUNCIÓN DE DOS COLUMNAS Y ASÍ SE MUESTRA.** Un técnico sale en la vidriera sólo con `aprobacion = '2'` **y** `visibilidad = '1'`; hay 7 aprobados que no se publican, porque la aprobación lo habilita como técnico y la visibilidad decide si además aparece. Por eso la columna del listado es `Publicado` (la conjunción, calculada por el backend en `publicado`) y no una de las dos banderas, el filtro `Publicación` opera sobre eso mismo, y el estado del medio se dice con todas las letras: `Visible, sin aprobar`. Un badge que dijera "visible" sobre alguien que no sale en el sitio es la confusión que el módulo existe para sacar.
- **NINGUNA DE LAS DOS ES LA BANDERA `habilitado`.** Son `varchar(1)` y van comparadas contra la **cadena**; `aprobacion` ni siquiera es booleana (`'1'` sin revisar, `'2'` aprobado). No se tocan con `esHabilitado()` / `valorHabilitado()` ni se bindean como enteros — es la excepción que ya documenta `www/CLAUDE.md`, y acá vale igual.
- **Un valor fuera de las dos listas se dibuja como lo que es.** La columna no tiene `ENUM` ni `CHECK`: si el legacy escribiera un tercer código, el badge lo muestra crudo en `badge-danger` y el select lo agrega marcado (`9 (fuera de catálogo)`), como los planes deshabilitados de Contratos. Sin esa opción el navegador caería en la primera y guardar le cambiaría el estado a la fila en silencio.
- **La ubicación son SEIS columnas y el formulario muestra las seis, apareadas.** Las de nombre limpio (`localidad`, `provincia`, `pais`) guardan el id del catálogo (`localidades` / `provincias` / `paises`) y las de guion bajo al final (`localidad_`, `provincia_`, `pais_`) guardan el **texto, que es lo único que el sitio público lee**. Hoy el texto lo tienen casi todas las filas y el id casi ninguna (`localidad` está vacía en las 95). Mostrar sólo una de las dos formas dejaría media ubicación invisible desde acá.
- **Los tres selects de catálogo están encadenados, y elegir uno completa el texto sólo si está vacío.** Al cambiar el país se repuebla la provincia con las que le cuelgan y el valor que dejó de pertenecerle se descarta; ídem provincia → localidad. El espejo hacia el campo de texto **nunca pisa lo cargado**: ese texto es el que ya se está publicando de esa persona (y trae cosas como `San Telmo,caba`, que no existen en el catálogo).
- **Lo heredado pasa, pero sólo mientras nadie toque el padre.** `tecnicoCatalogoValidado()` acepta el id que la fila ya traía —aunque hoy no esté en su catálogo— para no bloquear la edición del nombre por un dato que cargó el legacy; pero si el operador cambia el país, la provincia heredada **se revalida y corta**, porque ese par ya no es el que estaba guardado. Mismo criterio con el celular: los 10 dígitos se exigen a lo que se escribe nuevo, y las 6 filas que traen el número en formato internacional se re-guardan tal cual.
- **El celular se limpia mientras se tipea pero NUNCA se recorta**, igual que en las dos invitaciones: sacar un guion deja el mismo número, cortar `5492644123456` en el dígito 10 da uno que no es de nadie. Lo que sobra queda a la vista y lo rechaza la validación —del servidor, que es la que corre siempre.
- **El correo no es obligatorio acá y en el formulario público sí.** Este módulo edita las 95 filas que ya existen y una no tiene correo: exigirlo la dejaría sin poder corregirse por un dato que su alta nunca pidió. Lo que sí se exige es que lo que llegue **sea** un correo.
- **`uuid` y `registrado` se muestran y no se editan**, en su propia sección (`Asignado por el sistema`). El primero es la URL de la ficha que el técnico ya le pasó a sus clientes; la segunda es la fecha del alta. Es la excepción declarada de `ABM.md`: la restricción es de escritura, no de lectura. En el alta los asigna el backend —el uuid con el **mismo alfabeto de 16 caracteres** que genera `www`, y consultando antes que esté libre, porque la tabla no tiene `UNIQUE` y `tecnicoPorUuid()` resuelve con `LIMIT 1`.
- **`Ver ficha pública` va pegada a `Consultar`, sin divisor** (es otra forma de ver el registro) y **sólo cuando está publicado**: si no, esa URL responde 404. Abre `www.reactor.com.ar/tecnicos/consultar?uid=…` en otra pestaña, con el host escrito, igual que el visor de contratos.
- **Diecisiete tarjetas en Consultar: dieciséis `half` y `Domicilio` full en la ranura 9** (impar), así los ocho campos de arriba y los ocho de abajo cierran de a dos (§25). Agregar o quitar un campo obliga a rehacer esa cuenta.
- **La baja va con el `confirmDialog` estándar** (§15) y no con el modal de desglose: `tecnicos` es una isla del esquema, ninguna FK apunta a ella. Lo que sí se avisa —cuando corresponde— es que la ficha pública deja de responder: esa URL ya circula fuera de Reactor.

---

## 39. Modal `Acceso a Panel / App` (el enlace mágico y su configuración)

Lo abre `Usuarios → Consultar → Acciones → Generar acceso a…` después de que el
`POST api/enlaces_acceso` devolvió la URL. **Muestra el enlace y, debajo, los dos
límites que lo definen**: hasta cuándo vale y cuántas veces se puede abrir. No
aporta componentes nuevos — `.modal-wide` (§14) con cabecera primaria + barra de
acciones (§21-bis), `.form-row` / `.form-group` (§8), `.form-nota`,
`.field-error` y el recuadro rojo `.del-blocker` (§15.1).

**Reglas:**

- **Los campos operan sobre el enlace que ya está arriba, no sobre uno nuevo.**
  `Guardar` manda `PUT api/enlaces_acceso {id, expira, usos_max}` y **el token no
  cambia**: lo que el operador ya copió sigue sirviendo. Emitir otro con los
  valores nuevos obligaría a descartar el primero — y el token se muestra una
  sola vez, así que descartarlo es perderlo.
- **La caja del enlace es de dos renglones** (`.enlace-url`) y no el
  `.json-editor` de 360px que usaba hasta el 21/09/2026: ese alto es para pegar
  un JSON entero y acá hay una URL de una línea. El espacio que sobraba es
  exactamente el que ocupan ahora los dos campos. Envuelve (`pre-wrap` +
  `break-all`) porque la URL no tiene espacios donde cortar y con el `pre` del
  editor aparecía scroll horizontal.
- **Los defaults son 60 minutos y 1 uso**, y los elige el backend al emitir
  (`ENLACE_MINUTOS` / `ENLACE_USOS`): el modal los muestra ya cargados, así que
  el camino normal —generar y mandar— no obliga a tocar nada. Los topes también
  vienen de ahí (`expira_max`, 30 días; `usos_tope`, 100) y el front no los
  repite: el `max` del campo de fecha es el que calculó la base.
- **Las fechas se manejan en la hora de la BASE y no pasan por `new Date()`.** El
  valor del campo sale de recortar el string que devolvió la API
  (`2026-09-21 15:30:00` → `2026-09-21T15:30`) y vuelve igual; `min` y `max`
  salen de `ahora` / `expira_max`, que calcula la base. El canje compara contra
  `NOW()` de la base, así que reinterpretar la fecha contra la zona del navegador
  correría el límite — es el mismo drift que ya documentan los gráficos de señal.
- **El `min` / `max` del input no es el control.** El endpoint revalida los dos
  campos contra el reloj de la base y corta con 422; el front sólo ataja el campo
  vacío para no gastar un request.
- **Después de guardar se repinta con lo que devolvió la base**, no con lo
  tipeado: los campos y la línea del recuadro rojo dicen lo que quedó guardado y
  no lo que se pidió guardar.
- **El recuadro rojo sigue siendo el aviso de "se muestra una sola vez"**, y su
  última línea es la que resume los dos límites (`Vence el … y sirve hasta N
  veces`). Es lo único del modal que cambia al guardar.
- **`Guardar` es un botón más de la barra, no la única acción**: convive con
  `Copiar enlace` y `Abrir`, que operan sobre el mismo enlace. `Cerrar` sigue
  primero y sigue siendo el único ghost (§21-bis).

---

## 40. Comunicación · Conversaciones

Las charlas del **chat con IA de la burbuja de `www.reactor.com.ar`**. Dos tablas: `conversaciones` (una fila por charla) y `conversaciones_mensajes` (una por mensaje, de los dos lados). Endpoint `api/conversaciones.php`.

Es el tercer módulo del grupo Comunicación y el único de los tres donde **el que escribe es alguien de afuera del sistema**: Notificaciones las produce un proceso, Difusión la dispara un operador, y acá las filas las genera un visitante anónimo conversando con el asistente.

El módulo **no aporta componentes nuevos salvo las burbujas del diálogo**. Todo lo demás sale de las piezas ya documentadas: `moduleHeader()` (§23), `abmToolbar()` sin `+ Nuevo` (§9), tabla estándar (§10), Modal de Filtros compartido (§23-bis), tarjetas de consulta (§25) y barra de acciones del modal (§21-bis).

```css
/* El diálogo es una caja con SCROLL PROPIO dentro de la solapa. Ver la regla
   de más abajo: lo único que se mueve es la lista de mensajes. */
.conv-dialogo { max-height: 52vh; overflow-y: auto; overscroll-behavior: contain;
                background: var(--bg); border: 1px solid var(--border);
                border-radius: var(--radius); padding: 16px; }

/* Burbujas. La persona a la IZQUIERDA y el asistente a la derecha, al revés
   que en la burbuja del sitio: acá el que lee es un operador y lo que viene a
   buscar es qué le preguntaron. */
.conv-msg.persona   { align-self: flex-start; background: var(--surface);
                      border: 1px solid var(--border); }
.conv-msg.asistente { align-self: flex-end;
                      background: color-mix(in srgb, var(--primary) 18%, var(--surface));
                      border: 1px solid color-mix(in srgb, var(--primary) 38%, transparent); }
```

**Reglas:**

- **CONSULTA Y BAJA, SIN ALTA NI EDICIÓN, y las tres decisiones tienen motivo distinto.** Un alta fabricaría una charla que nunca ocurrió; una edición reescribiría lo que alguien dijo. **La baja sí está**, al revés que en Notificaciones, Señales y Registros: estas dos tablas son las únicas del sistema que acumulan texto libre, IP y navegador de gente que **nunca se registró** —datos personales bajo la 25.326— y tiene que existir la forma de borrar una conversación puntual sin entrar a la base a mano. `api/conversaciones.php` responde **GET y DELETE**, y nada más.
- **La columna `Consulta` es la razón de ser del listado.** Es la primera pregunta de la persona, traída con una subconsulta. Sin ella cada fila son dos fechas y una IP, y habría que abrir la ficha de las cien para saber de qué hablaban.
- **`Turnos` y `Mensajes` son dos números distintos y los dos se muestran.** `Turnos` es la columna `conversaciones`.`mensajes`, que cuenta **sólo los de la persona** porque es el contador con el que el sitio aplica su tope por conversación; `Mensajes` cuenta las filas de las dos partes. Mostrar uno solo haría que el número no cierre contra lo que se ve en la ficha.
- **La búsqueda por texto entra a la tabla hija con `EXISTS`, no con `JOIN`.** El método es el de siempre (§ABM.md: términos cruzados con Y, cada uno en todos los campos con O), pero uno de esos campos es `conversaciones_mensajes`.`texto`: con un `JOIN` la consulta devolvería una fila por mensaje y el `LIMIT` —que cuenta filas, no conversaciones— dejaría de significar lo que dice. Cada término se busca **en los datos de la conversación O en alguno de sus mensajes**, con dos juegos de placeholders de prefijos distintos (`qc` / `qm`), porque cada nombre puede aparecer una sola vez en la sentencia.
- **El modal de Consultar va en dos solapas: `General` y `Conversación`** (§25, `wireModalTabs()`). En la primera, las diez tarjetas con los datos de la charla; en la segunda, el diálogo. **Se dibujan las dos de entrada, sin lazy-load** —al revés que la solapa `Perfiles` de Usuarios—: el diálogo ya vino en el mismo request que abrió el modal, así que no hay nada que diferir.
- **EL SCROLL ES DE LA LISTA DE MENSAJES, NO DEL MODAL.** `.conv-dialogo` lleva su `max-height` y su `overflow-y`; el `.modal-body` no scrollea en esa solapa porque nada más ocupa alto. Si scrollease el cuerpo entero, las solapas se irían hacia arriba en la primera vuelta de rueda y se perdería de vista en qué pestaña se está parado — el mismo motivo por el que §21-bis fija la barra de acciones. Lleva `overscroll-behavior: contain` para que al llegar al final la rueda no siga empujando lo de atrás.
- **Al abrir la solapa el diálogo se posiciona abajo de todo**, que es donde está lo último que se dijo y, en una conversación que terminó mal, lo que se viene a leer. Va en el `onShow` de `wireModalTabs()` y no al montar el modal: el panel nace con `hidden` y un elemento oculto tiene `scrollHeight` 0, así que fijar el scroll antes de mostrarlo no hace nada.
- **La solapa `Conversación` SE AUTOREFRESCA cada 3 segundos** (`CONV_REFRESCO_MS`). La charla puede estar pasando en ese momento —alguien escribiendo en el sitio mientras del otro lado se la mira—, así que mientras la solapa esté a la vista se vuelve a pedir la ficha y, si hay mensajes nuevos, se repinta y se baja al final. Tres reglas, y las tres son la funcionalidad:
  - **Sólo se repinta si hay algo nuevo**, comparando el `id` del último mensaje. Repintar cada 3 segundos pase lo que pase tiene dos efectos que se notan enseguida: el `scrollTop` al final le arranca la lectura de las manos a quien subió a leer algo, y el DOM reemplazado le corta cualquier selección de texto.
  - **Sólo corre con la solapa abierta.** En `General` no hay nada que refrescar, y seguir pidiendo sería una consulta cada 3 segundos por cada modal que alguien dejó abierto. Lo prende y lo apaga el `onShow`; cerrar el modal lo apaga también.
  - **Un error corta el ciclo**, no lo reintenta: sesión vencida, red caída o la conversación borrada desde otra pestaña. Insistir cada 3 segundos contra un endpoint que falla sólo llena el log.
  - El refresco actualiza **el badge de la solapa y las tarjetas `Mensajes` y `Tokens`** de `General`: si no, mirar una conversación en vivo deja `Mensajes 4` al lado de un badge que dice 8, que se lee como un error de la pantalla.
  - Arriba del diálogo va el aviso **`En vivo · se actualiza cada 3 segundos`** con un punto que late. No es decoración: sin él, una conversación que crece sola parece que la pantalla se movió por las suyas, y decir cada cuánto evita la lectura opuesta —quedarse esperando algo que ya llegó—.
- **Cada burbuja lleva adentro el rótulo de quién habla y la hora.** El rótulo no es redundante con el lado en el que cae: es lo único que distingue a las dos cuando la pantalla se imprime, se captura o se lee con un lector de pantalla, donde "está a la derecha" no significa nada.
- **La burbuja del asistente va con TINTE de primary, no con primary sólido.** Es la regla dura 2: fuera del chrome el rojo institucional sólo aparece como acento. El tinte más el borde alcanzan para distinguirla de un vistazo, que es para lo que está el color.
- **Debajo de lo que escribió la persona va la URL completa desde la que lo escribió** (`conversaciones_mensajes`.`origen`), chica y en `--muted`. El chat vive en el pie de **todas** las páginas del sitio y la conversación sobrevive a la navegación, así que una misma charla puede empezar en `/precios/planes` y seguir en `/ayuda`: saber desde dónde preguntó cada cosa es la mitad de entender qué buscaba. `conversaciones`.`pagina` —la tarjeta *Página de apertura*— sólo dice dónde empezó.
  - **No se recorta con ellipsis.** Una URL cortada a la mitad no se puede copiar ni pegar en la barra del navegador, que es para lo que se la mira; envuelve en dos líneas y listo.
  - **Sólo del lado de la persona.** La respuesta del modelo no se escribe desde ninguna página: esas filas van en `NULL` y eso significa *no aplica*, no *falta*.
  - **OJO CON EL NOMBRE:** `conversaciones`.`origen` es la **IP** y `conversaciones_mensajes`.`origen` es la **URL**. Dos columnas con el mismo nombre y distinto significado en dos tablas que se leen siempre juntas. Por eso la tarjeta de la IP se rotula **`IP de origen`** y no `Origen` a secas: dos rótulos iguales para dos cosas distintas en la misma ficha se leen mal.
- **EL DIÁLOGO MUESTRA LA CHARLA Y NADA MÁS.** Debajo de las respuestas hubo primero una línea con modelo y tokens, y después los `uuid` de los artículos que se le pasaron al modelo; las dos se sacaron por el mismo motivo: son datos de auditoría y competían con lo que se viene a leer, que es qué se preguntó y qué se contestó. El **modelo** y los **tokens** pasaron a ser tarjetas de la solapa `General` —el modelo es el de la última respuesta, que dentro de una conversación no cambia salvo que se toque `OPENAI_MODELO` en el medio—. Los **artículos se siguen guardando** en `conversaciones_mensajes`.`contexto` y el endpoint los sigue devolviendo: dejaron de pintarse, no de registrarse, así que el día que haya que reconstruir de dónde salió una respuesta el dato está.
  - **Lo único que queda debajo de un mensaje es la URL**, y sólo del lado de la persona. Es la excepción y tiene motivo: no es auditoría del sistema sino contexto de la consulta — desde dónde la hizo cambia qué estaba buscando.
- **El diálogo se pide al abrir la ficha, no viene con el listado.** Son hasta 50 mensajes por conversación: traerlos para las 100 filas de la ventana serían miles de textos que nadie va a leer.
- **Debajo de cada respuesta van los `uuid` de los artículos que se le pasaron al modelo.** Es lo único que queda del contexto con el que contestó —el prompt de sistema **no se guarda**, se rearma en cada turno— y es lo que permite entender de dónde salió una respuesta rara. Una respuesta con `sin artículos` contestó sólo con el documento del experto y los planes.
- **El KPI de costo dice `< USD 0,01` en vez de `USD 0,00`.** Con el modelo chico que usa el chat, un mes de tráfico real puede costar menos de un centavo; un `toFixed(2)` pelado deja el indicador clavado en cero y se lee como un contador roto. Que el gasto sea despreciable es el dato, pero hay que decirlo de una forma que no parezca un bug. El backend manda **cuatro decimales** por eso mismo.
- **Los dos precios por millón de tokens están en el endpoint, no en la base** (`CONVERSACIONES_USD_ENTRADA` / `_SALIDA`). Son de OpenAI, cambian cuando ellos quieren y no hay nada que el operador pueda configurar; por eso el KPI dice **estimado** y por eso el rótulo lo aclara.
- **Ocho tarjetas media + `Identificador` y `Navegador` full**, en ese orden: cuatro renglones que cierran de a dos y después los dos anchos. Agregar o quitar un campo obliga a rehacer la cuenta (§25).
- **La baja arrastra los mensajes por la FK `ON DELETE CASCADE` y el `confirmDialog` dice cuántos son.** No hace falta el modal de impacto de §15.1: no hay nada que se conserve sin la referencia, así que el desglose entra en la frase — el mismo trato que `confirmDeleteDomain`.

---

## 41. Comercial · Artículos

ABM de `articulos`: el catálogo del que cuelga toda la plata del sistema — el abono de cada plan (`planes`.`articulo`), lo que se factura (`comprobantesrenglones`.`articulo`), lo que se le cobra a un chip y lo que publica la tienda. Cuelga de **Comercial**, debajo de `Talonarios`, con `fa-box`.

Reemplaza a `reactor-admin/articulos/` del sistema histórico, que está fuera de este repositorio.

**Va al final del grupo y no antes de Contratos** aunque sea el catálogo del que éste cuelga: `Contratos / Comprobantes / Talonarios` es el circuito que el operador recorre todos los días y el catálogo se toca de vez en cuando. `Planes` queda pegado a `Artículos` porque su abono **es** el precio de venta de un artículo.

### 41.1 Componentes propios

Casi todo sale de piezas ya documentadas — `moduleHeader()` (§23), `abmToolbar()` (§9), tabla estándar (§10), badges (§11), secciones de formulario (§8.1), Modal de Filtros compartido (§23-bis), tarjetas de consulta (§25) y confirmación con previsualización (§15.2). Lo propio son dos cosas:

```css
/* Marca de "este precio salió con otra cotización", pegada al importe en pesos.
   Misma métrica que `.plan-modo-icon` —es un atributo del número de al lado, no
   una columna— pero en `--warn` y no en `--muted`, y ahí está la diferencia:
   aquélla DESCRIBE un atributo (el modo del plan, que siempre vale algo) y ésta
   AVISA que el número que se está leyendo no es el que da la cuenta de hoy. Por
   eso además sólo se dibuja cuando corresponde: un ícono siempre presente deja
   de avisar nada. */
.precio-viejo-icon     { color: var(--warn); font-size: .75rem; margin-left: 6px; }

/* Los `metadatos` son los bloques que lee la tienda pública (`<oferta>`,
   `<resumen>`, `<especificaciones>`), y su formato es parte del dato: los
   saltos separan secciones y `||` separa columnas. Por eso se muestran y se
   editan monoespaciados y con los saltos intactos —`pre-wrap` y no un párrafo
   reflowado—, igual que el cuerpo de una difusión (§37.3). */
.art-metadatos         { white-space: pre-wrap; word-break: break-word;
                         font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                         font-size: .8rem; line-height: 1.45; margin: 0; }
.art-metadatos-input   { font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                         font-size: .8rem; line-height: 1.45; }
```

### 41.2 Reglas

- **DOS COLUMNAS SON DERIVADAS Y NO SE TIPEAN.** Es la cuenta de `cArticulo::recalcular()`:

  ```
  compra = importacion × cotización        (sólo cuando la moneda es Dólar)
  venta  = compra + compra × margen / 100  (siempre)
  ```

  Verificado contra la base: las 106 filas cumplen la segunda al centavo. Por eso `Precio de venta` va **siempre** `readonly` y `Precio de compra` lo va **cuando la moneda es Dólar** — dejarlo editable ahí prometería un valor que el guardado descarta. Los dos llevan vista previa en vivo y una `.form-nota` que dice de qué se arman, porque un campo que no se puede editar y no dice por qué se lee como un error (§8, y el mismo trato que `talonarios`.`nombre`).
- **LA CUENTA LA HACE EL BACKEND; EL FRONT SÓLO LA MUESTRA.** Vive en `api/articulos_lib.php` y la aplican por igual el `PUT` del ABM y la acción de recalcular. El JS del modal reproduce la fórmula **para la vista previa** y nada más: lo que se guarda no sale de ahí.
- **LOS PRECIOS SE RECALCULAN EN CADA GUARDADO, y eso sorprende: hay que decirlo.** Es lo que hace el back office viejo (`editar.php` llama a `recalcular()` antes de `agregar()` y de `modificar()`) y es la lectura correcta de la columna — `compra` en pesos **es** `importacion` por la cotización. La consecuencia es que corregirle el nombre a un artículo en dólares le actualiza el precio; la nota del formulario lo anuncia **antes** de guardar, que es la única diferencia con el legacy.
- **LA COTIZACIÓN NO ES UNA COLUMNA: vive en `parametros`.`articulos.dolar.cotizacion`** y la mueve el job de las 06:00 (§33-quater). Cada fila arrastra la cotización del día en que se la tocó, así que `Precio viejo` es una pregunta legítima: el módulo lo cuenta en la stat card, lo marca en la fila con `.precio-viejo-icon`, lo filtra desde el modal (`Precio`) y ofrece `Recalcular precios` como acción con nombre.
  - **Desde el 30/09/2026 el piso lo pone un job diario** (`articulos_recalcular.php`, 07:00, §33-quinquies), así que lo normal es que la stat card esté en **0** y que lo que marque sean las filas tocadas **después** de esa corrida. Antes de ese job el desfasaje era el estado de fondo de la tabla: convivían cinco cotizaciones implícitas (1370, 1375, 1380, 1415, 1450) y **70 de las 79 filas en dólares** estaban por debajo de la vigente. La stat card pasó de medir deuda acumulada a medir el día.
- **La cotización vigente se muestra bajo las KPIs, con su fecha y con de dónde sale.** Un precio derivado de un parámetro que la pantalla no muestra no se puede verificar contra nada.
- **`Recalcular precios` es una ACCIÓN DE NEGOCIO, no un `PUT`.** Vive en `api/articulos_accion.php?accion=recalcular`, `GET` previsualiza y `POST` ejecuta, y los dos resuelven la misma función (§15.2, ABM.md). Es el `Actualizar` del menú `Acciones` del back office viejo. Va **primera dentro del bloque de extras** del menú de fila, antes de las navegaciones y las copias (ABM.md §1.3).
  - **La confirmación muestra los cuatro números** —compra y venta, antes y después, con el delta— y **los planes que facturan ese artículo con sus contratos** en el recuadro ámbar de avisos: tocar el precio les cambia el abono a todos. Es plata, va dicho antes de confirmar.
  - **El `POST` rehace la cuenta dentro de la transacción y con la fila bloqueada** (`SELECT … FOR UPDATE`): entre la previsualización y el click alguien pudo editar el artículo o mover la cotización. La `.form-nota` al pie lo dice.
  - **Sin cotización cargada hay bloqueo, no un recálculo a cero**: el botón de confirmar no se dibuja y el endpoint corta igual con 409. Esconder el botón no es el control (`CLAUDE.md`).
  - **No hay "recalcular todos" EN LA PANTALLA, y sigue sin haberlo.** Un botón que reescriba el precio de los 106 artículos desde un menú de fila no es la misma decisión que repreciar uno. Lo masivo lo hace el job de las 07:00 (§33-quinquies), que para eso tiene guardas que esta acción no necesita —no manda a `$ 0,00` una fila que tenía precio, saltea lo que se pasa de la columna— porque ahí no hay nadie mirando la confirmación. Esta acción es para repreciar **ya**, sin esperar a mañana.
- **VISIBILIDAD NO ES `habilitado`: son dos preguntas y van dos badges.** `habilitado` dice si el artículo se puede usar (95 de 106) y `visibilidad` si además se publica en la tienda (38). Un artículo público y deshabilitado es posible y no es una inconsistencia. `visibilidad` es `varchar(10)` con los códigos `'0'`/`'1'` de `combos`, así que **no** se toca con `esHabilitado()` / `valorHabilitado()` — se compara como string, la misma excepción que `aprobacion` en Técnicos (§38).
- **Marca y categoría van de glosa bajo el nombre, no en columnas propias.** Son cómo se identifica el artículo, no datos que se comparen entre filas. La categoría sí es columna del Modal de Filtros, y en los desplegables se muestra con su **jerarquía** (`Monitores de Corriente · 001.003`), que es lo que dice de qué cuelga.
- **La columna `USD` sólo trae número cuando la moneda es Dólar.** En pesos la importación no participa de ninguna cuenta: mostrar un `0,00` la haría leer como un precio.
- **Veintiséis tarjetas en Consultar: veintidós `half` y cuatro `full`** — `Nombre` en la ranura 3 y `Web`, `Descripción` y `Metadatos` al final, que son los campos anchos de verdad. Agregar o quitar un campo obliga a rehacer esa cuenta (§25).
- **La baja va con el modal de desglose** (§15.1): las cuatro FK que apuntan acá son `RESTRICT` y las cuatro **bloquean** — planes (31 filas), chips (20), renglones de comprobantes (3.135) y ítems de carrito (1). No hay nada que se borre en cascada ni que quede sin referencia, así que las dos secciones del modal viajan vacías y sólo se dibuja el recuadro rojo.

---

## 42. Comercial · Planes

ABM de `planes`: lo que un dominio tiene contratado — cuántos cupos puede usar y cuánto paga por eso. `contratos`.`plan` y `utilizaciones`.`plan` cuelgan de acá. Cuelga de **Comercial**, debajo de `Artículos`, con `fa-layer-group`.

Reemplaza a `reactor-admin/planes/` del sistema histórico. El módulo **no aporta componentes nuevos**: sale entero de las piezas ya documentadas.

**Reglas:**

- **EL PRECIO DEL PLAN NO ESTÁ EN ESTA TABLA: está en `articulos`.** La columna `articulo` apunta al artículo que se factura y el abono es su `venta` — es de ahí que lo cobra `cContrato::facturar()`. Por eso el listado y la ficha muestran el abono como un dato **del artículo**, con su id a la vista, con el atajo `Ver artículo` en el menú y con una `.form-nota` en el formulario que dice cuál va a ser el abono y que se cambia en *Artículos*. Poner un campo de precio acá sería inventar un segundo lugar donde vive el mismo número.
- **`-1` SIGNIFICA ILIMITADO en los tres cupos, se guarda tal cual y se muestra traducido.** Es lo que escribe `cPlan::nuevo()` y lo que tienen 31 de los 33 planes en al menos uno. Traducirlo a `NULL` rompería `cPlan::detectar()`, que compara `<= usuarios` contra la columna. Pero **un `-1` en una grilla de cupos se lee como un error de carga**, así que la columna del listado muestra `∞`, la ficha dice `Ilimitado` y el formulario —que sí trabaja con el número, porque es lo que vuelve a la base— lleva la glosa `Ilimitado` en vivo bajo el campo que esté en `-1`, más la regla general al pie del bloque.
- **CADA CUPO TIENE SU PROPIA COLUMNA** — `Usuarios`, `Dispositivos` y `Usos`, las tres numéricas y alineadas a la derecha. Hasta el 30/09/2026 iban apiladas en una sola celda `Usu. / Disp. / Usos` con el tooltip que desplegaba la abreviatura: tres números separados por barras **no se leen en columna** —el ojo no puede comparar el cupo de usuarios de dos planes sin contar las barras primero—, y el encabezado abreviado obligaba a pasar el mouse para saber cuál era cuál. Separadas, el listado ordena y compara como cualquier otra columna numérica y el `∞` queda bajo el rótulo que le corresponde.
- **Un cupo menor que `-1` se rechaza.** No significa nada y `cPlan::detectar()` lo leería como "ningún usuario entra".
- **ONCE FILAS NO TIENEN `tipo`** (cadena vacía): son los planes viejos —Edificio / Ciudad / Casa Inteligente, Anónimo, Reactor Ilimitado—, todos deshabilitados y con contratos todavía colgados (el 118 tiene 9). El select lleva opción `Sin tipo` y acepta además el código heredado aunque no esté en `combos`, igual que `talonarios`.`tipo` con el talonario 35 (ABM.md).
- **`tipo` y `descripcion` vacíos se guardan como cadena vacía, no como `NULL`.** Las 33 filas usan la cadena vacía y ninguna tiene `NULL`: mandar `NULL` estrenaría una segunda forma de decir lo mismo (mismo criterio que `talonarios`.`terminos`).
- **EL ORDEN POR DEFECTO ES `orden` ASCENDENTE**, y no `id` descendente como el resto de los listados. La columna existe justamente para que los planes se lean de menor a mayor capacidad, que es como los ofrece la app — y es el orden del listado del back office viejo (`order by orden, id`).
- **El desplegable de artículos lista TODOS, no sólo los habilitados.** Hay planes vivos cuyo artículo está deshabilitado; con el filtro puesto, abrir uno y guardarlo lo dejaría sin artículo, o sea sin abono. Es el mismo criterio con el que Contratos lista todos los planes. El deshabilitado se marca en la etiqueta (`(deshabilitado)`) y en la ficha con un badge.
- **`Ver contratos` y `Ver artículo` son navegación cruzada y sólo se dibujan cuando hay a dónde ir.** Dejan el pedido en el scope de la app y saltan al listado destino, que lo vuelca en un filtro que **existe como campo de su Modal de Filtros** — `Plan` en Contratos, `Código` en Artículos (allá el artículo es la fila y no una FK, igual que Contratos → "Ver dominio"). Lo resuelve el par genérico `pedirFiltroCampo()` / `tomarFiltroCampo()`, que lleva el **campo** destino con el pedido; los tres pares viejos (`Dominio`, `Usuario`, `Contrato`) siguen existiendo porque están cableados en once módulos.
- **EL ARTÍCULO DE LA FICHA ES UNA PASTILLA CLICKEABLE Y ABRE `Consultar artículo` APILADO ENCIMA** (`.badge.badge-link`, §25), igual que `Usuario` y `Dominio` en Consultar perfil. Es el único campo de esa ficha que lleva a otro lado, así que **es la única píldora azul del modal**: `Tipo`, `Contratos` y `Utilizaciones` pasaron a `badge-success` para que el azul se lea como enlace y no como decoración (§11). Cuatro consecuencias:
  - **El `#id` queda AFUERA de la pastilla**, como el `<code>S</code>` que acompaña al tipo: es el código del artículo, no su nombre, y adentro del botón se leería como parte del rótulo del enlace. La excepción es el artículo **sin nombre**: ahí el `#id` pasa a ser el rótulo del enlace y el `<code>` de al lado se cae, o quedaría `#7 #7`. Es la misma degradación de `badgeFicha()`.
  - **Sin artículo no hay pastilla** — el campo se degrada a `Sin artículo` en `muted`, en vez de dejar un botón que no lleva a ningún lado. Y si el artículo está deshabilitado, el badge `badge-warn` de siempre va **después** de la pastilla.
  - **La ficha del artículo se pide en el momento y NO se cachea.** `openArticuloViewModal()` necesita la fila completa —los cuatro contadores y el recálculo de precios, que sólo arma el GET del listado—, y `CATALOGOS_PLANES.articulos` trae nada más el id y el nombre del desplegable. Guardarse el listado dejaría la ficha mostrando el precio viejo después de editar el artículo en el módulo de al lado; es un click esporádico y la vuelta se paga sola.
  - **No reemplaza al `Ver artículo` del menú `Acciones`, que hace otra cosa**: la pastilla abre la ficha sin salir del plan, el ítem del menú salta al **listado** de Artículos filtrado por ese código. Es el mismo reparto que en Consultar perfil, donde las pastillas abren fichas y el menú `Listar` navega a los módulos.
- **Trece tarjetas en Consultar: diez `half` y tres `full`** — `Nombre` en la ranura 3, `Artículo` y `Descripción` al final. Agregar o quitar un campo obliga a rehacer esa cuenta (§25).
- **La baja va con el modal de desglose** (§15.1): las dos FK que apuntan acá son `RESTRICT` y las dos **bloquean** — contratos (50 filas) y utilizaciones (98).

## 43. Inventario · Dispositivos: asistente de alta ("fabricar")

El `+ Nuevo dispositivo` del listado abre un **asistente de tres pasos**, no el
formulario de 35 columnas de §14. Lo dibuja `openDeviceWizard()` y lo resuelve
`api/dispositivos_accion.php?accion=fabricar`.

Es el porte de `reactor-admin/dispositivos/nuevo.php` — el alta del back office
viejo, que llama a `cDispositivo::crear()`. Ahí el alta pide **cuatro cosas**
(modelo, producto y las tres credenciales) y con eso escribe el dispositivo
**y sus canales**, uno por cada canal que declara el modelo.

**Por qué el alta no es el formulario del ABM.** Es la excepción que `ABM.md`
declara para las entidades con ciclo de vida, y acá se cumple entera:

- **La mitad de lo que se guarda es derivado.** El identificador ES la serie, el
  nombre sale de `modelo | serie`, y el nombre de cada canal sale del nombre del
  dispositivo. Nada de eso se escribe a mano.
- **El alta toca DOS tablas.** Cuántos canales se crean y con qué módulo lo
  decide el modelo elegido, no el formulario. Un alta que crea filas en otra
  tabla no se confirma con un botón `Guardar` y una frase.
- **Las otras 30 columnas son telemetría**, y un equipo recién fabricado no la
  tiene. Ofrecerlas vacías en el alta es pedir que se completen.

**Los tres pasos, y qué contesta cada uno:**

| paso | pregunta | campos |
|---|---|---|
| 1 · Equipo | qué equipo es | `Modelo` *, `Producto` *, `Dominio` |
| 2 · Credenciales | con qué nace | `Serie` *, `Identidad` *, `Llave` * + los dos derivados |
| 3 · Confirmación | qué se va a escribir | nada: es la previsualización del backend |

**Reglas:**

- **El indicador de pasos (`.wizard-steps`, §14-bis) NO es clickeable.** No es una
  variante de `.modal-tabs`: las pestañas se recorren en cualquier orden y acá
  cada paso valida antes de dejar pasar. Saltar al 3 sin modelo mostraría una
  confirmación de nada. Se avanza con los botones de la barra, que validan.
- **La barra de acciones agrega `Atrás` y `Siguiente` a §21-bis**, que es lo
  único que este modal cambia del patrón. Se respeta el resto —salida primero y
  en `btn-ghost`, todo lo demás en `btn-primary`, sin footer— y el avance va a
  la derecha con `.modal-menubar-end`: en un asistente el sentido de la lectura
  **es** la navegación, y `Siguiente` pegado a `Cancelar` los deja a un píxel.
  En el último paso el mismo botón pasa a `Crear dispositivo`.
- **LAS CREDENCIALES LAS GENERA EL SERVIDOR** (`?accion=fabricar&credenciales=1`),
  no el navegador. El largo, el alfabeto (16 mayúsculas, el
  `cCadena::aleatoria(16, '1A')` del legacy) y sobre todo **que la serie no esté
  ya tomada como identificador** son reglas de la base. `Regenerar` vuelve a
  pedirlas. Los campos quedan editables para el equipo que ya viene grabado.
- **El identificador y el nombre son campos derivados**: `readonly`, se recomponen
  en vivo al cambiar la serie o el modelo, y **no viajan en el payload** — los
  calcula el backend. Es la regla de `ABM.md` para `talonarios`.`nombre`.
- **Elegir un modelo muestra sus canales en el acto**, en el paso 1
  (`.wizard-canales`). Vienen ya resueltos en el catálogo y por el **mismo**
  parseo que después usa el `POST`, así que lo que se ve es lo que se escribe.
  Un modelo que dejaría canales sin módulo lo dice ahí con un badge.
- **El paso 3 lo arma el backend, no el front.** `GET ?accion=fabricar&…`
  devuelve el dispositivo derivado, la tabla de canales con su módulo y
  componente, los `avisos` y los `bloqueos`; el `POST` ejecuta lo mismo por la
  misma función. Es el reparto de §15.2 y de `contratos_accion.php`.
- **Un bloqueo esconde `Crear dispositivo`**, y el endpoint lo vuelve a chequear.
  Un aviso se muestra y deja seguir: el caso típico es un modelo cuyos `canalN`
  no nombran un módulo válido — el canal se crea igual porque el equipo físico
  tiene esa entrada, pero con `modulo` en `NULL` y hay que asignárselo después.
- **Agente y transceptor son fijos** (Reactor / Principal, los que hardcodea
  `cDispositivo::nuevo()`) y el asistente **los muestra igual** en el paso 3.
  Un campo ausente sin explicación se lee como un olvido; abajo del dominio hay
  una `.form-nota` que dice que se cambian desde la ficha.
- **El dominio por defecto es `Reactor`, el de stock.** Es lo único que el
  asistente agrega a `nuevo.php`, que lo tenía hardcodeado: acá se puede fabricar
  directo en el dominio del cliente sin pasar por la ficha.
- **Al crear, la ficha se abre sola** — el alta pidió lo mínimo y el resto se
  completa ahí (`ABM.md`).
- **`openDeviceModal()` sigue existiendo** para la edición y para el alta manual
  con las 35 columnas: reparar un dato o cargar un equipo que no responde a
  ningún modelo del catálogo es lo que ese formulario resuelve, y por eso el
  `POST` de `dispositivos.php` no se tocó. **Y tiene entrada en la pantalla** —
  una `.nota-link` al pie del paso 1. Sin ella el asistente le sacaría al
  operador algo que el back office viejo sí permitía, y eso no es reemplazar un
  alta: es recortarla.

---

## Reglas duras (criterios de aceptación)

1. **Ningún color hardcodeado** en el HTML/CSS final. Todo sale de las variables.
2. **Tema único con dos zonas:** chrome (sidebar + topbar) en rojo `#C11313` + resto en grises oscuros. No hay modo claro, no hay toggle de tema, no se usa `data-theme`. Nada fuera del chrome se pinta de rojo sólido — el rojo solo aparece como acento (botones primarios, focus, chips, links).
3. **Una sola acción primaria** por pantalla / modal. El resto secundarias o ghost.
4. **Focus visible rojo** en todos los inputs / selects / textareas (`box-shadow` con el rojo institucional).
5. **Loading** explícito: spinner o `.table-empty` — nunca tabla en blanco sin feedback.
6. **Layout fijo**: sidebar 220px, topbar 60px, content padding 24px.
7. **Densidad**: padding `10–14px` en celdas; gaps `12–20px` entre cards.
8. **Mobile**: `<768px` colapsa sidebar a overlay; grids `form-row*` a una columna.
9. **Sin librerías UI pesadas** (Bootstrap / Tailwind / Material). CSS plano + variables.
10. **Toolbar de listado completa**: búsqueda rápida + `Filtros` + `Refrescar` (sin texto), en ese orden y en los veinte módulos, más el desplegable `Listar` intercalado entre el buscador y `Filtros` en el módulo que tiene filtros rápidos (§9, §12.1). Sale de `abmToolbar()`; ninguno arma el suyo. Refrescar re-renderiza el módulo entero —KPIs incluidos— y conserva los filtros vigentes (§9).
11. **Si dudás, mirá los componentes de arriba antes de crear uno nuevo.**
