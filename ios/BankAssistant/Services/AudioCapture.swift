import AVFoundation

/// Microphone → 16 kHz mono WAV of one utterance, with a simple voice-activity
/// detector: starts at speech, ends after `silenceToEnd` of quiet.
/// The engine keeps running between turns so the session stays alive in the background.
final class AudioCapture {
    struct Utterance { let url: URL; let duration: TimeInterval }

    enum CaptureError: LocalizedError {
        case noSpeech, tooShort, engine(String)
        var errorDescription: String? {
            switch self {
            case .noSpeech: return "صدایی نشنیدم."
            case .tooShort: return "خیلی کوتاه بود؛ دوباره بگو."
            case .engine(let m): return "میکروفون: \(m)"
            }
        }
    }

    /// 0…1, for the animation.
    var onLevel: ((Float) -> Void)?

    private let engine = AVAudioEngine()
    private let target = AVAudioFormat(commonFormat: .pcmFormatInt16, sampleRate: 16_000, channels: 1, interleaved: true)!
    private var converter: AVAudioConverter?
    private var file: AVAudioFile?
    private var fileURL: URL?
    private var recording = false
    private var heardSpeech = false
    private var speechStart: Date?
    private var lastLoud = Date()
    private var startedAt = Date()
    private var finish: ((Result<Utterance, Error>) -> Void)?

    // tuning
    private let speechThreshold: Float = 0.035     // RMS
    private let silenceToEnd: TimeInterval = 1.2
    private let noSpeechTimeout: TimeInterval = 7
    private let maxLength: TimeInterval = 15

    var isRunning: Bool { engine.isRunning }

    func start() throws {
        guard !engine.isRunning else { return }
        let input = engine.inputNode
        let format = input.outputFormat(forBus: 0)
        guard format.sampleRate > 0 else { throw CaptureError.engine("ورودی صدا پیدا نشد") }
        converter = AVAudioConverter(from: format, to: target)
        input.removeTap(onBus: 0)
        input.installTap(onBus: 0, bufferSize: 2048, format: format) { [weak self] buffer, _ in
            self?.process(buffer)
        }
        engine.prepare()
        do { try engine.start() } catch { throw CaptureError.engine(error.localizedDescription) }
    }

    func stop() {
        engine.inputNode.removeTap(onBus: 0)
        engine.stop()
        cancelUtterance()
    }

    /// Records the next utterance; calls back on the main queue.
    func listen(_ done: @escaping (Result<Utterance, Error>) -> Void) {
        let url = FileManager.default.temporaryDirectory.appendingPathComponent("utt-\(UUID().uuidString).wav")
        do {
            file = try AVAudioFile(forWriting: url, settings: target.settings, commonFormat: .pcmFormatInt16, interleaved: true)
        } catch {
            done(.failure(CaptureError.engine(error.localizedDescription)))
            return
        }
        fileURL = url
        heardSpeech = false
        speechStart = nil
        startedAt = Date()
        lastLoud = Date()
        finish = done
        recording = true
    }

    func cancelUtterance() {
        recording = false
        finish = nil
        file = nil
        if let u = fileURL { try? FileManager.default.removeItem(at: u) }
        fileURL = nil
    }

    private func process(_ buffer: AVAudioPCMBuffer) {
        let level = rms(buffer)
        DispatchQueue.main.async { [weak self] in self?.onLevel?(min(1, level * 8)) }
        guard recording, let converter, let file else { return }

        let now = Date()
        if level > speechThreshold {
            lastLoud = now
            if !heardSpeech { heardSpeech = true; speechStart = now }
        }
        // convert to 16 kHz mono and append
        let ratio = target.sampleRate / buffer.format.sampleRate
        let cap = AVAudioFrameCount(Double(buffer.frameLength) * ratio) + 32
        if let out = AVAudioPCMBuffer(pcmFormat: target, frameCapacity: cap) {
            var fed = false
            var err: NSError?
            converter.convert(to: out, error: &err) { _, status in
                if fed { status.pointee = .noDataNow; return nil }
                fed = true
                status.pointee = .haveData
                return buffer
            }
            if err == nil, out.frameLength > 0 { try? file.write(from: out) }
        }

        if !heardSpeech && now.timeIntervalSince(startedAt) > noSpeechTimeout {
            end(.failure(CaptureError.noSpeech))
        } else if heardSpeech && (now.timeIntervalSince(lastLoud) > silenceToEnd || now.timeIntervalSince(startedAt) > maxLength) {
            let spoken = lastLoud.timeIntervalSince(speechStart ?? lastLoud)
            if spoken < 0.3 { end(.failure(CaptureError.tooShort)) } else if let u = fileURL { end(.success(Utterance(url: u, duration: spoken))) }
        }
    }

    private func end(_ result: Result<Utterance, Error>) {
        recording = false
        file = nil                                   // closes the WAV
        let cb = finish
        finish = nil
        if case .failure = result, let u = fileURL { try? FileManager.default.removeItem(at: u) }
        fileURL = nil
        DispatchQueue.main.async { cb?(result) }
    }

    private func rms(_ buffer: AVAudioPCMBuffer) -> Float {
        guard let ch = buffer.floatChannelData?[0], buffer.frameLength > 0 else { return 0 }
        var sum: Float = 0
        for i in 0..<Int(buffer.frameLength) { sum += ch[i] * ch[i] }
        return sqrt(sum / Float(buffer.frameLength))
    }
}
