# صدای محلی دستیار (STT + TTS روی همین سرور)

این پوشه تبدیل **صدا به متن** و **متن به صدا** را کاملاً روی سرور خودت انجام می‌دهد؛ بدون OpenAI، Google، Azure، ElevenLabs یا هر سرویس ابری دیگر، و بدون API Key.

```
 بله: ویس کاربر (OGG/Opus)
      │  webhook
      ▼
 PHP (bot.php) ── دانلود ویس از بله
      │  http://127.0.0.1:8765/stt   (فقط داخل سرور)
      ▼
 voice_service.py ── faster-whisper (large-v3-turbo، int8، CPU) ──► متن فارسی
      │
      ▼
 PHP: همان منطق قبلی (ba_interpret، پیش‌نویس، ثبت…) ──► پاسخ متنی + دکمه‌ها در بله
      │  http://127.0.0.1:8765/tts
      ▼
 voice_service.py ── Piper (fa_IR-gyro-medium) ──► WAV ──ffmpeg──► OGG/Opus
      │
      ▼
 PHP: sendVoice ──► ویس پاسخ در بله
```

اینترنت فقط **یک بار** موقع نصب لازم است (apt، pip، دانلود مدل‌ها). بعد از آن سرویس آفلاین کار می‌کند: `HF_HUB_OFFLINE=1` است و systemd هم اجازه‌ی وصل شدن به هیچ آدرسی جز localhost را به آن نمی‌دهد. PHP هم `voice_url` غیر از `127.0.0.1`/`localhost` را رد می‌کند تا صدا هرگز از سرور بیرون نرود.

## چه انتخاب شد و چرا

### STT: faster-whisper با مدل `large-v3-turbo`
- **faster-whisper** همان Whisper است روی CTranslate2: روی CPU حدود ۴ برابر سریع‌تر از Whisper اصلی و با int8 حافظه‌ی خیلی کمتر.
- **large-v3-turbo** (نسخه‌ی CTranslate2: `mobiuslabsgmbh/faster-whisper-large-v3-turbo`): فارسی‌اش نزدیک large-v3 است ولی چند برابر سریع‌تر. روی یک VPS معمولی ۴ هسته‌ای، یک ویس ۵ تا ۱۰ ثانیه‌ای معمولاً در چند ثانیه به متن تبدیل می‌شود.
- برای بهتر شدن املای کلمه‌های بانکی، یک متن راهنما (واریز، برداشت، حواله، بابت، تومان…) به مدل داده می‌شود و VAD سکوت‌ها را حذف می‌کند.
- **مدل‌های فارسیِ اختصاصی** (مثل `vhdm/whisper-large-fa-v1` که روی large-v3-turbo با داده‌ی فارسی fine-tune شده و WER ≈ ۱۴٪ روی داده‌ی تمیز گزارش کرده) بررسی شدند. پیش‌فرض نشدند چون عددشان روی داده‌ی آزمون خودشان است (نه یک معیار مستقل)، یک نفر نگهشان می‌دارد و fine-tuneهای کوچک روی صدای پرنویز ویس موبایل گاهی از مدل عمومی بدتر می‌شوند. ولی پشتیبانی می‌شوند؛ اگر روی ویس‌های خودت بهتر بود:
  ```
  sudo ./install.sh --stt-model vhdm/whisper-large-fa-v1 --convert
  ```
  (`--convert` یک بار مدل را به CTranslate2 int8 تبدیل می‌کند؛ موقع نصب PyTorch هم دانلود می‌شود.)
- سرور کوچک (۲ گیگ رم): `--stt-model small` (فارسی ضعیف‌تر) یا swap اضافه کن.

| مدل | دیسک | رم تقریبی | فارسی | سرعت روی CPU |
|---|---|---|---|---|
| `large-v3-turbo` (پیش‌فرض) | ۱٫۶ GB | ~۲–۲٫۵ GB | خوب | خوب |
| `large-v3` | ۳ GB | ~۴ GB | کمی بهتر | ~۳ برابر کندتر |
| `medium` | ۱٫۵ GB | ~۱٫۵ GB | متوسط | متوسط |
| `small` | ۵۰۰ MB | ~۱ GB | ضعیف | سریع |

