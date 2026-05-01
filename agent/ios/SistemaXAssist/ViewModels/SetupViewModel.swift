import Foundation

@MainActor
final class SetupViewModel: ObservableObject {
    @Published var isBusy = false
    @Published var statusText = "Pendiente"
    @Published var lastServerStatus = ""
    @Published var lastError = ""

    private let apiClient = APIClient()
    private weak var deviceStore: DeviceStore?
    private weak var pushCoordinator: PushCoordinator?

    func attach(deviceStore: DeviceStore, pushCoordinator: PushCoordinator) {
        self.deviceStore = deviceStore
        self.pushCoordinator = pushCoordinator
    }

    func bootstrapFromStorageIfNeeded() async {
        guard let deviceStore, let pushCoordinator else { return }
        if !deviceStore.agentToken.isEmpty, !pushCoordinator.fcmToken.isEmpty {
            await registerPushIfPossible()
        }
    }

    func registerDevice() async {
        guard let deviceStore else { return }
        isBusy = true
        lastError = ""
        defer {
            isBusy = false
            deviceStore.persist()
        }
        do {
            let result = try await apiClient.registerIOS(
                apiURL: deviceStore.apiURL,
                bootstrapToken: deviceStore.bootstrapToken,
                deviceUUID: deviceStore.deviceUUID,
                deviceName: deviceStore.deviceName
            )
            deviceStore.agentToken = result.agent_token
            deviceStore.lastRegisteredAt = ISO8601DateFormatter().string(from: Date())
            statusText = "iPhone registrado"
            await registerPushIfPossible()
        } catch {
            lastError = error.localizedDescription
            statusText = "Error de registro"
        }
    }

    func registerPushIfPossible() async {
        guard let deviceStore, let pushCoordinator else { return }
        guard !deviceStore.agentToken.isEmpty, !pushCoordinator.fcmToken.isEmpty else { return }
        do {
            let result = try await apiClient.registerPush(
                apiURL: deviceStore.apiURL,
                agentToken: deviceStore.agentToken,
                fcmToken: pushCoordinator.fcmToken
            )
            deviceStore.serverTokenId = result.token_id
            deviceStore.activeTokens = result.active_tokens
            deviceStore.lastRegisteredAt = ISO8601DateFormatter().string(from: Date())
            statusText = "Push registrado"
        } catch {
            lastError = error.localizedDescription
            statusText = "Error de push"
        }
    }

    func refreshServerStatus() async {
        guard let deviceStore, !deviceStore.agentToken.isEmpty else { return }
        do {
            let result = try await apiClient.fetchStatus(apiURL: deviceStore.apiURL, agentToken: deviceStore.agentToken)
            lastServerStatus = "\(result.company_name ?? "Empresa") · \(result.user_name ?? "Usuario")"
        } catch {
            lastError = error.localizedDescription
        }
    }
}
