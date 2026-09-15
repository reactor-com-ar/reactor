<?php

declare(strict_types=1);

/**
 * Formulario de contacto: `/nosotros/contacto`.
 *
 * Port de `reactor-www/nosotros/contacto/index.php`. La consulta va al CRM de
 * Databox por `prospectoRegistrar()`, el mismo embudo (`reactor-ventas`) y el
 * mismo asunto ("Consulta Web") que usaba el legacy, así que los leads siguen
 * cayendo donde el equipo comercial ya los mira.
 *
 * QUÉ SE ARREGLÓ DEL ORIGINAL — lo mismo que en el registro de instaladores:
 * no validaba nada, no tenía antispam (el captcha estaba comentado) y volvía a
 * enviar la consulta si alguien recargaba después del POST. Ver
 * `instaladores/registro.php`, donde está explicado en detalle.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/prospectos.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/instaladores.php'; // CELULAR_DIGITOS

$errores = [];
$datos   = ['nombre' => '', 'correo' => '', 'celular' => '', 'mensaje' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (array_keys($datos) as $campo) {
        $datos[$campo] = wwwEntrada($campo);
    }

    $desde = wwwEntradaEntera('desde');
    if (wwwEntrada('sitio') !== '' || ($desde > 0 && (time() - $desde) < 3)) {
        wwwIr('/nosotros/contacto/?ok=1');
    }

    if ($datos['nombre'] === '') {
        $errores['nombre'] = 'Ingresá tu nombre.';
    }
    if ($datos['correo'] === '') {
        $errores['correo'] = 'Ingresá tu correo.';
    } elseif (filter_var($datos['correo'], FILTER_VALIDATE_EMAIL) === false) {
        $errores['correo'] = 'Ese correo no parece válido.';
    }
    if ($datos['celular'] !== '' && (!ctype_digit($datos['celular']) || strlen($datos['celular']) !== CELULAR_DIGITOS)) {
        $errores['celular'] = 'El celular tiene que ser de ' . CELULAR_DIGITOS . ' dígitos, sin 0, sin 15 y sin +54.';
    }
    if ($datos['mensaje'] === '') {
        $errores['mensaje'] = 'Contanos en qué podemos ayudarte.';
    } elseif (mb_strlen($datos['mensaje']) > 1000) {
        $errores['mensaje'] = 'El mensaje es demasiado largo (máximo 1000 caracteres).';
    }

    if ($errores === []) {
        $registro = prospectoRegistrar([
            'nombre'  => $datos['nombre'],
            'correo'  => $datos['correo'],
            'celular' => $datos['celular'],
            'asunto'  => 'Consulta Web',
            'mensaje' => $datos['mensaje'],
        ]);

        if ($registro['ok']) {
            wwwIr('/nosotros/contacto/?ok=1');
        }

        // Acá el CRM SÍ es el destino final —no hay alta local que respalde la
        // consulta como en el registro de instaladores—, así que si falla hay
        // que decirlo: si no, la persona se va creyendo que escribió y nadie
        // recibió nada. Se le ofrece WhatsApp, que no depende de esto.
        error_log('[contacto] no se pudo registrar la consulta: ' . (string) $registro['error']);
        $errores['general'] = 'No pudimos enviar tu mensaje en este momento. '
            . 'Probá de nuevo en unos minutos o escribinos por WhatsApp al (+54) 1163099315.';
    }
}

if (wwwEntrada('ok') === '1') {
    wwwAviso(
        'Tu mensaje ha sido enviado correctamente. Gracias por contactarnos, te estaremos respondiendo a la brevedad.',
        '/',
        '¡Gracias!'
    );
}

wwwTitulo('Contacto');
wwwDescripcion('Escribinos: contacto comercial y soporte de Reactor.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';

/** Imprime el mensaje de error de un campo, si lo tiene. */
function contactoError(array $errores, string $campo): void
{
    if (isset($errores[$campo])) {
        echo '<div class="text-danger display-30 mt-1">' . e($errores[$campo]) . '</div>';
    }
}
?>

<!-- PAGE TITLE
================================================== -->
<section class="top-position1 pt-0">
    <div class="page-title-section bg-img cover-background theme-overlay-blue-dark" data-overlay-dark="75" data-background="<?= e(wwwPortada()) ?>">
        <div class="container">
            <div class="row">
                <div class="col-lg-12">
                    <h1>Contacto</h1>
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
                        <li><a href="#!">Nosotros</a></li>
                        <li><a href="#!">Contacto</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT FORM
