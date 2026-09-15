<?php

declare(strict_types=1);

/**
 * Aceptacion de una invitacion. Porta reactor-app/invitacion/aceptar.php.
 *
 * SOLO SE PIDE LO QUE FALTA (15/09/2026). La pantalla resuelve PRIMERO si el
 * correo de la invitacion ya tiene cuenta y recien despues decide que dibujar:
 *
 *   - Sin cuenta            -> se piden los tres campos (nombre, apellido, celular).
 *   - Con cuenta incompleta -> se pide SOLO lo que le falta a la cuenta y el
 *                              resto se muestra de solo lectura.
 *   - Con cuenta completa   -> NO SE PREGUNTA NADA: la invitacion se resuelve en
 *                              el mismo request y se muestra la bienvenida.
 *
 * Hasta esa fecha el formulario era incondicional y la busqueda de la cuenta
 * vivia ADENTRO del POST, asi que a alguien ya registrado se le pedian igual los
 * tres datos y despues se descartaban: el camino "ya tiene cuenta" no toca
 * `usuarios` a proposito (ni contrasena ni dominio activo), asi que lo tipeado
 * solo quedaba escrito en `invitaciones`.
 *
 * QUE SE COMPLETA Y QUE NO. De la cuenta que ya existe se llenan SOLO las
 * columnas vacias (`nombre`, `celular`, `correo`) y con un `WHERE` que las exige
 * vacias — ver `usuarioCompletarVacios()`. Nunca se pisa un dato cargado: la
 * credencial de esta pantalla es el `uuid` del enlace y ese uuid **lo ve el
 * emisor** (el listado de Invitaciones del panel lo muestra como
 * `Identificador`), asi que permitir sobrescribir el celular o el correo de una
 * cuenta ajena seria entregarle el desvio de las dos vias de recuperacion.
 *
 * EL CORREO NO SE PREGUNTA NUNCA: es el dato con el que se emitio la invitacion
 * y llego hasta aca, asi que ya lo tenemos.
 *
 * `usuarios` no tiene columna `apellido` (ver db/schema.sql): son dos campos
 * en el formulario porque es lo que la persona espera completar, pero se
 * guardan concatenados en `nombre`, que es como el legacy y el resto del
 * sistema leen el nombre completo. No se toca el esquema por esto.
 *
 * EL CELULAR SE GUARDA CRUDO: exactamente 10 digitos y nada mas — sin espacios,
 * guiones, parentesis, puntos ni el signo +. Ver CELULAR_DIGITOS y
 * validarDatosInvitado().
 *
 * LOS TRES CAMINOS del alta, que son los que pidio el negocio, no cambiaron:
 *
 *   1. La persona NO esta registrada  -> se crea `usuarios` + `perfiles` y se le
 *      entrega una contrasena generada (en pantalla y por correo).
 *   2. Ya tiene cuenta pero NO tiene perfil en este dominio -> no se crea la
 *      cuenta ni se le toca la contrasena ni el dominio activo: solo se le
 *      completan los huecos, se le agrega el perfil, y entra con las
 *      credenciales que ya usaba.
 *   3. Ya tiene cuenta Y perfil habilitado en este dominio -> NO SE CREA NADA.
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
// Para dejar la sesion ABIERTA al terminar el alta: `appSesionAbrir()` es el
// mismo punto que usan el login por contrasena, el de codigo y el canje de un
// enlace magico. Ver `sesionAbiertaSiCorresponde()` al pie.
require_once dirname(__DIR__) . '/lib/auth.php';

/**
 * Largo EXACTO del celular, en digitos.
 *
 * 10, que es el formato argentino sin el 0 de la caracteristica y sin el 15:
 * `2644123456`. No es un numero elegido de memoria — es lo que tiene el 100% de
 * los datos: las 351 filas no vacias de `usuarios`.`celular` y las 33 de
 * `invitaciones`.`celular` medidas el 07/09/2026 tienen los 10 digitos y NINGUNA
 * trae un caracter que no sea un digito.
 *
 * POR ESO EL CAMPO NO ADMITE SEPARADORES NI PREFIJOS. Antes aceptaba
 * `+ ( ) - .` y espacios con un minimo de 8 digitos, asi que `+54 9 264
 * 412-3456` entraba tal cual y quedaba escrito distinto de las otras 384 filas:
 * la columna es texto (`usuarios`.`celular` es varchar(15)) y nadie normaliza al
 * leer, asi que dos formas del mismo numero no se cruzan ni se buscan igual —
 * el buscador de Invitaciones hace `LIKE` sobre la columna cruda
 * (panel/api/invitaciones.php).
 *
 * Se declara una sola vez y de aca salen todas las apariciones del numero: el
 * `placeholder` / `maxlength` / `pattern` / `title` del input y el corte del
 * servidor. El filtro de tecleo NO la usa a proposito — saca separadores pero no
 * recorta, ver el `<script>` del formulario. Es copia de la constante homonima
 * de panel/invitacion/aceptar.php, como todo lo que comparten las dos
 * invitaciones sin compartir docroot.
 */
