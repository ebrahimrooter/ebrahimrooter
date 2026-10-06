import SwiftUI

/// Profile / settings: the business, accounts, SMS device, shortcuts, unpairing.
struct ProfileView: View {
    var onUnpair: () -> Void
    @Environment(FinanceStore.self) private var store
    @Environment(\.openURL) private var openURL
    @State private var confirmUnpair = false
    @State private var history = false

    var body: some View {
        let h = store.home
        ScrollView {
            VStack(spacing: 16) {
                VStack(spacing: 10) {
                    Avatar(name: h?.company.isEmpty == false ? h!.company : "حسابداری", size: 84)
                    Text(h?.company.isEmpty == false ? h!.company : "حسابداری").font(.fa(22, .bold)).foregroundStyle(Theme.ink)
                    Text(h?.device_name ?? "").font(.fa(13)).foregroundStyle(Theme.muted)
                }
                .padding(.top, 20)

                SectionCard {
                    SectionHeader(title: "حساب‌ها")
                    ForEach(h?.wallets ?? []) { w in
                        HStack {
                            Image(systemName: w.kind == "cash" ? "banknote" : "building.columns")
                                .foregroundStyle(Theme.income).frame(width: 28)
                            Text(w.name).font(.fa(15)).foregroundStyle(Theme.ink)
                            Spacer()
                            Text(store.hideBalance ? "•••" : Fa.toman(w.balance)).font(.fa(15, .semibold)).foregroundStyle(Theme.ink)
                        }
                    }
                }

                SectionCard {
                    SectionHeader(title: "اتصال")
                    row("server.rack", "سرور", APIClient.shared.server?.host ?? "—")
                    row("antenna.radiowaves.left.and.right", "دستگاه پیامک",
                        h?.sms_device == nil ? "وصل نشده" : (h!.sms_device!.online ? "فعال" : "قطع · آخرین پیام \(Fa.digits(h!.sms_device!.last_seen))"))
                    Button {
                        if let u = WebApp.url(path: "acc/") { openURL(u) }
                    } label: { row("safari", "پنل حسابداری در Safari", "") }
                        .buttonStyle(.plain)
                    Button { history = true } label: { row("text.bubble", "گفتگوهای دستیار", "") }
                        .buttonStyle(.plain)
                }

                SectionCard {
                    SectionHeader(title: "میان‌برها")
                    Text("«Hey Siri, Ask Bank Assistant»، دکمه‌ی Action، Control Center و ویجت صفحه‌ی قفل هم دستیار را باز می‌کنند. وقتی دستیار روشن است وضعیتش در Dynamic Island دیده می‌شود.")
                        .font(.fa(13)).foregroundStyle(Theme.muted)
                }

                SectionCard {
                    Button(role: .destructive) { confirmUnpair = true } label: {
                        Label("قطع اتصال این گوشی", systemImage: "link.badge.plus").font(.fa(15, .semibold))
                            .frame(maxWidth: .infinity)
                    }
                    Text("کلید دسترسی فقط در Keychain همین گوشی است. از تنظیمات اپ وب حسابداری هم می‌توانی دسترسی این گوشی را لغو کنی.")
                        .font(.fa(12)).foregroundStyle(Theme.muted)
                }
            }
            .padding(.horizontal, 18)
        }
        .background(Theme.paper.ignoresSafeArea())
        .refreshable { await store.refresh() }
        .confirmationDialog("این گوشی از حسابداری جدا شود؟", isPresented: $confirmUnpair, titleVisibility: .visible) {
            Button("قطع اتصال", role: .destructive, action: onUnpair)
        }
        .sheet(isPresented: $history) { HistoryView(turns: AssistantEngine.shared.turns) }
    }

    private func row(_ icon: String, _ title: String, _ value: String) -> some View {
        HStack {
            Image(systemName: icon).foregroundStyle(Theme.income).frame(width: 28)
            Text(title).font(.fa(15)).foregroundStyle(Theme.ink)
            Spacer()
            Text(value).font(.fa(13)).foregroundStyle(Theme.muted).lineLimit(1)
            if value.isEmpty { Image(systemName: "chevron.backward").font(.system(size: 13, weight: .semibold)).foregroundStyle(Theme.muted) }
        }
        .contentShape(Rectangle())
    }
}
