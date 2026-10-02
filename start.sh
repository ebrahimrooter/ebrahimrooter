#!/bin/sh
# Bank assistant - run on this Linux / macOS computer (no host needed).
# Needs PHP 8 with pdo_sqlite, curl, mbstring, openssl
#   Ubuntu/Debian:  sudo apt install php-cli php-sqlite3 php-curl php-mbstring
#   macOS:          brew install php
cd "$(dirname "$0")" || exit 1
PORT="${PORT:-8080}"
command -v php >/dev/null 2>&1 || { echo "PHP is not installed (see the top of this file)."; exit 1; }

php server/setup.php "$PORT" || exit 1
php server/cron.php daemon &
DAEMON=$!
trap 'kill $DAEMON 2>/dev/null' EXIT INT TERM
echo "Server is running on port $PORT. Ctrl+C to stop."
php -S "0.0.0.0:$PORT" -t server server/router.php
