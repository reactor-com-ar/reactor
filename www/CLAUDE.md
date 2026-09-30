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
  —registro de técnicos y contacto— usan trampa de miel (campo oculto
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
| `tecnicos`.`aprobacion` | `varchar(1)` | `'1'` / `'2'` / `'0'` | técnicos |
| `tecnicos`.`visibilidad` | `varchar(1)` | `'1'` / `'0'` | técnicos |

Las tres van comparadas contra la **cadena**. `aprobacion` ni siquiera es
booleana: `'1'` es "registrado, sin revisar" y `'2'` es "aprobado".

**Un técnico se publica sólo si tiene `aprobacion = '2'` Y
`visibilidad = '1'`.** Hay 7 aprobados que no se publican: la aprobación lo
habilita como técnico (cédula, dominios) y la visibilidad decide si además
sale en la vidriera. Filtrar por una sola publicaría gente que pidió no aparecer.

`planes`.`habilitado` **sí** es la bandera del repo y va con `esHabilitado()` y
el entero — `lib/habilitado.php` es copia idéntica de la de las otras tres apps.

## `instaladores` se llama `tecnicos` (17/09/2026)

La sección entera cambió de nombre: la tabla, la carpeta, el lib, las funciones
y la URL pública. El rename de la base lo hizo
[cloud/sql/migrations/20260917_1000_instaladores_a_tecnicos.sql](../cloud/sql/migrations/20260917_1000_instaladores_a_tecnicos.sql),
que es un `RENAME TABLE` y nada más: ni una columna, ni un tipo, ni un valor
cambiaron.

| antes | ahora |
|---|---|
| tabla `instaladores` | tabla `tecnicos` |
| `www/instaladores/` | [www/tecnicos/](tecnicos/) |
| `lib/instaladores.php` | [lib/tecnicos.php](lib/tecnicos.php) |
| `instaladoresListar()`, `instaladorPorUuid()`, … | `tecnicosListar()`, `tecnicoPorUuid()`, … |
| `INSTALADORES_AVATAR` | `TECNICOS_AVATAR` |
| `/instaladores` | `/tecnicos` |

- **Las URLs viejas siguen respondiendo, con un 301** puesto arriba de todo en el
  `.htaccess` de la raíz — ver la sección de rutas. `/instaladores` estaba
  indexada y la ficha `/instaladores/consultar?uid=…` es la URL que los propios
  técnicos le pasan a sus clientes: sin el 301 todo eso es un 404.
- **`TECNICOS_AVATAR` sigue apuntando a `media.reactor.com.ar/instaladores/`** y
  no es un olvido: el archivo vive en el media server, que no es parte de este
  repo. Renombrar la constante no mueve la imagen; el día que se mueva allá se
  cambia acá.
- **`aprobacion` y `visibilidad` se suben desde el módulo Técnicos de `cloud`**
  (18/09/2026): *Propiedad → Técnicos*, con el ABM completo de la tabla
  ([cloud/api/tecnicos.php](../cloud/api/tecnicos.php), §38 de
  [cloud/DESIGN.md](../cloud/DESIGN.md)). El sitio público sólo inserta
  solicitudes —nacen `aprobacion = '1'` y `visibilidad = '0'`— y no las aprueba
  nunca. **El back office legacy —fuera de este repo— sigue leyendo la misma
  tabla**, así que puede hacer lo mismo y las dos pantallas escriben lo mismo;
  el módulo de cloud es el que se mantiene. **Aplicar la migración sin renombrar
  del otro lado deja ese módulo viejo apuntando a una tabla que ya no existe**,
  y está anotado también en la cabecera de la migración — desde que existe el de
  cloud eso ya no deja a nadie sin poder publicar, pero lo rompe igual.
- Lo que **no** se tocó son las menciones a "Técnico Instalador" de
  [panel/api/dominios.php](../panel/api/dominios.php) y de la migración
  `20260905_2300`: ése es un nombre de rol de la tabla `roles`, otra cosa.

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

## Las cinco salidas al exterior

Ninguna vive en este repo y las cinco fallan sin voltear la página:

| salida | qué hace | dónde |
|---|---|---|
| CRM de Databox | contacto, registro de técnicos y suscripción al boletín | `lib/prospectos.php` |
| media server | las imágenes de las entradas | `ENTRADAS_MEDIA` |
| Google (GA4 + Ads) | medición | `lib/analytics.php` |
| OpenAI | el chat con IA de la burbuja | `lib/chat.php` |
| expertos de Datarocket | el documento que explica cómo funciona Reactor | `lib/chat.php` |

- **OpenAI es la única que se cobra por request, y por eso es distinta.** Las
  otras cuatro cuestan lo mismo se usen o no; ahí cada POST de un anónimo es
  plata. Todo lo que la rodea —los cupos, el `Origin`, el tope de caracteres—
  sale de eso y está en la sección del chat.
- **Las dos últimas corren en el MISMO request y en ese orden**: primero se trae
  el documento del experto y después se le pregunta al modelo. Por eso la de
  Datarocket va cacheada y con timeout corto: lo que tarde se le suma a cada
  respuesta del chat.
- **`DATABOX_APIKEY` la usan las dos de Databox** —el CRM y los expertos— y es la
  misma fila de `aplicaciones`. Rotarla las apaga a las dos.

- **El CRM es `POST /v4/datarocket/prospectos` con `DATABOX_APIKEY`** — la misma
  constante del .env que usa el correo. El legacy la leía de la tabla
  `parametros`. Un POST da de alta prospecto + oportunidad + interacción de una,
  por eso `embudo`, `asunto` y `mensaje` son obligatorios los tres.
- **Si el CRM falla, lo que se hace depende de si hay respaldo local.** En el
  registro de técnicos la fila YA quedó guardada, así que el fallo va al log
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

## La burbuja de chat con IA (28/09/2026)

El botón flotante de la esquina **ya no es un enlace a WhatsApp**: abre un chat
atendido por un modelo de OpenAI. Son cinco archivos —
[lib/chat.php](lib/chat.php) (prompt, recuperación y llamada),
[lib/conversaciones.php](lib/conversaciones.php) (las dos tablas),
[chat/mensaje.php](chat/mensaje.php) (el endpoint), `js/chat.js` y `css/chat.css`—
más el widget en [sistema/pie.php](sistema/pie.php).

- **SIN `OPENAI_APIKEY` NO SE DIBUJA NADA.** `chatActivo()` gatea el `<link>` de
  la cabeza, el widget del pie y el endpoint. Es cómo se apaga el chat sin
  deployar, y es por qué en desarrollo la esquina está vacía: la constante va
  **vacía a propósito** en `.env.development`, igual que las propiedades de
  Google, porque cada mensaje de prueba se cobra en la cuenta real.
- **CADA MENSAJE ES PLATA, Y ESO ORDENA TODO EL DISEÑO.** Este docroot no tiene
  login: el endpoint lo puede llamar cualquiera de internet, y el escenario caro
  no es el visitante curioso sino alguien usándolo de proxy gratis de GPT. Los
  cuatro frenos, en el orden en que corren: tope de caracteres del mensaje, cupo
  de la conversación, cupo por IP por hora y **cupo diario global**, que es el
  único que sigue valiendo cuando el abusador rota IP. Los cuatro están en las
  constantes de `lib/chat.php`. El quinto no está en el repo: el tope de gasto
  mensual de la cuenta de OpenAI, que es el que vale si este código tiene un bug.
- **El cupo de la conversación se cuenta con un `UPDATE` condicional**
  (`SET mensajes = mensajes + 1 ... WHERE uuid = :uuid AND mensajes < :tope`),
  que valida y cuenta en la misma sentencia. Es el mismo candado de
  `enlaces_acceso`.`usos` y por el mismo motivo: con un `SELECT` previo, dos POST
  simultáneos del último turno pasarían los dos.
- **El historial NO viaja en el POST.** El navegador manda el `uuid` de la
  conversación y el texto nuevo; la charla se rearma desde
  `conversaciones_mensajes`. Si viajara, cualquiera podría reescribir lo que "ya
  se dijo" —las reglas del sistema incluidas— y hacernos pagar el contexto que se
  le antoje. El `sessionStorage` del front guarda una copia **sólo para volver a
  pintarla** al cambiar de página.
- **El chequeo de `Origin` no es el control de acceso** y está anotado así en el
  endpoint: quien arma el POST a mano pone la cabecera que quiera. Sirve para que
  otro sitio no embeba la burbuja y nos facture las consultas de sus visitantes.
  Lo que frena el gasto son los cupos.
- **Lo que el modelo sabe se arma en cada turno y sale de cuatro fuentes**, todas
  fuera del código salvo la última:

  | fuente | de dónde | cuándo entra |
  |---|---|---|
  | cómo funciona Reactor | experto `reactor-asesor` de Datarocket | siempre |
  | planes y precios | `planes` + `articulos` ([lib/planes.php](lib/planes.php)) | siempre |
  | artículos | `entradas` (ayuda y blog) | los 4 que matchean |
  | mapa de URLs del sitio | `chatSitio()`, en el código | siempre |

  Los precios salen de la base porque uno escrito a mano en el prompt quedaría
  viejo el día que alguien lo cambia en el back office, y el chat cotizaría
  distinto de `/precios/planes`. **El mapa de URLs es lo único que se queda en el
  código, y a propósito**: es un hecho de *este sitio* —qué secciones existen y
  en qué ruta—, no del producto. Si viviera del otro lado, renombrar una sección
  acá dejaría al bot repartiendo 404 hasta que alguien se acuerde de editar un
  documento en otro sistema.
- **LA DESCRIPCIÓN DEL PRODUCTO LA MANTIENE EL NEGOCIO, NO ESTE REPO.** Sale del
  microservicio de expertos (`GET /v4/datarocket/expertos?slug=reactor-asesor`,
  Markdown pelado, Bearer con `DATABOX_APIKEY`) y se edita en el panel de cloud
  de Databox, sin deploy. **Reemplazó al texto que estaba hardcodeado**, no se
  sumó: el experto describe a Reactor como control de accesos (portones,
  peatonales, invitaciones temporales, convivencia con RFID) y la portada de este
  sitio lo describe como plataforma IoT genérica. Con las dos versiones en el
  mismo prompt, el bot contesta distinto según qué párrafo agarre.
- **El documento se cachea 10 minutos en `sys_get_temp_dir()` y se revalida con
  `ETag`.** El endpoint manda `Cache-Control: no-cache`, así que al vencer se
  pregunta de nuevo — pero con `If-None-Match`, y un 304 no trae cuerpo. Sin
  caché, una conversación de diez mensajes serían diez viajes a otro servidor
  **antes** de cada llamada a OpenAI.
- **La copia vencida NO se tira si el endpoint no contesta.** Los tres niveles,
  en orden: endpoint, copia local aunque esté vieja, y `CHAT_PRODUCTO_MINIMO`.
  Quedarse sin conocimiento deja al bot diciendo "no sé" a todo, que es peor que
  contestar con un documento de hace dos horas porque *parece* que funciona.
- **Lo que viene del experto es DATO, nunca instrucción, y el prompt lo dice.**
  Ese texto lo edita gente fuera de este repo y entra al prompt de sistema, al
  lado de las reglas: sin esa línea, quien pueda escribir ahí puede reescribirle
  las reglas al bot desde afuera. Va también un tope de 60.000 caracteres, porque
  el documento entra en **cada** mensaje y el día que alguien pegue un manual
  entero la factura se multiplica sin que nadie de este lado se entere.
- **LA AYUDA SE LLEVA EL CUPO Y EL BLOG APORTA UNO SOLO.** El blog son notas de
  difusión escritas para convencer: una respuesta de soporte armada con tres de
  ellas suena igual de segura que una armada con la ayuda.
- **La recuperación cruza los términos con O y rankea, y es la única desviación
  deliberada del método de [lib/busqueda.php](lib/busqueda.php)**, que los cruza
  con Y. Acá no hay ninguna lista que mire una persona: es la selección del
  contexto del modelo, y los dos errores no cuestan lo mismo — un artículo de más
  lo descarta el modelo, y cero artículos lo dejan sin con qué contestar. Con la
  Y, "cómo configuro el wifi del dispositivo" **no trae nada**. Lo que reemplaza
  a la Y es el umbral de cobertura de `chatUmbral()`: con uno o dos términos se
  exigen todos (quien escribe "alarma comunitaria" nombra una cosa), de tres en
  adelante alcanza con la mitad. **Que no entre ningún artículo es un resultado
  válido**: el modelo contesta que no sabe y deriva, que es mejor que contestar
  con aplomo desde contexto flojo.
- **`lib/busqueda.php` es el método del proyecto y `entradasListar()` todavía no
  lo usa**: el buscador público (`/search/results`) sigue buscando la frase
  entera con un solo `LIKE`. Convertirlo hace que encuentre más, o sea que cambia
  lo que ve el visitante, y va aparte.
- **La respuesta del modelo se pinta con `createTextNode()`, nunca con
  `innerHTML`.** Es la salida de algo a lo que cualquiera le puede pedir lo que
  quiera: con `innerHTML` alcanzaría con convencerlo de escribir una etiqueta
  para tener XSS en todas las páginas del sitio. Sólo se enlazan `http` y
  `https` — un `javascript:` en un `href` es la otra mitad del mismo agujero.
- **El chat no ve ninguna cuenta y el prompt se lo dice tres veces.** No hay
  sesión en este docroot: no puede responder por dispositivos, señales, facturas
  ni usuarios, y lo que corresponde ahí es derivar a `app.reactor.com.ar` o a
  WhatsApp. El día que se quiera un chat que sí conozca a la persona, no es esta
  UI: es otra, identificada.
- **WhatsApp no se perdió.** Está en el pie del panel, en el footer y en el menú
  de Soporte, y es a donde derivan todos los errores del endpoint (`derivar` en
  la respuesta JSON prende el enlace).
- **Se declaró en [privacidad.php](privacidad.php)**, que es donde ya está la
  lista de proveedores: el dato sale del país y eso se dice.
- **CADA MENSAJE GUARDA LA URL COMPLETA DESDE LA QUE SE ESCRIBIÓ**
  (`conversaciones_mensajes`.`origen`, 29/09/2026), y no alcanza con la de la
  conversación: la burbuja está en el pie de **todas** las páginas y el `uuid`
  vive en `sessionStorage`, así que alguien pregunta por los planes en
  `/precios/planes`, sigue navegando y tres mensajes después pregunta otra cosa
  parado en `/ayuda`. `conversaciones`.`pagina` sólo sabe dónde se **abrió**.
  Va la URL entera y no el path: el host y la querystring son parte del contexto
  —una consulta que llegó con `?utm_source=` dice de dónde salió esa persona—.
  **La valida el servidor** (`conversacionOrigenValido()`): sólo `http`/`https`
  y sólo del propio host, porque ese campo lo escribe el cliente y lo termina
  leyendo un operador en cloud. Lo que no pasa queda en `NULL`.
  **Ojo con el nombre**: `conversaciones`.`origen` es la **IP** y
  `conversaciones_mensajes`.`origen` es la **URL** — mismo nombre, dos
  significados, en dos tablas que se leen siempre juntas.
- **SI BORRAN LA CONVERSACIÓN DESDE CLOUD, EL CHAT SE REINICIA SOLO** y quien
  estaba escribiendo no se entera. El navegador guarda el `uuid` en
  `sessionStorage`, así que después de una baja lo sigue mandando: el endpoint
  contesta **409 con `reiniciar: true`** —una bandera para el cliente, no un
  texto para leer—, el front olvida el uuid y **reenvía el mismo mensaje**, que
  abre una conversación nueva.
  Hasta el 29/09/2026 ahí salía *"La conversación expiró. Recargá la página"* y
  era un callejón sin salida doble: **recargar no limpia `sessionStorage`** —
  muere con la pestaña, no con la recarga— así que el consejo no servía, y el
  uuid muerto seguía viajando en cada mensaje, que fallaban todos igual. Una
  baja hecha por privacidad desde el panel no puede dejar a una persona sin
  poder escribir.
  **El reenvío es UNO solo** (`reintento`): si el segundo intento también falla
  se muestra el error, porque un ciclo de reintentos contra un endpoint que
  rechaza es cómo un error se convierte en una tormenta de requests pagos.
  **Lo que ya está pintado en pantalla no se borra**: sacarle a alguien de la
  vista lo que acaba de escribir es peor que dejar dos burbujas que el asistente
  ya no recuerda.
- **Las conversaciones se leen desde `cloud`**, en *Comunicación →
  Conversaciones* ([cloud/api/conversaciones.php](../cloud/api/conversaciones.php),
  §40 de [cloud/DESIGN.md](../cloud/DESIGN.md)): el listado con la primera
  pregunta de cada charla, la ficha con el diálogo completo y la baja. **La baja
  existe ahí y no en los otros módulos read-only por un motivo concreto**: estas
  dos tablas son las únicas que acumulan texto libre, IP y navegador de gente que
  nunca se registró.
- **Lo que NO hay todavía es una política de retención.** Las filas se acumulan
  sin límite y nada las purga; el borrado de cloud es fila por fila. Cuando se
  decida el plazo, la tarea va al Programador de tareas de cloud.

## Rutas: tres `.htaccess` y el orden importa

- **El de la raíz** resuelve `/foo` → `foo.php`, apaga el listado de directorios,
  pone las cabeceras y mapea `/sitemap.xml` a `sitemap.php`. La condición mira el
  DESTINO (`$1.php`) y no `REQUEST_FILENAME`: con esa variable la regla entra en
  un bucle de redirecciones internas que termina en 500 — está explicado en el
  archivo.
- **Arriba de todo, antes que cualquier otra regla, va el 301 de
  `/instaladores` → `/tecnicos`**, con un grupo opcional que cubre las cuatro
  rutas de la sección de una sola vez. La querystring viaja sola: mod_rewrite la
  reinyecta cuando la sustitución no trae `?`, así que el `uid` de las fichas ya
  compartidas llega entero. **No se borra**: es el mismo criterio con el que
  `/sitemap.xml` responde aunque el archivo se genere y con el que
  `/ayuda/preguntas` conserva su slug corto — una URL publicada no se cambia, se
  redirige.
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

### Font Awesome 6.5.1 Pro, y es el ÚNICO (28/09/2026)

El paquete autohospedado vive en [fontawesome/](fontawesome/) — el mismo recorte
que `panel/assets/fontawesome/` y `cloud/assets/fontawesome/`, de donde se copió.
Lo enlazan [sistema/cabeza.php](sistema/cabeza.php) y
[lib/mensaje.php](lib/mensaje.php), que son los dos únicos `<head>` del sitio.
El detalle del paquete está en [fontawesome/README.md](fontawesome/README.md).

- **Antes se cargaba DOS veces y ninguna era la buena**: la copia **Free 6.0.0**
  del theme (`css/plugins/fontawesome-all.min.css`, importada desde
  `css/plugins.css`, con sus cuatro fuentes en `fonts/fa-*`) y encima un `<link>`
  a `cdn.jsdelivr.net` con `fontawesome-free@6.0.0-beta2` puesto **al final del
  `<head>`**, o sea una **beta** de otra versión, servida por un tercero, que
  además ganaba por orden. Las dos se eliminaron.
- **Ir a Pro no cambia cómo se escriben las clases, cambia qué íconos existen.**
  El theme mezcla las tres notaciones (`fa-solid fa-comment-dots`, `fas fa-search`,
  `fa fa-home`) y las tres siguen resolviendo. Lo que en Free caía en cuadrado
  vacío es el estilo **regular**, que ahí trae ~160 íconos: por eso
  `far fa-file-pdf`, `far fa-envelope` y `far fa-paper-plane` —el catálogo y el
  formulario de contacto— recién ahora renderizan.
- **Se enlazan las cinco hojas** (`all.min.css` + las cuatro `sharp-*`) y eso no
  cuesta los 4 MB de `webfonts/`: el browser pide cada `.woff2` recién cuando la
  página dibuja un glifo de esa familia, así que hoy bajan tres de los once. Las
  `sharp-*` van porque `all.min.css` **mapea** las clases `fa-sharp` pero no
  declara sus `@font-face`.
- **NO se importa desde `css/plugins.css`**, a diferencia de todos los demás
  plugins del theme: los `@import` de ese archivo viajan sin `?v=`. El cache-bust
  de FA es el **`filemtime` de `fontawesome/css/all.min.css`**, independiente de
  `version.txt` — reemplazar el paquete lo refresca solo, y un bump de
  `version.txt` no obliga a rebajar los ~350 KB de la fuente. Mismo criterio que
  `panel/index.php`.
- **Los pseudo-elementos del theme piden la familia por NOMBRE, no por clase**, y
  pedían una que nunca estuvo declarada en este sitio: `Font Awesome\ 5 Free`.
  Son siete adornos de `css/styles-red.css` —flechas del breadcrumb, comillas de
  los testimonios, chevrones del footer— que venían saliendo en blanco con Free
  6 **y también con la beta del CDN**, porque ninguna de las dos declara esa
  familia. Ahora dicen `"Font Awesome 6 Pro"`. **Al tocar el theme, ojo con
  esto**: un `content: '\f105'` no falla, simplemente no dibuja nada.
- **No se copió `icons.json`** (~630 KB), que sí está en `panel/` y `cloud/`: es
  el catálogo del "Explorador FA6" del módulo Herramientas y acá no hay módulo
  que lo consuma. Este docroot además **no tiene login**: todo lo que se deje
  adentro queda publicado.

## Qué NO se portó, y por qué

- **Las fichas de los tres dispositivos** (`CE-B1COC`, `CE-D4CO`, `CEMA-M3NO`).
  Piden nueve imágenes cada una que no existen ni en el legacy ni en el servidor
  —hoy `www.reactor.com.ar/productos/dispositivos/CE-B1COC/` responde 404—, así
  que publicarlas era sumar tres páginas con los huecos a la vista. El catálogo
  enlaza en cambio la **hoja de datos en PDF**, que sí está.
- **`/preguntas`**: es el Lorem Ipsum en inglés que venía con el theme ("Neque
  porro quisquam est qui dolorem ?"). El FAQ real es `/ayuda/preguntas`, que está
  en el menú y sale de la base.
- **Casi todo el circuito transaccional** (`cuenta/`, `pagos/`, `comprobantes/`,
  `contrato/`, `usuarios/`, `inscripciones/`, `agentes/`): decisión de alcance,
  el pedido fue el sitio público. **`comprobante/` y `pagar/` SÍ se portaron**
  (30/09/2026) — ver la sección siguiente: son las dos URLs que el sistema
  *nuevo* ya emite hacia acá.
- **Las carpetas con sufijo `___`** (`info___/`, `productos/actuadores___/`),
  que en el legacy es la marca de "desactivado".

## El comprobante y su pago (30/09/2026)

Son cinco archivos más `lib/comprobantes.php`, y existen porque **el sistema
nuevo ya emite dos URLs de este sitio que hasta ahora no respondían**:

| URL | quién la emite | qué hace |
|---|---|---|
| `/comprobante/visor?uuid=` | el botón **Abrir** del correo (`accionCorreo()` en [cloud/api/comprobantes_accion.php](../cloud/api/comprobantes_accion.php)) y el *Compartir* del back office viejo | la ficha: datos, total, estado y los tres botones |
| `/pagar/?uid=` | el ítem *Botón de pago* de Comercial → Comprobantes (`PAGO_BASE` en [cloud/api/comprobantes_lib.php](../cloud/api/comprobantes_lib.php)) | 302 al visor |
| `/comprobante/hoja?uuid=` | el visor | la hoja A4 imprimible |
| `/comprobante/abrir?uuid=` | el back office viejo y el panel legacy | 302 a la hoja |
| `/comprobante/descargar?uuid=` | ídem | 302 a la hoja con el diálogo de impresión |
| `/comprobante/pagar` | el botón **Pagar** del visor (POST) | 302 al cobrador de Databox |

- **LAS SEIS SON URLs PUBLICADAS Y NO SE CAMBIAN.** La del visor está en las
  casillas de correo de los clientes; `abrir` y `descargar` las enlazan
  `reactor-admin/comprobantes/consultar.php` y
  `reactor-panel/comprobantes/consultar.php`, que siguen vivas del otro lado de
  la transición. Es el mismo criterio de `/instaladores` y `/ayuda/preguntas`.
- **EL `uuid` ES LA ÚNICA CREDENCIAL**, y de ahí salen tres reglas. Se valida
  por FORMA antes de tocar la base —16 alfanuméricos en mayúscula, que es lo que
  tienen los 2.332 comprobantes— así que un identificador mal armado se contesta
  404 sin consultar. El visor y la hoja van **`noindex`**: lo que muestran es la
  razón social, el CUIT, el domicilio, el correo y el celular de un cliente, y el
  legacy los tenía indexables. Y **no hace falta cupo por IP** como en el chat:
  son 36^16 combinaciones y el uuid no es correlativo.
- **`wwwRobots(false)` es nuevo y vive en [lib/pagina.php](lib/pagina.php)**;
  `sistema/cabeza.php` emite la meta sólo cuando está apagado, así que ninguna
  otra página cambia.
- **LOS CUATRO CANDADOS DEL PAGO son el motivo de todo esto**
  (`comprobantePagable()`): es una prefactura (`talonarios.tipo = 'F'`), está
  **Pendiente** (`estado = '2'`), el total es mayor a cero y tiene número de
  serie. `comprobante/pagar.php` del legacy **no chequeaba ninguno**: leía el
  comprobante y redirigía al cobrador. Los tenía sólo la otra puerta,
  `pagar/index.php`, así que **la misma factura era pagable dos veces según por
  qué enlace se entrara**. De las 1.222 prefacturas de la base hay **969
  canceladas**: sin el candado del estado, reabrir un correo viejo vuelve a
  cobrar una factura ya paga. El total en cero tampoco es teórico — la última
  prefactura emitida está en 0,00 y MercadoPago la rechaza del otro lado, después
  de que la persona ya se fue del sitio.
- **El chequeo del servidor es el control; esconder el botón no.** El visor no
  dibuja *Pagar* y `pagar.php` corta igual, con el motivo en criollo
  (`comprobanteMotivoNoPagable()`): "Esta factura ya está paga", "fue anulada",
  "todavía no fue emitida". El legacy escondía el botón y no ponía nada en su
  lugar.
- **Ninguna página enlaza el pago con un `<a href>`**: el botón del visor es un
  **POST**. Un GET que crea una preferencia de cobro del otro lado lo dispara
  solo el prefetch del navegador o el preview del cliente de correo — el mismo
  motivo por el que `Aceptar` de las invitaciones dejó de ser un enlace. **El GET
  se sigue atendiendo** porque el enlace viejo está en correos ya enviados; lo
  que se sacó del HTML es la URL, no la ruta.
- **`fct` es el id del comprobante y NO SE TOCA**: es con lo que Databox concilia
  el pago. **El concepto sí se arregló**: el legacy lo armaba con
  `$xComprobante->punto`, una propiedad que `cComprobante` no declara —el punto
  de venta está en `talonarios`— así que todas las preferencias salieron
  diciendo "Factura 000-3349".
- **La vuelta del cobrador se muestra.** `ret` apunta al visor y éste lee `res`
  (`A` aprobado / `R` rechazado) para pintar el cartel arriba del comprobante. En
  el legacy ese cartel estaba escrito en `pagar.php` pero era **código muerto**:
  el `ret` apuntaba al visor, no ahí, así que nadie lo alcanzaba nunca. Un `res`
  que no se reconozca se trata como **pendiente y no como aprobado**.
  **Volver con `res=A` NO significa que el comprobante figure pago**: este sitio
  no escribe nada en la base, quien lo marca cancelado es el circuito de pagos
  del back office al conciliar. Por eso el visor muestra el estado real debajo.
- **`/pagar/?uid=` manda al visor y no salta al cobrador** como hacía el legacy.
  Quien lo abre desde cloud es un operador que necesita ver el comprobante y
  copiar el enlace; quien lo recibe es un cliente, y pagar sin ver qué se paga no
  es un atajo. Además un GET no crea una preferencia de cobro.

### La hoja es HTML, no un PDF generado

El legacy armaba el PDF con **dompdf**, y el camino era una vuelta entera: la
página pedía por HTTP su propia `hoja?id=<id cifrado>` con `file_get_contents`,
le pasaba el HTML a dompdf y devolvía el binario. **Acá no hay composer ni
`vendor/` en ninguna de las cuatro apps**, y `php:8.2-apache` no trae `gd` —que
dompdf necesita para el JPG del membrete—, así que traerlo era sumar una
dependencia y un cambio de Dockerfile para reproducir un documento que el
navegador ya sabe imprimir. *Descargar* abre el diálogo de impresión y "Guardar
como PDF" da el mismo papel: **una sola hoja A4 con el membrete embebido**,
verificado.

- **Lo que se pierde es el NOMBRE del archivo**, que en el legacy fijaba
  `cComprobante::archivo()`. Se compensa con el `<title>`, que es lo que los
  navegadores proponen al guardar: sale igual, `PREFACTURA - 001-007290 -
  2026-09-15`.
- **`print-color-adjust: exact` NO es decorativo.** El membrete es una imagen de
  fondo y los fondos no se imprimen salvo que se pida: sin esa regla la hoja sale
  sin la banda gris, sin el logo y con el texto blanco del pie invisible sobre
  blanco.
- **LAS POSICIONES SON ABSOLUTAS Y ESTÁN ATADAS A `fondo.jpg`.** El membrete es
  una A4 completa con recuadros dibujados, y cada bloque de texto tiene que caer
  adentro del suyo. Las coordenadas son las del legacy con el margen de 43px que
  dompdf le ponía al body ya sumado. **Mover un bloque sin mirar la imagen lo
  saca del recuadro.**
- **`talonarios`.`fondo` guarda sólo el nombre del archivo** (`fondo.jpg` en las
  18 filas). En el legacy lo resolvía el chroot de dompdf contra
  `/var/www/reactor-www/comprobante/`; acá se resuelve contra la misma carpeta de
  este sitio y **sólo si el archivo existe**: una hoja sin membrete se lee igual,
  una que pide una imagen que no está queda con el ícono roto.
- **Con más de 20 renglones se aprieta el interlineado, no se recorta la lista**
  (`.cuerpo.denso`). El alto entre el encabezado y los recuadros del pie es fijo
  —610px— y no es una preferencia estética: el membrete dibuja los recuadros ahí.
  El comprobante más largo de la base tiene **27 renglones** y con el
  interlineado normal tapaba los dos recuadros y dejaba el "Total ARS" encima de
  Observaciones. `overflow` queda en `visible` igual: recortar en silencio un
  renglón es esconder lo que se facturó y nadie lo notaría hasta que el cliente
  reclame.
- **`imprimir.php` no se portó.** Aceptaba el id cifrado con la XOR del legacy y
  **nada lo enlaza**: era el intermediario entre `abrir`/`descargar` y dompdf.
  Portarlo obligaba a traer ese cifrado a este repo para no ganar ninguna ruta
  nueva.
