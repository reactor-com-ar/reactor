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
   - **Click izquierdo sobre la fila = acción por defecto.** Un módulo puede habilitar el atajo agregando `class="row-clickable"` al `<tr>` (cursor pointer, §10 de `DESIGN.md`) y un listener de `click` en la fila. La acción por defecto es **Consultar** (en módulos sin modal de consulta, como el Editor de parámetros, es Editar). El botón hamburguesa frena la propagación para no disparar el atajo. El atajo es el comportamiento estándar de todo listado ABM: está activo en **Dominios, Dispositivos, Chips, Transceptores, Señales, Registros, Adopciones, Usuarios, Perfiles, Controladores, Roles y Permisos** (más la solapa Perfiles del modal de Consultar de Usuarios).
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
   - **Acciones de navegación cruzada** (listar los registros relacionados en su propio módulo): dejan el id pedido en el scope de la app y navegan con `window.location.hash = '#/<ruta>'`. El módulo destino lo consume en su `render*()`, lo vuelca al `state` del filtro correspondiente y lo limpia. El filtro usado tiene que existir además como campo del **Modal de Filtros** del módulo destino, para que el usuario vea por qué la lista viene acotada y pueda limpiarlo. Ejemplos: Dominios → "Ver dispositivos asociados" (`#/dispositivos`, filtro `Dominio`), Usuarios → "Listar perfiles" (`#/profiles`, filtro `Usuario`), Dispositivos → "Listar señales" (`#/signals`, filtro `Dispositivo`).
     - Hay un par de helpers por entidad: `pedirFiltroDominio(route, id)` / `tomarFiltroDominio(route)` y `pedirFiltroUsuario(route, campo, id)` / `tomarFiltroUsuario(route)`. Guardan `{ route, … }` y **sólo aplican si la ruta coincide** — si el usuario se desvía a otra pantalla el pedido se descarta en vez de filtrar un listado equivocado más tarde (ver `DESIGN.md` §21-bis.1). El de usuario lleva además el **campo** destino (`usuario` en Perfiles, `adoptador` / `liberador` en Adopciones): la misma persona entra por columnas distintas según el módulo. Las variables sueltas `pendingXxxFilter` son el patrón viejo y queda sólo la de dispositivo.
     - Cuando el endpoint destino **sabe filtrar por esa entidad, el filtro va en el fetch inicial** además del filtrado client-side: en tablas grandes (`registros`, `senales`, `adopciones`) recortar recién después de traer la ventana muestra "las N últimas de todos" filtradas, que para un registro chico puede dar vacío.

### Límite de resultados
- Por defecto: **100**.
- Modificable por el usuario desde el campo `Límite` del buscador.

## Buscador

### Toolbar

Arriba de la tabla, y en este orden de izquierda a derecha:

1. **Buscador rápido** (`.search-wrap > .search-input`), con su × para limpiarlo.
2. **`Filtros`** — `btn btn-secondary btn-sm` con `fa-filter`. Abre el Modal de Filtros.
3. **`Refrescar`** — **sin texto**: `btn btn-secondary btn-sm btn-icon-only` con
   `fa-rotate`, `title` y `aria-label` "Refrescar listado". Misma variante que
   `Filtros` para que se lean como un par y no como una acción suelta.
4. A la derecha del todo, la acción primaria `+ Nuevo <entidad>` (que los
   módulos read-only omiten).

Lo dibuja entero el helper compartido `abmToolbar()` y el botón de refrescar lo
cablea `wireRefresh(idPrefix, route, state)`: ningún módulo arma el suyo.

**Refrescar vuelve a pedirle todo al backend y re-renderiza el módulo completo**
—listado, catálogos y stat cards—, **conservando los filtros y la búsqueda
rápida vigentes**. Los dos detalles son la funcionalidad, no un extra:
refrescar sólo la tabla deja los KPIs contando lo viejo arriba de datos nuevos,
y perder los filtros convierte el refresh en un cambio de pantalla. Ver
`DESIGN.md` §9.

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
- **La paridad de la grilla es parte del diseño, no un accidente.** `.view-grid` es flex y `.view-card-half` lleva `flex: 1 1 calc(50% - 6px)`: una tarjeta media que queda sola en su renglón **se estira al 100%** y se lee como un campo destacado a propósito, cuando en realidad es el sobrante de una cuenta impar. Con una cantidad impar de campos hay que marcar una tarjeta `view-card-full` en una **ranura impar**, para que los bloques de arriba y de abajo sigan cerrando de a dos. **Agregar o quitar un campo obliga a rehacer esa cuenta.** Ejemplo: Consultar dominio son 15 campos con `Identificador` full en la ranura 3 → un renglón ancho y siete parejos.
- **Los campos son los de la tabla, no los que el front tenga a mano.** Si una columna no existe en `db/schema.sql`, no se inventa una tarjeta para ella (Consultar dominio no muestra `Descripción` ni `Creado`: `dominios` no tiene esas columnas). Los códigos cortos del sistema histórico (`situacion`, `autoadministrado`) se muestran **traducidos**, con el texto que sale de `combos` en el backend — nunca el número pelado ni una tabla de textos hardcodeada en el front.

### Alta / Edición
- El modal de **crear un nuevo registro** y el de **editar** deben incluir **todos los campos** de la entidad.
- Ambos modales comparten la misma estructura de campos; la única diferencia es si vienen precargados con los datos del registro (edición) o vacíos (alta).
- **Cabecera igual a la del modal de Consultar del mismo módulo**: título en primario (`.modal-header-primary`, §14 de `DESIGN.md`) y la barra de acciones debajo (`.modal-menubar`, §21-bis) en lugar del footer — `Cancelar` (`btn-ghost`) + `Guardar` (`btn-primary`) — **el mismo rótulo en alta y en edición**, porque el título del modal ya dice cuál de las dos es. Consultar y editar son la misma pantalla en dos modos: si una lleva la cabecera y la otra un footer neutro, se leen como dos sistemas distintos. Módulos ya migrados: Dominios, Usuarios, Perfiles, Controladores, Roles y Permisos.

### Eliminar
- La baja de un registro **sin dependencias** usa el `confirmDialog` estándar (§15 de `DESIGN.md`): título, una frase y los botones `Cancelar` / `Eliminar`.
- Cuando la baja **arrastra filas de otras tablas**, el `confirmDialog` no alcanza: hay que mostrar un **modal de confirmación con el desglose del impacto** (§15.1 de `DESIGN.md`). Es obligatorio cuando la entidad es el lado padre de FKs con `ON DELETE CASCADE`, `ON DELETE SET NULL` o `ON DELETE RESTRICT`.
  - Las cantidades **se piden al backend** antes de abrir el modal (endpoint `?impacto=1&id=N` del propio recurso), nunca se estiman en el front ni se hardcodean.
  - El desglose separa **lo que se elimina** de **lo que se conserva sin la referencia**. Son consecuencias distintas y el operador tiene que poder distinguirlas.
  - Si existe un **bloqueo** (una FK `RESTRICT` que el backend decide no resolver por su cuenta), el modal lo explica, dice qué hay que hacer para destrabarlo y **no ofrece el botón de confirmar**.
  - El endpoint `DELETE` **repite todas las validaciones** del modal: el desglose es informativo, no un permiso. Y hace la limpieza en **una transacción**, respetando el orden que imponen las FKs.
- Referencia: Usuarios (`openUserDeleteModal` en `app.js`, `handleImpacto()` / `handleDelete()` en `api/users.php`).