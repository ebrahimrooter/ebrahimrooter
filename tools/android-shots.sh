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
