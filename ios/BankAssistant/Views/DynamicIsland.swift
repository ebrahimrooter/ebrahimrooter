import SwiftUI
import UIKit

/// Dynamic Island–style notifications inside the app. A port of expo-dynamic-notifications
/// (github.com/rit3zh/expo-dynamic-notifications, MIT) — the same geometry, springs and timing
/// as the web app's server/app/vendor/island.js: a droplet falls out of the island on a gooey
/// neck, grows into a card, the content comes in from a blur; tap = action, swipe up = dismiss.
///
///     IslandCenter.shared.trigger(.init(title: "واریز ۵۰۰٬۰۰۰ تومان", message: "بانک ملی", accent: .green))
struct IslandNote: Identifiable {
    let id = UUID()
    var title: String
    var message: String = ""
    var symbol: String = "bell.fill"
    var accent: Color = Color(red: 0.18, green: 0.61, blue: 1)
    var duration: Double? = 3.6          // seconds; nil = until swiped or tapped
    var action: String? = nil            // a button on the card
    var onPress: (() -> Void)? = nil
}

private enum K {
    static let islandW: CGFloat = 126, islandH: CGFloat = 37.33, islandTop: CGFloat = 11, islandMinTop: CGFloat = 12
    static let cardMaxW: CGFloat = 396, cardH: CGFloat = 74, cardMargin: CGFloat = 16, cardGap: CGFloat = 34, cardR: CGFloat = 26
    static let drop: CGFloat = 52, neckW: CGFloat = 60, edge: CGFloat = 10
    static let gooBlur: CGFloat = 9, gooGain: Float = 22, gooThreshold: Float = 0.43, gooInset: CGFloat = 0.26
    static let growSpan = 0.7, growPower = 1.25, stretch = 0.38, neckBreak = 0.82, neckRise = 1.6, neckFall = 1.4
    static let tintStart = 0.06, tintEnd = 0.88, minScale = 0.88
    static let enterTint = 0.11, enterExpand = 0.34, enterReveal = 0.56, exitCollapse = 0.10, exitDrop = 0.28
    static let swipeDist: CGFloat = -18, swipeVel: CGFloat = -420
}

/// A value moved by a damped spring (duration + damping ratio), evaluated at any time.
private struct Spring1 {
    var from = 0.0, to = 0.0, vel = 0.0, start = 0.0, dur = 1.0, zeta = 1.0

    func value(_ t: Double) -> Double {
        guard t > start else { return from }
        let s = t - start
        if s >= dur + 0.08 { return to }
        let x0 = from - to
        let w0 = zeta < 1 ? 4.6 / (zeta * dur) : 6.6 / dur
        if zeta < 1 {
            let wd = w0 * (1 - zeta * zeta).squareRoot(), e = exp(-zeta * w0 * s)
            return to + e * (x0 * cos(wd * s) + (-vel + zeta * w0 * x0) / wd * sin(wd * s))
        }
        return to + (x0 + (-vel + w0 * x0) * s) * exp(-w0 * s)
    }
    func velocity(_ t: Double) -> Double { (value(t + 0.004) - value(t)) / 0.004 }
    var end: Double { start + dur + 0.08 }

    static func move(_ cur: Spring1, to target: Double, now: Double, delay: Double = 0, _ spec: (Double, Double, Double)) -> Spring1 {
        let v = cur.value(now)
        return Spring1(from: v, to: target, vel: cur.velocity(now) + spec.2 * (target - v), start: now + delay, dur: spec.0, zeta: spec.1)
    }
}

private enum Springs {
    static let drop = (1.15, 0.82, 0.0), expand = (1.0, 0.8, 0.0), reveal = (0.7, 1.0, 0.0), tint = (0.7, 1.0, 0.0)
    static let collapse = (0.66, 0.92, 2.0), ret = (1.15, 0.9, 0.0), fade = (0.36, 1.0, 0.0), drag = (0.56, 0.7, 0.0)
}

@MainActor
@Observable
final class IslandCenter {
    static let shared = IslandCenter()

