/*
 * SIM800 test - step 1 of the bank SMS project.
 *
 * Upload this first, open Serial Monitor at 115200 baud and read what it
 * says. It needs no Wi-Fi and no server. It checks, one by one:
 *   1. is the modem answering (and at which baud rate)
 *   2. the SIM card, signal strength, network registration, supply voltage
 *   3. SMS reading - every stored SMS and every new one is printed in
 *      Persian, with the sender ID (you need the bank's ID later)
 * Then anything you type in Serial Monitor is sent to the modem, so you
 * can try AT commands by hand.
 *
 * Wiring (ESP32 DevKit + SIM800L) - see the README for the full picture:
 *   SIM800L TXD -> GPIO16      SIM800L RXD -> GPIO17 (via divider, see README)
 *   SIM800L GND -> GND of ESP32 AND of the power supply
 *   SIM800L VCC -> 4.0 V supply that can give 2 A (NOT the ESP32 pins)
 *   SIM800L RST -> GPIO4 (optional)
 *
 * Board: "ESP32 Dev Module". No extra libraries.
 */

const int MODEM_RX_PIN  = 16;   // ESP32 receives here  <- SIM800 TXD
const int MODEM_TX_PIN  = 17;   // ESP32 sends here     -> SIM800 RXD
const int MODEM_RST_PIN = 4;    // -> SIM800 RST; set to -1 if not wired
const int MAX_SMS_SLOTS = 40;

HardwareSerial modem(2);
long modemBaud = 0;

/* ------------------------------ helpers ------------------------------ */

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

