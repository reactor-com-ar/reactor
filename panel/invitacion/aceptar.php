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
 * panel leen el nombre completo. No se toca el esquema por esto.
 *
 * EL CELULAR SE GUARDA CRUDO: exactamente 10 digitos y nada mas — sin espacios,
 * guiones, parentesis, puntos ni el signo +. Ver CELULAR_DIGITOS y
 * validarDatosInvitado().
 *
 * DOS CAMINOS, como en el legacy:
 *   - La persona ya tiene cuenta (el correo o el usuario ya existen): no se
 *     crea nada ni se le cambia la contrasena, solo se le da el perfil en
 *     este dominio y entra con las credenciales que ya usaba.
 *   - No tiene cuenta: se crea `usuarios` + `perfiles` y se le entrega una
 *     contrasena generada.
 *
 * EL PERFIL QUE SE OTORGA ES DE OPERADOR (`tipo = 'O'`), igual que en el legacy
 * y que en la invitacion de `app`. Hasta el 07/09/2026 era de Administrador,
 * porque la invitacion se emite desde el panel y al panel solo entra un
 * administrador; se cambio por decision explicita — **una invitacion da de alta
 * un Operador, se emita donde se emita**.
 *
 * O SEA QUE ESTA INVITACION YA NO DA ACCESO AL BACKOFFICE. Quien la acepta opera
 * la app; si entra a panel.reactor.com.ar el login lo rebota con `motivo=perfil`,
 * porque el gate sigue siendo `perfiles`.`tipo` = 'A' (lib/acceso.php). Un
 * Administrador se otorga hoy sólo desde el alta de `cloud` o editando el perfil
 * en Usuarios. Ver perfilAsegurado() al pie.
 *
 * A DIFERENCIA DEL LEGACY, el camino "ya tiene cuenta" TAMBIEN cierra la
 * invitacion (estado 3). El legacy da el acceso pero deja la fila en
 * pendiente para siempre, y esas pendientes eternas son las que ensucian el
 * listado del panel.
 */

require __DIR__ . '/_layout.php';
require_once dirname(__DIR__) . '/api/legacy_crypto.php';
require_once dirname(__DIR__) . '/lib/usuarios_alta.php';
// El rol con el que nace el perfil sale de la misma constante que decide quien
// entra al panel: si esa lista cambia, la invitacion la sigue sola.
require_once dirname(__DIR__) . '/lib/acceso.php';

/**
 * Vigencia del enlace que abre la sesion en la app al terminar el alta.
 *
 * 15 minutos, los mismos que los enlaces que emite cloud. Es holgado de sobra
 * para alguien que tiene el boton delante y acota la ventana si la pantalla
 * queda abierta en una maquina prestada.
 */
