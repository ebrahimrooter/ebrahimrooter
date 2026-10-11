<?php
/**
 * The books as one Excel workbook (for people, next to the .bkp file that
 * restores the program): summary, bank cards, every bank transaction, people,
 * and for every company (موسسه) its persons, goods and stock, invoices,
 * journal entries, trial balance, cash/bank accounts and cheques.
 * All amounts in toman. Locked with backup_password when it is set.
 *
 *   backup_excel_build()                 → .xlsx bytes (not locked)
 *   backup_excel_file($password)         → [path of a temp file, file name]
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/xlsx.php';

/** rial → toman, as a number Excel can add up */
function bx_t($rial)
{
    $v = (float)$rial / 10;
    return $v == (int)$v ? (int)$v : round($v, 1);
}

function backup_excel_sheets()
{
    $db = ba_db();
    $sheets = [];
    $wallets = ba_wallets();
    $txs = $db->query("SELECT t.*, c.name AS category_name, w.name AS wallet_name, s.body AS sms_text FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN wallets w ON w.id = t.wallet_id LEFT JOIN sms_raw s ON s.id = t.sms_id
        ORDER BY t.occurred_at, t.id")->fetchAll();
    $status = ['pending' => 'بی‌جواب', 'confirmed' => 'ثبت شده', 'ignored' => 'نادیده'];
    $bankFa = ['mellat' => 'ملت', 'melli' => 'ملی', 'saderat' => 'صادرات', 'blu' => 'بلو', 'cash' => 'نقد'];

    // accounting (main company) for the summary
    require_once __DIR__ . '/acc_api.php';
    require_once __DIR__ . '/inventory.php';
    acc_use_company(1);
    acc_db();
    $company = (string)acc_val('SELECT name FROM acc_company WHERE id = 1');
    $people = ba_people_totals();
    $inv = inv_list(1);
    [$jy, $jm, $jd] = ba_g2j((int)date('Y'), (int)date('m'), (int)date('d'));

    $sheets[] = ['name' => 'خلاصه', 'head' => ['', ''], 'widths' => [34, 26], 'rows' => [
        ['شرکت', $company],
        ['تاریخ تهیه', sprintf('%04d/%02d/%02d %s', $jy, $jm, $jd, date('H:i'))],
        ['موجودی کل کارت‌ها و صندوق (تومان)', bx_t(array_sum(array_column($wallets, 'balance')))],
        ['طلب از اشخاص (تومان)', bx_t($people['receivable'] ?? 0)],
        ['بدهی به اشخاص (تومان)', bx_t($people['payable'] ?? 0)],
        ['ارزش موجودی انبار (تومان)', bx_t($inv['totals']['value'] ?? 0)],
        ['تعداد تراکنش‌های بانک', count($txs)],
        ['تراکنش‌های بی‌جواب', count(array_filter($txs, fn($t) => $t['status'] === 'pending'))],
        ['', ''],
        ['همه‌ی مبلغ‌ها به تومان است. برای برگرداندن برنامه از فایل .bkp استفاده می‌شود؛ این فایل برای دیدن و حساب‌رسی است.', ''],
    ], 'bold_rows' => [2]];

    $sheets[] = ['name' => 'کارت‌ها', 'head' => ['کارت', 'بانک', 'شماره کارت/حساب', 'مانده در برنامه (تومان)', 'مانده طبق آخرین پیامک (تومان)', 'بی‌جواب'],
        'rows' => array_map(fn($w) => [$w['name'], $bankFa[$w['bank'] ?? ''] ?? '', (string)($w['card'] ?? ''), bx_t($w['balance']),
            $w['bank_balance'] !== null ? bx_t($w['bank_balance']) : '', (int)$w['pending']], $wallets)];

    $rows = [];
    $in = $out = 0;
    foreach ($txs as $t) {
        $amt = bx_t($t['amount']);
        if ($t['status'] !== 'ignored') {
            $t['direction'] === 'in' ? $in += $t['amount'] : $out += $t['amount'];
        }
        $rows[] = [(int)$t['id'], (string)$t['bank_date'], (string)$t['bank_time'], (string)$t['wallet_name'],
            $t['direction'] === 'in' ? 'واریز' : 'برداشت', $t['direction'] === 'in' ? $amt : -$amt,
            $t['balance'] !== null ? bx_t($t['balance']) : '', (string)$t['description'], (string)$t['party'], (string)$t['category_name'],
            $status[$t['status']] ?? $t['status'], trim(preg_replace('/\s+/u', ' ', (string)$t['sms_text']))];
    }
    $rows[] = ['', '', '', '', 'جمع واریز', bx_t($in)];
    $rows[] = ['', '', '', '', 'جمع برداشت', -bx_t($out)];
    $sheets[] = ['name' => 'تراکنش‌های بانک', 'head' => ['شناسه', 'تاریخ', 'ساعت', 'کارت', 'نوع', 'مبلغ (تومان)', 'مانده بانک (تومان)', 'بابت',
        'طرف حساب', 'دسته', 'وضعیت', 'متن پیامک'], 'rows' => $rows, 'bold_rows' => [count($rows) - 2, count($rows) - 1], 'widths' => [8, 12, 8, 14, 10, 16, 18, 30, 18, 30, 10, 60]];

    $sheets[] = ['name' => 'طرف‌حساب‌ها', 'head' => ['نام', 'تلفن', 'مانده (تومان؛ مثبت = بدهکار به ما)', 'تعداد'],
        'rows' => array_map(fn($p) => [$p['name'], (string)($p['phone'] ?? ''), bx_t($p['balance']), (int)$p['uses']], ba_people())];

    // every company's books
    $companies = acc_companies();
    $kinds = ['sale' => 'فروش', 'purchase' => 'خرید', 'sale_return' => 'برگشت از فروش', 'purchase_return' => 'برگشت از خرید',
        'sale_proforma' => 'پیش‌فاکتور فروش', 'purchase_proforma' => 'پیش‌فاکتور خرید', 'sale_order' => 'سفارش فروش', 'purchase_order' => 'سفارش خرید'];
    $admin = ['id' => 0, 'username' => 'backup', 'role' => 'admin', 'permissions' => '["*"]'];
    foreach ($companies as $cid => $cname) {
        acc_use_company($cid);
        acc_db();
        $sfx = count($companies) > 1 ? ' - ' . $cname : '';
        $sheets[] = ['name' => 'اشخاص' . $sfx, 'head' => ['کد', 'نام', 'نوع', 'موبایل', 'کد ملی', 'گروه', 'مانده (تومان)'],
            'rows' => array_map(fn($p) => [(string)$p['code'], $p['name'], (string)($p['type_label'] ?? $p['type']), (string)$p['mobile'],
                (string)$p['national_id'], (string)$p['groups'], bx_t($p['balance'])], r_persons())];

        $ci = inv_list($cid);
        $whs = $ci['warehouses'];
        $sheets[] = ['name' => 'کالا و انبار' . $sfx,
            'head' => array_merge(['کد', 'کالا', 'گروه', 'واحد', 'موجودی کل'], array_map(fn($w) => 'موجودی ' . $w['name'], $whs),
                ['نقطه سفارش', 'قیمت فروش (تومان)', 'بهای واحد (تومان)', 'ارزش موجودی (تومان)', 'وضعیت']),
            'rows' => array_map(function ($it) use ($whs) {
                $per = array_column($it['warehouses'], 'qty', 'warehouse_id');
                return array_merge([$it['code'], $it['name'], $it['group'], $it['unit'], $it['qty']],
                    array_map(fn($w) => (float)($per[$w['id']] ?? 0), $whs),
                    [$it['reorder_point'], bx_t($it['sale_price']), bx_t($it['cost']), bx_t($it['value']),
                        ['ok' => 'موجود', 'low' => 'رو به اتمام', 'out' => 'تمام شده'][$it['status']] ?? '']);
            }, $ci['items'])];

        $sheets[] = ['name' => 'فاکتورها' . $sfx, 'head' => ['شماره', 'نوع', 'تاریخ', 'شخص', 'جمع (تومان)', 'تخفیف', 'مالیات', 'مبلغ کل (تومان)', 'تسویه', 'وضعیت'],
            'rows' => array_map(fn($i) => [(string)$i['number'], $kinds[$i['kind']] ?? $i['kind'], (string)$i['date'], (string)($i['person_name'] ?? ''),
                bx_t($i['subtotal']), bx_t($i['discount']), bx_t($i['tax']), bx_t($i['total']), $i['settled'] ? 'تسویه' : 'باز',
                $i['status'] === 'draft' ? 'پیش‌نویس' : 'قطعی'], array_reverse(r_invoices($admin)))];

        $jr = [];
        foreach (array_reverse(r_journals()) as $j) {
            foreach ($j['lines'] as $l) {
                $jr[] = [(string)$j['number'], (string)$j['date'], (string)$j['description'], (string)$l['account_code'], (string)$l['account_name'],
                    (string)($l['person_name'] ?? ''), (string)$l['description'], bx_t($l['debit']), bx_t($l['credit'])];
            }
        }
        $sheets[] = ['name' => 'اسناد حسابداری' . $sfx, 'head' => ['سند', 'تاریخ', 'شرح سند', 'کد حساب', 'حساب', 'شخص', 'شرح ردیف', 'بدهکار (تومان)', 'بستانکار (تومان)'],
            'rows' => $jr];

        $tb = r_trial_balance();
        $tbRows = array_map(fn($r) => [(string)$r['code'], $r['name'], bx_t($r['debit']), bx_t($r['credit']), bx_t($r['balance'])], $tb['rows'] ?? []);
        $tbRows[] = ['', 'جمع', bx_t($tb['total_debit'] ?? 0), bx_t($tb['total_credit'] ?? 0), ''];
        $sheets[] = ['name' => 'تراز آزمایشی' . $sfx, 'head' => ['کد', 'حساب', 'بدهکار (تومان)', 'بستانکار (تومان)', 'مانده (تومان)'],
            'rows' => $tbRows, 'bold_rows' => [count($tbRows) - 1]];

        $sheets[] = ['name' => 'صندوق و بانک' . $sfx, 'head' => ['نام', 'نوع', 'شماره حساب', 'مانده (تومان)'],
            'rows' => array_map(fn($a) => [$a['name'], (string)$a['kind_label'], (string)$a['account_no'], bx_t($a['balance'])], r_accounts())];

        $sheets[] = ['name' => 'چک‌ها' . $sfx, 'head' => ['شماره', 'نوع', 'شخص', 'مبلغ (تومان)', 'سررسید', 'بانک', 'وضعیت', 'شرح'],
            'rows' => array_map(fn($c) => [(string)$c['number'], $c['direction'] === 'payable' ? 'پرداختی' : 'دریافتی', (string)$c['person_name'],
                bx_t($c['amount']), (string)$c['due_date'], (string)$c['bank_name'], (string)$c['status_label'], (string)$c['description']], r_cheques())];
    }
    acc_use_company(1);
    return $sheets;
}

function backup_excel_build()
{
    return xlsx_build(backup_excel_sheets());
}

/** The workbook in a temp file (locked with $password when given) and its name for Bale / download. */
function backup_excel_file($password = '')
{
    [$jy, $jm, $jd] = ba_g2j((int)date('Y'), (int)date('m'), (int)date('d'));
    $name = sprintf('hesab-%04d-%02d-%02d-%s.xlsx', $jy, $jm, $jd, date('Hi'));
    $bytes = backup_excel_build();
    if ((string)$password !== '') {
        $bytes = xlsx_encrypt($bytes, (string)$password);
    }
    $path = sys_get_temp_dir() . '/' . bin2hex(random_bytes(6)) . '-' . $name;
    file_put_contents($path, $bytes);
    @chmod($path, 0600);
    return [$path, $name];
}
