import SwiftUI
import SwiftData
import AppIntents

struct RootView: View {
    @Environment(\.modelContext) private var context
    @Query(sort: \LocalPurchase.observedAt, order: .reverse) private var purchases: [LocalPurchase]
    @Query(sort: \LocalAccount.name) private var accounts: [LocalAccount]
    @Query(sort: \CardLink.cardName) private var links: [CardLink]
    @State private var addingReceipt = false
    @StateObject private var receiptCloud = ReceiptCloudSession.shared
    @State private var accountName = ""
    @State private var scanningCard = false
    @State private var selected: LocalPurchase?
    @State private var dismissed = Set<String>()
    @State private var error: String?
    @State private var tab = 0

    var body: some View {
        TabView(selection: $tab) {
            NavigationStack {
                List {
                    Section {
                        Label("Stored on this iPhone", systemImage: "iphone.gen3")
                            .font(.subheadline).foregroundStyle(.secondary)
                    }
                    if purchases.isEmpty {
                        ContentUnavailableView("Your spending starts here", systemImage: "receipt", description: Text("Add a purchase, or connect the local Wallet action in Shortcuts."))
                    }
                    ForEach(purchases) { purchase in
                        Button { selected = purchase } label: {
                            HStack {
                                VStack(alignment: .leading, spacing: 5) {
                                    Text(purchase.merchant).font(.headline)
                                    Text(purchase.cardName).font(.caption).foregroundStyle(.secondary)
                                    Text(accounts.first(where: { $0.id == purchase.accountID })?.name ?? "Choose an account")
                                        .font(.caption).foregroundStyle(purchase.accountID == nil ? .orange : .secondary)
                                }
                                Spacer()
                                VStack(alignment: .trailing) {
                                    Text("\(purchase.currency.isEmpty ? "?" : purchase.currency) \(purchase.amountText)").monospacedDigit()
                                    Text(purchase.observedAt, style: .date).font(.caption).foregroundStyle(.secondary)
                                }
                            }.foregroundStyle(.primary)
                        }
                    }
                }.navigationTitle("SpendTrace")
                    .toolbar { Button("Add receipt", systemImage: "plus") { addingReceipt = true } }
            }.tabItem { Label("Ledger", systemImage: "list.bullet.rectangle") }.tag(0)
            ReceiptsView().tabItem { Label("Receipts", systemImage: "camera") }.tag(3)
            NavigationStack {
                List {
                    Section("Your accounts") {
                        ForEach(accounts) { account in
                            HStack {
                                Label(account.name, systemImage: "building.columns")
                                Spacer()
                                if let suffix = account.cardLastFour { Text("•••• " + suffix).font(.caption).foregroundStyle(.secondary) }
                            }
                        }
                        HStack {
                            TextField("Account name", text: $accountName)
                            Button("Add") { addAccount() }.disabled(accountName.trimmingCharacters(in: .whitespaces).isEmpty)
                        }
                    }
                    Section { Text("These are local labels. No bank login or server account is needed.").foregroundStyle(.secondary) }
                }.navigationTitle("Accounts")
                    .toolbar { Button("Scan card", systemImage: "camera") { scanningCard = true } }
            }.tabItem { Label("Accounts", systemImage: "creditcard") }.tag(1)
            NavigationStack {
                List {
                    Section("Automatic purchase capture") {
                        if let latest = purchases.first(where: { $0.source == "wallet" }) {
                            Label("Wallet purchases received", systemImage: "checkmark.circle.fill").foregroundStyle(.green)
                            Text("Last received: \(latest.observedAt.formatted(date: .abbreviated, time: .shortened))").font(.caption)
                        } else {
                            Label("Waiting for the first Wallet purchase", systemImage: "clock")
                        }
                        Text("Once Apple’s Transaction automation is enabled, SpendTrace saves incoming purchases on this iPhone automatically.")
                        ShortcutsLink().shortcutsLinkStyle(.automaticOutline)
                        DisclosureGroup("One-time setup required by Apple") {
                            Text("In Shortcuts, create a Transaction automation, choose your cards, and select Run Immediately.")
                            Text("Add SpendTrace’s Record purchase action. Set Merchant to Transaction → Merchant, Exact amount to Transaction → Amount → Currency Amount, Wallet card name to Transaction → Card or Pass → Name, and Currency code to Transaction → Amount → Currency Code.")
                            Text("Leave Event ID blank. Make a purchase and return here to confirm it arrived. The app cannot enable or inspect your personal automations itself.")
                            Text("The preconfigured downloadable shortcut is not ready yet. Until it is, these fields need to be selected once in Apple’s editor.").font(.footnote).foregroundStyle(.secondary)
                        }
                    }
                    Section("Cards and accounts") {
                        if links.isEmpty { Text("Card names appear after purchases arrive.").foregroundStyle(.secondary) }
                        ForEach(links) { link in
                            HStack {
                                Label(link.cardName, systemImage: "creditcard.fill")
                                Image(systemName: "arrow.right")
                                Text(accounts.first(where: { $0.id == link.accountID })?.name ?? "Account unavailable")
                            }.swipeActions { Button("Unlink", role: .destructive) { unlink(link) } }
                        }
                    }
                    Section("Privacy") {
                        Text("Wallet purchases stay in this app. Receipt reading defaults to on-device processing. Choosing OpenAI explicitly uploads the selected photo through your server; dashboard sync stays off.")
                        Text("The local database is excluded from device backups. Removing the app removes its data. Export and optional web sync are not available in this first build.").font(.footnote).foregroundStyle(.secondary)
                        Label("Web dashboard sync: not enabled", systemImage: "cloud.slash")
                    }
                }.navigationTitle("Wallet")
            }.tabItem { Label("Wallet", systemImage: "wallet.bifold") }.tag(2)
        }
        .sheet(isPresented: $scanningCard, onDismiss: { selectNext() }) { CardScanView() }
        .sheet(isPresented: $addingReceipt, onDismiss: { selectNext() }) {
            ReceiptScanView(cloud: receiptCloud, opensCameraOnAppear: true)
        }
        .sheet(item: $selected, onDismiss: { selectNext() }) { purchase in
            AssignCardView(purchase: purchase, accounts: accounts) {
                dismissed.insert(purchase.eventID); selected = nil
            }
        }
        .alert("Could not save", isPresented: Binding(get: { error != nil }, set: { if !$0 { error = nil } })) { Button("OK") { error = nil } } message: { Text(error ?? "") }
        .onChange(of: purchases.map(\.eventID), initial: true) { _, _ in selectNext() }
    }

