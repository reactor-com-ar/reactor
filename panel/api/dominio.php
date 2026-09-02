<?php

declare(strict_types=1);

/**
 * Ficha del dominio de la sesion.
 *
 *   GET api/dominio.php -> datos del dominio + inventario asociado
 *
 * Portado de reactor-panel/dominio/inicio.php, que mostraba nombre, numero,
 * usuarios, dispositivos, chips, situacion y habilitado del dominio con el
 * que estaba conectada la sesion.
 *
 * ALCANCE: el registro NO se elige por id. Se resuelve con requireDominioId()
 * -- igual que el legacy lo sacaba de `sesionDominio` -- para que nadie pueda
 * leer la ficha de otro dominio mandando un id a mano. El endpoint no acepta
 * ningun identificador de entrada.
 *
 * SOLO LECTURA: la ficha del dominio la administra el back office interno
 * (reactor-admin), no el cliente. Por eso no hay PUT ni DELETE. Las acciones
 * "Conectar / Desconectar dominio" del legacy tampoco se portan: operaban
 * sobre `perfiles` para cambiar de dominio dentro de la sesion, y este panel
 * todavia no tiene cambio de perfil.
 *
 * CONTADORES: usuarios / dispositivos / chips se calculan con COUNT(*), NO se
 * leen de las columnas cacheadas `dominios.usuarios` / `.dispositivos` /
 * `.chips` que si usaba el legacy: esos contadores los mantiene el sistema
 * viejo y estan desfasados (el dominio 2 declara 18 usuarios y tiene 5). Es la
 * misma decision que ya toma api/dashboard.php, y las tres tablas tienen
 * indice por `dominio` asi que los COUNT son baratos.
 *
 * SITUACION: `dominios.situacion` guarda el codigo corto (1, 2, 3). El texto
 * sale de `combos` con la clave '$xDominio->situacion' -- la misma que usaba
 * comboTraducir() en el legacy -- y SITUACIONES_FALLBACK cubre el caso de que
 * esa fila no este cargada.
 */

require __DIR__ . '/bootstrap.php';

/** Clave de `combos` con los textos de `dominios.situacion`. */
const COMBO_SITUACION = '$xDominio->situacion';

/** Ultimo recurso si `combos` no tiene cargada la clave de arriba. */
const SITUACIONES_FALLBACK = [
    ['valor' => '1', 'texto' => 'Normal'],
    ['valor' => '2', 'texto' => 'Limitado'],
    ['valor' => '3', 'texto' => 'Suspendido'],
];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    json_error('Metodo no permitido', 405);
}

try {
    $dominio = requireDominioId();

    $stmt = db()->prepare(
        'SELECT id, nombre, numero, situacion, habilitado
         FROM dominios
         WHERE id = :id
         LIMIT 1'
    );
    $stmt->execute([':id' => $dominio]);
    $row = $stmt->fetch();

    if (!$row) {
        // El id vive en el JWT: si la fila se borro despues del login, el token
        // sigue siendo valido pero apunta a la nada.
        json_error('El dominio de la sesion ya no existe. Volve a iniciar sesion.', 404);
    }

    $stmt = db()->prepare(
        'SELECT (SELECT COUNT(*) FROM usuarios     WHERE dominio = :dom1) AS usuarios,
                (SELECT COUNT(*) FROM dispositivos WHERE dominio = :dom2) AS dispositivos,
                (SELECT COUNT(*) FROM chips        WHERE dominio = :dom3) AS chips'
    );
    $stmt->execute([':dom1' => $dominio, ':dom2' => $dominio, ':dom3' => $dominio]);
    $t = $stmt->fetch() ?: ['usuarios' => 0, 'dispositivos' => 0, 'chips' => 0];

    json_ok([
        'dominio' => mapDominio($row),
        'totales' => [
            'usuarios'     => (int) $t['usuarios'],
            'dispositivos' => (int) $t['dispositivos'],
            'chips'        => (int) $t['chips'],
        ],
    ]);
} catch (Throwable $e) {
    json_error('Error al obtener el dominio: ' . $e->getMessage(), 500);
}

/* ------------------------------------------------------------------ */
/* Helpers                                                             */
/* ------------------------------------------------------------------ */

/** Normaliza la fila de `dominios` para el front, con la situacion traducida. */
function mapDominio(array $r): array
{
    $situacion = trim((string) ($r['situacion'] ?? ''));

    return [
        'id'               => (int) $r['id'],
        'nombre'           => trim((string) ($r['nombre'] ?? '')),
        'numero'           => trim((string) ($r['numero'] ?? '')),
        'situacion'        => $situacion,
        'situacion_texto'  => situaciones()[$situacion] ?? '',
        // `habilitado` es smallint y admite NULL: se normaliza a 1/0 para que
        // el front no tenga que distinguir NULL de 0 (ambos son "no").
        'habilitado'       => ((int) ($r['habilitado'] ?? 0)) === 1 ? 1 : 0,
    ];
}

/**
 * Tabla plana codigo -> texto de la situacion del dominio.
 * Se lee una sola vez por request.
 */
function situaciones(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $stmt = db()->prepare(
        'SELECT valor, texto FROM combos WHERE combo = :c ORDER BY orden ASC, texto ASC'
    );
    $stmt->execute([':c' => COMBO_SITUACION]);

    $filas = [];
    foreach ($stmt->fetchAll() as $r) {
        $valor = trim((string) ($r['valor'] ?? ''));
        if ($valor === '') {
            continue;
        }
        $filas[] = ['valor' => $valor, 'texto' => (string) ($r['texto'] ?? '')];
    }
    if ($filas === []) {
        $filas = SITUACIONES_FALLBACK;
    }

    $textos = [];
    foreach ($filas as $f) {
        $textos[$f['valor']] = $f['texto'];
    }

    return $cache = $textos;
}
