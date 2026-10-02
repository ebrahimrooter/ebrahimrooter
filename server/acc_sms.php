<?php
/**
 * Accounting module - SMS panel (پنل پیامک).
 *
 * Providers:
 *   kavenegar  api key, sender line; patterns = Kavenegar "verify/lookup" templates
 *   smsir      api key (x-api-key), line number; patterns = sms.ir template id
 *   custom     any panel with a plain HTTP API: a URL with {to}, {text}, {from}
 *              (e.g. https://panel.example/send?user=..&pass=..&to={to}&text={text})
 *   test       nothing is sent; messages are only logged (for trying the panel)
 * Messages are queued in acc_sms_log and sent in batches (right away for a
 * few, the rest by the background job), so a group send never times out.
 */

require_once __DIR__ . '/acc_core.php';

function acc_sms_schema()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $pdo = acc_db();
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_sms_templates (id INTEGER PRIMARY KEY, title TEXT NOT NULL, body TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_sms_patterns (id INTEGER PRIMARY KEY, title TEXT NOT NULL, code TEXT NOT NULL,
        params TEXT DEFAULT '[]')");
    $pdo->exec("CREATE TABLE IF NOT EXISTS acc_sms_log (id INTEGER PRIMARY KEY, created_at TEXT, user TEXT DEFAULT '',
        mobile TEXT NOT NULL, person_id INTEGER, text TEXT DEFAULT '', pattern_id INTEGER, pattern_values TEXT,
        status TEXT DEFAULT 'queued', provider TEXT DEFAULT '', provider_id TEXT DEFAULT '', error TEXT DEFAULT '',
        cost REAL DEFAULT 0, sent_at TEXT, batch TEXT DEFAULT '', kind TEXT DEFAULT 'manual')");
    $pdo->exec('CREATE INDEX IF NOT EXISTS acc_sms_status ON acc_sms_log(status)');
    if (!$pdo->query('SELECT COUNT(*) FROM acc_sms_templates')->fetchColumn()) {
        $ins = $pdo->prepare('INSERT INTO acc_sms_templates (title, body) VALUES (?, ?)');
        foreach ([
            ['یادآوری بدهی', "{name} عزیز، مانده حساب شما {balance} ریال است. لطفاً برای تسویه اقدام کنید.\n{company}"],
            ['تشکر از خرید', "{name} عزیز، از خرید شما سپاسگزاریم. فاکتور {number} به مبلغ {total} ریال.\n{company}"],
            ['سررسید چک', "{name} عزیز، چک شماره {cheque} به مبلغ {amount} ریال در تاریخ {due_date} سررسید می‌شود.\n{company}"],
        ] as $t) {
            $ins->execute($t);
        }
    }
}

const ACC_SMS_KEYS = ['sms_provider', 'sms_api_key', 'sms_sender', 'sms_custom_url', 'sms_custom_method', 'sms_auto_invoice',
    'sms_invoice_template', 'sms_cheque_days', 'sms_cheque_template'];

function acc_sms_settings()
{
    $s = (array)ba_kv_get('acc_sms_settings', []);
    return $s + ['sms_provider' => 'test', 'sms_api_key' => '', 'sms_sender' => '', 'sms_custom_url' => '', 'sms_custom_method' => 'GET',
        'sms_auto_invoice' => false, 'sms_invoice_template' => 0, 'sms_cheque_days' => 0, 'sms_cheque_template' => 0];
}

/** Iranian mobile in 09xxxxxxxxx form, or null. */
function acc_sms_mobile($m)
{
    $m = preg_replace('/\D/', '', ba_normalize((string)$m));
    if (preg_match('/^(?:0098|98|0)?(9\d{9})$/', $m, $mm)) {
        return '0' . $mm[1];
    }
    return null;
}

