package ir.example.bankassistant

import android.Manifest
import android.annotation.SuppressLint
import android.content.Intent
import android.content.pm.PackageManager
import android.graphics.Color
import android.graphics.Typeface
import android.graphics.drawable.GradientDrawable
import android.net.Uri
import android.os.Build
import android.os.Bundle
import android.widget.Toast
import android.provider.Settings
import android.text.InputType
import android.util.Log
import android.util.TypedValue
import android.view.Gravity
import android.view.View
import android.view.ViewGroup
import android.webkit.JavascriptInterface
import android.webkit.PermissionRequest
import android.webkit.ValueCallback
import android.webkit.WebChromeClient
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import android.widget.Button
import android.widget.EditText
import android.widget.LinearLayout
import android.widget.TextView
import androidx.activity.OnBackPressedCallback
import androidx.activity.result.contract.ActivityResultContracts
import androidx.appcompat.app.AppCompatActivity
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import org.json.JSONArray
import org.json.JSONObject

/**
 * The Android app: the phone web app (server/app) in a WebView, plus the native
 * floating orb (OrbService). The web app sees `window.AndroidOrb` and pairs the
 * orb with a one-time code — the app password never reaches native code; the
 * orb only gets a device token, kept encrypted (Android Keystore).
 */
class MainActivity : AppCompatActivity() {

    private lateinit var prefs: Prefs
    private var web: WebView? = null
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private val speaker by lazy { Speaker(cacheDir) }   // the web app's answers in the app's own voice
    private val webRecorder by lazy { Recorder(cacheDir) }   // the web app's questions for the app's own ear
    private var pendingWebPermission: PermissionRequest? = null
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var startOrbWhenAllowed = false

