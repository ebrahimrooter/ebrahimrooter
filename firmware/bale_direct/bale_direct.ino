/*
 * Bank SMS -> Bale bot, directly from the ESP32 over the SIM card's own
 * internet (SIM800 GPRS). No computer, no Wi-Fi, no server.
 *
 * - Only SMS from the banks in ALLOWED_SENDERS (Mellat, Melli, Saderat, Blu) are sent to Bale;
 *   anything else is deleted from the SIM.
 * - An SMS is deleted from the SIM only after Bale accepted it, so nothing
 *   is lost while there is no signal / no credit.
 * - First run without BALE_CHAT_ID: send /start to your bot in Bale; the
 *   ESP32 answers with your chat id (also printed in Serial Monitor).
 *
 * Libraries (Arduino IDE > Tools > Manage Libraries...):
 *   - "TinyGSM"       by Volodymyr Shymanskyy
 *   - "ESP_SSLClient" by Mobizt
 * Board: "ESP32 Dev Module". Serial Monitor: 115200.
 *
 * Wiring: same as sim800_test (see README): SIM800 TXD->GPIO16,
 * RXD->GPIO17 (1k/2k divider), RST->GPIO4, GND common, VCC = 4.0 V / 2 A.
 * GPRS draws more current than SMS: a strong supply matters even more.
 */

/* ----------------------------- settings ----------------------------- */

// From @botfather in Bale. Keep this file private: the token is a password.
const char *BALE_TOKEN    = "PUT-YOUR-BOT-TOKEN-HERE";
// Leave empty the first time; the device will tell you the number.
const char *BALE_CHAT_ID  = "";

// SMS from these banks go on; anything else (operator ads, …) is deleted from
// the SIM. A sender matches when it contains one of these (case, spaces and
// dashes don't matter). Add the exact sender your SIM shows for a bank if it
// is missing. Leave the list with only "" to send everything.
// Which card an SMS belongs to is decided on the server (Mellat, Melli,
// Saderat, Blu: each has its own card and its own account in the books).
const char *ALLOWED_SENDERS[] = {
  "Bank Mellat", "Mellat", "700717",           // بانک ملت
  "Bank Melli", "Melli", "BMI",                 // بانک ملی
  "Bank Saderat", "Saderat", "BSI",             // بانک صادرات
  "blu", "blubank",                             // بلو بانک
};
// One-time passwords from the bank (رمز پویا) also go to Bale. Set to
// false if you don't want codes in a chat history; they are then deleted.
const bool FORWARD_OTP = true;
// Ask "what was it for?" under each deposit/withdrawal, with buttons. The
// accounting program on the computer (start-windows.bat, same bot token)
// picks the SMS up when you tap a button or reply, asks and records it.
const bool ASK_IN_BALE = true;

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

const char *BALE_HOST = "tapi.bale.ai";
const int MAX_SMS_SLOTS = 40;
const unsigned long CHECK_EVERY_MS = 10000;
const char *FW_VERSION = "bale-1.1";

HardwareSerial SerialAT(2);
TinyGsm gsm(SerialAT);
TinyGsmClient tcp(gsm);
ESP_SSLClient tls;

long modemBaud = 0;
unsigned long lastCheck = 0;
long updateOffset = 0;
int netFailures = 0;

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

// Persian digits and separators -> ASCII digits only, e.g. "12,345,670" -> 12345670.
// Reads the number right after `word` in text; -1 if not there.
long long numberAfterWord(const String &text, const char *word) {
  int p = text.indexOf(word);
  if (p < 0) return -1;
  p += strlen(word);
  long long v = 0;
  bool any = false;
  while (p < (int)text.length()) {
    unsigned char c = text[p];
    if (c >= '0' && c <= '9') { v = v * 10 + (c - '0'); any = true; p++; }
    else if (c == 0xDB && p + 1 < (int)text.length() && (unsigned char)text[p + 1] >= 0xB0 && (unsigned char)text[p + 1] <= 0xB9) {
      v = v * 10 + ((unsigned char)text[p + 1] - 0xB0); any = true; p += 2;    // ۰..۹
    } else if (c == ',' || (any == false && (c == ':' || c == ' '))) { p++; }
    else if (c == 0xD9 && p + 1 < (int)text.length() && (unsigned char)text[p + 1] == 0xAC) { p += 2; }  // ٬
    else break;
  }
  return any ? v : -1;
}

