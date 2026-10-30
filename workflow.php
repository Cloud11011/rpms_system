<?php
/**
 * PRISM - Workflow, audit-trail and notification helpers.
 *
 * Shared by documents_api.php, ierb_api.php and audit_api.php.
 * Always load with require_once, AFTER config.php:
 *
 *     require __DIR__ . '/config.php';
 *     require_once __DIR__ . '/workflow.php';
 *
 * Document workflow (derived from existing columns and immutable Return audit):
 * Uploaded -> Pending Adviser Review -> Adviser approval + formal RPMS submission
 *                                      -> Denied / Resubmission Requested -> new revision
 * Submitted to RPMS -> Admin Return for Revision -> new revision -> Adviser review
 * Approval of the new current version formally resubmits it in the same transaction.
 * Admin Override retains its separate pre-B11 behavior.
 *
 * A re-upload for the same student + stage + document type becomes a new
 * version; the older version is kept and shown as "Superseded".
 */

if (!function_exists('db')) {
    require __DIR__ . '/config.php';
}
require_once __DIR__ . '/includes/notification_delivery.php';
require_once __DIR__ . '/includes/account_identity.php';
require_once __DIR__ . '/includes/document_workflow.php';
require_once __DIR__ . '/includes/current_workflow_eligibility.php';

// ---------------------------------------------------------------------
// Tunables. Override any of these in config.local.php (loaded by config.php
// before this file, so its values win).
// ---------------------------------------------------------------------

// Official IERB stages change only through explicit RPMS Administrator decisions.

// "Students Needing Attention" thresholds (in days).
if (!defined('ATTENTION_REVIEW_DAYS'))      define('ATTENTION_REVIEW_DAYS', 3);      // waiting on the adviser
if (!defined('ATTENTION_UNSUBMITTED_DAYS')) define('ATTENTION_UNSUBMITTED_DAYS', 3); // approved, student has not submitted
if (!defined('ATTENTION_OVERDUE_DAYS'))     define('ATTENTION_OVERDUE_DAYS', 30);    // no activity at all

// Advisers may only review documents of students assigned to them (RPMS interventions require reasoned Admin Override).
// Adviser reviews always require the current assignment; no legacy opt-out is supported.

// Minimum length of the reason required for an Admin Override.
if (!defined('OVERRIDE_MIN_REASON_LENGTH')) define('OVERRIDE_MIN_REASON_LENGTH', 5);

// Set to true once the IERB edit form sends a "reason" field: from then on,
// changing a student's stage/status through the plain "save" action requires one.
if (!defined('REQUIRE_OVERRIDE_REASON_ON_SAVE')) define('REQUIRE_OVERRIDE_REASON_ON_SAVE', true);

// ---------------------------------------------------------------------
// Workflow states
// ---------------------------------------------------------------------

const WF_PENDING_REVIEW = 'Pending Adviser Review';
const WF_NEEDS_REVISION = 'Needs Revision';
const WF_READY_FOR_RPMS = 'Ready for Formal RPMS Submission';
const WF_SUBMITTED_RPMS = 'Submitted to RPMS';
const WF_SUPERSEDED     = 'Superseded';
const WF_RETURNED_REVISION = 'Returned for Revision';

/** Workflow state of a documents row. Works on rows with or without the new columns. */
function document_workflow_state(array $d): string
{
    if (isset($d['is_current']) && (int)$d['is_current'] === 0) {
        return WF_SUPERSEDED;
    }
    if (!empty($d['rpms_returned_at'])) return WF_RETURNED_REVISION;
    $review = $d['review_status'] ?? 'Submitted';
    if ($review === 'Approved') {
        return !empty($d['rpms_submitted_at']) ? WF_SUBMITTED_RPMS : WF_READY_FOR_RPMS;
    }
    if ($review === 'Denied' || $review === 'Resubmission Requested') {
        return WF_NEEDS_REVISION;
    }
    return WF_PENDING_REVIEW;
}

