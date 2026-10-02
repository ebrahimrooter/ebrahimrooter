#!/usr/bin/env bash
# Bank assistant - full install on a fresh Ubuntu 22.04/24.04 VPS, in one go:
# Apache + PHP, the app in /var/www/html/bank, HTTPS (Let's Encrypt),
# config.php with fresh passwords, cron jobs and the local Persian voice.
#
#   sudo bash deploy-vps.sh --domain bank.example.ir
#
# Options:
#   --domain NAME     domain already pointing at this server's IP: HTTPS, Bale
#                     webhook, full phone app. Without it the app is on
#                     http://IP/bank and the Bale bot runs as a background
#                     service that fetches its messages (bank-bot.service).
#   --email ADDR      for Let's Encrypt expiry notices (optional)
#   --no-voice        skip the local STT/TTS (faster-whisper + Piper)
#   --stt-model NAME  passed to voice/install.sh (default large-v3-turbo)
#   --models-from DIR passed to voice/install.sh (models copied, no download)
#
# Safe to run again (e.g. to update): config.php and the database are kept.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
WEB=/var/www/html/bank
DOMAIN=
EMAIL=
VOICE=1
VOICE_ARGS=()

while [ $# -gt 0 ]; do
  case "$1" in
    --domain) DOMAIN="$2"; shift 2;;
    --email) EMAIL="$2"; shift 2;;
    --no-voice) VOICE=0; shift;;
    --stt-model|--models-from|--tts-voice) VOICE_ARGS+=("$1" "$2"); shift 2;;
    -h|--help) sed -n '2,19p' "$0"; exit 0;;
    *) echo "unknown option: $1" >&2; exit 1;;
  esac
done

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*" >&2; }
restart_apache() {
  if command -v systemctl >/dev/null && systemctl is-system-running >/dev/null 2>&1; then
    systemctl restart apache2
  else
    service apache2 restart >/dev/null || apachectl -k restart
  fi
}

[ "$(id -u)" -eq 0 ] || { echo "run as root: sudo bash deploy-vps.sh ..." >&2; exit 1; }
[ -f "$SRC/server/api.php" ] || { echo "run this from the unzipped bank-assistant folder" >&2; exit 1; }

# ------------------------------------------------------------ packages
say "Installing Apache and PHP"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q apache2 libapache2-mod-php php php-cli php-sqlite3 php-curl php-mbstring \
  rsync curl unzip ca-certificates cron
[ -n "$DOMAIN" ] && apt-get install -y -q certbot python3-certbot-apache

# --------------------------------------------------------------- files
say "Copying the app to $WEB"
mkdir -p "$WEB"
# keep the owner's config and database on updates
rsync -a --delete --exclude 'config.php' --exclude 'config.php.bak*' --exclude 'data/' "$SRC/server/" "$WEB/"
mkdir -p "$WEB/data"
[ -f "$WEB/data/.htaccess" ] || cp "$SRC/server/data/.htaccess" "$WEB/data/.htaccess"
chown -R www-data:www-data "$WEB"

# .htaccess must be honoured: it keeps config.php and the database private
sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf
a2enmod -q rewrite headers >/dev/null
restart_apache

# ---------------------------------------------------------------- HTTPS
if [ -n "$DOMAIN" ]; then
  say "HTTPS certificate for $DOMAIN"
  if [ -n "$EMAIL" ]; then M=(-m "$EMAIL"); else M=(--register-unsafely-without-email); fi
  if certbot --apache -d "$DOMAIN" --non-interactive --agree-tos --redirect "${M[@]}"; then
    BASE="https://$DOMAIN/bank"
  else
    warn "certbot failed: is the domain's DNS (A record) pointing at this server? Run again later."
    BASE="http://$DOMAIN/bank"
  fi
