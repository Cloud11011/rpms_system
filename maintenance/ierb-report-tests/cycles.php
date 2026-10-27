<?php
require_once dirname(__DIR__,2).'/includes/ierb_track_report.php';
if (!defined('WF_SUBMITTED_RPMS')) define('WF_SUBMITTED_RPMS','Submitted to RPMS');
$checks=[];
function verify(bool $ok,string $label): void { global $checks; if (!$ok) throw new RuntimeException($label); $checks[]=$label; }
foreach(['ai','local'] as $source)verify(summary_legacy_source(json_encode(['prism_document_summary'=>1,'summary'=>'Retained text','source'=>$source]))===$source,'Historical structured source remains recorded: '.$source);
verify(summary_legacy_source('Historical unstructured summary')==='unknown','Unstructured historical text is not assigned a fabricated Local/AI source');
verify(summary_legacy_source('{"prism_document_summary":1,"summary":"Text","source":"unknown"}')==='unknown','Malformed historical source metadata remains unknown');
$documents=['a'=>['id'=>'a','version_no'=>1,'document_type'=>'Study Protocol','supersedes_id'=>null,'rpms_submitted_at'=>'2026-01-01 08:00:00']];
$audits=[['id'=>1,'action'=>'document_submitted_to_rpms','entity_id'=>'a','after_value'=>WF_SUBMITTED_RPMS,'created_at'=>'2026-01-01 08:00:00','details'=>'']];
$cases=[];
for ($i=0;$i<=4;$i++) {
    if ($i) {
        $old=$i===1?'a':'d'.($i-1); $new='d'.$i; $date='2026-01-0'.($i+1).' 08:00:00';
        $case=['id'=>$i,'opened_at'=>$date,'resolved_at'=>$date,'requires_amendment'=>$i%2,'affected_documents_json'=>json_encode([['id'=>$old,'document_type'=>'Study Protocol']]),'reason'=>'Synthetic return reason','interrupted_stage'=>'initial_submission']; $cases[]=$case;
        $documents[$new]=['id'=>$new,'version_no'=>$i+1,'supersedes_id'=>$old,'document_type'=>'Study Protocol','rpms_submitted_at'=>$date];
        $audits[]=['id'=>$i*10,'action'=>'ierb_v14_open_correction','entity_id'=>'4','after_value'=>'','details'=>'V14 workflow 1; correction case '.$i,'created_at'=>$date];
        $audits[]=['id'=>$i*10+1,'action'=>'document_resubmitted_to_rpms','entity_id'=>$new,'after_value'=>WF_SUBMITTED_RPMS,'details'=>'','created_at'=>$date];
        // More than one corrected document in one official case is still one cycle.
        $other=$new.'x'; $documents[$other]=['id'=>$other,'version_no'=>$i+1,'supersedes_id'=>$old,'document_type'=>'Study Protocol','rpms_submitted_at'=>$date];
        $audits[]=['id'=>$i*10+2,'action'=>'document_resubmitted_to_rpms','entity_id'=>$other,'after_value'=>WF_SUBMITTED_RPMS,'details'=>'','created_at'=>$date];
    }
    $r=track_submission_cycles(array_reverse($cases),array_reverse($audits),$documents);
    verify(count($r['rows'])===4,'Exactly four tracking rows with '.$i.' correction cycles');
    verify(array_column($r['rows'],'label')===IERB_TRACK_LABELS,'Fixed Initial through 3rd labels with '.$i.' correction cycles');
    verify($r['officialResubmissionCount']===$i,'Chronological deduplicated case count '.$i);
    verify($r['rows'][0]['submittedAt']==='2026-01-01 08:00:00','Initial uses formal event, not holder last_submission_date '.$i);
    if ($i && $i<4) verify(count($r['rows'][$i]['submissionEvidence'])===2,'Multiple documents retain one official cycle '.$i);
    if ($i<3) verify($r['rows'][$i+1]['submittedAt']===null,'Unused slot remains unrecorded '.$i);
    if ($i===4) verify(count($r['overflowEvidence'])===1 && count($r['allCorrectionCases'])===4 && str_contains(implode(' ',$r['notes']),'RPMS REVIEW REQUIRED'),'Fourth cycle remains audit evidence with an explicit RPMS exception');
}
$r=track_submission_cycles($cases,array_filter($audits,fn($a)=>!str_contains($a['action'],'resubmitted')),$documents);
verify($r['officialResubmissionCount']===0,'Opening correction cases alone never establishes resubmission');
$r=track_submission_cycles([],[],[]); verify($r['rows'][0]['submittedAt']===null && $r['rows'][0]['returnedAt']===null,'Missing submission and return stamps remain unknown');
$sample=[$audits[0],['id'=>2,'action'=>'document_returned_for_revision','entity_id'=>'a','after_value'=>'','details'=>'','created_at'=>'2026-01-01 09:30:00']];
$r=track_submission_cycles([],$sample,$documents);
verify($r['rows'][0]['returnedAt']==='2026-01-01 09:30:00','Return uses its separate authoritative event');
$bad=$documents; $bad['a']['rpms_submitted_at']=null;
$r=track_submission_cycles([],$sample,$bad); verify($r['rows'][0]['submittedAt']===null,'An audit without its formal document stamp is incomplete, not an invented date');
foreach(['0000-00-00 00:00:00','2026-02-30 08:00:00','2026-01-01 25:00:00','2026-01-01',null] as $stamp)verify(track_recorded_timestamp($stamp)===null,'Invalid or incomplete database timestamp stays unknown: '.json_encode($stamp));
$r=track_submission_cycles($cases,array_filter($audits,fn($a)=>$a['action']!=='ierb_v14_open_correction'),$documents);
verify($r['officialResubmissionCount']===0 && count($r['notes'])>=4,'Missing opening audits prevent ambiguous same-second attribution');
$uploaded=['id'=>'unsubmitted','document_type'=>'Study Protocol','supersedes_id'=>'a','version_no'=>2,'uploaded_at'=>'2026-01-02 00:00:00','rpms_submitted_at'=>null];
verify(!track_case_document(['id'=>'unrelated','document_type'=>'Other Supporting Document','supersedes_id'=>null],$cases[0],$documents),'Unrelated category is not case evidence');
verify(track_case_document(['id'=>'amend','document_type'=>'Amendment Form','supersedes_id'=>null],$cases[0],$documents),'Required Amendment may be formally submitted in its case');
$optional=$cases[0]; $optional['requires_amendment']=0;
verify(!track_case_document(['id'=>'amend','document_type'=>'Amendment Form','supersedes_id'=>null],$optional,$documents),'Optional Amendment does not invent a formal correction cycle');
echo json_encode(['status'=>'PASS','assertions'=>count($checks),'cases'=>$checks],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
