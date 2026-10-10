import UIKit
import UserNotifications

/// Remote notifications: the APNs device token goes to the server; answers typed
/// in a notification are posted to the books without opening the app.
final class AppDelegate: NSObject, UIApplicationDelegate, UNUserNotificationCenterDelegate {
    func application(_ application: UIApplication, didFinishLaunchingWithOptions launchOptions: [UIApplication.LaunchOptionsKey: Any]? = nil) -> Bool {
        UNUserNotificationCenter.current().delegate = self
        PushManager.registerCategories()
        return true
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
