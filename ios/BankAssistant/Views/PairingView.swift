import SwiftUI
import UIKit

/// First run: connect to the accounting server with the one-time code from the
/// phone web app (Settings → «اپ دستیار آیفون»). A pairing link fills it in.
struct PairingView: View {
    var link: DeepLink?
    var onPaired: () -> Void

    @State private var server = ""
    @State private var code = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    TextField("https://دامنه/bank/api.php", text: $server)
                        .keyboardType(.URL).textInputAutocapitalization(.never).autocorrectionDisabled()
                        .environment(\.layoutDirection, .leftToRight)
                    TextField("کد ۸ حرفی", text: $code)
                        .textInputAutocapitalization(.characters).autocorrectionDisabled()
                        .environment(\.layoutDirection, .leftToRight)
                } header: {
                    Text("اتصال به حسابداری")
                } footer: {
                    Text("در اپ حسابداری روی گوشی: تنظیمات ← «اپ دستیار آیفون» ← «اتصال». کد ۵ دقیقه اعتبار دارد و فقط یک بار کار می‌کند.")
                }
                if let error {
                    Section { Text(error).foregroundStyle(.red) }
                }
                Section {
                    Button {
                        Task { await pair() }
                    } label: {
                        HStack { Spacer(); if busy { ProgressView() } else { Text("اتصال") }; Spacer() }
                    }
                    .disabled(busy || code.count < 8 || URL(string: server) == nil)
                }
            }
            .navigationTitle("دستیار حسابداری")
        }
        .onAppear(perform: fill)
        .onChange(of: link) { _, _ in fill() }
    }

    private func fill() {
        if case let .pair(c, s)? = link {
            code = c
            server = s.absoluteString
            Task { await pair() }
        }
    }

    private func pair() async {
        guard let url = URL(string: server.trimmingCharacters(in: .whitespaces)) else { return }
        busy = true
        error = nil
        defer { busy = false }
        do {
            try await APIClient.shared.pair(server: url, code: code.trimmingCharacters(in: .whitespaces), deviceName: UIDevice.current.name)
            onPaired()
        } catch {
            self.error = error.localizedDescription
        }
    }
}
