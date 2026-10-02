#pragma once
#include "Arduino.h"
#include <map>
#include <vector>
#include <string>

// UTF-8 -> UCS2 hex (UTF-16BE), like SIM800 in AT+CSCS="UCS2".
inline std::string toUcs2Hex(const std::string &u) {
  std::string out; char b[8];
  for (size_t i = 0; i < u.size();) {
    unsigned char c = u[i]; uint32_t cp; int n;
    if (c < 0x80) { cp = c; n = 1; } else if ((c >> 5) == 6) { cp = c & 0x1F; n = 2; }
    else if ((c >> 4) == 14) { cp = c & 0x0F; n = 3; } else { cp = c & 0x07; n = 4; }
    for (int k = 1; k < n; k++) cp = (cp << 6) | (u[i + k] & 0x3F);
    i += n;
    if (cp >= 0x10000) { cp -= 0x10000; snprintf(b, 8, "%04X", 0xD800 + (cp >> 10)); out += b; cp = 0xDC00 + (cp & 0x3FF); }
    snprintf(b, 8, "%04X", cp); out += b;
  }
  return out;
}

struct StoredSms { std::string sender, text, when; };

struct FakeModem {
  bool alive = true, reviveOnReset = true;
  std::vector<long> bauds = {9600, 19200, 38400, 57600, 115200};   // SIM800 auto-baud
  long hostBaud = 0;
  std::string charset = "GSM";
  bool cpmsOk = false, cnmiOk = false;
  std::map<int, StoredSms> slots;
  std::string rx, cmd;
  int capacity = 40;
  std::vector<std::string> log;

  std::string enc(const std::string &s) { return charset == "UCS2" ? toUcs2Hex(s) : s; }
  bool hearing() { if (!alive) return false; for (long b : bauds) if (b == hostBaud) return true; return false; }
  void ok(const std::string &body = "") { rx += body.empty() ? "\r\nOK\r\n" : "\r\n" + body + "\r\n\r\nOK\r\n"; }
  void err() { rx += "\r\nERROR\r\n"; }
  // A string parameter as the modem sees it: in UCS2 mode it must be hex.
  bool strArg(const std::string &raw, std::string &val) {
    if (charset != "UCS2") { val = raw; return true; }
    if (raw.size() % 4) return false;
    val.clear();
    for (size_t i = 0; i < raw.size(); i += 4) {
      int v = (int)strtol(raw.substr(i, 4).c_str(), nullptr, 16);
      if (!v || v > 0x7F) return false;
      val += (char)v;
    }
    return true;
  }
  int used() { return slots.size(); }
  std::string cpms() {
    std::string sm = "\"" + enc("SM") + "\",";
    std::string n = std::to_string(used()) + "," + std::to_string(capacity);
    return "+CPMS: " + sm + n + "," + sm + n + "," + sm + n;
  }
  void handle(const std::string &c) {
    log.push_back(c);
    if (c == "AT" || c == "ATE0" || c == "AT+CMGF=1" || c == "AT+CSDH=0") return ok();
    if (c == "ATI") return ok("SIM800 R14.18");
    if (c.rfind("AT+CSCS=\"", 0) == 0) {
      std::string v; if (!strArg(c.substr(9, c.size() - 10), v) || (v != "GSM" && v != "UCS2")) return err();
      charset = v; return ok();
    }
    if (c.rfind("AT+CPMS=\"", 0) == 0) {
      std::string v; if (!strArg(c.substr(9, 2 * (charset == "UCS2" ? 4 : 1)), v) || v != "SM") return err();
      cpmsOk = true; return ok(cpms());
    }
    if (c == "AT+CPMS?") return ok(cpms());
    if (c.rfind("AT+CNMI=", 0) == 0) { cnmiOk = true; return ok(); }
    if (c == "AT+CSQ") return ok("+CSQ: 18,0");
    if (c == "AT+CREG?") return ok("+CREG: 0,1");
    if (c == "AT+CBC") return ok("+CBC: 0,90,4012");
    if (c == "AT+CPIN?") return ok("+CPIN: READY");
    if (c == "AT+COPS?") return ok("+COPS: 0,0,\"IR-MCI\"");
    if (c.rfind("AT+CMGR=", 0) == 0) {
      int i = atoi(c.c_str() + 8);
      auto it = slots.find(i);
      if (it == slots.end()) return ok();
      const StoredSms &m = it->second;
      return ok("+CMGR: \"REC UNREAD\",\"" + enc(m.sender) + "\",\"\",\"" + m.when + "\"\r\n" + enc(m.text));
    }
    if (c.rfind("AT+CMGD=", 0) == 0) { slots.erase(atoi(c.c_str() + 8)); return ok(); }
    err();
  }
  void fromHost(char ch) {
    if (!hearing()) return;
    if (ch == '\r') { handle(cmd); cmd.clear(); } else cmd += ch;
  }
  // a new SMS arrives over the air
  void deliver(const std::string &sender, const std::string &text) {
    for (int i = 1; i <= capacity; i++) if (!slots.count(i)) {
      slots[i] = {sender, text, "26/10/01,10:00:00+14"};
      if (alive && cnmiOk) rx += "\r\n+CMTI: \"" + enc("SM") + "\"," + std::to_string(i) + "\r\n";
      return;
    }
  }
  // brown-out: the module restarts and forgets its settings
  void reboot() { charset = "GSM"; cnmiOk = false; rx += "\r\nRDY\r\n\r\n+CFUN: 1\r\n\r\n+CPIN: READY\r\n\r\nCall Ready\r\n\r\nSMS Ready\r\n"; }
};
