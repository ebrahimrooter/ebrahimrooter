<?php
/**
 * Voice assistant backend for the iPhone companion app (ios/) and any other
 * client: pairing, device tokens and the Persian commands it understands.
 *
 *   phone PWA (app password) ── assistant_pair ──► one-time code (5 min)
 *   iPhone app ── assistant_redeem(code) ──► device token (kept in the Keychain)
 *   iPhone app ── Bearer <device token> ──► assistant_ask / transcribe / speak / confirm
 *
 * Only a SHA-256 of every code and token is stored. A device can be revoked
 * from the phone app's settings. Nothing here calls the internet: speech goes
 * through the local voice service (voice/), the answers come from the books.
 */

require_once __DIR__ . '/lib.php';

const ASSISTANT_PAIR_TTL = 300;          // seconds a pairing code lives
const ASSISTANT_DRAFT_TTL = 600;         // seconds an invoice draft waits for «آره»

function assistant_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    ba_db()->exec("CREATE TABLE IF NOT EXISTS assistant_devices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL DEFAULT '',
        token_hash TEXT NOT NULL UNIQUE,
        created_at TEXT NOT NULL,
        last_seen TEXT,
        revoked INTEGER NOT NULL DEFAULT 0
    )");
    $cols = array_column(ba_db()->query('PRAGMA table_info(assistant_devices)')->fetchAll(), 'name');
    if (!in_array('apns_token', $cols, true)) {        // iPhone push notifications (apns.php)
        ba_db()->exec('ALTER TABLE assistant_devices ADD COLUMN apns_token TEXT');
        ba_db()->exec("ALTER TABLE assistant_devices ADD COLUMN apns_env TEXT NOT NULL DEFAULT 'sandbox'");
    }
}

/* ------------------------------------------------------------------ */
/* pairing and device tokens                                            */
/* ------------------------------------------------------------------ */

/** One-time code for the iPhone app (asked from the phone PWA, which knows the app password). */
function assistant_pair_code()
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';   // no 0/O, 1/I
    $code = '';
    for ($i = 0; $i < 8; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $codes = array_filter((array)ba_kv_get('assistant_pair', []), fn($c) => $c['exp'] > time());
    $codes[] = ['hash' => hash('sha256', $code), 'exp' => time() + ASSISTANT_PAIR_TTL];
    ba_kv_set('assistant_pair', array_values($codes));
    return $code;
}

