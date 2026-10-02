<?php
/**
 * Self-test:  php tests/run.php
 * Uses a throwaway database and config in a temp dir.
 */

$tmp = sys_get_temp_dir() . '/ba-test-' . getmypid();
@mkdir($tmp);
$work = $tmp . '/server';
@mkdir($work);
@mkdir($work . '/data');
copy(__DIR__ . '/../lib.php', $work . '/lib.php');
copy(__DIR__ . '/../bot.php', $work . '/bot.php');

// Local stand-in for the Bale bot API and the local voice service (voice/voice_service.py).
$port = 18000 + getmypid() % 1000;
$log = $tmp . '/bale.log';
file_put_contents($tmp . '/mock.php', '<?php
$uri = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
$body = file_get_contents("php://input");
$j = json_decode($body, true);
if ($j === null && $_POST) {   // multipart upload (sendVoice)
    $j = $_POST;
    foreach ($_FILES as $k => $f) { $j[$k] = ["type" => $f["type"], "name" => $f["name"], "data" => file_get_contents($f["tmp_name"])]; }
}
if ($uri === "/stt") { $j = ["bytes" => strlen($body), "head" => substr($body, 0, 4)]; }
file_put_contents(' . var_export($log, true) . ', json_encode(["uri" => $uri, "body" => $j], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
header("Content-Type: application/json");
if (strpos($uri, "/file/") !== false) { echo str_repeat("OggS", 1000); exit; }
if (in_array($uri, ["/health", "/stt", "/tts"], true) && ($_SERVER["HTTP_X_VOICE_TOKEN"] ?? "") !== "vt") { http_response_code(401); echo "{}"; exit; }
if ($uri === "/health") { echo json_encode(["ok" => true, "stt_ready" => true, "tts_ready" => true, "stt_model" => "large-v3-turbo", "tts_voice" => "fa_IR-gyro-medium"]); exit; }
if ($uri === "/stt") { echo json_encode(["ok" => true, "text" => "حقوق کارگر ها"], JSON_UNESCAPED_UNICODE); exit; }
if ($uri === "/tts") { header("Content-Type: " . ($j["format"] === "mp3" ? "audio/mpeg" : "audio/ogg")); echo "OggS" . $j["text"]; exit; }
if (substr($uri, -8) === "/getFile") { echo json_encode(["ok" => true, "result" => ["file_path" => "voice/1.ogg"]]); exit; }
static $n; $n = (int)@file_get_contents(__FILE__ . ".n") + 1; file_put_contents(__FILE__ . ".n", $n);
echo json_encode(["ok" => true, "result" => ["message_id" => 1000 + $n]]);
');
$mock = proc_open(['php', '-S', "127.0.0.1:$port", $tmp . '/mock.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
usleep(400000);

file_put_contents($work . '/config.php', "<?php return ['app_token'=>'a','device_token'=>'d','allowed_senders'=>[],'timezone'=>'Asia/Tehran','otp_pin'=>'1234',
    'bale_bot_token'=>'T','bale_chat_id'=>'42','bale_api_base'=>'http://127.0.0.1:$port','voice_url'=>'http://127.0.0.1:$port','voice_token'=>'vt'];");
require $work . '/lib.php';
require $work . '/bot.php';
ba_config();

/** Bale calls made since the last look. */
function bale_calls() {
    global $log;
    $lines = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES) : [];
    @unlink($log);
    return array_map(fn($l) => json_decode($l, true), $lines);
}
function last_text() {
    $texts = array_values(array_filter(array_map(fn($c) => $c['body']['text'] ?? null, bale_calls())));
    return $texts ? end($texts) : null;
}

$fails = 0;
function check($name, $got, $want) {
    global $fails;
    if ($got === $want) {
        echo "  ok   $name\n";
    } else {
        $fails++;
        echo "  FAIL $name\n       got:  " . var_export($got, true) . "\n       want: " . var_export($want, true) . "\n";
    }
}

echo "Jalali\n";
check('g2j 2026-09-29', ba_g2j(2026, 9, 29), [1405, 7, 7]);
check('g2j 2025-03-21 nowruz', ba_g2j(2025, 3, 21), [1404, 1, 1]);
check('j2g 1405/07/07', ba_j2g(1405, 7, 7), [2026, 9, 29]);
check('j2g 1403/12/30 leap', ba_j2g(1403, 12, 30), [2025, 3, 20]);
$okRound = true;
for ($t = strtotime('2020-01-01'); $t < strtotime('2030-01-01'); $t += 86400) {
    [$jy, $jm, $jd] = ba_g2j((int)date('Y', $t), (int)date('n', $t), (int)date('j', $t));
    if (ba_j2g($jy, $jm, $jd) !== [(int)date('Y', $t), (int)date('n', $t), (int)date('j', $t)]) { $okRound = false; break; }
}
check('round trip 2020..2030', $okRound, true);

echo "Parser\n";
$p = ba_parse_sms("بانک ملت\nبرداشت:1,250,000\nحساب:1234\nمانده:8,420,000\n1405/07/07-14:25");
check('mellat out', [$p['direction'], $p['amount'], $p['balance'], $p['account'], $p['bank_date'], $p['bank_time']],
    ['out', 1250000, 8420000, '1234', '1405/07/07', '14:25']);
$p = ba_parse_sms("ملت\nواریز:۵,۰۰۰,۰۰۰\nبه:12345678\nمانده:۱۳,۴۲۰,۰۰۰\n0707-09:10");
check('mellat in persian digits + MMDD', [$p['direction'], $p['amount'], $p['balance'], $p['bank_date'], $p['bank_time']],
    ['in', 5000000, 13420000, ba_today_jalali()[1] >= 7 ? sprintf('%04d/07/07', ba_today_jalali()[0]) : sprintf('%04d/07/07', ba_today_jalali()[0] - 1), '09:10']);
$p = ba_parse_sms("حساب 1234\nمبلغ:250,000-\nمانده:100,000\n05/07/03 10:00");
check('sign minus', [$p['direction'], $p['amount'], $p['bank_date']], ['out', 250000, '1405/07/03']);
$p = ba_parse_sms("مبلغ 300000+ به حساب 99 واریز شد");
check('sign plus', [$p['direction'], $p['amount']], ['in', 300000]);
// Real Bank Mellat format sent by the owner (account digits changed): no spaces or colons, yy/mm/dd date.
$p = ba_parse_sms("حساب1234567890\nواریز50,000\nمانده12,345,670\n05/07/10-00:40");
check('real Mellat SMS', [$p['direction'], $p['amount'], $p['balance'], $p['account'], $p['bank_date'], $p['bank_time']],
    ['in', 50000, 12345670, '1234567890', '1405/07/10', '00:40']);
check('OTP ignored', ba_parse_sms("رمز پویا: 123456 \n بانک ملت"), null);
check('ad ignored', ba_parse_sms("با همراه بانک ملت سریع‌تر پرداخت کنید"), null);

echo "Ingest\n";
$r1 = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:1,000,000\nحساب:1234\nمانده:9,000,000\n1405/07/01-10:00");
check('tx created', $r1['transaction']['status'], 'pending');
$r1b = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:1,000,000\nحساب:1234\nمانده:9,000,000\n1405/07/01-10:00");
check('duplicate dropped', $r1b['duplicate'], true);
// long SMS in two parts: first part has amount, second carries balance + date
$a = ba_ingest_sms('+98700717', "بانک ملت\nواریز:3,000,000\nحساب:1234");
$b = ba_ingest_sms('+98700717', "مانده:12,000,000\n1405/07/02-11:30");
check('multipart merged', [$b['transaction']['id'] ?? null, $b['transaction']['balance'] ?? null, $b['transaction']['bank_date'] ?? null],
    [$a['transaction']['id'], 12000000, '1405/07/02']);
// gap: 500k withdrawal SMS lost, then this one
ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:2,000,000\nحساب:1234\nمانده:9,500,000\n1405/07/03-09:00");

echo "Balance chain\n";
$rep = ba_balance_check('2026-09-20', '2026-09-30');
check('one gap found', count($rep['gaps']), 1);
check('gap = 500k missing withdrawal', $rep['gaps'][0]['missing'] ?? null, -500000);

echo "Statement\n";
$csv = "\xEF\xBB\xBFردیف,تاریخ,شرح,برداشت,واریز,مانده\n"
    . "1,1405/07/01,انتقال,\"1,000,000\",0,\"9,000,000\"\n"
    . "2,1405/07/02,واریز,0,\"3,000,000\",\"12,000,000\"\n"
    . "3,1405/07/02,کارمزد,\"500,000\",0,\"11,500,000\"\n"
    . "4,1405/07/03,انتقال,\"2,000,000\",0,\"9,500,000\"\n";
$lines = ba_parse_statement_csv($csv);
check('csv rows', count($lines), 4);
check('csv first', [$lines[0]['date'], $lines[0]['direction'], $lines[0]['amount']], ['2026-09-23', 'out', 1000000]);
$rec = ba_reconcile_statement($lines);
check('matched 3', $rec['matched'], 3);
check('only-in-bank = the 500k', array_map(fn($l) => $l['amount'], $rec['only_in_bank']), [500000]);
check('only-in-ledger none', count($rec['only_in_ledger']), 0);

echo "Interpret\n";
$cat = fn($name) => (int)ba_db()->query("SELECT id FROM categories WHERE name LIKE " . ba_db()->quote($name . '%'))->fetchColumn();
$g = ba_interpret('حواله به علی رضایی بابت خرید بذر', 'out');
check('party after حواله به', $g['party'], 'علی رضایی');
check('"بابت خرید" beats "حواله" -> purchase', $g['category_id'], $cat('خرید'));
$g = ba_interpret('حواله به علی رضایی تسویه طلبش', 'out');
check('settlement -> person account', $g['category_id'], $cat('پرداخت به اشخاص'));
$g = ba_interpret('کارت به کارت به حسن', 'out');
check('only "how" words -> person account', $g['category_id'], $cat('پرداخت به اشخاص'));
$g = ba_interpret('به آقای محمد احمدی حواله دادم', 'out');
check('title stripped', $g['party'], 'محمد احمدی');
$g = ba_interpret('واریز از طرف شرکت پارس بابت فروش', 'in');
check('receipt party', [$g['party'], $g['category_id']], ['شرکت پارس', $cat('فروش')]);
$g = ba_interpret('حقوق کارگر ها', 'out');
check('no party, category by keyword', [$g['party'], $g['category_id']], ['', $cat('حقوق')]);
ba_confirm_tx(1, 'بذر', 'حسن کریمی', $cat('خرید'));
$g = ba_interpret('پول حسن کریمی', 'out');
check('known party brings its category', [$g['party'], $g['category_id']], ['حسن کریمی', $cat('خرید')]);
check('yes words', [ba_is_yes('آره'), ba_is_yes('بله ثبت کن'), ba_is_yes('نه')], [true, true, false]);

echo "Bale bot\n";
ba_db()->exec("UPDATE transactions SET status = 'ignored' WHERE status = 'pending'");   // clean slate from earlier sections
bale_calls();
$t = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:4,000,000\nحساب:1234\nمانده:5,500,000\n1405/07/04-12:00")['transaction'];
bot_on_new_transaction($t);
$c = bale_calls();
check('question sent with buttons', [str_ends_with($c[0]['uri'], '/sendMessage'), isset($c[0]['body']['reply_markup']['inline_keyboard'])], [true, true]);
$qid = 1000 + (int)file_get_contents($tmp . '/mock.php.n');

bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'حواله به علی رضایی بابت خرید بذر']]);
check('draft shows party', (bool)preg_match('/علی رضایی/u', last_text()), true);
bot_handle_update(['callback_query' => ['id' => 'c1', 'data' => 'ok:' . $t['id'], 'message' => ['chat' => ['id' => 42], 'message_id' => 5]]]);
$after = ba_get_transaction($t['id']);
check('confirmed from Bale', [$after['status'], $after['party']], ['confirmed', 'علی رضایی']);
check('learned party', (int)ba_db()->query("SELECT COUNT(*) FROM parties WHERE name = 'علی رضایی'")->fetchColumn(), 1);

// Two new SMS; reply to the first question explicitly while the second is current.
$t1 = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:100,000\nحساب:1234\nمانده:5,400,000\n1405/07/05-08:00")['transaction'];
bot_on_new_transaction($t1);
bale_calls();
$q1 = 1000 + (int)file_get_contents($tmp . '/mock.php.n');
$t2 = ba_ingest_sms('+98700717', "بانک ملت\nواریز:900,000\nحساب:1234\nمانده:6,300,000\n1405/07/05-09:00")['transaction'];
bot_on_new_transaction($t2);
bale_calls();
$q2 = 1000 + (int)file_get_contents($tmp . '/mock.php.n');
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'قبض برق', 'reply_to_message' => ['message_id' => $q1]]]);
check('reply goes to the replied question', ba_kv_get('bot:draft:' . $t1['id'])['category_id'] ?? null, $cat('قبض'));
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'آره']]);
check('"آره" files the draft', ba_get_transaction($t1['id'])['status'], 'confirmed');

