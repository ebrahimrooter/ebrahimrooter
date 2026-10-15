package ir.example.bankassistant

import android.app.Activity
import android.content.ContentValues
import android.content.Context
import android.content.Intent
import android.net.Uri
import android.os.Build
import android.os.Handler
import android.os.Looper
import android.provider.MediaStore
import android.util.Base64
import android.webkit.JavascriptInterface
import android.webkit.WebResourceRequest
import android.webkit.WebResourceResponse
import android.webkit.WebView
import androidx.core.content.FileProvider
import androidx.webkit.WebViewAssetLoader
import org.json.JSONObject
import java.io.ByteArrayInputStream
import java.io.File
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

/**
 * The app without a server: the phone web app with its own books
 * (assets/www, made by tools/local-app/build.sh), the bank SMS from Bale.
 *   https://appassets.androidplatform.net/assets/www/app/   the bundled web app
 *   …/__bale/<method>?q=<json>                               Bale Bot API, token added here
 * The books are a file in the app's private storage (local-books.json). The floating orb
 * (OrbService) runs its own copy of the engine (app/local/headless.html) on the same file.
 */
class LocalMode(private val context: Context, private val prefs: Prefs) {
    val startUrl = "https://${WebViewAssetLoader.DEFAULT_DOMAIN}/assets/www/app/index.html?android=1"
    val orbUrl = "https://${WebViewAssetLoader.DEFAULT_DOMAIN}/assets/www/app/local/headless.html"
    private val assets = WebViewAssetLoader.Builder()
        .addPathHandler("/assets/", WebViewAssetLoader.AssetsPathHandler(context))
        .build()
    private val books = File(context.filesDir, "local-books.json")
    private val main = Handler(Looper.getMainLooper())

    fun isOwnPage(u: Uri) = u.host == WebViewAssetLoader.DEFAULT_DOMAIN

    fun intercept(req: WebResourceRequest): WebResourceResponse? {
        val u = req.url
        if (!isOwnPage(u)) return null
        val path = u.path ?: return null
        if (path.startsWith("/__bale/")) {
            val method = path.removePrefix("/__bale/").takeWhile { it.isLetterOrDigit() }
            val body = runCatching { bale(method, u.getQueryParameter("q") ?: "{}") }
                .getOrElse { JSONObject().put("ok", false).put("description", it.message ?: "بله در دسترس نیست").toString() }
            return WebResourceResponse("application/json", "utf-8", ByteArrayInputStream(body.toByteArray()))
                .apply { responseHeaders = mapOf("Cache-Control" to "no-store") }
        }
        return assets.shouldInterceptRequest(u)
    }

    private fun base() = (prefs.baleBase ?: "https://tapi.bale.ai").trimEnd('/')

    /** One Bot API call (JSON in, JSON out). Blocking: WebView asks on its own thread. */
    fun bale(method: String, json: String): String {
        val token = prefs.baleToken ?: return """{"ok":false,"description":"توکن ربات بله گذاشته نشده"}"""
        val c = URL("${base()}/bot$token/$method").openConnection() as HttpURLConnection
        c.connectTimeout = 15000
        c.readTimeout = 30000
        c.requestMethod = "POST"
        c.doOutput = true
        c.setRequestProperty("Content-Type", "application/json; charset=utf-8")
        c.outputStream.use { it.write(json.toByteArray()) }
        val s = (if (c.responseCode < 400) c.inputStream else c.errorStream)?.use { it.readBytes().toString(Charsets.UTF_8) } ?: ""
        c.disconnect()
        return s.ifBlank { """{"ok":false,"description":"بله جواب نداد (${c.responseCode})"}""" }
    }

    private fun sendDocument(chatId: String, name: String, bytes: ByteArray, caption: String): String {
        val token = prefs.baleToken ?: throw IllegalStateException("توکن ربات بله گذاشته نشده")
        val b = "----ba" + UUID.randomUUID().toString().replace("-", "")
        val c = URL("${base()}/bot$token/sendDocument").openConnection() as HttpURLConnection
        c.connectTimeout = 15000
        c.readTimeout = 60000
        c.requestMethod = "POST"
        c.doOutput = true
        c.setRequestProperty("Content-Type", "multipart/form-data; boundary=$b")
        c.outputStream.use { o ->
            fun field(k: String, v: String) = o.write("--$b\r\nContent-Disposition: form-data; name=\"$k\"\r\n\r\n$v\r\n".toByteArray())
            field("chat_id", chatId)
            if (caption.isNotBlank()) field("caption", caption)
            o.write("--$b\r\nContent-Disposition: form-data; name=\"document\"; filename=\"$name\"\r\nContent-Type: application/octet-stream\r\n\r\n".toByteArray())
            o.write(bytes)
            o.write("\r\n--$b--\r\n".toByteArray())
        }
        val s = (if (c.responseCode < 400) c.inputStream else c.errorStream)?.use { it.readBytes().toString(Charsets.UTF_8) } ?: ""
        c.disconnect()
        val j = runCatching { JSONObject(s) }.getOrNull()
        if (j?.optBoolean("ok") != true) throw IllegalStateException(j?.optString("description")?.ifBlank { null } ?: "به بله نرفت (${c.responseCode})")
        return "ok"
    }