/** Code -> new device token (shown once). Wrong codes are rate limited per IP. */
function assistant_redeem($code, $name, $ip)
{
    assistant_schema();
    $key = 'assistant_redeem_fail:' . $ip;
    $fails = (array)ba_kv_get($key, ['n' => 0, 'until' => 0]);
    if ($fails['until'] > time()) {
        throw new RuntimeException('تلاش زیاد؛ چند دقیقه بعد دوباره امتحان کن', 429);
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', (string)$code));
    $codes = array_filter((array)ba_kv_get('assistant_pair', []), fn($c) => $c['exp'] > time());
    $hit = null;
    foreach ($codes as $i => $c) {
        if (hash_equals($c['hash'], hash('sha256', $code))) {
            $hit = $i;
        }
    }
    if ($hit === null) {
        $fails['n']++;
        if ($fails['n'] >= 10) {
            $fails = ['n' => 0, 'until' => time() + 900];
        }
        ba_kv_set($key, $fails);
        throw new RuntimeException('کد اتصال نادرست است یا منقضی شده', 403);
    }
    unset($codes[$hit]);                       // one time only
    ba_kv_set('assistant_pair', array_values($codes));
    ba_kv_set($key, null);
    return assistant_new_device($name);
}

/** A new device token (returned once; only its SHA-256 is kept). */
function assistant_new_device($name)
{
    assistant_schema();
    $token = bin2hex(random_bytes(32));
    $name = mb_substr(trim((string)$name) ?: 'iPhone', 0, 60);
    ba_db()->prepare('INSERT INTO assistant_devices (name, token_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$name, hash('sha256', $token), date('Y-m-d H:i:s')]);
    return ['token' => $token, 'device_id' => (int)ba_db()->lastInsertId(), 'name' => $name];
}

/**
 * Sign in from the iPhone app with the app password, without the phone web app.
 * The password is only checked here; the app keeps just the device token it gets.
 * 5 wrong passwords from one address = 15 minutes locked, and a Bale notice.
 */
function assistant_login($password, $name, $ip)
{
    $key = 'assistant_login_fail:' . $ip;
    $fails = (array)ba_kv_get($key, ['n' => 0, 'until' => 0]);
    if ($fails['until'] > time()) {
        throw new RuntimeException('تلاش زیاد؛ ' . ceil(($fails['until'] - time()) / 60) . ' دقیقه بعد دوباره امتحان کن', 429);
    }
    $app = (string)(ba_config()['app_token'] ?? '');
    if ($app === '' || strpos($app, 'CHANGE-ME') === 0 || !hash_equals($app, (string)$password)) {
        usleep(400000);
        $fails['n']++;
        if ($fails['n'] >= 5) {
            $fails = ['n' => 0, 'until' => time() + 900];
            ba_notify('⚠️ پنج بار رمز اشتباه برای ورود اپ آیفون از ' . $ip . '؛ ۱۵ دقیقه قفل شد.');
        }
        ba_kv_set($key, $fails);
        throw new RuntimeException('رمز اپ نادرست است', 403);
    }
    ba_kv_set($key, null);
    $d = assistant_new_device($name);
    ba_notify('📱 گوشی «' . $d['name'] . '» با رمز اپ به دستیار حسابداری وصل شد. اگر کار تو نبود، از تنظیمات اپ دسترسی‌اش را لغو کن و رمز اپ را عوض کن.');
    return $d;
}

/** The device behind "Authorization: Bearer <token>", or null. */
function assistant_device()
{
    assistant_schema();
    $token = ba_bearer_token();
    if (!preg_match('/^[0-9a-f]{64}$/i', $token)) {
        return null;
    }
    $q = ba_db()->prepare('SELECT * FROM assistant_devices WHERE token_hash = ? AND revoked = 0');
    $q->execute([hash('sha256', strtolower($token))]);
    $d = $q->fetch();
    if ($d && (!$d['last_seen'] || strtotime($d['last_seen']) < time() - 60)) {
        ba_db()->prepare('UPDATE assistant_devices SET last_seen = ? WHERE id = ?')->execute([date('Y-m-d H:i:s'), $d['id']]);
    }
    return $d ?: null;
}

function assistant_devices()
{
    assistant_schema();
    return ba_db()->query('SELECT id, name, created_at, last_seen, revoked FROM assistant_devices ORDER BY id DESC')->fetchAll();
}

function assistant_revoke($id)
{
    assistant_schema();
    ba_db()->prepare('UPDATE assistant_devices SET revoked = 1 WHERE id = ?')->execute([(int)$id]);
}

/* ------------------------------------------------------------------ */
/* understanding a sentence                                             */
/* ------------------------------------------------------------------ */

/** "دو" -> 2 ... for the small quantities people say. */
function assistant_number_words($t)
{
    $map = ['یک' => 1, 'یه' => 1, 'دو' => 2, 'سه' => 3, 'چهار' => 4, 'پنج' => 5, 'شش' => 6, 'شیش' => 6, 'هفت' => 7, 'هشت' => 8, 'نه' => 9,
        'ده' => 10, 'یازده' => 11, 'دوازده' => 12, 'پانزده' => 15, 'پونزده' => 15, 'بیست' => 20, 'سی' => 30, 'چهل' => 40, 'پنجاه' => 50, 'صد' => 100];
    return preg_replace_callback('/(?<=^|\s)(' . implode('|', array_keys($map)) . ')(?=\s+(?:عدد|تا|کیسه|کیلو|بسته|جعبه|کارتن|متر|شاخه)\b)/u',
        fn($m) => (string)$map[$m[1]], $t);
}

/** [from, to, label] of the period a sentence talks about (Jalali). */
function assistant_period($t)
{
    $today = acc_today();
    [$y, $m] = array_map('intval', explode('/', $today));
    if (preg_match('/دیروز/u', $t)) {
        $d = acc_jalali_days_ago(1);
        return [$d, $d, 'دیروز'];
    }
    if (preg_match('/هفته/u', $t)) {
        return [acc_jalali_days_ago(6), $today, 'هفت روز اخیر'];
    }
    if (preg_match('/ماه\s*(قبل|پیش|گذشته)/u', $t)) {
        $pm = $m === 1 ? 12 : $m - 1;
        $py = $m === 1 ? $y - 1 : $y;
        return [sprintf('%04d/%02d/01', $py, $pm), sprintf('%04d/%02d/31', $py, $pm), 'ماه قبل'];
    }
    if (preg_match('/ماه/u', $t)) {
        return [sprintf('%04d/%02d/01', $y, $m), $today, 'این ماه'];
    }
    if (preg_match('/(امسال|سال)/u', $t)) {
        return [sprintf('%04d/01/01', $y), $today, 'امسال'];
    }
    return [$today, $today, 'امروز'];
}

/** The longest name of $rows found in $t. */
function assistant_find_name($t, array $rows, $key = 'name')
{
    $best = null;
    foreach ($rows as $r) {
        $n = ba_norm_text($r[$key]);
        if ($n !== '' && mb_strpos(" $t ", " $n") !== false && (!$best || mb_strlen($n) > mb_strlen(ba_norm_text($best[$key])))) {
            $best = $r;
        }
    }
    return $best;
}

function assistant_rial($v)
{
    return number_format(round(abs((float)$v) / 10)) . ' تومان';
}


/**
 * An amount said in a sentence, in rial. People say toman: «پنج میلیون»،
 * «۵۰۰ هزار تومان»، «۲.۵ میلیون»، «۱۲۰۰۰۰ تومان»، «۱۰۰۰۰۰ ریال».
 */
function assistant_amount($t)
{
    $words = ['یک' => 1, 'یه' => 1, 'دو' => 2, 'سه' => 3, 'چهار' => 4, 'پنج' => 5, 'شش' => 6, 'شیش' => 6, 'هفت' => 7, 'هشت' => 8, 'نه' => 9, 'ده' => 10,
        'یازده' => 11, 'دوازده' => 12, 'پانزده' => 15, 'پونزده' => 15, 'بیست' => 20, 'سی' => 30, 'چهل' => 40, 'پنجاه' => 50, 'شصت' => 60, 'هفتاد' => 70,
        'هشتاد' => 80, 'نود' => 90, 'صد' => 100, 'دویست' => 200, 'سیصد' => 300, 'چهارصد' => 400, 'پانصد' => 500, 'پونصد' => 500, 'ششصد' => 600,
        'هفتصد' => 700, 'هشتصد' => 800, 'نهصد' => 900];
    // «بیست و پنج میلیون» -> «25 میلیون»
    $t = preg_replace_callback('/((?:(?:' . implode('|', array_keys($words)) . ')(?:\s+و\s+)?)+)(?=\s*(?:میلیارد|میلیون|هزار|تومان|تومن|ریال))/u', function ($m) use ($words) {
        $n = 0;
        foreach (preg_split('/\s+(?:و\s+)?/u', trim($m[1])) as $w) {
            $n += $words[$w] ?? 0;
        }
        return $n ? $n . ' ' : $m[0];
    }, $t);
    if (!preg_match('/(\d+(?:\.\d+)?)\s*(میلیارد|میلیون|هزار)?(?:\s*و\s*(\d+)\s*(هزار))?\s*(تومان|تومن|ریال)?/u', $t, $m) || (float)$m[1] <= 0) {
        return 0;
    }
    $mult = ['میلیارد' => 1e9, 'میلیون' => 1e6, 'هزار' => 1e3][$m[2] ?? ''] ?? 1;
    $v = (float)$m[1] * $mult + (!empty($m[3]) ? (float)$m[3] * 1e3 : 0);
    return (int)round(($m[5] ?? '') === 'ریال' ? $v : $v * 10);
}

/**
 * A Jalali date said in a sentence: «۱۴۰۵/۰۸/۱۵»، «۱۵ آبان»، «پانزدهم آبان ۱۴۰۵»،
 * «فردا»، «ده روز دیگه»، «ماه بعد» / «یک ماه دیگه»، «آخر ماه». Returns [date, text matched] or null.
 */
function assistant_date($t)
{
    $months = ['فروردین' => 1, 'اردیبهشت' => 2, 'خرداد' => 3, 'تیر' => 4, 'مرداد' => 5, 'شهریور' => 6, 'مهر' => 7, 'آبان' => 8, 'آذر' => 9, 'دی' => 10, 'بهمن' => 11, 'اسفند' => 12];
    $ordinals = ['اول' => 1, 'یکم' => 1, 'دوم' => 2, 'سوم' => 3, 'چهارم' => 4, 'پنجم' => 5, 'ششم' => 6, 'هفتم' => 7, 'هشتم' => 8, 'نهم' => 9, 'دهم' => 10,
        'یازدهم' => 11, 'دوازدهم' => 12, 'سیزدهم' => 13, 'چهاردهم' => 14, 'پانزدهم' => 15, 'شانزدهم' => 16, 'هفدهم' => 17, 'هجدهم' => 18, 'نوزدهم' => 19,
        'بیستم' => 20, 'بیست و یکم' => 21, 'بیست و دوم' => 22, 'بیست و سوم' => 23, 'بیست و چهارم' => 24, 'بیست و پنجم' => 25, 'بیست و ششم' => 26,
        'بیست و هفتم' => 27, 'بیست و هشتم' => 28, 'بیست و نهم' => 29, 'سیام' => 30, 'سی ام' => 30, 'سی و یکم' => 31];
    [$ty, $tm, $td] = array_map('intval', explode('/', acc_today()));
    $plus = function ($days) use ($ty, $tm, $td) {
        [$gy, $gm, $gd] = ba_j2g($ty, $tm, $td);
        return vsprintf('%04d/%02d/%02d', ba_g2j(...array_map('intval', explode('-', date('Y-m-d', mktime(0, 0, 0, $gm, $gd + $days, $gy))))));
    };
    if (preg_match('~(1[34]\d\d)[/\-.](\d{1,2})[/\-.](\d{1,2})~u', $t, $m)) {
        return [sprintf('%04d/%02d/%02d', $m[1], $m[2], $m[3]), $m[0]];
    }
    $mre = implode('|', array_keys($months));
    $ore = implode('|', array_map(fn($k) => preg_quote($k, '/'), array_keys($ordinals)));
    if (preg_match('/(\d{1,2}|' . $ore . ')\s*(?:ام|م)?\s*(' . $mre . ')(?:\s*(?:ماه)?\s*(1[34]\d\d))?/u', $t, $m)) {
        $day = ctype_digit($m[1]) ? (int)$m[1] : $ordinals[$m[1]];
        $mon = $months[$m[2]];
        $year = !empty($m[3]) ? (int)$m[3] : ($mon < $tm || ($mon === $tm && $day < $td) ? $ty + 1 : $ty);   // a past day = next year
        return [sprintf('%04d/%02d/%02d', $year, $mon, $day), $m[0]];
    }
    if (preg_match('/پس ?فردا/u', $t, $m)) {
        return [$plus(2), $m[0]];
    }
    if (preg_match('/فردا/u', $t, $m)) {
        return [$plus(1), $m[0]];
    }
    if (preg_match('/(\d+)\s*روز\s*(?:دیگه|دیگر|بعد)/u', $t, $m)) {
        return [$plus((int)$m[1]), $m[0]];
    }
    if (preg_match('/(\d+)\s*ماه\s*(?:دیگه|دیگر|بعد)/u', $t, $m)) {
        return [acc_jalali_add_months(acc_today(), (int)$m[1]), $m[0]];
    }
    if (preg_match('/(ماه\s*(?:بعد|دیگه|دیگر|آینده)|یه ماه دیگه|یک ماه دیگر)/u', $t, $m)) {
        return [acc_jalali_add_months(acc_today(), 1), $m[0]];
    }
    if (preg_match('/آخر\s*(?:همین\s*)?ماه/u', $t, $m)) {
        return [sprintf('%04d/%02d/%02d', $ty, $tm, $tm <= 6 ? 31 : ($tm <= 11 ? 30 : 29)), $m[0]];
    }
    return null;
}

/** Cash or bank account named (or implied) in a sentence. */
function assistant_cash_account($t)
{
    $rows = acc_all('SELECT id, name, kind FROM acc_cash_accounts ORDER BY id');
    if (!$rows) {
        return null;
    }
    if ($named = assistant_find_name($t, $rows)) {
        return $named;
    }
    $kind = preg_match('/(نقد|صندوق|دستی)/u', $t) ? 'cash' : (preg_match('/(بانک|کارت|حساب|واریز|انتقال|پوز|کارتخوان)/u', $t) ? 'bank' : null);
    foreach ($rows as $r) {
        if ($kind && $r['kind'] === $kind) {
            return $r;
        }
    }
    return $rows[0];
}

/** Runs a confirmed draft. */
function assistant_execute(array $d, array $device)
{
    $who = 'assistant:' . $device['name'];
    switch ($d['type']) {
        case 'invoice':
            $inv = acc_invoice_save(null, $d['body']);
            acc_log($who, 'create_invoice', $inv['number']);
            if (function_exists('acc_sms_after_invoice')) {
                acc_sms_after_invoice($inv);
            }
            return ['reply' => 'فاکتور ' . $inv['number'] . ' ثبت شد. جمع ' . assistant_rial($inv['total']) . '.',
                'data' => ['invoice_id' => (int)$inv['id'], 'number' => $inv['number'], 'total' => (float)$inv['total']]];
        case 'treasury':
            $t = acc_treasury_record($d['body']);
            acc_log($who, 'treasury', $t['number']);
            return ['reply' => 'ثبت شد (' . $t['number'] . ').', 'data' => ['number' => $t['number']]];
        case 'person':
            $f = acc_person_fields($d['body']);
            $f['code'] = (['supplier' => 'S', 'employee' => 'E', 'investor' => 'I', 'marketer' => 'M', 'other' => 'O'][$f['type']] ?? 'C') . sprintf('%03d', acc_next('person'));
            $id = acc_insert('acc_persons', $f);
            acc_log($who, 'create_person', $f['name']);
            return ['reply' => $f['name'] . ' به اشخاص اضافه شد.', 'data' => ['person_id' => $id]];
        case 'cheque':
            $c = acc_cheque_record($d['body']);
            acc_log($who, 'cheque', $c['number']);
            return ['reply' => 'چک ' . $c['number'] . ' ثبت شد؛ سررسید ' . $c['due_date'] . '. روز قبل و روز سررسید یادآوری می‌کنم.', 'data' => ['cheque_id' => (int)$c['id']]];
        case 'cheque_action':
            $c = acc_cheque_act($d['body']['id'], $d['body']['action'], $d['body']);
            acc_log($who, 'cheque_' . $d['body']['action'], $c['number']);
            return ['reply' => 'وضعیت چک ' . $c['number'] . ': ' . (ACC_CHEQUE_STATUS[$c['status']] ?? $c['status']) . '.', 'data' => ['cheque_id' => (int)$c['id']]];
        case 'sms':
            $q = acc_sms_queue([$d['body']], $who, 'assistant');
            acc_sms_process(5);
            $st = acc_val('SELECT status FROM acc_sms_log ORDER BY id DESC LIMIT 1');
            return ['reply' => $q['queued'] ? ($st === 'sent' ? 'پیامک فرستاده شد.' : 'پیامک در صف ارسال است.') : 'شماره‌ی موبایل درست نیست.', 'data' => []];
    }
    return ['reply' => 'کاری برای ثبت نبود.', 'data' => []];
}

/** Asks for what is missing (due date, number), then the confirmation. */
function assistant_cheque_draft(array $b, $ctxKey)
{
    if (empty($b['due_date']) || empty($b['number'])) {
        ba_kv_set($ctxKey, ['exp' => time() + ASSISTANT_DRAFT_TTL, 'type' => 'cheque_fill', 'body' => $b]);
        return ['reply' => empty($b['due_date']) ? 'سررسید چک چه تاریخی است؟' : 'شماره‌ی چک چند است؟', 'state' => 'confirm', 'data' => []];
    }
    ba_kv_set($ctxKey, ['exp' => time() + ASSISTANT_DRAFT_TTL, 'type' => 'cheque', 'body' => $b]);
    return ['reply' => 'چک ' . ($b['direction'] === 'payable' ? 'پرداختی به ' : 'دریافتی از ') . $b['person'] . '، ' . assistant_rial($b['amount'])
        . '، شماره ' . $b['number'] . '، سررسید ' . $b['due_date'] . ($b['bank_name'] !== '' ? '، بانک ' . $b['bank_name'] : '') . '. ثبت کنم؟',
        'state' => 'confirm', 'data' => ['cheque' => $b]];
}

/**
 * One turn of the conversation. Writing actions (invoice, receipt, payment,
 * expense, new person, SMS) are drafted first and run only after «آره»;
 * a bank transaction asked about takes the next sentence as its answer.
 * Returns ['reply' => text, 'state' => answered|confirm|unknown|error, 'data' => [...]].
 */
function assistant_answer($text, array $device)
{
    require_once __DIR__ . '/acc_api.php';
    acc_use_company(1);
    acc_db();
    $t = assistant_number_words(ba_norm_text($text));
    $ctxKey = 'assistant_ctx:' . $device['id'];
    $ctx = ba_kv_get($ctxKey);
    if ($ctx && $ctx['exp'] < time()) {
        $ctx = null;
    }
    $ok = fn($reply, $data = []) => ['reply' => $reply, 'state' => 'answered', 'data' => $data];
    $draft = function ($type, $body, $question, $data = []) use ($ctxKey) {
        ba_kv_set($ctxKey, ['exp' => time() + ASSISTANT_DRAFT_TTL, 'type' => $type, 'body' => $body]);
        return ['reply' => $question, 'state' => 'confirm', 'data' => $data];
    };

    /* ---- the answer to a question the assistant asked ---- */
    if ($ctx) {
        $yes = preg_match('/^(آره|اره|بله|آری|باشه|ثبت کن|بفرست|تایید|درسته|اوکی|ok|yes)\b/u', $t);
        $no = preg_match('/^(نه|نخیر|لغو|کنسل|ولش کن|نمی ?خواد|بیخیال)\b/u', $t);
        if ($ctx['type'] === 'cheque_fill' && !$no) {
            ba_kv_set($ctxKey, null);
            $b = $ctx['body'];
            if (empty($b['due_date']) && ($dt = assistant_date($t))) {
                $b['due_date'] = $dt[0];
            } elseif (empty($b['number']) && preg_match('/(\d{3,})/', str_replace(' ', '', $t), $mm)) {
                $b['number'] = $mm[1];
            }
            return assistant_cheque_draft($b, $ctxKey);
        }
        if ($ctx['type'] === 'bank_tx') {
            ba_kv_set($ctxKey, null);
            $tx = ba_get_transaction($ctx['body']['id']);
            if ($tx && $tx['status'] === 'pending' && !$no) {
                if (preg_match('/(نادیده|حساب نکن|تکراری)/u', $t)) {
                    ba_db()->prepare("UPDATE transactions SET status = 'ignored' WHERE id = ?")->execute([$tx['id']]);
                    ba_acc_link_tx($tx['id']);
                    return $ok('نادیده گرفتم.');
                }
                $g = ba_interpret($text, $tx['direction']);
                try {
                    ba_confirm_tx($tx['id'], $g['description'], $g['party'], $g['category_id']);
                } catch (InvalidArgumentException $e) {
                    return ['reply' => $e->getMessage(), 'state' => 'error', 'data' => []];
                }
                $left = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
                return $ok('ثبت شد' . ($g['party'] !== '' ? '، ' . ($tx['direction'] === 'in' ? 'از ' : 'به ') . $g['party'] : '') . '.' . ($left ? ' ' . $left . ' تراکنش دیگر مانده.' : ''));
            }
            if ($no) {
                return $ok('باشه، بعداً.');
            }
        } elseif ($yes) {
            ba_kv_set($ctxKey, null);
            $r = assistant_execute($ctx, $device);
            return $ok($r['reply'], $r['data']);
        } elseif ($no) {
            ba_kv_set($ctxKey, null);
            return $ok('باشه، ثبت نشد.');
        }
        ba_kv_set($ctxKey, null);   // something else was asked: the draft is dropped
    }

    /* ---- writing actions (asked for confirmation) ---- */

    // cheque actions: «چک ۴۵۲۱۹۰ وصول شد»، «چک ۴۵۲۱۹۰ برگشت خورد»، «چک ۷۸۸ پاس شد»
    if (preg_match('/چک/u', $t) && preg_match('/(وصول|پاس|برگشت|برگشتی|نقد شد|خوابوندم|خواباندم|واگذار)/u', $t)
        && preg_match('/(\d{3,})/', $t, $mm)) {
        $c = acc_row("SELECT c.*, p.name pn FROM acc_cheques c LEFT JOIN acc_persons p ON p.id = c.person_id WHERE c.number = ? ORDER BY c.id DESC LIMIT 1", [$mm[1]]);
        if (!$c) {
            return ['reply' => 'چکی با شماره‌ی ' . $mm[1] . ' پیدا نکردم.', 'state' => 'error', 'data' => []];
        }
        $acc = assistant_cash_account($t . ' بانک');
        if (preg_match('/برگشت/u', $t)) {
            $action = 'return';
        } elseif (preg_match('/(خوابوندم|خواباندم|واگذار)/u', $t)) {
            $action = 'deposit';
        } else {
            $action = $c['direction'] === 'received' ? 'collect' : 'pay';
        }
        $label = ['return' => 'برگشت خورده', 'deposit' => 'واگذار به ' . $acc['name'], 'collect' => 'وصول به ' . $acc['name'], 'pay' => 'پاس از ' . $acc['name']][$action];
        return $draft('cheque_action', ['id' => (int)$c['id'], 'action' => $action, 'account_id' => (int)$acc['id']],
            'چک ' . $c['number'] . ' ' . ($c['direction'] === 'received' ? 'از ' : 'به ') . $c['pn'] . '، ' . assistant_rial($c['amount']) . ': ' . $label . '. ثبت کنم؟');
    }

    // new cheque: «یک چک ده میلیونی از علی رضایی گرفتم شماره ۴۵۲۱۹۰ سررسید ۱۵ آبان بانک ملی»
    if (preg_match('/چک/u', $t) && preg_match('/(گرفتم|دریافت|داد\b|دادم|کشیدم|صادر|نوشتم|ثبت کن)/u', $t)
        && !preg_match('/(چک ?های|چکای|سررسید چک|موعد چک)/u', $t)) {
        $payable = (bool)preg_match('/(دادم|کشیدم|صادر|نوشتم)/u', $t);
        $rest = $t;
        $number = '';
        if (preg_match('/(?:شماره|سریال)\s*(?:ی|ش)?\s*(\d[\d\s]{2,})/u', $rest, $mm)) {
            $number = str_replace(' ', '', $mm[1]);
            $rest = str_replace($mm[0], ' ', $rest);
        }
        $due = '';
        if (preg_match('/(?:سررسید|تاریخ|موعد|برای)\s*(?:ش|اش)?\s*(.+)$/u', $rest, $mm) && ($dt = assistant_date($mm[1]))) {
            [$due, $said] = $dt;
            $rest = str_replace($said, ' ', $rest);
        } elseif ($dt = assistant_date($rest)) {
            [$due, $said] = $dt;
            $rest = str_replace($said, ' ', $rest);
        }
        $bank = preg_match('/بانک\s+(\S+)/u', $rest, $mm) ? $mm[1] : '';
        $person = assistant_find_name($rest, acc_all('SELECT id, name FROM acc_persons'));
        $amount = assistant_amount(preg_replace('/(\S+?)ی(?=\s|$)/u', '$1', $rest));   // «ده میلیونی» -> «ده میلیون»
        if (!$person) {
            return ['reply' => 'چک ' . ($payable ? 'به' : 'از') . ' چه کسی؟ اسم طرف حساب را بگو.', 'state' => 'unknown', 'data' => []];
        }
        if ($amount <= 0) {
            return ['reply' => 'مبلغ چک چقدر است؟ مثلاً «چک ده میلیونی از ' . $person['name'] . ' گرفتم».', 'state' => 'unknown', 'data' => []];
        }
        return assistant_cheque_draft(['direction' => $payable ? 'payable' : 'received', 'person_id' => (int)$person['id'], 'person' => $person['name'],
            'amount' => $amount, 'number' => $number, 'due_date' => $due, 'bank_name' => $bank, 'date' => acc_today(),
            'account_id' => $payable ? (int)(assistant_cash_account('بانک')['id'] ?? 0) : null], $ctxKey);
    }

    // invoice: «برای علی رضایی فاکتور ثبت کن، دو عدد بذر گوجه»
    if (preg_match('/فاکتور/u', $t) && preg_match('/(ثبت|بزن|صادر|بنویس|درست کن)/u', $t)) {
        $kind = preg_match('/خرید/u', $t) ? 'purchase' : 'sale';
        $person = assistant_find_name($t, acc_all('SELECT id, name FROM acc_persons'));
        if (!$person) {
            return ['reply' => 'برای چه کسی؟ اسم طرف حساب را همان‌طور که در حسابداری ثبت شده بگو.', 'state' => 'unknown', 'data' => []];
        }
        $items = [];
        $lines = [];
        foreach (acc_all("SELECT id, name, sale_price, buy_price, unit FROM acc_products ORDER BY LENGTH(name) DESC") as $p) {
            $n = preg_quote(ba_norm_text($p['name']), '/');
            if (preg_match('/(\d+(?:\.\d+)?)\s*(?:عدد|تا|کیسه|کیلو|بسته|جعبه|کارتن|متر|شاخه)?\s*' . $n . '/u', $t, $m)
                || preg_match('/' . $n . '\s*(\d+(?:\.\d+)?)\s*(?:عدد|تا)?/u', $t, $m)) {
                $qty = (float)$m[1];
                $items[] = ['product_id' => (int)$p['id'], 'qty' => $qty, 'price' => (float)($kind === 'sale' ? $p['sale_price'] : $p['buy_price'])];
                $lines[] = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.') . ' ' . ($p['unit'] ?: 'عدد') . ' ' . $p['name'];
                $t = str_replace($m[0], ' ', $t);
            }
        }
        if (!$items) {
            return ['reply' => 'چه کالایی و چند تا؟ مثلاً «دو عدد بذر گوجه».', 'state' => 'unknown', 'data' => []];
        }
        $body = ['kind' => $kind, 'person_id' => (int)$person['id'], 'date' => acc_today(), 'items' => $items];
        $totals = acc_invoice_totals($kind, acc_invoice_lines($items), $body);
        acc_check_stock(acc_invoice_lines($items), $kind);
        return $draft('invoice', $body, 'فاکتور ' . ($kind === 'sale' ? 'فروش' : 'خرید') . ' برای ' . $person['name'] . ': ' . implode('، ', $lines)
            . '. جمع با مالیات ' . assistant_rial($totals['total']) . '. ثبت کنم؟', ['person' => $person['name'], 'items' => $lines, 'total' => $totals['total']]);
    }

    // new person: «مشتری جدید به اسم حسن کریمی با شماره 09121234567 اضافه کن»
    if (preg_match('/(اضافه کن|تعریف کن|بساز|ثبت کن)/u', $t) && preg_match('/(مشتری|تامین ?کننده|فروشنده|شخص|طرف حساب|کارمند|بازاریاب)/u', $t)
        && preg_match('/(?:به اسم|به نام|اسمش)\s+(.+?)(?:\s+(?:با|شماره|موبایل|تلفن|اضافه|تعریف|بساز|ثبت|را|رو)\b|$)/u', $t, $m)) {
        $name = trim($m[1]);
        $type = preg_match('/(تامین ?کننده|فروشنده)/u', $t) ? 'supplier' : (preg_match('/کارمند/u', $t) ? 'employee' : (preg_match('/بازاریاب/u', $t) ? 'marketer' : 'customer'));
        $mobile = preg_match('/(09\d{9})/', str_replace(' ', '', $t), $mm) ? $mm[1] : '';
        if (acc_val('SELECT 1 FROM acc_persons WHERE name = ?', [$name])) {
            return ['reply' => $name . ' از قبل در اشخاص هست.', 'state' => 'error', 'data' => []];
        }
        return $draft('person', ['name' => $name, 'type' => $type, 'mobile' => $mobile],
            ACC_PERSON_TYPES[$type] . ' جدید: ' . $name . ($mobile ? '، موبایل ' . $mobile : '') . '. اضافه کنم؟');
    }

    // SMS: «به علی رضایی پیامک یادآوری بدهی بفرست»
    if (preg_match('/(پیامک|اس ?ام ?اس)/u', $t) && preg_match('/(بفرست|بزن|ارسال)/u', $t)) {
        $p = assistant_find_name($t, acc_all('SELECT * FROM acc_persons'));
        if (!$p) {
            return ['reply' => 'پیامک برای چه کسی؟', 'state' => 'unknown', 'data' => []];
        }
        if (!acc_sms_mobile($p['mobile'])) {
            return ['reply' => 'برای ' . $p['name'] . ' شماره‌ی موبایل ثبت نشده.', 'state' => 'error', 'data' => []];
        }
        $msg = (float)$p['balance'] > 0
            ? acc_sms_render('{name} عزیز، مانده‌ی بدهی شما {balance} ریال است. لطفاً برای تسویه اقدام فرمایید. {company}', $p)
            : acc_sms_render('{name} عزیز، سلام. {company}', $p);
        return $draft('sms', ['mobile' => $p['mobile'], 'text' => $msg, 'person_id' => (int)$p['id']], 'پیامک به ' . $p['name'] . ': «' . $msg . '». بفرستم؟');
    }

    // receipt / payment / expense: «از علی رضایی ۵ میلیون نقد گرفتم»، «به پخش البرز ۳ میلیون از بانک دادم»، «۲ میلیون اجاره دادم»
    $in = preg_match('/(گرفتم|دریافت کردم|دریافت شد|واریز کرد|پرداخت کرد|داد\b|ریخت|رسید)/u', $t) && !preg_match('/(دادم|پرداخت کردم|ریختم)/u', $t);
    $out = preg_match('/(دادم|پرداخت کردم|پرداختم|ریختم|خرج کردم|هزینه کردم|واریز کردم)/u', $t);
    if (($in || $out) && ($amount = assistant_amount($t)) > 0) {
        $acc = assistant_cash_account($t);
        if (!$acc) {
            return ['reply' => 'هنوز صندوق یا بانکی تعریف نشده.', 'state' => 'error', 'data' => []];
        }
        $person = assistant_find_name($t, acc_all('SELECT id, name FROM acc_persons'));
        $body = ['kind' => $in ? 'receive' : 'pay', 'account_id' => (int)$acc['id'], 'amount' => $amount, 'date' => acc_today(), 'description' => trim($text)];
        $what = '';
        if ($person) {
            $body['person_id'] = (int)$person['id'];
            $what = ($in ? 'دریافت از ' : 'پرداخت به ') . $person['name'];
        } else {
            // no person: an expense / income type by its name («اجاره»، «حقوق»…), else general expense / other income
            $types = acc_all("SELECT id, name, code FROM acc_coa WHERE level = 'moein' AND parent_code IN ('51', '41') AND code NOT IN ('5101', '5103', '5105', '4101', '4102', '4104')");
            $type = assistant_find_name($t, $types);
            if (!$type) {   // «اجاره» is enough for «اجاره مغازه»
                foreach ($types as $ty) {
                    foreach (preg_split('/\s+/u', ba_norm_text($ty['name'])) as $w) {
                        if (mb_strlen($w) >= 3 && mb_strpos(" $t ", " $w") !== false && !in_array($w, ['هزینه', 'هزینه‌های', 'درآمد', 'سایر'], true)) {
                            $type = $ty;
                            break 2;
                        }
                    }
                }
            }
            if ($type && $type['code'] !== '5101') {
                $body['counter_account_id'] = (int)$type['id'];
                $what = ($in ? 'درآمد ' : 'هزینه‌ی ') . $type['name'];
            } else {
                $body['counter_code'] = $in ? '4103' : '5102';
                $what = $in ? 'درآمد متفرقه' : 'هزینه‌ی عمومی';
            }
        }
        return $draft('treasury', $body, $what . '، ' . assistant_rial($amount) . ($in ? ' به ' : ' از ') . $acc['name'] . '. ثبت کنم؟',
            ['amount' => $amount, 'account' => $acc['name']]);
    }

    /* ---- questions ---- */

    // overview
    if (preg_match('/(خلاصه|وضعیت|اوضاع|گزارش کلی|داشبورد)/u', $t)) {
        [$from, $to] = assistant_period('ماه');
        $sales = (float)acc_val("SELECT COALESCE(SUM(CASE kind WHEN 'sale' THEN subtotal - discount ELSE -(subtotal - discount) END), 0) FROM acc_invoices WHERE kind IN ('sale', 'sale_return') AND date BETWEEN ? AND ?", [$from, $to]);
        $cash = (float)acc_val('SELECT COALESCE(SUM(balance), 0) FROM acc_cash_accounts');
        [$recv, $pay] = acc_people_split();
        $low = (int)acc_val("SELECT COUNT(*) FROM acc_products WHERE kind != 'service' AND stock <= reorder_point");
        $pending = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
        $chq = acc_cheque_due_message(1);
        return $ok(($chq !== '' ? $chq . '. ' : '') . 'فروش این ماه ' . assistant_rial($sales) . '. موجودی صندوق و بانک ' . assistant_rial($cash) . '. طلب از دیگران ' . assistant_rial($recv)
            . ' و بدهی ' . assistant_rial($pay) . '.' . ($low ? ' ' . $low . ' کالا کم‌موجود است.' : '') . ($pending ? ' ' . $pending . ' تراکنش بانکی بی‌جواب داری.' : ''),
            ['sales_month' => $sales, 'cash' => $cash, 'receivables' => $recv, 'payables' => $pay, 'low_stock' => $low, 'pending' => $pending]);
    }

    // profit
    if (preg_match('/(سود|زیان|درآمد خالص)/u', $t)) {
        [$from, $to, $label] = assistant_period($t);
        $_GET['from'] = $from;
        $_GET['to'] = $to;
        $pl = r_profit_loss();
        return $ok(($pl['profit'] >= 0 ? 'سود ' : 'زیان ') . $label . ': ' . assistant_rial($pl['profit']) . '. درآمدها ' . assistant_rial($pl['income']) . ' و هزینه‌ها با بهای تمام‌شده ' . assistant_rial($pl['expense']) . '.',
            ['from' => $from, 'to' => $to, 'profit' => $pl['profit']]);
    }

    // purchases of a period
    if (preg_match('/(خرید|خریدیم|خریدم)/u', $t) && !preg_match('/فروش/u', $t)) {
        [$from, $to, $label] = assistant_period($t);
        $s = acc_row("SELECT COUNT(CASE WHEN kind = 'purchase' THEN 1 END) n, COALESCE(SUM(CASE kind WHEN 'purchase' THEN subtotal - discount ELSE -(subtotal - discount) END), 0) net
            FROM acc_invoices WHERE kind IN ('purchase', 'purchase_return') AND date BETWEEN ? AND ?", [$from, $to]);
        return $ok('خرید ' . $label . ': ' . assistant_rial($s['net']) . ' در ' . (int)$s['n'] . ' فاکتور، بدون مالیات.', ['net' => (float)$s['net'], 'count' => (int)$s['n']]);
    }

    // sales of a period (+ best sellers / best customers)
    if (preg_match('/(فروش|فروختیم|فروختم)/u', $t)) {
        [$from, $to, $label] = assistant_period($t);
        $s = acc_row("SELECT COUNT(CASE WHEN kind = 'sale' THEN 1 END) n,
            COALESCE(SUM(CASE kind WHEN 'sale' THEN subtotal - discount ELSE -(subtotal - discount) END), 0) net
            FROM acc_invoices WHERE kind IN ('sale', 'sale_return') AND date BETWEEN ? AND ?", [$from, $to]);
        $reply = 'فروش ' . $label . ': ' . assistant_rial($s['net']) . ' در ' . (int)$s['n'] . ' فاکتور، بدون مالیات.';
        $top = [];
        if (preg_match('/(مشتری|خریدار)/u', $t)) {
            $top = acc_all("SELECT p.name, SUM(i.subtotal - i.discount) amount FROM acc_invoices i JOIN acc_persons p ON p.id = i.person_id
                WHERE i.kind = 'sale' AND i.date BETWEEN ? AND ? GROUP BY p.id ORDER BY amount DESC LIMIT 3", [$from, $to]);
            $reply .= $top ? ' بهترین مشتری‌ها: ' . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['amount']), $top)) . '.' : '';
        } elseif (preg_match('/(گزارش|کالا|بیشتر|پرفروش)/u', $t) && (int)$s['n'] > 0) {
            $top = acc_all("SELECT p.name, SUM(it.qty * it.price) amount FROM acc_invoice_items it JOIN acc_invoices i ON i.id = it.invoice_id
                JOIN acc_products p ON p.id = it.product_id WHERE i.kind = 'sale' AND i.date BETWEEN ? AND ? GROUP BY p.id ORDER BY amount DESC LIMIT 3", [$from, $to]);
            $reply .= $top ? ' پرفروش‌ترین‌ها: ' . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['amount']), $top)) . '.' : '';
        }
        return $ok($reply, ['from' => $from, 'to' => $to, 'net' => (float)$s['net'], 'count' => (int)$s['n'], 'top' => $top]);
    }

    // cheques
    if (preg_match('/چک/u', $t)) {
        $days = preg_match('/(امروز)/u', $t) ? 0 : (preg_match('/ماه/u', $t) ? 30 : 7);
        $until = acc_jalali_fmt(...ba_g2j(...array_map('intval', explode('-', date('Y-m-d', strtotime("+$days days"))))));
        $rec = acc_all("SELECT c.number, c.amount, c.due_date, p.name FROM acc_cheques c LEFT JOIN acc_persons p ON p.id = c.person_id
            WHERE direction = 'received' AND status IN ('in_hand', 'deposited') AND due_date <= ? ORDER BY due_date", [$until]);
        $pay = acc_all("SELECT c.number, c.amount, c.due_date, p.name FROM acc_cheques c LEFT JOIN acc_persons p ON p.id = c.person_id
            WHERE direction = 'payable' AND status = 'issued' AND due_date <= ? ORDER BY due_date", [$until]);
        $f = fn($rows) => implode('، ', array_map(fn($c) => $c['name'] . ' ' . assistant_rial($c['amount']) . ' تاریخ ' . $c['due_date'], array_slice($rows, 0, 3)));
        $label = $days === 0 ? 'امروز' : ($days === 30 ? 'تا یک ماه دیگر' : 'تا یک هفته دیگر');
        return $ok(!$rec && !$pay ? 'چکی ' . $label . ' سررسید نمی‌شود.' : 'چک‌های ' . $label . ': ' . ($rec ? count($rec) . ' چک دریافتی جمعاً ' . assistant_rial(array_sum(array_column($rec, 'amount'))) . ' (' . $f($rec) . ')' : 'چک دریافتی نیست')
            . '؛ ' . ($pay ? count($pay) . ' چک پرداختی جمعاً ' . assistant_rial(array_sum(array_column($pay, 'amount'))) . ' (' . $f($pay) . ')' : 'چک پرداختی نیست') . '.',
            ['received' => $rec, 'payable' => $pay]);
    }

    // unpaid / due invoices
    if (preg_match('/(سررسید|تسویه نشده|پرداخت نشده|نسیه|مانده فاکتور|عقب افتاده)/u', $t)) {
        $rows = r_report_due_invoices();
        $sales = array_filter($rows, fn($r) => $r['kind'] === 'sale');
        $late = array_filter($sales, fn($r) => $r['overdue']);
        return $ok(count($sales) . ' فاکتور فروش تسویه‌نشده جمعاً ' . assistant_rial(array_sum(array_column($sales, 'remaining'))) . '؛ ' . count($late) . ' تا از سررسید گذشته'
            . ($late ? ': ' . implode('، ', array_map(fn($r) => $r['person_name'] . ' ' . assistant_rial($r['remaining']), array_slice(array_values($late), 0, 3))) : '') . '.',
            ['open' => count($sales), 'overdue' => count($late)]);
    }

    // VAT
    if (preg_match('/(مالیات|ارزش افزوده|مودیان)/u', $t)) {
        $r = r_tax_report();
        return $ok('مالیات ارزش افزوده‌ی فروش ' . assistant_rial($r['vat_sale']) . '، خرید ' . assistant_rial($r['vat_purchase']) . '، قابل پرداخت ' . assistant_rial($r['vat_payable']) . '.'
            . ($r['ready'] ? ' ' . $r['ready'] . ' صورتحساب آماده‌ی ارسال به سامانه مؤدیان است.' : ''), $r);
    }

    // loans
    if (preg_match('/(وام|قسط|اقساط)/u', $t)) {
        $next = acc_all("SELECT i.due_date, i.principal + i.interest amount, l.direction FROM acc_loan_installments i JOIN acc_loans l ON l.id = i.loan_id WHERE i.paid = 0 ORDER BY i.due_date LIMIT 3");
        return $ok($next ? 'اقساط بعدی: ' . implode('، ', array_map(fn($r) => assistant_rial($r['amount']) . ' تاریخ ' . $r['due_date'] . ($r['direction'] === 'given' ? ' (دریافتی)' : ''), $next)) . '.' : 'قسط پرداخت‌نشده‌ای نیست.', ['next' => $next]);
    }

    // last invoices
    if (preg_match('/(آخرین|اخرین)\s*فاکتور/u', $t)) {
        $rows = acc_all("SELECT i.number, i.total, i.date, p.name FROM acc_invoices i LEFT JOIN acc_persons p ON p.id = i.person_id WHERE i.kind = 'sale' ORDER BY i.id DESC LIMIT 3");
        return $ok($rows ? 'آخرین فاکتورهای فروش: ' . implode('، ', array_map(fn($r) => $r['number'] . ' ' . $r['name'] . ' ' . assistant_rial($r['total']), $rows)) . '.' : 'هنوز فاکتور فروشی نیست.', ['invoices' => $rows]);
    }

    // stock: one product or the whole warehouse
    if (preg_match('/(موجودی|انبار|چند تا داریم|چقدر داریم|کم ?موجود)/u', $t) && !preg_match('/(بانک|حساب|صندوق|پول)/u', $t)) {
        $p = assistant_find_name($t, acc_all("SELECT id, name, stock, unit, reorder_point FROM acc_products WHERE kind != 'service'"));
        if ($p) {
            return $ok('موجودی ' . $p['name'] . ': ' . rtrim(rtrim(number_format((float)$p['stock'], 2, '.', ''), '0'), '.') . ' ' . ($p['unit'] ?: 'عدد')
                . ((float)$p['stock'] <= (float)$p['reorder_point'] ? '؛ به نقطه‌ی سفارش رسیده.' : '.'), ['product' => $p['name'], 'stock' => (float)$p['stock']]);
        }
        $value = (float)acc_val("SELECT COALESCE(SUM(stock * CASE WHEN avg_cost > 0 THEN avg_cost ELSE buy_price END), 0) FROM acc_products WHERE kind != 'service'");
        $low = acc_all("SELECT name FROM acc_products WHERE kind != 'service' AND stock <= reorder_point ORDER BY stock LIMIT 5");
        return $ok('ارزش موجودی انبار ' . assistant_rial($value) . '.' . ($low ? ' کم‌موجودها: ' . implode('، ', array_column($low, 'name')) . '.' : ' همه‌ی کالاها موجودی کافی دارند.'),
            ['value' => $value, 'low' => array_column($low, 'name')]);
    }

    // money in the accounts
    if (preg_match('/(بانک|صندوق|نقدینگی|پول|موجودی حساب)/u', $t)) {
        $rows = acc_all('SELECT name, balance FROM acc_cash_accounts ORDER BY id');
        $sum = array_sum(array_column($rows, 'balance'));
        return $ok('موجودی همه‌ی حساب‌ها ' . assistant_rial($sum) . ($sum < 0 ? ' منفی' : '') . '. '
            . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['balance']), $rows)) . '.', ['total' => $sum, 'accounts' => $rows]);
    }

    // what someone owes / is owed
    if (preg_match('/(طلب|بدهی|بدهکار|بستانکار|حساب)/u', $t)) {
        $p = assistant_find_name($t, acc_all('SELECT id, name, balance FROM acc_persons'));
        if ($p) {
            $b = (float)$p['balance'];
            return $ok(abs($b) < 1 ? 'حساب ' . $p['name'] . ' صاف است.' : ($b > 0 ? $p['name'] . ' ' . assistant_rial($b) . ' به ما بدهکار است.' : 'ما ' . assistant_rial($b) . ' به ' . $p['name'] . ' بدهکاریم.'),
                ['person' => $p['name'], 'balance' => $b]);
        }
        [$recv, $pay] = acc_people_split();
        $top = acc_all('SELECT name, balance FROM acc_persons WHERE balance > 0 ORDER BY balance DESC LIMIT 3');
        return $ok('طلب ما از دیگران ' . assistant_rial($recv) . ' و بدهی ما ' . assistant_rial($pay) . '.'
            . ($top ? ' بیشترین بدهکارها: ' . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['balance']), $top)) . '.' : ''),
            ['receivables' => $recv, 'payables' => $pay]);
    }

    // bank SMS still waiting for an answer: ask about the oldest one, the next sentence answers it
    if (preg_match('/(بی ?جواب|تراکنش|واریز|برداشت)/u', $t)) {
        $tx = ba_db()->query("SELECT * FROM transactions WHERE status = 'pending' ORDER BY occurred_at, id LIMIT 1")->fetch();
        if (!$tx) {
            return $ok('همه‌ی تراکنش‌های بانک جواب گرفته‌اند.', ['pending' => 0]);
        }
        $n = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
        ba_kv_set($ctxKey, ['exp' => time() + ASSISTANT_DRAFT_TTL, 'type' => 'bank_tx', 'body' => ['id' => (int)$tx['id']]]);
        return ['reply' => $n . ' تراکنش بی‌جواب داری. ' . ($tx['direction'] === 'in' ? 'واریز ' : 'برداشت ') . assistant_rial($tx['amount'])
            . ($tx['bank_date'] ? ' تاریخ ' . $tx['bank_date'] : '') . '. بابت چی بود؟', 'state' => 'confirm', 'data' => ['pending' => $n, 'id' => (int)$tx['id']]];
    }

    return ['reply' => 'متوجه نشدم. مثلاً بپرس: «خلاصه وضعیت»، «فروش امروز»، «سود این ماه»، «موجودی انبار»، «حساب علی رضایی»، «چک‌های این هفته»، «فاکتورهای سررسید»، '
        . 'یا بگو: «برای علی رضایی فاکتور ثبت کن، دو عدد بذر گوجه»، «از علی رضایی پنج میلیون نقد گرفتم»، «دو میلیون اجاره دادم»، «به علی رضایی پیامک یادآوری بفرست».',
        'state' => 'unknown', 'data' => []];
}
