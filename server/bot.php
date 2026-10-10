<?php
/**
 * Bank assistant - two-way Bale bot.
 *
 * The bot asks "what was this for?" about each new transaction; the owner
 * answers in the chat by text or voice message, checks what the bot
 * understood, and files it with one tap. Only the chat id in config
 * (bale_chat_id) is served.
 *
 * Conversation state (kv table):
 *   bot:current        transaction the next free-text answer belongs to
 *   bot:skipped        ids put off with "later" (until the next new SMS)
 *   bot:draft:<id>     what was understood, waiting for "ثبت"
 * bot_messages maps each question message to its transaction, so replying
 * to an older question answers that one.
 */

require_once __DIR__ . '/lib.php';

/** Secret part of the webhook URL, derived from the device token. */
function bot_webhook_key() {
    return substr(hash_hmac('sha256', 'bale-webhook', ba_config()['device_token'] ?? ''), 0, 32);
}

function bot_when($tx) {
    return $tx['bank_date'] ? trim($tx['bank_date'] . ' ' . $tx['bank_time']) : substr($tx['occurred_at'], 0, 16);
}

function bot_remember_message($result, $tx_id) {
    if (is_array($result) && isset($result['message_id'])) {
        ba_db()->prepare('INSERT OR REPLACE INTO bot_messages (message_id, tx_id) VALUES (?, ?)')
            ->execute([(int)$result['message_id'], (int)$tx_id]);
    }
}

/** Asks about one transaction. */
function bot_ask($tx) {
    $verb = $tx['direction'] === 'in' ? '🟢 واریز' : '🔴 برداشت';
    $text = $verb . ' ' . ba_toman($tx['amount']) . (!empty($tx['wallet_name']) ? ' · 💳 ' . $tx['wallet_name'] : '') . "\n🕓 " . bot_when($tx)
        . ($tx['balance'] !== null ? "\nمانده: " . ba_toman($tx['balance']) : '')
        . "\n\nبابت چی بود؟ بنویس یا ویس بفرست.";
    $res = ba_notify($text, [[
        ['text' => '⏭ بعداً', 'callback_data' => 'later:' . $tx['id']],
        ['text' => '🚫 نادیده بگیر', 'callback_data' => 'ign:' . $tx['id']],
    ]]);
    bot_remember_message($res, $tx['id']);
    ba_kv_set('bot:current', (int)$tx['id']);
    return $res;
}

/** Oldest unanswered transaction that wasn't put off. */
function bot_next_pending() {
    $skipped = ba_kv_get('bot:skipped', []);
    foreach (ba_db()->query("SELECT id FROM transactions WHERE status = 'pending' ORDER BY occurred_at, id") as $r) {
        if (!in_array((int)$r['id'], $skipped, true)) {
            return ba_get_transaction($r['id']);
        }
    }
    return null;
}

function bot_ask_next() {
    $tx = bot_next_pending();
    if ($tx) {
        return bot_ask($tx);
    }
    ba_kv_set('bot:current', null);
    $left = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    return ba_notify($left ? "✅ فعلاً سؤالی نیست ({$left} مورد را گذاشتی برای بعد؛ /pending بزن تا دوباره بپرسم)."
        : '✅ همه‌ی تراکنش‌ها جواب گرفتند.');
}

/** Called when a new transaction SMS arrives. */
function bot_on_new_transaction($tx) {
    ba_kv_set('bot:skipped', []);
    return bot_ask($tx);
}

function bot_category_name($id) {
    if (!$id) {
        return null;
    }
    $q = ba_db()->prepare('SELECT name FROM categories WHERE id = ?');
    $q->execute([(int)$id]);
    return $q->fetchColumn() ?: null;
}

