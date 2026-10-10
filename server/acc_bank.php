<?php
/**
 * Bank assistant -> accounting books.
 *
 * When the link is on, every transaction confirmed in the bank assistant
 * (bank SMS, Bale bot, voice, or by hand) becomes a receipt / payment /
 * transfer in the accounting module:
 *   wallet               -> a cash/bank account (made on first use)
 *   "person" category    -> receipt from / payment to that person (made by name)
 *   income category      -> other income (4103)
 *   expense category     -> an expense type with the category's name (51xx)
 *   transfer category    -> transfer between the two accounts
 * Changing or ignoring the transaction later replaces / reverses its entry.
 */

require_once __DIR__ . '/acc_api.php';

function acc_bank_settings()
{
    return (array)ba_kv_get('acc_bank_link', []) + ['enabled' => true, 'wallets' => []];
}

/** Accounting cash account of a bank-assistant wallet. */
function acc_bank_account($wallet_id)
{
    $s = acc_bank_settings();
    $id = (int)($s['wallets'][(string)$wallet_id] ?? 0);
    if ($id && acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [$id])) {
        return $id;
    }
    $q = ba_db()->prepare('SELECT * FROM wallets WHERE id = ?');
    $q->execute([(int)$wallet_id]);
    $w = $q->fetch();
    if (!$w) {
        throw new AccError('حساب دستیار بانک یافت نشد');
    }
    $id = (int)acc_val('SELECT id FROM acc_cash_accounts WHERE name = ?', [$w['name']]);
    if (!$id) {
        $id = acc_insert('acc_cash_accounts', ['name' => $w['name'], 'kind' => $w['kind'] === 'cash' ? 'cash' : 'bank', 'balance' => 0]);
        if ((int)$w['opening']) {
            acc_post(acc_today(), 'مانده اول ' . $w['name'], [acc_cash_line($id, (int)$w['opening'], 'افتتاحیه'),
                acc_line('3101', max(-(int)$w['opening'], 0), max((int)$w['opening'], 0), 'افتتاحیه')], 'opening', 'cash_account', $id);
        }
    }
    $s['wallets'][(string)$wallet_id] = $id;
    ba_kv_set('acc_bank_link', $s);
    return $id;
}

function acc_bank_person($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        return null;
    }
    $id = (int)acc_val('SELECT id FROM acc_persons WHERE name = ?', [$name]);
    if ($id) {
        return $id;
    }
    return acc_insert('acc_persons', ['name' => $name, 'type' => 'other', 'code' => 'O' . sprintf('%03d', acc_next('person'))]);
}

/** Expense type (51xx) named like the bank category; made when missing. */
function acc_bank_expense_account($name)
{
    $name = trim((string)$name);
    if ($name === '') {
        return acc_account_id('5102');
    }
    $id = (int)acc_val("SELECT id FROM acc_coa WHERE name = ? AND parent_code = '51'", [$name]);
    if ($id) {
        return $id;
    }
    $max = (int)acc_val("SELECT MAX(CAST(code AS INTEGER)) FROM acc_coa WHERE parent_code = '51'");
    return acc_insert('acc_coa', ['code' => (string)max($max + 1, 5110), 'name' => $name, 'level' => 'moein', 'nature' => 'debit', 'parent_code' => '51']);
}

