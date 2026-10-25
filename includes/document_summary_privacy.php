<?php
/** Local-only policy, screening and single-use preview bindings. No network operations. */
function document_summary_policy(array $documentContext = []): array
{
    if (defined('PRISM_DOCUMENT_AI_SYNTHETIC_DEMO') && PRISM_DOCUMENT_AI_SYNTHETIC_DEMO === true
        && document_summary_registered_synthetic($documentContext)) {
        $model = defined('OPENROUTER_MODEL') ? OPENROUTER_MODEL : '';
        $valid = is_string($model) && preg_match('~\A[a-z0-9._-]+/[a-z0-9._-]+(?::[a-z0-9._-]+)?\z~i', $model);
        return ['enabled' => (bool)$valid, 'reference' => 'Server-registered synthetic demonstration document',
            'authorizationMode' => 'synthetic_demo', 'model' => is_string($model) ? $model : '', 'providers' => [],
            'reason' => $valid ? 'eligible' : 'demo_model_invalid'];
    }
    $enabled = defined('PRISM_DOCUMENT_AI_ENABLED') && PRISM_DOCUMENT_AI_ENABLED === true;
    $reference = defined('PRISM_DOCUMENT_AI_POLICY_REFERENCE') ? PRISM_DOCUMENT_AI_POLICY_REFERENCE : '';
    $model = defined('PRISM_DOCUMENT_AI_MODEL') ? PRISM_DOCUMENT_AI_MODEL : '';
    $providers = defined('PRISM_DOCUMENT_AI_PROVIDERS') ? PRISM_DOCUMENT_AI_PROVIDERS : [];
    $policyValid = is_string($reference) && trim($reference) !== '' && strlen($reference) <= 160
        && !preg_match('/[\x00-\x1f\x7f]/', $reference);
    $routeValid = is_string($model) && preg_match('~\A[a-z0-9._-]+/[a-z0-9._-]+\z~i', $model)
        && !str_starts_with($model, 'openrouter/') && is_array($providers) && count($providers) > 0 && count($providers) <= 8;
    foreach ((array)$providers as $provider) {
        if (!is_string($provider) || !preg_match('~\A[a-z0-9][a-z0-9._/-]{0,79}\z~i', $provider)) $routeValid = false;
    }
    return ['enabled' => $enabled && $policyValid && $routeValid, 'reference' => is_string($reference) ? $reference : '', 'authorizationMode' => 'institutional',
        'model' => is_string($model) ? $model : '', 'providers' => is_array($providers) ? array_values($providers) : [],
        'reason' => !$enabled ? 'policy_disabled' : (!$policyValid ? 'policy_incomplete' : (!$routeValid ? 'routing_invalid' : 'eligible'))];
}

/** Only operator-managed private registrations authorize the demo route; text and filenames never do. */
function document_summary_registered_synthetic(array $context): bool
{
    if (!defined('STORAGE_DIR') || !preg_match('/\A[a-f0-9]{24}\z/', (string)($context['id'] ?? ''))
        || !preg_match('/\A[a-f0-9]{64}\z/', (string)($context['sha256'] ?? ''))) return false;
    $path = STORAGE_DIR . '/document-summary-synthetic.json';
    if (!is_file($path) || !is_readable($path) || filesize($path) > 1048576) return false;
    $json = @file_get_contents($path);
    if ($json === false) return false;
    $registry = json_decode($json, true);
    $entry = is_array($registry) ? ($registry[$context['id']] ?? null) : null;
    return is_array($entry) && is_string($entry['sha256'] ?? null)
        && hash_equals($entry['sha256'], $context['sha256'])
        && (int)($entry['version'] ?? 0) > 0 && (int)$entry['version'] === (int)($context['version'] ?? 0);
}

