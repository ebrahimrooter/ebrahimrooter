<?php
/**
 * Accounting module test over HTTP:  php tests/acc_test.php
 * A copy of the server in a temp dir, PHP's built-in server, a full business
 * scenario; after every step the books must balance.
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/acc-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = $tmp . '/s';
@unlink("$S/config.php");
array_map('unlink', glob("$S/data/*.sqlite*") ?: []);
$taxPort = 33000 + getmypid() % 2000;
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran', 'allow_private_urls' => true, 'moadian_url' => 'http://127.0.0.1:$taxPort/requestsmanager/api/v2'];");
$port = 31000 + getmypid() % 2000;
$srv = proc_open(['php', '-S', "127.0.0.1:$port", '-t', $S, "$S/router.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/server.log", 'w']], $pipes);
usleep(500000);

$fails = 0;
function check($name, $got, $want)
{
    global $fails;
    $same = $got === $want || (is_numeric($got) && is_numeric($want) && abs($got - $want) < 0.01)
        || (is_array($got) && is_array($want) && json_encode(acc_t_norm($got)) === json_encode(acc_t_norm($want)));
    if ($same) {
        echo "  ok   $name\n";
    } else {
        $fails++;
        echo "  FAIL $name\n       got:  " . json_encode($got, JSON_UNESCAPED_UNICODE) . "\n       want: " . json_encode($want, JSON_UNESCAPED_UNICODE) . "\n";
    }
}

/** numbers as rounded floats, for comparing arrays */
function acc_t_norm($v)
{
    return is_array($v) ? array_map('acc_t_norm', $v) : (is_numeric($v) && !is_string($v) ? round((float)$v, 2) : $v);
}

$TOKEN = '';
/** [status, decoded body] */
function api($method, $path, $body = null, $token = null)
{
    global $port, $TOKEN;
    [$p, $q] = array_pad(explode('?', $path, 2), 2, '');
    $ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode($p) . ($q !== '' ? "&$q" : ''));
    $h = ['Content-Type: application/json'];
    $t = $token ?? $TOKEN;
    if ($t !== '') {
        $h[] = "Authorization: Bearer $t";
    }
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_POSTFIELDS => $body === null ? null : json_encode($body, JSON_UNESCAPED_UNICODE)]);
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode($out, true);
    return [$code, $j ?? $out];
}
function ok($method, $path, $body = null)
{
    [$c, $j] = api($method, $path, $body);
    if ($c !== 200) {
        global $fails;
        $fails++;
        echo "  FAIL $method $path -> $c " . json_encode($j, JSON_UNESCAPED_UNICODE) . "\n";
    }
    return $j;
}
/** Trial balance must balance and the balance sheet must too. */
function books($label)
{
    $tb = ok('GET', '/trial-balance');
    $bs = ok('GET', '/reports/balance-sheet');
    check("$label: trial balance debit = credit", round($tb['total_debit'], 2), round($tb['total_credit'], 2));
    check("$label: balance sheet balances", $bs['balanced'], true);
    return $bs;
}
function person($id)
{
    foreach (ok('GET', '/persons') as $p) {
        if ($p['id'] === $id) {
            return $p['balance'];
        }
    }
    return null;
}
function product($id)
{
    foreach (ok('GET', '/products') as $p) {
        if ($p['id'] === $id) {
            return $p;
        }
    }
    return null;
}
function cash($id)
{
    foreach (ok('GET', '/accounts') as $a) {
        if ($a['id'] === $id) {
            return $a['balance'];
        }
    }
    return null;
}

echo "Login\n";
check('no token -> 401', api('GET', '/persons')[0], 401);
check('wrong password -> 401', api('POST', '/login', ['username' => 'admin', 'password' => '1234'])[0], 401);
[$c, $j] = api('POST', '/login', ['username' => 'admin', 'password' => 'apppass123']);
check('admin logs in with the app password', [$c, $j['user']['role'] ?? null], [200, 'admin']);
$TOKEN = $j['token'];
check('me', ok('GET', '/me')['username'], 'admin');

echo "Setup with opening balances\n";
$bank = ok('POST', '/accounts', ['name' => 'بانک تست', 'kind' => 'bank', 'balance' => 1000000])['id'];
check('bank opening balance', cash($bank), 1000000.0);
$cust = ok('POST', '/persons', ['name' => 'مشتری الف', 'type' => 'customer', 'national_id' => '1234567890'])['id'];
$supp = ok('POST', '/persons', ['name' => 'تأمین‌کننده ب', 'type' => 'supplier', 'opening' => -200])['id'];
check('supplier opening (we owe 200)', person($supp), -200.0);
$prod = ok('POST', '/products', ['name' => 'کالای تست', 'buy_price' => 100, 'sale_price' => 200, 'stock' => 10, 'reorder_point' => 2])['id'];
check('opening stock 10', product($prod)['stock'], 10.0);
books('after openings');
check('opening entries exist', ok('POST', '/journals/opening')['number'], '3 سند افتتاحیه');

