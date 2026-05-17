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
    const hamburger        = document.getElementById('hamburger');
    const sidebar          = document.getElementById('sidebar');
    const sidebarOverlay   = document.getElementById('sidebar-overlay');
    const toastEl          = document.getElementById('toast');

    let pendingDevicesDomainFilter = null;
    let pendingSignalsDeviceFilter = null;

    /* ---------- Routing ---------- */
    const routes = {
        dashboard: { title: 'Dashboard',     render: renderDashboard, group: 'inicio'     },
        domains:   { title: 'Dominios',      render: renderDomains,   group: 'propiedad'  },
        devices:   { title: 'Dispositivos',  render: renderDevices,   group: 'inventario' },
        signals:   { title: 'Señales',       render: renderSignals,   group: 'registros'  },
        alerts:    { title: 'Alertas',       render: renderStub,      group: 'registros'  },
        users:     { title: 'Usuarios',      render: renderUsers,     group: 'seguridad'  },
        profiles:  { title: 'Perfiles',      render: renderProfiles,  group: 'seguridad'  },
        settings:  { title: 'Configuración', render: renderStub,      group: null         },
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
        });
        let body;
        try { body = await res.json(); } catch (_) { body = null; }

        if (!res.ok || !body || body.ok === false) {
            const msg = (body && body.error) ? body.error : `HTTP ${res.status}`;
            throw new Error(msg);
        }
        return body.data;
    }

    /* ---------- Views: Dashboard ---------- */
    async function renderDashboard(root) {
        try {
            const data = await api('devices.php');
            const s = data.resumen;
            const recent = data.dispositivos.slice(0, 5);

            root.innerHTML = `
                <div class="stats-bar">
                    <div class="stat-card dash-link" data-go="devices">
                        <span class="stat-label">Total dispositivos</span>
                        <span class="stat-value">${s.total}</span>
                    </div>
                    <div class="stat-card dash-link" data-go="devices">
                        <span class="stat-label">Online</span>
                        <span class="stat-value green">${s.online}</span>
                    </div>
                    <div class="stat-card dash-link" data-go="devices">
                        <span class="stat-label">Offline</span>
                        <span class="stat-value muted">${s.offline}</span>
                    </div>
                    <div class="stat-card dash-link" data-go="devices">
                        <span class="stat-label">Con error</span>
                        <span class="stat-value red">${s.error}</span>
                    </div>
                </div>

                <div class="dash-grid">
                    <div class="table-card">
                        <div class="dash-table-header">
                            <span>🛰️ Dispositivos recientes</span>
                            <a href="#/devices" class="dash-ver-mas">Ver todos →</a>
                        </div>
                        ${devicesTableBody(recent)}
                    </div>

                    <div class="table-card">
                        <div class="dash-table-header">
                            <span>⚠️ Con problemas</span>
                            <a href="#/alerts" class="dash-ver-mas">Ver alertas →</a>
                        </div>
                        ${devicesTableBody(data.dispositivos.filter(d => d.estado !== 'online').slice(0, 5))}
                    </div>
                </div>
            `;

            root.querySelectorAll('[data-go]').forEach(el => {
                el.addEventListener('click', () => { window.location.hash = '#/' + el.dataset.go; });
            });
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    /* ---------- Views: Dispositivos ---------- */
    async function renderDevices(root) {
        try {
            const [data, domData] = await Promise.all([
                api('devices.php'),
                api('domains.php'),
            ]);
            const dominios = domData.dominios;
            const domainOptions = ['<option value="">Todos los dominios</option>']
                .concat(dominios.map(d => `<option value="${d.id}">${escape(d.nombre)}</option>`))
                .join('');

            root.innerHTML = `
                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <input type="search" class="search-input" id="dev-search" placeholder="Buscar dispositivo…">
                            <button class="search-clear" id="dev-search-clear" aria-label="Limpiar">×</button>
                        </div>
                        <select id="dev-domain-filter">${domainOptions}</select>
                        <button class="filter-chip active" data-filter="all">Todos</button>
                        <button class="filter-chip" data-filter="online">Online</button>
                        <button class="filter-chip" data-filter="offline">Offline</button>
                        <button class="filter-chip" data-filter="error">Con error</button>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn btn-ghost btn-sm"><i class="fa-solid fa-file-export"></i> Exportar</button>
                        <button class="btn btn-primary btn-sm"><i class="fa-solid fa-plus"></i> Nuevo</button>
                    </div>
                </div>

                <div class="table-card" id="dev-table">${devicesTableBody(data.dispositivos)}</div>
            `;

            wireDevicesToolbar(data.dispositivos);

            if (pendingDevicesDomainFilter != null) {
                const sel = document.getElementById('dev-domain-filter');
                sel.value = String(pendingDevicesDomainFilter);
                pendingDevicesDomainFilter = null;
                sel.dispatchEvent(new Event('change'));
            }
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function devicesTableBody(dispositivos) {
        if (!dispositivos.length) {
            return `<div class="table-empty">No hay dispositivos para mostrar.</div>`;
        }

        const rows = dispositivos.map(d => `
            <tr data-id="${d.id}">
                <td><span class="td-id">${escape(d.uid)}</span></td>
                <td class="td-nombre">${escape(d.nombre)}</td>
                <td>${escape(d.tipo)}</td>
                <td><span class="badge badge-info">${escape(d.dominio_nombre)}</span></td>
                <td>${escape(d.ubicacion ?? '—')}</td>
                <td>${statusBadge(d.estado)}</td>
                <td>${formatDate(d.last_seen_at)}</td>
                <td class="actions">
                    <button class="btn-icon-sm" data-act="view"   title="Ver"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn-icon-sm" data-act="config" title="Editar configuración JSON"><i class="fa-solid fa-pencil"></i></button>
                </td>
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>UID</th>
                        <th>Nombre</th>
                        <th>Tipo</th>
                        <th>Dominio</th>
                        <th>Ubicación</th>
                        <th>Estado</th>
                        <th>Última conexión</th>
                        <th></th>
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

    function wireDevicesToolbar(allDispositivos) {
        const tableWrap   = document.getElementById('dev-table');
        const searchInput = document.getElementById('dev-search');
        const searchClear = document.getElementById('dev-search-clear');
        const domainSel   = document.getElementById('dev-domain-filter');
        const chips       = document.querySelectorAll('.filter-chip[data-filter]');
        let activeFilter  = 'all';

        function applyFilters() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const dom = domainSel.value;
            const filtered = allDispositivos.filter(d => {
                if (activeFilter !== 'all' && d.estado !== activeFilter) return false;
                if (dom && String(d.dominio_id) !== dom) return false;
                if (!q) return true;
                return (d.uid + ' ' + d.nombre + ' ' + d.tipo + ' ' + (d.dominio_nombre || '') + ' ' + (d.ubicacion || ''))
                    .toLowerCase().includes(q);
            });
            tableWrap.innerHTML = devicesTableBody(filtered);
        }

        chips.forEach(c => c.addEventListener('click', () => {
            chips.forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            activeFilter = c.dataset.filter;
            applyFilters();
        }));
        searchInput.addEventListener('input', applyFilters);
        searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); });
        domainSel.addEventListener('change', applyFilters);
    }

    /* ---------- Views: Dominios ---------- */
    async function renderDomains(root) {
        try {
            const data = await api('domains.php');
            root.innerHTML = `
                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <input type="search" class="search-input" id="dom-search" placeholder="Buscar dominio…">
                            <button class="search-clear" id="dom-search-clear" aria-label="Limpiar">×</button>
                        </div>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn btn-primary btn-sm" id="dom-new">
                            <i class="fa-solid fa-plus"></i> Nuevo dominio
                        </button>
                    </div>
                </div>

                <div class="table-card" id="dom-table">${domainsTableBody(data.dominios)}</div>
            `;

            wireDomainsView(data.dominios);
        } catch (e) {
            root.innerHTML = errorBox(e.message);
        }
    }

    function domainsTableBody(dominios) {
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
                <td class="actions">
                    <button class="btn-icon-sm" data-act="view"   title="Ver"><i class="fa-solid fa-eye"></i></button>
                    <button class="btn-icon-sm" data-act="edit"   title="Editar"><i class="fa-solid fa-pencil"></i></button>
                    <button class="btn-icon-sm" data-act="delete" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Descripción</th>
                        <th>Dispositivos</th>
                        <th>Creado</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>${rows}</tbody>
            </table>
        `;
    }

    function wireDomainsView(allDominios) {
        const tableWrap   = document.getElementById('dom-table');
        const searchInput = document.getElementById('dom-search');
        const searchClear = document.getElementById('dom-search-clear');
        const btnNew      = document.getElementById('dom-new');

        function applyFilters() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const filtered = !q ? allDominios : allDominios.filter(d =>
                (d.nombre + ' ' + (d.descripcion || '')).toLowerCase().includes(q)
            );
            tableWrap.innerHTML = domainsTableBody(filtered);
            wireRowActions();
        }

        function wireRowActions() {
            tableWrap.querySelectorAll('button[data-act]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = +btn.closest('tr').dataset.id;
                    const dom = allDominios.find(x => x.id === id);
                    if (!dom) return;
                    if (btn.dataset.act === 'view')   openDomainViewModal(dom);
                    if (btn.dataset.act === 'edit')   openDomainModal(dom);
                    if (btn.dataset.act === 'delete') confirmDeleteDomain(dom);
                });
            });
        }

        searchInput.addEventListener('input', applyFilters);
        searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); });
        btnNew.addEventListener('click', () => openDomainModal(null));

        wireRowActions();
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
                    await api('domains.php', { method: 'PUT', body: { id: dom.id, nombre, descripcion } });
                    toast('Dominio actualizado');
                } else {
                    await api('domains.php', { method: 'POST', body: { nombre, descripcion } });
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
        backdrop.innerHTML = `
            <div class="modal" role="dialog" aria-modal="true">
                <div class="modal-header">
                    <div class="modal-title">Detalle del dominio</div>
                    <button class="btn-icon-sm" data-act="close" aria-label="Cerrar">×</button>
                </div>
                <div class="modal-body">
                    <dl class="data-list">
                        <div class="data-row">
                            <dt class="data-label">ID</dt>
                            <dd class="data-value"><code>#${dom.id}</code></dd>
                        </div>
                        <div class="data-row">
                            <dt class="data-label">Nombre</dt>
                            <dd class="data-value">${escape(dom.nombre)}</dd>
                        </div>
                        <div class="data-row">
                            <dt class="data-label">Descripción</dt>
                            <dd class="data-value ${dom.descripcion ? '' : 'muted'}">${escape(dom.descripcion ?? 'Sin descripción')}</dd>
                        </div>
                        <div class="data-row">
                            <dt class="data-label">Dispositivos asociados</dt>
                            <dd class="data-value"><span class="badge badge-info">${dom.dispositivos_count}</span></dd>
                        </div>
                        <div class="data-row">
                            <dt class="data-label">Creado</dt>
                            <dd class="data-value">${formatDate(dom.created_at)}</dd>
                        </div>
                        <div class="data-row">
                            <dt class="data-label">Última actualización</dt>
                            <dd class="data-value">${formatDate(dom.updated_at)}</dd>
                        </div>
                    </dl>
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
                    pendingDevicesDomainFilter = dom.id;
                    window.location.hash = '#/devices';
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
        confirmDialog(
            'Eliminar dominio',
            `¿Eliminar el dominio "${dom.nombre}"? Esta acción no se puede deshacer.` +
            (dom.dispositivos_count > 0 ? ` Tiene ${dom.dispositivos_count} dispositivo(s) asociado(s).` : ''),
            async () => {
                try {
                    await api('domains.php?id=' + dom.id, { method: 'DELETE' });
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

    async function renderUsers(root) {
        try {
            const data = await api('users.php');
            const r = data.resumen;

            root.innerHTML = `
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

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <input type="search" class="search-input" id="usr-search" placeholder="Buscar por email o nombre…">
                            <button class="search-clear" id="usr-search-clear" aria-label="Limpiar">×</button>
                        </div>
                        <button class="filter-chip active" data-filter="all">Todos</button>
                        <button class="filter-chip" data-filter="admin">Admin</button>
                        <button class="filter-chip" data-filter="operador">Operador</button>
                        <button class="filter-chip" data-filter="lectura">Lectura</button>
                        <button class="filter-chip" data-filter="activos">Solo activos</button>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn btn-primary btn-sm" id="usr-new">
                            <i class="fa-solid fa-plus"></i> Nuevo usuario
                        </button>
                    </div>
                </div>

                <div class="table-card" id="usr-table">${usuariosTableBody(data.usuarios)}</div>
            `;

            wireUsersView(data.usuarios);
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
                <td class="actions">
                    <button class="btn-icon-sm" data-act="edit"   title="Editar"><i class="fa-solid fa-pencil"></i></button>
                    <button class="btn-icon-sm" data-act="delete" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Nombre</th>
                        <th>Email</th>
                        <th>Celular</th>
                        <th>Rol</th>
                        <th>Estado</th>
                        <th>Último login</th>
                        <th>Creado</th>
                        <th></th>
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

    function wireUsersView(allUsuarios) {
        const tableWrap   = document.getElementById('usr-table');
        const searchInput = document.getElementById('usr-search');
        const searchClear = document.getElementById('usr-search-clear');
        const btnNew      = document.getElementById('usr-new');
        const chips       = document.querySelectorAll('.filter-chip[data-filter]');
        let activeFilter  = 'all';

        function applyFilters() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const filtered = allUsuarios.filter(u => {
                if (activeFilter === 'activos') {
                    if (!u.activo) return false;
                } else if (activeFilter !== 'all' && u.rol !== activeFilter) {
                    return false;
                }
                if (!q) return true;
                return (u.email + ' ' + u.nombre + ' ' + (u.celular || '')).toLowerCase().includes(q);
            });
            tableWrap.innerHTML = usuariosTableBody(filtered);
            wireRowActions();
        }

        function wireRowActions() {
            tableWrap.querySelectorAll('button[data-act]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = +btn.closest('tr').dataset.id;
                    const u  = allUsuarios.find(x => x.id === id);
                    if (!u) return;
                    if (btn.dataset.act === 'edit')   openUserModal(u);
                    if (btn.dataset.act === 'delete') confirmDeleteUser(u);
                });
            });
        }

        chips.forEach(c => c.addEventListener('click', () => {
            chips.forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            activeFilter = c.dataset.filter;
            applyFilters();
        }));
        searchInput.addEventListener('input', applyFilters);
        searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); });
        btnNew.addEventListener('click', () => openUserModal(null));

        wireRowActions();
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

    async function renderProfiles(root) {
        try {
            const [data, usrData, domData] = await Promise.all([
                api('profiles.php'),
                api('users.php'),
                api('domains.php'),
            ]);
            const r = data.resumen;

            root.innerHTML = `
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

                <div class="toolbar">
                    <div class="toolbar-left">
                        <div class="search-wrap">
                            <input type="search" class="search-input" id="prf-search" placeholder="Buscar por usuario o dominio…">
                            <button class="search-clear" id="prf-search-clear" aria-label="Limpiar">×</button>
                        </div>
                        <button class="filter-chip active" data-filter="all">Todos</button>
                        <button class="filter-chip" data-filter="admin">Admin</button>
                        <button class="filter-chip" data-filter="operador">Operador</button>
                    </div>
                    <div class="toolbar-right">
                        <button class="btn btn-primary btn-sm" id="prf-new">
                            <i class="fa-solid fa-plus"></i> Nuevo perfil
                        </button>
                    </div>
                </div>

                <div class="table-card" id="prf-table">${perfilesTableBody(data.perfiles)}</div>
            `;

            wireProfilesView(data.perfiles, usrData.usuarios, domData.dominios);
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
                <td class="actions">
                    <button class="btn-icon-sm" data-act="edit"   title="Editar"><i class="fa-solid fa-pencil"></i></button>
                    <button class="btn-icon-sm" data-act="delete" title="Eliminar"><i class="fa-solid fa-trash"></i></button>
                </td>
            </tr>
        `).join('');

        return `
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Usuario</th>
                        <th>Dominio</th>
                        <th>Rol</th>
                        <th>Creado</th>
                        <th></th>
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

    function wireProfilesView(allPerfiles, allUsuarios, allDominios) {
        const tableWrap   = document.getElementById('prf-table');
        const searchInput = document.getElementById('prf-search');
        const searchClear = document.getElementById('prf-search-clear');
        const btnNew      = document.getElementById('prf-new');
        const chips       = document.querySelectorAll('.filter-chip[data-filter]');
        let activeFilter  = 'all';

        function applyFilters() {
            const q = (searchInput.value || '').trim().toLowerCase();
            const filtered = allPerfiles.filter(p => {
                if (activeFilter !== 'all' && p.rol !== activeFilter) return false;
                if (!q) return true;
                return (p.usuario_nombre + ' ' + p.usuario_email + ' ' + p.dominio_nombre)
                    .toLowerCase().includes(q);
            });
            tableWrap.innerHTML = perfilesTableBody(filtered);
            wireRowActions();
        }

        function wireRowActions() {
            tableWrap.querySelectorAll('button[data-act]').forEach(btn => {
                btn.addEventListener('click', () => {
                    const id = +btn.closest('tr').dataset.id;
                    const p  = allPerfiles.find(x => x.id === id);
                    if (!p) return;
                    if (btn.dataset.act === 'edit')   openProfileModal(p, allUsuarios, allDominios);
                    if (btn.dataset.act === 'delete') confirmDeleteProfile(p);
                });
            });
        }

        chips.forEach(c => c.addEventListener('click', () => {
            chips.forEach(x => x.classList.remove('active'));
            c.classList.add('active');
            activeFilter = c.dataset.filter;
            applyFilters();
        }));
        searchInput.addEventListener('input', applyFilters);
        searchClear.addEventListener('click', () => { searchInput.value = ''; applyFilters(); });
        btnNew.addEventListener('click', () => openProfileModal(null, allUsuarios, allDominios));

        wireRowActions();
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
