<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/comprobantes_lib.php';

/**
 * Renglones de un comprobante (`comprobantesrenglones`).
 *
 * Endpoint aparte de `comprobantes.php` porque son otra entidad con su propio
 * ciclo: el modal de la ficha los agrega, edita y borra de a uno sin volver a
 * guardar la cabecera. Sigue el patron de los endpoints hermanos del repo
 * (`tareas_ejecuciones.php`, `migraciones_apply.php`).
 *
 * CUATRO VERBOS DE A UNO Y UNO DE A TODOS. `POST` / `PUT` / `DELETE` tocan UN
 * renglon -- es lo que hace `openRenglonModal()` desde la ficha --, y `PATCH`
 * sincroniza LA GRILLA ENTERA contra lo que manda el editor (DESIGN.md
 * §25-quinquies): da de alta las filas nuevas, actualiza las que cambiaron y
 * borra las que el operador saco. No es una comodidad del front: esa grilla se
 * edita y se guarda de una sola vez, y resolverla con N llamadas sueltas deja
 * un comprobante a medio guardar cuando la tercera de ocho falla -- con los
 * totales ya reescritos por las dos que pasaron. `PATCH` corre las tres
 * operaciones en UNA transaccion y totaliza UNA vez al final.
 *
 * DOS INVARIANTES, Y LAS DOS SE CHEQUEAN CONTRA LA BASE:
 *
 *   1. Solo se tocan los renglones de un comprobante en `Preparacion`. Un
 *      comprobante Pendiente o Cancelado ya se emitio con esos renglones
 *      impresos; agregarle uno cambiaria el total de un documento entregado.
 *      La UI esconde los botones, pero esconder el boton no es el control.
 *   2. TODA escritura termina en `comprobanteTotalizar()`, que reescribe
 *      subtotal / iva / total desde los renglones. Es lo que hace el legacy en
 *      sus tres ramas (`renglon.php`) y es la unica forma de que el total no
 *      se despegue de lo que suma la grilla. La respuesta devuelve los totales
 *      nuevos para que la ficha los repinte sin pedir el detalle de nuevo.
 *
 * `monto` NO se deriva de cantidad x unitario. El legacy lo manda como un
 * campo mas y lo usa asi: los renglones de promocion se cargan con monto
 * NEGATIVO y unitario positivo (ver `cContrato::facturar()`), y los renglones
 * de encabezado van en 0 con detalle y sin importe. Derivarlo rompería los
 * dos casos. El front lo pre-calcula como comodidad y se puede pisar.
 */

/**
 * Tope de renglones que acepta un `PATCH`.
 *
 * No sale de un limite del esquema sino del uso: sobre 2.332 comprobantes y
 * 6.706 renglones, el mas largo tiene 27. El numero esta para que un payload
 * armado a mano no dispare miles de INSERTs adentro de una transaccion, no
 * para acotar lo que un operador puede cargar.
 */
const SYNC_RENGLONES_MAX = 200;

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    switch ($method) {
        case 'GET':    handleList();   break;
        case 'POST':   handleCreate(); break;
        case 'PUT':    handleUpdate(); break;
        case 'PATCH':  handleSync();   break;
        case 'DELETE': handleDelete(); break;
        default:
            json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al procesar renglones: ' . $e->getMessage(), 500);
}

function handleList(): void
{
    $id = (int) ($_GET['comprobante'] ?? 0);
    if ($id <= 0) json_error('Id de comprobante invalido', 422);

    json_ok(['renglones' => comprobanteRenglones($id)]);
}

function handleCreate(): void
{
    $in  = readJson();
    $cpb = (int) ($in['comprobante'] ?? 0);
    if ($cpb <= 0) json_error('Id de comprobante invalido', 422);

    exigirPreparacion($cpb);
    $data = validarRenglon($in);

    // `orden` se autonumera al final si no lo mandan: los renglones se
    // imprimen por `orden, id`, y dejarlo en 0 para todos haria que el orden
    // dependa del id -- que es lo mismo hasta que alguien edita uno.
    $orden = trim((string) ($in['orden'] ?? ''));
    if ($orden === '') {
        $max = db()->prepare('SELECT COALESCE(MAX(orden), -1) FROM comprobantesrenglones WHERE comprobante = :id');
        $max->execute([':id' => $cpb]);
        $data['orden'] = ((int) $max->fetchColumn()) + 1;
    } else {
        $data['orden'] = ordenValido($orden);
    }

    $stmt = db()->prepare(
        "INSERT INTO comprobantesrenglones
             (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
         VALUES
             (:cpb, :orden, :cantidad, :articulo, :detalle, :iva, :unitario, :monto, '1')"
    );
    $stmt->execute([
        ':cpb'      => $cpb,
        ':orden'    => $data['orden'],
        ':cantidad' => $data['cantidad'],
        ':articulo' => $data['articulo'],
        ':detalle'  => $data['detalle'],
        ':iva'      => $data['iva'],
        ':unitario' => $data['unitario'],
        ':monto'    => $data['monto'],
    ]);

    $id = (int) db()->lastInsertId();

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)], 201);
}

