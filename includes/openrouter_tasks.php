<?php
/** Fixed, operator-reviewed text models. No discovery, random routing, or paid upgrades at runtime. */
function openrouter_task_catalog(): array
{
    return defined('OPENROUTER_TASK_MODEL_CATALOG') ? OPENROUTER_TASK_MODEL_CATALOG : [
        'google/gemma-4-31b-it:free' => [
            'name' => 'Gemma 4 31B', 'text_instruct' => true, 'free' => true, 'reasoning' => true,
            'response_ids' => ['google/gemma-4-31b-it', 'google/gemma-4-31b-it-20260402'],
        ],
        'google/gemma-4-26b-a4b-it:free' => [
            'name' => 'Gemma 4 26B A4B', 'text_instruct' => true, 'free' => true, 'reasoning' => true,
            'response_ids' => ['google/gemma-4-26b-a4b-it', 'google/gemma-4-26b-a4b-it-20260403'],
        ],
    ];
}

function openrouter_task_models(string $task): array
{
    $setting = ['document_summary' => 'OPENROUTER_DOCUMENT_SUMMARY_MODELS', 'progress_report' => 'OPENROUTER_PROGRESS_REPORT_MODELS'][$task] ?? null;
    if ($setting === null) return [];
    $models = defined($setting) ? constant($setting) : (getenv($setting) !== false ? getenv($setting) :
        ($task === 'document_summary' ? ['google/gemma-4-31b-it:free', 'google/gemma-4-26b-a4b-it:free']
            : ['google/gemma-4-26b-a4b-it:free', 'google/gemma-4-31b-it:free']));
    if (is_string($models)) {
        $models = str_starts_with(trim($models), '[') ? json_decode($models, true) : explode(',', $models);
    }
    if (!is_array($models) || !array_is_list($models) || count($models) < 1 || count($models) > 2) return [];
    $catalog = openrouter_task_catalog();
    foreach ($models as &$model) {
        if (!is_string($model)) return [];
        $model = trim($model);
        if (!preg_match('~\A[a-z0-9._-]+/[a-z0-9._-]+:free\z~', $model)
            || !is_array($catalog[$model] ?? null) || ($catalog[$model]['text_instruct'] ?? false) !== true
            || ($catalog[$model]['free'] ?? false) !== true || str_starts_with($model, 'openrouter/')) return [];
    }
    unset($model);
    return count(array_unique($models)) === count($models) ? $models : [];
}

/** Response IDs may omit :free or name a dated build. Never infer an ID missing from the response. */
function openrouter_model_key(?string $actual, array $models): ?string
{
    if ($actual === null) return null;
    $catalog = openrouter_task_catalog();
    foreach ($models as $model) {
        if (in_array($actual, array_merge([$model], $catalog[$model]['response_ids'] ?? [],
            array_map(static fn($id) => $id . ':free', $catalog[$model]['response_ids'] ?? [])), true)) return $model;
    }
    return null;
}

function openrouter_model_label(?string $actual): ?string
{
    $key = openrouter_model_key($actual, array_keys(openrouter_task_catalog()));
    return $key !== null ? (string)(openrouter_task_catalog()[$key]['name'] ?? $actual) : $actual;
}

/** Parse Retry-After without sleeping in a web worker or issuing an early retry. */
function openrouter_retry_after(array $headers): int
{
    $raw = trim((string)($headers['retry-after'] ?? ''));
    if ($raw === '') return 0;
    if (ctype_digit($raw)) return min(PHP_INT_MAX - time(), (int)$raw);
    $timestamp = strtotime($raw);
    return $timestamp === false ? 0 : max(0, $timestamp - time());
}

