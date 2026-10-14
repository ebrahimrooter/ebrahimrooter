/*
 * Dynamic Island–style notifications for the web app (and the Android app's WebView).
 * A port of expo-dynamic-notifications (github.com/rit3zh/expo-dynamic-notifications, MIT):
 * a droplet falls out of the island, stretches on a gooey neck, grows into a card and the
 * content fades in from a blur; on exit it all flows back. Same geometry, springs and timing.
 *
 *   Island.trigger({ title, message, accent, icon, duration, onPress, actions })
 *   Island.dismiss()      Island.active()
 *
 * duration in ms (null = until swiped / tapped); icon: a short text or emoji in the accent circle.
 */
(function () {
  'use strict';
  var C = {
    ISLAND_W: 126, ISLAND_H: 37.33, ISLAND_TOP: 11, ISLAND_MIN_TOP: 12,
    CARD_MAX_W: 396, CARD_H: 74, CARD_MARGIN: 16, CARD_GAP: 34, CARD_R: 26,
    DROP: 52, NECK_W: 60, EDGE: 10,
    GOO_BLUR: 9, GOO_GAIN: 22, GOO_THRESHOLD: 0.43, GOO_INSET: 0.26,
    DROP_GROW_SPAN: 0.7, DROP_GROW_POWER: 1.25, DROP_STRETCH: 0.38,
    NECK_BREAK: 0.82, NECK_RISE: 1.6, NECK_FALL: 1.4, TINT_START: 0.06, TINT_END: 0.88,
    CONTENT_MIN_SCALE: 0.88, SHADOW_DY: 10, SHADOW_BLUR: 14,
    ENTER_TINT: 110, ENTER_EXPAND: 340, ENTER_REVEAL: 560, EXIT_COLLAPSE: 100, EXIT_DROP: 280,
    AUTO_DISMISS: 3600, SWIPE_DIST: -18, SWIPE_VEL: -420
  };
  var SPRING = {
    drop: [1150, 0.82], expand: [1000, 0.8], reveal: [700, 1], tint: [700, 1],
    collapse: [660, 0.92, 2], ret: [1150, 0.9], fade: [360, 1], drag: [560, 0.7]
  };
  var SVGNS = 'http://www.w3.org/2000/svg';
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  function clamp(v, a, b) { return Math.max(a, Math.min(b, v)); }
  function mix(t, a, b) { return a + (b - a) * t; }
  function easeOutPower(t, p) { return 1 - Math.pow(1 - t, p); }
  function neck(progress, rise, fall) {
    var t = clamp(progress, 0, 1);
    if (t <= 0 || t >= 1) return 0;
    var peak = rise / (rise + fall);
    return (Math.pow(t, rise) * Math.pow(1 - t, fall)) / (Math.pow(peak, rise) * Math.pow(1 - peak, fall));
  }

  /* A value moved by a damped spring (duration + damping ratio, like Reanimated's), with delays. */
  function Value(v) { this.v = v; this.a = null; }
  Value.prototype.to = function (target, spring, delay, done) {
    var from = this.v, vel = (this.a && this.a.vel) ? this.a.vel(performance.now()) : 0;
    if (spring[2]) vel += spring[2] * (target - from);
    var T = spring[0] / 1000, z = spring[1];
    var w0 = z < 1 ? 4.6 / (z * T) : 6.6 / T;     // settles within the given duration
    var start = performance.now() + (delay || 0), x0 = from - target, self = this;
    var a = {
      done: done || null,
      at: function (now) {
        var t = Math.max(0, (now - start) / 1000);
        if (now < start) return from;
        if (reduce) return target;
        if (z < 1) {
          var wd = w0 * Math.sqrt(1 - z * z), e = Math.exp(-z * w0 * t);
          return target + e * (x0 * Math.cos(wd * t) + ((-vel) + z * w0 * x0) / wd * Math.sin(wd * t));
        }
        return target + (x0 + ((-vel) + w0 * x0) * t) * Math.exp(-w0 * t);
      },
      vel: function (now) { var h = 0.004; return (a.at(now + h * 1000) - a.at(now)) / h; },
      end: start + spring[0] + 80
    };
    this.a = a;
    this.target = target;
    return this;
  };
  Value.prototype.tick = function (now) {
    var a = this.a;
    if (!a) return false;
    if (now >= a.end || reduce) {
      this.v = this.target; this.a = null;
      if (a.done) a.done();
      return false;
    }
    this.v = a.at(now);
    return true;
  };

  var S = {
    drop: new Value(0), expand: new Value(0), reveal: new Value(0), tint: new Value(0), drag: new Value(0),
    cur: null, queued: null, exiting: false, timer: null, raf: 0, built: false, el: {}
  };

  function el(tag, attrs, ns) {
    var e = ns ? document.createElementNS(SVGNS, tag) : document.createElement(tag);
    for (var k in attrs || {}) e.setAttribute(k, attrs[k]);
    return e;
  }

  function insetTop() {
    var p = document.createElement('div');
    p.style.cssText = 'position:fixed;top:0;height:env(safe-area-inset-top);visibility:hidden;pointer-events:none';
    document.body.appendChild(p);
    var h = p.getBoundingClientRect().height;
    p.remove();
    return h;
  }

  function layout() {
    var w = window.innerWidth, top = Math.max(insetTop() - C.ISLAND_H - C.ISLAND_TOP, C.ISLAND_MIN_TOP);
    var cardW = Math.min(w - C.CARD_MARGIN * 2, C.CARD_MAX_W), cardH = S.cardH || C.CARD_H;
    var bottom = top + C.ISLAND_H, cardTop = bottom + C.CARD_GAP;
    return {
      width: w, centerX: w / 2, islandTop: top, islandBottom: bottom, islandH: C.ISLAND_H, islandW: C.ISLAND_W,
      cardW: cardW, cardH: cardH, cardTop: cardTop, cardCY: cardTop + cardH / 2, cardR: Math.min(C.CARD_R, cardH / 2),
      canvasH: cardTop + cardH + 60
    };
  }

  function geometry(L, drop, expand, dragY) {
    var grow = easeOutPower(clamp(drop / C.DROP_GROW_SPAN, 0, 1), C.DROP_GROW_POWER);
    var nk = neck(drop / C.NECK_BREAK, C.NECK_RISE, C.NECK_FALL);
    var stretch = 1 + C.DROP_STRETCH * nk, droplet = C.DROP * grow;
    var width = Math.min(mix(expand, droplet / stretch, L.cardW), L.width - C.EDGE * 2);
    var height = mix(expand, droplet * stretch, L.cardH);
    var radius = Math.min(mix(expand, droplet * 0.5, L.cardR), Math.min(width, height) / 2);
    var originY = L.islandBottom - L.islandH * 0.34;
    var centerY = mix(drop, originY, L.cardCY) + Math.min(0, dragY);
    var nw = Math.min(C.NECK_W, width) * nk, neckY = L.islandBottom - L.islandH * 0.5;
    return {
      x: L.centerX - width / 2, y: centerY - height / 2, w: Math.max(0, width), h: Math.max(0, height), r: Math.max(0, radius),
      nx: L.centerX - nw / 2, ny: neckY, nw: nw, nh: Math.max(centerY - neckY, 0)
    };
  }

  function mixColor(t, a, b) {
    t = clamp((t - C.TINT_START) / (C.TINT_END - C.TINT_START), 0, 1);
    var pa = [parseInt(a.slice(1, 3), 16), parseInt(a.slice(3, 5), 16), parseInt(a.slice(5, 7), 16)];
    var pb = [parseInt(b.slice(1, 3), 16), parseInt(b.slice(3, 5), 16), parseInt(b.slice(5, 7), 16)];
    return 'rgb(' + pa.map(function (v, i) { return Math.round(mix(t, v, pb[i])); }).join(',') + ')';
  }

  function build() {
    if (S.built) return;
    S.built = true;
    var root = el('div', { id: 'island', 'aria-live': 'polite' });
    root.style.cssText = 'position:fixed;inset:0 0 auto 0;z-index:2147483000;pointer-events:none;direction:rtl';
    var svg = el('svg', { width: '100%', height: '0' }, true);
    svg.style.cssText = 'position:absolute;top:0;left:0;overflow:visible';
    var defs = el('defs', {}, true);
    var goo = el('filter', { id: 'islandGoo', x: '-50%', y: '-50%', width: '200%', height: '200%', 'color-interpolation-filters': 'sRGB' }, true);
    goo.appendChild(el('feGaussianBlur', { in: 'SourceGraphic', stdDeviation: String(C.GOO_BLUR), result: 'b' }, true));
    goo.appendChild(el('feColorMatrix', { in: 'b', mode: 'matrix', values: '1 0 0 0 0  0 1 0 0 0  0 0 1 0 0  0 0 0 ' + C.GOO_GAIN + ' ' + (-C.GOO_GAIN * C.GOO_THRESHOLD) }, true));
    var sh = el('filter', { id: 'islandShadow', x: '-30%', y: '-50%', width: '160%', height: '220%' }, true);
    sh.appendChild(el('feDropShadow', { dx: '0', dy: String(C.SHADOW_DY), stdDeviation: String(C.SHADOW_BLUR / 2), 'flood-color': 'rgb(16,19,28)', 'flood-opacity': '0.2' }, true));
    defs.appendChild(goo); defs.appendChild(sh); svg.appendChild(defs);
    var shadow = el('rect', { filter: 'url(#islandShadow)', fill: '#fff' }, true);
    var g = el('g', { filter: 'url(#islandGoo)' }, true);
    var pillBlur = el('rect', { fill: '#000' }, true), neckR = el('rect', { fill: '#000' }, true), drop = el('rect', {}, true);
    g.appendChild(pillBlur); g.appendChild(neckR); g.appendChild(drop);
    var pill = el('rect', { fill: '#000' }, true);
    svg.appendChild(shadow); svg.appendChild(g); svg.appendChild(pill);
    var card = el('div', { role: 'status' });
    card.style.cssText = 'position:absolute;display:flex;align-items:center;gap:12px;padding:0 16px;box-sizing:border-box;' +
      'pointer-events:auto;touch-action:none;cursor:pointer;font-family:inherit;color:#111;overflow:hidden;will-change:transform,opacity,filter';
    root.appendChild(svg); root.appendChild(card);
    document.body.appendChild(root);
    S.el = { root: root, svg: svg, shadow: shadow, pillBlur: pillBlur, neck: neckR, drop: drop, pill: pill, card: card };

    // tap = onPress; swipe up = dismiss; holding stops the countdown
    var y0 = null, t0 = 0, moved = false;
    card.addEventListener('pointerdown', function (e) {
      if (!S.cur || S.exiting) return;
      if (e.target.closest('button')) return;
      y0 = e.clientY; t0 = performance.now(); moved = false;
      clearTimeout(S.timer);
      card.setPointerCapture(e.pointerId);
    });
    card.addEventListener('pointermove', function (e) {
      if (y0 === null) return;
      var dy = e.clientY - y0;
      if (Math.abs(dy) > 6) moved = true;
      S.drag.v = dy < 0 ? dy : dy * 0.15; S.drag.a = null;
      loop();
    });
    var up = function (e) {
      if (y0 === null) return;
      var dy = e.clientY - y0, v = dy / Math.max(1, (performance.now() - t0) / 1000);
      y0 = null;
      if (!moved && S.cur) {
        var n = S.cur;
        dismiss();
        if (n.onPress) n.onPress();
        return;
      }
      if (dy < C.SWIPE_DIST || v < C.SWIPE_VEL) { dismiss(); return; }
      S.drag.to(0, SPRING.drag); loop();
      arm(S.cur, 2500);
    };
    card.addEventListener('pointerup', up);
    card.addEventListener('pointercancel', up);
    window.addEventListener('resize', function () { if (S.cur) draw(performance.now()); });
  }

  function set(e, a) { for (var k in a) e.setAttribute(k, a[k]); }

  function draw() {
    var L = S.L, E = S.el, g = geometry(L, S.drop.v, S.expand.v, S.drag.v), inset = C.GOO_BLUR * C.GOO_INSET;
    E.svg.setAttribute('height', String(L.canvasH));
    var ix = L.centerX - L.islandW / 2, ir = L.islandH / 2;
    set(E.pill, { x: ix, y: L.islandTop, width: L.islandW, height: L.islandH, rx: ir });
    set(E.pillBlur, { x: ix + inset, y: L.islandTop + inset, width: L.islandW - inset * 2, height: L.islandH - inset * 2, rx: Math.max(ir - inset, 0) });
    set(E.neck, { x: g.nx, y: g.ny, width: g.nw, height: g.nh, rx: g.nw / 2 });
    set(E.drop, { x: g.x, y: g.y, width: g.w, height: g.h, rx: g.r, fill: mixColor(S.tint.v, '#000000', '#ffffff') });
    set(E.shadow, { x: g.x, y: g.y, width: g.w, height: g.h, rx: g.r, opacity: clamp(S.expand.v, 0, 1) });
    var rv = clamp(S.reveal.v, 0, 1), ratio = L.cardW ? g.w / L.cardW : 0;
    var c = E.card.style;
    c.left = (L.centerX - L.cardW / 2) + 'px'; c.top = L.cardTop + 'px'; c.width = L.cardW + 'px'; c.height = L.cardH + 'px';
    c.borderRadius = L.cardR + 'px';
    c.transform = 'translateY(' + (g.y + g.h / 2 - L.cardCY) + 'px) scale(' + mix(rv, C.CONTENT_MIN_SCALE, 1) * Math.min(1, Math.max(ratio, 0.01)) + ')';
    c.opacity = String(rv);
    c.filter = rv < 0.99 ? 'blur(' + ((1 - rv) * 10).toFixed(1) + 'px)' : 'none';
    c.visibility = S.cur ? 'visible' : 'hidden';
    E.root.style.visibility = S.cur || S.exiting ? 'visible' : 'hidden';
  }

  function loop() {
    if (S.raf) return;
    var step = function (now) {
      S.raf = 0;
      var busy = false;
      ['drop', 'expand', 'reveal', 'tint', 'drag'].forEach(function (k) { if (S[k].tick(now)) busy = true; });
      if (S.cur) draw();
      if (busy) S.raf = requestAnimationFrame(step);
    };
    S.raf = requestAnimationFrame(step);
  }

  function esc(s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return '&#' + c.charCodeAt(0) + ';'; }); }

  function fill(n) {
    var card = S.el.card, accent = n.accent || '#2F9BFF';
    card.style.background = 'transparent';
    card.innerHTML =
      '<div style="flex:0 0 42px;height:42px;border-radius:50%;background:' + esc(accent) + ';color:#fff;display:grid;place-items:center;font-size:20px;font-weight:700">' + esc(n.icon || '🔔') + '</div>' +
      '<div style="flex:1;min-width:0;text-align:right">' +
      '<div style="font-weight:800;font-size:15px;line-height:1.35;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(n.title) + '</div>' +
      (n.message ? '<div style="font-size:13px;color:#77787D;line-height:1.4;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">' + esc(n.message) + '</div>' : '') +
      '</div>' +
      (n.actions || []).map(function (a, i) {
        return '<button type="button" data-i="' + i + '" style="flex:0 0 auto;border:0;border-radius:999px;padding:8px 14px;font:inherit;font-weight:700;font-size:13px;' +
          (i === 0 ? 'background:' + esc(accent) + ';color:#fff' : 'background:#E8E9EB;color:#111') + '">' + esc(a.label) + '</button>';
      }).join('');
    Array.prototype.forEach.call(card.querySelectorAll('button[data-i]'), function (b) {
      b.onclick = function (e) {
        e.stopPropagation();
        var a = n.actions[+b.getAttribute('data-i')];
        dismiss();
        if (a && a.onPress) a.onPress();
      };
    });
  }

  function arm(n, ms) {
    clearTimeout(S.timer);
    var life = ms !== undefined ? ms : (n.duration === undefined ? C.AUTO_DISMISS : n.duration);
    if (life !== null) S.timer = setTimeout(exit, (ms !== undefined ? 0 : C.ENTER_REVEAL) + life);
  }

  function enter(n) {
    build();
    S.cur = n; S.exiting = false;
    S.cardH = n.actions && n.actions.length ? 74 : C.CARD_H;
    S.L = layout();
    fill(n);
    ['drop', 'expand', 'reveal', 'tint', 'drag'].forEach(function (k) { S[k].v = 0; S[k].a = null; });
    S.drop.to(1, SPRING.drop);
    S.tint.to(1, SPRING.tint, C.ENTER_TINT);
    S.expand.to(1, SPRING.expand, C.ENTER_EXPAND);
    S.reveal.to(1, SPRING.reveal, C.ENTER_REVEAL);
    if (navigator.vibrate) try { navigator.vibrate(12); } catch (e) { /* not allowed */ }
    arm(n);
    draw(); loop();
  }

  function settle() {
    var gone = S.cur, next = S.queued;
    S.cur = null; S.queued = null; S.exiting = false;
    if (S.el.root) { S.el.root.style.visibility = 'hidden'; S.el.card.style.visibility = 'hidden'; }
    if (gone && gone.onDismiss) gone.onDismiss();
    if (next) enter(next);
  }

  function exit() {
    if (!S.cur || S.exiting) return;
    S.exiting = true;
    clearTimeout(S.timer);
    S.reveal.to(0, SPRING.fade);
    S.expand.to(0, SPRING.collapse, C.EXIT_COLLAPSE);
    S.tint.to(0, SPRING.ret, C.EXIT_DROP);
    S.drop.to(0, SPRING.ret, C.EXIT_DROP, settle);
    S.drag.to(0, SPRING.drag);
    loop();
  }

  function trigger(n) {
    if (!document.body) return;
    if (S.cur) { S.queued = n; exit(); return; }
    enter(n);
  }

  function dismiss() { S.queued = null; exit(); }

  window.Island = { trigger: trigger, dismiss: dismiss, active: function () { return !!S.cur && !S.exiting; }, _state: S, _geometry: geometry, _layout: layout };
})();
