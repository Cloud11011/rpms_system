<?php
/** Local-only policy, screening and single-use preview bindings. No network operations. */
function document_summary_policy(): array
{
    $enabled = defined('PRISM_DOCUMENT_AI_ENABLED') && PRISM_DOCUMENT_AI_ENABLED === true;
    $reference = defined('PRISM_DOCUMENT_AI_POLICY_REFERENCE') ? PRISM_DOCUMENT_AI_POLICY_REFERENCE : '';
    $model = defined('PRISM_DOCUMENT_AI_MODEL') ? PRISM_DOCUMENT_AI_MODEL : '';
    $providers = defined('PRISM_DOCUMENT_AI_PROVIDERS') ? PRISM_DOCUMENT_AI_PROVIDERS : [];
    $valid = is_string($reference) && trim($reference) !== '' && strlen($reference) <= 160
        && !preg_match('/[\x00-\x1f\x7f]/', $reference)
        && is_string($model) && preg_match('~\A[a-z0-9._-]+/[a-z0-9._-]+\z~i', $model)
        && !str_starts_with($model, 'openrouter/') && is_array($providers) && count($providers) > 0 && count($providers) <= 8;
    foreach ((array)$providers as $provider) {
        if (!is_string($provider) || !preg_match('~\A[a-z0-9][a-z0-9._/-]{0,79}\z~i', $provider)) $valid = false;
    }
    return ['enabled' => $enabled && $valid, 'reference' => is_string($reference) ? $reference : '',
        'model' => is_string($model) ? $model : '', 'providers' => is_array($providers) ? array_values($providers) : [],
        'reason' => !$enabled ? 'policy_disabled' : (!$valid ? 'policy_incomplete' : 'eligible')];
}

function document_summary_notice(string $reason): string
{
    return [
        'eligible' => 'Review the exact excerpt and confirm the document authorization before external processing.',
        'policy_disabled' => 'Institutional authorization for external document processing is not enabled.',
        'policy_incomplete' => 'An approved institutional policy, fixed model and provider allowlist must be configured.',
        'not_configured' => 'The document AI service is not configured or its transport is unavailable.',
        'sensitive_content' => 'Sensitive or potentially identifying content requires local-only handling.',
        'document_restricted' => 'The document contains a confidentiality or third-party-processing restriction. Use local processing.',
        'extraction_incomplete' => 'Extraction was incomplete, so external privacy screening cannot cover the document. Use local processing.',
        'no_useful_text' => 'No useful research text remained after de-identification.',
        'local_requested' => 'Local processing was selected. No document text was sent to OpenRouter.',
        'external_not_confirmed' => 'External document processing has not been confirmed. A local summary was generated.',
        'timeout' => 'OpenRouter timed out. A local summary was generated.',
        'rate_limited' => 'OpenRouter is rate limited. A local summary was generated.',
        'provider_unavailable' => 'No permitted privacy-compliant provider was available. A local summary was generated.',
        'service_auth' => 'OpenRouter rejected the service credentials or permissions. A local summary was generated.',
        'service_unavailable' => 'OpenRouter was unavailable. A local summary was generated.',
        'invalid_response' => 'The AI response was empty, invalid or failed output checks. A local summary was generated.',
        'ai_success' => 'Generated from a minimized excerpt. Review the original document before official use.',
    ][$reason] ?? 'External processing was unavailable. A local summary was generated.';
}

