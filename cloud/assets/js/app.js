(() => {
    'use strict';

    /* ---------- DOM refs ---------- */
    const view             = document.getElementById('view');
    const title            = document.getElementById('view-title');
    const navItems         = document.querySelectorAll('.nav-item[data-route]');
    const navGroupToggles  = document.querySelectorAll('.nav-group-toggle');
    const btnRefresh       = document.getElementById('btn-refresh');
    const btnUser          = document.getElementById('btn-user');
    const userDropdown     = document.getElementById('user-dropdown');
    const btnLogout        = document.getElementById('btn-logout');
    const hamburger        = document.getElementById('hamburger');
    const sidebar          = document.getElementById('sidebar');
    const sidebarOverlay   = document.getElementById('sidebar-overlay');
    const toastEl          = document.getElementById('toast');

    let pendingDispositivosDominioFilter = null;
    let pendingSignalsDeviceFilter = null;

    // Cleanup de la vista activa (timers, listeners globales). navigate() lo invoca
    // antes de renderizar la nueva vista; cada render que necesite cleanup lo asigna
    // como función nullable. Hoy lo usa el feed en vivo del dashboard.
    let activeViewCleanup = null;

    /* ---------- Routing ---------- */
    const routes = {
        dashboard:    { title: 'Dashboard',     render: renderDashboard,    group: 'inicio'     },
        dominios:     { title: 'Dominios',      render: renderDominios,     group: 'propiedad'  },
        dispositivos: { title: 'Dispositivos',  render: renderDispositivos, group: 'inventario' },
        chips:        { title: 'Chips',         render: renderChips,        group: 'inventario' },
        transceptores: { title: 'Transceptores', render: renderTransceptores, group: 'inventario' },
        signals:   { title: 'Señales',              render: renderSignals,   group: 'registros'  },
        registros: { title: 'Historial de registros', render: renderRegistros, group: 'registros'  },
        alerts:    { title: 'Alertas',              render: renderStub,      group: 'registros'  },
        users:     { title: 'Usuarios',      render: renderUsers,     group: 'seguridad'  },
        profiles:  { title: 'Perfiles',      render: renderProfiles,  group: 'seguridad'  },
        tools:     { title: 'Herramientas',  render: renderTools,     group: 'administracion' },
    };

    function currentRoute() {
        const hash = window.location.hash.replace('#/', '');
        return routes[hash] ? hash : 'dashboard';
    }

    function navigate() {
        if (typeof activeViewCleanup === 'function') {
            try { activeViewCleanup(); } catch (_) { /* noop */ }
            activeViewCleanup = null;
        }

        const key = currentRoute();
        const route = routes[key];

        title.textContent = route.title;
        navItems.forEach(n => n.classList.toggle('active', n.dataset.route === key));

        if (route.group) openGroup(route.group);
        closeSidebar();

        view.innerHTML = `
            <div class="table-card">
                <div class="table-empty"><div class="spin"></div></div>
            </div>`;
        route.render(view);
    }

    window.addEventListener('hashchange', navigate);
    document.addEventListener('DOMContentLoaded', navigate);

    /* ---------- Sidebar groups ---------- */
    navGroupToggles.forEach(btn => {
        btn.addEventListener('click', () => {
            btn.closest('.nav-group-wrap').classList.toggle('open');
        });
    });

    function openGroup(name) {
        document.querySelectorAll('.nav-group-wrap').forEach(g => {
            if (g.dataset.group === name) g.classList.add('open');
        });
    }

    /* ---------- Topbar ---------- */
    btnRefresh.addEventListener('click', () => { navigate(); toast('Actualizado'); });

    btnUser.addEventListener('click', e => {
        e.stopPropagation();
        userDropdown.classList.toggle('open');
    });
    document.addEventListener('click', e => {
        if (!userDropdown.contains(e.target) && e.target !== btnUser) {
            userDropdown.classList.remove('open');
        }
    });

    if (btnLogout) {
        btnLogout.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await fetch('api/logout.php', {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                });
            } catch (_) { /* aun si falla la llamada, vamos al login */ }
            window.location.href = 'login.php';
        });
    }

    /* ---------- Sidebar (mobile) ---------- */
    hamburger.addEventListener('click', () => {
        sidebar.classList.add('open');
        sidebarOverlay.classList.add('active');
    });
    sidebarOverlay.addEventListener('click', closeSidebar);
    function closeSidebar() {
        sidebar.classList.remove('open');
        sidebarOverlay.classList.remove('active');
    }

    /* ---------- API ---------- */
    async function api(path, opts = {}) {
        const res = await fetch(`api/${path}`, {
            headers: {
                'Accept': 'application/json',
                ...(opts.body ? { 'Content-Type': 'application/json' } : {}),
            },
            method: opts.method || 'GET',
            body: opts.body ? JSON.stringify(opts.body) : undefined,
            credentials: 'same-origin',
        });

        // Sesion expirada o no autenticada → al login.
        if (res.status === 401) {
            window.location.href = 'login.php';
            throw new Error('No autenticado');
        }

        let body;
        try { body = await res.json(); } catch (_) { body = null; }

        if (!res.ok || !body || body.ok === false) {
            const msg = (body && body.error) ? body.error : `HTTP ${res.status}`;
            throw new Error(msg);
        }
        return body.data;
    }

    /* ---------- ABM helpers ---------- */
    // Tarjeta read-only para modal de Consulta (50% del ancho, 2 por fila).
    function viewCardHalf(label, value) {
        return `<div class="view-card view-card-half">
            <div class="view-card-label">${escape(label)}</div>
            <div class="view-card-value">${value}</div>
        </div>`;
    }
    // Tarjeta read-only ancha (100% del ancho) para valores largos.
    function viewCardFull(label, value) {
        return `<div class="view-card view-card-full">
            <div class="view-card-label">${escape(label)}</div>
            <div class="view-card-value">${value}</div>
        </div>`;
    }
    function viewGrid(cards) {
        return `<div class="view-grid">${cards.join('')}</div>`;
    }
    // Celda <th> única "Acciones" al final del listado (ABM.md §1.3).
    function actionHeaderCells() {
        return '<th class="action-col">Acciones</th>';
    }
    // Celda <td> con el botón hamburguesa que dispara el menú contextual.
    // El menú real lo arma cada módulo en wireRowActions vía openRowMenu().
    function actionCells() {
        return `<td class="action-col"><button class="btn-icon-sm" data-act="menu" title="Acciones"><i class="fa-solid fa-bars"></i></button></td>`;
    }

    // Construye los items estándar del menú contextual de fila (ABM.md §1.3).
    // `opts` define qué acciones estándar incluir (view/edit/delete) y permite
    // sumar items extra del módulo. Cada item: { act, label, icon, danger?, onSelect }.
    function standardRowMenuItems(opts) {
        const items = [];
        if (opts.view)   items.push({ act: 'view',   label: 'Consultar', icon: 'fa-eye',    onSelect: opts.onView });
        if (opts.edit)   items.push({ act: 'edit',   label: 'Editar',    icon: 'fa-pencil', onSelect: opts.onEdit });
        if (opts.delete) items.push({ act: 'delete', label: 'Eliminar',  icon: 'fa-trash',  danger: true, onSelect: opts.onDelete });
        if (Array.isArray(opts.extra) && opts.extra.length) {
            if (items.length) items.push({ divider: true });
            opts.extra.forEach(it => items.push(it));
        }
        return items;
    }

    // Menú contextual flotante usado por la columna Acciones y por click
    // derecho sobre filas del listado (ABM.md §1.3). Se posiciona en
    // coordenadas de viewport (position:fixed) y se cierra al click afuera,
    // ESC o scroll. `anchor` puede ser:
    //   - { x, y }                → posiciona en ese punto del viewport
    //   - { rect: DOMRect }       → posiciona debajo y alineado al rect
    //   - Element                 → equivalente a { rect: el.getBoundingClientRect() }
    function openRowMenu(items, anchor) {
        closeRowMenu();
        if (!Array.isArray(items) || !items.length) return;

        const menu = document.createElement('div');
        menu.className = 'row-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = items.map((it, i) => {
            if (it.divider) return `<div class="action-menu-divider"></div>`;
            const cls = 'action-menu-item' + (it.danger ? ' danger' : '');
            // `icon` admite un sufijo simple ("fa-eye") al que se le antepone
            // `fa-solid`, o una clase completa que ya incluye el estilo
            // (ej.: "fa-regular fa-copy", "fa-brands fa-github").
            const iconClass = it.icon
                ? (/\bfa-(solid|regular|brands|light|duotone|thin)\b/.test(it.icon) ? it.icon : `fa-solid ${it.icon}`)
                : '';
            const icon = iconClass ? `<i class="${iconClass}"></i>` : '';
            return `<button type="button" class="${cls}" data-idx="${i}" role="menuitem">${icon} ${escape(it.label)}</button>`;
        }).join('');
        document.body.appendChild(menu);

        // Posicionamiento: a partir de un punto (x,y) o de un rect.
        let x, y;
        if (anchor instanceof Element) {
            const r = anchor.getBoundingClientRect();
            x = r.left;
            y = r.bottom + 4;
        } else if (anchor && anchor.rect) {
            x = anchor.rect.left;
            y = anchor.rect.bottom + 4;
        } else {
            x = (anchor && anchor.x) || 0;
            y = (anchor && anchor.y) || 0;
        }

        // Clampeo a viewport: si se sale por derecha o por abajo, lo flipeo.
        const mw = menu.offsetWidth;
        const mh = menu.offsetHeight;
        const vw = window.innerWidth;
        const vh = window.innerHeight;
        if (x + mw > vw - 8) x = Math.max(8, vw - mw - 8);
        if (y + mh > vh - 8) y = Math.max(8, y - mh - 8);
        menu.style.left = `${x}px`;
        menu.style.top  = `${y}px`;
        requestAnimationFrame(() => menu.classList.add('open'));

        menu.addEventListener('click', e => {
            const btn = e.target.closest('[data-idx]');
            if (!btn) return;
            const it = items[+btn.dataset.idx];
            closeRowMenu();
            if (typeof it?.onSelect === 'function') it.onSelect();
        });
    }

    function closeRowMenu() {
        document.querySelectorAll('.row-menu').forEach(m => m.remove());
    }

    document.addEventListener('click', e => {
        if (!e.target.closest('.row-menu')) closeRowMenu();
    });
    document.addEventListener('keydown', e => {
        if (e.key === 'Escape') closeRowMenu();
    });
    window.addEventListener('scroll', closeRowMenu, true);
    window.addEventListener('resize', closeRowMenu);
    // Lee el valor numérico del campo Límite respetando default 100.
    function readLimit(input, fallback) {
        const v = parseInt(input?.value || '', 10);
        if (!Number.isFinite(v) || v <= 0) return fallback ?? 100;
        return v;
    }

    // Header del módulo (ABM.md §1.1): título de la entidad en plural +
    // subtítulo descriptivo. Va antes de KPIs y toolbar.
    function moduleHeader(title, subtitle) {
        return `
            <div class="module-header">
                <h1 class="module-title">${escape(title)}</h1>
                <p class="module-subtitle">${escape(subtitle)}</p>
            </div>
        `;
    }

    // Toolbar del listado ABM (ABM.md §2):
    //   - Zona izquierda: input de búsqueda rápida + botón Filtros.
    //   - Zona derecha:   botón primario "+ Nuevo <entidad>".
    // `idPrefix` se usa para los ids: `${idPrefix}-quick`, `${idPrefix}-filters`,
    // `${idPrefix}-new`. `quickPlaceholder` lista los campos sobre los que opera.
    // `extraRight` (opcional) inyecta botones secundarios antes de "+ Nuevo"
    // en la zona derecha (ej.: "Monitor en tiempo real" en Señales).
    function abmToolbar({ idPrefix, quickPlaceholder, newLabel, extraRight }) {
        // newLabel = null|false ⇒ módulo read-only (señales, alertas): se omite
        // el botón `+ Nuevo` (ver DESIGN.md §9). El resto del toolbar (búsqueda
        // rápida + Filtros) se mantiene igual.
        const newBtn = newLabel
            ? `<button type="button" class="btn btn-primary btn-sm" id="${idPrefix}-new">
                   <i class="fa-solid fa-plus"></i> ${escape(newLabel)}
               </button>`
            : '';
        const extra = extraRight || '';
        return `
            <div class="toolbar">
                <div class="toolbar-left">
                    <div class="search-wrap">
                        <input type="search" id="${idPrefix}-quick" class="search-input"
                               placeholder="${escape(quickPlaceholder)}">
                        <button type="button" class="search-clear"
                                data-act="quick-clear" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">×</button>
                    </div>
                    <button type="button" class="btn btn-secondary btn-sm" id="${idPrefix}-filters">
                        <i class="fa-solid fa-filter"></i> Filtros
                    </button>
                </div>
                <div class="toolbar-right">${extra}${newBtn}</div>
            </div>
        `;
    }

    // Abre el Modal de Filtros (ABM.md §3). Recibe:
    //   - title:      siempre "Filtros" (lo dejamos parametrizable por las dudas).
    //   - bodyHtml:   HTML de la grilla de campos (form-rows o .filters-grid).
    //                 Las ids deben empezar con `${idPrefix}-fm-…` para evitar choques.
    //   - onApply(modal): callback que lee los campos y aplica los filtros.
    //                     El modal se cierra automáticamente al volver.
    //   - onClear(modal): callback opcional que resetea los campos a defaults.
    //                     Si no se pasa, "Limpiar" no hace nada visual.
    function openFiltersModal({ bodyHtml, onApply, onClear }) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" aria-labelledby="filters-title">
                <div class="modal-header">
                    <div class="modal-title" id="filters-title">Filtros</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"     data-act="clear">Limpiar</button>
                    <button class="btn btn-secondary" data-act="close">Cancelar</button>
                    <button class="btn btn-primary"   data-act="apply">Aplicar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const modal = backdrop.querySelector('.modal');
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
        backdrop.querySelector('[data-act="clear"]').addEventListener('click', () => {
            if (typeof onClear === 'function') onClear(modal);
        });
        backdrop.querySelector('[data-act="apply"]').addEventListener('click', () => {
            onApply(modal);
            close();
        });

        return modal;
    }

    /* ---------- Views: Dashboard ---------- */
    async function renderDashboard(root) {
        try {
            // Dispositivos alimenta las stat-cards de arriba; registros, la card
            // "Últimos registros" del grid. Fetch en paralelo para no encadenar.
            const [data, regData] = await Promise.all([
                api('dispositivos.php'),
                api('registros.php?sentido=S&limit=5'),
            ]);
            const s = data.resumen;
            const ultimosRegistros = (regData.registros || []).slice(0, 5);

            root.innerHTML = `
                <div class="stats-bar">
                    <div class="stat-card dash-link" data-go="dispositivos">
                        <span class="stat-label">Total dispositivos</span>
                        <span class="stat-value">${s.total}</span>
                    </div>
                    <div class="stat-card dash-link" data-go="dispositivos">
                        <span class="stat-label">Online</span>
                        <span class="stat-value green">${s.online}</span>
                    </div>
                    <div class="stat-card dash-link" data-go="dispositivos">
                        <span class="stat-label">Offline</span>
                        <span class="stat-value muted">${s.offline}</span>
                    </div>
                </div>

                <div class="table-card dash-chart-card" id="signals-chart-card">
                    <div class="dash-table-header">
                        <span>📈 Señales por minuto · últimas 24 h</span>
                        <div class="dash-live-controls">
                            <span class="dash-chart-summary" id="signals-chart-summary">
                                <span class="dash-chart-metric">
                                    <span class="dash-chart-metric-label">Total</span>
                                    <strong id="signals-chart-total">—</strong>
                                </span>
                                <span class="dash-chart-metric">
                                    <span class="dash-chart-metric-label">Pico</span>
                                    <strong id="signals-chart-max">—</strong>
                                </span>
                                <span class="dash-chart-metric">
                                    <span class="dash-chart-metric-label">Prom</span>
                                    <strong id="signals-chart-avg">—</strong>
                                </span>
                            </span>
                            <span class="dash-live-status" id="signals-chart-status">
                                <span class="live-dot"></span> En vivo · 1 min
                            </span>
                            <button type="button" class="btn-icon-sm" id="signals-chart-refresh"
                                    title="Refrescar" aria-label="Refrescar gráfico">
                                <i class="fa-solid fa-arrows-rotate"></i>
                            </button>
                        </div>
                    </div>
                    <div class="dash-chart-body" id="signals-chart-body">
                        <div class="dash-chart-empty">Cargando…</div>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="table-card">
                        <div class="dash-table-header">
                            <span>📋 Últimos registros</span>
                            <div class="dash-live-controls">
                                <button type="button" class="btn-icon-sm" id="reg-card-refresh"
                                        title="Refrescar" aria-label="Refrescar registros">
                                    <i class="fa-solid fa-arrows-rotate"></i>
                                </button>
                                <a href="#/registros" class="dash-ver-mas">Ver todos →</a>
                            </div>
                        </div>
                        <div id="reg-card-body">${registrosDashboardTableBody(ultimosRegistros)}</div>
                    </div>

                    <div class="table-card dash-live-card" id="live-feed-card">
                        <div class="dash-table-header">
                            <span>📡 Últimas señales</span>
                            <div class="dash-live-controls">
                                <span class="dash-live-status" id="live-feed-status">
                                    <span class="live-dot"></span> En vivo · 500 ms
                                </span>
                                <button type="button" class="btn-icon-sm" id="live-feed-toggle"
                                        title="Pausar" aria-label="Pausar feed">
                                    <i class="fa-solid fa-pause"></i>
                                </button>
                                <a href="#/signals" class="dash-ver-mas">Ver todas →</a>
                            </div>
                        </div>
                        <div id="live-feed-body"><div class="table-empty">Esperando señales…</div></div>
                    </div>
                </div>
            `;

            root.querySelectorAll('[data-go]').forEach(el => {
                el.addEventListener('click', () => { window.location.hash = '#/' + el.dataset.go; });
            });

            // Refresh local de la card "Últimos registros": re-fetch sólo de
            // ese endpoint y re-render del body, sin tocar el resto del
            // dashboard ni cortar el feed en vivo. Auto-refresca cada 15 s
            // y también responde al click manual del botón.
            const btnRegRefresh = document.getElementById('reg-card-refresh');
            const regCardBody   = document.getElementById('reg-card-body');
            let regRefreshTimer = null;
            if (btnRegRefresh && regCardBody) {
                const refreshRegistrosCard = async () => {
                    if (btnRegRefresh.disabled) return;
                    const icon = btnRegRefresh.querySelector('i');
                    btnRegRefresh.disabled = true;
                    icon?.classList.add('fa-spin');
                    try {
                        const fresh = await api('registros.php?sentido=S&limit=5');
                        regCardBody.innerHTML = registrosDashboardTableBody(
                            (fresh.registros || []).slice(0, 5)
                        );
                    } catch (err) {
                        toast('No se pudo refrescar registros: ' + err.message, 'error');
                    } finally {
                        icon?.classList.remove('fa-spin');
                        btnRegRefresh.disabled = false;
                    }
                };
                btnRegRefresh.addEventListener('click', refreshRegistrosCard);
                regRefreshTimer = setInterval(refreshRegistrosCard, 15_000);
            }

            const liveCleanup  = startDashboardLiveFeed();
            const chartCleanup = startDashboardSignalsChart();
            activeViewCleanup = () => {
                if (regRefreshTimer) clearInterval(regRefreshTimer);
                liveCleanup();
                chartCleanup();
            };
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    /* Gráfico "Señales por minuto · últimas 24 h" (dashboard).
     *
     * Polling a `signals_stats.php` cada 1 min. La ventana es móvil: el
     * servidor devuelve 1440 buckets de 1 minuto anclados al minuto en
     * curso, así que basta con re-render completo del SVG sin lógica
     * incremental. SVG inline (no librería) — una polilínea fluida + área
     * bajo la curva, con grid, eje Y dinámico y ticks X cada 4 h. A esta
     * densidad no se renderizan puntos individuales ni hit-area per
     * minuto: la línea actúa como sparkline y el header concentra las
     * métricas (Total/Pico/Prom).
     *
     * Devuelve cleanup() que apaga el timer; lo consume `activeViewCleanup`
     * al navegar a otra ruta. */
    function startDashboardSignalsChart() {
        const card = document.getElementById('signals-chart-card');
        if (!card) return () => {};

        const body    = card.querySelector('#signals-chart-body');
        const status  = card.querySelector('#signals-chart-status');
        const btnReload = card.querySelector('#signals-chart-refresh');
        const elTotal = card.querySelector('#signals-chart-total');
        const elMax   = card.querySelector('#signals-chart-max');
        const elAvg   = card.querySelector('#signals-chart-avg');

        const TICK_MS = 60_000;
        let fetching  = false;
        let lastError = false;

        function setStatus(text, paused) {
            status.innerHTML = `<span class="live-dot"></span> ${escape(text)}`;
            card.classList.toggle('live-paused', !!paused);
        }

        function renderChart(data) {
            const buckets = data.buckets || [];
            if (!buckets.length) {
                body.innerHTML = `<div class="dash-chart-empty">Sin datos</div>`;
                return;
            }

            elTotal.textContent = String(data.total ?? 0);
            elMax.textContent   = String(data.max   ?? 0);
            elAvg.textContent   = (data.avg ?? 0).toString().replace('.', ',');

            // SVG con viewBox: escala fluido al ancho del contenedor.
            const W = 1200, H = 220;
            const padL = 36, padR = 12, padT = 14, padB = 26;
            const innerW = W - padL - padR;
            const innerH = H - padT - padB;
            const n = buckets.length;          // 1440
            const slot   = innerW / n;

            // Escala Y: redondear el max hacia arriba a un "lindo" tope.
            const rawMax = Math.max(1, data.max ?? 0);
            const yMax   = niceCeil(rawMax);

            // 4 líneas horizontales de grid (0, 1/3, 2/3, max).
            const gridYs = [0, 1/3, 2/3, 1].map(f => ({
                v: Math.round(yMax * f),
                y: padT + innerH - innerH * f,
            }));

            const gridLines = gridYs.map(g => `
                <line class="dash-chart-grid" x1="${padL}" x2="${W - padR}"
                      y1="${g.y}" y2="${g.y}"></line>
                <text class="dash-chart-axis-label" x="${padL - 6}" y="${g.y + 3}"
                      text-anchor="end">${g.v}</text>
            `).join('');

            // Puntos: un vértice por bucket, centrado en su slot. A 1440
            // puntos sobre ~1150 px de ancho los círculos individuales
            // (y la hit-area por minuto) saturan el render — sólo se
            // dibuja la polilínea y el área.
            const pts = buckets.map((b, i) => {
                const c = b.count || 0;
                const x = padL + i * slot + slot / 2;
                const y = padT + innerH - (c / yMax) * innerH;
                return { x, y };
            });

            const lineD = pts.map((p, i) =>
                `${i === 0 ? 'M' : 'L'} ${p.x.toFixed(2)} ${p.y.toFixed(2)}`
            ).join(' ');

            // Área bajo la línea: misma trayectoria + cierre al eje X.
            const baseY = padT + innerH;
            const areaD = `${lineD} L ${pts[n-1].x.toFixed(2)} ${baseY} `
                        + `L ${pts[0].x.toFixed(2)} ${baseY} Z`;

            // Eje X: ticks en los minutos cerrados que caen en
            // 00:00 / 04:00 / 08:00 / 12:00 / 16:00 / 20:00 dentro de la
            // ventana de 24 h. Los índices exactos dependen de qué
            // minuto sea ahora, así que se descubren escaneando los
            // buckets.
            const tickHours = new Set([0, 4, 8, 12, 16, 20]);
            const xAxis = buckets.map((b, i) => {
                const colonIdx = b.minuto.indexOf(':');
                if (colonIdx < 0) return '';
                const mm = b.minuto.slice(colonIdx + 1);
                if (mm !== '00') return '';
                const hh = parseInt(b.minuto.slice(0, colonIdx), 10);
                if (!tickHours.has(hh)) return '';
                const cx = padL + i * slot + slot / 2;
                return `<text class="dash-chart-axis-label" x="${cx.toFixed(2)}"
                              y="${H - 8}" text-anchor="middle">${escape(b.minuto)}</text>`;
            }).join('');

            body.innerHTML = `
                <svg class="dash-chart-svg" viewBox="0 0 ${W} ${H}"
                     preserveAspectRatio="none" role="img"
                     aria-label="Señales por minuto en las últimas 24 horas">
                    ${gridLines}
                    <path class="dash-chart-area" d="${areaD}"></path>
                    <path class="dash-chart-line" d="${lineD}"></path>
                    ${xAxis}
                </svg>
            `;
        }

        function niceCeil(v) {
            if (v <= 1)  return 1;
            if (v <= 5)  return 5;
            if (v <= 10) return 10;
            const pow = Math.pow(10, Math.floor(Math.log10(v)));
            const n   = v / pow;
            let nice;
            if      (n <= 1.5) nice = 1.5;
            else if (n <= 2)   nice = 2;
            else if (n <= 3)   nice = 3;
            else if (n <= 5)   nice = 5;
            else               nice = 10;
            return Math.ceil(nice * pow);
        }

        async function tick(manual) {
            if (!document.body.contains(card)) return;
            if (fetching) return;
            fetching = true;
            const icon = btnReload?.querySelector('i');
            if (manual && icon) icon.classList.add('fa-spin');
            try {
                const data = await api('signals_stats.php');
                renderChart(data);
                if (lastError) { setStatus('En vivo · 1 min', false); lastError = false; }
            } catch (err) {
                lastError = true;
                setStatus('Error · reintentando', true);
            } finally {
                fetching = false;
                if (manual && icon) icon.classList.remove('fa-spin');
            }
        }

        btnReload?.addEventListener('click', () => tick(true));

        tick(false);
        const intervalId = setInterval(() => tick(false), TICK_MS);

        return function cleanup() {
            clearInterval(intervalId);
        };
    }

    /* Feed en vivo del dashboard (cada 500 ms).
     *
     * Mantiene un buffer de las últimas 20 señales y poll-ea
     * `signals_live.php?since_id=…` para traer sólo las nuevas. Pausa
     * automáticamente al pasar el mouse sobre la card; el botón
     * pausa/play permite congelar el feed manualmente para inspeccionar
     * una señal. Devuelve una función de cleanup que apaga el timer —
     * la consume `activeViewCleanup` al navegar a otra vista. */
    function startDashboardLiveFeed() {
        const card = document.getElementById('live-feed-card');
        if (!card) return () => {};

        const body   = card.querySelector('#live-feed-body');
        const toggle = card.querySelector('#live-feed-toggle');
        const status = card.querySelector('#live-feed-status');

        const MAX_ROWS    = 5;
        const TICK_MS     = 500;
        let buffer        = [];
        let maxId         = 0;
        let userPaused    = false;
        let hoverPaused   = false;
        let fetching      = false;
        let pendingRender = false;

        const isPaused = () => userPaused || hoverPaused;

        function updateStatus() {
            if (userPaused) {
                status.innerHTML = '<span class="live-dot"></span> Pausado';
                card.classList.add('live-paused');
            } else if (hoverPaused) {
                status.innerHTML = '<span class="live-dot"></span> En pausa (hover)';
                card.classList.add('live-paused');
            } else {
                status.innerHTML = '<span class="live-dot"></span> En vivo · 500 ms';
                card.classList.remove('live-paused');
            }
        }

        function renderRows(highlightIds) {
            if (!buffer.length) {
                body.innerHTML = `<div class="table-empty">Esperando señales…</div>`;
                return;
            }
            const highlight = new Set(highlightIds || []);
            const rows = buffer.map(s => `
                <tr${highlight.has(s.id) ? ' class="is-new"' : ''} data-id="${s.id}">
                    <td>
                        <div class="td-id">${escape(formatDateOnly(s.fecha))}</div>
                        <div class="td-id">${escape(formatTime(s.fecha))}</div>
                    </td>
                    <td>
                        <div class="td-nombre">${escape(s.dispositivo_nombre ?? '—')}</div>
                    </td>
                    <td>${sentidoLiveIcon(s.sentido)}</td>
                    <td>${s.mensaje != null && s.mensaje !== '' ? escape(s.mensaje) : '<span class="td-id">—</span>'}</td>
                </tr>
            `).join('');
            body.innerHTML = `
                <table class="live-feed-table">
                    <thead>
                        <tr>
                            <th>Hora</th>
                            <th>Dispositivo</th>
                            <th>Sentido</th>
                            <th>Mensaje</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            `;
        }

        async function tick() {
            if (!document.body.contains(card)) return;  // ya navegamos a otra vista
            if (fetching || isPaused()) return;
            fetching = true;
            try {
                const qs = new URLSearchParams();
                qs.set('since_id', String(maxId));
                qs.set('limit',    String(MAX_ROWS));
                const data = await api('signals_live.php?' + qs.toString());

                if (data.last_id > maxId) maxId = data.last_id;
                if (data.senales && data.senales.length) {
                    // signals_live.php devuelve DESC (nuevas primero);
                    // buffer ya está DESC -> concat preserva orden.
                    const newIds = data.senales.map(s => s.id);
                    buffer = data.senales.concat(buffer).slice(0, MAX_ROWS);
                    renderRows(newIds);
                } else if (pendingRender) {
                    renderRows([]);
                    pendingRender = false;
                }
            } catch (_) {
                // Silencioso: con polling de 500 ms un error transitorio se
                // recupera en el próximo tick. Si pasara a ser persistente
                // habría que mostrarlo en el status.
            } finally {
                fetching = false;
            }
        }

        toggle.addEventListener('click', () => {
            userPaused = !userPaused;
            toggle.innerHTML = userPaused
                ? '<i class="fa-solid fa-play"></i>'
                : '<i class="fa-solid fa-pause"></i>';
            toggle.title      = userPaused ? 'Reanudar' : 'Pausar';
            toggle.setAttribute('aria-label', toggle.title + ' feed');
            updateStatus();
        });
        card.addEventListener('mouseenter', () => { hoverPaused = true;  updateStatus(); });
        card.addEventListener('mouseleave', () => { hoverPaused = false; updateStatus(); });

        updateStatus();
        pendingRender = true;
        tick();
        const intervalId = setInterval(tick, TICK_MS);

        return function cleanup() {
            clearInterval(intervalId);
        };
    }

    /* ---------- Views: Dispositivos ---------- */
    const ORDEN_DISPOSITIVOS = [
        { value: 'id',           label: 'Código'          },
        { value: 'last_seen_at', label: 'Última conexión' },
        { value: 'nombre',       label: 'Nombre'          },
        { value: 'uid',          label: 'UID'             },
        { value: 'tipo',         label: 'Tipo'            },
        { value: 'estado',       label: 'Estado'          },
        { value: 'created_at',   label: 'Creado'          },
    ];
    const ESTADOS_DISPOSITIVO_FILTRO = [
        { value: 'online',  label: 'Online'      },
        { value: 'offline', label: 'Offline'     },
        { value: 'error',   label: 'Con error'   },
    ];

    function dispositivosDefaults() {
        return {
            codigo: '', texto: '', dominio: '', estado: '',
            orden:  'last_seen_at', dir: 'desc', limit: 100,
        };
    }

    async function renderDispositivos(root) {
        try {
            const [data, domData] = await Promise.all([
                api('dispositivos.php'),
                api('dominios.php'),
            ]);
            const dispositivos = data.dispositivos;
            const dominios     = domData.dominios;

            const state = dispositivosDefaults();
            if (pendingDispositivosDominioFilter != null) {
                state.dominio = String(pendingDispositivosDominioFilter);
                pendingDispositivosDominioFilter = null;
            }

            root.innerHTML = `
                ${moduleHeader('Dispositivos', 'Inventario de dispositivos conectados a la plataforma, su dominio asignado y su última actividad.')}
                ${abmToolbar({
                    idPrefix:         'dev',
                    quickPlaceholder: 'Buscar UID, nombre, tipo, ubicación…',
                    newLabel:         'Nuevo dispositivo',
                })}
                <div class="table-card" id="dev-table"></div>
            `;

            wireDevicesView(state, dispositivos, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function dispositivosTableBody(dispositivos) {
        if (!dispositivos.length) {
            return `<div class="table-empty">No hay dispositivos para mostrar.</div>`;
        }

        const rows = dispositivos.map(d => `
            <tr data-id="${d.id}">
                <td><span class="td-id">#${d.id}</span></td>
                <td><span class="td-id">${escape(d.uid)}</span></td>
                <td class="td-nombre">${escape(d.nombre)}</td>
                <td>${escape(d.tipo)}</td>
                <td><span class="badge badge-info">${escape(d.dominio_nombre)}</span></td>
                <td>${escape(d.ubicacion ?? '—')}</td>
                <td>${statusBadge(d.estado)}</td>
                <td>${formatDate(d.last_seen_at)}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>UID</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Dominio</th>
                        <th>Ubicación</th>
                        <th>Estado</th>
                        <th>Última conexión</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function statusBadge(status) {
        const map = {
            online:  { cls: 'badge-success', label: 'Online'  },
            offline: { cls: 'badge-warn',    label: 'Offline' },
            error:   { cls: 'badge-danger',  label: 'Error'   },
        };
        const m = map[status] || { cls: 'badge-info', label: status };
        return `<span class="badge ${m.cls}">${escape(m.label)}</span>`;
    }

    function wireDevicesView(state, allDispositivos, allDominios) {
        const tableWrap = document.getElementById('dev-table');
        const quick     = document.getElementById('dev-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('dev-filters');
        const btnNew    = document.getElementById('dev-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allDispositivos.filter(d => {
                if (Number.isFinite(codigo) && d.id !== codigo) return false;
                if (state.estado  && d.estado !== state.estado) return false;
                if (state.dominio && String(d.dominio_id) !== state.dominio) return false;
                if (q && !(d.uid + ' ' + d.nombre + ' ' + d.tipo + ' ' + (d.dominio_nombre || '') + ' ' + (d.ubicacion || ''))
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = dispositivosTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(d) {
            return standardRowMenuItems({
                view:     true, onView:   () => openDeviceViewModal(d),
                edit:     true, onEdit:   () => openDeviceModal(d, allDominios),
                delete:   true, onDelete: () => confirmDeleteDevice(d),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const d  = allDispositivos.find(x => x.id === id);
                if (!d) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(d), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(d), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => {
            state.texto = quick.value.trim();
            applyAndRender();
        });
        quickClr.addEventListener('click', () => {
            quick.value = '';
            state.texto = '';
            applyAndRender();
            quick.focus();
        });

        btnFilt.addEventListener('click', () => openDevicesFiltersModal(state, allDominios, applyAndRender));
        btnNew.addEventListener('click',  () => openDeviceModal(null, allDominios));

        applyAndRender();
    }

    function openDevicesFiltersModal(state, allDominios, onApply) {
        const domOpts = ['<option value="">Todos los dominios</option>'].concat(
            allDominios.map(d => `<option value="${d.id}"${String(d.id) === state.dominio ? ' selected' : ''}>${escape(d.nombre)}</option>`)
        ).join('');
        const estOpts = ['<option value="">Todos los estados</option>'].concat(
            ESTADOS_DISPOSITIVO_FILTRO.map(e =>
                `<option value="${e.value}"${e.value === state.estado ? ' selected' : ''}>${escape(e.label)}</option>`
            )
        ).join('');
        const ordOpts = ORDEN_DISPOSITIVOS.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="dev-fm-codigo">Código</label>
                    <input type="number" id="dev-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="dev-fm-texto">Buscar (UID / nombre / tipo / ubicación)</label>
                    <input type="search" id="dev-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="dev-fm-dominio">Dominio</label>
                    <select id="dev-fm-dominio">${domOpts}</select>
                </div>
                <div class="form-group">
                    <label for="dev-fm-estado">Estado</label>
                    <select id="dev-fm-estado">${estOpts}</select>
                </div>
                <div class="form-group">
                    <label for="dev-fm-limit">Límite</label>
                    <input type="number" id="dev-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="dev-fm-orden">Ordenar por</label>
                    <select id="dev-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="dev-fm-dir">Dirección</label>
                    <select id="dev-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo  = modal.querySelector('#dev-fm-codigo').value.trim();
                state.texto   = modal.querySelector('#dev-fm-texto').value.trim();
                state.dominio = modal.querySelector('#dev-fm-dominio').value;
                state.estado  = modal.querySelector('#dev-fm-estado').value;
                state.orden   = modal.querySelector('#dev-fm-orden').value;
                state.dir     = modal.querySelector('#dev-fm-dir').value;
                state.limit   = readLimit(modal.querySelector('#dev-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = dispositivosDefaults();
                modal.querySelector('#dev-fm-codigo').value  = d.codigo;
                modal.querySelector('#dev-fm-texto').value   = d.texto;
                modal.querySelector('#dev-fm-dominio').value = d.dominio;
                modal.querySelector('#dev-fm-estado').value  = d.estado;
                modal.querySelector('#dev-fm-orden').value   = d.orden;
                modal.querySelector('#dev-fm-dir').value     = d.dir;
                modal.querySelector('#dev-fm-limit').value   = String(d.limit);
            },
        });
    }

    function openDeviceViewModal(dev) {
        const cfgStr = dev.config_json
            ? (typeof dev.config_json === 'object'
                ? JSON.stringify(dev.config_json, null, 2)
                : String(dev.config_json))
            : '';
        const cfgValue = cfgStr
            ? `<pre>${escape(cfgStr)}</pre>`
            : `<span class="muted">Sin configuración</span>`;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar dispositivo</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',           `<code>#${dev.id}</code>`),
                        viewCardHalf('UID',              `<code>${escape(dev.uid)}</code>`),
                        viewCardHalf('Nombre',           escape(dev.nombre)),
                        viewCardHalf('Tipo',             escape(dev.tipo)),
                        viewCardHalf('Dominio',          `<span class="badge badge-info">${escape(dev.dominio_nombre)}</span>`),
                        viewCardHalf('Estado',           statusBadge(dev.estado)),
                        viewCardHalf('Ubicación',        dev.ubicacion ? escape(dev.ubicacion) : `<span class="muted">—</span>`),
                        viewCardHalf('Última conexión',  escape(formatDate(dev.last_seen_at))),
                        viewCardHalf('Creado',           escape(formatDate(dev.created_at))),
                        viewCardFull('Configuración (JSON)', cfgValue),
                    ])}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    const ESTADOS_DISPOSITIVO = [
        { value: 'online',  label: 'Online'  },
        { value: 'offline', label: 'Offline' },
        { value: 'error',   label: 'Error'   },
    ];

    function confirmDeleteDevice(dev) {
        confirmDialog(
            'Eliminar dispositivo',
            `¿Eliminar el dispositivo ${dev.nombre} (UID ${dev.uid})? Se borrarán también sus señales asociadas. Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('dispositivos.php?id=' + dev.id, { method: 'DELETE' });
                    toast('Dispositivo eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    function openDeviceModal(dev, allDominios) {
        const isEdit = !!dev;

        const domOpts = (isEdit ? '' : '<option value="">Elegí un dominio…</option>') +
            allDominios.map(d =>
                `<option value="${d.id}" ${dev?.dominio_id === d.id ? 'selected' : ''}>${escape(d.nombre)}</option>`
            ).join('');

        const estadoOpts = ESTADOS_DISPOSITIVO.map(e =>
            `<option value="${e.value}" ${(dev?.estado ?? 'offline') === e.value ? 'selected' : ''}>${escape(e.label)}</option>`
        ).join('');

        const initialJson = dev?.config_json
            ? JSON.stringify(dev.config_json, null, 2)
            : '';

        const titleSubtitle = isEdit
            ? `<span class="modal-subtitle">${escape(dev.nombre)} · <code>${escape(dev.uid)}</code></span>`
            : '';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">
                        ${isEdit ? 'Editar dispositivo' : 'Nuevo dispositivo'}
                        ${titleSubtitle}
                    </div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-dominio">Dominio</label>
                            <select id="dev-dominio">${domOpts}</select>
                            <div class="field-error" id="dev-dominio-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="dev-estado">Estado</label>
                            <select id="dev-estado">${estadoOpts}</select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="dev-uid">UID</label>
                            <input type="text" id="dev-uid" maxlength="64"
                                   value="${escape(dev?.uid ?? '')}"
                                   placeholder="RX-0001" required>
                            <div class="field-error" id="dev-uid-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="dev-tipo">Tipo</label>
                            <input type="text" id="dev-tipo" maxlength="60"
                                   value="${escape(dev?.tipo ?? '')}"
                                   placeholder="temperature / humidity / actuator…" required>
                            <div class="field-error" id="dev-tipo-err" style="display:none"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="dev-nombre">Nombre</label>
                        <input type="text" id="dev-nombre" maxlength="120"
                               value="${escape(dev?.nombre ?? '')}" required>
                        <div class="field-error" id="dev-nombre-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="dev-ubicacion">Ubicación</label>
                        <input type="text" id="dev-ubicacion" maxlength="120"
                               value="${escape(dev?.ubicacion ?? '')}"
                               placeholder="Opcional">
                    </div>
                    <div class="form-group">
                        <label for="dev-config-json">
                            Configuración (JSON libre — vacío equivale a limpiar; la estructura la valida el firmware)
                        </label>
                        <textarea id="dev-config-json"
                                  class="json-editor"
                                  spellcheck="false"
                                  autocomplete="off"
                                  placeholder='{ "channels": [] }'>${escape(initialJson)}</textarea>
                        <div class="field-error" id="dev-config-err" style="display:none"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="format" style="margin-right:auto">
                        <i class="fa-solid fa-wand-magic-sparkles"></i> Formatear JSON
                    </button>
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear dispositivo'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const domSel      = backdrop.querySelector('#dev-dominio');
        const estadoSel   = backdrop.querySelector('#dev-estado');
        const uidInput    = backdrop.querySelector('#dev-uid');
        const tipoInput   = backdrop.querySelector('#dev-tipo');
        const nombreInput = backdrop.querySelector('#dev-nombre');
        const ubicInput   = backdrop.querySelector('#dev-ubicacion');
        const editor      = backdrop.querySelector('#dev-config-json');
        const domErr      = backdrop.querySelector('#dev-dominio-err');
        const uidErr      = backdrop.querySelector('#dev-uid-err');
        const tipoErr     = backdrop.querySelector('#dev-tipo-err');
        const nombreErr   = backdrop.querySelector('#dev-nombre-err');
        const cfgErr      = backdrop.querySelector('#dev-config-err');
        const formatBtn   = backdrop.querySelector('[data-act="format"]');
        const saveBtn     = backdrop.querySelector('[data-act="save"]');

        function parseEditor() {
            const raw = editor.value.trim();
            if (raw === '') return { ok: true, value: null };
            try { return { ok: true, value: JSON.parse(raw) }; }
            catch (e) { return { ok: false, error: e.message }; }
        }
        function clearCfgError() {
            cfgErr.style.display = 'none';
            editor.classList.remove('input-invalid');
        }
        function showCfgError(msg) {
            cfgErr.textContent = msg;
            cfgErr.style.display = 'block';
            editor.classList.add('input-invalid');
        }

        editor.addEventListener('input', clearCfgError);
        (isEdit ? nombreInput : domSel).focus();
        if (isEdit) nombreInput.select();

        formatBtn.addEventListener('click', () => {
            const r = parseEditor();
            if (!r.ok) { showCfgError('No se puede formatear: ' + r.error); return; }
            editor.value = r.value === null ? '' : JSON.stringify(r.value, null, 2);
            clearCfgError();
        });

        saveBtn.addEventListener('click', async () => {
            [domErr, uidErr, tipoErr, nombreErr].forEach(el => el.style.display = 'none');
            [domSel, uidInput, tipoInput, nombreInput].forEach(el => el.classList.remove('input-invalid'));
            clearCfgError();

            const uid        = uidInput.value.trim();
            const dominio_id = +domSel.value;
            const nombre     = nombreInput.value.trim();
            const tipo       = tipoInput.value.trim();
            const ubicacion  = ubicInput.value.trim();
            const estado     = estadoSel.value;

            let firstInvalid = null;
            if (!dominio_id) {
                domErr.textContent = 'Elegí un dominio';
                domErr.style.display = 'block';
                domSel.classList.add('input-invalid');
                firstInvalid = firstInvalid || domSel;
            }
            if (!uid) {
                uidErr.textContent = 'El UID es obligatorio';
                uidErr.style.display = 'block';
                uidInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || uidInput;
            }
            if (!nombre) {
                nombreErr.textContent = 'El nombre es obligatorio';
                nombreErr.style.display = 'block';
                nombreInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || nombreInput;
            }
            if (!tipo) {
                tipoErr.textContent = 'El tipo es obligatorio';
                tipoErr.style.display = 'block';
                tipoInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || tipoInput;
            }

            const r = parseEditor();
            if (!r.ok) {
                showCfgError('JSON inválido: ' + r.error);
                firstInvalid = firstInvalid || editor;
            }
            if (firstInvalid) { firstInvalid.focus(); return; }

            const payload = { uid, dominio_id, nombre, tipo, ubicacion, estado, config_json: r.value };

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('dispositivos.php', { method: 'PUT', body: { id: dev.id, ...payload } });
                    toast('Dispositivo actualizado');
                } else {
                    await api('dispositivos.php', { method: 'POST', body: payload });
                    toast('Dispositivo creado');
                }
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    /* ---------- Views: Chips (SIM) ---------- */
    const ESTADOS_CHIP = [
        { value: 'activo',     label: 'Activo',     badge: 'badge-success' },
        { value: 'inactivo',   label: 'Inactivo',   badge: 'badge-warn'    },
        { value: 'suspendido', label: 'Suspendido', badge: 'badge-danger'  },
    ];

    const ORDEN_CHIPS = [
        { value: 'id',         label: 'Código'      },
        { value: 'operador',   label: 'Operador'    },
        { value: 'numero',     label: 'Número'      },
        { value: 'iccid',      label: 'ICCID'       },
        { value: 'estado',     label: 'Estado'      },
        { value: 'created_at', label: 'Creado'      },
    ];

    function chipsDefaults() {
        return {
            codigo: '', texto: '', dominio: '', estado: '',
            orden:  'id', dir: 'desc', limit: 100,
        };
    }

    async function renderChips(root) {
        try {
            const [data, domData] = await Promise.all([
                api('chips.php'),
                api('dominios.php'),
            ]);
            const r = data.resumen;
            const dominios = domData.dominios;
            const chips    = data.chips;

            const state = chipsDefaults();

            root.innerHTML = `
                ${moduleHeader('Chips', 'Líneas SIM disponibles para los dispositivos: estado, dominio asignado y datos del operador.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Activos</span>
                        <span class="stat-value green">${r.activo}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Inactivos</span>
                        <span class="stat-value orange">${r.inactivo}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Suspendidos</span>
                        <span class="stat-value red">${r.suspendido}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'chip',
                    quickPlaceholder: 'Buscar operador, número, ICCID, notas…',
                    newLabel:         'Nuevo chip',
                })}
                <div class="table-card" id="chip-table"></div>
            `;

            wireChipsView(state, chips, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function chipsTableBody(chips) {
        if (!chips.length) {
            return `<div class="table-empty">Todavía no hay chips cargados. Creá el primero con "Nuevo chip".</div>`;
        }

        const rows = chips.map(c => `
            <tr data-id="${c.id}">
                <td><span class="td-id">#${c.id}</span></td>
                <td class="td-nombre">${escape(c.operador)}</td>
                <td>${escape(c.numero)}</td>
                <td><span class="td-id">${escape(c.iccid)}</span></td>
                <td><span class="badge badge-info">${escape(c.dominio_nombre)}</span></td>
                <td>${c.apn ? escape(c.apn) : '<span class="td-id">—</span>'}</td>
                <td>${c.plan ? escape(c.plan) : '<span class="td-id">—</span>'}</td>
                <td>${chipEstadoBadge(c.estado)}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Operador</th>
                        <th>Número</th>
                        <th>ICCID</th>
                        <th>Dominio</th>
                        <th>APN</th>
                        <th>Plan</th>
                        <th>Estado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function chipEstadoBadge(estado) {
        const e = ESTADOS_CHIP.find(x => x.value === estado) || { label: estado, badge: 'badge-info' };
        return `<span class="badge ${e.badge}">${escape(e.label)}</span>`;
    }

    function wireChipsView(state, allChips, allDominios) {
        const tableWrap = document.getElementById('chip-table');
        const quick     = document.getElementById('chip-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('chip-filters');
        const btnNew    = document.getElementById('chip-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allChips.filter(c => {
                if (Number.isFinite(codigo) && c.id !== codigo) return false;
                if (state.estado  && c.estado !== state.estado) return false;
                if (state.dominio && String(c.dominio_id) !== state.dominio) return false;
                if (q && !(c.numero + ' ' + c.iccid + ' ' + c.operador + ' ' +
                           (c.apn || '') + ' ' + (c.plan || '') + ' ' + (c.notas || ''))
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = chipsTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(c) {
            return standardRowMenuItems({
                view:     true, onView:   () => openChipViewModal(c),
                edit:     true, onEdit:   () => openChipModal(c, allDominios),
                delete:   true, onDelete: () => confirmDeleteChip(c),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const c  = allChips.find(x => x.id === id);
                if (!c) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(c), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(c), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () => openChipsFiltersModal(state, allDominios, applyAndRender));
        btnNew.addEventListener('click',  () => openChipModal(null, allDominios));

        applyAndRender();
    }

    function openChipsFiltersModal(state, allDominios, onApply) {
        const domOpts = ['<option value="">Todos los dominios</option>'].concat(
            allDominios.map(d => `<option value="${d.id}"${String(d.id) === state.dominio ? ' selected' : ''}>${escape(d.nombre)}</option>`)
        ).join('');
        const estOpts = ['<option value="">Todos los estados</option>'].concat(
            ESTADOS_CHIP.map(e =>
                `<option value="${e.value}"${e.value === state.estado ? ' selected' : ''}>${escape(e.label)}</option>`
            )
        ).join('');
        const ordOpts = ORDEN_CHIPS.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="chip-fm-codigo">Código</label>
                    <input type="number" id="chip-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="chip-fm-texto">Buscar (operador / nº / ICCID / notas)</label>
                    <input type="search" id="chip-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="chip-fm-dominio">Dominio</label>
                    <select id="chip-fm-dominio">${domOpts}</select>
                </div>
                <div class="form-group">
                    <label for="chip-fm-estado">Estado</label>
                    <select id="chip-fm-estado">${estOpts}</select>
                </div>
                <div class="form-group">
                    <label for="chip-fm-limit">Límite</label>
                    <input type="number" id="chip-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="chip-fm-orden">Ordenar por</label>
                    <select id="chip-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="chip-fm-dir">Dirección</label>
                    <select id="chip-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo  = modal.querySelector('#chip-fm-codigo').value.trim();
                state.texto   = modal.querySelector('#chip-fm-texto').value.trim();
                state.dominio = modal.querySelector('#chip-fm-dominio').value;
                state.estado  = modal.querySelector('#chip-fm-estado').value;
                state.orden   = modal.querySelector('#chip-fm-orden').value;
                state.dir     = modal.querySelector('#chip-fm-dir').value;
                state.limit   = readLimit(modal.querySelector('#chip-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = chipsDefaults();
                modal.querySelector('#chip-fm-codigo').value  = d.codigo;
                modal.querySelector('#chip-fm-texto').value   = d.texto;
                modal.querySelector('#chip-fm-dominio').value = d.dominio;
                modal.querySelector('#chip-fm-estado').value  = d.estado;
                modal.querySelector('#chip-fm-orden').value   = d.orden;
                modal.querySelector('#chip-fm-dir').value     = d.dir;
                modal.querySelector('#chip-fm-limit').value   = String(d.limit);
            },
        });
    }

    function openChipViewModal(chip) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar chip</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',   `<code>#${chip.id}</code>`),
                        viewCardHalf('Operador', escape(chip.operador)),
                        viewCardHalf('Número',   escape(chip.numero)),
                        viewCardHalf('ICCID',    `<code>${escape(chip.iccid)}</code>`),
                        viewCardHalf('Dominio',  `<span class="badge badge-info">${escape(chip.dominio_nombre)}</span>`),
                        viewCardHalf('Estado',   chipEstadoBadge(chip.estado)),
                        viewCardHalf('APN',      chip.apn  ? escape(chip.apn)  : `<span class="muted">—</span>`),
                        viewCardHalf('Plan',     chip.plan ? escape(chip.plan) : `<span class="muted">—</span>`),
                        viewCardHalf('Creado',   escape(formatDate(chip.created_at))),
                        viewCardHalf('Actualizado', escape(formatDate(chip.updated_at))),
                        viewCardFull('Notas',    chip.notas ? escape(chip.notas) : `<span class="muted">Sin notas</span>`),
                    ])}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    function openChipModal(chip, allDominios) {
        const isEdit = !!chip;

        const domOpts = ['<option value="">Elegí un dominio…</option>'].concat(
            allDominios.map(d =>
                `<option value="${d.id}" ${chip?.dominio_id === d.id ? 'selected' : ''}>${escape(d.nombre)}</option>`
            )
        ).join('');

        const estadoOpts = ESTADOS_CHIP.map(e =>
            `<option value="${e.value}" ${(chip?.estado ?? 'activo') === e.value ? 'selected' : ''}>${escape(e.label)}</option>`
        ).join('');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar chip' : 'Nuevo chip'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="chip-dominio">Dominio</label>
                            <select id="chip-dominio">${domOpts}</select>
                            <div class="field-error" id="chip-dominio-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="chip-estado">Estado</label>
                            <select id="chip-estado">${estadoOpts}</select>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="chip-numero">Número de línea</label>
                            <input type="text" id="chip-numero" maxlength="30"
                                   value="${escape(chip?.numero ?? '')}"
                                   placeholder="+54 9 11 1234-5678" required>
                            <div class="field-error" id="chip-numero-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="chip-operador">Operador</label>
                            <input type="text" id="chip-operador" maxlength="60"
                                   value="${escape(chip?.operador ?? '')}"
                                   placeholder="Movistar / Claro / Personal" required>
                            <div class="field-error" id="chip-operador-err" style="display:none"></div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="chip-iccid">ICCID</label>
                        <input type="text" id="chip-iccid" maxlength="22"
                               value="${escape(chip?.iccid ?? '')}"
                               placeholder="18 a 22 dígitos" required>
                        <div class="field-error" id="chip-iccid-err" style="display:none"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="chip-apn">APN</label>
                            <input type="text" id="chip-apn" maxlength="120"
                                   value="${escape(chip?.apn ?? '')}"
                                   placeholder="Opcional">
                        </div>
                        <div class="form-group">
                            <label for="chip-plan">Plan</label>
                            <input type="text" id="chip-plan" maxlength="120"
                                   value="${escape(chip?.plan ?? '')}"
                                   placeholder="Opcional">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="chip-notas">Notas</label>
                        <textarea id="chip-notas" maxlength="500" placeholder="Opcional">${escape(chip?.notas ?? '')}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear chip'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const domInput      = backdrop.querySelector('#chip-dominio');
        const estadoSel     = backdrop.querySelector('#chip-estado');
        const numeroInput   = backdrop.querySelector('#chip-numero');
        const operadorInput = backdrop.querySelector('#chip-operador');
        const iccidInput    = backdrop.querySelector('#chip-iccid');
        const apnInput      = backdrop.querySelector('#chip-apn');
        const planInput     = backdrop.querySelector('#chip-plan');
        const notasInput    = backdrop.querySelector('#chip-notas');
        const domErr        = backdrop.querySelector('#chip-dominio-err');
        const numeroErr     = backdrop.querySelector('#chip-numero-err');
        const operadorErr   = backdrop.querySelector('#chip-operador-err');
        const iccidErr      = backdrop.querySelector('#chip-iccid-err');
        const saveBtn       = backdrop.querySelector('[data-act="save"]');

        (isEdit ? estadoSel : domInput).focus();

        saveBtn.addEventListener('click', async () => {
            const dominio_id = +domInput.value;
            const numero     = numeroInput.value.trim();
            const operador   = operadorInput.value.trim();
            const iccid      = iccidInput.value.trim();
            const apn        = apnInput.value.trim();
            const plan       = planInput.value.trim();
            const estado     = estadoSel.value;
            const notas      = notasInput.value.trim();

            [domErr, numeroErr, operadorErr, iccidErr].forEach(el => el.style.display = 'none');
            [domInput, numeroInput, operadorInput, iccidInput].forEach(el => el.classList.remove('input-invalid'));

            let firstInvalid = null;
            if (!dominio_id) {
                domErr.textContent = 'Elegí un dominio';
                domErr.style.display = 'block';
                domInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || domInput;
            }
            if (!numero) {
                numeroErr.textContent = 'El número es obligatorio';
                numeroErr.style.display = 'block';
                numeroInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || numeroInput;
            }
            if (!operador) {
                operadorErr.textContent = 'El operador es obligatorio';
                operadorErr.style.display = 'block';
                operadorInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || operadorInput;
            }
            if (!/^[0-9]{18,22}$/.test(iccid)) {
                iccidErr.textContent = 'El ICCID debe tener entre 18 y 22 dígitos numéricos';
                iccidErr.style.display = 'block';
                iccidInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || iccidInput;
            }
            if (firstInvalid) { firstInvalid.focus(); return; }

            const payload = { dominio_id, numero, operador, iccid, apn, plan, estado, notas };

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('chips.php', { method: 'PUT', body: { id: chip.id, ...payload } });
                    toast('Chip actualizado');
                } else {
                    await api('chips.php', { method: 'POST', body: payload });
                    toast('Chip creado');
                }
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function confirmDeleteChip(chip) {
        confirmDialog(
            'Eliminar chip',
            `¿Eliminar el chip ${chip.operador} ${chip.numero} (ICCID ${chip.iccid})? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('chips.php?id=' + chip.id, { method: 'DELETE' });
                    toast('Chip eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    /* ---------- Views: Transceptores ---------- */
    const ORDEN_TRANSCEPTORES = [
        { value: 'id',     label: 'Código'  },
        { value: 'nombre', label: 'Nombre'  },
        { value: 'host',   label: 'Host'    },
        { value: 'puerto', label: 'Puerto'  },
    ];

    function transceptoresDefaults() {
        return {
            codigo: '', texto: '',
            orden:  'id', dir: 'desc', limit: 100,
        };
    }

    async function renderTransceptores(root) {
        try {
            const data = await api('transceptores.php');
            const r = data.resumen;
            const transceptores = data.transceptores;
            const state = transceptoresDefaults();

            root.innerHTML = `
                ${moduleHeader('Transceptores', 'Gateways de mensajería (host, puerto y credenciales) que reciben y entregan señales hacia los dispositivos.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Con credenciales</span>
                        <span class="stat-value green">${r.con_credenciales}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Con señales</span>
                        <span class="stat-value">${r.con_senales}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'trx',
                    quickPlaceholder: 'Buscar nombre, host, usuario, entrada…',
                    newLabel:         'Nuevo transceptor',
                })}
                <div class="table-card" id="trx-table"></div>
            `;

            wireTransceptoresView(state, transceptores);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function transceptoresTableBody(transceptores) {
        if (!transceptores.length) {
            return `<div class="table-empty">Todavía no hay transceptores cargados. Creá el primero con "Nuevo transceptor".</div>`;
        }

        const rows = transceptores.map(t => `
            <tr data-id="${t.id}">
                <td><span class="td-id">#${t.id}</span></td>
                <td class="td-nombre">${escape(t.nombre ?? '—')}</td>
                <td>${escape(t.host ?? '—')}</td>
                <td>${escape(t.puerto ?? '—')}</td>
                <td>${t.usuario ? escape(t.usuario) : '<span class="td-id">—</span>'}</td>
                <td>${t.entrada ? `<code>${escape(t.entrada)}</code>` : '<span class="td-id">—</span>'}</td>
                <td><span class="badge badge-info">${t.senales_count}</span></td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Host</th>
                        <th>Puerto</th>
                        <th>Usuario</th>
                        <th>Entrada</th>
                        <th>Señales</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function wireTransceptoresView(state, allTransceptores) {
        const tableWrap = document.getElementById('trx-table');
        const quick     = document.getElementById('trx-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('trx-filters');
        const btnNew    = document.getElementById('trx-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allTransceptores.filter(t => {
                if (Number.isFinite(codigo) && t.id !== codigo) return false;
                if (q && !((t.nombre || '') + ' ' + (t.host || '') + ' ' +
                           (t.usuario || '') + ' ' + (t.entrada || ''))
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = transceptoresTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(t) {
            return standardRowMenuItems({
                view:     true, onView:   () => openTransceptorViewModal(t),
                edit:     true, onEdit:   () => openTransceptorModal(t),
                delete:   true, onDelete: () => confirmDeleteTransceptor(t),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const t  = allTransceptores.find(x => x.id === id);
                if (!t) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(t), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(t), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () => openTransceptoresFiltersModal(state, applyAndRender));
        btnNew.addEventListener('click',  () => openTransceptorModal(null));

        applyAndRender();
    }

    function openTransceptoresFiltersModal(state, onApply) {
        const ordOpts = ORDEN_TRANSCEPTORES.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="trx-fm-codigo">Código</label>
                    <input type="number" id="trx-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="trx-fm-texto">Buscar (nombre / host / usuario / entrada)</label>
                    <input type="search" id="trx-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="trx-fm-limit">Límite</label>
                    <input type="number" id="trx-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="trx-fm-orden">Ordenar por</label>
                    <select id="trx-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="trx-fm-dir">Dirección</label>
                    <select id="trx-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo = modal.querySelector('#trx-fm-codigo').value.trim();
                state.texto  = modal.querySelector('#trx-fm-texto').value.trim();
                state.orden  = modal.querySelector('#trx-fm-orden').value;
                state.dir    = modal.querySelector('#trx-fm-dir').value;
                state.limit  = readLimit(modal.querySelector('#trx-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = transceptoresDefaults();
                modal.querySelector('#trx-fm-codigo').value = d.codigo;
                modal.querySelector('#trx-fm-texto').value  = d.texto;
                modal.querySelector('#trx-fm-orden').value  = d.orden;
                modal.querySelector('#trx-fm-dir').value    = d.dir;
                modal.querySelector('#trx-fm-limit').value  = String(d.limit);
            },
        });
    }

    function openTransceptorViewModal(t) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        const usuarioVal = t.usuario
            ? escape(t.usuario)
            : `<span class="muted">Sin usuario</span>`;
        const entradaVal = t.entrada
            ? `<code>${escape(t.entrada)}</code>`
            : `<span class="muted">Sin entrada</span>`;
        const passVal = t.tiene_contrasena
            ? `<code>••••••••</code> <span class="muted">(oculta)</span>`
            : `<span class="muted">Sin contraseña</span>`;
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar transceptor</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',      `<code>#${t.id}</code>`),
                        viewCardHalf('Nombre',      escape(t.nombre ?? '—')),
                        viewCardHalf('Host',        escape(t.host ?? '—')),
                        viewCardHalf('Puerto',      escape(t.puerto ?? '—')),
                        viewCardHalf('Usuario',     usuarioVal),
                        viewCardHalf('Contraseña',  passVal),
                        viewCardHalf('Entrada',     entradaVal),
                        viewCardHalf('Señales asociadas', `<span class="badge badge-info">${t.senales_count}</span>`),
                    ])}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    function openTransceptorModal(t) {
        const isEdit = !!t;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar transceptor' : 'Nuevo transceptor'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="trx-nombre">Nombre</label>
                        <input type="text" id="trx-nombre" maxlength="255"
                               value="${escape(t?.nombre ?? '')}"
                               placeholder="Gateway principal" required>
                        <div class="field-error" id="trx-nombre-err" style="display:none"></div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="trx-host">Host</label>
                            <input type="text" id="trx-host" maxlength="255"
                                   value="${escape(t?.host ?? '')}"
                                   placeholder="mqtt.reactor.local" required>
                            <div class="field-error" id="trx-host-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="trx-puerto">Puerto</label>
                            <input type="number" id="trx-puerto" min="1" max="65535"
                                   value="${escape(t?.puerto ?? '')}"
                                   placeholder="1883" required>
                            <div class="field-error" id="trx-puerto-err" style="display:none"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="trx-usuario">Usuario</label>
                            <input type="text" id="trx-usuario" maxlength="255"
                                   value="${escape(t?.usuario ?? '')}"
                                   placeholder="Opcional">
                        </div>
                        <div class="form-group">
                            <label for="trx-contrasena">Contraseña ${isEdit && t.tiene_contrasena ? '<span class="muted" style="font-weight:400">(dejar vacío para no cambiar)</span>' : ''}</label>
                            <input type="password" id="trx-contrasena" maxlength="255"
                                   value=""
                                   placeholder="${isEdit && t.tiene_contrasena ? '••••••••' : 'Opcional'}"
                                   autocomplete="new-password">
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="trx-entrada">Entrada</label>
                        <input type="text" id="trx-entrada" maxlength="255"
                               value="${escape(t?.entrada ?? '')}"
                               placeholder="Topic / cola de entrada (opcional)">
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear transceptor'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const nombreInput     = backdrop.querySelector('#trx-nombre');
        const hostInput       = backdrop.querySelector('#trx-host');
        const puertoInput     = backdrop.querySelector('#trx-puerto');
        const usuarioInput    = backdrop.querySelector('#trx-usuario');
        const contrasenaInput = backdrop.querySelector('#trx-contrasena');
        const entradaInput    = backdrop.querySelector('#trx-entrada');
        const nombreErr       = backdrop.querySelector('#trx-nombre-err');
        const hostErr         = backdrop.querySelector('#trx-host-err');
        const puertoErr       = backdrop.querySelector('#trx-puerto-err');
        const saveBtn         = backdrop.querySelector('[data-act="save"]');

        nombreInput.focus();

        saveBtn.addEventListener('click', async () => {
            const nombre     = nombreInput.value.trim();
            const host       = hostInput.value.trim();
            const puerto     = puertoInput.value.trim();
            const usuario    = usuarioInput.value.trim();
            const contrasena = contrasenaInput.value;
            const entrada    = entradaInput.value.trim();

            [nombreErr, hostErr, puertoErr].forEach(el => el.style.display = 'none');
            [nombreInput, hostInput, puertoInput].forEach(el => el.classList.remove('input-invalid'));

            let firstInvalid = null;
            if (!nombre) {
                nombreErr.textContent = 'El nombre es obligatorio';
                nombreErr.style.display = 'block';
                nombreInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || nombreInput;
            }
            if (!host) {
                hostErr.textContent = 'El host es obligatorio';
                hostErr.style.display = 'block';
                hostInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || hostInput;
            }
            const puertoNum = parseInt(puerto, 10);
            if (!puerto || !Number.isFinite(puertoNum) || puertoNum < 1 || puertoNum > 65535) {
                puertoErr.textContent = 'El puerto debe ser un número entre 1 y 65535';
                puertoErr.style.display = 'block';
                puertoInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || puertoInput;
            }
            if (firstInvalid) { firstInvalid.focus(); return; }

            const payload = { nombre, host, puerto, usuario, contrasena, entrada };

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('transceptores.php', { method: 'PUT', body: { id: t.id, ...payload } });
                    toast('Transceptor actualizado');
                } else {
                    await api('transceptores.php', { method: 'POST', body: payload });
                    toast('Transceptor creado');
                }
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function confirmDeleteTransceptor(t) {
        const refNote = t.senales_count > 0
            ? ` No se podrá eliminar mientras tenga ${t.senales_count} señal(es) asociada(s).`
            : '';
        confirmDialog(
            'Eliminar transceptor',
            `¿Eliminar el transceptor "${t.nombre ?? '#' + t.id}" (${t.host ?? '—'}:${t.puerto ?? '—'})?${refNote} Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('transceptores.php?id=' + t.id, { method: 'DELETE' });
                    toast('Transceptor eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    /* ---------- Views: Dominios ---------- */
    const ORDEN_DOMINIOS = [
        { value: 'id',         label: 'Código'      },
        { value: 'nombre',     label: 'Nombre'      },
        { value: 'created_at', label: 'Creado'      },
    ];

    function dominiosDefaults() {
        return {
            codigo: '', texto: '',
            orden:  'id', dir: 'desc', limit: 100,
        };
    }

    async function renderDominios(root) {
        try {
            const data = await api('dominios.php');
            const dominios = data.dominios;
            const state = dominiosDefaults();

            root.innerHTML = `
                ${moduleHeader('Dominios', 'Espacios lógicos que agrupan dispositivos, chips y perfiles de acceso.')}
                ${abmToolbar({
                    idPrefix:         'dom',
                    quickPlaceholder: 'Buscar nombre o descripción…',
                    newLabel:         'Nuevo dominio',
                })}
                <div class="table-card" id="dom-table"></div>
            `;

            wireDomainsView(state, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function dominiosTableBody(dominios) {
        if (!dominios.length) {
            return `<div class="table-empty">Todavía no hay dominios. Creá el primero con "Nuevo dominio".</div>`;
        }

        const rows = dominios.map(d => `
            <tr data-id="${d.id}">
                <td><span class="td-id">#${d.id}</span></td>
                <td class="td-nombre">${escape(d.nombre)}</td>
                <td>${escape(d.descripcion ?? '—')}</td>
                <td><span class="badge badge-info">${d.dispositivos_count}</span></td>
                <td>${formatDate(d.created_at)}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Dispositivos</th>
                        <th>Creado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function wireDomainsView(state, allDominios) {
        const tableWrap = document.getElementById('dom-table');
        const quick     = document.getElementById('dom-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('dom-filters');
        const btnNew    = document.getElementById('dom-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allDominios.filter(d => {
                if (Number.isFinite(codigo) && d.id !== codigo) return false;
                if (q && !(d.nombre + ' ' + (d.descripcion || '')).toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = dominiosTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(dom) {
            return standardRowMenuItems({
                view:   true, onView:   () => openDomainViewModal(dom),
                edit:   true, onEdit:   () => openDomainModal(dom),
                delete: true, onDelete: () => confirmDeleteDomain(dom),
                extra: [
                    { act: 'go-devices', label: 'Ver dispositivos asociados', icon: 'fa-satellite-dish',
                      onSelect: () => { pendingDispositivosDominioFilter = dom.id; window.location.hash = '#/dispositivos'; } },
                    { act: 'copy-id',    label: 'Copiar ID',     icon: 'fa-hashtag',     onSelect: () => copyToClipboard(String(dom.id)) },
                    { act: 'copy-name',  label: 'Copiar nombre', icon: 'fa-regular fa-copy', onSelect: () => copyToClipboard(dom.nombre) },
                ],
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id  = +tr.dataset.id;
                const dom = allDominios.find(x => x.id === id);
                if (!dom) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(dom), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(dom), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () => openDominiosFiltersModal(state, applyAndRender));
        btnNew.addEventListener('click',  () => openDomainModal(null));

        applyAndRender();
    }

    function openDominiosFiltersModal(state, onApply) {
        const ordOpts = ORDEN_DOMINIOS.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="dom-fm-codigo">Código</label>
                    <input type="number" id="dom-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="dom-fm-texto">Buscar (nombre / descripción)</label>
                    <input type="search" id="dom-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="dom-fm-limit">Límite</label>
                    <input type="number" id="dom-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="dom-fm-orden">Ordenar por</label>
                    <select id="dom-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="dom-fm-dir">Dirección</label>
                    <select id="dom-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo = modal.querySelector('#dom-fm-codigo').value.trim();
                state.texto  = modal.querySelector('#dom-fm-texto').value.trim();
                state.orden  = modal.querySelector('#dom-fm-orden').value;
                state.dir    = modal.querySelector('#dom-fm-dir').value;
                state.limit  = readLimit(modal.querySelector('#dom-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = dominiosDefaults();
                modal.querySelector('#dom-fm-codigo').value = d.codigo;
                modal.querySelector('#dom-fm-texto').value  = d.texto;
                modal.querySelector('#dom-fm-orden').value  = d.orden;
                modal.querySelector('#dom-fm-dir').value    = d.dir;
                modal.querySelector('#dom-fm-limit').value  = String(d.limit);
            },
        });
    }

    function openDomainModal(dom) {
        const isEdit = !!dom;
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar dominio' : 'Nuevo dominio'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="dom-name">Nombre</label>
                        <input type="text" id="dom-name" maxlength="120" value="${escape(dom?.nombre ?? '')}" required>
                        <div class="field-error" id="dom-name-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="dom-desc">Descripción</label>
                        <textarea id="dom-desc" maxlength="255" placeholder="Opcional">${escape(dom?.descripcion ?? '')}</textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear dominio'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const nameInput = backdrop.querySelector('#dom-name');
        const descInput = backdrop.querySelector('#dom-desc');
        const nameErr   = backdrop.querySelector('#dom-name-err');
        const saveBtn   = backdrop.querySelector('[data-act="save"]');

        nameInput.focus();

        saveBtn.addEventListener('click', async () => {
            const nombre      = nameInput.value.trim();
            const descripcion = descInput.value.trim();

            nameErr.style.display = 'none';
            nameInput.classList.remove('input-invalid');

            if (!nombre) {
                nameErr.textContent = 'El nombre es obligatorio';
                nameErr.style.display = 'block';
                nameInput.classList.add('input-invalid');
                nameInput.focus();
                return;
            }

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('dominios.php', { method: 'PUT', body: { id: dom.id, nombre, descripcion } });
                    toast('Dominio actualizado');
                } else {
                    await api('dominios.php', { method: 'POST', body: { nombre, descripcion } });
                    toast('Dominio creado');
                }
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function openDomainViewModal(dom) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        const descValue = dom.descripcion
            ? escape(dom.descripcion)
            : `<span class="muted">Sin descripción</span>`;
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar dominio</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',                 `<code>#${dom.id}</code>`),
                        viewCardHalf('Nombre',                 escape(dom.nombre)),
                        viewCardHalf('Dispositivos asociados', `<span class="badge badge-info">${dom.dispositivos_count}</span>`),
                        viewCardHalf('Creado',                 escape(formatDate(dom.created_at))),
                        viewCardHalf('Última actualización',   escape(formatDate(dom.updated_at))),
                        viewCardFull('Descripción',            descValue),
                    ])}
                </div>
                <div class="modal-footer">
                    <div class="action-menu action-menu-up" id="dom-view-menu" style="margin-right:auto">
                        <button class="btn btn-secondary" data-act="menu-toggle">
                            <i class="fa-solid fa-ellipsis"></i> Acciones
                        </button>
                        <div class="action-menu-dropdown" role="menu">
                            <button class="action-menu-item" data-act="edit" role="menuitem">
                                <i class="fa-solid fa-pencil"></i> Editar dominio
                            </button>
                            <button class="action-menu-item" data-act="go-devices" role="menuitem">
                                <i class="fa-solid fa-satellite-dish"></i> Ver dispositivos asociados
                            </button>
                            <button class="action-menu-item" data-act="copy-id" role="menuitem">
                                <i class="fa-solid fa-hashtag"></i> Copiar ID
                            </button>
                            <button class="action-menu-item" data-act="copy-name" role="menuitem">
                                <i class="fa-regular fa-copy"></i> Copiar nombre
                            </button>
                            <div class="action-menu-divider"></div>
                            <button class="action-menu-item danger" data-act="delete" role="menuitem">
                                <i class="fa-solid fa-trash"></i> Eliminar dominio
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const menu       = backdrop.querySelector('#dom-view-menu');
        const menuToggle = menu.querySelector('[data-act="menu-toggle"]');

        menuToggle.addEventListener('click', e => {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        backdrop.addEventListener('click', e => {
            if (!menu.contains(e.target)) menu.classList.remove('open');
        });

        menu.querySelectorAll('.action-menu-item').forEach(item => {
            item.addEventListener('click', () => {
                menu.classList.remove('open');
                const act = item.dataset.act;
                if (act === 'edit') {
                    close();
                    openDomainModal(dom);
                } else if (act === 'go-devices') {
                    close();
                    pendingDispositivosDominioFilter = dom.id;
                    window.location.hash = '#/dispositivos';
                } else if (act === 'copy-id') {
                    copyToClipboard(String(dom.id));
                } else if (act === 'copy-name') {
                    copyToClipboard(dom.nombre);
                } else if (act === 'delete') {
                    close();
                    confirmDeleteDomain(dom);
                }
            });
        });
    }

    function confirmDeleteDomain(dom) {
        const reassignNote = dom.dispositivos_count > 0
            ? ` Sus ${dom.dispositivos_count} dispositivo(s) asociado(s) (y los chips, si los hubiera) se reasignarán al dominio "General". Los perfiles de acceso a este dominio se eliminarán.`
            : ` Los perfiles de acceso a este dominio se eliminarán (los chips asociados, si los hubiera, se reasignarán a "General").`;

        confirmDialog(
            'Eliminar dominio',
            `¿Eliminar el dominio "${dom.nombre}"?` + reassignNote + ' Esta acción no se puede deshacer.',
            async () => {
                try {
                    await api('dominios.php?id=' + dom.id, { method: 'DELETE' });
                    toast('Dominio eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    function confirmDialog(title, message, onConfirm) {
        const backdrop = document.createElement('div');
        backdrop.className = 'confirm-backdrop';
        backdrop.innerHTML = `
            <div class="confirm-box">
                <div class="confirm-title">${escape(title)}</div>
                <div class="confirm-msg">${escape(message)}</div>
                <div class="confirm-actions">
                    <button class="btn btn-ghost" data-act="cancel">Cancelar</button>
                    <button class="btn btn-danger" data-act="ok">Eliminar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 150);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelector('[data-act="cancel"]').addEventListener('click', close);
        backdrop.querySelector('[data-act="ok"]').addEventListener('click', () => {
            close();
            onConfirm();
        });
    }

    /* ---------- Views: Usuarios ---------- */
    const ROLES_USER = [
        { value: 'admin',    label: 'Administrador', badge: 'badge-danger' },
        { value: 'operador', label: 'Operador',      badge: 'badge-info'   },
        { value: 'lectura',  label: 'Solo lectura',  badge: 'badge-warn'   },
    ];

    const ORDEN_USUARIOS = [
        { value: 'id',            label: 'Código'        },
        { value: 'nombre',        label: 'Nombre'        },
        { value: 'email',         label: 'Email'         },
        { value: 'rol',           label: 'Rol'           },
        { value: 'last_login_at', label: 'Último login'  },
        { value: 'created_at',    label: 'Creado'        },
    ];

    function usuariosDefaults() {
        return {
            codigo: '', texto: '', rol: '', estado: '',
            orden:  'id', dir: 'desc', limit: 100,
        };
    }

    async function renderUsers(root) {
        try {
            const data = await api('users.php');
            const r = data.resumen;
            const usuarios = data.usuarios;
            const state = usuariosDefaults();

            root.innerHTML = `
                ${moduleHeader('Usuarios', 'Personas con acceso a la plataforma: credenciales, rol y estado.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Activos</span>
                        <span class="stat-value green">${r.activos}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Administradores</span>
                        <span class="stat-value orange">${r.admins}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'usr',
                    quickPlaceholder: 'Buscar nombre, email o celular…',
                    newLabel:         'Nuevo usuario',
                })}
                <div class="table-card" id="usr-table"></div>
            `;

            wireUsersView(state, usuarios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function usuariosTableBody(usuarios) {
        if (!usuarios.length) {
            return `<div class="table-empty">No hay usuarios. Creá el primero con "Nuevo usuario".</div>`;
        }

        const rows = usuarios.map(u => `
            <tr data-id="${u.id}">
                <td><span class="td-id">#${u.id}</span></td>
                <td class="td-nombre">${escape(u.nombre)}</td>
                <td>${escape(u.email)}</td>
                <td>${u.celular ? escape(u.celular) : '<span class="muted">—</span>'}</td>
                <td>${rolBadge(u.rol)}</td>
                <td>${u.activo
                    ? '<span class="badge badge-success">Activo</span>'
                    : '<span class="badge badge-danger">Inactivo</span>'}</td>
                <td>${formatDate(u.last_login_at)}</td>
                <td>${formatDate(u.created_at)}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Celular</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Último login</th>
                        <th>Creado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function rolBadge(rol) {
        const r = ROLES_USER.find(x => x.value === rol) || { label: rol, badge: 'badge-info' };
        return `<span class="badge ${r.badge}">${escape(r.label)}</span>`;
    }

    function wireUsersView(state, allUsuarios) {
        const tableWrap = document.getElementById('usr-table');
        const quick     = document.getElementById('usr-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('usr-filters');
        const btnNew    = document.getElementById('usr-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allUsuarios.filter(u => {
                if (Number.isFinite(codigo) && u.id !== codigo) return false;
                if (state.rol && u.rol !== state.rol) return false;
                if (state.estado === 'activo'   && !u.activo) return false;
                if (state.estado === 'inactivo' &&  u.activo) return false;
                if (q && !(u.email + ' ' + u.nombre + ' ' + (u.celular || '')).toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = usuariosTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(u) {
            return standardRowMenuItems({
                view:   true, onView:   () => openUserViewModal(u),
                edit:   true, onEdit:   () => openUserModal(u),
                delete: true, onDelete: () => confirmDeleteUser(u),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const u  = allUsuarios.find(x => x.id === id);
                if (!u) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(u), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(u), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () => openUsersFiltersModal(state, applyAndRender));
        btnNew.addEventListener('click',  () => openUserModal(null));

        applyAndRender();
    }

    function openUsersFiltersModal(state, onApply) {
        const rolOpts = ['<option value="">Todos los roles</option>'].concat(
            ROLES_USER.map(r =>
                `<option value="${r.value}"${r.value === state.rol ? ' selected' : ''}>${escape(r.label)}</option>`
            )
        ).join('');
        const estOpts = [
            `<option value=""${state.estado === '' ? ' selected' : ''}>Todos</option>`,
            `<option value="activo"${state.estado === 'activo' ? ' selected' : ''}>Activos</option>`,
            `<option value="inactivo"${state.estado === 'inactivo' ? ' selected' : ''}>Inactivos</option>`,
        ].join('');
        const ordOpts = ORDEN_USUARIOS.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="usr-fm-codigo">Código</label>
                    <input type="number" id="usr-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="usr-fm-texto">Buscar (nombre / email / celular)</label>
                    <input type="search" id="usr-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="usr-fm-rol">Rol</label>
                    <select id="usr-fm-rol">${rolOpts}</select>
                </div>
                <div class="form-group">
                    <label for="usr-fm-estado">Estado</label>
                    <select id="usr-fm-estado">${estOpts}</select>
                </div>
                <div class="form-group">
                    <label for="usr-fm-limit">Límite</label>
                    <input type="number" id="usr-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="usr-fm-orden">Ordenar por</label>
                    <select id="usr-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="usr-fm-dir">Dirección</label>
                    <select id="usr-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo = modal.querySelector('#usr-fm-codigo').value.trim();
                state.texto  = modal.querySelector('#usr-fm-texto').value.trim();
                state.rol    = modal.querySelector('#usr-fm-rol').value;
                state.estado = modal.querySelector('#usr-fm-estado').value;
                state.orden  = modal.querySelector('#usr-fm-orden').value;
                state.dir    = modal.querySelector('#usr-fm-dir').value;
                state.limit  = readLimit(modal.querySelector('#usr-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = usuariosDefaults();
                modal.querySelector('#usr-fm-codigo').value = d.codigo;
                modal.querySelector('#usr-fm-texto').value  = d.texto;
                modal.querySelector('#usr-fm-rol').value    = d.rol;
                modal.querySelector('#usr-fm-estado').value = d.estado;
                modal.querySelector('#usr-fm-orden').value  = d.orden;
                modal.querySelector('#usr-fm-dir').value    = d.dir;
                modal.querySelector('#usr-fm-limit').value  = String(d.limit);
            },
        });
    }

    function openUserViewModal(usr) {
        const estadoVal = usr.activo
            ? '<span class="badge badge-success">Activo</span>'
            : '<span class="badge badge-danger">Inactivo</span>';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar usuario</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="modal-tabs" role="tablist">
                        <button class="modal-tab active" data-tab="general" role="tab">General</button>
                        <button class="modal-tab"        data-tab="perfiles" role="tab">Perfiles</button>
                    </div>
                    <div class="modal-tabpanel" data-panel="general">
                        ${viewGrid([
                            viewCardHalf('Código',        `<code>#${usr.id}</code>`),
                            viewCardHalf('Nombre',        escape(usr.nombre)),
                            viewCardHalf('Email',         escape(usr.email)),
                            viewCardHalf('Celular',       usr.celular ? escape(usr.celular) : `<span class="muted">—</span>`),
                            viewCardHalf('Rol',           rolBadge(usr.rol)),
                            viewCardHalf('Estado',        estadoVal),
                            viewCardHalf('Último login',  escape(formatDate(usr.last_login_at))),
                            viewCardHalf('Creado',        escape(formatDate(usr.created_at))),
                            viewCardHalf('Actualizado',   escape(formatDate(usr.updated_at))),
                        ])}
                    </div>
                    <div class="modal-tabpanel" data-panel="perfiles" hidden>
                        <div data-role="perfiles-body">
                            <div style="text-align:center;padding:24px"><div class="spin"></div></div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const tabs    = backdrop.querySelectorAll('.modal-tab');
        const panels  = backdrop.querySelectorAll('.modal-tabpanel');
        const perfBody = backdrop.querySelector('[data-role="perfiles-body"]');
        let perfLoaded = false;

        async function loadPerfiles() {
            if (perfLoaded) return;
            perfLoaded = true;
            try {
                const data = await api('profiles.php?usuario_id=' + encodeURIComponent(usr.id));
                perfBody.innerHTML = perfilesUsuarioTableBody(data.perfiles || []);
            } catch (e) {
                perfLoaded = false;
                perfBody.innerHTML = errorBox(e.message);
            }
        }

        tabs.forEach(t => t.addEventListener('click', () => {
            const target = t.dataset.tab;
            tabs.forEach(x => x.classList.toggle('active', x === t));
            panels.forEach(p => p.hidden = p.dataset.panel !== target);
            if (target === 'perfiles') loadPerfiles();
        }));
    }

    function perfilesUsuarioTableBody(perfiles) {
        if (!perfiles.length) {
            return `<div class="table-empty">Este usuario no tiene perfiles asociados.</div>`;
        }
        const rows = perfiles.map(p => `
            <tr>
                <td><span class="td-id">#${p.id}</span></td>
                <td><span class="badge badge-info">${escape(p.dominio_nombre)}</span></td>
                <td>${perfilRolBadge(p.rol)}</td>
                <td>${formatDate(p.created_at)}</td>
            </tr>
        `).join('');
        return `
            <div class="table-card">
                <table>
                    <thead>
                        <tr>
                            <th>Código</th>
                            <th>Dominio</th>
                            <th>Rol</th>
                            <th>Creado</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function openUserModal(usr) {
        const isEdit  = !!usr;
        const rolOpts = ROLES_USER.map(r =>
            `<option value="${r.value}" ${usr?.rol === r.value ? 'selected' : ''}>${escape(r.label)}</option>`
        ).join('');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar usuario' : 'Nuevo usuario'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="usr-nombre">Nombre</label>
                            <input type="text" id="usr-nombre" maxlength="120" value="${escape(usr?.nombre ?? '')}" required>
                            <div class="field-error" id="usr-nombre-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="usr-email">Email</label>
                            <input type="email" id="usr-email" maxlength="120" value="${escape(usr?.email ?? '')}" required>
                            <div class="field-error" id="usr-email-err" style="display:none"></div>
                        </div>
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="usr-celular">Celular</label>
                            <input type="tel" id="usr-celular" maxlength="30"
                                   value="${escape(usr?.celular ?? '')}"
                                   placeholder="+54 9 11 1234-5678">
                            <div class="field-error" id="usr-celular-err" style="display:none"></div>
                        </div>
                        <div class="form-group">
                            <label for="usr-rol">Rol</label>
                            <select id="usr-rol">${rolOpts}</select>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Estado</label>
                        <label class="toggle-switch" style="margin-top:6px">
                            <input type="checkbox" id="usr-activo" ${(!usr || usr.activo) ? 'checked' : ''}>
                            <span class="toggle-track"><span class="toggle-thumb"></span></span>
                            <span class="toggle-label" id="usr-activo-label">${(!usr || usr.activo) ? 'Activo' : 'Inactivo'}</span>
                        </label>
                    </div>
                    <div class="form-group">
                        <label for="usr-pass">
                            ${isEdit ? 'Nueva contraseña (dejar vacío para no cambiar)' : 'Contraseña'}
                        </label>
                        <input type="password" id="usr-pass" minlength="6" autocomplete="new-password"
                               placeholder="${isEdit ? 'Sin cambios' : 'Mínimo 6 caracteres'}">
                        <div class="field-error" id="usr-pass-err" style="display:none"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear usuario'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const nombreInput  = backdrop.querySelector('#usr-nombre');
        const emailInput   = backdrop.querySelector('#usr-email');
        const celularInput = backdrop.querySelector('#usr-celular');
        const rolSel       = backdrop.querySelector('#usr-rol');
        const activoChk    = backdrop.querySelector('#usr-activo');
        const activoLbl    = backdrop.querySelector('#usr-activo-label');
        const passInput    = backdrop.querySelector('#usr-pass');
        const nombreErr    = backdrop.querySelector('#usr-nombre-err');
        const emailErr     = backdrop.querySelector('#usr-email-err');
        const celularErr   = backdrop.querySelector('#usr-celular-err');
        const passErr      = backdrop.querySelector('#usr-pass-err');
        const saveBtn      = backdrop.querySelector('[data-act="save"]');

        activoChk.addEventListener('change', () => {
            activoLbl.textContent = activoChk.checked ? 'Activo' : 'Inactivo';
        });

        nombreInput.focus();

        saveBtn.addEventListener('click', async () => {
            const nombre  = nombreInput.value.trim();
            const email   = emailInput.value.trim().toLowerCase();
            const celular = celularInput.value.trim();
            const rol     = rolSel.value;
            const activo  = activoChk.checked;
            const pass    = passInput.value;

            [nombreErr, emailErr, celularErr, passErr].forEach(el => el.style.display = 'none');
            [nombreInput, emailInput, celularInput, passInput].forEach(el => el.classList.remove('input-invalid'));

            let firstInvalid = null;
            if (!nombre) {
                nombreErr.textContent = 'El nombre es obligatorio';
                nombreErr.style.display = 'block';
                nombreInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || nombreInput;
            }
            if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
                emailErr.textContent = 'Ingresá un email válido';
                emailErr.style.display = 'block';
                emailInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || emailInput;
            }
            if (celular !== '' && !/^[+0-9\s().-]+$/.test(celular)) {
                celularErr.textContent = 'Solo números, espacios y los signos + ( ) - .';
                celularErr.style.display = 'block';
                celularInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || celularInput;
            }
            if (!isEdit && pass.length < 6) {
                passErr.textContent = 'Mínimo 6 caracteres';
                passErr.style.display = 'block';
                passInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || passInput;
            } else if (isEdit && pass !== '' && pass.length < 6) {
                passErr.textContent = 'Si la cambiás, mínimo 6 caracteres';
                passErr.style.display = 'block';
                passInput.classList.add('input-invalid');
                firstInvalid = firstInvalid || passInput;
            }
            if (firstInvalid) { firstInvalid.focus(); return; }

            const payload = { email, nombre, celular, rol, activo };
            if (pass !== '') payload.password = pass;

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('users.php', { method: 'PUT', body: { id: usr.id, ...payload } });
                    toast('Usuario actualizado');
                } else {
                    await api('users.php', { method: 'POST', body: payload });
                    toast('Usuario creado');
                }
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function confirmDeleteUser(usr) {
        confirmDialog(
            'Eliminar usuario',
            `¿Eliminar al usuario "${usr.nombre}" (${usr.email})? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('users.php?id=' + usr.id, { method: 'DELETE' });
                    toast('Usuario eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    /* ---------- Views: Perfiles ---------- */
    const ROLES_PERFIL = [
        { value: 'admin',    label: 'Administrador', badge: 'badge-danger' },
        { value: 'operador', label: 'Operador',      badge: 'badge-info'   },
    ];

    const ORDEN_PERFILES = [
        { value: 'id',             label: 'Código'   },
        { value: 'usuario_nombre', label: 'Usuario'  },
        { value: 'dominio_nombre', label: 'Dominio'  },
        { value: 'rol',            label: 'Rol'      },
        { value: 'created_at',     label: 'Creado'   },
    ];

    function perfilesDefaults() {
        return {
            codigo: '', texto: '', dominio: '', rol: '',
            orden:  'id', dir: 'desc', limit: 100,
        };
    }

    async function renderProfiles(root) {
        try {
            const [data, usrData, domData] = await Promise.all([
                api('profiles.php'),
                api('users.php'),
                api('dominios.php'),
            ]);
            const r = data.resumen;
            const perfiles  = data.perfiles;
            const usuarios  = usrData.usuarios;
            const dominios  = domData.dominios;
            const state     = perfilesDefaults();

            root.innerHTML = `
                ${moduleHeader('Perfiles', 'Relación entre usuarios y dominios: qué rol tiene cada usuario sobre cada dominio.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Administradores</span>
                        <span class="stat-value orange">${r.admin}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Operadores</span>
                        <span class="stat-value green">${r.operador}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'prf',
                    quickPlaceholder: 'Buscar usuario, email o dominio…',
                    newLabel:         'Nuevo perfil',
                })}
                <div class="table-card" id="prf-table"></div>
            `;

            wireProfilesView(state, perfiles, usuarios, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function perfilesTableBody(perfiles) {
        if (!perfiles.length) {
            return `<div class="table-empty">No hay perfiles. Creá el primero con "Nuevo perfil".</div>`;
        }

        const rows = perfiles.map(p => `
            <tr data-id="${p.id}">
                <td><span class="td-id">#${p.id}</span></td>
                <td>
                    <div class="td-nombre">${escape(p.usuario_nombre)}</div>
                    <div class="td-id">${escape(p.usuario_email)}</div>
                </td>
                <td><span class="badge badge-info">${escape(p.dominio_nombre)}</span></td>
                <td>${perfilRolBadge(p.rol)}</td>
                <td>${formatDate(p.created_at)}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Usuario</th>
                        <th>Dominio</th>
                        <th>Rol</th>
                        <th>Creado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function perfilRolBadge(rol) {
        const r = ROLES_PERFIL.find(x => x.value === rol) || { label: rol, badge: 'badge-info' };
        return `<span class="badge ${r.badge}">${escape(r.label)}</span>`;
    }

    function wireProfilesView(state, allPerfiles, allUsuarios, allDominios) {
        const tableWrap = document.getElementById('prf-table');
        const quick     = document.getElementById('prf-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('prf-filters');
        const btnNew    = document.getElementById('prf-new');

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = allPerfiles.filter(p => {
                if (Number.isFinite(codigo) && p.id !== codigo) return false;
                if (state.rol     && p.rol !== state.rol) return false;
                if (state.dominio && String(p.dominio_id) !== state.dominio) return false;
                if (q && !(p.usuario_nombre + ' ' + p.usuario_email + ' ' + p.dominio_nombre)
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = perfilesTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        function rowMenuFor(p) {
            return standardRowMenuItems({
                view:   true, onView:   () => openProfileViewModal(p),
                edit:   true, onEdit:   () => openProfileModal(p, allUsuarios, allDominios),
                delete: true, onDelete: () => confirmDeleteProfile(p),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const p  = allPerfiles.find(x => x.id === id);
                if (!p) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(p), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(p), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () => openProfilesFiltersModal(state, allDominios, applyAndRender));
        btnNew.addEventListener('click',  () => openProfileModal(null, allUsuarios, allDominios));

        applyAndRender();
    }

    function openProfilesFiltersModal(state, allDominios, onApply) {
        const domOpts = ['<option value="">Todos los dominios</option>'].concat(
            allDominios.map(d => `<option value="${d.id}"${String(d.id) === state.dominio ? ' selected' : ''}>${escape(d.nombre)}</option>`)
        ).join('');
        const rolOpts = ['<option value="">Todos los roles</option>'].concat(
            ROLES_PERFIL.map(r =>
                `<option value="${r.value}"${r.value === state.rol ? ' selected' : ''}>${escape(r.label)}</option>`
            )
        ).join('');
        const ordOpts = ORDEN_PERFILES.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="prf-fm-codigo">Código</label>
                    <input type="number" id="prf-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="prf-fm-texto">Buscar (usuario / email / dominio)</label>
                    <input type="search" id="prf-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="prf-fm-dominio">Dominio</label>
                    <select id="prf-fm-dominio">${domOpts}</select>
                </div>
                <div class="form-group">
                    <label for="prf-fm-rol">Rol</label>
                    <select id="prf-fm-rol">${rolOpts}</select>
                </div>
                <div class="form-group">
                    <label for="prf-fm-limit">Límite</label>
                    <input type="number" id="prf-fm-limit" min="1" max="1000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="prf-fm-orden">Ordenar por</label>
                    <select id="prf-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="prf-fm-dir">Dirección</label>
                    <select id="prf-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                state.codigo  = modal.querySelector('#prf-fm-codigo').value.trim();
                state.texto   = modal.querySelector('#prf-fm-texto').value.trim();
                state.dominio = modal.querySelector('#prf-fm-dominio').value;
                state.rol     = modal.querySelector('#prf-fm-rol').value;
                state.orden   = modal.querySelector('#prf-fm-orden').value;
                state.dir     = modal.querySelector('#prf-fm-dir').value;
                state.limit   = readLimit(modal.querySelector('#prf-fm-limit'), 100);
                onApply();
            },
            onClear(modal) {
                const d = perfilesDefaults();
                modal.querySelector('#prf-fm-codigo').value  = d.codigo;
                modal.querySelector('#prf-fm-texto').value   = d.texto;
                modal.querySelector('#prf-fm-dominio').value = d.dominio;
                modal.querySelector('#prf-fm-rol').value     = d.rol;
                modal.querySelector('#prf-fm-orden').value   = d.orden;
                modal.querySelector('#prf-fm-dir').value     = d.dir;
                modal.querySelector('#prf-fm-limit').value   = String(d.limit);
            },
        });
    }

    function openProfileViewModal(prf) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar perfil</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',       `<code>#${prf.id}</code>`),
                        viewCardHalf('Rol',          perfilRolBadge(prf.rol)),
                        viewCardHalf('Usuario',      escape(prf.usuario_nombre)),
                        viewCardHalf('Email',        escape(prf.usuario_email)),
                        viewCardHalf('Dominio',      `<span class="badge badge-info">${escape(prf.dominio_nombre)}</span>`),
                        viewCardHalf('Código dominio', `<code>#${prf.dominio_id}</code>`),
                        viewCardHalf('Creado',       escape(formatDate(prf.created_at))),
                        viewCardHalf('Actualizado',  escape(formatDate(prf.updated_at))),
                    ])}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    function openProfileModal(prf, allUsuarios, allDominios) {
        const isEdit = !!prf;

        const usrOpts = ['<option value="">Elegí un usuario…</option>'].concat(
            allUsuarios.map(u =>
                `<option value="${u.id}" ${prf?.usuario_id === u.id ? 'selected' : ''}>${escape(u.nombre)} (${escape(u.email)})</option>`
            )
        ).join('');

        const domOpts = ['<option value="">Elegí un dominio…</option>'].concat(
            allDominios.map(d =>
                `<option value="${d.id}" ${prf?.dominio_id === d.id ? 'selected' : ''}>${escape(d.nombre)}</option>`
            )
        ).join('');

        const rolOpts = ROLES_PERFIL.map(r =>
            `<option value="${r.value}" ${prf?.rol === r.value ? 'selected' : ''}>${escape(r.label)}</option>`
        ).join('');

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar perfil' : 'Nuevo perfil'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="prf-usuario">Usuario</label>
                        <select id="prf-usuario" ${isEdit ? 'disabled' : ''}>${usrOpts}</select>
                        <div class="field-error" id="prf-usuario-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="prf-dominio">Dominio</label>
                        <select id="prf-dominio" ${isEdit ? 'disabled' : ''}>${domOpts}</select>
                        <div class="field-error" id="prf-dominio-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="prf-rol">Rol en el dominio</label>
                        <select id="prf-rol">${rolOpts}</select>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear perfil'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const usrSel     = backdrop.querySelector('#prf-usuario');
        const domSel     = backdrop.querySelector('#prf-dominio');
        const rolSel     = backdrop.querySelector('#prf-rol');
        const usrErr     = backdrop.querySelector('#prf-usuario-err');
        const domErr     = backdrop.querySelector('#prf-dominio-err');
        const saveBtn    = backdrop.querySelector('[data-act="save"]');

        (isEdit ? rolSel : usrSel).focus();

        saveBtn.addEventListener('click', async () => {
            const rol = rolSel.value;

            [usrErr, domErr].forEach(el => el.style.display = 'none');
            [usrSel, domSel].forEach(el => el.classList.remove('input-invalid'));

            if (isEdit) {
                saveBtn.disabled = true;
                try {
                    await api('profiles.php', { method: 'PUT', body: { id: prf.id, rol } });
                    toast('Perfil actualizado');
                    close();
                    navigate();
                } catch (e) {
                    saveBtn.disabled = false;
                    toast(e.message, 'error');
                }
                return;
            }

            const usuario_id = +usrSel.value;
            const dominio_id = +domSel.value;

            let firstInvalid = null;
            if (!usuario_id) {
                usrErr.textContent = 'Elegí un usuario';
                usrErr.style.display = 'block';
                usrSel.classList.add('input-invalid');
                firstInvalid = firstInvalid || usrSel;
            }
            if (!dominio_id) {
                domErr.textContent = 'Elegí un dominio';
                domErr.style.display = 'block';
                domSel.classList.add('input-invalid');
                firstInvalid = firstInvalid || domSel;
            }
            if (firstInvalid) { firstInvalid.focus(); return; }

            saveBtn.disabled = true;
            try {
                await api('profiles.php', { method: 'POST', body: { usuario_id, dominio_id, rol } });
                toast('Perfil creado');
                close();
                navigate();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function confirmDeleteProfile(prf) {
        confirmDialog(
            'Eliminar perfil',
            `¿Eliminar el perfil de "${prf.usuario_nombre}" en "${prf.dominio_nombre}"? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('profiles.php?id=' + prf.id, { method: 'DELETE' });
                    toast('Perfil eliminado');
                    navigate();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    /* ---------- Views: Señales ----------
     * Read-only: las señales las generan los dispositivos. Sigue las
     * convenciones de listado ABM (header + KPIs + toolbar + tabla con
     * columna `Código` primero y columna `Consultar` al final). No tiene
     * Editar / Eliminar — los registros son inmutables, por lo que el
     * toolbar omite el botón `+ Nuevo` (DESIGN.md §9).
     *
     * Tabla real: db/schema.sql -> `senales` (id, serie, fecha, sentido,
     * transceptor, dispositivo, canal, topic, mensaje, estado). El campo
     * `dispositivo` es FK a `dispositivos.id`.
     */
    const SENTIDOS_SENAL = [
        { value: 'I', label: 'Entrante', badge: 'badge-info' },
        { value: 'O', label: 'Saliente', badge: 'badge-warn' },
    ];

    const ORDEN_SENALES = [
        { value: 'id',          label: 'Código'     },
        { value: 'fecha',       label: 'Fecha'      },
        { value: 'dispositivo', label: 'Dispositivo'},
        { value: 'canal',       label: 'Canal'      },
        { value: 'estado',      label: 'Estado'     },
    ];

    function signalsDefaults() {
        return {
            codigo: '', texto: '', dispositivo: '', dominio: '',
            sentido: '', estado: '',
            orden: 'id', dir: 'desc', limit: 100,
        };
    }

    async function renderSignals(root) {
        try {
            const initialDevice = pendingSignalsDeviceFilter;
            pendingSignalsDeviceFilter = null;

            const state = signalsDefaults();
            if (initialDevice) state.dispositivo = String(initialDevice);

            const qs = new URLSearchParams();
            qs.set('limit', String(state.limit));
            if (state.dispositivo) qs.set('dispositivo', state.dispositivo);

            const [data, devData, domData] = await Promise.all([
                api('signals.php?' + qs.toString()),
                api('dispositivos.php'),
                api('dominios.php'),
            ]);

            const r            = data.resumen;
            const dispositivos = devData.dispositivos;
            const dominios     = domData.dominios;
            let senales        = data.senales;

            root.innerHTML = `
                ${moduleHeader('Señales', 'Registro de señales enviadas por los dispositivos: fecha, dispositivo, canal y contenido del mensaje recibido.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Últimas 24 h</span>
                        <span class="stat-value green">${r.ultimas_24h}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Dispositivos activos (24 h)</span>
                        <span class="stat-value">${r.dispositivos_activos}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Hoy</span>
                        <span class="stat-value orange">${r.hoy}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'sig',
                    quickPlaceholder: 'Buscar topic, mensaje, dispositivo…',
                    newLabel:         null,
                    extraRight: `
                        <button type="button" class="btn btn-secondary btn-sm" id="sig-monitor"
                                title="Monitor en tiempo real de señales entrantes">
                            <i class="fa-solid fa-tower-broadcast"></i> Monitor en tiempo real
                        </button>
                    `,
                })}
                <div class="table-card" id="sig-table"></div>
            `;

            document.getElementById('sig-monitor').addEventListener('click', openSignalsLiveMonitorModal);
            wireSignalsView(state, senales, dispositivos, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function sentidoBadge(s) {
        if (!s) return `<span class="td-id">—</span>`;
        const item = SENTIDOS_SENAL.find(x => x.value === s);
        const label = item ? item.label : s;
        const cls   = item ? item.badge : 'badge-info';
        return `<span class="badge ${cls}">${escape(label)}</span>`;
    }

    // Variante compacta para el feed en vivo: sólo el ícono.
    //   S (Salida)  → upload
    //   E (Entrada) → download
    function sentidoLiveIcon(s) {
        if (s === 'S') return '<i class="fa-solid fa-upload sentido-icon sentido-out" title="Saliente" aria-label="Saliente"></i>';
        if (s === 'E') return '<i class="fa-solid fa-download sentido-icon sentido-in" title="Entrante" aria-label="Entrante"></i>';
        return '<span class="td-id">—</span>';
    }

    /* Modal "Monitor en tiempo real" (Señales).
     *
     * Modal tipo log de consola/terminal que muestra las señales que van
     * ingresando en vivo, poll-eando `signals_live.php` cada 100 ms (muy
     * agresivo vs. la card del dashboard, que va a 500 ms — el monitor
     * está pensado para sentirse "en tiempo real"). Cada señal es una
     * línea monoespaciada en un panel oscuro tipo terminal; las nuevas se
     * appendean al final con auto-scroll hasta el fondo.
     *
     * Diferencias vs. card del dashboard:
     *   - vive en un modal `signals-monitor-modal` (≈1650px de ancho).
     *   - estética terminal: fondo `#0a0a0a`, font monoespaciada, ANSI-ish.
     *   - orden cronológico ascendente (nuevas abajo, auto-scroll).
     *   - click en una línea abre el modal de detalle existente.
     *   - el timer se limpia al cerrar el modal. */
    function openSignalsLiveMonitorModal() {
        const MAX_ROWS = 250;
        const TICK_MS  = 100;   // tiempo real agresivo — 10 req/s por cliente (limitado además por el guard `fetching`).

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal signals-monitor-modal" role="dialog" aria-modal="true" aria-labelledby="sig-monitor-title">
                <div class="modal-header">
                    <div class="modal-title" id="sig-monitor-title">
                        Monitor en tiempo real
                        <span class="dash-live-status" id="sig-monitor-status">
                            <span class="live-dot"></span> En vivo · 100 ms
                        </span>
                    </div>
                    <div class="signals-monitor-controls">
                        <button type="button" class="btn-icon-sm" id="sig-monitor-toggle"
                                title="Pausar" aria-label="Pausar feed">
                            <i class="fa-solid fa-pause"></i>
                        </button>
                        <button type="button" class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                    </div>
                </div>
                <div class="modal-body signals-monitor-body">
                    <div class="signals-monitor-console" id="sig-monitor-console">
                        <div class="signals-monitor-empty">$ esperando señales…<span class="signals-monitor-caret"></span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="signals-monitor-footer-info">
                        <i class="fa-solid fa-terminal"></i>
                        <strong id="sig-monitor-count">0</strong> de <strong>${MAX_ROWS}</strong> líneas · click sobre una línea para ver detalle
                    </span>
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const modal       = backdrop.querySelector('.modal');
        const console_    = backdrop.querySelector('#sig-monitor-console');
        const status      = backdrop.querySelector('#sig-monitor-status');
        const toggle      = backdrop.querySelector('#sig-monitor-toggle');
        const countLabel  = backdrop.querySelector('#sig-monitor-count');

        // Buffer cronológico ascendente: índice 0 = más vieja, último = más nueva.
        let buffer      = [];
        let maxId       = 0;
        let userPaused  = false;
        let hoverPaused = false;
        let fetching    = false;
        let firstTick   = true;

        const isPaused = () => userPaused || hoverPaused;

        function updateStatus() {
            if (userPaused) {
                status.innerHTML = '<span class="live-dot"></span> Pausado';
                modal.classList.add('live-paused');
            } else if (hoverPaused) {
                status.innerHTML = '<span class="live-dot"></span> En pausa (hover)';
                modal.classList.add('live-paused');
            } else {
                status.innerHTML = '<span class="live-dot"></span> En vivo · 100 ms';
                modal.classList.remove('live-paused');
            }
        }

        // Render de una línea estilo log:
        //   2026-05-20 14:32:01.000 │ #1234 │  IN │ uid-abc Nombre │ topic/foo │ {"k":"v"}
        function renderLine(s, isNew) {
            const sentidoCls   = s.sentido === 'E' ? 'log-in'
                              : s.sentido === 'S' ? 'log-out'
                              : 'log-muted';
            const sentidoLabel = s.sentido === 'E' ? ' IN'
                              : s.sentido === 'S' ? 'OUT'
                              : ' --';
            const dispLabel = s.dispositivo_uuid
                ? `${escape(s.dispositivo_uuid)} ${escape(s.dispositivo_nombre ?? '')}`
                : escape(s.dispositivo_nombre ?? '—');
            const msg = (s.mensaje != null && s.mensaje !== '')
                ? escape(String(s.mensaje).replace(/\s+/g, ' ').trim())
                : '—';
            const topic = s.topic ? escape(s.topic) : '—';

            return `
                <div class="log-line${isNew ? ' is-new' : ''}" data-id="${s.id}">
                    <span class="log-ts">${escape(formatDateOnly(s.fecha))} ${escape(formatTime(s.fecha))}</span>
                    <span class="log-sep">│</span>
                    <span class="log-id">#${s.id}</span>
                    <span class="log-sep">│</span>
                    <span class="log-arrow ${sentidoCls}">${sentidoLabel}</span>
                    <span class="log-sep">│</span>
                    <span class="log-device">${dispLabel}</span>
                    <span class="log-sep">│</span>
                    <span class="log-topic">${topic}</span>
                    <span class="log-sep">│</span>
                    <span class="log-msg">${msg}</span>
                </div>
            `;
        }

        function bindLineClicks() {
            console_.querySelectorAll('.log-line').forEach(line => {
                if (line.dataset.bound === '1') return;
                line.dataset.bound = '1';
                line.addEventListener('click', () => {
                    const id = +line.dataset.id;
                    const s  = buffer.find(x => x.id === id);
                    if (s) openSignalViewModal(s);
                });
            });
        }

        function scrollToBottom() {
            console_.scrollTop = console_.scrollHeight;
        }

        function repaintAll() {
            countLabel.textContent = String(buffer.length);
            if (!buffer.length) {
                console_.innerHTML = `<div class="signals-monitor-empty">$ esperando señales…<span class="signals-monitor-caret"></span></div>`;
                return;
            }
            console_.innerHTML = buffer.map(s => renderLine(s, false)).join('');
            bindLineClicks();
        }

        function appendNew(newSenalesAsc) {
            // Quitar empty placeholder si está.
            const empty = console_.querySelector('.signals-monitor-empty');
            if (empty) empty.remove();

            // Añadir nuevas líneas al final.
            const html = newSenalesAsc.map(s => renderLine(s, true)).join('');
            console_.insertAdjacentHTML('beforeend', html);

            // Trim del DOM si el buffer ya cortó el principio.
            const lines = console_.querySelectorAll('.log-line');
            const overflow = lines.length - buffer.length;
            for (let i = 0; i < overflow; i++) lines[i].remove();

            countLabel.textContent = String(buffer.length);
            bindLineClicks();
            // Auto-scroll incondicional al pie (la pausa por hover ya da tiempo
            // para leer una línea sin que se mueva — cuando llegan nuevas
            // siempre seguimos al fondo).
            scrollToBottom();
        }

        async function tick() {
            if (!document.body.contains(backdrop)) return;
            if (fetching || isPaused()) return;
            fetching = true;
            try {
                const qs = new URLSearchParams();
                qs.set('since_id', String(maxId));
                qs.set('limit',    String(MAX_ROWS));
                const data = await api('signals_live.php?' + qs.toString());

                if (data.last_id > maxId) maxId = data.last_id;

                // signals_live.php devuelve DESC (nuevas primero); para el log
                // las invertimos a orden cronológico ascendente.
                const incoming = (data.senales || []).slice().reverse();
                if (!incoming.length) return;

                if (firstTick) {
                    firstTick = false;
                    buffer = incoming.slice(-MAX_ROWS);
                    repaintAll();
                    scrollToBottom();
                } else {
                    buffer = buffer.concat(incoming).slice(-MAX_ROWS);
                    appendNew(incoming);
                }
            } catch (_) {
                // Silencioso (mismo criterio que el feed del dashboard, §13.1).
            } finally {
                fetching = false;
            }
        }

        toggle.addEventListener('click', () => {
            userPaused = !userPaused;
            toggle.innerHTML = userPaused
                ? '<i class="fa-solid fa-play"></i>'
                : '<i class="fa-solid fa-pause"></i>';
            toggle.title = userPaused ? 'Reanudar' : 'Pausar';
            toggle.setAttribute('aria-label', toggle.title + ' feed');
            updateStatus();
        });
        // Pausa por hover sólo sobre el body (la cabecera tiene el botón de
        // pausa, no queremos que el hover de ese botón también pause).
        console_.addEventListener('mouseenter', () => { hoverPaused = true;  updateStatus(); });
        console_.addEventListener('mouseleave', () => { hoverPaused = false; updateStatus(); });

        updateStatus();
        tick();
        const intervalId = setInterval(tick, TICK_MS);

        function close() {
            clearInterval(intervalId);
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        }
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    function signalsTableBody(senales) {
        if (!senales.length) {
            return `<div class="table-empty">No hay señales que coincidan con los filtros.</div>`;
        }

        const rows = senales.map(s => `
            <tr data-id="${s.id}">
                <td><span class="td-id">#${s.id}</span></td>
                <td><span class="td-id">${formatDate(s.fecha)}</span></td>
                <td>
                    <div class="td-nombre">${escape(s.dispositivo_nombre ?? '—')}</div>
                    ${s.dispositivo_uuid ? `<div class="td-id">${escape(s.dispositivo_uuid)}</div>` : ''}
                </td>
                <td>${s.dominio_id ? `<span class="badge badge-info">${escape(s.dominio_nombre)}</span>` : '<span class="td-id">—</span>'}</td>
                <td>${s.canal != null ? `<span class="td-id">#${s.canal}</span>` : '<span class="td-id">—</span>'}</td>
                <td>${sentidoBadge(s.sentido)}</td>
                <td><span class="td-id">${escape(s.topic ?? '')}</span></td>
                <td>${s.mensaje != null && s.mensaje !== '' ? escape(s.mensaje) : '<span class="td-id">—</span>'}</td>
                <td>${s.estado != null ? `<span class="td-id">${s.estado}</span>` : '<span class="td-id">—</span>'}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Dispositivo</th>
                        <th>Dominio</th>
                        <th>Canal</th>
                        <th>Sentido</th>
                        <th>Topic</th>
                        <th>Mensaje</th>
                        <th>Estado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function wireSignalsView(state, allSenales, allDispositivos, allDominios) {
        const tableWrap = document.getElementById('sig-table');
        const quick     = document.getElementById('sig-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('sig-filters');

        let senales = allSenales;

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);

            let filtered = senales.filter(s => {
                if (Number.isFinite(codigo) && s.id !== codigo) return false;
                if (state.dispositivo && String(s.dispositivo) !== state.dispositivo) return false;
                if (state.dominio && String(s.dominio_id ?? '') !== state.dominio) return false;
                if (state.sentido && s.sentido !== state.sentido) return false;
                if (state.estado !== '' && String(s.estado ?? '') !== state.estado) return false;
                if (q && !((s.dispositivo_nombre ?? '') + ' ' +
                           (s.dispositivo_uuid   ?? '') + ' ' +
                           (s.topic              ?? '') + ' ' +
                           (s.mensaje            ?? '') + ' ' +
                           (s.transceptor_nombre ?? ''))
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = signalsTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        async function refetchFromServer() {
            const qs = new URLSearchParams();
            qs.set('limit', String(state.limit));
            if (state.dispositivo) qs.set('dispositivo', state.dispositivo);

            tableWrap.innerHTML = `<div class="table-empty"><div class="spin"></div></div>`;
            try {
                const data = await api('signals.php?' + qs.toString());
                senales = data.senales;
                applyAndRender();
            } catch (e) {
                tableWrap.innerHTML = errorBox(e.message);
            }
        }

        function rowMenuFor(s) {
            return standardRowMenuItems({
                view: true, onView: () => openSignalViewModal(s),
                extra: [
                    ...(s.dispositivo
                        ? [{ act: 'go-device', label: 'Ver dispositivo', icon: 'fa-satellite-dish',
                            onSelect: () => { pendingDispositivosDominioFilter = null; window.location.hash = '#/dispositivos'; } }]
                        : []),
                    ...(s.topic
                        ? [{ act: 'copy-topic',   label: 'Copiar topic',   icon: 'fa-regular fa-copy', onSelect: () => copyToClipboard(s.topic) }]
                        : []),
                    ...(s.mensaje != null && s.mensaje !== ''
                        ? [{ act: 'copy-mensaje', label: 'Copiar mensaje', icon: 'fa-regular fa-copy', onSelect: () => copyToClipboard(String(s.mensaje)) }]
                        : []),
                ],
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const s  = senales.find(x => x.id === id);
                if (!s) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(s), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(s), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () =>
            openSignalsFiltersModal(state, allDispositivos, allDominios, ({ refetch }) => {
                if (refetch) refetchFromServer();
                else        applyAndRender();
            })
        );

        applyAndRender();
    }

    function openSignalsFiltersModal(state, allDispositivos, allDominios, onApply) {
        const devOpts = ['<option value="">Todos los dispositivos</option>'].concat(
            allDispositivos.map(d =>
                `<option value="${d.id}"${String(d.id) === state.dispositivo ? ' selected' : ''}>${escape(d.uid)} · ${escape(d.nombre)}</option>`
            )
        ).join('');
        const domOpts = ['<option value="">Todos los dominios</option>'].concat(
            allDominios.map(d =>
                `<option value="${d.id}"${String(d.id) === state.dominio ? ' selected' : ''}>${escape(d.nombre)}</option>`
            )
        ).join('');
        const sentOpts = ['<option value="">Todos los sentidos</option>'].concat(
            SENTIDOS_SENAL.map(s =>
                `<option value="${s.value}"${s.value === state.sentido ? ' selected' : ''}>${escape(s.label)}</option>`
            )
        ).join('');
        const ordOpts = ORDEN_SENALES.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="sig-fm-codigo">Código</label>
                    <input type="number" id="sig-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="sig-fm-texto">Buscar (topic / mensaje / dispositivo)</label>
                    <input type="search" id="sig-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="sig-fm-dispositivo">Dispositivo</label>
                    <select id="sig-fm-dispositivo">${devOpts}</select>
                </div>
                <div class="form-group">
                    <label for="sig-fm-dominio">Dominio</label>
                    <select id="sig-fm-dominio">${domOpts}</select>
                </div>
                <div class="form-group">
                    <label for="sig-fm-sentido">Sentido</label>
                    <select id="sig-fm-sentido">${sentOpts}</select>
                </div>
                <div class="form-group">
                    <label for="sig-fm-estado">Estado</label>
                    <input type="number" id="sig-fm-estado" placeholder="Valor exacto" value="${escape(state.estado)}">
                </div>
                <div class="form-group">
                    <label for="sig-fm-limit">Límite</label>
                    <input type="number" id="sig-fm-limit" min="1" max="2000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="sig-fm-orden">Ordenar por</label>
                    <select id="sig-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="sig-fm-dir">Dirección</label>
                    <select id="sig-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                const prevDispositivo = state.dispositivo;
                const prevLimit       = state.limit;

                state.codigo      = modal.querySelector('#sig-fm-codigo').value.trim();
                state.texto       = modal.querySelector('#sig-fm-texto').value.trim();
                state.dispositivo = modal.querySelector('#sig-fm-dispositivo').value;
                state.dominio     = modal.querySelector('#sig-fm-dominio').value;
                state.sentido     = modal.querySelector('#sig-fm-sentido').value;
                state.estado      = modal.querySelector('#sig-fm-estado').value.trim();
                state.orden       = modal.querySelector('#sig-fm-orden').value;
                state.dir         = modal.querySelector('#sig-fm-dir').value;
                state.limit       = readLimit(modal.querySelector('#sig-fm-limit'), 100);

                // Dispositivo y límite afectan el filtrado server-side (?dispositivo=&limit=);
                // el resto se aplica client-side sobre el set ya descargado.
                const needsRefetch = state.dispositivo !== prevDispositivo
                                  || state.limit       !== prevLimit;
                onApply({ refetch: needsRefetch });
            },
            onClear(modal) {
                const d = signalsDefaults();
                modal.querySelector('#sig-fm-codigo').value      = d.codigo;
                modal.querySelector('#sig-fm-texto').value       = d.texto;
                modal.querySelector('#sig-fm-dispositivo').value = d.dispositivo;
                modal.querySelector('#sig-fm-dominio').value     = d.dominio;
                modal.querySelector('#sig-fm-sentido').value     = d.sentido;
                modal.querySelector('#sig-fm-estado').value      = d.estado;
                modal.querySelector('#sig-fm-orden').value       = d.orden;
                modal.querySelector('#sig-fm-dir').value         = d.dir;
                modal.querySelector('#sig-fm-limit').value       = String(d.limit);
            },
        });
    }

    function openSignalViewModal(s) {
        const dispositivoValue = s.dispositivo_nombre
            ? `${escape(s.dispositivo_nombre)}${s.dispositivo_uuid ? ` <code>${escape(s.dispositivo_uuid)}</code>` : ''}`
            : `<span class="muted">Sin dispositivo asociado</span>`;
        const dominioValue = s.dominio_id
            ? `<span class="badge badge-info">${escape(s.dominio_nombre)}</span>`
            : `<span class="muted">—</span>`;
        const transceptorValue = s.transceptor_nombre
            ? `${escape(s.transceptor_nombre)} <code>#${s.transceptor}</code>`
            : (s.transceptor != null ? `<code>#${s.transceptor}</code>` : `<span class="muted">—</span>`);
        const canalValue   = s.canal   != null ? `<code>#${s.canal}</code>`   : `<span class="muted">—</span>`;
        const serieValue   = s.serie   != null ? escape(String(s.serie))      : `<span class="muted">—</span>`;
        const estadoValue  = s.estado  != null ? escape(String(s.estado))     : `<span class="muted">—</span>`;
        const mensajeValue = (s.mensaje != null && s.mensaje !== '')
            ? `<pre>${escape(s.mensaje)}</pre>`
            : `<span class="muted">Sin mensaje</span>`;
        const topicValue   = s.topic ? `<code>${escape(s.topic)}</code>` : `<span class="muted">—</span>`;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar señal</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',      `<code>#${s.id}</code>`),
                        viewCardHalf('Fecha',       escape(formatDate(s.fecha))),
                        viewCardHalf('Dispositivo', dispositivoValue),
                        viewCardHalf('Dominio',     dominioValue),
                        viewCardHalf('Sentido',     sentidoBadge(s.sentido)),
                        viewCardHalf('Estado',      estadoValue),
                        viewCardHalf('Canal',       canalValue),
                        viewCardHalf('Serie',       serieValue),
                        viewCardHalf('Transceptor', transceptorValue),
                        viewCardFull('Topic',       topicValue),
                        viewCardFull('Mensaje',     mensajeValue),
                    ])}
                </div>
                <div class="modal-footer">
                    <div class="action-menu action-menu-up" id="sig-view-menu" style="margin-right:auto">
                        <button class="btn btn-secondary" data-act="menu-toggle">
                            <i class="fa-solid fa-ellipsis"></i> Acciones
                        </button>
                        <div class="action-menu-dropdown" role="menu">
                            <button class="action-menu-item" data-act="go-device" role="menuitem">
                                <i class="fa-solid fa-satellite-dish"></i> Ver dispositivo
                            </button>
                            <button class="action-menu-item" data-act="copy-topic" role="menuitem">
                                <i class="fa-regular fa-copy"></i> Copiar topic
                            </button>
                            <button class="action-menu-item" data-act="copy-mensaje" role="menuitem">
                                <i class="fa-regular fa-copy"></i> Copiar mensaje
                            </button>
                        </div>
                    </div>
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));

        const menu       = backdrop.querySelector('#sig-view-menu');
        const menuToggle = menu.querySelector('[data-act="menu-toggle"]');

        menuToggle.addEventListener('click', e => {
            e.stopPropagation();
            menu.classList.toggle('open');
        });
        backdrop.addEventListener('click', e => {
            if (!menu.contains(e.target)) menu.classList.remove('open');
        });

        menu.querySelectorAll('.action-menu-item').forEach(item => {
            item.addEventListener('click', () => {
                menu.classList.remove('open');
                const act = item.dataset.act;
                if (act === 'go-device') {
                    if (s.dispositivo) {
                        close();
                        window.location.hash = '#/dispositivos';
                    }
                } else if (act === 'copy-topic') {
                    copyToClipboard(s.topic || '');
                } else if (act === 'copy-mensaje') {
                    copyToClipboard(s.mensaje || '');
                }
            });
        });
    }

    /* ---------- Views: Registros ----------
     * Read-only: los registros los genera el sistema. Sigue las
     * convenciones de listado ABM (header + KPIs + toolbar + tabla con
     * columna `Código` primero y columna `Consultar` al final). No tiene
     * Editar / Eliminar — los registros son inmutables, por lo que el
     * toolbar omite el botón `+ Nuevo` (DESIGN.md §9).
     *
     * Tabla real: db/schema.sql -> `registros` (id, fecha, sentido,
     * usuario, dominio, dispositivo, canal, estado). Las FKs se
     * resuelven en el backend con LEFT JOIN sobre `dispositivos`,
     * `dominios` y `usuarios`.
     */
    const ORDEN_REGISTROS = [
        { value: 'id',          label: 'Código'      },
        { value: 'fecha',       label: 'Fecha'       },
        { value: 'dispositivo', label: 'Dispositivo' },
        { value: 'usuario',     label: 'Usuario'     },
        { value: 'canal',       label: 'Canal'       },
        { value: 'estado',      label: 'Estado'      },
    ];

    function registrosDefaults() {
        return {
            codigo: '', texto: '', dispositivo: '', dominio: '',
            sentido: '', estado: '',
            orden: 'id', dir: 'desc', limit: 100,
        };
    }

    async function renderRegistros(root) {
        try {
            const state = registrosDefaults();

            const qs = new URLSearchParams();
            qs.set('limit', String(state.limit));
            if (state.dispositivo) qs.set('dispositivo', state.dispositivo);

            const [data, devData, domData] = await Promise.all([
                api('registros.php?' + qs.toString()),
                api('dispositivos.php'),
                api('dominios.php'),
            ]);

            const r            = data.resumen;
            const dispositivos = devData.dispositivos;
            const dominios     = domData.dominios;
            let registros      = data.registros;

            root.innerHTML = `
                ${moduleHeader('Historial de registros', 'Bitácora del sistema: eventos asociados a dispositivos, dominios y usuarios, con fecha, sentido, canal y estado.')}
                <div class="stats-bar">
                    <div class="stat-card">
                        <span class="stat-label">Total</span>
                        <span class="stat-value">${r.total}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Últimas 24 h</span>
                        <span class="stat-value green">${r.ultimas_24h}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Dispositivos activos (24 h)</span>
                        <span class="stat-value">${r.dispositivos_activos}</span>
                    </div>
                    <div class="stat-card">
                        <span class="stat-label">Hoy</span>
                        <span class="stat-value orange">${r.hoy}</span>
                    </div>
                </div>
                ${abmToolbar({
                    idPrefix:         'reg',
                    quickPlaceholder: 'Buscar dispositivo, usuario, estado…',
                    newLabel:         null,
                })}
                <div class="table-card" id="reg-table"></div>
            `;

            wireRegistrosView(state, registros, dispositivos, dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    // Variante compacta para la card "Últimos registros" del dashboard.
    // Diferencias vs. registrosTableBody: oculta Código, Canal, Sentido,
    // Estado y la columna de acciones (ojo); el header de Fecha pasa a
    // llamarse "Hora". El filtro por sentido='S' lo aplica el endpoint.
    function registrosDashboardTableBody(registros) {
        if (!registros.length) {
            return `<div class="table-empty">No hay registros que coincidan con los filtros.</div>`;
        }

        const rows = registros.map(r => `
            <tr data-id="${r.id}">
                <td>
                    <div class="td-id">${escape(formatDateOnly(r.fecha))}</div>
                    <div class="td-id">${escape(formatTime(r.fecha))}</div>
                </td>
                <td>${r.dominio ? `<span class="badge badge-info">${escape(r.dominio_nombre)}</span>` : '<span class="td-id">—</span>'}</td>
                <td>
                    <div class="td-nombre">${escape(r.dispositivo_nombre ?? '—')}</div>
                </td>
                <td>
                    ${r.usuario_nombre
                        ? `<div class="td-nombre">${escape(r.usuario_nombre)}</div>`
                        : '<span class="td-id">—</span>'}
                </td>
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Hora</th>
                        <th>Dominio</th>
                        <th>Dispositivo</th>
                        <th>Usuario</th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function registrosTableBody(registros) {
        if (!registros.length) {
            return `<div class="table-empty">No hay registros que coincidan con los filtros.</div>`;
        }

        const rows = registros.map(r => `
            <tr data-id="${r.id}">
                <td><span class="td-id">#${r.id}</span></td>
                <td><span class="td-id">${formatDate(r.fecha)}</span></td>
                <td>
                    <div class="td-nombre">${escape(r.dispositivo_nombre ?? '—')}</div>
                    ${r.dispositivo_uuid ? `<div class="td-id">${escape(r.dispositivo_uuid)}</div>` : ''}
                </td>
                <td>${r.dominio ? `<span class="badge badge-info">${escape(r.dominio_nombre)}</span>` : '<span class="td-id">—</span>'}</td>
                <td>${r.canal != null ? `<span class="td-id">#${r.canal}</span>` : '<span class="td-id">—</span>'}</td>
                <td>${sentidoBadge(r.sentido)}</td>
                <td>
                    ${r.usuario_nombre ? `<div class="td-nombre">${escape(r.usuario_nombre)}</div>` : ''}
                    ${r.usuario_login
                        ? `<div class="td-id">${escape(r.usuario_login)}</div>`
                        : (!r.usuario_nombre ? '<span class="td-id">—</span>' : '')}
                </td>
                <td>${r.estado != null && r.estado !== '' ? escape(r.estado) : '<span class="td-id">—</span>'}</td>
                ${actionCells()}
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Fecha</th>
                        <th>Dispositivo</th>
                        <th>Dominio</th>
                        <th>Canal</th>
                        <th>Sentido</th>
                        <th>Usuario</th>
                        <th>Estado</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function wireRegistrosView(state, allRegistros, allDispositivos, allDominios) {
        const tableWrap = document.getElementById('reg-table');
        const quick     = document.getElementById('reg-quick');
        const quickClr  = document.querySelector('.toolbar [data-act="quick-clear"]');
        const btnFilt   = document.getElementById('reg-filters');

        let registros = allRegistros;

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const codigo = parseInt(state.codigo, 10);
            const estadoQ = state.estado.toLowerCase();

            let filtered = registros.filter(r => {
                if (Number.isFinite(codigo) && r.id !== codigo) return false;
                if (state.dispositivo && String(r.dispositivo) !== state.dispositivo) return false;
                if (state.dominio && String(r.dominio ?? '') !== state.dominio) return false;
                if (state.sentido && r.sentido !== state.sentido) return false;
                if (estadoQ && !String(r.estado ?? '').toLowerCase().includes(estadoQ)) return false;
                if (q && !((r.dispositivo_nombre ?? '') + ' ' +
                           (r.dispositivo_uuid   ?? '') + ' ' +
                           (r.usuario_nombre     ?? '') + ' ' +
                           (r.usuario_login      ?? '') + ' ' +
                           (r.estado             ?? ''))
                    .toLowerCase().includes(q)) return false;
                return true;
            });

            filtered.sort((a, b) => {
                const va = a[state.orden] ?? '';
                const vb = b[state.orden] ?? '';
                const cmp = String(va).localeCompare(String(vb), 'es', { numeric: true });
                return state.dir === 'asc' ? cmp : -cmp;
            });

            tableWrap.innerHTML = registrosTableBody(filtered.slice(0, state.limit));
            wireRowActions();
        }

        async function refetchFromServer() {
            const qs = new URLSearchParams();
            qs.set('limit', String(state.limit));
            if (state.dispositivo) qs.set('dispositivo', state.dispositivo);

            tableWrap.innerHTML = `<div class="table-empty"><div class="spin"></div></div>`;
            try {
                const data = await api('registros.php?' + qs.toString());
                registros = data.registros;
                applyAndRender();
            } catch (e) {
                tableWrap.innerHTML = errorBox(e.message);
            }
        }

        function rowMenuFor(r) {
            return standardRowMenuItems({
                view: true, onView: () => openRegistroViewModal(r),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const r  = registros.find(x => x.id === id);
                if (!r) return;
                tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                    e.stopPropagation();
                    openRowMenu(rowMenuFor(r), e.currentTarget);
                });
                tr.addEventListener('contextmenu', e => {
                    e.preventDefault();
                    openRowMenu(rowMenuFor(r), { x: e.clientX, y: e.clientY });
                });
            });
        }

        quick.value = state.texto;
        quick.addEventListener('input', () => { state.texto = quick.value.trim(); applyAndRender(); });
        quickClr.addEventListener('click', () => {
            quick.value = ''; state.texto = ''; applyAndRender(); quick.focus();
        });

        btnFilt.addEventListener('click', () =>
            openRegistrosFiltersModal(state, allDispositivos, allDominios, ({ refetch }) => {
                if (refetch) refetchFromServer();
                else        applyAndRender();
            })
        );

        applyAndRender();
    }

    function openRegistrosFiltersModal(state, allDispositivos, allDominios, onApply) {
        const devOpts = ['<option value="">Todos los dispositivos</option>'].concat(
            allDispositivos.map(d =>
                `<option value="${d.id}"${String(d.id) === state.dispositivo ? ' selected' : ''}>${escape(d.uid)} · ${escape(d.nombre)}</option>`
            )
        ).join('');
        const domOpts = ['<option value="">Todos los dominios</option>'].concat(
            allDominios.map(d =>
                `<option value="${d.id}"${String(d.id) === state.dominio ? ' selected' : ''}>${escape(d.nombre)}</option>`
            )
        ).join('');
        const sentOpts = ['<option value="">Todos los sentidos</option>'].concat(
            SENTIDOS_SENAL.map(s =>
                `<option value="${s.value}"${s.value === state.sentido ? ' selected' : ''}>${escape(s.label)}</option>`
            )
        ).join('');
        const ordOpts = ORDEN_REGISTROS.map(o =>
            `<option value="${o.value}"${o.value === state.orden ? ' selected' : ''}>${escape(o.label)}</option>`
        ).join('');

        const bodyHtml = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="reg-fm-codigo">Código</label>
                    <input type="number" id="reg-fm-codigo" min="1" placeholder="ID exacto" value="${escape(state.codigo)}">
                </div>
                <div class="form-group">
                    <label for="reg-fm-texto">Buscar (dispositivo / usuario / estado)</label>
                    <input type="search" id="reg-fm-texto" placeholder="Texto libre" value="${escape(state.texto)}">
                </div>
                <div class="form-group">
                    <label for="reg-fm-dispositivo">Dispositivo</label>
                    <select id="reg-fm-dispositivo">${devOpts}</select>
                </div>
                <div class="form-group">
                    <label for="reg-fm-dominio">Dominio</label>
                    <select id="reg-fm-dominio">${domOpts}</select>
                </div>
                <div class="form-group">
                    <label for="reg-fm-sentido">Sentido</label>
                    <select id="reg-fm-sentido">${sentOpts}</select>
                </div>
                <div class="form-group">
                    <label for="reg-fm-estado">Estado</label>
                    <input type="text" id="reg-fm-estado" placeholder="Coincidencia parcial" value="${escape(state.estado)}">
                </div>
                <div class="form-group">
                    <label for="reg-fm-limit">Límite</label>
                    <input type="number" id="reg-fm-limit" min="1" max="2000" value="${state.limit}">
                </div>
                <div class="form-group"></div>
                <div class="form-group">
                    <label for="reg-fm-orden">Ordenar por</label>
                    <select id="reg-fm-orden">${ordOpts}</select>
                </div>
                <div class="form-group">
                    <label for="reg-fm-dir">Dirección</label>
                    <select id="reg-fm-dir">
                        <option value="desc"${state.dir === 'desc' ? ' selected' : ''}>Descendente</option>
                        <option value="asc"${state.dir  === 'asc'  ? ' selected' : ''}>Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        openFiltersModal({
            bodyHtml,
            onApply(modal) {
                const prevDispositivo = state.dispositivo;
                const prevLimit       = state.limit;

                state.codigo      = modal.querySelector('#reg-fm-codigo').value.trim();
                state.texto       = modal.querySelector('#reg-fm-texto').value.trim();
                state.dispositivo = modal.querySelector('#reg-fm-dispositivo').value;
                state.dominio     = modal.querySelector('#reg-fm-dominio').value;
                state.sentido     = modal.querySelector('#reg-fm-sentido').value;
                state.estado      = modal.querySelector('#reg-fm-estado').value.trim();
                state.orden       = modal.querySelector('#reg-fm-orden').value;
                state.dir         = modal.querySelector('#reg-fm-dir').value;
                state.limit       = readLimit(modal.querySelector('#reg-fm-limit'), 100);

                // Dispositivo y límite viajan al backend (?dispositivo=&limit=);
                // el resto se aplica client-side sobre el set ya descargado.
                const needsRefetch = state.dispositivo !== prevDispositivo
                                  || state.limit       !== prevLimit;
                onApply({ refetch: needsRefetch });
            },
            onClear(modal) {
                const d = registrosDefaults();
                modal.querySelector('#reg-fm-codigo').value      = d.codigo;
                modal.querySelector('#reg-fm-texto').value       = d.texto;
                modal.querySelector('#reg-fm-dispositivo').value = d.dispositivo;
                modal.querySelector('#reg-fm-dominio').value     = d.dominio;
                modal.querySelector('#reg-fm-sentido').value     = d.sentido;
                modal.querySelector('#reg-fm-estado').value      = d.estado;
                modal.querySelector('#reg-fm-orden').value       = d.orden;
                modal.querySelector('#reg-fm-dir').value         = d.dir;
                modal.querySelector('#reg-fm-limit').value       = String(d.limit);
            },
        });
    }

    function openRegistroViewModal(r) {
        const dispositivoValue = r.dispositivo_nombre
            ? `${escape(r.dispositivo_nombre)}${r.dispositivo_uuid ? ` <code>${escape(r.dispositivo_uuid)}</code>` : ''}`
            : (r.dispositivo != null ? `<code>#${r.dispositivo}</code>` : `<span class="muted">Sin dispositivo asociado</span>`);
        const dominioValue = r.dominio
            ? `<span class="badge badge-info">${escape(r.dominio_nombre)}</span>`
            : `<span class="muted">—</span>`;
        const usuarioValue = r.usuario_nombre
            ? `${escape(r.usuario_nombre)}${r.usuario_login ? ` <code>${escape(r.usuario_login)}</code>` : ''}`
            : (r.usuario != null ? `<code>#${r.usuario}</code>` : `<span class="muted">—</span>`);
        const canalValue  = r.canal  != null ? `<code>#${r.canal}</code>` : `<span class="muted">—</span>`;
        const estadoValue = (r.estado != null && r.estado !== '')
            ? escape(String(r.estado))
            : `<span class="muted">—</span>`;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Consultar registro</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    ${viewGrid([
                        viewCardHalf('Código',      `<code>#${r.id}</code>`),
                        viewCardHalf('Fecha',       escape(formatDate(r.fecha))),
                        viewCardHalf('Dispositivo', dispositivoValue),
                        viewCardHalf('Dominio',     dominioValue),
                        viewCardHalf('Sentido',     sentidoBadge(r.sentido)),
                        viewCardHalf('Canal',       canalValue),
                        viewCardHalf('Usuario',     usuarioValue),
                        viewCardHalf('Estado',      estadoValue),
                    ])}
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const close = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', close));
    }

    /* ---------- Views: Herramientas ---------- */
    // Orden alfabético por título (crear_modulo_herramientas). Cuando agregues
    // una tarjeta nueva, insertala en el lugar que le corresponda por título.
    const toolsCatalog = [
        { icon: '🧩', title: 'Editor de parámetros',   desc: 'Variables runtime (variable / valor) que el resto del sistema lee.', action: abrirEditorParametros },
        { icon: '🗄️', title: 'Explorador DB',           desc: 'Recorré las tablas de la base del entorno actual, ojeá su estructura y los últimos registros.', action: abrirExploradorDB },
        { icon: '📁', title: 'Explorador S3',           desc: 'Navegá, subí, descargá y eliminá carpetas y archivos del bucket del entorno actual.', action: abrirExploradorS3 },
        { icon: '📜', title: 'Migrador DB',            desc: 'Aplicá las migraciones pendientes de cloud/sql/migrations/ contra la BD del entorno actual.', action: abrirMigraciones },
        { icon: '⏰', title: 'Programador de tareas',   desc: 'Administrá los procesos automáticos programados (tabla tareas) y revisá el historial + log en vivo de cada ejecución.', action: abrirTareas },
        { icon: '📰', title: 'Visor de sucesos',       desc: 'Recorré el log de actividad (tabla sucesos_log) que los distintos módulos van registrando al trabajar.', action: abrirVisorSucesos },
    ];

    function renderTools(root) {
        root.innerHTML = `
            <div class="tile-grid">
                ${toolsCatalog.map((t, i) => `
                    <button type="button" class="tile-card" data-tool-idx="${i}">
                        <span class="tile-icon">${t.icon}</span>
                        <span class="tile-title">${escape(t.title)}</span>
                        <span class="tile-desc">${escape(t.desc)}</span>
                    </button>
                `).join('')}
            </div>
        `;

        root.querySelectorAll('.tile-card').forEach(btn => {
            btn.addEventListener('click', () => {
                const tool = toolsCatalog[+btn.dataset.toolIdx];
                if (typeof tool?.action === 'function') {
                    tool.action();
                } else {
                    toast(`${tool.title}: próximamente`);
                }
            });
        });
    }

    /* ---------- Herramientas: Editor de parámetros ---------- */
    // Tabla `parametros` (db/schema.sql): id, variable, valor, comentario.
    // El schema es el legacy compartido con las apps históricas de Reactor
    // (MyISAM utf8mb3, sin UNIQUE en variable, sin timestamps). Adaptamos la
    // UX del skill "crear_editor_de_parametros" al esquema real: los campos
    // del form se llaman "Variable" y "Comentario" (coincidiendo con la
    // columna) en vez de "Clave" y "Descripción". El menú contextual sigue
    // el orden del skill: Editar / Copiar variable / --- / Eliminar. Row
    // click → edición directa (no hay modal de Consulta separado — con 3
    // campos flat, la consulta colapsa con la edición).

    let _paramCache          = [];   // último listado recibido
    let _paramFiltroQ        = '';
    let _paramCtx            = null; // { listado: backdrop, form: backdrop|null }
    let _paramSearchTimer    = null;
    let _paramGuardando      = false;

    const _RE_VARIABLE_PARAM = /^[A-Za-z0-9_.\-]+$/;

    async function abrirEditorParametros() {
        if (_paramCtx && _paramCtx.listado && document.body.contains(_paramCtx.listado)) return;
        _paramFiltroQ = '';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" style="max-width:880px">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:1.2rem">🧩</span>
                        <span>Editor de parámetros</span>
                        <span id="paramResumen" class="modal-subtitle"></span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body" style="gap:12px">
                    <div class="toolbar" style="margin-bottom:0">
                        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
                            <div class="search-wrap">
                                <input class="search-input" type="search" id="paramSearch"
                                       placeholder="🔍 Buscar variable, valor, comentario…">
                                <button class="search-clear" id="paramSearchClear" style="display:none">×</button>
                            </div>
                            <button class="btn btn-ghost btn-sm" data-act="refresh" title="Refrescar">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-primary btn-sm" data-act="new">
                                <i class="fa-solid fa-plus"></i> Nuevo parámetro
                            </button>
                        </div>
                    </div>

                    <div class="table-card">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:80px">Código</th>
                                    <th style="width:220px">Variable</th>
                                    <th>Valor</th>
                                    <th>Comentario</th>
                                    <th style="width:60px;text-align:center">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="paramTbody">
                                <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        _paramCtx = { listado: backdrop, form: null };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrarEditorParametros(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarEditorParametros));
        backdrop.querySelector('[data-act="refresh"]').addEventListener('click', cargarParametros);
        backdrop.querySelector('[data-act="new"]').addEventListener('click', () => abrirFormParametro(null));

        const inputSearch = backdrop.querySelector('#paramSearch');
        const btnClear    = backdrop.querySelector('#paramSearchClear');
        inputSearch.addEventListener('input', () => paramOnSearch(inputSearch.value));
        btnClear.addEventListener('click', () => {
            inputSearch.value = '';
            paramLimpiarBusqueda();
            inputSearch.focus();
        });

        cargarParametros();
    }

    function cerrarEditorParametros() {
        if (!_paramCtx || !_paramCtx.listado) return;
        const bd = _paramCtx.listado;
        bd.classList.remove('open');
        setTimeout(() => bd.remove(), 200);
        _paramCtx = null;
    }

    function paramOnSearch(v) {
        _paramFiltroQ = (v || '').trim();
        if (_paramCtx) {
            _paramCtx.listado.querySelector('#paramSearchClear').style.display =
                _paramFiltroQ ? '' : 'none';
        }
        clearTimeout(_paramSearchTimer);
        // Filtrado 100% client-side sobre el cache — el endpoint no soporta
        // ?q. Debounce corto para no re-renderizar en cada tecla.
        _paramSearchTimer = setTimeout(() => renderParametros(_paramFiltroAplicado()), 150);
    }

    function paramLimpiarBusqueda() {
        _paramFiltroQ = '';
        if (_paramCtx) {
            _paramCtx.listado.querySelector('#paramSearch').value = '';
            _paramCtx.listado.querySelector('#paramSearchClear').style.display = 'none';
        }
        renderParametros(_paramCache);
    }

    function _paramFiltroAplicado() {
        if (!_paramFiltroQ) return _paramCache;
        const q = _paramFiltroQ.toLowerCase();
        return _paramCache.filter(p =>
            ((p.variable   ?? '') + ' ' +
             (p.valor      ?? '') + ' ' +
             (p.comentario ?? '')).toLowerCase().includes(q)
        );
    }

    async function cargarParametros() {
        if (!_paramCtx) return;
        const tbody = _paramCtx.listado.querySelector('#paramTbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;
        try {
            const data = await api('parametros.php');
            _paramCache = data.parametros || [];
            renderParametros(_paramFiltroAplicado());
            actualizarResumenParametros();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }

    function actualizarResumenParametros() {
        if (!_paramCtx) return;
        const total   = _paramCache.length;
        const shown   = _paramFiltroAplicado().length;
        const resumen = _paramCtx.listado.querySelector('#paramResumen');
        if (!resumen) return;
        resumen.textContent = _paramFiltroQ
            ? `${shown} de ${total} parámetros`
            : `${total} parámetros`;
    }

    function renderParametros(rows) {
        if (!_paramCtx) return;
        const tbody = _paramCtx.listado.querySelector('#paramTbody');
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty">No hay parámetros para mostrar.</td></tr>`;
            actualizarResumenParametros();
            return;
        }
        const dashVacio = `<span style="color:var(--muted)">—</span>`;
        tbody.innerHTML = rows.map(p => {
            const variable   = escape(p.variable   ?? '');
            const valorFull  = escape(p.valor      ?? '');
            const comentario = escape(p.comentario ?? '');
            return `
                <tr class="row-clickable" data-id="${p.id}">
                    <td class="td-id">#${p.id}</td>
                    <td style="font-family:monospace;font-weight:600">${variable}</td>
                    <td style="font-family:monospace;color:var(--muted);max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"
                        title="${valorFull}">
                        ${p.valor != null && p.valor !== '' ? valorFull : dashVacio}
                    </td>
                    <td style="font-size:.82rem;color:var(--muted)">${p.comentario != null && p.comentario !== '' ? comentario : dashVacio}</td>
                    <td style="text-align:center">
                        <div class="actions" style="justify-content:center">
                            <button class="btn-icon-sm" data-act="menu" title="Más acciones">
                                <i class="fa-solid fa-bars"></i>
                            </button>
                        </div>
                    </td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('tr[data-id]').forEach(tr => {
            const id = +tr.dataset.id;
            const p  = _paramCache.find(x => x.id === id);
            if (!p) return;
            // Row click → editar (skill: no hay Consulta separada).
            tr.addEventListener('click', e => {
                if (e.target.closest('button')) return;
                abrirFormParametro(p);
            });
            tr.querySelector('button[data-act="menu"]')?.addEventListener('click', e => {
                e.stopPropagation();
                openRowMenu(menuItemsParametro(p), e.currentTarget);
            });
            tr.addEventListener('contextmenu', e => {
                e.preventDefault();
                openRowMenu(menuItemsParametro(p), { x: e.clientX, y: e.clientY });
            });
        });
        actualizarResumenParametros();
    }

    // Menú contextual de fila (orden fijo del skill):
    //   Editar · Copiar variable · --- · Eliminar
    // No hay "Consultar" — el row click ya abre la edición y el modelo
    // (variable / valor / comentario) es flat.
    function menuItemsParametro(p) {
        return [
            { act: 'edit',   label: 'Editar',            icon: 'fa-pencil', onSelect: () => abrirFormParametro(p) },
            { act: 'copy',   label: 'Copiar variable',   icon: 'fa-copy',   onSelect: () => copiarVariableParametro(p) },
            { divider: true },
            { act: 'delete', label: 'Eliminar',          icon: 'fa-trash',  danger: true, onSelect: () => eliminarParametro(p) },
        ];
    }

    function copiarVariableParametro(p) {
        copyToClipboard(p.variable || '');
    }

    // Modal de Alta/Edición. `param === null` → alta; row completo → edición.
    // Se abre por ENCIMA del modal de listado (dos modales apilados). El
    // listado sigue visible detrás para dar contexto.
    function abrirFormParametro(param) {
        const isEdit = !!param;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" style="max-width:560px">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px">
                        <span style="font-size:1.2rem">🧩</span>
                        <span>${isEdit ? 'Editar parámetro' : 'Nuevo parámetro'}</span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="paramFormVariable">Variable</label>
                        <input type="text" id="paramFormVariable" maxlength="255"
                               autocomplete="off" autocapitalize="none" spellcheck="false"
                               style="font-family:monospace"
                               placeholder="ej.: smtp_host, moneda_default"
                               value="${escape(param?.variable ?? '')}">
                        <div class="field-error" id="paramFormVariableErr" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="paramFormValor">Valor</label>
                        <textarea id="paramFormValor" maxlength="255"
                                  rows="3" style="font-family:monospace"
                                  placeholder="Valor del parámetro…">${escape(param?.valor ?? '')}</textarea>
                        <div class="field-error" id="paramFormValorErr" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="paramFormComentario">
                            Comentario <span style="font-weight:400;color:var(--muted)">— opcional</span>
                        </label>
                        <input type="text" id="paramFormComentario" maxlength="1024"
                               placeholder="Para qué se usa este parámetro"
                               value="${escape(param?.comentario ?? '')}">
                        <div class="field-error" id="paramFormComentarioErr" style="display:none"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear parámetro'}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        if (_paramCtx) _paramCtx.form = backdrop;

        const cerrar = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
            if (_paramCtx) _paramCtx.form = null;
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrar(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrar));

        const varInput = backdrop.querySelector('#paramFormVariable');
        const valInput = backdrop.querySelector('#paramFormValor');
        const comInput = backdrop.querySelector('#paramFormComentario');
        const varErr   = backdrop.querySelector('#paramFormVariableErr');
        const saveBtn  = backdrop.querySelector('[data-act="save"]');

        // Al editar, foco en el campo Valor (la variable ya suele ser conocida).
        // Al crear, foco en Variable.
        if (isEdit) { valInput.focus(); }
        else        { varInput.focus(); varInput.select(); }

        const limpiarErrores = () => {
            varErr.style.display = 'none';
            varInput.classList.remove('input-invalid');
        };

        const mostrarErrorVariable = (msg) => {
            varErr.textContent = msg;
            varErr.style.display = 'block';
            varInput.classList.add('input-invalid');
            varInput.focus();
        };

        saveBtn.addEventListener('click', async () => {
            if (_paramGuardando) return;
            limpiarErrores();

            const variable   = varInput.value.trim();
            const valor      = valInput.value; // no trimear — espacios pueden importar
            const comentario = comInput.value.trim();

            if (!variable) {
                mostrarErrorVariable('La variable es obligatoria.');
                return;
            }
            if (!_RE_VARIABLE_PARAM.test(variable)) {
                mostrarErrorVariable('Sólo letras, números, punto, guión y guión bajo.');
                return;
            }

            const payload = { variable, valor, comentario };
            _paramGuardando = true;
            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('parametros.php', { method: 'PUT', body: { id: param.id, ...payload } });
                    toast('Parámetro actualizado');
                } else {
                    await api('parametros.php', { method: 'POST', body: payload });
                    toast('Parámetro creado');
                }
                cerrar();
                cargarParametros();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            } finally {
                _paramGuardando = false;
            }
        });
    }

    function eliminarParametro(p) {
        confirmDialog(
            'Eliminar parámetro',
            `¿Eliminar el parámetro "${p.variable}"? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('parametros.php?id=' + p.id, { method: 'DELETE' });
                    toast('Parámetro eliminado');
                    cargarParametros();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    // Tecla Escape: cerrar en cascada (form → listado). El openRowMenu
    // ya tiene su propio handler de Escape que corre antes (cierra el
    // menú si está abierto).
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (_paramCtx && _paramCtx.form && _paramCtx.form.classList.contains('open')) {
            _paramCtx.form.classList.remove('open');
            const bd = _paramCtx.form;
            setTimeout(() => { bd.remove(); if (_paramCtx) _paramCtx.form = null; }, 200);
            return;
        }
        if (_paramCtx && _paramCtx.listado && _paramCtx.listado.classList.contains('open')) {
            cerrarEditorParametros();
        }
    });

    /* ---------- Herramientas: Migrador DB ---------- */
    // Tile de Herramientas que abre un modal con el listado de archivos
    // .sql de cloud/sql/migrations/, cruzado contra el ledger `migraciones`
    // de la BD del entorno actual. Cada fila muestra estado (pendiente /
    // aplicada / drift), tamaño, hash truncado, fecha de aplicación y
    // acciones ("Ver SQL" + "Aplicar" si corresponde).
    //
    // Endpoints:
    //   GET  api/migraciones.php               -> listado cruzado disco vs DB
    //   GET  api/migraciones_get.php?nombre=X  -> preview del contenido SQL
    //   POST api/migraciones_apply.php {nombre} -> aplicar una migración
    //
    // En producción el confirm se refuerza (título con ⚠, copy con
    // "(PRODUCCIÓN)", label "Aplicar en prod", danger:true). La aplicación
    // masiva es secuencial: si una falla, corta el loop y toastea
    // "corrida parcial".

    let _migradorCtx        = null; // refs a los backdrops abiertos (para ESC en cascada)
    let _migradorCargando   = false;
    let _migradorAplicando  = false;
    let _migradorCache      = [];
    let _migradorEnv        = 'unknown';
    let _migradorDatabase   = '';

    async function abrirMigraciones() {
        // Si ya hay un modal abierto, no hacer nada.
        if (_migradorCtx && _migradorCtx.listado && document.body.contains(_migradorCtx.listado)) return;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" style="max-width:960px">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:1.2rem">📜</span>
                        <span>Migrador DB</span>
                        <span class="badge badge-info" id="migrDbName" style="font-family:monospace">—</span>
                        <span class="badge" id="migrEnvBadge" style="font-family:monospace">—</span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body" style="gap:12px">
                    <div class="toolbar" style="margin-bottom:0">
                        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
                            <button class="btn btn-ghost btn-sm" data-act="refresh" title="Refrescar">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                            <span id="migrResumen" style="font-size:.82rem;color:var(--muted)"></span>
                        </div>
                        <div class="toolbar-right">
                            <button class="btn btn-primary btn-sm" id="migrBtnAplicarPendientes" data-act="apply-all" disabled>
                                Aplicar todas las pendientes
                            </button>
                        </div>
                    </div>

                    <div class="table-card" style="max-height:52vh;overflow-y:auto">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:110px;position:sticky;top:0;background:var(--bg);z-index:1">Estado</th>
                                    <th style="position:sticky;top:0;background:var(--bg);z-index:1">Archivo</th>
                                    <th style="width:90px;position:sticky;top:0;background:var(--bg);z-index:1">Tamaño</th>
                                    <th style="width:110px;position:sticky;top:0;background:var(--bg);z-index:1">Hash</th>
                                    <th style="width:160px;position:sticky;top:0;background:var(--bg);z-index:1">Aplicada</th>
                                    <th style="width:160px;text-align:center;position:sticky;top:0;background:var(--bg);z-index:1">Acciones</th>
                                </tr>
                            </thead>
                            <tbody id="migrTbody">
                                <tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="font-size:.78rem;color:var(--muted);line-height:1.5">
                        Los archivos viven en <code style="font-family:monospace">cloud/sql/migrations/</code>
                        y se aplican en orden alfabético. Cada migración se registra en la tabla
                        <code style="font-family:monospace">migraciones</code> de la BD del entorno actual
                        para no re-ejecutarse. <strong>El target es siempre la BD del propio panel.</strong>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        _migradorCtx = { listado: backdrop, preview: null };

        backdrop.addEventListener('click', e => {
            if (e.target === backdrop) cerrarMigraciones();
        });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarMigraciones));
        backdrop.querySelector('[data-act="refresh"]').addEventListener('click', cargarMigraciones);
        backdrop.querySelector('[data-act="apply-all"]').addEventListener('click', aplicarPendientesMigraciones);

        cargarMigraciones();
    }

    function cerrarMigraciones() {
        if (_migradorAplicando) { toast('Hay una migración en curso'); return; }
        if (!_migradorCtx || !_migradorCtx.listado) return;
        const bd = _migradorCtx.listado;
        bd.classList.remove('open');
        setTimeout(() => bd.remove(), 200);
        _migradorCtx = null;
    }

    async function cargarMigraciones() {
        if (_migradorCargando || !_migradorCtx) return;
        _migradorCargando = true;

        const tbody = _migradorCtx.listado.querySelector('#migrTbody');
        tbody.innerHTML = `<tr><td colspan="6" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

        try {
            const data = await api('migraciones.php');
            _migradorCache    = data.items    || [];
            _migradorEnv      = (data.env     || 'unknown').toLowerCase();
            _migradorDatabase = data.database || '';

            const dbEl  = _migradorCtx.listado.querySelector('#migrDbName');
            const envEl = _migradorCtx.listado.querySelector('#migrEnvBadge');
            dbEl.textContent  = _migradorDatabase || '—';
            envEl.textContent = _migradorEnv;
            const envCls = ({
                production:  'badge-danger',
                development: 'badge-success',
            })[_migradorEnv] || 'badge-warn';
            envEl.className = 'badge ' + envCls;
            envEl.style.fontFamily = 'monospace';

            renderMigraciones(_migradorCache);
            actualizarResumenMigraciones();
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
            actualizarResumenMigraciones(0, 0, 0, 0);
        } finally {
            _migradorCargando = false;
        }
    }

    function renderMigraciones(rows) {
        if (!_migradorCtx) return;
        const tbody = _migradorCtx.listado.querySelector('#migrTbody');
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-empty">No se encontraron archivos de migración.</td></tr>`;
            return;
        }
        // Pendientes arriba (orden ascendente = cronológico); aplicadas debajo
        // por id DESC (última aplicada arriba). El cache queda en orden ascendente
        // para que aplicarPendientesMigraciones() corra vieja -> nueva.
        const pendientes = rows.filter(m => m.estado === 'pendiente');
        const aplicadas  = rows.filter(m => m.estado === 'aplicada')
                               .slice().sort((a, b) => (b.id || 0) - (a.id || 0));
        const ordenadas  = pendientes.concat(aplicadas);

        tbody.innerHTML = ordenadas.map(m => {
            let badge;
            if (m.estado === 'aplicada' && m.hash_drift) {
                badge = `<span class="badge badge-warn" title="El archivo cambió después de aplicarse">⚠ drift</span>`;
            } else if (m.estado === 'aplicada') {
                badge = `<span class="badge badge-success">aplicada</span>`;
            } else {
                badge = `<span class="badge badge-info">pendiente</span>`;
            }
            const aplicada = m.aplicada
                ? `<span style="font-family:monospace">${escape(m.aplicada)}</span>`
                : `<span style="color:var(--muted)">—</span>`;
            const btnAplicar = m.estado === 'pendiente'
                ? `<button class="btn btn-primary btn-sm" data-act="apply" data-nombre="${escape(m.nombre)}">Aplicar</button>`
                : '';
            return `
                <tr>
                    <td>${badge}</td>
                    <td style="font-family:monospace;font-weight:600">${escape(m.nombre)}</td>
                    <td style="font-size:.82rem;color:var(--muted)">${formatearTamanoBytes(m.tamano)}</td>
                    <td style="font-family:monospace;font-size:.78rem;color:var(--muted)" title="${escape(m.hash)}">${escape((m.hash || '').substring(0, 8))}</td>
                    <td>${aplicada}</td>
                    <td style="text-align:center">
                        <button class="btn btn-ghost btn-sm" data-act="preview" data-nombre="${escape(m.nombre)}">Ver SQL</button>
                        ${btnAplicar}
                    </td>
                </tr>
            `;
        }).join('');

        tbody.querySelectorAll('[data-act="preview"]').forEach(b => {
            b.addEventListener('click', () => verMigracion(b.dataset.nombre));
        });
        tbody.querySelectorAll('[data-act="apply"]').forEach(b => {
            b.addEventListener('click', () => aplicarMigracionConConfirmacion(b.dataset.nombre));
        });
    }

    function actualizarResumenMigraciones() {
        if (!_migradorCtx) return;
        const total     = _migradorCache.length;
        const aplicadas = _migradorCache.filter(m => m.estado === 'aplicada').length;
        const pendientes= _migradorCache.filter(m => m.estado === 'pendiente').length;
        const drift     = _migradorCache.filter(m => m.hash_drift).length;

        const resumen = _migradorCtx.listado.querySelector('#migrResumen');
        let txt = `${total} archivo${total === 1 ? '' : 's'} · ${aplicadas} aplicada${aplicadas === 1 ? '' : 's'} · ${pendientes} pendiente${pendientes === 1 ? '' : 's'}`;
        if (drift > 0) txt += ` · ⚠ ${drift} con drift de hash`;
        resumen.textContent = txt;

        const btn = _migradorCtx.listado.querySelector('#migrBtnAplicarPendientes');
        if (pendientes === 0) {
            btn.disabled    = true;
            btn.textContent = 'Sin pendientes';
        } else {
            btn.disabled    = false;
            btn.textContent = `Aplicar ${pendientes} pendiente${pendientes === 1 ? '' : 's'}`;
        }
    }

    function formatearTamanoBytes(n) {
        n = +n || 0;
        if (n < 1024)          return n + ' B';
        if (n < 1024 * 1024)   return (n / 1024).toFixed(1) + ' KB';
        return (n / (1024 * 1024)).toFixed(1) + ' MB';
    }

    async function verMigracion(nombre) {
        if (!_migradorCtx) return;
        // Modal de preview (max-width via modal-wide).
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:1.2rem">📜</span>
                        <span>Migración</span>
                        <span class="modal-subtitle"><code style="font-family:monospace" id="migrPreviewNombre">${escape(nombre)}</code></span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label>Contenido SQL (solo lectura)</label>
                        <textarea class="json-editor" id="migrPreviewSql" readonly spellcheck="false" autocomplete="off"><div class="spin"></div></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                    <button class="btn btn-primary" id="migrPreviewBtnAplicar" data-act="apply" style="display:none">Aplicar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        _migradorCtx.preview = backdrop;

        const cerrar = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
            if (_migradorCtx) _migradorCtx.preview = null;
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrar(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrar));
        backdrop.querySelector('[data-act="apply"]').addEventListener('click', () => {
            cerrar();
            aplicarMigracionConConfirmacion(nombre);
        });

        const ta      = backdrop.querySelector('#migrPreviewSql');
        const btnApli = backdrop.querySelector('#migrPreviewBtnAplicar');
        ta.value = 'Cargando…';

        try {
            const data = await api('migraciones_get.php?nombre=' + encodeURIComponent(nombre));
            ta.value = data.contenido || '';
            const registro = _migradorCache.find(m => m.nombre === nombre);
            if (registro && registro.estado === 'pendiente') btnApli.style.display = '';
        } catch (e) {
            ta.value = '✗ ' + e.message;
        }
    }

    function aplicarMigracionConConfirmacion(nombre) {
        const esProd  = _migradorEnv === 'production';
        const marker  = esProd ? ' (PRODUCCIÓN)' : '';
        const titulo  = esProd ? '⚠ Aplicar en PRODUCCIÓN' : 'Aplicar migración';
        const mensaje = `Vas a aplicar «${nombre}» contra la base ${_migradorDatabase || '?'}${marker}. `
                      + `Las sentencias DDL no se pueden deshacer. ¿Continuar?`;
        confirmarMigrador(titulo, mensaje, esProd ? 'Aplicar en prod' : 'Aplicar', esProd, () => {
            aplicarMigracionSinConfirmar(nombre);
        });
    }

    async function aplicarMigracionSinConfirmar(nombre) {
        if (_migradorAplicando) return;
        _migradorAplicando = true;
        try {
            const data = await api('migraciones_apply.php', { method: 'POST', body: { nombre } });
            toast(`Aplicada «${nombre}» en ${data.duracion_ms} ms.`);
            await cargarMigraciones();
        } catch (e) {
            toast(e.message || 'Error al aplicar.', { error: true, duration: 10000 });
        } finally {
            _migradorAplicando = false;
        }
    }

    async function aplicarPendientesMigraciones() {
        const pendientes = _migradorCache.filter(m => m.estado === 'pendiente');
        if (!pendientes.length) return;

        const esProd  = _migradorEnv === 'production';
        const marker  = esProd ? ' (PRODUCCIÓN)' : '';
        const titulo  = esProd ? '⚠ Aplicar en PRODUCCIÓN' : 'Aplicar migraciones pendientes';
        const mensaje = `Vas a aplicar ${pendientes.length} migración(es) contra la base `
                      + `${_migradorDatabase || '?'}${marker} en orden alfabético. `
                      + `Si una falla, se detiene la corrida y las anteriores quedan aplicadas. ¿Continuar?`;
        confirmarMigrador(titulo, mensaje, esProd ? 'Aplicar en prod' : 'Aplicar', esProd, async () => {
            if (_migradorAplicando || !_migradorCtx) return;
            _migradorAplicando = true;
            const btn        = _migradorCtx.listado.querySelector('#migrBtnAplicarPendientes');
            const labelOrig  = btn.textContent;
            btn.disabled     = true;
            let aplicadas    = 0;
            try {
                for (const m of pendientes) {
                    btn.textContent = `Aplicando ${m.nombre}…`;
                    try {
                        await api('migraciones_apply.php', { method: 'POST', body: { nombre: m.nombre } });
                        aplicadas++;
                    } catch (e) {
                        toast(`Falló «${m.nombre}»: ${e.message}`, { error: true, duration: 10000 });
                        break;
                    }
                }
                if (aplicadas === pendientes.length) {
                    toast(`Aplicadas ${aplicadas} migración(es).`);
                } else {
                    toast(`Corrida parcial: ${aplicadas} de ${pendientes.length} aplicadas.`,
                          { error: true, duration: 10000 });
                }
            } finally {
                _migradorAplicando = false;
                btn.textContent = labelOrig;
                await cargarMigraciones();
            }
        });
    }

    // Confirm reforzado para el Migrador DB. A diferencia de confirmDialog()
    // (que hardcodea el label "Eliminar" y siempre pinta el CTA como danger),
    // acá el label y la severidad varían según entorno: en prod el CTA
    // permanece rojo con "Aplicar en prod"; en dev es primario con "Aplicar".
    function confirmarMigrador(titulo, mensaje, ctaLabel, danger, onConfirm) {
        const backdrop = document.createElement('div');
        backdrop.className = 'confirm-backdrop';
        const cls = danger ? 'btn-danger' : 'btn-primary';
        backdrop.innerHTML = `
            <div class="confirm-box">
                <div class="confirm-title">${escape(titulo)}</div>
                <div class="confirm-msg">${escape(mensaje)}</div>
                <div class="confirm-actions">
                    <button class="btn btn-ghost" data-act="cancel">Cancelar</button>
                    <button class="btn ${cls}" data-act="ok">${escape(ctaLabel)}</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        const cerrar = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 150);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrar(); });
        backdrop.querySelector('[data-act="cancel"]').addEventListener('click', cerrar);
        backdrop.querySelector('[data-act="ok"]').addEventListener('click', () => {
            cerrar();
            try { onConfirm(); } catch (_) { /* noop */ }
        });
    }

    /* ---------- Herramientas: Visor de sucesos ---------- */
    // Tile de Herramientas que abre un modal con el listado de la tabla
    // `sucesos_log` (log de actividad de los módulos del panel cloud).
    // Estrictamente read-only: no hay alta/edición/borrado desde acá; la
    // escritura vive en api/lib/sucesos.php (registrarSuceso).
    //
    // Endpoint único:
    //   GET api/sucesos.php?q=&tipo=&desde=&hasta=&limite=  -> listado
    //   GET api/sucesos.php?id=N                            -> detalle

    let _sucesosCtx        = null;
    let _sucesosCache      = [];
    let _sucesosFiltroQ    = '';
    let _sucesosFiltroTipo = '';
    let _sucesosSearchTimer = null;

    const SUCESOS_TIPOS = {
        info:   { label: 'Info',   icon: 'fa-circle-info',          color: 'var(--info)'   },
        alerta: { label: 'Alerta', icon: 'fa-triangle-exclamation', color: 'var(--warn)'   },
        error:  { label: 'Error',  icon: 'fa-circle-exclamation',   color: 'var(--danger)' },
    };

    function sucesoTipoHtml(tipo) {
        const meta = SUCESOS_TIPOS[tipo] || SUCESOS_TIPOS.info;
        return `<span style="display:inline-flex;align-items:center;gap:6px">` +
                 `<i class="fa-solid ${meta.icon}" style="color:${meta.color}"></i>` +
                 `<span>${meta.label}</span>` +
               `</span>`;
    }

    // Copia el suceso completo al portapapeles con un formato pensado para
    // pegarse directo en un asistente de programación (metadatos etiquetados
    // + detalle envuelto entre triples backticks para que el asistente lo
    // trate como bloque literal y no confunda un stack trace o JSON con
    // instrucciones). Normaliza \r\n → \n antes de copiar.
    function sucesoDetalleCopiar(s) {
        if (!s) { toast('No hay suceso para copiar.', { error: true }); return; }
        const tipoMeta = SUCESOS_TIPOS[s.tipo] || SUCESOS_TIPOS.info;
        const partes = [
            'Suceso #' + (s.id ?? '—') + ' registrado en el panel.',
            '',
            'Fecha:   ' + (s.fecha  || '—'),
            'Origen:  ' + (s.origen || '—'),
            'Tipo:    ' + tipoMeta.label + ' (' + (s.tipo || 'info') + ')',
            '',
            'Detalle:',
            '```',
            (s.detalle || '').replace(/\r\n/g, '\n'),
            '```',
        ];
        // Reusa copyToClipboard (§utils) que ya cubre el fallback vía
        // execCommand para navegadores/contextos sin Clipboard API.
        copyToClipboard(partes.join('\n'));
    }

    async function abrirVisorSucesos() {
        if (_sucesosCtx && _sucesosCtx.listado && document.body.contains(_sucesosCtx.listado)) return;

        // Reset de filtros al montarse (el visor arranca en "Todos").
        _sucesosFiltroQ    = '';
        _sucesosFiltroTipo = '';

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" style="max-width:1100px">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:1.2rem">📰</span>
                        <span>Visor de sucesos</span>
                        <span id="sucesosResumen" class="modal-subtitle"></span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body" style="gap:12px">
                    <div class="toolbar" style="margin-bottom:0">
                        <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
                            <div class="search-wrap">
                                <input class="search-input" type="search" id="sucesosSearch" placeholder="🔍 Buscar origen, detalle…">
                                <button class="search-clear" id="sucesosSearchClear" style="display:none">×</button>
                            </div>
                            <div id="sucesosTipoChips" style="display:flex;gap:6px;flex-wrap:wrap">
                                <button type="button" class="filter-chip active" data-val="">Todos</button>
                                <button type="button" class="filter-chip"        data-val="info"><i class="fa-solid fa-circle-info" style="color:var(--info)"></i> Info</button>
                                <button type="button" class="filter-chip"        data-val="alerta"><i class="fa-solid fa-triangle-exclamation" style="color:var(--warn)"></i> Alerta</button>
                                <button type="button" class="filter-chip"        data-val="error"><i class="fa-solid fa-circle-exclamation" style="color:var(--danger)"></i> Error</button>
                            </div>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
                                Desde <input type="date" id="sucesosDesde">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
                                Hasta <input type="date" id="sucesosHasta">
                            </label>
                            <label style="display:flex;align-items:center;gap:6px;font-size:.82rem;color:var(--muted)">
                                Límite
                                <select id="sucesosLimite">
                                    <option value="100">100</option>
                                    <option value="200" selected>200</option>
                                    <option value="500">500</option>
                                    <option value="1000">1.000</option>
                                    <option value="2000">2.000</option>
                                </select>
                            </label>
                            <button class="btn btn-ghost btn-sm" data-act="refresh" title="Refrescar">
                                <i class="fa-solid fa-rotate"></i>
                            </button>
                        </div>
                    </div>

                    <div class="table-card">
                        <table>
                            <thead>
                                <tr>
                                    <th style="width:80px">ID</th>
                                    <th style="width:170px">Fecha</th>
                                    <th style="width:180px">Origen</th>
                                    <th style="width:120px">Tipo</th>
                                    <th>Detalle</th>
                                </tr>
                            </thead>
                            <tbody id="sucesosTbody">
                                <tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="font-size:.78rem;color:var(--muted);line-height:1.5">
                        Vista de solo lectura sobre la tabla
                        <code style="font-family:monospace">sucesos_log</code>.
                        Los registros se ordenan por <strong>id descendente</strong> (más recientes primero).
                        Tocá una fila para ver el detalle completo.
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        _sucesosCtx = { listado: backdrop, detalle: null };

        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrarVisorSucesos(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarVisorSucesos));
        backdrop.querySelector('[data-act="refresh"]').addEventListener('click', cargarSucesos);

        const inputSearch = backdrop.querySelector('#sucesosSearch');
        const btnClear    = backdrop.querySelector('#sucesosSearchClear');
        inputSearch.addEventListener('input', () => sucesosOnSearch(inputSearch.value));
        btnClear.addEventListener('click', () => {
            inputSearch.value = '';
            sucesosLimpiarBusqueda();
            inputSearch.focus();
        });

        backdrop.querySelectorAll('#sucesosTipoChips .filter-chip').forEach(chip => {
            chip.addEventListener('click', () => setFiltroTipoSucesos(chip, chip.dataset.val));
        });
        backdrop.querySelector('#sucesosDesde').addEventListener('change', cargarSucesos);
        backdrop.querySelector('#sucesosHasta').addEventListener('change', cargarSucesos);
        backdrop.querySelector('#sucesosLimite').addEventListener('change', cargarSucesos);

        cargarSucesos();
    }

    function cerrarVisorSucesos() {
        if (!_sucesosCtx || !_sucesosCtx.listado) return;
        const bd = _sucesosCtx.listado;
        bd.classList.remove('open');
        setTimeout(() => bd.remove(), 200);
        _sucesosCtx = null;
    }

    function sucesosOnSearch(v) {
        _sucesosFiltroQ = (v || '').trim();
        if (_sucesosCtx) {
            _sucesosCtx.listado.querySelector('#sucesosSearchClear').style.display =
                _sucesosFiltroQ ? '' : 'none';
        }
        clearTimeout(_sucesosSearchTimer);
        _sucesosSearchTimer = setTimeout(cargarSucesos, 250);
    }

    function sucesosLimpiarBusqueda() {
        _sucesosFiltroQ = '';
        if (_sucesosCtx) {
            _sucesosCtx.listado.querySelector('#sucesosSearch').value = '';
            _sucesosCtx.listado.querySelector('#sucesosSearchClear').style.display = 'none';
        }
        cargarSucesos();
    }

    function setFiltroTipoSucesos(chip, valor) {
        _sucesosFiltroTipo = valor || '';
        if (_sucesosCtx) {
            _sucesosCtx.listado.querySelectorAll('#sucesosTipoChips .filter-chip').forEach(c => {
                c.classList.toggle('active', c === chip);
            });
        }
        cargarSucesos();
    }

    async function cargarSucesos() {
        if (!_sucesosCtx) return;
        const tbody = _sucesosCtx.listado.querySelector('#sucesosTbody');
        tbody.innerHTML = `<tr><td colspan="5" style="text-align:center;padding:20px"><div class="spin"></div></td></tr>`;

        const desde  = _sucesosCtx.listado.querySelector('#sucesosDesde').value  || '';
        const hasta  = _sucesosCtx.listado.querySelector('#sucesosHasta').value  || '';
        const limite = _sucesosCtx.listado.querySelector('#sucesosLimite').value || '200';

        const params = new URLSearchParams();
        if (_sucesosFiltroQ)    params.set('q', _sucesosFiltroQ);
        if (_sucesosFiltroTipo) params.set('tipo', _sucesosFiltroTipo);
        if (desde)              params.set('desde', desde);
        if (hasta)              params.set('hasta', hasta);
        params.set('limite', limite);

        try {
            const data = await api('sucesos.php?' + params.toString());
            _sucesosCache = data.items || [];
            const resumen = _sucesosCtx.listado.querySelector('#sucesosResumen');
            if (resumen && data.stats) {
                const m = data.stats.mostrados ?? _sucesosCache.length;
                const t = data.stats.total     ?? m;
                resumen.textContent = `${m.toLocaleString('es-AR')} de ${t.toLocaleString('es-AR')} registros`;
            }
            renderSucesos(_sucesosCache);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }

    function renderSucesos(rows) {
        if (!_sucesosCtx) return;
        const tbody = _sucesosCtx.listado.querySelector('#sucesosTbody');
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="5" class="table-empty">Sin sucesos para mostrar.</td></tr>`;
            return;
        }
        const dashVacio = `<span style="color:var(--muted);font-style:italic">—</span>`;
        tbody.innerHTML = rows.map(s => {
            const fecha   = escape(s.fecha   || '');
            const origen  = escape(s.origen  || '');
            const detalle = escape(s.detalle || '');
            return `
                <tr class="row-clickable" data-id="${s.id}">
                    <td class="td-id">${s.id}</td>
                    <td style="font-family:monospace;white-space:nowrap">${fecha  || dashVacio}</td>
                    <td style="font-family:monospace;font-weight:600">${origen || dashVacio}</td>
                    <td>${sucesoTipoHtml(s.tipo)}</td>
                    <td style="color:var(--muted);max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${detalle}">${detalle}</td>
                </tr>
            `;
        }).join('');
        tbody.querySelectorAll('tr[data-id]').forEach(tr => {
            tr.addEventListener('click', () => sucesosVerDetalle(+tr.dataset.id));
        });
    }

    function sucesosVerDetalle(id) {
        if (!_sucesosCtx) return;
        const s = _sucesosCache.find(x => x.id === id);
        if (!s) return;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true" style="max-width:780px">
                <div class="modal-header">
                    <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                        <span style="font-size:1.2rem">📰</span>
                        <span>Suceso</span>
                        <span class="modal-subtitle">#${s.id}</span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" title="Cerrar" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label>Fecha</label>
                            <div style="font-family:monospace">${escape(s.fecha || '—')}</div>
                        </div>
                        <div class="form-group">
                            <label>Tipo</label>
                            <div style="display:flex;align-items:center;gap:6px">${sucesoTipoHtml(s.tipo)}</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Origen</label>
                        <div style="font-family:monospace">${escape(s.origen || '—')}</div>
                    </div>
                    <div class="form-group">
                        <label>Detalle</label>
                        <textarea class="json-editor" readonly spellcheck="false" autocomplete="off" style="min-height:260px;font-family:monospace"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost" data-act="copy" style="margin-right:auto"
                            title="Copiar el suceso al portapapeles para pegarlo en un asistente de programación">
                        <i class="fa-solid fa-copy"></i> Copiar
                    </button>
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        // El textarea usa `value=` en runtime — asignar como texto para no
        // sufrir el escape de HTML dentro del innerHTML.
        backdrop.querySelector('textarea').value = s.detalle || '';

        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));
        _sucesosCtx.detalle = backdrop;

        backdrop.querySelector('[data-act="copy"]').addEventListener('click', () => sucesoDetalleCopiar(s));

        const cerrar = () => {
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
            if (_sucesosCtx) _sucesosCtx.detalle = null;
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop) cerrar(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrar));
    }

    // Tecla Escape: cierra en cascada — primero cualquier modal secundario
    // (preview del migrador, detalle del visor), luego el listado.
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        // Migrador: preview → listado.
        if (_migradorCtx && _migradorCtx.preview && _migradorCtx.preview.classList.contains('open')) {
            _migradorCtx.preview.classList.remove('open');
            setTimeout(() => { if (_migradorCtx) { _migradorCtx.preview?.remove(); _migradorCtx.preview = null; } }, 200);
            return;
        }
        if (_migradorCtx && _migradorCtx.listado && _migradorCtx.listado.classList.contains('open')) {
            cerrarMigraciones();
            return;
        }
        // Visor de sucesos: detalle → listado.
        if (_sucesosCtx && _sucesosCtx.detalle && _sucesosCtx.detalle.classList.contains('open')) {
            _sucesosCtx.detalle.classList.remove('open');
            setTimeout(() => { if (_sucesosCtx) { _sucesosCtx.detalle?.remove(); _sucesosCtx.detalle = null; } }, 200);
            return;
        }
        if (_sucesosCtx && _sucesosCtx.listado && _sucesosCtx.listado.classList.contains('open')) {
            cerrarVisorSucesos();
        }
    });

    /* ================================================================
       Herramientas: Explorador DB
       Tabla de tablas + detalle con tabs Registros/Campos + edición inline.
       Endpoints: db_tables / db_describe / db_records / db_update.
       ================================================================ */
    let dbExpTablas       = [];
    let dbExpFiltro       = '';
    let dbExpTablaActual  = null;
    let dbExpDbName       = '';
    let dbExpEnv          = '';
    let dbExpRegistros    = [];
    let dbExpPkCols       = [];
    let dbExpAutoIncCols  = [];
    let dbExpNullableCols = [];
    let dbExpColsTabla    = [];
    let dbExpRegsTotal    = 0;
    let dbExpLimite       = 50;
    let dbExpFiltroRegs   = '';
    let _dbExpBackdrop    = null;

    async function abrirExploradorDB() {
        if (_dbExpBackdrop && document.body.contains(_dbExpBackdrop)) return;
        dbExpTablas = []; dbExpFiltro = ''; dbExpTablaActual = null;
        dbExpRegistros = []; dbExpFiltroRegs = '';

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'dbExpModalBackdrop';
        bd.innerHTML = `
          <div class="modal db-exp-modal" role="dialog" aria-modal="true">
            <div class="modal-header">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-size:1.2rem">🗄️</span>
                <span>Explorador DB</span>
                <span class="badge badge-info" id="dbExpDbName" style="font-family:monospace">—</span>
                <span class="badge" id="dbExpEnvBadge" style="font-family:monospace">—</span>
              </div>
              <button class="btn-icon-sm" data-act="close" title="Cerrar">×</button>
            </div>
            <div class="modal-body">
              <div class="db-exp-toolbar">
                <div class="db-exp-breadcrumbs" id="dbExpBreadcrumbs"></div>
                <div class="db-exp-toolbar-right">
                  <button class="btn btn-ghost btn-sm" data-act="refresh" title="Refrescar"><i class="fa-solid fa-rotate"></i></button>
                  <div class="search-wrap" id="dbExpSearchWrap">
                    <input type="search" id="dbExpSearch" class="search-input" placeholder="Buscar tabla…">
                    <button class="search-clear" data-act="clearSearch">×</button>
                  </div>
                </div>
              </div>
              <div class="db-exp-view" id="dbExpViewTables">
                <div class="table-card db-exp-table-card">
                  <table>
                    <thead><tr><th style="width:36px"></th><th>Tabla</th><th style="width:140px">Filas (aprox.)</th><th style="width:120px">Engine</th></tr></thead>
                    <tbody id="dbExpTablesTbody"><tr><td colspan="4" style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody>
                  </table>
                </div>
                <div class="db-exp-footer-info" id="dbExpTablesInfo"></div>
              </div>
              <div class="db-exp-view db-exp-view-detail" id="dbExpViewDetail" style="display:none">
                <div class="db-exp-tabs" role="tablist">
                  <button class="db-exp-tab active" data-tab="recs"><i class="fa-solid fa-table"></i> Registros <span class="db-exp-tab-count" id="dbExpRecsMeta"></span></button>
                  <button class="db-exp-tab" data-tab="cols"><i class="fa-solid fa-list-ul"></i> Campos <span class="db-exp-tab-count" id="dbExpColsMeta"></span></button>
                </div>
                <div class="db-exp-tabpanel" id="dbExpTabRecs">
                  <div class="db-exp-recs-toolbar">
                    <div class="db-exp-recs-toolbar-left">
                      <label class="db-exp-limite-label">Límite
                        <select id="dbExpLimite">
                          <option value="10">10</option><option value="50" selected>50</option><option value="100">100</option><option value="200">200</option><option value="500">500</option>
                        </select>
                      </label>
                    </div>
                    <div class="db-exp-recs-toolbar-right">
                      <div class="search-wrap"><input type="search" id="dbExpRecsSearch" class="search-input" placeholder="Buscar en los registros…"><button class="search-clear" data-act="clearRecsSearch">×</button></div>
                    </div>
                  </div>
                  <div class="table-card db-exp-table-card db-exp-recs-card db-exp-fill">
                    <table id="dbExpRecsTable"><thead><tr><th></th></tr></thead><tbody id="dbExpRecsTbody"><tr><td style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody></table>
                  </div>
                </div>
                <div class="db-exp-tabpanel" id="dbExpTabCols" hidden>
                  <div class="table-card db-exp-table-card db-exp-fill">
                    <table>
                      <thead><tr><th style="width:36px">#</th><th>Campo</th><th>Tipo</th><th style="width:70px">Null</th><th style="width:70px">Clave</th><th>Default</th><th>Extra</th></tr></thead>
                      <tbody id="dbExpColsTbody"><tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-ghost" data-act="close">Cerrar</button>
            </div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _dbExpBackdrop = bd;

        bd.addEventListener('click', e => { if (e.target === bd) cerrarExploradorDB(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarExploradorDB));
        bd.querySelector('[data-act="refresh"]').addEventListener('click', dbExpRecargar);
        bd.querySelector('#dbExpSearch').addEventListener('input', dbExpFiltrarTablas);
        bd.querySelector('[data-act="clearSearch"]').addEventListener('click', dbExpLimpiarBuscador);
        bd.querySelector('#dbExpLimite').addEventListener('change', dbExpCambiarLimite);
        bd.querySelector('#dbExpRecsSearch').addEventListener('input', dbExpFiltrarRegistros);
        bd.querySelector('[data-act="clearRecsSearch"]').addEventListener('click', dbExpLimpiarBuscadorRegs);
        bd.querySelectorAll('.db-exp-tab').forEach(t => t.addEventListener('click', () => dbExpCambiarTab(t.dataset.tab)));

        await dbExpCargarTablas();
    }
    function cerrarExploradorDB() {
        if (!_dbExpBackdrop) return;
        _dbExpBackdrop.classList.remove('open');
        setTimeout(() => { _dbExpBackdrop?.remove(); _dbExpBackdrop = null; }, 200);
    }
    function dbExpMostrarVista(v) {
        if (!_dbExpBackdrop) return;
        _dbExpBackdrop.querySelector('#dbExpViewTables').style.display  = v === 'tables' ? '' : 'none';
        _dbExpBackdrop.querySelector('#dbExpViewDetail').style.display  = v === 'detail' ? '' : 'none';
        _dbExpBackdrop.querySelector('#dbExpSearchWrap').style.display = v === 'tables' ? '' : 'none';
    }
    function dbExpRenderBreadcrumbs() {
        if (!_dbExpBackdrop) return;
        const el = _dbExpBackdrop.querySelector('#dbExpBreadcrumbs');
        let html = `<button class="db-exp-crumb" data-act="root"><i class="fa-solid fa-database"></i> ${escape(dbExpDbName)}</button>`;
        if (dbExpTablaActual) {
            html += `<span class="db-exp-crumb-sep">/</span><span class="db-exp-crumb current">${escape(dbExpTablaActual)}</span>`;
        }
        el.innerHTML = html;
        el.querySelector('[data-act="root"]')?.addEventListener('click', dbExpVolverATablas);
    }
    function dbExpVolverATablas() {
        dbExpTablaActual = null;
        dbExpMostrarVista('tables');
        dbExpRenderBreadcrumbs();
    }
    function dbExpRecargar() {
        if (dbExpTablaActual) {
            dbExpCargarRegistros(dbExpTablaActual);
            apiDbDescribe(dbExpTablaActual);
        } else {
            dbExpCargarTablas();
        }
    }
    async function dbExpCargarTablas() {
        try {
            const data = await api('db_tables.php');
            dbExpTablas = data.tablas || [];
            dbExpDbName = data.database || '';
            dbExpEnv    = (data.env || 'unknown').toLowerCase();
            const dbEl  = _dbExpBackdrop.querySelector('#dbExpDbName');
            const envEl = _dbExpBackdrop.querySelector('#dbExpEnvBadge');
            dbEl.textContent  = dbExpDbName || '—';
            envEl.textContent = dbExpEnv;
            const envCls = ({ production: 'badge-danger', development: 'badge-success' })[dbExpEnv] || 'badge-warn';
            envEl.className = 'badge ' + envCls;
            envEl.style.fontFamily = 'monospace';
            dbExpRenderBreadcrumbs();
            dbExpRenderTablas();
        } catch (e) {
            _dbExpBackdrop.querySelector('#dbExpTablesTbody').innerHTML =
                `<tr><td colspan="4" class="db-exp-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }
    function dbExpRenderTablas() {
        const tbody = _dbExpBackdrop.querySelector('#dbExpTablesTbody');
        const info  = _dbExpBackdrop.querySelector('#dbExpTablesInfo');
        const q = dbExpFiltro.toLowerCase();
        const rows = q ? dbExpTablas.filter(t => t.nombre.toLowerCase().includes(q)) : dbExpTablas;
        if (!rows.length) {
            tbody.innerHTML = `<tr><td colspan="4" class="db-exp-empty">${q ? 'Sin resultados para el filtro.' : 'No hay tablas.'}</td></tr>`;
        } else {
            tbody.innerHTML = rows.map(t => `
                <tr class="row-clickable" data-tabla="${escape(t.nombre)}">
                  <td><i class="fa-solid fa-table" style="color:var(--info)"></i></td>
                  <td><div class="db-exp-nombre">${escape(t.nombre)}</div>${t.comentario ? `<div class="db-exp-coment">${escape(t.comentario)}</div>` : ''}</td>
                  <td class="db-exp-num">${t.filas_aprox != null ? t.filas_aprox.toLocaleString('es-AR') : '—'}</td>
                  <td class="db-exp-mono">${escape(t.engine || '')}</td>
                </tr>`).join('');
            tbody.querySelectorAll('tr[data-tabla]').forEach(tr => {
                tr.addEventListener('click', () => dbExpAbrirTabla(tr.dataset.tabla));
            });
        }
        info.innerHTML = `<span>${rows.length} tabla${rows.length === 1 ? '' : 's'}${q ? ` (filtradas de ${dbExpTablas.length})` : ''}</span>`;
    }
    function dbExpFiltrarTablas() {
        dbExpFiltro = (_dbExpBackdrop.querySelector('#dbExpSearch').value || '').trim();
        dbExpRenderTablas();
    }
    function dbExpLimpiarBuscador() {
        _dbExpBackdrop.querySelector('#dbExpSearch').value = '';
        dbExpFiltro = ''; dbExpRenderTablas();
    }
    async function dbExpAbrirTabla(nombre) {
        dbExpTablaActual = nombre;
        dbExpFiltroRegs = '';
        _dbExpBackdrop.querySelector('#dbExpRecsSearch').value = '';
        dbExpMostrarVista('detail');
        dbExpRenderBreadcrumbs();
        dbExpCambiarTab('recs');
        _dbExpBackdrop.querySelector('#dbExpRecsTbody').innerHTML = `<tr><td style="text-align:center;padding:24px"><div class="spin"></div></td></tr>`;
        _dbExpBackdrop.querySelector('#dbExpColsTbody').innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>`;
        await Promise.all([apiDbDescribe(nombre), dbExpCargarRegistros(nombre)]);
    }
    function dbExpCambiarTab(t) {
        _dbExpBackdrop.querySelectorAll('.db-exp-tab').forEach(x => x.classList.toggle('active', x.dataset.tab === t));
        _dbExpBackdrop.querySelector('#dbExpTabRecs').hidden = t !== 'recs';
        _dbExpBackdrop.querySelector('#dbExpTabCols').hidden = t !== 'cols';
    }
    async function apiDbDescribe(nombre) {
        try {
            const data = await api('db_describe.php?tabla=' + encodeURIComponent(nombre));
            dbExpRenderColumnas(data.columnas || []);
        } catch (e) {
            _dbExpBackdrop.querySelector('#dbExpColsTbody').innerHTML =
                `<tr><td colspan="7" class="db-exp-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }
    function dbExpRenderColumnas(cols) {
        const tbody = _dbExpBackdrop.querySelector('#dbExpColsTbody');
        _dbExpBackdrop.querySelector('#dbExpColsMeta').textContent = cols.length;
        if (!cols.length) { tbody.innerHTML = `<tr><td colspan="7" class="db-exp-empty">Sin columnas.</td></tr>`; return; }
        const claveBadge = k => {
            if (k === 'PRI') return `<span class="badge badge-warn">PK</span>`;
            if (k === 'UNI') return `<span class="badge badge-info">UQ</span>`;
            if (k === 'MUL') return `<span class="badge">IDX</span>`;
            return '';
        };
        const nullBadge = n => n === 'YES' ? `<span class="badge badge-warn">YES</span>` : `<span class="badge" style="color:var(--muted)">NO</span>`;
        tbody.innerHTML = cols.map(c => `
            <tr>
              <td class="db-exp-num">${c.posicion}</td>
              <td><div class="db-exp-col-nombre">${escape(c.nombre)}</div>${c.comentario ? `<div class="db-exp-coment">${escape(c.comentario)}</div>` : ''}</td>
              <td class="db-exp-mono">${escape(c.tipo)}</td>
              <td>${nullBadge(c.nullable)}</td>
              <td>${claveBadge(c.clave)}</td>
              <td>${c.predeterminado == null ? `<span class="db-exp-null">NULL</span>` : `<code>${escape(String(c.predeterminado))}</code>`}</td>
              <td>${c.extra ? `<code>${escape(c.extra)}</code>` : ''}</td>
            </tr>`).join('');
    }
    async function dbExpCargarRegistros(nombre) {
        try {
            const data = await api(`db_records.php?tabla=${encodeURIComponent(nombre)}&limite=${dbExpLimite}`);
            dbExpRegistros    = data.registros || [];
            dbExpPkCols       = data.pk        || [];
            dbExpAutoIncCols  = data.auto_inc  || [];
            dbExpNullableCols = data.nullable  || [];
            dbExpColsTabla    = data.columnas  || [];
            dbExpRegsTotal    = data.total     || 0;
            dbExpPintarRegistros();
        } catch (e) {
            _dbExpBackdrop.querySelector('#dbExpRecsTbody').innerHTML =
                `<tr><td class="db-exp-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }
    function dbExpPintarRegistros() {
        const thead = _dbExpBackdrop.querySelector('#dbExpRecsTable thead');
        const tbody = _dbExpBackdrop.querySelector('#dbExpRecsTbody');
        thead.innerHTML = '<tr>' + dbExpColsTabla.map(c => {
            const esPk = dbExpPkCols.includes(c);
            return `<th>${esPk ? `<i class="fa-solid fa-key" style="color:var(--warn);margin-right:4px"></i>` : ''}${escape(c)}</th>`;
        }).join('') + '</tr>';

        const q = dbExpFiltroRegs.toLowerCase();
        const filas = q
            ? dbExpRegistros.filter(r => Object.values(r).some(v => (v == null ? '' : String(v)).toLowerCase().includes(q)))
            : dbExpRegistros;

        if (!filas.length) {
            tbody.innerHTML = `<tr><td colspan="${Math.max(1, dbExpColsTabla.length)}" class="db-exp-empty">${q ? `Sin resultados para "${escape(dbExpFiltroRegs)}"` : 'Esta tabla está vacía.'}</td></tr>`;
        } else {
            const tienePk = dbExpPkCols.length > 0;
            tbody.innerHTML = filas.map(r => {
                const rowIdx = dbExpRegistros.indexOf(r);
                return `<tr data-row="${rowIdx}">` + dbExpColsTabla.map(c => {
                    const v = r[c];
                    const esPk = dbExpPkCols.includes(c);
                    const esAi = dbExpAutoIncCols.includes(c);
                    let cls = 'db-exp-cell-edit', title = 'Doble click para editar';
                    if (!tienePk) { cls = 'db-exp-cell-lock'; title = 'No editable: la tabla no tiene PK'; }
                    else if (esPk) { cls = 'db-exp-cell-lock'; title = 'No editable: PK'; }
                    else if (esAi) { cls = 'db-exp-cell-lock'; title = 'No editable: auto_increment'; }
                    const dbl = cls === 'db-exp-cell-edit' ? ' ondblclick="void 0"' : '';
                    return `<td class="${cls}" data-col="${escape(c)}" title="${title}"${dbl}>${dbExpFmtValor(v)}</td>`;
                }).join('') + '</tr>';
            }).join('');
            // Wire dblclick on editable cells.
            tbody.querySelectorAll('td.db-exp-cell-edit').forEach(td => {
                td.addEventListener('dblclick', () => dbExpEditarCelda(td));
            });
        }
        const meta = _dbExpBackdrop.querySelector('#dbExpRecsMeta');
        let mt = `${filas.length}/${dbExpRegsTotal}`;
        if (q && filas.length !== dbExpRegistros.length) mt += ` (filtrados de ${dbExpRegistros.length})`;
        if (!dbExpPkCols.length && dbExpRegistros.length > 0) mt += ' · solo lectura';
        meta.textContent = mt;
    }
    function dbExpCambiarLimite() {
        dbExpLimite = parseInt(_dbExpBackdrop.querySelector('#dbExpLimite').value, 10) || 50;
        if (dbExpTablaActual) dbExpCargarRegistros(dbExpTablaActual);
    }
    function dbExpFiltrarRegistros() {
        dbExpFiltroRegs = (_dbExpBackdrop.querySelector('#dbExpRecsSearch').value || '').trim();
        dbExpPintarRegistros();
    }
    function dbExpLimpiarBuscadorRegs() {
        _dbExpBackdrop.querySelector('#dbExpRecsSearch').value = '';
        dbExpFiltroRegs = ''; dbExpPintarRegistros();
    }
    function dbExpFmtValor(v) {
        if (v == null) return `<span class="db-exp-null">NULL</span>`;
        if (v === '')  return `<span class="db-exp-null">""</span>`;
        return escape(String(v));
    }
    function dbExpEditarCelda(td) {
        if (td.querySelector('input')) return;
        const tr     = td.parentElement;
        const rowIdx = +tr.dataset.row;
        const col    = td.dataset.col;
        const reg    = dbExpRegistros[rowIdx];
        if (!reg) return;
        const original = reg[col];
        const puedeNull = dbExpNullableCols.includes(col);
        const cur = original == null ? '' : String(original);
        td.classList.add('db-exp-cell-editing');
        const nullBtn = puedeNull ? `<button class="btn-icon-sm" data-act="null" title="NULL">⊘</button>` : '';
        td.innerHTML = `
            <div class="db-exp-edit-wrap">
              <input class="db-exp-edit-input" value="${escape(cur)}">
              <div class="db-exp-edit-actions">
                <button class="btn-icon-sm" data-act="save" title="Guardar">✓</button>
                <button class="btn-icon-sm" data-act="cancel" title="Cancelar">✗</button>
                ${nullBtn}
              </div>
            </div>`;
        const inp = td.querySelector('input');
        inp.focus(); inp.select();
        const cerrar = () => {
            td.classList.remove('db-exp-cell-editing', 'db-exp-cell-saving');
            td.innerHTML = dbExpFmtValor(reg[col]);
        };
        const guardar = async (nuevoValor) => {
            if (nuevoValor === original || (nuevoValor == null && original == null)) { cerrar(); return; }
            td.classList.add('db-exp-cell-saving');
            try {
                const pk = Object.fromEntries(dbExpPkCols.map(c => [c, reg[c]]));
                const data = await api('db_update.php', { method: 'POST', body: { tabla: dbExpTablaActual, columna: col, pk, valor: nuevoValor }});
                reg[col] = data.valor_guardado;
                cerrar();
                td.classList.add('db-exp-cell-ok');
                setTimeout(() => td.classList.remove('db-exp-cell-ok'), 800);
            } catch (e) {
                td.classList.remove('db-exp-cell-saving');
                toast(e.message, { error: true, duration: 10000 });
                inp.focus();
            }
        };
        inp.addEventListener('keydown', e => {
            if (e.key === 'Enter') { e.preventDefault(); e.stopPropagation(); guardar(inp.value); }
            else if (e.key === 'Escape') { e.preventDefault(); e.stopPropagation(); cerrar(); }
        });
        td.querySelector('[data-act="save"]').addEventListener('click', () => guardar(inp.value));
        td.querySelector('[data-act="cancel"]').addEventListener('click', cerrar);
        td.querySelector('[data-act="null"]')?.addEventListener('click', () => guardar(null));
    }

    /* ================================================================
       Herramientas: Explorador S3
       ================================================================ */
    let s3ExpPrefix      = '';
    let s3ExpNextToken   = null;
    let s3ExpBucket      = '';
    let s3ExpCargando    = false;
    let s3ExpUltimaLista = { folders: [], objects: [] };
    let s3ExpFiltro      = '';
    let _s3ExpBackdrop   = null;

    async function abrirExploradorS3() {
        if (_s3ExpBackdrop && document.body.contains(_s3ExpBackdrop)) return;
        s3ExpPrefix = ''; s3ExpNextToken = null; s3ExpFiltro = '';
        s3ExpUltimaLista = { folders: [], objects: [] };

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 's3ExpModalBackdrop';
        bd.innerHTML = `
          <div class="modal s3-exp-modal" role="dialog" aria-modal="true">
            <div class="modal-header">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px;flex-wrap:wrap">
                <span style="font-size:1.2rem">☁️</span>
                <span>Explorador S3</span>
                <span class="badge badge-info" id="s3ExpBucket" style="font-family:monospace">—</span>
              </div>
              <button class="btn-icon-sm" data-act="close" title="Cerrar">×</button>
            </div>
            <div class="modal-body">
              <div class="s3-exp-toolbar">
                <div class="s3-exp-breadcrumbs" id="s3ExpBreadcrumbs"></div>
                <div class="s3-exp-toolbar-right">
                  <button class="btn btn-ghost btn-sm" data-act="refresh" title="Refrescar"><i class="fa-solid fa-rotate"></i></button>
                  <div class="s3-exp-search"><i class="fa-solid fa-magnifying-glass"></i><input type="search" id="s3ExpBuscador" placeholder="Buscar archivos…" autocomplete="off"></div>
                  <input type="file" id="s3ExpUploadInput" style="display:none">
                  <button class="btn btn-secondary btn-sm" data-act="upload"><i class="fa-solid fa-upload"></i> Subir</button>
                  <button class="btn btn-secondary btn-sm" data-act="mkdir"><i class="fa-solid fa-folder-plus"></i> Nueva carpeta</button>
                </div>
              </div>
              <div class="table-card s3-exp-table-card">
                <table>
                  <thead><tr><th style="width:36px"></th><th>Nombre</th><th style="width:120px">Tamaño</th><th style="width:160px">Modificado</th><th style="width:60px;text-align:center">Acciones</th></tr></thead>
                  <tbody id="s3ExpTbody"><tr><td colspan="5" style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody>
                </table>
              </div>
              <div class="s3-exp-footer-info" id="s3ExpFooterInfo"></div>
              <div style="text-align:center">
                <button class="btn btn-ghost btn-sm" id="s3ExpBtnMas" style="display:none">Cargar más</button>
              </div>
            </div>
            <div class="modal-footer"><button class="btn btn-ghost" data-act="close">Cerrar</button></div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _s3ExpBackdrop = bd;

        bd.addEventListener('click', e => { if (e.target === bd) cerrarExploradorS3(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarExploradorS3));
        bd.querySelector('[data-act="refresh"]').addEventListener('click', s3ExpRecargar);
        bd.querySelector('#s3ExpBuscador').addEventListener('input', s3ExpFiltrar);
        bd.querySelector('[data-act="upload"]').addEventListener('click', () => bd.querySelector('#s3ExpUploadInput').click());
        bd.querySelector('#s3ExpUploadInput').addEventListener('change', e => s3ExpSubirArchivo(e.target.files));
        bd.querySelector('[data-act="mkdir"]').addEventListener('click', s3ExpCrearCarpeta);
        bd.querySelector('#s3ExpBtnMas').addEventListener('click', s3ExpCargarMas);

        await s3ExpCargar(true);
    }
    function cerrarExploradorS3() {
        if (!_s3ExpBackdrop) return;
        _s3ExpBackdrop.classList.remove('open');
        setTimeout(() => { _s3ExpBackdrop?.remove(); _s3ExpBackdrop = null; }, 200);
    }
    function s3ExpRecargar() { s3ExpFiltro = ''; if (_s3ExpBackdrop) _s3ExpBackdrop.querySelector('#s3ExpBuscador').value = ''; s3ExpCargar(true); }
    function s3ExpNavegar(prefix) {
        s3ExpPrefix = prefix;
        s3ExpFiltro = '';
        if (_s3ExpBackdrop) _s3ExpBackdrop.querySelector('#s3ExpBuscador').value = '';
        s3ExpCargar(true);
    }
    async function s3ExpCargar(reiniciar) {
        if (s3ExpCargando) return;
        s3ExpCargando = true;
        try {
            if (reiniciar) {
                s3ExpNextToken = null;
                s3ExpUltimaLista = { folders: [], objects: [] };
            }
            const qs = new URLSearchParams();
            if (s3ExpPrefix) qs.set('prefix', s3ExpPrefix);
            if (s3ExpNextToken) qs.set('token', s3ExpNextToken);
            const data = await api('s3_list.php?' + qs.toString());
            s3ExpBucket = data.bucket || '';
            _s3ExpBackdrop.querySelector('#s3ExpBucket').textContent = s3ExpBucket || '—';

            if (reiniciar) {
                s3ExpUltimaLista.folders = data.folders || [];
                s3ExpUltimaLista.objects = data.objects || [];
            } else {
                s3ExpUltimaLista.objects = s3ExpUltimaLista.objects.concat(data.objects || []);
            }
            // Ordenar objects por last_modified desc.
            s3ExpUltimaLista.objects.sort((a, b) => {
                if (!a.last_modified) return 1;
                if (!b.last_modified) return -1;
                return b.last_modified.localeCompare(a.last_modified);
            });
            s3ExpNextToken = data.truncated ? data.next_token : null;
            const btnMas = _s3ExpBackdrop.querySelector('#s3ExpBtnMas');
            btnMas.style.display = s3ExpNextToken ? '' : 'none';
            btnMas.disabled = false; btnMas.textContent = 'Cargar más';

            s3ExpRenderBreadcrumbs(s3ExpPrefix);
            s3ExpRenderTabla(s3ExpPrefix);
        } catch (e) {
            _s3ExpBackdrop.querySelector('#s3ExpTbody').innerHTML =
                `<tr><td colspan="5" class="s3-exp-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        } finally {
            s3ExpCargando = false;
        }
    }
    function s3ExpCargarMas() {
        const btn = _s3ExpBackdrop.querySelector('#s3ExpBtnMas');
        btn.disabled = true; btn.textContent = 'Cargando…';
        s3ExpCargar(false);
    }
    function s3ExpFiltrar() {
        s3ExpFiltro = (_s3ExpBackdrop.querySelector('#s3ExpBuscador').value || '').trim().toLowerCase();
        s3ExpRenderTabla(s3ExpPrefix);
    }
    function s3ExpRenderBreadcrumbs(prefix) {
        const el = _s3ExpBackdrop.querySelector('#s3ExpBreadcrumbs');
        const parts = prefix ? prefix.replace(/\/$/, '').split('/') : [];
        let html = `<button class="s3-exp-crumb" data-p=""><i class="fa-solid fa-house"></i> raíz</button>`;
        let acc = '';
        parts.forEach((p, i) => {
            acc += p + '/';
            const isLast = i === parts.length - 1;
            html += `<span class="s3-exp-crumb-sep">/</span>` +
                    (isLast ? `<span class="s3-exp-crumb current">${escape(p)}</span>`
                            : `<button class="s3-exp-crumb" data-p="${escape(acc)}">${escape(p)}</button>`);
        });
        el.innerHTML = html;
        el.querySelectorAll('.s3-exp-crumb[data-p]').forEach(b => b.addEventListener('click', () => s3ExpNavegar(b.dataset.p)));
    }
    function s3ExpRenderTabla(prefix) {
        const tbody = _s3ExpBackdrop.querySelector('#s3ExpTbody');
        const info  = _s3ExpBackdrop.querySelector('#s3ExpFooterInfo');
        const relName = k => (k || '').startsWith(prefix) ? k.slice(prefix.length) : k;
        const foldersOrig = s3ExpUltimaLista.folders || [];
        const objectsOrig = s3ExpUltimaLista.objects || [];
        const folders = s3ExpFiltro ? foldersOrig.filter(f => relName(f).toLowerCase().includes(s3ExpFiltro)) : foldersOrig;
        const objects = s3ExpFiltro ? objectsOrig.filter(o => relName(o.key).toLowerCase().includes(s3ExpFiltro)) : objectsOrig;

        let rowsHtml = '';
        if (prefix) {
            const parent = prefix.replace(/[^/]+\/$/, '');
            rowsHtml += `<tr class="row-clickable" data-nav="${escape(parent)}"><td><i class="fa-solid fa-turn-up" style="transform:rotate(-90deg);color:var(--muted)"></i></td><td colspan="4" style="color:var(--muted)">..</td></tr>`;
        }
        folders.forEach(f => {
            const nm = relName(f);
            rowsHtml += `<tr class="row-clickable" data-nav="${escape(f)}">
              <td><i class="fa-solid fa-folder" style="color:var(--warn)"></i></td>
              <td><div class="s3-exp-nombre">${escape(nm)}</div></td>
              <td class="s3-exp-size">—</td><td class="s3-exp-date">—</td>
              <td style="text-align:center"><button class="btn-icon-sm" data-key="${escape(f)}" data-folder="1"><i class="fa-solid fa-bars"></i></button></td>
            </tr>`;
        });
        objects.forEach(o => {
            const nm = relName(o.key);
            const esImg = s3ExpEsImagen(nm);
            const icono = esImg
                ? `<img class="s3-exp-thumb" loading="lazy" src="${escape(o.url)}" onerror="this.replaceWith(Object.assign(document.createElement('i'),{className:'fa-solid fa-file-image'}))">`
                : `<i class="fa-solid ${s3ExpIconoArchivo(nm)}" style="color:var(--info)"></i>`;
            rowsHtml += `<tr class="row-clickable" data-url="${escape(o.url)}">
              <td>${icono}</td>
              <td><div class="s3-exp-nombre">${escape(nm)}</div></td>
              <td class="s3-exp-size">${s3ExpFmtBytes(o.size)}</td>
              <td class="s3-exp-date">${s3ExpFormatFecha(o.last_modified)}</td>
              <td style="text-align:center"><button class="btn-icon-sm" data-key="${escape(o.key)}" data-url="${escape(o.url)}"><i class="fa-solid fa-bars"></i></button></td>
            </tr>`;
        });
        if (!rowsHtml) {
            rowsHtml = `<tr><td colspan="5" class="s3-exp-empty">${s3ExpFiltro ? `Sin resultados para "${escape(s3ExpFiltro)}"` : 'Esta carpeta está vacía.'}</td></tr>`;
        }
        tbody.innerHTML = rowsHtml;

        tbody.querySelectorAll('tr[data-nav]').forEach(tr => tr.addEventListener('click', e => {
            if (e.target.closest('button')) return; s3ExpNavegar(tr.dataset.nav);
        }));
        tbody.querySelectorAll('tr[data-url]').forEach(tr => tr.addEventListener('click', e => {
            if (e.target.closest('button')) return; s3ExpAbrirArchivo(tr.dataset.url);
        }));
        tbody.querySelectorAll('button[data-key]').forEach(b => b.addEventListener('click', e => {
            e.stopPropagation();
            const items = [];
            const key = b.dataset.key, esFolder = !!b.dataset.folder, url = b.dataset.url || '';
            if (!esFolder) {
                items.push({ act: 'open', label: 'Abrir / Descargar', icon: 'fa-up-right-from-square', onSelect: () => s3ExpAbrirArchivo(url) });
                items.push({ act: 'copy', label: 'Copiar URL pública', icon: 'fa-link',                 onSelect: () => s3ExpCopiarUrl(url) });
                items.push({ divider: true });
            }
            items.push({ act: 'del', label: 'Eliminar', icon: 'fa-trash', danger: true, onSelect: () => s3ExpEliminar(key, esFolder) });
            openRowMenu(items, e.currentTarget);
        }));

        const bytes = objects.reduce((s, o) => s + (o.size || 0), 0);
        const totalObjects = objectsOrig.length;
        info.innerHTML = `<span>${folders.length} carpeta${folders.length === 1 ? '' : 's'} · ${objects.length} archivo${objects.length === 1 ? '' : 's'} · ${s3ExpFmtBytes(bytes)}${s3ExpFiltro ? ` (filtrado de ${totalObjects})` : ' en esta carpeta'}</span>`;
    }
    function s3ExpFmtBytes(n) { n = +n || 0; if (n < 1024) return n + ' B'; if (n < 1048576) return (n/1024).toFixed(1) + ' KB'; if (n < 1073741824) return (n/1048576).toFixed(1) + ' MB'; return (n/1073741824).toFixed(2) + ' GB'; }
    function s3ExpFormatFecha(iso) { if (!iso) return '—'; const d = new Date(iso); if (isNaN(d)) return iso; const p = n => String(n).padStart(2,'0'); return `${d.getFullYear()}-${p(d.getMonth()+1)}-${p(d.getDate())} ${p(d.getHours())}:${p(d.getMinutes())}`; }
    function s3ExpEsImagen(n) { return /\.(jpe?g|png|gif|webp|bmp|svg|avif)$/i.test(n); }
    function s3ExpIconoArchivo(n) {
        const e = n.split('.').pop().toLowerCase();
        return { pdf:'fa-file-pdf', mp4:'fa-file-video', mov:'fa-file-video', avi:'fa-file-video', mp3:'fa-file-audio', wav:'fa-file-audio', zip:'fa-file-zipper', rar:'fa-file-zipper', csv:'fa-file-csv', xls:'fa-file-excel', xlsx:'fa-file-excel', doc:'fa-file-word', docx:'fa-file-word', txt:'fa-file-lines', log:'fa-file-lines', json:'fa-file-code', xml:'fa-file-code', html:'fa-file-code' }[e] || 'fa-file';
    }
    function s3ExpAbrirArchivo(url) { window.open(url, '_blank', 'noopener'); }
    function s3ExpCopiarUrl(url) { copyToClipboard(url); }
    async function s3ExpSubirArchivo(files) {
        if (!files || !files.length) return;
        const file = files[0];
        toast('Subiendo ' + file.name + '…');
        const fd = new FormData();
        fd.append('archivo', file);
        fd.append('prefix', s3ExpPrefix);
        fd.append('nombre', file.name);
        try {
            const res = await fetch('api/s3_upload.php', { method: 'POST', credentials: 'same-origin', body: fd });
            const body = await res.json();
            if (res.status === 401) { window.location.href = 'login.php'; return; }
            if (!res.ok || body.ok === false) throw new Error(body.error || ('HTTP ' + res.status));
            toast('Archivo subido');
            _s3ExpBackdrop.querySelector('#s3ExpUploadInput').value = '';
            s3ExpCargar(true);
        } catch (e) {
            toast(e.message, { error: true, duration: 10000 });
        }
    }
    async function s3ExpCrearCarpeta() {
        const nombre = prompt('Nombre de la nueva carpeta:');
        if (!nombre) return;
        try {
            await api('s3_create_folder.php', { method: 'POST', body: { prefix: s3ExpPrefix, nombre }});
            toast('Carpeta creada');
            s3ExpCargar(true);
        } catch (e) { toast(e.message, { error: true, duration: 10000 }); }
    }
    function s3ExpEliminar(key, esCarpeta) {
        const mensaje = esCarpeta
            ? `Vas a eliminar la carpeta "${key}" y TODO su contenido de forma recursiva. Esta acción no se puede deshacer.`
            : `¿Eliminar el archivo "${key}"?`;
        confirmDialog(esCarpeta ? 'Eliminar carpeta' : 'Eliminar archivo', mensaje, async () => {
            try {
                await api('s3_delete.php', { method: 'POST', body: { key, recursivo: esCarpeta }});
                toast('Eliminado');
                s3ExpCargar(true);
            } catch (e) { toast(e.message, { error: true, duration: 10000 }); }
        });
    }

    /* ================================================================
       Herramientas: Programador de tareas
       ================================================================ */
    let tareasCache            = [];
    let tareasFiltroQ          = '';
    let tareasFiltroActivo     = '1';
    let _tareasBackdrop        = null;
    let _tareasFormBackdrop    = null;
    let _tareasEjecBackdrop    = null;
    let _tareasTermBackdrop    = null;
    let _tareasCronBackdrop    = null;
    let ejecucionesTareaSel    = null;
    let ejecucionesFiltroEstado= '';
    let ejecucionesCache       = [];
    let terminalES             = null;
    let terminalEjecucionActual= null;
    let terminalAutoscroll     = true;

    async function abrirTareas() {
        if (_tareasBackdrop && document.body.contains(_tareasBackdrop)) return;
        tareasFiltroQ = ''; tareasFiltroActivo = '1';

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'tareasBackdrop';
        bd.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="max-width:1080px;display:flex;flex-direction:column;max-height:90vh;overflow:hidden">
            <div class="modal-header" style="flex-shrink:0">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px">
                <span style="font-size:1.2rem">⏰</span>
                <span>Programador de tareas</span>
              </div>
              <button class="btn-icon-sm" data-act="close">×</button>
            </div>
            <div class="modal-body" style="gap:12px;flex:1;overflow:hidden;min-height:0;display:flex;flex-direction:column">
              <div class="toolbar" style="margin-bottom:0">
                <div class="toolbar-left" style="gap:8px;flex-wrap:wrap">
                  <div class="search-wrap"><input type="search" id="tareasSearch" class="search-input" placeholder="Buscar tareas…"><button class="search-clear" data-act="clearSearch">×</button></div>
                  <div id="tareasChips" style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="filter-chip" data-val="">Todas</button>
                    <button class="filter-chip active" data-val="1">Activas</button>
                    <button class="filter-chip" data-val="0">Inactivas</button>
                  </div>
                  <button class="btn btn-ghost btn-sm" data-act="refresh"><i class="fa-solid fa-rotate"></i></button>
                </div>
                <div class="toolbar-right">
                  <button class="btn btn-primary btn-sm" data-act="new"><i class="fa-solid fa-plus"></i> Nueva tarea</button>
                </div>
              </div>
              <div class="table-card" style="flex:1;overflow-y:auto;min-height:0">
                <table>
                  <thead style="position:sticky;top:0;background:var(--bg);z-index:1">
                    <tr><th style="width:80px">Código</th><th>Nombre</th><th style="width:140px">Cron</th><th style="width:120px">Estado</th><th style="width:170px">Última corrida</th><th style="width:80px">Activa</th><th style="width:60px;text-align:center">Acciones</th></tr>
                  </thead>
                  <tbody id="tareasTbody"><tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer" style="flex-shrink:0"><button class="btn btn-ghost" data-act="close">Cerrar</button></div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _tareasBackdrop = bd;

        bd.addEventListener('click', e => { if (e.target === bd) cerrarTareas(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarTareas));
        bd.querySelector('[data-act="refresh"]').addEventListener('click', cargarTareas);
        bd.querySelector('[data-act="new"]').addEventListener('click', () => abrirFormTarea(null));
        bd.querySelector('#tareasSearch').addEventListener('input', tareasOnSearch);
        bd.querySelector('[data-act="clearSearch"]').addEventListener('click', tareasLimpiarBusqueda);
        bd.querySelectorAll('#tareasChips .filter-chip').forEach(c => c.addEventListener('click', () => tareasSetActivo(c.dataset.val, c)));

        cargarTareas();
    }
    function cerrarTareas() {
        if (!_tareasBackdrop) return;
        _tareasBackdrop.classList.remove('open');
        setTimeout(() => { _tareasBackdrop?.remove(); _tareasBackdrop = null; }, 200);
    }
    let _tareasSearchTimer = null;
    function tareasOnSearch() {
        tareasFiltroQ = (_tareasBackdrop.querySelector('#tareasSearch').value || '').trim();
        clearTimeout(_tareasSearchTimer);
        _tareasSearchTimer = setTimeout(cargarTareas, 250);
    }
    function tareasLimpiarBusqueda() {
        _tareasBackdrop.querySelector('#tareasSearch').value = '';
        tareasFiltroQ = ''; cargarTareas();
    }
    function tareasSetActivo(v, el) {
        tareasFiltroActivo = v;
        _tareasBackdrop.querySelectorAll('#tareasChips .filter-chip').forEach(c => c.classList.toggle('active', c === el));
        cargarTareas();
    }
    async function cargarTareas() {
        const tbody = _tareasBackdrop?.querySelector('#tareasTbody');
        if (!tbody) return;
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>`;
        try {
            const qs = new URLSearchParams();
            if (tareasFiltroQ) qs.set('q', tareasFiltroQ);
            if (tareasFiltroActivo !== '') qs.set('activo', tareasFiltroActivo);
            const data = await api('tareas.php?' + qs.toString());
            tareasCache = data.tareas || [];
            renderTareas(tareasCache);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }
    function tareaBadgeEstado(estado) {
        const map = {
            ok:        { cls: 'badge-success', label: 'OK' },
            error:     { cls: 'badge-danger',  label: 'Error' },
            timeout:   { cls: 'badge-warn',    label: 'Timeout' },
            killed:    { cls: 'badge-danger',  label: 'Killed' },
            corriendo: { cls: 'badge-info',    label: 'Corriendo' },
        };
        const m = map[estado] || { cls: '', label: 'Sin corrida' };
        return `<span class="badge ${m.cls}">${m.label}</span>`;
    }
    function renderTareas(rows) {
        const tbody = _tareasBackdrop.querySelector('#tareasTbody');
        if (!rows.length) { tbody.innerHTML = `<tr><td colspan="7" class="table-empty">No hay tareas.</td></tr>`; return; }
        tbody.innerHTML = rows.map(t => `
            <tr class="row-clickable" data-id="${t.id}">
              <td class="td-id">#${t.id}</td>
              <td><div style="font-weight:600">${escape(t.nombre)}</div>${t.descripcion ? `<div style="font-size:.78rem;color:var(--muted)">${escape(t.descripcion)}</div>` : ''}</td>
              <td style="font-family:monospace;font-size:.82rem">${escape(t.cron_expr)}</td>
              <td>${tareaBadgeEstado(t.ultimo_estado)}</td>
              <td style="font-family:monospace;font-size:.82rem">${t.ultimo_run ? escape(t.ultimo_run) : '<span style="color:var(--muted)">—</span>'}</td>
              <td>
                <label class="toggle-switch" onclick="event.stopPropagation()">
                  <input type="checkbox" data-toggle="${t.id}" ${t.activo ? 'checked' : ''}>
                  <span class="toggle-track"><span class="toggle-thumb"></span></span>
                </label>
              </td>
              <td style="text-align:center"><button class="btn-icon-sm" data-menu="${t.id}"><i class="fa-solid fa-bars"></i></button></td>
            </tr>`).join('');
        tbody.querySelectorAll('tr[data-id]').forEach(tr => {
            const id = +tr.dataset.id;
            tr.addEventListener('click', e => { if (e.target.closest('button,label,input')) return; abrirEjecuciones(id); });
            tr.addEventListener('contextmenu', e => {
                if (e.target.closest('label,input')) return;
                e.preventDefault();
                const t = tareasCache.find(x => x.id === id); if (!t) return;
                openRowMenu(menuItemsTarea(t), { x: e.clientX, y: e.clientY });
            });
        });
        tbody.querySelectorAll('input[data-toggle]').forEach(chk => {
            chk.addEventListener('change', () => toggleActivoTarea(+chk.dataset.toggle, chk.checked));
        });
        tbody.querySelectorAll('button[data-menu]').forEach(b => b.addEventListener('click', e => {
            e.stopPropagation();
            const id = +b.dataset.menu;
            const t  = tareasCache.find(x => x.id === id); if (!t) return;
            openRowMenu(menuItemsTarea(t), e.currentTarget);
        }));
    }
    function menuItemsTarea(t) {
        return [
            { act: 'ver',    label: 'Ver ejecuciones',                    icon: 'fa-list',      onSelect: () => abrirEjecuciones(t.id) },
            { act: 'run',    label: 'Ejecutar ahora',                     icon: 'fa-play',      onSelect: () => ejecutarAhora(t.id) },
            { act: 'tog',    label: t.activo ? 'Desactivar' : 'Activar',  icon: 'fa-power-off', onSelect: () => toggleActivoTarea(t.id, !t.activo) },
            { divider: true },
            { act: 'edit',   label: 'Editar',                             icon: 'fa-pen',       onSelect: () => abrirFormTarea(t) },
            { act: 'del',    label: 'Eliminar',                           icon: 'fa-trash', danger: true, onSelect: () => eliminarTarea(t.id) },
        ];
    }
    async function toggleActivoTarea(id, activo) {
        const t = tareasCache.find(x => x.id === id); if (!t) return;
        try {
            await api('tareas.php', { method: 'PUT', body: { ...t, activo: activo ? 1 : 0 } });
            toast(activo ? 'Tarea activada' : 'Tarea desactivada');
            cargarTareas();
        } catch (e) { toast(e.message, { error: true, duration: 10000 }); cargarTareas(); }
    }
    async function ejecutarAhora(id) {
        try {
            const data = await api('tareas_ejecutar.php', { method: 'POST', body: { tarea_id: id } });
            cargarTareas();
            abrirTerminal(data.ejecucion_id);
        } catch (e) { toast(e.message, { error: true, duration: 10000 }); }
    }
    function eliminarTarea(id) {
        const t = tareasCache.find(x => x.id === id); if (!t) return;
        confirmDialog('Eliminar tarea', `¿Eliminar "${t.nombre}" y todo su historial? Esta acción no se puede deshacer.`, async () => {
            try {
                const data = await api('tareas.php?id=' + id, { method: 'DELETE' });
                toast(`Tarea eliminada (${data.archivos_borrados || 0} archivos borrados)`);
                cargarTareas();
            } catch (e) { toast(e.message, { error: true, duration: 10000 }); }
        });
    }

    /* --- Form de tarea (alta/edición) --- */
    async function abrirFormTarea(param) {
        const isEdit = !!param;
        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'formTareaBackdrop';
        bd.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="max-width:640px">
            <div class="modal-header">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px"><span style="font-size:1.2rem">⏰</span><span>${isEdit ? 'Editar tarea' : 'Nueva tarea'}</span></div>
              <button class="btn-icon-sm" data-act="close">×</button>
            </div>
            <div class="modal-body">
              <div class="form-group"><label>Nombre</label><input type="text" id="formTareaNombre" maxlength="120" value="${escape(param?.nombre ?? '')}"><div class="field-error" id="formTareaNombreErr" style="display:none"></div></div>
              <div class="form-group"><label>Descripción <span style="color:var(--muted);font-weight:400">— opcional</span></label><input type="text" id="formTareaDesc" maxlength="255" value="${escape(param?.descripcion ?? '')}"></div>
              <div class="form-group">
                <label>Script</label>
                <div style="display:flex;gap:6px">
                  <select id="formTareaScript" style="flex:1"><option value="">Cargando…</option></select>
                  <button class="btn btn-ghost btn-sm" data-act="refreshScripts" title="Refrescar scripts"><i class="fa-solid fa-rotate"></i></button>
                </div>
                <div class="field-error" id="formTareaScriptErr" style="display:none"></div>
              </div>
              <div class="form-row">
                <div class="form-group">
                  <label>Expresión cron</label>
                  <div style="display:flex;gap:6px">
                    <input type="text" id="formTareaCron" style="font-family:monospace;flex:1" value="${escape(param?.cron_expr ?? '* * * * *')}">
                    <button class="btn btn-ghost btn-sm" data-act="cronBuilder" title="Abrir constructor"><i class="fa-solid fa-sliders"></i></button>
                  </div>
                  <div class="field-error" id="formTareaCronErr" style="display:none"></div>
                </div>
                <div class="form-group"><label>Timeout (segundos)</label><input type="number" id="formTareaTimeout" min="5" max="86400" value="${param?.timeout_seg ?? 300}"></div>
              </div>
              <div class="form-row form-row-3">
                <div class="form-group"><label>Si ya está corriendo</label>
                  <select id="formTareaOverlap">
                    <option value="skip" ${!param || param.overlap === 'skip' ? 'selected' : ''}>Saltar</option>
                    <option value="allow" ${param?.overlap === 'allow' ? 'selected' : ''}>Ejecutar</option>
                  </select>
                </div>
                <div class="form-group"><label>Retención (días)</label><input type="number" id="formTareaRet" min="1" max="3650" value="${param?.retencion_dias ?? 7}"></div>
                <div class="form-group"><label>Estado</label>
                  <select id="formTareaActivo">
                    <option value="1" ${!param || param.activo ? 'selected' : ''}>Activa</option>
                    <option value="0" ${param && !param.activo ? 'selected' : ''}>Inactiva</option>
                  </select>
                </div>
              </div>
            </div>
            <div class="modal-footer">
              <button class="btn btn-ghost" data-act="close">Cancelar</button>
              <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear tarea'}</button>
            </div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _tareasFormBackdrop = bd;

        const cerrar = () => { bd.classList.remove('open'); setTimeout(() => { bd.remove(); _tareasFormBackdrop = null; }, 200); };
        bd.addEventListener('click', e => { if (e.target === bd) cerrar(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrar));
        bd.querySelector('[data-act="refreshScripts"]').addEventListener('click', () => cargarScriptsDisponibles(param?.script ?? ''));
        bd.querySelector('[data-act="cronBuilder"]').addEventListener('click', abrirCronBuilder);
        bd.querySelector('[data-act="save"]').addEventListener('click', () => guardarTarea(param, cerrar));

        await cargarScriptsDisponibles(param?.script ?? '');
    }
    async function cargarScriptsDisponibles(actual) {
        try {
            const scripts = await api('tareas_scripts_disponibles.php');
            const sel = _tareasFormBackdrop.querySelector('#formTareaScript');
            let opts = `<option value="">— elegí un script —</option>` + scripts.map(s => `<option value="${escape(s)}" ${s === actual ? 'selected' : ''}>${escape(s)}</option>`).join('');
            if (actual && !scripts.includes(actual)) {
                opts += `<option value="${escape(actual)}" selected>⚠️ ${escape(actual)} (no está en cloud/jobs/)</option>`;
            }
            sel.innerHTML = opts;
        } catch (e) {
            _tareasFormBackdrop.querySelector('#formTareaScript').innerHTML = `<option value="">Error cargando scripts</option>`;
        }
    }
    async function guardarTarea(param, cerrar) {
        const bd = _tareasFormBackdrop;
        const nombre    = bd.querySelector('#formTareaNombre').value.trim();
        const descripcion = bd.querySelector('#formTareaDesc').value.trim();
        const script    = bd.querySelector('#formTareaScript').value;
        const cron_expr = bd.querySelector('#formTareaCron').value.trim();
        const timeout_seg    = +bd.querySelector('#formTareaTimeout').value || 300;
        const retencion_dias = +bd.querySelector('#formTareaRet').value || 7;
        const overlap   = bd.querySelector('#formTareaOverlap').value;
        const activo    = +bd.querySelector('#formTareaActivo').value;

        ['Nombre', 'Script', 'Cron'].forEach(k => { const e = bd.querySelector('#formTarea' + k + 'Err'); if (e) e.style.display = 'none'; });
        const err = (k, m) => { const el = bd.querySelector('#formTarea' + k + 'Err'); if (el) { el.textContent = m; el.style.display = 'block'; } };
        if (!nombre) { err('Nombre', 'El nombre es obligatorio.'); return; }
        if (!script) { err('Script', 'Elegí un script del desplegable.'); return; }
        if (!cron_expr) { err('Cron', 'La expresión cron es obligatoria.'); return; }
        if (cron_expr.split(/\s+/).length !== 5) { err('Cron', 'Deben ser 5 campos.'); return; }

        const payload = { nombre, descripcion, script, cron_expr, timeout_seg, retencion_dias, overlap, activo };
        try {
            if (param) {
                await api('tareas.php', { method: 'PUT', body: { id: param.id, ...payload }});
                toast('Tarea actualizada');
            } else {
                await api('tareas.php', { method: 'POST', body: payload });
                toast('Tarea creada');
            }
            cerrar();
            cargarTareas();
        } catch (e) {
            if ((e.message || '').includes('nombre_duplicado')) err('Nombre', 'Ya existe una tarea con ese nombre.');
            else toast(e.message, { error: true, duration: 10000 });
        }
    }

    /* --- Ejecuciones (historial) --- */
    async function abrirEjecuciones(tareaId) {
        const t = tareasCache.find(x => x.id === tareaId);
        ejecucionesTareaSel     = { id: tareaId, nombre: t ? t.nombre : ('#' + tareaId) };
        ejecucionesFiltroEstado = '';

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'ejecucionesBackdrop';
        bd.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="max-width:1000px;display:flex;flex-direction:column;max-height:90vh;overflow:hidden">
            <div class="modal-header" style="flex-shrink:0">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px"><span style="font-size:1.2rem">📜</span><span>Ejecuciones de ${escape(ejecucionesTareaSel.nombre)}</span></div>
              <button class="btn-icon-sm" data-act="close">×</button>
            </div>
            <div class="modal-body" style="gap:12px;flex:1;overflow:hidden;min-height:0;display:flex;flex-direction:column">
              <div class="toolbar" style="margin-bottom:0">
                <div class="toolbar-left" style="gap:6px;flex-wrap:wrap">
                  <div id="ejChips" style="display:flex;gap:6px;flex-wrap:wrap">
                    <button class="filter-chip active" data-val="">Todas</button>
                    <button class="filter-chip" data-val="corriendo">Corriendo</button>
                    <button class="filter-chip" data-val="ok">OK</button>
                    <button class="filter-chip" data-val="error">Error</button>
                    <button class="filter-chip" data-val="timeout">Timeout</button>
                    <button class="filter-chip" data-val="killed">Killed</button>
                  </div>
                  <button class="btn btn-ghost btn-sm" data-act="refresh"><i class="fa-solid fa-rotate"></i></button>
                </div>
              </div>
              <div class="table-card" style="flex:1;overflow-y:auto;min-height:0">
                <table>
                  <thead style="position:sticky;top:0;background:var(--bg);z-index:1">
                    <tr><th style="width:80px">Código</th><th style="width:170px">Inicio</th><th style="width:120px">Duración</th><th style="width:120px">Estado</th><th style="width:120px">Disparo</th><th>Mensaje</th><th style="width:60px;text-align:center">Acciones</th></tr>
                  </thead>
                  <tbody id="ejecucionesTbody"><tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr></tbody>
                </table>
              </div>
            </div>
            <div class="modal-footer" style="flex-shrink:0"><button class="btn btn-ghost" data-act="close">Cerrar</button></div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _tareasEjecBackdrop = bd;

        bd.addEventListener('click', e => { if (e.target === bd) cerrarEjecuciones(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarEjecuciones));
        bd.querySelector('[data-act="refresh"]').addEventListener('click', cargarEjecuciones);
        bd.querySelectorAll('#ejChips .filter-chip').forEach(c => c.addEventListener('click', () => {
            ejecucionesFiltroEstado = c.dataset.val;
            bd.querySelectorAll('#ejChips .filter-chip').forEach(x => x.classList.toggle('active', x === c));
            cargarEjecuciones();
        }));

        cargarEjecuciones();
    }
    function cerrarEjecuciones() {
        if (!_tareasEjecBackdrop) return;
        _tareasEjecBackdrop.classList.remove('open');
        setTimeout(() => { _tareasEjecBackdrop?.remove(); _tareasEjecBackdrop = null; }, 200);
        cargarTareas();
    }
    async function cargarEjecuciones() {
        if (!ejecucionesTareaSel) return;
        const tbody = _tareasEjecBackdrop.querySelector('#ejecucionesTbody');
        tbody.innerHTML = `<tr><td colspan="7" style="text-align:center;padding:24px"><div class="spin"></div></td></tr>`;
        try {
            const qs = new URLSearchParams({ tarea_id: String(ejecucionesTareaSel.id), limite: '100' });
            if (ejecucionesFiltroEstado) qs.set('estado', ejecucionesFiltroEstado);
            const data = await api('tareas_ejecuciones.php?' + qs.toString());
            ejecucionesCache = data.ejecuciones || [];
            renderEjecuciones(ejecucionesCache);
        } catch (e) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty" style="color:var(--danger)">✗ ${escape(e.message)}</td></tr>`;
        }
    }
    function renderEjecuciones(rows) {
        const tbody = _tareasEjecBackdrop.querySelector('#ejecucionesTbody');
        if (!rows.length) { tbody.innerHTML = `<tr><td colspan="7" class="table-empty">Sin ejecuciones.</td></tr>`; return; }
        tbody.innerHTML = rows.map(e => `
            <tr class="row-clickable" data-id="${e.id}">
              <td class="td-id">#${e.id}</td>
              <td style="font-family:monospace;font-size:.82rem">${escape(e.inicio || '')}</td>
              <td style="font-family:monospace;font-size:.82rem">${formatoDuracion(e.inicio, e.fin)}</td>
              <td>${tareaBadgeEstado(e.estado)}</td>
              <td style="font-size:.82rem;color:var(--muted)">${escape(e.disparo || '')}</td>
              <td style="font-size:.82rem;color:var(--muted);max-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="${escape(e.mensaje || '')}">${escape(e.mensaje || '')}</td>
              <td style="text-align:center"><button class="btn-icon-sm" data-menu="${e.id}"><i class="fa-solid fa-bars"></i></button></td>
            </tr>`).join('');
        tbody.querySelectorAll('tr[data-id]').forEach(tr => {
            const id = +tr.dataset.id;
            tr.addEventListener('click', e => { if (e.target.closest('button')) return; abrirTerminal(id); });
            tr.addEventListener('contextmenu', e => {
                e.preventDefault();
                const ej = ejecucionesCache.find(x => x.id === id); if (!ej) return;
                openRowMenu(menuItemsEjecucion(ej), { x: e.clientX, y: e.clientY });
            });
        });
        tbody.querySelectorAll('button[data-menu]').forEach(b => b.addEventListener('click', ev => {
            ev.stopPropagation();
            const id = +b.dataset.menu;
            const ej = ejecucionesCache.find(x => x.id === id); if (!ej) return;
            openRowMenu(menuItemsEjecucion(ej), ev.currentTarget);
        }));
    }
    function menuItemsEjecucion(ej) {
        const items = [
            { act: 'log', label: 'Ver log', icon: 'fa-terminal', onSelect: () => abrirTerminal(ej.id) },
        ];
        if (ej.estado === 'corriendo') {
            items.push({ act: 'stop', label: 'Detener', icon: 'fa-stop', danger: true, onSelect: () => detenerEjecucion(ej.id) });
        }
        return items;
    }
    function formatoDuracion(inicio, fin) {
        if (!inicio) return '—';
        const i = new Date(String(inicio).replace(' ', 'T'));
        const f = fin ? new Date(String(fin).replace(' ', 'T')) : new Date();
        if (isNaN(i)) return '—';
        const s = Math.max(0, Math.floor((f - i) / 1000));
        if (s < 60) return s + 's';
        if (s < 3600) return Math.floor(s / 60) + 'm ' + (s % 60) + 's';
        return Math.floor(s / 3600) + 'h ' + Math.floor((s % 3600) / 60) + 'm';
    }
    async function detenerEjecucion(id) {
        try {
            await api('tareas_ejecuciones.php', { method: 'POST', body: { id, accion: 'detener' } });
            toast('Ejecución detenida');
            cargarEjecuciones();
        } catch (e) { toast(e.message, { error: true, duration: 10000 }); }
    }

    /* --- Modal Terminal (SSE) --- */
    function abrirTerminal(ejecucionId) {
        terminalEjecucionActual = ejecucionId;
        terminalAutoscroll = true;

        if (terminalES) { try { terminalES.close(); } catch(_){} terminalES = null; }

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'terminalBackdrop';
        bd.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="max-width:960px">
            <div class="modal-header">
              <div class="modal-title" style="display:flex;align-items:center;gap:8px"><span style="font-size:1.2rem">🖥️</span><span>Log ejecución #${ejecucionId}</span><span class="badge badge-info" id="terminalBadge">corriendo</span></div>
              <button class="btn-icon-sm" data-act="close">×</button>
            </div>
            <div class="modal-body"><pre id="terminalOutput" class="terminal-live"></pre></div>
            <div class="modal-footer" style="justify-content:space-between">
              <button class="btn btn-ghost btn-sm" id="btnTerminalAutoscroll" class="active" title="Auto-scroll"><i class="fa-solid fa-angles-down"></i></button>
              <div style="display:flex;gap:6px">
                <button class="btn btn-danger btn-sm" id="btnTerminalDetener"><i class="fa-solid fa-stop"></i> Detener</button>
                <button class="btn btn-ghost" data-act="close">Cerrar</button>
              </div>
            </div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _tareasTermBackdrop = bd;

        const btnAuto = bd.querySelector('#btnTerminalAutoscroll'); btnAuto.classList.add('active');
        btnAuto.addEventListener('click', () => { terminalAutoscroll = !terminalAutoscroll; btnAuto.classList.toggle('active', terminalAutoscroll); toast(terminalAutoscroll ? 'Auto-scroll ON' : 'Auto-scroll OFF'); });
        bd.querySelector('#btnTerminalDetener').addEventListener('click', () => detenerEjecucion(ejecucionId));
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrarTerminal));
        bd.addEventListener('click', e => { if (e.target === bd) cerrarTerminal(); });

        const out = bd.querySelector('#terminalOutput');
        terminalES = new EventSource('api/tareas_ejecucion_stream.php?id=' + ejecucionId);
        terminalES.onmessage = ev => {
            out.textContent += ev.data + '\n';
            if (terminalAutoscroll) out.scrollTop = out.scrollHeight;
        };
        terminalES.addEventListener('end', ev => {
            const estado = ev.data || 'finalizado';
            const badge  = bd.querySelector('#terminalBadge');
            const map    = { ok: 'badge-success', error: 'badge-danger', killed: 'badge-danger', timeout: 'badge-warn' };
            badge.className = 'badge ' + (map[estado] || 'badge-info');
            badge.textContent = estado;
            bd.querySelector('#btnTerminalDetener').style.display = 'none';
            out.textContent += `\n── ejecución terminada (${estado}) ──\n`;
            try { terminalES.close(); } catch(_){}
            terminalES = null;
            if (_tareasEjecBackdrop) cargarEjecuciones();
            if (_tareasBackdrop) cargarTareas();
        });
        terminalES.onerror = () => { try { terminalES.close(); } catch(_){} terminalES = null; };
    }
    function cerrarTerminal() {
        if (terminalES) { try { terminalES.close(); } catch(_){} terminalES = null; }
        if (!_tareasTermBackdrop) return;
        _tareasTermBackdrop.classList.remove('open');
        setTimeout(() => { _tareasTermBackdrop?.remove(); _tareasTermBackdrop = null; }, 200);
        if (_tareasEjecBackdrop) cargarEjecuciones();
        if (_tareasBackdrop) cargarTareas();
    }

    /* --- Constructor de cron (mini) --- */
    const CRON_CAMPOS = ['min', 'hor', 'dom', 'mes', 'dow'];
    function abrirCronBuilder() {
        const cur = (_tareasFormBackdrop?.querySelector('#formTareaCron')?.value || '* * * * *').trim();
        const partes = cur.split(/\s+/); while (partes.length < 5) partes.push('*');

        const bd = document.createElement('div');
        bd.className = 'modal-backdrop';
        bd.id = 'cronBuilderBackdrop';
        bd.style.zIndex = '160';
        const campoRow = (id, label, val) => `
            <div class="form-row form-row-3" style="align-items:end;gap:8px">
              <div class="form-group"><label>${label}</label>
                <select data-cron-modo="${id}">
                  <option value="star">Cualquiera</option>
                  <option value="exact">Exacto</option>
                  <option value="step">Cada</option>
                  <option value="range">Rango</option>
                  <option value="list">Lista</option>
                </select>
              </div>
              <div class="form-group" style="grid-column:span 2"><label>Valor</label>
                <input type="text" data-cron-valor="${id}" value="${escape(val)}" style="font-family:monospace">
              </div>
            </div>`;
        bd.innerHTML = `
          <div class="modal" role="dialog" aria-modal="true" style="max-width:640px">
            <div class="modal-header"><div class="modal-title" style="display:flex;align-items:center;gap:8px"><span>🧮</span><span>Constructor de cron</span></div><button class="btn-icon-sm" data-act="close">×</button></div>
            <div class="modal-body">
              ${campoRow('min', 'Minuto (0-59)',  partes[0])}
              ${campoRow('hor', 'Hora (0-23)',    partes[1])}
              ${campoRow('dom', 'Día del mes (1-31)', partes[2])}
              ${campoRow('mes', 'Mes (1-12)',     partes[3])}
              ${campoRow('dow', 'Día de la semana (0=Dom..6=Sáb)', partes[4])}
              <div style="background:var(--bg);padding:12px;border-radius:8px;border:1px solid var(--border)">
                <div style="font-family:monospace;font-weight:700;font-size:1rem" id="cronBuilderPreview">${escape(cur)}</div>
                <div style="font-size:.82rem;color:var(--muted);margin-top:4px" id="cronBuilderDesc">—</div>
              </div>
            </div>
            <div class="modal-footer"><button class="btn btn-ghost" data-act="close">Cancelar</button><button class="btn btn-primary" data-act="apply">Aplicar</button></div>
          </div>`;
        document.body.appendChild(bd);
        requestAnimationFrame(() => bd.classList.add('open'));
        _tareasCronBackdrop = bd;

        CRON_CAMPOS.forEach((k, i) => { cronBuilderPoblarCampo(k, partes[i]); });
        cronBuilderOnChange();

        const cerrar = () => { bd.classList.remove('open'); setTimeout(() => { bd.remove(); _tareasCronBackdrop = null; }, 200); };
        bd.addEventListener('click', e => { if (e.target === bd) cerrar(); });
        bd.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', cerrar));
        bd.querySelector('[data-act="apply"]').addEventListener('click', () => {
            const expr = cronBuilderConstruir();
            if (_tareasFormBackdrop) {
                const inp = _tareasFormBackdrop.querySelector('#formTareaCron');
                inp.value = expr;
                _tareasFormBackdrop.querySelector('#formTareaCronErr').style.display = 'none';
            }
            cerrar();
        });
        bd.querySelectorAll('[data-cron-modo]').forEach(sel => sel.addEventListener('change', () => cronBuilderModoChange(sel.dataset.cronModo)));
        bd.querySelectorAll('[data-cron-valor]').forEach(inp => inp.addEventListener('input', cronBuilderOnChange));
    }
    function cronBuilderPoblarCampo(campo, valor) {
        const bd = _tareasCronBackdrop; if (!bd) return;
        const sel = bd.querySelector(`[data-cron-modo="${campo}"]`);
        const inp = bd.querySelector(`[data-cron-valor="${campo}"]`);
        let modo = 'star', v = '';
        if (valor === '*') modo = 'star';
        else if (/^\*\/\d+$/.test(valor))    { modo = 'step';  v = valor.slice(2); }
        else if (/^\d+-\d+$/.test(valor))    { modo = 'range'; v = valor; }
        else if (valor.includes(','))         { modo = 'list';  v = valor; }
        else                                   { modo = 'exact'; v = valor; }
        sel.value = modo;
        inp.value = v;
        inp.disabled = modo === 'star';
    }
    function cronBuilderModoChange(campo) {
        const bd = _tareasCronBackdrop; if (!bd) return;
        const sel = bd.querySelector(`[data-cron-modo="${campo}"]`);
        const inp = bd.querySelector(`[data-cron-valor="${campo}"]`);
        inp.disabled = sel.value === 'star';
        if (sel.value === 'star') inp.value = '';
        cronBuilderOnChange();
    }
    function cronBuilderConstruirCampo(campo) {
        const bd = _tareasCronBackdrop; if (!bd) return '*';
        const modo = bd.querySelector(`[data-cron-modo="${campo}"]`).value;
        const v    = (bd.querySelector(`[data-cron-valor="${campo}"]`).value || '').trim();
        if (modo === 'star' || v === '') return '*';
        if (modo === 'exact') return v;
        if (modo === 'step')  return '*/' + v;
        return v; // range o list ya vienen tal cual
    }
    function cronBuilderConstruir() {
        return CRON_CAMPOS.map(cronBuilderConstruirCampo).join(' ');
    }
    function cronBuilderOnChange() {
        const bd = _tareasCronBackdrop; if (!bd) return;
        const expr = cronBuilderConstruir();
        bd.querySelector('#cronBuilderPreview').textContent = expr;
        bd.querySelector('#cronBuilderDesc').textContent = cronDescribir(expr);
    }
    function cronDescribir(expr) {
        const [m, h, dom, mon, dow] = expr.split(/\s+/);
        const partes = [];
        if (m === '*' && h === '*') partes.push('Cada minuto');
        else if (m.startsWith('*/') && h === '*') partes.push(`Cada ${m.slice(2)} minutos`);
        else if (h.startsWith('*/') && /^\d+$/.test(m)) partes.push(`Al minuto ${m} de cada ${h.slice(2)} horas`);
        else if (m === '0' && h === '*') partes.push('Al minuto 0 de cada hora');
        else if (/^\d+$/.test(m) && /^\d+$/.test(h)) partes.push(`A las ${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}`);
        else partes.push(`Según patrón ${m} ${h}`);
        if (dom !== '*') partes.push('el día ' + dom + ' del mes');
        const meses = ['', 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
        if (mon !== '*' && /^\d+$/.test(mon)) partes.push('en ' + (meses[+mon] || mon));
        else if (mon !== '*') partes.push('en meses ' + mon);
        const dias = ['domingos', 'lunes', 'martes', 'miércoles', 'jueves', 'viernes', 'sábados'];
        if (dow !== '*') {
            if (/^\d+$/.test(dow)) partes.push('los ' + (dias[+dow] || dow));
            else if (/^\d+-\d+$/.test(dow)) {
                const [a, b] = dow.split('-').map(Number);
                partes.push(`de ${dias[a] || a} a ${dias[b] || b}`);
            } else partes.push('los días ' + dow);
        } else if (dom === '*' && mon === '*') partes.push('todos los días');
        return partes.join(', ').replace(/^./, s => s.toUpperCase()) + '.';
    }

    /* --- ESC cascada para el módulo de tareas --- */
    document.addEventListener('keydown', e => {
        if (e.key !== 'Escape') return;
        if (_tareasCronBackdrop?.classList.contains('open')) { _tareasCronBackdrop.classList.remove('open'); setTimeout(() => { _tareasCronBackdrop?.remove(); _tareasCronBackdrop = null; }, 200); return; }
        if (_tareasTermBackdrop?.classList.contains('open')) { cerrarTerminal(); return; }
        if (_tareasFormBackdrop?.classList.contains('open')) { _tareasFormBackdrop.classList.remove('open'); const b = _tareasFormBackdrop; setTimeout(() => { b?.remove(); _tareasFormBackdrop = null; }, 200); return; }
        if (_tareasEjecBackdrop?.classList.contains('open')) { cerrarEjecuciones(); return; }
        if (_tareasBackdrop?.classList.contains('open'))     { cerrarTareas(); return; }
        if (_s3ExpBackdrop?.classList.contains('open'))      { cerrarExploradorS3(); return; }
        if (_dbExpBackdrop?.classList.contains('open'))      { cerrarExploradorDB(); return; }
    });

    /* ---------- Views: Stub ---------- */
    function renderStub(root) {
        root.innerHTML = `
            <div class="table-card">
                <div class="table-empty">
                    <div style="font-size:2rem;margin-bottom:8px">🚧</div>
                    <div>Módulo en construcción.</div>
                </div>
            </div>
        `;
    }

    /* ---------- Feedback ---------- */
    function errorBox(msg) {
        return `
            <div class="table-card">
                <div class="table-empty" style="color:var(--danger)">
                    <div style="font-size:2rem;margin-bottom:8px">⚠️</div>
                    <div>Error: ${escape(msg)}</div>
                </div>
            </div>
        `;
    }

    let toastTimer = null;
    // Compat: acepta tanto la forma vieja toast(msg, 'error') como la forma
    // objeto toast(msg, { error: true, duration: 10000 }) que usan las
    // herramientas del panel (Migrador DB, Visor de sucesos) para mostrar
    // mensajes de error largos que el operador necesita tiempo de leer.
    function toast(msg, opts) {
        let isError = false;
        let duration = 1800;
        if (typeof opts === 'string') {
            isError = opts === 'error';
        } else if (opts && typeof opts === 'object') {
            isError = !!opts.error;
            if (typeof opts.duration === 'number') duration = opts.duration;
        }
        toastEl.textContent = msg;
        toastEl.classList.toggle('error', isError);
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), duration);
    }

    /* ---------- Utils ---------- */
    function formatDate(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return escape(s);
        return d.toLocaleString('es-AR');
    }

    // Variante compacta para el feed en vivo del dashboard: sólo HH:MM:SS.
    function formatTime(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return String(s);
        return d.toLocaleTimeString('es-AR', { hour12: false });
    }

    // Sólo la fecha (dd/MM/aaaa) — usado en el feed en vivo arriba de la hora.
    function formatDateOnly(s) {
        if (!s) return '—';
        const d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return String(s);
        return d.toLocaleDateString('es-AR');
    }

    function escape(s) {
        return String(s ?? '').replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }

    function copyToClipboard(text) {
        const value = String(text ?? '');
        const done  = ok => toast(ok ? 'Copiado al portapapeles' : 'No se pudo copiar', ok ? undefined : 'error');

        if (navigator.clipboard?.writeText) {
            navigator.clipboard.writeText(value).then(() => done(true), () => done(false));
            return;
        }
        const ta = document.createElement('textarea');
        ta.value = value;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity  = '0';
        document.body.appendChild(ta);
        ta.select();
        try { done(document.execCommand('copy')); } catch (_) { done(false); }
        ta.remove();
    }

    /* ---------- Polling de versión --------------------------------
     * Cada 60s consulta api/version.php y compara con la versión que
     * el body traía al cargar la página. Si difiere, muestra el
     * banner azul de "Hay una nueva versión disponible" y agrega
     * body.has-banner para que .layout compense los 44px del banner.
     * Una vez detectada la nueva versión, deja de pollear. */
    (function startVersionPolling() {
        const banner = document.getElementById('version-banner');
        const btn    = document.getElementById('version-banner-btn');
        if (!banner || !btn) return;

        const baseline = (document.body && document.body.dataset.version) || '';
        if (!baseline) return;

        btn.addEventListener('click', () => window.location.reload());

        let done = false;
        const show = () => {
            banner.removeAttribute('hidden');
            document.body.classList.add('has-banner');
            done = true;
        };

        setInterval(async () => {
            if (done) return;
            try {
                const res = await fetch('api/version.php', {
                    headers: { 'Accept': 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                });
                if (!res.ok) return;
                const body = await res.json();
                const v = body && body.data && body.data.version;
                if (v && v !== baseline) show();
            } catch (_) { /* noop */ }
        }, 60_000);
    })();
})();
