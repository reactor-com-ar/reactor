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

    // Importes y cantidades en formato local. `formatMoneda` siempre en pesos:
    // `comprobantes` no guarda moneda, todo se factura en ARS (la columna
    // `cotizacion` es el dolar de referencia del mes, no otra moneda).
    const MONEDA_AR = new Intl.NumberFormat('es-AR', { style: 'currency', currency: 'ARS' });
    const NUMERO_AR = new Intl.NumberFormat('es-AR', { maximumFractionDigits: 2 });

    function formatMoneda(valor) {
        if (valor === null || valor === undefined || valor === '') return null;
        const n = Number(valor);
        return Number.isFinite(n) ? MONEDA_AR.format(n) : null;
    }

    function formatNumero(valor) {
        if (valor === null || valor === undefined || valor === '') return null;
        const n = Number(valor);
        return Number.isFinite(n) ? NUMERO_AR.format(n) : null;
    }

    const DASH = '<span class="muted">—</span>';

    /* `ancho` define cuanto ocupa la tarjeta en la .view-grid: false = media
       (el default de casi todos los campos), true = fila entera y, para filas
       de tres columnas como el inventario del modulo Dominio, la cadena
       'third'. Los booleanos se mantienen por los call sites existentes. */
    function viewCard(label, valueHtml, ancho = false) {
        const clase = ancho === true  ? 'view-card-full'
                    : ancho === false ? 'view-card-half'
                    : `view-card-${ancho}`;
        return `<div class="view-card ${clase}">
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

    /* ---------- modal de Cambiar dominio ----------
     * Lista los perfiles de la cuenta (api/dominios.php), uno por fila, con el
     * dominio como titulo y el rol abajo. Al elegir uno, el POST asienta la
     * seleccion en `usuarios` y reemite el JWT: la sesion arranca de nuevo en
     * ese dominio sin pedir credenciales, y el reload muestra el panel ya
     * filtrado. Porta reactor-panel/sesion/cambiar.php del legacy. */
    const btnDominio = document.getElementById('btn-dominio');
    if (btnDominio) {
        btnDominio.addEventListener('click', async (e) => {
            e.preventDefault();
            if (userMenu) userMenu.classList.remove('open');
            try {
                const d = await api('api/dominios.php');
                const m = openModal('<i class="fa-solid fa-recycle"></i> Cambiar dominio', listaDominios(d));
                m.backdrop.querySelectorAll('[data-perfil]').forEach((btn) => {
                    btn.addEventListener('click', () => cambiarDominio(Number(btn.dataset.perfil), m.backdrop));
                });
            } catch (err) {
                toast(err.message || 'No se pudieron cargar los dominios', { error: true });
            }
        });
    }

    function listaDominios(d) {
        const items = d.perfiles || [];
        if (items.length === 0) {
            return `<div class="alert alert-info">
                Tu cuenta no tiene ningún dominio disponible. Pedile a un administrador
                que te asigne uno.
            </div>`;
        }

        const filas = items.map((x) => {
            const tags = [];
            if (x.actual)      tags.push('<span class="badge badge-success">Actual</span>');
            if (!x.habilitado) tags.push('<span class="badge badge-danger">Deshabilitado</span>');

            // Sin perfil = dominio activo sin fila en `perfiles` (lo asigna el
            // back office interno). No hay nada que asentar en la cuenta, asi
            // que la fila se muestra pero no es elegible.
            const meta  = x.rol ? escapeHtml(x.rol) : 'Sin perfil asignado — no se puede seleccionar';
            const clase = `dominio-item${x.actual ? ' is-actual' : ''}`;
            const body  = `<span class="dominio-item-icon"><i class="fa-solid fa-building"></i></span>
                <span class="dominio-item-body">
                    <span class="dominio-item-nombre">${escapeHtml(x.nombre || `#${x.dominio}`)}</span>
                    <span class="dominio-item-meta">${meta}</span>
                </span>
                <span class="dominio-item-tags">${tags.join('')}</span>`;

            return x.perfil
                ? `<button type="button" class="${clase}" data-perfil="${x.perfil}">${body}</button>`
                : `<div class="${clase}">${body}</div>`;
        }).join('');

        return `<p class="modal-note">
            Elegí con qué perfil querés seguir trabajando. El dominio del perfil
            elegido es el que filtra toda la información del panel.
        </p>
        <div class="dominio-list">${filas}</div>`;
    }

    async function cambiarDominio(perfilId, backdrop) {
        const lista = backdrop.querySelector('.dominio-list');
        if (lista) lista.classList.add('is-busy');
        try {
            await api('api/dominios.php', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ perfil: perfilId }),
            });
            // El POST ya reemplazo la cookie por un token con los claims del
            // dominio nuevo: recargar es todo lo que hace falta para que la
            // sesion arranque ahi. No se cierra el modal a proposito, para que
            // no parpadee el panel viejo antes de la recarga.
            window.location.reload();
        } catch (err) {
            if (lista) lista.classList.remove('is-busy');
            toast(err.message || 'No se pudo cambiar de dominio', { error: true });
        }
    }

    /* ---------- modal de Perfil ---------- */
    const btnPerfil = document.getElementById('btn-perfil');
    if (btnPerfil) {
        btnPerfil.addEventListener('click', async (e) => {
            e.preventDefault();
            if (userMenu) userMenu.classList.remove('open');
            try {
                const u = await api('api/perfil.php');
                const m = openModal('Mi cuenta', viewGridPerfil(u), {
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
    // `label` cambia el texto del boton rojo para las acciones irreversibles
    // que no son una baja (ej. Liberar un dispositivo).
    function confirmarBaja(texto, onConfirm, { label = 'Eliminar' } = {}) {
        const m = openModal('Confirmar', `<p style="font-size:.9rem;line-height:1.5">${texto}</p>`, {
            closeLabel:  'Cancelar',
            primaryHtml: `<button class="btn btn-danger" data-act="ok">${label}</button>`,
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
                    <div class="module-help-icon"><i class="fa-solid fa-users"></i></div>
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
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="us-quick" class="search-input"
                                   placeholder="Buscar usuario, nombre, correo o celular…">
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
            primaryHtml: '<button class="btn btn-primary" data-act="editar"><i class="fa-solid fa-pen-to-square"></i> Editar</button>',
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

    /* =========================================================
     * Modulo ABM: Dispositivos  (convenciones de la skill abm_design)
     * Portado de reactor-panel/dispositivos/listar.php: mismo recorte por
     * dominio, mismos filtros (codigo, identificador, nombre, enlace,
     * habilitado, limite) y las mismas columnas del listado legacy.
     * El filtro por dominio lo aplica el backend (api/dispositivos.php ->
     * requireDominioId()), aca solo se muestra de que dominio se trata.
     * ======================================================= */

    const DISPOSITIVOS_DEFAULTS = { codigo: '', modelo: 0, enlace: 'todos', estado: 'todos', limite: 100, orden: 'id', dir: 'desc' };

    const dispositivos = {
        q: '',
        ...DISPOSITIVOS_DEFAULTS,
        filas: [],
        catalogos: { agentes: [], modelos: [], productos: [], transceptores: [], chips: [] },
        resumen: null,
    };

    function dispositivosFiltrosActivos() {
        let n = 0;
        if (String(dispositivos.codigo) !== DISPOSITIVOS_DEFAULTS.codigo) n++;
        if (dispositivos.modelo !== DISPOSITIVOS_DEFAULTS.modelo)         n++;
        if (dispositivos.enlace !== DISPOSITIVOS_DEFAULTS.enlace)         n++;
        if (dispositivos.estado !== DISPOSITIVOS_DEFAULTS.estado)         n++;
        if (dispositivos.limite !== DISPOSITIVOS_DEFAULTS.limite)         n++;
        if (dispositivos.orden  !== DISPOSITIVOS_DEFAULTS.orden)          n++;
        if (dispositivos.dir    !== DISPOSITIVOS_DEFAULTS.dir)            n++;
        return n;
    }

    // 'YYYY-MM-DD HH:MM:SS' -> 'YYYY-MM-DDTHH:MM' (valor de <input datetime-local>).
    function toInputDateTime(value) {
        const s = String(value ?? '').trim();
        if (s === '' || s.startsWith('0000-00-00')) return '';
        const m = s.match(/^(\d{4}-\d{2}-\d{2})(?:[ T](\d{2}:\d{2}))?/);
        return m ? `${m[1]}T${m[2] || '00:00'}` : '';
    }

    function badgeEnlace(online) {
        return online
            ? '<span class="badge badge-success">En línea</span>'
            : '<span class="badge badge-warn">Fuera de línea</span>';
    }

    function badgeHabilitado(habilitado) {
        return habilitado
            ? '<span class="badge badge-success">Habilitado</span>'
            : '<span class="badge badge-danger">Deshabilitado</span>';
    }

    function renderDispositivos(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-microchip"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        Los dispositivos son los equipos instalados en campo que reportan señales
                        al sistema: cada uno tiene su identificador de fábrica, su modelo y su
                        estado de enlace con la plataforma.
                        Se listan únicamente los del dominio <strong>${dominio}</strong>, que es el
                        asociado a tu cuenta.
                    </div>
                </div>

                <div class="stats-bar" id="dv-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="dv-quick" class="search-input"
                                   placeholder="Buscar identificador, nombre, MAC, IP o serie…">
                            <button type="button" class="search-clear" id="dv-quick-clear"
                                    style="display:none" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="dv-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="dv-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="dv-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-primary" id="dv-nuevo">+ Nuevo dispositivo</button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Identificador</th>
                                <th>Nombre</th>
                                <th>Modelo</th>
                                <th>Enlace</th>
                                <th>Estado</th>
                                <th>Último latido</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="dv-tbody">
                            <tr><td colspan="8" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        const quick = container.querySelector('#dv-quick');
        const clear = container.querySelector('#dv-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { dispositivos.q = quick.value.trim(); cargarDispositivos(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            dispositivos.q = ''; cargarDispositivos();
        });

        container.querySelector('#dv-filtros').addEventListener('click', abrirFiltrosDispositivos);
        container.querySelector('#dv-refrescar').addEventListener('click', () => cargarDispositivos());
        container.querySelector('#dv-nuevo').addEventListener('click', () => formDispositivo(null));

        cargarDispositivos();
    }

    async function cargarDispositivos() {
        const tbody = document.getElementById('dv-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:      dispositivos.q,
            codigo: dispositivos.codigo || '',
            modelo: dispositivos.modelo || '',
            enlace: dispositivos.enlace,
            estado: dispositivos.estado,
            limite: dispositivos.limite,
            orden:  dispositivos.orden,
            dir:    dispositivos.dir,
        });

        try {
            const data = await api(`api/dispositivos.php?${qs}`);
            dispositivos.filas     = data.dispositivos || [];
            dispositivos.catalogos = data.catalogos || dispositivos.catalogos;
            dispositivos.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsDispositivos();
        pintarBadgeFiltrosDispositivos();

        if (dispositivos.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">No hay dispositivos que coincidan con la búsqueda.</td></tr>';
            return;
        }

        tbody.innerHTML = dispositivos.filas.map(filaDispositivo).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const d  = dispositivos.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verDispositivo(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuDispositivo(d), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuDispositivo(d), e.currentTarget);
            });
        });
    }

    function pintarStatsDispositivos() {
        const el = document.getElementById('dv-stats');
        const r  = dispositivos.resumen;
        if (!el || !r) return;
        el.innerHTML = `
            <div class="stat-card"><span class="stat-label">Total del dominio</span><span class="stat-value">${r.total}</span></div>
            <div class="stat-card"><span class="stat-label">En línea</span><span class="stat-value green">${r.enlazados}</span></div>
            <div class="stat-card"><span class="stat-label">Habilitados</span><span class="stat-value">${r.habilitados}</span></div>
            <div class="stat-card"><span class="stat-label">Mostrados</span><span class="stat-value">${r.mostrados}</span></div>
        `;
    }

    function pintarBadgeFiltrosDispositivos() {
        const btn   = document.getElementById('dv-filtros');
        const badge = document.getElementById('dv-filtros-badge');
        if (!btn || !badge) return;
        const n = dispositivosFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    function filaDispositivo(d) {
        return `
            <tr data-id="${d.id}" class="row-clickable">
                <td class="td-id">#${d.id}</td>
                <td>${d.uuid ? `<code>${escapeHtml(d.uuid)}</code>` : DASH}</td>
                <td class="td-nombre">${d.nombre ? escapeHtml(d.nombre) : DASH}</td>
                <td>${d.modelo_nombre ? escapeHtml(d.modelo_nombre) : DASH}</td>
                <td>${badgeEnlace(d.enlace)}</td>
                <td>${badgeHabilitado(d.habilitado)}</td>
                <td>${escapeHtml(formatDate(d.latido) || '') || DASH}</td>
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
    // recurso -> separador -> Editar -> Liberar (irreversible, al final).
    function menuDispositivo(d) {
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verDispositivo(d.id) },
            {
                label: d.habilitado ? 'Deshabilitar' : 'Habilitar',
                icon:  d.habilitado ? 'fa-plug-circle-xmark' : 'fa-plug-circle-check',
                onSelect: () => toggleDispositivo(d),
            },
            d.uuid ? { label: 'Copiar identificador', icon: 'fa-copy', onSelect: () => copiar(d.uuid) } : null,
            { sep: true },
            { label: 'Editar',  icon: 'fa-pen',        onSelect: () => formDispositivo(d.id) },
            { label: 'Liberar', icon: 'fa-link-slash', danger: true, onSelect: () => liberarDispositivo(d) },
        ];
    }

    /* ---------- Consultar ----------
     * Dos pestañas: General (la ficha completa) y Conexión (el nivel de
     * señal que viene reportando el equipo). La segunda pega contra otro
     * endpoint y se carga recién cuando el usuario la abre: la consulta
     * sobre `senales` es cara y la mayoría de las veces nadie la mira. */
    async function verDispositivo(id) {
        let d;
        try {
            d = (await api(`api/dispositivos.php?id=${id}`)).dispositivo;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const ref = (nombre, valor) => nombre
            ? escapeHtml(nombre)
            : (valor ? `<code>#${valor}</code>` : '');
        const num = (v) => (v === null || v === undefined ? '' : String(v));

        const general = `<div class="view-grid">${[
            viewCard('Código',                `<code>#${d.id}</code>`),
            viewCard('Identificador',         d.uuid ? `<code>${escapeHtml(d.uuid)}</code>` : ''),
            viewCard('Nombre',                escapeHtml(d.nombre), true),
            viewCard('Estado',                badgeHabilitado(d.habilitado)),
            viewCard('Enlace',                badgeEnlace(d.enlace)),
            viewCard('Dominio',               d.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(d.dominio_nombre)}</span>` : ''),
            viewCard('Agente',                ref(d.agente_nombre, d.agente)),
            viewCard('Modelo',                ref(d.modelo_nombre, d.modelo)),
            viewCard('Producto',              ref(d.producto_nombre, d.producto)),
            viewCard('Transceptor',           ref(d.transceptor_nombre, d.transceptor)),
            viewCard('Chip',                  ref(d.chip_nombre, d.chip)),
            viewCard('Firmware',              escapeHtml(d.firmware)),
            viewCard('MAC',                   d.mac ? `<code>${escapeHtml(d.mac)}</code>` : ''),
            viewCard('IP',                    d.ip ? `<code>${escapeHtml(d.ip)}</code>` : ''),
            viewCard('Señal',                 escapeHtml(d.senal)),
            viewCard('Serie',                 escapeHtml(d.serial)),
            viewCard('Identidad',             escapeHtml(d.identidad)),
            viewCard('Llave',                 d.llave ? `<code>${escapeHtml(d.llave)}</code>` : ''),
            viewCard('Límite de señales',     escapeHtml(num(d.senalesLimite))),
            viewCard('Fabricación',           escapeHtml(formatDate(d.fabricacion) || '')),
            viewCard('Instalación',           escapeHtml(formatDate(d.instalacion) || '')),
            viewCard('Adoptado',              d.adoptado ? '<span class="badge badge-info">Sí</span>' : '<span class="badge badge-warn">No</span>'),
            viewCard('Adopción',              d.adopcion ? `<code>#${d.adopcion}</code>` : ''),
            viewCard('Último inicio',         escapeHtml(formatDate(d.inicio) || '')),
            viewCard('Última conexión',       escapeHtml(formatDate(d.conexion) || '')),
            viewCard('Último latido',         escapeHtml(formatDate(d.latido) || '')),
            viewCard('Inicios',               escapeHtml(num(d.inicios))),
            viewCard('Conexiones',            escapeHtml(num(d.conexiones))),
            viewCard('Latidos',               escapeHtml(num(d.latidos))),
            viewCard('Monitoreo',             d.monitoreo ? '<span class="badge badge-success">Activo</span>' : '<span class="badge badge-danger">Inactivo</span>'),
            viewCard('Intervalo de monitoreo', escapeHtml(num(d.monitoreoIntervalo))),
            viewCard('Último monitoreo',      escapeHtml(formatDate(d.monitoreoUltimo) || '')),
            viewCard('Próximo monitoreo',     escapeHtml(formatDate(d.monitoreoSiguiente) || '')),
            viewCard('Correos de monitoreo',  escapeHtml(d.monitoreoCorreos), true),
            viewCard('Coordenadas',           escapeHtml(d.coordenadas), true),
            viewCard('Indicadores',           escapeHtml(d.indicadores), true),
        ].join('')}</div>`;

        const body = `
            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" data-tab="general" role="tab" aria-selected="true">
                    <i class="fa-solid fa-circle-info"></i> General
                </button>
                <button type="button" class="modal-tab" data-tab="conexion" role="tab" aria-selected="false">
                    <i class="fa-solid fa-wifi"></i> Conexión
                </button>
            </div>
            <div class="modal-tabpanel" data-panel="general" role="tabpanel">${general}</div>
            <div class="modal-tabpanel" data-panel="conexion" role="tabpanel" hidden>
                <div class="modal-tabpanel-body" data-role="conexion-body">
                    <div style="align-self:center;padding:24px"><div class="spin"></div></div>
                </div>
            </div>
        `;

        const m = openModal(`Consultar dispositivo <span class="muted">#${d.id}</span>`, body, {
            wide:        true,
            footerHtml:  '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
            primaryHtml: '<button class="btn btn-primary" data-act="editar"><i class="fa-solid fa-pen-to-square"></i> Editar</button>',
        });

        montarPestanasDispositivo(m.backdrop, d.id);

        m.backdrop.querySelector('[data-act="editar"]').addEventListener('click', () => {
            m.close();
            formDispositivo(d.id);
        });
        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            openRowMenu([
                d.uuid ? { label: 'Copiar identificador', icon: 'fa-copy', onSelect: () => copiar(d.uuid) } : null,
                d.mac  ? { label: 'Copiar MAC',           icon: 'fa-copy', onSelect: () => copiar(d.mac) }  : null,
                d.coordenadas ? { label: 'Copiar coordenadas', icon: 'fa-location-dot', onSelect: () => copiar(d.coordenadas) } : null,
                { sep: true },
                {
                    label: d.habilitado ? 'Deshabilitar' : 'Habilitar',
                    icon:  d.habilitado ? 'fa-plug-circle-xmark' : 'fa-plug-circle-check',
                    onSelect: () => { m.close(); toggleDispositivo(d); },
                },
            ], e.currentTarget);
        });
    }

    /* ---------- Consultar: pestañas ---------- */
    function montarPestanasDispositivo(backdrop, id) {
        const tabs   = backdrop.querySelectorAll('.modal-tab');
        const panels = backdrop.querySelectorAll('.modal-tabpanel');
        let conexionPedida = false;

        tabs.forEach((tab) => tab.addEventListener('click', () => {
            const destino = tab.dataset.tab;
            tabs.forEach((x) => {
                const activa = x === tab;
                x.classList.toggle('active', activa);
                x.setAttribute('aria-selected', activa ? 'true' : 'false');
            });
            panels.forEach((p) => { p.hidden = p.dataset.panel !== destino; });

            if (destino === 'conexion' && !conexionPedida) {
                conexionPedida = true;
                cargarConexionDispositivo(id, backdrop.querySelector('[data-role="conexion-body"]'))
                    .catch(() => { conexionPedida = false; });   // permite reintentar
            }
        }));
    }

    /* ---------- Consultar: pestaña Conexión ----------
     * Bandas de calidad de la escala del producto (-90 dBm = 0%,
     * -10 dBm = 100%): 50% cae justo en -50 dBm y 25% en -70 dBm, los dos
     * cortes clásicos de señal WiFi. El array va de mejor a peor porque
     * `bandaSenal` toma la primera que el valor alcanza. */
    const BANDAS_SENAL = [
        { clave: 'buena',   etiqueta: 'Buena',   desde: 50, dbm: '-50 dBm o mejor',      icono: 'fa-wifi' },
        { clave: 'regular', etiqueta: 'Regular', desde: 25, dbm: 'entre -51 y -70 dBm',  icono: 'fa-wifi-fair' },
        { clave: 'debil',   etiqueta: 'Débil',   desde: 0,  dbm: '-71 dBm o peor',       icono: 'fa-wifi-weak' },
    ];

    function bandaSenal(porcentaje) {
        return BANDAS_SENAL.find((b) => porcentaje >= b.desde) || BANDAS_SENAL[BANDAS_SENAL.length - 1];
    }

    /* '-65 dBm · 31% · Regular' con el ícono de su banda delante. `nivel` es
       el par {dbm, porcentaje} que arma el endpoint: la conversión dBm -> %
       vive allá y acá no se recalcula. */
    function nivelSenalHtml(nivel) {
        if (!nivel) return '';
        const banda = bandaSenal(nivel.porcentaje);
        return `<i class="fa-solid ${banda.icono} senal-icon-${banda.clave}"></i>
                ${nivel.dbm} dBm <span class="muted">· ${nivel.porcentaje}% · ${banda.etiqueta}</span>`;
    }

    async function cargarConexionDispositivo(id, contenedor) {
        if (!contenedor) return;
        let data;
        try {
            data = await api(`api/dispositivo_conexion.php?id=${id}`);
        } catch (err) {
            contenedor.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            throw err;
        }
        contenedor.innerHTML = vistaConexion(data);
    }

    function vistaConexion(data) {
        const escala   = data.escala || { maximo: -10, minimo: -90 };
        const muestras = data.muestras || [];
        const r        = data.resumen  || {};
        const actual   = data.dispositivo?.senal;

        const nota = `<p class="modal-note">
            Nivel de señal que el equipo informó en sus señales entrantes, de la
            medición más reciente a la más vieja. La escala va de
            ${escala.minimo} dBm (0%) a ${escala.maximo} dBm (100%).
        </p>`;

        if (muestras.length === 0) {
            return `${nota}
                <div class="alert alert-info">
                    Este dispositivo no informó su nivel de señal en las últimas
                    ${formatNumero(data.ventana) || data.ventana} señales registradas.
                </div>`;
        }

        const periodo = [formatDate(r.desde), formatDate(r.hasta)].filter(Boolean).join(' → ');
        const resumen = `<div class="view-grid">${[
            viewCard('Nivel actual', nivelSenalHtml(actual)),
            viewCard('Promedio',     nivelSenalHtml(r.promedio)),
            viewCard('Mejor',        nivelSenalHtml(r.mejor)),
            viewCard('Peor',         nivelSenalHtml(r.peor)),
            viewCard('Período medido',
                `${r.muestras} ${r.muestras === 1 ? 'medición' : 'mediciones'}${periodo ? ` · ${escapeHtml(periodo)}` : ''}`,
                true),
        ].join('')}</div>`;

        return `${nota}${resumen}
            <div class="senal-chart">
                <div class="senal-rows">${muestras.map(filaSenal).join('')}</div>
                <div class="senal-axis">
                    <div class="senal-axis-scale">${ejeSenal(escala)}</div>
                </div>
                <div class="senal-legend">${BANDAS_SENAL.map((b) => `
                    <span class="senal-legend-item">
                        <span class="senal-legend-dot senal-bar-${b.clave}"></span>${b.etiqueta}
                        <span class="muted">(${b.dbm})</span>
                    </span>`).join('')}
                </div>
            </div>`;
    }

    /* Rótulos del eje: uno por cada guía del track (0/25/50/75/100% de la
       escala). Se derivan de `escala` para que sigan diciendo la verdad si
       alguna vez cambian los extremos. */
    function ejeSenal(escala) {
        return [0, 25, 50, 75, 100].map((p) => {
            const dbm = Math.round(escala.minimo + ((escala.maximo - escala.minimo) * p) / 100);
            return `<span style="left:${p}%">${dbm}${p === 100 ? ' dBm' : ''}</span>`;
        }).join('');
    }

    function filaSenal(m) {
        const banda   = bandaSenal(m.porcentaje);
        const fecha   = formatDate(m.fecha) || '';
        // La fecha de la fila va corta (sin año): las mediciones son
        // recientes y el período completo ya está en el resumen.
        const corta   = fecha.replace(/^(\d{2}\/\d{2})\/\d{4}/, '$1');
        const reporte = m.reporte ? ` · reporte ${m.reporte}` : '';
        const titulo  = `${fecha} — ${m.dbm} dBm (${m.porcentaje}%, ${banda.etiqueta})${reporte} · señal #${m.id}`;

        return `
            <div class="senal-row" title="${escapeHtml(titulo)}">
                <span class="senal-row-fecha">${escapeHtml(corta)}</span>
                <span class="senal-track">
                    <span class="senal-bar senal-bar-${banda.clave}" style="width:${m.porcentaje}%"></span>
                </span>
                <span class="senal-row-valor">
                    <i class="fa-solid ${banda.icono} senal-icon-${banda.clave}"></i>${m.dbm} dBm
                </span>
            </div>`;
    }

    /* ---------- Alta / Edición ----------
     * Los campos de telemetria (enlace, ip, senal, firmware, contadores,
     * fechas de conexion/latido, adopcion y monitoreoUltimo/Siguiente) los
     * escribe el equipo: se consultan pero no se editan desde el panel.
     * El alta y la edicion NO comparten formulario: el alta pide la ficha
     * completa y la edicion expone solo `nombre` (ver `bodyEdicion`). */
    async function formDispositivo(id) {
        const esEdicion = id != null;
        let d = {
            uuid: '', nombre: '', agente: null, modelo: null, producto: null,
            transceptor: null, chip: null, mac: '', serial: '', identidad: '',
            llave: '', senalesLimite: null, fabricacion: '', instalacion: '',
            monitoreo: false, monitoreoIntervalo: null, monitoreoCorreos: '',
            coordenadas: '', indicadores: '', habilitado: true,
        };

        if (esEdicion) {
            try {
                d = (await api(`api/dispositivos.php?id=${id}`)).dispositivo;
            } catch (err) {
                toast(err.message, { error: true });
                return;
            }
        }

        const opciones = (lista, seleccionado, vacio) =>
            [`<option value="">${vacio}</option>`].concat(
                (lista || []).map((o) =>
                    `<option value="${o.id}"${o.id === seleccionado ? ' selected' : ''}>${escapeHtml(o.nombre || `#${o.id}`)}</option>`)
            ).join('');

        const c       = dispositivos.catalogos;
        const dominio = sesion.dominio_nombre || (sesion.dominio ? `#${sesion.dominio}` : '—');
        const num     = (v) => (v === null || v === undefined ? '' : String(v));

        /* En edicion la UI expone UN SOLO campo, `nombre`: el resto de la ficha
         * (identificador de fabrica, catalogos, fechas, monitoreo) lo
         * administra Reactor y no el cliente, y `habilitado` se cambia desde
         * Habilitar / Deshabilitar del menu contextual del listado. El alta
         * sigue pidiendo el formulario completo, que es donde se cargan
         * esos datos. */
        const bodyEdicion = `
            <div class="form-group">
                <label for="df-nombre">Nombre *</label>
                <input type="text" id="df-nombre" maxlength="255" value="${escapeHtml(d.nombre)}">
            </div>
            <div class="field-error" id="df-error" style="display:none"></div>
        `;

        const bodyAlta = `
            <div class="form-row">
                <div class="form-group">
                    <label for="df-uuid">Identificador *</label>
                    <input type="text" id="df-uuid" maxlength="16" value="${escapeHtml(d.uuid)}"
                           placeholder="UUID de fábrica">
                </div>
                <div class="form-group">
                    <label for="df-nombre">Nombre *</label>
                    <input type="text" id="df-nombre" maxlength="255" value="${escapeHtml(d.nombre)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-modelo">Modelo</label>
                    <select id="df-modelo">${opciones(c.modelos, d.modelo, '— Sin modelo —')}</select>
                </div>
                <div class="form-group">
                    <label for="df-producto">Producto</label>
                    <select id="df-producto">${opciones(c.productos, d.producto, '— Sin producto —')}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-agente">Agente</label>
                    <select id="df-agente">${opciones(c.agentes, d.agente, '— Sin agente —')}</select>
                </div>
                <div class="form-group">
                    <label for="df-transceptor">Transceptor</label>
                    <select id="df-transceptor">${opciones(c.transceptores, d.transceptor, '— Sin transceptor —')}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-chip">Chip</label>
                    <select id="df-chip">${opciones(c.chips, d.chip, '— Sin chip —')}</select>
                </div>
                <div class="form-group">
                    <label>Dominio</label>
                    <input type="text" value="${escapeHtml(dominio)}" readonly title="Se asigna desde tu sesión">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-mac">MAC</label>
                    <input type="text" id="df-mac" maxlength="50" value="${escapeHtml(d.mac)}">
                </div>
                <div class="form-group">
                    <label for="df-serial">Serie</label>
                    <input type="text" id="df-serial" maxlength="50" value="${escapeHtml(d.serial)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-identidad">Identidad</label>
                    <input type="text" id="df-identidad" maxlength="50" value="${escapeHtml(d.identidad)}">
                </div>
                <div class="form-group">
                    <label for="df-llave">Llave</label>
                    <input type="text" id="df-llave" maxlength="50" value="${escapeHtml(d.llave)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-fabricacion">Fabricación</label>
                    <input type="datetime-local" id="df-fabricacion" value="${toInputDateTime(d.fabricacion)}">
                </div>
                <div class="form-group">
                    <label for="df-instalacion">Instalación</label>
                    <input type="datetime-local" id="df-instalacion" value="${toInputDateTime(d.instalacion)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="df-senaleslimite">Límite de señales</label>
                    <input type="number" min="0" id="df-senaleslimite" value="${escapeHtml(num(d.senalesLimite))}">
                </div>
                <div class="form-group">
                    <label for="df-monitoreointervalo">Intervalo de monitoreo (min)</label>
                    <input type="number" min="0" id="df-monitoreointervalo" value="${escapeHtml(num(d.monitoreoIntervalo))}">
                </div>
            </div>
            <div class="form-group">
                <label for="df-monitoreocorreos">Correos de monitoreo</label>
                <input type="text" id="df-monitoreocorreos" maxlength="1000" value="${escapeHtml(d.monitoreoCorreos)}"
                       placeholder="Separados por coma">
            </div>
            <div class="form-group">
                <label for="df-coordenadas">Coordenadas</label>
                <input type="text" id="df-coordenadas" maxlength="255" value="${escapeHtml(d.coordenadas)}"
                       placeholder="latitud, longitud">
            </div>
            <div class="form-group">
                <label for="df-indicadores">Indicadores</label>
                <input type="text" id="df-indicadores" maxlength="1000" value="${escapeHtml(d.indicadores)}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="df-habilitado" ${d.habilitado ? 'checked' : ''}>
                        Dispositivo habilitado
                    </label>
                </div>
                <div class="form-group">
                    <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                        <input type="checkbox" id="df-monitoreo" ${d.monitoreo ? 'checked' : ''}>
                        Monitoreo activo
                    </label>
                </div>
            </div>
            <div class="field-error" id="df-error" style="display:none"></div>
        `;

        const m = openModal(
            esEdicion ? `Editar dispositivo <span class="muted">#${d.id}</span>` : 'Nuevo dispositivo',
            esEdicion ? bodyEdicion : bodyAlta,
            {
                wide:        !esEdicion,
                closeLabel:  'Cancelar',
                primaryHtml: `<button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar' : 'Crear dispositivo'}</button>`,
            }
        );

        const err = m.backdrop.querySelector('#df-error');
        const btn = m.backdrop.querySelector('[data-act="guardar"]');
        const val = (sel) => m.backdrop.querySelector(sel).value.trim();

        btn.addEventListener('click', async () => {
            /* El PUT reescribe la fila entera y en edicion el form ya no tiene
               los demas campos: se parte del registro tal como vino del GET y
               se pisa nada mas `nombre`, igual que el toggle del listado hace
               con `habilitado`. */
            const payload = esEdicion ? {
                ...payloadDispositivo(d),
                id:     d.id,
                nombre: val('#df-nombre'),
            } : {
                uuid:               val('#df-uuid'),
                nombre:             val('#df-nombre'),
                modelo:             +(val('#df-modelo')      || 0),
                producto:           +(val('#df-producto')    || 0),
                agente:             +(val('#df-agente')      || 0),
                transceptor:        +(val('#df-transceptor') || 0),
                chip:               +(val('#df-chip')        || 0),
                mac:                val('#df-mac'),
                serial:             val('#df-serial'),
                identidad:          val('#df-identidad'),
                llave:              val('#df-llave'),
                fabricacion:        val('#df-fabricacion'),
                instalacion:        val('#df-instalacion'),
                senalesLimite:      val('#df-senaleslimite'),
                monitoreoIntervalo: val('#df-monitoreointervalo'),
                monitoreoCorreos:   val('#df-monitoreocorreos'),
                coordenadas:        val('#df-coordenadas'),
                indicadores:        val('#df-indicadores'),
                habilitado:         m.backdrop.querySelector('#df-habilitado').checked,
                monitoreo:          m.backdrop.querySelector('#df-monitoreo').checked,
            };

            btn.disabled = true;
            try {
                await api('api/dispositivos.php', {
                    method:  esEdicion ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                m.close();
                toast(esEdicion ? 'Dispositivo actualizado' : 'Dispositivo creado');
                cargarDispositivos();
            } catch (e2) {
                err.textContent   = e2.message;
                err.style.display = '';
                btn.disabled = false;
            }
        });
    }

    /* El PUT reescribe la fila entera, asi que el toggle relee el registro
       completo y solo invierte `habilitado`. */
    function payloadDispositivo(d) {
        const num = (v) => (v === null || v === undefined ? '' : String(v));
        return {
            uuid:               d.uuid,
            nombre:             d.nombre,
            agente:             d.agente      || 0,
            modelo:             d.modelo      || 0,
            producto:           d.producto    || 0,
            transceptor:        d.transceptor || 0,
            chip:               d.chip        || 0,
            mac:                d.mac       || '',
            serial:             d.serial    || '',
            identidad:          d.identidad || '',
            llave:              d.llave     || '',
            fabricacion:        d.fabricacion || '',
            instalacion:        d.instalacion || '',
            senalesLimite:      num(d.senalesLimite),
            monitoreoIntervalo: num(d.monitoreoIntervalo),
            monitoreoCorreos:   d.monitoreoCorreos || '',
            coordenadas:        d.coordenadas || '',
            indicadores:        d.indicadores || '',
            habilitado:         !!d.habilitado,
            monitoreo:          !!d.monitoreo,
        };
    }

    async function toggleDispositivo(d) {
        try {
            const full = (await api(`api/dispositivos.php?id=${d.id}`)).dispositivo;
            await api('api/dispositivos.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...payloadDispositivo(full),
                    id:         full.id,
                    habilitado: !full.habilitado,
                }),
            });
            toast(full.habilitado ? 'Dispositivo deshabilitado' : 'Dispositivo habilitado');
            cargarDispositivos();
        } catch (err) {
            toast(err.message, { error: true });
        }
    }

    /* Liberar reemplaza a la baja: el dispositivo no se borra, se desvincula
       del dominio (vuelve al pool `Liberado`) para que otra cuenta pueda
       adoptarlo. Sale del listado porque deja de pertenecer al dominio de la
       sesion, no porque haya dejado de existir. */
    function liberarDispositivo(d) {
        confirmarBaja(
            `¿Liberar el dispositivo <strong>${escapeHtml(d.uuid || `#${d.id}`)}</strong>${d.nombre ? ` (${escapeHtml(d.nombre)})` : ''}?`
            + ' Se desvincula de esta cuenta y queda disponible para que otra lo adopte.'
            + ' Debes adoptarlo nuevamente para volverlo a ver en tu lista de dispositivos.',
            async () => {
                try {
                    await api(`api/dispositivos.php?accion=liberar&id=${d.id}`, { method: 'POST' });
                    toast('Dispositivo liberado');
                    cargarDispositivos();
                } catch (err) {
                    toast(err.message, { error: true });
                }
            },
            { label: 'Liberar' }
        );
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ----
     * Los cambios se aplican EN VIVO sobre el listado de fondo; "Aplicar"
     * solo cierra. "Cerrar" revierte al snapshot tomado al abrir. */
    function abrirFiltrosDispositivos() {
        const snapshot = { ...dispositivos };
        let aplicado   = false;

        const modeloOpts = ['<option value="0">Todos</option>'].concat(
            (dispositivos.catalogos.modelos || []).map((o) =>
                `<option value="${o.id}"${o.id === dispositivos.modelo ? ' selected' : ''}>${escapeHtml(o.nombre)}</option>`)
        ).join('');

        const chip = (grupo, val, label) =>
            `<button type="button" class="filter-chip${dispositivos[grupo] === val ? ' active' : ''}" data-valor="${val}">${label}</button>`;

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="df-f-codigo">Código</label>
                    <input type="number" min="1" id="df-f-codigo" placeholder="ID del dispositivo" value="${escapeHtml(dispositivos.codigo)}">
                </div>
                <div class="form-group">
                    <label for="df-f-modelo">Modelo</label>
                    <select id="df-f-modelo">${modeloOpts}</select>
                </div>
            </div>
            <div class="form-group">
                <label>Enlace</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="df-f-enlace">
                    ${chip('enlace', 'todos', 'Todos')}
                    ${chip('enlace', 'online', 'En línea')}
                    ${chip('enlace', 'offline', 'Fuera de línea')}
                </div>
            </div>
            <div class="form-group">
                <label>Estado del registro</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="df-f-estado">
                    ${chip('estado', 'todos', 'Todos')}
                    ${chip('estado', 'habilitados', 'Habilitados')}
                    ${chip('estado', 'deshabilitados', 'Deshabilitados')}
                </div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="df-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="df-f-limite" value="${dispositivos.limite}">
                </div>
                <div class="form-group">
                    <label for="df-f-orden">Ordenar por</label>
                    <select id="df-f-orden">
                        <option value="id">Código</option>
                        <option value="uuid">Identificador</option>
                        <option value="nombre">Nombre</option>
                        <option value="latido">Último latido</option>
                        <option value="conexion">Última conexión</option>
                        <option value="instalacion">Instalación</option>
                        <option value="fabricacion">Fabricación</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="df-f-dir">Dirección</label>
                    <select id="df-f-dir">
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
                Object.assign(dispositivos, snapshot);
                cargarDispositivos();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#df-f-orden').value = dispositivos.orden;
        $('#df-f-dir').value   = dispositivos.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosDispositivos(); cargarDispositivos(); };

        $('#df-f-codigo').addEventListener('input',  (e) => { dispositivos.codigo = e.target.value.trim(); aplicarEnVivo(); });
        $('#df-f-modelo').addEventListener('change', (e) => { dispositivos.modelo = +e.target.value || 0;  aplicarEnVivo(); });
        $('#df-f-limite').addEventListener('change', (e) => { dispositivos.limite = +e.target.value || 100; aplicarEnVivo(); });
        $('#df-f-orden').addEventListener('change',  (e) => { dispositivos.orden  = e.target.value; aplicarEnVivo(); });
        $('#df-f-dir').addEventListener('change',    (e) => { dispositivos.dir    = e.target.value; aplicarEnVivo(); });

        [['#df-f-enlace', 'enlace'], ['#df-f-estado', 'estado']].forEach(([sel, campo]) => {
            $(sel).addEventListener('click', (e) => {
                const b = e.target.closest('[data-valor]');
                if (!b) return;
                dispositivos[campo] = b.dataset.valor;
                m.backdrop.querySelectorAll(`${sel} .filter-chip`)
                    .forEach((x) => x.classList.toggle('active', x === b));
                aplicarEnVivo();
            });
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            Object.assign(dispositivos, DISPOSITIVOS_DEFAULTS);
            $('#df-f-codigo').value = '';
            $('#df-f-modelo').value = '0';
            $('#df-f-limite').value = DISPOSITIVOS_DEFAULTS.limite;
            $('#df-f-orden').value  = DISPOSITIVOS_DEFAULTS.orden;
            $('#df-f-dir').value    = DISPOSITIVOS_DEFAULTS.dir;
            [['#df-f-enlace', 'enlace'], ['#df-f-estado', 'estado']].forEach(([sel, campo]) => {
                m.backdrop.querySelectorAll(`${sel} .filter-chip`)
                    .forEach((x) => x.classList.toggle('active', x.dataset.valor === DISPOSITIVOS_DEFAULTS[campo]));
            });
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* =========================================================
     * Modulo: Actividad  (solo lectura)
     * Bitacora de `registros`: que hizo cada usuario del dominio, sobre
     * que dispositivo y canal, y con que resultado. La escriben el motor
     * y las apps -- el panel NUNCA la modifica. Por eso el modulo sigue
     * las convenciones de abm_design salvo las partes de escritura: no
     * hay boton "+ Nuevo", ni Editar, ni Eliminar (api/actividad.php solo
     * responde GET). El filtro por dominio lo aplica el backend
     * (requireDominioId()); aca solo se muestra de que dominio se trata.
     * Portado de reactor_legacy/reactor-app/dominio/actividad.php.
     * ======================================================= */

    /* `ventana` = cuantos ids hacia atras mira la consulta. `registros`
       tiene ~3M filas y ningun indice (dominio, id): sin ventana, una
       busqueda sin resultados barre la tabla entera (14 s medidos). Los
       valores validos los replica api/actividad.php (VENTANAS). */
    const ACTIVIDAD_VENTANAS = [
        { valor: 200000,  label: 'Últimos 200.000 registros' },
        { valor: 1000000, label: 'Último 1.000.000 de registros' },
        { valor: 0,       label: 'Todo el historial (lento)' },
    ];

    const ACTIVIDAD_DEFAULTS = {
        codigo: '', usuario: 0, dispositivo: 0, sentido: 'S',
        desde: '', hasta: '', ventana: 200000, limite: 100, orden: 'id', dir: 'desc',
    };

    const actividad = {
        q: '',
        ...ACTIVIDAD_DEFAULTS,
        filas: [],
        catalogos: { usuarios: [], dispositivos: [] },
        resumen: null,
    };

    function actividadFiltrosActivos() {
        return Object.keys(ACTIVIDAD_DEFAULTS)
            .filter((k) => String(actividad[k]) !== String(ACTIVIDAD_DEFAULTS[k]))
            .length;
    }

    // Mapeo del legacy: '0' apagado, '1' encendido y cualquier otro valor
    // se muestra tal cual (los canales de nivel/dimmer mandan texto).
    function actividadEstado(valor) {
        const v = String(valor ?? '').trim();
        if (v === '')  return DASH;
        if (v === '0') return '<span class="badge badge-danger"><i class="fa-solid fa-toggle-off"></i> Apagado</span>';
        if (v === '1') return '<span class="badge badge-success"><i class="fa-solid fa-toggle-on"></i> Encendido</span>';
        return `<span class="badge badge-warn"><i class="fa-solid fa-volume-low"></i> ${escapeHtml(v)}</span>`;
    }

    // 'S' = salida (lo que el usuario le mando al equipo), 'E' = entrada
    // (lo que el equipo reporto).
    function actividadSentido(valor) {
        if (valor === 'S') return '<span class="badge badge-info">Enviado</span>';
        if (valor === 'E') return '<span class="badge badge-warn">Recibido</span>';
        return DASH;
    }

    // Igual que formatDate() pero conservando los segundos: en una bitacora
    // el segundo exacto importa.
    function actividadFechaLarga(valor) {
        const s = String(valor ?? '').trim();
        if (s === '' || s.startsWith('0000-00-00')) return '';
        const m = s.match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}):(\d{2})(?::(\d{2}))?)?/);
        if (!m) return s;
        if (!m[4]) return `${m[3]}/${m[2]}/${m[1]}`;
        return `${m[3]}/${m[2]}/${m[1]} ${m[4]}:${m[5]}:${m[6] || '00'}`;
    }

    function renderActividad(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-clipboard-list"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        La actividad es el historial de acciones que los usuarios ejecutaron sobre los
                        dispositivos del sistema, con la fecha, el canal involucrado y el estado resultante.
                        Se listan únicamente las del dominio <strong>${dominio}</strong> y es un registro
                        de solo lectura: no se puede editar ni eliminar.
                    </div>
                </div>

                <div class="stats-bar" id="ac-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="ac-quick" class="search-input"
                                   placeholder="Buscar usuario, dispositivo, canal o estado…">
                            <button type="button" class="search-clear" id="ac-quick-clear"
                                    style="display:none" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="ac-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="ac-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="ac-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Fecha</th>
                                <th>Usuario</th>
                                <th>Dispositivo</th>
                                <th>Canal</th>
                                <th>Sentido</th>
                                <th>Estado</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="ac-tbody">
                            <tr><td colspan="8" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>

                <p id="ac-ventana-nota" style="font-size:.78rem;color:var(--muted);margin-top:10px"></p>
            </div>
        `;

        const quick = container.querySelector('#ac-quick');
        const clear = container.querySelector('#ac-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { actividad.q = quick.value.trim(); cargarActividad(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            actividad.q = ''; cargarActividad();
        });

        container.querySelector('#ac-filtros').addEventListener('click', abrirFiltrosActividad);
        container.querySelector('#ac-refrescar').addEventListener('click', () => cargarActividad());

        cargarActividad();
    }

    async function cargarActividad() {
        const tbody = document.getElementById('ac-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">Cargando…</td></tr>';
        pintarNotaVentanaActividad();

        const qs = new URLSearchParams({
            q:           actividad.q,
            codigo:      actividad.codigo || '',
            usuario:     actividad.usuario     || '',
            dispositivo: actividad.dispositivo || '',
            sentido:     actividad.sentido,
            desde:       actividad.desde,
            hasta:       actividad.hasta,
            ventana:     actividad.ventana,
            limite:      actividad.limite,
            orden:       actividad.orden,
            dir:         actividad.dir,
        });

        try {
            const data = await api(`api/actividad.php?${qs}`);
            actividad.filas     = data.actividad || [];
            actividad.catalogos = data.catalogos || { usuarios: [], dispositivos: [] };
            actividad.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsActividad();
        pintarBadgeFiltrosActividad();

        if (actividad.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">No hay actividad que coincida con la búsqueda.</td></tr>';
            return;
        }

        tbody.innerHTML = actividad.filas.map(filaActividad).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const r  = actividad.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verActividad(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuActividad(r), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuActividad(r), e.currentTarget);
            });
        });
    }

    function pintarStatsActividad() {
        const el = document.getElementById('ac-stats');
        const r  = actividad.resumen;
        if (!el || !r) return;
        el.innerHTML = `
            <div class="stat-card"><span class="stat-label">Total del dominio</span><span class="stat-value">${r.total}</span></div>
            <div class="stat-card"><span class="stat-label">Últimas 24 h</span><span class="stat-value green">${r.ultimas_24h}</span></div>
            <div class="stat-card"><span class="stat-label">Hoy</span><span class="stat-value">${r.hoy}</span></div>
            <div class="stat-card"><span class="stat-label">Mostrados</span><span class="stat-value muted">${r.mostrados}</span></div>
        `;
    }

    /* Deja a la vista que el listado corre dentro de una ventana de ids:
       sin esta linea, "no aparece la actividad vieja" parece un bug. */
    function pintarNotaVentanaActividad() {
        const el = document.getElementById('ac-ventana-nota');
        if (!el) return;
        if (actividad.codigo) {
            el.textContent = 'Búsqueda por código: alcanza a todo el historial.';
        } else if (actividad.ventana > 0) {
            el.textContent = `Se busca dentro de los ${actividad.ventana.toLocaleString('es-AR')} registros más `
                           + 'recientes del sistema. Para llegar al historial viejo, ampliá la ventana desde Filtros.';
        } else {
            el.textContent = 'Se busca en todo el historial: la consulta puede tardar varios segundos.';
        }
    }

    function pintarBadgeFiltrosActividad() {
        const btn   = document.getElementById('ac-filtros');
        const badge = document.getElementById('ac-filtros-badge');
        if (!btn || !badge) return;
        const n = actividadFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    function filaActividad(r) {
        const usuario = r.usuario_nombre || r.usuario_login
            ? `<div class="td-nombre">${escapeHtml(r.usuario_nombre || r.usuario_login)}</div>
               ${r.usuario_nombre && r.usuario_login ? `<div class="td-id">${escapeHtml(r.usuario_login)}</div>` : ''}`
            : DASH;

        const dispositivo = r.dispositivo_nombre || r.dispositivo_uuid
            ? `<div class="td-nombre">${escapeHtml(r.dispositivo_nombre || r.dispositivo_uuid)}</div>
               ${r.dispositivo_nombre && r.dispositivo_uuid ? `<div class="td-id">${escapeHtml(r.dispositivo_uuid)}</div>` : ''}`
            : DASH;

        const canal = r.canal_nombre
            ? escapeHtml(r.canal_nombre)
            : (r.canal_numero != null ? `<span class="td-id">#${r.canal_numero}</span>` : DASH);

        return `
            <tr data-id="${r.id}" class="row-clickable">
                <td class="td-id">#${r.id}</td>
                <td>${escapeHtml(formatDate(r.fecha) || '') || DASH}</td>
                <td>${usuario}</td>
                <td>${dispositivo}</td>
                <td>${canal}</td>
                <td>${actividadSentido(r.sentido)}</td>
                <td>${actividadEstado(r.estado)}</td>
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

    // Menu contextual de fila. Sin Editar ni Eliminar: la bitacora es de
    // solo lectura. Las acciones propias del recurso son atajos para
    // acotar el listado al usuario o al dispositivo de la fila.
    function menuActividad(r) {
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verActividad(r.id) },
            r.usuario ? {
                label: 'Filtrar por este usuario',
                icon:  'fa-user',
                onSelect: () => { actividad.usuario = r.usuario; pintarBadgeFiltrosActividad(); cargarActividad(); },
            } : null,
            r.dispositivo ? {
                label: 'Filtrar por este dispositivo',
                icon:  'fa-microchip',
                onSelect: () => { actividad.dispositivo = r.dispositivo; pintarBadgeFiltrosActividad(); cargarActividad(); },
            } : null,
        ].filter(Boolean);
    }

    /* ---------- Consultar ---------- */
    async function verActividad(id) {
        let r;
        try {
            r = (await api(`api/actividad.php?id=${id}`)).registro;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const body = `<div class="view-grid">${[
            viewCard('Código',              `<code>#${r.id}</code>`),
            viewCard('Fecha',               escapeHtml(actividadFechaLarga(r.fecha))),
            viewCard('Usuario',             r.usuario_nombre ? escapeHtml(r.usuario_nombre) : (r.usuario ? `<code>#${r.usuario}</code>` : '')),
            viewCard('Cuenta',              r.usuario_login ? escapeHtml(r.usuario_login) : ''),
            viewCard('Correo',              r.usuario_correo ? escapeHtml(r.usuario_correo) : '', true),
            viewCard('Dispositivo',         r.dispositivo_nombre ? escapeHtml(r.dispositivo_nombre) : (r.dispositivo ? `<code>#${r.dispositivo}</code>` : '')),
            viewCard('UUID del dispositivo', r.dispositivo_uuid ? `<code>${escapeHtml(r.dispositivo_uuid)}</code>` : ''),
            viewCard('Canal',               r.canal_nombre ? escapeHtml(r.canal_nombre) : (r.canal ? `<code>#${r.canal}</code>` : '')),
            viewCard('Número de canal',     r.canal_numero != null ? `<code>${r.canal_numero}</code>` : ''),
            viewCard('Sentido',             actividadSentido(r.sentido)),
            viewCard('Estado',              actividadEstado(r.estado)),
            viewCard('Dominio',             r.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(r.dominio_nombre)}</span>` : ''),
        ].join('')}</div>`;

        // Footer sin "Editar": no hay modal de edicion para este recurso.
        const m = openModal(`Consultar actividad <span class="muted">#${r.id}</span>`, body, {
            footerHtml: '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
        });

        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            const atajos = [
                r.usuario ? {
                    label: 'Filtrar por este usuario',
                    icon:  'fa-user',
                    onSelect: () => { m.close(); actividad.usuario = r.usuario; pintarBadgeFiltrosActividad(); cargarActividad(); },
                } : null,
                r.dispositivo ? {
                    label: 'Filtrar por este dispositivo',
                    icon:  'fa-microchip',
                    onSelect: () => { m.close(); actividad.dispositivo = r.dispositivo; pintarBadgeFiltrosActividad(); cargarActividad(); },
                } : null,
            ].filter(Boolean);

            const detalle = `#${r.id} ${actividadFechaLarga(r.fecha)}`
                + ` · ${r.usuario_nombre || r.usuario_login || '—'}`
                + ` · ${r.dispositivo_nombre || r.dispositivo_uuid || '—'}`
                + ` · ${r.canal_nombre || (r.canal ? '#' + r.canal : '—')}`
                + ` · ${r.estado || '—'}`;

            openRowMenu([
                ...atajos,
                ...(atajos.length ? [{ sep: true }] : []),
                { label: 'Copiar detalle', icon: 'fa-copy', onSelect: () => copiar(detalle) },
            ], e.currentTarget);
        });
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ---- */
    function abrirFiltrosActividad() {
        const snapshot = { ...actividad };
        let aplicado   = false;

        const opciones = (lista, seleccionado) => ['<option value="0">Todos</option>'].concat(
            (lista || []).map((x) =>
                `<option value="${x.id}"${x.id === seleccionado ? ' selected' : ''}>${escapeHtml(x.nombre)}</option>`)
        ).join('');

        const chip = (val, label) =>
            `<button type="button" class="filter-chip${actividad.sentido === val ? ' active' : ''}" data-valor="${val}">${label}</button>`;

        const ventanaOpts = ACTIVIDAD_VENTANAS.map((v) =>
            `<option value="${v.valor}"${v.valor === actividad.ventana ? ' selected' : ''}>${escapeHtml(v.label)}</option>`
        ).join('');

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="af-f-codigo">Código</label>
                    <input type="number" min="1" id="af-f-codigo" placeholder="ID del registro" value="${escapeHtml(actividad.codigo)}">
                </div>
                <div class="form-group">
                    <label for="af-f-usuario">Usuario</label>
                    <select id="af-f-usuario">${opciones(actividad.catalogos.usuarios, actividad.usuario)}</select>
                </div>
            </div>
            <div class="filters-grid">
                <div class="form-group">
                    <label for="af-f-dispositivo">Dispositivo</label>
                    <select id="af-f-dispositivo">${opciones(actividad.catalogos.dispositivos, actividad.dispositivo)}</select>
                </div>
                <div class="form-group">
                    <label for="af-f-ventana">Ventana de búsqueda</label>
                    <select id="af-f-ventana">${ventanaOpts}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="af-f-desde">Desde</label>
                    <input type="date" id="af-f-desde" value="${escapeHtml(actividad.desde)}">
                </div>
                <div class="form-group">
                    <label for="af-f-hasta">Hasta</label>
                    <input type="date" id="af-f-hasta" value="${escapeHtml(actividad.hasta)}">
                </div>
            </div>
            <div class="form-group">
                <label>Sentido</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="af-f-sentido">
                    ${chip('',  'Todos')}
                    ${chip('S', 'Enviados')}
                    ${chip('E', 'Recibidos')}
                </div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="af-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="af-f-limite" value="${actividad.limite}">
                </div>
                <div class="form-group">
                    <!-- Solo por PK: en registros el id es cronologico y
                         ordenar por fecha (sin indice) tarda 5,5 s. -->
                    <label for="af-f-orden">Ordenar por</label>
                    <select id="af-f-orden">
                        <option value="id">Código</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="af-f-dir">Dirección</label>
                    <select id="af-f-dir">
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
                Object.assign(actividad, snapshot);
                cargarActividad();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#af-f-orden').value = actividad.orden;
        $('#af-f-dir').value   = actividad.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosActividad(); cargarActividad(); };

        $('#af-f-codigo').addEventListener('input',       (e) => { actividad.codigo      = e.target.value.trim(); aplicarEnVivo(); });
        $('#af-f-usuario').addEventListener('change',     (e) => { actividad.usuario     = +e.target.value || 0;  aplicarEnVivo(); });
        $('#af-f-dispositivo').addEventListener('change', (e) => { actividad.dispositivo = +e.target.value || 0;  aplicarEnVivo(); });
        $('#af-f-desde').addEventListener('change',       (e) => { actividad.desde       = e.target.value;        aplicarEnVivo(); });
        $('#af-f-hasta').addEventListener('change',       (e) => { actividad.hasta       = e.target.value;        aplicarEnVivo(); });
        $('#af-f-ventana').addEventListener('change',     (e) => { actividad.ventana     = +e.target.value;      aplicarEnVivo(); });
        $('#af-f-limite').addEventListener('change',      (e) => { actividad.limite      = +e.target.value || 100; aplicarEnVivo(); });
        $('#af-f-orden').addEventListener('change',       (e) => { actividad.orden       = e.target.value;        aplicarEnVivo(); });
        $('#af-f-dir').addEventListener('change',         (e) => { actividad.dir         = e.target.value;        aplicarEnVivo(); });

        $('#af-f-sentido').addEventListener('click', (e) => {
            const b = e.target.closest('[data-valor]');
            if (!b) return;
            actividad.sentido = b.dataset.valor;
            m.backdrop.querySelectorAll('#af-f-sentido .filter-chip')
                .forEach((c) => c.classList.toggle('active', c === b));
            aplicarEnVivo();
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            Object.assign(actividad, ACTIVIDAD_DEFAULTS);
            $('#af-f-codigo').value      = '';
            $('#af-f-usuario').value     = '0';
            $('#af-f-dispositivo').value = '0';
            $('#af-f-desde').value       = '';
            $('#af-f-hasta').value       = '';
            $('#af-f-ventana').value     = ACTIVIDAD_DEFAULTS.ventana;
            $('#af-f-limite').value      = ACTIVIDAD_DEFAULTS.limite;
            $('#af-f-orden').value       = ACTIVIDAD_DEFAULTS.orden;
            $('#af-f-dir').value         = ACTIVIDAD_DEFAULTS.dir;
            m.backdrop.querySelectorAll('#af-f-sentido .filter-chip')
                .forEach((c) => c.classList.toggle('active', c.dataset.valor === ACTIVIDAD_DEFAULTS.sentido));
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* =========================================================
     * Modulo ABM: Chips  (convenciones de la skill abm_design)
     * Portado de reactor-panel/chips/listar.php: mismo recorte por dominio
     * y las mismas columnas del listado legacy (codigo, compania, numero,
     * estado), ampliadas con plan, titular y serie.
     * `compania`, `plan`, `responsable` y `pais` se guardan como codigos
     * cortos; el texto sale de la tabla `combos` — lo mismo que hacia
     * comboTraducir() en el legacy — y lo resuelve api/chips.php, que
     * ademas acota todo al dominio de la sesion (requireDominioId()).
     * ======================================================= */

    const CHIPS_DEFAULTS = {
        codigo: '', compania: '', plan: '', responsable: '',
        estado: 'todos', limite: 100, orden: 'id', dir: 'desc',
    };

    const chips = {
        q: '',
        ...CHIPS_DEFAULTS,
        filas: [],
        combos: { compania: [], plan: [], responsable: [], pais: [] },
        articulos: [],
        resumen: null,
    };

    function chipsFiltrosActivos() {
        let n = 0;
        if (String(chips.codigo) !== CHIPS_DEFAULTS.codigo)   n++;
        if (chips.compania    !== CHIPS_DEFAULTS.compania)    n++;
        if (chips.plan        !== CHIPS_DEFAULTS.plan)        n++;
        if (chips.responsable !== CHIPS_DEFAULTS.responsable) n++;
        if (chips.estado      !== CHIPS_DEFAULTS.estado)      n++;
        if (chips.limite      !== CHIPS_DEFAULTS.limite)      n++;
        if (chips.orden       !== CHIPS_DEFAULTS.orden)       n++;
        if (chips.dir         !== CHIPS_DEFAULTS.dir)         n++;
        return n;
    }

    /* <option>s de un combo del legacy: el value es el codigo corto que se
       guarda en la columna, el texto es la etiqueta que trae `combos`. */
    function opcionesCombo(lista, seleccionado, vacio) {
        return [`<option value="">${vacio}</option>`].concat(
            (lista || []).map((o) =>
                `<option value="${escapeHtml(o.valor)}"${o.valor === seleccionado ? ' selected' : ''}>${escapeHtml(o.texto)}</option>`)
        ).join('');
    }

    // Codigo suelto (ej. 'M') cuando `combos` no tiene la etiqueta cargada.
    function textoCombo(texto, codigo) {
        if (texto) return escapeHtml(texto);
        return codigo ? `<code>${escapeHtml(codigo)}</code>` : '';
    }

    function renderChips(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-sim-card"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        Los chips son las líneas SIM que le dan conectividad a los dispositivos:
                        cada uno tiene su número de teléfono, su serie (ICCID), la compañía que lo
                        provee y el plan contratado.
                        Se listan únicamente los del dominio <strong>${dominio}</strong>, que es el
                        asociado a tu cuenta.
                    </div>
                </div>

                <div class="stats-bar" id="ch-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="ch-quick" class="search-input"
                                   placeholder="Buscar teléfono, serie, titular o comentario…">
                            <button type="button" class="search-clear" id="ch-quick-clear"
                                    style="display:none" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="ch-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="ch-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="ch-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-primary" id="ch-nuevo">+ Nuevo chip</button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Teléfono</th>
                                <th>Compañía</th>
                                <th>Plan</th>
                                <th>Titular</th>
                                <th>Serie</th>
                                <th>Estado</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="ch-tbody">
                            <tr><td colspan="8" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        const quick = container.querySelector('#ch-quick');
        const clear = container.querySelector('#ch-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { chips.q = quick.value.trim(); cargarChips(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            chips.q = ''; cargarChips();
        });

        container.querySelector('#ch-filtros').addEventListener('click', abrirFiltrosChips);
        container.querySelector('#ch-refrescar').addEventListener('click', () => cargarChips());
        container.querySelector('#ch-nuevo').addEventListener('click', () => formChip(null));

        cargarChips();
    }

    async function cargarChips() {
        const tbody = document.getElementById('ch-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="8" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:           chips.q,
            codigo:      chips.codigo || '',
            compania:    chips.compania,
            plan:        chips.plan,
            responsable: chips.responsable,
            estado:      chips.estado,
            limite:      chips.limite,
            orden:       chips.orden,
            dir:         chips.dir,
        });

        try {
            const data = await api(`api/chips.php?${qs}`);
            chips.filas     = data.chips     || [];
            chips.combos    = data.combos    || chips.combos;
            chips.articulos = data.articulos || [];
            chips.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="8" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsChips();
        pintarBadgeFiltrosChips();

        if (chips.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="8" class="table-empty">No hay chips que coincidan con la búsqueda.</td></tr>';
            return;
        }

        tbody.innerHTML = chips.filas.map(filaChip).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const c  = chips.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verChip(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuChip(c), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuChip(c), e.currentTarget);
            });
        });
    }

    function pintarStatsChips() {
        const el = document.getElementById('ch-stats');
        const r  = chips.resumen;
        if (!el || !r) return;
        el.innerHTML = `
            <div class="stat-card"><span class="stat-label">Total del dominio</span><span class="stat-value">${r.total}</span></div>
            <div class="stat-card"><span class="stat-label">Habilitados</span><span class="stat-value green">${r.habilitados}</span></div>
            <div class="stat-card"><span class="stat-label">Deshabilitados</span><span class="stat-value muted">${r.deshabilitados}</span></div>
            <div class="stat-card"><span class="stat-label">Mostrados</span><span class="stat-value">${r.mostrados}</span></div>
        `;
    }

    function pintarBadgeFiltrosChips() {
        const btn   = document.getElementById('ch-filtros');
        const badge = document.getElementById('ch-filtros-badge');
        if (!btn || !badge) return;
        const n = chipsFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    function filaChip(c) {
        return `
            <tr data-id="${c.id}" class="row-clickable">
                <td class="td-id">#${c.id}</td>
                <td class="td-nombre">${c.telefono ? escapeHtml(c.telefono) : DASH}</td>
                <td>${textoCombo(c.compania_texto, c.compania) || DASH}</td>
                <td>${textoCombo(c.plan_texto, c.plan) || DASH}</td>
                <td>${c.titular ? escapeHtml(c.titular) : DASH}</td>
                <td>${c.serie ? `<code>${escapeHtml(c.serie)}</code>` : DASH}</td>
                <td>${badgeHabilitado(c.habilitado)}</td>
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
    function menuChip(c) {
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verChip(c.id) },
            {
                label: c.habilitado ? 'Deshabilitar' : 'Habilitar',
                icon:  c.habilitado ? 'fa-circle-xmark' : 'fa-circle-check',
                onSelect: () => toggleChip(c),
            },
            c.telefono ? { label: 'Copiar teléfono', icon: 'fa-copy', onSelect: () => copiar(c.telefono) } : null,
            c.serie    ? { label: 'Copiar serie',    icon: 'fa-copy', onSelect: () => copiar(c.serie) }    : null,
            { sep: true },
            { label: 'Editar',   icon: 'fa-pen',   onSelect: () => formChip(c.id) },
            { label: 'Eliminar', icon: 'fa-trash', danger: true, onSelect: () => eliminarChip(c) },
        ];
    }

    /* ---------- Consultar ---------- */
    async function verChip(id) {
        let c;
        try {
            c = (await api(`api/chips.php?id=${id}`)).chip;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const num = (v) => (v === null || v === undefined ? '' : String(v));

        const body = `<div class="view-grid">${[
            viewCard('Código',              `<code>#${c.id}</code>`),
            viewCard('Teléfono',            c.telefono ? `<code>${escapeHtml(c.telefono)}</code>` : ''),
            viewCard('Serie',               c.serie ? `<code>${escapeHtml(c.serie)}</code>` : ''),
            viewCard('Compañía',            textoCombo(c.compania_texto, c.compania)),
            viewCard('Plan',                textoCombo(c.plan_texto, c.plan)),
            viewCard('Estado',              badgeHabilitado(c.habilitado)),
            viewCard('Titular de línea',    escapeHtml(c.titular), true),
            viewCard('Responsable de pago', textoCombo(c.responsable_texto, c.responsable)),
            viewCard('País',                textoCombo(c.pais_texto, c.pais)),
            viewCard('Artículo',            c.articulo_nombre ? escapeHtml(c.articulo_nombre) : (c.articulo ? `<code>#${c.articulo}</code>` : ''), true),
            viewCard('Datos (mb)',          escapeHtml(num(c.datos))),
            viewCard('Mensajes (sms)',      escapeHtml(num(c.mensajes))),
            viewCard('Registrado',          escapeHtml(formatDate(c.registrado) || '')),
            viewCard('Recargado',           escapeHtml(formatDate(c.recargado) || '')),
            viewCard('Vencimiento',         escapeHtml(formatDate(c.vencimiento) || '')),
            viewCard('Dominio',             c.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(c.dominio_nombre)}</span>` : ''),
            viewCard('Comentario',          escapeHtml(c.comentario), true),
        ].join('')}</div>`;

        const m = openModal(`Consultar chip <span class="muted">#${c.id}</span>`, body, {
            footerHtml:  '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
            primaryHtml: '<button class="btn btn-primary" data-act="editar"><i class="fa-solid fa-pen-to-square"></i> Editar</button>',
        });

        m.backdrop.querySelector('[data-act="editar"]').addEventListener('click', () => {
            m.close();
            formChip(c.id);
        });
        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            openRowMenu([
                c.telefono ? { label: 'Copiar teléfono', icon: 'fa-copy', onSelect: () => copiar(c.telefono) } : null,
                c.serie    ? { label: 'Copiar serie',    icon: 'fa-copy', onSelect: () => copiar(c.serie) }    : null,
                { sep: true },
                {
                    label: c.habilitado ? 'Deshabilitar' : 'Habilitar',
                    icon:  c.habilitado ? 'fa-circle-xmark' : 'fa-circle-check',
                    onSelect: () => { m.close(); toggleChip(c); },
                },
            ], e.currentTarget);
        });
    }

    /* ---------- Alta / Edición ---------- */
    async function formChip(id) {
        const esEdicion = id != null;
        let c = {
            telefono: '', serie: '', titular: '', responsable: '', pais: '',
            compania: '', plan: '', datos: null, mensajes: null, articulo: null,
            registrado: '', recargado: '', vencimiento: '', comentario: '',
            habilitado: true,
        };

        if (esEdicion) {
            try {
                c = (await api(`api/chips.php?id=${id}`)).chip;
            } catch (err) {
                toast(err.message, { error: true });
                return;
            }
        }

        const articuloOpts = ['<option value="">— Sin artículo —</option>'].concat(
            chips.articulos.map((a) =>
                `<option value="${a.id}"${a.id === c.articulo ? ' selected' : ''}>${escapeHtml(a.etiqueta)}</option>`)
        ).join('');

        const dominio = sesion.dominio_nombre || (sesion.dominio ? `#${sesion.dominio}` : '—');
        const num     = (v) => (v === null || v === undefined ? '' : String(v));

        const body = `
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-telefono">Teléfono *</label>
                    <input type="tel" id="cf-telefono" maxlength="30" value="${escapeHtml(c.telefono)}">
                </div>
                <div class="form-group">
                    <label for="cf-serie">Serie</label>
                    <input type="text" id="cf-serie" maxlength="32" value="${escapeHtml(c.serie)}"
                           placeholder="ICCID">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-compania">Compañía</label>
                    <select id="cf-compania">${opcionesCombo(chips.combos.compania, c.compania, '— Sin compañía —')}</select>
                </div>
                <div class="form-group">
                    <label for="cf-plan">Plan</label>
                    <select id="cf-plan">${opcionesCombo(chips.combos.plan, c.plan, '— Sin plan —')}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-titular">Titular de línea</label>
                    <input type="text" id="cf-titular" maxlength="255" value="${escapeHtml(c.titular)}">
                </div>
                <div class="form-group">
                    <label for="cf-responsable">Responsable de pago</label>
                    <select id="cf-responsable">${opcionesCombo(chips.combos.responsable, c.responsable, '— Sin responsable —')}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-pais">País</label>
                    <select id="cf-pais">${opcionesCombo(chips.combos.pais, c.pais, '— Sin país —')}</select>
                </div>
                <div class="form-group">
                    <label for="cf-articulo">Artículo</label>
                    <select id="cf-articulo">${articuloOpts}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-datos">Datos (mb)</label>
                    <input type="number" min="0" id="cf-datos" value="${escapeHtml(num(c.datos))}">
                </div>
                <div class="form-group">
                    <label for="cf-mensajes">Mensajes (sms)</label>
                    <input type="number" min="0" id="cf-mensajes" value="${escapeHtml(num(c.mensajes))}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-registrado">Registrado</label>
                    <input type="date" id="cf-registrado" value="${escapeHtml(c.registrado)}">
                </div>
                <div class="form-group">
                    <label for="cf-recargado">Recargado</label>
                    <input type="date" id="cf-recargado" value="${escapeHtml(c.recargado)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-vencimiento">Vencimiento</label>
                    <input type="date" id="cf-vencimiento" value="${escapeHtml(c.vencimiento)}">
                </div>
                <div class="form-group">
                    <label>Dominio</label>
                    <input type="text" value="${escapeHtml(dominio)}" readonly title="Se asigna desde tu sesión">
                </div>
            </div>
            <div class="form-group">
                <label for="cf-comentario">Comentario</label>
                <textarea id="cf-comentario" maxlength="255" rows="2">${escapeHtml(c.comentario)}</textarea>
            </div>
            <div class="form-group">
                <label style="display:flex;align-items:center;gap:8px;cursor:pointer">
                    <input type="checkbox" id="cf-habilitado" ${c.habilitado ? 'checked' : ''}>
                    Chip habilitado
                </label>
            </div>
            <div class="field-error" id="cf-error" style="display:none"></div>
        `;

        const m = openModal(
            esEdicion ? `Editar chip <span class="muted">#${c.id}</span>` : 'Nuevo chip',
            body,
            {
                closeLabel:  'Cancelar',
                primaryHtml: `<button class="btn btn-primary" data-act="guardar">${esEdicion ? 'Guardar cambios' : 'Crear chip'}</button>`,
            }
        );

        const err = m.backdrop.querySelector('#cf-error');
        const btn = m.backdrop.querySelector('[data-act="guardar"]');
        const val = (sel) => m.backdrop.querySelector(sel).value.trim();

        btn.addEventListener('click', async () => {
            const payload = {
                telefono:    val('#cf-telefono'),
                serie:       val('#cf-serie'),
                titular:     val('#cf-titular'),
                responsable: val('#cf-responsable'),
                pais:        val('#cf-pais'),
                compania:    val('#cf-compania'),
                plan:        val('#cf-plan'),
                datos:       val('#cf-datos'),
                mensajes:    val('#cf-mensajes'),
                articulo:    val('#cf-articulo'),
                registrado:  val('#cf-registrado'),
                recargado:   val('#cf-recargado'),
                vencimiento: val('#cf-vencimiento'),
                comentario:  val('#cf-comentario'),
                habilitado:  m.backdrop.querySelector('#cf-habilitado').checked,
            };
            if (esEdicion) payload.id = c.id;

            btn.disabled = true;
            try {
                await api('api/chips.php', {
                    method:  esEdicion ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                m.close();
                toast(esEdicion ? 'Chip actualizado' : 'Chip creado');
                cargarChips();
            } catch (e2) {
                err.textContent   = e2.message;
                err.style.display = '';
                btn.disabled = false;
            }
        });
    }

    /* El PUT reescribe la fila entera, asi que el toggle relee el registro
       completo y solo invierte `habilitado`. */
    function payloadChip(c) {
        const num = (v) => (v === null || v === undefined ? '' : String(v));
        return {
            telefono:    c.telefono    || '',
            serie:       c.serie       || '',
            titular:     c.titular     || '',
            responsable: c.responsable || '',
            pais:        c.pais        || '',
            compania:    c.compania    || '',
            plan:        c.plan        || '',
            datos:       num(c.datos),
            mensajes:    num(c.mensajes),
            articulo:    c.articulo    || '',
            registrado:  c.registrado  || '',
            recargado:   c.recargado   || '',
            vencimiento: c.vencimiento || '',
            comentario:  c.comentario  || '',
            habilitado:  !!c.habilitado,
        };
    }

    async function toggleChip(c) {
        try {
            const full = (await api(`api/chips.php?id=${c.id}`)).chip;
            await api('api/chips.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    ...payloadChip(full),
                    id:         full.id,
                    habilitado: !full.habilitado,
                }),
            });
            toast(full.habilitado ? 'Chip deshabilitado' : 'Chip habilitado');
            cargarChips();
        } catch (err) {
            toast(err.message, { error: true });
        }
    }

    function eliminarChip(c) {
        confirmarBaja(
            `¿Eliminar el chip <strong>${escapeHtml(c.telefono || `#${c.id}`)}</strong>${c.titular ? ` (${escapeHtml(c.titular)})` : ''}? Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api(`api/chips.php?id=${c.id}`, { method: 'DELETE' });
                    toast('Chip eliminado');
                    cargarChips();
                } catch (err) {
                    toast(err.message, { error: true });
                }
            }
        );
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ----
     * Los cambios se aplican EN VIVO sobre el listado de fondo; "Aplicar"
     * solo cierra. "Cerrar" revierte al snapshot tomado al abrir. */
    function abrirFiltrosChips() {
        const snapshot = { ...chips };
        let aplicado   = false;

        const chipFiltro = (val, label) =>
            `<button type="button" class="filter-chip${chips.estado === val ? ' active' : ''}" data-valor="${val}">${label}</button>`;

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="cf-f-codigo">Código</label>
                    <input type="number" min="1" id="cf-f-codigo" placeholder="ID del chip" value="${escapeHtml(chips.codigo)}">
                </div>
                <div class="form-group">
                    <label for="cf-f-compania">Compañía</label>
                    <select id="cf-f-compania">${opcionesCombo(chips.combos.compania, chips.compania, 'Todas')}</select>
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-f-plan">Plan</label>
                    <select id="cf-f-plan">${opcionesCombo(chips.combos.plan, chips.plan, 'Todos')}</select>
                </div>
                <div class="form-group">
                    <label for="cf-f-responsable">Responsable de pago</label>
                    <select id="cf-f-responsable">${opcionesCombo(chips.combos.responsable, chips.responsable, 'Todos')}</select>
                </div>
            </div>
            <div class="form-group">
                <label>Estado del registro</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="cf-f-estado">
                    ${chipFiltro('todos', 'Todos')}
                    ${chipFiltro('habilitados', 'Habilitados')}
                    ${chipFiltro('deshabilitados', 'Deshabilitados')}
                </div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="cf-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="cf-f-limite" value="${chips.limite}">
                </div>
                <div class="form-group">
                    <label for="cf-f-orden">Ordenar por</label>
                    <select id="cf-f-orden">
                        <option value="id">Código</option>
                        <option value="telefono">Teléfono</option>
                        <option value="compania">Compañía</option>
                        <option value="titular">Titular</option>
                        <option value="serie">Serie</option>
                        <option value="registrado">Registrado</option>
                        <option value="vencimiento">Vencimiento</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="cf-f-dir">Dirección</label>
                    <select id="cf-f-dir">
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
                Object.assign(chips, snapshot);
                cargarChips();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#cf-f-orden').value = chips.orden;
        $('#cf-f-dir').value   = chips.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosChips(); cargarChips(); };

        $('#cf-f-codigo').addEventListener('input',       (e) => { chips.codigo      = e.target.value.trim(); aplicarEnVivo(); });
        $('#cf-f-compania').addEventListener('change',    (e) => { chips.compania    = e.target.value; aplicarEnVivo(); });
        $('#cf-f-plan').addEventListener('change',        (e) => { chips.plan        = e.target.value; aplicarEnVivo(); });
        $('#cf-f-responsable').addEventListener('change', (e) => { chips.responsable = e.target.value; aplicarEnVivo(); });
        $('#cf-f-limite').addEventListener('change',      (e) => { chips.limite      = +e.target.value || 100; aplicarEnVivo(); });
        $('#cf-f-orden').addEventListener('change',       (e) => { chips.orden       = e.target.value; aplicarEnVivo(); });
        $('#cf-f-dir').addEventListener('change',         (e) => { chips.dir         = e.target.value; aplicarEnVivo(); });

        $('#cf-f-estado').addEventListener('click', (e) => {
            const b = e.target.closest('[data-valor]');
            if (!b) return;
            chips.estado = b.dataset.valor;
            m.backdrop.querySelectorAll('#cf-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x === b));
            aplicarEnVivo();
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            Object.assign(chips, CHIPS_DEFAULTS);
            $('#cf-f-codigo').value      = '';
            $('#cf-f-compania').value    = '';
            $('#cf-f-plan').value        = '';
            $('#cf-f-responsable').value = '';
            $('#cf-f-limite').value      = CHIPS_DEFAULTS.limite;
            $('#cf-f-orden').value       = CHIPS_DEFAULTS.orden;
            $('#cf-f-dir').value         = CHIPS_DEFAULTS.dir;
            m.backdrop.querySelectorAll('#cf-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x.dataset.valor === CHIPS_DEFAULTS.estado));
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* =========================================================
     * Modulo: Invitaciones  (convenciones de la skill abm_design)
     * Portado de reactor-panel/invitaciones/listar.php: mismo recorte por
     * dominio y las mismas columnas del listado legacy (identificador,
     * emitida, emisor, destinatario, estado). La columna "Dominio" del
     * legacy no se repite en la tabla — todo el panel ya corre acotado al
     * dominio de la sesion — pero sigue estando en el modal de Consulta.
     *
     * SOLO LECTURA, igual que el legacy: la invitacion la emite el usuario
     * desde la pantalla de invitar y el destinatario es quien la acepta o
     * la rechaza. El menu contextual no tiene Editar ni Eliminar; sus
     * acciones propias son atajos para acotar el listado.
     * ======================================================= */

    const INVITACIONES_DEFAULTS = {
        codigo: '', uuid: '', emisor: 0, estado: '',
        desde: '', hasta: '', limite: 100, orden: 'id', dir: 'desc',
    };

    const invitaciones = {
        q: '',
        ...INVITACIONES_DEFAULTS,
        filas: [],
        estados: [],
        catalogos: { emisores: [] },
        resumen: null,
    };

    function invitacionesFiltrosActivos() {
        return Object.keys(INVITACIONES_DEFAULTS)
            .filter((k) => String(invitaciones[k]) !== String(INVITACIONES_DEFAULTS[k]))
            .length;
    }

    /* Codigos del legacy: 1 pendiente, 3 aceptada, 2 rechazada, 0 anulada.
       El texto lo manda el backend desde `combos`; aca solo se elige el
       color y el icono del badge. */
    function invitacionEstado(codigo, texto) {
        const v = String(codigo ?? '').trim();
        if (v === '') return DASH;
        const label = escapeHtml(texto || v);
        if (v === '1') return `<span class="badge badge-warn"><i class="fa-solid fa-clock"></i> ${label}</span>`;
        if (v === '3') return `<span class="badge badge-success"><i class="fa-solid fa-check"></i> ${label}</span>`;
        if (v === '2') return `<span class="badge badge-danger"><i class="fa-solid fa-xmark"></i> ${label}</span>`;
        if (v === '0') return `<span class="badge badge-info"><i class="fa-solid fa-ban"></i> ${label}</span>`;
        return `<span class="badge badge-info">${label}</span>`;
    }

    function renderInvitaciones(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-envelope-open-text"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        Las invitaciones son los pedidos de acceso que un usuario del dominio le envía a
                        una persona para que se sume al sistema, con sus datos de contacto, cuándo se emitió,
                        cuándo la abrió el destinatario y en qué estado quedó. Se listan únicamente las del
                        dominio <strong>${dominio}</strong> y es un registro de solo lectura: no se puede
                        editar ni eliminar.
                    </div>
                </div>

                <div class="stats-bar" id="in-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="in-quick" class="search-input"
                                   placeholder="Buscar identificador, destinatario o emisor…">
                            <button type="button" class="search-clear" id="in-quick-clear"
                                    style="display:none" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="in-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="in-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="in-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Identificador</th>
                                <th>Emitida</th>
                                <th>Emisor</th>
                                <th>Destinatario</th>
                                <th>Estado</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="in-tbody">
                            <tr><td colspan="7" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        const quick = container.querySelector('#in-quick');
        const clear = container.querySelector('#in-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { invitaciones.q = quick.value.trim(); cargarInvitaciones(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            invitaciones.q = ''; cargarInvitaciones();
        });

        container.querySelector('#in-filtros').addEventListener('click', abrirFiltrosInvitaciones);
        container.querySelector('#in-refrescar').addEventListener('click', () => cargarInvitaciones());

        cargarInvitaciones();
    }

    async function cargarInvitaciones() {
        const tbody = document.getElementById('in-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:      invitaciones.q,
            codigo: invitaciones.codigo || '',
            uuid:   invitaciones.uuid,
            emisor: invitaciones.emisor || '',
            estado: invitaciones.estado,
            desde:  invitaciones.desde,
            hasta:  invitaciones.hasta,
            limite: invitaciones.limite,
            orden:  invitaciones.orden,
            dir:    invitaciones.dir,
        });

        try {
            const data = await api(`api/invitaciones.php?${qs}`);
            invitaciones.filas     = data.invitaciones || [];
            invitaciones.estados   = data.estados      || [];
            invitaciones.catalogos = data.catalogos    || { emisores: [] };
            invitaciones.resumen   = data.resumen      || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsInvitaciones();
        pintarBadgeFiltrosInvitaciones();

        if (invitaciones.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">No hay invitaciones que coincidan con la búsqueda.</td></tr>';
            return;
        }

        tbody.innerHTML = invitaciones.filas.map(filaInvitacion).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const r  = invitaciones.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verInvitacion(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuInvitacion(r), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuInvitacion(r), e.currentTarget);
            });
        });
    }

    function pintarStatsInvitaciones() {
        const el = document.getElementById('in-stats');
        const r  = invitaciones.resumen;
        if (!el || !r) return;
        el.innerHTML = `
            <div class="stat-card"><span class="stat-label">Total del dominio</span><span class="stat-value">${r.total}</span></div>
            <div class="stat-card"><span class="stat-label">Pendientes</span><span class="stat-value orange">${r.pendientes}</span></div>
            <div class="stat-card"><span class="stat-label">Aceptadas</span><span class="stat-value green">${r.aceptadas}</span></div>
            <div class="stat-card"><span class="stat-label">Rechazadas</span><span class="stat-value red">${r.rechazadas}</span></div>
            <div class="stat-card"><span class="stat-label">Anuladas</span><span class="stat-value muted">${r.anuladas}</span></div>
            <div class="stat-card"><span class="stat-label">Mostradas</span><span class="stat-value muted">${r.mostrados}</span></div>
        `;
    }

    function pintarBadgeFiltrosInvitaciones() {
        const btn   = document.getElementById('in-filtros');
        const badge = document.getElementById('in-filtros-badge');
        if (!btn || !badge) return;
        const n = invitacionesFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    function filaInvitacion(r) {
        const emisor = r.emisor_nombre || r.emisor_login
            ? `<div class="td-nombre">${escapeHtml(r.emisor_nombre || r.emisor_login)}</div>
               ${r.emisor_nombre && r.emisor_login ? `<div class="td-id">${escapeHtml(r.emisor_login)}</div>` : ''}`
            : DASH;

        // El legacy apilaba nombre / celular / correo en una sola celda.
        const contacto = [r.celular, r.correo].filter(Boolean)
            .map((x) => `<div class="td-id">${escapeHtml(x)}</div>`).join('');
        const destinatario = (r.nombre || contacto)
            ? `${r.nombre ? `<div class="td-nombre">${escapeHtml(r.nombre)}</div>` : ''}${contacto}`
            : DASH;

        return `
            <tr data-id="${r.id}" class="row-clickable">
                <td class="td-id">#${r.id}</td>
                <td>${r.uuid ? `<code>${escapeHtml(r.uuid)}</code>` : DASH}</td>
                <td>${escapeHtml(formatDate(r.emitida) || '') || DASH}</td>
                <td>${emisor}</td>
                <td>${destinatario}</td>
                <td>${invitacionEstado(r.estado, r.estado_texto)}</td>
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

    // Menu contextual de fila. Sin Editar ni Eliminar: el modulo es de solo
    // lectura. Las acciones propias del recurso son atajos para acotar el
    // listado al emisor o al estado de la fila.
    function menuInvitacion(r) {
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verInvitacion(r.id) },
            r.uuid ? {
                label: 'Copiar identificador',
                icon:  'fa-copy',
                onSelect: () => copiar(r.uuid),
            } : null,
            r.emisor ? {
                label: 'Filtrar por este emisor',
                icon:  'fa-user',
                onSelect: () => { invitaciones.emisor = r.emisor; pintarBadgeFiltrosInvitaciones(); cargarInvitaciones(); },
            } : null,
            r.estado ? {
                label: `Filtrar por estado "${r.estado_texto || r.estado}"`,
                icon:  'fa-filter',
                onSelect: () => { invitaciones.estado = r.estado; pintarBadgeFiltrosInvitaciones(); cargarInvitaciones(); },
            } : null,
        ].filter(Boolean);
    }

    /* ---------- Consultar ---------- */
    async function verInvitacion(id) {
        let r;
        try {
            r = (await api(`api/invitaciones.php?id=${id}`)).invitacion;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const body = `<div class="view-grid">${[
            viewCard('Código',           `<code>#${r.id}</code>`),
            viewCard('Identificador',    r.uuid ? `<code>${escapeHtml(r.uuid)}</code>` : ''),
            viewCard('Estado',           invitacionEstado(r.estado, r.estado_texto)),
            viewCard('Dominio',          r.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(r.dominio_nombre)}</span>` : (r.dominio ? `<code>#${r.dominio}</code>` : '')),
            viewCard('Emisor',           r.emisor_nombre ? escapeHtml(r.emisor_nombre) : (r.emisor ? `<code>#${r.emisor}</code>` : '')),
            viewCard('Cuenta del emisor', r.emisor_login ? escapeHtml(r.emisor_login) : ''),
            viewCard('Correo del emisor', r.emisor_correo ? escapeHtml(r.emisor_correo) : '', true),
            viewCard('Destinatario',     r.nombre ? escapeHtml(r.nombre) : '', true),
            viewCard('Celular',          r.celular ? escapeHtml(r.celular) : ''),
            viewCard('Correo',           r.correo ? escapeHtml(r.correo) : ''),
            viewCard('Emitida',          escapeHtml(formatDate(r.emitida) || '')),
            viewCard('Abierta',          escapeHtml(formatDate(r.abierta) || '')),
        ].join('')}</div>`;

        // Footer sin "Editar": no hay modal de edicion para este recurso.
        const m = openModal(`Consultar invitación <span class="muted">#${r.id}</span>`, body, {
            footerHtml: '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
        });

        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            const atajos = [
                r.emisor ? {
                    label: 'Filtrar por este emisor',
                    icon:  'fa-user',
                    onSelect: () => { m.close(); invitaciones.emisor = r.emisor; pintarBadgeFiltrosInvitaciones(); cargarInvitaciones(); },
                } : null,
                r.estado ? {
                    label: `Filtrar por estado "${r.estado_texto || r.estado}"`,
                    icon:  'fa-filter',
                    onSelect: () => { m.close(); invitaciones.estado = r.estado; pintarBadgeFiltrosInvitaciones(); cargarInvitaciones(); },
                } : null,
            ].filter(Boolean);

            const detalle = `#${r.id} ${r.uuid || ''}`
                + ` · ${formatDate(r.emitida) || '—'}`
                + ` · ${r.emisor_nombre || r.emisor_login || '—'}`
                + ` · ${r.nombre || r.correo || r.celular || '—'}`
                + ` · ${r.estado_texto || r.estado || '—'}`;

            openRowMenu([
                ...atajos,
                ...(atajos.length ? [{ sep: true }] : []),
                r.uuid ? { label: 'Copiar identificador', icon: 'fa-link', onSelect: () => copiar(r.uuid) } : null,
                { label: 'Copiar detalle', icon: 'fa-copy', onSelect: () => copiar(detalle) },
            ].filter(Boolean), e.currentTarget);
        });
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ---- */
    function abrirFiltrosInvitaciones() {
        const snapshot = { ...invitaciones };
        let aplicado   = false;

        const emisorOpts = ['<option value="0">Todos</option>'].concat(
            (invitaciones.catalogos.emisores || []).map((x) =>
                `<option value="${x.id}"${x.id === invitaciones.emisor ? ' selected' : ''}>${escapeHtml(x.nombre)}</option>`)
        ).join('');

        const chipFiltro = (val, label) =>
            `<button type="button" class="filter-chip${invitaciones.estado === val ? ' active' : ''}" data-valor="${escapeHtml(val)}">${escapeHtml(label)}</button>`;

        const chipsEstado = [chipFiltro('', 'Todas')].concat(
            (invitaciones.estados || []).map((e) => chipFiltro(e.valor, e.texto))
        ).join('');

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="if-f-codigo">Código</label>
                    <input type="number" min="1" id="if-f-codigo" placeholder="ID de la invitación" value="${escapeHtml(invitaciones.codigo)}">
                </div>
                <div class="form-group">
                    <label for="if-f-uuid">Identificador</label>
                    <input type="text" id="if-f-uuid" placeholder="UUID de la invitación" value="${escapeHtml(invitaciones.uuid)}">
                </div>
            </div>
            <div class="form-group">
                <label for="if-f-emisor">Emisor</label>
                <select id="if-f-emisor">${emisorOpts}</select>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="if-f-desde">Emitida desde</label>
                    <input type="date" id="if-f-desde" value="${escapeHtml(invitaciones.desde)}">
                </div>
                <div class="form-group">
                    <label for="if-f-hasta">Emitida hasta</label>
                    <input type="date" id="if-f-hasta" value="${escapeHtml(invitaciones.hasta)}">
                </div>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="if-f-estado">${chipsEstado}</div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="if-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="if-f-limite" value="${invitaciones.limite}">
                </div>
                <div class="form-group">
                    <label for="if-f-orden">Ordenar por</label>
                    <select id="if-f-orden">
                        <option value="id">Código</option>
                        <option value="emitida">Emitida</option>
                        <option value="abierta">Abierta</option>
                        <option value="nombre">Destinatario</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="if-f-dir">Dirección</label>
                    <select id="if-f-dir">
                        <option value="desc">Descendente</option>
                        <option value="asc">Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        let debounceUuid = null;

        const m = openModal('<i class="fa-solid fa-filter"></i> Filtros', body, {
            primaryHtml: `
                <button class="btn btn-ghost"   data-act="limpiar">Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar">Aplicar</button>`,
            onClose: () => {
                // Un tipeo reciente en Identificador no puede pisar el estado
                // despues de revertir o de aplicar: se descarta el pendiente.
                clearTimeout(debounceUuid);
                // Cerrar / Esc / backdrop revierten; Aplicar no.
                if (aplicado) return;
                Object.assign(invitaciones, snapshot);
                cargarInvitaciones();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#if-f-orden').value = invitaciones.orden;
        $('#if-f-dir').value   = invitaciones.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosInvitaciones(); cargarInvitaciones(); };

        $('#if-f-codigo').addEventListener('input',  (e) => { invitaciones.codigo = e.target.value.trim(); aplicarEnVivo(); });
        $('#if-f-uuid').addEventListener('input',    (e) => {
            const v = e.target.value.trim();
            clearTimeout(debounceUuid);
            debounceUuid = setTimeout(() => { invitaciones.uuid = v; aplicarEnVivo(); }, 300);
        });
        $('#if-f-emisor').addEventListener('change', (e) => { invitaciones.emisor = +e.target.value || 0;  aplicarEnVivo(); });
        $('#if-f-desde').addEventListener('change',  (e) => { invitaciones.desde  = e.target.value;        aplicarEnVivo(); });
        $('#if-f-hasta').addEventListener('change',  (e) => { invitaciones.hasta  = e.target.value;        aplicarEnVivo(); });
        $('#if-f-limite').addEventListener('change', (e) => { invitaciones.limite = +e.target.value || 100; aplicarEnVivo(); });
        $('#if-f-orden').addEventListener('change',  (e) => { invitaciones.orden  = e.target.value;        aplicarEnVivo(); });
        $('#if-f-dir').addEventListener('change',    (e) => { invitaciones.dir    = e.target.value;        aplicarEnVivo(); });

        $('#if-f-estado').addEventListener('click', (e) => {
            const b = e.target.closest('[data-valor]');
            if (!b) return;
            invitaciones.estado = b.dataset.valor;
            m.backdrop.querySelectorAll('#if-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x === b));
            aplicarEnVivo();
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            clearTimeout(debounceUuid);
            Object.assign(invitaciones, INVITACIONES_DEFAULTS);
            $('#if-f-codigo').value = '';
            $('#if-f-uuid').value   = '';
            $('#if-f-emisor').value = '0';
            $('#if-f-desde').value  = '';
            $('#if-f-hasta').value  = '';
            $('#if-f-limite').value = INVITACIONES_DEFAULTS.limite;
            $('#if-f-orden').value  = INVITACIONES_DEFAULTS.orden;
            $('#if-f-dir').value    = INVITACIONES_DEFAULTS.dir;
            m.backdrop.querySelectorAll('#if-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x.dataset.valor === INVITACIONES_DEFAULTS.estado));
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* =========================================================
     * Modulos Facturas y Recibos (comprobantes del contrato)
     * Portado de reactor-panel/comprobantes/listar.php (+ consultar.php para
     * el detalle). Las dos pantallas son el MISMO listado con distinto tipo
     * de talonario -- F = facturas, R = recibos --, asi que comparten
     * renderer y tienen un estado de filtros por tipo.
     *
     * Solo lectura. El backend (api/comprobantes.php) acota por el contrato
     * del dominio de la sesion y devuelve unicamente los comprobantes en
     * estado Pendiente o Cancelado, como hacia el legacy.
     * ======================================================= */

    const COMPROBANTES_DEFAULTS = {
        codigo: '', numero: '', estado: '', desde: '', hasta: '',
        limite: 100, orden: 'id', dir: 'desc',
    };

    const CP_META = {
        F: {
            titulo: 'Facturas',
            icono:  'fa-file-invoice-dollar',
            buscar: 'Buscar razón social, CUIT o número…',
            vacio:  'No hay facturas que coincidan con la búsqueda.',
            ayuda:  (dom) => `Las facturas son los comprobantes de los servicios que se le
                     facturan al dominio <strong>${dom}</strong>, con su número, la fecha de
                     emisión, el vencimiento, el importe y si están pendientes de pago o ya
                     canceladas. Es un registro de solo lectura: se consultan y se descargan,
                     no se editan.`,
        },
        R: {
            titulo: 'Recibos',
            icono:  'fa-receipt',
            buscar: 'Buscar razón social, CUIT o número…',
            vacio:  'No hay recibos que coincidan con la búsqueda.',
            ayuda:  (dom) => `Los recibos son los comprobantes de los pagos recibidos por las
                     facturas del dominio <strong>${dom}</strong>, con su número, la fecha y el
                     importe acreditado. Es un registro de solo lectura: se consultan y se
                     descargan, no se editan.`,
        },
    };

    const comprobantes = {
        activo: 'F',
        F: { q: '', ...COMPROBANTES_DEFAULTS, filas: [], estados: [], resumen: null },
        R: { q: '', ...COMPROBANTES_DEFAULTS, filas: [], estados: [], resumen: null },
    };

    /** Estado de la solapa que se esta mirando (F o R). */
    const cp = () => comprobantes[comprobantes.activo];

    function comprobantesFiltrosActivos() {
        const s = cp();
        let n = 0;
        Object.keys(COMPROBANTES_DEFAULTS).forEach((k) => {
            if (String(s[k]) !== String(COMPROBANTES_DEFAULTS[k])) n++;
        });
        return n;
    }

    function renderComprobantes(container, tipo) {
        comprobantes.activo = tipo;
        const meta = CP_META[tipo];
        const esF  = tipo === 'F';

        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid ${meta.icono}"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        ${meta.ayuda(dominio)}
                    </div>
                </div>

                <div class="stats-bar" id="cp-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="cp-quick" class="search-input"
                                   placeholder="${escapeHtml(meta.buscar)}" value="${escapeHtml(cp().q)}">
                            <button type="button" class="search-clear" id="cp-quick-clear"
                                    style="display:${cp().q ? '' : 'none'}" title="Limpiar búsqueda">×</button>
                        </div>
                        <button type="button" class="btn btn-ghost btn-icon" id="cp-filtros" title="Filtros">
                            <i class="fa-solid fa-filter"></i>
                            <span class="btn-icon-badge" id="cp-filtros-badge" style="display:none">0</span>
                        </button>
                        <button type="button" class="btn btn-ghost btn-icon" id="cp-refrescar" title="Refrescar">
                            <i class="fa-solid fa-rotate"></i>
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Código</th>
                                <th>Número</th>
                                <th>Emisión</th>
                                ${esF ? '<th>Vencimiento</th>' : ''}
                                <th>Razón social</th>
                                <th style="text-align:right">Total</th>
                                <th>Estado</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="cp-tbody">
                            <tr><td colspan="${esF ? 8 : 7}" class="table-empty">Cargando…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        `;

        // `s` es el estado de ESTA solapa, capturado en el render: si el
        // usuario cambia de Facturas a Recibos mientras hay un tipeo pendiente,
        // el debounce escribe en la solapa que lo origino, no en la nueva.
        const s     = cp();
        const quick = container.querySelector('#cp-quick');
        const clear = container.querySelector('#cp-quick-clear');
        let debounce = null;
        quick.addEventListener('input', () => {
            clear.style.display = quick.value ? '' : 'none';
            clearTimeout(debounce);
            debounce = setTimeout(() => { s.q = quick.value.trim(); cargarComprobantes(); }, 300);
        });
        clear.addEventListener('click', () => {
            quick.value = ''; clear.style.display = 'none';
            s.q = ''; cargarComprobantes();
        });

        container.querySelector('#cp-filtros').addEventListener('click', abrirFiltrosComprobantes);
        container.querySelector('#cp-refrescar').addEventListener('click', () => cargarComprobantes());

        cargarComprobantes();
    }

    async function cargarComprobantes() {
        const tbody = document.getElementById('cp-tbody');
        if (!tbody) return;

        const tipo    = comprobantes.activo;
        const s       = cp();
        const columns = tipo === 'F' ? 8 : 7;
        tbody.innerHTML = `<tr><td colspan="${columns}" class="table-empty">Cargando…</td></tr>`;

        const qs = new URLSearchParams({
            tipo,
            q:      s.q,
            codigo: s.codigo || '',
            numero: s.numero,
            estado: s.estado,
            desde:  s.desde,
            hasta:  s.hasta,
            limite: s.limite,
            orden:  s.orden,
            dir:    s.dir,
        });

        try {
            const data = await api(`api/comprobantes.php?${qs}`);
            s.filas   = data.comprobantes || [];
            s.estados = data.estados      || [];
            s.resumen = data.resumen      || null;
        } catch (err) {
            // Incluye el caso "el dominio no tiene contrato asignado" (409),
            // que no es un error del usuario: se muestra tal cual lo redacta
            // el backend, sin tono de falla.
            s.resumen = null;
            pintarStatsComprobantes();
            tbody.innerHTML = `<tr><td colspan="${columns}" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsComprobantes();
        pintarBadgeFiltrosComprobantes();

        if (s.filas.length === 0) {
            tbody.innerHTML = `<tr><td colspan="${columns}" class="table-empty">${CP_META[tipo].vacio}</td></tr>`;
            return;
        }

        tbody.innerHTML = s.filas.map(filaComprobante).join('');
        tbody.querySelectorAll('tr[data-id]').forEach((tr) => {
            const id = +tr.dataset.id;
            const r  = s.filas.find((x) => x.id === id);
            tr.addEventListener('click', (e) => {
                if (e.target.closest('.action-col')) return;
                verComprobante(id);
            });
            tr.addEventListener('contextmenu', (e) => {
                e.preventDefault();
                openRowMenu(menuComprobante(r), { x: e.clientX, y: e.clientY });
            });
            tr.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
                e.stopPropagation();
                openRowMenu(menuComprobante(r), e.currentTarget);
            });
        });
    }

    function pintarStatsComprobantes() {
        const el = document.getElementById('cp-stats');
        const r  = cp().resumen;
        if (!el) return;
        if (!r) { el.innerHTML = ''; return; }

        const stat = (label, valor, clase = '') =>
            `<div class="stat-card"><span class="stat-label">${label}</span>
             <span class="stat-value ${clase}">${valor}</span></div>`;

        // En facturas lo que importa es cuánto queda por pagar; en recibos,
        // cuánto se pagó: el mismo resumen, leído distinto.
        el.innerHTML = comprobantes.activo === 'F'
            ? stat('Facturas del contrato', r.total)
              + stat('Pendientes', r.pendientes, r.pendientes > 0 ? 'orange' : 'muted')
              + stat('Importe pendiente', escapeHtml(formatMoneda(r.importe_pendiente) || '—'), r.importe_pendiente > 0 ? 'orange' : 'muted')
              + stat('Mostradas', r.mostrados, 'muted')
            : stat('Recibos del contrato', r.total)
              + stat('Importe acumulado', escapeHtml(formatMoneda(r.importe_total) || '—'), 'green')
              + stat('Mostrados', r.mostrados, 'muted');
    }

    function pintarBadgeFiltrosComprobantes() {
        const btn   = document.getElementById('cp-filtros');
        const badge = document.getElementById('cp-filtros-badge');
        if (!btn || !badge) return;
        const n = comprobantesFiltrosActivos();
        badge.textContent   = String(n);
        badge.style.display = n > 0 ? '' : 'none';
        btn.classList.toggle('active', n > 0);
    }

    /* Pendiente = todavia se debe; Cancelado = saldado. Son los dos unicos
       estados que el backend deja ver. */
    function comprobanteEstado(r) {
        const texto = escapeHtml(r.estado_texto || r.estado || '');
        if (!texto) return DASH;
        const clase = r.estado === '2' ? 'badge-warn' : 'badge-success';
        return `<span class="badge ${clase}">${texto}</span>`;
    }

    function filaComprobante(r) {
        const esF = comprobantes.activo === 'F';
        return `
            <tr data-id="${r.id}" class="row-clickable">
                <td class="td-id">#${r.id}</td>
                <td class="td-nombre">${escapeHtml(r.numero)}</td>
                <td>${escapeHtml(formatDate(r.emision) || '') || DASH}</td>
                ${esF ? `<td>${escapeHtml(formatDate(r.vencimiento) || '') || DASH}</td>` : ''}
                <td>${escapeHtml(r.razon) || DASH}</td>
                <td style="text-align:right;white-space:nowrap">${escapeHtml(formatMoneda(r.total) || '') || DASH}</td>
                <td>${comprobanteEstado(r)}</td>
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

    /* Menu contextual de fila. Sin Editar ni Eliminar: el modulo es de solo
       lectura. Las acciones propias son las del comprobante en si (abrirlo,
       bajarlo, compartirlo) y el atajo para acotar el listado por estado. */
    function menuComprobante(r) {
        const e = r.enlaces;
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verComprobante(r.id) },
            e ? { label: 'Abrir comprobante', icon: 'fa-up-right-from-square', onSelect: () => window.open(e.abrir, '_blank', 'noopener') } : null,
            e ? { label: 'Descargar',         icon: 'fa-download',            onSelect: () => window.open(e.descargar, '_blank', 'noopener') } : null,
            e ? { label: 'Copiar enlace',     icon: 'fa-link',                onSelect: () => copiar(e.compartir) } : null,
            r.estado ? { sep: true } : null,
            r.estado ? {
                label: `Filtrar por estado "${r.estado_texto || r.estado}"`,
                icon:  'fa-filter',
                onSelect: () => { cp().estado = r.estado; pintarBadgeFiltrosComprobantes(); cargarComprobantes(); },
            } : null,
        ].filter(Boolean);
    }

    /* ---------- Consultar ---------- */
    async function verComprobante(id) {
        const tipo = comprobantes.activo;
        let data;
        try {
            data = await api(`api/comprobantes.php?tipo=${tipo}&id=${id}`);
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const r   = data.comprobante;
        const esF = r.tipo !== 'R';

        const cards = [
            viewCard('Comprobante',      `<strong>${escapeHtml(r.numero_largo)}</strong>`),
            viewCard('Estado',           comprobanteEstado(r)),
            viewCard('Emisión',          escapeHtml(formatDate(r.emision) || '')),
            esF ? viewCard('Vencimiento', escapeHtml(formatDate(r.vencimiento) || '')) : null,
            viewCard('Razón social',     escapeHtml(r.razon), true),
            viewCard('Condición fiscal', escapeHtml(r.condicion_texto || r.condicion || '')),
            viewCard('CUIT',             r.cuit ? `<code>${escapeHtml(r.cuit)}</code>` : ''),
            viewCard('Domicilio',        escapeHtml(r.domicilio || ''), true),
            viewCard('Correo',           escapeHtml(r.correo  || '')),
            viewCard('Celular',          escapeHtml(r.celular || '')),
            r.fiscal ? viewCard('CAE',      r.caenro ? `<code>${escapeHtml(r.caenro)}</code>` : '') : null,
            r.fiscal ? viewCard('Vto. CAE', escapeHtml(formatDate(r.caevto) || '')) : null,
            r.observaciones ? viewCard('Observaciones', escapeHtml(r.observaciones), true) : null,
            viewCard('Código', `<code>#${r.id}</code>`),
        ].filter(Boolean).join('');

        const body = `<div class="view-grid">${cards}</div>
            ${detalleComprobante(data.renglones || [])}
            <div class="view-grid" style="margin-top:12px">
                ${viewCard('Subtotal', escapeHtml(formatMoneda(r.subtotal) || ''))}
                ${viewCard('IVA',      escapeHtml(formatMoneda(r.iva)      || ''))}
                ${viewCard('Total',    `<strong>${escapeHtml(formatMoneda(r.total) || '—')}</strong>`, true)}
            </div>`;

        // Footer sin "Editar": no hay modal de edicion para este recurso.
        const m = openModal(
            `${escapeHtml(r.numero)} <span class="muted">#${r.id}</span>`,
            body,
            {
                wide: true,
                footerHtml: '<button class="btn btn-ghost btn-icon" data-act="menu" title="Más acciones"><i class="fa-solid fa-bars"></i></button>',
                primaryHtml: r.enlaces
                    ? '<button class="btn btn-primary" data-act="abrir"><i class="fa-solid fa-up-right-from-square"></i> Abrir</button>'
                    : '',
            }
        );

        if (r.enlaces) {
            m.backdrop.querySelector('[data-act="abrir"]')
                .addEventListener('click', () => window.open(r.enlaces.abrir, '_blank', 'noopener'));
        }

        m.backdrop.querySelector('[data-act="menu"]').addEventListener('click', (e) => {
            e.stopPropagation();
            openRowMenu([
                r.enlaces ? { label: 'Descargar',     icon: 'fa-download', onSelect: () => window.open(r.enlaces.descargar, '_blank', 'noopener') } : null,
                r.enlaces ? { label: 'Copiar enlace', icon: 'fa-link',     onSelect: () => copiar(r.enlaces.compartir) } : null,
                { label: 'Copiar número', icon: 'fa-copy', onSelect: () => copiar(r.numero_largo) },
            ].filter(Boolean), e.currentTarget);
        });
    }

    /* Renglones del comprobante: la misma tabla que imprime el PDF. */
    function detalleComprobante(renglones) {
        if (renglones.length === 0) return '';

        const filas = renglones.map((x) => `
            <tr>
                <td style="text-align:right">${escapeHtml(formatNumero(x.cantidad) || '')}</td>
                <td>${escapeHtml(x.detalle) || DASH}</td>
                <td style="text-align:right">${x.iva === null ? DASH : escapeHtml(formatNumero(x.iva) + ' %')}</td>
                <td style="text-align:right;white-space:nowrap">${escapeHtml(formatMoneda(x.unitario) || '')}</td>
                <td style="text-align:right;white-space:nowrap">${escapeHtml(formatMoneda(x.monto) || '')}</td>
            </tr>`).join('');

        return `<div class="table-card" style="margin-top:16px">
            <table>
                <thead>
                    <tr>
                        <th style="text-align:right">Cantidad</th>
                        <th>Detalle</th>
                        <th style="text-align:right">IVA</th>
                        <th style="text-align:right">Unitario</th>
                        <th style="text-align:right">Monto</th>
                    </tr>
                </thead>
                <tbody>${filas}</tbody>
            </table>
        </div>`;
    }

    /* ---------- Modal de filtros (skill abm_design §Modal de filtros) ---- */
    function abrirFiltrosComprobantes() {
        const s        = cp();
        const snapshot = { ...s };
        const esF      = comprobantes.activo === 'F';
        let aplicado   = false;

        const chipFiltro = (val, label) =>
            `<button type="button" class="filter-chip${s.estado === val ? ' active' : ''}" data-valor="${escapeHtml(val)}">${escapeHtml(label)}</button>`;

        const chipsEstado = [chipFiltro('', 'Todos')].concat(
            (s.estados || []).map((e) => chipFiltro(e.valor, e.texto))
        ).join('');

        const body = `
            <div class="filters-grid">
                <div class="form-group">
                    <label for="cf-f-codigo">Código</label>
                    <input type="number" min="1" id="cf-f-codigo" placeholder="ID del comprobante" value="${escapeHtml(s.codigo)}">
                </div>
                <div class="form-group">
                    <label for="cf-f-numero">Número</label>
                    <input type="text" id="cf-f-numero" placeholder="Ej. 003340" value="${escapeHtml(s.numero)}">
                </div>
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="cf-f-desde">Emisión desde</label>
                    <input type="date" id="cf-f-desde" value="${escapeHtml(s.desde)}">
                </div>
                <div class="form-group">
                    <label for="cf-f-hasta">Emisión hasta</label>
                    <input type="date" id="cf-f-hasta" value="${escapeHtml(s.hasta)}">
                </div>
            </div>
            <div class="form-group">
                <label>Estado</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="cf-f-estado">${chipsEstado}</div>
            </div>
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="cf-f-limite">Límite</label>
                    <input type="number" min="1" max="1000" id="cf-f-limite" value="${s.limite}">
                </div>
                <div class="form-group">
                    <label for="cf-f-orden">Ordenar por</label>
                    <select id="cf-f-orden">
                        <option value="id">Código</option>
                        <option value="serie">Número</option>
                        <option value="emision">Emisión</option>
                        ${esF ? '<option value="vencimiento">Vencimiento</option>' : ''}
                        <option value="total">Total</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="cf-f-dir">Dirección</label>
                    <select id="cf-f-dir">
                        <option value="desc">Descendente</option>
                        <option value="asc">Ascendente</option>
                    </select>
                </div>
            </div>
        `;

        let debounceNumero = null;

        const m = openModal('<i class="fa-solid fa-filter"></i> Filtros', body, {
            primaryHtml: `
                <button class="btn btn-ghost"   data-act="limpiar">Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar">Aplicar</button>`,
            onClose: () => {
                // Un tipeo reciente en Número no puede pisar el estado despues
                // de revertir o de aplicar: se descarta el pendiente.
                clearTimeout(debounceNumero);
                // Cerrar / Esc / backdrop revierten; Aplicar no.
                if (aplicado) return;
                Object.assign(s, snapshot);
                cargarComprobantes();
            },
        });

        const $ = (sel) => m.backdrop.querySelector(sel);
        $('#cf-f-orden').value = s.orden;
        $('#cf-f-dir').value   = s.dir;

        const aplicarEnVivo = () => { pintarBadgeFiltrosComprobantes(); cargarComprobantes(); };

        $('#cf-f-codigo').addEventListener('input',  (e) => { s.codigo = e.target.value.trim(); aplicarEnVivo(); });
        $('#cf-f-numero').addEventListener('input',  (e) => {
            const v = e.target.value.trim();
            clearTimeout(debounceNumero);
            debounceNumero = setTimeout(() => { s.numero = v; aplicarEnVivo(); }, 300);
        });
        $('#cf-f-desde').addEventListener('change',  (e) => { s.desde  = e.target.value;        aplicarEnVivo(); });
        $('#cf-f-hasta').addEventListener('change',  (e) => { s.hasta  = e.target.value;        aplicarEnVivo(); });
        $('#cf-f-limite').addEventListener('change', (e) => { s.limite = +e.target.value || 100; aplicarEnVivo(); });
        $('#cf-f-orden').addEventListener('change',  (e) => { s.orden  = e.target.value;        aplicarEnVivo(); });
        $('#cf-f-dir').addEventListener('change',    (e) => { s.dir    = e.target.value;        aplicarEnVivo(); });

        $('#cf-f-estado').addEventListener('click', (e) => {
            const b = e.target.closest('[data-valor]');
            if (!b) return;
            s.estado = b.dataset.valor;
            m.backdrop.querySelectorAll('#cf-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x === b));
            aplicarEnVivo();
        });

        $('[data-act="limpiar"]').addEventListener('click', () => {
            clearTimeout(debounceNumero);
            Object.assign(s, COMPROBANTES_DEFAULTS);
            $('#cf-f-codigo').value = '';
            $('#cf-f-numero').value = '';
            $('#cf-f-desde').value  = '';
            $('#cf-f-hasta').value  = '';
            $('#cf-f-limite').value = COMPROBANTES_DEFAULTS.limite;
            $('#cf-f-orden').value  = COMPROBANTES_DEFAULTS.orden;
            $('#cf-f-dir').value    = COMPROBANTES_DEFAULTS.dir;
            m.backdrop.querySelectorAll('#cf-f-estado .filter-chip')
                .forEach((x) => x.classList.toggle('active', x.dataset.valor === COMPROBANTES_DEFAULTS.estado));
            aplicarEnVivo();
        });

        $('[data-act="aplicar"]').addEventListener('click', () => { aplicado = true; m.close(); });
    }

    /* =========================================================
     * Modulo: Dashboard
     * Muestra el inventario de la cuenta: cuantos usuarios, dispositivos
     * y chips tiene asociados el dominio de la sesion. Los numeros los
     * cuenta el backend (api/dashboard.php -> requireDominioId()); aca
     * solo se muestra de que dominio se trata.
     * ======================================================= */

    function renderDashboard(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-header">
                    <h2 class="module-title">Dashboard</h2>
                    <p class="module-subtitle">
                        Inventario asociado al dominio <strong>${dominio}</strong>.
                    </p>
                </div>

                <div class="stats-bar" id="db-stats"></div>
                <div id="db-error"></div>
            </div>
        `;

        cargarDashboard();
    }

    // `icono` es una clase FontAwesome (ej. 'fa-users'), no un emoji: el
    // helper arma el <i> para que ningun call site inyecte HTML crudo.
    //
    // El icono va como marca de agua a la derecha (.stat-card-watermark), no
    // pegado a la etiqueta: es decorativo, por eso aria-hidden -- el lector de
    // pantalla ya tiene la etiqueta y el valor.
    //
    // La tarjeta es un <a> al modulo que resume. Alcanza con el href porque el
    // router es hash-based (escucha `hashchange`), asi que no hace falta
    // handler: el link sirve tambien para "abrir en pestaña nueva" y para
    // navegar con teclado.
    function dashboardStat(label, valor, icono, ruta) {
        return `<a href="#/${ruta}" class="stat-card stat-card-watermark stat-card-link">
            <span class="stat-label">${escapeHtml(label)}</span>
            <span class="stat-value">${valor}</span>
            <i class="fa-solid ${icono} stat-icon" aria-hidden="true"></i>
        </a>`;
    }

    async function cargarDashboard() {
        const stats = document.getElementById('db-stats');
        const error = document.getElementById('db-error');
        if (!stats) return;

        stats.innerHTML = dashboardStat('Usuarios', '…', 'fa-users', 'usuarios')
                        + dashboardStat('Dispositivos', '…', 'fa-microchip', 'dispositivos')
                        + dashboardStat('Chips', '…', 'fa-sim-card', 'chips');
        error.innerHTML = '';

        try {
            const data = await api('api/dashboard.php');
            const t    = data.totales || {};
            stats.innerHTML = dashboardStat('Usuarios', t.usuarios ?? 0, 'fa-users', 'usuarios')
                            + dashboardStat('Dispositivos', t.dispositivos ?? 0, 'fa-microchip', 'dispositivos')
                            + dashboardStat('Chips', t.chips ?? 0, 'fa-sim-card', 'chips');
        } catch (err) {
            stats.innerHTML = '';
            error.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
        }
    }

    /* =========================================================
     * Modulo Facturacion (ficha unica, no ABM)
     * Portado de reactor-panel/comprobantes/facturacion.php: edita los datos
     * fiscales y de contacto del cliente al que se le facturan los servicios
     * del dominio. No hay listado ni alta -- el registro lo resuelve el
     * backend desde `dominios.cliente` (api/facturacion.php), aca no viaja
     * ningun id. Por eso la edicion es en pantalla y no en modal.
     *
     * La ficha entera vive en UNA tarjeta (.form-card) y adentro cada campo
     * es una tarjeta chica mas oscura (.view-card), igual que el modal de
     * Consultar de los ABM. Arranca en modo lectura; el boton "Editar" del
     * pie cambia los valores por inputs SIN mover la distribucion (mismas
     * tarjetas, mismos anchos) y ofrece Cancelar / Guardar.
     * ======================================================= */

    const facturacion = { cliente: null, condiciones: [], modo: 'ver' };

    function renderFacturacion(container) {
        const dominio = sesion.dominio_nombre
            ? escapeHtml(sesion.dominio_nombre)
            : (sesion.dominio ? `#${sesion.dominio}` : 'sin dominio asignado');

        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-calculator"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        Los datos de facturación son la identificación fiscal y de contacto
                        del cliente al que se le emiten los comprobantes del dominio
                        <strong>${dominio}</strong>. Lo que se guarde acá se usa en los
                        comprobantes que se emitan de ahora en más, no en los ya emitidos.
                    </div>
                </div>

                <div id="fc-aviso"></div>
                <div id="fc-ficha">
                    <div class="form-card"><div class="form-card-loading">Cargando…</div></div>
                </div>
            </div>
        `;

        cargarFacturacion();
    }

    async function cargarFacturacion() {
        const ficha = document.getElementById('fc-ficha');
        const aviso = document.getElementById('fc-aviso');
        if (!ficha) return;

        ficha.innerHTML = '<div class="form-card"><div class="form-card-loading">Cargando…</div></div>';
        aviso.innerHTML = '';

        try {
            const data = await api('api/facturacion.php');
            facturacion.cliente     = data.cliente     || null;
            facturacion.condiciones = data.condiciones || [];
            facturacion.modo        = 'ver';
        } catch (err) {
            ficha.innerHTML = '';
            aviso.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            return;
        }

        pintarFacturacion();
    }

    /* Tarjeta chica editable: mismo contenedor que viewCard() (mismo fondo
       oscuro y mismo ancho), con un control en lugar del valor. Asi el paso
       de lectura a edicion no mueve nada de lugar. */
    function editCard(label, id, controlHtml, full = false) {
        return `<div class="view-card ${full ? 'view-card-full' : 'view-card-half'}">
            <label class="view-card-label" for="${id}">${escapeHtml(label)}</label>
            ${controlHtml}
        </div>`;
    }

    function fichaFacturacionLectura(c) {
        return [
            viewCard('Razón social',     escapeHtml(c.razon), true),
            viewCard('Condición fiscal', c.condicion ? escapeHtml(c.condicion_texto || c.condicion) : ''),
            viewCard('CUIT',             c.cuit ? `<code>${escapeHtml(c.cuit)}</code>` : ''),
            viewCard('Contacto',         escapeHtml(c.contacto)),
            viewCard('Celular',          escapeHtml(c.celular)),
            viewCard('Correo',           escapeHtml(c.correo), true),
        ].join('');
    }

    function fichaFacturacionEdicion(c) {
        const opciones = ['<option value="">— Sin especificar —</option>'].concat(
            facturacion.condiciones.map((o) =>
                `<option value="${escapeHtml(o.valor)}"${o.valor === c.condicion ? ' selected' : ''}>${escapeHtml(o.texto)}</option>`)
        ).join('');

        return [
            editCard('Razón social *', 'fc-razon',
                `<input type="text" id="fc-razon" maxlength="255" value="${escapeHtml(c.razon)}">`, true),
            editCard('Condición fiscal', 'fc-condicion',
                `<select id="fc-condicion">${opciones}</select>`),
            editCard('CUIT', 'fc-cuit',
                `<input type="text" id="fc-cuit" maxlength="13" inputmode="numeric"
                        placeholder="11 dígitos" value="${escapeHtml(c.cuit)}">`),
            editCard('Contacto', 'fc-contacto',
                `<input type="text" id="fc-contacto" maxlength="255" value="${escapeHtml(c.contacto)}">`),
            editCard('Celular', 'fc-celular',
                `<input type="tel" id="fc-celular" maxlength="255" value="${escapeHtml(c.celular)}">`),
            editCard('Correo', 'fc-correo',
                `<input type="email" id="fc-correo" maxlength="255" value="${escapeHtml(c.correo)}">`, true),
        ].join('');
    }

    function pintarFacturacion() {
        const ficha = document.getElementById('fc-ficha');
        const aviso = document.getElementById('fc-aviso');
        if (!ficha) return;

        const c = facturacion.cliente;
        if (!c) {
            ficha.innerHTML = '';
            aviso.innerHTML = `<div class="alert alert-warn">
                El dominio no tiene una ficha de cliente asociada, así que no hay
                datos de facturación para editar. Pedile a un administrador que la cargue.
            </div>`;
            return;
        }

        const editando = facturacion.modo === 'editar';

        // En edicion la tarjeta es un <form> para que Enter guarde, como el
        // submit del legacy; en lectura alcanza un <div>.
        const tag  = editando ? 'form' : 'div';
        const pie  = editando
            ? `<button type="button" class="btn btn-ghost" data-act="cancelar">Cancelar</button>
               <button type="submit" class="btn btn-primary" data-act="guardar">
                   <i class="fa-solid fa-floppy-disk"></i> Guardar
               </button>`
            : `<button type="button" class="btn btn-primary" data-act="editar">
                   <i class="fa-solid fa-pen"></i> Editar
               </button>`;

        aviso.innerHTML = '';
        ficha.innerHTML = `
            <${tag} class="form-card" id="fc-card"${editando ? ' autocomplete="off"' : ''}>
                <div class="form-card-head">
                    <h3 class="form-card-title">Datos de facturación</h3>
                    <span class="form-card-hint">
                        Cliente <code>#${c.id}</code>${c.nombre ? ' · ' + escapeHtml(c.nombre) : ''}
                    </span>
                </div>

                <div class="view-grid">
                    ${editando ? fichaFacturacionEdicion(c) : fichaFacturacionLectura(c)}
                </div>

                <div class="field-error" id="fc-error" style="display:none"></div>

                <div class="form-card-foot">${pie}</div>
            </${tag}>
        `;

        const card = ficha.querySelector('#fc-card');
        if (editando) {
            card.addEventListener('submit', (e) => {
                e.preventDefault();
                guardarFacturacion();
            });
            card.querySelector('[data-act="cancelar"]').addEventListener('click', () => {
                facturacion.modo = 'ver';
                pintarFacturacion();
            });
            card.querySelector('#fc-razon').focus();
        } else {
            card.querySelector('[data-act="editar"]').addEventListener('click', () => {
                facturacion.modo = 'editar';
                pintarFacturacion();
            });
        }
    }

    async function guardarFacturacion() {
        const card = document.getElementById('fc-card');
        if (!card || !facturacion.cliente) return;

        const err   = card.querySelector('#fc-error');
        const btn   = card.querySelector('[data-act="guardar"]');
        const valor = (id) => card.querySelector(id).value.trim();

        const payload = {
            razon:     valor('#fc-razon'),
            condicion: card.querySelector('#fc-condicion').value,
            cuit:      valor('#fc-cuit'),
            contacto:  valor('#fc-contacto'),
            celular:   valor('#fc-celular'),
            correo:    valor('#fc-correo'),
        };

        btn.disabled = true;
        try {
            const data = await api('api/facturacion.php', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            // Se repinta con lo que devolvio el backend: el CUIT vuelve
            // normalizado a digitos y los vacios como cadena vacia.
            facturacion.cliente = data.cliente || facturacion.cliente;
            facturacion.modo    = 'ver';
            pintarFacturacion();
            toast('Datos de facturación actualizados');
        } catch (e) {
            err.textContent   = e.message;
            err.style.display = '';
            btn.disabled      = false;
        }
    }

    /* =========================================================
     * Modulo Dominio (ficha unica de solo lectura, no ABM)
     * Portado de reactor-panel/dominio/inicio.php: la tarjeta con los datos
     * del dominio con el que esta conectada la sesion. No hay listado ni id
     * en la URL -- el registro lo resuelve el backend desde el JWT
     * (api/dominio.php -> requireDominioId()).
     *
     * A diferencia de Facturacion, esta ficha NO se edita: el alta y la
     * modificacion del dominio son del back office interno. Por eso la
     * tarjeta no lleva .form-card-foot con "Editar".
     *
     * Los contadores de usuarios / dispositivos / chips los calcula el
     * backend con COUNT(*): las columnas cacheadas `dominios.usuarios` etc.
     * que leia el legacy estan desfasadas.
     * ======================================================= */

    const dominioFicha = { dominio: null, totales: null };

    function renderDominio(container) {
        container.innerHTML = `
            <div class="section">
                <div class="module-help" style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;box-shadow:var(--shadow);display:flex;gap:14px;align-items:center">
                    <div class="module-help-icon"><i class="fa-solid fa-flag"></i></div>
                    <div style="font-size:.88rem;color:var(--muted);line-height:1.45">
                        El dominio es la cuenta a la que pertenecen tus usuarios, dispositivos
                        y chips: todo lo que ves en el panel sale filtrado por él. Estos datos
                        los administra Reactor, así que desde acá se consultan pero no se editan.
                    </div>
                </div>

                <div id="dm-aviso"></div>
                <div id="dm-ficha">
                    <div class="form-card"><div class="form-card-loading">Cargando…</div></div>
                </div>
            </div>
        `;

        cargarDominio();
    }

    /* 1 Normal / 2 Limitado / 3 Suspendido (combo '$xDominio->situacion').
       El texto lo manda el backend; aca solo se elige el tono del badge. */
    const DOMINIO_SITUACION_TONO = { '1': 'success', '2': 'warn', '3': 'danger' };

    function badgeSituacion(codigo, texto) {
        const t = String(texto || '').trim();
        if (t === '') return null;
        const tono = DOMINIO_SITUACION_TONO[String(codigo)] || 'info';
        return `<span class="badge badge-${tono}">${escapeHtml(t)}</span>`;
    }

    async function cargarDominio() {
        const ficha = document.getElementById('dm-ficha');
        const aviso = document.getElementById('dm-aviso');
        if (!ficha) return;

        ficha.innerHTML = '<div class="form-card"><div class="form-card-loading">Cargando…</div></div>';
        aviso.innerHTML = '';

        try {
            const data = await api('api/dominio.php');
            dominioFicha.dominio = data.dominio || null;
            dominioFicha.totales = data.totales || null;
        } catch (err) {
            ficha.innerHTML = '';
            aviso.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            return;
        }

        pintarDominio();
    }

    function pintarDominio() {
        const ficha = document.getElementById('dm-ficha');
        if (!ficha) return;

        const d = dominioFicha.dominio;
        const t = dominioFicha.totales || {};
        if (!d) return;

        ficha.innerHTML = `
            <div class="form-card">
                <div class="form-card-head">
                    <h3 class="form-card-title">Datos del dominio</h3>
                </div>

                <div class="view-grid">
                    ${viewCard('Nombre', escapeHtml(d.nombre), 'third')}
                    ${viewCard('Situación', badgeSituacion(d.situacion, d.situacion_texto), 'third')}
                    ${viewCard('Estado', badgeHabilitado(d.habilitado === 1), 'third')}
                    ${viewCard('Usuarios', formatNumero(t.usuarios ?? 0), 'third')}
                    ${viewCard('Dispositivos', formatNumero(t.dispositivos ?? 0), 'third')}
                    ${viewCard('Chips', formatNumero(t.chips ?? 0), 'third')}
                </div>
            </div>
        `;
    }

    /* ---------- router ---------- */
    const viewEl  = document.getElementById('view');
    const titleEl = document.getElementById('view-title');

    // Cada modulo futuro se registra aca: `route: { title, render(container) }`.
    const routes = {
        dashboard: {
            title: 'Dashboard',
            render: renderDashboard,
        },
        dominio: {
            title: 'Dominio',
            render: renderDominio,
        },
        usuarios: {
            title: 'Usuarios',
            render: renderUsuarios,
        },
        dispositivos: {
            title: 'Dispositivos',
            render: renderDispositivos,
        },
        actividad: {
            title: 'Actividad',
            render: renderActividad,
        },
        invitaciones: {
            title: 'Invitaciones',
            render: renderInvitaciones,
        },
        chips: {
            title: 'Chips',
            render: renderChips,
        },
        facturas: {
            title: 'Facturas',
            render: (c) => renderComprobantes(c, 'F'),
        },
        recibos: {
            title: 'Recibos',
            render: (c) => renderComprobantes(c, 'R'),
        },
        facturacion: {
            title: 'Facturación',
            render: renderFacturacion,
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
