package ir.example.bankassistant

import android.annotation.SuppressLint
import android.content.Context
import android.graphics.PixelFormat
import android.view.Gravity
import android.view.WindowManager
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebView
import android.webkit.WebViewClient
import kotlinx.coroutines.CompletableDeferred
import kotlinx.coroutines.withTimeout
import org.json.JSONArray
import org.json.JSONObject
import java.util.concurrent.ConcurrentHashMap
import java.util.concurrent.atomic.AtomicInteger

/**
 * The floating orb without a server: its own copy of the books engine (app/local/headless.html)
 * in a WebView that is never shown, on the same books file as the app (LocalMode).
 * The same answers as [Api] (pending, ask); [onChange] when a bank SMS from the ESP32 was booked.
 * Main thread only.
 */
class LocalBrain(private val context: Context, prefs: Prefs, private val onChange: () -> Unit) {
    private val local = LocalMode(context, prefs)
    private var web: WebView? = null
    private var ready = CompletableDeferred<Unit>()
    private val waiting = ConcurrentHashMap<String, CompletableDeferred<String>>()
    private val ids = AtomicInteger()

    @SuppressLint("SetJavaScriptEnabled")
    fun attach(wm: WindowManager, overlayType: Int) {
        if (web != null) return
        val w = WebView(context)
        w.settings.javaScriptEnabled = true
        w.settings.domStorageEnabled = true
        if (BuildConfig.DEBUG) w.settings.mixedContentMode = android.webkit.WebSettings.MIXED_CONTENT_ALWAYS_ALLOW
        w.addJavascriptInterface(local.Bridge { web }, "BANative")
        w.addJavascriptInterface(Host(), "OrbHost")
        w.webViewClient = object : WebViewClient() {
            override fun shouldInterceptRequest(view: WebView, req: WebResourceRequest) = local.intercept(req)
        }
        // a 1×1 window that can't be seen or touched: an attached WebView keeps its timers running
        runCatching {
            wm.addView(w, WindowManager.LayoutParams(1, 1, overlayType,
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE,
                PixelFormat.TRANSLUCENT).apply { gravity = Gravity.TOP or Gravity.START; alpha = 0f })
        }
        w.loadUrl(local.orbUrl)
        web = w
    }

    fun detach(wm: WindowManager) {
        web?.let { runCatching { wm.removeView(it) }; it.destroy() }
        web = null
        waiting.values.forEach { it.completeExceptionally(ApiException("orb بسته شد")) }
        waiting.clear()
        ready = CompletableDeferred()
    }

    /** What is still waiting for «بابت چی بود؟» (and the link to the ESP32 kept up). */
    suspend fun pending(): List<Api.Pending> = pendingOf(JSONArray(call("sync", "")))

    suspend fun ask(text: String): Api.Reply {
        val o = JSONObject(call("ask", text))
        return Api.Reply(text, o.optString("reply"), o.optString("state"))
    }

    private fun pendingOf(a: JSONArray) = (0 until a.length()).map { i ->
        val t = a.getJSONObject(i)
        Api.Pending(t.getInt("id"), t.getString("direction") == "in", t.getLong("amount"))
    }

    private suspend fun call(what: String, arg: String): String {
        val w = web ?: throw ApiException("orb آماده نیست")
        withTimeout(20_000) { ready.await() }
        val id = "o" + ids.incrementAndGet()
        val d = CompletableDeferred<String>()
        waiting[id] = d
        w.evaluateJavascript("orbCall(${JSONObject.quote(id)}, ${JSONObject.quote(what)}, ${JSONObject.quote(arg)})", null)
        return try { withTimeout(60_000) { d.await() } } finally { waiting.remove(id) }
    }

    inner class Host {
        @JavascriptInterface fun ready() { ready.complete(Unit) }
        @JavascriptInterface fun changed() { android.os.Handler(android.os.Looper.getMainLooper()).post { onChange() } }
        @JavascriptInterface fun done(id: String, ok: Boolean, json: String) {
            val d = waiting[id] ?: return
            if (ok) d.complete(json)
            else d.completeExceptionally(ApiException(runCatching { JSONArray("[$json]").getString(0) }.getOrDefault("خطا")))
        }
    }
}
