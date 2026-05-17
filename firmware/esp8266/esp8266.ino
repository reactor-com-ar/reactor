// Reactor — firmware base ESP8266
// Conexión WiFi (modo STA) + cliente MQTT + servidor web de configuración.
// Toda la persistencia vive en LittleFS en un único archivo: /config.json.

#include <ESP8266WiFi.h>
#include <ESP8266WebServer.h>
#include <PubSubClient.h>
#include <LittleFS.h>
#include <ArduinoJson.h>

const char*    FIRMWARE_VERSION = "0.1.0";

const char*    CONFIG_PATH = "/config.json";
const uint16_t WEB_PORT    = 80;
const int      MAX_CHANNELS     = 8;
const uint16_t MQTT_BUFFER_SIZE = 1024;  // payload máx. de un mensaje MQTT

// Defaults usados solo en el primer arranque o si el JSON es inválido.
const char*    DEFAULT_WIFI_SSID     = "TU_SSID";
const char*    DEFAULT_WIFI_PASSWORD = "TU_PASSWORD";
const char*    DEFAULT_MQTT_HOST     = "tcr.reactor.com.ar";
const uint16_t DEFAULT_MQTT_PORT     = 1883;
const char*    DEFAULT_MQTT_USER     = "";
const char*    DEFAULT_MQTT_PASSWORD = "";

const char*    DEFAULT_ADMIN_USER = "admin";
const char*    DEFAULT_ADMIN_PASS = "admin";
const char*    DEFAULT_ROOT_USER  = "root";
const char*    DEFAULT_ROOT_PASS  = "root";

const unsigned long WIFI_TIMEOUT_MS    = 20000;
const unsigned long WIFI_RETRY_WAIT_MS = 5000;
const unsigned long MQTT_RETRY_WAIT_MS = 5000;

struct Config {
  String   wifiSsid;
  String   wifiPassword;
  String   mqttHost;
  uint16_t mqttPort;
  String   mqttUser;
  String   mqttPassword;
};

struct Usuario {
  String username;
  String password;
};

// Un canal del dispositivo: combina la identidad lógica (id, tipo de módulo)
// con el mapeo de hardware (pin, modo, valor inicial, inversión).
// La lista completa se recibe por MQTT desde el cloud (topic
// reactor/<uid>/config) y se persiste en /config.json.
//
// tipo: "rele" | "sensor_apertura" | "sensor_temperatura" (extensible)
// mode: "output" | "input" | "input_pullup" | "pwm" | "unused"
// initial: 0/1 para output, 0-1023 para pwm (analogWrite). Ignorado en inputs.
// inverted: invierte la lógica (útil para relés activos en LOW).
struct Channel {
  int    id;
  String tipo;
  int    pin;
  String mode;
  String label;
  int    initial;
  bool   inverted;
};

Config           config;
Usuario          usuarios[2];
Channel          channels[MAX_CHANNELS];
int              channelCount = 0;
WiFiClient       wifiClient;
PubSubClient     mqtt(wifiClient);
ESP8266WebServer server(WEB_PORT);
String           uid;          // MAC sin ":", también usado como clientId MQTT
String           topicConfig;  // reactor/<uid>/config (server -> device, retained)

// ===== Configuración del dispositivo (/config.json) =====
// Un único archivo concentra todo: uid, versión de firmware, red WiFi,
// broker MQTT, usuarios del panel y lista de canales del dispositivo.

void aplicarDefaultsUsuarios() {
  usuarios[0].username = DEFAULT_ADMIN_USER;
  usuarios[0].password = DEFAULT_ADMIN_PASS;
  usuarios[1].username = DEFAULT_ROOT_USER;
  usuarios[1].password = DEFAULT_ROOT_PASS;
}

void aplicarDefaults() {
  config.wifiSsid     = DEFAULT_WIFI_SSID;
  config.wifiPassword = DEFAULT_WIFI_PASSWORD;
  config.mqttHost     = DEFAULT_MQTT_HOST;
  config.mqttPort     = DEFAULT_MQTT_PORT;
  config.mqttUser     = DEFAULT_MQTT_USER;
  config.mqttPassword = DEFAULT_MQTT_PASSWORD;
  aplicarDefaultsUsuarios();
  channelCount = 0;  // los canales se reciben por MQTT desde el cloud
}

