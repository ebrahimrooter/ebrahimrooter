import SwiftUI

@main
struct BankAssistantApp: App {
    @State private var engine = AssistantEngine.shared
    @State private var pairing: DeepLink?
    @Environment(\.scenePhase) private var scenePhase

    var body: some Scene {
        WindowGroup {
            RootView(pairing: $pairing)
                .environment(engine)
                .environment(\.layoutDirection, .rightToLeft)
                .preferredColorScheme(.dark)
                .onOpenURL(perform: open)
                .onContinueUserActivity(NSUserActivityTypeBrowsingWeb) { a in
                    if let url = a.webpageURL { open(url) }
                }
        }
        .onChange(of: scenePhase) { _, phase in
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
    @Environment(AssistantEngine.self) private var engine
    @Binding var pairing: DeepLink?
    @State private var paired = APIClient.shared.isPaired

    var body: some View {
        Group {
            if paired && pairing == nil {
                AssistantView(onUnpair: { APIClient.shared.unpair(); paired = false })
            } else {
                PairingView(link: pairing) { paired = true; pairing = nil }
            }
        }
    }
}
