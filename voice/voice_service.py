#!/usr/bin/env python3
"""
Bank assistant - local Persian speech service (no cloud, no API key).

  STT: faster-whisper (CTranslate2), default model "large-v3-turbo", int8 on CPU
  TTS: Piper (ONNX), default voice "fa_IR-gyro-medium"

Everything runs on this server. Models are downloaded ONCE by
`voice_service.py download` (run by install.sh); after that the service runs
with Hugging Face in offline mode and never touches the network.

PHP talks to it in one of two ways, both local only:
  1) HTTP on 127.0.0.1 (recommended, models stay loaded in memory):
       voice_service.py serve [--host 127.0.0.1 --port 8765]
       GET  /health                     -> {"ok":true, ...}
       POST /stt   body = audio bytes   -> {"ok":true,"text":"..."}
       POST /tts   {"text":"...","format":"ogg|mp3|wav"} -> audio bytes
  2) CLI, one process per request (no daemon; slower, model loads every time):
       voice_service.py stt  FILE                -> JSON on stdout
       voice_service.py tts  --out FILE [--format ogg] < text on stdin

Settings come from command-line flags or environment variables
(see voice.env.sample); flags win.
"""

import argparse
import io
import json
import os
import shutil
import subprocess
import sys
import tempfile
import threading
import time
import wave
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))

# Words the owner says in answers. Given to Whisper as context, this noticeably
# improves spelling of banking terms and names in Persian.
STT_PROMPT = ("واریز، برداشت، حواله، کارت به کارت، بابت، تومان، ریال، قسط، تسویه، "
              "خرید، فروش، حقوق، اجاره، قبض، بعدی، نادیده، آره، ثبت کن.")

MAX_AUDIO_BYTES = 25 * 1024 * 1024
MAX_AUDIO_SECONDS = 180
MAX_TTS_CHARS = 1500

PIPER_URL = ("https://huggingface.co/rhasspy/piper-voices/resolve/main/"
             "{family}/{code}/{name}/{quality}/{code}-{name}-{quality}{ext}?download=true")


def env(name, default=None):
    v = os.environ.get(name)
    return v if v not in (None, "") else default


def settings(args=None):
    s = {
        "host": env("VOICE_HOST", "127.0.0.1"),
        "port": int(env("VOICE_PORT", "8765")),
        "token": env("VOICE_TOKEN", ""),
        "models_dir": env("VOICE_MODELS_DIR", os.path.join(HERE, "models")),
        "stt_model": env("STT_MODEL", "large-v3-turbo"),
        "stt_device": env("STT_DEVICE", "cpu"),
        "stt_compute": env("STT_COMPUTE_TYPE", "int8"),
        "stt_threads": int(env("STT_THREADS", "0")),
        "stt_beam": int(env("STT_BEAM_SIZE", "5")),
        "tts_voice": env("TTS_VOICE", "fa_IR-gyro-medium"),
        "tts_length_scale": float(env("TTS_LENGTH_SCALE", "1.0")),
        "tts_speaker": env("TTS_SPEAKER"),
        "preload": env("VOICE_PRELOAD", "1") != "0",
    }
    if args is not None:
        for k in list(s):
            v = getattr(args, k, None)
            if v is not None:
                s[k] = v
    return s


def ffmpeg_bin():
    path = env("FFMPEG", shutil.which("ffmpeg"))
    if not path:
        raise RuntimeError("ffmpeg is not installed (apt install ffmpeg)")
    return path


def run_ffmpeg(args, data):
    p = subprocess.run([ffmpeg_bin(), "-hide_banner", "-loglevel", "error", "-nostdin"] + args,
                       input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=120)
    if p.returncode != 0:
        raise RuntimeError("ffmpeg: " + p.stderr.decode("utf-8", "replace").strip()[:300])
    return p.stdout


# --------------------------------------------------------------------------
# Model locations
# --------------------------------------------------------------------------

def stt_model_path(s):
    """A local directory: an explicit path, models/whisper/<name>, or None (use the HF cache)."""
    m = s["stt_model"]
    if os.path.isdir(m):
        return m
    local = os.path.join(s["models_dir"], "whisper", m.replace("/", "--"))
    if os.path.isfile(os.path.join(local, "model.bin")):
        return local
    return None


