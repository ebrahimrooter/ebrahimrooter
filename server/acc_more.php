<?php
/**
 * Accounting module - the rest of the menu of a full accounting program:
 * phone book, brands, departments (بخش‌ها), guarantee
 * documents, cheque books, loans with installments, advances (پیش‌دریافت /
 * پیش‌پرداخت), expense/income types, production with formulas, barcode
 * labels, list import, management reports (profit by product with FIFO /
 * moving average / average to date / last purchase price, departments,
 * marketers, due invoices, order estimate, unused goods, TTMS file...),
 * data repair, database compaction and closing the fiscal year.
 */

require_once __DIR__ . '/acc_core.php';

function acc_more_schema()
{
    static $done = [];
    $pdo = acc_db();
    $key = spl_object_id($pdo);
    if (isset($done[$key])) {
        return;
    }
    $done[$key] = true;
    foreach ([
        "acc_phonebook (id INTEGER PRIMARY KEY, name TEXT NOT NULL, phones TEXT DEFAULT '', note TEXT DEFAULT '')",
        "acc_brands (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
        "acc_departments (id INTEGER PRIMARY KEY, name TEXT NOT NULL)",
        "acc_guarantees (id INTEGER PRIMARY KEY, direction TEXT DEFAULT 'received', person_id INTEGER, kind TEXT DEFAULT 'سفته',
            number TEXT DEFAULT '', amount REAL DEFAULT 0, date TEXT DEFAULT '', due_date TEXT DEFAULT '', status TEXT DEFAULT 'active',
            description TEXT DEFAULT '', created_at TEXT)",
        "acc_cheque_books (id INTEGER PRIMARY KEY, account_id INTEGER, bank_name TEXT DEFAULT '', serial_from TEXT, serial_to TEXT, created_at TEXT)",
        "acc_loans (id INTEGER PRIMARY KEY, direction TEXT DEFAULT 'received', person_id INTEGER, account_id INTEGER, amount REAL DEFAULT 0,
            interest_total REAL DEFAULT 0, installments INTEGER DEFAULT 1, start_date TEXT, interval_months INTEGER DEFAULT 1,
            description TEXT DEFAULT '', created_at TEXT)",
        "acc_loan_installments (id INTEGER PRIMARY KEY, loan_id INTEGER, no INTEGER, due_date TEXT, principal REAL DEFAULT 0,
            interest REAL DEFAULT 0, paid INTEGER DEFAULT 0, paid_at TEXT, account_id INTEGER)",
        "acc_bom (id INTEGER PRIMARY KEY, product_id INTEGER, name TEXT DEFAULT '', qty_out REAL DEFAULT 1, extra_cost REAL DEFAULT 0)",
        "acc_bom_items (id INTEGER PRIMARY KEY, bom_id INTEGER, product_id INTEGER, qty REAL DEFAULT 0)",
        "acc_productions (id INTEGER PRIMARY KEY, number TEXT, date TEXT, bom_id INTEGER, product_id INTEGER, runs REAL DEFAULT 1,
            qty REAL DEFAULT 0, warehouse_id INTEGER, cost REAL DEFAULT 0, status TEXT DEFAULT 'final', created_at TEXT)",
    ] as $t) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $t);
    }
}

/* ------------------------------------------------------------------ */
/* Jalali helpers                                                       */
/* ------------------------------------------------------------------ */

function acc_jalali_parse($d)
{
    if (!preg_match('~^(\d{4})/(\d{1,2})/(\d{1,2})$~', ba_normalize(trim((string)$d)), $m)) {
        return null;
    }
    return [(int)$m[1], (int)$m[2], (int)$m[3]];
}

function acc_jalali_fmt($y, $m, $d)
{
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

function acc_jalali_add_months($date, $months)
{
    [$y, $m, $d] = acc_jalali_parse($date) ?: ba_today_jalali();
    $m += $months;
    while ($m > 12) {
        $m -= 12;
        $y++;
    }
    $max = $m <= 6 ? 31 : ($m <= 11 ? 30 : 30);
    return acc_jalali_fmt($y, $m, min($d, $max));
}

/** Days between two Jalali dates (b - a). */
function acc_jalali_diff($a, $b)
{
    $pa = acc_jalali_parse($a);
    $pb = acc_jalali_parse($b);
    if (!$pa || !$pb) {
        return 0;
    }
    $ga = ba_j2g(...$pa);
    $gb = ba_j2g(...$pb);
    return (int)round((mktime(0, 0, 0, $gb[1], $gb[2], $gb[0]) - mktime(0, 0, 0, $ga[1], $ga[2], $ga[0])) / 86400);
}

function acc_jalali_days_ago($days)
{
    [$y, $m, $d] = ba_g2j(...array_map('intval', explode('-', date('Y-m-d', strtotime('-' . (int)$days . ' days')))));
    return acc_jalali_fmt($y, $m, $d);
}

/** from/to of the request (Jalali); defaults: whole history. */
function acc_range()
{
    $from = acc_jalali_parse($_GET['from'] ?? '') ? acc_jalali_fmt(...acc_jalali_parse($_GET['from'])) : '0000/00/00';
    $to = acc_jalali_parse($_GET['to'] ?? '') ? acc_jalali_fmt(...acc_jalali_parse($_GET['to'])) : '9999/99/99';
    return [$from, $to];
}

/* ------------------------------------------------------------------ */
/* simple lists: phone book, brands, departments                        */
/* ------------------------------------------------------------------ */

function acc_simple_save($table, array $fields, $id = null)
{
    acc_more_schema();
    if ($id) {
        if (!acc_val("SELECT 1 FROM $table WHERE id = ?", [$id])) {
            throw new AccError('یافت نشد', 404);
        }
        acc_update($table, $id, $fields);
        return ['ok' => true, 'id' => $id];
    }
    return ['ok' => true, 'id' => acc_insert($table, $fields)];
}

function r_phonebook()
{
    acc_more_schema();
    $q = trim((string)($_GET['q'] ?? ''));
    $rows = array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name'], 'phones' => $r['phones'], 'note' => $r['note'], 'source' => 'phonebook'],
        acc_all('SELECT * FROM acc_phonebook ORDER BY name'));
    foreach (acc_all("SELECT id, name, mobile, phone2, address FROM acc_persons WHERE mobile != '' OR phone2 != '' ORDER BY name") as $p) {
        $rows[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'phones' => trim($p['mobile'] . ' ' . $p['phone2']), 'note' => $p['address'], 'source' => 'person'];
    }
    if ($q !== '') {
        $rows = array_values(array_filter($rows, fn($r) => mb_strpos($r['name'] . ' ' . $r['phones'] . ' ' . $r['note'], $q) !== false));
    }
    return $rows;
}

function r_phonebook_save($u, $id = null)
{
    $b = acc_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام را بنویس');
    }
    return acc_simple_save('acc_phonebook', ['name' => $name, 'phones' => trim((string)($b['phones'] ?? '')), 'note' => trim((string)($b['note'] ?? ''))], $id);
}

function r_named_list($table)
{
    acc_more_schema();
    return array_map(fn($r) => ['id' => (int)$r['id'], 'name' => $r['name']], acc_all("SELECT * FROM $table ORDER BY name"));
}

