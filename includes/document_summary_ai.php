<?php
/** Document-only OpenRouter boundary. Does not call or change the report generator. */
require_once __DIR__ . '/document_summary.php';

function document_summary_fingerprint(array $document, string $path): string
{
    $state = [];
    foreach (['id','student_id','stored_name','version_no','is_current','archived_at','review_status','rpms_submitted_at','ai_summary'] as $key) {
        $state[$key] = $document[$key] ?? null;
    }
    $fileHash = hash_file('sha256', $path);
    if ($fileHash === false) throw new DocumentSummaryError('file_unreadable', 'The document could not be read.');
    return hash('sha256', serialize($state) . $fileHash);
}

/** Match whole known identities, not common name fragments or ordinary academic terms. */
function document_summary_minimize(string $text, array $identifiers): string
{
    usort($identifiers, static fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    foreach (array_unique($identifiers) as $term) {
        $term = trim((string)$term);
        if ($term === '' || (ctype_digit($term) && strlen($term) < 6)) continue;
        $pattern = preg_replace('/\s+/u', '\\s+', preg_quote($term, '~'));
        $text = preg_replace('~(?<![\p{L}\p{N}])' . $pattern . '(?![\p{L}\p{N}])~iu', '[identifier removed]', $text);
    }
    $text = preg_replace('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', '[email removed]', $text);
    $text = preg_replace('/\b(?:student\s*(?:id|number|no\.?)|(?:participant|patient|respondent)\s*(?:id|code|number|no\.?)|protocol\s*(?:code|id|number)|(?:government|national)\s*(?:id|identifier)|passport(?:\s*(?:number|no\.?))?|SSS|SSN|TIN)\s*[:#=]\s*[A-Z0-9][A-Z0-9._\/-]*/i', '[record identifier removed]', $text);
    $text = preg_replace('/(?<!\d)(?:\+?63[ -]?)?0?9\d{2}[ -]?\d{3}[ -]?\d{4}(?!\d)|(?<!\w)\+\d(?:[ ()-]*\d){9,14}(?!\d)/', '[phone removed]', $text);
    $text = preg_replace('/\b(?:phone|telephone|mobile|contact number)\s*[:=]\s*[+\d][\d ()-]{6,20}/i', '[phone removed]', $text);
    $text = preg_replace('/\b(?:home|residential|mailing)\s+address\s*[:=]\s*[^\n;.]+/iu', '[home address removed]', $text);
    $text = preg_replace('/\b\d{1,5}\s+(?:[\p{L}.-]+\s+){1,5}(?:Street|St\.|Road|Rd\.|Avenue|Ave\.)\b[^\n;.]*/u', '[street address removed]', $text);
    $text = preg_replace('/\b(?:api[_ -]?key|password|access[_ -]?token|secret)\s*[:=]\s*\S+|\bBearer\s+\S+|\bsk-(?:or-v1-)?[a-z0-9_-]{12,}/iu', '[credential removed]', $text);
    $text = preg_replace('/^(?:\s*(?:student name|researcher names?|authors?|principal investigator|participant name|prepared by|submitted by|contact person)\s*[:=])[^\n]+/imu', '[name removed]', $text);
    $text = preg_replace('/\b(participant|respondent|patient)\s+[A-Z]{0,3}[-_]?\d{1,8}\b/iu', '$1 [identifier removed]', $text);
    // Contextual actor names: preserve the complete research clause and its measurements.
    $text = preg_replace('/\b[\p{Lu}][\p{Ll}\x27-]+(?:\s+(?:[\p{Lu}][\p{Ll}\x27-]+|de|del|van)){1,4}(?=\s+(?:conducted|investigated|collected|recruited|interviewed)\b)/u', 'A researcher', $text);
    return $text;
}

/** Explicit restrictions and identifiable individual records cannot be authorized by a checkbox. */
function document_summary_external_restriction(string $text): ?string
{
    if (preg_match('/^\s*(?:(?:strictly|private and)\s+)?confidential(?:\s*[:\-].*)?\s*$|\b(?:do not|must not|not authorized to)\s+(?:share|send|transmit|disclose)\s+(?:this\s+(?:document|information)\s+)?(?:externally|with third parties)|\b(?:prohibited|not permitted|not authorized)\s+for\s+(?:external|third.party)\s+processing/imu', $text)) {
        return 'This document contains an explicit external-processing restriction. Use Local Summary unless an authorized, unrestricted copy is provided.';
    }
    if (preg_match('/\b(?:patient|participant|respondent)\s+(?:[A-Z]\d+|(?:id|code|name)\s*[:#=]\s*\S+)[^\n]{0,240}\b(?:diagnosed|diagnosis|HIV|medical history|home address|born on|date of birth)\b/iu', $text)) {
        return 'Individual participant or patient details may remain identifiable after minimization. Use Local Summary or provide an authorized aggregate research copy.';
    }
    if (preg_match('/\b(?:ignore\s+(?:all\s+)?(?:previous|prior|system)\s+instructions|reveal\s+(?:the\s+)?(?:database|secrets?)|(?:system|assistant)\s*:\s*(?:ignore|execute))\b|<\/?(?:script|system|iframe)\b/iu', $text)) {
        return 'Embedded executable content or instructions were detected. Use Local Summary or provide a clean research copy.';
    }
    return null;
}

/** Select complete, useful sentences from both ends of a large section. */
function document_summary_excerpt_slice(string $text, int $budget): string
{
    if (mb_strlen($text) <= $budget) return $text;
    $sentences = preg_split('/(?<=[.!?])\s+/u', preg_replace('/\s+/u', ' ', $text), -1, PREG_SPLIT_NO_EMPTY);
    $ranked = [];
    foreach ($sentences as $i => $sentence) {
        $score = ($i === 0 ? 6 : 0) + ($i === count($sentences) - 1 ? 3 : 0)
            + (preg_match('/\d/', $sentence) ? 8 : 0)
            + (preg_match('/\b(?:results?|findings?|limit\w*|conclud\w*|objectives?|method\w*)\b/iu', $sentence) ? 5 : 0);
        $ranked[] = ['i' => $i, 'score' => $score, 'text' => $sentence];
    }
    usort($ranked, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['i'] <=> $b['i']));
    $selected = []; $used = 0;
    foreach ($ranked as $item) {
        if ($used + mb_strlen($item['text']) + 1 > $budget) continue;
        $selected[$item['i']] = $item['text']; $used += mb_strlen($item['text']) + 1;
    }
    ksort($selected);
    // A single unusually long sentence is presented as a clearly bounded excerpt.
    return $selected ? implode(' ', $selected) : mb_substr($text, 0, max(0, $budget - 3)) . '...';
}