/** Share confirmed account exhaustion/backoff across tasks and PHP requests, without storing a key. */
function openrouter_task_cooldown(?array $new = null): ?array
{
    static $memory = null;
    if ($new !== null && ($new['until'] ?? 0) > ($memory['until'] ?? 0)) $memory = $new;
    if (defined('STORAGE_DIR') && defined('OPENROUTER_API_KEY')) {
        $dir = STORAGE_DIR . '/openrouter-cooldowns';
        if (is_dir($dir) || @mkdir($dir, 0700, true)) {
            $path = $dir . '/' . hash('sha256', OPENROUTER_API_KEY) . '.json';
            $handle = @fopen($path, 'c+');
            if ($handle) {
                @chmod($path, 0600);
                if (flock($handle, $new === null ? LOCK_SH : LOCK_EX)) {
                    $stored = json_decode((string)stream_get_contents($handle, 2048), true);
                    if (is_array($stored) && in_array($stored['reason'] ?? '', ['account_quota', 'rate_limited', 'retry_after'], true)
                        && is_int($stored['until'] ?? null) && $stored['until'] > ($memory['until'] ?? 0)) $memory = $stored;
                    if ($new !== null) {
                        rewind($handle); ftruncate($handle, 0); fwrite($handle, json_encode($memory)); fflush($handle);
                        @chmod($path, 0600);
                    }
                    flock($handle, LOCK_UN);
                }
                fclose($handle);
            } else return ['reason' => 'cooldown_storage', 'until' => time() + 60];
        } else return ['reason' => 'cooldown_storage', 'until' => time() + 60];
    }
    return ($memory['until'] ?? 0) > time() ? $memory : null;
}

/** Restricted HTTPS transport. Both attempts use identical headers, endpoint and account policies. */
function openrouter_task_http(array $payload): array
{
    if (!defined('OPENROUTER_API_KEY') || trim(OPENROUTER_API_KEY) === '' || !function_exists('curl_init')) {
        return ['status' => 0, 'body' => '', 'headers' => [], 'reason' => 'not_configured'];
    }
    $ch = null; $body = ''; $headers = []; $overflow = false;
    try {
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 65000) return ['reason' => 'request_invalid', 'status' => 0];
        $ch = curl_init('https://openrouter.ai/api/v1/chat/completions');
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $encoded,
            CURLOPT_RETURNTRANSFER => true, CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 25,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . OPENROUTER_API_KEY],
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$headers): int {
                if (str_starts_with($line, 'HTTP/')) $headers = [];
                $parts = explode(':', $line, 2);
                if (count($parts) === 2 && in_array(strtolower(trim($parts[0])), ['retry-after', 'x-ratelimit-reset'], true)) {
                    $headers[strtolower(trim($parts[0]))] = substr(trim($parts[1]), 0, 128);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$body, &$overflow): int {
                if (strlen($body) + strlen($chunk) > 65536) { $overflow = true; return 0; }
                $body .= $chunk; return strlen($chunk);
            }]);
        $ok = curl_exec($ch); $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $result = ['status' => $status, 'body' => $body, 'headers' => $headers];
        if ($overflow) $result['reason'] = 'invalid_response';
        elseif ($ok === false) $result['reason'] = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'service_unavailable';
        return $result;
    } catch (Throwable $error) {
        return ['reason' => 'service_unavailable', 'status' => 0];
    } finally { if ($ch !== null && $ch !== false) curl_close($ch); }
}

/** Only harmless presentation changes. HTML, instructions, links and JSON are not repaired away. */
function openrouter_normalize_text(string $text, bool $plain): string
{
    $text = trim(str_replace(["\r\n", "\r", "\xEF\xBB\xBF"], ["\n", "\n", ''], $text));
    $text = preg_replace('~\A\s*<think>.*?</think>\s*~su', '', $text);
    if ($plain) {
        if (preg_match('/\A```(?:text|plaintext|markdown)?\s*\n(.*?)\n```\z/su', $text, $match)) $text = trim($match[1]);
        $text = preg_replace('/^\s{0,3}#{1,6}\s+|^\s*[-*]\s+/mu', '', $text);
        $text = preg_replace('/\*\*([^*\n]+)\*\*|__([^_\n]+)__/u', '$1$2', $text);
    }
    return trim($text);
}

