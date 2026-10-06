import SwiftUI

/// The voice animation in the middle of the floating window. Reacts to the
/// microphone / speech level and changes with the state.
struct VoiceOrbView: View {
    var phase: AssistantPhase
    var level: Float

    var body: some View {
        TimelineView(.animation(minimumInterval: 1 / 60, paused: false)) { context in
            let t = context.date.timeIntervalSinceReferenceDate
            Canvas { ctx, size in
                let c = CGPoint(x: size.width / 2, y: size.height / 2)
                let r = min(size.width, size.height) * 0.30
                let lv = CGFloat(level)
                let colors = palette
                // soft glow
                ctx.fill(Path(ellipseIn: CGRect(x: c.x - r * 1.6, y: c.y - r * 1.6, width: r * 3.2, height: r * 3.2)),
                         with: .radialGradient(Gradient(colors: [colors[0].opacity(0.35), .clear]), center: c, startRadius: 0, endRadius: r * 1.6))
                // three wobbling rings
                for i in 0..<3 {
                    var path = Path()
                    let k = Double(i)
                    let amp = r * (0.06 + 0.22 * lv) * (phase == .processing ? 0.5 : 1)
                    for s in stride(from: 0.0, through: 2 * .pi, by: .pi / 90) {
                        let wobble = sin(s * (3 + k) + t * (1.4 + 0.5 * k)) * amp + cos(s * 2 - t * 0.9) * amp * 0.5
                        let rr = r * (1 - 0.08 * k) + wobble
                        let p = CGPoint(x: c.x + cos(s) * rr, y: c.y + sin(s) * rr)
                        s == 0 ? path.move(to: p) : path.addLine(to: p)
                    }
                    path.closeSubpath()
                    ctx.stroke(path, with: .color(colors[i % colors.count].opacity(0.85 - 0.2 * k)), lineWidth: 3 - k * 0.6)
                }
                // spinner dots while processing
                if phase == .processing {
                    for i in 0..<8 {
                        let a = t * 3 + Double(i) * .pi / 4
                        let p = CGPoint(x: c.x + cos(a) * r * 0.55, y: c.y + sin(a) * r * 0.55)
                        ctx.fill(Path(ellipseIn: CGRect(x: p.x - 3, y: p.y - 3, width: 6, height: 6)), with: .color(Color(red: 0.09, green: 0.3, blue: 0.17).opacity(0.3 + 0.08 * Double(i))))
                    }
                }
            }
        }
        .accessibilityLabel(phase.title)
    }

    private var palette: [Color] {
        let leaf = Color(red: 0.30, green: 0.70, blue: 0.40), lime = Color(red: 0.55, green: 0.88, blue: 0.56)
        let forest = Color(red: 0.09, green: 0.30, blue: 0.17)
        switch phase {
        case .listening: return [lime, leaf, forest]
        case .processing: return [leaf, Color(red: 0.25, green: 0.6, blue: 0.65), forest]
        case .speaking: return [Color(red: 0.75, green: 0.92, blue: 0.4), lime, forest]
        case .error: return [Color(red: 1, green: 0.4, blue: 0.4), .orange, forest]
        case .idle: return [leaf.opacity(0.8), lime, forest]
        }
    }
}
