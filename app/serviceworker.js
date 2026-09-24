/* Service worker de Reactor App.
 *
 * VIVE EN ESTA RUTA A PROPOSITO. La app legacy registra `/serviceworker.js`
 * con scope `/` desde `pwa/pwa.js`, asi que todos los celulares que hoy tienen
 * la PWA instalada ya tienen una registracion apuntando aca. Al repuntar
 * app.reactor.com.ar a este proyecto, el navegador va a buscar este archivo en
 * su chequeo de actualizacion, ver que cambio y REEMPLAZAR el worker viejo.
 * Si en cambio hubieramos usado otro nombre, el legacy quedaria registrado
 * para siempre (nadie serviria su script) y seguiria interceptando todo.
 *
 * QUE HACIA EL VIEJO Y POR QUE HABIA QUE SACARLO
 *
 *   self.addEventListener('fetch', e => e.respondWith(
 *       caches.match(e.request).then(r => r || fetch(e.request))));
 *
 *   Cache-first sobre TODO el origen y sin vencimiento: lo que hubiera quedado
 *   en la cache `reactor` se servia para siempre, sin revalidar nunca. En la
 *   practica solo tenia precacheado `/panel/index` (su handler `fetch` nunca
 *   escribia), pero igual se interponia en cada request. El `activate` de aca
 *   abajo borra esa cache.
 *
 * ESTRATEGIA: red primero para las NAVEGACIONES Y LOS DATOS, cache primero
 * para los ESTATICOS VERSIONADOS.
 *
 *   Esta app es PHP renderizado en el servidor con datos que cambian solos
 *   (estado de los canales, actividad, notificaciones). Cachear esas respuestas
 *   seria mostrar un tablero mentiroso: una luz apagada que figura encendida.
 *   Por eso `api/` NO SE CACHEA NUNCA y la navegacion siempre va a la red.
 *
 *   Los estaticos son otra cosa: `/assets/...` viaja con `?v=<version.txt>`, o
 *   sea que cada deploy cambia la URL. Cachearlos por URL exacta no puede
 *   servir nada viejo —la version nueva es otra clave— y saca de la ruta
 *   critica los dos recursos que BLOQUEAN EL RENDER (`style.css` y el CSS de
 *   Font Awesome). Ver el bloque de abajo sobre la pantalla en blanco.
 *
 * LA PANTALLA EN BLANCO (lo que este archivo viene a arreglar)
 *
 *   Sintoma reportado: la PWA queda horas abierta en segundo plano y al volver
 *   muestra el viewport VACIO — ni interfaz, ni mensaje de error, ni la
 *   pantalla offline, y sin forma de recargar porque en `display: standalone`
 *   no hay barra de direcciones ni pull-to-refresh. Solo se sale cerrando la
 *   app.
 *
 *   La version anterior de este handler era:
 *
 *       evento.respondWith(fetch(pedido).catch(() => ...offline...));
 *
 *   y ahi esta el agujero: `.catch()` corre cuando la request RECHAZA, no
 *   cuando se queda COLGADA. `fetch` no tiene timeout propio. El celular que
 *   vuelve de Doze con la radio a medio reconectar abre el socket y no recibe
 *   nada: la promesa no resuelve ni rechaza, `respondWith()` queda pendiente
 *   para siempre y el navegador no pinta NADA. No es que falle la pagina: es
 *   que nunca llega a existir, por eso no hay ni error.
 *
 *   Por eso ahora TODA respuesta del worker tiene corte duro (`conCorte()`):
 *   vencido el plazo se contesta con lo que haya —la pantalla offline, o la
 *   version anterior del estatico— y nunca se deja al navegador esperando.
 */

const CACHE  = 'reactor-app-v2';          // cascara: pantalla offline + logo

/* Estaticos. Todo lo de `/assets/` entra aca, incluido Font Awesome, que desde
 * ahora es LOCAL (`/assets/fontawesome/`) y no de cdnjs — ver el comentario del
 * `<head>` en index.php. Con eso la app ya no tiene NINGUNA dependencia de otro
 * origen en la ruta critica del render.
 *
 * SI ALGUN DIA SE ACTUALIZA FONT AWESOME, HAY QUE SUBIRLE EL NUMERO A ESTE
 * NOMBRE. El CSS va versionado por `filemtime` y se renueva solo, pero las
 * tipografias las pide el propio CSS con rutas relativas y SIN `?v=`
 * (`../webfonts/fa-solid-900.woff2`): la URL no cambia entre versiones, asi que
 * cache-first seguiria sirviendo los .woff2 viejos contra el CSS nuevo. Cambiar
 * este nombre hace que `activate` borre la cache entera y se rebaje todo. */
const ASSETS = 'reactor-app-assets-v1';

