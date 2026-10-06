import SwiftUI
import UIKit

/// One transaction. A pending bank transaction asks «بابت چی بود؟» (typed, or
/// spoken through the server's local speech-to-text) and posts it to the books.
struct TransactionSheet: View {
    let tx: Transaction
    @Environment(FinanceStore.self) private var store
    @Environment(\.dismiss) private var dismiss
    @State private var description = ""
    @State private var party = ""
    @State private var busy = false
    @State private var error: String?
    @State private var dictation = Dictation()

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 18) {
                    VStack(spacing: 8) {
                        Image(systemName: tx.isIn ? "arrow.down.left.circle.fill" : "arrow.up.right.circle.fill")
                            .font(.system(size: 46)).foregroundStyle(tx.isIn ? Theme.income : Theme.expense)
                        Text(tx.isIn ? "واریز" : "برداشت").font(.fa(15)).foregroundStyle(Theme.muted)
                        AmountText(rial: tx.amount, size: 32, color: Theme.ink)
                        Text(Fa.digits(tx.bank_date + "  " + tx.bank_time) + (tx.wallet.isEmpty ? "" : " · " + tx.wallet))
                            .font(.fa(13)).foregroundStyle(Theme.muted)
                    }
                    .frame(maxWidth: .infinity)

                    if let sms = tx.sms_text, !sms.isEmpty {
                        SectionCard {
                            Label("پیامک بانک", systemImage: "message.fill").font(.fa(13, .semibold)).foregroundStyle(Theme.muted)
                            Text(Fa.digits(sms)).font(.fa(14)).foregroundStyle(Theme.ink)
                        }
                    }

                    if tx.isPending {
                        SectionCard {
                            Text("بابت چی بود؟").font(.fa(17, .bold)).foregroundStyle(Theme.ink)
                            HStack(spacing: 10) {
                                TextField("مثلاً فروش نقدی، اجاره، خرید جنس", text: $description, axis: .vertical)
                                    .font(.fa(16)).padding(14)
                                    .background(RoundedRectangle(cornerRadius: 16).fill(Theme.paper))
                                Button { Task { await dictate() } } label: {
                                    Image(systemName: dictation.recording ? "stop.fill" : "mic.fill")
                                        .font(.system(size: 18, weight: .bold)).foregroundStyle(dictation.recording ? .white : Theme.ink)
                                        .frame(width: 50, height: 50)
                                        .background(Circle().fill(dictation.recording ? Theme.expense : Theme.lime))
                                }
                                .disabled(dictation.busy)
                                .accessibilityLabel("گفتن با صدا")
                            }
                            TextField("طرف حساب (اختیاری)", text: $party)
                                .font(.fa(16)).padding(14)
                                .background(RoundedRectangle(cornerRadius: 16).fill(Theme.paper))
                            if let error { Text(error).font(.fa(13)).foregroundStyle(Theme.expense) }
                            ArrowPillButton(title: "ثبت در حسابداری", busy: busy) { Task { await confirm() } }
                                .disabled(busy || description.trimmingCharacters(in: .whitespaces).isEmpty)
                            Button("مربوط به حساب‌ها نیست؛ نادیده بگیر", role: .destructive) { Task { await ignore() } }
                                .font(.fa(13)).frame(maxWidth: .infinity)
                        }
                    } else {
                        SectionCard {
                            detail("بابت", tx.description)
                            detail("طرف حساب", tx.party)
                            detail("دسته", tx.category)
                            detail("وضعیت", tx.status == "confirmed" ? "ثبت شده در دفاتر" : tx.status)
                        }
                    }
                }
                .padding(20)
            }
            .background(Theme.paper.opacity(0.5))
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("بستن") { dismiss() } } }
        }
        .onAppear { party = tx.party }
    }

    @ViewBuilder private func detail(_ k: String, _ v: String) -> some View {
        if !v.isEmpty {
            HStack { Text(k).foregroundStyle(Theme.muted); Spacer(); Text(v).foregroundStyle(Theme.ink) }.font(.fa(15))
        }
    }

    private func dictate() async {
        if let text = await dictation.toggle(), !text.isEmpty {
            description = text
        }
        if let e = dictation.error { error = e }
    }

    private func confirm() async {
        busy = true; error = nil
        defer { busy = false }
        do {
            try await store.confirm(tx, description: description.trimmingCharacters(in: .whitespaces), party: party.trimmingCharacters(in: .whitespaces))
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            dismiss()
        } catch { self.error = error.localizedDescription }
    }

    private func ignore() async {
        busy = true
        defer { busy = false }
        do { try await store.ignore(tx); dismiss() } catch { self.error = error.localizedDescription }
    }
}

/// «ثبت دستی»: cash or other money not seen by the bank SMS.
struct ManualEntrySheet: View {
    let draft: ManualDraft
    @Environment(FinanceStore.self) private var store
    @Environment(\.dismiss) private var dismiss
    @State private var incoming = false
    @State private var amount = ""
    @State private var description = ""
    @State private var party = ""
    @State private var busy = false
    @State private var error: String?

