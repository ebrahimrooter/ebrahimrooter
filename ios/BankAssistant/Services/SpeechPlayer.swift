import AVFoundation

/// Plays the answer. Persian speech comes from the server's local voice
/// (iOS has no Persian system voice); without it the answer is shown as text only.
final class SpeechPlayer: NSObject, AVAudioPlayerDelegate {
    var onLevel: ((Float) -> Void)?
    private var player: AVAudioPlayer?
    private var meter: Timer?
    private var done: CheckedContinuation<Void, Never>?

    func play(_ data: Data) async {
        stop()
        guard let p = try? AVAudioPlayer(data: data) else { return }
        p.delegate = self
        p.isMeteringEnabled = true
        player = p
        await withCheckedContinuation { (c: CheckedContinuation<Void, Never>) in
            done = c
            p.play()
            meter = Timer.scheduledTimer(withTimeInterval: 0.05, repeats: true) { [weak self] _ in
                guard let self, let p = self.player else { return }
                p.updateMeters()
                let db = p.averagePower(forChannel: 0)            // -160…0
                self.onLevel?(max(0, min(1, (db + 50) / 50)))
            }
        }
    }

    func stop() {
        meter?.invalidate()
        meter = nil
        player?.stop()
        player = nil
        resume()
    }

    func audioPlayerDidFinishPlaying(_ player: AVAudioPlayer, successfully flag: Bool) {
        meter?.invalidate()
        meter = nil
        onLevel?(0)
        resume()
    }

    private func resume() {
        let c = done
        done = nil
        c?.resume()
    }
}
