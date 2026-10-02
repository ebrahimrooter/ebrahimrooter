<?php
/**
 * Bank assistant - JSON API.
 *
 * Every request is api.php?r=<route>.
 *   - "ingest" and "heartbeat" are called by the ESP32 (or an iPhone
 *     Shortcut / Android SMS forwarder) and need the device token.
 *   - "bale" is the Bale bot webhook (secret key in the URL, see cron.php bale-setup).
 *   - Everything else is called by the phone app and needs the app token
 *     in an X-App-Token header (or ?token= for the CSV download link).
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/jobs.php';
require_once __DIR__ . '/webpush.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function out($data, $code = 200) {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * Answers the caller right away, then keeps running $after (notifications):
 * the ESP32 on slow 2G must not wait for Bale / Apple push to respond.
 */
function out_then($data, callable $after) {
    ignore_user_abort(true);
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    header('Content-Length: ' . strlen($json));
    header('Connection: close');
    echo $json;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } elseif (function_exists('litespeed_finish_request')) {
        litespeed_finish_request();
    } else {
        while (ob_get_level()) {
            ob_end_flush();
        }
        flush();
    }
    try {
        $after();
    } catch (Throwable $e) {
        error_log('after response: ' . $e->getMessage());
    }
    exit;
}

function fail($message, $code = 400) {
    out(['ok' => false, 'error' => $message], $code);
}

set_exception_handler(function ($ex) {
    if ($ex instanceof InvalidArgumentException) {
        fail($ex->getMessage(), 400);   // a validation message meant for the user
    }
    fail('خطای سرور: ' . $ex->getMessage(), 500);
});

/** "1,250,000" / "۱۲۵۰۰۰۰" in toman or rial field -> rial int. */
function rial_from($in, $field_rial, $field_toman = null) {
    if ($field_toman && isset($in[$field_toman]) && $in[$field_toman] !== '') {
        return 10 * (int)preg_replace('/[^\d\-]/', '', ba_normalize((string)$in[$field_toman]));
    }
    return (int)preg_replace('/[^\d\-]/', '', ba_normalize((string)($in[$field_rial] ?? '')));
}

function valid_date($d) {
    return is_string($d) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : date('Y-m-d');
}

$cfg = ba_config();
$route = $_GET['r'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

$in = $_POST;
if ($method === 'POST' && stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') === 0) {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
}

function token_ok($given, $expected) {
    return is_string($given) && $expected !== '' && strpos($expected, 'CHANGE-ME') !== 0 && hash_equals($expected, $given);
}

function require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        fail('فقط POST', 405);
    }
}

/* ---------------------------- device side ---------------------------- */

if ($route === 'ingest') {
    require_post();
    $token = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ($in['token'] ?? '');
    if (!token_ok($token, $cfg['device_token'] ?? '')) {
        fail('توکن دستگاه نادرست است', 401);
    }
    $sender = trim((string)($in['sender'] ?? ''));
    if (!empty($in['text_hex'])) {
        $text = ba_decode_ucs2_hex($in['text_hex']);
        if ($sender !== '' && preg_match('/^[0-9A-Fa-f]+$/', $sender) && strlen($sender) % 4 === 0) {
            $sender = ba_decode_ucs2_hex($sender);
        }
    } else {
        $text = (string)($in['text'] ?? '');
    }
    $text = trim($text);
    if ($text === '') {
        fail('متن پیامک خالی است');
    }
    if (!ba_sender_allowed($sender)) {
        // Say OK so the device deletes it from the SIM; it's just not a bank SMS.
        // Only the sender name is kept (to list it in settings), never the text.
        ba_db()->prepare("INSERT INTO sms_raw (sender, body, received_at, status) VALUES (?, '', ?, 'ignored')")
            ->execute([$sender, date('Y-m-d H:i:s')]);
        out(['ok' => true, 'ignored' => 'sender']);
    }
    $res = ba_ingest_sms($sender, $text, $in['modem_time'] ?? null);
    if (!empty($res['otp'])) {
        $o = $res['otp'];
        $info = ($o['amount'] ? ' · ' . ba_toman($o['amount']) : '') . ($o['merchant'] ? ' · ' . $o['merchant'] : '');
        $msg = !empty($cfg['otp_to_bale'])
            ? "🔐 رمز یکبار مصرف: {$o['code']}{$info}\n⏳ " . ceil($o['ttl'] / 60) . ' دقیقه اعتبار'
            : "🔐 رمز یکبار مصرف رسید{$info}\n⏳ " . ceil($o['ttl'] / 60) . ' دقیقه در اپ قابل دیدن است'
                . (!empty($cfg['app_url']) ? "\n" . rtrim($cfg['app_url'], '/') . '/#/otp' : '');
        out_then(['ok' => true, 'otp' => true], function () use ($msg, $info) {
            wp_notify_all(['title' => '🔐 رمز یکبار مصرف رسید', 'body' => ltrim($info, ' ·') ?: 'برای دیدن، اپ را باز کن', 'url' => '#/otp', 'tag' => 'otp']);
            ba_notify($msg);
        });
    }
    $tx = $res['transaction'];
    $reply = ['ok' => true, 'transaction_id' => $tx && empty($res['duplicate']) ? (int)$tx['id'] : null, 'duplicate' => $res['duplicate']];
    if ($tx && empty($res['merged']) && empty($res['duplicate'])) {
        // The SMS is safely stored; notifications go out after the device got its OK.
        out_then($reply, function () use ($tx) {
            try {
                wp_notify_transaction($tx);
            } finally {
                bot_on_new_transaction($tx);
            }
        });
    }
    out($reply);
}

