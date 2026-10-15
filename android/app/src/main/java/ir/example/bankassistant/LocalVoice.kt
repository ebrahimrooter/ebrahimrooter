package ir.example.bankassistant

import android.content.Context
import android.content.Intent
import android.os.Bundle
import android.speech.RecognitionListener
import android.speech.RecognizerIntent
import android.speech.SpeechRecognizer
import android.util.Log
import com.k2fsa.sherpa.onnx.FeatureConfig
import com.k2fsa.sherpa.onnx.OfflineModelConfig
import com.k2fsa.sherpa.onnx.OfflineNemoEncDecCtcModelConfig
import com.k2fsa.sherpa.onnx.OfflineRecognizer
import com.k2fsa.sherpa.onnx.OfflineRecognizerConfig
import com.k2fsa.sherpa.onnx.OfflineTts
import com.k2fsa.sherpa.onnx.OfflineTtsConfig
import com.k2fsa.sherpa.onnx.OfflineTtsModelConfig
import com.k2fsa.sherpa.onnx.OfflineTtsVitsModelConfig
import kotlinx.coroutines.suspendCancellableCoroutine
import java.io.ByteArrayOutputStream
import java.io.File
import java.nio.ByteBuffer
import java.nio.ByteOrder
import kotlin.coroutines.resume

/**
 * Persian voice inside the app, no server needed: Piper «amir» (fa_IR) through
 * sherpa-onnx, the model in assets/fa-voice (put there by tools/voice-model/fetch.sh).
 * The first call loads it (about a second); later sentences take a fraction of that.
 */
object LocalVoice {
    private const val TAG = "LocalVoice"
    private const val DIR = "fa-voice"
    private const val MODEL = "fa_IR-amir-medium.onnx"
    @Volatile private var tts: OfflineTts? = null
    @Volatile private var failed = false
    private val lock = Any()

    fun bundled(c: Context): Boolean =
        runCatching { c.assets.list(DIR)?.contains(MODEL) == true }.getOrDefault(false)

    private fun engine(c: Context): OfflineTts? {
        tts?.let { return it }
        if (failed) return null
        synchronized(lock) {
            tts?.let { return it }
            return try {
                // espeak-ng data must be real files; the model itself is read from the assets
                val data = File(c.filesDir, "$DIR/espeak-ng-data")
                val marker = File(data, ".v1")
                if (!marker.exists()) {
                    data.deleteRecursively()
                    copyAssets(c, "$DIR/espeak-ng-data", data)
                    marker.writeText("ok")
                }
                val cfg = OfflineTtsConfig(model = OfflineTtsModelConfig(
                    vits = OfflineTtsVitsModelConfig(model = "$DIR/$MODEL", tokens = "$DIR/tokens.txt", dataDir = data.absolutePath),
                    numThreads = 2, debug = false, provider = "cpu"))
                OfflineTts(assetManager = c.assets, config = cfg).also { tts = it }
            } catch (t: Throwable) {
                Log.w(TAG, "local voice not available", t)
                failed = true
                null
            }
        }
    }

    private fun copyAssets(c: Context, from: String, to: File) {
        val kids = c.assets.list(from) ?: emptyArray()
        if (kids.isEmpty()) {
            to.parentFile?.mkdirs()
            c.assets.open(from).use { i -> to.outputStream().use { o -> i.copyTo(o) } }
            return
        }
        to.mkdirs()
        for (k in kids) copyAssets(c, "$from/$k", File(to, k))
    }

    /** Loads the model in the background so the first answer is not delayed. */
    fun warmUp(c: Context) {
        if (bundled(c)) Thread { engine(c.applicationContext) }.start()
    }

    /** The sentence as WAV bytes (16-bit mono), or null when the voice is not in the app. Blocking. */
    fun wav(c: Context, text: String, speed: Float = 1.0f): ByteArray? {
        val speech = SpeechText.forSpeech(text)
        if (speech.isBlank()) return null
        val e = engine(c.applicationContext) ?: return null
        val audio = synchronized(lock) { e.generate(speech, 0, speed) }
        if (audio.samples.isEmpty()) return null
        return toWav(audio.samples, audio.sampleRate)
    }

    fun toWav(samples: FloatArray, rate: Int): ByteArray {
        val pcm = ByteBuffer.allocate(samples.size * 2).order(ByteOrder.LITTLE_ENDIAN)
        for (s in samples) pcm.putShort((s.coerceIn(-1f, 1f) * 32767f).toInt().toShort())
        val data = pcm.array()
        val h = ByteBuffer.allocate(44).order(ByteOrder.LITTLE_ENDIAN)
        h.put("RIFF".toByteArray()).putInt(36 + data.size).put("WAVE".toByteArray())
        h.put("fmt ".toByteArray()).putInt(16).putShort(1).putShort(1).putInt(rate).putInt(rate * 2).putShort(2).putShort(16)
        h.put("data".toByteArray()).putInt(data.size)
        return ByteArrayOutputStream(44 + data.size).apply { write(h.array()); write(data) }.toByteArray()
    }
}

/**
 * Persian speech-to-text inside the app, no server and no Google needed: Shenava
 * Rizeh (FastConformer CTC, Apache-2.0, int8, 38 MB; the iPhone has the bigger Koochik)
 * through sherpa-onnx, the model in
 * assets/fa-ear (tools/voice-model/fetch.sh android). Checked on real voices:
 * tools/voice-model/stt-report.txt.
 */
