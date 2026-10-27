<?php
require_once __DIR__.'/document_summary_ai.php';

function summary_history_available(PDO $pdo): bool
{
    return (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='14';
}

function summary_legacy_source(string $stored): string
{
    $meta=json_decode($stored,true);
    return is_array($meta) && ($meta['prism_document_summary']??null)===1 && is_string($meta['summary']??null)
        && in_array($meta['source']??null,['local','ai'],true)?$meta['source']:'unknown';
}

/** Caller owns document/student locks and transaction. Existing values imported before any new version. */
function summary_history_append(PDO $pdo,array $doc,array $actor,array $result,string $sha256): array
{
    if (!$pdo->inTransaction() || !summary_history_available($pdo)) throw new DocumentAIError('summary_schema_required','Summary history requires the reviewed schema 14 migration. Existing summaries remain readable.',503);
    $q=$pdo->prepare('SELECT MAX(version_no) FROM document_summary_versions WHERE document_id=?'); $q->execute([$doc['id']]); $version=(int)$q->fetchColumn();
    $insert=$pdo->prepare('INSERT INTO document_summary_versions(document_id,version_no,source_version_no,summary_content,source_type,model_identifier,generated_by_user_id,generated_at,source_sha256,partial_extraction,limitations,legacy_import) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)');
    if ($version===0 && trim((string)$doc['ai_summary'])!=='') {
        $old=document_summary_record($doc['ai_summary']);
        $insert->execute([$doc['id'],++$version,max(1,(int)$doc['version_no']),$old['summary'],
            summary_legacy_source($doc['ai_summary']),null,null,null,null,(int)$old['partial'],
            'Historical saved value. Original generation time, model, generating administrator and source fingerprint were not recorded; source version is the associated document record.',1]);
    }
    $limitations='Generated review aid; verify every statement against the original document. Extraction does not prove institutional approval or requirement acceptance. Scanned images, figures and complex table structure may not be extractable.';
    if ($result['partial']) $limitations.=' Partial extraction: only a bounded excerpt was processed; tables, scans, images and omitted passages may be unavailable.';
    if (!empty($result['fallbackReason'])) $limitations.=' External AI unavailable; Local Summary used ('.$result['fallbackReason'].').';
    $insert->execute([$doc['id'],++$version,max(1,(int)$doc['version_no']),$result['summary'],$result['source'],
        $result['source']==='ai'?OPENROUTER_MODEL:null,$actor['id'],$pdo->query('SELECT NOW(6)')->fetchColumn(),$sha256,(int)$result['partial'],$limitations,0]);
    return ['summaryId'=>(int)$pdo->lastInsertId(),'summaryVersion'=>$version,'limitations'=>$limitations];
}

function summary_history_list(PDO $pdo,array $doc): array
{
    $rows=[];
    if (summary_history_available($pdo)) {
        $q=$pdo->prepare('SELECT * FROM document_summary_versions WHERE document_id=? ORDER BY version_no DESC'); $q->execute([$doc['id']]); $rows=$q->fetchAll(PDO::FETCH_ASSOC);
    }
    if (!$rows && trim((string)$doc['ai_summary'])!=='') {
        $old=document_summary_record($doc['ai_summary']);
        $rows[]=['id'=>'legacy','version_no'=>0,'source_version_no'=>$doc['version_no'],'summary_content'=>$old['summary'],
            'source_type'=>summary_legacy_source($doc['ai_summary']),'model_identifier'=>null,'generated_at'=>null,'partial_extraction'=>$old['partial'],
            'limitations'=>'Historical value: generation metadata was not recorded.','legacy_import'=>1];
    }
    return $rows;
}

function summary_pdf_blocks(array $doc,array $version,string $researchTitle): array
{
    return ['# Research identification',$researchTitle?:'Not recorded',
        ['headers'=>['Source information','Recorded value'],'widths'=>[170,342],'rows'=>[
            ['Source filename',$doc['original_name']],['Document type',$doc['document_type']],['Source document version',(string)$version['source_version_no']],
            ['Summary version',(string)$version['version_no']],['Generated at (Asia/Manila)',$version['generated_at']?:'Not recorded'],
            ['Summary source',match($version['source_type']) {'ai'=>'AI','local'=>'Local',default=>'Not recorded (historical)'}],['Model',$version['model_identifier']?:'Not recorded']]],
        '# Summary (generated review aid)',$version['summary_content'],'# Source and verification notes',$version['limitations'],
        'Human verification required. Generated interpretations do not establish ethics approval, research findings or official progress.'];
}
