<?php

declare(strict_types=1);

/**
 * ABM de `perfiles`: el acceso de un usuario a un dominio.
 *
 * YA NO HAY ROL. `perfiles`.`rol` (y `perfiles`.`roles`) se eliminaron el
 * 06/09/2026 con `20260906_1500_perfiles_sin_rol.sql`: los roles pasaron a ser
 * un concepto de cloud (`controladores_roles`, `roles_permisos`) y dejaron de
 * colgar del perfil de un cliente. Lo que queda del perfil es a quien pertenece,
 * en que dominio vale, su `tipo` y si esta habilitado.
 *
 * PANELES DEL PERFIL. `perfiles_paneles` dice a que paneles de su dominio puede
 * entrar el perfil desde `app`. **El permiso es explicito: sin filas, el perfil
 * no ve NINGUN panel.** No hay fallback a "todos" — lo hubo mientras la tabla
 * estaba vacia, y la siembra de `20260906_1700` lo volvio innecesario.
 *
 * POR ESO EL ALTA PRE-TILDA TODOS los paneles del dominio elegido: un perfil que
 * naciera sin filas seria un perfil que no puede usar la app. El otro camino que
 * crea perfiles, `panel/invitacion/aceptar.php`, los inserta por su cuenta.
 *
 * `tipo` NO ES UN ROL, aunque se le parezca. Es `ENUM('A','O')` y la lee el
 * sistema legacy; el ABM la administra porque es una columna de la entidad, no
 * porque reparta permisos. Hoy no gatea nada — ver panel/lib/acceso.php.
 *
 * LOS QUE SI REPARTEN PERMISOS SON LOS TRES DE `lib/permisos.php`, columnas de
 * `perfiles` desde el 07/09/2026: `operacion` (usar los paneles de operacion en
 * `app`), `invitacion` (invitar usuarios desde `app`) y `facturacion` (ver y
 * abonar las facturas en `panel`). Son banderas `habilitado` — tinyint(1) 0/1 —
 * y se leen y escriben con el mismo criterio.
 *
 * CLOUD LOS OTORGA SIN RESTRICCION, a diferencia del panel. Alla `facturacion`
 * solo la puede dar quien ya la tiene (panel/api/usuarios.php), porque el que
 * edita es un cliente administrando su propio dominio y sin ese corte se la
 * daria a si mismo. Aca el que edita es Reactor sobre el sistema entero: si ya
 * puede crear el perfil y elegirle el dominio, pedirle ademas que tenga el
 * permiso que esta repartiendo no protege nada.
 *
 * NOMBRES REALES DE LA TABLA: las FK son `usuario` y `dominio`, no
 * `usuario_id` / `dominio_id`, y no existen `created_at` ni `updated_at`. Se
 * aliasa a los nombres que ya usa el front para no tocar el JS. **El alta
 * estaba rota desde antes de este cambio**: insertaba en `usuario_id` /
 * `dominio_id`, que no existen, asi que cualquier POST moria con un 1054. Se
 * corrigio de paso.
 */

require __DIR__ . '/bootstrap.php';

