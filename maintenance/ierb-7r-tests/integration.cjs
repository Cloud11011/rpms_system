// Batch 7R regression: collation alignment, group-wide uploads and formal submission, PI designation,
// Adviser correction requests with RPMS decisions, Research Title policy, report/deadline sorting, PDF titles,
// runtime preflight. Synthetic records 40-43 only; removed in finally. Run at schema 15 (PRISM_TEST_BATCH7R=1).
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');
function pdf(label) {
  const text = ('Synthetic Batch7R ' + label + ' document for fictional study participants. ').repeat(6), stream = `BT /F1 10 Tf 30 750 Td (${text}) Tj ET`;
  const objects = ['<< /Type /Catalog /Pages 2 0 R >>', '<< /Type /Pages /Kids [3 0 R] /Count 1 >>', '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>', '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>', `<< /Length ${stream.length} >>\nstream\n${stream}\nendstream`];
  let data = '%PDF-1.4\n'; const offsets = []; objects.forEach((o, i) => { offsets.push(Buffer.byteLength(data)); data += `${i + 1} 0 obj\n${o}\nendobj\n`; });
  const start = Buffer.byteLength(data); return Buffer.from(data + 'xref\n0 6\n0000000000 65535 f \n' + offsets.map(o => String(o).padStart(10, '0') + ' 00000 n \n').join('') + `trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n${start}\n%%EOF`);
}
module.exports = async h => {
  const { sql, check, post, response, expect, generation, sessions: s, base, root, php, review, login } = h;
  const start = h.checks.length;
  const helper = path.join(__dirname, 'fixture.php');
  const fx = data => { const r = JSON.parse(cp.execFileSync(php, [helper], { input: JSON.stringify(data), encoding: 'utf8', windowsHide: true })); if (!r.ok) throw Error(JSON.stringify(r)); return r.result; };
  const flag = path.join(review, 'sandbox/v14-operations.flag');
  const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR || path.join(root, 'PRISM_V14_7R_EVIDENCE'));
  fs.mkdirSync(evidence, { recursive: true });
  const upload = async (ctx, type, label, revisionOf = '') => response(await ctx.post('/documents_api.php?action=upload', { headers: { origin: base, 'X-Prism-Generation': await generation(ctx) },
    multipart: { documentType: type, revisionOf, requestId: crypto.randomUUID(), document: { name: `Synthetic Batch7R ${label}.pdf`, mimeType: 'application/pdf', buffer: pdf(label) } } }));
  const key = () => 'b7r-' + crypto.randomUUID();
  const wf = () => fx({ action: 'state' }).workflow;
  try {
    fx({ action: 'cleanup' });
    // --- 7R-A: extension tables follow the legacy base collation; monitor search and filters work on it.
    const coll = fx({ action: 'collation' });
    check(Object.values(coll.extension).length === 11 && Object.values(coll.extension).every(c => c === coll.anchor), 'Every schema 12-15 extension table uses the legacy base collation ' + coll.anchor);
    const setup = fx({ action: 'setup' });
    check(setup.enrolled && setup.project.memberCount === 3 && !setup.project.identifier.endsWith('-'), 'Explicit enrollment and reviewed reconciliation create one canonical three-member project');
    const monitor = await response(await s.admin.get('/ierb_api.php?action=monitor&q=' + encodeURIComponent('Peñaflor') + '&sortBy=title&direction=ASC'));
    check(monitor.status === 200 && JSON.stringify(monitor.body).includes('Batch 7R Shared Project'), 'IERB monitor search over canonical titles and legacy titles works on the ' + coll.anchor + ' base (no collation mix)');
    const slugs = fx({ action: 'slug' });
    check(!slugs.slug.endsWith('-') && slugs.slug.length <= 160 && slugs.short === 'nunoa-study', 'Long-title identifiers never end with a hyphen and short titles stay readable (B7-F22)');

    const m40 = await login('student40@example.test'), m41 = await login('student41@example.test'), m42 = await login('student42@example.test'), outsider = await login('student43@example.test');
    const adviser3 = await login('adviser3@example.test');
    // --- 7R-B: PI designation is recorded, displayed and grants nothing.
    check(fx({ action: 'pi', studentId: 41 }).principalInvestigatorStudentId === 41, 'RPMS designates the Principal Investigator through an audited project event');
    let failed = null; try { fx({ action: 'pi', studentId: 43 }); } catch (e) { failed = e.message; }
    check(failed && failed.includes('verified member'), 'A Student outside the project cannot be designated Principal Investigator');
    const checklist42 = await expect(response(await m42.get('/documents_api.php?action=requirements&studentId=42')), 200, 'A non-PI member reads the shared project checklist through their own identity');
    check(checklist42.body.submissionScope.principalInvestigator?.studentId === 41 && checklist42.body.submissionScope.researchTitle.includes('Peñaflor'), 'The checklist shows the recorded PI and the canonical research title');

    // --- 7R-B: every verified member uploads to the single project evidence record.
    const up41 = await expect(upload(m41, 'Study Protocol', 'protocol-by-PI'), 200, 'The PI member uploads a project document');
    const up42 = await expect(upload(m42, 'Curriculum Vitae', 'cv-by-member'), 200, 'A non-PI member uploads a project document');
    const d41 = sql('SELECT * FROM documents WHERE id=?', [up41.body.document.id])[0], d42 = sql('SELECT * FROM documents WHERE id=?', [up42.body.document.id])[0];
    check(d41.student_id === 40 && d42.student_id === 40 && d41.uploaded_by === 'Synthetic Student 41' && d42.uploaded_by === 'Synthetic Student 42', 'Both uploads join the one project evidence record and keep their individual uploaders');
    check(sql("SELECT user_email FROM activity_logs WHERE action='document_uploaded' AND entity_id=?", [d42.id])[0].user_email === 'student42@example.test', 'The upload audit identifies the uploading member');
    await expect(upload(m42, 'Study Protocol', 'unrequested-replacement'), 409,
      'A second member cannot silently replace an existing current group document');
    await expect(post(s.adviser,'review',{id:d41.id,status:'Resubmission Requested',remarks:'Synthetic protocol needs a corrected version.'},'documents_api.php'),200,
      'Assigned Adviser requests an explicit versioned revision of the shared document');
    const up42v2 = await expect(upload(m42, 'Study Protocol', 'protocol-v2-by-member',d41.id), 200,
      'A different verified member uploads the explicitly requested successor version');
    const v2 = sql('SELECT * FROM documents WHERE id=?', [up42v2.body.document.id])[0];
    check(v2.supersedes_id === d41.id && v2.version_no === 2 && sql('SELECT is_current FROM documents WHERE id=?', [d41.id])[0].is_current === 0, 'Per-document version chains stay single and correct across members');
    const list40 = await response(await m40.get('/documents_api.php?action=list'));
    check(list40.status === 200 && list40.body.documents.some(d => d.id === v2.id), 'The evidence-record member lists documents uploaded by other members');
    const sharedFile = await m40.get('/documents_api.php?action=file&download=1&id=' + v2.id);
    check(sharedFile.status() === 200 && (await sharedFile.body()).subarray(0, 5).toString() === '%PDF-', 'Every verified member may open the shared project file');
    await expect(response(await outsider.get('/documents_api.php?action=file&download=1&id=' + v2.id)), 403, 'A Student outside the project cannot open the project file');
    const outsiderList = await response(await outsider.get('/documents_api.php?action=list'));
    check(outsiderList.status === 200 && !outsiderList.body.documents.some(d => d.student_id === 40 || d.id === v2.id), 'A Student outside the project never lists project documents');
    await expect(response(await outsider.get('/documents_api.php?action=requirements&studentId=40')), 403, 'A Student outside the project cannot read its checklist');

    // --- 7R-B: formal RPMS submission of an Adviser-approved version by a non-PI member; duplicates refused.
    await expect(post(m42, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php'), 409, 'A version without Adviser approval cannot be formally submitted by a Student');
    fx({ action: 'legacy-approve', documentId: d42.id });
    await expect(post(outsider, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php'), 403, 'A Student outside the project cannot formally submit project evidence');
    await expect(post(adviser3, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php'), 403, 'Advisers have no separate formal-submission route');
    await expect(post(m42, 'submit_to_rpms', { id: d42.id }, 'documents_api.php'), 422, 'Student formal submission must confirm the exact version');
    const race = await Promise.all([post(m42, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php'), post(m40, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php')]);
    check(race.filter(r => r.status === 200).length === 1 && race.filter(r => r.status === 409).length === 1, 'Concurrent member submissions commit exactly once: ' + JSON.stringify(race.map(r => r.status)));
    const submitted = sql('SELECT * FROM documents WHERE id=?', [d42.id])[0];
    check(!!submitted.rpms_submitted_at && submitted.rpms_submitted_by.includes('(Student formal submission)'), 'The formal submitter is recorded on the version');
    check(sql("SELECT COUNT(*) n FROM activity_logs WHERE entity_id=? AND action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms')", [d42.id])[0].n === 1, 'Exactly one formal-submission audit exists for the version');
    await expect(post(m41, 'submit_to_rpms', { id: d42.id, versionNo: 1 }, 'documents_api.php'), 409, 'A later duplicate submission by another member is refused');
    const overrideDoc = (await expect(upload(m41, 'Payment Receipt', 'receipt'), 200, 'Member uploads a receipt')).body.document.id;
    fx({ action: 'legacy-approve', documentId: overrideDoc, override: true });
    await expect(post(m41, 'submit_to_rpms', { id: overrideDoc, versionNo: 1 }, 'documents_api.php'), 409, 'An RPMS-override approval is not an Adviser approval a Student may submit');

    // --- UX-01: the Student portal offers "Submit to RPMS" to every verified member, only for an Adviser approval that was
    // not forwarded automatically. An approval the Adviser records now is forwarded in the same operation and needs no Student action.
    const uiDoc = (await expect(upload(m40, 'Data Collection Questionnaire', 'questionnaire-for-ui'), 200, 'A member uploads a document for the Student submission UI check')).body.document;
    const autoDoc = (await expect(upload(m41, 'Diagrammatic Workflow', 'workflow-auto-forward'), 200, 'A member uploads a document the Adviser will approve')).body.document;
    const flags = async ctx => Object.fromEntries((await response(await ctx.get('/documents_api.php?action=list&perPage=100'))).body.documents.map(d => [d.id, { submit: d.actions?.memberSubmitToRpms === true, state: d.workflowState }]));
    check((await flags(m42))[uiDoc.id]?.submit === false, 'No Student submission action before Adviser approval');
    await expect(post(s.adviser, 'review', { id: autoDoc.id, status: 'Approved', remarks: 'Synthetic approval' }, 'documents_api.php'), 200, 'The assigned Adviser approves a member document');
    fx({ action: 'legacy-approve', documentId: uiDoc.id });
    const f42 = await flags(m42), f40 = await flags(m40);
    check(f42[autoDoc.id]?.state === 'Submitted to RPMS' && f42[autoDoc.id].submit === false, 'An Adviser approval is formally submitted automatically and offers no Student submission action');
    check(f42[uiDoc.id]?.submit === true && f40[uiDoc.id]?.submit === true && f42[uiDoc.id].state === 'Ready for Formal RPMS Submission', 'Every verified member (holder and non-PI member) is offered Submit to RPMS for an Adviser approval that was not forwarded');
    check(f42[overrideDoc]?.submit === false && f42[d42.id]?.submit === false && f42[v2.id]?.submit === false, 'No Student submission action for an RPMS-override approval, an already submitted version or a version awaiting review');
    const staffRows = [...(await response(await s.adviser.get('/documents_api.php?action=list&perPage=100'))).body.documents, ...(await response(await s.admin.get('/documents_api.php?action=list&perPage=100'))).body.documents];
    check(staffRows.some(d => d.id === uiDoc.id) && staffRows.every(d => !d.actions?.memberSubmitToRpms), 'Advisers and RPMS never receive the Student submission action');
    check(!(await flags(outsider))[uiDoc.id], 'A Student outside the project sees neither the document nor the action');
    const uiBrowser = await h.playwright.chromium.launch({ executablePath: process.env.PRISM_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
      const u42 = sql("SELECT ref_id,username FROM users WHERE email='student42@example.test'")[0];
      const tour = 'prismTour.student.' + crypto.createHash('sha256').update('Student|' + (u42.ref_id || u42.username)).digest('hex') + '.v1';
      for (const width of [390, 1440]) {
        const ctx = await uiBrowser.newContext({ viewport: { width, height: 900 }, storageState: await m42.storageState(), reducedMotion: 'reduce' });
        await ctx.addInitScript(key => localStorage.setItem(key, 'seen'), tour);
        await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com|cdnjs\.cloudflare\.com)\//, r => r.fulfill({ contentType: 'text/css', body: '' }));
        const page = await ctx.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
        await page.goto(base + '/role_portal.php#documents');
        const card = label => page.locator('.student-document-card').filter({ hasText: 'Synthetic Batch7R ' + label + '.pdf' });
        await card('questionnaire-for-ui').waitFor({ timeout: 15000 });
        const button = card('questionnaire-for-ui').getByRole('button', { name: /^Submit .* version 1 to RPMS$/ });
        const others = await Promise.all(['cv-by-member', 'receipt', 'workflow-auto-forward', 'protocol-v2-by-member'].map(l => card(l).getByRole('button', { name: /to RPMS/ }).count()));
        const visible = await button.isVisible(), fits = visible && await button.evaluate(el => { const r = el.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth; });
        const noScroll = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 2);
        check(visible && fits && noScroll && others.every(n => n === 0), `${width}px: the non-PI member sees Submit to RPMS only on the not-forwarded Adviser approval, within the viewport ${JSON.stringify({ visible, fits, noScroll, others })}`);
        await button.click();
        const dialog = page.locator('[role="dialog"][aria-modal="true"]').filter({ hasText: 'version 1 to RPMS' }).last();
        await dialog.waitFor({ timeout: 5000 });
        const text = await dialog.innerText();
        if (width === 390) {
          await dialog.locator('[data-act="cancel"]').click(); await page.waitForTimeout(300);
          check(text.includes('Synthetic Batch7R questionnaire-for-ui.pdf') && text.includes('Adviser approved') && !sql('SELECT rpms_submitted_at FROM documents WHERE id=?', [uiDoc.id])[0].rpms_submitted_at && await button.isVisible(),
            '390px: the confirmation names the exact file and version; Cancel submits nothing');
        } else {
          await dialog.locator('[data-act="ok"]').click();
          await page.waitForFunction(id => !document.querySelector(`[data-member-submit="${id}"]`), uiDoc.id, { timeout: 10000 });
          await card('questionnaire-for-ui').getByText('Submitted to RPMS').first().waitFor({ timeout: 10000 });
          check(true, '1440px: confirming submits the version; the card refreshes to Submitted to RPMS and the action disappears');
        }
        await page.screenshot({ path: path.join(evidence, `member-submit-${width}.png`) });
        check(!errors.length, `${width}px: no page errors in the Student documents view ${JSON.stringify(errors)}`);
        await ctx.close();
      }
    } finally { await uiBrowser.close(); }
    const uiSubmitted = sql('SELECT rpms_submitted_at,rpms_submitted_by FROM documents WHERE id=?', [uiDoc.id])[0];
    check(!!uiSubmitted.rpms_submitted_at && uiSubmitted.rpms_submitted_by.includes('Synthetic Student 42 (Student formal submission)'), 'The UI submission is recorded as the non-PI member\'s formal submission');
    check(sql("SELECT COUNT(*) n FROM activity_logs WHERE entity_id=? AND action IN ('document_submitted_to_rpms','document_resubmitted_to_rpms')", [uiDoc.id])[0].n === 1, 'Exactly one formal-submission audit exists for the UI submission');
    await expect(post(m41, 'submit_to_rpms', { id: uiDoc.id, versionNo: 1 }, 'documents_api.php'), 409, 'Another member cannot submit the same version again after the UI submission');

    // --- 7R-C: RPMS-only official resubmissions, distinct from Adviser document revisions.
    fs.rmSync(flag, { force: true });
    const requestBody = { studentId: 40, revision: Number(wf().revision), reason: 'Synthetic formal case request.', documentIds: [v2.id], suggestAmendment: true, requestKey: key() };
    await expect(post(s.adviser, 'v14_correction_request', requestBody), 403,
      'Retired Adviser correction-request API denies the Adviser even before activation');
    fs.writeFileSync(flag, 'synthetic activation for Batch 7R tests');
    await expect(post(s.adviser, 'v14_correction_request', requestBody), 403,
      'The assigned Adviser cannot request an official IERB resubmission');
    await expect(post(m41, 'v14_correction_request', requestBody), 403,
      'A Student cannot request an official IERB resubmission');
    await expect(post(s.admin, 'v14_correction_request', requestBody), 410,
      'Retired Adviser request route is unavailable even to RPMS');
    const open = (ctx, extra = {}) => post(ctx, 'v14_correction_open', { studentId: 40, revision: Number(wf().revision),
      reason: 'Synthetic RPMS initiates the official IERB resubmission.', documentIds: [v2.id], requiresAmendment: true,
      requestKey: key(), ...extra });
    await expect(open(s.adviser), 403, 'Assigned Adviser cannot directly open a formal resubmission case');
    await expect(open(m41), 403, 'Student cannot directly open a formal resubmission case');
    const queue = await expect(response(await s.admin.get('/ierb_api.php?action=v14_correction_queue')), 200,
      'RPMS can read the historical correction-request queue');
    check(!queue.body.requests.some(x => x.studentId === 40),
      'No new Adviser correction request was recorded by any retired route');
    await expect(response(await s.adviser.get('/ierb_api.php?action=v14_correction_queue')), 403,
      'Advisers cannot read the RPMS request decision queue');
    // UI on both viewport sizes: Adviser can still review document revisions, but not initiate formal resubmissions.
    const browser = await h.playwright.chromium.launch({ executablePath: process.env.PRISM_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
      for (const width of [390, 1440]) {
        for (const view of [{ role: 'adviser', session: s.adviser }, { role: 'admin', session: s.admin }]) {
          const ctx = await browser.newContext({ viewport: { width, height: 900 }, storageState: await view.session.storageState(), reducedMotion: 'reduce' });
          const page = await ctx.newPage(); const errors = []; page.on('pageerror', e => errors.push(e.message));
          await page.goto(base + '/documents.php');
          await page.evaluate(() => window.PrismRequirements.open(40));
          const dialog = page.locator('.v14-checklist-dialog'); await dialog.waitFor({ timeout: 10000 });
          await dialog.getByText('Official RPMS resubmissions: 0 of 3 used', { exact: false }).waitFor({ timeout: 10000 });
          const forbidden = await page.getByRole('button', { name: 'Request correction case' }).count();
          const revisionBadge = await page.evaluate(() => window.PrismUI.badge('Resubmission Requested'));
          check(revisionBadge.includes('Revision Requested') && !revisionBadge.includes('>Resubmission Requested<'),
            width + 'px ' + view.role + ': legacy review enum renders as Revision Requested, not official IERB resubmission');
          const action = dialog.getByRole('button', { name: 'Open official resubmission' });
          const accessible = await action.count();
          const piText = await dialog.innerText();
          if (view.role === 'admin') {
            await action.press('Enter');
            const confirm = page.locator('[aria-modal="true"]:visible').last();
            await confirm.waitFor({ timeout: 5000 });
            const fits = await confirm.evaluate(el => el.getBoundingClientRect().width <= innerWidth + 1);
            const hasFields = await confirm.locator('input[name="affected"]').count() > 0 && await confirm.locator('#prismReason').count() === 1;
            await page.keyboard.press('Escape'); await page.waitForTimeout(200);
            check(fits && hasFields && !errors.length && forbidden === 0 && accessible === 1 && piText.includes('Principal Investigator'),
              width + 'px: only RPMS sees a keyboard-accessible official resubmission dialog with affected documents, reason and PI');
          } else {
            check(forbidden === 0 && accessible === 0 && !errors.length && piText.includes('Principal Investigator'),
              width + 'px: Adviser sees shared requirements but no formal resubmission initiation action');
          }
          await page.screenshot({ path: path.join(evidence, 'official-resubmission-' + view.role + '-' + width + '.png') });
          await ctx.close();
        }
      }
    } finally { await browser.close(); }
    await expect(open(s.admin, { revision: Number(wf().revision) - 1 }), 409,
      'Stale RPMS stage revision cannot open a case');
    await expect(open(s.admin, { requiresAmendment: undefined }), 422,
      'RPMS must explicitly choose whether an Amendment Form is required');
    const prior = fx({ action: 'seed-prior-resubmissions' });
    check(prior.count === 2, 'Two completed official RPMS resubmission cycles consume exactly two slots');
    const opened = await expect(open(s.admin), 200, 'RPMS opens the third and final official resubmission');
    const after = fx({ action: 'state' });
    check(after.workflow.stage_key === 'resubmission_amendment' && Number(after.case.requires_amendment) === 1 && after.case.interrupted_stage === 'initial_submission',
      'Third RPMS resubmission is entered with the required Amendment decision and its interrupted stage retained');
    const count = sql('SELECT COUNT(*) n FROM ierb_correction_cases WHERE workflow_id=?', [after.workflow.id])[0].n;
    check(Number(count) === 3, 'Three official cycles exist for the one canonical project; ordinary file revision versions do not add a cycle');
    const members = sql('SELECT stage_key FROM research_project_members WHERE project_id=?', [after.project.id]);
    check(members.length === 3 && members.every(m => m.stage_key === 'resubmission_amendment'), 'All verified members reflect formal correction stage consistently');
    await expect(open(s.admin), 409, 'RPMS cannot open a concurrent correction case');
    await expect(post(s.admin, 'v14_correction_resolve', { studentId: 40, revision: Number(wf().revision), reason: 'Synthetic premature resolution.', requestKey: key() }), 409,
      'A case cannot be resolved before corrected document evidence is accepted');
    const current = (await response(await m41.get('/documents_api.php?action=requirements&studentId=41'))).body;
    check(current.workflow.officialResubmissionCount === 3 && current.workflow.officialResubmissionLimit === 3,
      'All verified members can read that the RPMS three-cycle maximum has been reached');
    check(fx({ action: 'cap-close-open-case' }).closed,
      'Isolated fixture closes the third case without affecting production data');
    const rejected = await expect(open(s.admin), 409,
      'A fourth official resubmission is rejected after the previous three are closed');
    check(/three official IERB resubmissions/.test(rejected.body.message) &&
      Number(sql('SELECT COUNT(*) n FROM ierb_correction_cases WHERE workflow_id=?', [after.workflow.id])[0].n) === 3,
      'The fourth-cycle attempt is refused by the shared server guard without inserting a case');
    // V14 remains activated for report and retired-route checks below.
    const activeContract = await expect(response(await s.admin.get('/ierb_api.php?action=v14_contract')), 200,
      'The isolated V14 contract is readable with official-case limits active');
    check(activeContract.body.operationsActivated === true,
      'Legacy-route checks run with V14 HTTP operations explicitly activated');

    // --- 7R-D: Research Title policy (B7-F09), sorting (B7-F13) and AI report titles (B7-F08).
    const row = id => { const r = sql('SELECT * FROM students WHERE id=?', [id])[0]; return { id, studentId: r.student_id, name: r.full_name, email: r.email, groupId: r.research_group, academicUnitKey: r.academic_unit_key, programKey: r.program_key, yearLevel: r.year_level, academicYear: r.academic_year, requirements: r.requirements || '' }; };
    for (const attemptedTitle of ['', 'ab', 'Batch 7R Outsider Study – Revised']) {
      await expect(post(s.admin,'save',{...row(43),research:attemptedTitle},'ierb_api.php'),409,
        'Legacy IERB title editing is read-only under active V14');
    }
    check(sql("SELECT research_title FROM students WHERE id=43")[0].research_title === 'Batch 7R Outsider Study'
      && !sql("SELECT id FROM activity_logs WHERE action='research_title_changed' AND entity_id='43'").length,
      'Rejected legacy IERB title edits preserve original data without audit mutations');
    await expect(post(s.admin, 'save', { ...row(41), research: 'Unaudited canonical rename attempt' }), 409,
      'Canonical project titles cannot be changed by the retired individual-stage endpoint');
    // Closure (B7C-01): the Student Records edit path applies the same Research Title policy as IERB entry.
    // Same payload shape as the Student Records modal: the stored group is sent unchanged (row().groupId).
    const recordEdit = research => post(s.admin, 'save', { ...row(43), adviserId: 2, stage: 'Stage 1', status: 'Pending', research }, 'students_api.php');
    const erased = await expect(recordEdit(''), 422, 'Student Records cannot erase an existing Research Title');
    const short = await expect(recordEdit('ab'), 422, 'Student Records requires at least 3 characters for a changed Research Title');
    check(/cannot be erased/.test(erased.body.message) && /3–255/.test(short.body.message), 'Both refusals come from the Research Title policy: ' + JSON.stringify([erased.body.message, short.body.message]));
    await expect(recordEdit('Batch 7R Outsider Study – Records Edit'), 200, 'Student Records saves a valid Research Title change');
    const recordAudit = sql("SELECT before_value,after_value,details FROM activity_logs WHERE action='research_title_changed' AND entity_id='43' ORDER BY id DESC LIMIT 1")[0];
    check(recordAudit?.before_value === 'Batch 7R Outsider Study' && recordAudit?.after_value === 'Batch 7R Outsider Study – Records Edit' && recordAudit.details.includes('Student Records edit'),
      'The Student Records title change is audited before/after');
    const reportsList = await expect(response(await s.admin.get('/reports_api.php?action=list&sort=oldest')), 200, 'Reports list accepts an allowlisted sort');
    await expect(response(await s.admin.get('/reports_api.php?action=list&sort=random')), 422, 'Unsupported report sort orders are refused');
    const generated = await expect(post(s.admin, 'ai_report', { mode: 'full' }, 'reports_api.php'), 200, 'AI Full report generates');
    const text = fx({ action: 'pdf-text', filename: 'ai_v2_' + generated.body.report.id + '.pdf' }).text;
    check(text.includes('Canonical research projects') && text.includes('Peñaflor') && text.includes('counted once') && !text.includes('Batch 7R Outsider Study'),
      'V14 AI report contains canonical research titles with accents, counts projects once, and excludes unrelated legacy Student records');
    check(text.includes('Limitations and human verification'), 'The AI report states its limitations and the human verification step');
    const newest = (await response(await s.admin.get('/reports_api.php?action=list'))).body.reports[0];
    check(newest?.id === generated.body.report.id && reportsList.status === 200, 'Reports default to newest generated first');
    const today = new Date(Date.now() + 8 * 3600e3).toISOString().slice(0, 10);
    await expect(response(await s.admin.get('/calendar_deadlines_api.php?action=list&from=' + today + '&to=' + today + '&sort=upcoming')), 200, 'Deadlines accept the nearest-upcoming sort');
    const badSort = await response(await s.admin.get('/calendar_deadlines_api.php?action=list&from=' + today + '&to=' + today + '&sort=random'));
    check(badSort.status >= 400 && badSort.status < 500, 'Unsupported deadline sort orders are refused');
    const records = await expect(response(await s.admin.get('/students_api.php?action=list&sortBy=updated&direction=DESC')), 200, 'Student Records accept newest-updated sorting');
    const ids = records.body.students.map(r => Number(r.id)).filter(Boolean);
    const stamps = ids.map(id => String(sql('SELECT updated_at FROM students WHERE id=?', [id])[0].updated_at));
    check(ids.length > 1 && stamps.every((v, i) => i === 0 || stamps[i - 1] >= v), 'Student Records are ordered newest updated first (verified against the database)');

    // --- 7R-A: runtime preflight recognizes the migrated schema and its contract.
    const pre = JSON.parse(cp.spawnSync(php, [path.join(root, 'maintenance/runtime-preflight.php'), '--database'], { encoding: 'utf8', windowsHide: true,
      env: { ...process.env, PRISM_PREFLIGHT_DB_DSN: 'mysql:host=127.0.0.1;port=' + (process.env.PRISM_TEST_DB_PORT || '13527') + ';dbname=prism_group_synthetic_v11', PRISM_PREFLIGHT_DB_USER: 'root', PRISM_PREFLIGHT_DB_PASSWORD: 'prism_synthetic_only' } }).stdout || '{}');
    check(pre.database?.ok === true && pre.database.contract === 'PASS' && pre.database.schema_version === sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v, 'Runtime preflight passes the reviewed contract at the migrated schema (B7-F06)');
    fs.writeFileSync(path.join(evidence, 'batch7r-integration.json'), JSON.stringify({ collation: coll, project: after.project, pi: after.pi, case: after.case, race: race.map(r => r.status), preflight: pre.database }, null, 2));
  } finally {
    fs.rmSync(flag, { force: true });
    try { fx({ action: 'cleanup' }); } catch (e) { console.error('Batch 7R cleanup failed: ' + e.message); }
  }
  check(h.checks.length - start > 60, 'Batch 7R suite executed ' + (h.checks.length - start) + ' assertions');
};
