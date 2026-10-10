/*
 * Test build: a pretend server inside the browser, so the phone web app and the
 * accounting panel run from plain HTML files (no PHP). Every request to api.php
 * is answered here from sample books recorded from the real server
 * (snapshot.js) plus what you do while testing. Everything is kept in this
 * browser's localStorage and shared by all open pages; «شروع دوباره» resets it.
 */
(function () {
  'use strict';
  var SNAP = window.__SNAPSHOT;
  var KEY = 'bank-test-state-v1';
  var realFetch = window.fetch ? window.fetch.bind(window) : null;
  var chan = 'BroadcastChannel' in window ? new BroadcastChannel('bank-test') : null;

  function clone(x) { return JSON.parse(JSON.stringify(x)); }
  function fresh() {
    var all = (SNAP.bank.__all && SNAP.bank.__all.json.items) || [];
    return { bank: clone(SNAP.bank), acc: clone(SNAP.acc), txs: clone(all), next: 5000, delta: {}, ctx: null, log: [], device: Date.now() };
  }
  function load() { try { return JSON.parse(localStorage.getItem(KEY)); } catch (e) { return null; } }
  var S = load() || fresh();
  function save(what) {
    try { localStorage.setItem(KEY, JSON.stringify(S)); } catch (e) { /* full / private mode: keep in memory */ }
    if (chan) chan.postMessage({ type: 'changed', what: what || '' });
  }
  // another page (the test lab, Bale simulator…) changed the books: show it here too
  var rerender = null;
  function changedElsewhere() {
    S = load() || fresh();
    clearTimeout(rerender);
    rerender = setTimeout(rerenderList, 150);
  }
  function rerenderList() {
    var h = location.hash.replace(/^#\/?/, '');
    var onList = /\/app\//.test(location.pathname) && (h === '' || h === 'history') && !document.body.classList.contains('siri-mode');
    if (onList) window.dispatchEvent(new HashChangeEvent('hashchange'));
  }
  window.addEventListener('storage', function (e) { if (e.key === KEY) changedElsewhere(); });
  if (chan) chan.onmessage = changedElsewhere;
  // no service worker in the test build (nothing to cache, no real push)
  try {
    if (navigator.serviceWorker) navigator.serviceWorker.register = function () { return Promise.reject(new Error('test build')); };
  } catch (e) {}
  // signed in already (test password: test)
  try {
    if (!localStorage.getItem('ba_token')) localStorage.setItem('ba_token', 'test');
    if (!localStorage.getItem('acc_token')) localStorage.setItem('acc_token', 'test-token');
  } catch (e) {}

  /* ------------------------------------------------------------ helpers */
  var FA = '۰۱۲۳۴۵۶۷۸۹';
  function faNum(n) { return Number(n || 0).toLocaleString('fa-IR'); }
  function toman(rial) { return faNum(Math.round(Math.abs(rial) / 10)) + ' تومان'; }
  function iso(d) { d = d || new Date(); return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0'); }
  function jalali(d) {
    try {
      var p = {};
      new Intl.DateTimeFormat('en-u-ca-persian-nu-latn', { year: 'numeric', month: '2-digit', day: '2-digit' }).formatToParts(d || new Date())
        .forEach(function (x) { p[x.type] = x.value; });
      return parseInt(p.year, 10) + '/' + p.month + '/' + p.day;
    } catch (e) { return '1405/07/18'; }
  }
  function norm(s) {
    return String(s || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/[۰-۹]/g, function (d) { return FA.indexOf(d); })
      .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); }).replace(/‌/g, ' ').replace(/\s+/g, ' ').trim();
  }
  function json(body, status) {
    return Promise.resolve(new Response(JSON.stringify(body), { status: status || 200, headers: { 'Content-Type': 'application/json; charset=utf-8' } }));
  }
  function text(body, ct) { return Promise.resolve(new Response(body, { status: 200, headers: { 'Content-Type': ct || 'text/plain; charset=utf-8' } })); }
  function bodyOf(init) {
    if (!init || !init.body) return {};
    if (typeof init.body === 'string') { try { return JSON.parse(init.body); } catch (e) { return {}; } }
    if (init.body instanceof FormData) { var o = {}; init.body.forEach(function (v, k) { o[k] = v; }); return o; }
    return {};
  }
  function parse(url) {
    var u = new URL(url, location.href);
    var q = new URLSearchParams(u.search);
    var acc = /\/acc\/api\.php$/.test(u.pathname) || (u.pathname.endsWith('api.php') && q.has('p'));
    var head = acc ? q.get('p') : q.get('r');
    var params = {};
    q.forEach(function (v, k) { if (k !== 'p' && k !== 'r' && k !== 'token') params[k] = v; });
    var rest = Object.keys(params).sort().map(function (k) { return k + '=' + params[k]; }).join('&');
    return { acc: acc, head: head || '', params: params, key: (head || '') + (rest ? '?' + rest : '') };
  }
  function snap(side, key, path) {
    var e = S[side][key] || (path && S[side][path]);
    return e || null;
  }

  /* ------------------------------------------------------------ bank / phone app */
  function cats() { return ((S.bank.categories || {}).json || {}).items || []; }
  function wallets() {
    var w = clone((((S.bank.report || {}).json || {}).wallets) || ((S.bank.wallets || {}).json || {}).items || []);
    w.forEach(function (x) { x.balance = (x.balance || 0) + (S.delta[x.id] || 0); });
    return w;
  }
  function txList(from, to) {
    return S.txs.filter(function (t) { var d = t.occurred_at.slice(0, 10); return (!from || d >= from) && (!to || d <= to); })
      .sort(function (a, b) { return a.occurred_at < b.occurred_at ? 1 : a.occurred_at > b.occurred_at ? -1 : b.id - a.id; });
  }
  function findTx(id) { id = +id; return S.txs.filter(function (t) { return +t.id === id; })[0]; }

  /** A bank SMS as the ESP32 would send it → a transaction waiting for «بابت چی بود؟». */
  function ingest(sms) {
    var t = norm(sms);
    var m = t.match(/(واریز|برداشت|انتقال|خرید)\s*[:：]?\s*([\d,٬]+)/);
    if (!m) {
      var otp = t.match(/(رمز|کد)[^\d]{0,20}(\d{5,8})/);
      if (otp) { S.log.push({ at: Date.now(), kind: 'otp', text: sms }); save('otp'); return { ok: true, otp: otp[2] }; }
      return { ok: false, error: 'در این پیامک واریز یا برداشتی پیدا نشد' };
    }
    var dir = m[1] === 'واریز' ? 'in' : 'out';
    var amount = parseInt(m[2].replace(/[,٬]/g, ''), 10);
    var bal = (t.match(/مانده\s*[:：]?\s*([\d,٬]+)/) || [])[1];
    var now = new Date();
    var tx = { id: ++S.next, sms_id: null, source: 'sms', direction: dir, amount: amount, balance: bal ? parseInt(bal.replace(/[,٬]/g, ''), 10) : null,
      account: '0123456789', bank_date: jalali(now), bank_time: String(now.getHours()).padStart(2, '0') + ':' + String(now.getMinutes()).padStart(2, '0'),
      occurred_at: iso(now) + ' ' + now.toTimeString().slice(0, 8), status: 'pending', description: null, party: null, category_id: null,
      category_name: null, wallet_id: 1, wallet_name: 'بانک ملت', sms_text: sms };
    S.txs.push(tx);
    S.delta[1] = (S.delta[1] || 0) + (dir === 'in' ? amount : -amount);
    S.log.push({ at: Date.now(), kind: 'sms', text: sms, tx: tx.id });
    save('tx');
    return { ok: true, transaction_id: tx.id, transaction: tx };
  }

  function guess(text, direction) {
    var t = norm(text), party = '', cat = null;
    var people = (((S.bank.people || {}).json || {}).items || []).map(function (p) { return p.name; })
      .concat((((S.acc['/persons'] || {}).json) || []).map(function (p) { return p.name; }));
    people.forEach(function (n) { if (n && t.indexOf(norm(n)) !== -1 && n.length > party.length) party = n; });
    cats().forEach(function (c) {
      if (cat || (c.direction !== 'both' && c.direction !== direction)) return;
      var kws = String(c.keywords || '').split(',').concat([c.name]).map(norm).filter(Boolean);
      if (kws.some(function (k) { return t.indexOf(k) !== -1; })) cat = c.id;
    });
    if (!cat && party) { var p = cats().filter(function (c) { return c.kind === 'party'; })[0]; if (p) cat = p.id; }
    return { description: String(text).trim(), party: party, category_id: cat };
  }

  function confirmTx(id, desc, party, catId) {
    var tx = findTx(id);
    if (!tx) return null;
    tx.status = 'confirmed';
    tx.description = desc;
    tx.party = party || null;
    tx.category_id = catId ? +catId : null;
    var c = cats().filter(function (x) { return +x.id === +catId; })[0];
    tx.category_name = c ? c.name : null;
    tx.confirmed_at = iso() + ' ' + new Date().toTimeString().slice(0, 8);
    save('tx');
    return tx;
  }

  /** The voice assistant's answers (the real one is server/assistant.php). */
  function ask(textIn) {
    var t = norm(textIn);
    var pend = S.txs.filter(function (x) { return x.status === 'pending'; });
    if (S.ctx && S.ctx.type === 'tx') {
      var tx = findTx(S.ctx.id);
      S.ctx = null;
      if (tx && tx.status === 'pending' && !/^(نه|بعدا|ولش)/.test(t)) {
        var g = guess(textIn, tx.direction);
        confirmTx(tx.id, g.description, g.party, g.category_id);
        var more = S.txs.filter(function (x) { return x.status === 'pending'; });
        return { reply: 'ثبت شد: ' + g.description + (g.party ? '، طرف حساب ' + g.party : '') + '.' + (more.length ? ' ' + faNum(more.length) + ' تراکنش بی‌جواب دیگر هم هست؛ بگو «تراکنش‌های بی‌جواب».' : ''), state: 'answered' };
      }
      save();
      return { reply: 'باشد، بعداً می‌پرسم.', state: 'answered' };
    }
    if (/بی ?جواب|بابت چی|تراکنش.*(مونده|مانده|جدید)/.test(t)) {
      if (!pend.length) return { reply: 'تراکنش بی‌جوابی نداری.', state: 'answered' };
      var p = pend[pend.length - 1];
      S.ctx = { type: 'tx', id: p.id };
      save();
      return { reply: faNum(pend.length) + ' تراکنش بی‌جواب داری. ' + (p.direction === 'in' ? 'واریز ' : 'برداشت ') + toman(p.amount) + ' تاریخ ' + p.bank_date + '. بابت چی بود؟', state: 'confirm' };
    }
    var dash = ((S.acc['/dashboard'] || {}).json) || {};
    if (/فروش/.test(t)) return { reply: 'جمع فروش امسال ' + toman(dash.sales || 0) + ' است.', state: 'answered' };
    if (/خرید/.test(t)) return { reply: 'جمع خرید امسال ' + toman(dash.purchases || 0) + ' است.', state: 'answered' };
    if (/سود|زیان/.test(t)) {
      var pl = ((S.acc['/reports/profit-loss'] || {}).json) || {};
      return { reply: 'درآمد ' + toman(pl.income || 0) + '، هزینه ' + toman(pl.expense || 0) + '، سود ' + toman(pl.profit || 0) + '.', state: 'answered' };
    }
    if (/موجودی (بانک|صندوق|حساب)|چقدر پول|موجودی کل/.test(t)) {
      return { reply: wallets().map(function (w) { return w.name + ' ' + toman(w.balance); }).join('، ') + '.', state: 'answered' };
    }
    if (/چک/.test(t)) {
      var ch = (((S.acc['/cheques'] || {}).json) || []).filter(function (c) { return /issued|received|open|pending/.test(c.status || ''); });
      return { reply: ch.length ? ch.slice(0, 3).map(function (c) { return (c.direction === 'payable' ? 'پرداختی به ' : 'دریافتی از ') + c.person_name + ' ' + toman(c.amount) + ' سررسید ' + c.due_date; }).join('؛ ') + '.' : 'چک باز نداری.', state: 'answered' };
    }
    if (/طلب|بدهی/.test(t)) {
      return { reply: 'طلب از مشتری‌ها ' + toman(dash.receivables || 0) + ' و بدهی به تأمین‌کننده‌ها ' + toman(dash.payables || 0) + '.', state: 'answered' };
    }
    if (/انبار|موجودی کالا/.test(t)) return { reply: 'ارزش موجودی انبار ' + toman(dash.stock_value || 0) + ' است.', state: 'answered' };
    var person = (((S.acc['/persons'] || {}).json) || []).filter(function (p) { return t.indexOf(norm(p.name)) !== -1; })[0];
    if (person) return { reply: person.name + ' ' + toman(person.balance) + (person.balance >= 0 ? ' به ما بدهکار است.' : ' از ما طلبکار است.'), state: 'answered' };
    return { reply: 'در نسخه‌ی تست این‌ها را بپرس: «تراکنش‌های بی‌جواب»، «فروش امسال»، «سود»، «موجودی بانک»، «چک‌ها»، «حساب علی رضایی»، «طلب و بدهی».', state: 'unknown' };
  }

  function bank(r, init) {
    var b = bodyOf(init), method = (init && init.method) || 'GET';
    var P = r.params;
    switch (r.head) {
      case 'ping': return json({ ok: true });
      case 'me': return json(Object.assign({}, (snap('bank', 'me') || { json: { ok: true } }).json, { wallets: wallets(), push_subs: 0 }));
      case 'list': {
        var items = txList(P.from, P.to);
        var tot = { in: 0, out: 0 };
        items.forEach(function (t) { if (t.status !== 'ignored') tot[t.direction] += +t.amount; });
        return json({ ok: true, items: items, bills: [], total_in: tot.in, total_out: tot.out });
      }
      case 'pending': return json({ ok: true, items: S.txs.filter(function (t) { return t.status === 'pending'; }).sort(function (a, b) { return a.id - b.id; }) });
      case 'transaction': { var tx = findTx(P.id); return tx ? json({ ok: true, item: tx }) : json({ ok: false, error: 'پیدا نشد' }, 404); }
      case 'report': {
        var rep = clone((snap('bank', r.key, 'report') || { json: { ok: true } }).json);
        rep.wallets = wallets();
        return json(rep);
      }
      case 'wallets': return json({ ok: true, items: wallets() });
      case 'confirm': {
        var c = confirmTx(b.id, b.description, b.party, b.category_id);
        return c ? json({ ok: true, item: c, synced: false }) : json({ ok: false, error: 'پیدا نشد' }, 404);
      }
      case 'ignore': case 'reopen': {
        var t2 = findTx(b.id);
        if (t2) { t2.status = r.head === 'ignore' ? 'ignored' : 'pending'; save('tx'); }
        return json({ ok: true });
      }
      case 'tx_delete': S.txs = S.txs.filter(function (t) { return !(+t.id === +b.id && t.source === 'manual'); }); save('tx'); return json({ ok: true });
      case 'manual': {
        var dir = b.direction === 'in' ? 'in' : 'out';
        var amt = Math.abs(Math.round(parseFloat(norm(b.amount_toman || '0').replace(/,/g, '')) * 10)) || +b.amount_rial || 0;
        if (!amt) return json({ ok: false, error: 'مبلغ نامعتبر است' }, 400);
        var d = b.date || iso();
        var w = +b.wallet_id || 2;
        var nt = { id: ++S.next, source: 'manual', direction: dir, amount: amt, bank_date: jalali(new Date(d + 'T12:00:00')), bank_time: '',
          occurred_at: d + ' 12:00:00', status: 'pending', wallet_id: w, wallet_name: (wallets().filter(function (x) { return x.id === w; })[0] || {}).name || '' };
        S.txs.push(nt);
        S.delta[w] = (S.delta[w] || 0) + (dir === 'in' ? amt : -amt);
        return json({ ok: true, item: confirmTx(nt.id, b.description || 'ثبت دستی', b.party, b.category_id) });
      }
      case 'interpret': return json({ ok: true, guess: guess(b.text || '', b.direction) });
      case 'assistant_web': return json(Object.assign({ ok: true, heard: b.text || '' }, ask(b.text || '')));
      case 'assistant_pair': return json({ ok: true, code: 'TEST2345', expires_in: 300, server: 'https://test/api.php', link: '#', universal_link: '#' });
      case 'backups': return json({ ok: true, items: S.backups || [], last: (S.backups || [])[0] || null, encrypted: true, to_bale: true });
      case 'backup_now': {
        var now = new Date();
        var name = 'bank-backup-' + iso(now).replace(/-/g, '') + '-' + now.toTimeString().slice(0, 8).replace(/:/g, '') + '.bkp';
        var e = { name: name, size: 48213, at: iso(now) + ' ' + now.toTimeString().slice(0, 8), encrypted: true, bale: true };
        S.backups = [e].concat(S.backups || []).slice(0, 14);
        save();
        return json({ ok: true, name: name, size: e.size, databases: ['bank.sqlite'], encrypted: true, sent_to_bale: true });
      }
      case 'backup_download': return text('نسخه‌ی تست: فایل پشتیبان واقعی روی سرور ساخته می‌شود.', 'application/octet-stream');
      case 'push_key': return json({ ok: false, error: 'نوتیف واقعی در نسخه‌ی تست نیست؛ از شبیه‌ساز ESP32 استفاده کن.' }, 501);
      case 'tts': case 'say': case 'transcribe': return json({ ok: false, error: 'در نسخه‌ی تست صدای مرورگر استفاده می‌شود' }, 501);
      case 'otp': return json({ ok: true, items: S.log.filter(function (l) { return l.kind === 'otp'; }).map(function (l, i) { return { id: i + 1, code: (norm(l.text).match(/(\d{5,8})/) || [])[1], received_at: new Date(l.at).toISOString().slice(0, 19).replace('T', ' '), text: l.text }; }) });
      case 'otp_count': return json({ ok: true, count: S.log.filter(function (l) { return l.kind === 'otp'; }).length });
    }
    if (method === 'GET') {
      var s = snap('bank', r.key, r.head);
      return json(s ? s.json : { ok: true, items: [] });
    }
    return json({ ok: true });   // settings and the like: accepted, not kept
  }

  /* ------------------------------------------------------------ accounting panel */
  function accList(path) { var e = S.acc[path]; return e && Array.isArray(e.json) ? e.json : null; }
  function nextId(list) { return list.reduce(function (m, x) { return Math.max(m, +x.id || 0); }, 0) + 1; }
  function personName(id) { return ((accList('/persons') || []).filter(function (p) { return +p.id === +id; })[0] || {}).name || ''; }

  function acc(r, init) {
    var method = ((init && init.method) || 'GET').toUpperCase();
    var b = bodyOf(init);
    var path = r.head.split('?')[0];
    if (path === '/login' || path === '/login/app') return json(S.acc['/login'].json);
    if (method === 'GET') {
      // invoices of one group (the sales / purchases pages) follow what was added
      if (path === '/invoices' && r.params.group && accList('/invoices')) {
        return json(accList('/invoices').filter(function (i) { return String(i.kind || '').indexOf(r.params.group) === 0; }));
      }
      var e = snap('acc', r.key, path);
      if (e) return e.json !== undefined ? json(e.json) : text(e.text, e.ct);
      return json(/\/\d+(\/|$)/.test(path) ? {} : []);
    }
    var m = path.match(/^(\/[a-z\-\/]+?)(?:\/(\d+))?$/);
    var base = m ? m[1] : path, id = m && m[2];
    var list = accList(base);
    if (method === 'POST' && list && !id) {
      var item = Object.assign({ id: nextId(list) }, b);
      if (item.person_id) item.person_name = personName(item.person_id);
      if (base === '/persons') { item.balance = 0; item.code = item.code || 'T' + item.id; item.type_label = { customer: 'مشتری', supplier: 'تأمین‌کننده' }[item.type] || ''; }
      if (base === '/products') { item.stock = +item.stock || 0; }
      if (base === '/invoices') {
        var sub = (b.items || []).reduce(function (s, x) { return s + (+x.qty || 0) * (+x.price || 0); }, 0);
        var disc = Math.round(sub * (+b.discount_percent || 0) / 100);
        var tax = Math.round((sub - disc) * 0.1);
        var kind = b.kind || 'sale';
        var prefix = { sale: 'SF', purchase: 'PF', sale_proforma: 'SQ', sale_return: 'SR', purchase_return: 'PR' }[kind] || 'TS';
        Object.assign(item, { kind: kind, number: prefix + '-T' + String(item.id).padStart(3, '0'), date: b.date || jalali(), subtotal: sub, discount: disc,
          tax: tax, total: sub - disc + tax + (+b.freight || 0), settled: false, status: 'final' });
      }
      list.unshift(item);
      save('acc');
      return json(item);
    }
    if ((method === 'PUT' || method === 'PATCH') && list && id) {
      list.forEach(function (x, i) { if (+x.id === +id) list[i] = Object.assign({}, x, b, { id: x.id }); });
      save('acc');
      return json(list.filter(function (x) { return +x.id === +id; })[0] || { ok: true });
    }
    if (method === 'DELETE' && list && id) {
      S.acc[base].json = list.filter(function (x) { return +x.id !== +id; });
      save('acc');
      return json({ ok: true });
    }
    return json({ ok: true, detail: 'نسخه‌ی تست: انجام شد (در نسخه‌ی واقعی روی سرور حساب می‌شود)' });
  }

  /* ------------------------------------------------------------ fetch */
  window.fetch = function (input, init) {
    var url = typeof input === 'string' ? input : (input && input.url) || '';
    if (!/api\.php/.test(url)) return realFetch ? realFetch(input, init) : Promise.reject(new Error('offline'));
    var r = parse(url);
    try {
      return new Promise(function (res) { setTimeout(res, 60 + Math.random() * 120); })   // feels like a network
        .then(function () { return r.acc ? acc(r, init) : bank(r, init); });
    } catch (e) {
      return json({ ok: false, error: String(e && e.message || e) }, 500);
    }
  };

  // for the test lab page
  window.__TEST = {
    ingest: ingest,
    ask: ask,
    confirm: confirmTx,
    guess: guess,
    pending: function () { return S.txs.filter(function (t) { return t.status === 'pending'; }).sort(function (a, b) { return a.id - b.id; }); },
    wallets: wallets,
    state: function () { return S; },
    reset: function () { S = fresh(); save('reset'); },
    toman: toman
  };
})();
