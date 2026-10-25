<?php
/** Reuse the local engine's research headings without changing its offline summary algorithm. */
function document_summary_research_excerpt(string $text): array
{
    $explicitTitle = null;
    if (preg_match('/^\s*title\s*:\s*([^\n]{4,300})$/imu', $text, $match)) {
        $explicitTitle = trim($match[1]);
        $text = preg_replace('/^\s*title\s*:\s*[^\n]{4,300}$/imu', '', $text, 1);
    }
    $content = summary_research_content($text);
    $title = $explicitTitle ?? $content['title'];
    $labels = ['abstract' => 'Abstract', 'overview' => 'Purpose and objectives', 'methods' => 'Methodology',
        'findings' => 'Results, conclusions and limitations', 'general' => 'Research context'];
    $groups = [];
    foreach ($content['blocks'] as $block) {
        $passage = trim($block['text']);
        if ($passage === '' || ($block['section'] === 'general' && count(summary_local_words($passage)) < 4)) continue;
        $groups[$block['section']][] = $passage;
    }
    $preliminaryOnly = !$groups;
    if (!$groups && $title === '' && trim($text) !== '') $groups['general'] = [summary_utf8_text($text)];
    $parts = $title !== '' ? ['Research title: ' . $title] : [];
    $remaining = DOCUMENT_SUMMARY_MAX_INPUT_CHARS - mb_strlen(implode("\n\n", $parts)) - 400;
    $weights = ['abstract' => 24, 'overview' => 18, 'methods' => 25, 'findings' => 29, 'general' => 10];
    $totalWeight = array_sum(array_intersect_key($weights, $groups)); $partial = false; $budgets = [];
    foreach ($groups as $section => $passages) $budgets[$section] = min(mb_strlen(implode("\n\n", $passages)), (int)floor($remaining * $weights[$section] / max(1, $totalWeight)));
    $unused = $remaining - array_sum($budgets);
    foreach ($labels as $section => $label) {
        if (!isset($budgets[$section])) continue;
        $extra = min($unused, mb_strlen(implode("\n\n", $groups[$section])) - $budgets[$section]);
        $budgets[$section] += $extra; $unused -= $extra;
    }
    foreach ($labels as $section => $label) {
        if (empty($groups[$section])) continue;
        $budget = $budgets[$section];
        $passages = $groups[$section]; $joined = implode("\n\n", $passages);
        if (mb_strlen($joined) > $budget) {
            $partial = true;
            // Reserve the first, middle and final passages: conclusions cannot be crowded out by results.
            $count = min(6, count($passages)); $positions = [];
            for ($i = 0; $i < $count; $i++) $positions[] = $count === 1 ? 0 : (int)round($i * (count($passages) - 1) / ($count - 1));
            $perPassage = max(40, (int)floor($budget / max(1, $count)) - 40); $chosen = [];
            foreach (array_unique($positions) as $position) {
                $passage = $passages[$position];
                if (mb_strlen($passage) > $perPassage) {
                    $window = max(15, (int)floor(($perPassage - 50) / 3)); $windows = [];
                    foreach ([0, max(0, (int)(mb_strlen($passage) / 2) - (int)($window / 2)), max(0, mb_strlen($passage) - $window)] as $offset) {
                        $piece = mb_substr($passage, $offset, $window);
                        if ($offset > 0) $piece = preg_replace('/^\S*\s*/u', '', $piece);
                        if ($offset + $window < mb_strlen($passage)) $piece = preg_replace('/\s+\S*$/u', '', $piece);
                        $windows[] = trim($piece);
                    }
                    $passage = implode("\n[Passage omitted]\n", $windows);
                }
                $chosen[] = $passage;
            }
            $joined = implode("\n[Passage omitted]\n", $chosen);
        }
        $parts[] = $label . ":\n" . $joined;
    }
    $excerpt = implode("\n\n", $parts);
    if (mb_strlen($excerpt) > DOCUMENT_SUMMARY_MAX_INPUT_CHARS) {
        $partial = true; $excerpt = mb_substr($excerpt, 0, DOCUMENT_SUMMARY_MAX_INPUT_CHARS);
    }
    return ['excerpt' => $excerpt, 'partial' => $partial, 'preliminaryOnly' => $preliminaryOnly];
}