bool guardarConfig() {
  JsonDocument doc;
  doc["uid"]     = uid;
  doc["version"] = FIRMWARE_VERSION;

  doc["wifi"]["ssid"]     = config.wifiSsid;
  doc["wifi"]["password"] = config.wifiPassword;

  doc["mqtt"]["host"]     = config.mqttHost;
  doc["mqtt"]["port"]     = config.mqttPort;
  doc["mqtt"]["user"]     = config.mqttUser;
  doc["mqtt"]["password"] = config.mqttPassword;

  JsonArray users = doc["users"].to<JsonArray>();
  for (auto& u : usuarios) {
    JsonObject o = users.add<JsonObject>();
    o["username"] = u.username;
    o["password"] = u.password;
  }

  JsonArray ch = doc["channels"].to<JsonArray>();
  for (int i = 0; i < channelCount; i++) {
    JsonObject o = ch.add<JsonObject>();
    o["id"]       = channels[i].id;
    o["tipo"]     = channels[i].tipo;
    o["pin"]      = channels[i].pin;
    o["mode"]     = channels[i].mode;
    o["label"]    = channels[i].label;
    o["initial"]  = channels[i].initial;
    o["inverted"] = channels[i].inverted;
  }

  File f = LittleFS.open(CONFIG_PATH, "w");
  if (!f) {
    Serial.println("[config] no se pudo abrir para escritura");
    return false;
  }
  serializeJsonPretty(doc, f);
  f.close();
  Serial.printf("[config] guardado en %s\n", CONFIG_PATH);
  return true;
}

void cargarConfig() {
  if (!LittleFS.begin()) {
    Serial.println("[fs] fallo al montar LittleFS, formateando...");
    LittleFS.format();
    LittleFS.begin();
  }

  if (!LittleFS.exists(CONFIG_PATH)) {
    Serial.println("[config] no existe, creando con defaults");
    aplicarDefaults();
    guardarConfig();
    return;
  }

  File f = LittleFS.open(CONFIG_PATH, "r");
  if (!f) {
    Serial.println("[config] no se pudo abrir, usando defaults");
    aplicarDefaults();
    return;
  }

  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, f);
  f.close();

  if (err) {
    Serial.printf("[config] JSON inválido (%s), regenerando con defaults\n", err.c_str());
    aplicarDefaults();
    guardarConfig();
    return;
  }

  config.wifiSsid     = doc["wifi"]["ssid"]     | DEFAULT_WIFI_SSID;
  config.wifiPassword = doc["wifi"]["password"] | DEFAULT_WIFI_PASSWORD;
  config.mqttHost     = doc["mqtt"]["host"]     | DEFAULT_MQTT_HOST;
  config.mqttPort     = doc["mqtt"]["port"]     | DEFAULT_MQTT_PORT;
  config.mqttUser     = doc["mqtt"]["user"]     | DEFAULT_MQTT_USER;
  config.mqttPassword = doc["mqtt"]["password"] | DEFAULT_MQTT_PASSWORD;

  JsonArray users = doc["users"].as<JsonArray>();
  if (users.size() >= 2) {
    usuarios[0].username = users[0]["username"] | DEFAULT_ADMIN_USER;
    usuarios[0].password = users[0]["password"] | DEFAULT_ADMIN_PASS;
    usuarios[1].username = users[1]["username"] | DEFAULT_ROOT_USER;
    usuarios[1].password = users[1]["password"] | DEFAULT_ROOT_PASS;
  } else {
    Serial.println("[config] sección \"users\" ausente o incompleta, usando defaults");
    aplicarDefaultsUsuarios();
  }

  channelCount = 0;
  JsonArray ch = doc["channels"].as<JsonArray>();
  for (JsonObject o : ch) {
    if (channelCount >= MAX_CHANNELS) {
      Serial.printf("[config] channels truncado a %d\n", MAX_CHANNELS);
      break;
    }
    int id = o["id"] | 0;
    if (id < 1 || id > MAX_CHANNELS) continue;
    Channel& c = channels[channelCount];
    c.id       = id;
    c.tipo     = (const char*)(o["tipo"]  | "");
    c.pin      = o["pin"]      | -1;
    c.mode     = (const char*)(o["mode"]  | "unused");
    c.label    = (const char*)(o["label"] | "");
    c.initial  = o["initial"]  | 0;
    c.inverted = o["inverted"] | false;
    channelCount++;
  }

  Serial.printf("[config] cargado: uid=%s ver=%s ssid=\"%s\" mqtt=%s:%u users=%s,%s ch=%d\n",
                (doc["uid"] | "").as<const char*>(),
                (doc["version"] | "").as<const char*>(),
                config.wifiSsid.c_str(), config.mqttHost.c_str(), config.mqttPort,
                usuarios[0].username.c_str(), usuarios[1].username.c_str(),
                channelCount);

  // Mantener uid y version del JSON siempre sincronizados con la realidad:
  // si el firmware se actualizó o el FS se transfirió a otro chip, reescribir.
  String jsonUid = doc["uid"]     | "";
  String jsonVer = doc["version"] | "";
  if (jsonUid != uid || jsonVer != FIRMWARE_VERSION) {
    Serial.printf("[config] sync uid/version: %s/%s -> %s/%s\n",
                  jsonUid.c_str(), jsonVer.c_str(),
                  uid.c_str(), FIRMWARE_VERSION);
    guardarConfig();
  }
}

