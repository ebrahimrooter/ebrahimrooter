/*
 * The app without a server. Every request the phone web app sends to api.php
 * is answered here, from books kept on the phone itself, and the bank SMS
 * come straight from the ESP32:
 *
 *   bank SMS → SIM800 → ESP32 (firmware/app_direct), sealed with the pairing code
 *            → a public MQTT broker (only sealed bytes; local/link.js)
 *            → this app: books it on its card, asks «بابت چی بود؟», saves, makes the Excel file,
 *              and answers «got it» so the ESP32 deletes the SMS from the SIM.
 *
 * Native side (Android MainActivity / iOS LocalModeView), window.BANative:
 *   load() → saved books (string) · save(string) · rev()
 *   saveFile(id, name, mime, base64) → window.__baCb(id, ok, message)
 *   test builds: linkCode(), mqttUrl()
 * Without it (a plain browser, the tests) localStorage and a download are used.
 */
(function () {
  'use strict';
  var SMS = window.BASms, XLSX = window.BAXlsx, SEED = window.BA_SEED;
  var KEY = 'ba-local-books-v1';
  var SEP = '──────────';
  var N = window.BANative || null;
  var realFetch = window.fetch.bind(window);
  /** Test builds of the iPhone app keep these lines (Application Support/local-debug.txt). */
  function log(m) { try { if (N && N.log) N.log(String(m)); } catch (e) { /* no log */ } }

  /* ---------------------------------------------------------------- books */

  function fresh() {
    var maxW = 0;
    SEED.wallets.forEach(function (w) { maxW = Math.max(maxW, w.id); });
    var maxC = 0;
    SEED.categories.forEach(function (c) { maxC = Math.max(maxC, c.id); });
    return { v: 1, wallets: JSON.parse(JSON.stringify(SEED.wallets)), cats: JSON.parse(JSON.stringify(SEED.categories)),
      txs: [], parties: [], otps: [], sms: [], senders: [], next: { tx: 1, wallet: maxW + 1, cat: maxC + 1, otp: 1, sms: 1 },
      link: null, ctx: null, otp_pin: null,
      created: new Date().toISOString() };
  }
  function load() {
    var raw = null;
    try { raw = N && N.load ? N.load() : null; } catch (e) { raw = null; }
    if (!raw) try { raw = localStorage.getItem(KEY); } catch (e) { raw = null; }
    try { var s = raw ? JSON.parse(raw) : null; if (s && s.v === 1) return s; } catch (e) { /* broken: start again, keep a copy */ try { localStorage.setItem(KEY + '-broken', raw); } catch (e2) {} }
    return fresh();
  }
  /** Android: the app and the floating orb each run a copy of this engine on the same books file;
   *  N.rev() changes on every save, so a copy reads the file again before it works on stale books. */
  function rev() { try { return N && N.rev ? String(N.rev()) : null; } catch (e) { return null; } }
  var S = load(), seenRev = rev();
  var saveTimer = null;
  function save() {
    clearTimeout(saveTimer);
    if (seenRev !== null) return saveNow();   // shared file: no window for the other copy to miss it
    saveTimer = setTimeout(saveNow, 50);
  }
  function saveNow() {
    clearTimeout(saveTimer);
    saveTimer = null;
    var raw = JSON.stringify(S);
    try { if (N && N.save) N.save(raw); } catch (e) { /* fall back below */ }
    seenRev = rev();
    try { localStorage.setItem(KEY, raw); } catch (e) { /* full / private mode */ }
  }
  function refresh() {
    if (seenRev === null || saveTimer) return;
    var r = rev();
    if (r !== seenRev) { S = load(); seenRev = r; }
  }
  window.addEventListener('pagehide', saveNow);

  /* ---------------------------------------------------------------- helpers */

  function pad(n) { return String(n).padStart(2, '0'); }
  function isoDate(d) { d = d || new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
  function stamp(d) { d = d || new Date(); return isoDate(d) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds()); }
  function jalaliOf(iso) { return SMS.isoToJalali(iso.slice(0, 10)); }
  function toman(rial) { return Math.round(Math.abs(rial) / 10).toLocaleString('fa-IR') + ' تومان'; }
  function normText(s) { return SMS.normalize(s, true).replace(/[.,،!؟?]/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase(); }
  function json(body, status) {
    return Promise.resolve(new Response(JSON.stringify(body), { status: status || 200, headers: { 'Content-Type': 'application/json; charset=utf-8' } }));
  }
  function fail(msg, status) { return json({ ok: false, error: msg }, status || 400); }
  function bodyOf(init) {
    if (!init || !init.body) return {};
    if (typeof init.body === 'string') { try { return JSON.parse(init.body); } catch (e) { return {}; } }
    if (typeof FormData !== 'undefined' && init.body instanceof FormData) { var o = {}; init.body.forEach(function (v, k) { o[k] = v; }); return o; }
    return {};
  }
  function rialFrom(o, rialKey, tomanKey) {
    if (o[rialKey] != null && o[rialKey] !== '') return parseInt(SMS.normalize(String(o[rialKey])).replace(/[^\d-]/g, ''), 10) || 0;
    var t = SMS.normalize(String(o[tomanKey] == null ? '' : o[tomanKey])).replace(/[^\d.-]/g, '');
    return Math.round((parseFloat(t) || 0) * 10);
  }
  function cat(id) { id = +id; return S.cats.filter(function (c) { return c.id === id; })[0] || null; }
  function wallet(id) { id = +id; return S.wallets.filter(function (w) { return w.id === id; })[0] || null; }
  function tx(id) { id = +id; return S.txs.filter(function (t) { return t.id === id; })[0] || null; }
  function txOut(t) {
    var c = cat(t.category_id), w = wallet(t.wallet_id);
    return Object.assign({}, t, { category_name: c ? c.name : null, category_kind: c ? c.kind : null, wallet_name: w ? w.name : null });
  }

  /* ---------------------------------------------------------------- cards (wallets) */

  function wallets() {
    var now = Date.now();
    return S.wallets.slice().sort(function (a, b) { return (a.kind === 'cash') - (b.kind === 'cash') || a.id - b.id; }).map(function (w) {
      var bal = +w.opening || 0, bank = null, bankAt = '', pending = 0;
      S.txs.forEach(function (t) {
        if (t.wallet_id === w.id) {
          bal += t.direction === 'in' ? t.amount : -t.amount;
          if (t.status === 'pending') pending++;
          if (t.balance != null && (t.occurred_at + '|' + String(t.id).padStart(9, '0')) > bankAt) { bankAt = t.occurred_at + '|' + String(t.id).padStart(9, '0'); bank = t.balance; }
        }
        if (t.counter_wallet_id === w.id && t.status === 'confirmed') bal += t.direction === 'out' ? t.amount : -t.amount;
      });
      return Object.assign({}, w, { balance: bal, bank_balance: bank, pending: pending,
        otps: S.otps.filter(function (o) { return o.wallet_id === w.id && o.expires > now; }).length,
        color: w.color || ((SMS.BANKS[w.bank] || {}).color) || '#2c3e50' });
    });
  }

  function walletFor(bank, account) {
    if (bank) {
      var rows = S.wallets.filter(function (w) { return w.bank === bank; }).sort(function (a, b) { return a.id - b.id; });
      var digits = String(account || '').replace(/\D/g, '');
      if (rows.length > 1 && digits.length >= 4) {
        var hit = rows.filter(function (w) { var c = String(w.card || '').replace(/\D/g, ''); return c && c.slice(-4) === digits.slice(-4); })[0];
        if (hit) return hit.id;
      }
      if (rows.length) return rows[0].id;
      if (SMS.BANKS[bank]) {
        var nw = { id: S.next.wallet++, name: SMS.BANKS[bank].name, kind: 'bank', opening: 0, is_sms: 1, bank: bank, card: null, color: SMS.BANKS[bank].color };
        S.wallets.push(nw);
        return nw.id;
      }
    }
    var first = S.wallets.filter(function (w) { return +w.is_sms; })[0] || S.wallets[0];
    return first.id;
  }

  /* ---------------------------------------------------------------- SMS → transaction */

  function ingest(sender, body, at) {
    var now = at || new Date();
    var otp = SMS.parseOtp(body);
    S.sms.push({ id: S.next.sms++, sender: sender, at: stamp(now), status: otp ? 'otp' : 'new' });
    if (S.sms.length > 500) S.sms = S.sms.slice(-500);
    var seen = S.senders.filter(function (x) { return x.sender === sender; })[0];
    if (!seen) S.senders.push(seen = { sender: sender, n: 0, tx: 0, otp: 0, last: null });
    seen.n++; seen.last = stamp(now);
    if (otp) {
      seen.otp++;
      if (otp.code) {
        var w = walletFor(SMS.detectBank(sender, body), null);
        S.otps.push({ id: S.next.otp++, code: otp.code, amount: otp.amount, merchant: otp.merchant, wallet_id: w,
          created_at: stamp(now), expires: now.getTime() + 1000 * (otp.ttl || 180) });
      }
      save();
      return { otp: true };
    }
    var p = SMS.parseSms(body, now);
    if (!p) { save(); return { transaction: null }; }
    var occurred = stamp(now), iso;
    if (p.bank_date && (iso = SMS.jalaliToIso(p.bank_date))) occurred = iso + ' ' + (p.bank_time || '00:00') + ':00';
    var dedup = [p.direction, p.amount, p.balance == null ? '' : p.balance, p.bank_date || '', p.bank_time || ''].join('|');
    if (p.balance == null && p.bank_time == null) dedup += '|' + S.next.sms;
    var dup = S.txs.filter(function (t) { return t.dedup_key === dedup; })[0];
    if (dup) { save(); return { transaction: dup, duplicate: true }; }
    var t = { id: S.next.tx++, sms_id: S.next.sms - 1, source: 'sms', direction: p.direction, amount: p.amount, balance: p.balance,
      account: p.account, bank_date: p.bank_date, bank_time: p.bank_time, occurred_at: occurred, status: 'pending', description: null,
      party: null, category_id: null, note: null, confirmed_at: null, counter_wallet_id: null, dedup_key: dedup,
      wallet_id: walletFor(SMS.detectBank(sender, body), p.account), sms_text: body, synced: 0 };
    S.txs.push(t);
    seen.tx++;
    save();
    return { transaction: t, duplicate: false };
  }

  /* ---------------------------------------------------------------- answers */

  function rememberParty(name, catId) {
    var p = S.parties.filter(function (x) { return x.name === name; })[0];
    if (!p) S.parties.push(p = { name: name, phone: null, note: null, opening: 0, uses: 0, last_category_id: null });
    p.uses++;
    if (catId) p.last_category_id = catId;
  }

  function confirmTx(id, description, party, categoryId, note, counterWalletId) {
    var t = tx(id);
    if (!t) throw new Error('پیدا نشد');
    party = String(party || '').trim() || null;
    categoryId = +categoryId || null;
    var c = cat(categoryId), counter = null;
    if (c && c.kind === 'party' && !party) throw new Error('این دسته حساب یک شخص را تغییر می‌دهد؛ طرف حساب را بگو.');
    if (c && c.kind === 'transfer') {
      counter = +counterWalletId || (S.wallets.filter(function (w) { return w.id !== t.wallet_id; })
        .sort(function (a, b) { return (b.kind === 'cash') - (a.kind === 'cash') || a.id - b.id; })[0] || {}).id;
      if (!counter || counter === t.wallet_id) throw new Error('برای انتقال، حساب مقصد را انتخاب کن.');
    }
    Object.assign(t, { status: 'confirmed', description: String(description).trim(), party: party, category_id: categoryId,
      note: String(note || '').trim() || null, confirmed_at: stamp(), counter_wallet_id: counter || null });
    if (party) rememberParty(party, categoryId);
    save();
    return txOut(t);
  }

  /** "حواله به علی رضایی بابت خرید بذر" → description, party, category (server/lib.php ba_interpret). */
  function interpret(text, direction) {
    var t = normText(text), res = { description: String(text).trim(), party: '', category_id: null };
    var titles = '(آقای|آقا|خانم|حاج|حاجی|جناب|دکتر|مهندس)';
    var stop = ['بابت', 'برای', 'که', 'و', 'بود', 'شد', 'هست', 'است', 'رو', 'را', 'تومن', 'تومان', 'بوده', 'دادم', 'زدم', 'فرستادم', 'گرفتم', 'ریختم', 'ریخت', 'داد', 'پول', 'حساب', 'کارت'];
    var known = null;
    S.parties.forEach(function (p) {
      var n = normText(p.name);
      if (n && (' ' + t + ' ').indexOf(' ' + n + ' ') !== -1 && (!known || p.name.length > known.name.length)) known = p;
    });
    if (known) {
      res.party = known.name;
      if (known.last_category_id) res.category_id = known.last_category_id;
    } else {
      var marker = direction === 'in' ? '(?:از|طرف)' : '(?:به|حواله|برای)';
      var m = new RegExp('(?:^|\\s)' + marker + '\\s+(?:حساب\\s+)?(.+)$', 'u').exec(t);
      if (m) {
        var name = [];
        m[1].replace(new RegExp('^' + titles + '\\s+', 'u'), '').split(' ').some(function (w) {
          if (name.length >= 3) return true;
          if (stop.indexOf(w) !== -1 || /^(به|از|حواله|طرف)$/.test(w) || new RegExp('^' + titles + '$', 'u').test(w)) return name.length > 0;
          name.push(w);
          return false;
        });
        res.party = name.join(' ');
      }
    }
    if (!res.category_id) {
      var how = ['حواله', 'کارت به کارت', 'انتقال', 'پایا', 'ساتنا'], best = [0, null], bestWhat = [0, null];
      S.cats.forEach(function (c) {
        if (c.direction !== 'both' && c.direction !== direction) return;
        String(c.keywords || '').split(',').forEach(function (k) {
          k = normText(k);
          if (!k || t.indexOf(k) === -1) return;
          if (k.length > best[0]) best = [k.length, c.id];
          if (how.indexOf(k) === -1 && k.length > bestWhat[0]) bestWhat = [k.length, c.id];
        });
      });
      res.category_id = bestWhat[1] || best[1];
    }
    return res;
  }

  /* ---------------------------------------------------------------- people and reports */

  function partyBalance(p) {
    var b = +p.opening || 0;
    S.txs.forEach(function (t) {
      var c = cat(t.category_id);
      if (t.party === p.name && t.status === 'confirmed' && c && c.kind === 'party') b += t.direction === 'out' ? t.amount : -t.amount;
    });
    return b;
  }
  function people() {
    return S.parties.map(function (p) { return Object.assign({}, p, { balance: partyBalance(p), overdue: 0 }); })
      .sort(function (a, b) { return Math.abs(b.balance) - Math.abs(a.balance) || b.uses - a.uses || a.name.localeCompare(b.name); });
  }
  function peopleTotals() {
    var r = 0, p = 0;
    people().forEach(function (x) { if (x.balance > 0) r += x.balance; else p -= x.balance; });
    return { receivable: r, payable: p };
  }
  function statement(name) {
    var p = S.parties.filter(function (x) { return x.name === name; })[0];
    if (!p) return null;
    var rows = S.txs.filter(function (t) { return t.party === name && t.status === 'confirmed'; }).map(function (t) {
      var c = cat(t.category_id), party = c && c.kind === 'party';
      return { ref: 'tx:' + t.id, sort: t.occurred_at + '|1|' + String(t.id).padStart(9, '0'), date: t.occurred_at.slice(0, 10),
        title: party ? (t.direction === 'out' ? 'پرداخت به او' : 'دریافت از او') : (t.direction === 'out' ? 'خرید/پرداخت نقدی' : 'فروش/دریافت نقدی'),
        description: t.description, category: c ? c.name : null, amount: t.amount, effect: party ? (t.direction === 'out' ? t.amount : -t.amount) : 0 };
    }).sort(function (a, b) { return a.sort < b.sort ? -1 : 1; });
    var run = +p.opening || 0;
    rows.forEach(function (r) { run += r.effect; r.running = run; });
    return { person: Object.assign({}, p, { balance: partyBalance(p) }), rows: rows };
  }
  function inRange(t, from, to) { return t.occurred_at >= from + ' 00:00:00' && t.occurred_at <= to + ' 23:59:59'; }
  function profitLoss(from, to) {
    var lines = { in: {}, out: {} };
    S.txs.forEach(function (t) {
      var c = cat(t.category_id);
      if (t.status !== 'confirmed' || (c && c.kind !== 'pl') || !inRange(t, from, to)) return;
      var name = c ? c.name : 'بدون دسته', l = lines[t.direction][name] || (lines[t.direction][name] = { total: 0, n: 0 });
      l.total += t.amount; l.n++;
    });
    var list = function (d) { return Object.keys(lines[d]).map(function (k) { return { category: k, total: lines[d][k].total, n: lines[d][k].n }; }).sort(function (a, b) { return b.total - a.total; }); };
    var inc = list('in'), exp = list('out');
    var ti = inc.reduce(function (s, x) { return s + x.total; }, 0), te = exp.reduce(function (s, x) { return s + x.total; }, 0);
    return { from: from, to: to, income: inc, expense: exp, total_income: ti, total_expense: te, profit: ti - te };
  }

  /* ---------------------------------------------------------------- the voice assistant (local) */

  function ask(text) {
    var t = normText(text);
    var pend = S.txs.filter(function (x) { return x.status === 'pending'; });
    if (S.ctx && S.ctx.type === 'tx') {
      var p = tx(S.ctx.id);
      S.ctx = null;
      if (p && p.status === 'pending' && !/^(نه|بعدا|ولش|بیخیال)/.test(t)) {
        var g = interpret(text, p.direction);
        try { confirmTx(p.id, g.description, g.party, g.category_id); } catch (e) { save(); return { reply: e.message, state: 'answered' }; }
        var more = S.txs.filter(function (x) { return x.status === 'pending'; }).length;
        return { reply: 'ثبت شد: ' + g.description + (g.party ? '، طرف حساب ' + g.party : '') + '.' + (more ? ' ' + more.toLocaleString('fa-IR') + ' تراکنش بی‌جواب دیگر هم هست.' : ''), state: 'answered' };
      }
      save();
      return { reply: 'باشد، بعداً می‌پرسم.', state: 'answered' };
    }
    if (/بی ?جواب|بابت چی|تراکنش.*(مونده|مانده|جدید)/.test(t)) {
      if (!pend.length) return { reply: 'تراکنش بی‌جوابی نداری.', state: 'answered' };
      var q = pend[pend.length - 1], w = wallet(q.wallet_id);
      S.ctx = { type: 'tx', id: q.id };
      save();
      return { reply: pend.length.toLocaleString('fa-IR') + ' تراکنش بی‌جواب داری. ' + (w ? w.name + '، ' : '') + (q.direction === 'in' ? 'واریز ' : 'برداشت ') + toman(q.amount) + '. بابت چی بود؟', state: 'confirm' };
    }
    var ws = wallets(), hitW = ws.filter(function (x) { return t.indexOf(normText(x.name)) !== -1 || (x.bank && SMS.BANKS[x.bank] && t.indexOf(SMS.BANKS[x.bank].word) !== -1); })[0];
    if (/موجودی|مانده|چقدر پول|پول دارم/.test(t)) {
      if (hitW) return { reply: 'موجودی ' + hitW.name + ' ' + toman(hitW.balance) + '.', state: 'answered' };
      var total = ws.reduce(function (s, x) { return s + x.balance; }, 0);
      return { reply: 'موجودی کل ' + toman(total) + ': ' + ws.map(function (x) { return x.name + ' ' + toman(x.balance); }).join('، ') + '.', state: 'answered' };
    }
    var today = isoDate(), monthStart = (function () { var j = SMS.todayJalali(); var g = SMS.j2g(j[0], j[1], 1); return g[0] + '-' + pad(g[1]) + '-' + pad(g[2]); })();
    var from = /امروز/.test(t) ? today : /دیروز/.test(t) ? isoDate(new Date(Date.now() - 864e5)) : monthStart;
    var to = /دیروز/.test(t) ? from : today, label = /امروز/.test(t) ? 'امروز' : /دیروز/.test(t) ? 'دیروز' : 'این ماه';
    var sum = function (d) { return S.txs.filter(function (x) { return x.direction === d && x.status !== 'ignored' && inRange(x, from, to); }).reduce(function (s, x) { return s + x.amount; }, 0); };
    if (/واریز|دریافت|فروش|درآمد/.test(t)) return { reply: 'جمع واریزهای ' + label + ' ' + toman(sum('in')) + '.', state: 'answered' };
    if (/برداشت|خرج|هزینه|پرداخت/.test(t)) return { reply: 'جمع برداشت‌های ' + label + ' ' + toman(sum('out')) + '.', state: 'answered' };
    if (/سود|زیان/.test(t)) { var pl = profitLoss(from, to); return { reply: label + ': درآمد ' + toman(pl.total_income) + '، هزینه ' + toman(pl.total_expense) + '، ' + (pl.profit >= 0 ? 'سود ' : 'زیان ') + toman(pl.profit) + '.', state: 'answered' }; }
    var person = people().filter(function (x) { return t.indexOf(normText(x.name)) !== -1; })[0];
    if (person) return { reply: person.name + (person.balance === 0 ? ' حسابش صاف است.' : ' ' + toman(person.balance) + (person.balance > 0 ? ' به ما بدهکار است.' : ' از ما طلبکار است.')), state: 'answered' };
    if (/طلب|بدهی/.test(t)) { var pt = peopleTotals(); return { reply: 'طلب از اشخاص ' + toman(pt.receivable) + ' و بدهی به اشخاص ' + toman(pt.payable) + '.', state: 'answered' }; }
    return { reply: 'این‌ها را بپرس: «تراکنش‌های بی‌جواب»، «موجودی ملت»، «واریزهای امروز»، «برداشت این ماه»، «سود این ماه»، «حساب علی رضایی».', state: 'unknown' };
  }

  /* ---------------------------------------------------------------- Excel */

  function t2(rial) { var v = rial / 10; return Number.isInteger(v) ? v : Math.round(v * 10) / 10; }
  var BANK_FA = { mellat: 'ملت', melli: 'ملی', saderat: 'صادرات', blu: 'بلو', cash: 'نقد' };
  function excelSheets() {
    var ws = wallets(), status = { pending: 'بی‌جواب', confirmed: 'ثبت شده', ignored: 'نادیده' };
    var txs = S.txs.slice().sort(function (a, b) { return a.occurred_at < b.occurred_at ? -1 : a.occurred_at > b.occurred_at ? 1 : a.id - b.id; });
    var pt = peopleTotals(), j = SMS.todayJalali(), d = new Date();
    var rows = [], inn = 0, out = 0;
    txs.forEach(function (t) {
      var c = cat(t.category_id), w = wallet(t.wallet_id), a = t2(t.amount);
      if (t.status !== 'ignored') { if (t.direction === 'in') inn += t.amount; else out += t.amount; }
      rows.push([t.id, t.bank_date || jalaliOf(t.occurred_at), t.bank_time || t.occurred_at.slice(11, 16), w ? w.name : '', t.direction === 'in' ? 'واریز' : 'برداشت',
        t.direction === 'in' ? a : -a, t.balance != null ? t2(t.balance) : '', t.description || '', t.party || '', c ? c.name : '', status[t.status] || t.status,
        String(t.sms_text || '').replace(/\s+/g, ' ').trim()]);
    });
    rows.push(['', '', '', '', 'جمع واریز', t2(inn)]);
    rows.push(['', '', '', '', 'جمع برداشت', -t2(out)]);
    return [
      { name: 'خلاصه', head: ['', ''], widths: [34, 26], bold_rows: [2], rows: [
        ['تهیه شده در', 'اپ بدون سرور'],
        ['تاریخ تهیه', j[0] + '/' + pad(j[1]) + '/' + pad(j[2]) + ' ' + pad(d.getHours()) + ':' + pad(d.getMinutes())],
        ['موجودی کل کارت‌ها و صندوق (تومان)', t2(ws.reduce(function (s, w) { return s + w.balance; }, 0))],
        ['طلب از اشخاص (تومان)', t2(pt.receivable)],
        ['بدهی به اشخاص (تومان)', t2(pt.payable)],
        ['تعداد تراکنش‌های بانک', txs.length],
        ['تراکنش‌های بی‌جواب', txs.filter(function (t) { return t.status === 'pending'; }).length],
        ['', ''],
        ['همه‌ی مبلغ‌ها به تومان است.', '']] },
      { name: 'کارت‌ها', head: ['کارت', 'بانک', 'شماره کارت/حساب', 'مانده در برنامه (تومان)', 'مانده طبق آخرین پیامک (تومان)', 'بی‌جواب'],
        rows: ws.map(function (w) { return [w.name, BANK_FA[w.bank] || '', String(w.card || ''), t2(w.balance), w.bank_balance != null ? t2(w.bank_balance) : '', w.pending]; }) },
      { name: 'تراکنش‌های بانک', head: ['شناسه', 'تاریخ', 'ساعت', 'کارت', 'نوع', 'مبلغ (تومان)', 'مانده بانک (تومان)', 'بابت', 'طرف حساب', 'دسته', 'وضعیت', 'متن پیامک'],
        rows: rows, bold_rows: [rows.length - 2, rows.length - 1], widths: [8, 12, 8, 14, 10, 16, 18, 30, 18, 30, 10, 60] },
      { name: 'طرف‌حساب‌ها', head: ['نام', 'تلفن', 'مانده (تومان؛ مثبت = بدهکار به ما)', 'تعداد'],
        rows: people().map(function (p) { return [p.name, String(p.phone || ''), t2(p.balance), p.uses]; }) }
    ];
  }
  function excelName() { var j = SMS.todayJalali(), d = new Date(); return 'hesab-' + j[0] + '-' + pad(j[1]) + '-' + pad(j[2]) + '-' + pad(d.getHours()) + pad(d.getMinutes()) + '.xlsx'; }
  function excelBytes() { return XLSX.build(excelSheets()); }
  var XLSX_MIME = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

  function b64(bytes) {
    var s = '', CH = 0x8000;
    for (var i = 0; i < bytes.length; i += CH) s += String.fromCharCode.apply(null, bytes.subarray(i, i + CH));
    return btoa(s);
  }

  /* ---------------------------------------------------------------- native calls */

  var cbs = {}, cbN = 0;
  window.__baCb = function (id, ok, msg) { var f = cbs[id]; delete cbs[id]; if (f) f(!!ok, msg || ''); };
  function nativeCall(fn, args) {
    return new Promise(function (resolve, reject) {
      var id = 'n' + (++cbN);
      cbs[id] = function (ok, msg) { if (ok) resolve(msg); else reject(new Error(msg || 'نشد')); };
      try { N[fn].apply(N, [id].concat(args)); } catch (e) { delete cbs[id]; reject(e); }
    });
  }
  /** Saves (or shares) a file the user can open in Excel / send anywhere. */
  function saveFile(name, mime, bytes) {
    if (N && N.saveFile) return nativeCall('saveFile', [name, mime, b64(bytes)]);
    var a = document.createElement('a');
    a.href = URL.createObjectURL(new Blob([bytes], { type: mime }));
    a.download = name;
    document.body.appendChild(a);
    a.click();
    setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
    return Promise.resolve('');
  }

  /* ---------------------------------------------------------------- the ESP32, directly (local/link.js) */

  var L = window.BALink;
  var BROKER = 'wss://broker.emqx.io:8084/mqtt';
  var client = null, linkKeys = null, linkState = 'offline', linkError = null;

  function link() {
    if (!S.link) S.link = { code: null, broker: null, acked: [], status: null, last_msg: null };
    var forced = null;
    try { forced = N && N.linkCode ? N.linkCode() : null; } catch (e) { forced = null; }   // test builds
    if (forced && L.cleanCode(forced) !== S.link.code) { S.link.code = L.cleanCode(forced); save(); }
    if (!S.link.code) { S.link.code = L.newCode(); save(); }
    return S.link;
  }

  /** The modem's time of an SMS, «26/10/02,00:40:50+14» (yy/MM/dd,hh:mm:ss). */
  function smsDate(w) {
    var m = /^(\d\d)\/(\d\d)\/(\d\d),(\d\d):(\d\d):(\d\d)/.exec(String(w || ''));
    if (!m) return null;
    var d = new Date(2000 + +m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
    return isNaN(d.getTime()) || d.getTime() > Date.now() + 864e5 ? null : d;
  }

  function newSms(n) {
    if (!n) return;
    if (window.Island) Island.trigger({ title: n.toLocaleString('fa-IR') + ' پیامک بانک رسید', icon: '↓', accent: '#16A34A', duration: 2800 });
    window.dispatchEvent(new HashChangeEvent('hashchange'));
    try { if (window.OrbHost && OrbHost.changed) OrbHost.changed(); } catch (e) { /* no orb */ }
  }

  function sendAck() {
    if (client && linkKeys) client.publish('ba/' + linkKeys.id + '/ack', L.seal(linkKeys, { v: 1, ids: link().acked.slice(-60) }), true);
  }

  /** One message from the broker: an SMS the ESP32 is holding, or its status. */
  function onLinkMessage(topic, bytes) {
    var k = linkKeys, base = 'ba/' + k.id + '/', text = new TextDecoder().decode(bytes);
    if (!text) return;                                  // a cleared retained message
    var m = L.open(k, text);
    if (!m) { log('link: a message not sealed with this code on ' + topic); return; }
    refresh();
    var lk = link();
    if (topic === base + 'st') { lk.status = Object.assign({}, m, { seen: stamp() }); save(); return; }
    if (topic.indexOf(base + 'sms/') !== 0 || !m.id || typeof m.text !== 'string') return;
    var n = 0;
    if (lk.acked.indexOf(m.id) === -1) {
      var res = ingest(String(m.from || 'ESP32'), m.text, smsDate(m.when) || new Date());
      if (res.transaction && !res.duplicate) n++;
      lk.acked.push(m.id);
      if (lk.acked.length > 80) lk.acked = lk.acked.slice(-80);
      lk.last_msg = stamp();
      save();
    }
    sendAck();                                          // the ESP32 deletes it from the SIM after this
    newSms(n);
    // the orb's copy: the app's own page may have booked it first; the orb still asks
    if (!n && window.BA_HEADLESS) try { if (window.OrbHost && OrbHost.changed) OrbHost.changed(); } catch (e) { /* no orb */ }
  }

  function startLink() {
    if (client) client.stop();
    var lk = link(), url = lk.broker || null;
    try { if (!url && N && N.mqttUrl) url = N.mqttUrl(); } catch (e) { url = null; }
    url = url || BROKER;
    linkKeys = L.keys(lk.code);
    var socket = null;
    if (url === 'fake') { window.__fakeBroker = window.__fakeBroker || L.fakeBroker(lk.code, true); socket = window.__fakeBroker.socket; }
    if (window.BA_TEST_BROKER) socket = window.BA_TEST_BROKER.socket;
    client = new L.Client({ url: url, socket: socket,
      clientId: 'ba-app-' + linkKeys.id.slice(0, 6) + '-' + Math.random().toString(36).slice(2, 8),
      topics: ['ba/' + linkKeys.id + '/sms/+', 'ba/' + linkKeys.id + '/st'],
      onMessage: onLinkMessage,
      onState: function (st, why) { linkState = st; linkError = why || null; log('link ' + st + (why ? ': ' + why : '')); } });
    client.start();
  }
  function sync() { if (!client || linkState !== 'online') startLink(); return Promise.resolve(0); }
  function pendingList() {
    refresh();
    return S.txs.filter(function (t) { return t.status === 'pending'; })
      .map(function (t) { return { id: t.id, direction: t.direction, amount: t.amount }; });
  }

  /* ---------------------------------------------------------------- backup file (to move to another phone) */

  function backupBytes() { return new TextEncoder().encode(JSON.stringify(S)); }
  function restore(text) {
    var s = JSON.parse(text);
    if (!s || s.v !== 1 || !Array.isArray(s.txs)) throw new Error('این فایل پشتیبان این اپ نیست.');
    S = s;
    saveNow();
  }

  /* ---------------------------------------------------------------- api.php */

  function route(url, init) {
    refresh();
    var u = new URL(url, location.href), r = u.searchParams.get('r') || '', P = {}, b = bodyOf(init);
    u.searchParams.forEach(function (v, k) { P[k] = v; });
    try {
      switch (r) {
        case 'ping': return json({ ok: true });
        case 'me':
          return json({ ok: true, local: true, stt: false, tts: false, accounting_webhook: false, bale: false, link: linkState === 'online', device: null,
            otp_ready: true, wallets: wallets(), push_subs: 0 });
        case 'wallets': return json({ ok: true, items: wallets() });
        case 'banks': return json({ ok: true, items: Object.keys(SMS.BANKS).map(function (k) { return { code: k, name: SMS.BANKS[k].name, color: SMS.BANKS[k].color }; }) });
        case 'wallet_save': {
          var name = String(b.name || '').trim();
          if (!name) return fail('نام خالی است');
          var kind = b.kind === 'cash' ? 'cash' : 'bank', bank = kind === 'cash' ? 'cash' : (SMS.BANKS[b.bank] ? b.bank : null);
          var card = SMS.normalize(String(b.card || '')).replace(/\D/g, '') || null;
          var color = /^#[0-9a-fA-F]{6}$/.test(b.color || '') ? b.color : ((SMS.BANKS[bank] || {}).color || null);
          var opening = rialFrom(b, 'opening_rial', 'opening_toman');
          if (b.id) {
            var w = wallet(b.id);
            if (!w) return fail('پیدا نشد', 404);
            Object.assign(w, { name: name, kind: kind, opening: opening, bank: bank || w.bank, card: 'card' in b ? card : w.card, color: color || w.color,
              is_sms: Math.max(+w.is_sms, bank && SMS.BANKS[bank] ? 1 : 0) });
            save();
            return json({ ok: true, id: w.id });
          }
          var nw = { id: S.next.wallet++, name: name, kind: kind, opening: opening, is_sms: bank && SMS.BANKS[bank] ? 1 : 0, bank: bank, card: card, color: color };
          S.wallets.push(nw);
          save();
          return json({ ok: true, id: nw.id });
        }
        case 'report': {
          var to = P.to || isoDate(), from = P.from || isoDate(new Date(Date.now() - 30 * 864e5));
          return json({ ok: true, pl: profitLoss(from, to), wallets: wallets(), people: peopleTotals() });
        }
        case 'pending':
          return json({ ok: true, items: S.txs.filter(function (t) { return t.status === 'pending'; })
            .sort(function (a, c) { return a.occurred_at < c.occurred_at ? -1 : a.occurred_at > c.occurred_at ? 1 : a.id - c.id; }).map(txOut) });
        case 'transaction': { var t = tx(P.id); return t ? json({ ok: true, item: txOut(t) }) : fail('پیدا نشد', 404); }
        case 'list': {
          var lt = P.to || isoDate(), lf = P.from || isoDate(new Date(Date.now() - 30 * 864e5)), tot = { in: 0, out: 0 };
          var items = S.txs.filter(function (x) { return inRange(x, lf, lt); })
            .sort(function (a, c) { return a.occurred_at < c.occurred_at ? 1 : a.occurred_at > c.occurred_at ? -1 : c.id - a.id; }).map(txOut);
          items.forEach(function (x) { if (x.status !== 'ignored') tot[x.direction] += x.amount; });
          return json({ ok: true, items: items, bills: [], total_in: tot.in, total_out: tot.out });
        }
        case 'confirm': {
          if (!tx(b.id)) return fail('پیدا نشد', 404);
          if (!String(b.description || '').trim()) return fail('بابت چه بود؟ شرح خالی است.');
          return json({ ok: true, item: confirmTx(b.id, b.description, b.party, b.category_id, b.note, b.counter_wallet_id), synced: false });
        }
        case 'ignore': { var ti = tx(b.id); if (ti) { ti.status = 'ignored'; if (b.note) ti.note = String(b.note).trim(); save(); } return json({ ok: true }); }
        case 'reopen': { var tr = tx(b.id); if (tr) { tr.status = 'pending'; save(); } return json({ ok: true }); }
        case 'manual': {
          var amount = Math.abs(rialFrom(b, 'amount_rial', 'amount_toman'));
          if (!(amount > 0)) return fail('مبلغ نامعتبر است');
          var wid = +b.wallet_id || (S.wallets.filter(function (x) { return x.kind === 'cash'; })[0] || S.wallets[0]).id;
          var date = /^\d{4}-\d{2}-\d{2}$/.test(b.date || '') ? b.date : isoDate();
          var m = { id: S.next.tx++, sms_id: null, source: 'manual', direction: b.direction === 'in' ? 'in' : 'out', amount: amount, balance: null, account: null,
            bank_date: jalaliOf(date), bank_time: null, occurred_at: date + ' 12:00:00', status: 'pending', description: null, party: null, category_id: null,
            note: null, confirmed_at: null, counter_wallet_id: null, dedup_key: null, wallet_id: wid, sms_text: null, synced: 0 };
          S.txs.push(m);
          try {
            return json({ ok: true, item: confirmTx(m.id, String(b.description || '').trim() || 'ثبت دستی', b.party, b.category_id, b.note, b.counter_wallet_id) });
          } catch (e) { S.txs = S.txs.filter(function (x) { return x.id !== m.id; }); save(); throw e; }
        }
        case 'tx_delete': S.txs = S.txs.filter(function (x) { return !(x.id === +b.id && x.source === 'manual'); }); save(); return json({ ok: true });
        case 'interpret': return json({ ok: true, guess: interpret(String(b.text || ''), b.direction === 'in' ? 'in' : 'out') });
        case 'categories': return json({ ok: true, items: S.cats.slice().sort(function (a, c) { return a.sort_order - c.sort_order || a.id - c.id; }) });
        case 'category_save': {
          var cn = String(b.name || '').trim();
          if (!cn) return fail('نام دسته خالی است');
          var cv = { name: cn, direction: ['in', 'out', 'both'].indexOf(b.direction) !== -1 ? b.direction : 'both',
            kind: ['pl', 'party', 'transfer'].indexOf(b.kind) !== -1 ? b.kind : 'pl',
            keywords: String(b.keywords || '').replace(/\s*[,،]\s*/g, ',').replace(/^,+|,+$/g, '').trim() };
          var ce = b.id ? cat(b.id) : null;
          if (ce) Object.assign(ce, cv); else S.cats.push(Object.assign({ id: S.next.cat++, sort_order: 99 }, cv));
          save();
          return json({ ok: true });
        }
        case 'category_delete':
          S.cats = S.cats.filter(function (c) { return c.id !== +b.id; });
          S.txs.forEach(function (x) { if (x.category_id === +b.id) x.category_id = null; });
          save();
          return json({ ok: true });
        case 'parties': return json({ ok: true, items: S.parties.slice().sort(function (a, c) { return c.uses - a.uses; }) });
        case 'people': return json({ ok: true, items: people(), totals: peopleTotals() });
        case 'person': { var st = statement(String(P.name || '')); return st ? json(Object.assign({ ok: true }, st)) : fail('این شخص پیدا نشد', 404); }
        case 'person_save': {
          var pn = String(b.name || '').replace(/\s+/g, ' ').trim();
          if (!pn) return fail('نام خالی است');
          var old = b.old_name ? S.parties.filter(function (x) { return x.name === b.old_name; })[0] : null;
          var pe = old || S.parties.filter(function (x) { return x.name === pn; })[0];
          if (!pe) S.parties.push(pe = { name: pn, phone: null, note: null, opening: 0, uses: 0, last_category_id: null });
          if (old && old.name !== pn) S.txs.forEach(function (x) { if (x.party === old.name) x.party = pn; });
          Object.assign(pe, { name: pn, phone: String(b.phone || '').trim() || null, note: String(b.note || '').trim() || null, opening: rialFrom(b, 'opening_rial', 'opening_toman') });
          save();
          return json({ ok: true });
        }
        case 'person_delete': S.parties = S.parties.filter(function (x) { return x.name !== b.name; }); save(); return json({ ok: true });
        case 'senders': return json({ ok: true, items: S.senders.slice().sort(function (a, c) { return a.last < c.last ? 1 : -1; }), bank_senders: [] });
        case 'senders_save': return json({ ok: true });
        case 'otp': {
          var pin = (init && init.headers && (init.headers['X-OTP-PIN'] || init.headers['x-otp-pin'])) || '';
          if (!S.otp_pin) { if (String(pin).length < 4) return fail('یک PIN چهار رقمی یا بیشتر بگذار (همین بار اول تعیین می‌شود).'); S.otp_pin = String(pin); save(); }
          if (String(pin) !== S.otp_pin) return fail('PIN اشتباه است', 403);
          var now = Date.now();
          S.otps = S.otps.filter(function (o) { return o.expires > now; });
          return json({ ok: true, items: S.otps.filter(function (o) { return !+P.wallet_id || o.wallet_id === +P.wallet_id; }).map(function (o) {
            var ow = wallet(o.wallet_id);
            return { id: o.id, code: o.code, amount: o.amount, merchant: o.merchant, wallet_id: o.wallet_id, wallet_name: ow ? ow.name : null,
              created_at: o.created_at, seconds_left: Math.max(0, Math.round((o.expires - now) / 1000)) };
          }) });
        }
        case 'otp_count': return json({ ok: true, count: S.otps.filter(function (o) { return o.expires > Date.now() && (!+P.wallet_id || o.wallet_id === +P.wallet_id); }).length });
        case 'otp_clear': S.otps = b.id ? S.otps.filter(function (o) { return o.id !== +b.id; }) : []; save(); return json({ ok: true });
        case 'assistant_web': return json(Object.assign({ ok: true, heard: b.text || '' }, ask(String(b.text || ''))));
        case 'inventory': return json({ ok: true, items: [], warehouses: [], totals: { value: 0, items: 0, low: 0, out: 0 } });
        case 'reconciliations': return json({ ok: true, items: [] });
        case 'backups': return json({ ok: true, local: true, items: [], last: null, encrypted: false, to_bale: false });
        case 'backup_excel':
          return Promise.resolve(new Response(new Blob([excelBytes()], { type: XLSX_MIME }),
            { status: 200, headers: { 'Content-Type': XLSX_MIME, 'Content-Disposition': 'attachment; filename="' + excelName() + '"' } }));
        case 'export':
          return Promise.resolve(new Response(new Blob([excelBytes()], { type: XLSX_MIME }), { status: 200, headers: { 'Content-Type': XLSX_MIME } }));
        case 'settings': {
          var lk = link();
          return json({ ok: true, local: true, code: L.showCode(lk.code), id: linkKeys ? linkKeys.id : null, state: linkState, error: linkError,
            device: lk.status, last_msg: lk.last_msg, broker: lk.broker || BROKER, custom_broker: !!lk.broker });
        }
        case 'local_sync': startLink(); return json({ ok: true, state: linkState });
        case 'local_settings': {
          var l2 = link();
          if (b.new_code) l2.code = L.newCode();
          if (b.code) {
            var c = L.cleanCode(b.code);
            if (c.length < 12) return fail('کد اتصال کوتاه است (۱۶ حرف و عدد).');
            l2.code = c;
          }
          if ('broker' in b) l2.broker = /^wss?:\/\/./.test(String(b.broker || '')) ? String(b.broker).trim() : null;
          if (b.new_code || b.code) { l2.acked = []; l2.status = null; }
          save();
          startLink();
          return json({ ok: true, code: L.showCode(l2.code) });
        }
        case 'local_backup': return saveFile('bank-assistant-backup.json', 'application/json', backupBytes()).then(function () { return json({ ok: true }); });
        case 'local_restore': restore(String(b.data || '')); return json({ ok: true });
        default:
          return fail('در نسخه‌ی بدون سرور نیست', 501);
      }
    } catch (e) {
      return fail(e.message || String(e));
    }
  }

  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (/(^|\/)api\.php(\?|$)/.test(url)) {
      var hdrs = {};
      var h = init && init.headers;
      if (h && typeof h.forEach === 'function') h.forEach(function (v, k) { hdrs[k] = v; });
      else if (h) Object.keys(h).forEach(function (k) { hdrs[k] = h[k]; });
      return route(url, Object.assign({}, init || {}, { headers: hdrs }));
    }
    return realFetch(input, init);
  };

  // no service worker: the files are inside the app already
  try { if (navigator.serviceWorker) navigator.serviceWorker.register = function () { return Promise.reject(new Error('local')); }; } catch (e) { /* none */ }
  // signed in already: the books are on this phone
  try { if (!localStorage.getItem('ba_token')) localStorage.setItem('ba_token', 'local'); } catch (e) { /* private mode */ }
  document.documentElement.classList.add('local');
  window.BA_LOCAL = {
    sync: sync, saveFile: saveFile, excelBytes: excelBytes, excelName: excelName,
    backupBytes: backupBytes, restore: restore, state: function () { refresh(); return S; }, ingest: ingest,
    link: function () { return { state: linkState, error: linkError, keys: linkKeys, code: link().code }; }, pending: pendingList, ask: function (text) { refresh(); var a = ask(text); save(); return a; }
  };
  saveNow();   // the books exist on the phone from the first start
  log('engine ready, ' + document.readyState + ', hidden=' + document.hidden);
  document.addEventListener('DOMContentLoaded', function () {
    log('DOMContentLoaded');
    startLink();
  });
})();
