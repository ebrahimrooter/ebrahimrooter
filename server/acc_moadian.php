<?php
/**
 * سامانه مؤدیان (Iranian tax authority, "request manager" API v2).
 *
 * Each invoice is signed by the taxpayer (JWS RS256 with the certificate in
 * x5c, sigT critical header), then encrypted for the tax authority (JWE
 * RSA-OAEP-256 / A256GCM with the server key from /server-information) and
 * sent to /invoice with a bearer token, which is a JWS over the nonce from
 * /nonce. The reference number of every packet is kept; /inquiry-by-reference-id
 * tells later whether it was accepted (SUCCESS) or rejected with the errors.
 *
 * Needed per company (حسابداری ← تنظیمات ← سامانه مؤدیان):
 *   tax_memory       شناسه یکتای حافظه مالیاتی (6 chars, e.g. A1B2C3)
 *   tax_private_key  کلید خصوصی RSA (PEM) whose public key was registered
 *   tax_certificate  گواهی امضای الکترونیکی (PEM) of that key
 *   tax_env          'main' (tp.tax.gov.ir) or 'sandbox'
 */

require_once __DIR__ . '/acc_core.php';

const ACC_MOADIAN_URLS = [
    'main' => 'https://tp.tax.gov.ir/requestsmanager/api/v2',
    'sandbox' => 'https://sandboxrc.tax.gov.ir/requestsmanager/api/v2',
];

function acc_b64u($bin)
{
    return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function acc_b64u_dec($s)
{
    return base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4));
}

function acc_uuid()
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
}

/** Certificate PEM -> base64 DER for x5c. */
function acc_pem_body($pem)
{
    return preg_replace('/-----[^-]+-----|\s+/', '', (string)$pem);
}

function acc_moadian_config(array $c = null)
{
    $c = $c ?? acc_company();
    $missing = [];
    if (!preg_match('/^[A-Za-z0-9]{6}$/', (string)$c['tax_memory'])) {
        $missing[] = 'شناسه یکتای حافظه مالیاتی (۶ حرف)';
    }
    $key = trim((string)($c['tax_private_key'] ?? ''));
    if ($key === '' || !openssl_pkey_get_private($key)) {
        $missing[] = 'کلید خصوصی معتبر';
    }
    $cert = trim((string)($c['tax_certificate'] ?? ''));
    if ($cert === '' || !openssl_x509_read($cert)) {
        $missing[] = 'گواهی امضا (certificate)';
    }
    if ($missing) {
        throw new AccError('برای ارسال به سامانه مؤدیان این‌ها را در تنظیمات وارد کن: ' . implode('، ', $missing));
    }
    return ['memory' => strtoupper($c['tax_memory']), 'key' => $key, 'cert' => $cert,
        'url' => (ba_config()['moadian_url'] ?? '') ?: (ACC_MOADIAN_URLS[$c['tax_env'] ?? 'main'] ?? ACC_MOADIAN_URLS['main'])];
}

/* ------------------------------------------------------------------ */
/* JOSE                                                                 */
/* ------------------------------------------------------------------ */

function acc_jws($payload, array $cfg)
{
    $header = ['alg' => 'RS256', 'x5c' => [acc_pem_body($cfg['cert'])], 'sigT' => gmdate('Y-m-d\TH:i:s\Z'),
        'typ' => 'jose', 'crit' => ['sigT'], 'cty' => 'text/plain'];
    $input = acc_b64u(json_encode($header, JSON_UNESCAPED_SLASHES)) . '.' . acc_b64u($payload);
    if (!openssl_sign($input, $sig, $cfg['key'], OPENSSL_ALGO_SHA256)) {
        throw new AccError('امضای صورتحساب با کلید خصوصی ممکن نشد');
    }
    return $input . '.' . acc_b64u($sig);
}

function acc_mgf1($seed, $len)
{
    $out = '';
    for ($i = 0; strlen($out) < $len; $i++) {
        $out .= hash('sha256', $seed . pack('N', $i), true);
    }
    return substr($out, 0, $len);
}