/** Los dos unicos valores de `perfiles`.`tipo`. */
const PERFIL_TIPOS = ['A' => 'Administrador', 'O' => 'Operador'];

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar perfiles: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $usuarioId = isset($_GET['usuario_id']) ? (int) $_GET['usuario_id'] : 0;

    // LEFT JOIN porque `usuario` y `dominio` admiten NULL (y arrastran el
    // centinela 0 del sistema historico): con INNER esas filas desaparecerian
    // del listado, y una fila que no le sirve a nadie y ademas no se puede ver
    // es una que nadie puede limpiar.
    $sql = 'SELECT p.id,
                   p.usuario    AS usuario_id,
                   p.dominio    AS dominio_id,
                   p.nombre     AS perfil_nombre,
                   p.tipo,
                   p.operacion,
                   p.invitacion,
                   p.facturacion,
                   p.habilitado,
                   u.nombre     AS usuario_nombre,
                   u.correo     AS usuario_email,
                   u.habilitado AS usuario_habilitado,
                   d.nombre     AS dominio_nombre
            FROM perfiles p
            LEFT JOIN usuarios u ON u.id = p.usuario
            LEFT JOIN dominios d ON d.id = p.dominio
            WHERE p.id > 0';
    $params = [];
    if ($usuarioId > 0) {
        $sql .= ' AND p.usuario = :uid';
        $params[':uid'] = $usuarioId;
    }
    $sql .= ' ORDER BY u.nombre ASC, d.nombre ASC';

    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    $panelesPorPerfil = panelesAsignados();

    $perfiles = array_map(static function (array $r) use ($panelesPorPerfil): array {
        $tipo = strtoupper(trim((string) ($r['tipo'] ?? '')));

        return [
            'id'             => (int) $r['id'],
            'usuario_id'     => (int) $r['usuario_id'],
            'dominio_id'     => (int) $r['dominio_id'],
            'perfil_nombre'  => (string) ($r['perfil_nombre'] ?? ''),
            'tipo'           => $tipo,
            'tipo_texto'     => PERFIL_TIPOS[$tipo] ?? '',
            'activo'         => esHabilitado($r['habilitado'] ?? 0),
            // Los tres permisos, ya como booleanos: el front no repite el
            // criterio de lectura de la columna. Van en el LISTADO y no solo en
            // la ficha porque el modulo no tiene GET por id — las dos pantallas
            // que los muestran (Consultar y Editar) trabajan sobre la fila que
            // ya trajo este endpoint.
            'permisos'       => perfilPermisos($r),
            'usuario_nombre' => (string) ($r['usuario_nombre'] ?? ''),
            'usuario_email'  => (string) ($r['usuario_email']  ?? ''),
            'usuario_activo' => esHabilitado($r['usuario_habilitado'] ?? 0),
            'dominio_nombre' => (string) ($r['dominio_nombre'] ?? ''),
            'paneles'        => $panelesPorPerfil[(int) $r['id']] ?? [],
        ];
    }, $stmt->fetchAll());

    $resumen = ['total' => count($perfiles), 'habilitados' => 0, 'administradores' => 0, 'con_paneles' => 0];
    foreach ($perfiles as $p) {
        if ($p['activo'])        $resumen['habilitados']++;
        if ($p['tipo'] === 'A')  $resumen['administradores']++;
        if ($p['paneles'])       $resumen['con_paneles']++;
    }

    json_ok([
        'perfiles' => $perfiles,
        'resumen'  => $resumen,
        'combos'   => ['tipo' => opcionesTipo()],
        // El catalogo entero (162 filas) con su dominio: el selector del modal
        // lo filtra por el dominio del perfil. Mandarlo completo evita un
        // segundo request cada vez que se cambia el dominio en el alta.
        // `array_values` porque `catalogoPaneles()` viene indexado por id y eso
        // serializa como objeto JSON, no como array.
        'catalogos' => ['paneles' => array_values(catalogoPaneles())],
    ]);
}

/** perfil -> [ids de panel]. Una sola consulta indexada para todo el listado. */
function panelesAsignados(): array
{
    $porPerfil = [];
    foreach (db()->query('SELECT perfil, panel FROM perfiles_paneles ORDER BY panel ASC') as $r) {
        $porPerfil[(int) $r['perfil']][] = (int) $r['panel'];
    }

    return $porPerfil;
}

/**
 * Paneles habilitados, con su dominio. Solo los habilitados: dar permiso sobre
 * un panel apagado no significa nada, porque `app` no lo lista igual.
 */
function catalogoPaneles(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $cache = [];
    foreach (db()->query('SELECT id, dominio, nombre FROM paneles WHERE habilitado = 1 ORDER BY nombre ASC, id ASC') as $r) {
        $cache[(int) $r['id']] = [
            'id'      => (int) $r['id'],
            'dominio' => (int) $r['dominio'],
            'nombre'  => (string) ($r['nombre'] ?? ''),
        ];
    }

    return $cache;
}

/**
 * Ids de panel validos para ese dominio. Se exige que el panel EXISTA y que sea
 * DEL MISMO DOMINIO que el perfil: la base no puede expresar esa restriccion con
 * una FK, asi que es el unico lugar donde se verifica. Sin esto, un id a mano en
 * el payload le daria a un perfil acceso al panel de otro cliente.
 */
