# Convenciones de módulos ABM

Reglas para generar módulos ABM (Alta, Baja, Modificación). Todo módulo nuevo debe respetarlas salvo indicación contraria.

## Listado

Las columnas del listado deben respetar este orden:

1. **Primera columna: `Código`**
   - Corresponde al ID de la tabla.
   - El título de la columna es `Código` (no `ID`).

2. **Columnas importantes de la tabla**
   - Los campos relevantes de la entidad.

3. **Columna `Acciones`, al final:**
   - Una sola columna llamada **`Acciones`** que contiene un botón con **ícono hamburguesa** (`fa-bars`).
   - El click sobre el botón **y** el **click derecho** sobre cualquier punto de la fila abren el **mismo menú contextual**, posicionado en el punto de activación.
   - **Click izquierdo sobre la fila = acción por defecto.** Un módulo puede habilitar el atajo agregando `class="row-clickable"` al `<tr>` (cursor pointer, §10 de `DESIGN.md`) y un listener de `click` en la fila. La acción por defecto es **Consultar** (en módulos sin modal de consulta, como el Editor de parámetros, es Editar). El botón hamburguesa frena la propagación para no disparar el atajo. El atajo es el comportamiento estándar de todo listado ABM: está activo en **Dominios, Dispositivos, Chips, Transceptores, Contratos, Comprobantes, Talonarios, Clientes, Artículos, Planes, Notificaciones, Difusión, Señales, Registros, Adopciones, Usuarios, Perfiles, Controladores, Roles y Permisos** (más la solapa Perfiles del modal de Consultar de Usuarios).
   - El menú contextual debe incluir, como mínimo y en este orden:
     - **Consultar** — ícono de ojo (`fa-eye`).
     - **Editar** — ícono de lápiz (`fa-pencil`).
     - **Eliminar** — ícono de tacho (`fa-trash`), en estilo de peligro.
   - **`Eliminar` va siempre al final del menú y siempre precedido por la línea separadora.** Ninguna acción se ubica por debajo de Eliminar.
   - Los módulos read-only (señales, registros, etc.) omiten Editar y Eliminar — el menú sólo trae Consultar.
   - El menú puede incluir acciones extra propias del módulo (ej.: "Ver dispositivos asociados", "Listar perfiles", "Copiar ID"). Hay dos ubicaciones posibles:
     - **Pegadas a `Consultar`, sin divisor**, cuando la acción es otra forma de *ver* el registro (ej.: Usuarios → "Listar perfiles" va inmediatamente debajo de Consultar, antes de Editar).
     - **Al final del bloque no destructivo, separadas por divisor**, para el resto (copiar valores, navegaciones secundarias).
   - Orden completo resultante: `Consultar · [extras de consulta] · Editar · --- · [extras generales] · --- · Eliminar`.
   - **Acciones de negocio** (las que ejecutan una transición de la entidad, no la editan): van **primeras dentro del bloque de extras**, antes de las navegaciones y las copias. Son lo que el operador viene a hacer sobre el registro; una navegación secundaria no puede quedarle arriba. Ejemplo: Contratos → `Facturar` / `Dar de baja` (las dos del menú `Acciones` del back office viejo).
     - **Se dibujan sólo cuando aplican, y el endpoint las corta igual.** El menú omite la que no corresponde —`Facturar` y `Dar de baja` sólo con el contrato habilitado— y el backend revalida la misma condición leyéndola de la base, dentro de la transacción. Esconder el botón no es el control (`CLAUDE.md`).
     - **La condición vive en una función, no repetida en cada menú.** El menú de la fila y el del modal de Consultar ofrecen las mismas acciones y preguntan por la misma vía (`contratoOperable(c)`); con la condición copiada, agregarle un caso deja un menú ofreciendo lo que el otro ya esconde.
     - **Van en su propio endpoint `<entidad>_accion.php?accion=<nombre>`**, no en el `PUT` del ABM: no son "guardar los campos que mandaste" y suelen tocar más de una tabla. **`GET` previsualiza y `POST` ejecuta**, resolviendo los dos la misma función del backend. Cuando lo que la acción va a hacer no entra en una frase, la confirmación es el modal con previsualización de `DESIGN.md` §15.2; cuando sí, alcanza `confirmDialog` con `label` y `tono` propios.
   - **Acciones de navegación cruzada** (listar los registros relacionados en su propio módulo): dejan el id pedido en el scope de la app y navegan con `window.location.hash = '#/<ruta>'`. El módulo destino lo consume en su `render*()`, lo vuelca al `state` del filtro correspondiente y lo limpia. El filtro usado tiene que existir además como campo del **Modal de Filtros** del módulo destino, para que el usuario vea por qué la lista viene acotada y pueda limpiarlo. Ejemplos: Dominios → "Ver dispositivos asociados" (`#/dispositivos`, filtro `Dominio`), Usuarios → "Listar perfiles" (`#/profiles`, filtro `Usuario`), Dispositivos → "Listar señales" (`#/signals`, filtro `Dispositivo`), Contratos → "Ver dominio" (`#/dominios`, filtro `Código` — ahí el dominio es la fila y no una FK, así que el pedido se vuelca en el filtro del id), Notificaciones → "Ver dominio" (ídem) y Dominios → "Notificaciones" / "Difusiones" (`#/notificaciones` con filtro `Dominio`, `#/difusion` con filtro `Alcance`).
     - **El filtro del destino tiene que significar lo mismo que el pedido.** `#/difusion` filtra por el **alcance con el que se creó la campaña**, no por quién la recibió: una difusión global (alcance `NULL`) no aparece al pedir un dominio aunque a esa gente también le haya llegado. Es la lectura correcta del filtro y por eso el ítem del menú dice `Difusiones` y no "Difusiones recibidas".
     - Hay un par de helpers por entidad: `pedirFiltroDominio(route, id)` / `tomarFiltroDominio(route)` y `pedirFiltroUsuario(route, campo, id)` / `tomarFiltroUsuario(route)`. Guardan `{ route, … }` y **sólo aplican si la ruta coincide** — si el usuario se desvía a otra pantalla el pedido se descarta en vez de filtrar un listado equivocado más tarde (ver `DESIGN.md` §21-bis.1). El de usuario lleva además el **campo** destino (`usuario` en Perfiles, `adoptador` / `liberador` en Adopciones): la misma persona entra por columnas distintas según el módulo. Las variables sueltas `pendingXxxFilter` son el patrón viejo y queda sólo la de dispositivo.
     - Cuando el endpoint destino **sabe filtrar por esa entidad, el filtro va en el fetch inicial** además del filtrado client-side: en tablas grandes (`registros`, `senales`, `adopciones`) recortar recién después de traer la ventana muestra "las N últimas de todos" filtradas, que para un registro chico puede dar vacío.

