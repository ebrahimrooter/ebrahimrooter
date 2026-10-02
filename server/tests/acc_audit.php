<?php
/**
 * Accountant audit over HTTP:  php tests/acc_audit.php
 * Hand-computed figures for a small trading business: every number an
 * accountant would check (costing, VAT, P&L, balance sheet, statements, CSV files).
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/acc-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = $tmp . '/s';
@unlink("$S/config.php");
array_map('unlink', glob("$S/data/*.sqlite*") ?: []);
$taxPort = 33000 + getmypid() % 2000;
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran', 'moadian_url' => 'http://127.0.0.1:$taxPort/requestsmanager/api/v2'];");
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

/** Inventory account (1103) must equal what the products are worth (stock x average cost). */
function stock_matches_books($label)
{
    $value = 0;
    foreach (ok('GET', '/products') as $p) {
        if (($p['kind'] ?? 'goods') !== 'service') {
            $value += $p['stock'] * ($p['avg_cost'] ?: $p['buy_price']);
        }
    }
    $inv = 0;
    foreach (ok('GET', '/trial-balance')['rows'] as $r) {
        if ($r['code'] === '1103') {
            $inv = $r['balance'];
        }
    }
    check("$label: inventory account = stock valuation", round($inv), round($value));
}
function csv_rows($path)
{
    [$c, $body] = api('GET', $path);
    if (substr($body, 0, 3) !== "\xEF\xBB\xBF") {
        global $fails;
        $fails++;
        echo "  FAIL $path: no UTF-8 BOM (Excel shows Persian broken)\n";
    }
    $lines = array_values(array_filter(preg_split('/\r\n|\n/', substr($body, 3)), 'strlen'));
    return array_map(fn($l) => str_getcsv($l, ',', '"', ''), $lines);
}

$TOKEN = ok('POST', '/login', ['username' => 'admin', 'password' => 'apppass123'])['token'];
ok('PUT', '/company', ['vat_rate' => 10, 'name' => 'بازرگانی نمونه']);
$coa = array_column(ok('GET', '/coa'), 'id', 'code');
$fy = ok('GET', '/fiscal')['name'];
$D = fn($m, $d) => sprintf('%s/%02d/%02d', $fy, $m, $d);

echo "1. opening: bank 10,000,000 capital\n";
$bank = ok('POST', '/accounts', ['name' => 'بانک', 'kind' => 'bank', 'balance' => 10000000])['id'];
$cashbox = ok('POST', '/accounts', ['name' => 'صندوق', 'kind' => 'cash'])['id'];
$C = ok('POST', '/persons', ['name' => 'مشتری', 'type' => 'customer', 'national_id' => '0012345678'])['id'];
$S = ok('POST', '/persons', ['name' => 'تأمین‌کننده', 'type' => 'supplier'])['id'];
$A = ok('POST', '/products', ['name' => 'کالای الف', 'buy_price' => 0, 'sale_price' => 200000])['id'];
books('opening');

echo "2. purchase 10 x 100,000, 10% discount, freight 50,000, VAT 10%\n";
$p1 = ok('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $S, 'date' => $D(1, 5), 'discount_percent' => 10, 'freight' => 50000,
    'items' => [['product_id' => $A, 'qty' => 10, 'price' => 100000]]]);
check('purchase 1: subtotal / discount / VAT / total', [$p1['subtotal'], $p1['discount'], $p1['tax'], $p1['total']], [1000000, 100000, 90000, 1040000]);
check('unit cost = (900,000 + 50,000) / 10', product($A)['avg_cost'], 95000);
check('supplier owed 1,040,000', person($S), -1040000);

echo "3. purchase 10 x 120,000\n";
$p2 = ok('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $S, 'date' => $D(1, 10), 'items' => [['product_id' => $A, 'qty' => 10, 'price' => 120000]]]);
check('moving average = (950,000 + 1,200,000) / 20', product($A)['avg_cost'], 107500);
stock_matches_books('after purchases');

echo "4. sale 5 x 200,000, discount 50,000\n";
$s1 = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $C, 'date' => $D(2, 1), 'discount' => 50000, 'items' => [['product_id' => $A, 'qty' => 5, 'price' => 200000]]]);
check('sale: net 950,000, VAT 95,000, total 1,045,000', [$s1['subtotal'] - $s1['discount'], $s1['tax'], $s1['total']], [950000, 95000, 1045000]);
$pl = ok('GET', '/reports/profit-loss');
check('cost of goods sold = 5 x 107,500', -array_sum(array_map(fn($r) => $r['code'] === '5101' ? $r['amount'] : 0, $pl['rows'])), 537500);
check('gross profit 412,500', $pl['profit'], 412500);
[$c, $j] = api('POST', '/invoices', ['kind' => 'sale', 'person_id' => $C, 'items' => [['product_id' => $A, 'qty' => 100, 'price' => 1]]]);
check('selling more than in stock refused', $c, 400);
stock_matches_books('after sale');

