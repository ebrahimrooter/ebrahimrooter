#!/bin/sh
# HTML-only test build (no PHP): the phone web app and the accounting panel with a
# pretend server in the browser (mock.js + snapshot.js), plus the test lab page.
#   sh tools/test-build/build.sh SNAPSHOT.json OUT_DIR
# SNAPSHOT.json comes from record.js against the demo server (tools/demo/run.sh).
set -e
R=$(cd "$(dirname "$0")/../.." && pwd)
SNAP=${1:?snapshot.json}
OUT=${2:-$R/dist/test}
rm -rf "$OUT" && mkdir -p "$OUT/shots"
cp -r "$R/server/app" "$OUT/app"
cp -r "$R/server/acc" "$OUT/acc"
rm -rf "$OUT/app/android" "$OUT/acc/api.php" "$OUT/app/sw.js"
cp "$R/tools/test-build/mock.js" "$OUT/mock.js"
cp "$R/tools/test-build/lab.html" "$OUT/index.html"
printf 'window.__SNAPSHOT = %s;\n' "$(cat "$SNAP")" > "$OUT/snapshot.js"
# the pretend server loads before each app's own script
sed -i 's#<script src="app.js#<script src="../snapshot.js"></script><script src="../mock.js"></script>\n<script src="app.js#' "$OUT/app/index.html"
sed -i 's#<script src="js/app.js"></script>#<script src="../snapshot.js"></script><script src="../mock.js"></script><script src="js/app.js"></script>#' "$OUT/acc/index.html"
grep -q 'mock.js' "$OUT/app/index.html" && grep -q 'mock.js' "$OUT/acc/index.html"
# screenshots of the native apps
for f in 1-onboarding 2-home 3-report 5-assistant 6-confirm 8-list; do cp "$R/ios/screenshots/$f.png" "$OUT/shots/ios-$f.png"; done
cp "$R/android/screenshots/a4-orb-home.png" "$OUT/shots/and-orb-home.png"
cp "$R/android/screenshots/a3-orb-home-asking.png" "$OUT/shots/and-orb-small.png"
cp "$R/android/screenshots/a2-web-home.png" "$OUT/shots/and-web-home.png"
cat > "$OUT/README.txt" <<'TXT'
نسخه‌ی تست دستیار بانک و حسابداری — بدون PHP و بدون سرور

باز کردن:
  index.html را با Chrome یا Edge باز کن (دابل‌کلیک). همه‌چیز همین‌جاست:
  - وسط صفحه گوشی با وب‌اپ؛ پایین صفحه پنل حسابداری کامپیوتر.
  - شبیه‌ساز ESP32: پیامک بانک بفرست؛ orb در گوشی و ربات بله می‌پرسند «بابت چی بود؟».
  - شبیه‌ساز ربات بله: جواب بده تا در حسابداری ثبت شود.
  - رمز ورود اگر پرسید: test   (پنل: admin / test)

روی گوشی یا هاست بدون PHP:
  کل پوشه را روی هر هاست ساده (فقط فایل) آپلود کن و index.html را باز کن.

نکته‌ها:
  - اطلاعات نمونه است؛ هر تغییری فقط در همین مرورگر می‌ماند. «شروع دوباره» همه را برمی‌گرداند.
  - دستیار صوتی با میکروفون در Chrome کار می‌کند.
  - گزارش‌های پیچیده و سندهای حسابداری از نسخه‌ی واقعی گرفته شده‌اند و با تغییرات تو دوباره حساب نمی‌شوند.
TXT
echo "test build: $OUT"
