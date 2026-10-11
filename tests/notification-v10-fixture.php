<?php
/** Test-only in-memory contract support. Never load application configuration or installed services. */
class NotificationV10FixturePDO extends PDO
{
    public function prepare(string $sql,array $options=[]): PDOStatement|false { return parent::prepare(str_replace(' FOR UPDATE','',$sql),$options); }
}
function notification_fixture_sqlite(PDO $pdo): void
{
    $pdo->sqliteCreateFunction('NOW',fn()=>date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('GET_LOCK',fn($name,$timeout)=>1);
    $pdo->sqliteCreateFunction('RELEASE_LOCK',fn($name)=>1);
    $columns=[
        'notifications'=>['id'=>'INTEGER PRIMARY KEY AUTOINCREMENT','recipient_type'=>'TEXT','recipient_id'=>'INTEGER','recipient_user_id'=>'INTEGER','campaign_id'=>'INTEGER',
            'recipient_email'=>'TEXT','recipient_name'=>'TEXT','subject'=>'TEXT','message'=>'TEXT','type'=>'TEXT','status'=>"TEXT DEFAULT 'Sent'",'delivery_info'=>'TEXT',
            'scheduled_at'=>'TEXT','sent_at'=>'TEXT','read_at'=>'TEXT','archived_at'=>'TEXT','deleted_at'=>'TEXT','sending_started_at'=>'TEXT','created_by'=>'TEXT','created_at'=>'TEXT DEFAULT CURRENT_TIMESTAMP'],
        'notification_campaigns'=>['id'=>'INTEGER PRIMARY KEY AUTOINCREMENT','sender_user_id'=>'INTEGER','kind'=>'TEXT','support_category'=>'TEXT','reply_to_campaign_id'=>'INTEGER',
            'subject'=>'TEXT','message'=>'TEXT','status'=>'TEXT','scheduled_at'=>'TEXT','sent_at'=>'TEXT','archived_at'=>'TEXT','deleted_at'=>'TEXT','created_at'=>'TEXT DEFAULT CURRENT_TIMESTAMP'],
        'users'=>['id'=>'INTEGER PRIMARY KEY','password_hash'=>'TEXT','username'=>'TEXT','ref_id'=>'TEXT','full_name'=>'TEXT','email'=>'TEXT','role'=>'TEXT','status'=>"TEXT DEFAULT 'Active'"],
        'students'=>['id'=>'INTEGER PRIMARY KEY','student_id'=>'TEXT','user_id'=>'INTEGER','full_name'=>'TEXT','email'=>'TEXT','archived_at'=>'TEXT','profile_completed_at'=>"TEXT DEFAULT '2026-01-01'",'adviser_id'=>'INTEGER'],
        'advisers'=>['id'=>'INTEGER PRIMARY KEY','employee_id'=>'TEXT','user_id'=>'INTEGER','full_name'=>'TEXT','email'=>'TEXT','status'=>"TEXT DEFAULT 'Active'",'archived_at'=>'TEXT','profile_completed_at'=>"TEXT DEFAULT '2026-01-01'"],
        'activity_logs'=>['id'=>'INTEGER PRIMARY KEY AUTOINCREMENT','user_email'=>'TEXT','actor_name'=>'TEXT','actor_role'=>'TEXT','action'=>'TEXT','entity_type'=>'TEXT','entity_id'=>'TEXT','details'=>'TEXT','created_at'=>'TEXT DEFAULT CURRENT_TIMESTAMP'],
    ];
    foreach ($columns as $table=>$fields) {
        $pdo->exec('CREATE TABLE IF NOT EXISTS '.$table.' ('.implode(',',array_map(fn($key,$value)=>$key.' '.$value,array_keys($fields),$fields)).')');
        $existing=array_column($pdo->query('PRAGMA table_info('.$table.')')->fetchAll(PDO::FETCH_ASSOC),'name');
        foreach ($fields as $key=>$value) if (!in_array($key,$existing,true)) {
            // SQLite cannot add a nonconstant default; existing fixture rows still get a stable timestamp.
            $value=str_replace(' DEFAULT CURRENT_TIMESTAMP','',$value);
            $pdo->exec('ALTER TABLE '.$table.' ADD COLUMN '.$key.' '.$value);
            if ($key==='created_at') $pdo->exec('UPDATE '.$table.' SET created_at=CURRENT_TIMESTAMP');
        }
    }
}