/** Shows what was understood and waits for a tap on "ثبت". */
function bot_show_draft($tx, array $draft, $edit_message_id = null) {
    ba_kv_set('bot:draft:' . $tx['id'], $draft);
    $text = '📝 ' . ($tx['direction'] === 'in' ? 'واریز ' : 'برداشت ') . ba_toman($tx['amount'])
        . "\nبابت: " . $draft['description']
        . "\n👤 طرف حساب: " . ($draft['party'] ?: '—')
        . "\n📂 دسته: " . (bot_category_name($draft['category_id']) ?: '—')
        . "\n\nدرسته؟";
    $kb = [
        [['text' => '✅ ثبت', 'callback_data' => 'ok:' . $tx['id']], ['text' => '📂 تغییر دسته', 'callback_data' => 'cat:' . $tx['id']]],
        [['text' => '👤 بدون طرف حساب', 'callback_data' => 'np:' . $tx['id']], ['text' => '🚫 نادیده', 'callback_data' => 'ign:' . $tx['id']]],
    ];
    if ($edit_message_id) {
        ba_voice_capture('add', $text);
        return ba_bale('editMessageText', ['chat_id' => ba_config()['bale_chat_id'], 'message_id' => $edit_message_id,
            'text' => $text, 'reply_markup' => ['inline_keyboard' => $kb]]);
    }
    $res = ba_notify($text, $kb);
    bot_remember_message($res, $tx['id']);
    return $res;
}

function bot_finish($tx_id, $message_id, $text) {
    ba_kv_set('bot:draft:' . $tx_id, null);
    if ($message_id) {
        ba_voice_capture('add', $text);
        ba_bale('editMessageText', ['chat_id' => ba_config()['bale_chat_id'], 'message_id' => $message_id, 'text' => $text]);
    } else {
        ba_notify($text);
    }
    bot_ask_next();
}

/** Turns an answer (typed or transcribed) into a draft for the right transaction. */
function bot_handle_answer($text, $reply_to_id) {
    $tx = null;
    if ($reply_to_id) {
        $q = ba_db()->prepare('SELECT tx_id FROM bot_messages WHERE message_id = ?');
        $q->execute([(int)$reply_to_id]);
        if ($id = $q->fetchColumn()) {
            $tx = ba_get_transaction($id);
        }
    }
    if (!$tx && ($cur = ba_kv_get('bot:current'))) {
        $tx = ba_get_transaction($cur);
    }
    if (!$tx) {
        $tx = bot_next_pending();
    }
    if (!$tx) {
        ba_notify('تراکنش بی‌جوابی نیست. 🙂');
        return;
    }
    ba_kv_set('bot:current', (int)$tx['id']);

    $draft = ba_kv_get('bot:draft:' . $tx['id']);
    if ($draft && ba_kv_get('bot:await_party') === (int)$tx['id']) {
        // The answer is the name we asked for.
        ba_kv_set('bot:await_party', null);
        $draft['party'] = trim($text);
        bot_show_draft($tx, $draft);
        return;
    }
    if ($draft && ba_is_yes($text)) {
        bot_file_draft($tx, $draft, null);
        return;
    }
    $a = ba_norm_text($text);
    if (preg_match('/^(بعدی|بعدا|رد کن|بگذر)/u', $a)) {
        bot_skip($tx['id'], null);
        return;
    }
    if (preg_match('/^(نادیده|حساب نکن|ثبت نکن|تکراری)/u', $a)) {
        ba_db()->prepare("UPDATE transactions SET status = 'ignored' WHERE id = ?")->execute([$tx['id']]);
        bot_finish($tx['id'], null, '🚫 نادیده گرفته شد.');
        return;
    }
    bot_show_draft($tx, ba_interpret($text, $tx['direction']));
}

function bot_skip($tx_id, $message_id) {
    $skipped = ba_kv_get('bot:skipped', []);
    $skipped[] = (int)$tx_id;
    ba_kv_set('bot:skipped', array_values(array_unique($skipped)));
    if ($message_id) {
        ba_bale('editMessageReplyMarkup', ['chat_id' => ba_config()['bale_chat_id'], 'message_id' => $message_id,
            'reply_markup' => ['inline_keyboard' => []]]);
    }
    bot_ask_next();
}

