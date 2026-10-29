<?php
/** READ-ONLY preview and post-cleanup verifier for decision D12 (revised): preserve EVERY RPMS Administrator account;
 * remove every test Student and Research Adviser account with their test research records.
 * It never deletes, archives or changes anything: SELECTs only, inside a READ ONLY transaction that is rolled back.
 * Usage (explicit connection; never loads config.php):
 *   PRISM_CLEANUP_DB_DSN='mysql:host=...;dbname=...' PRISM_CLEANUP_DB_USER=... PRISM_CLEANUP_DB_PASSWORD=... \
 *     php maintenance/clean-database-preview.php --retain-admins=1,13,46 [--storage=<dir>] [--redact] [--keep-institution-reports]
 *     php maintenance/clean-database-preview.php --retain-admins=1,13,46 --mode=verify-final --expect-from=<approved preview.json>
 * preview       Every account and record with the existing application operation that removes it, the Admin-owned records
 *               that reference test accounts, records that cannot be removed safely, and the expected final counts.
 * verify-final  Proves the cleaned state: the same Administrators with unchanged credentials and configuration, unchanged
 *               Admin deadlines and notifications, and zero Student, Adviser, document, report and test-owned records.
 * Execution is never performed here; it uses the authenticated Admin application operations in the reviewed procedure.
 */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$o = getopt('', ['retain-admins:', 'storage::', 'redact', 'mode::', 'expect-from::', 'keep-institution-reports', 'institution-reports::']);
