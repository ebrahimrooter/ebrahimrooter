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
  const tabs = await page.$$eval('#tabbar a', as => as.map(a => a.textContent.trim()));
  check('only two tabs', tabs.length === 2, JSON.stringify(tabs));
  const rows = await page.$$eval('.txlist .item', xs => xs.map(x => x.textContent));
  check('both transactions listed', rows.length === 2, JSON.stringify(rows));
  check('pending marked', rows.some(r => r.includes('بی‌جواب')));
  check('totals', (await page.textContent('.stat')).includes('۲٬۵۰۰٬۰۰۰'));
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
  const siri = await page.evaluate(() => ({ mode: document.body.classList.contains('siri-mode'), open: document.getElementById('orbStage').classList.contains('open'),
    line: document.getElementById('orbLine').textContent, third: document.getElementById('orbStage').getBoundingClientRect().height < innerHeight * 0.45 }));
  check('notification opens the orb in the bottom third', siri.mode && siri.open && siri.third && siri.line.includes('برداشت'), JSON.stringify(siri));
  await page.screenshot({ path: OUT + '/app-siri.png' });
  await page.click('#orbClose');
  await page.waitForTimeout(600);
  check('closing the orb goes back to the list', await page.evaluate(() => !document.body.classList.contains('siri-mode') && location.hash === '#/'));
  // the page that was open stays exactly as it was behind the orb
  await page.evaluate(() => { location.hash = '#/settings'; });
  await page.waitForTimeout(800);
  const before = await page.evaluate(() => document.getElementById('app').innerHTML.length);
  await page.evaluate(() => { location.hash = '#/orb/2'; });
  await page.waitForTimeout(1200);
  const under = await page.evaluate(() => ({ app: getComputedStyle(document.getElementById('app')).visibility, tabs: getComputedStyle(document.getElementById('tabbar')).visibility,
    bg: getComputedStyle(document.body).backgroundColor, same: document.getElementById('app').innerHTML.length }));
  check('only the orb: no app page or tabs behind it', under.app === 'hidden' && under.tabs === 'hidden' && under.bg === 'rgb(0, 0, 0)' && under.same === before, JSON.stringify(under));
  await page.screenshot({ path: OUT + '/app-siri-settings.png' });
  await page.click('#orbClose');
  await page.waitForTimeout(500);
  check('back on the same page', await page.evaluate(() => location.hash === '#/settings'));
  // cold start from the notification (app was closed): just the orb, nothing of the app
  const cold = page;
  await cold.goto('about:blank');
  await cold.goto('http://127.0.0.1:8834/app/#/orb/2');
  await cold.waitForTimeout(1800);
  const c = await cold.evaluate(() => ({ mode: document.body.classList.contains('siri-mode'), empty: document.getElementById('app').innerHTML.trim() === '',
    open: document.getElementById('orbStage').classList.contains('open') }));
  check('cold start: only the orb is drawn', c.mode && c.empty && c.open, JSON.stringify(c));
  await cold.screenshot({ path: OUT + '/app-siri.png' });
  await cold.click('#orbClose');
  await cold.waitForTimeout(800);
  check('after it closes (where the window cannot close) the list appears', (await cold.$$('.txlist .item')).length > 0);
  check('no JavaScript errors', errors.length === 0, JSON.stringify(errors.slice(0, 5)));
  await browser.close();
  srv.kill();
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); srv.kill(); process.exit(1); });
