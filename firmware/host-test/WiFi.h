#pragma once
#include "Arduino.h"
#define WL_CONNECTED 3
#define WIFI_STA 1
struct IPAddress { String toString() const { return "192.168.1.50"; } };
struct WiFiClass {
  bool up = true;
  int status() { return up ? WL_CONNECTED : 0; }
  void mode(int) {}
  void begin(const char *, const char *) {}
  IPAddress localIP() { return IPAddress(); }
  int RSSI() { return -60; }
};
extern WiFiClass WiFi;
struct WiFiClient {};