def piper_files(s):
    v = s["tts_voice"]
    if v.endswith(".onnx") and os.path.isfile(v):
        return v, v + ".json"
    onnx = os.path.join(s["models_dir"], "piper", v + ".onnx")
    return onnx, onnx + ".json"


# --------------------------------------------------------------------------
# Engines (loaded once, used under a lock: one CPU-heavy job at a time)
# --------------------------------------------------------------------------

class Engines:
    def __init__(self, s):
        self.s = s
        self._stt = None
        self._tts = None
        self._stt_lock = threading.Lock()
        self._tts_lock = threading.Lock()

    # ---- STT ----
    def stt_model(self):
        if self._stt is None:
            from faster_whisper import WhisperModel
            s = self.s
            path = stt_model_path(s)
            self._stt = WhisperModel(
                path or s["stt_model"],
                device=s["stt_device"],
                compute_type=s["stt_compute"],
                cpu_threads=s["stt_threads"],
                download_root=os.path.join(s["models_dir"], "hf-cache"),
                local_files_only=True,
            )
        return self._stt

    def transcribe(self, audio_bytes, language="fa"):
        if not audio_bytes:
            raise ValueError("empty audio")
        if len(audio_bytes) > MAX_AUDIO_BYTES:
            raise ValueError("audio too large")
        from faster_whisper.audio import decode_audio
        audio = decode_audio(io.BytesIO(audio_bytes), sampling_rate=16000)
        duration = len(audio) / 16000.0
        if duration > MAX_AUDIO_SECONDS:
            raise ValueError("audio longer than %d s" % MAX_AUDIO_SECONDS)
        t0 = time.time()
        with self._stt_lock:
            segments, info = self.stt_model().transcribe(
                audio,
                language=language or "fa",
                task="transcribe",
                beam_size=self.s["stt_beam"],
                vad_filter=True,
                vad_parameters={"min_silence_duration_ms": 500},
                condition_on_previous_text=False,
                initial_prompt=STT_PROMPT,
            )
            text = " ".join(seg.text.strip() for seg in segments).strip()
        return {"ok": True, "text": text, "language": info.language,
                "duration": round(duration, 2), "seconds": round(time.time() - t0, 2)}

    # ---- TTS ----
    def tts_voice(self):
        if self._tts is None:
            from piper import PiperVoice
            onnx, cfg = piper_files(self.s)
            if not os.path.isfile(onnx):
                raise RuntimeError("Piper voice not found: %s (run: voice_service.py download)" % onnx)
            self._tts = PiperVoice.load(onnx, config_path=cfg)
        return self._tts

    def synthesize(self, text, fmt="ogg"):
        text = (text or "").strip()
        if not text:
            raise ValueError("empty text")
        if len(text) > MAX_TTS_CHARS:
            raise ValueError("text too long")
        if fmt not in ("wav", "mp3", "ogg"):
            raise ValueError("format must be wav, mp3 or ogg")
        from piper import SynthesisConfig
        syn = SynthesisConfig(
            length_scale=self.s["tts_length_scale"],
            speaker_id=int(self.s["tts_speaker"]) if self.s["tts_speaker"] not in (None, "") else None,
        )
        buf = io.BytesIO()
        with self._tts_lock:
            voice = self.tts_voice()
            with wave.open(buf, "wb") as wf:
                voice.synthesize_wav(text, wf, syn_config=syn)
        wav = buf.getvalue()
        if fmt == "wav":
            return wav, "audio/wav"
        if fmt == "mp3":
            return run_ffmpeg(["-f", "wav", "-i", "pipe:0", "-ac", "1", "-codec:a", "libmp3lame",
                               "-b:a", "64k", "-f", "mp3", "pipe:1"], wav), "audio/mpeg"
        # Bale / Telegram voice notes must be OGG with the Opus codec.
        return run_ffmpeg(["-f", "wav", "-i", "pipe:0", "-ac", "1", "-ar", "48000", "-codec:a", "libopus",
                           "-b:a", "32k", "-application", "voip", "-f", "ogg", "pipe:1"], wav), "audio/ogg"

    def status(self):
        onnx, _ = piper_files(self.s)
        return {
            "ok": True,
            "stt_model": self.s["stt_model"],
            "stt_ready": bool(stt_model_path(self.s)) or self._stt is not None,
            "stt_loaded": self._stt is not None,
            "tts_voice": self.s["tts_voice"],
            "tts_ready": os.path.isfile(onnx),
            "tts_loaded": self._tts is not None,
            "ffmpeg": bool(shutil.which("ffmpeg") or env("FFMPEG")),
        }


