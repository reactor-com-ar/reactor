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

## 4. Sidebar

El sidebar está pintado de plano en `var(--primary)` (`#C11313`). Por eso sus elementos hijos **no usan los tokens `--text` / `--muted` / `--border`**: usan `#fff` para texto y `rgba(0,0,0,.18-.28)` para hover / activo / bordes. El contraste se calcula contra el rojo, no contra el gris del resto de la app.

```css
.sidebar       { background: var(--primary);             /* rojo institucional */
                 border-right: 1px solid rgba(0,0,0,.25); }

.sidebar-logo  { height: 60px;                /* coincide con la altura de .topbar */
                 padding: 0 20px; border-bottom: 1px solid rgba(0,0,0,.2);
                 display: flex; align-items: center; justify-content: center; flex-shrink: 0; }
.sidebar-logo-mark  { display: block; width: auto; height: 32px;
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
    <span class="nav-icon">📊</span> Dashboard
  </a>

  <div class="nav-group-wrap" data-group="inventario">
    <button type="button" class="nav-item nav-group-toggle">
      <span class="nav-icon">📦</span>
      <span class="nav-group-label">Inventario</span>
      <span class="nav-group-arrow">+</span>
    </button>
    <div class="nav-sub">
      <a href="#/dispositivos" class="nav-item nav-sub-item">
        <span class="nav-icon">🛰️</span> Dispositivos
      </a>
    </div>
  </div>
</nav>
```

## 5. Topbar

El topbar comparte el rojo institucional con el sidebar (`background: var(--primary)`). Por eso sus hijos siguen la misma regla del §4: `#fff` u opacidades de blanco para texto, negros translúcidos para estados. **No** usar `--text` / `--muted` / `--border` dentro del topbar — esos tokens están calibrados para gris.

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
             placeholder="Buscar UID, nombre, tipo, ubicación…">
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
    <span>📡 Últimas señales</span>
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

- Para nav y headers de cards usar **emojis** (📊 📋 ⚠️ 💬 👥 📦 💰 🛒 🏷️ 🛵 ⚙️ 🔔). Son legibles, no requieren librería y se ven bien tanto sobre el rojo del sidebar como sobre los grises del contenido.
- Para acciones por fila (editar / borrar / ver) usar **FontAwesome 6** (`<i class="fa-solid fa-pencil"></i>`).
- No usar dos sistemas de iconos en el mismo lugar.

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
    <span class="tile-icon">🛰️</span>
    <span class="tile-title">Simulador de señales</span>
    <span class="tile-desc">Genera y envía señales sintéticas para probar la ingesta.</span>
  </button>
  <a href="#/tools/webhooks" class="tile-card">
    <span class="tile-icon">📤</span>
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

## 28. Herramientas: CRUD dentro de modal

Algunas utilidades de **Herramientas** (§27) administran tablas de configuración pequeñas que **no merecen una entrada propia en el sidebar**: la lista es corta, la edita un puñado de usuarios y solo se accede de forma esporádica. En esos casos el tile abre directamente un **modal-gestor** que concentra el ABM completo (listar / buscar / alta / edición / eliminación) sin crear una ruta nueva.

El primer caso vivo es **Parámetros** (tabla `parametros`: `id`, `variable`, `valor`, `comentario`). El tile vive en el `tile-grid` de Herramientas y abre el modal-gestor; éste contiene su propia `toolbar` interna y su `table-card`, y delega alta/edición a un **sub-modal de formulario** y eliminación al `confirm-backdrop` estándar (§15).

```html
<div class="modal-backdrop open">
  <div class="modal modal-wide">
    <div class="modal-header">
      <div class="modal-title">
        Parámetros
        <span class="modal-subtitle">Variables de configuración del sistema</span>
      </div>
      <button class="btn-icon-sm" data-act="close">×</button>
    </div>
    <div class="modal-body">
      <div class="toolbar" style="margin-bottom:14px">
        <div class="toolbar-left">
          <div class="search-wrap">
            <input type="search" class="search-input" placeholder="Buscar variable, valor o comentario…">
            <button type="button" class="search-clear">×</button>
          </div>
        </div>
        <div class="toolbar-right">
          <button class="btn btn-primary btn-sm" data-act="new">
            <i class="fa-solid fa-plus"></i> Nuevo parámetro
          </button>
        </div>
      </div>
      <div class="table-card"> … tabla con Código / Variable / Valor / Comentario + ✏️ 🗑️ … </div>
    </div>
    <div class="modal-footer">
      <button class="btn btn-ghost" data-act="close">Cerrar</button>
    </div>
  </div>
</div>
```

**Reglas:**
- **Modal `.modal-wide`** (760px): la tabla interna necesita espacio para 4 columnas + acciones sin envolver.
- **Toolbar interna reutilizada**: el mismo bloque `.toolbar` / `.toolbar-left` / `.toolbar-right` / `.search-wrap` que un listado ABM normal (§9), pero embebido en `.modal-body`. Búsqueda rápida client-side a la izquierda + botón primario `+ Nuevo <entidad>` a la derecha. El `margin-bottom` reducido (`14px`) compensa el padding propio del modal.
- **Sin botón `Filtros`**: el dataset es chico (decenas de filas) y la búsqueda rápida sobre los campos visibles alcanza. Si en algún caso el volumen creciera, vale promover la herramienta a un módulo ABM real (con ruta + filtros completos) en lugar de complicar el modal.
- **Columnas de acción reducidas a Editar + Eliminar** (`actionCells({ edit: true, delete: true })`): se omite **Consultar** porque en este patrón todos los campos de la fila ya están visibles en la tabla — abrir un modal solo para releerlos sería ruido. Es la única excepción explícita a la regla de §24 de "tres iconos siempre", justificada porque la entidad tiene ≤4 campos cortos.
- **Alta y edición** abren un **segundo modal** estándar (`.modal`, 520px) por encima del gestor, con los campos del registro y footer `Cancelar` / `Guardar`. Al guardar, se cierra solo el formulario y el gestor refresca su lista.
- **Eliminación** usa `confirm-backdrop` (§15) sobre el gestor, igual que cualquier otro ABM.
- **Footer del gestor**: un único botón `Cerrar` (ghost) — no hay acción primaria, porque el ABM se ejerce desde la tabla y el botón `+ Nuevo` de la toolbar interna.
- **Subtítulo en el header** (`.modal-subtitle`) para explicar de qué tabla hablamos en una frase; reemplaza al `module-subtitle` (§23) que aquí no aplica porque no hay header de módulo.
- **Cuándo NO usar este patrón**: si la tabla necesita filtros (estado, dominio, fechas), si crece a cientos de filas, si interactúa con otras entidades, o si justifica métricas en `stat-card`. Esos casos van como módulo ABM regular en el sidebar.

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
