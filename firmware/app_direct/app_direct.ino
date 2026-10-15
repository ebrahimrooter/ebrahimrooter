/*
 * Bank SMS -> straight to the phone app (Android and iPhone), over the SIM card's own
 * internet (SIM800 GPRS). No Bale, no server, no computer, no Wi-Fi.
 *
 * - Each bank SMS is sealed with the pairing code (encrypted + signed) and left on a
 *   public MQTT broker as a retained message. The app (mode «without a server») reads it,
 *   books it, asks «what was it for?» and answers with an «ack».
 * - The SMS is deleted from the SIM only after the app's ack, so nothing is lost while the
 *   phone is off, there is no signal or no credit. Until then it stays on the SIM and on the broker.
 * - The broker only ever sees sealed bytes and a topic name made from the code.
 * - Only SMS from the banks in ALLOWED_SENDERS go on; anything else is deleted from the SIM.
 *
 * Libraries (Arduino IDE > Tools > Manage Libraries...):
 *   - "TinyGSM"      by Volodymyr Shymanskyy
 *   - "PubSubClient" by Nick O'Leary
 * Board: "ESP32 Dev Module". Serial Monitor: 115200.
 *
 * Wiring: same as sim800_test (see README): SIM800 TXD->GPIO16,
 * RXD->GPIO17 (1k/2k divider), RST->GPIO4, GND common, VCC = 4.0 V / 2 A.
 */

/* ----------------------------- settings ----------------------------- */

// The app's pairing code: app > Settings > «اتصال به ESP32». Keep it private: it is the key.
const char *PAIR_CODE = "PUT-THE-CODE-FROM-THE-APP";

// SMS from these banks go on; anything else (operator ads, …) is deleted from
// the SIM. A sender matches when it contains one of these (case, spaces and
// dashes don't matter). Add the exact sender your SIM shows for a bank if it
// is missing. Leave the list with only "" to send everything.
const char *ALLOWED_SENDERS[] = {
  "Bank Mellat", "Mellat", "700717",           // بانک ملت
  "Bank Melli", "Melli", "BMI",                 // بانک ملی
  "Bank Saderat", "Saderat", "BSI",             // بانک صادرات
  "blu", "blubank",                             // بلو بانک
};
// One-time passwords from the bank (رمز پویا) also go to the app («رمز پویا» on its home screen).
const bool FORWARD_OTP = true;

// Internet of the SIM card (APN): Hamrah-e Aval "mcinet", Irancell "mtnirancell", Rightel "rightel"
const char *APN       = "mcinet";
const char *APN_USER  = "";
const char *APN_PASS  = "";

// The message service, the same one the app uses (app > Settings > «اتصال به ESP32»).
const char *MQTT_HOST = "broker.emqx.io";
const int   MQTT_PORT = 1883;

const int MODEM_RX_PIN  = 16;   // ESP32 receives here  <- SIM800 TXD
const int MODEM_TX_PIN  = 17;   // ESP32 sends here     -> SIM800 RXD
const int MODEM_RST_PIN = 4;    // -> SIM800 RST; -1 if not wired
const int LED_PIN       = 2;    // on-board LED; -1 to disable

/* -------------------------------------------------------------------- */

#define TINY_GSM_MODEM_SIM800
#define TINY_GSM_RX_BUFFER 1024
#include <TinyGsmClient.h>
#include <PubSubClient.h>

const int MAX_SMS_SLOTS = 40;
const unsigned long CHECK_EVERY_MS = 15000;          // look at the SIM
const unsigned long ACK_CHECK_MS = 120000;           // SMS waiting for the app: look for its ack this often
const unsigned long REPUBLISH_MS = 1800000;          // and put it on the broker again (in case it was dropped)
const unsigned long STATUS_EVERY_MS = 600000;        // «I am here» for the app's settings page
const unsigned long ACK_WAIT_MS = 5000;              // after publishing, wait this long for the app's ack
const char *FW_VERSION = "app-1.0";