# --------------------------------------------------------------------------
# HTTP server (loopback only)
# --------------------------------------------------------------------------

def make_handler(engines, token):
    class Handler(BaseHTTPRequestHandler):
        server_version = "BankVoice/1.0"
        protocol_version = "HTTP/1.1"

        def log_message(self, fmt, *a):
            sys.stderr.write("[%s] %s\n" % (time.strftime("%H:%M:%S"), fmt % a))

        def send(self, code, body, ctype="application/json; charset=utf-8"):
            if isinstance(body, (dict, list)):
                body = json.dumps(body, ensure_ascii=False).encode("utf-8")
            self.send_response(code)
            self.send_header("Content-Type", ctype)
            self.send_header("Content-Length", str(len(body)))
            self.end_headers()
            self.wfile.write(body)

        def allowed(self):
            if token and self.headers.get("X-Voice-Token", "") != token:
                self.send(401, {"ok": False, "error": "bad token"})
                return False
            return True

        def body(self):
            n = int(self.headers.get("Content-Length") or 0)
            if n > MAX_AUDIO_BYTES:
                raise ValueError("request too large")
            return self.rfile.read(n) if n else b""

        def do_GET(self):
            if self.path.split("?")[0] == "/health":
                if self.allowed():
                    self.send(200, engines.status())
                return
            self.send(404, {"ok": False, "error": "not found"})

        def do_POST(self):
            path = self.path.split("?")[0]
            if path not in ("/stt", "/tts"):
                self.send(404, {"ok": False, "error": "not found"})
                return
            if not self.allowed():
                return
            try:
                data = self.body()
                if path == "/stt":
                    lang = "fa"
                    if "lang=" in self.path:
                        lang = self.path.split("lang=", 1)[1].split("&")[0][:5] or "fa"
                    self.send(200, engines.transcribe(data, lang))
                else:
                    req = json.loads(data.decode("utf-8") or "{}")
                    audio, ctype = engines.synthesize(req.get("text", ""), req.get("format", "ogg"))
                    self.send(200, audio, ctype)
            except ValueError as e:
                self.send(400, {"ok": False, "error": str(e)})
            except Exception as e:  # noqa: BLE001 - report anything to PHP as JSON
                self.log_message("error: %s", e)
                self.send(500, {"ok": False, "error": str(e)})

    return Handler


def cmd_serve(s):
    if s["host"] not in ("127.0.0.1", "localhost", "::1") and not env("VOICE_ALLOW_REMOTE"):
        sys.exit("refusing to listen on %s: this service is for the same server only "
                 "(set VOICE_ALLOW_REMOTE=1 if you really mean it)" % s["host"])
    engines = Engines(s)
    if s["preload"]:
        t0 = time.time()
        try:
            engines.stt_model()
            engines.tts_voice()
            sys.stderr.write("models loaded in %.1f s\n" % (time.time() - t0))
        except Exception as e:  # noqa: BLE001 - start anyway, /health shows what's missing
            sys.stderr.write("preload failed: %s\n" % e)
    httpd = ThreadingHTTPServer((s["host"], s["port"]), make_handler(engines, s["token"]))
    httpd.daemon_threads = True
    sys.stderr.write("bank voice service on http://%s:%d\n" % (s["host"], s["port"]))
    httpd.serve_forever()


# --------------------------------------------------------------------------
# One-time model download (the only step that needs the internet)
# --------------------------------------------------------------------------

def download_whisper(s, convert=False):
    from huggingface_hub import snapshot_download
    from faster_whisper.utils import _MODELS
    m = s["stt_model"]
    if os.path.isdir(m):
        print("STT model is a local folder:", m)
        return
    out = os.path.join(s["models_dir"], "whisper", m.replace("/", "--"))
    if os.path.isfile(os.path.join(out, "model.bin")):
        print("STT model already here:", out)
        return
    os.makedirs(out, exist_ok=True)
    if convert:
        # A Hugging Face Transformers checkpoint (e.g. a Persian fine-tune):
        # convert it once to CTranslate2 int8 for faster-whisper.
        try:
            from ctranslate2.converters import TransformersConverter
        except ImportError:
            sys.exit("conversion needs: pip install 'transformers[torch]'")
        print("converting", m, "->", out)
        TransformersConverter(m, copy_files=["tokenizer.json", "preprocessor_config.json"]).convert(
            out, quantization="int8", force=True)
        return
    repo = _MODELS.get(m, m)
    print("downloading", repo, "->", out)
    snapshot_download(repo, local_dir=out,
                      allow_patterns=["config.json", "preprocessor_config.json", "model.bin",
                                      "tokenizer.json", "vocabulary.*"])


