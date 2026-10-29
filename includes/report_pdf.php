<?php
// --- minimal, dependency-free PDF writer (no external library required) ---
// Text is encoded once as Windows-1252 for the standard fonts with /WinAnsiEncoding (B7-F10). That covers
// Filipino/Spanish diacritics, dashes, curly quotes and the euro sign. Characters the standard fonts cannot
// show become '?', and every PDF that needed a substitution says so on its first page; nothing is silently dropped.

/** Unicode code points 0x80-0x9F that Windows-1252 maps to printable glyphs. */
const REPORT_PDF_CP1252 = [0x20AC=>0x80,0x201A=>0x82,0x0192=>0x83,0x201E=>0x84,0x2026=>0x85,0x2020=>0x86,0x2021=>0x87,0x02C6=>0x88,
    0x2030=>0x89,0x0160=>0x8A,0x2039=>0x8B,0x0152=>0x8C,0x017D=>0x8E,0x2018=>0x91,0x2019=>0x92,0x201C=>0x93,0x201D=>0x94,
    0x2022=>0x95,0x2013=>0x96,0x2014=>0x97,0x02DC=>0x98,0x2122=>0x99,0x0161=>0x9A,0x203A=>0x9B,0x0153=>0x9C,0x017E=>0x9E,0x0178=>0x9F];

/** Count of characters replaced by '?' since the current make_pdf() started. */
function report_pdf_substitutions(?int $set = null): int
{
    static $count = 0;
    if ($set !== null) $count = $set;
    return $count;
}

/** UTF-8 text to the PDF's Windows-1252 bytes. Accepts already-encoded bytes (idempotent for ASCII and CP1252 output). */
function report_pdf_encode(string $text): string
{
    if (!mb_check_encoding($text, 'UTF-8')) return preg_replace('/[\x00-\x1F\x7F\x81\x8D\x8F\x90\x9D]/', ' ', $text);
    $out = '';
    foreach (mb_str_split($text, 1, 'UTF-8') as $char) {
        $code = mb_ord($char, 'UTF-8');
        if ($code >= 0x20 && $code <= 0x7E) $out .= $char;
        elseif ($code === 0x09 || $code === 0x0A || $code === 0x0D || $code === 0xA0) $out .= ' ';
        elseif ($code < 0x20 || $code === 0x7F || ($code >= 0x80 && $code <= 0x9F)) continue; // Control characters are never text.
        elseif ($code <= 0xFF) $out .= chr($code);
        elseif (isset(REPORT_PDF_CP1252[$code])) $out .= chr(REPORT_PDF_CP1252[$code]);
        elseif (($code >= 0x200B && $code <= 0x200F) || $code === 0xFEFF || ($code >= 0xFE00 && $code <= 0xFE0F)) continue; // Invisible formatting marks.
        else {
            // Latin letters outside Windows-1252 keep their base letter (for example Polish or Vietnamese diacritics).
            $base = class_exists('Normalizer') ? preg_replace('/\p{Mn}+/u', '', (string)Normalizer::normalize($char, Normalizer::FORM_D)) : '';
            if ($base !== '' && strlen($base) === 1 && ord($base) >= 0x20 && ord($base) <= 0x7E) $out .= $base;
            else { $out .= '?'; report_pdf_substitutions(report_pdf_substitutions() + 1); }
        }
    }
    return $out;
}

/** Escapes already-encoded PDF text (output of report_pdf_plain/report_pdf_lines or ASCII literals). */
function pdf_escape($s)
{
    $s = preg_replace('/[\x00-\x1F\x7F]/', ' ', (string)$s);
    return str_replace(['\\', '(', ')'], ['\\\\', '\(', '\)'], $s);
}
function wrap_lines($text, $width = 88)
{
    $out = [];
    foreach (preg_split('/\R/', (string)$text) as $line) {
        $wrapped = wordwrap(trim($line), $width, "\n", true);
        foreach (explode("\n", $wrapped) as $part) {
            $out[] = $part;
        }
    }
    return $out;
}
/** Text as the PDF will show it (Windows-1252 bytes); all PDF strings are escaped separately. */
function report_pdf_plain(string $text): string
{
    return report_pdf_encode($text);
}

