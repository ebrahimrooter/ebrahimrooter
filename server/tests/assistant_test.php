<?php
/**
 * Voice assistant backend over HTTP:  php tests/assistant_test.php
 * Pairing with a one-time code, device token, the Persian commands, revoking.
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/assistant-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
array_map('unlink', glob("$S/data/*.sqlite*") ?: []);
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran'];");
$port = 35000 + getmypid() % 2000;
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $S, "$S/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $pipes);
usleep(600000);

$fails = 0;
function check($name, $ok, $extra = '')
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : '  ' . $extra) . "\n";
    if (!$ok) {
        $fails++;
    }
}
function http($url, $body = null, array $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) {
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return [$code, json_decode($out, true) ?? $out];
}
$A = "http://127.0.0.1:$port/api.php?r=";
$ACC = "http://127.0.0.1:$port/acc/api.php?p=";
$tok = http($ACC . '/login', ['username' => 'admin', 'password' => 'apppass123'])[1]['token'];
$acc = fn($m, $p, $b = null) => http($ACC . $p, $b, ["Authorization: Bearer $tok"])[1];
$ali = $acc('POST', '/persons', ['name' => 'علی رضایی', 'type' => 'customer'])['id'];
$seed = $acc('POST', '/products', ['name' => 'بذر گوجه', 'sale_price' => 150000, 'buy_price' => 100000, 'stock' => 10, 'reorder_point' => 2])['id'];
http($ACC . '/invoices', ['kind' => 'sale', 'person_id' => $ali, 'items' => [['product_id' => $seed, 'qty' => 2, 'price' => 150000]]], ["Authorization: Bearer $tok"]);

echo "pairing\n";
check('pairing needs the app password', http($A . 'assistant_pair', [])[0] === 401);
[$c, $p] = http($A . 'assistant_pair', [], ['X-App-Token: apppass123']);
check('one-time code and app link', $c === 200 && strlen($p['code']) === 8 && strpos($p['link'], 'bankassistant://pair?code=') === 0, json_encode($p));
check('wrong code refused', http($A . 'assistant_redeem', ['code' => 'ZZZZZZZZ'])[0] === 403);
[$c, $r] = http($A . 'assistant_redeem', ['code' => $p['code'], 'device_name' => 'iPhone تست']);
check('code gives a device token', $c === 200 && strlen($r['token']) === 64, json_encode($r));
check('a code works only once', http($A . 'assistant_redeem', ['code' => $p['code']])[0] === 403);
$D = ['Authorization: Bearer ' . $r['token']];
check('no token, no answers', http($A . 'assistant_ask', ['text' => 'فروش امروز'])[0] === 401);
check('device known', http($A . 'assistant_me', [], $D)[1]['device']['name'] === 'iPhone تست');

echo "commands\n";
$ask = fn($t) => http($A . 'assistant_ask', ['text' => $t], $D)[1];
$a = $ask('فروش امروز چقدر بوده؟');
check('sales today', strpos($a['reply'], '30,000 تومان') !== false && $a['data']['count'] === 1, $a['reply']);
$a = $ask('گزارش فروش این ماه را بده');
check('monthly report with best sellers', strpos($a['reply'], 'بذر گوجه') !== false, $a['reply']);
$a = $ask('موجودی انبار را بگو');
check('warehouse value', strpos($a['reply'], 'ارزش موجودی انبار') !== false, $a['reply']);
$a = $ask('موجودی بذر گوجه چقدره');
check('stock of one product', strpos($a['reply'], 'موجودی بذر گوجه: 8') !== false, $a['reply']);
$a = $ask('حساب علی رضایی');
check('person balance', strpos($a['reply'], 'علی رضایی 33,000 تومان به ما بدهکار است') !== false, $a['reply']);
$a = $ask('برای علی رضایی فاکتور ثبت کن، سه عدد بذر گوجه');
check('invoice draft asks for confirmation', $a['state'] === 'confirm' && strpos($a['reply'], '3 عدد بذر گوجه') !== false && strpos($a['reply'], '49,500') !== false, $a['reply']);
$a = $ask('آره');
check('«آره» books the invoice', $a['state'] === 'answered' && strpos($a['reply'], 'ثبت شد') !== false, $a['reply']);
check('stock went down', $acc('GET', '/products')[0]['stock'] == 5);
$a = $ask('برای علی رضایی فاکتور ثبت کن، صد عدد بذر گوجه');
check('not enough stock said plainly', $a['state'] === 'error' && strpos($a['reply'], 'کافی نیست') !== false, $a['reply']);
$ask('برای علی رضایی فاکتور ثبت کن، یک عدد بذر گوجه');
$a = $ask('نه');
check('«نه» drops the draft', strpos($a['reply'], 'ثبت نشد') !== false && $acc('GET', '/products')[0]['stock'] == 5);
check('unknown sentence: help', $ask('هوا چطوره')['state'] === 'unknown');
check('speech needs the voice service (clear error)', http($A . 'assistant_speak', ['text' => 'سلام'], $D)[0] === 501);

echo "revoke\n";
$dev = http($A . 'assistant_devices', null, ['X-App-Token: apppass123'])[1]['items'][0];
http($A . 'assistant_revoke', ['id' => $dev['id']], ['X-App-Token: apppass123']);
check('revoked device is locked out', http($A . 'assistant_ask', ['text' => 'فروش امروز'], $D)[0] === 401);

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
