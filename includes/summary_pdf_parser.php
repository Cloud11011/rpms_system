<?php
/**
 * Small compatibility/budget adapter around the locked mature PDF parser, not a PDF parser.
 * v2.12.5's CMap regex requires whitespace before endbfrange. Compact publisher CMaps
 * can otherwise be misread as millions of ranges inside a ligature array.
 * Normalize that legal syntax before the library initializes fonts; never modify vendor files.
 */
final class SummaryPdfParser extends \Smalot\PdfParser\Parser
{
    private int $objectCount = 0;
    private int $streamBytes = 0;
    private int $mapEntries = 0;
    private float $started;

    public function __construct(\Smalot\PdfParser\Config $config)
    { parent::__construct([], $config); $this->started = microtime(true); }

    protected function parseObject(string $id, array $structure, ?\Smalot\PdfParser\Document $document = null)
    {
        if (++$this->objectCount > 15000 || microtime(true) - $this->started > 15) throw new DocumentSummaryError('pdf_budget', 'The PDF exceeds safe processing limits.');
        foreach ($structure as &$part) {
            if (!is_array($part) || ($part[0] ?? null) !== 'stream') continue;
            $decoded = isset($part[3][0]);
            $content = $decoded ? $part[3][0] : $part[1];
            $this->streamBytes += strlen($content);
            if ($this->streamBytes > 64 * 1024 * 1024) throw new DocumentSummaryError('pdf_budget', 'The PDF exceeds safe processing limits.');
            if (!str_contains($content, 'begincmap')) continue;
            if (strlen($content) > 1500000) throw new DocumentSummaryError('pdf_budget', 'The PDF font mapping exceeds safe processing limits.');
            $content = preg_replace('/([>\]])(end(?:bfrange|bfchar|codespacerange))\b/', '$1 $2', $content);
            preg_match_all('/beginbfchar(.*?)endbfchar/s', $content, $characters);
            foreach ($characters[1] as $section) $this->mapEntries += substr_count($section, '<');
            preg_match_all('/beginbfrange(.*?)endbfrange/s', $content, $sections);
            foreach ($sections[1] as $section) {
                preg_match_all('/<([0-9a-f]+)>\s*<([0-9a-f]+)>\s*(<[0-9a-f]+>|\[[\s<>0-9a-f]+\])/i', $section, $ranges, PREG_SET_ORDER);
                foreach ($ranges as $range) {
                    $from = hexdec($range[1]); $to = hexdec($range[2]);
                    if ($to < $from || $to - $from > 65535) throw new DocumentSummaryError('pdf_budget', 'The PDF font mapping exceeds safe processing limits.');
                    $this->mapEntries += (int)($to - $from + 1);
                    if (str_starts_with($range[3], '[')) $this->mapEntries += substr_count($range[3], '<');
                    if ($this->mapEntries > 100000) throw new DocumentSummaryError('pdf_budget', 'The PDF font mapping exceeds safe processing limits.');
                }
            }
            if ($this->mapEntries > 100000) throw new DocumentSummaryError('pdf_budget', 'The PDF font mapping exceeds safe processing limits.');
            if ($decoded) $part[3][0] = $content; else $part[1] = $content;
        }
        unset($part);
        parent::parseObject($id, $structure, $document);
    }
}
