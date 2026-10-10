<?php
$base = 'http://127.0.0.1:8840/acc/api.php?p=';
$T = '';
function api($m, $p, $b = null) {
    global $base, $T;
    [$path, $q] = array_pad(explode('?', $p, 2), 2, '');
    $ch = curl_init($base . rawurlencode($path) . ($q ? "&$q" : ''));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', "Authorization: Bearer $T"], CURLOPT_POSTFIELDS => $b === null ? null : json_encode($b)]);
    $r = json_decode(curl_exec($ch), true);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) !== 200) { fwrite(STDERR, "$m $p: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n"); }
    return $r;
}
$T = api('POST', '/login', ['username' => 'admin', 'password' => 'demo12345'])['token'];
api('PUT', '/company', ['name' => 'بازرگانی نمونه', 'vat_rate' => 10]);
$box = 1; $bank = 2;   // the two accounts every new company starts with
$coa = array_column(api('GET', '/coa'), 'id', 'code');
api('POST', '/journals', ['description' => 'سرمایه اولیه', 'date' => '1405/01/01', 'kind' => 'opening', 'lines' => [
    ['account_id' => $coa['1101'], 'debit' => 850000000, 'cash_account_id' => $bank], ['account_id' => $coa['1101'], 'debit' => 40000000, 'cash_account_id' => $box],
    ['account_id' => $coa['3101'], 'credit' => 890000000]]]);
$people = [];
foreach ([['علی رضایی', 'customer', '09121112233'], ['فروشگاه نور', 'customer', '09123334455'], ['مریم احمدی', 'customer', '09351234567'],
    ['پخش البرز', 'supplier', '02144556677'], ['بذر سبز', 'supplier', '02166778899'], ['حسن کریمی', 'marketer', '09190001122']] as [$n, $t, $m]) {
    $people[$n] = api('POST', '/persons', ['name' => $n, 'type' => $t, 'mobile' => $m, 'commission_rate' => 3])['id'];
}
$prod = []; $price = [];
foreach ([['بذر گوجه فرنگی', 1200000, 1650000], ['کود NPK', 850000, 1100000], ['سم قارچ‌کش', 450000, 620000], ['بذر خیار', 980000, 1350000], ['لوله آبیاری قطره‌ای', 300000, 420000]] as [$n, $b, $s]) {
    $price[$n] = $s;
    $prod[$n] = api('POST', '/products', ['name' => $n, 'buy_price' => $b, 'sale_price' => $s, 'reorder_point' => 10, 'unit' => 'عدد'])['id'];
}
$d = fn($m, $day) => sprintf('1405/%02d/%02d', $m, $day);
api('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $people['پخش البرز'], 'date' => $d(5, 3), 'freight' => 2500000, 'items' => [
    ['product_id' => $prod['بذر گوجه فرنگی'], 'qty' => 60, 'price' => 1200000], ['product_id' => $prod['کود NPK'], 'qty' => 80, 'price' => 850000]]]);
api('POST', '/invoices', ['kind' => 'purchase', 'person_id' => $people['بذر سبز'], 'date' => $d(5, 20), 'discount_percent' => 5, 'items' => [
    ['product_id' => $prod['بذر خیار'], 'qty' => 50, 'price' => 980000], ['product_id' => $prod['سم قارچ‌کش'], 'qty' => 40, 'price' => 450000],
    ['product_id' => $prod['لوله آبیاری قطره‌ای'], 'qty' => 200, 'price' => 300000]]]);
$sales = [];
$plan = [[6, 2, 'علی رضایی', [['بذر گوجه فرنگی', 12], ['کود NPK', 10]]], [6, 15, 'فروشگاه نور', [['بذر خیار', 15], ['لوله آبیاری قطره‌ای', 60]]],
    [6, 28, 'مریم احمدی', [['سم قارچ‌کش', 8], ['کود NPK', 6]]], [7, 3, 'فروشگاه نور', [['بذر گوجه فرنگی', 20], ['بذر خیار', 10]]],
    [7, 8, 'علی رضایی', [['لوله آبیاری قطره‌ای', 70], ['کود NPK', 25]]], [7, 10, 'مریم احمدی', [['بذر گوجه فرنگی', 6]]]];
