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
 * DOS CAMINOS, como en el legacy:
 *   - La persona ya tiene cuenta (el correo o el usuario ya existen): no se
 *     crea nada ni se le cambia la contrasena, solo se le da el perfil en
 *     este dominio y entra con las credenciales que ya usaba.
 *   - No tiene cuenta: se crea `usuarios` + `perfiles` y se le entrega una
 *     contrasena generada.
 *
 * EL PERFIL QUE SE OTORGA ES DE ADMINISTRADOR, no de Operador como en el legacy:
 * la invitacion se emite desde el panel y enlaza al panel, y al panel solo entra
 * un administrador. Ver perfilAsegurado() al pie.
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
            <input type="tel" id="inv-celular" name="celular" maxlength="15"
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
';

invitacionLayout('Completá tus datos', $cuerpo);

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
            'cuerpo'       => invitacionCuerpoCredenciales($dominioNombre, $correo, $contrasena, panelBaseUrl() . '/login.php'),
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
    ];
}

/**
 * Perfil de ADMINISTRADOR del usuario en el dominio, creandolo si no lo tenia.
 *
 * SE BUSCA UN PERFIL DE ADMINISTRADOR, NO "cualquier perfil del dominio". Si la
 * persona ya tenia uno de Operador ahi (porque usa reactor-app), ese NO habilita
 * el panel — el gate es `perfiles`.`tipo` = 'A' (lib/acceso.php) — asi que se le
 * agrega uno nuevo en vez de reescribirle el que ya tiene. Mutar el `tipo` de
 * una fila existente le cambiaria el acceso en el sistema legacy desde aca.
 *
 * Una cuenta con varios perfiles en el mismo dominio es normal en estos datos.
 *
 * `panel` queda en NULL y no en 0 — con las FK declaradas, el 0 del sistema
 * viejo ya no es un valor valido (ver db/schema.sql).
 *
 * `$registranteId` es el EMISOR de la invitacion, y va a `perfiles`.`registrante`
 * (migracion 20260907_1100): quien otorgo este acceso. Solo se escribe cuando el
 * perfil se CREA — si se reutiliza uno que ya estaba, ese acceso lo otorgo otro
 * y no hay nada que anotar.
 */
function perfilAsegurado(PDO $pdo, int $usuarioId, int $dominioId, string $dominioNombre, int $registranteId = 0): int
{
    $busca = $pdo->prepare(
        'SELECT id FROM perfiles
          WHERE usuario = :u AND dominio = :d
            AND habilitado = :hab
            AND tipo = :tipo
          ORDER BY id LIMIT 1'
    );
    $busca->execute([
        ':u'    => $usuarioId,
        ':d'    => $dominioId,
        ':hab'  => HABILITADO,
        ':tipo' => PERFIL_TIPO_ADMINISTRADOR,
    ]);
    $id = (int) ($busca->fetchColumn() ?: 0);
    if ($id > 0) {
        return $id;
    }

    $alta = $pdo->prepare(
        'INSERT INTO perfiles (uuid, nombre, usuario, dominio, tipo,
                               operacion, invitacion, facturacion,
                               registrante, habilitado)
         VALUES (:uuid, :nombre, :usuario, :dominio, :tipo,
                 :operacion, :invitacion, :facturacion,
                 :registrante, :habilitado)'
    );
    $alta->execute([
        ':uuid'       => bin2hex(random_bytes(8)),
        ':nombre'     => mb_substr('Administrador en ' . $dominioNombre, 0, 255),
        ':usuario'    => $usuarioId,
        ':dominio'    => $dominioId,
        // `perfiles`.`tipo` es ENUM('A','O') NOT NULL: dos letras y nada mas.
        // Ya no hay `rol` que acompañar — la columna se eliminó el 06/09/2026 —
        // pero `tipo` se sigue escribiendo en 'A' porque la lee el legacy.
        ':tipo'       => PERFIL_TIPO_ADMINISTRADOR,
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

/** @param array{usuario:string,contrasena:?string,nueva:bool,correo_ok:bool} $r */
function pantallaBienvenida(array $r, string $dominioNombre): string
{
    $ingresar = '<div class="inv-acciones" style="margin-top:4px">
            <a class="btn btn-primary" href="' . e(panelBaseUrl()) . '/login.php">
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