const ENLACE_APP_MINUTOS = 15;

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
 * `maxlength` / `pattern` / `title` del input y el corte del servidor. El filtro
 * de tecleo NO la usa a proposito — saca separadores pero no recorta, ver el
 * `<script>` del formulario. Es copia de la constante homonima de
 * app/invitacion/aceptar.php, como todo lo que comparten las dos invitaciones
 * sin compartir docroot.
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

    <form method="post" class="login-form" novalidate>
        <input type="hidden" name="uid" value="' . e((string) $inv['uuid']) . '">

        <div class="form-group">
            <label for="inv-nombre">Nombre</label>
            <input type="text" id="inv-nombre" name="nombre" maxlength="60"
                   value="' . e($nombre) . '" autocomplete="given-name" autofocus required>
        </div>

        <div class="form-group">
            <label for="inv-apellido">Apellido</label>
            <input type="text" id="inv-apellido" name="apellido" maxlength="60"
                   value="' . e($apellido) . '" autocomplete="family-name" required>
        </div>

        <div class="form-group">
            <label for="inv-celular">Celular</label>
            <input type="tel" id="inv-celular" name="celular"
                   inputmode="numeric" pattern="[0-9]{' . CELULAR_DIGITOS . '}"
                   maxlength="' . CELULAR_DIGITOS . '" placeholder="Ej. 2644123456"
                   title="' . CELULAR_DIGITOS . ' dígitos, sin el 0 de la característica y sin el 15"
                   value="' . e($celular) . '" autocomplete="tel" required>
        </div>

        <div class="form-group">
            <label for="inv-correo">Correo</label>
            <input type="email" id="inv-correo" value="' . e($correo) . '" readonly
                   title="Es el correo al que se envió la invitación">
        </div>

        ' . ($error !== '' ? '<div class="inv-note inv-note-bad">' . e($error) . '</div>' : '') . '

        <div class="inv-acciones">
            <a class="btn btn-alt" href="./?uid=' . e((string) $inv['uuid']) . '">
                <i class="fa-solid fa-chevron-left"></i> Volver
            </a>
            <button type="submit" class="btn btn-primary">
                <i class="fa-solid fa-check"></i> Aceptar
            </button>
        </div>
    </form>

    <script>
    // El celular va a la base CRUDO, asi que se escribe solo con digitos: esto
    // le saca al vuelo espacios, guiones, parentesis, puntos y el +, y de paso
    // resuelve el caso comun de pegar un numero copiado de la agenda del
    // telefono ("264 412-3456").
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
    </script>
';

invitacionLayout('Completá tus datos', $cuerpo);

/* ------------------------------------------------------------------ */
/* Logica                                                              */
/* ------------------------------------------------------------------ */

/**
 * Devuelve el mensaje de error, o '' si los datos estan bien.
 *
 * EL CELULAR NO SE NORMALIZA, SE RECHAZA. Lo que llegue con separadores no se
 * limpia en silencio: si alguien manda `+54 9 264 412-3456` (13 digitos) y esto
 * se quedara con los 10 ultimos, la persona terminaria registrada con un numero
 * que no escribio y sin enterarse. El filtro de tecleo del formulario ya evita
 * que se llegue hasta aca en el caso normal; este corte es para el POST sin
 * JavaScript, que es el unico que corre siempre.
 */
function validarDatosInvitado(string $nombre, string $apellido, string $celular): string
{
    if ($nombre === '' || $apellido === '' || $celular === '') {
        return 'Completá los tres campos para continuar.';
    }
    // `usuarios.nombre` es varchar(100) y guarda "Nombre Apellido".
    if (mb_strlen($nombre) > 60 || mb_strlen($apellido) > 60) {
        return 'El nombre y el apellido no pueden superar 60 caracteres cada uno.';
    }
    // `ctype_digit()` y no una expresion regular: `/^[0-9]+$/` da por buena una
    // cadena terminada en salto de linea (`$` matchea antes del \n final), y
    // aunque el `trim()` del llamador ya lo saque, el criterio no deberia
    // depender de eso. Tambien devuelve false con la cadena vacia.
    if (!ctype_digit($celular)) {
        return 'El celular se escribe solo con números: sin espacios, guiones, paréntesis ni el signo +.';
    }
    // Con solo digitos, `strlen()` ES la cantidad de digitos: son todos ASCII.
    if (strlen($celular) !== CELULAR_DIGITOS) {
        return 'El celular tiene que tener ' . CELULAR_DIGITOS . ' dígitos: la característica sin el 0 '
             . 'y el número sin el 15 (ej. 2644123456).';
    }
    return '';
}

