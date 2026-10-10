# HTML-only test build

The phone web app and the accounting panel running from plain files (no PHP):
a pretend server in the browser (`mock.js`) answers every `api.php` request from
sample books recorded from the real server (`snapshot.json`), plus a test lab page
with an ESP32 SMS simulator and a Bale bot simulator.

```
sh tools/demo/run.sh /tmp/demo                       # real server with sample books on :8840
node tools/test-build/record.js tools/test-build/snapshot.json
sh tools/test-build/build.sh tools/test-build/snapshot.json dist/test
```
Open `dist/test/index.html` in Chrome (or upload the folder to any static host).
