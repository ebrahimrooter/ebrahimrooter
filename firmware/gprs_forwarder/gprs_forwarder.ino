/*
 * Bank SMS -> your accounting program on a host, over the SIM card's own
 * internet (SIM800 GPRS). Fully automatic: no computer, no Wi-Fi, no taps.
 * The program on the host asks "what was it for?" in Bale and records it.
 *
 * - Each SMS (from ALLOWED_SENDER) is POSTed to SERVER_URL?r=ingest and
 *   deleted from the SIM only after the host accepted it, so nothing is
 *   lost while there is no signal / credit / the host is down.
 * - Every 10 minutes a heartbeat (signal, SIM queue) goes to ?r=heartbeat:
 *   the host warns you in Bale if the device goes quiet, and runs its
 *   daily reminder / weekly report.
 *
 * Settings: install.php on the host prints SERVER_URL and DEVICE_TOKEN.
 * Libraries (Tools > Manage Libraries): "TinyGSM" (Volodymyr Shymanskyy)
 * and "ESP_SSLClient" (Mobizt). Board: "ESP32 Dev Module". Serial: 115200.
 * Wiring: same as sim800_test (README): TXD->16, RXD->17 (1k/2k divider),
 * RST->4, GND common, VCC 4.0 V / 2 A.
 */

/* ----------------------------- settings ----------------------------- */

const char *SERVER_URL   = "https://example.com/bank/api.php";   // from install.php
const char *DEVICE_TOKEN = "from-install.php";

// Only SMS from this sender go to the host; others are deleted from the SIM.
// "" = send everything (the host can still filter in Settings > SMS senders).
const char *ALLOWED_SENDER = "Bank Mellat";

// Internet of the SIM card (APN): Hamrah-e Aval "mcinet", Irancell "mtnirancell", Rightel "rightel"
const char *APN       = "mcinet";
const char *APN_USER  = "";
const char *APN_PASS  = "";

const int MODEM_RX_PIN  = 16;   // ESP32 receives here  <- SIM800 TXD
const int MODEM_TX_PIN  = 17;   // ESP32 sends here     -> SIM800 RXD
const int MODEM_RST_PIN = 4;    // -> SIM800 RST; -1 if not wired
const int LED_PIN       = 2;    // on-board LED; -1 to disable

/* -------------------------------------------------------------------- */

#define TINY_GSM_MODEM_SIM800
#define TINY_GSM_RX_BUFFER 1024
#include <TinyGsmClient.h>

#define SSLCLIENT_INSECURE_ONLY   // encrypted, but the server certificate is not checked
#include <ESP_SSLClient.h>

const int MAX_SMS_SLOTS = 40;
const unsigned long CHECK_EVERY_MS = 10000;
const unsigned long HEARTBEAT_EVERY_MS = 600000;
const char *FW_VERSION = "gprs-1.0";

HardwareSerial SerialAT(2);
TinyGsm gsm(SerialAT);
TinyGsmClient tcp(gsm);
ESP_SSLClient net;               // TLS for https://, plain TCP for http://

long modemBaud = 0;
unsigned long lastCheck = 0;
unsigned long lastHeartbeat = 0;
bool heartbeatSent = false;
int netFailures = 0;

// SERVER_URL split up once in setup()
String srvHost, srvPath;
int srvPort = 443;
bool srvHttps = true;

/* ------------------------------ helpers ------------------------------ */

void led(bool on) {
  if (LED_PIN >= 0) digitalWrite(LED_PIN, on ? HIGH : LOW);
}

// Raw AT command (used only while no internet socket is open).
String atWait(const String &cmd, unsigned long timeoutMs) {
  while (SerialAT.available()) SerialAT.read();
  SerialAT.print(cmd);
  SerialAT.print("\r");
  String reply;
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    while (SerialAT.available()) reply += (char)SerialAT.read();
    if (reply.endsWith("OK\r\n") || reply.indexOf("ERROR") >= 0) break;
    delay(5);
  }
  return reply;
}

String at(const String &cmd) {
  return atWait(cmd, 3000);
}

int numberAfter(const String &reply, const char *prefix, int skipCommas) {
  int p = reply.indexOf(prefix);
  if (p < 0) return -1;
  p += strlen(prefix);
  for (int i = 0; i < skipCommas; i++) {
    p = reply.indexOf(',', p);
    if (p < 0) return -1;
    p++;
  }
  while (p < (int)reply.length() && !isDigit(reply[p])) p++;
  return reply.substring(p).toInt();
}

