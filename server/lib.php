<?php
/**
 * Bank assistant - shared core: config, SQLite, SMS parser, Jalali dates,
 * balance-chain check and statement reconciliation.
 *
 * Amounts are stored in RIAL as integers (that is what the bank SMS carries);
 * the app shows them in toman.
 */

define('BA_ROOT', __DIR__);
define('BA_DB_PATH', BA_ROOT . '/data/bank.sqlite');

// Settings the owner can change from the app (stored in the database,
// override config.php) - so nothing has to be edited by hand on a host.
const BA_APP_SETTINGS = ['bale_bot_token', 'bale_chat_id', 'otp_to_bale', 'api_url', 'app_url'];

function ba_config($reload = false) {
    static $cfg = null;
    if ($cfg === null || $reload) {
        $file = BA_ROOT . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('config.php پیدا نشد. install.php را باز کن (یا از config.sample.php یک کپی بساز).');
        }
        $cfg = require $file;
        date_default_timezone_set($cfg['timezone'] ?? 'Asia/Tehran');
        foreach ((array)ba_kv_get('config_overrides', []) as $k => $v) {
            if (in_array($k, BA_APP_SETTINGS, true)) {
                $cfg[$k] = $v;
            }
        }
    }
    return $cfg;
}

/** Saves settings changed in the app and reloads the configuration. */
function ba_settings_save(array $changes) {
    $over = (array)ba_kv_get('config_overrides', []);
    foreach ($changes as $k => $v) {
        if (in_array($k, BA_APP_SETTINGS, true)) {
            $over[$k] = $v;
        }
    }
    ba_kv_set('config_overrides', $over);
    return ba_config(true);
}

