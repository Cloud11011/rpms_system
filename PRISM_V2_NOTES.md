# PRISM v2 – workflow clarity, usability and record integrity

Built on top of the 12 feature requests already in `main`. No new roles, no new AI prompts
(the two whitelisted report actions are untouched; document summaries still use the same predefined prompt
and remain drafts for human review).

## What is in this drop

| File | Status | Purpose |
|---|---|---|
| `workflow.php` | **new** | Workflow states, audit log, notifications, "needs attention" logic |
| `documents_api.php` | **replace** | Workflow states, version history, `submit_to_rpms`, `override_review`, audit + confirmations |
| `ierb_api.php` | **replace** | List filters, doc counts, `override`, `needs_attention`, `export_csv`, audit |
| `audit_api.php` | **new** | Read-only audit trail (admin: all, adviser: own students) |
| `assets/css/prism-ui.css` | **new** | Badges, buttons, empty states, toasts, dialogs, tooltips, filter bar, mobile cards |
| `assets/js/prism-ui.js` | **new** | Same, plus the Student "Submit to RPMS" panel and the Needs Attention widget |
| `config.php` | **patch** (one block, below) | Migration for the new columns |
| `dashboard.php`, `role_portal.php`, `documents.php` | **small edits** (below) | Load the kit and mount the widgets |

## Install (about 10 minutes)

1. Back up the database and the project folder.
2. Copy the six new/replacement files above into the repo (same paths).
3. Apply the `config.php` patch.
4. Apply the page edits.
5. Load any page once. `migrate()` adds the new columns automatically (same self-migrating pattern as before).
6. Run the test checklist at the bottom.

### 1. `config.php` patch

Insert this block inside `migrate()`, immediately **before** the comment line
`// Seed default stage labels (feature request 7). ...`

```php
    // ---- PRISM v2: workflow clarity, version history, audit trail --------------------------
    $columnExists = function (string $table, string $column) use ($pdo): bool {
        $q = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t AND COLUMN_NAME = :c');
        $q->execute([':t' => $table, ':c' => $column]);
        return (int)$q->fetchColumn() > 0;
    };

    // Version history: a re-upload for the same student + stage + document type becomes a new version.
    add_column_if_missing($pdo, 'documents', 'version_no', "INT NOT NULL DEFAULT 1 AFTER stage");
    add_column_if_missing($pdo, 'documents', 'is_current', "TINYINT(1) NOT NULL DEFAULT 1 AFTER version_no");
    add_column_if_missing($pdo, 'documents', 'supersedes_id', "VARCHAR(40) NULL AFTER is_current");

    // Formal RPMS submission + Admin Override marker on documents.
    $hadRpmsColumn = $columnExists('documents', 'rpms_submitted_at');
    add_column_if_missing($pdo, 'documents', 'rpms_submitted_at', "DATETIME NULL");
    add_column_if_missing($pdo, 'documents', 'rpms_submitted_by', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'documents', 'admin_override', "TINYINT(1) NOT NULL DEFAULT 0");
    add_column_if_missing($pdo, 'documents', 'override_reason', "TEXT NULL");
    add_column_if_missing($pdo, 'documents', 'override_by', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'documents', 'override_at', "DATETIME NULL");
    if (!$hadRpmsColumn) {
        // One-time: documents approved BEFORE this step existed were already treated as done (the stage
        // advanced on approval), so mark them submitted. Otherwise every old approval would suddenly
        // show "Ready for Formal RPMS Submission".
        $pdo->exec("UPDATE documents
            SET rpms_submitted_at = COALESCE(reviewed_at, uploaded_at),
                rpms_submitted_by = 'Legacy record (approved before the formal submission step existed)'
            WHERE review_status = 'Approved' AND rpms_submitted_at IS NULL");
    }

    // Audit trail: extend the existing activity_logs table (old rows and log_activity() keep working).
    add_column_if_missing($pdo, 'activity_logs', 'actor_name', "VARCHAR(190) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'actor_role', "VARCHAR(20) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'entity_type', "VARCHAR(40) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'entity_id', "VARCHAR(64) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'student_id', "INT NULL");
    add_column_if_missing($pdo, 'activity_logs', 'reason', "TEXT NULL");
    add_column_if_missing($pdo, 'activity_logs', 'before_value', "VARCHAR(255) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'after_value', "VARCHAR(255) NULL");
    add_column_if_missing($pdo, 'activity_logs', 'is_override', "TINYINT(1) NOT NULL DEFAULT 0");
```

### 2. Page edits