    private(set) var note: IslandNote?
    fileprivate var drop = Spring1()
    fileprivate var expand = Spring1()
    fileprivate var reveal = Spring1()
    fileprivate var tint = Spring1()
    fileprivate var drag = Spring1()
    fileprivate var dragLive: CGFloat?
    private var queued: IslandNote?
    private(set) var exiting = false
    private var timer: Task<Void, Never>?
    private var window: IslandWindow?

    var active: Bool { note != nil && !exiting }

    static var now: Double { CACurrentMediaTime() }

    func trigger(_ n: IslandNote) {
        attach()
        if note != nil { queued = n; exit(); return }
        enter(n)
    }

    func dismiss() { queued = nil; exit() }

    private func enter(_ n: IslandNote) {
        let t = Self.now
        note = n
        exiting = false
        drop = Spring1(); expand = Spring1(); reveal = Spring1(); tint = Spring1(); drag = Spring1(); dragLive = nil
        drop = .move(drop, to: 1, now: t, Springs.drop)
        tint = .move(tint, to: 1, now: t, delay: K.enterTint, Springs.tint)
        expand = .move(expand, to: 1, now: t, delay: K.enterExpand, Springs.expand)
        reveal = .move(reveal, to: 1, now: t, delay: K.enterReveal, Springs.reveal)
        UIImpactFeedbackGenerator(style: .light).impactOccurred()
        arm(n.duration.map { K.enterReveal + $0 })
        window?.isHidden = false
    }

    fileprivate func arm(_ seconds: Double?) {
        timer?.cancel()
        guard let seconds else { return }
        timer = Task { [weak self] in
            try? await Task.sleep(for: .seconds(seconds))
            guard !Task.isCancelled else { return }
            self?.exit()
        }
    }

    fileprivate func exit() {
        guard note != nil, !exiting else { return }
        exiting = true
        timer?.cancel()
        let t = Self.now
        reveal = .move(reveal, to: 0, now: t, Springs.fade)
        expand = .move(expand, to: 0, now: t, delay: K.exitCollapse, Springs.collapse)
        tint = .move(tint, to: 0, now: t, delay: K.exitDrop, Springs.ret)
        drop = .move(drop, to: 0, now: t, delay: K.exitDrop, Springs.ret)
        if dragLive != nil { drag = Spring1(from: Double(dragLive ?? 0), to: 0, start: t, dur: Springs.drag.0, zeta: Springs.drag.1); dragLive = nil }
        let done = drop.end - t
        Task { [weak self] in
            try? await Task.sleep(for: .seconds(done))
            self?.settle()
        }
    }

    private func settle() {
        let next = queued
        queued = nil
        note = nil
        exiting = false
        if let next { enter(next) } else { window?.isHidden = true }
    }

    fileprivate func tap() {
        guard let n = note, !exiting else { return }
        dismiss()
        n.onPress?()
    }

    fileprivate func dragEnded(_ dy: CGFloat, velocity: CGFloat) {
        let t = Self.now
        drag = Spring1(from: Double(dragLive ?? 0), to: 0, start: t, dur: Springs.drag.0, zeta: Springs.drag.1)
        dragLive = nil
        if dy < K.swipeDist || velocity < K.swipeVel { dismiss() } else { arm(2.5) }
    }

    /// The island lives in its own window above everything (sheets included), touchable only on the card.
    private func attach() {
        guard window == nil,
              let scene = UIApplication.shared.connectedScenes.compactMap({ $0 as? UIWindowScene })
                .first(where: { $0.activationState == .foregroundActive }) ?? UIApplication.shared.connectedScenes.first as? UIWindowScene
        else { return }
        let w = IslandWindow(windowScene: scene)
        w.windowLevel = .alert + 1
        w.backgroundColor = .clear
        let host = UIHostingController(rootView: IslandOverlay().environment(\.layoutDirection, .rightToLeft))
        host.view.backgroundColor = .clear
        w.rootViewController = host
        w.isHidden = true
        window = w
    }

