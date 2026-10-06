import SwiftUI

/// Green finance look: dark green header, lime-green cards, white sheet, black pill tab bar.
enum Theme {
    static let deep = Color(red: 0.03, green: 0.08, blue: 0.05)          // almost black green
    static let forest = Color(red: 0.09, green: 0.22, blue: 0.13)
    static let leaf = Color(red: 0.36, green: 0.74, blue: 0.43)
    static let lime = Color(red: 0.55, green: 0.88, blue: 0.56)
    static let mint = Color(red: 0.73, green: 0.95, blue: 0.70)
    static let ink = Color(red: 0.07, green: 0.08, blue: 0.08)
    static let paper = Color(red: 0.96, green: 0.97, blue: 0.96)
    static let card = Color.white
    static let muted = Color(red: 0.45, green: 0.48, blue: 0.47)
    static let income = Color(red: 0.16, green: 0.62, blue: 0.30)
    static let expense = Color(red: 0.86, green: 0.27, blue: 0.27)

    /// Background of the dark screens (onboarding, home header).
    static var darkBackground: some View {
        ZStack {
            deep
            RadialGradient(colors: [forest.opacity(0.95), .clear], center: .init(x: 0.75, y: 0.05), startRadius: 10, endRadius: 520)
            RadialGradient(colors: [leaf.opacity(0.25), .clear], center: .init(x: 0.1, y: 0.35), startRadius: 0, endRadius: 320)
        }
    }

    /// The lime card gradient (balance card, report card, pending card).
    static let limeGradient = LinearGradient(colors: [mint, lime, leaf], startPoint: .topLeading, endPoint: .bottomTrailing)
}

extension Font {
    static func fa(_ size: CGFloat, _ weight: Font.Weight = .regular) -> Font {
        .system(size: size, weight: weight, design: .rounded)
    }
}

/// Big amount with smaller grey «تومان», like «$ 3,200.00».
struct AmountText: View {
    let rial: Int
    var size: CGFloat = 34
    var color: Color = .white
    var hidden = false

    var body: some View {
        HStack(alignment: .firstTextBaseline, spacing: 6) {
            Text(hidden ? "••••••" : Fa.toman(rial))
                .font(.fa(size, .bold))
                .foregroundStyle(color)
                .contentTransition(.numericText())
            Text("تومان")
                .font(.fa(size * 0.42, .semibold))
                .foregroundStyle(color.opacity(0.55))
        }
        .lineLimit(1)
        .minimumScaleFactor(0.5)
    }
}

/// «↗ ۱۲٪» pill.
struct ChangePill: View {
    let value: Double?
    var dark = false

    var body: some View {
        if let value {
            HStack(spacing: 3) {
                Image(systemName: value >= 0 ? "arrow.up.right" : "arrow.down.right")
                Text(Fa.percent(value))
            }
            .font(.fa(12, .semibold))
            .padding(.horizontal, 9).padding(.vertical, 4)
            .foregroundStyle(dark ? Theme.ink : Theme.lime)
            .background(Capsule().fill(dark ? .white.opacity(0.35) : Theme.lime.opacity(0.16)))
            .overlay(Capsule().strokeBorder(dark ? Theme.ink.opacity(0.5) : Theme.lime.opacity(0.4), lineWidth: 1))
        }
    }
}

/// Round icon button with a thin border (header actions).
struct RoundIconButton: View {
    let systemName: String
    var dark = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            Image(systemName: systemName)
                .font(.system(size: 17, weight: .medium))
                .foregroundStyle(dark ? .white : Theme.ink)
                .frame(width: 46, height: 46)
                .background(Circle().fill(dark ? .white.opacity(0.06) : .white))
                .overlay(Circle().strokeBorder(dark ? .white.opacity(0.18) : Theme.ink.opacity(0.12), lineWidth: 1))
        }
        .buttonStyle(.plain)
    }
}

/// Initials in a coloured circle, for people (no photos in the books).
struct Avatar: View {
    let name: String
    var size: CGFloat = 52

    private static let tints: [Color] = [
        Color(red: 0.93, green: 0.55, blue: 0.35), Color(red: 0.42, green: 0.55, blue: 0.95), Color(red: 0.62, green: 0.45, blue: 0.85),
        Color(red: 0.25, green: 0.68, blue: 0.62), Color(red: 0.90, green: 0.42, blue: 0.55), Color(red: 0.85, green: 0.68, blue: 0.25),
    ]

