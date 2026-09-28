#!/bin/bash
# ============================================================
# deploy.sh - Sincroniza la app al servidor reactor
# Host objetivo:  paloalto.reactor.com.ar
# URLs servidas:  https://cloud.reactor.com.ar
#                 https://panel.reactor.com.ar
#                 https://app.reactor.com.ar   (app end-user;
#                                               alias pwa. / newapp. / webapp.)
#                 https://www.reactor.com.ar   (sitio publico;
#                                               alias del apex reactor.com.ar)
#
# Uso:
#   bash deploy.sh           # solo sube cambios (NO toca los contenedores)
#   bash deploy.sh --restart # sube + recrea los contenedores
#   bash deploy.sh --rebuild # sube + reconstruye la imagen + recrea
#                            # (necesario si cambio docker/Dockerfile)
#
# El modo por defecto no reinicia nada: cloud/, panel/, app/ y www/ estan
# bind-monteados como directorios, asi que el codigo nuevo queda vivo apenas
# termina el rsync. El script avisa al final si detecto un cambio que si
# requiere --restart o --rebuild.
# ============================================================

set -e

HOST="paloalto.reactor.com.ar"
USER="ec2-user"
KEY="/c/Users/Javier/OneDrive/Temp/Llaves/reactor/reactor.pem"
BASE_LOCAL="$(cd "$(dirname "$0")/.." && pwd)"
BASE_REMOTE="/opt/app/reactor"
COMPOSE_FILE="docker-compose.prod.yml"   # generado por aprovisionar_server.sh

# sync    -> solo sube archivos (default)
# restart -> sube + docker compose up -d --force-recreate
# rebuild -> sube + docker compose build + up -d --force-recreate
MODE="sync"
case "${1:-}" in
    ""|--sync)  MODE="sync"    ;;
    --restart)  MODE="restart" ;;
    --rebuild)  MODE="rebuild" ;;
    *)
        echo "ERROR: parametro desconocido '$1'"
        echo "       Uso: bash deploy.sh [--restart|--rebuild]"
        exit 1
        ;;
esac

VERSION="1.0.$(date +%s)"

echo ""
echo "================================================"
echo "  Deploy reactor -- version: $VERSION"
echo "  Host: $HOST"
case "$MODE" in
    sync)    echo "  Modo: sync (no se reinician contenedores)" ;;
    restart) echo "  Modo: restart (recrea contenedores)" ;;
    rebuild) echo "  Modo: rebuild (reconstruye imagen + recrea)" ;;
esac
echo "================================================"
echo ""

# ---- 1. version.txt: NO se estampa aca, se estampa en el paso 3b ----
# El archivo es el cache-bust de los assets: cada docroot lo estampa como `?v=`
# en el <link> del CSS y el <script> del JS. www/ tambien -- wwwVersion() en
# www/lib/pagina.php, estampado por sistema/cabeza.php, sistema/pie.php y
# lib/mensaje.php --, asi que si no se sube, un cambio de CSS del sitio publico
# queda invisible detras de la copia que el navegador ya tiene.
#
# En www/ ademas arrastra el tema del legacy: esos archivos se servian con
# `?rnd=478` FIJO, o sea que cualquier navegador que haya pasado por el sitio
# viejo los tiene cacheados con esa URL exacta.
#
# PERO ES TAMBIEN LO QUE LEVANTA LA BARRA DE "ACTUALIZAR" DE LA PWA. El front de
# app/ pollea `api/version.php` cada 60s y lo compara contra el valor con el que
# se cargo la pagina (`<body data-version>`, app/index.php); si difieren, muestra
# la barra. Estampar los cuatro archivos ACA -- antes de saber que cambio -- le
# mostraba esa barra a todos los usuarios de la app por un deploy que solo tocaba
# el sitio institucional, que es ruido puro: no hay nada nuevo que recargar.
#
# Por eso el numero se estampa DESPUES del rsync y SOLO en los docroots que
# efectivamente cambiaron (paso 3b). El orden importa en los dos sentidos:
# calcularlo antes no se puede (todavia no se sabe que cambio) y subirlo antes
# tampoco conviene (el `?v=` nuevo apuntaria a assets que aun no estan arriba).
echo "  version.txt: se decide despues del rsync, segun que docroot haya cambiado."
echo ""

