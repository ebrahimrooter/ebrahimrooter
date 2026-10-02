"""Stand-in for the tax authority's request manager (tests only):
   python3 tests/moadian_mock.py PORT
Checks what a real server checks: the token and every invoice are JWS RS256
signed by the key of the x5c certificate with a critical sigT; invoices are
JWE RSA-OAEP-256/A256GCM for this server's key; the tax id's Verhoeff digit."""
import base64, json, sys, time, uuid
from http.server import BaseHTTPRequestHandler, HTTPServer
from urllib.parse import urlparse, parse_qs
from cryptography import x509
from cryptography.hazmat.primitives import hashes, serialization
from cryptography.hazmat.primitives.asymmetric import padding, rsa
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

KEY = rsa.generate_private_key(public_exponent=65537, key_size=2048)
PUB = base64.b64encode(KEY.public_key().public_bytes(serialization.Encoding.DER, serialization.PublicFormat.SubjectPublicKeyInfo)).decode()
NONCES, REFS, LOG = set(), {}, []

def b64d(s): return base64.urlsafe_b64decode(s + '=' * (-len(s) % 4))

def jws_verify(token):
    h, p, s = token.split('.')
    head = json.loads(b64d(h))
    assert head['alg'] == 'RS256' and 'sigT' in head and head.get('crit') == ['sigT'], head
    cert = x509.load_der_x509_certificate(base64.b64decode(head['x5c'][0]))
    cert.public_key().verify(b64d(s), (h + '.' + p).encode(), padding.PKCS1v15(), hashes.SHA256())
    return b64d(p)

def jwe_decrypt(token):
    h, ek, iv, ct, tag = token.split('.')
    head = json.loads(b64d(h))
    assert head == {'alg': 'RSA-OAEP-256', 'enc': 'A256GCM', 'kid': 'k1'}, head
    cek = KEY.decrypt(b64d(ek), padding.OAEP(mgf=padding.MGF1(hashes.SHA256()), algorithm=hashes.SHA256(), label=None))
    return AESGCM(cek).decrypt(b64d(iv), b64d(ct) + b64d(tag), h.encode()).decode()

D = [[0,1,2,3,4,5,6,7,8,9],[1,2,3,4,0,6,7,8,9,5],[2,3,4,0,1,7,8,9,5,6],[3,4,0,1,2,8,9,5,6,7],[4,0,1,2,3,9,5,6,7,8],[5,9,8,7,6,0,4,3,2,1],[6,5,9,8,7,1,0,4,3,2],[7,6,5,9,8,2,1,0,4,3],[8,7,6,5,9,3,2,1,0,4],[9,8,7,6,5,4,3,2,1,0]]
P = [[0,1,2,3,4,5,6,7,8,9],[1,5,7,6,2,8,3,0,9,4],[5,8,0,3,7,9,6,1,4,2],[8,9,1,6,0,4,3,5,2,7],[9,4,5,3,1,2,7,8,6,0],[4,2,8,6,5,7,3,9,0,1],[2,7,9,3,8,0,6,4,1,5],[7,0,4,6,9,1,3,2,5,8]]
def verhoeff_ok(num):  # validation form: whole number incl. check digit gives 0
    c = 0
    for i, n in enumerate(reversed(num)): c = D[c][P[i % 8][int(n)]]
    return c == 0

def taxid_ok(t):
    mem, days, serial, chk = t[:6], int(t[6:11], 16), int(t[11:21], 16), t[21]
    dec = ''.join(ch if ch.isdigit() else str(ord(ch)) for ch in mem) + str(days).zfill(6) + str(serial).zfill(12)
    return verhoeff_ok(dec + chk)

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def out(self, code, obj):
        b = json.dumps(obj).encode()
        self.send_response(code); self.send_header('Content-Type', 'application/json'); self.send_header('Content-Length', str(len(b))); self.end_headers(); self.wfile.write(b)
    def auth(self):
        tok = self.headers.get('Authorization', '')[7:]
        claims = json.loads(jws_verify(tok))
        assert claims['nonce'] in NONCES and len(claims['clientId']) == 6, claims
        return claims
    def do_GET(self):
        u = urlparse(self.path); q = parse_qs(u.query)
        try:
            if u.path.endswith('/nonce'):
                n = uuid.uuid4().hex; NONCES.add(n); return self.out(200, {'nonce': n, 'expDate': int(time.time() * 1000) + 20000})
            self.auth()
            if u.path.endswith('/server-information'):
                return self.out(200, {'serverTime': int(time.time() * 1000), 'publicKeys': [{'key': PUB, 'id': 'k1', 'algorithm': 'RSA', 'purpose': 1}]})
            if u.path.endswith('/fiscal-information'):
                return self.out(200, {'nameTrade': 'test', 'fiscalStatus': 'ACTIVE', 'economicCode': '10101010101'})
            if u.path.endswith('/inquiry-by-reference-id'):
                return self.out(200, [{'referenceNumber': r, 'status': REFS.get(r, 'FAILED'), 'data': {}} for r in q.get('referenceIds', [])])
            if u.path.endswith('/_log'):
                return self.out(200, LOG)
        except Exception as e:
            return self.out(401, {'message': 'auth: ' + repr(e)})
        self.out(404, {'message': 'not found'})
    def do_POST(self):
        try:
            self.auth()
            packets = json.loads(self.rfile.read(int(self.headers['Content-Length'])))
            res = []
            for p in packets:
                inv = json.loads(jws_verify(jwe_decrypt(p['payload'])))
                h = inv['header']
                assert taxid_ok(h['taxid']), 'taxid checksum ' + h['taxid']
                assert h['taxid'][:6] == p['header']['fiscalId']
                assert abs(h['tbill'] - sum(b['tsstam'] for b in inv['body'])) < 1
                ref = uuid.uuid4().hex; REFS[ref] = 'SUCCESS'; LOG.append(inv)
                res.append({'uid': p['header']['requestTraceId'], 'referenceNumber': ref, 'errorCode': None, 'errorDetail': None})
            self.out(200, {'timestamp': int(time.time() * 1000), 'result': res})
        except Exception as e:
            self.out(400, {'message': repr(e)})

HTTPServer(('127.0.0.1', int(sys.argv[1])), H).serve_forever()
