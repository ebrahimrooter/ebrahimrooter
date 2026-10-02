// Host-side test: the sketch is compiled as C++ against stand-ins (Arduino.h etc.).
#include "glue.cpp"
#include "build/sim800_test.cpp"
int main() {
  setvbuf(stdout, nullptr, _IONBF, 0);
  FakeModem m; g_modem = &m; m.charset = "UCS2";
  m.slots[2] = {"BANK MELLAT", "بانک ملت\nواریز:5,000,000\nمانده:12,500,000\n0709-10:15", "26/10/01,10:15:40+14"};
  setup();
  m.deliver("+989121234567", "سلام، تست 😀");
  Serial.in = "AT+CSQ\n";
  for (int i = 0; i < 400; i++) loop();
  printf("\n\n######## same program, modem not connected ########\n");
  FakeModem dead; dead.alive = false; dead.reviveOnReset = false; g_modem = &dead; modemBaud = 0;
  setup();
}
