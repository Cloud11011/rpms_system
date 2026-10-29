// Batch 7R compliance closure (F01): a verified non-PI member formally submits an Adviser-approved Form 33-0B
// Final Report over HTTP, which completes IERB for every member exactly once. Prerequisite, isolation, duplicate
// and security refusals are checked first. Synthetic records 60-63 only; removed in finally. Run at schema 15 (PRISM_TEST_CLOSURE=1).
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
function pdf(label) {
  const text = ('Synthetic Closure ' + label + ' document for fictional study participants. ').repeat(6), stream = `BT /F1 10 Tf 30 750 Td (${text}) Tj ET`;
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
  let data = '%PDF-1.4\n'; const offsets = []; objects.forEach((o, i) => { offsets.push(Buffer.byteLength(data)); data += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const start = Buffer.byteLength(data); return Buffer.from(data + 'xref\n0 6\n0000000000 65535 f \n' + offsets.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
}
module.exports = async h => {
  const { sql, check, post, response, expect, generation, sessions: s, base, root, php, review, login } = h;
  const start = h.checks.length;
  const helper = path.join(__dirname, 'closure-fixture.php');
  const fx = data => { const r = JSON.parse(cp.execFileSync(php, [helper], { input: JSON.stringify(data), encoding: 'utf8', windowsHide: true })); if (!r.ok) throw Error(JSON.stringify(r)); return r.result; };
  const flag = path.join(review, 'sandbox/v14-operations.flag');
  const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR || path.join(root, 'PRISM_V14_7R_COMPLIANCE_CLOSURE/evidence'));
  fs.mkdirSync(evidence, { recursive: true });
  const upload = async (ctx, type, label, revisionOf = '') => response(await ctx.post('/documents_api.php?action=upload', { headers: { origin: base, 'X-Prism-Generation': await generation(ctx) },
    multipart: { documentType: type, revisionOf, requestId: crypto.randomUUID(), document: { name: `Synthetic Closure ${label}.pdf`, mimeType: 'application/pdf', buffer: pdf(label) } } }));
  const key = () => 'cc-' + crypto.randomUUID();
  const state = () => fx({ action: 'state' });
  const rev = () => Number(state().workflow.revision);
  const doc = id => sql('SELECT * FROM documents WHERE id=?', [id])[0];
  const approve = id => post(s.adviser, 'review', { id, status: 'Approved', remarks: 'Synthetic closure Adviser approval' }, 'documents_api.php');
  const accept = (requirementKey, documentId, extra = {}) => post(s.admin, 'accept_requirement', { studentId: 60, requirementKey, documentId, revision: rev(), reason: 'Synthetic RPMS closure acceptance', requestKey: key(), ...extra }, 'documents_api.php');
  const submit = (ctx, id, versionNo, headers) => headers
    ? ctx.post('/documents_api.php?action=submit_to_rpms', { data: { id, versionNo }, headers }).then(response)
    : post(ctx, 'submit_to_rpms', versionNo === undefined ? { id } : { id, versionNo }, 'documents_api.php');
  // Everything a premature or duplicate submission must never change.
  const completion = () => {
    const st = state();
    return { stage: st.workflow.stage_key, revision: Number(st.workflow.revision), completedAt: st.project.completed_at, completionDocument: st.project.completion_document_id,
      members: st.members.map(m => m.stage_key).join(','), finalEvents: Number(sql("SELECT COUNT(*) n FROM ierb_workflow_events WHERE workflow_id=? AND event_key='final_report_submitted'", [st.workflow.id])[0].n),
      completedHistory: Number(sql("SELECT COUNT(*) n FROM research_project_member_history WHERE project_id=? AND to_stage='ierb_completed'", [st.project.id])[0].n) };
  };
  const unchanged = (before, id, label) => {
    const d = doc(id), after = completion();
    check(!d.rpms_submitted_at && JSON.stringify(after) === JSON.stringify(before) && after.stage !== 'ierb_completed' && !after.completedAt && after.finalEvents === 0,
      label + ' (no submission stamp, no completion, no ledger or member change)');
  };
  try {
    fs.rmSync(flag, { force: true });
    fx({ action: 'cleanup' });
    const setup = fx({ action: 'setup' });
    check(setup.enrolled && setup.project.memberCount === 3 && setup.pi === 61, 'Closure fixture: one verified three-member project (holder 60, PI 61, non-PI member 62) created through the reviewed services');
    const m60 = await login('student60@example.test'), m61 = await login('student61@example.test'), m62 = await login('student62@example.test');
    const outsider = await login('student63@example.test'), adviser3 = await login('adviser3@example.test');

    // 1-2. The non-PI member signs in and reads the shared project evidence through their own identity.
    const own = await expect(response(await m62.get('/documents_api.php?action=requirements&studentId=62')), 200, 'F01.1 Verified non-PI member 62 signs in and reads the shared project checklist');
    check(own.body.submissionScope.studentId === 60 && own.body.submissionScope.principalInvestigator?.studentId === 61 && own.body.workflow.stage === 'initial_submission',
      'F01.2 The checklist resolves to the one project evidence record; member 62 is not the PI');
    await expect(response(await outsider.get('/documents_api.php?action=requirements&studentId=60')), 403, 'F01.9 A Student outside the project cannot read its checklist');

    // Stage 1: 13 requirements, uploaded by rotating members, Adviser-approved (auto-forwarded) and accepted by RPMS over HTTP.
    const initial = own.body.requirements.filter(r => r.stage === 'initial_submission');
    check(initial.length === 13, 'Initial Submission lists exactly 13 requirements');
    const members = [m60, m61, m62], docs = {};
    for (const [i, r] of initial.entries()) {
      const up = await upload(members[i % 3], r.label, r.key);
      if (up.status !== 200) throw Error('Upload failed for ' + r.key + ': ' + JSON.stringify(up));
      docs[r.key] = up.body.document.id;
      const a = await approve(docs[r.key]); if (a.status !== 200) throw Error('Approval failed for ' + r.key + ': ' + JSON.stringify(a));
      const c = await accept(r.key, docs[r.key]); if (c.status !== 200) throw Error('Acceptance failed for ' + r.key + ': ' + JSON.stringify(c));
    }
    check(Object.keys(docs).every(k => doc(docs[k]).student_id === 60 && !!doc(docs[k]).rpms_submitted_at) && state().workflow.stage_key === 'initial_submission',
      'All 13 member uploads joined the evidence record, were formally submitted by Adviser approval and accepted; acceptance alone does not advance');

    // 10a. Missing prerequisite: Stage 1 (no Approval of Data Gathering, no Form 33 acceptance).
    const fr1 = (await expect(upload(m62, 'Form 33-0B - Final Report Form', 'final-report-v1'), 200, 'Non-PI member 62 uploads the Form 33-0B Final Report')).body.document.id;
    check(doc(fr1).student_id === 60 && doc(fr1).uploaded_by === 'Synthetic Student 62', 'The Final Report joins the project evidence record with member 62 as uploader');
    let before = completion();
    await expect(approve(fr1), 409, 'F01.10 Adviser approval of a Final Report before institutional approval is refused (its automatic submission would complete IERB)');
    check(doc(fr1).review_status !== 'Approved' && !doc(fr1).reviewed_by, 'The refused Adviser approval left no partial approval');
    unchanged(before, fr1, 'F01.10 The refused Adviser approval changed nothing');
    await expect(submit(m62, fr1, 1), 409, 'F01.10 A Final Report without Adviser approval cannot be formally submitted by a member');
    fx({ action: 'legacy-approve', documentId: fr1 });
    await expect(submit(m62, fr1, 1), 409, 'F01.10 An Adviser-approved Final Report cannot be formally submitted at Initial Submission');
    unchanged(before, fr1, 'F01.10 A premature member submission at Stage 1 changed nothing');

    // Group advancement to Approval of Data Gathering (RPMS-only, HTTP).
    const groupKey = crypto.createHash('sha256').update('project:' + state().project.id).digest('hex');
    const preview = await expect(post(s.admin, 'group_advance_preview', { groupKey }), 200, 'RPMS previews the project group advancement');
    check(preview.body.eligible, 'All 13 accepted requirements make the project eligible: ' + JSON.stringify(preview.body.reasons || []));
    await expect(post(s.admin, 'group_advance', { confirmationToken: preview.body.confirmationToken, reason: 'Synthetic RPMS reviewed all accepted closure evidence.', confirmed: true }), 200, 'RPMS advances the project to Approval of Data Gathering');
    check(state().members.every(m => m.stage_key === 'approval_data_gathering'), 'Every member reflects Approval of Data Gathering');

    // 10b. Missing prerequisite: Stage 3 without RPMS acceptance of Form 33.
    before = completion();
    await expect(submit(m62, fr1, 1), 409, 'F01.10 A Final Report cannot complete IERB before RPMS accepts Form 33');
    unchanged(before, fr1, 'F01.10 The submission without Form 33 acceptance changed nothing');

    // Form 33 by the holder; Adviser approval auto-forwards; RPMS accepts with the institutional reference.
    const f33 = (await expect(upload(m60, 'Form 33 - Certificate of Approval', 'form33'), 200, 'Holder member uploads Form 33')).body.document.id;
    await expect(approve(f33), 200, 'Adviser approves Form 33 (automatic formal submission)');
    await expect(accept('form33', f33), 422, 'Form 33 acceptance requires the institutional approval reference');
    await expect(accept('form33', f33, { institutionalApprovalReference: 'Synthetic IERB approval reference CC-2026-001' }), 200, 'RPMS accepts Form 33 with its institutional approval reference');

    // 10c. Missing prerequisite: an open correction case blocks forward progress.
    fs.writeFileSync(flag, 'synthetic activation for closure tests');
    await expect(post(s.admin, 'v14_correction_open', { studentId: 60, revision: rev(), reason: 'Synthetic RPMS correction of the study protocol.', documentIds: [docs.protocol], requiresAmendment: false, requestKey: key() }), 200, 'RPMS opens a correction case (no Amendment Form required for this case)');
    check(state().workflow.stage_key === 'resubmission_amendment', 'The project is at Resubmission / Amendment');
    before = completion();
    await expect(submit(m62, fr1, 1), 409, 'F01.10 A Final Report cannot complete IERB while a correction case is open');
    unchanged(before, fr1, 'F01.10 The submission during an open correction case changed nothing');
    // Reviewed correction flow (Batch 2): RPMS returns the exact affected version, then a member uploads its revision.
    await expect(post(s.admin, 'return_for_revision', { id: docs.protocol, reason: 'Synthetic case requires a corrected protocol version.' }, 'documents_api.php'), 200, 'RPMS returns the affected protocol version');
    const protocol2 = (await expect(upload(m61, 'Study Protocol', 'protocol-v2', docs.protocol), 200, 'PI member uploads the corrected Study Protocol as its revision')).body.document.id;
    await expect(approve(protocol2), 200, 'Adviser approves the corrected protocol (automatic formal submission)');
    await expect(accept('protocol', protocol2), 200, 'RPMS accepts the corrected protocol');
    await expect(post(s.admin, 'v14_correction_resolve', { studentId: 60, revision: rev(), reason: 'Synthetic corrected evidence verified.', requestKey: key() }), 200, 'RPMS resolves the correction case');
    fs.rmSync(flag, { force: true });
    check(state().workflow.stage_key === 'approval_data_gathering', 'Resolution returns the project to Approval of Data Gathering');

    // 10d. A newer, unapproved Final Report version is not eligible; the superseded approved one cannot be used either.
    const fr2 = (await expect(upload(m62, 'Form 33-0B - Final Report Form', 'final-report-v2'), 200, 'Member 62 uploads Final Report version 2')).body.document.id;
    check(doc(fr2).supersedes_id === fr1 && doc(fr2).version_no === 2, 'Final Report version 2 extends the single version chain');
    before = completion();
    await expect(submit(m62, fr2, 2), 409, 'F01.10 An unapproved current Final Report version cannot be formally submitted');
    await expect(submit(m62, fr1, 1), 409, 'F01.10 A superseded Adviser-approved version cannot be formally submitted');
    unchanged(before, fr2, 'F01.10 Refused submissions of v1/v2 changed nothing');
    fx({ action: 'legacy-approve', documentId: fr2 });

    // 3-4 and 9. Security and authorization refusals on the eligible version.
    await expect(submit(outsider, fr2, 2), 403, 'F01.9 A Student outside the project cannot formally submit the project Final Report');
    await expect(submit(adviser3, fr2, 2), 403, 'F01.9 An unassigned Adviser cannot formally submit it');
    await expect(submit(s.adviser, fr2, 2), 403, 'F01.4 The assigned Adviser has no separate formal-submission route');
    check((await m62.get('/documents_api.php?action=submit_to_rpms&id=' + fr2)).status() === 405, 'F01.4 Formal submission requires POST');
    await expect(submit(m62, fr2, 2, { origin: 'http://attacker.example', 'X-Prism-Generation': await generation(m62) }), 403, 'F01.4 A cross-origin formal submission is blocked');
    await expect(submit(m62, fr2, 2, { origin: base }), 409, 'F01.4 A formal submission without the session-generation marker is refused');
    await expect(submit(m62, fr2, 2, { origin: base, 'X-Prism-Generation': 'stale-generation' }), 409, 'F01.4 A stale session generation is refused');
    await expect(submit(m62, fr2), 422, 'F01.4 The member must confirm the exact version');
    await expect(submit(m62, fr2, 1), 409, 'F01.4 A mismatched version number is refused');
    const forged = await response(await m62.post('/documents_api.php?action=submit_to_rpms', { data: { id: fr2, versionNo: 2, rpms_submitted_by: 'Forged' }, headers: { origin: base, 'X-Prism-Generation': await generation(m62) } }));
    check(forged.status === 422, 'F01.4 Authoritative workflow fields cannot be supplied by the client');
    unchanged(before, fr2, 'F01.9 Refused and unauthorized attempts did not affect the project');

    // 3 and 5-8. The non-PI member formally submits; a simultaneous double click commits exactly once.
    const noticeFloor = Number(sql('SELECT COALESCE(MAX(id),0) id FROM notifications')[0].id), auditFloor = Number(sql('SELECT COALESCE(MAX(id),0) id FROM activity_logs')[0].id);
    const race = await Promise.all([submit(m62, fr2, 2), submit(m62, fr2, 2)]);
    const won = race.find(r => r.status === 200);
    check(race.filter(r => r.status === 200).length === 1 && race.filter(r => r.status === 409).length === 1, 'F01.3/F01.8 Member 62 formally submits; a simultaneous duplicate commits exactly once: ' + JSON.stringify(race.map(r => r.status)));
    check(won.body.stageAdvanced === true && won.body.newStage === 'ierb_completed' && won.body.workflowState === 'Submitted to RPMS', 'F01.5 The response reports automatic IERB completion');
    const d = doc(fr2), st = state();
    check(!!d.rpms_submitted_at && d.rpms_submitted_by.includes('Synthetic Student 62') && d.rpms_submitted_by.includes('(Student formal submission)'), 'F01.7 The version records the non-PI member as formal submitter');
    check(st.workflow.stage_key === 'ierb_completed' && !!st.project.completed_at && st.project.completion_document_id === fr2, 'F01.5 The project is IERB Completed with this Final Report as its completion evidence');
    check(st.members.length === 3 && st.members.every(m => m.stage_key === 'ierb_completed' && Number(m.workflow_revision) === Number(st.workflow.revision)), 'F01.6 All three verified members hold the synchronized completion status and revision');
    const history = sql("SELECT student_id FROM research_project_member_history WHERE project_id=? AND to_stage='ierb_completed' ORDER BY student_id", [st.project.id]).map(r => r.student_id).join(',');
    check(history === '60,61,62', 'F01.6 Each member has exactly one completion history row: ' + history);
    const events = sql("SELECT actor_user_id,from_stage,to_stage,evidence_json FROM ierb_workflow_events WHERE workflow_id=? AND event_key='final_report_submitted'", [st.workflow.id]);
    const ev = events.length === 1 ? JSON.parse(events[0].evidence_json) : {};
    check(events.length === 1 && Number(events[0].actor_user_id) === 62 && events[0].from_stage === 'approval_data_gathering' && events[0].to_stage === 'ierb_completed'
      && ev.finalReport?.documentId === fr2 && ev.institutionalApproval?.documentId === f33 && Object.keys(ev.initialRequirements || {}).length === 13 && ev.initialRequirements.protocol.documentId === protocol2,
      'F01.7 One immutable ledger event names member 62, the Final Report, the accepted Form 33 and all 13 current accepted requirements');
    const audits = sql("SELECT action,user_email,after_value FROM activity_logs WHERE id>? AND entity_id=?", [auditFloor, fr2]);
    // Version 1 was never formally submitted, so this is a first submission, not a resubmission.
    check(audits.length === 1 && audits[0].action === 'document_submitted_to_rpms' && audits[0].user_email === 'student62@example.test' && audits[0].after_value === 'Submitted to RPMS',
      'F01.7 Exactly one formal-submission audit, attributed to member 62: ' + JSON.stringify(audits));
    const progress = sql("SELECT entity_id FROM activity_logs WHERE id>? AND action='research_project_member_progress' AND after_value='ierb_completed' ORDER BY entity_id", [auditFloor]).map(r => r.entity_id).join(',');
    check(progress === '60,61,62', 'F01.7 A member-progress audit exists for every member: ' + progress);
    const notices = sql("SELECT recipient_type,recipient_id,subject,message FROM notifications WHERE id>?", [noticeFloor]);
    for (const id of [60, 61, 62]) check(notices.some(n => n.recipient_type === 'student' && Number(n.recipient_id) === id && n.subject === 'PRISM research project progress' && n.message.includes('IERB Completed')),
      'F01.6 Member ' + id + ' is notified that the project is IERB Completed');
    check(notices.some(n => n.recipient_type === 'student' && Number(n.recipient_id) === 60 && n.subject === 'PRISM Formal RPMS Submission Confirmation' && n.message.includes('Synthetic Student 62')),
      'F01.7 The evidence record receives the formal-submission confirmation naming the submitting member');
    check(notices.some(n => n.recipient_type === 'admin' && n.subject === 'Document submitted to RPMS' && n.message.includes('submitted by Student Synthetic Student 62')), 'F01.7 RPMS Admins receive the formal-submission queue notice');
    check(!notices.some(n => n.recipient_type === 'student' && Number(n.recipient_id) === 63), 'F01.9 The outsider receives no project notice');
    for (const [id, ctx] of [[60, m60], [61, m61], [62, m62]]) {
      const view = await response(await ctx.get('/documents_api.php?action=requirements&studentId=' + id));
      check(view.status === 200 && view.body.workflow.stage === 'ierb_completed' && view.body.workflow.progress === 100, 'F01.6 Member ' + id + ' reads IERB Completed (100%) through their own identity');
    }

    // 8. Later duplicates by every member are refused and change nothing.
    const done = completion();
    for (const [label, ctx] of [['holder 60', m60], ['PI 61', m61], ['submitter 62', m62]]) await expect(submit(ctx, fr2, 2), 409, 'F01.8 A later duplicate submission by ' + label + ' is refused');
    const replay = await Promise.all([submit(m60, fr2, 2), submit(m61, fr2, 2), submit(m62, fr2, 2)]);
    check(replay.every(r => r.status === 409), 'F01.8 Three concurrent duplicates are all refused: ' + JSON.stringify(replay.map(r => r.status)));
    check(JSON.stringify(completion()) === JSON.stringify(done) && done.finalEvents === 1 && done.completedHistory === 3
      && Number(sql("SELECT COUNT(*) n FROM activity_logs WHERE entity_id=? AND action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms','admin_override_submit_to_rpms')", [fr2])[0].n) === 1,
      'F01.8 Completion, ledger, member history and audit stay exactly as after the first submission');
    await expect(approve(fr2), 409, 'The Adviser cannot re-approve the completed Final Report');
    // Observation only (no reviewed rule states whether uploads stay open after completion).
    const lateUpload = await upload(m61, 'Form 33-0B - Final Report Form', 'final-report-after-completion');
    check(JSON.stringify(completion()) === JSON.stringify(done), 'A later Final Report upload (HTTP ' + lateUpload.status + ') does not alter completion evidence');
    fs.writeFileSync(path.join(evidence, 'closure-final-report.json'), JSON.stringify({ lateUploadStatus: lateUpload.status, project: { id: st.project.id, identifier: st.project.public_identifier, completedAt: st.project.completed_at, completionDocument: st.project.completion_document_id },
      submitter: 'student62@example.test (non-PI member)', pi: 61, race: race.map(r => r.status), duplicates: replay.map(r => r.status), members: st.members, history, ledgerEvent: { actor: events[0].actor_user_id, from: events[0].from_stage, to: events[0].to_stage },
      audits, memberProgressAudits: progress, notices: notices.map(n => ({ type: n.recipient_type, id: n.recipient_id, subject: n.subject })) }, null, 2));
  } finally {
    fs.rmSync(flag, { force: true });
    try { fx({ action: 'cleanup' }); } catch (e) { console.error('Closure cleanup failed: ' + e.message); }
  }
  check(!sql('SELECT id FROM students WHERE id IN (60,61,62,63)').length && !sql("SELECT id FROM documents WHERE original_name LIKE 'Synthetic Closure %'").length, 'Closure records are fully removed');
  check(h.checks.length - start > 60, 'Closure suite executed ' + (h.checks.length - start) + ' assertions');
};