### Límite de resultados
- Por defecto: **100**.
- Modificable por el usuario desde el campo `Límite` del buscador.

### Dónde se filtra: en el navegador o en el servidor

- **EL TEXTO LIBRE SIEMPRE EN EL SERVIDOR.** El buscador rápido y el campo `Buscar` del modal de Filtros se resuelven en SQL, en los **21** listados, sin excepción — ver "Cómo busca el texto libre". Esto no depende del tamaño de la tabla: un filtro de texto en el navegador sólo ve lo que se trajo, así que el día que el endpoint gane un `LIMIT` contesta *"no hay resultados"* sobre filas que existen, que es la única respuesta que un buscador no puede dar.
- **El resto de los filtros, por defecto en el navegador.** El módulo se trae la tabla entera en el `render*()` y el modal de Filtros recorta ese array. Es lo que hacen casi todos: con 18 talonarios o 50 contratos traer todo es más barato que ida y vuelta por cada filtro.
- **En el servidor cuando la tabla es grande y crece** (Comprobantes: 2.326 filas y una más por cada facturación). Ahí el modal de Filtros arma una query string, el endpoint filtra en SQL y `Aplicar` **vuelve a pedir la ventana** en vez de recortar un array.
- **Cuando se filtra en el servidor, la búsqueda rápida de la toolbar sigue siendo client-side** y opera **sólo sobre la ventana traída**. Son dos cosas distintas y hay que decirlo en la pantalla, o el operador concluye que un comprobante no existe cuando lo que pasa es que quedó fuera del límite:
  - el `placeholder` del buscador lo aclara (`Buscar en los resultados…`),
  - el modal de Filtros lleva una `.form-nota` que explica cuál es cuál,
  - y el listado lleva **pie de totales de la consulta** (§25-ter de `DESIGN.md`) con el aviso de recorte.
