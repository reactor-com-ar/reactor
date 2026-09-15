<?php

declare(strict_types=1);

/**
 * Politicas de privacidad y tratamiento de datos personales de Reactor.
 *
 * Port de `reactor-www/privacidad.php`. El marcado del theme es el mismo; lo unico
 * que cambia es el arranque: `lib/inicio.php` en vez del framework legacy.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';

wwwTitulo('Políticas de Privacidad');
wwwDescripcion('Politicas de privacidad y tratamiento de datos personales de Reactor.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
?>


<!-- PAGE TITLE
        ================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="/img/bg/bg-12.jpg">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Políticas de Privacidad</h1>
                </div>
            </div>
        </div>
    </div>
    <div class="container">
        <div class="row">
            <div class="col-lg-12">
                <div class="breadcrumb">
                    <ul>
                        <li><a href="/">Inicio</a></li>
                        <li><a href="#!">Legal</a></li>
                        <li><a href="#!">Políticas de Privacidad</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- PRIVACY POLICY CONTENT
        ================================================== -->
<section class="pt-0">
    <div class="container">

        <div class="section-heading4">
            <span>Legal</span>
            <h2>Tu privacidad es nuestra prioridad</h2>
        </div>

        <div class="row justify-content-center">
            <div class="col-lg-10">

                <p class="mb-1-9">
                    En <strong>Reactor</strong> respetamos tu privacidad y nos comprometemos a proteger los datos personales que compartís con nosotros. La presente Política de Privacidad describe cómo recopilamos, utilizamos, almacenamos y protegemos tu información cuando utilizás nuestra plataforma de domótica y seguridad, tanto a través de nuestro sitio web (<a href="https://www.reactor.com.ar">www.reactor.com.ar</a>), nuestras aplicaciones web (app, panel y administración), nuestros dispositivos IoT y demás servicios asociados (en conjunto, los “Servicios”).
                </p>
                <p class="mb-1-9">
                    Al utilizar nuestros Servicios, aceptás las prácticas descritas en este documento. Te recomendamos leerlo detenidamente.
                </p>

                <hr class="my-4">

                <h4 class="mb-3">1. Responsable del tratamiento de los datos</h4>
                <p class="mb-1-9">
                    El responsable del tratamiento de tus datos personales es <strong>Reactor</strong>, con domicilio en la República Argentina. Para cualquier consulta relacionada con tu privacidad podés contactarnos a través del correo electrónico <a href="mailto:info@reactor.com.ar">info@reactor.com.ar</a> o mediante nuestro formulario de <a href="/nosotros/contacto/">contacto</a>.
                </p>

                <h4 class="mb-3">2. Información que recopilamos</h4>
                <p>
                    Para brindarte un servicio seguro y eficiente, podemos recopilar los siguientes tipos de información:
                </p>
                <ul class="list-style1 mb-1-9">
                    <li><strong>Datos de identificación y contacto:</strong> nombre, apellido, documento de identidad, correo electrónico, teléfono, domicilio y datos de facturación.</li>
                    <li><strong>Datos de cuenta:</strong> nombre de usuario, contraseña cifrada, perfiles y permisos asignados dentro de la plataforma.</li>
                    <li><strong>Datos de dispositivos:</strong> identificadores de hardware, número de serie, firmware instalado, configuración, ubicación y estados reportados por los dispositivos IoT instalados en tu domicilio.</li>
                    <li><strong>Datos de uso:</strong> registros de actividad (logs), señales enviadas y recibidas, eventos, comandos, fechas y horarios de acceso.</li>
                    <li><strong>Datos técnicos:</strong> dirección IP, tipo de navegador, sistema operativo, identificador de dispositivo móvil, idioma y datos de geolocalización aproximada.</li>
                    <li><strong>Datos de pago:</strong> información necesaria para procesar tus pagos (a través de procesadores externos como MercadoPago); no almacenamos datos completos de tarjetas de crédito en nuestros servidores.</li>
                    <li><strong>Comunicaciones:</strong> mensajes, consultas y comentarios que nos envíes a través de formularios, correo electrónico, WhatsApp u otros canales.</li>
                </ul>

                <h4 class="mb-3">3. Cómo utilizamos la información</h4>
                <p>
                    Utilizamos la información recopilada con las siguientes finalidades:
                </p>
                <ul class="list-style1 mb-1-9">
                    <li>Crear y administrar tu cuenta de usuario.</li>
                    <li>Proveer, operar y mantener los Servicios contratados.</li>
                    <li>Procesar pagos, emitir comprobantes y gestionar contratos de monitoreo.</li>
                    <li>Permitir la comunicación entre la plataforma y tus dispositivos IoT a través de nuestros servidores MQTT.</li>
                    <li>Enviar notificaciones operativas relacionadas con alarmas, estados de dispositivos y eventos del sistema.</li>
                    <li>Brindar soporte técnico y atención al cliente.</li>
                    <li>Mejorar la calidad, seguridad y funcionalidad de nuestros Servicios mediante análisis estadísticos.</li>
                    <li>Detectar, prevenir e investigar fraudes, abusos o incidentes de seguridad.</li>
                    <li>Cumplir con obligaciones legales, fiscales y regulatorias.</li>
                    <li>Enviarte comunicaciones comerciales o promocionales (solo si has otorgado tu consentimiento o cuando la ley lo permita).</li>
                </ul>

                <h4 class="mb-3">4. Base legal para el tratamiento</h4>
                <p class="mb-1-9">
                    Tratamos tus datos personales bajo alguna de las siguientes bases legales: (i) la ejecución del contrato de prestación de Servicios que mantenés con Reactor; (ii) el cumplimiento de obligaciones legales aplicables; (iii) tu consentimiento expreso, cuando sea requerido; y (iv) el interés legítimo de Reactor en la mejora, seguridad y prevención de fraudes en sus Servicios, siempre respetando tus derechos fundamentales.
                </p>

                <h4 class="mb-3">5. Compartir información con terceros</h4>
                <p>
                    No vendemos tu información personal. Únicamente compartimos datos cuando es estrictamente necesario para la prestación del Servicio o cuando exista una obligación legal, en los siguientes casos:
                </p>
                <ul class="list-style1 mb-1-9">
                    <li><strong>Proveedores de servicios:</strong> infraestructura en la nube (AWS), servicios de correo electrónico (AWS SES, Mailjet), pasarelas de pago (MercadoPago), notificaciones push (Firebase), mensajería (WhatsApp) y otros proveedores tecnológicos contratados bajo acuerdos de confidencialidad.</li>
                    <li><strong>Agentes e instaladores autorizados:</strong> empresas externas responsables del mantenimiento de los dispositivos en tu zona, quienes acceden únicamente a la información necesaria para prestar el servicio técnico.</li>
                    <li><strong>Autoridades competentes:</strong> cuando exista una orden judicial, requerimiento legal o necesidad de proteger los derechos, la propiedad o la seguridad de Reactor, de nuestros usuarios o de terceros.</li>
                    <li><strong>Transferencias empresariales:</strong> en caso de fusión, adquisición, venta de activos o reestructuración corporativa, podremos transferir información, notificándolo cuando corresponda.</li>
                </ul>

                <h4 class="mb-3">6. Transferencias internacionales</h4>
                <p class="mb-1-9">
                    Algunos de nuestros proveedores pueden estar ubicados fuera de la República Argentina (por ejemplo, en Estados Unidos o la Unión Europea). En esos casos adoptamos las medidas técnicas y contractuales apropiadas para garantizar un nivel de protección equivalente al exigido por la legislación aplicable, incluida la Ley N° 25.326 de Protección de los Datos Personales.
                </p>

                <h4 class="mb-3">7. Seguridad de la información</h4>
                <p class="mb-1-9">
                    Implementamos medidas técnicas, físicas y organizativas razonables para proteger tu información contra el acceso no autorizado, alteración, divulgación o destrucción. Entre ellas: cifrado de contraseñas, autenticación mediante tokens JWT, conexiones HTTPS/TLS, segmentación de redes, controles de acceso, monitoreo continuo y resguardos periódicos. Sin embargo, ningún sistema es completamente seguro, por lo que no podemos garantizar la seguridad absoluta de la información.
                </p>

                <h4 class="mb-3">8. Conservación de los datos</h4>
                <p class="mb-1-9">
                    Conservamos tus datos personales durante el tiempo necesario para cumplir con los fines descritos en esta política, mientras mantengas una cuenta activa o un contrato vigente, y por los plazos adicionales requeridos por la legislación aplicable (por ejemplo, en materia fiscal, contable o de prevención de fraudes). Posteriormente, los datos serán eliminados o anonimizados de forma segura.
                </p>

                <h4 class="mb-3">9. Tus derechos</h4>
                <p>
                    En cumplimiento de la Ley N° 25.326 de Protección de los Datos Personales y demás normativa aplicable, tenés derecho a:
                </p>
                <ul class="list-style1 mb-1-9">
                    <li><strong>Acceso:</strong> conocer qué datos personales tenemos sobre vos.</li>
                    <li><strong>Rectificación:</strong> solicitar la corrección de datos inexactos o desactualizados.</li>
                    <li><strong>Supresión:</strong> pedir la eliminación de tus datos cuando ya no sean necesarios o hayas retirado tu consentimiento.</li>
                    <li><strong>Oposición:</strong> oponerte al tratamiento de tus datos para fines específicos como el marketing directo.</li>
                    <li><strong>Limitación:</strong> solicitar que se restrinja el tratamiento de tus datos en determinados casos.</li>
                    <li><strong>Portabilidad:</strong> recibir tus datos en un formato estructurado y de uso común, cuando sea técnicamente posible.</li>
                    <li><strong>Revocación del consentimiento:</strong> retirar en cualquier momento el consentimiento previamente otorgado, sin que ello afecte la licitud del tratamiento previo.</li>
                </ul>
                <p class="mb-1-9">
                    Podés ejercer estos derechos enviando un correo a <a href="mailto:info@reactor.com.ar">info@reactor.com.ar</a> indicando tu solicitud y acreditando tu identidad. Asimismo, en Argentina, podés presentar reclamos ante la <strong>Agencia de Acceso a la Información Pública</strong>, órgano de control de la Ley N° 25.326.
                </p>

                <h4 class="mb-3">10. Cookies y tecnologías similares</h4>
                <p class="mb-1-9">
                    Nuestro sitio y aplicaciones utilizan cookies y tecnologías similares para mantener tu sesión iniciada, recordar tus preferencias, analizar el tráfico y mejorar la experiencia de uso. Podés configurar tu navegador para rechazar o eliminar las cookies, aunque algunas funciones de los Servicios podrían verse afectadas. Para más información, consultá nuestra política de cookies o ajustá las preferencias desde tu navegador.
                </p>

                <h4 class="mb-3">11. Privacidad de menores de edad</h4>
                <p class="mb-1-9">
                    Nuestros Servicios están dirigidos a personas mayores de 18 años o, en su defecto, a menores con la autorización expresa de sus padres o representantes legales. No recopilamos intencionalmente datos personales de menores sin dicha autorización. Si tomamos conocimiento de que hemos recibido información de un menor sin autorización adecuada, procederemos a eliminarla de manera segura.
                </p>

                <h4 class="mb-3">12. Servicios de terceros</h4>
                <p class="mb-1-9">
                    Nuestros Servicios pueden contener enlaces o integraciones con sitios y servicios de terceros (por ejemplo, redes sociales, pasarelas de pago o proveedores de mapas). Reactor no se hace responsable por las prácticas de privacidad de dichos terceros. Te recomendamos revisar sus políticas antes de proporcionarles información personal.
                </p>

                <h4 class="mb-3">13. Cambios en esta Política</h4>
                <p class="mb-1-9">
                    Podemos actualizar esta Política de Privacidad de tiempo en tiempo para reflejar cambios en nuestras prácticas, en la tecnología, en la legislación o en otros factores. Publicaremos la versión actualizada en esta misma página, con la fecha de última modificación. Te recomendamos revisar periódicamente este documento. Si los cambios son sustanciales, te notificaremos por correo electrónico o mediante un aviso destacado dentro de la plataforma.
                </p>

                <h4 class="mb-3">14. Contacto</h4>
                <p class="mb-1-9">
                    Si tenés preguntas, comentarios o reclamos sobre esta Política de Privacidad o sobre el tratamiento de tus datos personales, podés escribirnos a <a href="mailto:info@reactor.com.ar">info@reactor.com.ar</a> o utilizar el formulario disponible en <a href="/nosotros/contacto/">Contacto</a>.
                </p>

                <hr class="my-4">

                <p class="text-muted">
                    <small>Última actualización: 12 de mayo de 2026.</small>
                </p>

            </div>
        </div>
    </div>
</section>
<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
