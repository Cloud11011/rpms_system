<?php
/** Isolated endpoint fixture only: volatile SQLite memory, no config bootstrap/live DB. */
if (PHP_SAPI !== 'cli') exit(1);
$checks = 0;
function verify(bool $ok, string $label): void {
    $GLOBALS['checks']++;
    if (!$ok) throw new RuntimeException($label);
}
function request_case(array $case): array {
    $process = proc_open([PHP_BINARY, __DIR__ . '/calendar-deadlines-audit.php', '--case', json_encode($case)],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]); fclose($pipes[2]);
    verify(proc_close($process) === 0 && $err === '', 'Endpoint fixture runs without warnings: ' . $err);
    return json_decode($out, true, 512, JSON_THROW_ON_ERROR);
}
$g1 = 'AMT-BSIT-Y2-2627-G01'; $g2 = 'AMT-BSIT-Y2-2627-G02'; $g3 = 'AMT-BSIT-Y2-2627-G03';
$sql = ["INSERT INTO calendar_deadlines (id,creator_user_id,title,description,deadline_date,target_scope,status) VALUES
    (1,10,'Global','','2026-10-10','all','Active'), (2,10,'Mixed groups','','2026-10-10','groups','Active'),
    (3,10,'Unrelated','','2026-10-10','groups','Active'), (4,11,'Cancelled','','2026-10-10','groups','Cancelled'),
    (5,11,'Own','','2026-10-10','groups','Active'), (6,99,'Other adviser','','2026-10-10','groups','Active')",
    "INSERT INTO calendar_deadline_groups VALUES (2,'$g1'),(2,'$g3'),(3,'$g3'),(4,'$g1'),(5,'$g2'),(6,'$g1')"];
$base = ['method' => 'GET', 'action' => 'dashboard_day', 'sql' => $sql, 'query' => ['date' => '2026-10-10']];
foreach (['anonymous' => 401] as $role => $status) {
    $r = request_case($base + ['role' => $role]); verify($r['status'] === $status, $role . ' denied');
    verify(!$r['notifications'] && !$r['mail'], 'No delivery side effects');
}
foreach (['admin' => [6,5,3,2,1], 'adviser' => [5,2,1], 'student' => [2,1]] as $role => $expected) {
    $r = request_case($base + ['role' => $role]);
    verify($r['status'] === 200 && array_map('intval', array_column($r['response']['deadlines'], 'id')) === $expected, $role . ' exact authorized rows; cancelled excluded');
    foreach ($r['response']['deadlines'] as $row) {
        verify(!isset($row['canCancel'], $row['creator_user_id']) && array_keys($row) === ['id','title','deadline_date','target_scope','status','groups'], 'Only required read-only fields');
        if ((int)$row['id'] === 2) verify($row['groups'] === ($role === 'admin' ? [$g1,$g3] : [$g1]), 'Mixed deadline group labels follow viewer scope');
    }
    verify(count($r['deadlines']) === 6 && !$r['notifications'] && !$r['mail'], 'GET preserves records and delivers nothing');
}
foreach ([['group'=>$g3], ['groups'=>[$g3]], ['manage'=>'1'], ['creator_user_id'=>99], ['from'=>'2026-01-01','to'=>'2026-12-31'], ['date'=>'2026-10-11','group'=>$g3]] as $crafted) {
    $case = array_replace($base, ['role'=>'adviser', 'query'=>array_replace($base['query'],$crafted)]);
    $r = request_case($case);
    verify($r['status'] === 200 && array_map('intval',array_column($r['response']['deadlines'],'id')) === (isset($crafted['date']) ? [] : [5,2,1]), 'Crafted parameters cannot widen date/assignment scope');
}
foreach ([null, '', 'bad', '2026-02-30', '2026-2-01', ['2026-10-10']] as $date) {
    $r=request_case(array_replace($base,['query'=>$date===null?[]:['date'=>$date]]));
    verify($r['status']===422, 'Missing/empty/invalid/array date rejected');
}
foreach (['POST','PUT','DELETE'] as $method) {
    $r=request_case(array_replace($base,['method'=>$method])); verify($r['status']===405, 'Detail action is GET-only: '.$method);
}
$r=request_case(array_replace($base,['query'=>['date'=>'2026-10-10','page'=>[]]])); verify($r['status']===422,'Invalid page rejected');
$r=request_case(array_replace($base,['role'=>'adviser','sql'=>array_merge($sql,['UPDATE students SET adviser_id=2 WHERE id IN (1,2,3)'])]));
verify(array_map('intval',array_column($r['response']['deadlines'],'id'))===[1],'Reassignment removes old targeted deadlines immediately');
$r=request_case(array_replace($base,['role'=>'adviser','lateSql'=>['UPDATE advisers SET profile_completed_at=NULL WHERE id=1']]));
verify($r['status']===403&&$r['response']['code']==='profile_completion_required','Pending Adviser remains gated');
$many=$sql;
for($i=7;$i<=28;$i++)$many[]="INSERT INTO calendar_deadlines (id,creator_user_id,title,description,deadline_date,target_scope) VALUES ($i,10,'Deadline $i','','2026-10-10','all')";
$ids=[];
foreach([1,2,3] as $page) {
    $r=request_case(array_replace($base,['role'=>'adviser','sql'=>$many,'query'=>['date'=>'2026-10-10','page'=>$page]]));
    verify($r['response']['total']===25&&$r['response']['pages']===3,'All authorized dense-date pages counted');
    $ids=array_merge($ids,array_column($r['response']['deadlines'],'id'));
}
verify(count(array_unique($ids))===25,'Every dense-date row retrieved once across pages');
$root=dirname(__DIR__);
$config=file_get_contents($root.'/config.php');
verify(str_contains($config,"strcasecmp((string)\$user['status'], 'Active') !== 0"), 'Existing invalid/archived account session policy retained');
verify(str_contains($config,'const SCHEMA_VERSION = 9;'),'Current schema remains v9');
$api=file_get_contents($root.'/calendar_deadlines_api.php');
$start=strpos($api,"    if (\$action === 'dashboard_day') {"); $end=strpos($api,"    if (\$action === 'group_options')",$start);
verify(!preg_match('/\b(?:INSERT|UPDATE|DELETE|ALTER|CREATE|audit_log|deadline_notify)\b/',substr($api,$start,$end-$start)), 'New detail branch contains only reads');
echo "PASS: $checks Batch 2 read-only endpoint/scope/Pending/pagination checks; no application database loaded.\n";
