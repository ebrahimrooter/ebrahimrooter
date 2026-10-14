/*
 * .xlsx in the app without a server: a port of server/xlsx.php (xlsx_build):
 * the same sheets, RTL, frozen header, number format, column widths and
 * styles. The zip stores the files uncompressed (method 0), which every Excel
 * reads; no password here (the file stays on your phone or goes to your Bale).
 *   BAXlsx.build([{ name, head: [...], rows: [[...]], widths?: [], bold_rows?: [] }]) → Uint8Array
 */
(function (root) {
  'use strict';

  function esc(s) {
    return String(s).replace(/[^\x09\x0A\x0D\x20-퟿-�\uD800-\uDFFF]/g, '')
      .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
  }
  function col(i) {
    var s = '';
    for (i++; i > 0; i = Math.floor((i - 1) / 26)) s = String.fromCharCode(65 + (i - 1) % 26) + s;
    return s;
  }
  function isNum(v) { return typeof v === 'number' && isFinite(v); }
  function cell(ref, v, style) {
    if (isNum(v)) {
      var n = Number.isInteger(v) ? String(v) : v.toFixed(6).replace(/0+$/, '').replace(/\.$/, '');
      return '<c r="' + ref + '" s="' + style + '"><v>' + n + '</v></c>';
    }
    return '<c r="' + ref + '" s="' + style + '" t="inlineStr"><is><t xml:space="preserve">' + esc(v) + '</t></is></c>';
  }
  function sheetName(name, used) {
    name = String(name).replace(/[\[\]:*?\/\\]/g, ' ').trim().replace(/^'+|'+$/g, '') || 'Sheet';
    name = Array.from(name).slice(0, 31).join('');
    var base = name;
    for (var k = 2; used[name.toLowerCase()]; k++) name = Array.from(base).slice(0, 28).join('') + ' ' + k;
    used[name.toLowerCase()] = true;
    return name;
  }
  function len(s) { return Array.from(String(s)).length; }

  function sheetXml(sh) {
    var head = sh.head || [], rows = sh.rows || [];
    var cols = Math.max(1, head.length, rows.reduce(function (m, r) { return Math.max(m, r.length); }, 0));
    var x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' +
      '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
      '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr><sheetViews><sheetView rightToLeft="1" workbookViewId="0">' +
      (head.length ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '') + '</sheetView></sheetViews>' +
      '<sheetFormatPr defaultRowHeight="18"/><cols>';
    for (var c = 0; c < cols; c++) {
      var w = sh.widths && sh.widths[c];
      if (w == null) {
        w = len(head[c] == null ? '' : head[c]) + 2;
        rows.slice(0, 300).forEach(function (r) {
          var v = r[c] == null ? '' : r[c];
          w = Math.max(w, (isNum(v) ? Math.round(v).toLocaleString('en-US').length : len(v)) + 2);
        });
        w = Math.min(60, Math.max(8, w));
      }
      x += '<col min="' + (c + 1) + '" max="' + (c + 1) + '" width="' + w + '" customWidth="1"/>';
    }
    x += '</cols><sheetData>';
    var r = 1;
    if (head.length) {
      x += '<row r="1" ht="22" customHeight="1">' + head.map(function (v, i) { return cell(col(i) + '1', String(v), 1); }).join('') + '</row>';
      r = 2;
    }
    var bold = {};
    (sh.bold_rows || []).forEach(function (i) { bold[i] = 1; });
    rows.forEach(function (row, i) {
      x += '<row r="' + r + '">';
      row.forEach(function (v, c2) {
        if (v == null || v === '') return;
        x += cell(col(c2) + r, v, isNum(v) ? (bold[i] ? 3 : 2) : (bold[i] ? 4 : 0));
      });
      x += '</row>';
      r++;
    });
    x += '</sheetData>';
    if (head.length && rows.length) x += '<autoFilter ref="A1:' + col(cols - 1) + (r - 1) + '"/>';
    return x + '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>' +
      '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
  }

  function build(sheets) {
    var files = {}, entries = '', rels = '', overrides = '', used = {};
    sheets.forEach(function (sh, i) {
      var n = i + 1;
      entries += '<sheet name="' + esc(sheetName(sh.name || 'Sheet' + n, used)) + '" sheetId="' + n + '" r:id="rId' + n + '"/>';
      rels += '<Relationship Id="rId' + n + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' + n + '.xml"/>';
      overrides += '<Override PartName="/xl/worksheets/sheet' + n + '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
      files['xl/worksheets/sheet' + n + '.xml'] = sheetXml(sh);
    });
    var X = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    files['[Content_Types].xml'] = X + '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">' +
      '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>' +
      '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>' +
      '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>' +
      '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>' + overrides + '</Types>';
    files['_rels/.rels'] = X + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' +
      '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>' +
      '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/></Relationships>';
    files['docProps/core.xml'] = X + '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" ' +
      'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"><dc:title>' + esc('حساب‌ها') + '</dc:title><dc:creator>Bank assistant</dc:creator>' +
      '<dcterms:created xsi:type="dcterms:W3CDTF">' + new Date().toISOString().replace(/\.\d+Z$/, 'Z') + '</dcterms:created></cp:coreProperties>';
    files['xl/workbook.xml'] = X + '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">' +
      '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' + entries + '</sheets></workbook>';
    files['xl/_rels/workbook.xml.rels'] = X + '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' + rels +
      '<Relationship Id="rId' + (sheets.length + 1) + '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    files['xl/styles.xml'] = X + '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">' +
      '<fonts count="3"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font><font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>' +
      '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>' +
      '<fill><patternFill patternType="solid"><fgColor rgb="FF1F8F45"/><bgColor indexed="64"/></patternFill></fill></fills>' +
      '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>' +
      '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>' +
      '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>' +
      '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>' +
      '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/><xf numFmtId="3" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>' +
      '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return zip(files);
  }

  var CRC = (function () {
    var t = new Uint32Array(256);
    for (var n = 0; n < 256; n++) { var c = n; for (var k = 0; k < 8; k++) c = c & 1 ? 0xEDB88320 ^ (c >>> 1) : c >>> 1; t[n] = c >>> 0; }
    return t;
  })();
  function crc32(b) { var c = 0xFFFFFFFF; for (var i = 0; i < b.length; i++) c = CRC[(c ^ b[i]) & 0xFF] ^ (c >>> 8); return (c ^ 0xFFFFFFFF) >>> 0; }

  function zip(files) {
    var enc = new TextEncoder(), parts = [], central = [], off = 0, n = 0, d = new Date();
    var dosTime = (d.getHours() << 11) | (d.getMinutes() << 5) | (d.getSeconds() >> 1);
    var dosDate = (Math.max(0, d.getFullYear() - 1980) << 9) | ((d.getMonth() + 1) << 5) | d.getDate();
    Object.keys(files).forEach(function (name) {
      var data = enc.encode(files[name]), nm = enc.encode(name), crc = crc32(data);
      var h = new DataView(new ArrayBuffer(30));
      h.setUint32(0, 0x04034b50, true); h.setUint16(4, 20, true); h.setUint16(6, 0x0800, true); h.setUint16(8, 0, true);
      h.setUint16(10, dosTime, true); h.setUint16(12, dosDate, true); h.setUint32(14, crc, true);
      h.setUint32(18, data.length, true); h.setUint32(22, data.length, true); h.setUint16(26, nm.length, true); h.setUint16(28, 0, true);
      parts.push(new Uint8Array(h.buffer), nm, data);
      var c = new DataView(new ArrayBuffer(46));
      c.setUint32(0, 0x02014b50, true); c.setUint16(4, 20, true); c.setUint16(6, 20, true); c.setUint16(8, 0x0800, true); c.setUint16(10, 0, true);
      c.setUint16(12, dosTime, true); c.setUint16(14, dosDate, true); c.setUint32(16, crc, true); c.setUint32(20, data.length, true);
      c.setUint32(24, data.length, true); c.setUint16(28, nm.length, true); c.setUint32(42, off, true);
      central.push(new Uint8Array(c.buffer), nm);
      off += 30 + nm.length + data.length;
      n++;
    });
    var csize = central.reduce(function (s, p) { return s + p.length; }, 0);
    var e = new DataView(new ArrayBuffer(22));
    e.setUint32(0, 0x06054b50, true); e.setUint16(8, n, true); e.setUint16(10, n, true); e.setUint32(12, csize, true); e.setUint32(16, off, true);
    var all = parts.concat(central, [new Uint8Array(e.buffer)]);
    var out = new Uint8Array(all.reduce(function (s, p) { return s + p.length; }, 0)), at = 0;
    all.forEach(function (p) { out.set(p, at); at += p.length; });
    return out;
  }

  var API = { build: build, zip: zip };
  if (typeof module !== 'undefined' && module.exports) module.exports = API;
  else root.BAXlsx = API;
})(this);
