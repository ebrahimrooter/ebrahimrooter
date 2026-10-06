import SwiftUI

/// The voice assistant in the bottom part of the screen (a sheet over the app,
/// like Siri). Leaving the app keeps the session going; outside, its status shows
/// in the Live Activity / Dynamic Island.
struct AssistantSheet: View {
    @Environment(AssistantEngine.self) private var engine
    @State private var showHistory = false

    var body: some View {
        VStack(spacing: 12) {
            HStack {
                Button { engine.stop() } label: { Image(systemName: "xmark") }
                    .buttonStyle(CircleButton()).opacity(engine.sessionActive ? 1 : 0.35)
                    .accessibilityLabel("بستن دستیار")
                Spacer()
                Text(engine.phase.title).font(.fa(15, .semibold)).foregroundStyle(.primary.opacity(0.85))
                Spacer()
                Button { showHistory = true } label: { Image(systemName: "text.bubble") }
                    .buttonStyle(CircleButton())
                    .accessibilityLabel("گفتگو")
            }
            .padding(.top, 14)
            VoiceOrbView(phase: engine.phase, level: engine.level)
                .frame(width: 170, height: 170)
                .contentShape(Circle())
                .onTapGesture { engine.toggle() }
                .accessibilityAddTraits(.isButton)
                .accessibilityHint(engine.sessionActive ? "دوباره صحبت کن یا متوقف کن" : "شروع دستیار")
            Text(engine.line)
                .font(.fa(17, .medium))
                .multilineTextAlignment(.center).lineLimit(5).minimumScaleFactor(0.8)
                .frame(maxWidth: .infinity, minHeight: 60)
            if engine.micDenied {
                Button("باز کردن تنظیمات میکروفون") { Permissions.openSettings() }
                    .font(.fa(13, .semibold))
            } else if !engine.sessionActive {
                Text("مثلاً: «فروش امروز چقدر بوده؟» · «از علی پنج میلیون نقد گرفتم» · «چک‌های این هفته»")
                    .font(.fa(12)).foregroundStyle(.secondary).multilineTextAlignment(.center)
            }
            Spacer(minLength: 0)
        }
        .padding(.horizontal, 22)
        .onAppear { if !engine.sessionActive { engine.toggle() } }
        .sheet(isPresented: $showHistory) { HistoryView(turns: engine.turns) }
    }
}

struct CircleButton: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 13, weight: .bold))
            .foregroundStyle(.primary)
            .frame(width: 34, height: 34)
            .background(Circle().fill(.primary.opacity(configuration.isPressed ? 0.2 : 0.08)))
    }
}

struct HistoryView: View {
    let turns: [AssistantEngine.Turn]
    var body: some View {
        NavigationStack {
            List(turns.reversed()) { t in
                VStack(alignment: .leading, spacing: 6) {
                    Text(t.heard).font(.fa(14)).foregroundStyle(.secondary)
                    Text(t.reply).font(.fa(15))
                }
                .padding(.vertical, 4)
            }
            .overlay { if turns.isEmpty { ContentUnavailableView("هنوز گفتگویی نیست", systemImage: "waveform") } }
            .navigationTitle("گفتگو")
        }
    }
}