String quoted(const String &line, int n) {
  int pos = 0;
  for (int i = 0; i <= n; i++) {
    int a = line.indexOf('"', pos);
    if (a < 0) return "";
    int b = line.indexOf('"', a + 1);
    if (b < 0) return "";
    if (i == n) return line.substring(a + 1, b);
    pos = b + 1;
  }
  return "";
}

int hexVal(char c) {
  if (c >= '0' && c <= '9') return c - '0';
  if (c >= 'A' && c <= 'F') return c - 'A' + 10;
  if (c >= 'a' && c <= 'f') return c - 'a' + 10;
  return -1;
}

// UCS2 hex from the modem -> UTF-8.
String ucs2ToUtf8(const String &hex) {
  if (hex.length() == 0 || hex.length() % 4 != 0) return hex;
  for (size_t i = 0; i < hex.length(); i++) if (hexVal(hex[i]) < 0) return hex;
  String out;
  for (size_t i = 0; i + 3 < hex.length(); i += 4) {
    uint32_t u = (hexVal(hex[i]) << 12) | (hexVal(hex[i + 1]) << 8) | (hexVal(hex[i + 2]) << 4) | hexVal(hex[i + 3]);
    if (u >= 0xD800 && u <= 0xDBFF && i + 7 < hex.length()) {
      uint32_t lo = (hexVal(hex[i + 4]) << 12) | (hexVal(hex[i + 5]) << 8) | (hexVal(hex[i + 6]) << 4) | hexVal(hex[i + 7]);
      u = 0x10000 + ((u - 0xD800) << 10) + (lo - 0xDC00);
      i += 4;
    }
    if (u < 0x80) {
      out += (char)u;
    } else if (u < 0x800) {
      out += (char)(0xC0 | (u >> 6)); out += (char)(0x80 | (u & 0x3F));
    } else if (u < 0x10000) {
      out += (char)(0xE0 | (u >> 12)); out += (char)(0x80 | ((u >> 6) & 0x3F)); out += (char)(0x80 | (u & 0x3F));
    } else {
      out += (char)(0xF0 | (u >> 18)); out += (char)(0x80 | ((u >> 12) & 0x3F));
      out += (char)(0x80 | ((u >> 6) & 0x3F)); out += (char)(0x80 | (u & 0x3F));
    }
  }
  return out;
}

String normalizeSender(const String &s) {
  String out;
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if (c == ' ' || c == '-' || c == '_') continue;
    out += (c >= 'A' && c <= 'Z') ? (char)(c - 'A' + 'a') : c;
  }
  return out;
}

String urlEncode(const String &s) {
  String out;
  const char *hex = "0123456789ABCDEF";
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if (isalnum((unsigned char)c) || c == '-' || c == '_' || c == '.') out += c;
    else { out += '%'; out += hex[(c >> 4) & 15]; out += hex[c & 15]; }
  }
  return out;
}

bool parseServerUrl() {
  String u = SERVER_URL;
  srvHttps = u.startsWith("https://");
  if (!srvHttps && !u.startsWith("http://")) return false;
  u = u.substring(srvHttps ? 8 : 7);
  int slash = u.indexOf('/');
  String hostPort = slash < 0 ? u : u.substring(0, slash);
  srvPath = slash < 0 ? "/api.php" : u.substring(slash);
  int colon = hostPort.indexOf(':');
  srvHost = colon < 0 ? hostPort : hostPort.substring(0, colon);
  srvPort = colon < 0 ? (srvHttps ? 443 : 80) : hostPort.substring(colon + 1).toInt();
  return srvHost.length() > 0;
}

/* ------------------------------- modem ------------------------------- */

void modemHardwareReset() {
  if (MODEM_RST_PIN < 0) return;
  Serial.println("resetting modem (RST pin)");
  digitalWrite(MODEM_RST_PIN, LOW);
  delay(200);
  digitalWrite(MODEM_RST_PIN, HIGH);
  delay(5000);
}

long findModem() {
  const long rates[] = {9600, 115200, 57600, 38400, 19200};
  for (long r : rates) {
    SerialAT.begin(r, SERIAL_8N1, MODEM_RX_PIN, MODEM_TX_PIN);
    delay(100);
    for (int i = 0; i < 4; i++) {
      if (atWait("AT", 500).indexOf("OK") >= 0) return r;
    }
    SerialAT.end();
  }
  return 0;
}

