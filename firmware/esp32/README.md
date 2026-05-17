# Firmware ESP32 — Reactor

Firmware base para dispositivos basados en **ESP32** (DevKit-C, NodeMCU-32S,
Wemos D1 R32, módulos WROOM-32 / WROVER, etc.) que forman parte de la
plataforma IoT **Reactor**. Estos dispositivos se administran desde el panel
web en [`cloud/`](../../cloud/) y reportan datos vía MQTT.

Sketch principal: [`esp32.ino`](esp32.ino).

Este firmware es el **port directo** del de `firmware/esp8266/`: misma
estructura de `/config.json`, mismo schema MQTT (`reactor/<uid>/config`),
mismo panel web y mismo flujo de boot. Las diferencias están localizadas en
los includes, el tipo del servidor web global, el montaje de LittleFS y la
resolución de PWM (ver "Diferencias respecto al sketch ESP8266" más abajo).

---

## Qué hace el firmware

Un dispositivo cumple este ciclo de vida en cada arranque:

1. **Boot.** Inicializa el puerto serial (115200 baud), monta **LittleFS**
   (lo formatea automáticamente si el mount falla, vía `LittleFS.begin(true)`)
   y calcula su `uid` a partir de la MAC del chip (`WiFi.macAddress()` sin `:`).
2. **Carga de configuración.** Lee `/config.json` desde LittleFS — un único
   archivo que concentra UID, versión, red WiFi, broker MQTT, usuarios del
   panel y lista de canales del dispositivo. Si no existe o tiene JSON
   inválido, lo regenera con los defaults compilados (constantes `DEFAULT_*`).
3. **Sync uid/version.** Compara el `uid` del JSON contra la MAC real y la
   `version` contra `FIRMWARE_VERSION` del sketch. Si alguno difiere
   (firmware actualizado o FS transferido a otro chip), reescribe el JSON
   para mantenerlo en sync con la realidad.
4. **Aplicación de canales al hardware.** Recorre la sección `channels` del
   JSON y configura cada pin (`pinMode` + `digitalWrite`/`analogWrite` según
   el `mode` declarado en el canal). Los pines 6–11 se ignoran porque están
   conectados al flash SPI interna del módulo (WROOM/WROVER).
5. **Conexión WiFi.** Modo estación (STA) usando `wifi.ssid` y `wifi.password`
   del JSON. Timeout de 20 s; si falla, reintenta cada 5 s sin reiniciar el
   chip. Al conectar imprime IP, RSSI y MAC por serial.
6. **Conexión MQTT.** Una vez con red, abre sesión contra el broker
   configurado (`mqtt.host:mqtt.port`, por defecto `tcr.reactor.com.ar:1883`).
   - `clientId` = `uid` (= MAC del chip sin `:`) → mismo formato que la
     columna `uid` de la tabla `dispositivos` en `cloud/`.
   - Si `mqtt.user` está vacío, se conecta anónimo; si tiene valor, se envían
     `user`/`password` como autenticación MQTT.
7. **Suscripción a config remota.** Al conectar el broker, el dispositivo se
   suscribe a `reactor/<uid>/config` con QoS 1. Cuando el cloud publica un
   mensaje retenido en ese topic, el dispositivo recibe la lista de canales
   (`channels`) y la persiste en la misma sección de `/config.json`.
8. **Servidor web.** Levanta un panel HTTP en el puerto 80 protegido con
   HTTP Basic Auth para ver el estado del dispositivo y editar `/config.json`
   desde el navegador.
9. **Operación.** Mantiene viva la sesión MQTT con `mqtt.loop()`, recibe
   mensajes en el callback `onMqttMessage()` y atiende al servidor web con
   `server.handleClient()`. Si se cae WiFi o MQTT, reintenta automáticamente
   sin reset.

Todo el comportamiento es resiliente: una caída de red o de broker **no**
reinicia el chip; solo dispara reintentos con backoff fijo.

---

## Archivo de configuración

Toda la configuración persistente vive en **un único archivo JSON** en
LittleFS: `/config.json`. Concentra seis secciones de nivel raíz —
`uid`, `version`, `wifi`, `mqtt`, `users` y `channels`. Se serializa con
`serializeJsonPretty` (indentado, fácil de leer y editar a mano) y se
regenera con los defaults compilados (constantes `DEFAULT_*`) si falta o
si el JSON es inválido.

Sobrevive a reboots y a re-flashes del sketch (mientras no se borre la
partición FS al subir firmware).

