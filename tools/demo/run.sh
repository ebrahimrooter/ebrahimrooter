#!/bin/sh
# Sample books for screenshots: copies server/ to $1, seeds it, serves it on :8840.
#   sh tools/demo/run.sh /tmp/demo     → http://127.0.0.1:8840/app/  (app password demo12345)
set -e
D=${1:-/tmp/bank-demo}
R=$(cd "$(dirname "$0")/../.." && pwd)
rm -rf "$D" && mkdir -p "$D" && cp -r "$R/server" "$D/s"
rm -f "$D/s/config.php" "$D"/s/data/*.sqlite*
printf "<?php return ['app_token' => 'demo12345', 'device_token' => 'd', 'timezone' => 'Asia/Tehran'];\n" > "$D/s/config.php"
(cd "$D/s" && nohup php -S 0.0.0.0:8840 router.php > "$D/server.log" 2>&1 &)
sleep 2
php "$R/tools/demo/seed.php"
php "$R/tools/demo/bank.php" "$D/s"
echo "demo on http://127.0.0.1:8840/app/"
