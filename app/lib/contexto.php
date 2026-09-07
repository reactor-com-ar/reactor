<?php

declare(strict_types=1);

/**
 * Contexto de la sesión: en qué dominio está parado el usuario.
 *
 * El dominio NO sale de `usuarios.dominio` sino del PERFIL activo:
 * `usuarios.perfil` -> `perfiles.dominio`. Es la misma cadena que armaba
 * `cAcceso::controlar()` en el legacy para escribir `sesionDominio`, y la que
 * cambia el modal "Cambiar de Dominio" (que en realidad cambia de perfil).
 *
 * Si el usuario no tiene perfil recordado — o el recordado quedó
 * deshabilitado — se cae al primero habilitado, igual que hacía
 * `cPerfil::usuario2id()`.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/habilitado.php';
// Los tres permisos del perfil. De la app gatean dos: `operacion` (los paneles
// de control) e `invitacion` (el item "Invitar un Usuario"). `facturacion` es
// del panel y viaja igual — el catalogo es uno solo para las tres apps.
require_once __DIR__ . '/permisos.php';

/**
 * @return array{perfil:int, dominio:int, nombre:string, panel:int, rol:string,
 *               permisos:array{operacion:bool, invitacion:bool, facturacion:bool}}
 *         Todo en cero/vacío si el usuario no tiene ningún perfil habilitado.
 *
 * `panel` es el último panel abierto de ese perfil (`perfiles.panel`); puede
 * venir en cero si el perfil todavía no abrió ninguno.
 *
 * `rol` es la etiqueta del perfil ("Administrador" / "Operador"), derivada de
 * `perfiles.tipo`. Ver `appRolDelPerfil()`.
 *
 * `permisos` son las tres banderas de `perfiles`. **Sin perfil van las tres en
 * `false`**, no en `true`: una cuenta que no está parada en ningún dominio no
 * tiene permisos, no los tiene todos.
 */
function appDominioActivo(array $usuario): array
{
    $vacio = [
        'perfil'    => 0,
        'dominio'   => 0,
        'nombre'    => '',
        'panel'     => 0,
        'rol'       => '',
        'situacion' => '',
        'permisos'  => perfilSinPermisos(),
    ];

    $sel = 'SELECT p.id AS perfil, p.dominio, p.panel, p.tipo,
                   p.operacion, p.invitacion, p.facturacion,
                   d.nombre, d.situacion,
                   NULL AS rol
            FROM perfiles p
            LEFT JOIN dominios d ON d.id = p.dominio';

    // 1) El perfil recordado en `usuarios.perfil`.
    $perfil = (int) ($usuario['perfil'] ?? 0);
    if ($perfil > 0) {
        $stmt = db()->prepare($sel . ' WHERE p.id = :p AND p.habilitado = 1 LIMIT 1');
        $stmt->execute([':p' => $perfil]);
        $row = $stmt->fetch();
        if ($row) {
            return appContextoDesdeFila($row);
        }
    }

    // 2) Primer perfil habilitado del usuario (mismo orden que el legacy).
    $stmt = db()->prepare(
        $sel . ' WHERE p.usuario = :u AND p.habilitado = 1 ORDER BY p.nombre, p.id LIMIT 1'
    );
    $stmt->execute([':u' => (int) $usuario['id']]);
    $row = $stmt->fetch();
    if (!$row) {
        return $vacio;
    }

    return appContextoDesdeFila($row);
}

/** Arma el contexto a partir de una fila del SELECT de `appDominioActivo()`. */
function appContextoDesdeFila(array $row): array
{
    return [
        'perfil'    => (int) $row['perfil'],
        'dominio'   => (int) $row['dominio'],
        'nombre'    => (string) ($row['nombre'] ?? ''),
        'panel'     => (int) ($row['panel'] ?? 0),
        'rol'       => appRolDelPerfil($row),
        'situacion' => (string) ($row['situacion'] ?? ''),
        'permisos'  => perfilPermisos($row),
    ];
}

/**
 * ¿El contexto de sesión tiene ese permiso?
 *
 * Es la ÚNICA lectura de `$ctx['permisos']` en toda la app: si mañana el
 * permiso deja de ser una columna del perfil, se cambia acá y no en las cinco
 * pantallas que preguntan.
 *
 * FALLA CERRADO. Un contexto sin la clave —una sesión sin perfil, o un array
 * armado antes de que existieran los permisos— devuelve `false`.
 */
function appPuede(array $ctx, string $permiso): bool
{
    return ($ctx['permisos'][$permiso] ?? false) === true;
}

