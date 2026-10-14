#!/bin/sh
# Bank assistant on a Mac: double-click this file (first time: right-click > Open).
# Needs PHP:  brew install php     (Homebrew: https://brew.sh)
cd "$(dirname "$0")" || exit 1
if ! command -v php >/dev/null 2>&1; then
  for p in /opt/homebrew/bin /usr/local/bin; do [ -x "$p/php" ] && PATH="$p:$PATH"; done
fi
if ! command -v php >/dev/null 2>&1; then
  echo "PHP is not installed. In Terminal:  brew install php   then double-click this file again."
  read -r _
  exit 1
fi
(sleep 2; open "http://localhost:${PORT:-8080}/app/") &
exec sh ./start.sh
