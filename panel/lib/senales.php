<?php

declare(strict_types=1);

/**
 * Utilidades compartidas para consultar `senales`.
 *
 * `senales` es la tabla mas grande del sistema (~863K filas vivas en
 * reactor_dev, ~90K nuevas por semana) y sus UNICOS indices son la PK y las
 * tres FKs (`transceptor`, `dispositivo`, `canal`): **`fecha` NO esta
 * indexada**. Cualquier consulta acotada por rango de fechas necesita ademas
 * una cota por clave primaria o termina mirando fila por fila todo el
 * historial que cae del lado del indice que si uso (348K filas / 1,6 s en el
 * peor caso medido para un solo dispositivo).
 *
 * Esa cota la calcula senalesPisoPorFecha(). Vive aca y no en un endpoint
 * porque la usan varios (`dispositivo_conexion.php`, `dashboard_senales.php`)
 * y es una busqueda binaria con un margen deliberado: duplicarla es
 * duplicar una sutileza facil de romper al copiar.
 */

/**
 * Colchon de ids que se le resta al piso calculado por fecha. `fecha` es
 * monotona respecto de `id` en la practica (las senales se insertan a
 * medida que llegan) pero no lo garantiza nada: inserciones concurrentes o
 * un reloj corrido pueden desordenar unas pocas filas. Pasarse de largo
 * hacia atras solo cuesta unas filas de scan; quedarse corto perderia
 * mediciones, asi que el margen va siempre para el lado barato.
 */
const SENALES_MARGEN_PISO = 2000;

/**
 * Primer id de `senales` cuya fecha entra en la ventana, con margen.
 *
 * Es una busqueda binaria sobre la PK: `fecha` no tiene indice, pero crece
 * junto con `id`, asi que cada sonda es un lookup puntual por clave
 * primaria (instantaneo) y en ~25 sondas se acota toda la tabla. El
 * resultado NO se usa como filtro exacto -- de eso se encarga el
 * `fecha >= :desde` de cada consulta -- sino para que el rango del indice
 * de `dispositivo` empiece cerca de la ventana en lugar de recorrer todo
 * el historial del equipo.
 */
function senalesPisoPorFecha(string $desde): int
{
    $lo = (int) db()->query('SELECT MIN(id) FROM senales')->fetchColumn();
    $hi = (int) db()->query('SELECT MAX(id) FROM senales')->fetchColumn();
    if ($lo <= 0 || $hi <= 0) {
        return 0;
    }

    // Se busca el primer id cuya fecha ya no es anterior a la ventana.
    $sonda = db()->prepare('SELECT fecha FROM senales WHERE id >= :probe ORDER BY id LIMIT 1');
    while ($lo < $hi) {
        $medio = intdiv($lo + $hi, 2);
        $sonda->execute([':probe' => $medio]);
        $fecha = (string) ($sonda->fetchColumn() ?: '');
        // Sin fila o con fecha nula se busca hacia atras: un piso de menos
        // solo cuesta scan, uno de mas se come mediciones.
        if ($fecha !== '' && $fecha < $desde) {
            $lo = $medio + 1;
        } else {
            $hi = $medio;
        }
    }

    return max(0, $lo - SENALES_MARGEN_PISO);
}
