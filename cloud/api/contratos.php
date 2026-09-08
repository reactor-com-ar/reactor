<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * ABM de `contratos` (db/schema.sql).
 *
 * Es el contrato comercial de un dominio: a quien se le factura (`cliente`),
 * que dominio cubre, con que `plan` y desde cuando, mas las fechas del ciclo de
 * facturacion (`facturado` / `facturar` / `tolerancia`) y del envio del estado
 * de cuenta (`remitir` / `remitido`).
 *
 * TODAS las columnas son editables MENOS dos:
 *
 *   - `id`, que es el AUTO_INCREMENT.
 *   - `promo`, que se sirve pero no se escribe. Ver PROMO abajo.
 *
 * PROMO ES DE SOLO LECTURA, Y NO POR COMODIDAD. La columna esta rota en el
 * esquema: `db/schema.sql` la declara `FOREIGN KEY (promo) REFERENCES
 * articulos (id)`, pero el sistema historico la usa como un PORCENTAJE de
 * descuento -- `cContrato::facturar()` calcula `($articulo->venta * $promo) /
 * 100` y el combo `$xContrato->promo` ofrece 0, 10, 20 ... 100. Las dos
 * lecturas no pueden convivir: `articulos` tiene ids 1..279 y NINGUNO de los
 * once valores del combo existe ahi, asi que cualquier promo que se guardara
 * violaria la FK. Hoy no explota porque las 50 filas la tienen en NULL.
 * Mientras no se decida cual de las dos cosas es, el ABM la muestra (traducida
 * contra `combos`, como el legacy) y no la toca: escribirla eligiendo una
 * lectura es repartir plata o romper el INSERT, y ninguna de las dos es una
 * decision que le toque a un formulario.
 *
 * FECHAS CENTINELA. El sistema historico no usa NULL para "sin fecha": usa
 * `1500-01-01` (`cTiempo::genesis()`) y, para `baja`, `2500-01-01`
 * (`cTiempo::apocalipsis()`). Es lo que tiene el 100% de los datos -- 49 de 50
 * filas en `desde`/`hasta`, 27 en `baja`, 26 en `tolerancia` y en `remitido` --
 * y `cContrato::nuevo()` sigue sembrando eso. El endpoint traduce en los dos
 * sentidos: al leer, un centinela sale como `null` (el front lo dibuja "sin
 * fecha" y abre el input vacio); al escribir, un campo vacio vuelve a guardarse
 * como el centinela que le corresponde a esa columna. Asi el ida y vuelta por
 * el ABM no cambia una sola fila y la columna no estrena un tercer valor que
 * las comparaciones del legacy nunca vieron.
 *
 * BORRAR. `comprobantes`.`contrato` y `pagos`.`contrato` son FK RESTRICT: un
 * contrato facturado no se borra, y el endpoint no intenta resolverlo por su
 * cuenta (son los comprobantes fiscales del cliente). `dominios`.`contrato` es
 * SET NULL: el dominio sobrevive sin la referencia. El desglose lo sirve
 * `?impacto=1&id=N` y el DELETE repite las validaciones (ABM.md, "Eliminar").
 */

/** Claves de `combos` con los textos de los codigos cortos de `contratos`. */
const COMBO_TIPO       = '$xContrato->tipo';
const COMBO_PROMO      = '$xContrato->promo';
const COMBO_REMITIR    = '$xContrato->remitir';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_REMITIR => ['1' => 'Si', '0' => 'No'],
];

/** "Sin fecha" del sistema historico (cTiempo::genesis / ::apocalipsis). */
const FECHA_GENESIS     = '1500-01-01';
const FECHA_APOCALIPSIS = '2500-01-01';

/**
 * Centinela de cada columna de fecha. `baja` usa el apocalipsis ("todavia no se
 * dio de baja"); el resto, el genesis ("todavia no paso"). Es exactamente el
 * reparto que hace `cContrato::nuevo()`.
 */
const CENTINELA_FECHA = [
    'desde'      => FECHA_GENESIS,
    'hasta'      => FECHA_GENESIS,
    'alta'       => FECHA_GENESIS,
    'baja'       => FECHA_APOCALIPSIS,
    'facturado'  => FECHA_GENESIS,
    'facturar'   => FECHA_GENESIS,
    'tolerancia' => FECHA_GENESIS,
];

