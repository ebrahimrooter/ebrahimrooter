#!/usr/bin/env python3
"""
Self-test of the voice service without the big models:
  venv/bin/python test_voice_service.py
The HTTP server, audio decoding (PyAV), WAV->OGG/Opus and WAV->MP3 (ffmpeg)
are the real ones; only Whisper and Piper are replaced by small fakes.
"""

import json
import math
import os
import struct
import subprocess
import sys
import threading
import unittest
import urllib.request
import wave
import io
from http.server import ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import voice_service as vs  # noqa: E402


def tone_wav(seconds=1.0, rate=22050):
    buf = io.BytesIO()
    with wave.open(buf, "wb") as w:
        w.setnchannels(1)
        w.setsampwidth(2)
        w.setframerate(rate)
        w.writeframes(b"".join(struct.pack("<h", int(8000 * math.sin(2 * math.pi * 440 * i / rate)))
                               for i in range(int(seconds * rate))))
    return buf.getvalue()


class FakeSeg:
    def __init__(self, text):
        self.text = text


class FakeInfo:
    language = "fa"


class FakeWhisper:
    def __init__(self):
        self.calls = []

    def transcribe(self, audio, **kw):
        self.calls.append((len(audio), kw))
        return iter([FakeSeg(" حواله به علی رضایی "), FakeSeg("بابت خرید بذر")]), FakeInfo()


class FakePiper:
    def synthesize_wav(self, text, wf, syn_config=None):
        wf.setnchannels(1)
        wf.setsampwidth(2)
        wf.setframerate(22050)
        with wave.open(io.BytesIO(tone_wav(0.5)), "rb") as r:
            wf.writeframes(r.readframes(r.getnframes()))


class VoiceServiceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        s = vs.settings()
        s.update(port=0, token="secret", preload=False)
        cls.engines = vs.Engines(s)
        cls.whisper = FakeWhisper()
        cls.engines._stt = cls.whisper
        cls.engines._tts = FakePiper()
        cls.httpd = ThreadingHTTPServer(("127.0.0.1", 0), vs.make_handler(cls.engines, "secret"))
        cls.url = "http://127.0.0.1:%d" % cls.httpd.server_address[1]
        threading.Thread(target=cls.httpd.serve_forever, daemon=True).start()

    @classmethod
    def tearDownClass(cls):
        cls.httpd.shutdown()

    def req(self, path, data=None, token="secret", ctype="application/octet-stream"):
        r = urllib.request.Request(self.url + path, data=data, method="POST" if data is not None else "GET")
        if token:
            r.add_header("X-Voice-Token", token)
        r.add_header("Content-Type", ctype)
        try:
            with urllib.request.urlopen(r, timeout=30) as resp:
                return resp.status, resp.headers.get("Content-Type"), resp.read()
        except urllib.error.HTTPError as e:
            return e.code, e.headers.get("Content-Type"), e.read()

    def test_health(self):
        code, _, body = self.req("/health")
        self.assertEqual(code, 200)
        self.assertTrue(json.loads(body)["stt_loaded"])

    def test_token_required(self):
        code, _, _ = self.req("/health", token="wrong")
        self.assertEqual(code, 401)

    def test_stt_bale_voice_ogg_opus(self):
        # What Bale sends: OGG/Opus voice note. Decoded by PyAV inside faster-whisper.
        ogg = vs.run_ffmpeg(["-f", "wav", "-i", "pipe:0", "-c:a", "libopus", "-f", "ogg", "pipe:1"], tone_wav(2))
        code, _, body = self.req("/stt", ogg)
        self.assertEqual(code, 200, body)
        d = json.loads(body)
        self.assertEqual(d["text"], "حواله به علی رضایی بابت خرید بذر")
        n, kw = self.whisper.calls[-1]
        self.assertAlmostEqual(n / 16000, 2, delta=0.1)          # resampled to 16 kHz mono
        self.assertEqual(kw["language"], "fa")
        self.assertTrue(kw["vad_filter"])

    def test_stt_browser_recordings(self):
        # iPhone Safari records audio/mp4 (AAC), Chrome records audio/webm (Opus).
        import tempfile
        for ext, codec in (("m4a", "aac"), ("webm", "libopus")):
            with tempfile.NamedTemporaryFile(suffix="." + ext) as f:
                subprocess.run([vs.ffmpeg_bin(), "-v", "error", "-y", "-f", "wav", "-i", "pipe:0",
                                "-c:a", codec, f.name], input=tone_wav(1), check=True)
                code, _, body = self.req("/stt", open(f.name, "rb").read())
            self.assertEqual(code, 200, (ext, body))

    def test_stt_garbage(self):
        code, _, body = self.req("/stt", b"not audio at all")
        self.assertIn(code, (400, 500))
        self.assertFalse(json.loads(body)["ok"])

    def test_tts_ogg_for_bale(self):
        code, ctype, body = self.req("/tts", json.dumps({"text": "ثبت شد", "format": "ogg"}).encode(),
                                     ctype="application/json")
        self.assertEqual(code, 200)
        self.assertEqual(ctype, "audio/ogg")
        self.assertEqual(body[:4], b"OggS")
        probe = subprocess.run(["ffprobe", "-v", "error", "-show_entries", "stream=codec_name",
                                "-of", "csv=p=0", "-"], input=body, capture_output=True)
        self.assertEqual(probe.stdout.decode().strip(), "opus")

    def test_tts_mp3_and_wav(self):
        code, ctype, body = self.req("/tts", json.dumps({"text": "سلام", "format": "mp3"}).encode())
        self.assertEqual((code, ctype), (200, "audio/mpeg"))
        code, ctype, body = self.req("/tts", json.dumps({"text": "سلام", "format": "wav"}).encode())
        self.assertEqual((code, ctype, body[:4]), (200, "audio/wav", b"RIFF"))

    def test_tts_bad_input(self):
        self.assertEqual(self.req("/tts", json.dumps({"text": ""}).encode())[0], 400)
        self.assertEqual(self.req("/tts", json.dumps({"text": "x", "format": "flac"}).encode())[0], 400)

    def test_refuses_public_bind(self):
        s = vs.settings()
        s["host"] = "0.0.0.0"
        with self.assertRaises(SystemExit):
            vs.cmd_serve(s)


if __name__ == "__main__":
    unittest.main(verbosity=2)
