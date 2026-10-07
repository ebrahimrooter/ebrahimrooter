import SwiftUI

/// Screen 2 of the design: dark header with the total balance and four action
/// tiles, then the white sheet: pending bank transactions, people, recent.
struct HomeView: View {
    @Binding var tab: AppTab
    @Environment(FinanceStore.self) private var store
    @State private var selected: Transaction?
    @State private var manual: ManualDraft?
    @State private var list: ListFilter?

    var body: some View {
        ScrollView {
            VStack(spacing: 0) {
                header
                sheet
            }
        }
        .background(alignment: .top) {
            VStack(spacing: 0) {
                Theme.darkBackground.frame(height: 520)
                Theme.paper
            }
            .ignoresSafeArea()
        }
        .background(Theme.paper)
        .refreshable { await store.refresh() }
        .sheet(item: $selected) { tx in TransactionSheet(tx: tx).presentationDetents([.medium, .large]) }
        .sheet(item: $manual) { d in ManualEntrySheet(draft: d).presentationDetents([.medium, .large]) }
        .sheet(item: $list) { f in TransactionListView(filter: f) }
        .onChange(of: PushManager.shared.openTx) { _, id in
            guard let id else { return }
            PushManager.shared.openTx = nil
            Task {
                await store.refresh()
                let all = (store.home?.pending ?? []) + (store.home?.recent ?? [])
                selected = all.first { $0.id == id }
            }
        }
        .task {
            guard Demo.enabled else { return }
            try? await Task.sleep(for: .seconds(1))
            switch Demo.sheet {
            case "tx": selected = store.home?.pending.first
            case "manual": manual = ManualDraft(party: "علی رضایی")
            case "list": list = ListFilter(direction: "")
            default: break
            }
        }
    }

    // MARK: header

    private var header: some View {
        let h = store.home
        return VStack(alignment: .leading, spacing: 18) {
            HStack(spacing: 12) {
                Avatar(name: h?.company.isEmpty == false ? h!.company : "حسابداری", size: 50)
                VStack(alignment: .leading, spacing: 2) {
                    Text(Fa.greeting()).font(.fa(13)).foregroundStyle(.white.opacity(0.65))
                    Text(h?.company.isEmpty == false ? h!.company : (h?.device_name ?? "دستیار حسابداری"))
                        .font(.fa(18, .bold)).foregroundStyle(.white).lineLimit(1)
                }
                Spacer()
                RoundIconButton(systemName: "doc.text.magnifyingglass", dark: true) { list = ListFilter(direction: "") }
                    .overlay(alignment: .topTrailing) {
                        if let n = h?.pending.count, n > 0 {
                            Circle().fill(Theme.lime).frame(width: 10, height: 10).offset(x: -4, y: 4)
                        }
                    }
            }

            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Text("موجودی کل").font(.fa(15)).foregroundStyle(.white.opacity(0.75))
                    Spacer()
                    Button { withAnimation { store.hideBalance.toggle() } } label: {
                        Image(systemName: store.hideBalance ? "eye" : "eye.slash")
                            .font(.system(size: 16)).foregroundStyle(.white.opacity(0.8))
                            .frame(width: 40, height: 40)
                            .background(Circle().fill(.white.opacity(0.08)))
                    }
                    .accessibilityLabel(store.hideBalance ? "نمایش موجودی" : "پنهان کردن موجودی")
                }
                HStack(alignment: .center, spacing: 10) {
                    AmountText(rial: h?.balance ?? 0, size: 36, hidden: store.hideBalance)
                        .redacted(reason: h == nil ? .placeholder : [])
                    ChangePill(value: h?.month.in_change)
                }
                HStack {
                    statusPill
                    Spacer()
                    if let m = h?.month {
                        Text("این ماه · واریز \(store.hideBalance ? "•••" : Fa.short(m.incoming)) · برداشت \(store.hideBalance ? "•••" : Fa.short(m.outgoing))")
                            .font(.fa(12, .medium)).foregroundStyle(.white.opacity(0.75))
                            .padding(.horizontal, 12).padding(.vertical, 7)
                            .background(Capsule().fill(.white.opacity(0.07)))
                    }
                }
            }
            .background(alignment: .center) {
                Image(systemName: "dollarsign")
                    .font(.system(size: 170, weight: .black))
                    .foregroundStyle(Theme.lime.opacity(0.07))
                    .offset(x: -20)
                    .allowsHitTesting(false)
            }

