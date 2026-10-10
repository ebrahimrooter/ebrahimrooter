<?php
/**
 * Backups of all the books: every database in data/ (bank + every company)
 * copied consistently with SQLite's VACUUM INTO, packed into one file,
 * optionally encrypted, kept on the server (last N) and sent to the owner's
 * Bale chat as an off-site copy.
 *
 * config.php (all optional):
 *   'backup_keep'     => 14,       // how many daily backups stay on the server
 *   'backup_to_bale'  => true,     // also send the file to bale_chat_id
 *   'backup_password' => '',       // encrypt (AES-256-GCM); without it you cannot restore!
 *
 *   php cron.php backup                       one now
 *   php cron.php restore FILE [PASSWORD]      put a backup back (the current data is kept aside)
 *
 * File: bank-backup-YYYYMMDD-HHMMSS.bkp = "BABK1" + flag + payload, payload =
 * gzip of a simple archive (name length, name, size, bytes, per database).
 */

require_once __DIR__ . '/lib.php';

const BACKUP_MAGIC = 'BABK1';

function backup_dir()
{
    $d = BA_ROOT . '/data/backups';
    if (!is_dir($d)) {
        mkdir($d, 0700, true);
        file_put_contents("$d/.htaccess", "Require all denied\n");
    }
    return $d;
}