// voice answer for t2 -> getFile, download, STT mock says "حقوق کارگر ها"
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'voice' => ['file_id' => 'F1'], 'reply_to_message' => ['message_id' => $q2]]]);
$c = bale_calls();
$uris = array_map(fn($x) => basename($x['uri']), $c);
check('voice downloaded + transcribed on this server', [in_array('getFile', $uris, true), in_array('1.ogg', $uris, true), in_array('stt', $uris, true)], [true, true, true]);
$stt = array_values(array_filter($c, fn($x) => $x['uri'] === '/stt'))[0]['body'] ?? [];
check('the Bale OGG itself went to local STT', [$stt['bytes'] ?? 0, $stt['head'] ?? ''], [4000, 'OggS']);
$sv = array_values(array_filter($c, fn($x) => str_ends_with($x['uri'], '/sendVoice')));
check('voice answered with one voice message', count($sv), 1);
$spoken = substr($sv[0]['body']['voice']['data'] ?? '', 4);
check('voice is OGG for Bale, replies to the voice', [substr($sv[0]['body']['voice']['data'] ?? '', 0, 4), $sv[0]['body']['voice']['type'] ?? '', (string)($sv[0]['body']['chat_id'] ?? '')],
    ['OggS', 'audio/ogg', '42']);
check('spoken answer = the draft, amount in words, no emoji, own words not echoed',
    [str_contains($spoken, 'واریز نود هزار تومان'), str_contains($spoken, 'بابت، حقوق کارگر ها'), str_contains($spoken, 'درسته'),
     (bool)preg_match('/[\x{1F300}-\x{1FAFF}]/u', $spoken), str_contains($spoken, '«')],
    [true, true, true, false, false]);