/** {name}, {balance}... filled for a person (and extra values). */
function acc_sms_render($body, array $person = null, array $extra = [])
{
    $c = acc_company();
    $vals = ['company' => $c['name'], 'date' => acc_today()];
    if ($person) {
        $vals += ['name' => $person['name'], 'code' => $person['code'], 'balance' => number_format(abs((float)$person['balance'])),
            'mobile' => $person['mobile']];
    } else {
        $vals += ['name' => '', 'code' => '', 'balance' => '', 'mobile' => ''];   // a number typed in, no person
    }
    foreach ($extra as $k => $v) {
        $vals[$k] = is_float($v) || is_int($v) ? number_format($v) : (string)$v;
    }
    return preg_replace_callback('/\{(\w+)\}/u', fn($m) => array_key_exists($m[1], $vals) ? $vals[$m[1]] : $m[0], (string)$body);
}

/** Queues messages: [['mobile' =>, 'text' =>, 'person_id' =>, 'pattern_id' =>, 'values' => []], ...] */
function acc_sms_queue(array $msgs, $user = '', $kind = 'manual')
{
    acc_sms_schema();
    $batch = bin2hex(random_bytes(4));
    $ok = $bad = 0;
    acc_tx(function () use ($msgs, $user, $kind, $batch, &$ok, &$bad) {
        foreach ($msgs as $m) {
            $mobile = acc_sms_mobile($m['mobile'] ?? '');
            $text = trim((string)($m['text'] ?? ''));
            if (!$mobile || ($text === '' && empty($m['pattern_id']))) {
                $bad++;
                continue;
            }
            acc_insert('acc_sms_log', ['created_at' => acc_now(), 'user' => $user, 'mobile' => $mobile, 'person_id' => $m['person_id'] ?? null,
                'text' => $text, 'pattern_id' => $m['pattern_id'] ?? null,
                'pattern_values' => isset($m['values']) ? json_encode($m['values'], JSON_UNESCAPED_UNICODE) : null,
                'status' => 'queued', 'batch' => $batch, 'kind' => $kind]);
            $ok++;
        }
    });
    return ['batch' => $batch, 'queued' => $ok, 'invalid' => $bad];
}

/** Sends up to $limit queued messages. Returns how many were tried. */
function acc_sms_process($limit = 20)
{
    acc_sms_schema();
    $rows = acc_all("SELECT * FROM acc_sms_log WHERE status = 'queued' ORDER BY id LIMIT " . (int)$limit);
    $cfg = acc_sms_settings();
    foreach ($rows as $r) {
        // claim the row first so two workers never send it twice
        $claimed = acc_q("UPDATE acc_sms_log SET status = 'sending' WHERE id = ? AND status = 'queued'", [$r['id']])->rowCount();
        if (!$claimed) {
            continue;
        }
        try {
            $pattern = $r['pattern_id'] ? acc_row('SELECT * FROM acc_sms_patterns WHERE id = ?', [$r['pattern_id']]) : null;
            $res = acc_sms_send_one($cfg, $r['mobile'], $r['text'], $pattern, json_decode((string)$r['pattern_values'], true) ?: []);
            acc_update('acc_sms_log', $r['id'], ['status' => 'sent', 'provider' => $cfg['sms_provider'], 'provider_id' => (string)($res['id'] ?? ''),
                'cost' => (float)($res['cost'] ?? 0), 'sent_at' => acc_now(), 'error' => '']);
        } catch (Throwable $e) {
            acc_update('acc_sms_log', $r['id'], ['status' => 'failed', 'provider' => $cfg['sms_provider'], 'error' => mb_substr($e->getMessage(), 0, 300)]);
        }
    }
    return count($rows);
}

function acc_sms_http($method, $url, $body = null, array $headers = [])
{
    $opts = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_HTTPHEADER => $headers];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    $ch = ba_curl($url, $opts);
    $resp = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($resp === false) {
        throw new RuntimeException('سرویس پیامک در دسترس نیست: ' . $err);
    }
    return [$code, (string)$resp];
}