/**
 * Cierra la invitacion y deja a la persona con acceso al dominio.
 * Todo va en una transaccion: media aceptacion (usuario sin perfil, o
 * invitacion cerrada sin cuenta) es un estado que ninguna pantalla sabe leer.
 *
 * @return array{usuario:string,contrasena:?string,nueva:bool,correo_ok:bool}
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
            // Mismo canal de alta que el BackOffice (panel/lib/usuarios_alta.php):
            // cifra la contrasena y deja el espejo perfiles/dominios/paneles.
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

        // El emisor de la invitacion queda como `registrante` del perfil SIEMPRE,
        // haya cuenta nueva o no. Es la diferencia con `usuarios`.`registrante`
        // de arriba, que solo se escribe cuando la cuenta se crea aca: una
        // cuenta que ya existia la registro otra persona en otro momento, y
        // pisarle ese dato seria reescribir un hecho. El acceso, en cambio, lo
        // esta otorgando esta invitacion.
        $perfilId = perfilAsegurado($pdo, $usuarioId, $dominioId, $dominioNombre, (int) $inv['emisor']);

        // Solo a la cuenta nueva se le fija el perfil activo. A una cuenta que
        // ya existia no se le mueve el dominio con el que esta trabajando: el
        // acceso nuevo le aparece en "Cambiar dominio".
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
            // AL LOGIN DE LA APP, no al del panel: el perfil que se acaba de
            // crear es de Operador y el panel lo rebota. El correo llega despues
            // de que la persona cerro esta pantalla, asi que si apuntara al panel
            // seria la unica pista que le queda y la mandaria al lugar
            // equivocado. Sin enlace magico a proposito: un correo se reenvia, se
            // archiva y queda en el servidor — lo que viaja son las credenciales,
            // que es lo que la persona ya tiene.
            'cuerpo'       => invitacionCuerpoCredenciales($dominioNombre, $correo, $contrasena, panelAppBaseUrl() . '/sesion/iniciar'),
            'prioridad'    => 4,
            'tags'         => 'invitacion-credenciales',
        ]);
        $correoOk = (bool) $envio['ok'];
    }

    return [
        'usuario'    => $correo,
        'contrasena' => $contrasena,
        'nueva'      => $nueva,
        'correo_ok'  => $correoOk,
        // Enlace de un solo uso que abre la sesion en la APP, o '' si no
        // corresponde. Ver la funcion.
        'enlace_app' => enlaceDeAccesoApp($pdo, $usuarioId, (int) $inv['emisor'], $nueva),
    ];
}

/**
 * Enlace magico de un solo uso a la app (`app.reactor.com.ar/acceso?t=...`), o
 * cadena vacia si no corresponde emitirlo.
 *
 * POR QUE UN ENLACE Y NO UNA COOKIE. Esta pagina corre en `panel.reactor.com.ar`
 * y la sesion que hay que abrir es la de `app.reactor.com.ar`: son dos hosts
 * distintos y `setcookie()` no puede cruzar esa frontera. La unica alternativa
 * seria emitir la cookie sobre `.reactor.com.ar`, que se la entregaria tambien a
 * `cloud` y al propio panel — un secreto de la app viajando a dos apps que no lo
 * necesitan. Asi que se reusa el mecanismo que el repo ya tiene para esto:
 * `enlaces_acceso` con `destino = 'app'`, que canjea `app/acceso.php` abriendo la
 * sesion con `appSesionAbrir()`. Es el mismo circuito de los enlaces que emite
 * cloud desde Usuarios.
 *
 * SOLO PARA LA CUENTA NUEVA, y eso es lo importante. La credencial de esta
 * pantalla es el `uuid` del enlace de invitacion, y ese uuid **lo ve el emisor**:
 * el listado de Invitaciones del panel lo muestra como `Identificador`. Si
 * aceptar emitiera un enlace de sesion tambien cuando la cuenta YA existia,
 * cualquier administrador tendria un secuestro de cuenta servido: invita al
 * correo de una cuenta existente —que puede ser Administradora de otros
 * dominios—, copia el uuid de su propio listado, abre el enlace el mismo y entra
 * como esa persona. Con la cuenta nueva no hay nada que secuestrar: la
 * contrasena se acaba de generar y se imprime en esta misma pantalla.
 *
 * VA DESPUES DEL COMMIT, en su propia escritura: el acceso ya es valido sin el
 * enlace, asi que si esto falla no se revierte un alta que salio bien — se cae al
 * boton que manda al login, igual que cuando la cuenta ya existia. Por eso el
 * `try`.
 *
 * `emisor_tabla = 'usuarios'` porque `invitaciones.emisor` es un id de esa tabla.
 * Los enlaces de cloud dicen `'controladores'` desde el 07/09/2026 — para eso
 * existe la columna.
 */
