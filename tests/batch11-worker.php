<?php
/** HTTP concurrency worker; accepts only the owned loopback fixture and verifies its MariaDB datadir. */
$case=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
if (!preg_match('~^http://127\.0\.0\.1:\d+/~D',$case['url'])) exit(1);
$pdo=new PDO($case['dsn'],'root',$case['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($case['datadir'])) exit(1);
file_put_contents($case['ready'],'ready');$deadline=microtime(true)+15;
while(!is_file($case['gate'])&&microtime(true)<$deadline)usleep(10000);
$curl=curl_init($case['url']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>25,CURLOPT_COOKIEFILE=>$case['cookie'],CURLOPT_POST=>true,
    CURLOPT_HTTPHEADER=>['Content-Type: application/json','Origin: '.$case['origin'],'X-Prism-Generation: '.$case['generation']],CURLOPT_POSTFIELDS=>json_encode($case['data'])]);
$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);curl_close($curl);
echo json_encode(['ok'=>$status===200,'status'=>$status,'result'=>json_decode((string)$body,true)]);
