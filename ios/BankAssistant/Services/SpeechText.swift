import Foundation

/// Chat text → what the voice should say (the same rules as ba_speech_text() on
/// the server and SpeechText.kt on Android): no emoji, links or /commands;
/// amounts, Jalali dates and times as Persian words — the phonemizer would read
/// «4,000,000» digit by digit. Checked against tools/voice-model/speech_cases.json.
enum SpeechText {
    private static let months = ["", "فروردین", "اردیبهشت", "خرداد", "تیر", "مرداد", "شهریور", "مهر", "آبان", "آذر", "دی", "بهمن", "اسفند"]
    private static let ones = ["", "یک", "دو", "سه", "چهار", "پنج", "شش", "هفت", "هشت", "نه", "ده", "یازده", "دوازده", "سیزده",
                               "چهارده", "پانزده", "شانزده", "هفده", "هجده", "نوزده"]
    private static let tens = ["", "", "بیست", "سی", "چهل", "پنجاه", "شصت", "هفتاد", "هشتاد", "نود"]
    private static let hundreds = ["", "صد", "دویست", "سیصد", "چهارصد", "پانصد", "ششصد", "هفتصد", "هشتصد", "نهصد"]
    private static let scales = ["", "هزار", "میلیون", "میلیارد", "هزار میلیارد"]
    private static let wb = "(?<![0-9A-Za-z_])"   // \b as on the server: ASCII word characters only
    private static let nw = "(?![0-9A-Za-z_])"

    static func numWords(_ value: Int64) -> String {
        if value == 0 { return "صفر" }
        if value < 0 { return "منفی " + numWords(-value) }
        func below1000(_ v: Int) -> String {
            var x = v
            var w: [String] = []
            if x >= 100 { w.append(hundreds[x / 100]); x %= 100 }
            if x >= 20 { w.append(tens[x / 10]); x %= 10 }
            if x > 0 { w.append(ones[x]) }
            return w.joined(separator: " و ")
        }
        var n = value
        var parts: [String] = []
        var i = 0
        while n > 0 && i < scales.count {
            let chunk = Int(n % 1000)
            if chunk != 0 {
                parts.append(i == 1 && chunk == 1 ? "هزار" : (below1000(chunk) + " " + scales[i]).trimmingCharacters(in: .whitespaces))
            }
            n /= 1000
            i += 1
        }
        return parts.reversed().joined(separator: " و ")
    }

    private static func normalize(_ s: String) -> String {
        var out = String.UnicodeScalarView()
        for u in s.unicodeScalars {
            switch u.value {
            case 0x06F0...0x06F9: out.append(Unicode.Scalar(u.value - 0x06F0 + 0x30)!)
            case 0x0660...0x0669: out.append(Unicode.Scalar(u.value - 0x0660 + 0x30)!)
            case 0x064A: out.append("ی")
            case 0x0643: out.append("ک")
            case 0x200C: out.append(" ")
            case 0x0D: continue
            default: out.append(u)
            }
        }
        return String(out)
    }

    /// Regex replace; `with` gets the match's groups (0 = whole match).
    private static func sub(_ pattern: String, _ s: String, _ with: ([String]) -> String) -> String {
        guard let re = try? NSRegularExpression(pattern: pattern) else { return s }
        let ns = s as NSString
        var out = ""
        var last = 0
        for m in re.matches(in: s, range: NSRange(location: 0, length: ns.length)) {
            out += ns.substring(with: NSRange(location: last, length: m.range.location - last))
            let g = (0..<m.numberOfRanges).map { m.range(at: $0).location == NSNotFound ? "" : ns.substring(with: m.range(at: $0)) }
            out += with(g)
            last = m.range.location + m.range.length
        }
        return out + ns.substring(from: last)
    }

    private static func sub(_ pattern: String, _ s: String, _ template: String) -> String {
        guard let re = try? NSRegularExpression(pattern: pattern) else { return s }
        return re.stringByReplacingMatches(in: s, range: NSRange(location: 0, length: (s as NSString).length), withTemplate: template)
    }

    static func forSpeech(_ text: String) -> String {
        var s = normalize(text)
        s = sub("(?<=\\d)[٬،](?=\\d{3})", s, ",")
        s = sub("https?://\\S+", s, " ")
        s = sub("(^|\\s)/[a-z_]+\(nw)", s, " ")
        s = sub("[\\x{1F000}-\\x{1FAFF}\\x{2600}-\\x{27BF}\\x{2B00}-\\x{2BFF}\\x{FE0F}\\x{200D}\\x{20E3}\\x{2190}-\\x{21FF}\\x{2500}-\\x{257F}]", s, " ")
        s = sub("\(wb)(1[34]\\d\\d)/(\\d{1,2})/(\\d{1,2})\(nw)", s) { g in
            let mo = Int(g[2]) ?? 0
            return (1...12).contains(mo) ? numWords(Int64(g[3])!) + " " + months[mo] + " " + numWords(Int64(g[1])!) : g[0]
        }
        s = sub("(ساعت\\s*)?\(wb)([01]?\\d|2[0-3]):([0-5]\\d)\(nw)", s) { g in
            let mi = Int(g[3]) ?? 0
            return "ساعت " + numWords(Int64(g[2])!) + (mi != 0 ? " و " + numWords(Int64(mi)) + " دقیقه" : "")
        }
        s = sub("\\d{1,3}(?:,\\d{3})+|\\d+", s) { g in
            let digits = g[0].replacingOccurrences(of: ",", with: "")
            return digits.count > 15 ? " " : numWords(Int64(digits)!)
        }
        let swaps: [(String, String)] = [("·", "،"), ("—", "،"), ("–", "،"), ("•", "،"), ("|", "،"), ("«", ""), ("»", ""),
                                         ("\"", ""), ("(", "، "), (")", "، "), (":", "،")]
        for (a, b) in swaps { s = s.replacingOccurrences(of: a, with: b) }
        s = sub("\\s*\\n+\\s*", s, ". ")
        s = sub("([.،؟!?])(\\s*[.،])+", s, "$1")
        s = sub("\\s+", s, " ")
        s = sub("^[\\s.،]+|[\\s.،]+$", s, "")
        return s.count > 1200 ? String(s.prefix(1200)) : s
    }
}