    /// Where the card is right now (for touches); set by the overlay.
    @ObservationIgnored fileprivate var cardFrame: CGRect = .zero
}

private final class IslandWindow: UIWindow {
    override func hitTest(_ point: CGPoint, with event: UIEvent?) -> UIView? {
        let c = IslandCenter.shared
        return c.active && c.cardFrame.insetBy(dx: -6, dy: -6).contains(point) ? super.hitTest(point, with: event) : nil
    }
}

private struct Layout {
    var width: CGFloat, centerX: CGFloat, islandTop: CGFloat, islandBottom: CGFloat
    var cardW: CGFloat, cardH: CGFloat, cardTop: CGFloat, cardCY: CGFloat, cardR: CGFloat

    init(size: CGSize, insetTop: CGFloat) {
        width = size.width
        centerX = size.width / 2
        islandTop = max(insetTop - K.islandH - K.islandTop, K.islandMinTop)
        islandBottom = islandTop + K.islandH
        cardW = min(size.width - K.cardMargin * 2, K.cardMaxW)
        cardH = K.cardH
        cardTop = islandBottom + K.cardGap
        cardCY = cardTop + cardH / 2
        cardR = min(K.cardR, cardH / 2)
    }
}

private struct Geo { var rect: CGRect; var r: CGFloat; var neck: CGRect }

private func neckProfile(_ p: Double) -> Double {
    let t = min(max(p, 0), 1)
    guard t > 0, t < 1 else { return 0 }
    let peak = K.neckRise / (K.neckRise + K.neckFall)
    return pow(t, K.neckRise) * pow(1 - t, K.neckFall) / (pow(peak, K.neckRise) * pow(1 - peak, K.neckFall))
}

private func geometry(_ L: Layout, drop: Double, expand: Double, dragY: CGFloat) -> Geo {
    let grow = 1 - pow(1 - min(max(drop / K.growSpan, 0), 1), K.growPower)
    let nk = neckProfile(drop / K.neckBreak)
    let stretch = 1 + K.stretch * nk, droplet = Double(K.drop) * grow
    let e = CGFloat(expand)
    let w = min(CGFloat(droplet / stretch) + (L.cardW - CGFloat(droplet / stretch)) * e, L.width - K.edge * 2)
    let h = CGFloat(droplet * stretch) + (L.cardH - CGFloat(droplet * stretch)) * e
    let r = min(CGFloat(droplet * 0.5) + (L.cardR - CGFloat(droplet * 0.5)) * e, min(w, h) / 2)
    let originY = L.islandBottom - K.islandH * 0.34
    let cy = originY + (L.cardCY - originY) * CGFloat(drop) + min(0, dragY)
    let nw = min(K.neckW, max(w, 0)) * CGFloat(nk), neckY = L.islandBottom - K.islandH * 0.5
    return Geo(rect: CGRect(x: L.centerX - w / 2, y: cy - h / 2, width: max(w, 0), height: max(h, 0)), r: max(r, 0),
               neck: CGRect(x: L.centerX - nw / 2, y: neckY, width: nw, height: max(cy - neckY, 0)))
}

struct IslandOverlay: View {
    @State private var center = IslandCenter.shared

