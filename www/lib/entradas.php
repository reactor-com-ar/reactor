<?php

declare(strict_types=1);

/**
 * Contenido editorial: la tabla `entradas` y su árbol `entradascategorias`.
 *
 * Es la misma tabla que llena el back office y la que leía el sitio legacy con
 * `$xEntrada` / `$xEntradaCategoria`; lo que cambia es que acá se consulta con
 * PDO y sentencias preparadas en vez de armar el SQL concatenando strings.
 *
 * DOS SECCIONES, UN ÁRBOL. `entradascategorias` es jerárquica (`padre`) y de
 * ella cuelgan las dos secciones del sitio:
 *
 *   115  Blog                111  Ayuda \ Preguntas Frecuentes  (padre 116)
 *   110  Blog \ Notas        106  Ayuda \ Tutoriales            (padre 116)
 *   118  Blog \ Noticias     116  Ayuda
 *
 * El legacy enumeraba los hijos a mano en cada consulta (`categoria=110 or
 * categoria=118`), repetido en cinco archivos. Acá se pide la RAMA —la raíz más
 * sus hijos, resueltos con `padre`— así que una categoría nueva cargada en el
 * back office aparece sola, sin tocar código. Las raíces sí están fijas porque
 * son las que definen qué sección es cuál; no hay nada en la base que lo diga.
 *
 * `entradascategorias` también tiene ramas que NO son del sitio (las de
 * `padre = 1`, "Web \ Productos \ Familia" y compañía, que alimentan otro
 * sistema) y 26 entradas con `categoria` en NULL. Pedir siempre por rama las
 * deja afuera sin filtro extra.
 *
 * `visibilidad` NO ES LA BANDERA `habilitado`. Es `varchar(1)` con `'1'` /
 * `'0'`, así que va comparada contra la CADENA `'1'` y no contra el entero: la
 * regla de `habilitado` que documenta el CLAUDE.md del repo no aplica a esta
 * columna, y aplicarla haría que MySQL castee la columna a número en cada fila
 * y se coma el índice.
 */

require_once __DIR__ . '/db.php';

/** Raíz de la rama del blog en `entradascategorias`. */
const ENTRADAS_BLOG = 115;

/** Raíz de la rama de ayuda. */
const ENTRADAS_AYUDA = 116;

/**
 * Host de las imágenes de las entradas. Los archivos no viven en este repo:
 * `entradas`.`miniatura` / `imagen` guardan sólo el nombre (`20250726180538.jpg`)
 * y el resto lo sirve el media server, igual que en el legacy.
 */
const ENTRADAS_MEDIA = 'https://media.reactor.com.ar/entradas';

/**
 * Ids de una rama: la raíz y sus hijos directos.
 *
 * La raíz va incluida porque hay contenido colgado directamente de ella —hoy
 * una entrada en `115`— y dejarla afuera la escondería del listado sin que
 * nadie entienda por qué.
 */
function entradasRama(int $raiz): array
{
    static $cache = [];

    if (isset($cache[$raiz])) {
        return $cache[$raiz];
    }

    $sql = db()->prepare('SELECT id FROM entradascategorias WHERE padre = :raiz ORDER BY nombre');
    $sql->execute([':raiz' => $raiz]);

    $cache[$raiz] = array_merge([$raiz], array_map('intval', $sql->fetchAll(PDO::FETCH_COLUMN)));

    return $cache[$raiz];
}

/**
 * Categorías hijas de una rama, con su alias y cuántas entradas visibles tiene
 * cada una. Es lo que dibuja el widget "Categorías" de la barra lateral.
 *
 * El orden es `nombre DESC` como en el legacy, que en la práctica pone Noticias
 * antes que Notas.
 */
