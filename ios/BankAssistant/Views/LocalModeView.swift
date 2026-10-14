import AVFoundation
import SwiftUI
import UIKit
import WebKit

/// The app without a server: the phone web app with its own books (Resources/www,
/// made by tools/local-app/build.sh), the bank SMS from Bale. Same as the Android app:
///   bankapp://local/app/index.html     the bundled web app
///   bankapp://local/__bale/<method>    Bale Bot API, the token is added here (never in the page)
/// The books are a file in Application Support (local-books.json); speaking and listening
/// use the app's own Persian voice and ear (LocalVoice / LocalEar).
enum LocalMode {
    static let tokenKey = "bale_bot_token"
    static let flagKey = "localMode"
    static var enabled: Bool {
        get { UserDefaults.standard.bool(forKey: flagKey) }
        set { UserDefaults.standard.set(newValue, forKey: flagKey) }
    }
    static var token: String? {
        if let t = KeychainStore.get(tokenKey) { return t }
        #if DEBUG
        // tests: an unsigned simulator build has no Keychain (-localToken X)
        return UserDefaults.standard.string(forKey: "localToken")
        #else
        return nil
        #endif
    }
    /// Only for tests (a pretend Bale: -baleBase http://localhost:8841).
    static var base: String {
        UserDefaults.standard.string(forKey: "baleBase") ?? "https://tapi.bale.ai"
    }

    /// Test builds: what happened, in Application Support/local-debug.txt (read by CI).
    static func debug(_ line: String) {
        #if DEBUG
        NSLog("LOCAL_MODE %@", line)
        let f = booksURL.deletingLastPathComponent().appendingPathComponent("local-debug.txt")
        if let h = try? FileHandle(forWritingTo: f) { h.seekToEndOfFile(); h.write(Data((line + "\n").utf8)); try? h.close() }
        else { try? Data((line + "\n").utf8).write(to: f) }
        #endif
    }

    static var booksURL: URL {
        let dir = FileManager.default.urls(for: .applicationSupportDirectory, in: .userDomainMask)[0]
        try? FileManager.default.createDirectory(at: dir, withIntermediateDirectories: true)
        return dir.appendingPathComponent("local-books.json")
    }

    /// One Bot API call, JSON in and out.
    static func bale(_ method: String, json: Data, token: String? = LocalMode.token) async throws -> Data {
        guard let token, let url = URL(string: "\(base)/bot\(token)/\(method)") else { throw APIError.server("توکن ربات بله گذاشته نشده") }
        var req = URLRequest(url: url, timeoutInterval: 30)
        req.httpMethod = "POST"
        req.setValue("application/json; charset=utf-8", forHTTPHeaderField: "Content-Type")
        req.httpBody = json
        return try await URLSession.shared.data(for: req).0
    }

    static func sendDocument(chat: String, name: String, data: Data, caption: String) async throws {
        guard let token, let url = URL(string: "\(base)/bot\(token)/sendDocument") else { throw APIError.server("توکن ربات بله گذاشته نشده") }
        let b = "----ba" + UUID().uuidString
        var body = Data()
        func field(_ k: String, _ v: String) { body.append("--\(b)\r\nContent-Disposition: form-data; name=\"\(k)\"\r\n\r\n\(v)\r\n".data(using: .utf8)!) }
        field("chat_id", chat)
        if !caption.isEmpty { field("caption", caption) }
        body.append("--\(b)\r\nContent-Disposition: form-data; name=\"document\"; filename=\"\(name)\"\r\nContent-Type: application/octet-stream\r\n\r\n".data(using: .utf8)!)
        body.append(data)
        body.append("\r\n--\(b)--\r\n".data(using: .utf8)!)
        var req = URLRequest(url: url, timeoutInterval: 60)
        req.httpMethod = "POST"
        req.setValue("multipart/form-data; boundary=\(b)", forHTTPHeaderField: "Content-Type")
        req.httpBody = body
        let (d, _) = try await URLSession.shared.data(for: req)
        let j = try? JSONSerialization.jsonObject(with: d) as? [String: Any]
        if (j?["ok"] as? Bool) != true { throw APIError.server((j?["description"] as? String) ?? "به بله نرفت") }
    }
}

