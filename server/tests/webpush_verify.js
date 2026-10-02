// Independent check of server/webpush.php (no shared code with it):
//  1. creates a browser-like subscription (P-256 key + auth secret)
//  2. lets PHP encrypt a message for it and send it to a local fake push service
//  3. verifies the VAPID JWT signature and decrypts the body per RFC 8291/8188
// usage: node tests/webpush_verify.js   (from server/)
const crypto = require('crypto');
const http = require('http');
const { execFile } = require('child_process');
const fs = require('fs');
const os = require('os');
const path = require('path');

const b64u = b => Buffer.from(b).toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
const unb64u = s => Buffer.from(s.replace(/-/g, '+').replace(/_/g, '/'), 'base64');
let fails = 0;
const check = (name, ok) => { console.log(`  ${ok ? 'ok  ' : 'FAIL'} ${name}`); if (!ok) fails++; };

const ua = crypto.createECDH('prime256v1'); ua.generateKeys();
const auth = crypto.randomBytes(16);
const hits = [];
const srv = http.createServer((req, res) => {
  const chunks = []; req.on('data', c => chunks.push(c));
  req.on('end', () => { hits.push({ url: req.url, headers: req.headers, body: Buffer.concat(chunks) }); res.writeHead(req.url.includes('gone') ? 410 : 201); res.end(); });
});
let port;
srv.listen(0, '127.0.0.1', () => {
  port = srv.address().port;
  const tmp = fs.mkdtempSync(path.join(os.tmpdir(), 'wp-'));
  fs.cpSync(__dirname + '/..', tmp + '/s', { recursive: true });
  for (const f of fs.readdirSync(tmp + '/s/data')) if (f.endsWith('.sqlite')) fs.unlinkSync(tmp + '/s/data/' + f);
  fs.writeFileSync(tmp + '/s/config.php', "<?php return ['app_token'=>'a','device_token'=>'d','timezone'=>'Asia/Tehran','app_url'=>'https://bank.example.ir/bank/app/'];");
  const php = `<?php require '${tmp}/s/webpush.php';
    $db = ba_db();
    foreach (['ok','gone'] as $n) $db->prepare('INSERT INTO push_subs (endpoint,p256dh,auth,created_at) VALUES (?,?,?,?)')
      ->execute(['http://127.0.0.1:${port}/push/'.$n, '${b64u(ua.getPublicKey())}', '${b64u(auth)}', 'now']);
    echo wp_notify_all(['title'=>'🟢 واریز 5,000 تومان','body'=>'بابت چی بود؟','url'=>'#/orb/7','tag'=>'tx-7','badge'=>1]), '|';
    echo (int)$db->query('SELECT COUNT(*) FROM push_subs')->fetchColumn();`;
  fs.writeFileSync(tmp + '/run.php', php);
  execFile('php', [tmp + '/run.php'], (err, stdout) => verify(stdout.toString(), tmp));
});

function verify(out, tmp) {
  check('PHP reports 1 delivered, expired subscription removed', out === '1|1');
  const h = hits.find(x => x.url === '/push/ok');
  check('headers: aes128gcm, TTL, Urgency high', h && h.headers['content-encoding'] === 'aes128gcm' && h.headers.ttl === '86400' && h.headers.urgency === 'high');

  // VAPID: "vapid t=<jwt>, k=<public key>"
  const m = /^vapid t=([^,]+), k=(.+)$/.exec(h.headers.authorization || '');
  check('VAPID header format', !!m);
  const [hd, pl, sig] = m[1].split('.');
  const claims = JSON.parse(unb64u(pl));
  check('JWT aud = push service origin, sub = site, exp in future', claims.aud === `http://127.0.0.1:${port}` && claims.sub === 'https://bank.example.ir' && claims.exp > Date.now() / 1000);
  const pub = crypto.createPublicKey({ key: { kty: 'EC', crv: 'P-256', x: b64u(unb64u(m[2]).subarray(1, 33)), y: b64u(unb64u(m[2]).subarray(33)) }, format: 'jwk' });
  check('JWT ES256 signature valid', crypto.verify('sha256', Buffer.from(hd + '.' + pl), { key: pub, dsaEncoding: 'ieee-p1363' }, unb64u(sig)));

  // RFC 8188 header + RFC 8291 key derivation, then AES-128-GCM decrypt
  const b = h.body;
  const salt = b.subarray(0, 16), rs = b.readUInt32BE(16), idlen = b[20], asPub = b.subarray(21, 21 + idlen);
  check('record size 4096, key id = 65-byte server key', rs === 4096 && idlen === 65);
  const ecdh = ua.computeSecret(asPub);
  const hmac = (k, d) => crypto.createHmac('sha256', k).update(d).digest();
  const prkKey = hmac(auth, ecdh);
  const ikm = hmac(prkKey, Buffer.concat([Buffer.from('WebPush: info\0'), ua.getPublicKey(), asPub, Buffer.from([1])]));
  const prk = hmac(salt, ikm);
  const cek = hmac(prk, Buffer.from('Content-Encoding: aes128gcm\0\x01')).subarray(0, 16);
  const nonce = hmac(prk, Buffer.from('Content-Encoding: nonce\0\x01')).subarray(0, 12);
  const ct = b.subarray(21 + idlen);
  const d = crypto.createDecipheriv('aes-128-gcm', cek, nonce);
  d.setAuthTag(ct.subarray(ct.length - 16));
  let plain;
  try { plain = Buffer.concat([d.update(ct.subarray(0, ct.length - 16)), d.final()]); } catch (e) { plain = null; }
  check('decrypts with the subscription keys (auth tag valid)', !!plain);
  check('last-record delimiter 0x02', plain && plain[plain.length - 1] === 2);
  const msg = plain && JSON.parse(plain.subarray(0, plain.length - 1).toString());
  check('payload intact (Persian text, url, tag, badge)', msg && msg.title === '🟢 واریز 5,000 تومان' && msg.url === '#/orb/7' && msg.tag === 'tx-7' && msg.badge === 1);

  srv.close();
  fs.rmSync(tmp, { recursive: true });
  console.log(fails ? `\n${fails} FAILED` : '\nall passed');
  process.exit(fails ? 1 : 0);
}
