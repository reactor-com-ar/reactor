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
.btn-icon-sm       { background: none; border: none; cursor: pointer;
                     padding: 4px 8px; border-radius: 6px; font-size: .85rem; }
.btn-icon-sm:hover { background: var(--bg); }
```

**Regla:** una sola acción primaria por pantalla o modal. El resto son `secondary` o `ghost`. `danger` solo para destruir / eliminar.

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

## 9. Toolbar (filtros + búsqueda + acciones)

Patrón normativo para el encabezado de cualquier listado ABM (ver `ABM.md` §2).

- **Zona izquierda**: input de búsqueda rápida (`.search-wrap > .search-input`) + botón `Filtros` (`btn-secondary` con `fa-filter`). El botón abre el Modal de Filtros (§23-bis), que es la fuente completa de filtros del módulo. La búsqueda rápida es sólo un atajo.
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
```

**Reglas:**
- El `placeholder` del input de búsqueda rápida lista los campos sobre los que opera la búsqueda (UID / nombre / tipo / ubicación, operador / nº / ICCID / notas, etc.).
- El filtrado en vivo se aplica al `input`/`change` event sin re-fetch (filtrado client-side por defecto). Señales y Registros son casos mixtos: filtran client-side sobre la última página descargada, pero re-fetchean cuando cambian los parámetros `?dispositivo=` o `?limit=` server-side.
- El botón `Filtros` es secundario, no primario — la acción primaria del listado es siempre `+ Nuevo <entidad>`, una sola por pantalla (ver §6).
- Si el módulo es read-only (señales, alertas), se omite el botón `+ Nuevo` y la toolbar colapsa a sólo búsqueda rápida + Filtros. El helper `abmToolbar` lo soporta nativamente pasando `newLabel: null`.
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
```

Si la stat-card es clickeable, agregale `.dash-link`:

```css
.dash-link { cursor: pointer; transition: opacity .15s; }
.dash-link:hover { opacity: .75; }
.stat-card.dash-link:hover { background: var(--bg); }
```

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
                  z-index: 100; opacity: 0; pointer-events: none; transition: opacity .2s; }
.modal-backdrop.open { opacity: 1; pointer-events: all; }
.modal          { background: var(--surface); border-radius: 14px;
                  width: 100%; max-width: 520px; max-height: 90vh; overflow-y: auto;
                  box-shadow: var(--shadow-lg);
                  transform: scale(.96) translateY(12px); transition: transform .2s; margin: 16px; }
.modal-backdrop.open .modal { transform: scale(1) translateY(0); }
.modal-header   { padding: 20px 24px 16px; border-bottom: 1px solid var(--border);
                  display: flex; align-items: center; justify-content: space-between; }
.modal-title    { font-size: 1rem; font-weight: 700;
                  display: flex; align-items: baseline; gap: 10px; flex-wrap: wrap; }
.modal-subtitle { font-size: .8rem; font-weight: 500; color: var(--muted); }
.modal-body     { padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-footer   { padding: 16px 24px; border-top: 1px solid var(--border);
                  display: flex; gap: 10px; justify-content: flex-end; }

/* Variante ancha para editores monoespaciados (JSON, logs, etc.). */
.modal.modal-wide { max-width: 760px; }
```