================================================== -->
<section class="pt-0">
    <div class="container">
        <div class="section-heading4">
            <span>Ponte en contacto</span>
            <h2>Siempre escuchando a nuestros clientes</h2>
        </div>
        <div class="row align-items-center justify-content-center">
            <div class="col-lg-8 col-xl-7">
                <div class="p-1-6 p-sm-1-9 p-lg-2-2 box-shadow2">

                    <?php if (isset($errores['general'])): ?>
                        <div class="alert alert-danger"><?= e($errores['general']) ?></div>
                    <?php endif; ?>

                    <!-- La barra final NO es un descuido. `/nosotros/contacto` es
                         una CARPETA, asi que Apache responde 301 a
                         `/nosotros/contacto/` — y un 301 sobre un POST hace que el
                         navegador reintente en GET y pierda el cuerpo: el mensaje
                         se enviaba vacio. Apuntando a la URL final no hay redireccion
                         en el medio. (El legacy usaba `action=""`, que posteaba a la
                         URL en curso y por eso no se lo comia, pero deja el destino
                         del formulario dependiendo de con que URL se entro.) -->
                    <form action="/nosotros/contacto/" method="post" novalidate>
                        <div class="quform-elements">
                            <div class="row">

                                <div class="col-md-12">
                                    <div class="quform-element form-group">
                                        <label for="nombre">Nombre <span class="quform-required">*</span></label>
                                        <div class="quform-input">
                                            <input class="form-control" id="nombre" type="text" name="nombre" maxlength="255" placeholder="Tu nombre" value="<?= e($datos['nombre']) ?>">
                                        </div>
                                        <?php contactoError($errores, 'nombre'); ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="quform-element form-group">
                                        <label for="correo">Correo <span class="quform-required">*</span></label>
                                        <div class="quform-input">
                                            <input class="form-control" id="correo" type="email" name="correo" maxlength="255" placeholder="Tu correo" value="<?= e($datos['correo']) ?>">
                                        </div>
                                        <?php contactoError($errores, 'correo'); ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="quform-element form-group">
                                        <label for="celular">Celular</label>
                                        <div class="quform-input">
                                            <input class="form-control" id="celular" type="tel" inputmode="numeric" name="celular" maxlength="<?= e((string) CELULAR_DIGITOS) ?>" placeholder="2644123456" value="<?= e($datos['celular']) ?>">
                                        </div>
                                        <?php contactoError($errores, 'celular'); ?>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <div class="quform-element form-group">
                                        <label for="mensaje">Mensaje <span class="quform-required">*</span></label>
                                        <div class="quform-input">
                                            <textarea class="form-control" id="mensaje" name="mensaje" rows="3" maxlength="1000" placeholder="Dinos unas palabras"><?= e($datos['mensaje']) ?></textarea>
                                        </div>
                                        <?php contactoError($errores, 'mensaje'); ?>
                                    </div>
                                </div>

                                <div style="position:absolute; left:-9999px;" aria-hidden="true">
                                    <label for="sitio">No completar</label>
                                    <input type="text" id="sitio" name="sitio" tabindex="-1" autocomplete="off" value="">
                                </div>
                                <input type="hidden" name="desde" value="<?= e((string) time()) ?>">

                                <div class="col-md-12">
                                    <div class="quform-submit-inner">
                                        <button class="butn lg" type="submit" style="margin-top: 15px;"><span>Enviar Mensaje</span></button>
                                    </div>
                                </div>

                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- CONTACT INFO
================================================== -->
<section class="py-0">
    <div class="row g-0">
        <div class="col-xl-6 bg-img cover-background theme-overlay-blue-dark min-height-450 d-none d-xl-block" data-background="/img/bg/bg-14.jpg"></div>
        <div class="col-xl-6">
            <div class="row g-0">
                <div class="col-sm-6 text-center">
                    <div class="p-2-5 p-lg-6 p-xl-7 bg-primary h-100 border-color-light-white border-sm-end border-bottom">
                        <i class="ti-map-alt fs-1 text-white"></i>
                        <div class="borders-bottom border-width-2 opacity3 border-color-white mx-auto my-4 w-70px"></div>
                        <h6 class="text-white font-weight-500">Argentina</h6>
                    </div>
                </div>
                <div class="col-sm-6 text-center">
                    <div class="p-2-5 p-lg-6 p-xl-7 bg-primary h-100 borders-bottom border-color-light-white">
                        <i class="ti-mobile fs-1 text-white"></i>
                        <div class="borders-bottom border-width-2 opacity3 border-color-white mx-auto my-4 w-70px"></div>
                        <h6 class="text-white font-weight-500">(+54) 1163099315</h6>
                    </div>
                </div>
                <div class="col-sm-6 text-center">
                    <div class="p-2-5 p-lg-6 p-xl-7 bg-primary h-100 borders-bottom border-lg-bottom-0 border-sm-end border-color-light-white">
                        <i class="ti-email fs-1 text-white"></i>
                        <div class="borders-bottom border-width-2 opacity3 border-color-white mx-auto my-4 w-70px"></div>
                        <h6 class="text-white font-weight-500">info@reactor.com.ar</h6>
                    </div>
                </div>
                <div class="col-sm-6 text-center">
                    <div class="p-2-5 p-lg-6 p-xl-7 bg-primary h-100">
                        <i class="ti-time fs-1 text-white"></i>
                        <div class="borders-bottom border-width-2 opacity3 border-color-white mx-auto my-4 w-70px"></div>
                        <h6 class="text-white font-weight-500">Lun a Vie 9hs a 21hs</h6>
                    </div>
                </div>
            </div>
        </div>
    </div>
</section>

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
