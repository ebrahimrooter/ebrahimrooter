#pragma once
// Stand-in for PubSubClient: an MQTT broker in memory (retained messages, subscriptions),
// reachable only while the SIM800 has internet (g_net.gprs).
#include "Arduino.h"
#include "TinyGsmClient.h"
#include <map>
#include <vector>
#include <deque>
struct FakeBroker {
  bool up = true;
  int connects = 0;
  std::map<std::string, std::string> retained;
  std::vector<std::pair<std::string, std::string>> log;            // every publish (topic, payload)
  std::function<void(const std::string &, const std::string &)> onPublish;   // «the app»
};
extern FakeBroker g_broker;
inline bool mqttMatch(const std::string &f, const std::string &t) {
  if (f == t) return true;
  if (f.size() >= 2 && f.compare(f.size() - 2, 2, "/#") == 0) return t.rfind(f.substr(0, f.size() - 1), 0) == 0;
  return false;
}
class PubSubClient {
  void (*cb)(char *, uint8_t *, unsigned int) = nullptr;
  bool conn = false;
  std::vector<std::string> subs;
  std::deque<std::pair<std::string, std::string>> q;
 public:
  explicit PubSubClient(TinyGsmClient &) {}
  PubSubClient &setServer(const char *, uint16_t) { return *this; }
  PubSubClient &setCallback(void (*f)(char *, uint8_t *, unsigned int)) { cb = f; return *this; }
  bool setBufferSize(uint16_t) { return true; }
  bool connect(const char *) { g_broker.connects++; conn = g_net.gprs && g_broker.up; return conn; }
  bool connected() { return conn && g_net.gprs && g_broker.up; }
  int state() { return connected() ? 0 : -2; }
  bool subscribe(const char *f) {
    if (!connected()) return false;
    subs.push_back(f);
    for (auto &r : g_broker.retained) if (mqttMatch(f, r.first)) q.push_back(r);
    return true;
  }
  bool publish(const char *t, const char *p, bool retain = false) { return publish(t, (const uint8_t *)p, strlen(p), retain); }
  bool publish(const char *t, const uint8_t *p, unsigned int n, bool retain) {
    if (!connected()) return false;
    std::string s((const char *)p, n);
    if (retain) { if (n) g_broker.retained[t] = s; else g_broker.retained.erase(t); }
    g_broker.log.push_back({t, s});
    if (g_broker.onPublish) g_broker.onPublish(t, s);
    return true;
  }
  // a message from another client (the app) while we are subscribed
  void inject(const std::string &t, const std::string &s) { for (auto &f : subs) if (mqttMatch(f, t)) { q.push_back({t, s}); return; } }
  bool loop() {
    while (!q.empty() && cb && connected()) {
      auto m = q.front(); q.pop_front();
      std::vector<uint8_t> b(m.second.begin(), m.second.end());
      std::string t = m.first;
      cb((char *)t.c_str(), b.data(), b.size());
    }
    return connected();
  }
  void disconnect() { conn = false; subs.clear(); q.clear(); }
};
