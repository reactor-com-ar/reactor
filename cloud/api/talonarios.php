<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * ABM de `talonarios` (db/schema.sql).
 *
 * Un talonario es una numeracion de comprobantes: la empresa que factura, el
 * tipo y subtipo de comprobante, el punto de venta y el proximo numero
 * (`serie`), mas los datos de pie que se imprimen (correo, web, fondo,
 * terminos). `comprobantes`.`talonario` y `clientes`.`talonario` cuelgan de
 * aca.
 *
 * TODAS las columnas son editables menos dos:
 *
 *   - `id`, que es el AUTO_INCREMENT.
 *   - `nombre`, que es DERIVADO. Ver NOMBRE abajo.
 *
 * NOMBRE ES DERIVADO, Y HAY UNA FILA QUE NO LO RESPETA. El legacy lo arma en
 * `cTalonario::nombrar()` y lo reescribe en cada alta y en cada edicion:
 *
 *     <empresa> - <texto del tipo> - <subtipo> - <punto con 3 digitos>
 *     "Alfatec - Remito - X - 001"
 *
 * Ojo con el segundo segmento: es el TEXTO del tipo traducido contra `combos`,
 * mientras que el subtipo va crudo (los cinco valores del combo son la letra
 * misma). Y ojo con el talonario 35, que se llama "Interno - Wescom - Abono -
 * X - 001": su `tipo` es `A`, que NO esta en el combo, asi que la derivacion le
 * daria "Wescom -  - X - 001" -- pierde el prefijo "Interno" y el "Abono", y es
 * un talonario con comprobantes emitidos. Por eso este endpoint NO reescribe el
 * nombre a ciegas: lo regenera cuando el nombre guardado coincide con lo que la
 * derivacion produce (17 de las 18 filas, o sea el comportamiento del legacy) y
 * lo CONSERVA cuando no coincide, porque entonces alguien lo escribio a mano y
 * pisarlo seria reescribir un hecho. Es el mismo criterio con el que las
 * invitaciones no tocan `usuarios`.`registrante` de una cuenta que ya existia.
 *
 * ESTADO NO SE LLAMA `habilitado`, PERO ES LA MISMA BANDERA. `talonarios`.
 * `estado` es `smallint` con dos valores -- 1 y 0 -- y el combo
 * `$xTalonario->estado` los traduce justamente como "Habilitado" /
 * "Deshabilitado". Se lo trata igual que a la bandera de CLAUDE.md: se
 * normaliza la entrada con `valorHabilitado()`, se escribe el ENTERO y en SQL
 * se compara contra el entero. Lo que no se hace es renombrar la columna ni
 * inventarle un `habilitado` que el sistema historico no lee.
 *
 * BORRAR. Las dos FK que apuntan aca son RESTRICT y las dos BLOQUEAN:
 * `comprobantes`.`talonario` (2.323 filas) porque son comprobantes fiscales ya
 * emitidos, y `clientes`.`talonario` (64) porque es el talonario con el que se
 * le factura a cada cliente. No hay nada que se borre en cascada ni que se
 * desvincule solo: si hay dependencias, no se borra. El desglose lo sirve
 * `?impacto=1&id=N` y el DELETE repite las validaciones (ABM.md, "Eliminar").
 */

/** Claves de `combos` con los textos de los codigos cortos de `talonarios`. */
const COMBO_TIPO    = '$xTalonario->tipo';
const COMBO_SUBTIPO = '$xTalonario->subtipo';
const COMBO_FISCAL  = '$xTalonario->fiscal';
const COMBO_ESTADO  = '$xTalonario->estado';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_FISCAL => ['1' => 'Si',         '0' => 'No'],
    COMBO_ESTADO => ['1' => 'Habilitado', '0' => 'Deshabilitado'],
];

