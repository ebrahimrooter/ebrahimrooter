import Foundation
import Speech
import SherpaOnnx

/// Persian voice inside the app, no server needed: Piper «amir» (fa_IR) through
/// sherpa-onnx, the model in Resources/FaVoice (put there by tools/voice-model/fetch.sh ios).
/// The first sentence loads it (about a second); later ones take a fraction of that.
final class LocalVoice {
    static let shared = LocalVoice()
    private let queue = DispatchQueue(label: "ir.example.bankassistant.voice", qos: .userInitiated)
    private var tts: SherpaOnnxOfflineTtsWrapper?
    private var failed = false

    private var dir: URL? { Bundle.main.url(forResource: "FaVoice", withExtension: nil) }

    var bundled: Bool {
        guard let d = dir else { return false }
        return FileManager.default.fileExists(atPath: d.appendingPathComponent("fa_IR-amir-medium.onnx").path)
    }

    private func engine() -> SherpaOnnxOfflineTtsWrapper? {
        if let tts { return tts }
        guard !failed, bundled, let d = dir else { return nil }
        let vits = sherpaOnnxOfflineTtsVitsModelConfig(
            model: d.appendingPathComponent("fa_IR-amir-medium.onnx").path,
            lexicon: "",
            tokens: d.appendingPathComponent("tokens.txt").path,
            dataDir: d.appendingPathComponent("espeak-ng-data").path)
        let model = sherpaOnnxOfflineTtsModelConfig(vits: vits, numThreads: 2, debug: 0)
        var cfg = sherpaOnnxOfflineTtsConfig(model: model)
        let t = SherpaOnnxOfflineTtsWrapper(config: &cfg)
        guard t.tts != nil else { failed = true; return nil }
        tts = t
        return t
    }

    /// Loads the model in the background so the first answer is not delayed.
    func warmUp() {
        guard bundled else { return }
        queue.async { _ = self.engine() }
    }

    /// The sentence as WAV (16-bit mono), or nil when the voice is not in the app.
    func wav(_ text: String, speed: Float = 1.0) async -> Data? {
        let speech = SpeechText.forSpeech(text)
        guard !speech.isEmpty else { return nil }
        return await withCheckedContinuation { c in
            queue.async {
                guard let e = self.engine() else { return c.resume(returning: nil) }
                let audio = e.generate(text: speech, sid: 0, speed: speed)
                let samples = audio.samples
                c.resume(returning: samples.isEmpty ? nil : Self.toWav(samples, rate: Int(audio.sampleRate)))
            }
        }
    }

    static func toWav(_ samples: [Float], rate: Int) -> Data {
        var d = Data(capacity: 44 + samples.count * 2)
        func u32(_ v: Int) { var x = UInt32(v).littleEndian; d.append(Data(bytes: &x, count: 4)) }
        func u16(_ v: Int) { var x = UInt16(v).littleEndian; d.append(Data(bytes: &x, count: 2)) }
        let size = samples.count * 2
        d.append(contentsOf: Array("RIFF".utf8)); u32(36 + size); d.append(contentsOf: Array("WAVE".utf8))
        d.append(contentsOf: Array("fmt ".utf8)); u32(16); u16(1); u16(1); u32(rate); u32(rate * 2); u16(2); u16(16)
        d.append(contentsOf: Array("data".utf8)); u32(size)
        var pcm = [Int16](repeating: 0, count: samples.count)
        for (i, s) in samples.enumerated() { pcm[i] = Int16(max(-1, min(1, s)) * 32767).littleEndian }
        pcm.withUnsafeBufferPointer { d.append(UnsafeBufferPointer(start: UnsafeRawPointer($0.baseAddress!).assumingMemoryBound(to: UInt8.self), count: size)) }
        return d
    }
}

/// Listening in Persian with Apple's own recognizer — only when this iPhone
/// offers Persian (fa-IR); otherwise the server's speech-to-text is used.
enum PhoneListen {
    static var recognizer: SFSpeechRecognizer? {
        guard let r = SFSpeechRecognizer(locale: Locale(identifier: "fa-IR")), r.locale.identifier.hasPrefix("fa") else { return nil }
        return r
    }

    static var available: Bool { recognizer?.isAvailable == true }

    /// The text in a recorded file, or nil when the phone can't do Persian.
    static func transcribe(fileURL: URL) async -> String? {
        guard let r = recognizer, r.isAvailable else { return nil }
        let status = await withCheckedContinuation { c in SFSpeechRecognizer.requestAuthorization { c.resume(returning: $0) } }
        guard status == .authorized else { return nil }
        let req = SFSpeechURLRecognitionRequest(url: fileURL)
        req.shouldReportPartialResults = false
        return await withCheckedContinuation { c in
            var done = false
            r.recognitionTask(with: req) { result, error in
                guard !done else { return }
                if let result, result.isFinal { done = true; c.resume(returning: result.bestTranscription.formattedString) }
                else if error != nil { done = true; c.resume(returning: nil) }
            }
        }
    }
}
