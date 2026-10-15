// The app without a server (app/local/engine.js) against a pretend Bale Bot API:
// /start → chat linked; a tap under an ESP32 SMS → booked on its card; a Reply → recorded
// with what it was for; the Excel file; the books survive a reload.
//   node tests/local_app_test.js      (CHROME=/path/to/chrome optional)
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path'), os = require('os');
const { execSync } = require('child_process');
const ROOT = path.resolve(__dirname, '../..');
let fails = 0;
const check = (n, ok, x = '') => { console.log((ok ? '  ok   ' : '  FAIL ') + n + (ok ? '' : '  ' + x)); if (!ok) fails++; };

// what the ESP32 (firmware/bale_direct formatForBale) posts into the chat
const SEP = '──────────';
const relay = (head, sms) => head + '\n' + SEP + '\n' + sms + '\n' + SEP + '\n✍️ بابت چی بود؟ دکمه را بزن یا روی همین پیام Reply کن و بنویس.';
const SMS1 = 'بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0718-14:25';
const SMS2 = 'بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n1405/07/20 09:12';

(async () => {
  const W = fs.mkdtempSync(path.join(os.tmpdir(), 'local-'));
  execSync(`sh "${ROOT}/tools/local-app/build.sh" "${W}/www"`, { stdio: 'ignore' });
  const bale = { queue: [], sent: [], calls: [], docs: [], nextId: 1 };
  const types = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.svg': 'image/svg+xml', '.png': 'image/png', '.json': 'application/json', '.webmanifest': 'application/manifest+json' };
  const srv = http.createServer((req, res) => {
    const u = new URL(req.url, 'http://x');
    if (u.pathname.startsWith('/__bale/')) {
      const method = u.pathname.slice(8);
      if (method === 'sendDocument') {
        let body = []; req.on('data', c => body.push(c)); req.on('end', () => { bale.docs.push(Buffer.concat(body)); res.end(JSON.stringify({ ok: true, result: {} })); });
        return;
      }
      const q = JSON.parse(u.searchParams.get('q') || '{}');
      bale.calls.push([method, q]);
      let result = true;
      if (method === 'getMe') result = { id: 1, username: 'my_bank_bot' };
      if (method === 'getUpdates') result = bale.queue.filter(x => x.update_id >= (q.offset || 0));
      if (method === 'sendMessage') { bale.sent.push(q); result = { message_id: 900 + bale.sent.length }; }
      res.setHeader('Content-Type', 'application/json');
      return res.end(JSON.stringify({ ok: true, result }));
    }
    let f = path.join(W, 'www', decodeURIComponent(u.pathname));
    if (f.endsWith('/')) f += 'index.html';
    if (!f.startsWith(path.join(W, 'www')) || !fs.existsSync(f) || fs.statSync(f).isDirectory()) { res.statusCode = 404; return res.end(); }
    res.setHeader('Content-Type', types[path.extname(f)] || 'application/octet-stream');
    fs.createReadStream(f).pipe(res);
  });
  await new Promise(r => srv.listen(0, '127.0.0.1', r));
  const base = `http://127.0.0.1:${srv.address().port}`;
  const upd = (o) => bale.queue.push(Object.assign({ update_id: bale.nextId++ }, o));
  const chat = { id: 555, type: 'private' };

  const b = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 } });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));

  // 1) /start links the chat
  upd({ message: { message_id: 1, chat, date: 1760000000, text: '/start' } });
  await p.goto(base + '/app/');
  await p.waitForTimeout(2500);
  check('home without a server (no login)', await p.isVisible('#tabbar') && !(await p.$('#t')));
  check('accounting and stock tabs hidden', !(await p.isVisible('.tabbar a[data-tab="acc"]')) && !(await p.isVisible('.tabbar a[data-tab="stock"]')));
  check('/start: chat linked, chat id sent back', bale.sent.some(m => m.chat_id === 555 && /chat id: 555/.test(m.text)), JSON.stringify(bale.sent));

  // 2) a tap under the ESP32's Mellat SMS
  upd({ callback_query: { id: 'cb1', data: 'relay:ans', message: { message_id: 10, chat, date: 1760000100, text: relay('🔴 برداشت 250,000 تومان', SMS1) } } });
  // 3) a Reply under the Melli SMS, with what it was for
  upd({ message: { message_id: 21, chat, date: 1760000300, text: 'فروش لپ تاپ از علی رضایی',
    reply_to_message: { message_id: 11, chat, date: 1760000200, text: relay('🟢 واریز 700,000 تومان', SMS2) } } });
  // and someone else's chat is ignored
  upd({ message: { message_id: 30, chat: { id: 999, type: 'private' }, date: 1760000400, text: 'موجودی' } });
  const r = await p.evaluate(() => BA_LOCAL.sync());
  await p.waitForTimeout(800);
  const st = await p.evaluate(() => BA_LOCAL.state());
  const t1 = st.txs.find(t => t.bale_mid === 10), t2 = st.txs.find(t => t.bale_mid === 11);
  const wName = id => (st.wallets.find(w => w.id === id) || {}).name;
  check('tap → booked on the Mellat card, waiting for an answer', t1 && t1.status === 'pending' && t1.amount === 2500000 && t1.direction === 'out' && wName(t1.wallet_id) === 'بانک ملت', JSON.stringify(t1));
  check('tap → button in Bale says it is in the app', bale.calls.some(([m, q]) => m === 'editMessageReplyMarkup' && q.message_id === 10) && bale.calls.some(([m, q]) => m === 'answerCallbackQuery' && q.callback_query_id === 'cb1'));
  check('reply → booked on Melli and recorded with the party', t2 && t2.status === 'confirmed' && t2.amount === 7000000 && t2.party === 'علی رضایی' && wName(t2.wallet_id) === 'بانک ملی', JSON.stringify(t2));
  check('reply → «ثبت شد» in Bale', bale.sent.some(m => m.reply_to_message_id === 21 && /ثبت شد/.test(m.text)));
  check('another chat is not answered', !bale.sent.some(m => m.chat_id === 999));
  check('the same message again is not booked twice', (await p.evaluate(() => { const s = BA_LOCAL.state(); return s.txs.length; })) === 2);

  // 3b) Android: a Bale notification of an ESP32 message (BaleWatch → the orb), no tap
  const SMS3 = 'بانک صادرات\nبرداشت:1,200,000\nمانده:3,300,000\n1405/07/21 10:05';
  const n1 = await p.evaluate(t => BA_LOCAL.notice(t), relay('🔴 برداشت 120,000 تومان', SMS3));
  const n2 = await p.evaluate(t => BA_LOCAL.notice(t), relay('🔴 برداشت 120,000 تومان', SMS3));
  check('notification → booked once, waiting for an answer', n1 && n1.status === 'pending' && n1.amount === 1200000 && n2 === null, JSON.stringify([n1, n2]));
  check('orb: pending list', (await p.evaluate(() => BA_LOCAL.pending())).some(x => x.id === n1.id && x.direction === 'out'));
  upd({ callback_query: { id: 'cb2', data: 'relay:ign', message: { message_id: 12, chat, date: 1760000500, text: relay('🔴 برداشت 120,000 تومان', SMS3) } } });
  await p.evaluate(() => BA_LOCAL.sync());
  check('a tap later on the same SMS: the same transaction', (await p.evaluate(() => BA_LOCAL.state().txs.length)) === 3 && (await p.evaluate(id => BA_LOCAL.state().txs.find(t => t.id === id).status, n1.id)) === 'ignored');

  const n3 = await p.evaluate(t => BA_LOCAL.notice(t), 'ربات بانک\nبانک صادرات\nبرداشت:300,000\nمانده:9,100,000\n1405/07/22 08:30');
  check('notification without the separators (heading + bank SMS) → booked', n3 && n3.amount === 300000 && n3.direction === 'out', JSON.stringify(n3));
  check('a chat message is not a transaction', (await p.evaluate(() => BA_LOCAL.notice('سلام، فردا میای؟'))) === null);
  await p.evaluate(id => { const s = BA_LOCAL.state(); s.txs.find(t => t.id === id).status = 'ignored'; }, n3.id);

  // 3c) the orb's own page (app/local/headless.html)
  const h = await ctx.newPage();
  h.on('pageerror', e => errors.push('headless: ' + e.message));
  await h.addInitScript(() => { window.__done = {}; window.OrbHost = { ready() { window.__ready = true; }, done(id, ok, json) { window.__done[id] = [ok, JSON.parse(json)]; } }; });
  await h.goto(base + '/app/local/headless.html');
  await h.waitForFunction(() => window.__ready);
  await h.evaluate(() => orbCall('a', 'pending', ''));
  await h.waitForFunction(() => window.__done.a);
  check('orb page: answers through OrbHost', await h.evaluate(() => window.__done.a[0] === true && Array.isArray(window.__done.a[1])));
  await h.close();

  // 4) in the app: the pending one, answered by voice-assistant text
  await p.goto(base + '/app/#/');
  await p.waitForTimeout(1200);
  check('card balance from the SMS', await p.evaluate(() => fetch('../api.php?r=wallets').then(r => r.json()).then(d => d.items.find(w => w.bank === 'mellat').bank_balance)) === 45000000);
  const a1 = await p.evaluate(() => fetch('../api.php?r=assistant_web', { method: 'POST', body: JSON.stringify({ text: 'تراکنش‌های بی‌جواب' }) }).then(r => r.json()));
  const a2 = await p.evaluate(() => fetch('../api.php?r=assistant_web', { method: 'POST', body: JSON.stringify({ text: 'خرید لوازم اداری' }) }).then(r => r.json()));
  check('assistant asks about the pending one and records the answer', /بابت چی بود/.test(a1.reply) && /ثبت شد/.test(a2.reply), a1.reply + ' | ' + a2.reply);
  const a3 = await p.evaluate(() => fetch('../api.php?r=assistant_web', { method: 'POST', body: JSON.stringify({ text: 'موجودی ملی' }) }).then(r => r.json()));
  check('assistant: card balance', /بانک ملی/.test(a3.reply), a3.reply);

  // 5) Excel
  const xb = await p.evaluate(() => Array.from(BA_LOCAL.excelBytes()));
  fs.writeFileSync(W + '/h.xlsx', Buffer.from(xb));
  const rows = execSync(`python3 -c "import openpyxl,json;wb=openpyxl.load_workbook('${W}/h.xlsx');print(json.dumps({ws.title:[[c.value for c in r] for r in ws.iter_rows()] for ws in wb},ensure_ascii=False))"`).toString();
  const book = JSON.parse(rows);
  const txSheet = book['تراکنش‌های بانک'] || [];
  check('Excel: sheets and the two transactions in toman', Object.keys(book).join(',') === 'خلاصه,کارت‌ها,تراکنش‌های بانک,طرف‌حساب‌ها'
    && txSheet.some(r => r[5] === -250000 && r[3] === 'بانک ملت' && r[7] === 'خرید لوازم اداری') && txSheet.some(r => r[5] === 700000 && r[8] === 'علی رضایی'), rows.slice(0, 400));
  const docsBefore = bale.docs.length;
  await p.evaluate(() => fetch('../api.php?r=backup_excel_bale', { method: 'POST', body: '{}' }));
  check('Excel to Bale (and once a day by itself)', bale.docs.length === docsBefore + 1 && docsBefore >= 1 && bale.docs[0].includes(Buffer.from('chat_id')), String(bale.docs.length));

  // 6) settings and persistence
  await p.goto(base + '/app/#/settings');
  await p.waitForTimeout(1500);
  const sText = await p.textContent('#app');
  check('settings: Bale (no server) and Excel cards', /نسخه‌ی بدون سرور/.test(sText) && /@my_bank_bot/.test(sText) && /555/.test(sText) && await p.isVisible('#lXlsx'), sText.slice(0, 200));
  await p.reload();
  await p.waitForTimeout(1200);
  check('books survive a reload', (await p.evaluate(() => BA_LOCAL.state().txs.filter(t => t.status === 'confirmed').length)) === 2);
  check('no JavaScript errors', errors.length === 0, errors.join(' | '));
  await b.close();
  srv.close();
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
