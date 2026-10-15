#!/bin/sh
# Emulator screenshots of the Android app against the demo server (tools/demo/run.sh on :8840).
# Run inside an emulator session (CI: reactivecircus/android-emulator-runner).
set -x
OUT=${1:-shots}
mkdir -p "$OUT"
PKG=ir.example.bankassistant
shot() { sleep "$2"; adb exec-out screencap -p > "$OUT/$1.png"; }
adb install -r android/app/build/outputs/apk/debug/app-debug.apk
adb shell appops set $PKG SYSTEM_ALERT_WINDOW allow
adb shell pm grant $PKG android.permission.RECORD_AUDIO
adb shell pm grant $PKG android.permission.POST_NOTIFICATIONS || true
adb shell settings put system screen_off_timeout 600000
adb shell input keyevent KEYCODE_WAKEUP
adb shell wm dismiss-keyguard || true

# Persian voice inside the app (no server): number rules and the Piper model
adb logcat -c
adb shell am start -W -n $PKG/.MainActivity --ez voice_test true
for i in $(seq 1 120); do adb logcat -d -s VOICE_TEST:* | grep -q "speech_text=" && break; sleep 2; done
adb logcat -d -s VOICE_TEST:* | tee "$OUT/voice-test.txt"
adb shell am force-stop $PKG

# first run: the server address
adb shell am start -W -n $PKG/.MainActivity
shot a1-setup 5
adb shell am force-stop $PKG

# the web app inside the app (signed in with the demo password; pairs the orb by itself)
adb shell am start -W -n $PKG/.MainActivity --es server http://10.0.2.2:8840/ --es demo_token demo12345
shot a2-web-home 14

# the floating orb: it polls, finds the unanswered bank transactions and asks
adb shell am force-stop $PKG
adb shell am start -W -n $PKG/.MainActivity --es server http://10.0.2.2:8840/ --ez demo_orb true
sleep 3
adb shell input keyevent KEYCODE_HOME
shot a3-orb-home-asking 6
shot a4-orb-home 14
adb shell am start -a android.settings.SETTINGS
shot a5-orb-over-settings 4
# the web app's orb card (settings)
adb shell am start -W -n $PKG/.MainActivity
sleep 2
adb shell input swipe 540 1700 540 400 300
shot a6-app-after 3
adb shell input keyevent KEYCODE_HOME
adb shell dumpsys activity services $PKG | grep -i -E "OrbService|isForeground" | head -5
adb logcat -d -s AndroidRuntime:E | tail -30

# the app without a server: books on the phone, the bank SMS straight from (a pretend) ESP32
CODE=TESTCODE2345ABCD
python3 tools/demo/fake_esp32.py publish $CODE 127.0.0.1 1883
python3 tools/demo/fake_esp32.py wait-ack $CODE 127.0.0.1 1883 120 esp000000000001 esp000000000002 > "$OUT/esp-ack.txt" 2>&1 &
ACK=$!
adb shell am force-stop $PKG
adb shell am start -W -n $PKG/.MainActivity --es link_code $CODE --es mqtt_url ws://10.0.2.2:9001
shot a7-local-home 20
wait $ACK
adb shell run-as $PKG cat files/local-books.json > "$OUT/local-books.json" || true
python3 - "$OUT/local-books.json" "$OUT/esp-ack.txt" <<'PY' | tee "$OUT/local-test.txt"
import json, sys
try:
    s = json.load(open(sys.argv[1]))
except Exception as e:
    print("local=fail no books file:", e); sys.exit(0)
t = {x["amount"]: x for x in s.get("txs", [])}
esp = open(sys.argv[2]).read()
ok = (2500000 in t and t[2500000]["direction"] == "out" and 7000000 in t and t[7000000]["direction"] == "in"
      and "esp=ok" in esp and (s.get("link") or {}).get("status", {}).get("sig") == 18)
print("local=" + ("ok" if ok else "fail"), "txs=", len(s.get("txs", [])), "|", esp.strip())
PY
grep -q "local=ok" "$OUT/local-test.txt" || { echo "LOCAL MODE TEST FAILED"; adb logcat -d | grep -iE "chromium|BankWeb|link" | grep -v "Mixed Content\|non-secure WebSocket" | tail -40; cat /tmp/mosquitto.log; exit 1; }

# on the Home Screen: a new bank SMS from the ESP32 → the floating orb books it and asks
adb shell input keyevent KEYCODE_HOME
sleep 4
adb logcat -c
python3 tools/demo/fake_esp32.py sms $CODE 127.0.0.1 1883 esp000000000003 "$(printf 'بانک صادرات\nبرداشت:1,200,000\nمانده:3,300,000\n1405/07/21 10:05')"
shot a8-local-orb-notice 8
shot a9-local-orb-asking 6
adb logcat -d -s BankOrb:* | tee "$OUT/local-orb.txt"
adb shell run-as $PKG cat files/local-books.json > "$OUT/local-books-2.json" || true
python3 - "$OUT/local-books-2.json" <<'PY' | tee -a "$OUT/local-orb.txt"
import json, sys
s = json.load(open(sys.argv[1]))
hit = [x for x in s.get("txs", []) if x.get("amount") == 1200000 and x.get("direction") == "out"]
print("orb=" + ("ok" if len(hit) == 1 else "fail"), "txs=", len(s.get("txs", [])))
PY
adb logcat -d -s AndroidRuntime:E | tail -30
grep -q "orb=ok" "$OUT/local-orb.txt" && grep -q "was booked" "$OUT/local-orb.txt" || { echo "LOCAL ORB TEST FAILED"; exit 1; }

# fail the job when the in-app voice does not work
grep -q "speech_text=5/5" "$OUT/voice-test.txt" && grep -q "tts=ok" "$OUT/voice-test.txt" && grep -q "stt=ok" "$OUT/voice-test.txt" || { echo "VOICE TEST FAILED"; exit 1; }
