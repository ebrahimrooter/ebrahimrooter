<?php
/**
 * Accounting module - HTTP API (same routes and JSON as the original
 * FastAPI app, so its interface in acc/ works unchanged).
 *   acc/api.php?p=/persons            GET / POST
 *   acc/api.php?p=/persons/3          PUT / DELETE
 * Auth: "Authorization: Bearer <token>" from /login (or ?token= for links).
 */

require_once __DIR__ . '/acc_core.php';
require_once __DIR__ . '/acc_ops.php';
require_once __DIR__ . '/acc_sms.php';
require_once __DIR__ . '/acc_more.php';
require_once __DIR__ . '/acc_bank.php';
require_once __DIR__ . '/acc_moadian.php';

function acc_routes()
{
    // [method, path regex, permission ('' any user, 'admin' admins only), handler]
    return [
        ['POST', '#^/login$#', null, 'r_login'],
        ['POST', '#^/login/app$#', null, 'r_login_app'],
        ['POST', '#^/download-ticket$#', '', 'r_download_ticket'],
        ['GET', '#^/health$#', null, fn() => ['ok' => true]],
        ['GET', '#^/me$#', '', fn($u) => acc_user_out($u)],
        ['PUT', '#^/me/password$#', '', 'r_change_password'],
        ['GET', '#^/dashboard$#', 'dashboard', 'r_dashboard'],
        ['GET', '#^/company$#', '', 'r_company'],
        ['PUT', '#^/company$#', 'admin', 'r_company_save'],
        ['GET', '#^/persons$#', 'persons', 'r_persons'],
        ['POST', '#^/persons$#', 'persons', 'r_person_create'],
        ['PUT', '#^/persons/(\d+)$#', 'persons', 'r_person_update'],
        ['DELETE', '#^/persons/(\d+)$#', 'persons', 'r_person_delete'],
        ['GET', '#^/products$#', 'products', 'r_products'],
        ['POST', '#^/products$#', 'products', 'r_product_create'],
        ['PUT', '#^/products/(\d+)$#', 'products', 'r_product_update'],
        ['DELETE', '#^/products/(\d+)$#', 'products', 'r_product_delete'],
        ['GET', '#^/invoices$#', '', 'r_invoices'],
        ['POST', '#^/invoices$#', '', 'r_invoice_create'],
        ['GET', '#^/invoices/(\d+)/detail$#', '', 'r_invoice_detail'],
        ['GET', '#^/invoices/(\d+)/print$#', '', 'r_invoice_print'],
        ['POST', '#^/invoices/(\d+)/finalize$#', '', 'r_invoice_finalize'],
        ['PUT', '#^/invoices/(\d+)$#', '', 'r_invoice_update'],
        ['DELETE', '#^/invoices/(\d+)$#', '', 'r_invoice_delete'],
        ['GET', '#^/journals$#', 'accounting', 'r_journals'],
        ['POST', '#^/journals$#', 'accounting', 'r_journal_create'],
        ['POST', '#^/journals/opening$#', 'accounting', 'r_opening'],
        ['POST', '#^/journals/closing$#', 'accounting', 'r_closing'],
        ['POST', '#^/journals/(\d+)/void$#', 'accounting', 'r_journal_void'],
        ['GET', '#^/coa$#', '', 'r_coa'],
        ['POST', '#^/coa$#', 'accounting', 'r_coa_create'],
        ['GET', '#^/fiscal$#', '', 'r_fiscal'],
        ['POST', '#^/fiscal/lock$#', 'admin', fn($u) => r_fiscal_set($u, 1)],
        ['POST', '#^/fiscal/unlock$#', 'admin', fn($u) => r_fiscal_set($u, 0)],
        ['GET', '#^/trial-balance$#', 'accounting', 'r_trial_balance'],
        ['GET', '#^/users$#', 'admin', 'r_users'],
        ['POST', '#^/users$#', 'admin', 'r_user_create'],
        ['PUT', '#^/users/(\d+)/permissions$#', 'admin', 'r_user_perms'],
        ['PUT', '#^/users/(\d+)/companies$#', 'admin', 'r_user_companies'],
        ['GET', '#^/permissions/roles$#', '', fn() => ACC_ROLE_PERMS],
        ['GET', '#^/logs$#', 'admin', 'r_logs'],
        ['GET', '#^/backup$#', 'admin', 'r_backup'],
        ['GET', '#^/accounts$#', 'treasury', 'r_accounts'],
        ['POST', '#^/accounts$#', 'treasury', 'r_account_create'],
        ['GET', '#^/treasury$#', 'treasury', 'r_treasury'],
        ['POST', '#^/treasury$#', 'treasury', 'r_treasury_create'],
        ['GET', '#^/cheques$#', 'treasury', 'r_cheques'],
        ['POST', '#^/cheques$#', 'treasury', 'r_cheque_create'],
        ['POST', '#^/cheques/(\d+)/action$#', 'treasury', 'r_cheque_action'],
        ['GET', '#^/warehouses$#', '', 'r_warehouses'],
        ['POST', '#^/warehouses$#', 'warehouse', 'r_warehouse_create'],
        ['GET', '#^/stock$#', '', 'r_stock'],
        ['GET', '#^/warehouse-docs$#', 'warehouse', 'r_wh_docs'],
        ['POST', '#^/warehouse-docs$#', 'warehouse', 'r_wh_doc_create'],
        ['GET', '#^/kardex/(\d+)$#', '', 'r_kardex'],
        ['GET', '#^/serials$#', 'warehouse', 'r_serials'],
        ['POST', '#^/serials$#', 'warehouse', 'r_serial_create'],
        ['POST', '#^/serials/(\d+)/out$#', 'warehouse', 'r_serial_out'],
        ['GET', '#^/stock-counts$#', 'warehouse', 'r_counts'],
        ['POST', '#^/stock-counts$#', 'warehouse', 'r_count_create'],
        ['POST', '#^/webhook/test$#', 'admin', fn() => (acc_webhook('test', 'System', [0], ['message' => 'تست اتصال']) ?: ['ok' => true])],
        ['GET', '#^/tax-invoices$#', 'tax', 'r_tax_list'],
        ['GET', '#^/tax-invoices/(\d+)$#', 'tax', 'r_tax_get'],
        ['POST', '#^/tax-invoices/(\d+)/send$#', 'tax', 'r_tax_send'],
        ['POST', '#^/tax-invoices/(\d+)/inquire$#', 'tax', 'r_tax_inquire'],
        ['GET', '#^/tax-report$#', 'tax', 'r_tax_report'],
        ['GET', '#^/reports/daybook$#', 'reports', 'r_daybook'],
        ['GET', '#^/reports/balance-sheet$#', 'reports', 'r_balance_sheet'],
        ['GET', '#^/reports/cashflow$#', 'reports', 'r_cashflow'],
        ['GET', '#^/reports/dues$#', 'reports', 'r_dues'],
        ['GET', '#^/reports/cogs$#', 'reports', 'r_cogs'],
        ['GET', '#^/reports/profit-loss$#', 'reports', 'r_profit_loss'],
        ['GET', '#^/reports/ledger/(\d+)$#', 'reports', 'r_ledger'],
        ['GET', '#^/reports/person/(\d+)$#', 'reports', 'r_person_statement'],
        ['GET', '#^/export/csv$#', 'export', 'r_export_csv'],
        ['GET', '#^/branches$#', '', fn() => acc_all('SELECT id, name, city FROM acc_branches')],
        ['POST', '#^/branches$#', 'admin', 'r_branch_create'],
        ['GET', '#^/currencies$#', '', fn() => acc_all('SELECT id, code, name, rate FROM acc_currencies')],
        ['POST', '#^/currencies$#', 'admin', 'r_currency_save'],
        ['GET', '#^/bank-statements$#', 'treasury', 'r_statements'],
        ['POST', '#^/bank-statements$#', 'treasury', 'r_statement_create'],
        ['POST', '#^/bank-statements/(\d+)/match$#', 'treasury', 'r_statement_match'],
        ['POST', '#^/attachments$#', 'attachments', 'r_attach_upload'],
        ['GET', '#^/attachments$#', 'attachments', 'r_attach_list'],
        ['GET', '#^/attachments/(\d+)/download$#', 'attachments', 'r_attach_download'],
        ['DELETE', '#^/attachments/(\d+)$#', 'attachments', 'r_attach_delete'],
        ['GET', '#^/sms/settings$#', 'admin', 'r_sms_settings'],
        ['PUT', '#^/sms/settings$#', 'admin', 'r_sms_settings_save'],
        ['GET', '#^/sms/credit$#', 'sms', 'r_sms_credit'],
        ['GET', '#^/sms/templates$#', 'sms', fn() => (acc_sms_schema() ?: acc_all('SELECT * FROM acc_sms_templates ORDER BY id'))],
        ['POST', '#^/sms/templates$#', 'sms', fn($u) => r_sms_item_save('acc_sms_templates', null)],
        ['PUT', '#^/sms/templates/(\d+)$#', 'sms', fn($u, $id) => r_sms_item_save('acc_sms_templates', $id)],
        ['DELETE', '#^/sms/templates/(\d+)$#', 'sms', fn($u, $id) => r_sms_item_delete('acc_sms_templates', $id)],
        ['GET', '#^/sms/patterns$#', 'sms', 'r_sms_patterns'],
        ['POST', '#^/sms/patterns$#', 'sms', fn($u) => r_sms_item_save('acc_sms_patterns', null)],
        ['PUT', '#^/sms/patterns/(\d+)$#', 'sms', fn($u, $id) => r_sms_item_save('acc_sms_patterns', $id)],
        ['DELETE', '#^/sms/patterns/(\d+)$#', 'sms', fn($u, $id) => r_sms_item_delete('acc_sms_patterns', $id)],
        ['POST', '#^/sms/send$#', 'sms', 'r_sms_send'],
        ['POST', '#^/sms/group$#', 'sms', 'r_sms_group'],
        ['GET', '#^/sms/log$#', 'sms', 'r_sms_log'],
        ['POST', '#^/sms/process$#', 'sms', fn() => ['tried' => acc_sms_process(50)]],
        ['POST', '#^/sms/retry/(\d+)$#', 'sms', 'r_sms_retry'],
    ];
}

/* ------------------------------------------------------------------ */
/* dispatcher                                                           */
/* ------------------------------------------------------------------ */

function acc_out($data, $code = 200)
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

/** Request body as array (JSON, or form fields). */
function acc_body()
{
    static $b = null;
    if ($b === null) {
        $raw = file_get_contents('php://input');
        $b = $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($b)) {
            $b = $_POST ?: [];
        }
    }
    return $b;
}

function acc_dispatch()
{
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    $path = '/' . trim((string)($_GET['p'] ?? ''), '/');
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    try {
        foreach (array_merge(acc_routes(), acc_more_routes()) as [$m, $re, $perm, $fn]) {
            if ($m !== $method || !preg_match($re, $path, $mm)) {
                continue;
            }
            array_shift($mm);
            $user = null;
            if ($perm !== null) {
                $token = ba_bearer_token();
                $user = $token === '' && isset($_GET['ticket']) ? acc_ticket_user((string)$_GET['ticket'], $path) : acc_session_user($token);
                if (!$user) {
                    throw new AccError('توکن نامعتبر است', 401);
                }
                if ($perm === 'admin' ? $user['role'] !== 'admin' : ($perm !== '' && !acc_can($user, $perm))) {
                    throw new AccError('دسترسی به «' . $perm . '» ندارید', 403);
                }
            }
            // the company chosen in the panel; users, logins and the bank link stay in the main one
            if ($user && !preg_match('#^/(me|users|companies|bank-link|bank/)#', $path)) {
                $cid = (int)($_SERVER['HTTP_X_COMPANY'] ?? ($_GET['company'] ?? 1)) ?: 1;
                if ($cid !== 1 && !isset(acc_companies()[$cid])) {
                    throw new AccError('موسسه یافت نشد', 404);
                }
                if (!in_array($cid, acc_user_companies($user), true)) {
                    throw new AccError('به این موسسه دسترسی ندارید', 403);
                }
                acc_use_company($cid);
            }
            $res = $fn($user, ...array_map('intval', $mm));
            if ($res !== null) {
                acc_out($res);
            }
            return;
        }
        throw new AccError('مسیر نامعتبر', 404);
    } catch (AccError $e) {
        acc_out(['detail' => $e->getMessage()], $e->status);
    } catch (InvalidArgumentException $e) {
        acc_out(['detail' => $e->getMessage()], 400);
    } catch (Throwable $e) {
        acc_out(['detail' => ba_public_error($e)], 500);
    }
}

