(() => {
    'use strict';

    const view  = document.getElementById('view');
    const title = document.getElementById('view-title');
    const navItems = document.querySelectorAll('.nav-item');
    const btnRefresh = document.getElementById('btn-refresh');

    const routes = {
        dashboard: { title: 'Dashboard',     render: renderDashboard },
        devices:   { title: 'Dispositivos',  render: renderDevices   },
        alerts:    { title: 'Alertas',       render: renderStub      },
        users:     { title: 'Usuarios',      render: renderStub      },
        settings:  { title: 'Configuracion', render: renderStub      },
    };

    function currentRoute() {
        const hash = window.location.hash.replace('#/', '');
        return routes[hash] ? hash : 'dashboard';
    }

    function navigate() {
        const key = currentRoute();
        const route = routes[key];

        title.textContent = route.title;
        navItems.forEach(n => n.classList.toggle('active', n.dataset.route === key));

        view.innerHTML = '<div class="loader"><i class="bi bi-arrow-repeat"></i> Cargando...</div>';
        route.render(view);
    }

    window.addEventListener('hashchange', navigate);
    btnRefresh.addEventListener('click', navigate);
    document.addEventListener('DOMContentLoaded', navigate);

    // ---------- API ----------
    async function api(path) {
        const res = await fetch(`api/${path}`, { headers: { 'Accept': 'application/json' } });
        if (!res.ok) {
            throw new Error(`HTTP ${res.status}`);
        }
        return res.json();
    }

    // ---------- Views ----------
    async function renderDashboard(root) {
        try {
            const data = await api('devices.php');
            const s = data.summary;

            root.innerHTML = `
                <div class="kpi-grid">
                    <div class="kpi-card">
                        <div>
                            <div class="kpi-label">Total dispositivos</div>
                            <div class="kpi-value">${s.total}</div>
                        </div>
                        <i class="bi bi-hdd-stack kpi-icon"></i>
                    </div>
                    <div class="kpi-card kpi-online">
                        <div>
                            <div class="kpi-label">Online</div>
                            <div class="kpi-value">${s.online}</div>
                        </div>
                        <i class="bi bi-wifi kpi-icon"></i>
                    </div>
                    <div class="kpi-card kpi-offline">
                        <div>
                            <div class="kpi-label">Offline</div>
                            <div class="kpi-value">${s.offline}</div>
                        </div>
                        <i class="bi bi-wifi-off kpi-icon"></i>
                    </div>
                    <div class="kpi-card kpi-error">
                        <div>
                            <div class="kpi-label">Con error</div>
                            <div class="kpi-value">${s.error}</div>
                        </div>
                        <i class="bi bi-exclamation-triangle kpi-icon"></i>
                    </div>
                </div>

                ${devicesPanel(data.devices.slice(0, 5), 'Dispositivos recientes')}
            `;
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    async function renderDevices(root) {
        try {
            const data = await api('devices.php');
            root.innerHTML = devicesPanel(data.devices, 'Todos los dispositivos');
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function renderStub(root) {
        root.innerHTML = `
            <div class="panel">
                <div class="empty-state">
                    <i class="bi bi-tools" style="font-size:32px;"></i>
                    <p class="mt-2 mb-0">Modulo en construccion.</p>
                </div>
            </div>
        `;
    }

    // ---------- Helpers ----------
    function devicesPanel(devices, heading) {
        if (!devices.length) {
            return `<div class="panel"><div class="empty-state">No hay dispositivos registrados.</div></div>`;
        }

        const rows = devices.map(d => `
            <tr>
                <td><strong>${escape(d.uid)}</strong></td>
                <td>${escape(d.name)}</td>
                <td>${escape(d.type)}</td>
                <td>${escape(d.location ?? '-')}</td>
                <td><span class="status-pill status-${d.status}">${d.status}</span></td>
                <td>${formatDate(d.last_seen_at)}</td>
            </tr>
        `).join('');

        return `
            <div class="panel">
                <div class="panel-header">
                    <h2 class="panel-title">${heading}</h2>
                    <button class="btn btn-sm btn-rx"><i class="bi bi-plus-lg"></i> Nuevo</button>
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>UID</th>
                            <th>Nombre</th>
                            <th>Tipo</th>
                            <th>Ubicacion</th>
                            <th>Estado</th>
                            <th>Ultima conexion</th>
                        </tr>
                    </thead>
                    <tbody>${rows}</tbody>
                </table>
            </div>
        `;
    }

    function errorBox(msg) {
        return `
            <div class="panel">
                <div class="empty-state" style="color:var(--rx-red)">
                    <i class="bi bi-exclamation-octagon" style="font-size:32px;"></i>
                    <p class="mt-2 mb-0">Error: ${escape(msg)}</p>
                </div>
            </div>
        `;
    }

    function formatDate(s) {
        if (!s) return '-';
        const d = new Date(s.replace(' ', 'T'));
        if (isNaN(d)) return escape(s);
        return d.toLocaleString('es-AR');
    }

    function escape(s) {
        return String(s).replace(/[&<>"']/g, c => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[c]));
    }
})();
