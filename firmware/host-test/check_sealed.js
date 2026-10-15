// Opens what the firmware sealed (build/sealed.txt) with the app's code: the same bytes both ways.
const L = require('../../server/app/local/link.js');
const fs = require('fs');
const k = L.keys('TESTCODE2345ABCD');
const lines = fs.readFileSync(process.argv[2], 'utf8').trim().split('\n');
let fails = 0;
for (const line of lines) {
  const m = L.open(k, line);
  if (!m || !m.id || typeof m.text !== 'string') { console.log('  FAIL the app cannot open: ' + line.slice(0, 40)); fails++; continue; }
  console.log('  ok   the app opens the firmware\'s message: ' + m.from + ' · ' + m.text.split('\n')[1]);
}
const st = L.open(k, fs.readFileSync(process.argv[3], 'utf8').trim());
if (st && st.sig === 18) console.log('  ok   the app reads the status (signal 18)'); else { console.log('  FAIL status'); fails++; }
console.log(fails ? fails + ' FAILED' : 'all passed');
process.exit(fails ? 1 : 0);