            HStack(spacing: 10) {
                tile("arrow.down.left", "واریزها", tint: Theme.leaf) { list = ListFilter(direction: "in") }
                tile("arrow.up.right", "برداشت‌ها", tint: Color(red: 0.75, green: 0.6, blue: 0.25)) { list = ListFilter(direction: "out") }
                tile("plus", "ثبت دستی", tint: Color(red: 0.3, green: 0.6, blue: 0.7)) { manual = ManualDraft() }
                Button { tab = .accounting } label: {
                    VStack(spacing: 6) {
                        Image(systemName: "square.grid.2x2").font(.system(size: 22, weight: .medium)).foregroundStyle(Theme.ink)
                        Text("حسابداری").font(.fa(11, .semibold)).foregroundStyle(Theme.ink.opacity(0.7))
                    }
                    .frame(maxWidth: .infinity).frame(height: 76)
                    .background(RoundedRectangle(cornerRadius: 20, style: .continuous).fill(.white.opacity(0.94)))
                }
                .buttonStyle(.plain)
            }
        }
        .padding(.horizontal, 20)
        .padding(.top, 12)
        .padding(.bottom, 26)
    }

    private var statusPill: some View {
        let d = store.home?.sms_device
        return HStack(spacing: 6) {
            Text("دستگاه پیامک:").foregroundStyle(.white.opacity(0.6))
            Text(d == nil ? "وصل نیست" : (d!.online ? "فعال" : "قطع")).foregroundStyle(d?.online == true ? Theme.lime : .orange)
        }
        .font(.fa(12, .semibold))
        .padding(.horizontal, 12).padding(.vertical, 7)
        .background(Capsule().fill(.white.opacity(0.07)))
    }

    private func tile(_ icon: String, _ title: String, tint: Color, action: @escaping () -> Void) -> some View {
        Button(action: action) {
            VStack(spacing: 6) {
                Image(systemName: icon).font(.system(size: 20, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 34, height: 34)
                    .overlay(RoundedRectangle(cornerRadius: 9).strokeBorder(.white.opacity(0.85), lineWidth: 1.6))
                Text(title).font(.fa(11, .semibold)).foregroundStyle(.white.opacity(0.85))
            }
            .frame(maxWidth: .infinity).frame(height: 76)
            .background(RoundedRectangle(cornerRadius: 20, style: .continuous).fill(tint.opacity(0.22)))
            .overlay(RoundedRectangle(cornerRadius: 20, style: .continuous).strokeBorder(tint.opacity(0.65), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }

    // MARK: sheet

    private var sheet: some View {
        VStack(spacing: 14) {
            if let err = store.error, store.home == nil {
                SectionCard {
                    Label(err, systemImage: "wifi.exclamationmark").font(.fa(14)).foregroundStyle(Theme.expense)
                    Button("دوباره") { Task { await store.refresh() } }.font(.fa(14, .semibold))
                }
            }
            if let alert = store.home?.cheque_alert {
                chequeCard(alert)
            }
            pendingCard
            peopleCard
            recentCard
        }
        .padding(16)
        .padding(.top, 4)
        .background(
            UnevenRoundedRectangle(topLeadingRadius: 32, topTrailingRadius: 32, style: .continuous).fill(Theme.paper)
        )
    }

    @ViewBuilder private var pendingCard: some View {
        let pending = store.home?.pending ?? []
        ZStack(alignment: .leading) {
            RoundedRectangle(cornerRadius: 24, style: .continuous).fill(Theme.limeGradient)
            RoundedRectangle(cornerRadius: 24, style: .continuous).strokeBorder(Theme.leaf.opacity(0.6), lineWidth: 1.5)
            HStack(alignment: .center) {
                VStack(alignment: .leading, spacing: 8) {
                    Text(pending.isEmpty ? "همه‌چیز ثبت شده" : "بابت چی بود؟").font(.fa(17, .bold)).foregroundStyle(Theme.ink)
                    Text(pending.isEmpty ? "تراکنش بانکی بدون توضیح نداری." : "\(Fa.number(pending.count)) تراکنش بانک منتظر توضیح توست.")
                        .font(.fa(13)).foregroundStyle(Theme.ink.opacity(0.75))
                    if let first = pending.first {
                        HStack(spacing: 8) {
                            Text((first.isIn ? "واریز " : "برداشت ") + Fa.toman(first.amount))
                                .font(.fa(13, .semibold)).foregroundStyle(Theme.ink)
                                .padding(.horizontal, 12).padding(.vertical, 7)
                                .overlay(Capsule().strokeBorder(Theme.ink, lineWidth: 1))
                            Button("ثبت کن") { selected = first }
                                .font(.fa(13, .semibold)).foregroundStyle(.white)
                                .padding(.horizontal, 16).padding(.vertical, 8)
                                .background(Capsule().fill(Theme.ink))
                        }
                        .padding(.top, 2)
                    }
                }
                Spacer()
                ZStack {
                    Image(systemName: "banknote.fill").font(.system(size: 54)).foregroundStyle(Theme.forest)
                        .rotationEffect(.degrees(-18)).offset(x: 10, y: -10)
                    Image(systemName: "wallet.pass.fill").font(.system(size: 50)).foregroundStyle(.white)
                        .shadow(color: .black.opacity(0.15), radius: 4, y: 3)
                }
                .frame(width: 96)
            }
            .padding(16)
        }
        .frame(minHeight: 120)
    }

    private func chequeCard(_ text: String) -> some View {
        SectionCard {
            HStack(alignment: .top, spacing: 12) {
                Image(systemName: "calendar.badge.exclamationmark").font(.system(size: 24)).foregroundStyle(.orange)
                Text(text).font(.fa(13)).foregroundStyle(Theme.ink).lineLimit(6)
            }
        }
    }

    @ViewBuilder private var peopleCard: some View {
        let parties = store.home?.parties ?? []
        SectionCard {
            SectionHeader(title: "طرف‌حساب‌های پرتکرار", action: "همه", onAction: { tab = .accounting })
            ScrollView(.horizontal, showsIndicators: false) {
                HStack(spacing: 14) {
                    ForEach(parties.prefix(6)) { p in
                        Button { manual = ManualDraft(party: p.name) } label: {
                            VStack(spacing: 6) {
                                Avatar(name: p.name)
                                Text(p.name.split(separator: " ").first.map(String.init) ?? p.name)
                                    .font(.fa(12)).foregroundStyle(Theme.ink).lineLimit(1)
                            }
                            .frame(width: 60)
                        }
                        .buttonStyle(.plain)
                    }
                    Button { manual = ManualDraft() } label: {
                        VStack(spacing: 6) {
                            Image(systemName: "plus").font(.system(size: 22, weight: .bold)).foregroundStyle(.white)
                                .frame(width: 52, height: 52)
                                .background(Circle().fill(Theme.income))
                            Text("جدید").font(.fa(12)).foregroundStyle(Theme.ink)
                        }
                        .frame(width: 60)
                    }
                    .buttonStyle(.plain)
                }
            }
        }
    }

    @ViewBuilder private var recentCard: some View {
        let recent = store.home?.recent ?? []
        SectionCard {
            SectionHeader(title: "تراکنش‌های اخیر", action: "همه", onAction: { list = ListFilter(direction: "") })
            if recent.isEmpty {
                Text(store.home == nil ? "در حال بارگذاری…" : "هنوز تراکنشی نیست.").font(.fa(14)).foregroundStyle(Theme.muted)
            }
            ForEach(recent.prefix(8)) { tx in
                Button { selected = tx } label: { TransactionRow(tx: tx, hidden: store.hideBalance) }
                    .buttonStyle(.plain)
                if tx.id != recent.prefix(8).last?.id { Divider().opacity(0.5) }
            }
        }
    }
}

struct ListFilter: Identifiable, Hashable {
    var direction: String
    var from: String?
    var to: String?
    var title: String?
    var id: String { "\(direction)|\(from ?? "")|\(to ?? "")" }
}

struct ManualDraft: Identifiable, Hashable {
    var incoming = false
    var party = ""
    let id = UUID()
}
