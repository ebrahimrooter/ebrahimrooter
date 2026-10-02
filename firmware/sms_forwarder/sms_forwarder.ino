/*
 * Bank SMS forwarder - ESP32 + SIM800L (also works with SIM800C/SIM900/A7670).
 * Step 2 of the bank SMS project: run sim800_test first until it shows
 * your SMS correctly, then upload this one.
 *
 * Every SMS that lands on the SIM is POSTed to the server
 * (api.php?r=ingest). It is deleted from the SIM only after the server
 * answered OK, so while Wi-Fi or the computer is off, messages simply wait
 * on the SIM and are sent later. Every 10 minutes it also reports signal /
 * SIM queue to api.php?r=heartbeat, so the server can warn you in Bale
 * when the device goes quiet.
 *
 * Text is read in UCS2 mode (Persian SMS are UCS2) and sent as hex; the
 * server decodes it. Serial Monitor (115200) shows it in Persian too.
 *
 * Wiring (ESP32 DevKit + SIM800L) - full picture in the README:
 *   SIM800L TXD -> GPIO16      SIM800L RXD -> GPIO17 (via 1k/2k divider)
 *   SIM800L GND -> GND (shared by ESP32 and the modem supply)
 *   SIM800L VCC -> 3.7-4.2 V supply that can give 2 A (NOT the ESP32 pins)
 *   SIM800L RST -> GPIO4 (optional, lets the ESP32 reset a hung modem)
 *
 * Board: "ESP32 Dev Module" (esp32 core 2.x or 3.x). No extra libraries.
 */

#include <WiFi.h>
#include <WiFiClientSecure.h>
#include <HTTPClient.h>

/* ----------------------------- settings ----------------------------- */
/* Only this block needs changing. start-windows.bat prints the last three. */

const char *WIFI_SSID     = "YOUR-WIFI";
const char *WIFI_PASS     = "YOUR-WIFI-PASSWORD";
const char *SERVER_URL    = "http://192.168.1.20:8080/api.php?r=ingest";
const char *HEARTBEAT_URL = "http://192.168.1.20:8080/api.php?r=heartbeat";
const char *DEVICE_TOKEN  = "same-as-device_token-in-config.php";

const int MODEM_RX_PIN  = 16;   // ESP32 receives here  <- SIM800 TXD
const int MODEM_TX_PIN  = 17;   // ESP32 sends here     -> SIM800 RXD
const int MODEM_RST_PIN = 4;    // -> SIM800 RST; set to -1 if not wired
const int LED_PIN       = 2;    // on-board LED of most DevKits; -1 to disable

/* -------------------------------------------------------------------- */

const int MAX_SMS_SLOTS = 40;                     // SIM storage slots scanned
const unsigned long SCAN_EVERY_MS = 15000;        // safety net besides "+CMTI"
const unsigned long HEARTBEAT_EVERY_MS = 600000;  // 10 minutes
const char *FW_VERSION = "1.2";

HardwareSerial modem(2);
long modemBaud = 0;
unsigned long lastScan = 0;
unsigned long lastHeartbeat = 0;
bool heartbeatSent = false;
int modemFailures = 0;

/* ------------------------------ helpers ------------------------------ */

void led(bool on) {
  if (LED_PIN >= 0) digitalWrite(LED_PIN, on ? HIGH : LOW);
}

// Sends an AT command and collects the reply until OK / ERROR / timeout.
String atWait(const String &cmd, unsigned long timeoutMs) {
  while (modem.available()) modem.read();
  modem.print(cmd);
  modem.print("\r");
  String reply;
  unsigned long start = millis();
  while (millis() - start < timeoutMs) {
    while (modem.available()) reply += (char)modem.read();
    if (reply.endsWith("OK\r\n") || reply.indexOf("ERROR") >= 0) break;
    delay(5);
  }
  return reply;
}

String at(const String &cmd) {
  return atWait(cmd, 3000);
}

// Number after "<prefix>", skipping some commas: ("+CSQ: 17,0", "+CSQ:", 0) -> 17.
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

// The n-th "quoted" field of a +CMGR header line.
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

int hexVal(char c) {
  if (c >= '0' && c <= '9') return c - '0';
  if (c >= 'A' && c <= 'F') return c - 'A' + 10;
  if (c >= 'a' && c <= 'f') return c - 'a' + 10;
  return -1;
}

