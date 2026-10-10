import AVFoundation
import SwiftUI
import UIKit

// MARK: - bank cards like Apple Wallet: the stack on Home, one panel per card
// (its transactions, its own «رمز پویا», its settings), and the orb asks there.

/// What the app knows about each bank the ESP32 reads (server/lib.php BA_BANKS).
enum BankStyle {
    struct Option: Hashable { let code: String; let fa: String }
    static let all: [Option] = [Option(code: "mellat", fa: "بانک ملت"), Option(code: "melli", fa: "بانک ملی"), Option(code: "saderat", fa: "بانک صادرات"),
                                Option(code: "blu", fa: "بلو بانک"), Option(code: "cash", fa: "صندوق (نقد)")]

    static func english(_ bank: String?) -> String {
        switch bank {
        case "mellat": return "Bank Mellat"
        case "melli": return "Bank Melli Iran"
        case "saderat": return "Bank Saderat Iran"
        case "blu": return "blu"
        case "cash": return "Cash"
        default: return ""
        }
    }

    static func logo(_ w: Wallet) -> String {
        switch w.bank {
        case "mellat": return "ملت"
        case "melli": return "ملی"
        case "saderat": return "صادرات"
        case "blu": return "blu"
        case "cash": return "₮"
        default: return String(w.name.prefix(1))
        }
    }

    static func defaultColor(_ bank: String) -> String {
        switch bank {
        case "mellat": return "#c8102e"
        case "melli": return "#0b3a74"
        case "saderat": return "#0e5e8c"
        case "blu": return "#1688f0"
        default: return "#5b6b64"
        }
    }
}

/// "#rrggbb" → colour, optionally lighter (pct > 0) or darker (pct < 0).
func hexColor(_ hex: String?, shade pct: Double = 0) -> Color {
    var s = (hex ?? "").trimmingCharacters(in: .whitespaces)
    if s.hasPrefix("#") { s.removeFirst() }
    guard s.count == 6, let n = UInt32(s, radix: 16) else { return Color(red: 0.17, green: 0.24, blue: 0.31) }
    func f(_ c: UInt32) -> Double {
        let v = Double(c) / 255
        return pct < 0 ? v * (1 + pct) : v + (1 - v) * pct
    }
    return Color(red: f(n >> 16 & 255), green: f(n >> 8 & 255), blue: f(n & 255))
}

/// One card of the stack (or the big one on top of its panel).
struct BankCardView: View {
    let wallet: Wallet
    var big = false
    var hidden = false

    var body: some View {
        let c = wallet.color ?? BankStyle.defaultColor(wallet.bank ?? "")
        VStack(alignment: .leading, spacing: 0) {
            HStack(spacing: 10) {
                Text(BankStyle.logo(wallet))
                    .font(.system(size: wallet.bank == "blu" ? 15 : 13, weight: .heavy, design: wallet.bank == "blu" ? .default : .rounded))
                    .padding(.horizontal, 7).frame(minWidth: 36, minHeight: 26)
                    .background(RoundedRectangle(cornerRadius: 7).fill(.white.opacity(0.18)))
                VStack(alignment: .leading, spacing: 0) {
                    Text(wallet.name).font(.fa(15, .bold)).lineLimit(1)
                    Text(BankStyle.english(wallet.bank)).font(.system(size: 11)).opacity(0.75)
                }
                Spacer()
                if let p = wallet.pending, p > 0 {
                    Text("\(Fa.number(p)) بی‌جواب").font(.fa(12, .bold)).foregroundStyle(Color(red: 0.23, green: 0.16, blue: 0))
                        .padding(.horizontal, 8).padding(.vertical, 3)
                        .background(Capsule().fill(Color(red: 1, green: 0.8, blue: 0.2)))
                }
                if let o = wallet.otps, o > 0 {
                    Text("🔐").font(.system(size: 12)).padding(.horizontal, 7).padding(.vertical, 3)
                        .background(Capsule().fill(.white.opacity(0.22)))
                }
            }
            Spacer(minLength: 8)
            HStack(alignment: .firstTextBaseline, spacing: 6) {
                Text(hidden ? "••••••" : Fa.toman(wallet.balance)).font(.fa(big ? 30 : 27, .heavy))
                Text("تومان").font(.fa(13, .semibold)).opacity(0.8)
            }
            HStack {
                Text(wallet.lastFour.map { "•••• " + $0 } ?? (wallet.isCash ? "نقد" : "شماره‌ی کارت را در تنظیمات کارت بزن"))
                    .font(.system(size: 13, design: .monospaced)).environment(\.layoutDirection, .leftToRight)
                Spacer()
                if let b = wallet.bank_balance, !wallet.isCash {
                    Text("مانده‌ی بانک " + (hidden ? "•••" : Fa.toman(b))).font(.fa(12))
                }
            }
            .opacity(0.9)
            .padding(.top, 8)
        }
        .foregroundStyle(.white)
        .padding(.horizontal, 18).padding(.vertical, 16)
        .frame(height: big ? 220 : 214)
        .background {
            ZStack {
                if wallet.isCash {
                    LinearGradient(colors: [Color(white: 0.17), Color(white: 0.07)], startPoint: .topLeading, endPoint: .bottomTrailing)
                } else {
                    LinearGradient(colors: [hexColor(c), hexColor(c, shade: -0.45)], startPoint: .topLeading, endPoint: .bottomTrailing)
                    RadialGradient(colors: [hexColor(c, shade: 0.28).opacity(0.9), .clear], center: .topTrailing, startRadius: 0, endRadius: 260)
                }
                LinearGradient(colors: [.white.opacity(0.10), .clear], startPoint: .top, endPoint: .center)
            }
        }
        .clipShape(RoundedRectangle(cornerRadius: 18, style: .continuous))
        .shadow(color: .black.opacity(0.28), radius: 11, y: 8)
    }
}

