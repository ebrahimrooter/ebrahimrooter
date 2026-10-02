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

    // Optional: server-side Persian speech-to-text for iPhone
    // (any OpenAI-compatible /v1/audio/transcriptions endpoint).
    'stt_url' => '',
    'stt_key' => '',
    'stt_model' => 'whisper-1',

    // Optional: Persian voice for the orb on iPhone (iOS has no Persian
    // voice). Any OpenAI-compatible /v1/audio/speech endpoint.
    'tts_url' => '',
    'tts_key' => '',
    'tts_model' => 'tts-1',
    'tts_voice' => 'alloy',
];
