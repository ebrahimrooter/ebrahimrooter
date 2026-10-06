import SwiftUI

enum AppTab: Hashable { case home, report, accounting, profile }

/// The four screens with the light pill tab bar (black circle on the current one)
/// and the voice orb in the middle.
struct MainTabView: View {
    var onUnpair: () -> Void
    @Environment(AssistantEngine.self) private var engine
    @Environment(FinanceStore.self) private var store
    @State private var tab: AppTab = .home
    @State private var showAssistant = false
    @State private var openedBooks = false

    var body: some View {
        ZStack(alignment: .bottom) {
            ZStack {
                switch tab {
                case .home: HomeView(tab: $tab)
                case .report: ReportView(tab: $tab)
                case .profile: ProfileView(onUnpair: onUnpair)
                case .accounting: Color.clear
                }
                // the panel stays loaded after the first visit (keeps its page and session)
                if openedBooks {
                    AccountingView()
                        .opacity(tab == .accounting ? 1 : 0)
                        .allowsHitTesting(tab == .accounting)
                }
            }
            .frame(maxWidth: .infinity, maxHeight: .infinity)
            .safeAreaInset(edge: .bottom) { Color.clear.frame(height: 78) }

            TabBar(tab: $tab, listening: engine.sessionActive) { showAssistant = true }
        }
        .ignoresSafeArea(.keyboard)
        .sheet(isPresented: $showAssistant, onDismiss: { Task { await store.refresh() } }) {
            AssistantSheet()
                .presentationDetents([.fraction(0.42), .large])
                .presentationBackground(.ultraThinMaterial)
                .presentationCornerRadius(36)
                .presentationDragIndicator(.visible)
        }
        .onChange(of: tab) { _, t in if t == .accounting { openedBooks = true } }
        .onChange(of: engine.sessionActive) { _, active in
            if active { showAssistant = true }
        }
        .task { await store.refresh() }
    }
}

struct TabBar: View {
    @Binding var tab: AppTab
    var listening: Bool
    var onOrb: () -> Void

    var body: some View {
        HStack(spacing: 0) {
            item(.home, "house")
            item(.report, "chart.bar")
            orb
            item(.accounting, "safari")
            item(.profile, "person")
        }
        .padding(.horizontal, 14)
        .frame(height: 74)
        .background(
            UnevenRoundedRectangle(topLeadingRadius: 30, topTrailingRadius: 30, style: .continuous)
                .fill(Color(white: 0.93))
                .ignoresSafeArea(edges: .bottom)
                .shadow(color: .black.opacity(0.06), radius: 12, y: -4)
        )
    }

    private func item(_ t: AppTab, _ icon: String) -> some View {
        Button {
            withAnimation(.spring(response: 0.3, dampingFraction: 0.8)) { tab = t }
        } label: {
            ZStack {
                if tab == t {
                    Circle().fill(Theme.ink).frame(width: 50, height: 50)
                        .transition(.scale.combined(with: .opacity))
                }
                Image(systemName: tab == t ? icon + ".fill" : icon)
                    .font(.system(size: 19, weight: .medium))
                    .foregroundStyle(tab == t ? .white : Theme.ink.opacity(0.75))
            }
            .frame(maxWidth: .infinity, minHeight: 56)
            .contentShape(Rectangle())
        }
        .buttonStyle(.plain)
        .accessibilityLabel(label(t))
    }

    private var orb: some View {
        Button(action: onOrb) {
            ZStack {
                Circle().fill(Theme.limeGradient).frame(width: 58, height: 58)
                    .shadow(color: Theme.leaf.opacity(0.55), radius: listening ? 16 : 8, y: 4)
                Circle().strokeBorder(.white, lineWidth: 3).frame(width: 58, height: 58)
                Image(systemName: listening ? "waveform" : "mic.fill")
                    .font(.system(size: 22, weight: .bold))
                    .foregroundStyle(Theme.ink)
                    .symbolEffect(.variableColor.iterative, isActive: listening)
            }
            .offset(y: -10)
            .frame(maxWidth: .infinity)
        }
        .buttonStyle(.plain)
        .accessibilityLabel("دستیار صوتی")
    }

    private func label(_ t: AppTab) -> String {
        switch t {
        case .home: return "خانه"
        case .report: return "گزارش"
        case .accounting: return "حسابداری"
        case .profile: return "تنظیمات"
        }
    }
}