function openrouter_task_decode(int $status, string $body, array $headers = [], bool $plain = false): array
{
    $base = ['status' => $status, 'model' => null, 'usage' => [], 'retryAfter' => openrouter_retry_after($headers), 'recoverable' => false];
    try { $value = strlen($body) <= 65536 && mb_check_encoding($body, 'UTF-8') ? json_decode($body, true, 32, JSON_THROW_ON_ERROR) : null; }
    catch (Throwable $error) { $value = null; }
    if (!is_array($value)) $value = [];
    $model = $value['model'] ?? null;
    if (is_string($model) && strlen($model) <= 160 && preg_match('~\A[a-zA-Z0-9._:/-]+\z~', $model)) $base['model'] = $model;
    elseif ($model !== null) return ['reason' => 'unexpected_model'] + $base;
    foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $field) {
        if (is_int($value['usage'][$field] ?? null) && $value['usage'][$field] >= 0) $base['usage'][$field] = $value['usage'][$field];
    }
    $reasoningTokens = $value['usage']['completion_tokens_details']['reasoning_tokens'] ?? null;
    if (is_int($reasoningTokens) && $reasoningTokens >= 0) $base['usage']['reasoning_tokens'] = $reasoningTokens;
    $error = $value['error'] ?? ($value['choices'][0]['error'] ?? null);
    $code = is_array($error) ? ($error['code'] ?? $status) : $status;
    if (is_numeric($code)) $code = (int)$code;
    // Inspect provider errors for classification only. Never return or log their messages or flagged input.
    $errorText = is_array($error) ? strtolower(json_encode($error) ?: '') : '';
    if ($error !== null || $status !== 200) {
        if (in_array($code, [401, 402, 403], true)) $reason = [401 => 'service_auth', 402 => 'service_credits', 403 => 'service_forbidden'][$code];
        elseif (preg_match('/moderation|content[_ -]?(?:filter|policy)|guardrail|privacy|permission|unauthorized/', $errorText)) $reason = 'service_forbidden';
        elseif ($code === 429) {
            if (preg_match('/free-models-per-day|daily.{0,60}(?:quota|limit)|(?:quota|limit).{0,60}(?:per.day|daily)|account.{0,40}quota/', $errorText)) {
                $reason = 'account_quota';
                $reset = $error['metadata']['resets_at'] ?? ($error['metadata']['reset_at'] ?? ($headers['x-ratelimit-reset'] ?? null));
                $resetTime = is_numeric($reset) ? (float)$reset : (is_string($reset) ? strtotime($reset) : false);
                if ($resetTime > 100000000000) $resetTime /= 1000;
                // A known daily limit without reset metadata blocks for 24 hours, rather than probing it repeatedly.
                $base['retryAfter'] = max($base['retryAfter'], $resetTime > time() ? min(PHP_INT_MAX - time(), (int)ceil($resetTime - time())) : 86400);
            }
            elseif (preg_match('/provider_name|upstream|provider.{0,40}(?:throttl|rate.limit)/', $errorText)) { $reason = 'provider_rate_limited'; $base['recoverable'] = true; }
            else $reason = 'rate_limited';
        } elseif (in_array($code, [404, 408, 500, 502, 503, 504], true)) {
            $reason = $code === 404 ? 'routing_unavailable' : (in_array($code, [408, 504], true) ? 'timeout' : 'provider_unavailable');
            $base['recoverable'] = true;
        } else $reason = 'service_unavailable';
        return ['reason' => $reason] + $base;
    }
    $choice = $value['choices'][0] ?? null; $message = is_array($choice) ? ($choice['message'] ?? null) : null;
    if (!is_array($choice) || !is_array($message) || (($message['role'] ?? 'assistant') !== 'assistant')) return ['reason' => 'invalid_response', 'recoverable' => true] + $base;
    $finish = $choice['finish_reason'] ?? null;
    $nativeFinish = strtolower(is_string($choice['native_finish_reason'] ?? null) ? $choice['native_finish_reason'] : '');
    $base['finishReason'] = in_array($finish, ['stop', 'length', 'content_filter', 'tool_calls', 'error', 'function_call'], true) ? $finish : null;
    if (!empty($message['refusal']) || $finish === 'content_filter'
        || in_array($nativeFinish, ['safety', 'content_filter', 'prohibited_content', 'spii', 'blocklist', 'recitation'], true)) return ['reason' => 'content_refused'] + $base;
    $text = $message['content'] ?? null;
    if (is_array($text)) {
        $parts = [];
        foreach ($text as $part) {
            if (is_array($part) && ($part['type'] ?? '') === 'refusal') return ['reason' => 'content_refused'] + $base;
            if (!is_array($part) || ($part['type'] ?? '') !== 'text' || !is_string($part['text'] ?? null)) return ['reason' => 'invalid_response', 'recoverable' => true] + $base;
            $parts[] = $part['text'];
        }
        $text = implode("\n", $parts);
    }
    $hadContent = is_string($text) && trim($text) !== '';
    $text = is_string($text) ? openrouter_normalize_text($text, $plain) : '';
    if (preg_match('/\A(?:(?:(?:I(?: am|\x{2019}m|\x27m)?\s+)?sorry[,.:]?\s*(?:but\s+)?)?(?:I|we)\s+(?:cannot|can[\x27\x{2019}]t|will not|must decline|am unable to|are unable to)\s+(?:assist|help|provide|summari[sz]e|process|comply)|(?:request|content)\s+(?:is\s+)?(?:blocked|refused))/iu', $text)) return ['reason' => 'content_refused'] + $base;
    if (preg_match('/\A\s*(?:\{\s*"(?:safe|unsafe|label|category|classification|flagged)"\s*:|(?:safety|moderation)\s*(?:classification|category|label)\s*:)/iu', $text)
        || preg_match('/\A(?:safe|unsafe)\s*(?:\n\s*S\d+(?:\s*,\s*S\d+)*)?\s*\z/i', $text)) {
        // A classifier response is never a summary. An unsafe verdict may be a refusal, so stop there.
        $blocked = (bool)preg_match('/\b(?:unsafe|harmful|prohibited)\b|"flagged"\s*:\s*true|"safe"\s*:\s*false/i', $text);
        return ['reason' => 'output_classification', 'recoverable' => !$blocked] + $base;
    }
    if ($finish === 'length' || in_array($nativeFinish, ['max_tokens', 'length'], true)) return ['reason' => 'output_truncated', 'recoverable' => true] + $base;
    if ($finish !== 'stop' || !empty($message['tool_calls']) || !empty($message['function_call'])) return ['reason' => 'invalid_response', 'recoverable' => true] + $base;
    if ($text === '') return ['reason' => $hadContent || !empty($message['reasoning']) || !empty($message['reasoning_details']) ? 'reasoning_only' : 'output_empty', 'recoverable' => true] + $base;
    if (str_contains($text, '<think>') || preg_match('/\A(?:analysis|reasoning)\s*:/i', $text)) return ['reason' => 'reasoning_only', 'recoverable' => true] + $base;
    if (strlen($text) > ($plain ? 6000 : 16000) || !mb_check_encoding($text, 'UTF-8') || preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/', $text)) return ['reason' => 'output_invalid', 'recoverable' => true] + $base;
    // A stop reason alone cannot make a visibly unfinished last sentence usable.
    if (!preg_match('/[.!?\x{2026}][\x{201d}\x{2019}"\x27)]*\s*\z/u', $text)) return ['reason' => 'output_incomplete', 'recoverable' => true] + $base;
    if (($base['usage']['completion_tokens'] ?? null) === 0) return ['reason' => 'output_empty', 'recoverable' => true] + $base;
    return ['reason' => 'ai_success', 'text' => $text] + $base;
}

