import SwiftUI
import SwiftData

@main struct SpendTraceApp: App {
    var body: some Scene {
        WindowGroup {
            if let container = try? LocalDatabase.container() {
                RootView().modelContainer(container).tint(Color(red: 0.07, green: 0.25, blue: 0.21))
            } else {
                ContentUnavailableView("Local storage is unavailable", systemImage: "lock.trianglebadge.exclamationmark", description: Text("Unlock your iPhone and reopen SpendTrace. Your existing data has not been reset."))
            }
        }
    }
}
