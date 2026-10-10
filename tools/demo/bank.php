<?php
chdir($argv[1]); require 'lib.php';
$db = ba_db();
// the four bank cards (seeded by lib.php): card digits + which card each sample goes to
$wid = [];
foreach ($db->query("SELECT id, bank FROM wallets") as $w) { $wid[$w['bank']] = (int)$w['id']; }
foreach (['mellat' => '6104337788', 'melli' => '6037991234', 'saderat' => '6037695566', 'blu' => '6219861122'] as $b => $card) {
    $db->prepare('UPDATE wallets SET card = ? WHERE bank = ?')->execute([substr($card, -4), $b]);
}
$open = ['mellat' => 912000000, 'melli' => 245000000, 'saderat' => 88000000, 'blu' => 46000000];
foreach ($open as $b => $o) { $db->prepare('UPDATE wallets SET opening = ? WHERE bank = ?')->execute([$o, $b]); }
$bals = $open;
$on = ['mellat', 'melli', 'mellat', 'saderat', 'mellat', 'blu', 'melli'];
$rows = [['in', 185000000, '1405/07/10', '09:42', 'فروش به فروشگاه نور', 'فروشگاه نور', 'party'],
    ['out', 12500000, '1405/07/10', '11:15', 'خرید سوخت تراکتور', '', 'pl'],
    ['in', 42000000, '1405/07/09', '16:30', 'تسویه علی رضایی', 'علی رضایی', 'party'],
    ['out', 3800000, '1405/07/09', '18:05', 'قبض برق', '', 'pl'],
    ['out', 60000000, '1405/07/08', '10:20', 'پرداخت به پخش البرز', 'پخش البرز', 'party'],
    ['in', 27500000, '1405/07/10', '13:02', null, null, null],
    ['out', 8900000, '1405/07/10', '14:47', null, null, null]];
foreach ($rows as $i => [$dir, $amt, $d, $t, $desc, $party, $kind]) {
    $bal = $bals[$on[$i]] += $dir === 'in' ? $amt : -$amt;
    [$y, $m, $dd] = explode('/', $d);
    [$gy, $gm, $gd] = ba_j2g((int)$y, (int)$m, (int)$dd);
    $db->prepare("INSERT INTO transactions (source, direction, amount, balance, bank_date, bank_time, occurred_at, status, wallet_id, account) VALUES ('sms', ?, ?, ?, ?, ?, ?, 'pending', ?, '0123456789')")
        ->execute([$dir, $amt, $bal, $d, $t, sprintf('%04d-%02d-%02d %s:00', $gy, $gm, $gd, $t), $wid[$on[$i]] ?? 1]);
    $id = (int)$db->lastInsertId();
    if ($desc) {
        $cat = (int)$db->query("SELECT id FROM categories WHERE kind = '$kind' AND direction IN ('$dir', 'both') ORDER BY id LIMIT 1")->fetchColumn();
        ba_confirm_tx($id, $desc, $party, $cat);
    }
}
$db->exec("SELECT 1");