/** Sends one message through the configured provider. Returns ['id' =>, 'cost' =>] or throws. */
function acc_sms_send_one(array $cfg, $mobile, $text, $pattern = null, array $values = [])
{
    $p = $cfg['sms_provider'];
    $key = trim((string)$cfg['sms_api_key']);
    switch ($p) {
        case 'test':
            return ['id' => 'test-' . bin2hex(random_bytes(3)), 'cost' => 0];
        case 'kavenegar':
            if ($key === '') {
                throw new RuntimeException('کلید API کاوه‌نگار تنظیم نشده');
            }
            $base = 'https://api.kavenegar.com/v1/' . rawurlencode($key);
            if ($pattern) {
                $q = ['receptor' => $mobile, 'template' => $pattern['code']];
                foreach (array_values(json_decode($pattern['params'], true) ?: []) as $i => $name) {
                    // Kavenegar: token, token2, token3, token10, token20 (no spaces in token1-3)
                    $slot = ['token', 'token2', 'token3', 'token10', 'token20'][$i] ?? null;
                    if ($slot) {
                        $v = (string)($values[$name] ?? '');
                        $q[$slot] = $i < 3 ? str_replace(' ', "\u{200C}", $v) : $v;
                    }
                }
                [$code, $resp] = acc_sms_http('POST', "$base/verify/lookup.json", http_build_query($q));
            } else {
                $q = ['receptor' => $mobile, 'message' => $text] + ($cfg['sms_sender'] !== '' ? ['sender' => $cfg['sms_sender']] : []);
                [$code, $resp] = acc_sms_http('POST', "$base/sms/send.json", http_build_query($q));
            }
            $j = json_decode($resp, true);
            if (($j['return']['status'] ?? 0) != 200) {
                throw new RuntimeException('کاوه‌نگار: ' . ($j['return']['message'] ?? "HTTP $code"));
            }
            return ['id' => (string)($j['entries'][0]['messageid'] ?? ''), 'cost' => (float)($j['entries'][0]['cost'] ?? 0)];
        case 'smsir':
            if ($key === '') {
                throw new RuntimeException('کلید API اس‌ام‌اس‌دات‌آی‌آر تنظیم نشده');
            }
            $h = ['Content-Type: application/json', 'Accept: application/json', 'x-api-key: ' . $key];
            if ($pattern) {
                $params = [];
                foreach (json_decode($pattern['params'], true) ?: [] as $name) {
                    $params[] = ['name' => $name, 'value' => (string)($values[$name] ?? '')];
                }
                [$code, $resp] = acc_sms_http('POST', 'https://api.sms.ir/v1/send/verify', json_encode(['mobile' => $mobile,
                    'templateId' => (int)$pattern['code'], 'parameters' => $params], JSON_UNESCAPED_UNICODE), $h);
            } else {
                [$code, $resp] = acc_sms_http('POST', 'https://api.sms.ir/v1/send/bulk', json_encode(['lineNumber' => (int)$cfg['sms_sender'] ?: null,
                    'messageText' => $text, 'mobiles' => [$mobile]], JSON_UNESCAPED_UNICODE), $h);
            }
            $j = json_decode($resp, true);
            if (($j['status'] ?? 0) != 1) {
                throw new RuntimeException('sms.ir: ' . ($j['message'] ?? "HTTP $code"));
            }
            return ['id' => (string)($j['data']['messageId'] ?? ($j['data']['packId'] ?? '')), 'cost' => (float)($j['data']['cost'] ?? 0)];
        case 'custom':
            $url = trim((string)$cfg['sms_custom_url']);
            if (!preg_match('~^https?://~', $url)) {
                throw new RuntimeException('آدرس وب‌سرویس پیامک تنظیم نشده');
            }
            if ($pattern) {
                $text = $pattern['title'] . ': ' . implode(' ', array_map('strval', $values));
            }
            $fill = fn($s) => strtr($s, ['{to}' => rawurlencode($mobile), '{text}' => rawurlencode($text), '{from}' => rawurlencode($cfg['sms_sender'])]);
            if (strtoupper($cfg['sms_custom_method']) === 'POST') {
                [$base, $query] = array_pad(explode('?', $url, 2), 2, '');
                [$code, $resp] = acc_sms_http('POST', $fill($base), $fill($query), ['Content-Type: application/x-www-form-urlencoded']);
            } else {
                [$code, $resp] = acc_sms_http('GET', $fill($url));
            }
            if ($code < 200 || $code >= 300) {
                throw new RuntimeException("وب‌سرویس پیامک: HTTP $code " . mb_substr(strip_tags($resp), 0, 100));
            }
            return ['id' => mb_substr(trim(strip_tags($resp)), 0, 60), 'cost' => 0];
    }
    throw new RuntimeException('سرویس‌دهنده‌ی پیامک ناشناخته است');
}

