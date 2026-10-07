// Caches the app shell so it opens instantly from the home screen.
// API calls always go to the network (live site data is never cached).
var CACHE = 'bank-assistant-v21';
var SHELL = ['./', 'index.html', 'app.css?v=17', 'app.js?v=21', 'vendor/orbs.js?v=1', 'manifest.webmanifest', 'icon-180.png', 'icon-192.png'];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var url = new URL(e.request.url);
  if (e.request.method !== 'GET' || url.origin !== location.origin || url.pathname.indexOf('api.php') !== -1) return;
  if (url.pathname.indexOf(new URL('./', self.registration.scope).pathname) !== 0) return;
  // Network first, cache as offline fallback, so updates show up right away.
  e.respondWith(fetch(e.request).then(function (res) {
    var copy = res.clone();
    caches.open(CACHE).then(function (c) { c.put(e.request, copy); });
    return res;
  }).catch(function () { return caches.match(e.request); }));
});

// ---- Notifications (Web Push): shown even when the app is closed ----
// The server sends {title, body, url, tag, badge}; see server/webpush.php.
self.addEventListener('push', function (e) {
  var m = {};
  try { m = e.data ? e.data.json() : {}; } catch (err) { m = { body: e.data ? e.data.text() : '' }; }
  var jobs = [self.registration.showNotification(m.title || 'دستیار بانک', {
    body: m.body || '',
    tag: m.tag || undefined,
    renotify: !!m.tag,
    icon: 'icon-192.png',
    badge: 'icon-192.png',
    lang: 'fa',
    dir: 'rtl',
    actions: m.tag ? [{ action: 'answer', title: '🎙 جواب بده' }, { action: 'later', title: 'بعداً' }] : [],
    data: { url: m.url || '#/' }
  })];
  // Number on the app icon = transactions still waiting for an answer.
  if (typeof m.badge === 'number' && self.navigator.setAppBadge) {
    jobs.push(m.badge ? self.navigator.setAppBadge(m.badge) : self.navigator.clearAppBadge());
  }
  e.waitUntil(Promise.all(jobs).catch(function () {}));
});

// Tap on the notification: open (or focus) the app on that transaction; the
// app then shows the orb for it.
self.addEventListener('notificationclick', function (e) {
  e.notification.close();
  if (e.action === 'later') return;
  var target = new URL('./' + ((e.notification.data && e.notification.data.url) || '#/'), self.registration.scope).href;
  e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) {
      var c = list[i];
      if (c.url.indexOf(self.registration.scope) === 0 && 'focus' in c) {
        c.postMessage({ type: 'open', url: target });
        return c.focus();
      }
    }
    return self.clients.openWindow(target);
  }));
});
