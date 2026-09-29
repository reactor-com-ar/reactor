<?php

declare(strict_types=1);

/**
 * Codigo de verificacion de 6 digitos del login de la app
 * (`usuarios.autenticacion = 'T'`, 29 cuentas de ~2080).
 *
 * QUE ARREGLA (29/09/2026). Hasta hoy `sesion/clave.php` generaba el codigo,
 * lo guardaba en `usuarios.clave` y lo ENCOLABA en `mensajes`, igual que el
 * legacy. En este monorepo NADIE consume esa cola: el unico consumidor era
 * `reactor-api` y la ultima fila realmente enviada es del 2024-06-22 (todo lo
 * posterior quedo con `estado = '1'` y `enviado = '1500-01-01'`). O sea que la
 * pantalla prometia un mensaje que no salia y esas 29 cuentas no podian
 * entrar. Ahora el codigo sale por el MISMO canal que la recuperacion de
 * contrasena y las invitaciones: el microservicio de correo de Databox
 * (`lib/databox.php`, POST /v4/aws/mensajes).
 *
 * SIEMPRE POR CORREO, a `usuarios.correo`, se haya tipeado el celular o el
 * correo en el paso 1. El legacy elegia el canal segun lo tipeado —'C' correo
 * si tenia '@', 'W' WhatsApp si no— y en este monorepo NO hay cliente de
 * WhatsApp: mantener esa rama seria seguir prometiendo un mensaje que no sale,
 * que es exactamente el bug que se esta arreglando. Las 29 cuentas con
 * `autenticacion = 'T'` tienen correo cargado, asi que el correo las cubre a
 * todas. La pantalla lo dice mostrando el correo enmascarado, para que quien
 * entro tipeando el celular sepa a que casilla mirar.
 *
 * LA FILA EN `mensajes` SE SIGUE ESCRIBIENDO, y por dos razones concretas:
 *
 *   1. Es el LIBRO DEL CUPO. `claveCupo()` cuenta filas de la ultima hora para
 *      frenar el uso de la pantalla como maquina de mandar correos a una
 *      casilla ajena — el codigo se emite sin contrasena, asi que cualquiera
 *      que conozca el correo de una cuenta 'T' llega hasta aca. El cupo tiene
 *      que vivir en el servidor: la marca de emision que guarda la cookie del
 *      paso 1 sirve para no reenviar en cada refresh, pero un script que no
 *      guarde cookies la ignora.
 *   2. Es el rastro que el back office viejo lista.
 *
 * Y SE ESCRIBE YA CERRADA (`enviado = NOW()`, `estado = '2'`), que es la
 * verdad —el correo ya salio cuando se hace el INSERT— y ademas evita que un
 * worker de la cola, si algun dia existe, lo mande por segunda vez.
 *
 * EL TEXTO DE ESA FILA NO LLEVA EL CODIGO. La cola guarda las 177 filas desde
 * 2023 para siempre y las del legacy tienen contrasenas en claro adentro
 * ("La contrasena de tu cuenta de Reactor es Wolf1214"). Un codigo de un solo
 * uso no tiene por que quedar escrito en un log permanente: la fila dice que
 * se envio, no que se envio.
 */

require_once dirname(__DIR__, 2) . '/env.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/databox.php';
require_once __DIR__ . '/auth.php';

/** Largo del codigo. Es el varchar(6) de `usuarios.clave`: no se puede subir. */
const CLAVE_DIGITOS = 6;

/** Envios por cuenta en la ultima hora. Ver el punto 1 del docblock. */
const CLAVE_CUPO_CUENTA = 5;
const CLAVE_CUPO_HORAS  = 1;

/** Canal y estado con los que se escribe la fila de `mensajes` (C = correo). */
const CLAVE_MENSAJE_CANAL   = 'C';
const CLAVE_MENSAJE_ENVIADO = '2';

