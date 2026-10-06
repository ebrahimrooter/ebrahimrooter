# دستیار صوتی حسابداری برای آیفون — گزارش امکان‌سنجی و راهنمای فنی

## ۱. گزارش امکان‌سنجی (پیش از کد)

### سؤال
> آیا iOS اجازه می‌دهد یک Voice Assistant سفارشی، بدون نمایش صفحه‌ی حسابداری پشت آن، به صورت پنجره‌ی شناور روی Home Screen باقی بماند؟

### جواب: **خیر** — نه برای وب‌اپ (PWA) و نه برای اپ Native، با هیچ API رسمی.

**چرا:**

| قابلیت iOS | چه چیزی را روی Home Screen نشان می‌دهد | برای دستیار صوتی مجاز است؟ |
|---|---|---|
| **View شناور دلخواه (overlay)** | — | iOS هیچ API عمومی برای کشیدن View روی Home Screen یا اپ‌های دیگر ندارد (معادل `SYSTEM_ALERT_WINDOW` اندروید وجود ندارد). |
| **AVPictureInPictureController** (پخش ویدیو) | پنجره‌ی PiP با گوشه‌ی گرد، قابل جابه‌جایی | فقط برای **پخش ویدیو/مدیا**. با `AVSampleBufferDisplayLayer` می‌شود هر تصویری را «ویدیو» کرد، اما این دقیقاً همان «PiP جعلی» است: ساختن ویدیوی ساختگی از یک UI برای اینکه شناور بماند. با بند 2.5.4 (استفاده از سرویس پس‌زمینه فقط برای هدف خودش) و روح بند 4.2/2.3 در تضاد است و خطر Reject جدی دارد. **استفاده نشد.** |
| **AVPictureInPictureVideoCallViewController** (iOS 15+) | PiP با View دلخواه | فقط برای **اپ‌های تماس تصویری واقعی** (ادامه‌ی تماس ویدیویی وقتی اپ بسته می‌شود). دستیار صوتی تماس تصویری نیست. **استفاده نشد.** |
| **CallKit** | رابط تماس سیستم | فقط برای **تماس VoIP واقعی**؛ جا زدن دستیار به‌جای تماس تلفنی نقض قوانین است (و در برخی کشورها اصلاً مجاز نیست). **استفاده نشد.** |
| **Live Activities + Dynamic Island** (ActivityKit) | وضعیت زنده روی **صفحه‌ی قفل**، در **Dynamic Island** (iPhone 14 Pro و جدیدتر، که روی Home Screen هم دیده می‌شود)، و بنر | **بله** — کاربرد رسمی‌اش دقیقاً همین است: «کاری که الان در جریان است». محدودیت: UI ثابت و کوچک، بدون انیمیشن آزاد، قابل جابه‌جایی نیست؛ روی آیفون‌های بدون Dynamic Island روی Home Screen چیزی نمی‌ماند (فقط صفحه‌ی قفل و بنر). |
| **Background Audio** (`UIBackgroundModes: audio`) | — | **بله**، تا وقتی جلسه‌ی صوتی واقعاً در جریان است (ضبط/پخش). اجازه می‌دهد بعد از رفتن به Home Screen یا قفل گوشی، شنیدن و جواب دادن ادامه پیدا کند. |
| **App Intents / App Shortcuts / Siri / Action button / Control Center** (iOS 17–18) | Siri و Control Center | **بله** برای **شروع** دستیار از هر جای گوشی. چون میکروفون فقط از Foreground شروع می‌شود، اپ باز می‌شود. Siri فارسی صحبت نمی‌کند؛ عبارت انگلیسی است و گفتگو فارسی. |
| **Push Notifications** | بنر | **بله** برای خبر دادن (مثلاً تراکنش جدید)؛ با زدن نوتیف اپ باز می‌شود. |

