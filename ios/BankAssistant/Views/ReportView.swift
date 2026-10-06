import SwiftUI
import UIKit

/// Screen 3 of the design: «گزارش», واریز/برداشت switch, the lime card with
/// the six-month bar chart, a dark card to the full books, the month's list.
struct ReportView: View {
    @Binding var tab: AppTab
    @Environment(FinanceStore.self) private var store
    @State private var incoming = true
    @State private var picked: String?
    @State private var list: ListFilter?
    @State private var items: [Transaction] = []
    @State private var selected: Transaction?

    private var months: [MonthSum] { store.home?.months ?? [] }
    private var current: MonthSum? { months.first { $0.id == picked } ?? months.last }

    var body: some View {
        ScrollView {
            VStack(spacing: 16) {
                HStack {
                    Text("گزارش").font(.fa(24, .bold)).foregroundStyle(Theme.ink)
                    Spacer()
                    RoundIconButton(systemName: "magnifyingglass") { list = ListFilter(direction: "") }
                    RoundIconButton(systemName: "calendar") {
                        if let m = current { list = ListFilter(direction: incoming ? "in" : "out", from: m.from, to: m.to, title: m.label) }
                    }
                }
                segmented
                chartCard
                booksCard
                monthList
            }
            .padding(.horizontal, 18)
            .padding(.top, 10)
        }
        .background(Theme.paper.ignoresSafeArea())
        .refreshable { await store.refresh(); await loadItems() }
        .task(id: "\(current?.id ?? "")|\(incoming)|\(store.home?.recent.first?.id ?? 0)") { await loadItems() }
        .sheet(item: $list) { f in TransactionListView(filter: f) }
        .sheet(item: $selected) { tx in TransactionSheet(tx: tx).presentationDetents([.medium, .large]) }
    }

    private var segmented: some View {
        HStack(spacing: 0) {
            seg("واریز", true)
            seg("برداشت", false)
        }
        .padding(4)
        .background(Capsule().fill(Color(white: 0.9)))
    }

    private func seg(_ title: String, _ value: Bool) -> some View {
        Button { withAnimation(.spring(response: 0.3)) { incoming = value } } label: {
            Text(title)
                .font(.fa(15, .semibold))
                .foregroundStyle(incoming == value ? .white : Theme.ink)
                .frame(maxWidth: .infinity).frame(height: 44)
                .background { if incoming == value { Capsule().fill(Theme.ink) } }
        }
        .buttonStyle(.plain)
    }

    private var chartCard: some View {
        let m = current
        let change = picked == nil ? (incoming ? store.home?.month.in_change : store.home?.month.out_change) : nil
        return VStack(alignment: .leading, spacing: 12) {
            HStack(alignment: .top) {
                VStack(alignment: .leading, spacing: 6) {
                    Text((incoming ? "جمع واریز " : "جمع برداشت ") + (m?.label ?? "")).font(.fa(15)).foregroundStyle(Theme.ink.opacity(0.7))
                    AmountText(rial: m?.value(incoming) ?? 0, size: 28, color: Theme.ink, hidden: store.hideBalance)
                }
                Spacer()
                ChangePill(value: change, dark: true)
            }
            BarChart(months: months, incoming: incoming, picked: $picked, hidden: store.hideBalance)
                .frame(height: 210)
        }
        .padding(16)
        .background(RoundedRectangle(cornerRadius: 26, style: .continuous).fill(Theme.limeGradient))
        .overlay(RoundedRectangle(cornerRadius: 26, style: .continuous).strokeBorder(Theme.leaf.opacity(0.6), lineWidth: 1.5))
        .redacted(reason: store.home == nil ? .placeholder : [])
    }

    private var booksCard: some View {
        Button { tab = .accounting } label: {
            HStack(spacing: 14) {
                ZStack {
                    RoundedRectangle(cornerRadius: 6).fill(Theme.lime).frame(width: 46, height: 32).rotationEffect(.degrees(-8))
                    RoundedRectangle(cornerRadius: 6).fill(Theme.mint).frame(width: 46, height: 32).offset(x: 6, y: 6)
                    Image(systemName: "doc.text.fill").font(.system(size: 14)).foregroundStyle(Theme.forest).offset(x: 6, y: 6)
                }
                .frame(width: 60)
                VStack(alignment: .leading, spacing: 3) {
                    Text("حسابداری کامل").font(.fa(16, .bold)).foregroundStyle(.white)
                    Text("فاکتور، انبار، چک، مالیات، گزارش‌ها").font(.fa(12)).foregroundStyle(.white.opacity(0.6))
                }
                Spacer()
                Image(systemName: "arrow.left")
                    .font(.system(size: 16, weight: .semibold)).foregroundStyle(.white)
                    .frame(width: 42, height: 42)
                    .overlay(Circle().strokeBorder(.white.opacity(0.5), lineWidth: 1))
            }
            .padding(14)
            .background(Theme.darkBackground.clipShape(RoundedRectangle(cornerRadius: 22, style: .continuous)))
        }
        .buttonStyle(.plain)
    }