check('voice echoed', (bool)array_filter($c, fn($x) => str_contains($x['body']['text'] ?? '', '«حقوق کارگر ها»')), true);
// t2 is a deposit, so the (out) category "حقوق" must not be picked
$d2 = ba_kv_get('bot:draft:' . $t2['id']);
check('draft for t2 respects direction', [is_array($d2), $d2['category_id'] ?? null, $d2['description'] ?? null], [true, null, 'حقوق کارگر ها']);
bot_handle_update(['callback_query' => ['id' => 'c2', 'data' => 'sc:' . $t2['id'] . ':' . $cat('فروش'), 'message' => ['chat' => ['id' => 42], 'message_id' => 7]]]);
check('category button changes draft', ba_kv_get('bot:draft:' . $t2['id'])['category_id'], $cat('فروش'));
bot_handle_update(['callback_query' => ['id' => 'c3', 'data' => 'later:' . $t2['id'], 'message' => ['chat' => ['id' => 42], 'message_id' => 7]]]);
check('later -> nothing left to ask', (bool)preg_match('/گذاشتی برای بعد/u', last_text()), true);

// strangers are ignored, except /start which only reveals their own chat id
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 99], 'text' => 'نادیده']]);
check('stranger ignored', bale_calls(), []);
bot_handle_update(['message' => ['chat' => ['id' => 99], 'text' => '/start']]);
$c = bale_calls();
check('stranger /start gets own chat id', [(string)($c[0]['body']['chat_id'] ?? ''), str_contains($c[0]['body']['text'] ?? '', '99')], ['99', true]);
check('stranger changed nothing', ba_get_transaction($t2['id'])['status'], 'pending');

