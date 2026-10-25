<?php
/** Dedicated document transport; shared account routing and privacy settings apply. */
function document_summary_request(string $excerpt, array $configuration): array
{
    if (!$configuration['enabled'] || mb_strlen($excerpt) > DOCUMENT_SUMMARY_MAX_INPUT_CHARS
        || !mb_check_encoding($excerpt, 'UTF-8') || trim($excerpt) === '') {
        throw new DocumentSummaryError('request_invalid', 'The external summary request is not permitted.');
    }
    // No per-document provider overrides or weaker retry route; account policies remain in force.
    return ['model' => $configuration['model'], 'messages' => [
        ['role' => 'system', 'content' => DOCUMENT_SUMMARY_PROMPT],
        ['role' => 'user', 'content' => $excerpt],
    ], 'temperature' => 0.2, 'max_tokens' => 800, 'stream' => false];
}

function document_summary_decode_response(int $status, string $body): array
{
    if ($status === 401) return ['reason' => 'service_auth'];
    if ($status === 402) return ['reason' => 'service_credits'];
    if ($status === 403) return ['reason' => 'service_forbidden'];
    if ($status === 408 || $status === 504) return ['reason' => 'timeout'];
    if ($status === 429) return ['reason' => 'rate_limited'];
    if ($status === 404) return ['reason' => 'routing_unavailable'];
    if (in_array($status, [502, 503], true)) return ['reason' => 'provider_unavailable'];
    if ($status !== 200) return ['reason' => 'service_unavailable'];
    if (strlen($body) > 32768 || !mb_check_encoding($body, 'UTF-8')) return ['reason' => 'invalid_response'];
    try { $value = json_decode($body, true, 32, JSON_THROW_ON_ERROR); }
    catch (Throwable $error) { return ['reason' => 'invalid_response']; }
    $choice = $value['choices'][0] ?? null;
    $text = $choice['message']['content'] ?? null;
    if (isset($value['error']) || !empty($choice['message']['tool_calls']) || !empty($choice['message']['function_call'])
        || !empty($choice['message']['refusal']) || !is_string($text) || trim($text) === '' || strlen($text) > 6000
        || ($choice['finish_reason'] ?? '') !== 'stop' || !mb_check_encoding($text, 'UTF-8')) return ['reason' => 'invalid_response'];
    return ['reason' => 'ai_success', 'summary' => trim($text)];
}

function openrouter_document_summary(string $excerpt, array $configuration): array
{
    if (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '' || !function_exists('curl_init')) return ['reason' => 'not_configured'];
    $ch = null; $body = ''; $overflow = false;
    try {
        $payload = json_encode(document_summary_request($excerpt, $configuration), JSON_THROW_ON_ERROR);
        if (strlen($payload) > 60000) return ['reason' => 'invalid_response'];
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENROUTER_API_KEY],
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 32768) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($overflow) return ['reason' => 'invalid_response'];
        if ($ok === false) return ['reason' => curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'service_unavailable'];
        return document_summary_decode_response($status, $body);
    } catch (Throwable $error) {
        // Neither the exception, request nor provider body belongs in a general-purpose log.
        return ['reason' => 'service_unavailable'];
    } finally { if ($ch !== null && $ch !== false) curl_close($ch); }
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