function enlaceDeAccesoApp(PDO $pdo, int $usuarioId, int $emisorId, bool $nueva): string
{
    if (!$nueva || $usuarioId <= 0) {
        return '';
    }

    // 32 bytes de CSPRNG en base64url. En la base se guarda el SHA-256, nunca el
    // token: quien lea `enlaces_acceso` no puede armar un enlace valido. Mismo
    // criterio que cloud/api/enlaces_acceso.php y que `recuperaciones`.
    $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

    try {
        $pdo->prepare(
            'INSERT INTO enlaces_acceso
                (usuario, destino, token, emisor, emisor_tabla, emitido, expira, origen)
             VALUES
                (:u, :dst, :t, :em, :et, NOW(), DATE_ADD(NOW(), INTERVAL :min MINUTE), :ip)'
        )->execute([
            ':u'   => $usuarioId,
            ':dst' => 'app',
            ':t'   => hash('sha256', $token),
            ':em'  => $emisorId ?: null,
            ':et'  => 'usuarios',
            ':min' => ENLACE_APP_MINUTOS,
            // `REMOTE_ADDR` y no `X-Forwarded-For`: ese header lo pone el cliente.
            ':ip'  => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        ]);
    } catch (Throwable $_) {
        return '';
    }

    return panelAppBaseUrl() . '/acceso?t=' . rawurlencode($token);
}

/**
 * Perfil de OPERADOR del usuario en el dominio, creandolo si no tenia ninguno.
 *
 * ESTA INVITACION DEJO DE DAR DE ALTA ADMINISTRADORES el 07/09/2026. Hasta esa
 * fecha creaba `tipo = 'A'` y buscaba un perfil de Administrador para reutilizar,
 * con este argumento: el gate del panel es `perfiles`.`tipo` = 'A'
 * (lib/acceso.php), asi que una invitacion emitida desde el BackOffice solo
 * podia significar alta administrativa. Se cambio por decision explicita: **una
 * invitacion da de alta un OPERADOR siempre, se emita donde se emita**, y ahora
 * las dos —esta y la de `app`— hacen exactamente lo mismo.
 *
 * CONSECUENCIA QUE HAY QUE TENER PRESENTE: quien acepta esta invitacion **no
 * entra al BackOffice**. Recibe sus credenciales, opera la app y, si intenta
 * entrar a panel.reactor.com.ar, el login lo rebota con `motivo=perfil`. Ya no
 * queda ningun camino que fabrique un Administrador sin que alguien lo decida a
 * mano: se otorga desde el alta de `cloud` (que elige el `tipo`) o editando el
 * perfil desde Usuarios. **Si un dominio se queda sin ningun Administrador, el
 * unico desbloqueo es `cloud`** — el mismo patron que ya rige para los tres
 * permisos del perfil.
 *
 * POR ESO TAMBIEN CAMBIO LA BUSQUEDA: antes pedia `tipo = 'A'` y hoy se queda
 * con CUALQUIER perfil habilitado del dominio, igual que `appPerfilAsegurado()`
 * en app/lib/perfiles.php. No es un detalle suelto: si siguiera buscando un 'A'
 * mientras crea un 'O', invitar a alguien que ya tiene su perfil de Operador ahi
 * le agregaria un segundo Operador identico en cada invitacion. Lo que la
 * invitacion promete es acceso al dominio, y esa persona ya lo tiene.
 *
 * Y NO SE REESCRIBE EL PERFIL QUE YA ESTA, cualquiera sea su `tipo`: si la
 * persona ya era Administradora ahi, bajarla a Operadora le sacaria el BackOffice
 * —y el back office viejo, que lee la misma columna— desde una pantalla que nadie
 * abrio para eso. Una cuenta con varios perfiles en el mismo dominio es normal en
 * estos datos, pero duplicarlos por sistema no.
 *
 * `panel` NACE SEMBRADO con el panel de id mas baja del dominio, no en NULL —
 * ver `panelInicialDelDominio()`. Y nunca en 0: el legacy (`cPerfil::nuevo()`)
 * escribe ese centinela, que con las FK declaradas ya no es un valor valido
 * (ver db/schema.sql).
 *
 * `$registranteId` es el EMISOR de la invitacion, y va a `perfiles`.`registrante`
 * (migracion 20260907_1100): quien otorgo este acceso. Solo se escribe cuando el
 * perfil se CREA — si se reutiliza uno que ya estaba, ese acceso lo otorgo otro
 * y no hay nada que anotar.
 */
