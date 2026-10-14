#!/bin/sh
# The phone web app with its own books (no server): server/app + app/local/*.js,
# for the Android app (assets/www) and the iPhone app (Resources/www).
#   sh tools/local-app/build.sh OUT_DIR
set -e
R=$(cd "$(dirname "$0")/../.." && pwd)
OUT=${1:?usage: build.sh OUT_DIR}
rm -rf "$OUT" && mkdir -p "$OUT/app"
( cd "$R/server/app" && tar cf - --exclude='./android' --exclude='*.apk' . ) | ( cd "$OUT/app" && tar xf - )
# fonts are shared with the accounting panel (../acc/vendor/fonts)
mkdir -p "$OUT/acc/vendor" && cp -r "$R/server/acc/vendor/fonts" "$OUT/acc/vendor/"
# the starting data of a fresh server (committed as app/local/seed.js; made again when PHP is here)
if command -v php >/dev/null 2>&1; then php "$R/tools/local-app/seed.php" > "$OUT/app/local/seed.js"; fi
# the engine answers api.php before app.js starts
sed -i.bak 's#<script src="app.js#<script src="local/seed.js"></script>\n<script src="local/sms.js"></script>\n<script src="local/xlsx.js"></script>\n<script src="local/engine.js"></script>\n<script src="app.js#' "$OUT/app/index.html"
rm -f "$OUT/app/index.html.bak" "$OUT/app/sw.js"
grep -q 'local/engine.js' "$OUT/app/index.html" || { echo "index.html: app.js script tag not found" >&2; exit 1; }
echo "local web app: $OUT/app ($(du -sh "$OUT/app" | cut -f1))"