try {
    $dsn = getenv('PRISM_CLEANUP_DB_DSN');
    if (!is_string($dsn) || !str_starts_with($dsn, 'mysql:')) throw new InvalidArgumentException('An explicit read-only connection (PRISM_CLEANUP_DB_DSN) is required.');
    if (!isset($o['retain-admins']) || !preg_match('/\A[1-9][0-9]{0,9}(,[1-9][0-9]{0,9})*\z/', (string)$o['retain-admins'])) throw new InvalidArgumentException('--retain-admins=<owner-confirmed users.id list> is required, for example 1,13,46.');
    $keep = array_values(array_unique(array_map('intval', explode(',', (string)$o['retain-admins'])))); sort($keep);
    $mode = $o['mode'] ?? 'preview'; $redact = isset($o['redact']);
    if (!in_array($mode, ['preview', 'verify-final'], true)) throw new InvalidArgumentException('--mode must be preview or verify-final.');
    $storage = isset($o['storage']) ? rtrim((string)$o['storage'], '/\\') : null;
    try { $pdo = new PDO($dsn, getenv('PRISM_CLEANUP_DB_USER') ?: '', getenv('PRISM_CLEANUP_DB_PASSWORD') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
    catch (PDOException $e) { throw new RuntimeException('connection_failed'); }
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $rows = static function (string $sql, array $p = []) use ($pdo): array { $q = $pdo->prepare($sql); $q->execute($p); return $q->fetchAll(); };
    $one = static fn(string $sql, array $p = []) => $rows($sql, $p)[0] ?? null;
    $n = static fn(string $sql, array $p = []) => (int)array_values($rows($sql, $p)[0] ?? [0])[0];
    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $has = static fn(string $t) => in_array($t, $tables, true);
    $in = static fn(array $ids) => $ids ? implode(',', array_map('intval', $ids)) : '0';
    $schema = (string)($one("SELECT v FROM schema_meta WHERE k='schema_version'")['v'] ?? '');
    $today = (string)$one('SELECT CURDATE() d')['d'];
    $hide = static fn($v) => $redact ? null : $v;
    $counts = static function () use ($tables, $n): array { $c = []; foreach ($tables as $t) $c[$t] = $n('SELECT COUNT(*) FROM `'.str_replace('`', '', $t).'`'); return $c; };
    $hash = static fn($v) => hash('sha256', json_encode($v, JSON_THROW_ON_ERROR));

    // ---- What must survive unchanged: the Administrators, their own records and institutional configuration.
    $fingerprint = static fn(array $u) => hash('sha256', implode('|', [$u['id'], $u['username'], $u['email'], $u['role'], $u['password_hash'], $u['created_at'], $u['status'], (int)$u['must_change_password']]));
    $admins = $rows("SELECT * FROM users WHERE role='admin' ORDER BY id");
    $adminIds = array_map(static fn($a) => (int)$a['id'], $admins);
    // Notices and campaigns are compared only up to the IDs that existed at preview time: cleanup steps (for example an
    // Admin cancelling an Adviser's deadline) legitimately add new ones, but must never alter the existing ones.
    // Admin-scoped records are hashed per Administrator, so the verifier compares exactly the owner-confirmed list.
    $preserved = static function (?array $limits = null) use ($rows, $n, $hash, $has, $fingerprint, $admins): array {
        $limits ??= ['notification' => $n('SELECT COALESCE(MAX(id),0) FROM notifications'), 'campaign' => $n('SELECT COALESCE(MAX(id),0) FROM notification_campaigns')];
        $perAdmin = []; $fingerprints = [];
        foreach ($admins as $a) {
            $id = (int)$a['id']; $fingerprints[(string)$id] = $fingerprint($a);
            $perAdmin[(string)$id] = [
                'deadlines' => $hash($rows('SELECT id,title,description,deadline_date,target_scope,status,created_at FROM calendar_deadlines WHERE creator_user_id=? ORDER BY id', [$id])),
                'deadlineGroups' => $hash($rows('SELECT g.deadline_id,g.research_group FROM calendar_deadline_groups g JOIN calendar_deadlines d ON d.id=g.deadline_id WHERE d.creator_user_id=? ORDER BY 1,2', [$id])),
                'notifications' => $hash($rows('SELECT id,status,read_at,archived_at,deleted_at,subject FROM notifications WHERE recipient_user_id=? AND id<=? ORDER BY id', [$id, (int)$limits['notification']])),
                'campaigns' => $hash($rows('SELECT id,status,archived_at,deleted_at,subject FROM notification_campaigns WHERE sender_user_id=? AND id<=? ORDER BY id', [$id, (int)$limits['campaign']])),
                'policyAcceptances' => $hash($rows('SELECT policy_version_id FROM user_policy_acceptances WHERE user_id=? ORDER BY 1', [$id])),
                'passwordResets' => $hash($rows('SELECT id,used FROM password_resets WHERE user_id=? ORDER BY id', [$id])),
            ];
        }
        return [
            'limits' => $limits,
            'adminFingerprints' => $fingerprints,
            'adminRecords' => $perAdmin,
            'legalPolicies' => $hash([$rows('SELECT * FROM legal_policies ORDER BY id'), $rows('SELECT id,policy_id,state,published_at,creator_user_id FROM legal_policy_versions ORDER BY id'), $rows('SELECT * FROM legal_policy_approvals ORDER BY id')]),
            'staffRegistrationCode' => $hash($rows('SELECT * FROM staff_registration_code')),
            'stageLabels' => $hash($rows('SELECT * FROM stage_labels')),
            'schemaMeta' => $hash($rows('SELECT * FROM schema_meta ORDER BY k')),
            'academicYears' => $has('academic_years') ? $hash($rows('SELECT * FROM academic_years ORDER BY 1')) : null,
        ];
    };
    $adminProblems = [];
    foreach ($keep as $id) {
        $a = $one('SELECT id,role,status,must_change_password FROM users WHERE id=?', [$id]);
        if (!$a) $adminProblems[] = ['id' => $id, 'problem' => 'confirmed_admin_id_not_found'];
        elseif ($a['role'] !== 'admin') $adminProblems[] = ['id' => $id, 'problem' => 'confirmed_id_is_not_an_admin'];
        elseif (strcasecmp((string)$a['status'], 'Active') !== 0) $adminProblems[] = ['id' => $id, 'problem' => 'admin_not_active (preserved as is)'];
    }
    foreach (array_diff($adminIds, $keep) as $id) $adminProblems[] = ['id' => $id, 'problem' => 'admin_not_in_owner_confirmed_list (every Admin is preserved; revalidate identities before execution)'];

    if ($mode === 'verify-final') {
        $expectFile = $o['expect-from'] ?? null;
        if (!is_string($expectFile) || !is_file($expectFile)) throw new InvalidArgumentException('--expect-from=<the approved preview JSON> is required for verify-final.');
        $expect = json_decode((string)file_get_contents($expectFile), true, 64, JSON_THROW_ON_ERROR);
        $now = $preserved($expect['preserved']['limits']); $c = $counts();
        // Institution-wide report deletion is separately authorized: verify either state explicitly.
        $reportsState = $o['institution-reports'] ?? ($expect['plan']['keepInstitutionReports'] ? 'kept' : 'deleted');
        if (!in_array($reportsState, ['kept', 'deleted'], true)) throw new InvalidArgumentException('--institution-reports must be kept or deleted.');
        $keptReports = $reportsState === 'kept' ? array_map('strval', array_column($expect['institutionReports'], 'id')) : [];
        // Audit accounting: every audit row that existed at preview time and was not predicted for removal must still exist.
        $audit = $expect['auditImpact'];
        $predicted = array_map('intval', $audit['predictedRemovalIds']);
        $stillPresentOutside = $n('SELECT COUNT(*) FROM activity_logs WHERE id<=?'.($predicted ? ' AND id NOT IN ('.implode(',', $predicted).')' : ''), [(int)$audit['maxAuditId']]);
        $removedPreexisting = (int)$audit['rowsAtPreview'] - $n('SELECT COUNT(*) FROM activity_logs WHERE id<=?', [(int)$audit['maxAuditId']]);
        $sameForKept = static fn(string $k) => array_reduce($keep, static fn($ok, $id) => $ok && isset($expect['preserved'][$k][(string)$id]) && $now[$k][(string)$id] === $expect['preserved'][$k][(string)$id], true);
        $orphanNotices = array_map('intval', $expect['orphanStudentNotifications'] ?? []);
        $checks = [
            'admins_preserved_unchanged' => $adminIds === $keep && $sameForKept('adminFingerprints'),
            'admin_owned_records_unchanged' => $sameForKept('adminRecords'),
            'only_admin_logins_remain' => $n("SELECT COUNT(*) FROM users WHERE role<>'admin'") === 0,
            'zero_students' => $c['students'] === 0, 'zero_advisers' => $c['advisers'] === 0,
            'zero_documents' => $c['documents'] === 0, 'zero_ierb_history' => $c['ierb_history'] === 0,
            'zero_student_owned_ai_outputs' => $n('SELECT COUNT(*) FROM ai_outputs WHERE owner_student_id IS NOT NULL OR owner_document_id IS NOT NULL') === 0,
            'zero_deadline_recipients' => $c['calendar_deadline_recipients'] === 0,
            'reports_as_planned' => array_map('strval', array_column($rows('SELECT id FROM reports ORDER BY id'), 'id')) === $keptReports,
            // Only the orphaned legacy notices identified in the approved preview may remain (no application path removes them).
            'student_notifications_only_identified_orphans' => !array_diff(array_map('intval', array_column($rows("SELECT id FROM notifications WHERE recipient_type='student'"), 'id')), $orphanNotices),
            'zero_non_admin_tokens' => $n("SELECT (SELECT COUNT(*) FROM account_invitations i JOIN users u ON u.id=i.user_id WHERE u.role<>'admin')+(SELECT COUNT(*) FROM password_resets p JOIN users u ON u.id=p.user_id WHERE u.role<>'admin')") === 0,
            'no_pending_purge_recovery' => $n('SELECT COUNT(*) FROM account_purge_jobs WHERE completed_at IS NULL') === 0,
            'audit_rows_outside_prediction_preserved' => $stillPresentOutside === (int)$audit['rowsAtPreview'] - count($predicted),
        ];
        foreach (['legalPolicies', 'staffRegistrationCode', 'stageLabels', 'schemaMeta', 'academicYears'] as $k) {
            $checks['unchanged_'.$k] = $now[$k] === $expect['preserved'][$k];
        }
        foreach (['research_projects', 'research_project_members', 'ierb_workflows', 'ierb_correction_cases', 'document_summary_versions', 'ierb_report_snapshots'] as $t) if ($has($t)) $checks['zero_'.$t] = $c[$t] === 0;
        $pdo->rollBack();
        $failed = array_keys(array_filter($checks, static fn($v) => !$v));
        echo json_encode(['mode' => 'verify-final', 'generatedAt' => date(DATE_ATOM), 'schemaVersion' => $schema, 'readOnly' => true, 'status' => $failed ? 'FAIL' : 'PASS',
            'failed' => $failed, 'checks' => $checks, 'admins' => $adminIds, 'adminProblems' => $adminProblems, 'institutionReports' => $reportsState, 'tableCounts' => $c,
            'auditAccounting' => ['preexistingRowsRemoved' => $removedPreexisting, 'predictedUpperBound' => count($predicted), 'predictedAdminActorRows' => count($audit['adminActorRemovalIds'])],
            'retainedHistory' => ['historicalAdviserNotifications' => $n("SELECT COUNT(*) FROM notifications WHERE recipient_type='historical_adviser'"),
                'deadlinesWithoutLiveCreator' => $n('SELECT COUNT(*) FROM calendar_deadlines WHERE creator_user_id IS NULL'), 'auditRows' => $c['activity_logs'], 'purgeJobs' => $c['account_purge_jobs']]],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
        exit($failed ? 3 : 0);
    }

    $blockers = [];
    $block = static function (string $kind, $id, string $reason, string $remedy) use (&$blockers) { $blockers[] = compact('kind', 'id', 'reason', 'remedy'); };
    foreach ($adminProblems as $p) $block('admin', $p['id'], $p['problem'], 'Owner revalidates the Administrator identities on production (read-only) before any step.');
    $v14 = array_values(array_filter(['ierb_workflows', 'research_project_members', 'document_summary_versions'], $has));
    $adminSet = $in($adminIds);
    // Mirrors lifecycle_resolve_login() (includes/account_identity.php), which the purge uses: [login|null, refusal|null].
    $resolve = static function (array $r, string $type) use ($rows, $one): array {
        $identifier = (string)$r[$type === 'student' ? 'student_id' : 'employee_id'];
        $known = json_decode((string)($one('SELECT details FROM activity_logs WHERE entity_type=? AND entity_id=? AND action IN ("account_identity_created","account_identity_changed") ORDER BY id DESC LIMIT 1', [$type, (string)$r['id']])['details'] ?? '[]'), true) ?: [];
        $knownUid = (int)($known['login_after'] ?? $known['login_id'] ?? 0);
        $logins = $rows('SELECT * FROM users WHERE email=? OR username=? OR ref_id=? OR id=? OR id=? ORDER BY id', [$r['email'], $identifier, $identifier, $r['user_id'] ?? 0, $knownUid]);
        if (!$logins && empty($r['user_id']) && !$knownUid) return [null, null];
        if (count($logins) !== 1) return [null, 'login_ownership_ambiguous'];
        $l = $logins[0]; $explicit = !empty($r['user_id']) && (int)$r['user_id'] === (int)$l['id']; $attested = $knownUid && $knownUid === (int)$l['id'];
        if ($l['role'] !== $type || (!empty($r['user_id']) && !$explicit) || ($knownUid && !$attested) || (!$explicit && !$attested && $l['username'] !== $identifier && (string)$l['ref_id'] !== $identifier)) return [$l, 'login_identity_conflict'];
        foreach (['students' => 'student', 'advisers' => 'adviser'] as $table => $role) {
            $field = $role === 'student' ? 'student_id' : 'employee_id'; $same = (int)($role === $type);
            if ($rows("SELECT id FROM $table WHERE (user_id=? OR (email=? AND ?=1) OR ($field=? AND ?=1) OR ($field=? AND ?=1)) AND (?<>? OR id<>?)", [$l['id'], $l['email'], $same, $l['username'], $same, $l['ref_id'], $same, $role, $type, $r['id']])) return [$l, 'login_claimed_by_another_record'];
        }
        return [$l, $l['email'] !== $r['email'] ? 'login_email_differs_from_record' : null];
    };

    // ---- Students: Archive -> Permanent delete. Only the lifecycle's own refusal rules are blockers.
    $students = [];
    foreach ($rows('SELECT * FROM students ORDER BY id') as $s) {
        $id = (int)$s['id'];
        $docs = $rows('SELECT id,stored_name,document_type,version_no,is_current,rpms_submitted_at FROM documents WHERE student_id=? ORDER BY id', [$id]);
        $returned = array_column($rows("SELECT DISTINCT d.id FROM documents d JOIN activity_logs a ON a.entity_type='document' AND a.entity_id=d.id AND a.action='document_returned_for_revision' WHERE d.student_id=? AND d.is_current=1", [$id]), 'id');
        $unsubmitted = array_values(array_map(static fn($d) => $d['id'], array_filter($docs, static fn($d) => (int)$d['is_current'] === 1 && empty($d['rpms_submitted_at']))));
        [$login, $loginProblem] = $resolve($s, 'student');
        $docIds = array_column($docs, 'id');
        $docList = $docIds ? implode(',', array_map(static fn($d) => $pdo->quote($d), $docIds)) : "''";
        $deps = ['documents' => count($docs), 'formallySubmitted' => count(array_filter($docs, static fn($d) => !empty($d['rpms_submitted_at']))),
            'reportsOwned' => $n('SELECT COUNT(*) FROM reports WHERE owner_student_id=?', [$id]), 'ierbHistory' => $n('SELECT COUNT(*) FROM ierb_history WHERE student_id=?', [$id]),
            'notifications' => $n("SELECT COUNT(*) FROM notifications WHERE recipient_type='student' AND recipient_id=?", [$id]),
            'aiOutputs' => $n("SELECT COUNT(*) FROM ai_outputs WHERE owner_student_id=? OR owner_document_id IN ($docList)", [$id])];
        // Admin-owned records that reference this test Student (identified; the reviewed purge handles them as shown).
        $adminRefs = ['adminDeadlineRecipientLinks' => $n("SELECT COUNT(*) FROM calendar_deadline_recipients r JOIN calendar_deadlines d ON d.id=r.deadline_id WHERE r.student_id=? AND d.creator_user_id IN ($adminSet)", [$id]),
            'adminGeneratedReportsOwnedByStudent' => $n("SELECT COUNT(*) FROM reports WHERE owner_student_id=? AND generated_by_user_id IN ($adminSet)", [$id]),
            'adminActorAuditRowsRemovedByPurge' => $n("SELECT COUNT(*) FROM activity_logs WHERE actor_role='admin' AND (student_id=? OR (entity_type='student' AND entity_id=?) OR (entity_type='document' AND entity_id IN ($docList)))", [$id, (string)$id])];
        $why = [];
        if (!empty($s['retention_hold'])) $why[] = ['retention_hold', 'RPMS removes the Retention Hold through the lifecycle (audited), or the record is retained.'];
        foreach ($v14 as $t) if ($n("SELECT COUNT(*) FROM `$t` ".($t === 'document_summary_versions' ? "v WHERE v.document_id IN ($docList)" : 'WHERE student_id=?'), $t === 'document_summary_versions' ? [] : [$id])) $why[] = [$t.'_history', 'No reviewed path deletes retained V14 history. Clean up before enrollment/summarization.'];
        if ($n("SELECT COUNT(*) FROM notifications WHERE recipient_type='student' AND (recipient_id=? OR ((recipient_id IS NULL OR recipient_id<=0) AND (recipient_email=? OR recipient_name=?))) AND status='Sending'", [$id, $s['email'], $s['full_name']])) $why[] = ['notification_sending', 'Wait for delivery, then re-run the preview.'];
        if ($n("SELECT COUNT(*) FROM notifications WHERE recipient_type='student' AND (recipient_id IS NULL OR recipient_id<=0) AND (recipient_email=? OR recipient_name=?) AND (recipient_email<>? OR (recipient_name IS NOT NULL AND recipient_name<>'' AND recipient_name<>?))", [$s['email'], $s['full_name'], $s['email'], $s['full_name']])) $why[] = ['ambiguous_legacy_notification_ownership', 'Admin review of the named legacy notifications.'];
        if ($loginProblem) $why[] = [$loginProblem, 'The lifecycle refuses the purge (lifecycle_resolve_login); reconcile the login identity through the audited record edit first.'];
        if ($n('SELECT COUNT(*) FROM documents WHERE student_id IS NULL AND (student_name=? OR (uploaded_by_role="student" AND uploaded_by=?))', [$s['full_name'], $s['full_name']])) $why[] = ['unlinked_legacy_document_collision', 'Admin deletes the unlinked legacy document after review (Documents).'];
        if ($n('SELECT COUNT(*) FROM documents d LEFT JOIN documents p ON p.id=d.supersedes_id WHERE d.student_id=? AND d.supersedes_id IS NOT NULL AND (p.id IS NULL OR p.student_id IS NULL OR p.student_id<>?)', [$id, $id])
            || $n('SELECT COUNT(*) FROM documents d JOIN documents o ON o.id=d.supersedes_id WHERE o.student_id=? AND (d.student_id IS NULL OR d.student_id<>?)', [$id, $id])) $why[] = ['version_chain_crosses_accounts', 'Admin review required.'];
        if ($n('SELECT COUNT(*) FROM documents d JOIN documents o ON o.stored_name=d.stored_name AND o.id<>d.id WHERE d.student_id=?', [$id])) $why[] = ['shared_document_file', 'Admin review required.'];
        if ($n('SELECT COUNT(*) FROM reports r JOIN reports o ON o.filename=r.filename AND o.id<>r.id WHERE r.owner_student_id=?', [$id])) $why[] = ['shared_report_file', 'Admin review required.'];
        if ($login && $n("SELECT COUNT(*) FROM calendar_deadlines WHERE creator_user_id=? AND status='Active' AND deadline_date>=?", [$login['id'], $today])) $why[] = ['future_active_deadline_authored', 'Cancel the deadline first.'];
        if (count($docs) + $deps['reportsOwned'] > 2000) $why[] = ['over_2000_files', 'Admin review required.'];
        foreach ($why as [$reason, $remedy]) $block('student', $id, $reason, $remedy);
        $pre = [];
        if ($unsubmitted || $returned) $pre[] = ['operation' => 'Documents → Delete (Admin) each listed current version WITH A REASON (returned versions are formally submitted, so the reason is mandatory); repeat while an earlier unsubmitted or returned version becomes current', 'documentIds' => array_values(array_unique(array_merge($unsubmitted, $returned))),
            'why' => 'An unsubmitted or returned current version is an unresolved workflow, which postpones the purge.'];
        if (empty($s['archived_at'])) $pre[] = ['operation' => 'Archive (Admin lifecycle; reversible)'];
        $students[] = ['id' => $id, 'studentNumber' => $hide($s['student_id']), 'name' => $hide($s['full_name']), 'email' => $hide($s['email']), 'pendingOnboarding' => trim((string)$s['student_id']) === '' || empty($s['profile_completed_at']),
            'archivedAt' => $s['archived_at'], 'stage' => $s['stage'], 'loginUserId' => $login ? (int)$login['id'] : null, 'lifecycleBlockers' => array_column($why, 0), 'preSteps' => $pre,
            'removal' => $why ? 'blocked' : 'Permanent delete via Admin lifecycle (confirmation = '.($redact ? 'exact Student ID, or invited email if Pending' : (trim((string)$s['student_id']) !== '' ? $s['student_id'] : $s['email'])).')',
            'dependencies' => $deps, 'adminRecordsReferencing' => $adminRefs,
            'files' => array_map(static fn($d) => ['documentId' => $d['id'], 'storedName' => basename((string)$d['stored_name']), 'version' => (int)$d['version_no'],
                'fileExists' => $storage !== null ? is_file($storage.'/documents/'.basename((string)$d['stored_name'])) : null], $docs)];
    }

    // ---- Advisers: purged after their Students (assignments are then empty).
    $advisers = [];
    foreach ($rows('SELECT * FROM advisers ORDER BY id') as $a) {
        $id = (int)$a['id'];
        [$login, $loginProblem] = $resolve($a, 'adviser');
        $authored = $login ? $rows('SELECT id,status,deadline_date FROM calendar_deadlines WHERE creator_user_id=? ORDER BY id', [$login['id']]) : [];
        $future = array_values(array_map(static fn($d) => (int)$d['id'], array_filter($authored, static fn($d) => $d['status'] === 'Active' && $d['deadline_date'] >= $today)));
        $why = [];
        if (!empty($a['retention_hold'])) $why[] = ['retention_hold', 'RPMS removes the Retention Hold (audited), or the record is retained.'];
        if ($has('research_projects') && $n('SELECT COUNT(*) FROM research_projects WHERE adviser_id=?', [$id])) $why[] = ['canonical_project_adviser', 'No reviewed path deletes retained project history.'];
        if ($n("SELECT COUNT(*) FROM notifications WHERE recipient_type='adviser' AND (recipient_id=? OR ((recipient_id IS NULL OR recipient_id<=0) AND (recipient_email=? OR recipient_name=?))) AND status='Sending'", [$id, $a['email'], $a['full_name']])) $why[] = ['notification_sending', 'Wait for delivery, then re-run.'];
        if ($loginProblem) $why[] = [$loginProblem, 'The lifecycle refuses the purge (lifecycle_resolve_login); Admin review required.'];
        foreach ($why as [$reason, $remedy]) $block('adviser', $id, $reason, $remedy);
        $pre = [];
        if ($future) $pre[] = ['operation' => 'Calendar → Cancel each future Active deadline this Adviser authored (the lifecycle refuses the purge otherwise)', 'deadlineIds' => $future];
        if (empty($a['archived_at'])) $pre[] = ['operation' => 'Archive (Admin lifecycle) after every assigned Student is purged (expectedAssignedStudents = 0)'];
        $advisers[] = ['id' => $id, 'employeeId' => $hide($a['employee_id']), 'name' => $hide($a['full_name']), 'email' => $hide($a['email']), 'archivedAt' => $a['archived_at'],
            'assignedStudentsNow' => $n('SELECT COUNT(*) FROM students WHERE adviser_id=?', [$id]), 'loginUserId' => $login ? (int)$login['id'] : null, 'lifecycleBlockers' => array_column($why, 0), 'preSteps' => $pre,
            'removal' => $why ? 'blocked' : 'Permanent delete via Admin lifecycle', 'deadlinesAuthored' => count($authored),
            // Notices carrying the Adviser's login are removed with it (foreign-key CASCADE); the rest are retyped as history.
            'retainedAfterPurge' => ['deadlinesKeptAsHistoryWithCreatorRemoved' => count($authored),
                'notificationsKeptAsHistoricalAdviser' => $n("SELECT COUNT(*) FROM notifications WHERE recipient_type='adviser' AND recipient_id=? AND (recipient_user_id IS NULL OR recipient_user_id<>?)", [$id, $login['id'] ?? 0]),
                'notificationsRemovedWithLogin' => $login ? $n('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=?', [$login['id']]) : 0,
                'campaignsSentContentErased' => $login ? $n('SELECT COUNT(*) FROM notification_campaigns WHERE sender_user_id=?', [$login['id']]) : 0]];
    }

    // ---- Audit impact: the audit rows the reviewed purge DELETES (mirrors retention_purge_mutate() clause for clause), so
    // their retention is decided before execution and verify-final proves no other pre-existing audit row was removed.
    $adminEmails = array_column($admins, 'email');
    $quoted = static fn(array $v) => implode(',', array_map(static fn($x) => $pdo->quote((string)$x), $v));
    $removal = []; $pseudonymized = 0;
    foreach ($rows('SELECT * FROM students ORDER BY id') as $s) {
        $id = (int)$s['id']; [$login] = $resolve($s, 'student');
        $docIds = array_column($rows('SELECT id FROM documents WHERE student_id=?', [$id]), 'id');
        $reportIds = array_column($rows('SELECT id FROM reports WHERE owner_student_id=?', [$id]), 'id');
        $emails = [(string)$s['email']]; $codes = [trim((string)$s['student_id']) !== '' ? (string)$s['student_id'] : 'student account #'.$id];
        foreach ($rows("SELECT details,before_value FROM activity_logs WHERE entity_type='student' AND entity_id=? AND action='account_identity_field_changed'", [(string)$id]) as $f) {
            if ($f['details'] === 'email' && $f['before_value']) $emails[] = $f['before_value'];
            if ($f['details'] === 'identifier' && $f['before_value']) $codes[] = $f['before_value'];
        }
        $emails = array_values(array_filter(array_unique($emails), static fn($e) => !$n('SELECT COUNT(*) FROM users WHERE email=? AND id<>?', [$e, $login['id'] ?? 0])));
        $codes = array_values(array_filter(array_unique($codes), static fn($c) => !$n('SELECT COUNT(*) FROM students WHERE student_id=? AND id<>?', [$c, $id])));
        $where = ['student_id=?', "(entity_type='student' AND entity_id=?)"]; $params = [$id, (string)$id];
        if ($docIds) $where[] = "(entity_type='document' AND entity_id IN (".$quoted($docIds).'))';
        if ($reportIds) $where[] = "(entity_type='report' AND entity_id IN (".$quoted($reportIds).'))';
        if ($emails) $where[] = 'user_email IN ('.$quoted($emails).')';
        if ($codes) $where[] = "(action='student_saved' AND details IN (".$quoted(array_map(static fn($c) => 'student_id='.$c, $codes)).'))';
        if ($login) { $where[] = "(entity_type='user' AND entity_id=?)"; $params[] = (string)$login['id']; }
        foreach ($rows('SELECT id,action,actor_role,user_email FROM activity_logs WHERE '.implode(' OR ', $where), $params) as $r) $removal[(int)$r['id']] = $r;
    }
    foreach ($rows('SELECT * FROM advisers ORDER BY id') as $a) {
        [$login] = $resolve($a, 'adviser');
        // A never-completed Adviser's rows are deleted; any other Adviser's rows are kept with the entity link removed.
        if (empty($a['employee_id']) && $a['profile_completed_at'] === null) {
            foreach ($rows("SELECT id,action,actor_role,user_email FROM activity_logs WHERE (entity_type='adviser' AND entity_id=?) OR user_email=?", [(string)$a['id'], $a['email']]) as $r) $removal[(int)$r['id']] = $r;
        } else $pseudonymized += $n("SELECT COUNT(*) FROM activity_logs WHERE entity_type='adviser' AND entity_id=?", [(string)$a['id']]);
        if ($login) $pseudonymized += $n("SELECT COUNT(*) FROM activity_logs WHERE entity_type='user' AND entity_id=?", [(string)$login['id']]);
    }
    ksort($removal);
    $adminRemoval = array_filter($removal, static fn($r) => $r['actor_role'] === 'admin' || in_array($r['user_email'], $adminEmails, true));
    $byAction = []; foreach ($adminRemoval as $r) $byAction[$r['action']] = ($byAction[$r['action']] ?? 0) + 1; arsort($byAction);
    $auditImpact = ['maxAuditId' => $n('SELECT COALESCE(MAX(id),0) FROM activity_logs'), 'rowsAtPreview' => $n('SELECT COUNT(*) FROM activity_logs'),
        'predictedRemovalIds' => array_keys($removal), 'adminActorRemovalIds' => array_keys($adminRemoval), 'adminActorRemovalByAction' => $byAction,
        'adviserRowsKeptWithEntityLinkRemoved' => $pseudonymized,
        'alsoRemovedDuringCleanup' => 'Each Student\'s Archive audit and the audits of its pre-step document deletions are written during the cleanup and removed by that Student\'s purge; the purge\'s own audit row (actor, method, reason, identifier) and its purge job are kept.'];

    // ---- Logins and records outside account ownership.
    $owned = array_filter(array_merge(array_column($students, 'loginUserId'), array_column($advisers, 'loginUserId')));
    $orphanLogins = [];
    foreach ($rows("SELECT id,role FROM users WHERE role<>'admin' ORDER BY id") as $u) if (!in_array((int)$u['id'], $owned, true)) {
        $orphanLogins[] = (int)$u['id'];
        $block('user', (int)$u['id'], 'login_without_owning_record', 'A '.$u['role'].' login with no Student/Adviser record cannot be purged by the lifecycle. Requires review.');
    }
    $institutionReports = $rows('SELECT id,filename,title,generated_by_user_id,generated_at FROM reports WHERE owner_student_id IS NULL ORDER BY id');
    foreach ($institutionReports as $r) {
        if (basename((string)$r['filename']) !== (string)$r['filename'] || !preg_match('/\A[A-Za-z0-9_.-]+\z/', (string)$r['filename']) || $n('SELECT COUNT(*) FROM reports WHERE filename=?', [$r['filename']]) !== 1) $block('report', $r['id'], 'report_file_requires_review', 'Report deletion refuses shared or unusual filenames.');
        if ($has('ierb_report_snapshots') && $n('SELECT COUNT(*) FROM ierb_report_snapshots WHERE report_id=?', [$r['id']])) $block('report', $r['id'], 'retained_report_snapshot', 'No reviewed path deletes a retained snapshot.');
    }
    $unlinkedDocs = array_column($rows('SELECT id FROM documents WHERE student_id IS NULL ORDER BY id'), 'id');
    // Student-type notices that no current account owns (for example a Student removed before the current lifecycle):
    // the purge cannot reach them and a notice can only be deleted by its recipient, so they are retained and listed.
    $orphanNotices = array_map('intval', array_column($rows("SELECT n.id FROM notifications n WHERE n.recipient_type='student'
        AND NOT EXISTS (SELECT 1 FROM students s WHERE s.id=n.recipient_id OR ((n.recipient_id IS NULL OR n.recipient_id<=0) AND (n.recipient_email=s.email OR n.recipient_name=s.full_name)))
        AND NOT EXISTS (SELECT 1 FROM users u WHERE u.id=n.recipient_user_id AND u.role<>'admin') ORDER BY n.id"), 'id'));
    $orphanAudit = $n("SELECT COUNT(*) FROM activity_logs WHERE student_id IS NOT NULL AND student_id NOT IN (SELECT id FROM students)");
    $unownedAi = $n('SELECT COUNT(*) FROM ai_outputs WHERE owner_student_id IS NULL AND owner_document_id IS NULL');
    if ($unownedAi) $block('ai_outputs', null, 'ai_outputs_without_owner', 'No application operation deletes unowned AI outputs; they are retained and listed.');
    $adminDeadlines = $rows("SELECT id,status,deadline_date,creator_user_id,target_scope FROM calendar_deadlines WHERE creator_user_id IN ($adminSet) ORDER BY id");
    $nonAdminRefs = ['staffCodeConsumedOrCreatedByNonAdmin' => $n("SELECT COUNT(*) FROM staff_registration_code s JOIN users u ON u.id IN (s.consumed_by_user_id,s.creator_user_id) WHERE u.role<>'admin'"),
        'legalRecordsByNonAdmin' => $n("SELECT (SELECT COUNT(*) FROM legal_policy_versions v JOIN users u ON u.id=v.creator_user_id WHERE u.role<>'admin')+(SELECT COUNT(*) FROM legal_policy_approvals a JOIN users u ON u.id=a.reviewer_user_id WHERE u.role<>'admin')"),
        'invitationsSentByNonAdmin' => $n("SELECT COUNT(*) FROM account_invitations i JOIN users u ON u.id=i.invited_by_user_id WHERE u.role<>'admin'")];
    foreach (['staffCodeConsumedOrCreatedByNonAdmin', 'legalRecordsByNonAdmin'] as $k) if ($nonAdminRefs[$k]) $block('configuration', null, $k, 'A configuration row references a test login; its foreign key is SET NULL on purge. Owner reviews before execution.');
    $orphanFiles = null;
    if ($storage !== null) {
        $referenced = ['documents' => array_flip(array_map('basename', array_column($rows('SELECT stored_name FROM documents'), 'stored_name'))), 'reports' => array_flip(array_map('basename', array_column($rows('SELECT filename FROM reports'), 'filename')))];
        foreach (['documents', 'reports'] as $dir) foreach (glob($storage.'/'.$dir.'/*') ?: [] as $f) if (is_file($f) && !isset($referenced[$dir][basename($f)])) $orphanFiles[$dir][] = basename($f);
    }
    $keepReports = isset($o['keep-institution-reports']);
    $current = $counts(); $snapshot = $preserved();
    $expected = $current;
    foreach (['students', 'advisers', 'documents', 'ierb_history', 'calendar_deadline_recipients'] as $t) $expected[$t] = 0;
    $expected['users'] = count($adminIds) + count($orphanLogins);
    $expected['reports'] = $keepReports ? count($institutionReports) : 0;
    $expected['ai_outputs'] = $unownedAi;
    $expected['account_invitations'] = $n("SELECT COUNT(*) FROM account_invitations WHERE user_id IN ($adminSet)");
    $expected['password_resets'] = $n("SELECT COUNT(*) FROM password_resets WHERE user_id IN ($adminSet)");
    $expected['user_policy_acceptances'] = $n("SELECT COUNT(*) FROM user_policy_acceptances WHERE user_id IN ($adminSet)");
    $expected['account_purge_jobs'] = $current['account_purge_jobs'] + count($students) + count($advisers);
    $expected['calendar_deadlines'] = $current['calendar_deadlines'].' (none deleted: '.count($adminDeadlines).' Admin-authored unchanged; Adviser-authored kept as history, future Active ones cancelled)';
    $expected['notifications'] = 'Students\' notices removed (except '.count($orphanNotices).' orphaned legacy notices no account owns); Advisers\' kept as historical_adviser; Admins\' unchanged';
    $expected['activity_logs'] = 'current minus rows the purge removes for each deleted account and its documents/reports, plus one audit per operation';
    $pdo->rollBack();

    $manifest = ['mode' => 'preview', 'scope' => 'D12 revised: preserve every Administrator; remove test Students and Advisers with their test research records',
        'generatedAt' => date(DATE_ATOM), 'schemaVersion' => $schema, 'serverVersion' => (string)$pdo->query('SELECT VERSION()')->fetchColumn(), 'readOnly' => true, 'redacted' => $redact,
        'plan' => ['retainAdmins' => $keep, 'keepInstitutionReports' => $keepReports],
        'admins' => array_map(static fn($a) => ['id' => (int)$a['id'], 'status' => $a['status'], 'createdAt' => $a['created_at'], 'username' => $hide($a['username']), 'email' => $hide($a['email']), 'mustChangePassword' => !empty($a['must_change_password']),
            'deadlinesAuthored' => $n('SELECT COUNT(*) FROM calendar_deadlines WHERE creator_user_id=?', [$a['id']]), 'notifications' => $n('SELECT COUNT(*) FROM notifications WHERE recipient_user_id=?', [$a['id']])], $admins),
        'adminProblems' => $adminProblems,
        'summary' => ['admins' => count($adminIds), 'students' => count($students), 'studentsBlocked' => count(array_filter($students, static fn($s) => $s['removal'] === 'blocked')),
            'studentsNeedingDocumentPreSteps' => count(array_filter($students, static fn($s) => array_filter($s['preSteps'], static fn($p) => isset($p['documentIds'])))),
            'advisers' => count($advisers), 'advisersBlocked' => count(array_filter($advisers, static fn($a) => $a['removal'] === 'blocked')),
            'adviserDeadlinesToCancel' => array_sum(array_map(static fn($a) => count($a['preSteps'][0]['deadlineIds'] ?? []), $advisers)),
            'institutionReports' => count($institutionReports), 'unlinkedDocuments' => count($unlinkedDocs), 'adminDeadlinesPreserved' => count($adminDeadlines),
            'adminDeadlineRecipientLinksRemovedByPurge' => array_sum(array_map(static fn($s) => $s['adminRecordsReferencing']['adminDeadlineRecipientLinks'], $students)),
            'auditRowsRemovedByPurge' => count($removal), 'adminActorAuditRowsRemovedByPurge' => count($adminRemoval),
            'orphanLogins' => count($orphanLogins), 'orphanStudentNotifications' => count($orphanNotices), 'orphanStudentAuditRows' => $orphanAudit, 'unownedAiOutputs' => $unownedAi, 'blockers' => count($blockers), 'orphanFiles' => $orphanFiles === null ? null : array_map('count', $orphanFiles)],
        'blockers' => $blockers,
        'operationOrder' => ['1. Verified hPanel database + storage backup, restore verified.', '2. Re-run this preview on the restored copy; owner signs decisionSha256 and the preserved fingerprints.',
            '3. Issue deletion-verification evidence for that exact database (maintenance/issue-deletion-verification.php) and place it in its config.local.php.',
            '4. Calendar: cancel only the future Active deadlines authored by Advisers (adviser preSteps). Admin deadlines are not touched.',
            '5. Documents: delete the unsubmitted/returned current versions in Student preSteps' .($unlinkedDocs ? ', and the unlinked legacy documents' : '').'.',
            '6. Archive every Student and Adviser.', '7. Permanent delete every Student (grace_period_override with a reason, one by one; or bulk permanent delete after 7 days).',
            '8. Permanent delete every Adviser (after step 7).', $keepReports ? '9. Institution-wide reports are kept (owner choice).' : '9. Reports → Delete each remaining institution-wide report (owner-confirmed: they summarize test records only).',
            '10. Recovery jobs: none pending.', '11. --mode=verify-final --expect-from=<this approved preview>; then every Admin signs in and operates PRISM.'],
        'students' => $students, 'advisers' => $advisers,
        'institutionReports' => array_map(static fn($r) => ['id' => $r['id'], 'filename' => $r['filename'], 'title' => $hide($r['title']), 'generatedByUserId' => $r['generated_by_user_id'] === null ? null : (int)$r['generated_by_user_id'], 'generatedAt' => $r['generated_at'],
            'decision' => $keepReports ? 'retain (owner choice)' : 'Reports → Delete (Admin); summarizes test records only'], $institutionReports),
        'unlinkedDocuments' => $unlinkedDocs, 'orphanStudentNotifications' => $orphanNotices, 'adminDeadlines' => $adminDeadlines, 'nonAdminReferencesInConfiguration' => $nonAdminRefs, 'orphanFiles' => $orphanFiles,
        'auditImpact' => $auditImpact, 'preserved' => $snapshot, 'currentCounts' => $current, 'expectedFinalCounts' => $expected,
        'retainedByDesign' => 'Every Administrator and its credentials, policy acceptances, deadlines, notifications and campaigns; schema and constraints; schema_meta, stage_labels, legal policies/versions/approvals, staff_registration_code, academic_years; activity_logs and account_purge_jobs (audit evidence), less what the reviewed purge removes for each deleted account.'];
    $manifest['decisionSha256'] = hash('sha256', json_encode([$keep, $keepReports, $snapshot, array_map(static fn($s) => [$s['id'], $s['removal'], $s['preSteps']], $students),
        array_map(static fn($a) => [$a['id'], $a['removal'], $a['preSteps']], $advisers), array_column($institutionReports, 'id'), $unlinkedDocs, $orphanNotices, $auditImpact['predictedRemovalIds'], $blockers], JSON_THROW_ON_ERROR));
    echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    fwrite(STDERR, json_encode(['ok' => false, 'error' => $e instanceof RuntimeException && $e->getMessage() === 'connection_failed' ? 'connection_failed' : $e->getMessage()]).PHP_EOL);
    exit(1);
}
