<?php
/** Read-only capability inventory: deliberately never loads application config/bootstrap. */
function prism_ini_bytes(string $value): int
{
    $value=trim($value);
    if ($value==='-1') return PHP_INT_MAX;
    $factor=match(strtolower(substr($value,-1))) {'g'=>1073741824,'m'=>1048576,'k'=>1024,default=>1};
    return (int)((float)$value*$factor);
}

function prism_runtime_preflight(string $root, bool $integrations=false): array
{
    $checks=[];
    $add=static function(string $name,bool $required,bool $ok,string $guidance) use (&$checks): void {
        $checks[]=['capability'=>$name,'required'=>$required,'ok'=>$ok,'guidance'=>$ok?'Available.':$guidance];
    };
    $add('PHP >=8.1',true,PHP_VERSION_ID>=80100,'Select PHP 8.1 or newer for both the web application and cron.');
    foreach (['ctype','dom','fileinfo','iconv','libxml','mbstring','pdo','pdo_mysql','session','zip','zlib'] as $extension) {
        $add('ext-'.$extension,true,extension_loaded($extension),'Enable '.$extension.' in the PHP configuration used by this SAPI.');
    }
    $add('Argon2id',true,defined('PASSWORD_ARGON2ID') && in_array('argon2id',password_algos(),true),'Use a PHP build with Argon2id password support.');
    $integrationRequired=$integrations || (bool)getenv('OPENROUTER_API_KEY') || (bool)getenv('GMAIL_CLIENT_ID');
    $add('cURL (Gmail/OpenRouter)',$integrationRequired,extension_loaded('curl'),'Enable cURL before enabling Gmail or OpenRouter; local fallback does not prove email delivery.');
    $autoload=$root.'/vendor/autoload.php';
    $add('Composer autoload',true,is_file($autoload)&&is_readable($autoload),'Install composer.lock dependencies using composer install --no-dev --no-scripts --no-plugins.');
    $parser=false;
    if (is_file($autoload)) {
        try { require_once $autoload; $parser=class_exists('Smalot\\PdfParser\\Parser'); }
        catch (Throwable $e) { /* Report only capability state; never include local exception details. */ }
    }
    $add('Locked PDF parser',true,$parser,'Install the locked smalot/pdfparser package and verify the autoloader.');
    foreach (['storage','storage/documents','storage/reports'] as $relative) {
        $dir=$root.'/'.$relative; $existing=$dir;
        while (!is_dir($existing) && dirname($existing)!==$existing) $existing=dirname($existing);
        $add($relative.' permissions',true,is_dir($dir)&&is_readable($dir)&&is_writable($dir),
            is_writable($existing)?'Create this private directory for the PHP account before deployment.':'Grant the PHP account read/write access to this private directory.');
    }
    foreach (['upload_tmp_dir','session.save_path'] as $setting) {
        $dir=(string)ini_get($setting); if (str_contains($dir,';')) $dir=substr($dir,strrpos($dir,';')+1);
        if ($dir==='') $dir=sys_get_temp_dir();
        $add($setting.' permissions',true,is_dir($dir)&&is_writable($dir),'Configure a private writable directory for this PHP SAPI.');
    }
    $add('file_uploads',true,(bool)ini_get('file_uploads'),'Enable file uploads for document submission.');
    $add('20 MiB upload limit',true,prism_ini_bytes((string)ini_get('upload_max_filesize'))>=20*1024*1024,'Set upload_max_filesize to at least 20M (application file limit).');
    $add('Multipart POST capacity',true,prism_ini_bytes((string)ini_get('post_max_size'))>20*1024*1024,'Set post_max_size above 20M and allow multipart overhead; increase for intended multi-file batches.');
    $failed=array_values(array_filter($checks,static fn($c)=>$c['required']&&!$c['ok']));
    return ['ok'=>!$failed,'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,'read_only'=>true,'checks'=>$checks,
        'database'=>'NOT VERIFIED; use the explicit --database option with isolated/authorized read-only credentials.'];
}

/** No migrate(), seed(), DDL or application records are written.
 * Schemas 11-15 also run the complete reviewed contract comparison used by the migration runners.
 */
function prism_runtime_database_preflight(PDO $pdo): array
{
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    try {
        $version=(string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn();
        $supported=['10','11','12','13','14','15'];
        $manifest=json_decode(file_get_contents(__DIR__.($version==='10'?'/account_lifecycle_schema.json':'/account_lifecycle_schema_v11.json')),true,512,JSON_THROW_ON_ERROR);
        $missing=[];
        $q=$pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        foreach ($manifest['columns'] as $column) {
            $q->execute([$column['TABLE_NAME'],$column['COLUMN_NAME']]);
            if ((int)$q->fetchColumn()!==1) $missing[]=$column['TABLE_NAME'].'.'.$column['COLUMN_NAME'];
        }
        $result=['schema_version'=>$version,'missing_objects'=>$missing,'server_version'=>(string)$pdo->query('SELECT VERSION()')->fetchColumn()];
        require_once __DIR__.'/schema_collation.php';
        try { $result['legacy_collation']=schema_extension_collation($pdo); }
        catch (SchemaCollationError $e) { $result['legacy_collation']='UNSUPPORTED: '.$e->getMessage(); }
        $contract='NOT CHECKED';
        if ($version!=='10' && in_array($version,$supported,true)) {
            require_once __DIR__.'/account_lifecycle.php';
            try { lifecycle_compare_schema($pdo); $contract='PASS'; }
            catch (Throwable $e) {
                $contract='FAIL';
                // Contract messages name tables and checks only, never data or credentials.
                $reasons=[]; for ($x=$e; $x; $x=$x->getPrevious()) $reasons[]=$x->getMessage();
                $result['contract_failure']=array_values(array_unique($reasons));
            }
        }
        return ['ok'=>in_array($version,$supported,true)&&!$missing&&$contract!=='FAIL','contract'=>$contract]+$result
            +['boundary'=>$version==='10'?'Read-only column presence check; not complete index/constraint or authenticated workflow verification.'
                :'Read-only exact reviewed schema contract (v11 manifest, frozen constraints and pinned extension definitions); not authenticated workflow verification.'];
    } finally { $pdo->rollBack(); }
}
