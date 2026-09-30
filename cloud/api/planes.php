<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

/**
 * ABM de `planes` (db/schema.sql).
 *
 * Un plan es lo que un dominio tiene contratado: cuanto puede usar y cuanto
 * paga por eso. `contratos`.`plan` y `utilizaciones`.`plan` cuelgan de aca.
 *
 * TODAS las columnas son editables menos `id`, que es el AUTO_INCREMENT.
 *
 * EL PRECIO DEL PLAN NO ESTA EN ESTA TABLA: esta en `articulos`. `planes`.
 * `articulo` apunta al articulo que se factura, y el abono es su `venta` --
 * `cContrato::facturar()` lo cobra de ahi. Por eso el listado y la ficha
 * muestran el abono como un dato DEL ARTICULO, con su id a la vista y el enlace
 * al modulo Articulos: cambiarlo se hace alla, no aca, y ahi es donde un cambio
 * de cotizacion mueve la plata de todos los contratos que cobran este plan.
 *
 * `-1` SIGNIFICA ILIMITADO en `usuarios`, `dispositivos` y `usos`, y no es un
 * centinela raro: es lo que escribe `cPlan::nuevo()` en las tres columnas y lo
 * que tienen 31 de los 33 planes en al menos una. Se guarda tal cual --
 * traducirlo a NULL estrenaria una segunda forma de decir lo mismo y romperia
 * `cPlan::detectar()`, que compara `<= usuarios` contra la columna --, pero se
 * MUESTRA como "Ilimitado": un `-1` en una grilla de cupos se lee como un error
 * de carga.
 *
 * `tipo` TIENE ONCE FILAS CON CADENA VACIA. Son los planes viejos (Edificio /
 * Ciudad / Casa Inteligente, Anonimo, Reactor Ilimitado), todos deshabilitados
 * menos por los contratos que siguen colgados de ellos -- el 118 tiene 9. El
 * select lleva opcion "Sin tipo" por eso: sin ella el navegador caeria en la
 * primera y guardar les cambiaria el tipo en silencio.
 *
 * BORRAR. Las dos FK que apuntan aca son RESTRICT y las dos BLOQUEAN:
 * `contratos`.`plan` (50 filas) porque es el acuerdo comercial vigente de un
 * dominio, y `utilizaciones`.`plan` (98) porque es el historico de consumo. No
 * hay nada que se borre en cascada ni que se desvincule solo. El desglose lo
 * sirve `?impacto=1&id=N` y el DELETE repite las validaciones (ABM.md,
 * "Eliminar").
 */

/** Claves de `combos` con los textos de los codigos cortos de `planes`. */
const COMBO_TIPO = '$xPlan->tipo';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_TIPO => ['S' => 'Standard', 'T' => 'Telemetry', 'D' => 'Developer'],
];

/** El valor que significa "sin tope" en usuarios / dispositivos / usos. */
const ILIMITADO = -1;