// ===== Aplicación de canales al hardware =====

// Recorre channels[] y configura pinMode + valor inicial según el modo
// declarado en cada canal. Se llama al boot y cada vez que llega una nueva
// lista de canales por MQTT.
void aplicarChannels() {
  for (int i = 0; i < channelCount; i++) {
    Channel& c = channels[i];

    if (c.pin < 0) {
      Serial.printf("[channel] #%d sin pin asignado, ignorado\n", c.id);
      continue;
    }

    // GPIO 6-11 están conectados al flash interno: tocarlos cuelga el chip.
    if (c.pin >= 6 && c.pin <= 11) {
      Serial.printf("[channel] #%d pin %d ignorado (reservado para flash)\n",
                    c.id, c.pin);
      continue;
    }

    if (c.mode == "output") {
      pinMode(c.pin, OUTPUT);
      int logical = c.initial ? 1 : 0;
      int physical = c.inverted ? !logical : logical;
      digitalWrite(c.pin, physical ? HIGH : LOW);
    } else if (c.mode == "input") {
      pinMode(c.pin, INPUT);
    } else if (c.mode == "input_pullup") {
      pinMode(c.pin, INPUT_PULLUP);
    } else if (c.mode == "pwm") {
      pinMode(c.pin, OUTPUT);
      int value = constrain(c.initial, 0, 1023);
      if (c.inverted) value = 1023 - value;
      analogWrite(c.pin, value);
    } else if (c.mode == "unused") {
      // no-op
    } else {
      Serial.printf("[channel] #%d pin %d: modo desconocido \"%s\"\n",
                    c.id, c.pin, c.mode.c_str());
      continue;
    }

    Serial.printf("[channel] #%d %s pin=%d mode=%s%s%s\n",
                  c.id, c.tipo.c_str(), c.pin, c.mode.c_str(),
                  c.label.length() ? " :: " : "",
                  c.label.c_str());
  }
}

// ===== MQTT =====

// Tipos de canal reconocidos. Los desconocidos se aceptan igual (loguean
// warning) para que el cloud pueda agregar tipos sin actualizar firmware.
bool tipoCanalConocido(const String& t) {
  return t == "rele" || t == "sensor_apertura" || t == "sensor_temperatura";
}