echo "Purchase (discount spread + freight into cost)\n";
$inv = ok('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $supp, 'date' => '1405/07/01', 'freight' => 50,
    'items' => [['product_id' => $prod, 'qty' => 5, 'price' => 120]]]);
check('purchase total = 600 + 60 VAT + 50 freight', $inv['total'], 710.0);
$p = product($prod);
check('stock 15', $p['stock'], 15.0);
check('average cost (10x100 + 5x130) / 15 = 110', round($p['avg_cost'], 4), 110.0);
check('supplier owes us -910', person($supp), -910.0);
books('after purchase');

echo "Sale with percent discount\n";
$sale = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'date' => '1405/07/02', 'discount_percent' => 10,
    'items' => [['product_id' => $prod, 'qty' => 3, 'price' => 200]]]);
check('sale total = 540 + 54', $sale['total'], 594.0);
check('customer owes 594', person($cust), 594.0);
check('stock 12', product($prod)['stock'], 12.0);
$pl = ok('GET', '/reports/profit-loss');
check('profit = sales 540 - cost 330', $pl['profit'], 210.0);
check('tax invoice made for the sale', count(ok('GET', '/tax-invoices?kind=sale')), 1);
books('after sale');

echo "Receipt and payment\n";
ok('POST', '/treasury', ['kind' => 'receive', 'account_id' => $bank, 'person_id' => $cust, 'invoice_id' => $sale['id'], 'amount' => 594, 'date' => '1405/07/03']);
check('customer settled', person($cust), 0.0);
check('bank 1,000,594', cash($bank), 1000594.0);
$list = ok('GET', '/invoices?kind=sale');
check('sale marked settled', $list[0]['settled'], true);
ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'person_id' => $supp, 'amount' => 910, 'date' => '1405/07/03']);
check('supplier settled', person($supp), 0.0);
$cashbox = ok('GET', '/accounts')[0]['id'];
ok('POST', '/treasury', ['kind' => 'transfer', 'account_id' => $bank, 'to_account_id' => $cashbox, 'amount' => 1000, 'date' => '1405/07/03']);
check('transfer moved money', [cash($bank), cash($cashbox)], [998684.0, 1000.0]);
ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $cashbox, 'amount' => 100, 'description' => 'آب و برق']);
check('expense without person reduces cash', cash($cashbox), 900.0);
books('after treasury');

echo "Cheques\n";
$ch = ok('POST', '/cheques', ['number' => '111', 'direction' => 'received', 'person_id' => $cust, 'amount' => 300, 'due_date' => '1405/08/01']);
check('received cheque credits customer', person($cust), -300.0);
check('collect without account refused', api('POST', '/cheques/' . $ch['id'] . '/action', ['action' => 'collect'])[0], 400);
ok('POST', '/cheques/' . $ch['id'] . '/action', ['action' => 'collect', 'account_id' => $bank]);
check('collected into bank', cash($bank), 998984.0);
check('collected cheque cannot bounce', api('POST', '/cheques/' . $ch['id'] . '/action', ['action' => 'return'])[0], 400);
$ch2 = ok('POST', '/cheques', ['number' => '222', 'direction' => 'received', 'person_id' => $cust, 'amount' => 50]);
ok('POST', '/cheques/' . $ch2['id'] . '/action', ['action' => 'return']);
check('bounced cheque back on customer', person($cust), -300.0);
$ch3 = ok('POST', '/cheques', ['number' => '333', 'direction' => 'received', 'person_id' => $cust, 'amount' => 70]);
check('spend needs the receiver', api('POST', '/cheques/' . $ch3['id'] . '/action', ['action' => 'spend'])[0], 400);
ok('POST', '/cheques/' . $ch3['id'] . '/action', ['action' => 'spend', 'person_id' => $supp]);
check('spent cheque: customer credited, supplier debited', [person($cust), person($supp)], [-370.0, 70.0]);
$pc = ok('POST', '/cheques', ['number' => '444', 'direction' => 'payable', 'person_id' => $supp, 'amount' => 70, 'account_id' => $bank]);
check('issued cheque', person($supp), 140.0);
ok('POST', '/cheques/' . $pc['id'] . '/action', ['action' => 'return']);
check('returned own cheque', person($supp), 70.0);
books('after cheques');

echo "Edit and delete invoices\n";
$s2 = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'date' => '1405/07/05', 'items' => [['product_id' => $prod, 'qty' => 4, 'price' => 200]]]);
check('stock 8 after sale of 4', product($prod)['stock'], 8.0);
ok('PUT', '/invoices/' . $s2['id'], ['kind' => 'sale', 'person_id' => $cust, 'date' => '1405/07/05', 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 200]]]);
check('edited to 1: stock 11', product($prod)['stock'], 11.0);
check('customer: -370 + 220', person($cust), -150.0);
check('edit keeps a single live tax invoice', count(ok('GET', '/tax-invoices?kind=sale')), 2);
ok('DELETE', '/invoices/' . $s2['id']);
check('deleted: stock back to 12', product($prod)['stock'], 12.0);
check('deleted: customer back', person($cust), -370.0);
books('after edit/delete');

