<?php
/**
 * Security fixes: brute-force lockouts, push allow-list, private URLs, idle phones,
 * unencrypted backups:  php tests/security_test.php
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/security-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
exec('rm -rf ' . escapeshellarg($S) . '/data/*.sqlite*');
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'otp_pin' => '4321', 'timezone' => 'Asia/Tehran'];");
$port = 38000 + getmypid() % 1000;
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $S, "$S/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $p);
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
    return [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode($out, true) ?? $out];
}
$A = "http://127.0.0.1:$port/api.php?r=";
$H = ['X-App-Token: apppass123'];
$sms = fn($sender, $text) => http($A . 'ingest', ['sender' => $sender, 'text' => $text], ['X-Device-Token: d'])[1];


echo "brute force\n";
for ($i = 0; $i < 8; $i++) {
    http($A . 'wallets', null, ['X-App-Token: wrong-' . $i]);
}
check('8 wrong app passwords: locked', http($A . 'wallets', null, ['X-App-Token: wrong'])[0] === 429);
check('...even the right one waits', http($A . 'wallets', null, $H)[0] === 429);
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; foreach (ba_db()->query("SELECT k FROM kv WHERE k LIKE \'throttle:%\'") as $r) ba_kv_set($r["k"], null);'));
check('after the lock: the right password works', http($A . 'wallets', null, $H)[0] === 200);
$ACC = "http://127.0.0.1:$port/acc/api.php?p=";
for ($i = 0; $i < 8; $i++) {
    http($ACC . '/login', ['username' => 'admin', 'password' => 'nope' . $i]);
}
check('8 wrong panel passwords: locked', http($ACC . '/login', ['username' => 'admin', 'password' => 'apppass123'])[0] === 429);
check('session never accepted from the URL', http($A . 'wallets&token=apppass123')[0] === 401);

echo "push endpoints\n";
$sub = fn($ep) => http($A . 'push_subscribe', ['subscription' => ['endpoint' => $ep, 'keys' => ['p256dh' => 'x', 'auth' => 'y']]], $H);
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; foreach (ba_db()->query("SELECT k FROM kv WHERE k LIKE \'throttle:%\'") as $r) ba_kv_set($r["k"], null);'));
check('push to any other address refused', $sub('https://127.0.0.1:8765/x')[0] === 400 && $sub('https://evil.example/push')[0] === 400);
check('browser push services accepted', $sub('https://fcm.googleapis.com/fcm/send/abc')[0] === 200 && $sub('https://web.push.apple.com/QW')[0] === 200);

echo "addresses typed by users\n";
$r = shell_exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; foreach (["http://127.0.0.1:8765/", "http://10.0.0.5/x", "http://192.168.1.1/", "http://[::1]/", "file:///etc/passwd", "gopher://x/"] as $u) { try { ba_curl_public($u, []); echo "OPEN $u\n"; } catch (RuntimeException $e) { echo "refused\n"; } }'));
check('this server, private networks and odd schemes refused', substr_count($r, 'refused') === 6, $r);

echo "paired phones\n";
$tok = http($A . 'assistant_login', ['password' => 'apppass123', 'device_name' => 'old phone'])[1]['token'] ?? '';
check('fresh phone works', http($A . 'assistant_home', [], ['Authorization: Bearer ' . $tok])[0] === 200);
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; ba_db()->exec("UPDATE assistant_devices SET last_seen = \'' . date('Y-m-d H:i:s', time() - 200 * 86400) . '\'");'));
check('a phone unused for half a year must pair again', http($A . 'assistant_home', [], ['Authorization: Bearer ' . $tok])[0] === 401);

echo "backups\n";
$b = shell_exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "backup.php"; $i = backup_create("test"); echo json_encode([$i["encrypted"], $i["sent_to_bale"]]);'));
check('no backup_password: backup made, not sent anywhere', trim((string)$b) === '[false,false]', (string)$b);

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