/** Remove appendices, references, signatures and visible identifiers without deleting useful paragraphs. */
function summary_minimized_text(string $text, array $identifiers): string
{
    $text = preg_split('/^\s*(?:appendi(?:x|ces)\b|references\s*$|bibliography\s*$|attachments?\s*:)/imu', $text, 2)[0];
    $text = deidentify_document_summary_text($text, $identifiers);
    $text = preg_replace('/\b(?:\+?63[ -]?|0)9\d{2}[ -]?\d{3}[ -]?\d{4}\b|(?<!\d)\+?\d[\d ().-]{7,18}\d(?!\d)/u', '[phone or numeric identifier removed]', $text);
    $text = preg_replace('/^\s*(?:signature|signed by|adviser|advisor|supervisor|author|co-author|investigator|researcher|participant|respondent|patient)\s*(?:name)?\s*[:=][^\n]*/imu', '[identity field removed]', $text);
    $text = preg_replace('/\b(?:Dr\.?|Prof\.?|Mr\.?|Mrs\.?|Ms\.?)\s+\p{Lu}[\p{L}\x{2019}\x27-]+(?:\s+(?:\p{Lu}\.?|\p{Lu}[\p{L}\x{2019}\x27-]+)){1,3}/u', '[name removed]', $text);
    $text = preg_replace('/\b(?:[\p{Lu}][\p{L}.&-]*\s+){1,6}(?:University|College|Hospital|School|Medical Center)\b/u', '[institution removed]', $text);
    $text = preg_replace('/\b(?:University|College|Hospital|School)\s+of\s+(?:[\p{Lu}][\p{L}-]*\s*){1,4}/u', '[institution removed] ', $text);
    $text = preg_replace('/\b(?:https?:\/\/|www\.)\S+|\b[A-Z]:\\\\\S+|\/(?:home|var|Users|storage)\/\S+/iu', '[link or path removed]', $text);
    $text = preg_replace('/\b(?:sk-(?:or-v1-)?[a-z0-9_-]{12,}|Bearer\s+\S+|(?:api[_ -]?key|password|access[_ -]?token|secret)\s*[:=]\s*\S+)/iu', '[credential removed]', $text);
    return trim($text);
}

/** Conservative risk signals, not an anonymity guarantee. Generic methods/consent prose stays eligible. */
function summary_sensitive_text(string $text): bool
{
    $patterns = [
        '/\b(?:medical\s*record|patient\s*(?:id|number|no\.?|name)|MRN|SSS|PhilHealth|passport|birth\s*date|date\s*of\s*birth|home\s*address|residential\s*address)\s*[:#=]|\bDOB\s*[:=]/iu',
        '/\b(?:participant|respondent|patient|subject)\s*(?:[A-Z]?\d+|[A-Z])\b[^\n.]{0,150}\b(?:age[ds]?|diagnos\w*|HIV|pregnan\w*|lives?|address|female|male|treatment)/iu',
        '/\b(?:case\s*(?:study|report)|rare\s*(?:disease|condition))\b[^\n.]{0,180}\b(?:patient|child|woman|man|family|aged|year.old)/iu',
        '/\b(?:[1-9]|one|two|three|four|five|six|seven|eight|nine)\s+(?:participants?|patients?|respondents?)\b[^\n.]{0,140}\b(?:diagnos\w*|HIV|pregnan\w*|village|barangay|aged|year.old)/iu',
        '/\b(?:interview\s*transcript|participant\s*(?:narrative|quote)|individual\s*(?:responses|records)|raw\s*(?:data|responses)|case\s*history)\b/iu',
        '/\b(?:participant|respondent|patient)[_ -]?(?:id|code|name)\b[^\n]{0,100}\b(?:age|sex|gender|diagnosis|condition|address)\b/iu',
        '/\b(?:[1-9]|one|two|three|four|five|six|seven|eight|nine)\s+(?:[\p{L}-]+\s+){0,3}(?:patients?|participants?)\b[^\n.]{0,120}\b(?:HIV|cancer|genetic|diagnos\w*|rare|pregnan\w*)/iu',
        '/["\x{201c}]I\s+(?:am|was|have|live|suffer|had|work)\b/iu',
        '/\b(?:my\s+name\s+is|named|contact|researcher|adviser|advisor|participant|respondent|patient)\s+(?:is\s+)?\p{Lu}[\p{L}\x27-]+\s+\p{Lu}[\p{L}\x27-]+/u',
        '/\b(?:at|in|from)\s+(?:\p{Lu}[\p{L}-]+\s+){0,3}(?:Barangay|Street|Road|Avenue)\b|\b(?:Barangay|Brgy\.?)\s+\p{Lu}[\p{L}-]+/u',
        '/\b\p{Lu}[\p{L}\x27-]+\s+\p{Lu}[\p{L}\x27-]+,\s*\p{Lu}[\p{L}\x27-]+\s+\p{Lu}[\p{L}\x27-]+\b/u',
        '/\b(?:ignore\s+(?:all\s+)?(?:previous|system|prior)\s+instructions|reveal\s+(?:the\s+)?(?:database|secrets?|system\s*prompt)|system\s*:\s*|<\/?(?:script|system|tool)\b)/iu',
        '/[\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}-\x{206f}]/u',
    ];
    foreach ($patterns as $pattern) if (preg_match($pattern, $text)) return true;
    return false;
}