- **Refrescar y los filtros no son lo mismo.** Refrescar re-renderiza el módulo entero —KPIs incluidos— vía `refrescarVista()`; aplicar un filtro sólo vuelve a pedir la tabla. Los KPIs de la cabecera son **del universo completo, no de la consulta**: un contador que cambiara al filtrar dejaría de ser un KPI.

## Buscador

### Toolbar

Arriba de la tabla, y en este orden de izquierda a derecha:

1. **Buscador rápido** (`.search-wrap > .search-input`), con su × para limpiarlo.
2. **`Listar`** — **sólo en el módulo que tiene filtros rápidos** (hoy Contratos):
   `btn btn-secondary btn-sm` con `fa-list` + `fa-caret-down .menubar-caret`.
   Despliega los mismos atajos que las stat cards (`DESIGN.md` §12.1) y los
   aplica **por la misma función**, nunca con una copia del criterio.
3. **`Filtros`** — `btn btn-secondary btn-sm` con `fa-filter`. Abre el Modal de Filtros.
4. **`Refrescar`** — **sin texto**: `btn btn-secondary btn-sm btn-icon-only` con
   `fa-rotate`, `title` y `aria-label` "Refrescar listado". Misma variante que
   `Filtros` para que se lean como un par y no como una acción suelta.
5. A la derecha del todo, la acción primaria `+ Nuevo <entidad>` (que los
   módulos read-only omiten).

Lo dibuja entero el helper compartido `abmToolbar()`; el botón de refrescar lo
cablea `wireRefresh(idPrefix, route, state)` y el desplegable `Listar`
—que se pide con `listar: true`— lo cablea `wireListarMenu(idPrefix, itemsFn)`:
ningún módulo arma el suyo.

**`Listar` va entre el buscador y `Filtros`**, no después: es el atajo a lo que
en `Filtros` habría que armar campo por campo, y `Filtros` + `Refrescar` tienen
que quedar pegados para leerse como un par.

**Refrescar vuelve a pedirle todo al backend y re-renderiza el módulo completo**
—listado, catálogos y stat cards—, **conservando los filtros y la búsqueda
rápida vigentes**. Los dos detalles son la funcionalidad, no un extra:
refrescar sólo la tabla deja los KPIs contando lo viejo arriba de datos nuevos,
y perder los filtros convierte el refresh en un cambio de pantalla. Ver
`DESIGN.md` §9.

### Cómo busca el texto libre (método único, obligatorio, y EN SQL)

Todo campo de texto libre —el **buscador rápido** de la toolbar y el campo
`Buscar` del **Modal de Filtros**, que en casi todos los módulos son el mismo
`state.texto`— viaja al endpoint como **`?q=`** y lo resuelve
`busquedaWhere()` de [lib/busqueda.php](lib/busqueda.php) en el `WHERE` de la
consulta. **Ningún módulo filtra texto en el navegador y ninguno arma el suyo**,
igual que con `abmToolbar()`.

Del lado del front lo cablea el helper compartido `wireBuscadorSql()`:

```js
const recargar = wireBuscadorSql({
    quick, quickClr, tableWrap, state,
    pedir:    q => api('dominios?q=' + encodeURIComponent(q)).then(d => d.dominios),
    alLlegar: filas => { dominios = filas; applyAndRender(); },
});
btnFilt.addEventListener('click', () => openDominiosFiltersModal(state, recargar));
// El render ya pidió el listado SIN `q`: sólo se re-pide si venía con búsqueda.
if (state.texto) recargar(); else applyAndRender();
```

