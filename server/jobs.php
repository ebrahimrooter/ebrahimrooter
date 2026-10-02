<?php
/**
 * Bank assistant - scheduled jobs, shared by cron.php (host cron / local
 * daemon) and api.php (the ESP32 heartbeat runs whatever is due, so a host
 * without cron still gets the daily reminder and the weekly report).
 */

require_once __DIR__ . '/lib.php';
require_once __DIR__ . '/bot.php';
require_once __DIR__ . '/webpush.php';

function job_weekly() {
    $report = ba_balance_check(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
    ba_save_reconciliation('balance', $report);

    $lines = ['📊 گزارش هفتگی مغایرت بانک (' . ba_iso_to_jalali($report['from']) . ' تا ' . ba_iso_to_jalali($report['to']) . ')'];
    $lines[] = 'پیامک‌های بررسی‌شده: ' . $report['checked'];
    if ($report['last_balance'] !== null) {
        $lines[] = 'آخرین مانده: ' . ba_toman($report['last_balance']);
    }
    if ($report['ok']) {
        $lines[] = '✅ زنجیره مانده‌ها کامل است؛ هیچ پیامکی جا نیفتاده.';
    } else {
        $lines[] = '⚠️ ' . count($report['gaps']) . ' مغایرت:';
        foreach ($report['gaps'] as $g) {
            $lines[] = '• بین ' . $g['after_date'] . ' و ' . $g['before_date'] . ': '
                . ba_toman($g['missing']) . ($g['missing'] > 0 ? ' واریز ثبت‌نشده' : ' برداشت ثبت‌نشده');
        }
        $lines[] = 'صورت‌حساب بانک را از همراه‌بانک بگیر و در اپ «مغایرت» بارگذاری کن.';
    }
    if ($report['pending']) {
        $lines[] = '🕓 ' . $report['pending'] . ' تراکنش هنوز «بابت» ندارد.';
    }
    $pl = ba_profit_loss(date('Y-m-d', strtotime('-7 days')), date('Y-m-d'));
    $lines[] = '';
    $lines[] = '💼 این هفته: درآمد ' . ba_toman($pl['total_income']) . ' · هزینه ' . ba_toman($pl['total_expense'])
        . ' · ' . ($pl['profit'] >= 0 ? 'سود ' : 'زیان ') . ba_toman($pl['profit']);
    $t = ba_people_totals();
    $lines[] = '👥 طلب تو از دیگران: ' . ba_toman($t['receivable']) . ' · بدهی تو: ' . ba_toman($t['payable']);
    $overdue = array_filter(ba_people(), fn($p) => $p['overdue'] && $p['balance'] > 0);
    foreach (array_slice($overdue, 0, 10) as $p) {
        $lines[] = '⏰ سررسید گذشته: ' . $p['name'] . ' — ' . ba_toman($p['balance']);
    }
    $text = implode("\n", $lines);
    ba_notify($text);
    wp_notify_all(['title' => '📊 گزارش هفتگی بانک', 'body' => implode(' · ', array_slice($lines, 1, 3)), 'url' => '#/reconcile', 'tag' => 'weekly']);
    echo $text, "\n";
}

function job_remind() {
    // Housekeeping: text of non-bank SMS is not kept longer than a day.
    ba_db()->prepare("UPDATE sms_raw SET body = '' WHERE status IN ('new', 'ignored') AND received_at < ?")
        ->execute([date('Y-m-d H:i:s', time() - 86400)]);
    ba_otp_purge();
    $n = (int)ba_db()->query("SELECT COUNT(*) FROM transactions WHERE status = 'pending'")->fetchColumn();
    if ($n) {
        ba_notify("🕓 {$n} تراکنش منتظر جواب «بابت چی بود؟» است.");
        ba_kv_set('bot:skipped', []);
        bot_ask_next();
    }
    echo "pending: $n\n";
}

function job_health() {
    ba_otp_purge();
    $dev = ba_kv_get('device');
    if (!$dev) {
        echo "no heartbeat ever received (ok if you don't use the ESP32)\n";
        return;
    }
    $silent_min = (int)((time() - strtotime($dev['last_seen'])) / 60);
    $problem = null;
    if ($silent_min >= 30) {
        $problem = "⚠️ دستگاه پیامک {$silent_min} دقیقه است خبری نداده (برق، وای‌فای یا ماژول). تا وصل نشود پیامک‌های بانک اینجا نمی‌رسند؛ روی سیم‌کارت می‌مانند و بعداً فرستاده می‌شوند.";
    } elseif (isset($dev['registered']) && !in_array($dev['registered'], [1, 5], true)) {
        $problem = '⚠️ سیم‌کارت دستگاه در شبکه ثبت نیست (آنتن/اعتبار سیم‌کارت را چک کن).';
    } elseif (isset($dev['sms_on_sim']) && $dev['sms_on_sim'] >= 25) {
        $problem = "⚠️ {$dev['sms_on_sim']} پیامک روی سیم‌کارت مانده و ارسال نشده؛ اتصال دستگاه به سرور را چک کن.";
    }
    if ($problem && empty($dev['alerted'])) {
        ba_notify($problem);
        wp_notify_all(['title' => '⚠️ دستگاه پیامک', 'body' => $problem, 'url' => '#/settings', 'tag' => 'device']);
        $dev['alerted'] = true;
        ba_kv_set('device', $dev);
    }
    echo ($problem ?: 'device ok') . "\n";
}

/**
 * Runs every job whose time has come (each at most once per period).
 * $withHealth = false when called from the ESP32 heartbeat: the device just
 * reported in, so "device went quiet" can't be true then.
 */
function jobs_due($withHealth = true) {
    $now = time();
    $today = date('Y-m-d');
    if ($withHealth && $now - (int)ba_kv_get('daemon:health', 0) >= 900) {
        ba_kv_set('daemon:health', $now);
        job_health();
    }
    if ((int)date('G') >= 21 && ba_kv_get('daemon:remind') !== $today) {
        ba_kv_set('daemon:remind', $today);
        job_remind();
    }
    if ((int)date('N') === 5 && (int)date('G') >= 20 && ba_kv_get('daemon:weekly') !== $today) {
        ba_kv_set('daemon:weekly', $today);
        job_weekly();
    }
}