**¿Por qué todo en un solo archivo?** Es la unidad de sync con el cloud:
el broker MQTT publica/recibe el JSON entero como un snapshot atómico,
sin tener que orquestar múltiples topics ni manejar estados intermedios.
Una sola escritura en flash = un solo punto de verdad consistente. Con el
heap libre del ESP32 (≥ 200 KB) hay margen sobrado para cualquier tamaño
de config realista.

### Estructura

Ejemplo completo de `/config.json` con todas las secciones pobladas (es lo
que vas a ver en un dispositivo ya provisionado y sincronizado con el
broker):

```json
{
  "uid": "84F3EB12AB34",
  "version": "0.1.0",
  "wifi": {
    "ssid": "MiRedWiFi",
    "password": "secreto"
  },
  "mqtt": {
    "host": "tcr.reactor.com.ar",
    "port": 1883,
    "user": "",
    "password": ""
  },
  "users": [
    {
      "username": "admin",
      "password": "admin"
    },
    {
      "username": "root",
      "password": "root"
    }
  ],
  "channels": [
    {
      "id": 1,
      "tipo": "rele",
      "pin": 16,
      "mode": "output",
      "label": "rele_1",
      "initial": 0,
      "inverted": true
    },
    {
      "id": 2,
      "tipo": "rele",
      "pin": 17,
      "mode": "output",
      "label": "rele_2",
      "initial": 0,
      "inverted": true
    },
    {
      "id": 3,
      "tipo": "sensor_apertura",
      "pin": 18,
      "mode": "input_pullup",
      "label": "puerta_principal",
      "initial": 0,
      "inverted": false
    },
    {
      "id": 4,
      "tipo": "sensor_temperatura",
      "pin": 34,
      "mode": "input",
      "label": "temp_ambiente",
      "initial": 0,
      "inverted": false
    }
  ]
}
```

En el primer boot (sin provisioning previo) el firmware genera el mismo
documento pero con los defaults del sketch (`wifi.ssid` = `"TU_SSID"`,
`channels` como array vacío, etc.).

### Campos raíz `uid` y `version`

| Campo | Tipo | Descripción |
|---|---|---|
| `uid` | string (12 hex) | MAC del WiFi sin `:`, en mayúsculas. **No editable**: el firmware lo recalcula en cada boot y reescribe el JSON si no coincide. Es el mismo valor que se usa como `clientId` MQTT y como `dispositivos.uid` en `cloud/`. |
| `version` | string (semver) | Versión del firmware actualmente corriendo. **No editable**: viene de la constante `FIRMWARE_VERSION` en el `.ino`; el firmware reescribe el JSON cuando difiere (por ejemplo al actualizar el sketch). |

Estos dos campos son **derivados**: editar el JSON a mano para cambiarlos
no tiene efecto, porque el firmware los sobreescribe en el siguiente boot.

### Sección `wifi` y `mqtt`

| Campo | Tipo | Descripción |
|---|---|---|
| `wifi.ssid` | string | SSID de la red WiFi a la que se asocia el dispositivo. |
| `wifi.password` | string | Contraseña WPA/WPA2 de esa red. |
| `mqtt.host` | string | Hostname o IP del broker MQTT. |
| `mqtt.port` | int (1–65535) | Puerto TCP del broker. `1883` = MQTT plano (sin TLS). |
| `mqtt.user` | string | Usuario MQTT. Vacío = conexión anónima al broker. |
| `mqtt.password` | string | Contraseña MQTT. Se envía solo si `mqtt.user` no está vacío. |

### Sección `users`

| Campo | Tipo | Descripción |
|---|---|---|
| `users` | array (length 2) | Lista de credenciales válidas para el panel web. |
| `users[i].username` | string | Nombre de usuario para HTTP Basic Auth. |
| `users[i].password` | string | Contraseña en texto plano. |

Notas importantes sobre `users`:

- El firmware espera **exactamente 2 entradas**. Si el array tiene menos de 2,
  esa sección se regenera con los defaults; las entradas adicionales se
  ignoran.
- Los nombres `admin` y `root` son convención por defecto. El match de
  Basic Auth se hace contra lo que esté guardado, así que técnicamente
  podrías renombrarlos editando el JSON — pero para mantener consistencia
  entre la flota, conviene dejarlos así.
- Las contraseñas se guardan **en texto plano**. Cualquiera con acceso
  físico al chip puede leerlas dumpeando LittleFS. **Cambialas en cuanto
  el equipo esté instalado** y no uses las contraseñas por defecto fuera
  de un entorno de laboratorio.

