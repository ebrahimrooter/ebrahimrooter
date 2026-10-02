// Host-side test: bale_direct.ino against a simulated SIM800 + Bale API.
#include "glue.cpp"
#include "ESP_SSLClient.h"
FakeNet g_net;
FakeBale g_bale;
FakeHost g_host;
#include "build/bale_direct.cpp"
#include <unistd.h>
#include <fcntl.h>

static int fails = 0;
static int savedOut = -1;
void quiet() { fflush(stdout); if (savedOut < 0) savedOut = dup(1); int n = open("/dev/null", O_WRONLY); dup2(n, 1); close(n); }
void loud() { fflush(stdout); if (savedOut >= 0) dup2(savedOut, 1); }
#define CHECK(name, cond) do { bool _c = (cond); printf("  %s %s\n", _c ? "ok  " : "FAIL", name); if (!_c) fails++; } while (0)
void runLoop(unsigned long ms) { unsigned long end = g_now + ms; while (g_now < end) loop(); }
bool sentContains(const std::string &s) { for (auto &m : g_bale.sent) if (m.text.find(s) != std::string::npos) return true; return false; }

int main() {
  setvbuf(stdout, nullptr, _IONBF, 0);
  FakeModem m;
  g_modem = &m;
  BALE_TOKEN = "T0K3N";
  // The user's real SMS format (account number and balance changed).
  const std::string bankSms = "حساب1234567890\nواریز50,000\nمانده12,345,670\n05/07/10-00:40";
  const std::string otpSms = "بانک ملت\nرمز پویا: 48213967\nمبلغ: 1,250,000 ریال";

  printf("\n== 1. first run, no BALE_CHAT_ID yet ==\n");
  m.charset = "UCS2";                                   // left over from an earlier sketch
  m.slots[1] = {"Bank Mellat", bankSms, "26/10/02,00:40:50+14"};
  m.slots[2] = {"Irancell", "بسته اینترنت شما فعال شد", "x"};
  g_bale.updates.push_back({41, "555"});
  quiet(); setup(); runLoop(15000); loud();
  CHECK("internet came up (APN sent while modem in GSM mode)", g_net.gprs && g_net.apn == "mcinet");
  CHECK("replied to /start with the chat id", g_bale.sent.size() >= 1 && g_bale.sent[0].chat == "555" && g_bale.sent[0].text.find("555") != std::string::npos);
  CHECK("did not answer the same /start twice", g_bale.sent.size() == 1);
  CHECK("Irancell SMS deleted, never sent", !m.slots.count(2) && !sentContains("بسته"));
  CHECK("bank SMS kept on SIM until chat id is set", m.slots.count(1) == 1);

  printf("\n== 2. chat id set (after re-upload) ==\n");
  g_bale.sent.clear();
  BALE_CHAT_ID = "555";
  quiet(); setup(); loud();
  CHECK("'device on' message", sentContains("روشن شد"));
  CHECK("bank SMS sent to Bale", sentContains("حساب1234567890"));
  CHECK("summary: 50,000 rial = 5,000 toman deposit", sentContains("🟢 واریز 5,000 تومان"));
  CHECK("summary: balance in toman", sentContains("مانده: 1,234,567 تومان"));
  CHECK("sent to the right chat", g_bale.sent.back().chat == "555");
  CHECK("deleted from SIM after Bale said ok", m.slots.empty());
  CHECK("modem back in GSM mode after reading (internet keeps working)", m.charset == "GSM");
  {
    const BaleMsg *tx = nullptr;
    for (auto &b : g_bale.sent) if (b.text.find("حساب1234567890") != std::string::npos) tx = &b;
    CHECK("asks 'what was it for?' under the SMS", tx && tx->text.find("بابت چی بود؟") != std::string::npos);
    bool between = false;
    if (tx) {
      size_t a = tx->text.find("──────────");
      size_t b = a == std::string::npos ? a : tx->text.find("──────────", a + 1);
      between = b != std::string::npos && tx->text.substr(a, b - a).find("واریز50,000") != std::string::npos;
    }
    CHECK("SMS sits between two separators (the accounting program reads it there)", between);
    CHECK("buttons: record / ignore", tx && tx->raw.find("relay:ans") != std::string::npos && tx->raw.find("relay:ign") != std::string::npos);
    CHECK("boot message has no buttons", g_bale.sent.size() && g_bale.sent[0].raw.find("inline_keyboard") == std::string::npos);
    // Hand the exact message to the PHP test of the accounting program.
    if (tx) { FILE *f = fopen("build/relay_message.txt", "w"); if (f) { fputs(tx->text.c_str(), f); fclose(f); } }
  }

  printf("\n== 3. new SMS while running ==\n");
  g_bale.sent.clear();
  m.deliver("BANK MELLAT", "حساب1234567890\nبرداشت2,500,000\nمانده12,095,670\n05/07/10-09:15");
  quiet(); runLoop(12000); loud();
  CHECK("withdrawal sent within ~10 s (sender case/spaces ignored)", sentContains("🔴 برداشت 250,000 تومان"));
  m.deliver("Bank Mellat", otpSms);
  quiet(); runLoop(12000); loud();
  CHECK("OTP sent with a 🔐 heading (FORWARD_OTP = true)", sentContains("🔐 رمز یکبار مصرف") && sentContains("48213967"));
  CHECK("no buttons and no question under an OTP", g_bale.sent.back().raw.find("inline_keyboard") == std::string::npos && g_bale.sent.back().text.find("بابت چی بود") == std::string::npos);

  printf("\n== 4. no internet for a while ==\n");
  g_bale.sent.clear();
  g_net.gprs = false; g_net.coverage = false;
  m.deliver("Bank Mellat", bankSms);
  quiet(); runLoop(120000); loud();
  CHECK("kept on the SIM while offline", m.slots.size() == 1 && g_bale.sent.empty());
  g_net.coverage = true;
  quiet(); runLoop(15000); loud();
  CHECK("sent after the network came back", m.slots.empty() && sentContains("حساب1234567890"));

  printf("\n== 5. wrong token ==\n");
  g_bale.sent.clear();
  BALE_TOKEN = "WRONG";
  m.deliver("Bank Mellat", bankSms);
  quiet(); runLoop(12000); loud();
  CHECK("rejected -> kept on SIM", m.slots.size() == 1);
  BALE_TOKEN = "T0K3N";
  quiet(); runLoop(12000); loud();
  CHECK("sent once the token is right", m.slots.empty());

  printf("\n== 6. what if the modem stayed in UCS2 after reading? ==\n");
  m.charset = "UCS2"; g_net.gprs = false;
  CHECK("then the internet would not connect (the trap is real)", !gsm.gprsConnect(APN));
  m.charset = "GSM";

  printf(fails ? "\n%d FAILED\n" : "\nall passed\n", fails);
  return fails ? 1 : 0;
}