/**
 * Cuantos minutos vive el codigo.
 *
 * NO es una vigencia propia: es la de la cookie del paso 1 (APP_LOGIN_TTL, 10
 * minutos), que es la unica credencial que lleva el `uid` con el que el POST
 * sabe contra que cuenta comparar. Sin esa cookie viva, `sesion/clave.php`
 * rebota a `iniciar` antes de mirar `usuarios.clave`, asi que un codigo viejo
 * en la columna no sirve para nada. Se emite la cookie de nuevo al mandar el
 * codigo, asi que la ventana arranca en el envio y no en el paso 1.
 */
function claveVigenciaMinutos(): int
{
    return intdiv(APP_LOGIN_TTL, 60);
}

/**
 * Codigo nuevo, de CSPRNG. Con relleno de ceros a la izquierda: el espacio son
 * el millon de combinaciones y no las 900.000 que quedarian si se empezara en
 * 100000.
 */
function claveNueva(): string
{
    return str_pad((string) random_int(0, (10 ** CLAVE_DIGITOS) - 1), CLAVE_DIGITOS, '0', STR_PAD_LEFT);
}

/**
 * `javier@databox.net.ar` -> `j***@databox.net.ar`.
 *
 * Se muestra en la pantalla porque a una cuenta 'T' se entra tambien tipeando
 * el celular, y en ese caso la persona no tiene de donde deducir a que casilla
 * le llego. Enmascarado y no completo porque esa pantalla la ve cualquiera que
 * tipee el celular o el correo de la cuenta: alcanza para reconocer la casilla
 * propia y no para descubrir una ajena. Los asteriscos son SIEMPRE tres, asi
 * que tampoco delatan el largo.
 *
 * Devuelve '' si no hay correo o si no tiene forma de correo — la pantalla usa
 * ese vacio para caer al encabezado generico.
 */
function claveCorreoEnmascarado(string $correo): string
{
    $correo = trim($correo);
    $arroba = strrpos($correo, '@');
    if ($arroba === false || $arroba === 0 || $arroba === strlen($correo) - 1) {
        return '';
    }

    return mb_substr($correo, 0, 1) . '***' . mb_substr($correo, $arroba);
}

/**
 * Cupo de la ultima hora para esa cuenta.
 *
 * Cuenta TODAS las filas de `mensajes` del usuario, no solo las que escribe
 * esta pantalla, porque la tabla no tiene con que distinguirlas. El error va
 * para el lado seguro: contar de mas hace el cupo mas estricto, nunca mas
 * flojo. En la practica no cambia nada — ningun otro camino del monorepo
 * escribe ahi.
 *
 * La ventana se mide con NOW() de la base y no con el reloj de PHP: los dos no
 * estan alineados (PHP corre en UTC y `lib/db.php` fija la sesion de MySQL en
 * -03:00), mismo motivo que ya documenta `lib/recuperacion.php`.
 */
function claveCupo(int $usuarioId): bool
{
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM mensajes
          WHERE usuario = :u AND encolado > DATE_SUB(NOW(), INTERVAL :h HOUR)'
    );
    $stmt->execute([':u' => $usuarioId, ':h' => CLAVE_CUPO_HORAS]);

    return (int) $stmt->fetchColumn() < CLAVE_CUPO_CUENTA;
}

/**
 * Genera el codigo, lo guarda y lo manda por correo.
 *
 * La escritura y el envio van en una transaccion, igual que `recuperacionEmitir()`:
 * si el microservicio no acepto el mensaje se revierte todo, y como se revierte
 * TAMBIEN el `UPDATE` de `usuarios.clave`, el codigo anterior —el que la persona
 * quiza si tenga en la casilla— sigue sirviendo. Pisarlo con uno que nadie
 * recibio la dejaria afuera.
 *
 * El detalle del fallo de Databox no sale a la pantalla (puede nombrar la
 * apikey o el endpoint): va al log y la persona ve un texto generico.
 *
 * @return array{ok:bool,error:?string}
 */
