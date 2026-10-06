import AVFoundation
import AVKit
import Observation
import SwiftUI
import UIKit
import os

private let log = Logger(subsystem: "ir.example.bankassistant", category: "pip")

/// The orb in a floating Picture-in-Picture window, on top of the Home Screen
/// and other apps while a voice session runs. It is the standard video PiP
/// (AVPlayerLayer + AVPictureInPictureController) that every iPhone with
/// iOS 14+ has, so it works on all versions this app supports (17+) and in
/// the Simulator. The «video» is a short silent loop of the orb per state
/// (Resources/Orb/orb-*.mp4, made by tools/orbvid); it changes with the state.
/// ▶︎ / ❚❚ in the window = talk / stop, the restore button opens the assistant.
@Observable
final class OrbPiP: NSObject {
    static let shared = OrbPiP()

    /// Shown inline in the tab bar's orb while a session runs (PiP starts from a visible layer).
    let playerLayer = AVPlayerLayer()
    private(set) var possible = false
    private(set) var active = false
    @ObservationIgnored var onRestore: (() -> Void)?

    @ObservationIgnored private let player = AVQueuePlayer()
    @ObservationIgnored private var looper: AVPlayerLooper?
    @ObservationIgnored private var shown: String?
    @ObservationIgnored private var controller: AVPictureInPictureController?
    @ObservationIgnored private var observations: [NSKeyValueObservation] = []
    @ObservationIgnored private var follow: Timer?
    @ObservationIgnored private var closeWork: DispatchWorkItem?
    @ObservationIgnored private var ourChange = false

    static var supported: Bool { AVPictureInPictureController.isPictureInPictureSupported() }

    func setup() {
        guard controller == nil, Self.supported else { return }
        player.isMuted = true
        player.allowsExternalPlayback = false
        player.preventsDisplaySleepDuringVideoPlayback = false
        playerLayer.player = player
        playerLayer.videoGravity = .resizeAspectFill
        show("idle")
        guard let c = AVPictureInPictureController(playerLayer: playerLayer) else { return }
        c.delegate = self
        c.requiresLinearPlayback = true                          // no ±15 s buttons
        c.canStartPictureInPictureAutomaticallyFromInline = true // leaving the app mid-conversation opens it
        observations.append(c.observe(\.isPictureInPicturePossible, options: [.initial, .new]) { [weak self] c, _ in
            let p = c.isPictureInPicturePossible
            log.info("pip possible: \(p)")
            DispatchQueue.main.async { self?.possible = p }
        })
        // ▶︎ / ❚❚ pressed in the PiP window
        observations.append(player.observe(\.timeControlStatus, options: [.new]) { [weak self] p, _ in
            let status = p.timeControlStatus
            DispatchQueue.main.async { self?.userPlayback(status) }
        })
        controller = c
    }

    func sessionStarted() {
        closeWork?.cancel()
        setup()
        guard controller != nil else { return }
        sync()
        play()
        if follow == nil {
            let t = Timer(timeInterval: 0.25, repeats: true) { [weak self] _ in self?.sync() }
            RunLoop.main.add(t, forMode: .common)
            follow = t
        }
    }

    /// The session ended: show «ready» a moment, then close the window.
    func sessionEnded() {
        show("idle")
        let work = DispatchWorkItem { [weak self] in
            guard let self else { return }
            self.controller?.stopPictureInPicture()
            self.follow?.invalidate()
            self.follow = nil
            self.ourChange = true
            self.player.pause()
            DispatchQueue.main.async { self.ourChange = false }
        }
        closeWork = work
        DispatchQueue.main.asyncAfter(deadline: .now() + (active ? 3 : 0.3), execute: work)
    }

    func toggle() {
        guard let c = controller else { return }
        log.info("pip toggle: active \(c.isPictureInPictureActive) possible \(c.isPictureInPicturePossible) rate \(self.player.rate) items \(self.player.items().count)")
        if c.isPictureInPictureActive { c.stopPictureInPicture() } else { play(); c.startPictureInPicture() }
    }

    // MARK: -

    private func sync() {
        let phase = MainActor.assumeIsolated { AssistantEngine.shared.phase }
        switch phase {
        case .listening: show("listening")
        case .processing: show("processing")
        case .speaking: show("speaking")
        case .idle, .error: show("idle")
        }
    }

    private func show(_ name: String) {
        guard name != shown else { return }
        guard let url = Bundle.main.url(forResource: "orb-" + name, withExtension: "mp4") else {
            log.error("orb video missing: \(name, privacy: .public)")
            return
        }
        shown = name
        let wasPlaying = player.rate > 0
        ourChange = true
        looper?.disableLooping()
        player.removeAllItems()
        let item = AVPlayerItem(url: url)
        looper = AVPlayerLooper(player: player, templateItem: item)
        log.info("orb video \(name, privacy: .public) loaded, looper status \(self.looper?.status.rawValue ?? -1)")
        if wasPlaying || active { player.play() }
        DispatchQueue.main.async { self.ourChange = false }
    }

    private func play() {
        ourChange = true
        player.play()
        DispatchQueue.main.async { self.ourChange = false }
    }

    private func userPlayback(_ status: AVPlayer.TimeControlStatus) {
        guard active, !ourChange else { return }
        MainActor.assumeIsolated {
            let e = AssistantEngine.shared
            switch status {
            case .paused: if e.sessionActive { e.stop() }
            case .playing: if !e.sessionActive || e.phase == .idle { e.talk() }
            default: break
            }
        }
    }
}

extension OrbPiP: AVPictureInPictureControllerDelegate {
    func pictureInPictureControllerDidStartPictureInPicture(_ c: AVPictureInPictureController) { active = true }
    func pictureInPictureControllerDidStopPictureInPicture(_ c: AVPictureInPictureController) { active = false }
    func pictureInPictureController(_ c: AVPictureInPictureController, failedToStartPictureInPictureWithError error: Error) {
        log.error("pip failed to start: \(error.localizedDescription, privacy: .public)")
    }

    func pictureInPictureController(_ c: AVPictureInPictureController,
                                    restoreUserInterfaceForPictureInPictureStopWithCompletionHandler completion: @escaping (Bool) -> Void) {
        onRestore?()
        completion(true)
    }
}

/// Hosts the PiP player layer (the orb video) in the window.
struct PiPSourceView: UIViewRepresentable {
    final class Host: UIView {
        override func layoutSubviews() {
            super.layoutSubviews()
            OrbPiP.shared.playerLayer.frame = bounds
        }
    }

    func makeUIView(context: Context) -> Host {
        let v = Host()
        v.isUserInteractionEnabled = false
        v.backgroundColor = .clear
        v.layer.addSublayer(OrbPiP.shared.playerLayer)
        return v
    }

    func updateUIView(_ uiView: Host, context: Context) {}
}