Reglas del cableado, las tres por un motivo:

- **`Aplicar` del modal de Filtros llama a `recargar`, no a `applyAndRender`.**
  Ese modal también edita `state.texto`, y el texto ya no recorta nada en el
  navegador: sin re-pedir, escribir ahí no haría nada.
- **Debounce de 300 ms y token de carrera.** Cada tecla puede costar una
  consulta; y aunque haya debounce, dos consultas pueden quedar en vuelo (red
  lenta, texto pegado) y sin el token la que llega última pinta la tabla aunque
  sea la respuesta vieja.
- **La `stats-bar` NO se repinta al buscar, y por eso el `render*()` pide SIEMPRE
  sin `q`.** Los KPIs son del universo y no de la consulta (`DESIGN.md` §9), y
  el `resumen` que devuelve un endpoint es del universo **sólo** si se lo pidió
  sin `q`. De ahí la última línea: el render ya trajo el universo, así que
  únicamente se vuelve a pedir cuando el estado venía con una búsqueda puesta
  (Refrescar conserva los filtros vigentes).

Lo que el método garantiza —y que un `.toLowerCase().includes(q)` no da—:

- **Los acentos no importan, en ninguna de las dos puntas.** `gonzalez`
  encuentra a `González` y `González` encuentra a `gonzalez`. La simetría es el
  punto: nadie sabe de memoria si el dato se cargó con tilde. El plegado se
  lleva también la virgulilla (`nino` trae `Niño`) y la diéresis.
- **Mayúsculas y minúsculas son indistintas.**
- **Se buscan pedazos de palabra, sueltos y en cualquier orden.** La consulta se
  parte en términos por espacios y cada término se busca como **subcadena**:
  `mari gon` encuentra a `María González` y también a `González, María`.
- **Los términos se cruzan con Y; cada término se busca en todos los campos con
  O.** Agregar una palabra tiene que **acotar** el resultado. Con la O al revés
  el segundo término lo agranda y el buscador empeora justo cuando más se lo
  necesita, que es cuando el primero trajo demasiado. Es la misma regla que el
  combo con buscador (`comboCoincide()`, `DESIGN.md` §34-bis).
- **La consulta vacía no filtra**: sin términos `busquedaWhere()` no devuelve
  ninguna condición, así que el endpoint **no** lleva el viejo `if ($q !== '')`.

Reglas al aplicarlo:

- **Los campos son los que anuncia el `placeholder`, ni más ni menos.** Buscar
  sobre una columna que la pantalla no muestra devuelve filas que el operador no
  puede explicar; no listarla en el `placeholder` esconde por qué apareció.
- **Van las columnas REALES, no los alias del `SELECT`.** `correo` y no `email`,
  `d.uuid` y no `uid`, `c.telefono` y no `numero`: MySQL evalúa el `WHERE` antes
  de la proyección, así que un alias ahí es `Unknown column`.
- **Lo que el front derivaba en PHP se reconstruye en SQL o se declara afuera.**
  El `numero` de Talonarios y de Comprobantes es un `str_pad` armado en PHP: se
  rehace con `CONCAT(LPAD(...))` y las **mismas** constantes de largo, para
  poder buscar lo que la pantalla muestra (`0003-00000123`) y no dos enteros
  sueltos. El `tipo_texto` de Contratos sale de `combos` y se resuelve con un
  `LEFT JOIN` por esa clave. El `tipo_completo` de Comprobantes es el único que
  quedó **fuera** de la búsqueda —pedía tres joins más por una columna que ya
  tiene su propio filtro—, y eso se dice acá porque es un cambio de
  comportamiento, no un detalle.
- **Ojo con la segunda consulta.** Comprobantes corre el mismo `WHERE` en la
  query del listado y en la de totales: si la búsqueda nombra un alias que sólo
  existe en una, la otra revienta. Se le agregaron los dos `LEFT JOIN` que le
  faltaban — son 1:1 por PK, así que no cambian el `COUNT` ni el `SUM`
  (verificado: 2.331 y 2.331 en las tres consultas probadas).
