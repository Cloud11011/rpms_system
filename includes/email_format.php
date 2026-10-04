<?php
/** Format plain notification text; stored HTML is always treated as text. */
function notification_email_html(string $body): string
{
    $escape = fn($value) => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $paragraphs = [];
    foreach (preg_split('/\r?\n\s*\r?\n/', trim($body)) as $paragraph) {
        $lines = [];
        foreach (preg_split('/\r\n|\r|\n/', $paragraph) as $line) {
            if (preg_match('/^(Hello|Dear) (.+),$/u', $line, $match)) {
                $lines[] = $escape($match[1]) . ' <strong>' . $escape($match[2]) . '</strong>,';
            } elseif (preg_match('/^((?:Student|Recipient|Research title|Review status|Review result|IERB stage|Current stage|Stage|Status|Protocol code|Research group|Pending requirements?|Requirements?|Scheduled(?: for)?|Formal-submission reference|Submitted|Submission(?: reference| number)?|Reference(?: number)?|Document|Filename):)\s*(.+)$/iu', $line, $match)) {
                // Keep explanatory sentences after a status value in normal weight.
                $value = $match[2]; $detail = '';
                if (preg_match('/^(?:Review status|Review result|Status):$/iu', $match[1]) && strpos($value, '. ') !== false) {
                    [$value, $detail] = explode('. ', $value, 2); $detail = '. ' . $detail;
                }
                $lines[] = $escape($match[1]) . ' <strong>' . $escape($value) . '</strong>' . $escape($detail);
            } elseif (preg_match('/^(.*?document\s+["\x{201c}])(.+?)(["\x{201d}].*)$/iu', $line, $match)) {
                $lines[] = $escape($match[1]) . '<strong>' . $escape($match[2]) . '</strong>' . $escape($match[3]);
            } elseif (preg_match('/^(.*?["\x{201c}])(.+?\.(?:pdf|docx?|txt|rtf|odt|png|jpe?g))(["\x{201d}].*)$/iu', $line, $match)) {
                $lines[] = $escape($match[1]) . '<strong>' . $escape($match[2]) . '</strong>' . $escape($match[3]);
            } else {
                $lines[] = $escape($line);
            }
        }
        $paragraphs[] = '<p>' . implode('<br>', $lines) . '</p>';
    }
    return '<!doctype html><html><body>' . implode("\n", $paragraphs) . '</body></html>';
}

/** Both live transports use the same multipart body; the log keeps the original text. */
function notification_email_mime(string $body): array
{
    $boundary = 'prism_' . bin2hex(random_bytes(16));
    $parts = [];
    foreach (['text/plain' => $body, 'text/html' => notification_email_html($body)] as $type => $content) {
        $parts[] = '--' . $boundary . "\r\nContent-Type: $type; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($content), 76, "\r\n");
    }
    return ['contentType' => 'multipart/alternative; boundary="' . $boundary . '"',
        'body' => implode("\r\n", $parts) . "\r\n--$boundary--\r\n"];
}
