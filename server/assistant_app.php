<?php
/**
 * Data for the native iPhone app (ios/), over the paired device token:
 * home screen (balance, deposits/withdrawals, pending bank transactions),
 * monthly report, confirming a transaction, a hand-made entry, and a short
 * accounting-panel session for the in-app panel. Amounts are rial.
 */

require_once __DIR__ . '/assistant.php';

/** Jalali [y, m] of a Gregorian 'Y-m-d…' string. */
function aa_jym($date)
{
    [$y, $m] = ba_g2j((int)substr($date, 0, 4), (int)substr($date, 5, 2), (int)substr($date, 8, 2));
    return [$y, $m];
}

/** Gregorian 'Y-m-d' of the first day of a Jalali month (month may overflow / underflow). */
function aa_jmonth_start($jy, $jm)
{
    while ($jm < 1) { $jm += 12; $jy--; }
    while ($jm > 12) { $jm -= 12; $jy++; }
    [$gy, $gm, $gd] = ba_j2g($jy, $jm, 1);
    return [sprintf('%04d-%02d-%02d', $gy, $gm, $gd), $jy, $jm];
}

const AA_JMONTHS = ['فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];

function aa_tx_out(array $t)
{
    return [
        'id' => (int)$t['id'],
        'direction' => $t['direction'],
        'amount' => (int)$t['amount'],
        'status' => $t['status'],
        'description' => (string)($t['description'] ?? ''),
        'party' => (string)($t['party'] ?? ''),
        'category' => (string)($t['category_name'] ?? ''),
        'wallet' => (string)($t['wallet_name'] ?? ''),
        'bank_date' => (string)($t['bank_date'] ?? ''),
        'bank_time' => (string)($t['bank_time'] ?? ''),
        'occurred_at' => $t['occurred_at'],
        'source' => $t['source'],
        'sms_text' => isset($t['sms_text']) ? (string)$t['sms_text'] : null,
    ];
}

function aa_txs($where, array $args, $limit)
{
    $q = ba_db()->prepare("SELECT t.*, c.name AS category_name, w.name AS wallet_name, s.body AS sms_text
        FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN wallets w ON w.id = t.wallet_id
        LEFT JOIN sms_raw s ON s.id = t.sms_id
        WHERE $where ORDER BY t.occurred_at DESC, t.id DESC LIMIT " . (int)$limit);
    $q->execute($args);
    return array_map('aa_tx_out', $q->fetchAll());
}

/** In / out sums of one period (ignored transactions left out). */
function aa_sums($from, $to)
{
    $q = ba_db()->prepare("SELECT direction, SUM(amount) FROM transactions
        WHERE status != 'ignored' AND occurred_at >= ? AND occurred_at < ? GROUP BY direction");
    $q->execute([$from . ' 00:00:00', $to . ' 00:00:00']);
    $s = ['in' => 0, 'out' => 0];
    foreach ($q->fetchAll(PDO::FETCH_NUM) as [$d, $sum]) {
        $s[$d] = (int)$sum;
    }
    return $s;
}

/** Last $n Jalali months, oldest first: [{label, year, month, in, out}]. */
function aa_months($n = 6)
{
    [$jy, $jm] = aa_jym(date('Y-m-d'));
    $out = [];
    for ($i = $n - 1; $i >= 0; $i--) {
        [$from, $y, $m] = aa_jmonth_start($jy, $jm - $i);
        [$to] = aa_jmonth_start($y, $m + 1);
        $out[] = ['label' => AA_JMONTHS[$m - 1], 'year' => $y, 'month' => $m,
            'from' => $from, 'to' => date('Y-m-d', strtotime($to . ' -1 day'))] + aa_sums($from, $to);
    }
    return $out;
}

function aa_home(array $device)
{
    $db = ba_db();
    $wallets = ba_wallets();
    $months = aa_months(6);
    $cur = $months[5];
    $prev = $months[4];
    $pct = fn($a, $b) => $b > 0 ? round(($a - $b) * 100 / $b) : null;
    $dev = ba_kv_get('device');
    $parties = $db->query("SELECT name, uses FROM parties ORDER BY uses DESC, name LIMIT 8")->fetchAll();
    $cheques = null;
    try {
        require_once __DIR__ . '/acc_api.php';
        acc_use_company(1);
        acc_db();
        $cheques = acc_cheque_due_message(7) ?: null;
    } catch (Throwable $e) {
        $cheques = null;
    }
    $company = '';
    try {
        $company = (string)acc_val('SELECT name FROM acc_company WHERE id = 1');
    } catch (Throwable $e) {
    }
    return [
        'company' => $company,
        'device_name' => $device['name'],
        'balance' => array_sum(array_column($wallets, 'balance')),
        'wallets' => array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name'], 'kind' => $w['kind'],
            'balance' => $w['balance'], 'bank_balance' => $w['bank_balance']], $wallets),
        'month' => ['label' => $cur['label'], 'in' => $cur['in'], 'out' => $cur['out'],
            'in_change' => $pct($cur['in'], $prev['in']), 'out_change' => $pct($cur['out'], $prev['out'])],
        'months' => $months,
        'pending' => aa_txs("t.status = 'pending'", [], 50),
        'recent' => aa_txs("t.status != 'ignored'", [], 20),
        'parties' => array_map(fn($p) => ['name' => $p['name'], 'uses' => (int)$p['uses']], $parties),
        'sms_device' => $dev ? ['last_seen' => $dev['last_seen'],
            'online' => strtotime($dev['last_seen']) > time() - 30 * 60, 'signal' => $dev['signal'] ?? null] : null,
        'cheque_alert' => $cheques,
    ];
}

/** Transactions of a period (Gregorian dates), optionally one direction. */
function aa_list($from, $to, $direction)
{
    $where = "t.occurred_at >= ? AND t.occurred_at <= ? AND t.status != 'ignored'";
    $args = [$from . ' 00:00:00', $to . ' 23:59:59'];
    if ($direction === 'in' || $direction === 'out') {
        $where .= ' AND t.direction = ?';
        $args[] = $direction;
    }
    $items = aa_txs($where, $args, 500);
    $tot = ['in' => 0, 'out' => 0];
    foreach ($items as $t) {
        $tot[$t['direction']] += $t['amount'];
    }
    return ['items' => $items, 'total_in' => $tot['in'], 'total_out' => $tot['out']];
}

/** Hand-made entry (cash etc.), confirmed and posted like the phone web app's «ثبت دستی». */
function aa_manual(array $in)
{
    $db = ba_db();
    $dir = ($in['direction'] ?? '') === 'in' ? 'in' : 'out';
    $amount = (int)round(abs((float)ba_normalize((string)($in['amount_toman'] ?? '0'))) * 10);
    if ($amount <= 0 || $amount > 1e15) {
        throw new InvalidArgumentException('مبلغ نامعتبر است');
    }
    $desc = trim((string)($in['description'] ?? ''));
    if ($desc === '') {
        throw new InvalidArgumentException('بابت چه بود؟ شرح خالی است.');
    }
    $wallet = (int)($in['wallet_id'] ?? 0) ?: (int)$db->query("SELECT id FROM wallets WHERE kind = 'cash' ORDER BY id LIMIT 1")->fetchColumn();
    if (!$wallet) {
        $wallet = (int)$db->query('SELECT id FROM wallets ORDER BY id LIMIT 1')->fetchColumn() ?: null;
    }
    $date = date('Y-m-d');
    [$jy, $jm, $jd] = ba_g2j((int)date('Y'), (int)date('m'), (int)date('d'));
    $db->prepare("INSERT INTO transactions (source, direction, amount, bank_date, occurred_at, status, wallet_id)
        VALUES ('manual', ?, ?, ?, ?, 'pending', ?)")
        ->execute([$dir, $amount, sprintf('%04d/%02d/%02d', $jy, $jm, $jd), $date . ' ' . date('H:i:s'), $wallet]);
    $id = (int)$db->lastInsertId();
    try {
        return ba_confirm_tx($id, $desc, $in['party'] ?? '', 0);
    } catch (Throwable $e) {
        $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
        throw $e;
    }
}

/**
 * A 12-hour session of the accounting panel for the app's built-in panel view.
 * The app keeps it in memory only (non-persistent web view), never on disk.
 */
function aa_acc_session(array $device)
{
    require_once __DIR__ . '/acc_api.php';
    acc_use_company(1);
    acc_db();
    $u = acc_row("SELECT * FROM acc_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
    if (!$u) {
        throw new RuntimeException('کاربر مدیر فعالی در حسابداری نیست', 403);
    }
    $token = bin2hex(random_bytes(24));
    acc_q('DELETE FROM acc_sessions WHERE expires_at < ?', [time()]);
    acc_insert('acc_sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u['id'], 'expires_at' => time() + 12 * 3600]);
    acc_log($u['username'], 'login', 'from the iPhone app: ' . $device['name']);
    return ['token' => $token, 'expires_in' => 12 * 3600];
}
