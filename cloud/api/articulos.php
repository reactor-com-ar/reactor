<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/articulos_lib.php';

/**
 * ABM de `articulos` (db/schema.sql).
 *
 * Un articulo es todo lo que Reactor vende o consume: productos, servicios y
 * componentes. Es el catalogo del que cuelga la plata del sistema --
 * `planes`.`articulo` (el abono de cada plan), `comprobantesrenglones`.
 * `articulo` (lo que se factura), `chips`.`articulo` y `carritositems`.
 * `articulo` (la tienda publica).
 *
 * TODAS las columnas son editables menos tres:
 *
 *   - `id`, que es el AUTO_INCREMENT.
 *   - `venta`, que es DERIVADA SIEMPRE.
 *   - `compra`, que es derivada cuando `moneda = 'D'`.
 *
 * LOS PRECIOS SE RECALCULAN EN CADA GUARDADO, y eso es el comportamiento del
 * sistema historico, no un agregado: `articulos/editar.php` llama a
 * `recalcular()` antes de `agregar()` y de `modificar()`. La cuenta esta en
 * `articulos_lib.php` y se aplica igual desde el ABM y desde
 * `articulos_accion.php?accion=recalcular`.
 *
 * La consecuencia hay que decirla porque sorprende: corregirle el nombre a un
 * articulo en dolares le actualiza el precio en pesos a la cotizacion de hoy.
 * Es la lectura correcta de la columna --`compra` en pesos ES `importacion` por
 * la cotizacion-- pero el formulario lo muestra antes de guardar en vez de
 * dejar que el operador se entere despues, que es lo que hace el back office
 * viejo.
 *
 * VISIBILIDAD NO ES `habilitado`. Son dos columnas y dos preguntas distintas:
 * `habilitado` dice si el articulo se puede usar (95 de 106) y `visibilidad`
 * si ademas se publica en la tienda (38). Un articulo publico y deshabilitado
 * es posible y no es un error de datos. `visibilidad` es `varchar(10)` con los
 * codigos '0'/'1' de `combos`, asi que NO se toca con `esHabilitado()` /
 * `valorHabilitado()` -- se compara como string, igual que `aprobacion` en
 * Tecnicos.
 *
 * BORRAR. Las cuatro FK que apuntan aca son RESTRICT y las cuatro BLOQUEAN:
 * `planes` (31), `chips` (20), `comprobantesrenglones` (3.135) y
 * `carritositems` (1). No hay nada que se borre en cascada ni que se desvincule
 * solo. El desglose lo sirve `?impacto=1&id=N` y el DELETE repite las
 * validaciones (ABM.md, "Eliminar").
 */