/**
 * Etiqueta del perfil, para mostrar.
 *
 * SALE DE `perfiles`.`tipo`, que es lo único que queda. Hasta el 06/09/2026 la
 * fuente buena era `perfiles.rol` -> `roles.nombre` y `tipo` era sólo el
 * fallback para los 145 perfiles con `rol` en NULL; al eliminarse la columna
 * (`20260906_1500_perfiles_sin_rol.sql`) el fallback pasó a ser el camino único.
 *
 * Se pierde granularidad y hay que saberlo: los perfiles que eran "Técnico",
 * "Contador" o "Director Comercial" ahora se muestran como Administrador u
 * Operador según su letra. Son ~20 filas y esta pantalla sólo lo usa como
 * rótulo, no para decidir nada.
 *
 * `tipo` es `ENUM('A','O') NOT NULL DEFAULT 'O'` desde
 * 20260905_2300_perfiles_tipo_a_o.sql, así que siempre da un nombre. El
 * `default` queda porque `match` sin él lanza `UnhandledMatchError`.
 */
function appRolDelPerfil(array $row): string
{
    $rol = trim((string) ($row['rol'] ?? ''));
    if ($rol !== '') {
        return $rol;
    }

    return match (strtoupper(trim((string) ($row['tipo'] ?? '')))) {
        'A'     => 'Administrador',
        'O'     => 'Operador',
        default => '',
    };
}

/**
 * Contadores denormalizados del dominio, tal como los guarda `dominios`.
 *
 * Son columnas, no `COUNT(*)`: las mantiene el resto del sistema al dar de alta
 * usuarios / dispositivos / chips. Es lo mismo que leía `dominio/detalles.php`.
 *
 * @return array{usuarios:int, dispositivos:int, chips:int}
 */
function appDominioContadores(int $dominio): array
{
    $vacio = ['usuarios' => 0, 'dispositivos' => 0, 'chips' => 0];
    if ($dominio <= 0) {
        return $vacio;
    }

    $stmt = db()->prepare(
        'SELECT usuarios, dispositivos, chips FROM dominios WHERE id = :d LIMIT 1'
    );
    $stmt->execute([':d' => $dominio]);
    $row = $stmt->fetch();
    if (!$row) {
        return $vacio;
    }

    return [
        'usuarios'     => (int) ($row['usuarios']     ?? 0),
        'dispositivos' => (int) ($row['dispositivos'] ?? 0),
        'chips'        => (int) ($row['chips']        ?? 0),
    ];
}

/**
 * Nombres de los administradores del dominio, para mostrar.
 *
 * ES `perfiles`.`tipo` = 'A', la MISMA fuente con la que `appRolDelPerfil()`
 * rotula "Administrador" el perfil propio. Tiene que serlo: el modal muestra las
 * dos cosas una debajo de la otra, y si cada una saliera de un criterio distinto
 * alguien podría verse como Administrador y no figurar en la lista de al lado.
 *
 * ES UN ROTULO, NO UN PERMISO — y por eso mirar `tipo` acá está bien. Lo que
 * gatea pantallas son `operacion` / `invitacion` / `facturacion` (ver
 * lib/permisos.php), que no se derivan de `tipo` ni al revés.
 *
 * DOBLE `habilitado`: el del perfil y el del usuario. El del perfil es el que
 * dice si esa persona sigue siendo administradora ACA (un perfil deshabilitado
 * ya no es de este dominio); el del usuario, si la cuenta puede entrar. Listar a
 * alguien deshabilitado sería mandar a golpearle la puerta a quien no puede
 * abrir.
 *
 * `DISTINCT` porque una misma persona puede tener más de un perfil administrador
 * en el mismo dominio: se la nombra una vez.
 *
 * @return list<string> Vacío si el dominio no tiene ninguno (o no hay dominio).
 */
function appDominioAdministradores(int $dominio): array
{
    if ($dominio <= 0) {
        return [];
    }

    $stmt = db()->prepare(
        "SELECT DISTINCT u.nombre
           FROM perfiles p
           INNER JOIN usuarios u ON u.id = p.usuario
          WHERE p.dominio = :d
            AND p.tipo = 'A'
            AND p.habilitado = 1
            AND u.habilitado = 1
            AND u.nombre IS NOT NULL
            AND TRIM(u.nombre) <> ''
          ORDER BY u.nombre"
    );
    $stmt->execute([':d' => $dominio]);

    $nombres = [];
    foreach ($stmt->fetchAll() as $row) {
        $nombres[] = trim((string) $row['nombre']);
    }

    return $nombres;
}

