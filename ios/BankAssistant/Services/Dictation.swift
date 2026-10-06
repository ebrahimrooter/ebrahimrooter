import AVFoundation
import Observation

/// One spoken sentence → text, through the server's local speech-to-text
/// (the same as the assistant, without asking the books). Ends by itself on silence.
@MainActor
@Observable
final class Dictation {
    private(set) var recording = false
    private(set) var busy = false
    private(set) var error: String?
    private let capture = AudioCapture()
    private var waiting: CheckedContinuation<Result<AudioCapture.Utterance, Error>, Never>?

    /// Starts listening and returns the text once the sentence ends; a second call stops.
    func toggle() async -> String? {
        if recording { finish(); return nil }
        error = nil
        if AssistantEngine.shared.sessionActive {
            error = "اول دستیار صوتی را ببند."
            return nil
        }
        if Permissions.mic != .granted {
            guard await Permissions.requestMic() else { error = "اجازه‌ی میکروفون داده نشده."; return nil }
        }
        do {
            let s = AVAudioSession.sharedInstance()
            try s.setCategory(.playAndRecord, mode: .default, options: [.defaultToSpeaker, .allowBluetooth])
            try s.setActive(true)
            try capture.start()
        } catch {
            self.error = error.localizedDescription
            return nil
        }
        recording = true
        let result: Result<AudioCapture.Utterance, Error> = await withCheckedContinuation { cont in
            waiting = cont
            capture.listen { [weak self] r in
                self?.waiting?.resume(returning: r)
                self?.waiting = nil
            }
        }
        finish()
        switch result {
        case .failure(let e):
            error = e.localizedDescription
            return nil
        case .success(let u):
            busy = true
            defer { busy = false; try? FileManager.default.removeItem(at: u.url) }
            do {
                return try await APIClient.shared.transcribe(fileURL: u.url)
            } catch {
                self.error = error.localizedDescription
                return nil
            }
        }
    }

    private func finish() {
        recording = false
        if let w = waiting {           // stopped by hand before the sentence ended
            waiting = nil
            w.resume(returning: .failure(AudioCapture.CaptureError.noSpeech))
        }
        capture.stop()
        try? AVAudioSession.sharedInstance().setActive(false, options: .notifyOthersOnDeactivation)
    }
}
