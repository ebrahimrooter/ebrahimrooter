<?php
/**
 * Accounting module - core: tables, users, double-entry posting, stock.
 *
 * Every operation that moves money or goods is recorded as a balanced
 * journal entry with lines (acc_post). Person balances and cash/bank
 * balances are caches that only acc_post changes, so the books and the
 * balances always agree. Amounts are rial; dates are Jalali "1405/07/10".
 *
 * Tables live in the bank assistant's SQLite file with an acc_ prefix.
 */

require_once __DIR__ . '/lib.php';

/** HTTP error with a message for the user (FastAPI-style {"detail": ...}). */
class AccError extends RuntimeException
{
    public $status;

    public function __construct($message, $status = 400)
    {
        parent::__construct($message);
        $this->status = $status;
    }
}

function acc_db()
{
    static $ready = false;
    $pdo = ba_db();
    if (!$ready) {
        $ready = true;
        acc_schema($pdo);
    }
    return $pdo;
}

function acc_schema(PDO $pdo)
{
    $pdo->exec('PRAGMA busy_timeout = 5000');
    $tables = [
        "acc_users (id INTEGER PRIMARY KEY, username TEXT UNIQUE NOT NULL, full_name TEXT DEFAULT '',
            password_hash TEXT NOT NULL, role TEXT DEFAULT 'seller', is_active INTEGER DEFAULT 1,
            permissions TEXT DEFAULT '', created_at TEXT)",
        "acc_sessions (token_hash TEXT PRIMARY KEY, user_id INTEGER NOT NULL, expires_at INTEGER NOT NULL)",
        "acc_company (id INTEGER PRIMARY KEY, name TEXT DEFAULT 'شرکت من', national_id TEXT DEFAULT '',
            economic_code TEXT DEFAULT '', vat_rate REAL DEFAULT 10, webhook_enabled INTEGER DEFAULT 0,
            webhook_url TEXT DEFAULT '', webhook_secret TEXT DEFAULT '', api_key TEXT DEFAULT '',
            tax_memory TEXT DEFAULT '', tax_key_set INTEGER DEFAULT 0,
            invoice_prefix_sale TEXT DEFAULT 'SF', invoice_prefix_buy TEXT DEFAULT 'PF')",
        "acc_counters (k TEXT PRIMARY KEY, n INTEGER NOT NULL)",
        "acc_persons (id INTEGER PRIMARY KEY, code TEXT, name TEXT NOT NULL, type TEXT DEFAULT 'customer',
            mobile TEXT DEFAULT '', national_id TEXT DEFAULT '', balance REAL DEFAULT 0, credit_limit REAL DEFAULT 0,
            legal_type TEXT DEFAULT 'real', address TEXT DEFAULT '', branch_id INTEGER)",
        "acc_products (id INTEGER PRIMARY KEY, code TEXT, name TEXT NOT NULL, unit TEXT DEFAULT 'عدد',
            sale_price REAL DEFAULT 0, buy_price REAL DEFAULT 0, stock REAL DEFAULT 0, reorder_point REAL DEFAULT 0,
            max_stock REAL DEFAULT 0, barcode TEXT DEFAULT '', group_name TEXT DEFAULT '',
            track_serial INTEGER DEFAULT 0, track_lot INTEGER DEFAULT 0, unit2 TEXT DEFAULT '',
            unit2_factor REAL DEFAULT 1, avg_cost REAL DEFAULT 0, last_cost REAL DEFAULT 0)",
        "acc_invoices (id INTEGER PRIMARY KEY, number TEXT, kind TEXT, date TEXT, person_id INTEGER,
            subtotal REAL DEFAULT 0, discount REAL DEFAULT 0, tax REAL DEFAULT 0, total REAL DEFAULT 0,
            settled INTEGER DEFAULT 0, discount_percent REAL DEFAULT 0, atf TEXT DEFAULT '', status TEXT DEFAULT 'final',
            freight REAL DEFAULT 0, customs REAL DEFAULT 0, other_cost REAL DEFAULT 0, branch_id INTEGER,
            created_at TEXT)",
        "acc_invoice_items (id INTEGER PRIMARY KEY, invoice_id INTEGER, product_id INTEGER, qty REAL DEFAULT 1,
            price REAL DEFAULT 0, unit TEXT DEFAULT '', unit_factor REAL DEFAULT 1, cost REAL DEFAULT 0)",
        "acc_warehouses (id INTEGER PRIMARY KEY, name TEXT NOT NULL, is_default INTEGER DEFAULT 0)",
        "acc_stock (warehouse_id INTEGER NOT NULL, product_id INTEGER NOT NULL, qty REAL DEFAULT 0,
            PRIMARY KEY (warehouse_id, product_id))",
        // every change of quantity, for the kardex
        "acc_stock_moves (id INTEGER PRIMARY KEY, date TEXT, product_id INTEGER, warehouse_id INTEGER,
            qty REAL, unit_cost REAL DEFAULT 0, doc_type TEXT, doc_id INTEGER, doc_number TEXT, kind TEXT)",
        "acc_wh_docs (id INTEGER PRIMARY KEY, number TEXT, date TEXT, kind TEXT, warehouse_id INTEGER,
            to_warehouse_id INTEGER, description TEXT DEFAULT '', created_at TEXT)",
        "acc_wh_doc_items (id INTEGER PRIMARY KEY, doc_id INTEGER, product_id INTEGER, qty REAL DEFAULT 0)",
        "acc_serials (id INTEGER PRIMARY KEY, product_id INTEGER, warehouse_id INTEGER, serial TEXT,
            lot TEXT DEFAULT '', expiry TEXT DEFAULT '', status TEXT DEFAULT 'in')",
        "acc_stock_counts (id INTEGER PRIMARY KEY, number TEXT, date TEXT, warehouse_id INTEGER,
            status TEXT DEFAULT 'posted', note TEXT DEFAULT '', created_at TEXT)",
        "acc_stock_count_items (id INTEGER PRIMARY KEY, count_id INTEGER, product_id INTEGER,
            system_qty REAL DEFAULT 0, counted_qty REAL DEFAULT 0, diff REAL DEFAULT 0)",
        "acc_branches (id INTEGER PRIMARY KEY, name TEXT NOT NULL, city TEXT DEFAULT '')",
        "acc_currencies (id INTEGER PRIMARY KEY, code TEXT, name TEXT, rate REAL DEFAULT 1)",
        "acc_bank_statements (id INTEGER PRIMARY KEY, account_id INTEGER, date TEXT DEFAULT '',
            amount REAL DEFAULT 0, description TEXT DEFAULT '', matched INTEGER DEFAULT 0, txn_id INTEGER)",
        "acc_coa (id INTEGER PRIMARY KEY, code TEXT UNIQUE, name TEXT NOT NULL, level TEXT DEFAULT 'moein',
            nature TEXT DEFAULT 'debit', parent_code TEXT DEFAULT '', is_active INTEGER DEFAULT 1)",
        "acc_journals (id INTEGER PRIMARY KEY, number TEXT, date TEXT, description TEXT DEFAULT '',
            debit REAL DEFAULT 0, credit REAL DEFAULT 0, kind TEXT DEFAULT 'manual', status TEXT DEFAULT 'final',
            source_type TEXT, source_id INTEGER, created_at TEXT)",
        "acc_journal_lines (id INTEGER PRIMARY KEY, journal_id INTEGER, account_id INTEGER, description TEXT DEFAULT '',
            debit REAL DEFAULT 0, credit REAL DEFAULT 0, person_id INTEGER, cash_account_id INTEGER)",
        "acc_fiscal (id INTEGER PRIMARY KEY, name TEXT DEFAULT '1405', locked INTEGER DEFAULT 0)",
        "acc_tax_invoices (id INTEGER PRIMARY KEY, invoice_id INTEGER, taxid TEXT, kind TEXT DEFAULT 'sale',
            pattern TEXT DEFAULT '1', invoice_type TEXT DEFAULT '1', seller_id TEXT DEFAULT '', buyer_id TEXT DEFAULT '',
            buyer_name TEXT DEFAULT '', date TEXT DEFAULT '', pre_tax REAL DEFAULT 0, vat REAL DEFAULT 0,
            total REAL DEFAULT 0, status TEXT DEFAULT 'ready', payload TEXT DEFAULT '', response TEXT DEFAULT '',
            created_at TEXT)",
        "acc_logs (id INTEGER PRIMARY KEY, user TEXT DEFAULT '', action TEXT DEFAULT '', detail TEXT DEFAULT '', created_at TEXT)",
        "acc_cash_accounts (id INTEGER PRIMARY KEY, name TEXT NOT NULL, kind TEXT DEFAULT 'cash',
            account_no TEXT DEFAULT '', balance REAL DEFAULT 0)",
        "acc_treasury (id INTEGER PRIMARY KEY, number TEXT, date TEXT, kind TEXT, account_id INTEGER,
            to_account_id INTEGER, person_id INTEGER, invoice_id INTEGER, amount REAL DEFAULT 0,
            description TEXT DEFAULT '', created_at TEXT)",
        "acc_attachments (id INTEGER PRIMARY KEY, object_type TEXT DEFAULT 'invoice', object_id INTEGER DEFAULT 0,
            filename TEXT DEFAULT '', path TEXT DEFAULT '', created_at TEXT)",
        "acc_cheques (id INTEGER PRIMARY KEY, number TEXT, direction TEXT, person_id INTEGER, account_id INTEGER,
            amount REAL DEFAULT 0, due_date TEXT DEFAULT '', bank_name TEXT DEFAULT '', status TEXT DEFAULT 'in_hand',
            description TEXT DEFAULT '', spent_to INTEGER, created_at TEXT)",
    ];
    foreach ($tables as $t) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $t);
    }
    // columns added after the first version
    foreach (ACC_COLUMNS as [$table, $col, $def]) {
        if (!in_array($col, array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name'), true)) {
            $pdo->exec("ALTER TABLE $table ADD COLUMN $col $def");
        }
    }
    $pdo->exec('CREATE INDEX IF NOT EXISTS acc_lines_journal ON acc_journal_lines(journal_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS acc_lines_account ON acc_journal_lines(account_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS acc_journals_source ON acc_journals(source_type, source_id)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS acc_moves_product ON acc_stock_moves(product_id)');
    acc_seed($pdo);
}

