#pragma once
// Stand-in for TinyGSM: same method names the sketch uses, backed by FakeModem.
// Like the real SIM800, the internet connection fails while the modem is in
// UCS2 mode (the APN parameter is then misread).
#include "Arduino.h"
#include "fakemodem.h"
struct FakeNet { bool coverage = true, gprs = false; std::string apn; int connects = 0; };
extern FakeNet g_net;
class TinyGsm {
 public:
  explicit TinyGsm(HardwareSerial &) {}
  bool init(const char * = nullptr) { return g_modem->alive; }
  bool isGprsConnected() { return g_net.gprs && g_modem->alive; }
  bool isNetworkConnected() { return g_net.coverage; }
  bool waitForNetwork(uint32_t timeout_ms = 60000L, bool = false) { if (!g_net.coverage) { delay(timeout_ms); return false; } return true; }
  int16_t getSignalQuality() { return 18; }
  bool gprsConnect(const char *apn, const char * = nullptr, const char * = nullptr) {
    g_net.connects++;
    if (!g_modem->alive || !g_net.coverage || g_modem->charset != "GSM") return false;
    g_net.gprs = true; g_net.apn = apn; return true;
  }
  bool gprsDisconnect() { g_net.gprs = false; return true; }
};
class TinyGsmClient {
 public:
  explicit TinyGsmClient(TinyGsm &) {}
};
