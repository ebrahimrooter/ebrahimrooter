<?php
/**
 * First run on your own computer: creates config.php with random tokens
 * and prints what to put into the ESP32 sketch.
 *   php server/setup.php [port]
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}
$port = (int)($argv[1] ?? 8080);
$cfgFile = __DIR__ . '/config.php';

$missing = array_values(array_filter(['pdo_sqlite', 'curl', 'mbstring', 'openssl'], fn($e) => !extension_loaded($e)));
if ($missing) {
    fwrite(STDERR, "PHP extensions missing: " . implode(', ', $missing) . "\n"
        . "Enable them in php.ini (see README: run on your own computer).\n");
    exit(1);
}

if (!is_file($cfgFile)) {
    $app = bin2hex(random_bytes(4));          // short enough to type on a phone
    $dev = bin2hex(random_bytes(16));
    $pin = (string)random_int(1000, 9999);
    $sample = file_get_contents(__DIR__ . '/config.sample.php');
    $sample = str_replace(["'CHANGE-ME-app'", "'CHANGE-ME-device'", "'otp_pin' => ''"],
        ["'$app'", "'$dev'", "'otp_pin' => '$pin'"], $sample);
    $sample = str_replace("'https://example.com/bank/app/'", "'http://localhost:$port/app/'", $sample);
    $sample = str_replace("'https://example.com/bank/api.php'", "'http://localhost:$port/api.php'", $sample);
    file_put_contents($cfgFile, $sample);
    echo "config.php created.\n";
}
$cfg = require $cfgFile;
if (!is_dir(__DIR__ . '/data')) {
    mkdir(__DIR__ . '/data', 0775, true);
}

// LAN addresses the phone and the ESP32 can reach this computer on.
$out = (string)@shell_exec(PHP_OS_FAMILY === 'Windows' ? 'ipconfig' : '(hostname -I; ifconfig; ip -4 addr) 2>/dev/null');
preg_match_all('/(?<![\d.])((?:192\.168|10|172\.(?:1[6-9]|2\d|3[01]))\.\d{1,3}\.\d{1,3})(?![\d.])/', $out, $m);
// skip the .1 / .255 router & broadcast addresses that ipconfig also lists
$ips = array_filter($m[1], fn($ip) => !preg_match('/\.(1|255)$/', $ip));
$ips = array_values(array_unique($ips));
$ip = $ips[0] ?? 'IP-OF-THIS-COMPUTER';

echo "\n================ Bank assistant ================\n";
echo " On this computer:  http://localhost:$port/app/\n";
foreach ($ips as $i) {
    echo " On your phone:     http://$i:$port/app/   (same Wi-Fi)\n";
}
echo " App password:      {$cfg['app_token']}\n";
echo " OTP PIN:           " . ($cfg['otp_pin'] ?? '') . "\n";
echo "\n Put these in the ESP32 sketch (sms_forwarder.ino):\n";
echo "   SERVER_URL    = \"http://$ip:$port/api.php?r=ingest\"\n";
echo "   HEARTBEAT_URL = \"http://$ip:$port/api.php?r=heartbeat\"\n";
echo "   DEVICE_TOKEN  = \"{$cfg['device_token']}\"\n";
echo "================================================\n\n";