    var body: some View {
        GeometryReader { proxy in
            let L = Layout(size: proxy.size, insetTop: proxy.safeAreaInsets.top)
            TimelineView(.animation(paused: center.note == nil)) { tl in
                let _ = tl.date
                let t = IslandCenter.now
                let dragY = center.dragLive ?? CGFloat(center.drag.value(t))
                let g = geometry(L, drop: center.drop.value(t), expand: center.expand.value(t), dragY: dragY)
                let reveal = min(max(center.reveal.value(t), 0), 1)
                let tint = min(max((center.tint.value(t) - K.tintStart) / (K.tintEnd - K.tintStart), 0), 1)
                ZStack(alignment: .topLeading) {
                    Canvas { ctx, _ in draw(ctx, L, g, tint: tint, shadow: min(max(center.expand.value(t), 0), 1)) }
                    if let n = center.note {
                        card(n)
                            .frame(width: L.cardW, height: L.cardH)
                            .scaleEffect(CGFloat(K.minScale + (1 - K.minScale) * reveal) * min(1, max(g.rect.width / max(L.cardW, 1), 0.01)))
                            .opacity(reveal)
                            .blur(radius: CGFloat((1 - reveal) * 10))
                            .position(x: L.centerX, y: g.rect.midY)
                            .onAppear { center.cardFrame = g.rect }
                            .onChange(of: g.rect) { _, r in center.cardFrame = r }
                            .gesture(DragGesture(minimumDistance: 0)
                                .onChanged { v in center.dragLive = v.translation.height < 0 ? v.translation.height : v.translation.height * 0.15; center.arm(nil) }
                                .onEnded { v in
                                    if abs(v.translation.height) < 6 && abs(v.translation.width) < 6 { center.dragLive = nil; center.tap() }
                                    else { center.dragEnded(v.translation.height, velocity: v.velocity.height) }
                                })
                    }
                }
            }
            .ignoresSafeArea()
        }
        .ignoresSafeArea()
    }

    private func draw(_ ctx: GraphicsContext, _ L: Layout, _ g: Geo, tint: Double, shadow: Double) {
        // soft shadow under the card
        ctx.drawLayer { s in
            s.opacity = shadow
            s.addFilter(.shadow(color: Color(red: 16 / 255, green: 19 / 255, blue: 28 / 255).opacity(0.2), radius: 7, x: 0, y: 10))
            s.fill(Path(roundedRect: g.rect, cornerRadius: g.r), with: .color(.white))
        }
        // the goo: blur, then a steep alpha curve (filters run last-added first)
        ctx.drawLayer { goo in
            var m = ColorMatrix()
            m.a4 = K.gooGain
            m.a5 = -K.gooGain * K.gooThreshold
            goo.addFilter(.colorMatrix(m))
            goo.addFilter(.blur(radius: K.gooBlur))
            let inset = K.gooBlur * K.gooInset
            let pill = CGRect(x: L.centerX - K.islandW / 2, y: L.islandTop, width: K.islandW, height: K.islandH)
            goo.fill(Path(roundedRect: pill.insetBy(dx: inset, dy: inset), cornerRadius: max(K.islandH / 2 - inset, 0)), with: .color(.black))
            goo.fill(Path(roundedRect: g.neck, cornerRadius: g.neck.width / 2), with: .color(.black))
            let shade = 1 - tint   // black → white
            goo.fill(Path(roundedRect: g.rect, cornerRadius: g.r), with: .color(Color(white: 1 - shade)))
        }
        // the island itself, sharp, on top (sits on the real one on iPhones that have it)
        let pill = CGRect(x: L.centerX - K.islandW / 2, y: L.islandTop, width: K.islandW, height: K.islandH)
        ctx.fill(Path(roundedRect: pill, cornerRadius: K.islandH / 2), with: .color(.black))
    }

    private func card(_ n: IslandNote) -> some View {
        HStack(spacing: 12) {
            Image(systemName: n.symbol)
                .font(.system(size: 18, weight: .bold))
                .foregroundStyle(.white)
                .frame(width: 42, height: 42)
                .background(Circle().fill(n.accent))
            VStack(alignment: .leading, spacing: 2) {
                Text(n.title).font(.system(size: 15, weight: .heavy)).foregroundStyle(.black).lineLimit(1)
                if !n.message.isEmpty {
                    Text(n.message).font(.system(size: 13)).foregroundStyle(Color(red: 0.47, green: 0.47, blue: 0.49)).lineLimit(1)
                }
            }
            .frame(maxWidth: .infinity, alignment: .leading)
            if let a = n.action {
                Button(a) { center.tap() }
                    .font(.system(size: 13, weight: .bold))
                    .foregroundStyle(.white)
                    .padding(.horizontal, 14).padding(.vertical, 8)
                    .background(Capsule().fill(n.accent))
            }
        }
        .padding(.horizontal, 16)
        .contentShape(Rectangle())
    }
}