function openrouter_task_log(string $task, array $models, array $result, bool $fallback, int $attempts): void
{
    if (!function_exists('log_api_error')) return;
    log_api_error('openrouter_task', json_encode(['task' => $task, 'requested_models' => $models,
        'successful_model' => $result['reason'] === 'ai_success' ? ($result['model'] ?? null) : null,
        'returned_model' => $result['model'] ?? null, 'status' => $result['status'] ?? 0,
        'category' => $result['reason'], 'fallback' => $fallback, 'attempts' => $attempts,
        'finish_reason' => $result['finishReason'] ?? null, 'usage' => $result['usage'] ?? []], JSON_UNESCAPED_SLASHES));
}

/**
 * Native multi-model fallback also retries moderation refusals (OpenRouter documentation).
 * Use one models entry per call to honor PRISM's no-refusal-retry rule. Standard provider
 * failover remains enabled by omission; no provider privacy/routing overrides are sent.
 * At most two HTTP calls, never the same model twice, with the same factual requirements.
 */
function openrouter_task_generate(string $task, array $messages, callable $validate, ?array $models = null, int $maxTokens = 1800): array
{
    $approved = openrouter_task_models($task); $models = $models ?? $approved;
    if (!$models || count($models) > 2 || count(array_unique($models)) !== count($models) || array_diff($models, $approved)) {
        $result = ['reason' => 'model_invalid']; openrouter_task_log($task, $approved, $result, false, 0); return $result;
    }
    if (!defined('OPENROUTER_API_KEY') || trim(OPENROUTER_API_KEY) === '' || !function_exists('curl_init')) {
        $result = ['reason' => 'not_configured']; openrouter_task_log($task, $models, $result, false, 0); return $result;
    }
    if ($cooldown = openrouter_task_cooldown()) {
        $result = ['reason' => $cooldown['reason'], 'retryAfter' => max(1, $cooldown['until'] - time())];
        openrouter_task_log($task, $models, $result, false, 0); return $result;
    }
    $attempted = []; $calls = 0; $last = ['reason' => 'service_unavailable'];
    foreach ($models as $model) {
        if (in_array($model, $attempted, true)) continue;
        $attempted[] = $model;
        $payload = ['models' => [$model], 'messages' => $messages, 'temperature' => 0.2,
            'max_tokens' => max(800, min(3000, $maxTokens)), 'stream' => false];
        if (!empty(openrouter_task_catalog()[$model]['reasoning'])) $payload['reasoning'] = ['enabled' => false];
        $http = openrouter_task_http($payload); $calls++;
        $last = isset($http['reason']) && (($http['status'] ?? 0) === 0 || ($http['status'] ?? 0) === 200) ? ['reason' => $http['reason'], 'status' => $http['status'] ?? 0,
            'recoverable' => in_array($http['reason'], ['timeout', 'invalid_response', 'service_unavailable'], true)]
            : openrouter_task_decode($http['status'], $http['body'] ?? '', $http['headers'] ?? [], $task === 'document_summary');
        $actual = $last['model'] ?? null; $actualKey = openrouter_model_key($actual, $approved);
        if ($actual !== null && $actualKey === null) $last = ['reason' => 'unexpected_model', 'recoverable' => false, 'status' => $last['status'] ?? 200];
        if ($actualKey !== null && !in_array($actualKey, $attempted, true)) $attempted[] = $actualKey;
        if ($last['reason'] === 'ai_success') {
            $checked = $validate($last['text']);
            if (is_string($checked) && trim($checked) !== '') $last['text'] = $checked;
            else { unset($last['text']); $last['reason'] = is_array($checked) ? ($checked['reason'] ?? 'output_invalid') : 'output_invalid';
                $last['recoverable'] = $last['reason'] === 'output_invalid'; }
        }
        $fallback = $calls > 1 || ($actualKey !== null && $actualKey !== $models[0]);
        openrouter_task_log($task, $models, $last, $fallback, $calls);
        if ($last['reason'] === 'ai_success') return $last + ['fallback' => $fallback, 'attempts' => $calls];
        $retryAfter = $last['retryAfter'] ?? openrouter_retry_after($http['headers'] ?? []);
        if ($last['reason'] === 'account_quota' || $retryAfter > 0 || $last['reason'] === 'rate_limited') {
            $delay = max($retryAfter, $last['reason'] === 'account_quota' ? 3600 : ($last['reason'] === 'rate_limited' ? 60 : 0));
            openrouter_task_cooldown(['reason' => $last['reason'] === 'account_quota' ? 'account_quota' : ($last['reason'] === 'rate_limited' ? 'rate_limited' : 'retry_after'), 'until' => time() + $delay]);
            $last['retryAfter'] = $delay;
            break;
        }
        if (empty($last['recoverable'])) break;
    }
    unset($last['text']);
    return $last + ['fallback' => $calls > 1, 'attempts' => $calls];
}
