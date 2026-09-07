<?php

declare(strict_types=1);

/**
 * Aceptacion de una invitacion. Porta reactor-app/invitacion/aceptar.php.
 *
 * QUE SE PIDE: nombre, apellido y celular. El correo NO se pregunta — es el
 * dato con el que se emitio la invitacion y llego hasta aca, asi que ya lo
 * tenemos. Es el espejo del legacy, que emitia por celular y por eso pedia
 * nombre y correo al aceptar.
 *
 * `usuarios` no tiene columna `apellido` (ver db/schema.sql): son dos campos
 * en el formulario porque es lo que la persona espera completar, pero se
 * guardan concatenados en `nombre`, que es como el legacy y el resto del
 * sistema leen el nombre completo. No se toca el esquema por esto.
 *
 * LOS TRES CAMINOS, que son los que pidio el negocio:
 *
 *   1. La persona NO esta registrada  -> se crea `usuarios` + `perfiles` y se le
 *      entrega una contrasena generada (en pantalla y por correo).
 *   2. Ya tiene cuenta pero NO tiene perfil en este dominio -> no se crea ni se
 *      toca la cuenta (ni la contrasena, ni el dominio activo): solo se le
 *      agrega el perfil, y entra con las credenciales que ya usaba.
 *   3. Ya tiene cuenta Y perfil habilitado en este dominio -> NO SE HACE NADA.
 *      La invitacion se cierra igual y se le avisa que ya tenia acceso.
 *
 * Los tres los resuelve `appPerfilAsegurado()` (lib/perfiles.php), que es donde
 * esta escrito con que forma nace el perfil: OPERADOR, con `operacion` e
 * `invitacion` en 1 y `facturacion` en 0.
 *
 * A DIFERENCIA DEL LEGACY, el camino "ya tiene cuenta" TAMBIEN cierra la
 * invitacion (estado 3). El legacy da el acceso pero deja la fila en
 * pendiente para siempre, y esas pendientes eternas son las que ensucian el
 * listado del panel.
 */

require __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/lib/usuarios_alta.php';
require_once dirname(__DIR__) . '/lib/perfiles.php';

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$uuid   = (string) ($_POST['uid'] ?? $_GET['uid'] ?? '');

$inv = invitacionPorUuid($uuid);
if ($inv === null) {
    invitacionCorte(
        'Invitación no encontrada',
        'El enlace no corresponde a ninguna invitación. Verificá que lo hayas copiado completo.'
    );
}

$motivo = invitacionMotivoNoVigente($inv);
if ($motivo !== null) {
    invitacionCorte('Invitación no disponible', $motivo);
}

$dominioId     = (int) $inv['dominio'];
$dominioNombre = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . $dominioId);
$correo        = strtolower(trim((string) $inv['correo']));

$error    = '';
$nombre   = '';
$apellido = '';
$celular  = '';

if ($metodo === 'POST') {
    $nombre   = trim((string) ($_POST['nombre']   ?? ''));
    $apellido = trim((string) ($_POST['apellido'] ?? ''));
    $celular  = trim((string) ($_POST['celular']  ?? ''));

    $error = validarDatosInvitado($nombre, $apellido, $celular);

    if ($error === '') {
        $resultado = aceptarInvitacion($inv, $nombre, $apellido, $celular);
        invitacionLayout('Bienvenido', pantallaBienvenida($resultado, $dominioNombre));
    }
}

/* ------------------------------------------------------------------ */
/* Formulario                                                          */
/* ------------------------------------------------------------------ */

$cuerpo = '
    <p class="inv-lead">
        Estás a un paso de sumarte a <strong>' . e($dominioNombre) . '</strong>.
        Completá tus datos para crear tu acceso.
    </p>

    <form method="post" class="sesion-form" novalidate>
        <input type="hidden" name="uid" value="' . e((string) $inv['uuid']) . '">

        <div class="sesion-campo">
            <input type="text" name="nombre" class="sesion-input" placeholder="Tu nombre"
                   maxlength="60" value="' . e($nombre) . '" autocomplete="given-name" autofocus required>
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>

        <div class="sesion-campo">
            <input type="text" name="apellido" class="sesion-input" placeholder="Tu apellido"
                   maxlength="60" value="' . e($apellido) . '" autocomplete="family-name" required>
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>

        <div class="sesion-campo">
            <input type="tel" name="celular" class="sesion-input" placeholder="Tu celular"
                   maxlength="15" value="' . e($celular) . '" autocomplete="tel" required>
            <i class="fa-solid fa-mobile-screen sesion-input-icono"></i>
        </div>

        <div class="sesion-campo">
            <input type="email" class="sesion-input" value="' . e($correo) . '" readonly
                   title="Es el correo al que se envió la invitación">
            <i class="fa-solid fa-envelope sesion-input-icono"></i>
        </div>

        <div class="sesion-fila">
            <a class="sesion-btn sesion-btn-secundario" href="./?uid=' . e((string) $inv['uuid']) . '">
                <i class="fa-solid fa-chevron-left"></i> Volver
            </a>
            <button type="submit" class="sesion-btn">
                <i class="fa-solid fa-check"></i> Aceptar
            </button>
        </div>
    </form>