/** Largos de las columnas de texto, para cortar antes que el motor. */
const LARGO_NOMBRE      = 255;
const LARGO_DESCRIPCION = 255;

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
    json_error('Error al procesar planes: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // La fila entera, los datos del articulo que le pone precio y los dos
    // contadores que gobiernan el borrado. Subconsultas correlacionadas y no
    // LEFT JOIN para los contadores: con JOINs las filas se multiplican entre si
    // y cada COUNT devolveria el producto (mismo criterio que api/talonarios.php
    // y api/articulos.php).
    //
    // Busqueda por texto libre: la resuelve la BASE y no el navegador
    // (`ABM.md`, "Como busca el texto libre"). Van las columnas REALES y no los
    // alias del SELECT, y son exactamente las que anuncia el `placeholder`.
    $q = trim((string) ($_GET['q'] ?? ''));
    [$condiciones, $busq] = busquedaWhere($q, [
        'p.nombre', 'p.descripcion', 'a.nombre',
    ]);

    $stmt = db()->prepare(
        'SELECT p.id,
                p.tipo,
                p.nombre,
                p.descripcion,
                p.habilitado,
                p.articulo,
                a.nombre      AS articulo_nombre,
                a.venta       AS articulo_venta,
                a.importacion AS articulo_importacion,
                a.moneda      AS articulo_moneda,
                a.habilitado  AS articulo_habilitado,
                p.usuarios,
                p.dispositivos,
                p.usos,
                p.orden,
                (SELECT COUNT(*) FROM contratos     c WHERE c.plan = p.id) AS contratos_count,
                (SELECT COUNT(*) FROM utilizaciones u WHERE u.plan = p.id) AS utilizaciones_count
         FROM planes p
         LEFT JOIN articulos a ON a.id = p.articulo'
        . ($condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '') . '
         ORDER BY p.orden ASC, p.id ASC'
    );
    $stmt->execute($busq);

    $planes = array_map(static function (array $r): array {
        $tipo = trim((string) ($r['tipo'] ?? ''));

        return [
            'id'                   => (int) $r['id'],
            'tipo'                 => $tipo,
            'tipo_texto'           => combo(COMBO_TIPO)[$tipo] ?? '',
            'nombre'               => trim((string) ($r['nombre']      ?? '')),
            'descripcion'          => trim((string) ($r['descripcion'] ?? '')),
            'habilitado'           => esHabilitado($r['habilitado']) ? 1 : 0,
            // El 0 de la FK es el centinela "sin asignar" del sistema historico,
            // no un id: se normaliza a null como el NULL real.
            'articulo'             => idOrNull($r['articulo']),
            'articulo_nombre'      => trim((string) ($r['articulo_nombre'] ?? '')),
            // El abono: `articulos`.`venta`, que es lo que cobra
            // `cContrato::facturar()`. null cuando el plan no tiene articulo.
            'abono'                => $r['articulo_venta']       === null ? null : (float) $r['articulo_venta'],
            'articulo_importacion' => $r['articulo_importacion'] === null ? null : (float) $r['articulo_importacion'],
            'articulo_moneda'      => trim((string) ($r['articulo_moneda'] ?? '')),
            'articulo_habilitado'  => $r['articulo_habilitado'] === null ? null : (esHabilitado($r['articulo_habilitado']) ? 1 : 0),
            // Los tres cupos van crudos: el `-1` lo traduce la pantalla, no el
            // backend, porque es el valor que despues vuelve en el formulario.
            'usuarios'             => (int) $r['usuarios'],
            'dispositivos'         => (int) $r['dispositivos'],
            'usos'                 => (int) $r['usos'],
            'orden'                => (int) $r['orden'],
            'contratos_count'      => (int) $r['contratos_count'],
            'utilizaciones_count'  => (int) $r['utilizaciones_count'],
        ];
    }, $stmt->fetchAll());

    $resumen = [
        'total'          => count($planes),
        'habilitados'    => 0,
        'deshabilitados' => 0,
        'contratados'    => 0,
    ];
    foreach ($planes as $p) {
        if ($p['habilitado'] === 1) $resumen['habilitados']++;
        else                        $resumen['deshabilitados']++;
        if ($p['contratos_count'] > 0) $resumen['contratados']++;
    }

    json_ok([
        'resumen'   => $resumen,
        'planes'    => $planes,
        'catalogos' => catalogos(),
    ]);
}

/**
 * Catalogos de los desplegables del modal de Alta/Edicion y de Filtros.
 *
 * `articulos` lista TODOS, no sólo los habilitados: hay planes vivos cuyo
 * articulo esta deshabilitado y con el filtro puesto abrir uno y guardarlo lo
 * dejaria sin articulo -- o sea sin abono. Es el mismo criterio con el que
 * Contratos lista todos los planes.
 */