function ba_db() {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    if (!is_dir(dirname(BA_DB_PATH))) {
        mkdir(dirname(BA_DB_PATH), 0775, true);
    }
    $pdo = new PDO('sqlite:' . BA_DB_PATH);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    $pdo->exec('PRAGMA secure_delete = ON');    // deleted OTPs are overwritten, not left in free pages
    $pdo->exec("CREATE TABLE IF NOT EXISTS sms_raw (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sender TEXT,
        body TEXT NOT NULL,
        modem_time TEXT,
        received_at TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'new'      -- new | parsed | ignored | merged | otp | otp_part
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS categories (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        direction TEXT NOT NULL DEFAULT 'both', -- in | out | both
        keywords TEXT NOT NULL DEFAULT '',      -- comma separated words heard in answers
        sort_order INTEGER DEFAULT 0
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS transactions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sms_id INTEGER REFERENCES sms_raw(id),
        source TEXT NOT NULL DEFAULT 'sms',     -- sms | manual
        direction TEXT NOT NULL,                -- in | out
        amount INTEGER NOT NULL,                -- rial
        balance INTEGER,                        -- rial, balance after this transaction (from SMS)
        account TEXT,
        bank_date TEXT,                         -- Jalali YYYY/MM/DD as the bank wrote it
        bank_time TEXT,
        occurred_at TEXT NOT NULL,              -- Gregorian 'Y-m-d H:i:s' (for sorting / matching)
        status TEXT NOT NULL DEFAULT 'pending', -- pending | confirmed | ignored
        description TEXT,
        party TEXT,
        category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
        note TEXT,
        confirmed_at TEXT,
        synced INTEGER NOT NULL DEFAULT 0,      -- pushed to accounting webhook
        dedup_key TEXT UNIQUE
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS parties (
        name TEXT PRIMARY KEY,
        uses INTEGER NOT NULL DEFAULT 1,
        last_category_id INTEGER
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS reconciliations (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        created_at TEXT NOT NULL,
        kind TEXT NOT NULL,                     -- balance | statement
        date_from TEXT,
        date_to TEXT,
        ok INTEGER NOT NULL,
        report TEXT NOT NULL                    -- JSON
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS kv (
        k TEXT PRIMARY KEY,
        v TEXT
    )");
    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_messages (
        message_id INTEGER PRIMARY KEY,         -- Bale message we sent
        tx_id INTEGER NOT NULL                  -- transaction it asked about
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS tx_occurred ON transactions(occurred_at)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS tx_status ON transactions(status)');

    $pdo->exec("CREATE TABLE IF NOT EXISTS otps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        sms_id INTEGER,
        created_at TEXT NOT NULL,
        expires_at INTEGER NOT NULL,            -- unix time; row is deleted after this
        code_enc TEXT NOT NULL,                 -- encrypted, never stored in plain text
        amount INTEGER,                         -- rial, if the SMS said
        merchant TEXT,
        seen_at TEXT
    )");
    // Money boxes: the bank account the SMS come from, cash, other accounts.
    $pdo->exec("CREATE TABLE IF NOT EXISTS wallets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        kind TEXT NOT NULL DEFAULT 'bank',      -- bank | cash
        opening INTEGER NOT NULL DEFAULT 0,     -- rial
        is_sms INTEGER NOT NULL DEFAULT 0       -- SMS transactions land here
    )");
    // Credit documents: sold to / bought from someone without money moving yet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS bills (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        date TEXT NOT NULL,                     -- Y-m-d
        party TEXT NOT NULL,
        type TEXT NOT NULL,                     -- sale (they owe me) | purchase (I owe them)
        amount INTEGER NOT NULL,                -- rial
        category_id INTEGER REFERENCES categories(id) ON DELETE SET NULL,
        description TEXT,
        due_date TEXT,
        created_at TEXT NOT NULL
    )");
    $pdo->exec('CREATE INDEX IF NOT EXISTS bills_party ON bills(party)');
    // Phones subscribed to notifications (Web Push).
    $pdo->exec("CREATE TABLE IF NOT EXISTS push_subs (
        endpoint TEXT PRIMARY KEY,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        device TEXT,
        created_at TEXT NOT NULL,
        last_ok TEXT
    )");

    // Columns added after the first release (existing databases get them here).
    $cols = function ($table) use ($pdo) {
        return array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(), 'name');
    };
    $fresh_categories = !$pdo->query('SELECT COUNT(*) FROM categories')->fetchColumn();
    if (!in_array('kind', $cols('categories'), true)) {
        // pl = income/expense, party = settles a person's account, transfer = between my own wallets
        $pdo->exec("ALTER TABLE categories ADD COLUMN kind TEXT NOT NULL DEFAULT 'pl'");
        if (!$fresh_categories) {
            $pdo->exec("UPDATE categories SET kind = 'party', name = 'پرداخت به اشخاص (حواله، قرض، تسویه)',
                keywords = 'حواله,کارت به کارت,انتقال,پایا,ساتنا,قرض,تسویه,قسط,علی الحساب,بدهی,طلب'
                WHERE name = 'حواله / انتقال به اشخاص'");
            $pdo->exec("UPDATE categories SET kind = 'party', name = 'دریافت از اشخاص (تسویه، طلب)',
                keywords = 'طلب,بدهی,تسویه,قسط,علی الحساب,برگشت,پس داد,قرض,حواله,کارت به کارت'
                WHERE name = 'دریافت از اشخاص'");
            $pdo->exec("INSERT INTO categories (name, direction, keywords, sort_order, kind) VALUES
                ('انتقال بین حساب‌های خودم', 'both', 'خودپرداز,عابربانک,عابر بانک,نقد کردم,صندوق,واریز نقدی,حساب خودم', 50, 'transfer')");
        }
    }
    if (!in_array('wallet_id', $cols('transactions'), true)) {
        $pdo->exec('ALTER TABLE transactions ADD COLUMN wallet_id INTEGER NOT NULL DEFAULT 1');
        $pdo->exec('ALTER TABLE transactions ADD COLUMN counter_wallet_id INTEGER');
    }
    if (!in_array('opening', $cols('parties'), true)) {
        $pdo->exec("ALTER TABLE parties ADD COLUMN phone TEXT");
        $pdo->exec("ALTER TABLE parties ADD COLUMN note TEXT");
        $pdo->exec("ALTER TABLE parties ADD COLUMN opening INTEGER NOT NULL DEFAULT 0");   // + they owe me, - I owe them
    }

    if ($fresh_categories) {
        $seed = [
            // name, direction, keywords, kind
            ['پرداخت به اشخاص (حواله، قرض، تسویه)', 'out', 'حواله,کارت به کارت,انتقال,پایا,ساتنا,قرض,تسویه,قسط,علی الحساب,بدهی,طلب', 'party'],
            ['خرید کالا و مواد', 'out', 'خرید,جنس,کالا,مواد,بار,بذر', 'pl'],
            ['حقوق و دستمزد', 'out', 'حقوق,دستمزد,کارگر,مزد', 'pl'],
            ['اجاره', 'out', 'اجاره,رهن', 'pl'],
            ['حمل و نقل', 'out', 'کرایه,باربری,پیک,حمل,ماشین', 'pl'],
            ['قبض و شارژ', 'out', 'قبض,برق,آب,گاز,تلفن,اینترنت,شارژ', 'pl'],
            ['هزینه شخصی / برداشت شخصی', 'out', 'شخصی,خانه,خونه,رستوران,بنزین', 'pl'],
            ['کارمزد بانکی', 'out', 'کارمزد', 'pl'],
            ['فروش', 'in', 'فروش,مشتری,فاکتور,سفارش', 'pl'],
            ['دریافت از اشخاص (تسویه، طلب)', 'in', 'طلب,بدهی,تسویه,قسط,علی الحساب,برگشت,پس داد,قرض,حواله,کارت به کارت', 'party'],
            ['سود بانکی', 'in', 'سود', 'pl'],
            ['انتقال بین حساب‌های خودم', 'both', 'خودپرداز,عابربانک,عابر بانک,نقد کردم,صندوق,واریز نقدی,حساب خودم', 'transfer'],
            ['سایر', 'both', 'سایر,متفرقه', 'pl'],
        ];
        $ins = $pdo->prepare('INSERT INTO categories (name, direction, keywords, sort_order, kind) VALUES (?, ?, ?, ?, ?)');
        foreach ($seed as $i => $row) {
            $ins->execute([$row[0], $row[1], $row[2], $i, $row[3]]);
        }
    }
    if (!$pdo->query('SELECT COUNT(*) FROM wallets')->fetchColumn()) {
        $pdo->exec("INSERT INTO wallets (id, name, kind, is_sms) VALUES (1, 'بانک ملت', 'bank', 1), (2, 'صندوق (نقد)', 'cash', 0)");
    }
    return $pdo;
}

/* ------------------------------------------------------------------ */
/* Text helpers                                                        */
/* ------------------------------------------------------------------ */

/**
 * Persian/Arabic digits -> ASCII, Arabic letters -> Persian and, unless
 * $keep_separators, strip thousand separators (not for CSV: there a comma
 * between "1" and "1405/..." is a column break).
 */
function ba_normalize($text, $keep_separators = false) {
    $text = strtr($text, [
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
        '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
        '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        'ي' => 'ی', 'ك' => 'ک', "\u{200C}" => ' ', "\r" => '',
    ]);
    if ($keep_separators) {
        return $text;
    }
    // 1,250,000 / 1٬250٬000 / 1،250،000 -> 1250000
    return preg_replace('/(?<=\d)[,٬،](?=\d{3})/u', '', $text);
}

/** SIM800/A7670 in UCS2 mode hand over text as hex of UTF-16BE. */
function ba_decode_ucs2_hex($hex) {
    $hex = preg_replace('/[^0-9A-Fa-f]/', '', $hex);
    if ($hex === '' || strlen($hex) % 4 !== 0) {
        return $hex;
    }
    return mb_convert_encoding(hex2bin($hex), 'UTF-8', 'UTF-16BE');
}

/* ------------------------------------------------------------------ */
/* Jalali <-> Gregorian (jdf algorithm)                                */
/* ------------------------------------------------------------------ */

function ba_g2j($gy, $gm, $gd) {
    $g_d_m = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
    $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
        + intdiv($gy2 + 399, 400) + $gd + $g_d_m[$gm - 1];
    $jy = -1595 + (33 * intdiv($days, 12053));
    $days %= 12053;
    $jy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $jy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    if ($days < 186) {
        $jm = 1 + intdiv($days, 31);
        $jd = 1 + ($days % 31);
    } else {
        $jm = 7 + intdiv($days - 186, 30);
        $jd = 1 + (($days - 186) % 30);
    }
    return [$jy, $jm, $jd];
}

function ba_j2g($jy, $jm, $jd) {
    $jy += 1595;
    $days = -355668 + (365 * $jy) + (intdiv($jy, 33) * 8) + intdiv(($jy % 33) + 3, 4) + $jd
        + (($jm < 7) ? ($jm - 1) * 31 : (($jm - 7) * 30) + 186);
    $gy = 400 * intdiv($days, 146097);
    $days %= 146097;
    if ($days > 36524) {
        $days--;
        $gy += 100 * intdiv($days, 36524);
        $days %= 36524;
        if ($days >= 365) {
            $days++;
        }
    }
    $gy += 4 * intdiv($days, 1461);
    $days %= 1461;
    if ($days > 365) {
        $gy += intdiv($days - 1, 365);
        $days = ($days - 1) % 365;
    }
    $gd = $days + 1;
    $leap = ($gy % 4 == 0 && $gy % 100 != 0) || ($gy % 400 == 0);
    $months = [0, 31, $leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    for ($gm = 0; $gm < 13 && $gd > $months[$gm]; $gm++) {
        $gd -= $months[$gm];
    }
    return [$gy, $gm, $gd];
}

/** 'YYYY/MM/DD' Jalali -> 'Y-m-d' Gregorian, or null. */
function ba_jalali_to_iso($jdate) {
    if (!preg_match('~^(\d{2,4})[/\-.](\d{1,2})[/\-.](\d{1,2})$~', trim(ba_normalize($jdate)), $m)) {
        return null;
    }
    $jy = (int)$m[1];
    if ($jy < 100) {
        $jy += 1400;
    }
    [$gy, $gm, $gd] = ba_j2g($jy, (int)$m[2], (int)$m[3]);
    return sprintf('%04d-%02d-%02d', $gy, $gm, $gd);
}

/** 'Y-m-d' Gregorian -> 'YYYY/MM/DD' Jalali. */
function ba_iso_to_jalali($iso) {
    [$jy, $jm, $jd] = ba_g2j((int)substr($iso, 0, 4), (int)substr($iso, 5, 2), (int)substr($iso, 8, 2));
    return sprintf('%04d/%02d/%02d', $jy, $jm, $jd);
}

function ba_today_jalali() {
    [$jy, $jm, $jd] = ba_g2j((int)date('Y'), (int)date('n'), (int)date('j'));
    return [$jy, $jm, $jd];
}

/* ------------------------------------------------------------------ */
/* SMS parser                                                          */
/* ------------------------------------------------------------------ */

/**
 * Pulls direction / amount / balance / account / date out of a bank SMS.
 * Written for Bank Mellat's formats but keyword based, so most Iranian
 * banks work too. Returns null if it isn't a transaction SMS
 * (OTP codes, ads, ...).
 *
 * Handles e.g.
 *   "بانک ملت\nبرداشت:1,250,000\nحساب:1234\nمانده:8,420,000\n0707-14:25"
 *   "واریز 5,000,000 ریال به حساب 12345 مانده 13,420,000 1405/07/07 09:10"
 *   "حساب 1234\nمبلغ:250,000-\nمانده:..."
 */
function ba_parse_sms($text) {
    $t = ba_normalize($text);

    $in_words = 'واریز|بستانکار|انتقال به حساب شما|نشست|سود';
    $out_words = 'برداشت|بدهکار|خرید|انتقال از|کارمزد|پرداخت';

    $direction = null;
    $amount = null;

    // 1) keyword directly followed by the amount: "برداشت:1250000" / "مبلغ: 250000-"
    if (preg_match('/(' . $in_words . '|' . $out_words . '|مبلغ)\s*[:：]?\s*([+\-]?)\s*(\d+)\s*([+\-]?)/u', $t, $m)) {
        $amount = (int)$m[3];
        $sign = $m[2] !== '' ? $m[2] : $m[4];
        if ($sign === '+') {
            $direction = 'in';
        } elseif ($sign === '-') {
            $direction = 'out';
        } elseif (preg_match('/^(' . $in_words . ')$/u', $m[1])) {
            $direction = 'in';
        } elseif (preg_match('/^(' . $out_words . ')$/u', $m[1])) {
            $direction = 'out';
        }
    }
    // 2) direction from anywhere in the text ("مبلغ" gave no hint)
    if ($amount !== null && $direction === null) {
        if (preg_match('/' . $in_words . '/u', $t)) {
            $direction = 'in';
        } elseif (preg_match('/' . $out_words . '/u', $t)) {
            $direction = 'out';
        }
    }
    if ($amount === null || $amount <= 0 || $direction === null) {
        return null;
    }

    $balance = null;
    if (preg_match('/(?:مانده|موجودی)\s*[:：]?\s*([+\-]?)\s*(\d+)\s*([+\-]?)/u', $t, $m)) {
        $balance = (int)$m[2];
        if ($m[1] === '-' || $m[3] === '-') {
            $balance = -$balance;
        }
    }

    $account = null;
    if (preg_match('/(?:حساب|سپرده|کارت)\s*[:：]?\s*(?:شماره\s*)?([0-9*.\-]{4,})/u', $t, $m)) {
        $account = trim($m[1], '.-');
    }

    $jdate = null;
    $time = null;
    if (preg_match('~(1[34]\d{2}|\d{2})/(\d{1,2})/(\d{1,2})~', $t, $m)) {
        $jy = (int)$m[1];
        if ($jy < 100) {
            $jy += 1400;
        }
        $jdate = sprintf('%04d/%02d/%02d', $jy, $m[2], $m[3]);
    } elseif (preg_match('/(?<!\d)(\d{2})(\d{2})-(\d{1,2}):(\d{2})(?!\d)/', $t, $m)) {
        // Mellat style "0707-14:25" = month 07, day 07, no year.
        [$cy, $cm] = ba_today_jalali();
        $jm = (int)$m[1];
        $jy = $jm > $cm ? $cy - 1 : $cy;   // December SMS read in January etc.
        $jdate = sprintf('%04d/%02d/%02d', $jy, $jm, $m[2]);
        $time = sprintf('%02d:%02d', $m[3], $m[4]);
    }
    if ($time === null && preg_match('/(?<!\d)(\d{1,2}):(\d{2})(?!\d)/', $t, $m)) {
        $time = sprintf('%02d:%02d', $m[1], $m[2]);
    }

    return [
        'direction' => $direction,
        'amount' => $amount,
        'balance' => $balance,
        'account' => $account,
        'bank_date' => $jdate,
        'bank_time' => $time,
    ];
}

/**
 * Sender allowed? The list chosen in the app (settings > bank senders) wins
 * over config allowed_senders. Empty = accept everyone, parser filters.
 * Works for numbers and alphanumeric sender IDs (e.g. "BankMellat").
 */
function ba_sender_allowed($sender) {
    $list = ba_kv_get('bank_senders') ?: (ba_config()['allowed_senders'] ?? []);
    if (!$list) {
        return true;
    }
    $s = strtolower(preg_replace('/\s+/', '', (string)$sender));
    foreach ($list as $allowed) {
        $a = strtolower(preg_replace('/\s+/', '', $allowed));
        if ($a !== '' && substr($s, -strlen($a)) === $a) {
            return true;
        }
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* One-time passwords (رمز پویا)                                        */
/* ------------------------------------------------------------------ */

const BA_OTP_WORDS = '/(رمز\s*(دوم\s*)?پویا|رمز\s*یک\s*بار|رمز\s*یکبار|یک\s*بار\s*مصرف|یکبارمصرف|کد\s*(تایید|تأیید|یکبار|یک بار|امنیتی|فعال\s*سازی)|\bOTP\b|one.?time|verification code)/iu';

/** Amount / merchant / validity that often come with an OTP SMS (not secret). */
function ba_otp_details($text) {
    $t = ba_normalize($text);
    $d = ['amount' => null, 'merchant' => null, 'ttl' => null];
    if (preg_match('/مبلغ\s*(?:خرید|تراکنش)?\s*[:：]?\s*(\d+)\s*(ریال|تومان)?/u', $t, $m)) {
        $d['amount'] = (int)$m[1] * (($m[2] ?? '') === 'تومان' ? 10 : 1);
    }
    if (preg_match('/(?:نام\s*پذیرنده|پذیرنده|فروشگاه|پرداخت\s*به)\s*[:：]\s*([^\n]{2,60})/u', $t, $m)) {
        $d['merchant'] = trim($m[1]);
    }
    if (preg_match('/(?:مهلت|اعتبار|معتبر)[^\n\d]{0,20}(\d{1,3})\s*(ثانیه|دقیقه)/u', $t, $m)) {
        $d['ttl'] = (int)$m[1] * ($m[2] === 'دقیقه' ? 60 : 1);
    } elseif (preg_match('/(?:اعتبار|معتبر)[^\n\d]{0,12}(\d{1,2}):(\d{2})(?::(\d{2}))?/u', $t, $m)) {
        $until = mktime((int)$m[1], (int)$m[2], (int)($m[3] ?? 0));
        if ($until > time()) {
            $d['ttl'] = $until - time();
        }
    }
    return $d;
}

/**
 * Recognises an OTP SMS. Returns null for anything else, otherwise
 * ['code' => '48213967' | null (keyword seen but code not in this part), amount, merchant, ttl].
 */
function ba_parse_otp($text) {
    $t = ba_normalize($text);
    if (!preg_match(BA_OTP_WORDS, $t)) {
        return null;
    }
    $code = null;
    foreach (preg_split('/\n/', $t) as $line) {
        if (!preg_match('/رمز|کد|code|otp|pass/iu', $line)) {
            continue;
        }
        // take out things that are numbers but not the code: amounts, times, dates, masked cards
        $clean = preg_replace([
            '/مبلغ\s*(?:خرید|تراکنش)?\s*[:：]?\s*\d+/u',
            '/\d+\s*(?:ریال|تومان|ثانیه|دقیقه)/u',
            '/\d{1,2}:\d{2}(?::\d{2})?/',
            '~\d{2,4}/\d{1,2}/\d{1,2}~',
            '/[\d*]*\*[\d*]*/',
        ], ' ', $line);
        if (preg_match('/(?<!\d)(\d{4,10})(?!\d)/', $clean, $m)) {
            $code = $m[1];
            break;
        }
    }
    return ['code' => $code] + ba_otp_details($text);
}

/** Masks every 4+ digit number except amounts, for storing a code-less OTP part. */
function ba_mask_digits($text) {
    $t = ba_normalize($text);
    return preg_replace_callback('/(مبلغ\s*[:：]?\s*)?(?<!\d)\d{4,}(?!\d)/u', function ($m) {
        return $m[1] !== '' ? $m[0] : '####';
    }, $t);
}

function ba_otp_key() {
    $cfg = ba_config();
    return hash_hmac('sha256', 'otp-at-rest', ($cfg['app_token'] ?? '') . '|' . ($cfg['device_token'] ?? ''), true);
}

function ba_seal($plain) {
    if (function_exists('sodium_crypto_secretbox')) {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return 's:' . base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, ba_otp_key()));
    }
    $iv = random_bytes(12);
    $tag = '';
    $ct = openssl_encrypt($plain, 'aes-256-gcm', ba_otp_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return 'o:' . base64_encode($iv . $tag . $ct);
}

function ba_unseal($sealed) {
    $raw = base64_decode(substr($sealed, 2));
    if (strpos($sealed, 's:') === 0) {
        $n = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
        $plain = sodium_crypto_secretbox_open(substr($raw, $n), substr($raw, 0, $n), ba_otp_key());
    } else {
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', ba_otp_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    }
    return $plain === false ? null : $plain;
}

/** Deletes expired OTPs (called on every app request and by cron). */
function ba_otp_purge() {
    ba_db()->prepare('DELETE FROM otps WHERE expires_at < ?')->execute([time()]);
}

/** Active OTPs, decrypted. Only called after the OTP PIN was checked. */
function ba_otp_active() {
    ba_otp_purge();
    $rows = ba_db()->query('SELECT * FROM otps ORDER BY id DESC')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id' => (int)$r['id'],
            'code' => ba_unseal($r['code_enc']),
            'amount' => $r['amount'] !== null ? (int)$r['amount'] : null,
            'merchant' => $r['merchant'],
            'created_at' => $r['created_at'],
            'seconds_left' => max(0, (int)$r['expires_at'] - time()),
        ];
    }
    if ($rows) {
        ba_db()->prepare('UPDATE otps SET seen_at = COALESCE(seen_at, ?)')->execute([date('Y-m-d H:i:s')]);
    }
    return $out;
}

/**
 * OTP branch of ingest. The SMS body is never stored in plain text: the
 * code goes encrypted into otps (deleted when it expires) and sms_raw only
 * keeps a placeholder. A long OTP SMS may come in two parts.
 */
function ba_ingest_otp($sender, $body, $modem_time, $otp, $prev) {
    $db = ba_db();
    $now = date('Y-m-d H:i:s');
    $store = function ($text, $status) use ($db, $sender, $modem_time, $now) {
        $db->prepare('INSERT INTO sms_raw (sender, body, modem_time, received_at, status) VALUES (?, ?, ?, ?, ?)')
            ->execute([$sender, $text, $modem_time, $now, $status]);
        return (int)$db->lastInsertId();
    };

    // Second part of an OTP whose first part had no code: glue and retry.
    if ((!$otp || !$otp['code']) && $prev && $prev['status'] === 'otp_part') {
        $joined = ba_parse_otp($prev['body'] . "\n" . ba_normalize($body));
        if ($joined && $joined['code']) {
            $otp = $joined;
            $db->prepare("UPDATE sms_raw SET body = '[رمز یکبار مصرف]', status = 'otp' WHERE id = ?")->execute([$prev['id']]);
        }
    }
    // Tail of an OTP whose code already came: only pick up amount / merchant.
    if ((!$otp || !$otp['code']) && $prev && $prev['status'] === 'otp') {
        $d = ba_otp_details($body);
        $db->prepare('UPDATE otps SET amount = COALESCE(amount, ?), merchant = COALESCE(merchant, ?)
            WHERE id = (SELECT MAX(id) FROM otps)')->execute([$d['amount'], $d['merchant']]);
        $sms_id = $store('[رمز یکبار مصرف - ادامه]', 'otp');
        return ['sms_id' => $sms_id, 'transaction' => null, 'duplicate' => false, 'otp' => null];
    }
    if (!$otp || !$otp['code']) {
        $sms_id = $store(ba_mask_digits($body), 'otp_part');
        return ['sms_id' => $sms_id, 'transaction' => null, 'duplicate' => false, 'otp' => null];
    }

    $sms_id = $store('[رمز یکبار مصرف]', 'otp');
    $ttl = max(30, min(600, $otp['ttl'] ?: 180));
    $db->prepare('INSERT INTO otps (sms_id, created_at, expires_at, code_enc, amount, merchant) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([$sms_id, $now, time() + $ttl, ba_seal($otp['code']), $otp['amount'], $otp['merchant']]);
    return ['sms_id' => $sms_id, 'transaction' => null, 'duplicate' => false,
        'otp' => ['id' => (int)$db->lastInsertId(), 'amount' => $otp['amount'], 'merchant' => $otp['merchant'], 'ttl' => $ttl, 'code' => $otp['code']]];
}

/**
 * Stores an incoming SMS and, if it is a bank transaction, creates a
 * pending transaction. Long Persian SMS arrive from the modem as separate
 * parts; a part that does not parse on its own is glued to the previous
 * part from the same sender if it came within 3 minutes.
 *
 * Returns ['sms_id' => int, 'transaction' => array|null, 'duplicate' => bool].
 */
function ba_ingest_sms($sender, $body, $modem_time = null) {
    $db = ba_db();
    $now = date('Y-m-d H:i:s');

    // OTPs first: they mention "مبلغ ... خرید" and must never become a transaction.
    $last = $db->prepare('SELECT * FROM sms_raw WHERE sender IS ? AND received_at >= ? ORDER BY id DESC LIMIT 1');
    $last->execute([$sender, date('Y-m-d H:i:s', time() - 180)]);
    $last = $last->fetch();
    $otp = ba_parse_otp($body);
    $otp_tail = $last && in_array($last['status'], ['otp', 'otp_part'], true)
        && !preg_match('/مانده|موجودی/u', ba_normalize($body));
    if ($otp || $otp_tail) {
        return ba_ingest_otp($sender, $body, $modem_time, $otp, $last);
    }

    $db->prepare('INSERT INTO sms_raw (sender, body, modem_time, received_at) VALUES (?, ?, ?, ?)')
        ->execute([$sender, $body, $modem_time, $now]);
    $sms_id = (int)$db->lastInsertId();

    $text = $body;
    $parsed = ba_parse_sms($text);
    $sms_row_for_tx = $sms_id;

    $prev = $db->prepare("SELECT * FROM sms_raw WHERE sender IS ? AND id < ? AND received_at >= ?
        ORDER BY id DESC LIMIT 1");
    $prev->execute([$sender, $sms_id, date('Y-m-d H:i:s', time() - 180)]);
    $prev = $prev->fetch();

    if ($prev && in_array($prev['status'], ['new', 'parsed'], true)) {
        $joined = $prev['body'] . "\n" . $body;
        $joined_parsed = ba_parse_sms($joined);
        $prev_tx = null;
        if ($prev['status'] === 'parsed') {
            $q = $db->prepare('SELECT * FROM transactions WHERE sms_id = ?');
            $q->execute([$prev['id']]);
            $prev_tx = $q->fetch();
        }
        // Case A: previous part alone wasn't a transaction, together they are.
        // Case B: previous part was a transaction but lacked balance / date that this part carries.
        $glue = ($prev['status'] === 'new' && !$parsed && $joined_parsed)
            || ($prev_tx && !$parsed && $joined_parsed
                && (($prev_tx['balance'] === null && $joined_parsed['balance'] !== null)
                    || ($prev_tx['bank_date'] === null && $joined_parsed['bank_date'] !== null)));
        if ($glue) {
            $db->prepare('UPDATE sms_raw SET body = ? WHERE id = ?')->execute([$joined, $prev['id']]);
            $db->prepare("UPDATE sms_raw SET status = 'merged' WHERE id = ?")->execute([$sms_id]);
            $sms_row_for_tx = (int)$prev['id'];
            $parsed = $joined_parsed;
            if ($prev_tx) {
                $date = $prev_tx['bank_date'] ?: $parsed['bank_date'];
                $time = $prev_tx['bank_time'] ?: $parsed['bank_time'];
                $occurred = ($date && ($iso = ba_jalali_to_iso($date))) ? $iso . ' ' . ($time ?: '00:00') . ':00' : $prev_tx['occurred_at'];
                $db->prepare('UPDATE transactions SET balance = ?, bank_date = ?, bank_time = ?, occurred_at = ?,
                    account = COALESCE(account, ?) WHERE id = ?')
                    ->execute([$parsed['balance'], $date, $time, $occurred, $parsed['account'], $prev_tx['id']]);
                return ['sms_id' => $sms_id, 'transaction' => ba_get_transaction($prev_tx['id']), 'duplicate' => false, 'merged' => true];
            }
        }
    }

    if (!$parsed) {
        return ['sms_id' => $sms_id, 'transaction' => null, 'duplicate' => false];
    }

    $occurred = $now;
    if ($parsed['bank_date'] && ($iso = ba_jalali_to_iso($parsed['bank_date']))) {
        $occurred = $iso . ' ' . ($parsed['bank_time'] ?: '00:00') . ':00';
    }
    // Same bank SMS delivered twice (modem retry, Shortcut + ESP32 both on).
    $dedup = implode('|', [$parsed['direction'], $parsed['amount'], $parsed['balance'], $parsed['bank_date'], $parsed['bank_time']]);
    if ($parsed['balance'] === null && $parsed['bank_time'] === null) {
        $dedup .= '|' . $sms_row_for_tx;   // not enough to identify it; never treat as duplicate
    }
    try {
        $wallet = (int)($db->query('SELECT id FROM wallets WHERE is_sms = 1 ORDER BY id LIMIT 1')->fetchColumn() ?: 1);
        $db->prepare("INSERT INTO transactions (sms_id, source, direction, amount, balance, account, bank_date, bank_time, occurred_at, dedup_key, wallet_id)
            VALUES (?, 'sms', ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$sms_row_for_tx, $parsed['direction'], $parsed['amount'], $parsed['balance'], $parsed['account'],
                $parsed['bank_date'], $parsed['bank_time'], $occurred, $dedup, $wallet]);
    } catch (PDOException $e) {
        if (strpos($e->getMessage(), 'UNIQUE') !== false) {
            $db->prepare("UPDATE sms_raw SET status = 'ignored' WHERE id = ?")->execute([$sms_id]);
            // Same SMS seen again (modem retry, or relayed through Bale twice): hand back the existing one.
            $q = $db->prepare('SELECT id FROM transactions WHERE dedup_key = ?');
            $q->execute([$dedup]);
            $existing = $q->fetchColumn();
            return ['sms_id' => $sms_id, 'transaction' => $existing ? ba_get_transaction($existing) : null, 'duplicate' => true];
        }
        throw $e;
    }
    $tx_id = (int)$db->lastInsertId();
    $db->prepare("UPDATE sms_raw SET status = 'parsed' WHERE id = ?")->execute([$sms_row_for_tx]);
    return ['sms_id' => $sms_id, 'transaction' => ba_get_transaction($tx_id), 'duplicate' => false];
}

function ba_get_transaction($id) {
    $q = ba_db()->prepare('SELECT t.*, c.name AS category_name, c.kind AS category_kind, w.name AS wallet_name FROM transactions t
        LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN wallets w ON w.id = t.wallet_id WHERE t.id = ?');
    $q->execute([$id]);
    return $q->fetch() ?: null;
}

/* ------------------------------------------------------------------ */
/* Money / notifications / accounting hook                             */
/* ------------------------------------------------------------------ */

function ba_toman($rial) {
    return number_format(intdiv(abs((int)$rial), 10)) . ' تومان';
}

/** Small key/value store (bot conversation state, device heartbeat). */
function ba_kv_get($key, $default = null) {
    $q = ba_db()->prepare('SELECT v FROM kv WHERE k = ?');
    $q->execute([$key]);
    $v = $q->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function ba_kv_set($key, $value) {
    if ($value === null) {
        ba_db()->prepare('DELETE FROM kv WHERE k = ?')->execute([$key]);
        return;
    }
    ba_db()->prepare('INSERT INTO kv (k, v) VALUES (?, ?) ON CONFLICT(k) DO UPDATE SET v = excluded.v')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

function ba_bale_enabled() {
    $cfg = ba_config();
    return !empty($cfg['bale_bot_token']) && function_exists('curl_init');
}

/**
 * curl handle with HTTPS that also works with PHP on Windows, which ships
 * without a CA bundle: there we trust the Windows certificate store.
 */
function ba_curl($url, array $opts) {
    $ch = curl_init($url);
    curl_setopt_array($ch, $opts + [CURLOPT_RETURNTRANSFER => true]);
    if (PHP_OS_FAMILY === 'Windows' && defined('CURLSSLOPT_NATIVE_CA') && !ini_get('curl.cainfo')) {
        curl_setopt($ch, CURLOPT_SSL_OPTIONS, CURLSSLOPT_NATIVE_CA);
    }
    return $ch;
}

/** Calls a Bale bot API method (Telegram-compatible). Returns 'result' or null. */
function ba_bale($method, array $params, $timeout = 15) {
    if (!ba_bale_enabled()) {
        return null;
    }
    $cfg = ba_config();
    $base = rtrim($cfg['bale_api_base'] ?? 'https://tapi.bale.ai', '/');
    $ch = ba_curl($base . '/bot' . $cfg['bale_bot_token'] . '/' . $method, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($params, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return !empty($data['ok']) ? ($data['result'] ?? true) : null;
}

/** Sends a message to the owner's Bale chat. Silent no-op if not configured. */
function ba_notify($text, $keyboard = null) {
    ba_voice_capture('add', $text);   // also spoken, when answering a voice message
    $chat = ba_config()['bale_chat_id'] ?? '';
    if ($chat === '' || !ba_bale_enabled()) {
        return null;
    }
    $params = ['chat_id' => $chat, 'text' => $text];
    if ($keyboard) {
        $params['reply_markup'] = ['inline_keyboard' => $keyboard];
    }
    return ba_bale('sendMessage', $params);
}

/* ------------------------------------------------------------------ */
/* Local speech: STT + TTS on this same server (voice/ folder)          */
/* ------------------------------------------------------------------ */
/*
 * No cloud service and no API key: speech is handled by the local voice
 * service (faster-whisper + Piper, see voice/README.md), reached either
 *   - over HTTP on the loopback interface (config voice_url, recommended:
 *     models stay loaded), or
 *   - as a command run per request (config voice_cli, no daemon needed).
 * voice_url must point at this machine; anything else is refused, so audio
 * never leaves the server.
 */

/** The configured voice_url if it is a loopback address, else null. */
function ba_voice_url() {
    $url = trim((string)(ba_config()['voice_url'] ?? ''));
    if ($url === '') {
        return null;
    }
    $p = parse_url($url);
    $host = strtolower(trim((string)($p['host'] ?? ''), '[]'));
    if (($p['scheme'] ?? '') !== 'http' || !in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        throw new RuntimeException('voice_url باید روی همین سرور باشد (http://127.0.0.1:...)');
    }
    return rtrim($url, '/');
}

/** voice_cli split into argv, or null. */
function ba_voice_cli() {
    $cli = trim((string)(ba_config()['voice_cli'] ?? ''));
    return $cli === '' ? null : preg_split('/\s+/', $cli);
}

function ba_voice_configured() {
    $cfg = ba_config();
    return trim((string)($cfg['voice_url'] ?? '')) !== '' || trim((string)($cfg['voice_cli'] ?? '')) !== '';
}

/**
 * Is local speech usable right now? Asks the service's /health (cached a
 * minute so the app's start-up call stays fast). The CLI mode counts as ready.
 * Returns ['stt' => bool, 'tts' => bool, 'mode' => 'http'|'cli'|null, ...].
 */
function ba_voice_status($fresh = false) {
    if (!ba_voice_configured()) {
        return ['stt' => false, 'tts' => false, 'mode' => null];
    }
    try {
        $url = ba_voice_url();
    } catch (RuntimeException $e) {
        return ['stt' => false, 'tts' => false, 'mode' => null, 'error' => $e->getMessage()];
    }
    if (!$url) {
        return ['stt' => true, 'tts' => true, 'mode' => 'cli'];
    }
    $cached = ba_kv_get('voice:status');
    if (!$fresh && is_array($cached) && ($cached['at'] ?? 0) > time() - 60) {
        return $cached;
    }
    $ch = ba_curl($url . '/health', [CURLOPT_TIMEOUT => 2, CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_HTTPHEADER => ['X-Voice-Token: ' . (ba_config()['voice_token'] ?? '')]]);
    $h = json_decode((string)curl_exec($ch), true);
    curl_close($ch);
    $st = ['stt' => !empty($h['stt_ready']), 'tts' => !empty($h['tts_ready']), 'mode' => 'http',
        'stt_model' => $h['stt_model'] ?? null, 'tts_voice' => $h['tts_voice'] ?? null,
        'error' => is_array($h) ? null : 'سرویس صدا روی ' . $url . ' جواب نمی‌دهد', 'at' => time()];
    ba_kv_set('voice:status', $st);
    return $st;
}

/** POST to the local service. Returns [http code, body, content type]. */
function ba_voice_post($path, $body, $content_type, $timeout) {
    $ch = ba_curl(ba_voice_url() . $path, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: ' . $content_type, 'X-Voice-Token: ' . (ba_config()['voice_token'] ?? '')],
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);
    if ($resp === false || $code === 0) {
        ba_kv_set('voice:status', null);
        throw new RuntimeException('سرویس صدای سرور در دسترس نیست (systemctl status bank-voice)');
    }
    return [$code, (string)$resp, $type];
}

/** Runs the CLI fallback. Returns [exit code, stdout, stderr]. */
function ba_voice_run(array $args, $stdin, $timeout) {
    if (!function_exists('proc_open')) {
        throw new RuntimeException('proc_open روی این PHP غیرفعال است؛ از voice_url استفاده کن');
    }
    $p = proc_open(array_merge(ba_voice_cli(), $args), [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($p)) {
        throw new RuntimeException('اجرای voice_cli نشد');
    }
    fwrite($pipes[0], (string)$stdin);
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = $err = '';
    $end = microtime(true) + $timeout;
    while (true) {
        $out .= stream_get_contents($pipes[1]);
        $err .= stream_get_contents($pipes[2]);
        $st = proc_get_status($p);
        if (!$st['running']) {
            $out .= stream_get_contents($pipes[1]);
            break;
        }
        if (microtime(true) > $end) {
            proc_terminate($p, 9);
            throw new RuntimeException('تبدیل صدا بیش از حد طول کشید');
        }
        usleep(50000);
    }
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($p);
    return [$st['exitcode'], $out, $err];
}

/**
 * Persian speech to text, on this server. $path is any audio file
 * (Bale OGG/Opus voice, iPhone m4a, Chrome webm). Returns the text, or throws.
 */
function ba_transcribe($path, $mime = null, $name = null) {
    if (!ba_voice_configured()) {
        throw new RuntimeException('تبدیل صدا به متن روی سرور نصب نشده (voice/install.sh)');
    }
    $timeout = (int)(ba_config()['voice_timeout'] ?? 120);
    if (filesize($path) > 25 * 1024 * 1024) {
        throw new RuntimeException('فایل صدا خیلی بزرگ است');
    }
    if (ba_voice_url()) {
        [$code, $resp] = ba_voice_post('/stt?lang=fa', file_get_contents($path), 'application/octet-stream', $timeout);
    } else {
        [$code, $resp] = ba_voice_run(['stt', $path, '--lang', 'fa'], '', $timeout);
        $code = $code === 0 ? 200 : 500;
    }
    $data = json_decode($resp, true);
    if ($code !== 200 || !isset($data['text'])) {
        throw new RuntimeException('تبدیل صدا به متن نشد' . (isset($data['error']) ? ' (' . $data['error'] . ')' : ''));
    }
    return trim($data['text']);
}

/**
 * Persian text to speech, on this server. $format: 'ogg' (OGG/Opus, what Bale
 * voice notes need), 'mp3' (the app) or 'wav'. Files are cached in data/tts,
 * so fixed phrases ("ثبت شد") are made only once. Returns the file path.
 */
function ba_tts($text, $format = 'mp3') {
    if (!ba_voice_configured()) {
        throw new RuntimeException('صدای سرور نصب نشده (voice/install.sh)');
    }
    $format = in_array($format, ['ogg', 'mp3', 'wav'], true) ? $format : 'mp3';
    $speech = ba_speech_text($text);
    if ($speech === '') {
        throw new RuntimeException('متنی برای خواندن نیست');
    }
    $dir = BA_ROOT . '/data/tts';
    if (!is_dir($dir)) {
        mkdir($dir, 0775, true);
    }
    // After changing the Piper voice, empty data/tts so old recordings aren't reused.
    $file = $dir . '/' . sha1($format . '|' . $speech) . '.' . $format;
    if (is_file($file) && filesize($file) > 0) {
        @touch($file);
        return $file;
    }
    $timeout = (int)(ba_config()['voice_timeout'] ?? 120);
    $tmp = $file . '.' . getmypid() . '.part';
    if (ba_voice_url()) {
        [$code, $audio, $type] = ba_voice_post('/tts', json_encode(['text' => $speech, 'format' => $format], JSON_UNESCAPED_UNICODE),
            'application/json', $timeout);
        if ($code !== 200 || $audio === '' || strpos($type, 'audio/') !== 0) {
            $err = json_decode($audio, true)['error'] ?? $code;
            throw new RuntimeException('ساخت صدا نشد (' . $err . ')');
        }
        file_put_contents($tmp, $audio);
    } else {
        [$code, , $err] = ba_voice_run(['tts', '--format', $format, '--out', $tmp], $speech, $timeout);
        if ($code !== 0 || !is_file($tmp) || !filesize($tmp)) {
            @unlink($tmp);
            throw new RuntimeException('ساخت صدا نشد (' . trim(substr($err, -200)) . ')');
        }
    }
    rename($tmp, $file);
    return $file;
}

/** Deletes spoken files not used for $days days (run from the daily job). */
function ba_tts_cleanup($days = 30) {
    foreach (glob(BA_ROOT . '/data/tts/*') ?: [] as $f) {
        if (is_file($f) && filemtime($f) < time() - $days * 86400) {
            @unlink($f);
        }
    }
}

/* -------- making chat text speakable for the Persian voice -------- */

/** 2500000 -> "دو میلیون و پانصد هزار" */
function ba_num_words($n) {
    $n = (int)$n;
    if ($n === 0) {
        return 'صفر';
    }
    if ($n < 0) {
        return 'منفی ' . ba_num_words(-$n);
    }
    static $ones = ['', 'یک', 'دو', 'سه', 'چهار', 'پنج', 'شش', 'هفت', 'هشت', 'نه', 'ده', 'یازده', 'دوازده', 'سیزده',
        'چهارده', 'پانزده', 'شانزده', 'هفده', 'هجده', 'نوزده'];
    static $tens = ['', '', 'بیست', 'سی', 'چهل', 'پنجاه', 'شصت', 'هفتاد', 'هشتاد', 'نود'];
    static $hundreds = ['', 'صد', 'دویست', 'سیصد', 'چهارصد', 'پانصد', 'ششصد', 'هفتصد', 'هشتصد', 'نهصد'];
    static $scales = ['', 'هزار', 'میلیون', 'میلیارد', 'هزار میلیارد'];
    $below1000 = function ($x) use ($ones, $tens, $hundreds) {
        $w = [];
        if ($x >= 100) {
            $w[] = $hundreds[intdiv($x, 100)];
            $x %= 100;
        }
        if ($x >= 20) {
            $w[] = $tens[intdiv($x, 10)];
            $x %= 10;
        }
        if ($x > 0) {
            $w[] = $ones[$x];
        }
        return implode(' و ', $w);
    };
    $parts = [];
    for ($i = 0; $n > 0 && $i < count($scales); $i++, $n = intdiv($n, 1000)) {
        $chunk = $n % 1000;
        if ($chunk === 0) {
            continue;
        }
        // "هزار", not "یک هزار"
        $parts[] = ($i === 1 && $chunk === 1) ? 'هزار' : trim($below1000($chunk) . ' ' . $scales[$i]);
    }
    return implode(' و ', array_reverse($parts));
}

/**
 * Chat text -> what the voice should say: no emoji or commands, amounts,
 * Jalali dates and times as Persian words (Piper's phonemizer would read
 * "4,000,000" digit by digit).
 */
function ba_speech_text($text) {
    static $months = ['', 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
    $s = ba_normalize((string)$text, true);                                   // Persian/Arabic digits -> ASCII
    $s = preg_replace('~https?://\S+~u', ' ', $s);
    $s = preg_replace('~(^|\s)/[a-z_]+\b~u', ' ', $s);                        // /pending, /p ...
    $s = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{200D}\x{20E3}\x{2190}-\x{21FF}\x{2500}-\x{257F}]/u', ' ', $s);
    // 1405/07/06 -> شش مهر هزار و چهارصد و پنج
    $s = preg_replace_callback('~\b(1[34]\d\d)/(\d{1,2})/(\d{1,2})\b~', function ($m) use ($months) {
        $mo = (int)$m[2];
        return $mo >= 1 && $mo <= 12 ? ba_num_words($m[3]) . ' ' . $months[$mo] . ' ' . ba_num_words($m[1]) : $m[0];
    }, $s);
    // 18:40 -> ساعت هجده و چهل دقیقه
    $s = preg_replace_callback('~(ساعت\s*)?\b([01]?\d|2[0-3]):([0-5]\d)\b~u', function ($m) {
        return 'ساعت ' . ba_num_words($m[2]) . ((int)$m[3] ? ' و ' . ba_num_words($m[3]) . ' دقیقه' : '');
    }, $s);
    // amounts and other numbers; very long digit runs (account numbers) are skipped
    $s = preg_replace_callback('~\d{1,3}(?:,\d{3})+|\d+~', function ($m) {
        $digits = str_replace(',', '', $m[0]);
        return strlen($digits) > 15 ? ' ' : ba_num_words($digits);
    }, $s);
    $s = str_replace(['·', '—', '–', '•', '|', '«', '»', '"', '(', ')', ':'], ['،', '،', '،', '،', '،', '', '', '', '، ', '، ', '،'], $s);
    $s = preg_replace("/\s*\n+\s*/u", '. ', $s);
    $s = preg_replace('/([.،؟!?])(\s*[.،])+/u', '$1', $s);
    $s = preg_replace('/^[\s.،]+|[\s.،]+$/u', '', preg_replace('/\s+/u', ' ', $s));   // trim() is byte-based
    return mb_substr($s, 0, 1200);
}

/** Uploads a file to a Bale method (sendVoice, sendAudio...) as multipart. */
function ba_bale_upload($method, array $params, $field, $path, $mime, $filename, $timeout = 60) {
    if (!ba_bale_enabled()) {
        return null;
    }
    $cfg = ba_config();
    $base = rtrim($cfg['bale_api_base'] ?? 'https://tapi.bale.ai', '/');
    foreach ($params as $k => $v) {
        if (is_array($v)) {
            $params[$k] = json_encode($v, JSON_UNESCAPED_UNICODE);
        }
    }
    $params[$field] = new CURLFile($path, $mime, $filename);
    $ch = ba_curl($base . '/bot' . $cfg['bale_bot_token'] . '/' . $method, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $params,
        CURLOPT_TIMEOUT => $timeout,
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);
    $data = json_decode((string)$resp, true);
    return !empty($data['ok']) ? ($data['result'] ?? true) : null;
}

/** Speaks $text into the owner's Bale chat as a voice message. */
function ba_send_voice($text, $reply_to = null) {
    $chat = ba_config()['bale_chat_id'] ?? '';
    if ($chat === '' || !ba_bale_enabled()) {
        return null;
    }
    $file = ba_tts($text, 'ogg');
    $params = ['chat_id' => $chat];
    if ($reply_to) {
        $params['reply_to_message_id'] = (int)$reply_to;
    }
    return ba_bale_upload('sendVoice', $params, 'voice', $file, 'audio/ogg', 'answer.ogg');
}

/*
 * While the bot answers a voice message, everything it writes is also
 * collected here and then spoken back as one voice message (bot.php):
 *   ba_voice_capture('start') ... ba_voice_capture('add', $text) ... ba_voice_capture('stop') -> [texts]
 * 'add' does nothing when no capture is running.
 */
function ba_voice_capture($action, $text = null) {
    static $buf = null;
    switch ($action) {
        case 'start':
            $buf = [];
            return null;
        case 'add':
            if ($buf !== null && trim((string)$text) !== '') {
                $buf[] = (string)$text;
            }
            return null;
        case 'stop':
            $out = $buf ?? [];
            $buf = null;
            return $out;
    }
    return $buf !== null;
}

/* ------------------------------------------------------------------ */
/* Understanding "what was it for?" answers                            */
/* ------------------------------------------------------------------ */

function ba_norm_text($s) {
    $s = ba_normalize((string)$s, true);
    $s = preg_replace('/[.,،!؟?]/u', ' ', $s);
    return mb_strtolower(trim(preg_replace('/\s+/u', ' ', $s)));
}

/**
 * "حواله به علی رضایی بابت خرید بذر" ->
 *   ['description' => '...', 'party' => 'علی رضایی', 'category_id' => <id of حواله>]
 * Used by both the phone app and the Bale bot.
 */
function ba_interpret($text, $direction) {
    $db = ba_db();
    $t = ba_norm_text($text);
    $res = ['description' => trim((string)$text), 'party' => '', 'category_id' => null];
    $titles = '(آقای|آقا|خانم|حاج|حاجی|جناب|دکتر|مهندس)';
    $stop = ['بابت', 'برای', 'که', 'و', 'بود', 'شد', 'هست', 'است', 'رو', 'را', 'تومن', 'تومان', 'بوده',
        'دادم', 'زدم', 'فرستادم', 'گرفتم', 'ریختم', 'ریخت', 'داد', 'پول', 'حساب', 'کارت'];

    // 1) someone we already know (longest name wins)
    $known = null;
    foreach ($db->query('SELECT * FROM parties') as $p) {
        $n = ba_norm_text($p['name']);
        if ($n !== '' && mb_strpos(" $t ", " $n ") !== false && (!$known || mb_strlen($p['name']) > mb_strlen($known['name']))) {
            $known = $p;
        }
    }
    if ($known) {
        $res['party'] = $known['name'];
        if ($known['last_category_id']) {
            $res['category_id'] = (int)$known['last_category_id'];
        }
    } else {
        // 2) a name after "به / حواله / برای" (payments) or "از / طرف" (receipts)
        $marker = $direction === 'in' ? '(?:از|طرف)' : '(?:به|حواله|برای)';
        if (preg_match('/(?:^|\s)' . $marker . '\s+(?:حساب\s+)?(.+)$/u', $t, $m)) {
            $name = [];
            foreach (explode(' ', preg_replace('/^' . $titles . '\s+/u', '', $m[1])) as $w) {
                if (count($name) >= 3) {
                    break;
                }
                if (in_array($w, $stop, true) || preg_match('/^(به|از|حواله|طرف)$/u', $w) || preg_match('/^' . $titles . '$/u', $w)) {
                    if ($name) {
                        break;
                    }
                    continue;
                }
                $name[] = $w;
            }
            $res['party'] = implode(' ', $name);
        }
    }

    // 3) category from its keywords (longest keyword wins). Words that only
    // say HOW money moved (حواله, کارت به کارت ...) lose to a word that says
    // WHAT it was for: "حواله به علی بابت خرید بذر" is a purchase, not a
    // settlement of Ali's account; "تسویه با علی" is.
    if (!$res['category_id']) {
        $how = ['حواله', 'کارت به کارت', 'انتقال', 'پایا', 'ساتنا'];
        $best = [0, null];
        $best_what = [0, null];
        foreach ($db->query('SELECT * FROM categories') as $c) {
            if ($c['direction'] !== 'both' && $c['direction'] !== $direction) {
                continue;
            }
            foreach (explode(',', (string)$c['keywords']) as $k) {
                $k = ba_norm_text($k);
                if ($k === '' || mb_strpos($t, $k) === false) {
                    continue;
                }
                if (mb_strlen($k) > $best[0]) {
                    $best = [mb_strlen($k), (int)$c['id']];
                }
                if (!in_array($k, $how, true) && mb_strlen($k) > $best_what[0]) {
                    $best_what = [mb_strlen($k), (int)$c['id']];
                }
            }
        }
        $res['category_id'] = $best_what[1] ?: $best[1];
    }
    return $res;
}

function ba_is_yes($text) {
    return (bool)preg_match('/^(بله|بلی|آره|اره|آری|درسته|درست|تایید|تأیید|ثبت|باشه|اوکی|ok|yes|حتما)/u', ba_norm_text($text));
}

function ba_category($id) {
    if (!$id) {
        return null;
    }
    $q = ba_db()->prepare('SELECT * FROM categories WHERE id = ?');
    $q->execute([(int)$id]);
    return $q->fetch() ?: null;
}

function ba_remember_party($party, $category_id = null) {
    ba_db()->prepare('INSERT INTO parties (name, uses, last_category_id) VALUES (?, 1, ?)
        ON CONFLICT(name) DO UPDATE SET uses = uses + 1, last_category_id = COALESCE(excluded.last_category_id, last_category_id)')
        ->execute([$party, $category_id]);
}

/**
 * Files the answer for a transaction, learns the party, pushes to accounting.
 * A "person account" category needs a person; a transfer between my own
 * wallets gets the other wallet (cash by default).
 */
function ba_confirm_tx($id, $description, $party, $category_id, $note = null, $counter_wallet_id = null) {
    $db = ba_db();
    $party = trim((string)$party) ?: null;
    $category_id = (int)$category_id ?: null;
    $cat = ba_category($category_id);
    if ($cat && $cat['kind'] === 'party' && !$party) {
        throw new InvalidArgumentException('این دسته حساب یک شخص را تغییر می‌دهد؛ طرف حساب را بگو.');
    }
    $counter = null;
    if ($cat && $cat['kind'] === 'transfer') {
        $q = $db->prepare('SELECT wallet_id FROM transactions WHERE id = ?');
        $q->execute([(int)$id]);
        $own = (int)$q->fetchColumn();
        $counter = (int)$counter_wallet_id ?: (int)$db->query("SELECT id FROM wallets WHERE id != $own ORDER BY kind = 'cash' DESC, id LIMIT 1")->fetchColumn();
        if (!$counter || $counter === $own) {
            throw new InvalidArgumentException('برای انتقال، حساب مقصد را انتخاب کن.');
        }
    }
    $db->prepare("UPDATE transactions SET status = 'confirmed', description = ?, party = ?, category_id = ?, note = ?,
        confirmed_at = ?, synced = 0, counter_wallet_id = ? WHERE id = ?")
        ->execute([trim((string)$description), $party, $category_id, trim((string)$note) ?: null, date('Y-m-d H:i:s'), $counter ?: null, (int)$id]);
    if ($party) {
        ba_remember_party($party, $category_id);
    }
    $tx = ba_get_transaction($id);
    $tx['synced_now'] = ba_sync_to_accounting($tx);
    ba_acc_link_tx($id);
    return $tx;
}

/**
 * When the accounting module is linked (حسابداری ← تنظیمات), a confirmed,
 * changed, ignored or deleted transaction is mirrored in its books. A failure
 * there never blocks the bank assistant; it is shown in the accounting settings.
 */
function ba_acc_link_tx($id) {
    $link = ba_kv_get('acc_bank_link');   // on unless turned off in the accounting settings
    if ((is_array($link) && empty($link['enabled'])) || !is_file(__DIR__ . '/acc_bank.php')) {
        return;
    }
    try {
        require_once __DIR__ . '/acc_bank.php';
        acc_bank_sync_tx((int)$id);
    } catch (Throwable $e) {
        error_log('acc bank link: ' . $e->getMessage());
        ba_kv_set('acc_bank_link_error', '#' . (int)$id . ': ' . $e->getMessage());
    }
}

/* ------------------------------------------------------------------ */
/* Accounting: people, wallets, profit & loss                          */
/* ------------------------------------------------------------------ */
/*
 * Sign convention for people: balance > 0 = they owe me (طلب من),
 * balance < 0 = I owe them (بدهی من).
 *   money I pay them  (category kind 'party', out)  -> +amount
 *   money they pay me (category kind 'party', in)   -> -amount
 *   credit sale to them    (bill type 'sale')       -> +amount
 *   credit purchase from them (bill 'purchase')     -> -amount
 * A cash purchase/sale (category kind 'pl') with a person attached is
 * shown in their statement but does not change their balance.
 */

const BA_PARTY_TX_SQL = "SELECT COALESCE(SUM(CASE WHEN t.direction = 'out' THEN t.amount ELSE -t.amount END), 0)
    FROM transactions t JOIN categories c ON c.id = t.category_id
    WHERE t.party = p.name AND t.status = 'confirmed' AND c.kind = 'party'";
const BA_PARTY_BILL_SQL = "SELECT COALESCE(SUM(CASE WHEN b.type = 'sale' THEN b.amount ELSE -b.amount END), 0)
    FROM bills b WHERE b.party = p.name";

/** Everyone with their balance, biggest amounts first. */
function ba_people() {
    $q = ba_db()->prepare("SELECT p.name, p.phone, p.note, p.opening, p.uses,
            p.opening + (" . BA_PARTY_TX_SQL . ") + (" . BA_PARTY_BILL_SQL . ") AS balance,
            (SELECT COUNT(*) FROM bills b WHERE b.party = p.name AND b.type = 'sale' AND b.due_date IS NOT NULL AND b.due_date < ?) AS overdue
        FROM parties p ORDER BY ABS(balance) DESC, p.uses DESC, p.name");
    $q->execute([date('Y-m-d')]);
    $rows = $q->fetchAll();
    foreach ($rows as &$r) {
        $r['balance'] = (int)$r['balance'];
        $r['opening'] = (int)$r['opening'];
        $r['overdue'] = (int)$r['overdue'];
    }
    return $rows;
}

function ba_person($name) {
    $q = ba_db()->prepare("SELECT p.*, p.opening + (" . BA_PARTY_TX_SQL . ") + (" . BA_PARTY_BILL_SQL . ") AS balance
        FROM parties p WHERE p.name = ?");
    $q->execute([$name]);
    $p = $q->fetch();
    if ($p) {
        $p['balance'] = (int)$p['balance'];
        $p['opening'] = (int)$p['opening'];
    }
    return $p ?: null;
}

/**
 * Statement of one person, oldest first, with running balance.
 * 'effect' is how the row moved their balance (0 for cash purchases/sales
 * shown only for reference).
 */
function ba_person_statement($name) {
    $db = ba_db();
    $p = ba_person($name);
    if (!$p) {
        return null;
    }
    $rows = [];
    $q = $db->prepare("SELECT t.id, t.direction, t.amount, t.occurred_at, t.bank_date, t.description, c.name AS category_name, c.kind
        FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.party = ? AND t.status = 'confirmed'");
    $q->execute([$name]);
    foreach ($q as $t) {
        $effect = $t['kind'] === 'party' ? ($t['direction'] === 'out' ? (int)$t['amount'] : -(int)$t['amount']) : 0;
        $rows[] = [
            'ref' => 'tx:' . $t['id'],
            'sort' => $t['occurred_at'] . sprintf('|1|%09d', $t['id']),
            'date' => substr($t['occurred_at'], 0, 10),
            'title' => $t['kind'] === 'party'
                ? ($t['direction'] === 'out' ? 'پرداخت به او' : 'دریافت از او')
                : ($t['direction'] === 'out' ? 'خرید/پرداخت نقدی' : 'فروش/دریافت نقدی'),
            'description' => $t['description'],
            'category' => $t['category_name'],
            'amount' => (int)$t['amount'],
            'effect' => $effect,
        ];
    }
    $q = $db->prepare('SELECT b.*, c.name AS category_name FROM bills b LEFT JOIN categories c ON c.id = b.category_id WHERE b.party = ?');
    $q->execute([$name]);
    foreach ($q as $b) {
        $rows[] = [
            'ref' => 'bill:' . $b['id'],
            'sort' => $b['date'] . sprintf(' 00:00:00|0|%09d', $b['id']),
            'date' => $b['date'],
            'title' => $b['type'] === 'sale' ? 'فروش نسیه به او' : 'خرید نسیه از او',
            'description' => $b['description'],
            'category' => $b['category_name'],
            'amount' => (int)$b['amount'],
            'effect' => $b['type'] === 'sale' ? (int)$b['amount'] : -(int)$b['amount'],
            'due_date' => $b['due_date'],
        ];
    }
    usort($rows, fn($x, $y) => strcmp($x['sort'], $y['sort']));
    $run = (int)$p['opening'];
    foreach ($rows as &$r) {
        $run += $r['effect'];
        $r['running'] = $run;
    }
    return ['person' => $p, 'rows' => $rows];
}

/**
 * Wallet balances: opening + every movement recorded on it (SMS ones too,
 * even unanswered or ignored - the money did move) + transfers into it.
 */
function ba_wallets() {
    $db = ba_db();
    $rows = $db->query("SELECT w.*,
            w.opening
            + COALESCE((SELECT SUM(CASE WHEN t.direction = 'in' THEN t.amount ELSE -t.amount END) FROM transactions t WHERE t.wallet_id = w.id), 0)
            + COALESCE((SELECT SUM(CASE WHEN t.direction = 'out' THEN t.amount ELSE -t.amount END) FROM transactions t
                WHERE t.counter_wallet_id = w.id AND t.status = 'confirmed'), 0) AS balance,
            (SELECT t.balance FROM transactions t WHERE t.wallet_id = w.id AND t.balance IS NOT NULL
                ORDER BY t.occurred_at DESC, t.id DESC LIMIT 1) AS bank_balance
        FROM wallets w ORDER BY w.id")->fetchAll();
    foreach ($rows as &$r) {
        $r['balance'] = (int)$r['balance'];
        $r['opening'] = (int)$r['opening'];
        $r['bank_balance'] = $r['bank_balance'] !== null ? (int)$r['bank_balance'] : null;
    }
    return $rows;
}

/**
 * Profit & loss for a period: income/expense categories from confirmed
 * money movements plus credit sales/purchases (bills). Settlements with
 * people and transfers between my own wallets are not income or expense.
 */
function ba_profit_loss($from, $to) {
    $db = ba_db();
    $q = $db->prepare("SELECT t.direction, COALESCE(c.name, 'بدون دسته') AS category, SUM(t.amount) AS total, COUNT(*) AS n
        FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.status = 'confirmed' AND COALESCE(c.kind, 'pl') = 'pl' AND t.occurred_at >= ? AND t.occurred_at <= ?
        GROUP BY t.direction, category");
    $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $lines = [];
    foreach ($q as $r) {
        $lines[$r['direction']][$r['category']] = ['total' => (int)$r['total'], 'n' => (int)$r['n']];
    }
    $q = $db->prepare("SELECT b.type, COALESCE(c.name, CASE b.type WHEN 'sale' THEN 'فروش' ELSE 'خرید کالا و مواد' END) AS category,
            SUM(b.amount) AS total, COUNT(*) AS n
        FROM bills b LEFT JOIN categories c ON c.id = b.category_id
        WHERE b.date >= ? AND b.date <= ? GROUP BY b.type, category");
    $q->execute([$from, $to]);
    foreach ($q as $r) {
        $dir = $r['type'] === 'sale' ? 'in' : 'out';
        $cur = $lines[$dir][$r['category']] ?? ['total' => 0, 'n' => 0];
        $lines[$dir][$r['category']] = ['total' => $cur['total'] + (int)$r['total'], 'n' => $cur['n'] + (int)$r['n']];
    }
    $out = ['from' => $from, 'to' => $to, 'income' => [], 'expense' => [], 'total_income' => 0, 'total_expense' => 0];
    foreach (['in' => 'income', 'out' => 'expense'] as $dir => $key) {
        foreach ($lines[$dir] ?? [] as $cat => $v) {
            $out[$key][] = ['category' => $cat, 'total' => $v['total'], 'n' => $v['n']];
            $out['total_' . $key] += $v['total'];
        }
        usort($out[$key], fn($a, $b) => $b['total'] <=> $a['total']);
    }
    $out['profit'] = $out['total_income'] - $out['total_expense'];
    return $out;
}

/** Totals of what others owe me and what I owe others. */
function ba_people_totals() {
    $recv = 0;
    $pay = 0;
    foreach (ba_people() as $p) {
        if ($p['balance'] > 0) {
            $recv += $p['balance'];
        } else {
            $pay -= $p['balance'];
        }
    }
    return ['receivable' => $recv, 'payable' => $pay];
}

/**
 * Pushes a confirmed transaction to the accounting program's webhook, if one
 * is configured (see README: this is where Hesabfa / your own software plugs in).
 */
function ba_sync_to_accounting($tx) {
    $url = ba_config()['accounting_webhook'] ?? '';
    if ($url === '' || !function_exists('curl_init')) {
        return false;
    }
    $payload = [
        'id' => (int)$tx['id'],
        'type' => $tx['direction'] === 'in' ? 'receipt' : 'payment',
        'amount_rial' => (int)$tx['amount'],
        'date_jalali' => $tx['bank_date'],
        'time' => $tx['bank_time'],
        'date' => substr($tx['occurred_at'], 0, 10),
        'description' => $tx['description'],
        'party' => $tx['party'],
        'category' => $tx['category_name'] ?? null,
        'note' => $tx['note'],
        'account' => $tx['account'],
        'balance_rial' => $tx['balance'] !== null ? (int)$tx['balance'] : null,
    ];
    $ch = ba_curl($url, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code >= 200 && $code < 300) {
        ba_db()->prepare('UPDATE transactions SET synced = 1 WHERE id = ?')->execute([$tx['id']]);
        return true;
    }
    return false;
}

/* ------------------------------------------------------------------ */
/* Reconciliation                                                      */
/* ------------------------------------------------------------------ */

/**
 * Balance chain check: every bank SMS carries the balance after it, so
 * previous balance +/- this amount must equal this balance. A break means
 * an SMS never arrived (modem off, no signal) or the bank took a fee
 * without SMS - exactly what a weekly reconciliation must catch.
 */
function ba_balance_check($from, $to) {
    $q = ba_db()->prepare("SELECT * FROM transactions WHERE source = 'sms' AND balance IS NOT NULL
        AND occurred_at >= ? AND occurred_at <= ? ORDER BY occurred_at, id");
    $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $rows = $q->fetchAll();

    $gaps = [];
    $prev = null;
    foreach ($rows as $r) {
        if ($prev && ($prev['account'] === null || $r['account'] === null || $prev['account'] === $r['account'])) {
            $signed = $r['direction'] === 'in' ? (int)$r['amount'] : -(int)$r['amount'];
            $expected = (int)$prev['balance'] + $signed;
            if ($expected !== (int)$r['balance']) {
                $diff = (int)$r['balance'] - $expected;   // + : money came in unseen, - : money left unseen
                $gaps[] = [
                    'after_id' => (int)$prev['id'],
                    'before_id' => (int)$r['id'],
                    'after_date' => trim($prev['bank_date'] . ' ' . $prev['bank_time']),
                    'before_date' => trim($r['bank_date'] . ' ' . $r['bank_time']),
                    'expected_balance' => $expected,
                    'actual_balance' => (int)$r['balance'],
                    'missing' => $diff,
                ];
            }
        }
        $prev = $r;
    }

    $pending = ba_db()->prepare("SELECT COUNT(*) FROM transactions WHERE status = 'pending'
        AND occurred_at >= ? AND occurred_at <= ?");
    $pending->execute([$from . ' 00:00:00', $to . ' 23:59:59']);

    return [
        'from' => $from,
        'to' => $to,
        'checked' => count($rows),
        'gaps' => $gaps,
        'pending' => (int)$pending->fetchColumn(),
        'last_balance' => $rows ? (int)end($rows)['balance'] : null,
        'ok' => !$gaps,
    ];
}

/**
 * Reads a statement exported from the bank's internet banking (CSV, or
 * Excel saved as CSV). Columns are found by header name, so the order
 * does not matter. Returns list of ['date' => 'Y-m-d', 'direction', 'amount', 'balance', 'desc'].
 */
function ba_parse_statement_csv($csv) {
    $csv = ba_normalize(preg_replace('/^\xEF\xBB\xBF/', '', $csv), true);
    $lines = array_values(array_filter(preg_split('/\n/', $csv), fn($l) => trim($l) !== ''));
    if (!$lines) {
        return [];
    }
    $delim = substr_count($lines[0], ';') > substr_count($lines[0], ',') ? ';'
        : (substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',');

    $col = ['date' => null, 'out' => null, 'in' => null, 'amount' => null, 'balance' => null, 'desc' => null];
    $header_row = null;
    foreach (array_slice($lines, 0, 15) as $i => $line) {
        $cells = array_map('trim', str_getcsv($line, $delim, '"', ''));
        foreach ($cells as $j => $h) {
            if ($col['date'] === null && preg_match('/تاریخ|date/ui', $h)) $col['date'] = $j;
            elseif ($col['out'] === null && preg_match('/برداشت|بدهکار|debit|withdraw/ui', $h)) $col['out'] = $j;
            elseif ($col['in'] === null && preg_match('/واریز|بستانکار|credit|deposit/ui', $h)) $col['in'] = $j;
            elseif ($col['balance'] === null && preg_match('/مانده|موجودی|balance/ui', $h)) $col['balance'] = $j;
            elseif ($col['amount'] === null && preg_match('/مبلغ|amount/ui', $h)) $col['amount'] = $j;
            elseif ($col['desc'] === null && preg_match('/شرح|توضیح|description/ui', $h)) $col['desc'] = $j;
        }
        if ($col['date'] !== null && ($col['in'] !== null || $col['out'] !== null || $col['amount'] !== null)) {
            $header_row = $i;
            break;
        }
        $col = array_fill_keys(array_keys($col), null);
    }
    if ($header_row === null) {
        throw new RuntimeException('ستون‌های «تاریخ» و «واریز/برداشت» (یا «مبلغ») در فایل پیدا نشد.');
    }

    $num = function ($v) {
        $v = preg_replace('/[^\d\-]/', '', (string)$v);
        return $v === '' || $v === '-' ? 0 : (int)$v;
    };
    $out = [];
    foreach (array_slice($lines, $header_row + 1) as $line) {
        $c = str_getcsv($line, $delim, '"', '');
        $raw_date = trim($c[$col['date']] ?? '');
        if (!preg_match('~(\d{2,4})[/\-.](\d{1,2})[/\-.](\d{1,2})~', $raw_date, $dm)) {
            continue;
        }
        $iso = (int)$dm[1] > 1700
            ? sprintf('%04d-%02d-%02d', $dm[1], $dm[2], $dm[3])
            : ba_jalali_to_iso("$dm[1]/$dm[2]/$dm[3]");
        $in = $col['in'] !== null ? abs($num($c[$col['in']] ?? '')) : 0;
        $o = $col['out'] !== null ? abs($num($c[$col['out']] ?? '')) : 0;
        if (!$in && !$o && $col['amount'] !== null) {
            $a = $num($c[$col['amount']] ?? '');
            $a < 0 ? $o = -$a : $in = $a;
        }
        if (!$in && !$o) {
            continue;
        }
        $out[] = [
            'date' => $iso,
            'direction' => $in ? 'in' : 'out',
            'amount' => $in ?: $o,
            'balance' => $col['balance'] !== null ? $num($c[$col['balance']] ?? '') : null,
            'desc' => $col['desc'] !== null ? trim($c[$col['desc']] ?? '') : '',
        ];
    }
    return $out;
}

/**
 * Bank statement vs. our ledger. Match = same direction and amount within
 * +/- 2 days (banks date some transfers on settlement day).
 */
function ba_reconcile_statement(array $lines, $from = null, $to = null) {
    if (!$lines) {
        throw new RuntimeException('هیچ ردیف تراکنشی در فایل پیدا نشد.');
    }
    $dates = array_column($lines, 'date');
    $from = $from ?: min($dates);
    $to = $to ?: max($dates);
    $lines = array_values(array_filter($lines, fn($l) => $l['date'] >= $from && $l['date'] <= $to));

    $q = ba_db()->prepare("SELECT t.*, c.name AS category_name FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.status != 'ignored' AND t.occurred_at >= ? AND t.occurred_at <= ? ORDER BY t.occurred_at");
    $q->execute([date('Y-m-d', strtotime($from . ' -2 days')) . ' 00:00:00', date('Y-m-d', strtotime($to . ' +2 days')) . ' 23:59:59']);
    $ledger = $q->fetchAll();
    $used = [];

    $only_bank = [];
    $matched = 0;
    foreach ($lines as $l) {
        $best = null;
        $best_dist = 99;
        foreach ($ledger as $k => $t) {
            if (isset($used[$k]) || $t['direction'] !== $l['direction'] || (int)$t['amount'] !== $l['amount']) {
                continue;
            }
            $dist = abs(strtotime(substr($t['occurred_at'], 0, 10)) - strtotime($l['date'])) / 86400;
            if ($dist <= 2 && $dist < $best_dist) {
                $best = $k;
                $best_dist = $dist;
            }
        }
        if ($best === null) {
            $only_bank[] = $l;
        } else {
            $used[$best] = true;
            $matched++;
        }
    }
    $only_ledger = [];
    $unconfirmed = [];
    foreach ($ledger as $k => $t) {
        $d = substr($t['occurred_at'], 0, 10);
        if ($d < $from || $d > $to) {
            continue;
        }
        if (!isset($used[$k])) {
            $only_ledger[] = $t;
        } elseif ($t['status'] === 'pending') {
            $unconfirmed[] = $t;
        }
    }
    $sum = fn($rows, $dir) => array_sum(array_map(fn($r) => (int)$r['amount'], array_filter($rows, fn($r) => $r['direction'] === $dir)));
    return [
        'from' => $from,
        'to' => $to,
        'bank_rows' => count($lines),
        'matched' => $matched,
        'only_in_bank' => $only_bank,
        'only_in_ledger' => $only_ledger,
        'matched_but_unconfirmed' => $unconfirmed,
        'bank_total_in' => $sum($lines, 'in'),
        'bank_total_out' => $sum($lines, 'out'),
        'ok' => !$only_bank && !$only_ledger,
    ];
}

function ba_save_reconciliation($kind, array $report) {
    ba_db()->prepare('INSERT INTO reconciliations (created_at, kind, date_from, date_to, ok, report) VALUES (?, ?, ?, ?, ?, ?)')
        ->execute([date('Y-m-d H:i:s'), $kind, $report['from'], $report['to'], $report['ok'] ? 1 : 0,
            json_encode($report, JSON_UNESCAPED_UNICODE)]);
    return (int)ba_db()->lastInsertId();
}