';

invitacionLayout('Completá tus datos', $cuerpo, $error);

/* ------------------------------------------------------------------ */
/* Logica                                                              */
/* ------------------------------------------------------------------ */

/** Devuelve el mensaje de error, o '' si los datos estan bien. */
function validarDatosInvitado(string $nombre, string $apellido, string $celular): string
{
    if ($nombre === '' || $apellido === '' || $celular === '') {
        return 'Completá los tres campos para continuar.';
    }
    // `usuarios.nombre` es varchar(100) y guarda "Nombre Apellido".
    if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) {
        return 'El nombre y el apellido no pueden superar 60 caracteres cada uno.';
    }
    if (mb_strlen($celular) > 15) {
        return 'El celular no puede superar 15 caracteres.';
    }
    if (!preg_match('/^[+0-9\s().-]+$/', $celular)) {
        return 'El celular solo admite números y los signos + ( ) - .';
    }
    if (preg_match_all('/\d/', $celular) < 8) {
        return 'El celular parece incompleto: ingresá el número con característica.';
    }
    return '';
}

/**
 * Cierra la invitacion y deja a la persona con acceso al dominio.
 * Todo va en una transaccion: media aceptacion (usuario sin perfil, o
 * invitacion cerrada sin cuenta) es un estado que ninguna pantalla sabe leer.
 *
 * @return array{usuario:string,contrasena:?string,nueva:bool,ya_tenia:bool,correo_ok:bool}
 */
