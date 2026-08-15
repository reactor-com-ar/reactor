/* Reactor App (end-user) — mockup visual.
 *
 * Cabla:
 *  - toggle del sidebar en mobile,
 *  - click en botones de control (toast de placeholder),
 *  - navegacion entre acciones del topbar y sidebar (visual only),
 *  - banner de nueva version (poll a api/version.php cada 60s),
 *  - registro del service-worker si existe (a futuro).
 */

(function () {
    'use strict';

    // ---------- Sidebar toggle (mobile) ----------
    var hamburger = document.getElementById('hamburger');
    var sidebar   = document.getElementById('sidebar');
    var overlay   = document.getElementById('sidebar-overlay');

    function openSidebar()  { sidebar.classList.add('open');  overlay.classList.add('open');  }
    function closeSidebar() { sidebar.classList.remove('open'); overlay.classList.remove('open'); }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            if (sidebar.classList.contains('open')) closeSidebar(); else openSidebar();
        });
    }
    if (overlay) overlay.addEventListener('click', closeSidebar);

    // Cerrar sidebar al elegir un item (mobile)
    document.querySelectorAll('.sidebar .nav-item').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll('.sidebar .nav-item').forEach(function (x) { x.classList.remove('active'); });
            el.classList.add('active');
            if (window.matchMedia('(max-width: 720px)').matches) closeSidebar();
        });
    });

    // ---------- Topbar actions (selector visual) ----------
    document.querySelectorAll('.topbar-action').forEach(function (el) {
        el.addEventListener('click', function () {
            document.querySelectorAll('.topbar-action').forEach(function (x) { x.classList.remove('active'); });
            el.classList.add('active');
            showToast(el.getAttribute('title') || 'Accion');
        });
    });

    // ---------- Click en botones de control ----------
    document.querySelectorAll('.tec-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var accion  = btn.getAttribute('data-accion')  || 'Accion';
            var control = btn.getAttribute('data-control') || '';
            showToast(control ? (control + ' — ' + accion) : accion);
        });
    });

    // ---------- Toast ----------
    var toastEl = document.getElementById('toast');
    var toastTimer = null;
    function showToast(msg) {
        if (!toastEl) return;
        toastEl.textContent = msg;
        toastEl.classList.add('show');
        if (toastTimer) clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.classList.remove('show'); }, 1600);
    }

    // ---------- Version banner (poll cada 60s) ----------
    var baselineVersion = document.body.getAttribute('data-version') || '';
    var banner       = document.getElementById('version-banner');
    var bannerBtn    = document.getElementById('version-banner-btn');
    var bannerShown  = false;

    if (bannerBtn) {
        bannerBtn.addEventListener('click', function () { window.location.reload(); });
    }

    function checkVersion() {
        if (bannerShown) return;
        fetch('api/version.php', { cache: 'no-store' })
            .then(function (r) { return r.ok ? r.json() : null; })
            .then(function (j) {
                if (!j || !j.ok || !j.version) return;
                if (j.version !== baselineVersion) {
                    banner.hidden = false;
                    document.body.classList.add('has-banner');
                    bannerShown = true;
                }
            })
            .catch(function () { /* silenciar errores transitorios */ });
    }

    if (banner) {
        setInterval(checkVersion, 60000);
    }

    // ---------- Install button (placeholder) ----------
    var btnInstall = document.getElementById('btn-install');
    if (btnInstall) {
        btnInstall.addEventListener('click', function (e) {
            e.preventDefault();
            showToast('Instalar la app');
        });
    }
})();
