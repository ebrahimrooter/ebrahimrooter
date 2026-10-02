// Runs app/sw.js in a simulated service-worker scope and fires the events an
// iPhone would: a push arriving, then a tap on the notification.
//   node tests/sw_test.js   (from server/)
const fs = require('fs');
const vm = require('vm');
let fails = 0;
const check = (name, ok) => { console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}`); if (!ok) fails++; };

function makeScope(clients) {
  const listeners = {};
  const shown = [], opened = [], badges = [], posted = [];
  const self = {
    addEventListener: (t, f) => { listeners[t] = f; },
    registration: { scope: 'https://bank.example.ir/bank/app/', showNotification: (title, opt) => { shown.push({ title, opt }); return Promise.resolve(); } },
    navigator: { setAppBadge: n => { badges.push(n); return Promise.resolve(); }, clearAppBadge: () => { badges.push(0); return Promise.resolve(); } },
    clients: {
      matchAll: () => Promise.resolve(clients.map(u => ({ url: u, focus: () => Promise.resolve(), postMessage: m => posted.push(m) }))),
      openWindow: u => { opened.push(u); return Promise.resolve(); },
      claim: () => Promise.resolve()
    },
    skipWaiting: () => Promise.resolve()
  };
  const ctx = { self, caches: {}, URL, location: { origin: 'https://bank.example.ir' }, fetch: () => Promise.reject() };
  vm.runInNewContext(fs.readFileSync(__dirname + '/../app/sw.js', 'utf8'), ctx);
  return { listeners, shown, opened, badges, posted };
}
const fire = (l, type, ev) => { let p = Promise.resolve(); ev.waitUntil = x => { p = x; }; l[type](ev); return p; };

(async () => {
  console.log('push arrives (app closed)');
  let s = makeScope([]);
  const msg = { title: '🟢 واریز 5,000 تومان', body: 'بابت چی بود؟ بزن تا بپرسم.', url: '#/orb/7', tag: 'tx-7', badge: 2 };
  await fire(s.listeners, 'push', { data: { json: () => msg, text: () => JSON.stringify(msg) } });
  check('notification shown with title/body', s.shown.length === 1 && s.shown[0].title === msg.title && s.shown[0].opt.body === msg.body);
  check('RTL Persian, tagged, keeps the orb link', s.shown[0].opt.dir === 'rtl' && s.shown[0].opt.tag === 'tx-7' && s.shown[0].opt.data.url === '#/orb/7');
  check('app icon badge = 2', s.badges[0] === 2);

  console.log('tap on it, app not running');
  await fire(s.listeners, 'notificationclick', { notification: { data: { url: '#/orb/7' }, close() {} } });
  check('opens the app on that transaction', s.opened[0] === 'https://bank.example.ir/bank/app/#/orb/7');

  console.log('tap on it, app already open');
  s = makeScope(['https://bank.example.ir/bank/app/#/history']);
  await fire(s.listeners, 'notificationclick', { notification: { data: { url: '#/orb/9' }, close() {} } });
  check('focuses the open app and tells it which transaction', s.opened.length === 0 && s.posted[0] && s.posted[0].url.endsWith('#/orb/9'));

  console.log('odd payloads');
  s = makeScope([]);
  await fire(s.listeners, 'push', { data: { json: () => { throw new Error('x'); }, text: () => 'plain text' } });
  check('non-JSON push still shows something', s.shown[0] && s.shown[0].opt.body === 'plain text');
  await fire(s.listeners, 'push', { data: { json: () => ({ title: 'x', badge: 0 }) } });
  check('badge 0 clears the icon number', s.badges.includes(0));

  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
})();
