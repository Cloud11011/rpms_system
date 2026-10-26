const fs = require('node:fs'), path = require('node:path'), crypto = require('node:crypto');
function pdf() {
  const text = 'Synthetic research methods. A descriptive quantitative study of 40 fictional transactions found average retrieval times of 12 minutes manually and 4 minutes centrally. The small nonrandom sample limits generalization. '.repeat(12);
  const stream = 'BT /F1 10 Tf 30 750 Td (' + text + ') Tj ET';
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', '<< /Length ' + stream.length + ' >>\nstream\n' + stream + '\nendstream'];
  let data = '%PDF-1.4\n', offsets = [0]; objects.forEach((o,i) => { offsets.push(Buffer.byteLength(data)); data += `${i+1} 0 obj\n${o}\nendobj\n`; });
  const start = Buffer.byteLength(data); data += 'xref\n0 6\n0000000000 65535 f \n' + offsets.slice(1).map(o => String(o).padStart(10,'0') + ' 00000 n \n').join('') + 'trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n' + start + '\n%%EOF'; return Buffer.from(data);
}
module.exports = async h => {
  const { sessions: s, expect, post, response, check, sql, generation, group } = h;
  group.reset([4,5,6]);
  async function deleteFixtureDocuments() {
    const selection = await expect(post(s.admin, 'selection_snapshot', { filters: { q: 'Synthetic group regression.pdf', includeOld: '1' } }, 'documents_api.php'), 200, 'Document management selects every matching synthetic document across pages');
    const reviewed = await expect(post(s.admin, 'bulk_delete_preview', { selectionToken: selection.body.selectionToken, excludedIds: [] }, 'documents_api.php'), 200, 'Existing bulk deletion previews exact cross-page document snapshot');
    const removed = await expect(post(s.admin, 'bulk_delete', { confirmationToken: reviewed.body.confirmationToken, confirmed: true, reason: 'Delete only isolated synthetic group regression fixtures.' }, 'documents_api.php'), 200, 'Existing confirmed permanent bulk deletion works in isolated storage');
    check(removed.body.deleted === reviewed.body.count && removed.body.physicalDeletionComplete, 'Bulk deletion removes all selected records and private fixture files');
  }
  if (sql("SELECT id FROM documents WHERE original_name='Synthetic group regression.pdf'").length) await deleteFixtureDocuments();
  const row = () => sql('SELECT stage,status FROM students WHERE id=4')[0];
  const decision = async (operation, extra = {}) => {
    const r = row(), version = String(sql('SELECT COALESCE(MAX(id),0) v FROM ierb_history WHERE student_id=4')[0].v);
    return post(s.admin, operation, { id: 4, reason: 'Synthetic RPMS regression decision with explicit review.', confirmed: true, expectedStage: r.stage, expectedStatus: r.status, expectedVersion: version, ...extra });
  };
  await expect(decision('correct', { stage: 'Stage 3' }), 200, 'Existing individual stage correction succeeds');
  await expect(decision('status', { status: 'Delayed' }), 200, 'Existing individual progress status action succeeds');
  check(row().stage === 'Stage 3' && row().status === 'Delayed', 'Correction and status remain independently controlled');
  await expect(decision('correct', { stage: 'Stage 1' }), 200, 'Individual stage correction restores test stage');
  const labels = (await response(await s.admin.get('/stage_labels_api.php?action=list'))).body;
  const first = labels.stages[0];
  await expect(post(s.admin, 'save', { stageKey: first.key, label: 'Synthetic RPMS Initial Review', guidance: 'Synthetic readiness instructions.', expectedVersion: first.version }, 'stage_labels_api.php'), 200, 'Existing Stage Guidance editing persists configured display name');
  const p = await group.preview(); check(p.currentStageLabel === 'Synthetic RPMS Initial Review', 'Group preview uses configured Stage Guidance labels with canonical stage key');
  let docId;
  async function upload(revisionOf = '') {
    return response(await s.student.post('/documents_api.php?action=upload', { headers: { origin: h.base, 'X-Prism-Generation': await generation(s.student) }, multipart: { documentType: 'Study Protocol', revisionOf, requestId: 'group-regression-' + Date.now(), document: { name: 'Synthetic group regression.pdf', mimeType: 'application/pdf', buffer: pdf() } } }));
  }
  let uploaded = await expect(upload(), 200, 'Existing Student document upload works'); docId = uploaded.body.document.id;
  const progressBefore = JSON.stringify(row());
  await expect(post(s.adviser, 'review', { id: docId, status: 'Approved', remarks: 'Synthetic Adviser review accepted.' }, 'documents_api.php'), 200, 'Assigned Adviser approval still automatically formally submits to RPMS');
  const document = sql('SELECT * FROM documents WHERE id=?', [docId])[0];
  check(document.reviewed_by === 'Synthetic Adviser 2' && !!document.rpms_submitted_at && !document.admin_override && JSON.stringify(row()) === progressBefore, 'Adviser approval preserves attribution and submission without changing official progress');
  const history = await response(await s.admin.get('/ierb_api.php?action=history&studentId=4'));
  check(history.status === 200, 'Existing IERB history endpoint remains readable');
  const notifications = await response(await s.student.get('/notifications_api.php?action=list&perPage=100'));
  check(notifications.status === 200 && notifications.body.ok, 'Committed in-app notification inbox remains operational');
  await expect(post(s.admin, 'return_for_revision', { id: docId, reason: 'Synthetic consent requirements need a new revision.' }, 'documents_api.php'), 200, 'Existing Return for Revision works');
  uploaded = await expect(upload(docId), 200, 'Student uploads a new version after Return for Revision');
  const newId = uploaded.body.document.id;
  check(sql('SELECT supersedes_id,version_no FROM documents WHERE id=?', [newId])[0].supersedes_id === docId, 'Document version chain preserves the submitted predecessor');
  await expect(post(s.adviser, 'review', { id: newId, status: 'Approved', remarks: 'Synthetic revised protocol accepted.' }, 'documents_api.php'), 200, 'Adviser approval formally resubmits new revision');
  fs.writeFileSync(path.join(h.review, 'sandbox/provider-scenario.txt'), 'network');
  for (const mode of ['summary', 'full']) {
    const report = await expect(post(s.admin, 'ai_report', { mode }, 'reports_api.php'), 200, 'AI ' + mode + ' progress report retains local fallback during provider failure');
    check(report.body.aiUsed === false, mode + ' progress report explicitly records local fallback');
  }
  fs.writeFileSync(path.join(h.review, 'sandbox/provider-scenario.txt'), 'success');
  await expect(post(s.admin, 'summarize', { id: newId, mode: 'local' }, 'documents_api.php'), 200, 'Existing document Local Summary generates and persists');
  const outgoing = await expect(post(s.admin, 'summarize_preview', { id: newId }, 'documents_api.php'), 200, 'Existing AI outgoing excerpt preview succeeds');
  await expect(post(s.admin, 'summarize', { id: newId, mode: 'ai', previewToken: outgoing.body.previewToken, confirmed: true }, 'documents_api.php'), 200, 'Existing document AI summarization generates and persists with isolated synthetic provider');
  const evidence = JSON.stringify(sql('SELECT * FROM documents ORDER BY id'));
  const studentBefore = sql('SELECT * FROM students WHERE id=4')[0];
  const ready = await group.preview(); await expect(post(s.admin, 'group_advance', group.payload(ready)), 200, 'Group advancement succeeds independently of existing submitted documents and summaries');
  const studentAfter = sql('SELECT * FROM students WHERE id=4')[0];
  for (const field of ['stage','updated_at']) { delete studentBefore[field]; delete studentAfter[field]; }
  check(JSON.stringify(studentBefore) === JSON.stringify(studentAfter) && JSON.stringify(sql('SELECT * FROM documents ORDER BY id')) === evidence, 'Group decision preserves every other Student field, approval evidence, submissions, version chains and stored AI summaries');
  // Nine additional isolated legacy document types make the management regression genuinely cross-page.
  for (let i=0;i<9;i++) {
    const id=crypto.randomBytes(12).toString('hex'), file=id+'.pdf', bytes=pdf();
    fs.writeFileSync(path.join(h.app,'storage/documents',file),bytes);
    sql("INSERT INTO documents(id,student_id,student_name,uploaded_by,uploaded_by_role,original_name,stored_name,mime,size,document_type,stage,review_status,version_no,is_current) VALUES (?,4,'Synthetic Student 4','Synthetic Student 4','student','Synthetic group regression.pdf',?,'application/pdf',?,?,'Stage 1','Submitted',1,1)", [id,file,bytes.length,'Synthetic fixture '+i]);
  }
  const page1 = (await response(await s.admin.get('/documents_api.php?action=list&perPage=10&includeOld=1&page=1&q=Synthetic%20group%20regression.pdf'))).body;
  const page2 = (await response(await s.admin.get('/documents_api.php?action=list&perPage=10&includeOld=1&page=2&q=Synthetic%20group%20regression.pdf'))).body;
  check(page1.documents.length === 10 && page2.documents.length === 1, 'Document management fixtures genuinely span two ten-row pages');
  for (const ctx of [s.admin, s.adviser]) {
    const r = await response(await ctx.get('/documents_api.php?action=list&perPage=1&page=2&stage=Stage%201'));
    check(r.status === 200 && r.body.ok, 'Existing document management pagination and filters remain operational');
  }
  sql('UPDATE users SET must_change_password=1 WHERE id=4');
  try { await expect(post(s.student, 'summarize_preview', { id: newId, method: 'local' }, 'documents_api.php'), 403, 'Account restriction still blocks document workflow'); }
  finally { sql('UPDATE users SET must_change_password=0 WHERE id=4'); }
  await deleteFixtureDocuments();
  group.reset([4,5,6]);
};
