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
