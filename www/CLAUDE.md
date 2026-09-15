# www — el sitio público

`www.reactor.com.ar` (y el apex `reactor.com.ar`). Vhost en el **puerto 8134**;
en desarrollo, `http://localhost:8134`. Es la cuarta app del repo, junto a
`cloud/` (8086), `panel/` (8087) y `app/` (8115).

Reemplaza a `reactor-www` del repositorio legacy. **No se trajo una sola línea
de PHP de ahí**: el framework viejo (`/var/www/reactor-api/framework`, con
`$xBase`, `$oPagina`, `$oParametro`) no existe en este repo y no se va a traer.
Lo único que viene del legacy es el **front-end del theme** —`css/`, `js/`,
`fonts/`, `quform/css`, `quform/js`— y las imágenes que las páginas piden de
verdad. El PHP está escrito de nuevo contra PDO, con las mismas convenciones que
las otras tres apps.

## Es el único docroot sin login

A `cloud`, `panel` y `app` se entra con credenciales. **Acá no**: cada archivo de
este directorio lo puede pedir cualquiera de internet, sin sesión y sin límite de
intentos. Eso cambia tres cosas al agregar una página:

- **Todo lo que llega del request se escapa al imprimirlo**, con `e()`. La única
  excepción deliberada es `entradas`.`cuerpo`, que es HTML cargado por el back
  office y se emite crudo a propósito (blog, ayuda e info) — está comentado en
  los tres lugares.
- **Los formularios validan en el servidor y llevan antispam.** Los dos que hay
  —registro de instaladores y contacto— usan trampa de miel (campo oculto
  `sitio`) más un tiempo mínimo de 3 segundos (`desde`). El bot detectado ve la
  MISMA pantalla de éxito que una persona: avisarle que lo agarraron sólo sirve
  para que la próxima vuelta sea más difícil de detectar.
- **Los errores de PHP no se muestran.** `lib/inicio.php` deja `display_errors`
  en 0 cuando `APP_ENV` es `production`. El legacy lo tenía en 1 fijo, así que
  cualquier aviso salía impreso en el HTML con las rutas del servidor adentro.

## Arranque: `lib/inicio.php` y `DOCUMENT_ROOT`

Toda página empieza igual, sin excepción:

```php
require_once $_SERVER['DOCUMENT_ROOT'] . '/lib/inicio.php';
wwwTitulo('Blog');
require $_SERVER['DOCUMENT_ROOT'] . '/sistema/cabeza.php';
```

Acá **sí** se usa `DOCUMENT_ROOT` y no `dirname(__DIR__, n)` como en el resto del
repo, y es a propósito: las páginas viven a profundidades distintas (`/`,
`/blog/`, `/precios/planes/`), así que un `dirname()` habría que contarlo bien en
cada archivo nuevo y contar mal no rompe nada hasta que alguien mueve la página
de carpeta. Sólo lo sirve Apache; ninguna de estas páginas corre por CLI.

`sistema/cabeza.php` y `sistema/pie.php` son el marco; en el medio va el HTML del
theme. Los parciales que no son páginas **arrancan con guion bajo**
(`blog/_sidebar.php`, `precios/planes/_tabla.php`) y el `.htaccess` de la raíz
los bloquea con `<FilesMatch "^_">`.

## Las tres banderas que NO son `habilitado`

El CLAUDE.md del repo es tajante con `habilitado`: tinyint, 0/1, entero en el
SQL. **En este sitio hay tres columnas que parecen esa bandera y no lo son**, y
tratarlas como tal hace que MySQL castee la columna en cada fila:

| columna | tipo | valores | dónde |
|---|---|---|---|
| `entradas`.`visibilidad` | `varchar(1)` | `'1'` / `'0'` | blog, ayuda, info |
| `instaladores`.`aprobacion` | `varchar(1)` | `'1'` / `'2'` / `'0'` | instaladores |
| `instaladores`.`visibilidad` | `varchar(1)` | `'1'` / `'0'` | instaladores |

