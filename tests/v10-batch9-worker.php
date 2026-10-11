<?php
if (PHP_SAPI!=='cli') exit(1);
require __DIR__.'/v10-batch9-helpers.php';
// Secrets stay in process pipes/memory, never command arguments or worker files.
$case=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$pdo=new PDO($case['dsn'],'root',$case['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($case['datadir'])) exit(2);
$pdo->exec("SET time_zone='+08:00'");
file_put_contents($case['ready'],'ready');
$deadline=microtime(true)+15;
while (!is_file($case['gate']) && microtime(true)<$deadline) usleep(10000);
if (!is_file($case['gate'])) exit(3);
try {
    if ($case['operation']==='register') $result=staff_register($pdo,$case['data']);
    elseif ($case['operation']==='expire') {
        $pdo->beginTransaction(); staff_lock_admins($pdo); staff_row($pdo);
        $pdo->exec("UPDATE staff_registration_code SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND) WHERE slot=1 AND state='acknowledged'");
        $pdo->commit(); $result=[];
    } elseif ($case['operation']==='deactivate') {
        $pdo->beginTransaction(); staff_lock_admins($pdo);
        $pdo->prepare("UPDATE users SET status='Inactive' WHERE id=?")->execute([$case['actor']['id']]);
        $pdo->commit(); $result=[];
    } else $result=staff_manage($pdo,$case['actor'],$case['data'],$case['context'],$case['key']);
    echo json_encode(['ok'=>true,'id'=>$result['id']??null,'generation'=>$result['row']['generation_id']??null]);
} catch (StaffRegistrationError $e) { echo json_encode(['ok'=>false]); }
catch (Throwable $e) { fwrite(STDERR,'Private worker operation failed: '.get_class($e).' at line '.$e->getLine().' driver '.(int)($e->errorInfo[1]??0)); exit(4); }
