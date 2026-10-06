import SwiftUI

/// The voice animation in the middle of the floating window. Reacts to the
/// microphone / speech level and changes with the state.
struct VoiceOrbView: View {
    var phase: AssistantPhase
    var level: Float

    var body: some View {
        TimelineView(.animation(minimumInterval: 1 / 60, paused: false)) { context in
            let t = context.date.timeIntervalSinceReferenceDate
            Canvas { ctx, size in draw(&ctx, size: size, t: t) }
        }
        .accessibilityLabel(phase.title)
    }

    private func draw(_ ctx: inout GraphicsContext, size: CGSize, t: Double) {
        let c = CGPoint(x: size.width / 2, y: size.height / 2)
        let r: CGFloat = min(size.width, size.height) * 0.30
        let lv = CGFloat(level)
        let colors = palette
        let forest = Color(red: 0.09, green: 0.3, blue: 0.17)
        // soft glow
        let glowRect = CGRect(x: c.x - r * 1.6, y: c.y - r * 1.6, width: r * 3.2, height: r * 3.2)
        let glow = Gradient(colors: [colors[0].opacity(0.35), .clear])
        ctx.fill(Path(ellipseIn: glowRect), with: .radialGradient(glow, center: c, startRadius: 0, endRadius: r * 1.6))
        // three wobbling rings
        let damp: CGFloat = phase == .processing ? 0.5 : 1
        for i in 0..<3 {
            let k = Double(i)
            let amp: CGFloat = r * (0.06 + 0.22 * lv) * damp
            let path = ring(center: c, radius: r * CGFloat(1 - 0.08 * k), amp: amp, k: k, t: t)
            let color = colors[i % colors.count].opacity(0.85 - 0.2 * k)
            ctx.stroke(path, with: .color(color), lineWidth: CGFloat(3 - k * 0.6))
        }
        // spinner dots while processing
        if phase == .processing {
            for i in 0..<8 {
                let a = t * 3 + Double(i) * .pi / 4
                let p = CGPoint(x: c.x + CGFloat(cos(a)) * r * 0.55, y: c.y + CGFloat(sin(a)) * r * 0.55)
                let dot = CGRect(x: p.x - 3, y: p.y - 3, width: 6, height: 6)
                ctx.fill(Path(ellipseIn: dot), with: .color(forest.opacity(0.3 + 0.08 * Double(i))))
            }
        }
    }

    private func ring(center c: CGPoint, radius: CGFloat, amp: CGFloat, k: Double, t: Double) -> Path {
        var path = Path()
        var s = 0.0
        var first = true
        while s <= 2 * Double.pi {
            let w1 = sin(s * (3 + k) + t * (1.4 + 0.5 * k))
            let w2 = cos(s * 2 - t * 0.9) * 0.5
            let rr = radius + CGFloat(w1 + w2) * amp
            let p = CGPoint(x: c.x + CGFloat(cos(s)) * rr, y: c.y + CGFloat(sin(s)) * rr)
            if first { path.move(to: p); first = false } else { path.addLine(to: p) }
            s += Double.pi / 90
        }
        path.closeSubpath()
        return path
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