/// Files, Bale and the voice for the page (WKURLSchemeHandler + window.webkit.messageHandlers.ba).
@MainActor
final class LocalBridge: NSObject, WKURLSchemeHandler, WKScriptMessageHandler {
    weak var web: WKWebView?
    private var stopped = Set<ObjectIdentifier>()
    private let root = Bundle.main.url(forResource: "www", withExtension: nil)
    private let player = SpeechPlayer()
    private lazy var dictation = Dictation()

    // MARK: bankapp:// — the bundled files and /__bale/

    func webView(_ webView: WKWebView, start task: WKURLSchemeTask) {
        guard let url = task.request.url else { return }
        let path = url.path
        if path.hasPrefix("/__bale/") {
            let method = String(path.dropFirst("/__bale/".count)).filter { $0.isLetter || $0.isNumber }
            let q = URLComponents(url: url, resolvingAgainstBaseURL: false)?.queryItems?.first { $0.name == "q" }?.value ?? "{}"
            Task { @MainActor in
                let data: Data
                do { data = try await LocalMode.bale(method, json: Data(q.utf8)) } catch {
                    LocalMode.debug("bale \(method) failed: \(error)")
                    data = (try? JSONSerialization.data(withJSONObject: ["ok": false, "description": error.localizedDescription])) ?? Data()
                }
                LocalMode.debug("bale \(method) -> \(data.count) bytes")
                self.respond(task, data, mime: "application/json")
            }
            return
        }
        LocalMode.debug("load \(path)")
        guard let root, let file = Optional(root.appendingPathComponent(path)),
              file.standardizedFileURL.path.hasPrefix(root.standardizedFileURL.path),
              let data = try? Data(contentsOf: file) else {
            respond(task, Data(), mime: "text/plain", status: 404)
            return
        }
        respond(task, data, mime: Self.mime(file.pathExtension))
    }

    func webView(_ webView: WKWebView, stop task: WKURLSchemeTask) {
        stopped.insert(ObjectIdentifier(task))
    }

    private func respond(_ task: WKURLSchemeTask, _ data: Data, mime: String, status: Int = 200) {
        guard !stopped.contains(ObjectIdentifier(task)), let url = task.request.url else { return }
        let r = HTTPURLResponse(url: url, statusCode: status, httpVersion: "HTTP/1.1",
                                headerFields: ["Content-Type": mime, "Content-Length": "\(data.count)", "Cache-Control": "no-store"])!
        task.didReceive(r)
        task.didReceive(data)
        task.didFinish()
    }

    private static func mime(_ ext: String) -> String {
        switch ext.lowercased() {
        case "html": "text/html; charset=utf-8"
        case "js": "text/javascript; charset=utf-8"
        case "css": "text/css; charset=utf-8"
        case "svg": "image/svg+xml"
        case "png": "image/png"
        case "json", "webmanifest": "application/json"
        case "woff2": "font/woff2"
        case "mp4": "video/mp4"
        default: "application/octet-stream"
        }
    }

    // MARK: messages from the page

