import Foundation
import Speech
import AVFoundation
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

/// Persian speech-to-text inside the app, no server needed: Shenava (FastConformer
/// CTC, Apache-2.0) through sherpa-onnx, the model in Resources/FaEar
/// (tools/voice-model/fetch.sh ios). Checked on real voices: tools/voice-model/stt-report.txt.
final class LocalEar {
    static let shared = LocalEar()
    private let queue = DispatchQueue(label: "ir.example.bankassistant.ear", qos: .userInitiated)
    private var rec: SherpaOnnxOfflineRecognizer?

    private var dir: URL? { Bundle.main.url(forResource: "FaEar", withExtension: nil) }
    private var model: URL? { dir?.appendingPathComponent("model.onnx") }

    var bundled: Bool {
        guard let m = model, let d = dir else { return false }
        return FileManager.default.fileExists(atPath: m.path) && FileManager.default.fileExists(atPath: d.appendingPathComponent("tokens.txt").path)
    }

    private func engine() -> SherpaOnnxOfflineRecognizer? {
        if let rec { return rec }
        guard bundled, let d = dir, let m = model else { return nil }   // the recognizer stops the app on a missing file
        let mc = sherpaOnnxOfflineModelConfig(tokens: d.appendingPathComponent("tokens.txt").path,
                                              nemoCtc: sherpaOnnxOfflineNemoEncDecCtcModelConfig(model: m.path), numThreads: 2)
        var cfg = sherpaOnnxOfflineRecognizerConfig(featConfig: sherpaOnnxFeatureConfig(sampleRate: 16000, featureDim: 80), modelConfig: mc)
        let r = SherpaOnnxOfflineRecognizer(config: &cfg)
        rec = r
        return r
    }

    func warmUp() {
        guard bundled else { return }
        queue.async { _ = self.engine() }
    }

    /// Text of a recording (AudioCapture's 16 kHz mono WAV); nil when the model is not in the app.
    func transcribe(fileURL: URL) async -> String? {
        guard bundled, let samples = Self.samples(fileURL) else { return nil }
        return await withCheckedContinuation { c in
            queue.async {
                guard let r = self.engine() else { return c.resume(returning: nil) }
                c.resume(returning: r.decode(samples: samples, sampleRate: 16000).text.trimmingCharacters(in: .whitespacesAndNewlines))
            }
        }
    }

    func transcribe(samples: [Float]) async -> String? {
        await withCheckedContinuation { c in
            queue.async {
                guard let r = self.engine() else { return c.resume(returning: nil) }
                c.resume(returning: r.decode(samples: Self.padded(samples), sampleRate: 16000).text.trimmingCharacters(in: .whitespacesAndNewlines))
            }
        }
    }

    /// 0.3 s of silence before and 0.6 s after: without it the last word gets lost.
    static func padded(_ s: [Float]) -> [Float] {
        [Float](repeating: 0, count: 4800) + s + [Float](repeating: 0, count: 9600)
    }

    static func samples(_ url: URL) -> [Float]? {
        guard let f = try? AVAudioFile(forReading: url),
              let fmt = AVAudioFormat(commonFormat: .pcmFormatFloat32, sampleRate: 16000, channels: 1, interleaved: false),
              let inBuf = AVAudioPCMBuffer(pcmFormat: f.processingFormat, frameCapacity: AVAudioFrameCount(f.length)),
              (try? f.read(into: inBuf)) != nil else { return nil }
        if f.processingFormat.sampleRate == 16000, f.processingFormat.channelCount == 1, let ch = inBuf.floatChannelData {
            return padded(Array(UnsafeBufferPointer(start: ch[0], count: Int(inBuf.frameLength))))
        }
        guard let conv = AVAudioConverter(from: f.processingFormat, to: fmt),
              let out = AVAudioPCMBuffer(pcmFormat: fmt, frameCapacity: AVAudioFrameCount(Double(inBuf.frameLength) * 16000 / f.processingFormat.sampleRate) + 1024)
        else { return nil }
        var fed = false
        _ = conv.convert(to: out, error: nil) { _, status in
            if fed { status.pointee = .endOfStream; return nil }
            fed = true
            status.pointee = .haveData
            return inBuf
        }
        guard let ch = out.floatChannelData else { return nil }
        return padded(Array(UnsafeBufferPointer(start: ch[0], count: Int(out.frameLength))))
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

/// What was said in a recording: the app's own recognizer first (no server), then
/// the server's speech-to-text, then Apple's Persian recognizer if this iPhone has one.
enum Hearing {
    static func text(of url: URL) async throws -> String {
        if let t = await LocalEar.shared.transcribe(fileURL: url) { return t }
        do {
            return try await APIClient.shared.transcribe(fileURL: url)
        } catch {
            if case APIError.unauthorized = error { throw error }
            guard let t = await PhoneListen.transcribe(fileURL: url) else { throw error }
            return t
        }
    }
}
