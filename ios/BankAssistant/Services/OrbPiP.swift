import AVFoundation
import AVKit
import Observation
import SwiftUI
import UIKit

/// The orb in a floating Picture-in-Picture window: stays on top of the Home
/// Screen and other apps while the voice session runs. Official API only —
/// AVPictureInPictureController with a sample-buffer layer that this class
/// fills with frames of the orb (drawn like VoiceOrbView) and the current line.
/// Play/pause in the window = talk / stop; the restore button opens the app.
@Observable
final class OrbPiP: NSObject {
    static let shared = OrbPiP()

    /// The source layer; must sit in the window (PiPSourceView) for PiP to be possible.
    let layer = AVSampleBufferDisplayLayer()
    private(set) var possible = false
    private(set) var active = false
    /// Called when the user taps «back to app» in the window.
    @ObservationIgnored var onRestore: (() -> Void)?

    @ObservationIgnored private var controller: AVPictureInPictureController?
    @ObservationIgnored private var observation: NSKeyValueObservation?
    @ObservationIgnored private var timer: Timer?
    @ObservationIgnored private var closeWork: DispatchWorkItem?
    @ObservationIgnored private var format: CMVideoFormatDescription?

    private let side = 600               // pixels of a (square) frame
    private let fps = 15.0

    static var supported: Bool { AVPictureInPictureController.isPictureInPictureSupported() }

    func setup() {
        guard controller == nil, Self.supported else { return }
        layer.videoGravity = .resizeAspect
        let source = AVPictureInPictureController.ContentSource(sampleBufferDisplayLayer: layer, playbackDelegate: self)
        let c = AVPictureInPictureController(contentSource: source)
        c.delegate = self
        c.requiresLinearPlayback = true                          // no skip buttons
        c.canStartPictureInPictureAutomaticallyFromInline = true // leaving the app mid-conversation opens it
        observation = c.observe(\.isPictureInPicturePossible, options: [.initial, .new]) { [weak self] c, _ in
            let p = c.isPictureInPicturePossible
            DispatchQueue.main.async { self?.possible = p }
        }
        controller = c
    }

    /// Frames are drawn only while a voice session runs (the audio session keeps
    /// the app alive in the background then).
    func sessionStarted() {
        closeWork?.cancel()
        setup()
        guard controller != nil, timer == nil else { return }
        renderFrame()
        let t = Timer(timeInterval: 1 / fps, repeats: true) { [weak self] _ in self?.renderFrame() }
        RunLoop.main.add(t, forMode: .common)
        timer = t
    }

    /// The session ended: show the last line a moment, then close the window.
    func sessionEnded() {
        let work = DispatchWorkItem { [weak self] in
            guard let self else { return }
            self.controller?.stopPictureInPicture()
            self.timer?.invalidate()
            self.timer = nil
        }
        closeWork = work
        DispatchQueue.main.asyncAfter(deadline: .now() + (active ? 3 : 0.5), execute: work)
    }

    func toggle() {
        guard let c = controller else { return }
        if c.isPictureInPictureActive { c.stopPictureInPicture() } else { c.startPictureInPicture() }
    }

    // MARK: - frames

    private func renderFrame() {
        MainActor.assumeIsolated {
            let e = AssistantEngine.shared
            let frame = OrbPiPFrame(phase: e.phase, level: e.level, line: e.line, t: Date().timeIntervalSinceReferenceDate)
            let r = ImageRenderer(content: frame)
            r.proposedSize = ProposedViewSize(width: 300, height: 300)
            r.scale = CGFloat(side) / 300
            guard let image = r.cgImage, let buffer = pixelBuffer(from: image), let sample = sampleBuffer(from: buffer) else { return }
            let renderer = layer.sampleBufferRenderer
            if renderer.status == .failed { renderer.flush() }
            renderer.enqueue(sample)
        }
    }

    private func pixelBuffer(from image: CGImage) -> CVPixelBuffer? {
        var pb: CVPixelBuffer?
        let attrs: [CFString: Any] = [kCVPixelBufferCGImageCompatibilityKey: true, kCVPixelBufferCGBitmapContextCompatibilityKey: true,
                                      kCVPixelBufferIOSurfacePropertiesKey: [:] as CFDictionary]
        guard CVPixelBufferCreate(kCFAllocatorDefault, side, side, kCVPixelFormatType_32BGRA, attrs as CFDictionary, &pb) == kCVReturnSuccess,
              let pb else { return nil }
        CVPixelBufferLockBaseAddress(pb, [])
        defer { CVPixelBufferUnlockBaseAddress(pb, []) }
        guard let ctx = CGContext(data: CVPixelBufferGetBaseAddress(pb), width: side, height: side, bitsPerComponent: 8,
                                  bytesPerRow: CVPixelBufferGetBytesPerRow(pb), space: CGColorSpaceCreateDeviceRGB(),
                                  bitmapInfo: CGImageAlphaInfo.premultipliedFirst.rawValue | CGBitmapInfo.byteOrder32Little.rawValue)
        else { return nil }
        ctx.draw(image, in: CGRect(x: 0, y: 0, width: side, height: side))
        return pb
    }

