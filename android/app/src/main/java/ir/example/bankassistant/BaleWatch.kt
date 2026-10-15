package ir.example.bankassistant

import android.Manifest
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.content.Intent
import android.content.pm.PackageManager
import android.os.Build
import android.util.Log
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import java.text.SimpleDateFormat
import java.util.Date
import java.util.Locale
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
        runCatching { posted(sbn) }   // someone else's notification must never close the app
    }

    private fun posted(sbn: StatusBarNotification) {
        if (!watched(sbn.packageName)) return
        val prefs = Prefs(this)
        val x = sbn.notification.extras
        val texts = mutableListOf<String>()
        x.getCharSequence(Notification.EXTRA_BIG_TEXT)?.let { texts += it.toString() }
        x.getCharSequence(Notification.EXTRA_TEXT)?.let { texts += it.toString() }
        x.getCharSequenceArray(Notification.EXTRA_TEXT_LINES)?.forEach { texts += it.toString() }
        @Suppress("DEPRECATION")
        x.getParcelableArray(Notification.EXTRA_MESSAGES)?.forEach { m ->
            (m as? android.os.Bundle)?.getCharSequence("text")?.let { texts += it.toString() }
        }
        val title = x.getCharSequence(Notification.EXTRA_TITLE)?.toString() ?: ""
        val bank = texts.filter { looksLikeBank(it) }.distinct()
        // what settings show: does the app see Bale's notifications at all, and what was in them
        prefs.baleLast = SimpleDateFormat("HH:mm", Locale.US).format(Date()) + " · " + title.take(30) + " · " +
            (texts.firstOrNull() ?: "(بدون متن)").replace('\n', ' ').take(70) + if (bank.isNotEmpty()) " ✓" else ""
        if (!prefs.local) return
        bank.forEach { t ->
            val h = t.hashCode()
            synchronized(recent) {
                if (h in recent) return@forEach
                recent.addLast(h)
                if (recent.size > 50) recent.removeFirst()
            }
            Log.i("BankOrb", "bale notification: bank SMS")
            if (!OrbService.baleNotice(this, t)) alert()
        }
    }

    /** The ESP32's message (between its separators), or a bank SMS shown as it is. */
    private fun looksLikeBank(t: String) = t.contains("──────────") ||
        (Regex("واریز|برداشت|انتقال|خرید|مانده|موجودی|برداشت").containsMatchIn(t) && Regex("[0-9۰-۹]{3}").containsMatchIn(t))

    /** No orb on the screen: a notification of ours that opens the app (which then asks). */
    private fun alert() {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel("tx", "واریز و برداشت", NotificationManager.IMPORTANCE_HIGH))
        val open = PendingIntent.getActivity(this, 4, Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)
        nm.notify(200, NotificationCompat.Builder(this, "tx")
            .setSmallIcon(R.drawable.ic_orb_small)
            .setContentTitle("پیامک بانک از بله رسید")
            .setContentText("ثبت شد؛ بزن تا بپرسم بابت چی بود.")
            .setAutoCancel(true)
            .setContentIntent(open)
            .build())
    }
}
