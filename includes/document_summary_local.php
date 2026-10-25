<?php
/** Bounded, deterministic research extraction. No configuration, database or network access. */
function summary_research_heading(string $line): ?array
{
    $line = preg_replace('/^(?:(?:chapter\s+(?:[ivxlcdm]+|\d+)\s*[:.\-]?\s*)|(?:\d+(?:\.\d+)*[.)]?\s+))/iu', '', trim($line));
    $labels = [
        'abstract' => 'abstract|executive summary',
        'overview' => 'introduction|background(?: of the study)?|research (?:topic|problem)|statement of the problem|objectives?(?: of the study)?|purpose(?: of the study)?',
        'methods' => 'methodology|methods?(?: and materials)?|materials and methods|research (?:design|methodology)|data (?:collection|analysis)|procedures?',
        'findings' => 'results?(?: and discussion)?|findings?(?: and discussion)?|key findings|discussion|conclusions?(?: and recommendations)?|limitations?(?: of the study)?|summary of findings',
        'skip' => 'approval(?: sheet| page)?|recommendation(?: sheet| page)?|acknowledg(?:e)?ments?|dedication|table of contents|contents|list of (?:figures|tables)|references|bibliography|appendi(?:x|ces)(?:\s+[a-z0-9]+)?|attachments?',
    ];
    foreach ($labels as $section => $pattern) {
        if (preg_match('/^(?:' . $pattern . ')(?:\s*[:\x{2013}\x{2014}]\s*(.*)|\s*)$/iu', $line, $m)) {
            return [$section, trim($m[1] ?? '')];
        }
    }
    return null;
}

/** Keep line boundaries until headings, contents entries and running furniture are classified. */
function summary_research_content(string $text): array
{
    $text = summary_utf8_text(mb_substr($text, 0, DOCUMENT_SUMMARY_MAX_TEXT_BYTES));
    $text = preg_replace('/\x{00ad}|[\x{200b}-\x{200f}\x{202a}-\x{202e}\x{2060}-\x{206f}]/u', '', $text);
    $text = preg_replace('/(\p{L})-\n(?=\p{Ll})/u', '$1', $text);
    $lines = explode("\n", $text); $frequency = [];
    foreach ($lines as $line) {
        $key = mb_strtolower(trim($line));
        if ($key !== '' && mb_strlen($key) < 110) $frequency[$key] = ($frequency[$key] ?? 0) + 1;
    }
    $blocks = []; $section = 'general'; $buffer = ''; $title = ''; $researchSeen = false;
    $flush = static function () use (&$blocks, &$buffer, &$section): void {
        if (trim($buffer) !== '') $blocks[] = ['section' => $section, 'text' => trim($buffer)];
        $buffer = '';
    };
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') { $flush(); continue; }
        if (preg_match('/^(?:[ivxlcdm]{1,9}|(?:page\s*)?\d+(?:\s*(?:of|\/)\s*\d+)?|chapter\s+(?:[ivxlcdm]+|\d+))\s*[.\-]?$/iu', $line)
            || preg_match('/\.{3,}\s*(?:\d+|[ivxlcdm]+)\s*$|^[_=\-\s]{4,}$/iu', $line)) continue;
        // Contents entries with right-aligned page numbers are never section starts.
        if (preg_match('/^(.{3,100}?)\s+\d+$/u', $line, $entry) && summary_research_heading($entry[1]) !== null) continue;
        $heading = summary_research_heading($line);
        if ($heading !== null) {
            $flush(); [$section, $inline] = $heading;
            if ($section !== 'skip') $researchSeen = true;
            $buffer = $inline;
            continue;
        }
        // A title can be recovered even from a single flattened cover/approval page.
        $cover = preg_split('/\b(?:an?\s+(?:undergraduate|graduate)\s+research|in partial fulfill?ment|approval sheet|presented to the)\b/iu', $line, 2)[0];
        $cover = trim(preg_replace('/^(?:[ivxlcdm]{1,9}\s+|title\s*:\s*)/iu', '', $cover));
        if (!$researchSeen && mb_strlen($cover) >= 15 && mb_strlen($cover) <= 240
            && (mb_strtoupper($cover) === $cover || preg_match('/^title\s*:/iu', $line) || $cover !== $line && str_contains($line, 'Undergraduate Research'))
            && !preg_match('/[.!?]\s|\b(?:university|department|college|researcher|advis[eo]r|submitted by|prepared by)\b/iu', $cover)) {
            if ($title === '') $title = $cover;
            $flush(); continue;
        }
        if ($section === 'skip') continue;
        if (($frequency[mb_strtolower($line)] ?? 0) >= 3 && !preg_match('/[.!?]$/u', $line)) continue;
        if (preg_match('/\b(?:in partial fulfill?ment|degree of bachelor|presented to the|recommend(?:ed|ation)? for (?:the )?oral|oral examination|panel of examiners|submitted by|prepared by|an? undergraduate research presented)\b|^(?:approved by|signature\s*[:=]|signed by)/iu', $line)) { $flush(); continue; }
        $buffer .= ($buffer === '' ? '' : ' ') . $line;
    }
    $flush();
    return ['blocks' => $blocks, 'title' => $title];
}