/** Standard Helvetica advance widths (1/1000 em) for WinAnsiEncoding bytes 0x20-0xFF; no runtime font dependency. */
function report_pdf_width(string $text, float $size = 9, bool $bold = false): float
{
    static $regular = [278,278,355,556,556,889,667,191,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,278,278,584,584,584,556,1015,667,667,722,722,667,611,778,722,278,500,667,556,833,722,778,667,778,722,667,611,722,667,944,667,667,611,278,278,278,469,556,333,556,556,500,556,556,278,556,556,222,222,500,222,833,556,556,556,556,333,500,278,556,500,722,500,500,500,334,260,334,584];
    static $strong = [278,333,474,556,556,889,722,238,333,333,389,584,278,333,278,278,556,556,556,556,556,556,556,556,556,556,333,333,584,584,584,611,975,722,722,722,722,667,611,778,722,278,556,722,611,833,722,778,667,778,722,667,611,722,667,944,667,667,611,333,278,333,584,556,333,556,611,556,611,556,333,611,611,278,278,556,278,889,611,611,611,611,389,556,333,611,556,778,556,556,500,389,280,389,584];
    // 0x80-0xFF from the Helvetica/Helvetica-Bold AFM files (unassigned CP1252 slots are never emitted).
    static $regularHigh = [556,0,222,556,333,1000,556,556,333,1000,667,333,1000,0,611,0,0,222,222,333,333,350,556,1000,333,1000,500,333,944,0,500,667,
        278,333,556,556,556,556,260,556,333,737,370,556,584,333,737,333,400,584,333,333,333,556,537,278,333,333,365,556,834,834,834,611,
        667,667,667,667,667,667,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,
        556,556,556,556,556,556,889,500,556,556,556,556,278,278,278,278,556,556,556,556,556,556,556,584,611,556,556,556,556,500,556,500];
    static $strongHigh = [556,0,278,556,500,1000,556,556,333,1000,667,333,1000,0,611,0,0,278,278,500,500,350,556,1000,333,1000,556,333,944,0,500,667,
        278,333,556,556,556,556,280,556,333,737,370,556,584,333,737,333,400,584,333,333,333,611,556,278,333,333,365,556,834,834,834,611,
        722,722,722,722,722,722,1000,722,667,667,667,667,278,278,278,278,722,722,778,778,778,778,778,584,778,722,722,722,722,667,667,611,
        556,556,556,556,556,556,889,556,556,556,556,556,278,278,278,278,611,611,611,611,611,611,611,584,611,611,611,611,611,556,611,556];
    $low = $bold ? $strong : $regular; $high = $bold ? $strongHigh : $regularHigh; $width = 0;
    foreach (str_split($text) as $char) { $o = ord($char); $width += $o >= 0x80 ? $high[$o-0x80] : ($low[$o-32] ?? 0); }
    return $width * $size / 1000;
}

