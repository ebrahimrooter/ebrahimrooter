import SwiftUI

// MARK: - «کالا و انبار» (server/inventory.php via assistant_inventory)

struct StockWarehouse: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let count: Int
    let value: Double
}

struct StockPlace: Decodable, Hashable {
    let warehouse_id: Int
    let name: String
    let qty: Double
}

struct StockItem: Decodable, Identifiable, Hashable {
    let id: Int
    let code: String
    let name: String
    let unit: String
    let group: String
    let barcode: String
    let qty: Double
    let reorder_point: Double
    let sale_price: Double
    let cost: Double
    let value: Double
    /// ok | low | out
    let status: String
    let warehouses: [StockPlace]

    func qty(in warehouse: Int?) -> Double {
        guard let warehouse else { return qty }
        return warehouses.first { $0.warehouse_id == warehouse }?.qty ?? 0
    }
}

struct StockTotals: Decodable, Hashable {
    let count: Int
    let value: Double
    let low: Int?
    let out: Int?
}

struct Inventory: Decodable {
    let warehouses: [StockWarehouse]
    let items: [StockItem]
    let totals: StockTotals
}

extension Fa {
    /// 12.5 → «۱۲٫۵», 40 → «۴۰»
    static func qty(_ v: Double) -> String {
        if v == v.rounded() { return number(Int(v)) }
        return digits(String(format: "%.2f", v)).replacingOccurrences(of: ".", with: "٫")
            .replacingOccurrences(of: "۰$", with: "", options: .regularExpression)
    }
}

/// The «کالا و انبار» tab: totals, warehouse chips, search, and every product with its stock.
struct StockView: View {
    @State private var inv: Inventory?
    @State private var error: String?
    @State private var warehouse: Int?
    @State private var filter = "all"
    @State private var query = ""
    @Environment(FinanceStore.self) private var store
    private var hidden: Bool { store.hideBalance }

    private var items: [StockItem] {
        guard let inv else { return [] }
        let q = query.trimmingCharacters(in: .whitespaces).lowercased()
        return inv.items.filter { it in
            if let w = warehouse, !it.warehouses.contains(where: { $0.warehouse_id == w }) { return false }
            if filter != "all" && it.status != filter { return false }
            if !q.isEmpty && !"\(it.name) \(it.code) \(it.barcode) \(it.group)".lowercased().contains(q) { return false }
            return true
        }
    }

    var body: some View {
        ScrollView {
            VStack(alignment: .leading, spacing: 14) {
                Text("کالا و انبار").font(.fa(24, .bold)).foregroundStyle(Theme.ink)
                if let inv {
                    totals(inv.totals)
                    warehouseChips(inv.warehouses)
                    listCard
                    Text("موجودی با رسید و حواله‌ی انبار و فاکتورها در «حسابداری ← انبار» تغییر می‌کند.")
                        .font(.fa(12)).foregroundStyle(Theme.muted)
                } else if let error {
                    Text(error).font(.fa(15)).foregroundStyle(Theme.expense)
                        .frame(maxWidth: .infinity).padding(.vertical, 40)
                } else {
                    ProgressView().frame(maxWidth: .infinity).padding(.vertical, 60)
                }
            }
            .padding(.horizontal, 18)
            .padding(.top, 10)
        }
        .background(Theme.paper.ignoresSafeArea())
        .refreshable { await load() }
        .task { await load() }
    }

    private func load() async {
        if Demo.enabled { inv = Demo.inventory; return }
        do { inv = try await APIClient.shared.inventory(); error = nil } catch { self.error = error.localizedDescription }
    }

    private func totals(_ t: StockTotals) -> some View {
        LazyVGrid(columns: [GridItem(.flexible(), spacing: 10), GridItem(.flexible(), spacing: 10)], spacing: 10) {
            tile("کالاها", Fa.number(t.count), Theme.ink)
            tile("ارزش موجودی", hidden ? "••••" : Fa.toman(Int(t.value)) + " تومان", Theme.ink)
            tile("رو به اتمام", Fa.number(t.low ?? 0), (t.low ?? 0) > 0 ? .orange : Theme.ink)
            tile("تمام شده", Fa.number(t.out ?? 0), (t.out ?? 0) > 0 ? Theme.expense : Theme.ink)
        }
    }

