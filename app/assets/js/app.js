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

    // ---------- fetch con corte duro ----------
    //
    // `fetch` NO TIENE TIMEOUT. Una request que queda colgada —el caso tipico
    // del celular que vuelve de Doze con la radio a medio reconectar, que abre
    // el socket y no recibe nada— no resuelve NI rechaza NUNCA: ni el `.then`
    // ni el `.catch` ni el `.finally` llegan a correr.
    //
    // Eso deja rota la pagina de formas que no se ven:
    //
    //   - `refrescarEstado()` deja `estadoEnVuelo = true` para siempre y el
    //     panel no vuelve a actualizarse nunca, aunque la conexion ya haya
    //     vuelto. Los displays quedan mostrando el estado de hace horas.
    //   - el boton del control queda `disabled` de por vida: el `.finally()`
    //     que lo reactiva nunca corre.
    //   - los modales de historial se quedan en "Cargando..." para siempre.
    //
    // Con el corte, la promesa SIEMPRE termina —resuelta o rechazada— y cada
    // uno de esos `finally` corre. Un tick perdido se recupera en el siguiente.
    var CORTE_GET  = 8000;
    // Los POST llevan mas plazo: del otro lado hay una orden que viaja al
    // broker MQTT, y cortar antes de tiempo diria "no se pudo" sobre algo que
    // en realidad se mando. El corte igual hace falta, porque quedarse colgado
    // deja el boton apagado hasta que se recargue la pagina.
    var CORTE_POST = 15000;

    function fetchCorte(url, opciones, ms) {
        var op = {};
        var k;
        for (k in (opciones || {})) {
            if (Object.prototype.hasOwnProperty.call(opciones, k)) op[k] = opciones[k];
        }

        var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
        if (ctrl) op.signal = ctrl.signal;

        var reloj = setTimeout(function () { if (ctrl) ctrl.abort(); }, ms || CORTE_GET);

        return fetch(url, op).then(
            function (r) { clearTimeout(reloj); return r; },
            function (e) { clearTimeout(reloj); throw e; }
        );
    }

    // ---------- Sidebar ----------
    // Se cierra en CUALQUIER ancho, pero de dos maneras distintas: en mobile
    // es un cajon flotante (.open + overlay), en desktop esta en el flujo y se
    // corre hacia afuera con margin negativo (.oculto). Por eso hay dos clases
    // y no una: el estado inicial tambien difiere (visible en desktop, oculto
    // en mobile), y una sola clase no puede representar los dos defaults.
    var hamburger = document.getElementById('hamburger');
    var sidebar   = document.getElementById('sidebar');
    var overlay   = document.getElementById('sidebar-overlay');

    function esMobile() { return window.matchMedia('(max-width: 720px)').matches; }

    function sidebarAbierto() {
        return esMobile() ? sidebar.classList.contains('open')
                          : !sidebar.classList.contains('oculto');
    }
    function abrirSidebar() {
        sidebar.classList.remove('oculto');
        if (esMobile()) { sidebar.classList.add('open'); overlay.classList.add('open'); }
    }
    function cerrarSidebar() {
        sidebar.classList.remove('open');
        overlay.classList.remove('open');
        if (!esMobile()) sidebar.classList.add('oculto');
    }

    if (hamburger) {
        hamburger.addEventListener('click', function () {
            if (sidebarAbierto()) cerrarSidebar(); else abrirSidebar();
        });
    }
    if (overlay) overlay.addEventListener('click', cerrarSidebar);

    // Elegir un item cierra el menu. Los items con submenu (.nav-toggle) quedan
    // afuera: cerrar al desplegarlos escondería el submenu recien abierto.
    document.querySelectorAll('.sidebar .nav-item:not(.nav-toggle), .sidebar .nav-subitem')
        .forEach(function (el) {
            el.addEventListener('click', function () {
                // Los que abren en otra pestaña (mesa de ayuda) no cambian de
                // pagina, asi que no deben quedar marcados como el item activo.
                if (el.getAttribute('target') !== '_blank') {
                    document.querySelectorAll('.sidebar .nav-item, .sidebar .nav-subitem')
                        .forEach(function (x) { x.classList.remove('active'); });
                    el.classList.add('active');
                }
                cerrarSidebar();
            });
        });

    // ---------- Submenus del sidebar (visual only) ----------
    // Acordeon: solo uno abierto a la vez, abrir uno cierra los demas.
    function setSubmenu(toggle, open) {
        var sub = document.getElementById(toggle.getAttribute('aria-controls'));
        if (!sub) return;
        sub.classList.toggle('open', open);
        toggle.classList.toggle('expanded', open);
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
    }

    document.querySelectorAll('.sidebar .nav-toggle').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            var abrir = !el.classList.contains('expanded');
            document.querySelectorAll('.sidebar .nav-toggle').forEach(function (x) {
                setSubmenu(x, false);
            });
            if (abrir) setSubmenu(el, true);
        });
    });

    // ---------- Modales ----------
    function abrirModal(id) {
        var m = document.getElementById(id);
        if (!m) return;
        m.classList.add('abierto');
        // La contraseña no viaja en el HTML de la pagina: se pide recien
        // cuando el usuario abre el modal.
        if (id === 'modal-usuario') cargarContrasena();
        // Estas dos se releen SIEMPRE: son historiales que cambian.
        if (id === 'modal-actividad')      cargarActividad();
        if (id === 'modal-notificaciones') cargarNotificaciones();
        if (id === 'modal-dominio')        cargarDominios();
        if (id === 'modal-panel')          cargarPaneles();
        // Se vacia al abrir: el modal queda abierto despues de invitar (para
        // mandar otra), asi que sin esto la proxima apertura mostraria el
        // correo y el aviso de la invitacion anterior.
        if (id === 'modal-invitar')        limpiarInvitacion();
        // El entorno se relee en cada apertura: storage y cookies cambian.
        if (id === 'modal-entorno') cargarEntorno();
        var foco = m.querySelector('input:not([type=hidden])');
        if (foco) foco.focus();
    }
    function cerrarModal(m) {
        if (m) m.classList.remove('abierto');
    }

    document.querySelectorAll('[data-modal]').forEach(function (el) {
        el.addEventListener('click', function (e) {
            e.preventDefault();
            abrirModal(el.getAttribute('data-modal'));
        });
    });

    document.querySelectorAll('.modal-fondo').forEach(function (m) {
        // Click en el fondo (no en la tarjeta) cierra.
        m.addEventListener('click', function (e) {
            if (e.target === m) cerrarModal(m);
        });
        m.querySelectorAll('[data-modal-cerrar]').forEach(function (btn) {
            btn.addEventListener('click', function () { cerrarModal(m); });
        });
    });

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.modal-fondo.abierto').forEach(cerrarModal);
        }
    });

    // ---------- Historiales (Actividad / Notificaciones) ----------
    // Los dos modales son la misma mecanica: pedir al endpoint y pintar una
    // lista scrolleable. Lo unico distinto es como se dibuja cada fila.
    function escapar(txt) {
        var d = document.createElement('div');
        d.textContent = txt == null ? '' : String(txt);
        return d.innerHTML;
    }

    function cargarHistorial(cfg) {
        var caja = document.getElementById(cfg.contenedor);
        if (!caja) return;
        caja.innerHTML = '<p class="lista-aviso">Cargando&hellip;</p>';

        fetchCorte(cfg.url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        }, CORTE_GET)
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) {
                if (!j || j.ok !== true) {
                    caja.innerHTML = '<p class="lista-aviso error">' +
                        escapar((j && j.error) ? j.error : 'No se pudo cargar.') + '</p>';
                    return;
                }
                var filas = j[cfg.campo] || [];
                if (!filas.length) {
                    caja.innerHTML = '<p class="lista-aviso">' + escapar(cfg.vacio) + '</p>';
                    return;
                }
                caja.innerHTML = filas.map(cfg.fila).join('');
            })
            .catch(function () {
                caja.innerHTML = '<p class="lista-aviso error">No se pudo conectar con el servidor.</p>';
            });
    }

    function cargarActividad() {
        cargarHistorial({
            url: 'api/actividad',
            contenedor: 'actividad-lista',
            campo: 'eventos',
            vacio: 'Todavía no hay actividad registrada.',
            fila: function (e) {
                return '<div class="evento">' +
                    '<div class="evento-fecha">' + escapar(e.fecha) + '</div>' +
                    '<div class="evento-dato">Usuario: <b>' + escapar(e.usuario) + '</b></div>' +
                    '<div class="evento-dato">Dispositivo: <b>' + escapar(e.dispositivo) + '</b></div>' +
                    '<div class="evento-dato">Canal: <b>' + escapar(e.canal) + '</b></div>' +
                    '<span class="evento-estado ' + escapar(e.tono) + '">' +
                        '<i class="' + escapar(e.icono) + '"></i> ' + escapar(e.texto) +
                    '</span>' +
                '</div>';
            }
        });
    }

    function cargarNotificaciones() {
        cargarHistorial({
            url: 'api/notificaciones',
            contenedor: 'notificaciones-lista',
            campo: 'notificaciones',
            vacio: 'No tenés notificaciones.',
            fila: function (n) {
                return '<div class="noti' + (n.nueva ? ' nueva' : '') + '">' +
                    '<div class="noti-fecha">' + escapar(n.fecha) + '</div>' +
                    '<div class="noti-texto">' +
                        '<i class="' + escapar(n.icono) + '"></i> ' + escapar(n.mensaje) +
                    '</div>' +
                '</div>';
            }
        });
    }

    // ---------- Selectores "Cambiar de Dominio" / "Cambiar de Panel" ----------
    // Los dos comparten la carga de la lista: se pide al endpoint en cada
    // apertura (cambian sin que cambie esta pagina) y se pintan botones.
    // El CLICK ya no es el mismo para los dos: Panel cambia de verdad
    // (POST + recarga), Dominio todavia no esta cableado.
    function cargarOpciones(cfg) {
        var caja = document.getElementById(cfg.contenedor);
        if (!caja) return;
        caja.innerHTML = '<p class="lista-aviso">Cargando&hellip;</p>';

        fetchCorte(cfg.url, {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        }, CORTE_GET)
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) {
                if (!j || j.ok !== true) {
                    caja.innerHTML = '<p class="lista-aviso error">' +
                        escapar((j && j.error) ? j.error : 'No se pudo cargar.') + '</p>';
                    return;
                }
                var filas = j[cfg.campo] || [];
                if (!filas.length) {
                    caja.innerHTML = '<p class="lista-aviso">' + escapar(cfg.vacio) + '</p>';
                    return;
                }
                caja.innerHTML = filas.map(function (o) {
                    return '<button type="button" class="opcion-btn' + (o.actual ? ' actual' : '') + '"' +
                        ' data-id="' + escapar(cfg.id(o)) + '"' +
                        ' data-nombre="' + escapar(o.nombre) + '">' +
                        '<i class="fa-solid fa-circle-chevron-right opcion-btn-ico"></i>' +
                        '<span class="opcion-btn-txt">' + escapar(o.nombre) + '</span>' +
                        (o.actual ? '<i class="fa-solid fa-check opcion-btn-check"></i>' : '') +
                    '</button>';
                }).join('');
            })
            .catch(function () {
                caja.innerHTML = '<p class="lista-aviso error">No se pudo conectar con el servidor.</p>';
            });
    }

    // Dominios = perfiles del usuario; el id que importa es el del PERFIL.
    function cargarDominios() {
        cargarOpciones({
            url: 'api/dominios',
            contenedor: 'dominio-lista',
            campo: 'dominios',
            vacio: 'No tenés dominios asignados.',
            id: function (d) { return d.perfil; }
        });
    }

    // Paneles del dominio activo.
    function cargarPaneles() {
        cargarOpciones({
            url: 'api/paneles',
            contenedor: 'panel-lista',
            campo: 'paneles',
            vacio: 'Este dominio no tiene paneles.',
            id: function (p) { return p.id; }
        });
    }

    // Delegado: los botones se crean despues de cargar cada lista.
    //
    // Los dos selectores hacen lo mismo —POST del id elegido y, cuando el
    // servidor confirma, recarga— y solo cambian el endpoint y el nombre del
    // campo. Cambiar de dominio ademas mueve el panel abierto, pero eso lo
    // resuelve entero el backend: desde aca es el mismo POST.
    //
    // La recarga es lo que hacia el legacy (`$oUrl->ir('/')`) y no es un
    // atajo: la franja del encabezado y los controles del panel los arma
    // `index.php` en el servidor a partir del alcance de la sesion.
    // Repintarlos desde el cliente seria duplicar esa resolucion en dos
    // lugares que pueden discrepar.
    function cablearSelector(cfg) {
        var caja = document.getElementById(cfg.contenedor);
        if (!caja) return;

        caja.addEventListener('click', function (e) {
            var btn = e.target.closest('.opcion-btn[data-id]');
            if (!btn) return;

            // Ya es la opcion abierta: no hay nada que escribir ni que recargar.
            if (btn.classList.contains('actual')) {
                cerrarModal(document.getElementById(cfg.modal));
                return;
            }

            // La lista se desactiva mientras viaja el POST: sin esto, dos
            // clicks seguidos mandan dos cambios y gana el que conteste ultimo.
            var botones = caja.querySelectorAll('.opcion-btn');
            function habilitar(v) {
                for (var i = 0; i < botones.length; i++) botones[i].disabled = !v;
            }

            habilitar(false);
            btn.classList.add('cargando');

            var cuerpo = {};
            cuerpo[cfg.campo] = Number(btn.getAttribute('data-id'));

            fetchCorte(cfg.url, {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify(cuerpo),
                credentials: 'same-origin'
            }, CORTE_POST)
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (j) {
                    if (!j || j.ok !== true) {
                        habilitar(true);
                        btn.classList.remove('cargando');
                        showToast((j && j.error) ? j.error : cfg.errorGenerico);
                        return;
                    }
                    window.location.reload();
                })
                .catch(function () {
                    habilitar(true);
                    btn.classList.remove('cargando');
                    showToast('No se pudo conectar con el servidor.');
                });
        });
    }

    // El id del dominio es el del PERFIL, no el del dominio: la lista sale de
    // `perfiles` y un mismo dominio puede tener varios (ver api/dominios.php).
    cablearSelector({
        contenedor: 'dominio-lista',
        modal: 'modal-dominio',
        url: 'api/dominios',
        campo: 'perfil',
        errorGenerico: 'No se pudo cambiar de dominio.'
    });

    cablearSelector({
        contenedor: 'panel-lista',
        modal: 'modal-panel',
        url: 'api/paneles',
        campo: 'panel',
        errorGenerico: 'No se pudo cambiar el panel.'
    });

    // ---------- Modal "Entorno" ----------
    // Todo lo de aca sale del propio navegador. No hay endpoint que devuelva
    // variables del servidor: el proceso PHP tiene las claves de firma de los
    // tokens y las credenciales de la base, y eso no puede viajar al cliente.

    function entornoTexto(v) {
        if (v === undefined || v === null || v === '') return '—';
        if (typeof v === 'boolean') return v ? 'Si' : 'No';
        return String(v);
    }

    // Los valores vienen de cookies y storage, o sea que los puede escribir
    // cualquier script: se pintan con textContent, nunca con innerHTML.
    function entornoSeccion(titulo, icono, pares, nota) {
        var sec = document.createElement('section');
        sec.className = 'entorno-seccion';

        var h = document.createElement('h3');
        h.className = 'modal-subtitulo';
        var ic = document.createElement('i');
        ic.className = 'fa-solid ' + icono;
        h.appendChild(ic);
        h.appendChild(document.createTextNode(' ' + titulo));
        sec.appendChild(h);

        if (!pares.length) {
            var vacio = document.createElement('p');
            vacio.className = 'entorno-vacio';
            vacio.textContent = 'Sin datos.';
            sec.appendChild(vacio);
        } else {
            var dl = document.createElement('dl');
            dl.className = 'entorno-lista';
            pares.forEach(function (par) {
                var fila = document.createElement('div');
                fila.className = 'entorno-fila';
                var dt = document.createElement('dt');
                dt.textContent = par[0];
                var dd = document.createElement('dd');
                dd.textContent = entornoTexto(par[1]);
                fila.appendChild(dt);
                fila.appendChild(dd);
                dl.appendChild(fila);
            });
            sec.appendChild(dl);
        }

        if (nota) {
            var p = document.createElement('p');
            p.className = 'entorno-nota';
            p.textContent = nota;
            sec.appendChild(p);
        }
        return sec;
    }

    function paresNavegador() {
        var n = navigator;
        return [
            ['User agent', n.userAgent],
            ['Plataforma', n.platform],
            ['Idioma', n.language],
            ['Idiomas', (n.languages || []).join(', ')],
            ['Cookies habilitadas', n.cookieEnabled],
            ['En linea', n.onLine],
            ['Nucleos de CPU', n.hardwareConcurrency],
            ['Memoria (GB)', n.deviceMemory],
            ['Service Worker', 'serviceWorker' in n],
            ['Notificaciones', ('Notification' in window) ? Notification.permission : 'no soportado']
        ];
    }

    function paresPantalla() {
        var orient = (screen.orientation && screen.orientation.type) || null;
        return [
            ['Resolucion', screen.width + ' x ' + screen.height],
            ['Area util', screen.availWidth + ' x ' + screen.availHeight],
            ['Ventana', window.innerWidth + ' x ' + window.innerHeight],
            ['Profundidad de color', screen.colorDepth + ' bits'],
            ['Densidad (DPR)', window.devicePixelRatio],
            ['Orientacion', orient],
            ['Instalada como PWA', window.matchMedia('(display-mode: standalone)').matches]
        ];
    }

    function paresPagina() {
        var ahora = new Date();
        var zona = '';
        try { zona = Intl.DateTimeFormat().resolvedOptions().timeZone; } catch (e) { zona = ''; }
        return [
            ['URL', location.href],
            ['Origen', location.origin],
            ['Protocolo', location.protocol],
            ['Referente', document.referrer],
            ['Version cargada', document.body.getAttribute('data-version')],
            ['Zona horaria', zona],
            ['Desfasaje UTC (min)', -ahora.getTimezoneOffset()],
            ['Hora local', ahora.toLocaleString()]
        ];
    }

    function paresCookies() {
        if (!document.cookie) return [];
        return document.cookie.split('; ').map(function (crudo) {
            var i = crudo.indexOf('=');
            if (i < 0) return [crudo, ''];
            var valor = crudo.slice(i + 1);
            // Una cookie mal codificada no debe romper todo el listado.
            try { valor = decodeURIComponent(valor); } catch (e) { /* cruda */ }
            return [crudo.slice(0, i), valor];
        });
    }

    function paresStorage(store) {
        var pares = [];
        try {
            for (var i = 0; i < store.length; i++) {
                var clave = store.key(i);
                pares.push([clave, store.getItem(clave)]);
            }
        } catch (e) {
            // Modo privado / storage bloqueado por el navegador.
            pares.push(['(no accesible)', (e && e.message) || String(e)]);
        }
        return pares;
    }

    function cargarEntorno() {
        var cont = document.getElementById('entorno-cliente');
        if (!cont) return;
        cont.textContent = '';

        cont.appendChild(entornoSeccion('Página y sesión', 'fa-location-crosshairs', paresPagina()));
        cont.appendChild(entornoSeccion('Navegador', 'fa-compass', paresNavegador()));
        cont.appendChild(entornoSeccion('Pantalla', 'fa-display', paresPantalla()));
        cont.appendChild(entornoSeccion(
            'Cookies', 'fa-cookie-bite', paresCookies(),
            'Solo las cookies visibles para JavaScript. Las de sesión son HttpOnly y no aparecen.'
        ));
        cont.appendChild(entornoSeccion('localStorage', 'fa-database', paresStorage(window.localStorage)));
        cont.appendChild(entornoSeccion('sessionStorage', 'fa-hourglass-half', paresStorage(window.sessionStorage)));
    }

    // "Copiar todo": recorre lo que se ve (incluida la seccion del servidor) y
    // arma un texto plano para pegar en el chat de la mesa de ayuda.
    var entornoCopiar = document.getElementById('entorno-copiar');
    if (entornoCopiar) {
        entornoCopiar.addEventListener('click', function () {
            var partes = [];
            document.querySelectorAll('#modal-entorno .entorno-seccion').forEach(function (sec) {
                var titulo = sec.querySelector('.modal-subtitulo');
                partes.push('== ' + (titulo ? titulo.textContent.trim() : '') + ' ==');
                sec.querySelectorAll('.entorno-fila').forEach(function (fila) {
                    partes.push(fila.querySelector('dt').textContent + ': ' +
                                fila.querySelector('dd').textContent);
                });
                partes.push('');
            });
            var texto = partes.join('\n');

            if (navigator.clipboard && navigator.clipboard.writeText) {
                navigator.clipboard.writeText(texto)
                    .then(function () { showToast('Entorno copiado.'); })
                    .catch(function () { showToast('No se pudo copiar.'); });
            } else {
                showToast('El navegador no permite copiar.');
            }
        });
    }

    // ---------- Contraseña (ver / cambiar) ----------
    // El campo arranca con la contraseña ACTUAL enmascarada; el ojo la muestra.
    // No se pide la anterior: teniendola a la vista no seria una defensa real.
    var formPass = document.getElementById('form-contrasena');
    var campoPass = document.getElementById('pass-nueva');
    var cargarContrasena = function () {};   // no-op si el modal no existe

    if (formPass && campoPass) {
        var aviso    = document.getElementById('pass-aviso');
        var enviar   = document.getElementById('pass-submit');
        var ojo      = document.getElementById('pass-ojo');
        var original = null;                 // la que devolvio el server
        var cargada  = false;

        function mostrarAviso(texto, ok) {
            aviso.textContent = texto;
            aviso.classList.toggle('error', !ok);
            aviso.classList.toggle('ok', !!ok);
            aviso.classList.add('visible');
        }
        function limpiarAviso() {
            aviso.textContent = '';
            aviso.classList.remove('visible', 'error', 'ok');
        }

        // Solo se habilita Guardar si hay un cambio real y valido.
        function revisar() {
            var v = campoPass.value;
            enviar.disabled = !cargada || v.length < 6 || v === original;
        }

        campoPass.addEventListener('input', function () {
            limpiarAviso();
            revisar();
        });

        // Ojo: alterna entre bolitas y texto.
        if (ojo) {
            ojo.addEventListener('click', function () {
                var verlo = campoPass.type === 'password';
                campoPass.type = verlo ? 'text' : 'password';
                ojo.setAttribute('aria-pressed', verlo ? 'true' : 'false');
                ojo.setAttribute('aria-label', verlo ? 'Ocultar contraseña' : 'Mostrar contraseña');
                ojo.innerHTML = verlo
                    ? '<i class="fa-solid fa-eye-slash"></i>'
                    : '<i class="fa-solid fa-eye"></i>';
                campoPass.focus();
            });
        }

        cargarContrasena = function () {
            if (cargada) return;             // ya la tenemos de una apertura previa
            limpiarAviso();
            fetchCorte('api/contrasena', {
                headers: { 'Accept': 'application/json' },
                credentials: 'same-origin',
                cache: 'no-store'
            }, CORTE_GET)
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (j) {
                    if (!j || j.ok !== true) {
                        campoPass.placeholder = 'No se pudo cargar';
                        mostrarAviso((j && j.error) ? j.error : 'No se pudo leer la contraseña.', false);
                        return;
                    }
                    original = j.contrasena || '';
                    campoPass.value       = original;
                    campoPass.placeholder = '';
                    campoPass.disabled    = false;
                    cargada = true;
                    revisar();
                })
                .catch(function () {
                    campoPass.placeholder = 'No se pudo cargar';
                    mostrarAviso('No se pudo conectar con el servidor.', false);
                });
        };

        formPass.addEventListener('submit', function (e) {
            e.preventDefault();
            limpiarAviso();

            enviar.disabled = true;
            fetchCorte('api/contrasena', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ nueva: campoPass.value }),
                credentials: 'same-origin'
            }, CORTE_POST)
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (j) {
                    if (!j || j.ok !== true) {
                        mostrarAviso((j && j.error) ? j.error : 'No se pudo cambiar la contraseña.', false);
                        revisar();
                        return;
                    }
                    // La nueva pasa a ser la actual: Guardar vuelve a apagarse
                    // hasta que el usuario cambie algo otra vez.
                    original = campoPass.value;
                    revisar();
                    mostrarAviso('Contraseña actualizada.', true);
                    showToast('Contraseña actualizada');
                })
                .catch(function () {
                    mostrarAviso('No se pudo conectar con el servidor.', false);
                    revisar();
                });
        });
    }

    // ---------- Invitar un usuario ----------
    // UN SOLO CAMPO, el correo: es el destino del mensaje y lo unico que hace
    // falta para emitir la invitacion. El nombre y el celular los completa la
    // persona invitada en `invitacion/aceptar.php`.
    //
    // EL MODAL NO SE CIERRA AL ENVIAR, y no es un descuido: el alta de la fila y
    // el encolado del correo van en la MISMA transaccion del backend, asi que
    // "se envio" es una respuesta que hay que esperar. Cerrar optimista mostraria
    // como enviada una invitacion que se revirtio. Al confirmarse queda el campo
    // vacio y el aviso en verde, que es lo que hace falta para invitar a otra
    // persona sin volver a abrir el modal.
    //
    // El markup existe solo si el perfil tiene el permiso `invitacion`
    // (index.php), asi que todo esto es no-op para el resto.
    var formInvitar       = document.getElementById('form-invitar');
    var limpiarInvitacion = function () {};

    if (formInvitar) {
        var invCampo  = document.getElementById('inv-correo');
        var invAviso  = document.getElementById('inv-aviso');
        var invEnviar = document.getElementById('inv-submit');

        // Validacion de forma, no de existencia: solo decide si el boton se
        // habilita. La buena la hace el backend con FILTER_VALIDATE_EMAIL.
        var invEsCorreo = function (v) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(String(v || '').trim());
        };

        var invMostrarAviso = function (texto, ok) {
            invAviso.textContent = texto;
            invAviso.classList.toggle('error', !ok);
            invAviso.classList.toggle('ok', !!ok);
            invAviso.classList.add('visible');
        };
        var invLimpiarAviso = function () {
            invAviso.textContent = '';
            invAviso.classList.remove('visible', 'error', 'ok');
        };
        var invRevisar = function () {
            invEnviar.disabled = !invEsCorreo(invCampo.value);
        };

        limpiarInvitacion = function () {
            invCampo.value = '';
            invLimpiarAviso();
            invRevisar();
        };

        invCampo.addEventListener('input', function () {
            invLimpiarAviso();
            invRevisar();
        });

        formInvitar.addEventListener('submit', function (e) {
            e.preventDefault();
            invLimpiarAviso();

            var correo = invCampo.value.trim();
            if (!invEsCorreo(correo)) {
                invMostrarAviso('Escribí un correo válido.', false);
                return;
            }

            invEnviar.disabled = true;
            fetchCorte('api/invitaciones', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ correo: correo }),
                credentials: 'same-origin'
            }, CORTE_POST)
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (j) {
                    if (!j || j.ok !== true) {
                        invMostrarAviso((j && j.error) ? j.error : 'No se pudo enviar la invitación.', false);
                        invRevisar();
                        return;
                    }
                    invCampo.value = '';
                    invRevisar();
                    invMostrarAviso('Le enviamos la invitación a ' + correo + '.', true);
                    showToast('Invitación enviada');
                })
                .catch(function () {
                    invMostrarAviso('No se pudo conectar con el servidor.', false);
                    invRevisar();
                });
        });
    }

    // ---------- Topbar actions ----------
    // Ya no hay estado "activo" que marcar: las tres acciones que quedan
    // (Panel, Dominio, Mesa de Ayuda) abren un modal o un enlace externo, y
    // ninguna representa una seccion de la pagina. Los modales los engancha
    // el handler de `[data-modal]`; la mesa de ayuda es un `<a>` comun.

    // ---------- Click en botones de control ----------
    // ---------- Botones del panel: mandan la orden al equipo ----------
    //
    // El equivalente del `clic()` de `reactor-app/panel/index.php`, que hacía
    // `$("#control-"+c).load("procesar?ctr=..&btn=..")` — un GET cuya respuesta
    // se inyectaba en un div oculto. Acá es un POST y la respuesta se mira.
    //
    // IMPORTANTE: al volver el POST el canal TODAVIA NO cambió de estado. La
    // orden viajó por MQTT y el equipo la va a ejecutar y recién después
    // reportar; el motor Python escribe `canales.estado` con ese reporte. Por
    // eso acá no se pinta ningún canal: el display lo actualiza `refrescarEstado()`
    // en su próxima pasada, cuando el cambio ya es real. Encender la pastilla
    // al apretar mostraría una luz prendida que quizás nunca se prendió.
    document.querySelectorAll('.tec-btn[data-boton]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            if (btn.disabled) return;
            var accion = btn.getAttribute('data-accion') || 'Acción';

            btn.disabled = true;
            btn.classList.add('enviando');

            fetchCorte('api/boton', {
                method: 'POST',
                headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
                body: JSON.stringify({ boton: Number(btn.getAttribute('data-boton')) }),
                credentials: 'same-origin'
            }, CORTE_POST)
                .then(function (r) { return r.json().catch(function () { return null; }); })
                .then(function (j) {
                    if (!j || j.ok !== true) {
                        showToast((j && j.error) ? j.error : 'No se pudo enviar la orden.');
                        return;
                    }
                    showToast(accion);
                    // Adelanta el sondeo: el equipo suele reportar en menos de
                    // un segundo y así el display no espera al tick siguiente.
                    setTimeout(refrescarEstado, 400);
                })
                .catch(function () {
                    showToast('No se pudo conectar con el servidor.');
                })
                .finally(function () {
                    btn.disabled = false;
                    btn.classList.remove('enviando');
                });
        });
    });

    // ---------- Estado en vivo de los displays ----------
    //
    // Reemplaza a `monitoresRefrescar()` del legacy, que pedía `monitor.php`
    // UNA VEZ POR CONTROL Y POR SEGUNDO y reemplazaba HTML con `.load()`. Acá
    // es una sola llamada para todo el panel y se pintan sólo los valores.
    //
    // Se pausa con la pestaña en segundo plano: es un sondeo indefinido y
    // seguir pidiendo con la app minimizada gasta batería y datos del celular
    // para actualizar algo que nadie está mirando.
    var CADENCIA = 2000;
    var estadoTimer = null;
    var estadoEnVuelo = false;
    // Momento del ultimo sondeo que contesto bien. Es lo que mira el vigia de
    // abajo para decidir si la app esta viva o quedo hablando sola.
    var ultimoOk = Date.now();

    function pintarControl(art, c) {
        var display = art.querySelector('[data-rol="display"]');
        var enlace  = art.querySelector('[data-rol="enlace"]');
        var power   = art.querySelector('[data-rol="power"]');
        var canales = art.querySelector('[data-rol="canales"]');

        if (display) {
            display.classList.toggle('off', !c.online);
            display.style.background = c.color;
        }
        if (enlace) {
            enlace.innerHTML = c.online
                ? '<i class="fa-solid fa-wifi"></i> ' + (c.senal === null ? '—' : c.senal + '%')
                : '<i class="fa-solid fa-plug"></i> ' + escapar(c.estadoTexto);
        }
        if (power) {
            power.innerHTML = c.online ? '100% <i class="fa-solid fa-battery-full"></i>' : '';
        }
        if (canales) {
            canales.innerHTML = (c.canales || []).map(function (k) {
                return k.sensor
                    ? '<span class="canal-valor">' + escapar(k.valor) + '</span>'
                    : '<span class="canal-estado' + (k.on ? ' on' : '') + '">' + Number(k.n) + '</span>';
            }).join('');
        }
    }

    function refrescarEstado() {
        if (estadoEnVuelo || document.hidden) return;
        if (!document.querySelector('.panel[data-control]')) return;
        estadoEnVuelo = true;

        fetchCorte('api/canales', {
            headers: { 'Accept': 'application/json' },
            credentials: 'same-origin',
            cache: 'no-store'
        }, CORTE_GET)
            .then(function (r) { return r.json().catch(function () { return null; }); })
            .then(function (j) {
                if (!j || j.ok !== true) return;
                ultimoOk = Date.now();
                (j.controles || []).forEach(function (c) {
                    var art = document.querySelector('.panel[data-control="' + c.id + '"]');
                    if (art) pintarControl(art, c);
                });
            })
            .catch(function () { /* un tick perdido se recupera en el siguiente */ })
            .finally(function () {
                estadoEnVuelo = false;
                revisarConexion();
            });
    }

    function arrancarSondeo() {
        if (estadoTimer) return;
        estadoTimer = setInterval(refrescarEstado, CADENCIA);
    }

    function pararSondeo() {
        if (!estadoTimer) return;
        clearInterval(estadoTimer);
        estadoTimer = null;
    }

    if (document.querySelector('.panel[data-control]')) {
        arrancarSondeo();
    }

    // ---------- Vigia de conexion ----------
    //
    // LA APP TIENE QUE PODER RECARGARSE DESDE ADENTRO. Corre en
    // `display: standalone` (manifest.json), o sea SIN barra de direcciones,
    // SIN boton de recargar y con el pull-to-refresh de Chrome desactivado: si
    // algo se rompe, el usuario no tiene ningun gesto disponible y la unica
    // salida es cerrar la app desde el administrador de tareas. Eso es lo que
    // venia pasando.
    //
    // Este banner es esa salida. Aparece cuando el panel lleva un rato sin
    // recibir un solo sondeo bueno —o sea, cuando lo que se ve en pantalla ya
    // no representa el estado real de los equipos— y ofrece recargar. Se
    // dibuja FIJO ABAJO y no en el flujo, a diferencia del banner de version:
    // asi no toca la altura de `.layout` y no hay que rehacer el `calc()` de
    // `body.has-banner` para el caso de los dos banners juntos.
    var UMBRAL_CAIDA = 20000;   // 10 sondeos perdidos seguidos
    var caidaBanner  = document.getElementById('conexion-banner');
    var caidaBtn     = document.getElementById('conexion-banner-btn');

    if (caidaBtn) {
        caidaBtn.addEventListener('click', function () { window.location.reload(); });
    }

    function mostrarCaida(visible) {
        if (!caidaBanner) return;
        caidaBanner.hidden = !visible;
    }

    function revisarConexion() {
        if (!caidaBanner) return;
        mostrarCaida((Date.now() - ultimoOk) > UMBRAL_CAIDA);
    }

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
        fetchCorte('api/version', { cache: 'no-store' }, CORTE_GET)
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

    // Se para con la app en segundo plano, igual que el sondeo del panel. Era
    // el unico temporizador que seguia corriendo con la app minimizada: un
    // pedido por minuto durante horas, para enterarse de un deploy que el
    // usuario no esta mirando. Al volver se consulta una vez y listo.
    var versionTimer = null;

    function arrancarVersion() {
        if (versionTimer || !banner) return;
        versionTimer = setInterval(checkVersion, 60000);
    }

    function pararVersion() {
        if (!versionTimer) return;
        clearInterval(versionTimer);
        versionTimer = null;
    }

    arrancarVersion();

    // ---------- Ciclo de vida: volver a la app despues de un rato ----------
    //
    // EL PROBLEMA QUE RESUELVE ESTE BLOQUE. La PWA se deja abierta todo el dia
    // y se usa a ratos. Mientras esta en segundo plano, Android es libre de
    // matarle el proceso de render para recuperar memoria —y lo hace, tanto
    // mas cuanto mas tiempo pasa y mas memoria ocupa la pestaña—. Al volver,
    // el navegador recarga la pagina, y esa recarga cae justo en el peor
    // momento posible: el equipo recien despierta y la radio todavia se esta
    // reconectando. Si esa recarga se cuelga, en `standalone` no hay forma de
    // reintentar y queda el viewport vacio hasta cerrar la app.
    //
    // Contra eso hay tres capas, y esta es la tercera:
    //   1. el service worker corta toda navegacion colgada y sirve la pantalla
    //      offline en vez de dejar al navegador esperando (serviceworker.js);
    //   2. los estaticos que bloquean el render salen de la cache del worker,
    //      asi que la recarga no depende de que la red conteste;
    //   3. aca: al volver, la pagina se refresca sola ANTES de que el sistema
    //      tenga que matarla, para que el usuario encuentre datos de ahora y
    //      no un tablero de hace ocho horas.
    //
    // NO SE RECARGA A CIEGAS. Recargar con la red caida cambiaria una interfaz
    // vieja pero usable por la pantalla offline. Por eso primero se comprueba
    // que el servidor conteste (`api/version` es publico, no toca la base y ya
    // se pide para el banner) y recien ahi se recarga. Si no contesta, se deja
    // la pagina como esta y el vigia de conexion muestra el banner con el
    // boton de recargar.
    var RECARGA_TRAS = 15 * 60 * 1000;
    var ocultoDesde  = document.hidden ? Date.now() : 0;

    function recargarSiContesta() {
        fetchCorte('api/version', { cache: 'no-store' }, 5000)
            .then(function (r) {
                if (r && r.ok) window.location.reload();
            })
            .catch(function () {
                // Sin servidor no se recarga: ver arriba. El vigia se encarga.
                revisarConexion();
            });
    }

    function alVolver() {
        var estuvo = ocultoDesde ? (Date.now() - ocultoDesde) : 0;
        ocultoDesde = 0;

        // Primero lo barato: mostrar el estado de ahora y no el de hace rato.
        refrescarEstado();
        arrancarSondeo();
        arrancarVersion();
        checkVersion();

        if (estuvo >= RECARGA_TRAS) recargarSiContesta();
    }

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) {
            ocultoDesde = Date.now();
            pararSondeo();
            pararVersion();
        } else {
            alVolver();
        }
    });

    // `pageshow` con `persisted` es la vuelta desde el bfcache: la pagina se
    // reanuda tal cual estaba, con los temporizadores congelados y los datos
    // viejos, y `visibilitychange` no siempre alcanza para distinguirlo. Es el
    // mismo tratamiento; `alVolver()` es idempotente (los dos arranques estan
    // guardados y `ocultoDesde` se limpia en la primera pasada).
    window.addEventListener('pageshow', function (e) {
        if (e.persisted) alVolver();
    });

    // ---------- Service worker ----------
    // Se registra SIEMPRE, no solo para instalar la app: los celulares que hoy
    // tienen la PWA legacy arrastran un worker viejo en `/serviceworker.js` con
    // estrategia cache-first sobre todo el origen. Registrar el nuestro en esa
    // misma ruta es lo que lo reemplaza (ver el comentario del archivo).
    if ('serviceWorker' in navigator) {
        window.addEventListener('load', function () {
            navigator.serviceWorker.register('/serviceworker.js')
                .catch(function () { /* sin SW la app funciona igual */ });
        });
    }

    // ---------- Instalar la app ----------
    // Chrome/Android avisa con `beforeinstallprompt` que se puede instalar, y
    // exige que el prompt salga desde un gesto del usuario: por eso se guarda
    // el evento y se dispara recien en el click del item del menu.
    var btnInstall = document.getElementById('btn-install');
    var promptDiferido = null;

    function yaInstalada() {
        return window.matchMedia('(display-mode: standalone)').matches
            || window.navigator.standalone === true;   // iOS
    }

    // Si la app ya corre instalada, el item no tiene sentido.
    if (btnInstall && yaInstalada()) {
        btnInstall.hidden = true;
    }

    window.addEventListener('beforeinstallprompt', function (e) {
        e.preventDefault();
        promptDiferido = e;
    });

    window.addEventListener('appinstalled', function () {
        promptDiferido = null;
        if (btnInstall) btnInstall.hidden = true;
        showToast('Reactor se instaló en tu dispositivo');
    });

    if (btnInstall) {
        btnInstall.addEventListener('click', function (e) {
            e.preventDefault();

            if (promptDiferido) {
                promptDiferido.prompt();
                promptDiferido.userChoice.then(function () {
                    // El evento no se puede reusar: si el usuario cancela,
                    // el navegador lo vuelve a emitir cuando corresponda.
                    promptDiferido = null;
                });
                return;
            }

            // iOS no implementa `beforeinstallprompt`: solo se puede instalar a
            // mano desde el menu Compartir.
            var esIOS = /iphone|ipad|ipod/i.test(navigator.userAgent);
            showToast(esIOS
                ? 'Tocá Compartir y elegí "Agregar a inicio"'
                : 'Usá el menú del navegador para instalar la app');
        });
    }
})();
