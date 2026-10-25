<?php
/** Local preparation and single-use preview bindings. No network operations. */
function document_summary_configuration(): array
{
    $model = defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : '';
    $reason = !defined('OPENROUTER_API_KEY') || trim(OPENROUTER_API_KEY) === '' ? 'missing_api_key'
        : (!function_exists('curl_init') ? 'transport_unavailable'
        : (!is_string($model) || !preg_match('~\A[a-z0-9._-]+/[a-z0-9._-]+(?::[a-z0-9._-]+)?\z~i', $model) ? 'model_invalid' : 'eligible'));
    return ['enabled' => $reason === 'eligible', 'model' => is_string($model) ? $model : '', 'reason' => $reason];
}

function document_summary_notice(string $reason): string
{
    return [
        'eligible' => 'Review the exact excerpt and confirm that you may send it to OpenRouter.',
        'model_invalid' => 'Configure a valid shared OPENROUTER_MODEL.',
        'missing_api_key' => 'The server-side OpenRouter API key is not configured.',
        'transport_unavailable' => 'The server does not have the required OpenRouter HTTPS transport available.',
        'not_configured' => 'The document AI service is not configured or its transport is unavailable.',
        'no_useful_text' => 'No useful research text remained after excerpt preparation.',
        'local_requested' => 'Local processing was selected. No document text was sent to OpenRouter.',
        'external_not_confirmed' => 'Review the AI excerpt and confirm that you may send it to OpenRouter.',
        'timeout' => 'OpenRouter timed out. No new summary was saved. You can generate a local summary instead.',
        'rate_limited' => 'OpenRouter is rate limited. No new summary was saved. You can generate a local summary instead.',
        'routing_unavailable' => 'OpenRouter could not serve the configured shared model. Check the model and account routing settings, or generate locally.',
        'provider_unavailable' => 'The OpenRouter provider is unavailable. Try again later or generate locally.',
        'service_auth' => 'OpenRouter rejected the API credentials. Ask the operator to check the protected key, or generate locally.',
        'service_forbidden' => 'OpenRouter refused the request. Account permissions, guardrails or content policies may be responsible; the operator must review them. You can generate locally.',
        'service_credits' => 'The OpenRouter account or key has insufficient credits. You can generate locally.',
        'service_unavailable' => 'OpenRouter could not complete the request. No new summary was saved. You can generate locally.',
        'invalid_response' => 'The AI response was empty, invalid or failed output checks. No new summary was saved. You can generate locally.',
        'ai_success' => 'Generated from the reviewed research excerpt. Review the original document before official use.',
    ][$reason] ?? 'External processing is unavailable. You can choose Local Summary.';
}

/** Names are matched as identities in context, not as common words everywhere in a paper. */
function summary_redact_person_names(string $text): string
{
    $word = '(?!(?i:Purpose|Methods?|Methodology|Findings?|Results?|Ethics|Title|Study|Research|University|Hospital|Data|Satisfaction|Survey|Questionnaire|Interview|Consent|Information|Demographics|Responses?|Recruitment|Selection|Population|Students?|Group|Sample)\b)\p{Lu}[\p{L}\p{M}\x{2019}\x27-]*\.?';
    $name = $word . '(?:[ \t]+(?:' . $word . '|(?i:de|del|dela|la|van|von))){1,5}';
    $roles = '(?i:researcher(?:s)?|advis[eo]r|supervisor|author|co-author|principal investigator|investigator|participant|respondent|patient|student(?: name)?|prepared by|submitted by|contact person|name)';
    $text = preg_replace('~\b(' . $roles . '(?:[ \t]+(?i:named|name|is named))?[ \t]*[:=]?[ \t]+)' . $name . '~u', '$1[name removed]', $text);
    $text = preg_replace('~\b(' . $roles . '[ \t]*[:=][ \t]*)' . $word . '~u', '$1[name removed]', $text);
    return preg_replace('~\b(?i:Dr\.?|Prof\.?|Professor|Mr\.?|Mrs\.?|Ms\.?)\s+' . $word . '(?:[ \t]+(?:' . $word . '|(?i:de|del|dela|la))){0,4}~u', '[name removed]', $text);
}

