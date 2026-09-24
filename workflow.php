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
 * Document workflow (derived from existing columns, never stored twice):
 *
 *   uploaded ─► Pending Adviser Review ─► (adviser approves) ─► Ready for Formal RPMS Submission
 *                      │                                                   │
 *                      └► Needs Revision (denied / resubmission requested) │ student clicks "Submit to RPMS"
 *                                                                          ▼
 *                                                                  Submitted to RPMS (locked)
 *
 * A re-upload for the same student + stage + document type becomes a new
 * version; the older version is kept and shown as "Superseded".
 */

if (!function_exists('db')) {
    require __DIR__ . '/config.php';
}

// ---------------------------------------------------------------------
// Tunables. Override any of these in config.local.php (loaded by config.php
// before this file, so its values win).
// ---------------------------------------------------------------------

// When does a student's IERB stage advance to the next stage?
//   'submission' = when the student formally submits the approved document to RPMS (default)
//   'approval'   = when the adviser approves it (the behaviour before this upgrade)
if (!defined('STAGE_ADVANCE_TRIGGER')) define('STAGE_ADVANCE_TRIGGER', getenv('STAGE_ADVANCE_TRIGGER') ?: 'submission');

// "Students Needing Attention" thresholds (in days).
if (!defined('ATTENTION_REVIEW_DAYS'))      define('ATTENTION_REVIEW_DAYS', 3);      // waiting on the adviser
if (!defined('ATTENTION_UNSUBMITTED_DAYS')) define('ATTENTION_UNSUBMITTED_DAYS', 3); // approved, student has not submitted
if (!defined('ATTENTION_OVERDUE_DAYS'))     define('ATTENTION_OVERDUE_DAYS', 30);    // no activity at all

// Advisers may only review documents of students assigned to them (admins can review anything).
if (!defined('ADVISERS_REVIEW_ONLY_OWN_STUDENTS')) define('ADVISERS_REVIEW_ONLY_OWN_STUDENTS', true);

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