    var body: some View {
        NavigationStack {
            ScrollView {
                VStack(alignment: .leading, spacing: 16) {
                    Picker("", selection: $incoming) {
                        Text("برداشت / پرداخت").tag(false)
                        Text("واریز / دریافت").tag(true)
                    }
                    .pickerStyle(.segmented)
                    VStack(alignment: .leading, spacing: 6) {
                        Text("مبلغ (تومان)").font(.fa(13, .semibold)).foregroundStyle(Theme.muted)
                        TextField("۰", text: $amount)
                            .keyboardType(.numberPad)
                            .font(.fa(34, .bold)).foregroundStyle(Theme.ink)
                        if let v = Int(Fa.ascii(amount)), v > 0 {
                            Text(Fa.number(v) + " تومان").font(.fa(13)).foregroundStyle(Theme.muted)
                        }
                    }
                    .padding(16)
                    .background(RoundedRectangle(cornerRadius: 20).fill(.white))
                    TextField("بابت چی بود؟", text: $description)
                        .font(.fa(16)).padding(14).background(RoundedRectangle(cornerRadius: 16).fill(.white))
                    TextField("طرف حساب (اختیاری)", text: $party)
                        .font(.fa(16)).padding(14).background(RoundedRectangle(cornerRadius: 16).fill(.white))
                    if let error { Text(error).font(.fa(13)).foregroundStyle(Theme.expense) }
                    ArrowPillButton(title: "ثبت", busy: busy) { Task { await save() } }
                        .disabled(busy || (Int(Fa.ascii(amount)) ?? 0) <= 0 || description.trimmingCharacters(in: .whitespaces).isEmpty)
                }
                .padding(20)
            }
            .background(Theme.paper)
            .navigationTitle("ثبت دستی").navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("بستن") { dismiss() } } }
        }
        .onAppear { incoming = draft.incoming; party = draft.party }
    }

    private func save() async {
        busy = true; error = nil
        defer { busy = false }
        do {
            try await store.manual(incoming: incoming, amountToman: Fa.ascii(amount), description: description.trimmingCharacters(in: .whitespaces),
                                   party: party.trimmingCharacters(in: .whitespaces))
            UINotificationFeedbackGenerator().notificationOccurred(.success)
            dismiss()
        } catch { self.error = error.localizedDescription }
    }
}

/// All deposits / withdrawals of a period, with search.
struct TransactionListView: View {
    let filter: ListFilter
    @Environment(\.dismiss) private var dismiss
    @Environment(FinanceStore.self) private var store
    @State private var items: [Transaction] = []
    @State private var totals = (incoming: 0, outgoing: 0)
    @State private var query = ""
    @State private var loading = true
    @State private var error: String?
    @State private var selected: Transaction?

    var body: some View {
        NavigationStack {
            List {
                Section {
                    HStack(spacing: 12) {
                        total("واریز", totals.incoming, Theme.income)
                        total("برداشت", totals.outgoing, Theme.expense)
                    }
                    .listRowBackground(Color.clear)
                    .listRowInsets(EdgeInsets())
                }
                if let error { Text(error).foregroundStyle(Theme.expense) }
                ForEach(shown) { tx in
                    Button { selected = tx } label: { TransactionRow(tx: tx, hidden: store.hideBalance) }
                        .buttonStyle(.plain)
                }
            }
            .overlay {
                if loading { ProgressView() } else if shown.isEmpty && error == nil {
                    ContentUnavailableView("تراکنشی نیست", systemImage: "tray")
                }
            }
            .searchable(text: $query, prompt: "جستجو در شرح، طرف حساب، مبلغ")
            .navigationTitle(filter.title ?? (filter.direction == "in" ? "واریزها" : filter.direction == "out" ? "برداشت‌ها" : "تراکنش‌ها"))
            .navigationBarTitleDisplayMode(.inline)
            .toolbar { ToolbarItem(placement: .cancellationAction) { Button("بستن") { dismiss() } } }
            .refreshable { await load() }
            .task { await load() }
            .sheet(item: $selected, onDismiss: { Task { await load() } }) { tx in
                TransactionSheet(tx: tx).presentationDetents([.medium, .large])
            }
        }
    }

    private var shown: [Transaction] {
        let q = query.trimmingCharacters(in: .whitespaces)
        guard !q.isEmpty else { return items }
        let digits = Fa.ascii(q)
        return items.filter { t in
            t.description.contains(q) || t.party.contains(q) || t.category.contains(q)
                || (!digits.isEmpty && String(t.amount / 10).contains(digits))
        }
    }

    private func total(_ title: String, _ rial: Int, _ color: Color) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title).font(.fa(12)).foregroundStyle(Theme.muted)
            Text(store.hideBalance ? "•••" : Fa.toman(rial)).font(.fa(17, .bold)).foregroundStyle(color)
                .lineLimit(1).minimumScaleFactor(0.6)
        }
        .padding(14)
        .frame(maxWidth: .infinity, alignment: .leading)
        .background(RoundedRectangle(cornerRadius: 18).fill(color.opacity(0.08)))
    }

    private func load() async {
        do {
            let r = try await APIClient.shared.transactions(from: filter.from ?? Self.daysAgo(90), to: filter.to, direction: filter.direction)
            items = r.items
            totals = (r.total_in, r.total_out)
            error = nil
        } catch { self.error = error.localizedDescription }
        loading = false
    }

    private static func daysAgo(_ n: Int) -> String {
        let f = DateFormatter()
        f.calendar = Calendar(identifier: .gregorian)
        f.locale = Locale(identifier: "en_US_POSIX")
        f.dateFormat = "yyyy-MM-dd"
        return f.string(from: Calendar.current.date(byAdding: .day, value: -n, to: .now) ?? .now)
    }
}
