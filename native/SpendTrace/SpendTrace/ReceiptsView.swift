import SwiftUI
import SwiftData
import VisionKit
import PhotosUI

struct ReceiptsView: View {
    @Query(sort: \LocalReceipt.createdAt, order: .reverse) private var receipts: [LocalReceipt]
    @State private var scanning = false
    @StateObject private var cloud = ReceiptCloudSession.shared
    var body: some View {
        NavigationStack {
            List {
                Section { Text("Choose on-device reading or OpenAI for each receipt. Local processing is the default.") }
                ForEach(receipts) { receipt in
                    NavigationLink {
                        List {
                            if let image = UIImage(data: receipt.image) { Image(uiImage: image).resizable().scaledToFit() }
                            Text(receipt.method)
                            Text("\(receipt.currency) \(receipt.amount)")
                            Text(receipt.extractedText).textSelection(.enabled)
                        }.navigationTitle(receipt.merchant.isEmpty ? "Receipt" : receipt.merchant)
                    } label: {
                        VStack(alignment: .leading) {
                            Text(receipt.merchant.isEmpty ? "Receipt" : receipt.merchant)
                            Text("\(receipt.currency) \(receipt.amount) · \(receipt.method)").font(.caption).foregroundStyle(.secondary)
                        }
                    }
                }
                if receipts.isEmpty { Text("Scan a receipt to keep its photo and reviewed details on this iPhone.").foregroundStyle(.secondary) }
            }.navigationTitle("Receipts").toolbar { Button("Scan", systemImage: "camera") { scanning = true } }
                .sheet(isPresented: $scanning) { ReceiptScanView(cloud: cloud) }
        }
    }
}

struct ReceiptScanView: View {
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    @ObservedObject var cloud: ReceiptCloudSession
    var opensCameraOnAppear = false
    @State private var didOpenCamera = false
    @State private var image: Data?
    @State private var photo: PhotosPickerItem?
    @State private var camera = false
    @State private var method = ReceiptMethod.local
    @State private var reading: ReceiptReading?
    @State private var merchant = ""
    @State private var amount = ""
    @State private var currency = ""
    @State private var busy = false
    @State private var loadingPhoto = false
    @State private var error: String?
    @State private var showingAI = false

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    if let image, let preview = UIImage(data: image) {
                        Image(uiImage: preview).resizable().scaledToFit().frame(maxWidth: .infinity, maxHeight: reading == nil ? 250 : 150)
                    }
                    HStack {
                        Button(image == nil ? "Take photo" : "Retake", systemImage: "camera") { camera = true }
                            .disabled(!VNDocumentCameraViewController.isSupported)
                        Spacer()
                        PhotosPicker(selection: $photo, matching: .images) { Label("Choose photo", systemImage: "photo") }
                    }.buttonStyle(.borderless)
                }
                if loadingPhoto { ProgressView("Loading photo…") }
                if image != nil && reading == nil && !busy {
                    Section {
                        Button { process(using: .local) } label: {
                            Label("Process locally", systemImage: "iphone.gen3").frame(maxWidth: .infinity).padding(.vertical, 6)
                        }.buttonStyle(.borderedProminent)
                        Text("Private and offline. Check the suggested details.").font(.caption).foregroundStyle(.secondary)
                        Button { showingAI = true } label: {
                            Label("Process with AI", systemImage: "sparkles").frame(maxWidth: .infinity).padding(.vertical, 6)
                        }.buttonStyle(.bordered)
                        Text("Sends the photo to OpenAI. May read complex receipts better; internet and API usage required.").font(.caption).foregroundStyle(.secondary)
                    }
                }
                if busy { ProgressView(method == .local ? "Reading on this iPhone…" : "Reading with OpenAI…") }
                if let reading {
                    Section("Check your receipt") {
                        LabeledContent("Merchant") { TextField("Enter merchant", text: $merchant).multilineTextAlignment(.trailing) }
                        LabeledContent("Total") { TextField("Enter total", text: $amount).keyboardType(.decimalPad).multilineTextAlignment(.trailing) }
                        LabeledContent("Currency") { TextField("Unknown", text: $currency).textInputAutocapitalization(.characters).autocorrectionDisabled().multilineTextAlignment(.trailing) }
                        Text("Correct anything that looks wrong. A blank field means we couldn’t identify it confidently.").font(.caption).foregroundStyle(.secondary)
                        DisclosureGroup("See recognized text") { Text(reading.text).textSelection(.enabled) }
                        Button("Confirm and save receipt") { save(reading) }.buttonStyle(.borderedProminent)
                        Button("Try another processing method") { self.reading = nil; error = nil }
                    }
                }
                if let error {
                    Section {
                        Text(error).foregroundStyle(.red)
                        if image != nil && reading == nil && !busy {
                            Button("Enter details manually") { reading = ReceiptReading(text: "Manually entered"); merchant = ""; amount = ""; currency = "" }
                        }
                    }
                }
            }
            .disabled(busy || loadingPhoto)
            .navigationTitle(reading == nil ? "Scan receipt" : "Review receipt")
            .toolbar { Button("Close") { dismiss() }.disabled(busy || loadingPhoto) }
            .interactiveDismissDisabled(busy || loadingPhoto)
            .onAppear {
                guard opensCameraOnAppear, !didOpenCamera else { return }
                didOpenCamera = true
                if VNDocumentCameraViewController.isSupported { camera = true }
                else { error = "Camera unavailable. Choose a photo instead." }
            }
            .sheet(isPresented: $camera) {
                ReceiptCamera { images in
                    camera = false
                    if let first = images.first { setImage(first) }
                    if images.count > 1 { error = "Only the first page was selected. Scan additional receipts separately." }
                } cancel: { camera = false } failure: { error = $0; camera = false }
            }
            .sheet(isPresented: $showingAI) {
                ReceiptAIConsentView(cloud: cloud) {
                    showingAI = false
                    process(using: .openAI)
                }
            }
            .onChange(of: photo) { _, item in
                guard let item else { return }
                image = nil; reading = nil; error = nil; loadingPhoto = true
                Task {
                    defer { loadingPhoto = false }
                    do {
                        guard let data = try await item.loadTransferable(type: Data.self), let image = UIImage(data: data) else { throw LocalError.invalid("Could not load this photo. Choose another image.") }
                        setImage(image)
                    } catch { self.error = error.localizedDescription }
                }
            }
        }
    }
    private func setImage(_ source: UIImage) {
        let scale = min(1, 3000 / max(source.size.width, source.size.height))
        let size = CGSize(width: source.size.width * scale, height: source.size.height * scale)
        let format = UIGraphicsImageRendererFormat(); format.scale = 1
        image = UIGraphicsImageRenderer(size: size, format: format).image { _ in source.draw(in: CGRect(origin: .zero, size: size)) }.jpegData(compressionQuality: 0.9)
        reading = nil; error = nil; merchant = ""; amount = ""; currency = ""
    }
    private func process(using method: ReceiptMethod) {
        guard let image else { return }
        self.method = method; busy = true; error = nil; reading = nil
        Task {
            defer { busy = false }
            do {
                let result = try await (method == .local ? ReceiptRecognition.local(image) : cloud.extract(image, consent: true))
                reading = result; merchant = result.merchant; amount = result.amount; currency = result.currency
            } catch { self.error = error.localizedDescription }
        }
    }
    private func save(_ reading: ReceiptReading) {
        guard let image else { return }
        do {
            let input = try PurchaseInput(merchant: merchant, amount: amount, currency: currency, cardName: "Receipt", source: "receipt")
            context.insert(LocalReceipt(image: image, method: method.rawValue, merchant: input.merchant, amount: input.amount, currency: input.currency, text: reading.text))
            try context.save(); dismiss()
        } catch { context.rollback(); self.error = error.localizedDescription }
    }
}