const VIVAS  = [CACHE, ASSETS];

const OFFLINE = '/offline.html';

// Lo minimo para que la pantalla offline se vea completa sin red.
const PRECARGA = [OFFLINE, '/assets/img/logo.png'];

/* Cortes. El de navegacion es mas largo porque del otro lado hay PHP + base de
 * datos; el de un estatico que no contesta en 6 segundos ya es una red rota. */
const CORTE_NAVEGACION = 8000;
const CORTE_ESTATICO   = 6000;

self.addEventListener('install', (evento) => {
    evento.waitUntil(
        caches.open(CACHE)
            // Se guarda recurso por recurso y se ignora el que falle, EN VEZ
            // de `cache.addAll()`, que es atomico: si uno solo de los archivos
            // no se puede bajar, addAll rechaza, la instalacion falla y este
            // worker NO se activa nunca. Y como el worker que sigue mandando
            // en ese caso es el legacy —cache-first sobre todo el origen—, un
            // 404 en una imagen dejaria a los celulares con el worker viejo
            // para siempre, que es exactamente lo que este archivo viene a
            // resolver. Fallar la instalacion por un recurso opcional es peor
            // que quedarse sin ese recurso.
            .then((cache) => Promise.all(
                PRECARGA.map((url) => cache.add(url).catch(() => null))
            ))
            // `skipWaiting` para que el worker viejo se vaya en la primera
            // visita y no cuando el usuario cierre todas las pestañas: en una
            // PWA instalada eso puede no pasar nunca.
            .then(() => self.skipWaiting())
    );
});

self.addEventListener('activate', (evento) => {
    evento.waitUntil((async () => {
        /* Navigation preload: el navegador dispara la request de navegacion EN
         * PARALELO con el arranque del worker, en vez de esperar a que el
         * worker despierte para recien ahi pedirla. Despues de horas en
         * segundo plano el worker SIEMPRE esta dormido, que es justo el caso
         * que nos interesa: sin esto, el arranque del worker se suma entero a
         * la espera de la primera pantalla. */
        if (self.registration.navigationPreload) {
            await self.registration.navigationPreload.enable().catch(() => null);
        }

        const nombres = await caches.keys();
        await Promise.all(
            // Borra TODA cache que no sea de las nuestras. Aca es donde muere
            // la cache `reactor` del worker legacy, y tambien la `reactor-app-v1`
            // de la version anterior de este archivo.
            nombres.filter((n) => !VIVAS.includes(n)).map((n) => caches.delete(n))
        );

        await self.clients.claim();
    })());
});

/* Corte duro para cualquier promesa de red.
 *
 * NO aborta la request: solo deja de esperarla. Es a proposito — si el socket
 * termina contestando, el navegador igual completa la descarga y la conexion
 * queda tibia para el proximo pedido. Lo unico que hace falta es que el
 * usuario no se quede mirando una pantalla vacia mientras tanto. */
function conCorte(promesa, ms) {
    return new Promise((resolver, rechazar) => {
        const reloj = setTimeout(() => rechazar(new Error('corte')), ms);
        promesa.then(
            (valor) => { clearTimeout(reloj); resolver(valor); },
            (error) => { clearTimeout(reloj); rechazar(error); }
        );
    });
}

/* Respuesta de ultimo recurso cuando no hay red NI pantalla offline cacheada.
 *
 * `respondWith()` TIENE que recibir una Response. Si se le pasa `undefined`
 * —que es justo lo que devuelve `caches.match()` cuando el recurso no esta— el
 * navegador corta la navegacion con un error de red y muestra
 * "No se puede acceder a este sitio / ERR_FAILED", sin ninguna pista de que el
 * culpable fue el service worker. Por eso nunca se devuelve el resultado de
 * `caches.match()` pelado. */
