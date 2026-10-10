// Browser test of the phone app (deposits/withdrawals + orb):  node tests/app_ui_test.js
// (set CHROME=/path/to/chrome to use an installed browser)
const { chromium } = require('playwright');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path'), os = require('os');
const SRC = path.resolve(__dirname, '..'), T = process.argv[2] || path.join(os.tmpdir(), 'app-ui-' + process.pid), OUT = process.argv[3] || T;
execSync(`rm -rf ${T} && mkdir -p ${T} && cp -r ${SRC} ${T}/s && rm -f ${T}/s/config.php ${T}/s/data/*.sqlite*`);
fs.writeFileSync(`${T}/s/config.php`, "<?php return ['app_token' => 'apppass123', 'device_token' => 'dev123', 'timezone' => 'Asia/Tehran'];");
const srv = spawn('php', ['-S', '127.0.0.1:8834', '-t', `${T}/s`, `${T}/s/router.php`], { stdio: 'ignore' });
let fails = 0;
const check = (n, ok, extra = '') => { console.log((ok ? '  ok   ' : '  FAIL ') + n + (ok ? '' : ' ' + extra)); if (!ok) fails++; };
(async () => {
  await new Promise(r => setTimeout(r, 700));
  // two bank transactions: one answered, one waiting
  fs.writeFileSync(`${T}/seed.php`, `<?php chdir("${T}/s"); require "lib.php"; $db = ba_db();
    foreach ([["in", 25000000], ["out", 4000000]] as $i => [$d, $a]) { $db->prepare("INSERT INTO transactions (source, direction, amount, bank_date, occurred_at, status, wallet_id) VALUES ('manual', ?, ?, ?, ?, 'pending', 1)")->execute([$d, $a, "1405/07/10", date("Y-m-d H:i:s", time() - 600 * $i)]); }
    ba_confirm_tx(1, "فروش بذر", "علی", 0);`);
  execSync(`php ${T}/seed.php`);
  const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined }).catch(() => chromium.launch());
  const page = await browser.newPage({ viewport: { width: 390, height: 844 }, locale: 'fa-IR', isMobile: true, hasTouch: true });
  const errors = [];
  page.on('pageerror', e => errors.push(e.message));
  page.on('response', r => { if (r.status() >= 400 && !r.url().includes('fonts.g')) errors.push('HTTP ' + r.status() + ' ' + r.url()); });
  page.on('dialog', d => d.accept());
  await page.goto('http://127.0.0.1:8834/app/');
  await page.fill('#t', 'apppass123');
  await page.click('#f button');
  await page.waitForTimeout(1500);
  check('home shows the orb', await page.isVisible('#homeOrb .orb'));
  check('orb drawn by thinking-orbs (canvas)', await page.isVisible('#homeOrb .orb canvas'));
  const tabs = await page.$$eval('#tabbar a', as => as.map(a => a.textContent.trim()));
  check('four tabs: deposits/withdrawals, accounting, goods & warehouse, settings', tabs.length === 4 && tabs.some(t => t.includes('کالا و انبار')), JSON.stringify(tabs));
  const rows = await page.$$eval('.txlist .item', xs => xs.map(x => x.textContent));
  check('both transactions listed', rows.length === 2, JSON.stringify(rows));
  check('pending marked', rows.some(r => r.includes('بی‌جواب')));
  check('totals', (await page.textContent('.stat2')).includes('۲٬۵۰۰٬۰۰۰'));
  await page.screenshot({ path: OUT + '/app-home.png' });
  await page.click('.chip[data-f="out"]');
  await page.waitForTimeout(600);
  check('filter: withdrawals only', (await page.$$('.txlist .item')).length === 1);
  await page.click('.chip[data-f="all"]');
  await page.waitForTimeout(600);
  // tapping the orb opens the voice stage (or the form when the browser has no speech)
  await page.click('#homeOrb');
  await page.waitForTimeout(1200);
  const opened = await page.evaluate(() => ((document.getElementById('orbStage') || {}).className || '') + ' ' + location.hash);
  check('orb starts the assistant', /open|ask/.test(opened), opened);
  await page.screenshot({ path: OUT + '/app-orb.png' });
  // opened from a push notification: only the orb, full screen
  await page.evaluate(() => { location.hash = '#/orb/2'; });
  await page.waitForTimeout(1500);
  const siri = await page.evaluate(() => ({ card: /^#\/card\/1/.test(location.hash), panel: !!document.querySelector('.wc.big'), open: document.getElementById('orbStage').classList.contains('open'),
    line: document.getElementById('orbLine').textContent }));
  check('notification opens the card panel and the orb asks there', siri.card && siri.panel && siri.open && siri.line.includes('برداشت'), JSON.stringify(siri));
  await page.screenshot({ path: OUT + '/app-siri.png' });
  const pip = await page.evaluate(() => { const b = document.getElementById('orbPip'); return b && !b.hidden; });
  check('float (picture-in-picture) button where the browser supports it', pip);
  await page.click('#orbPip');
  await page.waitForTimeout(800);
  check('orb floats in a picture-in-picture window', await page.evaluate(() => !!document.pictureInPictureElement));
  await page.click('#orbClose');
  await page.waitForTimeout(1800);
  check('closing the orb closes the floating window too', await page.evaluate(() => !document.pictureInPictureElement));
  await page.waitForTimeout(600);
  check('closing the orb stays on the card panel', await page.evaluate(() => !document.body.classList.contains('siri-mode') && /^#\/card\/1/.test(location.hash) && !!document.querySelector('.wc.big')));
  // home: the cards stacked like Apple Wallet, one per bank
  await page.evaluate(() => { location.hash = '#/'; });
  await page.waitForTimeout(1200);
  const stack = await page.$$eval('.wstack .wc', xs => xs.map(x => x.textContent));
  check('home: Wallet stack of Mellat, Blu, Melli, Saderat + cash', stack.length === 5 && ['ملت', 'بلو', 'ملی', 'صادرات'].every(b => stack.some(t => t.includes(b))), JSON.stringify(stack));
  await page.screenshot({ path: OUT + '/app-cards.png', fullPage: true });
  await page.click('.wst-item:nth-child(3)', { position: { x: 60, y: 24 } });
  await page.waitForTimeout(1200);
  check('tapping a card opens its panel', await page.evaluate(() => /^#\/card\/\d+$/.test(location.hash) && !!document.querySelector('.cp-seg')));
  await page.click('.cp-seg button[data-tab="otp"]');
  await page.waitForTimeout(500);
  check('the card panel has its own «رمز پویا» section behind a PIN', await page.evaluate(() => /\/otp$/.test(location.hash) && !!document.getElementById('pinf')));
  await page.screenshot({ path: OUT + '/app-card-otp.png' });
  await page.click('.cp-seg button[data-tab="set"]');
  await page.waitForTimeout(500);
  check('card settings: bank, last 4 digits, color', await page.evaluate(() => !!document.querySelector('#cardf select[name=bank]') && !!document.querySelector('#cardf input[name=card]')));
  // another card opens on its transactions, not on the section left open on the previous one
  await page.click('.cp-seg button[data-tab="otp"]');
  await page.waitForTimeout(400);
  await page.evaluate(() => { location.hash = '#/'; });
  await page.waitForTimeout(1000);
  await page.click('.wst-item:nth-child(1)', { position: { x: 60, y: 24 } });
  await page.waitForTimeout(1000);
  check('next card opens on «تراکنش‌ها»', await page.evaluate(() => /^#\/card\/\d+$/.test(location.hash) && document.querySelector('.cp-seg button.on').getAttribute('data-tab') === 'tx' && !document.getElementById('pinf')));
  // old «#/otp» links go to a card's own «رمز پویا»
  await page.evaluate(() => { location.hash = '#/otp'; });
  await page.waitForTimeout(1200);
  check('#/otp opens a card\'s «رمز پویا»', await page.evaluate(() => /^#\/card\/\d+\/otp$/.test(location.hash) && !!document.getElementById('pinf')));
  // «کالا و انبار»: every product with its stock (empty books here: the list says so)
  await page.click('#tabbar a[data-tab="stock"]');
  await page.waitForTimeout(1200);
  check('goods & warehouse tab opens', await page.evaluate(() => location.hash === '#/stock' && !!document.querySelector('.st-sum') && !!document.getElementById('stList')));
  // the whole accounting panel inside the app, signed in with the app password
  await page.goto('http://127.0.0.1:8834/app/#/');
  await page.waitForTimeout(1200);
  await page.click('#tabbar a[data-tab="acc"]');
  await page.waitForTimeout(3500);
  const fr = page.frameLocator('#accFrame');
  const accIn = await page.frames().find(f => f.url().includes('/acc/')).evaluate(() => document.body._x_dataStack[0].isLoggedIn);
  check('accounting tab: panel open and signed in without a second login', accIn);
  await page.screenshot({ path: OUT + '/app-acc.png' });
  await fr.locator('.m-menu').click();
  await page.waitForTimeout(500);
  await page.screenshot({ path: OUT + '/app-acc-menu.png' });
  await fr.locator('aside nav a', { hasText: 'فروش' }).first().click();
  await page.waitForTimeout(800);
  const accPage = await page.frames().find(f => f.url().includes('/acc/')).evaluate(() => ({ p: document.body._x_dataStack[0].currentPage, menu: document.body.classList.contains('menu-open') }));
  check('phone menu opens a page and closes', accPage.p === 'sales' && !accPage.menu, JSON.stringify(accPage));
  await page.screenshot({ path: OUT + '/app-acc-sales.png' });
  await fr.locator('button', { hasText: 'فاکتور فروش جدید' }).first().click();
  await page.waitForTimeout(600);
  check('invoice form fits the phone', await page.frames().find(f => f.url().includes('/acc/')).evaluate(() => {
    const m = [...document.querySelectorAll('.fixed.inset-0 > div')].find(d => d.offsetParent);
    return !!m && m.getBoundingClientRect().width <= innerWidth;
  }));
  await page.screenshot({ path: OUT + '/app-acc-invoice.png' });
  await page.frames().find(f => f.url().includes('/acc/')).evaluate(() => { document.body._x_dataStack[0].showInvoiceModal = false; });
  await page.click('#tabbar a[data-tab="home"]');
  await page.waitForTimeout(800);
  check('back to deposits/withdrawals', await page.isVisible('#homeOrb'));
  // cold start from the notification (app was closed): straight to the card's panel, the orb asking
  await page.goto('about:blank');
  await page.goto('http://127.0.0.1:8834/app/#/orb/2');
  await page.waitForTimeout(2000);
  const c = await page.evaluate(() => ({ hash: location.hash, panel: !!document.querySelector('.wc.big'), open: document.getElementById('orbStage').classList.contains('open') }));
  check('cold start: the card panel with the orb', /^#\/card\/1/.test(c.hash) && c.panel && c.open, JSON.stringify(c));
  await page.screenshot({ path: OUT + '/app-card-orb.png' });
  check('no JavaScript errors', errors.length === 0, JSON.stringify(errors.slice(0, 5)));
  await browser.close();
  srv.kill();
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); srv.kill(); process.exit(1); });