const CELULAR_DIGITOS = 10;

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

/* ------------------------------------------------------------------ */
/* Que hay que preguntar                                               */
/* ------------------------------------------------------------------ */

// LA CUENTA SE BUSCA ANTES DE DIBUJAR NADA. Esta consulta es la que decide si
// hay formulario y con que campos; la de `aceptarInvitacion()` —la misma, pero
// dentro de la transaccion— es la que decide que se escribe. Son dos porque
// entre una y otra puede pasar cualquier cosa: la de adentro es la que manda.
$cuenta        = cuentaDelCorreo(db(), $correo);
$nombreCuenta  = $cuenta['nombre']  ?? '';
$celularCuenta = $cuenta['celular'] ?? '';

// Sin cuenta las dos quedan vacias, asi que se piden los dos bloques: es el
// mismo criterio escrito una sola vez.
$pideNombre  = $nombreCuenta  === '';
$pideCelular = $celularCuenta === '';

// `accion=confirmar` distingue el envio DEL FORMULARIO del POST con el que
// `index.php` manda a esta pantalla (el boton Aceptar de la ficha). Sin esa
// marca el POST se trata como una llegada mas y vuelve a decidir que mostrar.
$confirma = $metodo === 'POST' && (string) ($_POST['accion'] ?? '') === 'confirmar';

$error    = '';
$nombre   = '';
$apellido = '';
$celular  = '';

if ($confirma) {
    // SOLO SE LEE DEL POST LO QUE ESTA PANTALLA PREGUNTO. Lo que no se pregunto
    // ya esta en la cuenta, y tomarlo del formulario dejaria que un POST armado
    // a mano mandara un celular sobre una cuenta que ya tiene el suyo. El
    // `WHERE` de `usuarioCompletarVacios()` es el segundo candado del mismo
    // agujero; este es el primero.
    $nombre   = $pideNombre  ? trim((string) ($_POST['nombre']   ?? '')) : '';
    $apellido = $pideNombre  ? trim((string) ($_POST['apellido'] ?? '')) : '';
    $celular  = $pideCelular ? trim((string) ($_POST['celular']  ?? '')) : '';

    $error = validarDatosInvitado($nombre, $apellido, $celular, $pideNombre, $pideCelular);

    if ($error === '') {
        $resultado = aceptarInvitacion($inv, $nombre, $apellido, $celular);
        invitacionLayout('Bienvenido', pantallaBienvenida($resultado, $dominioNombre));
    }
} elseif (!$pideNombre && !$pideCelular) {
    // NADA QUE PREGUNTAR: la cuenta existe y tiene todo. Un formulario con los
    // tres campos de solo lectura y un boton Aceptar seria un tramite que no
    // decide nada — la persona ya apreto Aceptar en la ficha para llegar hasta
    // aca. Se resuelve en el mismo request.
    $resultado = aceptarInvitacion($inv, '', '', '');
    invitacionLayout('Bienvenido', pantallaBienvenida($resultado, $dominioNombre));
}

/* ------------------------------------------------------------------ */
/* Formulario                                                          */
/* ------------------------------------------------------------------ */

// Que se esta mirando: un alta o el completado de una cuenta que ya existe.
$lead = $cuenta === null
    ? 'Estás a un paso de sumarte a <strong>' . e($dominioNombre) . '</strong>.
        Completá tus datos para crear tu acceso.'
    : 'Ya tenés una cuenta en Reactor, así que no hace falta que la crees de nuevo.
        Para sumarte a <strong>' . e($dominioNombre) . '</strong> sólo falta
        ' . ($pideNombre && $pideCelular ? 'completar tus datos' : ($pideNombre ? 'tu nombre' : 'tu celular')) . '.';

