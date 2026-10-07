package ir.example.bankassistant

import org.json.JSONArray
import org.json.JSONObject
import java.io.ByteArrayOutputStream
import java.io.File
import java.io.IOException
import java.net.HttpURLConnection
import java.net.URL
import java.util.UUID

class ApiException(message: String, val code: Int = 0) : IOException(message)

/** server/api.php routes for a paired device (Authorization: Bearer <device token>). Call off the main thread. */
class Api(private val prefs: Prefs) {

    data class Reply(val heard: String, val reply: String, val state: String)
    data class Pending(val id: Int, val incoming: Boolean, val amountRial: Long)

    fun redeem(base: String, code: String, deviceName: String): String {
        val r = postJson(base + "api.php", "assistant_redeem", JSONObject().put("code", code).put("device_name", deviceName), null)
        return JSONObject(String(r)).getString("token")
    }

    fun ask(text: String): Reply {
        val o = JSONObject(String(postJson(api(), "assistant_ask", JSONObject().put("text", text), token())))
        return Reply(o.optString("heard"), o.optString("reply"), o.optString("state"))
    }

    fun transcribe(wav: File): String {
        val boundary = "B-" + UUID.randomUUID()
        val body = ByteArrayOutputStream().apply {
            write("--$boundary\r\nContent-Disposition: form-data; name=\"audio\"; filename=\"speech.wav\"\r\nContent-Type: audio/wav\r\n\r\n".toByteArray())
            write(wav.readBytes())
            write("\r\n--$boundary--\r\n".toByteArray())
        }.toByteArray()
        val r = send(api(), "assistant_transcribe", body, "multipart/form-data; boundary=$boundary", token())
        return JSONObject(String(r)).optString("text").trim()
    }

    /** Persian speech (mp3) from the server's local voice. */
    fun speak(text: String): ByteArray = postJson(api(), "assistant_speak", JSONObject().put("text", text), token())

    fun pending(): List<Pending> {
        val a: JSONArray = JSONObject(String(postJson(api(), "assistant_pending", JSONObject(), token()))).getJSONArray("items")
        return (0 until a.length()).map { i ->
            val t = a.getJSONObject(i)
            Pending(t.getInt("id"), t.getString("direction") == "in", t.getLong("amount"))
        }
    }

    // ---- plumbing

    private fun api() = prefs.api ?: throw ApiException("اپ هنوز به سرور وصل نشده", 401)
    private fun token() = prefs.deviceToken ?: throw ApiException("اپ هنوز به حسابداری وصل نشده", 401)

    private fun postJson(url: String, route: String, body: JSONObject, token: String?) =
        send(url, route, body.toString().toByteArray(), "application/json; charset=utf-8", token)

    private fun send(url: String, route: String, body: ByteArray, type: String, token: String?): ByteArray {
        val c = (URL("$url?r=$route").openConnection() as HttpURLConnection).apply {
            requestMethod = "POST"
            connectTimeout = 15_000
            readTimeout = 60_000
            doOutput = true
            useCaches = false
            setRequestProperty("Content-Type", type)
            if (token != null) {
                setRequestProperty("Authorization", "Bearer $token")
                setRequestProperty("X-Auth-Token", token)          // Apache may drop Authorization
            }
        }
        try {
            c.outputStream.use { it.write(body) }
            val code = c.responseCode
            val stream = if (code in 200..299) c.inputStream else c.errorStream
            val bytes = stream?.use { it.readBytes() } ?: ByteArray(0)
            if (code == 401) throw ApiException("دسترسی این گوشی لغو شده؛ دوباره وصل کن", 401)
            if (code !in 200..299) {
                val msg = runCatching { JSONObject(String(bytes)).optString("error") }.getOrNull()
                throw ApiException(if (msg.isNullOrBlank()) "خطای سرور ($code)" else msg, code)
            }
            return bytes
        } catch (e: ApiException) {
            throw e
        } catch (e: IOException) {
            throw ApiException("سرور در دسترس نیست: ${e.message}")
        } finally {
            c.disconnect()
        }
    }
}