    private func selectNext() {
        guard selected == nil, !addingReceipt, !scanningCard else { return }
        if let purchase = purchases.first(where: { $0.accountID == nil && !dismissed.contains($0.eventID) }) {
            dismissed.insert(purchase.eventID); selected = purchase
        }
    }
    private func addAccount() {
        let name = accountName.trimmingCharacters(in: .whitespacesAndNewlines)
        guard !name.isEmpty, name.count <= 100 else { error = "Use an account name under 100 characters."; return }
        context.insert(LocalAccount(name: name))
        do { try context.save(); accountName = "" } catch { context.rollback(); self.error = error.localizedDescription }
    }
    private func unlink(_ link: CardLink) {
        context.delete(link)
        do { try context.save() } catch { context.rollback(); self.error = error.localizedDescription }
    }
}

struct AddPurchaseView: View {
    @Environment(\.modelContext) private var context
    @Environment(\.dismiss) private var dismiss
    @State private var merchant = ""
    @State private var amount = ""
    @State private var currency = ""
    @State private var card = ""
    @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                TextField("Merchant", text: $merchant)
                TextField("Exact amount (12.34)", text: $amount).keyboardType(.decimalPad)
                TextField("Currency code, if known", text: $currency).textInputAutocapitalization(.characters)
                TextField("Card name", text: $card)
                if let error { Text(error).foregroundStyle(.red) }
                Button("Save on this iPhone") {
                    do {
                        let input = try PurchaseInput(merchant: merchant, amount: amount, currency: currency, cardName: card, source: "manual")
                        _ = try LocalDatabase.record(input, in: context); dismiss()
                    } catch { self.error = error.localizedDescription }
                }
            }.navigationTitle("Add purchase").toolbar { Button("Cancel") { dismiss() } }
        }
    }
}

struct AssignCardView: View {
    @Environment(\.modelContext) private var context
    let purchase: LocalPurchase
    let accounts: [LocalAccount]
    let finish: () -> Void
    @State private var accountID: UUID?
    @State private var remember = true
    @State private var uniqueName = false
    @State private var confirmed = false
    @State private var error: String?
    var body: some View {
        NavigationStack {
            Form {
                Section { Text(purchase.merchant).font(.headline); Text("\(purchase.currency) \(purchase.amountText)") }
                Section("Link this card") {
                    HStack(alignment: .top) {
                        VStack { Image(systemName: "creditcard.fill").font(.largeTitle); Text(purchase.cardName).font(.headline) }
                            .frame(maxWidth: .infinity).padding().background(.green.opacity(0.1), in: RoundedRectangle(cornerRadius: 12))
                        Image(systemName: "arrow.right").padding(.top, 25)
                        VStack(alignment: .leading) {
                            ForEach(accounts) { account in
                                Button { accountID = account.id } label: {
                                    HStack { Text(account.name); if accountID == account.id { Image(systemName: "checkmark.circle.fill") } }.padding(8)
                                }
                            }
                            if accounts.isEmpty { Text("Add an account in the Accounts tab first.") }
                        }.frame(maxWidth: .infinity)
                    }
                    Toggle("Remember for future purchases", isOn: $remember)
                    if remember { Toggle("Only one card uses this name", isOn: $uniqueName) }
                    Toggle("I checked this purchase and account", isOn: $confirmed)
                    if let error { Text(error).foregroundStyle(.red) }
                    Button(remember ? "Save card link" : "Assign this purchase") {
                        guard let account = accounts.first(where: { $0.id == accountID }) else { return }
                        do { try LocalDatabase.assign(purchase, to: account, remember: remember, uniqueName: uniqueName, in: context); finish() }
                        catch { self.error = error.localizedDescription }
                    }.disabled(accountID == nil || !confirmed || (remember && !uniqueName))
                    Text("Only this purchase and future matching purchases are affected. Other waiting purchases still need review.").font(.footnote).foregroundStyle(.secondary)
                }
            }.navigationTitle("Choose an account").toolbar { Button("Later", action: finish) }
        }
    }
}
