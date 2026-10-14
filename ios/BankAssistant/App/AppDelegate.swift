import UIKit
import UserNotifications

/// Remote notifications: the APNs device token goes to the server; answers typed
/// in a notification are posted to the books without opening the app.
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        PushManager.registerCategories()
        LocalVoice.shared.warmUp()
        LocalEar.shared.warmUp()
        if ProcessInfo.processInfo.arguments.contains("-voicetest") { Task { await Self.voiceTest() } }
        return true
    }

    /// CI self-check (simulator): the number rules match the server's, and the
    /// app's own voice speaks. Result in Documents/voice-test.txt.
    static func voiceTest() async {
        var same = 0, total = 0
        if let u = Bundle.main.url(forResource: "speech_cases", withExtension: "json", subdirectory: "FaVoice"),
           let d = try? Data(contentsOf: u),
           let cases = try? JSONSerialization.jsonObject(with: d) as? [[String: String]] {
            for c in cases {
                total += 1
                let got = SpeechText.forSpeech(c["in"] ?? "")
                if got == c["out"] { same += 1 } else { print("VOICE_TEST diff: \(got) | want: \(c["out"] ?? "")") }
            }
        }
        let t = Date()
        let wav = await LocalVoice.shared.wav("موجودی کارت ملت ۴٬۲۵۰٬۰۰۰ تومان است.")
        let ms = Int(Date().timeIntervalSince(t) * 1000)
        // and the app's own ear on the app's own voice (16 kHz like the microphone recording)
        var heard = "-"
        var earMs = 0
        if let wav {
            let url = FileManager.default.temporaryDirectory.appendingPathComponent("voice-test.wav")
            try? wav.write(to: url)
            let t2 = Date()
            heard = await LocalEar.shared.transcribe(fileURL: url) ?? "nil"
            earMs = Int(Date().timeIntervalSince(t2) * 1000)
        }
        let line = "speech_text=\(same)/\(total) bundled=\(LocalVoice.shared.bundled) tts=\((wav?.count ?? 0) > 44 + 22050 ? "ok" : "fail") " +
            "bytes=\(wav?.count ?? 0) ms=\(ms) phone_listen=\(PhoneListen.recognizer != nil) " +
            "ear=\(LocalEar.shared.bundled) stt=\(heard.contains("کارت") && heard.contains("ملت") ? "ok" : "fail") stt_ms=\(earMs) heard=\(heard)"
        print("VOICE_TEST " + line)
        let docs = FileManager.default.urls(for: .documentDirectory, in: .userDomainMask)[0]
        try? line.write(to: docs.appendingPathComponent("voice-test.txt"), atomically: true, encoding: .utf8)
        if let wav { try? wav.write(to: docs.appendingPathComponent("voice-test.wav")) }
    }

    func application(_ application: UIApplication, didRegisterForRemoteNotificationsWithDeviceToken deviceToken: Data) {
        let hex = deviceToken.map { String(format: "%02x", $0) }.joined()
        Task { await PushManager.shared.upload(token: hex) }
    }

    func application(_ application: UIApplication, didFailToRegisterForRemoteNotificationsWithError error: Error) {
        print("push registration failed: \(error.localizedDescription)")
    }

    // shown also while the app is open; the books are refreshed
    func userNotificationCenter(_ center: UNUserNotificationCenter, willPresent notification: UNNotification) async -> UNNotificationPresentationOptions {
        await FinanceStore.shared.refresh()
        return [.banner, .sound, .list]
    }

    func userNotificationCenter(_ center: UNUserNotificationCenter, didReceive response: UNNotificationResponse) async {
        let info = response.notification.request.content.userInfo
        let txID = (info["tx_id"] as? NSNumber)?.intValue ?? (info["tx_id"] as? Int)
        switch response.actionIdentifier {
        case "ANSWER":
            guard let id = txID, let typed = (response as? UNTextInputNotificationResponse)?.userText,
                  !typed.trimmingCharacters(in: .whitespaces).isEmpty else { return }
            do {
                try await APIClient.shared.confirm(id: id, description: typed.trimmingCharacters(in: .whitespaces), party: "")
                PushManager.notice("✅ ثبت شد", typed)
            } catch {
                PushManager.notice("ثبت نشد", error.localizedDescription)
            }
        default:   // tapped, or «جواب با صدا»
            let otpCard = (info["card_otp"] as? NSNumber)?.intValue ?? (info["card_otp"] as? Int)
            await MainActor.run {
                if let otpCard { PushManager.shared.openCardOTP = otpCard } else { PushManager.shared.openTx = txID }
            }
        }
    }
}