// Internet commands carry text parameters (APN, host) that the modem only
// understands in the GSM character set. If the modem is in UCS2, even this
// command must be written in UCS2 ("GSM" = 0047 0053 004D).
void charsetGsm() {
  if (at("AT+CSCS=\"GSM\"").indexOf("OK") < 0) at("AT+CSCS=\"00470053004D\"");
}

bool modemInit() {
  modemBaud = findModem();
  if (!modemBaud) {
    Serial.println("modem not answering (power? TX/RX crossed? run sim800_test)");
    return false;
  }
  charsetGsm();
  gsm.init();                                      // TinyGSM: echo off, error codes, SIM check
  charsetGsm();
  at("AT+CMGF=1");                                 // SMS text mode
  at("AT+CPMS=\"SM\",\"SM\",\"SM\"");              // SMS stored on the SIM
  at("AT+CNMI=2,1,0,0,0");
  Serial.printf("modem ready at %ld baud\n", modemBaud);
  return true;
}

/* ------------------------------- host -------------------------------- */

bool internetUp() {
  if (gsm.isGprsConnected()) return true;
  Serial.print("waiting for network...");
  if (!gsm.waitForNetwork(60000L)) {
    Serial.println(" no network (antenna? 2G coverage? SIM active?)");
    return false;
  }
  Serial.printf(" ok, signal %d/31\n", gsm.getSignalQuality());
  Serial.printf("connecting to internet (APN %s)...", APN);
  if (!gsm.gprsConnect(APN, APN_USER, APN_PASS)) {
    Serial.println(" failed (wrong APN? no internet package / credit?)");
    return false;
  }
  Serial.println(" ok");
  return true;
}

// POSTs a form to api.php?r=<route>. Returns HTTP status (-1 = no connection) and the body.
int hostPost(const char *route, const String &form, String &body) {
  body = "";
  if (!internetUp()) return -1;
  led(true);
  int status = -1;
  if (net.connect(srvHost.c_str(), srvPort)) {
    String req = "POST " + srvPath + "?r=" + route + " HTTP/1.1\r\n";
    req += "Host: " + srvHost + "\r\n";
    req += "User-Agent: bank-sms-esp32/" + String(FW_VERSION) + "\r\n";
    req += "X-Device-Token: " + String(DEVICE_TOKEN) + "\r\n";
    req += "Content-Type: application/x-www-form-urlencoded\r\n";
    req += "Connection: close\r\n";
    req += "Content-Length: " + String(form.length()) + "\r\n\r\n";
    net.print(req);
    net.print(form);
    String resp;
    unsigned long start = millis();
    while (millis() - start < 30000) {
      while (net.available()) resp += (char)net.read();
      if (!net.connected() && !net.available()) break;
      if (resp.length() > 4000) break;
      delay(10);
    }
    if (resp.startsWith("HTTP/1.")) status = resp.substring(9, 12).toInt();
    int b = resp.indexOf("\r\n\r\n");
    body = b >= 0 ? resp.substring(b + 4) : resp;
  } else {
    Serial.println("  could not connect to " + srvHost + (srvHttps ? " (https)" : " (http)"));
  }
  net.stop();
  led(false);
  if (status < 0) netFailures++; else netFailures = 0;
  return status;
}

void explain(int code) {
  if (code == 401) Serial.println("  DEVICE_TOKEN does not match the host (copy it again from install.php)");
  else if (code == 404) Serial.println("  wrong SERVER_URL path (it must end in /api.php)");
  else if (code == 301 || code == 302) Serial.println("  the host redirects: use https:// in SERVER_URL");
  else if (code == 403 || code == 503) Serial.println("  the host blocked the request (firewall / bot protection): ask hosting support to allow it");
}

// Returns true when the host accepted the SMS (then it may be deleted).
bool forward(const String &senderHex, const String &textHex, const String &when) {
  String body;
  int code = hostPost("ingest", "sender=" + urlEncode(senderHex) + "&text_hex=" + urlEncode(textHex) + "&modem_time=" + urlEncode(when), body);
  bool ok = code == 200 && body.indexOf("\"ok\":true") >= 0;
  Serial.printf("  host -> %d %s\n", code, ok ? "ok" : body.substring(0, 160).c_str());
  explain(code);
  return ok;
}

