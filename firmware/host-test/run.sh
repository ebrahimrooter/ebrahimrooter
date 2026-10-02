#!/bin/sh
# Compiles both sketches on a PC against a simulated ESP32 + SIM800 and runs
# the scenarios (no hardware needed). Needs g++.   ./run.sh
cd "$(dirname "$0")" || exit 1
set -e
# Same preprocessing as the Arduino IDE (prototypes on top), so errors like
# "'X' does not name a type" show up here too.
mkdir -p build
for s in sms_forwarder sim800_test bale_direct gprs_forwarder; do
  python3 arduino_preprocess.py ../$s/$s.ino build/$s.cpp
done
FLAGS="-std=gnu++17 -Wall -Wextra -Wno-unused-parameter -I."
g++ $FLAGS -x c++ test_forwarder.cpp -o /tmp/ba_test_forwarder
g++ $FLAGS -x c++ test_sim800.cpp -o /tmp/ba_test_sim800
g++ $FLAGS -x c++ test_bale.cpp -o /tmp/ba_test_bale
g++ $FLAGS -x c++ test_gprs.cpp -o /tmp/ba_test_gprs
echo "### sms_forwarder against a simulated SIM800"
/tmp/ba_test_forwarder
echo
echo "### bale_direct against a simulated SIM800 + Bale API"
/tmp/ba_test_bale
echo
echo "### gprs_forwarder -> the real accounting program (server/api.php) + simulated Bale"
T=$(mktemp -d)
cp -r ../../server "$T/server"
rm -f "$T/server/config.php" "$T/server/data/"*.sqlite*
cat > "$T/balemock.php" <<'PHP'
<?php
file_put_contents(getenv('BALE_LOG'), file_get_contents('php://input') . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo json_encode(['ok' => true, 'result' => ['message_id' => rand(1, 99999)]]);
PHP
PORT=$((20000 + $$ % 10000))
export BALE_LOG="$T/bale.log" APP_TOKEN=APPTOKEN REAL_API="http://127.0.0.1:$PORT/api.php"
printf "<?php return ['app_token'=>'APPTOKEN','device_token'=>'DEVTOKEN','allowed_senders'=>[],'timezone'=>'Asia/Tehran','bale_bot_token'=>'T','bale_chat_id'=>'42','bale_api_base'=>'http://127.0.0.1:%s'];" $((PORT + 1)) > "$T/server/config.php"
php -S 127.0.0.1:$PORT -t "$T/server" "$T/server/router.php" >/dev/null 2>&1 & P1=$!
php -S 127.0.0.1:$((PORT + 1)) "$T/balemock.php" >/dev/null 2>&1 & P2=$!
sleep 1
/tmp/ba_test_gprs || { kill $P1 $P2; exit 1; }
kill $P1 $P2
rm -rf "$T"
echo
echo "### sim800_test output with a simulated SIM800 (what Serial Monitor will show)"
/tmp/ba_test_sim800