/**
 * Links the browser opens by itself (print page, backup, CSV, attachment) cannot
 * send the Authorization header: the panel asks for a one-time ticket for exactly
 * that address, valid 60 seconds, and puts it in the link instead of its session.
 */
function r_download_ticket($u)
{
    $path = '/' . trim((string)(acc_body()['path'] ?? ''), '/');
    if (!preg_match('#^/(invoices/\d+/print|labels|backup|export/csv|attachments/\d+/download|reports/ttms)$#', $path)) {
        throw new AccError('این آدرس لینک دانلود ندارد');
    }
    $ticket = bin2hex(random_bytes(16));
    $all = array_filter((array)ba_kv_get('acc_tickets', []), fn($t) => $t['exp'] > time());
    $all[hash('sha256', $ticket)] = ['user' => (int)$u['id'], 'path' => $path, 'exp' => time() + 60];
    ba_kv_set('acc_tickets', $all);
    return ['ticket' => $ticket];
}

/** The user behind a one-time download ticket for this address (used up on the spot). */
function acc_ticket_user($ticket, $path)
{
    $all = (array)ba_kv_get('acc_tickets', []);
    $key = hash('sha256', $ticket);
    $t = $all[$key] ?? null;
    if (!$t) {
        return null;
    }
    unset($all[$key]);
    ba_kv_set('acc_tickets', array_filter($all, fn($x) => $x['exp'] > time()));
    if ($t['exp'] < time() || $t['path'] !== $path) {
        return null;
    }
    return acc_row('SELECT * FROM acc_users WHERE id = ? AND is_active = 1', [$t['user']]) ?: null;
}

/** Requires a permission inside a handler (when it depends on the body). */
function acc_need(array $u, $perm)
{
    if (!acc_can($u, $perm)) {
        throw new AccError('دسترسی به «' . $perm . '» ندارید', 403);
    }
}

function acc_num($v)
{
    return (float)preg_replace('/[^\d.\-]/', '', ba_normalize((string)$v)) ?: 0.0;
}

/* ------------------------------------------------------------------ */
/* login, company, users                                                */
/* ------------------------------------------------------------------ */

function r_login()
{
    $b = acc_body();
    return acc_login($b['username'] ?? '', $b['password'] ?? '');
}

/**
 * The phone app (which holds the app password, the owner's key to everything)
 * opens the accounting panel inside it without a second login: as admin.
 */
function r_login_app()
{
    $app = (string)(ba_config()['app_token'] ?? '');
    $given = (string)($_SERVER['HTTP_X_APP_TOKEN'] ?? '');
    if (($wait = ba_throttle_wait('app')) > 0) {
        throw new AccError('تلاش زیاد با رمز اشتباه؛ ' . ceil($wait / 60) . ' دقیقه بعد دوباره امتحان کن.', 429);
    }
    if ($app === '' || strpos($app, 'CHANGE-ME') === 0 || !hash_equals($app, $given)) {
        ba_throttle_fail('app');
        throw new AccError('رمز اپ نادرست است', 401);
    }
    $u = acc_row("SELECT * FROM acc_users WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
    if (!$u) {
        throw new AccError('کاربر مدیر فعالی نیست', 403);
    }
    $token = bin2hex(random_bytes(24));
    acc_q('DELETE FROM acc_sessions WHERE expires_at < ?', [time()]);
    acc_insert('acc_sessions', ['token_hash' => hash('sha256', $token), 'user_id' => $u['id'], 'expires_at' => time() + 12 * 3600]);
    acc_log($u['username'], 'login', 'from the phone app');
    return ['token' => $token, 'access_token' => $token, 'user' => acc_user_out($u)];
}

function r_change_password($u)
{
    $b = acc_body();
    if (!password_verify((string)($b['old_password'] ?? ''), $u['password_hash'])) {
        throw new AccError('رمز فعلی اشتباه است');
    }
    if (mb_strlen((string)($b['new_password'] ?? '')) < 10) {
        throw new AccError('رمز جدید حداقل ۱۰ حرف باشد');
    }
    acc_update('acc_users', $u['id'], ['password_hash' => password_hash($b['new_password'], PASSWORD_DEFAULT)]);
    acc_q('DELETE FROM acc_sessions WHERE user_id = ?', [$u['id']]);
    if ($u['username'] === 'admin') {
        ba_kv_set('acc_admin_default_pw', 0);
    }
    acc_log($u['username'], 'change_password');
    return ['ok' => true];
}

/** A user-typed address the server will call: refuse this server and the private network. */
function acc_check_public_url($url)
{
    try {
        curl_close(ba_curl_public($url, []));
    } catch (RuntimeException $e) {
        throw new AccError($e->getMessage());
    }
}

function r_dashboard()
{
    $low = acc_all("SELECT id, name, stock, reorder_point, unit FROM acc_products WHERE kind != 'service' AND stock <= reorder_point");
    return [
        // net of returns, without VAT
        'sales' => (float)acc_val("SELECT COALESCE(SUM(CASE kind WHEN 'sale' THEN 1 ELSE -1 END * (subtotal - discount)), 0) FROM acc_invoices WHERE kind IN ('sale', 'sale_return')"),
        'purchases' => (float)acc_val("SELECT COALESCE(SUM(CASE kind WHEN 'purchase' THEN 1 ELSE -1 END * (subtotal - discount + freight + customs + other_cost)), 0) FROM acc_invoices WHERE kind IN ('purchase', 'purchase_return')"),
        'receivables' => acc_people_split()[0],
        'payables' => acc_people_split()[1],
        'stock_value' => (float)acc_val("SELECT COALESCE(SUM(stock * CASE WHEN avg_cost > 0 THEN avg_cost ELSE buy_price END), 0) FROM acc_products WHERE kind != 'service'"),
        'cash' => (float)acc_val('SELECT COALESCE(SUM(balance), 0) FROM acc_cash_accounts'),
        'low_stock' => $low,
    ];
}

function r_company($u = null)
{
    $c = acc_company();
    foreach (['webhook_enabled', 'tax_key_set', 'allow_negative_stock'] as $k) {
        $c[$k] = (bool)$c[$k];
    }
    unset($c['id'], $c['tax_private_key'], $c['tax_certificate'], $c['api_key']);
    if (!$u || $u['role'] !== 'admin') {   // every user reads the company's name and settings, only admin its secrets
        unset($c['webhook_secret'], $c['webhook_url']);
    }
    return $c;
}

function r_company_save($u)
{
    $b = acc_body();
    $allowed = ['name', 'national_id', 'economic_code', 'vat_rate', 'tax_memory', 'tax_key_set', 'invoice_prefix_sale',
        'invoice_prefix_buy', 'allow_negative_stock', 'webhook_enabled', 'webhook_url', 'webhook_secret', 'address', 'phone', 'invoice_footer', 'postal_code'];
    $set = [];
    foreach ($allowed as $k) {
        if (array_key_exists($k, $b)) {
            $set[$k] = is_bool($b[$k]) ? (int)$b[$k] : $b[$k];
        }
    }
    if (isset($set['webhook_url']) && $set['webhook_url'] !== '' && !preg_match('~^https?://~', $set['webhook_url'])) {
        throw new AccError('آدرس Webhook باید با http یا https شروع شود');
    }
    if (!empty($set['webhook_url'])) {
        acc_check_public_url($set['webhook_url']);
    }
    acc_update('acc_company', 1, $set);
    acc_log($u['username'], 'update_company');
    return ['ok' => true];
}

function r_users()
{
    return array_map(fn($x) => ['id' => (int)$x['id'], 'username' => $x['username'], 'full_name' => $x['full_name'],
        'role' => $x['role'], 'is_active' => (bool)$x['is_active'], 'companies' => acc_user_companies($x)], acc_all('SELECT * FROM acc_users ORDER BY id'));
}

function r_user_create($u)
{
    $b = acc_body();
    $name = trim((string)($b['username'] ?? ''));
    if ($name === '' || mb_strlen((string)($b['password'] ?? '')) < 10) {
        throw new AccError('نام کاربری و رمز (حداقل ۱۰ حرف) لازم است');
    }
    if (!isset(ACC_ROLE_PERMS[$b['role'] ?? ''])) {
        throw new AccError('نقش نامعتبر است');
    }
    if (acc_val('SELECT 1 FROM acc_users WHERE username = ?', [$name])) {
        throw new AccError('این نام کاربری وجود دارد');
    }
    acc_insert('acc_users', ['username' => $name, 'full_name' => (string)($b['full_name'] ?? ''),
        'password_hash' => password_hash($b['password'], PASSWORD_DEFAULT), 'role' => $b['role'], 'created_at' => acc_now()]);
    acc_log($u['username'], 'create_user', $name);
    return ['ok' => true];
}

function r_user_perms($u, $uid)
{
    $perms = array_values(array_filter((array)(acc_body()['permissions'] ?? []), 'is_string'));
    if (!acc_val('SELECT 1 FROM acc_users WHERE id = ?', [$uid])) {
        throw new AccError('کاربر یافت نشد', 404);
    }
    acc_update('acc_users', $uid, ['permissions' => json_encode($perms, JSON_UNESCAPED_UNICODE)]);
    return ['ok' => true, 'permissions' => $perms];
}

function r_logs()
{
    return acc_all('SELECT id, user, action, detail, created_at FROM acc_logs ORDER BY id DESC LIMIT 100');
}

/** Full backup: a consistent copy of the company's database file. */
function r_backup($u)
{
    $tmp = tempnam(sys_get_temp_dir(), 'accbk');
    @unlink($tmp);
    acc_db()->exec('VACUUM INTO ' . acc_db()->quote($tmp));
    acc_log($u['username'], 'backup');
    $name = 'backup-' . (acc_company_id() === 1 ? 'main' : 'company' . acc_company_id()) . '-' . date('Ymd-His') . '.sqlite';
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    unlink($tmp);
    return null;
}

/** Puts back a backup file; the current file is kept next to it first. */
function r_restore($u)
{
    if (!isset($_FILES['file']) || !is_uploaded_file($_FILES['file']['tmp_name'])) {
        throw new AccError('فایل پشتیبان (.sqlite) را انتخاب کن');
    }
    $up = $_FILES['file']['tmp_name'];
    if (file_get_contents($up, false, null, 0, 16) !== "SQLite format 3\0") {
        throw new AccError('این فایل پشتیبان این برنامه نیست');
    }
    try {
        $t = new PDO('sqlite:' . $up);
        $t->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $ok = $t->query('PRAGMA integrity_check')->fetchColumn() === 'ok' && $t->query('SELECT COUNT(*) FROM acc_journals')->fetchColumn() !== false
            // this program makes no triggers or views: a file with them could run hidden SQL on every query later
            && (int)$t->query("SELECT COUNT(*) FROM sqlite_master WHERE type IN ('trigger', 'view')")->fetchColumn() === 0;
        $t = null;
    } catch (Throwable $e) {
        $ok = false;
    }
    if (!$ok) {
        throw new AccError('فایل پشتیبان خراب است یا اطلاعات حسابداری ندارد');
    }
    $cid = acc_company_id();
    $file = $cid === 1 ? BA_DB_PATH : acc_company_file($cid);
    $keep = dirname($file) . '/before-restore-' . date('Ymd-His') . '-' . basename($file);
    acc_db()->exec('VACUUM INTO ' . acc_db()->quote($keep));
    if (!move_uploaded_file($up, $file . '.new') || !rename($file . '.new', $file)) {
        throw new AccError('نوشتن فایل روی سرور ممکن نشد', 500);
    }
    @unlink($file . '-wal');
    @unlink($file . '-shm');
    error_log('acc restore by ' . $u['username'] . ', previous file kept as ' . basename($keep));
    return ['ok' => true, 'kept' => basename($keep)];
}

function r_companies($u)
{
    $mine = acc_user_companies($u);
    $out = [];
    foreach (acc_companies() as $id => $name) {
        if (in_array($id, $mine, true)) {
            $out[] = ['id' => $id, 'name' => $name];
        }
    }
    return $out;
}

/** Which companies a (non-admin) user may open. */
function r_user_companies($u, $uid)
{
    $x = acc_row('SELECT * FROM acc_users WHERE id = ?', [$uid]);
    if (!$x) {
        throw new AccError('کاربر یافت نشد', 404);
    }
    $all = array_keys(acc_companies());
    $ids = array_values(array_intersect($all, array_map('intval', (array)(acc_body()['companies'] ?? []))));
    acc_update('acc_users', $uid, ['companies' => json_encode($ids ?: [1])]);
    acc_log($u['username'], 'user_companies', $x['username'] . ': ' . implode(',', $ids ?: [1]));
    return ['ok' => true, 'companies' => $ids ?: [1]];
}

/** New company (موسسه) with its own books; lists can be copied from the current one. */
function r_company_create($u)
{
    $b = acc_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام موسسه را بنویس');
    }
    $from = (int)($b['copy_from'] ?? 0);
    $list = (array)ba_kv_get('acc_companies', []);
    $id = max(array_merge([1], array_column($list, 'id'))) + 1;
    $copy = [];
    if ($from && isset(acc_companies()[$from])) {
        acc_use_company($from);
        $copy = ['persons' => acc_all("SELECT code, name, type, mobile, phone2, national_id, legal_type, address, groups, credit_limit FROM acc_persons"),
            'products' => acc_all("SELECT code, name, unit, sale_price, buy_price, barcode, group_name, unit2, unit2_factor, reorder_point, max_stock, tax_code, kind FROM acc_products"),
            'coa' => acc_all('SELECT code, name, level, nature, parent_code FROM acc_coa')];
    }
    acc_use_company($id);
    acc_db();
    acc_update('acc_company', 1, ['name' => $name]);
    acc_tx(function () use ($copy) {
        foreach ($copy['coa'] ?? [] as $r) {
            acc_q('INSERT OR IGNORE INTO acc_coa (code, name, level, nature, parent_code) VALUES (?, ?, ?, ?, ?)', array_values($r));
        }
        foreach (['persons', 'products'] as $t) {
            foreach ($copy[$t] ?? [] as $r) {
                acc_insert('acc_' . $t, $r);
            }
        }
    });
    acc_use_company(1);
    $list[] = ['id' => $id, 'name' => $name];
    ba_kv_set('acc_companies', $list);
    acc_log($u['username'], 'company_create', $name);
    return ['ok' => true, 'id' => $id];
}