function catalogos(): array
{
    $articulos = array_map(static fn(array $r): array => [
        'id'         => (int) $r['id'],
        'nombre'     => trim((string) ($r['nombre'] ?? '')),
        'venta'      => (float) $r['venta'],
        'habilitado' => esHabilitado($r['habilitado']) ? 1 : 0,
    ], db()->query('SELECT id, nombre, venta, habilitado FROM articulos ORDER BY nombre ASC, id ASC')->fetchAll());

    return [
        'articulos' => $articulos,
        'tipos'     => comboLista(COMBO_TIPO),
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * Las dos dependencias BLOQUEAN: son FK RESTRICT y ademas cosas que este
 * endpoint no tiene por que resolver solo -- el contrato vigente de un dominio y
 * el historico de consumo. No hay nada que se elimine en cascada ni que se
 * desvincule.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre FROM planes WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $plan = $stmt->fetch();
    if (!$plan) json_error('Plan no encontrado', 404);

    $deps = dependencias($id);

    $bloqueos = [];
    if ($deps['contratos'] > 0) {
        $bloqueos[] = ['label' => 'Contratos que tienen este plan', 'cantidad' => $deps['contratos']];
    }
    if ($deps['utilizaciones'] > 0) {
        $bloqueos[] = ['label' => 'Utilizaciones registradas contra este plan', 'cantidad' => $deps['utilizaciones']];
    }

    json_ok([
        'plan' => [
            'id'     => (int) $plan['id'],
            'nombre' => trim((string) ($plan['nombre'] ?? '')),
        ],
        'bloqueos' => $bloqueos,
        // Las dos claves viajan vacias porque el modal compartido las lee: de un
        // plan no cuelga nada que se borre ni que quede sin referencia.
        'elimina'    => [],
        'desvincula' => [],
    ]);
}

function handleCreate(): void
{
    $data = validarPlan(readJson());

    $stmt = db()->prepare(
        'INSERT INTO planes
             (tipo, nombre, descripcion, habilitado, articulo, usuarios, dispositivos, usos, orden)
         VALUES
             (:tipo, :nombre, :descripcion, :habilitado, :articulo, :usuarios, :dispositivos, :usos, :orden)'
    );
    $stmt->execute(bindPlan($data));

    json_ok(['id' => (int) db()->lastInsertId(), 'nombre' => $data['nombre']], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $previo = db()->prepare('SELECT tipo FROM planes WHERE id = :id');
    $previo->execute([':id' => $id]);
    $fila = $previo->fetch();
    if (!$fila) json_error('Plan no encontrado', 404);

    $data = validarPlan($in, $fila);

    $stmt = db()->prepare(
        'UPDATE planes
            SET tipo         = :tipo,
                nombre       = :nombre,
                descripcion  = :descripcion,
                habilitado   = :habilitado,
                articulo     = :articulo,
                usuarios     = :usuarios,
                dispositivos = :dispositivos,
                usos         = :usos,
                orden        = :orden
          WHERE id = :id'
    );
    $stmt->execute(bindPlan($data) + [':id' => $id]);

    json_ok(['id' => $id, 'nombre' => $data['nombre']]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM planes WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Plan no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite las
    // validaciones por su cuenta (ABM.md, "Eliminar").
    $deps = dependencias($id);

    $partes = [];
    if ($deps['contratos'] > 0)     $partes[] = "{$deps['contratos']} contrato(s)";
    if ($deps['utilizaciones'] > 0) $partes[] = "{$deps['utilizaciones']} utilizacion(es)";

    if ($partes !== []) {
        json_error(
            'No se puede eliminar: el plan tiene ' . implode(' y ', $partes) .
            '. Reasignalos a otro plan antes de borrarlo.',
            409
        );
    }

    $stmt = db()->prepare('DELETE FROM planes WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_ok(['id' => $id]);
}

/* ---------------------------------------------------------------- helpers */

/** Cantidad de filas que dependen del plan, por tabla. */
function dependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM contratos     WHERE plan = :a) AS contratos,
                (SELECT COUNT(*) FROM utilizaciones WHERE plan = :b) AS utilizaciones'
    );
    $stmt->execute([':a' => $id, ':b' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'contratos'     => (int) ($r['contratos']     ?? 0),
        'utilizaciones' => (int) ($r['utilizaciones'] ?? 0),
    ];
}

/**
 * Valida y normaliza el payload.
 *
 * `$previo` es null en el alta y la fila actual en la edicion: se usa para
 * aceptar el `tipo` que la fila YA TENIA aunque no este en el catalogo, que es
 * el mismo trato que `talonarios`.`tipo` le da al talonario 35. Sin eso, una
 * fila vieja quedaria imposible de guardar -- nadie podria corregirle ni el
 * nombre -- y la validacion, que existe para que no ENTREN codigos nuevos fuera
 * del catalogo, terminaria bloqueando uno que ya esta facturando.
 */
function validarPlan(array $in, ?array $previo = null): array
{
    $out = [];

    $out['tipo']        = codigoDeCombo($in['tipo'] ?? null, COMBO_TIPO, 'El tipo', $previo['tipo'] ?? null);
    $out['nombre']      = textoObligatorio($in['nombre'] ?? null, LARGO_NOMBRE, 'El nombre');
    $out['descripcion'] = textoLimitado($in['descripcion'] ?? null, LARGO_DESCRIPCION, 'La descripcion');

    $out['articulo'] = fkOpcional($in['articulo'] ?? null, 'articulos', 'El articulo');

    // Los tres cupos admiten -1 (ilimitado) y cualquier entero >= 0. Un -2 no
    // significa nada: se corta en vez de guardarse como un tope negativo que
    // `cPlan::detectar()` leeria como "ningun usuario entra".
    $out['usuarios']     = cupo($in['usuarios']     ?? null, 'El cupo de usuarios');
    $out['dispositivos'] = cupo($in['dispositivos'] ?? null, 'El cupo de dispositivos');
    $out['usos']         = cupo($in['usos']         ?? null, 'El cupo de usos');

    $out['orden'] = enteroNoNegativo($in['orden'] ?? null, 'El orden');

    $out['habilitado'] = valorHabilitado($in['habilitado'] ?? DESHABILITADO);

    return $out;
}

/** Parametros del INSERT / UPDATE, en el orden del esquema. */
function bindPlan(array $d): array
{
    return [
        ':tipo'         => $d['tipo'],
        ':nombre'       => $d['nombre'],
        ':descripcion'  => $d['descripcion'],
        ':habilitado'   => $d['habilitado'],
        ':articulo'     => $d['articulo'],
        ':usuarios'     => $d['usuarios'],
        ':dispositivos' => $d['dispositivos'],
        ':usos'         => $d['usos'],
        ':orden'        => $d['orden'],
    ];
}

/**
 * Codigo corto que tiene que estar en su combo. Vacio => cadena vacia.
 *
 * NO devuelve null: las 33 filas usan la cadena vacia y ninguna tiene NULL, asi
 * que mandar NULL estrenaria una segunda forma de decir lo mismo (mismo criterio
 * que `talonarios`.`terminos`).
 *
 * `$heredado` es el valor que la fila ya tenia: se acepta siempre. Un alta no
 * lo tiene, asi que ahi el catalogo se exige entero.
 */
function codigoDeCombo(mixed $valor, string $clave, string $rotulo, ?string $heredado): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return '';

    if (mb_strlen($v) > 1) json_error("{$rotulo} tiene que ser un codigo de una letra", 422);

    if ($heredado !== null && $v === trim($heredado)) return $v;

    $catalogo = combo($clave);
    if ($catalogo !== [] && !isset($catalogo[$v])) {
        json_error("{$rotulo} \"{$v}\" no esta en el catalogo", 422);
    }

    return $v;
}

function textoObligatorio(mixed $valor, int $max, string $rotulo): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '')            json_error("{$rotulo} es obligatorio", 422);
    if (mb_strlen($v) > $max) json_error("{$rotulo} no puede superar {$max} caracteres", 422);

    return $v;
}