function prepare_document_summary(array $extraction, array $identifiers): array
{
    $text = summary_minimized_text($extraction['text'], $identifiers);
    $excerpt = summary_model_excerpt($text);
    $policy = document_summary_policy();
    $reason = $policy['reason'];
    if ($reason === 'eligible' && (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '' || !function_exists('curl_init'))) $reason = 'not_configured';
    // Inspect all extracted content, including passages outside the selected excerpt.
    if (preg_match('/\b(?:confidential\s+(?:attachment|appendix|data)|internal\s+use\s+only|not\s+(?:be\s+)?shared\s+(?:externally|with\s+third)|no\s+(?:external|third.part(?:y|ies)|AI)\s+processing|(?:do\s+not|must\s+not)\s+(?:send|share|disclose|upload|use)[^\n.]{0,100}(?:external|third.part(?:y|ies)|AI)|consent[^\n.]{0,80}(?:prohibits?|restricts?|does\s+not\s+permit))\b/iu', $extraction['text'])) $reason = 'document_restricted';
    elseif (!empty($extraction['partial'])) $reason = 'extraction_incomplete';
    elseif (summary_sensitive_text($text) || summary_sensitive_text(deidentify_document_summary_text($extraction['text'], $identifiers))) $reason = 'sensitive_content';
    if (preg_match_all('/\p{L}{2,}/u', preg_replace('/\[[^\]]*\]/u', '', $excerpt)) < 4) $reason = 'no_useful_text';
    return ['excerpt' => $excerpt, 'eligible' => $reason === 'eligible', 'reason' => $reason,
        'partial' => !empty($extraction['partial']) || mb_strlen($text) > DOCUMENT_SUMMARY_MAX_INPUT_CHARS,
        'pages' => $extraction['pages'] ?? null, 'policy' => $policy];
}

function document_summary_binding(array $document, string $fileHash, array $prepared, array $actor): string
{
    return hash('sha256', json_encode([$document, $fileHash, $prepared, (string)$actor['id'],
        (string)($actor['password_hash'] ?? ''), DOCUMENT_SUMMARY_PROMPT], JSON_THROW_ON_ERROR));
}

function issue_document_summary_preview(string $binding, string $documentId, array $actor): string
{
    $now = time();
    $previews = array_filter($_SESSION['document_summary_previews'] ?? [], fn($p) => $p['expires'] > $now);
    $previews = array_slice($previews, -4, null, true);
    $token = bin2hex(random_bytes(32));
    $previews[hash('sha256', $token)] = ['binding' => $binding, 'id' => $documentId,
        'actor' => (string)$actor['id'], 'expires' => $now + 300];
    $_SESSION['document_summary_previews'] = $previews;
    return $token;
}

function consume_document_summary_preview(string $token, string $binding, string $documentId, array $actor): void
{
    $key = hash('sha256', $token); $preview = $_SESSION['document_summary_previews'][$key] ?? null;
    unset($_SESSION['document_summary_previews'][$key]);
    if (!$preview || $preview['expires'] <= time() || $preview['id'] !== $documentId
        || $preview['actor'] !== (string)$actor['id'] || !hash_equals($preview['binding'], $binding)) {
        throw new DocumentSummaryError('preview_changed', 'The preview expired, was used, or the document changed. Review a fresh preview.', 409);
    }
}

/** Source and notices travel with the summary in the existing TEXT column; legacy plain text is readable. */
function document_summary_unpack(?string $stored): array
{
    $value = json_decode((string)$stored, true);
    if (is_array($value) && ($value['format'] ?? '') === 'prism.document-summary.v1'
        && is_string($value['summary'] ?? null) && in_array($value['source'] ?? '', ['openrouter_ai', 'local_fallback'], true)) {
        return ['summary' => $value['summary'], 'source' => $value['source'], 'partial' => !empty($value['partial']),
            'notice' => document_summary_notice((string)($value['reason'] ?? '')), 'reason' => (string)($value['reason'] ?? '')];
    }
    return ['summary' => $stored, 'source' => null, 'partial' => false, 'notice' => '', 'reason' => 'legacy'];
}

function document_summary_pack(array $result): string
{
    return json_encode(['format' => 'prism.document-summary.v1', 'summary' => $result['summary'],
        'source' => $result['source'], 'partial' => $result['partial'], 'reason' => $result['reason']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
