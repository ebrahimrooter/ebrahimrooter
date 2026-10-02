#include "fakemodem.h"
#include "HTTPClient.h"
unsigned long g_now = 0;
HostSerial Serial;
WiFiClass WiFi;
EspClass ESP;
FakeModem *g_modem = nullptr;
std::vector<Post> g_posts;
int g_server_code = 200;
int g_resets = 0;
struct Restart {};
static int rstLevel = 1;
void delay(unsigned long ms) { g_now += ms; }
void pinMode(int, int) {}
void digitalWrite(int pin, int v) {
  if (pin == 4) {
    if (rstLevel == 1 && v == 0) { g_resets++; if (g_modem->reviveOnReset) { g_modem->alive = true; g_modem->charset = "GSM"; g_modem->cnmiOk = false; } }
    rstLevel = v;
  }
}
void EspClass::restart() { throw Restart(); }
void HardwareSerial::begin(long baud, int, int, int) { g_modem->hostBaud = baud; }
int HardwareSerial::available() { return g_modem->rx.size(); }
int HardwareSerial::read() { if (g_modem->rx.empty()) return -1; int c = (unsigned char)g_modem->rx[0]; g_modem->rx.erase(0, 1); return c; }
void HardwareSerial::print(const String &x) { for (char c : x.std()) g_modem->fromHost(c); }