/** Optional personal-identifier minimization; institutions and research measurements remain intact. */
function summary_minimized_text(string $text, array $identifiers, bool $redactIdentifiers = true): string
{
    if ($redactIdentifiers) {
    $text = deidentify_document_summary_text($text, $identifiers);
    // Global phone patterns require a phone-shaped number; numeric results and statistics stay intact.
    $text = preg_replace('/(?<![\p{L}\d])(?:\+?63[ -]?|0)9\d{2}[ -]?\d{3}[ -]?\d{4}(?!\d)|(?<![\p{L}\d])\+\d[\d ()-]{7,18}\d(?!\d)/u', '[phone removed]', $text);
    $text = preg_replace('/\b((?:phone|telephone|mobile|contact number|tel)\s*[:=]\s*)[+\d][\d ()-]{5,20}\d/iu', '$1[phone removed]', $text);
    $text = preg_replace('/\b((?:home address|residential address|address|signature|signed by)\s*[:=]\s*)[^;\n]+/iu', '$1[identifier removed]', $text);
    $text = preg_replace('/\b((?:SSS|PhilHealth|passport)\s*(?:number|no\.?|id)?\s*[:#=]\s*)[A-Z0-9][A-Z0-9.\/-]*/iu', '$1[record identifier removed]', $text);
    $text = preg_replace('/\b((?:date of birth|birth date|DOB)\s*[:=]\s*)(?:\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}|[A-Z][a-z]+\s+\d{1,2},?\s+\d{4})/iu', '$1[date removed]', $text);
    }
    $text = preg_replace('/\b(?:https?:\/\/|www\.)\S+|\b[A-Z]:\\\\\S+|\/(?:home|var|Users|storage)\/\S+/iu', '[link or path removed]', $text);
    $text = preg_replace('/\b(?:sk-(?:or-v1-)?[a-z0-9_-]{12,}|Bearer\s+\S+|(?:api[_ -]?key|password|access[_ -]?token|secret)\s*[:=]\s*\S+)/iu', '[credential removed]', $text);
    return trim($text);
}

/** Conservative risk signals, not an anonymity guarantee. Generic methods/consent prose stays eligible. */
function summary_sensitive_text(string $text, bool $beforeMinimization = false): bool
{
    if (summary_untrusted_instructions($text)) return true;
    $patterns = [
        '/\b(?:medical\s*record|patient\s*(?:id|number|no\.?|name)|MRN)\s*[:#=]/iu',
        '/\b(?i:participant|respondent|patient|subject|student)\b[ \t]+(?:[A-Z]{0,3}[- ]?\d+|(?i:no\.?|number|id|code)[ :#-]*[A-Z0-9-]+|[A-Z])\b[^\n.]{0,150}\b(?i:age[ds]?|diagnos\w*|HIV|pregnan\w*|lives?|address|female|male|treatment)/u',
        '/\b(?:case\s*report|rare\s*(?:disease|condition))\b[^\n.]{0,180}\b(?:patient|child|woman|man|family|aged|year.old)/iu',
        '/\b(?:participant|respondent|patient)[_ -]?(?:id|code|name)\b[^\n]{0,100}[,\t|][^\n]*\b(?:age|sex|gender|diagnosis|condition|address)\b[^\n]*\n[^\n]*\d/iu',
        '/\b(?:[1-9]|one|two|three|four|five|six|seven|eight|nine)\s+(?:[\p{L}-]+\s+){0,3}(?:patients?|participants?|students?|respondents?|subjects?)\b[^\n.]{0,120}\b(?:HIV|cancer|genetic|diagnos\w*|rare|pregnan\w*)/iu',
        '/["\x{201c}]I\s+(?:am|was|have|live|suffer|had|work)\b/iu',
        '/\b(?i:participant|respondent|patient|student)[ \t]+\p{Lu}[\p{L}\x27-]+(?:[ \t]+\p{Lu}[\p{L}\x27-]+){1,4}[^\n.]{0,100}\b(?i:diagnos\w*|HIV|pregnan\w*|medical|treatment|lives|address)\b/u',
        '/^\s*(?:confidential\s+)?(?:interview\s*transcript|participant\s*(?:narrative|quote)|case\s*history)\s*[:=]/imu',
        '/\b\p{Lu}[\p{L}\x27-]+[ \t]+\p{Lu}[\p{L}\x27-]+[,\t|][ \t]*\d{1,3}[,\t|][^\n]{0,100}\b(?i:female|male|diagnos\w*|HIV|pregnan\w*)\b/u',
    ];
    if (!$beforeMinimization) $patterns[] = '/\b(?:at|in|from)\s+(?:\p{Lu}[\p{L}-]+\s+){0,3}(?:Barangay|Street|Road|Avenue)\b|\b(?:Barangay|Brgy\.?)\s+\p{Lu}[\p{L}-]+/u';
    foreach ($patterns as $pattern) if (preg_match($pattern, $text)) return true;
    return false;
}