- **El plegado en JS sigue existiendo para el combo con buscador.**
  `normalizarBusqueda()` y `comboNormalizar()` tienen que plegar igual; el
  segundo existe aparte porque el combo resalta lo que coincidió y necesita que
  los índices apunten al mismo lugar en la original. Si cambia la regla, cambian
  las dos.

#### Cómo lo resuelve `lib/busqueda.php`

`busquedaWhere($consulta, $columnas)` vive en
[lib/busqueda.php](lib/busqueda.php) y lo carga `api/bootstrap.php` para todos
los endpoints. Devuelve `[$condiciones, $params]` para meter en el `WHERE`; con
la consulta vacía las dos vienen vacías y no se agrega nada. Lo usan los 21
listados más `Razón social` de Comprobantes, el Visor de sucesos y el
Programador de tareas.

```php
[$condiciones, $busq] = busquedaWhere($q, ['nombre', 'script', 'descripcion']);
$where  = array_merge($where, $condiciones);
$params = array_merge($params, $busq);
```

Arma `(col1 LIKE :q0_0 OR col2 LIKE :q0_1) AND (col1 LIKE :q1_0 OR …)` — la
misma regla Y/O del front. Tres cosas que no se deducen del código:

- **Los acentos y las mayúsculas los pliega la COLLATION, no la query.** Todas
  las columnas del esquema son `utf8mb4_..._ci`, y esas collations comparan
  `a` = `á`, `n` = `ñ` y `u` = `ü`. Verificado contra el motor: `SELECT "Niño"
  LIKE "%nino%"` da 1. **Una columna con collation acento-sensible
  (`..._as_cs`, `..._bin`) dejaría de cumplir la garantía**, y lo que hay que
  arreglar ahí es la collation, no agregarle un `REPLACE` a la query.
- **UN PLACEHOLDER POR TÉRMINO Y POR COLUMNA**, aunque el valor sea idéntico:
  con `ATTR_EMULATE_PREPARES => false` PDO mapea cada nombre a **una** posición,
  así que reusar `:q0` en las cuatro columnas corta con `SQLSTATE[HY093]
  Invalid parameter number`. Es el error que ya documentaban a mano los cinco
  endpoints del panel, y el helper lo resuelve de una vez.
- **Los comodines de `LIKE` se escapan** (`busquedaEscapar()`): quien busca
  `50%` quiere ese texto y no "cualquier cosa que empiece con 50" (medido:
  48 filas contra 0), y un `_` suelto matchea cualquier caracter.
- **`LIKE '%…%'` NO usa índice, y en las tablas grandes eso muerde.** Sin
  búsqueda, un `ORDER BY id DESC LIMIT n` se sirve de la PK y para en la fila n;
  con el `LIKE` recorre hacia atrás hasta juntar n coincidencias, y si el
  término no está en ninguna, recorre la tabla entera. Por eso **Señales y
  Registros acotan por `id > MAX(id) - VENTANA_BUSQUEDA` (200.000), y sólo
  cuando hay búsqueda**: medido en dev, `registros` (2.952.693 filas) pasó de
  2.567 ms a 132 ms y `senales` (863.350) de 811 ms a 138 ms. `notificaciones`
  (68.717 filas, 78 ms) y `adopciones` (225) no la llevan — agregársela sería
  recortarles el alcance a cambio de nada. **La contrapartida es real: en esos
  dos la búsqueda no ve más allá de la ventana**, que es el mismo trato que el
  panel ya hace en Actividad y con el mismo tamaño.

El archivo es **copia idéntica de [panel/lib/busqueda.php](../panel/lib/busqueda.php)**,
como `habilitado.php` y `permisos.php`: las apps no comparten docroot.

### Modal de Filtros

El formulario de búsqueda debe respetar este orden de campos:

1. **Primer campo: `Código`**
   - Tipo: numérico.
   - Etiqueta: `Código`.
   - Corresponde al ID de la entidad.

2. **Campos comunes del recurso**
   - Los filtros propios de la entidad, en el medio del formulario.

