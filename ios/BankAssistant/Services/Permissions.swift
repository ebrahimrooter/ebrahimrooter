import AVFoundation
import UIKit

/// Standard iOS microphone permission.
enum Permissions {
    enum Mic { case granted, denied, undetermined }

    static var mic: Mic {
        switch AVAudioApplication.shared.recordPermission {
        case .granted: return .granted
        case .denied: return .denied
        default: return .undetermined
        }
    }

    static func requestMic() async -> Bool {
        await AVAudioApplication.requestRecordPermission()
    }

    @MainActor
    static func openSettings() {
        if let url = URL(string: UIApplication.openSettingsURLString) { UIApplication.shared.open(url) }
    }
}
