"""A pretend Bale Bot API for the emulator / simulator tests of the app without a server:
    python3 tools/demo/fakebale.py 8841
The owner sends /start, taps the button under an ESP32 Mellat SMS and replies under a
Melli one; every call is logged to /tmp/fakebale.log."""
import json, sys, time, re
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

SEP = '──────────'
def relay(head, sms):
    return f"{head}\n{SEP}\n{sms}\n{SEP}\n✍️ بابت چی بود؟ دکمه را بزن یا روی همین پیام Reply کن و بنویس."
CHAT = {'id': 555, 'type': 'private'}
T = int(time.time())
UPDATES = [
    {'update_id': 1, 'message': {'message_id': 1, 'chat': CHAT, 'date': T - 300, 'text': '/start'}},
    {'update_id': 2, 'callback_query': {'id': 'cb1', 'data': 'relay:ans', 'message': {'message_id': 10, 'chat': CHAT, 'date': T - 200,
        'text': relay('🔴 برداشت 250,000 تومان', 'بانک ملت\nبرداشت:2,500,000\nمانده:45,000,000\n0720-14:25')}}},
    {'update_id': 3, 'message': {'message_id': 21, 'chat': CHAT, 'date': T - 100, 'text': 'فروش لپ تاپ از علی رضایی',
        'reply_to_message': {'message_id': 11, 'chat': CHAT, 'date': T - 150,
            'text': relay('🟢 واریز 700,000 تومان', 'بانک ملی ایران\nواریز:7,000,000\nمانده:12,500,000\n10:05')}}},
]

class H(BaseHTTPRequestHandler):
    def log_message(self, *a): pass
    def do_GET(self): self.do_POST()
    def do_POST(self):
        m = re.match(r'^/bot[^/]+/(\w+)', self.path)
        if not m:
            self.send_response(404); self.end_headers(); return
        method = m.group(1)
        raw = self.rfile.read(int(self.headers.get('Content-Length') or 0))
        body = {}
        if 'json' in (self.headers.get('Content-Type') or ''):
            try: body = json.loads(raw or b'{}')
            except Exception: body = {}
        with open('/tmp/fakebale.log', 'a', encoding='utf-8') as f:
            f.write(method + ' ' + (json.dumps(body, ensure_ascii=False) if body else f'<{len(raw)} bytes>') + '\n')
        result = True
        if method == 'getMe': result = {'id': 1, 'username': 'test_bank_bot', 'is_bot': True}
        elif method == 'getUpdates': result = [u for u in UPDATES if u['update_id'] >= int(body.get('offset') or 0)]
        elif method == 'sendMessage': result = {'message_id': 900}
        out = json.dumps({'ok': True, 'result': result}, ensure_ascii=False).encode()
        self.send_response(200)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Content-Length', str(len(out)))
        self.end_headers()
        self.wfile.write(out)

ThreadingHTTPServer(('0.0.0.0', int(sys.argv[1]) if len(sys.argv) > 1 else 8841), H).serve_forever()