function claveEmitir(array $usuario): array
{
    $id     = (int) ($usuario['id'] ?? 0);
    $correo = trim((string) ($usuario['correo'] ?? ''));
    $nombre = trim((string) ($usuario['nombre'] ?? ''));

    if ($correo === '') {
        // Hoy no pasa —las 29 cuentas 'T' tienen correo— pero nada lo garantiza:
        // `usuarios.correo` es NULL-able y el modo 'T' se asigna a mano desde el
        // back office viejo. Sin correo no hay a donde mandarlo.
        return ['ok' => false, 'error' => 'La cuenta no tiene un correo cargado. Comunicate con Reactor.'];
    }

    if (!claveCupo($id)) {
        return ['ok' => false, 'error' => 'Se enviaron demasiados códigos en la última hora. Probá de nuevo más tarde.'];
    }

    $pdo   = db();
    $clave = claveNueva();

    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare('UPDATE usuarios SET clave = :c WHERE id = :id');
        $upd->execute([':c' => $clave, ':id' => $id]);

        $ins = $pdo->prepare(
            'INSERT INTO mensajes (canal, usuario, destinatario, destino, texto, encolado, enviado, estado)
             VALUES (:canal, :usuario, :destinatario, :destino, :texto, NOW(), NOW(), :estado)'
        );
        $ins->execute([
            ':canal'        => CLAVE_MENSAJE_CANAL,
            ':usuario'      => $id,
            ':destinatario' => $nombre,
            ':destino'      => $correo,
            // Sin el codigo adentro, a proposito: ver el docblock de arriba.
            ':texto'        => 'Código de verificación del ingreso a la app.',
            ':estado'       => CLAVE_MENSAJE_ENVIADO,
        ]);

        $envio = databoxCorreoEncolar([
            'destino'      => $correo,
            'destinatario' => $nombre,
            'asunto'       => 'Tu código de verificación de Reactor',
            'cuerpo'       => claveCuerpoCorreo($nombre, $clave, claveVigenciaMinutos()),
            // 5 = la mas alta que acepta el microservicio. Es el unico correo
            // del sistema con alguien esperandolo del otro lado y una ventana de
            // 10 minutos: si sale detras de una difusion, llega vencido.
            'prioridad'    => 5,
            'tags'         => 'clave-ingreso',
        ]);

        if (!$envio['ok']) {
            $pdo->rollBack();
            error_log('[clave] no se pudo enviar el código al usuario ' . $id . ': ' . (string) $envio['error']);
            return ['ok' => false, 'error' => 'No pudimos enviar el código. Probá de nuevo en un momento.'];
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    return ['ok' => true, 'error' => null];
}

/**
 * Borra el codigo usado.
 *
 * Es lo que hace cierto el "sirve una sola vez" del correo: sin esto el valor
 * queda en la columna hasta la proxima emision. No bloquea el login si falla —
 * la sesion ya se gano con el codigo correcto.
 */
function claveConsumir(int $usuarioId): void
{
    try {
        $upd = db()->prepare('UPDATE usuarios SET clave = NULL WHERE id = :id');
        $upd->execute([':id' => $usuarioId]);
    } catch (Throwable $_) { /* noop */ }
}

/**
 * Cuerpo HTML del correo.
 *
 * Es un fragmento y no un documento: la plantilla `reactor` de Databox lo
 * inserta en {cuerpo} y aporta encabezado y pie. El estilo del codigo va inline
 * porque ningun cliente de correo aplica hojas externas.
 */
function claveCuerpoCorreo(string $nombre, string $clave, int $minutos): string
{
    $hola   = $nombre !== '' ? '<p>Hola ' . htmlspecialchars($nombre, ENT_QUOTES, 'UTF-8') . ',</p>' : '';
    $codigo = htmlspecialchars($clave, ENT_QUOTES, 'UTF-8');

    return $hola
         . '<p>Tu código de verificación para entrar a <strong>Reactor</strong> es:</p>'
         . '<p style="font-size:30px;font-weight:bold;letter-spacing:8px;margin:20px 0;">' . $codigo . '</p>'
         . '<p>Vence en ' . $minutos . ' minutos y sirve una sola vez.</p>'
         . '<p>Si no estabas intentando entrar, ignorá este mensaje: sin el código nadie puede usar tu cuenta.</p>';
}