### TTS: Piper با صدای `fa_IR-gyro-medium`
- **Piper** سبک است (ONNX، فقط CPU، بدون GPU)، روی هر لینوکسی اجرا می‌شود و چند صدای **فارسی واقعی** دارد. espeak-ng (برای تلفظ فارسی) داخل خود بسته‌ی pip است.
- صداهای فارسی: `fa_IR-gyro-medium` (پیش‌فرض)، `fa_IR-amir-medium`، `fa_IR-ganji-medium`، `fa_IR-ganji_adabi-medium`، `fa_IR-reza_ibrahim-medium`. نمونه‌هایشان را در [rhasspy/piper-voices](https://huggingface.co/rhasspy/piper-voices/tree/main/fa/fa_IR) گوش کن و با `--tts-voice` عوض کن.
- PHP قبل از خواندن، متن را «قابل گفتن» می‌کند: «۴,۰۰۰,۰۰۰ تومان» ← «چهار میلیون تومان»، «1405/07/06» ← «شش مهر هزار و چهارصد و پنج»، «18:40» ← «ساعت هجده و چهل دقیقه»، و ایموجی و فرمان‌ها (/pending) حذف می‌شوند.
- خروجی: OGG/Opus برای ویس بله، MP3 برای اپ گوشی، یا WAV.

## پیش‌نیازها

یک **VPS/سرور لینوکسی** (Ubuntu 22.04/24.04 یا Debian 12) با دسترسی root. هاست اشتراکی cPanel معمولاً اجازه‌ی اجرای Python و مدل را نمی‌دهد؛ اگر برنامه روی هاست اشتراکی است، این سرویس باید روی یک VPS باشد و PHP هم روی همان VPS (چون ارتباط فقط از `127.0.0.1` مجاز است).

| چیز | از کجا | توضیح |
|---|---|---|
| Python ۳٫۹+ و venv | apt | خود install.sh نصب می‌کند |
| ffmpeg | apt | ساخت OGG/Opus و MP3 |
| faster-whisper، piper-tts | pip (داخل venv) | `requirements.txt` |
| مدل Whisper | Hugging Face، یک بار | ~۱٫۶ GB برای turbo |
| صدای Piper | Hugging Face، یک بار | ~۶۰ MB |
| PHP ۷٫۴+ با curl | همان قبلی | |

حداقل سخت‌افزار پیشنهادی: ۲ هسته و ۴ گیگ رم (با turbo). ۴ هسته سریع‌تر جواب می‌دهد. GPU لازم نیست.

## نصب

```bash
# روی VPS، کنار برنامه (مثلاً /var/www/bank همان محتوای پوشه‌ی server است)
cd bank-assistant/voice
sudo ./install.sh --php-config /var/www/bank/config.php
```

install.sh این کارها را می‌کند:
1. ffmpeg، python3-venv را با apt نصب می‌کند.
2. برنامه را در `/opt/bank-voice` می‌گذارد و یک کاربر سیستمی `bankvoice` می‌سازد.
3. venv و پکیج‌ها را نصب می‌کند و **یک بار** مدل‌ها را در `/opt/bank-voice/models` دانلود می‌کند.
4. سرویس systemd `bank-voice` را روی `127.0.0.1:8765` با یک توکن تصادفی راه می‌اندازد (مدل‌ها یک بار در حافظه بار می‌شوند).
5. `voice_url` و `voice_token` را در `config.php` می‌نویسد (از فایل قبلی پشتیبان می‌گیرد).

بعد تست کن:
```bash
php /var/www/bank/cron.php voice-test
```
یک جمله را با Piper می‌سازد و دوباره با Whisper به متن برمی‌گرداند. حالا در بله به ربات ویس بفرست: متن را نشان می‌دهد، پیش‌نویس را می‌سازد و **جواب را هم به‌صورت ویس** می‌فرستد. (برای خاموش کردن ویس جواب: `'bale_voice_reply' => false`.)

### سرور بدون دسترسی به Hugging Face
روی یک سیستم دیگر که اینترنت دارد:
```bash
python3 -m venv v && v/bin/pip install -r requirements.txt
v/bin/python voice_service.py download --models-dir ./models
```
پوشه‌ی `models` را به سرور کپی کن و:
```bash
sudo ./install.sh --models-from ./models --php-config /var/www/bank/config.php
```
(pip هم اگر مسدود است، از `pip download -r requirements.txt -d wheels` روی همان سیستم و `pip install --no-index -f wheels` استفاده کن؛ معماری و نسخه‌ی پایتون دو سیستم باید یکی باشد.)

### بدون systemd (CLI)
`sudo ./install.sh --no-service` در `config.php` به‌جای `voice_url` این را می‌گذارد:
```php
'voice_cli' => '/opt/bank-voice/venv/bin/python /opt/bank-voice/voice_service.py',
```
PHP برای هر ویس برنامه را اجرا می‌کند (`proc_open`). کار می‌کند ولی هر بار مدل از نو بار می‌شود (۵ تا ۲۰ ثانیه)، پس سرویس پیشنهاد می‌شود.

## تنظیمات و نگهداری

- تنظیمات سرویس: `/opt/bank-voice/voice.env` (مدل، صدا، تعداد هسته، سرعت گفتار) ← بعد `sudo systemctl restart bank-voice`.
- عوض کردن مدل/صدا: دوباره `sudo ./install.sh --stt-model … --tts-voice …` (فقط مدل جدید دانلود می‌شود). بعد از عوض کردن صدا، `server/data/tts/` را خالی کن تا جمله‌های ذخیره‌شده با صدای قبلی دوباره استفاده نشوند.
- وضعیت: `systemctl status bank-voice`، لاگ: `journalctl -u bank-voice -f`، سلامت: `curl -H "X-Voice-Token: …" http://127.0.0.1:8765/health`.
- صداهای ساخته‌شده در `server/data/tts` نگه داشته می‌شوند (جمله‌های تکراری دوباره ساخته نمی‌شوند) و بعد از ۳۰ روز استفاده‌نشدن پاک می‌شوند.
- تست خودِ سرویس بدون مدل‌ها: `/opt/bank-voice/venv/bin/python /opt/bank-voice/test_voice_service.py`

## API داخلی (فقط 127.0.0.1)

| مسیر | ورودی | خروجی |
|---|---|---|
| `GET /health` | — | `{"ok":true,"stt_ready":…,"tts_ready":…}` |
| `POST /stt?lang=fa` | بدنه = فایل صدا (ogg، m4a، webm، wav، mp3) | `{"ok":true,"text":"…","duration":…,"seconds":…}` |
| `POST /tts` | `{"text":"…","format":"ogg|mp3|wav"}` | فایل صدا |

همه با هدر `X-Voice-Token`. حداکثر ۲۵ مگابایت / ۳ دقیقه صدا و ۱۵۰۰ کاراکتر متن.