function validarPaneles(mixed $entrada, int $dominioId): array
{
    if (!is_array($entrada)) return [];

    $catalogo = catalogoPaneles();
    $ids      = [];
    foreach ($entrada as $v) {
        $id = (int) $v;
        if ($id <= 0) continue;
        if (!isset($catalogo[$id])) {
            json_error('El panel #' . $id . ' no existe o esta deshabilitado', 422);
        }
        if ($catalogo[$id]['dominio'] !== $dominioId) {
            json_error('El panel #' . $id . ' es de otro dominio', 422);
        }
        $ids[] = $id;
    }

    return array_values(array_unique($ids));
}

/**
 * Deja `perfiles_paneles` igual a `$paneles` para ese perfil, tocando SOLO lo
 * que cambia — mismo criterio que las otras dos tablas puente del repo:
 * `asignado` es la fecha en que se dio ese permiso, y reescribir la fila entera
 * en cada guardado la volveria la fecha del ultimo `Guardar`.
 */
function sincronizarPaneles(PDO $pdo, int $perfil, array $paneles): void
{
    $stmt = $pdo->prepare('SELECT panel FROM perfiles_paneles WHERE perfil = :p');
    $stmt->execute([':p' => $perfil]);

    $previos = array_map('intval', array_column($stmt->fetchAll(), 'panel'));
    $agregar = array_values(array_diff($paneles, $previos));
    $quitar  = array_values(array_diff($previos, $paneles));

    if ($quitar) {
        // Interpolar la lista es seguro: son enteros, ya casteados.
        $ids = implode(',', array_map('intval', $quitar));
        $pdo->prepare("DELETE FROM perfiles_paneles WHERE perfil = :p AND panel IN ($ids)")
            ->execute([':p' => $perfil]);
    }

    if ($agregar) {
        $ins = $pdo->prepare('INSERT INTO perfiles_paneles (perfil, panel, asignado) VALUES (:p, :pa, NOW())');
        foreach ($agregar as $panel) {
            $ins->execute([':p' => $perfil, ':pa' => $panel]);
        }
    }
}

/**
 * Los tres permisos del payload, normalizados al entero que va a la base.
 *
 * EL QUE NO VIENE QUEDA EN 0, que es el default de la columna y el criterio de
 * `habilitado`: sin dato no hay permiso. El front los manda siempre los tres —
 * el alta pre-tilda `operacion` e `invitacion`, igual que pre-tilda los paneles
 * del dominio— asi que este default solo alcanza a un POST armado a mano.
 *
 * @return array{operacion:int, invitacion:int, facturacion:int}
 */
function permisosDelPayload(array $in): array
{
    $permisos = [];
    foreach (perfilPermisosClaves() as $clave) {
        $permisos[$clave] = valorHabilitado($in[$clave] ?? 0);
    }

    return $permisos;
}

function handleCreate(): void
{
    $in         = readJson();
    $usuario_id = (int) ($in['usuario_id'] ?? 0);
    $dominio_id = (int) ($in['dominio_id'] ?? 0);
    $tipo       = validarTipo($in['tipo'] ?? 'O');
    $activo     = isset($in['activo']) ? (bool) $in['activo'] : true;
    $permisos   = permisosDelPayload($in);

    if ($usuario_id <= 0) json_error('Usuario invalido', 422);
    if ($dominio_id <= 0) json_error('Dominio invalido', 422);

    $paneles = validarPaneles($in['paneles'] ?? [], $dominio_id);

    // El nombre del perfil es el del sistema historico ("Operador en <dominio>").
    // Se arma aca porque la columna es NOT NULL en la practica para todo lo que
    // el legacy muestra, y dejarla vacia da una fila sin etiqueta en dos apps.
    $dom = db()->prepare('SELECT nombre FROM dominios WHERE id = :d');
    $dom->execute([':d' => $dominio_id]);
    $dominioNombre = (string) ($dom->fetchColumn() ?: '');
    if ($dominioNombre === '') json_error('El dominio no existe', 422);

    // QUIEN ESTA DANDO DE ALTA ESTE ACCESO. Va a `perfiles`.`registrante`, la
    // columna que responde "quien otorgo este acceso" — que NO es lo mismo que
    // `usuarios`.`registrante`, que responde quien creo la CUENTA (la escribe
    // `usuarioAlta()` en api/users.php). Este es el unico alta de perfiles CON
    // sesion: los otros dos son las invitaciones, que corren sin ella y anotan
    // al emisor de la invitacion.
    //
    // VA EN NULL, Y NO ES UN OLVIDO. La columna es una FK contra
    // `usuarios`.`id`, y desde el 07/09/2026 el login de cloud valida contra
    // `controladores`: quien esta logueado no es una fila de `usuarios`, asi que
    // su id ahi apuntaria a otra persona. Un perfil otorgado desde cloud no lo
    // registro ningun usuario — lo registro Reactor —, y eso dice el NULL.
    // Mismo criterio que `usuarioAlta()` en api/users.php.
    $registrante = null;

    // La fila y sus paneles van en una transaccion: un perfil a medio asignar
    // deja permisos que nadie pidio.
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO perfiles (uuid, nombre, usuario, dominio, tipo,
                                   operacion, invitacion, facturacion,
                                   registrante, habilitado)
             VALUES (:uuid, :nombre, :u, :d, :t, :op, :inv, :fac, :reg, :h)'
        );
        $stmt->execute([
            ':uuid'   => bin2hex(random_bytes(8)),
            ':nombre' => mb_substr(PERFIL_TIPOS[$tipo] . ' en ' . $dominioNombre, 0, 255),
            ':u'      => $usuario_id,
            ':d'      => $dominio_id,
            ':t'      => $tipo,
            ':op'     => $permisos['operacion'],
            ':inv'    => $permisos['invitacion'],
            ':fac'    => $permisos['facturacion'],
            ':reg'    => $registrante,
            // Entero, nunca el booleano de PHP. Ver lib/habilitado.php.
            ':h'      => valorHabilitado($activo),
        ]);

        $id = (int) $pdo->lastInsertId();
        sincronizarPaneles($pdo, $id, $paneles);

        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ((int) $e->errorInfo[1] === 1452) {
            json_error('Usuario o dominio inexistente', 422);
        }
        throw $e;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id], 201);
}

