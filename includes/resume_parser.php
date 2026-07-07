<?php
// ═════════════════════════════════════════════════════════════════════════════
//  SmartHire v7 — includes/resume_parser.php
//  Extract plain text from a resume file (PDF / DOCX / DOC / TXT) in pure PHP,
//  so the ATS engine scores real documents instead of binary garbage (fixes B5).
//
//  Public API:
//    extract_resume_text(string $absPath, ?string $ext = null): array
//      → ['text' => string, 'words' => int, 'method' => 'pdf|docx|doc|txt|none']
// ═════════════════════════════════════════════════════════════════════════════

function extract_resume_text(string $absPath, ?string $ext = null): array {
    if (!is_file($absPath)) return ['text' => '', 'words' => 0, 'method' => 'none'];
    $ext = strtolower($ext ?: pathinfo($absPath, PATHINFO_EXTENSION));

    // ── Transparent parse cache ──────────────────────────────────────────────
    // Report pages re-read the same resume on every view; PDF/DOCX extraction is
    // the hot spot. Key = file identity (path+mtime+size), so a re-uploaded or
    // edited file self-invalidates. Cache lives outside the web root (sys temp);
    // any cache failure silently falls through to a normal parse.
    $ck = null;
    if (in_array($ext, ['pdf', 'docx', 'doc'], true)) {
        $st = @stat($absPath);
        if ($st) {
            $dir = sys_get_temp_dir() . '/sh_parse_cache';
            if (is_dir($dir) || @mkdir($dir, 0700, true)) {
                $ck = $dir . '/' . sha1($absPath . '|' . $st['mtime'] . '|' . $st['size']) . '.json';
                if (is_file($ck)) {
                    $hit = json_decode((string)@file_get_contents($ck), true);
                    if (is_array($hit) && isset($hit['text'], $hit['words'], $hit['method'])) return $hit;
                }
            }
        }
    }

    $text = match ($ext) {
        'txt'  => sh_read_txt($absPath),
        'docx' => sh_extract_docx($absPath),
        'pdf'  => sh_extract_pdf($absPath),
        'doc'  => sh_extract_doc($absPath),
        default => sh_sniff_and_extract($absPath),
    };

    $text = sh_normalise_text($text);
    $out = ['text' => $text, 'words' => str_word_count($text), 'method' => $ext ?: 'none'];
    if ($ck !== null && $text !== '') @file_put_contents($ck, json_encode($out), LOCK_EX);
    return $out;
}

// ── TXT ──────────────────────────────────────────────────────────────────────
function sh_read_txt(string $path): string {
    $raw = (string)@file_get_contents($path);
    if ($raw !== '' && !mb_check_encoding($raw, 'UTF-8')) {
        $raw = mb_convert_encoding($raw, 'UTF-8', 'UTF-8, ISO-8859-1, Windows-1252');
    }
    return $raw;
}

