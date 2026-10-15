<?php
/** Best-effort local plain-text extraction for approval-date detection. */
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
                    // Upload limits measure compressed bytes; bound the expanded XML as well.
                    $maxXmlBytes = 1500000;
                    try {
                        $entry = $archive->statName('word/document.xml');
                        $xml = $entry !== false && $entry['size'] <= $maxXmlBytes
                            ? $archive->getFromName('word/document.xml', $maxXmlBytes)
                            : false;
                    } finally {
                        $archive->close();
                    }
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
 * Uses local patterns only; uploads never send document text to an AI service.
 * Returns ['date' => 'YYYY-MM-DD' or null, 'source' => 'regex' or null].
 */
if (!function_exists('ai_detect_approval_date')) {
    function ai_detect_approval_date(string $text): array
    {
        if (trim($text) === '') {
            return ['date' => null, 'source' => null];
        }

        $date = regex_detect_approval_date($text);
        return ['date' => $date, 'source' => $date !== null ? 'regex' : null];
    }
}

/** Local detection: looks for common date phrasing near "approv*"/"issued" keywords. */
if (!function_exists('regex_detect_approval_date')) {
    function regex_detect_approval_date(string $text): ?string
    {
        // A date elsewhere in the document is not evidence of approval.
        preg_match_all('/\b(?:date\s+(?:of\s+)?approval|date\s+approved|approval\s+date|approval(?=\s*:)|approved|date\s+of\s+issuance|issuance\s+date|issued)\b/i',
            $text, $anchors, PREG_OFFSET_CAPTURE);
        $months = 'January|February|March|April|May|June|July|August|September|October|November|December';
        foreach ($anchors[0] as [$anchor, $offset]) {
            $prefix = substr($text, max(0, $offset - 40), min(40, $offset));
            if (preg_match('/\b(?:not|never|no|awaiting|pending|without)\b[^.;!]*$|\b(?:to|will|may|could|should|would|can)\s+be\s*$/i', $prefix)) {
                continue;
            }
            // Only inspect the short clause following an approval/issuance label.
            $context = preg_split('/[.;!]/', substr($text, $offset + strlen($anchor), 120), 2)[0];
            // A later submission/expiry date in the clause is not the approval date.
            $context = preg_replace('/^\s*[:\-]?\s*(?:(?:on|this|the|as\s+of|dated)\s+)*/i', '', $context);
            $year = $month = $day = null;
            if (preg_match('/^(20\d{2})-(\d{2})-(\d{2})\b/', $context, $m)) {
                [$year, $month, $day] = [(int)$m[1], (int)$m[2], (int)$m[3]];
            } elseif (preg_match('/^(\d{1,2})(?:st|nd|rd|th)?\s+(?:day\s+of\s+)?(' . $months . ')\s+(\d{4})\b/i', $context, $m)) {
                [$year, $month, $day] = [(int)$m[3], (int)date('n', strtotime($m[2] . ' 1, 2000')), (int)$m[1]];
            } elseif (preg_match('/^(' . $months . ')\s+(\d{1,2}),?\s+(\d{4})\b/i', $context, $m)) {
                [$year, $month, $day] = [(int)$m[3], (int)date('n', strtotime($m[1] . ' 1, 2000')), (int)$m[2]];
            }
            if ($year !== null && checkdate($month, $day, $year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
        return null;
    }
}