Every edit is find → replace/insert on a single line, so it still matches if your line breaks differ.

#### `dashboard.php` (admin)

| # | Find | Do |
|---|---|---|
| D1 | `<link rel="stylesheet" href="assets/css/dashboard.css">` | Add after it: `<link rel="stylesheet" href="assets/css/prism-ui.css">` |
| D2 | `<!-- MODAL: DASHBOARD DAY TASKS -->` | Insert **before** it: `<script>window.PRISM_STAGE_LABELS = <?php echo json_encode(stage_labels_map()); ?>;</script>` and `<script src="assets/js/prism-ui.js"></script>` |
| D3 | `<!-- AI SUMMARY BANNER -->` | Insert **before** it: `<div data-prism-hint="admin-dashboard"></div>` |
| D4 | `<!-- STAGE PIPELINE FUNNEL WIDGET -->` | Insert **before** it the "Students Needing Attention" box (below) |
| D5 | `<button class="btn-secondary-sm"><i class="fa-solid fa-file-csv"></i> Import CSV</button>` | Add after it: `<a class="btn-secondary-sm prism-link-btn" href="ierb_api.php?action=export_csv" title="Download the student progress list as a CSV file"><i class="fa-solid fa-file-arrow-down"></i> Export CSV</a>` |
| D6 | `<th>Group ID</th>` | Replace with `<th>Protocol Code / Group</th>` |
| D7 | `<td><strong>${escapeMonitorHtml(record.groupId \|\| record.studentId)}</strong></td>` | Replace with snippet **D7** below |
| D8 | `<td><span class="stage-tag">${escapeMonitorHtml(record.stage \|\| 'Stage 1')}</span></td>` | Replace with `<td>${PrismUI.badge(record.stage \|\| 'Stage 1', { text: record.stageLabel \|\| record.stage })}${PrismUI.docMini(record.docs)}</td>` |
| D9 | `<td><span class="status-badge ${statusClass}">${escapeMonitorHtml(record.status \|\| 'Pending')}</span></td>` | Replace with `<td>${PrismUI.badge(record.status \|\| 'Pending')}</td>` |
| D10 | `row.innerHTML = '<td colspan="8" class="empty-state">No IERB records available. Add a student entry to begin.</td>';` | Replace with `row.innerHTML = '<td colspan="8">' + PrismUI.emptyState({ icon: 'fa-user-graduate', title: 'No IERB records yet', text: 'Add a student and assign an adviser to start tracking progress.', actionLabel: 'Add a student', actionHref: 'admin_students.php' }) + '</td>';` |
| D11a | `data-id="${record.id}"><i class="fa-solid fa-file-lines"></i></button></td>` | Replace with `data-id="${record.id}"><i class="fa-solid fa-file-lines"></i></button><button class="icon-btn prism-override-icon" data-monitor-action="override" title="Admin Override (always logged)" aria-label="Admin Override for ${escapeMonitorHtml(record.name)}"><i class="fa-solid fa-user-shield"></i></button></td>` |
| D11b | `row.querySelector('[data-monitor-action="summary"]').addEventListener('click', () => openSummaryModal(record.name, record.id));` | Add after it: `row.querySelector('[data-monitor-action="override"]').addEventListener('click', async () => { if (await PrismUI.overrideStudent(record)) { await loadMonitorStudents(); renderIerbMonitor(); } });` |
| D12a | `alert(data.message \|\| (data.ok ? 'Follow-up sent.' : 'Could not send follow-up.'));` | Replace with `PrismUI.toast(data.message \|\| (data.ok ? 'Follow-up sent.' : 'Could not send follow-up.'), data.ok ? 'success' : 'error');` |
| D12b | `alert('Could not reach the server to send the follow-up email.');` | Replace with `PrismUI.toast('Could not reach the server to send the follow-up email. Try again in a moment.', 'error');` |
| D13a | the line starting `row.innerHTML = ` that contains `<i class="fa-regular fa-file-lines"></i><span>${escapeMonitorHtml(d.originalName)}</span>` | Replace the whole line with snippet **D13a** below |
| D13b | `<p>No repository uploads available.</p>` | Replace with `<p>No documents yet. Uploads from students and advisers will appear here.</p>` |

D7 – replacement (protocol code first, group/student ID underneath):

```js
<td><strong>${escapeMonitorHtml(record.protocolCode || record.groupId || record.studentId)}</strong>${record.protocolCode ? `<small class="prism-sub">${escapeMonitorHtml(record.groupId || record.studentId)}</small>` : ''}</td>
```

