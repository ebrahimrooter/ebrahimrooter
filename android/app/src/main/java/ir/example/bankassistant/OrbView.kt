package ir.example.bankassistant

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.view.View
import kotlin.math.PI
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin

enum class Phase(val title: String) {
    IDLE("آماده"), LISTENING("دارم گوش می‌دم…"), PROCESSING("در حال آماده کردن جواب…"), SPEAKING("پاسخ"), ERROR("خطا")
}

/**
 * The orb: a white sphere made of dashed vertical lines turning on black
 * (like the reference design). It turns faster while thinking, breathes with
 * the voice while listening / speaking. [disc] draws the black circle behind
 * it (the small floating orb); without it the sphere sits on the card.
 */
class OrbView(context: Context, private val disc: Boolean = true) : View(context) {
    var phase = Phase.IDLE
        set(v) { field = v; invalidate() }
    /** 0…1 voice level */
    var level = 0f
    /** a bank transaction waits: a soft green ring pulses */
    var attention = false

    private val start = System.nanoTime()
    private val bg = Paint(Paint.ANTI_ALIAS_FLAG).apply { color = Color.BLACK }
    private val rim = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE; color = Color.argb(60, 255, 255, 255) }
    private val line = Paint(Paint.ANTI_ALIAS_FLAG).apply { strokeCap = Paint.Cap.ROUND; color = Color.WHITE }
    private val pulse = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE }
    private var smooth = 0f
    private var angle = 0.0
    private var lastT = 0.0

    override fun onDraw(canvas: Canvas) {
        val t = (System.nanoTime() - start) / 1e9
        val dt = (t - lastT).coerceIn(0.0, 0.1)
        lastT = t
        val w = width.toFloat(); val h = height.toFloat()
        val cx = w / 2; val cy = h / 2
        val half = min(w, h) / 2
        if (disc) {
            canvas.drawCircle(cx, cy, half * 0.96f, bg)
            rim.strokeWidth = half * 0.03f
            canvas.drawCircle(cx, cy, half * 0.96f, rim)
        }
        if (attention) {
            val k = ((t * 1.1) % 1.0).toFloat()
            pulse.strokeWidth = half * 0.07f
            pulse.color = Color.argb(((1 - k) * 220).toInt(), 141, 224, 143)
            canvas.drawCircle(cx, cy, half * (0.80f + 0.16f * k), pulse)
        }
        smooth += (level - smooth) * 0.25f
        val speed = when (phase) {
            Phase.PROCESSING -> 2.4
            Phase.LISTENING, Phase.SPEAKING -> 0.9 + smooth * 1.5
            else -> 0.45
        }
        angle += speed * dt
        val breathe = when (phase) {
            Phase.LISTENING, Phase.SPEAKING -> 1f + 0.10f * smooth + 0.02f * sin(t * 3).toFloat()
            Phase.PROCESSING -> 1f + 0.03f * sin(t * 5).toFloat()
            else -> 1f + 0.015f * sin(t * 1.5).toFloat()
        }
        val r = half * (if (disc) 0.56f else 0.92f) * breathe
        // slight tilt, like a globe seen from a bit above
        val tilt = 0.32
        val ct = cos(tilt); val st = sin(tilt)
        val meridians = if (disc) 22 else 34
        val steps = if (disc) 16 else 26
        line.strokeWidth = r * (if (disc) 0.07f else 0.045f)
        for (i in 0 until meridians) {
            val phi = angle + i * 2 * PI / meridians
            for (j in 0 until steps) {
                // a short dash along the meridian from theta0 to theta1
                val th0 = PI * (j + 0.18) / steps
                val th1 = PI * (j + 0.82) / steps
                val p0 = project(th0, phi, ct, st)
                val p1 = project(th1, phi, ct, st)
                val z = (p0[2] + p1[2]) / 2
                if (z < -0.05) continue                     // back side hidden
                val shade = ((z + 0.05) / 1.05).coerceIn(0.0, 1.0)
                val wave = if (phase == Phase.PROCESSING) 0.75 + 0.25 * sin(t * 6 - j * 0.6) else 1.0
                line.alpha = (255 * (0.15 + 0.85 * shade * shade) * wave).toInt().coerceIn(0, 255)
                canvas.drawLine(cx + (p0[0] * r).toFloat(), cy + (p0[1] * r).toFloat(), cx + (p1[0] * r).toFloat(), cy + (p1[1] * r).toFloat(), line)
            }
        }
        if (phase == Phase.IDLE && !attention) postInvalidateDelayed(40) else postInvalidateOnAnimation()
    }

    /** Point of the unit sphere (theta from the north pole, phi around) → screen x, y and depth z. */
    private fun project(theta: Double, phi: Double, ct: Double, st: Double): DoubleArray {
        val x = sin(theta) * sin(phi)
        val y = -cos(theta)
        val z = sin(theta) * cos(phi)
        // tilt around the x axis
        val y2 = y * ct - z * st
        val z2 = y * st + z * ct
        return doubleArrayOf(x, y2, z2)
    }
}