/** Brings one bank-assistant transaction in line with the books. Returns the treasury number or null. */
function acc_bank_sync_tx($tx_id)
{
    acc_db();
    $old = acc_row('SELECT * FROM acc_treasury WHERE ba_tx_id = ?', [(int)$tx_id]);
    $tx = ba_get_transaction($tx_id);
    return acc_tx(function () use ($old, $tx) {
        if ($old) {
            acc_void_source('treasury', $old['id'], 'تغییر در دستیار بانک');
            acc_q('DELETE FROM acc_treasury WHERE id = ?', [$old['id']]);
        }
        if (!$tx || $tx['status'] !== 'confirmed' || (int)$tx['amount'] <= 0) {
            return null;
        }
        $date = acc_jalali_parse($tx['bank_date'] ?? '') ? acc_jalali_fmt(...acc_jalali_parse($tx['bank_date'])) : acc_today();
        $desc = trim(($tx['description'] ?? '') . ($tx['party'] ? ' - ' . $tx['party'] : '')) . ' (دستیار بانک)';
        $acc = acc_bank_account($tx['wallet_id']);
        $in = $tx['direction'] === 'in';
        $kind = $tx['category_kind'] ?? 'pl';
        if ($kind === 'transfer' && $tx['counter_wallet_id']) {
            $other = acc_bank_account($tx['counter_wallet_id']);
            $b = ['kind' => 'transfer', 'account_id' => $in ? $other : $acc, 'to_account_id' => $in ? $acc : $other];
        } elseif ($kind === 'party') {
            $b = ['kind' => $in ? 'receive' : 'pay', 'account_id' => $acc, 'person_id' => acc_bank_person($tx['party'])];
            if (!$b['person_id']) {
                $b['counter_code'] = $in ? '4103' : '5102';
            }
        } else {
            $b = ['kind' => $in ? 'receive' : 'pay', 'account_id' => $acc, 'person_id' => acc_bank_person($tx['party']),
                'counter_account_id' => $in ? acc_account_id('4103') : acc_bank_expense_account($tx['category_name'] ?? '')];
        }
        $t = acc_treasury_record($b + ['amount' => (int)$tx['amount'], 'date' => $date, 'description' => $desc]);
        acc_q('UPDATE acc_treasury SET ba_tx_id = ? WHERE id = ?', [(int)$tx['id'], $t['id']]);
        return $t['number'];
    });
}

/** Imports every confirmed transaction that is not in the books yet. */
function acc_bank_sync_all()
{
    acc_db();
    $done = 0;
    $errors = [];
    foreach (ba_db()->query("SELECT id FROM transactions WHERE status = 'confirmed' ORDER BY occurred_at, id")->fetchAll() as $r) {
        if (acc_val('SELECT 1 FROM acc_treasury WHERE ba_tx_id = ?', [$r['id']])) {
            continue;
        }
        try {
            if (acc_bank_sync_tx($r['id'])) {
                $done++;
            }
        } catch (Throwable $e) {
            $errors[] = '#' . $r['id'] . ': ' . $e->getMessage();
        }
    }
    return ['imported' => $done, 'errors' => array_slice($errors, 0, 20)];
}

