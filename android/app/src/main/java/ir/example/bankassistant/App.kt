package ir.example.bankassistant

import android.app.Application
import android.os.Build
import java.io.File

/**
 * If the app ever crashes: the error is kept (shown on the next start, to copy and send) and the
 * floating orb is not started again by itself, so one bad part can't keep the app from opening.
 */
class App : Application() {
    override fun onCreate() {
        super.onCreate()
        val before = Thread.getDefaultUncaughtExceptionHandler()
        Thread.setDefaultUncaughtExceptionHandler { t, e ->
            runCatching {
                Prefs(this).orbEnabled = false
                crashFile(this).writeText("نسخه ${BuildConfig.VERSION_NAME} · ${Build.MANUFACTURER} ${Build.MODEL} · Android ${Build.VERSION.RELEASE}\n" +
                    "${t.name}: ${e.stackTraceToString().take(6000)}")
            }
            before?.uncaughtException(t, e)
        }
    }

    companion object {
        fun crashFile(c: android.content.Context) = File(c.filesDir, "last-crash.txt")
    }
}
