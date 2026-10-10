<?php
/**
 * Bank cards: SMS of Mellat, Melli, Saderat and Blu go to their own card, OTPs
 * to their card's section, each card its own account in the books:  php tests/cards_test.php
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/cards-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
exec('rm -rf ' . escapeshellarg($S) . '/data/*.sqlite*');
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'otp_pin' => '4321', 'timezone' => 'Asia/Tehran'];");
$port = 39000 + getmypid() % 1000;
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

echo "cards\n";
$w = http($A . 'wallets', null, $H)[1]['items'];
$by = [];
foreach ($w as $x) $by[$x['bank']] = $x;
check('four bank cards + cash from the start', isset($by['mellat'], $by['melli'], $by['saderat'], $by['blu'], $by['cash']), json_encode(array_column($w, 'name', 'bank'), JSON_UNESCAPED_UNICODE));
check('each card has its colour', $by['mellat']['color'] && $by['blu']['color'] && $by['melli']['color'] !== $by['blu']['color']);

$cases = [
    ['mellat', 'Bank Mellat', "بانک ملت\nبرداشت:1,250,000\nحساب:1234\nمانده:8,420,000\n0707-14:25", 'out', 1250000],
    ['melli', '+98999', "بانک ملی ایران\nانتقال از:0101234567001\nمبلغ:2,000,000-\nمانده:15,300,000\n1405/07/18-10:12", 'out', 2000000],
    ['melli', 'BMI', "بانك ملي ايران\nواریز:۵٬۰۰۰٬۰۰۰+\nبه:...5521\nمانده:۲۰٬۳۰۰٬۰۰۰\n1405/07/18-11:00", 'in', 5000000],
    ['saderat', 'Bank Saderat', "بانک صادرات ایران\nبرداشت از حساب:0219876543\nمبلغ: 750,000 ریال\nمانده: 4,250,000\n1405/07/18 12:40", 'out', 750000],
    ['blu', 'blu', "بلو\nواریز\n2,500,000 ریال\nبه حساب شما\nموجودی: 12,500,000 ریال\n1405/07/18 13:05", 'in', 2500000],
    ['blu', '+98300', "blu bank\nخرید با کارت: 320,000 ریال\nموجودی: 12,180,000 ریال\n1405/07/18 13:30", 'out', 320000],
];
foreach ($cases as $i => [$bank, $sender, $text, $dir, $amount]) {
    $r = $sms($sender, $text);
    $tx = http($A . 'transaction&id=' . ($r['transaction_id'] ?? 0), null, $H)[1]['item'] ?? null;
    check("$bank SMS #" . ($i + 1) . " → card «{$by[$bank]['name']}»", $tx && (int)$tx['wallet_id'] === (int)$by[$bank]['id'] && $tx['direction'] === $dir && (int)$tx['amount'] === $amount,
        json_encode([$r, $tx ? [$tx['wallet_id'], $tx['direction'], $tx['amount']] : null], JSON_UNESCAPED_UNICODE));
}
check('«بلوار» in a Mellat SMS is not Blu', ($t = $sms('Bank Mellat', "بانک ملت\nخرید:90,000\nفروشگاه بلوار کشاورز\nمانده:8,330,000\n0707-15:10")) && (int)(http($A . 'transaction&id=' . $t['transaction_id'], null, $H)[1]['item']['wallet_id']) === (int)$by['mellat']['id']);

$w2 = http($A . 'wallets', null, $H)[1]['items'];
$pend = array_column($w2, 'pending', 'bank');
check('pending counted per card', $pend['melli'] === 2 && $pend['blu'] === 2 && $pend['saderat'] === 1, json_encode($pend));

// OTP of Melli goes to Melli's section only
$o = $sms('BMI', "بانک ملی ایران\nرمز دوم پویا: 584213\nمبلغ: 1,200,000 ریال\nپذیرنده: فروشگاه نمونه\nاعتبار 2 دقیقه");
check('OTP tagged with its card', ($o['otp'] ?? false) && (int)$o['wallet_id'] === (int)$by['melli']['id'], json_encode($o));
$P = array_merge($H, ['X-OTP-PIN: 4321']);
$mel = http($A . 'otp&wallet_id=' . $by['melli']['id'], null, $P)[1]['items'];
$sad = http($A . 'otp&wallet_id=' . $by['saderat']['id'], null, $P)[1]['items'];
check("card OTP section: Melli sees it, Saderat doesn't", count($mel) === 1 && $mel[0]['code'] === '584213' && count($sad) === 0, json_encode([$mel, $sad], JSON_UNESCAPED_UNICODE));
check('OTP section still needs the PIN', http($A . 'otp&wallet_id=' . $by['melli']['id'], null, $H)[0] === 403);
check('OTP waiting shown on the card', (int)array_column(http($A . 'wallets', null, $H)[1]['items'], 'otps', 'bank')['melli'] === 1);

// confirm a Blu transaction → its own account in the books
$blu = array_values(array_filter(http($A . 'pending', null, $H)[1]['items'], fn($t) => (int)$t['wallet_id'] === (int)$by['blu']['id']))[0];
check('confirm on the Blu card', http($A . 'confirm', ['id' => $blu['id'], 'description' => 'فروش نقدی'], $H)[0] === 200);
$ACC = "http://127.0.0.1:$port/acc/api.php?p=";
$tok = http($ACC . '/login', ['username' => 'admin', 'password' => 'apppass123'])[1]['token'];
$meta = http($ACC . '/bank/meta', null, ["Authorization: Bearer $tok"])[1];
$accs = array_column(http($ACC . '/accounts', null, ["Authorization: Bearer $tok"])[1], null, 'name');
check('every card has its own account in the books', isset($accs['بانک ملت'], $accs['بانک ملی'], $accs['بانک صادرات'], $accs['بلو بانک']), implode('، ', array_keys($accs)));
check("the Blu receipt landed in Blu's account", (int)$accs['بلو بانک']['balance'] === 2500000, json_encode($accs['بلو بانک'] ?? null, JSON_UNESCAPED_UNICODE));

// a second Melli card: told apart by the last 4 digits
[$c, $nw] = http($A . 'wallet_save', ['name' => 'کارت ملی دوم', 'kind' => 'bank', 'bank' => 'melli', 'card' => '6037-9911-2233-7788'], $H);
$r = $sms('BMI', "بانک ملی ایران\nبرداشت:400,000\nکارت:7788\nمانده:900,000\n1405/07/18-16:00");
$tx = http($A . 'transaction&id=' . $r['transaction_id'], null, $H)[1]['item'];
check('second card of the same bank chosen by its digits', (int)$tx['wallet_id'] === (int)$nw['id'], json_encode([$nw, $tx['wallet_id']]));
check('banks list for the card form', count(http($A . 'banks', null, $H)[1]['items']) === 4);

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
