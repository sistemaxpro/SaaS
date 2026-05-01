import SwiftUI

struct SetupView: View {
    @EnvironmentObject private var deviceStore: DeviceStore
    @EnvironmentObject private var pushCoordinator: PushCoordinator
    @EnvironmentObject private var viewModel: SetupViewModel

    var body: some View {
        NavigationStack {
            Form {
                Section("Conexion") {
                    TextField("API URL", text: $deviceStore.apiURL)
                        .textInputAutocapitalization(.never)
                        .keyboardType(.URL)
                        .autocorrectionDisabled()

                    TextField("Bootstrap token", text: $deviceStore.bootstrapToken)
                        .textInputAutocapitalization(.never)
                        .autocorrectionDisabled()

                    TextField("Nombre del equipo", text: $deviceStore.deviceName)
                }

                Section("Estado") {
                    statusRow("Estado", viewModel.statusText)
                    statusRow("Permiso notificaciones", pushCoordinator.permissionGranted ? "OK" : "Pendiente")
                    statusRow("Token FCM", pushCoordinator.fcmToken.isEmpty ? "Pendiente" : "Recibido")
                    statusRow("Ultimo registro", deviceStore.lastRegisteredAt.isEmpty ? "-" : deviceStore.lastRegisteredAt)
                }

                Section {
                    Button("Conceder notificaciones") {
                        Task { await pushCoordinator.requestNotifications() }
                    }

                    Button("Registrar iPhone") {
                        Task { await viewModel.registerDevice() }
                    }
                    .disabled(viewModel.isBusy || deviceStore.bootstrapToken.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)

                    Button("Registrar push") {
                        Task { await viewModel.registerPushIfPossible() }
                    }
                    .disabled(deviceStore.agentToken.isEmpty || pushCoordinator.fcmToken.isEmpty)

                    Button("Consultar estado servidor") {
                        Task { await viewModel.refreshServerStatus() }
                    }
                    .disabled(deviceStore.agentToken.isEmpty)
                }

                if !viewModel.lastError.isEmpty {
                    Section("Error") {
                        Text(viewModel.lastError)
                            .foregroundStyle(.red)
                    }
                }
            }
            .navigationTitle("SistemaX Assist iPhone")
        }
    }

    @ViewBuilder
    private func statusRow(_ title: String, _ value: String) -> some View {
        HStack {
            Text(title)
            Spacer()
            Text(value)
                .foregroundStyle(.secondary)
                .multilineTextAlignment(.trailing)
        }
    }
}