    var body: some View {
        let tint = Self.tints[abs(name.unicodeScalars.reduce(0) { $0 &+ Int($1.value) }) % Self.tints.count]
        Text(initials)
            .font(.fa(size * 0.36, .bold))
            .foregroundStyle(.white)
            .frame(width: size, height: size)
            .background(Circle().fill(LinearGradient(colors: [tint, tint.opacity(0.7)], startPoint: .top, endPoint: .bottom)))
            .overlay(Circle().strokeBorder(.white, lineWidth: 2))
            .shadow(color: tint.opacity(0.35), radius: 6, y: 3)
    }

    private var initials: String {
        let words = name.split(separator: " ").filter { !$0.isEmpty }
        let letters = words.prefix(2).compactMap { $0.first.map(String.init) }
        return letters.isEmpty ? "؟" : letters.joined(separator: "‌")
    }
}

/// White rounded section on the light sheet.
struct SectionCard<Content: View>: View {
    @ViewBuilder var content: Content

    var body: some View {
        VStack(alignment: .leading, spacing: 14) { content }
            .padding(16)
            .frame(maxWidth: .infinity, alignment: .leading)
            .background(RoundedRectangle(cornerRadius: 24, style: .continuous).fill(Theme.card))
            .overlay(RoundedRectangle(cornerRadius: 24, style: .continuous).strokeBorder(Theme.ink.opacity(0.05)))
    }
}

struct SectionHeader: View {
    let title: String
    var action: String?
    var onAction: (() -> Void)?

    var body: some View {
        HStack {
            Text(title).font(.fa(17, .bold)).foregroundStyle(Theme.ink)
            Spacer()
            if let action, let onAction {
                Button(action, action: onAction).font(.fa(14, .semibold)).foregroundStyle(Theme.income)
            }
        }
    }
}

/// Primary pill: «شروع کنیم ←» with a black circle arrow.
struct ArrowPillButton: View {
    let title: String
    var busy = false
    let action: () -> Void

    var body: some View {
        Button(action: action) {
            HStack {
                Text(title).font(.fa(16, .semibold)).foregroundStyle(Theme.ink)
                    .frame(maxWidth: .infinity)
                ZStack {
                    Circle().fill(Theme.ink)
                    if busy { ProgressView().tint(.white) } else {
                        Image(systemName: "arrow.left").font(.system(size: 17, weight: .bold)).foregroundStyle(.white)
                    }
                }
                .frame(width: 46, height: 46)
            }
            .padding(5).padding(.leading, 12)
            .background(Capsule().fill(Theme.limeGradient))
            .overlay(Capsule().strokeBorder(Theme.lime, lineWidth: 1.5))
        }
        .buttonStyle(.plain)
    }
}

struct TransactionRow: View {
    let tx: Transaction
    var hidden = false

    var body: some View {
        HStack(spacing: 12) {
            ZStack {
                Circle().fill(tx.isIn ? Theme.income.opacity(0.12) : Theme.expense.opacity(0.10))
                Image(systemName: tx.isIn ? "arrow.down.left" : "arrow.up.right")
                    .font(.system(size: 16, weight: .bold))
                    .foregroundStyle(tx.isIn ? Theme.income : Theme.expense)
            }
            .frame(width: 44, height: 44)
            VStack(alignment: .leading, spacing: 4) {
                HStack(spacing: 6) {
                    Text(tx.title).font(.fa(15, .semibold)).foregroundStyle(Theme.ink).lineLimit(1)
                    if tx.isPending {
                        Text("منتظر توضیح").font(.fa(10, .bold)).foregroundStyle(.orange)
                            .padding(.horizontal, 6).padding(.vertical, 2)
                            .background(Capsule().fill(.orange.opacity(0.12)))
                    }
                }
                Text(tx.subtitle).font(.fa(12)).foregroundStyle(Theme.muted).lineLimit(1)
            }
            Spacer(minLength: 8)
            Text(hidden ? "•••" : (tx.isIn ? "+" : "−") + Fa.toman(tx.amount))
                .font(.fa(15, .bold))
                .foregroundStyle(tx.isIn ? Theme.income : Theme.ink)
                .lineLimit(1)
        }
        .contentShape(Rectangle())
    }
}
