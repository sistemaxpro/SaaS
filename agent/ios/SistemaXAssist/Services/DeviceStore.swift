import Foundation
import UIKit

@MainActor
final class DeviceStore: ObservableObject {
    @Published var apiURL: String
    @Published var bootstrapToken: String
    @Published var deviceName: String
    @Published var agentToken: String
    @Published var deviceUUID: String
    @Published var lastRegisteredAt: String
    @Published var serverTokenId: Int
    @Published var activeTokens: Int

    private let defaults = UserDefaults.standard

    init() {
        self.apiURL = defaults.string(forKey: "ios.api_url") ?? "https://cliente.sistemax.pro/public/api/mobile_tracking.php"
        self.bootstrapToken = defaults.string(forKey: "ios.bootstrap_token") ?? ""
        self.deviceName = defaults.string(forKey: "ios.device_name") ?? UIDevice.current.name
        self.agentToken = defaults.string(forKey: "ios.agent_token") ?? ""
        self.deviceUUID = defaults.string(forKey: "ios.device_uuid") ?? UUID().uuidString.lowercased()
        self.lastRegisteredAt = defaults.string(forKey: "ios.last_registered_at") ?? ""
        self.serverTokenId = defaults.integer(forKey: "ios.server_token_id")
        self.activeTokens = defaults.integer(forKey: "ios.active_tokens")
        defaults.set(deviceUUID, forKey: "ios.device_uuid")
    }

    func persist() {
        defaults.set(apiURL, forKey: "ios.api_url")
        defaults.set(bootstrapToken, forKey: "ios.bootstrap_token")
        defaults.set(deviceName, forKey: "ios.device_name")
        defaults.set(agentToken, forKey: "ios.agent_token")
        defaults.set(deviceUUID, forKey: "ios.device_uuid")
        defaults.set(lastRegisteredAt, forKey: "ios.last_registered_at")
        defaults.set(serverTokenId, forKey: "ios.server_token_id")
        defaults.set(activeTokens, forKey: "ios.active_tokens")
    }
}
