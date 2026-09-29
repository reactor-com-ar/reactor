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

    /* ----------------------------------------------------------------------
       El teclado de Android.

       EN PANTALLA CHICA EL PANEL OCUPA TODO (`height: 100%`, media query de
       480px) Y ESO SE ROMPE AL ABRIR EL TECLADO. Chrome en Android achica el
       viewport VISUAL pero no el de LAYOUT, asi que el `100%` sigue valiendo la
       pantalla entera: la mitad de abajo del panel —el campo de texto incluido—
       queda tapada por el teclado. El navegador entonces hace scroll para
       mostrar el input enfocado y se lleva el encabezado fuera de vista, con un
       hueco blanco en el medio. Es el sintoma que se ve en un celular real.

       `window.visualViewport` es la unica pieza que SI refleja el teclado: su
       `height` es lo que queda visible y su `offsetTop` cuanto se corrio. Atando
       el panel a esos dos numeros, ocupa exactamente lo que se ve.

       SE HACE POR JS Y NO CON `interactive-widget=resizes-content` en el
       `<meta viewport>` porque esa etiqueta es del sitio ENTERO: cambiaria como
       se comporta el teclado en el formulario de contacto y en el de tecnicos,
       que hoy andan bien. Esto queda acotado al widget.

       Sin `visualViewport` —navegadores viejos— no se toca nada y queda el
       comportamiento de antes: el panel se ve mal con el teclado abierto, pero
       se puede escribir igual.
       ---------------------------------------------------------------------- */

    /** true cuando el panel esta a pantalla completa (la media query del CSS). */
    function esPantallaChica() {
        return window.matchMedia('(max-width: 480px)').matches;
    }

    function ajustarAlTeclado(bajarAlFinal) {
        var vv = window.visualViewport;
        if (!vv) {
            return;
        }

        // Con el panel cerrado o en pantalla grande se devuelven los estilos al
        // CSS: el panel de escritorio son 370px flotando abajo a la derecha y no
        // tiene nada que ver con el viewport.
        if (!widget.classList.contains('abierto') || !esPantallaChica()) {
            panel.style.top = '';
            panel.style.bottom = '';
            panel.style.height = '';
            return;
        }

        panel.style.top    = vv.offsetTop + 'px';
        panel.style.bottom = 'auto';
        panel.style.height = vv.height + 'px';

        if (bajarAlFinal) {
            abajo();
        }
    }

    if (window.visualViewport) {
        // `resize` es el teclado abriendose o cerrandose: ahi ademas hay que
        // bajar al ultimo mensaje, que es lo que la persona estaba mirando.
        window.visualViewport.addEventListener('resize', function () {
            ajustarAlTeclado(true);
        });
        // `scroll` es el viewport corriendose con el teclado ya abierto. Aca NO
        // se baja al final: la persona puede estar subiendo a leer algo y
        // moverle la lista en cada evento se la arranca de las manos.
        window.visualViewport.addEventListener('scroll', function () {
            ajustarAlTeclado(false);
        });
    }

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

        ajustarAlTeclado(false);
        campo.focus();
        abajo();
    }

    function cerrarPanel() {
        widget.classList.remove('abierto');
        panel.setAttribute('aria-hidden', 'true');
        // Se limpian los estilos inline: si quedaran puestos, al reabrir en
        // horizontal o en una tablet el panel arrancaria con el alto del
        // teclado de la vez anterior.
        ajustarAlTeclado(false);
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

        enviarAlServidor(texto, false);
    }

    /**
     * Olvida la conversación: el uuid y la copia local de lo que se mostró.
     *
     * Se usa cuando el servidor avisa que esa conversación ya no existe —la
     * borraron desde el panel—. Lo que ya está pintado en pantalla NO se borra:
     * sacarle a alguien de la vista lo que acaba de escribir es peor que dejar
     * dos burbujas que el asistente ya no recuerda.
     */
    function olvidar() {
        try {
            window.sessionStorage.removeItem(CLAVE_UUID);
            window.sessionStorage.removeItem(CLAVE_HIST);
        } catch (e) {
            /* sin memoria: no hay nada que olvidar. */
        }
    }

    /**
     * Manda un texto ya pintado en pantalla.
     *
     * NO SE LLAMA `enviar` Y NO SE PUEDE LLAMAR ASI: arriba hay
     * `var enviar = document.getElementById('chat-enviar')`, el BOTON. La
     * declaracion de una funcion se eleva, pero la asignacion del `var` corre
     * despues y la pisa, asi que `enviar` termina siendo un `<button>` y
     * `mandar()` revienta con "enviar is not a function" — en el navegador, no
     * al chequear la sintaxis. Paso de verdad y dejo el chat mudo en produccion.
     *
     * `reintento` corta el bucle: el reenvío por `reiniciar` se hace UNA sola
     * vez. Si el segundo intento también falla, se muestra el error en vez de
     * volver a probar — un ciclo de reintentos contra un endpoint que rechaza
     * es la forma de convertir un error en una tormenta de requests pagos.
     */
    function enviarAlServidor(texto, reintento) {
        // Cuando el pedido se relanza, el que desbloquea es el reintento: si
        // desbloqueara también este, el campo quedaría habilitado mientras hay
        // una consulta en vuelo y se podrían encimar dos.
        var relanzado = false;

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
                // `pagina` es donde se ABRIO la conversacion y se guarda una
                // sola vez; `origen` es donde esta la persona AHORA y viaja en
                // cada mensaje. Son distintos en cuanto alguien navega sin
                // cerrar la burbuja, que es el caso normal: el chat vive en el
                // pie de todas las paginas y el uuid sobrevive en sessionStorage.
                pagina: window.location.pathname,
                // La URL COMPLETA, no el path: el host y la querystring son
                // parte del contexto. Una consulta que llego desde una campana
                // con `?utm_source=` dice de donde salio esa persona, y el path
                // solo lo pierde. El servidor la valida antes de guardarla.
                origen: window.location.href
            })
        }).then(function (respuesta) {
            // Los errores del endpoint vienen con codigo != 200 y con cuerpo
            // JSON: se lee igual, porque el texto para mostrar esta ahi.
            return respuesta.json();
        }).then(function (datos) {
            puntitos(false);

            // LA BANDERA VA ANTES QUE NADA, incluso antes de guardar el uuid: el
            // servidor avisa que la conversacion que tenemos guardada ya no
            // existe —la borraron desde el panel mientras la persona seguia con
            // la burbuja abierta—. Se la olvida y se manda de nuevo: arranca una
            // conversacion nueva y para quien escribio no paso nada.
            //
            // Sin esto la persona quedaba trabada PARA SIEMPRE: el uuid muerto
            // seguia viajando en cada mensaje y todos fallaban igual. Recargar
            // tampoco servia -- `sessionStorage` muere con la pestana, no con la
            // recarga.
            if (datos && datos.reiniciar && !reintento) {
                olvidar();
                // El mensaje ya estaba anotado en el historial que se acaba de
                // borrar; se vuelve a anotar para que la copia local siga
                // contando la misma charla que se ve en pantalla.
                recordar('yo', texto);
                relanzado = true;
                enviarAlServidor(texto, true);
                return;
            }

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
            if (relanzado) {
                return;
            }
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