/**
 * Paneles habilitados de un dominio, y cuál de ellos está abierto.
 *
 * `perfiles.panel` guarda el último panel que usó ESE perfil. Si viene vacío
 * —o quedó deshabilitado, o es de otro dominio— el activo pasa a ser el
 * primero de la lista, que es el que abriría `cPanel::perfil2id()`.
 *
 * Vive acá y no en `api/paneles.php` porque el nombre del panel activo también
 * lo necesita la franja del encabezado en `index.php`: si la regla del
 * "recordado o el primero" estuviera duplicada, las dos vistas podrían
 * discrepar sobre cuál es el panel abierto.
 *
 * @return array{activo:int, nombre:string, paneles:list<array{id:int, nombre:string, actual:bool}>}
 */
/**
 * Ids de panel a los que ese perfil tiene permiso, segun `perfiles_paneles`.
 *
 * **SIN FILAS SIGNIFICA NINGUN PANEL.** El permiso es explicito: si el perfil
 * no tiene una fila para ese panel, no lo ve. No hay fallback a "todos".
 *
 * Hubo un fallback y duro poco. Cuando la tabla se creo vacia
 * (`20260906_1600`), "sin filas" significaba "todos los del dominio" — era lo
 * unico que no le sacaba los paneles a los 2227 perfiles de golpe. La siembra
 * de `20260906_1700` volvio ese permiso explicito para los 2225 que tenian
 * paneles, y con eso el fallback dejo de hacer falta: ahora destildar la lista
 * entera significa lo que parece.
 *
 * QUIEN CREA PERFILES TIENE QUE ASIGNARLES PANELES. Un perfil nuevo sin filas
 * no ve nada. Los dos caminos que los crean ya lo hacen: el alta de cloud
 * pre-tilda todos los del dominio, y `panel/invitacion/aceptar.php` los inserta
 * al crear el perfil.
 *
 * @return list<int>
 */
function appPanelesPermitidos(int $perfil): array
{
    if ($perfil <= 0) {
        return [];
    }

    $stmt = db()->prepare('SELECT panel FROM perfiles_paneles WHERE perfil = :p');
    $stmt->execute([':p' => $perfil]);

    return array_map('intval', array_column($stmt->fetchAll(), 'panel'));
}

function appPanelesDelDominio(int $dominio, int $panelRecordado, int $perfil = 0): array
{
    $vacio = ['activo' => 0, 'nombre' => '', 'paneles' => []];
    if ($dominio <= 0) {
        return $vacio;
    }

    // Permiso por perfil: la lista SIEMPRE se acota a lo que diga
    // `perfiles_paneles`. Sin filas no hay paneles, asi que se corta antes de
    // consultar — un `IN ()` vacio ni siquiera es SQL valido.
    $permitidos = appPanelesPermitidos($perfil);
    if ($permitidos === []) {
        return $vacio;
    }

    $sql = 'SELECT id, nombre
              FROM paneles
             WHERE dominio = :d AND habilitado = 1
               AND id IN (' . implode(',', $permitidos) . ')
             ORDER BY nombre';

    $stmt = db()->prepare($sql);
    $stmt->execute([':d' => $dominio]);
    $filas = $stmt->fetchAll();
    if (!$filas) {
        return $vacio;
    }

    $ids    = array_map(static fn (array $r): int => (int) $r['id'], $filas);
    $activo = in_array($panelRecordado, $ids, true) ? $panelRecordado : $ids[0];

    $nombre  = '';
    $paneles = [];
    foreach ($filas as $r) {
        $id  = (int) $r['id'];
        $nom = trim((string) ($r['nombre'] ?? ''));
        $nom = $nom !== '' ? $nom : '(sin nombre)';
        if ($id === $activo) {
            $nombre = $nom;
        }
        $paneles[] = ['id' => $id, 'nombre' => $nom, 'actual' => $id === $activo];
    }

    return ['activo' => $activo, 'nombre' => $nombre, 'paneles' => $paneles];
}

// -----------------------------------------------------------------------
// Contexto de sesión (el `$_SESSION` del legacy)
// -----------------------------------------------------------------------