/**
 * Edita el `tipo`, el estado, los tres permisos y los paneles del perfil. El
 * `usuario` y el `dominio` no se tocan — cambiarlos convierte la fila en otro
 * acceso, y para eso esta el alta.
 *
 * El PUT reescribe los cinco campos con lo que venga, sin el `array_key_exists`
 * que si usa panel/api/usuarios.php: aca el unico llamador es el formulario
 * completo, no hay un toggle de fila que mande un campo suelto.
 */
function handleUpdate(): void
{
    $in       = readJson();
    $id       = (int) ($in['id'] ?? 0);
    $tipo     = validarTipo($in['tipo'] ?? '');
    $activo   = isset($in['activo']) ? (bool) $in['activo'] : false;
    $permisos = permisosDelPayload($in);

    if ($id <= 0) json_error('Id invalido', 422);

    // El dominio sale de la FILA, no del payload: es contra el dominio real del
    // perfil que se valida que cada panel le corresponda.
    $prev = db()->prepare('SELECT dominio FROM perfiles WHERE id = :id');
    $prev->execute([':id' => $id]);
    $fila = $prev->fetch();
    if (!$fila) json_error('Perfil no encontrado', 404);

    $paneles = validarPaneles($in['paneles'] ?? [], (int) $fila['dominio']);

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $pdo->prepare(
            'UPDATE perfiles
                SET tipo = :t, habilitado = :h,
                    operacion = :op, invitacion = :inv, facturacion = :fac
              WHERE id = :id'
        )->execute([
            ':t'   => $tipo,
            ':h'   => valorHabilitado($activo),
            ':op'  => $permisos['operacion'],
            ':inv' => $permisos['invitacion'],
            ':fac' => $permisos['facturacion'],
            ':id'  => $id,
        ]);

        sincronizarPaneles($pdo, $id, $paneles);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('DELETE FROM perfiles WHERE id = :id');
    $stmt->execute([':id' => $id]);

    if ($stmt->rowCount() === 0) json_error('Perfil no encontrado', 404);

    json_ok(['id' => $id]);
}

/** `tipo` es ENUM('A','O') NOT NULL: dos letras y nada mas. */
function validarTipo(mixed $valor): string
{
    $tipo = strtoupper(trim((string) $valor));
    if (!array_key_exists($tipo, PERFIL_TIPOS)) json_error('Tipo invalido', 422);

    return $tipo;
}

function opcionesTipo(): array
{
    $opciones = [];
    foreach (PERFIL_TIPOS as $valor => $texto) {
        $opciones[] = ['valor' => $valor, 'texto' => $texto];
    }

    return $opciones;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
