package ir.example.bankassistant

import android.content.Context
import android.content.SharedPreferences
import androidx.security.crypto.EncryptedSharedPreferences
import androidx.security.crypto.MasterKey

/** Server address (not secret) and the device token (encrypted with the Android Keystore). */
class Prefs(context: Context) {
    private val plain: SharedPreferences = context.getSharedPreferences("settings", Context.MODE_PRIVATE)
    private val secret: SharedPreferences = EncryptedSharedPreferences.create(
        context, "secret",
        MasterKey.Builder(context).setKeyScheme(MasterKey.KeyScheme.AES256_GCM).build(),
        EncryptedSharedPreferences.PrefKeyEncryptionScheme.AES256_SIV,
        EncryptedSharedPreferences.PrefValueEncryptionScheme.AES256_GCM,
    )

    /** e.g. https://example.com/bank/  (the folder that holds api.php and app/) */
    var base: String?
        get() = plain.getString("base", null)
        set(v) = plain.edit().putString("base", v).apply()

    var deviceToken: String?
        get() = secret.getString("device_token", null)
        set(v) = secret.edit().putString("device_token", v).apply()

    /** The user turned the floating orb on (restarted after reboot / update). */
    var orbEnabled: Boolean
        get() = plain.getBoolean("orb_enabled", false)
        set(v) = plain.edit().putBoolean("orb_enabled", v).apply()

    /** Ask «بابت چی بود؟» out loud as soon as a bank transaction arrives. */
    var autoAsk: Boolean
        get() = plain.getBoolean("auto_ask", true)
        set(v) = plain.edit().putBoolean("auto_ask", v).apply()

    var orbX: Int
        get() = plain.getInt("orb_x", -1)
        set(v) = plain.edit().putInt("orb_x", v).apply()
    var orbY: Int
        get() = plain.getInt("orb_y", -1)
        set(v) = plain.edit().putInt("orb_y", v).apply()

    /** Pending transaction ids already announced. */
    var seenPending: Set<String>
        get() = plain.getStringSet("seen_pending", emptySet()) ?: emptySet()
        set(v) = plain.edit().putStringSet("seen_pending", v).apply()

    val api: String? get() = base?.let { it + "api.php" }
    val paired: Boolean get() = base != null && deviceToken != null

    fun unpair() {
        deviceToken = null
        orbEnabled = false
        seenPending = emptySet()
    }

    companion object {
        /** «example.com/bank», «https://example.com/bank/app/» … → «https://example.com/bank/». */
        fun normalizeBase(input: String): String? {
            var s = input.trim()
            if (s.isEmpty()) return null
            if (!s.startsWith("http://") && !s.startsWith("https://")) s = "https://$s"
            s = s.substringBefore('#').substringBefore('?')
            s = s.removeSuffix("/api.php").removeSuffix("/")
            s = s.removeSuffix("/app").removeSuffix("/acc").removeSuffix("/")
            val uri = android.net.Uri.parse(s)
            val host = uri.host ?: return null
            if (uri.scheme == "http" && !isLocal(host)) return null
            return "$s/"
        }

        fun isLocal(host: String) = host == "localhost" || host == "10.0.2.2" || host.startsWith("192.168.") ||
            host.startsWith("10.") || Regex("^172\\.(1[6-9]|2\\d|3[01])\\.").containsMatchIn(host)
    }
}