    private func tile(_ title: String, _ value: String, _ color: Color) -> some View {
        VStack(alignment: .leading, spacing: 4) {
            Text(title).font(.fa(13)).foregroundStyle(Theme.muted)
            Text(value).font(.fa(18, .bold)).foregroundStyle(color).lineLimit(1).minimumScaleFactor(0.6)
        }
        .frame(maxWidth: .infinity, alignment: .leading)
        .padding(12)
        .background(RoundedRectangle(cornerRadius: 18, style: .continuous).fill(Theme.card))
    }

    private func warehouseChips(_ list: [StockWarehouse]) -> some View {
        ScrollView(.horizontal, showsIndicators: false) {
            HStack(spacing: 8) {
                chip("همه‌ی انبارها", on: warehouse == nil) { warehouse = nil }
                ForEach(list) { w in
                    chip("\(w.name) (\(Fa.number(w.count)))", on: warehouse == w.id) { warehouse = w.id }
                }
            }
        }
    }

    private func chip(_ title: String, on: Bool, _ action: @escaping () -> Void) -> some View {
        Button { withAnimation(.spring(response: 0.3)) { action() } } label: {
            Text(title).font(.fa(14, on ? .bold : .regular))
                .foregroundStyle(on ? .white : Theme.ink)
                .padding(.horizontal, 14).frame(height: 36)
                .background(Capsule().fill(on ? Theme.income : Theme.card))
                .overlay(Capsule().strokeBorder(Theme.ink.opacity(on ? 0 : 0.1)))
        }
        .buttonStyle(.plain)
    }

    private var listCard: some View {
        VStack(alignment: .leading, spacing: 10) {
            HStack {
                Image(systemName: "magnifyingglass").foregroundStyle(Theme.muted)
                TextField("جستجوی نام، کد یا بارکد", text: $query).font(.fa(15))
            }
            .padding(.horizontal, 12).frame(height: 44)
            .background(RoundedRectangle(cornerRadius: 14).fill(Theme.paper))
            HStack(spacing: 8) {
                chip("همه", on: filter == "all") { filter = "all" }
                chip("رو به اتمام", on: filter == "low") { filter = "low" }
                chip("تمام شده", on: filter == "out") { filter = "out" }
                Spacer()
                Text("\(Fa.number(items.count)) کالا").font(.fa(13)).foregroundStyle(Theme.muted)
            }
            if items.isEmpty {
                Text("کالایی با این شرط‌ها نیست.").font(.fa(14)).foregroundStyle(Theme.muted).padding(.vertical, 20)
            }
            ForEach(items) { it in
                row(it)
                if it.id != items.last?.id { Divider() }
            }
        }
        .padding(16)
        .background(RoundedRectangle(cornerRadius: 24, style: .continuous).fill(Theme.card))
    }

