<?php
/** Private-instance API worker: never bootstraps config.php, db(), seed() or migrations. */
if(PHP_SAPI!=='cli' || $argc!==2) exit(1);
$case=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
class B105PDO extends PDO {
    public int $queries=0;
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false {
        ++$this->queries; return $fetchMode===null?parent::query($query):parent::query($query,$fetchMode,...$fetchModeArgs);
    }
    public function prepare(string $query,array $options=[]): PDOStatement|false {
        ++$this->queries; return parent::prepare($query,$options);
    }
}
$pdo=new B105PDO($case['dsn'],$case['dbUser']??'root',$case['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if(realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($case['datadir'])) exit(1);
require_once __DIR__.'/../includes/account_lifecycle.php';
define('SCHEMA_VERSION',10);
define('PRISM_HARD_DELETE_SCHEMA_VERIFIED',$case['enabled']??true);
if(empty($case['missing'])) define('PRISM_HARD_DELETE_VERIFICATION',$case['evidence']);
define('PRISM_HARD_DELETE_VERIFICATION_KEY',$case['key']??str_repeat('a',64));
define('STORAGE_DIR',dirname($case['datadir']).'/storage');
define('STAGE_SEQUENCE',['Stage 1','Stage 2','Stage 3','Stage 4','Stage 5','Completed']);
session_save_path($case['datadir']); session_id('persistent'.bin2hex(random_bytes(8))); session_start();
$_SESSION['user_id']=901;
function stage_label(string $stage): string { return $stage; }
function db(): PDO { return $GLOBALS['pdo']; }
function api_require_login($roles): array {
    $actor=db()->query('SELECT * FROM users WHERE id=901')->fetch();
    if(!in_array($actor['role'],(array)$roles,true)) json_out(['ok'=>false],403);
    return $actor;
}
function require_post_same_origin(): void {}
function json_body(...$args): array { return $GLOBALS['case']['data']; }
function json_out(array $data,int $status=200): never {
    echo json_encode(['status'=>$status,'data'=>$data,'queries'=>db()->queries,'milliseconds'=>(microtime(true)-$GLOBALS['started'])*1000],JSON_THROW_ON_ERROR); exit;
}
ini_set('error_log',dirname($case['datadir']).'/verification-errors.log');
if(isset($case['fkChecks'])) $pdo->exec('SET SESSION foreign_key_checks='.(int)$case['fkChecks']);
if(!empty($case['metadataError'])) {
    $pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[B105DeniedStatement::class]);
}
class B105DeniedStatement extends PDOStatement {
    public function execute(?array $params=null): bool {
        if(str_contains($this->queryString,'INFORMATION_SCHEMA')) throw new PDOException('Fixture metadata query denied');
        return parent::execute($params);
    }
}
$pdo->queries=0; $started=microtime(true);
if(($case['mode']??'availability')==='availability') {
    $_SERVER['REQUEST_METHOD']='GET'; $_GET['action']='availability';
} else $_SERVER['REQUEST_METHOD']='POST';
// Execute the unchanged production API dispatch after replacing only its configuration bootstrap.
$source=substr(file_get_contents(__DIR__.'/../account_lifecycle_api.php'),5);
$source=str_replace("require __DIR__.'/config.php';",'',$source);
eval(str_replace('__DIR__','dirname(__DIR__)',$source));
