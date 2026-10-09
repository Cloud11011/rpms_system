<?php
/** Explicit read-only operator verification. Never calls db(), seed(), or migrate(). */
if(PHP_SAPI!=='cli' || $argc!==3 || $argv[1]!=='--verify' || $argv[2]!=='--schema-changes-excluded') {
    fwrite(STDERR,"Use --verify --schema-changes-excluded only after excluding concurrent schema changes.\n");
    exit(1);
}
require dirname(__DIR__).'/includes/account_identity.php';
require dirname(__DIR__).'/includes/account_lifecycle_schema.php';
try {
    // Credentials belong to a separately authorized verifier, not the normal web account.
    foreach(['PRISM_SCHEMA_VERIFY_HOST','PRISM_SCHEMA_VERIFY_DATABASE','PRISM_SCHEMA_VERIFY_USER','PRISM_SCHEMA_VERIFY_PASSWORD'] as $key) {
        if(getenv($key)===false || getenv($key)==='') throw new RuntimeException('Verifier configuration is incomplete.');
    }
    $pdo=new PDO('mysql:host='.getenv('PRISM_SCHEMA_VERIFY_HOST').';port='.(getenv('PRISM_SCHEMA_VERIFY_PORT')?:3306).
        ';dbname='.getenv('PRISM_SCHEMA_VERIFY_DATABASE').';charset=utf8mb4',getenv('PRISM_SCHEMA_VERIFY_USER'),getenv('PRISM_SCHEMA_VERIFY_PASSWORD'),
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $verification=lifecycle_privileged_verification($pdo);
    $pdo->rollBack();
    echo "Verified complete visible dependency inventory against the reviewed manifest.\n";
    echo "Operator configuration for this deployment only:\n";
    echo "define('PRISM_HARD_DELETE_SCHEMA_VERIFIED', true);\n";
    echo "define('PRISM_HARD_DELETE_VERIFICATION', ".var_export($verification,true).");\n";
} catch(Throwable $error) {
    if(isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR,"Verification refused (".get_class($error)."). Permanent deletion must remain disabled.\n");
    exit(1);
}