### نزدیک‌ترین تجربه‌ی رسمی و قابل انتشار — همان که پیاده شد
1. کاربر در اپ (یا از PWA با یک لمس، یا با Siri / دکمه‌ی Action / Control Center) دستیار را شروع می‌کند → پنجره‌ی شناور تیره با انیمیشن صوتی در مرکز و وضعیت («در حال گوش دادن…»، «در حال پردازش…»، «در حال پاسخ دادن…»).
2. کاربر به **Home Screen واقعی** برمی‌گردد → جلسه با Background Audio ادامه دارد و وضعیتش در **Dynamic Island** (و صفحه‌ی قفل) زنده نمایش داده می‌شود؛ دکمه‌های «🎙 بپرس» و «✕ پایان» روی Live Activity بدون باز شدن اپ کار می‌کنند (iOS 17).
3. ضربه روی Dynamic Island / Live Activity → اپ با رابط کامل دستیار باز می‌شود.

چیزی که **ممکن نیست** و ساخته نشد: پنجره‌ی دلخواه، قابل جابه‌جایی، با انیمیشن آزاد روی Home Screen. جابه‌جایی پنجره فقط داخل خود اپ است.

---

## ۲. معماری

```
 iPhone PWA (server/app)                    iPhone Native Companion (ios/)
  • واریز/برداشت، orb وب                     • پنجره‌ی شناور دستیار (SwiftUI)
  • «اتصال اپ آیفون» ← کد یک‌بارمصرف          • ضبط صدا + VAD (AVAudioEngine)
  • bankassistant://listen ───────────────►  • Live Activity / Dynamic Island (ActivityKit)
                                              • App Intents: Siri, Action, Control Center
           │ X-App-Token                              │ Bearer <device token> (Keychain)
           ▼                                          ▼
                      server/api.php  (Web API، HTTPS)
                       ├─ assistant_pair / devices / revoke   (با رمز اپ)
                       └─ assistant_redeem / me / ask / transcribe / speak  (با توکن دستگاه)
                                         │
                ┌────────────────────────┼─────────────────────────┐
                ▼                        ▼                         ▼
     server/assistant.php        Accounting backend          voice/ (روی همین سرور)
     فهم جمله‌ی فارسی و          acc_*.php (فاکتور، انبار،     faster-whisper (STT)
     پیش‌نویس فاکتور             گزارش، اشخاص)                Piper (TTS فارسی)
```

- رابط اصلی حسابداری همان وب است؛ اپ Native فقط **دستیار صوتی + قابلیت‌های سیستمی** است.
- هیچ سرویس اینترنتی بیرونی صدا را نمی‌شنود: تبدیل گفتار به متن و متن به گفتار روی سرور خودت است (iOS صدای فارسی برای TTS ندارد، پس TTS از سرور می‌آید).
- «هوش» دستیار فعلاً قاعده‌محور و فارسی است (`server/assistant.php`)؛ برای وصل کردن یک مدل زبانی کافی است `assistant_answer()` را عوض کنی — قرارداد API تغییر نمی‌کند.

## ۳. ساختار فایل‌ها

```
ios/
  project.yml                         XcodeGen (iOS 17، دو target)
  BankAssistant/                      اپ
    App/BankAssistantApp.swift        نقطه‌ی شروع، Deep Link، چرخه‌ی عمر
    App/DeepLinks.swift               bankassistant:// و Universal Link
    Services/APIClient.swift          قرارداد API، توکن در Keychain
    Services/KeychainStore.swift
    Services/AudioCapture.swift       میکروفون ← WAV 16kHz + تشخیص شروع/پایان صحبت
    Services/SpeechPlayer.swift       پخش پاسخ (mp3 از سرور) + سطح صدا برای انیمیشن
    Services/AssistantEngine.swift    ماشین حالت: Idle/Listening/Processing/Speaking/Error
    Services/LiveActivityController.swift
    Services/Permissions.swift        اجازه‌ی میکروفون
    Views/AssistantView.swift         پنجره‌ی شناور تیره، قابل جابه‌جایی، تاریخچه، تنظیمات
    Views/VoiceOrbView.swift          انیمیشن صوتی (Canvas)
    Views/PairingView.swift           اتصال با کد یک‌بارمصرف
    Intents/AppShortcuts.swift        Siri / Spotlight / Action button
    Resources/Info.plist, BankAssistant.entitlements, PrivacyInfo.xcprivacy
  Shared/                             مشترک اپ و ویجت
    AssistantActivity.swift           Attributes لایو اکتیویتی، حالت‌ها، دکمه‌های Stop/Talk
    StartAssistantIntent.swift
  AssistantWidgets/                   Widget Extension
    AssistantWidgets.swift            Live Activity، Dynamic Island، Control (iOS 18)
server/
  assistant.php                       جفت‌سازی، توکن دستگاه، فهم فرمان‌ها
  api.php                             مسیرهای assistant_*
  assistant/index.html                صفحه‌ی Universal Link وقتی اپ نصب نیست
  tests/assistant_test.php            تست
```