/** RSAES-OAEP with SHA-256 and MGF1-SHA-256 (PHP's openssl only does SHA-1 OAEP). */
function acc_rsa_oaep256($msg, $pub_pem)
{
    $pub = openssl_pkey_get_public($pub_pem);
    if (!$pub) {
        throw new AccError('کلید عمومی سامانه مؤدیان نامعتبر است');
    }
    $k = intdiv(openssl_pkey_get_details($pub)['bits'] + 7, 8);
    $h = 32;
    if (strlen($msg) > $k - 2 * $h - 2) {
        throw new AccError('پیام برای RSA بلند است');
    }
    $db = hash('sha256', '', true) . str_repeat("\0", $k - strlen($msg) - 2 * $h - 2) . "\x01" . $msg;
    $seed = random_bytes($h);
    $mdb = $db ^ acc_mgf1($seed, $k - $h - 1);
    $mseed = $seed ^ acc_mgf1($mdb, $h);
    if (!openssl_public_encrypt("\0" . $mseed . $mdb, $out, $pub, OPENSSL_NO_PADDING)) {
        throw new AccError('رمزنگاری کلید نشد');
    }
    return $out;
}

function acc_jwe($plain, $pub_pem, $kid)
{
    $header = acc_b64u(json_encode(['alg' => 'RSA-OAEP-256', 'enc' => 'A256GCM', 'kid' => $kid]));
    $cek = random_bytes(32);
    $iv = random_bytes(12);
    $ct = openssl_encrypt($plain, 'aes-256-gcm', $cek, OPENSSL_RAW_DATA, $iv, $tag, $header, 16);
    return $header . '.' . acc_b64u(acc_rsa_oaep256($cek, $pub_pem)) . '.' . acc_b64u($iv) . '.' . acc_b64u($ct) . '.' . acc_b64u($tag);
}

/* ------------------------------------------------------------------ */
/* HTTP                                                                 */
/* ------------------------------------------------------------------ */

function acc_moadian_http($method, $url, $body = null, $token = null)
{
    $h = ['Accept: application/json', 'Content-Type: application/json', 'requestTraceId: ' . acc_uuid(), 'timestamp: ' . (int)(microtime(true) * 1000)];
    if ($token) {
        $h[] = 'Authorization: Bearer ' . $token;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 40, CURLOPT_CONNECTTIMEOUT => 15]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
    $out = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($out === false) {
        throw new AccError('اتصال به سامانه مؤدیان برقرار نشد: ' . $err, 502);
    }
    $j = json_decode($out, true);
    if ($code < 200 || $code >= 300) {
        $msg = is_array($j) ? ($j['message'] ?? ($j['errors'][0]['message'] ?? ($j['error'] ?? json_encode($j, JSON_UNESCAPED_UNICODE)))) : substr((string)$out, 0, 300);
        throw new AccError('سامانه مؤدیان (HTTP ' . $code . '): ' . (is_string($msg) ? $msg : json_encode($msg, JSON_UNESCAPED_UNICODE)), 502);
    }
    return $j;
}

function acc_moadian_token(array $cfg)
{
    $n = acc_moadian_http('GET', $cfg['url'] . '/nonce?timeToLive=20');
    if (empty($n['nonce'])) {
        throw new AccError('سامانه مؤدیان nonce نداد', 502);
    }
    return acc_jws(json_encode(['nonce' => $n['nonce'], 'clientId' => $cfg['memory']]), $cfg);
}

/** Encryption key of the tax authority: [pem, id]. */
function acc_moadian_server_key(array $cfg, $token)
{
    $info = acc_moadian_http('GET', $cfg['url'] . '/server-information', null, $token);
    $keys = $info['publicKeys'] ?? ($info['result']['data']['publicKeys'] ?? []);
    foreach ($keys as $k) {
        if (!empty($k['key'])) {
            return ["-----BEGIN PUBLIC KEY-----\n" . chunk_split(acc_pem_body($k['key']), 64, "\n") . "-----END PUBLIC KEY-----\n", (string)$k['id']];
        }
    }
    throw new AccError('کلید عمومی سامانه مؤدیان دریافت نشد', 502);
}

/* ------------------------------------------------------------------ */
/* invoice                                                              */
/* ------------------------------------------------------------------ */

/** Unix ms of a Jalali date at noon Tehran time. */
function acc_jalali_ms($date)
{
    [$y, $m, $d] = acc_jalali_parse($date) ?: ba_today_jalali();
    [$gy, $gm, $gd] = ba_j2g($y, $m, $d);
    $t = new DateTime(sprintf('%04d-%02d-%02d 12:00:00', $gy, $gm, $gd), new DateTimeZone('Asia/Tehran'));
    return $t->getTimestamp() * 1000;
}

