// The web app inside the Android app: window.AndroidOrb bridge, orb card, pairing with a one-time code.
//   node tests/android_bridge_test.js   (CHROME=/path/to/chrome optional)
const { chromium } = require('playwright');
const { spawn, execSync } = require('child_process');
const fs = require('fs'), path = require('path'), os = require('os');
const SRC = path.resolve(__dirname, '..'), T = fs.mkdtempSync(path.join(os.tmpdir(), 'and-'));
let fails = 0;
const check = (n, ok, x = '') => { console.log((ok ? '  ok   ' : '  FAIL ') + n + (ok ? '' : '  ' + x)); if (!ok) fails++; };
(async () => {
  execSync(`cp -r "${SRC}" "${T}/s" && rm -f "${T}"/s/data/*.sqlite* "${T}/s/config.php"`);
  fs.writeFileSync(`${T}/s/config.php`, "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran'];");
  const port = 36000 + process.pid % 1000;
  const srv = spawn('php', ['-S', `127.0.0.1:${port}`, '-t', `${T}/s`, `${T}/s/router.php`], { stdio: 'ignore' });
  await new Promise(r => setTimeout(r, 700));
  const b = await chromium.launch({ executablePath: process.env.CHROME || undefined });
  const ctx = await b.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36 BankAssistantAndroid/1' });
  // the native side, recorded
  await ctx.addInitScript(() => {
    // native state outlives page loads, like the real app
    const st = JSON.parse(sessionStorage.getItem('nat') || '{"paired":false,"on":false,"auto":true}');
    window.__calls = JSON.parse(sessionStorage.getItem('calls') || '[]');
    const save = () => { sessionStorage.setItem('nat', JSON.stringify(st)); sessionStorage.setItem('calls', JSON.stringify(__calls)); };
    const push = __calls.push.bind(__calls);
    __calls.push = x => { push(x); save(); };
    window.AndroidOrb = {
      version: () => 1, isPaired: () => st.paired, orbOn: () => st.on, canOverlay: () => true, autoAsk: () => st.auto,
      setAutoAsk: v => { st.auto = v; __calls.push('auto:' + v); save(); },
      pair: code => { __calls.push('pair:' + code); st.paired = /^[A-Z2-9]{8}$/.test(code); save(); setTimeout(() => window.onAndroidPaired(st.paired, ''), 10); },
      startOrb: () => { __calls.push('start'); st.on = true; save(); setTimeout(() => window.onAndroidOrb(), 10); },
      stopOrb: () => { __calls.push('stop'); st.on = false; save(); setTimeout(() => window.onAndroidOrb(), 10); },
      talk: () => __calls.push('talk'), unpair: () => {}, changeServer: () => __calls.push('server'),
    };
  });
  const p = await ctx.newPage();
  const errors = [];
  p.on('pageerror', e => errors.push(e.message));
  await p.goto(`http://127.0.0.1:${port}/app/?android=1`);
  await p.fill('#t', 'apppass123');
  await p.click('#f button');
  await p.waitForTimeout(2000);
  let calls = await p.evaluate(() => __calls);
  check('paired by itself with a one-time code', calls.some(c => /^pair:[A-Z2-9]{8}$/.test(c)), JSON.stringify(calls));
  check('home: orb chip', (await p.textContent('#ghAnd')).includes('orb'));
  await p.click('#ghAnd');
  await p.waitForTimeout(300);
  check('home chip turns the orb on', (await p.evaluate(() => __calls)).includes('start'));
  await p.goto(`http://127.0.0.1:${port}/app/#/settings`);
  await p.waitForTimeout(1200);
  check('settings: Android card, no iPhone card', await p.isVisible('#andBox') && !(await p.$('#iosPair')));
  check('orb shown as on', (await p.textContent('#andBox')).includes('روشن است'));
  await p.uncheck('#andAuto');
  await p.click('#andTalk');
  await p.click('#andStop');
  await p.waitForTimeout(400);
  calls = await p.evaluate(() => __calls);
  check('auto-ask off, talk, stop reach the native side', calls.includes('auto:false') && calls.includes('talk') && calls.includes('stop'), JSON.stringify(calls));
  check('orb shown as off', (await p.textContent('#andBox')).includes('خاموش است'));
  // Android browser without the app: download card
  const c2 = await b.newContext({ viewport: { width: 390, height: 844 }, userAgent: 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/126 Mobile Safari/537.36' });
  const p2 = await c2.newPage();
  await p2.goto(`http://127.0.0.1:${port}/app/`);
  await p2.fill('#t', 'apppass123');
  await p2.click('#f button');
  await p2.waitForTimeout(1500);
  await p2.goto(`http://127.0.0.1:${port}/app/#/settings`);
  await p2.waitForTimeout(1000);
  check('Android browser: download the app', await p2.isVisible('a[href="android/bank-assistant.apk"]'));
  check('no JavaScript errors', errors.length === 0, errors.join(' | '));
  await b.close();
  srv.kill();
  execSync(`rm -rf "${T}"`);
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})();