## ۴. قرارداد API (Web ↔ Native) — `H`

همه `POST` به `https://DOMAIN/bank/api.php?r=<route>`، بدنه JSON (مگر transcribe)، پاسخ JSON با `ok`.
خطا: `{"ok":false,"error":"…"}` با کد HTTP (401 = دستگاه وصل نیست/لغو شده).

| route | احراز هویت | ورودی | خروجی |
|---|---|---|---|
| `assistant_pair` | `X-App-Token` (رمز اپ وب) | — | `code` (۸ حرف، ۵ دقیقه، یک‌بار)، `server`، `link` (`bankassistant://pair?...`)، `universal_link` |
| `assistant_devices` (GET) | `X-App-Token` | — | `items[]`: id, name, created_at, last_seen, revoked |
| `assistant_revoke` | `X-App-Token` | `id` | `ok` |
| `assistant_redeem` | — (کد یک‌بارمصرف؛ ۱۰ تلاش غلط = ۱۵ دقیقه قفل برای IP) | `code`, `device_name` | `token` (۶۴ هگز، فقط همین یک بار)، `device_id`, `name` |
| `assistant_me` | `Authorization: Bearer <token>` | — | `device`, `voice` (صدای سرور نصب هست؟) |
| `assistant_ask` | Bearer | `text` | `heard`, `reply`, `state` (`answered` / `confirm` / `unknown` / `error`), `data` |
| `assistant_transcribe` | Bearer | multipart: `audio` (WAV/m4a، حداکثر ۵MB) | `text` |
| `assistant_speak` | Bearer | `text` | `audio/mpeg` |

نمونه:
```
POST /bank/api.php?r=assistant_ask
Authorization: Bearer 3f…a9
{"text":"برای علی رضایی فاکتور ثبت کن، سه عدد بذر گوجه"}

{"ok":true,"heard":"…","state":"confirm",
 "reply":"فاکتور فروش برای علی رضایی: 3 عدد بذر گوجه. جمع با مالیات 49,500 تومان. ثبت کنم؟",
 "data":{"person":"علی رضایی","items":["3 عدد بذر گوجه"],"total":495000}}

{"text":"آره"}  →  {"state":"answered","reply":"فاکتور SF-0002 ثبت شد. جمع 49,500 تومان."}
```

