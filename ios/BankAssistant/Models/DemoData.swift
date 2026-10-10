import Foundation

/// Sample books for previews and simulator screenshots: launch with `-demo`
/// (optionally `-tab report|stock|accounting|profile`). Never used otherwise.
enum Demo {
    static let enabled = ProcessInfo.processInfo.arguments.contains("-demo")

    static var startTab: AppTab {
        let a = ProcessInfo.processInfo.arguments
        guard let i = a.firstIndex(of: "-tab"), i + 1 < a.count else { return .home }
        switch a[i + 1] {
        case "report": return .report
        case "stock": return .stock
        case "accounting": return .accounting
        case "profile": return .profile
        default: return .home
        }
    }

    /// `-sheet assistant|tx|manual|list|pip`
    static var sheet: String? {
        let a = ProcessInfo.processInfo.arguments
        guard let i = a.firstIndex(of: "-sheet"), i + 1 < a.count else { return nil }
        return a[i + 1]
    }

    static var home: HomeData {
        // swiftlint:disable:next force_try
        try! JSONDecoder().decode(HomeData.self, from: Data(json.utf8))
    }

    static func list(direction: String) -> TxList {
        let all = home.recent + home.pending
        let items = direction.isEmpty ? all : all.filter { $0.direction == direction }
        return TxList(items: items, total_in: all.filter(\.isIn).reduce(0) { $0 + $1.amount },
                      total_out: all.filter { !$0.isIn }.reduce(0) { $0 + $1.amount })
    }

    private static let walletNames = [1: "بانک ملت", 3: "بلو بانک", 4: "بانک ملی", 5: "بانک صادرات", 2: "صندوق"]

    private static func tx(_ id: Int, _ dir: String, _ amount: Int, _ status: String, _ desc: String, _ party: String, _ date: String, _ time: String, _ sms: String? = nil, w: Int = 1) -> String {
        let s = sms.map { "\"\($0)\"" } ?? "null"
        return """
        {"id":\(id),"direction":"\(dir)","amount":\(amount),"status":"\(status)","description":"\(desc)","party":"\(party)","category":"","wallet":"\(walletNames[w] ?? "")","wallet_id":\(w),"bank_date":"\(date)","bank_time":"\(time)","occurred_at":"2026-10-06 10:00:00","source":"sms","sms_text":\(s)}
        """
    }

    private static var json: String {
        let pending = [
            tx(101, "in", 27_500_000, "pending", "", "", "1405/07/14", "14:47", "بلو\\nواریز به حساب: 27,500,000 ریال\\nمانده: 735,000,000 ریال", w: 3),
            tx(102, "out", 8_900_000, "pending", "", "", "1405/07/14", "11:20", "بانک ملی ایران\\nبرداشت از حساب 0101234567001\\nمبلغ: 8,900,000 ریال", w: 4),
        ].joined(separator: ",")
        let recent = [
            tx(90, "in", 120_000_000, "confirmed", "فروش عمده بذر", "فروشگاه نور", "1405/07/13", "16:05"),
            tx(89, "out", 25_000_000, "confirmed", "اجاره انبار", "", "1405/07/12", "09:30"),
            tx(88, "in", 45_600_000, "confirmed", "تسویه فاکتور ۱۲۴", "علی رضایی", "1405/07/11", "13:12"),
            tx(87, "out", 62_000_000, "confirmed", "خرید کود", "پخش البرز", "1405/07/10", "10:44"),
            tx(86, "in", 18_000_000, "confirmed", "فروش نقدی", "مریم احمدی", "1405/07/09", "18:20"),
            tx(85, "out", 3_400_000, "confirmed", "قبض برق", "", "1405/07/08", "08:15"),
        ].joined(separator: ",")
        return """
        {"company":"بازرگانی سبز","device_name":"iPhone","balance":10593000000,
         "wallets":[{"id":1,"name":"بانک ملت","kind":"bank","balance":7123000000,"bank_balance":7123000000,"bank":"mellat","card":"6104337788","color":"#c8102e","pending":0,"otps":0},
           {"id":3,"name":"بلو بانک","kind":"bank","balance":735000000,"bank_balance":735000000,"bank":"blu","card":"6219861122","color":"#1688f0","pending":1,"otps":0},
           {"id":4,"name":"بانک ملی","kind":"bank","balance":1450000000,"bank_balance":1450000000,"bank":"melli","card":"6037991234","color":"#0b3a74","pending":1,"otps":1},
           {"id":5,"name":"بانک صادرات","kind":"bank","balance":565000000,"bank_balance":565000000,"bank":"saderat","card":"6037695566","color":"#0e5e8c","pending":0,"otps":0},
           {"id":2,"name":"صندوق (نقد)","kind":"cash","balance":720000000,"bank_balance":null,"bank":"cash","card":"","color":"#5b6b64","pending":0,"otps":0}],
         "month":{"label":"مهر","in":2545000000,"out":852000000,"in_change":16,"out_change":-8},
         "months":[
          {"label":"اردیبهشت","year":1405,"month":2,"from":"2026-04-21","to":"2026-05-21","in":1450000000,"out":980000000},
          {"label":"خرداد","year":1405,"month":3,"from":"2026-05-22","to":"2026-06-21","in":2120000000,"out":1210000000},
          {"label":"تیر","year":1405,"month":4,"from":"2026-06-22","to":"2026-07-22","in":1780000000,"out":760000000},
          {"label":"مرداد","year":1405,"month":5,"from":"2026-07-23","to":"2026-08-22","in":2290000000,"out":1430000000},
          {"label":"شهریور","year":1405,"month":6,"from":"2026-08-23","to":"2026-09-22","in":2195000000,"out":925000000},
          {"label":"مهر","year":1405,"month":7,"from":"2026-09-23","to":"2026-10-22","in":2545000000,"out":852000000}],
         "pending":[\(pending)],
         "recent":[\(recent)],
         "parties":[{"name":"علی رضایی","uses":12},{"name":"مریم احمدی","uses":9},{"name":"فروشگاه نور","uses":7},{"name":"پخش البرز","uses":5},{"name":"حسن کریمی","uses":3}],
         "sms_device":{"last_seen":"1405/07/14 14:50","online":true,"signal":22},
         "cheque_alert":null}
        """
    }
}