function r_named_save($table, $id = null)
{
    $name = trim((string)(acc_body()['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام را بنویس');
    }
    return acc_simple_save($table, ['name' => $name], $id);
}

function r_named_delete($table, $id, $used_sql = null)
{
    acc_more_schema();
    if ($used_sql && acc_val($used_sql, [$id])) {
        throw new AccError('در حال استفاده است و حذف نمی‌شود');
    }
    acc_q("DELETE FROM $table WHERE id = ?", [$id]);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* guarantee documents (اسناد ضمانتی) - a register, no accounting effect */
/* ------------------------------------------------------------------ */

const ACC_GUARANTEE_STATUS = ['active' => 'جاری', 'returned' => 'عودت‌شده', 'claimed' => 'اجرا/وصول‌شده', 'cancelled' => 'باطل'];

function r_guarantees()
{
    acc_more_schema();
    return array_map(fn($g) => ['id' => (int)$g['id'], 'direction' => $g['direction'], 'direction_label' => $g['direction'] === 'given' ? 'داده‌شده' : 'دریافتی',
        'person_id' => (int)$g['person_id'], 'person_name' => $g['pn'], 'kind' => $g['kind'], 'number' => $g['number'], 'amount' => (float)$g['amount'],
        'date' => $g['date'], 'due_date' => $g['due_date'], 'status' => $g['status'], 'status_label' => ACC_GUARANTEE_STATUS[$g['status']] ?? $g['status'],
        'description' => $g['description']],
        acc_all('SELECT g.*, p.name pn FROM acc_guarantees g LEFT JOIN acc_persons p ON p.id = g.person_id ORDER BY g.id DESC'));
}

function r_guarantee_save($u, $id = null)
{
    $b = acc_body();
    if (!acc_val('SELECT 1 FROM acc_persons WHERE id = ?', [(int)($b['person_id'] ?? 0)])) {
        throw new AccError('طرف حساب را انتخاب کن');
    }
    $f = ['direction' => ($b['direction'] ?? '') === 'given' ? 'given' : 'received', 'person_id' => (int)$b['person_id'],
        'kind' => trim((string)($b['kind'] ?? 'سفته')) ?: 'سفته', 'number' => trim((string)($b['number'] ?? '')), 'amount' => acc_num($b['amount'] ?? 0),
        'date' => trim((string)($b['date'] ?? '')) ?: acc_today(), 'due_date' => trim((string)($b['due_date'] ?? '')),
        'status' => isset(ACC_GUARANTEE_STATUS[$b['status'] ?? '']) ? $b['status'] : 'active', 'description' => trim((string)($b['description'] ?? ''))];
    if (!$id) {
        $f['created_at'] = acc_now();
    }
    acc_log($u['username'], 'guarantee', $f['number']);
    return acc_simple_save('acc_guarantees', $f, $id);
}

/* ------------------------------------------------------------------ */
/* cheque books (دسته چک)                                               */
/* ------------------------------------------------------------------ */

function r_cheque_books()
{
    acc_more_schema();
    $out = [];
    foreach (acc_all('SELECT b.*, a.name an FROM acc_cheque_books b LEFT JOIN acc_cash_accounts a ON a.id = b.account_id ORDER BY b.id DESC') as $b) {
        $from = (int)$b['serial_from'];
        $to = (int)$b['serial_to'];
        $used = [];
        foreach (acc_all("SELECT number, status FROM acc_cheques WHERE direction = 'payable' AND account_id = ?", [$b['account_id']]) as $c) {
            $n = (int)preg_replace('/\D/', '', $c['number']);
            if ($n >= $from && $n <= $to) {
                $used[] = $n;
            }
        }
        $next = null;
        for ($n = $from; $n <= $to; $n++) {
            if (!in_array($n, $used, true)) {
                $next = $n;
                break;
            }
        }
        $out[] = ['id' => (int)$b['id'], 'account_id' => (int)$b['account_id'], 'account_name' => $b['an'], 'bank_name' => $b['bank_name'],
            'serial_from' => $b['serial_from'], 'serial_to' => $b['serial_to'], 'total' => $to - $from + 1, 'used' => count($used),
            'remaining' => $to - $from + 1 - count($used), 'next' => $next];
    }
    return $out;
}

function r_cheque_book_save()
{
    $b = acc_body();
    $from = (int)preg_replace('/\D/', '', ba_normalize((string)($b['serial_from'] ?? '')));
    $to = (int)preg_replace('/\D/', '', ba_normalize((string)($b['serial_to'] ?? '')));
    if (!acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [(int)($b['account_id'] ?? 0)])) {
        throw new AccError('حساب بانک را انتخاب کن');
    }
    if ($from <= 0 || $to < $from || $to - $from > 1000) {
        throw new AccError('شماره سریال اول و آخر دسته چک را درست وارد کن');
    }
    return acc_simple_save('acc_cheque_books', ['account_id' => (int)$b['account_id'], 'bank_name' => trim((string)($b['bank_name'] ?? '')),
        'serial_from' => (string)$from, 'serial_to' => (string)$to, 'created_at' => acc_now()]);
}

/* ------------------------------------------------------------------ */
/* loans (وام / قرض) with installments                                  */
/* ------------------------------------------------------------------ */

function r_loans()
{
    acc_more_schema();
    $out = [];
    foreach (acc_all('SELECT l.*, p.name pn, a.name an FROM acc_loans l LEFT JOIN acc_persons p ON p.id = l.person_id
        LEFT JOIN acc_cash_accounts a ON a.id = l.account_id ORDER BY l.id DESC') as $l) {
        $inst = acc_all('SELECT * FROM acc_loan_installments WHERE loan_id = ? ORDER BY no', [$l['id']]);
        $paid = array_filter($inst, fn($i) => $i['paid']);
        $out[] = ['id' => (int)$l['id'], 'direction' => $l['direction'], 'direction_label' => $l['direction'] === 'given' ? 'پرداختی (قرض داده)' : 'دریافتی',
            'person_name' => $l['pn'], 'account_name' => $l['an'], 'amount' => (float)$l['amount'], 'interest_total' => (float)$l['interest_total'],
            'installments' => (int)$l['installments'], 'paid_count' => count($paid), 'start_date' => $l['start_date'], 'description' => $l['description'],
            'remaining' => array_sum(array_map(fn($i) => $i['paid'] ? 0 : (float)$i['principal'] + (float)$i['interest'], $inst)),
            'schedule' => array_map(fn($i) => ['id' => (int)$i['id'], 'no' => (int)$i['no'], 'due_date' => $i['due_date'], 'principal' => (float)$i['principal'],
                'interest' => (float)$i['interest'], 'amount' => (float)$i['principal'] + (float)$i['interest'], 'paid' => (bool)$i['paid'], 'paid_at' => $i['paid_at']], $inst)];
    }
    return $out;
}

function r_loan_create($u)
{
    acc_require_open();
    acc_more_schema();
    $b = acc_body();
    $dir = ($b['direction'] ?? '') === 'given' ? 'given' : 'received';
    $amount = acc_num($b['amount'] ?? 0);
    $n = max(1, min(240, (int)($b['installments'] ?? 1)));
    $interest = max(0, acc_num($b['interest_total'] ?? 0));
    $acc = acc_row('SELECT * FROM acc_cash_accounts WHERE id = ?', [(int)($b['account_id'] ?? 0)]);
    $pid = !empty($b['person_id']) ? (int)$b['person_id'] : null;
    if ($amount <= 0 || !$acc) {
        throw new AccError('مبلغ وام و حساب بانک/صندوق لازم است');
    }
    if ($dir === 'given' && !$pid) {
        throw new AccError('قرض را به چه کسی دادی؟ طرف حساب را انتخاب کن');
    }
    $start = trim((string)($b['start_date'] ?? '')) ?: acc_jalali_add_months(acc_today(), 1);
    $every = max(1, (int)($b['interval_months'] ?? 1));
    $date = trim((string)($b['date'] ?? '')) ?: acc_today();
    $desc = trim((string)($b['description'] ?? ''));
    return acc_tx(function () use ($dir, $amount, $n, $interest, $acc, $pid, $start, $every, $date, $desc, $u) {
        $id = acc_insert('acc_loans', ['direction' => $dir, 'person_id' => $pid, 'account_id' => $acc['id'], 'amount' => $amount, 'interest_total' => $interest,
            'installments' => $n, 'start_date' => $start, 'interval_months' => $every, 'description' => $desc, 'created_at' => acc_now()]);
        // equal installments; rounding goes to the last one
        $p = floor($amount / $n);
        $i = floor($interest / $n);
        for ($k = 1; $k <= $n; $k++) {
            acc_insert('acc_loan_installments', ['loan_id' => $id, 'no' => $k, 'due_date' => acc_jalali_add_months($start, ($k - 1) * $every),
                'principal' => $k < $n ? $p : $amount - $p * ($n - 1), 'interest' => $k < $n ? $i : $interest - $i * ($n - 1)]);
        }
        $label = ($dir === 'given' ? 'قرض داده‌شده' : 'وام دریافتی') . ($desc !== '' ? ' - ' . $desc : '');
        $lines = $dir === 'received'
            ? [acc_cash_line($acc['id'], $amount, $label), acc_line('2104', 0, $amount, $label, ['person' => $pid])]
            : [acc_line('1106', $amount, 0, $label, ['person' => $pid]), acc_cash_line($acc['id'], -$amount, $label)];
        acc_post($date, $label, $lines, 'auto', 'loan', $id);
        acc_log($u['username'], 'loan', (string)$id);
        return ['ok' => true, 'id' => $id];
    });
}

function r_loan_pay($u, $inst_id)
{
    acc_require_open();
    acc_more_schema();
    $b = acc_body();
    $i = acc_row('SELECT i.*, l.direction, l.person_id, l.account_id loan_account FROM acc_loan_installments i JOIN acc_loans l ON l.id = i.loan_id WHERE i.id = ?', [$inst_id]);
    if (!$i) {
        throw new AccError('قسط یافت نشد', 404);
    }
    if ($i['paid']) {
        throw new AccError('این قسط قبلاً تسویه شده');
    }
    $acc = (int)($b['account_id'] ?? 0) ?: (int)$i['loan_account'];
    if (!acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [$acc])) {
        throw new AccError('حساب بانک/صندوق را انتخاب کن');
    }
    $date = trim((string)($b['date'] ?? '')) ?: acc_today();
    $p = (float)$i['principal'];
    $int = (float)$i['interest'];
    $label = 'قسط ' . $i['no'] . ' وام #' . $i['loan_id'];
    $lines = $i['direction'] === 'received'
        ? [acc_line('2104', $p, 0, $label, ['person' => $i['person_id']]), acc_line('5104', $int, 0, 'سود و کارمزد'), acc_cash_line($acc, -($p + $int), $label)]
        : [acc_cash_line($acc, $p + $int, $label), acc_line('1106', 0, $p, $label, ['person' => $i['person_id']]), acc_line('4105', 0, $int, 'سود وام')];
    acc_tx(function () use ($date, $label, $lines, $i, $acc) {
        acc_post($date, ($i['direction'] === 'received' ? 'پرداخت ' : 'دریافت ') . $label, $lines, 'auto', 'loan', (int)$i['loan_id']);
        acc_update('acc_loan_installments', $i['id'], ['paid' => 1, 'paid_at' => $date, 'account_id' => $acc]);
    });
    acc_log($u['username'], 'loan_installment', $label);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* advances: پیش‌دریافت (from customers) / پیش‌پرداخت (to suppliers)    */
/* ------------------------------------------------------------------ */

function r_advances()
{
    $rows = [];
    foreach (acc_all("SELECT l.person_id, p.name, a.code, SUM(l.debit - l.credit) b FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id
        LEFT JOIN acc_persons p ON p.id = l.person_id WHERE a.code IN ('2105', '1107') GROUP BY l.person_id, a.code HAVING ABS(b) > 0.01") as $r) {
        $rows[] = ['person_id' => (int)$r['person_id'], 'person_name' => $r['name'] ?? '-', 'kind' => $r['code'] === '2105' ? 'prereceive' : 'prepay',
            'kind_label' => $r['code'] === '2105' ? 'پیش‌دریافت' : 'پیش‌پرداخت', 'amount' => abs((float)$r['b'])];
    }
    return $rows;
}

function r_advance_create($u)
{
    $b = acc_body();
    $kind = ($b['kind'] ?? '') === 'prepay' ? 'prepay' : 'prereceive';
    if (empty($b['person_id'])) {
        throw new AccError('طرف حساب را انتخاب کن');
    }
    $t = acc_treasury_record(['kind' => $kind === 'prepay' ? 'pay' : 'receive', 'account_id' => $b['account_id'] ?? 0, 'person_id' => $b['person_id'],
        'amount' => $b['amount'] ?? 0, 'date' => $b['date'] ?? '', 'counter_code' => $kind === 'prepay' ? '1107' : '2105',
        'description' => trim(($kind === 'prepay' ? 'پیش‌پرداخت' : 'پیش‌دریافت') . ' ' . ($b['description'] ?? ''))]);
    acc_log($u['username'], $kind, $t['number']);
    return ['ok' => true, 'id' => $t['id'], 'number' => $t['number']];
}

/** Moves an advance onto the person's account (settles invoices with it). */
function r_advance_apply($u)
{
    acc_require_open();
    $b = acc_body();
    $kind = ($b['kind'] ?? '') === 'prepay' ? 'prepay' : 'prereceive';
    $pid = (int)($b['person_id'] ?? 0);
    $code = $kind === 'prepay' ? '1107' : '2105';
    $avail = abs((float)acc_val('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id
        WHERE a.code = ? AND l.person_id = ?', [$code, $pid]));
    $amount = acc_num($b['amount'] ?? 0) ?: $avail;
    if ($amount <= 0 || $amount > $avail + 0.01) {
        throw new AccError('مانده‌ی ' . ($kind === 'prepay' ? 'پیش‌پرداخت' : 'پیش‌دریافت') . ' این شخص ' . number_format($avail) . ' ریال است');
    }
    $lines = $kind === 'prereceive'
        ? [acc_line('2105', $amount, 0, 'تسویه با پیش‌دریافت', ['person' => $pid]), acc_person_line($pid, -$amount, 'از محل پیش‌دریافت')]
        : [acc_person_line($pid, $amount, 'از محل پیش‌پرداخت'), acc_line('1107', 0, $amount, 'تسویه با پیش‌پرداخت', ['person' => $pid])];
    acc_post(trim((string)($b['date'] ?? '')) ?: acc_today(), ($kind === 'prepay' ? 'تسویه پیش‌پرداخت ' : 'تسویه پیش‌دریافت ')
        . acc_val('SELECT name FROM acc_persons WHERE id = ?', [$pid]), $lines, 'auto', 'advance', $pid);
    acc_log($u['username'], 'advance_apply', (string)$pid);
    return ['ok' => true, 'amount' => $amount];
}

/* ------------------------------------------------------------------ */
/* expense / income types (حساب‌های معین زیر 51 و 41)                   */
/* ------------------------------------------------------------------ */

function r_expense_types()
{
    return array_map(fn($a) => ['id' => (int)$a['id'], 'code' => $a['code'], 'name' => $a['name'], 'kind' => $a['code'][0] === '4' ? 'income' : 'expense'],
        acc_all("SELECT * FROM acc_coa WHERE level = 'moein' AND (parent_code = '51' OR parent_code = '41')
            AND code NOT IN ('5101', '5103', '5105', '4101', '4102', '4104') ORDER BY code"));
}

function r_expense_type_create($u)
{
    $b = acc_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام نوع هزینه/درآمد را بنویس');
    }
    $parent = ($b['kind'] ?? '') === 'income' ? '41' : '51';
    $max = (int)acc_val('SELECT MAX(CAST(code AS INTEGER)) FROM acc_coa WHERE parent_code = ?', [$parent]);
    $code = (string)max($max + 1, (int)($parent . '10'));
    $id = acc_insert('acc_coa', ['code' => $code, 'name' => $name, 'level' => 'moein', 'nature' => $parent === '41' ? 'credit' : 'debit', 'parent_code' => $parent]);
    acc_log($u['username'], 'expense_type', $name);
    return ['ok' => true, 'id' => $id, 'code' => $code];
}

/* ------------------------------------------------------------------ */
/* production (تولید) with formulas                                    */
/* ------------------------------------------------------------------ */

function r_boms()
{
    acc_more_schema();
    $out = [];
    foreach (acc_all('SELECT b.*, p.name pn FROM acc_bom b LEFT JOIN acc_products p ON p.id = b.product_id ORDER BY b.id DESC') as $b) {
        $items = acc_all('SELECT i.*, p.name, p.unit, p.avg_cost, p.buy_price FROM acc_bom_items i LEFT JOIN acc_products p ON p.id = i.product_id WHERE bom_id = ?', [$b['id']]);
        $cost = array_sum(array_map(fn($i) => (float)$i['qty'] * (float)($i['avg_cost'] ?: $i['buy_price']), $items)) + (float)$b['extra_cost'];
        $out[] = ['id' => (int)$b['id'], 'product_id' => (int)$b['product_id'], 'product_name' => $b['pn'], 'name' => $b['name'] ?: $b['pn'],
            'qty_out' => (float)$b['qty_out'], 'extra_cost' => (float)$b['extra_cost'], 'unit_cost' => (float)$b['qty_out'] > 0 ? $cost / (float)$b['qty_out'] : 0,
            'items' => array_map(fn($i) => ['product_id' => (int)$i['product_id'], 'name' => $i['name'], 'unit' => $i['unit'], 'qty' => (float)$i['qty']], $items)];
    }
    return $out;
}

function r_bom_save($u, $id = null)
{
    acc_more_schema();
    $b = acc_body();
    $pid = (int)($b['product_id'] ?? 0);
    if (!acc_product($pid)) {
        throw new AccError('کالای ساخته‌شده را انتخاب کن');
    }
    $items = [];
    foreach ((array)($b['items'] ?? []) as $it) {
        $q = acc_num($it['qty'] ?? 0);
        $cp = (int)($it['product_id'] ?? 0);
        if ($cp && $q > 0) {
            if ($cp === $pid) {
                throw new AccError('کالا نمی‌تواند جزء فرمول خودش باشد');
            }
            if (!acc_product($cp)) {
                throw new AccError('ماده اولیه یافت نشد');
            }
            $items[] = [$cp, $q];
        }
    }
    if (!$items) {
        throw new AccError('حداقل یک ماده اولیه با مقدار لازم است');
    }
    return acc_tx(function () use ($id, $b, $pid, $items) {
        $f = ['product_id' => $pid, 'name' => trim((string)($b['name'] ?? '')), 'qty_out' => acc_num($b['qty_out'] ?? 1) ?: 1, 'extra_cost' => acc_num($b['extra_cost'] ?? 0)];
        $r = acc_simple_save('acc_bom', $f, $id);
        acc_q('DELETE FROM acc_bom_items WHERE bom_id = ?', [$r['id']]);
        foreach ($items as [$cp, $q]) {
            acc_insert('acc_bom_items', ['bom_id' => $r['id'], 'product_id' => $cp, 'qty' => $q]);
        }
        return $r;
    });
}

function r_productions()
{
    acc_more_schema();
    return array_map(fn($p) => ['id' => (int)$p['id'], 'number' => $p['number'], 'date' => $p['date'], 'product_name' => $p['pn'], 'qty' => (float)$p['qty'],
        'runs' => (float)$p['runs'], 'cost' => (float)$p['cost'], 'unit_cost' => (float)$p['qty'] > 0 ? (float)$p['cost'] / (float)$p['qty'] : 0, 'status' => $p['status']],
        acc_all('SELECT r.*, p.name pn FROM acc_productions r LEFT JOIN acc_products p ON p.id = r.product_id ORDER BY r.id DESC'));
}

function r_production_create($u)
{
    acc_require_open();
    acc_more_schema();
    $b = acc_body();
    $bom = acc_row('SELECT * FROM acc_bom WHERE id = ?', [(int)($b['bom_id'] ?? 0)]);
    if (!$bom) {
        throw new AccError('فرمول تولید را انتخاب کن');
    }
    $qty = acc_num($b['qty'] ?? 0);
    if ($qty <= 0) {
        throw new AccError('مقدار تولید باید بزرگتر از صفر باشد');
    }
    $wh = (int)($b['warehouse_id'] ?? 0) ?: acc_default_warehouse();
    $runs = $qty / (float)$bom['qty_out'];
    $date = trim((string)($b['date'] ?? '')) ?: acc_today();
    return acc_tx(function () use ($bom, $qty, $wh, $runs, $date, $u) {
        $number = sprintf('PR-%04d', acc_next('production'));
        $id = acc_insert('acc_productions', ['number' => $number, 'date' => $date, 'bom_id' => $bom['id'], 'product_id' => $bom['product_id'],
            'runs' => $runs, 'qty' => $qty, 'warehouse_id' => $wh, 'created_at' => acc_now()]);
        $doc = ['type' => 'production', 'id' => $id, 'number' => $number, 'date' => $date];
        $materials = 0;
        foreach (acc_all('SELECT * FROM acc_bom_items WHERE bom_id = ?', [$bom['id']]) as $it) {
            $p = acc_product($it['product_id']);
            $need = (float)$it['qty'] * $runs;
            $cost = acc_unit_cost($p);
            $materials += $need * $cost;
            acc_change_stock($wh, $p['id'], -$need, $doc + ['kind' => 'production_use', 'cost' => $cost]);
        }
        $extra = (float)$bom['extra_cost'] * $runs;
        $total = $materials + $extra;
        $unit = $total / $qty;
        // the made product's average cost takes in the new batch
        $prod = acc_product($bom['product_id']);
        $old = max((float)$prod['stock'], 0);
        $avg = ($old + $qty) > 0 ? ($old * acc_unit_cost($prod) + $total) / ($old + $qty) : $unit;
        acc_update('acc_products', $prod['id'], ['avg_cost' => $avg, 'last_cost' => $unit]);
        acc_change_stock($wh, $prod['id'], $qty, $doc + ['kind' => 'production', 'cost' => $unit]);
        acc_post($date, 'تولید ' . $number . ' - ' . $prod['name'], [acc_line('1103', $total, 0, 'محصول ساخته‌شده'),
            acc_line('1103', 0, $materials, 'مصرف مواد اولیه'), acc_line('5105', 0, $extra, 'سربار جذب‌شده')], 'auto', 'production', $id);
        acc_update('acc_productions', $id, ['cost' => $total]);
        acc_log($u['username'], 'production', $number);
        return ['ok' => true, 'id' => $id, 'number' => $number, 'unit_cost' => $unit];
    });
}

function r_production_delete($u, $id)
{
    acc_require_open();
    acc_more_schema();
    $p = acc_row('SELECT * FROM acc_productions WHERE id = ?', [$id]);
    if (!$p || $p['status'] === 'void') {
        throw new AccError('تولید یافت نشد', 404);
    }
    acc_tx(function () use ($p) {
        foreach (acc_all("SELECT * FROM acc_stock_moves WHERE doc_type = 'production' AND doc_id = ? AND kind NOT IN ('reversal', 'reversed')", [$p['id']]) as $m) {
            acc_change_stock($m['warehouse_id'], $m['product_id'], -(float)$m['qty'], ['type' => 'production', 'id' => (int)$p['id'], 'number' => $p['number'],
                'date' => $p['date'], 'kind' => 'reversal', 'cost' => $m['unit_cost']]);
            acc_q("UPDATE acc_stock_moves SET kind = 'reversed' WHERE id = ?", [$m['id']]);
        }
        acc_void_source('production', $p['id'], 'حذف تولید');
        acc_update('acc_productions', $p['id'], ['status' => 'void']);
    });
    acc_log($u['username'], 'production_delete', $p['number']);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* barcode labels (Code 128-B, SVG)                                     */
/* ------------------------------------------------------------------ */

function acc_code128_svg($text, $height = 40)
{
    static $pat = ['212222', '222122', '222221', '121223', '121322', '131222', '122213', '122312', '132212', '221213', '221312', '231212', '112232', '122132',
        '122231', '113222', '123122', '123221', '223211', '221132', '221231', '213212', '223112', '312131', '311222', '321122', '321221', '312212', '322112',
        '322211', '212123', '212321', '232121', '111323', '131123', '131321', '112313', '132113', '132311', '211313', '231113', '231311', '112133', '112331',
        '132131', '113123', '113321', '133121', '313121', '211331', '231131', '213113', '213311', '213131', '311123', '311321', '331121', '312113', '312311',
        '332111', '314111', '221411', '431111', '111224', '111422', '121124', '121421', '141122', '141221', '112214', '112412', '122114', '122411', '142112',
        '142211', '241211', '221114', '413111', '241112', '134111', '111242', '121142', '121241', '114212', '124112', '124211', '411212', '421112', '421211',
        '212141', '214121', '412121', '111143', '111341', '131141', '114113', '114311', '411113', '411311', '113141', '114131', '311141', '411131', '211412',
        '211214', '211232', '2331112'];
    $text = preg_replace('/[^\x20-\x7E]/', '', (string)$text);
    $codes = [104];                         // start B
    foreach (str_split($text) as $ch) {
        $codes[] = ord($ch) - 32;
    }
    $sum = 104;
    foreach (array_slice($codes, 1) as $i => $c) {
        $sum += $c * ($i + 1);
    }
    $codes[] = $sum % 103;
    $codes[] = 106;                         // stop
    $x = 10;
    $bars = '';
    foreach ($codes as $c) {
        foreach (str_split($pat[$c]) as $k => $w) {
            if ($k % 2 === 0) {
                $bars .= '<rect x="' . $x . '" y="0" width="' . $w . '" height="' . $height . '"/>';
            }
            $x += (int)$w;
        }
    }
    return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . ($x + 10) . ' ' . $height . '" height="' . $height . '" preserveAspectRatio="none">' . $bars . '</svg>';
}

function r_labels()
{
    $ids = array_filter(array_map('intval', explode(',', (string)($_GET['ids'] ?? ''))));
    $copies = max(1, min(100, (int)($_GET['copies'] ?? 1)));
    $rows = $ids ? acc_all('SELECT * FROM acc_products WHERE id IN (' . implode(',', $ids) . ')') : acc_all('SELECT * FROM acc_products');
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $cells = '';
    foreach ($rows as $p) {
        $code = $p['barcode'] !== '' ? $p['barcode'] : $p['code'];
        for ($i = 0; $i < $copies; $i++) {
            $cells .= '<div class="l"><div class="n">' . $e($p['name']) . '</div>' . acc_code128_svg($code) . '<div class="c">' . $e($code)
                . '</div><div class="p">' . number_format((float)$p['sale_price']) . ' ریال</div></div>';
        }
    }
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>برچسب بارکد</title><style>'
        . 'body{font-family:Vazirmatn,Tahoma,sans-serif;margin:8px}.g{display:grid;grid-template-columns:repeat(auto-fill,52mm);gap:2mm}'
        . '.l{width:50mm;height:28mm;border:1px dashed #bbb;padding:1.5mm;box-sizing:border-box;text-align:center;overflow:hidden}'
        . '.n{font-size:9pt;white-space:nowrap;overflow:hidden}.c{font:8pt monospace;direction:ltr}.p{font-size:8pt;font-weight:bold}svg{width:100%;height:11mm}'
        . '@media print{.l{border:0}button{display:none}}</style></head><body><button onclick="print()">چاپ</button><div class="g">' . $cells
        . '</div></body></html>';
    return null;
}

/* ------------------------------------------------------------------ */
/* import lists (ورود لیست) from CSV / Excel                             */
/* ------------------------------------------------------------------ */

/** CSV text -> rows keyed by normalised header. */
function acc_csv_rows($csv, array $aliases)
{
    if (!mb_check_encoding($csv, 'UTF-8')) {
        $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1256');
    }
    $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
    $lines = preg_split('/\r\n|\r|\n/', trim($csv));
    if (count($lines) < 2) {
        throw new AccError('فایل باید سطر عنوان و حداقل یک سطر داده داشته باشد');
    }
    $sep = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';' : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');
    $head = array_map(fn($h) => trim(ba_normalize($h)), str_getcsv($lines[0], $sep, '"', ''));
    $map = [];
    foreach ($head as $i => $h) {
        foreach ($aliases as $key => $names) {
            if (in_array(mb_strtolower($h), $names, true)) {
                $map[$i] = $key;
            }
        }
    }
    if (!in_array('name', $map, true)) {
        throw new AccError('ستون «نام» در سطر اول پیدا نشد');
    }
    $rows = [];
    foreach (array_slice($lines, 1) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, $sep, '"', '');
        $r = [];
        foreach ($map as $i => $k) {
            $r[$k] = trim((string)($cells[$i] ?? ''));
        }
        $rows[] = $r;
    }
    return $rows;
}

function acc_import_csv_text()
{
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        return file_get_contents($_FILES['file']['tmp_name']);
    }
    return (string)(acc_body()['csv'] ?? '');
}

function r_import_persons($u)
{
    $rows = acc_csv_rows(acc_import_csv_text(), [
        'name' => ['name', 'نام', 'نام شخص', 'طرف حساب'], 'mobile' => ['mobile', 'موبایل', 'تلفن همراه', 'همراه'],
        'type' => ['type', 'نوع'], 'national_id' => ['national_id', 'کد ملی', 'شناسه ملی'], 'address' => ['address', 'آدرس', 'نشانی'],
        'opening' => ['opening', 'مانده', 'مانده اول', 'balance'], 'groups' => ['groups', 'گروه', 'نقش'],
    ]);
    $types = array_flip(ACC_PERSON_TYPES);
    $done = $skip = 0;
    acc_tx(function () use ($rows, $types, &$done, &$skip) {
        foreach ($rows as $r) {
            if (($r['name'] ?? '') === '' || acc_val('SELECT 1 FROM acc_persons WHERE name = ?', [$r['name']])) {
                $skip++;
                continue;
            }
            $type = $types[$r['type'] ?? ''] ?? (isset(ACC_PERSON_TYPES[$r['type'] ?? '']) ? $r['type'] : 'customer');
            $f = acc_person_fields($r + ['type' => $type]);
            $f['type'] = $type;
            $f['code'] = (['supplier' => 'S', 'employee' => 'E', 'investor' => 'I', 'marketer' => 'M', 'other' => 'O'][$type] ?? 'C') . sprintf('%03d', acc_next('person'));
            $id = acc_insert('acc_persons', $f);
            $opening = acc_num($r['opening'] ?? 0);
            if ($opening) {
                acc_post(acc_today(), 'مانده اول ' . $f['name'], [acc_person_line($id, $opening, 'افتتاحیه'),
                    acc_line('3101', max(-$opening, 0), max($opening, 0), 'افتتاحیه')], 'opening', 'person', $id);
            }
            $done++;
        }
    });
    acc_log($u['username'], 'import_persons', "$done");
    return ['ok' => true, 'imported' => $done, 'skipped' => $skip];
}

function r_import_products($u)
{
    $rows = acc_csv_rows(acc_import_csv_text(), [
        'name' => ['name', 'نام', 'نام کالا', 'کالا'], 'code' => ['code', 'کد', 'کد کالا'], 'unit' => ['unit', 'واحد'],
        'buy_price' => ['buy_price', 'قیمت خرید', 'خرید'], 'sale_price' => ['sale_price', 'قیمت فروش', 'فروش'],
        'stock' => ['stock', 'موجودی', 'تعداد'], 'barcode' => ['barcode', 'بارکد'], 'group_name' => ['group', 'گروه', 'گروه کالا'],
    ]);
    $done = $skip = 0;
    acc_tx(function () use ($rows, &$done, &$skip) {
        foreach ($rows as $r) {
            if (($r['name'] ?? '') === '' || acc_val('SELECT 1 FROM acc_products WHERE name = ? OR (code != \'\' AND code = ?)', [$r['name'], $r['code'] ?? ''])) {
                $skip++;
                continue;
            }
            $f = acc_product_fields($r);
            if ($f['code'] === '') {
                $f['code'] = (string)(1000 + acc_next('product'));
            }
            $f['avg_cost'] = $f['last_cost'] = $f['buy_price'];
            $id = acc_insert('acc_products', $f);
            $qty = acc_num($r['stock'] ?? 0);
            if ($qty) {
                acc_stock_adjust($id, $qty, 'opening', 'موجودی اول ' . $f['name']);
            }
            $done++;
        }
    });
    acc_log($u['username'], 'import_products', "$done");
    return ['ok' => true, 'imported' => $done, 'skipped' => $skip];
}

/* ------------------------------------------------------------------ */
/* reports                                                              */
/* ------------------------------------------------------------------ */

/** Sale/purchase invoices of a range with totals, and the same per product. */
function r_report_trade()
{
    [$from, $to] = acc_range();
    $group = ($_GET['group'] ?? 'sale') === 'purchase' ? 'purchase' : 'sale';
    $inv = acc_all('SELECT i.*, p.name pn FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id WHERE i.kind IN (?, ?) AND i.date BETWEEN ? AND ? ORDER BY i.date, i.id',
        [$group, $group . '_return', $from, $to]);
    $sign = fn($k) => substr($k, -6) === 'return' ? -1 : 1;
    $products = [];
    foreach ($inv as $i) {
        $ratio = (float)$i['subtotal'] > 0 ? ((float)$i['subtotal'] - (float)$i['discount']) / (float)$i['subtotal'] : 0;
        foreach (acc_all('SELECT it.*, p.name FROM acc_invoice_items it LEFT JOIN acc_products p ON p.id = it.product_id WHERE invoice_id = ?', [$i['id']]) as $it) {
            $k = (int)$it['product_id'];
            $products[$k] = $products[$k] ?? ['product_id' => $k, 'name' => $it['name'], 'qty' => 0, 'amount' => 0, 'cost' => 0];
            $base = (float)$it['qty'] * (float)$it['unit_factor'];
            $products[$k]['qty'] += $sign($i['kind']) * $base;
            $products[$k]['amount'] += $sign($i['kind']) * (float)$it['qty'] * (float)$it['price'] * $ratio;
            $products[$k]['cost'] += $sign($i['kind']) * $base * (float)$it['cost'];
        }
    }
    foreach ($products as &$p) {
        $p['profit'] = $group === 'sale' ? $p['amount'] - $p['cost'] : null;
    }
    unset($p);
    $rows = array_map(fn($i) => ['id' => (int)$i['id'], 'number' => $i['number'], 'kind' => $i['kind'], 'date' => $i['date'], 'person_name' => $i['pn'],
        'net' => $sign($i['kind']) * ((float)$i['subtotal'] - (float)$i['discount']), 'tax' => $sign($i['kind']) * (float)$i['tax'],
        'total' => $sign($i['kind']) * (float)$i['total'], 'settled' => (bool)$i['settled']], $inv);
    return ['from' => $from, 'to' => $to, 'invoices' => $rows, 'products' => array_values($products),
        'totals' => ['net' => array_sum(array_column($rows, 'net')), 'tax' => array_sum(array_column($rows, 'tax')), 'total' => array_sum(array_column($rows, 'total')),
            'cost' => array_sum(array_column($products, 'cost')), 'count' => count($rows)]];
}

/**
 * Profit of every sale line under a costing method, from the stock moves:
 *   avg          moving weighted average (what the books use)
 *   fifo         first in, first out
 *   avg_to_date  average of all purchases up to the sale date
 *   last         last purchase price before the sale
 */
function r_report_profit()
{
    [$from, $to] = acc_range();
    $method = in_array($_GET['method'] ?? '', ['fifo', 'avg_to_date', 'last'], true) ? $_GET['method'] : 'avg';
    $per_product = [];
    $per_invoice = [];
    foreach (acc_all('SELECT id, name FROM acc_products') as $p) {
        $layers = [];      // fifo: [qty, cost]
        $qty_on = 0;
        $avg = 0;
        $buy_qty = 0;
        $buy_val = 0;
        $last = 0;
        foreach (acc_all("SELECT m.*, i.date idate FROM acc_stock_moves m LEFT JOIN acc_invoices i ON m.doc_type = 'invoice' AND i.id = m.doc_id
            WHERE m.product_id = ? AND m.kind NOT IN ('reversal', 'reversed') ORDER BY m.id", [$p['id']]) as $m) {
            $q = (float)$m['qty'];
            $c = (float)$m['unit_cost'];
            if ($q > 0) {
                if (in_array($m['kind'], ['purchase', 'opening', 'production'], true)) {
                    $buy_qty += $q;
                    $buy_val += $q * $c;
                    $last = $c;
                }
                $layers[] = [$q, $c];
                $avg = ($qty_on + $q) > 0 ? (max($qty_on, 0) * $avg + $q * $c) / (max($qty_on, 0) + $q) : $c;
                $qty_on += $q;
                continue;
            }
            $out = -$q;
            // cost of what left under each method
            $fifo_cost = 0;
            $left = $out;
            while ($left > 1e-9 && $layers) {
                $take = min($left, $layers[0][0]);
                $fifo_cost += $take * $layers[0][1];
                $layers[0][0] -= $take;
                $left -= $take;
                if ($layers[0][0] <= 1e-9) {
                    array_shift($layers);
                }
            }
            $fifo_cost += $left * ($last ?: $avg);
            $costs = ['avg' => $out * $avg, 'fifo' => $fifo_cost, 'avg_to_date' => $out * ($buy_qty > 0 ? $buy_val / $buy_qty : $avg), 'last' => $out * ($last ?: $avg)];
            $qty_on -= $out;
            if ($m['kind'] !== 'sale' || $m['doc_type'] !== 'invoice' || $m['idate'] < $from || $m['idate'] > $to) {
                continue;
            }
            $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$m['doc_id']]);
            $ratio = (float)$inv['subtotal'] > 0 ? ((float)$inv['subtotal'] - (float)$inv['discount']) / (float)$inv['subtotal'] : 0;
            $rev = 0;
            foreach (acc_all('SELECT * FROM acc_invoice_items WHERE invoice_id = ? AND product_id = ?', [$inv['id'], $p['id']]) as $it) {
                $rev += (float)$it['qty'] * (float)$it['price'] * $ratio;
            }
            $cost = $costs[$method];
            $pp = &$per_product[$p['id']];
            $pp = ($pp ?? ['product_id' => (int)$p['id'], 'name' => $p['name'], 'qty' => 0, 'revenue' => 0, 'cost' => 0]);
            $pp['qty'] += $out;
            $pp['revenue'] += $rev;
            $pp['cost'] += $cost;
            unset($pp);
            $pi = &$per_invoice[$inv['id']];
            $pi = ($pi ?? ['id' => (int)$inv['id'], 'number' => $inv['number'], 'date' => $inv['date'], 'revenue' => 0, 'cost' => 0]);
            $pi['revenue'] += $rev;
            $pi['cost'] += $cost;
            unset($pi);
        }
    }
    $fin = function ($rows) {
        return array_values(array_map(fn($r) => $r + ['profit' => $r['revenue'] - $r['cost'], 'margin' => $r['revenue'] > 0 ? round(($r['revenue'] - $r['cost']) * 100 / $r['revenue'], 1) : 0], $rows));
    };
    $prod = $fin($per_product);
    return ['method' => $method, 'from' => $from, 'to' => $to, 'products' => $prod, 'invoices' => $fin($per_invoice),
        'total' => ['revenue' => array_sum(array_column($prod, 'revenue')), 'cost' => array_sum(array_column($prod, 'cost')), 'profit' => array_sum(array_column($prod, 'profit'))]];
}

/** Profit by department (بخش) from the sale lines' booked cost. */
function r_report_departments()
{
    acc_more_schema();
    [$from, $to] = acc_range();
    $rows = [];
    foreach (acc_all("SELECT i.*, d.name dn FROM acc_invoices i LEFT JOIN acc_departments d ON d.id = i.department_id
        WHERE i.kind IN ('sale', 'sale_return') AND i.date BETWEEN ? AND ?", [$from, $to]) as $i) {
        $sign = $i['kind'] === 'sale' ? 1 : -1;
        $k = (int)$i['department_id'];
        $rows[$k] = $rows[$k] ?? ['department_id' => $k, 'name' => $i['dn'] ?? 'بدون بخش', 'count' => 0, 'revenue' => 0, 'cost' => 0];
        $rows[$k]['count']++;
        $rows[$k]['revenue'] += $sign * ((float)$i['subtotal'] - (float)$i['discount']);
        $rows[$k]['cost'] += $sign * (float)acc_val('SELECT COALESCE(SUM(qty * unit_factor * cost), 0) FROM acc_invoice_items WHERE invoice_id = ?', [$i['id']]);
    }
    return array_values(array_map(fn($r) => $r + ['profit' => $r['revenue'] - $r['cost']], $rows));
}

function r_report_marketers()
{
    [$from, $to] = acc_range();
    $out = [];
    foreach (acc_all("SELECT * FROM acc_persons WHERE type = 'marketer' ORDER BY name") as $m) {
        $sales = (float)acc_val("SELECT COALESCE(SUM(CASE kind WHEN 'sale' THEN subtotal - discount ELSE -(subtotal - discount) END), 0)
            FROM acc_invoices WHERE marketer_id = ? AND kind IN ('sale', 'sale_return') AND date BETWEEN ? AND ?", [$m['id'], $from, $to]);
        $count = (int)acc_val("SELECT COUNT(*) FROM acc_invoices WHERE marketer_id = ? AND kind = 'sale' AND date BETWEEN ? AND ?", [$m['id'], $from, $to]);
        $out[] = ['id' => (int)$m['id'], 'name' => $m['name'], 'rate' => (float)$m['commission_rate'], 'invoices' => $count, 'sales' => $sales,
            'commission' => round($sales * (float)$m['commission_rate'] / 100), 'balance' => (float)$m['balance']];
    }
    return $out;
}

/** Books a marketer's commission for a range (D 5106 | C marketer). */
function r_marketer_commission($u, $id)
{
    acc_require_open();
    $m = acc_row("SELECT * FROM acc_persons WHERE id = ? AND type = 'marketer'", [$id]);
    if (!$m) {
        throw new AccError('بازاریاب یافت نشد', 404);
    }
    $row = array_values(array_filter(r_report_marketers(), fn($r) => $r['id'] === (int)$id))[0] ?? null;
    if (!$row || $row['commission'] <= 0) {
        throw new AccError('در این بازه کمیسیونی نیست');
    }
    [$from, $to] = acc_range();
    acc_post(acc_today(), 'کمیسیون ' . $m['name'] . " ($from تا $to)", [acc_line('5106', $row['commission'], 0, 'کمیسیون فروش'),
        acc_person_line($id, -$row['commission'], 'کمیسیون')], 'auto', 'commission', (int)$id);
    acc_log($u['username'], 'commission', $m['name']);
    return ['ok' => true, 'commission' => $row['commission']];
}

/** Summary by group account (کل) and its sub-accounts (معین). */
function r_report_accounts()
{
    $rows = [];
    foreach (acc_all("SELECT a.*, COALESCE((SELECT SUM(debit) FROM acc_journal_lines WHERE account_id = a.id), 0) d,
        COALESCE((SELECT SUM(credit) FROM acc_journal_lines WHERE account_id = a.id), 0) c FROM acc_coa a ORDER BY code") as $a) {
        $rows[] = ['id' => (int)$a['id'], 'code' => $a['code'], 'name' => $a['name'], 'level' => $a['level'], 'parent_code' => $a['parent_code'],
            'debit' => (float)$a['d'], 'credit' => (float)$a['c'], 'balance' => (float)$a['d'] - (float)$a['c']];
    }
    foreach ($rows as &$k) {
        if ($k['level'] === 'kol') {
            foreach ($rows as $m) {
                if ($m['parent_code'] === $k['code']) {
                    $k['debit'] += $m['debit'];
                    $k['credit'] += $m['credit'];
                }
            }
            $k['balance'] = $k['debit'] - $k['credit'];
        }
    }
    unset($k);
    return array_values(array_filter($rows, fn($r) => $r['level'] === 'kol' || abs($r['debit']) + abs($r['credit']) > 0));
}

/** All movements of one cash/bank account (صورتحساب بانک / صندوق). */
function r_report_cash($u, $id)
{
    $a = acc_row('SELECT * FROM acc_cash_accounts WHERE id = ?', [$id]);
    if (!$a) {
        throw new AccError('حساب یافت نشد', 404);
    }
    [$from, $to] = acc_range();
    $bal = (float)acc_val('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM acc_journal_lines l JOIN acc_journals j ON j.id = l.journal_id
        WHERE l.cash_account_id = ? AND j.date < ?', [$id, $from]);
    $rows = [['date' => $from === '0000/00/00' ? '' : $from, 'number' => '', 'description' => 'مانده از قبل', 'debit' => 0, 'credit' => 0, 'balance' => $bal]];
    foreach (acc_all('SELECT j.number, j.date, j.description jd, l.debit, l.credit FROM acc_journal_lines l JOIN acc_journals j ON j.id = l.journal_id
        WHERE l.cash_account_id = ? AND j.date BETWEEN ? AND ? ORDER BY j.date, j.id', [$id, $from, $to]) as $r) {
        $bal += $r['debit'] - $r['credit'];
        $rows[] = ['date' => $r['date'], 'number' => $r['number'], 'description' => $r['jd'], 'debit' => (float)$r['debit'], 'credit' => (float)$r['credit'], 'balance' => $bal];
    }
    return ['account' => $a['name'], 'rows' => $rows, 'balance' => $bal];
}

/** Every money movement with its lines (عملیات‌ها / ریز عملیات). */
function r_report_operations()
{
    [$from, $to] = acc_range();
    $kind = (string)($_GET['source'] ?? '');
    $args = [$from, $to];
    $w = '';
    if ($kind !== '') {
        $w = ' AND j.source_type = ?';
        $args[] = $kind;
    }
    $out = [];
    foreach (acc_all("SELECT * FROM acc_journals j WHERE j.date BETWEEN ? AND ? AND j.kind != 'reversal'$w ORDER BY j.date, j.id", $args) as $j) {
        $out[] = ['id' => (int)$j['id'], 'number' => $j['number'], 'date' => $j['date'], 'description' => $j['description'], 'amount' => (float)$j['debit'],
            'source' => $j['source_type'] ?: 'manual', 'status' => $j['status'],
            'lines' => array_map(fn($l) => ['account' => $l['code'] . ' ' . $l['name'], 'person' => $l['pn'], 'cash' => $l['cn'], 'debit' => (float)$l['debit'], 'credit' => (float)$l['credit']],
                acc_all('SELECT l.*, a.code, a.name, p.name pn, c.name cn FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id
                    LEFT JOIN acc_persons p ON p.id = l.person_id LEFT JOIN acc_cash_accounts c ON c.id = l.cash_account_id WHERE journal_id = ?', [$j['id']]))];
    }
    return $out;
}

/** Open invoices with a due date (حساب سررسیدار). */
function r_report_due_invoices()
{
    $today = acc_today();
    $out = [];
    foreach (acc_all("SELECT i.*, p.name pn, p.mobile FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id
        WHERE i.kind IN ('sale', 'purchase') AND i.settled = 0 AND i.status = 'final' ORDER BY CASE WHEN i.due_date = '' THEN 1 ELSE 0 END, i.due_date") as $i) {
        $paid = (float)acc_val('SELECT COALESCE(SUM(amount), 0) FROM acc_treasury WHERE invoice_id = ? AND kind = ?', [$i['id'], $i['kind'] === 'sale' ? 'receive' : 'pay']);
        $due = $i['due_date'] ?: $i['date'];
        $out[] = ['id' => (int)$i['id'], 'number' => $i['number'], 'kind' => $i['kind'], 'person_name' => $i['pn'], 'mobile' => $i['mobile'], 'date' => $i['date'],
            'due_date' => $i['due_date'], 'total' => (float)$i['total'], 'paid' => $paid, 'remaining' => (float)$i['total'] - $paid,
            'days' => acc_jalali_diff($due, $today), 'overdue' => $due < $today];
    }
    return $out;
}

/** How much to order: sales of the last N days, cover for M days or up to the maximum. */
function r_report_order_estimate()
{
    $days = max(1, (int)($_GET['days'] ?? 30));
    $cover = max(1, (int)($_GET['cover'] ?? 30));
    $since = acc_jalali_days_ago($days);
    $out = [];
    foreach (acc_all("SELECT * FROM acc_products WHERE kind != 'service' ORDER BY name") as $p) {
        $sold = -(float)acc_val("SELECT COALESCE(SUM(qty), 0) FROM acc_stock_moves WHERE product_id = ? AND kind IN ('sale', 'production_use', 'issue') AND date >= ?", [$p['id'], $since]);
        $daily = $sold / $days;
        $need = max(0, $daily * $cover - (float)$p['stock']);
        if ((float)$p['max_stock'] > 0) {
            $need = max($need, (float)$p['stock'] <= (float)$p['reorder_point'] ? (float)$p['max_stock'] - (float)$p['stock'] : 0);
        }
        $out[] = ['id' => (int)$p['id'], 'name' => $p['name'], 'unit' => $p['unit'], 'stock' => (float)$p['stock'], 'sold' => $sold, 'daily' => round($daily, 2),
            'days_left' => $daily > 0 ? (int)floor((float)$p['stock'] / $daily) : null, 'suggest' => ceil($need), 'buy_price' => (float)$p['buy_price']];
    }
    usort($out, fn($a, $b) => $b['suggest'] <=> $a['suggest']);
    return ['days' => $days, 'cover' => $cover, 'rows' => $out];
}

function r_report_unused()
{
    $days = max(1, (int)($_GET['days'] ?? 90));
    $since = acc_jalali_days_ago($days);
    return array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'stock' => (float)$p['stock'], 'last_move' => $p['lm'],
        'value' => (float)$p['stock'] * (float)($p['avg_cost'] ?: $p['buy_price'])],
        acc_all("SELECT p.*, (SELECT MAX(date) FROM acc_stock_moves m WHERE m.product_id = p.id) lm FROM acc_products p
            WHERE NOT EXISTS (SELECT 1 FROM acc_stock_moves m WHERE m.product_id = p.id AND m.date >= ? AND m.kind IN ('sale', 'purchase', 'issue', 'production_use'))
            ORDER BY p.name", [$since]));
}

/** Seasonal purchases/sales file (معاملات فصلی / TTMS) as CSV. */
function r_report_ttms()
{
    $year = (int)($_GET['year'] ?? ba_today_jalali()[0]);
    $season = max(1, min(4, (int)($_GET['season'] ?? 1)));
    $kind = ($_GET['kind'] ?? 'sale') === 'purchase' ? 'purchase' : 'sale';
    $from = acc_jalali_fmt($year, ($season - 1) * 3 + 1, 1);
    $to = acc_jalali_fmt($year, $season * 3, 31);
    $c = acc_company();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="TTMS-' . $year . '-' . $season . '-' . $kind . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    fputcsv($fh, ['ردیف', 'نوع شخص', 'کد/شناسه ملی', 'کد اقتصادی', 'نام', 'کد پستی', 'نشانی', 'تاریخ', 'شماره فاکتور', 'مبلغ بدون مالیات', 'مالیات و عوارض', 'مبلغ کل', 'فروشنده/خریدار'], ',', '"', '');
    $n = 0;
    foreach (acc_all('SELECT i.*, p.name pn, p.national_id, p.legal_type, p.address FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id
        WHERE i.kind = ? AND i.date BETWEEN ? AND ? ORDER BY i.date, i.id', [$kind, $from, $to]) as $i) {
        fputcsv($fh, [++$n, $i['legal_type'] === 'legal' ? 'حقوقی' : 'حقیقی', $i['national_id'], '', $i['pn'], '', $i['address'], $i['date'], $i['number'],
            (float)$i['subtotal'] - (float)$i['discount'], (float)$i['tax'], (float)$i['total'], $c['name']], ',', '"', '');
    }
    fclose($fh);
    return null;
}

/* ------------------------------------------------------------------ */
/* maintenance                                                          */
/* ------------------------------------------------------------------ */

/** ترمیم اطلاعات: rebuilds every cached balance from the books. */
function r_repair($u)
{
    $fixed = [];
    acc_tx(function () use (&$fixed) {
        foreach (acc_all('SELECT p.id, p.name, p.balance, COALESCE((SELECT SUM(debit - credit) FROM acc_journal_lines WHERE person_id = p.id), 0) b FROM acc_persons p') as $p) {
            if (abs($p['balance'] - $p['b']) > 0.01) {
                acc_update('acc_persons', $p['id'], ['balance' => (float)$p['b']]);
                $fixed[] = 'مانده ' . $p['name'];
            }
        }
        foreach (acc_all('SELECT a.id, a.name, a.balance, COALESCE((SELECT SUM(debit - credit) FROM acc_journal_lines WHERE cash_account_id = a.id), 0) b FROM acc_cash_accounts a') as $a) {
            if (abs($a['balance'] - $a['b']) > 0.01) {
                acc_update('acc_cash_accounts', $a['id'], ['balance' => (float)$a['b']]);
                $fixed[] = 'مانده ' . $a['name'];
            }
        }
        foreach (acc_all('SELECT warehouse_id, product_id, SUM(qty) q FROM acc_stock_moves GROUP BY warehouse_id, product_id') as $m) {
            $cur = acc_val('SELECT qty FROM acc_stock WHERE warehouse_id = ? AND product_id = ?', [$m['warehouse_id'], $m['product_id']]);
            if ($cur === false || abs((float)$cur - (float)$m['q']) > 1e-6) {
                acc_q('INSERT INTO acc_stock (warehouse_id, product_id, qty) VALUES (?, ?, ?) ON CONFLICT(warehouse_id, product_id) DO UPDATE SET qty = excluded.qty',
                    [$m['warehouse_id'], $m['product_id'], (float)$m['q']]);
                $fixed[] = 'موجودی انبار کالا #' . $m['product_id'];
            }
        }
        foreach (acc_all('SELECT p.id, p.name, p.stock, COALESCE((SELECT SUM(qty) FROM acc_stock s WHERE s.product_id = p.id), 0) q FROM acc_products p') as $p) {
            if (abs($p['stock'] - $p['q']) > 1e-6) {
                acc_update('acc_products', $p['id'], ['stock' => (float)$p['q']]);
                $fixed[] = 'موجودی ' . $p['name'];
            }
        }
        foreach (acc_all('SELECT j.id, j.number, j.debit, COALESCE((SELECT SUM(debit) FROM acc_journal_lines WHERE journal_id = j.id), 0) d FROM acc_journals j') as $j) {
            if (abs($j['debit'] - $j['d']) > 0.01) {
                acc_update('acc_journals', $j['id'], ['debit' => (float)$j['d'], 'credit' => (float)$j['d']]);
                $fixed[] = 'جمع سند ' . $j['number'];
            }
        }
    });
    $unbalanced = acc_all('SELECT journal_id, SUM(debit) d, SUM(credit) c FROM acc_journal_lines GROUP BY journal_id HAVING ABS(d - c) > 0.01');
    acc_log($u['username'], 'repair', count($fixed) . ' fixed');
    return ['ok' => true, 'fixed' => $fixed, 'unbalanced_journals' => count($unbalanced)];
}

function r_vacuum($u)
{
    $file = BA_DB_PATH;
    $before = is_file($file) ? filesize($file) : 0;
    acc_db()->exec('VACUUM');
    clearstatcache();
    acc_log($u['username'], 'vacuum');
    return ['ok' => true, 'before' => $before, 'after' => is_file($file) ? filesize($file) : 0];
}

/** بستن سال مالی: closing entry for the year, then the next year opens. */
function r_close_year($u)
{
    $fy = acc_row('SELECT * FROM acc_fiscal ORDER BY id LIMIT 1');
    if (acc_locked()) {
        throw new AccError('دوره قفل است؛ اول قفل را باز کن');
    }
    $closed = acc_val("SELECT 1 FROM acc_journals WHERE kind = 'closing' AND status = 'final' AND description LIKE ?", ['%' . $fy['name'] . '%']);
    $profit = null;
    if (!$closed) {
        try {
            $profit = r_closing($u)['profit'];
        } catch (AccError $e) {
            if (strpos($e->getMessage(), 'برای بستن نیست') === false) {
                throw $e;
            }
        }
    }
    $next = (string)((int)$fy['name'] + 1);
    acc_q('UPDATE acc_fiscal SET name = ?, locked = 0 WHERE id = ?', [$next, $fy['id']]);
    acc_log($u['username'], 'close_year', $fy['name'] . ' -> ' . $next);
    return ['ok' => true, 'closed' => $fy['name'], 'year' => $next, 'profit' => $profit];
}

/** حذف دریافت/پرداخت: reverses its entry and removes the record. */
function r_treasury_delete($u, $id)
{
    acc_require_open();
    $t = acc_row('SELECT * FROM acc_treasury WHERE id = ?', [$id]);
    if (!$t) {
        throw new AccError('یافت نشد', 404);
    }
    acc_tx(function () use ($t) {
        acc_void_source('treasury', $t['id'], 'حذف ' . $t['number']);
        if ($t['invoice_id']) {
            acc_q('UPDATE acc_invoices SET settled = 0 WHERE id = ?', [$t['invoice_id']]);
        }
        acc_q('DELETE FROM acc_treasury WHERE id = ?', [$t['id']]);
    });
    acc_log($u['username'], 'treasury_delete', $t['number']);
    return ['ok' => true];
}

function acc_more_routes()
{
    return [
        ['DELETE', '#^/treasury/(\d+)$#', 'treasury', 'r_treasury_delete'],
        ['GET', '#^/phonebook$#', '', 'r_phonebook'],
        ['POST', '#^/phonebook$#', 'persons', 'r_phonebook_save'],
        ['PUT', '#^/phonebook/(\d+)$#', 'persons', 'r_phonebook_save'],
        ['DELETE', '#^/phonebook/(\d+)$#', 'persons', fn($u, $id) => r_named_delete('acc_phonebook', $id)],
        ['GET', '#^/brands$#', '', fn() => r_named_list('acc_brands')],
        ['POST', '#^/brands$#', 'products', fn() => r_named_save('acc_brands')],
        ['PUT', '#^/brands/(\d+)$#', 'products', fn($u, $id) => r_named_save('acc_brands', $id)],
        ['DELETE', '#^/brands/(\d+)$#', 'products', fn($u, $id) => r_named_delete('acc_brands', $id, 'SELECT 1 FROM acc_products WHERE brand_id = ?')],
        ['GET', '#^/departments$#', '', fn() => r_named_list('acc_departments')],
        ['POST', '#^/departments$#', 'admin', fn() => r_named_save('acc_departments')],
        ['PUT', '#^/departments/(\d+)$#', 'admin', fn($u, $id) => r_named_save('acc_departments', $id)],
        ['DELETE', '#^/departments/(\d+)$#', 'admin', fn($u, $id) => r_named_delete('acc_departments', $id, 'SELECT 1 FROM acc_invoices WHERE department_id = ?')],
        ['GET', '#^/guarantees$#', 'treasury', 'r_guarantees'],
        ['POST', '#^/guarantees$#', 'treasury', 'r_guarantee_save'],
        ['PUT', '#^/guarantees/(\d+)$#', 'treasury', 'r_guarantee_save'],
        ['GET', '#^/cheque-books$#', 'treasury', 'r_cheque_books'],
        ['POST', '#^/cheque-books$#', 'treasury', 'r_cheque_book_save'],
        ['DELETE', '#^/cheque-books/(\d+)$#', 'treasury', fn($u, $id) => r_named_delete('acc_cheque_books', $id)],
        ['GET', '#^/loans$#', 'treasury', 'r_loans'],
        ['POST', '#^/loans$#', 'treasury', 'r_loan_create'],
        ['POST', '#^/loans/installments/(\d+)/pay$#', 'treasury', 'r_loan_pay'],
        ['GET', '#^/advances$#', 'treasury', 'r_advances'],
        ['POST', '#^/advances$#', 'treasury', 'r_advance_create'],
        ['POST', '#^/advances/apply$#', 'treasury', 'r_advance_apply'],
        ['GET', '#^/expense-types$#', 'treasury', 'r_expense_types'],
        ['POST', '#^/expense-types$#', 'accounting', 'r_expense_type_create'],
        ['GET', '#^/boms$#', 'warehouse', 'r_boms'],
        ['POST', '#^/boms$#', 'warehouse', 'r_bom_save'],
        ['PUT', '#^/boms/(\d+)$#', 'warehouse', 'r_bom_save'],
        ['GET', '#^/productions$#', 'warehouse', 'r_productions'],
        ['POST', '#^/productions$#', 'warehouse', 'r_production_create'],
        ['DELETE', '#^/productions/(\d+)$#', 'warehouse', 'r_production_delete'],
        ['GET', '#^/labels$#', 'products', 'r_labels'],
        ['POST', '#^/import/persons$#', 'persons', 'r_import_persons'],
        ['POST', '#^/import/products$#', 'products', 'r_import_products'],
        ['GET', '#^/reports/trade$#', 'reports', 'r_report_trade'],
        ['GET', '#^/reports/profit$#', 'reports', 'r_report_profit'],
        ['GET', '#^/reports/departments$#', 'reports', 'r_report_departments'],
        ['GET', '#^/reports/marketers$#', 'reports', 'r_report_marketers'],
        ['POST', '#^/reports/marketers/(\d+)/commission$#', 'accounting', 'r_marketer_commission'],
        ['GET', '#^/reports/accounts$#', 'reports', 'r_report_accounts'],
        ['GET', '#^/reports/cash/(\d+)$#', 'reports', 'r_report_cash'],
        ['GET', '#^/reports/operations$#', 'reports', 'r_report_operations'],
        ['GET', '#^/reports/due-invoices$#', 'reports', 'r_report_due_invoices'],
        ['GET', '#^/reports/order-estimate$#', 'reports', 'r_report_order_estimate'],
        ['GET', '#^/reports/unused$#', 'reports', 'r_report_unused'],
        ['GET', '#^/reports/ttms$#', 'tax', 'r_report_ttms'],
        ['GET', '#^/bank-link$#', 'treasury', 'r_bank_link'],
        ['PUT', '#^/bank-link$#', 'admin', 'r_bank_link_save'],
        ['POST', '#^/bank-link/sync$#', 'admin', 'r_bank_link_sync'],
        ['POST', '#^/tools/repair$#', 'admin', 'r_repair'],
        ['POST', '#^/tools/vacuum$#', 'admin', 'r_vacuum'],
        ['POST', '#^/tools/close-year$#', 'admin', 'r_close_year'],
    ];
}
