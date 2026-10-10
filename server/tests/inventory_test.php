<?php
/**
 * «کالا و انبار» of the phone apps: every product with its stock, in total and
 * per warehouse, for the web app (inventory) and the iPhone app (assistant_inventory):
 *   php tests/inventory_test.php
 */

$src = realpath(__DIR__ . '/..');
$tmp = sys_get_temp_dir() . '/inventory-test-' . getmypid();
exec('mkdir -p ' . escapeshellarg($tmp) . ' && cp -r ' . escapeshellarg($src) . ' ' . escapeshellarg($tmp . '/s'));
$S = "$tmp/s";
@unlink("$S/config.php");
exec('rm -rf ' . escapeshellarg($S) . '/data/*.sqlite*');
file_put_contents("$S/config.php", "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'otp_pin' => '4321', 'timezone' => 'Asia/Tehran'];");
$port = 39500 + getmypid() % 1000;
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

$acc = function ($m, $p, $b = null) use ($port, &$T) {
    $ch = curl_init("http://127.0.0.1:$port/acc/api.php?p=" . rawurlencode($p));
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $T], CURLOPT_POSTFIELDS => $b === null ? null : json_encode($b)]);
    return json_decode(curl_exec($ch), true);
};
$T = '';
$T = $acc('POST', '/login', ['username' => 'admin', 'password' => 'apppass123'])['token'] ?? '';
check('signed in to the books', $T !== '');
$a = $acc('POST', '/products', ['name' => 'کود اوره', 'code' => 'K1', 'buy_price' => 500000, 'sale_price' => 700000, 'reorder_point' => 10, 'unit' => 'کیسه'])['id'];
$b = $acc('POST', '/products', ['name' => 'سم علف‌کش', 'code' => 'S1', 'buy_price' => 300000, 'sale_price' => 450000, 'reorder_point' => 5, 'unit' => 'لیتر'])['id'];
$c = $acc('POST', '/products', ['name' => 'نایلون', 'code' => 'N1', 'buy_price' => 100000, 'sale_price' => 150000, 'unit' => 'رول'])['id'];
$acc('POST', '/products', ['name' => 'خدمات سم‌پاشی', 'kind' => 'service', 'sale_price' => 900000]);
$farm = $acc('POST', '/warehouses', ['name' => 'انبار مزرعه'])['id'];
$acc('POST', '/warehouse-docs', ['kind' => 'receipt', 'warehouse_id' => 1, 'items' => [['product_id' => $a, 'qty' => 50, 'price' => 500000], ['product_id' => $b, 'qty' => 4, 'price' => 300000]]]);
$acc('POST', '/warehouse-docs', ['kind' => 'transfer', 'warehouse_id' => 1, 'to_warehouse_id' => $farm, 'items' => [['product_id' => $a, 'qty' => 20]]]);

echo "web app\n";
[$code, $d] = http($A . 'inventory', null, $H);
check('inventory answers', $code === 200 && ($d['ok'] ?? false), json_encode($d, JSON_UNESCAPED_UNICODE));
$by = array_column($d['items'] ?? [], null, 'name');
check('every product listed, services left out', count($by) === 3 && !isset($by['خدمات سم‌پاشی']), implode(',', array_keys($by)));
check('total stock of a product', ($by['کود اوره']['qty'] ?? 0) == 50);
$places = array_column($by['کود اوره']['warehouses'] ?? [], 'qty', 'name');
check('stock per warehouse', ($places['انبار مرکزی'] ?? 0) == 30 && ($places['انبار مزرعه'] ?? 0) == 20, json_encode($places, JSON_UNESCAPED_UNICODE));
check('below the reorder point is «low»', ($by['سم علف‌کش']['status'] ?? '') === 'low');
check('nothing in stock is «out»', ($by['نایلون']['status'] ?? '') === 'out' && $by['نایلون']['warehouses'] === []);
check('value of the stock', ($by['کود اوره']['value'] ?? 0) == 25000000 && $d['totals']['value'] == 25000000 + 1200000);
check('warehouses with their item counts', count($d['warehouses']) === 2 && $d['warehouses'][1]['name'] === 'انبار مزرعه' && $d['warehouses'][1]['count'] === 1);
check('totals: count, low, out', $d['totals']['count'] === 3 && $d['totals']['low'] === 1 && $d['totals']['out'] === 1, json_encode($d['totals']));
check('needs the app password', http($A . 'inventory')[0] === 401);

echo "iPhone app\n";
$tok = http($A . 'assistant_login', ['password' => 'apppass123', 'device_name' => 'iPhone'])[1]['token'] ?? '';
[$code, $i] = http($A . 'assistant_inventory', [], ['Authorization: Bearer ' . $tok]);
check('assistant_inventory with the device token', $code === 200 && count($i['items'] ?? []) === 3, json_encode($i, JSON_UNESCAPED_UNICODE));
check('assistant_inventory refuses without a token', http($A . 'assistant_inventory', [])[0] === 401);

proc_terminate($srv);
exec('rm -rf ' . escapeshellarg($tmp));
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
