const { chromium } = require('playwright');
const fs = require('fs');
const DIR = process.argv[2];
(async () => {
  const b = await chromium.launch({ executablePath: process.env.CHROME });
  const p = await b.newPage({ viewport: { width: 480, height: 480 } });
  await p.goto('file://' + DIR + '/orb.html');
  await p.waitForTimeout(2500);
  await p.evaluate(() => document.fonts.ready);
  const N = 120;
  for (const ph of ['idle', 'listening', 'processing', 'speaking']) {
    fs.mkdirSync(`${DIR}/${ph}`, { recursive: true });
    for (let i = 0; i < N; i++) {
      const url = await p.evaluate(([ph, i, N]) => window.draw(ph, i, N), [ph, i, N]);
      fs.writeFileSync(`${DIR}/${ph}/${String(i).padStart(4, '0')}.png`, Buffer.from(url.split(',')[1], 'base64'));
    }
  }
  const fontOk = await p.evaluate(() => document.fonts.check('800 34px Vazirmatn'));
  console.log('font', fontOk);
  await b.close();
})();
