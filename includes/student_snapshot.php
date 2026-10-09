<?php
/** Final persistence guards. Locks are held only for database work, never provider calls. */
final class StudentSnapshotConflict extends RuntimeException {}

function student_snapshot_rows(PDO $pdo, array $ids): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Snapshot revalidation requires a transaction.');
    $ids=array_values(array_unique(array_map('intval',$ids))); sort($ids,SORT_NUMERIC);
    if (!$ids) return [];
    $q=$pdo->prepare('SELECT * FROM students WHERE id IN ('.implode(',',array_fill(0,count($ids),'?')).') ORDER BY id FOR UPDATE');
    $q->execute($ids); $rows=[];
    foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) $rows[(int)$row['id']]=$row;
    return $rows;
}

function notification_lock_recipients(PDO $pdo, array $recipients): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Notification persistence requires a transaction.');
    $adviserIds=[]; $studentIds=[];
    foreach($recipients as $r) {
        if($r['type']==='student') $studentIds[]=(int)$r['id'];
        if($r['type']==='adviser') $adviserIds[]=(int)$r['id'];
    }
    $adviserIds=array_values(array_unique($adviserIds)); sort($adviserIds,SORT_NUMERIC); $advisers=[];
    if($adviserIds) {
        $q=$pdo->prepare('SELECT * FROM advisers WHERE id IN ('.implode(',',array_fill(0,count($adviserIds),'?')).') ORDER BY id FOR UPDATE');
        $q->execute($adviserIds);
        foreach($q->fetchAll(PDO::FETCH_ASSOC) as $row) $advisers[(int)$row['id']]=$row;
    }
    $students=student_snapshot_rows($pdo,$studentIds); $valid=[];
    foreach($recipients as $r) {
        if(!in_array($r['type'],['student','adviser'],true)) { $valid[]=$r; continue; }
        $current=($r['type']==='student'?$students:$advisers)[(int)$r['id']]??null;
        if(!$current || ($r['type']==='student'?!empty($current['archived_at']):strcasecmp($current['status'],'Active')!==0)) continue;
        if((string)($r['email']??'')!==(string)($current['email']??'') || (string)$r['name']!==(string)$current['full_name']) continue;
        foreach(['research_group','adviser_id'] as $field) if(array_key_exists($field,$r) && (string)$r[$field] !== (string)$current[$field]) continue 2;
        $valid[]=$r;
    }
    return $valid;
}

/** Caller has finished expensive PDF/AI generation. Captured Student rows must still be current. */
function report_persist_snapshot(PDO $pdo, array $students, array $values, ?array $history = null): void
{
    $pdo->beginTransaction();
    try {
        $current=student_snapshot_rows($pdo,array_column($students,'id'));
        foreach($students as $student) {
            $row=$current[(int)$student['id']]??null;
            if(!$row || !empty($row['archived_at'])) throw new StudentSnapshotConflict('Report membership changed. Generate the report again.');
            foreach($student as $key=>$value) {
                if($key==='adviser_name') continue;
                if(!array_key_exists($key,$row) || ($value===null)!==($row[$key]===null) || (string)$value!==(string)$row[$key]) {
                    throw new StudentSnapshotConflict('Report data changed. Generate the report again.');
                }
            }
        }
        if($history!==null && $students) {
            $q=$pdo->prepare('SELECT * FROM ierb_history WHERE student_id=:id ORDER BY created_at DESC LIMIT 20');
            $q->execute([':id'=>$students[0]['id']]);
            if($q->fetchAll(PDO::FETCH_ASSOC)!==$history) throw new StudentSnapshotConflict('Report history changed. Generate the report again.');
        }
        $pdo->prepare('INSERT INTO reports (id,title,type,filename,generated_by,generated_by_user_id) VALUES (:id,:title,:type,:file,:by,:uid)')->execute($values);
        $pdo->commit();
    } catch(Throwable $error) {
        if($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
