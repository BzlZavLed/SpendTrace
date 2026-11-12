import SwiftUI
import SwiftData
import PhotosUI
import Vision
import VisionKit

struct CardSuggestion {
    let name: String
    let lastFour: String

    static func parse(_ text: String) -> CardSuggestion {
        let upper = text.uppercased()
        let issuers = ["Bank of America", "Capital One", "American Express", "Wells Fargo", "Chase", "Citibank", "Citi", "Discover", "Chime", "Synchrony", "Barclays", "HSBC", "Santander", "Monzo", "Revolut"]
        let networks = ["Mastercard", "Visa"]
        let issuer = issuers.first { contains($0, in: upper) }
        let network = networks.first { contains($0, in: upper) }
        let name = [issuer, network].compactMap { $0 }.joined(separator: " ")
        var suffixes = Set<String>()
        let pattern = #"(?<!\d)(?:\d[ -]?){13,19}(?!\d)|[Xx*•●]{2,}[ -]*(\d{4})(?!\d)"#
        if let regex = try? NSRegularExpression(pattern: pattern) {
            for line in text.components(separatedBy: .newlines) {
                guard line.range(of: #"(?i)\b(CVV|CVC|CID|PIN|SECURITY)\b"#, options: .regularExpression) == nil else { continue }
                for match in regex.matches(in: line, range: NSRange(line.startIndex..., in: line)) {
                    guard let range = Range(match.range, in: line) else { continue }
                    let digits = line[range].filter { $0.isASCII && $0.isNumber }
                    if digits.count == 4 || validCardNumber(digits) { suffixes.insert(String(digits.suffix(4))) }
                }
            }
        }
        return CardSuggestion(name: name.isEmpty ? "Card" : name, lastFour: suffixes.count == 1 ? suffixes.first! : "")
    }

    private static func contains(_ name: String, in text: String) -> Bool {
        text.range(of: "\\b" + NSRegularExpression.escapedPattern(for: name.uppercased()) + "\\b", options: .regularExpression) != nil
    }

    private static func validCardNumber(_ value: String) -> Bool {
        guard (13...19).contains(value.count), Set(value).count > 1 else { return false }
        let sum = value.reversed().enumerated().reduce(0) { total, pair in
            let digit = pair.element.wholeNumberValue ?? 0
            let doubled = pair.offset % 2 == 1 ? digit * 2 : digit
            return total + (doubled > 9 ? doubled - 9 : doubled)
        }
        return sum % 10 == 0
    }

    static func validated(name: String, lastFour: String) throws -> CardSuggestion {
        let name = name.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !name.isEmpty, name.count <= 100 else { throw LocalError.invalid("Enter an account name under 100 characters.") }
        guard name.filter(\.isNumber).count <= 4 else { throw LocalError.invalid("Use an account name, not a full card number.") }
        guard lastFour.isEmpty || (lastFour.count == 4 && lastFour.allSatisfy({ $0.isASCII && $0.isNumber })) else { throw LocalError.invalid("Enter only the last four digits, or leave them blank.") }
        return CardSuggestion(name: name, lastFour: lastFour)
    }

    static func read(_ image: Data) async throws -> CardSuggestion {
        try await Task.detached(priority: .userInitiated) {
            let request = VNRecognizeTextRequest()
            request.recognitionLevel = .accurate
            request.automaticallyDetectsLanguage = true
            try VNImageRequestHandler(data: image).perform([request])
            let text = (request.results ?? []).compactMap { $0.topCandidates(1).first?.string }.joined(separator: "\n")
            guard !text.isEmpty else { throw LocalError.invalid("Couldn’t read the card. Try better lighting or enter its name below.") }
            return parse(text)
        }.value
    }
}

struct CardScanView: View {
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    @State private var camera = false
    @State private var photo: PhotosPickerItem?
    @State private var name = ""
    @State private var lastFour = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Label("Add an account from your card", systemImage: "creditcard.viewfinder")
                    Button("Take photo", systemImage: "camera") { camera = true }.disabled(!VNDocumentCameraViewController.isSupported)
                    PhotosPicker("Choose photo", selection: $photo, matching: .images)
                    Text("Use the front of the card. Reading happens on this iPhone. Only the account name and optional last four digits are saved; the photo and other card details are discarded.").font(.caption).foregroundStyle(.secondary)
                }
                if busy { ProgressView("Reading card…") }
                Section("Confirm account") {
                    TextField("Account name", text: $name).textContentType(.none)
                    TextField("Last four digits (optional)", text: $lastFour).keyboardType(.numberPad).textContentType(.none)
                    Button("Create account") { save() }.disabled(name.trimmingCharacters(in: .whitespacesAndNewlines).isEmpty)
                    Text("This creates a local account label. You’ll confirm its Wallet card link when a purchase arrives.").font(.caption).foregroundStyle(.secondary)
                }
                if let error { Text(error).foregroundStyle(.red) }
            }.disabled(busy).navigationTitle("Scan card")
                .toolbar { Button("Cancel") { dismiss() }.disabled(busy) }
                .interactiveDismissDisabled(busy)
                .sheet(isPresented: $camera) {
                    ReceiptCamera { images in
                        camera = false
                        if let image = images.first { read(image) }
                    } cancel: { camera = false } failure: { error = $0; camera = false }
                }
                .onChange(of: photo) { _, item in
                    guard let item else { return }
                    busy = true; error = nil; name = ""; lastFour = ""
                    Task {
                        do {
                            guard let data = try await item.loadTransferable(type: Data.self), let image = UIImage(data: data) else { throw LocalError.invalid("Could not load the photo.") }
                            busy = false; read(image)
                        } catch { busy = false; self.error = error.localizedDescription }
                        photo = nil
                    }
                }
        }
    }

    private func read(_ image: UIImage) {
        busy = true; error = nil; name = ""; lastFour = ""
        let scale = min(1, 2500 / max(image.size.width, image.size.height))
        let size = CGSize(width: image.size.width * scale, height: image.size.height * scale)
        let format = UIGraphicsImageRendererFormat(); format.scale = 1
        let data = UIGraphicsImageRenderer(size: size, format: format).image { _ in image.draw(in: CGRect(origin: .zero, size: size)) }.jpegData(compressionQuality: 0.95)
        Task {
            defer { busy = false }
            do {
                guard let data else { throw LocalError.invalid("Could not read this photo.") }
                let result = try await CardSuggestion.read(data)
                name = result.name; lastFour = result.lastFour
            } catch { self.error = error.localizedDescription }
        }
    }

    private func save() {
        do {
            let result = try CardSuggestion.validated(name: name, lastFour: lastFour)
            let existing = try context.fetch(FetchDescriptor<LocalAccount>())
            guard !existing.contains(where: { PurchaseInput.normalize($0.name) == PurchaseInput.normalize(result.name) && ($0.cardLastFour ?? "") == result.lastFour }) else {
                throw LocalError.invalid("That account already exists. Use a different name if this is another account.")
            }
            context.insert(LocalAccount(name: result.name, cardLastFour: result.lastFour.isEmpty ? nil : result.lastFour))
            try context.save(); dismiss()
        } catch { context.rollback(); self.error = error.localizedDescription }
    }
}