echo "Local voice\n";
check('number words', [ba_num_words(2500000), ba_num_words(1000), ba_num_words(1405), ba_num_words(90000), ba_num_words(17)],
    ['دو میلیون و پانصد هزار', 'هزار', 'هزار و چهارصد و پنج', 'نود هزار', 'هفده']);
check('speech text', ba_speech_text("🔴 برداشت ۲,۵۰۰,۰۰۰ تومان\n🕓 1405/07/06 18:40\nبابت چی بود؟ /pending"),
    'برداشت دو میلیون و پانصد هزار تومان. شش مهر هزار و چهارصد و پنج ساعت هجده و چهل دقیقه. بابت چی بود؟');
check('time on the hour', ba_speech_text('ساعت 12:00'), 'ساعت دوازده');
check('account numbers are not read out', ba_speech_text('حساب 1234567890123456789'), 'حساب');
bale_calls();
$f1 = ba_tts('ثبت شد.', 'mp3');
$f2 = ba_tts('ثبت شد.', 'mp3');
check('TTS cached: one call for a repeated phrase', [$f1 === $f2, count(array_filter(bale_calls(), fn($x) => $x['uri'] === '/tts'))], [true, 1]);
check('status from /health', [ba_voice_status(true)['stt'], ba_voice_status()['tts_voice']], [true, 'fa_IR-gyro-medium']);
// Audio must never leave the server: a non-local voice_url is refused.
$cfgBak = file_get_contents($work . '/config.php');
file_put_contents($work . '/config.php', str_replace("'voice_url'=>'http://127.0.0.1:$port'", "'voice_url'=>'https://api.example.com'", $cfgBak));
ba_config(true);
try { ba_transcribe(__FILE__); $refused = false; } catch (RuntimeException $e) { $refused = str_contains($e->getMessage(), 'همین سرور'); }
check('non-local voice_url refused', [$refused, ba_voice_status(true)['stt']], [true, false]);
// CLI mode (no daemon): voice_cli is run per request.
$fake = $tmp . '/fake_voice.php';
file_put_contents($fake, '<?php $a = $argv; if ($a[1] === "stt") { echo json_encode(["ok" => true, "text" => "cli:" . filesize($a[2])]); exit(0); }
$out = $a[array_search("--out", $a) + 1]; file_put_contents($out, "OggS" . stream_get_contents(STDIN)); exit(0);');
file_put_contents($work . '/config.php', str_replace("'voice_url'=>'http://127.0.0.1:$port'", "'voice_url'=>'','voice_cli'=>'php $fake'", $cfgBak));
ba_config(true);
$wav = $tmp . '/a.ogg';
file_put_contents($wav, 'OggS12345');
check('CLI mode STT', ba_transcribe($wav), 'cli:9');
check('CLI mode TTS', file_get_contents(ba_tts('سلام ۵ تومان', 'ogg')), 'OggSسلام پنج تومان');
file_put_contents($work . '/config.php', $cfgBak);
ba_config(true);

echo "OTP\n";
$db = ba_db();
$before = (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$r = ba_ingest_sms('BankMellat', "بانک ملت\nرمز پویا: 48213967\nمبلغ: 1,250,000 ریال\nپذیرنده: فروشگاه نمونه\nمهلت: 120 ثانیه");
check('OTP recognised', [$r['otp']['code'] ?? null, $r['otp']['amount'] ?? null, $r['otp']['merchant'] ?? null, $r['otp']['ttl'] ?? null],
    ['48213967', 1250000, 'فروشگاه نمونه', 120]);
check('OTP with "مبلغ" is NOT a withdrawal', (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn(), $before);
$raw = file_get_contents($work . '/data/bank.sqlite') . (is_file($work . '/data/bank.sqlite-wal') ? file_get_contents($work . '/data/bank.sqlite-wal') : '');
check('code not stored in plain text anywhere in the DB file', strpos($raw, '48213967'), false);
$act = ba_otp_active();
check('decrypted for the app', [$act[0]['code'] ?? null, $act[0]['seconds_left'] > 100], ['48213967', true]);
$db->exec('UPDATE otps SET expires_at = ' . (time() - 1));
check('expired -> gone', ba_otp_active(), []);
// code on the same line as the amount; code before keyword
$p = ba_parse_otp('رمز پویا خرید به مبلغ 350,000 ریال: 772911');
check('amount not mistaken for code', [$p['code'], $p['amount']], ['772911', 350000]);
$p = ba_parse_otp("کد تایید شما 55123 می باشد\nاعتبار 2 دقیقه");
check('کد تایید + ttl in minutes', [$p['code'], $p['ttl']], ['55123', 120]);
$p = ba_parse_otp("کارت 6104****1234\nرمز دوم پویا: 902211");
check('masked card ignored', $p['code'], '902211');
// two-part OTP: first part without code, second part with it
ba_ingest_sms('BankMellat2', "بانک ملت\nرمز پویا خرید\nمبلغ: 90,000 ریال\nپذیرنده: کافه");
$r = ba_ingest_sms('BankMellat2', "رمز: 3341907\nمهلت 3 دقیقه");
check('two-part OTP joined', [$r['otp']['code'] ?? null, $r['otp']['amount'] ?? null], ['3341907', 90000]);
$stored = $db->query("SELECT group_concat(body, '|') FROM sms_raw WHERE sender = 'BankMellat2'")->fetchColumn();
check('two-part OTP: no code in sms_raw', strpos($stored, '3341907'), false);
$r = ba_ingest_sms('BankMellat', "بانک ملت\nبرداشت:10,000\nحساب:1234\nمانده:6,290,000\n1405/07/05-10:00");
check('normal SMS right after an OTP still a transaction', isset($r['transaction']['id']), true);

echo "Accounting\n";
$db->exec("UPDATE transactions SET status = 'ignored' WHERE status != 'confirmed'");
$pay = $cat('پرداخت به اشخاص');
$recv = $cat('دریافت از اشخاص');
// opening: Reza owed me 1,000,000 toman
$db->exec("INSERT INTO parties (name, uses, opening) VALUES ('رضا', 0, 10000000)");
// credit sale to Reza 5,000,000 toman, he pays 4,000,000 via bank, credit purchase from Hasan 2,000,000
$db->prepare("INSERT INTO bills (date, party, type, amount, description, created_at) VALUES ('2026-09-25', 'رضا', 'sale', 50000000, 'فروش گردو', '')")->execute();
$db->prepare("INSERT INTO bills (date, party, type, amount, description, created_at) VALUES ('2026-09-26', 'حسن کریمی', 'purchase', 20000000, 'خرید بذر', '')")->execute();
$t = ba_ingest_sms('+98700717', "بانک ملت\nواریز:40,000,000\nحساب:1234\nمانده:46,290,000\n1405/07/06-10:00")['transaction'];
ba_confirm_tx($t['id'], 'تسویه بخشی از طلب', 'رضا', $recv);
check('Reza: 1M + 5M - 4M = 2M toman owed to me', ba_person('رضا')['balance'], 20000000);
// paying Hasan 2M via bank settles him
$t = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:20,000,000\nحساب:1234\nمانده:26,290,000\n1405/07/06-11:00")['transaction'];
ba_confirm_tx($t['id'], 'تسویه', 'حسن کریمی', $pay);
check('Hasan settled', ba_person('حسن کریمی')['balance'], 0);
// a cash purchase WITH a person attached does not change their balance
$t = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:5,000,000\nحساب:1234\nمانده:21,290,000\n1405/07/06-12:00")['transaction'];
ba_confirm_tx($t['id'], 'خرید کود', 'حسن کریمی', $cat('خرید'));
check('cash purchase: Hasan still settled', ba_person('حسن کریمی')['balance'], 0);
$st = ba_person_statement('حسن کریمی');
check('...but it shows in his statement with effect 0', [count($st['rows']), end($st['rows'])['effect']], [4, 0]);
$st = ba_person_statement('رضا');
check('running balance', array_column($st['rows'], 'running'), [60000000, 20000000]);
// party category without a person is refused
$t = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:1,000,000\nحساب:1234\nمانده:20,290,000\n1405/07/06-13:00")['transaction'];
try { ba_confirm_tx($t['id'], 'حواله', '', $pay); $refused = false; } catch (InvalidArgumentException $e) { $refused = true; }
check('person-account category needs a person', $refused, true);
// ATM withdrawal = transfer bank -> cash
ba_confirm_tx($t['id'], 'خودپرداز', '', $cat('انتقال'));
$w = array_column(ba_wallets(), 'balance', 'id');
check('transfer moved 100k toman into cash', $w[2], 1000000);
$pl = ba_profit_loss('2026-09-20', '2026-09-30');
// expenses in range: credit purchase 2M, cash purchase 0.5M, and from earlier sections
// 0.4M "بابت خرید بذر", 0.1M utility bill, 0.1M seed purchase. Settlements and the transfer are excluded.
check('P&L: income = credit sale only; expenses exclude settlements/transfer',
    [$pl['total_income'], $pl['total_expense']], [50000000, 20000000 + 5000000 + 4000000 + 1000000 + 100000]);
$tot = ba_people_totals();
check('totals', [$tot['receivable'], $tot['payable']], [20000000, 0]);

echo "Bot + people\n";
bale_calls();
$t = ba_ingest_sms('+98700717', "بانک ملت\nبرداشت:3,000,000\nحساب:1234\nمانده:17,290,000\n1405/07/07-09:00")['transaction'];
bot_on_new_transaction($t);
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'قسط بدهی']]);
bale_calls();
bot_handle_update(['callback_query' => ['id' => 'c9', 'data' => 'ok:' . $t['id'], 'message' => ['chat' => ['id' => 42], 'message_id' => 9]]]);
check('bot asks for the person', (bool)preg_match('/اسم شخص/u', last_text()), true);
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'رضا']]);
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'آره']]);
$said = implode("\n", array_filter(array_map(fn($c) => $c['body']['text'] ?? null, bale_calls())));
check('filed on Reza, new balance in the reply', [ba_get_transaction($t['id'])['party'], (bool)preg_match('/مانده حساب رضا: 2,300,000 تومان بدهکار/u', $said)], ['رضا', true]);
check('Reza now 2M + 0.3M', ba_person('رضا')['balance'], 23000000);
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => '/p رض']]);
check('/p finds by part of name', (bool)preg_match('/رضا: .*بدهکار/u', last_text()), true);