    private var monthList: some View {
        SectionCard {
            SectionHeader(title: (incoming ? "واریزهای " : "برداشت‌های ") + (current?.label ?? ""), action: "همه") {
                if let m = current { list = ListFilter(direction: incoming ? "in" : "out", from: m.from, to: m.to, title: m.label) }
            }
            if items.isEmpty {
                Text("موردی نیست.").font(.fa(14)).foregroundStyle(Theme.muted)
            }
            ForEach(items.prefix(10)) { tx in
                Button { selected = tx } label: { TransactionRow(tx: tx, hidden: store.hideBalance) }.buttonStyle(.plain)
                if tx.id != items.prefix(10).last?.id { Divider().opacity(0.5) }
            }
        }
    }

    private func loadItems() async {
        guard let m = current else { return }
        if Demo.enabled { items = Demo.list(direction: incoming ? "in" : "out").items; return }
        if let r = try? await APIClient.shared.transactions(from: m.from, to: m.to, direction: incoming ? "in" : "out") {
            items = r.items
        }
    }
}

/// Six months: hatched lime bars, the chosen one solid black with a value bubble.
struct BarChart: View {
    let months: [MonthSum]
    let incoming: Bool
    @Binding var picked: String?
    var hidden = false

    var body: some View {
        let maxV = max(1, months.map { $0.value(incoming) }.max() ?? 1)
        let chosen = picked ?? months.last?.id
        GeometryReader { geo in
            let barH = geo.size.height - 28
            HStack(alignment: .bottom, spacing: 10) {
                ForEach(months) { m in
                    let v = m.value(incoming)
                    let h = max(26, CGFloat(v) / CGFloat(maxV) * (barH - 40))
                    VStack(spacing: 6) {
                        Spacer(minLength: 0)
                        ZStack(alignment: .top) {
                            if m.id == chosen {
                                Capsule().fill(Theme.ink).frame(height: h)
                            } else {
                                Capsule().fill(Theme.ink.opacity(0.05)).frame(height: h)
                                    .overlay(Hatch().stroke(Theme.ink.opacity(0.28), lineWidth: 1.2).clipShape(Capsule()))
                                    .overlay(Capsule().strokeBorder(Theme.ink.opacity(0.18), lineWidth: 1))
                            }
                        }
                        .overlay(alignment: .top) {
                            if m.id == chosen {
                                Text(hidden ? "•••" : short(v))
                                    .font(.fa(12, .bold)).foregroundStyle(.white)
                                    .fixedSize()
                                    .padding(.horizontal, 10).padding(.vertical, 6)
                                    .background(Capsule().fill(Theme.ink))
                                    .offset(y: -34)
                            }
                        }
                        Text(m.label).font(.fa(10, .medium)).foregroundStyle(Theme.ink.opacity(0.7))
                            .lineLimit(1).minimumScaleFactor(0.7)
                            .frame(height: 16)
                    }
                    .frame(maxWidth: .infinity)
                    .contentShape(Rectangle())
                    .onTapGesture {
                        withAnimation(.spring(response: 0.35, dampingFraction: 0.75)) { picked = m.id }
                        UISelectionFeedbackGenerator().selectionChanged()
                    }
                    .accessibilityElement(children: .ignore)
                    .accessibilityLabel("\(m.label): \(Fa.toman(v)) تومان")
                }
            }
            .frame(height: geo.size.height, alignment: .bottom)
        }
        .padding(.top, 30)
    }

    /// «۱٫۲ م» (million toman) / «۸۵۰ ه» (thousand toman).
    private func short(_ rial: Int) -> String {
        let t = Double(rial) / 10
        if t >= 1_000_000_000 { return Fa.digits(String(format: "%.1f", t / 1_000_000_000)) + " میلیارد" }
        if t >= 1_000_000 { return Fa.digits(String(format: "%.1f", t / 1_000_000)) + " م" }
        if t >= 1_000 { return Fa.number(Int(t / 1_000)) + " ه" }
        return Fa.number(Int(t))
    }
}

/// Diagonal lines (the striped bars of the design).
struct Hatch: Shape {
    func path(in rect: CGRect) -> Path {
        var p = Path()
        var x = -rect.height
        while x < rect.width {
            p.move(to: CGPoint(x: x, y: rect.height))
            p.addLine(to: CGPoint(x: x + rect.height, y: 0))
            x += 7
        }
        return p
    }
}
