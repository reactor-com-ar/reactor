/**
 * Burbuja de chat con IA. El HTML lo dibuja `sistema/pie.php` y el unico
 * endpoint es `POST /chat/mensaje`.
 *
 * VANILLA Y NO JQUERY, aunque el theme cargue jQuery: este widget se dibuja en
 * todas las paginas del sitio y no tiene por que depender de que los plugins del
 * theme hayan inicializado bien. Va suelto y sin `$(document).ready`.
 *
 * LA CONVERSACION VIVE EN EL SERVIDOR. Aca se guarda el `uuid` en
 * `sessionStorage` —para que la charla siga si la persona navega a otra pagina—
 * y una copia de lo que se mostro, que es solo para volver a pintarlo. Lo que el
 * modelo lee sale siempre de la base: el historial NO se manda en el POST.
 *
 * `sessionStorage` y no `localStorage`: la conversacion muere con la pestana. Es
 * lo correcto para una maquina prestada y evita que alguien vuelva una semana
 * despues a una charla que ya no existe del otro lado.
 */

(function () {
    'use strict';

    var CLAVE_UUID = 'reactor.chat.uuid';
    var CLAVE_HIST = 'reactor.chat.historial';

    /** Cuantos mensajes se recuerdan para repintar. El servidor guarda todo. */
    var HISTORIAL_MAXIMO = 40;

    var SALUDO = 'Hola. Soy el asistente de Reactor y puedo ayudarte con los dispositivos, la '
        + 'plataforma, los planes y las preguntas frecuentes. ¿Qué necesitás?';

    var widget = document.getElementById('chat-widget');
    if (!widget) {
        return;
    }

    var burbuja  = document.getElementById('chat-burbuja');
    var panel    = document.getElementById('chat-panel');
    var cerrar   = document.getElementById('chat-cerrar');
    var cuerpo   = document.getElementById('chat-cuerpo');
    var campo    = document.getElementById('chat-texto');
    var enviar   = document.getElementById('chat-enviar');
    var formu    = document.getElementById('chat-form');

    var esperando = false;

    /* ----------------------------------------------------------------------
       Almacenamiento. Envuelto en try porque en modo privado de algunos
       navegadores `sessionStorage` existe pero tirar/leer lanza: sin el try, el
       chat entero se cae por no poder recordar el uuid, que es lo menos
       importante que hace.
       ---------------------------------------------------------------------- */

    function leer(clave) {
        try {
            return window.sessionStorage.getItem(clave);
        } catch (e) {
            return null;
        }
    }

    function guardar(clave, valor) {
        try {
            window.sessionStorage.setItem(clave, valor);
        } catch (e) {
            /* sin memoria: la conversacion funciona igual, pero no sobrevive al
               cambio de pagina. */
        }
    }

    function historial() {
        try {
            var crudo = leer(CLAVE_HIST);
            var filas = crudo ? JSON.parse(crudo) : [];
            return Array.isArray(filas) ? filas : [];
        } catch (e) {
            return [];
        }
    }

    function recordar(clase, texto) {
        var filas = historial();
        filas.push({ clase: clase, texto: texto });
        guardar(CLAVE_HIST, JSON.stringify(filas.slice(-HISTORIAL_MAXIMO)));
    }

    /* ----------------------------------------------------------------------
       Pintar mensajes.
       ---------------------------------------------------------------------- */

    /**
     * Escribe el texto dentro del elemento convirtiendo las URLs en enlaces.
     *
     * SE ARMA CON NODOS Y NO CON `innerHTML`, y no es una manía: lo que se pinta
     * acá es la salida de un modelo de lenguaje al que cualquiera le puede pedir
     * lo que quiera. Con `innerHTML` alcanzaría con convencerlo de escribir una
     * etiqueta para tener XSS en todas las páginas del sitio. Con `createTextNode`
     * eso no puede pasar, se escriba lo que se escriba.
     *
     * Sólo se enlazan `http` y `https`: un `javascript:` en un `href` es la otra
     * mitad del mismo agujero.
     */
    function pintarTexto(elemento, texto) {
        var patron = /https?:\/\/[^\s<>"')]+/g;
        var desde  = 0;
        var coincide;

        while ((coincide = patron.exec(texto)) !== null) {
            if (coincide.index > desde) {
                elemento.appendChild(document.createTextNode(texto.slice(desde, coincide.index)));
            }

            var url = coincide[0].replace(/[.,;:]+$/, '');
            var a   = document.createElement('a');
            a.href        = url;
            a.textContent = url;
            a.target      = '_blank';
            a.rel         = 'noopener';
            elemento.appendChild(a);

            desde = coincide.index + url.length;
        }

        if (desde < texto.length) {
            elemento.appendChild(document.createTextNode(texto.slice(desde)));
        }
    }

    function mensaje(clase, texto) {
        var div = document.createElement('div');
        div.className = 'chat-msg ' + clase;
        pintarTexto(div, texto);
        cuerpo.appendChild(div);
        abajo();

        return div;
    }

    /** Un error, con la salida a WhatsApp adentro cuando el servidor la pide. */
    function error(texto, derivar) {
        var div = mensaje('error', texto);

        if (derivar) {
            div.appendChild(document.createElement('br'));
            var a = document.createElement('a');
            a.href        = '/whatsapp';
            a.textContent = 'Escribinos por WhatsApp';
            a.target      = '_blank';
            a.rel         = 'noopener';
            div.appendChild(a);
        }
    }

    function abajo() {
        cuerpo.scrollTop = cuerpo.scrollHeight;
    }

    function puntitos(prender) {
        var previo = document.getElementById('chat-puntos');
        if (previo) {
            previo.remove();
        }
        if (!prender) {
            return;
        }

        var div = document.createElement('div');
        div.className = 'chat-msg ellos';
        div.id = 'chat-puntos';
        div.innerHTML = '<span class="chat-puntos"><span></span><span></span><span></span></span>';
        cuerpo.appendChild(div);
        abajo();
    }

    /* ----------------------------------------------------------------------
       Abrir y cerrar.
       ---------------------------------------------------------------------- */

    function abrir() {
        widget.classList.add('abierto');
        panel.setAttribute('aria-hidden', 'false');

        if (!cuerpo.hasChildNodes()) {
            var previos = historial();
            if (previos.length) {
                previos.forEach(function (fila) {
                    mensaje(fila.clase, fila.texto);
                });
            } else {
                mensaje('ellos', SALUDO);
            }
        }

        campo.focus();
        abajo();
    }

    function cerrarPanel() {
        widget.classList.remove('abierto');
        panel.setAttribute('aria-hidden', 'true');
        burbuja.focus();
    }

    /* ----------------------------------------------------------------------
       Mandar.
       ---------------------------------------------------------------------- */

    function mandar() {
        var texto = campo.value.trim();
        if (texto === '' || esperando) {
            return;
        }

        mensaje('yo', texto);
        recordar('yo', texto);

        campo.value = '';
        campo.style.height = '';
        bloquear(true);
        puntitos(true);

        fetch('/chat/mensaje', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            // `same-origin` y no `omit`: el endpoint no usa cookies hoy, pero si
            // manana suma una defensa con cookie firmada tiene que viajar.
            credentials: 'same-origin',
            body: JSON.stringify({
                uuid: leer(CLAVE_UUID) || '',
                texto: texto,
                pagina: window.location.pathname
            })
        }).then(function (respuesta) {
            // Los errores del endpoint vienen con codigo != 200 y con cuerpo
            // JSON: se lee igual, porque el texto para mostrar esta ahi.
            return respuesta.json();
        }).then(function (datos) {
            puntitos(false);

            if (datos && datos.uuid) {
                guardar(CLAVE_UUID, datos.uuid);
            }

            if (datos && datos.ok) {
                mensaje('ellos', datos.texto);
                recordar('ellos', datos.texto);
            } else {
                error((datos && datos.error) || 'No pude contestarte ahora mismo.', !!(datos && datos.derivar));
            }
        }).catch(function () {
            // Se cayo la red o el servidor contesto algo que no era JSON. No hay
            // nada para contar: lo unico util es ofrecer el otro canal.
            puntitos(false);
            error('No pude contestarte ahora mismo.', true);
        }).then(function () {
            bloquear(false);
            campo.focus();
        });
    }

    function bloquear(si) {
        esperando = si;
        enviar.disabled = si;
        campo.disabled = si;
    }

    /* ----------------------------------------------------------------------
       Eventos.
       ---------------------------------------------------------------------- */

    burbuja.addEventListener('click', abrir);
    cerrar.addEventListener('click', cerrarPanel);

    formu.addEventListener('submit', function (evento) {
        evento.preventDefault();
        mandar();
    });

    campo.addEventListener('keydown', function (evento) {
        // Enter manda y Shift+Enter hace un salto de linea, que es lo que espera
        // cualquiera que haya usado un chat.
        if (evento.key === 'Enter' && !evento.shiftKey) {
            evento.preventDefault();
            mandar();
        }
    });

    // El textarea crece con el texto hasta el tope que le pone el CSS.
    campo.addEventListener('input', function () {
        campo.style.height = '';
        campo.style.height = Math.min(campo.scrollHeight, 96) + 'px';
    });

    document.addEventListener('keydown', function (evento) {
        if (evento.key === 'Escape' && widget.classList.contains('abierto')) {
            cerrarPanel();
        }
    });
}());