echo "ESP32 relay through Bale\n";
// The exact message the ESP32 firmware sends (written by firmware/host-test/run.sh), or the same layout.
$relayFile = __DIR__ . '/../../firmware/host-test/build/relay_message.txt';
$relay = is_file($relayFile) ? file_get_contents($relayFile)
    : "🟢 واریز 5,000 تومان\nمانده: 1,234,567 تومان\n──────────\nحساب1234567890\nواریز50,000\nمانده12,345,670\n05/07/10-00:40\n──────────\n✍️ بابت چی بود؟ دکمه را بزن یا روی همین پیام Reply کن و بنویس.";
echo '  (relay message from ' . (is_file($relayFile) ? 'the ESP32 test' : 'built-in sample') . ")\n";
$db->exec("UPDATE transactions SET status = 'ignored' WHERE status = 'pending'");
ba_kv_set('bot:current', null);
$count = fn() => (int)$db->query('SELECT COUNT(*) FROM transactions')->fetchColumn();
$espMsg = fn($id, $text) => ['message_id' => $id, 'from' => ['id' => 1, 'is_bot' => true], 'chat' => ['id' => 42], 'date' => time(), 'text' => $text];
$n0 = $count();
bale_calls();
bot_handle_update(['callback_query' => ['id' => 'r1', 'data' => 'relay:ans', 'message' => $espMsg(7001, $relay)]]);
$t = ba_get_transaction((int)ba_kv_get('bot:current'));
check('button -> SMS booked from the message text', [$count() - $n0, $t['direction'] ?? null, $t['amount'] ?? null, $t['balance'] ?? null], [1, 'in', 50000, 12345670]);
check('asks "what was it for?" as a reply to that message', (bool)preg_match('/بابت چی بود/u', last_text()), true);
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'فروش گردو به رضا']]);
check('answer -> draft for that SMS', ba_kv_get('bot:draft:' . $t['id'])['description'] ?? null, 'فروش گردو به رضا');
bot_handle_update(['callback_query' => ['id' => 'r2', 'data' => 'ok:' . $t['id'], 'message' => ['chat' => ['id' => 42], 'message_id' => 8001]]]);
check('recorded', ba_get_transaction($t['id'])['status'], 'confirmed');
bale_calls();
bot_handle_update(['callback_query' => ['id' => 'r3', 'data' => 'relay:ans', 'message' => $espMsg(7001, $relay)]]);
check('tapping again: "already recorded", no duplicate', [$count() - $n0, (bool)preg_match('/قبلاً ثبت شده/u', last_text())], [1, true]);

