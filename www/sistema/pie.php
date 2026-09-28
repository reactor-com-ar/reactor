<?php

declare(strict_types=1);

/**
 * Pie común: el footer, la burbuja flotante del chat y todos los `<script>` del
 * theme. Cierra el `main-wrapper` que abrió `sistema/cabeza.php`.
 *
 * Port de `sistema/pie.php` del legacy. El orden de los scripts es el mismo y
 * NO se puede tocar a la ligera: jQuery va primero porque todo lo demás lo
 * necesita, y `main.js` va último porque es el que inicializa los plugins que
 * se cargaron antes.
 *
 * Arreglos respecto del original:
 *   - El `<span class="current-year">` del copyright quedaba vacío: ningún JS
 *     del theme lo llenaba, así que el pie decía "©" y nada más. Ahora el año
 *     lo escribe PHP.
 *   - `quform/js/plugins.js` y `scripts.js` ya no se cargan: eran el cliente
 *     AJAX de Quform, que posteaba a endpoints PHP de la librería que este repo
 *     no tiene. Los formularios de acá son POST normales y el CSS de Quform
 *     —que sí es lo que les da el aspecto— se sigue cargando en la cabeza.
 *   - Los enlaces a sitios externos llevan `rel="noopener"`.
 *
 * El botón flotante que había acá era un `<a>` a `wa.me` con su propio `<style>`
 * embebido; lo reemplazó la burbuja del chat (28/09/2026). El acceso a WhatsApp
 * sigue estando: en el pie del panel, en el footer y en el menú de Soporte.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

$wwwVersion = wwwVersion();
?>

<!-- FOOTER
================================================== -->
<footer>
    <div class="container">
        <div class="row mt-n2-6">

            <div class="col-sm-6 col-lg-3 mt-2-6 wow fadeIn" data-wow-delay="200ms">
                <div class="mb-1-6 mb-lg-1-9">
                    <img src="/img/logos/footer-light-logo.png" alt="<?= e(wwwNombre()) ?>">
                </div>
                <ul class="contact-list">
                    <li><span class="fa fa-home pe-3 text-white"></span><a href="/nosotros/contacto">Argentina</a></li>
                    <li><i class="fab fa-whatsapp fa-lg pe-3 text-white"></i><a href="/whatsapp" target="_blank" rel="noopener">(+54) 1163099315</a></li>
                    <li><span class="fa fa-envelope pe-3 text-white"></span><a href="/correo">info@reactor.com.ar</a></li>
                </ul>
            </div>

            <div class="col-sm-6 col-lg-2 mt-2-6 wow fadeIn" data-wow-delay="800ms">
                <div class="ps-sm-1-9 ps-lg-2-2 ps-xl-2-5">
                    <h3 class="h5 mb-1-6 mb-lg-1-9 text-primary">Soporte</h3>
                    <ul class="footer-list-style1">
                        <li><a href="/ayuda">Ayuda</a></li>
                        <li><a href="/tecnicos">Técnicos</a></li>
                        <li><a href="https://dev.reactor.com.ar" target="_blank" rel="noopener">Desarrolladores</a></li>
                    </ul>
                </div>
            </div>

            <div class="col-sm-6 col-lg-2 mt-2-6 wow fadeIn" data-wow-delay="400ms">
                <div class="ps-sm-1-9 ps-lg-2-2 ps-xl-2-5">
                    <h3 class="h5 mb-1-6 mb-lg-1-9 text-primary">Blog</h3>
                    <ul class="footer-list-style1">
                        <li><a href="/blog">Todo</a></li>
                        <li><a href="/blog/noticias">Noticias</a></li>
                        <li><a href="/blog/notas">Notas</a></li>
                    </ul>
                </div>
            </div>

            <div class="col-sm-6 col-lg-2 mt-2-6 wow fadeIn" data-wow-delay="400ms">
                <div class="ps-sm-1-9 ps-lg-2-2 ps-xl-2-5">
                    <h3 class="h5 mb-1-6 mb-lg-1-9 text-primary">Nosotros</h3>
                    <ul class="footer-list-style1">
                        <li><a href="/nosotros/acerca">Acerca nuestro</a></li>
                        <li><a href="/nosotros/historia">Nuestra historia</a></li>
                        <li><a href="/nosotros/contacto">Contacto</a></li>
                    </ul>
                </div>
            </div>

            <div class="col-sm-6 col-lg-3 mt-2-6 wow fadeIn" data-wow-delay="1000ms">
                <div class="ps-lg-2-2 ps-xl-2-5">
                    <h3 class="h5 mb-1-6 mb-lg-1-9 text-primary">Boletín Semanal</h3>
                    <p class="text-white mb-4">Mantente actualizado con las novedades del sistema</p>
                    <form class="newsletter-form w-90 w-sm-100" action="/sistema/suscribir" method="post">
                        <div class="quform-elements">
                            <div class="row">
                                <div class="col-md-12">
                                    <div class="quform-input">
                                        <input class="form-control" id="email_address" type="email" name="correo" placeholder="Suscríbete" required>
                                    </div>
                                </div>
                                <div class="col-md-12">
                                    <div class="quform-submit-inner">
                                        <button class="btn" type="submit"><span><i class="fas fa-paper-plane text-white"></i></span></button>
                                    </div>
                                    <div class="quform-loading-wrap text-start"><span class="quform-loading"></span></div>
                                </div>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div class="footer-bar borders-top border-color-light-white wow fadeIn" data-wow-delay="1200ms">
        <div class="container">
            <div class="row">
                <div class="col-md-6 text-center text-md-start mt-3 mt-md-0 order-2 order-md-1">
                    <p class="d-inline-block text-white mb-0">&copy; <?= date('Y') ?>
                        <a href="/privacidad" class="text-primary white-hover">Políticas de Privacidad</a>
                    </p>
                </div>
                <div class="col-md-6 text-center text-md-end order-1 order-md-2">
                    <p class="text-white d-inline-block mb-0 align-middle">Seguinos:</p>
                    <ul class="footer-social-style1">
                        <li><a href="/facebook" target="_blank" rel="noopener" aria-label="Facebook"><i class="fab fa-facebook-f"></i></a></li>
                        <li><a href="/instagram" target="_blank" rel="noopener" aria-label="Instagram"><i class="fab fa-instagram"></i></a></li>
                        <li><a href="/tiktok" target="_blank" rel="noopener" aria-label="TikTok"><i class="fab fa-tiktok"></i></a></li>
                        <li><a href="/youtube" target="_blank" rel="noopener" aria-label="YouTube"><i class="fab fa-youtube"></i></a></li>
                        <li><a href="/whatsapp" target="_blank" rel="noopener" aria-label="WhatsApp"><i class="fab fa-whatsapp"></i></a></li>
                        <li><a href="https://dev.reactor.com.ar" target="_blank" rel="noopener" aria-label="Desarrolladores"><i class="fab fa-github"></i></a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</footer>

</div>
<!-- fin main-wrapper -->

<?php if (chatActivo()): ?>
    <!-- BURBUJA DE CHAT CON IA
    ==================================================
    Ocupa el lugar del botón flotante de WhatsApp, que era un `<a>` a `wa.me` con
    su `<style>` embebido acá —el único CSS del sitio fuera de `css/`—. WhatsApp
    no se perdió: está en el pie del panel, que es donde sirve, cuando el
    asistente no alcanza.

    SIN `OPENAI_APIKEY` NO SE DIBUJA NADA. No es un detalle de implementación:
    es cómo se apaga el chat sin deployar, y es lo que mantiene el sitio de
    desarrollo sin gastar. Ojo con eso al probar, que la esquina queda vacía.

    Todo el HTML va acá y no lo arma el JS porque el JS puede no llegar a
    cargarse; así el botón existe desde el primer pintado. -->
    <div class="chat-widget" id="chat-widget">

        <button type="button" class="chat-burbuja" id="chat-burbuja" aria-label="Abrir el asistente de Reactor">
            <i class="fa-solid fa-comment-dots" aria-hidden="true"></i>
        </button>

        <div class="chat-panel" id="chat-panel" role="dialog" aria-modal="false" aria-label="Asistente de Reactor" aria-hidden="true">

            <div class="chat-cabecera">
                <div>
                    <h2>Asistente Reactor</h2>
                    <!-- Que es un bot se dice acá arriba y no en letra chica:
                         quien escribe tiene que saber con qué está hablando. -->
                    <p>Respuestas generadas por inteligencia artificial</p>
                </div>
                <button type="button" class="chat-cerrar" id="chat-cerrar" aria-label="Cerrar el asistente">&times;</button>
            </div>

            <!-- `aria-live` para que un lector de pantalla anuncie la respuesta:
                 llega sola, sin que la persona haya navegado a ningún lado. -->
            <div class="chat-cuerpo" id="chat-cuerpo" aria-live="polite"></div>

            <div class="chat-pie">
                <form id="chat-form" autocomplete="off">
                    <div class="chat-fila">
                        <textarea class="chat-texto" id="chat-texto" rows="1" maxlength="<?= e((string) CHAT_MENSAJE_MAXIMO) ?>" placeholder="Escribí tu consulta…" aria-label="Tu consulta"></textarea>
                        <button type="submit" class="chat-enviar" id="chat-enviar" aria-label="Enviar">
                            <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                        </button>
                    </div>
                </form>
                <div class="chat-avisos">
                    <a href="/whatsapp" target="_blank" rel="noopener">
                        <i class="fab fa-whatsapp" aria-hidden="true"></i> Hablar con una persona
                    </a>
                </div>
            </div>

        </div>
    </div>
<?php endif; ?>

<!-- jQuery: va primero, todo lo que sigue depende de el -->
<script src="/js/jquery.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/popper.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/bootstrap.min.js?v=<?= e($wwwVersion) ?>"></script>

<!-- buscador de la barra superior -->
<script src="/search/search.js?v=<?= e($wwwVersion) ?>"></script>

<!-- navegacion -->
<script src="/js/nav-menu.js?v=<?= e($wwwVersion) ?>"></script>

<!-- plugins del theme -->
<script src="/js/animated.headline.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/easy.responsive.tabs.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/owl.carousel.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/owl.carousel.thumbs.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.counterup.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jarallax.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jarallax-video.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.stellar.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/waypoints.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/countdown.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/datetimepicker.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.magnific-popup.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/lightgallery-all.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.mousewheel.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/isotope.pkgd.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/wow.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.lettering.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/jquery.textillate.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/tilt.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/clipboard.min.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/prism.js?v=<?= e($wwwVersion) ?>"></script>

<!-- main.js va ultimo: inicializa todos los plugins de arriba -->
<script src="/js/main.js?v=<?= e($wwwVersion) ?>"></script>
<script src="/js/custom.js?v=<?= e($wwwVersion) ?>"></script>

<?php if (chatActivo()): ?>
    <!-- La burbuja de chat. Va despues de todo pero NO depende de nada de
         arriba: es vanilla y no usa jQuery ni los plugins del theme. -->
    <script src="/js/chat.js?v=<?= e($wwwVersion) ?>"></script>
<?php endif; ?>

</body>

</html>
