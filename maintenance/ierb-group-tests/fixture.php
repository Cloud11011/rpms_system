<?php
if (PHP_SAPI !== 'cli') exit; // Test fixture: never reachable over HTTP (B7-F21).
require __DIR__.'/bootstrap.php';
$in=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$pdo=db();
try {
    if ($in['action']==='sql') {
        $q=$pdo->prepare($in['sql']); $q->execute($in['params']??[]);
        echo json_encode($q->columnCount() ? $q->fetchAll() : ['affected'=>$q->rowCount()]);
    } elseif ($in['action']==='schema') {
        lifecycle_compare_schema($pdo);
        echo json_encode(['schemaVersion'=>$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),'manifest'=>'PASS']);
    } elseif ($in['action']==='prepare') {
        $_SESSION=['user_id'=>1,'login_generation'=>bin2hex(random_bytes(16))];
        $actor=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
        $preview=ierb_group_preview($pdo,$actor,$in['key']);
        echo json_encode(['preview'=>$preview,'session'=>$_SESSION]);
    } elseif ($in['action']==='confirm') {
        $_SESSION=$in['session'];
        $actor=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
        $GLOBALS['provider_failure']=!empty($in['providerFailure']);
        $result=ierb_group_advance($pdo,$actor,$in['data']);
        echo json_encode(['status'=>200,'body'=>$result,'providerCalls'=>count($GLOBALS['provider_calls']??[]),'errors'=>$GLOBALS['fixture_errors']??[]]);
    } elseif ($in['action']==='hold') {
        $pdo->beginTransaction();
        $q=$pdo->prepare($in['sql']); $q->execute($in['params']??[]);
        echo "locked\n"; flush(); usleep(1200000); $pdo->commit(); echo 'released';
    } elseif ($in['action']==='fence') {
        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ'); $pdo->beginTransaction();
        $pdo->query('SELECT id FROM students FORCE INDEX (PRIMARY) ORDER BY id FOR UPDATE')->fetchAll();
        echo "locked\n"; flush(); usleep(1200000); $pdo->commit(); echo 'released';
    } elseif ($in['action']==='decide') {
        $actor=$pdo->query('SELECT * FROM users WHERE id=1')->fetch();
        echo json_encode(['status'=>200,'body'=>ierb_progress_decide($pdo,$actor,$in['operation'],$in['data'])]);
    } else throw new RuntimeException('Unknown isolated fixture action.');
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['status'=>lifecycle_error_status($e),'message'=>$e->getMessage()]);
}
