<?php
/** Document input gate and task-specific validation around the shared bounded transport. */
function document_summary_request(string $excerpt, array $configuration): array
{
    if (!$configuration['enabled'] || empty($configuration['models']) || mb_strlen($excerpt) > DOCUMENT_SUMMARY_MAX_INPUT_CHARS
        || !mb_check_encoding($excerpt, 'UTF-8') || trim($excerpt) === '') {
        throw new DocumentSummaryError('request_invalid', 'The external summary request is not permitted.');
    }
    return ['models' => $configuration['models'], 'messages' => [
        ['role' => 'system', 'content' => DOCUMENT_SUMMARY_PROMPT],
        ['role' => 'user', 'content' => $excerpt],
    ], 'temperature' => 0.2, 'max_tokens' => 1800, 'stream' => false];
}

function document_summary_decode_response(int $status, string $body): array
{
    $result = openrouter_task_decode($status, $body, [], true);
    if (isset($result['text'])) { $result['summary'] = $result['text']; unset($result['text']); }
    return $result;
}

function openrouter_document_summary(string $excerpt, array $configuration, array $identifiers = [], bool $redactIdentifiers = true): array
{
    $request = document_summary_request($excerpt, $configuration);
    $result = openrouter_task_generate('document_summary', $request['messages'],
        static function (string $text) use ($identifiers, $redactIdentifiers) {
            // A privacy failure is terminal; another model must not be used to bypass it.
            if (summary_untrusted_instructions($text)) return ['reason' => 'policy_output'];
            if (summary_minimized_text($text, $identifiers, $redactIdentifiers) !== trim($text)) return ['reason' => 'privacy_output'];
            return validate_document_ai_summary($text, $identifiers, $redactIdentifiers);
        }, $request['models'], $request['max_tokens']);
    if (isset($result['text'])) { $result['summary'] = $result['text']; unset($result['text']); }
    return $result;
}

/** Reject disclosure, instructions, markup and approval assertions; never repair a suspicious completion into success. */
function validate_document_ai_summary(string $text, array $identifiers, bool $redactIdentifiers = true): ?string
{
    // Explicit absence/disclaimer statements are useful; affirmative approval claims remain prohibited.
    $approvalCheck = preg_replace('/\b(?:approval|clearance|certification)\s+(?:status\s+)?(?:is|was|remains)\s+(?:unclear|unknown|unverified|absent|not\s+(?:stated|established|documented|confirmed|provided))\b|\b(?:does\s+not|cannot)\s+(?:establish|imply|confirm|grant|prove)\s+(?:(?:official|institutional|IERB|ethical?)\s+)*(?:approval|clearance|certification)\b/iu', '', $text);
    if (strlen($text) > 6000 || mb_strlen($text) < 60 || !mb_check_encoding($text, 'UTF-8')
        || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]|<\/?[a-z][^>]*>|```|!?\[[^\]]*\]\(|https?:\/\//iu', $text)
        || summary_untrusted_instructions($text)
        || summary_minimized_text($text, $identifiers, $redactIdentifiers) !== trim($text)
        || preg_match('/\b(?:has|have|was|were|is|are)\s+(?:(?:officially|fully|ethically|institutionally)\s+)*(?:approved|certified|compliant)\b|\b(?:received|obtained|granted|secured)\s+(?:(?:official|ethical|IERB|institutional)\s+)*(?:approval|clearance|certification)\b|\b(?:approval|clearance|certification)\s+(?:was|is|has been)\s+(?:granted|obtained|confirmed)\b/iu', $approvalCheck)) return null;
    $words = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY);
    if (count($words) > 320) return null;
    return trim($text);
}
