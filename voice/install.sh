#!/usr/bin/env bash
# Bank assistant - installs the local Persian voice service on a Linux VPS
# (Ubuntu 22.04/24.04 or Debian 12). Run as root from this folder:
#
#   sudo ./install.sh --php-config /var/www/bank/config.php
#
# Options:
#   --dir DIR            where to install            (default /opt/bank-voice)
#   --stt-model NAME     faster-whisper model         (default large-v3-turbo)
#   --tts-voice NAME     Piper voice                  (default fa_IR-gyro-medium)
#   --convert            the STT model is a Hugging Face Transformers checkpoint
#                        (e.g. a Persian fine-tune): convert it once to CTranslate2
#   --models-from DIR    offline install: copy models from DIR (made by
#                        `voice_service.py download` on another machine)
#   --php-config FILE    write voice_url / voice_token into the PHP config.php
#   --port N             loopback port                (default 8765)
#   --no-service         don't install the systemd service (PHP will use the CLI)
#
# Internet is needed only now (apt, pip, one model download). After that the
# service runs offline: systemd even blocks it from any address but localhost.
set -euo pipefail

SRC="$(cd "$(dirname "$0")" && pwd)"
DIR=/opt/bank-voice
STT_MODEL=large-v3-turbo
TTS_VOICE=fa_IR-gyro-medium
CONVERT=0
MODELS_FROM=
PHP_CONFIG=
PORT=8765
SERVICE=1
SVC_USER=bankvoice

while [ $# -gt 0 ]; do
  case "$1" in
    --dir) DIR="$2"; shift 2;;
    --stt-model) STT_MODEL="$2"; shift 2;;
    --tts-voice) TTS_VOICE="$2"; shift 2;;
    --convert) CONVERT=1; shift;;
    --models-from) MODELS_FROM="$2"; shift 2;;
    --php-config) PHP_CONFIG="$2"; shift 2;;
    --port) PORT="$2"; shift 2;;
    --no-service) SERVICE=0; shift;;
    -h|--help) sed -n '2,22p' "$0"; exit 0;;
    *) echo "unknown option: $1" >&2; exit 1;;
  esac
done

say() { printf '\n\033[1;32m==> %s\033[0m\n' "$*"; }
warn() { printf '\033[1;33m!! %s\033[0m\n' "$*" >&2; }

[ "$(id -u)" -eq 0 ] || { echo "run as root (sudo ./install.sh ...)" >&2; exit 1; }

# ---------------------------------------------------------------- checks
say "Checking the server"
MEM_MB=$(awk '/MemTotal/ {print int($2/1024)}' /proc/meminfo)
CPUS=$(nproc)
echo "RAM: ${MEM_MB} MB, CPU cores: ${CPUS}, arch: $(uname -m)"
case "$STT_MODEL" in
  large-v3|large) if [ "$MEM_MB" -lt 5000 ]; then warn "large-v3 wants ~4 GB RAM free; consider --stt-model large-v3-turbo"; fi;;
  large-v3-turbo|turbo) if [ "$MEM_MB" -lt 3000 ]; then warn "large-v3-turbo wants ~2.5 GB RAM; on a 2 GB VPS use --stt-model small (weaker Persian) or add swap"; fi;;
esac
case "$(uname -m)" in x86_64|aarch64) ;; *) warn "untested CPU architecture; wheels may be missing";; esac

# ---------------------------------------------------------- system packages
say "Installing system packages (python3, venv, ffmpeg)"
if command -v apt-get >/dev/null; then
  export DEBIAN_FRONTEND=noninteractive
  apt-get update -q
  apt-get install -y -q python3 python3-venv python3-pip ffmpeg curl ca-certificates
elif command -v dnf >/dev/null; then
  dnf install -y python3 python3-pip ffmpeg curl || warn "on RHEL/Alma/Rocky ffmpeg needs RPM Fusion / EPEL"
else
  warn "unknown package manager: install python3 (>=3.9), python3-venv and ffmpeg yourself"
fi
python3 -c 'import sys; sys.exit(0 if sys.version_info >= (3, 9) else 1)' || { echo "python 3.9+ required" >&2; exit 1; }
command -v ffmpeg >/dev/null || { echo "ffmpeg missing" >&2; exit 1; }

# --------------------------------------------------------------- files, user
say "Installing into $DIR"
id "$SVC_USER" >/dev/null 2>&1 || useradd --system --home-dir "$DIR" --shell /usr/sbin/nologin "$SVC_USER"
mkdir -p "$DIR/models"
install -m 0755 "$SRC/voice_service.py" "$DIR/voice_service.py"
install -m 0644 "$SRC/requirements.txt" "$SRC/test_voice_service.py" "$SRC/voice.env.sample" "$DIR/"

# ------------------------------------------------------------ python venv
say "Python packages (faster-whisper, piper-tts) in $DIR/venv"
[ -x "$DIR/venv/bin/python" ] || python3 -m venv "$DIR/venv"
"$DIR/venv/bin/pip" install -q --upgrade pip wheel
"$DIR/venv/bin/pip" install -q -r "$DIR/requirements.txt"
if [ "$CONVERT" = 1 ]; then
  "$DIR/venv/bin/pip" install -q 'transformers[torch]'
fi

# ------------------------------------------------------------------ settings
if [ -f "$DIR/voice.env" ]; then
  TOKEN=$(sed -n 's/^VOICE_TOKEN=//p' "$DIR/voice.env")
else
  TOKEN=
