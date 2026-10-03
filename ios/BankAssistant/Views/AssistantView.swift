import SwiftUI

/// The floating assistant window (inside the app). Leaving the app keeps the
/// session going; outside, its status shows in the Live Activity / Dynamic Island.
struct AssistantView: View {
    @Environment(AssistantEngine.self) private var engine
    @Environment(\.openURL) private var openURL
    var onUnpair: () -> Void

    @State private var offset: CGSize = .zero
    @State private var drag: CGSize = .zero
    @State private var showHistory = false
    @State private var showSettings = false

    var body: some View {
        ZStack {
            LinearGradient(colors: [Color(white: 0.06), Color(red: 0.05, green: 0.06, blue: 0.12)], startPoint: .top, endPoint: .bottom)
                .ignoresSafeArea()

            VStack(spacing: 18) {
                Spacer()
                floatingWindow
                    .offset(x: offset.width + drag.width, y: offset.height + drag.height)
                    .gesture(DragGesture()
                        .onChanged { drag = $0.translation }
                        .onEnded { v in
                            offset.width += v.translation.width
                            offset.height += v.translation.height
                            drag = .zero
                        })
                    .animation(.spring(response: 0.35, dampingFraction: 0.8), value: drag)
                Spacer()
                if engine.sessionActive {
                    Label("می‌توانی به صفحه‌ی اصلی بروی؛ دستیار ادامه می‌دهد و وضعیتش روی صفحه‌ی قفل و Dynamic Island دیده می‌شود.", systemImage: "info.circle")
                        .font(.footnote).foregroundStyle(.white.opacity(0.6))
                        .multilineTextAlignment(.center).padding(.horizontal, 32)
                }
                bottomBar
            }
            .padding(.bottom, 12)
        }
        .sheet(isPresented: $showHistory) { HistoryView(turns: engine.turns) }
        .sheet(isPresented: $showSettings) { SettingsView(onUnpair: onUnpair) }
    }

    private var floatingWindow: some View {
        VStack(spacing: 10) {
            HStack {
                Button { engine.stop() } label: { Image(systemName: "xmark") }
                    .buttonStyle(CircleButton()).opacity(engine.sessionActive ? 1 : 0.35)
                    .accessibilityLabel("بستن دستیار")
                Spacer()
                Text(engine.phase.title).font(.subheadline.weight(.semibold)).foregroundStyle(.white.opacity(0.85))
                Spacer()
                Button { showHistory = true } label: { Image(systemName: "arrow.up.left.and.arrow.down.right") }
                    .buttonStyle(CircleButton())
                    .accessibilityLabel("باز کردن گفتگو")
            }
            VoiceOrbView(phase: engine.phase, level: engine.level)
                .frame(width: 200, height: 200)
                .contentShape(Circle())
                .onTapGesture { engine.toggle() }
                .accessibilityAddTraits(.isButton)
                .accessibilityHint(engine.sessionActive ? "دوباره صحبت کن یا متوقف کن" : "شروع دستیار")
            Text(engine.line)
                .font(.body).foregroundStyle(.white)
                .multilineTextAlignment(.center).lineLimit(4).minimumScaleFactor(0.8)
                .frame(maxWidth: .infinity, minHeight: 66)
            if engine.micDenied {
                Button("باز کردن تنظیمات میکروفون") { Permissions.openSettings() }
                    .font(.footnote.weight(.semibold))
            }
        }
        .padding(18)
        .frame(width: 320)
        .background(RoundedRectangle(cornerRadius: 34, style: .continuous).fill(Color(white: 0.11).opacity(0.96)))
        .overlay(RoundedRectangle(cornerRadius: 34, style: .continuous).strokeBorder(.white.opacity(0.08)))
        .shadow(color: .black.opacity(0.55), radius: 30, y: 12)
    }

    private var bottomBar: some View {
        HStack(spacing: 24) {
            Button { if let u = WebApp.url() { openURL(u) } } label: { Label("حسابداری", systemImage: "list.bullet.rectangle") }
            Button { showSettings = true } label: { Label("تنظیمات", systemImage: "gearshape") }
        }
        .font(.footnote).foregroundStyle(.white.opacity(0.7))
    }
}

struct CircleButton: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label
            .font(.system(size: 13, weight: .bold))
            .foregroundStyle(.white)
            .frame(width: 32, height: 32)
            .background(Circle().fill(.white.opacity(configuration.isPressed ? 0.25 : 0.12)))
    }
}

struct HistoryView: View {
    let turns: [AssistantEngine.Turn]
    var body: some View {
        NavigationStack {
            List(turns.reversed()) { t in
                VStack(alignment: .leading, spacing: 6) {
                    Text(t.heard).font(.subheadline).foregroundStyle(.secondary)
                    Text(t.reply)
                }
                .padding(.vertical, 4)
            }
            .overlay { if turns.isEmpty { ContentUnavailableView("هنوز گفتگویی نیست", systemImage: "waveform") } }
            .navigationTitle("گفتگو")
        }
    }
}

struct SettingsView: View {
    var onUnpair: () -> Void
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            Form {
                Section("سرور") {
                    Text(APIClient.shared.server?.host ?? "—")
                }
                Section {
                    Button("قطع اتصال این گوشی", role: .destructive) { onUnpair(); dismiss() }
                } footer: {
                    Text("کلید دسترسی فقط در Keychain همین گوشی است. از تنظیمات اپ حسابداری هم می‌توانی دسترسی این گوشی را لغو کنی.")
                }
                Section("میان‌برها") {
                    Text("«Hey Siri, Ask Bank Assistant»، دکمه‌ی Action، Control Center و صفحه‌ی قفل هم دستیار را باز می‌کنند.")
                        .font(.footnote)
                }
            }
            .navigationTitle("تنظیمات")
        }
    }
}