    /** Download folder (Android 10+) and the share sheet (Excel, Bale, Drive…). */
    private fun saveFile(name: String, mime: String, bytes: ByteArray): String {
        var where = ""
        if (Build.VERSION.SDK_INT >= 29) runCatching {
            val v = ContentValues().apply {
                put(MediaStore.Downloads.DISPLAY_NAME, name)
                put(MediaStore.Downloads.MIME_TYPE, mime)
            }
            context.contentResolver.insert(MediaStore.Downloads.EXTERNAL_CONTENT_URI, v)?.let { uri ->
                context.contentResolver.openOutputStream(uri)?.use { it.write(bytes) }
                where = "در پوشه‌ی Download ذخیره شد"
            }
        }
        val dir = File(context.cacheDir, "share").apply { mkdirs() }
        val f = File(dir, name).apply { writeBytes(bytes) }
        val uri = FileProvider.getUriForFile(context, context.packageName + ".files", f)
        main.post {
            runCatching {
                val chooser = Intent.createChooser(Intent(Intent.ACTION_SEND).setType(mime)
                    .putExtra(Intent.EXTRA_STREAM, uri).addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION), name)
                if (context !is Activity) chooser.addFlags(Intent.FLAG_ACTIVITY_NEW_TASK)
                context.startActivity(chooser)
            }
        }
        return where
    }

    /** window.BANative for app/local/engine.js */
    inner class Bridge(private val web: () -> WebView?) {
        @JavascriptInterface fun load(): String? = runCatching { if (books.exists()) books.readText() else null }.getOrNull()

        @JavascriptInterface fun save(data: String) {
            synchronized(Shared) {
                val tmp = File(books.parentFile, books.name + ".tmp")
                tmp.writeText(data)
                tmp.renameTo(books)
                Shared.rev++
            }
        }

        /** Changes on every save (by the app or the orb): the other copy then reads the file again. */
        @JavascriptInterface fun rev(): String = synchronized(Shared) { Shared.rev.toString() }

        /** One reader of Bale at a time (getUpdates' offset is in the books). */
        @JavascriptInterface fun lock(): Boolean = synchronized(Shared) {
            val now = System.currentTimeMillis()
            if (now < Shared.lockUntil) false else { Shared.lockUntil = now + 90_000; true }
        }
        @JavascriptInterface fun unlock() = synchronized(Shared) { Shared.lockUntil = 0L }

        @JavascriptInterface fun saveFile(id: String, name: String, mime: String, b64: String) = background(id) {
            this@LocalMode.saveFile(safeName(name), mime, Base64.decode(b64, Base64.DEFAULT))
        }

        @JavascriptInterface fun sendDocument(id: String, chatId: String, name: String, b64: String, caption: String) = background(id) {
            this@LocalMode.sendDocument(chatId, safeName(name), Base64.decode(b64, Base64.DEFAULT), caption)
        }

        /** Leave the mode without a server (settings ← «تغییر حالت»). */
        @JavascriptInterface fun changeMode() { main.post { (context as? MainActivity)?.leaveLocal() } }

        private fun background(id: String, work: () -> String) {
            Thread {
                val r = runCatching(work)
                val ok = r.isSuccess
                val msg = JSONObject.quote(r.getOrNull() ?: r.exceptionOrNull()?.message ?: "")
                main.post { web()?.evaluateJavascript("window.__baCb && window.__baCb(${JSONObject.quote(id)}, $ok, $msg)", null) }
            }.start()
        }

        private fun safeName(n: String) = n.replace(Regex("[^\\w.\\-]"), "_").take(80).ifBlank { "file" }
    }

    /** The same books for every copy of the engine in this process (the app's page and the orb's). */
    private object Shared {
        var rev = 0L
        var lockUntil = 0L
    }
}
