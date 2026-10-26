<?php
require_once __DIR__ . '/openrouter_tasks.php';

/** Generate before persistence. No report row or PDF is created by either HTTP attempt. */
function generate_progress_report_narrative(array $students, string $mode): array
{
    $prompt = $mode === 'full' ? AI_FULL_PROMPT : AI_SUMMARY_PROMPT;
    $structured = "Scope: all active student records visible to RPMS administration.\n" . build_structured_progress_text($students);
    $response = openrouter_task_generate('progress_report', [
        ['role' => 'system', 'content' => $prompt], ['role' => 'user', 'content' => $structured],
    ], static function (string $text) use ($students, $mode) {
        if (!ai_narrative_excludes_names($text, $students)
            || preg_match('/[A-Z0-9.!#$%&\x27*+\/=?^_`{|}~-]+@[A-Z0-9.-]+\.[A-Z]{2,}|\b(?:sk-or-v1-|Bearer\s+\S+)/i', $text)) return ['reason' => 'privacy_output'];
        if (preg_match('/<\/?[a-z][^>]*>|\b(?:ignore\s+(?:all\s+)?(?:previous|system)\s+instructions|reveal\s+(?:the\s+)?secrets?)\b/iu', $text)) return ['reason' => 'policy_output'];
        $text = strip_report_markdown_tables($text);
        $words = preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        if (mb_strlen($text) < 60 || count($words) < 12 || count($words) > ($mode === 'full' ? 550 : 240)) return null;
        return $text;
    }, null, $mode === 'full' ? 2500 : 1400);
    $aiUsed = $response['reason'] === 'ai_success';
    return ['narrative' => $aiUsed ? $response['text'] : ($mode === 'full' ? local_full_narrative($students) : local_summary_narrative($students)),
        'aiUsed' => $aiUsed, 'model' => $aiUsed ? ($response['model'] ?? null) : null,
        'modelLabel' => $aiUsed ? openrouter_model_label($response['model'] ?? null) : null,
        'reason' => $response['reason'], 'retryAfter' => $response['retryAfter'] ?? 0];
}
