<?php
/** Actual parsers and non-sensitive local files; no application bootstrap or external model. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/document_summary.php';
require __DIR__ . '/../ai_helpers.php';
$checks = 0; $results = [];
function verify(bool $ok, string $label): void { $GLOBALS['checks']++; if (!$ok) throw new RuntimeException($label); }
$dir = __DIR__ . '/fixtures/document-summary/';
foreach (['academic-research.pdf', 'research-protocol.docx','research-protocol.txt','research-protocol.rtf'] as $name) {
    $start = microtime(true); $out = extract_document_text_for_summary($dir . $name);
    verify(strlen($out['text']) > 600, $name . ' contains substantive text');
    if (str_ends_with($name, '.pdf')) {
        verify($out['pages'] >= 8, 'Actual multi-page academic PDF');
        verify(stripos($out['text'], 'questionnaire') !== false && stripos($out['text'], 'ethic') !== false, 'Academic methodology and ethics recovered');
        verify(strlen(extract_document_text($dir . $name)) < strlen($out['text']) / 4, 'Old byte-regex fails on this actual academic PDF');
    } else {
        verify(str_contains($out['text'], 'informed consent') && str_contains($out['text'], 'café'), $name . ' retains research content and accents');
    }
    if (str_ends_with($name, '.docx')) verify(str_contains($out['text'], 'Table methodology'), 'DOCX table text retained');
    if (str_ends_with($name, '.rtf')) verify(!str_contains($out['text'], 'Hidden') && !str_contains($out['text'], 'executable'), 'RTF destinations excluded');
    $results[$name] = ['bytes' => strlen($out['text']), 'pages' => $out['pages'], 'partial' => $out['partial'], 'seconds' => round(microtime(true)-$start, 3)];
}
foreach (['scanned-image.pdf'=>'pdf_no_text','malformed.pdf'=>'invalid_pdf','font-budget.pdf'=>'pdf_budget','encrypted.pdf'=>'encrypted_pdf','external-entity.docx'=>'invalid_docx_xml','invalid-container.docx'=>'invalid_docx','unsupported.doc'=>'unsupported_format'] as $name => $reason) {
    try { extract_document_text_for_summary($dir . $name); verify(false, 'Expected extraction error'); }
    catch (DocumentSummaryError $error) { verify($error->reasonCode === $reason, $name . ' safe specific failure'); verify(!str_contains($error->getMessage(), $dir), 'No paths exposed'); }
}
$identifiers = ['Maria Santos','2026-12345','CEU-IERB-2026-071'];
$text = "Maria Santos (2026-12345) submitted CEU-IERB-2026-071. unknown@example.test\nStudent name: Another Person\nProtocol code: OTHER-2026-91\nPurpose: voluntary research.";
$redacted = deidentify_document_summary_text($text, $identifiers);
foreach (['Maria','Santos','2026-12345','CEU-IERB','unknown@example','Another Person','OTHER-2026'] as $needle) verify(!str_contains($redacted, $needle), 'Obvious identifier removed: ' . $needle);
verify(str_contains($redacted, 'voluntary research'), 'Useful research content retained');
verify(ai_detect_approval_date('Approved March 14, 2026.') === ['date'=>'2026-03-14','source'=>'regex'], 'Approval date remains local');
$excerpt = summary_model_excerpt(str_repeat('Background research. ', 600) . 'Ethics: Informed consent is required. ' . str_repeat('Detailed methods questionnaire. ', 600));
verify(mb_strlen($excerpt) <= DOCUMENT_SUMMARY_MAX_INPUT_CHARS && str_contains($excerpt,'Informed consent'), 'Bounded excerpt retains later ethics information');
$oldMemory = ini_get('memory_limit'); ini_set('memory_limit','32M');
try { extract_document_text_for_summary($dir.'academic-research.pdf'); verify(false,'Expected low-memory rejection'); }
catch (DocumentSummaryError $error) { verify($error->reasonCode==='memory_budget','Low-memory PDF fails before parse'); }
finally { ini_set('memory_limit',$oldMemory); }
echo json_encode($results, JSON_PRETTY_PRINT), "\nPASS: $checks extraction/privacy assertions.\n";