function aceptarInvitacion(array $inv, string $nombre, string $apellido, string $celular): array
{
    $pdo           = db();
    $dominioId     = (int) $inv['dominio'];
    $dominioNombre = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . $dominioId);
    $correo        = strtolower(trim((string) $inv['correo']));
    $completo      = $nombre . ' ' . $apellido;

    $pdo->beginTransaction();
    try {
        // La invitacion se cierra con el estado en el WHERE: si dos envios
        // simultaneos entran juntos, solo uno crea la cuenta.
        $cerrar = $pdo->prepare(
            'UPDATE invitaciones
                SET nombre = :nombre, celular = :celular, estado = :aceptada
              WHERE id = :id AND estado = :pendiente'
        );
        $cerrar->execute([
            ':nombre'    => $completo,
            ':celular'   => $celular,
            ':aceptada'  => INVITACION_ACEPTADA,
            ':pendiente' => INVITACION_PENDIENTE,
            ':id'        => (int) $inv['id'],
        ]);
        if ($cerrar->rowCount() === 0) {
            $pdo->rollBack();
            invitacionCorte('Invitación no disponible', 'Esta invitación ya fue resuelta.');
        }

        // Un placeholder por columna: con EMULATE_PREPARES=false PDO no
        // admite repetir el mismo nombre en un statement (HY093).
        $busca = $pdo->prepare(
            'SELECT id FROM usuarios
              WHERE usuario = :u OR LOWER(correo) = :c
              ORDER BY id LIMIT 1'
        );
        $busca->execute([':u' => $correo, ':c' => $correo]);
        $usuarioId = (int) ($busca->fetchColumn() ?: 0);

        $contrasena = null;
        $nueva      = $usuarioId === 0;

        if ($nueva) {
            $contrasena = contrasenaGenerada();
            // Mismo canal de alta que el BackOffice (lib/usuarios_alta.php):
            // cifra la contrasena y fija la forma unica del alta.
            $usuarioId = usuarioAlta($pdo, [
                'nombre'      => $completo,
                'usuario'     => $correo,
                'contrasena'  => $contrasena,
                'correo'      => $correo,
                'celular'     => $celular,
                'registrante' => (int) $inv['emisor'],
                'dominio'     => $dominioId,
            ]);
        }

        // Si ya tenia un perfil habilitado en este dominio, esto no crea nada y
        // devuelve el que estaba: es el tercer camino de la cabecera.
        //
        // El emisor de la invitacion queda como `registrante` del perfil que se
        // cree. Es la diferencia con `usuarios`.`registrante` de arriba, que
        // solo se escribe cuando la cuenta se crea aca: una cuenta que ya
        // existia la registro otra persona en otro momento y ese dato no se
        // pisa. El acceso, en cambio, lo esta otorgando esta invitacion.
        $perfilPrevio = $nueva ? 0 : perfilHabilitadoDelDominio($pdo, $usuarioId, $dominioId);
        $perfilId     = appPerfilAsegurado($pdo, $usuarioId, $dominioId, $dominioNombre, (int) $inv['emisor']);

        // Solo a la cuenta nueva se le fija el perfil activo. A una cuenta que
        // ya existia no se le mueve el dominio con el que esta trabajando: el
        // acceso nuevo le aparece en "Cambiar de Dominio".
        if ($nueva) {
            usuarioPerfilActivo($pdo, $usuarioId, $perfilId);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    // El correo va DESPUES del commit y su resultado no revierte nada: el
    // acceso ya existe y es valido. Si el envio falla, la contrasena se
    // muestra igual en pantalla, que es donde esta la persona ahora mismo.
    $correoOk = true;
    if ($nueva && $contrasena !== null) {
        $envio = databoxCorreoEncolar([
            'destino'      => $correo,
            'destinatario' => $completo,
            'asunto'       => 'Tu acceso a ' . $dominioNombre,
            'cuerpo'       => invitacionCuerpoCredenciales(
                $dominioNombre,
                $correo,
                $contrasena,
                appBaseUrl() . '/sesion/iniciar'
            ),
            'prioridad'    => 4,
            'tags'         => 'invitacion-credenciales',
        ]);
        $correoOk = (bool) $envio['ok'];
    }

    return [
        'usuario'    => $correo,
        'contrasena' => $contrasena,
        'nueva'      => $nueva,
        'ya_tenia'   => $perfilPrevio > 0,
        'correo_ok'  => $correoOk,
    ];
}

/**
 * Id del perfil habilitado que la cuenta YA tenia en el dominio, o 0.
 *
 * Se consulta antes de `appPerfilAsegurado()` solo para el MENSAJE: la funcion
 * devuelve el mismo id lo haya encontrado o creado, y decirle "ya tenías acceso"
 * a quien acaba de recibirlo —o al reves— seria mentirle sobre lo que paso.
 */
function perfilHabilitadoDelDominio(PDO $pdo, int $usuarioId, int $dominioId): int
{
    $stmt = $pdo->prepare(
        'SELECT id FROM perfiles
          WHERE usuario = :u AND dominio = :d AND habilitado = :hab
          ORDER BY id LIMIT 1'
    );
    $stmt->execute([':u' => $usuarioId, ':d' => $dominioId, ':hab' => HABILITADO]);

    return (int) ($stmt->fetchColumn() ?: 0);
}

/**
 * Contrasena inicial. Sin caracteres ambiguos (0/O, 1/l/I): se lee de un
 * correo y se tipea a mano. 10 chars -> 16 en base64, dentro del varchar(50)
 * de `usuarios.contrasena`.
 */
function contrasenaGenerada(): string
{
    $alfabeto = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $tope     = strlen($alfabeto) - 1;
    $out      = '';
    for ($i = 0; $i < 10; $i++) {
        $out .= $alfabeto[random_int(0, $tope)];
    }
    return $out;
}

/** @param array{usuario:string,contrasena:?string,nueva:bool,ya_tenia:bool,correo_ok:bool} $r */
function pantallaBienvenida(array $r, string $dominioNombre): string
{
    $ingresar = '<div class="inv-acciones inv-acciones-sola">
            <a class="sesion-btn" href="' . e(appBaseUrl()) . '/sesion/iniciar">
                <i class="fa-solid fa-right-to-bracket"></i> Ingresar
            </a>
        </div>';

    // Camino 3: ya tenia perfil en este dominio. No se creo nada.
    if ($r['ya_tenia']) {
        return '<div class="inv-nota inv-nota-ok">Ya tenías acceso a <strong>'
             . e($dominioNombre) . '</strong>.</div>'
             . '<p class="inv-lead">Ingresá con las credenciales que ya usabas: no hizo falta '
             . 'cambiarte nada.</p>'
             . $ingresar;
    }

    // Camino 2: la cuenta existia y se le sumo el acceso a este dominio.
    if (!$r['nueva']) {
        return '<div class="inv-nota inv-nota-ok">Ya tenés acceso a <strong>'
             . e($dominioNombre) . '</strong>.</div>'
             . '<p class="inv-lead">Ingresá con las credenciales que ya usabas: el dominio nuevo '
             . 'te va a aparecer en <strong>Cambiar de Dominio</strong>.</p>'
             . $ingresar;
    }

    // Camino 1: cuenta recien creada.
    $aviso = $r['correo_ok']
        ? '<p class="inv-lead">Te mandamos estos datos por correo a <strong>' . e($r['usuario']) . '</strong>.</p>'
        : '<div class="inv-nota inv-nota-bad">No pudimos enviarte el correo con los datos de acceso. '
          . 'Anotálos antes de cerrar esta página.</div>';

    return '<div class="inv-nota inv-nota-ok">Tu cuenta en <strong>' . e($dominioNombre)
         . '</strong> ya está activa.</div>'
         . '<div class="inv-datos">'
         . invitacionDato('Usuario', $r['usuario'])
         . invitacionDato('Contraseña', (string) $r['contrasena'])
         . '</div>'
         . $aviso
         . '<p class="inv-lead">Te recomendamos cambiarla después del primer ingreso.</p>'
         . $ingresar;
}