/** Digitos con los que `nombrar()` rellena el punto de venta. */
const PUNTO_DIGITOS = 3;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':
            if (isset($_GET['impacto'])) handleImpacto();
            else                         handleList();
            break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar talonarios: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // La fila entera, el nombre de la empresa y los dos contadores que
    // gobiernan el borrado. Subconsultas correlacionadas y no LEFT JOIN: con
    // JOINs las filas se multiplican entre si y cada COUNT devolveria el
    // producto (mismo criterio que api/dominios.php y api/contratos.php). Las
    // dos columnas contadas tienen indice -- lo trae su FK.
    $stmt = db()->query(
        'SELECT t.id,
                t.nombre,
                t.empresa,
                em.nombre AS empresa_nombre,
                em.razon  AS empresa_razon,
                t.tipo,
                t.subtipo,
                t.punto,
                t.serie,
                t.fiscal,
                t.correo,
                t.web,
                t.fondo,
                t.terminos,
                t.estado,
                (SELECT COUNT(*) FROM comprobantes cp WHERE cp.talonario = t.id) AS comprobantes_count,
                (SELECT COUNT(*) FROM clientes     cl WHERE cl.talonario = t.id) AS clientes_count
         FROM talonarios t
         LEFT JOIN empresas em ON em.id = t.empresa
         ORDER BY t.id DESC'
    );

    $talonarios = array_map(static function (array $r): array {
        $tipo    = trim((string) ($r['tipo']    ?? ''));
        $subtipo = trim((string) ($r['subtipo'] ?? ''));
        $fiscal  = trim((string) ($r['fiscal']  ?? ''));
        $estado  = (int) ($r['estado'] ?? 0);
        $nombre  = trim((string) ($r['nombre']  ?? ''));

        $derivado = nombrar(
            trim((string) ($r['empresa_nombre'] ?? '')),
            $tipo,
            $subtipo,
            (int) $r['punto']
        );

        return [
            'id'                 => (int) $r['id'],
            'nombre'             => $nombre,
            // El nombre derivado viaja aparte para que el modal de edicion
            // pueda mostrar la vista previa en vivo sin recalcular textos de
            // `combos` en el front.
            'nombre_derivado'    => $derivado,
            // Una fila con nombre propio (hoy solo la 35) NO se renombra al
            // guardar; el front lo dice en el formulario en vez de dejar que
            // el operador se entere despues.
            'nombre_propio'      => $nombre !== '' && $nombre !== $derivado,
            // El 0 de la FK es el centinela "sin asignar" del sistema
            // historico, no un id: se normaliza a null como el NULL real.
            'empresa'            => idOrNull($r['empresa']),
            'empresa_nombre'     => trim((string) ($r['empresa_nombre'] ?? '')),
            'empresa_razon'      => trim((string) ($r['empresa_razon']  ?? '')),
            'tipo'               => $tipo,
            'tipo_texto'         => combo(COMBO_TIPO)[$tipo] ?? '',
            'subtipo'            => $subtipo,
            'punto'              => (int) $r['punto'],
            'serie'              => (int) $r['serie'],
            // El numero completo tal como lo arma un comprobante: punto de
            // venta con 4 digitos y correlativo con 8, el formato de AFIP.
            'proximo'            => sprintf('%04d-%08d', (int) $r['punto'], (int) $r['serie']),
            'fiscal'             => $fiscal,
            'fiscal_texto'       => combo(COMBO_FISCAL)[$fiscal] ?? '',
            'correo'             => trim((string) ($r['correo']   ?? '')),
            'web'                => trim((string) ($r['web']      ?? '')),
            'fondo'              => trim((string) ($r['fondo']    ?? '')),
            'terminos'           => (string) ($r['terminos'] ?? ''),
            // `estado` es la bandera de dos valores: se sirve como entero.
            'estado'             => $estado === 1 ? 1 : 0,
            'estado_texto'       => combo(COMBO_ESTADO)[(string) $estado] ?? '',
            'comprobantes_count' => (int) $r['comprobantes_count'],
            'clientes_count'     => (int) $r['clientes_count'],
        ];
    }, $stmt->fetchAll());

    $resumen = [
        'total'          => count($talonarios),
        'habilitados'    => 0,
        'deshabilitados' => 0,
        'fiscales'       => 0,
        'empresas'       => 0,
    ];
    $empresas = [];
    foreach ($talonarios as $t) {
        if ($t['estado'] === 1) $resumen['habilitados']++;
        else                    $resumen['deshabilitados']++;
        if ($t['fiscal'] === '1') $resumen['fiscales']++;
        if ($t['empresa'] !== null) $empresas[$t['empresa']] = true;
    }
    $resumen['empresas'] = count($empresas);

    json_ok([
        'resumen'    => $resumen,
        'talonarios' => $talonarios,
        'catalogos'  => catalogos(),
    ]);
}

