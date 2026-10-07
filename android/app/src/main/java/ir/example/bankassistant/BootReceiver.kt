package ir.example.bankassistant

import android.content.BroadcastReceiver
import android.content.Context
import android.content.Intent

/** Puts the orb back after a reboot or an app update, if the user had it on. */
class BootReceiver : BroadcastReceiver() {
    override fun onReceive(context: Context, intent: Intent) {
        val prefs = Prefs(context)
        if (prefs.orbEnabled && prefs.paired && OrbService.canDrawOverlays(context)) {
            runCatching { OrbService.start(context) }
        }
    }
}
