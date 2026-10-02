<?php
// Bank assistant - scheduled jobs. Run from the host's cron:
//
// # every Friday 20:00 - weekly reconciliation report
// 0 20 * * 5  php /path/to/server/cron.php weekly
// # every day 21:00 - reminder of transactions nobody has explained yet
// 0 21 * * *  php /path/to/server/cron.php remind
// # every 15 minutes - alert if the ESP32 stopped reporting
// */15 * * * *  php /path/to/server/cron.php health
//
// One-off: connect the Bale bot to this server (after filling config.php):
// php /path/to/server/cron.php bale-setup
//
// On your own computer instead of a host: one process does all of the above
// and polls the Bale bot (no public address needed):
// php /path/to/server/cron.php daemon
//
// On a host without cron the ESP32 heartbeat (every 10 min) runs the due
// jobs too (see jobs.php); cron is then only needed for "device went quiet".

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('cli only');
}
require_once __DIR__ . '/jobs.php';
ba_config();

function job_bale_setup() {
    $cfg = ba_config();
    if (empty($cfg['bale_bot_token']) || empty($cfg['api_url'])) {
        fwrite(STDERR, "bale_bot_token and api_url must be set in config.php\n");
        return 1;
    }
    $url = $cfg['api_url'] . '?r=bale&key=' . bot_webhook_key();
    $ok = ba_bale('setWebhook', ['url' => $url]);
    echo $ok ? "webhook set: {$cfg['api_url']}?r=bale&key=***\n" : "setWebhook failed (token / network?)\n";
    if ($ok && !empty($cfg['bale_chat_id'])) {
        ba_notify(bot_help());
    } elseif ($ok) {
        echo "Now send /start to the bot in Bale; it will reply with your chat id for bale_chat_id.\n";
    }
    return 0;
}

/**
 * For running on your own computer (no host, no cPanel): one long-running
 * process that does the scheduled jobs and talks to the Bale bot by polling
 * (getUpdates), so the bot works without a public web address.
 *   php cron.php daemon
 */
function job_daemon() {
    $bale = ba_bale_enabled();
    echo '[' . date('H:i:s') . '] daemon started' . ($bale ? ', Bale bot polling' : ' (Bale not configured)') . "\n";
    if ($bale) {
        ba_bale('deleteWebhook', []);   // polling and a webhook can't both be active
    }
    while (true) {
        try {
            jobs_due();
            if ($bale) {
                $updates = ba_bale('getUpdates', ['offset' => (int)ba_kv_get('bale:offset', 0), 'timeout' => 25], 40);
                foreach (is_array($updates) ? $updates : [] as $u) {
                    ba_kv_set('bale:offset', (int)$u['update_id'] + 1);
                    try {
                        bot_handle_update($u);
                    } catch (Throwable $e) {
                        fwrite(STDERR, '[' . date('H:i:s') . '] bot: ' . $e->getMessage() . "\n");
                    }
                }
                if (!is_array($updates)) {
                    sleep(5);   // no internet / Bale unreachable: retry calmly
                }
            } else {
                ba_otp_purge();
                sleep(20);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, '[' . date('H:i:s') . '] ' . $e->getMessage() . "\n");
            sleep(10);
        }
    }
}

$job = $argv[1] ?? 'weekly';
$jobs = ['weekly' => 'job_weekly', 'remind' => 'job_remind', 'health' => 'job_health',
    'bale-setup' => 'job_bale_setup', 'daemon' => 'job_daemon'];
if (!isset($jobs[$job])) {
    fwrite(STDERR, "usage: php cron.php weekly|remind|health|bale-setup|daemon\n");
    exit(1);
}
exit((int)$jobs[$job]());
