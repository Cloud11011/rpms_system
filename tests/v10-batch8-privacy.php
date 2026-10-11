<?php
/** Execute real AI/report/CSV/metrics source assembly with Support sentinel rows in each isolated database. */
if (PHP_SAPI!=='cli') exit(1);
$checks=0;
foreach ([
    ['file'=>'reports_api.php','action'=>'ai_report','data'=>['mode'=>'summary'],'provider'=>'success'],
    ['file'=>'reports_api.php','action'=>'ai_report','data'=>['mode'=>'full'],'provider'=>'fallback'],
    ['file'=>'reports_api.php','action'=>'generate','data'=>['type'=>'Progress Report']],
    ['file'=>'reports_api.php','action'=>'generate','data'=>['type'=>'Student Report','studentId'=>1]],
    ['file'=>'data_exports_api.php','action'=>'students'],['file'=>'data_exports_api.php','action'=>'advisers'],['file'=>'data_exports_api.php','action'=>'documents'],
    ['file'=>'ierb_api.php','action'=>'export_csv'],['file'=>'ierb_api.php','action'=>'stage_distribution'],['file'=>'ierb_api.php','action'=>'list'],
] as $case) {
    $case['supportSentinel']=true;
    $p=proc_open([PHP_BINARY,__DIR__.'/archive-operational-audit.php','--case',json_encode($case)],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
    fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$exit=proc_close($p);
    if ($exit!==0 || $err!=='') throw new RuntimeException('Privacy fixture failed: '.$err.$out);
    $r=json_decode($out,true,512,JSON_THROW_ON_ERROR);
    if ($r['status']!==200 || str_contains(json_encode($r),'B8_SUPPORT_PRIVATE_SENTINEL') || str_contains(base64_decode($r['body']),'B8_SUPPORT_PRIVATE_SENTINEL')) throw new RuntimeException('Support content reached '.$case['file'].' '.$case['action']);
    if (!empty($r['response']['ok']) || base64_decode($r['body'])!=='' || $r['pdf']!=='') $checks++;
    else throw new RuntimeException('Privacy test must produce actual report/CSV/metrics output.');
}
echo "PASS: $checks real AI/report/export/metrics sentinel exclusion paths\n";