### Sección `channels` — canales del dispositivo

Cada canal combina **qué es** (identidad lógica: id + tipo de módulo) con
**cómo está cableado** (mapeo de hardware: pin, modo, valor inicial,
inversión). Un dispositivo soporta hasta **8 canales** (`MAX_CHANNELS` en
el `.ino`).

A diferencia de las otras secciones, `channels` **no se edita
localmente**: la lista la publica el cloud por MQTT y el firmware solo la
recibe, persiste y aplica al hardware. El panel web la muestra read-only.

| Campo | Tipo | Descripción |
|---|---|---|
| `channels` | array (length ≤ 8) | Lista de canales del dispositivo. Vacío hasta que el broker publique la config. |
| `channels[i].id` | int (1–8) | Identificador del canal dentro del dispositivo. Debe ser único. Fuera de rango → ignorado. |
| `channels[i].tipo` | string | Tipo de módulo conectado a ese canal. Ver tabla más abajo. |
| `channels[i].pin` | int | GPIO del ESP32 al que está conectado el módulo. Usar números de GPIO, no etiquetas de placa. |
| `channels[i].mode` | string | Modo de pin Arduino a aplicar al boot. Ver tabla. |
| `channels[i].label` | string | Nombre human-readable para logs/UI (ej. `"rele_caldera"`, `"puerta_garage"`). |
| `channels[i].initial` | int | Valor inicial al boot. Para `output`: `0` o `1`. Para `pwm`: `0`–`1023`. Ignorado en modos de entrada. |
| `channels[i].inverted` | bool | Si `true`, invierte la lógica del pin (útil para relés activos en LOW). |

Tipos de módulo reconocidos hoy:

| `tipo` | Significado | Modo típico |
|---|---|---|
| `rele` | Salida de relé (digital on/off). | `output` |
| `sensor_apertura` | Sensor magnético de apertura, contacto seco. | `input_pullup` |
| `sensor_temperatura` | Sensor de temperatura. | `input` |

Los tipos desconocidos **se aceptan igual** (con log de warning). Eso
permite que el cloud introduzca tipos nuevos sin actualizar firmware en
toda la flota.

Modos de pin soportados:

| `mode` | Llamada | Cuándo usarlo |
|---|---|---|
| `output` | `pinMode(OUTPUT)` + `digitalWrite(initial)` | Relé, transistor, LED on/off. |
| `input` | `pinMode(INPUT)` | Sensor digital con pull-down externo, o pin de solo-entrada (GPIO 34–39). |
| `input_pullup` | `pinMode(INPUT_PULLUP)` | Botón / contacto seco contra GND. **No** disponible en GPIO 34–39 (no tienen pull-up interno). |
| `pwm` | `pinMode(OUTPUT)` + `analogWriteResolution(10)` + `analogWrite(initial)` | Atenuación de LED, control de velocidad. Rango 0–1023 (10 bits, alineado con ESP8266). |
| `unused` | (nada) | Reserva el slot del canal pero no toca el hardware. |

Reglas y límites de hardware (específicos de ESP32):

- Máximo **8 canales** (`MAX_CHANNELS` en el `.ino`). Si el payload trae
  más, los sobrantes se truncan con warning.
- Los pines **6–11 están reservados** para la flash SPI interna en módulos
  WROOM/WROVER; configurarlos cuelga el chip. El firmware loguea
  `[channel] #N pin X ignorado (reservado para flash SPI)` y sigue. En
  módulos PICO-D4 o WROOM-V3 estos pines sí están disponibles, pero el
  guardrail se mantiene por defecto.
- **GPIO 34–39 son solo entrada** (input-only). Setearlos como `output` o
  `pwm` no tiene efecto. Tampoco tienen pull-up/pull-down interno, así que
  `input_pullup` ahí necesita una resistencia externa.
- Pines de **strapping** que afectan el modo de boot: GPIO 0, 2, 12, 15.
  Conviene no usarlos como salidas activas durante el reset.
- Pines típicamente expuestos y seguros: 13, 14, 16, 17, 18, 19, 21, 22,
  23, 25, 26, 27, 32, 33.

#### Flujo de sincronización con el broker

1. **Suscripción.** Al conectar MQTT, el firmware se suscribe a
   `reactor/<uid>/config` con QoS 1.
