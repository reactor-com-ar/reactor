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

    /* expuesto para modulos futuros */
    window.Panel = { api, toast };
})();