/** Wrap using the selected font's actual advances, retaining inline bold and cell newlines. */
function report_pdf_lines(string $text, float $width, bool $markdown = false, float $size = 9, bool $heading = false): array
{
    $lines = [[]]; $used = 0; $bold = false;
    foreach (preg_split('/(\*\*|\r\n|\r|\n)/', $text, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
        if (in_array($part, ["\r\n","\r","\n"], true)) { $lines[] = []; $used = 0; continue; }
        if ($markdown && $part === '**') { $bold = !$bold; continue; }
        foreach (preg_split('/(\s+)/', report_pdf_plain($part), -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) as $word) {
            if (trim($word) === '') $word = ' ';
            if ($used && $used + report_pdf_width($word, $size, $heading || $bold) > $width) { $lines[] = []; $used = 0; }
            if (!$used && $word === ' ') continue;
            while ($word !== '') {
                $length = 0; $advance = 0;
                while ($length < strlen($word)) {
                    $next = report_pdf_width($word[$length], $size, $heading || $bold);
                    if ($length && $used + $advance + $next > $width) break;
                    $advance += $next; $length++;
                }
                $piece = substr($word, 0, $length);
                $lines[count($lines)-1][] = [$piece, $bold];
                $used += $advance; $word = substr($word, $length);
                if ($word !== '') { $lines[] = []; $used = 0; }
            }
        }
    }
    return $lines;
}

/** Suppress complete Markdown tables; retain ordinary prose and non-table pipe characters. */
function strip_report_markdown_tables(string $narrative): string
{
    $lines = preg_split('/\R/', $narrative); $clean = [];
    $separator = static function (string $line): bool {
        $line = trim($line);
        if (!str_contains($line, '|')) return false;
        $cells = explode('|', trim($line, " |\t"));
        foreach ($cells as $cell) if (!preg_match('/^:?-{3,}:?$/D', trim($cell))) return false;
        return count($cells) > 0;
    };
    for ($i=0, $count=count($lines); $i<$count; $i++) {
        if ($i+1 < $count && str_contains($lines[$i], '|') && $separator($lines[$i+1])) {
            $i += 2;
            while ($i<$count && trim($lines[$i]) !== '' && str_contains($lines[$i], '|')) $i++;
            $i--; continue;
        }
        $clean[] = $lines[$i];
    }
    return trim(implode("\n", $clean));
}

/** Strings remain supported for existing report callers; table blocks are server-built facts. */
function make_pdf($title, $lines)
{
    $pages = []; $stream = ''; $y = 742; report_pdf_substitutions(0);
    $draw = function (array $runs, float $x, float $baseline, float $size = 9, bool $bold = false) use (&$stream): void {
        foreach ($runs as [$text, $strong]) {
            $font = ($bold || $strong) ? 'F2' : 'F1';
            $stream .= "BT /$font $size Tf 1 0 0 1 $x $baseline Tm (" . pdf_escape($text) . ") Tj ET\n";
            $x += report_pdf_width($text, $size, $bold || $strong);
        }
    };
    $newPage = function () use (&$pages, &$stream, &$y, $draw, $title): void {
        if ($stream !== '') $pages[] = $stream;
        $stream = ''; $y = 750;
        foreach (report_pdf_lines((string)$title, 512, false, 13, true) as $line) { $draw($line, 50, $y, 13, true); $y -= 17; }
        $draw([['PRISM - IERB Progress & Reporting System', false]], 50, $y, 8); $y -= 13;
        $draw([['Generated: ' . date('Y-m-d H:i'), false]], 50, $y, 8); $y -= 22;
    };
    $newPage();
    $pageTop = $y;
    foreach ($lines as $blockIndex => $block) {
        if (is_array($block)) {
            $headers = $block['headers']; $widths = $block['widths'];
            $rowLines = function (array $cells, bool $bold = false) use ($widths): array {
                $out = [];
                foreach ($widths as $i => $width) $out[] = report_pdf_lines((string)($cells[$i] ?? ''), $width - 12, false, 9, $bold);
                return $out;
            };
            $paint = function (array $cells, int $offset, int $count, bool $header) use (&$stream, &$y, $widths, $draw): void {
                $height = $count * 12 + 10; $x = 50;
                foreach ($widths as $i => $width) {
                    if ($header) $stream .= "q 0.92 g $x " . ($y-$height) . " $width $height re f Q\n";
                    $stream .= "q 0.7 G 0.5 w $x " . ($y-$height) . " $width $height re S Q\n";
                    for ($j=0; $j<$count; $j++) $draw($cells[$i][$offset+$j] ?? [], $x+6, $y-14-$j*12, 9, $header);
                    $x += $width;
                }
                $y -= $height;
            };
            $head = $rowLines($headers, true); $headCount = max(array_map('count', $head));
            $headHeight = $headCount*12+10;
            if ($y < 50+$headHeight+34) $newPage();
            $paint($head, 0, $headCount, true);
            foreach ($block['rows'] as $row) {
                $cells = $rowLines($row); $count = max(array_map('count', $cells)); $offset = 0;
                $rowHeight = $count*12 + 10;
                if ($rowHeight > $y-50 && $rowHeight <= $pageTop-50-$headHeight) {
                    $newPage(); $paint($head, 0, $headCount, true);
                }
                while ($offset < $count) {
                    $available = (int)floor(($y-50-10)/12);
                    if ($available < 1) { $newPage(); $paint($head, 0, $headCount, true); continue; }
                    $take = min($available, $count-$offset);
                    $paint($cells, $offset, $take, false); $offset += $take;
                }
            }
            $y -= 14;
            continue;
        }
        foreach (preg_split('/\R/', (string)$block) as $paragraph) {
            $heading = preg_match('/^#{1,6}\s+(.+)$/', $paragraph, $match);
            $text = $heading ? $match[1] : $paragraph;
            $size = $heading ? 11 : 9; $step = $heading ? 17 : 13;
            $wrapped = report_pdf_lines($text, 512, true, $size, (bool)$heading);
            $reserve = 26;
            $next = $lines[$blockIndex + 1] ?? null;
            if ($heading && is_array($next)) {
                $headerLines = 1;
                foreach ($next['headers'] as $i => $header) {
                    $headerLines = max($headerLines, count(report_pdf_lines((string)$header,
                        $next['widths'][$i]-12, false, 9, true)));
                }
                $reserve = 5 + $headerLines*12 + 10 + 34;
            }
            if ($heading && $y < 50 + count($wrapped)*$step + $reserve) $newPage();
            foreach ($wrapped as $line) {
                if ($y < 64) $newPage();
                $draw($line, 50, $y, $size, (bool)$heading); $y -= $step;
            }
            $y -= 5;
        }
    }
    $pages[] = $stream;
    if (report_pdf_substitutions() > 0) {
        // Disclose characters outside the standard PDF fonts instead of dropping them silently.
        $pages[0] .= "BT /F1 7 Tf 1 0 0 1 50 40 Tm (" . pdf_escape('Some characters outside the PDF font are shown as ?; see the PRISM record for the exact text.') . ") Tj ET\n";
    }
    $objects = [1 => '<< /Type /Catalog /Pages 2 0 R >>',
        3 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
        4 => '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>'];
    $kids = []; $id = 5;
    foreach ($pages as $index => $content) {
        $number = ($index+1) . ' / ' . count($pages);
        $content .= "BT /F1 8 Tf 1 0 0 1 50 30 Tm (Page $number) Tj ET\n";
        $pageId = $id++; $contentId = $id++; $kids[] = "$pageId 0 R";
        $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents $contentId 0 R >>";
        $objects[$contentId] = '<< /Length ' . strlen($content) . ">>\nstream\n$content\nendstream";
    }
    $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';
    ksort($objects); $pdf = "%PDF-1.4\n"; $offsets = [0];
    foreach ($objects as $num => $object) { $offsets[$num] = strlen($pdf); $pdf .= "$num 0 obj\n$object\nendobj\n"; }
    $xref = strlen($pdf); $max = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($max+1) . "\n0000000000 65535 f \n";
    for ($n=1; $n<=$max; $n++) $pdf .= sprintf('%010d 00000 n ', $offsets[$n]) . "\n";
    return $pdf . "trailer\n<< /Size " . ($max+1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
