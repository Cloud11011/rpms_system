<?php
/** Existing TEXT field, with plain-text legacy summaries remaining readable. */
function document_summary_record(?string $stored): array
{
    $record = json_decode((string)$stored, true);
    if (is_array($record) && ($record['format'] ?? '') === 'prism_document_summary_v1'
        && is_string($record['summary'] ?? null)
        && in_array($record['source'] ?? null, ['openrouter_ai', 'local_fallback'], true)) {
        return ['summary' => $record['summary'], 'source' => $record['source'], 'partial' => !empty($record['partial'])];
    }
    return ['summary' => $stored, 'source' => null, 'partial' => false];
}

function document_summary_encode(array $result): string
{
    return json_encode(['format' => 'prism_document_summary_v1', 'summary' => $result['summary'],
        'source' => $result['source'], 'partial' => !empty($result['partial'])], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
}