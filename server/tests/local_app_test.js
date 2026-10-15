// The app without a server (app/local/engine.js + link.js) with the ESP32's messages on an
// in-page MQTT broker: SMS sealed with the pairing code → booked on its card, «ack» back (so the
// ESP32 deletes it), the same SMS never twice, a foreign code ignored, OTPs, the Excel file,
// the books survive a reload.
//   node tests/local_app_test.js      (CHROME=/path/to/chrome optional)
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path'), os = require('os');
const { execSync } = require('child_process');
const ROOT = path.resolve(__dirname, '../..');
let fails = 0;
const check = (n, ok, x = '') => { console.log((ok ? '  ok   ' : '  FAIL ') + n + (ok ? '' : '  ' + x)); if (!ok) fails++; };

const SMS1 = 'بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0718-14:25';
const SMS2 = 'بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n1405/07/20 09:12';
const OTP = 'بانک ملت\nرمز پویا: 48213967\nمبلغ: 1,250,000 ریال';

(async () => {
  const W = fs.mkdtempSync(path.join(os.tmpdir(), 'local-'));
  execSync(`sh "${ROOT}/tools/local-app/build.sh" "${W}/www"`, { stdio: 'ignore' });
  const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json', '.webmanifest': 'application/manifest+json' };
  const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    let f = path.join(W, 'www', decodeURIComponent(u.pathname));
    if (f.endsWith('/')) f += 'index.html';
    if (!f.startsWith(path.join(W, 'www')) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.statusCode = 404; return res.end(); }
    res.setHeader('Content-Type', types[path.extname(f)] || 'application/octet-stream');
    fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${srv.address().port}`;

  const b = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
  // the broker lives in the page (BALink.fakeBroker); created on first use
  await ctx.addInitScript(() => { Object.defineProperty(window, 'BA_TEST_BROKER', { get() { return window.__tb || (window.__tb = BALink.fakeBroker()); } }); });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  // what the ESP32 does: one retained, sealed message per SMS still on the SIM
  const esp = (id, from, text, code) => p.evaluate(([id, from, text, code]) => {
    const k = BALink.keys(code || BA_LOCAL.link().code);
    __tb.publish('ba/' + k.id + '/sms/' + id, BALink.seal(k, { v: 1, id, from, text, when: '26/10/02,09:12:00+14' }), true);
  }, [id, from, text, code || null]);
  const acked = () => p.evaluate(() => { const k = BA_LOCAL.link().keys, r = __tb.retained['ba/' + k.id + '/ack']; return r ? BALink.open(k, new TextDecoder().decode(r)).ids : []; });

  // 1) first start: no login, a pairing code of its own
  await p.goto(base + '/app/');
  await p.waitForTimeout(2000);
  check('home without a server (no login)', await p.isVisible('#tabbar') && !(await p.$('#t')));
  check('accounting and stock tabs hidden', !(await p.isVisible('.tabbar a[data-tab="acc"]')) && !(await p.isVisible('.tabbar a[data-tab="stock"]')));
  const link = await p.evaluate(() => BA_LOCAL.link());
  check('a pairing code of 16 letters and digits, connected', /^[A-Z2-9]{16}$/.test(link.code) && link.state === 'online', JSON.stringify(link));

  // 2) the ESP32 leaves two bank SMS and its status
  await p.evaluate(() => { const k = BA_LOCAL.link().keys; __tb.publish('ba/' + k.id + '/st', BALink.seal(k, { v: 1, sig: 21, wait: 2, fw: 'app-1.0' }), true); });
  await esp('m1', 'Bank Mellat', SMS1);
  await esp('m2', 'Bank Melli', SMS2);
  await p.waitForTimeout(600);
  const st = await p.evaluate(() => BA_LOCAL.state());
  const t1 = st.txs.find(t => t.amount === 2500000), t2 = st.txs.find(t => t.amount === 7000000);
  const wName = id => (st.wallets.find(w => w.id === id) || {}).name;
  check('Mellat withdrawal booked on its card, waiting for an answer', t1 && t1.status === 'pending' && t1.direction === 'out' && wName(t1.wallet_id) === 'بانک ملت', JSON.stringify(t1));
  check('Melli deposit booked on its card', t2 && t2.direction === 'in' && wName(t2.wallet_id) === 'بانک ملی', JSON.stringify(t2));
  check('«ack» for both (the ESP32 then deletes them from the SIM)', JSON.stringify(await acked()) === '["m1","m2"]', JSON.stringify(await acked()));
  check('ESP32 status kept (signal 21)', st.link.status && st.link.status.sig === 21);

  // 3) the same SMS again (the ESP32 had not seen the ack yet), a foreign code, an OTP
  await esp('m1', 'Bank Mellat', SMS1);
  await esp('x9', 'Bank Mellat', 'بانک ملت\nبرداشت:9,000,000\nمانده:1,000\n0718-15:00', 'AAAABBBBCCCCDDDD');
  await esp('o1', 'Bank Mellat', OTP);
  await p.waitForTimeout(600);
  const st2 = await p.evaluate(() => BA_LOCAL.state());
  check('the same SMS is not booked twice', st2.txs.length === 2, String(st2.txs.length));
  check('a message sealed with another code is ignored', !st2.txs.some(t => t.amount === 9000000));
  check('OTP kept for «رمز پویا»', st2.otps.some(o => o.code === '48213967'));

  // 4) the orb's own page (app/local/headless.html)
  const h = await ctx.newPage();
  h.on('pageerror', e => errors.push('headless: ' + e.message));
  await h.addInitScript(() => { window.__done = {}; window.OrbHost = { ready() { window.__ready = true; }, done(id, ok, json) { window.__done[id] = [ok, JSON.parse(json)]; } }; });
  await h.goto(base + '/app/local/headless.html');
  await h.waitForFunction(() => window.__ready);
  await h.evaluate(() => orbCall('a', 'pending', ''));
  await h.waitForFunction(() => window.__done.a);
  check('orb page: the two waiting ones through OrbHost', await h.evaluate(() => window.__done.a[0] === true && window.__done.a[1].length === 2));
  await h.close();

  // 5) in the app: the assistant asks about the waiting ones and records the answers
  await p.goto(base + '/app/#/');
  await p.waitForTimeout(1200);
  check('card balance from the SMS', await p.evaluate(() => fetch('../api.php?r=wallets').then(r => r.json()).then(d => d.items.find(w => w.bank === 'mellat').bank_balance)) === 45000000);
  const ask = t => p.evaluate(t => fetch('../api.php?r=assistant_web', { method: 'POST', body: JSON.stringify({ text: t }) }).then(r => r.json()), t);
  const a1 = await ask('تراکنش‌های بی‌جواب'), a2 = await ask('فروش لپ تاپ از علی رضایی');
  const a3 = await ask('تراکنش‌های بی‌جواب'), a4 = await ask('خرید لوازم اداری');
  check('assistant asks about the pending ones and records the answers', /بابت چی بود/.test(a1.reply) && /ثبت شد/.test(a2.reply) && /بابت چی بود/.test(a3.reply) && /ثبت شد/.test(a4.reply), [a1, a2, a3, a4].map(x => x.reply).join(' | '));
  const a5 = await ask('موجودی ملی');
  check('assistant: card balance', /بانک ملی/.test(a5.reply), a5.reply);

  // 6) Excel
  const xb = await p.evaluate(() => Array.from(BA_LOCAL.excelBytes()));
  fs.writeFileSync(W + '/h.xlsx', Buffer.from(xb));
  const rows = execSync(`python3 -c "import openpyxl,json;wb=openpyxl.load_workbook('${W}/h.xlsx');print(json.dumps({ws.title:[[c.value for c in r] for r in ws.iter_rows()] for ws in wb},ensure_ascii=False))"`).toString();
  const book = JSON.parse(rows);
  const txSheet = book['تراکنش‌های بانک'] || [];
  check('Excel: sheets and the two transactions in toman', Object.keys(book).join(',') === 'خلاصه,کارت‌ها,تراکنش‌های بانک,طرف‌حساب‌ها'
    && txSheet.some(r => r[5] === -250000 && r[3] === 'بانک ملت' && r[7] === 'خرید لوازم اداری') && txSheet.some(r => r[5] === 700000 && r[8] === 'علی رضایی'), rows.slice(0, 400));

  // 7) settings and persistence
  await p.goto(base + '/app/#/settings');
  await p.waitForTimeout(1500);
  const sText = await p.textContent('#app');
  check('settings: pairing code, ESP32 status, Excel; no Bale', sText.includes(link.code.replace(/(.{4})(?=.)/g, '$1-')) && /آنتن/.test(sText) && await p.isVisible('#lXlsx') && !/بله/.test(sText.replace(/بلو/g, '')), sText.slice(0, 300));
  await p.reload();
  await p.waitForTimeout(1200);
  check('books survive a reload', (await p.evaluate(() => BA_LOCAL.state().txs.filter(t => t.status === 'confirmed').length)) === 2);
  check('the same code after a reload', (await p.evaluate(() => BA_LOCAL.link().code)) === link.code);
  check('no JavaScript errors', errors.length === 0, errors.join(' | '));
  await b.close();
  srv.close();
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