// El foco va al primer campo editable, que no siempre es el nombre.
$foco = $pideNombre ? 'nombre' : 'celular';

$campos = '';

if ($pideNombre) {
    $campos .= '
        <div class="sesion-campo">
            <input type="text" name="nombre" class="sesion-input" placeholder="Tu nombre"
                   maxlength="60" value="' . e($nombre) . '" autocomplete="given-name"'
                   . ($foco === 'nombre' ? ' autofocus' : '') . ' required>
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>

        <div class="sesion-campo">
            <input type="text" name="apellido" class="sesion-input" placeholder="Tu apellido"
                   maxlength="60" value="' . e($apellido) . '" autocomplete="family-name" required>
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>';
} else {
    // De solo lectura y sin `name`: es un dato de la cuenta, no un campo del
    // formulario. Si viajara, el servidor lo ignoraria igual (ver arriba).
    $campos .= '
        <div class="sesion-campo">
            <input type="text" class="sesion-input" value="' . e($nombreCuenta) . '" readonly
                   title="Es el nombre con el que ya estás registrado">
            <i class="fa-solid fa-user sesion-input-icono"></i>
        </div>';
}

if ($pideCelular) {
    $campos .= '
        <div class="sesion-campo">
            <input type="tel" id="inv-celular" name="celular" class="sesion-input"
                   placeholder="Tu celular (' . CELULAR_DIGITOS . ' dígitos)"
                   inputmode="numeric" pattern="[0-9]{' . CELULAR_DIGITOS . '}"
                   maxlength="' . CELULAR_DIGITOS . '"
                   title="' . CELULAR_DIGITOS . ' dígitos, sin el 0 de la característica y sin el 15"
                   value="' . e($celular) . '" autocomplete="tel"'
                   . ($foco === 'celular' ? ' autofocus' : '') . ' required>
            <i class="fa-solid fa-mobile-screen sesion-input-icono"></i>
        </div>';
} else {
    $campos .= '
        <div class="sesion-campo">
            <input type="tel" class="sesion-input" value="' . e($celularCuenta) . '" readonly
                   title="Es el celular con el que ya estás registrado">
            <i class="fa-solid fa-mobile-screen sesion-input-icono"></i>
        </div>';
}

$cuerpo = '
    <p class="inv-lead">' . $lead . '</p>

    <form method="post" class="sesion-form" novalidate>
        <input type="hidden" name="uid" value="' . e((string) $inv['uuid']) . '">
        <input type="hidden" name="accion" value="confirmar">
        ' . $campos . '

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

if ($pideCelular) {
    $cuerpo .= '
    <script>
    // El celular va a la base CRUDO, asi que se escribe solo con digitos: esto
    // le saca al vuelo espacios, guiones, parentesis, puntos y el +, y de paso
    // resuelve el caso comun de pegar un numero copiado de la agenda del
    // telefono ("264 412-3456"). En el celular importa mas que en el panel:
    // esta pantalla se abre casi siempre desde el correo del telefono.
    //
    // SACA SEPARADORES, NUNCA RECORTA. Quitar un guion deja el MISMO numero;
    // cortarlo en el digito 10 lo cambia por otro — pegar "+5492644123456"
    // quedaria como "5492644123", que no es el celular de nadie y la persona no
    // tendria como notarlo. El sobrante se deja a la vista y lo rechaza
    // validarDatosInvitado() diciendo cuantos digitos van, que es la misma razon
    // por la que el servidor tampoco normaliza. El `maxlength` sigue frenando el
    // tecleo, que es donde si alcanza.
    //
    // NO ES EL CONTROL: el `pattern` de arriba tampoco, porque el form lleva
    // `novalidate`. Lo unico que corre siempre es la validacion del servidor;
    // esto solo evita que la persona llegue al error.
    (function () {
        var campo = document.getElementById("inv-celular");
        if (!campo) { return; }
        function soloDigitos() {
            // Se reescribe SOLO si cambio. Asignar `value` manda el cursor al
            // final del campo, asi que hacerlo en cada tecla volveria imposible
            // corregir un digito del medio.
            var limpio = campo.value.replace(/[^0-9]/g, "");
            if (limpio !== campo.value) { campo.value = limpio; }
        }
        campo.addEventListener("input", soloDigitos);
        campo.addEventListener("blur", soloDigitos);
    })();
    </script>';
}

