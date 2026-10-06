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
function person_bal($acc, $name)
{
    foreach ($acc('GET', '/persons') as $p) {
        if ($p['name'] === $name) {
            return $p['balance'];
        }
    }
    return null;
}
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

echo "more of the accounting by voice\n";
$acc('POST', '/expense-types', ['name' => 'اجاره مغازه']);
$cash0 = array_sum(array_column($acc('GET', '/accounts'), 'balance'));
$a = $ask('از علی رضایی پنج میلیون تومان نقد گرفتم');
check('receipt drafted (person, amount in toman, cash box)', $a['state'] === 'confirm' && strpos($a['reply'], 'دریافت از علی رضایی') !== false && strpos($a['reply'], '5,000,000 تومان') !== false, $a['reply']);
$ask('آره');
check('receipt booked: customer and cash', [person_bal($acc, 'علی رضایی'), array_sum(array_column($acc('GET', '/accounts'), 'balance'))] == [330000 + 495000 - 50000000, $cash0 + 50000000]);
$a = $ask('دو میلیون و پانصد هزار تومان اجاره از بانک دادم');
check('expense by its type name', strpos($a['reply'], 'هزینه‌ی اجاره مغازه') !== false && strpos($a['reply'], '2,500,000') !== false, $a['reply']);
$ask('بله');
$a = $ask('سود این ماه چقدره');
check('profit', strpos($a['reply'], 'زیان این ماه') !== false || strpos($a['reply'], 'سود این ماه') !== false, $a['reply']);
$a = $ask('خلاصه وضعیت');
check('overview', strpos($a['reply'], 'فروش این ماه') !== false && strpos($a['reply'], 'موجودی صندوق و بانک') !== false, $a['reply']);
$a = $ask('مشتری جدید به اسم حسن کریمی با شماره 09121234567 اضافه کن');
check('new person drafted', $a['state'] === 'confirm' && strpos($a['reply'], 'حسن کریمی') !== false && strpos($a['reply'], '09121234567') !== false, $a['reply']);
$ask('آره');
check('person added', in_array('حسن کریمی', array_column($acc('GET', '/persons'), 'name'), true));
$a = $ask('به حسن کریمی پیامک بفرست');
check('SMS drafted with the text', $a['state'] === 'confirm' && strpos($a['reply'], 'پیامک به حسن کریمی') !== false, $a['reply']);
$ask('نه');
check('cheques this week', strpos($ask('چک‌های این هفته')['reply'], 'چک') !== false);
check('unpaid invoices', strpos($ask('فاکتورهای سررسید گذشته')['reply'], 'فاکتور فروش تسویه‌نشده') !== false);
check('VAT', strpos($ask('مالیات ارزش افزوده چقدره')['reply'], 'قابل پرداخت') !== false);
check('purchases', strpos($ask('خرید این ماه')['reply'], 'خرید این ماه') !== false);
check('best customers', strpos($ask('بهترین مشتری‌های فروش این ماه')['reply'], 'بهترین مشتری‌ها: علی رضایی') !== false);
// a bank SMS waiting for an answer, answered by voice
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; ba_db()->exec("INSERT INTO transactions (source, direction, amount, bank_date, occurred_at, status, wallet_id) VALUES (\'manual\', \'out\', 1200000, \'' . trim(shell_exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; [$y,$m,$d] = ba_today_jalali(); printf("%04d/%02d/%02d", $y, $m, $d);'))) . '\', datetime(\'now\'), \'pending\', 1)");'));
$a = $ask('تراکنش‌های بی‌جواب');
check('assistant asks about the bank transaction', $a['state'] === 'confirm' && strpos($a['reply'], 'برداشت 120,000 تومان') !== false && strpos($a['reply'], 'بابت چی بود') !== false, $a['reply']);
$a = $ask('خرید گازوئیل');
check('the next sentence answers it', strpos($a['reply'], 'ثبت شد') !== false && strpos($ask('تراکنش بی جواب')['reply'], 'همه‌ی تراکنش‌ها') !== false, $a['reply']);
check('speech needs the voice service (clear error)', http($A . 'assistant_speak', ['text' => 'سلام'], $D)[0] === 501);

echo "cheques by voice\n";
$a = $ask('یک چک ده میلیون تومانی از علی رضایی گرفتم شماره ۴۵۲۱۹۰ سررسید فردا بانک ملی');
check('received cheque drafted with number, due date, bank', $a['state'] === 'confirm' && strpos($a['reply'], 'دریافتی از علی رضایی') !== false
    && strpos($a['reply'], '10,000,000 تومان') !== false && strpos($a['reply'], 'شماره 452190') !== false && strpos($a['reply'], 'بانک ملی') !== false, $a['reply']);
$a = $ask('آره');
check('cheque booked', strpos($a['reply'], 'چک 452190 ثبت شد') !== false, $a['reply']);
$chq = array_values(array_filter($acc('GET', '/cheques'), fn($c) => $c['number'] === '452190'))[0] ?? null;
check('in the cheque list, in hand, due tomorrow', $chq && $chq['status'] === 'in_hand' && $chq['amount'] == 100000000);
$a = $ask('به حسن کریمی چک دو میلیونی دادم شماره ۷۸۸۱۲');
check('missing due date is asked', $a['state'] === 'confirm' && strpos($a['reply'], 'سررسید چک چه تاریخی است') !== false, $a['reply']);
$a = $ask('پانزدهم اسفند');
check('then the confirmation, payable', strpos($a['reply'], 'پرداختی به حسن کریمی') !== false && preg_match('~سررسید 14\d\d/12/15~u', $a['reply']), $a['reply']);
$ask('بله');
$a = $ask('چک‌های این هفته');
check('due cheques announced', strpos($a['reply'], 'علی رضایی') !== false && strpos($a['reply'], 'چک دریافتی') !== false, $a['reply']);
$a = $ask('خلاصه وضعیت');
check('overview starts with cheques due tomorrow', strpos($a['reply'], 'سررسید چک') !== false && strpos($a['reply'], 'فردا: دریافتی از علی رضایی') !== false, $a['reply']);
$a = $ask('چک ۴۵۲۱۹۰ وصول شد');
check('cheque collection drafted', $a['state'] === 'confirm' && strpos($a['reply'], 'وصول به') !== false, $a['reply']);
$a = $ask('آره');
check('cheque collected', strpos($a['reply'], 'وصول شده') !== false, $a['reply']);
// the owner's morning alert (Bale + push) uses the same text
$msg = shell_exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "jobs.php"; acc_db(); echo acc_cheque_due_message(400);'));
check('morning alert lists the open cheques', strpos($msg, 'سررسید چک') !== false && strpos($msg, 'پرداختی به حسن کریمی') !== false && strpos($msg, '452190') === false, $msg);

echo "revoke\n";
$dev = http($A . 'assistant_devices', null, ['X-App-Token: apppass123'])[1]['items'][0];
http($A . 'assistant_revoke', ['id' => $dev['id']], ['X-App-Token: apppass123']);
check('revoked device is locked out', http($A . 'assistant_ask', ['text' => 'فروش امروز'], $D)[0] === 401);

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
