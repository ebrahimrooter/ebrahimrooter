<?php
/**
 * Corpus for the app's own SMS parser (server/app/local/sms.js): every SMS-like
 * string in the tests and the demo, plus fixed cases, with what server/lib.php makes of it.
 *   php tests/local_sms_corpus.php > tests/fixtures/sms_corpus.json
 */
require __DIR__ . '/../lib.php';
$files = array_merge(glob(__DIR__ . '/*.php'), glob(__DIR__ . '/../../tools/demo/*.php'));
$texts = [];
foreach ($files as $f) {
    if (basename($f) === basename(__FILE__)) continue;
    foreach (token_get_all(file_get_contents($f)) as $tok) {
        if (!is_array($tok) || $tok[0] !== T_CONSTANT_ENCAPSED_STRING) continue;
        $s = @eval('return ' . $tok[1] . ';');
        if (is_string($s) && mb_strlen($s) >= 12 && preg_match('/واریز|برداشت|مبلغ|رمز|کد|مانده|خرید|بستانکار|بدهکار|انتقال|ریال/u', $s)) $texts[$s] = 1;
    }
}
foreach ([
    "بانک ملت\nبرداشت:1,250,000\nمانده:45,000,000\n0707-14:25",
    "بانک ملی ایران\nانتقال از:0101234567001\nمبلغ:2,000,000-\nمانده:12,500,000\n1405/07/18 09:12",
    "بلو\nخرید با کارت: 320,000 ریال\nموجودی: 1,200,000 ریال\n12:40",
    "صادرات\nواریز:۵,۰۰۰,۰۰۰\nمانده:۸,۴۰۰,۰۰۰\n۰۵/۰۷/۱۸-۱۶:۲۰",
    "رمز پویا: 48213967\nمبلغ خرید: 1,250,000 ریال\nپذیرنده: فروشگاه نور\nاعتبار: 120 ثانیه",
    "کد تایید شما 5521 است",
    "بانک ملت\nسود سپرده:120,500\nمانده:9,000,000",
    "تبلیغ: با بانک ملت وام بگیرید",
] as $s) $texts[$s] = 1;
$out = [];
foreach (array_keys($texts) as $s) {
    $out[] = ['text' => $s, 'sms' => ba_parse_sms($s), 'otp' => ba_parse_otp($s) ? (['code' => ba_parse_otp($s)['code'], 'amount' => ba_parse_otp($s)['amount'], 'merchant' => ba_parse_otp($s)['merchant']]) : null,
        'bank' => ba_detect_bank('', $s)];
}
echo json_encode(['today' => ba_today_jalali(), 'cases' => $out], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