echo "Returns, pro-forma\n";
$sr = ok('POST', '/invoices', ['kind' => 'sale_return', 'person_id' => $cust, 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 200]]]);
check('sale return total', $sr['total'], 220.0);
check('stock 13', product($prod)['stock'], 13.0);
$pf = ok('POST', '/invoices', ['kind' => 'sale_proforma', 'person_id' => $cust, 'items' => [['product_id' => $prod, 'qty' => 2, 'price' => 250]]]);
check('pro-forma: no tax, no stock change', [$pf['tax'], product($prod)['stock']], [0.0, 13.0]);
$fin = ok('POST', '/invoices/' . $pf['id'] . '/finalize');
check('finalized to sale with VAT', [$fin['kind'], $fin['total']], ['sale', 550.0]);
check('stock 11', product($prod)['stock'], 11.0);
books('after returns');

echo "Warehouses\n";
$w2 = ok('POST', '/warehouses', ['name' => 'انبار دوم'])['id'];
$w1 = ok('GET', '/warehouses')[0]['id'];
ok('POST', '/warehouse-docs', ['kind' => 'transfer', 'warehouse_id' => $w1, 'to_warehouse_id' => $w2, 'items' => [['product_id' => $prod, 'qty' => 4]]]);
$st = array_column(ok('GET', '/stock?warehouse_id=' . $w2), 'qty');
check('transfer: 4 in second warehouse, total unchanged', [$st, product($prod)['stock']], [[4.0], 11.0]);
ok('POST', '/warehouse-docs', ['kind' => 'issue', 'warehouse_id' => $w2, 'items' => [['product_id' => $prod, 'qty' => 1]]]);
ok('POST', '/stock-counts', ['warehouse_id' => $w1, 'items' => [['product_id' => $prod, 'counted_qty' => 5]]]);
check('count: main warehouse 7 -> 5', product($prod)['stock'], 8.0);
$k = ok('GET', '/kardex/' . $prod);
check('kardex ends at current stock', end($k['rows'])['balance'], 8.0);
books('after warehouse');
$bs = ok('GET', '/reports/balance-sheet');
check('inventory account = stock x average cost', round($bs['assets']['inventory'], 2), round(8 * product($prod)['avg_cost'], 2));

echo "Manual journal, void, closing\n";
$coa = array_column(ok('GET', '/coa'), 'id', 'code');
check('unbalanced journal refused', api('POST', '/journals', ['description' => 'x', 'lines' => [['account_id' => $coa['5102'], 'debit' => 10], ['account_id' => $coa['3101'], 'credit' => 9]]])[0], 400);
check('kol account refused', api('POST', '/journals', ['description' => 'x', 'lines' => [['account_id' => $coa['51'], 'debit' => 10], ['account_id' => $coa['3101'], 'credit' => 10]]])[0], 400);
$mj = ok('POST', '/journals', ['description' => 'هزینه دستی', 'lines' => [['account_id' => $coa['5102'], 'debit' => 10], ['account_id' => $coa['3101'], 'credit' => 10]]]);
ok('POST', '/journals/' . $mj['id'] . '/void');
check('void twice refused', api('POST', '/journals/' . $mj['id'] . '/void')[0], 400);
$auto = array_values(array_filter(ok('GET', '/journals'), fn($j) => $j['source_type'] === 'invoice' && $j['status'] === 'final'))[0];
check('automatic entry cannot be voided by hand', api('POST', '/journals/' . $auto['id'] . '/void')[0], 400);
$profit = ok('GET', '/reports/profit-loss')['profit'];
$cl = ok('POST', '/journals/closing');
check('closing moves the profit', round($cl['profit'], 2), round($profit, 2));
$open_pl = array_filter(ok('GET', '/trial-balance')['rows'], fn($r) => in_array($r['code'][0], ['4', '5'], true) && abs($r['balance']) > 0.01);
check('after closing every income/expense account is zero', array_values($open_pl), []);
check('P&L report still shows the year (closing excluded)', round(ok('GET', '/reports/profit-loss')['profit'], 2), round($profit, 2));
check('second closing refused', api('POST', '/journals/closing')[0], 400);
books('after closing');

