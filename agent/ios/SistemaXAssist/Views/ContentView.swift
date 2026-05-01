import SwiftUI

struct ContentView: View {
    var body: some View {
        TabView {
            SetupView()
                .tabItem {
                    Label("Configurar", systemImage: "iphone.gen3")
                }

            DiagnosticsView()
                .tabItem {
                    Label("Diagnostico", systemImage: "waveform.path.ecg")
                }
        }
    }
}
