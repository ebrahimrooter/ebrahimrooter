"""Persian speech-to-text on the phone: which Shenava model is good enough?
Runs in CI (needs huggingface.co). For each model: real people (FLEURS fa_ir test clips)
and the app's own kind of sentences (spoken by the Piper voice), CER/WER and speed.
   python3 tools/voice-model/eval_stt.py WORKDIR
"""
import glob, io, json, os, re, sys, tarfile, time, urllib.request, wave
import numpy as np
import sherpa_onnx

W = sys.argv[1]
os.makedirs(W, exist_ok=True)
HF = "https://huggingface.co"
MODELS = ["Reza2kn/Shenava-Rizeh-Pizeh-v1.0-sherpa-onnx", "Reza2kn/Shenava-Rizeh-v1.0-sherpa-onnx", "Reza2kn/Shenava-Koochik-v1.0-sherpa-onnx"]
SENTENCES = ["فروش امروز چقدر بوده", "موجودی کارت ملت چقدره", "بابت خرید لوازم اداری بود", "پانصد هزار تومان به علی دادم",
             "خلاصه وضعیت این ماه رو بگو", "این واریز بابت فروش لپ تاپ بود", "نادیده بگیر", "بله ثبت کن", "موجودی انبار برنج چقدره",
             "یک فاکتور فروش برای آقای محمدی ثبت کن"]


def get(url, path):
    if not os.path.exists(path):
        os.makedirs(os.path.dirname(path), exist_ok=True)
        urllib.request.urlretrieve(url, path + ".part")
        os.rename(path + ".part", path)
    return path


def norm(s):
    s = s.replace("ي", "ی").replace("ك", "ک").replace("‌", " ")
    s = re.sub(r"[^\w\s]", " ", s)
    return re.sub(r"\s+", " ", s).strip()


def ed(a, b):
    d = list(range(len(b) + 1))
    for i in range(1, len(a) + 1):
        p, d[0] = d[0], i
        for j in range(1, len(b) + 1):
            p, d[j] = d[j], min(d[j] + 1, d[j - 1] + 1, p + (a[i - 1] != b[j - 1]))
    return d[len(b)]


def score(pairs):
    c = sum(ed(norm(r), norm(h)) for r, h in pairs) / max(1, sum(len(norm(r)) for r, _ in pairs))
    w = sum(ed(norm(r).split(), norm(h).split()) for r, h in pairs) / max(1, sum(len(norm(r).split()) for r, _ in pairs))
    return round(100 * c, 1), round(100 * w, 1)


def model_files(repo):
    info = json.load(urllib.request.urlopen(f"{HF}/api/models/{repo}"))
    files = [s["rfilename"] for s in info.get("siblings", [])]
    d = os.path.join(W, repo.split("/")[1])
    for f in files:
        if f.endswith((".onnx", ".txt", ".json", ".md")) or "tokens" in f:
            get(f"{HF}/{repo}/resolve/main/{f}", os.path.join(d, f))
    return d, files


def recognizer(d):
    onnx = sorted(glob.glob(d + "/**/*.onnx", recursive=True))
    tokens = (glob.glob(d + "/**/tokens.txt", recursive=True) or [None])[0]
    enc = [f for f in onnx if "encoder" in f]
    if enc:
        pick = lambda k: sorted([f for f in onnx if k in f], key=lambda f: ("int8" not in f, f))[0]
        return sherpa_onnx.OfflineRecognizer.from_transducer(encoder=pick("encoder"), decoder=pick("decoder"), joiner=pick("joiner"),
                                                             tokens=tokens, num_threads=2, model_type="nemo_transducer"), pick("encoder")
    m = sorted(onnx, key=lambda f: ("int8" not in f, os.path.getsize(f)))[0]
    return sherpa_onnx.OfflineRecognizer.from_nemo_ctc(model=m, tokens=tokens, num_threads=2), m


def run(rec, samples, sr):
    s = rec.create_stream()
    s.accept_waveform(sr, samples)
    rec.decode_stream(s)
    return s.result.text


# real people: FLEURS fa_ir test
fl = os.path.join(W, "fleurs")
tsv = get(f"{HF}/datasets/google/fleurs/resolve/main/data/fa_ir/test.tsv", fl + "/test.tsv")
rows = [l.rstrip("\n").split("\t") for l in open(tsv, encoding="utf-8")][:40]
want = {r[1]: r[2] for r in rows}
tgz = get(f"{HF}/datasets/google/fleurs/resolve/main/data/fa_ir/audio/test.tar.gz", fl + "/test.tar.gz")
clips = {}
with tarfile.open(tgz) as t:
    for m in t:
        n = os.path.basename(m.name)
        if n in want:
            with wave.open(io.BytesIO(t.extractfile(m).read())) as w:
                a = np.frombuffer(w.readframes(w.getnframes()), dtype=np.int16).astype(np.float32) / 32768
                clips[n] = (a, w.getframerate())
print("fleurs clips:", len(clips))

# the app's sentences in the Piper voice
vd = os.environ.get("FA_VOICE", "android/app/src/main/assets/fa-voice")
tts = sherpa_onnx.OfflineTts(sherpa_onnx.OfflineTtsConfig(model=sherpa_onnx.OfflineTtsModelConfig(vits=sherpa_onnx.OfflineTtsVitsModelConfig(
    model=vd + "/fa_IR-amir-medium.onnx", tokens=vd + "/tokens.txt", data_dir=vd + "/espeak-ng-data"), num_threads=2)))
spoken = []
for s in SENTENCES:
    a = tts.generate(s, sid=0, speed=1.0)
    spoken.append((s, np.array(a.samples, dtype=np.float32), a.sample_rate))

report = []
for repo in MODELS:
    try:
        d, files = model_files(repo)
        rec, used = recognizer(d)
    except Exception as e:  # noqa
        report.append(f"{repo}: could not load ({e})")
        continue
    t = time.time(); dur = 0
    real = []
    for n, (a, sr) in clips.items():
        real.append((want[n], run(rec, a, sr))); dur += len(a) / sr
    rtf = (time.time() - t) / max(dur, 1)
    app = [(s, run(rec, a, sr)) for s, a, sr in spoken]
    size = sum(os.path.getsize(f) for f in glob.glob(d + "/**/*.onnx", recursive=True)) // 1000000
    report.append(f"{repo}  [{os.path.basename(used)}; onnx total {size} MB; files {files}]\n"
                  f"  FLEURS (real people, {len(real)} clips): CER {score(real)[0]}%  WER {score(real)[1]}%  RTF {rtf:.3f}\n"
                  f"  app sentences (Piper voice): CER {score(app)[0]}%  WER {score(app)[1]}%")
    for r, h in real[:4] + app:
        report.append(f"    want: {r}\n    got:  {h}")
out = "\n".join(report)
print(out)
open(os.path.join(W, "stt-report.txt"), "w", encoding="utf-8").write(out)