def download_piper(s):
    v = s["tts_voice"]
    onnx, cfg = piper_files(s)
    if os.path.isfile(onnx) and os.path.isfile(cfg):
        print("Piper voice already here:", onnx)
        return
    try:
        code, name, quality = v.split("-", 2)     # fa_IR-gyro-medium
    except ValueError:
        sys.exit("TTS_VOICE should look like fa_IR-gyro-medium, or be a path to a .onnx file")
    os.makedirs(os.path.dirname(onnx), exist_ok=True)
    from urllib.request import urlopen
    for ext, dest in ((".onnx.json", cfg), (".onnx", onnx)):
        url = PIPER_URL.format(family=code.split("_")[0], code=code, name=name, quality=quality, ext=ext)
        print("downloading", url)
        tmp = dest + ".part"
        with urlopen(url, timeout=60) as r, open(tmp, "wb") as f:
            shutil.copyfileobj(r, f)
        os.replace(tmp, dest)


def cmd_download(s, convert=False):
    download_whisper(s, convert)
    download_piper(s)
    print("done. Models are in", s["models_dir"])


# --------------------------------------------------------------------------
# CLI
# --------------------------------------------------------------------------

def main(argv=None):
    ap = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = ap.add_subparsers(dest="cmd", required=True)

    def common(p):
        p.add_argument("--models-dir", dest="models_dir")
        p.add_argument("--stt-model", dest="stt_model")
        p.add_argument("--tts-voice", dest="tts_voice")
        return p

    p = common(sub.add_parser("serve", help="run the local HTTP service"))
    p.add_argument("--host")
    p.add_argument("--port", type=int)
    p = common(sub.add_parser("stt", help="transcribe one audio file, print JSON"))
    p.add_argument("file")
    p.add_argument("--lang", default="fa")
    p = common(sub.add_parser("tts", help="speak text from stdin (or --text) into --out"))
    p.add_argument("--out", required=True)
    p.add_argument("--format", choices=["ogg", "mp3", "wav"])
    p.add_argument("--text")
    p = common(sub.add_parser("download", help="fetch the models once (needs internet)"))
    p.add_argument("--convert", action="store_true",
                   help="STT model is a Transformers checkpoint: convert it to CTranslate2")
    common(sub.add_parser("status", help="show which models are present"))

    a = ap.parse_args(argv)
    s = settings(a)
    if a.cmd != "download":
        # Run time is offline: models are only read from disk, never fetched.
        os.environ["HF_HUB_OFFLINE"] = "1"
        os.environ["HF_HUB_DISABLE_TELEMETRY"] = "1"

    if a.cmd == "serve":
        cmd_serve(s)
    elif a.cmd == "download":
        cmd_download(s, a.convert)
    elif a.cmd == "status":
        print(json.dumps(Engines(s).status(), ensure_ascii=False))
    elif a.cmd == "stt":
        try:
            with open(a.file, "rb") as f:
                res = Engines(s).transcribe(f.read(), a.lang)
        except Exception as e:  # noqa: BLE001
            res = {"ok": False, "error": str(e)}
        print(json.dumps(res, ensure_ascii=False))
        return 0 if res["ok"] else 1
    elif a.cmd == "tts":
        text = a.text if a.text is not None else sys.stdin.read()
        fmt = a.format or os.path.splitext(a.out)[1].lstrip(".") or "ogg"
        audio, _ = Engines(s).synthesize(text, fmt)
        fd, tmp = tempfile.mkstemp(dir=os.path.dirname(os.path.abspath(a.out)))
        with os.fdopen(fd, "wb") as f:
            f.write(audio)
        os.replace(tmp, a.out)
    return 0


if __name__ == "__main__":
    sys.exit(main())