# ---- 2. Verificar artefactos requeridos ----
for f in .env.production env.php docker/Dockerfile cloud panel app www; do
    if [ ! -e "$BASE_LOCAL/$f" ]; then
        echo "ERROR: falta $BASE_LOCAL/$f"
        exit 1
    fi
done

# ---- 3. Subir cloud/, panel/, app/, www/, docker/, db/, .env.production, env.php ----
# NO subimos docker-compose.yml: en el servidor vive docker-compose.prod.yml,
# generado por aprovisionar_server.sh (sin servicio reactor-db).
# .env.production y env.php se suben en cada deploy para mantener prod en sync.
# env.php es require_once'd desde cloud/index.php, panel/index.php y app/lib/
# db.php, y carga las constantes que leen las apps (APP_KEY_*, DB_*, MQTT_*)
# -- sin el, prod queda 500.
echo "  Subiendo cloud/, panel/, app/, www/, docker/, db/, .env.production y env.php (mirror con --delete)..."
cd "$BASE_LOCAL"

# db/ se incluye porque CLAUDE.md lo declara como schema de referencia.
# Si no existe (proyecto recien clonado en otra maquina), se omite.
INCLUDE_DB=""
if [ -d "$BASE_LOCAL/db" ]; then
    INCLUDE_DB="db"
fi

# Sync con borrado: si un archivo (o carpeta) no esta en local, tampoco
# debe quedar en el server. `tar -xzf` solo extrae encima (aditivo), por
# eso el flujo es:
#   (1) tar local -> stdin del ssh
#   (2) en remoto: extraer a un staging temporal
#   (3) en remoto: rsync -a --delete de staging hacia BASE_REMOTE por
#       carpeta (acota el alcance, evita tocar otras carpetas del server)
#   (4) en remoto: limpiar staging
# rsync vive en el server (Amazon Linux lo trae por default); no hace
# falta tenerlo instalado en local.
STAGING="/tmp/reactor-deploy-$(date +%s)"

# El remoto emite marcadores REACTOR_*: los consumimos abajo para decidir
# si hay que avisar que este deploy necesita --restart / --rebuild.
SYNC_OUT="$(
tar \
    --exclude='./cloud/.git' \
    --exclude='./cloud/node_modules' \
    --exclude='./cloud/vendor' \
    --exclude='./panel/.git' \
    --exclude='./panel/node_modules' \
    --exclude='./panel/vendor' \
    --exclude='./app/.git' \
    --exclude='./app/node_modules' \
    --exclude='./app/vendor' \
    --exclude='./www/.git' \
    --exclude='./www/node_modules' \
    --exclude='./www/vendor' \
    --exclude='*.log' \
    --exclude='*.pem' \
    --exclude='*.key' \
    -czf - cloud panel app www docker $INCLUDE_DB .env.production env.php | \
