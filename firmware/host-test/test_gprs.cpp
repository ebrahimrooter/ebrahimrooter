// Host-side test: gprs_forwarder.ino against a simulated SIM800 whose
// "internet" reaches the REAL accounting program (server/api.php started by
// run.sh) - the whole chain ESP32 -> host -> Bale question is exercised.
#include "glue.cpp"
#include "ESP_SSLClient.h"
FakeNet g_net;
FakeBale g_bale;
FakeHost g_host;
#include "build/gprs_forwarder.cpp"
#include <unistd.h>
#include <fcntl.h>

static int fails = 0;
static int savedOut = -1;
void quiet() { fflush(stdout); if (savedOut < 0) savedOut = dup(1); int n = open("/dev/null", O_WRONLY); dup2(n, 1); close(n); }
void loud() { fflush(stdout); if (savedOut >= 0) dup2(savedOut, 1); }
#define CHECK(name, cond) do { bool _c = (cond); printf("  %s %s\n", _c ? "ok  " : "FAIL", name); if (!_c) fails++; } while (0)
void runLoop(unsigned long ms) { unsigned long end = g_now + ms; while (g_now < end) loop(); }

// Ask the real accounting program (app side of the API).
std::string appApi(const std::string &route) {
  std::string cmd = std::string("curl -s -H 'X-App-Token: ") + getenv("APP_TOKEN") + "' '" + getenv("REAL_API") + "?r=" + route + "'";
  std::string out; char buf[4096]; size_t n;
  FILE *p = popen(cmd.c_str(), "r");
  while ((n = fread(buf, 1, sizeof buf, p)) > 0) out.append(buf, n);
  pclose(p);
  return out;
}
int count(const std::string &hay, const std::string &needle) {
  int c = 0; for (size_t p = hay.find(needle); p != std::string::npos; p = hay.find(needle, p + 1)) c++; return c;
}
std::string baleLog() {
  FILE *f = fopen(getenv("BALE_LOG"), "r"); if (!f) return "";
  std::string out; char buf[4096]; size_t n;
  while ((n = fread(buf, 1, sizeof buf, f)) > 0) out.append(buf, n);
  fclose(f); return out;
}

int main() {
  setvbuf(stdout, nullptr, _IONBF, 0);
  if (!getenv("REAL_API")) { printf("run via run.sh (needs REAL_API)\n"); return 1; }
  FakeModem m;
  g_modem = &m;
  SERVER_URL = "https://example.com/bank/api.php";
  DEVICE_TOKEN = "DEVTOKEN";
  const std::string sms1 = "حساب1234567890\nواریز50,000\nمانده12,345,670\n05/07/10-00:40";
  const std::string sms2 = "حساب1234567890\nبرداشت300,000\nمانده12,045,670\n05/07/10-11:05";

  printf("\n== 1. boot: bank SMS + operator SMS waiting ==\n");
  m.charset = "UCS2";
  m.slots[1] = {"Bank Mellat", sms1, "26/10/02,00:40:50+14"};
  m.slots[2] = {"Irancell", "بسته اینترنت", "x"};
  quiet(); setup(); loud();
  std::string pend = appApi("pending");
  CHECK("URL parsed: https example.com:443 /bank/api.php", srvHttps && srvHost == "example.com" && srvPort == 443 && srvPath == "/bank/api.php");
  CHECK("operator SMS deleted on the device, never sent", !m.slots.count(2) && count(pend, "بسته") == 0);
  CHECK("bank SMS booked in the real accounting program", count(pend, "\"amount\":50000") == 1);
  CHECK("deleted from SIM after the host said ok", m.slots.empty());
  CHECK("host asked in Bale: 'what was it for?'", baleLog().find("بابت چی بود") != std::string::npos);
  std::string me = appApi("me");
  CHECK("heartbeat reached the host (signal 18 shown in the app)", me.find("\"signal\":18") != std::string::npos);

  printf("\n== 2. a withdrawal arrives while running ==\n");
  m.deliver("Bank Mellat", sms2);
  quiet(); runLoop(12000); loud();
  pend = appApi("pending");
  CHECK("booked within ~10 s, fully automatic", count(pend, "\"amount\":300000") == 1 && m.slots.empty());

  printf("\n== 3. same SMS delivered twice by the modem ==\n");
  m.deliver("Bank Mellat", sms2);
  quiet(); runLoop(12000); loud();
  CHECK("host keeps one transaction, device clears the SIM", count(appApi("pending"), "\"amount\":300000") == 1 && m.slots.empty());

  printf("\n== 4. host unreachable ==\n");
  g_host.up = false;
  const std::string sms3 = "حساب1234567890\nواریز1,000,000\nمانده13,045,670\n05/07/10-12:00";
  m.deliver("Bank Mellat", sms3);
  quiet(); runLoop(60000); loud();
  CHECK("kept on the SIM while the host is down", m.slots.size() == 1);
  g_host.up = true;
  quiet(); runLoop(15000); loud();
  CHECK("delivered when the host is back", m.slots.empty() && count(appApi("pending"), "\"amount\":1000000") == 1);

  printf("\n== 5. wrong DEVICE_TOKEN ==\n");
  DEVICE_TOKEN = "WRONG";
  m.deliver("Bank Mellat", "حساب1234567890\nواریز20,000\nمانده13,065,670\n05/07/10-13:00");
  quiet(); runLoop(12000); loud();
  CHECK("real API says 401 -> kept on SIM", m.slots.size() == 1);
  DEVICE_TOKEN = "DEVTOKEN";
  quiet(); runLoop(12000); loud();
  CHECK("sent after fixing the token", m.slots.empty());

  printf("\n== 6. four banks: Melli, Saderat, Blu also go on; each lands on its own card ==\n");
  m.deliver("Bank Melli", "بانک ملی ایران\nواریز به حساب 0101234567001\nمبلغ: 7,000,000 ریال\nمانده: 20,000,000\n1405/07/10 14:00");
  m.deliver("SADERAT", "بانک صادرات\nبرداشت از حساب 0201234\nمبلغ 650,000 ریال\n1405/07/10 14:05");
  m.deliver("blubank", "بلو\nخرید با کارت: 320,000 ریال\nمانده: 4,180,000 ریال");
  m.deliver("MCI", "هدیه همراه اول");
  quiet(); runLoop(30000); loud();
  pend = appApi("pending");
  CHECK("Melli, Saderat and Blu SMS forwarded, operator SMS deleted", m.slots.empty() && count(pend, "هدیه") == 0);
  CHECK("Melli deposit on the Melli card", pend.find("\"amount\":7000000") != std::string::npos && pend.find("\"wallet_name\":\"بانک ملی\"") != std::string::npos);
  CHECK("Saderat and Blu on their cards", pend.find("\"wallet_name\":\"بانک صادرات\"") != std::string::npos && pend.find("\"wallet_name\":\"بلو بانک\"") != std::string::npos);

  printf("\n== 7. plain http and custom port ==\n");
  SERVER_URL = "http://example.com:8080/x/api.php";
  parseServerUrl();
  CHECK("http://host:8080/x/api.php parsed", !srvHttps && srvHost == "example.com" && srvPort == 8080 && srvPath == "/x/api.php");
  SERVER_URL = "example.com/api.php";
  CHECK("missing https:// is rejected", !parseServerUrl());

  printf(fails ? "\n%d FAILED\n" : "\nall passed\n", fails);
  return fails ? 1 : 0;
}