/* ------------------------------------------------------------------ */
/* persons and products                                                 */
/* ------------------------------------------------------------------ */

const ACC_PERSON_TYPES = ['customer' => 'مشتری', 'supplier' => 'تأمین‌کننده', 'employee' => 'کارمند', 'investor' => 'سرمایه‌گذار',
    'marketer' => 'بازاریاب', 'other' => 'سایر'];

function acc_person_out(array $p)
{
    return ['id' => (int)$p['id'], 'code' => $p['code'], 'name' => $p['name'], 'type' => $p['type'], 'mobile' => $p['mobile'],
        'national_id' => $p['national_id'], 'balance' => (float)$p['balance'], 'credit_limit' => (float)$p['credit_limit'],
        'legal_type' => $p['legal_type'] ?: 'real', 'address' => $p['address'] ?: '', 'groups' => $p['groups'] ?? '',
        'phone2' => $p['phone2'] ?? '', 'commission_rate' => (float)($p['commission_rate'] ?? 0), 'type_label' => ACC_PERSON_TYPES[$p['type']] ?? $p['type']];
}

function r_persons()
{
    $type = $_GET['type'] ?? '';
    $rows = $type !== '' ? acc_all('SELECT * FROM acc_persons WHERE type = ? ORDER BY id', [$type]) : acc_all('SELECT * FROM acc_persons ORDER BY id');
    return array_map('acc_person_out', $rows);
}

function acc_person_fields(array $b)
{
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام شخص را بنویس');
    }
    $type = (string)($b['type'] ?? 'customer');
    if (!isset(ACC_PERSON_TYPES[$type])) {
        $type = 'customer';
    }
    return ['name' => $name, 'type' => $type, 'mobile' => (string)($b['mobile'] ?? ''), 'phone2' => (string)($b['phone2'] ?? ''),
        'commission_rate' => min(max(acc_num($b['commission_rate'] ?? 0), 0), 100),
        'national_id' => (string)($b['national_id'] ?? ''), 'credit_limit' => acc_num($b['credit_limit'] ?? 0),
        'legal_type' => ($b['legal_type'] ?? '') === 'legal' ? 'legal' : 'real', 'address' => (string)($b['address'] ?? ''),
        'groups' => implode(',', array_unique(array_filter(array_map('trim', preg_split('/[,،]/u', (string)($b['groups'] ?? '')))))) ];
}

function r_person_create($u)
{
    $b = acc_body();
    $f = acc_person_fields($b);
    $id = acc_tx(function () use ($f, $b) {
        $f['code'] = (['supplier' => 'S', 'employee' => 'E', 'investor' => 'I', 'marketer' => 'M', 'other' => 'O'][$f['type']] ?? 'C') . sprintf('%03d', acc_next('person'));
        $id = acc_insert('acc_persons', $f);
        // optional opening balance: + they owe us, - we owe them
        $opening = acc_num($b['opening'] ?? ($b['balance'] ?? 0));
        if ($opening) {
            acc_post(acc_today(), 'مانده اول ' . $f['name'], [acc_person_line($id, $opening, 'افتتاحیه'),
                acc_line('3101', max(-$opening, 0), max($opening, 0), 'افتتاحیه')], 'opening', 'person', $id);
        }
        return $id;
    });
    acc_log($u['username'], 'create_person', $f['name']);
    acc_webhook('create', 'Contact', [$id], ['id' => $id, 'name' => $f['name'], 'type' => $f['type']]);
    return ['id' => $id, 'code' => acc_val('SELECT code FROM acc_persons WHERE id = ?', [$id])];
}

function r_person_update($u, $id)
{
    if (!acc_val('SELECT 1 FROM acc_persons WHERE id = ?', [$id])) {
        throw new AccError('شخص یافت نشد', 404);
    }
    $f = acc_person_fields(acc_body());
    acc_update('acc_persons', $id, $f);
    acc_log($u['username'], 'update_person', $f['name']);
    acc_webhook('update', 'Contact', [$id], ['id' => $id, 'name' => $f['name']]);
    return ['ok' => true];
}

function r_person_delete($u, $id)
{
    if (!acc_val('SELECT 1 FROM acc_persons WHERE id = ?', [$id])) {
        throw new AccError('شخص یافت نشد', 404);
    }
    if (acc_val('SELECT 1 FROM acc_journal_lines WHERE person_id = ? LIMIT 1', [$id]) || acc_val('SELECT 1 FROM acc_invoices WHERE person_id = ? LIMIT 1', [$id])) {
        throw new AccError('این شخص گردش حساب دارد و حذف نمی‌شود');
    }
    acc_q('DELETE FROM acc_persons WHERE id = ?', [$id]);
    acc_log($u['username'], 'delete_person', (string)$id);
    acc_webhook('delete', 'Contact', [$id]);
    return ['ok' => true];
}

function acc_product_out(array $p)
{
    foreach (['sale_price', 'buy_price', 'stock', 'reorder_point', 'max_stock', 'unit2_factor', 'avg_cost', 'last_cost'] as $k) {
        $p[$k] = (float)$p[$k];
    }
    $p['id'] = (int)$p['id'];
    $p['track_serial'] = (bool)$p['track_serial'];
    $p['brand_id'] = $p['brand_id'] ? (int)$p['brand_id'] : null;
    $p['track_lot'] = (bool)$p['track_lot'];
    return $p;
}

function r_products()
{
    $q = trim((string)($_GET['q'] ?? ''));
    $rows = acc_all('SELECT * FROM acc_products ORDER BY id');
    if ($q !== '') {
        $rows = array_filter($rows, fn($p) => mb_strpos((string)$p['name'], $q) !== false || mb_strpos((string)$p['code'], $q) !== false
            || ($p['barcode'] !== '' && $p['barcode'] === $q));
    }
    return array_values(array_map('acc_product_out', $rows));
}

function acc_product_fields(array $b)
{
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام کالا را بنویس');
    }
    $f = ['name' => $name];
    foreach (['code', 'unit', 'barcode', 'group_name', 'unit2'] as $k) {
        $f[$k] = trim((string)($b[$k] ?? ''));
    }
    $f['unit'] = $f['unit'] ?: 'عدد';
    foreach (['sale_price', 'buy_price', 'reorder_point', 'max_stock'] as $k) {
        $f[$k] = acc_num($b[$k] ?? 0);
    }
    $f['unit2_factor'] = acc_num($b['unit2_factor'] ?? 1) ?: 1;
    $f['tax_code'] = preg_replace('/\D/', '', ba_normalize((string)($b['tax_code'] ?? '')));
    $f['brand_id'] = !empty($b['brand_id']) ? (int)$b['brand_id'] : null;
    $f['kind'] = ($b['kind'] ?? '') === 'service' ? 'service' : 'goods';
    $f['track_serial'] = !empty($b['track_serial']) ? 1 : 0;
    $f['track_lot'] = !empty($b['track_lot']) ? 1 : 0;
    return $f;
}

function r_product_create($u)
{
    $b = acc_body();
    $f = acc_product_fields($b);
    $id = acc_tx(function () use ($f, $b) {
        if ($f['code'] === '') {
            $f['code'] = (string)(1000 + acc_next('product'));
        }
        $f['avg_cost'] = $f['buy_price'];
        $f['last_cost'] = $f['buy_price'];
        $id = acc_insert('acc_products', $f);
        $qty = acc_num($b['stock'] ?? 0);
        if ($qty) {
            acc_stock_adjust($id, $qty, 'opening', 'موجودی اول ' . $f['name']);
        }
        return $id;
    });
    acc_log($u['username'], 'create_product', $f['name']);
    acc_webhook('create', 'Product', [$id], ['id' => $id, 'name' => $f['name']]);
    return ['id' => $id];
}

function r_product_update($u, $id)
{
    $p = acc_product($id);
    if (!$p) {
        throw new AccError('کالا یافت نشد', 404);
    }
    $b = acc_body();
    $f = acc_product_fields($b);
    acc_tx(function () use ($id, $f, $b, $p) {
        acc_update('acc_products', $id, $f);
        // a changed stock number is an adjustment of the default warehouse
        if (array_key_exists('stock', $b)) {
            $diff = acc_num($b['stock']) - (float)$p['stock'];
            if (abs($diff) > 1e-9) {
                acc_stock_adjust($id, $diff, 'adjust', 'اصلاح موجودی ' . $f['name']);
            }
        }
    });
    acc_log($u['username'], 'update_product', $f['name']);
    acc_webhook('update', 'Product', [$id], ['id' => $id, 'name' => $f['name']]);
    return ['ok' => true];
}