Las tres van comparadas contra la **cadena**. `aprobacion` ni siquiera es
booleana: `'1'` es "registrado, sin revisar" y `'2'` es "aprobado".

**Un instalador se publica sólo si tiene `aprobacion = '2'` Y
`visibilidad = '1'`.** Hay 7 aprobados que no se publican: la aprobación lo
habilita como instalador (cédula, dominios) y la visibilidad decide si además
sale en la vidriera. Filtrar por una sola publicaría gente que pidió no aparecer.

`planes`.`habilitado` **sí** es la bandera del repo y va con `esHabilitado()` y
el entero — `lib/habilitado.php` es copia idéntica de la de las otras tres apps.

## El contenido sale de `entradas`, y se pide POR RAMA

`entradascategorias` es jerárquica y de ella cuelgan las dos secciones:

```
115  Blog              116  Ayuda
110  Blog \ Notas      106  Ayuda \ Tutoriales
118  Blog \ Noticias   111  Ayuda \ Preguntas Frecuentes
```

El legacy enumeraba los hijos a mano en cada consulta (`categoria=110 or
categoria=118`), repetido en cinco archivos. Acá se pide la **rama** —la raíz más
sus hijos, resueltos con `padre`— así que una categoría nueva cargada en el back
office aparece sola. Las raíces (`ENTRADAS_BLOG`, `ENTRADAS_AYUDA`) sí están
fijas: no hay nada en la base que diga cuál es cuál.

- **Hay contenido colgado de la RAÍZ**, no sólo de las hojas: hoy una entrada en
  la categoría 115. Por eso la rama incluye la raíz, y por eso el enlace a su
  categoría lo arma `entradasUrlCategoria()` — construirlo como el de cualquier
  otra daba `/blog/blog`, que no es ninguna ruta y devolvía 404.
- **La ficha se busca acotada a su rama** (`entradaPorUuid()`), así que el slug de
  un artículo de ayuda no se renderiza dentro del blog con el breadcrumb
  equivocado y una URL duplicada. `entradaSuelta()` es la excepción, y existe
  sólo para `/info`: esas entradas tienen `categoria` en NULL.
- **Los slugs de categoría difieren entre las dos secciones y así tienen que
  quedar**: el blog usa el alias entero (`/blog/noticias`) y la ayuda sólo la
  primera palabra (`/ayuda/preguntas`, no `/ayuda/preguntas-frecuentes`). Son las
  URLs ya publicadas e indexadas; unificarlas rompe `/ayuda/preguntas`.
- **Las imágenes de las entradas no están en este repo.** `miniatura` e `imagen`
  guardan sólo el nombre del archivo y el resto lo sirve
  `https://media.reactor.com.ar/entradas` (`ENTRADAS_MEDIA`).
- **El blog esconde las entradas con fecha futura y la ayuda no.** Una nota
  fechada adelante está programada; un tutorial con fecha rara sigue siendo útil.

## Las tres salidas al exterior

Ninguna vive en este repo y las tres fallan sin voltear la página:

| salida | qué hace | dónde |
|---|---|---|
| CRM de Databox | contacto, registro de instaladores y suscripción al boletín | `lib/prospectos.php` |
| media server | las imágenes de las entradas | `ENTRADAS_MEDIA` |
| Google (GA4 + Ads) | medición | `lib/analytics.php` |

- **El CRM es `POST /v4/datarocket/prospectos` con `DATABOX_APIKEY`** — la misma
  constante del .env que usa el correo. El legacy la leía de la tabla
  `parametros`. Un POST da de alta prospecto + oportunidad + interacción de una,
  por eso `embudo`, `asunto` y `mensaje` son obligatorios los tres.
- **Si el CRM falla, lo que se hace depende de si hay respaldo local.** En el
  registro de instaladores la fila YA quedó guardada, así que el fallo va al log
  y la persona ve el éxito. En contacto el CRM es el destino final: ahí hay que
  decirlo, o la persona se va creyendo que escribió y nadie recibió nada.