2. **Recepción.** El cloud publica en ese topic con `retained=true` un
   payload JSON con la lista completa de canales del dispositivo. El
   mensaje retenido se entrega inmediatamente al suscribirse (incluso si
   se publicó días antes), así que el dispositivo recibe su config tan
   pronto se reconecta.
3. **Aplicación.** El firmware parsea el payload, valida cada `id` en
   rango `1..8`, descarta entradas inválidas, reemplaza la lista local de
   canales, persiste todo `/config.json` con `guardarConfig()` y reaplica
   el hardware con `aplicarChannels()` (pinMode + valor inicial). Los
   pines reasignados toman efecto sin necesidad de reboot.
4. **Resiliencia.** Si el broker está offline, el dispositivo arranca con
   la última lista persistida en `/config.json`. La sincronización se
   pone al día apenas se reconecta.

Payload esperado en el topic `reactor/<uid>/config` (mismo schema que la
sección `channels` del archivo):

```json
{
  "channels": [
    {
      "id": 1,
      "tipo": "rele",
      "pin": 16,
      "mode": "output",
      "label": "rele_1",
      "initial": 0,
      "inverted": true
    },
    {
      "id": 2,
      "tipo": "sensor_apertura",
      "pin": 18,
      "mode": "input_pullup",
      "label": "puerta",
      "initial": 0,
      "inverted": false
    }
  ]
}
```

Restricciones:

- Máximo **8 canales** (`MAX_CHANNELS` en el `.ino`). Si el payload trae
  más, los sobrantes se truncan con warning.
- Tamaño máximo del payload MQTT: **1024 bytes** (`MQTT_BUFFER_SIZE`).
  Suficiente para ~30+ canales con el schema actual.
- El broker **es la autoridad**: una lista vacía borra los canales locales.
  El cloud no debería publicar mensajes vacíos a menos que realmente
  quiera vaciar la config del dispositivo.

Si al cargar el JSON falta un campo o tiene tipo incorrecto, ese campo cae
al default compilado en el `.ino` — un JSON parcial sigue siendo válido y
no rompe el boot.

---

## Panel web de configuración

Una vez que el dispositivo está en la red, expone un panel HTTP simple en
**`http://<ip-del-dispositivo>/`** (puerto 80, sin TLS).

- **Auth.** HTTP Basic Auth contra los usuarios definidos en la sección
  `users` de `/config.json`. Cualquiera de los dos (`admin` o `root`) puede
  entrar con su contraseña.
- **Pantalla principal.** Muestra estado en vivo (UID, versión de firmware,
  MAC, IP, RSSI, estado MQTT, lista de canales con tipo y pin asignado) y
  un formulario para editar las secciones `wifi` y `mqtt` de `/config.json`.
  La sección `users` se modifica por LittleFS upload; la sección
  `channels` la administra el cloud por MQTT y el panel solo la muestra
  (read-only).
- **Guardar.** Al enviar el formulario, el firmware persiste el nuevo
  `/config.json` y **reinicia el dispositivo** para aplicar la configuración
  limpiamente (reconectar a la nueva red y/o broker). El navegador puede
  perder conexión si cambió el SSID — es esperado.
- **Renderizado.** El HTML va embebido en flash (`PROGMEM`) dentro del
  sketch, no se sirve desde LittleFS. Estilo dark + acentos rojos, alineado
  con la identidad visual de `cloud/`.

> **Nota de seguridad.** HTTP Basic Auth transmite las credenciales codificadas
> en base64, **no cifradas**. Cualquiera en la misma red WiFi puede capturarlas.
> El panel está pensado para redes confiables (LAN del operador). No exponer
> el puerto 80 a Internet sin un reverse proxy con TLS adelante.

### Cambiar las contraseñas del panel

Hoy el panel **no incluye UI** para cambiar las contraseñas de `admin`/`root`.
Tres caminos:

1. Editar la sección `users` de `firmware/esp32/data/config.json`
   localmente y subir el archivo con el plugin **arduino-esp32-littlefs-plugin**
   (más sencillo en aprovisionamiento masivo).
2. Modificar las constantes `DEFAULT_ADMIN_PASS` / `DEFAULT_ROOT_PASS` en el
   `.ino`, borrar `/config.json` del dispositivo y dejar que el firmware lo
   regenere con los nuevos defaults en el próximo boot.
3. Editar la sección `users` en runtime desde código propio y llamar a
   `guardarConfig()` para persistirla (todavía no expuesto en el panel;
   queda como extensión futura).

---

## Provisioning de un dispositivo nuevo

Dos caminos posibles para inicializar la config de un equipo:

1. **Editar defaults y flashear.** Modificar las constantes `DEFAULT_*` al
   principio de [`esp32.ino`](esp32.ino), compilar y subir. En el primer
   boot el firmware crea `/config.json` con esos valores y queda persistido.
2. **Subir `data/config.json` por LittleFS.** Crear el archivo en
   `firmware/esp32/data/config.json` con los valores reales del equipo y
   subirlo con el plugin **arduino-esp32-littlefs-plugin** del Arduino IDE.
   Queda en el FS del dispositivo sin tocar el sketch — recomendado cuando
   se aprovisionan varios dispositivos con el mismo binario pero distintas
   credenciales.

---

## Setup del entorno

### Arduino IDE

1. **Arduino IDE 2.x** instalado.
2. Agregar la URL del board manager en *Preferences → Additional boards
   manager URLs*:
   `https://espressif.github.io/arduino-esp32/package_esp32_index.json`
3. *Boards Manager* → instalar **esp32 by Espressif Systems**. **Versión
   3.0 o superior** (el sketch usa `analogWrite()` / `analogWriteResolution()`,
   que aparecieron en la línea 3.x del core).
4. *Tools → Board* → seleccionar la placa real (típicamente *ESP32 Dev
   Module*, *NodeMCU-32S* o la que corresponda).
5. *Tools → Partition Scheme* → elegir un esquema que incluya **partición
   SPIFFS/LittleFS**, p. ej. *Default 4MB with spiffs (1.2MB APP/1.5MB SPIFFS)*
   o cualquier variante con FS. La partición se reutiliza como LittleFS.

### Librerías (Library Manager)

| Librería | Para qué |
|---|---|
| **PubSubClient** (Nick O'Leary) | Cliente MQTT. |
| **ArduinoJson** v7 (Benoit Blanchon) | Parseo/serialización de los JSON. |

`WiFi`, `WebServer` y `LittleFS` ya vienen con el core ESP32, no requieren
instalación adicional.

### Plugin opcional

- **arduino-esp32-littlefs-plugin** — para subir el contenido de `data/`
  al FS del dispositivo sin recompilar el sketch. **Atención**: es un
  plugin distinto al de ESP8266 (`arduino-littlefs-upload`); no se pueden
  usar de forma intercambiable.

---

## Diferencias respecto al sketch ESP8266

El sketch [`esp32.ino`](esp32.ino) es ~99% idéntico al de
[`firmware/esp8266/esp8266.ino`](../esp8266/esp8266.ino). Las diferencias
están confinadas a:

| Tema | ESP8266 | ESP32 |
|---|---|---|
| Header WiFi | `#include <ESP8266WiFi.h>` | `#include <WiFi.h>` |
| Header WebServer | `#include <ESP8266WebServer.h>` | `#include <WebServer.h>` |
| Tipo del server | `ESP8266WebServer server(80)` | `WebServer server(80)` |
| Montaje LittleFS | `LittleFS.begin()` + format manual si falla | `LittleFS.begin(true)` (auto-format) |
| Rango PWM | 0–1023 nativo (`analogWrite`) | Por defecto 0–255 → el sketch llama `analogWriteResolution(pin, 10)` para mantener 0–1023 |
| Pines reservados | GPIO 6–11 (flash) | GPIO 6–11 (flash SPI en WROOM/WROVER) |
| Pines solo-entrada | — | GPIO 34–39 |
| Pines de strapping | GPIO 0, 2, 15, (16 con limitaciones) | GPIO 0, 2, 12, 15 |
| Heap libre típico | ~25–35 KB | ~200+ KB |

El schema de `/config.json`, los topics MQTT, el HTML del panel y el flujo
de boot son **idénticos**, así que un dispositivo ESP8266 y uno ESP32 son
intercambiables desde el punto de vista del cloud.

---

## Integración con `cloud/`

El firmware no habla HTTP directo con el panel `cloud/`. Toda la
comunicación entre dispositivo y plataforma pasa por el **broker MQTT**
(`tcr.reactor.com.ar`). El panel administra los dispositivos a partir del
`uid` (la MAC normalizada), que coincide con el `clientId` MQTT del firmware.

El panel web embebido es **independiente** de `cloud/`: sirve solo para
operación local del equipo (configurar credenciales, ver estado), no expone
funciones de la plataforma.

Los topics de telemetría y comandos todavía no están definidos; cuando se
formalicen, se documentarán acá y en `cloud/DESIGN.md` en simultáneo.
