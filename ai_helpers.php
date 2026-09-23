<?php
/** Best-effort plain-text extraction used before sending content to OpenRouter (or the local fallback). */
if (!function_exists('extract_document_text')) {
    function extract_document_text(string $path): string
    {
        if (!is_file($path)) {
            return '';
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $text = '';
        if (in_array($ext, ['txt', 'rtf'], true)) {
            $text = file_get_contents($path, false, null, 0, 300000);
        } elseif ($ext === 'docx' && class_exists('ZipArchive')) {
            try {
                $archive = new ZipArchive();
                if ($archive->open($path) === true) {
                    $xml = $archive->getFromName('word/document.xml');
                    $archive->close();
                    if ($xml !== false) {
                        $text = strip_tags(str_replace(['</w:p>', '</w:tab>'], [".\n", ' '], $xml));
                    }
                }
            } catch (Throwable $e) {
                $text = '';
            }
        } elseif ($ext === 'pdf') {
            $raw = file_get_contents($path, false, null, 0, 1500000);
            if (preg_match_all('/\(([^()]*)\)\s*Tj/', $raw, $matches)) {
                $text = implode(' ', $matches[1]);
            }
        }
        $text = preg_replace('/^\x{FEFF}/u', '', (string)$text);
        return trim(preg_replace('/\s+/u', ' ', (string)$text));
    }
}

/**
 * Detects the actual IERB approval date printed on a document's own text
 * (e.g. an approval certificate reading "...approved this 14th day of
 * March 2026...") rather than relying on when the student happened to
 * upload the file, which can lag behind by days or weeks.
 *
 * Tries the AI first (OpenRouter, same predefined-query approach as
 * summarization); falls back to a handful of regex date patterns if AI
 * is unavailable or returns nothing usable. Returns ['date' => 'YYYY-MM-DD'
 * or null, 'source' => 'ai'|'regex'|null].
 */
if (!function_exists('ai_detect_approval_date')) {
    function ai_detect_approval_date(string $text): array
    {
        if (trim($text) === '') {
            return ['date' => null, 'source' => null];
        }

        if (openrouter_available()) {
            $raw = openrouter_generate(
                'You extract dates from university ethics-review documents for a Research Planning '
                . 'and Monitoring Section. Find the actual approval/issuance date printed in the '
                . 'document text (e.g. near words like "approved", "date approved", "issued on"). '
                . 'Respond with ONLY the date in YYYY-MM-DD format, and nothing else. If no such '
                . 'date is clearly present in the text, respond with exactly: NONE',
                substr($text, 0, 6000)
            );
            if ($raw !== null) {
                $raw = trim($raw);
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw) && strtotime($raw) !== false) {
                    return ['date' => $raw, 'source' => 'ai'];
                }
                // AI said NONE or returned something unparseable -- fall through to regex,
                // rather than treating an unusable reply as "no date found" outright.
            }
        }

        return ['date' => regex_detect_approval_date($text), 'source' => $text !== '' ? 'regex' : null];
    }
}

/** Local fallback: looks for common date phrasing near "approv*"/"issued" keywords. */
if (!function_exists('regex_detect_approval_date')) {
    function regex_detect_approval_date(string $text): ?string
    {
        // Numeric formats: 2026-03-14, 03/14/2026, 14/03/2026
        if (preg_match('/\b(20\d{2})-(\d{2})-(\d{2})\b/', $text, $m)) {
            $candidate = "{$m[1]}-{$m[2]}-{$m[3]}";
            if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
                return $candidate;
            }
        }
        // Written formats: "14th day of March 2026", "March 14, 2026", "14 March 2026"
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';
        if (preg_match('/\b(\d{1,2})(?:st|nd|rd|th)?\s+day\s+of\s+(' . $months . ')\s+(\d{4})\b/i', $text, $m)) {
            $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        if (preg_match('/\b(' . $months . ')\s+(\d{1,2}),?\s+(\d{4})\b/i', $text, $m)) {
            $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        if (preg_match('/\b(\d{1,2})\s+(' . $months . ')\s+(\d{4})\b/i', $text, $m)) {
            $ts = strtotime("{$m[1]} {$m[2]} {$m[3]}");
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return null;
    }
}