/** A document that was formally submitted to RPMS is locked: its review status can no longer change. */
function document_is_locked(array $d): bool
{
    return !empty($d['rpms_submitted_at']);
}

function override_reason_valid(string $reason): bool
{
    return mb_strlen(trim($reason)) >= OVERRIDE_MIN_REASON_LENGTH;
}

function override_reason_message(): string
{
    return 'An Admin Override needs a reason (at least ' . OVERRIDE_MIN_REASON_LENGTH . ' characters). It is saved with your name and the time.';
}

function workflow_days_since(?string $timestamp): int
{
    if (!$timestamp) {
        return 0;
    }
    $t = strtotime($timestamp);
    return $t ? (int)max(0, floor((time() - $t) / 86400)) : 0;
}

function plural_days(int $n): string
{
    return $n . ' day' . ($n === 1 ? '' : 's');
}

// ---------------------------------------------------------------------
// Audit trail (extends the existing activity_logs table; see migration)
// ---------------------------------------------------------------------

/**
 * Writes one audit entry: who ($actor = users row), what, when (automatic),
 * and optional context: entity_type, entity_id, student_id, details, reason,
 * before, after, override (bool). Never throws.
 */
function audit_log(array $actor, string $action, array $ctx = []): void
{
    $owned=false;$pdo=null;
    try {
        $pdo=db();
        if (!empty($ctx['student_id']) || in_array($ctx['entity_type']??'',['student','document','report'],true)) {
            $owned=!$pdo->inTransaction();if($owned)$pdo->beginTransaction();
            if (!audit_lock_live_student($pdo,$ctx)) {if($owned)$pdo->commit();return;}
        }
        $pdo->prepare('INSERT INTO activity_logs
            (user_email, action, details, actor_name, actor_role, entity_type, entity_id,
             student_id, reason, before_value, after_value, is_override)
            VALUES (:email,:action,:details,:name,:role,:etype,:eid,:sid,:reason,:before,:after,:ovr)')
            ->execute([
                ':email'   => $actor['email'] ?? null,
                ':action'  => $action,
                ':details' => mb_substr((string)($ctx['details'] ?? ''), 0, 255),
                ':name'    => $actor['full_name'] ?? null,
                ':role'    => $actor['role'] ?? null,
                ':etype'   => $ctx['entity_type'] ?? null,
                ':eid'     => isset($ctx['entity_id']) ? (string)$ctx['entity_id'] : null,
                ':sid'     => isset($ctx['student_id']) && $ctx['student_id'] !== '' ? (int)$ctx['student_id'] : null,
                ':reason'  => isset($ctx['reason']) && $ctx['reason'] !== '' ? (string)$ctx['reason'] : null,
                ':before'  => isset($ctx['before']) ? mb_substr((string)$ctx['before'], 0, 255) : null,
                ':after'   => isset($ctx['after']) ? mb_substr((string)$ctx['after'], 0, 255) : null,
                ':ovr'     => !empty($ctx['override']) ? 1 : 0,
            ]);
        if($owned)$pdo->commit();
    } catch (Throwable $e) {
        if($owned && $pdo?->inTransaction())$pdo->rollBack();
        if (function_exists('log_api_error')) {
            log_api_error('audit', $e->getMessage());
        }
    }
}

/** Existing Admin Overrides must commit with their audit. The caller owns rollback/commit. */
function audit_override_atomic(PDO $pdo, array $actor, string $action, array $ctx): void
{
    if (!$pdo->inTransaction() || ($actor['role'] ?? '') !== 'admin' || empty($ctx['override'])) {
        throw new LogicException('Override audit requires an Admin override transaction.');
    }
    if (!audit_lock_live_student($pdo, $ctx)) {
        throw new RuntimeException('Override audit ownership is no longer available.');
    }
    $pdo->prepare('INSERT INTO activity_logs
        (user_email, action, details, actor_name, actor_role, entity_type, entity_id,
         student_id, reason, before_value, after_value, is_override)
        VALUES (:email,:action,:details,:name,:role,:etype,:eid,:sid,:reason,:before,:after,:ovr)')
        ->execute([
            ':email' => $actor['email'] ?? null,
            ':action' => $action,
            ':details' => mb_substr((string)($ctx['details'] ?? ''), 0, 255),
            ':name' => $actor['full_name'] ?? null,
            ':role' => $actor['role'] ?? null,
            ':etype' => $ctx['entity_type'] ?? null,
            ':eid' => isset($ctx['entity_id']) ? (string)$ctx['entity_id'] : null,
            ':sid' => isset($ctx['student_id']) && $ctx['student_id'] !== '' ? (int)$ctx['student_id'] : null,
            ':reason' => isset($ctx['reason']) && $ctx['reason'] !== '' ? (string)$ctx['reason'] : null,
            ':before' => isset($ctx['before']) ? mb_substr((string)$ctx['before'], 0, 255) : null,
            ':after' => isset($ctx['after']) ? mb_substr((string)$ctx['after'], 0, 255) : null,
            ':ovr' => 1,
        ]);
}

/** Human-readable label for an audit action code. */
function audit_action_label(string $action): string
{
    static $labels = [
        'document_uploaded'                 => 'Document uploaded',
        'document_summarized'               => 'Document summary generated',
        'document_summary_failed'           => 'Document summary failed',
        'document_approved'                 => 'Document approved',
        'document_denied'                   => 'Document denied',
        'document_resubmission_requested'   => 'Resubmission requested',
        'document_reviewed'                 => 'Document status changed',
        'document_commented'                => 'Remark added',
        'document_submitted_to_rpms'        => 'Formally submitted to RPMS',
        'document_resubmitted_to_rpms'      => 'Formally resubmitted to RPMS',
        'document_returned_for_revision'    => 'RPMS returned for revision',
        'document_deleted'                  => 'Document deleted',
        'admin_override_document'           => 'Admin Override: document status',
        'admin_override_submit_to_rpms'     => 'Admin Override: submitted to RPMS',
        'admin_override_student_progress'   => 'Admin Override: student progress',
        'ierb_stage_advanced' => 'RPMS stage advancement',
        'ierb_stage_corrected' => 'RPMS stage correction',
        'ierb_status_updated' => 'RPMS progress status update',
        'stage_guidance_updated' => 'Stage guidance updated',
        'stage_label_updated' => 'Stage name updated',
        'stage_auto_advanced'               => 'Stage advanced',
        'ierb_saved'                        => 'IERB record saved',
        'ierb_note_added'                   => 'Note added',
        'ierb_deleted'                      => 'Student record deleted',
        'progress_exported'                 => 'Progress list exported (CSV)',
    ];
    return $labels[$action] ?? ucfirst(str_replace('_', ' ', $action));
}

// ---------------------------------------------------------------------
// Lookups
// ---------------------------------------------------------------------

/** students row plus adviser_email / adviser_name / adviser_user_id (all may be null). */
function student_with_adviser(PDO $pdo, int $studentDbId): ?array
{
    $stmt = $pdo->prepare('SELECT s.*, a.id AS adviser_pk, a.email AS adviser_email, a.full_name AS adviser_name
        FROM students s LEFT JOIN advisers a ON a.id = s.adviser_id WHERE s.id = :id');
    $stmt->execute([':id' => $studentDbId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

/** Archive under the student lock, retaining every relationship and collected row. */
function archive_student(PDO $pdo, array $actor, int $id): array
{
    require_once __DIR__ . '/includes/account_lifecycle.php';
    $before=student_with_adviser($pdo,$id);
    retention_change($pdo,$actor,['accountType'=>'student','targetId'=>$id,'action'=>'archive']);
    return $before ?? [];
}

// ---------------------------------------------------------------------
// Notifications (in-app rows plus best-effort email for every recipient)
// ---------------------------------------------------------------------

/** Persist required intent on the caller's business transaction; never deliver here. */
function workflow_queue_notices(PDO $pdo, array $actor, int $studentId, ?array $studentNotice, ?array $adviserNotice=null): array
{
    if (!$pdo->inTransaction()) throw new LogicException('Workflow notice intent requires the business transaction.');
    $student=student_with_adviser($pdo,$studentId);
    if (!$student) throw new RuntimeException('Workflow notice recipient is missing.');
    $recipients=[];
    if ($studentNotice!==null) $recipients[]=['student',$studentId,$student['email'],$student['full_name'],$studentNotice];
    if ($adviserNotice!==null && !empty($student['adviser_pk'])) {
        $recipients[]=['adviser',(int)$student['adviser_pk'],$student['adviser_email'],$student['adviser_name'],$adviserNotice];
    }
    $ids=[];
    foreach ($recipients as [$role,$id,$email,$name,$notice]) {
        $copy=create_notification($pdo,$role,$id,(string)$email,(string)$name,$notice['subject'],$notice['message'],
            $notice['type']??'Status Update',$actor['full_name'],null,date('Y-m-d H:i:s'));
        if (!isset($copy['id'])) throw new RuntimeException('Workflow notification intent could not be recorded. Refresh the recipient state.');
        $ids[]=(int)$copy['id'];
    }
    return $ids;
}

/** Legacy entry point: caller-owned transactions must propagate queue failures. */
function notify_in_app(PDO $pdo, string $recipientType, ?int $recipientId, string $email, string $name,
                       string $subject, string $message, string $type, string $createdBy,
                       ?string $emailBody = null, ?string $emailSubject = null): void
{
    try {
        $copy=create_notification($pdo, $recipientType, $recipientId, $email, $name,
            $subject, $message, $type, $createdBy, $emailBody, null, $emailSubject);
        if ($pdo->inTransaction() && !isset($copy['id'])) throw new RuntimeException('Required notification recipient changed.');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) throw $e;
        log_api_error('notify_in_app', 'Workflow notification records could not be saved.');
    }
}

/** Keep the student's email body distinct from the shorter in-app message. */
function notify_student(PDO $pdo, int $studentDbId, string $subject, string $emailBody, string $inAppMessage,
                        string $type, string $createdBy): void
{
    $s = $pdo->prepare('SELECT email, full_name FROM students WHERE id = :id');
    $s->execute([':id' => $studentDbId]);
    $student = $s->fetch();
    if (!$student) {
        return;
    }
    notify_in_app($pdo, 'student', $studentDbId, (string)($student['email'] ?? ''), (string)$student['full_name'],
        $subject, $inAppMessage, $type, $createdBy, $emailBody);
}

function notify_adviser_of_student(PDO $pdo, int $studentDbId, string $subject, string $message, string $type, string $createdBy): void
{
    $s = student_with_adviser($pdo, $studentDbId);
    if ($s && !empty($s['adviser_email'])) {
        notify_in_app($pdo, 'adviser', (int)$s['adviser_pk'], $s['adviser_email'], (string)$s['adviser_name'],
            $subject, $message, $type, $createdBy);
    }
}

function notify_rpms_admins(PDO $pdo, string $subject, string $message, string $type, string $createdBy,
                            ?string $emailBody = null, ?string $emailSubject = null): void
{
    $owned=!$pdo->inTransaction(); $ids=[];
    try {
        if ($owned) $pdo->beginTransaction();
        $admins=notification_rows($pdo,"SELECT id,email,full_name FROM users WHERE role='admin' AND status='Active' ORDER BY id FOR UPDATE");
        if ($admins) {
            $campaign=notification_create_campaign($pdo,notification_sender(),'workflow',$subject,$message,date('Y-m-d H:i:s'));
            foreach ($admins as $a) {
                $copy=create_notification($pdo,'admin',(int)$a['id'],(string)($a['email']??''),(string)$a['full_name'],
                    $subject,$message,$type,$createdBy,$emailBody,date('Y-m-d H:i:s'),$emailSubject,'workflow',$campaign);
                if (!isset($copy['id'])) throw new RuntimeException('Required Administrator notification recipient changed.');
                $ids[]=$copy['id'];
            }
        }
        if ($owned) $pdo->commit();
    } catch (Throwable $e) {
        if ($owned && $pdo->inTransaction()) $pdo->rollBack();
        if (!$owned) throw $e;
        log_api_error('notify_rpms_admins','Workflow notification records could not be saved.'); return;
    }
    if ($owned) foreach ($ids as $id) notification_dispatch($pdo,$id,null,[$emailSubject??$subject,$emailBody??$message]);
}

// ---------------------------------------------------------------------
// IERB history + stage advancement
// ---------------------------------------------------------------------

function record_history(PDO $pdo, int $studentDbId, string $stage, string $status, string $note,
                        ?string $submissionDate, string $actorLabel): void
{
    $pdo->prepare('INSERT INTO ierb_history (student_id, stage, status, note, submission_date, actor)
        VALUES (:sid,:stage,:status,:note,:sub,:actor)')
        ->execute([':sid' => $studentDbId, ':stage' => $stage, ':status' => $status, ':note' => $note,
                   ':sub' => $submissionDate, ':actor' => $actorLabel]);
}

// ---------------------------------------------------------------------
// Document counts per student, and "Students Needing Attention"
// ---------------------------------------------------------------------

function empty_doc_counts(): array
{
    return ['pendingReview' => 0, 'needsRevision' => 0, 'readyForRpms' => 0, 'submittedToRpms' => 0];
}

/** [student db id => counts] over CURRENT document versions only. */
function student_document_counts(PDO $pdo, ?array $studentIds = null): array
{
    if ($studentIds === []) return [];
    $scope = $studentIds === null ? '' : ' AND student_id IN (' . implode(',', array_map('intval', $studentIds)) . ')';
    $rows = $pdo->query('SELECT student_id, review_status, rpms_submitted_at, is_current, '
        . document_returned_sql('documents') . ' AS rpms_returned_at
        FROM documents WHERE is_current = 1 AND student_id IS NOT NULL' . $scope)->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($out[$sid])) {
            $out[$sid] = empty_doc_counts();
        }
        switch (document_workflow_state($r)) {
            case WF_PENDING_REVIEW: $out[$sid]['pendingReview']++;   break;
            case WF_NEEDS_REVISION: $out[$sid]['needsRevision']++;   break;
            case WF_RETURNED_REVISION: $out[$sid]['needsRevision']++; break;
            case WF_READY_FOR_RPMS: $out[$sid]['readyForRpms']++;    break;
            case WF_SUBMITTED_RPMS: $out[$sid]['submittedToRpms']++; break;
        }
    }
    return $out;
}

/**
 * Students (not yet Completed) who need someone to act. Admins see everyone;
 * advisers only their own students. Each entry lists why, most urgent first.
 * severity: 3 = high, 2 = medium, 1 = low.
 */
function students_needing_attention(PDO $pdo, array $user): array
{
    $sql = 'SELECT s.*, a.full_name AS adviser_name FROM students s
            LEFT JOIN advisers a ON a.id = s.adviser_id WHERE s.archived_at IS NULL AND s.profile_completed_at IS NOT NULL AND s.stage <> :done';
    $params = [':done' => 'Completed'];
    if (ierb_v14_schema_available($pdo)) {
        $sql .= " AND NOT EXISTS (SELECT 1 FROM ierb_workflows w WHERE w.student_id=s.id AND w.stage_key='ierb_completed')";
    }
    if (research_projects_available($pdo)) {
        // A canonical group's evidence belongs to its holder; avoid separate
        // overdue alerts for members with no individual document rows.
        $sql .= ' AND NOT EXISTS (SELECT 1 FROM research_project_members m JOIN research_projects p ON p.id=m.project_id WHERE m.student_id=s.id AND p.submission_holder_id<>s.id)';
    }
    if ($user['role'] === 'adviser') {
        $sql .= ' AND a.email = :e';
        $params[':e'] = $user['email'];
    }
    $stmt = $pdo->prepare($sql . ' ORDER BY s.full_name');
    $stmt->execute($params);
    $students = $stmt->fetchAll();
    if (!$students) {
        return [];
    }

    $docRows = $pdo->query('SELECT student_id, review_status, rpms_submitted_at, uploaded_at, reviewed_at, is_current, '
        . document_returned_sql('documents') . ' AS rpms_returned_at
        FROM documents WHERE is_current = 1 AND student_id IS NOT NULL')->fetchAll();
    $byStudent = [];
    foreach ($docRows as $d) {
        $byStudent[(int)$d['student_id']][] = $d;
    }

    $out = [];
    foreach ($students as $s) {
        $reasons = [];
        $semantic = ierb_v14_read_summary($pdo, (int)$s['id']);

        if ($s['status'] === 'Delayed') {
            $reasons[] = ['type' => 'delayed', 'severity' => 3, 'label' => 'Marked as delayed'];
        }

        $pending = 0; $pendingSince = null; $ready = 0; $readySince = null;
        foreach ($byStudent[(int)$s['id']] ?? [] as $d) {
            $state = document_workflow_state($d);
            if ($state === WF_PENDING_REVIEW) {
                $pending++;
                $pendingSince = ($pendingSince === null || $d['uploaded_at'] < $pendingSince) ? $d['uploaded_at'] : $pendingSince;
            } elseif ($state === WF_READY_FOR_RPMS) {
                $ready++;
                $t = $d['reviewed_at'] ?: $d['uploaded_at'];
                $readySince = ($readySince === null || $t < $readySince) ? $t : $readySince;
            }
        }
        if ($pending > 0 && workflow_days_since($pendingSince) >= ATTENTION_REVIEW_DAYS) {
            $reasons[] = ['type' => 'pending_review', 'severity' => 2,
                'label' => $pending . ' document' . ($pending === 1 ? '' : 's') . ' waiting for adviser review ('
                           . plural_days(workflow_days_since($pendingSince)) . ')'];
        }
        if ($ready > 0 && workflow_days_since($readySince) >= ATTENTION_UNSUBMITTED_DAYS) {
            $reasons[] = ['type' => 'not_submitted', 'severity' => 1,
                'label' => 'Approved but not yet submitted to RPMS (' . plural_days(workflow_days_since($readySince)) . ')'];
        }

        $lastActivity = $s['last_submission_date'] ?: substr((string)$s['created_at'], 0, 10);
        $idle = workflow_days_since($lastActivity);
        if ($idle >= ATTENTION_OVERDUE_DAYS) {
            $reasons[] = ['type' => 'overdue', 'severity' => 2, 'label' => 'No submission activity for ' . plural_days($idle)];
        }

        if (!$reasons) {
            continue;
        }
        usort($reasons, fn($a, $b) => $b['severity'] <=> $a['severity']);
        $out[] = [
            'id'           => (int)$s['id'],
            'studentId'    => $s['student_id'],
            'name'         => $s['full_name'],
            'protocolCode' => $s['protocol_code'] ?? null,
            'groupId'      => $s['research_group'],
            'research'     => $s['research_title'],
            'stage'        => $semantic['stage'] ?? $s['stage'],
            'stageLabel'   => $semantic['stageLabel'] ?? stage_label($s['stage']),
            'status'       => $s['status'],
            'adviser'      => $s['adviser_name'],
            'topSeverity'  => $reasons[0]['severity'],
            'reasons'      => $reasons,
        ];
    }
    usort($out, fn($a, $b) => [$b['topSeverity'], $a['name']] <=> [$a['topSeverity'], $b['name']]);
    return $out;
}