/** Buttons under an ESP32 relay message: ✍️ record (ans) / 🚫 ignore (ign). */
function bot_relay_button(array $msg, $action) {
    $owner = ba_config()['bale_chat_id'];
    $tx = bot_relay_tx($msg);
    if (!$tx) {
        ba_bale('sendMessage', ['chat_id' => $owner, 'reply_to_message_id' => $msg['message_id'] ?? null,
            'text' => 'این پیامک واریز یا برداشت نیست؛ چیزی ثبت نشد.']);
        return;
    }
    $mid = $msg['message_id'] ?? null;
    if ($action === 'ign') {
        ba_db()->prepare("UPDATE transactions SET status = 'ignored' WHERE id = ? AND status = 'pending'")->execute([$tx['id']]);
        ba_bale('editMessageReplyMarkup', ['chat_id' => $owner, 'message_id' => $mid,
            'reply_markup' => ['inline_keyboard' => [[['text' => '🚫 نادیده گرفته شد', 'callback_data' => 'relay:noop']]]]]);
        return;
    }
    if ($action === 'noop') {
        return;
    }
    if ($tx['status'] === 'confirmed') {
        ba_bale('sendMessage', ['chat_id' => $owner, 'reply_to_message_id' => $mid,
            'text' => '✅ این تراکنش قبلاً ثبت شده: ' . $tx['description']]);
        return;
    }
    ba_kv_set('bot:current', (int)$tx['id']);
    $res = ba_bale('sendMessage', ['chat_id' => $owner, 'reply_to_message_id' => $mid,
        'text' => ($tx['direction'] === 'in' ? '🟢 واریز ' : '🔴 برداشت ') . ba_toman($tx['amount']) . "\nبابت چی بود؟ بنویس یا ویس بفرست.",
        'reply_markup' => ['inline_keyboard' => [[
            ['text' => '⏭ بعداً', 'callback_data' => 'later:' . $tx['id']],
            ['text' => '🚫 نادیده بگیر', 'callback_data' => 'ign:' . $tx['id']],
        ]]]]);
    bot_remember_message($res, $tx['id']);
}

/** Confirms a draft; asks for the person's name when the category needs one. */
function bot_file_draft($tx, array $draft, $message_id) {
    try {
        $done = ba_confirm_tx($tx['id'], $draft['description'], $draft['party'], $draft['category_id']);
    } catch (InvalidArgumentException $e) {
        ba_kv_set('bot:await_party', (int)$tx['id']);
        ba_kv_set('bot:current', (int)$tx['id']);
        ba_notify('👤 ' . $e->getMessage() . ' اسم شخص را بنویس یا بگو.');
        return;
    }
    $line = '✅ ثبت شد: ' . $done['description']
        . ($done['party'] ? ' · ' . $done['party'] : '') . ($done['category_name'] ? ' · ' . $done['category_name'] : '');
    if ($done['party'] && $done['category_kind'] === 'party') {
        $bal = ba_person($done['party'])['balance'];
        $line .= "\n👤 مانده حساب {$done['party']}: " . bot_balance_text($bal);
    }
    if ($done['synced_now']) {
        $line .= "\n(به حسابداری رفت)";
    }
    bot_finish($tx['id'], $message_id, $line);
}

function bot_balance_text($balance) {
    if ($balance > 0) {
        return ba_toman($balance) . ' بدهکار است (طلب تو)';
    }
    if ($balance < 0) {
        return ba_toman($balance) . ' طلبکار است (بدهی تو)';
    }
    return 'تسویه ✅';
}

function bot_people() {
    $people = array_values(array_filter(ba_people(), fn($p) => $p['balance'] !== 0));
    $t = ba_people_totals();
    $lines = ['👥 حساب اشخاص', 'طلب تو از دیگران: ' . ba_toman($t['receivable']), 'بدهی تو به دیگران: ' . ba_toman($t['payable']), ''];
    foreach (array_slice($people, 0, 15) as $p) {
        $lines[] = ($p['balance'] > 0 ? '🟢 ' : '🔴 ') . $p['name'] . ': ' . bot_balance_text($p['balance']) . ($p['overdue'] ? ' ⏰' : '');
    }
    if (!$people) {
        $lines[] = 'حساب همه تسویه است.';
    }
    $lines[] = "\nبرای ریز حساب: /p نام";
    ba_notify(implode("\n", $lines));
}