HardwareSerial SerialAT(2);
TinyGsm gsm(SerialAT);
TinyGsmClient tcp(gsm);
PubSubClient mqtt(tcp);

long modemBaud = 0;
unsigned long lastCheck = 0;
unsigned long lastNet = 0;
unsigned long lastStatus = 0;
bool statusSent = false;
int netFailures = 0;

uint8_t keyEnc[32], keyMac[32];
String topicBase;                                    // "ba/<16 hex>/"
String ackIds;                                       // the app's last ack: ",id1,id2,...,"

// One entry per SIM slot (index = slot number).
String slotId[MAX_SMS_SLOTS + 1];
String slotFrom[MAX_SMS_SLOTS + 1];
String slotText[MAX_SMS_SLOTS + 1];
String slotWhen[MAX_SMS_SLOTS + 1];
unsigned long slotSentAt[MAX_SMS_SLOTS + 1];         // 0 = not on the broker yet (this run)

/* --------------------- sealing (same as app/local/link.js) --------------------- */

const uint32_t SHA_K[64] = {
  0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
  0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
  0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
  0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
  0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
  0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
  0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
  0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2};

uint32_t ror32(uint32_t x, int n) { return (x >> n) | (x << (32 - n)); }

// SHA-256 of a + b (two parts, so HMAC needs no copies).
void sha256two(const uint8_t *a, size_t alen, const uint8_t *b, size_t blen, uint8_t *out) {
  uint32_t h[8] = {0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19};
  size_t n = alen + blen, total = ((n + 9 + 63) / 64) * 64;
  uint8_t block[64];
  uint32_t w[64];
  for (size_t off = 0; off < total; off += 64) {
    for (int i = 0; i < 64; i++) {
      size_t p = off + i;
      uint8_t v;
      if (p < alen) v = a[p];
      else if (p < n) v = b[p - alen];
      else if (p == n) v = 0x80;
      else if (p >= total - 8) v = (uint8_t)(((uint64_t)n * 8) >> (8 * (total - 1 - p)));
      else v = 0;
      block[i] = v;
    }
    for (int i = 0; i < 16; i++) w[i] = ((uint32_t)block[i * 4] << 24) | ((uint32_t)block[i * 4 + 1] << 16) | ((uint32_t)block[i * 4 + 2] << 8) | block[i * 4 + 3];
    for (int i = 16; i < 64; i++) {
      uint32_t s0 = ror32(w[i - 15], 7) ^ ror32(w[i - 15], 18) ^ (w[i - 15] >> 3);
      uint32_t s1 = ror32(w[i - 2], 17) ^ ror32(w[i - 2], 19) ^ (w[i - 2] >> 10);
      w[i] = w[i - 16] + s0 + w[i - 7] + s1;
    }
    uint32_t A = h[0], B = h[1], C = h[2], D = h[3], E = h[4], F = h[5], G = h[6], H = h[7];
    for (int i = 0; i < 64; i++) {
      uint32_t t1 = H + (ror32(E, 6) ^ ror32(E, 11) ^ ror32(E, 25)) + ((E & F) ^ (~E & G)) + SHA_K[i] + w[i];
      uint32_t t2 = (ror32(A, 2) ^ ror32(A, 13) ^ ror32(A, 22)) + ((A & B) ^ (A & C) ^ (B & C));
      H = G; G = F; F = E; E = D + t1; D = C; C = B; B = A; A = t1 + t2;
    }
    h[0] += A; h[1] += B; h[2] += C; h[3] += D; h[4] += E; h[5] += F; h[6] += G; h[7] += H;
  }
  for (int i = 0; i < 8; i++) { out[i * 4] = h[i] >> 24; out[i * 4 + 1] = h[i] >> 16; out[i * 4 + 2] = h[i] >> 8; out[i * 4 + 3] = h[i]; }
}

