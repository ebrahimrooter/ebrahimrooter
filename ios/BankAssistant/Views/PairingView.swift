import SwiftUI
import UIKit

/// Connect to the accounting server with the one-time code from the phone web
/// app (Settings → «اپ دستیار آیفون» → «اتصال اپ آیفون»). A pairing link fills it in.
struct PairingView: View {
    var link: DeepLink?
    var onPaired: () -> Void

    @State private var server = ""
    @State private var code = ""
    @State private var password = ""
    @State private var withCode = false
    @State private var busy = false
    @State private var error: String?
    @FocusState private var focus: Field?
    private enum Field { case server, code, password }

    var body: some View {
        ZStack {
            Theme.darkBackground.ignoresSafeArea()
                .onTapGesture { focus = nil }
            ScrollView {
                VStack(alignment: .leading, spacing: 22) {
                    Image(systemName: "link.circle.fill")
                        .font(.system(size: 54)).foregroundStyle(Theme.lime)
                        .padding(.top, 50)
                    Text("اتصال به حسابداری").font(.fa(30, .bold)).foregroundStyle(.white)
                    Text(withCode
                         ? "در اپ وب حسابداری: تنظیمات ← «اپ دستیار آیفون» ← «اتصال اپ آیفون». کد ۵ دقیقه اعتبار دارد و فقط یک بار کار می‌کند."
                         : "آدرس سرور و همان رمز اپ را بزن. رمز فقط یک بار برای وصل شدن فرستاده می‌شود و روی گوشی ذخیره نمی‌شود.")
                        .font(.fa(15)).foregroundStyle(.white.opacity(0.65))

                    Picker("", selection: $withCode) {
                        Text("رمز اپ").tag(false)
                        Text("کد یک‌بارمصرف").tag(true)
                    }
                    .pickerStyle(.segmented)

                    field("آدرس سرور", "https://دامنه/bank/", text: $server, f: .server, keyboard: .URL, caps: .never)
                    if withCode {
                        field("کد یک‌بارمصرف", "ABCD2345", text: $code, f: .code, keyboard: .asciiCapable, caps: .characters)
                    } else {
                        VStack(alignment: .leading, spacing: 8) {
                            Text("رمز اپ").font(.fa(13, .semibold)).foregroundStyle(.white.opacity(0.7))
                            SecureField("", text: $password, prompt: Text("رمز اپ").foregroundColor(.white.opacity(0.3)))
                                .focused($focus, equals: .password)
                                .textContentType(.password)
                                .font(.fa(17, .medium)).foregroundStyle(.white)
                                .environment(\.layoutDirection, .leftToRight)
                                .padding(.horizontal, 18).frame(height: 56)
                                .background(RoundedRectangle(cornerRadius: 18, style: .continuous).fill(.white.opacity(0.07)))
                                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous)
                                    .strokeBorder(focus == .password ? Theme.lime : .white.opacity(0.14), lineWidth: 1))
                        }
                    }

                    if let error {
                        Label(error, systemImage: "exclamationmark.triangle.fill")
                            .font(.fa(14)).foregroundStyle(Color(red: 1, green: 0.55, blue: 0.5))
                    }

                    ArrowPillButton(title: withCode ? "اتصال" : "ورود", busy: busy) { Task { await pair() } }
                        .disabled(busy || !ready)
                        .opacity(ready ? 1 : 0.55)
                        .padding(.top, 8)
                }
                .padding(24)
            }
            .scrollDismissesKeyboard(.interactively)
        }
        .onAppear(perform: fill)
        .onChange(of: link) { _, _ in fill() }
    }

    private func field(_ title: String, _ placeholder: String, text: Binding<String>, f: Field,
                       keyboard: UIKeyboardType, caps: TextInputAutocapitalization) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(title).font(.fa(13, .semibold)).foregroundStyle(.white.opacity(0.7))
            TextField("", text: text, prompt: Text(placeholder).foregroundColor(.white.opacity(0.3)))
                .focused($focus, equals: f)
                .keyboardType(keyboard)
                .textInputAutocapitalization(caps).autocorrectionDisabled()
                .font(.fa(17, .medium)).foregroundStyle(.white)
                .environment(\.layoutDirection, .leftToRight)
                .padding(.horizontal, 18).frame(height: 56)
                .background(RoundedRectangle(cornerRadius: 18, style: .continuous).fill(.white.opacity(0.07)))
                .overlay(RoundedRectangle(cornerRadius: 18, style: .continuous)
                    .strokeBorder(focus == f ? Theme.lime : .white.opacity(0.14), lineWidth: 1))
        }
    }

    private var ready: Bool {
        apiURL != nil && (withCode ? code.count >= 8 : !password.isEmpty)
    }

    /// «example.com/bank», «https://example.com/bank/app/» … → https://example.com/bank/api.php
    private var apiURL: URL? {
        var s = server.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !s.isEmpty else { return nil }
        if !s.hasPrefix("http://") && !s.hasPrefix("https://") { s = "https://" + s }
        if let q = s.firstIndex(where: { $0 == "?" || $0 == "#" }) { s = String(s[..<q]) }
        while s.hasSuffix("/") { s.removeLast() }
        for tail in ["/api.php", "/app", "/acc"] where s.hasSuffix(tail) { s.removeLast(tail.count) }
        return URL(string: s + "/api.php")
    }

    private func fill() {
        if case let .pair(c, s)? = link {
            withCode = true
            code = c
            server = s.absoluteString
            Task { await pair() }
        }
    }

    private func pair() async {
        guard let url = apiURL else { return }
        busy = true
        error = nil
        defer { busy = false }
        do {
            if withCode {
                try await APIClient.shared.pair(server: url, code: code.trimmingCharacters(in: .whitespaces).uppercased(), deviceName: UIDevice.current.name)
            } else {
                try await APIClient.shared.login(server: url, password: password, deviceName: UIDevice.current.name)
                password = ""
            }
            onPaired()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