function bot_person($name) {
    $name = trim($name);
    $st = ba_person_statement($name);
    if (!$st) {
        // forgiving match: first person whose name contains what was typed
        foreach (ba_people() as $p) {
            if ($name !== '' && mb_strpos(ba_norm_text($p['name']), ba_norm_text($name)) !== false) {
                $st = ba_person_statement($p['name']);
                break;
            }
        }
    }
    if (!$st) {
        ba_notify("«{$name}» پیدا نشد. /people را بزن.");
        return;
    }
    $lines = ['👤 ' . $st['person']['name'] . ': ' . bot_balance_text($st['person']['balance'])];
    foreach (array_slice(array_reverse(array_filter($st['rows'], fn($r) => $r['effect'] !== 0)), 0, 8) as $r) {
        $lines[] = ba_iso_to_jalali($r['date']) . ' · ' . $r['title'] . ' ' . ba_toman($r['amount'])
            . ($r['description'] ? ' · ' . $r['description'] : '');
    }
    ba_notify(implode("\n", $lines));
}

/* ------------------------------------------------------------------ */
/* SMS relayed by the ESP32 (firmware/bale_direct) through Bale         */
/* ------------------------------------------------------------------ */
/*
 * The ESP32 posts each bank SMS into the owner's chat as this same bot,
 * over the SIM card's internet. A bot never receives its own messages, so
 * the SMS reaches this program when the owner taps a button under it or
 * replies to it: both updates carry the full message text. Format:
 *   <summary lines>\n──────────\n<raw SMS>\n──────────\n<question>
 */
const BOT_RELAY_SEP = '──────────';

/** Raw SMS inside an ESP32 relay message, or null if it isn't one. */
function bot_relay_sms_text($text) {
    $parts = explode(BOT_RELAY_SEP, (string)$text);
    if (count($parts) < 2) {
        return null;
    }
    $sms = trim($parts[1]);
    if ($sms === '' || mb_strpos($parts[0], '🔐') !== false) {   // one-time passwords are never booked
        return null;
    }
    return $sms;
}

/**
 * Books the SMS of a relay message (once; repeats return the same
 * transaction) and remembers that message as asking about it.
 */
function bot_relay_tx(array $msg) {
    $mid = (int)($msg['message_id'] ?? 0);
    if ($mid) {
        $q = ba_db()->prepare('SELECT tx_id FROM bot_messages WHERE message_id = ?');
        $q->execute([$mid]);
        if ($id = $q->fetchColumn()) {
            return ba_get_transaction($id);
        }
    }
    $sms = bot_relay_sms_text($msg['text'] ?? '');
    if ($sms === null) {
        return null;
    }
    $res = ba_ingest_sms('ESP32 via Bale', $sms, isset($msg['date']) ? date('Y-m-d H:i:s', (int)$msg['date']) : null);
    $tx = $res['transaction'] ?? null;
    if ($tx && $mid) {
        bot_remember_message(['message_id' => $mid], $tx['id']);
    }
    return $tx;
}

/** Downloads a voice message from Bale and transcribes it on this server. */
function bot_voice_to_text($file_id) {
    if (!ba_voice_configured()) {
        throw new RuntimeException('تبدیل صدا به متن روی سرور هنوز نصب نشده (voice/install.sh)');
    }
    $file = ba_bale('getFile', ['file_id' => $file_id]);
    if (!is_array($file) || empty($file['file_path'])) {
        throw new RuntimeException('دانلود ویس از بله نشد');
    }
    $cfg = ba_config();
    $base = rtrim($cfg['bale_api_base'] ?? 'https://tapi.bale.ai', '/');
    $ch = ba_curl($base . '/file/bot' . $cfg['bale_bot_token'] . '/' . $file['file_path'], [CURLOPT_TIMEOUT => 30]);
    $audio = curl_exec($ch);
    curl_close($ch);
    if ($audio === false || $audio === '') {
        throw new RuntimeException('دانلود ویس از بله نشد');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'bav');
    file_put_contents($tmp, $audio);
    try {
        return ba_transcribe($tmp, 'audio/ogg', 'voice.ogg');   // local faster-whisper
    } finally {
        @unlink($tmp);
    }
}

/** Answer voice with voice? config bale_voice_reply (default on) and local TTS present. */
function bot_voice_reply_on() {
    return (ba_config()['bale_voice_reply'] ?? true) && ba_voice_configured();
}

/**
 * Speaks what the bot just wrote as one voice message (local Piper TTS).
 * The text messages with their buttons were already sent, so a failure here
 * loses nothing: it is only logged.
 */
function bot_speak(array $texts, $reply_to = null) {
    $text = trim(implode("\n", $texts));
    if ($text === '') {
        return null;
    }
    try {
        return ba_send_voice($text, $reply_to);
    } catch (Throwable $e) {
        error_log('voice reply: ' . $e->getMessage());
        return null;
    }
}