echo "Fiscal lock, users and permissions\n";
ok('POST', '/fiscal/lock');
check('locked: no invoice', api('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 1]]])[0], 400);
ok('POST', '/fiscal/unlock');
check('short password refused', api('POST', '/users', ['username' => 'ali', 'password' => '1', 'role' => 'seller'])[0], 400);
ok('POST', '/users', ['username' => 'ali', 'password' => 'seller-pass-1', 'full_name' => 'علی', 'role' => 'seller']);
$st = api('POST', '/login', ['username' => 'ali', 'password' => 'seller-pass-1'])[1]['token'];
check('seller: no journals', api('GET', '/journals', null, $st)[0], 403);
check('seller: no purchases', api('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $supp, 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 1]]], $st)[0], 403);
check('seller: can sell', api('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 200]]], $st)[0], 200);
check('seller: no users list', api('GET', '/users', null, $st)[0], 403);
check('person with history cannot be deleted', api('DELETE', '/persons/' . $cust)[0], 400);
check('activity log', in_array('create_invoice', array_column(ok('GET', '/logs'), 'action'), true), true);

check('password change needs the old one', api('PUT', '/me/password', ['old_password' => 'x', 'new_password' => 'new-pass-12345'])[0], 400);
ok('PUT', '/me/password', ['old_password' => 'apppass123', 'new_password' => 'new-pass-12345']);
check('old sessions end after a password change', api('GET', '/me')[0], 401);
check('old password no longer works', api('POST', '/login', ['username' => 'admin', 'password' => 'apppass123'])[0], 401);
$TOKEN = api('POST', '/login', ['username' => 'admin', 'password' => 'new-pass-12345'])[1]['token'];
check('new password works', api('GET', '/me')[0], 200);

echo "Files and printing\n";
$ticket = fn($path) => api('POST', '/download-ticket', ['path' => $path])[1]['ticket'] ?? '';
check('the session is not accepted in a link (?token=)', api('GET', '/invoices/' . $sale['id'] . '/print?token=' . $TOKEN, null, '')[0], 401);
$tk = $ticket('/invoices/' . $sale['id'] . '/print');
[$c, $html] = api('GET', '/invoices/' . $sale['id'] . '/print?ticket=' . $tk, null, '');
check('print works with a one-time ticket', [$c, strpos($html, 'فاکتور فروش') !== false], [200, true]);
check('the ticket works only once', api('GET', '/invoices/' . $sale['id'] . '/print?ticket=' . $tk, null, '')[0], 401);
check('a ticket opens only its own address', api('GET', '/backup?ticket=' . $ticket('/export/csv'), null, '')[0], 401);
check('no tickets for API data', api('POST', '/download-ticket', ['path' => '/persons'])[0], 400);
[$c, $csv] = api('GET', '/export/csv?what=sales&ticket=' . $ticket('/export/csv'), null, '');
check('CSV with BOM for Excel', [$c, substr($csv, 0, 3)], [200, "\xEF\xBB\xBF"]);
$ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=/attachments&object_type=invoice&object_id=" . $sale['id']);
file_put_contents("$tmp/a.txt", 'hello');
curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN"],
    CURLOPT_POSTFIELDS => ['file' => new CURLFile("$tmp/a.txt", 'text/plain', '../../evil.txt')]]);
$att = json_decode(curl_exec($ch), true);
curl_close($ch);
check('upload: name cleaned', $att['filename'] ?? null, 'evil.txt');
[$c, $data] = api('GET', '/attachments/' . $att['id'] . '/download?ticket=' . $ticket('/attachments/' . $att['id'] . '/download'), null, '');
check('download', [$c, $data], [200, 'hello']);
check('internal files not served', (function () use ($port) {
    $r = [];
    foreach (['acc_core.php', 'data/bank.sqlite', 'config.php'] as $f) {
        $ch = curl_init("http://127.0.0.1:$port/$f");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_exec($ch);
        $r[] = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    }
    return $r;
})(), [404, 404, 404]);
echo "SMS panel\n";
file_put_contents("$tmp/sms.php", '<?php file_put_contents(__DIR__ . "/sms.log", json_encode($_GET + $_POST, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
if (($_GET["to"] ?? $_POST["to"] ?? "") === "09120000000") { http_response_code(500); echo "bad number"; exit; } echo "OK-" . rand(100, 999);');
$smsPort = $port + 1;
$mock = proc_open(['php', '-S', "127.0.0.1:$smsPort", "$tmp/sms.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $mp);
usleep(400000);
$smsSent = function () use ($tmp) {
    $l = is_file("$tmp/sms.log") ? array_map(fn($x) => json_decode($x, true), file("$tmp/sms.log", FILE_IGNORE_NEW_LINES)) : [];
    @unlink("$tmp/sms.log");
    return $l;
};
check('custom URL must have {to}', api('PUT', '/sms/settings', ['sms_provider' => 'custom', 'sms_custom_url' => 'http://x/'])[0], 400);
ok('PUT', '/sms/settings', ['sms_provider' => 'custom', 'sms_custom_url' => "http://127.0.0.1:$smsPort/send?to={to}&msg={text}&from={from}", 'sms_sender' => '3000']);
check('api key never shown back', ok('GET', '/sms/settings')['sms_api_key'], '');
ok('PUT', '/persons/' . $cust, ['name' => 'مشتری الف', 'type' => 'customer', 'national_id' => '1234567890', 'mobile' => '۰۹۱۲۱۲۳۴۵۶۷', 'groups' => 'عمده، وفادار']);
ok('PUT', '/persons/' . $supp, ['name' => 'تأمین‌کننده ب', 'type' => 'supplier', 'mobile' => '+989351112233']);
check('groups saved', array_column(ok('GET', '/persons'), 'groups', 'id')[$cust], 'عمده,وفادار');
$tpl = ok('POST', '/sms/templates', ['title' => 'تست', 'body' => '{name} مانده {balance} ریال - {company}'])['id'];
check('invalid mobile refused', api('POST', '/sms/send', ['mobile' => '12345', 'text' => 'x'])[0], 400);
ok('POST', '/sms/send', ['person_id' => $cust, 'template_id' => $tpl]);
$sent = $smsSent();
check('single SMS: normalised number, filled template', [$sent[0]['to'] ?? null, $sent[0]['msg'] ?? null, $sent[0]['from'] ?? null],
    ['09121234567', 'مشتری الف مانده ' . number_format(abs(person($cust))) . ' ریال - شرکت من', '3000']);
$g = ok('POST', '/sms/group', ['target' => 'all', 'text' => 'سلام {name}', 'numbers' => "09121234567\n09351112233, 09129998877, 0912000000"]);
check('group: duplicates and bad numbers dropped', [$g['queued'], $g['invalid']], [3, 0]);
check('group: personalised texts', array_column($smsSent(), 'msg', 'to'), ['09121234567' => 'سلام مشتری الف', '09351112233' => 'سلام تأمین‌کننده ب', '09129998877' => 'سلام']);
ok('POST', '/sms/group', ['target' => 'group', 'group' => 'وفادار', 'text' => 'ویژه']);
check('group by person group', array_column($smsSent(), 'to'), ['09121234567']);
[$c, $j] = api('POST', '/sms/send', ['mobile' => '09120000000', 'text' => 'x']);
check('provider error shown', [$c, strpos($j['detail'] ?? '', 'HTTP 500') !== false], [502, true]);
$log = ok('GET', '/sms/log');
check('history with counts', [$log['counts']['sent'] ?? 0, $log['counts']['failed'] ?? 0], [5, 1]);
$pat = ok('POST', '/sms/patterns', ['title' => 'فاکتور', 'code' => '12345', 'params' => 'name, total'])['id'];
check('pattern params parsed', ok('GET', '/sms/patterns')[0]['params'], ['name', 'total']);
ok('PUT', '/sms/settings', ['sms_auto_invoice' => true, 'sms_invoice_template' => ok('GET', '/sms/templates')[1]['id']]);
$smsSent();
$inv3 = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 200]]]);
// the queued invoice SMS goes out with the background job
exec('php ' . escapeshellarg("$S/cron.php") . ' health > /dev/null 2>&1');
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "jobs.php"; acc_sms_process(10);'));
$sent = $smsSent();
check('automatic invoice SMS', [count($sent), strpos($sent[0]['msg'] ?? '', $inv3['number']) !== false], [1, true]);
check('seller cannot change SMS settings', api('PUT', '/sms/settings', ['sms_provider' => 'test'], $st)[0], 403);
proc_terminate($mock);

echo "-- more features\n";
$pb = ok('POST', '/phonebook', ['name' => 'تعمیرکار', 'phones' => '02122223333']);
check('phone book lists people too', count(array_filter(ok('GET', '/phonebook?q=' . rawurlencode('مشتری')), fn($r) => $r['source'] === 'person')) > 0, true);
$br = ok('POST', '/brands', ['name' => 'برند الف'])['id'];
$dep = ok('POST', '/departments', ['name' => 'فروشگاه'])['id'];
$mk = ok('POST', '/persons', ['name' => 'بازاریاب ج', 'type' => 'marketer', 'commission_rate' => 5])['id'];
$svc = ok('POST', '/products', ['name' => 'خدمت نصب', 'kind' => 'service', 'sale_price' => 1000])['id'];
$s2 = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $cust, 'marketer_id' => $mk, 'department_id' => $dep, 'due_date' => '1400/01/01',
    'items' => [['product_id' => $prod, 'qty' => 1, 'price' => 400], ['product_id' => $svc, 'qty' => 1, 'price' => 1000]]]);
check('service has no stock', product($svc)['stock'], 0);
books('service sale');
$mr = array_values(array_filter(ok('GET', '/reports/marketers'), fn($r) => $r['id'] === $mk))[0];
check('marketer commission 5%', [$mr['sales'], $mr['commission']], [1400, 70]);
ok('POST', "/reports/marketers/$mk/commission");
check('commission credited to marketer', person($mk), -70);
check('department profit', array_values(array_filter(ok('GET', '/reports/departments'), fn($r) => $r['department_id'] === $dep))[0]['revenue'], 1400);
check('due invoice overdue', array_values(array_filter(ok('GET', '/reports/due-invoices'), fn($r) => $r['id'] === $s2['id']))[0]['overdue'], true);
foreach (['avg', 'fifo', 'avg_to_date', 'last'] as $m) {
    $pr = ok('GET', "/reports/profit?method=$m");
    check("profit report $m has revenue", $pr['total']['revenue'] > 0, true);
}
check('trade report', ok('GET', '/reports/trade?group=sale')['totals']['count'] > 0, true);
// loan received in 2 installments with interest
$cash0 = cash($bank);
$loan = ok('POST', '/loans', ['direction' => 'received', 'account_id' => $bank, 'amount' => 1000, 'interest_total' => 100, 'installments' => 2, 'start_date' => '1405/08/01']);
check('loan in bank', cash($bank), $cash0 + 1000);
$l = ok('GET', '/loans')[0];
check('installments', array_column($l['schedule'], 'amount'), [550, 550]);
ok('POST', '/loans/installments/' . $l['schedule'][0]['id'] . '/pay');
check('installment paid from bank', cash($bank), $cash0 + 450);
check('loan remaining', ok('GET', '/loans')[0]['remaining'], 550);
books('loan');
// pre-receipt then settle an invoice with it
$bal = person($cust);
$adv = ok('POST', '/advances', ['kind' => 'prereceive', 'person_id' => $cust, 'account_id' => $bank, 'amount' => 300]);
check('advance listed', ok('GET', '/advances')[0]['amount'], 300);
check('pre-receipt counts in the person total', person($cust), $bal - 300);
ok('POST', '/advances/apply', ['kind' => 'prereceive', 'person_id' => $cust]);
check('advance applied', [person($cust), count(ok('GET', '/advances'))], [$bal - 300, 0]);
books('advance');
// expense type and paying it
$et = ok('POST', '/expense-types', ['name' => 'اجاره'])['id'];
$tr = ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'amount' => 40, 'counter_account_id' => $et, 'description' => 'اجاره مهر']);
check('expense type in list', in_array($et, array_column(ok('GET', '/expense-types'), 'id'), true), true);
$c1 = cash($bank);
ok('DELETE', '/treasury/' . $tr['id']);
check('treasury delete restores bank', cash($bank), $c1 + 40);
books('expense');
// production: 2 x prod -> 1 x made product, overhead 10
$made = ok('POST', '/products', ['name' => 'محصول ساخته', 'sale_price' => 900])['id'];
$bom = ok('POST', '/boms', ['product_id' => $made, 'qty_out' => 1, 'extra_cost' => 10, 'items' => [['product_id' => $prod, 'qty' => 2]]])['id'];
$st0 = product($prod)['stock'];
[$c] = api('POST', '/productions', ['bom_id' => $bom, 'qty' => 2]);
check('production needs the materials in the warehouse', $c, 400);
$pr = ok('POST', '/productions', ['bom_id' => $bom, 'qty' => 1]);
check('production stock', [product($prod)['stock'], product($made)['stock']], [$st0 - 2, 1]);
books('production');
ok('DELETE', '/productions/' . $pr['id']);
check('production delete', [product($prod)['stock'], product($made)['stock']], [$st0, 0]);
books('production delete');
// guarantee, cheque book, labels, import, reports
ok('POST', '/guarantees', ['person_id' => $cust, 'kind' => 'سفته', 'number' => 'G1', 'amount' => 5000]);
check('guarantee', ok('GET', '/guarantees')[0]['status_label'], 'جاری');
ok('POST', '/cheque-books', ['account_id' => $bank, 'serial_from' => '440', 'serial_to' => '449']);
check('cheque book used leaves', [ok('GET', '/cheque-books')[0]['used'], ok('GET', '/cheque-books')[0]['next']], [1, 440]);
[$c, $lab] = api('GET', "/labels?ids=$prod");
check('labels page has barcode', [$c, strpos($lab, '<svg') !== false], [200, true]);
$im = ok('POST', '/import/persons', ['csv' => "نام,موبایل,نوع,مانده\nوارداتی یک,09120001111,مشتری,500\nمشتری الف,,,\n"]);
check('import persons', [$im['imported'], $im['skipped']], [1, 1]);
$im = ok('POST', '/import/products', ['csv' => "name;code;sale_price;stock\nکالای وارداتی;IMP1;300;5\n"]);
check('import products', $im['imported'], 1);
books('import');
check('accounts summary', count(ok('GET', '/reports/accounts')) > 3, true);
check('cash statement ends at balance', ok('GET', "/reports/cash/$bank")['balance'], cash($bank));
check('operations', count(ok('GET', '/reports/operations')) > 5, true);
check('order estimate', isset(ok('GET', '/reports/order-estimate')['rows']), true);
ok('GET', '/reports/unused');
[$c, $csv] = api('GET', '/reports/ttms?year=1405&season=3');
check('ttms csv', $c, 200);
// broken cache gets repaired
exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; ba_db()->exec("UPDATE acc_persons SET balance = 123456");'));
$rp = ok('POST', '/tools/repair');
check('repair fixed balances', count($rp['fixed']) > 0 && $rp['unbalanced_journals'] === 0, true);
check('balance after repair', person($cust), $bal - 300);
ok('POST', '/tools/vacuum');
books('repair');
$cy = ok('POST', '/tools/close-year');
check('new fiscal year', $cy['year'], (string)((int)$cy['closed'] + 1));
books('year closed');