String withCommas(long long v) {
  String digits = String((unsigned long)(v % 1000000000ULL));
  if (v >= 1000000000LL) {
    String hi = String((unsigned long)(v / 1000000000LL));
    String lo = String((unsigned long)(v % 1000000000LL));
    while (lo.length() < 9) lo = "0" + lo;
    digits = hi + lo;
  }
  String out;
  int n = digits.length();
  for (int i = 0; i < n; i++) {
    out += digits[i];
    if ((n - i - 1) % 3 == 0 && i < n - 1) out += ',';
  }
  return out;
}

bool isOtp(const String &t) {
  bool password = t.indexOf("رمز") >= 0 && (t.indexOf("پویا") >= 0 || t.indexOf("یکبار") >= 0 || t.indexOf("یک بار") >= 0);
  return password || t.indexOf("کد تایید") >= 0 || t.indexOf("کد تأیید") >= 0;
}

// The Bale message for one bank SMS: a readable summary line, then the SMS itself.
String formatForBale(const String &text) {
  String head;
  if (isOtp(text)) {
    head = "🔐 رمز یکبار مصرف";
  } else {
    long long dep = numberAfterWord(text, "واریز");
    long long wd = numberAfterWord(text, "برداشت");
    long long bal = numberAfterWord(text, "مانده");
    if (dep >= 0) head = "🟢 واریز " + withCommas(dep / 10) + " تومان";
    else if (wd >= 0) head = "🔴 برداشت " + withCommas(wd / 10) + " تومان";
    else head = "🏦 پیامک بانک";
    if (bal >= 0) head += "\nمانده: " + withCommas(bal / 10) + " تومان";
  }
  String msg = head + "\n──────────\n" + text;
  // The second separator closes the SMS; the accounting program reads the
  // text between the two, so keep this layout.
  if (ASK_IN_BALE && !isOtp(text)) msg += "\n──────────\n✍️ بابت چی بود؟ دکمه را بزن یا روی همین پیام Reply کن و بنویس.";
  return msg;
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

/* -------------------------------- Bale ------------------------------- */

// POSTs JSON to a Bale bot method. Returns the HTTP status (or -1) and the body.
int bale(const char *method, const String &json, String &body) {
  body = "";
  if (!internetUp()) return -1;
  led(true);
  int status = -1;
  if (tls.connect(BALE_HOST, 443)) {
    tls.print(String("POST /bot") + BALE_TOKEN + "/" + method + " HTTP/1.1\r\n");
    tls.print(String("Host: ") + BALE_HOST + "\r\n");
    tls.print("Content-Type: application/json\r\n");
    tls.print("Connection: close\r\n");
    tls.print("Content-Length: " + String(json.length()) + "\r\n\r\n");
    tls.print(json);
    String resp;
    unsigned long start = millis();
    while (millis() - start < 30000) {
      while (tls.available()) resp += (char)tls.read();
      if (!tls.connected() && !tls.available()) break;
      if (resp.length() > 6000) break;
      delay(10);
    }
    if (resp.startsWith("HTTP/1.")) status = resp.substring(9, 12).toInt();
    int b = resp.indexOf("\r\n\r\n");
    body = b >= 0 ? resp.substring(b + 4) : resp;
  } else {
    Serial.println("  could not open a secure connection to Bale");
  }
  tls.stop();
  led(false);
  return status;
}

bool baleSend(const String &chatId, const String &text, bool withButtons) {
  String json = "{\"chat_id\":\"" + jsonEscape(chatId) + "\",\"text\":\"" + jsonEscape(text) + "\"";
  if (withButtons) {
    json += ",\"reply_markup\":{\"inline_keyboard\":[[{\"text\":\"✍️ ثبت در حسابداری\",\"callback_data\":\"relay:ans\"},"
            "{\"text\":\"🚫 شخصی / نادیده\",\"callback_data\":\"relay:ign\"}]]}";
  }
  json += "}";
  String body;
  int code = bale("sendMessage", json, body);
  bool ok = code == 200 && body.indexOf("\"ok\":true") >= 0;
  Serial.printf("  Bale -> %d %s\n", code, ok ? "ok" : body.substring(0, 200).c_str());
  if (code == 401 || code == 404) Serial.println("  BALE_TOKEN looks wrong (copy it again from @botfather)");
  if (code == 400 && body.indexOf("chat") >= 0) Serial.println("  BALE_CHAT_ID looks wrong; empty it to find yours again");
  if (code < 0) netFailures++; else netFailures = 0;
  return ok;
}

// Without a chat id yet: answer whoever writes to the bot with their chat id.
void discoverChatId() {
  String body;
  int code = bale("getUpdates", "{\"offset\":" + String(updateOffset) + ",\"timeout\":0}", body);
  if (code != 200) {
    Serial.printf("getUpdates -> %d (token ok? internet ok?)\n", code);
    return;
  }
  int p = 0;
  while ((p = body.indexOf("\"update_id\":", p)) >= 0) {
    p += 12;
    long id = body.substring(p).toInt();
    if (id >= updateOffset) updateOffset = id + 1;
    int c = body.indexOf("\"chat\":", p);
    int i = c >= 0 ? body.indexOf("\"id\":", c) : -1;
    if (i < 0) continue;
    String chat = String(body.substring(i + 5).toInt());
    Serial.println("\n>>> your chat id: " + chat + "   put it in BALE_CHAT_ID and upload again <<<\n");
    baleSend(chat, "شناسه چت شما: " + chat + "\nآن را در BALE_CHAT_ID برنامه‌ی ESP32 بگذار و دوباره آپلود کن.", false);
  }
}

/* ------------------------------ SMS work ----------------------------- */

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
  bool haveChat = strlen(BALE_CHAT_ID) > 0;
  int handled = 0;
  for (int idx = 1; idx <= MAX_SMS_SLOTS && handled < used; idx++) {
    String sender, text, when;
    if (!readSms(idx, sender, text, when)) continue;
    handled++;
    Serial.printf("\nSMS #%d from %s at %s\n%s\n", idx, sender.c_str(), when.c_str(), text.c_str());
    if (!senderAllowed(sender)) {
      Serial.println("  not from a bank in ALLOWED_SENDERS -> deleted");
      at("AT+CMGD=" + String(idx));
      continue;
    }
    if (!FORWARD_OTP && isOtp(text)) {
      Serial.println("  one-time password, FORWARD_OTP is off -> deleted");
      at("AT+CMGD=" + String(idx));
      continue;
    }
    if (!haveChat) {
      Serial.println("  kept on the SIM until BALE_CHAT_ID is set");
      continue;
    }
    if (baleSend(BALE_CHAT_ID, formatForBale(text), ASK_IN_BALE && !isOtp(text))) {
      at("AT+CMGD=" + String(idx));
    } else {
      Serial.println("  kept on the SIM, will retry");
      break;                                     // no point trying the rest right now
    }
  }
}

void setup() {
  Serial.begin(115200);
  delay(1000);
  Serial.printf("\n===== bank SMS -> Bale (%s) =====\n", FW_VERSION);
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
  tls.setInsecure();
  tls.setBufferSizes(16384, 2048);                 // full TLS records: works with any server
  tls.setClient(&tcp);

  if (strlen(BALE_CHAT_ID) == 0) {
    Serial.println("BALE_CHAT_ID is empty: open your bot in Bale and send /start ...");
  } else {
    baleSend(BALE_CHAT_ID, "✅ دستگاه پیامک بانک روشن شد.", false);
  }
  checkInbox();
}

void loop() {
  if (millis() - lastCheck > CHECK_EVERY_MS) {
    if (strlen(BALE_CHAT_ID) == 0) discoverChatId();
    checkInbox();
  }
  // Network trouble for a while (e.g. ~5 minutes): reset the modem and start over.
  if (netFailures >= 30) {
    netFailures = 0;
    gsm.gprsDisconnect();
    modemHardwareReset();
    modemInit();
  }
  delay(50);
}