function entradasCategorias(int $raiz): array
{
    $sql = db()->prepare(
        'SELECT c.id,
                c.nombre,
                (SELECT COUNT(*) FROM entradas e
                  WHERE e.categoria = c.id AND e.visibilidad = :vis) AS cantidad
           FROM entradascategorias c
          WHERE c.padre = :raiz
          ORDER BY c.nombre DESC'
    );
    $sql->execute([':raiz' => $raiz, ':vis' => '1']);

    $filas = [];
    foreach ($sql->fetchAll() as $fila) {
        $filas[] = [
            'id'       => (int) $fila['id'],
            'nombre'   => (string) $fila['nombre'],
            'alias'    => entradasAlias((string) $fila['nombre']),
            'cantidad' => (int) $fila['cantidad'],
        ];
    }

    return $filas;
}

/**
 * Último tramo del nombre jerárquico: `Blog \ Noticias` -> `Noticias`.
 * Es el `id2alias()` / `alias()` del legacy.
 */
function entradasAlias(string $nombre): string
{
    $partes = explode(' \ ', $nombre);

    return trim((string) end($partes));
}

/** Alias de una categoría por id. Devuelve '' si el id no existe. */
function entradasAliasDeId(?int $id): string
{
    static $cache = [];

    if ($id === null) {
        return '';
    }
    if (isset($cache[$id])) {
        return $cache[$id];
    }

    $sql = db()->prepare('SELECT nombre FROM entradascategorias WHERE id = :id');
    $sql->execute([':id' => $id]);
    $nombre = $sql->fetchColumn();

    $cache[$id] = $nombre === false ? '' : entradasAlias((string) $nombre);

    return $cache[$id];
}

/**
 * Slug de una categoría para la URL.
 *
 * El blog usa el alias entero en minúsculas (`Noticias` -> `noticias`) y la
 * ayuda sólo la primera palabra (`Preguntas Frecuentes` -> `preguntas`), tal
 * como lo resolvían por separado `blog/categorias.php` y `ayuda/categorias.php`.
 * Se mantiene la diferencia porque son las URLs que ya están publicadas e
 * indexadas: unificarlas rompería `/ayuda/preguntas`.
 */
function entradasSlug(string $alias, bool $primeraPalabra = false): string
{
    $alias = mb_strtolower(trim($alias), 'UTF-8');
    if ($primeraPalabra) {
        $alias = explode(' ', $alias)[0];
    }

    return $alias;
}

/**
 * URL de la categoría de una entrada, dentro de su sección.
 *
 * CUIDADO CON LA RAÍZ. Hay contenido colgado directamente de la raíz de la rama
 * —hoy una entrada en la categoría 115, "Blog"— y para esas filas el alias es
 * el de la propia sección. Armar la URL como con cualquier otra categoría daba
 * `/blog/blog`, que no es ninguna ruta y devolvía 404: el `.htoaccess` sólo
 * conoce `/blog/noticias` y `/blog/notas`, y el comodín de slugs buscaba una
 * entrada con `uuid = 'blog'`. Cuando la categoría ES la raíz, el enlace que
 * corresponde es el de la sección entera.
 *
 * @param int  $raiz            ENTRADAS_BLOG o ENTRADAS_AYUDA.
 * @param string $seccion       '/blog' o '/ayuda'.
 * @param bool $primeraPalabra  true en ayuda (ver `entradasSlug()`).
 */
function entradasUrlCategoria(int $raiz, string $seccion, ?int $categoria, bool $primeraPalabra = false): string
{
    if ($categoria === null || $categoria === $raiz) {
        return $seccion;
    }

    $alias = entradasAliasDeId($categoria);

    return $alias === '' ? $seccion : $seccion . '/' . rawurlencode(entradasSlug($alias, $primeraPalabra));
}

/**
 * Listado de entradas de una rama.
 *
 * @param int    $raiz        ENTRADAS_BLOG o ENTRADAS_AYUDA.
 * @param int    $categoria   0 = toda la rama; un id filtra esa categoría sola.
 * @param string $buscar      Texto libre sobre volanta / título / bajada.
 * @param bool   $futuras     false descarta las entradas con fecha posterior a
 *                            hoy. El blog las oculta —una nota fechada adelante
 *                            está programada, no publicada— y la ayuda no, que
 *                            es como funcionaba el legacy: un tutorial con
 *                            fecha rara seguiría siendo útil y esconderlo sólo
 *                            lo haría desaparecer sin explicación.
 */