فرمان‌هایی که الان می‌فهمد (`I`) — همین‌ها در orb اپ موبایل وب هم کار می‌کنند (`assistant_web`):
- **پرسیدنی:** «خلاصه وضعیت»، «فروش امروز / دیروز / این هفته / این ماه / ماه قبل / امسال»، «گزارش فروش این ماه» (پرفروش‌ترین کالاها)، «بهترین مشتری‌ها»، «خرید این ماه»، «سود این ماه»، «موجودی انبار» / «موجودی بذر گوجه» / «کم‌موجودها»، «موجودی بانک و صندوق»، «حساب علی رضایی»، «طلب‌ها و بدهی‌ها»، «چک‌های امروز / این هفته / این ماه»، «فاکتورهای سررسید گذشته»، «مالیات ارزش افزوده»، «اقساط وام»، «آخرین فاکتورها».
- **ثبتی (اول می‌پرسد «ثبت کنم؟»):** «برای علی رضایی فاکتور (خرید) ثبت کن، دو عدد بذر گوجه»، «از علی رضایی پنج میلیون تومان نقد گرفتم»، «به پخش البرز سه میلیون از بانک دادم»، «دو میلیون و پانصد هزار تومان اجاره دادم» (نوع هزینه از اسمش)، «مشتری جدید به اسم … با شماره 0912… اضافه کن»، «به علی رضایی پیامک یادآوری بفرست».
- **تراکنش بانکی بی‌جواب:** «تراکنش‌های بی‌جواب» ← می‌پرسد «برداشت … بابت چی بود؟» ← جمله‌ی بعدی‌ات جوابش ثبت می‌شود.

## ۵. امنیت

- اپ Native **هیچ رمز یا Secret داخل کد ندارد**. اتصال با کد یک‌بارمصرف ۵ دقیقه‌ای که از داخل PWA (که رمز اپ را دارد) گرفته می‌شود.
- توکن دستگاه ۲۵۶ بیتی، فقط در **Keychain** با `kSecAttrAccessibleAfterFirstUnlockThisDeviceOnly` (به بکاپ iCloud نمی‌رود). روی سرور فقط SHA-256 آن ذخیره می‌شود.
- هر گوشی جدا لغو می‌شود (تنظیمات PWA ← «اپ دستیار آیفون» ← لغو دسترسی).
- `URLSession` از نوع `ephemeral`: هیچ پاسخ حسابداری روی دیسک کش نمی‌شود؛ چیزی در UserDefaults جز آدرس سرور نیست. فایل صدای موقت بعد از ارسال پاک می‌شود.
- فقط HTTPS (ATS پیش‌فرض iOS).
- PWA توکن اپ را مثل قبل نگه می‌دارد؛ اطلاعات حسابداری در localStorage ذخیره نمی‌شود.

## ۶. چرخه‌ی عمر (`10`)

| وضعیت | چه می‌شود |
|---|---|
| **Foreground** | پنجره‌ی کامل؛ ضربه روی انیمیشن = شروع / دوباره پرسیدن / توقف. |
| **رفتن به Home Screen در وسط جلسه** | جلسه ادامه دارد (Background Audio)؛ وضعیت در Dynamic Island و صفحه‌ی قفل. نشانگر نارنجی میکروفون iOS هم روشن است. |
| **Background ولی جلسه بیکار** | اپ جلسه را می‌بندد (`scenePhase == .background` و Idle/Error) تا بی‌دلیل صدا را نگه ندارد؛ Live Activity تمام می‌شود. دو بار سکوت پشت سر هم هم جلسه را می‌بندد. |
| **قفل شدن گوشی** | مثل Background؛ Live Activity روی صفحه‌ی قفل با دکمه‌های «بپرس» و «پایان». |
| **تماس تلفنی** | `AVAudioSession.interruptionNotification (began)` → ضبط و پخش متوقف، جلسه بسته، متن «به‌خاطر تماس متوقف شد». بعد از تماس خودکار میکروفون روشن **نمی‌شود** (حریم خصوصی)؛ کاربر دوباره می‌زند. |
| **رد اجازه‌ی میکروفون** | حالت Error با دکمه‌ی «باز کردن تنظیمات میکروفون». |
| **لغو دسترسی دستگاه روی سرور** | پاسخ 401 → توکن از Keychain پاک و صفحه‌ی اتصال نمایش داده می‌شود. |
| **قطع اینترنت / سرور** | Error با متن واضح؛ میکروفون در حلقه باز نمی‌ماند. |
| **صدای سرور نصب نیست** | پاسخ به‌صورت متن نمایش داده می‌شود (زمان خواندن)، بدون پخش. |

