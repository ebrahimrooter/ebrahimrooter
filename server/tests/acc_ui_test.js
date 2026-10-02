// Browser test of the accounting UI:  npm i playwright && node tests/acc_ui_test.js
// (set CHROME=/path/to/chrome to use an installed browser)
// Browser test of the accounting UI on PHP's built-in server.
const { chromium } = require('playwright');
const { spawn, execSync } = require('child_process');
const fs = require('fs');
const path = require('path'), os = require('os');
const SRC = path.resolve(__dirname, '..'), T = process.argv[2] || path.join(os.tmpdir(), 'acc-ui-' + process.pid), OUT = process.argv[3] || T;
execSync(`rm -rf ${T} && mkdir -p ${T} && cp -r ${SRC} ${T}/s && rm -f ${T}/s/config.php ${T}/s/data/*.sqlite*`);
fs.writeFileSync(`${T}/s/config.php`, "<?php return ['app_token' => 'apppass123', 'device_token' => 'd', 'timezone' => 'Asia/Tehran'];");
const srv = spawn('php', ['-S', '127.0.0.1:8833', '-t', `${T}/s`, `${T}/s/router.php`], { stdio: 'ignore' });
let fails = 0;
const check = (n, ok, extra = '') => { console.log((ok ? '  ok   ' : '  FAIL ') + n + (ok ? '' : ' ' + extra)); if (!ok) fails++; };
(async () => {
  await new Promise(r => setTimeout(r, 700));
  const browser = await chromium.launch({ executablePath: process.env.CHROME || undefined }).catch(() => chromium.launch());
  const page = await browser.newPage({ viewport: { width: 1366, height: 860 }, locale: 'fa-IR' });
  const errors = [], external = [];
  page.on('pageerror', e => errors.push(e.message + ' @ ' + (e.stack || '').split('\n').slice(0,3).join(' / ')));
  page.on('response', r => { if (r.status() >= 400) errors.push('HTTP ' + r.status() + ' ' + r.url()); });
  page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  page.on('request', r => { if (!r.url().startsWith('http://127.0.0.1:8833')) external.push(r.url()); });
  page.on('dialog', d => d.accept());
  await page.goto('http://127.0.0.1:8833/acc/');
  await page.waitForTimeout(500);
  check('login screen shown, no password prefilled', await page.inputValue('input[x-model="loginForm.password"]') === '');
  await page.fill('input[x-model="loginForm.password"]', 'apppass123');
  await page.click('form button[type=submit], button:has-text("ورود")');
  await page.waitForTimeout(1200);
  const st = () => page.evaluate(() => { const d = document.body._x_dataStack[0]; return { logged: d.isLoggedIn, page: d.currentPage }; });
  check('logged in', (await st()).logged);
  await page.screenshot({ path: OUT + '/1-dashboard.png' });
  const font = await page.evaluate(() => document.fonts.check('16px Vazirmatn', 'سلام'));
  check('Vazirmatn font loaded from this server', font);
  // person
  await page.evaluate(() => document.body._x_dataStack[0].currentPage = 'persons');
  await page.click('button[\\@click="openPersonModal()"]');
  await page.fill('input[x-model="personForm.name"]', 'مشتری مرورگر');
  await page.fill('input[x-model="personForm.national_id"]', '0012345678');
  await page.click('button[\\@click="savePerson"]');
  await page.waitForTimeout(800);
  // product
  await page.evaluate(() => document.body._x_dataStack[0].currentPage = 'products');
  await page.click('button[\\@click="openProductModal()"]');
  await page.fill('input[x-model="productForm.name"]', 'کالای مرورگر');
  await page.fill('input[x-model\\.number="productForm.buy_price"]', '1000');
  await page.fill('input[x-model\\.number="productForm.sale_price"]', '1500');
  await page.fill('input[x-model\\.number="productForm.stock"]', '20');
  await page.click('button[\\@click="saveProduct"]');
  await page.waitForTimeout(800);
  const d1 = await page.evaluate(() => { const d = document.body._x_dataStack[0]; return { persons: d.persons.map(p => [p.name, p.national_id]), products: d.products.map(p => [p.name, p.stock]) }; });
  check('person saved with national id', JSON.stringify(d1.persons) === JSON.stringify([['مشتری مرورگر', '0012345678']]), JSON.stringify(d1.persons));
  check('product saved with opening stock', JSON.stringify(d1.products) === JSON.stringify([['کالای مرورگر', 20]]), JSON.stringify(d1.products));
  // sale invoice
  await page.evaluate(() => document.body._x_dataStack[0].currentPage = 'sales');
  await page.click('button[\\@click="openInvoiceModal(\'sale\')"]');
  await page.waitForTimeout(300);
  await page.selectOption('select[x-model="invoiceForm.person_id"]', { index: 1 });
  await page.selectOption('select[x-model="item.product_id"]', { index: 1 });
  await page.fill('input[x-model\\.number="item.qty"]', '2');
  await page.fill('input[x-model\\.number="item.price"]', '1500');
  await page.screenshot({ path: OUT + '/2-invoice-form.png' });
  await page.click('button[\\@click="saveInvoice"]');
  await page.waitForTimeout(1000);
  const d2 = await page.evaluate(() => { const d = document.body._x_dataStack[0]; return { sales: d.salesInvoices.map(i => [i.number, i.total]), stock: d.products[0].stock, bal: d.persons[0].balance }; });
  check('sale invoice from the form: 3000 + VAT', JSON.stringify(d2.sales) === JSON.stringify([['SF-0001', 3300]]), JSON.stringify(d2));
  check('stock and customer balance updated', d2.stock === 18 && d2.bal === 3300, JSON.stringify(d2));
  await page.screenshot({ path: OUT + '/3-sales.png' });
  // every page renders without errors
  for (const p of ['dashboard', 'persons', 'tax', 'warehouse', 'products', 'sales', 'purchases', 'treasury', 'accounting', 'reports', 'bank', 'more', 'mreports', 'sms', 'settings']) {
    await page.evaluate(x => document.body._x_dataStack[0].currentPage = x, p);
    await page.waitForTimeout(250);
    const shown = await page.evaluate(x => { const el = [...document.querySelectorAll('div[x-show]')].find(d => d.getAttribute('x-show').replace(/\s/g, '') === "currentPage==='" + x + "'"); return !!(el && el.offsetParent); }, p);
    check('page "' + p + '" is visible when chosen', shown);
    const strays = await page.evaluate(x => {
      const pageDivs = [...document.querySelectorAll('div[x-show]')].filter(e => /^currentPage===/.test(e.getAttribute('x-show').replace(/\s/g, '')));
      const own = pageDivs.filter(e => e.getAttribute('x-show').replace(/\s/g, '').startsWith("currentPage==='" + x + "'"));
      return [...pageDivs[0].parentElement.children].filter(c => c.offsetParent && !own.includes(c)).map(c => c.textContent.trim().slice(0, 30));
    }, p);
    check('page "' + p + '" shows nothing from other pages', strays.length === 0, JSON.stringify(strays));
    if (['accounting', 'treasury', 'reports', 'tax', 'warehouse', 'settings'].includes(p)) await page.screenshot({ path: `${OUT}/4-${p}.png` });
  }
  // SMS panel (test provider): single message goes to the history
  await page.evaluate(() => { const d = document.body._x_dataStack[0]; return d.req('/api/sms/settings', { method: 'PUT', body: JSON.stringify({ sms_provider: 'test' }) }); });
  await page.evaluate(() => { const d = document.body._x_dataStack[0]; d.currentPage = 'sms'; d.smsLoad(); });
  await page.waitForTimeout(500);
  await page.fill('input[x-model="sms.single.mobile"]', '09121234567');
  await page.fill('textarea[x-model="sms.single.text"]', 'سلام از پنل');
  await page.click('button[\\@click="smsSendSingle()"]');
  await page.waitForTimeout(800);
  await page.evaluate(() => { const d = document.body._x_dataStack[0]; d.sms.tab = 'log'; return d.smsLoadLog(); });
  await page.waitForTimeout(400);
  const lg = await page.evaluate(() => document.body._x_dataStack[0].sms.log.map(r => [r.mobile, r.status]));
  check('SMS panel: sent message in the history', JSON.stringify(lg) === JSON.stringify([['09121234567', 'sent']]), JSON.stringify(lg));
  await page.screenshot({ path: OUT + '/5-sms.png' });
  await page.evaluate(() => document.body._x_dataStack[0].loadBalanceSheet && document.body._x_dataStack[0].loadBalanceSheet());
  await page.waitForTimeout(500);
  // company settings really saved (VAT rate field)
  await page.evaluate(() => document.body._x_dataStack[0].currentPage = 'settings');
  await page.waitForTimeout(300);
  await page.fill('input[x-model\\.number="company.vat_rate"]', '9');
  await page.click('button[\\@click="saveCompany"]');
  await page.waitForTimeout(600);
  const vat = await page.evaluate(() => document.body._x_dataStack[0].req('/api/company').then(c => c.vat_rate));
  check('VAT rate saved from the settings page', +vat === 9, String(vat));
  // more features: every tab and every report opens without errors
  for (const t of await page.evaluate(() => document.body._x_dataStack[0].moreTabs.map(x => x[0]))) {
    await page.evaluate(x => { const d = document.body._x_dataStack[0]; d.currentPage = 'more'; d.moreSetTab(x); }, t);
    await page.waitForTimeout(300);
  }
  await page.evaluate(() => { const d = document.body._x_dataStack[0]; d.moreSetTab('phonebook'); });
  await page.waitForTimeout(300);
  await page.fill('input[x-model="more.form.name"]', 'تعمیرکار');
  await page.fill('input[x-model="more.form.phones"]', '02122223333');
  await page.click('button[\\@click="moreSave()"] >> visible=true');
  await page.waitForTimeout(500);
  check('phone book entry saved', await page.evaluate(() => document.body._x_dataStack[0].more.rows.some(r => r.name === 'تعمیرکار')));
  await page.screenshot({ path: OUT + '/6-more.png' });
  for (const t of await page.evaluate(() => document.body._x_dataStack[0].repTabs.map(x => x[0]))) {
    await page.evaluate(x => { const d = document.body._x_dataStack[0]; d.currentPage = 'mreports'; if (x === 'cash') d.rep.account = d.accounts[0]?.id || ''; d.repSetTab(x); }, t);
    await page.waitForTimeout(400);
  }
  await page.evaluate(() => document.body._x_dataStack[0].repSetTab('profit'));
  await page.waitForTimeout(500);
  check('profit report rows', await page.evaluate(() => document.body._x_dataStack[0].repRows.length > 0));
  await page.screenshot({ path: OUT + '/7-mreports.png' });
  // print opens with the token
  const [popup] = await Promise.all([page.waitForEvent('popup'), page.evaluate(() => { const d = document.body._x_dataStack[0]; d.printInvoice(d.salesInvoices[0]); })]);
  await popup.waitForLoadState();
  check('print page opens (token in link)', (await popup.content()).includes('فاکتور فروش'));
  check('no JavaScript errors', errors.length === 0, JSON.stringify(errors.slice(0, 5)));
  check('nothing loaded from outside the server', external.length === 0, JSON.stringify(external.slice(0, 5)));
  await browser.close();
  srv.kill();
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})().catch(e => { console.error(e); srv.kill(); process.exit(1); });
