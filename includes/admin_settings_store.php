<?php

function prism_settings_store_available(PDO $pdo): bool
{
    static $connections;
    $connections??=new WeakMap();
    if (isset($connections[$pdo])) return $connections[$pdo];
    if ((string)$pdo->query("SELECT v FROM schema_meta WHERE k='prism_settings_v1'")->fetchColumn()!=='1') return false;
    $q=$pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE()
        AND TABLE_NAME IN ('prism_settings','prism_deadline_reminder_dispatches','academic_year_removals')");
    $q->execute();
    $available=(int)$q->fetchColumn()===3;
    if ($available) {
        require_once __DIR__.'/admin_settings_schema.php';
        prism_admin_settings_schema_verify($pdo);
        $connections[$pdo]=true;
    }
    return $available;
}

function prism_settings_get(PDO $pdo,string $key,mixed $default): mixed
{
    if (!prism_settings_store_available($pdo)) return $default;
    $q=$pdo->prepare('SELECT setting_value FROM prism_settings WHERE setting_key=?');
    $q->execute([$key]); $value=$q->fetchColumn();
    return $value===false?$default:json_decode((string)$value,true,32,JSON_THROW_ON_ERROR);
}

function prism_settings_save(PDO $pdo,array $actor,string $key,mixed $value): void
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may change these settings.');
    if (!prism_settings_store_available($pdo)) throw new AccountLifecycleConflict('Communication settings require the approved settings migration.');
    if ($pdo->inTransaction()) throw new LogicException('Settings save owns its transaction.');
    prism_settings_lock($pdo);
    try {
        $pdo->beginTransaction();
        $live=prism_settings_live_actor($pdo,$actor);
        $q=$pdo->prepare('SELECT setting_value FROM prism_settings WHERE setting_key=? FOR UPDATE');
        $q->execute([$key]); $before=$q->fetchColumn();
        prism_settings_write($pdo,$live,$key,$value,$before);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { prism_settings_unlock($pdo); }
}

function prism_settings_update_template(PDO $pdo,array $actor,string $key,string $template,array $defaults): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may change these settings.');
    if (!prism_settings_store_available($pdo)) throw new AccountLifecycleConflict('Communication settings require the approved settings migration.');
    if ($pdo->inTransaction()) throw new LogicException('Settings save owns its transaction.');
    prism_settings_lock($pdo);
    try {
        $pdo->beginTransaction();
        $live=prism_settings_live_actor($pdo,$actor);
        $q=$pdo->prepare('SELECT setting_value FROM prism_settings WHERE setting_key=? FOR UPDATE');
        $q->execute(['communication.notification_templates']); $before=$q->fetchColumn();
        $templates=$before===false?$defaults:json_decode((string)$before,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($templates)) throw new RuntimeException('Stored notification templates are invalid.');
        $templates[$key]=$template;
        prism_settings_write($pdo,$live,'communication.notification_templates',$templates,$before);
        $pdo->commit();
        return $templates;
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    finally { prism_settings_unlock($pdo); }
}

function prism_settings_lock(PDO $pdo): void
{
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance is busy. Try again.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_settings_configuration',5)")->fetchColumn()!==1) {
        $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
        throw new AccountLifecycleConflict('Another administrator is saving settings. Try again.');
    }
}

function prism_settings_unlock(PDO $pdo): void
{
    $pdo->query("SELECT RELEASE_LOCK('prism_settings_configuration')");
    $pdo->query("SELECT RELEASE_LOCK('prism_migrate')");
}

function prism_settings_live_actor(PDO $pdo,array $actor): array
{
    $live=current_workflow_actor($pdo,$actor);
    if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible RPMS Administrator is required.');
    return $live;
}

function prism_settings_write(PDO $pdo,array $live,string $key,mixed $value,mixed $before): void
{
    require_once __DIR__.'/document_workflow.php';
    $encoded=json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    $beforeHash=$before===false?null:'sha256:'.hash('sha256',(string)$before);
    if ($before!==false && hash_equals((string)$before,$encoded)) return;
    $q=$pdo->prepare('INSERT INTO prism_settings(setting_key,setting_value,updated_by_user_id) VALUES (?,?,?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),updated_by_user_id=VALUES(updated_by_user_id),updated_at=CURRENT_TIMESTAMP(6)');
    $q->execute([$key,$encoded,$live['id']]);
    document_workflow_audit($pdo,$live,'communication_setting_updated',[
        'entity_type'=>'communication_setting','entity_id'=>$key,'student_id'=>null,
        'before'=>$beforeHash,'after'=>'sha256:'.hash('sha256',$encoded),
        'details'=>'Communication configuration updated; credential and provider settings were not changed.',
    ]);
}

function prism_notification_template_defaults(): array
{
    return [
        'document_submission'=>'{message}',
        'adviser_review'=>'{message}',
        'document_status'=>'{message}',
        'rpms_correction'=>'{message}',
        'deadline_reminder'=>'{message}',
    ];
}

function prism_notification_template_key(string $type,string $subject): ?string
{
    if ($type==='Deadline Reminder' || ($type==='Reminder' && $subject==='Official deadline reminder')) return 'deadline_reminder';
    if ($type==='Review Needed') return 'adviser_review';
    if ($type!=='Status Update') return null;
    if (preg_match('/correction|resubmission|revision/i',$subject)) return 'rpms_correction';
    if (preg_match('/submission|submitted/i',$subject)) return 'document_submission';
    if ($subject==='PRISM Document Status Update') return 'document_status';
    return null;
}

function prism_notification_apply_template(PDO $pdo,string $type,string $subject,string $recipient,string $message): string
{
    $key=prism_notification_template_key($type,$subject);
    if ($key===null) return $message;
    $templates=prism_settings_get($pdo,'communication.notification_templates',prism_notification_template_defaults());
    $template=$templates[$key]??null;
    if (!is_string($template)) return $message;
    return strtr($template,['{recipient}'=>$recipient,'{message}'=>$message]);
}