D13a – replacement (workflow badge + version tag in "Recent Repository Uploads"):

```js
row.innerHTML = `<i class="fa-regular fa-file-lines"></i><span>${escapeMonitorHtml(d.originalName)}${d.versionNo > 1 ? ' (v' + d.versionNo + ')' : ''}</span><small>${escapeMonitorHtml(d.protocolCode || d.student || 'Unassigned')} &bull; ${PrismUI.badge(d.workflowState || d.reviewStatus, { small: true })}</small>`;
```

D4 – the box to insert:

```html
<div class="content-box">
<div class="table-header">
<h3><i class="fa-solid fa-bell-concierge"></i> Students Needing Attention <span data-prism-tip="Delayed students, documents waiting too long for an adviser, approved documents not yet submitted to RPMS, and students with no recent activity."></span></h3>
<a class="prism-btn is-secondary is-sm" href="ierbprog.php">Open IERB Progress</a>
</div>
<div data-prism-needs-attention data-limit="8"></div>
</div>
```

#### `role_portal.php` (student)

| # | Find | Do |
|---|---|---|
| P1 | `<link rel="stylesheet" href="assets/css/role-topnav.css">` | Add after it: `<link rel="stylesheet" href="assets/css/prism-ui.css">` |
| P2 | `<script src="assets/js/role-portal.js"></script>` | Insert **before** it: `<script src="assets/js/prism-ui.js"></script>` |
| P3 | the line starting `<div class="welcome-card">` | Add after it: `<div data-prism-student-workflow="compact"></div>` |
| P4 | `<section class="portal-page" data-section="submit">` | Add after it: `<div data-prism-hint="student-submit"></div>` |
| P5 | the line starting `<div id="protocolCodeCard"` | Add after it: `<div data-prism-hint="student-documents"></div>` and `<div data-prism-student-workflow></div>` |

#### `documents.php` (admin + adviser)

| # | Find | Do |
|---|---|---|
| M1 | `<link rel="stylesheet" href="assets/css/dashboard.css"><link rel="stylesheet" href="assets/css/documents.css">` | Add after it: `<link rel="stylesheet" href="assets/css/prism-ui.css">` |
| M2 | `<script src="assets/js/documents.js"></script>` | Insert **before** it: `<script src="assets/js/prism-ui.js"></script>` |
| M3 | `<section class="document-controls">` | Insert **before** it: `<?php if ($authUser['role'] === 'adviser'): ?><div data-prism-hint="adviser-documents"></div><?php endif; ?>` |

The documents table on this page and the student "My Documents" table switch to a card layout on phones automatically.

## Decisions I made – please confirm or veto

