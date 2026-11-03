<?php
/** One read source for every academic selector. No DDL, inferred promotion or rollover. */
function academic_years_available(PDO $pdo): bool
{
    return in_array((string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn(),['15','16'],true);
}

function academic_year_management_available(PDO $pdo): bool
{
    return (string)$pdo->query("SELECT v FROM schema_meta WHERE k='schema_version'")->fetchColumn()==='16';
}

function academic_year_removals_available(PDO $pdo): bool
{
    require_once __DIR__.'/admin_settings_store.php';
    return prism_settings_store_available($pdo);
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

function academic_year_registration_years(PDO $pdo): array
{
    if (!academic_year_management_available($pdo)) return academic_years($pdo);
    if (academic_year_removals_available($pdo)) {
        return $pdo->query('SELECT s.year_label FROM academic_year_settings s LEFT JOIN academic_year_removals r ON r.year_label=s.year_label
            WHERE s.available_for_registration=1 AND r.year_label IS NULL ORDER BY s.year_label')->fetchAll(PDO::FETCH_COLUMN);
    }
    return $pdo->query('SELECT year_label FROM academic_year_settings WHERE available_for_registration=1 ORDER BY year_label')
        ->fetchAll(PDO::FETCH_COLUMN);
}

function academic_year_settings_list(PDO $pdo): array
{
    $years=academic_years($pdo);
    if (!academic_year_management_available($pdo)) {
        return array_map(static fn($year)=>[
            'academicYear'=>$year,'availableForRegistration'=>true,'isCurrent'=>false,'isDeleted'=>false,
        ],$years);
    }
    $hasRemovals=academic_year_removals_available($pdo);
    $rows=$pdo->query('SELECT s.year_label,s.available_for_registration,s.is_current'.($hasRemovals?',r.year_label AS removed_label':'')
        .' FROM academic_year_settings s'.($hasRemovals?' LEFT JOIN academic_year_removals r ON r.year_label=s.year_label':''))
        ->fetchAll(PDO::FETCH_ASSOC);
    $settings=[];
    foreach ($rows as $row) {
        $settings[$row['year_label']]=[
            'availableForRegistration'=>(bool)$row['available_for_registration'],
            'isCurrent'=>(bool)$row['is_current'],
            'isDeleted'=>$hasRemovals && $row['removed_label']!==null,
        ];
    }
    return array_map(static fn($year)=>[
        'academicYear'=>$year,
        'availableForRegistration'=>$settings[$year]['availableForRegistration']??false,
        'isCurrent'=>$settings[$year]['isCurrent']??false,
        'isDeleted'=>$settings[$year]['isDeleted']??false,
    ],$years);
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
        if (in_array($year,academic_years($pdo),true)) {
            if (academic_year_management_available($pdo)) {
                $q=$pdo->prepare('SELECT s.available_for_registration'.(academic_year_removals_available($pdo)?',r.year_label AS removed_label':'')
                    .' FROM academic_year_settings s'.(academic_year_removals_available($pdo)?' LEFT JOIN academic_year_removals r ON r.year_label=s.year_label':'')
                    .' WHERE s.year_label=? FOR UPDATE');
                $q->execute([$year]); $existing=$q->fetch(PDO::FETCH_ASSOC);
                if ($existing && (!(bool)$existing['available_for_registration'] || !empty($existing['removed_label']))) {
                    if (academic_year_removals_available($pdo)) $pdo->prepare('DELETE FROM academic_year_removals WHERE year_label=?')->execute([$year]);
                    $pdo->prepare('UPDATE academic_year_settings SET available_for_registration=1 WHERE year_label=?')->execute([$year]);
                    document_workflow_audit($pdo,$live,'academic_year_registration_restored',[
                        'entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,
                        'before'=>!empty($existing['removed_label'])?'removed':'unavailable','after'=>'available',
                        'details'=>'Academic year restored for new registrations; historical assignments were not modified.',
                    ]);
                    $pdo->commit();
                    return ['ok'=>true,'academicYear'=>$year,'yearSettings'=>academic_year_settings_list($pdo)];
                }
            }
            throw new AccountLifecycleConflict('That academic year already exists and is available for registration.');
        }
        $pdo->prepare('INSERT INTO academic_years(year_label,historical,added_by_user_id) VALUES (?,0,?)')->execute([$year,$live['id']]);
        if (academic_year_management_available($pdo)) {
            $pdo->prepare('INSERT INTO academic_year_settings(year_label,available_for_registration) VALUES (?,1)')->execute([$year]);
        }
        document_workflow_audit($pdo,$live,'academic_year_added',['entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,'after'=>$year,'details'=>'Academic year added to the shared catalog; no record assignments changed.']);
        $pdo->commit(); return ['ok'=>true,'academicYear'=>$year,'academicYears'=>academic_years($pdo)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        if ($e instanceof PDOException && (int)($e->errorInfo[1]??0)===1062) throw new AccountLifecycleConflict('That academic year already exists.');
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

function academic_year_set_availability(PDO $pdo,array $actor,mixed $year,mixed $available): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may manage academic years.');
    if (!is_string($year) || $year==='' || !is_bool($available) || ($available && !academic_year_valid($year))) {
        throw new AccountLifecycleValidation('Choose a valid academic year and availability setting.');
    }
    if (!academic_year_management_available($pdo)) throw new AccountLifecycleConflict('Academic-year settings require an operator-approved database update.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance is busy. Try again.');
    try {
        $pdo->beginTransaction();
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible RPMS Administrator is required.');
        $q=$pdo->prepare('SELECT available_for_registration,is_current FROM academic_year_settings WHERE year_label=? FOR UPDATE');
        $q->execute([$year]); $before=$q->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new AccountLifecycleConflict('That academic year is no longer in the catalog. Refresh and try again.');
        if (!$available && (bool)$before['is_current']) throw new AccountLifecycleConflict('Set another current academic year before making this year unavailable.');
        if ((bool)$before['available_for_registration']!==$available) {
            if ($available && academic_year_removals_available($pdo)) {
                $pdo->prepare('DELETE FROM academic_year_removals WHERE year_label=?')->execute([$year]);
            }
            $pdo->prepare('UPDATE academic_year_settings SET available_for_registration=? WHERE year_label=?')->execute([$available?1:0,$year]);
            document_workflow_audit($pdo,$live,'academic_year_availability_changed',[
                'entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,
                'before'=>(bool)$before['available_for_registration']?'available':'unavailable',
                'after'=>$available?'available':'unavailable',
                'details'=>'Registration availability changed; historical student assignments were not modified.',
            ]);
        }
        $pdo->commit();
        return ['ok'=>true,'yearSettings'=>academic_year_settings_list($pdo)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

function academic_year_remove(PDO $pdo,array $actor,mixed $year): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may manage academic years.');
    if (!is_string($year) || !academic_year_valid($year)) throw new AccountLifecycleValidation('Choose a valid academic year.');
    if (!academic_year_management_available($pdo) || !academic_year_removals_available($pdo)) throw new AccountLifecycleConflict('Academic-year removal requires the approved settings migration.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance is busy. Try again.');
    try {
        $pdo->beginTransaction();
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible RPMS Administrator is required.');
        $q=$pdo->prepare('SELECT available_for_registration,is_current FROM academic_year_settings WHERE year_label=? FOR UPDATE');
        $q->execute([$year]); $before=$q->fetch(PDO::FETCH_ASSOC);
        if (!$before) throw new AccountLifecycleConflict('That academic year is no longer in the catalog. Refresh and try again.');
        if ((bool)$before['is_current']) throw new AccountLifecycleConflict('Select another active academic year before deleting this one.');
        $existing=$pdo->prepare('SELECT year_label FROM academic_year_removals WHERE year_label=? FOR UPDATE');
        $existing->execute([$year]);
        if ($existing->fetchColumn()!==false) throw new AccountLifecycleConflict('This academic year was already removed from new registrations.');
        $pdo->prepare('INSERT INTO academic_year_removals(year_label,removed_by_user_id) VALUES (?,?)')->execute([$year,$live['id']]);
        if ((bool)$before['available_for_registration']) {
            $pdo->prepare('UPDATE academic_year_settings SET available_for_registration=0 WHERE year_label=?')->execute([$year]);
        }
        document_workflow_audit($pdo,$live,'academic_year_removed_from_registration',[
            'entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,
            'before'=>(bool)$before['available_for_registration']?'available':'unavailable','after'=>'removed',
            'details'=>'Academic year removed from new registration choices; its catalog row and all historical assignments were retained.',
        ]);
        $pdo->commit();
        return ['ok'=>true,'yearSettings'=>academic_year_settings_list($pdo)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    } finally { $pdo->query("SELECT RELEASE_LOCK('prism_migrate')"); }
}

function academic_year_set_current(PDO $pdo,array $actor,mixed $year): array
{
    if (($actor['role']??'')!=='admin') throw new AccountLifecycleForbidden('Only RPMS Administrators may manage academic years.');
    if (!academic_year_valid($year)) throw new AccountLifecycleValidation('Choose a valid academic year in YYYY-YYYY format.');
    if (!academic_year_management_available($pdo)) throw new AccountLifecycleConflict('Academic-year settings require an operator-approved database update.');
    if ((int)$pdo->query("SELECT GET_LOCK('prism_migrate',5)")->fetchColumn()!==1) throw new AccountLifecycleConflict('Schema maintenance is busy. Try again.');
    try {
        $pdo->beginTransaction();
        $live=current_workflow_actor($pdo,$actor);
        if (!legal_admin_eligible($live)) throw new AccountLifecycleForbidden('An eligible RPMS Administrator is required.');
        $hasRemovals=academic_year_removals_available($pdo);
        $q=$pdo->prepare('SELECT s.is_current'.($hasRemovals?',r.year_label AS removed_label':'')
            .' FROM academic_year_settings s'.($hasRemovals?' LEFT JOIN academic_year_removals r ON r.year_label=s.year_label':'')
            .' WHERE s.year_label=? FOR UPDATE');
        $q->execute([$year]); $target=$q->fetch(PDO::FETCH_ASSOC);
        if (!$target) throw new AccountLifecycleConflict('That academic year is no longer in the catalog. Refresh and try again.');
        if (!empty($target['removed_label'])) throw new AccountLifecycleConflict('Restore this academic year before selecting it as current.');
        $before=$pdo->query('SELECT year_label FROM academic_year_settings WHERE is_current=1 FOR UPDATE')->fetchColumn();
        if ($before!==$year) {
            $pdo->exec('UPDATE academic_year_settings SET is_current=0 WHERE is_current=1');
            $pdo->prepare('UPDATE academic_year_settings SET is_current=1,available_for_registration=1 WHERE year_label=?')->execute([$year]);
            document_workflow_audit($pdo,$live,'academic_year_current_changed',[
                'entity_type'=>'academic_year','entity_id'=>$year,'student_id'=>null,
                'before'=>$before===false?null:(string)$before,'after'=>$year,
                'details'=>'Current academic year changed and was made available for registration; existing student assignments were not modified.',
            ]);
        }
        $pdo->commit();
        return ['ok'=>true,'yearSettings'=>academic_year_settings_list($pdo)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
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
