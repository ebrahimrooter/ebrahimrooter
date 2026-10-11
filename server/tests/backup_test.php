<?php
/**
 * Backups: make, list, download, send to Bale (mock), encrypted restore:  php tests/backup_test.php
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/backup-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
exec('rm -rf ' . escapeshellarg("$S/data/backups") . ' ' . escapeshellarg($S) . '/data/*.sqlite*');
$port = 38000 + getmypid() % 1000;
$bport = $port + 1;
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran',
    'bale_bot_token' => 'T', 'bale_chat_id' => '42', 'bale_api_base' => 'http://127.0.0.1:$bport', 'backup_password' => 'secret-pass', 'backup_keep' => 2];");
file_put_contents("$tmp/bale.php", <<<'PHP'
<?php
$f = $_FILES['document'] ?? null;
if ($f) copy($f['tmp_name'], __DIR__ . '/up-' . basename($f['name']));
file_put_contents(__DIR__ . '/bale.jsonl', json_encode(['path' => $_SERVER['REQUEST_URI'], 'chat' => $_POST['chat_id'] ?? '', 'caption' => $_POST['caption'] ?? '',
    'file' => $f ? $f['name'] : null, 'size' => $f ? $f['size'] : 0], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
header('Content-Type: application/json');
echo '{"ok":true,"result":{"message_id":1}}';
PHP);
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $S, "$S/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $p1);
$bale = proc_open(['php', '-S', "127.0.0.1:$bport", "$tmp/bale.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $p2);
usleep(700000);

$fails = 0;
function check($name, $ok, $extra = '')
{
    global $fails;
    echo ($ok ? '  ok   ' : '  FAIL ') . $name . ($ok ? '' : '  ' . $extra) . "\n";
    if (!$ok) $fails++;
}
function http($url, $body = null, array $headers = [], $raw = false)
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json'], $headers)]);
    if ($body !== null) curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    return [$code, $raw ? $out : (json_decode($out, true) ?? $out)];
}
$A = "http://127.0.0.1:$port/api.php?r=";
$H = ['X-App-Token: apppass123'];
$count = fn() => (int)trim(shell_exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; echo ba_db()->query("SELECT COUNT(*) FROM transactions")->fetchColumn();')));

echo "backups\n";
http($A . 'ingest', ['sender' => '+98700717', 'text' => "بانک ملت\nواریز:3,000,000\nحساب:1234"], ['X-Device-Token: d']);
http("http://127.0.0.1:$port/acc/api.php?p=/login", ['username' => 'admin', 'password' => 'apppass123']);   // makes the accounting tables
check('one transaction before the backup', $count() === 1);
check('backups need the app password', http($A . 'backups')[0] === 401);
[$c, $b] = http($A . 'backup_now', [], $H);
check('backup made from the app', $c === 200 && $b['encrypted'] === true && in_array('bank.sqlite', $b['databases'], true), json_encode($b));
usleep(300000);
$sent = array_values(array_filter(array_map(fn($l) => json_decode($l, true), file("$tmp/bale.jsonl", FILE_IGNORE_NEW_LINES) ?: []),
    fn($x) => strpos($x['path'], 'sendDocument') !== false));
$bkp = array_values(array_filter($sent, fn($x) => substr((string)$x['file'], -4) === '.bkp'));
$xl = array_values(array_filter($sent, fn($x) => substr((string)$x['file'], -5) === '.xlsx'));
check('sent to the owner\'s Bale chat as a document', count($bkp) === 1 && strpos($bkp[0]['path'], '/botT/sendDocument') === 0
    && $bkp[0]['chat'] === '42' && $bkp[0]['file'] === $b['name'] && $bkp[0]['size'] === $b['size'] && $b['sent_to_bale'], json_encode($sent, JSON_UNESCAPED_UNICODE));
check('...and the books as an Excel file', count($xl) === 1 && $xl[0]['chat'] === '42' && !empty($b['excel_to_bale']) && strpos($xl[0]['caption'], 'اکسل') !== false, json_encode($sent, JSON_UNESCAPED_UNICODE));
$xfile = "$tmp/up-" . ($xl[0]['file'] ?? 'none');
check('Excel file locked (an Office encrypted file, no readable workbook inside)', is_file($xfile) && strncmp((string)file_get_contents($xfile), "\xD0\xCF\x11\xE0", 4) === 0
    && strpos((string)file_get_contents($xfile), 'worksheet') === false);
$py = "import sys, io, msoffcrypto, openpyxl\nf = msoffcrypto.OfficeFile(open(sys.argv[1], 'rb'))\ntry:\n    f.load_key(password='wrong', verify_password=True); print('WRONG-OK')\nexcept Exception: pass\nf = msoffcrypto.OfficeFile(open(sys.argv[1], 'rb')); f.load_key(password=sys.argv[2], verify_password=True)\nb = io.BytesIO(); f.decrypt(b, verify_integrity=True)\nwb = openpyxl.load_workbook(b)\nws = wb['تراکنش‌های بانک']\nprint('|'.join(wb.sheetnames)); print(ws.cell(2, 6).value)\n";
file_put_contents("$tmp/x.py", $py);
$r = (string)shell_exec('python3 ' . escapeshellarg("$tmp/x.py") . ' ' . escapeshellarg($xfile) . ' secret-pass 2>&1');
if (strpos($r, 'No module named') !== false) {
    echo "  skip Excel content check (pip install msoffcrypto-tool openpyxl)\n";
} else {
    check('Excel opens only with the backup password; every part of the books inside', strpos($r, 'WRONG-OK') === false
        && strpos($r, 'کارت‌ها|تراکنش‌های بانک|طرف‌حساب‌ها|اشخاص|کالا و انبار|فاکتورها|اسناد حسابداری|تراز آزمایشی|صندوق و بانک|چک‌ها') !== false
        && preg_match('/^300000$/m', $r), $r);
}
[$c, $x2] = http($A . 'backup_excel', null, $H, true);
check('Excel download in the app (locked too)', $c === 200 && strncmp($x2, "\xD0\xCF\x11\xE0", 4) === 0);
check('Excel needs the app password', http($A . 'backup_excel')[0] === 401);
@unlink("$tmp/bale.jsonl");
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "bot.php"; bot_handle_update(["message" => ["chat" => ["id" => 42], "text" => "/excel"]]);'));
usleep(300000);
check('/excel in Bale sends the workbook now', strpos((string)@file_get_contents("$tmp/bale.jsonl"), '.xlsx') !== false);
$body = file_get_contents("$S/data/backups/{$b['name']}");
check('encrypted: no database text inside', strpos($body, 'SQLite format') === false && strpos(@gzdecode(substr($body, 6)) ?: '', 'SQLite') === false);
[$c, $dl] = http($A . 'backup_download&name=' . $b['name'], null, $H, true);
check('download = the file', $c === 200 && $dl === $body);
check('no path tricks', http($A . 'backup_download&name=../config.php', null, $H)[0] === 404);
sleep(1);
exec("php $S/cron.php backup", $o, $rc);
sleep(1);
exec("php $S/cron.php backup", $o, $rc);
$l = http($A . 'backups', null, $H)[1];
check('only the last backup_keep (2) stay', count($l['items']) === 2 && $l['last']['name'] === $l['items'][0]['name'], json_encode($l['items']));

// lose data, then restore the newest
http($A . 'ingest', ['sender' => '+98700717', 'text' => "بانک ملت\nبرداشت:500,000\nحساب:1234"], ['X-Device-Token: d']);
check('two transactions now', $count() === 2);
proc_terminate($srv);
usleep(300000);
$newest = $l['items'][0]['name'];
exec("php $S/cron.php restore $newest wrong-pass 2>&1", $o1, $rc1);
check('wrong password refused, data untouched', $rc1 === 1 && $count() === 2, implode("\n", $o1));
exec("php $S/cron.php restore $newest secret-pass 2>&1", $o2, $rc2);
check('restored with the password', $rc2 === 0 && $count() === 1, implode("\n", $o2));
check('the data before it kept aside', count(glob("$S/data/before-restore-*/bank.sqlite")) === 1);

proc_terminate($bale);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