// ── DOCX  (Office Open XML = a zip; text lives in word/document.xml) ──────────
function sh_extract_docx(string $path): string {
    if (!class_exists('ZipArchive')) return '';
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) return '';
    $out = '';
    // main document + headers/footers
    $parts = ['word/document.xml'];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        if (preg_match('#^word/(header|footer)\d*\.xml$#', $name)) $parts[] = $name;
    }
    foreach (array_unique($parts) as $part) {
        $xml = $zip->getFromName($part);
        if ($xml === false) continue;
        // paragraph + break boundaries become spaces/newlines before tag stripping
        $xml = preg_replace('#</w:p>#', "\n", $xml);
        $xml = preg_replace('#<w:(br|tab)\b[^>]*/?>#', ' ', $xml);
        $out .= strip_tags($xml) . "\n";
    }
    $zip->close();
    return html_entity_decode($out, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

// ── DOC  (legacy binary Word) — best effort: pull printable runs ─────────────
function sh_extract_doc(string $path): string {
    $raw = (string)@file_get_contents($path);
    if ($raw === '') return '';
    // Word binary stores text as UTF-16LE runs; grab ASCII-ish sequences.
    $raw = @iconv('UTF-16LE', 'UTF-8//IGNORE', $raw) ?: $raw;
    if (preg_match_all('/[\x20-\x7E\p{L}\p{N}][\x20-\x7E\p{L}\p{N} .,;:@\/\-()]{3,}/u', $raw, $m)) {
        return implode(' ', $m[0]);
    }
    return preg_replace('/[^\x20-\x7E\n]+/', ' ', $raw);
}

// ── PDF  (text-based): decode Flate streams, read text-showing operators ─────
function sh_extract_pdf(string $path): string {
    $data = (string)@file_get_contents($path);
    if ($data === '') return '';

    $text = '';
    // 1) Pull every stream...endstream block; FlateDecode-inflate what we can.
    if (preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $data, $streams)) {
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) $decoded = @gzinflate($stream);      // raw deflate
            if ($decoded === false) $decoded = @gzuncompress(substr($stream, 2)); // skip zlib hdr
            if ($decoded !== false && $decoded !== '') {
                $text .= sh_pdf_ops_to_text($decoded) . "\n";
            }
        }
    }
    // 2) Fallback: some PDFs keep uncompressed text operators in the body.
    if (trim($text) === '') {
        $text = sh_pdf_ops_to_text($data);
    }
    return $text;
}

/** Extract readable text from a PDF content stream's Tj / TJ / ' / " operators. */
function sh_pdf_ops_to_text(string $content): string {
    $out = '';
    // (string) Tj   and   ' / " show-text operators
    if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)\s*(?:Tj|\'|")/s', $content, $m)) {
        foreach ($m[0] as $frag) {
            if (preg_match('/\((.*)\)\s*(?:Tj|\'|")\s*$/s', $frag, $mm)) {
                $out .= sh_pdf_unescape($mm[1]) . ' ';
            }
        }
    }
    // [ (a) -250 (b) ] TJ   arrays
    if (preg_match_all('/\[(.*?)\]\s*TJ/s', $content, $arr)) {
        foreach ($arr[1] as $chunk) {
            if (preg_match_all('/\((?:\\\\.|[^()\\\\])*\)/s', $chunk, $ps)) {
                foreach ($ps[0] as $p) {
                    $out .= sh_pdf_unescape(substr($p, 1, -1));
                }
                $out .= ' ';
            }
        }
    }
    return $out;
}

/** Unescape PDF string literals: \n \t \( \) \\ and \ddd octal. */
function sh_pdf_unescape(string $s): string {
    $s = preg_replace_callback('/\\\\([nrtbf()\\\\]|[0-7]{1,3})/', function ($m) {
        return match ($m[1]) {
            'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
            '(' => '(',  ')' => ')',  '\\' => '\\',
            default => chr(octdec($m[1])),
        };
    }, $s);
    return $s;
}

// ── When extension is unknown, sniff the magic bytes ─────────────────────────
function sh_sniff_and_extract(string $path): string {
    $head = (string)@file_get_contents($path, false, null, 0, 8);
    if (str_starts_with($head, '%PDF'))              return sh_extract_pdf($path);   // %PDF
    if (str_starts_with($head, "PK\x03\x04"))        return sh_extract_docx($path);  // zip → docx
    if (str_starts_with($head, "\xD0\xCF\x11\xE0"))  return sh_extract_doc($path);   // OLE → doc
    return sh_read_txt($path);
}

// ── Normalise: collapse whitespace, drop control chars, cap length ───────────
function sh_normalise_text(string $t): string {
    if ($t === '') return '';
    if (!mb_check_encoding($t, 'UTF-8')) $t = mb_convert_encoding($t, 'UTF-8', 'UTF-8');
    $t = preg_replace('/[^\P{C}\n\t]+/u', '', $t);   // strip control chars except \n \t
    $t = preg_replace('/[ \t]+/', ' ', $t);
    $t = preg_replace('/\n{3,}/', "\n\n", $t);
    $t = trim($t);
    return mb_substr($t, 0, 20000);                   // sane cap for storage/scoring
}