function r_product_delete($u, $id)
{
    if (!acc_product($id)) {
        throw new AccError('کالا یافت نشد', 404);
    }
    if (acc_val('SELECT 1 FROM acc_invoice_items WHERE product_id = ? LIMIT 1', [$id]) || acc_val('SELECT 1 FROM acc_stock_moves WHERE product_id = ? LIMIT 1', [$id])) {
        throw new AccError('این کالا گردش دارد و حذف نمی‌شود');
    }
    acc_q('DELETE FROM acc_products WHERE id = ?', [$id]);
    acc_q('DELETE FROM acc_stock WHERE product_id = ?', [$id]);
    acc_log($u['username'], 'delete_product', (string)$id);
    acc_webhook('delete', 'Product', [$id]);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* invoices                                                             */
/* ------------------------------------------------------------------ */

function acc_invoice_perm(array $u, $kind)
{
    acc_need($u, strpos((string)$kind, 'purchase') === 0 ? 'purchases' : 'sales');
}

function r_invoices($u)
{
    // ?kind=sale (one kind) or ?group=sale (sale, its returns, pro-formas and orders)
    $kind = (string)($_GET['kind'] ?? '');
    $group = in_array($_GET['group'] ?? '', ['sale', 'purchase'], true) ? $_GET['group'] : '';
    if ($kind === '' && $group === '') {
        if (!acc_can($u, 'sales') || !acc_can($u, 'purchases')) {
            throw new AccError('نوع فاکتور (kind یا group) را مشخص کن', 403);
        }
    } else {
        acc_invoice_perm($u, $kind !== '' ? $kind : $group);
    }
    $where = $kind !== '' ? ' WHERE i.kind = ?' : ($group !== '' ? ' WHERE i.kind LIKE ?' : '');
    $rows = acc_all('SELECT i.*, p.name AS person_name FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id'
        . $where . ' ORDER BY i.id DESC', $kind !== '' ? [$kind] : ($group !== '' ? [$group . '%'] : []));
    return array_map(fn($i) => ['id' => (int)$i['id'], 'number' => $i['number'], 'kind' => $i['kind'], 'date' => $i['date'],
        'person_id' => (int)$i['person_id'], 'person_name' => $i['person_name'] ?? '-', 'subtotal' => (float)$i['subtotal'],
        'discount' => (float)$i['discount'], 'tax' => (float)$i['tax'], 'total' => (float)$i['total'], 'settled' => (bool)$i['settled'],
        'discount_percent' => (float)$i['discount_percent'], 'atf' => $i['atf'], 'status' => $i['status'], 'due_date' => $i['due_date']], $rows);
}

function r_invoice_create($u)
{
    $b = acc_body();
    acc_invoice_perm($u, $b['kind'] ?? '');
    $inv = acc_invoice_save(null, $b);
    acc_log($u['username'], 'create_invoice', $inv['number']);
    acc_sms_after_invoice($inv);
    acc_webhook('create', 'Invoice', [(int)$inv['id']], ['number' => $inv['number'], 'total' => (float)$inv['total'], 'kind' => $inv['kind']]);
    return ['id' => (int)$inv['id'], 'number' => $inv['number'], 'subtotal' => (float)$inv['subtotal'], 'discount' => (float)$inv['discount'],
        'total' => (float)$inv['total'], 'tax' => (float)$inv['tax'], 'atf' => $inv['atf']];
}

function r_invoice_update($u, $id)
{
    $old = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    if (!$old) {
        throw new AccError('فاکتور یافت نشد', 404);
    }
    $b = acc_body();
    acc_invoice_perm($u, $old['kind']);
    acc_invoice_perm($u, $b['kind'] ?? $old['kind']);
    $inv = acc_invoice_save($id, $b);
    acc_log($u['username'], 'edit_invoice', $inv['number']);
    return ['ok' => true, 'total' => (float)$inv['total']];
}

function r_invoice_delete($u, $id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        throw new AccError('فاکتور یافت نشد', 404);
    }
    acc_invoice_perm($u, $inv['kind']);
    acc_require_open();
    acc_tx(function () use ($inv) {
        acc_invoice_unpost($inv, 'حذف فاکتور');
        acc_q('DELETE FROM acc_invoice_items WHERE invoice_id = ?', [$inv['id']]);
        acc_q('DELETE FROM acc_tax_invoices WHERE invoice_id = ?', [$inv['id']]);
        acc_q('DELETE FROM acc_invoices WHERE id = ?', [$inv['id']]);
    });
    acc_log($u['username'], 'delete_invoice', $inv['number']);
    acc_webhook('delete', 'Invoice', [$id]);
    return ['ok' => true];
}

function r_invoice_detail($u, $id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        throw new AccError('فاکتور یافت نشد', 404);
    }
    acc_invoice_perm($u, $inv['kind']);
    $items = acc_all('SELECT * FROM acc_invoice_items WHERE invoice_id = ?', [$id]);
    return ['id' => (int)$inv['id'], 'number' => $inv['number'], 'kind' => $inv['kind'], 'date' => $inv['date'],
        'person_id' => (int)$inv['person_id'], 'subtotal' => (float)$inv['subtotal'], 'discount' => (float)$inv['discount'],
        'discount_percent' => (float)$inv['discount_percent'], 'tax' => (float)$inv['tax'], 'total' => (float)$inv['total'],
        'freight' => (float)$inv['freight'], 'customs' => (float)$inv['customs'], 'other_cost' => (float)$inv['other_cost'],
        'status' => $inv['status'], 'settled' => (bool)$inv['settled'], 'due_date' => $inv['due_date'], 'note' => $inv['note'],
        'no_vat' => (bool)$inv['no_vat'], 'marketer_id' => $inv['marketer_id'] ? (int)$inv['marketer_id'] : null, 'department_id' => $inv['department_id'] ? (int)$inv['department_id'] : null,
        'branch_id' => $inv['branch_id'] ? (int)$inv['branch_id'] : null,
        'items' => array_map(fn($it) => ['product_id' => (int)$it['product_id'], 'qty' => (float)$it['qty'], 'price' => (float)$it['price'],
            'unit' => (float)$it['unit_factor'] != 1.0 ? 'secondary' : 'primary'], $items)];
}

function r_invoice_finalize($u, $id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        throw new AccError('سند یافت نشد', 404);
    }
    acc_invoice_perm($u, $inv['kind']);
    $inv = acc_invoice_finalize($inv);
    acc_sms_after_invoice($inv);
    acc_log($u['username'], 'finalize_invoice', $inv['number']);
    acc_webhook('update', 'Invoice', [$id], ['number' => $inv['number'], 'kind' => $inv['kind']]);
    return ['id' => (int)$inv['id'], 'number' => $inv['number'], 'kind' => $inv['kind'], 'total' => (float)$inv['total']];
}

function r_invoice_print($u, $id)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$id]);
    if (!$inv) {
        throw new AccError('فاکتور یافت نشد', 404);
    }
    acc_invoice_perm($u, $inv['kind']);
    $c = acc_company();
    $p = acc_row('SELECT * FROM acc_persons WHERE id = ?', [$inv['person_id']]) ?: [];
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $n = fn($v) => number_format((float)$v);
    $titles = ['sale' => 'فاکتور فروش', 'purchase' => 'فاکتور خرید', 'sale_return' => 'برگشت از فروش', 'purchase_return' => 'برگشت از خرید',
        'sale_proforma' => 'پیش‌فاکتور فروش', 'purchase_proforma' => 'پیش‌فاکتور خرید', 'sale_order' => 'سفارش فروش', 'purchase_order' => 'سفارش خرید'];
    $rows = '';
    $i = 0;
    foreach (acc_all('SELECT it.*, pr.code, pr.name FROM acc_invoice_items it LEFT JOIN acc_products pr ON pr.id = it.product_id WHERE invoice_id = ?', [$id]) as $it) {
        $rows .= '<tr><td>' . (++$i) . '</td><td>' . $e($it['code']) . '</td><td>' . $e($it['name']) . '</td><td>' . $e((float)$it['qty'] . ' ' . $it['unit'])
            . '</td><td>' . $n($it['price']) . '</td><td>' . $n($it['qty'] * $it['price']) . '</td></tr>';
    }
    $printJs = acc_print_page_headers();
    echo '<!doctype html><html lang="fa" dir="rtl"><head><meta charset="utf-8"><title>' . $e($inv['number']) . '</title>'
        . '<style>body{font-family:Vazirmatn,Tahoma,sans-serif;padding:24px}table{width:100%;border-collapse:collapse}td,th{border:1px solid #bbb;padding:6px;text-align:right}'
        . '.muted{color:#555}.tot td{font-weight:bold}@media print{button{display:none}}</style></head><body data-autoprint>'
        . '<h2>' . $e($titles[$inv['kind']] ?? 'فاکتور') . ' ' . $e($inv['number']) . '</h2>'
        . '<p>فروشنده: ' . $e($c['name']) . ' | شناسه ملی: ' . $e($c['national_id']) . ' | کد اقتصادی: ' . $e($c['economic_code'])
        . ($c['address'] ? ' | ' . $e($c['address']) : '') . ($c['phone'] ? ' | تلفن: ' . $e($c['phone']) : '') . '</p>'
        . '<p>طرف حساب: ' . $e($p['name'] ?? '') . ' | شناسه: ' . $e($p['national_id'] ?? '') . ' | ' . $e($p['address'] ?? '') . '</p>'
        . '<p class="muted">تاریخ: ' . $e($inv['date']) . ' | شماره عطف: ' . $e($inv['atf']) . '</p>'
        . '<table><thead><tr><th>#</th><th>کد</th><th>کالا</th><th>تعداد</th><th>فی (ریال)</th><th>جمع (ریال)</th></tr></thead><tbody>' . $rows . '</tbody>'
        . '<tfoot><tr><td colspan="5">جمع</td><td>' . $n($inv['subtotal']) . '</td></tr><tr><td colspan="5">تخفیف</td><td>' . $n($inv['discount']) . '</td></tr>'
        . (($inv['freight'] + $inv['customs'] + $inv['other_cost']) > 0 ? '<tr><td colspan="5">حمل، گمرک و سایر</td><td>' . $n($inv['freight'] + $inv['customs'] + $inv['other_cost']) . '</td></tr>' : '')
        . '<tr><td colspan="5">مالیات بر ارزش افزوده</td><td>' . $n($inv['tax']) . '</td></tr><tr class="tot"><td colspan="5">مبلغ کل</td><td>' . $n($inv['total']) . '</td></tr></tfoot></table>'
        . ($inv['due_date'] ? '<p>سررسید پرداخت: ' . $e($inv['due_date']) . '</p>' : '') . ($inv['note'] ? '<p>' . nl2br($e($inv['note'])) . '</p>' : '')
        . ($c['invoice_footer'] ? '<p class="muted">' . nl2br($e($c['invoice_footer'])) . '</p>' : '')
        . '<p><button data-print>چاپ</button></p>' . $printJs . '</body></html>';
    return null;
}

/* ------------------------------------------------------------------ */
/* journals and chart of accounts                                       */
/* ------------------------------------------------------------------ */

function r_journals()
{
    $lines = [];
    foreach (acc_all('SELECT l.*, a.code, a.name AS account_name, p.name AS person_name FROM acc_journal_lines l
        LEFT JOIN acc_coa a ON a.id = l.account_id LEFT JOIN acc_persons p ON p.id = l.person_id ORDER BY l.id') as $l) {
        $lines[$l['journal_id']][] = ['account_id' => (int)$l['account_id'], 'account_code' => $l['code'], 'account_name' => $l['account_name'],
            'person_name' => $l['person_name'], 'description' => $l['description'], 'debit' => (float)$l['debit'], 'credit' => (float)$l['credit']];
    }
    return array_map(fn($j) => ['id' => (int)$j['id'], 'number' => $j['number'], 'date' => $j['date'], 'description' => $j['description'],
        'debit' => (float)$j['debit'], 'credit' => (float)$j['credit'], 'kind' => $j['kind'], 'status' => $j['status'],
        'source_type' => $j['source_type'], 'lines' => $lines[$j['id']] ?? []], acc_all('SELECT * FROM acc_journals ORDER BY id DESC'));
}

function r_journal_create($u)
{
    acc_require_open();
    $b = acc_body();
    $lines = [];
    foreach ((array)($b['lines'] ?? []) as $l) {
        $aid = (int)($l['account_id'] ?? 0);
        $acc = acc_row('SELECT * FROM acc_coa WHERE id = ?', [$aid]);
        if (!$acc) {
            throw new AccError('حساب ردیف سند را انتخاب کن');
        }
        if ($acc['level'] === 'kol') {
            throw new AccError('روی حساب کل («' . $acc['name'] . '») سند زده نمی‌شود؛ حساب معین را انتخاب کن');
        }
        $pid = !empty($l['person_id']) ? (int)$l['person_id'] : null;
        $cash = !empty($l['cash_account_id']) ? (int)$l['cash_account_id'] : null;
        $dr = acc_num($l['debit'] ?? 0);
        $cr = acc_num($l['credit'] ?? 0);
        if ($dr < 0 || $cr < 0 || ($dr > 0 && $cr > 0)) {
            throw new AccError('هر ردیف یا بدهکار است یا بستانکار، و مبلغ منفی نمی‌شود');
        }
        // the subsidiary balances must follow the books
        if ($acc['code'] === '1101' && !acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [(int)$cash])) {
            throw new AccError('برای ردیف «صندوق و بانک»، صندوق/بانک را انتخاب کن');
        }
        if (in_array($acc['code'], ['1102', '2101'], true) && !$pid) {
            throw new AccError('برای ردیف حساب‌های دریافتنی/پرداختنی، طرف حساب را انتخاب کن');
        }
        if ($acc['code'] !== '1101') {
            $cash = null;
        }
        $lines[] = ['code' => null, 'account_id' => $aid, 'debit' => acc_num($l['debit'] ?? 0), 'credit' => acc_num($l['credit'] ?? 0),
            'desc' => (string)($l['description'] ?? ''), 'person' => $pid, 'cash' => $cash];
    }
    if (!$lines) {
        throw new AccError('سند باید ردیف بدهکار و بستانکار داشته باشد');
    }
    $d = round(array_sum(array_column($lines, 'debit')), 2);
    if ($d <= 0) {
        throw new AccError('مبلغ سند باید بزرگتر از صفر باشد');
    }
    if ($d !== round(array_sum(array_column($lines, 'credit')), 2)) {
        throw new AccError('بدهکار و بستانکار باید برابر باشند');
    }
    $jid = acc_post((string)($b['date'] ?? '') ?: acc_today(), trim((string)($b['description'] ?? '')) ?: 'سند دستی', $lines,
        ($b['kind'] ?? 'manual') === 'opening' ? 'opening' : 'manual');
    $num = acc_val('SELECT number FROM acc_journals WHERE id = ?', [$jid]);
    acc_log($u['username'], 'create_journal', $num);
    return ['id' => $jid, 'number' => $num];
}

