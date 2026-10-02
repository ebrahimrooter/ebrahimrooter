// Minimal host-side stand-in for the Arduino/ESP32 core, enough to compile
// and run the sketches against a simulated SIM800.
#pragma once
#include <string>
#include <cstring>
#include <cstdio>
#include <cstdarg>
#include <cstdint>
#include <cctype>
#include <cstdlib>
#include <deque>
#include <functional>

class String {
  std::string s;
 public:
  String() {}
  String(const char *c) : s(c ? c : "") {}
  String(const std::string &x) : s(x) {}
  String(char c) : s(1, c) {}
  String(int v) : s(std::to_string(v)) {}
  String(unsigned int v) : s(std::to_string(v)) {}
  String(long v) : s(std::to_string(v)) {}
  String(unsigned long v) : s(std::to_string(v)) {}
  unsigned int length() const { return s.size(); }
  const char *c_str() const { return s.c_str(); }
  char operator[](unsigned int i) const { return i < s.size() ? s[i] : 0; }
  String &operator+=(const String &o) { s += o.s; return *this; }
  String &operator+=(const char *o) { s += o; return *this; }
  String &operator+=(char c) { s += c; return *this; }
  friend String operator+(const String &a, const String &b) { return String(a.s + b.s); }
  friend String operator+(const String &a, const char *b) { return String(a.s + b); }
  friend String operator+(const char *a, const String &b) { return String(std::string(a) + b.s); }
  bool operator==(const String &o) const { return s == o.s; }
  bool operator!=(const String &o) const { return s != o.s; }
  int indexOf(char c, unsigned int from = 0) const { auto p = s.find(c, from); return p == std::string::npos ? -1 : (int)p; }
  int indexOf(const String &x, unsigned int from = 0) const { auto p = s.find(x.s, from); return p == std::string::npos ? -1 : (int)p; }
  int indexOf(const char *x, unsigned int from = 0) const { auto p = s.find(x, from); return p == std::string::npos ? -1 : (int)p; }
  int lastIndexOf(char c) const { auto p = s.rfind(c); return p == std::string::npos ? -1 : (int)p; }
  int lastIndexOf(const char *x) const { auto p = s.rfind(x); return p == std::string::npos ? -1 : (int)p; }
  String substring(unsigned int a) const { return a >= s.size() ? String() : String(s.substr(a)); }
  String substring(unsigned int a, unsigned int b) const { if (a > b) std::swap(a, b); if (a >= s.size()) return String(); return String(s.substr(a, b - a)); }
  bool startsWith(const char *p) const { return s.rfind(p, 0) == 0; }
  bool endsWith(const char *p) const { size_t n = strlen(p); return s.size() >= n && s.compare(s.size() - n, n, p) == 0; }
  void trim() { size_t a = s.find_first_not_of(" \t\r\n"); if (a == std::string::npos) { s.clear(); return; } size_t b = s.find_last_not_of(" \t\r\n"); s = s.substr(a, b - a + 1); }
  void replace(const char *f, const char *t) { std::string F(f), T(t); size_t p = 0; while ((p = s.find(F, p)) != std::string::npos) { s.replace(p, F.size(), T); p += T.size(); } }
  long toInt() const { return atol(s.c_str()); }
  const std::string &std() const { return s; }
};

extern unsigned long g_now;
inline unsigned long millis() { return g_now; }
void delay(unsigned long ms);
inline bool isDigit(char c) { return c >= '0' && c <= '9'; }
#define HIGH 1
#define LOW 0
#define OUTPUT 1
#define SERIAL_8N1 0
void pinMode(int, int);
void digitalWrite(int, int);

struct HostSerial {
  std::string in;
  void begin(long) {}
  int available() { return in.size(); }
  int read() { if (in.empty()) return -1; int c = (unsigned char)in[0]; in.erase(0, 1); return c; }
  void print(const String &x) { fputs(x.c_str(), stdout); }
  void print(const char *x) { fputs(x, stdout); }
  void println(const String &x = String()) { puts(x.c_str()); }
  void println(const char *x) { puts(x); }
  void printf(const char *f, ...) { va_list a; va_start(a, f); vprintf(f, a); va_end(a); }
};
extern HostSerial Serial;

// Simulated SIM800 lives behind this UART.
struct FakeModem;
extern FakeModem *g_modem;
class HardwareSerial {
 public:
  explicit HardwareSerial(int) {}
  void begin(long baud, int, int, int);
  void end() {}
  int available();
  int read();
  void print(const String &x);
  void print(const char *x) { print(String(x)); }
};

struct EspClass { void restart(); };
extern EspClass ESP;