/** Columnas `date` y columnas `datetime`, en el orden del esquema. */
const CAMPOS_FECHA    = ['desde', 'hasta', 'alta', 'baja', 'facturado', 'facturar', 'tolerancia'];
const CAMPOS_FECHAHORA = ['registro', 'firma', 'remitido'];

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
    json_error('Error al procesar contratos: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // La fila entera, mas el nombre de cada FK para no hacer N llamadas desde
    // el front, mas los dos contadores que gobiernan el borrado. Son
    // subconsultas correlacionadas y no LEFT JOIN: con JOINs las filas se
    // multiplican entre si y cada COUNT devolveria el producto (ver el mismo
    // criterio en api/dominios.php). Las dos tienen indice -- lo trae la FK.
    $stmt = db()->query(
        'SELECT c.id,
                c.uuid,
                c.cliente,
                cl.nombre      AS cliente_nombre,
                c.dominio,
                do.nombre      AS dominio_nombre,
                do.situacion   AS dominio_situacion,
                c.tipo,
                c.plan,
                pl.nombre      AS plan_nombre,
                pl.descripcion AS plan_descripcion,
                ar.venta       AS plan_venta,
                c.promo,
                c.desde,
                c.hasta,
                c.registro,
                c.firma,
                c.alta,
                c.baja,
                c.facturado,
                c.facturar,
                c.tolerancia,
                c.remitir,
                c.remitido,
                c.habilitado,
                (SELECT COUNT(*) FROM comprobantes cp WHERE cp.contrato = c.id) AS comprobantes_count,
                (SELECT COUNT(*) FROM pagos        pg WHERE pg.contrato = c.id) AS pagos_count
         FROM contratos c
         LEFT JOIN clientes  cl ON cl.id = c.cliente
         LEFT JOIN dominios  do ON do.id = c.dominio
         LEFT JOIN planes    pl ON pl.id = c.plan
         LEFT JOIN articulos ar ON ar.id = pl.articulo
         ORDER BY c.id DESC'
    );

    $hoy = date('Y-m-d');

    $contratos = array_map(static function (array $r) use ($hoy): array {
        $tipo    = trim((string) ($r['tipo']    ?? ''));
        $promo   = trim((string) ($r['promo']   ?? ''));
        $remitir = trim((string) ($r['remitir'] ?? ''));

        $fila = [
            'id'                 => (int) $r['id'],
            'uuid'               => trim((string) ($r['uuid'] ?? '')),
            // El 0 de estas FKs es el centinela "sin asignar" del sistema
            // historico, no un id: se normaliza a null como el NULL real.
            'cliente'            => idOrNull($r['cliente']),
            'cliente_nombre'     => trim((string) ($r['cliente_nombre'] ?? '')),
            'dominio'            => idOrNull($r['dominio']),
            'dominio_nombre'     => trim((string) ($r['dominio_nombre'] ?? '')),
            'dominio_situacion'  => trim((string) ($r['dominio_situacion'] ?? '')),
            'tipo'               => $tipo,
            'tipo_texto'         => combo(COMBO_TIPO)[$tipo] ?? '',
            'plan'               => idOrNull($r['plan']),
            'plan_nombre'        => trim((string) ($r['plan_nombre'] ?? '')),
            'plan_descripcion'   => trim((string) ($r['plan_descripcion'] ?? '')),
            'plan_venta'         => $r['plan_venta'] === null ? null : (float) $r['plan_venta'],
            'promo'              => $promo === '' ? null : $promo,
            'promo_texto'        => combo(COMBO_PROMO)[$promo] ?? '',
            'remitir'            => $remitir,
            'remitir_texto'      => combo(COMBO_REMITIR)[$remitir] ?? '',
            'habilitado'         => esHabilitado($r['habilitado']) ? 1 : 0,
            'comprobantes_count' => (int) $r['comprobantes_count'],
            'pagos_count'        => (int) $r['pagos_count'],
        ];

        foreach (CAMPOS_FECHA as $campo) {
            $fila[$campo] = fechaSalida($r[$campo] ?? null, $campo);
        }
        foreach (CAMPOS_FECHAHORA as $campo) {
            $fila[$campo] = fechaHoraSalida($r[$campo] ?? null);
        }

        // "Facturable" es el criterio del listado legacy (listar?frd=genesis&
        // frh=hoy&hab=1): habilitado y con la fecha de proxima facturacion ya
        // cumplida. `facturar` sale ya normalizado, asi que un centinela es
        // null y no cuenta como vencido.
        $fila['facturable'] = $fila['habilitado'] === 1
            && $fila['facturar'] !== null
            && $fila['facturar'] <= $hoy;

        // "Remisible": habilitado y con el estado de cuenta pendiente de envio.
        $fila['remisible'] = $fila['habilitado'] === 1 && $remitir === '1';

        return $fila;
    }, $stmt->fetchAll());

    $resumen = [
        'total'          => count($contratos),
        'habilitados'    => 0,
        'deshabilitados' => 0,
        'facturables'    => 0,
        'remisibles'     => 0,
    ];
    foreach ($contratos as $c) {
        if ($c['habilitado'] === 1) $resumen['habilitados']++;
        else                        $resumen['deshabilitados']++;
        if ($c['facturable'])       $resumen['facturables']++;
        if ($c['remisible'])        $resumen['remisibles']++;
    }

    json_ok([
        'resumen'   => $resumen,
        'contratos' => $contratos,
        'catalogos' => catalogos(),
    ]);
}

