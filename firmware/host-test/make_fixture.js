// The app's side of the sealing (server/app/local/link.js) for the firmware test:
// an ack sealed by the app for an SMS the firmware will compute the same id for.
//   node make_fixture.js > build/app_fixture.h
const L = require('../../server/app/local/link.js');
const code = 'TESTCODE2345ABCD';
const k = L.keys(code);
const id = L.hex(L.sha256(L.utf8('Bank Melli|26/10/01,10:00:00+14|بانک ملی\nواریز:7,000,000\nمانده:12,500,000'))).slice(0, 16);
const ack = L.seal(k, { v: 1, ids: ['0000000000000000', id] });
const foreign = L.seal(L.keys('OTHERCODE2345678'), { v: 1, ids: [id] });
console.log(`#define APP_TOPIC "ba/${k.id}/"\n#define APP_ACK_ID "${id}"\n#define APP_ACK "${ack}"\n#define FOREIGN_ACK "${foreign}"`);
