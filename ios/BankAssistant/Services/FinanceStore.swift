import Foundation
import Observation

/// What the screens show: one call for the home screen, refreshed on open,
/// pull-to-refresh, after every change and after a voice session.
@MainActor
@Observable
final class FinanceStore {
    static let shared = FinanceStore()

    private(set) var home: HomeData?
    private(set) var loading = false
    var error: String?
    var hideBalance = UserDefaults.standard.bool(forKey: "hideBalance") {
        didSet { UserDefaults.standard.set(hideBalance, forKey: "hideBalance") }
    }

    private let api = APIClient.shared

    func refresh() async {
        guard api.isPaired else { return }
        loading = true
        defer { loading = false }
        do {
            home = try await api.home()
            error = nil
        } catch {
            self.error = error.localizedDescription
        }
    }

    func confirm(_ tx: Transaction, description: String, party: String) async throws {
        try await api.confirm(id: tx.id, description: description, party: party)
        await refresh()
    }

    func ignore(_ tx: Transaction) async throws {
        try await api.ignore(id: tx.id)
        await refresh()
    }

    func manual(incoming: Bool, amountToman: String, description: String, party: String) async throws {
        try await api.manual(incoming: incoming, amountToman: amountToman, description: description, party: party)
        await refresh()
    }

    func reset() { home = nil; error = nil }
}
