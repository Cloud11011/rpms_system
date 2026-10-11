<?php
/** Sends only a synthetic multipart upload to the parent's isolated loopback application. */
if(PHP_SAPI!=='cli'||$argc!==2)exit(1);
$f=json_decode($argv[1],true,512,JSON_THROW_ON_ERROR);
if(!preg_match('~\Ahttp://127\.0\.0\.1:[0-9]+/documents_api\.php\?action=upload\z~',$f['url']??'')
    ||!str_contains(realpath($f['file'])?:'','prism-reset-migration-'))exit(1);
preg_match('/(?:^|; )prism_generation=([^;]+)/',$f['cookie'],$generation);
$curl=curl_init($f['url']);curl_setopt_array($curl,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_TIMEOUT=>15,CURLOPT_COOKIE=>$f['cookie'],
    CURLOPT_HTTPHEADER=>['Origin: '.$f['origin'],'X-PRISM-Generation: '.($generation[1]??'')],CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>['document'=>new CURLFile($f['file'],'application/pdf','synthetic.pdf'),'studentDbId'=>100,'stage'=>'Stage 1','documentType'=>'Study Protocol']]);
$body=curl_exec($curl);$status=curl_getinfo($curl,CURLINFO_RESPONSE_CODE);$error=curl_error($curl);curl_close($curl);
if($error!==''){fwrite(STDERR,$error);exit(1);}echo json_encode(['status'=>$status,'data'=>json_decode($body,true)]);