object LocalEar {
    private const val TAG = "LocalEar"
    private const val DIR = "fa-ear"
    @Volatile private var rec: OfflineRecognizer? = null
    @Volatile private var failed = false
    private val lock = Any()

    fun bundled(c: Context): Boolean =
        runCatching { c.assets.list(DIR)?.let { "model.onnx" in it && "tokens.txt" in it } == true }.getOrDefault(false)

    private fun engine(c: Context): OfflineRecognizer? {
        rec?.let { return it }
        if (failed || !bundled(c)) return null
        synchronized(lock) {
            rec?.let { return it }
            return try {
                val cfg = OfflineRecognizerConfig(
                    featConfig = FeatureConfig(sampleRate = 16000, featureDim = 80),
                    modelConfig = OfflineModelConfig(nemo = OfflineNemoEncDecCtcModelConfig("$DIR/model.onnx"),
                        tokens = "$DIR/tokens.txt", numThreads = 2, debug = false, provider = "cpu"))
                OfflineRecognizer(assetManager = c.assets, config = cfg).also { rec = it }
            } catch (t: Throwable) {
                Log.w(TAG, "local speech-to-text not available", t)
                failed = true
                null
            }
        }
    }

    fun warmUp(c: Context) {
        if (bundled(c)) Thread { engine(c.applicationContext) }.start()
    }

    /** Text of 16 kHz mono samples (-1…1), or null when the model is not in the app. Blocking. */
    fun transcribe(c: Context, samples: FloatArray): String? {
        val e = engine(c.applicationContext) ?: return null
        // 0.3 s of silence before and 0.6 s after: without it the last word gets lost
        val padded = FloatArray(4800 + samples.size + 9600)
        System.arraycopy(samples, 0, padded, 4800, samples.size)
        // quiet microphones: bring the loudest part up to about 0.7 (the model was trained on normal levels)
        var peak = 0f
        for (v in samples) peak = maxOf(peak, kotlin.math.abs(v))
        if (peak in 0.002f..0.5f) {
            val g = minOf(0.7f / peak, 30f)
            for (i in padded.indices) padded[i] *= g
        }
        return synchronized(lock) {
            val s = e.createStream()
            try {
                s.acceptWaveform(padded, 16000)
                e.decode(s)
                e.getResult(s).text.trim()
            } finally { s.release() }
        }
    }

    /** Text of a Recorder WAV (16 kHz mono 16-bit). */
    fun transcribe(c: Context, wav: File): String? {
        if (!bundled(c)) return null
        val b = wav.readBytes()
        if (b.size <= 44) return ""
        val bb = ByteBuffer.wrap(b, 44, b.size - 44).order(ByteOrder.LITTLE_ENDIAN)
        val out = FloatArray((b.size - 44) / 2)
        for (i in out.indices) out[i] = bb.short / 32768f
        return transcribe(c, out)
    }
}

/**
 * Listening in Persian with the phone's own recognizer (Google's on most
 * Android phones), so no server is needed for it either. null = not available
 * on this phone (then the server's speech-to-text is used, when it has one).
 * Must be called on the main thread.
 */
object PhoneListen {
    fun available(c: Context) = SpeechRecognizer.isRecognitionAvailable(c)

    /** Returns the text, "" when nothing was understood, null when the recognizer can't be used. */
    suspend fun listen(c: Context, onLevel: (Float) -> Unit = {}): String? = suspendCancellableCoroutine { cont ->
        if (!available(c)) { cont.resume(null); return@suspendCancellableCoroutine }
        val sr = SpeechRecognizer.createSpeechRecognizer(c)
        val intent = Intent(RecognizerIntent.ACTION_RECOGNIZE_SPEECH)
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE_MODEL, RecognizerIntent.LANGUAGE_MODEL_FREE_FORM)
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE, "fa-IR")
            .putExtra(RecognizerIntent.EXTRA_LANGUAGE_PREFERENCE, "fa-IR")
            .putExtra(RecognizerIntent.EXTRA_MAX_RESULTS, 1)
            .putExtra(RecognizerIntent.EXTRA_CALLING_PACKAGE, c.packageName)
        var done = false
        fun finish(v: String?) {
            if (done) return
            done = true
            runCatching { sr.destroy() }
            if (cont.isActive) cont.resume(v)
        }
        sr.setRecognitionListener(object : RecognitionListener {
            override fun onResults(results: Bundle?) =
                finish(results?.getStringArrayList(SpeechRecognizer.RESULTS_RECOGNITION)?.firstOrNull()?.trim() ?: "")
            override fun onError(error: Int) = finish(when (error) {
                SpeechRecognizer.ERROR_NO_MATCH, SpeechRecognizer.ERROR_SPEECH_TIMEOUT -> ""
                else -> null      // no network, language missing, busy…: let the caller fall back
            })
            override fun onRmsChanged(rmsdB: Float) = onLevel(((rmsdB + 2f) / 12f).coerceIn(0f, 1f))
            override fun onReadyForSpeech(params: Bundle?) {}
            override fun onBeginningOfSpeech() {}
            override fun onBufferReceived(buffer: ByteArray?) {}
            override fun onEndOfSpeech() {}
            override fun onPartialResults(partialResults: Bundle?) {}
            override fun onEvent(eventType: Int, params: Bundle?) {}
        })
        cont.invokeOnCancellation { runCatching { sr.cancel(); sr.destroy() } }
        sr.startListening(intent)
    }
}
