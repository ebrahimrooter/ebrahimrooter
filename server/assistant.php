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
    $token = bin2hex(random_bytes(32));
    $name = mb_substr(trim((string)$name) ?: 'iPhone', 0, 60);
    ba_db()->prepare('INSERT INTO assistant_devices (name, token_hash, created_at) VALUES (?, ?, ?)')
        ->execute([$name, hash('sha256', $token), date('Y-m-d H:i:s')]);
    return ['token' => $token, 'device_id' => (int)ba_db()->lastInsertId(), 'name' => $name];
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
 * One turn of the conversation. $device holds the pending invoice draft, so a
 * following «آره» / «نه» confirms or drops it.
 * Returns ['reply' => text, 'state' => answered|confirm|unknown, 'data' => [...]].
 */
function assistant_answer($text, array $device)
{
    require_once __DIR__ . '/acc_api.php';
    acc_use_company(1);
    acc_db();
    $t = assistant_number_words(ba_norm_text($text));
    $draftKey = 'assistant_draft:' . $device['id'];
    $draft = ba_kv_get($draftKey);
    if ($draft && $draft['exp'] < time()) {
        ba_kv_set($draftKey, null);
        $draft = null;
    }

    // a pending invoice: yes / no
    if ($draft && preg_match('/^(آره|اره|بله|آری|باشه|ثبت کن|تایید|اوکی|ok|yes)\b/u', $t)) {
        ba_kv_set($draftKey, null);
        $inv = acc_invoice_save(null, $draft['body']);
        acc_log('assistant:' . $device['name'], 'create_invoice', $inv['number']);
        if (function_exists('acc_sms_after_invoice')) {
            acc_sms_after_invoice($inv);
        }
        return ['reply' => 'فاکتور ' . $inv['number'] . ' ثبت شد. جمع ' . assistant_rial($inv['total']) . '.', 'state' => 'answered',
            'data' => ['invoice_id' => (int)$inv['id'], 'number' => $inv['number'], 'total' => (float)$inv['total']]];
    }
    if ($draft && preg_match('/^(نه|نخیر|لغو|کنسل|ولش کن|نمی ?خواد)\b/u', $t)) {
        ba_kv_set($draftKey, null);
        return ['reply' => 'باشه، فاکتور ثبت نشد.', 'state' => 'answered', 'data' => []];
    }

    // invoice for someone: «برای علی رضایی فاکتور ثبت کن، دو عدد بذر گوجه»
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
                $price = (float)($kind === 'sale' ? $p['sale_price'] : $p['buy_price']);
                $items[] = ['product_id' => (int)$p['id'], 'qty' => $qty, 'price' => $price];
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
        ba_kv_set($draftKey, ['exp' => time() + ASSISTANT_DRAFT_TTL, 'body' => $body]);
        return ['reply' => 'فاکتور ' . ($kind === 'sale' ? 'فروش' : 'خرید') . ' برای ' . $person['name'] . ': ' . implode('، ', $lines)
            . '. جمع با مالیات ' . assistant_rial($totals['total']) . '. ثبت کنم؟', 'state' => 'confirm',
            'data' => ['person' => $person['name'], 'items' => $lines, 'total' => $totals['total']]];
    }

    // sales report of a period
    if (preg_match('/(فروش|فروختیم|فروختم)/u', $t)) {
        [$from, $to, $label] = assistant_period($t);
        $s = acc_row("SELECT COUNT(CASE WHEN kind = 'sale' THEN 1 END) n,
            COALESCE(SUM(CASE kind WHEN 'sale' THEN subtotal - discount ELSE -(subtotal - discount) END), 0) net
            FROM acc_invoices WHERE kind IN ('sale', 'sale_return') AND date BETWEEN ? AND ?", [$from, $to]);
        $reply = 'فروش ' . $label . ': ' . assistant_rial($s['net']) . ' در ' . (int)$s['n'] . ' فاکتور، بدون مالیات.';
        $top = [];
        if (preg_match('/(گزارش|کالا|بیشتر|پرفروش)/u', $t) && (int)$s['n'] > 0) {
            $top = acc_all("SELECT p.name, SUM(it.qty * it.price) amount FROM acc_invoice_items it JOIN acc_invoices i ON i.id = it.invoice_id
                JOIN acc_products p ON p.id = it.product_id WHERE i.kind = 'sale' AND i.date BETWEEN ? AND ? GROUP BY p.id ORDER BY amount DESC LIMIT 3", [$from, $to]);
            if ($top) {
                $reply .= ' پرفروش‌ترین‌ها: ' . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['amount']), $top)) . '.';
            }
        }
        return ['reply' => $reply, 'state' => 'answered', 'data' => ['from' => $from, 'to' => $to, 'net' => (float)$s['net'], 'count' => (int)$s['n'], 'top' => $top]];
    }

    // stock: one product or the whole warehouse
    if (preg_match('/(موجودی|انبار|چند تا داریم|چقدر داریم)/u', $t) && !preg_match('/(بانک|حساب|صندوق|پول)/u', $t)) {
        $p = assistant_find_name($t, acc_all("SELECT id, name, stock, unit, reorder_point FROM acc_products WHERE kind != 'service'"));
        if ($p) {
            return ['reply' => 'موجودی ' . $p['name'] . ': ' . rtrim(rtrim(number_format((float)$p['stock'], 2, '.', ''), '0'), '.') . ' ' . ($p['unit'] ?: 'عدد')
                . ((float)$p['stock'] <= (float)$p['reorder_point'] ? '؛ به نقطه‌ی سفارش رسیده.' : '.'), 'state' => 'answered',
                'data' => ['product' => $p['name'], 'stock' => (float)$p['stock']]];
        }
        $value = (float)acc_val("SELECT COALESCE(SUM(stock * CASE WHEN avg_cost > 0 THEN avg_cost ELSE buy_price END), 0) FROM acc_products WHERE kind != 'service'");
        $low = acc_all("SELECT name FROM acc_products WHERE kind != 'service' AND stock <= reorder_point ORDER BY stock LIMIT 5");
        return ['reply' => 'ارزش موجودی انبار ' . assistant_rial($value) . '.' . ($low ? ' کم‌موجودها: ' . implode('، ', array_column($low, 'name')) . '.' : ' همه‌ی کالاها موجودی کافی دارند.'),
            'state' => 'answered', 'data' => ['value' => $value, 'low' => array_column($low, 'name')]];
    }

    // money in the accounts
    if (preg_match('/(بانک|صندوق|نقدینگی|پول|موجودی حساب)/u', $t)) {
        $rows = acc_all('SELECT name, balance FROM acc_cash_accounts ORDER BY id');
        $sum = array_sum(array_column($rows, 'balance'));
        return ['reply' => 'موجودی همه‌ی حساب‌ها ' . assistant_rial($sum) . ($sum < 0 ? ' منفی' : '') . '. '
            . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['balance']), $rows)) . '.', 'state' => 'answered',
            'data' => ['total' => $sum, 'accounts' => $rows]];
    }

    // what someone owes / is owed
    if (preg_match('/(طلب|بدهی|بدهکار|بستانکار|حساب)/u', $t)) {
        $p = assistant_find_name($t, acc_all('SELECT id, name, balance FROM acc_persons'));
        if ($p) {
            $b = (float)$p['balance'];
            return ['reply' => abs($b) < 1 ? 'حساب ' . $p['name'] . ' صاف است.' : ($b > 0 ? $p['name'] . ' ' . assistant_rial($b) . ' به ما بدهکار است.' : 'ما ' . assistant_rial($b) . ' به ' . $p['name'] . ' بدهکاریم.'),
                'state' => 'answered', 'data' => ['person' => $p['name'], 'balance' => $b]];
        }
        [$recv, $pay] = acc_people_split();
        $top = acc_all('SELECT name, balance FROM acc_persons WHERE balance > 0 ORDER BY balance DESC LIMIT 3');
        return ['reply' => 'طلب ما از دیگران ' . assistant_rial($recv) . ' و بدهی ما ' . assistant_rial($pay) . '.'
            . ($top ? ' بیشترین بدهکارها: ' . implode('، ', array_map(fn($r) => $r['name'] . ' ' . assistant_rial($r['balance']), $top)) . '.' : ''),
            'state' => 'answered', 'data' => ['receivables' => $recv, 'payables' => $pay]];
    }

    // bank SMS still waiting for an answer
    if (preg_match('/(بی ?جواب|تراکنش|واریز|برداشت)/u', $t)) {
        $n = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
        return ['reply' => $n ? $n . ' تراکنش بانکی بی‌جواب داری.' : 'همه‌ی تراکنش‌های بانک جواب گرفته‌اند.', 'state' => 'answered', 'data' => ['pending' => $n]];
    }

    return ['reply' => 'متوجه نشدم. می‌توانی بپرسی: «فروش امروز چقدر بوده؟»، «موجودی انبار را بگو»، «گزارش فروش این ماه»، «حساب علی رضایی» یا «برای علی رضایی فاکتور ثبت کن، دو عدد بذر گوجه».',
        'state' => 'unknown', 'data' => []];
}
