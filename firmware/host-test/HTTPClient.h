#pragma once
#include "WiFi.h"
#include <vector>
struct Post { std::string url, body, token; };
extern std::vector<Post> g_posts;
extern int g_server_code;
class HTTPClient {
  std::string url, token, resp;
 public:
  bool begin(WiFiClient &, const char *u) { url = u; return true; }
  void addHeader(const char *k, const char *v) { if (!strcmp(k, "X-Device-Token")) token = v; }
  void setTimeout(int) {}
  int POST(const String &body) {
    if (g_server_code < 0) return -1;
    g_posts.push_back({url, body.std(), token});
    resp = g_server_code == 200 ? "{\"ok\":true}" : "{\"ok\":false}";
    return g_server_code;
  }
  String getString() { return String(resp); }
  void end() {}
};
