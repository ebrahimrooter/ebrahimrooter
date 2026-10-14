#!/bin/sh
# The app's own Persian voice (Piper «amir», fa_IR, CC0) for sherpa-onnx, trimmed
# to what Persian needs (63 MB). Not kept in git: run before building the apps.
#   sh tools/voice-model/fetch.sh android     → android/app/src/main/assets/fa-voice + fa-ear (+ libs/sherpa-onnx.aar)
#   sh tools/voice-model/fetch.sh ios         → ios/BankAssistant/Resources/FaVoice (+ FaEar: speech-to-text)
#   sh tools/voice-model/fetch.sh DIR         → DIR
set -e
cd "$(dirname "$0")/../.." || exit 1
VER=1.13.8
REL=https://github.com/k2-fsa/sherpa-onnx/releases/download
CACHE="${VOICE_CACHE:-${TMPDIR:-/tmp}/bank-voice-cache}"
mkdir -p "$CACHE"
case "$1" in
  android) OUT=android/app/src/main/assets/fa-voice ;;
  ios) OUT=ios/BankAssistant/Resources/FaVoice ;;
  "") echo "usage: fetch.sh android|ios|DIR" >&2; exit 1 ;;
  *) OUT="$1" ;;
esac
get() { [ -s "$CACHE/$2" ] || curl -fsSL --retry 3 -o "$CACHE/$2" "$1"; }
# Persian speech-to-text inside the apps (iOS has no Persian recognizer; Android's needs
# Google): Shenava (Apache-2.0), quantized to int8. iPhone: Koochik (131 MB, ~12% word
# errors on real voices); Android: Rizeh (38 MB, ~17%) so the APK stays under 100 MB.
# NO_EAR=1 skips it (then the phone's / the server's speech-to-text is used).
#   ear DIR Koochik|Rizeh
ear() {
  [ "${NO_EAR:-0}" = 1 ] && return 0
  HFM=https://huggingface.co/Reza2kn/Shenava-$2-v1.0-sherpa-onnx/resolve/main
  if [ ! -s "$CACHE/ear-$2-int8.onnx" ]; then
    get "$HFM/model.onnx" ear-$2-fp32.onnx
    [ -d "$CACHE/venv" ] || { python3 -m venv "$CACHE/venv" && "$CACHE/venv/bin/pip" -q install onnx onnxruntime; }
    "$CACHE/venv/bin/python" tools/voice-model/quantize.py "$CACHE/ear-$2-fp32.onnx" "$CACHE/ear-$2-int8.onnx"
  fi
  get "$HFM/tokens.txt" ear-$2-tokens.txt
  get "$HFM/LICENSE" ear-$2-LICENSE
  rm -rf "$1" && mkdir -p "$1"
  cp "$CACHE/ear-$2-int8.onnx" "$1/model.onnx"; cp "$CACHE/ear-$2-tokens.txt" "$1/tokens.txt"; cp "$CACHE/ear-$2-LICENSE" "$1/LICENSE"
  echo "speech-to-text ($2): $1 ($(du -sh "$1" | cut -f1))"
}
# Android: the int8 voice (18 MB instead of 63; as clear to the recognizer:
# tools/voice-model/stt-report.txt), so the APK fits in git and on your own server
V=vits-piper-fa_IR-amir-medium
[ "$1" = android ] && V=$V-int8
get "$REL/tts-models/$V.tar.bz2" $V.tar.bz2
rm -rf "$CACHE/x" && mkdir -p "$CACHE/x" && tar xjf "$CACHE/$V.tar.bz2" -C "$CACHE/x"
SRC="$CACHE/x/$V"
rm -rf "$OUT" && mkdir -p "$OUT/espeak-ng-data"
cp "$SRC/fa_IR-amir-medium.onnx" "$SRC/tokens.txt" "$SRC/MODEL_CARD" "$OUT/"
for f in phondata phonindex phontab intonations fa_dict lang voices; do cp -r "$SRC/espeak-ng-data/$f" "$OUT/espeak-ng-data/"; done
echo "voice: $OUT ($(du -sh "$OUT" | cut -f1))"
if [ "$1" = android ]; then
  get "$REL/v$VER/sherpa-onnx-$VER.aar" sherpa-onnx-$VER.aar
  mkdir -p android/app/libs && cp "$CACHE/sherpa-onnx-$VER.aar" android/app/libs/sherpa-onnx.aar
  cp tools/voice-model/speech_cases.json android/app/src/main/assets/speech_cases.json
  echo "sherpa-onnx $VER: android/app/libs/sherpa-onnx.aar"
  ear android/app/src/main/assets/fa-ear Rizeh
fi
if [ "$1" = ios ]; then
  cp tools/voice-model/speech_cases.json "$OUT/speech_cases.json"   # for the -voicetest self-check
  ear ios/BankAssistant/Resources/FaEar Koochik
fi