function entradasListar(int $raiz, int $categoria = 0, string $buscar = '', bool $futuras = true, int $limite = 60): array
{
    $rama = entradasRama($raiz);
    if ($categoria > 0) {
        // Una categoría pedida por URL tiene que pertenecer a la rama: si no,
        // `/blog?cat=106` mostraría tutoriales de ayuda dentro del blog.
        if (!in_array($categoria, $rama, true)) {
            return [];
        }
        $rama = [$categoria];
    }

    $marcas = implode(',', array_fill(0, count($rama), '?'));
    $where  = ['e.categoria IN (' . $marcas . ')', 'e.visibilidad = ?'];
    $args   = array_merge($rama, ['1']);

    if (!$futuras) {
        $where[] = 'e.fecha <= CURDATE()';
    }

    $buscar = trim($buscar);
    if ($buscar !== '') {
        $where[] = '(e.volanta LIKE ? OR e.titulo LIKE ? OR e.bajada LIKE ? OR e.cuerpo LIKE ?)';
        $patron  = '%' . entradasEscaparLike($buscar) . '%';
        array_push($args, $patron, $patron, $patron, $patron);
    }

    // El límite va interpolado y no bindeado a propósito: MySQL no acepta un
    // placeholder en LIMIT con `EMULATE_PREPARES => false`. Se castea a int y
    // se acota, así que no hay forma de que llegue algo que no sea un número.
    $limite = max(1, min(200, $limite));

    $sql = db()->prepare(
        'SELECT e.id, e.uuid, e.fecha, e.categoria, e.autor, e.volanta, e.titulo,
                e.bajada, e.miniatura, e.imagen, e.etiquetas
           FROM entradas e
          WHERE ' . implode(' AND ', $where) . '
          ORDER BY e.fecha DESC, e.id DESC
          LIMIT ' . $limite
    );
    $sql->execute($args);

    return $sql->fetchAll();
}

/** Cuántas entradas visibles tiene una rama entera. Para el "Todo (N)". */
function entradasCantidad(int $raiz): int
{
    $rama   = entradasRama($raiz);
    $marcas = implode(',', array_fill(0, count($rama), '?'));

    $sql = db()->prepare('SELECT COUNT(*) FROM entradas WHERE categoria IN (' . $marcas . ') AND visibilidad = ?');
    $sql->execute(array_merge($rama, ['1']));

    return (int) $sql->fetchColumn();
}

/**
 * Una entrada por su uuid (el slug de la URL), acotada a su rama.
 *
 * La rama importa: sin ella `/ayuda/<uuid-de-una-nota-del-blog>` renderizaría
 * la nota dentro de la sección de ayuda, con el breadcrumb equivocado y una URL
 * duplicada que los buscadores indexan como dos páginas distintas.
 *
 * Devuelve null si no existe, si no está visible o si es de otra rama.
 */
function entradaPorUuid(string $uuid, int $raiz): ?array
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return null;
    }

    $rama   = entradasRama($raiz);
    $marcas = implode(',', array_fill(0, count($rama), '?'));

    $sql = db()->prepare(
        'SELECT id, uuid, fecha, categoria, autor, volanta, titulo, bajada,
                cuerpo, etiquetas, miniatura, imagen
           FROM entradas
          WHERE uuid = ? AND visibilidad = ? AND categoria IN (' . $marcas . ')
          ORDER BY id DESC
          LIMIT 1'
    );
    $sql->execute(array_merge([$uuid, '1'], $rama));

    $fila = $sql->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Una entrada visible por su uuid, SIN acotar a ninguna rama.
 *
 * Es lo que necesita `/info`, que muestra páginas sueltas de contenido —la de
 * "Características de un sistema IoT" a la que enlaza la portada, por ejemplo—
 * que no cuelgan del blog ni de la ayuda: tienen `categoria` en NULL y por eso
 * `entradaPorUuid()` no las encuentra.
 *
 * Preferí SIEMPRE `entradaPorUuid()` cuando la página pertenece a una sección:
 * el filtro por rama es lo que evita que el mismo contenido se sirva desde dos
 * URLs distintas.
 */
