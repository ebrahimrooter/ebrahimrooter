// Shared by the app and the widget extension.
import ActivityKit
import AppIntents
import Foundation

/// The assistant's states (also shown in the Live Activity / Dynamic Island).
enum AssistantPhase: String, Codable, Hashable, Sendable {
    case idle, listening, processing, speaking, error

    var title: String {
        switch self {
        case .idle: return "آماده"
        case .listening: return "در حال گوش دادن…"
        case .processing: return "در حال پردازش…"
        case .speaking: return "در حال پاسخ دادن…"
        case .error: return "خطا"
        }
    }

    var symbol: String {
        switch self {
        case .idle: return "mic"
        case .listening: return "waveform"
        case .processing: return "ellipsis"
        case .speaking: return "speaker.wave.2.fill"
        case .error: return "exclamationmark.triangle.fill"
        }
    }
}

/// Live Activity: Lock Screen, Dynamic Island (iPhone 14 Pro and later) and banners.
struct AssistantActivityAttributes: ActivityAttributes {
    struct ContentState: Codable, Hashable {
        var phase: AssistantPhase
        /// Last thing heard or said (short).
        var line: String
        var updatedAt: Date
    }
    var deviceName: String
}

/// The app sets these at launch; Live Activity buttons run in the app's process.
enum AssistantIntentBridge {
    @MainActor static var stop: (() -> Void)?
    @MainActor static var talk: (() -> Void)?
}

/// «پایان» button on the Live Activity (iOS 17+, runs without opening the app).
struct StopAssistantIntent: LiveActivityIntent {
    static var title: LocalizedStringResource = "پایان دستیار"
    static var description = IntentDescription("دستیار صوتی را متوقف می‌کند.")

    init() {}

    @MainActor
    func perform() async throws -> some IntentResult {
        AssistantIntentBridge.stop?()
        return .result()
    }
}

/// «بپرس» button: starts another listening turn while the voice session is still running.
struct TalkAssistantIntent: LiveActivityIntent {
    static var title: LocalizedStringResource = "صحبت با دستیار"

    init() {}

    @MainActor
    func perform() async throws -> some IntentResult {
        AssistantIntentBridge.talk?()
        return .result()
    }
}