void hmac256(const uint8_t *key, size_t klen, const uint8_t *msg, size_t mlen, uint8_t *out) {
  uint8_t k[64], pad[64], inner[32];
  memset(k, 0, 64);
  if (klen > 64) sha256two(key, klen, nullptr, 0, k); else memcpy(k, key, klen);
  for (int i = 0; i < 64; i++) pad[i] = k[i] ^ 0x36;
  sha256two(pad, 64, msg, mlen, inner);
  for (int i = 0; i < 64; i++) pad[i] = k[i] ^ 0x5c;
  sha256two(pad, 64, inner, 32, out);
}

String toHex(const uint8_t *b, size_t n) {
  const char *d = "0123456789abcdef";
  String s;
  for (size_t i = 0; i < n; i++) { s += d[b[i] >> 4]; s += d[b[i] & 15]; }
  return s;
}

const char *B64 = "ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/";

String base64(const uint8_t *b, size_t n) {
  String s;
  for (size_t i = 0; i < n; i += 3) {
    uint32_t v = (uint32_t)b[i] << 16 | (i + 1 < n ? (uint32_t)b[i + 1] << 8 : 0) | (i + 2 < n ? b[i + 2] : 0);
    s += B64[(v >> 18) & 63]; s += B64[(v >> 12) & 63];
    s += i + 1 < n ? B64[(v >> 6) & 63] : '=';
    s += i + 2 < n ? B64[v & 63] : '=';
  }
  return s;
}

// Decodes into out (room for len*3/4 bytes); returns the length, -1 if not base64.
int unbase64(const String &s, uint8_t *out) {
  int n = 0, bits = 0;
  uint32_t acc = 0;
  for (unsigned int i = 0; i < s.length(); i++) {
    char c = s[i];
    if (c == '=' || c == '\r' || c == '\n' || c == ' ') continue;
    const char *p = strchr(B64, c);
    if (!p || !c) return -1;
    acc = (acc << 6) | (uint32_t)(p - B64);
    bits += 6;
    if (bits >= 8) { bits -= 8; out[n++] = (acc >> bits) & 0xFF; }
  }
  return n;
}

// XOR with HMAC(keyEnc, iv | block number) - the same both ways.
void keystream(const uint8_t *iv, uint8_t *data, size_t n) {
  uint8_t in[16], ks[32];
  memcpy(in, iv, 12);
  for (size_t blk = 0; blk * 32 < n; blk++) {
    in[12] = blk >> 24; in[13] = blk >> 16; in[14] = blk >> 8; in[15] = blk;
    hmac256(keyEnc, 32, in, 16, ks);
    for (size_t i = 0; i < 32 && blk * 32 + i < n; i++) data[blk * 32 + i] ^= ks[i];
  }
}

void makeKeys() {
  String code = "ba-link-v1:";
  for (const char *p = PAIR_CODE; *p; p++) {
    char c = *p;
    if (c >= 'a' && c <= 'z') c -= 32;
    if ((c >= 'A' && c <= 'Z') || (c >= '0' && c <= '9')) code += c;
  }
  uint8_t master[32], t[32];
  sha256two((const uint8_t *)code.c_str(), code.length(), nullptr, 0, master);
  hmac256(master, 32, (const uint8_t *)"enc", 3, keyEnc);
  hmac256(master, 32, (const uint8_t *)"mac", 3, keyMac);
  hmac256(master, 32, (const uint8_t *)"topic", 5, t);
  topicBase = "ba/" + toHex(t, 8) + "/";
}

String sealText(const String &plain) {
  size_t n = plain.length();
  uint8_t *buf = (uint8_t *)malloc(12 + n + 32);
  if (!buf) return "";
  for (int i = 0; i < 12; i++) buf[i] = esp_random() & 0xFF;
  memcpy(buf + 12, plain.c_str(), n);
  keystream(buf, buf + 12, n);
  uint8_t tag[32];
  hmac256(keyMac, 32, buf, 12 + n, tag);
  memcpy(buf + 12 + n, tag, 16);
  String out = base64(buf, 12 + n + 16);
  free(buf);
  return out;
}

