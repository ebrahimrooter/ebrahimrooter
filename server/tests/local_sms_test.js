// The app's own SMS parser (app/local/sms.js) must read every SMS exactly as server/lib.php does.
//   php tests/local_sms_corpus.php > tests/fixtures/sms_corpus.json && node tests/local_sms_test.js
const S = require('../app/local/sms.js');
const C = require('./fixtures/sms_corpus.json');
let fails = 0;
const same = (a, b) => JSON.stringify(a) === JSON.stringify(b);
for (const c of C.cases) {
  const got = { sms: S.parseSms(c.text), otp: (o => o && { code: o.code, amount: o.amount, merchant: o.merchant })(S.parseOtp(c.text)), bank: S.detectBank('', c.text) };
  for (const k of ['sms', 'otp', 'bank']) {
    if (!same(got[k], c[k])) { fails++; console.log('  FAIL ' + k + ': ' + JSON.stringify(c.text).slice(0, 90) + '\n     php: ' + JSON.stringify(c[k]) + '\n     js:  ' + JSON.stringify(got[k])); }
  }
}
// calendar both ways
for (const [g, j] of [['2026-10-14', '1405/07/22'], ['2025-03-21', '1404/01/01'], ['2024-03-20', '1403/01/01'], ['2026-03-20', '1404/12/29']]) {
  if (S.isoToJalali(g) !== j || S.jalaliToIso(j) !== g) { fails++; console.log('  FAIL calendar ' + g + ' ' + j + ' -> ' + S.isoToJalali(g) + ' / ' + S.jalaliToIso(j)); }
}
console.log(C.cases.length + ' SMS compared with the server');
console.log(fails ? fails + ' FAILED' : 'all passed');
process.exit(fails ? 1 : 0);
