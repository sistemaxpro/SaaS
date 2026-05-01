import SwiftUI

@main
struct SistemaXAssistApp: App {
    @UIApplicationDelegateAdaptor(AppDelegate.self) private var appDelegate
    @StateObject private var deviceStore = DeviceStore()
    @StateObject private var pushCoordinator = PushCoordinator.shared
    @StateObject private var setupViewModel = SetupViewModel()

    var body: some Scene {
        WindowGroup {
            ContentView()
                .environmentObject(deviceStore)
                .environmentObject(pushCoordinator)
                .environmentObject(setupViewModel)
                .task {
                    setupViewModel.attach(deviceStore: deviceStore, pushCoordinator: pushCoordinator)
                    await setupViewModel.bootstrapFromStorageIfNeeded()
                }
        }
    }
}