/** Largos de las columnas de texto, para cortar antes que el motor. */
const LARGO_NOMBRE      = 100;
const LARGO_MARCA       = 100;
const LARGO_SKU         = 50;
const LARGO_EAN         = 50;
const LARGO_WEB         = 255;
const LARGO_TEXTO_LARGO = 65535;

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
    json_error('Error al procesar articulos: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    // La fila entera, el nombre de la categoria y los cuatro contadores que
    // gobiernan el borrado. Subconsultas correlacionadas y no LEFT JOIN: con
    // JOINs las filas se multiplican entre si y cada COUNT devolveria el
    // producto (mismo criterio que api/talonarios.php y api/dominios.php). Las
    // cuatro columnas contadas tienen indice -- lo trae su FK.
    //
    // Busqueda por texto libre: la resuelve la BASE y no el navegador
    // (`ABM.md`, "Como busca el texto libre"). Van las columnas REALES y no los
    // alias del SELECT, y son exactamente las que anuncia el `placeholder`.
    $q = trim((string) ($_GET['q'] ?? ''));
    [$condiciones, $busq] = busquedaWhere($q, [
        'a.nombre', 'a.marca', 'a.sku', 'a.ean', 'ac.nombre',
    ]);

    $stmt = db()->prepare(
        'SELECT a.id,
                a.tipo,
                a.categoria,
                ac.nombre    AS categoria_nombre,
                ac.jerarquia AS categoria_jerarquia,
                a.marca,
                a.nombre,
                a.descripcion,
                a.metadatos,
                a.sku,
                a.ean,
                a.actual,
                a.minimo,
                a.recomendado,
                a.iva,
                a.moneda,
                a.importacion,
                a.compra,
                a.margen,
                a.venta,
                a.web,
                a.visibilidad,
                a.habilitado,
                (SELECT COUNT(*) FROM planes                p  WHERE p.articulo  = a.id) AS planes_count,
                (SELECT COUNT(*) FROM chips                 c  WHERE c.articulo  = a.id) AS chips_count,
                (SELECT COUNT(*) FROM comprobantesrenglones cr WHERE cr.articulo = a.id) AS renglones_count,
                (SELECT COUNT(*) FROM carritositems         ci WHERE ci.articulo = a.id) AS carritos_count
         FROM articulos a
         LEFT JOIN articuloscategorias ac ON ac.id = a.categoria'
        . ($condiciones ? ' WHERE ' . implode(' AND ', $condiciones) : '') . '
         ORDER BY a.id DESC'
    );
    $stmt->execute($busq);

    $cotizacion = cotizacionDolar();

    $articulos = array_map(static function (array $r): array {
        $tipo        = trim((string) ($r['tipo']        ?? ''));
        $moneda      = trim((string) ($r['moneda']      ?? ''));
        $visibilidad = trim((string) ($r['visibilidad'] ?? ''));

        $importacion = (float) $r['importacion'];
        $compra      = (float) $r['compra'];
        $margen      = (float) $r['margen'];
        $venta       = (float) $r['venta'];

        // Lo que quedaria si se recalculara ahora. Viaja en el listado para que
        // la ficha pueda decir si la fila esta al dia con la cotizacion vigente
        // sin pedir un segundo endpoint: las filas en dolares arrastran la
        // cotizacion del dia en que se las toco por ultima vez, no la de hoy.
        $recalculado = articuloPrecios($moneda, $importacion, $compra, $margen);

        return [
            'id'                  => (int) $r['id'],
            'tipo'                => $tipo,
            'tipo_texto'          => combo(COMBO_TIPO)[$tipo] ?? '',
            // El 0 de la FK es el centinela "sin asignar" del sistema historico,
            // no un id: se normaliza a null como el NULL real.
            'categoria'           => idOrNull($r['categoria']),
            'categoria_nombre'    => trim((string) ($r['categoria_nombre']    ?? '')),
            'categoria_jerarquia' => trim((string) ($r['categoria_jerarquia'] ?? '')),
            'marca'               => trim((string) ($r['marca']       ?? '')),
            'nombre'              => trim((string) ($r['nombre']      ?? '')),
            'descripcion'         => (string) ($r['descripcion'] ?? ''),
            'metadatos'           => (string) ($r['metadatos']   ?? ''),
            'sku'                 => trim((string) ($r['sku'] ?? '')),
            'ean'                 => trim((string) ($r['ean'] ?? '')),
            'actual'              => (int) $r['actual'],
            'minimo'              => (int) $r['minimo'],
            'recomendado'         => (int) $r['recomendado'],
            'iva'                 => (float) $r['iva'],
            'moneda'              => $moneda,
            'moneda_texto'        => combo(COMBO_MONEDA)[$moneda] ?? '',
            'importacion'         => $importacion,
            'compra'              => $compra,
            'margen'              => $margen,
            'venta'               => $venta,
            'compra_recalculada'  => $recalculado['compra'],
            'venta_recalculada'   => $recalculado['venta'],
            // Con una diferencia de mas de un centavo la fila quedo con la
            // cotizacion de otro dia. Se decide con la resta y no comparando
            // cotizaciones: un articulo en pesos nunca esta desalineado.
            'desalineado'         => abs($recalculado['compra'] - $compra) > 0.01
                                  || abs($recalculado['venta']  - $venta)  > 0.01,
            'web'                 => trim((string) ($r['web'] ?? '')),
            'visibilidad'         => $visibilidad,
            'visibilidad_texto'   => combo(COMBO_VISIBILIDAD)[$visibilidad] ?? '',
            'habilitado'          => esHabilitado($r['habilitado']) ? 1 : 0,
            'planes_count'        => (int) $r['planes_count'],
            'chips_count'         => (int) $r['chips_count'],
            'renglones_count'     => (int) $r['renglones_count'],
            'carritos_count'      => (int) $r['carritos_count'],
        ];
    }, $stmt->fetchAll());

    $resumen = [
        'total'          => count($articulos),
        'habilitados'    => 0,
        'deshabilitados' => 0,
        'publicos'       => 0,
        'desalineados'   => 0,
        'cotizacion'     => $cotizacion,
        'cotizacion_fecha' => parametroValor(PARAMETRO_COTIZACION_FECHA),
    ];
    foreach ($articulos as $a) {
        if ($a['habilitado'] === 1) $resumen['habilitados']++;
        else                        $resumen['deshabilitados']++;
        if ($a['visibilidad'] === '1') $resumen['publicos']++;
        if ($a['desalineado'])         $resumen['desalineados']++;
    }

    json_ok([
        'resumen'   => $resumen,
        'articulos' => $articulos,
        'catalogos' => catalogos(),
    ]);
}