/** Makes one backup now. Returns ['file' => path, 'name', 'size', 'databases' => [...], 'encrypted', 'sent_to_bale']. */
function backup_create($reason = 'manual')
{
    $cfg = ba_config();
    $data = BA_ROOT . '/data';
    $tmp = sys_get_temp_dir() . '/bkp-' . bin2hex(random_bytes(4));
    mkdir($tmp, 0700);
    $files = [];
    try {
        foreach (glob("$data/*.sqlite") ?: [] as $db) {
            $name = basename($db);
            $copy = "$tmp/$name";
            // a consistent copy even while the app writes (no locks held for long)
            $pdo = new PDO('sqlite:' . $db);
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('PRAGMA busy_timeout = 10000');
            $pdo->exec('VACUUM INTO ' . $pdo->quote($copy));
            $pdo = null;
            $files[$name] = $copy;
        }
        if (!$files) {
            throw new RuntimeException('پایگاه داده‌ای برای پشتیبان‌گیری نیست');
        }
        $pack = '';
        foreach ($files as $name => $path) {
            $bytes = file_get_contents($path);
            $pack .= pack('n', strlen($name)) . $name . pack('J', strlen($bytes)) . $bytes;
        }
        $payload = gzencode($pack, 6);
        $password = (string)($cfg['backup_password'] ?? '');
        if ($password !== '') {
            $salt = random_bytes(16);
            $iv = random_bytes(12);
            $key = hash_pbkdf2('sha256', $password, $salt, 200000, 32, true);
            $enc = openssl_encrypt($payload, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
            $body = BACKUP_MAGIC . "E" . $salt . $iv . $tag . $enc;
        } else {
            $body = BACKUP_MAGIC . "P" . $payload;
        }
        $fname = 'bank-backup-' . date('Ymd-His') . '.bkp';
        $out = backup_dir() . '/' . $fname;
        file_put_contents($out, $body, LOCK_EX);
        chmod($out, 0600);
    } finally {
        foreach (glob("$tmp/*") ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($tmp);
    }
    backup_prune((int)($cfg['backup_keep'] ?? 14));
    $info = ['file' => $out, 'name' => $fname, 'size' => filesize($out), 'databases' => array_keys($files),
        'encrypted' => $password !== '', 'sent_to_bale' => false];
    if (($cfg['backup_to_bale'] ?? true) && !empty($cfg['bale_chat_id']) && ba_bale_enabled()) {
        if ($info['encrypted']) {
            $caption = '🗄 پشتیبان حساب‌ها · ' . ba_fa_datetime() . ' · ' . round($info['size'] / 1024) . ' KB · رمزدار';
            $info['sent_to_bale'] = (bool)ba_bale_upload('sendDocument', ['chat_id' => $cfg['bale_chat_id'], 'caption' => $caption],
                'document', $out, 'application/octet-stream', $fname);
        } else {
            // all the books in plain form never leave the server for a chat app
            ba_notify('🗄 پشتیبان روی سرور ساخته شد، ولی به بله فرستاده نشد چون رمز ندارد. در config.php یک backup_password بگذار.');
        }
    }
    ba_kv_set('backup:last', ['name' => $fname, 'at' => date('Y-m-d H:i:s'), 'size' => $info['size'],
        'encrypted' => $info['encrypted'], 'bale' => $info['sent_to_bale'], 'reason' => $reason]);
    return $info;
}

function ba_fa_datetime()
{
    [$y, $m, $d] = ba_g2j((int)date('Y'), (int)date('m'), (int)date('d'));
    return sprintf('%04d/%02d/%02d %s', $y, $m, $d, date('H:i'));
}

function backup_list()
{
    $out = [];
    foreach (glob(backup_dir() . '/bank-backup-*.bkp') ?: [] as $f) {
        $out[] = ['name' => basename($f), 'size' => filesize($f), 'at' => date('Y-m-d H:i:s', filemtime($f)),
            'encrypted' => substr((string)file_get_contents($f, false, null, 0, 6), 5, 1) === 'E'];
    }
    usort($out, fn($a, $b) => strcmp($b['name'], $a['name']));
    return $out;
}

function backup_prune($keep)
{
    $keep = max(1, $keep);
    $all = glob(backup_dir() . '/bank-backup-*.bkp') ?: [];
    rsort($all);
    foreach (array_slice($all, $keep) as $old) {
        @unlink($old);
    }
}

/** Path of a backup by its name (only names this module made). */
function backup_path($name)
{
    if (!preg_match('/^bank-backup-\d{8}-\d{6}\.bkp$/', (string)$name)) {
        return null;
    }
    $p = backup_dir() . '/' . $name;
    return is_file($p) ? $p : null;
}

/** File → [database name => bytes]. */
function backup_unpack($body, $password = '')
{
    if (substr($body, 0, 5) !== BACKUP_MAGIC) {
        throw new RuntimeException('این فایل پشتیبان این برنامه نیست');
    }
    if ($body[5] === 'E') {
        if ($password === '') {
            throw new RuntimeException('این پشتیبان رمز دارد؛ رمز پشتیبان را بده');
        }
        $salt = substr($body, 6, 16);
        $iv = substr($body, 22, 12);
        $tag = substr($body, 34, 16);
        $key = hash_pbkdf2('sha256', $password, $salt, 200000, 32, true);
        $payload = openssl_decrypt(substr($body, 50), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($payload === false) {
            throw new RuntimeException('رمز پشتیبان اشتباه است یا فایل خراب است');
        }
    } else {
        $payload = substr($body, 6);
    }
    $pack = gzdecode($payload);
    if ($pack === false) {
        throw new RuntimeException('فایل پشتیبان خراب است');
    }
    $dbs = [];
    $i = 0;
    while ($i < strlen($pack)) {
        $n = unpack('n', substr($pack, $i, 2))[1];
        $name = substr($pack, $i + 2, $n);
        $size = unpack('J', substr($pack, $i + 2 + $n, 8))[1];
        $bytes = substr($pack, $i + 10 + $n, $size);
        if (!preg_match('/^[a-z0-9_]+\.sqlite$/', $name) || strlen($bytes) !== $size || strncmp($bytes, "SQLite format 3\0", 16) !== 0) {
            throw new RuntimeException('فایل پشتیبان خراب است');
        }
        $dbs[$name] = $bytes;
        $i += 10 + $n + $size;
    }
    return $dbs;
}

/**
 * Puts a backup back. The current databases move to data/before-restore-<time>/
 * (nothing is deleted). Run with the app stopped (php cron.php restore …).
 */
function backup_restore($file, $password = '')
{
    $dbs = backup_unpack((string)file_get_contents($file), $password);
    $data = BA_ROOT . '/data';
    $aside = "$data/before-restore-" . date('Ymd-His');
    mkdir($aside, 0700, true);
    foreach (glob("$data/*.sqlite*") ?: [] as $f) {
        rename($f, "$aside/" . basename($f));
    }
    foreach ($dbs as $name => $bytes) {
        file_put_contents("$data/$name", $bytes);
    }
    return ['restored' => array_keys($dbs), 'previous' => $aside];
}