/** Catalogos de los desplegables del modal de Alta/Edicion y de Filtros. */
function catalogos(): array
{
    $empresas = array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => trim((string) ($r['nombre'] ?? '')),
        'razon'  => trim((string) ($r['razon']  ?? '')),
        'cuit'   => trim((string) ($r['cuit']   ?? '')),
    ], db()->query('SELECT id, nombre, razon, cuit FROM empresas ORDER BY nombre ASC, id ASC')->fetchAll());

    return [
        'empresas' => $empresas,
        'tipos'    => comboLista(COMBO_TIPO),
        'subtipos' => comboLista(COMBO_SUBTIPO),
        'fiscal'   => comboLista(COMBO_FISCAL),
        'estados'  => comboLista(COMBO_ESTADO),
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * Las dos dependencias BLOQUEAN: son FK RESTRICT y ademas cosas que este
 * endpoint no tiene por que resolver solo -- comprobantes fiscales emitidos y
 * la asignacion de talonario de cada cliente. No hay nada que se elimine en
 * cascada ni que se desvincule.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(
        'SELECT t.id, t.nombre, t.empresa, em.nombre AS empresa_nombre
         FROM talonarios t
         LEFT JOIN empresas em ON em.id = t.empresa
         WHERE t.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $tal = $stmt->fetch();
    if (!$tal) json_error('Talonario no encontrado', 404);

    $deps = dependencias($id);

    $bloqueos = [];
    if ($deps['comprobantes'] > 0) {
        $bloqueos[] = ['label' => 'Comprobantes emitidos con este talonario', 'cantidad' => $deps['comprobantes']];
    }
    if ($deps['clientes'] > 0) {
        $bloqueos[] = ['label' => 'Clientes que facturan con este talonario', 'cantidad' => $deps['clientes']];
    }

    json_ok([
        'talonario' => [
            'id'             => (int) $tal['id'],
            'nombre'         => trim((string) ($tal['nombre'] ?? '')),
            'empresa_nombre' => trim((string) ($tal['empresa_nombre'] ?? '')),
        ],
        'bloqueos' => $bloqueos,
        // Las dos claves viajan vacias porque el modal compartido las lee: de
        // un talonario no cuelga nada que se borre ni que quede sin referencia.
        'elimina'    => [],
        'desvincula' => [],
    ]);
}

function handleCreate(): void
{
    $data = validarTalonario(readJson(), null);

    $stmt = db()->prepare(
        'INSERT INTO talonarios
             (nombre, empresa, tipo, subtipo, punto, serie, fiscal, correo, web, fondo, terminos, estado)
         VALUES
             (:nombre, :empresa, :tipo, :subtipo, :punto, :serie, :fiscal, :correo, :web, :fondo, :terminos, :estado)'
    );
    $stmt->execute(bindTalonario($data));

    json_ok(['id' => (int) db()->lastInsertId(), 'nombre' => $data['nombre']], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $actual = db()->prepare(
        'SELECT t.nombre, t.empresa, t.tipo, t.subtipo, t.punto, em.nombre AS empresa_nombre
         FROM talonarios t
         LEFT JOIN empresas em ON em.id = t.empresa
         WHERE t.id = :id'
    );
    $actual->execute([':id' => $id]);
    $previo = $actual->fetch();
    if (!$previo) json_error('Talonario no encontrado', 404);

    $data = validarTalonario($in, $previo);

    $stmt = db()->prepare(
        'UPDATE talonarios
            SET nombre   = :nombre,
                empresa  = :empresa,
                tipo     = :tipo,
                subtipo  = :subtipo,
                punto    = :punto,
                serie    = :serie,
                fiscal   = :fiscal,
                correo   = :correo,
                web      = :web,
                fondo    = :fondo,
                terminos = :terminos,
                estado   = :estado
          WHERE id = :id'
    );
    $stmt->execute(bindTalonario($data) + [':id' => $id]);

    json_ok(['id' => $id, 'nombre' => $data['nombre']]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM talonarios WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Talonario no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite las
    // validaciones por su cuenta (ABM.md, "Eliminar").
    $deps = dependencias($id);
    if ($deps['comprobantes'] > 0 || $deps['clientes'] > 0) {
        $partes = [];
        if ($deps['comprobantes'] > 0) $partes[] = "{$deps['comprobantes']} comprobante(s)";
        if ($deps['clientes'] > 0)     $partes[] = "{$deps['clientes']} cliente(s)";
        json_error(
            'No se puede eliminar: el talonario tiene ' . implode(' y ', $partes) .
            '. Reasignalos a otro talonario antes de borrarlo.',
            409
        );
    }

    $stmt = db()->prepare('DELETE FROM talonarios WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_ok(['id' => $id]);
}

/* ---------------------------------------------------------------- helpers */

/** Cantidad de filas que dependen del talonario, por tabla. */
function dependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM comprobantes WHERE talonario = :a) AS comprobantes,
                (SELECT COUNT(*) FROM clientes     WHERE talonario = :b) AS clientes'
    );
    $stmt->execute([':a' => $id, ':b' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'comprobantes' => (int) ($r['comprobantes'] ?? 0),
        'clientes'     => (int) ($r['clientes']     ?? 0),
    ];
}

/**
 * El nombre que arma `cTalonario::nombrar()`.
 *
 * El tipo va TRADUCIDO y el subtipo CRUDO -- no es un descuido del legacy: los
 * cinco valores del combo de subtipo son la letra misma, asi que traducirlo no
 * cambiaria nada, mientras que el tipo es un codigo de una letra que sin el
 * texto no se lee. Cuando `combos` no tiene el tipo se usa el codigo pelado en
 * vez de dejar el segmento vacio, que es lo que hace el legacy: un nombre con
 * un hueco en el medio ("Wescom -  - X - 001") no dice nada.
 */
function nombrar(string $empresaNombre, string $tipo, string $subtipo, int $punto): string
{
    $tipoTexto = combo(COMBO_TIPO)[$tipo] ?? $tipo;

    return $empresaNombre . ' - ' . $tipoTexto . ' - ' . $subtipo . ' - '
         . str_pad((string) $punto, PUNTO_DIGITOS, '0', STR_PAD_LEFT);
}

/**
 * Valida y normaliza el payload.
 *
 * `$previo` es null en el alta y la fila actual en la edicion: se usa para
 * decidir si el nombre se regenera o se conserva (ver NOMBRE en la cabecera).
 * `nombre` NO se lee del payload -- es derivado y el formulario lo muestra de
 * solo lectura.
 */
function validarTalonario(array $in, ?array $previo): array
{
    $out = [];

    $out['empresa'] = fkOpcional($in['empresa'] ?? null, 'empresas', 'La empresa');

    // tipo / subtipo / fiscal son varchar(1) y ademas tienen que estar en su
    // combo: un codigo fuera del catalogo se veria pelado en toda pantalla que
    // lo traduzca -- incluido el back office viejo, que es el que imprime los
    // comprobantes.
    //
    // El codigo que la fila YA TENIA se acepta aunque no este en el catalogo.
    // Es el caso del talonario 35, cuyo `tipo` es `A` ("Abono") y no figura en
    // el combo: sin esta excepcion la fila quedaria imposible de guardar --
    // nadie podria corregirle ni el correo-- y la validacion, que existe para
    // que no ENTREN codigos nuevos fuera del catalogo, terminaria bloqueando
    // uno viejo que ya esta emitiendo comprobantes. Un alta no tiene `$previo`,
    // asi que ahi el catalogo se exige entero.
    $out['tipo']    = codigoDeCombo($in['tipo']    ?? null, COMBO_TIPO,    'El tipo',             $previo['tipo']    ?? null);
    $out['subtipo'] = codigoDeCombo($in['subtipo'] ?? null, COMBO_SUBTIPO, 'El subtipo',          $previo['subtipo'] ?? null);
    $out['fiscal']  = codigoDeCombo($in['fiscal']  ?? null, COMBO_FISCAL,  'El valor de fiscal',  null);

    // punto y serie son enteros no negativos. `serie` es el proximo numero de
    // comprobante: no se valida hacia arriba, pero un negativo no existe.
    $out['punto'] = enteroNoNegativo($in['punto'] ?? null, 'El punto de venta');
    $out['serie'] = enteroNoNegativo($in['serie'] ?? null, 'La serie');

    // Los cuatro campos de texto se guardan VACIOS, no NULL, cuando no tienen
    // valor. Es lo contrario de lo que hace el resto de los endpoints
    // (`=== '' ? null : $x`) y es a proposito: las 18 filas usan la cadena
    // vacia -- `terminos` la tiene en las 18 y `correo`/`web`/`fondo` nunca
    // estan vacios --, asi que mandar NULL estrenaria una segunda forma de
    // decir lo mismo y un ida y vuelta por el ABM cambiaria filas que nadie
    // toco. Los dos valores le dan igual al legacy, que imprime el pie con
    // `nl2br()`; la uniformidad no.
    $correo = trim((string) ($in['correo'] ?? ''));
    if ($correo !== '') {
        if (mb_strlen($correo) > 100)                    json_error('El correo no puede superar 100 caracteres', 422);
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) json_error('El correo no es valido', 422);
    }
    $out['correo'] = $correo;

    $web = trim((string) ($in['web'] ?? ''));
    if (mb_strlen($web) > 100) json_error('La web no puede superar 100 caracteres', 422);
    $out['web'] = $web;

    $fondo = trim((string) ($in['fondo'] ?? ''));
    if (mb_strlen($fondo) > 100) json_error('El fondo no puede superar 100 caracteres', 422);
    $out['fondo'] = $fondo;

    // `terminos` es varchar(5000), no TEXT: pasarse no trunca en silencio.
    $terminos = (string) ($in['terminos'] ?? '');
    if (mb_strlen($terminos) > 5000) json_error('Los terminos no pueden superar 5000 caracteres', 422);
    $out['terminos'] = $terminos;

    // `estado` es la misma bandera de dos valores que `habilitado`, aunque la
    // columna se llame distinto: se normaliza igual y se escribe el entero.
    $out['estado'] = valorHabilitado($in['estado'] ?? DESHABILITADO);

    $out['nombre'] = nombreAGuardar($out, $previo);

    return $out;
}

/**
 * Nombre a persistir: el derivado, salvo que la fila lleve uno propio.
 *
 * En el alta siempre es el derivado -- no hay nada que conservar. En la
 * edicion se compara el nombre guardado contra la derivacion de los valores
 * que la fila TENIA: si coincidian, la fila se venia autonombrando y se
 * renombra (el comportamiento del legacy); si no, alguien lo escribio a mano y
 * se conserva. Ver NOMBRE en la cabecera.
 */
function nombreAGuardar(array $data, ?array $previo): string
{
    $derivado = nombrar(empresaNombre($data['empresa']), $data['tipo'], $data['subtipo'], $data['punto']);

    if ($previo === null) return $derivado;

    $guardado = trim((string) ($previo['nombre'] ?? ''));
    $derivadoPrevio = nombrar(
        trim((string) ($previo['empresa_nombre'] ?? '')),
        trim((string) ($previo['tipo']    ?? '')),
        trim((string) ($previo['subtipo'] ?? '')),
        (int) ($previo['punto'] ?? 0)
    );

    return ($guardado !== '' && $guardado !== $derivadoPrevio) ? $guardado : $derivado;
}

/** Nombre de una empresa por id, cacheado por request. */
function empresaNombre(?int $id): string
{
    static $cache = [];
    if ($id === null) return '';
    if (isset($cache[$id])) return $cache[$id];

    $stmt = db()->prepare('SELECT nombre FROM empresas WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $cache[$id] = trim((string) ($stmt->fetchColumn() ?: ''));
}

/** Parametros del INSERT / UPDATE, en el orden del esquema. */
function bindTalonario(array $d): array
{
    return [
        ':nombre'   => $d['nombre'],
        ':empresa'  => $d['empresa'],
        ':tipo'     => $d['tipo'],
        ':subtipo'  => $d['subtipo'],
        ':punto'    => $d['punto'],
        ':serie'    => $d['serie'],
        ':fiscal'   => $d['fiscal'],
        ':correo'   => $d['correo'],
        ':web'      => $d['web'],
        ':fondo'    => $d['fondo'],
        ':terminos' => $d['terminos'],
        ':estado'   => $d['estado'],
    ];
}

/**
 * Codigo corto que tiene que existir en su combo. Vacio => null.
 *
 * `$heredado` es el valor que la fila ya tenia: se acepta siempre, aunque no
 * este en el catalogo (ver la nota de `validarTalonario()`).
 *
 * Si `combos` no tiene cargada la clave se acepta lo que venga: sin catalogo no
 * hay contra que validar, y bloquear el alta entera por eso seria peor.
 */
function codigoDeCombo(mixed $valor, string $clave, string $rotulo, ?string $heredado): ?string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return null;

    if (mb_strlen($v) > 1) json_error("{$rotulo} tiene que ser un codigo de una letra", 422);

    if ($heredado !== null && $v === trim($heredado)) return $v;

    $catalogo = combo($clave);
    if ($catalogo !== [] && !isset($catalogo[$v])) {
        json_error("{$rotulo} \"{$v}\" no esta en el catalogo", 422);
    }

    return $v;
}

function enteroNoNegativo(mixed $valor, string $rotulo): int
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0;
    if (!ctype_digit($v)) json_error("{$rotulo} tiene que ser un numero entero de 0 o mas", 422);

    $n = (int) $v;
    if ($n > 999999) json_error("{$rotulo} no puede superar 999999", 422);

    return $n;
}

