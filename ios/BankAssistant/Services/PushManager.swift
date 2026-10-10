import Observation
import UIKit
import UserNotifications

/// The app's own notifications (APNs through server/apns.php): a bank transaction
/// arrives → «بابت چی بود؟» on the Lock Screen; long-press to type the answer
/// right there, or tap to open it in the app (with the microphone).
@MainActor
@Observable
final class PushManager {
    static let shared = PushManager()

    /// A transaction the user opened from a notification (HomeView shows it).
    var openTx: Int?
    /// A one-time code arrived for this card: open its «رمز پویا».
    var openCardOTP: Int?
    private(set) var status = ""

    /// Debug builds talk to Apple's sandbox, TestFlight / App Store builds to production.
    nonisolated static var environment: String {
        #if DEBUG
        return "sandbox"
        #else
        return "production"
        #endif
    }

    func enable() async {
        guard APIClient.shared.isPaired else { return }
        let granted = (try? await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .sound, .badge])) ?? false
        guard granted else { status = "اجازه‌ی نوتیف داده نشده"; return }
        UIApplication.shared.registerForRemoteNotifications()
    }

    func upload(token: String) async {
        do {
            try await APIClient.shared.registerPush(token: token, environment: Self.environment)
            status = "فعال"
        } catch {
            status = error.localizedDescription
        }
    }

    /// «بابت چی بود؟» with a text field, and «جواب با صدا» that opens the app.
    nonisolated static func registerCategories() {
        let answer = UNTextInputNotificationAction(identifier: "ANSWER", title: "بابت چی بود؟", options: [],
                                                   textInputButtonTitle: "ثبت", textInputPlaceholder: "مثلاً فروش نقدی به علی رضایی")
        let voice = UNNotificationAction(identifier: "VOICE", title: "جواب با صدا", options: [.foreground])
        let tx = UNNotificationCategory(identifier: "BANK_TX", actions: [answer, voice], intentIdentifiers: [], options: [])
        UNUserNotificationCenter.current().setNotificationCategories([tx])
    }

    /// A short local notice after answering from the notification.
    nonisolated static func notice(_ title: String, _ body: String) {
        let c = UNMutableNotificationContent()
        c.title = title
        c.body = body
        UNUserNotificationCenter.current().add(UNNotificationRequest(identifier: UUID().uuidString, content: c, trigger: nil))
    }
}