else
  IP=$(curl -fsS -4 --max-time 5 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')
  BASE="http://$IP/bank"
  warn "no --domain: the Bale bot works through a background service (polling); the app opens on $BASE"
  warn "(without HTTPS the phone app can't use the microphone or notifications - use voice in Bale)."
fi

case "$BASE" in https://*) MODE=webhook;; *) MODE=polling;; esac

# --------------------------------------------------------------- config
CFG="$WEB/config.php"
NEW_CFG=0
if [ ! -f "$CFG" ]; then
  say "Creating config.php with new passwords"
  BASE="$BASE" CFG="$CFG" php <<'PHP'
<?php
$cfg = getenv('CFG');
$base = getenv('BASE');
$s = file_get_contents(dirname($cfg) . '/config.sample.php');
$s = str_replace(
    ["'CHANGE-ME-app'", "'CHANGE-ME-device'", "'otp_pin' => ''",
     "'https://example.com/bank/app/'", "'https://example.com/bank/api.php'"],
    [var_export(bin2hex(random_bytes(5)), true), var_export(bin2hex(random_bytes(16)), true),
     "'otp_pin' => '" . random_int(100000, 999999) . "'",
     var_export($base . '/app/', true), var_export($base . '/api.php', true)],
    $s);
file_put_contents($cfg, $s);
PHP
  NEW_CFG=1
else
  say "config.php already exists - kept (passwords unchanged)"
  if ! php -l "$CFG" >/dev/null 2>&1; then
    # e.g. a failed edit: go back to the newest backup that is valid PHP
    for b in $(ls -t "$CFG".bak.* 2>/dev/null); do
      if php -l "$b" >/dev/null 2>&1; then
        warn "config.php was broken - restored from $(basename "$b")"
        cp -p "$b" "$CFG"
        break
      fi
    done
  fi
  # the domain may have changed from http to https
  sed -i -E "s#^(\s*'app_url' => ')[^']*'#\1$BASE/app/'#; s#^(\s*'api_url' => ')[^']*'#\1$BASE/api.php'#" "$CFG"
fi
chown www-data:www-data "$CFG"
chmod 0640 "$CFG"
php -l "$CFG" >/dev/null
# install.php is not needed: the config was made here
rm -f "$WEB/install.php"

# ------------------------------------------- Bale bot + scheduled jobs
if [ "$MODE" = webhook ]; then
  # HTTPS: Bale calls the server (webhook); cron runs the scheduled jobs.
  say "Scheduled jobs (weekly report, daily reminder, device health)"
  cat > /etc/cron.d/bank-assistant <<EOF
# Bank assistant (deploy-vps.sh)
0 20 * * 5   www-data php $WEB/cron.php weekly >/dev/null 2>&1
0 21 * * *   www-data php $WEB/cron.php remind >/dev/null 2>&1
*/15 * * * * www-data php $WEB/cron.php health >/dev/null 2>&1
EOF
  chmod 0644 /etc/cron.d/bank-assistant
  if [ -f /etc/systemd/system/bank-bot.service ] && command -v systemctl >/dev/null; then
    systemctl disable --now bank-bot >/dev/null 2>&1 || true
  fi
else
  # No domain / HTTPS: a background service fetches the bot's messages from
  # Bale itself (polling) and also runs the scheduled jobs, so no cron.
  say "Background service bank-bot (Bale bot without a domain + scheduled jobs)"
  rm -f /etc/cron.d/bank-assistant
  cat > /etc/systemd/system/bank-bot.service <<EOF
[Unit]
Description=Bank assistant - Bale bot (polling) and scheduled jobs
After=network-online.target bank-voice.service
Wants=network-online.target

[Service]
User=www-data
ExecStart=/usr/bin/php $WEB/cron.php daemon
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
EOF
  if command -v systemctl >/dev/null && systemctl is-system-running >/dev/null 2>&1; then
    systemctl daemon-reload
    systemctl enable --now bank-bot >/dev/null
    systemctl restart bank-bot
  else
    warn "systemd is not running here: start it by hand: sudo -u www-data php $WEB/cron.php daemon"
  fi
fi

# ---------------------------------------------------------------- voice
if [ "$VOICE" = 1 ]; then
  say "Local Persian voice (this takes 10-20 minutes)"
  if bash "$SRC/voice/install.sh" --php-config "$CFG" ${VOICE_ARGS[@]+"${VOICE_ARGS[@]}"}; then
    chown www-data:www-data "$CFG"; chmod 0640 "$CFG"
    sudo -u www-data php "$WEB/cron.php" voice-test || warn "voice-test failed: journalctl -u bank-voice -n 50"
  else
    warn "voice install failed; the rest works. Retry: sudo bash voice/install.sh --php-config $CFG"
  fi
fi

# --------------------------------------------------------------- summary
APP=$(php -r '$c = require $argv[1]; echo $c["app_token"];' "$CFG")
DEV=$(php -r '$c = require $argv[1]; echo $c["device_token"];' "$CFG")
PIN=$(php -r '$c = require $argv[1]; echo $c["otp_pin"];' "$CFG")
say "Done"
echo "  App:            $BASE/app/"
if [ "$NEW_CFG" = 1 ]; then
  echo "  App password:   $APP        <- write these down, shown only now"
  echo "  OTP PIN:        $PIN"
fi
echo "  ESP32 (gprs_forwarder.ino):"
echo "    SERVER_URL   = \"$BASE/api.php\""
echo "    DEVICE_TOKEN = \"$DEV\""
echo
echo "  Next: open the app -> Settings -> Bale bot -> paste the bot token -> Connect,"
echo "        then within 10 minutes send /start to the bot in Bale."
if [ "$MODE" = polling ]; then echo "  Bot mode: no domain (polling). Status: systemctl status bank-bot"; fi