function r_bank_link()
{
    $s = acc_bank_settings();
    $wallets = [];
    foreach (ba_db()->query('SELECT * FROM wallets ORDER BY id')->fetchAll() as $w) {
        $aid = (int)($s['wallets'][(string)$w['id']] ?? 0);
        $wallets[] = ['id' => (int)$w['id'], 'name' => $w['name'], 'account_id' => $aid ?: null,
            'account_name' => $aid ? acc_val('SELECT name FROM acc_cash_accounts WHERE id = ?', [$aid]) : null];
    }
    return ['enabled' => (bool)$s['enabled'], 'wallets' => $wallets, 'last_error' => ba_kv_get('acc_bank_link_error'),
        'imported' => (int)acc_val('SELECT COUNT(*) FROM acc_treasury WHERE ba_tx_id IS NOT NULL'),
        'waiting' => (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'confirmed'")->fetchColumn()];
}

function r_bank_link_save($u)
{
    $b = acc_body();
    $s = acc_bank_settings();
    $s['enabled'] = !empty($b['enabled']);
    foreach ((array)($b['wallets'] ?? []) as $w) {
        $aid = (int)($w['account_id'] ?? 0);
        if ($aid && acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [$aid])) {
            $s['wallets'][(string)(int)$w['id']] = $aid;
        }
    }
    ba_kv_set('acc_bank_link', $s);
    ba_kv_set('acc_bank_link_error', null);
    acc_log($u['username'], 'bank_link', $s['enabled'] ? 'on' : 'off');
    return r_bank_link();
}

function r_bank_link_sync($u)
{
    if (!acc_bank_settings()['enabled']) {
        throw new AccError('اول اتصال به دستیار بانک را روشن کن');
    }
    $r = acc_bank_sync_all();
    acc_log($u['username'], 'bank_link_sync', (string)$r['imported']);
    return $r;
}

/* ------------------------------------------------------------------ */
/* the bank assistant's transactions inside the panel                   */
/* ------------------------------------------------------------------ */

function r_bank_transactions()
{
    $w = ['1 = 1'];
    $args = [];
    if (in_array($_GET['status'] ?? '', ['pending', 'confirmed', 'ignored'], true)) {
        $w[] = 't.status = ?';
        $args[] = $_GET['status'];
    }
    if (in_array($_GET['direction'] ?? '', ['in', 'out'], true)) {
        $w[] = 't.direction = ?';
        $args[] = $_GET['direction'];
    }
    $days = max(1, min(3650, (int)($_GET['days'] ?? 30)));
    $w[] = 't.occurred_at >= ?';
    $args[] = date('Y-m-d 00:00:00', strtotime("-$days days"));
    $q = ba_db()->prepare('SELECT t.*, c.name category_name, c.kind category_kind, w.name wallet_name, s.body sms_text FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN wallets w ON w.id = t.wallet_id LEFT JOIN sms_raw s ON s.id = t.sms_id
        WHERE ' . implode(' AND ', $w) . ' ORDER BY t.occurred_at DESC, t.id DESC LIMIT 1000');
    $q->execute($args);
    $rows = $q->fetchAll();
    $in = $out = 0;
    foreach ($rows as $r) {
        if ($r['status'] !== 'ignored') {
            $r['direction'] === 'in' ? $in += $r['amount'] : $out += $r['amount'];
        }
    }
    $pending = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    return ['rows' => $rows, 'total_in' => $in, 'total_out' => $out, 'pending' => $pending];
}

/** Every card (wallet) has its own account in the books, also before its first transaction. */
function acc_bank_ensure_accounts()
{
    if (!acc_bank_settings()['enabled']) {
        return;
    }
    foreach (ba_db()->query('SELECT id FROM wallets ORDER BY id')->fetchAll() as $w) {
        try {
            acc_bank_account((int)$w['id']);
        } catch (Throwable $e) {
            error_log('card account: ' . $e->getMessage());
        }
    }
}

function r_bank_meta()
{
    acc_bank_ensure_accounts();
    $db = ba_db();
    return ['categories' => $db->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll(),
        'wallets' => $db->query('SELECT * FROM wallets ORDER BY id')->fetchAll(),
        'parties' => array_column($db->query('SELECT name FROM parties ORDER BY uses DESC LIMIT 500')->fetchAll(), 'name')];
}

function r_bank_confirm($u, $id)
{
    $b = acc_body();
    if (!ba_get_transaction($id)) {
        throw new AccError('تراکنش یافت نشد', 404);
    }
    if (trim((string)($b['description'] ?? '')) === '') {
        throw new AccError('بابت چه بود؟ شرح را بنویس');
    }
    try {
        $tx = ba_confirm_tx($id, $b['description'], $b['party'] ?? '', $b['category_id'] ?? 0, $b['note'] ?? '', $b['counter_wallet_id'] ?? null);
    } catch (InvalidArgumentException $e) {
        throw new AccError($e->getMessage());
    }
    ba_kv_set('bot:draft:' . $id, null);
    acc_log($u['username'], 'bank_confirm', (string)$id);
    return ['ok' => true, 'item' => $tx];
}

function r_bank_status($u, $id, $status)
{
    if (!ba_get_transaction($id)) {
        throw new AccError('تراکنش یافت نشد', 404);
    }
    ba_db()->prepare('UPDATE transactions SET status = ? WHERE id = ?')->execute([$status, $id]);
    ba_acc_link_tx($id);
    acc_log($u['username'], 'bank_' . $status, (string)$id);
    return ['ok' => true];
}