foreach ($plan as $i => [$m, $day, $who, $items]) {
    $sales[] = api('POST', '/invoices', ['kind' => 'sale', 'person_id' => $people[$who], 'date' => $d($m, $day), 'marketer_id' => $people['حسن کریمی'],
        'due_date' => $d($m + 1, $day), 'discount_percent' => $i % 2 ? 3 : 0,
        'items' => array_map(fn($x) => ['product_id' => $prod[$x[0]], 'qty' => $x[1], 'price' => $price[$x[0]]], $items)]);
}

api('POST', '/treasury', ['kind' => 'receive', 'account_id' => $bank, 'invoice_id' => $sales[0]['id'], 'amount' => $sales[0]['total'], 'date' => $d(6, 10)]);
api('POST', '/treasury', ['kind' => 'receive', 'account_id' => $box, 'invoice_id' => $sales[2]['id'], 'amount' => 5000000, 'date' => $d(7, 1)]);
api('POST', '/cheques', ['number' => '452190', 'direction' => 'received', 'person_id' => $people['فروشگاه نور'], 'amount' => 45000000, 'due_date' => $d(8, 15), 'date' => $d(7, 3), 'bank_name' => 'ملی']);
api('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'person_id' => $people['پخش البرز'], 'amount' => 90000000, 'date' => $d(6, 5)]);
$rent = api('POST', '/expense-types', ['name' => 'اجاره مغازه'])['id'];
api('POST', '/treasury', ['kind' => 'pay', 'account_id' => $bank, 'counter_account_id' => $rent, 'amount' => 25000000, 'date' => $d(7, 1), 'description' => 'اجاره مهر']);
api('POST', '/invoices', ['kind' => 'sale_proforma', 'person_id' => $people['علی رضایی'], 'date' => $d(7, 10), 'items' => [['product_id' => $prod['بذر خیار'], 'qty' => 5, 'price' => $price['بذر خیار']]]]);
// more for the overview: a payable cheque due tomorrow, a loan, a production formula
[$ty, $tm, $td] = array_map('intval', explode('/', '1405/07/14'));
api('POST', '/cheques', ['number' => '788120', 'direction' => 'payable', 'person_id' => $people['بذر سبز'], 'amount' => 30000000, 'due_date' => '1405/07/15', 'date' => $d(7, 5), 'account_id' => $bank]);
api('POST', '/loans', ['direction' => 'received', 'account_id' => $bank, 'amount' => 200000000, 'interest_total' => 36000000, 'installments' => 12, 'start_date' => '1405/08/01', 'description' => 'وام بانک ملت']);
api('POST', '/guarantees', ['person_id' => $people['فروشگاه نور'], 'kind' => 'سفته', 'number' => 'S-1201', 'amount' => 100000000, 'due_date' => '1406/01/01']);
// «کالا و انبار» of the phone apps: a second warehouse, a transfer, an item running low and one out of stock
$farm = api('POST', '/warehouses', ['name' => 'انبار مزرعه'])['id'];
api('POST', '/warehouse-docs', ['kind' => 'transfer', 'warehouse_id' => 1, 'to_warehouse_id' => $farm, 'date' => $d(7, 11), 'description' => 'ارسال به مزرعه',
    'items' => [['product_id' => $prod['کود NPK'], 'qty' => 20], ['product_id' => $prod['لوله آبیاری قطره‌ای'], 'qty' => 60]]]);
$p1 = api('POST', '/products', ['name' => 'سم حشره‌کش', 'code' => '1006', 'buy_price' => 520000, 'sale_price' => 700000, 'reorder_point' => 10, 'unit' => 'لیتر'])['id'];
api('POST', '/warehouse-docs', ['kind' => 'receipt', 'warehouse_id' => 1, 'date' => $d(7, 11), 'description' => 'خرید نقدی', 'items' => [['product_id' => $p1, 'qty' => 6, 'price' => 520000]]]);
api('POST', '/products', ['name' => 'نایلون گلخانه', 'code' => '1007', 'buy_price' => 2400000, 'sale_price' => 3100000, 'reorder_point' => 3, 'unit' => 'رول']);
