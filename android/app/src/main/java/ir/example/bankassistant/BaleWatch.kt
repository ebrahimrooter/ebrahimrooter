package ir.example.bankassistant

import android.app.Notification
import android.content.ComponentName
import android.content.Context
import android.provider.Settings
import android.service.notification.NotificationListenerService
import android.service.notification.StatusBarNotification

/**
 * The app without a server: a Bale bot never sees its own messages, so the app would only hear of
 * a bank SMS after a tap in Bale. With «notification access», the Bale notification of the ESP32's
 * message is enough: it goes to the floating orb, which books it and asks «بابت چی بود؟» right away.
 */
class BaleWatch : NotificationListenerService() {

    companion object {
        /** Bale (and, in test builds, `adb shell cmd notification post`). */
        private fun watched(pkg: String) = pkg.startsWith("ir.nasim") || pkg.contains("bale", ignoreCase = true) ||
            (BuildConfig.DEBUG && pkg == "com.android.shell")

        fun allowed(c: Context): Boolean {
            val me = ComponentName(c, BaleWatch::class.java).flattenToString()
            return (Settings.Secure.getString(c.contentResolver, "enabled_notification_listeners") ?: "").split(':').any { it == me }
        }

        private val recent = ArrayDeque<Int>()
    }

    override fun onNotificationPosted(sbn: StatusBarNotification) {
        if (!watched(sbn.packageName)) return
        val prefs = Prefs(this)
        if (!prefs.local) return
        val x = sbn.notification.extras
        val texts = mutableListOf<String>()
        x.getCharSequence(Notification.EXTRA_BIG_TEXT)?.let { texts += it.toString() }
        x.getCharSequence(Notification.EXTRA_TEXT)?.let { texts += it.toString() }
        x.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.forEach { texts += it.toString() }
        @Suppress("DEPRECATION")
        x.getParcelableArray(Notification.EXTRA_MESSAGES)?.forEach { m ->
            (m as? android.os.Bundle)?.getCharSequence("text")?.let { texts += it.toString() }
        }
        texts.filter { it.contains("──────────") }.distinct().forEach { t ->
            val h = t.hashCode()
            synchronized(recent) {
                if (h in recent) return@forEach
                recent.addLast(h)
                if (recent.size > 50) recent.removeFirst()
            }
            OrbService.baleNotice(this, t)
        }
    }
}
