import SwiftUI

struct DiagnosticsView: View {
    @EnvironmentObject private var deviceStore: DeviceStore
    @EnvironmentObject private var pushCoordinator: PushCoordinator
    @EnvironmentObject private var viewModel: SetupViewModel

    var body: some View {
        NavigationStack {
            List {
                Section("Push") {
                    row("Permiso", pushCoordinator.permissionGranted ? "Concedido" : "Pendiente")
                    row("Token FCM", pushCoordinator.fcmToken.isEmpty ? "No disponible" : pushCoordinator.fcmToken)
                    row("Ultimo push", pushCoordinator.lastPushAt.isEmpty ? "-" : pushCoordinator.lastPushAt)
                    row("Titulo push", pushCoordinator.lastPushTitle.isEmpty ? "-" : pushCoordinator.lastPushTitle)
                    row("Detalle", pushCoordinator.lastPushDetail.isEmpty ? "-" : pushCoordinator.lastPushDetail)
                }

                Section("Servidor") {
                    row("Agent token", deviceStore.agentToken.isEmpty ? "No registrado" : "Registrado")
                    row("Token servidor ID", deviceStore.serverTokenId > 0 ? "\(deviceStore.serverTokenId)" : "-")
                    row("Tokens activos", deviceStore.activeTokens > 0 ? "\(deviceStore.activeTokens)" : "-")
                    row("Estado servidor", viewModel.lastServerStatus.isEmpty ? "-" : viewModel.lastServerStatus)
                }

                if !pushCoordinator.lastError.isEmpty {
                    Section("Ultimo error") {
                        Text(pushCoordinator.lastError)
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle("Diagnostico")
        }
    }

    @ViewBuilder
    private func row(_ title: String, _ value: String) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title)
                .font(.caption)
                .foregroundStyle(.secondary)
            Text(value)
                .font(.body)
                .textSelection(.enabled)
        }
        .padding(.vertical, 2)
    }
}