function perfilAsegurado(PDO $pdo, int $usuarioId, int $dominioId, string $dominioNombre, int $registranteId = 0): int
{
    // CUALQUIER perfil habilitado del dominio, sin filtrar por `tipo`. Ver la
    // cabecera: filtrar por 'A' mientras se crea un 'O' duplicaria el perfil de
    // todo Operador que ya estuviera en el dominio.
    $busca = $pdo->prepare(
        'SELECT id FROM perfiles
          WHERE usuario = :u AND dominio = :d
            AND habilitado = :hab
          ORDER BY id LIMIT 1'
    );
    $busca->execute([
        ':u'   => $usuarioId,
        ':d'   => $dominioId,
        ':hab' => HABILITADO,
    ]);
    $id = (int) ($busca->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $alta = $pdo->prepare(
        'INSERT INTO perfiles (uuid, nombre, usuario, dominio, tipo,
                               operacion, invitacion, facturacion, panel,
                               registrante, habilitado)
         VALUES (:uuid, :nombre, :usuario, :dominio, :tipo,
                 :operacion, :invitacion, :facturacion, :panel,
                 :registrante, :habilitado)'
    );
    $alta->execute([
        ':uuid'       => bin2hex(random_bytes(8)),
        ':nombre'     => mb_substr('Operador en ' . $dominioNombre, 0, 255),
        ':usuario'    => $usuarioId,
        ':dominio'    => $dominioId,
        // `perfiles`.`tipo` es ENUM('A','O') NOT NULL: dos letras y nada mas.
        // OPERADOR, no Administrador (07/09/2026, ver la cabecera): una
        // invitacion da de alta un Operador se emita donde se emita. Como esta
        // columna la lee el sistema legacy —que SI reparte permisos con ella—,
        // escribir 'A' aca ademas le abriria al invitado el back office viejo
        // entero sin que nadie lo decidiera. Es el mismo argumento que ya valia
        // para la invitacion de `app`; ahora vale para las dos.
        ':tipo'       => PERFIL_TIPO_OPERADOR,
        // PERMISOS DEL PERFIL NUEVO: opera la app e invita, no factura.
        //
        // Los dos primeros son el mismo criterio con el que la migracion
        // 20260907_1000 sembro las filas que ya existian, y el mismo con el que
        // el alta de cloud pre-tilda los switches: un perfil que naciera sin
        // ellos seria un perfil que no puede usar la app.
        //
        // `facturacion` EN 0, Y NO ES UN DESCUIDO. Este alta corre SIN sesion
        // —la credencial es el uuid del enlace, no un perfil—, asi que no hay
        // contra quien chequear la regla de "nadie otorga lo que no tiene"
        // (api/usuarios.php). Si naciera en 1, cualquier administrador sin
        // facturacion tendria el camino servido: invita a una cuenta que
        // controle, la acepta, y ya hay un perfil con facturacion en el dominio.
        // El corte del PUT quedaria en decoracion. Se lo da despues un perfil
        // que si lo tenga.
        //
        // PENDIENTE: desde el 07/09/2026 esa regla vale para LOS TRES permisos,
        // asi que `operacion` e `invitacion` en 1 son el mismo agujero — un
        // administrador sin `operacion` puede fabricarse un perfil que la tenga
        // invitando a una cuenta propia. Cerrarlo pide guardar los permisos del
        // EMISOR en la fila de `invitaciones` (columna nueva: hay que proponer el
        // cambio de esquema antes), porque aca ya no hay sesion de la cual
        // leerlos. Se dejan en 1 mientras tanto: bajarlos a 0 romperia el alta
        // —el perfil no podria usar la app— que es peor que el agujero.
        ':operacion'   => HABILITADO,
        ':invitacion'  => HABILITADO,
        ':facturacion' => DESHABILITADO,
        // Panel con el que abre la app la primera vez. Ver la funcion de abajo.
        ':panel'       => panelInicialDelDominio($pdo, $dominioId),
        // QUIEN OTORGO ESTE ACCESO: el emisor de la invitacion. `?: null` y no
        // el entero pelado porque la columna es una FK y el `0` del sistema
        // historico ya no es un valor valido — mismo criterio con el que
        // `usuarioAlta()` escribe `usuarios`.`registrante`. Una invitacion
        // vieja sin emisor deja el perfil con NULL, que se lee como "no se
        // sabe" (ver 20260907_1100).
        ':registrante' => $registranteId ?: null,
        // El perfil nace habilitado. La columna es tinyint(1) NOT NULL y su
        // unico otro valor es 0 (lib/habilitado.php).
        ':habilitado' => HABILITADO,
    ]);

    $perfilId = (int) $pdo->lastInsertId();

    // PANELES: el perfil nace con acceso a TODOS los paneles habilitados de su
    // dominio. No es un extra — es obligatorio. `perfiles_paneles` no tiene
    // fallback: un perfil sin filas no ve NINGUN panel en `app`
    // (appPanelesPermitidos() en app/lib/contexto.php), asi que sin esto la
    // persona aceptaria la invitacion, recibiria sus credenciales y entraria a
    // una app vacia. Es la misma regla con la que la migracion
    // `20260906_1700_sembrar_perfiles_paneles.sql` sembro los 2225 perfiles que
    // ya existian, y el mismo default que pre-tilda el alta de cloud.
    //
    // El INSERT ... SELECT con `dominio = :d` en las dos puntas es lo que impide
    // que se cuele un panel de otro dominio.
    $pdo->prepare(
        'INSERT IGNORE INTO perfiles_paneles (perfil, panel, asignado)
         SELECT :perfil, pa.id, NOW()
           FROM paneles pa
          WHERE pa.dominio = :d AND pa.habilitado = 1'
    )->execute([':perfil' => $perfilId, ':d' => $dominioId]);

    return $perfilId;
}