function entradaSuelta(string $uuid): ?array
{
    $uuid = trim($uuid);
    if ($uuid === '') {
        return null;
    }

    $sql = db()->prepare(
        'SELECT id, uuid, fecha, categoria, autor, volanta, titulo, bajada,
                cuerpo, etiquetas, miniatura, imagen
           FROM entradas
          WHERE uuid = :uuid AND visibilidad = :vis
          ORDER BY id DESC
          LIMIT 1'
    );
    $sql->execute([':uuid' => $uuid, ':vis' => '1']);

    $fila = $sql->fetch();

    return $fila === false ? null : $fila;
}

/**
 * Las últimas entradas CON imagen de una rama, para el carrusel "Últimos
 * posts". Sin imagen el carrusel mostraría un hueco, así que se filtran acá y
 * no en la plantilla.
 */
function entradasUltimas(int $raiz, int $excepto = 0, int $limite = 5): array
{
    $rama   = entradasRama($raiz);
    $marcas = implode(',', array_fill(0, count($rama), '?'));
    $args   = array_merge($rama, ['1', '']);

    $where = 'categoria IN (' . $marcas . ') AND visibilidad = ? AND imagen <> ?';
    if ($excepto > 0) {
        $where  .= ' AND id <> ?';
        $args[]  = $excepto;
    }

    $limite = max(1, min(20, $limite));

    $sql = db()->prepare(
        'SELECT id, uuid, fecha, volanta, titulo, miniatura, imagen
           FROM entradas
          WHERE ' . $where . '
          ORDER BY fecha DESC, id DESC
          LIMIT ' . $limite
    );
    $sql->execute($args);

    return $sql->fetchAll();
}

/**
 * URL de la imagen grande o de la miniatura de una entrada.
 *
 * Devuelve '' cuando la entrada no tiene esa imagen cargada, para que quien
 * dibuja decida entre omitir el `<img>` o poner un placeholder — un `src` que
 * termina en `/` pide el índice del directorio y deja el ícono de imagen rota.
 */
function entradaImagen(array $entrada, string $campo = 'imagen'): string
{
    $archivo = trim((string) ($entrada[$campo] ?? ''));

    return $archivo === '' ? '' : ENTRADAS_MEDIA . '/' . rawurlencode($archivo);
}

/**
 * Miniatura redimensionada por el media server: `/mini?src=...&w=...&h=...`.
 * Es el `zoom()` del legacy. Cae a la imagen sin redimensionar si no hay
 * miniatura cargada.
 */
function entradaMiniatura(array $entrada, int $ancho = 636, int $alto = 444): string
{
    $archivo = trim((string) ($entrada['miniatura'] ?? ''));
    if ($archivo === '') {
        return entradaImagen($entrada, 'imagen');
    }

    return ENTRADAS_MEDIA . '/mini?' . http_build_query(['src' => $archivo, 'w' => $ancho, 'h' => $alto]);
}

/** Etiquetas de una entrada, ya separadas y sin vacíos. */
function entradaEtiquetas(array $entrada): array
{
    $crudas = explode(',', (string) ($entrada['etiquetas'] ?? ''));
    $limpias = [];
    foreach ($crudas as $etiqueta) {
        $etiqueta = trim($etiqueta);
        if ($etiqueta !== '') {
            $limpias[] = $etiqueta;
        }
    }

    return $limpias;
}

/**
 * Escapa los comodines de LIKE.
 *
 * El placeholder de PDO protege de la inyección, pero no de que un `%` tipeado
 * en el buscador se interprete como comodín: buscar `100%` sin esto devuelve
 * todo lo que empiece con `100`.
 */
function entradasEscaparLike(string $texto): string
{
    return str_replace(['\\', '%', '_'], ['\\\\', '\%', '\_'], $texto);
}

/** 'YYYY-MM-DD' -> 'DD/MM/YY', el formato de fecha que usa el theme. */
function entradaFecha(?string $fecha): string
{
    $ts = $fecha === null ? false : strtotime($fecha);

    return $ts === false ? '' : date('d/m/y', $ts);
}