/**
 * Catalogos de los desplegables del modal de Alta/Edicion y de Filtros.
 *
 * Los planes vienen TODOS, no solo los habilitados como en el legacy
 * (`comboCargar('plan', "... where habilitado=1 ...")`): hay 9 contratos vivos
 * sobre el plan 118, que esta deshabilitado. Con el filtro del legacy, abrir
 * uno de esos y guardarlo lo dejaba sin plan sin que nadie lo pidiera. El front
 * los marca y el orden es el mismo (`orden`, `nombre`).
 */
function catalogos(): array
{
    $clientes = array_map(static fn(array $r): array => [
        'id'     => (int) $r['id'],
        'nombre' => trim((string) ($r['nombre'] ?? '')),
    ], db()->query('SELECT id, nombre FROM clientes ORDER BY nombre ASC, id ASC')->fetchAll());

    $dominios = array_map(static fn(array $r): array => [
        'id'         => (int) $r['id'],
        'nombre'     => trim((string) ($r['nombre'] ?? '')),
        'cliente'    => idOrNull($r['cliente']),
        'habilitado' => esHabilitado($r['habilitado']) ? 1 : 0,
    ], db()->query('SELECT id, nombre, cliente, habilitado FROM dominios ORDER BY nombre ASC, id ASC')->fetchAll());

    $planes = array_map(static fn(array $r): array => [
        'id'          => (int) $r['id'],
        'nombre'      => trim((string) ($r['nombre'] ?? '')),
        'descripcion' => trim((string) ($r['descripcion'] ?? '')),
        'habilitado'  => esHabilitado($r['habilitado']) ? 1 : 0,
        'venta'       => $r['venta'] === null ? null : (float) $r['venta'],
    ], db()->query(
        'SELECT p.id, p.nombre, p.descripcion, p.habilitado, a.venta
         FROM planes p
         LEFT JOIN articulos a ON a.id = p.articulo
         ORDER BY p.habilitado DESC, p.orden ASC, p.nombre ASC'
    )->fetchAll());

    return [
        'clientes' => $clientes,
        'dominios' => $dominios,
        'planes'   => $planes,
        'tipos'    => comboLista(COMBO_TIPO),
        'promos'   => comboLista(COMBO_PROMO),
        'remitir'  => comboLista(COMBO_REMITIR),
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * `comprobantes` y `pagos` BLOQUEAN: son FK RESTRICT y ademas la contabilidad
 * del cliente -- reasignarlos o borrarlos desde aca seria tocar comprobantes
 * fiscales ya emitidos. `dominios` solo DESVINCULA: la FK es SET NULL.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare(
        'SELECT c.id, c.uuid, c.dominio, do.nombre AS dominio_nombre,
                c.cliente, cl.nombre AS cliente_nombre
         FROM contratos c
         LEFT JOIN dominios do ON do.id = c.dominio
         LEFT JOIN clientes cl ON cl.id = c.cliente
         WHERE c.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $con = $stmt->fetch();
    if (!$con) json_error('Contrato no encontrado', 404);

    $deps = dependencias($id);

    $bloqueos = [];
    if ($deps['comprobantes'] > 0) {
        $bloqueos[] = ['label' => 'Comprobantes emitidos', 'cantidad' => $deps['comprobantes']];
    }
    if ($deps['pagos'] > 0) {
        $bloqueos[] = ['label' => 'Pagos registrados', 'cantidad' => $deps['pagos']];
    }

    $desvincula = [];
    if ($deps['dominios'] > 0) {
        $desvincula[] = ['label' => 'Dominios que lo tienen como contrato vigente', 'cantidad' => $deps['dominios']];
    }

    json_ok([
        'contrato' => [
            'id'             => (int) $con['id'],
            'uuid'           => trim((string) ($con['uuid'] ?? '')),
            'dominio_nombre' => trim((string) ($con['dominio_nombre'] ?? '')),
            'cliente_nombre' => trim((string) ($con['cliente_nombre'] ?? '')),
        ],
        'bloqueos'   => $bloqueos,
        // Nada cuelga en cascada de un contrato: no hay tabla hija que se borre
        // con el. La clave viaja igual porque el modal la lee.
        'elimina'    => [],
        'desvincula' => $desvincula,
    ]);
}

function handleCreate(): void
{
    $data = validarContrato(readJson(), null);

    // `uuid` es la credencial del estado de cuenta publico
    // (reactor.com.ar/contrato/estado?uid=...). Si no lo mandan, se genera con
    // el mismo formato del legacy (`cCadena::aleatoria(8, '0')`): 8 digitos.
    if ($data['uuid'] === null) {
        $data['uuid'] = uuidLibre();
    }

    // `promo` no entra en el INSERT: queda en el NULL del esquema. Ver PROMO
    // en la cabecera del archivo.
    $stmt = db()->prepare(
        'INSERT INTO contratos
             (uuid, cliente, dominio, tipo, plan, desde, hasta, registro, firma,
              alta, baja, facturado, facturar, tolerancia, remitir, remitido, habilitado)
         VALUES
             (:uuid, :cliente, :dominio, :tipo, :plan, :desde, :hasta, :registro, :firma,
              :alta, :baja, :facturado, :facturar, :tolerancia, :remitir, :remitido, :habilitado)'
    );
    $stmt->execute(bindContrato($data));

    json_ok(['id' => (int) db()->lastInsertId()], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM contratos WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Contrato no encontrado', 404);

    $data = validarContrato($in, $id);
    if ($data['uuid'] === null) json_error('El identificador es obligatorio', 422);

    $stmt = db()->prepare(
        'UPDATE contratos
            SET uuid       = :uuid,
                cliente    = :cliente,
                dominio    = :dominio,
                tipo       = :tipo,
                plan       = :plan,
                desde      = :desde,
                hasta      = :hasta,
                registro   = :registro,
                firma      = :firma,
                alta       = :alta,
                baja       = :baja,
                facturado  = :facturado,
                facturar   = :facturar,
                tolerancia = :tolerancia,
                remitir    = :remitir,
                remitido   = :remitido,
                habilitado = :habilitado
          WHERE id = :id'
    );
    $stmt->execute(bindContrato($data) + [':id' => $id]);

    json_ok(['id' => $id]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $pdo = db();

    $existe = $pdo->prepare('SELECT 1 FROM contratos WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Contrato no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite las
    // validaciones por su cuenta (ABM.md, "Eliminar").
    $deps = dependencias($id);
    if ($deps['comprobantes'] > 0 || $deps['pagos'] > 0) {
        $partes = [];
        if ($deps['comprobantes'] > 0) $partes[] = "{$deps['comprobantes']} comprobante(s)";
        if ($deps['pagos'] > 0)        $partes[] = "{$deps['pagos']} pago(s)";
        json_error(
            'No se puede eliminar: el contrato tiene ' . implode(' y ', $partes) .
            '. Reasignalos o anulalos antes de borrarlo.',
            409
        );
    }

    try {
        $pdo->beginTransaction();

        // `dominios`.`contrato` es SET NULL en la base, pero se hace explicito:
        // asi el numero que informa la respuesta es el que realmente se toco y
        // no depende del motor (prod es MariaDB, dev es MySQL).
        $desv = $pdo->prepare('UPDATE dominios SET contrato = NULL WHERE contrato = :id');
        $desv->execute([':id' => $id]);
        $dominios = $desv->rowCount();

        $pdo->prepare('DELETE FROM contratos WHERE id = :id')->execute([':id' => $id]);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    json_ok(['id' => $id, 'dominios' => $dominios]);
}

/* ---------------------------------------------------------------- helpers */

/** Cantidad de filas que dependen del contrato, por tabla. */
function dependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM comprobantes WHERE contrato = :a) AS comprobantes,
                (SELECT COUNT(*) FROM pagos        WHERE contrato = :b) AS pagos,
                (SELECT COUNT(*) FROM dominios     WHERE contrato = :c) AS dominios'
    );
    $stmt->execute([':a' => $id, ':b' => $id, ':c' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'comprobantes' => (int) ($r['comprobantes'] ?? 0),
        'pagos'        => (int) ($r['pagos']        ?? 0),
        'dominios'     => (int) ($r['dominios']     ?? 0),
    ];
}

/**
 * Valida y normaliza el payload. `$id` es null en el alta y el id propio en la
 * edicion (para que el chequeo de uuid unico no choque contra si mismo).
 *
 * `promo` NO se lee del payload a proposito: ver PROMO en la cabecera.
 */
function validarContrato(array $in, ?int $id): array
{
    $out = [];

    // uuid: 8 digitos exactos, el formato que tienen las 50 filas y el que
    // genera el legacy. Se valida con ctype_digit() y no con una expresion
    // regular: `/^[0-9]+$/` da por buena una cadena terminada en salto de
    // linea (mismo criterio que el celular de las invitaciones).
    $uuid = trim((string) ($in['uuid'] ?? ''));
    if ($uuid === '') {
        $out['uuid'] = null;                       // alta: se genera; edicion: se rechaza
    } else {
        if (strlen($uuid) !== 8 || !ctype_digit($uuid)) {
            json_error('El identificador debe tener exactamente 8 digitos', 422);
        }
        // Es la credencial del estado de cuenta publico: dos contratos con el
        // mismo uuid dejarian a un cliente viendo la cuenta del otro. La base
        // no lo impide (no hay UNIQUE en la columna), asi que lo impide el ABM.
        $dup = db()->prepare('SELECT id FROM contratos WHERE uuid = :u AND id <> :id');
        $dup->execute([':u' => $uuid, ':id' => $id ?? 0]);
        if ($otro = $dup->fetchColumn()) {
            json_error("El identificador {$uuid} ya lo usa el contrato #{$otro}", 409);
        }
        $out['uuid'] = $uuid;
    }

    $out['cliente'] = fkOpcional($in['cliente'] ?? null, 'clientes', 'El cliente');
    $out['dominio'] = fkOpcional($in['dominio'] ?? null, 'dominios', 'El dominio');
    $out['plan']    = fkOpcional($in['plan']    ?? null, 'planes',   'El plan');

    // tipo: varchar(3), y ademas tiene que ser uno de los del combo. Un tipo
    // fuera del catalogo se veria como el codigo pelado en toda pantalla que
    // lo traduzca -- incluido el back office viejo.
    $tipo = trim((string) ($in['tipo'] ?? ''));
    if ($tipo !== '') {
        if (mb_strlen($tipo) > 3) json_error('El tipo no puede superar 3 caracteres', 422);
        $tipos = combo(COMBO_TIPO);
        if ($tipos !== [] && !isset($tipos[$tipo])) {
            json_error("El tipo \"{$tipo}\" no esta en el catalogo", 422);
        }
    }
    $out['tipo'] = $tipo === '' ? null : $tipo;

    // remitir: varchar(1) con dos valores, igual que una bandera `habilitado`
    // pero guardada como texto. Se normaliza a '1' / '0' y nada mas.
    $remitir = trim((string) ($in['remitir'] ?? ''));
    $out['remitir'] = $remitir === '' ? null : (valorHabilitado($remitir) === HABILITADO ? '1' : '0');

    foreach (CAMPOS_FECHA as $campo) {
        $out[$campo] = fechaEntrada($in[$campo] ?? null, $campo);
    }
    foreach (CAMPOS_FECHAHORA as $campo) {
        $out[$campo] = fechaHoraEntrada($in[$campo] ?? null, $campo);
    }

    // `habilitado` es tinyint(1) NOT NULL: siempre va el entero (CLAUDE.md).
    $out['habilitado'] = valorHabilitado($in['habilitado'] ?? DESHABILITADO);

    return $out;
}

/** Parametros del INSERT / UPDATE, en el orden del esquema. */
function bindContrato(array $d): array
{
    return [
        ':uuid'       => $d['uuid'],
        ':cliente'    => $d['cliente'],
        ':dominio'    => $d['dominio'],
        ':tipo'       => $d['tipo'],
        ':plan'       => $d['plan'],
        ':desde'      => $d['desde'],
        ':hasta'      => $d['hasta'],
        ':registro'   => $d['registro'],
        ':firma'      => $d['firma'],
        ':alta'       => $d['alta'],
        ':baja'       => $d['baja'],
        ':facturado'  => $d['facturado'],
        ':facturar'   => $d['facturar'],
        ':tolerancia' => $d['tolerancia'],
        ':remitir'    => $d['remitir'],
        ':remitido'   => $d['remitido'],
        ':habilitado' => $d['habilitado'],
    ];
}

/**
 * Id de una FK opcional. Ademas de normalizar el 0 centinela a null, verifica
 * que la fila exista: las tres son FK RESTRICT y un id inventado devolveria un
 * 500 del motor en vez de decir cual campo esta mal.
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

/** Un centinela leido de la base sale como null ("sin fecha"). */
function fechaSalida(mixed $valor, string $campo): ?string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '' || $v === '0000-00-00') return null;

    return $v === (CENTINELA_FECHA[$campo] ?? FECHA_GENESIS) ? null : $v;
}

/** Ídem para las tres columnas datetime, cuyo "sin fecha" es el genesis. */
function fechaHoraSalida(mixed $valor): ?string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '' || str_starts_with($v, '0000-00-00')) return null;

    return str_starts_with($v, FECHA_GENESIS) ? null : $v;
}

