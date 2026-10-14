/*
 * Bank SMS parser for the app without a server: a line-by-line port of
 * server/lib.php (ba_normalize, ba_parse_sms, ba_detect_bank, ba_parse_otp,
 * ba_g2j / ba_j2g). Checked against the PHP output by server/tests/local_sms_test.js.
 * Works in the browser (window.BASms) and in Node (require).
 */
(function (root) {
  'use strict';

  function normalize(text, keepSeparators) {
    var s = String(text == null ? '' : text)
      .replace(/[۰-۹]/g, function (d) { return String(d.charCodeAt(0) - 0x6F0); })
      .replace(/[٠-٩]/g, function (d) { return String(d.charCodeAt(0) - 0x660); })
      .replace(/ي/g, 'ی').replace(/ك/g, 'ک').replace(/‌/g, ' ').replace(/\r/g, '');
    if (keepSeparators) return s;
    return s.replace(/(?<=\d)[,٬،](?=\d{3})/g, '');
  }

  function div(a, b) { return Math.trunc(a / b); }

  function g2j(gy, gm, gd) {
    var gdm = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
    var gy2 = gm > 2 ? gy + 1 : gy;
    var days = 355666 + 365 * gy + div(gy2 + 3, 4) - div(gy2 + 99, 100) + div(gy2 + 399, 400) + gd + gdm[gm - 1];
    var jy = -1595 + 33 * div(days, 12053);
    days %= 12053;
    jy += 4 * div(days, 1461);
    days %= 1461;
    if (days > 365) { jy += div(days - 1, 365); days = (days - 1) % 365; }
    var jm, jd;
    if (days < 186) { jm = 1 + div(days, 31); jd = 1 + days % 31; } else { jm = 7 + div(days - 186, 30); jd = 1 + (days - 186) % 30; }
    return [jy, jm, jd];
  }

  function j2g(jy, jm, jd) {
    jy += 1595;
    var days = -355668 + 365 * jy + div(jy, 33) * 8 + div((jy % 33) + 3, 4) + jd + (jm < 7 ? (jm - 1) * 31 : (jm - 7) * 30 + 186);
    var gy = 400 * div(days, 146097);
    days %= 146097;
    if (days > 36524) { days--; gy += 100 * div(days, 36524); days %= 36524; if (days >= 365) days++; }
    gy += 4 * div(days, 1461);
    days %= 1461;
    if (days > 365) { gy += div(days - 1, 365); days = (days - 1) % 365; }
    var gd = days + 1;
    var leap = (gy % 4 === 0 && gy % 100 !== 0) || gy % 400 === 0;
    var months = [0, 31, leap ? 29 : 28, 31, 30, 31, 30, 31, 31, 30, 31, 30, 31];
    var gm = 1;
    for (; gm <= 12 && gd > months[gm]; gm++) gd -= months[gm];
    return [gy, gm, gd];
  }

  function pad(n, w) { return String(n).padStart(w || 2, '0'); }

  function jalaliToIso(jdate) {
    var m = /^(\d{2,4})[/\-.](\d{1,2})[/\-.](\d{1,2})$/.exec(normalize(jdate).trim());
    if (!m) return null;
    var jy = +m[1];
    if (jy < 100) jy += 1400;
    var g = j2g(jy, +m[2], +m[3]);
    return pad(g[0], 4) + '-' + pad(g[1]) + '-' + pad(g[2]);
  }

  function isoToJalali(iso) {
    var j = g2j(+iso.slice(0, 4), +iso.slice(5, 7), +iso.slice(8, 10));
    return pad(j[0], 4) + '/' + pad(j[1]) + '/' + pad(j[2]);
  }

  function todayJalali(now) {
    var d = now || new Date();
    return g2j(d.getFullYear(), d.getMonth() + 1, d.getDate());
  }

  /** PHP mb_strcut($t, start, len) on UTF-8 byte offsets, as ba_parse_sms uses it. */
  var enc = typeof TextEncoder !== 'undefined' ? new TextEncoder() : null;
  var dec = typeof TextDecoder !== 'undefined' ? new TextDecoder() : null;
  function byteOffset(str, charIndex) { return enc.encode(str.slice(0, charIndex)).length; }
  function strcut(str, start, len) {
    var b = enc.encode(str), s = start, e = Math.min(b.length, start + len);
    while (s < e && (b[s] & 0xC0) === 0x80) s--;   // mb_strcut moves the start back to a character start
    while (e < b.length && e > s && (b[e] & 0xC0) === 0x80) e--;
    return dec.decode(b.slice(Math.max(0, s), e));
  }

  var IN_WORDS = 'واریز|بستانکار|انتقال به حساب شما|نشست|سود';
  var OUT_WORDS = 'برداشت|بدهکار|خرید|انتقال از|کارمزد|پرداخت';

  function parseSms(text, now) {
    var t = normalize(text), m;
    var direction = null, amount = null;

    // 0) "مبلغ: 2000000-" wins when present
    if ((m = /مبلغ\s*(?:تراکنش)?\s*[:：]?\s*([+\-]?)\s*(\d+)\s*([+\-]?)/u.exec(t))) {
      amount = parseInt(m[2], 10);
      var sign0 = m[1] !== '' ? m[1] : m[3];
      direction = sign0 === '+' ? 'in' : sign0 === '-' ? 'out' : null;
    }
    // 1) keyword directly followed by the amount
    if (amount === null && (m = new RegExp('(' + IN_WORDS + '|' + OUT_WORDS + '|مبلغ)\\s*[:：]?\\s*([+\\-]?)\\s*(\\d+)\\s*([+\\-]?)', 'u').exec(t))) {
      amount = parseInt(m[3], 10);
      var sign = m[2] !== '' ? m[2] : m[4];
      if (sign === '+') direction = 'in';
      else if (sign === '-') direction = 'out';
      else if (new RegExp('^(' + IN_WORDS + ')$', 'u').test(m[1])) direction = 'in';
      else if (new RegExp('^(' + OUT_WORDS + ')$', 'u').test(m[1])) direction = 'out';
    }
    // 1b) the first amount in rial / toman that is not the balance
    if (amount === null && enc) {
      var re = /(\d+)\s*(ریال|تومان)/gu, a;
      while ((a = re.exec(t))) {
        var off = byteOffset(t, a.index);
        var before = strcut(t, Math.max(0, off - 40), Math.min(40, off));
        if (!/مانده|موجودی/u.test(before)) {
          amount = parseInt(a[1], 10) * (a[2] === 'تومان' ? 10 : 1);
          break;
        }
      }
    }
    // 2) direction from anywhere in the text
    if (amount !== null && direction === null) {
      if (new RegExp(IN_WORDS, 'u').test(t)) direction = 'in';
      else if (new RegExp(OUT_WORDS, 'u').test(t)) direction = 'out';
    }
    if (amount === null || !(amount > 0) || direction === null) return null;

    var balance = null;
    if ((m = /(?:مانده|موجودی)\s*[:：]?\s*([+\-]?)\s*(\d+)\s*([+\-]?)/u.exec(t))) {
      balance = parseInt(m[2], 10);
      if (m[1] === '-' || m[3] === '-') balance = -balance;
    }
    var account = null;
    if ((m = /(?:حساب|سپرده|کارت)\s*[:：]?\s*(?:شماره\s*)?([0-9*.\-]{4,})/u.exec(t))) {
      account = m[1].replace(/^[.\-]+|[.\-]+$/g, '');
    }
    var jdate = null, time = null;
    if ((m = /(1[34]\d{2}|\d{2})\/(\d{1,2})\/(\d{1,2})/.exec(t))) {
      var jy = +m[1];
      if (jy < 100) jy += 1400;
      jdate = pad(jy, 4) + '/' + pad(+m[2]) + '/' + pad(+m[3]);
    } else if ((m = /(?<!\d)(\d{2})(\d{2})-(\d{1,2}):(\d{2})(?!\d)/.exec(t))) {
      var tj = todayJalali(now), jm = +m[1];
      jdate = pad(jm > tj[1] ? tj[0] - 1 : tj[0], 4) + '/' + pad(jm) + '/' + pad(+m[2]);
      time = pad(+m[3]) + ':' + pad(+m[4]);
    }
    if (time === null && (m = /(?<!\d)(\d{1,2}):(\d{2})(?!\d)/.exec(t))) time = pad(+m[1]) + ':' + pad(+m[2]);

    return { direction: direction, amount: amount, balance: balance, account: account, bank_date: jdate, bank_time: time };
  }

  // PHP's \b under /u is Unicode-aware (PCRE UCP: Persian letters are word characters); JS's isn't
  var W = '[\\p{L}\\p{N}_]';
  var UB = '(?:(?<=' + W + ')(?!' + W + ')|(?<!' + W + ')(?=' + W + '))';
  function ure(src, flags) { return new RegExp(src.replace(/\\b/g, UB), flags); }

  var BANKS = {
    mellat: { name: 'بانک ملت', word: 'ملت', color: '#c8102e', sender: /mellat|700717/i, body: ure('بانک\\s*ملت|^\\s*ملت\\b|mellat', 'iu') },
    blu: { name: 'بلو بانک', word: 'بلو', color: '#1688f0', sender: /\bblu|blubank/i, body: ure('^\\s*بلو\\b|بلو\\s*بانک|بلوبانک|\\bblu\\b', 'iu') },
    melli: { name: 'بانک ملی', word: 'ملی', color: '#0b3a74', sender: /melli|\bbmi\b/i, body: ure('بانک\\s*ملی|ملی\\s*ایران|\\bbmi\\b|melli', 'iu') },
    saderat: { name: 'بانک صادرات', word: 'صادرات', color: '#0e5e8c', sender: /saderat|\bbsi\b/i, body: ure('صادرات|saderat|\\bbsi\\b', 'iu') }
  };

  function detectBank(sender, body) {
    var s = String(sender || '').replace(/\s+/g, '').toLowerCase(), k;
    for (k in BANKS) if (s !== '' && BANKS[k].sender.test(s)) return k;
    var t = normalize(body);
    for (k in BANKS) if (BANKS[k].body.test(t)) return k;
    return null;
  }

  var OTP_WORDS = ure('(رمز\\s*(دوم\\s*)?پویا|رمز\\s*یک\\s*بار|رمز\\s*یکبار|یک\\s*بار\\s*مصرف|یکبارمصرف|کد\\s*(تایید|تأیید|یکبار|یک بار|امنیتی|فعال\\s*سازی)|\\bOTP\\b|one.?time|verification code)', 'iu');

  function otpDetails(text, now) {
    var t = normalize(text), d = { amount: null, merchant: null, ttl: null }, m;
    if ((m = /مبلغ\s*(?:خرید|تراکنش)?\s*[:：]?\s*(\d+)\s*(ریال|تومان)?/u.exec(t))) d.amount = parseInt(m[1], 10) * (m[2] === 'تومان' ? 10 : 1);
    if ((m = /(?:نام\s*پذیرنده|پذیرنده|فروشگاه|پرداخت\s*به)\s*[:：]\s*([^\n]{2,60})/u.exec(t))) d.merchant = m[1].trim();
    if ((m = /(?:مهلت|اعتبار|معتبر)[^\n\d]{0,20}(\d{1,3})\s*(ثانیه|دقیقه)/u.exec(t))) d.ttl = +m[1] * (m[2] === 'دقیقه' ? 60 : 1);
    else if ((m = /(?:اعتبار|معتبر)[^\n\d]{0,12}(\d{1,2}):(\d{2})(?::(\d{2}))?/u.exec(t))) {
      var n = now || new Date(), until = new Date(n.getFullYear(), n.getMonth(), n.getDate(), +m[1], +m[2], +(m[3] || 0));
      if (until > n) d.ttl = Math.round((until - n) / 1000);
    }
    return d;
  }

  function parseOtp(text, now) {
    var t = normalize(text);
    if (!OTP_WORDS.test(t)) return null;
    var code = null, lines = t.split('\n');
    for (var i = 0; i < lines.length; i++) {
      if (!/رمز|کد|code|otp|pass/iu.test(lines[i])) continue;
      var clean = lines[i]
        .replace(/مبلغ\s*(?:خرید|تراکنش)?\s*[:：]?\s*\d+/gu, ' ')
        .replace(/\d+\s*(?:ریال|تومان|ثانیه|دقیقه)/gu, ' ')
        .replace(/\d{1,2}:\d{2}(?::\d{2})?/g, ' ')
        .replace(/\d{2,4}\/\d{1,2}\/\d{1,2}/g, ' ')
        .replace(/[\d*]*\*[\d*]*/g, ' ');
      var m = /(?<!\d)(\d{4,10})(?!\d)/.exec(clean);
      if (m) { code = m[1]; break; }
    }
    var d = otpDetails(text, now);
    return { code: code, amount: d.amount, merchant: d.merchant, ttl: d.ttl };
  }

  var API = { normalize: normalize, parseSms: parseSms, parseOtp: parseOtp, detectBank: detectBank, BANKS: BANKS,
    g2j: g2j, j2g: j2g, jalaliToIso: jalaliToIso, isoToJalali: isoToJalali, todayJalali: todayJalali };
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
  else root.BASms = API;
})(this);