const ACC_COLUMNS = [
    ['acc_persons', 'groups', "TEXT DEFAULT ''"],          // comma separated groups / roles (مشتری عمده، همکار، ...)
    ['acc_persons', 'commission_rate', 'REAL DEFAULT 0'],  // marketers: percent of their sales
    ['acc_persons', 'phone2', "TEXT DEFAULT ''"],
    ['acc_invoices', 'due_date', "TEXT DEFAULT ''"],
    ['acc_invoices', 'marketer_id', 'INTEGER'],
    ['acc_invoices', 'department_id', 'INTEGER'],
    ['acc_invoices', 'note', "TEXT DEFAULT ''"],
    ['acc_products', 'brand_id', 'INTEGER'],
    ['acc_products', 'tax_code', "TEXT DEFAULT ''"],       // شناسه کالا/خدمت سامانه مؤدیان
    ['acc_products', 'kind', "TEXT DEFAULT 'goods'"],      // goods | service
    ['acc_company', 'address', "TEXT DEFAULT ''"],
    ['acc_company', 'phone', "TEXT DEFAULT ''"],
    ['acc_company', 'invoice_footer', "TEXT DEFAULT ''"],
    ['acc_company', 'postal_code', "TEXT DEFAULT ''"],
    ['acc_treasury', 'counter_account_id', 'INTEGER'],
    ['acc_treasury', 'ba_tx_id', 'INTEGER'],                // transaction of the bank assistant it came from
];

