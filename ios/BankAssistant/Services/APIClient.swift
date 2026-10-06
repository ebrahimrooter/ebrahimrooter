import Foundation

/// Talks to server/api.php (see ios/README.md «API contract»).
/// The server address is not secret (UserDefaults); the device token is (Keychain).
struct AskReply: Decodable {
    let ok: Bool
    let heard: String?
    let reply: String
    /// answered | confirm | unknown | error
    let state: String
}

struct RedeemReply: Decodable {
    let ok: Bool
    let token: String
    let device_id: Int
    let name: String
}

private struct TextReply: Decodable { let ok: Bool; let text: String }
private struct ErrorReply: Decodable { let ok: Bool; let error: String? }

enum APIError: LocalizedError {
    case notPaired, unauthorized, server(String), offline(String)

    var errorDescription: String? {
        switch self {
        case .notPaired: return "اپ هنوز به حسابداری وصل نشده."
        case .unauthorized: return "دسترسی این گوشی لغو شده؛ دوباره اتصال بده."
        case .server(let m): return m
        case .offline(let m): return "سرور در دسترس نیست: \(m)"
        }
    }
}

final class APIClient: @unchecked Sendable {
    static let shared = APIClient()
    private let tokenKey = "deviceToken"
    private let serverKey = "serverURL"
    private let session: URLSession = {
        let c = URLSessionConfiguration.ephemeral          // nothing cached on disk
        c.timeoutIntervalForRequest = 45
        c.waitsForConnectivity = false
        return URLSession(configuration: c)
    }()

    var server: URL? {
        get { UserDefaults.standard.string(forKey: serverKey).flatMap(URL.init(string:)) }
        set { UserDefaults.standard.set(newValue?.absoluteString, forKey: serverKey) }
    }
    var isPaired: Bool { server != nil && KeychainStore.get(tokenKey) != nil }

    func unpair() {
        KeychainStore.remove(tokenKey)
        server = nil
    }

    /// One-time code from the phone web app → device token in the Keychain.
    func pair(server: URL, code: String, deviceName: String) async throws {
        guard server.scheme == "https" || server.host == "localhost" || server.host?.hasPrefix("192.168.") == true else {
            throw APIError.server("آدرس سرور باید https باشد.")
        }
        let r: RedeemReply = try await json(server, route: "assistant_redeem", body: ["code": code, "device_name": deviceName], token: nil)
        try KeychainStore.set(r.token, for: tokenKey)
        self.server = server
    }

    func ask(_ text: String) async throws -> AskReply {
        try await json(nil, route: "assistant_ask", body: ["text": text], token: try token())
    }

    func transcribe(fileURL: URL) async throws -> String {
        guard let base = server else { throw APIError.notPaired }
        let boundary = "B-\(UUID().uuidString)"
        var req = request(base, route: "assistant_transcribe", token: try token())
        req.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        var body = Data()
        body.append("--\(boundary)\r\nContent-Disposition: form-data; name=\"audio\"; filename=\"speech.wav\"\r\nContent-Type: audio/wav\r\n\r\n".data(using: .utf8)!)
        body.append(try Data(contentsOf: fileURL))
        body.append("\r\n--\(boundary)--\r\n".data(using: .utf8)!)
        let data = try await send(req, body: body)
        return try JSONDecoder().decode(TextReply.self, from: data).text
    }

    /// Persian speech made by the server's local voice (mp3).
    func speech(for text: String) async throws -> Data {
        guard let base = server else { throw APIError.notPaired }
        var req = request(base, route: "assistant_speak", token: try token())
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        return try await send(req, body: try JSONSerialization.data(withJSONObject: ["text": text]))
    }

    // MARK: - plumbing

    private func token() throws -> String {
        guard let t = KeychainStore.get(tokenKey) else { throw APIError.notPaired }
        return t
    }

    private func request(_ base: URL, route: String, token: String?) -> URLRequest {
        var c = URLComponents(url: base, resolvingAgainstBaseURL: false)!
        c.queryItems = [URLQueryItem(name: "r", value: route)]
        var req = URLRequest(url: c.url!)
        req.httpMethod = "POST"
        if let token {
            req.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
            req.setValue(token, forHTTPHeaderField: "X-Auth-Token")      // Apache may drop Authorization
        }
        return req
    }

    private func json<T: Decodable>(_ base: URL?, route: String, body: [String: String], token: String?) async throws -> T {
        guard let b = base ?? server else { throw APIError.notPaired }
        var req = request(b, route: route, token: token)
        req.setValue("application/json", forHTTPHeaderField: "Content-Type")
        let data = try await send(req, body: try JSONSerialization.data(withJSONObject: body))
        return try JSONDecoder().decode(T.self, from: data)
    }

    private func send(_ req: URLRequest, body: Data) async throws -> Data {
        let data: Data
        let resp: URLResponse
        do {
            (data, resp) = try await session.upload(for: req, from: body)
        } catch {
            throw APIError.offline(error.localizedDescription)
        }
        let code = (resp as? HTTPURLResponse)?.statusCode ?? 0
        if code == 401 { throw APIError.unauthorized }
        guard (200..<300).contains(code) else {
            let msg = (try? JSONDecoder().decode(ErrorReply.self, from: data))?.error ?? "خطای سرور (\(code))"
            throw APIError.server(msg)
        }
        return data
    }
}