function respuestaSinConexion() {
    return new Response(
        '<!DOCTYPE html><html lang="es"><head><meta charset="utf-8">'
        + '<meta name="viewport" content="width=device-width,initial-scale=1">'
        + '<title>Sin conexión</title></head>'
        + '<body style="font-family:sans-serif;background:#262f38;color:#f0f0f0;'
        + 'display:flex;align-items:center;justify-content:center;height:100vh;margin:0">'
        + '<div style="text-align:center;padding:24px">'
        + '<h1 style="font-size:1.2rem;margin:0 0 8px">Sin conexión</h1>'
        + '<p style="opacity:.7;margin:0 0 16px">Revisá tu conexión y volvé a intentar.</p>'
        + '<button onclick="location.reload()" style="border:0;border-radius:20px;'
        + 'background:#C11313;color:#fff;font:inherit;padding:11px 26px">Reintentar</button>'
        + '</div></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
}

/* Navegacion: red (con preload si esta disponible) y corte duro. Lo que nunca
 * puede pasar es que esto quede pendiente — ver el bloque de cabecera. */
async function responderNavegacion(evento) {
    const red = (async () => {
        // `preloadResponse` resuelve a undefined cuando el preload no esta
        // habilitado o el navegador no lo soporta; ahi se pide a mano.
        //
        // Y si RECHAZA tampoco se rinde: que haya fallado el preload no prueba
        // que la red este caida (el preload sale antes de que el worker
        // despierte, con la radio todavia negociando). Rendirse ahi mandaria a
        // la pantalla offline a gente que tiene conexion.
        try {
            const previa = await evento.preloadResponse;
            if (previa) return previa;
        } catch (e) { /* se pide a mano */ }
        return fetch(evento.request);
    })();

    try {
        return await conCorte(red, CORTE_NAVEGACION);
    } catch (e) {
        return (await caches.match(OFFLINE)) || respuestaSinConexion();
    }
}

/* Solo mismo origen. Ya no hay nada de terceros que cachear: Font Awesome
 * pasó a `/assets/fontawesome/` y cae dentro de esta misma regla. */
function esEstatico(url) {
    return url.origin === self.location.origin
        && url.pathname.startsWith('/assets/');
}

/* Entradas cacheadas del mismo archivo en OTRA version (`?v=` distinto). */
async function otrasVersiones(cache, url) {
    const claves = await cache.keys();
    return claves.filter((k) => {
        const u = new URL(k.url);
        return u.origin === url.origin && u.pathname === url.pathname && u.search !== url.search;
    });
}

/* Estaticos: cache primero por URL EXACTA, red si no esta, y si la red falla
 * se sirve la version anterior del mismo archivo.
 *
 * Servir un `style.css` de la version pasada es una decision consciente: el
 * unico caso en que ocurre es "hubo deploy Y la red no contesta", y ahi la
 * alternativa no es la hoja nueva sino NINGUNA hoja, o sea la pantalla en
 * blanco que este archivo viene a evitar. Ademas el banner de nueva version
 * del front ya le propone al usuario recargar cuando la red vuelve. */
async function responderEstatico(pedido) {
    const url   = new URL(pedido.url);
    const cache = await caches.open(ASSETS);

    // `caches.match()` global y no `cache.match()`: busca en TODAS las caches.
    // Hace falta porque `/assets/img/logo.png` esta precacheado en la cascara
    // (`CACHE`) y no aca — es el logo de la pantalla offline, y mirarlo solo en
    // `ASSETS` lo dejaria sin encontrar justo cuando no hay red, que es el unico
    // momento en que esa pantalla se muestra.
    const exacto = await caches.match(pedido);
    if (exacto) return exacto;

    try {
        const respuesta = await conCorte(fetch(pedido), CORTE_ESTATICO);
        // Solo se guarda lo que contesto bien. Un 404 o un 500 cacheado con una
        // URL versionada quedaria pegado hasta el proximo deploy.
        if (respuesta && respuesta.ok) {
            await cache.put(pedido, respuesta.clone());
            // Podado: al guardar la version nueva se borran las anteriores del
            // MISMO archivo. Sin esto la cache crece un juego completo de
            // assets por deploy y no la limpia nadie — `activate` solo corre
            // cuando cambia ESTE script, no cuando cambia `version.txt`.
            const viejas = await otrasVersiones(cache, url);
            await Promise.all(viejas.map((k) => cache.delete(k)));
        }
        return respuesta;
    } catch (e) {
        const viejas = await otrasVersiones(cache, url);
        if (viejas.length) {
            const anterior = await cache.match(viejas[viejas.length - 1]);
            if (anterior) return anterior;
        }
        throw e;
    }
}

self.addEventListener('fetch', (evento) => {
    const pedido = evento.request;

    // Solo GET. Un POST cacheado o reintentado seria una orden mandada dos veces.
    if (pedido.method !== 'GET') return;

    if (pedido.mode === 'navigate') {
        evento.respondWith(responderNavegacion(evento));
        return;
    }

    const url = new URL(pedido.url);
    if (esEstatico(url)) {
        evento.respondWith(responderEstatico(pedido));
        return;
    }

    // `api/` y todo lo demas van derecho a la red, sin que el worker se meta:
    // son datos que cambian solos y cachearlos mostraria un tablero mentiroso.
});

/* Notificaciones push: el worker legacy tenia un handler `push` conectado a
 * FCM (`firebase-messaging-sw.js`, `pwaTokenFijar.php`,
 * `notificaciones/suscribir.php`). Este proyecto todavia no implementa push,
 * asi que al reemplazar el worker las notificaciones dejan de llegar. Cuando
 * se reimplemente, el handler va aca. */