    func userContentController(_ uc: WKUserContentController, didReceive message: WKScriptMessage) {
        guard let m = message.body as? [String: Any], let op = m["op"] as? String else { return }
        let id = m["id"] as? String ?? ""
        switch op {
        case "save":
            if let s = m["data"] as? String {
                do { try Data(s.utf8).write(to: LocalMode.booksURL, options: .atomic) } catch { LocalMode.debug("save failed: \(error)") }
            }
        case "log":
            LocalMode.debug("page: \(m["text"] as? String ?? "")")
        case "saveFile":
            guard let name = m["name"] as? String, let b64 = m["b64"] as? String, let data = Data(base64Encoded: b64) else { return done(id, false, "فایل خراب بود") }
            let f = FileManager.default.temporaryDirectory.appendingPathComponent(name.replacingOccurrences(of: "/", with: "_"))
            do { try data.write(to: f, options: .atomic) } catch { return done(id, false, error.localizedDescription) }
            share(f)
            done(id, true, "")
        case "sendDocument":
            guard let chat = m["chat"] as? String, let name = m["name"] as? String, let b64 = m["b64"] as? String,
                  let data = Data(base64Encoded: b64) else { return done(id, false, "فایل خراب بود") }
            Task { @MainActor in
                do { try await LocalMode.sendDocument(chat: chat, name: name, data: data, caption: m["caption"] as? String ?? ""); self.done(id, true, "") }
                catch { self.done(id, false, error.localizedDescription) }
            }
        case "speak":
            let text = m["text"] as? String ?? ""
            Task { @MainActor in
                var ok = false
                if let wav = await LocalVoice.shared.wav(text) {
                    try? AVAudioSession.sharedInstance().setCategory(.playback)
                    try? AVAudioSession.sharedInstance().setActive(true)
                    await self.player.play(wav)
                    ok = true
                }
                self.js("window.onAndroidSpoken && window.onAndroidSpoken(\(Self.q(id)), \(ok))")
            }
        case "stopSpeaking":
            player.stop()
        case "listen":
            Task { @MainActor in
                let heard = await self.dictation.toggle()
                self.js("window.onAndroidHeard && window.onAndroidHeard(\(Self.q(id)), \(heard.map { Self.q($0) } ?? "null"))")
            }
        case "changeMode":
            LocalMode.enabled = false
            NotificationCenter.default.post(name: .localModeChanged, object: nil)
        default:
            break
        }
    }

    private func done(_ id: String, _ ok: Bool, _ msg: String) {
        js("window.__baCb && window.__baCb(\(Self.q(id)), \(ok), \(Self.q(msg)))")
    }

    private func js(_ s: String) {
        DispatchQueue.main.async { self.web?.evaluateJavaScript(s) }
    }

    /// A JS string literal.
    static func q(_ s: String) -> String {
        let d = (try? JSONSerialization.data(withJSONObject: [s])) ?? Data("[\"\"]".utf8)
        return String(String(decoding: d, as: UTF8.self).dropFirst().dropLast())
    }

    private func share(_ file: URL) {
        DispatchQueue.main.async {
            guard let top = UIApplication.shared.connectedScenes.compactMap({ ($0 as? UIWindowScene)?.keyWindow }).first?.rootViewController else { return }
            var vc = top
            while let p = vc.presentedViewController { vc = p }
            let a = UIActivityViewController(activityItems: [file], applicationActivities: nil)
            a.popoverPresentationController?.sourceView = vc.view
            vc.present(a, animated: true)
        }
    }

    /// window.BANative (app/local/engine.js) and window.NativeVoice (app.js Voice), before the page's own scripts.
    static func pageScript() -> String {
        let books = (try? String(contentsOf: LocalMode.booksURL, encoding: .utf8)).map { q($0) } ?? "null"
        let voice = LocalVoice.shared.bundled, ear = LocalEar.shared.bundled
        return """
        (function () {
          var post = function (o) { window.webkit.messageHandlers.ba.postMessage(o); };
          window.addEventListener('error', function (e) { post({ op: 'log', text: 'error: ' + e.message + ' @ ' + e.filename + ':' + e.lineno }); });
          window.addEventListener('unhandledrejection', function (e) { post({ op: 'log', text: 'rejection: ' + (e.reason && e.reason.message || e.reason) }); });
          var books = \(books);
          window.BANative = {
            load: function () { return books; },
            save: function (s) { books = s; post({ op: 'save', data: s }); },
            saveFile: function (id, name, mime, b64) { post({ op: 'saveFile', id: id, name: name, mime: mime, b64: b64 }); },
            sendDocument: function (id, chat, name, b64, caption) { post({ op: 'sendDocument', id: id, chat: chat, name: name, b64: b64, caption: caption }); },
            changeMode: function () { post({ op: 'changeMode' }); }
          };
          window.NativeVoice = {
            localVoice: function () { return \(voice); },
            canListen: function () { return \(ear); },
            speak: function (id, text) { post({ op: 'speak', id: id, text: text }); },
            listen: function (id) { post({ op: 'listen', id: id }); },
            stopSpeaking: function () { post({ op: 'stopSpeaking' }); }
          };
        })();
        """
    }
}

