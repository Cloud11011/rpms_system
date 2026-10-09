<?php
/** Recoverable same-volume quarantine. Never deletes source files before DB commit. */
function purge_storage_root(): string
{
    $path=defined('STORAGE_DIR')?STORAGE_DIR:dirname(__DIR__).'/storage';
    $root=realpath($path);
    if ($root===false || is_link($path) || !is_dir($root)) throw new AccountLifecycleConflict('Private purge storage is unavailable.');
    return $root;
}

function purge_journal_root(): string
{
    $root=purge_storage_root().DIRECTORY_SEPARATOR.'.purge';
    if (!is_dir($root) && !@mkdir($root,0700)) throw new AccountLifecycleConflict('Cannot prepare private purge recovery storage.');
    if (is_link($root) || realpath($root)!==$root) throw new AccountLifecycleConflict('Unsafe purge recovery storage.');
    return $root;
}

function purge_file_path(string $kind,string $name): string
{
    if (!in_array($kind,['documents','reports'],true) || $name==='' || basename($name)!==$name
        || preg_match('~[\\\\/\x00-\x1f:]~',$name) || in_array($name,['.','..'],true)) {
        throw new AccountLifecycleConflict('Unsafe stored file name; Admin review required.');
    }
    $root=purge_storage_root().DIRECTORY_SEPARATOR.$kind;
    if (is_link($root) || !is_dir($root) || realpath($root)!==$root) throw new AccountLifecycleConflict('Unsafe private file directory.');
    $path=$root.DIRECTORY_SEPARATOR.$name;
    if (is_link($path) || (file_exists($path) && (realpath($path)!==$path || !is_file($path)))) {
        throw new AccountLifecycleConflict('Unsafe or shared file path; Admin review required.');
    }
    return $path;
}

function purge_manifest_read(string $job): array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/',$job)) throw new AccountLifecycleValidation('Invalid recovery job.');
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$job;
    if (is_link($dir) || !is_dir($dir) || realpath($dir)!==$dir) throw new AccountLifecycleConflict('Recovery journal is unavailable.');
    $path=$dir.DIRECTORY_SEPARATOR.'manifest.json';
    if (is_link($path) || !is_file($path) || filesize($path)>1048576) throw new AccountLifecycleConflict('Unsafe recovery journal.');
    try { $manifest=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR); }
    catch(Throwable $e) { throw new AccountLifecycleConflict('Corrupt recovery journal; operator review required.'); }
    if (($manifest['version']??null)!==1 || ($manifest['job']??null)!==$job || !in_array($manifest['type']??null,['student','adviser'],true)
        || !is_int($manifest['id']??null) || !is_array($manifest['files']??null) || count($manifest['files'])>2000) {
        throw new AccountLifecycleConflict('Invalid recovery journal; operator review required.');
    }
    foreach ($manifest['files'] as $i=>$file) {
        if (!is_int($i) || !is_string($file['name']??null) || !preg_match('/\A[a-f0-9]{64}\z/',$file['sha256']??'')
            || !is_int($file['size']??null)) throw new AccountLifecycleConflict('Invalid recovery file evidence.');
        purge_file_path($file['kind']??'',$file['name']);
    }
    return $manifest;
}

function purge_journal_candidates(PDO $pdo): array
{
    $root=purge_journal_root(); $dirs=glob($root.DIRECTORY_SEPARATOR.'*',GLOB_ONLYDIR)?:[];
    if (count($dirs)>1000) throw new AccountLifecycleConflict('Too many pending recovery journals; operator review required.');
    $binding=lifecycle_database_binding($pdo); $out=[];
    foreach ($dirs as $dir) {
        $job=basename($dir);
        if (!preg_match('/\A[a-f0-9]{32}\z/',$job)) throw new AccountLifecycleConflict('Unexpected private recovery directory.');
        $m=purge_manifest_read($job);
        if (($m['database']??null)!==$binding) throw new AccountLifecycleConflict('Recovery journal belongs to a different database/server.');
        $out[]=['jobId'=>$job,'accountType'=>$m['type'],'targetId'=>$m['id']];
    }
    return $out;
}

function purge_require_no_pending(PDO $pdo,string $type,int $id): void
{
    foreach (purge_journal_candidates($pdo) as $job) if ($job['accountType']===$type && $job['targetId']===$id) {
        throw new AccountLifecycleConflict('Purge recovery required for job '.$job['jobId'].'. Review Recovery before changing this account.');
    }
}

/** Include committed unfinished jobs even if an operator/file fault removed their disk journal. */
function purge_recovery_jobs(PDO $pdo): array
{
    $disk=purge_journal_candidates($pdo);$jobs=[];
    foreach($disk as $item)$jobs[$item['jobId']]=$item+['manifestAvailable'=>true];
    $rows=lifecycle_rows($pdo,'SELECT id,account_type,account_id FROM account_purge_jobs WHERE status<>"complete" ORDER BY created_at LIMIT 1001');
    if(count($rows)>1000)throw new AccountLifecycleConflict('Too many unfinished DB purge jobs; operator review required.');
    foreach($rows as $row)if(!isset($jobs[$row['id']]))$jobs[$row['id']]=['jobId'=>$row['id'],'accountType'=>$row['account_type'],'targetId'=>(int)$row['account_id'],'manifestAvailable'=>false];
    return array_values($jobs);
}

