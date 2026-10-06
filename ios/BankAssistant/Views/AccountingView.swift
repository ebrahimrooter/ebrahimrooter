import SwiftUI
import WebKit

/// The full accounting panel (server/acc) inside the app. It signs in with a
/// short session from the device token; the web view keeps nothing on disk
/// (non-persistent data store), so no accounting data stays on the phone.
struct AccountingView: View {
    @State private var session: PanelSession?
    @State private var error: String?
    @State private var loading = true
    @State private var reloadID = UUID()

    var body: some View {
        ZStack {
            Theme.paper.ignoresSafeArea()
            if let session, let url = WebApp.url(path: "acc/?embed=1&app=ios") {
                PanelWebView(url: url, token: session.token, loading: $loading)
                    .id(reloadID)
                    .ignoresSafeArea(edges: .bottom)
            }
            if loading && error == nil {
                ProgressView("در حال باز کردن حسابداری…").font(.fa(14)).tint(Theme.income)
            }
            if let error {
                VStack(spacing: 14) {
                    Image(systemName: "exclamationmark.icloud").font(.system(size: 40)).foregroundStyle(Theme.muted)
                    Text(error).font(.fa(14)).foregroundStyle(Theme.ink).multilineTextAlignment(.center)
                    Button("دوباره") { Task { await open() } }
                        .font(.fa(15, .semibold)).foregroundStyle(.white)
                        .padding(.horizontal, 26).padding(.vertical, 12)
                        .background(Capsule().fill(Theme.ink))
                }
                .padding(30)
            }
        }
        .task { if session == nil { await open() } }
    }

    private func open() async {
        error = nil
        loading = true
        do {
            session = try await APIClient.shared.panelSession()
            reloadID = UUID()
        } catch {
            self.error = error.localizedDescription
            loading = false
        }
    }
}

struct PanelWebView: UIViewRepresentable {
    let url: URL
    let token: String
    @Binding var loading: Bool

    func makeCoordinator() -> Coordinator { Coordinator(self) }

    func makeUIView(context: Context) -> WKWebView {
        let config = WKWebViewConfiguration()
        config.websiteDataStore = .nonPersistent()          // memory only
        // the panel reads its session from localStorage («acc_token»); give it before its script runs
        let js = "try{localStorage.setItem('acc_token'," + Self.jsString(token) + ");localStorage.setItem('acc_company','1');}catch(e){}"
        config.userContentController.addUserScript(WKUserScript(source: js, injectionTime: .atDocumentStart, forMainFrameOnly: true))
        let web = WKWebView(frame: .zero, configuration: config)
        web.navigationDelegate = context.coordinator
        web.allowsBackForwardNavigationGestures = true
        web.isOpaque = false
        web.backgroundColor = .clear
        web.scrollView.contentInsetAdjustmentBehavior = .always
        let refresh = UIRefreshControl()
        refresh.addTarget(context.coordinator, action: #selector(Coordinator.reload(_:)), for: .valueChanged)
        web.scrollView.refreshControl = refresh
        web.load(URLRequest(url: url))
        return web
    }

    func updateUIView(_ web: WKWebView, context: Context) {}

    private static func jsString(_ s: String) -> String {
        let data = try? JSONSerialization.data(withJSONObject: [s])
        let arr = data.flatMap { String(data: $0, encoding: .utf8) } ?? "[\"\"]"
        return String(arr.dropFirst().dropLast())
    }

    final class Coordinator: NSObject, WKNavigationDelegate {
        let parent: PanelWebView
        init(_ p: PanelWebView) { parent = p }

        @objc func reload(_ sender: UIRefreshControl) {
            (sender.superview?.superview as? WKWebView)?.reload()
            sender.endRefreshing()
        }

        func webView(_ webView: WKWebView, didFinish navigation: WKNavigation!) { parent.loading = false }
        func webView(_ webView: WKWebView, didFail navigation: WKNavigation!, withError error: Error) { parent.loading = false }
        func webView(_ webView: WKWebView, didFailProvisionalNavigation navigation: WKNavigation!, withError error: Error) { parent.loading = false }

        /// Stay on our own server; anything else (PDF share links, tel:, sms:) opens outside.
        func webView(_ webView: WKWebView, decidePolicyFor action: WKNavigationAction, decisionHandler: @escaping (WKNavigationActionPolicy) -> Void) {
            guard let u = action.request.url else { return decisionHandler(.cancel) }
            if u.host == parent.url.host || u.scheme == "about" || u.scheme == "blob" || u.scheme == "data" {
                return decisionHandler(.allow)
            }
            UIApplication.shared.open(u)
            decisionHandler(.cancel)
        }
    }
}