invitacionLayout('Completá tus datos', $cuerpo, $error);

/* ------------------------------------------------------------------ */
/* Logica                                                              */
/* ------------------------------------------------------------------ */

/**
 * La cuenta que ya existe con ese correo, o null.
 *
 * Un placeholder por columna: con EMULATE_PREPARES=false PDO no admite repetir
 * el mismo nombre en un statement (HY093).
 *
 * `usuario = :u` ademas de `correo` porque el alta por invitacion escribe el
 * correo en las dos columnas (`usuarioAlta()`), y hay cuentas del sistema viejo
 * que solo lo tienen en una. Ninguna de las dos tiene `UNIQUE`, asi que se
 * desempata por id — el mismo criterio que el login.
 *
 * @return array{id:int,nombre:string,celular:string}|null
 */
function cuentaDelCorreo(PDO $pdo, string $correo): ?array
{
    if ($correo === '') {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT id, nombre, celular
           FROM usuarios
          WHERE usuario = :u OR LOWER(correo) = :c
          ORDER BY id LIMIT 1'
    );
    $stmt->execute([':u' => $correo, ':c' => $correo]);
    $fila = $stmt->fetch();

    if (!$fila) {
        return null;
    }

    // Las tres columnas son NULL-ables y ademas arrastran cadenas vacias del
    // sistema viejo: el `trim()` deja los dos casos en el mismo '' y el resto
    // del archivo pregunta por eso y nada mas.
    return [
        'id'      => (int) $fila['id'],
        'nombre'  => trim((string) ($fila['nombre']  ?? '')),
        'celular' => trim((string) ($fila['celular'] ?? '')),
    ];
}

/**
 * Devuelve el mensaje de error, o '' si los datos estan bien.
 *
 * SOLO VALIDA LO QUE SE PREGUNTO. Un campo que no se dibujo llega vacio a
 * proposito (ver el bloque `$confirma`) y exigirlo dejaria la pantalla
 * trabada pidiendo algo que no tiene donde escribirse.
 *
 * EL CELULAR NO SE NORMALIZA, SE RECHAZA. Lo que llegue con separadores no se
 * limpia en silencio: si alguien manda `+54 9 264 412-3456` (13 digitos) y esto
 * se quedara con los 10 ultimos, la persona terminaria registrada con un numero
 * que no escribio y sin enterarse. El filtro de tecleo del formulario ya evita
 * que se llegue hasta aca en el caso normal; este corte es para el POST sin
 * JavaScript, que es el unico que corre siempre.
 */
function validarDatosInvitado(
    string $nombre,
    string $apellido,
    string $celular,
    bool $pideNombre,
    bool $pideCelular
): string {
    if ($pideNombre && $pideCelular && $nombre === '' && $apellido === '' && $celular === '') {
        return 'Completá los tres campos para continuar.';
    }

    if ($pideNombre) {
        if ($nombre === '' || $apellido === '') {
            return 'Completá tu nombre y tu apellido para continuar.';
        }
        // `usuarios.nombre` es varchar(100) y guarda "Nombre Apellido".
        if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) {
            return 'El nombre y el apellido no pueden superar 60 caracteres cada uno.';
        }
    }

    if ($pideCelular) {
        if ($celular === '') {
            return 'Completá tu celular para continuar.';
        }
        // `ctype_digit()` y no una expresion regular: `/^[0-9]+$/` da por buena
        // una cadena terminada en salto de linea (`$` matchea antes del \n
        // final), y aunque el `trim()` del llamador ya lo saque, el criterio no
        // deberia depender de eso. Tambien devuelve false con la cadena vacia.
        if (!ctype_digit($celular)) {
            return 'El celular se escribe solo con números: sin espacios, guiones, paréntesis ni el signo +.';
        }
        // Con solo digitos, `strlen()` ES la cantidad de digitos: son todos ASCII.
        if (strlen($celular) !== CELULAR_DIGITOS) {
            return 'El celular tiene que tener ' . CELULAR_DIGITOS . ' dígitos: la característica sin el 0 '
                 . 'y el número sin el 15 (ej. 2644123456).';
        }
    }

    return '';
}