3. **Último campo: `Límite`**
   - Tipo: numérico con control up/down.
   - Valor por defecto: `100`.

4. **Ordenamiento (solo si corresponde al módulo)**
   - Dos selects:
     - Select con los campos por los que se puede ordenar.
     - Select `Dirección` con dos opciones: `Ascendente` / `Descendente`.
   - Si el módulo no requiere ordenamiento configurable, omitir este bloque.

**Cabecera del modal de Filtros:** la misma que la de Consultar y la de
Alta/Edición — título en primario (`.modal-header-primary`) y barra de acciones
(`.modal-menubar`) en lugar del footer, con `Cancelar` (`btn-ghost`) +
`Limpiar` + `Aplicar` (`btn-primary`), los tres directos. Lo dibuja el helper
compartido `openFiltersModal()`, así que sale igual en todos los módulos y no
hay una versión por listado.

## Modales

### Consultar
- Al abrir el modal de **Consultar**, se deben mostrar **todos los campos** del registro seleccionado (no solo los que aparecen en el listado).
- Los campos se muestran en modo lectura.
- Cada campo se renderiza en una **tarjeta individual** (`div`) con:
  - **Esquinas redondeadas**.
  - **Sin bordes** (`border: none`). Las tarjetas se diferencian del fondo del modal únicamente por el color de fondo, no por un borde.
  - **Color de fondo exactamente un 10% más oscuro** que el color de fondo del modal. Implementación recomendada en CSS: `background: color-mix(in srgb, var(--surface) 90%, #000);`. Este valor es **obligatorio** y no debe variarse por módulo.
  - Etiqueta del campo y valor dentro de la misma tarjeta.
- **Ancho de las tarjetas**:
  - Cuando el valor del campo puede mostrarse con **pocos caracteres** (códigos, números, fechas, estados, booleanos, etc.), la tarjeta ocupa el **50% del ancho** de la fila, permitiendo dos tarjetas por fila.
  - Cuando el valor requiere más espacio (descripciones largas, observaciones, direcciones completas, etc.), la tarjeta ocupa el **100% del ancho** de la fila.
- **Tarjeta en fila para las listas de "esto lo tiene / esto no"** (`view-card-full` + `view-card-row`, §25 de `DESIGN.md`): nombre a la izquierda —con su glosa debajo, en `muted`— y **píldora de estado a la derecha**, contra el borde de la tarjeta. Es para pestañas que listan permisos, paneles o cualquier catálogo de banderas del registro, donde la pregunta no es qué dice cada tarjeta sino **cuáles están prendidas**: con los badges alineados en la misma columna se contesta de un vistazo. **No es la forma por defecto** — un campo suelto va apilado. Los rótulos son siempre `Habilitado` / `Deshabilitado`, los mismos de la bandera `habilitado`.
- **La paridad de la grilla es parte del diseño, no un accidente.** `.view-grid` es flex y `.view-card-half` lleva `flex: 1 1 calc(50% - 6px)`: una tarjeta media que queda sola en su renglón **se estira al 100%** y se lee como un campo destacado a propósito, cuando en realidad es el sobrante de una cuenta impar. Con una cantidad impar de campos hay que marcar una tarjeta `view-card-full` en una **ranura impar**, para que los bloques de arriba y de abajo sigan cerrando de a dos. **Agregar o quitar un campo obliga a rehacer esa cuenta.** Ejemplo: Consultar dominio son 15 campos con `Identificador` full en la ranura 3 → un renglón ancho y siete parejos.
- **Los campos son los de la tabla, no los que el front tenga a mano.** Si una columna no existe en `db/schema.sql`, no se inventa una tarjeta para ella (Consultar dominio no muestra `Descripción` ni `Creado`: `dominios` no tiene esas columnas). Los códigos cortos del sistema histórico (`situacion`, `autoadministrado`) se muestran **traducidos**, con el texto que sale de `combos` en el backend — nunca el número pelado ni una tabla de textos hardcodeada en el front.