function textoLimitado(mixed $valor, int $max, string $rotulo): string
{
    $v = trim((string) ($valor ?? ''));
    if (mb_strlen($v) > $max) json_error("{$rotulo} no puede superar {$max} caracteres", 422);

    return $v;
}

/** Cupo: -1 (ilimitado) o cualquier entero de 0 para arriba. */
function cupo(mixed $valor, string $rotulo): int
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return ILIMITADO;

    if (!preg_match('/^-?\d+$/', $v)) json_error("{$rotulo} tiene que ser un numero entero", 422);

    $n = (int) $v;
    if ($n < ILIMITADO)  json_error("{$rotulo} sólo admite -1 (ilimitado) o un numero de 0 para arriba", 422);
    if ($n > 2147483647) json_error("{$rotulo} esta fuera de rango", 422);

    return $n;
}

function enteroNoNegativo(mixed $valor, string $rotulo): int
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0;

    if (!ctype_digit($v)) json_error("{$rotulo} tiene que ser un numero entero de 0 o mas", 422);

    $n = (int) $v;
    if ($n > 2147483647) json_error("{$rotulo} esta fuera de rango", 422);

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
 * Id de una FK del sistema historico, donde el 0 significa "sin asignar" igual
 * que el NULL (ver el criterio del `0` centinela en `db/schema.sql`).
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