## ۷. حداقل iOS: **17.0** (`11`)
- دکمه‌های تعاملی روی Live Activity (`LiveActivityIntent`، App Intents در ویجت) از iOS 17.
- `AVAudioApplication.requestRecordPermission`، `@Observable`، `ContentUnavailableView` از iOS 17.
- Control Center control با `#available(iOS 18)` اضافه می‌شود و روی 17 فقط نیست.
- Dynamic Island فقط iPhone 14 Pro / 15 / 16 به بعد؛ بقیه Live Activity را روی صفحه‌ی قفل می‌بینند.

## ۸. Deep Link و Universal Link (`L`, `9`)

- **PWA → اپ:** `bankassistant://listen` (دکمه‌ی «🎙 دستیار حسابداری» در خانه‌ی PWA و «باز کردن دستیار» در تنظیمات) و `bankassistant://pair?code=…&server=…`. لینک داخل همان دامنه در PWA Universal Link را فعال نمی‌کند (رفتار iOS)، برای همین PWA از scheme اختصاصی استفاده می‌کند؛ iOS یک بار می‌پرسد «باز شود؟».
- **Universal Link:** `https://DOMAIN/bank/assistant/?a=listen` و `?a=pair&code=…&server=…` (مثلاً از پیام بله). اگر اپ نصب نباشد، `server/assistant/index.html` باز می‌شود.
  - روی سرور: `sudo bash deploy-vps.sh --domain 185-221-237-61.sslip.io --ios-app-id TEAMID.ir.example.bankassistant` ← فایل `/.well-known/apple-app-site-association` (JSON، روی HTTPS، در ریشه‌ی دامنه) ساخته می‌شود.
  - در اپ: `Resources/BankAssistant.entitlements` ← `applinks:DOMAIN` (دامنه‌ی خودت).
- **اپ → وب:** دکمه‌ی «حسابداری» آدرس `…/bank/app/` را باز می‌کند. iOS لینک را در Safari باز می‌کند، نه لزوماً در PWA نصب‌شده (اجازه‌ی باز کردن مستقیم PWA وجود ندارد).

## ۹. ساخت و اجرا روی آیفون (`M`)

نیاز: Mac با Xcode 15.4 یا جدیدتر (برای Control Center: Xcode 16)، حساب Apple Developer (برای اجرای ساده روی گوشی خودت حساب رایگان کافی است، ولی Universal Links / Associated Domains و انتشار به حساب پولی Developer Program نیاز دارند؛ با حساب رایگان، entitlement مربوط به applinks را موقتاً بردار)، آیفون با iOS 17+.

```bash
brew install xcodegen
cd ios
# در project.yml: DEVELOPMENT_TEAM و bundle idها (ir.example.bankassistant → شناسه‌ی خودت)
# در Resources/BankAssistant.entitlements: applinks:دامنه‌ی خودت
xcodegen generate
open BankAssistant.xcodeproj
```
در Xcode: هدف BankAssistant ← Signing ← تیم را انتخاب کن (برای هدف AssistantWidgets هم) ← آیفون را با کابل وصل کن ← Run. بار اول روی گوشی: Settings ← General ← VPN & Device Management ← به اپ اعتماد کن (اگر حساب رایگان است). Developer Mode را در Settings ← Privacy & Security روشن کن.

روی سرور (یک بار): صدای محلی باید نصب باشد (`voice/install.sh`، همان که در deploy-vps.sh اجرا می‌شود) و برای Universal Link گزینه‌ی `--ios-app-id`.

اتصال: PWA روی همان گوشی ← تنظیمات ← «اپ دستیار آیفون» ← «اتصال اپ آیفون» ← «وصل کردن اپ روی همین گوشی».

## ۱۰. تست روی دستگاه واقعی (`N`)

