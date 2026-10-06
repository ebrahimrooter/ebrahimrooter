import ActivityKit
import AppIntents
import SwiftUI
import WidgetKit

@main
struct AssistantWidgetsBundle: WidgetBundle {
    var body: some Widget {
        AssistantLiveActivity()
        if #available(iOS 18.0, *) {
            StartAssistantControl()
        }
    }
}

/// Lock Screen + Dynamic Island while a voice session runs.
struct AssistantLiveActivity: Widget {
    var body: some WidgetConfiguration {
        ActivityConfiguration(for: AssistantActivityAttributes.self) { context in
            // Lock Screen / banner
            HStack(spacing: 14) {
                PhaseBadge(phase: context.state.phase, size: 44)
                VStack(alignment: .leading, spacing: 4) {
                    Text(context.state.phase.title).font(.headline)
                    Text(context.state.line).font(.subheadline).foregroundStyle(.secondary).lineLimit(2)
                }
                Spacer()
                Button(intent: TalkAssistantIntent()) { Image(systemName: "mic.fill") }
                    .buttonStyle(.bordered).tint(.white)
                Button(intent: StopAssistantIntent()) { Image(systemName: "xmark") }
                    .buttonStyle(.bordered).tint(.white)
            }
            .padding(16)
            .activityBackgroundTint(Color(white: 0.08))
            .activitySystemActionForegroundColor(.white)
            .environment(\.layoutDirection, .rightToLeft)
            .widgetURL(URL(string: "bankassistant://listen"))
        } dynamicIsland: { context in
            DynamicIsland {
                DynamicIslandExpandedRegion(.leading) { PhaseBadge(phase: context.state.phase, size: 36) }
                DynamicIslandExpandedRegion(.center) {
                    Text(context.state.phase.title).font(.headline)
                }
                DynamicIslandExpandedRegion(.trailing) {
                    Button(intent: StopAssistantIntent()) { Image(systemName: "xmark") }.tint(.white)
                }
                DynamicIslandExpandedRegion(.bottom) {
                    Text(context.state.line).font(.subheadline).lineLimit(2).frame(maxWidth: .infinity, alignment: .leading)
                }
            } compactLeading: {
                Image(systemName: context.state.phase.symbol).foregroundStyle(phaseTint(context.state.phase))
            } compactTrailing: {
                Text(short(context.state.phase)).font(.caption2).foregroundStyle(phaseTint(context.state.phase))
            } minimal: {
                Image(systemName: context.state.phase.symbol).foregroundStyle(phaseTint(context.state.phase))
            }
            .widgetURL(URL(string: "bankassistant://listen"))
            .keylineTint(phaseTint(context.state.phase))
        }
    }

    private func short(_ p: AssistantPhase) -> String {
        switch p {
        case .listening: return "گوش"
        case .processing: return "پردازش"
        case .speaking: return "پاسخ"
        case .error: return "خطا"
        case .idle: return "آماده"
        }
    }
}

func phaseTint(_ p: AssistantPhase) -> Color {
    switch p {
    case .listening: return Color(red: 0.55, green: 0.88, blue: 0.56)
    case .processing: return Color(red: 0.36, green: 0.74, blue: 0.43)
    case .speaking: return Color(red: 0.75, green: 0.92, blue: 0.4)
    case .error: return .red
    case .idle: return .white
    }
}

struct PhaseBadge: View {
    var phase: AssistantPhase
    var size: CGFloat
    var body: some View {
        ZStack {
            Circle().fill(phaseTint(phase).opacity(0.22))
            Image(systemName: phase.symbol).font(.system(size: size * 0.42, weight: .semibold)).foregroundStyle(phaseTint(phase))
        }
        .frame(width: size, height: size)
    }
}

/// Control Center / Lock Screen / Action button control (iOS 18).
@available(iOS 18.0, *)
struct StartAssistantControl: ControlWidget {
    var body: some ControlWidgetConfiguration {
        StaticControlConfiguration(kind: "ir.example.bankassistant.start") {
            ControlWidgetButton(action: StartAssistantIntent()) {
                Label("دستیار حسابداری", systemImage: "waveform")
            }
        }
        .displayName("دستیار حسابداری")
        .description("دستیار صوتی حسابداری را باز می‌کند و گوش می‌دهد.")
    }
}
