<?php
/**
 * Bank assistant - one-time web installer for a host (no SSH needed).
 * Open https://your-site/bank/install.php once: it checks the host,
 * creates config.php with random passwords and shows them. As soon as
 * config.php exists it refuses to run again.
 *
 * Only the site's owner can run it: it asks for the code written in
 * data/install-code.txt (made on the first visit, readable only from the host's
 * File Manager, data/ is closed to the web). After installing it deletes itself.
 */

header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex');

$cfgFile = __DIR__ . '/config.php';
$done = null;
$problems = [];

if (is_file($cfgFile)) {
    http_response_code(403);
    $problems[] = 'نصب قبلاً انجام شده (config.php وجود دارد). برای نصب دوباره، اول config.php را از کنترل‌پنل پاک کن.';
} else {
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        $problems[] = 'نسخه‌ی PHP باید ۷٫۴ یا بالاتر باشد (الان ' . PHP_VERSION . '). در کنترل‌پنل هاست، نسخه‌ی PHP را ۸ کن.';
    }
    foreach (['pdo_sqlite' => 'PDO SQLite', 'curl' => 'cURL', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL'] as $ext => $name) {
        if (!extension_loaded($ext)) {
            $problems[] = "افزونه‌ی {$name} روی هاست فعال نیست؛ در کنترل‌پنل (Select PHP Version / PHP Extensions) فعالش کن یا از پشتیبانی هاست بخواه.";
        }
    }
    if (!is_dir(__DIR__ . '/data')) {
        @mkdir(__DIR__ . '/data', 0775, true);
    }
    if (!is_writable(__DIR__ . '/data') || !is_writable(__DIR__)) {
        $problems[] = 'پوشه‌ی برنامه یا data قابل نوشتن نیست؛ در File Manager دسترسی (Permission) پوشه‌ها را 755 بگذار.';
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (!$https) {
        $problems[] = 'این صفحه با http باز شده. ربات بله فقط با https کار می‌کند: در کنترل‌پنل SSL (معمولاً AutoSSL / Let\'s Encrypt رایگان) را فعال کن و این صفحه را با https باز کن.';
    }

    // proof that the visitor owns the host: a code only the File Manager shows
    $codeFile = __DIR__ . '/data/install-code.txt';
    if (!$problems && !is_file($codeFile)) {
        file_put_contents($codeFile, strtoupper(bin2hex(random_bytes(5))) . "\n");
        @chmod($codeFile, 0600);
    }
    $code = is_file($codeFile) ? trim((string)file_get_contents($codeFile)) : '';
    if (!$problems && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && ($code === '' || !hash_equals($code, strtoupper(trim((string)($_POST['code'] ?? '')))))) {
        usleep(500000);
        $problems[] = 'کد نصب درست نیست. در File Manager هاست فایل data/install-code.txt را باز کن و کد داخلش را بنویس.';
    }

    if (!$problems && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        $base = 'https://' . $_SERVER['HTTP_HOST'] . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/\\');
        $app = bin2hex(random_bytes(8));
        $bkp = bin2hex(random_bytes(12));
        $dev = bin2hex(random_bytes(16));
        $pin = (string)random_int(100000, 999999);
        $sample = file_get_contents(__DIR__ . '/config.sample.php');
        $sample = str_replace(
            ["'CHANGE-ME-app'", "'CHANGE-ME-device'", "'otp_pin' => ''", "'https://example.com/bank/app/'", "'https://example.com/bank/api.php'"],
            ["'$app'", "'$dev'", "'otp_pin' => '$pin'", var_export($base . '/app/', true), var_export($base . '/api.php', true)],
            $sample
        );
        $sample = str_replace("'backup_password' => ''", "'backup_password' => '$bkp'", $sample);
        if (file_put_contents($cfgFile, $sample, LOCK_EX) === false) {
            $problems[] = 'نوشتن config.php نشد (دسترسی پوشه).';
        } else {
            @chmod($cfgFile, 0640);
            $done = ['base' => $base, 'app' => $app, 'dev' => $dev, 'pin' => $pin, 'bkp' => $bkp];
            @unlink($codeFile);
        }
    }
}
$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="fa" dir="rtl">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>نصب دستیار بانک</title>
<style>
  body { font-family: Vazirmatn, Tahoma, sans-serif; background: #f3f6f5; color: #16211f; margin: 0; padding: 16px; line-height: 1.8; }
  main { max-width: 640px; margin: 0 auto; }
  .card { background: #fff; border: 1px solid #dde6e3; border-radius: 14px; padding: 16px; margin: 12px 0; }
  .bad { border-color: #e8b4b0; background: #fff6f5; }
  code, pre { direction: ltr; unicode-bidi: isolate; background: #f3f6f5; border-radius: 8px; padding: 2px 6px; font-size: 14px; }
  pre { padding: 10px; overflow-x: auto; text-align: left; }
  .big { font-size: 22px; font-weight: 700; letter-spacing: 1px; }
  button, .btn { font: inherit; font-weight: 700; background: #0f6e5f; color: #fff; border: 0; border-radius: 10px; padding: 10px 18px; text-decoration: none; display: inline-block; }
  .warn { color: #b3261e; font-weight: 700; }
</style>
</head>
<body><main>
<h1>نصب دستیار بانک</h1>
<?php if ($done): ?>
  <div class="card">
    <p>✅ نصب شد. <span class="warn">این صفحه فقط همین یک بار نشان داده می‌شود؛ از آن عکس بگیر یا یادداشت کن.</span></p>
    <p>رمز اپ:<br><span class="big"><code><?= $e($done['app']) ?></code></span></p>
    <p>PIN رمزهای یکبار مصرف:<br><span class="big"><code><?= $e($done['pin']) ?></code></span></p>
    <p>رمز پشتیبان‌ها (بدون آن پشتیبان باز نمی‌شود؛ جایی بیرون از سرور نگه دار):<br><span class="big"><code><?= $e($done['bkp']) ?></code></span></p>
  </div>
  <div class="card">
    <b>برای برنامه‌ی ESP32 (gprs_forwarder.ino):</b>
    <pre>const char *SERVER_URL   = "<?= $e($done['base']) ?>/api.php";
const char *DEVICE_TOKEN = "<?= $e($done['dev']) ?>";</pre>
  </div>
  <div class="card">
    <b>قدم بعد:</b>
    <ol>
      <li>اپ را باز کن و رمز اپ را بزن:<br><a class="btn" href="app/">باز کردن اپ</a></li>
      <li>اپ ← تنظیمات ← «ربات بله»: توکن ربات را بده و «اتصال» را بزن، بعد در بله به ربات <code>/start</code> بفرست.</li>
      <li><code>install.php</code> خودش پاک شد؛ اگر هنوز در File Manager هست، پاکش کن.</li>
    </ol>
  </div>
<?php elseif ($problems): ?>
  <?php foreach ($problems as $p): ?><div class="card bad">⚠️ <?= $e($p) ?></div><?php endforeach; ?>
<?php else: ?>
  <div class="card">
    <p>✅ هاست آماده است: PHP <?= $e(PHP_VERSION) ?>، SQLite، cURL، https.</p>
    <p>با زدن دکمه، تنظیمات و رمزهای تصادفی ساخته و یک بار نشان داده می‌شوند.</p>
    <form method="post">
      <p>برای اینکه فقط صاحب هاست بتواند نصب کند: در File Manager فایل <code>data/install-code.txt</code> را باز کن و کد داخلش را اینجا بنویس.</p>
      <p><input name="code" required autocomplete="off" style="font:inherit;direction:ltr;padding:8px;border:1px solid #ccc;border-radius:8px"></p>
      <button>نصب</button>
    </form>
  </div>
<?php endif; ?>
</main></body>
</html>
<?php
if ($done) {
    @unlink(__FILE__);   // its job is done; nobody can run it again
}
