// Host-side test: app_direct.ino against a simulated SIM800 + MQTT broker + «the app».
#include "glue.cpp"
#include "TinyGsmClient.h"
#include "PubSubClient.h"
FakeNet g_net;
FakeBroker g_broker;
#include "build/app_direct.cpp"
#include "build/app_fixture.h"
#include <unistd.h>
#include <fcntl.h>

static int fails = 0;
static int savedOut = -1;
void quiet() { fflush(stdout); if (savedOut < 0) savedOut = dup(1); int n = open("/dev/null", O_WRONLY); dup2(n, 1); close(n); }
void loud() { fflush(stdout); if (savedOut >= 0) dup2(savedOut, 1); }
#define CHECK(name, cond) do { bool _c = (cond); printf("  %s %s\n", _c ? "ok  " : "FAIL", name); if (!_c) fails++; } while (0)
void runLoop(unsigned long ms) { unsigned long end = g_now + ms; while (g_now < end) loop(); }

// «The app»: online or not; when online it acks every SMS it sees, like app/local/engine.js.
bool appOnline = true;
std::vector<std::string> appGot;
int retainedSms() { int n = 0; for (auto &r : g_broker.retained) if (r.first.find("/sms/") != std::string::npos) n++; return n; }

int main() {
  setvbuf(stdout, nullptr, _IONBF, 0);
  FakeModem m;
  g_modem = &m;
  PAIR_CODE = "testcode-2345-abcd";            // dashes and case don't matter
  FILE *sealedOut = fopen("build/sealed.txt", "w");
  g_broker.onPublish = [&](const std::string &t, const std::string &p) {
    if (t.find("/sms/") == std::string::npos || p.empty()) return;
    fprintf(sealedOut, "%s\n", p.c_str());
    if (!appOnline) return;
    String json;
    if (!openText(String(p), json)) return;
    int a = json.indexOf("\"id\":\"") + 6;
    std::string id = json.substring(a, json.indexOf('"', a)).std();
    appGot.push_back(json.std());
    std::string ids;
    for (auto &r : appGot) { int b = String(r).indexOf("\"id\":\"") + 6; ids += (ids.empty() ? "\"" : ",\"") + String(r).substring(b, String(r).indexOf('"', b)).std() + "\""; }
    std::string ack = sealText(String("{\"v\":1,\"ids\":[" + ids + "]}")).std();
    g_broker.retained[topicBase.std() + "ack"] = ack;
    mqtt.inject(topicBase.std() + "ack", ack);
  };

  printf("\n== 1. boot: two bank SMS + an operator SMS on the SIM, the app online ==\n");
  m.charset = "UCS2";
  m.slots[1] = {"Bank Mellat", "بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000", "26/10/02,00:40:50+14"};
  m.slots[2] = {"Irancell", "بسته اینترنت شما فعال شد", "x"};
  m.slots[3] = {"Bank Saderat", "بانک صادرات\nواریز:1,000,000", "26/10/02,00:41:00+14"};
  quiet(); setup(); runLoop(1000); loud();
  CHECK("topic made from the code, same as the app's", topicBase == APP_TOPIC);
  CHECK("internet came up (APN sent in GSM mode)", g_net.apn == "mcinet");
  CHECK("operator SMS deleted, never sent", !m.slots.count(2) && appGot.size() == 2);
  CHECK("the app got both bank SMS", appGot.size() == 2 && appGot[0].find("برداشت:2,500,000") != std::string::npos);
  CHECK("deleted from the SIM after the app's ack", m.slots.empty());
  CHECK("taken off the broker too (retained cleared)", retainedSms() == 0);
  CHECK("status for the app's settings", g_broker.retained.count(APP_TOPIC "st") == 1);
  CHECK("modem back in GSM mode after reading", m.charset == "GSM");
  { String st; openText(String(g_broker.retained[APP_TOPIC "st"]), st);
    FILE *f = fopen("build/status.txt", "w"); fprintf(f, "%s\n", g_broker.retained[APP_TOPIC "st"].c_str()); fclose(f);
    CHECK("status: signal and nothing waiting", st.indexOf("\"sig\":18") >= 0 && st.indexOf("\"wait\":0") >= 0); }

  printf("\n== 2. the phone is off: the SMS waits on the SIM and on the broker ==\n");
  appOnline = false;
  int before = g_broker.connects;
  m.deliver("Bank Melli", "بانک ملی\nواریز:7,000,000\nمانده:12,500,000");
  quiet(); runLoop(20000); loud();
  CHECK("put on the broker within ~15 s", retainedSms() == 1 && g_broker.retained.count(APP_TOPIC "sms/" APP_ACK_ID));
  CHECK("kept on the SIM (no ack yet)", m.slots.size() == 1);
  quiet(); runLoop(60000); loud();
  CHECK("does not stay online meanwhile (a few connections only)", g_broker.connects - before <= 2);

  printf("\n== 3. an ack sealed with another code does nothing ==\n");
  g_broker.retained[APP_TOPIC "ack"] = FOREIGN_ACK;
  quiet(); runLoop(130000); loud();
  CHECK("still on the SIM", m.slots.size() == 1);

  printf("\n== 4. the app comes back and acks (sealed by app/local/link.js) ==\n");
  g_broker.retained[APP_TOPIC "ack"] = APP_ACK;
  quiet(); runLoop(130000); loud();
  CHECK("deleted from the SIM once the app's ack was seen", m.slots.empty());
  CHECK("and off the broker", retainedSms() == 0);

  printf("\n== 5. no internet for a while ==\n");
  appOnline = true;
  g_net.coverage = false; g_net.gprs = false;
  m.deliver("Bank Mellat", "بانک ملت\nبرداشت:300,000");
  quiet(); runLoop(60000); loud();
  CHECK("kept on the SIM while offline", m.slots.size() == 1);
  g_net.coverage = true;
  quiet(); runLoop(20000); loud();
  CHECK("sent and deleted after the network came back", m.slots.empty() && appGot.size() == 3);

  printf("\n== 6. one-time password ==\n");
  m.deliver("Bank Mellat", "بانک ملت\nرمز پویا: 48213967");
  quiet(); runLoop(20000); loud();
  CHECK("OTP goes to the app too (FORWARD_OTP = true)", appGot.size() == 4 && appGot[3].find("48213967") != std::string::npos);
  fclose(sealedOut);

  printf(fails ? "\n%d FAILED\n" : "\nall passed\n", fails);
  return fails ? 1 : 0;
}
