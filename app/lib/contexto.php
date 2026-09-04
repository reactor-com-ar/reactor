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

/**
 * @return array{perfil:int, dominio:int, nombre:string, panel:int, rol:string}
 *         Todo en cero/vacío si el usuario no tiene ningún perfil habilitado.
 *
 * `panel` es el último panel abierto de ese perfil (`perfiles.panel`); puede
 * venir en cero si el perfil todavía no abrió ninguno.
 *
 * `rol` es el nombre del rol del perfil ("Administrador", "Operador",
 * "Técnico"...), que sale de `perfiles.rol` -> `roles.nombre`. Ver
 * `appRolDelPerfil()` por el fallback cuando `rol` viene vacío.
 */
function appDominioActivo(array $usuario): array
{
    $vacio = ['perfil' => 0, 'dominio' => 0, 'nombre' => '', 'panel' => 0, 'rol' => ''];

    $sel = 'SELECT p.id AS perfil, p.dominio, p.panel, p.tipo,
                   d.nombre,
                   r.nombre AS rol
            FROM perfiles p
            LEFT JOIN dominios d ON d.id = p.dominio
            LEFT JOIN roles    r ON r.id = p.rol';

    // 1) El perfil recordado en `usuarios.perfil`.
    $perfil = (int) ($usuario['perfil'] ?? 0);
    if ($perfil > 0) {
        $stmt = db()->prepare($sel . ' WHERE p.id = :p AND p.habilitado = \'1\' LIMIT 1');
        $stmt->execute([':p' => $perfil]);
        $row = $stmt->fetch();
        if ($row) {
            return appContextoDesdeFila($row);
        }
    }

    // 2) Primer perfil habilitado del usuario (mismo orden que el legacy).
    $stmt = db()->prepare(
        $sel . ' WHERE p.usuario = :u AND p.habilitado = \'1\' ORDER BY p.nombre, p.id LIMIT 1'
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
        'perfil'  => (int) $row['perfil'],
        'dominio' => (int) $row['dominio'],
        'nombre'  => (string) ($row['nombre'] ?? ''),
        'panel'   => (int) ($row['panel'] ?? 0),
        'rol'     => appRolDelPerfil($row),
    ];
}

/**
 * Nombre del rol del perfil, para mostrar.
 *
 * La fuente buena es `perfiles.rol` -> `roles.nombre` (1589 perfiles Operador,
 * 469 Administrador, y algunos Técnico / Contador / etc.). Pero 145 perfiles
 * tienen `rol` en NULL, así que se cae a `perfiles.tipo`, que codifica lo mismo
 * en una letra ('A' = 444 filas, 'O' = 1761). Si tampoco hay tipo, queda vacío
 * y la vista muestra un guión.
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
function appPanelesDelDominio(int $dominio, int $panelRecordado): array
{
    $vacio = ['activo' => 0, 'nombre' => '', 'paneles' => []];
    if ($dominio <= 0) {
        return $vacio;
    }

    $stmt = db()->prepare(
        'SELECT id, nombre
         FROM paneles
         WHERE dominio = :d AND habilitado = \'1\'
         ORDER BY nombre'
    );
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
 *               panelNombre:string, rol:string, origen:string}
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
    $paneles   = appPanelesDelDominio($ctx['dominio'], $recordado);

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
                d.nombre,
                r.nombre AS rol
         FROM perfiles p
         LEFT JOIN dominios d ON d.id = p.dominio
         LEFT JOIN roles    r ON r.id = p.rol
         WHERE p.id = :p AND p.usuario = :u AND p.habilitado = \'1\'
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
    $pan = appPanelesDelDominio($ctx['dominio'], $ctx['panel']);

    return [
        'per' => $ctx['perfil'],
        'dom' => $ctx['dominio'],
        'pan' => $pan['activo'],
    ];
}