- **La suscripción al boletín cambió de canal.** El legacy la mandaba a una lista
  de Mailjet con el cliente del framework viejo; acá entra al CRM con el asunto
  "Suscripción al boletín". El punto a cambiar si se vuelve a una lista de correo
  es sólo `sistema/suscribir.php`.
- **Las propiedades de Google son DOS y no son las de `app/`.**
  `WWW_GA_MEASUREMENT_ID` (`G-6F5J17H188`, propiedad *reactor-www*) y
  `WWW_GOOGLE_ADS_ID` (`AW-17108947322`). `GA_MEASUREMENT_ID` a secas es
  `G-CJM86BQMB4`, la de la app end-user: mezclarlas arruina las dos series. Las
  tres están vacías en desarrollo.

## Rutas: tres `.htaccess` y el orden importa

- **El de la raíz** resuelve `/foo` → `foo.php`, apaga el listado de directorios,
  pone las cabeceras y mapea `/sitemap.xml` a `sitemap.php`. La condición mira el
  DESTINO (`$1.php`) y no `REQUEST_FILENAME`: con esa variable la regla entra en
  un bucle de redirecciones internas que termina en 500 — está explicado en el
  archivo.
- **Los de `blog/` y `ayuda/`** resuelven las rutas fijas, después los `.php` de
  la carpeta y **al final** el comodín de slugs. El comodín lleva `[L]`, así que
  **todo lo que vaya después de él es código muerto**: en el legacy la regla de
  los `.php` estaba abajo y por eso `/blog/listar` devolvía 404.

**Una página que vive en una carpeta recibe un 301 de Apache a la URL con barra
final** (`/nosotros/contacto` → `/nosotros/contacto/`). En un GET es un salto de
más; **en un POST el navegador reintenta en GET y pierde el cuerpo**. Por eso el
formulario de contacto apunta a `/nosotros/contacto/` con la barra. Si se agrega
otro formulario dentro de una carpeta, va la barra.

## Assets y caché

`version.txt` es el cache-bust de todo el CSS y el JS, igual que en `cloud/` y
`panel/`: **al tocar cualquier archivo de `css/` o `js/` hay que subirlo**, o el
navegador sigue sirviendo la copia vieja. El legacy servía el CSS con `?rnd=478`
fijo, que no cambiaba nunca.

Las imágenes **no** llevan versión y se cachean un mes.

Del legacy se copió sólo lo que alguna página pide: 15 MB en vez de los 250 MB de
`reactor-www`. Lo que quedó afuera es material de demostración del theme
(`img/banner` 42 MB con cero referencias, `img/demos` 22 MB, `img/portfolio`,
`img/shop`, `img/team`). Si falta una imagen, está en
`reactor_legacy/reactor-www/img/`.

## Qué NO se portó, y por qué

- **Las fichas de los tres dispositivos** (`CE-B1COC`, `CE-D4CO`, `CEMA-M3NO`).
  Piden nueve imágenes cada una que no existen ni en el legacy ni en el servidor
  —hoy `www.reactor.com.ar/productos/dispositivos/CE-B1COC/` responde 404—, así
  que publicarlas era sumar tres páginas con los huecos a la vista. El catálogo
  enlaza en cambio la **hoja de datos en PDF**, que sí está.
- **`/preguntas`**: es el Lorem Ipsum en inglés que venía con el theme ("Neque
  porro quisquam est qui dolorem ?"). El FAQ real es `/ayuda/preguntas`, que está
  en el menú y sale de la base.
- **Todo el circuito transaccional** (`cuenta/`, `pagar/`, `pagos/`,
  `comprobante/`, `comprobantes/`, `contrato/`, `usuarios/`, `inscripciones/`,
  `agentes/`): decisión de alcance, el pedido fue el sitio público.
- **Las carpetas con sufijo `___`** (`info___/`, `productos/actuadores___/`),
  que en el legacy es la marca de "desactivado".
