import Foundation
import SwiftData

@Model final class LocalAccount {
    @Attribute(.unique) var id: UUID
    var name: String
    var cardLastFour: String?
    init(name: String, cardLastFour: String? = nil) { self.id = UUID(); self.name = name; self.cardLastFour = cardLastFour }
}

@Model final class CardLink {
    @Attribute(.unique) var cardKey: String
    var cardName: String
    var accountID: UUID
    init(cardName: String, accountID: UUID) {
        self.cardName = cardName
        self.cardKey = PurchaseInput.normalize(cardName)
        self.accountID = accountID
    }
}

@Model final class LocalPurchase {
    @Attribute(.unique) var eventID: String
    var merchant: String
    var amountText: String
    var currency: String
    var cardName: String
    var observedAt: Date
    var accountID: UUID?
    var source: String
    init(input: PurchaseInput, accountID: UUID?) {
        self.eventID = input.eventID; self.merchant = input.merchant
        self.amountText = input.amount; self.currency = input.currency
        self.cardName = input.cardName; self.observedAt = input.date
        self.accountID = accountID; self.source = input.source
    }
}

struct PurchaseInput {
    let eventID: String
    let merchant: String
    let amount: String
    let currency: String
    let cardName: String
    let date: Date
    let source: String

    init(eventID: String = UUID().uuidString, merchant: String, amount: String, currency: String, cardName: String, date: Date = .now, source: String = "wallet") throws {
        guard let uuid = UUID(uuidString: eventID) else { throw LocalError.invalid("The event ID must be a UUID.") }
        let merchant = merchant.trimmingCharacters(in: .whitespacesAndNewlines)
        let card = cardName.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !merchant.isEmpty, merchant.count <= 200 else { throw LocalError.invalid("Enter a merchant name.") }
        guard !card.isEmpty, card.count <= 100 else { throw LocalError.invalid("Provide the Wallet card name.") }
        guard amount.range(of: #"\A-?[0-9]{1,12}(\.[0-9]{1,6})?\z"#, options: .regularExpression) != nil else {
            throw LocalError.invalid("Use an exact amount such as 12.34, without currency symbols or separators.")
        }
        let currency = currency.trimmingCharacters(in: .whitespacesAndNewlines).uppercased()
        guard currency.isEmpty || Locale.Currency.isoCurrencies.contains(where: { $0.identifier == currency }) else { throw LocalError.invalid("Use a valid currency code, or leave it blank if unknown.") }
        self.eventID = uuid.uuidString; self.merchant = merchant; self.amount = amount
        self.currency = currency; self.cardName = card; self.date = date; self.source = source
    }

    static func normalize(_ text: String) -> String {
        text.split(whereSeparator: { $0.isWhitespace }).joined(separator: " ").lowercased()
    }
}

enum LocalError: LocalizedError {
    case invalid(String)
    var errorDescription: String? { if case .invalid(let message) = self { return message }; return nil }
}

@MainActor enum LocalDatabase {
    static let schema = Schema([LocalAccount.self, CardLink.self, LocalPurchase.self, LocalReceipt.self])
    private static let stored: Result<ModelContainer, Error> = Result {
        let root = URL.applicationSupportDirectory.appendingPathComponent("SpendTraceLocal", isDirectory: true)
        try FileManager.default.createDirectory(at: root, withIntermediateDirectories: true, attributes: [.protectionKey: FileProtectionType.completeUntilFirstUserAuthentication])
        var directory = root
        var values = URLResourceValues(); values.isExcludedFromBackup = true
        try directory.setResourceValues(values)
        let config = ModelConfiguration(schema: schema, url: root.appendingPathComponent("ledger.store"), cloudKitDatabase: .none)
        return try ModelContainer(for: schema, configurations: [config])
    }
    static func container() throws -> ModelContainer { try stored.get() }

    static func record(_ input: PurchaseInput, in context: ModelContext) throws -> LocalPurchase {
        let eventID = input.eventID
        if let existing = try context.fetch(FetchDescriptor<LocalPurchase>(predicate: #Predicate { $0.eventID == eventID })).first {
            guard existing.merchant == input.merchant, existing.amountText == input.amount, existing.currency == input.currency, existing.cardName == input.cardName, existing.source == input.source else {
                throw LocalError.invalid("This event ID was already used for a different purchase.")
            }
            return existing
        }
        let key = PurchaseInput.normalize(input.cardName)
        let link = try context.fetch(FetchDescriptor<CardLink>(predicate: #Predicate { $0.cardKey == key })).first
        let accounts = try context.fetch(FetchDescriptor<LocalAccount>())
        let accountID = link.flatMap { item in accounts.contains(where: { $0.id == item.accountID }) ? item.accountID : nil }
        let purchase = LocalPurchase(input: input, accountID: accountID)
        context.insert(purchase)
        do { try context.save() } catch { context.rollback(); throw error }
        return purchase
    }

    static func assign(_ purchase: LocalPurchase, to account: LocalAccount, remember: Bool, uniqueName: Bool, in context: ModelContext) throws {
        guard !remember || uniqueName else { throw LocalError.invalid("Confirm that only one card uses this name, or assign only this purchase.") }
        if remember {
            let key = PurchaseInput.normalize(purchase.cardName)
            if let existing = try context.fetch(FetchDescriptor<CardLink>(predicate: #Predicate { $0.cardKey == key })).first {
                guard existing.accountID == account.id else { throw LocalError.invalid("This card already links to another account. Remove its link in Wallet before changing it.") }
            } else { context.insert(CardLink(cardName: purchase.cardName, accountID: account.id)) }
        }
        purchase.accountID = account.id
        do { try context.save() } catch { context.rollback(); throw error }
    }
}