void heartbeat() {
  lastHeartbeat = millis();
  int csq = gsm.getSignalQuality();
  int creg = numberAfter(at("AT+CREG?"), "+CREG:", 1);
  int used = numberAfter(at("AT+CPMS?"), "+CPMS:", 1);
  String body;
  int code = hostPost("heartbeat", "csq=" + String(csq) + "&creg=" + String(creg) + "&used=" + String(used) +
                      "&uptime=" + String(millis() / 60000) + "&fw=" + FW_VERSION, body);
  heartbeatSent = code == 200;
  Serial.printf("heartbeat -> %d (signal %d/31, network %d, on SIM %d)\n", code, csq, creg, used);
  explain(code);
}

/* ------------------------------ SMS work ----------------------------- */

// Reads one slot: sender and text stay in UCS2 hex (the host decodes them).
// UCS2 only for the duration of the read, then back to GSM so the internet
// commands keep working.
bool readSms(int idx, String &senderHex, String &textHex, String &when) {
  at("AT+CSCS=\"UCS2\"");
  String r = atWait("AT+CMGR=" + String(idx), 5000);
  charsetGsm();
  int h = r.indexOf("+CMGR:");
  if (h < 0) return false;
  int eol = r.indexOf('\n', h);
  String header = r.substring(h, eol);
  int okPos = r.lastIndexOf("\r\nOK");
  textHex = r.substring(eol + 1, okPos > eol ? okPos : r.length());
  textHex.trim();
  senderHex = quoted(header, 1);
  when = quoted(header, 3);
  if (when.length() == 0) when = quoted(header, 2);
  return true;
}

void checkInbox() {
  lastCheck = millis();
  int used = numberAfter(at("AT+CPMS?"), "+CPMS:", 1);
  if (used < 0) {
    Serial.println("modem not answering");
    modemHardwareReset();
    if (!modemInit()) ESP.restart();
    return;
  }
  if (used == 0) return;
  String allowed = normalizeSender(ALLOWED_SENDER);
  int handled = 0;
  for (int idx = 1; idx <= MAX_SMS_SLOTS && handled < used; idx++) {
    String senderHex, textHex, when;
    if (!readSms(idx, senderHex, textHex, when)) continue;
    handled++;
    String sender = ucs2ToUtf8(senderHex);
    Serial.printf("\nSMS #%d from %s at %s\n%s\n", idx, sender.c_str(), when.c_str(), ucs2ToUtf8(textHex).c_str());
    if (allowed.length() && normalizeSender(sender) != allowed) {
      Serial.println("  not from " + String(ALLOWED_SENDER) + " -> deleted");
      at("AT+CMGD=" + String(idx));
      continue;
    }
    if (textHex.length() == 0 || forward(senderHex, textHex, when)) {
      at("AT+CMGD=" + String(idx));
    } else {
      Serial.println("  kept on the SIM, will retry");
      break;
    }
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.printf("\n===== bank SMS -> host (%s) =====\n", FW_VERSION);
  if (!parseServerUrl()) {
    Serial.println("SERVER_URL must start with https:// or http:// (copy it from install.php)");
  }
  if (LED_PIN >= 0) pinMode(LED_PIN, OUTPUT);
  if (MODEM_RST_PIN >= 0) {
    pinMode(MODEM_RST_PIN, OUTPUT);
    digitalWrite(MODEM_RST_PIN, HIGH);
  }
  delay(3000);
  if (!modemInit()) {
    modemHardwareReset();
    if (!modemInit()) {
      delay(10000);
      ESP.restart();
    }
  }
  net.setInsecure();
  net.setBufferSizes(16384, 2048);
  net.setClient(&tcp, srvHttps);
  checkInbox();
  heartbeat();
}

void loop() {
  if (millis() - lastCheck > CHECK_EVERY_MS) checkInbox();
  // retry a failed heartbeat after a minute, otherwise every 10 minutes
  if (millis() - lastHeartbeat > (heartbeatSent ? HEARTBEAT_EVERY_MS : 60000UL)) heartbeat();
  // Network trouble for a while: reset the modem and start over.
  if (netFailures >= 30) {
    netFailures = 0;
    gsm.gprsDisconnect();
    modemHardwareReset();
    modemInit();
  }
  delay(50);
}