/**
 * Cierra la invitacion y deja a la persona con acceso al dominio.
 * Todo va en una transaccion: media aceptacion (usuario sin perfil, o
 * invitacion cerrada sin cuenta) es un estado que ninguna pantalla sabe leer.
 *
 * `$nombre` / `$apellido` / `$celular` son SOLO lo que el formulario pregunto:
 * vienen vacios cuando la cuenta ya tenia el dato. La cuenta se vuelve a buscar
 * aca adentro y lo que ella ya tiene le gana a lo que llego por el POST.
 *
 * @return array{usuario:string,contrasena:?string,nueva:bool,ya_tenia:bool,correo_ok:bool,sesion:bool}
 */
function aceptarInvitacion(array $inv, string $nombre, string $apellido, string $celular): array
{
    $pdo           = db();
    $dominioId     = (int) $inv['dominio'];
    $dominioNombre = trim((string) ($inv['dominio_nombre'] ?? '')) ?: ('#' . $dominioId);
    $correo        = strtolower(trim((string) $inv['correo']));

    $pdo->beginTransaction();
    try {
        // LA BUSQUEDA VA ADENTRO DE LA TRANSACCION y es la que manda: la de la
        // pantalla decidio que preguntar, esta decide que escribir. Si entre las
        // dos alguien completo la cuenta, gana lo que ya esta en la base.
        $cuenta        = cuentaDelCorreo($pdo, $correo);
        $nueva         = $cuenta === null;
        $nombreCuenta  = $nueva ? '' : $cuenta['nombre'];
        $celularCuenta = $nueva ? '' : $cuenta['celular'];

        // El dato de la cuenta le gana al del formulario, siempre.
        $completo = $nombreCuenta !== '' ? $nombreCuenta : trim($nombre . ' ' . $apellido);
        $movil    = $celularCuenta !== '' ? $celularCuenta : $celular;

        // La invitacion se cierra con el estado en el WHERE: si dos envios
        // simultaneos entran juntos, solo uno crea la cuenta.
        $cerrar = $pdo->prepare(
            'UPDATE invitaciones
                SET nombre = :nombre, celular = :celular, estado = :aceptada
              WHERE id = :id AND estado = :pendiente'
        );
        $cerrar->execute([
            // Lo que se anota es lo que quedo en la cuenta, no lo que se tipeo:
            // el listado de Invitaciones del panel muestra estas dos columnas y
            // tienen que decir a quien se le dio el acceso.
            ':nombre'    => $completo,
            ':celular'   => $movil,
            ':aceptada'  => INVITACION_ACEPTADA,
            ':pendiente' => INVITACION_PENDIENTE,
            ':id'        => (int) $inv['id'],
        ]);
        if ($cerrar->rowCount() === 0) {
            $pdo->rollBack();
            invitacionCorte('Invitación no disponible', 'Esta invitación ya fue resuelta.');
        }

        $contrasena = null;
        $usuarioId  = $nueva ? 0 : $cuenta['id'];

        if ($nueva) {
            $contrasena = contrasenaGenerada();
            // Mismo canal de alta que el BackOffice (lib/usuarios_alta.php):
            // cifra la contrasena y fija la forma unica del alta.
            $usuarioId = usuarioAlta($pdo, [
                'nombre'      => $completo,
                'usuario'     => $correo,
                'contrasena'  => $contrasena,
                'correo'      => $correo,
                'celular'     => $movil,
                'registrante' => (int) $inv['emisor'],
                'dominio'     => $dominioId,
            ]);
        } else {
            // SOLO LOS HUECOS. La cuenta no se toca en nada mas: ni contrasena,
            // ni dominio activo, ni un dato que ya tuviera cargado.
            usuarioCompletarVacios($pdo, $usuarioId, $completo, $movil, $correo);
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
        // Deja la sesion abierta cuando corresponde, para que `Ingresar` entre
        // directo a la app en vez de mandar al login.
        'sesion'     => sesionAbiertaSiCorresponde($usuarioId, $nueva),
    ];
}

/**
 * Completa las columnas VACIAS de una cuenta que ya existia. Nunca pisa un dato
 * cargado.
 *
 * EL `WHERE` ES EL CONTROL, no el `if` del llamador. La credencial de esta
 * pantalla es el `uuid` del enlace y ese uuid **lo ve el emisor** —el listado de
 * Invitaciones del panel lo muestra como `Identificador`—, asi que un `UPDATE`
 * sin esa condicion le dejaria servido cambiarle el celular o el correo a una
 * cuenta ajena: las dos son puerta de entrada (el login de `app` busca por
 * `celular` O `correo`) y las dos reciben la recuperacion de contrasena.
 * Ademas resuelve la carrera contra la consulta de la pantalla, que se hizo
 * fuera de la transaccion.
 *
 * `correo` se completa aunque no se pregunte: es el correo al que llego esta
 * invitacion y una cuenta sin correo no puede recuperar la contrasena
 * (app/recuperar/). Si ya tenia uno, no se toca — el `WHERE` lo impide.
 */
function usuarioCompletarVacios(PDO $pdo, int $usuarioId, string $nombre, string $celular, string $correo): void
{
    // Las claves son nombres de columna literales de este array, nunca datos de
    // afuera: por eso se pueden interpolar en el SQL.
    $huecos = [
        'nombre'  => $nombre,
        'celular' => $celular,
        'correo'  => $correo,
    ];

    foreach ($huecos as $columna => $valor) {
        if ($valor === '') {
            continue;
        }
        $pdo->prepare(
            "UPDATE usuarios SET {$columna} = :v
              WHERE id = :id AND ({$columna} IS NULL OR {$columna} = '')"
        )->execute([':v' => $valor, ':id' => $usuarioId]);
    }
}

/**
 * Abre la sesion de la app para la cuenta recien creada. Devuelve `true` si
 * quedo abierta.
 *
 * SOLO PARA LA CUENTA NUEVA (`$nueva`), Y NO ES UNA LIMITACION TECNICA — es el
 * punto entero de la funcion. La credencial de esta pantalla es el `uuid` del
 * enlace, y ese uuid **lo ve el emisor**: el listado de Invitaciones del panel lo
 * muestra como `Identificador`. Si aceptar abriera sesion tambien cuando la
 * cuenta YA existia, cualquiera que pueda emitir una invitacion tendria un
 * secuestro de cuenta servido: invita al correo de una cuenta existente —que
 * puede ser Administradora de otros dominios—, copia el uuid de su propio
 * listado, abre el enlace el mismo y entra como esa persona.
 *
 * Con la cuenta nueva no hay nada que secuestrar: la contrasena se acaba de
 * generar y esta impresa en esta misma pantalla, asi que quien tiene el enlace
 * ya tiene las credenciales completas. La sesion no le da nada que no tuviera.
 *
 * A quien ya tenia cuenta se le sigue pidiendo SU contrasena, que es ademas lo
 * que la pantalla le dice ("Ingresá con las credenciales que ya usabas").
 *
 * No se abre dentro de la transaccion: `setcookie()` no se revierte con un
 * rollback y una cookie de una sesion que no llego a existir es peor que
 * ninguna. Va despues del commit, antes de imprimir nada.
 */
function sesionAbiertaSiCorresponde(int $usuarioId, bool $nueva): bool
{
    if (!$nueva) {
        return false;
    }

    // `appUsuarioVigente()` revalida `habilitado` contra la base. Nace en 1
    // (`usuarioAlta()`), asi que esto no deberia fallar nunca — pero si falla,
    // la pantalla cae sola al boton que manda al login.
    $usuario = appUsuarioVigente($usuarioId);
    if ($usuario === null) {
        return false;
    }

    // El mismo punto que el login por contrasena: resuelve el ALCANCE de la
    // sesion (`per` / `dom` / `pan`) y marca `ingresado`. Firmar la cookie a
    // mano dejaria una sesion sin alcance, distinguible de una normal.
    appSesionAbrir($usuario);

    return true;
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

/** @param array{usuario:string,contrasena:?string,nueva:bool,ya_tenia:bool,correo_ok:bool,sesion:bool} $r */
function pantallaBienvenida(array $r, string $dominioNombre): string
{
    // CON LA SESION YA ABIERTA, `Ingresar` va a la app y no al login: mandar al
    // formulario a alguien que ya tiene la cookie es pedirle una contrasena que
    // acabamos de generarle. Sin sesion (la cuenta ya existia) va al login, que
    // es donde tiene que tipear LA SUYA.
    $destino = $r['sesion'] ? '/' : '/sesion/iniciar';

    $ingresar = '<div class="inv-acciones inv-acciones-sola">
            <a class="sesion-btn" href="' . e(appBaseUrl() . $destino) . '">
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