function document_summary_notice(string $reason): string
{
    return [
        'eligible' => 'Review the exact excerpt and confirm the document authorization before external processing.',
        'policy_disabled' => 'Institutional authorization for external document processing is not enabled.',
        'policy_incomplete' => 'A valid institutional policy reference authorizing document processing must be configured.',
        'routing_invalid' => 'Configure an approved fixed document model and provider allowlist. Automatic or unrestricted routing is not permitted.',
        'demo_model_invalid' => 'Configure a valid OPENROUTER_MODEL for the synthetic demonstration.',
        'missing_api_key' => 'The server-side OpenRouter API key is not configured.',
        'transport_unavailable' => 'The server does not have the required OpenRouter HTTPS transport available.',
        'not_configured' => 'The document AI service is not configured or its transport is unavailable.',
        'sensitive_content' => 'High-risk individual or confidential information was detected. A sufficiently minimized external excerpt could not be established; use Local Summary.',
        'untrusted_content' => 'The document contains suspicious embedded instructions. External processing is disabled; useful research passages may still be summarized locally.',
        'document_restricted' => 'The document contains a confidentiality or third-party-processing restriction. Use local processing.',
        'extraction_incomplete' => 'Extraction was incomplete, so external privacy screening cannot cover the document. Use local processing.',
        'no_useful_text' => 'No useful research text remained after de-identification.',
        'local_requested' => 'Local processing was selected. No document text was sent to OpenRouter.',
        'external_not_confirmed' => 'Review the AI excerpt and confirm document authorization before external processing.',
        'timeout' => 'OpenRouter timed out. No new summary was saved. You can generate a local summary instead.',
        'rate_limited' => 'OpenRouter is rate limited. No new summary was saved. You can generate a local summary instead.',
        'routing_unavailable' => 'OpenRouter could not serve the configured model/provider route under the required privacy constraints. Review the route and account settings, or generate locally.',
        'provider_unavailable' => 'No permitted privacy-compliant provider is available. You can generate locally; routing restrictions will not be relaxed.',
        'service_auth' => 'OpenRouter rejected the API credentials. Ask the operator to check the protected key, or generate locally.',
        'service_forbidden' => 'OpenRouter refused the request. Account permissions, guardrails or content policies may be responsible; the operator must review them. You can generate locally.',
        'service_credits' => 'The OpenRouter account or key has insufficient credits. You can generate locally.',
        'service_unavailable' => 'OpenRouter could not complete the request. No new summary was saved. You can generate locally.',
        'invalid_response' => 'The AI response was empty, invalid or failed output checks. No new summary was saved. You can generate locally.',
        'ai_success' => 'Generated from a minimized excerpt. Review the original document before official use.',
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

function summary_document_body(string $text): string
{
    return preg_split('/^\s*(?:appendi(?:x|ces)\b|references\s*$|bibliography\s*$|attachments?\s*:)/imu', $text, 2)[0];
}

/** Remove appendices, references, signatures and visible identifiers without deleting useful paragraphs. */
function summary_minimized_text(string $text, array $identifiers): string
{
    $text = summary_document_body($text);
    $text = deidentify_document_summary_text($text, $identifiers);
    // Global phone patterns require a phone-shaped number; numeric results and statistics stay intact.
    $text = preg_replace('/(?<![\p{L}\d])(?:\+?63[ -]?|0)9\d{2}[ -]?\d{3}[ -]?\d{4}(?!\d)|(?<![\p{L}\d])\+\d[\d ()-]{7,18}\d(?!\d)/u', '[phone removed]', $text);
    $text = preg_replace('/\b((?:phone|telephone|mobile|contact number|tel)\s*[:=]\s*)[+\d][\d ()-]{5,20}\d/iu', '$1[phone removed]', $text);
    $text = preg_replace('/\b((?:home address|residential address|address|signature|signed by)\s*[:=]\s*)[^;\n]+/iu', '$1[identifier removed]', $text);
    $text = preg_replace('/\b((?:SSS|PhilHealth|passport)\s*(?:number|no\.?|id)?\s*[:#=]\s*)[A-Z0-9][A-Z0-9.\/-]*/iu', '$1[record identifier removed]', $text);
    $text = preg_replace('/\b((?:date of birth|birth date|DOB)\s*[:=]\s*)(?:\d{1,4}[.\/-]\d{1,2}[.\/-]\d{1,4}|[A-Z][a-z]+\s+\d{1,2},?\s+\d{4})/iu', '$1[date removed]', $text);
    $text = preg_replace('/\b(?:\p{Lu}[\p{L}.&-]*[ \t]+){1,6}(?:University|College|Hospital|School|Medical Center)\b/u', '[institution removed]', $text);
    $text = preg_replace('/\b(?:University|College|Hospital|School)[ \t]+of[ \t]+(?:\p{Lu}[\p{L}-]*[ \t]*){1,4}/u', '[institution removed] ', $text);
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

function prepare_document_summary(array $extraction, array $identifiers, array $documentContext = []): array
{
    $text = summary_minimized_text($extraction['text'], $identifiers);
    $excerpt = summary_model_excerpt($text);
    $policy = document_summary_policy($documentContext);
    $configurationReason = $policy['reason'];
    if ($configurationReason === 'eligible' && (!defined('OPENROUTER_API_KEY') || OPENROUTER_API_KEY === '')) $configurationReason = 'missing_api_key';
    if ($configurationReason === 'eligible' && !function_exists('curl_init')) $configurationReason = 'transport_unavailable';
    $privacyReason = 'eligible';
    // High-risk context is checked before redaction; ordinary removable identifiers are not a veto.
    if (preg_match('/\b(?:confidential\s+(?:attachment|appendix|data|interview\s*transcript)|privileged\s+(?:institutional\s+)?information|internal\s+use\s+only|not\s+(?:be\s+)?shared\s+(?:externally|with\s+third)|no\s+(?:external|third.part(?:y|ies)|AI)\s+processing(?!\s+restrictions?\b)|(?:do\s+not|must\s+not)\s+(?:send|share|disclose|upload|use)[^\n.]{0,100}(?:external|third.part(?:y|ies)|AI)|consent[^\n.]{0,80}(?:prohibits?|restricts?|does\s+not\s+permit)[^\n.]{0,80}(?:external|third.part(?:y|ies)|AI|OpenRouter|OpenAI|cloud))\b/iu', $extraction['text'])) $privacyReason = 'document_restricted';
    elseif (!empty($extraction['partial'])) $privacyReason = 'extraction_incomplete';
    elseif (summary_untrusted_instructions($extraction['text'])) $privacyReason = 'untrusted_content';
    elseif (summary_sensitive_text($extraction['text'], true) || summary_sensitive_text($text)) $privacyReason = 'sensitive_content';
    if (preg_match_all('/\p{L}{2,}/u', preg_replace('/\[[^\]]*\]/u', '', $excerpt)) < 4) $privacyReason = 'no_useful_text';
    $category = $privacyReason !== 'eligible' ? 'C' : (preg_match('/\[(?:identifier|name|email|phone|record identifier|date|credential|link or path) removed\]/u', $text) ? 'B' : 'A');
    $reason = $privacyReason !== 'eligible' ? $privacyReason : $configurationReason;
    return ['excerpt' => $excerpt, 'eligible' => $reason === 'eligible', 'reason' => $reason,
        'privacyCategory' => $category, 'privacyReason' => $privacyReason, 'configurationReason' => $configurationReason,
        'requiresIdentifierReview' => $category === 'B',
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
