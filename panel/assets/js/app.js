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
            window.location.href = 'login';
            throw new Error('Sesion expirada');
        }
        let body = null;
        try { body = await res.json(); } catch (_) { body = null; }
        // 403 con `motivo: 'perfil'` = la sesion perdio su perfil habilitado
        // (se lo revocaron con la sesion abierta). No es un error de la
        // pantalla que lo pidio, asi que no va como toast: se vuelve al login,
        // que explica por que no se puede entrar. Los demas 403 SI son de
        // negocio ("El usuario esta deshabilitado") y siguen su camino normal.
        if (res.status === 403 && body && body.motivo === 'perfil') {
            window.location.href = 'login?motivo=perfil';
            throw new Error(body.error || 'Acceso denegado');
        }
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

    /* Tarjeta read-only EN FILA: el nombre (con su glosa debajo) a la izquierda
       y la pildora de estado a la derecha. Portada de cloud (`viewCardEstado()`
       en cloud/assets/js/app.js), donde la usan las mismas dos pestañas.

       Es para las listas de "esto lo tiene / esto no" -- los permisos y los
       paneles del perfil --, donde la pregunta no es que dice cada tarjeta sino
       cuales estan prendidas. Con la pildora a la derecha las tres o siete
       quedan alineadas en la misma columna y eso se contesta de un vistazo;
       apiladas hay que leer tarjeta por tarjeta, porque el badge arranca donde
       termine el rotulo de arriba y nunca dos en el mismo x.

       Va SIEMPRE `full`: la fila es el renglon entero, asi que la paridad de la
       grilla no entra en juego y no hay ranura impar que calcular.

       `detalleHtml` entra como HTML -- lo escapa quien llama -- y es OPCIONAL:
       sin el no se dibuja el `.view-card-value`. Ojo que aca NO cae al `DASH`
       como `viewCard()`: un guion es "este campo no tiene valor", y la glosa no
       es un campo sino la aclaracion del rotulo. Los rotulos del estado son los
       de siempre -- `habilitado` es 1 o 0 y nada mas --, por eso los fija la
       tarjeta y no cada call site. */
    function viewCardEstado(label, detalleHtml, activo) {
        return `<div class="view-card view-card-full view-card-row">
            <div class="view-card-main">
                <div class="view-card-label">${escapeHtml(label)}</div>
                ${detalleHtml ? `<div class="view-card-value">${detalleHtml}</div>` : ''}
            </div>
            <span class="badge ${activo ? 'badge-success' : 'badge-danger'}">${activo ? 'Habilitado' : 'Deshabilitado'}</span>
        </div>`;
    }

    /* Valor de una referencia a otra tabla: el nombre si el JOIN lo trajo y
       el id entre `code` si no (usuario borrado, equipo que cambio de dueño,
       catalogo incompleto). Vacio si la fila no apunta a nada -- ahi
       `viewCard` pone el guion. */
    function ref(nombre, id) {
        if (nombre) return escapeHtml(nombre);
        return id ? `<code>#${id}</code>` : '';
    }

    /* Modal generico: recibe titulo + HTML del body y lo monta sobre un
       backdrop efimero que se destruye al cerrar.

       FORMATO UNICO (skill `abm_design`): barra de titulo pintada en primario
       + barra de acciones debajo con TODOS los botones, y SIN footer. La
       salida va primera y es el unico boton neutro; el resto va en primario.
       Los call sites siguen pasando su HTML por `footerHtml` / `primaryHtml`
       (los nombres quedaron por compatibilidad) y la barra los normaliza al
       tamano chico via CSS.

       opts.wide        -> modal ancho: true = 880px (dumps, tablas), 'xl' =
                           1040px, el doble del ancho base (fichas largas)
       opts.footerHtml  -> botones extra, entre la salida y la accion primaria
       opts.primaryHtml -> accion(es) primaria(s), al final de la barra
       opts.closeLabel  -> etiqueta de la salida (default "Cerrar"; "Cancelar"
                           en formularios, filtros y confirmaciones)
       opts.confirmacion-> true para el dialogo de confirmacion: conserva la
                           forma vieja (cabecera neutra + botones abajo). Es la
                           excepcion declarada del estandar -- una confirmacion
                           es una pregunta, no una pantalla, y su boton rojo se
                           queda lejos de la salida a proposito.
       opts.onClose     -> callback al cerrar por cualquier via */
    function openModal(title, bodyHtml, opts = {}) {
        const backdrop = document.createElement('div');
        backdrop.className = 'modal-backdrop';
        const salida = escapeHtml(opts.closeLabel || 'Cerrar');
        const cabecera = opts.confirmacion
            ? `<div class="modal-header">
                   <div class="modal-title">${title}</div>
                   <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
               </div>`
            : `<div class="modal-header modal-header-primary">
                   <div class="modal-title">${title}</div>
                   <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
               </div>
               <div class="modal-menubar" role="toolbar" aria-label="Acciones del modal">
                   <button class="btn btn-ghost" data-act="close">
                       <i class="fa-solid fa-xmark"></i> ${salida}
                   </button>
                   ${opts.footerHtml || ''}
                   ${opts.primaryHtml || ''}
               </div>`;
        const pie = opts.confirmacion
            ? `<div class="modal-footer">
                   ${opts.footerHtml || ''}
                   <button class="btn btn-ghost" data-act="close">${salida}</button>
                   ${opts.primaryHtml || ''}
               </div>`
            : '';
        backdrop.innerHTML = `
            <div class="modal ${opts.wide === true ? 'modal-wide' : opts.wide ? `modal-${opts.wide}` : ''}" role="dialog" aria-modal="true">
                ${cabecera}
                <div class="modal-body">${bodyHtml}</div>
                ${pie}
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
     * dominio como titulo y el nombre del perfil abajo. Al elegir uno, el POST asienta la
     * seleccion en `usuarios` y reemite el JWT: la sesion arranca de nuevo en
     * ese dominio sin pedir credenciales, y el reload muestra el panel ya
     * filtrado. Porta reactor-panel/sesion/cambiar.php del legacy.
     *
     * SOLO VIENEN LOS DOMINIOS DONDE LA CUENTA ES ADMINISTRADORA: es la misma
     * regla con la que se entra al panel, aplicada en el backend. Un dominio
     * donde la persona es Operadora no aparece — y si igual se manda su id, el
     * POST lo rechaza. Por eso todas las filas son elegibles: ya no existe el
     * caso "dominio activo sin perfil". */
    const btnDominio = document.getElementById('btn-dominio');
    if (btnDominio) {
        btnDominio.addEventListener('click', async (e) => {
            e.preventDefault();
            if (userMenu) userMenu.classList.remove('open');
            try {
                const d = await api('api/dominios');
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
                Tu cuenta no tiene perfil de Administrador en ningún dominio. Pedile a
                un administrador que te asigne uno.
            </div>`;
        }

        const filas = items.map((x) => {
            const tags = [];
            if (x.actual)      tags.push('<span class="badge badge-success">Actual</span>');
            if (!x.habilitado) tags.push('<span class="badge badge-danger">Deshabilitado</span>');

            return `<button type="button" class="dominio-item${x.actual ? ' is-actual' : ''}"
                            data-perfil="${x.perfil}">
                <span class="dominio-item-icon"><i class="fa-solid fa-building"></i></span>
                <span class="dominio-item-body">
                    <span class="dominio-item-nombre">${escapeHtml(x.nombre || `#${x.dominio}`)}</span>
                    <span class="dominio-item-meta">${escapeHtml(x.perfil_nombre || "")}</span>
                </span>
                <span class="dominio-item-tags">${tags.join('')}</span>
            </button>`;
        }).join('');

        return `<p class="modal-note">
            Estos son los dominios donde tu cuenta tiene perfil de Administrador.
            El del perfil que elijas es el que filtra toda la información del panel.
        </p>
        <div class="dominio-list">${filas}</div>`;
    }

    async function cambiarDominio(perfilId, backdrop) {
        const lista = backdrop.querySelector('.dominio-list');
        if (lista) lista.classList.add('is-busy');
        try {
            await api('api/dominios', {
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
                const u = await api('api/perfil');
                const m = openModal('Mi cuenta', viewGridPerfil(u), {
                    footerHtml: '<button class="btn btn-primary" data-act="entorno"><i class="fa-solid fa-server"></i> Entorno</button>',
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
            const data = await api('api/entorno');
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
                await fetch('api/logout', { method: 'POST', credentials: 'same-origin' });
            } catch (_) { /* noop */ }
            window.location.href = 'login';
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
     * que muestra el panel se filtra por ese dominio.
     *
     * `permisos` son los tres del perfil (`operacion` / `invitacion` /
     * `facturacion`), resueltos contra la base en cada carga del shell. Solo
     * sirven para NO DIBUJAR lo que el backend va a rechazar: el control de
     * acceso real es `requirePermisoPanel()` en el endpoint. Si el tag no vino
     * —pagina servida por una version vieja del shell— los tres quedan en
     * `false`, que es el default seguro: sin dato no hay permiso. */
    const sesion = (() => {
        const tag = document.getElementById('panel-sesion');
        let ctx = {};
        if (tag) {
            try { ctx = JSON.parse(tag.textContent || '{}') || {}; } catch (_) { ctx = {}; }
        }
        ctx.permisos = Object.assign(
            { operacion: false, invitacion: false, facturacion: false },
            ctx.permisos || {}
        );
        return ctx;
    })();

    /** ¿La sesion tiene ese permiso? Unica lectura de `sesion.permisos`. */
    function puede(permiso) {
        return sesion.permisos[permiso] === true;
    }

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
            // Excepcion del estandar de modales: una confirmacion es una
            // pregunta, no una pantalla. Conserva cabecera neutra y los dos
            // botones abajo, con el rojo lejos de la salida.
            confirmacion: true,
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
     *
     * CADA FILA ES UN PERFIL, NO UNA CUENTA. Lo que lista el modulo es quien
     * tiene acceso a este dominio, y eso vive en `perfiles`: `usuarios.dominio`
     * es solo el dominio ACTIVO de la cuenta (el ultimo que la persona uso), no
     * la lista de dominios a los que puede entrar. Por lo mismo el `Estado` de
     * la fila es `perfiles.habilitado` y no `usuarios.habilitado` — el de la
     * cuenta se ve en el modal de Consultar, como dato aparte.
     *
     * El listado siempre sale acotado al dominio de la sesion: el filtro
     * lo aplica el backend (api/usuarios.php -> requireDominioId()), aca
     * solo se muestra de que dominio se trata.
     * ======================================================= */

    const USUARIOS_DEFAULTS = { estado: 'todos', limite: 100, orden: 'id', dir: 'desc' };

    const usuarios = {
        q: '',
        ...USUARIOS_DEFAULTS,
        filas: [],
        resumen: null,
    };

    function usuariosFiltrosActivos() {
        let n = 0;
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
                        Cada fila es un <strong>perfil</strong>: el acceso de una persona al dominio
                        <strong>${dominio}</strong>. Una misma persona puede tener más de un perfil,
                        y el estado que se muestra es el del perfil — no el de su cuenta.
                    </div>
                </div>

                <div class="stats-bar" id="us-stats"></div>

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <i class="fa-solid fa-magnifying-glass search-icon"></i>
                            <input type="search" id="us-quick" class="search-input"
                                   placeholder="Buscar usuario, nombre, correo, celular o perfil…">
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
                                <th>Usuario</th>
                                <th>Nombre</th>
                                <th>Correo</th>
                                <th>Celular</th>
                                <th>Estado</th>
                                <th>Último ingreso</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="us-tbody">
                            <tr><td colspan="7" class="table-empty">Cargando…</td></tr>
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
        // El alta no crea la cuenta: invita. Reusa el modal del modulo
        // Invitaciones (`formInvitacion()`), que pide solo el correo y encola
        // el envio. Si la persona ya tiene cuenta, al aceptar se le agrega el
        // perfil de este dominio en vez de crear un usuario nuevo.
        container.querySelector('#us-nuevo').addEventListener('click', () => formInvitacion());

        cargarUsuarios();
    }

    async function cargarUsuarios() {
        const tbody = document.getElementById('us-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:      usuarios.q,
            estado: usuarios.estado,
            limite: usuarios.limite,
            orden:  usuarios.orden,
            dir:    usuarios.dir,
        });

        try {
            const data = await api(`api/usuarios?${qs}`);
            usuarios.filas   = data.perfiles || [];
            usuarios.resumen = data.resumen  || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsUsuarios();
        pintarBadgeFiltrosUsuarios();

        if (usuarios.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">No hay perfiles que coincidan con la búsqueda.</td></tr>';
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
            <div class="stat-card"><span class="stat-label">Perfiles del dominio</span><span class="stat-value">${r.total}</span></div>
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

    // `u.habilitado` es el del PERFIL (la fila). El de la cuenta viaja en
    // `u.usuario_habilitado` y se muestra recien en el modal de Consultar.
    function filaUsuario(u) {
        const estado = u.habilitado
            ? '<span class="badge badge-success">Habilitado</span>'
            : '<span class="badge badge-danger">Deshabilitado</span>';
        return `
            <tr data-id="${u.id}" class="row-clickable">
                <td>${u.usuario ? escapeHtml(u.usuario) : DASH}</td>
                <td class="td-nombre">${u.nombre ? escapeHtml(u.nombre) : DASH}</td>
                <td>${u.correo  ? escapeHtml(u.correo)  : DASH}</td>
                <td>${u.celular ? escapeHtml(u.celular) : DASH}</td>
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

    // Menu contextual de fila, en el orden del skill abm_design: Consultar ->
    // accion propia del recurso (Habilitar/Deshabilitar) -> separador ->
    // Editar -> Eliminar (destructiva, al final). Todas trabajan sobre el
    // PERFIL de la fila, no sobre la cuenta.
    //
    // Sobre el perfil de la propia sesion quedan `Consultar` y `Editar`, sin las
    // otras dos: deshabilitarlo o borrarlo cierra el panel en el request
    // siguiente y el backend corta con 409, asi que ofrecerlas seria ofrecer un
    // error. EDITARLO SI SE PUEDE: `tipo` y los paneles no gatean el panel —el
    // gate solo mira `habilitado`— y el editor deja el switch de estado
    // bloqueado, que es lo unico que podria cerrarle la puerta a la sesion.
    function menuUsuario(u) {
        if (u.es_propio) {
            return [
                { label: 'Consultar', icon: 'fa-eye', onSelect: () => verUsuario(u.id) },
                { sep: true },
                { label: 'Editar',    icon: 'fa-pen', onSelect: () => formPerfil(u.id) },
            ];
        }
        return [
            { label: 'Consultar', icon: 'fa-eye',    onSelect: () => verUsuario(u.id) },
            {
                label: u.habilitado ? 'Deshabilitar' : 'Habilitar',
                icon:  u.habilitado ? 'fa-user-slash' : 'fa-user-check',
                onSelect: () => toggleUsuario(u),
            },
            { sep: true },
            { label: 'Editar',   icon: 'fa-pen',   onSelect: () => formPerfil(u.id) },
            { label: 'Eliminar', icon: 'fa-trash', danger: true, onSelect: () => eliminarUsuario(u) },
        ];
    }

    /* ---------- Consultar ----------
     * TRES PESTAÑAS, las mismas que el editor: `General` (los datos del acceso y
     * de la persona), `Permisos` (los tres de `perfiles`) y `Paneles` (los de
     * `perfiles_paneles` contra el catalogo del dominio). Antes era una sola
     * grilla con los permisos abajo de una divisoria; con la solapa cada bloque
     * se mira por separado y la paridad de cada uno se resuelve sola.
     *
     * LAS TRES SALEN DEL MISMO `GET ?id=N` que ya usaba el editor —trae la
     * ficha, los paneles del perfil y el catalogo del dominio—, asi que ninguna
     * se carga bajo demanda: no hay una segunda consulta que ahorrar.
     *
     * LAS TRES SOLAPAS ESTAN SIEMPRE, aunque el perfil no tenga ningun permiso o
     * el dominio ningun panel. Que una pestaña aparezca y desaparezca segun la
     * fila hace saltar el modal y deja al que mira sin saber si falta la pestaña
     * o si no hay dato (mismo criterio que Consultar registro de Actividad). Es
     * la diferencia con el EDITOR, donde `Permisos` no existe si no hay ninguno
     * para otorgar: alla la solapa vacia no seria un dato de la fila sino una
     * pantalla sin nada que hacer.
     *
     * PERMISOS Y PANELES SE DIBUJAN IGUAL, con la tarjeta en fila de cloud
     * (`viewCardEstado()`): nombre a la izquierda y pildora `Habilitado` /
     * `Deshabilitado` a la derecha. Las dos contestan la misma pregunta —que
     * tiene prendido este perfil— y resolverla con dos formas distintas obliga a
     * releer la segunda. Solo Paneles conserva estado vacio, para el dominio sin
     * ningun panel habilitado; los tres permisos estan siempre. */
    async function verUsuario(id) {
        let data;
        try {
            data = await api(`api/usuarios?id=${id}`);
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const u        = data.perfil;
        const catalogo = (data.catalogos && data.catalogos.paneles) || [];

        const badge = (ok, si, no) => ok
            ? `<span class="badge badge-success">${si}</span>`
            : `<span class="badge badge-danger">${no}</span>`;

        /* 12 tarjetas: la ficha abre por el acceso (`Perfil` + `Tipo`, que es lo
           que la fila representa) y cierra por los dos estados, que son
           independientes entre si — una cuenta deshabilitada no entra ni con el
           perfil habilitado, y al reves el perfil deshabilitado solo cierra
           ESTE dominio. Todas van a media tarjeta, asi que los seis renglones
           cierran de a dos; agregar o quitar un campo deja la cuenta impar y
           estira la ultima a todo el ancho.

           `Tipo` ocupa la ranura que tenia `Rol` hasta el 06/09/2026, cuando se
           elimino `perfiles`.`rol`. No es un reemplazo conceptual —`tipo` es la
           columna A/O que lee el legacy, no un rol— pero es el unico atributo
           del perfil que queda ademas del nombre, y la cuenta tenia que seguir
           siendo par. */
        const general = `<div class="view-grid">${[
            viewCard('Perfil',             u.perfil_nombre ? escapeHtml(u.perfil_nombre) : ''),
            viewCard('Tipo',               u.tipo === 'A' ? 'Administrador' : (u.tipo === 'O' ? 'Operador' : '')),
            viewCard('Usuario',            u.usuario ? escapeHtml(u.usuario) : ''),
            viewCard('Nombre',             u.nombre  ? escapeHtml(u.nombre)  : ''),
            viewCard('Correo',             u.correo  ? escapeHtml(u.correo)  : ''),
            viewCard('Celular',            u.celular ? escapeHtml(u.celular) : ''),
            viewCard('Identificador',      u.usuario_uuid ? `<code>${escapeHtml(u.usuario_uuid)}</code>` : ''),
            viewCard('Último ingreso',     escapeHtml(formatDate(u.ingresado) || '')),
            viewCard('Registrado',         escapeHtml(formatDate(u.registrado) || '')),
            viewCard('Registrado por',     u.registrante_nombre ? escapeHtml(u.registrante_nombre) : ''),
            viewCard('Estado del perfil',  badge(u.habilitado, 'Habilitado', 'Deshabilitado')),
            viewCard('Estado de la cuenta', badge(u.usuario_habilitado, 'Habilitada', 'Deshabilitada')),
        ].join('')}</div>`;

        /* SE LISTAN LOS TRES, tenga el perfil el permiso o no. Hasta el
           07/09/2026 se filtraba a los otorgados, con este argumento: la pestaña
           es lo que el acceso PUEDE HACER y un renglon negativo ocupa el mismo
           lugar sin agregar nada. Ya no: con la pildora a la derecha lo que se
           lee de un vistazo es la COLUMNA de estados, y para eso las tres filas
           tienen que estar — un permiso que no aparece no se distingue de un
           permiso que no existe. Es el mismo criterio que la pestaña Paneles,
           que siempre listo el catalogo entero.

           De paso desaparece el estado vacio: los tres estan siempre, asi que
           no hay caso "sin permisos" que distinguir de "la pestaña no cargo".

           NO SE FILTRA POR `puede()`, a diferencia del editor: alla se esconde
           el permiso que la sesion no tiene porque nadie otorga lo que no tiene,
           y aca no se otorga nada — esconderlo ocultaria un dato de la fila que
           se esta consultando.

           EL TEXTO SALE DE `PERMISOS_PERFIL`, el mismo catalogo que dibuja el
           editor: dos copias de la frase que dice que abre cada permiso se
           desincronizan sola. */
        const permisos = `<div class="view-grid">${PERMISOS_PERFIL.map((p) => viewCardEstado(
            p.etiqueta,
            `<span class="muted">${escapeHtml(p.detalle)}</span>`,
            !!u[p.clave],
        )).join('')}</div>`;

        /* ACA SE LISTA EL CATALOGO ENTERO, habilitados y no: es la inversa de
           Permisos y es a proposito. El catalogo son los paneles del dominio
           —una lista corta y cerrada, el mas grande de la base tiene 7— y lo que
           importa es contra que se recorta el acceso; mostrando solo los
           permitidos, un perfil con dos de siete se lee igual que uno con dos de
           dos. Los permisos, en cambio, son tres claves fijas que ya se conocen.

           `catalogoPaneles()` trae solo los HABILITADOS del dominio (dar permiso
           sobre un panel apagado no significa nada), asi que el badge de la
           tarjeta habla del PERMISO DEL PERFIL sobre el panel, no del estado del
           panel.

           POR ESO LA LISTA ES EL CATALOGO UNIDO A LO QUE EL PERFIL YA TIENE, y
           no el catalogo solo: un panel asignado que despues se deshabilito no
           esta en el catalogo y desapareceria de la ficha aunque el perfil lo
           siga teniendo en `perfiles_paneles`. Esas filas salen con `#id` por
           nombre, que es lo unico que se sabe de ellas. Mismo criterio que
           `perfilPanelesFicha()` en cloud.

           Sin paridad que calcular: `viewCardEstado()` va siempre `full`, asi
           que la ranura impar que documenta Dispositivos → General no aplica. */
        const asignados = new Set((u.paneles || []).map(Number));
        const delCatalogo = catalogo.map((p) => ({ id: Number(p.id), nombre: p.nombre }));
        const conocidos   = new Set(delCatalogo.map((p) => p.id));
        asignados.forEach((id) => {
            if (!conocidos.has(id)) delCatalogo.push({ id, nombre: '' });
        });

        // Sin segundo renglon: el `#id` que iba bajo el nombre se saco por
        // pedido. Sobrevive solo como nombre de reemplazo cuando la fila salio
        // de la union y no del catalogo, que es el unico caso sin nombre.
        const paneles = delCatalogo.length
            ? `<div class="view-grid">${delCatalogo.map((p) => viewCardEstado(
                   p.nombre || `Panel #${p.id}`,
                   '',
                   asignados.has(p.id),
               )).join('')}</div>`
            : `<div class="paneles-lista">
                   <div class="paneles-vacio">Este dominio no tiene paneles habilitados.</div>
               </div>`;

        // Los rotulos y los iconos son los del editor (`General` / `Permisos` /
        // `Paneles`): es la misma ficha en modo lectura y en modo edicion, y si
        // no coincidieran se leerian como dos pantallas distintas.
        const body = `
            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" data-tab="general" role="tab" aria-selected="true">
                    <i class="fa-solid fa-circle-info"></i> General
                </button>
                <button type="button" class="modal-tab" data-tab="permisos" role="tab" aria-selected="false">
                    <i class="fa-solid fa-key"></i> Permisos
                </button>
                <button type="button" class="modal-tab" data-tab="paneles" role="tab" aria-selected="false">
                    <i class="fa-solid fa-table-columns"></i> Paneles
                </button>
            </div>
            <div class="modal-tabpanel" data-panel="general"  role="tabpanel">${general}</div>
            <div class="modal-tabpanel" data-panel="permisos" role="tabpanel" hidden>${permisos}</div>
            <div class="modal-tabpanel" data-panel="paneles"  role="tabpanel" hidden>${paneles}</div>
        `;

        // La barra suma `Editar` como boton DIRECTO y no dentro de un
        // desplegable `Acciones`: es la unica accion de la ficha, y un menu de
        // un solo item no se justifica (misma regla que Consultar dispositivo).
        // El resto de las acciones del perfil sigue viviendo en el menu
        // contextual de la fila del listado.
        const m = openModal('Consultar perfil', body, {
            wide:        'xl',
            primaryHtml: '<button class="btn btn-primary" data-act="editar"><i class="fa-solid fa-pen-to-square"></i> Editar</button>',
        });

        montarPestanas(m.backdrop);

        m.backdrop.querySelector('[data-act="editar"]').addEventListener('click', () => {
            m.close();
            formPerfil(u.id);
        });
    }

    /* ---------- Editar perfil ----------
     * Modal de edicion con dos pestañas, portado del editor de cloud
     * (`openProfileModal()` + cloud/api/profiles.php): `General` con los campos
     * de la entidad y `Paneles` con el selector de `perfiles_paneles`.
     *
     * SE EDITAN `tipo`, `habilitado`, LOS TRES PERMISOS y los paneles. Los datos
     * de la persona (nombre, correo, celular, contraseña) son de su CUENTA y no
     * de este modulo, y el `nombre` / `uuid` del perfil los escribe la
     * invitacion que lo creo. Por eso el usuario va como campo deshabilitado:
     * da el contexto de QUE acceso se esta editando, no es un campo del
     * formulario.
     *
     * LOS PERMISOS TIENEN PESTAÑA PROPIA y no se agregan a `General`: dos de los
     * tres no son del panel sino de `app`, asi que meterlos junto a `tipo` y
     * `habilitado` los haria leer como atributos de ESTA pantalla. La pestaña
     * los agrupa y le pone a cada uno donde vale.
     *
     * NINGUNO DE LOS TRES SE OTORGA SIN TENERLO, y el que no se puede otorgar NO
     * SE DIBUJA. La lista sale de `PERMISOS_PERFIL` filtrada por `puede()`, asi
     * que quien edita solo ve los permisos que su propio perfil tiene.
     *
     * ES LA EXCEPCION A "visible y bloqueado", que sigue valiendo para el
     * `habilitado` del perfil propio. Ahi el switch queda porque el dato es del
     * perfil que se esta mirando y esconderlo haria dudar de si falta el dato;
     * aca lo que falta es del que MIRA, y un switch apagado que no se puede tocar
     * solo invita a pelearse con el. El backend corta con 403 igual: la UI no es
     * el control de acceso.
     *
     * SI NO PUEDE OTORGAR NINGUNO, LA PESTAÑA NO EXISTE. Una solapa vacia se lee
     * como una pantalla rota, igual que un agrupador vacio en el sidebar.
     *
     * NO SE MUESTRA EL DOMINIO, a diferencia de cloud. Alla el listado cruza
     * todos los dominios y el dato distingue una fila de otra; aca el panel
     * filtra todo por el dominio de la sesion, asi que la columna solo puede
     * tener un valor — el mismo criterio con el que se excluyo de la ficha de
     * Consultar y de Dispositivos → General.
     *
     * LAS DOS PESTAÑAS SE LLAMAN IGUAL QUE EN CLOUD (`General` / `Paneles`): es
     * la misma ficha en dos productos, y si los rotulos no coinciden se leen
     * como pantallas distintas. */
    async function formPerfil(id) {
        let data;
        try {
            data = await api(`api/usuarios?id=${id}`);
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const u        = data.perfil;
        const catalogo = (data.catalogos && data.catalogos.paneles) || [];
        const tipo     = u.tipo === 'A' ? 'A' : 'O';
        // El estado del perfil de la sesion no se puede tocar: apagarlo cierra
        // el panel en el request siguiente y el backend corta con 409. Se deja
        // VISIBLE Y BLOQUEADO en vez de esconderlo — un campo que desaparece
        // segun la fila hace dudar de si falta el dato o falta el permiso.
        const propio = !!u.es_propio;
        const quien  = u.usuario || u.nombre || u.perfil_nombre || `#${u.id}`;

        // LOS PERMISOS QUE ESTA SESION NO TIENE NO SE DIBUJAN. Es la unica parte
        // del modal que mira a QUIEN edita y no a la fila editada: nadie otorga
        // lo que no tiene, asi que ofrecerlo (aunque sea bloqueado) es ofrecer
        // algo que el backend va a rechazar.
        const permisos = PERMISOS_PERFIL.filter((p) => puede(p.clave));

        const body = `
            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" data-tab="general" role="tab" aria-selected="true">
                    <i class="fa-solid fa-circle-info"></i> General
                </button>
                ${permisos.length ? `<button type="button" class="modal-tab" data-tab="permisos" role="tab" aria-selected="false">
                    <i class="fa-solid fa-key"></i> Permisos
                </button>` : ''}
                <button type="button" class="modal-tab" data-tab="paneles" role="tab" aria-selected="false">
                    <i class="fa-solid fa-table-columns"></i> Paneles
                </button>
            </div>
            <div class="modal-tabpanel" data-panel="general" role="tabpanel">
                <div class="form-group">
                    <label for="up-quien">Usuario</label>
                    <input type="text" id="up-quien" value="${escapeHtml(quien)}" disabled>
                </div>
                <div class="form-group">
                    <label for="up-tipo">Tipo</label>
                    <select id="up-tipo">
                        <option value="A"${tipo === 'A' ? ' selected' : ''}>Administrador</option>
                        <option value="O"${tipo === 'O' ? ' selected' : ''}>Operador</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Habilitado</label>
                    <label class="toggle-switch" style="margin-top:6px">
                        <input type="checkbox" id="up-habilitado"${u.habilitado ? ' checked' : ''}${propio ? ' disabled' : ''}>
                        <span class="toggle-track"><span class="toggle-thumb"></span></span>
                        <span class="toggle-label" id="up-habilitado-label">${u.habilitado ? 'Sí' : 'No'}</span>
                    </label>
                    ${propio ? `<div class="form-nota">Es el perfil con el que estás trabajando: si lo deshabilitaras, perderías el acceso al panel.</div>` : ''}
                </div>
            </div>
            ${permisos.length ? `<div class="modal-tabpanel" data-panel="permisos" role="tabpanel" hidden>
                <div class="paneles-lista">
                    ${permisos.map((p) => permisoItemHtml(p.clave, p.etiqueta, p.detalle, u[p.clave])).join('')}
                </div>
            </div>` : ''}
            <!-- Sin nota introductoria, igual que la pestaña Permisos: la
                 explicacion del badge "Ultimo abierto" se saco por pedido. El
                 badge se queda -- es un dato de la fila --, y lo que hace al
                 destildarla lo sigue resolviendo olvidarPanelSinPermiso() en
                 el backend, que es donde vive la regla. -->
            <div class="modal-tabpanel" data-panel="paneles" role="tabpanel" hidden>
                <div id="up-paneles" class="paneles-wrap">${panelesListaHtml(catalogo, u.paneles)}</div>
            </div>
        `;

        const m = openModal(`Editar perfil <span class="muted">#${u.id}</span>`, body, {
            wide:        true,
            closeLabel:  'Cancelar',
            primaryHtml: '<button class="btn btn-primary" data-act="guardar"><i class="fa-solid fa-floppy-disk"></i> Guardar</button>',
        });

        montarPestanas(m.backdrop);

        const chk = m.backdrop.querySelector('#up-habilitado');
        const lbl = m.backdrop.querySelector('#up-habilitado-label');
        chk.addEventListener('change', () => { lbl.textContent = chk.checked ? 'Sí' : 'No'; });

        // VIAJAN SOLO LOS PERMISOS DIBUJADOS, y los que no estan no se tocan: el
        // PUT relee de la fila lo que no viene. Antes iban los tres siempre —el
        // bloqueado mandaba su valor sin cambios— para que el payload no
        // dependiera de quien edita; desde que el switch no existe, ese valor lo
        // inventaria el front en vez de elegirlo alguien, y si la fila cambio
        // entre el GET y el PUT lo mandaria viejo y se comeria un 403 por un
        // cambio que nadie pidio.
        //
        // Se arma DENTRO del handler: leer los controles al abrir el modal
        // mandaria siempre los valores iniciales.
        const armarPayload = () => {
            const payload = {
                id:   u.id,
                tipo: m.backdrop.querySelector('#up-tipo').value,
                // Sobre el perfil propio el checkbox va `disabled`, asi que esto
                // manda el estado sin cambios y el backend lo deja pasar: solo
                // rechaza el cambio, no el guardado.
                habilitado: chk.checked,
                paneles:    Array.from(wrap.querySelectorAll('.panel-item input:checked')).map((i) => +i.value),
            };
            permisos.forEach((p) => {
                payload[p.clave] = m.backdrop.querySelector(`#up-perm-${p.clave}`).checked;
            });
            return payload;
        };

        const guardar = m.backdrop.querySelector('[data-act="guardar"]');
        guardar.addEventListener('click', async () => {
            guardar.disabled = true;
            try {
                await api('api/usuarios', {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(armarPayload()),
                });
                toast('Perfil actualizado');
                m.close();
                cargarUsuarios();
            } catch (err) {
                // El modal NO se cierra: lo que rebota son validaciones del
                // backend, y cerrarlo perderia lo que se acaba de elegir.
                guardar.disabled = false;
                toast(err.message, { error: true });
            }
        });
    }

    /* Los tres permisos del perfil, en el orden en que se dibujan. Las claves son
       las columnas de `perfiles` y las mismas que valida `lib/permisos.php`: si
       alguna vez se agrega una cuarta, va aca y en el catalogo de PHP.

       El detalle dice DONDE vale cada uno, porque dos de los tres son de `app` y
       sin eso se leen como permisos de esta pantalla. */
    const PERMISOS_PERFIL = [
        { clave: 'operacion',   etiqueta: 'Operación',   detalle: 'Ver y usar los paneles de operación en la app.' },
        { clave: 'invitacion',  etiqueta: 'Invitación',  detalle: 'Invitar usuarios nuevos al dominio desde la app.' },
        { clave: 'facturacion', etiqueta: 'Facturación', detalle: 'Ver y abonar las facturas del servicio en este panel.' },
    ];

    /* Una fila de la pestaña Permisos: switch + nombre + que abre.
       Reusa el markup de la lista de paneles (`.paneles-lista` /
       `.toggle-switch.panel-item`) porque es exactamente la misma forma —una
       lista corta y cerrada de cosas que se prenden y se apagan— y duplicar el
       CSS para tres filas no compra nada.

       El `input` va PEGADO al `.toggle-track`: el CSS del switch pinta el estado
       con `input:checked + .toggle-track`, y cualquier nodo entre los dos lo deja
       siempre apagado.

       NO HAY ESTADO BLOQUEADO: todo lo que llega aca se puede tocar. El permiso
       que la sesion no tiene no se dibuja, asi que el filtro esta antes (ver
       `formPerfil`) y no en esta funcion. */
    function permisoItemHtml(clave, etiqueta, detalle, activo) {
        return `
            <label class="toggle-switch panel-item">
                <span class="panel-item-nombre">
                    ${escapeHtml(etiqueta)}
                    <span class="muted">${escapeHtml(detalle)}</span>
                </span>
                <input type="checkbox" id="up-perm-${clave}"${activo ? ' checked' : ''}>
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
        `;
    }

    /* Lista de paneles del perfil: un switch por panel del dominio, sin
       buscador. No reusa ningun selector con filtro a proposito — el catalogo
       son los paneles de UN dominio y el mas grande de la base tiene 7, asi que
       un buscador sobre siete filas es ruido, y el switch dice mejor que un
       checkbox que se esta prendiendo o apagando un permiso.

       El `input` va PEGADO al `.toggle-track`: el CSS del switch pinta el estado
       con `input:checked + .toggle-track`, y cualquier nodo entre los dos lo
       deja siempre apagado. Es el error facil de cometer al reordenar el markup.

       SIN EL BADGE `Ultimo abierto` (07/09/2026, pedido explicito). Marcaba la
       fila de `perfiles.panel` —el ultimo panel que ese perfil abrio en la app—
       para avisar que destildarla borra esa memoria. Se fue con la nota que lo
       explicaba, y con eso la funcion dejo de recibir el tercer parametro.
       **La regla no era del badge**: al guardar, `olvidarPanelSinPermiso()` en
       el backend sigue mandando `panel` a NULL si el perfil pierde el permiso
       sobre el panel recordado. Lo que se saco es el aviso, no el comportamiento. */
    function panelesListaHtml(catalogo, seleccion) {
        if (catalogo.length === 0) {
            return `<div class="paneles-lista">
                <div class="paneles-vacio">Este dominio no tiene paneles habilitados.</div>
            </div>`;
        }

        // Sin el `<code>#id</code>` al lado del nombre (se saco por pedido,
        // igual que en la pestaña Paneles de Consultar): el id no es un dato
        // que ayude a elegir. El `value` del checkbox lo sigue llevando, que es
        // lo unico que necesita el guardado.
        const sel   = new Set((seleccion || []).map(Number));
        const filas = catalogo.map((p) => `
            <label class="toggle-switch panel-item">
                <span class="panel-item-nombre">${escapeHtml(p.nombre) || 'Sin nombre'}</span>
                <input type="checkbox" value="${p.id}"${sel.has(Number(p.id)) ? ' checked' : ''}>
                <span class="toggle-track"><span class="toggle-thumb"></span></span>
            </label>
        `).join('');

        // Sin `Seleccionar todo` / `Deseleccionar todo` (07/09/2026, pedido
        // explicito): eran dos botones sobre una lista de a lo sumo 7 filas,
        // donde tildar a mano cuesta lo mismo. Con ellos se fue tambien el
        // `.paneles-acciones` de este control.
        return `<div class="paneles-lista">${filas}</div>`;
    }

    function copiar(texto) {
        navigator.clipboard.writeText(String(texto || ''))
            .then(() => toast('Copiado al portapapeles'))
            .catch(() => toast('No se pudo copiar', { error: true }));
    }

    /* ---------- Habilitar / Deshabilitar ----------
     * El PUT manda SOLO `{id, habilitado}` y toca solo `perfiles.habilitado`.
     * No hace falta traerse el registro antes —como sí hacía la version que
     * reescribia la fila entera de `usuarios`—: no hay ningun otro campo que
     * el guardado pueda borrar. */
    async function toggleUsuario(u) {
        try {
            await api('api/usuarios', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ id: u.id, habilitado: !u.habilitado }),
            });
            toast(u.habilitado ? 'Perfil deshabilitado' : 'Perfil habilitado');
            cargarUsuarios();
        } catch (err) {
            toast(err.message, { error: true });
        }
    }

    /* ---------- Eliminar ----------
     * Borra el PERFIL, no la cuenta: la persona pierde el acceso a este
     * dominio y conserva usuario, contraseña y los accesos que tenga en otros
     * dominios. El texto lo dice explicitamente porque la fila muestra sus
     * datos personales y "Eliminar" se lee como "borrar a la persona". */
    function eliminarUsuario(u) {
        const quien = u.usuario
            ? `<strong>${escapeHtml(u.usuario)}</strong>${u.nombre ? ` (${escapeHtml(u.nombre)})` : ''}`
            : `el perfil <strong>#${u.id}</strong>`;
        confirmarBaja(
            `¿Quitarle a ${quien} el acceso a este dominio? Se elimina el perfil
             <strong>${escapeHtml(u.perfil_nombre || `#${u.id}`)}</strong>;
             la cuenta y sus accesos a otros dominios no se tocan.
             Esta acción no se puede deshacer.`,
            async () => {
                try {
                    await api(`api/usuarios?id=${u.id}`, { method: 'DELETE' });
                    toast('Perfil eliminado');
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

        const chip = (val, label) =>
            `<button type="button" class="filter-chip${usuarios.estado === val ? ' active' : ''}" data-estado="${val}">${label}</button>`;

        const body = `
            <div class="form-group">
                <label>Estado del perfil</label>
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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
     * dominio y mismos filtros (identificador, nombre, enlace, habilitado,
     * limite) que el listado legacy. El `codigo` (el id) ya no se filtra ni
     * se muestra: es un dato interno que al cliente no le dice nada.
     * El filtro por dominio lo aplica el backend (api/dispositivos.php ->
     * requireDominioId()), aca solo se muestra de que dominio se trata.
     * ======================================================= */

    const DISPOSITIVOS_DEFAULTS = { modelo: 0, enlace: 'todos', estado: 'todos', limite: 100, orden: 'id', dir: 'desc' };

    const dispositivos = {
        q: '',
        ...DISPOSITIVOS_DEFAULTS,
        filas: [],
        catalogos: { modelos: [] },   // unico catalogo que queda: el filtro por modelo
        resumen: null,
    };

    function dispositivosFiltrosActivos() {
        let n = 0;
        if (dispositivos.modelo !== DISPOSITIVOS_DEFAULTS.modelo)         n++;
        if (dispositivos.enlace !== DISPOSITIVOS_DEFAULTS.enlace)         n++;
        if (dispositivos.estado !== DISPOSITIVOS_DEFAULTS.estado)         n++;
        if (dispositivos.limite !== DISPOSITIVOS_DEFAULTS.limite)         n++;
        if (dispositivos.orden  !== DISPOSITIVOS_DEFAULTS.orden)          n++;
        if (dispositivos.dir    !== DISPOSITIVOS_DEFAULTS.dir)            n++;
        return n;
    }

    function badgeEnlace(online) {
        return online
            ? '<span class="badge badge-success">Online</span>'
            : '<span class="badge badge-warn">Offline</span>';
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
                            <tr><td colspan="7" class="table-empty">Cargando…</td></tr>
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
        container.querySelector('#dv-nuevo').addEventListener('click', formAdoptarDispositivo);

        cargarDispositivos();
    }

    async function cargarDispositivos() {
        const tbody = document.getElementById('dv-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:      dispositivos.q,
            modelo: dispositivos.modelo || '',
            enlace: dispositivos.enlace,
            estado: dispositivos.estado,
            limite: dispositivos.limite,
            orden:  dispositivos.orden,
            dir:    dispositivos.dir,
        });

        try {
            const data = await api(`api/dispositivos?${qs}`);
            dispositivos.filas     = data.dispositivos || [];
            dispositivos.catalogos = data.catalogos || dispositivos.catalogos;
            dispositivos.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsDispositivos();
        pintarBadgeFiltrosDispositivos();

        if (dispositivos.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">No hay dispositivos que coincidan con la búsqueda.</td></tr>';
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
            <div class="stat-card"><span class="stat-label">Online</span><span class="stat-value green">${r.enlazados}</span></div>
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
            d = (await api(`api/dispositivos?id=${id}`)).dispositivo;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const num = (v) => (v === null || v === undefined ? '' : String(v));

        /* La grilla es flex con `flex-grow: 1` (CSS §11): una tarjeta sola en
           su renglon se estira al 100%. Los campos son 16 -- par -- asi que
           todas van al 50% y ninguna queda estirada. Agregar o quitar UNO
           deja el ultimo renglon con una tarjeta ancha: si pasa, hay que
           marcar una `full` en ranura impar para recuperar la paridad. */
        const general = `<div class="view-grid">${[
            viewCard('Identificador',         d.uuid ? `<code>${escapeHtml(d.uuid)}</code>` : ''),
            viewCard('Nombre',                escapeHtml(d.nombre)),
            viewCard('Estado',                badgeHabilitado(d.habilitado)),
            viewCard('Enlace',                badgeEnlace(d.enlace)),
            viewCard('Modelo',                ref(d.modelo_nombre, d.modelo)),
            viewCard('Producto',              ref(d.producto_nombre, d.producto)),
            viewCard('Serie',                 escapeHtml(d.serial)),
            viewCard('MAC',                   d.mac ? `<code>${escapeHtml(d.mac)}</code>` : ''),
            viewCard('IP',                    d.ip ? `<code>${escapeHtml(d.ip)}</code>` : ''),
            viewCard('Señal',                 escapeHtml(d.senal)),
            viewCard('Último inicio',         escapeHtml(formatDate(d.inicio) || '')),
            viewCard('Último latido',         escapeHtml(formatDate(d.latido) || '')),
            viewCard('Inicios',               escapeHtml(num(d.inicios))),
            viewCard('Conexiones',            escapeHtml(num(d.conexiones))),
            viewCard('Latidos',               escapeHtml(num(d.latidos))),
            viewCard('Firmware',              escapeHtml(d.firmware)),
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

        const m = openModal('Consultar dispositivo', body, {
            wide:        true,
            primaryHtml: '<button class="btn btn-primary" data-act="editar"><i class="fa-solid fa-pen-to-square"></i> Editar</button>',
        });

        montarPestanasDispositivo(m.backdrop, d.id);

        m.backdrop.querySelector('[data-act="editar"]').addEventListener('click', () => {
            m.close();
            formDispositivo(d.id);
        });
    }

    /* ---------- Consultar: pestañas ---------- */
    function montarPestanasDispositivo(backdrop, id) {
        let conexionPedida = false;

        montarPestanas(backdrop, (destino) => {
            if (destino !== 'conexion' || conexionPedida) return;
            conexionPedida = true;
            cargarConexionDispositivo(id, backdrop.querySelector('[data-role="conexion-body"]'), null)
                .catch(() => { conexionPedida = false; });       // permite reintentar
        });
    }

    /* Solapas de un modal: alterna `.modal-tab` / `.modal-tabpanel` por
       `data-tab` / `data-panel`. `onMostrar(destino)` es opcional y lo usan
       los paneles que se cargan recien al abrirse (Dispositivo -> Conexion);
       los que ya vienen renderizados en el HTML no lo necesitan. */
    function montarPestanas(backdrop, onMostrar) {
        const tabs   = backdrop.querySelectorAll('.modal-tab');
        const panels = backdrop.querySelectorAll('.modal-tabpanel');

        tabs.forEach((tab) => tab.addEventListener('click', () => {
            const destino = tab.dataset.tab;
            tabs.forEach((x) => {
                const activa = x === tab;
                x.classList.toggle('active', activa);
                x.setAttribute('aria-selected', activa ? 'true' : 'false');
            });
            panels.forEach((p) => { p.hidden = p.dataset.panel !== destino; });

            if (typeof onMostrar === 'function') onMostrar(destino);
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

    async function cargarConexionDispositivo(id, contenedor, horas) {
        if (!contenedor) return;
        const qs = horas ? `&horas=${horas}` : '';
        let data;
        try {
            data = await api(`api/dispositivo_conexion?id=${id}${qs}`);
        } catch (err) {
            contenedor.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            throw err;
        }
        contenedor.innerHTML = vistaConexion(data);
        activarConexion(contenedor, id);
    }

    function vistaConexion(data) {
        const r      = data.resumen || {};
        const actual = data.dispositivo?.senal;
        const serie  = data.serie || [];

        const chips = (data.opciones || []).map((h) => `
            <button type="button" class="filter-chip${h === data.horas ? ' active' : ''}" data-horas="${h}">
                ${etiquetaHoras(h)}
            </button>`).join('');

        const sinDatos = (r.muestras || 0) === 0
            ? `<div class="alert alert-info" style="margin:0">
                   El equipo no informó su nivel de señal en las últimas
                   ${etiquetaHoras(data.horas)}: el gráfico queda vacío.
               </div>`
            : '';

        const resumen = `<div class="view-grid">${[
            viewCard('Nivel actual', nivelSenalHtml(actual)),
            viewCard('Promedio',     nivelSenalHtml(r.promedio)),
            viewCard('Mejor',        nivelSenalHtml(r.mejor)),
            viewCard('Peor',         nivelSenalHtml(r.peor)),
            viewCard('Cobertura',
                `${r.horas_con_dato || 0} de ${r.horas || 0} horas con reporte` +
                `<span class="muted"> · ${formatNumero(r.muestras || 0)} ${r.muestras === 1 ? 'medición' : 'mediciones'}</span>`,
                true),
        ].join('')}</div>`;

        return `
            <p class="modal-note">
                Nivel de señal a lo largo del tiempo. El equipo sólo informa su nivel
                al conectarse o al arrancar, no en cada mensaje: los puntos son las
                horas que midió y el tramo punteado arrastra el último nivel conocido.
                Pasá el mouse por el gráfico para ver el detalle de cada hora.
            </p>
            <div class="senal-toolbar">
                <span class="senal-toolbar-label">Período</span>
                <div class="senal-chips">${chips}</div>
            </div>
            ${sinDatos}
            ${graficoSenal(serie, data.escala || { maximo: -10, minimo: -90 }, data.horas)}
            <div class="senal-legend">${BANDAS_SENAL.map((b) => `
                <span class="senal-legend-item">
                    <span class="senal-legend-dot senal-zona-${b.clave}"></span>${b.etiqueta}
                    <span class="muted">(${b.dbm})</span>
                </span>`).join('')}
            </div>
            ${resumen}`;
    }

    // Hasta 48 h se lee mejor en horas; de ahí en más, en días.
    function etiquetaHoras(h) {
        return h > 48 ? `${Math.round(h / 24)} días` : `${h} h`;
    }

    /* ---------- El gráfico ----------
     * SVG a mano, sin librerías (el panel no tiene build step). Eje X = tiempo
     * en horas, eje Y = nivel en dBm sobre la escala del producto. Las bandas
     * de calidad van de fondo como zonas horizontales: así el color dice en qué
     * franja está el equipo sin pintar la línea, que es una sola serie.
     *
     * El viewBox mide lo mismo que el ancho útil del modal ancho (880 - 48 de
     * padding), así que a tamaño normal 1 unidad = 1 píxel y los textos salen
     * al tamaño declarado; si el modal se angosta, escala todo junto. */
    const SENAL_VB = { w: 832, h: 220, padL: 42, padR: 12, padT: 12, padB: 28 };

    function graficoSenal(serie, escala, horas) {
        const { w, h, padL, padR, padT, padB } = SENAL_VB;
        const plotW = w - padL - padR;
        const plotH = h - padT - padB;
        const n     = serie.length || 1;

        const y = (dbm) => padT + (plotH * (escala.maximo - dbm)) / (escala.maximo - escala.minimo);
        const x = (i)   => padL + (plotW * (i + 0.5)) / n;

        // Zonas de calidad: los cortes de las bandas expresados en dBm.
        const zonas = [
            { clave: 'buena',   hasta: escala.maximo, desde: -50 },
            { clave: 'regular', hasta: -50,           desde: -70 },
            { clave: 'debil',   hasta: -70,           desde: escala.minimo },
        ].map((z) => `<rect class="senal-zona senal-zona-${z.clave}" x="${padL}" y="${y(z.hasta)}"
                            width="${plotW}" height="${(y(z.desde) - y(z.hasta)).toFixed(1)}"/>`).join('');

        // Guías y rótulos del eje Y, cada 20 dBm (25% de la escala).
        const ticksY = [];
        for (let dbm = escala.minimo; dbm <= escala.maximo; dbm += 20) ticksY.push(dbm);
        const ejeY = ticksY.map((dbm) => `
            <line class="senal-grid" x1="${padL}" y1="${y(dbm)}" x2="${w - padR}" y2="${y(dbm)}"/>
            <text class="senal-tick" x="${padL - 8}" y="${y(dbm) + 3.5}" text-anchor="end">${dbm}</text>`).join('');

        // Rótulos del eje X: un paso "redondo" que deje como mucho 8 marcas.
        const paso  = [1, 2, 3, 4, 6, 8, 12, 24, 48].find((p) => n / p <= 8) || Math.ceil(n / 8);
        const ejeX  = serie.map((p, i) => (i % paso !== 0 ? '' : `
            <text class="senal-tick" x="${x(i)}" y="${h - padB + 16}" text-anchor="middle">${etiquetaHora(p.hora, horas)}</text>`)).join('');

        // El backend arrastra el último nivel conocido a las horas sin lectura
        // (`estimado`), así que la línea es continua. Pero el tramo ESTIMADO se
        // dibuja punteado y el MEDIDO sólido: si los dos fueran iguales, un
        // equipo que informó dos veces en el día se vería igual que uno que
        // informó cada hora, que es justo lo que no puede pasar.
        const pts = [];
        serie.forEach((p, i) => {
            if (p.dbm === null) return;
            pts.push({ i, cx: x(i).toFixed(1), cy: y(p.dbm).toFixed(1), medido: (p.muestras || 0) > 0 });
        });

        // Se agrupan los segmentos consecutivos del mismo tipo en una sola
        // polilínea: dibujar 168 <line> sueltas pierde los empalmes redondeados
        // y llena el DOM al pedo. Un tramo es sólido sólo si sus DOS extremos
        // son lecturas reales.
        const tramos = [];
        for (let k = 1; k < pts.length; k++) {
            const solido = pts[k - 1].medido && pts[k].medido;
            const ultimo = tramos[tramos.length - 1];
            if (ultimo && ultimo.solido === solido) ultimo.pts.push(pts[k]);
            else tramos.push({ solido, pts: [pts[k - 1], pts[k]] });
        }

        const linea = tramos.map((t) => `<polyline class="senal-linea${t.solido ? '' : ' senal-linea-est'}"
                  points="${t.pts.map((p) => `${p.cx},${p.cy}`).join(' ')}"/>`).join('');

        // Un punto por cada hora MEDIDA -- las arrastradas no llevan, que es lo
        // que deja ver de dónde salió cada dato. En 7 días son hasta ~168 y se
        // achican para que no se empasten.
        const radio  = n > 48 ? 3 : 3.5;
        const puntos = pts.filter((p) => p.medido)
            .map((p) => `<circle class="senal-punto" cx="${p.cx}" cy="${p.cy}" r="${radio}"/>`).join('');

        // Columnas invisibles: una por hora, son el área sensible del hover. La
        // hora con lectura lleva ademas su `cy`, para que al pasar el mouse el
        // punto de esa hora se agrande y quede claro qué lectura se está
        // leyendo en el tooltip.
        const hit = serie.map((p, i) => `
            <rect class="senal-hit" x="${(padL + (plotW * i) / n).toFixed(1)}" y="${padT}"
                  width="${(plotW / n).toFixed(2)}" height="${plotH}"
                  data-x="${x(i).toFixed(1)}"${(p.muestras || 0) > 0 ? ` data-cy="${y(p.dbm).toFixed(1)}"` : ''}
                  data-tip="${escapeHtml(tipHora(p, horas))}"
                  role="img" aria-label="${escapeHtml(tipHora(p, horas))}"/>`).join('');

        return `
            <div class="senal-plot">
                <svg class="senal-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="xMidYMid meet"
                     role="img" aria-label="Nivel de señal por hora, en dBm">
                    ${zonas}${ejeY}${ejeX}
                    <line class="senal-cross" x1="0" y1="${padT}" x2="0" y2="${padT + plotH}" hidden/>
                    ${linea}${puntos}
                    <circle class="senal-punto-activo" cx="0" cy="0" r="5.5" hidden/>
                    ${hit}
                    <text class="senal-tick senal-unidad" x="${padL - 8}" y="${padT - 3}" text-anchor="end">dBm</text>
                </svg>
                <div class="senal-tip" hidden></div>
            </div>`;
    }

    // 'YYYY-MM-DD HH:00:00' -> 'HH:00' en ventanas cortas, 'DD/MM' en las largas.
    function etiquetaHora(hora, horas) {
        const m = String(hora ?? '').match(/^(\d{4})-(\d{2})-(\d{2}) (\d{2})/);
        if (!m) return '';
        return horas > 48 ? `${m[3]}/${m[2]}` : `${m[4]}:00`;
    }

    // Texto del tooltip / etiqueta accesible de una hora de la serie.
    // La hora estimada NO se muestra como si fuera una medición: dice que el
    // equipo no informó y de cuándo es el nivel que se está arrastrando.
    function tipHora(p, horas) {
        const cuando = formatDate(p.hora) || p.hora;
        if (p.dbm === null) return `${cuando} · sin reporte`;

        const banda = bandaSenal(p.porcentaje);
        if (p.estimado) {
            const desde = p.origen ? formatDate(p.origen) || p.origen : null;
            return `${cuando} · sin reporte · último nivel conocido: ${p.dbm} dBm`
                 + ` · ${p.porcentaje}% · ${banda.etiqueta}${desde ? ` (de ${desde})` : ''}`;
        }

        const rango = p.minimo !== p.maximo ? ` (entre ${p.minimo} y ${p.maximo})` : '';
        return `${cuando} · ${p.dbm} dBm${rango} · ${p.porcentaje}% · ${banda.etiqueta}`
             + ` · ${p.muestras} ${p.muestras === 1 ? 'medición' : 'mediciones'}`;
    }

    /* Cablea lo que el HTML no puede: los chips de período y el hover del
       gráfico (cruz vertical + tooltip). Se llama después de cada render. */
    function activarConexion(contenedor, id) {
        contenedor.querySelectorAll('.senal-chips [data-horas]').forEach((chip) => {
            chip.addEventListener('click', () => {
                cargarConexionDispositivo(id, contenedor, +chip.dataset.horas).catch(() => {});
            });
        });

        const plot  = contenedor.querySelector('.senal-plot');
        const tip   = contenedor.querySelector('.senal-tip');
        const cruz  = contenedor.querySelector('.senal-cross');
        const marca = contenedor.querySelector('.senal-punto-activo');
        if (!plot || !tip || !cruz) return;

        plot.querySelectorAll('.senal-hit').forEach((celda) => {
            celda.addEventListener('mouseenter', () => {
                cruz.setAttribute('x1', celda.dataset.x);
                cruz.setAttribute('x2', celda.dataset.x);
                cruz.removeAttribute('hidden');

                // Sólo las horas con lectura tienen `cy`: en las vacías la
                // marca se esconde, así el tooltip que dice "sin reporte" no
                // queda acompañado de un punto que no existe.
                if (marca) {
                    if (celda.dataset.cy) {
                        marca.setAttribute('cx', celda.dataset.x);
                        marca.setAttribute('cy', celda.dataset.cy);
                        marca.removeAttribute('hidden');
                    } else {
                        marca.setAttribute('hidden', '');
                    }
                }

                // La posición se mide sobre el DOM, no sobre el viewBox: el SVG
                // escala con el modal y las unidades no son píxeles.
                const c = celda.getBoundingClientRect();
                const p = plot.getBoundingClientRect();

                // Se muestra en `visibility: hidden` para poder medirlo: con el
                // atributo `hidden` puesto es `display: none` y `offsetWidth`
                // da 0. Es un solo reflow y no llega a verse.
                tip.textContent   = celda.dataset.tip;
                tip.style.visibility = 'hidden';
                tip.removeAttribute('hidden');

                // El tooltip va centrado sobre la columna (`translate(-50%)`),
                // así que en las horas de los extremos la mitad se salía del
                // plot: el `.modal-body` tiene `overflow-y: auto` y eso vuelve
                // `auto` también al eje X, así que aparecía una barra de scroll
                // horizontal y el texto quedaba cortado. Acá se acota el centro
                // para que ningún borde pase de la caja del gráfico.
                const media  = tip.offsetWidth / 2;
                const centro = c.left - p.left + c.width / 2;
                const tope   = Math.max(media + 4, p.width - media - 4);

                tip.style.left = `${Math.min(Math.max(centro, media + 4), tope)}px`;
                tip.style.top  = `${c.top - p.top}px`;
                tip.style.visibility = '';
            });
        });

        plot.addEventListener('mouseleave', () => {
            cruz.setAttribute('hidden', '');
            tip.setAttribute('hidden', '');
            if (marca) marca.setAttribute('hidden', '');
        });
    }

    /* ---------- Adopción (reemplaza al alta) ----------
     * El cliente no da de alta dispositivos: los fabrica Reactor y el panel
     * los ADOPTA. Por eso el modal pide un solo dato, el número de serie del
     * equipo, y el botón dice `Continuar`: lo que sigue no es un alta sino la
     * confirmación de la adopción, que el backend ya registró. */
    function formAdoptarDispositivo() {
        const body = `
            <p style="font-size:.88rem;color:var(--muted);line-height:1.5;margin:0">
                Ingresá el número de serie que figura en la etiqueta del equipo.
                Si está disponible, lo incorporamos a tu cuenta.
            </p>
            <div class="form-group">
                <label for="da-serial">Número de serie *</label>
                <input type="text" id="da-serial" maxlength="50" autocomplete="off" spellcheck="false"
                       placeholder="El que figura en la etiqueta del equipo">
            </div>
            <div class="field-error" id="da-error" style="display:none"></div>
        `;

        const m = openModal('Nuevo dispositivo', body, {
            closeLabel:  'Cancelar',
            primaryHtml: '<button class="btn btn-primary" data-act="continuar">Continuar</button>',
        });

        const input = m.backdrop.querySelector('#da-serial');
        const err   = m.backdrop.querySelector('#da-error');
        const btn   = m.backdrop.querySelector('[data-act="continuar"]');

        input.focus();
        // Enter confirma: con un solo campo, obligar a ir al botón molesta.
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });

        btn.addEventListener('click', async () => {
            const serial = input.value.trim();
            const fallar = (msg) => {
                err.textContent   = msg;
                err.style.display = '';
                input.classList.add('input-invalid');
            };

            if (serial === '') {
                fallar('Ingresá el número de serie del equipo.');
                input.focus();
                return;
            }

            err.style.display = 'none';
            input.classList.remove('input-invalid');
            btn.disabled = true;
            try {
                const adoptado = await api('api/dispositivos?accion=adoptar', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ serial }),
                });
                m.close();
                cargarDispositivos();
                modalDispositivoAdoptado(adoptado);
            } catch (e2) {
                fallar(e2.message);
                btn.disabled = false;
            }
        });
    }

    /* Segundo modal del alta. Cuando se abre, el equipo YA fue adoptado: la
       adopción se registró en el POST anterior, acá sólo se confirma y se
       muestra de qué equipo se trata. Por eso no tiene acción primaria. */
    function modalDispositivoAdoptado(d) {
        const body = `
            <div class="alert alert-success" style="margin-bottom:0">
                El dispositivo fue adoptado y ya forma parte de tu cuenta.
            </div>
            <div class="view-grid">
                ${viewCard('Identificador',   d.uuid   ? `<code>${escapeHtml(d.uuid)}</code>`   : '')}
                ${viewCard('Número de serie', d.serial ? `<code>${escapeHtml(d.serial)}</code>` : '')}
                ${viewCard('Nombre',          escapeHtml(d.nombre || ''), true)}
                ${viewCard('Modelo',          escapeHtml(d.modelo_nombre || ''))}
                ${viewCard('Estado',          badgeHabilitado(true))}
            </div>
            <p style="font-size:.85rem;color:var(--muted);line-height:1.5;margin:0">
                Podés renombrarlo con <strong>Editar</strong>, en el menú de la fila.
            </p>
        `;
        openModal('Dispositivo adoptado', body, { closeLabel: 'Cerrar' });
    }

    /* ---------- Edición ----------
     * La UI expone UN SOLO campo, `nombre`. El resto de la ficha (identificador
     * de fábrica, catálogos, MAC, fechas, monitoreo) lo administra Reactor y no
     * el cliente; `habilitado` se cambia desde Habilitar / Deshabilitar del
     * menú contextual del listado. Los campos de telemetría (enlace, ip, senal,
     * firmware, contadores, fechas de conexión/latido, adopción y
     * monitoreoUltimo/Siguiente) los escribe el equipo: se consultan pero no se
     * editan desde el panel. */
    async function formDispositivo(id) {
        let d;
        try {
            d = (await api(`api/dispositivos?id=${id}`)).dispositivo;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const body = `
            <div class="form-group">
                <label for="df-nombre">Nombre *</label>
                <input type="text" id="df-nombre" maxlength="255" value="${escapeHtml(d.nombre)}">
            </div>
            <div class="field-error" id="df-error" style="display:none"></div>
        `;

        const m = openModal(`Editar dispositivo <span class="muted">#${d.id}</span>`, body, {
            closeLabel:  'Cancelar',
            primaryHtml: '<button class="btn btn-primary" data-act="guardar">Guardar</button>',
        });

        const err   = m.backdrop.querySelector('#df-error');
        const btn   = m.backdrop.querySelector('[data-act="guardar"]');
        const input = m.backdrop.querySelector('#df-nombre');

        input.focus();
        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });

        btn.addEventListener('click', async () => {
            /* El PUT reescribe la fila entera y el form ya no tiene los demás
               campos: se parte del registro tal como vino del GET y se pisa
               nada más `nombre`, igual que el toggle del listado hace con
               `habilitado`. */
            const payload = { ...payloadDispositivo(d), id: d.id, nombre: input.value.trim() };

            btn.disabled = true;
            try {
                await api('api/dispositivos', {
                    method:  'PUT',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify(payload),
                });
                m.close();
                toast('Dispositivo actualizado');
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
            const full = (await api(`api/dispositivos?id=${d.id}`)).dispositivo;
            await api('api/dispositivos', {
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
                    await api(`api/dispositivos?accion=liberar&id=${d.id}`, { method: 'POST' });
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
            <!-- Modelo va solo y a lo ancho: al sacarse "Codigo" quedaba
                 media columna vacia a su derecha. -->
            <div class="form-group">
                <label for="df-f-modelo">Modelo</label>
                <select id="df-f-modelo">${modeloOpts}</select>
            </div>
            <div class="form-group">
                <label>Enlace</label>
                <div style="display:flex;gap:6px;flex-wrap:wrap" id="df-f-enlace">
                    ${chip('enlace', 'todos', 'Todos')}
                    ${chip('enlace', 'online', 'Online')}
                    ${chip('enlace', 'offline', 'Offline')}
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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
       busqueda sin resultados barre la tabla entera (14 s medidos).
       NO es un filtro de la UI: es fija en 200.000 y viaja siempre igual
       en el GET (api/actividad.php la valida contra su lista VENTANAS).
       Desde que se saco la busqueda por codigo (el unico camino que el
       backend resolvia sin ventana) el historial viejo no tiene atajo:
       se llega acotando por fecha dentro de la misma ventana. */
    const ACTIVIDAD_DEFAULTS = {
        usuario: 0, dispositivo: 0, sentido: 'S',
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
                            <tr><td colspan="7" class="table-empty">Cargando…</td></tr>
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
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Cargando…</td></tr>';
        pintarNotaVentanaActividad();

        const qs = new URLSearchParams({
            q:           actividad.q,
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
            const data = await api(`api/actividad?${qs}`);
            actividad.filas     = data.actividad || [];
            actividad.catalogos = data.catalogos || { usuarios: [], dispositivos: [] };
            actividad.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsActividad();
        pintarBadgeFiltrosActividad();

        if (actividad.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">No hay actividad que coincida con la búsqueda.</td></tr>';
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
        if (actividad.ventana > 0) {
            el.textContent = `Se busca dentro de los ${actividad.ventana.toLocaleString('es-AR')} registros más `
                           + 'recientes del sistema.';
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
            r = (await api(`api/actividad?id=${id}`)).registro;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        /* Las tres fichas son `view-grid` de tarjetas al 50%. La grilla es
           flex con `flex-grow: 1` (CSS §11): con la cuenta impar la ultima
           tarjeta se estira a todo el ancho y se lee como un campo
           destacado a proposito. Usuario y Dispositivo son 8 y cierran
           solas; General quedo en 7 al sacarse `Codigo`, asi que `Fecha`
           va `full` en la ranura IMPAR (la primera) y las seis restantes
           cierran de a dos. Al tocar cualquiera de las tres, mantener la
           paridad -- o marcar una `full` en ranura impar. */
        const general = `<div class="view-grid">${[
            viewCard('Fecha',       escapeHtml(actividadFechaLarga(r.fecha)), true),
            viewCard('Usuario',     ref(r.usuario_nombre, r.usuario)),
            viewCard('Cuenta',      escapeHtml(r.usuario_login || '')),
            viewCard('Dispositivo', ref(r.dispositivo_nombre, r.dispositivo)),
            viewCard('Canal',       ref(r.canal_nombre, r.canal)),
            viewCard('Sentido',     actividadSentido(r.sentido)),
            viewCard('Estado',      actividadEstado(r.estado)),
        ].join('')}</div>`;

        const body = `
            <div class="modal-tabs" role="tablist">
                <button type="button" class="modal-tab active" data-tab="general" role="tab" aria-selected="true">
                    <i class="fa-solid fa-circle-info"></i> General
                </button>
                <button type="button" class="modal-tab" data-tab="usuario" role="tab" aria-selected="false">
                    <i class="fa-solid fa-user"></i> Usuario
                </button>
                <button type="button" class="modal-tab" data-tab="dispositivo" role="tab" aria-selected="false">
                    <i class="fa-solid fa-microchip"></i> Dispositivo
                </button>
            </div>
            <div class="modal-tabpanel" data-panel="general" role="tabpanel">${general}</div>
            <div class="modal-tabpanel" data-panel="usuario" role="tabpanel" hidden>
                ${fichaUsuarioActividad(r.usuario_ficha)}
            </div>
            <div class="modal-tabpanel" data-panel="dispositivo" role="tabpanel" hidden>
                ${fichaDispositivoActividad(r.dispositivo_ficha)}
            </div>
        `;

        /* `wide` por las pestañas: es el mismo criterio del modal de
           Consultar dispositivo, que tambien las tiene. A 520px las tres
           solapas y las tarjetas al 50% quedan apretadas.
           Pie solo con "Cerrar": no hay modal de edicion para este recurso,
           y los atajos (filtrar por usuario / dispositivo, copiar detalle)
           viven en el menu contextual de la fila. */
        const m = openModal('Consultar actividad', body, { wide: true });
        montarPestanas(m.backdrop);
    }

    /* Las dos fichas las arma el GET por id de api/actividad.php con los
       mismos JOIN del registro -- no se piden a api/usuarios.php ni a
       api/dispositivos.php, que filtran por el dominio ACTUAL del usuario o
       del equipo y darian 404 sobre actividad vieja perfectamente valida. */
    function fichaUsuarioActividad(u) {
        if (!u) return vacioPestana('fa-user', 'Este registro no tiene un usuario asociado.');

        return `<div class="view-grid">${[
            viewCard('Identificador',  u.uuid ? `<code>${escapeHtml(u.uuid)}</code>` : ''),
            viewCard('Cuenta',         escapeHtml(u.usuario || '')),
            viewCard('Nombre',         escapeHtml(u.nombre || '')),
            viewCard('Correo',         escapeHtml(u.correo || '')),
            viewCard('Celular',        escapeHtml(u.celular || '')),
            viewCard('Último ingreso', escapeHtml(formatDate(u.ingresado) || '')),
            viewCard('Registrado',     escapeHtml(formatDate(u.registrado) || '')),
            viewCard('Estado',         badgeHabilitado(u.habilitado)),
        ].join('')}</div>`;
    }

    function fichaDispositivoActividad(d) {
        if (!d) return vacioPestana('fa-microchip', 'Este registro no tiene un dispositivo asociado.');

        return `<div class="view-grid">${[
            viewCard('Identificador', d.uuid ? `<code>${escapeHtml(d.uuid)}</code>` : ''),
            viewCard('Nombre',        escapeHtml(d.nombre || '')),
            viewCard('Modelo',        ref(d.modelo_nombre, d.modelo)),
            viewCard('Serie',         escapeHtml(d.serial || '')),
            viewCard('Señal',         escapeHtml(d.senal || '')),
            viewCard('Último latido', escapeHtml(formatDate(d.latido) || '')),
            viewCard('Estado',        badgeHabilitado(d.habilitado)),
            viewCard('Enlace',        badgeEnlace(d.enlace)),
        ].join('')}</div>`;
    }

    /* Estado vacio de una pestaña. Las tres solapas estan SIEMPRE, aunque
       el registro no tenga usuario o dispositivo: que aparezcan y
       desaparezcan segun la fila haria saltar el modal y dejaria al usuario
       sin saber si la pestaña falta o si no hay dato. */
    function vacioPestana(icono, texto) {
        return `<div class="table-empty" style="padding:32px 12px;text-align:center">
            <i class="fa-solid ${icono}" style="font-size:1.6rem;opacity:.35;display:block;margin-bottom:10px"></i>
            ${escapeHtml(texto)}
        </div>`;
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

        const body = `
            <!-- Los dos selects comparten renglon: al sacarse "Codigo",
                 Usuario quedaba con media columna vacia a su derecha y
                 Dispositivo solo a lo ancho debajo. -->
            <div class="filters-grid">
                <div class="form-group">
                    <label for="af-f-usuario">Usuario</label>
                    <select id="af-f-usuario">${opciones(actividad.catalogos.usuarios, actividad.usuario)}</select>
                </div>
                <div class="form-group">
                    <label for="af-f-dispositivo">Dispositivo</label>
                    <select id="af-f-dispositivo">${opciones(actividad.catalogos.dispositivos, actividad.dispositivo)}</select>
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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

        $('#af-f-usuario').addEventListener('change',     (e) => { actividad.usuario     = +e.target.value || 0;  aplicarEnVivo(); });
        $('#af-f-dispositivo').addEventListener('change', (e) => { actividad.dispositivo = +e.target.value || 0;  aplicarEnVivo(); });
        $('#af-f-desde').addEventListener('change',       (e) => { actividad.desde       = e.target.value;        aplicarEnVivo(); });
        $('#af-f-hasta').addEventListener('change',       (e) => { actividad.hasta       = e.target.value;        aplicarEnVivo(); });
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
            $('#af-f-usuario').value     = '0';
            $('#af-f-dispositivo').value = '0';
            $('#af-f-desde').value       = '';
            $('#af-f-hasta').value       = '';
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
     * y las mismas columnas del listado legacy (compania, numero, estado),
     * ampliadas con plan, titular y serie.
     * `compania`, `plan`, `responsable` y `pais` se guardan como codigos
     * cortos; el texto sale de la tabla `combos` — lo mismo que hacia
     * comboTraducir() en el legacy — y lo resuelve api/chips.php, que
     * ademas acota todo al dominio de la sesion (requireDominioId()).
     * ======================================================= */

    const CHIPS_DEFAULTS = {
        compania: '', plan: '', responsable: '',
        estado: 'todos', limite: 100, orden: 'id', dir: 'desc',
    };

    const chips = {
        q: '',
        ...CHIPS_DEFAULTS,
        filas: [],
        combos: { compania: [], plan: [], responsable: [], pais: [] },
        resumen: null,
    };

    function chipsFiltrosActivos() {
        let n = 0;
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
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
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
                            <tr><td colspan="7" class="table-empty">Cargando…</td></tr>
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

        cargarChips();
    }

    async function cargarChips() {
        const tbody = document.getElementById('ch-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="7" class="table-empty">Cargando…</td></tr>';

        const qs = new URLSearchParams({
            q:           chips.q,
            compania:    chips.compania,
            plan:        chips.plan,
            responsable: chips.responsable,
            estado:      chips.estado,
            limite:      chips.limite,
            orden:       chips.orden,
            dir:         chips.dir,
        });

        try {
            const data = await api(`api/chips?${qs}`);
            chips.filas     = data.chips     || [];
            chips.combos    = data.combos    || chips.combos;
            chips.resumen   = data.resumen   || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="7" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsChips();
        pintarBadgeFiltrosChips();

        if (chips.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="7" class="table-empty">No hay chips que coincidan con la búsqueda.</td></tr>';
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

    /* Menu contextual de fila: solo Consultar. El chip se da de alta y se
     * administra desde cloud, asi que el panel no ofrece Editar, Eliminar ni
     * Habilitar/Deshabilitar sobre la fila. */
    function menuChip(c) {
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verChip(c.id) },
        ];
    }

    /* ---------- Consultar ---------- */
    async function verChip(id) {
        let c;
        try {
            c = (await api(`api/chips?id=${id}`)).chip;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        const body = `<div class="view-grid">${[
            viewCard('Teléfono', c.telefono ? `<code>${escapeHtml(c.telefono)}</code>` : ''),
            viewCard('Serie',    c.serie ? `<code>${escapeHtml(c.serie)}</code>` : ''),
            viewCard('Compañía', textoCombo(c.compania_texto, c.compania)),
            viewCard('País',     textoCombo(c.pais_texto, c.pais)),
            viewCard('Estado',   badgeHabilitado(c.habilitado), true),
        ].join('')}</div>`;

        // Solo lectura: el chip se administra desde cloud, asi que el modal no
        // lleva accion primaria ni menu de pie — unicamente "Cerrar".
        openModal('Consultar chip', body);
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
            <!-- Los tres selects en un solo renglon: al sacarse "Codigo",
                 Compania quedaba con media columna vacia a su derecha. -->
            <div class="form-row form-row-3">
                <div class="form-group">
                    <label for="cf-f-compania">Compañía</label>
                    <select id="cf-f-compania">${opcionesCombo(chips.combos.compania, chips.compania, 'Todas')}</select>
                </div>
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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
     * ALTA SI, EDICION NO: se puede invitar (boton "Invitar" de la toolbar)
     * pero no editar ni dar de baja una invitacion, porque una vez emitida
     * el que la mueve es el destinatario, aceptandola o rechazandola. Por eso
     * el menu contextual no tiene Editar ni Eliminar; sus acciones propias
     * son atajos para acotar el listado.
     *
     * El alta pide UN solo campo, el correo, que es a donde va el mensaje.
     * Nombre y celular los completa el invitado al aceptar. Es la misma
     * forma del alta legacy (un solo campo, el celular) con el canal
     * invertido: aquel mandaba por WhatsApp, este por correo.
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
                        Las invitaciones son los pedidos de acceso que un usuario del dominio le envía por
                        correo a una persona para que se sume al sistema, con sus datos de contacto, cuándo
                        se emitió, cuándo la abrió el destinatario y en qué estado quedó. Se listan
                        únicamente las del dominio <strong>${dominio}</strong>. Una vez enviada no se
                        edita ni se elimina: la resuelve el destinatario desde el enlace que recibió.
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
                    <div class="toolbar-right">
                        <button type="button" class="btn btn-primary" id="in-nueva">
                            <i class="fa-solid fa-paper-plane"></i> Invitar
                        </button>
                    </div>
                </div>

                <div class="table-card">
                    <table>
                        <thead>
                            <tr>
                                <th>Identificador</th>
                                <th>Emitida</th>
                                <th>Emisor</th>
                                <th>Destinatario</th>
                                <th>Estado</th>
                                <th class="action-col">Acciones</th>
                            </tr>
                        </thead>
                        <tbody id="in-tbody">
                            <tr><td colspan="6" class="table-empty">Cargando…</td></tr>
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
        container.querySelector('#in-nueva').addEventListener('click', () => formInvitacion());

        cargarInvitaciones();
    }

    async function cargarInvitaciones() {
        const tbody = document.getElementById('in-tbody');
        if (!tbody) return;
        tbody.innerHTML = '<tr><td colspan="6" class="table-empty">Cargando…</td></tr>';

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
            const data = await api(`api/invitaciones?${qs}`);
            invitaciones.filas     = data.invitaciones || [];
            invitaciones.estados   = data.estados      || [];
            invitaciones.catalogos = data.catalogos    || { emisores: [] };
            invitaciones.resumen   = data.resumen      || null;
        } catch (err) {
            tbody.innerHTML = `<tr><td colspan="6" class="table-empty">${escapeHtml(err.message)}</td></tr>`;
            return;
        }

        pintarStatsInvitaciones();
        pintarBadgeFiltrosInvitaciones();

        if (invitaciones.filas.length === 0) {
            tbody.innerHTML = '<tr><td colspan="6" class="table-empty">No hay invitaciones que coincidan con la búsqueda.</td></tr>';
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

    /* Celda de persona: el nombre arriba en blanco y sus dos datos de contacto
       debajo, en el tono tenue de `.td-id`. La comparten las dos columnas
       -- Emisor (datos de `usuarios`) y Destinatario (datos de la propia
       invitacion) -- para que las dos se lean igual. El legacy ya apilaba
       nombre / celular / correo en una sola celda. */
    function celdaPersona(nombre, correo, celular) {
        const contacto = [correo, celular].filter(Boolean)
            .map((x) => `<div class="td-id">${escapeHtml(x)}</div>`).join('');
        if (!nombre && !contacto) return DASH;
        return `${nombre ? `<div class="td-nombre">${escapeHtml(nombre)}</div>` : ''}${contacto}`;
    }

    function filaInvitacion(r) {
        return `
            <tr data-id="${r.id}" class="row-clickable">
                <td>${r.uuid ? `<code>${escapeHtml(r.uuid)}</code>` : DASH}</td>
                <td>${escapeHtml(formatDate(r.emitida) || '') || DASH}</td>
                <td>${celdaPersona(r.emisor_nombre || r.emisor_login, r.emisor_correo, r.emisor_celular)}</td>
                <td>${celdaPersona(r.nombre, r.correo, r.celular)}</td>
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

    /* ---------- Invitar (alta + envio) ----------
     * Un solo campo: el correo del invitado. El resto de la ficha la
     * completa el propio invitado cuando acepta, asi que no hay nada mas
     * que preguntarle al emisor.
     *
     * El POST encola el correo en el microservicio antes de responder, y si
     * el envio falla el backend revierte el alta: por eso el boton espera la
     * respuesta en vez de cerrar el modal de entrada. Un "Invitación enviada"
     * optimista mentiria en el unico caso que importa. */
    function formInvitacion() {
        const dominio = sesion.dominio_nombre || (sesion.dominio ? `#${sesion.dominio}` : '—');

        const body = `
            <div class="form-group">
                <label for="if-correo">Correo del invitado *</label>
                <input type="email" id="if-correo" maxlength="100" autocomplete="off"
                       placeholder="persona@ejemplo.com" autofocus>
            </div>
            <div class="form-group">
                <label>Dominio</label>
                <input type="text" value="${escapeHtml(dominio)}" readonly
                       title="Se toma de tu sesión">
            </div>
            <div class="alert alert-info" style="margin-top:4px">
                Le va a llegar un correo con un enlace para aceptar o rechazar la invitación.
                Su nombre, apellido y celular los completa al aceptar.
            </div>
            <div class="field-error" id="if-error" style="display:none"></div>
        `;

        const m = openModal('Invitar usuario', body, {
            closeLabel:  'Cancelar',
            primaryHtml: '<button class="btn btn-primary" data-act="enviar">Enviar invitación</button>',
        });

        const input = m.backdrop.querySelector('#if-correo');
        const err   = m.backdrop.querySelector('#if-error');
        const btn   = m.backdrop.querySelector('[data-act="enviar"]');
        const label = btn.textContent;

        input.addEventListener('keydown', (e) => { if (e.key === 'Enter') btn.click(); });

        btn.addEventListener('click', async () => {
            const correo = input.value.trim();
            if (correo === '') {
                err.textContent = 'El correo es obligatorio.';
                err.style.display = '';
                input.focus();
                return;
            }

            err.style.display = 'none';
            btn.disabled = true;
            btn.textContent = 'Enviando…';
            try {
                await api('api/invitaciones', {
                    method:  'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body:    JSON.stringify({ correo }),
                });
                m.close();
                toast(`Invitación enviada a ${correo}`);
                cargarInvitaciones();
            } catch (e2) {
                err.textContent   = e2.message;
                err.style.display = '';
                btn.disabled = false;
                btn.textContent = label;
                input.focus();
            }
        });
    }

    /* ---------- Consultar ---------- */
    async function verInvitacion(id) {
        let r;
        try {
            r = (await api(`api/invitaciones?id=${id}`)).invitacion;
        } catch (err) {
            toast(err.message, { error: true });
            return;
        }

        /* Dos bloques separados por una divisoria: arriba la invitacion (de
           quien salio, a que dominio y con que identificador) y abajo el
           destinatario con las fechas. Sin tarjeta `Codigo`. Arriba son
           cuatro tarjetas media, asi que los dos renglones cierran solos;
           abajo son cinco y por eso `Nombre` va `full` en la ranura impar
           -- ver la nota de paridad de `.view-grid` en CLAUDE.md. */
        const body = `<div class="view-grid">${[
            viewCard('Estado',        invitacionEstado(r.estado, r.estado_texto)),
            viewCard('Dominio',       r.dominio_nombre ? `<span class="badge badge-info">${escapeHtml(r.dominio_nombre)}</span>` : (r.dominio ? `<code>#${r.dominio}</code>` : '')),
            viewCard('Emisor',        r.emisor_nombre ? escapeHtml(r.emisor_nombre) : (r.emisor ? `<code>#${r.emisor}</code>` : '')),
            viewCard('Identificador', r.uuid ? `<code>${escapeHtml(r.uuid)}</code>` : ''),
            '<div class="view-sep"></div>',
            viewCard('Nombre',        r.nombre ? escapeHtml(r.nombre) : '', true),
            viewCard('Correo',        r.correo ? escapeHtml(r.correo) : ''),
            viewCard('Celular',       r.celular ? escapeHtml(r.celular) : ''),
            viewCard('Emitida',       escapeHtml(formatDate(r.emitida) || '')),
            viewCard('Abierta',       escapeHtml(formatDate(r.abierta) || '')),
        ].join('')}</div>`;

        // Titulo sin el `#id` que llevan los otros modales de consulta: la
        // invitacion se identifica por su `uuid`, que es una tarjeta mas.
        // Pie sin "Editar" (no hay modal de edicion para este recurso) y sin
        // el boton ☰: los atajos ya viven en el menu contextual de la fila.
        openModal('Consultar invitación', body);
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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
        numero: '', estado: '', desde: '', hasta: '',
        limite: 10, orden: 'id', dir: 'desc',
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
                            <tr><td colspan="${esF ? 7 : 6}" class="table-empty">Cargando…</td></tr>
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
        const columns = tipo === 'F' ? 7 : 6;
        tbody.innerHTML = `<tr><td colspan="${columns}" class="table-empty">Cargando…</td></tr>`;

        const qs = new URLSearchParams({
            tipo,
            q:      s.q,
            numero: s.numero,
            estado: s.estado,
            desde:  s.desde,
            hasta:  s.hasta,
            limite: s.limite,
            orden:  s.orden,
            dir:    s.dir,
        });

        try {
            const data = await api(`api/comprobantes?${qs}`);
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
    const CP_ESTADO_PENDIENTE = '2';
    const CP_ESTADO_CANCELADO = '3';

    function comprobanteEstado(r) {
        const texto = escapeHtml(r.estado_texto || r.estado || '');
        if (!texto) return DASH;
        const clase = r.estado === CP_ESTADO_PENDIENTE ? 'badge-warn' : 'badge-success';
        return `<span class="badge ${clase}">${texto}</span>`;
    }

    function filaComprobante(r) {
        const esF = comprobantes.activo === 'F';
        return `
            <tr data-id="${r.id}" class="row-clickable">
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
       bajarlo, compartirlo) y el atajo para acotar el listado por estado.
       Ese atajo NO se ofrece sobre un comprobante Cancelado (misma regla en
       Facturas y en Recibos, que comparten este menu). */
    function menuComprobante(r) {
        const e       = r.enlaces;
        const filtrar = r.estado && r.estado !== CP_ESTADO_CANCELADO;
        return [
            { label: 'Consultar', icon: 'fa-eye', onSelect: () => verComprobante(r.id) },
            e ? { label: 'Abrir comprobante', icon: 'fa-up-right-from-square', onSelect: () => window.open(e.abrir, '_blank', 'noopener') } : null,
            e ? { label: 'Descargar',         icon: 'fa-download',            onSelect: () => window.open(e.descargar, '_blank', 'noopener') } : null,
            e ? { label: 'Copiar enlace',     icon: 'fa-link',                onSelect: () => copiar(e.compartir) } : null,
            filtrar ? { sep: true } : null,
            filtrar ? {
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
            data = await api(`api/comprobantes?tipo=${tipo}&id=${id}`);
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
        ].filter(Boolean).join('');

        const body = `<div class="view-grid">${cards}</div>
            ${detalleComprobante(data.renglones || [])}
            <div class="view-grid" style="margin-top:12px">
                ${viewCard('Subtotal', escapeHtml(formatMoneda(r.subtotal) || ''))}
                ${viewCard('IVA',      escapeHtml(formatMoneda(r.iva)      || ''))}
                ${viewCard('Total',    `<strong>${escapeHtml(formatMoneda(r.total) || '—')}</strong>`, true)}
            </div>`;

        // Barra sin "Editar": no hay modal de edicion para este recurso. Las
        // acciones extra van en el desplegable `Acciones`, que abre el mismo
        // ctx-menu flotante del listado.
        const m = openModal(
            escapeHtml(r.numero),
            body,
            {
                wide: true,
                footerHtml: r.enlaces
                    ? '<button class="btn btn-primary" data-act="abrir"><i class="fa-solid fa-up-right-from-square"></i> Abrir</button>'
                    : '',
                primaryHtml: `<button class="btn btn-primary" data-act="menu">
                    <i class="fa-solid fa-bolt"></i> Acciones
                    <i class="fa-solid fa-caret-down menubar-caret"></i>
                </button>`,
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
            <!-- Numero va solo y a lo ancho: al sacarse "Codigo" quedaba
                 media columna vacia a su derecha. -->
            <div class="form-group">
                <label for="cf-f-numero">Número</label>
                <input type="text" id="cf-f-numero" placeholder="Ej. 003340" value="${escapeHtml(s.numero)}">
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
            closeLabel:  'Cancelar',
            primaryHtml: `
                <button class="btn btn-primary" data-act="limpiar"><i class="fa-solid fa-eraser"></i> Limpiar</button>
                <button class="btn btn-primary" data-act="aplicar"><i class="fa-solid fa-check"></i> Aplicar</button>`,
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

        $('#cf-f-numero').addEventListener('input',  (e) => {
            const v = e.target.value.trim();
            clearTimeout(debounceNumero);
            debounceNumero = setTimeout(() => { s.numero = v; aplicarEnVivo(); }, 300);
        });
        $('#cf-f-desde').addEventListener('change',  (e) => { s.desde  = e.target.value;        aplicarEnVivo(); });
        $('#cf-f-hasta').addEventListener('change',  (e) => { s.hasta  = e.target.value;        aplicarEnVivo(); });
        $('#cf-f-limite').addEventListener('change', (e) => { s.limite = +e.target.value || 10; aplicarEnVivo(); });
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
     *
     * Debajo de las tarjetas va el grafico de uso por dispositivo
     * (api/dashboard_senales.php). Son dos cargas independientes a
     * proposito: la del inventario es instantanea y la del grafico agrega
     * ~400K filas de `senales`, asi que si fueran una sola espera los
     * numeros de arriba tardarian de mas sin necesidad.
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
                <div id="db-uso"></div>
            </div>
        `;

        cargarDashboard();
        cargarUso();
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
            const data = await api('api/dashboard');
            const t    = data.totales || {};
            stats.innerHTML = dashboardStat('Usuarios', t.usuarios ?? 0, 'fa-users', 'usuarios')
                            + dashboardStat('Dispositivos', t.dispositivos ?? 0, 'fa-microchip', 'dispositivos')
                            + dashboardStat('Chips', t.chips ?? 0, 'fa-sim-card', 'chips');
        } catch (err) {
            stats.innerHTML = '';
            error.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
        }
    }

    /* ---------- Uso por dispositivo (grafico del dashboard) ----------
     * Lineas multi-serie: una por dispositivo del dominio, un punto por dia,
     * sobre los ultimos 30 dias. SVG a mano, sin librerias (el panel no tiene
     * build step), igual que el grafico de senal de Dispositivos.
     *
     * Diferencia de fondo con aquel: alla el eje Y es una MEDICION y las horas
     * sin reporte son un corte en la linea; aca es un CONTEO y un dia sin
     * senales vale cero, asi que las lineas van enteras, sin huecos.
     *
     * El color de cada serie lo decide el BACKEND (`slot`), no el orden del
     * array: el color sigue al equipo. Ver el comentario de la paleta en
     * style.css §15b, que ademas explica por que hay solo seis ranuras. */

    /* La identidad de cada serie la lleva SOLO la referencia de arriba (mas
     * el tooltip y la vista de tabla). No hay rotulos al final de las lineas:
     * reservarles una canaleta a la derecha dejaba un vacio que se leia como
     * si al grafico le faltaran dias. Si se vuelven a querer, hay que sumar
     * el ancho del rotulo a padR -- no dibujarlos sobre el area del plot. */
    const USO_VB = { w: 1200, h: 280, padL: 56, padR: 16, padT: 16, padB: 34 };

    /* Ultimo payload y ventana elegida. Cambiar de ventana vuelve a pedir:
     * son datos distintos, no otra vista de los mismos.
     *
     * La ventana por defecto vive aca y ademas en el backend
     * (VENTANA_DEFECTO). Las dos tienen que decir lo mismo: el front la manda
     * siempre explicita, asi que la del backend solo entra en juego si se
     * pega la URL del endpoint a mano, pero si se cambia una hay que cambiar
     * la otra o el chip marcado no coincide con lo que se sirve. */
    let usoDatos   = null;
    let usoVentana = '30d';

    async function cargarUso() {
        const cont = document.getElementById('db-uso');
        if (!cont) return;

        // Al cambiar de ventana ya hay un grafico en pantalla: en vez de
        // reemplazarlo por un cartel de "cargando" (que hace saltar el alto
        // de la pagina), se atenua el que esta y se cambia recien cuando
        // llegan los datos nuevos.
        const card = cont.querySelector('.uso-card');
        if (card) {
            card.classList.add('uso-cargando');
        } else {
            cont.innerHTML = `<div class="uso-card"><div class="uso-head"><div class="uso-title">
                    <i class="fa-solid fa-chart-line"></i><span>Uso por dispositivo</span>
                    <span class="uso-hint">cargando…</span>
                </div></div></div>`;
        }

        try {
            usoDatos = await api(`api/dashboard_senales?ventana=${encodeURIComponent(usoVentana)}`);
        } catch (err) {
            cont.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            return;
        }
        // El backend puede haber caido en su ventana de defecto si la clave
        // no le cerro; se toma la que efectivamente sirvio, para que el chip
        // marcado sea el que corresponde a lo que se esta viendo.
        usoVentana = usoDatos.resumen?.ventana || usoVentana;
        pintarUso();
    }

    function pintarUso() {
        const cont = document.getElementById('db-uso');
        if (!cont || !usoDatos) return;
        cont.innerHTML = vistaUso(usoDatos);
        activarUso(cont);
    }

    /* Las series que se DIBUJAN: las que tienen ranura de color mas, si hay,
     * el agregado "otros". El resto viaja igual en `data.series` y se lista
     * en la vista de tabla -- el tope de colores decide que se dibuja, no
     * que se informa. */
    function usoDibujadas(data) {
        const conColor = (data.series || []).filter((s) => s.slot);
        return data.otros ? conColor.concat([{ ...data.otros, slot: null }]) : conColor;
    }

    const claseSlot = (s) => `uso-s${s.slot || 0}`;

    function vistaUso(data) {
        const r = data.resumen || {};

        // "Comandos" y no "señales": el grafico cuenta solo los mensajes
        // `CMD=` -- las ordenes que se le mandan al equipo -- y no los latidos
        // ni los reportes periodicos, que llegan igual sin que nadie lo toque.
        // Ver la cabecera de api/dashboard_senales.php.
        const hint = [
            r.etiqueta || 'últimos 30 días',
            `${formatNumero(r.total || 0)} ${r.total === 1 ? 'comando' : 'comandos'}`,
            `${r.equipos_activos || 0} de ${r.equipos_dominio || 0} dispositivos`,
        ].join(' · ');

        // Los chips salen de `opciones` (los define el backend), no de una
        // lista propia: dos listas se desincronizan y el front terminaria
        // ofreciendo una ventana que el endpoint no sabe servir.
        const chips = (data.opciones || []).map((o) => `
            <button type="button" class="filter-chip${o.clave === usoVentana ? ' active' : ''}"
                    data-ventana="${escapeHtml(o.clave)}"
                    aria-pressed="${o.clave === usoVentana}">${escapeHtml(o.corta)}</button>`).join('');

        return `
            <div class="uso-card">
                <div class="uso-head">
                    <div class="uso-title">
                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                        <span>Uso por dispositivo</span>
                        <span class="uso-hint">${escapeHtml(hint)}</span>
                    </div>
                    <div class="uso-actions">
                        <div class="uso-chips" role="group" aria-label="Período">${chips}</div>
                        <a href="#/actividad" class="btn-icon-sm"
                           title="Ver actividad" aria-label="Ver actividad">
                            <i class="fa-solid fa-list" aria-hidden="true"></i>
                        </a>
                        <button type="button" class="btn-icon-sm" data-act="refrescar"
                                title="Refrescar" aria-label="Refrescar">
                            <i class="fa-solid fa-arrows-rotate" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
                ${cuerpoUso(data)}
            </div>`;
    }

    function cuerpoUso(data) {
        const r = data.resumen || {};

        if (!(data.series || []).length) {
            return `<div class="alert alert-info" style="margin:0">${sinUso(r)}</div>`;
        }

        const dibujadas = usoDibujadas(data);
        const leyenda = dibujadas.map((s) => `
            <span class="uso-legend-item ${claseSlot(s)}">
                <span class="uso-legend-line"></span>${escapeHtml(s.nombre)}
                <strong>${formatNumero(s.total)}</strong>
            </span>`).join('');

        return `<div class="uso-legend">${leyenda}</div>${graficoUso(data, dibujadas)}`;
    }

    // Mensaje de la ventana vacia. Que el dominio no haya registrado nada en
    // el periodo es un dato en si mismo, asi que se dice cuando fue la ultima
    // senal de vida conocida en lugar de dejar el grafico en blanco.
    function sinUso(r) {
        if (!r.equipos_dominio) {
            return 'El dominio todavía no tiene dispositivos adoptados.';
        }
        const cuando = formatDate(r.ultima_actividad);
        return `Ninguno de los ${r.equipos_dominio} dispositivos del dominio registró uso `
             + `en ${escapeHtml(r.periodo || 'el período')}.`
             + (cuando ? ` La última actividad conocida es del ${escapeHtml(cuando)}.` : '');
    }

    /* Geometria del plot. Sale de aca y no de cada funcion porque el render y
     * el hover tienen que usar EXACTAMENTE la misma escala: los puntos del
     * hover son circulos sueltos, no vertices de la polilinea, asi que si las
     * dos cuentas se separan el punto queda al lado de su linea. */
    function geometriaUso(data) {
        const n = (data.puntos || []).length || 1;
        const { w, h, padL, padR, padT, padB } = USO_VB;
        const plotW = w - padL - padR;
        const plotH = h - padT - padB;
        const { tope, ticks } = escalaUso(data.resumen?.maximo || 0);

        return {
            n, w, h, padL, padR, padT, padB, plotW, plotH, tope, ticks,
            x: (i) => padL + (plotW * (i + 0.5)) / n,
            y: (v) => padT + plotH - (plotH * v) / tope,
        };
    }

    function graficoUso(data, dibujadas) {
        const puntos = data.puntos || [];
        const porHora = data.granularidad === 'hora';
        const { n, w, h, padL, padR, padT, padB, plotW, plotH, ticks, x, y } = geometriaUso(data);

        // Guias y rotulos del eje Y en cifras redondas.
        const ejeY = ticks.map((v) => `
            <line class="uso-grid" x1="${padL}" y1="${y(v).toFixed(1)}" x2="${(w - padR).toFixed(1)}" y2="${y(v).toFixed(1)}"/>
            <text class="uso-tick" x="${padL - 8}" y="${(y(v) + 3.5).toFixed(1)}" text-anchor="end">${formatNumero(v)}</text>`).join('');

        // Eje X: un paso que deje las etiquetas sin pisarse, contado DESDE EL
        // ULTIMO PUNTO hacia atras. Anclarlo al ultimo y no al primero es lo
        // que garantiza que el punto en curso siempre lleve rotulo: si el
        // paso no divide justo a la ventana, contando desde el principio el
        // ultimo rotulo cae antes del final y el grafico se lee como si le
        // faltara el tramo mas reciente, que es el que mas se mira.
        const paso   = Math.max(1, Math.ceil(n / Math.max(1, Math.floor(plotW / 46))));
        const rotula = new Set();
        for (let i = n - 1; i >= 0; i -= paso) rotula.add(i);

        const ejeX = puntos.map((p, i) => (!rotula.has(i) ? '' : `
            <text class="uso-tick" x="${x(i).toFixed(1)}" y="${h - padB + 16}" text-anchor="middle">${etiquetaPunto(p, porHora)}</text>`)).join('');

        const lineas = dibujadas.map((s) => {
            const pts = (s.valores || []).map((v, i) => `${x(i).toFixed(1)},${y(v).toFixed(1)}`).join(' ');
            return `<polyline class="uso-linea ${claseSlot(s)}" points="${pts}"/>`;
        }).join('');

        // Un circulo por serie, escondido: el hover lo mueve a la columna que
        // se esta mirando en lugar de dibujar n x 7 puntos permanentes.
        const marcas = dibujadas.map((s) => `
            <circle class="uso-punto ${claseSlot(s)}" r="3.5" cx="0" cy="0" hidden/>`).join('');

        // Columnas invisibles: una por punto, el area sensible del hover. Son
        // mucho mas anchas que la linea, asi no hay que apuntarle al pixel.
        const hit = puntos.map((p, i) => `
            <rect class="uso-hit" x="${(padL + (plotW * i) / n).toFixed(1)}" y="${padT}"
                  width="${(plotW / n).toFixed(2)}" height="${plotH}"
                  data-i="${i}" data-x="${x(i).toFixed(1)}"/>`).join('');

        const alt = `Comandos por dispositivo y por ${porHora ? 'hora' : 'día'}, `
                  + `${escapeHtml(data.resumen?.etiqueta || '')}`;

        return `
            <div class="uso-plot">
                <svg class="uso-svg" viewBox="0 0 ${w} ${h}" preserveAspectRatio="xMidYMid meet"
                     role="img" aria-label="${alt}">
                    ${ejeY}${ejeX}
                    <line class="uso-cross" x1="0" y1="${padT}" x2="0" y2="${padT + plotH}" hidden/>
                    ${lineas}${marcas}${hit}
                </svg>
                <div class="uso-tip" hidden></div>
            </div>`;
    }

    const recortar = (t, max) => (String(t ?? '').length > max ? String(t).slice(0, max - 1) + '…' : String(t ?? ''));

    /* Tope y guias del eje Y en cifras redondas: el paso se elige de
     * 1/2/2.5/5/10 x 10^k para que caigan ~4 intervalos. Con el maximo crudo
     * como tope las guias darian numeros como 33 y 67. */
    function escalaUso(maximo) {
        const max   = Math.max(1, maximo);
        const crudo = max / 4;
        const pot   = Math.pow(10, Math.floor(Math.log10(crudo)));
        const paso  = [1, 2, 2.5, 5, 10].map((m) => m * pot).find((p) => p >= crudo) || 10 * pot;
        const tope  = Math.ceil(max / paso) * paso;

        const ticks = [];
        for (let v = 0; v <= tope + paso / 1000; v += paso) ticks.push(Math.round(v));
        return { tope, ticks };
    }

    /* Rotulo del eje X. La clave del punto es 'YYYY-MM-DD' en las ventanas
     * por dia y 'YYYY-MM-DD HH:00:00' en la de 24 h; el eje muestra 'DD/MM' o
     * 'HH:00' segun el caso. En 24 h la fecha no aporta -- son todas de hoy o
     * de ayer -- y la hora es justamente lo que se esta mirando. */
    function etiquetaPunto(punto, porHora) {
        const m = String(punto ?? '').match(/^(\d{4})-(\d{2})-(\d{2})(?:[ T](\d{2}))?/);
        if (!m) return '';
        return porHora ? `${m[4] ?? '00'}:00` : `${m[3]}/${m[2]}`;
    }

    /* Cablea lo que el HTML no puede: los chips de periodo, el boton de
     * refrescar y el hover del grafico (cruz vertical + puntos + tooltip).
     * El otro icono de la cabecera NO se cablea aca: es un <a href="#/actividad">
     * y lo resuelve el router por hash, asi que ademas sirve para abrir el
     * modulo en una pestaña nueva. Se llama despues de cada render. */
    function activarUso(cont) {
        const btnRefr = cont.querySelector('[data-act="refrescar"]');

        // Cambiar de ventana SI vuelve a pedir: son datos distintos, no otra
        // vista de los mismos. Se ignora el click en el chip ya activo para
        // no gastar una consulta en repintar lo mismo.
        cont.querySelectorAll('.uso-chips [data-ventana]').forEach((chip) => {
            chip.addEventListener('click', () => {
                if (chip.dataset.ventana === usoVentana) return;
                usoVentana = chip.dataset.ventana;
                cargarUso();
            });
        });

        btnRefr?.addEventListener('click', () => {
            btnRefr.querySelector('i')?.classList.add('fa-spin');
            cargarUso();
        });

        const plot = cont.querySelector('.uso-plot');
        if (!plot) return;

        const tip    = plot.querySelector('.uso-tip');
        const cruz   = plot.querySelector('.uso-cross');
        const puntos = [...plot.querySelectorAll('.uso-punto')];
        const dibujadas = usoDibujadas(usoDatos);
        const geo    = geometriaUso(usoDatos);

        plot.querySelectorAll('.uso-hit').forEach((celda) => {
            celda.addEventListener('mouseenter', () => {
                const i = +celda.dataset.i;

                cruz.setAttribute('x1', celda.dataset.x);
                cruz.setAttribute('x2', celda.dataset.x);
                cruz.removeAttribute('hidden');

                // Los puntos se crearon en el mismo orden que `dibujadas`, asi
                // que el indice alcanza para emparejarlos con su serie.
                puntos.forEach((p, k) => {
                    const s = dibujadas[k];
                    if (!s) return;
                    p.setAttribute('cx', geo.x(i).toFixed(1));
                    p.setAttribute('cy', geo.y(s.valores[i] || 0).toFixed(1));
                    p.removeAttribute('hidden');
                });

                // El tooltip se muestra ANTES de medirlo: mientras esta
                // `hidden` no tiene ancho, y sin ancho no se puede acotar a
                // los bordes del grafico.
                tip.innerHTML = tipUso(usoDatos, dibujadas, i);
                tip.removeAttribute('hidden');

                // Cuelga del borde de arriba del area de dibujo, no del
                // punto: la columna sensible es toda la altura del plot, asi
                // que anclarlo al cursor lo dejaria saltando. Y se corre para
                // adentro en los extremos, si no en el primer y el ultimo dia
                // se sale de la tarjeta.
                const c    = celda.getBoundingClientRect();
                const p    = plot.getBoundingClientRect();
                const medio = tip.offsetWidth / 2;
                const x     = c.left - p.left + c.width / 2;

                tip.style.left = `${Math.max(medio + 2, Math.min(p.width - medio - 2, x))}px`;
                tip.style.top  = `${c.top - p.top}px`;
            });
        });

        plot.addEventListener('mouseleave', () => {
            cruz.setAttribute('hidden', '');
            tip.setAttribute('hidden', '');
            puntos.forEach((p) => p.setAttribute('hidden', ''));
        });
    }

    /* Contenido del tooltip: el punto (dia u hora, segun la ventana) y,
     * debajo, cada serie dibujada con su cifra. Se ordena de mayor a menor
     * para que el orden de las filas coincida con el orden vertical de las
     * lineas en esa columna. */
    function tipUso(data, dibujadas, i) {
        const dia   = (data.puntos || [])[i];
        const filas = dibujadas
            .map((s) => ({ s, v: s.valores[i] || 0 }))
            .sort((a, b) => b.v - a.v)
            .map(({ s, v }) => `
                <div class="uso-tip-fila ${claseSlot(s)}">
                    <i></i>
                    <span class="uso-tip-nombre">${escapeHtml(recortar(s.nombre, 26))}</span>
                    <span class="uso-tip-valor">${formatNumero(v)}</span>
                </div>`).join('');

        return `<div class="uso-tip-fecha">${escapeHtml(formatDate(dia) || dia || '')}</div>${filas}`;
    }

    /* =========================================================
     * Modulo Facturacion (ficha unica, no ABM)
     * Portado de reactor-panel/comprobantes/facturacion.php: edita los datos
     * fiscales y de contacto del cliente al que se le facturan los servicios
     * del dominio. No hay listado ni alta -- el registro lo resuelve el
     * backend desde `dominios.cliente` (api/facturacion.php), aca no viaja
     * ningun id.
     *
     * La ficha entera vive en UNA tarjeta (.form-card) y adentro cada campo
     * es una tarjeta chica mas oscura (.view-card), igual que el modal de
     * Consultar de los ABM. La pantalla es SIEMPRE de lectura: el boton
     * "Editar" del pie abre un modal con el formulario, y la ficha se repinta
     * al guardar.
     *
     * ANTES LA EDICION ERA EN LA PROPIA PANTALLA -- las mismas tarjetas
     * cambiaban el valor por un input y el pie pasaba a Cancelar / Guardar.
     * Se movio al modal para que editar se vea igual en todo el panel: en el
     * resto de los modulos el formulario siempre llega en un modal, y una
     * pantalla que en cambio se transformaba en formulario dejaba a la persona
     * sin el limite visual que marca "esto es un formulario abierto, tiene que
     * cerrarse". Ademas el modal empuja al gris el fondo, y con la ficha
     * editandose en su lugar no habia nada que distinguiera lectura de
     * edicion salvo la forma de los controles.
     * ======================================================= */

    const facturacion = { cliente: null, condiciones: [] };

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
            const data = await api('api/facturacion');
            facturacion.cliente     = data.cliente     || null;
            facturacion.condiciones = data.condiciones || [];
        } catch (err) {
            ficha.innerHTML = '';
            aviso.innerHTML = `<div class="alert alert-error">${escapeHtml(err.message)}</div>`;
            return;
        }

        pintarFacturacion();
    }

    function fichaFacturacionLectura(c) {
        return [
            viewCard('Razón social',     escapeHtml(c.razon), true),
            viewCard('Condición fiscal', c.condicion ? escapeHtml(c.condicion_texto || c.condicion) : ''),
            viewCard('CUIT',             c.cuit ? `<code>${escapeHtml(c.cuit)}</code>` : ''),
            viewCard('Contacto',         escapeHtml(c.contacto), true),
            viewCard('Correo',           escapeHtml(c.correo)),
            viewCard('Celular',          escapeHtml(c.celular)),
        ].join('');
    }

    /* El formulario usa `.form-group` / `.form-row`, el mismo markup que el
       resto de los modales de edicion del panel (Editar dispositivo, Invitar
       usuario). NO reusa las `.view-card` de la ficha de lectura: esa tarjeta
       oscura es del modo consulta -- envolver un input en ella lo dejaba
       dentro de una caja que ningun otro formulario tiene. */
    function fichaFacturacionEdicion(c) {
        const opciones = ['<option value="">— Sin especificar —</option>'].concat(
            facturacion.condiciones.map((o) =>
                `<option value="${escapeHtml(o.valor)}"${o.valor === c.condicion ? ' selected' : ''}>${escapeHtml(o.texto)}</option>`)
        ).join('');

        return `
            <div class="form-group">
                <label for="fc-razon">Razón social *</label>
                <input type="text" id="fc-razon" maxlength="255" value="${escapeHtml(c.razon)}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="fc-condicion">Condición fiscal</label>
                    <select id="fc-condicion">${opciones}</select>
                </div>
                <div class="form-group">
                    <label for="fc-cuit">CUIT</label>
                    <input type="text" id="fc-cuit" maxlength="13" inputmode="numeric"
                           placeholder="11 dígitos" value="${escapeHtml(c.cuit)}">
                </div>
            </div>
            <div class="form-group">
                <label for="fc-contacto">Contacto</label>
                <input type="text" id="fc-contacto" maxlength="255" value="${escapeHtml(c.contacto)}">
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="fc-correo">Correo</label>
                    <input type="email" id="fc-correo" maxlength="255" value="${escapeHtml(c.correo)}">
                </div>
                <div class="form-group">
                    <label for="fc-celular">Celular</label>
                    <input type="tel" id="fc-celular" maxlength="255" value="${escapeHtml(c.celular)}">
                </div>
            </div>
        `;
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

        aviso.innerHTML = '';
        ficha.innerHTML = `
            <div class="form-card" id="fc-card">
                <div class="form-card-head">
                    <h3 class="form-card-title">Datos de facturación</h3>
                    <span class="form-card-hint">
                        Cliente <code>#${c.id}</code>${c.nombre ? ' · ' + escapeHtml(c.nombre) : ''}
                    </span>
                </div>

                <div class="view-grid">
                    ${fichaFacturacionLectura(c)}
                </div>

                <div class="form-card-foot">
                    <button type="button" class="btn btn-primary" data-act="editar">
                        <i class="fa-solid fa-pen"></i> Editar
                    </button>
                </div>
            </div>
        `;

        ficha.querySelector('[data-act="editar"]')
             .addEventListener('click', abrirModalFacturacion);
    }

    /* Modal de edicion. El formulario vive en el cuerpo y el boton Guardar en
       la barra de acciones, que openModal() dibuja FUERA del <form>: por eso
       lleva `form="fc-form"` -- el atributo de HTML5 que le da dueno a un
       control que esta afuera. Sin eso el submit no dispara y habria que
       duplicar el guardado en un listener de click. Enter dentro de cualquier
       campo entra por el mismo camino, como el submit del legacy. */
    function abrirModalFacturacion() {
        const c = facturacion.cliente;
        if (!c) return;

        // El <form> lleva `.form-stack` porque es hijo unico del `.modal-body`:
        // el gap del body separa a SUS hijos, y sin esto los form-group de
        // adentro quedaban pegados entre si.
        const body = `
            <form id="fc-form" class="form-stack" autocomplete="off">
                ${fichaFacturacionEdicion(c)}
                <div class="field-error" id="fc-error" style="display:none"></div>
            </form>
        `;

        const m = openModal('Editar datos de facturación', body, {
            wide:       true,
            closeLabel: 'Cancelar',
            primaryHtml: `<button type="submit" form="fc-form" class="btn btn-primary" data-act="guardar">
                              <i class="fa-solid fa-floppy-disk"></i> Guardar
                          </button>`,
        });

        m.backdrop.querySelector('#fc-form').addEventListener('submit', (e) => {
            e.preventDefault();
            guardarFacturacion(m);
        });
        m.backdrop.querySelector('#fc-razon').focus();
    }

    async function guardarFacturacion(m) {
        if (!facturacion.cliente) return;

        const scope = m.backdrop;
        const err   = scope.querySelector('#fc-error');
        const btn   = scope.querySelector('[data-act="guardar"]');
        const valor = (id) => scope.querySelector(id).value.trim();

        const payload = {
            razon:     valor('#fc-razon'),
            condicion: scope.querySelector('#fc-condicion').value,
            cuit:      valor('#fc-cuit'),
            contacto:  valor('#fc-contacto'),
            celular:   valor('#fc-celular'),
            correo:    valor('#fc-correo'),
        };

        btn.disabled      = true;
        err.style.display = 'none';
        try {
            const data = await api('api/facturacion', {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify(payload),
            });
            // Se repinta con lo que devolvio el backend: el CUIT vuelve
            // normalizado a digitos y los vacios como cadena vacia.
            facturacion.cliente = data.cliente || facturacion.cliente;
            m.close();
            pintarFacturacion();
            toast('Datos de facturación actualizados');
        } catch (e) {
            // El error se muestra DENTRO del modal y el modal queda abierto:
            // lo que rebota son validaciones del backend (CUIT, razon social)
            // y cerrarlo perderia lo tipeado.
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
            const data = await api('api/dominio');
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

    /* Las tres pantallas del agrupador "Cuenta" cuelgan del permiso
       `facturacion`. Sin el se BORRAN DEL ROUTER en vez de renderizar un cartel
       de "no tenes permiso": el sidebar tampoco las dibuja (index.php), asi que
       la unica forma de llegar es pegando el hash a mano, y ahi `currentRoute()`
       ya cae solo en Dashboard porque la clave no existe. Un cartel dedicado
       seria una pantalla nueva para un caso al que no se llega navegando.

       El backend corta igual con 403 (`requirePermisoPanel()`): esto es UI. */
    if (!puede('facturacion')) {
        delete routes.facturas;
        delete routes.recibos;
        delete routes.facturacion;
    }

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
                const res = await fetch('api/version', {
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
