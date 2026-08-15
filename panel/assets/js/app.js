/* =========================================================
 * Panel — Reactor (SPA shell)
 * Router minimo por hash + chrome del BackOffice. Cada modulo
 * que se agregue debe montar su vista en #view desde una funcion
 * registrada en `routes`.
 * ======================================================= */

(() => {
    'use strict';

    /* ---------- toast ---------- */
    const toastEl = document.getElementById('toast');
    let toastTimer = null;
    function toast(msg, opts = {}) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.toggle('error', !!opts.error);
        toastEl.classList.add('show');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => toastEl.classList.remove('show'), opts.duration || 2400);
    }

    /* ---------- api helper ---------- */
    async function api(path, opts = {}) {
        const res = await fetch(path, {
            method: opts.method || 'GET',
            headers: { 'Accept': 'application/json', ...(opts.headers || {}) },
            credentials: 'same-origin',
            body: opts.body || undefined,
        });
        if (res.status === 401) {
            window.location.href = 'login.php';
            throw new Error('Sesion expirada');
        }
        let body = null;
        try { body = await res.json(); } catch (_) { body = null; }
        if (!res.ok || !body || body.ok === false) {
            const msg = (body && body.error) ? body.error : `Error HTTP ${res.status}`;
            throw new Error(msg);
        }
        return body.data;
    }

    /* ---------- user dropdown ---------- */
    const btnUser  = document.getElementById('btn-user');
    const userMenu = document.getElementById('user-dropdown');
    if (btnUser && userMenu) {
        btnUser.addEventListener('click', (e) => {
            e.stopPropagation();
            userMenu.classList.toggle('open');
        });
        document.addEventListener('click', (e) => {
            if (!userMenu.contains(e.target) && e.target !== btnUser) {
                userMenu.classList.remove('open');
            }
        });
    }

    /* ---------- helpers de vista ---------- */
    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (c) => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
        })[c]);
    }

    // 'YYYY-MM-DD HH:MM:SS' (MySQL) -> 'DD/MM/YYYY HH:MM'. Vacio => null.
    function formatDate(value) {
        const s = String(value ?? '').trim();
        if (s === '' || s.startsWith('0000-00-00')) return null;
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2}))?/);
        if (!m) return s;
        return m[4] ? `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}` : `${m[3]}/${m[2]}/${m[1]}`;
    }

    const DASH = '<span class="muted">—</span>';

    function viewCard(label, valueHtml, full = false) {
        return `<div class="view-card ${full ? 'view-card-full' : 'view-card-half'}">
            <div class="view-card-label">${escapeHtml(label)}</div>
            <div class="view-card-value">${valueHtml || DASH}</div>
        </div>`;
    }

    /* Modal generico: recibe titulo + HTML del body y lo monta sobre un
       backdrop efimero que se destruye al cerrar.
       opts.wide        -> modal ancho (dumps, tablas)
       opts.footerHtml  -> botones extra, a la IZQUIERDA del boton Cerrar
       opts.primaryHtml -> botones a la DERECHA del Cerrar (accion primaria)
       opts.closeLabel  -> etiqueta del boton Cerrar (default "Cerrar")
       opts.onClose     -> callback al cerrar por cualquier via */
    function openModal(title, bodyHtml, opts = {}) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        backdrop.innerHTML = `
            <div class="modal ${opts.wide ? 'modal-wide' : ''}" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">${title}</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">${bodyHtml}</div>
                <div class="modal-footer">
                    ${opts.footerHtml || ''}
                    <button class="btn btn-ghost" data-act="close">${escapeHtml(opts.closeLabel || 'Cerrar')}</button>
                    ${opts.primaryHtml || ''}
                </div>
            </div>
        `;
        document.body.appendChild(backdrop);
        requestAnimationFrame(() => backdrop.classList.add('open'));

        let cerrado = false;
        const close = () => {
            if (cerrado) return;
            cerrado = true;
            backdrop.classList.remove('open');
            document.removeEventListener('keydown', onKey);
            setTimeout(() => backdrop.remove(), 200);
            if (typeof opts.onClose === 'function') opts.onClose();
        };
        // Esc cierra solo el modal de arriba de la pila (Entorno sobre Perfil).
        const onKey = (e) => {
            if (e.key !== 'Escape') return;
            const stack = document.querySelectorAll('.modal-backdrop');
            if (stack[stack.length - 1] === backdrop) close();
        };

        backdrop.addEventListener('click', (e) => { if (e.target === backdrop) close(); });
        backdrop.querySelectorAll('[data-act="close"]').forEach((b) => b.addEventListener('click', close));
        document.addEventListener('keydown', onKey);
        return { backdrop, close };
    }

    /* ---------- modal de Perfil ---------- */
    const btnPerfil = document.getElementById('btn-perfil');
    if (btnPerfil) {
        btnPerfil.addEventListener('click', async (e) => {
            e.preventDefault();
            if (userMenu) userMenu.classList.remove('open');
            try {
                const u = await api('api/perfil.php');
                const m = openModal('Mi perfil', viewGridPerfil(u), {
                    footerHtml: '<button class="btn btn-secondary" data-act="entorno">Entorno</button>',
                });
                m.backdrop.querySelector('[data-act="entorno"]')
                    .addEventListener('click', openEntornoModal);
            } catch (err) {
                toast(err.message || 'No se pudo cargar el perfil', { error: true });
            }
        });
    }

    function viewGridPerfil(u) {
        const dominio = u.dominio_nombre
            ? `<span class="badge badge-info">${escapeHtml(u.dominio_nombre)}</span>`
            : (u.dominio_id ? `<code>#${u.dominio_id}</code>` : '');
        const perfil = u.perfil_nombre
            ? escapeHtml(u.perfil_nombre)
            : (u.perfil_id ? `<code>#${u.perfil_id}</code>` : '');
        const estado = u.habilitado
            ? '<span class="badge badge-success">Habilitado</span>'
            : '<span class="badge badge-danger">Deshabilitado</span>';

        const cards = [
            viewCard('Código',         `<code>#${u.id}</code>`),
            viewCard('Usuario',        escapeHtml(u.usuario)),
            viewCard('Nombre',         escapeHtml(u.nombre), true),
            viewCard('Correo',         u.correo ? escapeHtml(u.correo) : ''),
            viewCard('Celular',        u.celular ? escapeHtml(u.celular) : ''),
            viewCard('Dominio',        dominio),
            viewCard('Perfil',         perfil),
            viewCard('Estado',         estado),
            viewCard('Último ingreso', escapeHtml(formatDate(u.ingresado) || '')),
            viewCard('Registrado',     escapeHtml(formatDate(u.registrado) || '')),
            viewCard('UUID',           u.uuid ? `<code>${escapeHtml(u.uuid)}</code>` : ''),
        ];
        return `<div class="view-grid">${cards.join('')}</div>`;
    }

    /* ---------- modal de Entorno ----------
     * Junta el snapshot del servidor (api/entorno.php) con lo que solo el
     * navegador conoce: cookies visibles, localStorage, sessionStorage y
     * datos de la pagina. Es un visor de diagnostico, todo de solo lectura. */

    function envSection(titulo, items) {
        const entries = Object.entries(items || {});
        const rows = entries.map(([k, v]) => {
            const val = (v === null || v === undefined || v === '') ? DASH : escapeHtml(v);
            return `<tr><th>${escapeHtml(k)}</th><td>${val}</td></tr>`;
        }).join('');
        return `<section class="env-section">
            <h3 class="env-section-title">
                ${escapeHtml(titulo)}<span class="env-count">${entries.length}</span>
            </h3>
            <table class="env-table"><tbody>${rows}</tbody></table>
        </section>`;
    }

    // localStorage / sessionStorage pueden tirar SecurityError (cookies
    // bloqueadas, modo privado viejo): se reporta el error como valor.
    function readStorage(store) {
        const items = {};
        try {
            for (let i = 0; i < store.length; i++) {
                const k = store.key(i);
                items[k] = store.getItem(k);
            }
        } catch (err) {
            items['(inaccesible)'] = err.message;
            return items;
        }
        if (Object.keys(items).length === 0) items['(vacío)'] = '';
        return items;
    }

    function readCookies() {
        const items = {};
        (document.cookie || '').split(';').map((s) => s.trim()).filter(Boolean).forEach((pair) => {
            const i = pair.indexOf('=');
            const k = i === -1 ? pair : pair.slice(0, i);
            let v = i === -1 ? '' : pair.slice(i + 1);
            try { v = decodeURIComponent(v); } catch (_) { /* valor no encodeado */ }
            items[k] = v;
        });
        if (Object.keys(items).length === 0) {
            items['(sin cookies visibles)'] = 'Las cookies HttpOnly no son legibles desde JS';
        }
        return items;
    }

    function readNavegador() {
        const s = window.screen || {};
        const now = new Date();
        return {
            'URL':                 window.location.href,
            'Origen':              window.location.origin,
            'Ruta SPA':            window.location.hash || '(vacío)',
            'Versión de assets':   (document.body && document.body.dataset.version) || '',
            'User agent':          navigator.userAgent,
            'Idioma':              navigator.language,
            'Zona horaria':        Intl.DateTimeFormat().resolvedOptions().timeZone,
            'Hora local':          now.toLocaleString('es-AR'),
            'Offset UTC (min)':    String(-now.getTimezoneOffset()),
            'Pantalla':            `${s.width || '?'}x${s.height || '?'}`,
            'Viewport':            `${window.innerWidth}x${window.innerHeight}`,
            'devicePixelRatio':    String(window.devicePixelRatio || 1),
            'Online':              navigator.onLine ? 'sí' : 'no',
            'Cookies habilitadas': navigator.cookieEnabled ? 'sí' : 'no',
        };
    }

    async function openEntornoModal() {
        let server = {};
        let aviso   = '';
        try {
            const data = await api('api/entorno.php');
            (data.secciones || []).forEach((s) => { server[s.id] = s; });
        } catch (err) {
            aviso = `<div class="alert alert-warn">No se pudo leer el entorno del servidor: ${escapeHtml(err.message)}</div>`;
        }

        const srv = (id) => (server[id] ? envSection(server[id].titulo, server[id].items) : '');

        // Las secciones van dentro de .modal-scroll: el scroll queda contenido
        // en la tarjeta interior y el footer (Cerrar) nunca se va de pantalla.
        const secciones = [
            srv('aplicacion'),
            srv('sesion'),
            srv('cookies'),
            envSection('Cookies (navegador)', readCookies()),
            envSection('localStorage',        readStorage(window.localStorage)),
            envSection('sessionStorage',      readStorage(window.sessionStorage)),
            envSection('Navegador / Página',  readNavegador()),
            srv('php'),
            srv('servidor'),
            srv('variables'),
            srv('constantes'),
        ].join('');

        const html = aviso + `<div class="modal-scroll">${secciones}</div>`;
        openModal('Entorno', html, { wide: true });
    }

    const btnLogout = document.getElementById('btn-logout');
    if (btnLogout) {
        btnLogout.addEventListener('click', async (e) => {
            e.preventDefault();
            try {
                await fetch('api/logout.php', { method: 'POST', credentials: 'same-origin' });
            } catch (_) { /* noop */ }
            window.location.href = 'login.php';
        });
    }

    /* ---------- hamburguesa mobile ---------- */
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sidebar-overlay');
    const hamburger = document.getElementById('hamburger');
    function closeSidebar() {
        sidebar && sidebar.classList.remove('open');
        overlay && overlay.classList.remove('active');
    }
    if (hamburger && sidebar && overlay) {
        hamburger.addEventListener('click', () => {
            sidebar.classList.add('open');
            overlay.classList.add('active');
        });
        overlay.addEventListener('click', closeSidebar);
    }

    /* ---------- sidebar: grupos colapsables + link activo ---------- */
    document.querySelectorAll('.nav-group-toggle').forEach((btn) => {
        btn.addEventListener('click', () => {
            const wrap = btn.closest('.nav-group-wrap');
            if (wrap) wrap.classList.toggle('open');
        });
    });

    /* Items del menu portados del legacy que todavia no tienen modulo:
       se muestran en el sidebar pero no navegan a ningun lado. */
    document.querySelectorAll('.nav-soon').forEach((a) => {
        a.addEventListener('click', (e) => {
            e.preventDefault();
            const label = a.textContent.trim();
            toast(`"${label}" todavía no está disponible.`);
        });
    });

    function setActiveLink(route) {
        document.querySelectorAll('.nav-item[data-route]').forEach((a) => {
            const isActive = a.dataset.route === route;
            a.classList.toggle('active', isActive);
            if (isActive) {
                const wrap = a.closest('.nav-group-wrap');
                if (wrap) wrap.classList.add('open');
            }
        });
    }

    /* ---------- contexto de sesion ----------
     * Lo inyecta index.php desde lib/sesion.php. `dominio` es el id de
     * `usuarios.dominio` capturado al iniciar sesion: TODA la informacion
     * que muestra el panel se filtra por ese dominio. */
    const sesion = (() => {
        const tag = document.getElementById('panel-sesion');
        if (!tag) return {};
        try { return JSON.parse(tag.textContent || '{}') || {}; } catch (_) { return {}; }
    })();

    /* ---------- ABM: menu contextual de fila ----------
     * Un unico menu flotante por pantalla. `anchor` puede ser un Element
     * (se ancla abajo a la izquierda) o un punto {x, y} del click derecho. */
    function closeRowMenu() {
        document.querySelectorAll('.ctx-menu').forEach((m) => m.remove());
    }

    function openRowMenu(items, anchor) {
        closeRowMenu();
        const visibles = (items || []).filter(Boolean);
        if (visibles.length === 0) return;

        const menu = document.createElement('div');
        menu.className = 'ctx-menu';
        menu.setAttribute('role', 'menu');
        menu.innerHTML = visibles.map((it, i) => {
            if (it.sep) return '<div class="ctx-menu-sep"></div>';
            const cls = it.danger ? ' class="ctx-menu-danger"' : '';
            return `<button type="button"${cls} data-idx="${i}" role="menuitem">
                        <i class="fa-solid ${it.icon || 'fa-circle'}"></i><span>${escapeHtml(it.label)}</span>
                    </button>`;
        }).join('');
        document.body.appendChild(menu);
        menu.classList.add('open');

        let x, y;
        if (anchor instanceof Element) {
            const r = anchor.getBoundingClientRect();
            x = r.left; y = r.bottom + 4;
        } else {
            x = (anchor && anchor.x) || 0;
            y = (anchor && anchor.y) || 0;
        }
        // Clampeo al viewport: si no entra a la derecha o abajo, se flipea.
        if (x + menu.offsetWidth  > window.innerWidth  - 8) x = Math.max(8, window.innerWidth  - menu.offsetWidth  - 8);
        if (y + menu.offsetHeight > window.innerHeight - 8) y = Math.max(8, y - menu.offsetHeight - 8);
        menu.style.left = `${x}px`;
        menu.style.top  = `${y}px`;

        menu.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-idx]');
            if (!btn) return;
            const it = visibles[+btn.dataset.idx];
            closeRowMenu();
            if (it && typeof it.onSelect === 'function') it.onSelect();
        });
    }

    document.addEventListener('click', (e) => { if (!e.target.closest('.ctx-menu')) closeRowMenu(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape') closeRowMenu(); });
    window.addEventListener('scroll', closeRowMenu, true);
    window.addEventListener('resize', closeRowMenu);

    /* Confirmacion destructiva: modal chico con Cancelar / Eliminar. */
    function confirmarBaja(texto, onConfirm) {
        const m = openModal('Confirmar', `<p style="font-size:.9rem;line-height:1.5">${texto}</p>`, {
            closeLabel:  'Cancelar',
            primaryHtml: '<button class="btn btn-danger" data-act="ok">Eliminar</button>',
        });
        m.backdrop.querySelector('[data-act="ok"]').addEventListener('click', () => {
            m.close();
            onConfirm();
        });
    }

    /* =========================================================
     * Modulo ABM: Usuarios  (convenciones de la skill abm_design)
     * El listado siempre sale acotado al dominio de la sesion: el filtro
     * lo aplica el backend (api/usuarios.php -> requireDominioId()), aca
     * solo se muestra de que dominio se trata.
     * ======================================================= */

    const USUARIOS_DEFAULTS = { codigo: '', perfil: 0, estado: 'todos', limite: 100, orden: 'id', dir: 'desc' };

    const usuarios = {
        q: '',
        ...USUARIOS_DEFAULTS,
        filas: [],
        perfiles: [],
        resumen: null,
    };

    function usuariosFiltrosActivos() {
        let n = 0;
        if (String(usuarios.codigo) !== USUARIOS_DEFAULTS.codigo) n++;
        if (usuarios.perfil !== USUARIOS_DEFAULTS.perfil)         n++;
        if (usuarios.estado !== USUARIOS_DEFAULTS.estado)         n++;
        if (usuarios.limite !== USUARIOS_DEFAULTS.limite)         n++;
        if (usuarios.orden  !== USUARIOS_DEFAULTS.orden)          n++;
        if (usuarios.dir    !== USUARIOS_DEFAULTS.dir)            n++;
        return n;
    }

    function renderUsuarios(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div style="font-size:1.6rem;line-height:1">👥</div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        Los usuarios son las personas con acceso al sistema, con sus credenciales,
                        datos de contacto y el perfil que define qué pueden hacer.
                        Se listan únicamente los del dominio <strong>${dominio}</strong>, que es el
                        asociado a tu cuenta.
                    </div>
                </div>

                <div class="stats-bar" id="us-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <input type="search" id="us-quick" class="search-input"
                                   placeholder="🔍 Buscar usuario, nombre, correo o celular…">
                            <button type="button" class="search-clear" id="us-quick-clear"
                                    style="display:none" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="us-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="us-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="us-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-primary" id="us-nuevo">+ Nuevo usuario</button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Celular</th>
                                <th>Perfil</th>
                                <th>Estado</th>
                                <th>Último ingreso</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="us-tbody">
                            <tr><td colspan="9" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        const quick = container.querySelector('#us-quick');
        const clear = container.querySelector('#us-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { usuarios.q = quick.value.trim(); cargarUsuarios(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            usuarios.q = ''; cargarUsuarios();
        });

        container.querySelector('#us-filtros').addEventListener('click', abrirFiltrosUsuarios);
        container.querySelector('#us-refrescar').addEventListener('click', () => cargarUsuarios());
        container.querySelector('#us-nuevo').addEventListener('click', () => formUsuario(null));

        cargarUsuarios();
    }

    async function cargarUsuarios() {
        const tbody = document.getElementById('us-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="9" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:      usuarios.q,
            codigo: usuarios.codigo || '',
            perfil: usuarios.perfil || '',
            estado: usuarios.estado,
            limite: usuarios.limite,
            orden:  usuarios.orden,
            dir:    usuarios.dir,
        });

        try {
            const data = await api(`api/usuarios.php?${qs}`);
            usuarios.filas    = data.usuarios || [];
            usuarios.perfiles = data.perfiles || [];
            usuarios.resumen  = data.resumen  || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="9" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsUsuarios();
        pintarBadgeFiltrosUsuarios();

        if (usuarios.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="9" class="table-empty">No hay usuarios que coincidan con la búsqueda.</td></tr>';
            return;
        }

        tbody.innerHTML = usuarios.filas.map(filaUsuario).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const u  = usuarios.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verUsuario(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuUsuario(u), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuUsuario(u), e.currentTarget);
            });
        });
    }

    function pintarStatsUsuarios() {
        const el = document.getElementById('us-stats');
        const r  = usuarios.resumen;
        if (!el || !r) return;
        el.innerHTML = `
            <div class="stat-card"><span class="stat-label">Total del dominio</span><span class="stat-value">${r.total}</span></div>
            <div class="stat-card"><span class="stat-label">Habilitados</span><span class="stat-value green">${r.habilitados}</span></div>
            <div class="stat-card"><span class="stat-label">Deshabilitados</span><span class="stat-value muted">${r.deshabilitados}</span></div>
            <div class="stat-card"><span class="stat-label">Mostrados</span><span class="stat-value">${r.mostrados}</span></div>
        `;
    }

    function pintarBadgeFiltrosUsuarios() {
        const btn   = document.getElementById('us-filtros');
        const badge = document.getElementById('us-filtros-badge');
        if (!btn || !badge) return;
        const n = usuariosFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    function filaUsuario(u) {
        const estado = u.habilitado
            ? '<span class="badge badge-success">Habilitado</span>'
            : '<span class="badge badge-danger">Deshabilitado</span>';
        return `
            <tr data-id="${u.id}" class="row-clickable">
                <td class="td-id">#${u.id}</td>
                <td>${escapeHtml(u.usuario)}</td>
                <td class="td-nombre">${escapeHtml(u.nombre)}</td>
                <td>${u.correo  ? escapeHtml(u.correo)  : DASH}</td>
                <td>${u.celular ? escapeHtml(u.celular) : DASH}</td>
                <td>${u.perfil_nombre ? escapeHtml(u.perfil_nombre) : DASH}</td>
                <td>${estado}</td>
                <td>${escapeHtml(formatDate(u.ingresado) || '') || DASH}</td>
                <td class="action-col">
                    <div class="actions">
                        <button type="button" class="btn-icon-sm" data-act="menu" title="Más acciones">
                            <i class="fa-solid fa-bars"></i>
                        </button>
                    </div>
                </td>
            </tr>
        `;
    }

    // Menu contextual de fila (skill abm_design): Consultar -> acciones del
    // recurso -> separador -> Editar -> Eliminar (destructiva, al final).
    function menuUsuario(u) {
        return [
            { label: 'Consultar', icon: 'fa-eye',    onSelect: () => verUsuario(u.id) },
            {
                label: u.habilitado ? 'Deshabilitar' : 'Habilitar',
                icon:  u.habilitado ? 'fa-user-slash' : 'fa-user-check',
                onSelect: () => toggleUsuario(u),
            },
            { sep: true },
            { label: 'Editar',   icon: 'fa-pen',   onSelect: () => formUsuario(u.id) },
            { label: 'Eliminar', icon: 'fa-trash', danger: true, onSelect: () => eliminarUsuario(u) },
        ];
    }

    /* ---------- Consultar ---------- */
    async function verUsuario(id) {
        let u;
        try {
            u = (await api(`api/usuarios.php?id=${id}`)).usuario;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const estado = u.habilitado
            ? '<span class="badge badge-success">Habilitado</span>'
            : '<span class="badge badge-danger">Deshabilitado</span>';

        const body = `<div class="view-grid">${[
            viewCard('Código',            `<code>#${u.id}</code>`),
            viewCard('Usuario',           escapeHtml(u.usuario)),
            viewCard('Nombre',            escapeHtml(u.nombre), true),
            viewCard('Correo',            u.correo  ? escapeHtml(u.correo)  : ''),
            viewCard('Celular',           u.celular ? escapeHtml(u.celular) : ''),
            viewCard('Perfil',            u.perfil_nombre ? escapeHtml(u.perfil_nombre) : (u.perfil ? `<code>#${u.perfil}</code>` : '')),
            viewCard('Dominio',           u.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(u.dominio_nombre)}</span>` : ''),
            viewCard('Estado',            estado),
            viewCard('Autenticación',     u.autenticacion ? escapeHtml(u.autenticacion) : ''),
            viewCard('Roles',             u.roles ? escapeHtml(u.roles) : '', true),
            viewCard('Registrado por',    u.registrante_nombre ? escapeHtml(u.registrante_nombre) : ''),
            viewCard('Registrado',        escapeHtml(formatDate(u.registrado) || '')),
            viewCard('Último ingreso',    escapeHtml(formatDate(u.ingresado) || '')),
            viewCard('Panel',             u.panel ? `<code>#${u.panel}</code>` : ''),
            viewCard('UUID',              u.uuid ? `<code>${escapeHtml(u.uuid)}</code>` : ''),
        ].join('')}</div>`;

        const m = openModal(`Consultar usuario <span class="muted">#${u.id}</span>`, body, {
            footerHtml:  '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
            primaryHtml: '<button class="btn btn-primary" data-act="editar">✏️ Editar</button>',
        });

        m.backdrop.querySelector('[data-act="editar"]').addEventListener('click', () => {
            m.close();
            formUsuario(u.id);
        });
        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            openRowMenu([
                { label: 'Copiar usuario', icon: 'fa-copy', onSelect: () => copiar(u.usuario) },
                u.correo ? { label: 'Copiar correo', icon: 'fa-envelope', onSelect: () => copiar(u.correo) } : null,
                { sep: true },
                {
                    label: u.habilitado ? 'Deshabilitar' : 'Habilitar',
                    icon:  u.habilitado ? 'fa-user-slash' : 'fa-user-check',
                    onSelect: () => { m.close(); toggleUsuario(u); },
                },
            ], e.currentTarget);
        });
    }

    function copiar(texto) {
        navigator.clipboard.writeText(String(texto || ''))
            .then(() => toast('Copiado al portapapeles'))
            .catch(() => toast('No se pudo copiar', { error: true }));
    }

    /* ---------- Alta / Edición ---------- */
    async function formUsuario(id) {
        const esEdicion = id != null;
        let u = { usuario: '', nombre: '', correo: '', celular: '', roles: '', perfil: null, habilitado: true };

        if (esEdicion) {
            try {
                u = (await api(`api/usuarios.php?id=${id}`)).usuario;
            } catch (err) {
                toast(err.message, { error: true });
                return;
            }
        }

        const opciones = ['<option value="">— Sin perfil —</option>'].concat(
            usuarios.perfiles.map((p) =>
                `<option value="${p.id}"${p.id === u.perfil ? ' selected' : ''}>${escapeHtml(p.nombre)}</option>`)
        ).join('');

        const dominio = sesion.dominio_nombre || (sesion.dominio ? `#${sesion.dominio}` : '—');

        const body = `
            <div class="form-row">
                <div class="form-group">
                    <label for="uf-usuario">Usuario *</label>
                    <input type="text" id="uf-usuario" maxlength="100" value="${escapeHtml(u.usuario)}">
                </div>
                <div class="form-group">
                    <label for="uf-nombre">Nombre *</label>
                    <input type="text" id="uf-nombre" maxlength="100" value="${escapeHtml(u.nombre)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="uf-correo">Correo</label>
                    <input type="email" id="uf-correo" maxlength="100" value="${escapeHtml(u.correo)}">
                </div>
                <div class="form-group">
                    <label for="uf-celular">Celular</label>
                    <input type="tel" id="uf-celular" maxlength="15" value="${escapeHtml(u.celular)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="uf-perfil">Perfil</label>
                    <select id="uf-perfil">${opciones}</select>
                </div>
                <div class="form-group">
                    <label for="uf-roles">Roles</label>
                    <input type="text" id="uf-roles" maxlength="255" value="${escapeHtml(u.roles)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="uf-contrasena">Contraseña ${esEdicion ? '' : '*'}</label>
                    <input type="password" id="uf-contrasena" maxlength="32" autocomplete="new-password"
                           placeholder="${esEdicion ? 'Dejar vacío para no cambiarla' : 'Mínimo 4 caracteres'}">
                </div>
                <div class="form-group">
                    <label>Dominio</label>
                    <input type="text" value="${escapeHtml(dominio)}" readonly title="Se asigna desde tu sesión">
                </div>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="uf-habilitado" ${u.habilitado ? 'checked' : ''}>
                    Usuario habilitado para ingresar
                </label>
            </div>
            <div class="field-error" id="uf-error" style="display:none"></div>
        `;

        const m = openModal(
            esEdicion ? `Editar usuario <span class="muted">#${u.id}</span>` : 'Nuevo usuario',
            body,
            {
                closeLabel:  'Cancelar',
                primaryHtml: `<button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar cambios' : 'Crear usuario'}</button>`,
            }
        );

        const err = m.backdrop.querySelector('#uf-error');
        const btn = m.backdrop.querySelector('[data-act="guardar"]');

        btn.addEventListener('click', async () => {
            const payload = {
                usuario:    m.backdrop.querySelector('#uf-usuario').value.trim(),
                nombre:     m.backdrop.querySelector('#uf-nombre').value.trim(),
                correo:     m.backdrop.querySelector('#uf-correo').value.trim(),
                celular:    m.backdrop.querySelector('#uf-celular').value.trim(),
                roles:      m.backdrop.querySelector('#uf-roles').value.trim(),
                perfil:     +(m.backdrop.querySelector('#uf-perfil').value || 0),
                contrasena: m.backdrop.querySelector('#uf-contrasena').value,
                habilitado: m.backdrop.querySelector('#uf-habilitado').checked,
            };
            if (esEdicion) payload.id = u.id;

            btn.disabled = true;
            try {
                await api('api/usuarios.php', {
                    method:  esEdicion ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                m.close();
                toast(esEdicion ? 'Usuario actualizado' : 'Usuario creado');
                cargarUsuarios();
            } catch (e2) {
                err.textContent   = e2.message;
                err.style.display = '';
                btn.disabled = false;
            }
        });
    }

    async function toggleUsuario(u) {
        try {
            const full = (await api(`api/usuarios.php?id=${u.id}`)).usuario;
            await api('api/usuarios.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    id:         full.id,
                    usuario:    full.usuario,
                    nombre:     full.nombre,
                    correo:     full.correo,
                    celular:    full.celular,
                    roles:      full.roles,
                    perfil:     full.perfil || 0,
                    habilitado: !full.habilitado,
                }),
            });
            toast(full.habilitado ? 'Usuario deshabilitado' : 'Usuario habilitado');
            cargarUsuarios();
        } catch (err) {
            toast(err.message, { error: true });
        }
    }

    function eliminarUsuario(u) {
        confirmarBaja(
            `¿Eliminar al usuario <strong>${escapeHtml(u.usuario)}</strong> (${escapeHtml(u.nombre)})? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api(`api/usuarios.php?id=${u.id}`, { method: 'DELETE' });
                    toast('Usuario eliminado');
                    cargarUsuarios();
                } catch (err) {
                    toast(err.message, { error: true });
                }
            }
        );
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ----
     * Los cambios se aplican EN VIVO sobre el listado de fondo; "Aplicar"
     * solo cierra. "Cerrar" revierte al snapshot tomado al abrir. */
    function abrirFiltrosUsuarios() {
        const snapshot = { ...usuarios };
        let aplicado   = false;

        const perfilOpts = ['<option value="0">Todos</option>'].concat(
            usuarios.perfiles.map((p) =>
                `<option value="${p.id}"${p.id === usuarios.perfil ? ' selected' : ''}>${escapeHtml(p.nombre)}</option>`)
        ).join('');

        const chip = (val, label) =>
            `<button type="button" class="filter-chip${usuarios.estado === val ? ' active' : ''}" data-estado="${val}">${label}</button>`;

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="uf-f-codigo">Código</label>
                    <input type="number" min="1" id="uf-f-codigo" placeholder="ID del usuario" value="${escapeHtml(usuarios.codigo)}">
                </div>
                <div class="form-group">
                    <label for="uf-f-perfil">Perfil</label>
                    <select id="uf-f-perfil">${perfilOpts}</select>
                </div>
            </div>
            <div class="form-group">
                <label>Estado del registro</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="uf-f-estado">
                    ${chip('todos', 'Todos')}
                    ${chip('habilitados', 'Habilitados')}
                    ${chip('deshabilitados', 'Deshabilitados')}
                </div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="uf-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="uf-f-limite" value="${usuarios.limite}">
                </div>
                <div class="form-group">
                    <label for="uf-f-orden">Ordenar por</label>
                    <select id="uf-f-orden">
                        <option value="id">Código</option>
                        <option value="usuario">Usuario</option>
                        <option value="nombre">Nombre</option>
                        <option value="correo">Correo</option>
                        <option value="registrado">Registrado</option>
                        <option value="ingresado">Último ingreso</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="uf-f-dir">Dirección</label>
                    <select id="uf-f-dir">
                        <option value="desc">Descendente</option>
                        <option value="asc">Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        const m = openModal('<i class="fa-solid fa-filter"></i> Filtros', body, {
            primaryHtml: `
                <button class="btn btn-ghost"   data-act="limpiar">Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar">Aplicar</button>`,
            onClose: () => {
                // Cerrar / Esc / backdrop revierten; Aplicar no.
                if (aplicado) return;
                Object.assign(usuarios, snapshot);
                cargarUsuarios();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#uf-f-orden').value = usuarios.orden;
        $('#uf-f-dir').value   = usuarios.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosUsuarios(); cargarUsuarios(); };

        $('#uf-f-codigo').addEventListener('input',  (e) => { usuarios.codigo = e.target.value.trim(); aplicarEnVivo(); });
        $('#uf-f-perfil').addEventListener('change', (e) => { usuarios.perfil = +e.target.value || 0;  aplicarEnVivo(); });
        $('#uf-f-limite').addEventListener('change', (e) => { usuarios.limite = +e.target.value || 100; aplicarEnVivo(); });
        $('#uf-f-orden').addEventListener('change',  (e) => { usuarios.orden  = e.target.value; aplicarEnVivo(); });
        $('#uf-f-dir').addEventListener('change',    (e) => { usuarios.dir    = e.target.value; aplicarEnVivo(); });

        $('#uf-f-estado').addEventListener('click', (e) => {
            const b = e.target.closest('[data-estado]');
            if (!b) return;
            usuarios.estado = b.dataset.estado;
            m.backdrop.querySelectorAll('#uf-f-estado .filter-chip')
                .forEach((c) => c.classList.toggle('active', c === b));
            aplicarEnVivo();
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            Object.assign(usuarios, USUARIOS_DEFAULTS);
            $('#uf-f-codigo').value = '';
            $('#uf-f-perfil').value = '0';
            $('#uf-f-limite').value = USUARIOS_DEFAULTS.limite;
            $('#uf-f-orden').value  = USUARIOS_DEFAULTS.orden;
            $('#uf-f-dir').value    = USUARIOS_DEFAULTS.dir;
            m.backdrop.querySelectorAll('#uf-f-estado .filter-chip')
                .forEach((c) => c.classList.toggle('active', c.dataset.estado === USUARIOS_DEFAULTS.estado));
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* ---------- router ---------- */
    const viewEl  = document.getElementById('view');
    const titleEl = document.getElementById('view-title');

    // Cada modulo futuro se registra aca: `route: { title, render(container) }`.
    // Por ahora solo el dashboard: placeholder para que el shell tenga contenido.
    const routes = {
        dashboard: {
            title: 'Dashboard',
            render(container) {
                container.innerHTML = `
                    <div class="module-header">
                        <h2 class="module-title">Dashboard</h2>
                        <p class="module-subtitle">
                            Bienvenido al panel administrativo de Reactor.
                            Elegí un módulo desde el menú lateral para empezar.
                        </p>
                    </div>
                    <div class="alert alert-info">
                        Este panel está recién iniciado — no hay módulos cargados todavía.
                        Cada módulo se agregará bajo su categoría en el sidebar.
                    </div>
                `;
            },
        },
        usuarios: {
            title: 'Usuarios',
            render: renderUsuarios,
        },
    };

    function currentRoute() {
        const hash = window.location.hash || '#/dashboard';
        const key  = hash.replace(/^#\/?/, '').split('/')[0] || 'dashboard';
        return routes[key] ? key : 'dashboard';
    }

    function render() {
        const key   = currentRoute();
        const route = routes[key];
        if (titleEl) titleEl.textContent = route.title;
        setActiveLink(key);
        try {
            route.render(viewEl);
        } catch (err) {
            viewEl.innerHTML = `<div class="alert alert-error">Error al renderizar la vista: ${err.message}</div>`;
        }
        closeSidebar();
    }

    window.addEventListener('hashchange', render);
    render();

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

    /* expuesto para modulos futuros */
    window.Panel = { api, toast };
})();