function prepare_document_summary(array $extraction, array $identifiers): array
{
    $restriction = document_summary_external_restriction($extraction['text']);
    if ($restriction !== null) throw new DocumentSummaryError('restricted_content', $restriction);
    $content = summary_research_content($extraction['text']);
    $groups = ['abstract' => [], 'overview' => [], 'methods' => [], 'findings' => [], 'general' => []];
    foreach ($content['blocks'] as $block) {
        if (isset($groups[$block['section']])) $groups[$block['section']][] = $block['text'];
    }
    $parts = []; $seen = []; $omitted = !empty($extraction['partial']) || !empty($content['filtered']);
    if ($content['title'] !== '') $parts[] = 'Research title: ' . $content['title'];
    // Each research group has a reserved share; late findings/limitations cannot be crowded out by the introduction.
    foreach (['abstract' => 1600, 'overview' => 1100, 'methods' => 1800, 'findings' => 2200, 'general' => 700] as $group => $budget) {
        if (!$groups[$group]) continue;
        // Headingless documents share the whole budget across all paragraphs.
        if ($group === 'general' && !$groups['abstract'] && !$groups['overview'] && !$groups['methods'] && !$groups['findings']) $budget = 6800;
        $items = array_values(array_unique($groups[$group]));
        if (count($items) !== count($groups[$group])) $omitted = true;
        if ($group === 'general' && ($groups['abstract'] || $groups['overview'] || $groups['methods'] || $groups['findings'])) {
            $filtered = array_values(array_filter($items, static fn($item) => count(summary_local_words($item)) >= 8
                && preg_match('/\b(?:study|research)\s+(?:aims?|examines?|uses?|investigates?|evaluates?|compares?)\b|\b(?:objectives?|findings?|retrieval time)\b/iu', $item)));
            if (count($filtered) !== count($items)) $omitted = true;
            $items = $filtered;
        }
        if (!$items) continue;
        if (count($items) > 12) {
            $omitted = true;
            $items = array_merge(array_slice($items, 0, 4), array_slice($items, (int)(count($items) / 2), 4), array_slice($items, -4));
        }
        $share = max(50, (int)floor($budget / count($items)) - 2);
        $selected = [];
        foreach ($items as $item) {
            $sliced = document_summary_excerpt_slice($item, $share);
            if ($sliced !== $item) $omitted = true;
            $item = $sliced;
            $key = mb_strtolower(preg_replace('/\s+/u', ' ', $item));
            if (isset($seen[$key])) { $omitted = true; continue; }
            $seen[$key] = true; $selected[] = $item;
        }
        if ($selected) $parts[] = ucfirst($group === 'methods' ? 'methodology' : $group) . ":\n" . implode("\n\n", $selected);
    }
    $excerpt = document_summary_minimize(implode("\n\n", $parts), $identifiers);
    if (mb_strlen($excerpt) > DOCUMENT_SUMMARY_MAX_INPUT_CHARS) $omitted = true;
    $excerpt = mb_substr(trim($excerpt), 0, DOCUMENT_SUMMARY_MAX_INPUT_CHARS);
    if (count(summary_local_words($excerpt)) < 15) throw new DocumentSummaryError('no_useful_text', 'There is too little research text for AI summarization. Try Local Summary or provide the study sections.');
    return ['excerpt' => $excerpt, 'partial' => $omitted, 'pages' => $extraction['pages']];
}