// Reply path: the owner answers by replying straight to a second ESP32 message.
$relay2 = str_replace(["واریز 5,000", "واریز50,000", "مانده12,345,670", "00:40"], ["برداشت 30,000", "برداشت300,000", "مانده12,045,670", "11:05"], $relay);
bale_calls();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'خرید نان', 'reply_to_message' => $espMsg(7002, $relay2)]]);
$q = $db->query("SELECT * FROM transactions WHERE amount = 300000 AND direction = 'out'")->fetch();
check('reply -> SMS booked and the reply is its answer', [$q ? 1 : 0, ba_kv_get('bot:draft:' . ($q['id'] ?? 0))['description'] ?? null], [1, 'خرید نان']);
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'آره']]);
check('"آره" records it', ba_get_transaction($q['id'])['status'], 'confirmed');

// Ignore button
$relay3 = str_replace(["00:40", "مانده12,345,670"], ["12:00", "مانده12,395,670"], $relay);
bot_handle_update(['callback_query' => ['id' => 'r4', 'data' => 'relay:ign', 'message' => $espMsg(7003, $relay3)]]);
$q3 = $db->query("SELECT status FROM transactions WHERE balance = 12395670")->fetchColumn();
check('ignore button -> booked as ignored', $q3, 'ignored');

// OTP relayed by the ESP32: replying to it must not book anything.
$n1 = $count();
bot_handle_update(['message' => ['chat' => ['id' => 42], 'text' => 'ok', 'reply_to_message' => $espMsg(7004,
    "🔐 رمز یکبار مصرف\n──────────\nبانک ملت\nرمز پویا: 48213967\nمبلغ: 1,250,000 ریال")]]);
check('reply to a relayed OTP books nothing', $count(), $n1);
// A stranger pressing a relay button changes nothing.
bot_handle_update(['callback_query' => ['id' => 'r5', 'data' => 'relay:ans', 'message' => ['message_id' => 7005, 'chat' => ['id' => 99], 'text' => $relay2]]]);
check('stranger cannot book', $count(), $n1);

proc_terminate($mock);
array_map('unlink', array_merge(glob($work . '/data/tts/*') ?: [], array_filter(glob($work . '/data/*') ?: [], 'is_file')));
@rmdir($work . '/data/tts');
echo $fails ? "\n$fails FAILED\n" : "\nall passed\n";
exit($fails ? 1 : 0);