if ($route === 'heartbeat') {
    // ESP32 reports every few minutes; cron.php health alerts when it goes quiet.
    require_post();
    $token = $_SERVER['HTTP_X_DEVICE_TOKEN'] ?? ($in['token'] ?? '');
    if (!token_ok($token, $cfg['device_token'] ?? '')) {
        fail('توکن دستگاه نادرست است', 401);
    }
    $prev = ba_kv_get('device', []);
    ba_kv_set('device', [
        'last_seen' => date('Y-m-d H:i:s'),
        'signal' => isset($in['csq']) ? (int)$in['csq'] : null,       // 0-31, 99 = unknown
        'registered' => isset($in['creg']) ? (int)$in['creg'] : null,  // 1 home, 5 roaming
        'sms_on_sim' => isset($in['used']) ? (int)$in['used'] : null,
        'wifi_rssi' => isset($in['rssi']) ? (int)$in['rssi'] : null,
        'uptime_min' => isset($in['uptime']) ? (int)$in['uptime'] : null,
        'fw' => substr((string)($in['fw'] ?? ''), 0, 20),
        'alerted' => false,
    ]);
    if (!empty($prev['alerted'])) {
        ba_notify('✅ دستگاه پیامک دوباره وصل شد.');
    }
    // Hosts without cron: the heartbeat runs the daily reminder / weekly report when due.
    ob_start();
    try {
        jobs_due(false);
    } catch (Throwable $e) {
        error_log('jobs: ' . $e->getMessage());
    }
    ob_end_clean();
    out(['ok' => true]);
}

if ($route === 'bale') {
    // Always answer 200 so Bale doesn't retry forever on our own errors.
    if (!hash_equals(bot_webhook_key(), (string)($_GET['key'] ?? ''))) {
        fail('forbidden', 403);
    }
    $update = json_decode(file_get_contents('php://input'), true);
    if (!is_array($update)) {
        out(['ok' => true]);
    }
    // Bale re-sends an update it thinks timed out; handle each one only once.
    if (isset($update['update_id'])) {
        $seen = (array)ba_kv_get('bale:seen', []);
        if (in_array((int)$update['update_id'], $seen, true)) {
            out(['ok' => true, 'duplicate' => true]);
        }
        ba_kv_set('bale:seen', array_slice(array_merge($seen, [(int)$update['update_id']]), -100));
    }
    $handle = function () use ($update) {
        try {
            bot_handle_update($update);
        } catch (Throwable $e) {
            error_log('bale webhook: ' . $e->getMessage());
        }
    };
    $msg = $update['message'] ?? [];
    if (isset($msg['voice']) || isset($msg['audio'])) {
        // Local speech-to-text and the spoken answer take a few seconds on a
        // CPU: tell Bale "received" first, then work.
        @set_time_limit(300);
        out_then(['ok' => true], $handle);
    }
    $handle();
    out(['ok' => true]);
}

