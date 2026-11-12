import Foundation
import SwiftData
import Vision
import UIKit
import Security

@Model final class LocalReceipt {
    @Attribute(.unique) var id: UUID
    var createdAt: Date
    var method: String
    var merchant: String
    var amount: String
    var currency: String
    var extractedText: String
    @Attribute(.externalStorage) var image: Data
    init(image: Data, method: String, merchant: String, amount: String, currency: String, text: String) {
        id = UUID(); createdAt = .now; self.image = image; self.method = method
        self.merchant = merchant; self.amount = amount; self.currency = currency; extractedText = text
    }
}

enum ReceiptMethod: String, CaseIterable {
    case local = "On this iPhone"
    case openAI = "OpenAI"
}

struct ReceiptReading {
    var merchant = ""
    var amount = ""
    var currency = ""
    var text: String
}

/// Conservative suggestions from printed evidence. Never infer currency from the device locale.
enum LocalReceiptParser {
    static func parse(_ text: String) -> ReceiptReading {
        let lines = text.components(separatedBy: .newlines).map { $0.trimmingCharacters(in: .whitespacesAndNewlines) }.filter { !$0.isEmpty }
        let upper = text.uppercased()
        var currencies = Set<String>()
        for code in Locale.Currency.isoCurrencies.map(\.identifier) {
            if matches("(?<![A-Z])" + NSRegularExpression.escapedPattern(for: code) + "(?![A-Z])", upper) { currencies.insert(code) }
        }
        for (symbol, code) in [("€", "EUR"), ("US$", "USD"), ("CA$", "CAD"), ("AU$", "AUD"), ("NZ$", "NZD")] {
            if upper.contains(symbol) { currencies.insert(code) }
        }
        let currency = currencies.count == 1 ? currencies.first! : ""
        let merchant = lines.prefix(10).first { line in
            let letters = line.unicodeScalars.filter { CharacterSet.letters.contains($0) }.count
            return letters >= 3 && line.count <= 100
                && !matches(#"(?i)\b(receipt|invoice|welcome|thank|tel|phone|www|https|cashier|member|order|transaction|date|time|total|tax|vat|copy|customer)\b|@"#, line)
                && !matches(#"\d{3}|^\d|\d+[.,]\d{2}\s*$"#, line)
        } ?? ""
        var totals: [(rank: Int, amount: String)] = []
        for (index, line) in lines.enumerated() {
            let label = line.uppercased()
            if matches(#"\b(SUB\s*TOTAL|SUBTOTAL|TAX|TIP|SAVINGS|DISCOUNT|CHANGE|CASH|TENDERED|FSA|ELIGIBLE|ITEMS|SOLD|QUANTITY|NUMBER|PAID|VAT)\b"#, label) { continue }
            let rank: Int
            if matches(#"\b(GRAND\s+TOTAL|AMOUNT\s+DUE|TOTAL\s+DUE|TOTAL\s+AMOUNT)\b"#, label) { rank = 3 }
            else if matches(#"\b(TOTAL|TOTALE|SUMME|GESAMT|IMPORTE)\b"#, label) { rank = 2 }
            else if matches(#"\b(BALANCE\s+DUE)\b"#, label) { rank = 1 }
            else { continue }
            if let amount = trailingAmount(line) { totals.append((rank, amount)) }
            else if index + 1 < lines.count, isAmountOnly(lines[index + 1]), let amount = trailingAmount(lines[index + 1]) {
                totals.append((rank, amount))
            }
        }
        let bestRank = totals.map(\.rank).max()
        let amounts = Set(totals.filter { $0.rank == bestRank }.map(\.amount))
        return ReceiptReading(merchant: merchant, amount: amounts.count == 1 ? amounts.first! : "", currency: currency, text: text)
    }

    private static func matches(_ pattern: String, _ text: String) -> Bool {
        text.range(of: pattern, options: .regularExpression) != nil
    }

    private static func isAmountOnly(_ line: String) -> Bool {
        matches(#"^(?:[A-Z]{3}\s*)?[$€£¥]?\s*-?\d[\d., ]*(?:\s*[A-Z]{3})?$"#, line.uppercased())
    }

    private static func trailingAmount(_ line: String) -> String? {
        let pattern = #"(?<![\d.,])-?\d[\d., \u00a0]*(?=\s*(?:[A-Z]{3}|[$€£¥])?\s*$)"#
        guard let range = line.uppercased().range(of: pattern, options: .regularExpression) else { return nil }
        let value = String(line.uppercased()[range]).trimmingCharacters(in: .whitespaces).replacingOccurrences(of: "\u{00a0}", with: " ")
        let separators = value.filter { $0 == "." || $0 == "," }
        if separators.isEmpty { return matches(#"^-?\d{1,12}$"#, value) ? value : nil }
        guard let separator = value.lastIndex(where: { $0 == "." || $0 == "," }) else { return nil }
        let fraction = value[value.index(after: separator)...]
        // Three trailing digits could be grouping or decimal precision; leave it for review.
        guard fraction.count == 1 || fraction.count == 2 else { return nil }
        var whole = String(value[..<separator])
        if whole.contains(" ") {
            let groups = whole.split(separator: " ", omittingEmptySubsequences: false)
            guard (1...3).contains(groups[0].replacingOccurrences(of: "-", with: "").count), groups.dropFirst().allSatisfy({ $0.count == 3 && $0.allSatisfy(\.isNumber) }) else { return nil }
            whole = groups.joined()
        }
        let decimal = value[separator]
        let grouping: Character = decimal == "." ? "," : "."
        guard !whole.contains(decimal) else { return nil }
        if whole.contains(grouping) {
            let groups = whole.split(separator: grouping, omittingEmptySubsequences: false)
            guard (1...3).contains(groups[0].replacingOccurrences(of: "-", with: "").count), groups.dropFirst().allSatisfy({ $0.count == 3 }) else { return nil }
        }
        let result = whole.replacingOccurrences(of: String(grouping), with: "") + "." + fraction
        return matches(#"^-?\d{1,12}\.\d{1,2}$"#, result) ? result : nil
    }
}

enum ReceiptRecognition {
    static func local(_ image: Data) async throws -> ReceiptReading {
        try await Task.detached(priority: .userInitiated) {
            let request = VNRecognizeTextRequest()
            request.recognitionLevel = .accurate
            request.usesLanguageCorrection = true
            request.automaticallyDetectsLanguage = true
            try VNImageRequestHandler(data: image).perform([request])
            let observations = (request.results ?? []).sorted { $0.boundingBox.midY > $1.boundingBox.midY }
            var rows: [[VNRecognizedTextObservation]] = []
            for observation in observations {
                if let index = rows.firstIndex(where: { row in
                    guard let first = row.first else { return false }
                    return abs(first.boundingBox.midY - observation.boundingBox.midY) < min(first.boundingBox.height, observation.boundingBox.height) * 0.5
                }) {
                    rows[index].append(observation)
                } else { rows.append([observation]) }
            }
            let text = rows.map { row in
                row.sorted { $0.boundingBox.minX < $1.boundingBox.minX }.compactMap { $0.topCandidates(1).first?.string }.joined(separator: " ")
            }.joined(separator: "\n")
            guard !text.isEmpty else { throw LocalError.invalid("No readable text found. Retake the photo with better lighting or enter the details manually.") }
            return LocalReceiptParser.parse(text)
        }.value
    }

    static func serverURL(_ value: String) throws -> URL {
        guard let url = URL(string: value.trimmingCharacters(in: .whitespacesAndNewlines)),
              url.scheme == "https", url.host != nil, url.user == nil, url.password == nil,
              url.query == nil, url.fragment == nil, url.path.isEmpty || url.path == "/" else {
            throw LocalError.invalid("Enter your trusted SpendTrace server’s HTTPS address, without a path.")
        }
        return url
    }
}

/// Refuse redirects so credentials and photos cannot be forwarded to another server.
final class ReceiptNetworkDelegate: NSObject, URLSessionTaskDelegate {
    func urlSession(_ session: URLSession, task: URLSessionTask, willPerformHTTPRedirection response: HTTPURLResponse, newRequest request: URLRequest, completionHandler: @escaping (URLRequest?) -> Void) {
        completionHandler(nil)
    }
}

@MainActor final class ReceiptCloudSession: ObservableObject {
    static let shared = ReceiptCloudSession()
    static var configuredServer: String { Bundle.main.object(forInfoDictionaryKey: "SpendTraceServerURL") as? String ?? "" }
    private static let keychainQuery: [String: Any] = [kSecClass as String: kSecClassGenericPassword, kSecAttrService as String: "com.spendtrace.local.ai", kSecAttrAccount as String: "session"]

    init() {
        var query = Self.keychainQuery
        query[kSecReturnData as String] = true
        query[kSecMatchLimit as String] = kSecMatchLimitOne
        var value: CFTypeRef?
        guard SecItemCopyMatching(query as CFDictionary, &value) == errSecSuccess,
              let data = value as? Data, let saved = try? JSONSerialization.jsonObject(with: data) as? [String: String],
              let address = saved["origin"], let origin = try? ReceiptRecognition.serverURL(address),
              Self.configuredServer.isEmpty || (try? ReceiptRecognition.serverURL(Self.configuredServer)) == origin,
              let expires = saved["expires"], let date = ISO8601DateFormatter().date(from: expires), date > .now else { return }
        self.origin = origin; token = saved["token"]
    }
    @Published private(set) var origin: URL?
    private var token: String?
    private let session = URLSession(configuration: .ephemeral, delegate: ReceiptNetworkDelegate(), delegateQueue: nil)
    var connected: Bool { token != nil }

    func disconnect() { token = nil; origin = nil; SecItemDelete(Self.keychainQuery as CFDictionary) }

    func connect(server: String, email: String, password: String) async throws {
        disconnect()
        let origin = try ReceiptRecognition.serverURL(server)
        var request = URLRequest(url: origin.appendingPathComponent("api/v1/mobile/receipt-session"))
        request.httpMethod = "POST"
        request.setValue("application/json", forHTTPHeaderField: "Content-Type")
        request.httpBody = try JSONSerialization.data(withJSONObject: ["email": email, "password": password])
        let data = try await send(request)
        guard let value = try JSONSerialization.jsonObject(with: data) as? [String: Any], let token = value["token"] as? String, let expires = value["expires_at"] as? String else { throw LocalError.invalid("The server returned an invalid sign-in response.") }
        let saved = try JSONSerialization.data(withJSONObject: ["origin": origin.absoluteString, "token": token, "expires": expires])
        var query = Self.keychainQuery
        query[kSecValueData as String] = saved
        query[kSecAttrAccessible as String] = kSecAttrAccessibleWhenUnlockedThisDeviceOnly
        guard SecItemAdd(query as CFDictionary, nil) == errSecSuccess else { throw LocalError.invalid("Could not securely save your sign-in. Please try again.") }
        self.origin = origin; self.token = token
    }

    func extract(_ image: Data, consent: Bool) async throws -> ReceiptReading {
        guard consent else { throw LocalError.invalid("Confirm this receipt upload first.") }
        guard let token, let origin else { throw LocalError.invalid("Sign in to your SpendTrace server first.") }
        let boundary = UUID().uuidString
        var request = URLRequest(url: origin.appendingPathComponent("api/v1/mobile/receipt-extraction"))
        request.httpMethod = "POST"; request.timeoutInterval = 110
        request.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        request.setValue("multipart/form-data; boundary=\(boundary)", forHTTPHeaderField: "Content-Type")
        var body = Data("--\(boundary)\r\nContent-Disposition: form-data; name=\"consent\"\r\n\r\n1\r\n--\(boundary)\r\nContent-Disposition: form-data; name=\"image\"; filename=\"receipt.jpg\"\r\nContent-Type: image/jpeg\r\n\r\n".utf8)
        body.append(image); body.append(Data("\r\n--\(boundary)--\r\n".utf8)); request.httpBody = body
        let data = try await send(request)
        guard let result = try JSONSerialization.jsonObject(with: data) as? [String: Any] else { throw LocalError.invalid("Invalid receipt response.") }
        var lines: [String] = []
        for (key, label) in [("merchant", "Merchant"), ("purchase_date", "Date"), ("purchase_time", "Time"), ("subtotal_decimal", "Subtotal"), ("tax_decimal", "Tax"), ("tip_decimal", "Tip"), ("discount_decimal", "Discount"), ("total_decimal", "Total"), ("currency", "Currency")] {
            if let value = result[key] as? String { lines.append("\(label): \(value)") }
        }
        lines.append("\nItems — verify each line against the photo")
        for item in result["items"] as? [[String: Any]] ?? [] {
            let description = item["description"] as? String ?? "Unreadable item"
            let amount = item["amount_decimal"] as? String ?? "Unknown amount"
            let quantity = (item["quantity"] as? String).map { " (quantity \($0))" } ?? ""
            lines.append("\(description)\(quantity): \(amount)")
        }
        if let reasons = result["review_reasons"] as? [String], !reasons.isEmpty { lines.append("\nCheck: " + reasons.joined(separator: "\n")) }
        let text = lines.joined(separator: "\n")
        return ReceiptReading(merchant: result["merchant"] as? String ?? "", amount: result["total_decimal"] as? String ?? "", currency: result["currency"] as? String ?? "", text: text)
    }

    private func send(_ request: URLRequest) async throws -> Data {
        var request = request; request.setValue("application/json", forHTTPHeaderField: "Accept")
        let (data, response) = try await session.data(for: request)
        guard let response = response as? HTTPURLResponse else { throw LocalError.invalid("No server response.") }
        if response.statusCode == 401 { disconnect(); throw LocalError.invalid("Sign in again. Check your email and password.") }
        if response.statusCode == 403 { throw LocalError.invalid("AI access isn’t enabled for this SpendTrace account.") }
        guard (200..<300).contains(response.statusCode) else {
            throw LocalError.invalid(response.statusCode == 429 ? "Too many requests. Wait a minute before retrying." : "Processing failed (\(response.statusCode)). Nothing was saved to your ledger. You can retry or use local processing.")
        }
        return data
    }
}
