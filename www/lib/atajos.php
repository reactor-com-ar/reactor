<?php

declare(strict_types=1);

/**
 * Los atajos de marca: `/whatsapp`, `/facebook`, `/correo` y compañía.
 *
 * Son las URLs cortas que se imprimen en folletos, se pegan en las redes y se
 * dictan por teléfono. Cada una es un archivo de una línea en la raíz —para que
 * `/whatsapp` siga siendo `/whatsapp`— y el destino de todas está acá, en un
 * solo lugar: en el legacy cada uno repetía el `require_once` del framework y
 * su propio `$oUrl->ir(...)`, así que para saber a dónde iba `/tiktok` había que
 * abrir `tiktok.php`.
 *
 * Todos redirigen con 302 y no con 301 a propósito: son atajos de marca cuyo
 * destino cambia —una cuenta nueva de Instagram, otro número de WhatsApp— y un
 * 301 se lo queda el navegador para siempre, así que quien pasó una vez
 * seguiría yendo al destino viejo aunque se cambie acá.
 */

require_once __DIR__ . '/inicio.php';

/** Atajo -> destino. La clave es el nombre del archivo, sin `.php`. */
const ATAJOS = [
    'facebook'     => 'https://www.facebook.com/reactor.oficial',
    'instagram'    => 'https://www.instagram.com/reactor.oficial',
    'tiktok'       => 'https://www.tiktok.com/@reactor.oficial',
    'youtube'      => 'https://www.youtube.com/@reactor-oficial',
    'whatsapp'     => 'https://api.whatsapp.com/send?phone=5491163099315&text=Hola%20Reactor',
    'mercadolibre' => 'https://articulo.mercadolibre.com.ar/MLA-1787927120-alarma-comunitaria-Reactor-vc-g5-4glte-con-tu-logo-_JM',

    // `github` apunta al portal de desarrolladores y no a GitHub: en el legacy
    // la URL de GitHub estaba comentada porque el repositorio no es publico. El
    // icono del pie sigue siendo el de GitHub, que es lo que la gente reconoce.
    'github'       => 'https://dev.reactor.com.ar',

    'app'          => 'https://app.reactor.com.ar',
    'control'      => 'https://control.reactor.com.ar',

    // Los tres que caen dentro del propio sitio.
    'correo'       => '/nosotros/contacto',
    'contacto'     => '/nosotros/contacto',
    'aplicacion'   => '/productos/aplicacion',
];

/**
 * Redirige al destino del atajo y corta.
 *
 * Si el nombre no está en la tabla manda a la portada en vez de tirar un error:
 * estos archivos los invoca el .htaccess por nombre, así que un atajo sin
 * destino sólo puede ser un archivo que quedó sin borrar.
 */
function atajo(string $nombre): never
{
    wwwIr(ATAJOS[$nombre] ?? '/');
}