/** Chart of accounts used by the automatic entries (code => [name, level, nature, parent]). */
const ACC_COA = [
    '11' => ['دارایی‌های جاری', 'kol', 'debit', ''],
    '1101' => ['صندوق و بانک', 'moein', 'debit', '11'],
    '1102' => ['حساب‌های دریافتنی', 'moein', 'debit', '11'],
    '1103' => ['موجودی کالا', 'moein', 'debit', '11'],
    '1104' => ['اسناد دریافتنی (چک)', 'moein', 'debit', '11'],
    '1105' => ['مالیات بر ارزش افزوده خرید', 'moein', 'debit', '11'],
    '12' => ['دارایی‌های غیرجاری', 'kol', 'debit', ''],
    '21' => ['بدهی‌های جاری', 'kol', 'credit', ''],
    '2101' => ['حساب‌های پرداختنی', 'moein', 'credit', '21'],
    '2102' => ['اسناد پرداختنی (چک)', 'moein', 'credit', '21'],
    '2103' => ['مالیات بر ارزش افزوده فروش', 'moein', 'credit', '21'],
    '31' => ['حقوق صاحبان سهام', 'kol', 'credit', ''],
    '3101' => ['سرمایه', 'moein', 'credit', '31'],
    '3102' => ['سود و زیان انباشته', 'moein', 'credit', '31'],
    '41' => ['درآمدها', 'kol', 'credit', ''],
    '4101' => ['فروش', 'moein', 'credit', '41'],
    '4102' => ['برگشت از فروش', 'moein', 'debit', '41'],
    '4103' => ['سایر درآمدها', 'moein', 'credit', '41'],
    '4104' => ['اضافات انبار', 'moein', 'credit', '41'],
    '51' => ['بهای تمام‌شده و هزینه‌ها', 'kol', 'debit', ''],
    '5101' => ['بهای تمام‌شده کالای فروش‌رفته', 'moein', 'debit', '51'],
    '5102' => ['هزینه‌های عمومی', 'moein', 'debit', '51'],
    '5103' => ['کسورات و مصرف انبار', 'moein', 'debit', '51'],
    '1106' => ['وام‌های پرداختی به دیگران', 'moein', 'debit', '11'],
    '1107' => ['پیش‌پرداخت‌ها', 'moein', 'debit', '11'],
    '2104' => ['وام‌های دریافتی', 'moein', 'credit', '21'],
    '2105' => ['پیش‌دریافت‌ها', 'moein', 'credit', '21'],
    '4105' => ['درآمد سود وام', 'moein', 'credit', '41'],
    '5104' => ['هزینه سود و کارمزد وام', 'moein', 'debit', '51'],
    '5105' => ['سربار جذب‌شده تولید', 'moein', 'credit', '51'],
    '5106' => ['کمیسیون بازاریابی', 'moein', 'debit', '51'],
];