// Procesa el payload del topic reactor/<uid>/config:
//   {
//     "channels": [
//       { "id": 1, "tipo": "rele", "pin": 5, "mode": "output",
//         "label": "rele_1", "initial": 0, "inverted": true },
//       ...
//     ]
//   }
// Reemplaza la lista local de canales, persiste en /config.json y reaplica
// el hardware (pinMode + valor inicial) inmediatamente.
void procesarConfigCanales(byte* payload, unsigned int length) {
  JsonDocument doc;
  DeserializationError err = deserializeJson(doc, payload, length);
  if (err) {
    Serial.printf("[config] payload de canales inválido: %s\n", err.c_str());
    return;
  }

  JsonArray arr = doc["channels"].as<JsonArray>();
  if (arr.isNull()) {
    Serial.println("[config] payload sin sección \"channels\"");
    return;
  }

  int nuevos = 0;
  for (JsonObject o : arr) {
    if (nuevos >= MAX_CHANNELS) {
      Serial.printf("[config] canales truncados a %d (máximo soportado)\n", MAX_CHANNELS);
      break;
    }
    int id = o["id"] | 0;
    String tipo = (const char*)(o["tipo"] | "");
    if (id < 1 || id > MAX_CHANNELS) {
      Serial.printf("[config] canal id=%d fuera de rango 1..%d, ignorado\n",
                    id, MAX_CHANNELS);
      continue;
    }
    if (!tipoCanalConocido(tipo)) {
      Serial.printf("[config] canal id=%d tipo \"%s\" desconocido (aceptado igual)\n",
                    id, tipo.c_str());
    }
    Channel& c = channels[nuevos];
    c.id       = id;
    c.tipo     = tipo;
    c.pin      = o["pin"]      | -1;
    c.mode     = (const char*)(o["mode"]  | "unused");
    c.label    = (const char*)(o["label"] | "");
    c.initial  = o["initial"]  | 0;
    c.inverted = o["inverted"] | false;
    nuevos++;
  }
  channelCount = nuevos;

  Serial.printf("[config] canales actualizados desde MQTT: %d\n", channelCount);

  guardarConfig();
  aplicarChannels();
}

void onMqttMessage(char* topic, byte* payload, unsigned int length) {
  Serial.printf("[mqtt] msg en %s (%u bytes)\n", topic, length);

  if (String(topic) == topicConfig) {
    procesarConfigCanales(payload, length);
    return;
  }

  // Topic no manejado: dump por serial para debug.
  Serial.print("  payload: ");
  for (unsigned int i = 0; i < length; i++) Serial.print((char) payload[i]);
  Serial.println();
}

bool conectarMqtt() {
  Serial.printf("[mqtt] conectando a %s:%u como \"%s\"... ",
                config.mqttHost.c_str(), config.mqttPort, uid.c_str());

  bool ok;
  if (config.mqttUser.length() > 0) {
    ok = mqtt.connect(uid.c_str(),
                      config.mqttUser.c_str(),
                      config.mqttPassword.c_str());
  } else {
    ok = mqtt.connect(uid.c_str());
  }

  if (ok) {
    Serial.println("ok");
    if (mqtt.subscribe(topicConfig.c_str(), 1)) {
      Serial.printf("[mqtt] suscrito a %s (QoS 1)\n", topicConfig.c_str());
    } else {
      Serial.printf("[mqtt] fallo al suscribir a %s\n", topicConfig.c_str());
    }
    return true;
  }

  Serial.printf("fallo (rc=%d), reintento en %lus\n",
                mqtt.state(), MQTT_RETRY_WAIT_MS / 1000);
  return false;
}

// ===== WiFi =====

void conectarWifi() {
  Serial.printf("[wifi] conectando a \"%s\"", config.wifiSsid.c_str());

  WiFi.mode(WIFI_STA);
  WiFi.persistent(false);
  WiFi.setAutoReconnect(true);
  WiFi.begin(config.wifiSsid.c_str(), config.wifiPassword.c_str());

  unsigned long inicio = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - inicio < WIFI_TIMEOUT_MS) {
    delay(500);
    Serial.print('.');
  }
  Serial.println();

  if (WiFi.status() == WL_CONNECTED) {
    Serial.printf("[wifi] conectado | IP: %s | RSSI: %d dBm | MAC: %s\n",
                  WiFi.localIP().toString().c_str(),
                  WiFi.RSSI(),
                  WiFi.macAddress().c_str());
  } else {
    Serial.printf("[wifi] fallo (status=%d), reintento en %lus\n",
                  WiFi.status(), WIFI_RETRY_WAIT_MS / 1000);
  }
}

