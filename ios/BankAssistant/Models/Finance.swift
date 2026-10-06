import Foundation

/// JSON of server/assistant_app.php (amounts are rial).
struct Transaction: Decodable, Identifiable, Hashable {
    let id: Int
    let direction: String          // in | out
    let amount: Int
    let status: String             // pending | confirmed | ignored
    let description: String
    let party: String
    let category: String
    let wallet: String
    let bank_date: String          // Jalali yyyy/mm/dd
    let bank_time: String
    let occurred_at: String
    let source: String             // sms | manual
    let sms_text: String?

    var isIn: Bool { direction == "in" }
    var isPending: Bool { status == "pending" }
    var title: String {
        if !description.isEmpty { return description }
        if !party.isEmpty { return party }
        return isIn ? "واریز" : "برداشت"
    }
    var subtitle: String {
        var parts: [String] = []
        if !party.isEmpty && party != title { parts.append(party) }
        parts.append(Fa.digits(bank_date + (bank_time.isEmpty ? "" : " · " + bank_time)))
        return parts.joined(separator: " · ")
    }
}

struct Wallet: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let kind: String
    let balance: Int
    let bank_balance: Int?
}

struct MonthSum: Decodable, Identifiable, Hashable {
    let label: String
    let year: Int
    let month: Int
    let from: String
    let to: String
    let incoming: Int
    let outgoing: Int
    var id: String { "\(year)-\(month)" }
    func value(_ isIn: Bool) -> Int { isIn ? incoming : outgoing }

    enum CodingKeys: String, CodingKey {
        case label, year, month, from, to
        case incoming = "in", outgoing = "out"
    }
}

struct MonthNow: Decodable, Hashable {
    let label: String
    let incoming: Int
    let outgoing: Int
    let in_change: Double?
    let out_change: Double?

    enum CodingKeys: String, CodingKey {
        case label, in_change, out_change
        case incoming = "in", outgoing = "out"
    }
}

struct Party: Decodable, Identifiable, Hashable {
    let name: String
    let uses: Int
    var id: String { name }
}

struct SmsDevice: Decodable, Hashable {
    let last_seen: String
    let online: Bool
    let signal: Int?
}

struct HomeData: Decodable {
    let company: String
    let device_name: String
    let balance: Int
    let wallets: [Wallet]
    let month: MonthNow
    let months: [MonthSum]
    let pending: [Transaction]
    let recent: [Transaction]
    let parties: [Party]
    let sms_device: SmsDevice?
    let cheque_alert: String?
}

struct TxList: Decodable {
    let items: [Transaction]
    let total_in: Int
    let total_out: Int
}

struct PanelSession: Decodable {
    let token: String
    let expires_in: Int
}

/// Persian digits and money.
enum Fa {
    private static let fmt: NumberFormatter = {
        let f = NumberFormatter()
        f.locale = Locale(identifier: "fa_IR")
        f.numberStyle = .decimal
        f.maximumFractionDigits = 0
        return f
    }()

    static func number(_ n: Int) -> String { fmt.string(from: NSNumber(value: n)) ?? String(n) }

    /// rial → «۱۲٬۵۰۰٬۰۰۰»  (toman, no unit)
    static func toman(_ rial: Int) -> String { number(rial / 10) }

    static func digits(_ s: String) -> String {
        let map: [Character: Character] = ["0": "۰", "1": "۱", "2": "۲", "3": "۳", "4": "۴", "5": "۵", "6": "۶", "7": "۷", "8": "۸", "9": "۹"]
        return String(s.map { map[$0] ?? $0 })
    }

    /// Persian / Arabic digits and separators typed by the user → plain ASCII number.
    static func ascii(_ s: String) -> String {
        var out = ""
        for ch in s {
            if let v = ch.wholeNumberValue { out += String(v) } else if ch == "." || ch == "٫" { out += "." }
        }
        return out
    }

    static func percent(_ v: Double) -> String { digits(String(Int(abs(v).rounded()))) + "٪" }

    static func greeting(_ date: Date = .now) -> String {
        switch Calendar.current.component(.hour, from: date) {
        case 4..<12: return "صبح بخیر،"
        case 12..<17: return "ظهر بخیر،"
        case 17..<21: return "عصر بخیر،"
        default: return "شب بخیر،"
        }
    }
}