**Variantes:**
- `.modal-wide`: aumenta el `max-width` a 760px. Usar **solo** cuando el contenido sea un editor monoespaciado (JSON, logs, payloads) que necesita ancho real para no envolver — ver §23. Los formularios normales se quedan en el ancho base de 520px.
- `.modal-subtitle`: chip secundario al lado del título (mismo bloque `.modal-title`) para identificar el recurso editado, por ejemplo `Configuración JSON · Nombre · <code>UID</code>`. No reemplaza al título, lo complementa.

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
    <div class="modal-header">
      <div class="modal-title">Filtros</div>
      <button class="btn-icon-sm" data-act="close">×</button>
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
    <div class="modal-footer">
      <button class="btn btn-ghost"     data-act="clear">Limpiar</button>
      <button class="btn btn-secondary" data-act="close">Cancelar</button>
      <button class="btn btn-primary"   data-act="apply">Aplicar</button>
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
- Footer en orden **Limpiar → Cancelar → Aplicar**: ghost / secondary / primary. `Limpiar` solo resetea los campos del modal a sus defaults (no aplica ni cierra). `Cancelar` cierra sin aplicar. `Aplicar` lee los valores, actualiza el estado del listado y cierra el modal.
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
- **Click izquierdo en la fila → acción por defecto (Consultar).** Opt-in por módulo: `<tr class="row-clickable">` (§10) + listener de `click` en la fila que abre el modal de Consultar. El botón hamburguesa hace `stopPropagation()` para no dispararlo. Activo en todos los listados ABM: **Dominios, Dispositivos, Chips, Transceptores, Señales, Registros, Usuarios y Perfiles** (más la solapa Perfiles del modal de Consultar de Usuarios). El click derecho sigue abriendo el menú contextual completo.

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
- El modal de Consultar muestra **todos los campos** de la entidad (no solo los del listado). Es la única vista donde el usuario ve la fila completa sin pasar al modo edición.
- Valores nulos / vacíos van como `<span class="muted">—</span>` o `<span class="muted">Sin descripción</span>` dentro del `view-card-value`.
- JSON / payloads dentro de `<pre>`; no usar `.json-editor` (es para edición, no para read-only).

**Pestañas dentro del modal de Consultar (opcional).** Cuando la entidad tiene relaciones importantes con otras tablas (ej.: usuarios ↔ perfiles), el modal de Consultar puede dividirse en pestañas usando `.modal-tabs` / `.modal-tab` / `.modal-tabpanel`. La primera pestaña se llama siempre **`General`** y contiene el `view-grid` con todos los campos de la entidad; las pestañas siguientes muestran cada relación en una tabla `.table-card` de solo lectura (sin columna `Acciones`, sin menú contextual — las acciones se hacen desde el módulo de la relación, no desde acá). El primer ejemplo es Consultar usuario → `General` + `Perfiles` (lista de dominios y rol por dominio). Cada pestaña de relación lazy-loads su contenido en el primer click al tab correspondiente para no penalizar la apertura del modal.

Las filas de una pestaña de relación son **clickeables** (`<tr class="row-clickable" data-id="…">`, §10): el click izquierdo abre el **modal de Consultar de la entidad relacionada**, apilado encima del modal actual (`.modal-backdrop` comparte `z-index:100`, así que el último montado queda arriba). Al cerrarlo, el modal de origen sigue abierto y con la pestaña activa. Se reutiliza el mismo `openXxxViewModal()` que usa el módulo de la relación — no se duplica el markup de tarjetas. Ejemplo vigente: Consultar usuario → pestaña `Perfiles` → click en una fila → **Consultar perfil**.

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
      <p class="login-subtitle">Ingresá con tu usuario para continuar.</p>
      <form class="login-form" id="login-form">
        <div class="form-group">
          <label for="login-usuario">Usuario</label>
          <input type="text" id="login-usuario" name="usuario" autocomplete="username" autofocus required>
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

**Requisitos de despliegue** (una sola vez al aprovisionar el server, ver `cloud/jobs/crontab`):
1. `cronie` instalado.
2. Extensión PHP `pcntl` habilitada (para el handler de SIGTERM del bootstrap).
3. Directorio `/var/log/reactor/cloud/ejecuciones/` con owner `www-data:www-data` y `chmod 755`.
4. Copiar `cloud/jobs/crontab` a `/etc/cron.d/reactor-cloud` (`chown root:root`, `chmod 644`).

Estos pasos se documentan también en el propio `cloud/jobs/crontab` y quedan pendientes del script de aprovisionar del server (no forman parte del deploy).

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
10. **Si dudás, mirá los componentes de arriba antes de crear uno nuevo.**
