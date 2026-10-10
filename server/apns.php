<?php
/**
 * Push notifications to the iPhone app (ios/) through Apple's APNs.
 * Token-based auth: a .p8 key from developer.apple.com (Keys → Apple Push
 * Notifications service), signed per Apple's spec as an ES256 JWT, sent over HTTP/2.
 *
 * config.php:
 *   'apns_key_id'   => 'ABC123DEFG',            // Key ID of the .p8 key
 *   'apns_team_id'  => 'TEAMID1234',            // Apple Developer Team ID
 *   'apns_key_file' => '/etc/bank/AuthKey.p8',  // the .p8 file (outside the web folder)
 *   'apns_topic'    => 'ir.example.bankassistant',  // the app's bundle id
 * The app tells the server its device token and whether it is a development
 * build (sandbox) or a TestFlight / App Store build (production).
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/assistant.php';
require_once __DIR__ . '/webpush.php';

function apns_config()
{
    $c = ba_config();
    $file = (string)($c['apns_key_file'] ?? '');
    return [
        'key_id' => trim((string)($c['apns_key_id'] ?? '')),
        'team_id' => trim((string)($c['apns_team_id'] ?? '')),
        'key' => $file !== '' && is_readable($file) ? (string)file_get_contents($file) : (string)($c['apns_key_p8'] ?? ''),
        'topic' => trim((string)($c['apns_topic'] ?? '')),
        'host' => rtrim((string)($c['apns_host'] ?? ''), '/'),   // tests only
    ];
}

function apns_enabled()
{
    $c = apns_config();
    return $c['key_id'] !== '' && $c['team_id'] !== '' && $c['topic'] !== '' && strpos($c['key'], 'PRIVATE KEY') !== false
        && function_exists('curl_init') && function_exists('openssl_sign');
}

/** The provider token; Apple wants it renewed at most every hour and not more than every 20 minutes. */
function apns_jwt()
{
    $c = apns_config();
    $cached = ba_kv_get('apns_jwt');
    if (is_array($cached) && $cached['kid'] === $c['key_id'] && $cached['iat'] > time() - 45 * 60) {
        return $cached['jwt'];
    }
    $iat = time();
    $head = wp_b64u(json_encode(['alg' => 'ES256', 'kid' => $c['key_id']]));
    $claims = wp_b64u(json_encode(['iss' => $c['team_id'], 'iat' => $iat]));
    $key = openssl_pkey_get_private($c['key']);
    if (!$key || !openssl_sign("$head.$claims", $der, $key, OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('کلید APNs (.p8) خوانده نشد');
    }
    $jwt = "$head.$claims." . wp_b64u(wp_der_to_raw($der));
    ba_kv_set('apns_jwt', ['jwt' => $jwt, 'iat' => $iat, 'kid' => $c['key_id']]);
    return $jwt;
}

/** One notification to one device. Returns [http status, reason]. */
function apns_send($deviceToken, $env, array $payload, $collapse = '')
{
    $c = apns_config();
    $host = $c['host'] ?: ($env === 'production' ? 'https://api.push.apple.com' : 'https://api.sandbox.push.apple.com');
    $headers = [
        'authorization: bearer ' . apns_jwt(),
        'apns-topic: ' . $c['topic'],
        'apns-push-type: alert',
        'apns-priority: 10',
        'content-type: application/json',
    ];
    if ($collapse !== '') {
        $headers[] = 'apns-collapse-id: ' . substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $collapse), 0, 64);
    }
    $ch = ba_curl($host . '/3/device/' . $deviceToken, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTP_VERSION => defined('CURL_HTTP_VERSION_2TLS') ? CURL_HTTP_VERSION_2TLS : CURL_HTTP_VERSION_2_0,
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    $reason = $err ?: (string)(json_decode((string)$body, true)['reason'] ?? '');
    return [$code, $reason];
}

/** The same message the web app gets (wp_notify_all), to every iPhone app that asked for it. */
function apns_notify_all(array $message)
{
    if (!apns_enabled()) {
        return 0;
    }
    assistant_schema();
    $db = ba_db();
    $rows = $db->query("SELECT id, apns_token, apns_env FROM assistant_devices WHERE revoked = 0 AND apns_token IS NOT NULL AND apns_token != ''")->fetchAll();
    if (!$rows) {
        return 0;
    }
    $payload = ['aps' => [
        'alert' => ['title' => (string)($message['title'] ?? 'دستیار حسابداری'), 'body' => (string)($message['body'] ?? '')],
        'sound' => 'default',
        'thread-id' => (string)($message['tag'] ?? 'bank'),
    ]];
    if (isset($message['badge'])) {
        $payload['aps']['badge'] = (int)$message['badge'];
    }
    // a bank transaction: answer «بابت چی بود؟» right from the notification
    if (preg_match('~#/(?:orb|ask)/(\d+)~', (string)($message['url'] ?? ''), $m)) {
        $payload['aps']['category'] = 'BANK_TX';
        $payload['aps']['alert']['body'] = 'بابت چی بود؟ نگه دار و بنویس، یا بزن تا با صدا بگویی.';
        $payload['tx_id'] = (int)$m[1];
    }
    // a one-time code: the app opens that card's «رمز پویا»
    if (preg_match('~#/card/(\d+)/otp~', (string)($message['url'] ?? ''), $m)) {
        $payload['card_otp'] = (int)$m[1];
    }
    $sent = 0;
    foreach ($rows as $r) {
        try {
            [$code, $reason] = apns_send($r['apns_token'], $r['apns_env'], $payload, (string)($message['tag'] ?? ''));
        } catch (Throwable $e) {
            error_log('apns: ' . $e->getMessage());
            return $sent;
        }
        if ($code === 200) {
            $sent++;
        } elseif ($code === 410 || in_array($reason, ['BadDeviceToken', 'Unregistered', 'DeviceTokenNotForTopic'], true)) {
            $db->prepare('UPDATE assistant_devices SET apns_token = NULL WHERE id = ?')->execute([$r['id']]);
        } else {
            error_log("apns: $code $reason");
        }
    }
    return $sent;
}

/** The iPhone app's APNs device token (hex) for this paired device. */
function apns_register(array $device, $token, $env)
{
    $token = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$token));
    if (strlen($token) < 32 || strlen($token) > 200) {
        throw new InvalidArgumentException('توکن نوتیف نامعتبر است');
    }
    $env = $env === 'production' ? 'production' : 'sandbox';
    assistant_schema();
    ba_db()->prepare('UPDATE assistant_devices SET apns_token = ?, apns_env = ? WHERE id = ?')->execute([$token, $env, (int)$device['id']]);
    return ['push' => apns_enabled()];
}
