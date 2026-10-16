package ir.example.bankassistant

import android.content.Context
import android.util.Base64
import android.util.Log
import org.json.JSONObject
import java.io.DataInputStream
import java.io.File
import java.io.OutputStream
import java.net.InetSocketAddress
import java.net.Socket
import java.net.SocketTimeoutException
import java.net.URI
import java.security.MessageDigest
import javax.crypto.Mac
import javax.crypto.spec.SecretKeySpec

/**
 * The link to the ESP32 inside the orb's foreground service, so a bank SMS becomes a notification
 * while the app is closed or the screen is off (the WebView of the books sleeps then).
 * It only listens: the books (app/local/engine.js) book the SMS and send the ack, so the ESP32
 * keeps it on the SIM until it is really in the books. Same sealing as app/local/link.js.
 */
class NativeLink(private val context: Context, private val onSms: (JSONObject) -> Unit) {
    @Volatile private var stopped = false
    @Volatile private var socket: Socket? = null
    private var thread: Thread? = null
    private val seen = HashSet<String>()
    @Volatile var state = "offline"
        private set

    fun start() {
        if (thread?.isAlive == true) return
        stopped = false
        thread = Thread({ run() }, "bank-link").apply { isDaemon = true; start() }
    }

    fun stop() {
        stopped = true
        runCatching { socket?.close() }
    }

    /** KeepAlive: a dead connection is noticed now instead of at the next read timeout. */
    fun poke() {
        val s = socket
        if (s == null || s.isClosed) { start(); return }
        Thread { runCatching { synchronized(this) { s.getOutputStream().write(byteArrayOf(0xc0.toByte(), 0)) } }.onFailure { runCatching { s.close() } } }.start()
    }

    private fun books(): JSONObject? = runCatching { JSONObject(File(context.filesDir, "local-books.json").readText()) }.getOrNull()

    private fun run() {
        var wait = 3_000L
        while (!stopped) {
            val link = books()?.optJSONObject("link")
            val code = link?.optString("code")?.takeIf { it.isNotBlank() }
            if (code == null) { sleep(10_000); continue }
            val (host, port) = endpoint(link.optString("broker").takeIf { it.isNotBlank() })
            val k = Keys(code)
            try {
                val s = Socket()
                s.connect(InetSocketAddress(host, port), 15_000)
                s.soTimeout = 60_000
                socket = s
                val out = s.getOutputStream()
                val inp = DataInputStream(s.getInputStream())
                send(out, 0x10, str("MQTT") + byteArrayOf(4, 0x02, 0, 240.toByte()) + str("ba-orb-" + k.id.take(6) + "-" + (System.nanoTime() % 100000)))
                if (read(inp).first shr 4 != 2) throw IllegalStateException("broker refused")
                send(out, 0x82, byteArrayOf(0, 1) + str("ba/${k.id}/sms/+") + byteArrayOf(0))
                state = "online"
                wait = 3_000L
                Log.i(TAG, "link up ($host:$port)")
                while (!stopped) {
                    val (head, body) = try { read(inp) } catch (e: SocketTimeoutException) {
                        synchronized(this) { out.write(byteArrayOf(0xc0.toByte(), 0)) }      // PINGREQ
                        continue
                    }
                    if (head shr 4 != 3) continue
                    val tl = ((body[0].toInt() and 0xff) shl 8) or (body[1].toInt() and 0xff)
                    var p = 2 + tl
                    if ((head shr 1) and 3 != 0) p += 2
                    val payload = String(body, p, body.size - p, Charsets.UTF_8)
                    if (payload.isEmpty()) continue
                    val m = k.open(payload) ?: continue
                    val id = m.optString("id")
                    if (id.isEmpty() || !seen.add(id)) continue
                    val acked = books()?.optJSONObject("link")?.optJSONArray("acked")
                    if (acked != null && (0 until acked.length()).any { acked.optString(it) == id }) continue
                    Log.i(TAG, "bank SMS from the ESP32: $id")
                    runCatching { onSms(m) }
                }
            } catch (e: Exception) {
                if (!stopped) Log.w(TAG, "link: ${e.message}")
            } finally {
                state = "offline"
                runCatching { socket?.close() }
                socket = null
            }
            if (!stopped) { sleep(wait); wait = (wait * 2).coerceAtMost(60_000) }
        }
    }

    private fun sleep(ms: Long) = runCatching { Thread.sleep(ms) }

    /** The app's broker is a WebSocket URL; the same broker answers plain MQTT on 1883 (like the ESP32). */
    private fun endpoint(broker: String?): Pair<String, Int> {
        val test = if (BuildConfig.DEBUG) Prefs(context).testMqttUrl else null
        val u = runCatching { URI(test ?: broker ?: DEFAULT) }.getOrNull()
        return Pair(u?.host ?: "broker.emqx.io", 1883)
    }

