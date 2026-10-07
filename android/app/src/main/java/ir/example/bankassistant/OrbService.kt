package ir.example.bankassistant

import android.Manifest
import android.animation.ValueAnimator
import android.annotation.SuppressLint
import android.app.Notification
import android.app.NotificationChannel
import android.app.NotificationManager
import android.app.PendingIntent
import android.app.Service
import android.content.Context
import android.content.Intent
import android.content.pm.PackageManager
import android.content.pm.ServiceInfo
import android.graphics.Color
import android.graphics.PixelFormat
import android.graphics.drawable.GradientDrawable
import android.os.Build
import android.os.IBinder
import android.provider.Settings
import android.util.TypedValue
import android.view.Gravity
import android.view.MotionEvent
import android.view.View
import android.view.WindowManager
import android.view.animation.OvershootInterpolator
import android.view.ViewGroup
import android.widget.FrameLayout
import android.widget.LinearLayout
import android.widget.TextView
import android.widget.Toast
import androidx.core.app.NotificationCompat
import androidx.core.content.ContextCompat
import kotlinx.coroutines.CoroutineScope
import kotlinx.coroutines.Dispatchers
import kotlinx.coroutines.Job
import kotlinx.coroutines.SupervisorJob
import kotlinx.coroutines.cancel
import kotlinx.coroutines.delay
import kotlinx.coroutines.isActive
import kotlinx.coroutines.launch
import kotlinx.coroutines.withContext
import java.text.NumberFormat
import java.util.Locale
import kotlin.math.abs
import kotlin.math.hypot

/**
 * The floating orb on the Home Screen and over other apps (Android allows it
 * with «Display over other apps»). Tap: talk to the accounting assistant
 * (server speech-to-text → answer → server voice, until «خداحافظ» or silence).
 * Drag: move it; drop on ✕: close. Long press: open the app. A new bank
 * transaction makes it pulse and ask «بابت چی بود؟».
 */
class OrbService : Service() {

    companion object {
        const val ACTION_STOP = "stop"
        const val ACTION_TALK = "talk"
        const val EXTRA_DEMO = "demo"
        private const val CHANNEL_ORB = "orb"
        private const val CHANNEL_TX = "tx"
        private const val NOTE_ID = 7

        fun canDrawOverlays(c: Context) = Settings.canDrawOverlays(c)

        fun start(c: Context, talk: Boolean = false) {
            Prefs(c).orbEnabled = true
            val i = Intent(c, OrbService::class.java)
            if (talk) i.action = ACTION_TALK
            ContextCompat.startForegroundService(c, i)
        }

        fun stop(c: Context) {
            Prefs(c).orbEnabled = false
            c.startService(Intent(c, OrbService::class.java).setAction(ACTION_STOP))
        }

        @Volatile var running = false
            private set
    }

    private lateinit var prefs: Prefs
    private lateinit var api: Api
    private lateinit var wm: WindowManager
    private val scope = CoroutineScope(SupervisorJob() + Dispatchers.Main)
    private var session: Job? = null
    private var poller: Job? = null
    private lateinit var recorder: Recorder
    private lateinit var speaker: Speaker

    private lateinit var orb: OrbView
    private lateinit var orbParams: WindowManager.LayoutParams
    // the black card (reference design): turning line sphere, title, what it says
    private lateinit var card: LinearLayout
    private lateinit var cardParams: WindowManager.LayoutParams
    private lateinit var cardOrb: OrbView
    private lateinit var cardTitle: TextView
    private lateinit var cardText: TextView
    private var trash: View? = null
    private var demo = false

    private val orbSize by lazy { dp(68) }

    override fun onBind(intent: Intent?): IBinder? = null

    override fun onCreate() {
        super.onCreate()
        prefs = Prefs(this)
        api = Api(prefs)
        wm = getSystemService(WINDOW_SERVICE) as WindowManager
        recorder = Recorder(cacheDir)
        speaker = Speaker(cacheDir)
        channels()
    }

    override fun onStartCommand(intent: Intent?, flags: Int, startId: Int): Int {
        if (intent?.action == ACTION_STOP) {
            prefs.orbEnabled = false
            stopSelf()
            return START_NOT_STICKY
        }
        demo = intent?.getBooleanExtra(EXTRA_DEMO, false) == true
        goForeground(withMic = hasMic())
        if (!canDrawOverlays(this)) {
            Toast.makeText(this, "اول اجازه‌ی «نمایش روی برنامه‌های دیگر» را بده", Toast.LENGTH_LONG).show()
            stopSelf()
            return START_NOT_STICKY
        }
        if (!running) {
            running = true
            addViews()
            if (demo) showDemo() else startPolling()
        }
        if (intent?.action == ACTION_TALK) onTap()
        return START_STICKY
    }

