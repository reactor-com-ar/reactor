<?php

declare(strict_types=1);

// Sincronizador de tablas — metadata de los dos entornos + listado de tablas
// del origen.
// GET api/sincronizador_tables.php             -> { entornos: {dev, prod} }
// GET api/sincronizador_tables.php?origen=dev  -> { entornos, origen, tablas }
//
// Sin `origen` NO abre ninguna conexion: `sincEntornoInfo()` sale de parsear el
// `.env`, asi que las etiquetas "Entorno (nombreDeBD)" de los dos selectores se
// arman antes de que el operador elija nada y sin tocar una base. Recien con
// `origen` se conecta, y solo a ese entorno.
//
// UNA sola query contra el diccionario, pidiendo SOLO `TABLE_NAME`. Nada de
// cantidades de filas: ni COUNT(*) por tabla (129 full scans + 129 round-trips
// contra una RDS remota antes de que el <select> muestre nada) ni TABLE_ROWS
// (columna de estadisticas: obliga al motor a abrir cada tabla y tomarle un
// metadata lock salvo que la cache del diccionario siga vigente, con lo cual
// el costo es impredecible). El unico COUNT(*) de la herramienta es el del
// `run`, sobre la tabla ya elegida y con la terminal mostrando en que anda.

require __DIR__ . '/bootstrap.php';          // requireAuth() incluido
require __DIR__ . '/lib/sincronizador.php';

asertarSoloDev();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'OPTIONS') exit;
if ($method !== 'GET')     json_error('metodo_no_soportado', 405);

$entornos = [
    'dev'  => sincEntornoInfo('dev'),
    'prod' => sincEntornoInfo('prod'),
];

$origen = (string) ($_GET['origen'] ?? '');
if ($origen === '') {
    json_ok(['entornos' => $entornos, 'origen' => null, 'tablas' => []]);
}
if (!sincAmbienteValido($origen)) {
    json_error('Origen invalido: se espera "dev" o "prod".', 400);
}

try {
    $info = $entornos[$origen];
    $pdo  = sincPdo($origen);

    $stmt = $pdo->prepare(
        "SELECT TABLE_NAME AS nombre
           FROM INFORMATION_SCHEMA.TABLES
          WHERE TABLE_SCHEMA = :db
            AND TABLE_TYPE   = 'BASE TABLE'
          ORDER BY TABLE_NAME ASC"
    );
    // El filtro por TABLE_TYPE no es opcional: una vista no es sincronizable.
    $stmt->execute([':db' => $info['database']]);

    $tablas = [];
    foreach ($stmt->fetchAll() as $row) {
        $tablas[] = ['nombre' => (string) $row['nombre']];
    }

    json_ok([
        'entornos' => $entornos,
        'origen'   => $info,
        'tablas'   => $tablas,
    ]);
} catch (Throwable $e) {
    json_error('Error al listar las tablas del origen: ' . $e->getMessage(), 500);
}