/**
 * Un campo de fecha vacio vuelve a guardarse como el centinela de su columna,
 * no como NULL: es lo que tiene el 100% de los datos y lo que siguen escribiendo
 * el alta y las rutinas del sistema historico.
 */
function fechaEntrada(mixed $valor, string $campo): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return CENTINELA_FECHA[$campo] ?? FECHA_GENESIS;

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) {
        json_error("La fecha de \"{$campo}\" no es valida (formato AAAA-MM-DD)", 422);
    }

    return $v;
}

/**
 * Ídem para datetime. El input `datetime-local` del navegador manda
 * `AAAA-MM-DDTHH:MM` (sin segundos), asi que se aceptan las dos formas y se
 * normaliza a la que guarda la base.
 */
function fechaHoraEntrada(mixed $valor, string $campo): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return FECHA_GENESIS . ' 00:00:00';

    $v = str_replace('T', ' ', $v);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $v)) $v .= ':00';

    $d = DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $v);
    if ($d === false || $d->format('Y-m-d H:i:s') !== $v) {
        json_error("La fecha y hora de \"{$campo}\" no es valida (formato AAAA-MM-DD HH:MM)", 422);
    }

    return $v;
}

/** 8 digitos que no este usando ningun otro contrato. */
function uuidLibre(): string
{
    $stmt = db()->prepare('SELECT 1 FROM contratos WHERE uuid = :u');
    for ($intento = 0; $intento < 20; $intento++) {
        $uuid = str_pad((string) random_int(0, 99999999), 8, '0', STR_PAD_LEFT);
        $stmt->execute([':u' => $uuid]);
        if (!$stmt->fetchColumn()) return $uuid;
    }
    json_error('No se pudo generar un identificador libre, reintenta', 500);
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
