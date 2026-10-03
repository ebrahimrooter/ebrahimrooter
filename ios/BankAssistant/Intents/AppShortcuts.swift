import AppIntents

/// «Hey Siri, Ask Bank Assistant», Spotlight, Shortcuts and the Action button.
/// (Siri does not speak Persian; the phrase is English, the conversation itself is Persian.)
struct BankAssistantShortcuts: AppShortcutsProvider {
    static var appShortcuts: [AppShortcut] {
        AppShortcut(intent: StartAssistantIntent(),
                    phrases: ["Ask \(.applicationName)", "Start \(.applicationName)", "Open \(.applicationName)"],
                    shortTitle: "دستیار حسابداری",
                    systemImageName: "waveform")
    }
}
