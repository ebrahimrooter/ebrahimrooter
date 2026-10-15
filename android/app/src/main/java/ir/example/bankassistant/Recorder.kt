package ir.example.bankassistant

import android.annotation.SuppressLint
import android.media.AudioFormat
import android.media.AudioRecord
import android.media.MediaRecorder
import java.io.File
import java.io.RandomAccessFile
import kotlin.math.sqrt

/**
 * Microphone → 16 kHz mono WAV of one sentence. Starts at speech, ends after
 * 1.2 s of quiet; null if nothing was said within 7 s. Blocking: call off the main thread.
 * «Speech» is measured against the room's own noise (the first 0.3 s): phone microphones
 * differ a lot in level (Samsung's voice-recognition input is very quiet).
 */
class Recorder(private val dir: File) {
    @Volatile var level = 0f            // 0…1, for the orb animation
        private set
    @Volatile private var cancelled = false
    /** What the last recording saw (settings ← «آزمایش صدا و میکروفون»). */
    @Volatile var lastInfo = ""
        private set

    private val rate = 16_000
    private val minThreshold = 0.006f
    private val silenceToEndMs = 1_200
    private val noSpeechMs = 7_000
    private val maxMs = 15_000

    fun cancel() { cancelled = true }

    @SuppressLint("MissingPermission")    // checked by the caller
    fun record(): File? {
        cancelled = false
        val min = AudioRecord.getMinBufferSize(rate, AudioFormat.CHANNEL_IN_MONO, AudioFormat.ENCODING_PCM_16BIT)
        val rec = AudioRecord(MediaRecorder.AudioSource.VOICE_RECOGNITION, rate, AudioFormat.CHANNEL_IN_MONO,
            AudioFormat.ENCODING_PCM_16BIT, maxOf(min, rate / 5 * 2))
        if (rec.state != AudioRecord.STATE_INITIALIZED) {
            rec.release()
            throw IllegalStateException("میکروفون آماده نشد")
        }
        val out = File(dir, "utt-${System.nanoTime()}.wav")
        val raf = RandomAccessFile(out, "rw")
        raf.setLength(0)
        raf.write(ByteArray(44))           // header later
        val chunk = ShortArray(rate / 10)  // 100 ms
        val bytes = ByteArray(chunk.size * 2)
        var heard = false
        var noise = 0f
        var noiseN = 0
        var threshold = 0.02f
        var peak = 0f
        var startMs = 0L
        var lastLoud = 0L
        var t = 0L
        var data = 0L
        val pre = ArrayDeque<ByteArray>()  // 300 ms before speech starts
        rec.startRecording()
        try {
            while (!cancelled) {
                val n = rec.read(chunk, 0, chunk.size)
                if (n <= 0) continue
                t += n * 1000L / rate
                var sum = 0.0
                for (i in 0 until n) {
                    val v = chunk[i] / 32768.0
                    sum += v * v
                    bytes[i * 2] = (chunk[i].toInt() and 0xff).toByte()
                    bytes[i * 2 + 1] = (chunk[i].toInt() shr 8 and 0xff).toByte()
                }
                val rms = sqrt(sum / n).toFloat()
                peak = maxOf(peak, rms)
                level = (rms * 20).coerceIn(0f, 1f)
                val piece = bytes.copyOf(n * 2)
                if (!heard) {
                    pre.addLast(piece)
                    if (pre.size > 3) pre.removeFirst()
                    if (t <= 300) {                 // the room before anyone speaks
                        noise += rms; noiseN++
                        threshold = maxOf(minThreshold, noise / noiseN * 2.5f + 0.003f)
                        continue
                    }
                    if (rms > threshold) {
                        heard = true
                        startMs = t
                        lastLoud = t
                        pre.forEach { raf.write(it); data += it.size }
                        pre.clear()
                    } else if (t > noSpeechMs) {
                        break
                    }
                    continue
                }
                raf.write(piece)
                data += piece.size
                if (rms > threshold) lastLoud = t
                if (t - lastLoud > silenceToEndMs || t - startMs > maxMs) break
            }
        } finally {
            level = 0f
            rec.stop()
            rec.release()
        }
        lastInfo = "صدای اتاق " + "%.3f".format(if (noiseN > 0) noise / noiseN else 0f) + " · بلندترین " + "%.3f".format(peak) +
            " · مرز " + "%.3f".format(threshold) + (if (heard) " · حرف شنیده شد" else " · حرفی شنیده نشد")
        if (!heard || cancelled || data < rate / 2) {   // under ~0.25 s
            raf.close()
            out.delete()
            return null
        }
        raf.seek(0)
        raf.write(wavHeader(data))
        raf.close()
        return out
    }

    private fun wavHeader(dataLen: Long): ByteArray {
        val h = java.nio.ByteBuffer.allocate(44).order(java.nio.ByteOrder.LITTLE_ENDIAN)
        h.put("RIFF".toByteArray()); h.putInt((36 + dataLen).toInt()); h.put("WAVE".toByteArray())
        h.put("fmt ".toByteArray()); h.putInt(16); h.putShort(1); h.putShort(1)
        h.putInt(rate); h.putInt(rate * 2); h.putShort(2); h.putShort(16)
        h.put("data".toByteArray()); h.putInt(dataLen.toInt())
        return h.array()
    }
}
