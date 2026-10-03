import AppIntents
import Foundation

/// Opens the app and starts listening. Used by Siri / Spotlight / the Action
/// button (App Shortcuts), the Control Center control (iOS 18) and the widget.
/// The microphone may only be started from the foreground, so it opens the app.
struct StartAssistantIntent: AppIntent {
    static var title: LocalizedStringResource = "شروع دستیار حسابداری"
    static var description = IntentDescription("دستیار صوتی حسابداری را باز می‌کند و گوش می‌دهد.")
    static var openAppWhenRun: Bool = true

    init() {}

    @MainActor
    func perform() async throws -> some IntentResult {
        AssistantIntentBridge.talk?()
        return .result()
    }
}