    private val micPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        pendingWebPermission?.let { if (ok) it.grant(it.resources) else it.deny() }
        pendingWebPermission = null
        // the web app's question that asked for the microphone: listen now (or say nothing was heard)
        micWaiting?.let { id ->
            micWaiting = null
            if (ok) hear(id) else {
                Toast.makeText(this, "برای شنیدن، اجازه‌ی میکروفون لازم است", Toast.LENGTH_LONG).show()
                heard(id, "")
            }
        }
    }
    private var micWaiting: String? = null
    private val notePermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }
    private val pickFile = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(r.resultCode, r.data))
        fileCallback = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = Prefs(this)
        if (BuildConfig.DEBUG) intent?.getStringExtra("server")?.let { Prefs.normalizeBase(it)?.let { b -> prefs.base = b; prefs.mode = "server" } }
        // tests: the mode without a server against a pretend Bale (tools/demo/fakebale.php)
        if (BuildConfig.DEBUG) intent?.getStringExtra("local_token")?.let { t ->
            prefs.baleToken = t
            prefs.baleBase = intent?.getStringExtra("bale_base")
            prefs.mode = "local"
        }
        window.statusBarColor = Color.parseColor("#07140C")
        if (prefs.local) showLocal() else if (prefs.base == null) showSetup() else showWeb()
        showLastCrash()
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                val w = web
                if (w != null && w.canGoBack()) w.goBack() else finish()
            }
        })
        // emulator screenshots (debug builds only): adb shell am start … --es demo_token PASS --ez demo_orb true
        if (BuildConfig.DEBUG && intent?.getBooleanExtra("demo_orb", false) == true) OrbService.start(this)
        if (BuildConfig.DEBUG && intent?.getBooleanExtra("voice_test", false) == true) voiceTest()
        LocalVoice.warmUp(this)
        LocalEar.warmUp(this)
    }

    /**
     * Debug builds: checks the app's own Persian voice on the emulator (CI greps the log):
     * the number-to-words rules against the server's output, and one spoken sentence.
     */
    private fun voiceTest() {
        scope.launch(Dispatchers.IO) {
            var same = 0
            var total = 0
            runCatching {
                val cases = JSONArray(assets.open("speech_cases.json").bufferedReader().readText())
                total = cases.length()
                for (i in 0 until total) {
                    val c = cases.getJSONObject(i)
                    val got = SpeechText.forSpeech(c.getString("in"))
                    if (got == c.getString("out")) same++ else Log.w("VOICE_TEST", "diff: " + got + " | want: " + c.getString("out"))
                }
            }
            val t = System.currentTimeMillis()
            val wav = runCatching { LocalVoice.wav(this@MainActivity, "بانک ملی، واریز ۷٬۰۰۰٬۰۰۰ تومان. بابت چی بود؟") }.getOrNull()
            val ms = System.currentTimeMillis() - t
            var heard = "-"
            var earMs = 0L
            if (wav != null) {
                // the app's own ear on the app's own voice (22.05 kHz → 16 kHz, as the microphone records)
                val src = java.nio.ByteBuffer.wrap(wav, 44, wav.size - 44).order(java.nio.ByteOrder.LITTLE_ENDIAN).asShortBuffer()
                val inRate = java.nio.ByteBuffer.wrap(wav, 24, 4).order(java.nio.ByteOrder.LITTLE_ENDIAN).int
                val n = src.remaining()
                val out = FloatArray((n.toLong() * 16000 / inRate).toInt())
                for (i in out.indices) out[i] = src.get(minOf(n - 1, (i.toLong() * inRate / 16000).toInt())) / 32768f
                val t2 = System.currentTimeMillis()
                heard = runCatching { LocalEar.transcribe(this@MainActivity, out) }.getOrNull() ?: "null"
                earMs = System.currentTimeMillis() - t2
            }
            Log.i("VOICE_TEST", "ear=${LocalEar.bundled(this@MainActivity)} stt=${if ("ملی" in heard && "میلیون" in heard) "ok" else "fail"} stt_ms=$earMs heard=$heard")
            Log.i("VOICE_TEST", "speech_text=$same/$total bundled=${LocalVoice.bundled(this@MainActivity)} " +
                "tts=${if (wav != null && wav.size > 44 + 22050) "ok" else "fail"} bytes=${wav?.size ?: 0} ms=$ms " +
                "phone_listen=${PhoneListen.available(this@MainActivity)}")
        }
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (BuildConfig.DEBUG && intent.getBooleanExtra("demo_orb", false)) OrbService.start(this)
        if (BuildConfig.DEBUG && intent.getBooleanExtra("voice_test", false)) voiceTest()
    }

    override fun onResume() {
        super.onResume()
        if (startOrbWhenAllowed && OrbService.canDrawOverlays(this)) {
            startOrbWhenAllowed = false
            OrbService.start(this)
            notifyWeb()
        }
        // restart the orb if the user had it on
        if (prefs.orbEnabled && !OrbService.running && OrbService.canDrawOverlays(this)) OrbService.start(this)
        notifyWeb()   // back from a settings screen (overlay, notification access)
    }

    override fun onDestroy() {
        scope.cancel()
        super.onDestroy()
    }

    // ------------------------------------------------------------ first run

    private fun showSetup(error: String? = null) {
        web?.destroy()
        web = null
        val p = dp(24)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(p, p, p, p)
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            background = GradientDrawable(GradientDrawable.Orientation.TOP_BOTTOM,
                intArrayOf(Color.parseColor("#275C34"), Color.parseColor("#07140C"), Color.parseColor("#07140C")))
        }
        root.addView(OrbView(this).apply { phase = Phase.LISTENING; level = 0.3f }, LinearLayout.LayoutParams(dp(120), dp(120)).apply {
            gravity = Gravity.CENTER_HORIZONTAL; bottomMargin = dp(24)
        })
        root.addView(text("دستیار حسابداری", 30f, Color.WHITE, bold = true))
        root.addView(text("آدرس سرور حسابداری خودت را بنویس (همان آدرسی که اپ وب را با آن باز می‌کنی).", 15f, Color.argb(170, 255, 255, 255)).apply {
            setPadding(0, dp(8), 0, dp(20))
        })
        val input = EditText(this).apply {
            hint = "https://دامنه/bank/"
            setHintTextColor(Color.argb(90, 255, 255, 255))
            setTextColor(Color.WHITE)
            textDirection = View.TEXT_DIRECTION_LTR
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_URI
            setSingleLine()
            setPadding(dp(18), dp(14), dp(18), dp(14))
            background = GradientDrawable().apply {
                cornerRadius = dp(18).toFloat(); setColor(Color.argb(20, 255, 255, 255)); setStroke(dp(1), Color.argb(50, 255, 255, 255))
            }
            prefs.base?.let { setText(it) }
        }
        root.addView(input, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT))
        if (error != null) root.addView(text(error, 14f, Color.parseColor("#FF9B8F")).apply { setPadding(0, dp(10), 0, 0) })
        val go = Button(this).apply {
            text = "ورود"
            isAllCaps = false
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 17f)
            setTextColor(Color.parseColor("#121414"))
            typeface = Typeface.DEFAULT_BOLD
            background = GradientDrawable(GradientDrawable.Orientation.TL_BR,
                intArrayOf(Color.parseColor("#BBF3B4"), Color.parseColor("#8DE08F"), Color.parseColor("#5BBD6D"))).apply { cornerRadius = dp(30).toFloat() }
            setOnClickListener {
                val base = Prefs.normalizeBase(input.text.toString())
                if (base == null) {
                    showSetup("آدرس درست نیست. برای سرور اینترنتی https لازم است (مثلاً https://example.com/bank/).")
                } else {
                    prefs.base = base
                    prefs.mode = "server"
                    showWeb()
                }
            }
        }
        root.addView(go, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(58)).apply { topMargin = dp(20) })
        root.addView(text("یا بدون سرور: پیامک‌های بانک را ESP32 به ربات بله‌ات می‌فرستد و همه‌چیز روی همین گوشی ثبت می‌شود.", 14f, Color.argb(170, 255, 255, 255)).apply {
            setPadding(0, dp(28), 0, dp(4))
        })
        root.addView(Button(this).apply {
            text = "بدون سرور (فقط بله و ESP32)"
            isAllCaps = false
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 16f)
            setTextColor(Color.WHITE)
            background = GradientDrawable().apply { cornerRadius = dp(30).toFloat(); setStroke(dp(1), Color.argb(120, 255, 255, 255)) }
            setOnClickListener { showLocalSetup() }
        }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(54)).apply { topMargin = dp(8) })
        setContentView(root)
    }

    /** The mode without a server: only the Bale bot token (the same one the ESP32 sends with). */
    private fun showLocalSetup(error: String? = null) {
        val p = dp(24)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(p, p, p, p)
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            background = GradientDrawable(GradientDrawable.Orientation.TOP_BOTTOM,
                intArrayOf(Color.parseColor("#275C34"), Color.parseColor("#07140C"), Color.parseColor("#07140C")))
        }
        root.addView(text("بدون سرور", 28f, Color.WHITE, bold = true))
        root.addView(text("۱) در بله از @botfather یک ربات بساز و توکنش را اینجا بگذار (همان توکنی که در فریمور ESP32 هست).\n" +
            "۲) در بله به ربات خودت /start بفرست؛ اپ چت را وصل می‌کند و عددش را نشان می‌دهد.\n" +
            "۳) زیر هر پیامک بانک در بله دکمه‌ی «ثبت در حسابداری» را بزن یا Reply کن؛ اپ آن را می‌خواند و ثبت می‌کند.", 14f, Color.argb(190, 255, 255, 255)).apply {
            setPadding(0, dp(10), 0, dp(18))
        })
        val input = EditText(this).apply {
            hint = "توکن ربات بله"
            setHintTextColor(Color.argb(90, 255, 255, 255))
            setTextColor(Color.WHITE)
            textDirection = View.TEXT_DIRECTION_LTR
            inputType = InputType.TYPE_CLASS_TEXT or InputType.TYPE_TEXT_VARIATION_VISIBLE_PASSWORD
            setSingleLine()
            setPadding(dp(18), dp(14), dp(18), dp(14))
            background = GradientDrawable().apply {
                cornerRadius = dp(18).toFloat(); setColor(Color.argb(20, 255, 255, 255)); setStroke(dp(1), Color.argb(50, 255, 255, 255))
            }
        }
        root.addView(input, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT))
        if (error != null) root.addView(text(error, 14f, Color.parseColor("#FF9B8F")).apply { setPadding(0, dp(10), 0, 0) })
        root.addView(Button(this).apply {
            text = "شروع"
            isAllCaps = false
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 17f)
            setTextColor(Color.parseColor("#121414"))
            typeface = Typeface.DEFAULT_BOLD
            background = GradientDrawable(GradientDrawable.Orientation.TL_BR,
                intArrayOf(Color.parseColor("#BBF3B4"), Color.parseColor("#8DE08F"), Color.parseColor("#5BBD6D"))).apply { cornerRadius = dp(30).toFloat() }
            setOnClickListener {
                val token = input.text.toString().trim()
                if (!Regex("^\\d{5,}:[\\w-]{10,}$").matches(token)) return@setOnClickListener showLocalSetup("این توکن ربات بله نیست (شکلش این است: 123456789:AbC…).")
                isEnabled = false
                prefs.baleToken = token
                scope.launch {
                    val me = withContext(Dispatchers.IO) { runCatching { JSONObject(LocalMode(this@MainActivity, prefs).bale("getMe", "{}")) }.getOrNull() }
                    if (me?.optBoolean("ok") == true) {
                        prefs.mode = "local"
                        showLocal()
                    } else {
                        prefs.baleToken = null
                        showLocalSetup(if (me == null) "به بله وصل نشد؛ اینترنت را ببین." else "بله این توکن را قبول نکرد.")
                    }
                }
            }
        }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(58)).apply { topMargin = dp(20) })
        root.addView(Button(this).apply {
            text = "برگشت"
            isAllCaps = false
            setTextColor(Color.argb(200, 255, 255, 255))
            setBackgroundColor(Color.TRANSPARENT)
            setOnClickListener { showSetup() }
        })
        setContentView(root)
    }

    /** The web app with its own books (assets/www) — no server. */
    @SuppressLint("SetJavaScriptEnabled")
    private fun showLocal() {
        web?.destroy()
        val local = LocalMode(this, prefs)
        val w = WebView(this)
        web = w
        w.setBackgroundColor(Color.parseColor("#07140C"))
        with(w.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString BankAssistantAndroid/1 BankAssistantLocal/1"
            allowFileAccess = false
            setSupportZoom(false)
        }
        w.addJavascriptInterface(Bridge(), "AndroidOrb")
        w.addJavascriptInterface(local.Bridge { web }, "BANative")
        w.webViewClient = object : WebViewClient() {
            override fun shouldInterceptRequest(view: WebView, req: WebResourceRequest) = local.intercept(req)
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                if (local.isOwnPage(req.url)) return false
                runCatching { startActivity(Intent(Intent.ACTION_VIEW, req.url)) }
                return true
            }
            override fun onReceivedError(view: WebView, req: WebResourceRequest, err: android.webkit.WebResourceError) {
                if (req.isForMainFrame) pageError = "${err.description} (${err.errorCode})"
            }
        }
        w.webChromeClient = object : WebChromeClient() {
            override fun onConsoleMessage(m: android.webkit.ConsoleMessage): Boolean = noteConsole(m)
            override fun onShowFileChooser(view: WebView, cb: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = cb
                return runCatching { pickFile.launch(params.createIntent()); true }.getOrElse { fileCallback = null; false }
            }
        }
        setContentView(w)
        w.loadUrl(local.startUrl)
        guard(w, local = true)
    }

    // ------------------------------------------------------------ never a black screen

    private var pageError: String? = null

    private fun noteConsole(m: android.webkit.ConsoleMessage): Boolean {
        if (m.messageLevel() == android.webkit.ConsoleMessage.MessageLevel.ERROR) {
            Log.w("BankWeb", "${m.message()} @${m.sourceId()}:${m.lineNumber()}")
            if (pageError == null) pageError = "${m.message()} (${m.sourceId().substringAfterLast('/')}:${m.lineNumber()})"
        }
        return false
    }

    /** The page did not load, or broke before showing anything: say why, with a way out. */
    private fun guard(w: WebView, local: Boolean) {
        pageError = null
        w.postDelayed({
            if (web !== w || isFinishing) return@postDelayed
            w.evaluateJavascript("(function(){var a=document.getElementById('app');return !!(a&&a.children.length)})()") { ok ->
                if (web === w && ok != "true") showLoadError(local)
            }
        }, if (local) 12000 else 20000)
    }

    private fun showLoadError(local: Boolean) {
        val why = pageError
        web?.destroy()
        web = null
        val p = dp(24)
        val root = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_VERTICAL
            setPadding(p, p, p, p)
            layoutDirection = View.LAYOUT_DIRECTION_RTL
            setBackgroundColor(Color.parseColor("#07140C"))
        }
        root.addView(text("صفحه باز نشد", 26f, Color.WHITE, bold = true))
        root.addView(text(if (local) "صفحه‌ی اپ (نسخه‌ی بدون سرور) بالا نیامد." else
            "سرور جواب نداد: ${prefs.base}\nکامپیوتر یا سرور روشن است؟ گوشی به همان شبکه یا اینترنت وصل است؟",
            15f, Color.argb(190, 255, 255, 255)).apply { setPadding(0, dp(10), 0, dp(10)) })
        if (why != null) root.addView(text("خطا: $why", 13f, Color.parseColor("#FCA5A5")).apply {
            textDirection = View.TEXT_DIRECTION_LTR
            setTextIsSelectable(true)
            setPadding(0, 0, 0, dp(16))
        })
        fun button(label: String, filled: Boolean, go: () -> Unit) = root.addView(Button(this).apply {
            text = label
            isAllCaps = false
            setTextColor(if (filled) Color.parseColor("#07140C") else Color.WHITE)
            background = GradientDrawable().apply {
                cornerRadius = dp(18).toFloat()
                setColor(if (filled) Color.parseColor("#4ADE80") else Color.argb(25, 255, 255, 255))
            }
            setOnClickListener { go() }
        }, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(52)).apply { topMargin = dp(10) })
        button("دوباره", true) { if (local) showLocal() else showWeb() }
        button(if (local) "تغییر حالت (با سرور / بدون سرور)" else "تغییر سرور یا حالت", false) {
            if (local) leaveLocal() else showSetup()
        }
        setContentView(root)
    }

    /** Settings ← «تغییر حالت»: back to the first screen (the books stay on the phone). */
    fun leaveLocal() {
        prefs.mode = null
        showSetup()
    }

    private fun text(s: String, size: Float, color: Int, bold: Boolean = false) = TextView(this).apply {
        text = s
        setTextSize(TypedValue.COMPLEX_UNIT_SP, size)
        setTextColor(color)
        if (bold) typeface = Typeface.DEFAULT_BOLD
        textDirection = View.TEXT_DIRECTION_RTL
    }

    // ------------------------------------------------------------ web app

    @SuppressLint("SetJavaScriptEnabled")
    private fun showWeb() {
        val base = prefs.base ?: return showSetup()
        val w = WebView(this)
        web = w
        w.setBackgroundColor(Color.parseColor("#07140C"))
        with(w.settings) {
            javaScriptEnabled = true
            domStorageEnabled = true
            mediaPlaybackRequiresUserGesture = false
            userAgentString = "$userAgentString BankAssistantAndroid/1"
            allowFileAccess = false
            setSupportZoom(false)
        }
        w.addJavascriptInterface(Bridge(), "AndroidOrb")
        w.webViewClient = object : WebViewClient() {
            override fun shouldOverrideUrlLoading(view: WebView, req: WebResourceRequest): Boolean {
                val u = req.url
                val home = Uri.parse(base)
                if ((u.scheme == "https" || u.scheme == "http") && u.host == home.host) return false
                runCatching { startActivity(Intent(Intent.ACTION_VIEW, u)) }
                return true
            }
            override fun onReceivedError(view: WebView, req: WebResourceRequest, err: android.webkit.WebResourceError) {
                if (req.isForMainFrame) pageError = "${err.description} (${err.errorCode})"
            }
        }
        w.webChromeClient = object : WebChromeClient() {
            override fun onConsoleMessage(m: android.webkit.ConsoleMessage): Boolean = noteConsole(m)
            override fun onPermissionRequest(request: PermissionRequest) {
                runOnUiThread {
                    if (PermissionRequest.RESOURCE_AUDIO_CAPTURE !in request.resources) return@runOnUiThread request.deny()
                    if (hasMic()) request.grant(arrayOf(PermissionRequest.RESOURCE_AUDIO_CAPTURE))
                    else { pendingWebPermission = request; micPermission.launch(Manifest.permission.RECORD_AUDIO) }
                }
            }

            override fun onShowFileChooser(view: WebView, cb: ValueCallback<Array<Uri>>, params: FileChooserParams): Boolean {
                fileCallback?.onReceiveValue(null)
                fileCallback = cb
                return runCatching { pickFile.launch(params.createIntent()); true }.getOrElse { fileCallback = null; false }
            }
        }
        w.setDownloadListener { url, _, _, _, _ -> runCatching { startActivity(Intent(Intent.ACTION_VIEW, Uri.parse(url))) } }
        setContentView(w)
        val demoToken = if (BuildConfig.DEBUG) intent?.getStringExtra("demo_token") else null
        if (demoToken != null) {
            w.webViewClient = object : WebViewClient() {
                var done = false
                override fun onPageFinished(view: WebView, url: String) {
                    if (done) return
                    done = true
                    view.evaluateJavascript("localStorage.setItem('ba_token', ${JSONObject.quote(demoToken)}); location.reload();", null)
                }
            }
        }
        w.loadUrl(base + "app/?android=1")
        guard(w, local = false)
    }

    private fun notifyWeb() {
        web?.evaluateJavascript("window.onAndroidOrb && window.onAndroidOrb()", null)
    }

    /** The app closed by itself last time: say so once, with the error to copy (App.kt). */
    private fun showLastCrash() {
        val f = App.crashFile(this)
        val text = runCatching { f.readText() }.getOrNull() ?: return
        f.delete()
        android.app.AlertDialog.Builder(this)
            .setTitle("اپ دفعه‌ی قبل بسته شد")
            .setMessage("orb شناور خاموش شد تا اپ باز شود. اگر دوباره پیش آمد، این متن را کپی کن و بفرست:\n\n" + text.take(1500))
            .setPositiveButton("کپی خطا") { _, _ ->
                (getSystemService(CLIPBOARD_SERVICE) as android.content.ClipboardManager)
                    .setPrimaryClip(android.content.ClipData.newPlainText("crash", text))
                Toast.makeText(this, "کپی شد", Toast.LENGTH_SHORT).show()
            }
            .setNegativeButton("بستن", null)
            .show()
    }

    private fun hasMic() = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    /** One question from the web app: the app's own recognizer first (no server, no Google), else the phone's. */
    private fun hear(id: String) {
        scope.launch {
            var text: String? = null
            if (LocalEar.bundled(this@MainActivity)) text = withContext(Dispatchers.IO) {
                val wav = try { webRecorder.record() } catch (e: Exception) { Log.w("BankVoice", "record: $e"); null }
                if (wav == null) "" else try { LocalEar.transcribe(this@MainActivity, wav) } finally { wav.delete() }
            }
            if (text == null && PhoneListen.available(this@MainActivity)) text = PhoneListen.listen(this@MainActivity)
            Log.i("BankVoice", "heard: " + (text ?: "<no recognizer>"))
            heard(id, text)
        }
    }

    private fun heard(id: String, text: String?) {
        web?.evaluateJavascript("window.onAndroidHeard && window.onAndroidHeard(${JSONObject.quote(id)}, " +
            (if (text == null) "null" else JSONObject.quote(text)) + ")", null)
    }

    /** What the web app can ask from the Android side. */
    inner class Bridge {
        @JavascriptInterface fun version() = 1
        @JavascriptInterface fun isPaired() = prefs.paired || prefs.local

        /** Without a server: the orb reads Bale notifications (BaleWatch), so no tap in Bale is needed. */
        @JavascriptInterface fun baleNotices() = BaleWatch.allowed(this@MainActivity)
        @JavascriptInterface fun openBaleNotices() = runOnUiThread {
            runCatching { startActivity(Intent(Settings.ACTION_NOTIFICATION_LISTENER_SETTINGS)) }
        }
        @JavascriptInterface fun orbOn() = OrbService.running
        @JavascriptInterface fun canOverlay() = OrbService.canDrawOverlays(this@MainActivity)
        @JavascriptInterface fun autoAsk() = prefs.autoAsk
        @JavascriptInterface fun setAutoAsk(on: Boolean) { prefs.autoAsk = on }

        /** One-time code from api.php?r=assistant_pair (the web app asks it with its own login). */
        @JavascriptInterface fun pair(code: String) {
            val base = prefs.base ?: return
            scope.launch {
                val result = runCatching {
                    withContext(Dispatchers.IO) { Api(prefs).redeem(base, code.trim().uppercase(), Build.MANUFACTURER + " " + Build.MODEL) }
                }
                result.onSuccess { prefs.deviceToken = it }
                val msg = JSONObject.quote(result.exceptionOrNull()?.message ?: "")
                web?.evaluateJavascript("window.onAndroidPaired && window.onAndroidPaired(${result.isSuccess}, $msg)", null)
            }
        }

        @JavascriptInterface fun startOrb() = runOnUiThread {
            if (!hasMic()) micPermission.launch(Manifest.permission.RECORD_AUDIO)
            if (Build.VERSION.SDK_INT >= 33) notePermission.launch(Manifest.permission.POST_NOTIFICATIONS)
            if (OrbService.canDrawOverlays(this@MainActivity)) {
                OrbService.start(this@MainActivity)
                notifyWeb()
            } else {
                startOrbWhenAllowed = true
                startActivity(Intent(Settings.ACTION_MANAGE_OVERLAY_PERMISSION, Uri.parse("package:$packageName")))
            }
        }

        @JavascriptInterface fun stopOrb() = runOnUiThread {
            OrbService.stop(this@MainActivity)
            web?.postDelayed({ notifyWeb() }, 300)
        }

        @JavascriptInterface fun talk() = runOnUiThread {
            if (OrbService.canDrawOverlays(this@MainActivity)) OrbService.start(this@MainActivity, talk = true) else startOrb()
        }

        @JavascriptInterface fun unpair() = runOnUiThread {
            OrbService.stop(this@MainActivity)
            prefs.unpair()
        }

        /** The app's own Persian voice is there (then the web app needs no server voice). */
        @JavascriptInterface fun localVoice() = LocalVoice.bundled(this@MainActivity)

        /** Says [text] in the app's voice; then window.onAndroidSpoken(id, ok). */
        @JavascriptInterface fun speak(id: String, text: String) {
            scope.launch {
                val wav = withContext(Dispatchers.IO) { runCatching { LocalVoice.wav(this@MainActivity, text) }.getOrNull() }
                if (wav != null) runCatching { speaker.play(wav) }
                web?.evaluateJavascript("window.onAndroidSpoken && window.onAndroidSpoken(${JSONObject.quote(id)}, ${wav != null})", null)
            }
        }

        @JavascriptInterface fun stopSpeaking() = runOnUiThread { speaker.stop() }

        /** The phone's Persian recognizer can listen (no server needed). */
        @JavascriptInterface fun canListen() = LocalEar.bundled(this@MainActivity) || PhoneListen.available(this@MainActivity)

        /** Listens once; then window.onAndroidHeard(id, text) — text null when it could not listen. */
        @JavascriptInterface fun listen(id: String) = runOnUiThread {
            if (!hasMic()) {
                // ask first, then listen (the web app waits for the answer)
                micWaiting?.let { heard(it, "") }
                micWaiting = id
                micPermission.launch(Manifest.permission.RECORD_AUDIO)
                return@runOnUiThread
            }
            hear(id)
        }

        @JavascriptInterface fun changeServer() = runOnUiThread {
            OrbService.stop(this@MainActivity)
            prefs.unpair()
            prefs.base = null
            showSetup()
        }
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