function r_journal_void($u, $id)
{
    acc_require_open();
    $j = acc_row('SELECT * FROM acc_journals WHERE id = ?', [$id]);
    if (!$j) {
        throw new AccError('سند یافت نشد', 404);
    }
    if ($j['source_type'] && $j['source_type'] !== 'manual' && !in_array($j['kind'], ['manual'], true)) {
        throw new AccError('این سند خودکار است؛ از خود فاکتور / عملیات آن را اصلاح یا حذف کن');
    }
    $rev = acc_void_journal($id);
    acc_log($u['username'], 'void_journal', $j['number']);
    return ['ok' => true, 'reversal' => acc_val('SELECT number FROM acc_journals WHERE id = ?', [$rev])];
}

function r_opening()
{
    // Opening balances are recorded automatically when a person, a cash/bank
    // account or a product is defined with a balance (kind = opening).
    $n = (int)acc_val("SELECT COUNT(*) FROM acc_journals WHERE kind = 'opening' AND status = 'final'");
    $sum = (float)acc_val("SELECT COALESCE(SUM(debit), 0) FROM acc_journals WHERE kind = 'opening' AND status = 'final'");
    if (!$n) {
        throw new AccError('هنوز مانده‌ی اولی ثبت نشده. هنگام تعریف صندوق/بانک، کالا یا شخص، مانده‌ی اول را وارد کن؛ سند افتتاحیه خودکار زده می‌شود.');
    }
    return ['id' => null, 'number' => "$n سند افتتاحیه", 'debit' => $sum, 'credit' => $sum];
}