/** Catalogos de los desplegables del modal de Alta/Edicion y de Filtros. */
function catalogos(): array
{
    // Por jerarquia y no por nombre: la columna es el arbol del catalogo
    // ("001.002" cuelga de "001"), asi que ordenarla deja los hijos debajo de
    // su padre. Hoy son 7 filas.
    $categorias = array_map(static fn(array $r): array => [
        'id'        => (int) $r['id'],
        'nombre'    => trim((string) ($r['nombre']    ?? '')),
        'jerarquia' => trim((string) ($r['jerarquia'] ?? '')),
    ], db()->query('SELECT id, jerarquia, nombre FROM articuloscategorias ORDER BY jerarquia ASC, nombre ASC, id ASC')->fetchAll());

    return [
        'categorias'   => $categorias,
        'tipos'        => comboLista(COMBO_TIPO),
        'monedas'      => comboLista(COMBO_MONEDA),
        'visibilidad'  => comboLista(COMBO_VISIBILIDAD),
        'cotizacion'   => cotizacionDolar(),
        'cotizacion_fecha' => parametroValor(PARAMETRO_COTIZACION_FECHA),
    ];
}

/**
 * Desglose del borrado (ABM.md, "Eliminar"; DESIGN.md §15.1).
 *
 * Las cuatro dependencias BLOQUEAN: son FK RESTRICT y ademas cosas que este
 * endpoint no tiene por que resolver solo -- renglones de comprobantes ya
 * emitidos, el abono de un plan contratado, el articulo con el que se factura un
 * chip. No hay nada que se elimine en cascada ni que se desvincule.
 */
