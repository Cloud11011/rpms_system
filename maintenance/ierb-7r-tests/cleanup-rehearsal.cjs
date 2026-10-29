// Clean-database rehearsal for decision D12 (revised): preserve EVERY RPMS Administrator; remove every test Student and
// Research Adviser with their test research records. DEDICATED disposable fixture only: it permanently removes the seeded
// Student and Adviser accounts through the real HTTP application operations (no SQL deletes, no foreign-key changes).
// Deletion-verification evidence is issued with maintenance/issue-deletion-verification.php (cleanup blocker B4), whose
// refusals and cron-runner step are tested here on the isolated fixture only.
// Run: PRISM_TEST_PHASE=documents PRISM_TEST_CLEANUP_REHEARSAL=dedicated-fixture node maintenance/ierb-group-tests/run.cjs
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
const PASSWORD = 'SyntheticPass123!', ADMINS = [1, 91, 92];
function pdf(label) {
  const text = ('Synthetic Cleanup ' + label + ' document for fictional study participants. ').repeat(6), stream = `BT /F1 10 Tf 30 750 Td (${text}) Tj ET`;
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
  let data = '%PDF-1.4\n'; const offsets = []; objects.forEach((o, i) => { offsets.push(Buffer.byteLength(data)); data += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const start = Buffer.byteLength(data); return Buffer.from(data + 'xref\n0 6\n0000000000 65535 f \n' + offsets.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
}
module.exports = async h => {
  const { sql, check, post, response, expect, generation, sessions: s, base, root, php, review, login } = h;
  if (process.env.PRISM_TEST_CLEANUP_REHEARSAL !== 'dedicated-fixture') throw Error('The cleanup rehearsal permanently removes fixture accounts; run it only on a dedicated fixture.');
  const start = h.checks.length;
  const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR || path.join(root, 'PRISM_V14_7R_COMPLIANCE_CLOSURE/evidence/cleanup'));
  fs.mkdirSync(evidence, { recursive: true });
  const fx = data => { const r = JSON.parse(cp.execFileSync(php, [path.join(__dirname, 'cleanup-fixture.php')], { input: JSON.stringify(data), encoding: 'utf8', windowsHide: true })); if (!r.ok) throw Error(JSON.stringify(r)); return r.result; };
  const dbEnv = { PRISM_MIGRATION_DB_DSN: 'mysql:host=127.0.0.1;port=' + (process.env.PRISM_TEST_DB_PORT || '13527') + ';dbname=prism_group_synthetic_v11;charset=utf8mb4', PRISM_MIGRATION_DB_USER: 'root', PRISM_MIGRATION_DB_PASSWORD: 'prism_synthetic_only' };
  const run = (script, args, env = {}) => { const r = cp.spawnSync(php, [path.join(root, 'maintenance', script), ...args], { encoding: 'utf8', windowsHide: true, env: { ...process.env, ...env } });
    let out = null; try { out = JSON.parse(r.stdout); } catch (_) {} let err = null; try { err = JSON.parse(r.stderr); } catch (_) {} return { exit: r.status, body: out, err, raw: r.stdout + r.stderr }; };
  const previewFile = path.join(evidence, 'rehearsal-preview.json');
  const tool = (...args) => run('clean-database-preview.php', ['--retain-admins=' + ADMINS.join(','), ...args],
    { PRISM_CLEANUP_DB_DSN: dbEnv.PRISM_MIGRATION_DB_DSN.replace(';charset=utf8mb4', ''), PRISM_CLEANUP_DB_USER: 'root', PRISM_CLEANUP_DB_PASSWORD: 'prism_synthetic_only' });
  const issue = (key, ...args) => run('issue-deletion-verification.php', args, { ...dbEnv, PRISM_SCHEMA_VERIFY_KEY: key });
  const installEvidence = (key, evidenceJson) => fs.writeFileSync(path.join(review, 'sandbox/hard-delete-verification.json'), JSON.stringify({ key, evidence: evidenceJson }));
  const send = async (ctx, url, data) => response(await ctx.post(url, { data, headers: { origin: base, 'X-Prism-Generation': await generation(ctx) } }));
  const purge = (type, id, confirmation) => send(s.admin, '/account_lifecycle_api.php', { accountType: type, targetId: id, action: 'grace_period_override', currentPassword: PASSWORD, confirmation, confirmed: true,
    reason: 'Owner-confirmed test data cleanup rehearsal (D12 revised)' });
  const upload = async (ctx, type, label) => response(await ctx.post('/documents_api.php?action=upload', { headers: { origin: base, 'X-Prism-Generation': await generation(ctx) },
    multipart: { documentType: type, requestId: crypto.randomUUID(), document: { name: `Synthetic Cleanup ${label}.pdf`, mimeType: 'application/pdf', buffer: pdf(label) } } }));
  const future = new Date(Date.now() + 8 * 3600e3 + 20 * 86400e3).toISOString().slice(0, 10);
  const log = { steps: [] }; const step = (name, detail) => log.steps.push({ name, ...detail });
  const keyA = crypto.randomBytes(32).toString('hex'), keyB = crypto.randomBytes(32).toString('hex');
  try {
    fx({ action: 'revoke-verification' });
    // --- 1. Test records created through the application; three Administrators, two of them authoring deadlines.
    await expect(upload(s.student, 'Study Protocol', 'pending-protocol'), 200, 'Test Student uploads a document that stays pending Adviser review');
    if (!sql("SELECT id FROM documents WHERE student_id=5 AND document_type='Curriculum Vitae' AND rpms_submitted_at IS NOT NULL").length) {
      const s5 = await login('student5@example.test');
      const cv = await expect(upload(s5, 'Curriculum Vitae', 'approved-cv'), 200, 'Second test Student uploads a document');
      const assigned = await login(sql('SELECT a.email FROM advisers a JOIN students st ON st.adviser_id=a.id WHERE st.id=5')[0].email);
      await expect(post(assigned, 'review', { id: cv.body.document.id, status: 'Approved', remarks: 'Synthetic approval' }, 'documents_api.php'), 200, 'The assigned Adviser approval formally submits it to RPMS');
    }
    await expect(send(s.admin, '/calendar_deadlines_api.php?action=create', { title: 'Synthetic Admin 1 deadline', description: '', date: future, target: 'all', groups: [] }), 200, 'Admin 1 creates an official deadline for all groups');
    fx({ action: 'extra-admins' });
    const a91 = await login('admin91@example.test'), a92 = await login('admin92@example.test');
    await expect(send(a92, '/calendar_deadlines_api.php?action=create', { title: 'Synthetic Admin 92 deadline', description: '', date: future, target: 'all', groups: [] }), 200, 'Admin 92 creates an official deadline');
    const adviserDeadline = await expect(send(s.adviser, '/calendar_deadlines_api.php?action=create', { title: 'Synthetic Adviser 2 deadline', description: '', date: future, target: 'all', groups: [] }), 200, 'Test Adviser 2 creates a future official deadline for its groups');
    check(sql('SELECT COUNT(*) n FROM calendar_deadline_recipients r JOIN calendar_deadlines d ON d.id=r.deadline_id WHERE d.creator_user_id IN (1,92)')[0].n >= 1, 'Admin-authored deadlines reference test Students through recipient links');
    const adminDeadlinesBefore = sql('SELECT id,status,deadline_date,title FROM calendar_deadlines WHERE creator_user_id IN (1,91,92) ORDER BY id');

    // --- 2. Exact preview (read-only).
    const before = tool();
    fs.writeFileSync(previewFile, JSON.stringify(before.body, null, 2));
    const b = before.body;
    check(before.exit === 0 && b.readOnly && JSON.stringify(b.admins.map(a => a.id)) === '[1,91,92]' && !b.adminProblems.length, 'Preview: exactly the three owner-confirmed Administrators, no identity problem');
    check(b.summary.studentsBlocked === 0 && b.summary.advisersBlocked === 0 && b.blockers.length === 0, 'Preview: no Student, Adviser or other blocker: ' + JSON.stringify(b.blockers));
    const adviserPre = b.advisers.find(a => a.id === 2).preSteps.find(p => p.deadlineIds);
    check(adviserPre && adviserPre.deadlineIds.length === 1 && b.summary.adminDeadlinesPreserved === adminDeadlinesBefore.length, 'Preview: only the Adviser-authored future deadline must be cancelled; Admin deadlines are preserved');
    check(b.students.filter(x => x.preSteps.some(p => p.documentIds)).map(x => x.id).join(',') === '4' && b.summary.adminDeadlineRecipientLinksRemovedByPurge >= 1, 'Preview: one document pre-step (Student 4) and the Admin deadline links to test Students are identified');
    check(tool('--mode=verify-final', '--expect-from=' + previewFile).exit === 3, 'verify-final FAILS on the uncleaned database');

    // --- 3. Deletion-verification evidence (B4): the tool's refusals, then issuance, then the lifecycle gate.
    check(issue(keyA, '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting').exit === 1, 'Issuer refuses shared-hosting evidence without the external-dependency attestation');
    check(issue(keyA, '--expect-db=other_database', '--mode=shared-hosting', '--external-dependencies-excluded').exit === 1, 'Issuer refuses a database other than --expect-db');
    check(issue('not-a-key', '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded').exit === 1, 'Issuer refuses a malformed key');
    check(issue(keyA, '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded', '--out=' + path.join(root, 'evidence-inside-app.json')).exit === 1
      && !fs.existsSync(path.join(root, 'evidence-inside-app.json')), 'Issuer refuses to write evidence inside the application directory');
    // SEC-01: evidence goes only to a new private file; it is never printed (no --out, no issuance).
    const noOut = issue(keyA, '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded');
    check(noOut.exit === 1 && noOut.body === null && /--out/.test(noOut.err?.message || ''), 'Issuer refuses to run without a private --out file, so evidence is never printed');
    await expect(purge('student', 5, 'S5'), 409, 'An active Student cannot be purged before Archive');
    await expect(post(s.admin, 'delete', { id: 5 }, 'students_api.php'), 200, 'Archive Student 5');
    const gate = await purge('student', 5, 'S5');
    check(gate.status === 409 && /deployment verification/i.test(gate.body.message), 'Without evidence, permanent deletion is refused: ' + gate.body.message);
    const priv = fs.mkdtempSync(path.join(review, 'sandbox', 'private-')), receipts = path.join(priv, 'receipts'); fs.mkdirSync(receipts);
    const evidenceFile = path.join(priv, 'issued-evidence.json');
    const issued = issue(keyA, '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded', '--out=' + evidenceFile);
    const issuedEvidence = JSON.parse(fs.readFileSync(evidenceFile, 'utf8'));
    const leaves = (v, out = []) => { if (v && typeof v === 'object') Object.values(v).forEach(x => leaves(x, out)); else if (typeof v === 'string' || typeof v === 'number') out.push(String(v)); return out; };
    const secretLeaves = leaves(issuedEvidence).filter(v => v.length >= 12 && v !== 'prism_group_synthetic_v11' && v !== 'schema_scoped_shared_hosting');
    check(issued.exit === 0 && issued.body.selfCheck === 'PASS' && issued.body.evidenceFile === 'issued-evidence.json' && !('evidence' in issued.body) && issuedEvidence.verification_mode === 'schema_scoped_shared_hosting'
      && !issued.raw.includes(keyA) && secretLeaves.length > 0 && secretLeaves.every(v => !issued.raw.includes(v)),
      'Issuer writes self-checked shared-hosting evidence only to the private file; stdout has neither the key nor any evidence value');
    const reissue = issue(keyA, '--expect-db=prism_group_synthetic_v11', '--mode=shared-hosting', '--external-dependencies-excluded', '--out=' + evidenceFile);
    check(reissue.exit === 1 && JSON.stringify(JSON.parse(fs.readFileSync(evidenceFile, 'utf8'))) === JSON.stringify(issuedEvidence), 'Issuer never overwrites an existing evidence file');
    installEvidence(keyB, issuedEvidence);
    check((await purge('student', 5, 'S5')).status === 409, 'Evidence issued with a different key is refused by the application');
    installEvidence(keyA, { ...issuedEvidence, issued_at: issuedEvidence.issued_at + 1 });
    check((await purge('student', 5, 'S5')).status === 409, 'Tampered evidence is refused by the application');
    // The no-SSH cron route: private config outside the application, evidence in the private receipt directory only.
    const cronConfig = { dsn: dbEnv.PRISM_MIGRATION_DB_DSN, user: 'root', password: 'prism_synthetic_only', database: 'prism_group_synthetic_v11', receiptDir: receipts,
      deletionVerificationKey: keyA, externalDependenciesExcluded: true };
    fs.writeFileSync(path.join(priv, 'config.json'), JSON.stringify(cronConfig));
    const cron = run('hostinger-cron-runner.php', [path.join(priv, 'config.json'), 'deletion-verification', 'x']);
    const evidenceFiles = fs.readdirSync(receipts).filter(f => f.startsWith('prism-deletion-verification-evidence-'));
    const receiptText = fs.readdirSync(receipts).filter(f => f.startsWith('prism-deletion-verification-x-')).map(f => fs.readFileSync(path.join(receipts, f), 'utf8')).join('');
    const cronEvidence = evidenceFiles.length === 1 ? JSON.parse(fs.readFileSync(path.join(receipts, evidenceFiles[0]), 'utf8')) : {};
    check(cron.exit === 0 && evidenceFiles.length === 1 && /^prism-deletion-verification-evidence-\d{8}-\d{6}-[0-9a-f]{6}\.json$/.test(evidenceFiles[0]) && receiptText.length > 0 && !receiptText.includes(keyA) && !receiptText.includes('prism_synthetic_only') && !receiptText.includes('127.0.0.1') && !/"host"/.test(receiptText)
      && leaves(cronEvidence).filter(v => v.length >= 12 && v !== 'prism_group_synthetic_v11' && v !== 'schema_scoped_shared_hosting').every(v => !receiptText.includes(v) && !cron.raw.includes(v)),
      'Cron step issues evidence into the private receipt directory only; neither the receipt nor cron output has the key, password, DSN, host or any evidence value');
    // SEC-02: the probe uses the shared connection policy and is bound to the configured database, as is preflight.
    const cronProbe = run('hostinger-cron-runner.php', [path.join(priv, 'config.json'), 'probe', 'x']);
    check(cronProbe.exit === 0 && cronProbe.body.connected === true && cronProbe.body.databaseMatches === true && cronProbe.body.tls?.caVerification === false && cronProbe.body.schemaVersion === sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v,
      'Cron probe connects through the shared connection policy and confirms the configured database');
    fs.writeFileSync(path.join(priv, 'wrong-db.json'), JSON.stringify({ ...cronConfig, database: 'prism_other_database' }));
    const wrongProbe = run('hostinger-cron-runner.php', [path.join(priv, 'wrong-db.json'), 'probe', 'x']);
    check(wrongProbe.exit === 1 && wrongProbe.body.errorClass === 'database_mismatch' && !('schemaVersion' in wrongProbe.body), 'Cron probe refuses a connection to a database other than the configured one');
    const wrongPreflight = run('hostinger-cron-runner.php', [path.join(priv, 'wrong-db.json'), 'preflight', 'x']);
    check(wrongPreflight.exit === 1 && wrongPreflight.body.stdout?.database?.errorClass === 'database_mismatch', 'Cron preflight refuses a database other than the configured one');
    installEvidence(keyA, cronEvidence);
    step('verification', { note: 'Evidence issued by maintenance/issue-deletion-verification.php through the cron step; key and evidence stay in the disposable sandbox.' });

    // --- 4. Execute the plan in the documented order.
    for (const id of adviserPre.deadlineIds) await expect(send(s.admin, '/calendar_deadlines_api.php?action=cancel', { id }), 200, 'Cancel the Adviser-authored future deadline ' + id);
    for (let guard = 0; guard < 10; guard++) {
      const pending = sql('SELECT id FROM documents WHERE is_current=1 AND rpms_submitted_at IS NULL ORDER BY id');
      if (!pending.length) break;
      for (const { id } of pending) await expect(post(s.admin, 'delete', { id }, 'documents_api.php'), 200, 'Delete unsubmitted current document ' + id + ' (pre-step)');
    }
    const students = sql('SELECT id,student_id,email,archived_at FROM students ORDER BY id');
    for (const st of students) if (!st.archived_at) await expect(post(s.admin, 'delete', { id: st.id }, 'students_api.php'), 200, 'Archive Student ' + st.id);
    for (const st of students) {
      const r = await purge('student', st.id, st.student_id || st.email);
      check(r.status === 200 && r.body.complete === true, 'Permanently delete Student ' + st.id + ' through the lifecycle: ' + JSON.stringify(r.body).slice(0, 160));
    }
    const advisers = sql('SELECT id,employee_id,email FROM advisers ORDER BY id');
    for (const a of advisers) await expect(post(s.admin, 'delete', { id: a.id, expectedAssignedStudents: 0 }, 'advisers_api.php'), 200, 'Archive Adviser ' + a.id + ' (no assigned Students remain)');
    for (const a of advisers) {
      const r = await purge('adviser', a.id, a.employee_id || a.email);
      check(r.status === 200 && r.body.complete === true, 'Permanently delete Adviser ' + a.id + ' through the lifecycle: ' + JSON.stringify(r.body).slice(0, 160));
    }
    for (const { id } of sql('SELECT id FROM reports ORDER BY id')) await expect(response(await s.admin.post('/reports_api.php?action=delete&id=' + encodeURIComponent(id), { data: {}, headers: { origin: base, 'X-Prism-Generation': await generation(s.admin) } })), 200, 'Delete institution-wide report ' + id);

    // --- 5. Final dataset: every Administrator and every Admin-owned record unchanged; zero test accounts and records.
    const final = tool('--mode=verify-final', '--expect-from=' + previewFile);
    fs.writeFileSync(path.join(evidence, 'rehearsal-verify-final.json'), JSON.stringify(final.body, null, 2));
    check(final.exit === 0 && final.body.status === 'PASS', 'verify-final PASSES: the 3 Administrators and their deadlines, notices, campaigns, acceptances and configuration are unchanged; zero Student, Adviser, document, report and test-owned records: ' + JSON.stringify(final.body.failed));
    check(JSON.stringify(sql('SELECT id,status,deadline_date,title FROM calendar_deadlines WHERE creator_user_id IN (1,91,92) ORDER BY id')) === JSON.stringify(adminDeadlinesBefore)
      && adminDeadlinesBefore.every(d => d.status === 'Active'), 'Admin-authored deadlines are still Active and unchanged (only their links to deleted test Students are gone)');
    check(sql("SELECT status FROM calendar_deadlines WHERE id=?", [adviserPre.deadlineIds[0]])[0]?.status === 'Cancelled' && sql('SELECT creator_user_id FROM calendar_deadlines WHERE id=?', [adviserPre.deadlineIds[0]])[0].creator_user_id === null,
      'The test Adviser deadline remains only as Cancelled history with its creator link removed (no delete operation exists)');
    check(sql("SELECT COUNT(*) n FROM activity_logs WHERE action IN ('student_grace_period_override_purge','adviser_grace_period_override_purge')")[0].n === students.length + advisers.length, 'Every purge left its retained audit row');

    // --- 6. Every Administrator still signs in and operates PRISM.
    for (const [email, id] of [['admin@example.test', 1], ['admin91@example.test', 91], ['admin92@example.test', 92]]) {
      const admin = await login(email);
      for (const page of ['dashboard.php', 'admin_students.php', 'admin_advisers.php', 'documents.php', 'reports.php', 'calendar.php', 'ierbprog.php', 'admin_notifications.php', 'account.php']) {
        const r = await admin.get('/' + page, { maxRedirects: 0 });
        if (r.status() !== 200) throw Error(`Admin ${id} could not open ${page}: HTTP ${r.status()}`);
      }
      check(true, `Admin ${id} signs in and opens all 9 main pages`);
      const lists = await Promise.all(['students_api.php?action=list', 'advisers_api.php?action=list', 'documents_api.php?action=list', 'reports_api.php?action=list'].map(async u => response(await admin.get('/' + u))));
      check(lists.every(l => l.status === 200) && lists[0].body.students.length === 0 && lists[1].body.advisers.length === 0 && lists[2].body.documents.length === 0 && lists[3].body.reports.length === 0, `Admin ${id} sees empty Student, Adviser, document and report lists`);
    }
    const a1 = await login('admin@example.test');
    await expect(send(a1, '/account_invitation_api.php?action=invite', { accountType: 'adviser', email: 'new.adviser@example.test' }), 200, 'Admin 1 invites a new Adviser');
    await expect(send(await login('admin91@example.test'), '/account_invitation_api.php?action=invite', { accountType: 'student', email: 'new.student@example.test' }), 200, 'Admin 91 invites a new Student (Unassigned until an Adviser completes onboarding)');
    const cal = await response(await a92.get('/calendar_deadlines_api.php?action=list&from=' + future + '&to=' + future + '&sort=upcoming'));
    check(cal.status === 200 && JSON.stringify(cal.body).includes('Synthetic Admin 92 deadline'), 'Admin 92 still sees its own Active deadline');
    const pre = JSON.parse(cp.spawnSync(php, [path.join(root, 'maintenance/runtime-preflight.php'), '--database'], { encoding: 'utf8', windowsHide: true,
      env: { ...process.env, PRISM_PREFLIGHT_DB_DSN: dbEnv.PRISM_MIGRATION_DB_DSN, PRISM_PREFLIGHT_DB_USER: 'root', PRISM_PREFLIGHT_DB_PASSWORD: 'prism_synthetic_only' } }).stdout || '{}');
    check(pre.database?.ok === true && pre.database.contract === 'PASS', 'Runtime preflight: the schema contract still passes after cleanup');
    log.final = final.body; log.preflight = pre.database; log.adviserDeadline = adviserDeadline.body?.id ?? null;
  } finally {
    fx({ action: 'revoke-verification' });
    for (const d of fs.readdirSync(path.join(review, 'sandbox')).filter(n => n.startsWith('private-'))) fs.rmSync(path.join(review, 'sandbox', d), { recursive: true, force: true });
    fs.writeFileSync(path.join(evidence, 'rehearsal-log.json'), JSON.stringify(log, null, 2));
  }
  check(h.checks.length - start > 40, 'Cleanup rehearsal executed ' + (h.checks.length - start) + ' assertions');
};