// ===== Servidor web =====

const char HTML_PAGE[] PROGMEM = R"HTML(<!DOCTYPE html>
<html lang="es"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Reactor — Configuración</title>
<style>
:root{--bg:#1a1a1a;--surface:#242526;--border:#383838;--primary:#C11313;--primary-h:#8e0e0e;--text:#f0f0f0;--muted:#9ca0a4;--radius:10px;}
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;padding:24px;}
.container{max-width:480px;margin:0 auto;}
h1{color:var(--primary);margin-bottom:4px;font-size:22px;}
.subtitle{color:var(--muted);margin-bottom:20px;font-size:13px;}
.card{background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:20px;margin-bottom:14px;}
.card h2{font-size:15px;margin-bottom:14px;color:var(--text);}
.field{margin-bottom:10px;}
.field label{display:block;color:var(--muted);font-size:12px;margin-bottom:4px;text-transform:uppercase;letter-spacing:.4px;}
.field input{width:100%;background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);padding:10px 12px;color:var(--text);font-size:14px;font-family:inherit;}
.field input:focus{outline:none;border-color:var(--primary);}
.note{color:var(--muted);font-size:11px;margin-top:4px;}
button{width:100%;background:var(--primary);color:#fff;border:none;border-radius:var(--radius);padding:12px;font-size:14px;font-weight:600;cursor:pointer;}
button:hover{background:var(--primary-h);}
.ok{background:rgba(34,197,94,.12);border:1px solid #22c55e;color:#22c55e;padding:10px 12px;border-radius:var(--radius);margin-bottom:14px;font-size:13px;}
.meta{color:var(--muted);font-size:12px;margin-bottom:4px;}
.meta strong{color:var(--text);font-weight:600;}
</style></head><body><div class="container">
<h1>Reactor</h1><div class="subtitle">Configuración del dispositivo</div>
%SAVED%
<div class="card"><h2>Estado</h2>
<div class="meta">UID: <strong>%UID%</strong></div>
<div class="meta">Firmware: <strong>%VERSION%</strong></div>
<div class="meta">MAC: <strong>%MAC%</strong></div>
<div class="meta">IP: <strong>%IP%</strong></div>
<div class="meta">RSSI: <strong>%RSSI% dBm</strong></div>
<div class="meta">MQTT: <strong>%MQTTSTATE%</strong></div>
<div class="meta">Canales: <strong>%CHANNELS%</strong></div>
</div>
<form method="POST" action="/save">
<div class="card"><h2>WiFi</h2>
<div class="field"><label>SSID</label><input type="text" name="wifi_ssid" value="%WIFI_SSID%" required></div>
<div class="field"><label>Contraseña</label><input type="password" name="wifi_password" value="%WIFI_PASS%"></div>
</div>
<div class="card"><h2>MQTT</h2>
<div class="field"><label>Host</label><input type="text" name="mqtt_host" value="%MQTT_HOST%" required></div>
<div class="field"><label>Puerto</label><input type="number" name="mqtt_port" value="%MQTT_PORT%" min="1" max="65535" required></div>
<div class="field"><label>Usuario</label><input type="text" name="mqtt_user" value="%MQTT_USER%"><div class="note">Vacío = conexión anónima al broker.</div></div>
<div class="field"><label>Contraseña</label><input type="password" name="mqtt_password" value="%MQTT_PASS%"></div>
</div>
<button type="submit">Guardar y reiniciar</button>
</form></div></body></html>)HTML";

String resumenCanales() {
  if (channelCount == 0) return "0 (esperando config del broker)";
  String s = String(channelCount) + " — ";
  for (int i = 0; i < channelCount; i++) {
    if (i > 0) s += ", ";
    s += "#" + String(channels[i].id) + " " + channels[i].tipo
       + " (pin " + String(channels[i].pin) + ")";
  }
  return s;
}

String htmlAttr(const String& s) {
  String r;
  r.reserve(s.length() + 8);
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    switch (c) {
      case '&':  r += "&amp;";  break;
      case '<':  r += "&lt;";   break;
      case '>':  r += "&gt;";   break;
      case '"':  r += "&quot;"; break;
      case '\'': r += "&#39;";  break;
      default:   r += c;
    }
  }
  return r;
}

