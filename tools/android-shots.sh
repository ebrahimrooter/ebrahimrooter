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

# the app without a server: books on the phone, the bank SMS from (a pretend) Bale
adb shell am force-stop $PKG
adb shell am start -W -n $PKG/.MainActivity --es local_token 123456789:test-token-abcdef --es bale_base http://10.0.2.2:8841
shot a7-local-home 20
adb shell run-as $PKG cat files/local-books.json > "$OUT/local-books.json" || true
python3 - "$OUT/local-books.json" <<'PY' | tee "$OUT/local-test.txt"
import json, sys
try:
    s = json.load(open(sys.argv[1]))
except Exception as e:
    print("local=fail no books file:", e); sys.exit(0)
t = {x.get("bale_mid"): x for x in s.get("txs", [])}
a, b = t.get(10), t.get(11)
ok = (s.get("bale", {}).get("chat_id") == "555" and a and a["status"] == "pending" and a["amount"] == 2500000
      and b and b["status"] == "confirmed" and b.get("party") == "علی رضایی")
print("local=" + ("ok" if ok else "fail"), "chat=", s.get("bale", {}).get("chat_id"), "txs=", len(s.get("txs", [])), "last_error=", s.get("bale", {}).get("last_error"))
PY
grep -E "^(sendMessage|editMessageReplyMarkup|answerCallbackQuery|sendDocument)" /tmp/fakebale.log | cut -c1-120 | tee -a "$OUT/local-test.txt" || true
grep -q "local=ok" "$OUT/local-test.txt" || { echo "LOCAL MODE TEST FAILED"; exit 1; }

# fail the job when the in-app voice does not work
grep -q "speech_text=5/5" "$OUT/voice-test.txt" && grep -q "tts=ok" "$OUT/voice-test.txt" && grep -q "stt=ok" "$OUT/voice-test.txt" || { echo "VOICE TEST FAILED"; exit 1; }