    private func row(_ it: StockItem) -> some View {
        let tint: Color = it.status == "out" ? Theme.expense : it.status == "low" ? .orange : Theme.income
        let q = it.qty(in: warehouse)
        return HStack(alignment: .center, spacing: 12) {
            Image(systemName: it.status == "out" ? "shippingbox" : "shippingbox.fill")
                .font(.system(size: 18, weight: .semibold))
                .foregroundStyle(tint)
                .frame(width: 44, height: 44)
                .background(RoundedRectangle(cornerRadius: 14).fill(tint.opacity(0.12)))
            VStack(alignment: .leading, spacing: 3) {
                HStack(spacing: 6) {
                    Text(it.name).font(.fa(15, .bold)).foregroundStyle(Theme.ink)
                    if it.status != "ok" {
                        Text(it.status == "out" ? "تمام شده" : "رو به اتمام").font(.fa(11, .bold))
                            .foregroundStyle(tint).padding(.horizontal, 7).padding(.vertical, 2)
                            .background(Capsule().fill(tint.opacity(0.14)))
                    }
                }
                Text([it.code.isEmpty ? nil : "کد " + Fa.digits(it.code), it.group.isEmpty ? nil : it.group,
                      it.reorder_point > 0 ? "نقطه‌ی سفارش " + Fa.qty(it.reorder_point) : nil].compactMap { $0 }.joined(separator: " · "))
                    .font(.fa(12)).foregroundStyle(Theme.muted)
                if warehouse == nil && !it.warehouses.isEmpty {
                    HStack(spacing: 6) {
                        ForEach(it.warehouses, id: \.warehouse_id) { w in
                            Text(it.warehouses.count > 1 ? "\(w.name): \(Fa.qty(w.qty))" : w.name)
                                .font(.fa(11)).foregroundStyle(Theme.ink.opacity(0.8))
                                .padding(.horizontal, 8).padding(.vertical, 2)
                                .background(Capsule().fill(Theme.ink.opacity(0.06)))
                        }
                    }
                }
            }
            Spacer(minLength: 4)
            VStack(alignment: .trailing, spacing: 1) {
                Text(Fa.qty(q)).font(.fa(20, .bold)).foregroundStyle(Theme.ink)
                Text(it.unit).font(.fa(11)).foregroundStyle(Theme.muted)
                if it.cost > 0 && !hidden {
                    Text(Fa.toman(Int(max(0, q) * it.cost))).font(.fa(11)).foregroundStyle(Theme.muted)
                }
            }
        }
        .padding(.vertical, 4)
    }
}

extension Demo {
    static var inventory: Inventory {
        let json = """
        {"warehouses":[{"id":1,"name":"انبار مرکزی","count":6,"value":95000000},{"id":2,"name":"انبار مزرعه","count":2,"value":25000000}],
         "totals":{"count":7,"value":120638393,"low":1,"out":1},
         "items":[
          {"id":4,"code":"1004","name":"بذر خیار","unit":"عدد","group":"بذر","barcode":"","qty":25,"reorder_point":10,"sale_price":1350000,"cost":931000,"value":23275000,"status":"ok","warehouses":[{"warehouse_id":1,"name":"انبار مرکزی","qty":25}]},
          {"id":1,"code":"1001","name":"بذر گوجه فرنگی","unit":"عدد","group":"بذر","barcode":"","qty":22,"reorder_point":10,"sale_price":1650000,"cost":1221429,"value":26871429,"status":"ok","warehouses":[{"warehouse_id":1,"name":"انبار مرکزی","qty":22}]},
          {"id":6,"code":"1006","name":"سم حشره‌کش","unit":"لیتر","group":"سم","barcode":"","qty":6,"reorder_point":10,"sale_price":700000,"cost":520000,"value":3120000,"status":"low","warehouses":[{"warehouse_id":1,"name":"انبار مرکزی","qty":6}]},
          {"id":5,"code":"1005","name":"لوله آبیاری قطره‌ای","unit":"متر","group":"آبیاری","barcode":"","qty":70,"reorder_point":10,"sale_price":420000,"cost":300000,"value":21000000,"status":"ok","warehouses":[{"warehouse_id":1,"name":"انبار مرکزی","qty":10},{"warehouse_id":2,"name":"انبار مزرعه","qty":60}]},
          {"id":2,"code":"1002","name":"کود NPK","unit":"کیسه","group":"کود","barcode":"","qty":39,"reorder_point":10,"sale_price":1100000,"cost":865000,"value":33735000,"status":"ok","warehouses":[{"warehouse_id":1,"name":"انبار مرکزی","qty":19},{"warehouse_id":2,"name":"انبار مزرعه","qty":20}]},
          {"id":7,"code":"1007","name":"نایلون گلخانه","unit":"رول","group":"","barcode":"","qty":0,"reorder_point":3,"sale_price":3100000,"cost":2400000,"value":0,"status":"out","warehouses":[]}
         ]}
        """
        // swiftlint:disable:next force_try
        return try! JSONDecoder().decode(Inventory.self, from: Data(json.utf8))
    }
}