1. **اتصال:** کد را دو بار استفاده کن ← بار دوم باید رد شود. کد غلط ← «نادرست است».
2. **فرمان‌ها:** «فروش امروز چقدر بوده؟»، «موجودی انبار را بگو»، «گزارش فروش این ماه را بده»، «برای علی رضایی فاکتور ثبت کن، دو عدد …» ← «آره» ← در پنل حسابداری فاکتور را ببین.
3. **Home Screen:** وسط «در حال گوش دادن…» به صفحه‌ی اصلی برو ← Dynamic Island باید وضعیت را نشان دهد ← حرف بزن ← وضعیت به «پردازش» و «پاسخ» عوض شود و صدا پخش شود.
4. **صفحه‌ی قفل:** گوشی را قفل کن ← Live Activity با «بپرس» و «پایان»؛ «پایان» باید بدون باز شدن اپ جلسه را ببندد.
5. **تماس:** وسط جلسه با گوشی دیگر تماس بگیر ← جلسه باید بسته شود و بعد از تماس میکروفون خودبه‌خود روشن نشود.
6. **اجازه‌ی میکروفون:** در Settings ← Privacy ← Microphone اجازه را بردار ← دکمه‌ی «باز کردن تنظیمات» باید بیاید.
7. **لغو دسترسی:** از PWA دستگاه را لغو کن ← فرمان بعدی باید به صفحه‌ی اتصال برگردد.
8. **Siri / Action button / Control Center (iOS 18):** «Hey Siri, Ask Bank Assistant» ← اپ باز و گوش‌به‌زنگ.
9. **بدون صدای سرور:** سرویس `bank-voice` را متوقف کن ← پاسخ باید فقط متن باشد، بدون کرش.

سمت سرور خودکار تست می‌شود: `php server/tests/assistant_test.php` (جفت‌سازی، یک‌بار مصرف بودن کد، توکن، فرمان‌ها، پیش‌نویس فاکتور و «آره/نه»، کمبود موجودی، لغو دسترسی).

## ۱۱. App Store (`O`)

- **سازگار:** Live Activities برای «جلسه‌ی در جریان»، Background Audio فقط وقتی واقعاً ضبط/پخش هست (جلسه‌ی بیکار بسته می‌شود — بند 2.5.4)، App Intents/Shortcuts، Keychain، بدون API خصوصی.
- **استفاده‌نشده چون خطر Reject دارد:** PiP با ویدیوی ساختگی، Video-Call PiP بدون تماس تصویری، CallKit بدون تماس واقعی، باز نگه داشتن صدا برای زنده ماندن اپ.
- **الزامات:** متن `NSMicrophoneUsageDescription` (هست)، Privacy Manifest (`PrivacyInfo.xcprivacy`، هست)، برچسب حریم خصوصی App Store Connect: «Audio Data — App Functionality، Not Linked، Not Tracking»، **حساب آزمایشی برای بازبینی Apple** (یک سرور نمایشی با کد اتصال؛ بازبین باید بتواند بدون سرور شخصی شما اپ را امتحان کند — بند 2.1)، توضیح در Review Notes که صدا فقط به سرور خود مشتری می‌رود و Background Audio فقط حین مکالمه است.
- **توزیع:** چون اپ برای سرور اختصاصی هر کسب‌وکار است، TestFlight یا App Store عمومی با «سرور نمایشی» یا Custom App (Apple Business Manager). عضویت Apple Developer Program برای ساکنان برخی کشورها با محدودیت‌های تحریم روبه‌روست؛ این را قبل از شروع بررسی کن.

## ۱۲. چیزهایی که باید بدانی
- کد Swift روی Linux فقط از نظر **نحوی** بررسی شد (`swiftc -parse`) و منطق Deep Link تست شد؛ کامپایل کامل با SDK آیفون، Live Activity و اجرا روی دستگاه را باید روی Mac با Xcode انجام دهی. اگر خطای کامپایل دیدی، متن خطا را بفرست.
- انیمیشن داخل Dynamic Island محدود به چیزی است که iOS اجازه می‌دهد (آیکن و متن وضعیت)، نه انیمیشن آزاد.