bool verificarAuth() {
  for (auto& u : usuarios) {
    if (server.authenticate(u.username.c_str(), u.password.c_str())) {
      return true;
    }
  }
  server.requestAuthentication(BASIC_AUTH, "Reactor", "Credenciales requeridas");
  return false;
}

void handleRoot() {
  if (!verificarAuth()) return;

  String page = FPSTR(HTML_PAGE);
  page.replace("%SAVED%",
    server.arg("saved") == "1"
      ? "<div class='ok'>Configuración guardada. El dispositivo está reiniciando…</div>"
      : "");
  page.replace("%UID%",       uid);
  page.replace("%VERSION%",   FIRMWARE_VERSION);
  page.replace("%MAC%",       WiFi.macAddress());
  page.replace("%IP%",        WiFi.localIP().toString());
  page.replace("%RSSI%",      String(WiFi.RSSI()));
  page.replace("%MQTTSTATE%", mqtt.connected() ? "conectado" : "desconectado");
  page.replace("%CHANNELS%",  htmlAttr(resumenCanales()));
  page.replace("%WIFI_SSID%", htmlAttr(config.wifiSsid));
  page.replace("%WIFI_PASS%", htmlAttr(config.wifiPassword));
  page.replace("%MQTT_HOST%", htmlAttr(config.mqttHost));
  page.replace("%MQTT_PORT%", String(config.mqttPort));
  page.replace("%MQTT_USER%", htmlAttr(config.mqttUser));
  page.replace("%MQTT_PASS%", htmlAttr(config.mqttPassword));

  server.send(200, "text/html; charset=utf-8", page);
}

void handleSave() {
  if (!verificarAuth()) return;

  config.wifiSsid     = server.arg("wifi_ssid");
  config.wifiPassword = server.arg("wifi_password");
  config.mqttHost     = server.arg("mqtt_host");
  config.mqttPort     = (uint16_t) server.arg("mqtt_port").toInt();
  config.mqttUser     = server.arg("mqtt_user");
  config.mqttPassword = server.arg("mqtt_password");

  guardarConfig();

  server.sendHeader("Location", "/?saved=1");
  server.send(303, "text/plain", "");

  // Reiniciar para aplicar la nueva configuración limpiamente.
  delay(500);
  ESP.restart();
}

void setupServidorWeb() {
  server.on("/", HTTP_GET, handleRoot);
  server.on("/save", HTTP_POST, handleSave);
  server.onNotFound([]() { server.send(404, "text/plain", "Not Found"); });
  server.begin();
  Serial.printf("[web] servidor en http://%s/ (puerto %u)\n",
                WiFi.localIP().toString().c_str(), WEB_PORT);
}

// ===== setup / loop =====

void setup() {
  Serial.begin(115200);
  delay(100);
  Serial.println();
  Serial.printf("=== Reactor ESP8266 v%s ===\n", FIRMWARE_VERSION);

  // La MAC está disponible sin necesidad de WiFi.begin(); se lee del eFuse.
  // Calculamos uid acá para que cargarConfig pueda persistirlo / sincronizarlo.
  uid = WiFi.macAddress();
  uid.replace(":", "");
  topicConfig = "reactor/" + uid + "/config";
  Serial.printf("[uid] %s\n", uid.c_str());

  cargarConfig();
  aplicarChannels();
  conectarWifi();

  mqtt.setBufferSize(MQTT_BUFFER_SIZE);
  mqtt.setServer(config.mqttHost.c_str(), config.mqttPort);
  mqtt.setCallback(onMqttMessage);

  setupServidorWeb();
}

void loop() {
  server.handleClient();

  if (WiFi.status() != WL_CONNECTED) {
    delay(WIFI_RETRY_WAIT_MS);
    conectarWifi();
    return;
  }

  if (!mqtt.connected()) {
    if (!conectarMqtt()) {
      delay(MQTT_RETRY_WAIT_MS);
      return;
    }
  }

  mqtt.loop();

  // TODO: lógica de aplicación (lectura de sensores, publish, etc.)
  delay(10);
}
