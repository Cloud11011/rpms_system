<?php
/** Private server worker. Synthetic credentials are read from a private test config file. */
if (PHP_SAPI!=='cli' || $argc!==2) exit(1);
$file=realpath($argv[1]);
if (!$file || !str_contains($file,'prism-reset-migration-')) exit(1);
$fixture=json_decode(file_get_contents($file),true,512,JSON_THROW_ON_ERROR);
require_once __DIR__.'/../includes/legal_policy.php';
class Batch6PausedStatement extends PDOStatement {
    protected function __construct(private ?string $gate) {}
    public function fetchAll(int $mode=PDO::FETCH_DEFAULT,mixed ...$args): array {
        $rows=parent::fetchAll($mode,...$args);
        if ($this->gate && $this->queryString==='SELECT * FROM users FORCE INDEX(PRIMARY) ORDER BY id FOR UPDATE') {
            file_put_contents($this->gate.'.ready','locked');
            $deadline=microtime(true)+15;
            while (!file_exists($this->gate) && microtime(true)<$deadline) usleep(10000);
            if (!file_exists($this->gate)) throw new RuntimeException('Publication gate timed out.');
        }
        return $rows;
    }
}
$pdo=new PDO($fixture['dsn'],'root',$fixture['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
if (realpath($pdo->query('SELECT @@datadir')->fetchColumn())!==realpath($fixture['datadir'])) exit(1);
$pdo->setAttribute(PDO::ATTR_STATEMENT_CLASS,[Batch6PausedStatement::class,[$fixture['gate']??null]]);
try {
    $mode=$fixture['mode'];
    if ($mode==='manage') {
        $user=legal_rows($pdo,'SELECT * FROM users WHERE id=?',[$fixture['actor']])[0];
        $result=legal_manage($pdo,$user,$fixture['data']);
    } elseif ($mode==='insert_admin') {
        $pdo->prepare("INSERT INTO users(username,password_hash,role,full_name,email,ref_id) VALUES ('RACE',?,'admin','Race Admin','race@example.invalid','RACE')")->execute([password_hash('Batch6 fixture passphrase',PASSWORD_DEFAULT)]); $result=true;
    } elseif ($mode==='deactivate_admin') { $pdo->exec("UPDATE users SET status='Inactive' WHERE id=".(int)$fixture['actor']); $result=true;
    } elseif ($mode==='consume') {
        $pdo->beginTransaction();
        $pdo->query('SELECT slot FROM staff_registration_code WHERE slot=1 FOR UPDATE')->fetchColumn();
        $result=$pdo->exec("UPDATE staff_registration_code SET state='consumed',consumed_at=NOW(),verifier_hash=NULL WHERE slot=1 AND state='acknowledged' AND expires_at>NOW()");
        $pdo->commit();
    } else throw new RuntimeException('Unknown fixture mode.');
    echo json_encode(['ok'=>true,'result'=>$result]);
} catch (LegalPolicyError $e) { echo json_encode(['ok'=>false,'status'=>$e->status]); }
catch (PDOException $e) {
    // Match the application's safe transient-DB failure behavior; no secret/error SQL in response.
    if (!in_array((int)($e->errorInfo[1]??0),[1020,1205,1213],true)) throw $e;
    echo json_encode(['ok'=>false,'status'=>503,'transient'=>true]);
}