echo "5. customer returns 1 (at 190,000 = the discounted price)\n";
ok('POST', '/invoices', ['kind' => 'sale_return', 'person_id' => $C, 'date' => $D(2, 3), 'items' => [['product_id' => $A, 'qty' => 1, 'price' => 190000]]]);
check('customer: 1,045,000 - 209,000', person($C), 836000);
stock_matches_books('after sale return');

echo "6. return 2 to the supplier at 120,000\n";
ok('POST', '/invoices', ['kind' => 'purchase_return', 'person_id' => $S, 'date' => $D(2, 5), 'items' => [['product_id' => $A, 'qty' => 2, 'price' => 120000]]]);
check('stock 20 - 5 + 1 - 2', product($A)['stock'], 14);
check('average after return = (16 x 107,500 - 240,000) / 14', round(product($A)['avg_cost'], 2), round(1480000 / 14, 2));
stock_matches_books('after purchase return');
check('supplier: 1,040,000 + 1,320,000 - 264,000', person($S), -2096000);

echo "7. money: receipt on the sale, payment on purchase 1 without choosing the person, rent\n";
ok('POST', '/treasury', ['kind' => 'receive', 'account_id' => $bank, 'invoice_id' => $s1['id'], 'amount' => 500000, 'date' => $D(2, 10)]);
ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'invoice_id' => $p1['id'], 'amount' => 1040000, 'date' => $D(2, 11)]);
check('payment for an invoice goes to its supplier', person($S), -1056000);
check('purchase 1 settled', array_values(array_filter(ok('GET', '/invoices?group=purchase'), fn($i) => $i['id'] === $p1['id']))[0]['settled'], true);
ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $cashbox, 'amount' => 300000, 'date' => $D(2, 12), 'description' => 'اجاره']);
check('cash box can go negative only as recorded', cash($cashbox), -300000);
ok('POST', '/treasury', ['kind' => 'transfer', 'account_id' => $bank, 'to_account_id' => $cashbox, 'amount' => 1000000, 'date' => $D(2, 12)]);
books('money');

echo "8. cheque from the customer for the rest of the sale, cleared\n";
$ch = ok('POST', '/cheques', ['number' => 'CH1', 'direction' => 'received', 'person_id' => $C, 'amount' => 545000, 'due_date' => $D(3, 1), 'date' => $D(2, 15), 'invoice_id' => $s1['id']]);
check('cheque dated as entered', array_values(array_filter(ok('GET', '/journals'), fn($j) => strpos($j['description'], 'CH1') !== false))[0]['date'] ?? null, $D(2, 15));
check('sale settled by receipt + cheque', array_values(array_filter(ok('GET', '/invoices?group=sale'), fn($i) => $i['id'] === $s1['id']))[0]['settled'], true);
ok('POST', '/cheques/' . $ch['id'] . '/action', ['action' => 'collect', 'account_id' => $bank, 'date' => $D(3, 1)]);
check('customer after receipt and cheque', person($C), 836000 - 500000 - 545000);

echo "9. statements\n";
$pl = ok('GET', '/reports/profit-loss');
// 950,000 sales - 190,000 return - (537,500 - 107,500) COGS - 300,000 rent
check('profit = 950,000 - 190,000 - 430,000 - 300,000', $pl['profit'], 30000);
$tax = ok('GET', '/tax-report');
check('VAT on sales 95,000', $tax['vat_sale'], 95000);
$bs = books('statements');
check('VAT: input 90,000 + 120,000 - 24,000 vs output 95,000 - 19,000', [$bs['assets']['vat_credit'], $bs['liabilities']['vat']], [186000, 76000]);
check('bank = 10,000,000 + 500,000 - 1,040,000 - 1,000,000 + 545,000', cash($bank), 9005000);
check('cash box = -300,000 + 1,000,000', cash($cashbox), 700000);
check('balance sheet cash = both accounts', $bs['assets']['cash'], 9705000);
check('equity = capital 10,000,000 + profit 30,000', $bs['equity']['total'], 10030000);
$cf = ok('GET', '/reports/cashflow');
check('cash flow: in 1,045,000 (receipt + cheque), out 1,340,000, transfers apart', [$cf['operating_in'], $cf['operating_out'], $cf['transfers']], [1045000, 1340000, 1000000]);
$st = ok('GET', "/reports/person/$C");
check('customer statement ends at the balance', end($st['rows'])['balance'], person($C));
$led = ok('GET', '/reports/ledger/' . $coa['1103']);
check('inventory ledger ends at 1,480,000', round($led['balance']), 1480000);