function handleImpacto(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $stmt = db()->prepare('SELECT id, nombre FROM articulos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $art = $stmt->fetch();
    if (!$art) json_error('Articulo no encontrado', 404);

    $deps = dependencias($id);

    $bloqueos = [];
    if ($deps['planes'] > 0) {
        $bloqueos[] = ['label' => 'Planes que lo facturan como abono', 'cantidad' => $deps['planes']];
    }
    if ($deps['renglones'] > 0) {
        $bloqueos[] = ['label' => 'Renglones de comprobantes emitidos', 'cantidad' => $deps['renglones']];
    }
    if ($deps['chips'] > 0) {
        $bloqueos[] = ['label' => 'Chips que se facturan con este articulo', 'cantidad' => $deps['chips']];
    }
    if ($deps['carritos'] > 0) {
        $bloqueos[] = ['label' => 'Items en carritos de la tienda', 'cantidad' => $deps['carritos']];
    }

    json_ok([
        'articulo' => [
            'id'     => (int) $art['id'],
            'nombre' => trim((string) ($art['nombre'] ?? '')),
        ],
        'bloqueos' => $bloqueos,
        // Las dos claves viajan vacias porque el modal compartido las lee: de un
        // articulo no cuelga nada que se borre ni que quede sin referencia.
        'elimina'    => [],
        'desvincula' => [],
    ]);
}

function handleCreate(): void
{
    $data = validarArticulo(readJson());

    $stmt = db()->prepare(
        'INSERT INTO articulos
             (tipo, categoria, marca, nombre, descripcion, metadatos, sku, ean,
              actual, minimo, recomendado, iva, moneda, importacion, compra,
              margen, venta, web, visibilidad, habilitado)
         VALUES
             (:tipo, :categoria, :marca, :nombre, :descripcion, :metadatos, :sku, :ean,
              :actual, :minimo, :recomendado, :iva, :moneda, :importacion, :compra,
              :margen, :venta, :web, :visibilidad, :habilitado)'
    );
    $stmt->execute(bindArticulo($data));

    json_ok([
        'id'     => (int) db()->lastInsertId(),
        'nombre' => $data['nombre'],
        'compra' => $data['compra'],
        'venta'  => $data['venta'],
    ], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $actual = db()->prepare('SELECT tipo, moneda, visibilidad FROM articulos WHERE id = :id');
    $actual->execute([':id' => $id]);
    $previo = $actual->fetch();
    if (!$previo) json_error('Articulo no encontrado', 404);

    $data = validarArticulo($in, $previo);

    $stmt = db()->prepare(
        'UPDATE articulos
            SET tipo        = :tipo,
                categoria   = :categoria,
                marca       = :marca,
                nombre      = :nombre,
                descripcion = :descripcion,
                metadatos   = :metadatos,
                sku         = :sku,
                ean         = :ean,
                actual      = :actual,
                minimo      = :minimo,
                recomendado = :recomendado,
                iva         = :iva,
                moneda      = :moneda,
                importacion = :importacion,
                compra      = :compra,
                margen      = :margen,
                venta       = :venta,
                web         = :web,
                visibilidad = :visibilidad,
                habilitado  = :habilitado
          WHERE id = :id'
    );
    $stmt->execute(bindArticulo($data) + [':id' => $id]);

    json_ok([
        'id'     => $id,
        'nombre' => $data['nombre'],
        'compra' => $data['compra'],
        'venta'  => $data['venta'],
    ]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $existe = db()->prepare('SELECT 1 FROM articulos WHERE id = :id');
    $existe->execute([':id' => $id]);
    if (!$existe->fetchColumn()) json_error('Articulo no encontrado', 404);

    // El desglose de ?impacto=1 es informativo: el DELETE repite las
    // validaciones por su cuenta (ABM.md, "Eliminar").
    $deps = dependencias($id);

    $partes = [];
    if ($deps['planes'] > 0)    $partes[] = "{$deps['planes']} plan(es)";
    if ($deps['renglones'] > 0) $partes[] = "{$deps['renglones']} renglon(es) de comprobante";
    if ($deps['chips'] > 0)     $partes[] = "{$deps['chips']} chip(s)";
    if ($deps['carritos'] > 0)  $partes[] = "{$deps['carritos']} item(s) de carrito";

    if ($partes !== []) {
        json_error(
            'No se puede eliminar: el articulo tiene ' . implode(', ', $partes) .
            '. Reasignalos a otro articulo antes de borrarlo.',
            409
        );
    }

    $stmt = db()->prepare('DELETE FROM articulos WHERE id = :id');
    $stmt->execute([':id' => $id]);

    json_ok(['id' => $id]);
}

/* ---------------------------------------------------------------- helpers */

/** Cantidad de filas que dependen del articulo, por tabla. */
function dependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM planes                WHERE articulo = :a) AS planes,
                (SELECT COUNT(*) FROM chips                 WHERE articulo = :b) AS chips,
                (SELECT COUNT(*) FROM comprobantesrenglones WHERE articulo = :c) AS renglones,
                (SELECT COUNT(*) FROM carritositems         WHERE articulo = :d) AS carritos'
    );
    $stmt->execute([':a' => $id, ':b' => $id, ':c' => $id, ':d' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'planes'    => (int) ($r['planes']    ?? 0),
        'chips'     => (int) ($r['chips']     ?? 0),
        'renglones' => (int) ($r['renglones'] ?? 0),
        'carritos'  => (int) ($r['carritos']  ?? 0),
    ];
}

/**
 * Valida y normaliza el payload.
 *
 * `compra` y `venta` NO se leen tal cual del payload: pasan por
 * `articuloPrecios()`, que es la cuenta del sistema historico. De `compra` se
 * lee lo que llega sólo para el caso de los pesos -- en dolares la pisa la
 * cotizacion --, y `venta` sale siempre de la cuenta. El formulario los muestra
 * de solo lectura, con la vista previa en vivo.
 *
 * `$previo` es null en el alta y la fila actual en la edicion: se usa para
 * aceptar los codigos cortos que la fila YA TENIA aunque no esten en el
 * catalogo. Hoy las 106 filas estan dentro de el, pero la columna no tiene
 * `ENUM` ni `CHECK` y el legacy escribe la misma tabla: sin esta excepcion, el
 * dia que aparezca un codigo nuevo esa fila quedaria imposible de guardar --
 * nadie podria corregirle ni el nombre. Es el mismo trato que `talonarios`.
 * `tipo` le da al talonario 35.
 */
function validarArticulo(array $in, ?array $previo = null): array
{
    $out = [];

    // tipo / moneda / visibilidad son codigos cortos que tienen que estar en su
    // combo: uno fuera del catalogo se veria pelado en toda pantalla que lo
    // traduzca, incluida la tienda publica y el back office viejo.
    $out['tipo']        = codigoDeCombo($in['tipo']        ?? null, COMBO_TIPO,        'El tipo',          1,  $previo['tipo']        ?? null);
    $out['moneda']      = codigoDeCombo($in['moneda']      ?? null, COMBO_MONEDA,      'La moneda',        1,  $previo['moneda']      ?? null);
    $out['visibilidad'] = codigoDeCombo($in['visibilidad'] ?? null, COMBO_VISIBILIDAD, 'La visibilidad',  10,  $previo['visibilidad'] ?? null);

    // `moneda` decide de donde sale `compra`, asi que sin ella la cuenta no
    // existe. Las 106 filas la tienen.
    if ($out['moneda'] === '') json_error('La moneda es obligatoria', 422);

    $out['categoria'] = fkOpcional($in['categoria'] ?? null, 'articuloscategorias', 'La categoria');

    $out['nombre'] = textoObligatorio($in['nombre'] ?? null, LARGO_NOMBRE, 'El nombre');
    $out['marca']  = textoLimitado($in['marca'] ?? null, LARGO_MARCA, 'La marca');
    $out['sku']    = textoLimitado($in['sku']   ?? null, LARGO_SKU,   'El codigo SKU');
    $out['ean']    = textoLimitado($in['ean']   ?? null, LARGO_EAN,   'El codigo EAN');
    $out['web']    = textoLimitado($in['web']   ?? null, LARGO_WEB,   'La web');

    // Los dos mediumtext se guardan tal cual, sin `trim()` de los saltos: son el
    // texto que imprime la tienda publica y `metadatos` ademas lleva bloques
    // (`<resumen>`, `<especificaciones>`) cuyo formato es significativo.
    $out['descripcion'] = textoLargo($in['descripcion'] ?? null, 'La descripcion');
    $out['metadatos']   = textoLargo($in['metadatos']   ?? null, 'Los metadatos');

    $out['actual']      = entero($in['actual']      ?? null, 'El stock actual');
    $out['minimo']      = entero($in['minimo']      ?? null, 'El stock minimo');
    $out['recomendado'] = entero($in['recomendado'] ?? null, 'El stock recomendado');

    $out['iva'] = decimalNoNegativo($in['iva'] ?? null, 'El IVA');
    if ($out['iva'] > 100) json_error('El IVA no puede superar 100 %', 422);

    $out['importacion'] = decimalNoNegativo($in['importacion'] ?? null, 'El precio de importacion');
    $out['margen']      = decimalNoNegativo($in['margen']      ?? null, 'El margen');

    // En pesos la compra es un dato que se carga; en dolares llega igual desde
    // el formulario (que la muestra) pero `articuloPrecios()` la descarta.
    $compraEntrada = decimalNoNegativo($in['compra'] ?? null, 'El precio de compra');

    if ($out['moneda'] === MONEDA_DOLAR && cotizacionDolar() <= 0) {
        json_error(
            'No hay cotizacion del dolar cargada en parametros (' . PARAMETRO_COTIZACION .
            '): un articulo en dolares quedaria en $ 0,00.',
            409
        );
    }

    $precios = articuloPrecios($out['moneda'], $out['importacion'], $compraEntrada, $out['margen']);
    $out['compra'] = $precios['compra'];
    $out['venta']  = $precios['venta'];

    // `decimal(10,2)` se chequea DESPUES de la cuenta: lo que se pasa de rango
    // no es lo que se tipeo sino el producto por la cotizacion.
    foreach (['importacion' => 'El precio de importacion',
              'compra'      => 'El precio de compra',
              'venta'       => 'El precio de venta',
              'margen'      => 'El margen'] as $campo => $rotulo) {
        if ($out[$campo] > DECIMAL_MAX) {
            json_error("{$rotulo} supera el maximo de la columna (" . number_format(DECIMAL_MAX, 2, ',', '.') . ')', 422);
        }
    }

    $out['habilitado'] = valorHabilitado($in['habilitado'] ?? DESHABILITADO);

    return $out;
}

/** Parametros del INSERT / UPDATE, en el orden del esquema. */
function bindArticulo(array $d): array
{
    return [
        ':tipo'        => $d['tipo'],
        ':categoria'   => $d['categoria'],
        ':marca'       => $d['marca'],
        ':nombre'      => $d['nombre'],
        ':descripcion' => $d['descripcion'],
        ':metadatos'   => $d['metadatos'],
        ':sku'         => $d['sku'],
        ':ean'         => $d['ean'],
        ':actual'      => $d['actual'],
        ':minimo'      => $d['minimo'],
        ':recomendado' => $d['recomendado'],
        ':iva'         => $d['iva'],
        ':moneda'      => $d['moneda'],
        ':importacion' => $d['importacion'],
        ':compra'      => $d['compra'],
        ':margen'      => $d['margen'],
        ':venta'       => $d['venta'],
        ':web'         => $d['web'],
        ':visibilidad' => $d['visibilidad'],
        ':habilitado'  => $d['habilitado'],
    ];
}

/**
 * Codigo corto que tiene que existir en su combo. Vacio => cadena vacia.
 *
 * NO devuelve null: las 106 filas usan la cadena vacia y ninguna columna de
 * texto de esta tabla tiene un NULL, asi que mandar NULL estrenaria una segunda
 * forma de decir lo mismo (mismo criterio que `talonarios`.`terminos`).
 *
 * `$heredado` es el valor que la fila ya tenia: se acepta siempre, aunque no
 * este en el catalogo. Un alta no lo tiene, asi que ahi se exige entero.
 *
 * Si `combos` no tiene cargada la clave se acepta lo que venga: sin catalogo no
 * hay contra que validar, y bloquear el alta entera por eso seria peor.
 */
function codigoDeCombo(mixed $valor, string $clave, string $rotulo, int $largo, ?string $heredado = null): string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return '';

    if (mb_strlen($v) > $largo) json_error("{$rotulo} no puede superar {$largo} caracter(es)", 422);

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

function textoLargo(mixed $valor, string $rotulo): string
{
    $v = (string) ($valor ?? '');
    if (mb_strlen($v) > LARGO_TEXTO_LARGO) {
        json_error("{$rotulo} no puede superar " . LARGO_TEXTO_LARGO . ' caracteres', 422);
    }

    return $v;
}

/**
 * Entero con signo. El stock admite negativos: son 4 filas por debajo del
 * minimo hoy y nada impide que una quede en rojo tras una salida.
 */
function entero(mixed $valor, string $rotulo): int
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0;

    if (!preg_match('/^-?\d+$/', $v)) json_error("{$rotulo} tiene que ser un numero entero", 422);

    $n = (int) $v;
    if ($n > 2147483647 || $n < -2147483648) json_error("{$rotulo} esta fuera de rango", 422);

    return $n;
}

function decimalNoNegativo(mixed $valor, string $rotulo): float
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0.0;

    if (!is_numeric($v))  json_error("{$rotulo} tiene que ser un numero", 422);
    if ((float) $v < 0)   json_error("{$rotulo} no puede ser negativo", 422);

    return round((float) $v, 2);
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
