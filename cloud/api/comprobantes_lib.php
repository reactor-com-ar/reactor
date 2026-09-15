<?php

declare(strict_types=1);

/**
 * Piezas compartidas por los tres endpoints de comprobantes:
 * `comprobantes.php` (listado / ficha / ABM), `comprobantes_renglones.php`
 * (los renglones) y `comprobantes_accion.php` (autorizar, anular, duplicar,
 * enviar por correo, registrar pago).
 *
 * Estan en un archivo aparte y no duplicadas en los tres porque son UNA sola
 * regla en cada caso -- el formato del numero, el recalculo de totales, la
 * traduccion de los codigos cortos -- y tres copias de `comprobanteTotalizar()`
 * son tres formas de que el total deje de coincidir con sus renglones. Es la
 * misma razon por la que `habilitado.php` vive en `lib/`; la diferencia es que
 * esto no lo comparten tres apps sin docroot comun, sino tres endpoints del
 * mismo directorio, asi que un `require_once` alcanza.
 */

/** Claves de `combos` con los textos de los codigos cortos. */
const COMBO_ESTADO         = '$xComprobante->estado';
const COMBO_CONDICION      = '$xComprobante->condicion';
const COMBO_RENGLON_IVA    = '$xComprobanteRenglon->iva';
const COMBO_TALONARIO_TIPO = '$xTalonario->tipo';
const COMBO_TALONARIO_FISCAL = '$xTalonario->fiscal';
const COMBO_PAGO_ESTADO    = '$xPago->estado';

/** Ultimo recurso si `combos` no tiene cargada la clave. */
const COMBOS_FALLBACK = [
    COMBO_ESTADO      => ['0' => 'Anulado', '1' => 'Preparacion', '2' => 'Pendiente', '3' => 'Cancelado'],
    COMBO_PAGO_ESTADO => ['0' => 'Anulado', '1' => 'Pendiente',   '2' => 'Imputado'],
    COMBO_TALONARIO_FISCAL => ['1' => 'Si', '0' => 'No'],
];

/**
 * Los cuatro estados de `comprobantes`.`estado` (varchar(1)).
 *
 * NO es la bandera `habilitado` ni una variante suya: son cuatro valores con
 * transiciones propias -- Preparacion -> Pendiente -> Cancelado, y Anulado
 * como salida. Se comparan como STRING porque la columna es varchar y '0'
 * (Anulado) es un valor real: con un `empty()` o un cast a int de por medio,
 * "anulado" y "sin estado" se confunden, y hay 149 anulados.
 */
const ESTADO_ANULADO     = '0';
const ESTADO_PREPARACION = '1';
const ESTADO_PENDIENTE   = '2';
const ESTADO_CANCELADO   = '3';

/** Digitos del punto de venta y del correlativo en el numero impreso. */
const NUMERO_PUNTO_DIGITOS = 3;
const NUMERO_SERIE_DIGITOS = 6;

/** Visor publico del comprobante, indexado por `uuid`. Vive fuera de cloud. */
const VISOR_BASE = 'https://www.reactor.com.ar/comprobante';

/** Boton de pago publico, indexado por `uuid`. */
const PAGO_BASE = 'https://www.reactor.com.ar/pagar';

/** Largo del `uuid`, el mismo que genera `cCadena::aleatoria(16, '0A')`. */
const UUID_LARGO = 16;

/**
 * Columnas del SELECT de cabecera. Es una constante y no texto repetido
 * porque `handleList()` y `handleDetalle()` tienen que devolver EXACTAMENTE
 * la misma forma: la ficha se abre con la fila del listado y se refresca con
 * la del detalle, y un campo que este en una y no en la otra aparece y
 * desaparece al refrescar.
 */
const COMPROBANTE_COLUMNAS = '
    c.id, c.uuid, c.talonario, c.serie, c.caenro, c.caevto, c.caeres,
    c.emision, c.vencimiento, c.contrato, c.cliente, c.razon, c.condicion,
    c.cuit, c.domicilio, c.correo, c.celular, c.subtotal, c.iva, c.total,
    c.cotizacion, c.observaciones, c.comentarios, c.medio, c.estado,
    t.nombre  AS talonario_nombre,
    t.tipo    AS talonario_tipo,
    t.subtipo AS talonario_subtipo,
    t.punto   AS talonario_punto,
    t.fiscal  AS talonario_fiscal,
    t.empresa AS empresa,
    em.nombre AS empresa_nombre,
    cl.nombre AS cliente_nombre,
    me.nombre AS medio_nombre';