function acc_verhoeff($num)
{
    static $d = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],
        [5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]];
    static $p = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,7,8,6,0],
        [4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]];
    static $inv = [0,4,3,2,1,5,6,7,8,9];
    $c = 0;
    $digits = array_reverse(str_split($num));
    foreach ($digits as $i => $n) {
        $c = $d[$c][$p[($i + 1) % 8][(int)$n]];
    }
    return $inv[$c];
}

/** شماره مالیاتی ۲۲ رقمی: memory id + days since epoch (hex) + serial (hex) + Verhoeff check. */
function acc_moadian_taxid($memory, $ms, $serial)
{
    $days = intdiv((int)$ms, 86400000);
    $dec = '';
    foreach (str_split(strtoupper($memory)) as $ch) {
        $dec .= ctype_digit($ch) ? $ch : (string)ord($ch);
    }
    $dec .= str_pad((string)$days, 6, '0', STR_PAD_LEFT) . str_pad((string)$serial, 12, '0', STR_PAD_LEFT);
    return strtoupper($memory . str_pad(dechex($days), 5, '0', STR_PAD_LEFT) . str_pad(dechex($serial), 10, '0', STR_PAD_LEFT) . acc_verhoeff($dec));
}

/**
 * Invoice JSON as the tax authority expects. $ins: 1 original, 3 cancel.
 * Type 1 (with buyer) when the buyer has a national id / economic code, else type 2.
 */
function acc_moadian_invoice(array $t, array $cfg, $ins = 1, $serial = null, $ref_taxid = null)
{
    $inv = acc_row('SELECT * FROM acc_invoices WHERE id = ?', [$t['invoice_id']]);
    if (!$inv) {
        throw new AccError('فاکتور این صورتحساب یافت نشد');
    }
    $person = acc_row('SELECT * FROM acc_persons WHERE id = ?', [$inv['person_id']]) ?: [];
    $c = acc_company();
    $seller = preg_replace('/\D/', '', (string)($c['economic_code'] ?: $c['national_id']));
    if ($seller === '') {
        throw new AccError('کد اقتصادی / شناسه ملی فروشنده (شرکت) در تنظیمات خالی است');
    }
    $ms = acc_jalali_ms($inv['date']);
    $serial = $serial ?? (int)$inv['id'];
    $buyer = preg_replace('/\D/', '', (string)($person['national_id'] ?? ''));
    $type = $buyer !== '' ? 1 : 2;
    $ratio = (float)$inv['subtotal'] > 0 ? (float)$inv['discount'] / (float)$inv['subtotal'] : 0;
    $vat = (float)$c['vat_rate'];
    $body = [];
    foreach (acc_all('SELECT it.*, p.name, p.code, p.tax_code FROM acc_invoice_items it LEFT JOIN acc_products p ON p.id = it.product_id WHERE invoice_id = ?', [$inv['id']]) as $it) {
        $sstid = preg_replace('/\D/', '', (string)$it['tax_code']);
        if (strlen($sstid) !== 13) {
            throw new AccError('شناسه ۱۳ رقمی کالا/خدمت (سامانه مؤدیان) برای «' . $it['name'] . '» وارد نشده');
        }
        $prdis = round((float)$it['qty'] * (float)$it['price']);
        $dis = round($prdis * $ratio);
        $adis = $prdis - $dis;
        $vam = round($adis * $vat / 100);
        $body[] = ['sstid' => $sstid, 'sstt' => mb_substr($it['name'], 0, 400), 'am' => (float)$it['qty'], 'mu' => '1627', 'fee' => (float)$it['price'],
            'prdis' => $prdis, 'dis' => $dis, 'adis' => $adis, 'vra' => $vat, 'vam' => $vam, 'tsstam' => $adis + $vam];
    }
    $sum = fn($k) => array_sum(array_column($body, $k));
    $header = ['taxid' => acc_moadian_taxid($cfg['memory'], $ms, $serial), 'inno' => str_pad(dechex($serial), 10, '0', STR_PAD_LEFT),
        'indatim' => $ms, 'indati2m' => $ms, 'inty' => $type, 'inp' => 1, 'ins' => $ins, 'tins' => $seller,
        'tprdis' => $sum('prdis'), 'tdis' => $sum('dis'), 'tadis' => $sum('adis'), 'tvam' => $sum('vam'), 'todam' => 0, 'tbill' => $sum('tsstam'),
        'setm' => $inv['settled'] ? 1 : 2, 'cap' => $inv['settled'] ? $sum('tsstam') : 0, 'insp' => $inv['settled'] ? 0 : $sum('tsstam')];
    if ($type === 1) {
        $header += ['tob' => ($person['legal_type'] ?? '') === 'legal' ? 2 : 1, 'bid' => $buyer, 'tinb' => strlen($buyer) > 11 ? $buyer : ''];
    }
    if ($ref_taxid) {
        $header['irtaxid'] = $ref_taxid;
    }
    return ['header' => $header, 'body' => $body, 'payments' => []];
}

