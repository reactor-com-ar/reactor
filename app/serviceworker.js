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
 * ESTRATEGIA DE ESTE WORKER: red primero, sin cachear respuestas.
 *
 *   Esta app es PHP renderizado en el servidor con datos que cambian solos
 *   (estado de los canales, actividad, notificaciones). Cachear respuestas
 *   seria mostrar un tablero mentiroso: una luz apagada que figura encendida.
 *   Por eso lo unico que se guarda es la pantalla de "sin conexion", y solo se
 *   usa cuando la navegacion falla de verdad.
 */

const CACHE = 'reactor-app-v1';
const OFFLINE = '/offline.html';

// Lo minimo para que la pantalla offline se vea completa sin red.
const PRECARGA = [OFFLINE, '/assets/img/logo.png'];

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
    evento.waitUntil(
        caches.keys()
            .then((nombres) => Promise.all(
                // Borra TODA cache que no sea la nuestra. Aca es donde muere
                // la cache `reactor` del worker legacy.
                nombres.filter((n) => n !== CACHE).map((n) => caches.delete(n))
            ))
            .then(() => self.clients.claim())
    );
});

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
        + '<p style="opacity:.7;margin:0">Revisá tu conexión y volvé a intentar.</p>'
        + '</div></body></html>',
        { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } }
    );
}

self.addEventListener('fetch', (evento) => {
    const pedido = evento.request;

    // Solo navegaciones. Assets, endpoints y todo lo demas van derecho a la
    // red, sin que el worker se meta.
    if (pedido.mode !== 'navigate') return;

    evento.respondWith(
        fetch(pedido).catch(() =>
            caches.match(OFFLINE).then((r) => r || respuestaSinConexion())
        )
    );
});

/* Notificaciones push: el worker legacy tenia un handler `push` conectado a
 * FCM (`firebase-messaging-sw.js`, `pwaTokenFijar.php`,
 * `notificaciones/suscribir.php`). Este proyecto todavia no implementa push,
 * asi que al reemplazar el worker las notificaciones dejan de llegar. Cuando
 * se reimplemente, el handler va aca. */