function document_summary_retry_after(?string $header): ?int
{
    if ($header === null) return null;
    $header = trim($header);
    if (ctype_digit($header)) return (int)min((float)$header, PHP_INT_MAX);
    $date = strtotime($header);
    return $date === false ? null : max(0, $date - time());
}

function document_summary_positive_approval(string $text): bool
{
    foreach (preg_split('/(?<=[.!?])\s+|\n/u', $text) as $sentence) {
        if (preg_match('/\b(?:no|not|without|pending|unapproved|hasn.t|wasn.t|isn.t)\b/iu', $sentence)) continue;
        if (preg_match('/\b(?:ethic(?:s|al)?|IERB|IRB|institutional review board|ethics committee)\b.{0,100}\b(?:approved|approval (?:was |is |has been )?(?:granted|obtained|secured))\b|\bapproved\b.{0,80}\b(?:IERB|IRB|ethics committee|institutional review board)\b|\b(?:received|obtained|secured|granted)\s+(?:[\p{L}]+\s+){0,3}(?:ethic(?:s|al)?|IERB|IRB|institutional)\s+approval\b|\b(?:was|were|is|has been|have been)\s+(?:(?:formally|officially|ethically)\s+)?approved\b/iu', $sentence)) return true;
    }
    return false;
}

function validate_document_ai_completion(string $text, string $excerpt, array $identifiers, ?string $finish): string
{
    $text = summary_utf8_text($text);
    if ($text === '') throw new DocumentSummaryError('empty_completion', 'OpenRouter returned an empty completion. Try Local Summary instead.', 502);
    if (in_array($finish, ['length','content_filter','error','tool_calls'], true)) {
        throw new DocumentSummaryError('rejected_completion', $finish === 'length'
            ? 'The AI response was truncated. The saved summary was retained. Try Local Summary instead.'
            : 'The provider could not return a usable research summary. Try Local Summary instead.', 502);
    }
    if ((defined('OPENROUTER_API_KEY') && OPENROUTER_API_KEY !== '' && str_contains($text, OPENROUTER_API_KEY))
        || preg_match('/^(?:I(?:\x{2019}|\x27)?m sorry[,.: ]*)?(?:I (?:cannot|can.t|am unable to)|Sorry[,.: ]|As an AI[, ])/iu', $text)
        || preg_match('/<\?(?:php|=)|<\/?(?:script|iframe|object)\b|javascript\s*:|\bon\w+\s*=\s*[\x22\x27]|```\s*(?:php|javascript|html|sh|bash)\b/iu', $text)
        || summary_local_unsafe($text)
        || document_summary_minimize($text, $identifiers) !== $text
        || (document_summary_positive_approval($text) && !document_summary_positive_approval($excerpt))
        || mb_strlen($text) > 10000 || count(summary_local_words($text)) < 12) {
        throw new DocumentSummaryError('rejected_completion', 'The AI response did not pass the research summary checks. The saved summary was retained. Try Local Summary instead.', 502);
    }
    // Harmless headings, bullets and numbering remain readable plain text, always escaped by the UI.
    $text = preg_replace('/^\s{0,3}#{1,6}\s+/m', '', $text);
    $text = preg_replace('/\*\*([^*\n]+)\*\*|__([^_\n]+)__/u', '$1$2', $text);
    return trim($text);
}