// UCS2 hex from the modem -> UTF-8, only for showing it in Serial Monitor.
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

/* ------------------------------- modem ------------------------------- */

void modemHardwareReset() {
  if (MODEM_RST_PIN < 0) return;
  Serial.println("resetting modem (RST pin)");
  digitalWrite(MODEM_RST_PIN, LOW);
  delay(200);
  digitalWrite(MODEM_RST_PIN, HIGH);
  delay(5000);
}

// SIM800 auto-detects the baud rate from the first "AT"; try the usual ones.
long findModem() {
  const long rates[] = {9600, 115200, 57600, 38400, 19200};
  for (long r : rates) {
    modem.begin(r, SERIAL_8N1, MODEM_RX_PIN, MODEM_TX_PIN);
    delay(100);
    for (int i = 0; i < 4; i++) {
      if (atWait("AT", 500).indexOf("OK") >= 0) return r;
    }
    modem.end();
  }
  return 0;
}

bool modemInit() {
  modemBaud = findModem();
  if (!modemBaud) {
    Serial.println("modem not answering (power? TX/RX crossed? run sim800_test)");
    return false;
  }
  at("ATE0");                                       // no echo
  // Leave UCS2 first in case the modem is still in it from before: in UCS2
  // mode even this command must be written in UCS2 ("GSM" = 0047 0053 004D).
  if (at("AT+CSCS=\"GSM\"").indexOf("OK") < 0) at("AT+CSCS=\"00470053004D\"");
  at("AT+CMGF=1");                                  // SMS text mode
  at("AT+CPMS=\"SM\",\"SM\",\"SM\"");               // store on the SIM
  at("AT+CNMI=2,1,0,0,0");                          // "+CMTI" when an SMS is stored
  at("AT+CSDH=0");
  at("AT+CSCS=\"UCS2\"");                           // last: Persian-safe text
  Serial.printf("modem ready at %ld baud\n", modemBaud);
  modemFailures = 0;
  return true;
}

/* -------------------------------- wifi ------------------------------- */

void wifiEnsure() {
  if (WiFi.status() == WL_CONNECTED) return;
  WiFi.mode(WIFI_STA);
  WiFi.begin(WIFI_SSID, WIFI_PASS);
  unsigned long start = millis();
  while (WiFi.status() != WL_CONNECTED && millis() - start < 15000) delay(250);
  if (WiFi.status() == WL_CONNECTED) Serial.println("wifi ok, ESP32 IP " + WiFi.localIP().toString());
  else Serial.println("wifi failed (name/password? 2.4 GHz network? ESP32 has no 5 GHz)");
}

// POSTs a form body to url with the device token. Returns HTTP code, fills resp.
int postForm(const char *url, const String &body, String &resp) {
  wifiEnsure();
  if (WiFi.status() != WL_CONNECTED) return -1;
  HTTPClient http;
  WiFiClient plain;                 // http://  - server on your own computer
  WiFiClientSecure tls;             // https:// - server on a host
  bool ok;
  if (strncmp(url, "https://", 8) == 0) {
    tls.setInsecure();              // for a pinned certificate use tls.setCACert(...)
    ok = http.begin(tls, url);
  } else {
    ok = http.begin(plain, url);
  }
  if (!ok) return -1;
  http.addHeader("Content-Type", "application/x-www-form-urlencoded");
  http.addHeader("X-Device-Token", DEVICE_TOKEN);
  http.setTimeout(15000);
  int code = http.POST(body);
  resp = code > 0 ? http.getString() : "";
  http.end();
  return code;
}

/* ------------------------------- work -------------------------------- */

void heartbeat() {
  lastHeartbeat = millis();
  int csq = numberAfter(at("AT+CSQ"), "+CSQ:", 0);
  int creg = numberAfter(at("AT+CREG?"), "+CREG:", 1);
  int used = numberAfter(at("AT+CPMS?"), "+CPMS:", 1);
  String body = "csq=" + String(csq) + "&creg=" + String(creg) + "&used=" + String(used) +
                "&rssi=" + String(WiFi.RSSI()) + "&uptime=" + String(millis() / 60000) + "&fw=" + FW_VERSION;
  String resp;
  int code = postForm(HEARTBEAT_URL, body, resp);
  heartbeatSent = code == 200;
  Serial.printf("heartbeat -> %d (signal %d/31, network %d, on SIM %d)\n", code, csq, creg, used);
  if (code == 401) Serial.println("  DEVICE_TOKEN does not match device_token in config.php");
}

