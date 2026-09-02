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
   - **Click izquierdo sobre la fila = acción por defecto.** Un módulo puede habilitar el atajo agregando `class="row-clickable"` al `<tr>` (cursor pointer, §10 de `DESIGN.md`) y un listener de `click` en la fila. La acción por defecto es **Consultar** (en módulos sin modal de consulta, como el Editor de parámetros, es Editar). El botón hamburguesa frena la propagación para no disparar el atajo. El atajo es el comportamiento estándar de todo listado ABM: está activo en **Dominios, Dispositivos, Chips, Transceptores, Señales, Registros, Adopciones, Usuarios y Perfiles** (más la solapa Perfiles del modal de Consultar de Usuarios).
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
   - **Acciones de navegación cruzada** (listar los registros relacionados en su propio módulo): dejan el id en una variable `pendingXxxFilter` del scope de la app y navegan con `window.location.hash = '#/<ruta>'`. El módulo destino consume ese pending en su `render*()`, lo vuelca al `state` del filtro correspondiente y lo limpia. El filtro usado tiene que existir además como campo del **Modal de Filtros** del módulo destino, para que el usuario vea por qué la lista viene acotada y pueda limpiarlo. Ejemplos: Dominios → "Ver dispositivos asociados" (`#/dispositivos`, filtro `Dominio`), Usuarios → "Listar perfiles" (`#/profiles`, filtro `Usuario`).

### Límite de resultados
- Por defecto: **100**.
- Modificable por el usuario desde el campo `Límite` del buscador.

## Buscador

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

### Alta / Edición
- El modal de **crear un nuevo registro** y el de **editar** deben incluir **todos los campos** de la entidad.
- Ambos modales comparten la misma estructura de campos; la única diferencia es si vienen precargados con los datos del registro (edición) o vacíos (alta).