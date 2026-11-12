import XCTest
import SwiftData
import UIKit
@testable import SpendTrace

@MainActor final class LocalLedgerTests: XCTestCase {
    func testReceiptStorageDoesNotCreateAnExpense() throws {
        let container = try memory(); let context = container.mainContext
        context.insert(LocalReceipt(image: Data([1, 2, 3]), method: ReceiptMethod.local.rawValue, merchant: "Shop", amount: "12.340000", currency: "", text: "Original text"))
        try context.save()
        XCTAssertEqual(try context.fetchCount(FetchDescriptor<LocalPurchase>()), 0)
        let receipt = try XCTUnwrap(context.fetch(FetchDescriptor<LocalReceipt>()).first)
        XCTAssertEqual(receipt.amount, "12.340000")
        XCTAssertEqual(receipt.image, Data([1, 2, 3]))
    }
    func testLocalReceiptRecognitionSuggestsFinancialFields() async throws {
        let image = UIGraphicsImageRenderer(size: CGSize(width: 800, height: 400)).image { renderer in
            UIColor.white.setFill(); renderer.fill(CGRect(x: 0, y: 0, width: 800, height: 400))
            ("EXAMPLE SHOP\nTOTAL 12.34" as NSString).draw(at: CGPoint(x: 40, y: 60), withAttributes: [.font: UIFont.systemFont(ofSize: 40), .foregroundColor: UIColor.black])
        }
        let reading = try await ReceiptRecognition.local(try XCTUnwrap(image.jpegData(compressionQuality: 1)))
        XCTAssertTrue(reading.text.contains("12.34"))
        XCTAssertEqual(reading.merchant, "EXAMPLE SHOP")
        XCTAssertEqual(reading.amount, "12.34")
        XCTAssertTrue(reading.currency.isEmpty)
    }
    func testCloudRequiresConsentAndTrustedHTTPSOrigin() async throws {
        for invalid in ["http://example.com", "https://user:secret@example.com", "https://example.com/path", "https://example.com?token=secret"] {
            XCTAssertThrowsError(try ReceiptRecognition.serverURL(invalid))
        }
        XCTAssertEqual(try ReceiptRecognition.serverURL("https://example.com").host, "example.com")
        let cloud = ReceiptCloudSession()
        do { _ = try await cloud.extract(Data(), consent: false); XCTFail("Missing consent must fail before networking") }
        catch { XCTAssertTrue(error.localizedDescription.contains("Confirm")) }
        XCTAssertFalse(cloud.connected)
    }
    func testLocalParserUsesPrintedTotalRatherThanSubtotalOrPayment() {
        let reading = LocalReceiptParser.parse("CORNER MARKET\n123 Main Street\nSUBTOTAL 10.00\nTAX 2.34\nTOTAL $12.34\nCASH 20.00\nCHANGE 7.66")
        XCTAssertEqual(reading.merchant, "CORNER MARKET")
        XCTAssertEqual(reading.amount, "12.34")
        XCTAssertEqual(reading.currency, "")
        let optical = LocalReceiptParser.parse("COSTCO\nOPTICAL\nSUBTOTAL 221.98\n*** TOTAL 221.98\nFSA TOTAL 221.98\nTOTAL NUMBER OF ITEMS SOLD 2")
        XCTAssertEqual(optical.amount, "221.98")
    }
    func testLocalParserSupportsDecimalCommaSeparatedColumnsAndExplicitCurrency() {
        let european = LocalReceiptParser.parse("CAFE BLEU\nTOTAL EUR 1.234,56")
        XCTAssertEqual(european.amount, "1234.56")
        XCTAssertEqual(european.currency, "EUR")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL 1 234,56 EUR").amount, "1234.56")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL 12 34.56").amount, "")
        let columns = LocalReceiptParser.parse("BOOK SHOP\nGRAND TOTAL\nUSD 42.90")
        XCTAssertEqual(columns.amount, "42.90")
        XCTAssertEqual(columns.currency, "USD")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL US$ 10.50").currency, "USD")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL -12.34 EUR").amount, "-12.34")
    }
    func testLocalParserLeavesAmbiguousTotalsAndCurrenciesForReview() {
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL 12.34\nTOTAL 56.78").amount, "")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL 1,234").amount, "")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nSUBTOTAL 12.34\nCASH 20.00").amount, "")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nUSD CAD\nTOTAL $12.34").currency, "")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL\nItem 12.34").amount, "")
        XCTAssertEqual(LocalReceiptParser.parse("SHOP\nTOTAL 12.34\nAMOUNT DUE 15.00").amount, "15.00")
    }
    func testCardScanKeepsOnlyIssuerAndSuffix() throws {
        let result = CardSuggestion.parse("CAPITAL ONE\nVISA\n4111 1111 1111 1111\nJANE CUSTOMER\n12/29\nCVV 123")
        XCTAssertEqual(result.name, "Capital One Visa")
        XCTAssertEqual(result.lastFour, "1111")
        let container = try memory(); let context = container.mainContext
        context.insert(LocalAccount(name: result.name, cardLastFour: result.lastFour))
        try context.save()
        let account = try XCTUnwrap(context.fetch(FetchDescriptor<LocalAccount>()).first)
        XCTAssertEqual(account.cardLastFour, "1111")
        XCTAssertEqual(try context.fetchCount(FetchDescriptor<CardLink>()), 0)
        XCTAssertEqual(try context.fetchCount(FetchDescriptor<LocalPurchase>()), 0)
        XCTAssertEqual(try context.fetchCount(FetchDescriptor<LocalReceipt>()), 0)
    }
    func testCardScanDoesNotGuessUnknownOrConflictingDetails() {
        XCTAssertEqual(CardSuggestion.parse("JANE CUSTOMER\n12/29\nCVV 123").name, "Card")
        XCTAssertEqual(CardSuggestion.parse("CVV ****1234").lastFour, "")
        XCTAssertEqual(CardSuggestion.parse("4111 1111 1111 1112").lastFour, "")
        XCTAssertEqual(CardSuggestion.parse("**** 1234\n**** 9876").lastFour, "")
        XCTAssertEqual(CardSuggestion.parse("VISA\n**** **** **** 4321").lastFour, "4321")
    }
    func testCardAccountValidationPreventsFullNumberStorage() throws {
        XCTAssertThrowsError(try CardSuggestion.validated(name: "Visa 4111 1111 1111 1111", lastFour: "1111"))
        XCTAssertThrowsError(try CardSuggestion.validated(name: "4111.1111.1111.1111", lastFour: ""))
        XCTAssertThrowsError(try CardSuggestion.validated(name: "Visa", lastFour: "4111111111111111"))
        XCTAssertThrowsError(try CardSuggestion.validated(name: "Visa", lastFour: "123"))
        XCTAssertEqual(try CardSuggestion.validated(name: " Personal Visa ", lastFour: "0123").name, "Personal Visa")
        XCTAssertEqual(try CardSuggestion.validated(name: "Personal", lastFour: "").lastFour, "")
    }
    private func memory() throws -> ModelContainer {
        try ModelContainer(for: LocalDatabase.schema, configurations: [ModelConfiguration(schema: LocalDatabase.schema, isStoredInMemoryOnly: true, cloudKitDatabase: .none)])
    }
    func testExactAmountsAndUnknownCurrency() throws {
        let input = try PurchaseInput(merchant: "Optical", amount: "156.990000", currency: "", cardName: "Visa")
        XCTAssertEqual(input.amount, "156.990000")
        XCTAssertEqual(input.currency, "")
        for invalid in ["12.34\n", "1,200.00", "$12.00", "12,30", "1e3", "NaN", "12.1234567"] {
            XCTAssertThrowsError(try PurchaseInput(merchant: "Shop", amount: invalid, currency: "USD", cardName: "Card"))
        }
    }
    func testReplayDoesNotCreateDuplicateOrReplacePurchase() throws {
        let container = try memory(); let context = container.mainContext
        let id = UUID().uuidString
        let input = try PurchaseInput(eventID: id, merchant: "Cafe", amount: "12.30", currency: "USD", cardName: "Visa")
        _ = try LocalDatabase.record(input, in: context)
        _ = try LocalDatabase.record(input, in: context)
        XCTAssertEqual(try context.fetchCount(FetchDescriptor<LocalPurchase>()), 1)
        let changed = try PurchaseInput(eventID: id, merchant: "Cafe", amount: "99", currency: "USD", cardName: "Visa")
        XCTAssertThrowsError(try LocalDatabase.record(changed, in: context))
        XCTAssertEqual(try context.fetch(FetchDescriptor<LocalPurchase>()).first?.amountText, "12.30")
    }
    func testConfirmedCardMappingAndAccountConflicts() throws {
        let container = try memory(); let context = container.mainContext
        let first = LocalAccount(name: "Personal"); let second = LocalAccount(name: "Business")
        context.insert(first); context.insert(second); try context.save()
        let input = try PurchaseInput(merchant: "Shop", amount: "10", currency: "USD", cardName: "Everyday Visa")
        let purchase = try LocalDatabase.record(input, in: context)
        XCTAssertNil(purchase.accountID)
        XCTAssertThrowsError(try LocalDatabase.assign(purchase, to: first, remember: true, uniqueName: false, in: context))
        try LocalDatabase.assign(purchase, to: first, remember: true, uniqueName: true, in: context)
        let next = try LocalDatabase.record(PurchaseInput(merchant: "Shop", amount: "11", currency: "USD", cardName: " everyday   VISA "), in: context)
        XCTAssertEqual(next.accountID, first.id)
        XCTAssertThrowsError(try LocalDatabase.assign(next, to: second, remember: true, uniqueName: true, in: context))
        XCTAssertEqual(next.accountID, first.id)
        XCTAssertEqual(purchase.accountID, first.id)
    }
    func testLocalStorePersistsWithoutCloudKit() throws {
        let folder = FileManager.default.temporaryDirectory.appendingPathComponent(UUID().uuidString, isDirectory: true)
        try FileManager.default.createDirectory(at: folder, withIntermediateDirectories: true)
        defer { try? FileManager.default.removeItem(at: folder) }
        let url = folder.appendingPathComponent("test.store")
        let config = ModelConfiguration(schema: LocalDatabase.schema, url: url, cloudKitDatabase: .none)
        var container: ModelContainer? = try ModelContainer(for: LocalDatabase.schema, configurations: [config])
        _ = try LocalDatabase.record(PurchaseInput(merchant: "Local shop", amount: "7.50", currency: "USD", cardName: "Visa"), in: container!.mainContext)
        container = nil
        let reopened = try ModelContainer(for: LocalDatabase.schema, configurations: [config])
        XCTAssertEqual(try reopened.mainContext.fetch(FetchDescriptor<LocalPurchase>()).first?.merchant, "Local shop")
    }
    func testShortcutActionRecordsLocallyWithoutCreatingDuplicates() async throws {
        let context = try LocalDatabase.container().mainContext
        let id = UUID().uuidString
        var intent = RecordPurchaseIntent()
        intent.merchant = "Intent test fixture"
        intent.amount = "12.340000"
        intent.currency = "USD"
        intent.cardName = "Test card"
        intent.eventID = id
        defer {
            if let records = try? context.fetch(FetchDescriptor<LocalPurchase>(predicate: #Predicate { $0.eventID == id })) {
                for record in records { context.delete(record) }
                try? context.save()
            }
        }
        _ = try await intent.perform()
        _ = try await intent.perform()
        let records = try context.fetch(FetchDescriptor<LocalPurchase>(predicate: #Predicate { $0.eventID == id }))
        XCTAssertEqual(records.count, 1)
        XCTAssertEqual(records.first?.amountText, "12.340000")
        XCTAssertNil(records.first?.accountID)
    }

}
