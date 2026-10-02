<?php
/**
 * Web Push (notifications on the phone even when the app is closed) in
 * plain PHP + OpenSSL - no Composer needed on a cheap host.
 *
 *   RFC 8292  VAPID: the server proves who it is with an ES256-signed JWT
 *   RFC 8291  message encryption for the browser (ECDH + HKDF)
 *   RFC 8188  "aes128gcm" content encoding
 *
 * iPhone: works from iOS 16.4 for apps added to the Home Screen; the
 * endpoint is then on web.push.apple.com.
 */

require_once __DIR__ . '/lib.php';

function wp_b64u($bin) {
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function wp_b64u_decode($s) {
    $s = strtr((string)$s, '-_', '+/');
    return base64_decode($s . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

/** Uncompressed P-256 point (65 bytes) -> PEM public key OpenSSL can use. */
function wp_point_to_pem($point) {
    $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
    return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
}

function wp_new_ec_key() {
    $k = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
    if (!$k) {
        throw new RuntimeException('OpenSSL روی این هاست کلید EC نمی‌سازد.');
    }
    $d = openssl_pkey_get_details($k);
    $pad = fn($v) => str_pad($v, 32, "\0", STR_PAD_LEFT);
    return ['key' => $k, 'public' => "\x04" . $pad($d['ec']['x']) . $pad($d['ec']['y'])];
}

/** The server's VAPID key pair, created once and kept in the database. */
function wp_vapid() {
    $v = ba_kv_get('vapid');
    if (!$v) {
        $k = wp_new_ec_key();
        openssl_pkey_export($k['key'], $pem);
        $v = ['private_pem' => $pem, 'public' => wp_b64u($k['public'])];
        ba_kv_set('vapid', $v);
    }
    return $v;
}

/** DER ECDSA signature -> 64-byte r||s that JWT ES256 expects. */
function wp_der_to_raw($der) {
    $pos = 2 + (ord($der[1]) & 0x80 ? (ord($der[1]) & 0x7f) : 0);   // SEQUENCE header
    $out = '';
    for ($i = 0; $i < 2; $i++) {
        $len = ord($der[$pos + 1]);
        $int = substr($der, $pos + 2, $len);
        $out .= str_pad(ltrim($int, "\0"), 32, "\0", STR_PAD_LEFT);
        $pos += 2 + $len;
    }
    return $out;
}

function wp_vapid_header($endpoint) {
    $v = wp_vapid();
    $p = parse_url($endpoint);
    $aud = $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '');
    $cfg = ba_config();
    $app = parse_url($cfg['app_url'] ?? '');
    // Apple requires a real contact: an https URL of this site works.
    $sub = !empty($app['host']) ? 'https://' . $app['host'] : 'mailto:owner@example.com';
    $jwt = wp_b64u(json_encode(['typ' => 'JWT', 'alg' => 'ES256'])) . '.' .
        wp_b64u(json_encode(['aud' => $aud, 'exp' => time() + 12 * 3600, 'sub' => $sub], JSON_UNESCAPED_SLASHES));
    if (!openssl_sign($jwt, $der, $v['private_pem'], OPENSSL_ALGO_SHA256)) {
        throw new RuntimeException('امضای VAPID نشد');
    }
    return 'vapid t=' . $jwt . '.' . wp_b64u(wp_der_to_raw($der)) . ', k=' . $v['public'];
}

/** Encrypts a payload for one subscription (RFC 8291 / aes128gcm). */
function wp_encrypt($payload, $p256dh_b64u, $auth_b64u) {
    $ua_public = wp_b64u_decode($p256dh_b64u);
    $auth = wp_b64u_decode($auth_b64u);
    if (strlen($ua_public) !== 65 || strlen($auth) < 16) {
        throw new InvalidArgumentException('کلیدهای اشتراک نوتیف نامعتبر است');
    }
    $as = wp_new_ec_key();
    $secret = openssl_pkey_derive(openssl_pkey_get_public(wp_point_to_pem($ua_public)), $as['key'], 32);
    if ($secret === false) {
        throw new RuntimeException('ECDH نشد');
    }
    $hmac = fn($key, $data) => hash_hmac('sha256', $data, $key, true);
    $prk_key = $hmac($auth, $secret);
    $ikm = $hmac($prk_key, "WebPush: info\0" . $ua_public . $as['public'] . "\x01");
    $salt = random_bytes(16);
    $prk = $hmac($salt, $ikm);
    $cek = substr($hmac($prk, "Content-Encoding: aes128gcm\0\x01"), 0, 16);
    $nonce = substr($hmac($prk, "Content-Encoding: nonce\0\x01"), 0, 12);
    $tag = '';
    $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $salt . pack('N', 4096) . chr(65) . $as['public'] . $cipher . $tag;
}

/** Sends one push. Returns the HTTP status (201 = delivered to the push service). */
function wp_send(array $sub, array $message) {
    $body = wp_encrypt(json_encode($message, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $sub['p256dh'], $sub['auth']);
    $ch = ba_curl($sub['endpoint'], [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/octet-stream',
            'Content-Encoding: aes128gcm',
            'TTL: 86400',
            'Urgency: high',
            'Authorization: ' . wp_vapid_header($sub['endpoint']),
        ],
        CURLOPT_TIMEOUT => 15,
    ]);
    curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    return $code;
}

/**
 * Notifies every subscribed phone. $message: title, body, url (opened on
 * tap), tag (same tag replaces the older notification), badge (app icon
 * number). Expired subscriptions (404/410) are removed.
 */
function wp_notify_all(array $message) {
    if (!function_exists('openssl_pkey_derive') || !function_exists('curl_init')) {
        return 0;
    }
    $db = ba_db();
    $sent = 0;
    foreach ($db->query('SELECT * FROM push_subs')->fetchAll() as $sub) {
        try {
            $code = wp_send($sub, $message);
        } catch (Throwable $e) {
            error_log('push: ' . $e->getMessage());
            continue;
        }
        if ($code === 404 || $code === 410) {
            $db->prepare('DELETE FROM push_subs WHERE endpoint = ?')->execute([$sub['endpoint']]);
        } elseif ($code >= 200 && $code < 300) {
            $db->prepare('UPDATE push_subs SET last_ok = ? WHERE endpoint = ?')->execute([date('Y-m-d H:i:s'), $sub['endpoint']]);
            $sent++;
        } else {
            error_log("push: {$code} from " . parse_url($sub['endpoint'], PHP_URL_HOST));
        }
    }
    return $sent;
}

/** Notification for a new bank transaction: tapping it opens the orb. */
function wp_notify_transaction($tx) {
    $pending = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    return wp_notify_all([
        'title' => ($tx['direction'] === 'in' ? '🟢 واریز ' : '🔴 برداشت ') . ba_toman($tx['amount']),
        'body' => 'بابت چی بود؟ بزن تا بپرسم.',
        'url' => '#/orb/' . $tx['id'],
        'tag' => 'tx-' . $tx['id'],
        'badge' => $pending,
    ]);
}