function acc_seed(PDO $pdo)
{
    $ins = $pdo->prepare('INSERT OR IGNORE INTO acc_coa (code, name, level, nature, parent_code) VALUES (?, ?, ?, ?, ?)');
    foreach (ACC_COA as $code => $a) {
        $ins->execute([$code, $a[0], $a[1], $a[2], $a[3]]);
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_company')->fetchColumn()) {
        $pdo->prepare("INSERT INTO acc_company (id, name, vat_rate, api_key) VALUES (1, 'شرکت من', 10, ?)")
            ->execute(['acc_' . bin2hex(random_bytes(8))]);
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_fiscal')->fetchColumn()) {
        $pdo->prepare('INSERT INTO acc_fiscal (name, locked) VALUES (?, 0)')->execute([(string)ba_today_jalali()[0]]);
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_warehouses')->fetchColumn()) {
        $pdo->exec("INSERT INTO acc_warehouses (name, is_default) VALUES ('انبار مرکزی', 1)");
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_cash_accounts')->fetchColumn()) {
        $pdo->exec("INSERT INTO acc_cash_accounts (name, kind) VALUES ('صندوق اصلی', 'cash'), ('بانک ملت', 'bank')");
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_currencies')->fetchColumn()) {
        $pdo->exec("INSERT INTO acc_currencies (code, name, rate) VALUES ('IRR', 'ریال', 1)");
    }
    if (!$pdo->query('SELECT COUNT(*) FROM acc_users')->fetchColumn()) {
        // First login: user "admin" with the bank assistant's app password.
        $pw = (string)(ba_config()['app_token'] ?? '');
        if ($pw === '' || strpos($pw, 'CHANGE-ME') === 0) {
            $pw = bin2hex(random_bytes(6));
            ba_kv_set('acc_initial_password', $pw);
        }
        $pdo->prepare("INSERT INTO acc_users (username, full_name, password_hash, role, created_at) VALUES ('admin', 'مدیر سیستم', ?, 'admin', ?)")
            ->execute([password_hash($pw, PASSWORD_DEFAULT), date('c')]);
    }
}

/* ------------------------------------------------------------------ */
/* small helpers                                                        */
/* ------------------------------------------------------------------ */

function acc_q($sql, array $args = [])
{
    $st = acc_db()->prepare($sql);
    $st->execute($args);
    return $st;
}

function acc_row($sql, array $args = [])
{
    return acc_q($sql, $args)->fetch() ?: null;
}

