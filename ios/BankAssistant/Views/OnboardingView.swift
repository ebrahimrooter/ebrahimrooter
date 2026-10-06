import SwiftUI

/// First screen: dark green, a tilted lime card, three pages, «رد شدن» / «شروع کنیم».
struct OnboardingView: View {
    var onDone: () -> Void
    @State private var page = 0
    @State private var tilt = false

    private let pages: [(String, String)] = [
        ("حسابداری هوشمند\nبرای کسب‌وکار هر روزت", "واریز و برداشت‌های بانک خودشان می‌رسند؛ فقط بگو بابت چه بود تا در دفاتر بنشیند."),
        ("دستیار صوتی\nکه حساب‌ها را می‌داند", "بپرس «فروش امروز چقدر بود؟» یا بگو «از علی پنج میلیون گرفتم» تا ثبت شود."),
        ("همه‌چیز روی\nسرور خودت", "اطلاعات حساب‌ها فقط روی سرور حسابداری خودت است و کلید گوشی در Keychain می‌ماند."),
    ]

    var body: some View {
        ZStack {
            Theme.darkBackground.ignoresSafeArea()
            VStack(spacing: 0) {
                Spacer(minLength: 20)
                cards
                    .frame(height: 330)
                    .padding(.horizontal, 24)
                Spacer(minLength: 20)
                TabView(selection: $page) {
                    ForEach(pages.indices, id: \.self) { i in
                        VStack(spacing: 14) {
                            Text(pages[i].0)
                                .font(.fa(30, .bold)).foregroundStyle(.white)
                                .multilineTextAlignment(.center)
                            Text(pages[i].1)
                                .font(.fa(15)).foregroundStyle(.white.opacity(0.65))
                                .multilineTextAlignment(.center).padding(.horizontal, 28)
                        }
                        .tag(i)
                    }
                }
                .tabViewStyle(.page(indexDisplayMode: .never))
                .frame(height: 200)
                dots.padding(.vertical, 18)
                HStack(spacing: 12) {
                    Button("رد شدن", action: onDone)
                        .font(.fa(16, .semibold)).foregroundStyle(Theme.ink)
                        .frame(width: 110, height: 56)
                        .background(Capsule().fill(.white))
                    ArrowPillButton(title: page == pages.count - 1 ? "شروع کنیم" : "بعدی") {
                        if page < pages.count - 1 { withAnimation { page += 1 } } else { onDone() }
                    }
                }
                .padding(.horizontal, 20)
                .padding(.bottom, 24)
            }
        }
        .onAppear { withAnimation(.easeInOut(duration: 3).repeatForever(autoreverses: true)) { tilt = true } }
    }

    private var cards: some View {
        ZStack {
            RoundedRectangle(cornerRadius: 28, style: .continuous)
                .fill(Theme.leaf.opacity(0.25))
                .frame(width: 250, height: 310)
                .offset(x: 30, y: -10)
                .blur(radius: 1)
            BalanceCardArt()
                .frame(width: 300, height: 190)
                .rotationEffect(.degrees(tilt ? -14 : -18))
                .offset(y: tilt ? 4 : -4)
                .shadow(color: Theme.lime.opacity(0.35), radius: 30, y: 16)
        }
    }

    private var dots: some View {
        HStack(spacing: 7) {
            ForEach(pages.indices, id: \.self) { i in
                Circle()
                    .fill(i == page ? Theme.lime : .white.opacity(0.3))
                    .frame(width: 8, height: 8)
                    .overlay(Circle().strokeBorder(Theme.lime.opacity(i == page ? 0.6 : 0), lineWidth: 4).scaleEffect(1.8))
            }
        }
        .animation(.spring, value: page)
    }
}

/// The lime card of the first screen.
struct BalanceCardArt: View {
    var body: some View {
        ZStack(alignment: .topLeading) {
            RoundedRectangle(cornerRadius: 26, style: .continuous).fill(Theme.limeGradient)
            RoundedRectangle(cornerRadius: 26, style: .continuous).strokeBorder(.white.opacity(0.35), lineWidth: 1)
            VStack(alignment: .leading, spacing: 10) {
                HStack {
                    Text("موجودی حساب").font(.fa(16, .medium)).foregroundStyle(Theme.ink.opacity(0.7))
                    Spacer()
                    ChangePill(value: 12, dark: true)
                }
                HStack(alignment: .firstTextBaseline, spacing: 6) {
                    Text("۳۲۰٬۰۰۰٬۰۰۰").font(.fa(30, .heavy)).foregroundStyle(Theme.ink)
                    Text("تومان").font(.fa(13, .bold)).foregroundStyle(Theme.ink.opacity(0.5))
                }
                Spacer()
                HStack(alignment: .bottom) {
                    Image(systemName: "waveform.circle.fill").font(.system(size: 30)).foregroundStyle(Theme.ink.opacity(0.85))
                    Spacer()
                    Text("۶۰۳۷ •••• ۴۵۳۲").font(.fa(14, .semibold)).foregroundStyle(Theme.ink.opacity(0.5))
                        .environment(\.layoutDirection, .leftToRight)
                }
            }
            .padding(20)
            dotsPattern.padding(.top, 96).padding(.leading, 20)
        }
    }

    private var dotsPattern: some View {
        Canvas { ctx, _ in
            for r in 0..<4 {
                for c in 0..<12 {
                    ctx.fill(Path(ellipseIn: CGRect(x: CGFloat(c) * 7, y: CGFloat(r) * 7, width: 2.4, height: 2.4)),
                             with: .color(Theme.ink.opacity(0.25)))
                }
            }
        }
        .frame(width: 90, height: 30)
    }
}
