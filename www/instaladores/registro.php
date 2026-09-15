<?php

declare(strict_types=1);

/**
 * Formulario de registro de instaladores: `/instaladores/registro`.
 *
 * Port de `reactor-www/instaladores/registro.php`. El alta y el registro en el
 * CRM viven en `lib/instaladores.php`; acá está sólo la pantalla.
 *
 * QUÉ SE ARREGLÓ DEL ORIGINAL:
 *
 *   - NO VALIDABA NADA. El legacy pasaba lo que llegara del POST directo al
 *     INSERT, así que un submit con todo vacío creaba una fila en blanco y un
 *     correo inventado quedaba guardado igual. Ahora `instaladorValidar()`
 *     corta antes y el formulario vuelve con los datos puestos y el error al
 *     lado del campo, en vez de perder lo tipeado.
 *   - NO TENÍA ANTISPAM. El captcha estaba comentado (`//echo $wAntibot...`),
 *     o sea que era un formulario abierto en internet que escribe en la base
 *     sin ninguna traba. Ahora hay trampa de miel y tiempo mínimo — ver abajo.
 *   - El POST exitoso ahora redirige (patrón POST/Redirect/GET): así recargar
 *     la pantalla de confirmación no vuelve a dar de alta al instalador.
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/instaladores.php';

$errores = [];
$datos   = ['nombre' => '', 'correo' => '', 'celular' => '', 'actividad' => ''];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    foreach (array_keys($datos) as $campo) {
        $datos[$campo] = wwwEntrada($campo);
    }

    // ANTISPAM EN DOS PASOS, los dos invisibles para una persona:
    //
    //   1. Trampa de miel: `sitio` es un campo oculto que nadie ve ni puede
    //      tabular. Un bot que completa todo lo completa también.
    //   2. Tiempo mínimo: `desde` viaja con el momento en que se dibujó el
    //      formulario. Nadie lee, piensa y tipea nombre, correo y celular en
    //      menos de tres segundos.
    //
    // Los dos casos se tratan igual que un envío exitoso —misma pantalla, mismo
    // texto— y no se guarda nada. Decirle al bot que lo detectaron sólo sirve
    // para que la próxima vuelta sea más difícil de detectar.
    $desde     = wwwEntradaEntera('desde');
    $esRobot   = wwwEntrada('sitio') !== '' || ($desde > 0 && (time() - $desde) < 3);

    if ($esRobot) {
        wwwIr('/instaladores/registro?ok=1');
    }

    $errores = instaladorValidar($datos);

    if ($errores === []) {
        $alta = instaladorAlta($datos);
        if ($alta['ok']) {
            // POST/Redirect/GET: sin esto, un F5 sobre la confirmación repite
            // el alta y el instalador queda cargado dos veces.
            wwwIr('/instaladores/registro?ok=1');
        }
        $errores['general'] = (string) $alta['error'];
    }
}

if (wwwEntrada('ok') === '1') {
    wwwAviso(
        'Hemos recibido tu registro correctamente. Pronto te estaremos contactando.',
        '/instaladores',
        '¡Gracias!'
    );
}

wwwTitulo('Registro de Instaladores');
wwwDescripcion('Registrate como instalador certificado Reactor y aparecé en el listado que ven nuestros clientes.');

require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';

/** Imprime el mensaje de error de un campo, si lo tiene. */
function registroError(array $errores, string $campo): void
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
                    <h1>Registro de Instaladores</h1>
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
                        <li><a href="/instaladores">Instaladores</a></li>
                        <li><a href="#!">Registro</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</section>

<!-- FORMULARIO
================================================== -->
<section class="pt-0">
    <div class="container">
        <div class="section-heading4">
            <span>Ponte en contacto</span>
            <h2>Mantente conectado con tus clientes Reactor</h2>
        </div>
        <div class="row align-items-center justify-content-center">
            <div class="col-lg-8 col-xl-7">
                <div class="p-1-6 p-sm-1-9 p-lg-2-2 box-shadow2">

                    <?php if (isset($errores['general'])): ?>
                        <div class="alert alert-danger"><?= e($errores['general']) ?></div>
                    <?php endif; ?>

                    <!-- `novalidate`: la validación del navegador es una ayuda, no
                         el control. Lo que corre siempre es la del servidor. -->
                    <form action="/instaladores/registro" method="post" novalidate>
                        <div class="quform-elements">
                            <div class="row">

                                <div class="col-md-12">
                                    <div class="quform-element form-group">
                                        <label for="nombre">Nombre <span class="quform-required">*</span></label>
                                        <div class="quform-input">
                                            <input class="form-control" id="nombre" type="text" name="nombre" maxlength="255" placeholder="Tu nombre" value="<?= e($datos['nombre']) ?>">
                                        </div>
                                        <?php registroError($errores, 'nombre'); ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="quform-element form-group">
                                        <label for="correo">Correo <span class="quform-required">*</span></label>
                                        <div class="quform-input">
                                            <input class="form-control" id="correo" type="email" name="correo" maxlength="255" placeholder="Tu correo" value="<?= e($datos['correo']) ?>">
                                        </div>
                                        <?php registroError($errores, 'correo'); ?>
                                    </div>
                                </div>

                                <div class="col-md-6">
                                    <div class="quform-element form-group">
                                        <label for="celular">Celular</label>
                                        <div class="quform-input">
                                            <input class="form-control" id="celular" type="tel" inputmode="numeric" name="celular" maxlength="<?= e((string) CELULAR_DIGITOS) ?>" placeholder="2644123456" value="<?= e($datos['celular']) ?>">
                                        </div>
                                        <div class="display-30 text-muted mt-1"><?= e((string) CELULAR_DIGITOS) ?> dígitos, sin 0, sin 15 y sin +54.</div>
                                        <?php registroError($errores, 'celular'); ?>
                                    </div>
                                </div>

                                <div class="col-md-12">
                                    <div class="quform-element form-group">
                                        <label for="actividad">Actividad</label>
                                        <div class="quform-input">
                                            <input class="form-control" id="actividad" type="text" name="actividad" maxlength="255" placeholder="Describe tu actividad (opcional)" value="<?= e($datos['actividad']) ?>">
                                        </div>
                                        <?php registroError($errores, 'actividad'); ?>
                                    </div>
                                </div>

                                <!-- Trampa de miel. `aria-hidden` + `tabindex=-1` para que
                                     un lector de pantalla tampoco lo anuncie. -->
                                <div style="position:absolute; left:-9999px;" aria-hidden="true">
                                    <label for="sitio">No completar</label>
                                    <input type="text" id="sitio" name="sitio" tabindex="-1" autocomplete="off" value="">
                                </div>
                                <input type="hidden" name="desde" value="<?= e((string) time()) ?>">

                                <div class="col-md-12">
                                    <div class="quform-submit-inner">
                                        <button class="butn lg" type="submit" style="margin-top: 15px;"><span>Enviar Registro</span></button>
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

<?php require $_SERVER['DOCUMENT_ROOT'] . '/sistema/pie.php'; ?>
