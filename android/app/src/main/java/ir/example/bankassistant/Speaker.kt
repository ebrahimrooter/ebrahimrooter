package ir.example.bankassistant

import android.media.AudioAttributes
import android.media.MediaPlayer
import android.media.audiofx.Visualizer
import kotlinx.coroutines.suspendCancellableCoroutine
import java.io.File
import kotlin.coroutines.resume

/** Plays the server's mp3 answer; `level` follows the voice for the orb (when allowed). */
class Speaker(private val dir: File) {
    @Volatile var level = 0f
        private set
    private var player: MediaPlayer? = null
    private var viz: Visualizer? = null

    suspend fun play(mp3: ByteArray) {
        stop()
        val f = File(dir, "say-${System.nanoTime()}.mp3").apply { writeBytes(mp3) }
        try {
            suspendCancellableCoroutine { cont ->
                val p = MediaPlayer()
                player = p
                p.setAudioAttributes(AudioAttributes.Builder()
                    .setUsage(AudioAttributes.USAGE_ASSISTANT)
                    .setContentType(AudioAttributes.CONTENT_TYPE_SPEECH).build())
                p.setDataSource(f.absolutePath)
                p.setOnCompletionListener { if (cont.isActive) cont.resume(Unit) }
                p.setOnErrorListener { _, _, _ -> if (cont.isActive) cont.resume(Unit); true }
                p.setOnPreparedListener {
                    it.start()
                    startLevel(it.audioSessionId)
                }
                p.prepareAsync()
                cont.invokeOnCancellation { stop() }
            }
        } finally {
            stop()
            f.delete()
        }
    }

    fun stop() {
        runCatching { viz?.enabled = false; viz?.release() }
        viz = null
        runCatching { player?.stop() }
        runCatching { player?.release() }
        player = null
        level = 0f
    }

    private fun startLevel(session: Int) {
        // Visualizer needs RECORD_AUDIO (we have it); without it the orb just pulses.
        runCatching {
            val v = Visualizer(session)
            v.captureSize = Visualizer.getCaptureSizeRange()[0]
            v.setDataCaptureListener(object : Visualizer.OnDataCaptureListener {
                override fun onWaveFormDataCapture(vz: Visualizer, wave: ByteArray, rate: Int) {
                    var peak = 0
                    for (b in wave) peak = maxOf(peak, kotlin.math.abs((b.toInt() and 0xff) - 128))
                    level = (peak / 90f).coerceIn(0f, 1f)
                }
                override fun onFftDataCapture(vz: Visualizer, fft: ByteArray, rate: Int) {}
            }, Visualizer.getMaxCaptureRate() / 2, true, false)
            v.enabled = true
            viz = v
        }
    }
}