/**
 * Numero impreso del comprobante: `punto-serie` con 3 y 6 digitos, el formato
 * del listado del back office viejo ("001-007287").
 *
 * Con `serie = 0` devuelve null y no "001-000000": el cero significa que el
 * comprobante todavia no se autorizo, o sea que NO tiene numero. Imprimir un
 * cero relleno lo haria parecer el comprobante numero cero de la serie.
 */
function comprobanteNumero(mixed $punto, mixed $serie): ?string
{
    $s = (int) $serie;
    if ($s <= 0) return null;

    return str_pad((string) (int) $punto, NUMERO_PUNTO_DIGITOS, '0', STR_PAD_LEFT)
         . '-' . str_pad((string) $s, NUMERO_SERIE_DIGITOS, '0', STR_PAD_LEFT);
}

/** Normaliza una fila de cabecera al JSON que consume el front. */
function comprobanteSalida(array $r): array
{
    $estado    = trim((string) ($r['estado']    ?? ''));
    $condicion = trim((string) ($r['condicion'] ?? ''));
    $tipo      = trim((string) ($r['talonario_tipo']    ?? ''));
    $subtipo   = trim((string) ($r['talonario_subtipo'] ?? ''));
    $fiscal    = trim((string) ($r['talonario_fiscal']  ?? ''));

    $tipoTexto = combo(COMBO_TALONARIO_TIPO)[$tipo] ?? $tipo;

    return [
        'id'                => (int) $r['id'],
        'uuid'              => trim((string) ($r['uuid'] ?? '')),
        // El 0 de estas FKs es el centinela "sin asignar" del sistema
        // historico, no un id: se normaliza a null como el NULL real.
        'talonario'         => idOrNull($r['talonario']),
        'talonario_nombre'  => trim((string) ($r['talonario_nombre'] ?? '')),
        'tipo'              => $tipo,
        'tipo_texto'        => $tipoTexto,
        'subtipo'           => $subtipo,
        // "Prefactura X" — el tipo traducido y el subtipo crudo, la misma
        // combinacion que arma el nombre del talonario.
        'tipo_completo'     => trim($tipoTexto . ' ' . $subtipo),
        'punto'             => $r['talonario_punto'] === null ? null : (int) $r['talonario_punto'],
        'serie'             => $r['serie'] === null ? null : (int) $r['serie'],
        'numero'            => comprobanteNumero($r['talonario_punto'] ?? null, $r['serie'] ?? null),
        'fiscal'            => $fiscal,
        'fiscal_texto'      => combo(COMBO_TALONARIO_FISCAL)[$fiscal] ?? '',
        'caenro'            => trim((string) ($r['caenro'] ?? '')),
        'caevto'            => trim((string) ($r['caevto'] ?? '')),
        'caeres'            => trim((string) ($r['caeres'] ?? '')),
        'empresa'           => idOrNull($r['empresa']),
        'empresa_nombre'    => trim((string) ($r['empresa_nombre'] ?? '')),
        'emision'           => $r['emision'],
        'vencimiento'       => $r['vencimiento'],
        'contrato'          => idOrNull($r['contrato']),
        'cliente'           => idOrNull($r['cliente']),
        'cliente_nombre'    => trim((string) ($r['cliente_nombre'] ?? '')),
        'razon'             => trim((string) ($r['razon']     ?? '')),
        'domicilio'         => trim((string) ($r['domicilio'] ?? '')),
        'correo'            => trim((string) ($r['correo']    ?? '')),
        'celular'           => trim((string) ($r['celular']   ?? '')),
        'condicion'         => $condicion,
        'condicion_texto'   => combo(COMBO_CONDICION)[$condicion] ?? '',
        'cuit'              => trim((string) ($r['cuit'] ?? '')),
        'subtotal'          => $r['subtotal']   === null ? null : (float) $r['subtotal'],
        'iva'               => $r['iva']        === null ? null : (float) $r['iva'],
        'total'             => $r['total']      === null ? null : (float) $r['total'],
        'cotizacion'        => $r['cotizacion'] === null ? null : (float) $r['cotizacion'],
        'observaciones'     => (string) ($r['observaciones'] ?? ''),
        'comentarios'       => (string) ($r['comentarios']   ?? ''),
        'medio'             => idOrNull($r['medio']),
        'medio_nombre'      => trim((string) ($r['medio_nombre'] ?? '')),
        'estado'            => $estado,
        'estado_texto'      => combo(COMBO_ESTADO)[$estado] ?? '',
        // Las tres puertas que abre cada estado, resueltas EN EL BACKEND y no
        // en el front: son las mismas condiciones que despues vuelve a chequear
        // cada accion, asi que tienen que salir de un solo lugar.
        'puede_editar'      => $estado === ESTADO_PREPARACION,
        'puede_autorizar'   => $estado === ESTADO_PREPARACION,
        'puede_anular'      => $estado === ESTADO_PENDIENTE,
        // El boton de pago del legacy sale solo para prefacturas pendientes.
        'puede_pagar'       => $estado === ESTADO_PENDIENTE,
        'boton_pago'        => ($tipo === 'F' && $estado === ESTADO_PENDIENTE)
            ? PAGO_BASE . '/?uid=' . rawurlencode(trim((string) ($r['uuid'] ?? '')))
            : null,
    ];
}