/// The cards on top of each other, each showing its top strip; tap one to open its panel.
struct WalletStack: View {
    let wallets: [Wallet]
    var hidden = false
    var onTap: (Wallet) -> Void
    var onAdd: () -> Void
    private let step: CGFloat = 58

    var body: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Text("کارت‌ها").font(.fa(18, .bold)).foregroundStyle(Theme.ink)
                Spacer()
                Button("+ کارت", action: onAdd).font(.fa(14, .bold)).foregroundStyle(Theme.income)
            }
            ZStack(alignment: .top) {
                ForEach(Array(wallets.enumerated()), id: \.element.id) { i, w in
                    Button { onTap(w) } label: { BankCardView(wallet: w, hidden: hidden) }
                        .buttonStyle(CardPress())
                        .offset(y: CGFloat(i) * step)
                        .zIndex(Double(i))
                        .accessibilityLabel("\(w.name)، موجودی \(Fa.toman(w.balance)) تومان")
                }
            }
            .frame(height: CGFloat(max(0, wallets.count - 1)) * step + 214, alignment: .top)
        }
    }
}

private struct CardPress: ButtonStyle {
    func makeBody(configuration: Configuration) -> some View {
        configuration.label.offset(y: configuration.isPressed ? -6 : 0)
            .animation(.spring(response: 0.25), value: configuration.isPressed)
    }
}

enum CardTab: String, Hashable { case tx, otp, settings }

/// Which card panel to open: from the stack, a notification (ask = that
/// transaction, the orb asks about it there) or a one-time code (tab .otp).
struct CardRoute: Identifiable, Hashable {
    let walletID: Int
    var tab: CardTab = .tx
    var ask: Transaction?
    var id: String { "\(walletID)|\(tab.rawValue)|\(ask?.id ?? 0)" }
}

/// The panel of one card: the big card, its sums, and three sections.
struct CardPanel: View {
    let route: CardRoute
    @Environment(FinanceStore.self) private var store
    @Environment(\.dismiss) private var dismiss
    @State private var tab: CardTab = .tx
    @State private var items: [Transaction] = []
    @State private var filter = ""
    @State private var asking: Transaction?
    @State private var selected: Transaction?
    @State private var loadError: String?

