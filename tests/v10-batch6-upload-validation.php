<?php
namespace PrismBatch6Upload;
if (PHP_SAPI!=='cli') exit(1);
function is_uploaded_file(string $path): bool { return true; }
foreach (['office_container','document_upload'] as $helper) {
    $source=file_get_contents(__DIR__.'/../includes/'.$helper.'.php');
    eval('namespace '.__NAMESPACE__.'; use \\RuntimeException; use \\finfo; use \\ZipArchive; '.preg_replace('/^<\?php\s*/','',$source));
}
$dir=sys_get_temp_dir().'/prism-batch6-validation-'.bin2hex(random_bytes(6));mkdir($dir);$checks=0;
function check(bool $ok,string $label): void { ++$GLOBALS['checks']; if (!$ok) throw new \RuntimeException($label); }
function file_input(string $name,string $path): array { return ['name'=>$name,'tmp_name'=>$path,'size'=>filesize($path),'type'=>'spoofed/client-mime','error'=>UPLOAD_ERR_OK]; }
function rejected(array $file): void { try { prism_validate_upload($file);check(false,'Invalid document accepted: '.$file['name']); } catch (DocumentUploadError $e) { check(in_array($e->status,[400,413,415,422],true),'Format failure safe status'); } }
try {
    $pdf=__DIR__.'/fixtures/document-summary/academic-research.pdf';
    $docx=__DIR__.'/fixtures/document-summary/research-protocol.docx';
    foreach (['valid.pdf','VALID.PDF'] as $name) check(prism_validate_upload(file_input($name,$pdf))['ext']==='pdf','Genuine PDF');
    foreach (['valid.docx','VALID.DOCX','valid.DoCx'] as $name) check(prism_validate_upload(file_input($name,$docx))['ext']==='docx','Genuine DOCX');
    foreach (['doc','docm','dot','dotx','rtf','txt','csv','xls','xlsx','xlsm','ppt','pptx','odt','ods','odp','jpg','jpeg','png','gif','webp','svg','zip','rar','7z','html','htm','php','js','exe','bat','cmd','ps1'] as $ext) rejected(file_input('file.'.$ext,$pdf));
    foreach (['file.pdf.exe','file.docx.php','file.pdf.jpg','file.docx.zip','../file.pdf','C:\\file.pdf',"file\0.pdf",'file.php.pdf'] as $name) rejected(file_input($name,$pdf));
    $fake=$dir.'/fake';file_put_contents($fake,'MZ executable fixture'); rejected(file_input('fake.pdf',$fake)); rejected(file_input('fake.docx',$fake));
    file_put_contents($fake,"%PDF-1.7\nUnrelated executable fixture\n%%EOF"); rejected(file_input('fake.pdf',$fake));
    file_put_contents($fake,''); rejected(file_input('zero.pdf',$fake)); rejected(file_input('zero.docx',$fake));
    foreach (['arbitrary','xlsx','pptx','macro','malformed','entity','external-main','missing-main'] as $kind) {
        $zip=new \ZipArchive();$path=$dir.'/'.$kind.'.zip';$zip->open($path,\ZipArchive::CREATE);
        $ct='<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>';
        $rel='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>';
        $word='<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p/></w:body></w:document>';
        if ($kind==='arbitrary') { $zip->addFromString('one.txt','arbitrary'); $zip->addFromString('two.txt','arbitrary'); }
        else {
            if ($kind==='xlsx') $zip->addFromString('xl/workbook.xml','<workbook/>');
            if ($kind==='pptx') $zip->addFromString('ppt/presentation.xml','<presentation/>');
            if ($kind==='macro') $ct=str_replace('application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml','application/vnd.ms-word.document.macroEnabled.main+xml',$ct);
            if ($kind==='malformed') $word='<w:document';
            if ($kind==='entity') $word='<!DOCTYPE x [<!ENTITY x SYSTEM "file:///never-read">]>'.$word;
            if ($kind==='external-main') $rel=str_replace('Target="word/document.xml"','Target="https://evil.invalid" TargetMode="External"',$rel);
            if ($kind==='missing-main') $rel='<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"/>';
            $zip->addFromString('[Content_Types].xml',$ct);$zip->addFromString('_rels/.rels',$rel);$zip->addFromString('word/document.xml',$word);
        }
        $zip->close(); rejected(file_input('fake.docx',$path));
    }
    $file=file_input('valid.pdf',$pdf);
    check(count(prism_upload_files($file))===1,'Single transport');check(count(prism_upload_files(array_map(fn($v)=>[$v,$v],$file)))===2,'Multiple transport');
    foreach ([null,[],['name'=>[]],array_replace(array_map(fn($v)=>[$v,$v],$file),['size'=>[1]])] as $bad) {
        try {prism_upload_files($bad);check(false,'Malformed array accepted');}catch(DocumentUploadError $e){check($e->status===400,'Malformed transport');}
    }
    foreach ([1,2,3,4,6,7,8,99] as $code) rejected(array_replace($file,['error'=>$code]));
    $large=$dir.'/large';$handle=fopen($large,'wb');ftruncate($handle,21*1024*1024);fclose($handle);rejected(file_input('large.pdf',$large));
    echo "PASS: $checks PDF/DOCX allowlist, uppercase, MIME, OOXML, macro/container/XML spoof, filename, transport and size assertions.\n";
} finally { foreach (glob($dir.'/*') as $path) unlink($path); rmdir($dir); }
