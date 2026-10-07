<?php
/**
 * iPhone push (APNs) against a local mock of Apple's server:  php tests/apns_test.php
 * The mock checks the ES256 provider token with the public half of a fresh key.
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/apns-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
array_map('unlink', glob("$S/data/*.sqlite*") ?: []);
$key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
openssl_pkey_export($key, $pem);
file_put_contents("$tmp/AuthKey.p8", $pem);
file_put_contents("$tmp/pub.pem", openssl_pkey_get_details($key)['key']);
$port = 37000 + getmypid() % 1000;
$mport = $port + 1;
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran',
    'apns_key_id' => 'KEY1234567', 'apns_team_id' => 'TEAM123456', 'apns_key_file' => '$tmp/AuthKey.p8', 'apns_topic' => 'ir.example.bankassistant',
    'apns_host' => 'http://127.0.0.1:$mport'];");

// Apple's side, mocked
file_put_contents("$tmp/mock.php", <<<'PHP'
<?php
$dir = __DIR__;
$hdr = array_change_key_case(getallheaders(), CASE_LOWER);
$jwt = substr($hdr['authorization'] ?? '', 7);
[$h, $c, $sig] = array_pad(explode('.', $jwt), 3, '');
$raw = base64_decode(strtr($sig, '-_', '+/'));
$int = function ($b) { $b = ltrim($b, "\0"); if ($b === '' || ord($b[0]) > 127) $b = "\0" . $b; return "\x02" . chr(strlen($b)) . $b; };
$der = $int(substr($raw, 0, 32)) . $int(substr($raw, 32));
$der = "\x30" . chr(strlen($der)) . $der;
$ok = openssl_verify("$h.$c", $der, file_get_contents("$dir/pub.pem"), OPENSSL_ALGO_SHA256) === 1;
$head = json_decode(base64_decode(strtr($h, '-_', '+/')), true);
$claims = json_decode(base64_decode(strtr($c, '-_', '+/')), true);
$token = basename($_SERVER['REQUEST_URI']);
file_put_contents("$dir/got.jsonl", json_encode(['ok' => $ok, 'kid' => $head['kid'] ?? '', 'iss' => $claims['iss'] ?? '',
    'topic' => $hdr['apns-topic'] ?? '', 'token' => $token, 'body' => json_decode(file_get_contents('php://input'), true)], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
if (!$ok) { http_response_code(403); echo '{"reason":"InvalidProviderToken"}'; exit; }
if (strpos($token, 'dead') === 0) { http_response_code(410); echo '{"reason":"Unregistered"}'; exit; }
echo '';
PHP);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $S, "$S/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $p1);
$mock = proc_open(['php', '-S', "127.0.0.1:$mport", "$tmp/mock.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2);
usleep(700000);

$fails = 0;
function check($name, $ok, $extra = '')
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : '  ' . $extra) . "\n";
    if (!$ok) $fails++;
}
function http($url, $body = null, array $headers = [])
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, json_decode($out, true) ?? $out];
}
$A = "http://127.0.0.1:$port/api.php?r=";
$got = function () use ($tmp) { return array_map(fn($l) => json_decode($l, true), file("$tmp/got.jsonl", FILE_IGNORE_NEW_LINES) ?: []); };

echo "iPhone push\n";
$t = http($A . 'assistant_login', ['password' => 'apppass123', 'device_name' => 'iPhone'])[1]['token'];
$D = ['Authorization: Bearer ' . $t];
check('server says push is set up', http($A . 'assistant_me', [], $D)[1]['push'] === true);
check('bad device token refused', http($A . 'assistant_push_register', ['token' => 'xyz'], $D)[0] === 400);
$apnsToken = str_repeat('ab12', 16);
check('device token saved', http($A . 'assistant_push_register', ['token' => $apnsToken, 'env' => 'sandbox'], $D)[0] === 200);
http($A . 'ingest', ['sender' => '+98700717', 'text' => "بانک ملت\nبرداشت:1,200,000\nحساب:1234\nمانده:5,000,000"], ['X-Device-Token: d']);
for ($i = 0; $i < 30 && !is_file("$tmp/got.jsonl"); $i++) usleep(100000);   // sent after the device got its OK
$g = $got();
$last = end($g);
check('a bank SMS reaches the iPhone', count($g) === 1 && $last['token'] === $apnsToken, json_encode($g, JSON_UNESCAPED_UNICODE));
check('signed provider token (ES256, kid, team)', $last['ok'] && $last['kid'] === 'KEY1234567' && $last['iss'] === 'TEAM123456');
check('topic is the bundle id', $last['topic'] === 'ir.example.bankassistant');
check('answer from the notification: category and transaction id',
    ($last['body']['aps']['category'] ?? '') === 'BANK_TX' && ($last['body']['tx_id'] ?? 0) > 0 && strpos($last['body']['aps']['alert']['title'], 'برداشت') !== false,
    json_encode($last['body'], JSON_UNESCAPED_UNICODE));
// the transaction id in the push can be confirmed with the device token (the notification's text field)
check('confirm with the id from the push', http($A . 'assistant_confirm', ['id' => $last['body']['tx_id'], 'description' => 'خرید سوخت'], $D)[0] === 200);
// a device Apple says is gone loses its token
http($A . 'assistant_push_register', ['token' => 'dead' . str_repeat('0', 60)], $D);
http($A . 'push_test', [], ['X-App-Token: apppass123']);
http($A . 'push_test', [], ['X-App-Token: apppass123']);
$deads = array_filter($got(), fn($x) => strpos($x['token'], 'dead') === 0);
check('410 from Apple clears the token (sent once, not twice)', count($deads) === 1, count($deads) . ' sends');

proc_terminate($srv);
proc_terminate($mock);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