function summary_local_words(string $text): array
{
    preg_match_all('/[\p{L}\p{N}]+(?:[\x{2019}\x27-][\p{L}\p{N}]+)*/u', mb_strtolower($text), $m);
    return $m[0];
}

function summary_local_similar(array $a, array $b): bool
{
    // Similar wording with different measurements is evidence, not a duplicate.
    $numbersA = array_values(array_filter($a, static fn($word) => is_numeric($word)));
    $numbersB = array_values(array_filter($b, static fn($word) => is_numeric($word)));
    sort($numbersA); sort($numbersB);
    if ($numbersA !== $numbersB) return false;
    if ((bool)array_intersect($a, ['not','no','cannot','never','without']) !== (bool)array_intersect($b, ['not','no','cannot','never','without'])) return false;
    $intersection = count(array_intersect($a, $b));
    return $intersection / max(1, count(array_unique(array_merge($a, $b)))) >= 0.72
        || $intersection / max(1, min(count($a), count($b))) >= 0.9;
}

function research_local_summary(string $text): string
{
    $content = summary_research_content($text); $candidates = []; $seen = []; $index = 0;
    foreach ($content['blocks'] as $block) {
        $sentences = preg_split('/(?<=[.!?])\s+(?=[\p{Lu}\p{N}\x{201c}"])|\n+/u', $block['text'], -1, PREG_SPLIT_NO_EMPTY);
        foreach ($sentences as $sentence) {
            $sentence = trim(preg_replace('/\s+/u', ' ', $sentence));
            $words = summary_local_words($sentence); $count = count($words);
            if ($count < 6 || $count > 70 || preg_match('/[<>]|\[(?:[^\]]*removed|Later document excerpt|Final document excerpt)\]/iu', $sentence)
                || summary_sensitive_text($sentence)
                || preg_match('/\b(?:researcher names?|degree requirements?|bachelor of|partial fulfill?ment|oral examination|approval sheet|recommended for|acknowledg(?:e)?ments?|dedicated to)\b|^(?:researcher|author|advis[eo]r|dean|panel member|prepared by|submitted by)\s*[:=]?\s+\p{Lu}|\b(?:api[_ -]?key|password|access[_ -]?token|signature)\s*[:=]/iu', $sentence)
                || preg_match('/\b(?:[A-Z][a-z]+\s+){1,3}[A-Z][a-z]+\s*,\s*(?:Ph\.?D|MS|MA|MIT)\b/u', $sentence)) continue;
            // Require a prose clause, rather than a title, author list, or numeric table row.
            if (!preg_match('/\b(?:is|are|was|were|has|have|had|may|can|cannot|could|should|will|uses?|used|aim\w*|examin\w*|evaluat\w*|investigat\w*|assess\w*|develop\w*|compar\w*|involv\w*|analy[sz]\w*|collect\w*|averag\w*|show\w*|found|find\w*|report\w*|indicat\w*|suggest\w*|conclud\w*|reveal\w*|demonstrat\w*|describ\w*|reduc\w*|increas\w*|decreas\w*|improv\w*|support\w*|remain\w*|limit\w*|complet\w*|participat\w*|includ\w*|focus\w*|determin\w*|provid\w*|record\w*|track\w*|measur\w*|address\w*)\b/iu', $sentence)) continue;
            $purpose = (bool)preg_match('/\b(?:aim\w*|purpose|objectives?|research problem|study (?:examines|investigates|evaluates)|seeks? to)\b/iu', $sentence);
            $methods = (bool)preg_match('/\b(?:method\w*|descriptive|quantitative|qualitative|mixed.methods?|research design|sample|sampling|interviews?|surveys?|questionnaires?|transactions?|data collection)\b/iu', $sentence);
            $finding = (bool)preg_match('/\b(?:results?|findings?|found|averag\w*|retrieval time|conclud\w*|decreas\w*|increas\w*|compar\w*)\b/iu', $sentence);
            $limitation = (bool)preg_match('/\b(?:limit\w*|synthetic|fictional|simulated|cannot|does not establish|no (?:randomization|statistical)|not (?:reported|established)|only this)\b/iu', $sentence);
            $section = match ($block['section']) {
                'methods' => 'methods', 'findings' => 'findings',
                default => $purpose ? 'overview' : ($finding || ($limitation && !$methods) ? 'findings' : ($methods ? 'methods' : 'overview')),
            };
            $score = 2 + (int)$purpose * 7 + (int)$methods * 6 + (int)$finding * 7 + (int)$limitation * 6
                + (int)(bool)preg_match('/\d/u', $sentence) * 3
                + ($block['section'] === 'abstract' ? 8 : ($block['section'] !== 'general' ? 4 : 0));
            if (!preg_match('/[.!?]$/u', $sentence)) { $score -= 3; $sentence .= '.'; }
            $tokens = array_values(array_unique($words));
            $key = implode(' ', $words);
            if (isset($seen[$key])) continue;
            $seen[$key] = true;
            $candidates[] = compact('sentence', 'section', 'score', 'tokens', 'count', 'index', 'limitation'); $index++;
        }
    }
    if (!$candidates) {
        $title = $content['title'];
        if ($title === '' || summary_sensitive_text($title) || preg_match('/\[|[<>@]|\b(?:password|api.key)\b/iu', $title)) return '';
        if (mb_strtoupper($title) === $title) $title = mb_convert_case($title, MB_CASE_TITLE, 'UTF-8');
        return "Overview\n\nThe document identifies the research topic as \"" . rtrim($title, '.:') . "\".\n\nFindings and Limitations\n\nThe available extracted text primarily contains preliminary information. The study's methodology and findings cannot be determined from this content alone.";
    }
    usort($candidates, static fn($a, $b) => ($b['score'] <=> $a['score']) ?: ($a['index'] <=> $b['index']));
    $selected = []; $total = 0;
    $take = static function (array $candidate) use (&$selected, &$total): bool {
        if (isset($selected[$candidate['index']]) || $total + $candidate['count'] > 175) return false;
        foreach ($selected as $previous) if (summary_local_similar($candidate['tokens'], $previous['tokens'])) return false;
        $selected[$candidate['index']] = $candidate; $total += $candidate['count']; return true;
    };
    // Reserve coverage before filling the budget; a long abstract cannot crowd out later methods/results.
    foreach (['overview', 'methods', 'findings'] as $section) {
        foreach ($candidates as $candidate) if ($candidate['section'] === $section && $take($candidate)) break;
    }
    foreach ($candidates as $candidate) if ($candidate['limitation'] && $take($candidate)) break;
    if (count($candidates) > 12 && !array_filter($content['blocks'], static fn($block) => $block['section'] !== 'general')) {
        foreach ([1, 2] as $region) {
            foreach ($candidates as $candidate) {
                if ((int)floor($candidate['index'] * 3 / count($candidates)) === $region && $take($candidate)) break;
            }
        }
    }
    foreach ($candidates as $candidate) {
        if ($total >= 145 || count($selected) >= 8) break;
        $take($candidate);
    }
    $paragraphs = [];
    foreach (['overview' => 'Overview', 'methods' => 'Methodology', 'findings' => 'Findings and Limitations'] as $section => $label) {
        $group = array_filter($selected, static fn($candidate) => $candidate['section'] === $section);
        if (!$group) continue;
        usort($group, static fn($a, $b) => $a['index'] <=> $b['index']);
        $paragraphs[] = $label . "\n\n" . implode(' ', array_column($group, 'sentence'));
    }
    return implode("\n\n", $paragraphs);
}
