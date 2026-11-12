import AppIntents
import SwiftData

struct RecordPurchaseIntent: AppIntent {
    static var title: LocalizedStringResource = "Record purchase"
    static var description = IntentDescription("Save a Wallet purchase on this iPhone. No webhook or server connection is used.")
    static var openAppWhenRun: Bool = false

    @Parameter(title: "Merchant") var merchant: String
    @Parameter(title: "Exact amount", description: "Decimal text, for example 12.34. No currency symbols or grouping.") var amount: String
    @Parameter(title: "Wallet card name") var cardName: String
    @Parameter(title: "Currency code", default: "") var currency: String
    @Parameter(title: "Event ID", description: "Optional UUID. Reuse it only when retrying the same purchase.", default: "") var eventID: String

    @MainActor func perform() async throws -> some IntentResult & ProvidesDialog {
        let input = try PurchaseInput(eventID: eventID.isEmpty ? UUID().uuidString : eventID, merchant: merchant, amount: amount, currency: currency, cardName: cardName)
        let purchase = try LocalDatabase.record(input, in: LocalDatabase.container().mainContext)
        if purchase.accountID == nil { return .result(dialog: "Saved on this iPhone. Open SpendTrace to choose the account for this card.") }
        return .result(dialog: "Purchase saved on this iPhone.")
    }
}

struct SpendTraceShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(intent: RecordPurchaseIntent(), phrases: ["Record a purchase in \(.applicationName)"], shortTitle: "Record purchase", systemImageName: "creditcard")
    }
}
