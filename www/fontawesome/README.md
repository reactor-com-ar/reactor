# Font Awesome 6.5.1 Pro (autohospedado)

Copia local del paquete **Font Awesome Pro 6.5.1 — Web**, el mismo recorte que
ya usan [panel/assets/fontawesome/](../../panel/assets/fontawesome/) y
[cloud/assets/fontawesome/](../../cloud/assets/fontawesome/). De ahí se copió
esta carpeta.

**Reemplaza a Font Awesome Free 6.0.0**, que el sitio cargaba por dos caminos a
la vez y los dos se eliminaron el 28/09/2026:

- `css/plugins/fontawesome-all.min.css` (Free 6.0.0), importada desde
  `css/plugins.css`, con sus cuatro fuentes sueltas en `fonts/fa-*`.
- Un `<link>` a `cdn.jsdelivr.net/.../fontawesome-free@6.0.0-beta2` en
  `sistema/cabeza.php` y en `lib/mensaje.php` — una **beta** de otra versión,
  servida por un tercero, que además pisaba a la anterior.

## Contenido

```
css/all.min.css            Classic (solid/regular/light/thin) + Duotone + Brands + shims v4/v5
css/sharp-solid.min.css    @font-face de la familia Sharp — peso 900
css/sharp-regular.min.css  @font-face de la familia Sharp — peso 400
css/sharp-light.min.css    @font-face de la familia Sharp — peso 300
css/sharp-thin.min.css     @font-face de la familia Sharp — peso 100
webfonts/*.woff2           Las 11 fuentes
LICENSE.txt                Licencia comercial de Fonticons, Inc.
```

Las cuatro hojas `sharp-*` son necesarias porque `all.min.css` **mapea** las
clases `.fa-sharp` / `.fasl` / `.fasr` / `.fass` / `.fast` a la familia
`"Font Awesome 6 Sharp"` pero **no declara sus `@font-face`**. Sin ellas,
`fa-sharp fa-solid fa-house` renderiza un cuadrado vacío.

**Los `.woff2` no se descargan por el solo hecho de enlazar el CSS**: el browser
pide cada fuente recién cuando la página renderiza un glifo de esa familia. Hoy
el sitio usa `fa-solid`, `far` y `fab`, así que en la práctica bajan tres
archivos y los otros ocho no se piden nunca. Por eso enlazar las cinco hojas no
cuesta 4 MB: cuesta los ~10 KB que pesan las hojas `sharp-*` más el CSS.

## Diferencias contra el paquete original

- Sólo se copiaron los formatos **`.woff2`** (no los `.ttf`). Las referencias a
  `.ttf` se quitaron de los `@font-face` para no dejar URLs colgadas.
- **No se copió `icons.json`** (~630 KB), que sí está en `panel/` y `cloud/`:
  es el catálogo de metadatos para el "Explorador FA6" del módulo Herramientas
  y acá no hay módulo que lo consuma. Este docroot además **no tiene login**, así
  que todo lo que se deje adentro queda publicado. Si alguna vez hace falta, se
  copia de `cloud/assets/fontawesome/icons.json`.
- No se copiaron `less/`, `scss/`, `sprites/`, `svgs/`, `js/` ni los `.css`
  sueltos (`duotone.css`, `v4-shims.css`, …): el sitio usa únicamente el modo
  webfont+CSS.

## Cómo lo enlaza el sitio

`sistema/cabeza.php` (todas las páginas) y `lib/mensaje.php` (la pantalla de
aviso, que no pasa por la cabecera) enlazan las cinco hojas desde acá. **No se
importa desde `css/plugins.css`**, a diferencia de los otros plugins del theme,
por el cache-busting: los `@import` de ese archivo viajan sin `?v=`.

El cache-bust es el **`filemtime` de `css/all.min.css`**, independiente de
`version.txt` — igual que en `panel/`. Al reemplazar el paquete el navegador toma
la versión nueva sin tocar nada más, y al revés, un bump de `version.txt` no
fuerza a rebajar los ~350 KB de la fuente.

## Las clases que usa el sitio

El theme mezcla las tres notaciones y **las tres siguen funcionando** con este
paquete:

| notación | ejemplo en el sitio | familia que resuelve |
|---|---|---|
| v6 larga | `fa-solid fa-comment-dots` | Font Awesome 6 Pro, peso 900 |
| v5 corta | `fas fa-search`, `far fa-file-pdf`, `fab fa-whatsapp` | ídem / peso 400 / Brands |
| v4 | `fa fa-home`, `fa fa-envelope` | `.fa` cae a Pro peso 900 |

**Lo que cambia respecto de Free es qué íconos existen**, no cómo se escriben:
en Free el estilo `regular` trae ~160 íconos y el resto cae en cuadrado vacío.
Por eso `far fa-paper-plane` y `far fa-envelope` del formulario de contacto
renderizan de verdad recién ahora.

Los pseudo-elementos del theme (`css/styles-red.css`: las flechas del
breadcrumb, las comillas de los testimonios, los chevrones del footer) piden la
familia **por nombre**, no por clase, y decían `Font Awesome\ 5 Free` — una
familia que **nunca estuvo declarada en este sitio**, ni con Free 6 ni con la
beta del CDN, así que esos siete adornos venían saliendo en blanco. Apuntan a
`"Font Awesome 6 Pro"` desde el 28/09/2026.

## Al actualizar de versión

1. Reemplazar `css/` y `webfonts/` con los del paquete nuevo (mismo recorte:
   `all.min.css` + las cuatro `sharp-*`, sólo `.woff2`, sin refs a `.ttf`).
2. Actualizar el número de versión en este README.
3. **No hace falta tocar `version.txt`**: el cache-bust de FA es su propio
   `filemtime`.

Lo más simple es actualizar primero `cloud/assets/fontawesome/` y copiar la
carpeta entera acá, salteando `icons.json`.