// First line of the reply that starts with prefix, e.g. "+CSQ: 17,0".
String lineWith(const String &reply, const char *prefix) {
  int p = reply.indexOf(prefix);
  if (p < 0) return "";
  int e = reply.indexOf('\r', p);
  return reply.substring(p, e < 0 ? reply.length() : e);
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

bool isUcs2Hex(const String &s) {
  if (s.length() == 0 || s.length() % 4 != 0) return false;
  for (size_t i = 0; i < s.length(); i++) if (hexVal(s[i]) < 0) return false;
  return true;
}

void appendUtf8(String &out, uint32_t cp) {
  if (cp < 0x80) {
    out += (char)cp;
  } else if (cp < 0x800) {
    out += (char)(0xC0 | (cp >> 6));
    out += (char)(0x80 | (cp & 0x3F));
  } else if (cp < 0x10000) {
    out += (char)(0xE0 | (cp >> 12));
    out += (char)(0x80 | ((cp >> 6) & 0x3F));
    out += (char)(0x80 | (cp & 0x3F));
  } else {
    out += (char)(0xF0 | (cp >> 18));
    out += (char)(0x80 | ((cp >> 12) & 0x3F));
    out += (char)(0x80 | ((cp >> 6) & 0x3F));
    out += (char)(0x80 | (cp & 0x3F));
  }
}

// "0628062706460020..." (UCS2 hex from the modem) -> readable UTF-8 text.
String ucs2ToUtf8(const String &hex) {
  if (!isUcs2Hex(hex)) return hex;
  String out;
  for (size_t i = 0; i + 3 < hex.length(); i += 4) {
    uint32_t u = (hexVal(hex[i]) << 12) | (hexVal(hex[i + 1]) << 8) | (hexVal(hex[i + 2]) << 4) | hexVal(hex[i + 3]);
    if (u >= 0xD800 && u <= 0xDBFF && i + 7 < hex.length()) {      // emoji etc.: surrogate pair
      uint32_t lo = (hexVal(hex[i + 4]) << 12) | (hexVal(hex[i + 5]) << 8) | (hexVal(hex[i + 6]) << 4) | hexVal(hex[i + 7]);
      u = 0x10000 + ((u - 0xD800) << 10) + (lo - 0xDC00);
      i += 4;
    }
    appendUtf8(out, u);
  }
  return out;
}

void title(const char *t) {
  Serial.println();
  Serial.print("---- ");
  Serial.print(t);
  Serial.println(" ----");
}

/* ------------------------------- steps ------------------------------- */

void hardwareReset() {
  if (MODEM_RST_PIN < 0) return;
  pinMode(MODEM_RST_PIN, OUTPUT);
  digitalWrite(MODEM_RST_PIN, LOW);
  delay(200);
  digitalWrite(MODEM_RST_PIN, HIGH);
  delay(4000);
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

// The modem may still be in UCS2 from an earlier run; then even this
// command must be written in UCS2 ("GSM" = 0047 0053 004D).
void charsetGsm() {
  if (at("AT+CSCS=\"GSM\"").indexOf("OK") < 0) at("AT+CSCS=\"00470053004D\"");
}

void checkModem() {
  title("1. Modem");
  modemBaud = findModem();
  if (!modemBaud) {
    Serial.println("FAIL: no answer from the modem. Check, in this order:");
    Serial.println("  - power: SIM800 VCC 3.7-4.2 V from a supply that gives 2 A, GND shared with ESP32");
    Serial.println("  - the module's LED blinks at all (no blinking = no power / dead module)");
    Serial.println("  - SIM800 TXD -> GPIO16 and SIM800 RXD -> GPIO17 (TX/RX crossed, not TX-TX)");
    Serial.println("  - wait 10 s after power-up and press the ESP32 EN/RST button to try again");
    return;
  }
  Serial.printf("OK: modem answers at %ld baud\n", modemBaud);
  at("ATE0");
  charsetGsm();
  String ati = atWait("ATI", 2000);
  ati.replace("\r\n", " ");
  ati.replace("OK", "");
  ati.trim();
  Serial.println("Model: " + ati);
}

void checkPowerSimNetwork() {
  title("2. Power, SIM card, network");
  int mv = numberAfter(at("AT+CBC"), "+CBC:", 2);
  if (mv > 0) {
    Serial.printf("Supply voltage: %d.%02d V  ", mv / 1000, (mv % 1000) / 10);
    if (mv < 3500) Serial.println("<- TOO LOW: the modem will keep restarting. Use a 2 A supply set to ~4.0 V.");
    else if (mv > 4400) Serial.println("<- TOO HIGH: max 4.4 V, risk of damage!");
    else Serial.println("(good)");
  }

  String pin = lineWith(at("AT+CPIN?"), "+CPIN:");
  Serial.println("SIM: " + pin);
  if (pin.indexOf("READY") < 0) {
    if (pin.indexOf("SIM PIN") >= 0) Serial.println("  -> the SIM asks for a PIN code. Put it in a phone and disable the PIN, then retry.");
    else Serial.println("  -> SIM not detected: check it is inserted the right way round (and is a micro-SIM / adapter fits).");
    return;
  }

  Serial.print("Waiting for the network");
  int stat = -1;
  for (int i = 0; i < 60; i++) {                 // up to 60 s after power-up
    stat = numberAfter(at("AT+CREG?"), "+CREG:", 1);
    if (stat == 1 || stat == 5 || stat == 3) break;
    Serial.print(".");
    delay(1000);
  }
  Serial.println();
  const char *names[] = {"not searching", "registered (home)", "searching...", "REGISTRATION DENIED", "unknown", "registered (roaming)"};
  Serial.printf("Network: %s\n", stat >= 0 && stat <= 5 ? names[stat] : "no answer");
  if (stat == 3) Serial.println("  -> the operator refuses this SIM (inactive / blocked line?). Test the SIM in a phone.");
  if (stat == 0 || stat == 2) Serial.println("  -> no 2G network: antenna attached? 2G coverage of your operator here? LED should blink every ~3 s when registered.");

  Serial.println("Operator: " + lineWith(at("AT+COPS?"), "+COPS:"));
  int csq = numberAfter(at("AT+CSQ"), "+CSQ:", 0);
  if (csq >= 0 && csq != 99) {
    Serial.printf("Signal: %d/31 (%d dBm) ", csq, -113 + 2 * csq);
    Serial.println(csq >= 15 ? "good" : csq >= 10 ? "ok" : csq >= 5 ? "weak - move the antenna / device" : "very weak");
  } else {
    Serial.println("Signal: unknown (no network yet)");
  }
}

void printSms(int idx, const String &reply) {
  int h = reply.indexOf("+CMGR:");
  if (h < 0) return;
  int eol = reply.indexOf('\n', h);
  String header = reply.substring(h, eol);
  int okPos = reply.lastIndexOf("\r\nOK");
  String text = reply.substring(eol + 1, okPos > eol ? okPos : reply.length());
  text.trim();
  String sender = quoted(header, 1);
  String when = quoted(header, 3);
  if (when.length() == 0) when = quoted(header, 2);
  Serial.printf("\n[SMS #%d] from: %s   time: %s\n", idx, ucs2ToUtf8(sender).c_str(), when.c_str());
  Serial.println(ucs2ToUtf8(text));
}

void checkSms() {
  title("3. SMS");
  at("AT+CMGF=1");                       // text mode
  at("AT+CPMS=\"SM\",\"SM\",\"SM\"");    // store on the SIM (before switching to UCS2!)
  at("AT+CNMI=2,1,0,0,0");               // tell us when a new SMS is stored
  at("AT+CSCS=\"UCS2\"");                // Persian-safe
  int used = numberAfter(at("AT+CPMS?"), "+CPMS:", 1);
  Serial.printf("SMS stored on the SIM: %d\n", used);
  int shown = 0;
  for (int idx = 1; idx <= MAX_SMS_SLOTS && shown < used; idx++) {
    String r = atWait("AT+CMGR=" + String(idx), 5000);
    if (r.indexOf("+CMGR:") >= 0) {
      printSms(idx, r);
      shown++;
    }
  }
  Serial.println("\nREADY. Send an SMS to this SIM from your phone (write something in Persian).");
  Serial.println("It should appear here within a few seconds. Then wait for a bank SMS and note");
  Serial.println("its 'from:' value - that is the bank's sender ID.");
  Serial.println("You can also type AT commands here (e.g. AT+CSQ) and press Enter.");
}

/* ----------------------------- main ----------------------------- */

void setup() {
  Serial.begin(115200);
  delay(1500);
  Serial.println("\n\n===== SIM800 test =====");
  hardwareReset();
  checkModem();
  if (!modemBaud) return;
  checkPowerSimNetwork();
  checkSms();
}

void loop() {
  static String line;
  // modem -> Serial Monitor (and show new SMS decoded)
  while (modemBaud && modem.available()) {
    char c = modem.read();
    if (c == '\n') {
      line.trim();
      if (line.startsWith("+CMTI:")) {
        int idx = line.substring(line.lastIndexOf(',') + 1).toInt();
        delay(300);
        printSms(idx, atWait("AT+CMGR=" + String(idx), 5000));
      } else if (line.length()) {
        Serial.println("<< " + line);
        if (line.indexOf("UNDER-VOLTAGE") >= 0 || line.indexOf("NORMAL POWER DOWN") >= 0) {
          Serial.println("!! the modem reports a power problem: use a stronger 4 V / 2 A supply.");
        }
      }
      line = "";
    } else if (c != '\r') {
      line += c;
    }
  }
  // Serial Monitor -> modem (manual AT commands)
  static String typed;
  while (Serial.available()) {
    char c = Serial.read();
    if (c == '\n' || c == '\r') {
      typed.trim();
      if (typed.length() && modemBaud) {
        Serial.println(">> " + typed);
        String r = atWait(typed, 5000);
        r.trim();
        Serial.println(r);
      }
      typed = "";
    } else {
      typed += c;
    }
  }
  delay(5);
}