/**
 * Panel con el que nace el perfil: el de **id mas baja** entre los habilitados
 * del dominio. NULL si el dominio no tiene ninguno.
 *
 * `perfiles`.`panel` (singular, no confundir con `perfiles_paneles`) es la
 * MEMORIA del ultimo panel abierto: la escribe `app` en cada cambio y es lo que
 * `app` reabre al conectarse. Hasta el 07/09/2026 el perfil nacia con NULL y
 * `app` caia sola al primer panel permitido; sembrarla deja el dato escrito
 * desde el alta.
 *
 * **EL FILTRO ES EL MISMO QUE EL DEL `INSERT ... SELECT` de arriba**
 * (`dominio = :d AND habilitado = 1`), y eso no es casualidad: es lo que
 * garantiza que el panel sembrado este SIEMPRE entre los que el perfil tiene
 * permitidos en `perfiles_paneles`. Es la invariante que `app` mantiene y la que
 * despues protege `olvidarPanelSinPermiso()` en api/usuarios.php. Si se cambia
 * uno de los dos criterios hay que cambiar el otro, o el perfil nace recordando
 * un panel que no puede abrir.
 *
 * NULL SI EL DOMINIO NO TIENE PANELES HABILITADOS, y no un 0 de relleno: la
 * columna es FK contra `paneles` (`fk_perfiles_panel`, RESTRICT). Ese perfil
 * tampoco va a recibir filas en `perfiles_paneles`, asi que el NULL es el
 * sintoma correcto de un dominio sin paneles, no la causa.
 *
 * OJO CON EL DESEMPATE: `app` ordena sus paneles POR NOMBRE
 * (`appPanelesDelDominio()` en app/lib/contexto.php) y cuando `panel` viene
 * vacio cae al primero de ESA lista. O sea que el panel de id mas baja no tiene
 * por que ser el mismo que abriria la app por su cuenta. Se siembra por id
 * porque es el criterio pedido: estable, no depende de que alguien renombre un
 * panel.
 *
 * Es copia de `appPanelInicialDelDominio()` de app/lib/perfiles.php, como todo
 * lo que comparten las tres apps sin compartir docroot.
 */