1. **The stage now advances when the student formally submits to RPMS, not when the adviser approves.**
   Before, approval auto-advanced the stage (feature #6). With a separate formal step, advancing on approval would show
   "Initial Ethics Review" before anything reached RPMS. To restore the old behaviour add
   `define('STAGE_ADVANCE_TRIGGER', 'approval');` to `config.local.php`.
2. **Versions are keyed by student + stage + document type.** Uploading a different document type in the same stage does *not*
   replace the other one. If the same type is uploaded again it becomes version 2, and version 1 stays available.
3. **Approved-before-this-upgrade documents are marked as already submitted** (one-time backfill, labelled "Legacy record").
4. **A submitted document is locked.** Its review status can't change and students can't re-upload it. Only an admin can
   correct it, and only with Admin Override (reason required). Deleting a locked document is admin-only and also needs a reason.
5. **Advisers can only review their own students' documents** (admins can review anything). This matches the README's
   "scoped to their own advisees" but the old code didn't enforce it. Turn off with
   `define('ADVISERS_REVIEW_ONLY_OWN_STUDENTS', false);`.
6. **Uploads with no `stage` field now default to the student's current stage** (previously always "Stage 1"), so versioning
   and progression line up for the student portal form, which doesn't send a stage.
7. **Admin Override on the existing "save" form is logged but not yet blocked when the reason is empty**, because your current
   IERB edit form doesn't send a reason yet. The new `override` action (used by the purple shield button on the dashboard)
   *requires* a reason. Once the form sends `reason`, set `define('REQUIRE_OVERRIDE_REASON_ON_SAVE', true);`.
8. **Approve is not blocked without remarks, and Deny/Request resubmission don't require remarks yet**, for the same reason:
   the existing ✓/✕ buttons don't ask for one. I'd require remarks on deny once the documents page is updated.

Thresholds for "Needs Attention" (all overridable in `config.local.php`): `ATTENTION_REVIEW_DAYS` = 3,
`ATTENTION_UNSUBMITTED_DAYS` = 3, `ATTENTION_OVERDUE_DAYS` = 30.

## API reference (new or changed)

| Endpoint | Who | Notes |
|---|---|---|
| `documents_api.php?action=list` | all | Now returns only current versions (`&includeOld=1` for all), plus `counts` by workflow state and per-row `actions` flags. Filters: `q`, `state`, `stage`, `type` |
| `…?action=upload` | all | Re-upload = new version. Response has a `message` |
| `…?action=versions&id=` | all (students: own) | Version chain, newest first |
| `…?action=review&id=` (POST `{status, remarks}`) | admin, adviser | Same contract as before. Adds locking, adviser scoping, confirmation `message` |
| `…?action=submit_to_rpms&id=` (POST `{reason?}`) | student, admin | Requires *Ready for Formal RPMS Submission*. Admin can force it with a `reason` (logged as override) |
| `…?action=override_review&id=` (POST `{status, reason}`) | admin | Reason required |
| `…?action=delete&id=` (POST `{reason?}`) | admin, adviser | Locked docs: admin + reason. Restores the previous version if the newest is deleted |
| `ierb_api.php?action=list` | all | Adds `docs` counts per student; filters `q`, `stage`, `status` |
| `…?action=needs_attention&limit=` | admin, adviser | Delayed / waiting on adviser / approved-not-submitted / inactive |
| `…?action=export_csv` (+ same filters) | admin, adviser | Protocol Code first. UTF-8 with BOM, formula-injection safe |
| `…?action=override` (POST `{id, stage?, status?, reason}`) | admin | Reason required. Notifies student and adviser |
| `audit_api.php?action=list` | admin, adviser | Filters: `studentId`, `action_code`, `override=1`, `from`, `to`, `q`, `limit`, `offset` |

Document workflow states (derived, not stored twice): **Pending Adviser Review** → **Ready for Formal RPMS Submission** →
**Submitted to RPMS**; plus **Needs Revision** and **Superseded**.

## Test checklist (I could not run PHP/MySQL in my environment – please run this once)

The JavaScript was tested in a real browser with mocked API responses; the PHP was only lint-checked structurally.

1. Load any page: no errors; `SHOW COLUMNS FROM documents` lists `version_no`, `is_current`, `rpms_submitted_at`, …
   Existing approved documents show **Submitted to RPMS** (legacy), not *Ready*.
2. Student uploads a file → status **Pending Adviser Review**; adviser gets a "New document to review" notification.
3. Adviser approves → student sees **Submit to RPMS** on the dashboard and My Documents; stage has **not** moved yet.
4. Student clicks **Submit to RPMS** → confirmation toast with a reference; stage advances; admins and adviser notified;
   `ierb_history` has both rows; `activity_logs` has `document_submitted_to_rpms` and `stage_auto_advanced`.
5. Student tries to re-upload that document → blocked with a clear message. Upload a *different* type → allowed.
6. Deny a document, student re-uploads the same type → shows as version 2; **Version history** lists both; v1 opens.
7. Another adviser tries to approve it → clear "assigned to another adviser" message.
8. Admin clicks the purple shield on the dashboard → dialog refuses an empty reason; saving logs who/when/why, notifies the student.
9. `audit_api.php?action=list&override=1` (while logged in as admin) lists the override with its reason.
10. **Export CSV** opens in Excel with Protocol Code as the first column; filters (`&status=Delayed`) apply.
11. Resize to a phone width: documents / monitor tables become cards. Empty database: the dashboard shows the empty-state prompts.

## Not done yet (I need files I can't reach)

GitHub's directory listing and `assets/js/*` aren't readable from here, so I couldn't change how existing tables/buttons are
drawn. Still to do, once I have `assets/js/documents.js`, `assets/js/role-portal.js`, `admin_students.php`, `admin_notifications.php`
and `ierbprog.php` (with their JS/CSS):

- Workflow badges, version badge and an **Admin Override** button on the Documents table; a labelled override entry on IERB Progress.
- Search + filters on **Students** and **Notifications** (the kit already supports them: add `data-prism-filter="Status,Stage"` to a table).
- Status badges and empty states inside the student "My Documents" table (the new panel above it already covers the main action).
- An **Activity log** page behind the "Activity Logs" link in the profile menu (it is a dead `#` link today; `audit_api.php` is ready).
- `reports_api.php` / `students_api.php`: if they count documents, add `AND is_current = 1` so old versions aren't counted twice.