/** Called with account/child locks held. Shared DB paths are checked by the purge planner. */
function purge_files_prepare(PDO $pdo,string $type,int $id,array $files): array
{
    if (count($files)>2000) throw new AccountLifecycleConflict('Account exceeds the 2,000-file purge limit; operator review required.');
    $job=bin2hex(random_bytes(16)); $items=[]; $seen=[]; $bytes=0;
    foreach ($files as $file) {
        $path=purge_file_path($file['kind'],$file['name']);
        if (isset($seen[$path])) throw new AccountLifecycleConflict('Multiple records share a stored file; Admin review required.');
        $seen[$path]=true;
        if (!is_file($path)) continue; // An already absent file has nothing to finalize.
        $stat=stat($path);
        $bytes+=(int)($stat['size']??0);
        if ($bytes>268435456) throw new AccountLifecycleConflict('Account files exceed the 256 MiB purge preparation limit; Admin review required.');
        if (($stat['nlink']??1)>1) throw new AccountLifecycleConflict('Hard-linked file ownership is ambiguous.');
        $hash=hash_file('sha256',$path);
        if ($hash===false || !is_readable($path)) throw new AccountLifecycleConflict('File cannot be prepared for recoverable purge.');
        $items[]=$file+['sha256'=>$hash,'size'=>(int)filesize($path)];
    }
    $manifest=['version'=>1,'job'=>$job,'database'=>lifecycle_database_binding($pdo),'type'=>$type,'id'=>$id,'files'=>$items];
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$job;
    if (!@mkdir($dir,0700)) throw new AccountLifecycleConflict('Cannot prepare recovery journal.');
    $json=json_encode($manifest,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
    $handle=@fopen($dir.DIRECTORY_SEPARATOR.'manifest.json','x+b');
    if (!$handle) { @rmdir($dir); throw new AccountLifecycleConflict('Cannot persist recovery journal.'); }
    $writeFailure=null;
    try {
        if (fwrite($handle,$json)!==strlen($json) || !fflush($handle) || (function_exists('fsync') && !fsync($handle))) {
            throw new AccountLifecycleConflict('Cannot flush recovery journal.');
        }
    } catch(Throwable $error) { $writeFailure=$error; }
    finally { fclose($handle); }
    if ($writeFailure) { @unlink($dir.DIRECTORY_SEPARATOR.'manifest.json'); @rmdir($dir); throw $writeFailure; }
    return ['job'=>$job,'manifest'=>$manifest,'hash'=>hash('sha256',$json)];
}

function purge_file_matches(string $path,array $file): bool
{
    return !is_link($path) && is_file($path) && filesize($path)===$file['size']
        && hash_equals($file['sha256'],(string)hash_file('sha256',$path));
}

function purge_files_stage(array $prepared): void
{
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$prepared['job'];
    foreach ($prepared['manifest']['files'] as $i=>$file) {
        $source=purge_file_path($file['kind'],$file['name']); $destination=$dir.DIRECTORY_SEPARATOR.$i.'.file';
        if (!purge_file_matches($source,$file) || file_exists($destination) || !@rename($source,$destination)) {
            throw new AccountLifecycleConflict('File staging failed; purge was not committed. Recovery job '.$prepared['job'].'.');
        }
    }
}

/** Idempotent recovery, never overwrites another file. */
function purge_files_restore(string $job,array $manifest): void
{
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$job;
    foreach ($manifest['files'] as $i=>$file) {
        $source=purge_file_path($file['kind'],$file['name']); $staged=$dir.DIRECTORY_SEPARATOR.$i.'.file';
        if (file_exists($staged) || is_link($staged)) {
            if (!purge_file_matches($staged,$file) || file_exists($source) || !@rename($staged,$source)) {
                throw new AccountLifecycleConflict('File restoration requires operator review. Recovery job '.$job.'.');
            }
        } elseif (!purge_file_matches($source,$file)) {
            throw new AccountLifecycleConflict('Recovery source changed; operator review required. Job '.$job.'.');
        }
    }
    purge_files_remove_journal($job);
}

function purge_files_remove_journal(string $job): void
{
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$job;
    $entries=array_values(array_diff(scandir($dir)?:[],['.','..','manifest.json']));
    if ($entries) throw new AccountLifecycleConflict('Unexpected recovery files remain. Job '.$job.'.');
    if (!@unlink($dir.DIRECTORY_SEPARATOR.'manifest.json') || !@rmdir($dir)) {
        throw new AccountLifecycleConflict('Recovery journal cleanup is incomplete. Job '.$job.'.');
    }
}

/** Only callable after positively establishing the durable committed job. */
function purge_files_finalize(PDO $pdo,string $job,array $manifest): void
{
    $rows=lifecycle_rows($pdo,'SELECT * FROM account_purge_jobs WHERE id=?',[$job]);
    if (!$rows || !hash_equals($rows[0]['manifest_sha256'],hash_file('sha256',purge_journal_root().DIRECTORY_SEPARATOR.$job.DIRECTORY_SEPARATOR.'manifest.json'))) {
        throw new AccountLifecycleConflict('Committed purge recovery evidence is invalid. Job '.$job.'.');
    }
    $dir=purge_journal_root().DIRECTORY_SEPARATOR.$job;
    foreach ($manifest['files'] as $i=>$file) {
        $path=$dir.DIRECTORY_SEPARATOR.$i.'.file';
        if (file_exists($path) || is_link($path)) {
            if (!purge_file_matches($path,$file) || !@unlink($path)) {
                throw new AccountLifecycleConflict('Database purge committed; file finalization is incomplete. Recovery job '.$job.'.');
            }
        }
        // Files at the old source after commit cannot be deleted by a retry.
    }
    $pdo->prepare('UPDATE account_purge_jobs SET status="complete",completed_at=COALESCE(completed_at,NOW()) WHERE id=?')->execute([$job]);
    purge_files_remove_journal($job);
}
