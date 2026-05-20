# Convenciones de módulos ABM

Reglas para generar módulos ABM (Alta, Baja, Modificación). Todo módulo nuevo debe respetarlas salvo indicación contraria.

Estas convenciones son **genéricas**: aplican a cualquier proyecto. No se asume ningún color primario, ni nombres concretos de campos o entidades. Cuando este documento menciona el "color primario", se refiere al color principal del proyecto donde se aplique (definido en su sistema de diseño, por ejemplo vía variable CSS).

---

## 1. Estructura general de la vista de listado

Toda vista de listado de un módulo ABM se compone, de arriba hacia abajo, por las siguientes secciones:

1. **Encabezado del módulo**
   - **Título** del módulo (nombre de la entidad en plural).
   - **Subtítulo** breve descriptivo, en un tono más tenue que el título, que explica de qué se trata el módulo en una sola frase.

2. **Tarjetas de indicadores (KPIs)** *(opcionales)*
   - Fila horizontal de tarjetas con métricas resumen del recurso (por ejemplo: total de registros, activos, inactivos, etc.).
   - Cada tarjeta contiene:
     - Una **etiqueta corta en mayúsculas** y color tenue (ej.: `TOTAL`, `ACTIVOS`, `INACTIVOS`).
     - Un **valor numérico grande**, destacado en color (el color puede variar por tarjeta según la semántica del indicador: neutro/primario para totales, verde para positivos, rojo para negativos, etc.).
     - Una **leyenda secundaria** opcional debajo, que explica brevemente la métrica.
   - Si el módulo no tiene métricas relevantes, esta sección se omite.

3. **Toolbar (barra de acciones del listado)**
   - Se ubica entre los KPIs (o el encabezado) y la tabla.
   - Tiene dos zonas:
     - **Zona izquierda**: búsqueda rápida y filtros avanzados.
     - **Zona derecha**: botón de alta de un nuevo registro.

4. **Tabla de resultados** (ver sección 4).

---

## 2. Toolbar del listado

### 2.1 Zona izquierda

1. **Input de búsqueda rápida**
   - Campo de texto único.
   - `placeholder` que indica los campos sobre los que opera la búsqueda (ej.: `Buscar nombre, correo, DNI…`).
   - Filtra el listado en vivo (o al presionar Enter) por los campos más representativos de la entidad.
   - **No reemplaza al modal de filtros**; es solo un atajo para la búsqueda más frecuente.

2. **Botón `Filtros`**
   - Botón secundario, junto al input de búsqueda rápida.
   - Lleva un ícono de embudo (filtro) a la izquierda del texto.
   - Al hacer clic abre el **Modal de Filtros** (ver sección 3).

### 2.2 Zona derecha

3. **Botón de alta** *(obligatorio en todo listado ABM)*
   - **Ubicación: siempre arriba a la derecha del listado**, formando parte del toolbar superior. Es el primer elemento visible en la esquina superior derecha de la vista, por encima de la tabla.
   - Botón **primario** (usa el color primario del proyecto), para que destaque visualmente frente al resto de controles del toolbar.
   - Texto con el formato `+ Nuevo <entidad>` (ej.: `+ Nuevo usuario`, `+ Nuevo dispositivo`). El símbolo `+` precede al texto.
   - Al hacer clic abre el modal de **Alta** (ver sección 5) para crear un nuevo registro del recurso del listado.
   - **No se omite** salvo que el módulo sea explícitamente de solo lectura. Si el usuario actual no tiene permisos para dar de alta, el botón se muestra **deshabilitado**, pero no se quita, para mantener una ubicación consistente entre módulos.

---

## 3. Modal de Filtros

El modal de filtros centraliza **todos** los filtros y opciones de listado del módulo. La búsqueda rápida del toolbar es un atajo, pero el modal es la fuente completa.

### 3.1 Estructura general

- Modal centrado, con título `Filtros` y botón de cierre (`×`) en la esquina superior derecha.
- Cuerpo organizado en una **grilla de dos columnas** de campos (en pantallas chicas, colapsa a una columna).
- Pie del modal con botones de acción alineados a la derecha (ver 3.4).

### 3.2 Campos obligatorios comunes a todos los ABM

Todo modal de filtros, independientemente del módulo, debe incluir estos campos comunes:

1. **`Código`**
   - **Primer campo** del formulario.
   - Tipo: numérico / texto.
   - Permite buscar un registro por su ID exacto.
   - Etiqueta: `Código` (no `ID`).

2. **`Límite`**
   - Cantidad máxima de resultados a mostrar.
   - Tipo: numérico con control up/down.
   - **Valor por defecto: `100`**.

3. **`Ordenar por`**
   - Select con la lista de campos por los que se puede ordenar el listado.
   - Debe incluir, como mínimo, la opción `Código`.

4. **`Dirección`**
   - Select con dos opciones: `Ascendente` / `Descendente`.
   - Valor por defecto: `Descendente`.
   - Se muestra **siempre junto a `Ordenar por`** (misma fila de la grilla).

### 3.3 Campos propios del módulo

Entre `Código` (primero) y el bloque `Límite` / `Ordenar por` / `Dirección` (al final) se ubican los **filtros propios del recurso**: nombre, correo, estado, categoría, fechas, relaciones con otras entidades, etc. Su cantidad y tipo dependen de cada módulo.

### 3.4 Botones del pie del modal

Alineados a la derecha del pie, en este orden de izquierda a derecha:

1. **`Limpiar`** — botón terciario / link. Resetea todos los campos a sus valores por defecto.
2. **`Cancelar`** — botón secundario. Cierra el modal **sin aplicar** los cambios.
3. **`Aplicar`** — botón **primario** (color primario del proyecto). Aplica los filtros y cierra el modal, refrescando la tabla.

---

## 4. Tabla de resultados

### 4.1 Orden de columnas

1. **Primera columna: `Código`**
   - Corresponde al ID de la tabla.
   - El título de la columna es `Código` (no `ID`).
   - Se suele mostrar con prefijo `#` (ej.: `#22116`).

2. **Columnas importantes de la entidad**
   - Los campos relevantes para identificar y operar sobre cada registro (nombre, contacto, estado, etc.).
   - Pueden combinar dos datos en una misma celda (ej.: nombre arriba, DNI abajo en tono tenue) cuando aporta a la legibilidad.

3. **Última columna: `Acciones`** *(obligatoria — ver 4.2)*.

### 4.2 Columna `Acciones` (obligatoria)

**Toda tabla de listado de un ABM debe incluir, en el costado derecho, una columna llamada `Acciones`**. Esta columna es de cumplimiento obligatorio y contiene exactamente **tres iconos**, siempre en este orden de izquierda a derecha:

| Orden | Ícono            | Acción      | Comportamiento                                                                 |
|-------|------------------|-------------|---------------------------------------------------------------------------------|
| 1     | 👁️ Ojo           | **Consultar** | Abre el modal de **Consultar** (solo lectura) con todos los campos del registro. |
| 2     | ✏️ Lápiz         | **Editar**    | Abre el modal de **Edición** con los datos del registro precargados.             |
| 3     | 🗑️ Tacho de basura | **Eliminar**  | Solicita confirmación y elimina el registro.                                     |

Reglas de la columna:

- Título de la columna: `Acciones`, en mayúsculas (siguiendo el estilo del resto del header).
- Los tres iconos se muestran en **línea**, separados con un espacio cómodo para clickear.
- Los iconos son **siempre los tres**, en **ese orden**, en **todos** los módulos ABM. No se reemplazan por menús desplegables ni por botones con texto.
- Si una acción no está disponible para un registro puntual (por permisos, estado, etc.), el ícono se muestra **deshabilitado** (tono atenuado, sin cursor pointer), pero **no se omite**, para mantener la alineación visual entre filas.

### 4.3 Límite de resultados

- Por defecto se muestran **100** registros.
- El usuario puede modificarlo desde el campo `Límite` del modal de filtros.

---

## 5. Modales de Consultar / Alta / Edición

### 5.1 Consultar

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

### 5.2 Alta / Edición

- El modal de **crear un nuevo registro** y el de **editar** deben incluir **todos los campos** de la entidad.
- Ambos modales comparten la misma estructura de campos; la única diferencia es si vienen precargados con los datos del registro (edición) o vacíos (alta).
- El botón de confirmación principal (`Guardar`, `Crear`, etc.) usa el **color primario** del proyecto y se alinea a la derecha del pie del modal, junto a un botón `Cancelar` secundario.