    private fun str(s: String): ByteArray { val b = s.toByteArray(); return byteArrayOf((b.size shr 8).toByte(), b.size.toByte()) + b }

    private fun send(out: OutputStream, type: Int, body: ByteArray) {
        val len = ArrayList<Byte>()
        var n = body.size
        do { var d = n % 128; n /= 128; if (n > 0) d = d or 128; len.add(d.toByte()) } while (n > 0)
        synchronized(this) { out.write(byteArrayOf(type.toByte()) + len.toByteArray() + body); out.flush() }
    }

    private fun read(inp: DataInputStream): Pair<Int, ByteArray> {
        val head = inp.readUnsignedByte()
        var len = 0; var mul = 1
        while (true) { val b = inp.readUnsignedByte(); len += (b and 127) * mul; mul *= 128; if (b and 128 == 0) break }
        val body = ByteArray(len)
        inp.readFully(body)
        return Pair(head, body)
    }

    /** Same as app/local/link.js keys/open: HMAC-SHA256 stream + 16-byte tag. */
    private class Keys(code: String) {
        val ke: ByteArray; val km: ByteArray; val id: String
        init {
            val clean = code.uppercase().filter { it in 'A'..'Z' || it in '0'..'9' }
            val master = MessageDigest.getInstance("SHA-256").digest(("ba-link-v1:$clean").toByteArray())
            ke = hmac(master, "enc".toByteArray()); km = hmac(master, "mac".toByteArray())
            id = hmac(master, "topic".toByteArray()).joinToString("") { "%02x".format(it) }.take(16)
        }
        fun open(text: String): JSONObject? {
            val b = runCatching { Base64.decode(text.trim(), Base64.DEFAULT) }.getOrNull() ?: return null
            if (b.size < 28) return null
            val iv = b.copyOfRange(0, 12); val ct = b.copyOfRange(12, b.size - 16); val tag = b.copyOfRange(b.size - 16, b.size)
            if (!MessageDigest.isEqual(hmac(km, iv + ct).copyOf(16), tag)) return null
            val pt = ByteArray(ct.size)
            var blk = 0
            while (blk * 32 < ct.size) {
                val ks = hmac(ke, iv + byteArrayOf((blk shr 24).toByte(), (blk shr 16).toByte(), (blk shr 8).toByte(), blk.toByte()))
                for (i in 0 until 32) { val j = blk * 32 + i; if (j < ct.size) pt[j] = (ct[j].toInt() xor ks[i].toInt()).toByte() }
                blk++
            }
            return runCatching { JSONObject(String(pt, Charsets.UTF_8)) }.getOrNull()
        }
        companion object {
            fun hmac(key: ByteArray, msg: ByteArray): ByteArray =
                Mac.getInstance("HmacSHA256").apply { init(SecretKeySpec(key, "HmacSHA256")) }.doFinal(msg)
        }
    }

    companion object {
        private const val TAG = "BankOrb"
        private const val DEFAULT = "wss://broker.emqx.io:8084/mqtt"

        /** The code of a bank one-time password («رمز پویا: 48213967»); null when it is not one. */
        fun otpOf(text: String): String? {
            val t = text.map { c -> if (c in '۰'..'۹') ('0' + (c - '۰')) else if (c in '٠'..'٩') ('0' + (c - '٠')) else c }.joinToString("")
            if (!(t.contains("رمز") && (t.contains("پویا") || t.contains("یکبار") || t.contains("یک بار"))) && !t.contains("کد تایید") && !t.contains("کد تأیید")) return null
            for (line in t.lines()) {
                if (!Regex("رمز|کد").containsMatchIn(line)) continue
                Regex("(?<![0-9])([0-9]{4,10})(?![0-9])").find(line.replace(Regex("[0-9,]+\\s*(ریال|تومان)"), " "))?.let { return it.groupValues[1] }
            }
            return ""
        }

        /** «برداشت:2,500,000» → (false, 2500000 rial); null when it is not a deposit / withdrawal. */
        fun amountOf(text: String): Pair<Boolean, Long>? {
            val t = text.map { c -> if (c in '۰'..'۹') ('0' + (c - '۰')) else if (c in '٠'..'٩') ('0' + (c - '٠')) else c }.joinToString("")
            val m = Regex("(واریز|برداشت|انتقال|خرید)[^0-9]{0,12}([0-9][0-9,٬]*)").find(t) ?: return null
            val v = m.groupValues[2].filter { it.isDigit() }.toLongOrNull() ?: return null
            return Pair(m.groupValues[1] == "واریز", v)
        }
    }
}
