<?php
/**
 * Excel files (.xlsx) without any PHP extension beyond zlib and openssl:
 *
 *   xlsx_build($sheets)            → the .xlsx bytes
 *   xlsx_encrypt($xlsx, $password) → the same workbook locked with a password the
 *                                    way Excel does it (ECMA-376 «agile» encryption,
 *                                    AES-256 + SHA-512); Excel / LibreOffice ask
 *                                    for the password when opening it.
 *
 * $sheets = [['name' => 'تراکنش‌ها', 'head' => ['تاریخ', 'مبلغ'], 'rows' => [[...], ...],
 *             'widths' => [12, 16]], ...]
 * A cell that is int/float is written as a number (shown with thousands separators),
 * anything else as text. Sheets are right-to-left with a frozen, filterable header.
 */

/* ------------------------------------------------------------------ workbook */

function xlsx_build(array $sheets)
{
    $files = [];
    $sheetEntries = $rels = $overrides = '';
    $used = [];
    foreach (array_values($sheets) as $i => $sh) {
        $n = $i + 1;
        $name = xlsx_sheet_name((string)($sh['name'] ?? "Sheet$n"), $used);
        $sheetEntries .= '<sheet name="' . xlsx_esc($name) . '" sheetId="' . $n . '" r:id="rId' . $n . '"/>';
        $rels .= '<Relationship Id="rId' . $n . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $n . '.xml"/>';
        $overrides .= '<Override PartName="/xl/worksheets/sheet' . $n . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $files["xl/worksheets/sheet$n.xml"] = xlsx_sheet_xml($sh);
    }
    $count = count($sheets);
    $files['[Content_Types].xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . $overrides . '</Types>';
    $files['_rels/.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '</Relationships>';
    $files['docProps/core.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" '
        . 'xmlns:dcterms="http://purl.org/dc/terms/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>' . xlsx_esc('پشتیبان حساب‌ها') . '</dc:title><dc:creator>Bank assistant</dc:creator>'
        . '<dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>';
    $files['xl/workbook.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<bookViews><workbookView activeTab="0"/></bookViews><sheets>' . $sheetEntries . '</sheets></workbook>';
    $files['xl/_rels/workbook.xml.rels'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">' . $rels
        . '<Relationship Id="rId' . ($count + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>';
    // 0 normal · 1 header (bold, green, centred) · 2 number #,##0 · 3 bold number (totals) · 4 bold text
    $files['xl/styles.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="3"><font><sz val="11"/><name val="Tahoma"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Tahoma"/></font>'
        . '<font><b/><sz val="11"/><name val="Tahoma"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FF1F8F45"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="5"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyAlignment="1"><alignment horizontal="center" vertical="center"/></xf>'
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="3" fontId="2" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>';
    return xlsx_zip($files);
}

function xlsx_sheet_xml(array $sh)
{
    $head = array_values((array)($sh['head'] ?? []));
    $rows = array_values((array)($sh['rows'] ?? []));
    $cols = max(1, count($head), $rows ? max(array_map('count', $rows)) : 0);
    $last = xlsx_col($cols - 1);
    $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheetPr><pageSetUpPr fitToPage="1"/></sheetPr>'   // printed: all columns on the page width
        . '<sheetViews><sheetView rightToLeft="1" workbookViewId="0">'
        . ($head ? '<pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/>' : '') . '</sheetView></sheetViews>'
        . '<sheetFormatPr defaultRowHeight="18"/><cols>';
    for ($c = 0; $c < $cols; $c++) {
        $w = $sh['widths'][$c] ?? null;
        if ($w === null) {   // as wide as the longest value (within reason)
            $w = mb_strlen((string)($head[$c] ?? '')) + 2;
            foreach (array_slice($rows, 0, 300) as $r) {
                $v = $r[$c] ?? '';
                $w = max($w, (is_int($v) || is_float($v) ? strlen(number_format($v)) : mb_strlen((string)$v)) + 2);
            }
            $w = min(60, max(8, $w));
        }
        $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $x .= '</cols><sheetData>';
    $r = 1;
    if ($head) {
        $x .= '<row r="1" ht="22" customHeight="1">';
        foreach ($head as $c => $v) {
            $x .= xlsx_cell(xlsx_col($c) . '1', (string)$v, 1);
        }
        $x .= '</row>';
        $r = 2;
    }
    $boldRows = array_flip((array)($sh['bold_rows'] ?? []));   // e.g. a totals row
    foreach ($rows as $i => $row) {
        $x .= '<row r="' . $r . '">';
        $bold = isset($boldRows[$i]);
        foreach (array_values($row) as $c => $v) {
            if ($v === null || $v === '') {
                continue;
            }
            $num = is_int($v) || is_float($v);
            $x .= xlsx_cell(xlsx_col($c) . $r, $v, $num ? ($bold ? 3 : 2) : ($bold ? 4 : 0));
        }
        $x .= '</row>';
        $r++;
    }
    $x .= '</sheetData>';
    if ($head && $rows) {
        $x .= '<autoFilter ref="A1:' . $last . ($r - 1) . '"/>';
    }
    return $x . '<pageMargins left="0.4" right="0.4" top="0.5" bottom="0.5" header="0.3" footer="0.3"/>'
        . '<pageSetup paperSize="9" orientation="landscape" fitToWidth="1" fitToHeight="0"/></worksheet>';
}

function xlsx_cell($ref, $v, $style)
{
    if (is_int($v) || is_float($v)) {
        $num = is_float($v) ? rtrim(rtrim(sprintf('%.6F', $v), '0'), '.') : (string)$v;
        return '<c r="' . $ref . '" s="' . $style . '"><v>' . $num . '</v></c>';
    }
    return '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_esc((string)$v) . '</t></is></c>';
}

/** 0 → A, 25 → Z, 26 → AA */
function xlsx_col($i)
{
    $s = '';
    for ($i++; $i > 0; $i = intdiv($i - 1, 26)) {
        $s = chr(65 + ($i - 1) % 26) . $s;
    }
    return $s;
}

/** Text for XML: escaped, characters XML does not allow removed. */
function xlsx_esc($s)
{
    $s = (string)$s;
    if (!mb_check_encoding($s, 'UTF-8')) {
        $s = mb_convert_encoding($s, 'UTF-8', 'UTF-8');
    }
    $s = preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $s);
    // a text cell starting with = + - @ is kept as text by Excel anyway (inline string), nothing to run
    return htmlspecialchars($s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/** Excel's rules for sheet names: ≤ 31 characters, none of []:*?/\, unique. */
function xlsx_sheet_name($name, array &$used)
{
    $name = trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $name), " '") ?: 'Sheet';
    $name = mb_substr($name, 0, 31);
    $base = $name;
    for ($k = 2; isset($used[mb_strtolower($name)]); $k++) {
        $name = mb_substr($base, 0, 28) . ' ' . $k;
    }
    $used[mb_strtolower($name)] = true;
    return $name;
}

/* ------------------------------------------------------------------ zip (deflate) */

function xlsx_zip(array $files)
{
    $out = $central = '';
    $t = getdate();
    $dosTime = ($t['hours'] << 11) | ($t['minutes'] << 5) | intdiv($t['seconds'], 2);
    $dosDate = (max(0, $t['year'] - 1980) << 9) | ($t['mon'] << 5) | $t['mday'];
    $n = 0;
    foreach ($files as $name => $data) {
        $data = (string)$data;
        $comp = gzdeflate($data, 6);
        $crc = crc32($data);
        $offset = strlen($out);
        $head = pack('vvvvvVVVvv', 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data), strlen($name), 0);
        $out .= "PK\x03\x04" . $head . $name . $comp;
        $central .= "PK\x01\x02" . pack('vvvvvvVVVvvvvvVV', 20, 20, 0x0800, 8, $dosTime, $dosDate, $crc, strlen($comp), strlen($data),
            strlen($name), 0, 0, 0, 0, 0, $offset) . $name;
        $n++;
    }
    return $out . $central . "PK\x05\x06" . pack('vvvvVVv', 0, 0, $n, $n, strlen($central), strlen($out), 0);
}

/* ------------------------------------------------------------------ password (ECMA-376 agile) */

function xlsx_encrypt($xlsx, $password, $spinCount = 100000)
{
    $aes = fn($data, $key, $iv) => openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING, $iv);
    $pad = fn($s, $n, $c = "\0") => strlen($s) >= $n ? substr($s, 0, $n) : $s . str_repeat($c, $n - strlen($s));
    $padBlock = fn($s) => strlen($s) % 16 ? $s . str_repeat("\0", 16 - strlen($s) % 16) : $s;

    // the key that opens the workbook, protected by a key from the password
    $pwSalt = random_bytes(16);
    $h = hash('sha512', $pwSalt . mb_convert_encoding((string)$password, 'UTF-16LE', 'UTF-8'), true);
    for ($i = 0; $i < $spinCount; $i++) {
        $h = hash('sha512', pack('V', $i) . $h, true);
    }
    $derive = fn($block) => substr(hash('sha512', $h . $block, true), 0, 32);
    $verifierInput = random_bytes(16);
    $encVerifierInput = $aes($verifierInput, $derive("\xfe\xa7\xd2\x76\x3b\x4b\x9e\x79"), $pwSalt);
    $encVerifierHash = $aes($padBlock(hash('sha512', $verifierInput, true)), $derive("\xd7\xaa\x0f\x6d\x30\x61\x34\x4e"), $pwSalt);
    $secret = random_bytes(32);
    $encKey = $aes($secret, $derive("\x14\x6e\x0b\xe7\xab\xac\xd0\xd6"), $pwSalt);

    // the workbook, in 4096-byte segments, each with its own IV
    $dataSalt = random_bytes(16);
    $pkg = pack('P', strlen($xlsx));
    foreach (str_split($xlsx, 4096) as $i => $seg) {
        $pkg .= $aes($padBlock($seg), $secret, substr(hash('sha512', $dataSalt . pack('V', $i), true), 0, 16));
    }
    // the stream must be at least 4096 bytes (smaller ones would belong in the mini stream);
    // readers stop at the size written at its start, so zeros at the end are ignored
    $pkg = $pad($pkg, max(8 + 4096, strlen($pkg)));   // 8-byte size + whole AES blocks
    // integrity: HMAC-SHA512 over the encrypted package (the whole stream)
    $hmacKey = random_bytes(64);
    $iv1 = substr(hash('sha512', $dataSalt . "\x5f\xb2\xad\x01\x0c\xb9\xe1\xf6", true), 0, 16);
    $iv2 = substr(hash('sha512', $dataSalt . "\xa0\x67\x7f\x02\xb2\x2c\x84\x33", true), 0, 16);
    $encHmacKey = $aes($hmacKey, $secret, $iv1);
    $encHmacValue = $aes(hash_hmac('sha512', $pkg, $hmacKey, true), $secret, $iv2);

    $b = fn($s) => base64_encode($s);
    $info = pack('vvV', 4, 4, 0x40) . '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . "\r\n"
        . '<encryption xmlns="http://schemas.microsoft.com/office/2006/encryption" xmlns:p="http://schemas.microsoft.com/office/2006/keyEncryptor/password" xmlns:c="http://schemas.microsoft.com/office/2006/keyEncryptor/certificate">'
        . '<keyData saltSize="16" blockSize="16" keyBits="256" hashSize="64" cipherAlgorithm="AES" cipherChaining="ChainingModeCBC" hashAlgorithm="SHA512" saltValue="' . $b($dataSalt) . '"/>'
        . '<dataIntegrity encryptedHmacKey="' . $b($encHmacKey) . '" encryptedHmacValue="' . $b($encHmacValue) . '"/>'
        . '<keyEncryptors><keyEncryptor uri="http://schemas.microsoft.com/office/2006/keyEncryptor/password">'
        . '<p:encryptedKey spinCount="' . $spinCount . '" saltSize="16" blockSize="16" keyBits="256" hashSize="64" cipherAlgorithm="AES" cipherChaining="ChainingModeCBC" hashAlgorithm="SHA512" '
        . 'saltValue="' . $b($pwSalt) . '" encryptedVerifierHashInput="' . $b($encVerifierInput) . '" encryptedVerifierHashValue="' . $b($encVerifierHash) . '" encryptedKeyValue="' . $b($encKey) . '"/>'
        . '</keyEncryptor></keyEncryptors></encryption>';

    // the «data spaces» that tell Office the package is encrypted (fixed content, MS-OFFCRYPTO 2.1)
    $u16 = fn($s) => mb_convert_encoding($s, 'UTF-16LE', 'UTF-8');
    $lstr = fn($s) => pack('V', strlen($u16($s))) . $u16($s) . str_repeat("\0", (4 - strlen($u16($s)) % 4) % 4);
    $version = $lstr('Microsoft.Container.DataSpaces') . pack('vvvvvv', 1, 0, 1, 0, 1, 0);
    $mapEntry = pack('V', 1) . pack('V', 0) . $lstr('EncryptedPackage') . $lstr('StrongEncryptionDataSpace');
    $map = pack('VV', 8, 1) . pack('V', strlen($mapEntry) + 4) . $mapEntry;
    $dataSpace = pack('VV', 8, 1) . $lstr('StrongEncryptionTransform');
    $transformHead = pack('V', 1) . $lstr('{FF9A3F03-56EF-4613-BDD5-5A41C1D07246}');
    $primary = pack('V', strlen($transformHead) + 4) . $transformHead . $lstr('Microsoft.Container.EncryptionTransform')
        . pack('vvvvvv', 1, 0, 1, 0, 1, 0) . pack('V', 0) . pack('V', 0) . pack('V', 0) . pack('V', 4);   // name "", block size, cipher mode, reserved

    return xlsx_cfb([
        ['EncryptionInfo', $info],
        ['EncryptedPackage', $pkg],
        ["\x06DataSpaces", [
            ['Version', $version],
            ['DataSpaceMap', $map],
            ['DataSpaceInfo', [['StrongEncryptionDataSpace', $dataSpace]]],
            ['TransformInfo', [['StrongEncryptionTransform', [["\x06Primary", $primary]]]]],
        ]],
    ]);
}

/* ------------------------------------------------------------------ OLE compound file (v3, 512-byte sectors) */

/**
 * $tree = [[name, bytes|children], ...] under the root storage. Streams under
 * 4096 bytes go into the mini stream (64-byte sectors), as the format requires.
 */
function xlsx_cfb(array $tree)
{
    $END = 0xFFFFFFFE; $FREE = 0xFFFFFFFF; $FATSECT = 0xFFFFFFFD; $NONE = 0xFFFFFFFF;
    // flatten into directory entries
    $dir = [['name' => 'Root Entry', 'type' => 5, 'data' => '', 'kids' => []]];
    $add = function (array $items, $parent) use (&$add, &$dir) {
        foreach ($items as [$name, $content]) {
            $id = count($dir);
            $isDir = is_array($content);
            $dir[] = ['name' => $name, 'type' => $isDir ? 1 : 2, 'data' => $isDir ? '' : $content, 'kids' => []];
            $dir[$parent]['kids'][] = $id;
            if ($isDir) {
                $add($content, $id);
            }
        }
    };
    $add($tree, 0);

    // mini stream: small streams, each padded to 64 bytes
    $mini = '';
    $miniFat = [];
    foreach ($dir as $i => &$e) {
        $e['start'] = $END;
        if ($e['type'] === 2 && strlen($e['data']) < 4096 && strlen($e['data']) > 0) {
            $first = intdiv(strlen($mini), 64);
            $n = intdiv(strlen($e['data']) + 63, 64);
            for ($k = 0; $k < $n; $k++) {
                $miniFat[] = $k === $n - 1 ? $END : $first + $k + 1;
            }
            $mini .= str_pad($e['data'], $n * 64, "\0");
            $e['start'] = $first;
            $e['mini'] = true;
        }
    }
    unset($e);

    $sec = fn($bytes) => intdiv(strlen($bytes) + 511, 512);
    $miniFatBytes = '';
    foreach ($miniFat as $v) {
        $miniFatBytes .= pack('V', $v);
    }
    $nMiniFat = $sec($miniFatBytes);
    $nDir = intdiv(count($dir) + 3, 4);
    $nMini = $sec($mini);
    $big = [];   // [entry index => sectors]
    foreach ($dir as $i => $e) {
        if ($e['type'] === 2 && empty($e['mini']) && strlen($e['data']) > 0) {
            $big[$i] = $sec($e['data']);
        }
    }
    $content = $nMiniFat + $nDir + $nMini + array_sum($big);
    for ($nFat = 1; $nFat * 128 < $nFat + $content; $nFat++) {
    }
    if ($nFat > 109) {
        throw new RuntimeException('فایل اکسل برای قفل‌کردن خیلی بزرگ است');
    }

    // sector map: FAT | miniFAT | directory | mini stream | big streams
    $fat = array_fill(0, $nFat * 128, $FREE);
    for ($k = 0; $k < $nFat; $k++) {
        $fat[$k] = $FATSECT;
    }
    $pos = $nFat;
    $chain = function ($n) use (&$fat, &$pos, $END) {
        if ($n === 0) {
            return $END;
        }
        $first = $pos;
        for ($k = 0; $k < $n; $k++) {
            $fat[$pos + $k] = $k === $n - 1 ? $END : $pos + $k + 1;
        }
        $pos += $n;
        return $first;
    };
    $miniFatStart = $chain($nMiniFat);
    $dirStart = $chain($nDir);
    $miniStart = $chain($nMini);
    $dir[0]['start'] = $nMini ? $miniStart : $END;
    $dir[0]['size'] = strlen($mini);
    foreach ($big as $i => $n) {
        $dir[$i]['start'] = $chain($n);
    }

    // siblings as a binary search tree (Office order: shorter name first, then case-insensitive)
    $cmp = function ($a, $b) use ($dir) {
        $la = mb_strlen($dir[$a]['name']);
        $lb = mb_strlen($dir[$b]['name']);
        return $la !== $lb ? $la - $lb : strcmp(mb_strtoupper($dir[$a]['name']), mb_strtoupper($dir[$b]['name']));
    };
    $left = $right = $child = array_fill(0, count($dir), $NONE);
    $build = function (array $ids) use (&$build, &$left, &$right) {
        if (!$ids) {
            return 0xFFFFFFFF;
        }
        $m = intdiv(count($ids), 2);
        $left[$ids[$m]] = $build(array_slice($ids, 0, $m));
        $right[$ids[$m]] = $build(array_slice($ids, $m + 1));
        return $ids[$m];
    };
    foreach ($dir as $i => $e) {
        if ($e['kids']) {
            $kids = $e['kids'];
            usort($kids, $cmp);
            $child[$i] = $build($kids);
        }
    }

    // write
    $hdr = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1" . str_repeat("\0", 16)
        . pack('vvvvv', 0x3E, 3, 0xFFFE, 9, 6) . str_repeat("\0", 6)
        . pack('VVVVVVVVV', 0, $nFat, $dirStart, 0, 4096, $nMiniFat ? $miniFatStart : $END, $nMiniFat, $END, 0);
    for ($k = 0; $k < 109; $k++) {
        $hdr .= pack('V', $k < $nFat ? $k : $FREE);
    }
    $out = $hdr;
    foreach ($fat as $v) {
        $out .= pack('V', $v);
    }
    $out .= str_pad($miniFatBytes, $nMiniFat * 512, pack('V', $FREE));
    $dirBytes = '';
    foreach ($dir as $i => $e) {
        $name = mb_convert_encoding($e['name'], 'UTF-16LE', 'UTF-8');
        $size = $i === 0 ? $e['size'] : strlen($e['data']);
        $dirBytes .= str_pad($name . "\0\0", 64, "\0") . pack('v', strlen($name) + 2) . pack('CC', $e['type'], 1)
            . pack('VVV', $left[$i], $right[$i], $child[$i]) . str_repeat("\0", 16) . pack('V', 0) . str_repeat("\0", 16)
            . pack('V', $e['type'] === 1 ? 0 : $e['start']) . pack('P', $e['type'] === 1 ? 0 : $size);
    }
    $empty = str_pad('', 64, "\0") . pack('v', 0) . pack('CC', 0, 0) . pack('VVV', $NONE, $NONE, $NONE) . str_repeat("\0", 36) . pack('V', 0) . pack('P', 0);
    while (strlen($dirBytes) < $nDir * 512) {
        $dirBytes .= $empty;
    }
    $out .= $dirBytes . str_pad($mini, $nMini * 512, "\0");
    foreach ($big as $i => $n) {
        $out .= str_pad($dir[$i]['data'], $n * 512, "\0");
    }
    return $out;
}