extension Notification.Name {
    static let localModeChanged = Notification.Name("localModeChanged")
}

/// The whole app in the mode without a server.
struct LocalModeView: UIViewRepresentable {
    func makeCoordinator() -> LocalBridge { LocalBridge() }

    func makeUIView(context: Context) -> WKWebView {
        let cfg = WKWebViewConfiguration()
        cfg.setURLSchemeHandler(context.coordinator, forURLScheme: "bankapp")
        cfg.userContentController.add(context.coordinator, name: "ba")
        cfg.userContentController.addUserScript(WKUserScript(source: LocalBridge.pageScript(), injectionTime: .atDocumentStart, forMainFrameOnly: true))
        cfg.allowsInlineMediaPlayback = true
        cfg.mediaTypesRequiringUserActionForPlayback = []
        let w = WKWebView(frame: .zero, configuration: cfg)
        w.isOpaque = false
        w.backgroundColor = UIColor(red: 7 / 255, green: 20 / 255, blue: 12 / 255, alpha: 1)
        w.scrollView.contentInsetAdjustmentBehavior = .never
        context.coordinator.web = w
        w.load(URLRequest(url: URL(string: "bankapp://local/app/index.html")!))
        return w
    }

    func updateUIView(_ uiView: WKWebView, context: Context) {}
}

/// The first step of the mode without a server: the Bale bot token (the same one the ESP32 sends with).
struct LocalSetupView: View {
    var onDone: () -> Void
    var onBack: () -> Void
    @State private var token = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        ZStack {
            Theme.darkBackground.ignoresSafeArea()
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Image(systemName: "paperplane.circle.fill").font(.system(size: 54)).foregroundStyle(Theme.lime).padding(.top, 50)
                    Text("بدون سرور").font(.fa(30, .bold)).foregroundStyle(.white)
                    Text("۱) در بله از ‎@botfather یک ربات بساز و توکنش را اینجا بگذار (همان توکنی که در فریمور ESP32 هست).\n۲) در بله به ربات خودت ‎/start بفرست.\n۳) زیر هر پیامک بانک در بله «ثبت در حسابداری» را بزن یا Reply کن؛ اپ آن را می‌خواند، ثبت می‌کند و اکسلش را می‌سازد.")
                        .font(.fa(15)).foregroundStyle(.white.opacity(0.7))
                    TextField("", text: $token, prompt: Text("توکن ربات بله").foregroundColor(.white.opacity(0.3)))
                        .textInputAutocapitalization(.never).autocorrectionDisabled()
                        .font(.fa(16, .medium)).foregroundStyle(.white)
                        .environment(\.layoutDirection, .leftToRight)
                        .padding(.horizontal, 18).frame(height: 56)
                        .background(RoundedRectangle(cornerRadius: 18, style: .continuous).fill(.white.opacity(0.07)))
                    if let error {
                        Label(error, systemImage: "exclamationmark.triangle.fill").font(.fa(14)).foregroundStyle(Color(red: 1, green: 0.55, blue: 0.5))
                    }
                    ArrowPillButton(title: "شروع", busy: busy) { Task { await start() } }
                        .disabled(busy || token.count < 10)
                    Button("برگشت", action: onBack).font(.fa(15)).foregroundStyle(.white.opacity(0.7))
                }
                .padding(24)
            }
        }
    }

    private func start() async {
        let t = token.trimmingCharacters(in: .whitespacesAndNewlines)
        busy = true
        error = nil
        defer { busy = false }
        do {
            let d = try await LocalMode.bale("getMe", json: Data("{}".utf8), token: t)
            let j = try? JSONSerialization.jsonObject(with: d) as? [String: Any]
            guard (j?["ok"] as? Bool) == true else { error = "بله این توکن را قبول نکرد."; return }
            try KeychainStore.set(t, for: LocalMode.tokenKey)
            LocalMode.enabled = true
            onDone()
        } catch {
            self.error = "به بله وصل نشد؛ اینترنت را ببین."
        }
    }
}
