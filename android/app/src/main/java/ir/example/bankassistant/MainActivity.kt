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
import android.provider.Settings
import android.text.InputType
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
    private var pendingWebPermission: PermissionRequest? = null
    private var fileCallback: ValueCallback<Array<Uri>>? = null
    private var startOrbWhenAllowed = false

    private val micPermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { ok ->
        pendingWebPermission?.let { if (ok) it.grant(it.resources) else it.deny() }
        pendingWebPermission = null
    }
    private val notePermission = registerForActivityResult(ActivityResultContracts.RequestPermission()) { }
    private val pickFile = registerForActivityResult(ActivityResultContracts.StartActivityForResult()) { r ->
        fileCallback?.onReceiveValue(WebChromeClient.FileChooserParams.parseResult(r.resultCode, r.data))
        fileCallback = null
    }

    override fun onCreate(savedInstanceState: Bundle?) {
        super.onCreate(savedInstanceState)
        prefs = Prefs(this)
        if (BuildConfig.DEBUG) intent?.getStringExtra("server")?.let { Prefs.normalizeBase(it)?.let { b -> prefs.base = b } }
        window.statusBarColor = Color.parseColor("#07140C")
        if (prefs.base == null) showSetup() else showWeb()
        onBackPressedDispatcher.addCallback(this, object : OnBackPressedCallback(true) {
            override fun handleOnBackPressed() {
                val w = web
                if (w != null && w.canGoBack()) w.goBack() else finish()
            }
        })
        // emulator screenshots (debug builds only): adb shell am start … --es demo_token PASS --ez demo_orb true
        if (BuildConfig.DEBUG && intent?.getBooleanExtra("demo_orb", false) == true) OrbService.start(this)
    }

    override fun onNewIntent(intent: Intent) {
        super.onNewIntent(intent)
        setIntent(intent)
        if (BuildConfig.DEBUG && intent.getBooleanExtra("demo_orb", false)) OrbService.start(this)
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
                    showWeb()
                }
            }
        }
        root.addView(go, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, dp(58)).apply { topMargin = dp(20) })
        setContentView(root)
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
        }
        w.webChromeClient = object : WebChromeClient() {
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
    }

    private fun notifyWeb() {
        web?.evaluateJavascript("window.onAndroidOrb && window.onAndroidOrb()", null)
    }

    private fun hasMic() = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    /** What the web app can ask from the Android side. */
    inner class Bridge {
        @JavascriptInterface fun version() = 1
        @JavascriptInterface fun isPaired() = prefs.paired
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

        @JavascriptInterface fun changeServer() = runOnUiThread {
            OrbService.stop(this@MainActivity)
            prefs.unpair()
            prefs.base = null
            showSetup()
        }
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
