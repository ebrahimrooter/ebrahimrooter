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
    /// the bank card it came on (each card has its own panel)
    let wallet_id: Int?
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

/// A bank card (or the cash box): one card in the Wallet stack, with its own panel.
struct Wallet: Decodable, Identifiable, Hashable {
    let id: Int
    let name: String
    let kind: String
    let balance: Int
    let bank_balance: Int?
    /// mellat | melli | saderat | blu | cash
    var bank: String? = nil
    /// card / account digits (the last 4 pick the card when a bank has several)
    var card: String? = nil
    /// "#rrggbb"
    var color: String? = nil
    var pending: Int? = nil
    /// fresh one-time codes waiting in this card's «رمز پویا»
    var otps: Int? = nil
    var opening: Int? = nil

    var isCash: Bool { kind == "cash" || bank == "cash" }
    var lastFour: String? {
        guard let c = card, c.count >= 4 else { return nil }
        return String(c.suffix(4))
    }
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

    /// «۲۵۴٫۵ م» (million toman), «۸۵۰ ه» (thousand toman).
    static func short(_ rial: Int) -> String {
        let t = Double(abs(rial)) / 10
        let one: (Double) -> String = { digits(String(format: "%.1f", $0)).replacingOccurrences(of: ".", with: "٫").replacingOccurrences(of: "٫۰", with: "") }
        if t >= 1_000_000_000 { return one(t / 1_000_000_000) + " میلیارد" }
        if t >= 1_000_000 { return one(t / 1_000_000) + " م" }
        if t >= 1_000 { return number(Int(t / 1_000)) + " ه" }
        return number(Int(t))
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

struct BankInfo: Decodable, Hashable, Identifiable {
    let code: String
    let name: String
    let color: String
    var id: String { code }
}

struct CardList: Decodable {
    let items: [Wallet]
    let banks: [BankInfo]
}

struct SavedID: Decodable { let id: Int }

/// One-time code (رمز پویا) of a card, decrypted on the server for a few minutes.
struct OneTimeCode: Decodable, Identifiable, Hashable {
    let id: Int
    let code: String
    let amount: Int?
    let merchant: String?
    let wallet_id: Int?
    let seconds_left: Int
}

struct OTPList: Decodable { let items: [OneTimeCode] }