if ($route === 'ping') {
    out(['ok' => true, 'time' => date('c')]);
}

/* ------------------------------ app side ----------------------------- */

$app_token = $_SERVER['HTTP_X_APP_TOKEN'] ?? ($_GET['token'] ?? '');
if (!token_ok($app_token, $cfg['app_token'] ?? '')) {
    fail('رمز اپ نادرست است', 401);
}
$db = ba_db();
ba_otp_purge();

/** Second lock for OTPs: PIN from config, 5 wrong tries = 15 minutes locked. */
function require_otp_pin($cfg) {
    $pin = (string)($cfg['otp_pin'] ?? '');
    if (strlen($pin) < 4) {
        fail('برای دیدن رمزها اول otp_pin (حداقل ۴ رقم) را در config.php بگذار.', 503);
    }
    $lock = ba_kv_get('otp_lock', ['fails' => 0, 'until' => 0]);
    if ($lock['until'] > time()) {
        fail('به‌خاطر رمز اشتباه قفل شد؛ ' . ceil(($lock['until'] - time()) / 60) . ' دقیقه دیگر امتحان کن.', 429);
    }
    $given = ba_normalize((string)($_SERVER['HTTP_X_OTP_PIN'] ?? ''));
    if (!hash_equals($pin, $given)) {
        $lock['fails']++;
        if ($lock['fails'] >= 5) {
            $lock = ['fails' => 0, 'until' => time() + 900];
            ba_notify('⚠️ پنج بار رمز اشتباه برای دیدن رمزهای یکبار مصرف زده شد؛ ۱۵ دقیقه قفل شد.');
        }
        ba_kv_set('otp_lock', $lock);
        fail('PIN اشتباه است', 403);
    }
    ba_kv_set('otp_lock', ['fails' => 0, 'until' => 0]);
}