/** Remaining credit from the provider (rial), or null when it can't say. */
function acc_sms_credit()
{
    $cfg = acc_sms_settings();
    $key = trim((string)$cfg['sms_api_key']);
    if ($cfg['sms_provider'] === 'kavenegar' && $key !== '') {
        [, $resp] = acc_sms_http('GET', 'https://api.kavenegar.com/v1/' . rawurlencode($key) . '/account/info.json');
        $j = json_decode($resp, true);
        if (($j['return']['status'] ?? 0) == 200) {
            return ['credit' => (float)$j['entries']['remaincredit'], 'unit' => 'ریال'];
        }
        throw new RuntimeException('کاوه‌نگار: ' . ($j['return']['message'] ?? 'خطا'));
    }
    if ($cfg['sms_provider'] === 'smsir' && $key !== '') {
        [, $resp] = acc_sms_http('GET', 'https://api.sms.ir/v1/credit', null, ['Accept: application/json', 'x-api-key: ' . $key]);
        $j = json_decode($resp, true);
        if (($j['status'] ?? 0) == 1) {
            return ['credit' => (float)$j['data'], 'unit' => 'پیامک'];
        }
        throw new RuntimeException('sms.ir: ' . ($j['message'] ?? 'خطا'));
    }
    return ['credit' => null, 'unit' => ''];
}

/* ------------------------------------------------------------------ */
/* automatic messages                                                   */
/* ------------------------------------------------------------------ */

/** After a final sale invoice, if enabled in the settings. */
function acc_sms_after_invoice(array $inv)
{
    $cfg = acc_sms_settings();
    if (empty($cfg['sms_auto_invoice']) || $inv['kind'] !== 'sale') {
        return;
    }
    acc_sms_schema();
    $t = acc_row('SELECT * FROM acc_sms_templates WHERE id = ?', [(int)$cfg['sms_invoice_template']]);
    $p = acc_row('SELECT * FROM acc_persons WHERE id = ?', [$inv['person_id']]);
    if (!$t || !$p || !acc_sms_mobile($p['mobile'])) {
        return;
    }
    acc_sms_queue([['mobile' => $p['mobile'], 'person_id' => $p['id'],
        'text' => acc_sms_render($t['body'], $p, ['number' => $inv['number'], 'total' => (float)$inv['total']])]], 'system', 'invoice');
}

/** Daily: reminders for received cheques due in N days (each cheque once). */
function acc_sms_cheque_reminders()
{
    $cfg = acc_sms_settings();
    $days = (int)$cfg['sms_cheque_days'];
    $t = $days > 0 ? acc_row('SELECT * FROM acc_sms_templates WHERE id = ?', [(int)$cfg['sms_cheque_template']]) : null;
    if (!$t) {
        return 0;
    }
    [$y, $m, $d] = ba_g2j(...array_map('intval', explode('-', date('Y-m-d', strtotime("+$days days")))));
    $due = sprintf('%04d/%02d/%02d', $y, $m, $d);
    $sent = (array)ba_kv_get('acc_sms_cheque_reminded', []);
    $msgs = [];
    foreach (acc_all("SELECT c.*, p.name, p.code, p.mobile, p.balance FROM acc_cheques c JOIN acc_persons p ON p.id = c.person_id
        WHERE c.status IN ('in_hand', 'deposited', 'issued') AND c.due_date = ?", [$due]) as $c) {
        if (in_array((int)$c['id'], $sent, true) || !acc_sms_mobile($c['mobile'])) {
            continue;
        }
        $sent[] = (int)$c['id'];
        $msgs[] = ['mobile' => $c['mobile'], 'person_id' => $c['person_id'], 'text' => acc_sms_render($t['body'], $c,
            ['cheque' => $c['number'], 'amount' => (float)$c['amount'], 'due_date' => $c['due_date']])];
    }
    ba_kv_set('acc_sms_cheque_reminded', array_slice($sent, -2000));
    return $msgs ? acc_sms_queue($msgs, 'system', 'cheque')['queued'] : 0;
}
