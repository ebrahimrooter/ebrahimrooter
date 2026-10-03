import Foundation

/// Links that open this app:
///   bankassistant://listen                                  start listening (from the phone web app)
///   bankassistant://pair?code=ABCD2345&server=https://…/api.php
///   https://YOUR-DOMAIN/bank/assistant/?a=listen             Universal Link, same as above
///   https://YOUR-DOMAIN/bank/assistant/?a=pair&code=…&server=…
enum DeepLink: Equatable {
    case listen
    case pair(code: String, server: URL)

    init?(_ url: URL) {
        guard let c = URLComponents(url: url, resolvingAgainstBaseURL: false) else { return nil }
        let q = Dictionary((c.queryItems ?? []).map { ($0.name, $0.value ?? "") }, uniquingKeysWith: { first, _ in first })
        let action: String
        if url.scheme == "bankassistant" {
            action = c.host ?? ""
        } else if url.scheme == "https", url.path.hasSuffix("/assistant/") || url.path.hasSuffix("/assistant") {
            action = q["a"] ?? ""
        } else {
            return nil
        }
        switch action {
        case "listen":
            self = .listen
        case "pair":
            guard let code = q["code"], !code.isEmpty, let s = q["server"], let server = URL(string: s) else { return nil }
            self = .pair(code: code, server: server)
        default:
            return nil
        }
    }
}

/// The other way: the app opens the phone web app (Safari / home-screen app) for the full books.
enum WebApp {
    static func url(path: String = "app/") -> URL? {
        guard let api = APIClient.shared.server else { return nil }
        return URL(string: path, relativeTo: api.deletingLastPathComponent())?.absoluteURL
    }
}