function bot_help() {
    return "دستیار بانک 🏦\n"
        . "هر پیامک واریز/برداشت که برسد، اینجا می‌پرسم بابت چی بود.\n"
        . "• جواب را بنویس یا ویس بفرست؛ مثلا «حواله به علی رضایی بابت خرید بذر»\n"
        . "• برای جواب به سؤال قدیمی‌تر، روی همان پیام Reply کن\n"
        . "• «بعدی» = بعداً، «نادیده» = حساب نکن\n"
        . "/pending سؤال بعدی\n/report گزارش مغایرت ۷ روز اخیر\n/today خلاصه امروز\n"
        . "/people طلب و بدهی اشخاص\n/p نام — ریز حساب یک نفر";
}

function bot_report() {
    $r = ba_balance_check(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
    ba_save_reconciliation('balance', $r);
    $lines = ['📊 مغایرت ' . ba_iso_to_jalali($r['from']) . ' تا ' . ba_iso_to_jalali($r['to'])];
    $lines[] = $r['ok'] ? '✅ زنجیره مانده‌ها کامل است.' : '⚠️ ' . count($r['gaps']) . ' جای خالی:';
    foreach ($r['gaps'] as $g) {
        $lines[] = '• بین ' . $g['after_date'] . ' و ' . $g['before_date'] . ': ' . ba_toman($g['missing'])
            . ($g['missing'] > 0 ? ' واریز' : ' برداشت') . ' بدون پیامک';
    }
    if ($r['last_balance'] !== null) {
        $lines[] = 'آخرین مانده: ' . ba_toman($r['last_balance']);
    }
    if ($r['pending']) {
        $lines[] = '🕓 ' . $r['pending'] . ' تراکنش بی‌جواب';
    }
    ba_notify(implode("\n", $lines));
}

function bot_today() {
    $q = ba_db()->prepare("SELECT direction, SUM(amount) s, COUNT(*) n FROM transactions
        WHERE status != 'ignored' AND occurred_at >= ? GROUP BY direction");
    $q->execute([date('Y-m-d') . ' 00:00:00']);
    $t = ['in' => [0, 0], 'out' => [0, 0]];
    foreach ($q as $r) {
        $t[$r['direction']] = [(int)$r['s'], (int)$r['n']];
    }
    ba_notify('📅 امروز ' . ba_iso_to_jalali(date('Y-m-d'))
        . "\n🟢 واریز: " . ba_toman($t['in'][0]) . ' (' . $t['in'][1] . ' مورد)'
        . "\n🔴 برداشت: " . ba_toman($t['out'][0]) . ' (' . $t['out'][1] . ' مورد)');
}

/** Entry point for one webhook update from Bale. */
function bot_handle_update(array $u) {
    $owner = (string)(ba_config()['bale_chat_id'] ?? '');

    if (isset($u['callback_query'])) {
        $cb = $u['callback_query'];
        ba_bale('answerCallbackQuery', ['callback_query_id' => $cb['id']]);
        $chat = (string)($cb['message']['chat']['id'] ?? '');
        if ($owner === '' || $chat !== $owner) {
            return;
        }
        $msg_id = $cb['message']['message_id'] ?? null;
        $parts = explode(':', (string)($cb['data'] ?? ''));
        if ($parts[0] === 'relay') {
            bot_relay_button($cb['message'], $parts[1] ?? 'ans');
            return;
        }
        $tx = ba_get_transaction((int)($parts[1] ?? 0));
        if (!$tx) {
            return;
        }
        $draft = ba_kv_get('bot:draft:' . $tx['id']);
        switch ($parts[0]) {
            case 'ok':
                if (!$draft) {
                    ba_notify('اول بنویس بابت چی بود.');
                    return;
                }
                bot_file_draft($tx, $draft, $msg_id);
                return;
            case 'cat':
                $rows = [];
                $q = ba_db()->prepare("SELECT id, name FROM categories WHERE direction IN (?, 'both') ORDER BY sort_order, id");
                $q->execute([$tx['direction']]);
                foreach (array_chunk($q->fetchAll(), 2) as $pair) {
                    $rows[] = array_map(fn($c) => ['text' => $c['name'], 'callback_data' => 'sc:' . $tx['id'] . ':' . $c['id']], $pair);
                }
                ba_bale('editMessageReplyMarkup', ['chat_id' => $owner, 'message_id' => $msg_id, 'reply_markup' => ['inline_keyboard' => $rows]]);
                return;
            case 'sc':
                if ($draft) {
                    $draft['category_id'] = (int)($parts[2] ?? 0) ?: null;
                    bot_show_draft($tx, $draft, $msg_id);
                }
                return;
            case 'np':
                if ($draft) {
                    $draft['party'] = '';
                    bot_show_draft($tx, $draft, $msg_id);
                }
                return;
            case 'ign':
                ba_db()->prepare("UPDATE transactions SET status = 'ignored' WHERE id = ?")->execute([$tx['id']]);
                bot_finish($tx['id'], $msg_id, '🚫 ' . ($tx['direction'] === 'in' ? 'واریز ' : 'برداشت ') . ba_toman($tx['amount']) . ' نادیده گرفته شد.');
                return;
            case 'later':
                bot_skip($tx['id'], $msg_id);
                return;
        }
        return;
    }

    $m = $u['message'] ?? null;
    if (!$m) {
        return;
    }
    $chat = (string)($m['chat']['id'] ?? '');
    $text = trim((string)($m['text'] ?? ''));

    if ($owner === '' || $chat !== $owner) {
        if (strpos($text, '/start') === 0) {
            // Right after "connect" in the app, «/start CODE» with the code the app shows claims
            // the bot (10-minute window); a plain /start from someone who found the bot does not.
            $code = (string)ba_kv_get('bale_claim_code', '');
            $given = trim(substr($text, 6));
            if ($owner === '' && (int)ba_kv_get('bale_claim_until', 0) > time() && $code !== '' && hash_equals($code, ba_normalize($given))) {
                ba_kv_set('bale_claim_code', null);
                ba_settings_save(['bale_chat_id' => $chat]);
                ba_kv_set('bale_claim_until', null);
                ba_notify("✅ ربات به حسابداری تو وصل شد.\n\n" . bot_help());
                return;
            }
            // Otherwise only tell whoever writes their own chat id, nothing else.
            ba_bale('sendMessage', ['chat_id' => $chat, 'text' => "شناسه چت شما: {$chat}"]);
        }
        return;
    }

    if ($text === '/start' || $text === '/help') {
        ba_notify(bot_help() . "\n\nشناسه چت: {$chat}");
        return;
    }
    if ($text === '/pending' || $text === 'بی‌جواب' || $text === 'بی جواب') {
        ba_kv_set('bot:skipped', []);
        bot_ask_next();
        return;
    }
    if ($text === '/report') {
        bot_report();
        return;
    }
    if ($text === '/today') {
        bot_today();
        return;
    }
    if ($text === '/people') {
        bot_people();
        return;
    }
    if (preg_match('~^/p\s+(.+)$~u', $text, $pm)) {
        bot_person($pm[1]);
        return;
    }

    // A reply to an SMS the ESP32 relayed: book that SMS first, so the answer lands on it.
    if (!empty($m['reply_to_message'])) {
        bot_relay_tx($m['reply_to_message']);
    }

    $voice = $m['voice'] ?? ($m['audio'] ?? null);
    if ($voice) {
        try {
            $text = bot_voice_to_text($voice['file_id']);
        } catch (Throwable $e) {
            ba_notify('🎙 ' . $e->getMessage() . '. لطفاً بنویس.');
            return;
        }
        if ($text === '') {
            ba_notify('🎙 چیزی از ویس نفهمیدم، دوباره بگو یا بنویس.');
            return;
        }
        ba_notify('🎙 «' . $text . '»');
        if (bot_voice_reply_on()) {
            // Spoken question -> spoken answer: whatever the bot writes now is also said.
            ba_voice_capture('start');
            try {
                bot_handle_answer($text, $m['reply_to_message']['message_id'] ?? null);
            } finally {
                $said = ba_voice_capture('stop');
            }
            bot_speak($said, $m['message_id'] ?? null);
            return;
        }
    }
    if ($text === '') {
        return;
    }
    bot_handle_answer($text, $m['reply_to_message']['message_id'] ?? null);
}