/** Signs, encrypts and sends invoices; returns [uid => reference number]. */
function acc_moadian_send(array $invoices, array $cfg)
{
    $token = acc_moadian_token($cfg);
    [$pub, $kid] = acc_moadian_server_key($cfg, $token);
    $packets = [];
    foreach ($invoices as $uid => $invoice) {
        $packets[] = ['payload' => acc_jwe(acc_jws(json_encode($invoice, JSON_UNESCAPED_UNICODE), $cfg), $pub, $kid),
            'header' => ['requestTraceId' => $uid, 'fiscalId' => $cfg['memory']]];
    }
    $r = acc_moadian_http('POST', $cfg['url'] . '/invoice', $packets, $token);
    $out = [];
    foreach ($r['result'] ?? [] as $x) {
        $out[$x['uid'] ?? ''] = $x['referenceNumber'] ?? null;
    }
    return ['refs' => $out, 'raw' => $r];
}

function acc_moadian_inquiry(array $refs, array $cfg)
{
    $token = acc_moadian_token($cfg);
    $q = implode('&', array_map(fn($r) => 'referenceIds=' . rawurlencode($r), $refs));
    return acc_moadian_http('GET', $cfg['url'] . '/inquiry-by-reference-id?' . $q, null, $token);
}

/* ------------------------------------------------------------------ */
/* API handlers                                                         */
/* ------------------------------------------------------------------ */

function acc_moadian_dispatch($id, $ins = 1)
{
    $t = acc_tax_get($id);
    if ($t['kind'] !== 'sale') {
        throw new AccError('فقط صورتحساب فروش را فروشنده به سامانه می‌فرستد');
    }
    $cfg = acc_moadian_config();
    $serial = $ins === 1 ? (int)$t['invoice_id'] : 900000000 + (int)acc_next('tax_cancel');
    $invoice = acc_moadian_invoice($t, $cfg, $ins, $serial, $ins === 1 ? null : $t['taxid']);
    $uid = acc_uuid();
    try {
        $r = acc_moadian_send([$uid => $invoice], $cfg);
    } catch (AccError $e) {
        acc_update('acc_tax_invoices', $id, ['status' => 'failed', 'response' => json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE)]);
        throw $e;
    }
    $ref = $r['refs'][$uid] ?? (array_values($r['refs'])[0] ?? null);
    $set = ['status' => $ins === 1 ? 'sent' : 'cancel_sent', 'reference' => (string)$ref,
        'payload' => json_encode($invoice, JSON_UNESCAPED_UNICODE), 'response' => json_encode($r['raw'], JSON_UNESCAPED_UNICODE)];
    if ($ins === 1) {
        $set['taxid'] = $invoice['header']['taxid'];
    }
    acc_update('acc_tax_invoices', $id, $set);
    return $ref;
}

function r_tax_send($u, $id)
{
    $ref = acc_moadian_dispatch($id, 1);
    acc_log($u['username'], 'tax_send', (string)$ref);
    acc_webhook('update', 'TaxInvoice', [$id], ['status' => 'sent', 'reference' => $ref]);
    return acc_tax_out(acc_tax_get($id));
}

function r_tax_cancel($u, $id)
{
    $t = acc_tax_get($id);
    if (!in_array($t['status'], ['sent', 'accepted'], true)) {
        throw new AccError('فقط صورتحسابی که به سامانه رفته باطل می‌شود');
    }
    $ref = acc_moadian_dispatch($id, 3);
    acc_log($u['username'], 'tax_cancel', (string)$ref);
    return acc_tax_out(acc_tax_get($id));
}

