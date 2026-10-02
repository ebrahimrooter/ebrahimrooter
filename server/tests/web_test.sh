#!/bin/sh
# Host setup flow over HTTP: install.php -> app -> "connect Bale" -> /start claims the bot.
#   server/tests/web_test.sh
cd "$(dirname "$0")/.." || exit 1
T=$(mktemp -d); cp -r . "$T/s"; rm -f "$T/s/config.php" "$T/s/data/"*.sqlite*
P=$((30000 + $$ % 10000)); B=$((P + 1))
cat > "$T/bale.php" <<'PHP'
<?php
$m = basename(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
file_put_contents(__DIR__ . '/bale.log', $m . ' ' . file_get_contents('php://input') . "\n", FILE_APPEND);
header('Content-Type: application/json');
$r = ['getMe' => ['id' => 1, 'username' => 'my_bank_bot'], 'getWebhookInfo' => ['url' => is_file(__DIR__ . '/hook') ? file_get_contents(__DIR__ . '/hook') : '']];
if ($m === 'setWebhook') { file_put_contents(__DIR__ . '/hook', json_decode(file_get_contents('php://input'), true)['url']); }
echo json_encode(['ok' => true, 'result' => $r[$m] ?? ['message_id' => 1]]);
PHP
php -S 127.0.0.1:$P -t "$T/s" >/dev/null 2>&1 & S1=$!
php -S 127.0.0.1:$B "$T/bale.php" >/dev/null 2>&1 & S2=$!
sleep 1
U="http://127.0.0.1:$P"; H="X-Forwarded-Proto: https"
fails=0
check() { if [ "$2" = "$3" ]; then echo "  ok   $1"; else echo "  FAIL $1 (got: $2)"; fails=$((fails+1)); fi; }

echo "install.php"
check "plain http is refused (Bale needs https)" "$(curl -s $U/install.php | grep -c 'SSL')" "1"
check "https: host ready" "$(curl -s -H "$H" $U/install.php | grep -c 'هاست آماده است')" "1"
OUT=$(curl -s -H "$H" -X POST $U/install.php)
APP=$(printf '%s' "$OUT" | grep -o 'رمز اپ:<br><span class="big"><code>[0-9a-f]*' | sed 's/.*<code>//')
DEV=$(printf '%s' "$OUT" | grep -o 'DEVICE_TOKEN = "[0-9a-f]*' | sed 's/.*"//')
check "passwords shown once" "$([ ${#APP} -eq 10 ] && [ ${#DEV} -eq 32 ] && echo yes)" "yes"
check "ESP32 SERVER_URL line" "$(printf '%s' "$OUT" | grep -c "SERVER_URL   = \"https://127.0.0.1:$P/api.php\"")" "1"
check "second visit refused" "$(curl -s -o /dev/null -w '%{http_code}' -H "$H" $U/install.php)" "403"
# test-only: point the Bale client at the mock
sed -i "s#^return \[#return ['bale_api_base' => 'http://127.0.0.1:$B',#" "$T/s/config.php"

echo "connect Bale from the app"
A="X-App-Token: $APP"
check "bad token shape refused" "$(curl -s -H "$H" -H "$A" -H 'Content-Type: application/json' -d '{"bale_bot_token":"nope"}' "$U/api.php?r=bale_connect" | grep -c '"ok":false')" "1"
R=$(curl -s -H "$H" -H "$A" -H 'Content-Type: application/json' -d '{"bale_bot_token":"123456:ABCDEFGHIJKLMNOP"}' "$U/api.php?r=bale_connect")
check "connect: bot found, waiting for /start" "$(printf '%s' "$R" | grep -c '"bot":"my_bank_bot","waiting_for_start":true')" "1"
check "webhook points at this server" "$(grep -c "https://127.0.0.1:$P/api.php?r=bale&key=" "$T/hook")" "1"
KEY=$(sed 's/.*key=//' "$T/hook")
curl -s -H 'Content-Type: application/json' -d '{"message":{"chat":{"id":555},"text":"/start"}}' "$U/api.php?r=bale&key=$KEY" >/dev/null
S=$(curl -s -H "$H" -H "$A" "$U/api.php?r=settings")
check "first /start after connect claims the bot" "$(printf '%s' "$S" | grep -c '"chat_id":"555"')" "1"
curl -s -H 'Content-Type: application/json' -d '{"message":{"chat":{"id":666},"text":"/start"}}' "$U/api.php?r=bale&key=$KEY" >/dev/null
check "a later stranger cannot take it over" "$(curl -s -H "$H" -H "$A" "$U/api.php?r=settings" | grep -c '"chat_id":"555"')" "1"
check "wrong webhook key refused" "$(curl -s -o /dev/null -w '%{http_code}' -d '{}' "$U/api.php?r=bale&key=wrong")" "403"

echo "ESP32 path"
SMS=$(php -r 'echo strtoupper(bin2hex(mb_convert_encoding("حساب1234567890\nواریز50,000\nمانده12,345,670\n05/07/10-00:40","UTF-16BE","UTF-8")));')
check "SMS from the device is booked" "$(curl -s -H "X-Device-Token: $DEV" --data "text_hex=$SMS" "$U/api.php?r=ingest" | grep -c '"transaction_id":1')" "1"
# notifications go out right after the device got its answer: give them a moment
for i in 1 2 3 4 5 6; do grep -q '🟢' "$T/bale.log" 2>/dev/null && break; sleep 0.5; done
check "the owner is asked about the deposit in Bale" "$(grep sendMessage "$T/bale.log" | grep '"chat_id":"555"' | grep '🟢 واریز' | grep -c 'بابت چی بود')" "1"
check "nothing goes to the stranger" "$(grep sendMessage "$T/bale.log" | grep '"chat_id":"666"' | grep -c '🟢')" "0"
check "heartbeat ok (runs due jobs, returns clean JSON)" "$(curl -s -H "X-Device-Token: $DEV" --data 'csq=17&creg=1&used=0&uptime=5' "$U/api.php?r=heartbeat")" '{"ok":true}'
check "config.php not downloadable" "$(curl -s -o /dev/null -w '%{http_code}' $U/config.php.txt)" "404"

kill $S1 $S2; rm -rf "$T"
[ $fails -eq 0 ] && echo "all passed" || { echo "$fails FAILED"; exit 1; }