### Alta / Edición
- El modal de **crear un nuevo registro** y el de **editar** deben incluir **todos los campos** de la entidad.
- Ambos modales comparten la misma estructura de campos; la única diferencia es si vienen precargados con los datos del registro (edición) o vacíos (alta).
- **La excepción son las entidades con ciclo de vida, y hay que declararla.** Un comprobante no es una fila que se edita: es un documento que pasa por `Preparación → Pendiente → Cancelado`, con `Anulado` como salida. Un dispositivo tampoco: es un equipo que se **fabrica**, y recién después se adopta, se instala y empieza a reportar. Ahí el ABM **no** expone todos los campos:
  - **el alta pide lo mínimo para existir** (en Comprobantes, sólo el talonario) y deja el registro en su primer estado; el resto se completa desde la ficha, que se abre sola al crear;
  - **la edición se habilita sólo en el estado que la admite**, y sobre los campos que ese estado admite;
  - **lo derivado y lo asignado no se editan nunca**: los totales salen de los hijos, el número de la serie del talonario, el CAE de AFIP;
  - **todo eso sí se muestra**. La restricción es de escritura, no de lectura, y la ficha dice **qué no se puede editar y por qué** — un campo ausente sin explicación se lee como un olvido.
  - **Cuando el alta además crea filas en otra tabla, es un asistente y no un formulario.** Es el caso de Dispositivos (`DESIGN.md` §43): el alta pide modelo, producto y las tres credenciales, y de ahí salen el dispositivo **y un canal por cada canal que declara el modelo**. Tres pasos —qué equipo es, con qué credenciales nace, qué se va a escribir— y el último es la **previsualización que devuelve el backend**, con la tabla de canales real. Un alta que escribe en dos tablas no se confirma con un botón `Guardar` y una frase; vale la misma regla de `GET` previsualiza / `POST` ejecuta que las acciones de negocio, y por eso vive en `<entidad>_accion.php`. **El formulario de todos los campos no desaparece**: se queda con la edición y con el alta manual, que es lo que resuelve un dato roto o un equipo sin modelo en el catálogo.
- **El estado se resuelve en el backend y viaja como booleanos.** `comprobanteSalida()` sirve `puede_editar`, `puede_autorizar`, `puede_anular`: la UI no dibuja lo que no corresponde y **cada endpoint vuelve a chequear la precondición contra la base**. Es el mismo criterio de los tres permisos del perfil en `CLAUDE.md` — esconder el botón no es el control de acceso. Duplicar la condición en el front (`estado === '1'` repetido en seis lugares) es cómo se desincronizan la pantalla y el endpoint.
- **Las transiciones no son un `PUT`.** Autorizar, anular y duplicar viven en su propio endpoint (`comprobantes_accion.php?accion=…`) porque ninguna es "guardá los campos que te mando": cada una tiene su precondición y varias tocan más de una tabla. Mezclarlas con la edición haría que un payload con `estado` adentro se saltee la precondición.
- **Un contador compartido se toma con `SELECT … FOR UPDATE`.** Autorizar lee `talonarios.serie`, le suma uno y guarda; sin el lock, dos autorizaciones simultáneas leen el mismo valor y emiten **dos comprobantes con el mismo número fiscal**. El sistema histórico no lo hace y por eso acá sí.
- **Un campo derivado se muestra de sólo lectura y con vista previa en vivo, y lo calcula el backend.** Es el caso de `talonarios`.`nombre`, que sale de `empresa - tipo - subtipo - punto`: el input va `readonly`, se recompone al cambiar cualquiera de sus partes y **no viaja en el payload** — si lo calculara el front habría dos fórmulas que se pueden desincronizar. Debajo va una `.form-nota` que explica de qué se arma, porque un campo que no se puede editar y no dice por qué se lee como un error.
  - **Y no se regenera a ciegas sobre un valor escrito a mano.** El backend compara lo guardado contra la derivación de los valores que la fila *tenía*: si coincidían, regenera; si no, conserva. Sin eso, abrir y guardar el talonario 35 (`Interno - Wescom - Abono - X - 001`) lo renombraría a `Wescom - A - X - 001`. Mismo criterio con el que las invitaciones no pisan `usuarios`.`registrante` de una cuenta que ya existía.
  - **Pero un campo derivado que además es una credencial se edita, y el espejo vive en el front.** Es el caso de `usuarios`.`usuario` en el modal de Usuarios: sale del correo por convención del alta (2065 de 2083 filas lo tienen idéntico) pero 17 filas tienen credencial propia, y `api/login.php` entra por ahí. Hasta el 15/09/2026 el campo no se mostraba y la API arrastraba el correo nuevo cuando la credencial vieja coincidía con el correo viejo — la regla de la viñeta anterior, aplicada a un dato con el que la gente entra al sistema. **Resolvía el caso correcto pero a ciegas**: quien cambiaba el email no tenía cómo saber que le estaba cambiando también la credencial, ni cómo *no* cambiársela. Ahora el campo va a la vista, a mitad de ancho y **al lado de la contraseña** —las dos mitades de la credencial juntas—, sigue al email mientras nadie lo edite y deja de seguirlo en cuanto alguien escribe en él; el backend escribe lo que le mandan. La diferencia con `talonarios`.`nombre` es que ése es un rótulo que se puede recomponer si sale mal, y éste es la puerta de entrada: el operador tiene que **ver** lo que está por cambiar antes de guardar.
