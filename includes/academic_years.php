<?php
/** One read source for every academic selector. No DDL, inferred promotion or rollover. */
function academic_years_available(PDO $pdo): bool
{
    return (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='15';
}

function academic_year_valid(mixed $year): bool
{
    return is_string($year) && preg_match('/\A([1-9][0-9]{3})-([1-9][0-9]{3})\z/D',$year,$m)
        && (int)$m[2]===(int)$m[1]+1;
}

function academic_years(PDO $pdo): array
{
    // Until the operator migrates, use retained database values plus the reviewed
    // compatibility seed. These three values are migration data, not a validation list.
    $years=academic_years_available($pdo)
        ? $pdo->query('SELECT year_label FROM academic_years')->fetchAll(PDO::FETCH_COLUMN)
        : json_decode(file_get_contents(__DIR__.'/academic_year_seed.json'),true,512,JSON_THROW_ON_ERROR);
    $stored=$pdo->query("SELECT DISTINCT academic_year FROM students WHERE academic_year IS NOT NULL AND TRIM(academic_year)<>''")->fetchAll(PDO::FETCH_COLUMN);
    $years=array_values(array_unique(array_merge($years,$stored))); sort($years,SORT_STRING);
    return $years;
}

function academic_year_add(PDO $pdo,array $actor,mixed $year): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may add academic years.');
    if (!academic_year_valid($year)) throw new AccountLifecycleValidation('Enter consecutive years in YYYY-YYYY format, for example 2028-2029.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance is busy. Try again.');
    try {
        $pdo->beginTransaction();
        if (!academic_years_available($pdo)) throw new AccountLifecycleConflict('Academic-year management requires an operator-approved database update.');
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible RPMS Administrator is required.');
        if (in_array($year,academic_years($pdo),true)) throw new AccountLifecycleConflict('That academic year already exists.');
        $pdo->prepare('INSERT INTO academic_years(year_label,historical,added_by_user_id) VALUES (?,0,?)')->execute([$year,$live['id']]);
        document_workflow_audit($pdo,$live,'academic_year_added',['entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,'after'=>$year,'details'=>'Academic year added to the shared catalog; no record assignments changed.']);
        $pdo->commit(); return ['ok'=>true,'academicYear'=>$year,'academicYears'=>academic_years($pdo)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) throw new AccountLifecycleConflict('That academic year already exists.');
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

/** Called after an authorized assignment write, in that same transaction. */
function academic_year_assignment_audit(PDO $pdo,array $actor,int $studentId,?string $before,?string $after): void
{
    if ($before===$after) return;
    if (!$pdo->inTransaction()) throw new LogicException('Academic-year assignment audit requires a transaction.');
    $q=$pdo->prepare('INSERT INTO activity_logs(user_email,action,details,actor_name,actor_role,entity_type,entity_id,student_id,before_value,after_value) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $q->execute([$actor['email'],'student_academic_year_changed','Authorized individual academic-year assignment',$actor['full_name'],$actor['role'],'student',(string)$studentId,$studentId,$before,$after]);
    if ($q->rowCount()!==1) throw new RuntimeException('Academic-year assignment audit was not persisted.');
}
