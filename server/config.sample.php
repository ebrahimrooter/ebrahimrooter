<?php
/**
 * Copy this file to config.php and fill it in. config.php is not committed.
 * Make tokens long and random, e.g.:  php -r "echo bin2hex(random_bytes(20));"
 */
return [
    // Password the phone app asks for the first time.
    'app_token' => 'CHANGE-ME-app',

    // Secret the ESP32 (or Shortcut / SMS forwarder) sends with each SMS.
    'device_token' => 'CHANGE-ME-device',

    // Only keep SMS from these senders (number or alphanumeric ID such as
    // "BankMellat"; matched on the end, case-insensitive). Easier: leave it
    // empty and tick the bank's sender in the app (Settings > SMS senders).
    'allowed_senders' => [],

    // One-time passwords (رمز پویا) that arrive on the same SIM are kept
    // encrypted for their few minutes of validity and shown in the app only
    // after this extra PIN (at least 4 digits, different from app_token).
    'otp_pin' => '',
    // true = also send the code itself in Bale. Off by default: then Bale
    // only says "a code arrived" and the code stays on your own server.
    'otp_to_bale' => false,

    'timezone' => 'Asia/Tehran',

    // Public addresses of the app and of api.php.
    'app_url' => 'https://example.com/bank/app/',
    'api_url' => 'https://example.com/bank/api.php',

    // Optional but recommended: Bale bot. It asks "what was this for?" in the
    // chat, understands typed or voice answers, and sends the weekly report.
    // 1) make a bot with @botfather in Bale and put its token here
    // 2) run once:  php cron.php bale-setup
    // 3) send /start to the bot; it replies with your chat id -> put it below
    'bale_bot_token' => '',
    'bale_chat_id' => '',

    // Optional: each confirmed transaction is POSTed here as JSON (see README)
    // so it can be written into your accounting program automatically.
    'accounting_webhook' => '',

    // Persian voice, entirely on this server (no cloud, no API key):
    // speech-to-text with faster-whisper and text-to-speech with Piper.
    // Install once with:  sudo voice/install.sh --php-config /path/to/config.php
    // (it fills in the three lines below). See voice/README.md.
    //   voice_url: the local voice service; must be 127.0.0.1 / localhost.
    //   voice_cli: instead of the service, run the engine per request, e.g.
    //              '/opt/bank-voice/venv/bin/python /opt/bank-voice/voice_service.py'
    //              (no daemon, but every voice loads the model again: slow).
    'voice_url' => '',
    'voice_token' => '',
    'voice_cli' => '',
    'voice_timeout' => 120,
    // Answer a Bale voice message with a voice message too (plus the usual text).
    'bale_voice_reply' => true,

    // Backups of all the books every night at 3 (data/backups, and as a file in your Bale chat).
    // backup_password encrypts them (AES-256-GCM). Keep it somewhere safe: without it no restore.
    //   php cron.php backup    /    php cron.php restore FILE PASSWORD
    'backup_password' => '',
    'backup_keep' => 14,
    'backup_to_bale' => true,

    // The iPhone app's own notifications (APNs). developer.apple.com → Keys → «+» →
    // Apple Push Notifications service → download AuthKey_XXXX.p8 (once!).
    'apns_key_id' => '',            // e.g. ABC123DEFG
    'apns_team_id' => '',           // your Team ID (Membership details)
    'apns_key_file' => '',          // e.g. /etc/bank-assistant/AuthKey_ABC123DEFG.p8 (outside the web folder)
    'apns_topic' => 'ir.example.bankassistant',   // the app's bundle id (ios/project.yml)
];