/** Workflow state of a documents row. Works on rows with or without the new columns. */
function document_workflow_state(array $d): string
{
    if (isset($d['is_current']) && (int)$d['is_current'] === 0) {
        return WF_SUPERSEDED;
    }
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
    try {
        db()->prepare('INSERT INTO activity_logs
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
    } catch (Throwable $e) {
        if (function_exists('log_api_error')) {
            log_api_error('audit', $e->getMessage());
        }
    }
}

/** Human-readable label for an audit action code. */
function audit_action_label(string $action): string
{
    static $labels = [
        'document_uploaded'                 => 'Document uploaded',
        'document_approved'                 => 'Document approved',
        'document_denied'                   => 'Document denied',
        'document_resubmission_requested'   => 'Resubmission requested',
        'document_reviewed'                 => 'Document status changed',
        'document_commented'                => 'Remark added',
        'document_submitted_to_rpms'        => 'Formally submitted to RPMS',
        'document_deleted'                  => 'Document deleted',
        'admin_override_document'           => 'Admin Override: document status',
        'admin_override_submit_to_rpms'     => 'Admin Override: submitted to RPMS',
        'admin_override_student_progress'   => 'Admin Override: student progress',
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

// ---------------------------------------------------------------------
// Notifications (in-app rows in `notifications`, plus email for students)
// ---------------------------------------------------------------------

function notify_in_app(PDO $pdo, string $recipientType, ?int $recipientId, string $email, string $name,
                       string $subject, string $message, string $type, string $createdBy): void
{
    try {
        $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
                subject, message, type, status, delivery_info, sent_at, created_by)
            VALUES (:rt,:rid,:email,:name,:subject,:msg,:type,"Sent","In-app notification",NOW(),:by)')
            ->execute([
                ':rt' => $recipientType, ':rid' => $recipientId, ':email' => $email, ':name' => $name,
                ':subject' => $subject, ':msg' => $message, ':type' => $type, ':by' => $createdBy,
            ]);
    } catch (Throwable $e) {
        log_api_error('notify_in_app', $e->getMessage());
    }
}

/** Emails the student and records the notification (Sent / Failed), like the original review flow did. */
function notify_student(PDO $pdo, int $studentDbId, string $subject, string $emailBody, string $inAppMessage,
                        string $type, string $createdBy): void
{
    $s = $pdo->prepare('SELECT email, full_name FROM students WHERE id = :id');
    $s->execute([':id' => $studentDbId]);
    $student = $s->fetch();
    if (!$student) {
        return;
    }
    try {
        $result = send_notification_email($student['email'], $subject, $emailBody);
        $pdo->prepare('INSERT INTO notifications (recipient_type, recipient_id, recipient_email, recipient_name,
                subject, message, type, status, delivery_info, sent_at, created_by)
            VALUES ("student",:sid,:email,:name,:subject,:msg,:type,:status,:info,NOW(),:by)')
            ->execute([
                ':sid' => $studentDbId, ':email' => $student['email'], ':name' => $student['full_name'],
                ':subject' => $subject, ':msg' => $inAppMessage, ':type' => $type,
                ':status' => $result['ok'] ? (($result['channel'] ?? '') === 'log' ? 'Logged' : 'Sent') : 'Failed', ':info' => $result['message'], ':by' => $createdBy,
            ]);
    } catch (Throwable $e) {
        log_api_error('notify_student', $e->getMessage());
    }
}

function notify_adviser_of_student(PDO $pdo, int $studentDbId, string $subject, string $message, string $type, string $createdBy): void
{
    $s = student_with_adviser($pdo, $studentDbId);
    if ($s && !empty($s['adviser_email'])) {
        notify_in_app($pdo, 'adviser', (int)$s['adviser_pk'], $s['adviser_email'], (string)$s['adviser_name'],
            $subject, $message, $type, $createdBy);
    }
}

function notify_rpms_admins(PDO $pdo, string $subject, string $message, string $type, string $createdBy): void
{
    try {
        $admins = $pdo->query("SELECT id, email, full_name FROM users WHERE role = 'admin' AND status = 'Active'")->fetchAll();
    } catch (Throwable $e) {
        return;
    }
    foreach ($admins as $a) {
        notify_in_app($pdo, 'admin', (int)$a['id'], $a['email'], $a['full_name'], $subject, $message, $type, $createdBy);
    }
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

/**
 * Moves the student to the next IERB stage when $doc belongs to their CURRENT
 * stage (so re-processing an old document never re-triggers progression).
 * Logs to ierb_history and the audit trail. Returns the new stage key or null.
 * Call inside the caller's transaction if atomicity matters.
 */
function advance_stage_for_document(PDO $pdo, array $doc, array $actor, string $effectiveDate, string $why): ?string
{
    if (empty($doc['student_id'])) {
        return null;
    }
    $q = $pdo->prepare('SELECT stage, status FROM students WHERE id = :id');
    $q->execute([':id' => $doc['student_id']]);
    $student = $q->fetch();
    if (!$student || $doc['stage'] !== $student['stage']) {
        return null;
    }
    $newStage = next_stage($student['stage']);
    if ($newStage === $student['stage']) {
        return null;
    }

    $pdo->prepare('UPDATE students SET stage = :stage, status = :status, last_submission_date = :sub, updated_at = NOW()
        WHERE id = :id')
        ->execute([':stage' => $newStage, ':status' => 'On Track', ':sub' => $effectiveDate, ':id' => $doc['student_id']]);

    $note = 'Auto-advanced to ' . stage_label($newStage) . ' (' . $newStage . ') ' . $why . '.';
    record_history($pdo, (int)$doc['student_id'], $newStage, 'On Track', $note, $effectiveDate, $actor['full_name'] . ' (auto)');

    audit_log($actor, 'stage_auto_advanced', [
        'entity_type' => 'student', 'entity_id' => $doc['student_id'], 'student_id' => $doc['student_id'],
        'before' => $student['stage'], 'after' => $newStage, 'details' => $note,
    ]);
    return $newStage;
}

// ---------------------------------------------------------------------
// Document counts per student, and "Students Needing Attention"
// ---------------------------------------------------------------------

function empty_doc_counts(): array
{
    return ['pendingReview' => 0, 'needsRevision' => 0, 'readyForRpms' => 0, 'submittedToRpms' => 0];
}

/** [student db id => counts] over CURRENT document versions only. */
function student_document_counts(PDO $pdo): array
{
    $rows = $pdo->query('SELECT student_id, review_status, rpms_submitted_at, is_current
        FROM documents WHERE is_current = 1 AND student_id IS NOT NULL')->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($out[$sid])) {
            $out[$sid] = empty_doc_counts();
        }
        switch (document_workflow_state($r)) {
            case WF_PENDING_REVIEW: $out[$sid]['pendingReview']++;   break;
            case WF_NEEDS_REVISION: $out[$sid]['needsRevision']++;   break;
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
            LEFT JOIN advisers a ON a.id = s.adviser_id WHERE s.stage <> :done';
    $params = [':done' => 'Completed'];
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

    $docRows = $pdo->query('SELECT student_id, review_status, rpms_submitted_at, uploaded_at, reviewed_at, is_current
        FROM documents WHERE is_current = 1 AND student_id IS NOT NULL')->fetchAll();
    $byStudent = [];
    foreach ($docRows as $d) {
        $byStudent[(int)$d['student_id']][] = $d;
    }

    $out = [];
    foreach ($students as $s) {
        $reasons = [];

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
            'stage'        => $s['stage'],
            'stageLabel'   => stage_label($s['stage']),
            'status'       => $s['status'],
            'adviser'      => $s['adviser_name'],
            'topSeverity'  => $reasons[0]['severity'],
            'reasons'      => $reasons,
        ];
    }
    usort($out, fn($a, $b) => [$b['topSeverity'], $a['name']] <=> [$a['topSeverity'], $b['name']]);
    return $out;
}