function acc_all($sql, array $args = [])
{
    return acc_q($sql, $args)->fetchAll();
}

function acc_val($sql, array $args = [])
{
    return acc_q($sql, $args)->fetchColumn();
}

function acc_insert($table, array $data)
{
    $cols = array_keys($data);
    acc_q('INSERT INTO ' . $table . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', array_fill(0, count($cols), '?')) . ')',
        array_values($data));
    return (int)acc_db()->lastInsertId();
}

function acc_update($table, $id, array $data)
{
    if (!$data) {
        return;
    }
    $set = implode(', ', array_map(fn($c) => "$c = ?", array_keys($data)));
    acc_q("UPDATE $table SET $set WHERE id = ?", array_merge(array_values($data), [$id]));
}

/** Next number of a series (survives deletes, unlike COUNT+1). */
function acc_next($key)
{
    acc_q('INSERT INTO acc_counters (k, n) VALUES (?, 1) ON CONFLICT(k) DO UPDATE SET n = n + 1', [$key]);
    return (int)acc_val('SELECT n FROM acc_counters WHERE k = ?', [$key]);
}

function acc_now()
{
    return date('Y-m-d\TH:i:s');
}

function acc_today()
{
    [$y, $m, $d] = ba_today_jalali();
    return sprintf('%04d/%02d/%02d', $y, $m, $d);
}

function acc_company()
{
    return acc_row('SELECT * FROM acc_company WHERE id = 1');
}

function acc_locked()
{
    return (bool)acc_val('SELECT locked FROM acc_fiscal ORDER BY id LIMIT 1');
}

function acc_require_open()
{
    if (acc_locked()) {
        throw new AccError('دوره مالی قفل است. امکان ثبت سند جدید وجود ندارد.');
    }
}

function acc_log($user, $action, $detail = '')
{
    acc_insert('acc_logs', ['user' => (string)$user, 'action' => $action, 'detail' => (string)$detail, 'created_at' => acc_now()]);
}

