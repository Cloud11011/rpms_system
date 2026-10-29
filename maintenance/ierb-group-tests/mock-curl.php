<?php
if (!in_array(PHP_SAPI, ['cli', 'cli-server'], true)) exit; // Disposable fixture server only (B7-F21).
// PHP builtins are disabled only in the disposable fixture server. No network calls occur.
class FixtureCurlHandle { public array $options = []; public int $status = 200; }
if (!function_exists('curl_init')) {
function curl_init(?string $url = null): FixtureCurlHandle { return new FixtureCurlHandle(); }
function curl_setopt_array($handle, array $options): bool { $handle->options = $options; return true; }
function curl_exec($handle) {
    $scenario = trim((string)@file_get_contents(PRISM_TEST_REVIEW_DIR . '/sandbox/provider-scenario.txt')) ?: 'success';
    $handle->status = $scenario === 'rate' ? 429 : ($scenario === 'model' ? 404 : 200);
    if ($scenario === 'network') return false;
    $request = json_decode($handle->options[CURLOPT_POSTFIELDS] ?? '{}', true);
    file_put_contents(PRISM_TEST_REVIEW_DIR . '/sandbox/provider-calls.ndjson', json_encode(['scenario' => $scenario, 'model' => $request['model'] ?? null,
        'hasMaxTokens' => array_key_exists('max_tokens', $request), 'inputCharacters' => mb_strlen($request['messages'][1]['content'] ?? '')]) . "\n", FILE_APPEND);
    $isReport = !str_starts_with($request['messages'][0]['content']??'','Summarize the research excerpt')
        && str_contains($request['messages'][0]['content'] ?? '', 'progress');
    $content = $isReport
        ? "## Progress snapshot\nTwo active records are recorded at Stage 1. Submission dates are not recorded. Optional verification can clarify missing information."
        : 'This descriptive quantitative study examined 40 fictional research transactions. Average retrieval time was 12 minutes manually and 4 minutes with centralized retrieval. The findings describe this synthetic sample only; the small sample and absence of randomization limit generalization.';
    if (str_starts_with($request['messages'][0]['content']??'','Organize a concise progress assessment')) {
        file_put_contents(PRISM_TEST_REVIEW_DIR.'/sandbox/project-ai-request.json',json_encode($request));
        if ($scenario==='project-success') $content=json_encode(['sentenceKeys'=>['stage','cycles','verification']]);
        if ($scenario==='project-stale') db()->exec("UPDATE research_projects SET research_title=CONCAT(research_title,' changed during provider call')");
    }
    $response = $scenario === 'malformed' ? '{bad json' : json_encode(['choices' => [['finish_reason' => $scenario === 'incomplete' ? 'length' : 'stop', 'message' => ['content' => $scenario === 'empty' ? '' : $content]]]]);
    $header = $handle->options[CURLOPT_HEADERFUNCTION] ?? null;
    if ($header && $handle->status === 429) $header($handle, "Retry-After: 30\r\n");
    $write = $handle->options[CURLOPT_WRITEFUNCTION] ?? null;
    if ($write) { $write($handle, $response); return true; }
    return $response;
}
function curl_getinfo($handle, ?int $option = null) { return $option === CURLINFO_HTTP_CODE ? $handle->status : ['http_code' => $handle->status]; }
function curl_errno($handle): int { return 0; }
function curl_error($handle): string { return 'Synthetic network failure'; }
function curl_close($handle): void {}
}
