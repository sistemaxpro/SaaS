import Foundation
import UIKit
import UserNotifications
import FirebaseMessaging

@MainActor
final class PushCoordinator: NSObject, ObservableObject, UNUserNotificationCenterDelegate, MessagingDelegate {
    static let shared = PushCoordinator()

    @Published var permissionGranted = false
    @Published var fcmToken = ""
    @Published var lastPushAt = ""
    @Published var lastPushTitle = ""
    @Published var lastPushDetail = ""
    @Published var lastError = ""

    private override init() {
        super.init()
    }

    func refreshAuthorizationStatus() {
        UNUserNotificationCenter.current().getNotificationSettings { settings in
            Task { @MainActor in
                self.permissionGranted = settings.authorizationStatus == .authorized || settings.authorizationStatus == .provisional
            }
        }
    }

    func requestNotifications() async {
        do {
            let granted = try await UNUserNotificationCenter.current().requestAuthorization(options: [.alert, .badge, .sound])
            permissionGranted = granted
            if granted {
                UIApplication.shared.registerForRemoteNotifications()
            }
        } catch {
            lastError = error.localizedDescription
        }
    }

    func didRegisterForRemoteNotifications(deviceToken: Data) {
        Messaging.messaging().apnsToken = deviceToken
    }

    nonisolated func messaging(_ messaging: Messaging, didReceiveRegistrationToken fcmToken: String?) {
        Task { @MainActor in
            self.fcmToken = fcmToken ?? ""
            self.lastPushDetail = self.lastPushDetail
        }
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        willPresent notification: UNNotification,
        withCompletionHandler completionHandler: @escaping (UNNotificationPresentationOptions) -> Void
    ) {
        let content = notification.request.content
        Task { @MainActor in
            self.lastPushAt = ISO8601DateFormatter().string(from: Date())
            self.lastPushTitle = content.title
            self.lastPushDetail = content.body
        }
        completionHandler([.banner, .sound, .badge])
    }

    nonisolated func userNotificationCenter(
        _ center: UNUserNotificationCenter,
        didReceive response: UNNotificationResponse,
        withCompletionHandler completionHandler: @escaping () -> Void
    ) {
        let content = response.notification.request.content
        Task { @MainActor in
            self.lastPushAt = ISO8601DateFormatter().string(from: Date())
            self.lastPushTitle = content.title
            self.lastPushDetail = content.body
        }
        completionHandler()
    }
}