echo "9b. management reports and warehouse\n";
$fifo = array_values(array_filter(ok('GET', '/reports/profit?method=fifo')['invoices'], fn($r) => $r['id'] === $s1['id']))[0];
check('FIFO cost of the first sale = 5 x 95,000', $fifo['cost'], 475000);
$avg = array_values(array_filter(ok('GET', '/reports/profit?method=avg')['invoices'], fn($r) => $r['id'] === $s1['id']))[0];
check('moving-average cost of the first sale', $avg['cost'], 537500);
$wh2 = ok('POST', '/warehouses', ['name' => 'انبار دوم'])['id'];
$wh1 = array_values(array_filter(ok('GET', '/warehouses'), fn($w) => $w['is_default']))[0]['id'];
ok('POST', '/warehouse-docs', ['kind' => 'transfer', 'warehouse_id' => $wh1, 'to_warehouse_id' => $wh2, 'items' => [['product_id' => $A, 'qty' => 4]]]);
[$c] = api('POST', '/warehouse-docs', ['kind' => 'issue', 'warehouse_id' => $wh2, 'items' => [['product_id' => $A, 'qty' => 5]]]);
check('issuing more than the warehouse has refused', $c, 400);
ok('POST', '/warehouse-docs', ['kind' => 'issue', 'warehouse_id' => $wh2, 'items' => [['product_id' => $A, 'qty' => 1]]]);
ok('POST', '/stock-counts', ['warehouse_id' => $wh2, 'items' => [['product_id' => $A, 'counted_qty' => 2]]]);
check('stock after transfer, issue and count (shortage 1)', product($A)['stock'], 12);
$kx = ok('GET', "/kardex/$A");
check('kardex ends at the stock', end($kx['rows'])['balance'], 12);
stock_matches_books('after warehouse');
books('warehouse');

echo "10. edit and delete\n";
$B = ok('POST', '/products', ['name' => 'کالای ب'])['id'];
ok('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $S, 'items' => [['product_id' => $B, 'qty' => 10, 'price' => 100]]]);
$pb = ok('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $S, 'items' => [['product_id' => $B, 'qty' => 10, 'price' => 200]]]);
check('average of B 150', product($B)['avg_cost'], 150);
ok('DELETE', '/invoices/' . $pb['id']);
check('deleting the 2nd purchase puts the average back to 100', [product($B)['stock'], product($B)['avg_cost']], [10, 100]);
stock_matches_books('after delete');
$sb = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $C, 'items' => [['product_id' => $B, 'qty' => 4, 'price' => 300]]]);
ok('PUT', '/invoices/' . $sb['id'], ['kind' => 'sale', 'person_id' => $C, 'items' => [['product_id' => $B, 'qty' => 10, 'price' => 300]]]);
check('edit may use the stock the invoice itself took', product($B)['stock'], 0);
stock_matches_books('after edit');
[$c] = api('POST', '/invoices', ['kind' => 'sale', 'person_id' => $C, 'discount' => 999999, 'items' => [['product_id' => $A, 'qty' => 1, 'price' => 100]]]);
check('discount above the total refused', $c, 400);
[$c] = api('POST', '/journals', ['description' => 'x', 'lines' => [['account_id' => $coa['1101'], 'debit' => 10], ['account_id' => $coa['3101'], 'credit' => 10]]]);
check('manual entry on cash needs the cash account', $c, 400);
books('edits');

echo "11. CSV files\n";
$rows = csv_rows('/export/csv?what=sales');
check('sales CSV: header + sales', count($rows), 1 + count(array_filter(ok('GET', '/invoices?group=sale'), fn($i) => $i['kind'] === 'sale')));
check('sales CSV numbers are plain numbers', is_numeric($rows[1][6] ?? 'x'), true);
check('persons CSV', count(csv_rows('/export/csv?what=persons')) - 1, count(ok('GET', '/persons')));
check('products CSV', count(csv_rows('/export/csv?what=products')) - 1, count(ok('GET', '/products')));
$tt = csv_rows('/reports/ttms?year=' . $fy . '&season=1');
check('TTMS CSV: one row per sale of the season (spring: only the first sale)', count($tt) - 1, 1);

echo "12. year end\n";
$y2 = (string)((int)$fy + 1);
$next = ok('POST', '/invoices', ['kind' => 'sale', 'person_id' => $C, 'date' => "$y2/01/05", 'items' => [['product_id' => $A, 'qty' => 1, 'price' => 200000]]]);
$cy = ok('POST', '/tools/close-year');
// + product B: 10 x 300 sold, cost 10 x 100; - one A issued and one A short in the count, at average cost
check('closed with this year\'s profit only', round($cy['profit']), round(30000 + 3000 - 1000 - 2 * 1480000 / 14));
$pl2 = ok('GET', '/reports/profit-loss');
check('new year P&L has only the new year\'s sale', round($pl2['profit']), round(200000 - 1480000 / 14));
[$c] = api('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'amount' => 1000, 'date' => $D(6, 1)]);
check('nothing can be dated in the closed year', $c, 400);
[$c] = api('DELETE', '/invoices/' . $s1['id']);
check('an invoice of the closed year cannot be deleted', $c, 400);
[$c, $j] = api('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'amount' => 1000, 'date' => '2026-01-05']);
check('a Gregorian / wrong date is refused', $c, 400);
$t = ok('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'amount' => 1000, 'date' => '۱۴۰۶/۲/۳']);
check('Persian digits and short dates are accepted', array_values(array_filter(ok('GET', '/treasury'), fn($x) => $x['id'] === $t['id']))[0]['date'], '1406/02/03');
books('after year end');

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
