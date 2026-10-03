import ActivityKit
import Foundation

/// The assistant's status outside the app: Lock Screen, Dynamic Island, banners.
/// Started when a voice session starts (app in the foreground, as iOS requires),
/// updated while the session runs in the background, ended with the session.
@MainActor
final class LiveActivityController {
    private var activity: Activity<AssistantActivityAttributes>?

    var enabled: Bool { ActivityAuthorizationInfo().areActivitiesEnabled }

    func start(phase: AssistantPhase, line: String) {
        guard enabled, activity == nil else { return update(phase: phase, line: line) }
        let state = AssistantActivityAttributes.ContentState(phase: phase, line: line, updatedAt: .now)
        do {
            activity = try Activity.request(attributes: AssistantActivityAttributes(deviceName: "iPhone"),
                                            content: .init(state: state, staleDate: .now.addingTimeInterval(15 * 60)),
                                            pushType: nil)
        } catch {
            activity = nil      // Live Activities off in Settings, or too many running: the app still works
        }
    }

    func update(phase: AssistantPhase, line: String) {
        guard let activity else { return }
        let state = AssistantActivityAttributes.ContentState(phase: phase, line: String(line.prefix(90)), updatedAt: .now)
        Task { await activity.update(.init(state: state, staleDate: .now.addingTimeInterval(15 * 60))) }
    }

    func end(line: String = "پایان") {
        guard let activity else { return }
        let state = AssistantActivityAttributes.ContentState(phase: .idle, line: line, updatedAt: .now)
        self.activity = nil
        Task { await activity.end(.init(state: state, staleDate: nil), dismissalPolicy: .after(.now.addingTimeInterval(4))) }
    }
}
