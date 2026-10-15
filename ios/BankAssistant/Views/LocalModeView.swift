import AVFoundation
import SwiftUI
import UIKit
import WebKit

/// The app without a server: the phone web app with its own books (Resources/www,
/// made by tools/local-app/build.sh); the bank SMS come straight from the ESP32
/// (app/local/link.js, over a WebSocket from the page). Same as the Android app:
///   bankapp://local/app/index.html     the bundled web app
/// The books are a file in Application Support (local-books.json); speaking and listening
/// use the app's own Persian voice and ear (LocalVoice / LocalEar).
enum LocalMode {
    static let flagKey = "localMode"
    static var enabled: Bool {
        get { UserDefaults.standard.bool(forKey: flagKey) }
        set { UserDefaults.standard.set(newValue, forKey: flagKey) }
    }
    /// Test builds only: a pretend ESP32 (-linkCode CODE -mqttUrl fake|ws://…).
    static var testLinkCode: String? {
        #if DEBUG
        return UserDefaults.standard.string(forKey: "linkCode")
        #else
        return nil
        #endif
    }
    static var testMqttUrl: String? {
        #if DEBUG
        return UserDefaults.standard.string(forKey: "mqttUrl")
        #else
        return nil
        #endif
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

}

/// Files and the voice for the page (WKURLSchemeHandler + window.webkit.messageHandlers.ba).
@MainActor
final class LocalBridge: NSObject, WKURLSchemeHandler, WKScriptMessageHandler {
    weak var web: WKWebView?
    private var stopped = Set<ObjectIdentifier>()
    private let root = Bundle.main.url(forResource: "www", withExtension: nil)
    private let player = SpeechPlayer()
    private lazy var dictation = Dictation()

    // MARK: bankapp:// — the bundled files

    func webView(_ webView: WKWebView, start task: WKURLSchemeTask) {
        guard let url = task.request.url else { return }
        let path = url.path
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
            linkCode: function () { return \(LocalMode.testLinkCode.map { q($0) } ?? "null"); },
            mqttUrl: function () { return \(LocalMode.testMqttUrl.map { q($0) } ?? "null"); },
            changeMode: function () { post({ op: 'changeMode' }); },
            log: function (t) { post({ op: 'log', text: t }); }
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

/// The first step of the mode without a server: what it is; the pairing code is in the app's settings.
struct LocalSetupView: View {
    var onDone: () -> Void
    var onBack: () -> Void

    var body: some View {
        ZStack {
            Theme.darkBackground.ignoresSafeArea()
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    Image(systemName: "antenna.radiowaves.left.and.right.circle.fill").font(.system(size: 54)).foregroundStyle(Theme.lime).padding(.top, 50)
                    Text("بدون سرور").font(.fa(30, .bold)).foregroundStyle(.white)
                    Text("ESP32 پیامک‌های بانک را مستقیم به همین اپ می‌فرستد (رمزشده؛ بدون سرور).\n۱) «شروع» را بزن.\n۲) در تنظیمات اپ، «اتصال به ESP32»، کد اتصال را ببین و در فریمور ESP32 (PAIR_CODE) بگذار.\n۳) هر پیامک بانک روی کارت همان بانک ثبت می‌شود و اپ می‌پرسد «بابت چی بود؟»؛ اکسلش را هم همین‌جا می‌سازی.")
                        .font(.fa(15)).foregroundStyle(.white.opacity(0.7))
                    ArrowPillButton(title: "شروع", busy: false) {
                        LocalMode.enabled = true
                        onDone()
                    }
                    Button("برگشت", action: onBack).font(.fa(15)).foregroundStyle(.white.opacity(0.7))
                }
                .padding(24)
            }
        }
    }
}