switch ($route) {

case 'settings':
    $info = ba_bale_enabled() ? ba_bale('getWebhookInfo', []) : null;
    $me = ba_bale_enabled() ? ba_kv_get('bale_bot_username') : null;
    out(['ok' => true, 'has_token' => !empty($cfg['bale_bot_token']), 'bot' => $me,
        'chat_id' => (string)($cfg['bale_chat_id'] ?? ''), 'webhook' => is_array($info) ? ($info['url'] ?? '') !== '' : null,
        'webhook_error' => is_array($info) ? ($info['last_error_message'] ?? null) : null,
        'waiting_for_start' => (int)ba_kv_get('bale_claim_until', 0) > time(),
        'polling' => (int)ba_kv_get('daemon:alive', 0) > time() - 120,   // background service fetches Bale messages
        'otp_to_bale' => !empty($cfg['otp_to_bale']), 'voice' => ba_voice_status(true),
        'voice_reply' => (bool)($cfg['bale_voice_reply'] ?? true)]);

case 'bale_connect':
    // Save the bot token (checked with getMe), point the bot's webhook at this
    // server and wait 10 minutes for the owner's /start to learn the chat id.
    require_post();
    $token = trim((string)($in['bale_bot_token'] ?? ''));
    if ($token !== '') {
        if (!preg_match('/^\d+:[A-Za-z0-9_\-]{10,}$/', $token)) {
            fail('شکل توکن درست نیست؛ از @botfather دوباره کپی کن.');
        }
        $old = $cfg['bale_bot_token'] ?? '';
        $cfg = ba_settings_save(['bale_bot_token' => $token]);
        $me = ba_bale('getMe', []);
        if (!is_array($me)) {
            $cfg = ba_settings_save(['bale_bot_token' => $old]);
            fail('بله این توکن را قبول نکرد (یا هاست به بله دسترسی ندارد).');
        }
        ba_kv_set('bale_bot_username', $me['username'] ?? null);
        if ($token !== $old) {
            $cfg = ba_settings_save(['bale_chat_id' => '']);   // new bot: claim again
        }
    }
    if (empty($cfg['bale_bot_token'])) {
        fail('اول توکن ربات را بده.');
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    $apiUrl = ($https ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\') . '/api.php';
    // When the background service (cron.php daemon) runs, it fetches the bot's
    // messages itself (polling): no webhook, and it works without https too.
    $polling = (int)ba_kv_get('daemon:alive', 0) > time() - 120;
    if (!$https && !$polling) {
        fail('ربات بله فقط به آدرس https وصل می‌شود. در کنترل‌پنل هاست SSL را فعال کن و اپ را با https باز کن'
            . ' (یا روی سرور بدون دامنه، سرویس bank-bot را با deploy-vps.sh راه بینداز).');
    }
    $cfg = ba_settings_save(['api_url' => $apiUrl, 'app_url' => dirname($apiUrl) . '/app/']);
    if (!$polling && !ba_bale('setWebhook', ['url' => $apiUrl . '?r=bale&key=' . bot_webhook_key()])) {
        fail('بله وب‌هوک را قبول نکرد. چند دقیقه بعد دوباره امتحان کن.');
    }
    if (empty($cfg['bale_chat_id'])) {
        ba_kv_set('bale_claim_until', time() + 600);
    } else {
        ba_notify('✅ ربات دوباره به حسابداری وصل شد.');
    }
    out(['ok' => true, 'bot' => ba_kv_get('bale_bot_username'), 'waiting_for_start' => empty($cfg['bale_chat_id']),
        'mode' => $polling ? 'polling' : 'webhook']);

case 'bale_forget_chat':
    require_post();
    ba_settings_save(['bale_chat_id' => '']);
    ba_kv_set('bale_claim_until', time() + 600);
    out(['ok' => true]);

case 'otp':
    require_otp_pin($cfg);
    out(['ok' => true, 'items' => ba_otp_active()]);

case 'otp_clear':
    require_post();
    require_otp_pin($cfg);
    if (!empty($in['id'])) {
        $db->prepare('DELETE FROM otps WHERE id = ?')->execute([(int)$in['id']]);
    } else {
        $db->exec('DELETE FROM otps');
    }
    out(['ok' => true]);

case 'otp_count':
    // No PIN: only says whether something is waiting, never the code.
    out(['ok' => true, 'count' => (int)$db->query('SELECT COUNT(*) FROM otps')->fetchColumn()]);

case 'senders':
    // Every sender ID seen, so the owner can tick which ones are the bank.
    $rows = $db->query("SELECT sender, COUNT(*) AS n, SUM(status = 'parsed') AS tx, SUM(status IN ('otp', 'otp_part')) AS otp,
        MAX(received_at) AS last FROM sms_raw GROUP BY sender ORDER BY last DESC LIMIT 40")->fetchAll();
    out(['ok' => true, 'items' => $rows, 'bank_senders' => ba_kv_get('bank_senders') ?: ($cfg['allowed_senders'] ?? [])]);

case 'senders_save':
    require_post();
    $list = array_values(array_filter(array_map('trim', (array)($in['senders'] ?? [])), 'strlen'));
    ba_kv_set('bank_senders', $list ?: null);
    out(['ok' => true]);

case 'people':
    out(['ok' => true, 'items' => ba_people(), 'totals' => ba_people_totals()]);

case 'person':
    $st = ba_person_statement((string)($_GET['name'] ?? ''));
    if (!$st) {
        fail('این شخص پیدا نشد', 404);
    }
    out(['ok' => true] + $st);

case 'person_save':
    require_post();
    $name = trim(preg_replace('/\s+/u', ' ', (string)($in['name'] ?? '')));
    $old = trim((string)($in['old_name'] ?? ''));
    if ($name === '') {
        fail('نام خالی است');
    }
    $opening = rial_from($in, 'opening_rial', 'opening_toman');
    $db->beginTransaction();
    if ($old !== '' && $old !== $name) {
        if (ba_person($name)) {
            $db->rollBack();
            fail('شخصی با این نام هست؛ نام دیگری بگذار.');
        }
        $db->prepare('UPDATE parties SET name = ? WHERE name = ?')->execute([$name, $old]);
        $db->prepare('UPDATE transactions SET party = ? WHERE party = ?')->execute([$name, $old]);
        $db->prepare('UPDATE bills SET party = ? WHERE party = ?')->execute([$name, $old]);
    }
    $db->prepare('INSERT INTO parties (name, uses, phone, note, opening) VALUES (?, 0, ?, ?, ?)
        ON CONFLICT(name) DO UPDATE SET phone = excluded.phone, note = excluded.note, opening = excluded.opening')
        ->execute([$name, trim((string)($in['phone'] ?? '')) ?: null, trim((string)($in['note'] ?? '')) ?: null, $opening]);
    $db->commit();
    out(['ok' => true, 'person' => ba_person($name)]);

case 'person_delete':
    require_post();
    $name = (string)($in['name'] ?? '');
    $q = $db->prepare("SELECT (SELECT COUNT(*) FROM transactions WHERE party = ?) + (SELECT COUNT(*) FROM bills WHERE party = ?)");
    $q->execute([$name, $name]);
    if ($q->fetchColumn()) {
        fail('این شخص سابقه دارد و حذف نمی‌شود.');
    }
    $db->prepare('DELETE FROM parties WHERE name = ?')->execute([$name]);
    out(['ok' => true]);

case 'bill_save':
    // Credit sale / purchase: no money moved yet, only the person's balance changes.
    require_post();
    $party = trim((string)($in['party'] ?? ''));
    $type = ($in['type'] ?? '') === 'purchase' ? 'purchase' : 'sale';
    $amount = abs(rial_from($in, 'amount_rial', 'amount_toman'));
    if ($party === '' || $amount <= 0) {
        fail('طرف حساب و مبلغ لازم است');
    }
    $due = !empty($in['due_date']) ? valid_date($in['due_date']) : null;
    $vals = [valid_date($in['date'] ?? ''), $party, $type, $amount, (int)($in['category_id'] ?? 0) ?: null,
        trim((string)($in['description'] ?? '')) ?: ($type === 'sale' ? 'فروش نسیه' : 'خرید نسیه'), $due];
    if (!empty($in['id'])) {
        $db->prepare('UPDATE bills SET date = ?, party = ?, type = ?, amount = ?, category_id = ?, description = ?, due_date = ? WHERE id = ?')
            ->execute(array_merge($vals, [(int)$in['id']]));
        $id = (int)$in['id'];
    } else {
        $db->prepare('INSERT INTO bills (date, party, type, amount, category_id, description, due_date, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')
            ->execute(array_merge($vals, [date('Y-m-d H:i:s')]));
        $id = (int)$db->lastInsertId();
    }
    ba_remember_party($party, $vals[4]);
    out(['ok' => true, 'id' => $id, 'balance' => ba_person($party)['balance']]);

case 'bill_delete':
    require_post();
    $db->prepare('DELETE FROM bills WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
    out(['ok' => true]);

case 'wallets':
    out(['ok' => true, 'items' => ba_wallets()]);

case 'wallet_save':
    require_post();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        fail('نام خالی است');
    }
    $kind = ($in['kind'] ?? '') === 'cash' ? 'cash' : 'bank';
    $opening = rial_from($in, 'opening_rial', 'opening_toman');
    if (!empty($in['id'])) {
        $db->prepare('UPDATE wallets SET name = ?, kind = ?, opening = ? WHERE id = ?')->execute([$name, $kind, $opening, (int)$in['id']]);
    } else {
        $db->prepare('INSERT INTO wallets (name, kind, opening) VALUES (?, ?, ?)')->execute([$name, $kind, $opening]);
    }
    out(['ok' => true]);

case 'report':
    $from = valid_date($_GET['from'] ?? date('Y-m-d', strtotime('-30 days')));
    $to = valid_date($_GET['to'] ?? date('Y-m-d'));
    out(['ok' => true, 'pl' => ba_profit_loss($from, $to), 'wallets' => ba_wallets(), 'people' => ba_people_totals()]);

case 'me':
    $voice = ba_voice_status();
    out(['ok' => true, 'stt' => $voice['stt'], 'accounting_webhook' => !empty($cfg['accounting_webhook']),
        'bale' => ba_bale_enabled() && !empty($cfg['bale_chat_id']), 'device' => ba_kv_get('device'),
        'otp_ready' => strlen((string)($cfg['otp_pin'] ?? '')) >= 4, 'wallets' => ba_wallets(),
        'tts' => $voice['tts'], 'push_subs' => (int)$db->query('SELECT COUNT(*) FROM push_subs')->fetchColumn()]);

case 'push_key':
    out(['ok' => true, 'key' => wp_vapid()['public']]);

case 'push_subscribe':
    require_post();
    $sub = $in['subscription'] ?? [];
    $ep = (string)($sub['endpoint'] ?? '');
    if (!preg_match('~^https://~', $ep) || empty($sub['keys']['p256dh']) || empty($sub['keys']['auth'])) {
        fail('اشتراک نوتیف نامعتبر است');
    }
    $db->prepare('INSERT INTO push_subs (endpoint, p256dh, auth, device, created_at) VALUES (?, ?, ?, ?, ?)
        ON CONFLICT(endpoint) DO UPDATE SET p256dh = excluded.p256dh, auth = excluded.auth, device = excluded.device')
        ->execute([$ep, $sub['keys']['p256dh'], $sub['keys']['auth'], substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 200), date('Y-m-d H:i:s')]);
    out(['ok' => true]);

case 'push_unsubscribe':
    require_post();
    $db->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([(string)($in['endpoint'] ?? '')]);
    out(['ok' => true]);

case 'push_test':
    require_post();
    $n = wp_notify_all(['title' => 'دستیار بانک', 'body' => '✅ نوتیف‌ها کار می‌کنند.', 'url' => '#/', 'tag' => 'test']);
    if (!$n) {
        fail('نوتیف به هیچ گوشی‌ای نرسید. اگر روی گوشی «اجازه» داده‌ای، چند دقیقه بعد دوباره امتحان کن؛ ممکن است هاست به سرور نوتیف اپل دسترسی نداشته باشد.');
    }
    out(['ok' => true, 'sent' => $n]);

case 'tts':
    // Persian speech made on this server (local Piper) for phones without a Persian voice (iPhone).
    require_post();
    $text = trim((string)($in['text'] ?? ''));
    if ($text === '' || mb_strlen($text) > 400) {
        fail('متن نامعتبر');
    }
    try {
        $file = ba_tts($text, 'mp3');
    } catch (RuntimeException $e) {
        fail($e->getMessage(), ba_voice_configured() ? 502 : 501);
    }
    header('Content-Type: audio/mpeg');
    header('Cache-Control: private, max-age=86400');
    header('Content-Length: ' . filesize($file));
    readfile($file);
    exit;

case 'interpret':
    require_post();
    out(['ok' => true, 'guess' => ba_interpret((string)($in['text'] ?? ''), ($in['direction'] ?? '') === 'in' ? 'in' : 'out')]);

case 'pending':
    $rows = $db->query("SELECT t.*, s.body AS sms_text FROM transactions t LEFT JOIN sms_raw s ON s.id = t.sms_id
        WHERE t.status = 'pending' ORDER BY t.occurred_at, t.id")->fetchAll();
    out(['ok' => true, 'items' => $rows]);

case 'transaction':
    $tx = ba_get_transaction((int)($_GET['id'] ?? 0));
    if (!$tx) {
        fail('پیدا نشد', 404);
    }
    if ($tx['sms_id']) {
        $q = $db->prepare('SELECT body FROM sms_raw WHERE id = ?');
        $q->execute([$tx['sms_id']]);
        $tx['sms_text'] = $q->fetchColumn();
    }
    out(['ok' => true, 'item' => $tx]);

case 'list':
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $q = $db->prepare("SELECT t.*, c.name AS category_name, c.kind AS category_kind, w.name AS wallet_name
        FROM transactions t LEFT JOIN categories c ON c.id = t.category_id LEFT JOIN wallets w ON w.id = t.wallet_id
        WHERE t.occurred_at >= ? AND t.occurred_at <= ? ORDER BY t.occurred_at DESC, t.id DESC");
    $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    $items = $q->fetchAll();
    $b = $db->prepare('SELECT b.*, c.name AS category_name FROM bills b LEFT JOIN categories c ON c.id = b.category_id
        WHERE b.date >= ? AND b.date <= ? ORDER BY b.date DESC, b.id DESC');
    $b->execute([$from, $to]);
    $bills = $b->fetchAll();
    $tot = ['in' => 0, 'out' => 0];
    foreach ($items as $t) {
        if ($t['status'] !== 'ignored') {
            $tot[$t['direction']] += (int)$t['amount'];
        }
    }
    out(['ok' => true, 'items' => $items, 'bills' => $bills, 'total_in' => $tot['in'], 'total_out' => $tot['out']]);

case 'confirm':
    require_post();
    $id = (int)($in['id'] ?? 0);
    $tx = ba_get_transaction($id);
    if (!$tx) {
        fail('پیدا نشد', 404);
    }
    $desc = trim((string)($in['description'] ?? ''));
    if ($desc === '') {
        fail('بابت چه بود؟ شرح خالی است.');
    }
    $tx = ba_confirm_tx($id, $desc, $in['party'] ?? '', $in['category_id'] ?? 0, $in['note'] ?? '', $in['counter_wallet_id'] ?? null);
    ba_kv_set('bot:draft:' . $id, null);
    out(['ok' => true, 'item' => $tx, 'synced' => $tx['synced_now']]);

case 'ignore':
    require_post();
    $db->prepare("UPDATE transactions SET status = 'ignored', note = COALESCE(?, note) WHERE id = ?")
        ->execute([trim((string)($in['note'] ?? '')) ?: null, (int)($in['id'] ?? 0)]);
    ba_acc_link_tx((int)($in['id'] ?? 0));
    out(['ok' => true]);

case 'reopen':
    require_post();
    $db->prepare("UPDATE transactions SET status = 'pending' WHERE id = ?")->execute([(int)($in['id'] ?? 0)]);
    ba_acc_link_tx((int)($in['id'] ?? 0));
    out(['ok' => true]);

case 'manual':
    // Cash / other-account movements, transfers between my wallets, or a bank
    // transaction whose SMS never arrived (found by reconciliation).
    require_post();
    $dir = ($in['direction'] ?? '') === 'in' ? 'in' : 'out';
    $amount = abs(rial_from($in, 'amount_rial', 'amount_toman'));
    if ($amount <= 0) {
        fail('مبلغ نامعتبر است');
    }
    $wallet = (int)($in['wallet_id'] ?? 0) ?: (int)$db->query("SELECT id FROM wallets WHERE kind = 'cash' ORDER BY id LIMIT 1")->fetchColumn();
    $date = valid_date($in['date'] ?? '');
    [$jy, $jm, $jd] = ba_g2j((int)substr($date, 0, 4), (int)substr($date, 5, 2), (int)substr($date, 8, 2));
    $db->prepare("INSERT INTO transactions (source, direction, amount, bank_date, occurred_at, status, wallet_id)
        VALUES ('manual', ?, ?, ?, ?, 'pending', ?)")
        ->execute([$dir, $amount, sprintf('%04d/%02d/%02d', $jy, $jm, $jd), $date . ' 12:00:00', $wallet]);
    $id = (int)$db->lastInsertId();
    try {
        $tx = ba_confirm_tx($id, trim((string)($in['description'] ?? '')) ?: 'ثبت دستی', $in['party'] ?? '',
            $in['category_id'] ?? 0, $in['note'] ?? '', $in['counter_wallet_id'] ?? null);
    } catch (InvalidArgumentException $e) {
        $db->prepare('DELETE FROM transactions WHERE id = ?')->execute([$id]);
        throw $e;
    }
    out(['ok' => true, 'item' => $tx]);

case 'tx_delete':
    // Only hand-made entries; bank SMS entries are evidence and stay.
    require_post();
    $db->prepare("DELETE FROM transactions WHERE id = ? AND source = 'manual'")->execute([(int)($in['id'] ?? 0)]);
    ba_acc_link_tx((int)($in['id'] ?? 0));
    out(['ok' => true]);

case 'categories':
    out(['ok' => true, 'items' => $db->query('SELECT * FROM categories ORDER BY sort_order, id')->fetchAll()]);

case 'category_save':
    require_post();
    $name = trim((string)($in['name'] ?? ''));
    if ($name === '') {
        fail('نام دسته خالی است');
    }
    $dir = in_array($in['direction'] ?? '', ['in', 'out', 'both'], true) ? $in['direction'] : 'both';
    $kind = in_array($in['kind'] ?? '', ['pl', 'party', 'transfer'], true) ? $in['kind'] : 'pl';
    $kw = trim(preg_replace('/\s*[,،]\s*/u', ',', (string)($in['keywords'] ?? '')), ',');
    if (!empty($in['id'])) {
        $db->prepare('UPDATE categories SET name = ?, direction = ?, keywords = ?, kind = ? WHERE id = ?')->execute([$name, $dir, $kw, $kind, (int)$in['id']]);
    } else {
        $db->prepare('INSERT INTO categories (name, direction, keywords, sort_order, kind) VALUES (?, ?, ?, 99, ?)')->execute([$name, $dir, $kw, $kind]);
    }
    out(['ok' => true]);

case 'category_delete':
    require_post();
    $db->prepare('DELETE FROM categories WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
    out(['ok' => true]);

case 'parties':
    out(['ok' => true, 'items' => $db->query('SELECT * FROM parties ORDER BY uses DESC LIMIT 300')->fetchAll()]);

case 'balance_check':
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-7 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $report = ba_balance_check($from, $to);
    $report['id'] = ba_save_reconciliation('balance', $report);
    out(['ok' => true, 'report' => $report]);

case 'reconcile':
    require_post();
    $csv = (string)($in['csv'] ?? '');
    if (isset($_FILES['file']) && is_uploaded_file($_FILES['file']['tmp_name'])) {
        $csv = file_get_contents($_FILES['file']['tmp_name']);
    }
    if (trim($csv) === '') {
        fail('فایل صورت‌حساب خالی است');
    }
    if (!mb_check_encoding($csv, 'UTF-8')) {
        $csv = mb_convert_encoding($csv, 'UTF-8', 'Windows-1256');   // Excel "CSV" on Persian Windows
    }
    $lines = ba_parse_statement_csv($csv);
    $report = ba_reconcile_statement($lines, $in['from'] ?? null, $in['to'] ?? null);
    $report['id'] = ba_save_reconciliation('statement', $report);
    out(['ok' => true, 'report' => $report]);

case 'reconciliations':
    $rows = $db->query('SELECT id, created_at, kind, date_from, date_to, ok, report FROM reconciliations ORDER BY id DESC LIMIT 20')->fetchAll();
    foreach ($rows as &$r) {
        $r['report'] = json_decode($r['report'], true);
    }
    out(['ok' => true, 'items' => $rows]);

case 'transcribe':
    // Speech-to-text on this server (local faster-whisper) for phones whose
    // browser can't recognise Persian (iPhone). Nothing is sent to the internet.
    require_post();
    if (!isset($_FILES['audio']) || !is_uploaded_file($_FILES['audio']['tmp_name'])) {
        fail('فایل صدا نرسید');
    }
    try {
        $text = ba_transcribe($_FILES['audio']['tmp_name'], $_FILES['audio']['type'], $_FILES['audio']['name']);
    } catch (RuntimeException $e) {
        fail($e->getMessage(), ba_voice_configured() ? 502 : 501);
    }
    out(['ok' => true, 'text' => $text]);

case 'export':
    // Excel-friendly CSV of confirmed transactions, for importing into the accounting program.
    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $q = $db->prepare("SELECT t.*, c.name AS category_name FROM transactions t LEFT JOIN categories c ON c.id = t.category_id
        WHERE t.status = 'confirmed' AND t.occurred_at >= ? AND t.occurred_at <= ? ORDER BY t.occurred_at, t.id");
    $q->execute([$from . ' 00:00:00', $to . ' 23:59:59']);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="bank-' . $from . '_' . $to . '.csv"');
    $fh = fopen('php://output', 'w');
    fwrite($fh, "\xEF\xBB\xBF");
    ba_csv_put($fh, ['شناسه', 'تاریخ', 'ساعت', 'نوع', 'مبلغ (ریال)', 'طرف حساب', 'دسته', 'شرح', 'یادداشت', 'مانده بانک (ریال)', 'حساب']);
    foreach ($q as $t) {
        ba_csv_put($fh, [$t['id'], $t['bank_date'], $t['bank_time'], $t['direction'] === 'in' ? 'دریافت' : 'پرداخت', $t['amount'],
            $t['party'], $t['category_name'], $t['description'], $t['note'], $t['balance'], $t['account']]);
    }
    fclose($fh);
    exit;

default:
    fail('مسیر نامعتبر', 404);
}