fi
[ -n "$TOKEN" ] || TOKEN=$(head -c 24 /dev/urandom | od -An -tx1 | tr -d ' \n')
sed -e "s#^VOICE_PORT=.*#VOICE_PORT=$PORT#" \
    -e "s#^VOICE_TOKEN=.*#VOICE_TOKEN=$TOKEN#" \
    -e "s#^VOICE_MODELS_DIR=.*#VOICE_MODELS_DIR=$DIR/models#" \
    -e "s#^STT_MODEL=.*#STT_MODEL=$STT_MODEL#" \
    -e "s#^TTS_VOICE=.*#TTS_VOICE=$TTS_VOICE#" \
    "$SRC/voice.env.sample" > "$DIR/voice.env"
chmod 0640 "$DIR/voice.env"

# -------------------------------------------------------------------- models
if [ -n "$MODELS_FROM" ]; then
  say "Copying models from $MODELS_FROM (offline install)"
  cp -a "$MODELS_FROM/." "$DIR/models/"
else
  say "Downloading models once: $STT_MODEL + $TTS_VOICE (about 1-3 GB)"
  ARGS=(download --models-dir "$DIR/models" --stt-model "$STT_MODEL" --tts-voice "$TTS_VOICE")
  [ "$CONVERT" = 1 ] && ARGS+=(--convert)
  "$DIR/venv/bin/python" "$DIR/voice_service.py" "${ARGS[@]}"
fi
chown -R "$SVC_USER:$SVC_USER" "$DIR"
# PHP's user reads the token from config.php, not from here; still let the
# web server group run the CLI fallback if it is ever used.
chmod -R o+rX "$DIR/venv" "$DIR/models" "$DIR/voice_service.py"

say "Self-test (no models needed)"
"$DIR/venv/bin/python" "$DIR/test_voice_service.py" >/dev/null 2>&1 && echo "ok" || warn "self-test failed: run $DIR/venv/bin/python $DIR/test_voice_service.py"

# ------------------------------------------------------------------ service
if [ "$SERVICE" = 1 ] && command -v systemctl >/dev/null; then
  say "systemd service bank-voice"
  sed -e "s#__DIR__#$DIR#g" -e "s#__USER__#$SVC_USER#g" "$SRC/bank-voice.service" > /etc/systemd/system/bank-voice.service
  systemctl daemon-reload
  systemctl enable bank-voice >/dev/null
  systemctl restart bank-voice
  echo -n "waiting for the models to load "
  for _ in $(seq 1 120); do
    if curl -fsS -H "X-Voice-Token: $TOKEN" "http://127.0.0.1:$PORT/health" >/dev/null 2>&1; then break; fi
    echo -n "."; sleep 2
  done
  echo
  curl -fsS -H "X-Voice-Token: $TOKEN" "http://127.0.0.1:$PORT/health" || warn "service not answering yet: journalctl -u bank-voice -n 50"
  echo
  VOICE_URL="http://127.0.0.1:$PORT"
  VOICE_CLI=""
else
  VOICE_URL=""
  VOICE_CLI="$DIR/venv/bin/python $DIR/voice_service.py"
fi

# -------------------------------------------------------------- PHP config
LINES="    'voice_url' => '$VOICE_URL',
    'voice_token' => '$TOKEN',
    'voice_cli' => '$VOICE_CLI',"
if [ -n "$PHP_CONFIG" ] && [ -f "$PHP_CONFIG" ]; then
  say "Writing voice settings into $PHP_CONFIG"
  cp -p "$PHP_CONFIG" "$PHP_CONFIG.bak.$(date +%s)"
  # Edited with PHP into a temporary copy; the real file is replaced only if
  # the result passes "php -l", so config.php can never be left broken.
  if VOICE_URL="$VOICE_URL" VOICE_TOKEN="$TOKEN" VOICE_CLI="$VOICE_CLI" CFG="$PHP_CONFIG" php <<'PHP'
<?php
$cfg = getenv('CFG');
$s = file_get_contents($cfg);
$keys = 'voice_url|voice_token|voice_cli|stt_url|stt_key|stt_model|tts_url|tts_key|tts_model|tts_voice';
$s = preg_replace("/^[ \t]*'(?:$keys)'[ \t]*=>.*\R/m", '', $s);      // old voice/stt/tts lines
$add = "    'voice_url' => " . var_export(getenv('VOICE_URL'), true) . ",\n"
     . "    'voice_token' => " . var_export(getenv('VOICE_TOKEN'), true) . ",\n"
     . "    'voice_cli' => " . var_export(getenv('VOICE_CLI'), true) . ",\n";
$s = preg_replace('/^(return\s*\[[ \t]*\R)/m', '$1' . str_replace('$', '\\$', $add), $s, 1, $n);
if ($n !== 1) { fwrite(STDERR, "no 'return [' line found\n"); exit(1); }
$tmp = $cfg . '.new';
file_put_contents($tmp, $s);
exec('php -l ' . escapeshellarg($tmp) . ' 2>&1', $out, $rc);
if ($rc !== 0) { @unlink($tmp); fwrite(STDERR, implode("\n", $out) . "\n"); exit(1); }
$st = stat($cfg);
rename($tmp, $cfg);
@chown($cfg, $st['uid']); @chgrp($cfg, $st['gid']); @chmod($cfg, $st['mode'] & 0777);
echo "config.php ok\n";
PHP
  then :; else
    warn "could not update $PHP_CONFIG automatically (left unchanged). Add these lines inside its array by hand:"
    echo "$LINES"
  fi
else
  say "Put these lines into server/config.php (inside the array):"
  echo "$LINES"
fi
if [ -n "$VOICE_CLI" ]; then warn "no service: every voice message loads the model again (5-20 s). The systemd service is much faster."; fi

say "Done. Test from the server:"
echo "  curl -H 'X-Voice-Token: $TOKEN' http://127.0.0.1:$PORT/health"
echo "  php $(dirname "${PHP_CONFIG:-/path/to/server/config.php}")/cron.php voice-test"