function panelInicialDelDominio(PDO $pdo, int $dominioId): ?int
{
    $stmt = $pdo->prepare(
        'SELECT MIN(id) FROM paneles WHERE dominio = :d AND habilitado = 1'
    );
    $stmt->execute([':d' => $dominioId]);

    // MIN() sobre cero filas devuelve NULL, no 0: el `?: 0` normaliza las dos
    // formas antes de decidir.
    $id = (int) ($stmt->fetchColumn() ?: 0);

    return $id > 0 ? $id : null;
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

/** @param array{usuario:string,contrasena:?string,nueva:bool,correo_ok:bool,enlace_app:string} $r */
function pantallaBienvenida(array $r, string $dominioNombre): string
{
    // `Ingresar` VA A LA APP, NO AL PANEL. Desde el 07/09/2026 esta invitacion da
    // de alta un Operador (ver la cabecera), y un Operador no entra al BackOffice:
    // el boton viejo —`panelBaseUrl() . '/login.php'`— lo mandaba a un login que lo
    // iba a rebotar con `motivo=perfil` sin explicarle por que.
    //
    // Con enlace se entra con la sesion ya abierta; sin el, al login de la app
    // para que tipee SU contrasena. Los dos casos van al mismo dominio.
    $destino = $r['enlace_app'] !== ''
        ? $r['enlace_app']
        : panelAppBaseUrl() . '/sesion/iniciar';

    $ingresar = '<div class="inv-acciones" style="margin-top:4px">
            <a class="btn btn-primary" href="' . e($destino) . '">
                <i class="fa-solid fa-right-to-bracket"></i> Ingresar
            </a>
        </div>';

    if (!$r['nueva']) {
        return '<div class="inv-note inv-note-ok">Ya tenés acceso a <strong>'
             . e($dominioNombre) . '</strong>.</div>'
             . '<p class="inv-lead">Ingresá con las credenciales que ya usabas: el dominio nuevo '
             . 'te va a aparecer en <strong>Cambiar dominio</strong>.</p>'
             . $ingresar;
    }

    $aviso = $r['correo_ok']
        ? '<p class="inv-lead">Te mandamos estos datos por correo a <strong>' . e($r['usuario']) . '</strong>.</p>'
        : '<div class="inv-note inv-note-bad">No pudimos enviarte el correo con los datos de acceso. '
          . 'Anotálos antes de cerrar esta página.</div>';

    return '<div class="inv-note inv-note-ok">Tu cuenta en <strong>' . e($dominioNombre)
         . '</strong> ya está activa.</div>'
         . '<div class="inv-datos">'
         . invitacionDato('Usuario', $r['usuario'])
         . invitacionDato('Contraseña', (string) $r['contrasena'])
         . '</div>'
         . $aviso
         . '<p class="inv-lead">Te recomendamos cambiarla después del primer ingreso.</p>'
         . $ingresar;
}