    override fun onDestroy() {
        running = false
        scope.cancel()
        recorder.cancel()
        speaker.stop()
        runCatching { wm.removeView(orb) }
        runCatching { wm.removeView(card) }
        trash?.let { runCatching { wm.removeView(it) } }
        super.onDestroy()
    }

    // ---------------------------------------------------------------- windows

    private fun overlayType() = WindowManager.LayoutParams.TYPE_APPLICATION_OVERLAY

    @SuppressLint("ClickableViewAccessibility")
    private fun addViews() {
        val screen = resources.displayMetrics
        orb = OrbView(this)
        orbParams = WindowManager.LayoutParams(orbSize, orbSize, overlayType(),
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT).apply {
            gravity = Gravity.TOP or Gravity.START
            x = if (prefs.orbX >= 0) prefs.orbX else screen.widthPixels - orbSize - dp(8)
            y = if (prefs.orbY >= 0) prefs.orbY else (screen.heightPixels * 0.62).toInt()
        }
        orb.elevation = dp(8).toFloat()
        orb.contentDescription = "دستیار حسابداری"
        wm.addView(orb, orbParams)

        cardOrb = OrbView(this, disc = false)
        cardTitle = TextView(this).apply {
            setTextColor(Color.WHITE)
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 19f)
            typeface = android.graphics.Typeface.DEFAULT_BOLD
            gravity = Gravity.CENTER
            textDirection = View.TEXT_DIRECTION_RTL
        }
        cardText = TextView(this).apply {
            setTextColor(Color.argb(190, 255, 255, 255))
            setTextSize(TypedValue.COMPLEX_UNIT_SP, 15f)
            gravity = Gravity.CENTER
            textDirection = View.TEXT_DIRECTION_RTL
            setLineSpacing(0f, 1.25f)
            maxLines = 6
        }
        card = LinearLayout(this).apply {
            orientation = LinearLayout.VERTICAL
            gravity = Gravity.CENTER_HORIZONTAL
            setPadding(dp(24), dp(28), dp(24), dp(26))
            background = GradientDrawable().apply {
                cornerRadius = dp(40).toFloat()
                setColor(Color.BLACK)
                setStroke(dp(1), Color.argb(40, 255, 255, 255))
            }
            elevation = dp(16).toFloat()
            addView(cardOrb, LinearLayout.LayoutParams(dp(150), dp(150)).apply { bottomMargin = dp(22) })
            addView(cardTitle, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT))
            addView(cardText, LinearLayout.LayoutParams(ViewGroup.LayoutParams.MATCH_PARENT, ViewGroup.LayoutParams.WRAP_CONTENT).apply { topMargin = dp(10) })
            visibility = View.GONE
            setOnClickListener { onTap() }
        }
        cardParams = WindowManager.LayoutParams(
            minOf(screen.widthPixels - dp(32), dp(420)), WindowManager.LayoutParams.WRAP_CONTENT, overlayType(),
            WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_LAYOUT_NO_LIMITS,
            PixelFormat.TRANSLUCENT).apply {
            gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL
            y = dp(36)
            windowAnimations = android.R.style.Animation_Toast
        }
        wm.addView(card, cardParams)

        var downX = 0f; var downY = 0f; var startX = 0; var startY = 0
        var dragging = false; var downAt = 0L
        val longPress = Runnable { if (!dragging) openApp() }
        orb.setOnTouchListener { _, e ->
            when (e.actionMasked) {
                MotionEvent.ACTION_DOWN -> {
                    downX = e.rawX; downY = e.rawY; startX = orbParams.x; startY = orbParams.y
                    dragging = false; downAt = System.currentTimeMillis()
                    orb.postDelayed(longPress, 650)
                    orb.animate().scaleX(0.92f).scaleY(0.92f).setDuration(90).start()
                }
                MotionEvent.ACTION_MOVE -> {
                    val dx = e.rawX - downX; val dy = e.rawY - downY
                    if (!dragging && hypot(dx, dy) > dp(8)) {
                        dragging = true
                        orb.removeCallbacks(longPress)
                        showTrash(true)
                    }
                    if (dragging) {
                        orbParams.x = startX + dx.toInt(); orbParams.y = startY + dy.toInt()
                        wm.updateViewLayout(orb, orbParams)
                        placeBubble()
                        highlightTrash(overTrash())
                    }
                }
                MotionEvent.ACTION_UP, MotionEvent.ACTION_CANCEL -> {
                    orb.removeCallbacks(longPress)
                    orb.animate().scaleX(1f).scaleY(1f).setDuration(120).start()
                    if (dragging) {
                        val close = overTrash()
                        showTrash(false)
                        if (close) { prefs.orbEnabled = false; stopSelf() } else snapToEdge()
                    } else if (e.actionMasked == MotionEvent.ACTION_UP && System.currentTimeMillis() - downAt < 600) {
                        onTap()
                    }
                }
            }
            true
        }
        // appear
        orb.scaleX = 0f; orb.scaleY = 0f
        orb.animate().scaleX(1f).scaleY(1f).setInterpolator(OvershootInterpolator()).setDuration(350).start()
    }

    private fun snapToEdge() {
        val w = resources.displayMetrics.widthPixels
        val h = resources.displayMetrics.heightPixels
        val target = if (orbParams.x + orbSize / 2 < w / 2) dp(6) else w - orbSize - dp(6)
        orbParams.y = orbParams.y.coerceIn(dp(40), h - orbSize - dp(60))
        ValueAnimator.ofInt(orbParams.x, target).apply {
            duration = 260
            interpolator = OvershootInterpolator(1.2f)
            addUpdateListener {
                orbParams.x = it.animatedValue as Int
                runCatching { wm.updateViewLayout(orb, orbParams) }
                placeBubble()
            }
            start()
        }
        prefs.orbX = target; prefs.orbY = orbParams.y
    }

    private fun placeBubble() {}   // the card sits at the bottom of the screen

    private var cardTitleText = "دستیار حسابداری"

    /** Shows the card with [text] under the current title; null hides it. */
    private fun say(text: String?) {
        if (text.isNullOrBlank()) {
            if (card.visibility == View.VISIBLE) {
                card.animate().alpha(0f).translationY(dp(24).toFloat()).setDuration(220).withEndAction {
                    card.visibility = View.GONE
                    card.translationY = 0f
                }.start()
            }
            return
        }
        cardTitle.text = cardTitleText
        cardText.text = text
        if (card.visibility != View.VISIBLE) {
            card.visibility = View.VISIBLE
            card.alpha = 0f
            card.translationY = dp(30).toFloat()
            card.animate().alpha(1f).translationY(0f).setInterpolator(OvershootInterpolator(0.8f)).setDuration(320).start()
        }
    }

    private fun showTrash(show: Boolean) {
        if (show && trash == null) {
            val v = TextView(this).apply {
                text = "✕"
                setTextColor(Color.WHITE)
                setTextSize(TypedValue.COMPLEX_UNIT_SP, 22f)
                gravity = Gravity.CENTER
                background = GradientDrawable().apply { shape = GradientDrawable.OVAL; setColor(Color.argb(200, 20, 20, 20)) }
            }
            val p = WindowManager.LayoutParams(dp(64), dp(64), overlayType(),
                WindowManager.LayoutParams.FLAG_NOT_FOCUSABLE or WindowManager.LayoutParams.FLAG_NOT_TOUCHABLE,
                PixelFormat.TRANSLUCENT).apply { gravity = Gravity.BOTTOM or Gravity.CENTER_HORIZONTAL; y = dp(56) }
            wm.addView(v, p)
            trash = v
        } else if (!show) {
            trash?.let { runCatching { wm.removeView(it) } }
            trash = null
        }
    }

    private fun overTrash(): Boolean {
        val m = resources.displayMetrics
        val cx = orbParams.x + orbSize / 2f
        val cy = orbParams.y + orbSize / 2f
        return abs(cx - m.widthPixels / 2f) < dp(70) && (m.heightPixels - dp(88) - cy) < dp(90)
    }

    private fun highlightTrash(on: Boolean) {
        trash?.animate()?.scaleX(if (on) 1.25f else 1f)?.scaleY(if (on) 1.25f else 1f)?.setDuration(100)?.start()
    }

    private fun openApp() {
        startActivity(Intent(this, MainActivity::class.java).addFlags(Intent.FLAG_ACTIVITY_NEW_TASK))
    }

    // ---------------------------------------------------------------- voice

    private fun onTap() {
        if (session?.isActive == true) {
            // tap while it talks / listens: stop
            endSession("بسته شد")
            return
        }
        if (!prefs.paired && !demo) {
            say("اول اپ را باز کن و وارد شو تا دستیار وصل شود.")
            openApp()
            return
        }
        if (!hasMic()) {
            say("اجازه‌ی میکروفون لازم است؛ اپ را باز کن.")
            openApp()
            return
        }
        goForeground(withMic = true)
        session = scope.launch { converse(null) }
    }

    /** One conversation: [first] is asked without listening (e.g. a new bank transaction). */
    private suspend fun converse(first: String?) {
        orb.attention = false
        var next = first
        var silent = 0
        try {
            while (scope.isActive) {
                val heard: String
                if (next != null) {
                    heard = next
                    next = null
                } else {
                    set(Phase.LISTENING, "بگو؛ مثلاً «فروش امروز چقدر بود؟»")
                    val levelJob = scope.launch { while (isActive) { orb.level = recorder.level; cardOrb.level = recorder.level; delay(50) } }
                    val wav = try { withContext(Dispatchers.IO) { recorder.record() } } finally { levelJob.cancel() }
                    if (wav == null) {
                        silent++
                        if (silent >= 2) { endSession("چیزی نشنیدم؛ بسته شد"); return }
                        set(Phase.IDLE, "چیزی نشنیدم؛ دوباره بگو.")
                        continue
                    }
                    silent = 0
                    set(Phase.PROCESSING, "در حال فکر کردن…")
                    heard = try { withContext(Dispatchers.IO) { api.transcribe(wav) } } finally { wav.delete() }
                    if (heard.isBlank()) { set(Phase.IDLE, "نفهمیدم؛ دوباره بگو."); continue }
                    if (isGoodbye(heard)) { endSession("خداحافظ"); return }
                    say("«$heard»")
                }
                set(Phase.PROCESSING, if (first != null && heard == first) "دارم تراکنش‌های بانک را نگاه می‌کنم؛ چند لحظه صبر کن." else "«$heard»\nچند لحظه صبر کن…")
                val r = withContext(Dispatchers.IO) { api.ask(heard) }
                set(Phase.SPEAKING, r.reply)
                val audio = runCatching { withContext(Dispatchers.IO) { api.speak(r.reply) } }.getOrNull()
                if (audio != null) {
                    val levelJob = scope.launch { while (isActive) { orb.level = speaker.level; cardOrb.level = speaker.level; delay(50) } }
                    try { speaker.play(audio) } finally { levelJob.cancel() }
                } else {
                    delay((r.reply.length * 70L).coerceIn(1500, 6000))
                }
            }
        } catch (e: ApiException) {
            if (e.code == 401) { prefs.unpair(); endSession(e.message); return }
            set(Phase.ERROR, e.message ?: "خطا")
            delay(2500)
            endSession(null)
        } catch (e: kotlinx.coroutines.CancellationException) {
            throw e
        } catch (e: Exception) {
            set(Phase.ERROR, e.message ?: "خطا")
            delay(2500)
            endSession(null)
        }
    }

    private fun endSession(message: String?) {
        session?.cancel()
        session = null
        recorder.cancel()
        speaker.stop()
        orb.level = 0f
        cardOrb.level = 0f
        set(Phase.IDLE, message)
        card.postDelayed({ if (session?.isActive != true) say(null) }, if (message != null) 2500 else 300)
    }

    private fun set(p: Phase, text: String?) {
        orb.phase = p
        cardOrb.phase = p
        cardTitleText = when (p) {
            Phase.IDLE -> "دستیار حسابداری"
            Phase.SPEAKING -> "پاسخ"
            else -> p.title
        }
        say(text)
        updateNotification(if (p == Phase.IDLE) "روی orb بزن و بپرس" else p.title)
    }

    private fun isGoodbye(s: String) = listOf("خداحافظ", "تمام", "بسه", "کافیه", "تموم").any { s.trim().startsWith(it) }

    // ---------------------------------------------------------------- new bank transactions

    private fun startPolling() {
        poller?.cancel()
        poller = scope.launch {
            delay(3000)
            while (isActive) {
                if (prefs.paired && session?.isActive != true) {
                    runCatching { withContext(Dispatchers.IO) { api.pending() } }.onSuccess { list -> onPending(list) }
                }
                delay(30_000)
            }
        }
    }

    private fun onPending(list: List<Api.Pending>) {
        val seen = prefs.seenPending
        val fresh = list.filter { it.id.toString() !in seen }
        orb.attention = list.isNotEmpty()
        prefs.seenPending = list.map { it.id.toString() }.toSet()   // forget answered ones
        val t = fresh.firstOrNull() ?: return
        val what = (if (t.incoming) "واریز " else "برداشت ") + toman(t.amountRial) + " تومان"
        notifyTx(what)
        bounce()
        if (prefs.autoAsk && hasMic()) {
            goForeground(withMic = true)
            session = scope.launch { converse("تراکنش‌های بی‌جواب") }
        } else {
            say("$what — بابت چی بود؟ روی orb بزن و بگو.")
        }
    }

    private fun bounce() {
        orb.animate().scaleX(1.25f).scaleY(1.25f).setDuration(160).withEndAction {
            orb.animate().scaleX(1f).scaleY(1f).setInterpolator(OvershootInterpolator(3f)).setDuration(380).start()
        }.start()
    }

    private fun toman(rial: Long) = NumberFormat.getNumberInstance(Locale("fa", "IR")).format(rial / 10)

    private fun showDemo() {
        // sample state for emulator screenshots (adb … --ez demo true)
        orb.attention = true
        set(Phase.PROCESSING, "دارم تراکنش‌های بانک را نگاه می‌کنم؛ چند لحظه صبر کن.")
    }

    // ---------------------------------------------------------------- notifications

    private fun hasMic() = ContextCompat.checkSelfPermission(this, Manifest.permission.RECORD_AUDIO) == PackageManager.PERMISSION_GRANTED

    private fun channels() {
        val nm = getSystemService(NotificationManager::class.java)
        nm.createNotificationChannel(NotificationChannel(CHANNEL_ORB, "orb شناور", NotificationManager.IMPORTANCE_MIN).apply {
            description = "تا وقتی orb روی صفحه است"
            setShowBadge(false)
        })
        nm.createNotificationChannel(NotificationChannel(CHANNEL_TX, "واریز و برداشت", NotificationManager.IMPORTANCE_HIGH))
    }

    private fun note(text: String): Notification {
        val open = PendingIntent.getActivity(this, 0, Intent(this, MainActivity::class.java), PendingIntent.FLAG_IMMUTABLE)
        val stop = PendingIntent.getService(this, 1, Intent(this, OrbService::class.java).setAction(ACTION_STOP), PendingIntent.FLAG_IMMUTABLE)
        val talk = PendingIntent.getService(this, 2, Intent(this, OrbService::class.java).setAction(ACTION_TALK), PendingIntent.FLAG_IMMUTABLE)
        return NotificationCompat.Builder(this, CHANNEL_ORB)
            .setSmallIcon(R.drawable.ic_orb_small)
            .setContentTitle("دستیار حسابداری")
            .setContentText(text)
            .setOngoing(true)
            .setSilent(true)
            .setContentIntent(open)
            .addAction(0, "صحبت", talk)
            .addAction(0, "بستن orb", stop)
            .build()
    }

    private fun goForeground(withMic: Boolean) {
        val n = note("روی orb بزن و بپرس")
        if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.UPSIDE_DOWN_CAKE) {
            var type = ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE
            if (withMic) type = type or ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE
            try {
                startForeground(NOTE_ID, n, type)
            } catch (e: Exception) {
                // the microphone type can be refused from the background; the orb still shows
                startForeground(NOTE_ID, n, ServiceInfo.FOREGROUND_SERVICE_TYPE_SPECIAL_USE)
            }
        } else if (Build.VERSION.SDK_INT >= Build.VERSION_CODES.Q) {
            try {
                startForeground(NOTE_ID, n, if (withMic) ServiceInfo.FOREGROUND_SERVICE_TYPE_MICROPHONE else 0)
            } catch (e: Exception) {
                startForeground(NOTE_ID, n)
            }
        } else {
            startForeground(NOTE_ID, n)
        }
    }

    private fun updateNotification(text: String) {
        runCatching { getSystemService(NotificationManager::class.java).notify(NOTE_ID, note(text)) }
    }

    private fun notifyTx(what: String) {
        if (Build.VERSION.SDK_INT >= 33 &&
            ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return
        val talk = PendingIntent.getService(this, 3, Intent(this, OrbService::class.java).setAction(ACTION_TALK), PendingIntent.FLAG_IMMUTABLE)
        val n = NotificationCompat.Builder(this, CHANNEL_TX)
            .setSmallIcon(R.drawable.ic_orb_small)
            .setContentTitle(what)
            .setContentText("بابت چی بود؟ روی orb بزن و بگو.")
            .setAutoCancel(true)
            .setContentIntent(talk)
            .build()
        getSystemService(NotificationManager::class.java).notify(100 + (System.currentTimeMillis() % 1000).toInt(), n)
    }

    private fun dp(v: Int) = (v * resources.displayMetrics.density).toInt()
}
