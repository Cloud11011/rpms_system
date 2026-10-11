<?php
namespace PrismBatch5bValidation;
if (PHP_SAPI!=='cli')exit(1);
function is_uploaded_file(string $path): bool {return true;}
function office_container_is_valid(...$args): bool {return true;}
$source=file_get_contents(__DIR__.'/../includes/document_upload.php');
eval('namespace '.__NAMESPACE__.'; use \\RuntimeException; use \\finfo; '.preg_replace('/^<\?php\s*/','',$source));
$tmp=tempnam(sys_get_temp_dir(),'prism-batch5b-');file_put_contents($tmp,file_get_contents(__DIR__.'/fixtures/batch6-valid.pdf'));
$valid=['name'=>'file.pdf','tmp_name'=>$tmp,'error'=>UPLOAD_ERR_OK,'size'=>filesize($tmp),'type'=>'untrusted/client-type'];$checks=0;
function check(bool $ok):void {++$GLOBALS['checks'];if(!$ok)throw new \RuntimeException('Validation assertion failed.');}
try {
    check(count(prism_upload_files($valid))===1);
    $multiple=array_map(fn($v)=>[$v,$v],$valid);check(count(prism_upload_files($multiple))===2);
    check(prism_validate_upload($valid)['mime']==='application/pdf');
    foreach ([null,[],['name'=>[]],array_replace($multiple,['size'=>[12]]),array_replace($multiple,['tmp_name'=>[$tmp,[$tmp]]]),array_replace($valid,['size'=>['12']])] as $bad) {
        try {prism_upload_files($bad);check(false);}catch(DocumentUploadError $e){check($e->status===400);}
    }
    foreach ([1,2,3,4,6,7,8,99] as $error) {
        try {prism_validate_upload(array_replace($valid,['error'=>$error]));check(false);}catch(DocumentUploadError $e){check($e->status===400);}
    }
    foreach (['../file.txt','C:\\file.txt',"file\0.txt",'file.php.txt','bad.html','bad.exe','bad.docx'] as $name) {
        try {prism_validate_upload(array_replace($valid,['name'=>$name]));check(false);}catch(DocumentUploadError $e){check(in_array($e->status,[422,415],true));}
    }
    $oversize=21*1024*1024;$h=fopen($tmp,'c+');ftruncate($h,$oversize);fclose($h);clearstatcache();
    try {prism_validate_upload(array_replace($valid,['size'=>$oversize]));check(false);}catch(DocumentUploadError $e){check($e->status===413);}
    echo "PASS: $checks normalization, malformed-array, UPLOAD_ERR, unsafe-name, MIME and size cases; temporary file only.\n";
}finally{unlink($tmp);}
