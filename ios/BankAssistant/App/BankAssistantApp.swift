import SwiftUI

@main
struct BankAssistantApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @State private var engine = AssistantEngine.shared
    @State private var store = FinanceStore.shared
    @State private var pairing: DeepLink?
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView(pairing: $pairing)
                .environment(engine)
                .environment(store)
                .environment(\.layoutDirection, .rightToLeft)
                .environment(\.locale, Locale(identifier: "fa_IR"))
                .preferredColorScheme(.light)
                .tint(Theme.income)
                .onOpenURL(perform: open)
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { a in
                    if let url = a.webpageURL { open(url) }
                }
        }
        .onChange(of: scenePhase) { _, phase in
            if phase == .active { Task { await store.refresh() } }
            // In the background the session lives only while it listens / speaks;
            // an idle session is closed so the app does not hold the audio session for nothing.
            if phase == .background, engine.sessionActive, engine.phase == .idle || engine.phase == .error {
                engine.stop()
            }
        }
    }

    private func open(_ url: URL) {
        switch DeepLink(url) {
        case .listen?: engine.toggle()
        case let link?: pairing = link
        case nil: break
        }
    }
}

struct RootView: View {
    @Environment(FinanceStore.self) private var store
    @Binding var pairing: DeepLink?
    @State private var paired = APIClient.shared.isPaired || Demo.enabled
    @AppStorage("onboarded") private var onboarded = false

    var body: some View {
        Group {
            if paired && pairing == nil {
                MainTabView(onUnpair: unpair)
            } else if (!onboarded || ProcessInfo.processInfo.arguments.contains("-onboarding")) && pairing == nil {
                OnboardingView { withAnimation { onboarded = true } }
            } else {
                PairingView(link: pairing) {
                    paired = true
                    pairing = nil
                    onboarded = true
                    Task { await store.refresh() }
                }
            }
        }
        .onReceive(NotificationCenter.default.publisher(for: .deviceUnpaired)) { _ in
            store.reset()
            paired = false
        }
    }

    private func unpair() {
        AssistantEngine.shared.stop()
        APIClient.shared.unpair()
        store.reset()
        paired = false
    }
}
