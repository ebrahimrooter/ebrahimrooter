/* Bank assistant - phone app.
 * Asks "what was this for?" about every bank SMS, by voice where the phone
 * allows it, and files the answer into the ledger. */
(function () {
  'use strict';

  var API = '../api.php';
  var TOKEN_KEY = 'ba_token';
  var app = document.getElementById('app');
  var state = { me: null, cats: [], parties: [], wallets: [] };

  /* ------------------------------ helpers ------------------------------ */

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  function store(key, val) {
    try {
      if (val === undefined) return localStorage.getItem(key) || '';
      if (val === null) localStorage.removeItem(key); else localStorage.setItem(key, val);
    } catch (e) { /* private mode */ }
    return '';
  }
  function token() { return store(TOKEN_KEY); }
  function faDigits(s) { return String(s == null ? '' : s).replace(/\d/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'[d]; }); }
  function fa(n) { return Number(n || 0).toLocaleString('fa-IR'); }
  function toman(rial) { return fa(Math.round(Math.abs(rial) / 10)) + ' تومان'; }
  function spokenToman(rial) {
    var t = Math.round(Math.abs(rial) / 10), parts = [];
    var m = Math.floor(t / 1e6), k = Math.floor((t % 1e6) / 1000), r = t % 1000;
    if (m) parts.push(m + ' میلیون');
    if (k) parts.push(k + ' هزار');
    if (r) parts.push(String(r));
    return (parts.join(' و ') || '0') + ' تومان';
  }
  function isoDay(offset) {
    var d = new Date(Date.now() + (offset || 0) * 86400000);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  function jalali(iso) {
    try { return new Intl.DateTimeFormat('fa-IR-u-ca-persian', { dateStyle: 'medium' }).format(new Date(iso + 'T12:00:00')); }
    catch (e) { return iso; }
  }
  function when(tx) {
    return tx.bank_date ? faDigits(tx.bank_date + (tx.bank_time ? ' - ' + tx.bank_time : '')) : jalali(tx.occurred_at.slice(0, 10));
  }
  /** Normalise Persian text for matching. */
  function norm(s) {
    return String(s || '').replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ')
      .replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
      .replace(/[.,،!؟?]/g, ' ').replace(/\s+/g, ' ').trim().toLowerCase();
  }

  var toastTimer;
  function toast(msg) {
    var el = document.getElementById('toast');
    el.textContent = msg;
    el.hidden = false;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(function () { el.hidden = true; }, 3200);
  }

  function api(route, opts) {
    opts = opts || {};
    var headers = { 'X-App-Token': token() };
    var body;
    if (opts.form) body = opts.form;
    else if (opts.body) { body = JSON.stringify(opts.body); headers['Content-Type'] = 'application/json'; }
    return fetch(API + '?r=' + route + (opts.query || ''), { method: body ? 'POST' : 'GET', headers: headers, body: body })
      .then(function (res) {
        return res.json().catch(function () { throw new Error('پاسخ سرور قابل خواندن نیست (' + res.status + ')'); })
          .then(function (d) {
            if (res.status === 401) { store(TOKEN_KEY, null); route !== 'me' && location.reload(); }
            if (!d.ok) throw new Error(d.error || 'خطا');
            return d;
          });
      });
  }

  function loadLookups() {
    return Promise.all([api('categories'), api('parties'), api('wallets')]).then(function (r) {
      state.cats = r[0].items;
      state.parties = r[1].items;
      state.wallets = r[2].items;
    });
  }

  function setBadge(n) {
    var b = document.getElementById('pendingBadge');
    b.hidden = !n;
    b.textContent = faDigits(n);
    // the number on the app icon too (installed app)
    try {
      if (navigator.setAppBadge) (n ? navigator.setAppBadge(n) : navigator.clearAppBadge()).catch(function () {});
    } catch (e) { /* not supported */ }
  }

  /* ------------------------------- voice ------------------------------- */

  var Voice = {
    faVoice: null,
    SR: window.SpeechRecognition || window.webkitSpeechRecognition || null,
    srBroken: false,
    ttsBroken: false,
    stop: null,
    audio: null,
    // iPhone/iPad: Safari's Persian speech recognition is unreliable, so when
    // the server can transcribe, record and send the audio instead.
    isIOS: /iPhone|iPad|iPod/.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1),

    init: function () {
      if (!('speechSynthesis' in window)) return;
      var pick = function () {
        var vs = speechSynthesis.getVoices();
        Voice.faVoice = vs.filter(function (v) { return /^fa/i.test(v.lang); })[0] || null;
      };
      pick();
      speechSynthesis.onvoiceschanged = pick;
    },
    canListen: function () {
      return (this.SR && !this.srBroken) || this.canRecord();
    },
    canRecord: function () {
      return !!(state.me && state.me.stt && window.MediaRecorder && navigator.mediaDevices);
    },

    /**
     * Call inside a tap: iOS only lets a page play sound (and use the mic)
     * after a user gesture, so the orb's "answer" button unlocks audio here.
     */
    unlock: function () {
      try {
        if (!Voice.audio) {
          Voice.audio = new Audio();
          Voice.audio.setAttribute('playsinline', '');
        }
        Voice.audio.src = silentWav();
        var p = Voice.audio.play();
        if (p && p.catch) p.catch(function () {});
        audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
        if (audioCtx.state === 'suspended') audioCtx.resume();
      } catch (e) { /* no audio */ }
    },

    /** Persian speech generated on the server (local Piper voice), played through the unlocked element. */
    speakServer: function (text) {
      return fetch(API + '?r=tts', { method: 'POST', headers: { 'X-App-Token': token(), 'Content-Type': 'application/json' }, body: JSON.stringify({ text: text }) })
        .then(function (res) { if (!res.ok) throw new Error('tts ' + res.status); return res.blob(); })
        .then(function (blob) {
          return new Promise(function (resolve, reject) {
            var a = Voice.audio || (Voice.audio = new Audio());
            var url = URL.createObjectURL(blob);
            var done = function () { URL.revokeObjectURL(url); resolve(); };
            a.onended = done;
            a.onerror = function () { URL.revokeObjectURL(url); reject(new Error('play')); };
            a.src = url;
            var p = a.play();
            if (p && p.catch) p.catch(function (e) { URL.revokeObjectURL(url); reject(e); });
            setTimeout(done, 30000);
          });
        });
    },

    /** Speaks: server voice if set up, else the phone's Persian voice, else a chime (the text is on screen). */
    speak: function (text) {
      if (state.me && state.me.tts && !Voice.ttsBroken) {
        return Voice.speakServer(text).catch(function () { Voice.ttsBroken = true; return Voice.speakLocal(text); });
      }
      return Voice.speakLocal(text);
    },

    speakLocal: function (text) {
      return new Promise(function (resolve) {
        if (!Voice.faVoice) { chime(); return setTimeout(resolve, 350); }
        var u = new SpeechSynthesisUtterance(text);
        u.voice = Voice.faVoice;
        u.lang = Voice.faVoice.lang;
        u.onend = u.onerror = function () { resolve(); };
        speechSynthesis.cancel();
        speechSynthesis.speak(u);
        setTimeout(resolve, 20000);
      });
    },

    /** Resolves with what was said ('' for silence). maxMs caps a recording. */
    listen: function (maxMs) {
      if (this.isIOS && this.canRecord()) return this.listenRecord(maxMs);
      if (this.SR && !this.srBroken) {
        return this.listenBrowser(maxMs).catch(function (err) {
          if (/language|not-allowed|service/.test(err.message)) Voice.srBroken = true;
          if (Voice.canRecord()) return Voice.listenRecord(maxMs);
          throw err;
        });
      }
      if (this.canRecord()) return this.listenRecord(maxMs);
      return Promise.reject(new Error('no-recognition'));
    },

    listenBrowser: function (maxMs) {
      return new Promise(function (resolve, reject) {
        var r = new Voice.SR(), got = '', failed = false, settled = false;
        // Some browsers (iOS Safari among them) can start listening and then
        // never report an end or an error: never wait longer than allowed.
        var limit = setTimeout(function () {
          try { r.stop(); } catch (e) { /* already stopped */ }
          setTimeout(function () {
            if (settled) return;
            settled = true;
            Voice.stop = null;
            try { r.abort(); } catch (e) { /* ignore */ }
            resolve(got);
          }, 1500);
        }, (maxMs || 8000) + 2000);
        var finish = function (fn, v) { if (settled) return; settled = true; clearTimeout(limit); fn(v); };
        r.lang = 'fa-IR';
        r.interimResults = false;
        r.maxAlternatives = 1;
        r.onresult = function (e) { got = e.results[0][0].transcript; };
        r.onerror = function (e) {
          failed = true;
          if (e.error === 'no-speech' || e.error === 'aborted') finish(resolve, '');
          else finish(reject, new Error(e.error));
        };
        r.onend = function () { Voice.stop = null; if (!failed) finish(resolve, got); };
        Voice.stop = function () { r.stop(); };
        r.start();
      });
    },

    listenRecord: function (maxMs) {
      return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        return new Promise(function (resolve, reject) {
          var mime = ['audio/mp4', 'audio/webm', 'audio/ogg'].filter(function (m) { return MediaRecorder.isTypeSupported(m); })[0];
          var rec = new MediaRecorder(stream, mime ? { mimeType: mime } : undefined), chunks = [];
          rec.ondataavailable = function (e) { if (e.data.size) chunks.push(e.data); };
          rec.onstop = function () {
            Voice.stop = null;
            stream.getTracks().forEach(function (t) { t.stop(); });
            var blob = new Blob(chunks, { type: rec.mimeType || mime || 'audio/mp4' });
            if (blob.size < 2000) return resolve('');
            var fd = new FormData();
            fd.append('audio', blob, /webm/.test(blob.type) ? 'voice.webm' : /ogg/.test(blob.type) ? 'voice.ogg' : 'voice.m4a');
            api('transcribe', { form: fd }).then(function (d) { resolve(d.text); }, reject);
          };
          Voice.stop = function () { if (rec.state !== 'inactive') rec.stop(); };
          rec.start();
          setTimeout(Voice.stop, maxMs || 8000);
          endOnSilence(stream, function () { if (Voice.stop) Voice.stop(); });
        });
      });
    }
  };

  var audioCtx;

  /** 0.1 s of silence as a WAV data URL (used to unlock audio on iOS). */
  function silentWav() {
    var n = 800, buf = new ArrayBuffer(44 + n * 2), v = new DataView(buf);
    var str = function (o, t) { for (var i = 0; i < t.length; i++) v.setUint8(o + i, t.charCodeAt(i)); };
    str(0, 'RIFF'); v.setUint32(4, 36 + n * 2, true); str(8, 'WAVE'); str(12, 'fmt ');
    v.setUint32(16, 16, true); v.setUint16(20, 1, true); v.setUint16(22, 1, true);
    v.setUint32(24, 8000, true); v.setUint32(28, 16000, true); v.setUint16(32, 2, true); v.setUint16(34, 16, true);
    str(36, 'data'); v.setUint32(40, n * 2, true);
    var bin = '', bytes = new Uint8Array(buf);
    for (var i = 0; i < bytes.length; i++) bin += String.fromCharCode(bytes[i]);
    return 'data:audio/wav;base64,' + btoa(bin);
  }

  /**
   * Stops a recording by itself: after the person spoke and then stayed
   * quiet for 1.3 s, or if nothing was said within 6 s.
   */
  function endOnSilence(stream, stop) {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var src = audioCtx.createMediaStreamSource(stream), an = audioCtx.createAnalyser();
      an.fftSize = 1024;
      src.connect(an);
      var data = new Uint8Array(an.fftSize), started = Date.now(), spoke = false, lastLoud = Date.now();
      var tick = function () {
        if (!stream.active) return;
        an.getByteTimeDomainData(data);
        var sum = 0;
        for (var i = 0; i < data.length; i++) { var x = (data[i] - 128) / 128; sum += x * x; }
        var rms = Math.sqrt(sum / data.length), now = Date.now();
        if (rms > 0.04) { spoke = true; lastLoud = now; }
        if ((spoke && now - lastLoud > 1300) || (!spoke && now - started > 6000)) { src.disconnect(); stop(); return; }
        setTimeout(tick, 100);
      };
      setTimeout(tick, 300);
    } catch (e) { /* no Web Audio: the fixed time limit still applies */ }
  }
  function chime() {
    try {
      audioCtx = audioCtx || new (window.AudioContext || window.webkitAudioContext)();
      var o = audioCtx.createOscillator(), g = audioCtx.createGain();
      o.frequency.value = 880;
      g.gain.setValueAtTime(0.15, audioCtx.currentTime);
      g.gain.exponentialRampToValueAtTime(0.001, audioCtx.currentTime + 0.3);
      o.connect(g); g.connect(audioCtx.destination);
      o.start(); o.stop(audioCtx.currentTime + 0.3);
    } catch (e) { /* no audio */ }
  }

  /* ---------------------- understanding the answer --------------------- */

  // Party/category extraction lives on the server (api ?r=interpret) so the
  // app and the Bale bot understand answers the same way.
  function isYes(s) { return /^(بله|بلی|آره|اره|آری|درسته|درست|تایید|تأیید|ثبت|باشه|اوکی|ok|yes|حتما|آره ثبت)/.test(norm(s)); }
  var KIND_ICON = { party: '👤 ', transfer: '🔁 ', pl: '' };
  function catOption(c, selected) {
    return '<option value="' + c.id + '"' + (String(c.id) === String(selected) ? ' selected' : '') + '>' + (KIND_ICON[c.kind] || '') + esc(c.name) + '</option>';
  }
  function catKind(id) {
    var c = state.cats.filter(function (x) { return String(x.id) === String(id); })[0];
    return c ? c.kind : '';
  }
  function kindHint(kind) {
    return kind === 'party' ? '👤 حساب طرف را تغییر می‌دهد (طلب/بدهی). طرف حساب لازم است.'
      : kind === 'transfer' ? '🔁 جابه‌جایی بین حساب‌های خودت؛ درآمد یا هزینه نیست.'
      : kind === 'pl' ? 'درآمد/هزینه؛ روی طلب و بدهی اشخاص اثر ندارد.' : '';
  }
  function partyList(id) {
    return '<datalist id="' + id + '">' + state.parties.map(function (p) { return '<option value="' + esc(p.name) + '">'; }).join('') + '</datalist>';
  }
  function balanceLabel(b) {
    return b > 0 ? '<span class="in">' + toman(b) + ' بدهکار است</span>'
      : b < 0 ? '<span class="out">' + toman(b) + ' طلبکار است</span>' : '<span class="muted">تسویه</span>';
  }

  function catName(id) {
    var c = state.cats.filter(function (x) { return String(x.id) === String(id); })[0];
    return c ? c.name : '';
  }

  /* ------------------------------- views ------------------------------- */

  function viewLogin() {
    document.getElementById('tabbar').hidden = true;
    app.innerHTML =
      '<h1>دستیار بانک</h1>' +
      '<div class="card"><p class="muted">رمز اپ (همان <b>app_token</b> در config.php سرور) را وارد کن. فقط یک بار لازم است.</p>' +
      '<form id="f"><label for="t">رمز</label><input id="t" type="password" autocomplete="current-password" required>' +
      '<div class="btns"><button class="btn">ورود</button></div></form></div>';
    document.getElementById('f').onsubmit = function (e) {
      e.preventDefault();
      store(TOKEN_KEY, document.getElementById('t').value.trim());
      api('me').then(function () { location.hash = '#/'; boot(); }, function (err) { toast(err.message); });
    };
  }

  /* -- assistant (home) -- */

  var run = { active: false, skipped: {} };

  function txCard(tx) {
    return '<div class="card"><div class="row between"><span class="pill ' + tx.status + '">' +
      ({ pending: 'بی‌جواب', confirmed: 'ثبت شده', ignored: 'نادیده' }[tx.status]) + '</span>' +
      '<span class="muted">' + when(tx) + '</span></div>' +
      '<div class="amount ' + tx.direction + '">' + (tx.direction === 'in' ? 'واریز ' : 'برداشت ') + toman(tx.amount) + '</div>' +
      (tx.balance != null ? '<div class="muted">مانده بعد از آن: ' + toman(tx.balance) + '</div>' : '') +
      (tx.sms_text ? '<details><summary class="muted">متن پیامک</summary><div class="sms">' + esc(tx.sms_text) + '</div></details>' : '') +
      '</div>';
  }

  function formHtml(tx) {
    var cats = state.cats.filter(function (c) { return c.direction === 'both' || c.direction === tx.direction; });
    return '<form id="txf" class="card">' +
      '<label for="desc">بابت چه بود؟</label><textarea id="desc" required placeholder="مثلا: حواله به علی رضایی بابت خرید بذر">' + esc(tx.description) + '</textarea>' +
      '<label for="party">طرف حساب</label><input id="party" list="partyList" value="' + esc(tx.party) + '" autocomplete="off">' +
      '<datalist id="partyList">' + state.parties.map(function (p) { return '<option value="' + esc(p.name) + '">'; }).join('') + '</datalist>' +
      '<label for="cat">دسته</label><select id="cat"><option value="">— انتخاب —</option>' +
      cats.map(function (c) { return catOption(c, tx.category_id); }).join('') +
      '</select><div class="muted" id="catHint">' + kindHint(catKind(tx.category_id)) + '</div>' +
      '<label for="note">یادداشت (اختیاری)</label><input id="note" value="' + esc(tx.note) + '">' +
      '<div class="btns"><button class="btn" type="submit">ثبت در دفتر</button>' +
      (tx.status === 'pending'
        ? '<button class="btn ghost" type="button" id="ignoreBtn">نادیده بگیر</button>'
        : '<button class="btn ghost" type="button" id="reopenBtn">برگردان به بی‌جواب</button>') +
      '</div></form>';
  }

  function fillForm(g) {
    document.getElementById('desc').value = g.description;
    if (g.party) document.getElementById('party').value = g.party;
    if (g.category_id) document.getElementById('cat').value = String(g.category_id);
    document.getElementById('catHint').textContent = kindHint(catKind(g.category_id));
  }

  function formValues(id) {
    return {
      id: id,
      description: document.getElementById('desc').value.trim(),
      party: document.getElementById('party').value.trim(),
      category_id: document.getElementById('cat').value,
      note: document.getElementById('note').value.trim()
    };
  }

  function bubble(who, text) {
    var box = document.getElementById('chat');
    if (!box) return;
    var el = document.createElement('div');
    el.className = 'bubble ' + who;
    el.textContent = text;
    box.appendChild(el);
    el.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
  }

  function micState(live, label) {
    var b = document.getElementById('micBtn');
    if (!b) return;
    b.classList.toggle('live', live);
    b.innerHTML = live ? 'دارم گوش می‌دهم…<small>برای تمام کردن بزن</small>' : (label || '🎙 بپرس<small>بابت هر تراکنش را با صدا جواب بده</small>');
  }

  function hear(maxMs) {
    micState(true);
    return Voice.listen(maxMs).then(function (t) { micState(false, '⏸ دستیار روشن است'); return t || ''; },
      function (err) { micState(false); throw err; });
  }

  function viewAsk(id) {
    var p = id ? api('transaction', { query: '&id=' + id }).then(function (d) { return d.item; })
      : api('pending').then(function (d) {
        setBadge(d.items.length);
        return d.items.filter(function (t) { return !run.skipped[t.id]; })[0] || d.items[0] || null;
      });
    return p.then(function (tx) {
      otpBadge();
      setTimeout(permNudge, 0);   // after this screen has been drawn
      if (!tx) {
        app.innerHTML = '<h1>دستیار بانک</h1><div class="card"><p>🎉 هیچ تراکنش بی‌جوابی نمانده.</p>' +
          '<p class="muted">هر پیامک واریز/برداشت که برسد، اینجا ظاهر می‌شود.</p></div>' + voiceInfo();
        return;
      }
      app.innerHTML = '<h1>بابت چی بود؟</h1>' + txCard(tx) +
        (Voice.canListen() && tx.status === 'pending'
          ? '<button class="btn mic" id="micBtn" type="button"></button>'
          : (tx.status === 'pending' ? '<p class="muted">تشخیص گفتار فارسی روی این مرورگر در دسترس نیست؛ روی کادر زیر بزن و از 🎙 کیبورد گوشی استفاده کن.</p>' : '')) +
        '<div id="chat"></div>' + formHtml(tx);
      micState(false);
      var mic = document.getElementById('micBtn');
      if (mic) mic.onclick = function () {
        Voice.unlock();
        if (Voice.stop) { Voice.stop(); return; }
        if (!run.active) startAssistant(tx);
      };
      bindForm(tx);
    });
  }

  function otpBadge() {
    api('otp_count').then(function (d) {
      var el = document.getElementById('otpLink');
      if (!el) {
        el = document.createElement('a');
        el.id = 'otpLink';
        el.className = 'btn ghost block';
        el.href = '#/otp';
        el.style.marginBottom = '12px';
        app.insertBefore(el, app.children[1] || null);
      }
      el.textContent = d.count ? '🔐 ' + faDigits(d.count) + ' رمز یکبار مصرف رسیده — ببین' : '🔐 رمز یکبار مصرف';
      el.classList.toggle('alert', !!d.count);
    }).catch(function () {});
  }

  function bindForm(tx) {
    var catSel = document.getElementById('cat');
    catSel.onchange = function () { document.getElementById('catHint').textContent = kindHint(catKind(catSel.value)); };
    document.getElementById('txf').onsubmit = function (e) {
      e.preventDefault();
      run.active = false;
      save(tx.id).then(function () { next(tx); });
    };
    var ig = document.getElementById('ignoreBtn');
    if (ig) ig.onclick = function () {
      run.active = false;
      api('ignore', { body: { id: tx.id } }).then(function () { toast('نادیده گرفته شد'); next(tx); }, function (e) { toast(e.message); });
    };
    var re = document.getElementById('reopenBtn');
    if (re) re.onclick = function () {
      api('reopen', { body: { id: tx.id } }).then(function () { location.hash = '#/ask/' + tx.id; viewAsk(tx.id); });
    };
  }

  function save(id) {
    var v = formValues(id);
    if (!v.description) { toast('بنویس یا بگو بابت چه بود'); return Promise.reject(new Error('empty')); }
    return api('confirm', { body: v }).then(function (d) {
      toast('ثبت شد' + (d.synced ? ' و به حسابداری رفت' : ''));
      if (v.party && !state.parties.some(function (p) { return p.name === v.party; })) {
        state.parties.push({ name: v.party, last_category_id: v.category_id });
      }
    }, function (e) { toast(e.message); throw e; });
  }

  function next(tx) {
    if (location.hash !== '#/' && location.hash !== '') location.hash = '#/';
    else viewAsk();
  }

  /** The voice loop: ask, listen, understand, confirm, save, next. */
  function startAssistant(first) {
    run.active = true;
    var tx = first;
    var loop = function () {
      if (!run.active || !tx) { run.active = false; return; }
      return askOne(tx).then(function (outcome) {
        if (outcome === 'skip') run.skipped[tx.id] = true;
        if (outcome === 'stop' || outcome === 'manual') { run.active = false; micState(false); return; }
        return api('pending').then(function (d) {
          setBadge(d.items.length);
          var nx = d.items.filter(function (t) { return !run.skipped[t.id]; })[0];
          if (!nx) {
            run.active = false;
            return Voice.speak('همه‌ی تراکنش‌ها جواب گرفتند.').then(function () { location.hash = '#/'; viewAsk(); });
          }
          if (location.hash !== '#/') history.replaceState(null, '', '#/');
          app.innerHTML = '<h1>بابت چی بود؟</h1>' + txCard(nx) + '<button class="btn mic" id="micBtn" type="button"></button><div id="chat"></div>' + formHtml(nx);
          micState(false, '⏸ دستیار روشن است');
          document.getElementById('micBtn').onclick = function () { if (Voice.stop) Voice.stop(); else { run.active = false; micState(false); } };
          bindForm(nx);
          tx = nx;
          return loop();
        });
      }).catch(function (err) {
        run.active = false;
        micState(false);
        if (err.message === 'no-recognition' || /language|not-allowed/.test(err.message)) {
          bubble('bot', 'این گوشی اجازه/امکان تشخیص گفتار فارسی نداد. در کادر «بابت چه بود؟» بنویس یا از میکروفون کیبورد استفاده کن.');
        } else if (err.message !== 'empty') {
          bubble('bot', 'خطا: ' + err.message);
        }
      });
    };
    return loop();
  }

  function askOne(tx) {
    var q = (tx.direction === 'in' ? 'یک واریزِ ' : 'یک برداشتِ ') + spokenToman(tx.amount) +
      (tx.bank_time ? '، ساعت ' + tx.bank_time : '') + '. بابت چی بود؟';
    bubble('bot', q);
    return Voice.speak(q).then(function () { return hear(9000); }).then(function (ans) {
      if (!ans) {
        bubble('bot', 'چیزی نشنیدم. دوباره 🎙 را بزن یا بنویس و «ثبت» را بزن.');
        return 'manual';
      }
      bubble('me', ans);
      var a = norm(ans);
      if (/^(بعدی|رد کن|ردش کن|بعدا|بگذر)/.test(a)) return 'skip';
      if (/^(تمام|بسه|توقف|کافیه|خداحافظ)/.test(a)) return 'stop';
      if (/(نادیده|حساب نکن|ثبت نکن|تکراری)/.test(a)) {
        return api('ignore', { body: { id: tx.id } }).then(function () { return Voice.speak('نادیده گرفتم.'); }).then(function () { return 'ignored'; });
      }
      return api('interpret', { body: { text: ans, direction: tx.direction } }).then(function (d) {
      var g = d.guess;
      fillForm(g);
      var summary = (g.party ? (tx.direction === 'in' ? 'از ' : 'به ') + g.party + '، ' : '') +
        (g.category_id ? 'دسته‌ی ' + catName(g.category_id) + '، ' : '') + 'ثبت کنم؟';
      bubble('bot', summary);
      return Voice.speak(summary).then(function () { return hear(4000); }).then(function (yn) {
        if (yn) bubble('me', yn);
        if (isYes(yn)) {
          return save(tx.id).then(function () { return Voice.speak('ثبت شد.'); }).then(function () { return 'saved'; });
        }
        bubble('bot', 'باشه؛ فرم پایین را درست کن و «ثبت در دفتر» را بزن.');
        return 'manual';
      });
      });
    });
  }

  function voiceInfo() {
    var lines = [];
    lines.push(Voice.faVoice ? '🔊 صدای فارسی: دارد (' + esc(Voice.faVoice.name) + ')' : '🔊 صدای فارسی روی این گوشی نیست؛ سؤال‌ها با یک «دینگ» و متن نشان داده می‌شوند.');
    lines.push(Voice.SR && !Voice.srBroken ? '🎙 تشخیص گفتار مرورگر: دارد' : (Voice.canRecord() ? '🎙 تشخیص گفتار: از طریق سرور' : '🎙 تشخیص گفتار: ندارد (از میکروفون کیبورد استفاده کن)'));
    lines.push(state.me && state.me.bale ? '💬 ربات بله: فعال — می‌توانی همان‌جا هم جواب بدهی' : '💬 ربات بله: تنظیم نشده');
    var dev = state.me && state.me.device;
    if (dev) {
      var mins = Math.round((Date.now() - new Date(dev.last_seen.replace(' ', 'T')).getTime()) / 60000);
      var sig = dev.signal == null || dev.signal === 99 ? 'نامعلوم' : dev.signal >= 15 ? 'خوب' : dev.signal >= 8 ? 'متوسط' : 'ضعیف';
      lines.push((mins > 30 ? '🔴' : '🟢') + ' دستگاه پیامک: آخرین خبر ' + (mins < 1 ? 'همین الان' : faDigits(mins) + ' دقیقه پیش') +
        ' · آنتن ' + sig + (dev.sms_on_sim ? ' · ' + faDigits(dev.sms_on_sim) + ' پیامک در صف' : ''));
    } else {
      lines.push('📟 دستگاه پیامک: هنوز خبری نداده');
    }
    return '<div class="card muted">' + lines.join('<br>') + '</div>';
  }

  /* -- ledger -- */

  function bars(list, cls) {
    if (!list.length) return '<p class="muted">—</p>';
    var max = list[0].total || 1;
    return list.map(function (x) {
      return '<div class="bar"><div class="row between"><span>' + esc(x.category) + ' <span class="muted">(' + faDigits(x.n) + ')</span></span><b class="num">' + toman(x.total) + '</b></div>' +
        '<div class="track"><i class="' + cls + '" style="width:' + Math.max(2, Math.round(x.total / max * 100)) + '%"></i></div></div>';
    }).join('');
  }

  function walletsHtml(ws) {
    return '<div class="card"><b>موجودی حساب‌ها</b>' + ws.map(function (w) {
      var diff = w.bank_balance != null && w.bank_balance !== w.balance;
      return '<div class="row between" style="margin-top:6px"><span>' + (w.kind === 'cash' ? '💵 ' : '🏦 ') + esc(w.name) + '</span><b class="num">' + (w.balance < 0 ? '−' : '') + toman(w.balance) + '</b></div>' +
        (w.bank_balance != null ? '<div class="muted" style="font-size:13px">مانده طبق آخرین پیامک بانک: ' + toman(w.bank_balance) +
          (diff ? ' <span class="warn">(با حساب دفتر فرق دارد؛ «مانده اول» حساب را در تنظیمات بگذار)</span>' : ' ✅') + '</div>' : '');
    }).join('') + '</div>';
  }

  function viewHistory() {
    var days = +(store('ba_days') || 30);
    var from = isoDay(-days), to = isoDay(0), q = '&from=' + from + '&to=' + to;
    app.innerHTML = '<h1>دفتر</h1><p class="muted">در حال بارگذاری…</p>';
    return Promise.all([api('list', { query: q }), api('report', { query: q })]).then(function (r) {
      var d = r[0], rep = r[1], pl = rep.pl;
      var rows = d.items.map(function (t) { return { kind: 'tx', sort: t.occurred_at, t: t }; })
        .concat(d.bills.map(function (b) { return { kind: 'bill', sort: b.date + ' 00:00:00', b: b }; }))
        .sort(function (x, y) { return x.sort < y.sort ? 1 : x.sort > y.sort ? -1 : 0; });
      app.innerHTML = '<h1>دفتر</h1>' +
        '<div class="row"><select id="days" class="grow">' + [7, 30, 90, 365].map(function (n) {
          return '<option value="' + n + '"' + (n === days ? ' selected' : '') + '>' + faDigits(n) + ' روز اخیر</option>';
        }).join('') + '</select><a class="btn" href="#/manual">+ ثبت</a></div>' +
        '<div class="stat" style="margin-top:12px"><div class="card">درآمد<b class="in">' + toman(pl.total_income) + '</b></div>' +
        '<div class="card">هزینه<b class="out">' + toman(pl.total_expense) + '</b></div></div>' +
        '<div class="card"><div class="row between"><b>' + (pl.profit >= 0 ? 'سود' : 'زیان') + ' این بازه</b><b class="' + (pl.profit >= 0 ? 'in' : 'out') + '">' + toman(pl.profit) + '</b></div>' +
        '<div class="muted" style="font-size:13px">حواله/تسویه با اشخاص و انتقال بین حساب‌های خودت، درآمد یا هزینه حساب نمی‌شوند.</div></div>' +
        walletsHtml(rep.wallets) +
        '<a class="card row between" href="#/people" style="color:inherit;text-decoration:none"><span>👥 طلب تو از دیگران<br><b class="in">' + toman(rep.people.receivable) + '</b></span>' +
        '<span>بدهی تو به دیگران<br><b class="out">' + toman(rep.people.payable) + '</b></span></a>' +
        '<h2>هزینه‌ها بر اساس دسته</h2><div class="card">' + bars(pl.expense, 'out') + '</div>' +
        '<h2>درآمدها بر اساس دسته</h2><div class="card">' + bars(pl.income, 'in') + '</div>' +
        '<h2>ریز گردش</h2><div class="card list">' + (rows.length ? rows.map(function (r) {
          if (r.kind === 'bill') {
            var b = r.b;
            return '<a class="item" href="#/person/' + encodeURIComponent(b.party) + '"><div class="grow"><div>🧾 ' + (b.type === 'sale' ? 'فروش نسیه' : 'خرید نسیه') + ' · ' + esc(b.party) + '</div>' +
              '<div class="muted">' + jalali(b.date) + (b.description ? ' · ' + esc(b.description) : '') + '</div></div><b class="muted num">' + toman(b.amount) + '</b></a>';
          }
          var t = r.t;
          return '<a class="item" href="#/ask/' + t.id + '"><div class="grow"><div>' + (KIND_ICON[t.category_kind] || '') + esc(t.description || 'بی‌جواب') +
            (t.party ? ' <span class="muted">· ' + esc(t.party) + '</span>' : '') + '</div>' +
            '<div class="muted">' + when(t) + ' · ' + esc(t.wallet_name || '') + (t.category_name ? ' · ' + esc(t.category_name) : '') +
            (t.status !== 'confirmed' ? ' · <span class="pill ' + t.status + '">' + (t.status === 'pending' ? 'بی‌جواب' : 'نادیده') + '</span>' : '') +
            '</div></div><b class="' + t.direction + ' num">' + (t.direction === 'in' ? '+' : '−') + toman(t.amount) + '</b></a>';
        }).join('') : '<p class="muted">در این بازه چیزی ثبت نشده.</p>') + '</div>' +
        '<a class="btn ghost block" href="' + API + '?r=export&token=' + encodeURIComponent(token()) + q + '">⬇ خروجی اکسل (CSV)</a>';
      document.getElementById('days').onchange = function () { store('ba_days', this.value); viewHistory(); };
    });
  }

  /* -- new entry: receive / pay / credit sale / credit purchase / transfer -- */

  var ENTRY_TYPES = [
    ['in', 'دریافت'], ['out', 'پرداخت'], ['sale', 'فروش نسیه'], ['purchase', 'خرید نسیه'], ['transfer', 'انتقال']
  ];

  function viewManual(prefill) {
    prefill = prefill || {};
    var type = prefill.type || (prefill.direction === 'in' ? 'in' : 'out');
    var walletOpts = function (sel) {
      return state.wallets.map(function (w) { return '<option value="' + w.id + '"' + (String(w.id) === String(sel) ? ' selected' : '') + '>' + esc(w.name) + '</option>'; }).join('');
    };
    var cash = (state.wallets.filter(function (w) { return w.kind === 'cash'; })[0] || state.wallets[0] || {}).id;
    var bank = (state.wallets.filter(function (w) { return +w.is_sms; })[0] || state.wallets[0] || {}).id;

    app.innerHTML = '<h1>ثبت</h1>' +
      '<div class="seg" id="seg">' + ENTRY_TYPES.map(function (t) { return '<button type="button" data-t="' + t[0] + '">' + t[1] + '</button>'; }).join('') + '</div>' +
      '<p class="muted" id="typeHelp"></p>' +
      '<form id="mf" class="card">' +
      '<div data-for="in out"><label for="mw">از کدام حساب؟</label><select id="mw">' + walletOpts(prefill.date ? bank : cash) + '</select></div>' +
      '<div data-for="transfer"><div class="row"><div class="grow"><label for="tw1">از</label><select id="tw1">' + walletOpts(bank) + '</select></div>' +
      '<div class="grow"><label for="tw2">به</label><select id="tw2">' + walletOpts(cash) + '</select></div></div></div>' +
      '<div data-for="in out sale purchase"><label for="mparty">طرف حساب</label><input id="mparty" list="pl2" autocomplete="off" value="' + esc(prefill.party || '') + '">' + partyList('pl2') + '</div>' +
      '<label for="amt">مبلغ (تومان)</label><input id="amt" inputmode="numeric" required value="' + (prefill.amount ? Math.round(prefill.amount / 10) : '') + '">' +
      '<div class="muted" id="amtWords"></div>' +
      '<label for="dt">تاریخ</label><input id="dt" type="date" value="' + (prefill.date || isoDay(0)) + '"><div class="muted" id="dtj"></div>' +
      '<div data-for="in out sale purchase"><label for="mcat">دسته</label><select id="mcat"></select><div class="muted" id="mcatHint"></div></div>' +
      '<label for="mdesc">شرح</label><input id="mdesc" value="' + esc(prefill.desc || '') + '">' +
      '<div data-for="sale purchase"><label for="due">سررسید (اختیاری)</label><input id="due" type="date"></div>' +
      '<div class="btns"><button class="btn">ثبت</button></div></form>';

    var help = {
      in: 'پول به یکی از حساب‌هایت آمد (نقد یا بانکی که پیامکش نیامده).',
      out: 'پول از یکی از حساب‌هایت رفت.',
      sale: 'جنس/خدمت دادی و پولش را بعداً می‌گیری؛ طرف به تو بدهکار می‌شود. وقتی پول را گرفتی، «دریافت» با دسته 👤 ثبت کن.',
      purchase: 'جنس/خدمت گرفتی و پولش را بعداً می‌دهی؛ تو به طرف بدهکار می‌شوی.',
      transfer: 'مثلاً برداشت از خودپرداز (بانک ← صندوق) یا واریز نقدی به بانک.'
    };
    var setType = function (t) {
      type = t;
      Array.prototype.forEach.call(document.querySelectorAll('#seg button'), function (b) { b.classList.toggle('on', b.getAttribute('data-t') === t); });
      Array.prototype.forEach.call(document.querySelectorAll('[data-for]'), function (el) { el.hidden = el.getAttribute('data-for').split(' ').indexOf(t) === -1; });
      document.getElementById('typeHelp').textContent = help[t];
      var dir = t === 'in' || t === 'sale' ? 'in' : 'out';
      var cats = state.cats.filter(function (c) {
        if (c.direction !== 'both' && c.direction !== dir) return false;
        if (t === 'sale' || t === 'purchase') return c.kind === 'pl';
        return c.kind !== 'transfer';
      });
      var pick = prefill.party && (t === 'in' || t === 'out') ? (cats.filter(function (c) { return c.kind === 'party'; })[0] || {}).id : '';
      document.getElementById('mcat').innerHTML = '<option value="">—</option>' + cats.map(function (c) { return catOption(c, pick); }).join('');
      document.getElementById('mcatHint').textContent = kindHint(catKind(pick));
    };
    document.getElementById('mcat').onchange = function () { document.getElementById('mcatHint').textContent = kindHint(catKind(this.value)); };
    Array.prototype.forEach.call(document.querySelectorAll('#seg button'), function (b) { b.onclick = function () { setType(b.getAttribute('data-t')); }; });
    setType(type);

    var dt = document.getElementById('dt');
    var showJ = function () { document.getElementById('dtj').textContent = dt.value ? jalali(dt.value) : ''; };
    dt.oninput = showJ; showJ();
    var amt = document.getElementById('amt');
    var showAmt = function () {
      var v = Number(norm(amt.value).replace(/\D/g, ''));
      document.getElementById('amtWords').textContent = v ? faDigits(spokenToman(v * 10)) : '';
    };
    amt.oninput = showAmt; showAmt();

    document.getElementById('mf').onsubmit = function (e) {
      e.preventDefault();
      var v = function (id) { return document.getElementById(id).value; };
      var amountT = norm(v('amt')).replace(/\D/g, '');
      var req;
      if (type === 'sale' || type === 'purchase') {
        req = api('bill_save', { body: { type: type, party: v('mparty'), amount_toman: amountT, date: v('dt'), category_id: v('mcat'), description: v('mdesc'), due_date: v('due') } });
      } else if (type === 'transfer') {
        if (v('tw1') === v('tw2')) return toast('حساب مبدأ و مقصد یکی است');
        var tcat = state.cats.filter(function (c) { return c.kind === 'transfer'; })[0];
        req = api('manual', { body: { direction: 'out', wallet_id: v('tw1'), counter_wallet_id: v('tw2'), amount_toman: amountT, date: v('dt'),
          category_id: tcat ? tcat.id : '', description: v('mdesc') || 'انتقال' } });
      } else {
        req = api('manual', { body: { direction: type, wallet_id: v('mw'), party: v('mparty'), amount_toman: amountT, date: v('dt'), category_id: v('mcat'), description: v('mdesc') } });
      }
      req.then(function () {
        toast('ثبت شد');
        var party = v('mparty').trim();
        loadLookups().then(function () {
          location.hash = party && type !== 'transfer' ? '#/person/' + encodeURIComponent(party) : '#/history';
        });
      }, function (err) { toast(err.message); });
    };
  }

  /* -- people (حساب اشخاص) -- */

  function viewPeople() {
    var filter = store('ba_pf') || 'all';
    return api('people').then(function (d) {
      var render = function () {
        var qv = norm((document.getElementById('pq') || {}).value || '');
        var list = d.items.filter(function (p) {
          if (filter === 'recv' && p.balance <= 0) return false;
          if (filter === 'pay' && p.balance >= 0) return false;
          return !qv || norm(p.name).indexOf(qv) !== -1;
        });
        document.getElementById('plist').innerHTML = list.length ? list.map(function (p) {
          return '<a class="item" href="#/person/' + encodeURIComponent(p.name) + '"><div class="grow"><div>' + esc(p.name) + (p.overdue ? ' ⏰' : '') + '</div>' +
            (p.phone ? '<div class="muted num">' + esc(p.phone) + '</div>' : '') + '</div><div style="text-align:left">' + balanceLabel(p.balance) + '</div></a>';
        }).join('') : '<p class="muted">کسی نیست.</p>';
      };
      app.innerHTML = '<h1>حساب اشخاص</h1>' +
        '<div class="stat"><div class="card">طلب تو از دیگران<b class="in">' + toman(d.totals.receivable) + '</b></div>' +
        '<div class="card">بدهی تو به دیگران<b class="out">' + toman(d.totals.payable) + '</b></div></div>' +
        '<div class="row" style="margin:12px 0"><input id="pq" class="grow" placeholder="جستجوی نام…"><a class="btn" href="#/person-edit/">+ شخص</a></div>' +
        '<div class="seg" id="pf"><button data-f="all">همه</button><button data-f="recv">بدهکاران به من</button><button data-f="pay">طلبکاران از من</button></div>' +
        '<div class="card list" id="plist"></div>';
      Array.prototype.forEach.call(document.querySelectorAll('#pf button'), function (b) {
        b.classList.toggle('on', b.getAttribute('data-f') === filter);
        b.onclick = function () { filter = b.getAttribute('data-f'); store('ba_pf', filter); viewPeople(); };
      });
      document.getElementById('pq').oninput = render;
      render();
    });
  }

  function statementText(st) {
    var lines = ['صورت‌حساب ' + st.person.name + ' — ' + jalali(isoDay(0))];
    if (st.person.opening) lines.push('مانده اول: ' + toman(st.person.opening) + (st.person.opening > 0 ? ' بدهکار' : ' بستانکار'));
    st.rows.filter(function (r) { return r.effect; }).forEach(function (r) {
      lines.push(jalali(r.date) + ' | ' + r.title + (r.description ? ' (' + r.description + ')' : '') + ' | ' + toman(r.amount) + ' | مانده: ' + toman(r.running) + (r.running > 0 ? ' بدهکار' : r.running < 0 ? ' بستانکار' : ''));
    });
    lines.push('مانده نهایی: ' + toman(st.person.balance) + (st.person.balance > 0 ? ' بدهکار' : st.person.balance < 0 ? ' بستانکار' : ' (تسویه)'));
    return lines.join('\n');
  }

  function viewPerson(name) {
    return api('person', { query: '&name=' + encodeURIComponent(name) }).then(function (st) {
      var p = st.person, enc = encodeURIComponent(p.name);
      app.innerHTML = '<div class="row between"><h1>' + esc(p.name) + '</h1><a class="btn plain" href="#/person-edit/' + enc + '">ویرایش</a></div>' +
        (p.phone ? '<p><a class="num" href="tel:' + esc(p.phone) + '">📞 ' + esc(p.phone) + '</a></p>' : '') +
        '<div class="card"><div class="muted">مانده حساب</div><div class="amount">' + balanceLabel(p.balance) + '</div>' +
        '<div class="muted" style="font-size:13px">' + (p.balance > 0 ? 'این مبلغ را باید از او بگیری.' : p.balance < 0 ? 'این مبلغ را باید به او بدهی.' : '') + '</div></div>' +
        '<div class="grid2">' +
        '<a class="btn ghost" href="#/manual/sale/' + enc + '">🧾 فروش نسیه به او</a>' +
        '<a class="btn ghost" href="#/manual/purchase/' + enc + '">🧾 خرید نسیه از او</a>' +
        '<a class="btn ghost" href="#/manual/in/' + enc + '">⬇ دریافت از او</a>' +
        '<a class="btn ghost" href="#/manual/out/' + enc + '">⬆ پرداخت به او</a></div>' +
        '<h2>صورت‌حساب</h2><div class="card list">' +
        (p.opening ? '<div class="item"><div class="grow">مانده اول</div><div class="st-run">' + balanceLabel(p.opening) + '</div></div>' : '') +
        st.rows.map(function (r) {
          var del = r.ref.indexOf('bill:') === 0 ? ' · <a href="#" data-bill="' + r.ref.slice(5) + '">حذف</a>' : '';
          return '<div class="item' + (r.effect ? '' : ' faded') + '"><div class="grow"><div>' + esc(r.title) +
            ' <b class="num ' + (r.effect > 0 ? 'in' : r.effect < 0 ? 'out' : '') + '">' + toman(r.amount) + '</b></div>' +
            '<div class="muted">' + jalali(r.date) + (r.description ? ' · ' + esc(r.description) : '') +
            (r.due_date ? ' · سررسید ' + jalali(r.due_date) : '') + (!r.effect ? ' · نقدی، بدون اثر بر مانده' : '') + del + '</div></div>' +
            (r.effect ? '<div class="st-run"><div class="muted" style="font-size:12px">مانده</div>' + balanceLabel(r.running) + '</div>' : '') + '</div>';
        }).join('') + (st.rows.length ? '' : '<p class="muted">هنوز چیزی ثبت نشده.</p>') + '</div>' +
        '<div class="btns"><button class="btn ghost" id="share">📤 فرستادن صورت‌حساب</button></div>';
      document.getElementById('share').onclick = function () {
        var text = statementText(st);
        if (navigator.share) navigator.share({ text: text }).catch(function () {});
        else if (navigator.clipboard) navigator.clipboard.writeText(text).then(function () { toast('کپی شد'); });
      };
      Array.prototype.forEach.call(app.querySelectorAll('[data-bill]'), function (a) {
        a.onclick = function (e) {
          e.preventDefault();
          if (!confirm('این فاکتور نسیه حذف شود؟')) return;
          api('bill_delete', { body: { id: a.getAttribute('data-bill') } }).then(function () { viewPerson(name); });
        };
      });
    });
  }

  function viewPersonEdit(name) {
    var load = name ? api('person', { query: '&name=' + encodeURIComponent(name) }).then(function (d) { return d.person; }) : Promise.resolve({ name: '', opening: 0 });
    return load.then(function (p) {
      var o = p.opening || 0;
      app.innerHTML = '<h1>' + (name ? 'ویرایش ' + esc(name) : 'شخص جدید') + '</h1><form id="pe" class="card">' +
        '<label for="pn">نام</label><input id="pn" required value="' + esc(p.name) + '">' +
        '<label for="pp">تلفن</label><input id="pp" inputmode="tel" value="' + esc(p.phone || '') + '">' +
        '<label for="po">مانده اول (از قبل از این برنامه، تومان)</label><div class="row"><input id="po" class="grow" inputmode="numeric" value="' + (o ? Math.abs(o) / 10 : '') + '">' +
        '<select id="ps" style="width:auto"><option value="1">او به من بدهکار است</option><option value="-1"' + (o < 0 ? ' selected' : '') + '>من به او بدهکارم</option></select></div>' +
        '<label for="pnote">یادداشت</label><input id="pnote" value="' + esc(p.note || '') + '">' +
        '<div class="btns"><button class="btn">ذخیره</button>' + (name ? '<button type="button" class="btn plain" id="pdel">حذف</button>' : '') + '</div></form>';
      document.getElementById('pe').onsubmit = function (e) {
        e.preventDefault();
        var v = Number(norm(document.getElementById('po').value).replace(/\D/g, '')) * Number(document.getElementById('ps').value);
        var nn = document.getElementById('pn').value.trim();
        api('person_save', { body: { old_name: name || '', name: nn, phone: document.getElementById('pp').value, note: document.getElementById('pnote').value, opening_toman: String(v) } })
          .then(loadLookups).then(function () { toast('ذخیره شد'); location.hash = '#/person/' + encodeURIComponent(nn); }, function (err) { toast(err.message); });
      };
      var del = document.getElementById('pdel');
      if (del) del.onclick = function () {
        if (!confirm('حذف شود؟')) return;
        api('person_delete', { body: { name: name } }).then(loadLookups).then(function () { location.hash = '#/people'; }, function (err) { toast(err.message); });
      };
    });
  }

  /* -- one-time passwords (رمز پویا) -- */

  // The PIN lives only in this variable: never in storage, gone on reload or after 3 idle minutes.
  var otp = { pin: '', timer: null, lockAt: 0 };

  function viewOtp() {
    clearInterval(otp.timer);
    if (!otp.pin || Date.now() > otp.lockAt) {
      otp.pin = '';
      app.innerHTML = '<h1>🔐 رمز یکبار مصرف</h1><div class="card"><p class="muted">رمزهای پویای بانک که روی سیم‌کارت دستگاه می‌آیند، رمزشده و فقط چند دقیقه اینجا نگه داشته می‌شوند.</p>' +
        (state.me && !state.me.otp_ready ? '<p class="warn">اول otp_pin را در config.php سرور بگذار.</p>' : '') +
        '<form id="pinf"><label for="pin">PIN رمزها</label><input id="pin" type="password" inputmode="numeric" autocomplete="off" required>' +
        '<div class="btns"><button class="btn">باز کن</button></div></form></div>';
      document.getElementById('pinf').onsubmit = function (e) {
        e.preventDefault();
        otp.pin = document.getElementById('pin').value;
        otp.lockAt = Date.now() + 180000;
        viewOtp();
      };
      return;
    }
    app.innerHTML = '<h1>🔐 رمز یکبار مصرف</h1><div id="otps"><p class="muted">…</p></div>' +
      '<p class="muted" style="font-size:13px">صفحه را باز بگذار؛ رمز تازه خودش ظاهر می‌شود. بعد از ۳ دقیقه بی‌کاری دوباره PIN می‌خواهد.</p>' +
      '<div class="btns"><button class="btn plain" id="lock">قفل کن</button></div>';
    document.getElementById('lock').onclick = function () { otp.pin = ''; viewOtp(); };
    var poll = function () {
      if (location.hash !== '#/otp') { clearInterval(otp.timer); return; }
      if (Date.now() > otp.lockAt) { otp.pin = ''; viewOtp(); return; }
      fetch(API + '?r=otp', { headers: { 'X-App-Token': token(), 'X-OTP-PIN': otp.pin }, cache: 'no-store' })
        .then(function (res) { return res.json(); })
        .then(function (d) {
          if (!d.ok) { otp.pin = ''; clearInterval(otp.timer); toast(d.error); viewOtp(); return; }
          var box = document.getElementById('otps');
          if (!box) return;
          box.innerHTML = d.items.length ? d.items.map(function (o) {
            return '<div class="card otp"><div class="code num">' + esc(o.code) + '</div>' +
              (o.amount ? '<div>مبلغ: <b>' + toman(o.amount) + '</b></div>' : '') +
              (o.merchant ? '<div>پذیرنده: <b>' + esc(o.merchant) + '</b></div>' : '') +
              '<div class="muted">⚠️ اگر خریدی با این مبلغ و پذیرنده انجام نمی‌دهی، رمز را به کسی نده.</div>' +
              '<div class="track"><i class="in" style="width:' + Math.min(100, Math.round(o.seconds_left / 1.8)) + '%"></i></div>' +
              '<div class="muted">' + faDigits(o.seconds_left) + ' ثانیه اعتبار</div>' +
              '<div class="btns"><button class="btn" data-copy="' + esc(o.code) + '">کپی</button><button class="btn ghost" data-clear="' + o.id + '">پاک کن</button></div></div>';
          }).join('') : '<div class="card"><p>⏳ منتظر رمز… خرید را شروع کن؛ به‌محض رسیدن پیامک اینجا ظاهر می‌شود.</p></div>';
          Array.prototype.forEach.call(box.querySelectorAll('[data-copy]'), function (b) {
            b.onclick = function () {
              otp.lockAt = Date.now() + 180000;
              if (navigator.clipboard) navigator.clipboard.writeText(b.getAttribute('data-copy')).then(function () { toast('کپی شد'); });
            };
          });
          Array.prototype.forEach.call(box.querySelectorAll('[data-clear]'), function (b) {
            b.onclick = function () {
              fetch(API + '?r=otp_clear', { method: 'POST', headers: { 'X-App-Token': token(), 'X-OTP-PIN': otp.pin, 'Content-Type': 'application/json' },
                body: JSON.stringify({ id: b.getAttribute('data-clear') }) }).then(poll);
            };
          });
        }).catch(function () {});
    };
    poll();
    otp.timer = setInterval(poll, 2000);
  }

  /* -- reconciliation -- */

  var lastReport = null;

  function reportHtml(kind, r) {
    if (kind === 'balance') {
      return '<div class="card"><b>' + (r.ok ? '✅ زنجیره‌ی مانده‌ها کامل است' : '⚠️ ' + faDigits(r.gaps.length) + ' جای خالی پیدا شد') + '</b>' +
        '<p class="muted">' + jalali(r.from) + ' تا ' + jalali(r.to) + ' · ' + faDigits(r.checked) + ' پیامک' +
        (r.last_balance != null ? ' · آخرین مانده ' + toman(r.last_balance) : '') + '</p>' +
        r.gaps.map(function (g) {
          return '<div class="sms">بین ' + faDigits(g.after_date) + ' و ' + faDigits(g.before_date) + ': <b class="' + (g.missing > 0 ? 'in' : 'out') + '">' +
            toman(g.missing) + (g.missing > 0 ? ' واریزِ' : ' برداشتِ') + '</b> بدون پیامک (یا کارمزد). ' +
            '<a href="#/manual" data-gap=\'' + esc(JSON.stringify({ direction: g.missing > 0 ? 'in' : 'out', amount: Math.abs(g.missing) })) + '\'>ثبت دستی</a></div>';
        }).join('') +
        (r.pending ? '<p class="warn">🕓 ' + faDigits(r.pending) + ' تراکنش در این بازه هنوز بی‌جواب است.</p>' : '') + '</div>';
    }
    var rows = function (list, isBank) {
      return '<table>' + list.map(function (x) {
        return '<tr><td>' + (isBank ? jalali(x.date) : when(x)) + '</td><td class="' + x.direction + '">' + (x.direction === 'in' ? '+' : '−') + toman(x.amount) + '</td><td class="muted">' +
          esc(isBank ? x.desc : (x.description || 'بی‌جواب')) + '</td>' +
          (isBank ? '<td><a href="#/manual" data-gap=\'' + esc(JSON.stringify({ direction: x.direction, amount: x.amount, date: x.date, desc: x.desc })) + '\'>ثبت</a></td>' : '') + '</tr>';
      }).join('') + '</table>';
    };
    return '<div class="card"><b>' + (r.ok ? '✅ دفتر با صورت‌حساب بانک یکی است' : '⚠️ مغایرت دارد') + '</b>' +
      '<p class="muted">' + jalali(r.from) + ' تا ' + jalali(r.to) + ' · ' + faDigits(r.bank_rows) + ' ردیف بانک · ' + faDigits(r.matched) + ' جور شد</p>' +
      (r.only_in_bank.length ? '<h2>در بانک هست، در دفتر نیست</h2>' + rows(r.only_in_bank, true) : '') +
      (r.only_in_ledger.length ? '<h2>در دفتر هست، در بانک نیست</h2>' + rows(r.only_in_ledger, false) : '') +
      (r.matched_but_unconfirmed.length ? '<h2>جور شده ولی بی‌جواب</h2>' + rows(r.matched_but_unconfirmed, false) : '') +
      '</div>';
  }

  function bindGapLinks() {
    Array.prototype.forEach.call(app.querySelectorAll('[data-gap]'), function (a) {
      a.onclick = function (e) {
        e.preventDefault();
        history.pushState(null, '', '#/manual');
        viewManual(JSON.parse(a.getAttribute('data-gap')));
      };
    });
  }

  function viewReconcile() {
    app.innerHTML = '<h1>صورت مغایرت</h1>' +
      '<div class="card"><b>۱. بررسی زنجیره‌ی مانده‌ها</b><p class="muted">هر پیامک مانده‌ی بعد از تراکنش را دارد؛ اگر «مانده قبلی ± مبلغ» با «مانده جدید» نخواند، یعنی پیامکی نرسیده یا کارمزدی کسر شده. این کار هر هفته خودکار هم انجام می‌شود.</p>' +
      '<div class="row"><input type="date" id="bf" value="' + isoDay(-7) + '"><input type="date" id="bt" value="' + isoDay(0) + '"></div>' +
      '<div class="btns"><button class="btn" id="bc">بررسی کن</button></div></div>' +
      '<div class="card"><b>۲. مقایسه با صورت‌حساب بانک</b><p class="muted">از اینترنت‌بانک/همراه‌بانک ملت صورت‌حساب را «اکسل» بگیر، در اکسل با Save As ‏CSV ذخیره کن و اینجا انتخاب کن. ستون‌ها از روی عنوان (تاریخ، برداشت، واریز، مانده) پیدا می‌شوند.</p>' +
      '<input type="file" id="csv" accept=".csv,text/csv,text/plain"><div class="btns"><button class="btn" id="rc">مقایسه کن</button></div></div>' +
      '<div id="rep">' + (lastReport ? reportHtml(lastReport.kind, lastReport.report) : '') + '</div>' +
      '<h2>گزارش‌های قبلی</h2><div class="card list" id="old"><p class="muted">…</p></div>';
    bindGapLinks();
    var show = function (kind, report) {
      lastReport = { kind: kind, report: report };
      document.getElementById('rep').innerHTML = reportHtml(kind, report);
      bindGapLinks();
      document.getElementById('rep').scrollIntoView({ behavior: 'smooth' });
    };
    document.getElementById('bc').onclick = function () {
      api('balance_check', { query: '&from=' + document.getElementById('bf').value + '&to=' + document.getElementById('bt').value })
        .then(function (d) { show('balance', d.report); }, function (e) { toast(e.message); });
    };
    document.getElementById('rc').onclick = function () {
      var f = document.getElementById('csv').files[0];
      if (!f) return toast('اول فایل را انتخاب کن');
      var fd = new FormData();
      fd.append('file', f);
      api('reconcile', { form: fd }).then(function (d) { show('statement', d.report); }, function (e) { toast(e.message); });
    };
    api('reconciliations').then(function (d) {
      var old = document.getElementById('old');
      old.innerHTML = d.items.length ? d.items.map(function (r, i) {
        return '<a class="item" href="#" data-i="' + i + '"><div class="grow">' + (r.kind === 'balance' ? 'زنجیره مانده' : 'صورت‌حساب بانک') +
          '<div class="muted">' + jalali(r.date_from) + ' تا ' + jalali(r.date_to) + '</div></div>' + (+r.ok ? '✅' : '⚠️') + '</a>';
      }).join('') : '<p class="muted">هنوز گزارشی نیست.</p>';
      Array.prototype.forEach.call(old.querySelectorAll('[data-i]'), function (a) {
        a.onclick = function (e) { e.preventDefault(); var r = d.items[+a.getAttribute('data-i')]; show(r.kind, r.report); };
      });
    });
  }

  /* -- settings -- */

  function viewSettings() {
    var dirLabel = { in: 'واریز', out: 'برداشت', both: 'هر دو' };
    var kindLabel = { pl: 'درآمد/هزینه', party: '👤 حساب شخص', transfer: '🔁 انتقال' };
    var kindSelect = function (k) {
      return '<select name="kind" style="width:auto">' + ['pl', 'party', 'transfer'].map(function (x) { return '<option value="' + x + '"' + (k === x ? ' selected' : '') + '>' + kindLabel[x] + '</option>'; }).join('') + '</select>';
    };
    app.innerHTML = '<h1>تنظیمات</h1>' +
      '<h2 id="perms">دسترسی‌ها</h2><div class="card" id="permBox"></div>' +
      '<h2>ربات بله</h2><div class="card" id="bale"><p class="muted">…</p></div>' +
      voiceInfo() +
      '<div class="btns"><button class="btn ghost" id="testVoice">آزمایش صدا و میکروفون</button></div>' +
      '<h2>فرستنده‌های پیامک</h2><p class="muted">شناسه‌ای که پیامک بانک با آن می‌آید (مثلاً یک نام انگلیسی) را تیک بزن؛ بقیه پیامک‌ها دیگر ذخیره نمی‌شوند. اگر هیچ‌کدام تیک نخورد، همه بررسی می‌شوند.</p>' +
      '<form class="card" id="sf"><div id="slist" class="muted">…</div><div class="btns"><button class="btn ghost">ذخیره</button></div></form>' +
      '<h2>حساب‌ها (بانک و صندوق)</h2><p class="muted">«مانده اول» را یک بار بگذار تا موجودی درست حساب شود. پیامک‌های بانک به حسابی می‌روند که 📩 دارد.</p>' +
      state.wallets.map(function (w) {
        return '<form class="card wf" data-id="' + w.id + '"><div class="row"><input name="name" class="grow" value="' + esc(w.name) + '">' + (+w.is_sms ? ' 📩' : '') +
          '<select name="kind" style="width:auto"><option value="bank">بانک</option><option value="cash"' + (w.kind === 'cash' ? ' selected' : '') + '>نقد</option></select></div>' +
          '<label>مانده اول (تومان)</label><input name="opening" inputmode="numeric" value="' + (w.opening ? w.opening / 10 : '') + '">' +
          '<div class="btns"><button class="btn ghost">ذخیره</button></div></form>';
      }).join('') +
      '<form class="card wf" data-id=""><b>حساب جدید</b><div class="row" style="margin-top:8px"><input name="name" class="grow" placeholder="مثلاً بانک ملی" required>' +
      '<select name="kind" style="width:auto"><option value="bank">بانک</option><option value="cash">نقد</option></select></div>' +
      '<label>مانده اول (تومان)</label><input name="opening" inputmode="numeric"><div class="btns"><button class="btn">افزودن</button></div></form>' +
      '<h2>دسته‌ها</h2><p class="muted">کلمه‌های کلیدی را با ویرگول جدا کن؛ اگر در جوابت بیایند، دسته خودکار انتخاب می‌شود.</p>' +
      state.cats.map(function (c) {
        return '<form class="card catf" data-id="' + c.id + '"><div class="row"><input name="name" class="grow" value="' + esc(c.name) + '">' +
          '<select name="direction" style="width:auto">' + ['out', 'in', 'both'].map(function (d) { return '<option value="' + d + '"' + (c.direction === d ? ' selected' : '') + '>' + dirLabel[d] + '</option>'; }).join('') + '</select>' + kindSelect(c.kind) + '</div>' +
          '<input name="keywords" style="margin-top:8px" value="' + esc(c.keywords) + '" placeholder="کلمات کلیدی">' +
          '<div class="btns"><button class="btn ghost">ذخیره</button><button class="btn plain" type="button" data-del>حذف</button></div></form>';
      }).join('') +
      '<form class="card catf" data-id=""><b>دسته‌ی جدید</b><div class="row" style="margin-top:8px"><input name="name" class="grow" placeholder="نام" required>' +
      '<select name="direction" style="width:auto"><option value="out">برداشت</option><option value="in">واریز</option><option value="both">هر دو</option></select>' + kindSelect('pl') + '</div>' +
      '<input name="keywords" style="margin-top:8px" placeholder="کلمات کلیدی"><div class="btns"><button class="btn">افزودن</button></div></form>' +
      '<div class="btns"><button class="btn plain" id="logout">خروج از این گوشی</button></div>';

    Array.prototype.forEach.call(app.querySelectorAll('.catf'), function (f) {
      f.onsubmit = function (e) {
        e.preventDefault();
        api('category_save', { body: { id: f.getAttribute('data-id'), name: f.name.value, direction: f.direction.value, kind: f.kind.value, keywords: f.keywords.value } })
          .then(loadLookups).then(function () { toast('ذخیره شد'); viewSettings(); }, function (err) { toast(err.message); });
      };
      var del = f.querySelector('[data-del]');
      if (del) del.onclick = function () {
        if (!confirm('این دسته حذف شود؟ تراکنش‌های آن بدون دسته می‌مانند.')) return;
        api('category_delete', { body: { id: f.getAttribute('data-id') } }).then(loadLookups).then(viewSettings);
      };
    });
    Array.prototype.forEach.call(app.querySelectorAll('.wf'), function (f) {
      f.onsubmit = function (e) {
        e.preventDefault();
        api('wallet_save', { body: { id: f.getAttribute('data-id'), name: f.name.value, kind: f.kind.value, opening_toman: norm(f.opening.value).replace(/[^\d-]/g, '') } })
          .then(loadLookups).then(function () { toast('ذخیره شد'); viewSettings(); }, function (err) { toast(err.message); });
      };
    });
    api('senders').then(function (d) {
      var chosen = d.bank_senders.map(function (x) { return String(x).toLowerCase(); });
      document.getElementById('slist').innerHTML = d.items.length ? d.items.map(function (x) {
        var on = chosen.indexOf(String(x.sender).toLowerCase()) !== -1;
        return '<label class="check"><input type="checkbox" value="' + esc(x.sender) + '"' + (on ? ' checked' : '') + '> <b class="num">' + esc(x.sender || '(بی‌نام)') + '</b> ' +
          '<span class="muted">' + faDigits(x.n) + ' پیامک' + (+x.tx ? ' · ' + faDigits(x.tx) + ' تراکنش' : '') + (+x.otp ? ' · ' + faDigits(x.otp) + ' رمز پویا' : '') + '</span></label>';
      }).join('') : 'هنوز پیامکی نرسیده.';
    });
    document.getElementById('sf').onsubmit = function (e) {
      e.preventDefault();
      var list = Array.prototype.map.call(app.querySelectorAll('#slist input:checked'), function (i) { return i.value; });
      api('senders_save', { body: { senders: list } }).then(function () { toast(list.length ? 'فقط این فرستنده‌ها بررسی می‌شوند' : 'همه فرستنده‌ها بررسی می‌شوند'); });
    };
    baleSettings();
    renderPerms(document.getElementById('permBox'));
    document.getElementById('testVoice').onclick = function () {
      Voice.speak('سلام. یک جمله بگو.').then(function () { return Voice.listen(5000); })
        .then(function (t) { toast(t ? 'شنیدم: ' + t : 'چیزی نشنیدم'); }, function (e) { toast('میکروفون: ' + e.message); });
    };
    document.getElementById('logout').onclick = function () { store(TOKEN_KEY, null); location.reload(); };
  }

  /* -- permissions: notifications + microphone (iPhone: from a tap only) -- */

  var Perm = {
    standalone: function () {
      return window.navigator.standalone === true || (window.matchMedia && matchMedia('(display-mode: standalone)').matches);
    },
    pushSupported: function () {
      return 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
    },
    notif: function () {
      return 'Notification' in window ? Notification.permission : 'unsupported';   // default | granted | denied
    },
    mic: function () { return store('ba_mic_ok') === '1'; },

    /** Must run inside a tap: asks for notifications, then subscribes this phone on the server. */
    enableNotifications: function () {
      if (!Perm.pushSupported()) return Promise.reject(new Error(Voice.isIOS && !Perm.standalone()
        ? 'اول اپ را به صفحه اصلی اضافه کن و از همان آیکن باز کن.' : 'این مرورگر نوتیف وب را پشتیبانی نمی‌کند.'));
      return Notification.requestPermission().then(function (p) {
        if (p !== 'granted') throw new Error(p === 'denied'
          ? 'نوتیف رد شده؛ از Settings گوشی ← Notifications ← دستیار بانک، روشنش کن.' : 'اجازه داده نشد.');
        return Promise.all([navigator.serviceWorker.ready, api('push_key')]);
      }).then(function (r) {
        var reg = r[0], key = b64uToBytes(r[1].key);
        var sub = function () { return reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: key }); };
        return reg.pushManager.getSubscription().then(function (old) {
          // a subscription made for another server key can't be reused
          return old ? old.unsubscribe().then(sub) : sub();
        });
      }).then(function (subscription) {
        return api('push_subscribe', { body: { subscription: subscription.toJSON() } });
      }).then(function () { store('ba_push_ok', '1'); });
    },

    /** Asks for the microphone once (the prompt) and releases it right away. */
    enableMic: function () {
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) return Promise.reject(new Error('این مرورگر به میکروفون دسترسی نمی‌دهد (https لازم است).'));
      return navigator.mediaDevices.getUserMedia({ audio: true }).then(function (stream) {
        stream.getTracks().forEach(function (t) { t.stop(); });
        store('ba_mic_ok', '1');
      }, function () {
        throw new Error('میکروفون رد شد؛ از Settings گوشی ← Safari (یا دستیار بانک) ← Microphone، اجازه بده.');
      });
    }
  };

  function b64uToBytes(s) {
    var b = atob(s.replace(/-/g, '+').replace(/_/g, '/') + '==='.slice((s.length + 3) % 4));
    var out = new Uint8Array(b.length);
    for (var i = 0; i < b.length; i++) out[i] = b.charCodeAt(i);
    return out;
  }

  function renderPerms(box) {
    if (!box) return;
    var row = function (icon, title, ok, action, note) {
      return '<div class="row between" style="margin:8px 0"><div class="grow"><div>' + icon + ' ' + title + '</div>' +
        (note ? '<div class="muted" style="font-size:13px">' + note + '</div>' : '') + '</div>' +
        (ok ? '<span class="in">✅</span>' : action || '<span class="muted">—</span>') + '</div>';
    };
    var iosBrowser = Voice.isIOS && !Perm.standalone();
    var n = Perm.notif(), me = state.me || {};
    var hasPersianVoice = !!Voice.faVoice;
    box.innerHTML =
      (iosBrowser ? row('📲', 'نصب روی صفحه اصلی', false, '', 'برای نوتیف لازم است: در Safari دکمه‌ی Share ← <b>Add to Home Screen</b>، بعد اپ را از آیکنش باز کن.') : '') +
      row('🔔', 'نوتیف (حتی وقتی اپ بسته است)', n === 'granted' && store('ba_push_ok') === '1',
        n === 'denied' ? '<span class="out">رد شده</span>' : '<button class="btn" type="button" id="pNotif"' + (iosBrowser ? ' disabled' : '') + '>اجازه بده</button>',
        n === 'denied' ? 'Settings گوشی ← Notifications ← دستیار بانک' : '') +
      row('🎙', 'میکروفون', Perm.mic(), '<button class="btn" type="button" id="pMic">اجازه بده</button>') +
      row('🗣', 'صدای فارسی دستیار', me.tts || hasPersianVoice, '<span class="muted">زنگ + متن</span>',
        me.tts ? 'از سرور' : hasPersianVoice ? 'صدای خود گوشی' : 'گوشی صدای فارسی ندارد؛ برای حرف زدن orb، صدای محلی سرور را نصب کن (voice/install.sh)') +
      row('👂', 'فهمیدن حرف تو', me.stt || (Voice.SR && !Voice.isIOS), '<span class="muted">تایپ</span>',
        me.stt ? 'از سرور' : Voice.SR && !Voice.isIOS ? 'خود مرورگر' : 'برای آیفون، تبدیل صدای محلی سرور را نصب کن (voice/install.sh)؛ تا آن موقع فرم باز می‌شود') +
      (n === 'granted' && store('ba_push_ok') === '1' ? '<div class="btns"><button class="btn ghost" type="button" id="pTest">نوتیف آزمایشی</button></div>' : '');
    var bind = function (id, fn) {
      var b = document.getElementById(id);
      if (b) b.onclick = function () {
        b.disabled = true;
        fn().then(function () { toast('✅ انجام شد'); renderPerms(box); }, function (e) { toast(e.message); renderPerms(box); });
      };
    };
    bind('pNotif', Perm.enableNotifications);
    bind('pMic', function () { Voice.unlock(); return Perm.enableMic(); });
    bind('pTest', function () { return api('push_test', { body: {} }).then(function () { toast('چند ثانیه صبر کن… (اپ را ببند تا نوتیف را ببینی)'); }); });
  }

  /** On the main screen: a short card until notifications and the mic are allowed. */
  function permNudge() {
    if (store('ba_perm_later') === '1' || document.getElementById('permNudge') || !document.getElementById('otpLink') && !app.querySelector('h1')) return;
    var needNotif = Perm.pushSupported() && Perm.notif() === 'default';
    if (!needNotif && Perm.mic()) return;
    if (Voice.isIOS && !Perm.standalone()) return;   // the settings page explains installing first
    var card = document.createElement('div');
    card.className = 'card';
    card.id = 'permNudge';
    card.innerHTML = '<b>🔔 دسترسی‌های دستیار</b><p class="muted">تا وقتی پیامک بانک رسید خبرت کنم و با صدا بپرسم، اجازه‌ی نوتیف و میکروفون را بده.</p>' +
      '<div class="btns"><button class="btn" type="button" id="nAllow">اجازه بده</button><button class="btn plain" type="button" id="nLater">بعداً</button></div>';
    app.insertBefore(card, app.children[1] || null);
    document.getElementById('nAllow').onclick = function () {
      Voice.unlock();
      var steps = needNotif ? Perm.enableNotifications() : Promise.resolve();   // first call stays inside the tap
      steps.catch(function (e) { toast(e.message); }).then(function () { return Perm.mic() ? null : Perm.enableMic(); })
        .then(function () { toast('✅ آماده‌ام'); card.remove(); }, function (e) { toast(e.message); });
    };
    document.getElementById('nLater').onclick = function () { store('ba_perm_later', '1'); card.remove(); };
  }

  /** Opened from a notification (#/orb/<id>): show the orb for that transaction. */
  function openOrbFor(id) {
    history.replaceState(null, '', '#/');
    return Promise.resolve(viewAsk()).then(function () {
      return api('transaction', { query: '&id=' + id });
    }).then(function (d) {
      var tx = d.item;
      if (tx.status !== 'pending') { toast('این تراکنش قبلاً جواب گرفته'); return; }
      if (+tx.id > (+store('ba_orb_last') || 0)) store('ba_orb_last', String(tx.id));   // the poller won't announce it again
      orbShow(tx);   // one tap on «جواب بده» starts the voice (iPhone needs that tap for sound and mic)
    }).catch(function (e) { toast(e.message); });
  }

  /* -- Bale bot connection (host) -- */

  var balePoll = null;

  function baleSettings() {
    clearTimeout(balePoll);
    var box = document.getElementById('bale');
    if (!box) return;
    api('settings').then(function (st) {
      var https = location.protocol === 'https:';
      var canConnect = https || st.polling;   // no https: the server's background service polls Bale
      var lines = [];
      if (st.bot) lines.push('🤖 ربات: <b class="num">@' + esc(st.bot) + '</b>');
      if (st.has_token && !https && st.polling) lines.push('🔗 اتصال به این سرور: ✅ <span class="muted">(بدون دامنه، سرویس پس‌زمینه پیام‌ها را می‌گیرد)</span>');
      if (st.has_token && https) lines.push(st.webhook ? '🔗 اتصال به این سرور: ✅' : '🔗 اتصال به این سرور: ❌' + (st.webhook_error ? ' <span class="muted">(' + esc(st.webhook_error) + ')</span>' : ''));
      if (st.chat_id) lines.push('💬 چت تو: ✅ وصل');
      else if (st.waiting_for_start) lines.push('<b class="warn">⏳ حالا در بله به ' + (st.bot ? '<span class="num">@' + esc(st.bot) + '</span>' : 'ربات') + ' پیام <span class="num">/start</span> بفرست…</b>');
      box.innerHTML = (lines.length ? '<p>' + lines.join('<br>') + '</p>' : '') +
        (!canConnect ? '<p class="muted">روی کامپیوتر خودت (بدون https) توکن و شناسه‌ی چت را در <span class="num">config.php</span> بگذار؛ این بخش برای هاست است.</p>' :
          '<p class="muted">ربات را در بله با <span class="num">@botfather</span> بساز و توکنش را اینجا بگذار. توکن مثل رمز است؛ به کسی نده.</p>' +
          '<form id="bf"><input id="btok" type="password" autocomplete="off" placeholder="' + (st.has_token ? 'توکن ذخیره شده — برای عوض کردن، توکن جدید' : 'توکن ربات') + '">' +
          '<div class="btns"><button class="btn">' + (st.has_token ? 'اتصال دوباره' : 'اتصال') + '</button>' +
          (st.chat_id ? '<button class="btn plain" type="button" id="bforget">چت دیگری صاحب ربات شود</button>' : '') + '</div></form>');
      var f = document.getElementById('bf');
      if (f) f.onsubmit = function (e) {
        e.preventDefault();
        f.querySelector('button').disabled = true;
        api('bale_connect', { body: { bale_bot_token: document.getElementById('btok').value.trim() } })
          .then(function (d) { toast(d.waiting_for_start ? 'حالا در بله /start بفرست' : 'وصل شد'); baleSettings(); },
            function (err) { toast(err.message); baleSettings(); });
      };
      var fg = document.getElementById('bforget');
      if (fg) fg.onclick = function () {
        if (!confirm('ربات از این چت جدا شود؟ بعدش اولین کسی که ظرف ۱۰ دقیقه /start بزند صاحبش می‌شود.')) return;
        api('bale_forget_chat', { body: {} }).then(baleSettings);
      };
      if (st.waiting_for_start && location.hash === '#/settings') balePoll = setTimeout(baleSettings, 3000);
      if (st.chat_id && state.me) state.me.bale = true;
    }, function (err) { box.innerHTML = '<p class="muted">' + esc(err.message) + '</p>'; });
  }


  /* ------------------------- AI Orb: نوتیف + دستیار صوتی ------------------------- *
   * وقتی پیامک تازه‌ی بانک برسد (تراکنش بی‌جواب جدید)، نوتیف orb از بالا می‌آید.
   * با زدن «جواب بده»، orb در یک‌سوم پایین صفحه باز می‌شود، می‌گوید «واریز/برداشت … بابت چی بود؟»،
   * جواب را می‌شنود، خلاصه را می‌خواند و بعد از «آره» در دفتر ثبت می‌کند. */

  var ORB_POLL_MS = 8000;
  var ORB_TIMEOUT = 12000;
  var ORB_CONFIRM = true;      // false = بعد از فهمیدن جواب، بدون پرسیدن «ثبت کنم؟» مستقیم ثبت کن
  var orb = { built: false, session: 0, busy: false, timer: null, poller: null, tx: null };

  function orbBuild() {
    if (orb.built) return;
    orb.built = true;
    var orbHtml = '<div class="orb"><i></i><i></i><i></i><i></i></div>';
    var n = document.createElement('div');
    n.id = 'orbNotif'; n.className = 'notif'; n.setAttribute('role', 'alertdialog'); n.setAttribute('aria-live', 'assertive');
    n.innerHTML = '<div class="notif-head">' + orbHtml +
      '<div class="notif-text"><div class="notif-title">دستیار بانک</div><div class="notif-body" id="orbNBody"></div></div>' +
      '<div class="notif-time">همین الان</div></div>' +
      '<div class="notif-actions"><button type="button" class="obtn obtn-yes" id="orbYes">جواب بده</button>' +
      '<button type="button" class="obtn obtn-no" id="orbNo">بعداً</button></div>' +
      '<div class="notif-timer"><b></b></div>';
    var s = document.createElement('div');
    s.id = 'orbStage'; s.className = 'stage';
    s.innerHTML = '<button type="button" class="close" id="orbClose" aria-label="بستن">✕</button>' + orbHtml +
      '<div class="stage-line" id="orbLine"></div><div class="stage-sub" id="orbSub"></div>';
    document.body.appendChild(n);
    document.body.appendChild(s);

    document.getElementById('orbYes').onclick = function () { Voice.unlock(); orbHide(false); orbStart(orb.tx); };
    document.getElementById('orbNo').onclick = function () { orbHide(true); };
    document.getElementById('orbClose').onclick = orbCloseStage;

    // کشیدن کادر به پایین = بستن
    var sy = null;
    s.addEventListener('pointerdown', function (e) { if (e.target.closest('button')) return; sy = e.clientY; s.classList.add('dragging'); s.setPointerCapture(e.pointerId); });
    s.addEventListener('pointermove', function (e) { if (sy === null) return; s.style.transform = 'translateY(' + Math.max(0, e.clientY - sy) + 'px)'; });
    var endSheet = function (e) {
      if (sy === null) return;
      var dy = e.clientY - sy; sy = null;
      s.classList.remove('dragging'); s.style.transform = '';
      if (dy > 60) orbCloseStage();
    };
    s.addEventListener('pointerup', endSheet);
    s.addEventListener('pointercancel', endSheet);

    // با نگه‌داشتن انگشت شمارش معکوس نوتیف می‌ایستد؛ کشیدن به بالا = رد کردن
    var startY = null;
    n.addEventListener('pointerdown', function (e) {
      n.classList.add('paused'); clearTimeout(orb.timer);
      if (e.target.closest('button')) return;
      startY = e.clientY; n.classList.add('dragging'); n.setPointerCapture(e.pointerId);
    });
    n.addEventListener('pointermove', function (e) {
      if (startY === null) return;
      n.style.transform = 'translate(-50%, ' + Math.min(0, e.clientY - startY) + 'px)';
    });
    var endN = function (e) {
      if (startY === null) { n.classList.remove('paused'); return; }
      var dy = e.clientY - startY; startY = null;
      n.classList.remove('dragging');
      if (dy < -40) orbHide(true);
      else { n.style.transform = ''; n.classList.remove('paused'); orb.timer = setTimeout(function () { orbHide(true); }, 5000); }
    };
    n.addEventListener('pointerup', endN);
    n.addEventListener('pointercancel', endN);
  }

  function orbShow(tx) {
    orbBuild();
    orb.tx = tx;
    var n = document.getElementById('orbNotif');
    document.getElementById('orbNBody').textContent =
      (tx.direction === 'in' ? 'واریز ' : 'برداشت ') + toman(tx.amount) + ' — بابت چی بود؟';
    n.style.setProperty('--timeout', ORB_TIMEOUT + 'ms');
    n.classList.remove('show'); void n.offsetWidth;
    n.classList.add('show');
    if (navigator.vibrate) navigator.vibrate(30);
    clearTimeout(orb.timer);
    orb.timer = setTimeout(function () { orbHide(true); }, ORB_TIMEOUT);
  }

  function orbHide() {
    clearTimeout(orb.timer);
    var n = document.getElementById('orbNotif');
    if (!n) return;
    n.classList.remove('show', 'paused');
    n.style.transform = '';
  }

  function orbSay(line, sub) {
    var l = document.getElementById('orbLine'), s = document.getElementById('orbSub');
    if (l) l.textContent = line || '';
    if (s) s.textContent = sub || '';
  }
  function orbMode(mode) {   // speaking | listening | ''
    var st = document.getElementById('orbStage');
    st.classList.toggle('speaking', mode === 'speaking');
    st.classList.toggle('listening', mode === 'listening');
  }
  function orbCloseStage() {
    orb.session++;                       // هر مرحله‌ی در حال اجرا را بی‌اثر می‌کند
    orb.busy = false;
    if (Voice.stop) Voice.stop();
    try { speechSynthesis.cancel(); } catch (e) { /* ignore */ }
    orbMode('');
    var st = document.getElementById('orbStage');
    if (st) st.classList.remove('open');
  }

  /** جلسه‌ی صوتی: بپرس ← بشنو ← بفهم ← تأیید ← ثبت */
  function orbStart(tx) {
    if (!tx) return;
    orbBuild();
    var my = ++orb.session;
    orb.busy = true;
    var alive = function () { return orb.session === my; };
    document.getElementById('orbStage').classList.add('open');

    var speak = function (text) { orbSay(text); orbMode('speaking'); return Voice.speak(text).then(function () { orbMode(''); }); };
    var hearOrb = function (ms, hint) {
      orbSay(document.getElementById('orbLine').textContent, hint || 'دارم گوش می‌دم…');
      orbMode('listening');
      return Voice.listen(ms).then(function (t) { orbMode(''); return t || ''; }, function (e) { orbMode(''); throw e; });
    };
    var finish = function (msg, ms) {
      orbSay(msg, '');
      setTimeout(function () { if (alive()) orbCloseStage(); }, ms || 1400);
      return Promise.resolve();
    };
    // اگر صدا کار نکرد یا جواب نامفهوم بود: فرم همان تراکنش را باز کن
    var manual = function (guess, why) {
      if (!alive()) return;
      orbCloseStage();
      history.replaceState(null, '', '#/ask/' + tx.id);
      Promise.resolve(viewAsk(tx.id)).then(function () { if (guess) fillForm(guess); });
      if (why) toast(why);
    };

    var q = (tx.direction === 'in' ? 'واریز ' : 'برداشت ') + spokenToman(tx.amount) +
      (tx.bank_time ? '، ساعت ' + tx.bank_time : '') + '. بابت چی بود؟';

    speak(q).then(function () {
      if (!alive()) return;
      return hearOrb(9000).then(function (ans) {
        if (!alive()) return;
        if (!ans) return speak('چیزی نشنیدم. فرمش رو باز می‌کنم.').then(function () { manual(null); });
        var a = norm(ans);
        orbSay(ans, '');
        if (/^(بعدی|رد کن|ردش کن|بعدا|بگذر|تمام|بسه|توقف|کافیه|خداحافظ)/.test(a)) return finish('باشه، بعداً می‌پرسم.');
        if (/(نادیده|حساب نکن|ثبت نکن|تکراری)/.test(a)) {
          return api('ignore', { body: { id: tx.id } }).then(function () { return speak('نادیده گرفتم.'); }).then(function () { return finish('نادیده گرفته شد'); });
        }
        return api('interpret', { body: { text: ans, direction: tx.direction } }).then(function (d) {
          if (!alive()) return;
          var g = d.guess;
          var saveIt = function () {
            return api('confirm', { body: { id: tx.id, description: g.description, party: g.party || '', category_id: g.category_id || '', note: '' } }).then(function (r) {
              if (g.party && !state.parties.some(function (p) { return p.name === g.party; })) state.parties.push({ name: g.party, last_category_id: g.category_id });
              toast('ثبت شد' + (r.synced ? ' و به حسابداری رفت' : ''));
              api('pending').then(function (p) { setBadge(p.items.length); if (location.hash === '#/' || location.hash === '') viewAsk(); }).catch(function () {});
              return speak('ثبت شد.').then(function () { return finish('✅ ثبت شد'); });
            });
          };
          var summary = (g.party ? (tx.direction === 'in' ? 'از ' : 'به ') + g.party + '، ' : '') +
            (g.category_id ? 'دسته‌ی ' + catName(g.category_id) + '، ' : '') + (ORB_CONFIRM ? 'ثبت کنم؟' : 'ثبت شد.');
          if (!ORB_CONFIRM && g.description) return saveIt();
          return speak(summary).then(function () {
            if (!alive()) return;
            return hearOrb(4000, 'بگو «آره» تا ثبت کنم').then(function (yn) {
              if (!alive()) return;
              if (yn) orbSay(yn, '');
              if (isYes(yn)) return saveIt();
              return speak('باشه، خودت فرم رو درست کن.').then(function () { manual(g); });
            });
          });
        });
      });
    }).catch(function (err) {
      if (!alive()) return;
      var voiceProblem = err && (err.message === 'no-recognition' || /language|not-allowed|service/.test(err.message));
      manual(null, voiceProblem ? 'تشخیص گفتار فارسی روی این گوشی فعال نشد؛ فرم را پر کن' : (err && err.message !== 'empty' ? 'خطا: ' + err.message : ''));
    });
  }

  /** هر چند ثانیه سرور را نگاه می‌کند؛ تراکنش بی‌جوابِ تازه ← نوتیف orb. */
  function orbCheck() {
    if (!token() || document.hidden || orb.busy) return;
    var n = document.getElementById('orbNotif');
    if (n && n.classList.contains('show')) return;
    api('pending').then(function (d) {
      setBadge(d.items.length);
      var last = +store('ba_orb_last') || 0;
      var maxId = d.items.reduce(function (m, t) { return Math.max(m, +t.id); }, 0);
      if (!store('ba_orb_last')) { store('ba_orb_last', String(maxId)); return; }   // بار اول: قدیمی‌ها را نپرس
      var fresh = d.items.filter(function (t) { return +t.id > last; })[0];
      if (!fresh) return;
      store('ba_orb_last', String(fresh.id));
      if (!orb.busy) orbShow(fresh);
    }).catch(function () {});
  }

  function orbWatch() {
    clearInterval(orb.poller);
    orbBuild();
    orbCheck();
    orb.poller = setInterval(orbCheck, ORB_POLL_MS);
  }

  /* ------------------------------- router ------------------------------ */

  function route() {
    if (!token()) return viewLogin();
    run.active = false;
    if (Voice.stop) Voice.stop();
    var h = location.hash.replace(/^#\/?/, '');
    var tab = h.split('/')[0] || 'home';
    clearInterval(otp.timer);
    var parts = h.split('/');
    var tabOf = { ask: 'home', otp: 'home', manual: 'history', person: 'people', 'person-edit': 'people' };
    Array.prototype.forEach.call(document.querySelectorAll('.tabbar a'), function (a) {
      a.classList.toggle('on', a.getAttribute('data-tab') === (tabOf[tab] || tab));
    });
    var p;
    if (tab === 'ask') p = viewAsk(+parts[1]);
    else if (tab === 'history') p = viewHistory();
    else if (tab === 'manual') p = viewManual(parts[1] ? { type: parts[1], party: decodeURIComponent(parts[2] || '') } : {});
    else if (tab === 'people') p = viewPeople();
    else if (tab === 'person') p = viewPerson(decodeURIComponent(parts[1] || ''));
    else if (tab === 'person-edit') p = viewPersonEdit(decodeURIComponent(parts[1] || ''));
    else if (tab === 'otp') p = viewOtp();
    else if (tab === 'orb') p = openOrbFor(+parts[1]);
    else if (tab === 'reconcile') p = viewReconcile();
    else if (tab === 'settings') p = viewSettings();
    else p = viewAsk();
    Promise.resolve(p).catch(function (e) { app.innerHTML = '<div class="card">خطا: ' + esc(e.message) + '</div>'; });
    window.scrollTo(0, 0);
  }

  function boot() {
    if (!token()) return viewLogin();
    document.getElementById('tabbar').hidden = false;
    api('me').then(function (d) {
      state.me = d;
      return loadLookups();
    }).then(route, function (e) { app.innerHTML = '<div class="card">خطا: ' + esc(e.message) + '</div>'; }).then(orbWatch);
  }

  Voice.init();
  window.addEventListener('hashchange', route);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden && token() && !run.active && !orb.busy && (location.hash === '' || location.hash === '#/')) route();
  });
  if ('serviceWorker' in navigator) {
    navigator.serviceWorker.register('sw.js').catch(function () {});
    // notification tapped while the app was already open
    navigator.serviceWorker.addEventListener('message', function (e) {
      if (e.data && e.data.type === 'open') location.hash = new URL(e.data.url).hash || '#/';
    });
  }
  document.addEventListener('visibilitychange', function () { if (!document.hidden && token()) orbCheck(); });
  boot();
})();