echo "-- bank assistant link\n";
$bankphp = function ($code) use ($S) {
    exec('php -r ' . escapeshellarg('chdir("' . $S . '"); require "lib.php"; require "acc_bank.php"; ' . $code), $o);
    return implode("\n", $o);
};
ok('POST', '/tools/repair');
$bl = ok('PUT', '/bank-link', ['enabled' => true]);
check('bank wallets listed', count($bl['wallets']) >= 2, true);
// a payment to a person, an expense and a transfer, confirmed in the bank assistant
$ids = $bankphp('$db = ba_db(); $cat = fn($k, $d) => (int)$db->query("SELECT id FROM categories WHERE kind = \'$k\' AND direction IN (\'$d\', \'both\') ORDER BY id LIMIT 1")->fetchColumn();
    $mk = function ($dir, $amt) use ($db) { $db->prepare("INSERT INTO transactions (source, direction, amount, bank_date, occurred_at, status, wallet_id) VALUES (\'manual\', ?, ?, \'1406/01/10\', \'2027-03-30 10:00:00\', \'pending\', 1)")->execute([$dir, $amt]); return (int)$db->lastInsertId(); };
    $a = $mk("out", 5000); ba_confirm_tx($a, "قرض", "علی بانکی", $cat("party", "out"));
    $b = $mk("out", 700); ba_confirm_tx($b, "ناهار", "", $cat("pl", "out"));
    $c = $mk("in", 2000); ba_confirm_tx($c, "فروش نقدی", "", $cat("pl", "in"));
    echo json_encode([$a, $b, $c]);');
[$ta, $tb, $tc] = json_decode($ids, true) ?: [0, 0, 0];
$ali = array_values(array_filter(ok('GET', '/persons'), fn($p) => $p['name'] === 'علی بانکی'))[0] ?? null;
check('person made from the bank party, owes us', $ali ? $ali['balance'] : null, 5000);
$bankAcc = array_values(array_filter(ok('GET', '/accounts'), fn($a) => $a['name'] === 'بانک ملت'))[0] ?? null;
check('wallet became a bank account', $bankAcc ? $bankAcc['balance'] : null, -3700);
books('bank link');
// changing the answer replaces the entry; ignoring reverses it
$bankphp('ba_confirm_tx(' . (int)$ta . ', "قرض", "علی بانکی", (int)ba_db()->query("SELECT id FROM categories WHERE kind = \'party\' AND direction IN (\'out\', \'both\') ORDER BY id LIMIT 1")->fetchColumn(), "", null);
    ba_db()->prepare("UPDATE transactions SET amount = 6000 WHERE id = ?")->execute([' . (int)$ta . ']); ba_acc_link_tx(' . (int)$ta . ');');
check('edit replaces entry', person($ali['id']), 6000);
$bankphp('ba_db()->prepare("UPDATE transactions SET status = \'ignored\' WHERE id = ?")->execute([' . (int)$tb . ']); ba_acc_link_tx(' . (int)$tb . ');');
check('ignored transaction reversed', cash($bankAcc['id']), -6000 + 2000);
check('one treasury row per transaction', ok('GET', '/bank-link')['imported'], 2);
ok('PUT', '/bank-link', ['enabled' => false]);
check('sync while off refused', api('POST', '/bank-link/sync')[0], 400);
ok('PUT', '/bank-link', ['enabled' => true]);
check('sync all: nothing new', ok('POST', '/bank-link/sync')['imported'], 0);
books('bank link final');

echo "-- tax authority (سامانه مؤدیان)\n";
$taxMock = proc_open(['python3', __DIR__ . '/moadian_mock.py', (string)$taxPort], [1 => ['file', '/dev/null', 'w'], 2 => ['file', "$tmp/tax.log", 'w']], $tp);
usleep(900000);
$tinv = ok('GET', '/tax-invoices?kind=sale')[0];
[$c, $j] = api('POST', '/tax-invoices/' . $tinv['id'] . '/send');
check('send refused without keys', [$c, strpos($j['detail'] ?? '', 'حافظه') !== false], [400, true]);
$pk = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
openssl_pkey_export($pk, $pkPem);
$crt = openssl_csr_sign(openssl_csr_new(['commonName' => 'Test Co', 'serialNumber' => '14000000000'], $pk), null, $pk, 365);
openssl_x509_export($crt, $crtPem);
ok('PUT', '/company', ['economic_code' => '14000000000', 'tax_memory' => 'A1B2C3']);
$keys = ok('PUT', '/tax/keys', ['private_key' => $pkPem, 'certificate' => $crtPem]);
check('key and certificate match', $keys['key_matches_certificate'], true);
check('private key never returned', strpos(json_encode(ok('GET', '/company')), 'PRIVATE') === false, true);
check('connection test', ok('POST', '/tax/test')['server_key_id'], 'k1');
[$c, $j] = api('POST', '/tax-invoices/' . $tinv['id'] . '/send');
check('product without 13-digit tax code refused', [$c, strpos($j['detail'] ?? '', '۱۳ رقمی') !== false], [400, true]);
foreach (ok('GET', '/products') as $p) {
    ok('PUT', '/products/' . $p['id'], ['name' => $p['name'], 'tax_code' => '2710000138624', 'sale_price' => $p['sale_price'], 'buy_price' => $p['buy_price']]);
}
$sent = ok('POST', '/tax-invoices/' . $tinv['id'] . '/send');
check('sent: signed, encrypted, accepted by the server', [$sent['status'], strlen($sent['taxid']), $sent['reference'] !== ''], ['sent', 22, true]);
check('inquiry: accepted', ok('POST', '/tax-invoices/' . $tinv['id'] . '/inquire')['status'], 'accepted');
$cx = ok('POST', '/tax-invoices/' . $tinv['id'] . '/cancel');
check('cancel sent', $cx['status'], 'cancel_sent');
check('cancel confirmed', ok('POST', '/tax-invoices/' . $tinv['id'] . '/inquire')['status'], 'cancelled');
proc_terminate($taxMock);

echo "-- companies, backup and restore\n";
$co = ok('POST', '/companies', ['name' => 'شرکت دوم', 'copy_from' => 1]);
check('two companies', count(ok('GET', '/companies')), 2);
$cget = function ($path, $cid) use ($port, &$TOKEN) {
    $ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode($path));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN", "X-Company: $cid"]]);
    $o = curl_exec($ch);
    curl_close($ch);
    return $o;
};
$p2 = json_decode($cget('/persons', $co['id']), true);
check('lists copied, without balances', [count($p2) > 3, array_sum(array_column($p2, 'balance'))], [true, 0]);
check('own books: empty journal', count(json_decode($cget('/journals', $co['id']), true)), 0);
check('company name', json_decode($cget('/company', $co['id']), true)['name'], 'شرکت دوم');
check('main company untouched', ok('GET', '/company')['name'] !== 'شرکت دوم', true);
// a non-admin opens only the companies the admin gave
$sellerTok = api('POST', '/login', ['username' => 'ali', 'password' => 'seller-pass-1'])[1]['token'];
$sget = function ($path, $cid) use ($port, $sellerTok) {
    $ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode($path));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $sellerTok", "X-Company: $cid"]]);
    curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $c;
};
check('seller: other company refused', $sget('/persons', $co['id']), 403);
check('seller: sees only the main company', count(api('GET', '/companies', null, $sellerTok)[1]), 1);
$ali = array_values(array_filter(ok('GET', '/users'), fn($x) => $x['username'] === 'ali'))[0];
ok('PUT', '/users/' . $ali['id'] . '/companies', ['companies' => [1, $co['id']]]);
check('admin gave the second company: allowed', $sget('/persons', $co['id']), 200);
check('only admin sets companies', api('PUT', '/users/' . $ali['id'] . '/companies', ['companies' => [1]], $sellerTok)[0], 403);
// company secrets only for admin
ok('PUT', '/company', ['webhook_secret' => 'whsec-1']);
$asSeller = api('GET', '/company', null, $sellerTok)[1];
check('seller: no webhook secret / api key', [isset($asSeller['webhook_secret']), isset($asSeller['api_key'])], [false, false]);
check('admin: webhook secret', ok('GET', '/company')['webhook_secret'] ?? null, 'whsec-1');
$bk = $cget('/backup', 1);
check('backup is a database file', substr($bk, 0, 15), 'SQLite format 3');
$before = count(ok('GET', '/persons'));
ok('POST', '/persons', ['name' => 'بعد از پشتیبان']);
file_put_contents("$tmp/bk.sqlite", $bk);
$ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode('/restore'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN"],
    CURLOPT_POSTFIELDS => ['file' => new CURLFile("$tmp/bk.sqlite")]]);
$rs = json_decode(curl_exec($ch), true);
curl_close($ch);
check('restored', [$rs['ok'] ?? false, count(ok('GET', '/persons'))], [true, $before]);
file_put_contents("$tmp/bad.sqlite", 'not a db');
$ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode('/restore'));
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_HTTPHEADER => ["Authorization: Bearer $TOKEN"],
    CURLOPT_POSTFIELDS => ['file' => new CURLFile("$tmp/bad.sqlite")]]);
curl_exec($ch);
check('bad backup refused', curl_getinfo($ch, CURLINFO_HTTP_CODE), 400);
curl_close($ch);

books('final');

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