/** Renglones de un comprobante, en el orden en que se imprimen. */
function comprobanteRenglones(int $id): array
{
    $stmt = db()->prepare(
        'SELECT r.id, r.comprobante, r.orden, r.cantidad, r.articulo,
                r.detalle, r.iva, r.unitario, r.monto, r.estado,
                a.nombre AS articulo_nombre
         FROM comprobantesrenglones r
         LEFT JOIN articulos a ON a.id = r.articulo
         WHERE r.comprobante = :id
         ORDER BY r.orden ASC, r.id ASC'
    );
    $stmt->execute([':id' => $id]);

    return array_map(static fn(array $r): array => [
        'id'              => (int) $r['id'],
        'comprobante'     => (int) $r['comprobante'],
        'orden'           => (int) $r['orden'],
        'cantidad'        => $r['cantidad'] === null ? null : (float) $r['cantidad'],
        'articulo'        => idOrNull($r['articulo']),
        'articulo_nombre' => trim((string) ($r['articulo_nombre'] ?? '')),
        'detalle'         => (string) ($r['detalle'] ?? ''),
        'iva'             => $r['iva']      === null ? null : (float) $r['iva'],
        'unitario'        => $r['unitario'] === null ? null : (float) $r['unitario'],
        'monto'           => $r['monto']    === null ? null : (float) $r['monto'],
        'estado'          => trim((string) ($r['estado'] ?? '')),
    ], $stmt->fetchAll());
}

/**
 * Subtotal / iva / total de una lista de renglones, SIN tocar la base.
 *
 * Es la aritmetica de `cComprobante::totalizar()` portada tal cual: los montos
 * de los renglones vienen CON IVA INCLUIDO, asi que el impuesto se DESAGREGA
 * (`monto - monto / 1.21`) en vez de sumarse encima, y el subtotal es el total
 * menos ese impuesto. Calcularlo al reves —subtotal * 1.21— daria un total
 * distinto del que ya tienen los 2.326 comprobantes emitidos.
 *
 * Solo desagrega las dos alicuotas que el legacy conoce (10,50 % y 21,00 %,
 * las mismas del combo `$xComprobanteRenglon->iva`); cualquier otra queda como
 * monto sin IVA discriminado, igual que en el sistema viejo.
 *
 * Esta separada de `comprobanteTotalizar()` porque la cuenta la necesita
 * tambien quien todavia NO tiene renglones en la base: la previsualizacion de
 * "Facturar" de Contratos (`contratos_accion.php`) muestra los totales del
 * comprobante que se va a emitir. Con la cuenta duplicada ahi, el total que
 * anuncia la pantalla y el que despues persiste el alta se despegan al primer
 * cambio de alicuota.
 *
 * @param array $renglones Filas con al menos `iva` y `monto`.
 */
function comprobanteTotalesDe(array $renglones): array
{
    $total = 0.0;
    $iva   = 0.0;
    foreach ($renglones as $r) {
        $monto = (float) ($r['monto'] ?? 0);
        $total += $monto;

        $alicuota = round((float) ($r['iva'] ?? 0), 2);
        if ($alicuota === 10.5)      $iva += $monto - ($monto / 1.105);
        elseif ($alicuota === 21.0)  $iva += $monto - ($monto / 1.21);
    }

    $total = round($total, 2);
    $iva   = round($iva, 2);

    return ['subtotal' => round($total - $iva, 2), 'iva' => $iva, 'total' => $total];
}

/**
 * Recalcula subtotal / iva / total desde los renglones de la base y los persiste.
 *
 * Se llama SIEMPRE que un renglon cambia -- alta, edicion y baja --, que es la
 * unica forma de que el total no se despegue de sus propios renglones.
 */