function r_tax_inquire($u, $id)
{
    $t = acc_tax_get($id);
    if (($t['reference'] ?? '') === '') {
        throw new AccError('این صورتحساب هنوز ارسال نشده');
    }
    $r = acc_moadian_inquiry([$t['reference']], acc_moadian_config());
    $row = is_array($r) && isset($r[0]) ? $r[0] : ($r['result'][0] ?? $r);
    $st = strtoupper((string)($row['status'] ?? ''));
    $map = ['SUCCESS' => $t['status'] === 'cancel_sent' ? 'cancelled' : 'accepted', 'FAILED' => 'rejected'];
    acc_update('acc_tax_invoices', $id, ['status' => $map[$st] ?? $t['status'], 'response' => json_encode($r, JSON_UNESCAPED_UNICODE)]);
    return acc_tax_out(acc_tax_get($id));
}

/** Tests the settings: nonce, token and the server key. */
function r_tax_test()
{
    $cfg = acc_moadian_config();
    $token = acc_moadian_token($cfg);
    [, $kid] = acc_moadian_server_key($cfg, $token);
    $info = null;
    try {
        $info = acc_moadian_http('GET', $cfg['url'] . '/fiscal-information?memoryId=' . rawurlencode($cfg['memory']), null, $token);
    } catch (AccError $e) {
        $info = ['error' => $e->getMessage()];
    }
    return ['ok' => true, 'server_key_id' => $kid, 'fiscal' => $info];
}

/** New RSA key pair; the public key is registered in the taxpayer portal. */
function r_tax_keygen($u)
{
    $k = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
    openssl_pkey_export($k, $priv);
    $pub = openssl_pkey_get_details($k)['key'];
    acc_update('acc_company', 1, ['tax_private_key' => $priv]);
    acc_log($u['username'], 'tax_keygen');
    return ['ok' => true, 'public_key' => $pub];
}

function r_tax_keys_save($u)
{
    $b = acc_body();
    $set = [];
    if (isset($b['private_key']) && trim($b['private_key']) !== '') {
        if (!openssl_pkey_get_private(trim($b['private_key']))) {
            throw new AccError('کلید خصوصی معتبر نیست (فرمت PEM)');
        }
        $set['tax_private_key'] = trim($b['private_key']);
    }
    if (isset($b['certificate']) && trim($b['certificate']) !== '') {
        $cert = trim($b['certificate']);
        if (strpos($cert, '-----BEGIN') === false) {
            $cert = "-----BEGIN CERTIFICATE-----\n" . chunk_split(acc_pem_body($cert), 64, "\n") . "-----END CERTIFICATE-----";
        }
        if (!openssl_x509_read($cert)) {
            throw new AccError('گواهی معتبر نیست (فایل .crt یا .cer)');
        }
        $set['tax_certificate'] = $cert;
    }
    if (isset($b['env'])) {
        $set['tax_env'] = $b['env'] === 'sandbox' ? 'sandbox' : 'main';
    }
    if ($set) {
        acc_update('acc_company', 1, $set);
    }
    acc_log($u['username'], 'tax_keys');
    return r_tax_keys();
}

function r_tax_keys()
{
    $c = acc_company();
    $key = trim((string)($c['tax_private_key'] ?? ''));
    $pub = '';
    if ($key !== '' && ($k = openssl_pkey_get_private($key))) {
        $pub = openssl_pkey_get_details($k)['key'];
    }
    $cert = trim((string)($c['tax_certificate'] ?? ''));
    $ci = $cert !== '' ? openssl_x509_parse($cert) : null;
    $match = $pub !== '' && $cert !== '' && openssl_x509_check_private_key($cert, $key);
    return ['has_key' => $pub !== '', 'public_key' => $pub, 'has_certificate' => (bool)$ci,
        'certificate_subject' => $ci['name'] ?? null, 'certificate_expires' => isset($ci['validTo_time_t']) ? date('Y-m-d', $ci['validTo_time_t']) : null,
        'key_matches_certificate' => $match, 'env' => $c['tax_env'] ?? 'main', 'memory' => $c['tax_memory']];
}