// false when it was not sealed with our code (or changed on the way)
bool openText(const String &sealed, String &plain) {
  uint8_t *buf = (uint8_t *)malloc(sealed.length() * 3 / 4 + 4);
  if (!buf) return false;
  int n = unbase64(sealed, buf);
  bool ok = false;
  if (n >= 28) {
    uint8_t tag[32];
    hmac256(keyMac, 32, buf, n - 16, tag);
    uint8_t diff = 0;
    for (int i = 0; i < 16; i++) diff |= tag[i] ^ buf[n - 16 + i];
    if (!diff) {
      keystream(buf, buf + 12, n - 28);
      plain = "";
      for (int i = 12; i < n - 16; i++) plain += (char)buf[i];
      ok = true;
    }
  }
  free(buf);
  return ok;
}

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

String jsonEscape(const String &s) {
  String out;
  for (size_t i = 0; i < s.length(); i++) {
    char c = s[i];
    if (c == '"' || c == '\\') { out += '\\'; out += c; }
    else if (c == '\n') out += "\\n";
    else if (c == '\r') continue;
    else if ((unsigned char)c < 0x20) out += ' ';
    else out += c;
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

/** Is this SMS from one of ALLOWED_SENDERS? */
bool senderAllowed(const String &sender) {
  String s = normalizeSender(sender);
  for (size_t i = 0; i < sizeof(ALLOWED_SENDERS) / sizeof(ALLOWED_SENDERS[0]); i++) {
    String a = normalizeSender(ALLOWED_SENDERS[i]);
    if (a.length() == 0 || s.indexOf(a) >= 0) return true;
  }
  return false;
}

bool isOtp(const String &t) {
  bool password = t.indexOf("رمز") >= 0 && (t.indexOf("پویا") >= 0 || t.indexOf("یکبار") >= 0 || t.indexOf("یک بار") >= 0);
  return password || t.indexOf("کد تایید") >= 0 || t.indexOf("کد تأیید") >= 0;
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

// Reads one slot into sender/text/when; false if the slot is empty.
// UCS2 only for the duration of the read, then back to GSM so the internet
// commands keep working. (No custom struct here: the Arduino IDE puts
// function declarations at the top of the file, before any struct.)
bool readSms(int idx, String &sender, String &text, String &when) {
  at("AT+CSCS=\"UCS2\"");
  String r = atWait("AT+CMGR=" + String(idx), 5000);
  charsetGsm();
  int h = r.indexOf("+CMGR:");
  if (h < 0) return false;
  int eol = r.indexOf('\n', h);
  String header = r.substring(h, eol);
  int okPos = r.lastIndexOf("\r\nOK");
  String body = r.substring(eol + 1, okPos > eol ? okPos : r.length());
  body.trim();
  sender = ucs2ToUtf8(quoted(header, 1));
  when = quoted(header, 3);
  if (when.length() == 0) when = quoted(header, 2);
  text = ucs2ToUtf8(body);
  return true;
}

/* ---------------------------------- MQTT ---------------------------------- */

// The app's retained ack: {"v":1,"ids":["…","…"]}
void onMqtt(char *topic, byte *payload, unsigned int length) {
  if (String(topic) != topicBase + "ack" || length == 0) return;
  String sealed;
  for (unsigned int i = 0; i < length; i++) sealed += (char)payload[i];
  String json;
  if (!openText(sealed, json)) {
    Serial.println("  an ack not sealed with PAIR_CODE (is the code the same as in the app?)");
    return;
  }
  String ids = ",";
  int k = json.indexOf("\"ids\"");
  int l = k >= 0 ? json.indexOf('[', k) : -1, r = l >= 0 ? json.indexOf(']', l) : -1;
  if (l >= 0 && r > l) {
    String list = json.substring(l + 1, r);
    int q = 0;
    while ((q = list.indexOf('"', q)) >= 0) {
      int e = list.indexOf('"', q + 1);
      if (e < 0) break;
      ids += list.substring(q + 1, e) + ",";
      q = e + 1;
    }
  }
  ackIds = ids;
}

bool mqttOpen() {
  if (mqtt.connected()) return true;
  mqtt.setServer(MQTT_HOST, MQTT_PORT);
  mqtt.setCallback(onMqtt);
  mqtt.setBufferSize(2048);
  String id = "ba-esp-" + topicBase.substring(3, 15);
  Serial.printf("message service %s:%d...", MQTT_HOST, MQTT_PORT);
  if (!mqtt.connect(id.c_str())) {
    Serial.printf(" failed (%d)\n", mqtt.state());
    return false;
  }
  Serial.println(" ok");
  mqtt.subscribe((topicBase + "ack").c_str());
  return true;
}

void mqttClose() {
  mqtt.disconnect();
  tcp.stop();
}

// Lets MQTT run for a while (callbacks fire in here).
void mqttRun(unsigned long ms) {
  unsigned long start = millis();
  while (millis() - start < ms) {
    if (!mqtt.loop()) break;
    delay(20);
  }
}

bool acked(const String &id) {
  return id.length() > 0 && ackIds.indexOf("," + id + ",") >= 0;
}

/* ------------------------------ SMS work ----------------------------- */

String smsId(const String &from, const String &when, const String &text) {
  String all = from + "|" + when + "|" + text;
  uint8_t h[32];
  sha256two((const uint8_t *)all.c_str(), all.length(), nullptr, 0, h);
  return toHex(h, 8);
}

// Reads the SIM: bank SMS into the slot table, anything else deleted.
// Returns how many are waiting for the app.
int readInbox() {
  int used = numberAfter(at("AT+CPMS?"), "+CPMS:", 1);
  if (used < 0) {
    Serial.println("modem not answering");
    modemHardwareReset();
    if (!modemInit()) ESP.restart();
    return -1;
  }
  bool seen[MAX_SMS_SLOTS + 1];
  for (int i = 0; i <= MAX_SMS_SLOTS; i++) seen[i] = false;
  int handled = 0, waiting = 0;
  for (int idx = 1; idx <= MAX_SMS_SLOTS && handled < used; idx++) {
    String sender, text, when;
    if (!readSms(idx, sender, text, when)) continue;
    handled++;
    if (!senderAllowed(sender)) {
      Serial.printf("SMS #%d from %s: not a bank in ALLOWED_SENDERS -> deleted\n", idx, sender.c_str());
      at("AT+CMGD=" + String(idx));
      continue;
    }
    if (!FORWARD_OTP && isOtp(text)) {
      Serial.printf("SMS #%d: one-time password, FORWARD_OTP is off -> deleted\n", idx);
      at("AT+CMGD=" + String(idx));
      continue;
    }
    String id = smsId(sender, when, text);
    if (slotId[idx] != id) {
      Serial.printf("\nSMS #%d from %s at %s\n%s\n", idx, sender.c_str(), when.c_str(), text.c_str());
      slotId[idx] = id; slotFrom[idx] = sender; slotText[idx] = text; slotWhen[idx] = when; slotSentAt[idx] = 0;
    }
    seen[idx] = true;
    waiting++;
  }
  for (int i = 1; i <= MAX_SMS_SLOTS; i++) if (!seen[i]) slotId[i] = "";
  return waiting;
}

void checkInbox() {
  lastCheck = millis();
  int waiting = readInbox();
  if (waiting < 0) return;
  bool fresh = false;
  for (int i = 1; i <= MAX_SMS_SLOTS; i++) {
    if (slotId[i].length() && (slotSentAt[i] == 0 || millis() - slotSentAt[i] > REPUBLISH_MS)) fresh = true;
  }
  bool statusDue = !statusSent || millis() - lastStatus > STATUS_EVERY_MS;
  bool lookForAck = waiting > 0 && millis() - lastNet > ACK_CHECK_MS;
  if (!fresh && !statusDue && !lookForAck) return;

  lastNet = millis();
  if (!internetUp() || !mqttOpen()) {
    netFailures++;
    Serial.println("  kept on the SIM, will retry");
    return;
  }
  netFailures = 0;
  led(true);
  mqttRun(800);                                   // the retained ack arrives first
  int published = 0;
  for (int i = 1; i <= MAX_SMS_SLOTS; i++) {
    if (!slotId[i].length() || acked(slotId[i])) continue;
    if (slotSentAt[i] != 0 && millis() - slotSentAt[i] <= REPUBLISH_MS) continue;
    String json = "{\"v\":1,\"id\":\"" + slotId[i] + "\",\"from\":\"" + jsonEscape(slotFrom[i]) +
                  "\",\"when\":\"" + jsonEscape(slotWhen[i]) + "\",\"text\":\"" + jsonEscape(slotText[i]) + "\"}";
    if (mqtt.publish((topicBase + "sms/" + slotId[i]).c_str(), sealText(json).c_str(), true)) {
      slotSentAt[i] = millis();
      published++;
      Serial.printf("  SMS #%d -> app (waiting for its ack)\n", i);
    } else {
      Serial.printf("  SMS #%d: publish failed (too long? network?)\n", i);
    }
  }
  if (published) mqttRun(ACK_WAIT_MS);            // the app is online: its ack comes in a moment
  int done = 0;
  for (int i = 1; i <= MAX_SMS_SLOTS; i++) {
    if (!acked(slotId[i])) continue;
    mqtt.publish((topicBase + "sms/" + slotId[i]).c_str(), (const uint8_t *)"", 0, true);   // off the broker
    done++;
  }
  String st = "{\"v\":1,\"sig\":" + String(gsm.getSignalQuality()) + ",\"wait\":" + String(waiting - done) +
              ",\"fw\":\"" + FW_VERSION + "\",\"up\":" + String(millis() / 1000) + "}";
  if (mqtt.publish((topicBase + "st").c_str(), sealText(st).c_str(), true)) { statusSent = true; lastStatus = millis(); }
  mqttRun(300);
  mqttClose();
  led(false);
  // raw AT commands only with the socket closed
  for (int i = 1; i <= MAX_SMS_SLOTS; i++) {
    if (!acked(slotId[i])) continue;
    Serial.printf("  SMS #%d: the app has it -> deleted from the SIM\n", i);
    at("AT+CMGD=" + String(i));
    slotId[i] = "";
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.printf("\n===== bank SMS -> phone app (%s) =====\n", FW_VERSION);
  if (LED_PIN >= 0) pinMode(LED_PIN, OUTPUT);
  if (MODEM_RST_PIN >= 0) {
    pinMode(MODEM_RST_PIN, OUTPUT);
    digitalWrite(MODEM_RST_PIN, HIGH);
  }
  makeKeys();
  if (strlen(PAIR_CODE) < 16 || strstr(PAIR_CODE, "PUT-") == PAIR_CODE) {
    Serial.println("PAIR_CODE is not set: copy it from the app (Settings > اتصال به ESP32) and upload again.");
  }
  Serial.println("topic: " + topicBase);
  delay(3000);
  if (!modemInit()) {
    modemHardwareReset();
    if (!modemInit()) {
      delay(10000);
      ESP.restart();
    }
  }
  checkInbox();
}

void loop() {
  if (millis() - lastCheck > CHECK_EVERY_MS) checkInbox();
  // Network trouble for a while (~ten tries): reset the modem and start over.
  if (netFailures >= 10) {
    netFailures = 0;
    gsm.gprsDisconnect();
    modemHardwareReset();
    modemInit();
  }
  delay(50);
}
