<?php

declare(strict_types=1);

require __DIR__ . '/bootstrap.php';
// El calculo de la mora -- bandas, que cuenta como deuda, la consulta que la
// resuelve y la regla de que el dominio se queda con la peor situacion de sus
// contratos -- vive en su propio lib, y lo comparten el barrido de las 04:00, la
// tarea del dia 15 y los dos `Actualizar situacion` (este y el de Contratos).
require_once __DIR__ . '/contratos_situacion_lib.php';
// Recalcular puede dejar un dominio Suspendido, o sea sin controles en la app:
// eso deja un suceso para poder ubicarlo en el tiempo desde el Visor.
require_once __DIR__ . '/lib/sucesos.php';

/**
 * Acciones de negocio sobre un dominio: `?accion=<nombre>`.
 *
 *   situacion  Recalcula la mora de sus contratos habilitados y la del dominio.
 *
 * Va en un endpoint aparte y NO como un `PUT` sobre `dominios.php` por la misma
 * razon que `contratos_accion.php` y `comprobantes_accion.php`: no es "guardar
 * los campos que mandaste". El `PUT` del ABM escribe `nombre` y `descripcion` y
 * nada mas; esto no recibe un valor, lo CALCULA -- y lo que calcula decide si un
 * cliente ve o no los controles de operacion de la app. Mezclarlas haria que un
 * payload con `situacion` adentro pudiera escribir a mano lo que esta accion
 * deduce de la deuda.
 *
 * EL VERBO SEPARA PREVISUALIZAR DE EJECUTAR: `GET` devuelve lo que se VA a
 * escribir --con los dias de atraso y el detalle de la deuda que lo explican-- y
 * `POST` lo escribe. Los dos llaman a la MISMA funcion del lib con el flag
 * `$escribir`, que es lo unico que garantiza que lo que muestra la pantalla sea
 * lo que despues queda en la base. Es el mismo reparto de
 * `contratos_accion.php?accion=facturar`, y aca se justifica por lo mismo: el
 * resultado puede ser '3' Suspendido.
 *
 * ------------------------------------------------------------------------
 * ES LA MISMA CUENTA QUE LA TAREA DE LAS 04:00, ACOTADA A UN DOMINIO
 * ------------------------------------------------------------------------
 *
 * No "parecida": el mismo codigo. `situacionRecalcularDominio()` de
 * `contratos_situacion_lib.php` es lo que corre acá y lo que corre allá, con las
 * mismas bandas (menos de 15 dias Normal, 15 a 29 Limitado, 30 o mas Suspendido),
 * la misma definicion de deuda (prefactura o factura PENDIENTE con vencimiento
 * utilizable) y la misma regla de que el dominio se queda con la PEOR situacion
 * de sus contratos habilitados.
 *
 * Con una copia propia, esta pantalla diria una situacion y el job escribiria
 * otra a las 04:00 de la mañana siguiente, sin que nadie hubiera tocado nada en
 * el medio.
 *
 * RECALCULA TODOS LOS CONTRATOS HABILITADOS DEL DOMINIO, no solo la columna del
 * dominio: para saber que le toca al dominio hay que mirar a cada contrato, y si
 * se los mira hay que escribir lo que se calculo -- si no, `contratos`.
 * `situacion` quedaria diciendo una cosa y `dominios`.`situacion` estaria
 * calculada con otra. La pantalla los lista uno por uno.
 *
 * SI EL DOMINIO NO TIENE NINGUN CONTRATO HABILITADO NO ESCRIBE NADA, ni siquiera
 * un Normal: es la regla del job -- un dominio sin contrato vivo no tiene mora
 * que mirar y pisarle la `situacion` seria opinar sobre un cliente del que este
 * calculo no sabe nada. La respuesta lo dice con un aviso.
 *
 * NO TOCA `habilitado`, ni del dominio ni de sus contratos: eso es de `baja` /
 * `alta` de `contratos_accion.php`. La mora dice cuanto debe el cliente; el
 * estado comercial dice si el contrato esta vivo. Un dominio dado de baja que
 * vuelve a Normal por esta accion sigue deshabilitado.
 */

$accion = isset($_GET['accion']) ? trim((string) $_GET['accion']) : '';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        switch ($accion) {
            case 'situacion': json_ok(situacionDelDominio(false)); break;
            default:
                json_error('Accion desconocida o sin previsualizacion', 400);
        }
    } elseif ($method === 'POST') {
        switch ($accion) {
            case 'situacion': json_ok(situacionDelDominio(true)); break;
            default:
                json_error('Accion desconocida', 400);
        }
    } else {
        json_error('Metodo no permitido', 405);
    }
} catch (Throwable $e) {
    json_error('Error al ejecutar la accion: ' . $e->getMessage(), 500);
}

/** Id del dominio sobre el que opera la accion. */
function dominioPedido(): int
{
    $id = (int) ($_GET['id'] ?? 0);
    if ($id <= 0) json_error('Id invalido', 422);

    return $id;
}

/**
 * El cuerpo que comparten la previsualizacion y la ejecucion.
 *
 * Con `$escribir = true` abre transaccion y bloquea las filas; con `false` no
 * abre nada y solo lee. El `FOR UPDATE` del camino de escritura toma primero
 * `contratos` y despues `dominios` -- el orden del job y el de `contratoBaja()`,
 * para que dos escrituras encimadas se esperen en vez de abrazarse.
 */
function situacionDelDominio(bool $escribir): array
{
    $id  = dominioPedido();
    $pdo = db();

    $stmt = $pdo->prepare('SELECT id FROM dominios WHERE id = :id');
    $stmt->execute([':id' => $id]);
    if ($stmt->fetchColumn() === false) json_error('Dominio no encontrado', 404);

    if (!$escribir) {
        return situacionRecalcularDominio($pdo, $id, false) + ['dominio_pedido' => $id];
    }

    try {
        $pdo->beginTransaction();
        $r = situacionRecalcularDominio($pdo, $id, true);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }

    sucesoSituacion($pdo, 'dominios', $r, sprintf('desde el dominio #%d', $id));

    return $r + ['dominio_pedido' => $id];
}
