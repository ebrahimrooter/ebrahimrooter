package ir.example.bankassistant

import android.content.Context
import android.graphics.Canvas
import android.graphics.Color
import android.graphics.Paint
import android.graphics.Path
import android.graphics.RadialGradient
import android.graphics.Shader
import android.view.View
import kotlin.math.cos
import kotlin.math.min
import kotlin.math.sin

enum class Phase(val title: String) {
    IDLE("آماده"), LISTENING("دارم گوش می‌دم…"), PROCESSING("در حال فکر کردن…"), SPEAKING("در حال پاسخ…"), ERROR("خطا")
}

/** The green orb (same drawing as the iPhone app / web app): wobbling rings on a dark glass disc. */
class OrbView(context: Context) : View(context) {
    var phase = Phase.IDLE
        set(v) { field = v; invalidate() }
    /** 0…1 voice level */
    var level = 0f
    var attention = false            // pulse ring: a bank transaction waits

    private val start = System.nanoTime()
    private val disc = Paint(Paint.ANTI_ALIAS_FLAG)
    private val rim = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE; color = Color.argb(70, 255, 255, 255) }
    private val ring = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE; strokeCap = Paint.Cap.ROUND }
    private val dot = Paint(Paint.ANTI_ALIAS_FLAG)
    private val glow = Paint(Paint.ANTI_ALIAS_FLAG)
    private val pulse = Paint(Paint.ANTI_ALIAS_FLAG).apply { style = Paint.Style.STROKE }
    private val path = Path()
    private var smooth = 0f

    private fun palette(): IntArray = when (phase) {
        Phase.LISTENING -> intArrayOf(0xFF8DE08F.toInt(), 0xFF5BBD6D.toInt(), 0xFFC0EB66.toInt())
        Phase.PROCESSING -> intArrayOf(0xFF5BBD6D.toInt(), 0xFF40A0A6.toInt(), 0xFF8DE08F.toInt())
        Phase.SPEAKING -> intArrayOf(0xFFC0EB66.toInt(), 0xFF8DE08F.toInt(), 0xFF5BBD6D.toInt())
        Phase.ERROR -> intArrayOf(0xFFFF6B6B.toInt(), 0xFFFFA94D.toInt(), 0xFF8DE08F.toInt())
        Phase.IDLE -> intArrayOf(0xCC5BBD6D.toInt(), 0xFF8DE08F.toInt(), 0xFF2F8A4A.toInt())
    }

    override fun onDraw(canvas: Canvas) {
        val t = (System.nanoTime() - start) / 1e9
        val w = width.toFloat(); val h = height.toFloat()
        val cx = w / 2; val cy = h / 2
        val R = min(w, h) / 2 * 0.86f
        smooth += (level - smooth) * 0.25f
        // dark glass disc
        disc.shader = RadialGradient(cx, cy - R * 0.6f, R * 1.6f, intArrayOf(0xF2275C34.toInt(), 0xF207140C.toInt()), null, Shader.TileMode.CLAMP)
        canvas.drawCircle(cx, cy, R, disc)
        rim.strokeWidth = R * 0.04f
        canvas.drawCircle(cx, cy, R, rim)
        if (attention) {
            val k = ((t * 1.2) % 1.0).toFloat()
            pulse.strokeWidth = R * 0.08f
            pulse.color = Color.argb(((1 - k) * 200).toInt(), 141, 224, 143)
            canvas.drawCircle(cx, cy, R * (0.92f + 0.12f * k), pulse)
        }
        val colors = palette()
        val r = R * 0.52f
        glow.shader = RadialGradient(cx, cy, r * 1.5f, intArrayOf((colors[0] and 0x00FFFFFF) or 0x55000000, 0), null, Shader.TileMode.CLAMP)
        canvas.drawCircle(cx, cy, r * 1.5f, glow)
        val lv = when (phase) {
            Phase.IDLE -> 0.08f + if (attention) 0.15f else 0f
            Phase.PROCESSING -> 0.12f
            else -> 0.15f + smooth * 0.9f
        }
        val damp = if (phase == Phase.PROCESSING) 0.5f else 1f
        for (k in 0 until 3) {
            val amp = r * (0.06f + 0.22f * lv) * damp
            val rad = r * (1 - 0.08f * k)
            path.reset()
            var s = 0.0
            var first = true
            while (s <= Math.PI * 2 + 0.001) {
                val wv = sin(s * (3 + k) + t * (1.4 + 0.5 * k)) + cos(s * 2 - t * 0.9) * 0.5
                val rr = rad + (wv * amp).toFloat()
                val x = cx + (cos(s) * rr).toFloat(); val y = cy + (sin(s) * rr).toFloat()
                if (first) { path.moveTo(x, y); first = false } else path.lineTo(x, y)
                s += Math.PI / 60
            }
            path.close()
            ring.color = colors[k]
            ring.alpha = (255 * (0.95f - 0.2f * k)).toInt()
            ring.strokeWidth = r * (0.075f - 0.015f * k)
            canvas.drawPath(path, ring)
        }
        if (phase == Phase.PROCESSING) {
            for (d in 0 until 8) {
                val a = t * 3 + d * Math.PI / 4
                dot.color = Color.argb((80 + 20 * d).coerceAtMost(255), 141, 224, 143)
                canvas.drawCircle(cx + (cos(a) * r * 0.55).toFloat(), cy + (sin(a) * r * 0.55).toFloat(), r * 0.06f, dot)
            }
        }
        if (phase == Phase.IDLE && !attention) postInvalidateDelayed(60) else postInvalidateOnAnimation()
    }
}