// Returns true when the server accepted the SMS (then it may be deleted).
bool forward(const String &senderHex, const String &textHex, const String &modemTime) {
  String body = "sender=" + urlEncode(senderHex) + "&text_hex=" + urlEncode(textHex) + "&modem_time=" + urlEncode(modemTime);
  String resp;
  led(true);
  int code = postForm(SERVER_URL, body, resp);
  led(false);
  Serial.printf("  -> server %d %s\n", code, resp.c_str());
  if (code < 0) Serial.println("  computer unreachable: is start-windows.bat running? right IP in SERVER_URL? firewall allowed?");
  if (code == 401) Serial.println("  DEVICE_TOKEN does not match device_token in config.php");
  return code == 200 && resp.indexOf("\"ok\":true") >= 0;
}

// Reads every stored SMS, forwards it, deletes the ones the server took.
void scanInbox() {
  lastScan = millis();
  String cpms = at("AT+CPMS?");
  if (cpms.length() == 0) {
    if (++modemFailures >= 3) {
      Serial.println("modem stopped answering");
      modemHardwareReset();
      if (!modemInit()) ESP.restart();
    }
    return;
  }
  modemFailures = 0;
  int used = numberAfter(cpms, "+CPMS:", 1);
  if (used <= 0) return;                         // nothing waiting: cheap check

  int handled = 0;
  for (int idx = 1; idx <= MAX_SMS_SLOTS && handled < used; idx++) {
    String r = atWait("AT+CMGR=" + String(idx), 5000);
    int h = r.indexOf("+CMGR:");
    if (h < 0) continue;                         // empty slot
    handled++;
    int eol = r.indexOf('\n', h);
    String header = r.substring(h, eol);
    int okPos = r.lastIndexOf("\r\nOK");
    String text = r.substring(eol + 1, okPos > eol ? okPos : r.length());
    text.trim();
    // header: +CMGR: "REC UNREAD","<sender>","<alpha>","yy/MM/dd,hh:mm:ss+zz"
    String sender = quoted(header, 1);
    String when = quoted(header, 3);
    if (when.length() == 0) when = quoted(header, 2);
    Serial.printf("\nSMS #%d from %s at %s\n", idx, ucs2ToUtf8(sender).c_str(), when.c_str());
    Serial.println(ucs2ToUtf8(text));

    if (text.length() == 0 || forward(sender, text, when)) {
      at("AT+CMGD=" + String(idx));
    } else {
      Serial.println("  kept on the SIM, will retry");
      return;                                    // no point hammering a dead server
    }
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.printf("\n===== bank SMS forwarder %s =====\n", FW_VERSION);
  if (LED_PIN >= 0) pinMode(LED_PIN, OUTPUT);
  if (MODEM_RST_PIN >= 0) {
    pinMode(MODEM_RST_PIN, OUTPUT);
    digitalWrite(MODEM_RST_PIN, HIGH);
  }
  delay(3000);                                   // modem boot
  wifiEnsure();
  if (!modemInit()) {
    modemHardwareReset();
    if (!modemInit()) {
      delay(10000);
      ESP.restart();
    }
  }
  scanInbox();                                   // anything that came while we were off
  heartbeat();
}

void loop() {
  static String line;
  while (modem.available()) {
    char c = modem.read();
    if (c == '\n') {
      if (line.startsWith("+CMTI:")) {
        delay(300);                              // let the modem finish storing it
        scanInbox();
      } else if (line.indexOf("SMS Ready") >= 0 || line.indexOf("RDY") >= 0) {
        Serial.println("modem restarted by itself (power dip?) - setting it up again");
        modemInit();
      } else if (line.indexOf("UNDER-VOLTAGE") >= 0) {
        Serial.println("modem reports UNDER-VOLTAGE: its supply is too weak (needs ~4 V / 2 A)");
      }
      line = "";
    } else if (c != '\r') {
      line += c;
      if (line.length() > 200) line = "";
    }
  }
  if (millis() - lastScan > SCAN_EVERY_MS) scanInbox();
  // retry a failed heartbeat after a minute, otherwise every 10 minutes
  if (millis() - lastHeartbeat > (heartbeatSent ? HEARTBEAT_EVERY_MS : 60000UL)) heartbeat();
  delay(20);
}