/** Within one database transaction (nested calls join the outer one). */
function acc_tx(callable $fn)
{
    $pdo = acc_db();
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $r = $fn();
        $pdo->commit();
        return $r;
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/* ------------------------------------------------------------------ */
/* users and sessions                                                   */
/* ------------------------------------------------------------------ */

const ACC_ROLE_PERMS = [
    'admin' => ['*'],
    'accountant' => ['dashboard', 'persons', 'products', 'sales', 'purchases', 'treasury', 'accounting', 'reports', 'tax', 'warehouse', 'attachments', 'export', 'sms'],
    'seller' => ['dashboard', 'persons', 'products', 'sales', 'reports', 'attachments'],
    'warehouse' => ['dashboard', 'products', 'warehouse', 'attachments'],
];

function acc_user_perms(array $u)
{
    $custom = json_decode((string)($u['permissions'] ?? ''), true);
    if (is_array($custom) && $custom) {
        return $custom;
    }
    return ACC_ROLE_PERMS[$u['role']] ?? ['dashboard'];
}

function acc_user_out(array $u)
{
    return ['username' => $u['username'], 'full_name' => $u['full_name'], 'role' => $u['role'], 'permissions' => acc_user_perms($u)];
}

function acc_login($username, $password)
{
    $u = acc_row('SELECT * FROM acc_users WHERE username = ?', [(string)$username]);
    if (!$u || !$u['is_active'] || !password_verify((string)$password, $u['password_hash'])) {
        // slow down guessing a little
        usleep(300000);
        throw new AccError('نام کاربری یا رمز اشتباه است', 401);
    }
    $token = bin2hex(random_bytes(24));
    acc_q('DELETE FROM acc_sessions WHERE expires_at < ?', [time()]);
    acc_insert('acc_sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u['id'], 'expires_at' => time() + 12 * 3600]);
    acc_log($u['username'], 'login');
    return ['token' => $token, 'access_token' => $token, 'user' => acc_user_out($u)];
}

function acc_session_user($token)
{
    if (!is_string($token) || $token === '') {
        return null;
    }
    $u = acc_row('SELECT u.* FROM acc_sessions s JOIN acc_users u ON u.id = s.user_id
        WHERE s.token_hash = ? AND s.expires_at > ? AND u.is_active = 1', [hash('sha256', $token), time()]);
    return $u ?: null;
}

function acc_can(array $user, $perm)
{
    $p = acc_user_perms($user);
    return in_array('*', $p, true) || in_array($perm, $p, true);
}

/* ------------------------------------------------------------------ */
/* double-entry posting                                                 */
/* ------------------------------------------------------------------ */

function acc_account_id($code)
{
    static $cache = [];
    if (!isset($cache[$code])) {
        $id = acc_val('SELECT id FROM acc_coa WHERE code = ?', [(string)$code]);
        if (!$id) {
            $a = ACC_COA[$code] ?? null;
            if (!$a) {
                throw new AccError("حساب $code در کدینگ نیست");
            }
            $id = acc_insert('acc_coa', ['code' => $code, 'name' => $a[0], 'level' => $a[1], 'nature' => $a[2], 'parent_code' => $a[3]]);
        }
        $cache[$code] = (int)$id;
    }
    return $cache[$code];
}

/** Control account of a person: suppliers 2101, everyone else 1102. */
function acc_person_code($person_id)
{
    $type = acc_val('SELECT type FROM acc_persons WHERE id = ?', [(int)$person_id]);
    return $type === 'supplier' ? '2101' : '1102';
}

/** A line: [code, debit, credit, description, ['person' => id, 'cash' => id]] */
function acc_line($code, $debit, $credit, $desc = '', array $sub = [])
{
    return ['code' => $code, 'debit' => (float)$debit, 'credit' => (float)$credit, 'desc' => $desc,
        'person' => $sub['person'] ?? null, 'cash' => $sub['cash'] ?? null, 'account_id' => $sub['account_id'] ?? null];
}

/** Line on the person's control account; +amount = they owe more (debit). */
function acc_person_line($person_id, $amount, $desc = '')
{
    $amount = (float)$amount;
    return acc_line(acc_person_code($person_id), max($amount, 0), max(-$amount, 0), $desc, ['person' => (int)$person_id]);
}

/** Line on a cash/bank account (1101); +amount = money in. */
function acc_cash_line($cash_id, $amount, $desc = '')
{
    $amount = (float)$amount;
    return acc_line('1101', max($amount, 0), max(-$amount, 0), $desc, ['cash' => (int)$cash_id]);
}

/**
 * Records a balanced journal entry and updates the person / cash caches.
 * Zero lines are dropped; an entry with nothing left is not recorded.
 * Returns the journal id (or null).
 */
function acc_post($date, $description, array $lines, $kind = 'auto', $source_type = null, $source_id = null)
{
    $lines = array_values(array_filter($lines, fn($l) => round($l['debit'], 2) != 0 || round($l['credit'], 2) != 0));
    if (!$lines) {
        return null;
    }
    $debit = round(array_sum(array_column($lines, 'debit')), 2);
    $credit = round(array_sum(array_column($lines, 'credit')), 2);
    if (abs($debit - $credit) > 0.01) {
        throw new AccError("سند تراز نیست (بدهکار $debit، بستانکار $credit)");
    }
    return acc_tx(function () use ($date, $description, $lines, $kind, $source_type, $source_id, $debit) {
        $jid = acc_insert('acc_journals', [
            'number' => (string)acc_next('journal'), 'date' => $date ?: acc_today(), 'description' => $description,
            'debit' => $debit, 'credit' => $debit, 'kind' => $kind, 'status' => 'final',
            'source_type' => $source_type, 'source_id' => $source_id, 'created_at' => acc_now(),
        ]);
        foreach ($lines as $l) {
            $acc = $l['account_id'] ?: acc_account_id($l['code']);
            acc_insert('acc_journal_lines', ['journal_id' => $jid, 'account_id' => $acc, 'description' => $l['desc'],
                'debit' => $l['debit'], 'credit' => $l['credit'], 'person_id' => $l['person'], 'cash_account_id' => $l['cash']]);
            acc_apply_line_caches($l['person'], $l['cash'], $l['debit'] - $l['credit']);
        }
        return $jid;
    });
}

function acc_apply_line_caches($person_id, $cash_id, $delta)
{
    if ($person_id) {
        acc_q('UPDATE acc_persons SET balance = balance + ? WHERE id = ?', [$delta, $person_id]);
    }
    if ($cash_id) {
        acc_q('UPDATE acc_cash_accounts SET balance = balance + ? WHERE id = ?', [$delta, $cash_id]);
    }
}

/** Voids a journal with a reversing entry (same lines, sides swapped). */
function acc_void_journal($jid, $why = '')
{
    return acc_tx(function () use ($jid, $why) {
        $j = acc_row('SELECT * FROM acc_journals WHERE id = ?', [(int)$jid]);
        if (!$j) {
            throw new AccError('سند یافت نشد', 404);
        }
        if ($j['status'] === 'void') {
            throw new AccError('این سند قبلاً ابطال شده');
        }
        acc_q("UPDATE acc_journals SET status = 'void' WHERE id = ?", [$j['id']]);
        $lines = [];
        foreach (acc_all('SELECT * FROM acc_journal_lines WHERE journal_id = ?', [$j['id']]) as $ln) {
            $lines[] = ['code' => null, 'account_id' => (int)$ln['account_id'], 'debit' => (float)$ln['credit'],
                'credit' => (float)$ln['debit'], 'desc' => 'برگشت', 'person' => $ln['person_id'], 'cash' => $ln['cash_account_id']];
        }
        $rev = acc_post($j['date'], 'برگشت سند ' . $j['number'] . ($why ? " ($why)" : ''), $lines, 'reversal', $j['source_type'], $j['source_id']);
        if ($rev) {
            acc_q("UPDATE acc_journals SET status = 'void' WHERE id = ?", [$rev]);   // the pair cancels out
        }
        return $rev;
    });
}

/** Voids every live entry of a source document (invoice edit/delete). */
function acc_void_source($type, $id, $why = '')
{
    foreach (acc_all("SELECT id FROM acc_journals WHERE source_type = ? AND source_id = ? AND status != 'void' AND kind != 'reversal'", [$type, (int)$id]) as $j) {
        acc_void_journal($j['id'], $why);
    }
}

/* ------------------------------------------------------------------ */
/* stock                                                                */
/* ------------------------------------------------------------------ */

function acc_default_warehouse()
{
    return (int)(acc_val('SELECT id FROM acc_warehouses ORDER BY is_default DESC, id LIMIT 1') ?: 0);
}

/** Moves quantity in a warehouse and records the move for the kardex. */
function acc_change_stock($wh, $product_id, $qty, array $doc = [])
{
    $wh = (int)($wh ?: acc_default_warehouse());
    acc_q('INSERT INTO acc_stock (warehouse_id, product_id, qty) VALUES (?, ?, ?)
        ON CONFLICT(warehouse_id, product_id) DO UPDATE SET qty = qty + excluded.qty', [$wh, (int)$product_id, (float)$qty]);
    acc_q('UPDATE acc_products SET stock = stock + ? WHERE id = ?', [(float)$qty, (int)$product_id]);
    acc_insert('acc_stock_moves', ['date' => $doc['date'] ?? acc_today(), 'product_id' => (int)$product_id, 'warehouse_id' => $wh,
        'qty' => (float)$qty, 'unit_cost' => (float)($doc['cost'] ?? 0), 'doc_type' => $doc['type'] ?? '',
        'doc_id' => $doc['id'] ?? null, 'doc_number' => $doc['number'] ?? '', 'kind' => $doc['kind'] ?? '']);
}

function acc_product($id)
{
    return acc_row('SELECT * FROM acc_products WHERE id = ?', [(int)$id]);
}

/** Cost per base unit used for issues: weighted average, else purchase price. */
function acc_unit_cost(array $p)
{
    return (float)($p['avg_cost'] ?: $p['buy_price']);
}

/* ------------------------------------------------------------------ */
/* webhook (n8n etc.)                                                   */
/* ------------------------------------------------------------------ */

function acc_webhook($action, $object_type, array $ids, $data = null)
{
    $c = acc_company();
    if (!$c || !$c['webhook_enabled'] || !$c['webhook_url'] || !function_exists('curl_init')) {
        return;
    }
    $payload = ['Password' => $c['webhook_secret'], 'Action' => $action, 'ObjectType' => $object_type,
        'ObjectIdList' => $ids, 'Data' => $data, 'Timestamp' => gmdate('c'), 'Source' => 'Accounting-API'];
    $ch = ba_curl($c['webhook_url'], [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2]);
    curl_exec($ch);
    curl_close($ch);
}
