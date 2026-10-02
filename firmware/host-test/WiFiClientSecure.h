#pragma once
#include "WiFi.h"
struct WiFiClientSecure : WiFiClient { void setInsecure() {} };
