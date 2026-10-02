#pragma once
// Stand-in for ESP_SSLClient talking to a simulated Bale bot API.
#include "TinyGsmClient.h"
#include <vector>
struct BaleMsg { std::string chat, text, raw; };
struct FakeBale {
  bool up = true;
  std::string token = "T0K3N";
  std::vector<BaleMsg> sent;
  std::vector<std::pair<long, std::string>> updates;   // update_id, chat id
  std::string lastPath;
  static std::string jsonField(const std::string &body, const std::string &key) {
    size_t p = body.find("\"" + key + "\":");
    if (p == std::string::npos) return "";
    p += key.size() + 3;
    std::string out;
    if (body[p] != '"') { while (p < body.size() && (isdigit((unsigned char)body[p]) || body[p] == '-')) out += body[p++]; return out; }
    for (p++; p < body.size() && body[p] != '"'; p++) {
      if (body[p] == '\\' && p + 1 < body.size()) { p++; out += body[p] == 'n' ? '\n' : body[p]; } else out += body[p];
    }
    return out;
  }
  std::string handle(const std::string &req) {
    size_t sp = req.find(' '), sp2 = req.find(' ', sp + 1);
    lastPath = req.substr(sp + 1, sp2 - sp - 1);
    std::string body = req.substr(req.find("\r\n\r\n") + 4);
    auto reply = [](int code, const std::string &b) {
      return "HTTP/1.1 " + std::to_string(code) + (code == 200 ? " OK" : " ERR") + "\r\nContent-Type: application/json\r\nContent-Length: " +
             std::to_string(b.size()) + "\r\nConnection: close\r\n\r\n" + b;
    };
    if (lastPath.rfind("/bot" + token + "/", 0) != 0) return reply(401, "{\"ok\":false,\"description\":\"Unauthorized\"}");
    std::string method = lastPath.substr(5 + token.size());
    if (method == "sendMessage") {
      sent.push_back({jsonField(body, "chat_id"), jsonField(body, "text"), body});
      return reply(200, "{\"ok\":true,\"result\":{\"message_id\":" + std::to_string(sent.size()) + "}}");
    }
    if (method == "getUpdates") {
      long off = atol(jsonField(body, "offset").c_str());
      std::string r = "{\"ok\":true,\"result\":[";
      bool first = true;
      for (auto &u : updates) if (u.first >= off) {
        if (!first) r += ",";
        first = false;
        r += "{\"update_id\":" + std::to_string(u.first) + ",\"message\":{\"message_id\":1,\"from\":{\"id\":999,\"is_bot\":false},\"chat\":{\"id\":" + u.second + ",\"type\":\"private\"},\"text\":\"/start\"}}";
      }
      return reply(200, r + "]}");
    }
    return reply(404, "{\"ok\":false}");
  }
};
extern FakeBale g_bale;
// Any other host: the accounting program. If REAL_API is set (run.sh starts
// PHP's built-in server), requests really go to server/api.php via curl.
struct FakeHost {
  bool up = true;
  std::string host = "example.com";
  std::vector<std::string> requests;
  std::string handle(const std::string &req) {
    requests.push_back(req);
    size_t sp = req.find(' '), sp2 = req.find(' ', sp + 1);
    std::string path = req.substr(sp + 1, sp2 - sp - 1);
    std::string body = req.substr(req.find("\r\n\r\n") + 4);
    std::string token;
    size_t t = req.find("X-Device-Token: ");
    if (t != std::string::npos) token = req.substr(t + 16, req.find("\r\n", t) - t - 16);
    const char *real = getenv("REAL_API");
    if (real) {
      std::string q = path.substr(path.find('?'));
      FILE *f = fopen("build/req_body.txt", "w"); fputs(body.c_str(), f); fclose(f);
      std::string cmd = std::string("curl -s -i -X POST -H 'X-Device-Token: ") + token +
        "' -H 'Content-Type: application/x-www-form-urlencoded' --data-binary @build/req_body.txt '" + real + q + "'";
      std::string out; char buf[4096];
      FILE *p = popen(cmd.c_str(), "r");
      size_t n; while ((n = fread(buf, 1, sizeof buf, p)) > 0) out.append(buf, n);
      pclose(p);
      return out;
    }
    std::string b = token == "DEVTOKEN" ? "{\"ok\":true}" : "{\"ok\":false}";
    return std::string("HTTP/1.1 ") + (token == "DEVTOKEN" ? "200 OK" : "401 Unauthorized") + "\r\nContent-Length: " + std::to_string(b.size()) + "\r\n\r\n" + b;
  }
};
extern FakeHost g_host;
class ESP_SSLClient {
  std::string req, resp;
  size_t pos = 0;
  bool open = false, ssl = true, toBale = true;
 public:
  int lastPort = 0;
  void setInsecure() {}
  void setBufferSizes(int, int) {}
  void setClient(TinyGsmClient *, bool enableSSL = true) { ssl = enableSSL; }
  int connect(const char *host, uint16_t port) {
    req.clear(); resp.clear(); pos = 0; lastPort = port;
    toBale = std::string(host) == "tapi.bale.ai";
    if (toBale) open = g_net.gprs && g_bale.up && port == 443 && ssl;
    else open = g_net.gprs && g_host.up && std::string(host) == g_host.host && port == (ssl ? 443 : 80);
    return open;
  }
  void print(const String &s) {
    if (!open) return;
    req += s.std();
    size_t h = req.find("\r\n\r\n");
    if (h == std::string::npos) return;
    size_t cl = req.find("Content-Length: ");
    size_t need = cl == std::string::npos ? 0 : (size_t)atol(req.c_str() + cl + 16);
    if (req.size() - h - 4 >= need && resp.empty()) resp = toBale ? g_bale.handle(req) : g_host.handle(req);
  }
  int available() { return resp.size() - pos; }
  int read() { return pos < resp.size() ? (unsigned char)resp[pos++] : -1; }
  bool connected() { return open && pos < resp.size(); }
  void stop() { open = false; }
};
