<?php
/**
 * A pretend Bale Bot API for the emulator test of the app without a server:
 *   php -S 0.0.0.0:8841 tools/demo/fakebale.php
 * The owner sends /start, taps the button under an ESP32 Mellat SMS and replies
 * under a Melli one; every call is logged to fakebale.log next to this file.
 */
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (!preg_match('~^/bot[^/]+/(\w+)$~', $path, $m)) { http_response_code(404); exit; }
$method = $m[1];
$in = json_decode(file_get_contents('php://input'), true) ?: $_POST;
file_put_contents(sys_get_temp_dir() . '/fakebale.log', $method . ' ' . json_encode($in, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
$sep = '──────────';
$relay = fn($head, $sms) => "$head\n$sep\n$sms\n$sep\n✍️ بابت چی بود؟ دکمه را بزن یا روی همین پیام Reply کن و بنویس.";
$chat = ['id' => 555, 'type' => 'private'];
$updates = [
    ['update_id' => 1, 'message' => ['message_id' => 1, 'chat' => $chat, 'date' => time() - 300, 'text' => '/start']],
    ['update_id' => 2, 'callback_query' => ['id' => 'cb1', 'data' => 'relay:ans', 'message' => ['message_id' => 10, 'chat' => $chat, 'date' => time() - 200,
        'text' => $relay('🔴 برداشت 250,000 تومان', "بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0720-14:25")]]],
    ['update_id' => 3, 'message' => ['message_id' => 21, 'chat' => $chat, 'date' => time() - 100, 'text' => 'فروش لپ تاپ از علی رضایی',
        'reply_to_message' => ['message_id' => 11, 'chat' => $chat, 'date' => time() - 150,
            'text' => $relay('🟢 واریز 700,000 تومان', "بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n10:05")]]],
];
header('Content-Type: application/json; charset=utf-8');
$result = true;
if ($method === 'getMe') $result = ['id' => 1, 'username' => 'test_bank_bot', 'is_bot' => true];
if ($method === 'getUpdates') $result = array_values(array_filter($updates, fn($u) => $u['update_id'] >= (int)($in['offset'] ?? 0)));
if ($method === 'sendMessage') $result = ['message_id' => 900];
echo json_encode(['ok' => true, 'result' => $result], JSON_UNESCAPED_UNICODE);