- **Un `<select>` tiene que incluir el valor guardado aunque no esté en el catálogo.** Si no lo tiene, el navegador cae en la primera opción y el guardado **borra el dato en silencio**. Pasa de verdad: `talonarios`.`tipo` del 35 es `A` y ese código no está en `combos`. La opción huérfana se agrega marcada (`A (fuera de catálogo)`) y el endpoint hace la contraparte — acepta el código **heredado** por esa fila y exige el catálogo sólo para valores nuevos, así la validación no deja inservible una fila vieja que ya está emitiendo comprobantes. Mismo criterio para los planes deshabilitados de Contratos.
- **Cabecera igual a la del modal de Consultar del mismo módulo**: título en primario (`.modal-header-primary`, §14 de `DESIGN.md`) y la barra de acciones debajo (`.modal-menubar`, §21-bis) en lugar del footer — `Cancelar` (`btn-ghost`) + `Guardar` (`btn-primary`) — **el mismo rótulo en alta y en edición**, porque el título del modal ya dice cuál de las dos es. Consultar y editar son la misma pantalla en dos modos: si una lleva la cabecera y la otra un footer neutro, se leen como dos sistemas distintos. Módulos ya migrados: Dominios, Usuarios, Perfiles, Controladores, Roles y Permisos.

### Eliminar
- La baja de un registro **sin dependencias** usa el `confirmDialog` estándar (§15 de `DESIGN.md`): título, una frase y los botones `Cancelar` / `Eliminar`.
- Cuando la baja **arrastra filas de otras tablas**, el `confirmDialog` no alcanza: hay que mostrar un **modal de confirmación con el desglose del impacto** (§15.1 de `DESIGN.md`). Es obligatorio cuando la entidad es el lado padre de FKs con `ON DELETE CASCADE`, `ON DELETE SET NULL` o `ON DELETE RESTRICT`.
  - Las cantidades **se piden al backend** antes de abrir el modal (endpoint `?impacto=1&id=N` del propio recurso), nunca se estiman en el front ni se hardcodean.
  - El desglose separa **lo que se elimina** de **lo que se conserva sin la referencia**. Son consecuencias distintas y el operador tiene que poder distinguirlas.
  - Si existe un **bloqueo** (una FK `RESTRICT` que el backend decide no resolver por su cuenta), el modal lo explica, dice qué hay que hacer para destrabarlo y **no ofrece el botón de confirmar**.
  - El endpoint `DELETE` **repite todas las validaciones** del modal: el desglose es informativo, no un permiso. Y hace la limpieza en **una transacción**, respetando el orden que imponen las FKs.
- Referencia: Usuarios (`openUserDeleteModal` en `app.js`, `handleImpacto()` / `handleDelete()` en `api/users.php`).