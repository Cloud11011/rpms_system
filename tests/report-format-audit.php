<?php
/** CLI-only report formatting/privacy tests. No bootstrap, DB, AI, or real student data. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$source = file_get_contents(dirname(__DIR__) . '/reports_api.php');
function report_test_part(string $source, string $from, string $to): string {
    $start = strpos($source, $from); $end = strpos($source, $to, $start);
    if ($start === false || $end === false) throw new RuntimeException('Reviewed extraction boundary missing');
    $part = substr($source, $start, $end-$start);
    if (preg_match('/\b(?:require|include)(?:_once)?\s*[\(\'"$]/', $part)) throw new RuntimeException('Unexpected dependency');
    return $part;
}
const STAGE_SEQUENCE = ['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed'];
eval(report_test_part($source, 'function pdf_escape(', "if ($" . "action === 'list')"));
eval(report_test_part($source, 'const AI_REPORT_MODES', "if ($" . "action === 'ai_report')"));
eval(report_test_part($source, 'function report_case_ref(', "if ($" . "action === 'generate')"));
$checks=0;
function expect_report(bool $ok, string $message): void {
    ++$GLOBALS['checks']; if (!$ok) throw new RuntimeException($message);
}
$student=['id'=>987654,'full_name'=>'PRIVATE NAME','student_id'=>'PRIVATE ID','email'=>'private@example.invalid',
    'adviser_name'=>'PRIVATE ADVISER','course'=>'PRIVATE COURSE','research_title'=>'PRIVATE TITLE',
    'research_group'=>'PRIVATE GROUP','protocol_code'=>'PRIVATE PROTOCOL','requirements'=>'PRIVATE NOTES',
    'stage'=>'Stage 1','status'=>'On Track','last_submission_date'=>'2026-10-01'];
$other=array_replace($student,['stage'=>'Stage 2','status'=>'Delayed','last_submission_date'=>null]);
$rows=[$student,$other]; $json=build_structured_progress_text($rows); $facts=json_decode($json,true,512,JSON_THROW_ON_ERROR);
foreach (['987654','PRIVATE','private@example.invalid','full_name','student_id','adviser_name','research_title','course','email'] as $private) {
    expect_report(!str_contains($json,$private),'AI payload excludes '.$private);
}
expect_report($facts['recordCount']===2 && $facts['statuses']===['On Track'=>1,'Delayed'=>1],'Counts describe records exactly');
expect_report(array_column($facts['cases'],'caseRef')===['CASE-0001','CASE-0002'],'Report-local case references');
expect_report($facts['cases'][1]['lastSubmissionDate']===null,'Missing date stays unknown');
foreach (['2026-02-30','PRIVATE DATE','2026-10-01 extra'] as $date) {
    $bad=array_replace($student,['stage'=>'PRIVATE STAGE','status'=>'PRIVATE STATUS','last_submission_date'=>$date]);
    $payload=build_structured_progress_text([$bad]);
    expect_report(!str_contains($payload,'PRIVATE'),'Unknown enums/free text cannot identify records');
    expect_report(json_decode($payload,true)['cases'][0]['lastSubmissionDate']===null,'Only valid calendar dates exported');
}
$large=build_structured_progress_text(array_fill(0,500,$student)); $largeFacts=json_decode($large,true,512,JSON_THROW_ON_ERROR);
expect_report(strlen($large)<=11000 && $largeFacts['recordCount']===500 && $largeFacts['caseDetailsOmitted'],'Transport does not truncate JSON or total counts');
foreach ([AI_SUMMARY_PROMPT,AI_FULL_PROMPT] as $prompt) {
    foreach (['Do not infer causes','missing submission date','not distinct research groups','Do not invent identities','provided snapshot','unless explicitly supplied','quantitatively supported','Do not infer risk or outstanding requirements from missing fields'] as $rule) expect_report(str_contains($prompt,$rule),'Both prompts require factual restraint: '.$rule);
}
foreach (['summary','full'] as $mode) {
    $blocks=progress_report_tables($rows,$mode); $tables=array_values(array_filter($blocks,'is_array'));
    expect_report(count($tables)===($mode==='full'?3:2),$mode.' database-derived factual tables');
    expect_report(end($tables[0]['rows'])===['Total student records','2'],'Factual total retained');
    if ($mode==='full') expect_report(str_starts_with($tables[2]['rows'][0][0],$facts['cases'][0]['caseRef']) && count($tables[2]['rows'])===2,'Local mapping uses same case identifier');
    $pdf=make_pdf('Synthetic '.$mode,array_merge($blocks,["# Narrative\n**Verified** facts only."]));
    expect_report(str_contains($pdf,'/Helvetica-Bold') && str_contains($pdf,'/F2 11 Tf'),'Heading/bold fonts exist');
    expect_report(!str_contains($pdf,'**') && !str_contains($pdf,'# Narrative'),'Markdown markers are not printed');
    expect_report(str_contains($pdf,'re S') && str_contains($pdf,'re f'),'Tables have borders and header fill');
    expect_report(str_contains($pdf,'(Verified) Tj'),'Inline bold text retained');
    preg_match('/startxref\n(\d+)\n%%EOF$/',$pdf,$xref);
    expect_report(isset($xref[1]) && substr($pdf,(int)$xref[1],4)==='xref','Valid xref offset');
    preg_match_all('/(\d+) 0 obj\n/',$pdf,$objects,PREG_OFFSET_CAPTURE);
    foreach ($objects[1] as $i=>$object) expect_report(str_contains($pdf,sprintf('%010d 00000 n',$objects[0][$i][1])),'Object offset matches xref');
}
$long=array_replace($student,['requirements'=>str_repeat('LONGROW ',900).'END-OF-LONG-ROW','research_title'=>'Literal **asterisks** (parentheses) \\']);
$pdf=make_pdf('Synthetic pagination',array_merge(progress_report_tables([$long,$other],'full'),["## End heading\nText with **bold words** and a final marker."]));
expect_report(substr_count($pdf,'/Type /Page /Parent')>1,'Long rows paginate');
expect_report(substr_count($pdf,'(Protocol) Tj')>1,'Split case table repeats column headers');
expect_report(str_contains($pdf,'(END-OF-LONG-ROW) Tj'),'Long row content is not dropped');
expect_report(str_contains($pdf,'**'),'Table values remain literal, not Markdown');
expect_report(str_contains($pdf,'(final) Tj'),'Content after a long table survives');
preg_match_all('/1 0 0 1 [\d.]+ ([\d.]+) Tm/',$pdf,$ys);
expect_report(min(array_map('floatval',$ys[1]))>=30,'Text stays within page/footer bounds');
foreach (['likely','highest risk','set a resubmission deadline'] as $guess) expect_report(!str_contains(local_full_narrative($rows),$guess),'Fallback avoids unsupported '.$guess);
$keepTogether=make_pdf('Heading boundary',array_merge(array_fill(0,34,'Filler'),['# Factual section',
    ['headers'=>['Stage','Records'],'widths'=>[384,128],'rows'=>[['Stage 1','1']]]]));
preg_match_all('/stream\n(.*?)\nendstream/s',$keepTogether,$pageStreams);
foreach ($pageStreams[1] as $pageStream) {
    if (str_contains($pageStream,'(Factual) Tj')) expect_report(str_contains($pageStream,'(Records) Tj') && str_contains($pageStream,'(1) Tj'),'Heading stays with following table');
}
$wholeRow=make_pdf('Row boundary',array_merge(array_fill(0,31,'Filler'),[
    ['headers'=>['Field','Value'],'widths'=>[170,342],'rows'=>[['First','One'],['ROW-START',str_repeat('WRAPPED ',14).'ROW-END']]]]));
preg_match_all('/stream\n(.*?)\nendstream/s',$wholeRow,$rowStreams);
foreach ($rowStreams[1] as $pageStream) {
    if (str_contains($pageStream,'(ROW-START) Tj')) expect_report(str_contains($pageStream,'(ROW-END) Tj'),'Ordinary row stays intact across page boundary');
}
foreach (["| Label | Count |\n| --- | ---: |\n| INVENTED_TABLE_CELL | 999 |",
    "Label | Count\n:--- | ---:\nINVENTED_TABLE_CELL | 999", "| Label |\n| --- |\n| INVENTED_TABLE_CELL |"] as $table) {
    expect_report(strip_report_markdown_tables("Before\n".$table."\n\nAfter")==="Before\n\nAfter",'Only Markdown table blocks removed');
    expect_report(strip_report_markdown_tables($table)==='','Table-only narrative becomes empty for fallback');
}
expect_report(strip_report_markdown_tables('Ordinary A | B prose')==='Ordinary A | B prose','Non-table pipe text is preserved');
expect_report(abs(report_pdf_width('WWW',10)-28.32)<0.001 && report_pdf_width('iii',10)<report_pdf_width('WWW',10),'Helvetica uses proportional metrics');
expect_report(count(report_pdf_lines('2026-10-01',58))===1 && count(report_pdf_lines('submission',58,false,9,true))===1,'Date column keeps dates and header words intact');
foreach ([false,true] as $bold) {
    foreach (report_pdf_lines(str_repeat('WWW iii **bold** ',20),90,true,9,$bold) as $line) {
        $width=0; foreach($line as [$text,$strong]) $width+=report_pdf_width($text,9,$bold||$strong);
        expect_report($width<=90.001,'Font-aware wrapped line fits its cell');
    }
}
// Exercise the actual ai_report action with delivery/auth/SQL replaced, never config.php.
class ReportFixturePDO extends PDO {
    public function __construct() {}
    public function prepare(string $query, array $options=[]): PDOStatement|false {
        expect_report(str_starts_with($query,'INSERT INTO reports '),'Only existing report persistence is used');
        return new class extends PDOStatement {
            public function execute(?array $params=null): bool { $GLOBALS['savedReport']=$params; return true; }
        };
    }
}
function report_students_for_user(PDO $pdo, array $user, string $stage=''): array { return $GLOBALS['fixtureRows']; }
function openrouter_generate(string $prompt, string $content): ?string {
    $GLOBALS['outbound']=[$prompt,$content]; return $GLOBALS['aiReply'];
}
function log_activity(...$args): void {}
class ReportResponse extends RuntimeException {}
function json_out($data, $status=200): void { $GLOBALS['response']=[$data,$status]; throw new ReportResponse(); }
$directory=sys_get_temp_dir().'/prism-report-audit-'.bin2hex(random_bytes(6));
mkdir($directory,0700); define('REPORTS_DIR',$directory);
$actionBody=report_test_part($source,"if ($"."action === 'ai_report')",'function report_case_ref(');
try {
    foreach (['admin','adviser'] as $role) foreach (['summary','full'] as $mode) foreach ([null,"# Observations\n**Recorded** snapshot only.","# Observations\n**Recorded** snapshot only.\n| Label | Count |\n| --- | ---: |\n| INVENTED_TABLE_CELL | 999 |"] as $reply) {
        $GLOBALS['fixtureRows']=$rows; $GLOBALS['aiReply']=$reply; $GLOBALS['outbound']=null;
        $pdo=new ReportFixturePDO(); $action='ai_report'; $data=['mode'=>$mode];
        $user=['id'=>1,'role'=>$role,'full_name'=>'PRIVATE OPERATOR','email'=>'operator@example.invalid'];
        try { eval($actionBody); } catch (ReportResponse $done) {}
        [$result,$status]=$GLOBALS['response']; [$prompt,$payload]=$GLOBALS['outbound'];
        expect_report($status===200 && $result['ok'] && $result['aiUsed']===($reply!==null),'Existing response contract/fallback maintained');
        expect_report(!str_contains($payload,'PRIVATE') && !str_contains($payload,'example.invalid'),'Actual transport boundary excludes identities');
        expect_report(str_contains($payload,$role==='adviser'?'only students assigned':'all student records'),'Actual prompt retains authorized scope');
        $saved=$GLOBALS['savedReport'];$generated=file_get_contents(REPORTS_DIR.'/'.$saved[':file']);
        expect_report(str_starts_with($generated,'%PDF-1.4') && str_contains($generated,'re S'),'Actual action writes factual PDF tables');
        expect_report(!str_contains($generated,'|') && !str_contains($generated,'---') && !str_contains($generated,'INVENTED_TABLE_CELL'),'AI Markdown table pipes/separators/data are absent from the PDF');
        if ($mode==='full') {
            expect_report(strpos($generated,'(Narrative) Tj')<strpos($generated,'(Case) Tj'),'Compact case table follows narrative');
            expect_report(str_contains($generated,'(NAME) Tj') && str_contains($generated,'(PROTOCOL) Tj'),'Direct identifiers remain in local PDF only');
        }
        expect_report($saved[':uid']===1 && $result['report']['id']===$saved[':id'],'Report ownership/ID persistence unchanged');
        unlink(REPORTS_DIR.'/'.$saved[':file']);
    }
} finally {
    foreach (glob($directory.'/*.pdf') as $file) unlink($file);
    rmdir($directory);
}
if (($argv[1] ?? '') === '--sample-pdf') {
    $sample=tempnam(sys_get_temp_dir(),'prism-report-'); file_put_contents($sample,$pdf); echo 'Synthetic PDF: '.$sample."\n";
}
echo "PASS: $checks report privacy/formatting assertions; no bootstrap or external services.\n";
