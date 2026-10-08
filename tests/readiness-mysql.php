<?php
// Included only by the private temporary-server harness. No application config is loaded.
date_default_timezone_set('Asia/Manila');
$pdo->exec('CREATE DATABASE readiness_test');
$pdo->exec('USE readiness_test');
$pdo->exec("CREATE TABLE users (id INT PRIMARY KEY, role VARCHAR(20)); INSERT INTO users VALUES (1,'admin')");
$pdo->exec("CREATE TABLE activity_logs (id INT AUTO_INCREMENT PRIMARY KEY, action VARCHAR(50), user_email VARCHAR(100), created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE password_resets (id INT AUTO_INCREMENT PRIMARY KEY,user_id INT,token VARCHAR(80),expires_at DATETIME,used INT DEFAULT 0,created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
$pdo->exec("CREATE TABLE notifications (id INT AUTO_INCREMENT PRIMARY KEY, status VARCHAR(20), scheduled_at DATETIME, created_at DATETIME DEFAULT CURRENT_TIMESTAMP)");
foreach(['DB_HOST'=>'127.0.0.1','DB_PORT'=>$port,'DB_NAME'=>'readiness_test','DB_USER'=>'root','DB_PASS'=>$password] as $key=>$value)define('PrismReadinessSQL\\'.$key,$value);
$source=file_get_contents(dirname(__DIR__).'/config.php');
function readiness_function(string $source,string $name):string {
    $source=str_replace("\r\n","\n",$source);$start=strpos($source,'function '.$name.'(');$end=strpos($source,"\n}",$start);
    if($start===false||$end===false)throw new RuntimeException('Reviewed function missing');return substr($source,$start,$end+2-$start);
}
eval('namespace PrismReadinessSQL; use \PDO; use \Throwable; use \RuntimeException; const APP_BASE_URL="https://fixture.invalid";'
    .'function migrate(PDO $pdo):void { if($pdo->query("SELECT @@session.time_zone")->fetchColumn()!=="+08:00")throw new RuntimeException("Timezone must precede migration checks"); }'
    .'function seed(PDO $pdo):void { throw new RuntimeException("Unexpected seed"); }'
    .'function app_base_url_is_valid():bool{return true;} function log_api_error(...$args):void{throw new RuntimeException("Unexpected error");}'
    .'function send_notification_email(...$args):array{return ["ok"=>true,"channel"=>"fixture"];}'
    .readiness_function($source,'db').readiness_function($source,'too_many_recent_failures').readiness_function($source,'send_account_setup_email'));
$connection=PrismReadinessSQL\db();
$row=$connection->query('SELECT NOW() AS mysql_now, @@session.time_zone AS session_timezone')->fetch();
reset_migration_expect('+08:00',$row['session_timezone'],'PRISM session timezone');
reset_migration_expect(true,abs(strtotime($row['mysql_now'])-time())<=2,'NOW matches Manila time');
echo 'PRISM PDO verification: '.json_encode($row)."\n";
$insert=$connection->prepare("INSERT INTO activity_logs (action,user_email) VALUES ('login_failed',?)");
for($i=0;$i<8;$i++)$insert->execute(['recent@example.test']);
reset_migration_expect(true,PrismReadinessSQL\too_many_recent_failures('recent@example.test'),'Recent database-generated failures throttle');
$connection->exec("UPDATE activity_logs SET created_at=DATE_SUB(NOW(),INTERVAL 16 MINUTE)");
reset_migration_expect(false,PrismReadinessSQL\too_many_recent_failures('recent@example.test'),'Expired throttle window');
PrismReadinessSQL\send_account_setup_email($connection,1,'student@example.test','Student');
$remaining=(int)$connection->query('SELECT TIMESTAMPDIFF(SECOND,NOW(),expires_at) FROM password_resets ORDER BY id DESC LIMIT 1')->fetchColumn();
reset_migration_expect(true,$remaining>=3598&&$remaining<=3600,'Setup token is valid for one hour, not nine');
// Execute the unchanged actual password-reset token issuance block through PRISM PDO.
$endpoint=str_replace("\r\n","\n",file_get_contents(dirname(__DIR__).'/forgot_password_process.php'));
$start=strpos($endpoint,'            $token = bin2hex(random_bytes(32));');
$end=strpos($endpoint,"        }\n        \$pdo->commit();",$start);
reset_migration_expect(true,$start!==false&&$end!==false,'Reset issuance block isolation');
$user=['id'=>2];$pdo=$connection;eval(substr($endpoint,$start,$end-$start));
$remaining=(int)$pdo->query('SELECT TIMESTAMPDIFF(SECOND,NOW(),expires_at) FROM password_resets WHERE user_id=2')->fetchColumn();
reset_migration_expect(true,$remaining>=3598&&$remaining<=3600,'Password reset expires in about one hour');
$pdo->exec("INSERT INTO notifications (status,scheduled_at) VALUES ('Scheduled',DATE_SUB(NOW(),INTERVAL 1 SECOND)),('Scheduled',DATE_ADD(NOW(),INTERVAL 1 MINUTE))");
reset_migration_expect(1,(int)$pdo->query("SELECT COUNT(*) FROM notifications WHERE status='Scheduled' AND scheduled_at<=NOW()")->fetchColumn(),'Only due scheduled record is eligible');
reset_migration_expect(true,abs(strtotime($pdo->query('SELECT created_at FROM notifications LIMIT 1')->fetchColumn())-time())<=2,'New CURRENT_TIMESTAMP uses Manila');
$pdo->exec('UPDATE password_resets SET expires_at=DATE_SUB(NOW(),INTERVAL 1 SECOND)');
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM password_resets WHERE used=0 AND expires_at>NOW()')->fetchColumn(),'Expired reset/setup tokens rejected');
echo "PASS: Manila timezone, bootstrap ordering, throttle, reset/setup expiry, scheduled due time and generated timestamps.\n";
