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

```css
:root {
  --bg:        #f4f6fb;
  --surface:   #ffffff;
  --border:    #e2e8f0;
  --primary:   #FFA000;   /* naranja de marca */
  --primary-h: #e08c00;
  --danger:    #ef4444;
  --success:   #22c55e;
  --warn:      #f59e0b;
  --info:      #3b82f6;
  --purple:    #8b5cf6;
  --text:      #1e293b;
  --muted:     #64748b;
  --radius:    10px;
  --shadow:    0 1px 4px rgba(0,0,0,.08);
  --shadow-lg: 0 8px 32px rgba(0,0,0,.12);
  --row-hover: #f1f5f9;
}

[data-theme="dark"] {
  --bg:        #32373D;
  --surface:   #3d4248;
  --border:    #555b63;
  --primary:   #FFA000;
  --primary-h: #e08c00;
  --danger:    #ef4444;
  --success:   #22c55e;
  --warn:      #f59e0b;
  --text:      #f0f0f0;
  --muted:     #aaaaaa;
  --shadow:    0 1px 4px rgba(0,0,0,.4);
  --shadow-lg: 0 8px 32px rgba(0,0,0,.6);
  --row-hover: #4a5058;
}
```

**Reglas:**
- El modo oscuro se activa seteando `data-theme="dark"` en `<html>` o `<body>`. No tocar markup.
- Color de marca: `var(--primary)` (naranja `#FFA000`). Usalo para acciones primarias, focus ring, links activos, hover de nav, "ver más", chips activos.
- Radios: **10px** en cards / inputs / botones (`var(--radius)`), **14px** en modales, **99px** en badges y toasts.
- Sombras: muy sutiles. `var(--shadow)` en cards / topbar, `var(--shadow-lg)` en modales y dropdowns.
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

```css
.sidebar-logo  { padding: 20px 20px 16px; border-bottom: 1px solid var(--border);
                 display: flex; align-items: center; gap: 8px; }
.sidebar-nav   { padding: 0 0 12px; flex: 1; }
.sidebar-footer{ padding: 10px 20px; font-size: .75rem; color: var(--muted);
                 border-top: 1px solid var(--border); text-align: center;
                 letter-spacing: .03em; font-family: monospace; }

.nav-item { display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; font-size: .9rem; color: var(--muted);
            cursor: pointer; border-left: 3px solid transparent;
            transition: background .15s, color .15s; text-decoration: none; }
.nav-item:hover  { background: rgba(255,160,0,.1); color: var(--primary); }
.nav-item.active { background: var(--primary); color: #fff;
                   border-left-color: var(--primary); font-weight: 600; }
.nav-icon { font-size: 1.1rem; width: 20px; text-align: center; }

/* Grupos colapsables */
.nav-group-wrap .nav-sub        { display: none; background: var(--bg); }
.nav-group-wrap.open .nav-sub   { display: block; }
.nav-group-toggle               { justify-content: space-between; }
.nav-group-arrow                { font-size: .75rem; font-weight: 700;
                                  margin-left: auto; transition: transform .2s;
                                  color: var(--muted); }
.nav-group-wrap.open .nav-group-arrow { transform: rotate(45deg); }
.nav-sub-item                   { padding-left: 34px; background: var(--bg); }
```

**Patrón:** logo arriba (36px alto), grupos con flecha `+` que rota 45° al abrir, footer con versión en monospace.

## 5. Topbar

```css
.topbar-title    { font-size: 1rem; font-weight: 600; flex: 1; }
.topbar-user     { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.topbar-username { background: none; border: none; cursor: pointer;
                   font-size: .85rem; color: var(--muted);
                   display: flex; align-items: center; gap: 4px;
                   padding: 6px 10px; border-radius: 8px; transition: background .15s; }
.topbar-username:hover { background: var(--border); }
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
.btn-danger:hover  { background: #dc2626; }
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
  border-color: var(--primary); box-shadow: 0 0 0 3px rgba(255,160,0,.12);
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

Patrón para encabezado de cualquier pantalla con tabla.

```html
<div class="toolbar">
  <div class="toolbar-left">
    <div class="search-wrap">
      <input type="search" class="search-input" placeholder="Buscar…">
      <button class="search-clear">×</button>
    </div>
    <button class="filter-chip active">Todos</button>
    <button class="filter-chip">Activos</button>
    <button class="filter-chip">Inactivos</button>
  </div>
  <div class="toolbar-right">
    <button class="btn btn-ghost">Exportar</button>
    <button class="btn btn-primary">+ Nuevo</button>
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

.filter-chip        { padding: 6px 12px; border-radius: 20px;
                      border: 1.5px solid var(--border);
                      font-size: .8rem; font-weight: 600; cursor: pointer;
                      white-space: nowrap; background: var(--surface);
                      color: var(--muted); transition: all .15s; }
.filter-chip:hover  { border-color: var(--primary); color: var(--primary); }
.filter-chip.active { background: var(--primary); border-color: var(--primary); color: #fff; }
```

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

```css
.badge         { display: inline-block; padding: 2px 10px;
                 border-radius: 99px; font-size: .75rem; font-weight: 600; }
.badge-info    { background: #eff6ff; color: #3b82f6; }
.badge-success { background: #dcfce7; color: #16a34a; }
.badge-danger  { background: #fef2f2; color: #dc2626; }
.badge-warn    { background: #fef9c3; color: #a16207; }
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
.modal-title    { font-size: 1rem; font-weight: 700; }
.modal-body     { padding: 20px 24px; display: flex; flex-direction: column; gap: 16px; }
.modal-footer   { padding: 16px 24px; border-top: 1px solid var(--border);
                  display: flex; gap: 10px; justify-content: flex-end; }
```

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
         background: #1e293b; color: #fff;
         padding: 10px 20px; border-radius: 99px;
         font-size: .88rem; opacity: 0; pointer-events: none;
         transition: opacity .2s, transform .2s;
         z-index: 200; white-space: nowrap; }
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

- Para nav y headers de cards usar **emojis** (📊 📋 ⚠️ 💬 👥 📦 💰 🛒 🏷️ 🛵 ⚙️ 🔔). Son legibles, no requieren librería y respetan dark mode.
- Para acciones por fila (editar / borrar / ver) usar **FontAwesome 6** (`<i class="fa-solid fa-pencil"></i>`).
- No usar dos sistemas de iconos en el mismo lugar.

---

## Reglas duras (criterios de aceptación)

1. **Ningún color hardcodeado** en el HTML/CSS final. Todo sale de las variables.
2. **Dark mode funciona** con solo `data-theme="dark"`, sin tocar markup.
3. **Una sola acción primaria** por pantalla / modal. El resto secundarias o ghost.
4. **Focus visible naranja** en todos los inputs / selects / textareas.
5. **Loading** explícito: spinner o `.table-empty` — nunca tabla en blanco sin feedback.
6. **Layout fijo**: sidebar 220px, topbar 60px, content padding 24px.
7. **Densidad**: padding `10–14px` en celdas; gaps `12–20px` entre cards.
8. **Mobile**: `<768px` colapsa sidebar a overlay; grids `form-row*` a una columna.
9. **Sin librerías UI pesadas** (Bootstrap / Tailwind / Material). CSS plano + variables.
10. **Si dudás, mirá los componentes de arriba antes de crear uno nuevo.**
