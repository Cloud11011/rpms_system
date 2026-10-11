<?php
$case=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$pdo=new PDO($case['dsn'],'root',$case['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($case['datadir'])) exit(1);
date_default_timezone_set('Asia/Manila'); $pdo->exec("SET time_zone='+08:00'");
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
function stage_label(string $s): string { return $s; }
require __DIR__.'/v10-batch10-bootstrap.php';
file_put_contents($case['ready'],'ready');
$deadline=microtime(true)+15; while (!is_file($case['gate']) && microtime(true)<$deadline) usleep(10000);
try {
    if ($case['operation']==='upload') {
        $curl=curl_init($case['url']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>20,
            CURLOPT_COOKIEFILE=>$case['cookie'],CURLOPT_POST=>true,CURLOPT_HTTPHEADER=>['Origin: '.$case['origin'],'X-Prism-Generation: '.$case['generation']],
            CURLOPT_POSTFIELDS=>['document'=>new CURLFile($case['file']),'documentType'=>$case['type'],'revisionOf'=>$case['revision'],
                'fileCount'=>'1','requestId'=>bin2hex(random_bytes(12))]]);
        $body=curl_exec($curl);$code=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
        echo json_encode(['ok'=>$code===200,'status'=>$code,'result'=>json_decode($body,true)]);exit;
    }
    $result=document_workflow_change($pdo,$case['actor'],$case['id'],$case['operation'],$case['status']??'',$case['reason']??'');
    echo json_encode(['ok'=>true,'result'=>$result]);
} catch (DocumentWorkflowError $e) { echo json_encode(['ok'=>false,'status'=>$e->status]); }
catch (Throwable $e) { fwrite(STDERR,get_class($e).': '.$e->getMessage()); exit(1); }
