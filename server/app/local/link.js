/*
 * ESP32 → this phone, directly (no Bale, no server).
 *
 * The ESP32 (firmware/app_direct) puts each bank SMS, sealed with the pairing code, on a public
 * MQTT broker as a retained message; the app reads it there over a WebSocket, books it and answers
 * with an «ack», and only then does the ESP32 delete the SMS from the SIM. The broker sees only
 * sealed bytes and a topic name derived from the code; anyone without the code can read nothing.
 *
 *   topics   ba/<id>/sms/<msg id>   one retained message per SMS still on the SIM (empty = gone)
 *            ba/<id>/ack            retained, from the app: the ids it has booked
 *            ba/<id>/st             retained, from the ESP32: signal, SMS waiting, firmware
 *   sealing  master = SHA-256("ba-link-v1:" + code); ke/km/topic = HMAC(master, "enc"/"mac"/"topic")
 *            ct = pt XOR HMAC(ke, iv ‖ be32(block)) per 32 bytes, tag = HMAC(km, iv ‖ ct)[0..16]
 *            payload = base64(iv[12] ‖ ct ‖ tag)        (same code in firmware/app_direct)
 */
(function (root, factory) {
  if (typeof module === 'object' && module.exports) module.exports = factory();
  else root.BALink = factory();
})(typeof self !== 'undefined' ? self : this, function () {
  'use strict';

  /* ---------------------------------------------------------------- SHA-256, HMAC */

  var K = new Uint32Array([
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2]);

  function sha256(msg) {
    var n = msg.length, total = ((n + 9 + 63) >> 6) << 6, m = new Uint8Array(total);
    m.set(msg);
    m[n] = 0x80;
    var bits = n * 8;
    m[total - 4] = bits >>> 24; m[total - 3] = bits >>> 16; m[total - 2] = bits >>> 8; m[total - 1] = bits;
    m[total - 5] = Math.floor(n / 0x20000000);
    var h = new Uint32Array([0x6a09e667, 0xbb67ae85, 0x3c6ef372, 0xa54ff53a, 0x510e527f, 0x9b05688c, 0x1f83d9ab, 0x5be0cd19]);
    var w = new Uint32Array(64);
    for (var off = 0; off < total; off += 64) {
      for (var i = 0; i < 16; i++) w[i] = (m[off + i * 4] << 24) | (m[off + i * 4 + 1] << 16) | (m[off + i * 4 + 2] << 8) | m[off + i * 4 + 3];
      for (i = 16; i < 64; i++) {
        var a = w[i - 15], b = w[i - 2];
        var s0 = ((a >>> 7) | (a << 25)) ^ ((a >>> 18) | (a << 14)) ^ (a >>> 3);
        var s1 = ((b >>> 17) | (b << 15)) ^ ((b >>> 19) | (b << 13)) ^ (b >>> 10);
        w[i] = (w[i - 16] + s0 + w[i - 7] + s1) | 0;
      }
      var A = h[0], B = h[1], C = h[2], D = h[3], E = h[4], F = h[5], G = h[6], H = h[7];
      for (i = 0; i < 64; i++) {
        var S1 = ((E >>> 6) | (E << 26)) ^ ((E >>> 11) | (E << 21)) ^ ((E >>> 25) | (E << 7));
        var t1 = (H + S1 + ((E & F) ^ (~E & G)) + K[i] + w[i]) | 0;
        var S0 = ((A >>> 2) | (A << 30)) ^ ((A >>> 13) | (A << 19)) ^ ((A >>> 22) | (A << 10));
        var t2 = (S0 + ((A & B) ^ (A & C) ^ (B & C))) | 0;
        H = G; G = F; F = E; E = (D + t1) | 0; D = C; C = B; B = A; A = (t1 + t2) | 0;
      }
      h[0] += A; h[1] += B; h[2] += C; h[3] += D; h[4] += E; h[5] += F; h[6] += G; h[7] += H;
    }
    var out = new Uint8Array(32);
    for (i = 0; i < 8; i++) { out[i * 4] = h[i] >>> 24; out[i * 4 + 1] = h[i] >>> 16; out[i * 4 + 2] = h[i] >>> 8; out[i * 4 + 3] = h[i]; }
    return out;
  }

  function concat() {
    var n = 0, i;
    for (i = 0; i < arguments.length; i++) n += arguments[i].length;
    var out = new Uint8Array(n), p = 0;
    for (i = 0; i < arguments.length; i++) { out.set(arguments[i], p); p += arguments[i].length; }
    return out;
  }

  function hmac(key, msg) {
    if (key.length > 64) key = sha256(key);
    var k = new Uint8Array(64), ipad = new Uint8Array(64), opad = new Uint8Array(64);
    k.set(key);
    for (var i = 0; i < 64; i++) { ipad[i] = k[i] ^ 0x36; opad[i] = k[i] ^ 0x5c; }
    return sha256(concat(opad, sha256(concat(ipad, msg))));
  }

  function utf8(s) { return new TextEncoder().encode(s); }
  function fromUtf8(b) { return new TextDecoder().decode(b); }
  function hex(b) { var s = ''; for (var i = 0; i < b.length; i++) s += (b[i] < 16 ? '0' : '') + b[i].toString(16); return s; }
  function b64(b) { var s = ''; for (var i = 0; i < b.length; i++) s += String.fromCharCode(b[i]); return btoa(s); }
  function unb64(s) { var t = atob(s), b = new Uint8Array(t.length); for (var i = 0; i < t.length; i++) b[i] = t.charCodeAt(i); return b; }

  /* ---------------------------------------------------------------- the pairing code */

  var ALPHA = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
  /** 16 characters (about 79 bits), shown as XXXX-XXXX-XXXX-XXXX. */
  function newCode(random) {
    var r = random || function (n) { var a = new Uint8Array(n); crypto.getRandomValues(a); return a; };
    var bytes = r(16), s = '';
    for (var i = 0; i < 16; i++) s += ALPHA[bytes[i] % ALPHA.length];
    return s;
  }
  function cleanCode(c) { return String(c || '').toUpperCase().replace(/[^A-Z0-9]/g, ''); }
  function showCode(c) { return cleanCode(c).replace(/(.{4})(?=.)/g, '$1-'); }

  function keys(code) {
    var master = sha256(utf8('ba-link-v1:' + cleanCode(code)));
    return { ke: hmac(master, utf8('enc')), km: hmac(master, utf8('mac')), id: hex(hmac(master, utf8('topic'))).slice(0, 16) };
  }

  function stream(ke, iv, data) {
    var out = new Uint8Array(data.length), ctr = new Uint8Array(4);
    for (var blk = 0; blk * 32 < data.length; blk++) {
      ctr[0] = blk >>> 24; ctr[1] = blk >>> 16; ctr[2] = blk >>> 8; ctr[3] = blk;
      var ks = hmac(ke, concat(iv, ctr));
      for (var i = 0; i < 32 && blk * 32 + i < data.length; i++) out[blk * 32 + i] = data[blk * 32 + i] ^ ks[i];
    }
    return out;
  }

  function seal(k, obj, iv) {
    if (!iv) { iv = new Uint8Array(12); crypto.getRandomValues(iv); }
    var ct = stream(k.ke, iv, utf8(JSON.stringify(obj)));
    return b64(concat(iv, ct, hmac(k.km, concat(iv, ct)).slice(0, 16)));
  }

  /** The object, or null when it was not sealed with this code (or changed on the way). */
  function open(k, text) {
    var b;
    try { b = unb64(String(text).trim()); } catch (e) { return null; }
    if (b.length < 12 + 16) return null;
    var iv = b.slice(0, 12), ct = b.slice(12, b.length - 16), tag = b.slice(b.length - 16);
    var want = hmac(k.km, concat(iv, ct)), diff = 0;
    for (var i = 0; i < 16; i++) diff |= want[i] ^ tag[i];
    if (diff) return null;
    try { return JSON.parse(fromUtf8(stream(k.ke, iv, ct))); } catch (e) { return null; }
  }

  /* ---------------------------------------------------------------- MQTT 3.1.1 over WebSocket */

  function str(s) { var b = utf8(s); return concat(new Uint8Array([b.length >> 8, b.length & 255]), b); }
  function packet(type, body) {
    var len = [], n = body.length;
    do { var d = n % 128; n = Math.floor(n / 128); len.push(n ? d | 128 : d); } while (n);
    return concat(new Uint8Array([type]), new Uint8Array(len), body);
  }

  /**
   * A small MQTT client: connect, subscribe, publish (QoS 0, retained or not), keep alive, reconnect.
   * opts: { url, clientId, topics:[...], onMessage(topic, bytes), onState(text), socket (tests) }
   */
  function Client(opts) {
    this.o = opts;
    this.ws = null; this.buf = new Uint8Array(0); this.up = false; this.timer = null; this.ping = null; this.wait = 2000; this.closed = false;
    this.pid = 1;
  }
  Client.prototype.start = function () {
    var self = this, o = this.o;
    this.closed = false;
    clearTimeout(this.timer);
    var ws;
    try { ws = o.socket ? o.socket(o.url) : new WebSocket(o.url, ['mqtt']); } catch (e) { return this.retry(e.message); }
    this.ws = ws;
    ws.binaryType = 'arraybuffer';
    ws.onopen = function () {
      var body = concat(str('MQTT'), new Uint8Array([4, 0x02, 0, 60]), str(o.clientId));
      ws.send(packet(0x10, body));
    };
    ws.onmessage = function (ev) { self.feed(new Uint8Array(ev.data)); };
    ws.onerror = function () { /* onclose follows */ };
    ws.onclose = function () { self.down('اتصال قطع شد'); };
  };
  Client.prototype.retry = function (why) {
    var self = this;
    if (this.closed) return;
    if (this.o.onState) this.o.onState('offline', why);
    clearTimeout(this.timer);
    this.timer = setTimeout(function () { self.start(); }, this.wait);
    this.wait = Math.min(this.wait * 2, 60000);
  };
  Client.prototype.down = function (why) {
    this.up = false;
    clearInterval(this.ping);
    this.ws = null;
    this.retry(why);
  };
  Client.prototype.stop = function () {
    this.closed = true;
    clearTimeout(this.timer); clearInterval(this.ping);
    if (this.ws) try { this.ws.send(new Uint8Array([0xe0, 0])); this.ws.close(); } catch (e) { /* gone */ }
    this.ws = null; this.up = false;
  };
  Client.prototype.publish = function (topic, text, retain) {
    if (!this.up) return false;
    this.ws.send(packet(0x30 | (retain ? 1 : 0), concat(str(topic), utf8(text || ''))));
    return true;
  };
  Client.prototype.feed = function (chunk) {
    this.buf = concat(this.buf, chunk);
    for (;;) {
      var b = this.buf;
      if (b.length < 2) return;
      var len = 0, mul = 1, i = 1;
      for (; i < 5; i++) {
        if (i >= b.length) return;
        len += (b[i] & 127) * mul;
        mul *= 128;
        if (!(b[i] & 128)) break;
      }
      var start = i + 1;
      if (b.length < start + len) return;
      this.handle(b[0], b.slice(start, start + len));
      this.buf = b.slice(start + len);
    }
  };
  Client.prototype.handle = function (head, body) {
    var self = this, o = this.o, type = head >> 4;
    if (type === 2) {                                   // CONNACK
      if (body[1] !== 0) { this.closed = true; if (o.onState) o.onState('refused', 'broker: ' + body[1]); return; }
      this.up = true; this.wait = 2000;
      var subs = new Uint8Array(0);
      o.topics.forEach(function (t) { subs = concat(subs, str(t), new Uint8Array([0])); });
      this.ws.send(packet(0x82, concat(new Uint8Array([0, this.pid++ & 255 || 1]), subs)));
      clearInterval(this.ping);
      this.ping = setInterval(function () { if (self.up) try { self.ws.send(new Uint8Array([0xc0, 0])); } catch (e) { /* closing */ } }, 45000);
      if (o.onState) o.onState('online');
    } else if (type === 3) {                            // PUBLISH
      var tl = (body[0] << 8) | body[1], topic = fromUtf8(body.slice(2, 2 + tl)), p = 2 + tl, qos = (head >> 1) & 3;
      if (qos) { this.ws.send(new Uint8Array([0x40, 2, body[p], body[p + 1]])); p += 2; }
      if (o.onMessage) o.onMessage(topic, body.slice(p), !!(head & 1));
    }
  };

  /* ---------------------------------------------------------------- a broker in the page (tests, demo) */

  function matches(filter, topic) {
    var f = filter.split('/'), t = topic.split('/');
    for (var i = 0; i < f.length; i++) {
      if (f[i] === '#') return true;
      if (i >= t.length || (f[i] !== '+' && f[i] !== t[i])) return false;
    }
    return f.length === t.length;
  }

  /**
   * An in-memory MQTT broker with WebSocket-like sockets: what the app sees on a real one.
   * With [demo], it holds two bank SMS sealed with [code], the way the ESP32 leaves them.
   */
  function fakeBroker(code, demo) {
    var B = { retained: {}, published: [], subs: [] };
    function send(sub, topic, payload, retain) {
      sub.ws.onmessage({ data: packet(0x30 | (retain ? 1 : 0), concat(str(topic), payload)).buffer });
    }
    B.publish = function (topic, text, retain) {
      var payload = utf8(text || '');
      if (retain) { if (payload.length) B.retained[topic] = payload; else delete B.retained[topic]; }
      B.published.push({ topic: topic, text: text, retain: !!retain });
      B.subs.forEach(function (s) { if (s.filters.some(function (f) { return matches(f, topic); })) send(s, topic, payload, false); });
    };
    B.socket = function () {
      var ws = { readyState: 1 }, sub = { ws: ws, filters: [] }, buf = new Uint8Array(0);
      ws.send = function (data) {
        buf = concat(buf, new Uint8Array(data.buffer ? data.buffer.slice(data.byteOffset, data.byteOffset + data.byteLength) : data));
        while (buf.length >= 2) {
          var len = 0, mul = 1, i = 1;
          for (; i < 5 && i < buf.length; i++) { len += (buf[i] & 127) * mul; mul *= 128; if (!(buf[i] & 128)) break; }
          var start = i + 1;
          if (buf.length < start + len) return;
          var head = buf[0], body = buf.slice(start, start + len);
          buf = buf.slice(start + len);
          var type = head >> 4;
          if (type === 1) setTimeout(function () { ws.onmessage({ data: new Uint8Array([0x20, 2, 0, 0]).buffer }); }, 5);
          else if (type === 8) {
            var p = 2, got = [];
            while (p < body.length) {
              var l = (body[p] << 8) | body[p + 1], f = new TextDecoder().decode(body.slice(p + 2, p + 2 + l));
              p += 3 + l; got.push(f); sub.filters.push(f);
            }
            if (B.subs.indexOf(sub) === -1) B.subs.push(sub);
            setTimeout(function () {
              Object.keys(B.retained).forEach(function (t) { if (got.some(function (f) { return matches(f, t); })) send(sub, t, B.retained[t], true); });
            }, 5);
          } else if (type === 3) {
            var tl = (body[0] << 8) | body[1];
            B.publish(new TextDecoder().decode(body.slice(2, 2 + tl)), new TextDecoder().decode(body.slice(2 + tl)), !!(head & 1));
          } else if (type === 14) { ws.close(); }
        }
      };
      ws.close = function () {
        B.subs = B.subs.filter(function (x) { return x !== sub; });
        if (ws.readyState === 1) { ws.readyState = 3; setTimeout(function () { if (ws.onclose) ws.onclose({}); }, 0); }
      };
      setTimeout(function () { if (ws.onopen) ws.onopen({}); }, 5);
      return ws;
    };
    if (demo && code) {
      var k = keys(code), at = 'ba/' + k.id + '/';
      var sms = [
        { id: 'demo0000000000a1', from: 'Bank Mellat', when: '', text: 'بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0718-14:25' },
        { id: 'demo0000000000a2', from: 'Bank Melli', when: '', text: 'بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n1405/07/20 09:12' }];
      sms.forEach(function (m) { B.retained[at + 'sms/' + m.id] = utf8(seal(k, m)); });
      B.retained[at + 'st'] = utf8(seal(k, { v: 1, sig: 18, wait: 2, fw: 'demo' }));
      // like the ESP32: an «ack» deletes the SMS (clears its retained message)
      B.subs.push({ ws: { onmessage: function (ev) {
        var b = new Uint8Array(ev.data), h = 1;
        while (b[h] & 128) h++;
        var tl = (b[h + 1] << 8) | b[h + 2], topic = new TextDecoder().decode(b.slice(h + 3, h + 3 + tl));
        if (topic !== at + 'ack') return;
        var a = open(k, new TextDecoder().decode(b.slice(h + 3 + tl)));
        (a && a.ids || []).forEach(function (id) { if (B.retained[at + 'sms/' + id]) B.publish(at + 'sms/' + id, '', true); });
      } }, filters: [at + 'ack'] });
    }
    return B;
  }

  return { fakeBroker: fakeBroker, matches: matches, sha256: sha256, hmac: hmac, hex: hex, b64: b64, unb64: unb64, utf8: utf8, keys: keys, seal: seal, open: open,
    newCode: newCode, cleanCode: cleanCode, showCode: showCode, Client: Client, packet: packet, str: str };
});
