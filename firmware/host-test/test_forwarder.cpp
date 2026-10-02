// Host-side test: the sketch is compiled as C++ against stand-ins (Arduino.h etc.).
#include "glue.cpp"
#include "build/sms_forwarder.cpp"

#include <unistd.h>
#include <fcntl.h>
static int fails = 0;
static int savedOut = -1;
void quiet() { fflush(stdout); if (savedOut < 0) savedOut = dup(1); int n = open("/dev/null", O_WRONLY); dup2(n, 1); close(n); }
void loud() { fflush(stdout); if (savedOut >= 0) dup2(savedOut, 1); }
#define CHECK(name, cond) do { bool _c = (cond); printf("  %s %s\n", _c ? "ok  " : "FAIL", name); if (!_c) fails++; } while (0)

std::string urlDecode(const std::string &s) {
  std::string o;
  for (size_t i = 0; i < s.size(); i++) {
    if (s[i] == '%' && i + 2 < s.size()) { o += (char)strtol(s.substr(i + 1, 2).c_str(), nullptr, 16); i += 2; }
    else o += s[i] == '+' ? ' ' : s[i];
  }
  return o;
}
std::string field(const std::string &body, const std::string &k) {
  size_t p = body.find(k + "=");
  if (p == std::string::npos) return "";
  p += k.size() + 1;
  return urlDecode(body.substr(p, body.find('&', p) - p));
}
std::vector<Post> smsPosts() { std::vector<Post> v; for (auto &p : g_posts) if (p.url.find("ingest") != std::string::npos) v.push_back(p); return v; }
void runLoop(unsigned long ms) { unsigned long end = g_now + ms; while (g_now < end) loop(); }

int main() {
  setvbuf(stdout, nullptr, _IONBF, 0);
  FakeModem m;
  g_modem = &m;
  const std::string bank = "BANK MELLAT";
  const std::string t1 = "بانک ملت\nبرداشت:2,500,000\nحساب:1234\nمانده:7,500,000\n1405/07/09-18:40";
  const std::string t2 = "رمز پویا: 48213967 😀";

  printf("\n== 1. boot: modem left in UCS2 from before, 2 SMS waiting on the SIM ==\n");
  m.charset = "UCS2";
  m.slots[1] = {bank, t1, "26/10/01,18:40:02+14"};
  m.slots[3] = {"+989121234567", "سلام", "26/10/01,18:41:00+14"};
  quiet();
  setup();
  loud();
  auto ps = smsPosts();
  CHECK("storage selected despite stale UCS2 mode (AT+CPMS ok)", m.cpmsOk);
  CHECK("ends in UCS2 mode", m.charset == "UCS2");
  CHECK("new-SMS notices enabled", m.cnmiOk);
  CHECK("both SMS posted", ps.size() == 2);
  CHECK("text decodes to the exact Persian SMS", ps.size() && field(ps[0].body, "text_hex") == toUcs2Hex(t1));
  CHECK("alphanumeric sender sent as UCS2 hex", ps.size() && field(ps[0].body, "sender") == toUcs2Hex(bank));
  CHECK("modem time sent", ps.size() && field(ps[0].body, "modem_time") == "26/10/01,18:40:02+14");
  CHECK("device token header", ps.size() && ps[0].token == DEVICE_TOKEN);
  CHECK("SIM emptied after server OK", m.slots.empty());
  CHECK("heartbeat sent at boot", g_posts.size() == 3 && g_posts[2].url.find("heartbeat") != std::string::npos);
  CHECK("heartbeat carries signal 18", g_posts.back().body.find("csq=18") != std::string::npos);

  printf("\n== 2. new SMS arrives (+CMTI) - forwarded right away ==\n");
  g_posts.clear();
  m.deliver(bank, t2);
  quiet(); runLoop(2000); loud();
  ps = smsPosts();
  CHECK("forwarded within 2 s", ps.size() == 1);
  CHECK("emoji (surrogate pair) kept intact", ps.size() && field(ps[0].body, "text_hex") == toUcs2Hex(t2));

  printf("\n== 3. computer switched off: SMS must wait on the SIM ==\n");
  g_posts.clear();
  g_server_code = -1;
  m.deliver(bank, t1);
  quiet(); runLoop(60000); loud();
  CHECK("kept on the SIM while server is down", m.slots.size() == 1);
  g_server_code = 200;
  quiet(); runLoop(20000); loud();
  CHECK("sent once the computer is back (safety-net scan)", smsPosts().size() == 1 && m.slots.empty());

  printf("\n== 4. wrong DEVICE_TOKEN (server says 401) ==\n");
  g_posts.clear();
  g_server_code = 401;
  m.deliver(bank, t1);
  quiet(); runLoop(5000); loud();
  CHECK("not deleted when rejected", m.slots.size() == 1);
  g_server_code = 200;
  quiet(); runLoop(20000); loud();
  CHECK("delivered after fixing", m.slots.empty());

  printf("\n== 5. modem restarts by itself (power dip) ==\n");
  g_posts.clear();
  m.reboot();
  quiet(); runLoop(3000); loud();
  CHECK("set up again: UCS2 + notices", m.charset == "UCS2" && m.cnmiOk);
  m.deliver(bank, t1);
  quiet(); runLoop(2000); loud();
  CHECK("SMS after the restart decoded right", smsPosts().size() == 1 && field(smsPosts()[0].body, "text_hex") == toUcs2Hex(t1));

  printf("\n== 6. modem freezes: ESP32 resets it via RST pin ==\n");
  g_posts.clear();
  m.alive = false;
  int r0 = g_resets;
  quiet(); runLoop(70000); loud();
  CHECK("hardware reset done", g_resets > r0);
  CHECK("modem back in UCS2 with notices", m.alive && m.charset == "UCS2" && m.cnmiOk);
  m.deliver(bank, t1);
  quiet(); runLoop(2000); loud();
  CHECK("works again after reset", m.slots.empty());

  printf("\n== 7. modem only answers at 115200 (baud set fixed earlier) ==\n");
  FakeModem m2; m2.bauds = {115200}; g_modem = &m2; g_posts.clear();
  m2.slots[1] = {bank, t1, "x"};
  quiet(); setup(); loud();
  CHECK("found it at 115200 and forwarded", modemBaud == 115200 && smsPosts().size() == 1);

  printf("\n== 8. no modem at all ==\n");
  FakeModem m3; m3.alive = false; m3.reviveOnReset = false; g_modem = &m3;
  bool restarted = false;
  quiet();
  try { setup(); } catch (Restart &) { restarted = true; }
  loud();
  CHECK("ESP32 restarts itself instead of hanging", restarted);

  printf(fails ? "\n%d FAILED\n" : "\nall passed\n", fails);
  return fails ? 1 : 0;
}