function summary_untrusted_instructions(string $text): bool
{
    return (bool)preg_match('/\b(?:ignore\s+(?:all\s+)?(?:previous|system|prior)\s+instructions|reveal\s+(?:the\s+)?(?:database|secrets?|system\s*prompt)|(?:system|developer)\s*:\s*(?:ignore|you are|reveal|override|act as)\b|<\/?(?:script|system|tool)\b)|[\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}-\x{206f}]/iu', $text);
}

function prepare_document_summary(array $extraction, array $identifiers, bool $redactIdentifiers = true): array
{
    $selection = document_summary_research_excerpt($extraction['text']);
    $excerpt = summary_minimized_text($selection['excerpt'], $identifiers, $redactIdentifiers);
    $warnings = [];
    if ($selection['preliminaryOnly']) $warnings[] = 'No research body was recognized. The available excerpt may contain only a title or preliminary information; methodology and findings may be unavailable.';
    if (summary_sensitive_text($extraction['text'], true)) $warnings[] = 'Potentially sensitive individual information was detected. Check the excerpt carefully and use Local Summary if you may not share it.';
    if (preg_match('/\b(?:confidential|internal use only|no external processing|do not (?:share|send|upload)|consent[^\n.]{0,60}(?:prohibits?|restricts?))\b/iu', $extraction['text'])) {
        $warnings[] = 'The document may contain confidentiality or sharing restrictions. Confirmation does not override those restrictions or establish institutional or legal authorization.';
    }
    if (summary_untrusted_instructions($extraction['text'])) {
        $warnings[] = 'Suspicious embedded instructions were detected. Matching passages were excluded; review the remaining research excerpt.';
        $parts = preg_split('/(?<=[.!?])\s+|\n+/u', $excerpt, -1, PREG_SPLIT_NO_EMPTY);
        $excerpt = implode("\n", array_filter($parts, static fn($part) => !summary_untrusted_instructions($part)));
    }
    $partial = !empty($extraction['partial']) || $selection['partial'];
    if ($partial) $warnings[] = 'Only part of the extracted document fits this excerpt. Some passages were omitted; missing details must not be inferred.';
    if ($redactIdentifiers && $excerpt !== $selection['excerpt']) $warnings[] = 'Some identifiers or links were minimized. Screening can miss identifying details; inspect the exact text below.';
    $configuration = document_summary_configuration(); $reason = $configuration['reason'];
    if (preg_match_all('/\p{L}{2,}/u', preg_replace('/\[[^\]]*\]/u', '', $excerpt)) < 4) $reason = 'no_useful_text';
    return ['excerpt' => $excerpt, 'eligible' => $reason === 'eligible', 'reason' => $reason,
        'warnings' => $warnings, 'redactIdentifiers' => $redactIdentifiers, 'partial' => $partial,
        'pages' => $extraction['pages'] ?? null, 'configuration' => $configuration];
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

function revoke_document_summary_previews(string $documentId, array $actor, ?string $token = null): void
{
    foreach ($_SESSION['document_summary_previews'] ?? [] as $key => $preview) {
        if ($preview['id'] === $documentId && $preview['actor'] === (string)$actor['id']
            && ($token === null || hash_equals($key, hash('sha256', $token)))) unset($_SESSION['document_summary_previews'][$key]);
    }
}

/** Source and notices travel with the summary in the existing TEXT column; legacy plain text is readable. */
function document_summary_unpack(?string $stored): array
{
    $value = json_decode((string)$stored, true);
    if (is_array($value) && ($value['format'] ?? '') === 'prism.document-summary.v1'
        && is_string($value['summary'] ?? null) && in_array($value['source'] ?? '', ['openrouter_ai', 'local_fallback'], true)) {
        return ['summary' => $value['summary'], 'source' => $value['source'], 'partial' => !empty($value['partial']),
            'notice' => $value['source'] === 'local_fallback' && ($value['reason'] ?? '') !== 'local_requested'
                ? 'This saved summary was generated locally because AI was unavailable or external processing was not permitted.'
                : document_summary_notice((string)($value['reason'] ?? '')), 'reason' => (string)($value['reason'] ?? '')];
    }
    return ['summary' => $stored, 'source' => null, 'partial' => false, 'notice' => '', 'reason' => 'legacy'];
}

function document_summary_pack(array $result): string
{
    return json_encode(['format' => 'prism.document-summary.v1', 'summary' => $result['summary'],
        'source' => $result['source'], 'partial' => $result['partial'], 'reason' => $result['reason']], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
