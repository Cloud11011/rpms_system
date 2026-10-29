// Restored-copy rehearsal of decision D12 (revised): run the cleanup plan against an ISOLATED restore of the owner-supplied
// export (dedicated fixture whose datadir is verified by bootstrap.php). Every Administrator is preserved; every Student and
// Research Adviser is removed through the real HTTP Archive -> Permanent delete operations. The real Administrators'
// passwords are unknown and never reset, so a test-only operator Admin (9001) performs the steps and then deletes its own
// account; the real Administrators are verified structurally (credentials fingerprint, eligibility, legal status).
// Logs contain database IDs, counts and hashes only; never names, emails or Student IDs.
// Run (standalone): PRISM_TEST_REVIEW_DIR=<restored fixture> PRISM_TEST_DB_PORT=... PRISM_TEST_APP_PORT=... PRISM_TEST_DB_COLLATION=...
//   PRISM_TEST_PLAYWRIGHT=... PRISM_TEST_EVIDENCE_DIR=<out> PRISM_TEST_CLEANUP_REHEARSAL=restored-copy node maintenance/ierb-7r-tests/cleanup-rehearsal-restored.cjs 1,13,46
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto'), assert = require('node:assert/strict');
if (process.env.PRISM_TEST_CLEANUP_REHEARSAL !== 'restored-copy') { console.error('Restored-copy rehearsal only: it permanently removes accounts from the isolated restore.'); process.exit(2); }
const root = path.resolve(__dirname, '../..'), review = process.env.PRISM_TEST_REVIEW_DIR, app = path.join(review, 'sandbox/app');
const php = process.env.PRISM_TEST_PHP || 'C:/xampp/php/php.exe', base = 'http://127.0.0.1:' + process.env.PRISM_TEST_APP_PORT, playwright = require(process.env.PRISM_TEST_PLAYWRIGHT);
const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR); fs.mkdirSync(evidence, { recursive: true });
const ADMINS = (process.argv[2] || '').split(',').map(Number).filter(Boolean), OPERATOR = 9001, PASSWORD = 'SyntheticPass123!';
const checks = []; const check = (value, label) => { assert.ok(value, label); checks.push(label); console.log(label); };
const json = (file, args, input, env = {}) => { const r = cp.spawnSync(php, [file, ...args], { input, encoding: 'utf8', windowsHide: true, env: { ...process.env, ...env } }); let body = null; try { body = JSON.parse(r.stdout); } catch (_) {} return { exit: r.status, body, raw: r.stdout + r.stderr }; };
const sql = (q, p = []) => { const r = json(path.join(root, 'maintenance/ierb-group-tests/fixture.php'), [], JSON.stringify({ action: 'sql', sql: q, params: p })); if (!Array.isArray(r.body)) throw Error('Fixture SQL failed: ' + r.raw.slice(0, 300)); return r.body; };
const fx = data => { const r = json(path.join(__dirname, 'cleanup-fixture.php'), [], JSON.stringify(data)); if (!r.body?.ok) throw Error(r.raw.slice(0, 300)); return r.body.result; };
const dsn = 'mysql:host=127.0.0.1;port=' + process.env.PRISM_TEST_DB_PORT + ';dbname=prism_group_synthetic_v11';
const tool = (...args) => json(path.join(root, 'maintenance/clean-database-preview.php'), args, '', { PRISM_CLEANUP_DB_DSN: dsn, PRISM_CLEANUP_DB_USER: 'root', PRISM_CLEANUP_DB_PASSWORD: 'prism_synthetic_only' });
const sleep = ms => new Promise(r => setTimeout(r, ms));
function prepareApp() { // Same copy rule as maintenance/ierb-group-tests/run.cjs; never copies config.local.php.
  fs.mkdirSync(app, { recursive: true });
  for (const file of cp.execFileSync('git', ['ls-files', '-z'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean)) {
    if (!file.endsWith('.php') && !file.startsWith('assets/') && !file.startsWith('includes/')) continue;
    const target = path.join(app, file); fs.mkdirSync(path.dirname(target), { recursive: true }); fs.copyFileSync(path.join(root, file), target);
  }
  for (const dir of ['includes', 'assets', 'vendor']) fs.cpSync(path.join(root, dir), path.join(app, dir), { recursive: true });
  for (const folder of ['storage/documents', 'storage/reports', 'sessions', 'uploads']) fs.mkdirSync(path.join(app, folder), { recursive: true });
}
(async () => {
  let server; const log = { admins: ADMINS, operator: OPERATOR };
  const verification = path.join(review, 'sandbox/hard-delete-verification.json');
  try {
    check(ADMINS.length > 0, 'Owner-confirmed Administrator IDs supplied: ' + ADMINS.join(','));
    const schema = json(path.join(root, 'maintenance/ierb-group-tests/fixture.php'), [], '{"action":"schema"}').body;
    check(schema.schemaVersion === '11' && schema.manifest === 'PASS', 'Isolated restore is at schema 11 and matches the reviewed v11 manifest');
    prepareApp();
    server = cp.spawn(php, ['-d', 'extension=zip', '-d', 'auto_prepend_file=' + path.join(root, 'maintenance/ierb-group-tests/runtime.php'), '-d', 'disable_functions=mail,curl_init,curl_setopt_array,curl_exec,curl_getinfo,curl_errno,curl_error,curl_close',
      '-d', 'session.save_path=' + path.join(app, 'sessions'), '-d', 'upload_tmp_dir=' + path.join(app, 'uploads'), '-S', '127.0.0.1:' + process.env.PRISM_TEST_APP_PORT, '-t', app], { windowsHide: true });
    for (let n = 0; n < 60; n++) { try { if ((await fetch(base + '/login.php')).ok) break; } catch (_) {} await sleep(100); }

    // --- Before: the real Administrators' state, the operator, the exact preview.
    const healthBefore = fx({ action: 'admin-health', ids: ADMINS });
    check(ADMINS.every(id => healthBefore[id].exists && healthBefore[id].role === 'admin'), 'Every confirmed ID is an existing Administrator (identity revalidated)');
    fx({ action: 'operator-admin' });
    // The operating Administrator signs in BEFORE the preview: sign-in may transparently upgrade an older password hash,
    // which must happen before the credential fingerprints are taken (the same order applies on staging and production).
    const op = await playwright.request.newContext({ baseURL: base });
    const signin = await op.post('/login_process.php', { form: { email: 'rehearsal.operator@example.test', password: PASSWORD }, headers: { origin: base }, maxRedirects: 0 });
    check(signin.status() === 302, 'The test operator Administrator signs in before the preview');
    const previewFile = path.join(evidence, 'restored-preview-redacted.json');
    const before = tool('--retain-admins=' + [...ADMINS, OPERATOR].join(','), '--redact');
    fs.writeFileSync(previewFile, JSON.stringify(before.body, null, 2));
    const b = before.body;
    check(before.exit === 0 && JSON.stringify(b.admins.map(a => a.id)) === JSON.stringify([...ADMINS, OPERATOR].sort((x, y) => x - y)) && !b.adminProblems.length, 'Preview: the confirmed Administrators plus the test operator, no identity problem');
    log.preview = { summary: b.summary, blockers: b.blockers, expectedFinalCounts: b.expectedFinalCounts, decisionSha256: b.decisionSha256 };
    check(b.summary.studentsBlocked === 0 && b.summary.advisersBlocked === 0, 'Preview: no Student or Adviser is blocked by a lifecycle rule (' + b.summary.students + ' Students, ' + b.summary.advisers + ' Advisers)');
    console.log('Preview blockers: ' + JSON.stringify(b.blockers.map(x => x.kind + ':' + x.reason)));

    // --- Deletion-verification evidence for this exact isolated database (blocker B4 tool).
    const key = crypto.randomBytes(32).toString('hex');
    const issued = json(path.join(root, 'maintenance/issue-deletion-verification.php'), ['--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded'], '',
      { PRISM_MIGRATION_DB_DSN: dsn + ';charset=utf8mb4', PRISM_MIGRATION_DB_USER: 'root', PRISM_MIGRATION_DB_PASSWORD: 'prism_synthetic_only', PRISM_SCHEMA_VERIFY_KEY: key });
    check(issued.exit === 0 && issued.body.selfCheck === 'PASS' && issued.body.schemaVersion === '11', 'Issuer produces self-checked evidence for the restored database at schema 11');
    fs.writeFileSync(verification, JSON.stringify({ key, evidence: issued.body.evidence }));

    // --- Execute the plan as the test operator, through the HTTP application operations only.
    const gen = async () => (await op.storageState()).cookies.find(c => c.name === 'prism_generation')?.value || '';
    const send = async (url, data) => { const r = await op.post(url, { data, headers: { origin: base, 'X-Prism-Generation': await gen() } }); return { status: r.status(), body: await r.json().catch(() => ({})) }; };
    const results = { cancelledAdviserDeadlines: [], deletedDocuments: [], archived: [], purged: [], refused: [], deletedReports: [] };
    for (const a of b.advisers) for (const id of (a.preSteps.find(p => p.deadlineIds)?.deadlineIds || [])) {
      const r = await send('/calendar_deadlines_api.php?action=cancel', { id }); (r.status === 200 ? results.cancelledAdviserDeadlines : results.refused).push({ deadline: id, status: r.status, message: r.body.message });
    }
    // The lifecycle's "unresolved workflow": a current version that is unsubmitted OR was returned for revision. A returned
    // version is formally submitted, so its Admin deletion needs a reason; the reason is sent for every pre-step deletion.
    const unresolved = "SELECT d.id FROM documents d WHERE d.student_id IS NULL OR (d.is_current=1 AND (d.rpms_submitted_at IS NULL OR EXISTS (SELECT 1 FROM activity_logs a WHERE a.entity_type='document' AND a.entity_id=d.id AND a.action='document_returned_for_revision'))) ORDER BY d.id";
    check(JSON.stringify(sql(unresolved).map(r => r.id).sort()) === JSON.stringify(b.students.flatMap(st => st.preSteps.flatMap(p => p.documentIds || [])).concat(b.unlinkedDocuments).sort()),
      'The preview listed exactly the document versions the lifecycle treats as unresolved');
    for (let guard = 0; guard < 20; guard++) {
      const pending = sql(unresolved);
      if (!pending.length) break;
      for (const { id } of pending) { const r = await send('/documents_api.php?action=delete', { id, reason: 'Owner-confirmed test data cleanup (D12 revised)' }); (r.status === 200 ? results.deletedDocuments : results.refused).push({ document: id, status: r.status, message: r.body.message }); }
    }
    check(!sql(unresolved).length, 'Pre-step: no unresolved or unlinked current document remains (' + results.deletedDocuments.length + ' versions deleted)');
    const purge = (type, id, confirmation) => send('/account_lifecycle_api.php', { accountType: type, targetId: id, action: 'grace_period_override', currentPassword: PASSWORD, confirmation, confirmed: true, reason: 'Owner-confirmed test data cleanup rehearsal on an isolated restore (D12 revised)' });
    for (const st of sql('SELECT id,archived_at FROM students ORDER BY id')) if (!st.archived_at) { const r = await send('/students_api.php?action=delete', { id: st.id }); (r.status === 200 ? results.archived : results.refused).push({ student: st.id, status: r.status, message: r.body.message }); }
    for (const st of sql('SELECT id,student_id,email FROM students ORDER BY id')) {
      const r = await purge('student', st.id, String(st.student_id || '').trim() !== '' ? st.student_id : st.email);
      (r.status === 200 && r.body.complete ? results.purged : results.refused).push({ student: st.id, status: r.status, message: r.body.message });
    }
    for (const a of sql('SELECT id,archived_at FROM advisers ORDER BY id')) if (!a.archived_at) { const r = await send('/advisers_api.php?action=delete', { id: a.id, expectedAssignedStudents: 0 }); (r.status === 200 ? results.archived : results.refused).push({ adviser: a.id, status: r.status, message: r.body.message }); }
    for (const a of sql('SELECT id,employee_id,email FROM advisers ORDER BY id')) {
      const r = await purge('adviser', a.id, String(a.employee_id || '').trim() !== '' ? a.employee_id : a.email);
      (r.status === 200 && r.body.complete ? results.purged : results.refused).push({ adviser: a.id, status: r.status, message: r.body.message });
    }
    log.results = results;
    check(results.refused.length === 0, 'Every planned operation succeeded; refusals: ' + JSON.stringify(results.refused).slice(0, 600));
    check(results.purged.length === b.summary.students + b.summary.advisers, 'Purged ' + results.purged.length + ' accounts (' + b.summary.students + ' Students + ' + b.summary.advisers + ' Advisers) through the lifecycle');

    // --- Cleanup window ends with the 28 institution-wide reports still present (their deletion is separately authorized).
    const kept = tool('--retain-admins=' + [...ADMINS, OPERATOR].join(','), '--mode=verify-final', '--institution-reports=kept', '--expect-from=' + previewFile);
    fs.writeFileSync(path.join(evidence, 'restored-verify-final-reports-kept.json'), JSON.stringify(kept.body, null, 2));
    check(kept.exit === 0 && kept.body.status === 'PASS' && kept.body.tableCounts.reports === b.institutionReports.length,
      'verify-final PASSES with the ' + b.institutionReports.length + ' institution-wide reports kept: Administrators and Admin-owned records unchanged; zero Student, Adviser and document records; the Student-owned report went with its Student: ' + JSON.stringify(kept.body?.failed));
    const acct = kept.body.auditAccounting;
    check(acct.preexistingRowsRemoved <= acct.predictedUpperBound && kept.body.checks.audit_rows_outside_prediction_preserved,
      'Audit accounting: ' + acct.preexistingRowsRemoved + ' pre-existing audit rows removed, all within the ' + acct.predictedUpperBound + ' predicted; every other pre-existing audit row is preserved');
    // The prediction is an upper bound: rows about a document deleted in the pre-step, with no Student link, survive the purge.
    const survivors = sql('SELECT id,entity_type,entity_id,student_id FROM activity_logs WHERE id IN (' + (b.auditImpact.adminActorRemovalIds.join(',') || '0') + ')');
    const preStepDocs = results.deletedDocuments.map(d => d.document);
    check(survivors.every(r => r.entity_type === 'document' && preStepDocs.includes(r.entity_id) && r.student_id === null),
      'The only predicted Admin-action rows that survive concern documents deleted in the pre-step (' + survivors.length + ' rows)');
    const adminGone = b.auditImpact.adminActorRemovalIds.length - survivors.length;
    const created = sql('SELECT COUNT(*) n, COALESCE(MAX(id),0) mx FROM activity_logs WHERE id>?', [b.auditImpact.maxAuditId])[0];
    log.audit = { predictedRemoval: acct.predictedUpperBound, preexistingRemoved: acct.preexistingRowsRemoved, predictedAdminActor: b.auditImpact.adminActorRemovalIds.length, adminActorRemoved: adminGone,
      writtenDuringCleanupKept: created.n, writtenDuringCleanupRemoved: created.mx - b.auditImpact.maxAuditId - created.n };
    log.audit.adminActorSurvivors = survivors.map(r => r.id);
    check(adminGone > 0 && adminGone <= b.auditImpact.adminActorRemovalIds.length, adminGone + ' of the ' + b.auditImpact.adminActorRemovalIds.length + ' predicted Admin-action audit rows were removed by the purge (not yet approved for production)');
    // --- The separately authorized step, rehearsed: delete the institution-wide reports, then verify again.
    for (const { id } of sql('SELECT id FROM reports ORDER BY id')) { const r = await op.post('/reports_api.php?action=delete&id=' + encodeURIComponent(id), { data: {}, headers: { origin: base, 'X-Prism-Generation': await gen() } });
      (r.status() === 200 ? results.deletedReports : results.refused).push({ report: id, status: r.status() }); }
    check(results.deletedReports.length === b.institutionReports.length && !results.refused.length, 'Separately authorized step: the ' + results.deletedReports.length + ' institution-wide reports are deleted through Reports → Delete');
    const final = tool('--retain-admins=' + [...ADMINS, OPERATOR].join(','), '--mode=verify-final', '--institution-reports=deleted', '--expect-from=' + previewFile);
    fs.writeFileSync(path.join(evidence, 'restored-verify-final.json'), JSON.stringify(final.body, null, 2));
    check(final.exit === 0 && final.body.status === 'PASS' && final.body.tableCounts.reports === 0, 'verify-final PASSES after report deletion: zero reports; report-deletion audits kept: ' + JSON.stringify(final.body?.failed));
    for (const page of ['dashboard.php', 'admin_students.php', 'admin_advisers.php', 'documents.php', 'reports.php', 'calendar.php', 'ierbprog.php', 'admin_notifications.php', 'account.php']) {
      const r = await op.get('/' + page, { maxRedirects: 0 }); if (r.status() !== 200) throw Error('Administrator could not open ' + page + ': ' + r.status());
    }
    check(true, 'An Administrator session opens all 9 main pages on the cleaned restore');
    const selfDelete = await send('/account_lifecycle_api.php', { accountType: 'admin', targetId: OPERATOR, action: 'permanent_delete', currentPassword: PASSWORD, confirmation: 'DELETE', confirmed: true });
    check(selfDelete.status === 200, 'The test operator deletes its own Administrator account (other Administrators remain): ' + JSON.stringify(selfDelete.body).slice(0, 160));
    await op.dispose();

    // --- After: exactly the confirmed Administrators, unchanged and functional.
    const after = tool('--retain-admins=' + ADMINS.join(','), '--redact');
    fs.writeFileSync(path.join(evidence, 'restored-after-redacted.json'), JSON.stringify(after.body, null, 2));
    const fpBefore = Object.fromEntries(ADMINS.map(id => [id, b.preserved.adminFingerprints[String(id)]]));
    check(after.exit === 0 && JSON.stringify(after.body.admins.map(a => a.id)) === JSON.stringify(ADMINS) && !after.body.adminProblems.length
      && ADMINS.every(id => after.body.preserved.adminFingerprints[String(id)] === fpBefore[id]), 'Exactly the confirmed Administrators remain, each with an unchanged credential fingerprint');
    const c = after.body.currentCounts;
    check(c.users === ADMINS.length && c.students === 0 && c.advisers === 0 && c.documents === 0 && c.reports === 0 && c.ierb_history === 0 && c.calendar_deadline_recipients === 0 && !after.body.blockers.length,
      'Final dataset: ' + ADMINS.length + ' users (all Administrators), 0 Students, 0 Advisers, 0 documents, 0 reports, 0 history, 0 deadline recipients');
    check(ADMINS.every(id => JSON.stringify(after.body.preserved.adminRecords[String(id)]) === JSON.stringify(b.preserved.adminRecords[String(id)]))
      && ['legalPolicies', 'staffRegistrationCode', 'stageLabels', 'schemaMeta'].every(k => after.body.preserved[k] === b.preserved[k]),
      'Each Administrator\'s deadlines, deadline groups, notices, campaigns, policy acceptances and password-reset rows, and the institutional configuration, are byte-identical (hashes)');
    const keptAdviserNotices = b.advisers.reduce((t, a) => t + a.retainedAfterPurge.notificationsKeptAsHistoricalAdviser, 0);
    check(sql("SELECT COUNT(*) n FROM notifications WHERE recipient_type='historical_adviser'")[0].n === keptAdviserNotices, 'The preview predicted exactly the ' + keptAdviserNotices + ' Adviser notices kept as history');
    check(JSON.stringify(after.body.orphanStudentNotifications) === JSON.stringify(b.orphanStudentNotifications),'Only the ' + b.orphanStudentNotifications.length + ' orphaned legacy Student notices identified in the preview remain (no account owns them)');
    const healthAfter = fx({ action: 'admin-health', ids: ADMINS });
    check(JSON.stringify(healthAfter) === JSON.stringify(healthBefore) && ADMINS.every(id => healthAfter[id].active && healthAfter[id].adminEligible && !healthAfter[id].legalOutstanding && !healthAfter[id].mustChangePassword),
      'Every Administrator is still Active, Admin-eligible, has no outstanding legal policy and needs no password change');
    const schemaAfter = json(path.join(root, 'maintenance/ierb-group-tests/fixture.php'), [], '{"action":"schema"}').body;
    check(schemaAfter.schemaVersion === '11' && schemaAfter.manifest === 'PASS', 'The schema still matches the reviewed v11 manifest');
    log.retainedHistory = final.body.retainedHistory; log.finalCounts = c; log.adminHealth = healthAfter;
    console.log(JSON.stringify({ status: 'PASS', assertions: checks.length }));
  } catch (e) { console.error(e); process.exitCode = 1; log.failure = String(e).slice(0, 2000); }
  finally {
    try { fs.unlinkSync(verification); } catch (_) {}
    if (server) server.kill();
    log.checks = checks;
    fs.writeFileSync(path.join(evidence, 'restored-rehearsal-log.json'), JSON.stringify(log, null, 2));
  }
})();
