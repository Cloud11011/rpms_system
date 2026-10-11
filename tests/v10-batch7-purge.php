<?php
/** Existing account lifecycle and frozen FK behavior on private fixtures. */
if (!isset($pdo,$datadir)) exit(1);
$actor=b7_row('users',1); [$cid,$copies]=b7_send();
notification_transition($pdo,b7_row('users',3),'archive',$copies[0]);
notification_transition($pdo,b7_row('users',3),'delete',$copies[0],true);
$tombstone=b7_row('notifications',$copies[0]);
$result=lifecycle_execute($pdo,$actor,['accountType'=>'admin','action'=>'permanent_delete','targetId'=>1,
    'currentPassword'=>'Batch7 fixture password','confirmation'=>'DELETE','confirmed'=>true]);
reset_migration_expect(true,$result['ok'],'Existing Admin self-purge completes');
$campaign=b7_row('notification_campaigns',$cid);
reset_migration_expect(null,$campaign['sender_user_id'],'Purged sender reference SET NULL');
reset_migration_expect(null,$campaign['message'],'Sender body erased before FK nulling');
reset_migration_expect('PRIVATE BODY',b7_row('notifications',$copies[1])['message'],'Other recipient survives sender-account purge');
$actor=b7_row('users',2);
// Adviser sender purge uses the retention workflow too.
$actor=b7_row('users',5); [$adviserCampaign,$adviserCopies]=b7_send('workflow'); $actor=b7_row('users',2);
foreach ([['adviser',20,'ADV1'],['student',10,'S1']] as [$type,$target,$confirmation]) {
    retention_change($pdo,$actor,['accountType'=>$type,'targetId'=>$target,'action'=>'archive','expectedAssignedStudents'=>0]);
    $input=['accountType'=>$type,'targetId'=>$target,'action'=>'permanent_delete','currentPassword'=>'Batch7 fixture password','confirmation'=>$confirmation,'confirmed'=>true];
    $caught=false; try { retention_purge($pdo,$actor,$input); } catch (AccountLifecycleConflict $e) { $caught=true; }
    reset_migration_expect(true,$caught,'Existing account grace period is still enforced');
    retention_change($pdo,$actor,['accountType'=>$type,'targetId'=>$target,'action'=>'hold','reason'=>'Fixture hold']);
    $input['action']='grace_period_override'; $input['reason']='Private fixture exercises existing grace override';
    $caught=false; try { retention_purge($pdo,$actor,$input); } catch (AccountLifecycleConflict $e) { $caught=true; }
    reset_migration_expect(true,$caught,'Existing retention Hold still blocks purge');
    retention_change($pdo,$actor,['accountType'=>$type,'targetId'=>$target,'action'=>'remove_hold']);
    if ($type==='student') {
        $pdo->prepare("UPDATE notifications SET status='Sending' WHERE id=?")->execute([$adviserCopies[0]]);
        $caught=false; try { retention_purge($pdo,$actor,$input); }
        catch (AccountLifecycleConflict $e) { $caught=str_contains($e->getMessage(),'unresolved workflow'); }
        reset_migration_expect(true,$caught,'Existing unresolved workflow still blocks purge with grace override');
        $pdo->prepare("UPDATE notifications SET status='Sent' WHERE id=?")->execute([$adviserCopies[0]]);
    }
    retention_change($pdo,$actor,['accountType'=>$type,'targetId'=>$target,'action'=>'restore']);
    if ($type==='student') reset_migration_expect($tombstone,b7_row('notifications',$copies[0]),'Account restore does not recreate or alter a deleted notification');
    retention_change($pdo,$actor,['accountType'=>$type,'targetId'=>$target,'action'=>'archive','expectedAssignedStudents'=>0]);
    $result=retention_purge($pdo,$actor,$input);
    reset_migration_expect(true,$result['ok'],'Verified existing retention purge and file journal complete');
}
reset_migration_expect(0,(int)$pdo->query('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=3')->fetchColumn(),'Recipient CASCADE removes account-bound copies');
reset_migration_expect('PRIVATE BODY',b7_row('notifications',$copies[1])['message'],'Unrelated Student copies survive recipient purge');
reset_migration_expect(null,b7_row('notification_campaigns',$adviserCampaign)['message'],'Retention sender purge erases campaign body');
reset_migration_expect(null,b7_row('notification_campaigns',$adviserCampaign)['sender_user_id'],'Adviser sender reference SET NULL');
$audit=$pdo->query('SELECT * FROM activity_logs')->fetchAll();
reset_migration_expect(false,str_contains(json_encode($audit),'PRIVATE BODY'),'Purge audit never retains notification body');
$jobs=$pdo->query('SELECT status FROM account_purge_jobs')->fetchAll(PDO::FETCH_COLUMN);
reset_migration_expect(['complete','complete'],$jobs,'Existing recoverable purge journals finalize');
lifecycle_compare_schema($pdo);
