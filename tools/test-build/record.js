// Records the API answers of the demo server (tools/demo/run.sh, :8840) for the
// HTML-only test build:  node record.js OUT.json   (needs playwright; CHROME=… optional)
const { chromium } = require('playwright');
const fs = require('fs');
const BASE = process.env.BASE || 'http://127.0.0.1:8840';
const OUT = process.argv[2] || 'snapshot.json';
const snap = { bank: {}, acc: {}, recorded: new Date().toISOString() };

// "api.php?r=list&from=…" → ["bank", "list?from=…"]; "acc/api.php?p=/persons&x=1" → ["acc", "/persons?x=1"]
function keyOf(url) {
  const u = new URL(url, BASE);
  const q = new URLSearchParams(u.search);
  const isAcc = u.pathname.includes('/acc/');
  const head = isAcc ? q.get('p') : q.get('r');
  q.delete('p'); q.delete('r'); q.delete('token');
  const rest = [...q.entries()].sort().map(([k, v]) => k + '=' + v).join('&');
  return [isAcc ? 'acc' : 'bank', head + (rest ? '?' + rest : '')];
}
function put(url, ct, body) {
  const [side, key] = keyOf(url);
  if (!key || key.startsWith('null')) return;
  if (ct.includes('json')) { try { body = JSON.parse(body); } catch (e) { return; } snap[side][key] = { json: body }; }
  else snap[side][key] = { ct, text: body };
}
async function get(url, headers) {
  const r = await fetch(BASE + url, { headers });
  const ct = r.headers.get('content-type') || '';
  const t = await r.text();
  if (r.ok) put(url, ct, t);
  return ct.includes('json') ? (() => { try { return JSON.parse(t); } catch (e) { return null; } })() : t;
}

(async () => {
  // ---- accounting API, every GET route
  const login = await (await fetch(BASE + '/acc/api.php?p=/login', { method: 'POST', headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username: 'admin', password: 'demo12345' }) })).json();
  const A = { Authorization: 'Bearer ' + login.token, 'X-Company': '1' };
  snap.acc['/login'] = { json: { ...login, token: 'test-token', access_token: 'test-token' } };
  const plain = ['/health', '/me', '/dashboard', '/company', '/persons', '/products', '/invoices', '/journals', '/coa', '/fiscal', '/trial-balance',
    '/users', '/permissions/roles', '/logs', '/accounts', '/treasury', '/cheques', '/warehouses', '/stock', '/warehouse-docs', '/serials',
    '/stock-counts', '/tax-invoices', '/tax-report', '/reports/daybook', '/reports/balance-sheet', '/reports/cashflow', '/reports/dues',
    '/reports/cogs', '/reports/profit-loss', '/branches', '/currencies', '/bank-statements', '/attachments', '/sms/settings', '/sms/credit',
    '/sms/templates', '/sms/patterns', '/sms/log', '/phonebook', '/brands', '/departments', '/guarantees', '/cheque-books', '/loans', '/advances',
    '/expense-types', '/boms', '/productions', '/labels', '/reports/trade', '/reports/profit', '/reports/departments', '/reports/marketers',
    '/reports/accounts', '/reports/operations', '/reports/due-invoices', '/reports/order-estimate', '/reports/unused', '/reports/ttms',
    '/companies', '/tax/keys', '/bank/transactions', '/bank/meta', '/export/csv?type=persons', '/export/csv?type=products', '/export/csv?type=invoices'];
  const res = {};
  for (const p of plain) {
    const [path, q] = p.split('?');
    res[p] = await get('/acc/api.php?p=' + encodeURIComponent(path) + (q ? '&' + q : ''), A);
  }
  const ids = (x) => (Array.isArray(x) ? x : (x && x.items) || []).map(i => i.id).filter(Boolean);
  for (const id of ids(res['/invoices'])) {
    await get('/acc/api.php?p=' + encodeURIComponent('/invoices/' + id + '/detail'), A);
    await get('/acc/api.php?p=' + encodeURIComponent('/invoices/' + id + '/print'), A);
  }
  for (const id of ids(res['/products'])) await get('/acc/api.php?p=' + encodeURIComponent('/kardex/' + id), A);
  for (const id of ids(res['/persons'])) await get('/acc/api.php?p=' + encodeURIComponent('/reports/person/' + id), A);
  for (const id of ids(res['/accounts'])) await get('/acc/api.php?p=' + encodeURIComponent('/reports/cash/' + id), A);
  for (const id of ids(res['/tax-invoices'])) await get('/acc/api.php?p=' + encodeURIComponent('/tax-invoices/' + id), A);
  for (const a of (res['/coa'] || []).slice(0, 400)) await get('/acc/api.php?p=' + encodeURIComponent('/reports/ledger/' + a.id), A);

  // ---- bank / phone app API
  const B = { 'X-App-Token': 'demo12345' };
  for (const r of ['me', 'pending', 'categories', 'parties', 'people', 'wallets', 'banks', 'inventory', 'settings', 'senders', 'otp_count', 'reconciliations',
    'backups', 'assistant_devices', 'report']) await get('/api.php?r=' + r, B);
  const iso = (d) => new Date(Date.now() - d * 864e5).toISOString().slice(0, 10);
  const all = await get('/api.php?r=list&from=' + iso(400) + '&to=' + iso(0), B);
  snap.bank['__all'] = { json: all };   // every transaction: the test backend filters it itself
  for (const p of (await get('/api.php?r=people', B) || {}).items || []) await get('/api.php?r=person&name=' + encodeURIComponent(p.name), B);

  // ---- the real query strings the two apps send (UI crawl)
  const b = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const ctx = await b.newContext({ viewport: { width: 1280, height: 900 }, locale: 'fa-IR' });
  const page = await ctx.newPage();
  page.on('response', async (r) => {
    const u = r.url();
    if (!u.includes('api.php') || r.request().method() !== 'GET' || !r.ok()) return;
    try { put(u, r.headers()['content-type'] || '', await r.text()); } catch (e) {}
  });
  await page.goto(BASE + '/app/');
  await page.fill('#t', 'demo12345');
  await page.click('#f button');
  await page.waitForTimeout(2500);
  for (const h of ['#/', '#/settings', '#/people', '#/history', '#/reconcile', '#/manual', '#/otp', '#/card/1', '#/card/4']) {
    await page.goto(BASE + '/app/' + h); await page.waitForTimeout(1500);
  }
  await page.goto(BASE + '/acc/');
  await page.waitForTimeout(2500);
  const items = await page.$$eval('aside a, nav a', as => as.map((a, i) => i));
  for (const pid of ['dashboard', 'persons', 'products', 'sales', 'purchases', 'treasury', 'accounting', 'reports', 'warehouse', 'tax', 'bank', 'more', 'mreports', 'sms', 'settings']) {
    await page.evaluate((pid) => {
      const root = document.querySelector('[x-data]');
      const d = window.Alpine && Alpine.$data(root);
      if (!d) return;
      d.currentPage = pid;
      if (pid === 'sms' && d.smsLoad) d.smsLoad();
      if (pid === 'more' && d.moreLoad) d.moreLoad();
      if (pid === 'bank' && d.bankLoad) d.bankLoad();
      if (pid === 'mreports' && d.repLoad) d.repLoad();
    }, pid);
    await page.waitForTimeout(1200);
  }
  await b.close();
  fs.writeFileSync(OUT, JSON.stringify(snap));
  console.log('bank', Object.keys(snap.bank).length, 'acc', Object.keys(snap.acc).length, (fs.statSync(OUT).size / 1024 | 0) + ' KB');
})();
