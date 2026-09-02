# Font Awesome 6.5.1 Pro (autohospedado)

Copia local del paquete **Font Awesome Pro 6.5.1 — Web**. El panel ya no
carga FontAwesome desde `cdnjs.cloudflare.com`: `panel/index.php` y
`panel/login.php` enlazan directo estas hojas.

Portado desde `databox/cloud/assets/fontawesome/`, que es donde vive el
paquete original recortado. Los dos proyectos comparten el mismo recorte.

## Contenido

```
css/all.min.css            Classic (solid/regular/light/thin) + Duotone + Brands + shims v4/v5
css/sharp-solid.min.css    @font-face de la familia Sharp — peso 900
css/sharp-regular.min.css  @font-face de la familia Sharp — peso 400
css/sharp-light.min.css    @font-face de la familia Sharp — peso 300
css/sharp-thin.min.css     @font-face de la familia Sharp — peso 100
webfonts/*.woff2           Las 11 fuentes
icons.json                 Catálogo de íconos (ver abajo)
LICENSE.txt                Licencia comercial de Fonticons, Inc.
```

Las cuatro hojas `sharp-*` son necesarias porque `all.min.css` **mapea** las
clases `.fa-sharp` / `.fasl` / `.fasr` / `.fass` / `.fast` a la familia
`"Font Awesome 6 Sharp"` pero **no declara sus `@font-face`**. Sin ellas,
`fa-sharp fa-solid fa-house` renderiza un cuadrado vacío.

Los `.woff2` no se descargan por el solo hecho de enlazar el CSS: el browser
pide cada fuente recién cuando la página renderiza un glifo de esa familia.
Hoy el panel usa sólo `fa-solid`, así que en la práctica baja
`fa-solid-900.woff2` y nada más.

## Diferencias contra el paquete original

- Sólo se copiaron los formatos **`.woff2`** (no los `.ttf`). Las referencias
  a `.ttf` se quitaron de los `@font-face` para no dejar URLs colgadas: todo
  navegador con soporte de `@font-face` variable soporta woff2 y el fallback
  truetype nunca se pedía.
- No se copiaron `less/`, `scss/`, `sprites/`, `svgs/`, `js/` ni el resto de
  los `.css` sueltos (`duotone.css`, `v4-shims.css`, …): el panel usa
  únicamente el modo webfont+CSS.

## Cache-busting

`index.php` / `login.php` calculan `$faVer` con el `filemtime` de
`css/all.min.css`, independiente de `panel/version.txt`. Al reemplazar el
paquete el navegador toma la versión nueva sin tocar nada más — y al revés,
un bump de `version.txt` no fuerza a rebajar los ~350 KB de la fuente.

## `icons.json`

Catálogo compacto (~630 KB) con los metadatos de todos los íconos. **Ningún
código del panel lo consume todavía** — viene del port y queda disponible por
si se implementa el "Explorador FA6" como herramienta del módulo
Herramientas. Si no se va a usar, se puede borrar sin romper nada.

Se genera a partir de los metadatos del paquete oficial
(`metadata/icons.json`, `metadata/icon-families.json`, `metadata/categories.yml`)
con el script que vive en el repo `databox`:

```bash
python scripts/generar_fa6_icons.py "/ruta/al/fontawesome-6.5.1-web-pro"
```

Esquema de cada entrada (claves cortas para achicar el archivo):

| Clave | Significado                                                      |
|-------|------------------------------------------------------------------|
| `n`   | nombre del ícono (`house`) — la clase es `fa-<n>`                 |
| `l`   | etiqueta legible (`House`); ausente si es igual al nombre         |
| `u`   | codepoint (`f015`)                                               |
| `c`   | estilos Classic disponibles: `s`olid `r`egular `l`ight `t`hin `b`rands |
| `p`   | estilos Sharp disponibles: `s` `r` `l` `t`                        |
| `d`   | `1` si existe en Duotone                                         |
| `f`   | `1` si el ícono es Free (sin la marca, es sólo Pro)              |
| `t`   | sinónimos de búsqueda                                            |
| `a`   | alias (nombres viejos que siguen funcionando)                    |

## Al actualizar de versión

1. Reemplazar `css/` y `webfonts/` con los del paquete nuevo (mismo recorte:
   `all.min.css` + las cuatro `sharp-*`, sólo `.woff2`, sin refs a `.ttf`).
2. Regenerar `icons.json` con el script (si se lo mantiene).
3. Actualizar el número de versión en este README.

Lo más simple es actualizar primero `databox/cloud/assets/fontawesome/` y
copiar la carpeta entera acá.