function handleUpdate(): void
{
    $in = readJson();
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $prev = db()->prepare('SELECT comprobante FROM comprobantesrenglones WHERE id = :id');
    $prev->execute([':id' => $id]);
    $cpb = (int) ($prev->fetchColumn() ?: 0);
    if ($cpb <= 0) json_error('Renglon no encontrado', 404);

    exigirPreparacion($cpb);
    $data = validarRenglon($in);

    $orden = trim((string) ($in['orden'] ?? ''));
    $data['orden'] = $orden === '' ? 0 : ordenValido($orden);

    // `comprobante` NO se toca: mover un renglon de un comprobante a otro
    // cambiaria el total de los dos y no es lo que este modal ofrece.
    db()->prepare(
        'UPDATE comprobantesrenglones
            SET orden = :orden, cantidad = :cantidad, articulo = :articulo,
                detalle = :detalle, iva = :iva, unitario = :unitario, monto = :monto
          WHERE id = :id'
    )->execute([
        ':orden'    => $data['orden'],
        ':cantidad' => $data['cantidad'],
        ':articulo' => $data['articulo'],
        ':detalle'  => $data['detalle'],
        ':iva'      => $data['iva'],
        ':unitario' => $data['unitario'],
        ':monto'    => $data['monto'],
        ':id'       => $id,
    ]);

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)]);
}

/**
 * Sincroniza la grilla entera del editor contra la base, en UNA transaccion.
 *
 * Recibe `{comprobante, renglones: [...]}` con la lista COMPLETA tal como
 * quedo en la pantalla. Un renglon con `id` es uno que ya existia; sin `id`,
 * uno nuevo. Lo que esta en la base y NO viene en la lista se borra: la grilla
 * es la verdad, y el operador que saco una fila espera que desaparezca.
 *
 * TODO SE VALIDA ANTES DE ABRIR LA TRANSACCION. `validarRenglon()` corta con
 * `json_error()`, que termina el request en el acto: llamarla con la
 * transaccion abierta dejaria la conexion con un `BEGIN` sin cerrar. Por eso
 * el recorrido va en dos pasadas -- primero valida las N filas, despues
 * escribe.
 *
 * `orden` NO se lee del payload: es la POSICION en la grilla. Los renglones se
 * imprimen por `orden, id`, asi que el orden en que se ven en el editor es el
 * orden en que salen impresos, y mandarlo aparte permitiria que las dos cosas
 * no coincidan. La contrapartida es que un `orden` cargado a mano desde
 * `openRenglonModal()` (0, 5, 10) se renumera a 0, 1, 2 al guardar el editor:
 * cambia el valor, no la secuencia.
 */