function r_closing($u)
{
    acc_require_open();
    $fy = acc_row('SELECT * FROM acc_fiscal ORDER BY id LIMIT 1');
    if (acc_val("SELECT 1 FROM acc_journals WHERE kind = 'closing' AND status = 'final' AND description LIKE ?", ['%' . $fy['name'] . '%'])) {
        throw new AccError('سند اختتامیه این سال قبلاً ثبت شده');
    }
    // close every income/expense account into accumulated profit (3102)
    $lines = [];
    $profit = 0;
    // only this year's entries: anything dated in the next year stays for that year
    foreach (acc_all("SELECT a.id, a.code, SUM(l.debit) d, SUM(l.credit) c FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id
        JOIN acc_journals j ON j.id = l.journal_id WHERE (a.code LIKE '4%' OR a.code LIKE '5%') AND j.date <= ? GROUP BY a.id", [$fy['name'] . '/12/31']) as $r) {
        $bal = (float)$r['d'] - (float)$r['c'];
        if (abs($bal) < 0.01) {
            continue;
        }
        $lines[] = ['code' => null, 'account_id' => (int)$r['id'], 'debit' => max(-$bal, 0), 'credit' => max($bal, 0), 'desc' => 'بستن حساب', 'person' => null, 'cash' => null];
        $profit -= $bal;
    }
    if (!$lines) {
        throw new AccError('حساب درآمد و هزینه‌ای برای بستن نیست');
    }
    $lines[] = acc_line('3102', max(-$profit, 0), max($profit, 0), $profit >= 0 ? 'سود دوره' : 'زیان دوره');
    $jid = acc_post(sprintf('%s/12/29', $fy['name']), 'سند اختتامیه سال ' . $fy['name'] . ' (بستن درآمد و هزینه)', $lines, 'closing');
    acc_log($u['username'], 'closing_journal', (string)$jid);
    return ['id' => $jid, 'number' => acc_val('SELECT number FROM acc_journals WHERE id = ?', [$jid]), 'profit' => $profit];
}

function r_coa()
{
    return array_map(fn($a) => ['id' => (int)$a['id'], 'code' => $a['code'], 'name' => $a['name'], 'level' => $a['level'],
        'nature' => $a['nature'], 'parent_code' => $a['parent_code']], acc_all('SELECT * FROM acc_coa ORDER BY code'));
}

function r_coa_create($u)
{
    $b = acc_body();
    $code = trim((string)($b['code'] ?? ''));
    $name = trim((string)($b['name'] ?? ''));
    if (!preg_match('/^\d{1,8}$/', $code) || $name === '') {
        throw new AccError('کد عددی و نام حساب لازم است');
    }
    if (acc_val('SELECT 1 FROM acc_coa WHERE code = ?', [$code])) {
        throw new AccError('این کد حساب تکراری است');
    }
    $id = acc_insert('acc_coa', ['code' => $code, 'name' => $name, 'level' => ($b['level'] ?? '') === 'kol' ? 'kol' : 'moein',
        'nature' => ($b['nature'] ?? '') === 'credit' ? 'credit' : 'debit', 'parent_code' => (string)($b['parent_code'] ?? '')]);
    acc_log($u['username'], 'create_coa', $code);
    return ['id' => $id];
}

function r_fiscal()
{
    $f = acc_row('SELECT * FROM acc_fiscal ORDER BY id LIMIT 1');
    return ['id' => (int)$f['id'], 'name' => $f['name'], 'locked' => (bool)$f['locked']];
}

function r_fiscal_set($u, $locked)
{
    acc_q('UPDATE acc_fiscal SET locked = ?', [$locked]);
    acc_log($u['username'], $locked ? 'lock_period' : 'unlock_period');
    return ['ok' => true, 'locked' => (bool)$locked];
}

function r_trial_balance()
{
    $rows = [];
    foreach (acc_all("SELECT a.code, a.name, COALESCE(SUM(l.debit), 0) d, COALESCE(SUM(l.credit), 0) c FROM acc_coa a
        LEFT JOIN acc_journal_lines l ON l.account_id = a.id WHERE a.level = 'moein' GROUP BY a.id ORDER BY a.code") as $r) {
        $rows[] = ['code' => $r['code'], 'name' => $r['name'], 'debit' => (float)$r['d'], 'credit' => (float)$r['c'], 'balance' => (float)$r['d'] - (float)$r['c']];
    }
    return ['rows' => $rows, 'total_debit' => array_sum(array_column($rows, 'debit')), 'total_credit' => array_sum(array_column($rows, 'credit')), 'locked' => acc_locked()];
}

/* ------------------------------------------------------------------ */
/* treasury: cash/bank accounts, receipts/payments, cheques             */
/* ------------------------------------------------------------------ */

const ACC_CASH_KINDS = ['cash' => 'صندوق', 'bank' => 'بانک', 'pos' => 'کارتخوان', 'petty' => 'تنخواه'];

function acc_account_out(array $a)
{
    return ['id' => (int)$a['id'], 'name' => $a['name'], 'kind' => $a['kind'], 'kind_label' => ACC_CASH_KINDS[$a['kind']] ?? $a['kind'],
        'account_no' => $a['account_no'], 'balance' => (float)$a['balance']];
}

function r_accounts()
{
    acc_bank_ensure_accounts();   // every bank card shows up with its own account
    return array_map('acc_account_out', acc_all('SELECT * FROM acc_cash_accounts ORDER BY id'));
}

function r_account_create($u)
{
    $b = acc_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام حساب را بنویس');
    }
    $kind = isset(ACC_CASH_KINDS[$b['kind'] ?? '']) ? $b['kind'] : 'cash';
    $id = acc_tx(function () use ($name, $kind, $b) {
        $id = acc_insert('acc_cash_accounts', ['name' => $name, 'kind' => $kind, 'account_no' => (string)($b['account_no'] ?? ''), 'balance' => 0]);
        $opening = acc_num($b['balance'] ?? 0);
        if ($opening) {
            acc_post(acc_today(), 'مانده اول ' . $name, [acc_cash_line($id, $opening, 'افتتاحیه'),
                acc_line('3101', max(-$opening, 0), max($opening, 0), 'افتتاحیه')], 'opening', 'cash_account', $id);
        }
        return $id;
    });
    acc_log($u['username'], 'create_account', $name);
    return acc_account_out(acc_row('SELECT * FROM acc_cash_accounts WHERE id = ?', [$id]));
}

function r_treasury()
{
    $kinds = ['receive' => 'دریافت', 'pay' => 'پرداخت', 'transfer' => 'انتقال'];
    return array_map(fn($t) => ['id' => (int)$t['id'], 'number' => $t['number'], 'date' => $t['date'], 'kind' => $t['kind'],
        'kind_label' => $kinds[$t['kind']] ?? $t['kind'], 'account_name' => $t['account_name'] ?? '-', 'to_account_name' => $t['to_name'] ?? '',
        'person_name' => $t['person_name'] ?? '-', 'amount' => (float)$t['amount'], 'description' => $t['description']],
        acc_all('SELECT t.*, a.name account_name, b.name to_name, p.name person_name FROM acc_treasury t
            LEFT JOIN acc_cash_accounts a ON a.id = t.account_id LEFT JOIN acc_cash_accounts b ON b.id = t.to_account_id
            LEFT JOIN acc_persons p ON p.id = t.person_id ORDER BY t.id DESC'));
}

function r_treasury_create($u)
{
    $t = acc_treasury_record(acc_body());
    acc_log($u['username'], 'treasury', $t['number']);
    acc_webhook('create', 'Treasury', [$t['id']], ['number' => $t['number'], 'kind' => $t['kind'], 'amount' => $t['amount']]);
    return ['id' => $t['id'], 'number' => $t['number']];
}

const ACC_CHEQUE_STATUS = ['in_hand' => 'نزد صندوق', 'deposited' => 'واگذار شده', 'collected' => 'وصول شده', 'returned' => 'برگشتی',
    'spent' => 'خرج شده', 'issued' => 'صادر شده', 'paid' => 'پرداخت شده'];

function acc_cheque_out(array $c)
{
    return ['id' => (int)$c['id'], 'number' => $c['number'], 'direction' => $c['direction'], 'person_id' => (int)$c['person_id'],
        'person_name' => $c['person_name'] ?? (acc_val('SELECT name FROM acc_persons WHERE id = ?', [$c['person_id']]) ?: '-'),
        'account_id' => $c['account_id'] ? (int)$c['account_id'] : null, 'amount' => (float)$c['amount'], 'due_date' => $c['due_date'],
        'bank_name' => $c['bank_name'], 'status' => $c['status'], 'status_label' => ACC_CHEQUE_STATUS[$c['status']] ?? $c['status'],
        'description' => $c['description']];
}

function r_cheques()
{
    $dir = $_GET['direction'] ?? '';
    return array_map('acc_cheque_out', acc_all('SELECT c.*, p.name person_name FROM acc_cheques c LEFT JOIN acc_persons p ON p.id = c.person_id'
        . ($dir !== '' ? ' WHERE c.direction = ?' : '') . ' ORDER BY c.id DESC', $dir !== '' ? [$dir] : []));
}

function r_cheque_create($u)
{
    $c = acc_cheque_record(acc_body());
    acc_log($u['username'], 'create_cheque', $c['number']);
    acc_webhook('create', 'Cheque', [(int)$c['id']], ['number' => $c['number'], 'amount' => (float)$c['amount'], 'direction' => $c['direction']]);
    return acc_cheque_out($c);
}

function r_cheque_action($u, $id)
{
    $b = acc_body();
    $c = acc_cheque_act($id, (string)($b['action'] ?? ''), $b);
    acc_log($u['username'], 'cheque_action', $c['number'] . ':' . ($b['action'] ?? ''));
    acc_webhook('update', 'Cheque', [$id], ['number' => $c['number'], 'status' => $c['status'], 'action' => $b['action'] ?? '']);
    return acc_cheque_out($c);
}

function r_statements()
{
    $aid = (int)($_GET['account_id'] ?? 0);
    return array_map(fn($s) => ['id' => (int)$s['id'], 'account_id' => (int)$s['account_id'], 'date' => $s['date'], 'amount' => (float)$s['amount'],
        'description' => $s['description'], 'matched' => (bool)$s['matched'], 'txn_id' => $s['txn_id'] ? (int)$s['txn_id'] : null],
        acc_all('SELECT * FROM acc_bank_statements' . ($aid ? ' WHERE account_id = ?' : '') . ' ORDER BY id DESC', $aid ? [$aid] : []));
}

function r_statement_create()
{
    $b = acc_body();
    if (!acc_val('SELECT 1 FROM acc_cash_accounts WHERE id = ?', [(int)($b['account_id'] ?? 0)])) {
        throw new AccError('حساب را انتخاب کن');
    }
    return ['id' => acc_insert('acc_bank_statements', ['account_id' => (int)$b['account_id'], 'date' => (string)($b['date'] ?? ''),
        'amount' => acc_num($b['amount'] ?? 0), 'description' => (string)($b['description'] ?? '')])];
}

function r_statement_match($u, $id)
{
    $s = acc_row('SELECT * FROM acc_bank_statements WHERE id = ?', [$id]);
    if (!$s) {
        throw new AccError('ردیف صورت‌حساب یافت نشد', 404);
    }
    $txn = isset($_GET['txn_id']) ? (int)$_GET['txn_id'] : null;
    if ($txn === null) {
        // same account and amount (receipts positive, payments negative), not matched before
        $kind = (float)$s['amount'] >= 0 ? 'receive' : 'pay';
        $txn = acc_val('SELECT id FROM acc_treasury WHERE account_id = ? AND amount = ? AND kind = ?
            AND id NOT IN (SELECT txn_id FROM acc_bank_statements WHERE txn_id IS NOT NULL) ORDER BY id LIMIT 1',
            [$s['account_id'], abs((float)$s['amount']), $kind]) ?: null;
        if (!$txn) {
            $txn = acc_val('SELECT id FROM acc_treasury WHERE account_id = ? AND amount = ? ORDER BY id LIMIT 1', [$s['account_id'], abs((float)$s['amount'])]) ?: null;
        }
    }
    acc_update('acc_bank_statements', $id, ['matched' => $txn ? 1 : 0, 'txn_id' => $txn]);
    return ['ok' => true, 'txn_id' => $txn ? (int)$txn : null];
}

/* ------------------------------------------------------------------ */
/* warehouses                                                           */
/* ------------------------------------------------------------------ */

function r_warehouses()
{
    return array_map(fn($w) => ['id' => (int)$w['id'], 'name' => $w['name'], 'is_default' => (bool)$w['is_default']], acc_all('SELECT * FROM acc_warehouses ORDER BY id'));
}

function r_warehouse_create($u)
{
    $b = acc_body();
    $name = trim((string)($b['name'] ?? ''));
    if ($name === '') {
        throw new AccError('نام انبار را بنویس');
    }
    if (!empty($b['is_default'])) {
        acc_q('UPDATE acc_warehouses SET is_default = 0');
    }
    $id = acc_insert('acc_warehouses', ['name' => $name, 'is_default' => !empty($b['is_default']) ? 1 : 0]);
    return ['id' => $id, 'name' => $name, 'is_default' => !empty($b['is_default'])];
}

function r_stock()
{
    $wid = (int)($_GET['warehouse_id'] ?? 0);
    return array_map(fn($s) => ['warehouse_id' => (int)$s['warehouse_id'], 'warehouse_name' => $s['wname'] ?? '-', 'product_id' => (int)$s['product_id'],
        'product_name' => $s['pname'] ?? '-', 'code' => $s['code'] ?? '', 'qty' => (float)$s['qty'], 'reorder_point' => (float)$s['reorder_point']],
        acc_all('SELECT s.*, w.name wname, p.name pname, p.code, p.reorder_point FROM acc_stock s LEFT JOIN acc_warehouses w ON w.id = s.warehouse_id
            LEFT JOIN acc_products p ON p.id = s.product_id' . ($wid ? ' WHERE s.warehouse_id = ?' : '') . ' ORDER BY w.id, p.name', $wid ? [$wid] : []));
}

function r_wh_docs()
{
    $labels = ['receipt' => 'رسید', 'issue' => 'حواله', 'transfer' => 'انتقال'];
    return array_map(fn($d) => ['id' => (int)$d['id'], 'number' => $d['number'], 'date' => $d['date'], 'kind' => $d['kind'],
        'kind_label' => $labels[$d['kind']] ?? $d['kind'], 'warehouse_name' => $d['wname'] ?? '-', 'to_warehouse_name' => $d['tname'] ?? '',
        'description' => $d['description'], 'items_count' => (int)$d['n']],
        acc_all('SELECT d.*, w.name wname, t.name tname, (SELECT COUNT(*) FROM acc_wh_doc_items i WHERE i.doc_id = d.id) n FROM acc_wh_docs d
            LEFT JOIN acc_warehouses w ON w.id = d.warehouse_id LEFT JOIN acc_warehouses t ON t.id = d.to_warehouse_id ORDER BY d.id DESC'));
}

function r_wh_doc_create($u)
{
    $d = acc_wh_doc_record(acc_body());
    acc_log($u['username'], 'warehouse_doc', $d['number']);
    return ['id' => $d['id'], 'number' => $d['number']];
}

function r_kardex($u, $pid)
{
    $p = acc_product($pid);
    if (!$p) {
        throw new AccError('کالا یافت نشد', 404);
    }
    if (!acc_can($u, 'warehouse') && !acc_can($u, 'products')) {
        throw new AccError('دسترسی ندارید', 403);
    }
    $wh = (int)($_GET['warehouse_id'] ?? 0);
    $bal = 0;
    $rows = [];
    foreach (acc_all('SELECT m.*, w.name wname FROM acc_stock_moves m LEFT JOIN acc_warehouses w ON w.id = m.warehouse_id WHERE product_id = ?'
        . ($wh ? ' AND warehouse_id = ?' : '') . ' ORDER BY m.id', $wh ? [$pid, $wh] : [$pid]) as $m) {
        $q = (float)$m['qty'];
        $bal += $q;
        $rows[] = ['date' => $m['date'], 'doc' => $m['doc_number'], 'kind' => $m['kind'], 'warehouse' => $m['wname'],
            'qty_in' => max($q, 0), 'qty_out' => max(-$q, 0), 'unit_cost' => (float)$m['unit_cost'], 'balance' => $bal];
    }
    return ['product' => $p['name'], 'rows' => $rows, 'stock' => (float)$p['stock']];
}

function r_serials()
{
    $w = [];
    $a = [];
    if (!empty($_GET['product_id'])) {
        $w[] = 's.product_id = ?';
        $a[] = (int)$_GET['product_id'];
    }
    if (!empty($_GET['status'])) {
        $w[] = 's.status = ?';
        $a[] = $_GET['status'];
    }
    return array_map(fn($s) => ['id' => (int)$s['id'], 'product_id' => (int)$s['product_id'], 'product_name' => $s['pname'] ?? '-',
        'warehouse_name' => $s['wname'] ?? '-', 'serial' => $s['serial'], 'lot' => $s['lot'], 'expiry' => $s['expiry'], 'status' => $s['status']],
        acc_all('SELECT s.*, p.name pname, w.name wname FROM acc_serials s LEFT JOIN acc_products p ON p.id = s.product_id
            LEFT JOIN acc_warehouses w ON w.id = s.warehouse_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY s.id DESC', $a));
}

function r_serial_create()
{
    acc_require_open();
    $b = acc_body();
    $serial = trim((string)($b['serial'] ?? ''));
    if ($serial === '' || !acc_product((int)($b['product_id'] ?? 0))) {
        throw new AccError('کالا و شماره سریال لازم است');
    }
    if (acc_val("SELECT 1 FROM acc_serials WHERE serial = ? AND status = 'in'", [$serial])) {
        throw new AccError('این سریال هم‌اکنون در انبار موجود است');
    }
    return ['id' => acc_insert('acc_serials', ['product_id' => (int)$b['product_id'], 'warehouse_id' => !empty($b['warehouse_id']) ? (int)$b['warehouse_id'] : null,
        'serial' => $serial, 'lot' => (string)($b['lot'] ?? ''), 'expiry' => (string)($b['expiry'] ?? ''), 'status' => 'in'])];
}

function r_serial_out($u, $id)
{
    acc_require_open();
    if (!acc_val('SELECT 1 FROM acc_serials WHERE id = ?', [$id])) {
        throw new AccError('سریال یافت نشد', 404);
    }
    acc_q("UPDATE acc_serials SET status = 'out' WHERE id = ?", [$id]);
    return ['ok' => true];
}

function r_counts()
{
    return array_map(fn($c) => ['id' => (int)$c['id'], 'number' => $c['number'], 'date' => $c['date'], 'status' => $c['status'],
        'warehouse_name' => $c['wname'] ?? '-', 'items' => (int)$c['n'], 'shortage' => (float)$c['sh'], 'overage' => (float)$c['ov'], 'note' => $c['note']],
        acc_all('SELECT c.*, w.name wname, (SELECT COUNT(*) FROM acc_stock_count_items i WHERE i.count_id = c.id) n,
            (SELECT COALESCE(SUM(diff), 0) FROM acc_stock_count_items i WHERE i.count_id = c.id AND diff < 0) sh,
            (SELECT COALESCE(SUM(diff), 0) FROM acc_stock_count_items i WHERE i.count_id = c.id AND diff > 0) ov
            FROM acc_stock_counts c LEFT JOIN acc_warehouses w ON w.id = c.warehouse_id ORDER BY c.id DESC'));
}

function r_count_create($u)
{
    $c = acc_stock_count_record(acc_body());
    acc_log($u['username'], 'stock_count', $c['number']);
    return ['id' => $c['id'], 'number' => $c['number']];
}

/* ------------------------------------------------------------------ */
/* tax (سامانه مؤدیان)                                                  */
/* ------------------------------------------------------------------ */

function acc_tax_out(array $t)
{
    $labels = ['ready' => 'آماده ارسال', 'sent' => 'ارسال شد (در انتظار نتیجه)', 'accepted' => 'پذیرفته شد', 'rejected' => 'رد شد', 'failed' => 'ارسال نشد',
        'cancel_sent' => 'ابطال ارسال شد', 'cancelled' => 'باطل شد', 'draft' => 'پیش‌نویس'];
    return ['id' => (int)$t['id'], 'invoice_id' => (int)$t['invoice_id'], 'taxid' => $t['taxid'], 'kind' => $t['kind'], 'buyer_name' => $t['buyer_name'],
        'buyer_id' => $t['buyer_id'], 'seller_id' => $t['seller_id'], 'date' => $t['date'], 'pre_tax' => (float)$t['pre_tax'], 'vat' => (float)$t['vat'],
        'total' => (float)$t['total'], 'status' => $t['status'], 'status_label' => $labels[$t['status']] ?? $t['status'], 'payload' => $t['payload'], 'response' => $t['response'], 'reference' => $t['reference'] ?? ''];
}

function r_tax_list()
{
    $kind = $_GET['kind'] ?? '';
    return array_map('acc_tax_out', acc_all('SELECT * FROM acc_tax_invoices' . ($kind !== '' ? ' WHERE kind = ?' : '') . ' ORDER BY id DESC', $kind !== '' ? [$kind] : []));
}

function acc_tax_get($id)
{
    $t = acc_row('SELECT * FROM acc_tax_invoices WHERE id = ?', [$id]);
    if (!$t) {
        throw new AccError('صورتحساب مالیاتی یافت نشد', 404);
    }
    return $t;
}

function r_tax_get($u, $id)
{
    return acc_tax_out(acc_tax_get($id));
}

function r_tax_report()
{
    $rows = acc_all('SELECT * FROM acc_tax_invoices');
    $sale = array_filter($rows, fn($r) => $r['kind'] === 'sale');
    $buy = array_filter($rows, fn($r) => $r['kind'] === 'purchase');
    $vs = array_sum(array_column($sale, 'vat'));
    $vb = array_sum(array_column($buy, 'vat'));
    return ['sale_count' => count($sale), 'purchase_count' => count($buy), 'vat_sale' => (float)$vs, 'vat_purchase' => (float)$vb,
        'vat_payable' => (float)($vs - $vb), 'missing_buyer_id' => count(array_filter($sale, fn($r) => !$r['buyer_id'])),
        'sent' => count(array_filter($rows, fn($r) => in_array($r['status'], ['sent', 'accepted'], true))),
        'ready' => count(array_filter($rows, fn($r) => $r['status'] === 'ready')), 'failed' => count(array_filter($rows, fn($r) => $r['status'] === 'failed'))];
}

/* ------------------------------------------------------------------ */
/* reports                                                              */
/* ------------------------------------------------------------------ */

function r_daybook()
{
    return array_map(fn($j) => ['number' => $j['number'], 'date' => $j['date'], 'description' => $j['description'], 'debit' => (float)$j['debit'],
        'credit' => (float)$j['credit'], 'status' => $j['status']], acc_all('SELECT * FROM acc_journals ORDER BY id'));
}

/** Balance (debit - credit) of every account code prefix. */
function acc_balance_of($prefix)
{
    return (float)acc_val('SELECT COALESCE(SUM(l.debit - l.credit), 0) FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id WHERE a.code LIKE ?', [$prefix . '%']);
}

/** [receivables, payables, unassigned] from the person control accounts (1102/2101). */
function acc_people_split()
{
    $recv = $pay = $unassigned = 0;
    foreach (acc_all("SELECT l.person_id, SUM(l.debit - l.credit) b FROM acc_journal_lines l JOIN acc_coa a ON a.id = l.account_id
        WHERE a.code IN ('1102', '2101') GROUP BY l.person_id") as $r) {
        if ($r['person_id'] === null) {
            $unassigned += (float)$r['b'];
        } elseif ($r['b'] > 0) {
            $recv += (float)$r['b'];
        } else {
            $pay -= (float)$r['b'];
        }
    }
    return [$recv, $pay, $unassigned];
}

function r_balance_sheet()
{
    // people: from the lines on the two control accounts, grouped by person, so
    // what they owe is an asset and what we owe them a liability
    [$recv, $pay, $unassigned] = acc_people_split();
    $cash = acc_balance_of('1101');
    $inventory = acc_balance_of('1103');
    $cheques_in = acc_balance_of('1104');
    $vat_in = acc_balance_of('1105');
    $assets_other = acc_balance_of('1') - $cash - acc_balance_of('1102') - $inventory - $cheques_in - $vat_in + max($unassigned, 0);
    $assets_total = $cash + $recv + $cheques_in + $inventory + $vat_in + $assets_other;
    $cheques_out = -acc_balance_of('2102');
    $vat_out = -acc_balance_of('2103');
    $liab_other = -acc_balance_of('2') + acc_balance_of('2101') - $cheques_out - $vat_out + max(-$unassigned, 0);
    $liab_total = $pay + $cheques_out + $vat_out + $liab_other;
    $capital = -acc_balance_of('3');
    $profit = -acc_balance_of('4') - acc_balance_of('5');
    $equity = $capital + $profit;
    return [
        'assets' => ['cash' => $cash, 'receivables' => $recv, 'cheques' => $cheques_in, 'inventory' => $inventory, 'vat_credit' => $vat_in,
            'other' => $assets_other, 'total' => $assets_total],
        'liabilities' => ['payables' => $pay, 'cheques' => $cheques_out, 'vat' => $vat_out, 'other' => $liab_other, 'total' => $liab_total],
        'equity' => ['capital' => $capital, 'profit' => $profit, 'capital_and_profit' => $equity, 'total' => $equity],
        'balanced' => abs($assets_total - ($liab_total + $equity)) < 1,
    ];
}

/** Profit and loss of a date range; by default the current fiscal year. */
function r_profit_loss()
{
    $fy = (string)acc_val('SELECT name FROM acc_fiscal ORDER BY id LIMIT 1');
    $from = acc_jalali_parse($_GET['from'] ?? '') ? acc_jalali_fmt(...acc_jalali_parse($_GET['from'])) : $fy . '/01/01';
    $to = acc_jalali_parse($_GET['to'] ?? '') ? acc_jalali_fmt(...acc_jalali_parse($_GET['to'])) : $fy . '/12/31';
    $rows = acc_all("SELECT a.code, a.name, COALESCE(SUM(l.credit - l.debit), 0) amt FROM acc_coa a JOIN acc_journal_lines l ON l.account_id = a.id
        JOIN acc_journals j ON j.id = l.journal_id WHERE (a.code LIKE '4%' OR a.code LIKE '5%') AND j.kind != 'closing' AND j.date BETWEEN ? AND ?
        GROUP BY a.id ORDER BY a.code", [$from, $to]);
    $income = array_sum(array_map(fn($r) => $r['code'][0] === '4' ? (float)$r['amt'] : 0, $rows));
    $expense = -array_sum(array_map(fn($r) => $r['code'][0] === '5' ? (float)$r['amt'] : 0, $rows));
    return ['rows' => array_map(fn($r) => ['code' => $r['code'], 'name' => $r['name'], 'amount' => (float)$r['amt']], $rows),
        'income' => $income, 'expense' => $expense, 'profit' => $income - $expense, 'from' => $from, 'to' => $to];
}

function r_ledger($u, $account_id)
{
    $a = acc_row('SELECT * FROM acc_coa WHERE id = ?', [$account_id]);
    if (!$a) {
        throw new AccError('حساب یافت نشد', 404);
    }
    $bal = 0;
    $rows = [];
    foreach (acc_all('SELECT j.number, j.date, j.description jd, l.description, l.debit, l.credit FROM acc_journal_lines l JOIN acc_journals j ON j.id = l.journal_id
        JOIN acc_coa a ON a.id = l.account_id WHERE a.code LIKE ? ORDER BY j.date, j.id, l.id', [$a['code'] . '%']) as $r) {
        $bal += $r['debit'] - $r['credit'];
        $rows[] = ['number' => $r['number'], 'date' => $r['date'], 'description' => trim($r['jd'] . ' ' . $r['description']),
            'debit' => (float)$r['debit'], 'credit' => (float)$r['credit'], 'balance' => $bal];
    }
    return ['account' => $a['code'] . ' ' . $a['name'], 'rows' => $rows, 'balance' => $bal];
}

function r_person_statement($u, $pid)
{
    $p = acc_row('SELECT * FROM acc_persons WHERE id = ?', [$pid]);
    if (!$p) {
        throw new AccError('شخص یافت نشد', 404);
    }
    $bal = 0;
    $rows = [];
    foreach (acc_all('SELECT j.number, j.date, j.description jd, l.description, l.debit, l.credit FROM acc_journal_lines l
        JOIN acc_journals j ON j.id = l.journal_id WHERE l.person_id = ? ORDER BY j.date, j.id, l.id', [$pid]) as $r) {
        $bal += $r['debit'] - $r['credit'];
        $rows[] = ['number' => $r['number'], 'date' => $r['date'], 'description' => $r['jd'], 'debit' => (float)$r['debit'],
            'credit' => (float)$r['credit'], 'balance' => $bal];
    }
    return ['person' => acc_person_out($p), 'rows' => $rows, 'balance' => $bal];
}

/** Money in and out of cash/bank accounts (receipts, payments, cheques cleared, loans...); transfers between them apart. */
function r_cashflow()
{
    $in = $out = $tr = 0;
    foreach (acc_all("SELECT j.id, SUM(CASE WHEN a.code = '1101' THEN l.debit - l.credit ELSE 0 END) net,
        SUM(CASE WHEN a.code != '1101' THEN 1 ELSE 0 END) others, SUM(CASE WHEN a.code = '1101' THEN l.debit ELSE 0 END) moved
        FROM acc_journals j JOIN acc_journal_lines l ON l.journal_id = j.id JOIN acc_coa a ON a.id = l.account_id
        WHERE j.kind != 'opening' GROUP BY j.id") as $r) {
        if ((int)$r['others'] === 0) {
            $tr += (float)$r['moved'];
        } elseif ($r['net'] > 0) {
            $in += (float)$r['net'];
        } else {
            $out -= (float)$r['net'];
        }
    }
    return ['operating_in' => $in, 'operating_out' => $out, 'net_operating' => $in - $out, 'transfers' => $tr,
        'cash_balance' => (float)acc_val('SELECT COALESCE(SUM(balance), 0) FROM acc_cash_accounts'),
        'rows' => array_map(fn($t) => ['kind' => $t['kind'], 'number' => $t['number'], 'date' => $t['date'], 'amount' => (float)$t['amount'], 'description' => $t['description']],
            acc_all('SELECT * FROM acc_treasury ORDER BY id DESC LIMIT 50'))];
}

function r_dues()
{
    $ch = fn($dir, $st) => array_map(fn($c) => ['number' => $c['number'], 'amount' => (float)$c['amount'], 'due_date' => $c['due_date'], 'person_name' => $c['pn']],
        acc_all('SELECT c.*, p.name pn FROM acc_cheques c LEFT JOIN acc_persons p ON p.id = c.person_id WHERE direction = ? AND status IN (' . $st . ') ORDER BY due_date', [$dir]));
    return ['receivable_cheques' => $ch('received', "'in_hand','deposited'"), 'payable_cheques' => $ch('payable', "'issued'"),
        'customers' => array_map(fn($p) => ['name' => $p['name'], 'balance' => (float)$p['balance']], acc_all('SELECT name, balance FROM acc_persons WHERE balance > 0 ORDER BY balance DESC')),
        'suppliers' => array_map(fn($p) => ['name' => $p['name'], 'balance' => (float)$p['balance']], acc_all('SELECT name, balance FROM acc_persons WHERE balance < 0 ORDER BY balance'))];
}

function r_cogs()
{
    $rows = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => $p['name'], 'stock' => (float)$p['stock'], 'avg_cost' => (float)$p['avg_cost'],
        'last_cost' => (float)($p['last_cost'] ?: $p['buy_price']), 'stock_value' => (float)$p['stock'] * acc_unit_cost($p)], acc_all('SELECT * FROM acc_products ORDER BY id'));
    return ['rows' => $rows, 'total_value' => array_sum(array_column($rows, 'stock_value'))];
}

function r_export_csv()
{
    $what = $_GET['what'] ?? 'sales';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/\W/', '', $what) . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");   // Excel reads UTF-8 Persian with the BOM
    if ($what === 'sales' || $what === 'purchases') {
        ba_csv_put($fh, ['شماره', 'تاریخ', 'طرف حساب', 'جمع', 'تخفیف', 'مالیات', 'مبلغ کل']);
        foreach (acc_all('SELECT i.*, p.name pn FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id WHERE kind = ? ORDER BY i.id', [$what === 'sales' ? 'sale' : 'purchase']) as $i) {
            ba_csv_put($fh, [$i['number'], $i['date'], $i['pn'], $i['subtotal'], $i['discount'], $i['tax'], $i['total']]);
        }
    } elseif ($what === 'products') {
        ba_csv_put($fh, ['کد', 'نام', 'واحد', 'موجودی', 'قیمت فروش', 'میانگین بها']);
        foreach (acc_all('SELECT * FROM acc_products ORDER BY id') as $p) {
            ba_csv_put($fh, [$p['code'], $p['name'], $p['unit'], $p['stock'], $p['sale_price'], $p['avg_cost']]);
        }
    } else {
        ba_csv_put($fh, ['کد', 'نام', 'نوع', 'موبایل', 'مانده (+ بدهکار)']);
        foreach (acc_all('SELECT * FROM acc_persons ORDER BY id') as $p) {
            ba_csv_put($fh, [$p['code'], $p['name'], $p['type'], $p['mobile'], $p['balance']]);
        }
    }
    fclose($fh);
    return null;
}

function r_branch_create()
{
    $b = acc_body();
    if (trim((string)($b['name'] ?? '')) === '') {
        throw new AccError('نام شعبه را بنویس');
    }
    return ['id' => acc_insert('acc_branches', ['name' => trim($b['name']), 'city' => (string)($b['city'] ?? '')])];
}

function r_currency_save()
{
    $b = acc_body();
    $code = strtoupper(trim((string)($b['code'] ?? '')));
    if ($code === '') {
        throw new AccError('کد ارز لازم است');
    }
    $id = acc_val('SELECT id FROM acc_currencies WHERE code = ?', [$code]);
    if ($id) {
        acc_update('acc_currencies', $id, ['name' => (string)($b['name'] ?? $code), 'rate' => acc_num($b['rate'] ?? 1)]);
    } else {
        acc_insert('acc_currencies', ['code' => $code, 'name' => (string)($b['name'] ?? $code), 'rate' => acc_num($b['rate'] ?? 1)]);
    }
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* attachments                                                          */
/* ------------------------------------------------------------------ */

function acc_upload_dir()
{
    $d = BA_ROOT . '/data/acc_uploads';
    if (!is_dir($d)) {
        mkdir($d, 0770, true);
    }
    return $d;
}

function r_attach_upload($u)
{
    $f = $_FILES['file'] ?? null;
    if (!$f || !is_uploaded_file($f['tmp_name'])) {
        throw new AccError('فایلی نرسید');
    }
    if ($f['size'] > 10 * 1024 * 1024) {
        throw new AccError('حداکثر حجم فایل ۱۰ مگابایت است');
    }
    $name = basename((string)$f['name']) ?: 'file';
    $stored = bin2hex(random_bytes(12));          // never the user's name on disk
    move_uploaded_file($f['tmp_name'], acc_upload_dir() . '/' . $stored);
    $id = acc_insert('acc_attachments', ['object_type' => preg_replace('/\W/', '', (string)($_GET['object_type'] ?? 'invoice')),
        'object_id' => (int)($_GET['object_id'] ?? 0), 'filename' => $name, 'path' => $stored, 'created_at' => acc_now()]);
    acc_log($u['username'], 'upload_attachment', $name);
    return ['id' => $id, 'filename' => $name];
}

function r_attach_list()
{
    return array_map(fn($a) => ['id' => (int)$a['id'], 'filename' => $a['filename'], 'object_type' => $a['object_type'], 'object_id' => (int)$a['object_id']],
        acc_all('SELECT * FROM acc_attachments WHERE object_type = ? AND object_id = ?', [(string)($_GET['object_type'] ?? 'invoice'), (int)($_GET['object_id'] ?? 0)]));
}

function r_attach_download($u, $id)
{
    $a = acc_row('SELECT * FROM acc_attachments WHERE id = ?', [$id]);
    $path = $a ? acc_upload_dir() . '/' . basename($a['path']) : '';
    if (!$a || !is_file($path)) {
        throw new AccError('پیوست یافت نشد', 404);
    }
    header('Content-Type: application/octet-stream');
    header("Content-Disposition: attachment; filename*=UTF-8''" . rawurlencode($a['filename']));
    header('Content-Length: ' . filesize($path));
    readfile($path);
    return null;
}

function r_attach_delete($u, $id)
{
    $a = acc_row('SELECT * FROM acc_attachments WHERE id = ?', [$id]);
    if (!$a) {
        throw new AccError('پیوست یافت نشد', 404);
    }
    @unlink(acc_upload_dir() . '/' . basename($a['path']));
    acc_q('DELETE FROM acc_attachments WHERE id = ?', [$id]);
    return ['ok' => true];
}

/* ------------------------------------------------------------------ */
/* SMS panel                                                            */
/* ------------------------------------------------------------------ */

function r_sms_settings()
{
    acc_sms_schema();
    $s = acc_sms_settings();
    $s['sms_api_key_set'] = $s['sms_api_key'] !== '';
    $s['sms_api_key'] = $s['sms_api_key'] !== '' ? str_repeat('•', 8) . substr($s['sms_api_key'], -4) : '';
    return $s;
}

function r_sms_settings_save($u)
{
    $b = acc_body();
    $s = acc_sms_settings();
    foreach (ACC_SMS_KEYS as $k) {
        if (!array_key_exists($k, $b)) {
            continue;
        }
        if ($k === 'sms_api_key' && strpos((string)$b[$k], '•') !== false) {
            continue;   // the masked value came back unchanged
        }
        $s[$k] = is_bool($b[$k]) ? $b[$k] : (is_numeric($b[$k]) && $k !== 'sms_sender' && $k !== 'sms_api_key' ? $b[$k] + 0 : trim((string)$b[$k]));
    }
    if (!in_array($s['sms_provider'], ['test', 'kavenegar', 'smsir', 'custom'], true)) {
        throw new AccError('سرویس‌دهنده‌ی پیامک نامعتبر است');
    }
    if ($s['sms_provider'] === 'custom' && !preg_match('~^https?://.*\{to\}.*~', $s['sms_custom_url']) ) {
        throw new AccError('آدرس وب‌سرویس باید با http شروع شود و {to} و {text} داشته باشد');
    }
    if ($s['sms_provider'] === 'custom') {
        acc_check_public_url(str_replace(['{to}', '{text}', '{from}'], '', $s['sms_custom_url']));
    }
    ba_kv_set('acc_sms_settings', $s);
    acc_log($u['username'], 'sms_settings', $s['sms_provider']);
    return ['ok' => true];
}

function r_sms_credit()
{
    try {
        return acc_sms_credit() + ['provider' => acc_sms_settings()['sms_provider']];
    } catch (RuntimeException $e) {
        throw new AccError($e->getMessage(), 502);
    }
}

function r_sms_patterns()
{
    acc_sms_schema();
    return array_map(fn($p) => ['id' => (int)$p['id'], 'title' => $p['title'], 'code' => $p['code'], 'params' => json_decode($p['params'], true) ?: []],
        acc_all('SELECT * FROM acc_sms_patterns ORDER BY id'));
}

function r_sms_item_save($table, $id)
{
    acc_sms_schema();
    $b = acc_body();
    $title = trim((string)($b['title'] ?? ''));
    if ($title === '') {
        throw new AccError('عنوان لازم است');
    }
    if ($table === 'acc_sms_templates') {
        $f = ['title' => $title, 'body' => trim((string)($b['body'] ?? ''))];
        if ($f['body'] === '') {
            throw new AccError('متن نمونه پیامک خالی است');
        }
    } else {
        $params = is_array($b['params'] ?? null) ? $b['params'] : preg_split('/[\s,،]+/u', (string)($b['params'] ?? ''), -1, PREG_SPLIT_NO_EMPTY);
        $f = ['title' => $title, 'code' => trim((string)($b['code'] ?? '')), 'params' => json_encode(array_values(array_map('strval', $params)), JSON_UNESCAPED_UNICODE)];
        if ($f['code'] === '') {
            throw new AccError('کد/نام پترن در پنل پیامک لازم است');
        }
    }
    if ($id) {
        if (!acc_val("SELECT 1 FROM $table WHERE id = ?", [$id])) {
            throw new AccError('یافت نشد', 404);
        }
        acc_update($table, $id, $f);
        return ['ok' => true, 'id' => $id];
    }
    return ['ok' => true, 'id' => acc_insert($table, $f)];
}

function r_sms_item_delete($table, $id)
{
    acc_sms_schema();
    acc_q("DELETE FROM $table WHERE id = ?", [$id]);
    return ['ok' => true];
}

/** Text of a message: a saved template (filled for the person) or free text. */
function acc_sms_body_for(array $b, array $person = null)
{
    if (!empty($b['template_id'])) {
        $t = acc_row('SELECT * FROM acc_sms_templates WHERE id = ?', [(int)$b['template_id']]);
        if (!$t) {
            throw new AccError('نمونه پیامک یافت نشد');
        }
        return acc_sms_render($t['body'], $person);
    }
    return acc_sms_render((string)($b['text'] ?? ''), $person);
}

/** Pattern values for a person: {name} etc. in the given values are filled in. */
function acc_sms_pattern_values(array $b, array $person = null)
{
    $out = [];
    foreach ((array)($b['values'] ?? []) as $k => $v) {
        $out[$k] = acc_sms_render((string)$v, $person);
    }
    return $out;
}

function r_sms_send($u)
{
    acc_sms_schema();
    $b = acc_body();
    $person = !empty($b['person_id']) ? acc_row('SELECT * FROM acc_persons WHERE id = ?', [(int)$b['person_id']]) : null;
    $mobile = trim((string)($b['mobile'] ?? '')) ?: ($person['mobile'] ?? '');
    if (!acc_sms_mobile($mobile)) {
        throw new AccError('شماره موبایل معتبر نیست');
    }
    $msg = ['mobile' => $mobile, 'person_id' => $person['id'] ?? null];
    if (!empty($b['pattern_id'])) {
        $msg += ['pattern_id' => (int)$b['pattern_id'], 'values' => acc_sms_pattern_values($b, $person)];
    } else {
        $msg['text'] = acc_sms_body_for($b, $person);
        if (trim($msg['text']) === '') {
            throw new AccError('متن پیامک خالی است');
        }
    }
    $q = acc_sms_queue([$msg], $u['username']);
    acc_sms_process(5);
    $row = acc_row('SELECT status, error FROM acc_sms_log WHERE batch = ?', [$q['batch']]);
    if (($row['status'] ?? '') === 'failed') {
        throw new AccError('ارسال نشد: ' . $row['error'], 502);
    }
    acc_log($u['username'], 'sms_send', acc_sms_mobile($mobile));
    return ['ok' => true, 'status' => $row['status'] ?? 'queued'];
}

/** Recipients of a group send. */
function acc_sms_targets(array $b)
{
    $target = (string)($b['target'] ?? 'all');
    $min = acc_num($b['min_balance'] ?? 0);
    switch ($target) {
        case 'customers':
        case 'suppliers':
            return acc_all('SELECT * FROM acc_persons WHERE type = ?', [substr($target, 0, -1)]);
        case 'debtors':
            return acc_all('SELECT * FROM acc_persons WHERE balance > ?', [max($min, 0)]);
        case 'creditors':
            return acc_all('SELECT * FROM acc_persons WHERE balance < ?', [-max($min, 0)]);
        case 'ids':
            $ids = array_map('intval', (array)($b['ids'] ?? []));
            return $ids ? acc_all('SELECT * FROM acc_persons WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids) : [];
        case 'group':
            return acc_all("SELECT * FROM acc_persons WHERE ',' || COALESCE(groups, '') || ',' LIKE ?", ['%,' . trim((string)($b['group'] ?? '')) . ',%']);
        case 'numbers':
            return [];
        default:
            return acc_all('SELECT * FROM acc_persons');
    }
}

function r_sms_group($u)
{
    acc_sms_schema();
    $b = acc_body();
    $msgs = [];
    $seen = [];
    foreach (acc_sms_targets($b) as $p) {
        $m = acc_sms_mobile($p['mobile']);
        if (!$m || isset($seen[$m])) {
            continue;
        }
        $seen[$m] = true;
        $msgs[] = !empty($b['pattern_id']) ? ['mobile' => $m, 'person_id' => $p['id'], 'pattern_id' => (int)$b['pattern_id'], 'values' => acc_sms_pattern_values($b, $p)]
            : ['mobile' => $m, 'person_id' => $p['id'], 'text' => acc_sms_body_for($b, $p)];
    }
    // extra numbers typed or pasted (one per line / comma separated)
    foreach (preg_split('/[\s,،;]+/u', (string)($b['numbers'] ?? ''), -1, PREG_SPLIT_NO_EMPTY) as $n) {
        $m = acc_sms_mobile($n);
        if ($m && !isset($seen[$m])) {
            $seen[$m] = true;
            $msgs[] = !empty($b['pattern_id']) ? ['mobile' => $m, 'pattern_id' => (int)$b['pattern_id'], 'values' => acc_sms_pattern_values($b)]
                : ['mobile' => $m, 'text' => acc_sms_body_for($b)];
        }
    }
    if (!$msgs) {
        throw new AccError('هیچ گیرنده‌ای با موبایل معتبر پیدا نشد');
    }
    if (count($msgs) > 5000) {
        throw new AccError('حداکثر ۵۰۰۰ گیرنده در هر ارسال');
    }
    if (empty($b['pattern_id']) && !array_filter($msgs, fn($m) => trim($m['text']) !== '')) {
        throw new AccError('متن پیامک خالی است');
    }
    $q = acc_sms_queue($msgs, $u['username'], 'group');
    acc_sms_process(20);   // the rest goes out with the background job
    acc_log($u['username'], 'sms_group', $q['queued'] . ' recipients');
    return $q + ['ok' => true];
}

function r_sms_log()
{
    acc_sms_schema();
    $w = [];
    $a = [];
    if (!empty($_GET['status'])) {
        $w[] = 'l.status = ?';
        $a[] = $_GET['status'];
    }
    if (!empty($_GET['q'])) {
        $w[] = '(l.mobile LIKE ? OR l.text LIKE ? OR p.name LIKE ?)';
        $q = '%' . $_GET['q'] . '%';
        array_push($a, $q, $q, $q);
    }
    $rows = acc_all('SELECT l.*, p.name person_name, pt.title pattern_title FROM acc_sms_log l LEFT JOIN acc_persons p ON p.id = l.person_id
        LEFT JOIN acc_sms_patterns pt ON pt.id = l.pattern_id' . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . ' ORDER BY l.id DESC LIMIT 300', $a);
    $counts = [];
    foreach (acc_all('SELECT status, COUNT(*) n FROM acc_sms_log GROUP BY status') as $c) {
        $counts[$c['status']] = (int)$c['n'];
    }
    return ['rows' => array_map(fn($r) => ['id' => (int)$r['id'], 'created_at' => $r['created_at'], 'user' => $r['user'], 'mobile' => $r['mobile'],
        'person_name' => $r['person_name'], 'text' => $r['text'] !== '' ? $r['text'] : ('پترن: ' . $r['pattern_title']), 'status' => $r['status'],
        'error' => $r['error'], 'cost' => (float)$r['cost'], 'kind' => $r['kind'], 'sent_at' => $r['sent_at']], $rows), 'counts' => $counts];
}

function r_sms_retry($u, $id)
{
    acc_sms_schema();
    acc_q("UPDATE acc_sms_log SET status = 'queued', error = '' WHERE id = ? AND status = 'failed'", [$id]);
    acc_sms_process(5);
    return ['ok' => true, 'status' => acc_val('SELECT status FROM acc_sms_log WHERE id = ?', [$id])];
}