/**
 * Id de una FK opcional. Ademas de normalizar el 0 centinela a null, verifica
 * que la fila exista: la FK es RESTRICT y un id inventado devolveria un 500 del
 * motor en vez de decir cual campo esta mal.
 */
function fkOpcional(mixed $valor, string $tabla, string $rotulo): ?int
{
    $id = idOrNull($valor);
    if ($id === null) return null;

    $stmt = db()->prepare("SELECT 1 FROM {$tabla} WHERE id = :id");
    $stmt->execute([':id' => $id]);
    if (!$stmt->fetchColumn()) json_error("{$rotulo} #{$id} no existe", 422);

    return $id;
}

/** Tabla plana valor -> texto de un combo del sistema historico. */
function combo(string $clave): array
{
    static $cache = [];
    if (isset($cache[$clave])) return $cache[$clave];

    $stmt = db()->prepare('SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, id ASC');
    $stmt->execute([':c' => $clave]);

    $textos = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') continue;
        $textos[$valor] = (string) ($r['texto'] ?? '');
    }

    if ($textos === []) $textos = COMBOS_FALLBACK[$clave] ?? [];

    return $cache[$clave] = $textos;
}

/** El mismo combo, como lista ordenada para poblar un <select>. */
function comboLista(string $clave): array
{
    $items = [];
    foreach (combo($clave) as $valor => $texto) {
        $items[] = ['valor' => (string) $valor, 'texto' => $texto];
    }
    return $items;
}

/**
 * Id de una FK del sistema historico, donde el 0 significa "sin asignar"
 * igual que el NULL (ver el criterio del `0` centinela en db/schema.sql).
 */
function idOrNull(mixed $v): ?int
{
    $id = (int) $v;
    return $id > 0 ? $id : null;
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
