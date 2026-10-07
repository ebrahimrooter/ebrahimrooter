<?php
chdir($argv[1]); require 'lib.php';
$db = ba_db();
$rows = [['in', 185000000, '1405/07/10', '09:42', 'فروش به فروشگاه نور', 'فروشگاه نور', 'party'],
    ['out', 12500000, '1405/07/10', '11:15', 'خرید سوخت تراکتور', '', 'pl'],
    ['in', 42000000, '1405/07/09', '16:30', 'تسویه علی رضایی', 'علی رضایی', 'party'],
    ['out', 3800000, '1405/07/09', '18:05', 'قبض برق', '', 'pl'],
    ['out', 60000000, '1405/07/08', '10:20', 'پرداخت به پخش البرز', 'پخش البرز', 'party'],
    ['in', 27500000, '1405/07/10', '13:02', null, null, null],
    ['out', 8900000, '1405/07/10', '14:47', null, null, null]];
$bal = 912000000;
foreach ($rows as $i => [$dir, $amt, $d, $t, $desc, $party, $kind]) {
    [$y, $m, $dd] = explode('/', $d);
    [$gy, $gm, $gd] = ba_j2g((int)$y, (int)$m, (int)$dd);
    $db->prepare("INSERT INTO transactions (source, direction, amount, balance, bank_date, bank_time, occurred_at, status, wallet_id, account) VALUES ('sms', ?, ?, ?, ?, ?, ?, 'pending', 1, '0123456789')")
        ->execute([$dir, $amt, $bal, $d, $t, sprintf('%04d-%02d-%02d %s:00', $gy, $gm, $gd, $t)]);
    $id = (int)$db->lastInsertId();
    $bal += $dir === 'in' ? $amt : -$amt;
    if ($desc) {
        $cat = (int)$db->query("SELECT id FROM categories WHERE kind = '$kind' AND direction IN ('$dir', 'both') ORDER BY id LIMIT 1")->fetchColumn();
        ba_confirm_tx($id, $desc, $party, $cat);
    }
}
$db->exec("SELECT 1");