    private var wallet: Wallet? { store.home?.wallets.first { $0.id == route.walletID } }
    private var pending: [Transaction] { (store.home?.pending ?? []).filter { $0.wallet_id == route.walletID } }

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(spacing: 14) {
                    if let w = wallet {
                        BankCardView(wallet: w, big: true, hidden: store.hideBalance)
                        sums
                        Picker("", selection: $tab) {
                            Text(pending.isEmpty ? "تراکنش‌ها" : "تراکنش‌ها (\(Fa.number(pending.count)))").tag(CardTab.tx)
                            Text("🔐 رمز پویا").tag(CardTab.otp)
                            Text("تنظیمات کارت").tag(CardTab.settings)
                        }
                        .pickerStyle(.segmented)
                        switch tab {
                        case .tx: transactions
                        case .otp: CardOTPView(wallet: w)
                        case .settings: CardSettingsView(wallet: w) { Task { await store.refresh() } }
                        }
                    } else {
                        ProgressView().padding(.vertical, 60)
                    }
                }
                .padding(.horizontal, 18)
                .padding(.top, 6)
                .padding(.bottom, asking == nil ? 20 : 300)
            }
            .background(Theme.paper.ignoresSafeArea())
            .navigationTitle(wallet?.name ?? "کارت")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("کارت‌ها") { dismiss() } } }
            .refreshable { await store.refresh(); await load() }
            .overlay(alignment: .bottom) {
                if let tx = asking {
                    CardAskPanel(tx: tx, cardName: wallet?.name ?? tx.wallet, autoStart: route.ask?.id == tx.id) {
                        withAnimation(.spring(response: 0.35)) { asking = nil }
                        Task { await store.refresh(); await load() }
                    }
                    .transition(.move(edge: .bottom).combined(with: .opacity))
                }
            }
            .sheet(item: $selected) { tx in TransactionSheet(tx: tx).presentationDetents([.medium, .large]) }
        }
        .task {
            tab = route.tab
            if let tx = route.ask, tx.isPending { asking = tx }
            await load()
        }
    }

    private var sums: some View {
        let inSum = items.filter(\.isIn).reduce(0) { $0 + $1.amount }
        let outSum = items.filter { !$0.isIn }.reduce(0) { $0 + $1.amount }
        return HStack(spacing: 10) {
            sumBox("واریز ۱۲۰ روز", inSum, Theme.income)
            sumBox("برداشت ۱۲۰ روز", outSum, Theme.expense)
        }
    }

    private func sumBox(_ title: String, _ rial: Int, _ tint: Color) -> some View {
        VStack(alignment: .leading, spacing: 3) {
            Text(title).font(.fa(12)).foregroundStyle(Theme.muted)
            Text(store.hideBalance ? "•••" : Fa.toman(rial) + " تومان").font(.fa(16, .bold)).foregroundStyle(tint)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(RoundedRectangle(cornerRadius: 16, style: .continuous).fill(tint.opacity(0.08)))
    }

    @ViewBuilder private var transactions: some View {
        ForEach(pending) { tx in
            HStack {
                VStack(alignment: .leading, spacing: 4) {
                    Text("بابت چی بود؟").font(.fa(16, .bold)).foregroundStyle(Theme.ink)
                    Text((tx.isIn ? "واریز " : "برداشت ") + Fa.toman(tx.amount) + " تومان · " + Fa.digits(tx.bank_date + " " + tx.bank_time))
                        .font(.fa(13)).foregroundStyle(Theme.ink.opacity(0.75))
                }
                Spacer()
                Button {
                    withAnimation(.spring(response: 0.35)) { asking = tx }
                } label: {
                    Label("بگو", systemImage: "mic.fill").font(.fa(13, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 14).padding(.vertical, 8).background(Capsule().fill(Theme.ink))
                }
                .buttonStyle(.plain)
            }
            .padding(14)
            .background(RoundedRectangle(cornerRadius: 20, style: .continuous).fill(Theme.limeGradient))
        }
        SectionCard {
            HStack(spacing: 8) {
                chip("همه", "")
                chip("واریز", "in")
                chip("برداشت", "out")
            }
            if let loadError { Text(loadError).font(.fa(13)).foregroundStyle(Theme.expense) }
            let shown = items.filter { filter.isEmpty || $0.direction == filter }
            if shown.isEmpty {
                Text("تراکنشی برای این کارت نیست.").font(.fa(14)).foregroundStyle(Theme.muted).padding(.vertical, 8)
            }
            ForEach(shown) { tx in
                Button { selected = tx } label: { TransactionRow(tx: tx, hidden: store.hideBalance) }.buttonStyle(.plain)
                if tx.id != shown.last?.id { Divider().opacity(0.5) }
            }
        }
    }

    private func chip(_ title: String, _ value: String) -> some View {
        Button { filter = value } label: {
            Text(title).font(.fa(13, filter == value ? .bold : .regular))
                .foregroundStyle(filter == value ? .white : Theme.ink)
                .padding(.horizontal, 14).frame(height: 32)
                .background(Capsule().fill(filter == value ? Theme.income : Theme.paper))
        }
        .buttonStyle(.plain)
    }

    private func load() async {
        if Demo.enabled {
            let d = Demo.home
            items = (d.pending + d.recent).filter { $0.wallet_id == route.walletID }
            return
        }
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        let from = f.string(from: Date().addingTimeInterval(-120 * 86_400))
        do {
            items = try await APIClient.shared.transactions(from: from, to: f.string(from: Date()), walletID: route.walletID).items
            loadError = nil
        } catch { loadError = error.localizedDescription }
    }
}

/// The orb in the bottom third of the card's panel: says the question, listens,
/// and books the answer on this card.
struct CardAskPanel: View {
    let tx: Transaction
    let cardName: String
    var autoStart = false
    var onDone: () -> Void
    @Environment(FinanceStore.self) private var store
    @State private var dictation = Dictation()
    @State private var text = ""
    @State private var party = ""
    @State private var busy = false
    @State private var speaking = false
    @State private var error: String?
    @State private var player = SpeechPlayer()

    private var question: String {
        (cardName.isEmpty ? "" : cardName + "، ") + (tx.isIn ? "واریز " : "برداشت ") + Fa.toman(tx.amount) + " تومان. بابت چی بود؟"
    }
    private var phase: AssistantPhase {
        if dictation.recording { return .listening }
        if busy || dictation.busy { return .processing }
        if speaking { return .speaking }
        return .idle
    }

    var body: some View {
        VStack(spacing: 10) {
            Capsule().fill(Theme.ink.opacity(0.2)).frame(width: 40, height: 5).padding(.top, 8)
            HStack {
                Spacer()
                Button { player.stop(); onDone() } label: {
                    Image(systemName: "xmark").font(.system(size: 14, weight: .bold)).foregroundStyle(Theme.ink)
                        .frame(width: 32, height: 32).background(Circle().fill(Theme.paper))
                }
                .accessibilityLabel("بستن")
            }
            .padding(.horizontal, 14).padding(.top, -28)
            Button { Task { await listen() } } label: {
                VoiceOrbView(phase: phase, level: dictation.recording ? 0.6 : (speaking ? 0.4 : 0.12))
                    .frame(width: 130, height: 130)
            }
            .buttonStyle(.plain)
            .accessibilityLabel("بگو بابت چی بود")
            Text(question).font(.fa(16, .bold)).foregroundStyle(Theme.ink).multilineTextAlignment(.center)
            Text(dictation.recording ? "دارم گوش می‌دهم…" : dictation.busy ? "در حال نوشتن…" : "روی دستیار بزن و بگو، یا بنویس")
                .font(.fa(12)).foregroundStyle(Theme.muted)
            HStack(spacing: 8) {
                TextField("مثلاً فروش نقدی به علی رضایی", text: $text)
                    .font(.fa(15)).padding(.horizontal, 12).frame(height: 44)
                    .background(RoundedRectangle(cornerRadius: 14).fill(Theme.paper))
                Button { Task { await save() } } label: {
                    Text("ثبت").font(.fa(14, .bold)).foregroundStyle(.white)
                        .padding(.horizontal, 18).frame(height: 44)
                        .background(Capsule().fill(text.trimmingCharacters(in: .whitespaces).isEmpty ? Theme.muted : Theme.ink))
                }
                .disabled(busy || text.trimmingCharacters(in: .whitespaces).isEmpty)
            }
            if let error { Text(error).font(.fa(12)).foregroundStyle(Theme.expense) }
        }
        .padding(.horizontal, 16).padding(.bottom, 14)
        .frame(maxWidth: .infinity)
        .background(
            UnevenRoundedRectangle(topLeadingRadius: 30, topTrailingRadius: 30, style: .continuous)
                .fill(.ultraThinMaterial)
                .shadow(color: .black.opacity(0.12), radius: 16, y: -4)
                .ignoresSafeArea(edges: .bottom)
        )
        .task {
            guard autoStart, !Demo.enabled else { return }
            await say()
            await listen()
        }
        .onDisappear { player.stop() }
    }

    /// The question in the server's Persian voice (when it has one).
    private func say() async {
        guard let data = try? await APIClient.shared.speech(for: question) else { return }
        try? AVAudioSession.sharedInstance().setCategory(.playback)
        try? AVAudioSession.sharedInstance().setActive(true)
        speaking = true
        await player.play(data)
        speaking = false
    }

    private func listen() async {
        error = nil
        if let heard = await dictation.toggle(), !heard.isEmpty {
            text = heard
            await save()
        } else if let e = dictation.error {
            error = e
        }
    }

    private func save() async {
        let d = text.trimmingCharacters(in: .whitespaces)
        guard !d.isEmpty else { return }
        busy = true
        defer { busy = false }
        do {
            try await store.confirm(tx, description: d, party: party)
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            onDone()
        } catch { self.error = error.localizedDescription }
    }
}

/// «رمز پویا» of one card: the PIN, then only this card's codes, refreshed every 2 s.
struct CardOTPView: View {
    let wallet: Wallet
    @State private var pin = ""
    @State private var unlockedPin: String?
    @State private var codes: [OneTimeCode] = []
    @State private var error: String?
    @State private var lockAt = Date.distantPast

    var body: some View {
        SectionCard {
            if let p = unlockedPin, Date() < lockAt {
                if codes.isEmpty {
                    Text("⏳ منتظر رمز «\(wallet.name)»… خرید را شروع کن؛ به‌محض رسیدن پیامک اینجا ظاهر می‌شود.")
                        .font(.fa(14)).foregroundStyle(Theme.ink)
                }
                ForEach(codes) { o in codeCard(o) }
                if let error { Text(error).font(.fa(12)).foregroundStyle(Theme.expense) }
                Button("قفل کن") { unlockedPin = nil; codes = [] }.font(.fa(14, .semibold))
                    .task(id: p) { await poll(p) }
            } else {
                Text("رمزهای پویای «\(wallet.name)» رمزشده روی سرور می‌مانند و فقط چند دقیقه اینجا دیده می‌شوند. رمز کارت‌های دیگر در پنل خودشان است.")
                    .font(.fa(13)).foregroundStyle(Theme.muted)
                SecureField("PIN رمزها", text: $pin)
                    .keyboardType(.numberPad).font(.fa(18))
                    .padding(.horizontal, 12).frame(height: 46)
                    .background(RoundedRectangle(cornerRadius: 14).fill(Theme.paper))
                if let error { Text(error).font(.fa(12)).foregroundStyle(Theme.expense) }
                Button {
                    unlockedPin = Fa.ascii(pin); pin = ""; error = nil
                    lockAt = Date().addingTimeInterval(180)
                } label: {
                    Text("باز کن").font(.fa(15, .bold)).foregroundStyle(.white)
                        .frame(maxWidth: .infinity).frame(height: 46)
                        .background(Capsule().fill(Theme.ink))
                }
                .disabled(pin.count < 4)
            }
        }
        .onAppear {
            if Demo.enabled && Demo.sheet == "otp" { unlockedPin = "demo"; lockAt = Date().addingTimeInterval(180) }
        }
    }

    private func codeCard(_ o: OneTimeCode) -> some View {
        VStack(alignment: .leading, spacing: 8) {
            Text(Fa.digits(o.code)).font(.system(size: 34, weight: .heavy, design: .monospaced)).foregroundStyle(Theme.ink)
                .environment(\.layoutDirection, .leftToRight)
            if let a = o.amount { Text("مبلغ: " + Fa.toman(a) + " تومان").font(.fa(14)) }
            if let m = o.merchant, !m.isEmpty { Text("پذیرنده: " + m).font(.fa(14)) }
            Text("⚠️ اگر خریدی با این مبلغ و پذیرنده انجام نمی‌دهی، رمز را به کسی نده.").font(.fa(12)).foregroundStyle(Theme.muted)
            ProgressView(value: Double(min(180, o.seconds_left)), total: 180).tint(Theme.income)
            HStack {
                Text("\(Fa.number(o.seconds_left)) ثانیه اعتبار").font(.fa(12)).foregroundStyle(Theme.muted)
                Spacer()
                Button("کپی") {
                    UIPasteboard.general.string = o.code
                    lockAt = Date().addingTimeInterval(180)
                    UINotificationFeedbackGenerator().notificationOccurred(.success)
                }
                .font(.fa(14, .bold))
            }
        }
        .padding(14)
        .background(RoundedRectangle(cornerRadius: 18).fill(Theme.paper))
    }

    private func poll(_ p: String) async {
        while !Task.isCancelled, unlockedPin == p, Date() < lockAt {
            if Demo.enabled {
                codes = [OneTimeCode(id: 1, code: "482913", amount: 15_000_000, merchant: "فروشگاه نمونه", wallet_id: wallet.id, seconds_left: 96)]
            } else {
                do {
                    codes = try await APIClient.shared.otps(walletID: wallet.id, pin: p)
                    error = nil
                } catch {
                    self.error = error.localizedDescription
                    unlockedPin = nil
                    return
                }
            }
            try? await Task.sleep(for: .seconds(2))
        }
        if Date() >= lockAt { unlockedPin = nil; codes = [] }
    }
}

/// «تنظیمات کارت» (or a new card): bank, last digits, colour, opening balance.
struct CardSettingsView: View {
    let wallet: Wallet?
    var onSaved: () -> Void
    @Environment(\.dismiss) private var dismiss
    @State private var name = ""
    @State private var bank = "melli"
    @State private var card = ""
    @State private var color = Color.blue
    @State private var opening = ""
    @State private var busy = false
    @State private var message: String?

    var body: some View {
        SectionCard {
            field("نام کارت") { TextField("مثلاً کارت ملی شخصی", text: $name) }
            field("بانک (پیامک‌هایش به این کارت می‌آید)") {
                Picker("بانک", selection: $bank) {
                    ForEach(BankStyle.all, id: \.code) { b in Text(b.fa).tag(b.code) }
                }
                .pickerStyle(.menu)
            }
            field("چهار رقم آخر کارت یا حساب") { TextField("7788", text: $card).keyboardType(.numberPad) }
            Text("اگر از یک بانک چند کارت داری، پیامک با همین رقم‌ها به کارت درست می‌رود.").font(.fa(12)).foregroundStyle(Theme.muted)
            ColorPicker("رنگ کارت", selection: $color, supportsOpacity: false).font(.fa(14))
            field("مانده‌ی اول (تومان)") { TextField("۰", text: $opening).keyboardType(.numberPad) }
            if let message { Text(message).font(.fa(13)).foregroundStyle(Theme.muted) }
            Button { Task { await save() } } label: {
                Text(wallet == nil ? "افزودن کارت" : "ذخیره").font(.fa(15, .bold)).foregroundStyle(.white)
                    .frame(maxWidth: .infinity).frame(height: 46)
                    .background(Capsule().fill(Theme.ink))
            }
            .disabled(busy || name.trimmingCharacters(in: .whitespaces).isEmpty)
            if wallet != nil {
                Text("در حسابداری این کارت حساب جدای خودش را دارد (به همین نام، در خزانه‌داری).").font(.fa(12)).foregroundStyle(Theme.muted)
            }
        }
        .onAppear {
            guard let w = wallet else { color = hexColor(BankStyle.defaultColor(bank)); return }
            name = w.name
            bank = w.bank ?? (w.isCash ? "cash" : "mellat")
            card = w.card ?? ""
            color = hexColor(w.color ?? BankStyle.defaultColor(bank))
            opening = (w.opening ?? 0) > 0 ? String((w.opening ?? 0) / 10) : ""
        }
    }

    private func field<C: View>(_ title: String, @ViewBuilder _ content: () -> C) -> some View {
        VStack(alignment: .leading, spacing: 6) {
            Text(title).font(.fa(13, .semibold)).foregroundStyle(Theme.muted)
            content().font(.fa(16)).padding(.horizontal, 12).frame(minHeight: 44)
                .background(RoundedRectangle(cornerRadius: 14).fill(Theme.paper))
        }
    }

    private func hex(_ c: Color) -> String {
        var r: CGFloat = 0, g: CGFloat = 0, b: CGFloat = 0, a: CGFloat = 0
        UIColor(c).getRed(&r, green: &g, blue: &b, alpha: &a)
        return String(format: "#%02x%02x%02x", Int(max(0, min(1, r)) * 255), Int(max(0, min(1, g)) * 255), Int(max(0, min(1, b)) * 255))
    }

    private func save() async {
        busy = true
        defer { busy = false }
        if Demo.enabled { message = "نسخه‌ی نمایشی: ذخیره نمی‌شود."; return }
        do {
            _ = try await APIClient.shared.saveCard(id: wallet?.id, name: name.trimmingCharacters(in: .whitespaces), bank: bank,
                                                    card: Fa.ascii(card), color: hex(color), openingToman: Fa.ascii(opening))
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            message = "ذخیره شد"
            onSaved()
            if wallet == nil { dismiss() }
        } catch { message = error.localizedDescription }
    }
}

/// «+ کارت»
struct NewCardSheet: View {
    @Environment(FinanceStore.self) private var store
    @Environment(\.dismiss) private var dismiss

    var body: some View {
        NavigationStack {
            ScrollView {
                CardSettingsView(wallet: nil) { Task { await store.refresh() } }.padding(18)
            }
            .background(Theme.paper.ignoresSafeArea())
            .navigationTitle("کارت جدید")
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("بستن") { dismiss() } } }
        }
    }
}