function comprobanteTotalizar(int $id): array
{
    $stmt = db()->prepare('SELECT iva, monto FROM comprobantesrenglones WHERE comprobante = :id');
    $stmt->execute([':id' => $id]);

    $totales = comprobanteTotalesDe($stmt->fetchAll());

    db()->prepare('UPDATE comprobantes SET subtotal = :s, iva = :i, total = :t WHERE id = :id')
        ->execute([
            ':s'  => $totales['subtotal'],
            ':i'  => $totales['iva'],
            ':t'  => $totales['total'],
            ':id' => $id,
        ]);

    return $totales;
}

/** Cabecera minima de un comprobante, o 404. */
function comprobanteCabecera(int $id): array
{
    $stmt = db()->prepare(
        'SELECT c.id, c.uuid, c.estado, c.serie, c.talonario, c.contrato, c.correo,
                c.razon, c.total, c.cliente,
                t.tipo, t.subtipo, t.punto, t.serie AS talonario_serie, t.nombre AS talonario_nombre
         FROM comprobantes c
         LEFT JOIN talonarios t ON t.id = c.talonario
         WHERE c.id = :id'
    );
    $stmt->execute([':id' => $id]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Comprobante no encontrado', 404);

    return $fila;
}

/** Cantidad de filas que dependen del comprobante, por tabla. */
function comprobanteDependencias(int $id): array
{
    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM pagos                 WHERE comprobante = :a) AS pagos,
                (SELECT COUNT(*) FROM comprobantesrenglones WHERE comprobante = :b) AS renglones'
    );
    $stmt->execute([':a' => $id, ':b' => $id]);
    $r = $stmt->fetch() ?: [];

    return [
        'pagos'     => (int) ($r['pagos']     ?? 0),
        'renglones' => (int) ($r['renglones'] ?? 0),
    ];
}

/** 16 caracteres alfanumericos en mayuscula que no use ningun comprobante. */
function comprobanteUuidLibre(): string
{
    $alfabeto = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $stmt     = db()->prepare('SELECT 1 FROM comprobantes WHERE uuid = :u');

    for ($intento = 0; $intento < 20; $intento++) {
        $uuid = '';
        for ($i = 0; $i < UUID_LARGO; $i++) {
            $uuid .= $alfabeto[random_int(0, strlen($alfabeto) - 1)];
        }
        $stmt->execute([':u' => $uuid]);
        if (!$stmt->fetchColumn()) return $uuid;
    }

    json_error('No se pudo generar un identificador libre, reintenta', 500);
}

/* ------------------------------------------------------------- utilidades */

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

/**
 * Id de una FK opcional que ademas tiene que existir: las cuatro FK de
 * `comprobantes` son RESTRICT y un id inventado devolveria un 500 del motor
 * en vez de decir cual campo esta mal.
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

/** Texto recortado al largo de su columna: pasarse es un 422, no un truncado. */
function textoLimitado(mixed $valor, int $max, string $rotulo): string
{
    $v = trim((string) ($valor ?? ''));
    if (mb_strlen($v) > $max) json_error("{$rotulo} no puede superar {$max} caracteres", 422);
    return $v;
}

/** Fecha `AAAA-MM-DD` o null. A diferencia de Contratos, aca NO hay centinelas. */
function fechaOpcional(mixed $valor, string $campo): ?string
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return null;

    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if ($d === false || $d->format('Y-m-d') !== $v) {
        json_error("La fecha de \"{$campo}\" no es valida (formato AAAA-MM-DD)", 422);
    }

    return $v;
}

/** Decimal(11,2) de 0 o mas. Acepta coma o punto como separador. */
function decimalNoNegativo(mixed $valor, string $rotulo): float
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0.0;

    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) json_error("{$rotulo} tiene que ser un numero", 422);

    $n = (float) $v;
    if ($n < 0)            json_error("{$rotulo} no puede ser negativo", 422);
    if ($n > 999999999.99) json_error("{$rotulo} supera el maximo de la columna", 422);

    return round($n, 2);
}

/** Decimal(11,2) que SI admite negativos: los descuentos van en monto negativo. */
function decimalConSigno(mixed $valor, string $rotulo): float
{
    $v = trim((string) ($valor ?? ''));
    if ($v === '') return 0.0;

    $v = str_replace(',', '.', $v);
    if (!is_numeric($v)) json_error("{$rotulo} tiene que ser un numero", 422);

    $n = (float) $v;
    if (abs($n) > 999999999.99) json_error("{$rotulo} supera el maximo de la columna", 422);

    return round($n, 2);
}

function readJson(): array
{
    $raw = file_get_contents('php://input');
    if ($raw === false || $raw === '') return [];

    $data = json_decode($raw, true);
    if (!is_array($data)) json_error('Body JSON invalido', 400);

    return $data;
}