struct ReceiptAIConsentView: View {
    @Environment(\.dismiss) private var dismiss
    @ObservedObject var cloud: ReceiptCloudSession
    let send: () -> Void
    @State private var server = ReceiptCloudSession.configuredServer
    @State private var email = ""
    @State private var password = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            Form {
                Section {
                    Text("This photo will be sent through your SpendTrace server to OpenAI, including any personal information visible on it.")
                    Text("AI may read complex receipts better, but can make mistakes. Internet is required and API usage is billed to the server operator.").font(.subheadline).foregroundStyle(.secondary)
                    DisclosureGroup("Privacy details") {
                        Text("SpendTrace uses a temporary upload without adding it to your web ledger. OpenAI API data is not used for training by default. Abuse-monitoring retention is generally up to 30 days, with exceptions; disabling response storage does not guarantee zero retention. This does not turn on dashboard sync.")
                        Link("OpenAI data policy", destination: URL(string: "https://developers.openai.com/api/docs/guides/your-data")!)
                    }
                }
                if cloud.connected {
                    Section {
                        Text("Via \(cloud.origin?.host ?? "your SpendTrace server")").font(.caption)
                        Button("Send photo and process") { send() }.buttonStyle(.borderedProminent)
                        Button("Sign out") { cloud.disconnect() }
                    }
                } else {
                    Section("Sign in to SpendTrace") {
                        Text("AI is available for testing. Use your SpendTrace account; no OpenAI account or API key is needed.").font(.caption)
                        if ReceiptCloudSession.configuredServer.isEmpty {
                            Text("AI service isn’t configured in this build. Local processing is still available.").foregroundStyle(.secondary)
                        }
                        TextField("Email", text: $email).keyboardType(.emailAddress).textInputAutocapitalization(.never).autocorrectionDisabled()
                        SecureField("Password", text: $password)
                        Button("Sign in") {
                            busy = true; error = nil
                            Task {
                                defer { busy = false; password = "" }
                                do { try await cloud.connect(server: server, email: email, password: password) }
                                catch { self.error = error.localizedDescription }
                            }
                        }.disabled(ReceiptCloudSession.configuredServer.isEmpty)
                    }
                }
                if busy { ProgressView("Signing in…") }
                if let error { Text(error).foregroundStyle(.red) }
            }.disabled(busy).navigationTitle("Process with AI")
                .toolbar { Button("Cancel") { dismiss() }.disabled(busy) }
                .interactiveDismissDisabled(busy)
        }
    }
}

struct ReceiptCamera: UIViewControllerRepresentable {
    let finish: ([UIImage]) -> Void
    let cancel: () -> Void
    let failure: (String) -> Void
    func makeCoordinator() -> Coordinator { Coordinator(self) }
    func makeUIViewController(context: Context) -> VNDocumentCameraViewController { let camera = VNDocumentCameraViewController(); camera.delegate = context.coordinator; return camera }
    func updateUIViewController(_ controller: VNDocumentCameraViewController, context: Context) {}
    final class Coordinator: NSObject, VNDocumentCameraViewControllerDelegate {
        let parent: ReceiptCamera
        init(_ parent: ReceiptCamera) { self.parent = parent }
        func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFinishWith scan: VNDocumentCameraScan) { parent.finish((0..<scan.pageCount).map { scan.imageOfPage(at: $0) }) }
        func documentCameraViewControllerDidCancel(_ controller: VNDocumentCameraViewController) { parent.cancel() }
        func documentCameraViewController(_ controller: VNDocumentCameraViewController, didFailWithError error: Error) { parent.failure("Camera unavailable. Check camera permission in Settings, or choose a photo.") }
    }
}
