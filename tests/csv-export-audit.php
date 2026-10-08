<?php
/** Actual export endpoints and synthetic SQLite fixture; no application bootstrap or live records. */
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/csv_export.php';
$checks=0;
function verify(bool $ok,string $label): void { $GLOBALS['checks']++;if(!$ok)throw new RuntimeException($label); }
function endpoint(array $case): array {
    $p=proc_open([PHP_BINARY,__DIR__.'/archive-operational-audit.php','--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    verify($exit===0&&$err==='', 'Export fixture clean: '.$err.' '.$out);return json_decode($out,true,512,JSON_THROW_ON_ERROR);
}
function csv_rows(array $r): array {
    $csv=base64_decode($r['body']);verify(str_starts_with($csv,"\xEF\xBB\xBF"),'UTF-8 BOM');
    $f=fopen('php://memory','r+');fwrite($f,substr($csv,3));rewind($f);$rows=[];
    while (($row=fgetcsv($f,0,',','"',''))!==false) $rows[]=$row;fclose($f);return $rows;
}
foreach (['=','+','-','@',"\t","\r"] as $prefix) verify(csv_safe($prefix.'value')==="'".$prefix.'value','Formula prefix protected');
verify(csv_safe(null)===''&&csv_safe('Café')==='Café','Empty and Unicode preserved');
foreach (['students'=>[19,3,'student_records_exported'],'advisers'=>[9,2,'adviser_records_exported'],'documents'=>[26,1,'document_records_exported']] as $type=>[$columns,$count,$event]) {
    $r=endpoint(['file'=>'data_exports_api.php','action'=>$type]);$rows=csv_rows($r);
    verify($r['status']===200&&count($rows)===1+$count&&count($rows[0])===$columns,'Authorized full export and column count '.$type);
    verify(in_array('Content-Type: text/csv; charset=UTF-8',$r['headers'],true)&&in_array('Cache-Control: no-store',$r['headers'],true),'Correct CSV/cache headers');
    verify(count($r['audits'])===1&&$r['audits'][0]['action']===$event&&str_contains($r['audits'][0]['context']['details'],'rows='.$count),'Safe row-count audit');
    verify($r['unchanged'],'Export changes no business data');
    verify(!str_contains(json_encode($r['audits']),'ST-3')&&!str_contains(json_encode($r['audits']),'Historical saved summary'),'No exported contents logged');
    verify((bool)preg_match('/filename="prism_\w+_records_[0-9]{8}_[0-9]{6}\.csv"/',implode('\n',$r['headers'])),'Timestamped filename');
    if ($type==='students') {
        $mapped=array_combine(array_column(array_slice($rows,1),0),array_slice($rows,1));
        verify($mapped['ST-3'][15]==='Archived'&&$mapped['ST-3'][16]==='2026-10-02'&&$mapped['ST-1'][15]==='Active','Comprehensive Student CSV preserves and labels C');
        verify($mapped['ST-1'][3]==='Accountancy / Management / Technology'&&$mapped['ST-1'][4]==='BS in Information Technology','Human-readable catalog labels');
    }
    if ($type==='advisers') {
        $mapped=array_combine(array_column(array_slice($rows,1),0),array_slice($rows,1));
        verify($mapped['EMP-1'][6]==='2'&&$mapped['EMP-2'][4]==='Inactive','Current workload and inactive Adviser retained');
        verify(str_contains($mapped['EMP-1'][5],'G01')&&str_contains($mapped['EMP-1'][5],'G02')&&!str_contains($mapped['EMP-1'][5],'G03'),'Canonical active assignment groups');
    }
    if ($type==='documents') {
        verify($rows[1][4]==='Archived'&&$rows[1][25]==='Yes','Historical document metadata and summary availability');
        foreach (['stored_name','research-protocol.txt','Historical saved summary','password','token','C:/','C:\\'] as $secret) verify(!str_contains(base64_decode($r['body']),$secret),'Metadata excludes '.$secret);
    }
    $r=endpoint(['file'=>'data_exports_api.php','action'=>$type,'empty'=>true]);$rows=csv_rows($r);verify(count($rows)===1&&count($rows[0])===$columns&&$r['unchanged'],'Empty export retains headers and data');
}
foreach (['student','adviser'] as $role) foreach (['students','advisers','documents','ierb'] as $action) {
    $r=endpoint(['file'=>'data_exports_api.php','action'=>$action,'role'=>$role]);verify($r['status']===403&&base64_decode($r['body'])===''&&!$r['audits']&&$r['unchanged'],'Institution-wide export forbidden for '.$role);
}
$r=endpoint(['file'=>'data_exports_api.php','action'=>'ierb','query'=>['stage'=>'Stage 1','q'=>'Active','direction'=>'DESC']]);
verify(in_array('Location: ierb_api.php?action=export_csv&q=Active&stage=Stage+1&direction=DESC',$r['headers'],true),'Central IERB delegates filters to existing authorized exporter');
foreach (['admin','adviser'] as $role) {
    $r=endpoint(['file'=>'ierb_api.php','action'=>'export_csv','role'=>$role]);$rows=csv_rows($r);verify(count($rows)===3&&$r['audits'][0]['action']==='progress_exported'&&$r['unchanged'],'Existing scoped IERB CSV succeeds');
    $r=endpoint(['file'=>'ierb_api.php','action'=>'export_csv','role'=>$role,'query'=>['stage'=>'Stage 1']]);$rows=csv_rows($r);verify(count($rows)===2&&$rows[1][1]==='ST-1','Filtered current IERB CSV excludes matching archived C');
}
$r=endpoint(['file'=>'data_exports_api.php','action'=>'students','records'=>27]);verify(count(csv_rows($r))===28,'Export is independent of ten-row list pagination');
$r=endpoint(['file'=>'data_exports_api.php','action'=>'documents','richDocs'=>true]);$rows=csv_rows($r);
verify(count($rows)===4&&in_array('Unlinked/Historical',array_column($rows,4),true)&&in_array('No',array_column($rows,10),true),'Unlinked and superseded history exported');
$r=endpoint(['file'=>'data_exports_api.php','action'=>'students','unsafe'=>true]);$rows=csv_rows($r);
$unsafe=current(array_filter($rows,fn($row)=>($row[0]??'')==='ST-1'));
verify($unsafe[1]==="'=SUM(1,2)"&&$unsafe[12]==="'\tformula",'Endpoint sanitizes user-controlled formula fields');
verify($unsafe[7]==="Café, \"quoted\"\nmultiline",'Unicode commas quotes and multiline round-trip');
verify($unsafe[3]==='legacy_unit'&&$unsafe[4]==='Unsupported Legacy Course','Unsupported legacy academic values retained');
echo "PASS: $checks CSV authorization/data/history/format/security assertions; no persistent CSV or live records.\n";