function handleSync(): void
{
    $in  = readJson();
    $cpb = (int) ($in['comprobante'] ?? 0);
    if ($cpb <= 0) json_error('Id de comprobante invalido', 422);

    exigirPreparacion($cpb);

    if (!isset($in['renglones']) || !is_array($in['renglones'])) {
        json_error('Faltan los renglones', 422);
    }
    if (count($in['renglones']) > SYNC_RENGLONES_MAX) {
        json_error('Un comprobante no puede tener mas de ' . SYNC_RENGLONES_MAX . ' renglones', 422);
    }

    // Los ids que el comprobante tiene HOY. Un id que llega y no esta en esta
    // lista es de otro comprobante -- o de uno que alguien borro mientras el
    // editor estaba abierto -- y se rechaza el pedido entero: aceptarlo
    // salteado guardaria una grilla distinta de la que se ve en pantalla.
    $stmt = db()->prepare('SELECT id FROM comprobantesrenglones WHERE comprobante = :id');
    $stmt->execute([':id' => $cpb]);
    $vivos = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    $filas = [];
    $ids   = [];
    foreach (array_values($in['renglones']) as $i => $r) {
        if (!is_array($r)) json_error('Renglon invalido en la posicion ' . ($i + 1), 422);

        $data = validarRenglon($r);

        $id = (int) ($r['id'] ?? 0);
        if ($id > 0) {
            if (!in_array($id, $vivos, true)) {
                json_error("El renglon #{$id} ya no es de este comprobante. Volve a abrir la ficha.", 409);
            }
            if (in_array($id, $ids, true)) {
                json_error("El renglon #{$id} viene repetido", 422);
            }
            $ids[] = $id;
        }

        $data['id']    = $id > 0 ? $id : null;
        $data['orden'] = $i;
        $filas[]       = $data;
    }

    $sobran = array_values(array_diff($vivos, $ids));

    $pdo = db();
    try {
        $pdo->beginTransaction();

        if ($sobran !== []) {
            $marcas = implode(',', array_fill(0, count($sobran), '?'));
            $pdo->prepare("DELETE FROM comprobantesrenglones WHERE comprobante = ? AND id IN ($marcas)")
                ->execute(array_merge([$cpb], $sobran));
        }

        $upd = $pdo->prepare(
            'UPDATE comprobantesrenglones
                SET orden = :orden, cantidad = :cantidad, articulo = :articulo,
                    detalle = :detalle, iva = :iva, unitario = :unitario, monto = :monto
              WHERE id = :id AND comprobante = :cpb'
        );
        $ins = $pdo->prepare(
            "INSERT INTO comprobantesrenglones
                 (comprobante, orden, cantidad, articulo, detalle, iva, unitario, monto, estado)
             VALUES
                 (:cpb, :orden, :cantidad, :articulo, :detalle, :iva, :unitario, :monto, '1')"
        );

        foreach ($filas as $f) {
            $args = [
                ':cpb'      => $cpb,
                ':orden'    => $f['orden'],
                ':cantidad' => $f['cantidad'],
                ':articulo' => $f['articulo'],
                ':detalle'  => $f['detalle'],
                ':iva'      => $f['iva'],
                ':unitario' => $f['unitario'],
                ':monto'    => $f['monto'],
            ];
            if ($f['id'] === null) {
                $ins->execute($args);
            } else {
                $upd->execute($args + [':id' => $f['id']]);
            }
        }

        // Una sola vez y adentro de la transaccion: si algo de arriba falla,
        // los totales de la cabecera tampoco se movieron.
        $totales = comprobanteTotalizar($cpb);

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    // La grilla vuelve releida de la base -- con los ids nuevos y el `orden`
    // ya renumerado -- para que el editor no tenga que adivinarlos.
    json_ok(['totales' => $totales, 'renglones' => comprobanteRenglones($cpb)]);
}

function handleDelete(): void
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    $prev = db()->prepare('SELECT comprobante FROM comprobantesrenglones WHERE id = :id');
    $prev->execute([':id' => $id]);
    $cpb = (int) ($prev->fetchColumn() ?: 0);
    if ($cpb <= 0) json_error('Renglon no encontrado', 404);

    exigirPreparacion($cpb);

    db()->prepare('DELETE FROM comprobantesrenglones WHERE id = :id')->execute([':id' => $id]);

    json_ok(['id' => $id, 'totales' => comprobanteTotalizar($cpb)]);
}

/* ---------------------------------------------------------------- helpers */

/** Corta con 409 si el comprobante no esta en Preparacion. */
function exigirPreparacion(int $cpb): void
{
    $stmt = db()->prepare('SELECT estado FROM comprobantes WHERE id = :id');
    $stmt->execute([':id' => $cpb]);
    $fila = $stmt->fetch();
    if (!$fila) json_error('Comprobante no encontrado', 404);

    $estado = trim((string) $fila['estado']);
    if ($estado !== ESTADO_PREPARACION) {
        json_error(
            'Solo se pueden tocar los renglones de un comprobante en Preparacion. Este esta en "' .
            (combo(COMBO_ESTADO)[$estado] ?? 'sin estado') . '".',
            409
        );
    }
}

function validarRenglon(array $in): array
{
    $detalle = textoLimitado($in['detalle'] ?? '', 500, 'El detalle');
    if ($detalle === '') json_error('El detalle es obligatorio', 422);

    // `iva` es la alicuota (0 / 10.50 / 21.00), no el importe. Se valida
    // contra el combo cuando existe: una alicuota que no sea una de esas tres
    // no se desagrega en `comprobanteTotalizar()` y quedaria como monto sin
    // IVA discriminado sin que nadie lo note.
    $iva      = decimalNoNegativo($in['iva'] ?? null, 'El IVA');
    $catalogo = combo(COMBO_RENGLON_IVA);
    if ($catalogo !== []) {
        $valores = array_map(static fn($v) => (float) $v, array_keys($catalogo));
        if (!in_array($iva, $valores, true)) {
            json_error(
                'La alicuota de IVA tiene que ser una de: ' . implode(', ', array_keys($catalogo)),
                422
            );
        }
    }

    return [
        'cantidad' => decimalConSigno($in['cantidad'] ?? null, 'La cantidad'),
        'articulo' => fkOpcional($in['articulo'] ?? null, 'articulos', 'El articulo'),
        'detalle'  => $detalle,
        'iva'      => $iva,
        'unitario' => decimalConSigno($in['unitario'] ?? null, 'El unitario'),
        // Negativo a proposito: asi se cargan los renglones de descuento.
        'monto'    => decimalConSigno($in['monto'] ?? null, 'El monto'),
    ];
}

/** `orden` es smallint: 0 a 32767, pero se acota a lo razonable. */
function ordenValido(string $v): int
{
    if (!ctype_digit($v)) json_error('El orden tiene que ser un numero entero de 0 o mas', 422);

    $n = (int) $v;
    if ($n > 9999) json_error('El orden no puede superar 9999', 422);

    return $n;
}
