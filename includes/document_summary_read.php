<?php
/** Called after document lookup and existing ownership checks. Summary access remains RPMS-only. */
if ($user['role']!=='admin') json_out(['ok'=>false,'message'=>'RPMS summary access required.'],403);
require_once __DIR__.'/document_summary_history.php';
$versions=summary_history_list($pdo,$doc);
if ($action==='summary_history') json_out(['ok'=>true,'versions'=>$versions]);
$versionId=$_GET['summaryId']??'';
if (!is_string($versionId) || !preg_match('/\A(?:legacy|[1-9][0-9]*)\z/',$versionId)) json_out(['ok'=>false,'message'=>'Choose a summary version.'],422);
$selected=null;
foreach ($versions as $version) if ((string)$version['id']===$versionId) $selected=$version;
if (!$selected) json_out(['ok'=>false,'message'=>'Summary version not found for this source document.'],404);
require_once __DIR__.'/report_pdf.php';
$project=research_project_for_student($pdo,(int)$doc['student_id']);
$q=$pdo->prepare('SELECT research_title FROM students WHERE id=?'); $q->execute([$doc['student_id']]);
$title=$project['research_title']??$q->fetchColumn();
$pdf=make_pdf('CEU RPMS - Document Summary',summary_pdf_blocks($doc,$selected,(string)$title));
// Mandatory audit: do not serve an export without retained access evidence.
try {
    $pdo->beginTransaction();
    $owners=student_snapshot_rows($pdo,[(int)$doc['student_id']]);
    if (!isset($owners[(int)$doc['student_id']])) throw new RuntimeException('Source ownership changed.');
    $live=current_workflow_actor($pdo,$user);
    $q=$pdo->prepare('SELECT student_id FROM documents WHERE id=? FOR UPDATE'); $q->execute([$doc['id']]);
    if ((int)$q->fetchColumn()!==(int)$doc['student_id']) throw new RuntimeException('Source ownership changed.');
    document_workflow_audit($pdo,$live,'document_summary_exported',['entity_id'=>$doc['id'],'student_id'=>$doc['student_id'],
        'details'=>'Summary ID '.$selected['id'].'; version '.$selected['version_no']]);
    $pdo->commit();
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    json_out(['ok'=>false,'message'=>'Summary export could not be authorized and audited. Refresh and try again.'],409);
}
header('Content-Type: application/pdf'); header('Cache-Control: private, no-store'); header('X-Content-Type-Options: nosniff');
header('Content-Disposition: attachment; filename="document-summary-v'.(int)$selected['version_no'].'.pdf"');
echo $pdf; exit;