function document_openrouter_summary(string $excerpt, array $identifiers): string
{
    if (!defined('OPENROUTER_API_KEY') || trim(OPENROUTER_API_KEY) === '') throw new DocumentSummaryError('missing_api_key', 'OpenRouter is not configured. Generate a Local Summary or ask the server administrator to configure the API key.', 503);
    if (!defined('OPENROUTER_MODEL') || trim(OPENROUTER_MODEL) === '') throw new DocumentSummaryError('invalid_model', 'The configured OpenRouter model is missing or invalid.', 503);
    if (!function_exists('curl_init')) throw new DocumentSummaryError('transport_unavailable', 'HTTPS AI requests require the PHP cURL extension. Local Summary remains available.', 503);
    $response = ''; $retryAfter = null;
    $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_POST => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 45,
        CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENROUTER_API_KEY],
        CURLOPT_POSTFIELDS => json_encode(['model' => OPENROUTER_MODEL,
            'messages' => [['role' => 'system', 'content' => DOCUMENT_SUMMARY_PROMPT], ['role' => 'user', 'content' => $excerpt]],
            'temperature' => 0.2, 'max_tokens' => 650, 'stream' => false], JSON_THROW_ON_ERROR),
        CURLOPT_HEADERFUNCTION => static function ($handle, string $header) use (&$retryAfter): int {
            if (stripos($header, 'Retry-After:') === 0) $retryAfter = document_summary_retry_after(substr($header, 12));
            return strlen($header);
        },
        CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$response): int {
            if (strlen($response) + strlen($chunk) > 131072) return 0;
            $response .= $chunk; return strlen($chunk);
        },
    ]);
    try { $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $errno = curl_errno($ch); }
    finally { curl_close($ch); }
    if ($errno === CURLE_OPERATION_TIMEDOUT) throw new DocumentSummaryError('network_timeout', 'The OpenRouter request timed out. No automatic retry was made. Try Local Summary instead.', 504);
    if ($ok === false || $errno) throw new DocumentSummaryError('network_failure', 'OpenRouter could not be reached securely. Try Local Summary instead.', 503);
    $data = json_decode($response, true);
    if ($status !== 200 || isset($data['error'])) {
        $code = $status === 200 ? (int)($data['error']['code'] ?? 502) : $status;
        [$reason, $message, $http] = match ($code) {
            401, 403 => ['invalid_api_key', 'OpenRouter rejected the configured credentials or access permissions.', 503],
            402 => ['insufficient_credits', 'OpenRouter reports insufficient credits for this request.', 503],
            404 => ['invalid_model', 'The configured OpenRouter model is unavailable or invalid.', 503],
            429 => ['rate_limited', 'OpenRouter is rate limiting requests. No automatic retry was made.', 429],
            default => ['provider_failure', 'OpenRouter could not complete this request. Try Local Summary instead.', 502],
        };
        if ($code === 400 && preg_match('/model/i', (string)($data['error']['message'] ?? ''))) {
            $reason = 'invalid_model'; $message = 'OpenRouter rejected the configured model.'; $http = 503;
        }
        if ($retryAfter !== null) $message .= ' Retry after ' . $retryAfter . ' seconds.';
        throw new DocumentSummaryError($reason, $message, $http, $retryAfter);
    }
    $choice = $data['choices'][0] ?? [];
    if (!empty($choice['message']['refusal'])) throw new DocumentSummaryError('rejected_completion', 'The provider declined to summarize this excerpt. Try Local Summary instead.', 502);
    return validate_document_ai_completion(is_string($choice['message']['content'] ?? null) ? $choice['message']['content'] : '', $excerpt, $identifiers, $choice['finish_reason'] ?? null);
}