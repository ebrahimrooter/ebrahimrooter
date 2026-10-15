package ir.example.bankassistant

import android.app.AlarmManager
import android.app.PendingIntent
import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent
import android.os.SystemClock

/**
 * The mode without a server, screen off: Android lets the network sleep (Doze). Every few minutes
 * (allowed while idle) the orb's books look at the link to the ESP32 again, so a bank SMS becomes
 * a notification even in a pocket. With «run in the background» allowed it is near instant.
 */
class KeepAlive : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        OrbService.keepAlive(context)
        if (OrbService.running) schedule(context)
    }

    companion object {
        private const val EVERY_MS = 4 * 60_000L

        private fun pending(c: Context) = PendingIntent.getBroadcast(c, 9, Intent(c, KeepAlive::class.java),
            PendingIntent.FLAG_IMMUTABLE or PendingIntent.FLAG_UPDATE_CURRENT)

        fun schedule(c: Context) {
            val am = c.getSystemService(Context.ALARM_SERVICE) as AlarmManager
            am.setAndAllowWhileIdle(AlarmManager.ELAPSED_REALTIME_WAKEUP, SystemClock.elapsedRealtime() + EVERY_MS, pending(c))
        }

        fun cancel(c: Context) {
            (c.getSystemService(Context.ALARM_SERVICE) as AlarmManager).cancel(pending(c))
        }
    }
}
