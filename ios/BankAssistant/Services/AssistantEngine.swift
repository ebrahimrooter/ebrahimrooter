import AVFoundation
import Observation
import UIKit

/// One voice session: listen → transcribe (server) → ask the accounting backend →
/// speak the answer (server voice) → listen again, until the user stops or says
/// nothing twice. The audio session stays active for the whole session, which is
/// what lets it continue on the Home Screen / Lock Screen (UIBackgroundModes: audio).
@MainActor
@Observable
final class AssistantEngine {
    static let shared = AssistantEngine()

    struct Turn: Identifiable { let id = UUID(); let heard: String; let reply: String }

    private(set) var phase: AssistantPhase = .idle
    private(set) var line = "روی دستیار بزن و بپرس"
    private(set) var level: Float = 0
    private(set) var turns: [Turn] = []
    private(set) var sessionActive = false
    private(set) var micDenied = false
    var errorMessage: String?

    private let capture = AudioCapture()
    private let player = SpeechPlayer()
    private let live = LiveActivityController()
    private let api = APIClient.shared
    private var silentTurns = 0
    private var observers: [NSObjectProtocol] = []

    private init() {
        capture.onLevel = { [weak self] l in if self?.phase == .listening { self?.level = l } }
        player.onLevel = { [weak self] l in if self?.phase == .speaking { self?.level = l } }
        AssistantIntentBridge.stop = { [weak self] in self?.stop() }
        AssistantIntentBridge.talk = { [weak self] in self?.talk() }
        let nc = NotificationCenter.default
        observers.append(nc.addObserver(forName: AVAudioSession.interruptionNotification, object: nil, queue: .main) { [weak self] n in
            let raw = n.userInfo?[AVAudioSessionInterruptionTypeKey] as? UInt
            if raw == AVAudioSession.InterruptionType.began.rawValue {
                Task { @MainActor in self?.stop(reason: "به‌خاطر تماس یا صدای دیگر متوقف شد") }
            }
        })
        observers.append(nc.addObserver(forName: AVAudioSession.mediaServicesWereResetNotification, object: nil, queue: .main) { [weak self] _ in
            Task { @MainActor in self?.stop(reason: "سرویس صدای گوشی دوباره راه افتاد؛ دوباره شروع کن") }
        })
    }

    // MARK: - control

    /// Tap on the orb: start a session, or talk again, or stop while it listens.
    func toggle() {
        if !sessionActive { Task { await startSession() } }
        else if phase == .listening { stop() }
        else if phase == .idle || phase == .error { talk() }
    }

    func startSession() async {
        errorMessage = nil
        guard api.isPaired else { return fail(APIError.notPaired.localizedDescription) }
        switch Permissions.mic {
        case .denied:
            micDenied = true
            return fail("دسترسی میکروفون بسته است. از تنظیمات آیفون اجازه بده.")
        case .undetermined:
            guard await Permissions.requestMic() else {
                micDenied = true
                return fail("بدون اجازه‌ی میکروفون دستیار نمی‌تواند بشنود.")
            }
        case .granted: break
        }
        micDenied = false
        do {
            let s = AVAudioSession.sharedInstance()
            try s.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker, .allowBluetooth])
            try s.setActive(true)
            try capture.start()
        } catch {
            return fail("میکروفون آماده نشد: \(error.localizedDescription)")
        }
        sessionActive = true
        silentTurns = 0
        live.start(phase: .listening, line: "در حال گوش دادن…")
        talk()
    }

    /// One more listening turn inside the running session.
    func talk() {
        guard sessionActive else { Task { await startSession() }; return }
        player.stop()
        set(.listening, "بگو…")
        capture.listen { [weak self] result in
            guard let self else { return }
            switch result {
            case .success(let u): Task { await self.handle(u) }
            case .failure(let e):
                self.silentTurns += 1
                if self.silentTurns >= 2 { self.stop(reason: "چیزی نشنیدم؛ دستیار بسته شد") }
                else { self.set(.idle, e.localizedDescription + " روی دستیار بزن یا دوباره بگو.") ; self.talk() }
            }
        }
    }

    /// Sample state for previews and simulator screenshots (`-demo`); no audio.
    func showDemo(phase p: AssistantPhase, line text: String, heard: String? = nil) {
        guard Demo.enabled else { return }
        sessionActive = true
        phase = p
        line = text
        level = p == .speaking || p == .listening ? 0.55 : 0
        if let heard { turns.append(Turn(heard: heard, reply: text)) }
    }

    func stop(reason: String = "پایان") {
        capture.cancelUtterance()
        capture.stop()
        player.stop()
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
        sessionActive = false
        level = 0
        live.end(line: reason)
        set(.idle, reason == "پایان" ? "روی دستیار بزن و بپرس" : reason, updateLive: false)
    }

    // MARK: - one turn

    private func handle(_ u: AudioCapture.Utterance) async {
        silentTurns = 0
        set(.processing, "در حال پردازش…")
        do {
            let heard = try await api.transcribe(fileURL: u.url)
            try? FileManager.default.removeItem(at: u.url)
            guard !heard.trimmingCharacters(in: .whitespaces).isEmpty else { set(.idle, "نفهمیدم؛ دوباره بگو."); return talk() }
            if Self.isGoodbye(heard) { return stop(reason: "خداحافظ") }
            line = "«\(heard)»"
            let r = try await api.ask(heard)
            turns.append(Turn(heard: heard, reply: r.reply))
            set(.speaking, r.reply)
            if let audio = try? await api.speech(for: r.reply) {
                await player.play(audio)
            } else {
                try? await Task.sleep(for: .seconds(min(6, Double(r.reply.count) / 15)))   // time to read it
            }
            guard sessionActive else { return }
            talk()                                   // conversation continues (also answers «ثبت کنم؟»)
        } catch {
            try? FileManager.default.removeItem(at: u.url)
            if case APIError.unauthorized = error { api.unpair() }
            fail(error.localizedDescription)
        }
    }

    private func fail(_ message: String) {
        errorMessage = message
        set(.error, message)
        if sessionActive {
            // keep the session for another try, but never keep the mic open in a loop on errors
            capture.cancelUtterance()
        }
    }

    private func set(_ p: AssistantPhase, _ text: String, updateLive: Bool = true) {
        phase = p
        line = text
        if p != .listening && p != .speaking { level = 0 }
        if updateLive && sessionActive { live.update(phase: p, line: text) }
    }

    private static func isGoodbye(_ s: String) -> Bool {
        let t = s.trimmingCharacters(in: .whitespacesAndNewlines)
        return ["خداحافظ", "تمام", "بسه", "کافیه", "تموم"].contains { t.hasPrefix($0) }
    }
}