ssh -i "$KEY" -o StrictHostKeyChecking=no \
    "$USER@$HOST" "
        set -e
        mkdir -p '$STAGING'
        tar -xzf - -C '$STAGING/'
        for dir in cloud panel app www docker $INCLUDE_DB; do
            if [ -d \"$STAGING/\$dir\" ]; then
                # -i itemiza; filtramos a transferencias/borrados reales
                # (las lineas que empiezan con '.' son solo atributos).
                #
                # version.txt se EXCLUYE del sync -- y el --delete respeta los
                # excluidos, asi que el del server sobrevive --: lo escribe el
                # paso 3b recien cuando se sabe que cambio. Si viajara aca
                # contaminaria su propia deteccion: el archivo que anuncia el
                # cambio seria el unico cambio, y todo docroot figuraria
                # modificado siempre. El '/' inicial lo ancla a la raiz del
                # docroot (no matchea un assets/version.txt).
                changed=\$(rsync -ai --delete --exclude='/version.txt' \
                              \"$STAGING/\$dir/\" \"$BASE_REMOTE/\$dir/\" \
                          | grep -E '^(>|<|\*deleting)' || true)
                # Quien cambio. docker/ ademas define la imagen (Dockerfile,
                # ports.conf, vhosts.conf): si cambio, el contenedor corriendo
                # quedo desactualizado.
                if [ -n \"\$changed\" ]; then
                    echo \"REACTOR_CAMBIO:\$dir\"
                fi
                # Y que version quedo publicada: el paso 3b la copia al working
                # tree de los docroots que NO se bumpean, para que el repo diga
                # lo mismo que el servidor.
                if [ -f \"$BASE_REMOTE/\$dir/version.txt\" ]; then
                    echo \"REACTOR_VERSION:\$dir:\$(tr -d '\r\n' < \"$BASE_REMOTE/\$dir/version.txt\")\"
                fi
            fi
        done
        # app/ es el ultimo docroot que se sumo: si el compose del server
        # todavia no lo bind-montea, subir los archivos no alcanza -- el
        # contenedor sirve /var/www/app vacio y app.reactor.com.ar da 404.
        if ! grep -q '/var/www/app' \"$BASE_REMOTE/$COMPOSE_FILE\" 2>/dev/null; then
            echo 'REACTOR_COMPOSE_SIN_APP'
        fi
        # www/ es el docroot mas nuevo (2026-09-15) y arrastra el mismo
        # problema: el compose del server es anterior, asi que no lo montea.
        # El grep tiene que ser por el path COMPLETO con dos puntos:
        # '/var/www/www' sin mas tambien matchearia '/var/www/www...' de un
        # mount futuro, y sobre todo el prefijo '/var/www/' aparece en todas
        # las lineas -- este es el unico mount cuyo nombre es prefijo de si
        # mismo.
        if ! grep -q ':/var/www/www$' \"$BASE_REMOTE/$COMPOSE_FILE\" 2>/dev/null; then
            echo 'REACTOR_COMPOSE_SIN_WWW'
        fi
        for f in .env.production env.php; do
            [ -f \"$STAGING/\$f\" ] || continue
            # Si Docker hizo bind-mount cuando el archivo no existia, el
            # path en el host quedo como directorio vacio. Lo removemos
            # antes de copiar el archivo nuevo.
            if [ -d \"$BASE_REMOTE/\$f\" ]; then
                rm -rf \"$BASE_REMOTE/\$f\"
            fi
            if [ -f \"$BASE_REMOTE/\$f\" ]; then
                if ! cmp -s \"$STAGING/\$f\" \"$BASE_REMOTE/\$f\"; then
                    # Escritura in-place: '>' trunca y reescribe el MISMO
                    # inodo. Docker bind-montea estos archivos por inodo, asi
                    # que el contenedor ve el contenido nuevo sin recrearse
                    # (un 'cp' que reemplace el inodo lo dejaria viendo el
                    # archivo viejo).
                    cat \"$STAGING/\$f\" > \"$BASE_REMOTE/\$f\"
                    echo \"REACTOR_ENV_CHANGED:\$f\"
                fi
            else
                # No existia (o era el directorio vacio que acabamos de
                # borrar): el bind-mount del contenedor apunta a otra cosa.
                cp -f \"$STAGING/\$f\" \"$BASE_REMOTE/\$f\"
                echo \"REACTOR_ENV_NEW:\$f\"
            fi
        done
        rm -rf '$STAGING'
    "
)"
echo "  OK"
echo ""

# ---- 3b. version.txt, solo en los docroots que cambiaron ----
#
# LA REGLA: un docroot se bumpea si y solo si su propio contenido cambio. De ahi
# salen las dos propiedades que se necesitan a la vez:
#
#   - el cache-bust sigue siendo correcto por construccion: si un asset de app/
#     cambio, app/ cambio, y el `?v=` sube. Al reves tambien -- si no cambio
#     nada, no hay ningun archivo nuevo que rebajar, asi que no bumpear no puede
#     dejar al navegador con una copia vieja: la copia vieja ES la actual.
#   - la barra de "Actualizar" de la PWA aparece solo cuando hay algo nuevo en
#     app/. Un deploy de www/ ya no la levanta.
#
# La deteccion es la del rsync del paso 3, o sea la diferencia real contra lo que
# hay publicado, y no un `git diff` (el deploy sube el working tree, no un
# commit). Si rsync duda, transfiere: el error posible es bumpear de mas -- una
# barra sobrante --, no de menos -- un asset viejo pegado.
DOCROOTS="cloud panel app www"

# Lo que cambia fuera de los docroots pero los afecta a los cuatro: docker/ es la
# imagen que los sirve, y env.php / .env.production son las constantes que lee el
# PHP de todos (APP_KEY_*, DB_*, MQTT_*). Ahi se bumpea todo.
TODOS=0
if echo "$SYNC_OUT" | grep -q '^REACTOR_CAMBIO:docker$'; then TODOS=1; fi
if echo "$SYNC_OUT" | grep -q '^REACTOR_ENV_'; then TODOS=1; fi

BUMPEADOS=""
for d in $DOCROOTS; do
    if [ "$TODOS" = 1 ] || echo "$SYNC_OUT" | grep -q "^REACTOR_CAMBIO:$d\$"; then
        echo "$VERSION" > "$BASE_LOCAL/$d/version.txt"
        BUMPEADOS="$BUMPEADOS $d"
    else
        # Sin cambios: el working tree se alinea con lo que quedo publicado, para
        # que el repo no arrastre un numero que el servidor no tiene.
        remoto="$(echo "$SYNC_OUT" | sed -n "s/^REACTOR_VERSION:$d://p" | tail -n 1)"
        if [ -n "$remoto" ]; then
            echo "$remoto" > "$BASE_LOCAL/$d/version.txt"
        fi
    fi
done

if [ -n "$BUMPEADOS" ]; then
    ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" "
        set -e
        for d in $BUMPEADOS; do
            echo '$VERSION' > '$BASE_REMOTE/\$d/version.txt'
        done
    "
    echo "  version.txt -> $VERSION  en:$BUMPEADOS"
    for d in $DOCROOTS; do
        case " $BUMPEADOS " in
            *" $d "*) ;;
            *) echo "  version.txt -> sin cambios en $d/, se deja como estaba" ;;
        esac
    done
else
    echo "  version.txt: ningun docroot cambio, no se bumpea ninguno."
fi
echo ""

# ---- 4. Rebuild / recreate del contenedor (solo si se pidio) ----
# Por defecto NO se toca el contenedor. La gran mayoria de los deploys son
# cambios de codigo en cloud/ y panel/, y esas dos carpetas estan
# bind-monteadas como DIRECTORIOS: el contenedor resuelve cada archivo por
# path en cada request, asi que el codigo nuevo ya esta vivo al terminar el
# paso 3. (No hay opcache habilitado en la imagen -- ver docker/Dockerfile --
# asi que tampoco hay bytecode cacheado que invalidar.)
#
# .env.production y env.php se bind-montean por ARCHIVO, o sea por inodo. El
# paso 3 los reescribe in-place para no romper ese mount, de modo que env.php
# (require_once en cada request) tambien queda vivo sin recrear.
#
# Cuando SI hace falta recrear:
#   --restart : cambio .env.production. Las vars de 'env_file:' se inyectan
#               al proceso al CREAR el contenedor; el archivo nuevo no las
#               actualiza. Tambien si el bind-mount quedo roto (env NEW).
#   --rebuild : cambio docker/ (Dockerfile, ports.conf, vhosts.conf) o
#               cualquier cosa horneada en la imagen.
case "$MODE" in
    rebuild)
        echo "  Reconstruyendo imagen Docker y recreando contenedores..."
        ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
            "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE build && docker compose -f $COMPOSE_FILE up -d --force-recreate"
        echo "  OK -- imagen reconstruida y contenedores levantados"
        ;;
    restart)
        echo "  Recreando contenedores..."
        ssh -i "$KEY" -o StrictHostKeyChecking=no "$USER@$HOST" \
            "cd '$BASE_REMOTE' && docker compose -f $COMPOSE_FILE up -d --force-recreate"
        echo "  OK -- contenedores recreados"
        ;;
    sync)
        echo "  Sin reinicio: cloud/, panel/, app/ y www/ son bind-mounts, los cambios ya estan vivos."

        # Avisos: cambios que el sync solo NO alcanza a activar.
        AVISOS=""
        if echo "$SYNC_OUT" | grep -q '^REACTOR_CAMBIO:docker$'; then
            AVISOS="$AVISOS
    - Cambio docker/ (imagen): correr 'bash deploy.sh --rebuild' para activarlo."
        fi
        if echo "$SYNC_OUT" | grep -q '^REACTOR_ENV_CHANGED:\.env\.production$'; then
            AVISOS="$AVISOS
    - Cambio .env.production: las vars de env_file: se inyectan al crear el
      contenedor. Correr 'bash deploy.sh --restart' para activarlas."
        fi
        if echo "$SYNC_OUT" | grep -q '^REACTOR_ENV_NEW:'; then
            AVISOS="$AVISOS
    - Se creo un archivo de entorno que no existia en el server: el
      bind-mount del contenedor quedo apuntando al inodo viejo. Correr
      'bash deploy.sh --restart'."
        fi

        if [ -n "$AVISOS" ]; then
            echo ""
            echo "  AVISO -- este deploy incluye cambios que necesitan recrear:$AVISOS"
        fi
        ;;
esac
echo ""

# ---- 4b. El compose del server no monta app/ o www/ ----
# aprovisionar_server.sh genera docker-compose.prod.yml, pero deploy.sh NO lo
# regenera: si el server quedo con la version previa a que esos docroots
# existieran, ningun modo de este script alcanza para publicarlos. Hay que
# re-aprovisionar. (Ni siquiera --rebuild sirve: reconstruye la imagen leyendo
# el compose viejo, que no tiene ni el mount ni el puerto publicado.)
if echo "$SYNC_OUT" | grep -q '^REACTOR_COMPOSE_SIN_APP$'; then
    echo "  AVISO -- el $COMPOSE_FILE del server no bind-montea ./app:/var/www/app."
    echo "           Los archivos se subieron, pero el contenedor no los sirve todavia."
    echo "           Correr una vez: bash scripts/aprovisionar.sh"
    echo "           (regenera el compose con el puerto 8115, agrega el server block"
    echo "            de nginx para app.reactor.com.ar y suma el dominio al cert SSL)."
    echo ""
fi
if echo "$SYNC_OUT" | grep -q '^REACTOR_COMPOSE_SIN_WWW$'; then
    echo "  AVISO -- el $COMPOSE_FILE del server no bind-montea ./www:/var/www/www."
    echo "           Los archivos se subieron, pero el contenedor no los sirve todavia."
    echo "           Correr una vez: bash scripts/aprovisionar.sh"
    echo "           (regenera el compose con el puerto 8134, agrega el server block"
    echo "            de nginx para www.reactor.com.ar + el apex, y los suma al cert)."
    echo "           OJO: el apex solo entra al cert si su DNS ya apunta al server"
    echo "           con un registro A -- mover ese DNS baja el sitio que este hoy"
    echo "           en reactor.com.ar, asi que es un paso deliberado."
    echo ""
fi

# ---- 5. Migraciones SQL ----
# Las migraciones viven en cloud/sql/migrations/ y son idempotentes.
# Como el contenedor PHP no trae cliente mysql, se aplican manualmente
# desde un host con acceso a RDS, por ejemplo:
#   for f in cloud/sql/migrations/*.sql; do
#       mysql -h <RDS_HOST> -u <USER> -p<PASS> reactor < "$f"
#   done
echo "  Migraciones SQL: aplicar manualmente contra RDS (ver comentario en deploy.sh)."
echo ""

echo "================================================"
echo "  Deploy completo"
echo "    cloud: https://cloud.reactor.com.ar"
echo "    panel: https://panel.reactor.com.ar"
echo "    app:   https://app.reactor.com.ar   (alias: pwa. / newapp. / webapp.)"
echo "    www:   https://www.reactor.com.ar   (alias: reactor.com.ar)"
echo "================================================"
echo ""