    private func sampleBuffer(from pb: CVPixelBuffer) -> CMSampleBuffer? {
        if format == nil {
            CMVideoFormatDescriptionCreateForImageBuffer(allocator: kCFAllocatorDefault, imageBuffer: pb, formatDescriptionOut: &format)
        }
        guard let format else { return nil }
        var timing = CMSampleTimingInfo(duration: CMTime(value: 1, timescale: CMTimeScale(fps)),
                                        presentationTimeStamp: CMClockGetTime(CMClockGetHostTimeClock()), decodeTimeStamp: .invalid)
        var sb: CMSampleBuffer?
        guard CMSampleBufferCreateReadyWithImageBuffer(allocator: kCFAllocatorDefault, imageBuffer: pb, formatDescription: format,
                                                       sampleTiming: &timing, sampleBufferOut: &sb) == noErr, let sb else { return nil }
        if let attachments = CMSampleBufferGetSampleAttachmentsArray(sb, createIfNecessary: true), CFArrayGetCount(attachments) > 0 {
            let dict = unsafeBitCast(CFArrayGetValueAtIndex(attachments, 0), to: CFMutableDictionary.self)
            CFDictionarySetValue(dict, Unmanaged.passUnretained(kCMSampleAttachmentKey_DisplayImmediately).toOpaque(),
                                 Unmanaged.passUnretained(kCFBooleanTrue).toOpaque())
        }
        return sb
    }
}

extension OrbPiP: AVPictureInPictureControllerDelegate {
    func pictureInPictureControllerDidStartPictureInPicture(_ c: AVPictureInPictureController) { active = true }
    func pictureInPictureControllerDidStopPictureInPicture(_ c: AVPictureInPictureController) { active = false }

    func pictureInPictureController(_ c: AVPictureInPictureController,
                                    restoreUserInterfaceForPictureInPictureStopWithCompletionHandler completion: @escaping (Bool) -> Void) {
        onRestore?()
        completion(true)
    }
}

extension OrbPiP: AVPictureInPictureSampleBufferPlaybackDelegate {
    /// ▶︎ = talk again, ❚❚ = stop the conversation.
    func pictureInPictureController(_ c: AVPictureInPictureController, setPlaying playing: Bool) {
        MainActor.assumeIsolated {
            let e = AssistantEngine.shared
            if playing { e.talk() } else { e.stop() }
        }
    }

    func pictureInPictureControllerTimeRangeForPlayback(_ c: AVPictureInPictureController) -> CMTimeRange {
        CMTimeRange(start: .negativeInfinity, duration: .positiveInfinity)     // live
    }

    func pictureInPictureControllerIsPlaybackPaused(_ c: AVPictureInPictureController) -> Bool {
        MainActor.assumeIsolated { !AssistantEngine.shared.sessionActive || AssistantEngine.shared.phase == .idle }
    }

    func pictureInPictureController(_ c: AVPictureInPictureController, didTransitionToRenderSize newRenderSize: CMVideoDimensions) {}

    func pictureInPictureController(_ c: AVPictureInPictureController, skipByInterval skipInterval: CMTime,
                                    completion completionHandler: @escaping () -> Void) {
        completionHandler()
    }
}

/// One PiP frame: the orb and what it says, on the dark green background.
struct OrbPiPFrame: View {
    let phase: AssistantPhase
    let level: Float
    let line: String
    let t: Double

    var body: some View {
        ZStack {
            Theme.deep
            RadialGradient(colors: [Theme.forest, .clear], center: .top, startRadius: 0, endRadius: 260)
            VStack(spacing: 6) {
                Canvas { ctx, size in VoiceOrbView.draw(&ctx, size: size, t: t, level: level, phase: phase) }
                    .frame(width: 190, height: 190)
                Text(line)
                    .font(.system(size: 17, weight: .semibold, design: .rounded))
                    .foregroundStyle(.white)
                    .multilineTextAlignment(.center)
                    .lineLimit(3)
                    .minimumScaleFactor(0.7)
                    .padding(.horizontal, 18)
                    .frame(height: 80, alignment: .top)
            }
        }
        .frame(width: 300, height: 300)
        .environment(\.layoutDirection, .rightToLeft)
    }
}

/// Holds the PiP source layer inside the window (tiny, nearly invisible).
struct PiPSourceView: UIViewRepresentable {
    final class Host: UIView {
        override func layoutSubviews() {
            super.layoutSubviews()
            OrbPiP.shared.layer.frame = bounds
        }
    }

    func makeUIView(context: Context) -> Host {
        let v = Host()
        v.isUserInteractionEnabled = false
        v.layer.addSublayer(OrbPiP.shared.layer)
        return v
    }

    func updateUIView(_ uiView: Host, context: Context) {}
}
