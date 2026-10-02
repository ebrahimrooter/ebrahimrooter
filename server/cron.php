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
// Check the local voice (STT + TTS on this server, see voice/README.md):
// php /path/to/server/cron.php voice-test
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
    echo '[' . date('H:i:s') . "] daemon started\n";
    $polling_token = null;   // bot this process is polling for
    $poll_fails = 0;
    while (true) {
        try {
            // Re-read the settings: a bot token saved later in the app is picked up
            // without a restart (a server without a domain/https works this way).
            $cfg = ba_config(true);
            ba_kv_set('daemon:alive', time());
            jobs_due();
            $token = ba_bale_enabled() ? (string)$cfg['bale_bot_token'] : null;
            if ($token !== $polling_token) {
                if ($token) {
                    ba_bale('deleteWebhook', []);   // polling and a webhook can't both be active
                    if ($polling_token !== null || ba_kv_get('bale:offset_bot') !== sha1($token)) {
                        ba_kv_set('bale:offset', 0);
                        ba_kv_set('bale:offset_bot', sha1($token));
                    }
                }
                echo '[' . date('H:i:s') . '] ' . ($token ? 'Bale bot polling' : 'Bale not configured') . "\n";
                $polling_token = $token;
            }
            if ($token) {
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
                    // no internet / Bale unreachable: retry calmly. A webhook set
                    // meanwhile also blocks getUpdates: remove it now and then.
                    if (++$poll_fails % 6 === 0) {
                        ba_bale('deleteWebhook', []);
                    }
                    sleep(5);
                } else {
                    $poll_fails = 0;
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

/**
 * Local voice round trip: Piper says a sentence, faster-whisper writes it
 * back. Shows that both engines work on this server without the internet.
 */
function job_voice_test() {
    $st = ba_voice_status(true);
    echo 'mode: ' . ($st['mode'] ?? 'not configured') . (isset($st['stt_model']) ? ", STT {$st['stt_model']}, TTS {$st['tts_voice']}" : '') . "\n";
    if (!empty($st['error']) || !$st['mode']) {
        fwrite(STDERR, ($st['error'] ?? 'voice_url / voice_cli is empty in config.php') . "\n");
        return 1;
    }
    $say = 'برداشت ۲,۵۰۰,۰۰۰ تومان، ۱۴۰۵/۰۷/۰۶ ساعت ۱۸:۴۰. بابت چی بود؟';
    echo "TTS text:  {$say}\nspoken as: " . ba_speech_text($say) . "\n";
    try {
        $t = microtime(true);
        $file = ba_tts($say, 'ogg');
        printf("TTS ok:    %s (%d KB, %.1f s)\n", $file, filesize($file) / 1024, microtime(true) - $t);
        $t = microtime(true);
        $text = ba_transcribe($file, 'audio/ogg', 'test.ogg');
        printf("STT ok:    «%s» (%.1f s)\n", $text, microtime(true) - $t);
    } catch (Throwable $e) {
        fwrite(STDERR, 'failed: ' . $e->getMessage() . "\n");
        return 1;
    }
    return 0;
}

$job = $argv[1] ?? 'weekly';
$jobs = ['weekly' => 'job_weekly', 'remind' => 'job_remind', 'health' => 'job_health',
    'bale-setup' => 'job_bale_setup', 'daemon' => 'job_daemon', 'voice-test' => 'job_voice_test'];
if (!isset($jobs[$job])) {
    fwrite(STDERR, "usage: php cron.php weekly|remind|health|bale-setup|daemon|voice-test\n");
    exit(1);
}
exit((int)$jobs[$job]());
