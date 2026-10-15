#!/usr/bin/env python3
"""A pretend ESP32 (firmware/app_direct) on an MQTT broker, for the app tests.

  fake_esp32.py publish  CODE HOST PORT            two bank SMS + status, retained (as on the SIM)
  fake_esp32.py sms      CODE HOST PORT ID TEXT    one more bank SMS
  fake_esp32.py wait-ack CODE HOST PORT SECONDS ID...   until the app's ack lists every ID
                                                   (then, like the ESP32, clears those SMS)

Sealing is the one in server/app/local/link.js and firmware/app_direct.
"""
import base64, hashlib, hmac, json, os, re, socket, struct, sys, time

SMS = [
    ('esp000000000001', 'Bank Mellat', 'بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0718-14:25'),
    ('esp000000000002', 'Bank Melli', 'بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n1405/07/20 09:12'),
]


def keys(code):
    code = re.sub(r'[^A-Z0-9]', '', code.upper())
    master = hashlib.sha256(('ba-link-v1:' + code).encode()).digest()
    h = lambda k: hmac.new(master, k, hashlib.sha256).digest()
    return h(b'enc'), h(b'mac'), 'ba/' + h(b'topic').hex()[:16] + '/'


def stream(ke, iv, data):
    out = bytearray()
    for blk in range((len(data) + 31) // 32):
        ks = hmac.new(ke, iv + struct.pack('>I', blk), hashlib.sha256).digest()
        out += bytes(a ^ b for a, b in zip(data[blk * 32:blk * 32 + 32], ks))
    return bytes(out)


def seal(k, obj):
    ke, km, _ = k
    iv = os.urandom(12)
    ct = stream(ke, iv, json.dumps(obj, ensure_ascii=False).encode())
    return base64.b64encode(iv + ct + hmac.new(km, iv + ct, hashlib.sha256).digest()[:16]).decode()


def unseal(k, text):
    ke, km, _ = k
    try:
        b = base64.b64decode(text)
    except Exception:
        return None
    if len(b) < 28:
        return None
    iv, ct, tag = b[:12], b[12:-16], b[-16:]
    if not hmac.compare_digest(hmac.new(km, iv + ct, hashlib.sha256).digest()[:16], tag):
        return None
    return json.loads(stream(ke, iv, ct))


# ---- just enough MQTT 3.1.1

def rlen(n):
    out = bytearray()
    while True:
        d, n = n % 128, n // 128
        out.append(d | (128 if n else 0))
        if not n:
            return bytes(out)


def s16(s):
    b = s.encode()
    return struct.pack('>H', len(b)) + b


def pkt(t, body):
    return bytes([t]) + rlen(len(body)) + body


def connect(host, port, cid):
    s = socket.create_connection((host, port), timeout=10)
    s.sendall(pkt(0x10, s16('MQTT') + bytes([4, 2, 0, 60]) + s16(cid)))
    read(s)
    return s


def read(s):
    h = s.recv(1)
    if not h:
        raise EOFError
    n, mul = 0, 1
    while True:
        b = s.recv(1)[0]
        n += (b & 127) * mul
        mul *= 128
        if not b & 128:
            break
    body = b''
    while len(body) < n:
        body += s.recv(n - len(body))
    return h[0], body


def publish(s, topic, text, retain=True):
    s.sendall(pkt(0x30 | (1 if retain else 0), s16(topic) + text.encode()))


def main():
    cmd, code, host, port = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
    k = keys(code)
    base = k[2]
    if cmd in ('publish', 'sms'):
        s = connect(host, port, 'fake-esp32-pub')
        items = SMS if cmd == 'publish' else [(sys.argv[5], 'Bank Saderat', sys.argv[6])]
        for i, frm, text in items:
            publish(s, base + 'sms/' + i, seal(k, {'v': 1, 'id': i, 'from': frm, 'when': '', 'text': text}))
        publish(s, base + 'st', seal(k, {'v': 1, 'sig': 18, 'wait': len(items), 'fw': 'fake'}))
        s.sendall(bytes([0xe0, 0]))
        s.close()
        print('published', [i for i, _, _ in items], 'on', base)
    elif cmd == 'wait-ack':
        want, until = set(sys.argv[6:]), time.time() + int(sys.argv[5])
        s = connect(host, port, 'fake-esp32-ack')
        s.sendall(pkt(0x82, b'\x00\x01' + s16(base + 'ack') + b'\x00'))
        s.settimeout(5)
        got = set()
        while time.time() < until and not want <= got:
            try:
                t, body = read(s)
            except socket.timeout:
                s.sendall(bytes([0xc0, 0]))
                continue
            if t >> 4 != 3:
                continue
            tl = struct.unpack('>H', body[:2])[0]
            a = unseal(k, body[2 + tl:].decode())
            if a:
                got = set(a.get('ids', []))
        for i in want & got:
            publish(s, base + 'sms/' + i, '')       # like the ESP32: off the broker once the app has it
        s.close()
        print(('esp=ok' if want <= got else 'esp=fail'), 'acked=', sorted(got))
    else:
        sys.exit(__doc__)


if __name__ == '__main__':
    main()