/**
 * Alcance de la sesión, resuelto con precedencia TOKEN -> BASE.
 *
 * Es el port de lo que `cAcceso::controlar()` dejaba en `$_SESSION`:
 * `sesionPerfil`, `sesionDominio` y `sesionPanel`. Allá la sesión PHP hace de
 * caché — se hidrata desde la base la primera vez (`if ($sesionUsuario == '')`)
 * y después se lee de memoria. Acá el token cumple ese papel, porque no hay
 * sesión de servidor donde guardar nada.
 *
 * POR QUÉ EL TOKEN NO GANA A CIEGAS. El legacy revalida lo que tiene en sesión
 * (`$zPerfil->verificar()`, `$zPanel->verificar()`) justamente porque el dato
 * cacheado puede haber quedado viejo: el perfil se deshabilita, el panel se
 * borra o se muda de dominio. Acá pasa lo mismo, con el agravante de que el
 * token dura un año. Así que el claim entra como PREFERENCIA y la validación
 * la hacen las mismas funciones que ya resolvían todo:
 *
 *   - `per`: tiene que seguir siendo un perfil habilitado DEL USUARIO. Si no,
 *     se cae a `usuarios.perfil` y de ahí al primero habilitado.
 *   - `pan`: tiene que seguir siendo un panel habilitado DEL DOMINIO. Si no,
 *     se cae a `perfiles.panel` y de ahí al primero de la lista.
 *
 * `origen` dice de dónde salió cada cosa ('token' o 'db'); lo muestra el modal
 * Entorno, que es el equivalente del `print_r($_SESSION)` del legacy.
 *
 * @return array{perfil:int, dominio:int, nombre:string, panel:int,
 *               panelNombre:string, rol:string, situacion:string,
 *               permisos:array{operacion:bool, invitacion:bool, facturacion:bool},
 *               origen:string}
 */
function appContextoSesion(array $usuario): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $payload = function_exists('appTokenPayload') ? appTokenPayload() : null;
    $perToken = (int) ($payload['per'] ?? 0);
    $panToken = (int) ($payload['pan'] ?? 0);

    $origen = 'db';

    // --- Perfil (y con él, dominio) ---
    $ctx = null;
    if ($perToken > 0) {
        $ctx = appPerfilHabilitado($perToken, (int) $usuario['id']);
        if ($ctx !== null) {
            $origen = 'token';
        }
    }
    if ($ctx === null) {
        $ctx = appDominioActivo($usuario);
    }

    // --- Panel: el del token si sigue siendo válido, si no el de la base ---
    $recordado = $panToken > 0 ? $panToken : $ctx['panel'];
    $paneles   = appPanelesDelDominio($ctx['dominio'], $recordado, (int) $ctx['perfil']);

    if ($panToken > 0 && $paneles['activo'] !== $panToken) {
        // El panel del token ya no existe / no es de este dominio: el alcance
        // que se está usando NO es el que dice el token.
        $origen = 'db';
    }

    $cache = [
        'perfil'      => $ctx['perfil'],
        'dominio'     => $ctx['dominio'],
        'nombre'      => $ctx['nombre'],
        'panel'       => $paneles['activo'],
        'panelNombre' => $paneles['nombre'],
        'rol'         => $ctx['rol'],
        'situacion'   => $ctx['situacion'],
        // Los permisos salen SIEMPRE de la fila del perfil que se acaba de
        // resolver contra la base, nunca de un claim del token: el JWT de esta
        // app dura un año (ver arriba), así que un permiso revocado tiene que
        // dejar de valer en el request siguiente y no al vencer la sesión.
        'permisos'    => $ctx['permisos'] ?? perfilSinPermisos(),
        'origen'      => $origen,
    ];

    return $cache;
}

/**
 * Un perfil por id, sólo si está habilitado y es DEL usuario dado.
 *
 * El filtro por `usuario` es el control de acceso del claim: sin él, un token
 * con un `per` de otra cuenta movería la sesión al dominio de esa cuenta.
 *
 * @return array{perfil:int, dominio:int, nombre:string, panel:int, rol:string}|null
 */
function appPerfilHabilitado(int $perfil, int $usuario): ?array
{
    if ($perfil <= 0 || $usuario <= 0) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT p.id AS perfil, p.dominio, p.panel, p.tipo,
                p.operacion, p.invitacion, p.facturacion,
                d.nombre, d.situacion,
                NULL AS rol
         FROM perfiles p
         LEFT JOIN dominios d ON d.id = p.dominio
         WHERE p.id = :p AND p.usuario = :u AND p.habilitado = 1
         LIMIT 1'
    );
    $stmt->execute([':p' => $perfil, ':u' => $usuario]);
    $row = $stmt->fetch();

    return $row ? appContextoDesdeFila($row) : null;
}

/**
 * Los claims de alcance con los que se firma el token.
 *
 * @return array{per:int, dom:int, pan:int}
 */
function appClaimsDeSesion(array $usuario): array
{
    $ctx = appDominioActivo($usuario);
    $pan = appPanelesDelDominio($ctx['dominio'], $ctx['panel'], (int) $ctx['perfil']);

    return [
        'per' => $ctx['perfil'],
        'dom' => $ctx['dominio'],
        'pan' => $pan['activo'],
    ];
}
