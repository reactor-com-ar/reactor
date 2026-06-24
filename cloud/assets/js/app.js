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
    const toolsCatalog = [
        { icon: '⚙️', title: 'Parámetros',  desc: 'Administra las variables de configuración del sistema.', action: openParametrosManager },
        { icon: '🛠️', title: 'Migraciones', desc: 'Ejecuta migraciones de base de datos con log en vivo. Idempotente.', action: openMigracionesConsole },
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

    /* ---------- Herramientas: Parámetros ---------- */
    // Tabla `parametros` (db/schema.sql): id, variable, valor, comentario.
    // A diferencia de los ABM regulares, vive 100% dentro de un modal lanzado
    // desde el tile-grid de Herramientas. La lista se mantiene en memoria
    // dentro del modal y se re-fetchea tras cada alta/edición/borrado.

    async function openParametrosManager() {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal modal-wide" role="dialog" aria-modal="true" aria-labelledby="params-title">
                <div class="modal-header">
                    <div class="modal-title" id="params-title">
                        Parámetros
                        <span class="modal-subtitle">Variables de configuración del sistema</span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="toolbar" style="margin-bottom:14px">
                        <div class="toolbar-left">
                            <div class="search-wrap">
                                <input type="search" id="params-quick" class="search-input"
                                       placeholder="Buscar variable, valor o comentario…">
                                <button type="button" class="search-clear"
                                        data-act="quick-clear" title="Limpiar búsqueda" aria-label="Limpiar búsqueda">×</button>
                            </div>
                        </div>
                        <div class="toolbar-right">
                            <button type="button" class="btn btn-primary btn-sm" data-act="new">
                                <i class="fa-solid fa-plus"></i> Nuevo parámetro
                            </button>
                        </div>
                    </div>
                    <div class="table-card" id="params-table">
                        <div class="table-empty"><div class="spin"></div></div>
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

        const tableWrap = backdrop.querySelector('#params-table');
        const quick     = backdrop.querySelector('#params-quick');
        const quickClr  = backdrop.querySelector('[data-act="quick-clear"]');
        const btnNew    = backdrop.querySelector('[data-act="new"]');

        const state = { texto: '', items: [] };

        function applyAndRender() {
            const q = state.texto.toLowerCase();
            const filtered = q
                ? state.items.filter(p =>
                    ((p.variable   ?? '') + ' ' +
                     (p.valor      ?? '') + ' ' +
                     (p.comentario ?? '')).toLowerCase().includes(q))
                : state.items;
            tableWrap.innerHTML = parametrosTableBody(filtered);
            wireRowActions();
        }

        function rowMenuFor(p) {
            return standardRowMenuItems({
                edit:   true, onEdit:   () => openParametroForm(p, reload),
                delete: true, onDelete: () => confirmDeleteParametro(p, reload),
            });
        }
        function wireRowActions() {
            tableWrap.querySelectorAll('tbody tr').forEach(tr => {
                const id = +tr.dataset.id;
                const p  = state.items.find(x => x.id === id);
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

        async function reload() {
            tableWrap.innerHTML = `<div class="table-empty"><div class="spin"></div></div>`;
            try {
                const data = await api('parametros.php');
                state.items = data.parametros || [];
                applyAndRender();
            } catch (e) {
                tableWrap.innerHTML = `<div class="table-empty" style="color:var(--danger)">Error: ${escape(e.message)}</div>`;
            }
        }

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
        btnNew.addEventListener('click', () => openParametroForm(null, reload));

        reload();
    }

    function parametrosTableBody(items) {
        if (!items.length) {
            return `<div class="table-empty">No hay parámetros para mostrar.</div>`;
        }
        const rows = items.map(p => `
            <tr data-id="${p.id}">
                <td><span class="td-id">#${p.id}</span></td>
                <td class="td-nombre">${escape(p.variable ?? '')}</td>
                <td>${p.valor != null && p.valor !== '' ? escape(p.valor) : '<span style="color:var(--muted)">—</span>'}</td>
                <td>${p.comentario != null && p.comentario !== '' ? escape(p.comentario) : '<span style="color:var(--muted)">—</span>'}</td>
                ${actionCells()}
            </tr>
        `).join('');
        return `
            <table>
                <thead>
                    <tr>
                        <th>Código</th>
                        <th>Variable</th>
                        <th>Valor</th>
                        <th>Comentario</th>
                        ${actionHeaderCells()}
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function openParametroForm(param, onSaved) {
        const isEdit = !!param;

        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${isEdit ? 'Editar parámetro' : 'Nuevo parámetro'}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <div class="form-group">
                        <label for="param-variable">Variable</label>
                        <input type="text" id="param-variable" maxlength="255"
                               value="${escape(param?.variable ?? '')}"
                               placeholder="ej.: smtp_host" required>
                        <div class="field-error" id="param-variable-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="param-valor">Valor</label>
                        <input type="text" id="param-valor" maxlength="255"
                               value="${escape(param?.valor ?? '')}"
                               placeholder="Opcional">
                        <div class="field-error" id="param-valor-err" style="display:none"></div>
                    </div>
                    <div class="form-group">
                        <label for="param-comentario">Comentario</label>
                        <textarea id="param-comentario" maxlength="1024" placeholder="Opcional">${escape(param?.comentario ?? '')}</textarea>
                        <div class="field-error" id="param-comentario-err" style="display:none"></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button class="btn btn-ghost"   data-act="close">Cancelar</button>
                    <button class="btn btn-primary" data-act="save">${isEdit ? 'Guardar cambios' : 'Crear parámetro'}</button>
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

        const varInput = backdrop.querySelector('#param-variable');
        const valInput = backdrop.querySelector('#param-valor');
        const comInput = backdrop.querySelector('#param-comentario');
        const varErr   = backdrop.querySelector('#param-variable-err');
        const saveBtn  = backdrop.querySelector('[data-act="save"]');

        varInput.focus();
        varInput.select();

        saveBtn.addEventListener('click', async () => {
            const variable   = varInput.value.trim();
            const valor      = valInput.value.trim();
            const comentario = comInput.value.trim();

            varErr.style.display = 'none';
            varInput.classList.remove('input-invalid');

            if (!variable) {
                varErr.textContent = 'La variable es obligatoria';
                varErr.style.display = 'block';
                varInput.classList.add('input-invalid');
                varInput.focus();
                return;
            }

            const payload = { variable, valor, comentario };

            saveBtn.disabled = true;
            try {
                if (isEdit) {
                    await api('parametros.php', { method: 'PUT', body: { id: param.id, ...payload } });
                    toast('Parámetro actualizado');
                } else {
                    await api('parametros.php', { method: 'POST', body: payload });
                    toast('Parámetro creado');
                }
                close();
                if (typeof onSaved === 'function') onSaved();
            } catch (e) {
                saveBtn.disabled = false;
                toast(e.message, 'error');
            }
        });
    }

    function confirmDeleteParametro(param, onDeleted) {
        confirmDialog(
            'Eliminar parámetro',
            `¿Eliminar el parámetro "${param.variable}"? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api('parametros.php?id=' + param.id, { method: 'DELETE' });
                    toast('Parámetro eliminado');
                    if (typeof onDeleted === 'function') onDeleted();
                } catch (e) {
                    toast(e.message, 'error');
                }
            }
        );
    }

    /* ---------- Herramientas: Migraciones ---------- */
    // Tile de Herramientas que abre un modal con pinta de terminal y un
    // botón "Ejecutar". El cuerpo es una consola monoespaciada (estilo
    // `signals-monitor-console`) donde aparece el progreso en vivo.
    //
    // El listado inicial (GET api/migraciones.php) marca cada archivo
    // como aplicado/pendiente. El "Ejecutar" abre un POST que devuelve
    // Server-Sent Events: cada `event: log` pinta una línea en la
    // consola; `event: file` actualiza el contador; `event: done` cierra
    // el run y libera el botón. La operación es idempotente — los
    // archivos ya aplicados se saltean en el server.

    async function openMigracionesConsole() {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal migraciones-modal" role="dialog" aria-modal="true" aria-labelledby="mig-title">
                <div class="modal-header">
                    <div class="modal-title" id="mig-title">
                        <i class="fa-solid fa-terminal"></i> Migraciones
                        <span class="modal-subtitle">Aplica las migraciones pendientes de <code>cloud/sql/migrations/</code>. Operación idempotente.</span>
                    </div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body migraciones-body">
                    <div class="migraciones-toolbar">
                        <div class="migraciones-status" id="mig-status">
                            <span class="spin-sm" id="mig-spin"></span>
                            <span id="mig-status-text">Cargando estado…</span>
                        </div>
                        <div class="migraciones-actions">
                            <button type="button" class="btn btn-ghost btn-sm" data-act="clear" title="Limpiar consola">
                                <i class="fa-solid fa-eraser"></i> Limpiar
                            </button>
                            <button type="button" class="btn btn-primary btn-sm" data-act="run" disabled>
                                <i class="fa-solid fa-play"></i> Ejecutar pendientes
                            </button>
                        </div>
                    </div>
                    <div class="migraciones-console" id="mig-console">
                        <div class="mig-line mig-muted">$ esperando comando…<span class="signals-monitor-caret"></span></div>
                    </div>
                </div>
                <div class="modal-footer">
                    <span class="migraciones-footer-info">
                        <i class="fa-solid fa-database"></i>
                        Idempotente · Los archivos con éxito previo se saltean
                    </span>
                    <button class="btn btn-ghost" data-act="close">Cerrar</button>
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        const consoleEl  = backdrop.querySelector('#mig-console');
        const statusText = backdrop.querySelector('#mig-status-text');
        const spin       = backdrop.querySelector('#mig-spin');
        const btnRun     = backdrop.querySelector('[data-act="run"]');
        const btnClear   = backdrop.querySelector('[data-act="clear"]');

        let activeAbort = null;
        let running     = false;

        const close = () => {
            if (activeAbort) activeAbort.abort();
            backdrop.classList.remove('open');
            setTimeout(() => backdrop.remove(), 200);
        };
        backdrop.addEventListener('click', e => { if (e.target === backdrop && !running) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach(b => b.addEventListener('click', () => {
            if (running) { toast('Hay una migración en curso', 'error'); return; }
            close();
        }));

        function appendLine(level, msg) {
            const cls = ({
                info: 'mig-info', ok: 'mig-ok', warn: 'mig-warn',
                err:  'mig-err',  skip: 'mig-skip',
            })[level] || 'mig-muted';
            // Prefijo tipo prompt para info, [OK]/[ERR]/etc. para el resto.
            const prefix = ({
                ok:   '[OK]  ',
                err:  '[ERR] ',
                warn: '[WRN] ',
                skip: '[--]  ',
                info: '',
            })[level] ?? '';
            const div = document.createElement('div');
            div.className = 'mig-line ' + cls;
            div.textContent = prefix + (msg ?? '');
            consoleEl.appendChild(div);
            consoleEl.scrollTop = consoleEl.scrollHeight;
        }

        function setStatus(text, busy) {
            statusText.textContent = text;
            spin.style.visibility = busy ? '' : 'hidden';
        }

        btnClear.addEventListener('click', () => {
            if (running) return;
            consoleEl.innerHTML = '<div class="mig-line mig-muted">$ consola limpia</div>';
        });

        async function refreshList() {
            setStatus('Cargando estado…', true);
            btnRun.disabled = true;
            try {
                const data = await api('migraciones.php');
                const total = data.total ?? 0;
                const pend  = data.pendientes ?? 0;
                if (total === 0) {
                    setStatus('No se encontraron archivos de migración.', false);
                    btnRun.disabled = true;
                } else if (pend === 0) {
                    setStatus(`Todas al día (${total}/${total} aplicadas).`, false);
                    btnRun.disabled = false; // permitir re-run (será no-op).
                } else {
                    setStatus(`${pend} pendiente(s) de ${total} total.`, false);
                    btnRun.disabled = false;
                }
                appendLine('info', `Estado: ${total - pend}/${total} aplicadas, ${pend} pendiente(s).`);
                (data.migraciones || []).forEach(m => {
                    if (m.aplicada) {
                        appendLine('skip', `${m.archivo}  (aplicada ${m.ejecutado_at ?? ''}, ${m.duracion_ms ?? 0} ms)`);
                    } else if (m.success === 0) {
                        appendLine('warn', `${m.archivo}  (último intento falló: ${m.error ?? 'sin detalle'})`);
                    } else {
                        appendLine('info', `${m.archivo}  (pendiente)`);
                    }
                });
            } catch (e) {
                setStatus('Error al cargar estado.', false);
                appendLine('err', 'No se pudo obtener el estado: ' + e.message);
            }
        }

        btnRun.addEventListener('click', async () => {
            if (running) return;
            running = true;
            btnRun.disabled = true;
            btnClear.disabled = true;
            setStatus('Ejecutando…', true);
            appendLine('info', '');
            appendLine('info', '$ POST api/migraciones.php?run=1');

            activeAbort = new AbortController();
            try {
                await streamMigracionesRun(activeAbort.signal, (event, payload) => {
                    if (event === 'log') {
                        appendLine(payload.level || 'info', payload.msg ?? '');
                    } else if (event === 'file') {
                        // Ya quedó loggeado en el evento `log` paralelo; este es
                        // estructurado por si en el futuro queremos un contador.
                    } else if (event === 'done') {
                        const { aplicadas, salteadas, fallidas } = payload;
                        if (fallidas > 0) {
                            setStatus(`Terminado con errores: ${aplicadas} aplicadas, ${fallidas} fallidas.`, false);
                            toast('Migraciones: ' + fallidas + ' fallida(s)', 'error');
                        } else if (aplicadas > 0) {
                            setStatus(`Listo. ${aplicadas} aplicada(s), ${salteadas} salteada(s).`, false);
                            toast(`Migraciones: ${aplicadas} aplicada(s)`);
                        } else {
                            setStatus(`Sin cambios. ${salteadas} salteada(s).`, false);
                            toast('Sin migraciones pendientes');
                        }
                    }
                });
            } catch (e) {
                if (e.name === 'AbortError') {
                    appendLine('warn', 'Operación cancelada.');
                } else {
                    appendLine('err', 'Error de stream: ' + e.message);
                    setStatus('Error de stream.', false);
                }
            } finally {
                running = false;
                activeAbort = null;
                btnClear.disabled = false;
                btnRun.disabled = false;
            }
        });

        refreshList();
    }

    /**
     * Hace POST a api/migraciones.php?run=1 y parsea la respuesta como
     * stream Server-Sent Events, llamando `onEvent(eventName, dataObj)`
     * por cada mensaje. Resuelve cuando el servidor cierra el stream.
     */
    async function streamMigracionesRun(signal, onEvent) {
        const res = await fetch('api/migraciones.php?run=1', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Accept': 'text/event-stream' },
            signal,
        });
        if (res.status === 401) {
            window.location.href = 'login.php';
            throw new Error('No autenticado');
        }
        if (!res.ok || !res.body) {
            throw new Error('HTTP ' + res.status);
        }

        const reader  = res.body.getReader();
        const decoder = new TextDecoder('utf-8');
        let buffer = '';

        while (true) {
            const { value, done } = await reader.read();
            if (done) break;
            buffer += decoder.decode(value, { stream: true });

            // SSE separa mensajes por línea en blanco (\n\n).
            let sep;
            while ((sep = buffer.indexOf('\n\n')) !== -1) {
                const raw = buffer.slice(0, sep);
                buffer = buffer.slice(sep + 2);

                let evt = 'message';
                let dataLines = [];
                raw.split('\n').forEach(line => {
                    if (line.startsWith('event:')) evt = line.slice(6).trim();
                    else if (line.startsWith('data:')) dataLines.push(line.slice(5).trim());
                });
                if (!dataLines.length) continue;
                let payload = null;
                try { payload = JSON.parse(dataLines.join('\n')); } catch (_) { payload = { raw: dataLines.join('\n') }; }
                onEvent(evt, payload);
            }
        }
    }

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
    function toast(msg, kind) {
        toastEl.textContent = msg;
        toastEl.classList.toggle('error', kind === 'error');
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), 1800);
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
})();
