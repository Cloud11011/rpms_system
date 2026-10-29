// Batch 6 acceptance: forms, required markers, numeric Student IDs, submit/cancel behavior,
// deletion confirmations, account UX and Student/Adviser accents. Synthetic fixture data only.
const fs = require('node:fs'), path = require('node:path'), cp = require('node:child_process'), crypto = require('node:crypto');

module.exports = async h => {
  const { root, php, post, response, expect, check, sessions: s, playwright, base } = h;
  const sql = (...args) => { const value = h.sql(...args); if (value.status >= 400) throw Error('Fixture SQL failed: ' + JSON.stringify(value)); return value; };
  const evidence = path.resolve(process.env.PRISM_TEST_EVIDENCE_DIR || path.join(root, 'PRISM_V14_BATCH_06_EVIDENCE')); fs.mkdirSync(evidence, { recursive: true });
  const start = h.checks.length;
  const schema = sql("SELECT v FROM schema_meta WHERE k='schema_version'")[0].v;
  const tag = 'schema ' + schema + ': ';
  const docsFixture = data => JSON.parse(cp.execFileSync(php, [path.join(root, 'maintenance/ierb-v14-tests/documents-fixture.php')], { input: JSON.stringify(data), encoding: 'utf8', windowsHide: true }));
  const unit = data => JSON.parse(cp.execFileSync(php, [path.join(__dirname, 'fixture.php')], { input: JSON.stringify(data), encoding: 'utf8', windowsHide: true }));
  const guide = value => { const dot = value.indexOf('.'); return `prismTour.${value.slice(0, dot)}.${crypto.createHash('sha256').update(value.slice(dot + 1)).digest('hex')}.v1`; };
  const guideKeys = ['admin.admin|1', 'adviser.adviser|2', 'student.Student|S4'].map(guide);
  const academic = { academicUnitKey: 'amt', programKey: 'bsit', yearLevel: '4th Year', academicYear: '2026-2027' };
  const emails = [];
  const studentPayload = (studentId, email, extra = {}) => { emails.push(email); return { id: 0, studentId, name: 'Batch6 Synthetic ' + studentId, email, research: 'Batch6 synthetic research title', group: '', adviserId: 2, stage: 'Stage 1', status: 'On Track', ...academic, ...extra }; };
  const docIds = [];
  const insertDoc = (id, fields) => {
    docIds.push(id);
    const row = { student_id: 4, student_name: 'Synthetic Student 4', uploaded_by: 'Synthetic Student 4', uploaded_by_role: 'student', original_name: id + '.pdf', stored_name: id + '-stored.pdf', mime: 'application/pdf', size: 1200,
      document_type: 'Study Protocol', stage: 'Stage 1', review_status: 'Pending', version_no: 1, is_current: 1, supersedes_id: null, rpms_submitted_at: null, uploaded_at: '2026-10-20 09:00:00', ...fields };
    const cols = Object.keys(row);
    sql(`INSERT INTO documents (id,${cols.join(',')}) VALUES (?,${cols.map(() => '?').join(',')})`, [id, ...cols.map(c => row[c])]);
  };
  // Remove only this module's synthetic enrollment; never roll back the fixture schema.
  const unenroll = () => { for (const { id } of sql('SELECT id FROM ierb_workflows WHERE student_id=4')) { sql('DELETE FROM ierb_workflow_events WHERE workflow_id=?', [id]); sql('DELETE FROM ierb_correction_cases WHERE workflow_id=?', [id]); sql('DELETE FROM ierb_workflows WHERE id=?', [id]); } };
  let browser, enrolled = false;
  try {
    // ------------------------------------------------------------- server-side Student ID policy
    for (const [value, ok] of [['2026123456', true], ['0026123456', true], ['2026ABC123', false], ['STUDENT-123', false], ['2026 123', false], ['１２３', false], ['-123', false], ['12.5', false]]) {
      check(unit({ action: 'student_id', value }).valid === ok, 'Shared server Student ID rule ' + (ok ? 'accepts ' : 'rejects ') + JSON.stringify(value));
    }
    check(unit({ action: 'student_id', value: 'S5', existing: 'S5' }).valid && !unit({ action: 'student_id', value: 'S5X', existing: 'S5' }).valid,
      'Unchanged historical alphanumeric ID remains valid; a changed ID must be numeric');
    check(unit({ action: 'onboarding_numeric' }).rejected, 'Student profile completion rejects a non-numeric new Student ID before any write');

    for (const bad of ['2026ABC123', 'STUDENT-123', '2026 123']) {
      await expect(post(s.admin, 'save', studentPayload(bad, `batch6-bad-${emails.length}@example.test`), 'students_api.php'), 422, tag + 'Student Records rejects non-numeric new Student ID ' + bad);
      await expect(post(s.admin, 'save', { ...studentPayload(bad, `batch6-badi-${emails.length}@example.test`), groupId: '' }, 'ierb_api.php'), 422, tag + 'IERB entry rejects non-numeric new Student ID ' + bad);
    }
    check(!sql("SELECT id FROM students WHERE email LIKE 'batch6-bad%'").length, tag + 'Rejected IDs create no Student or login rows');
    const created = await expect(post(s.admin, 'save', studentPayload('0026600001', 'batch6-lead@example.test'), 'students_api.php'), 200, tag + 'Valid numeric Student ID with leading zeros is saved');
    check(sql('SELECT student_id FROM students WHERE id=?', [created.body.id])[0].student_id === '0026600001' && sql("SELECT username FROM users WHERE email='batch6-lead@example.test'")[0].username === '0026600001',
      tag + 'Leading zeros are preserved in the Student record and login identity');
    await expect(post(s.admin, 'save', studentPayload('0026600001', 'batch6-dup@example.test'), 'students_api.php'), 409, tag + 'Duplicate Student ID is rejected');
    await expect(post(s.admin, 'save', { ...studentPayload('26600002', 'batch6-notitle@example.test'), research: '', groupId: '' }, 'ierb_api.php'), 422, tag + 'IERB entry server validation requires the research title marked in the form');
    // Schema 13+ deliberately forbids new group creation through IERB entry (canonical projects); creation is checked on 11/12.
    const legacyGroups = ['11', '12'].includes(schema);
    if (legacyGroups) await expect(post(s.admin, 'save', { ...studentPayload('26600003', 'batch6-ierb@example.test'), group: '__create__', groupId: '__create__' }, 'ierb_api.php'), 200, tag + 'IERB entry accepts a valid numeric Student ID');
    const edited = sql('SELECT * FROM students WHERE id=?', [created.body.id])[0];
    const editPayload = studentId => ({ ...studentPayload(studentId, edited.email), id: edited.id, name: edited.full_name, research: edited.research_title || '', adviserId: edited.adviser_id, stage: edited.stage, status: edited.status, group: '__keep__' });
    await expect(post(s.admin, 'save', editPayload('0026600009'), 'students_api.php'), 200, tag + 'A changed Student ID may be corrected to another numeric value');
    await expect(post(s.admin, 'save', editPayload('B6-CHANGED'), 'students_api.php'), 422, tag + 'A changed Student ID cannot become alphanumeric');
    // Model a historical record: an alphanumeric ID stored before this rule existed.
    sql("UPDATE students SET student_id='B6-LEGACY-01' WHERE id=?", [edited.id]);
    await expect(post(s.admin, 'save', { ...editPayload('B6-LEGACY-01'), research: 'Batch6 updated research title' }, 'students_api.php'), 200, tag + 'Historical alphanumeric Student ID record can still be edited when its ID is unchanged');
    check(sql('SELECT student_id,research_title FROM students WHERE id=?', [edited.id]).every(r => r.student_id === 'B6-LEGACY-01' && r.research_title === 'Batch6 updated research title'), tag + 'Legacy identifier is preserved, never rewritten');
    await expect(post(s.admin, 'save', editPayload('B6-LEGACY-02'), 'students_api.php'), 422, tag + 'Changing a historical ID still requires a numeric value');
    check(sql('SELECT student_id FROM students WHERE id=5')[0].student_id === 'S5', tag + 'Seeded legacy Student ID S5 is untouched by Batch 6');
    const login = await playwright.request.newContext({ baseURL: base });
    try {
      const r = await login.post('/login_process.php', { form: { email: 'student5@example.test', password: 'SyntheticPass123!' }, headers: { origin: base }, maxRedirects: 0 });
      check(r.status() === 302 && !/login\.php/.test(r.headers().location || ''), tag + 'Legacy-ID Student can still sign in');
    } finally { await login.dispose(); }

    // ------------------------------------------------------------- deletion API
    const xssName = 'batch6-<img src=x onerror=window.__b6=1>-protocol';
    insertDoc('b6aa00000000000000000001', { original_name: xssName + '-v1.pdf', is_current: 0, version_no: 1 });
    insertDoc('b6aa00000000000000000002', { original_name: xssName + '-v2.pdf', version_no: 2, supersedes_id: 'b6aa00000000000000000001', uploaded_by: 'Synthetic <b>Student</b> 4' });
    insertDoc('b6aa00000000000000000003', { original_name: 'batch6-unattributed.pdf', document_type: 'Curriculum Vitae', uploaded_by: '', uploaded_by_role: null });
    insertDoc('b6aa00000000000000000004', { original_name: 'batch6-submitted.pdf', document_type: 'Payment Receipt', review_status: 'Approved', rpms_submitted_at: '2026-10-21 10:00:00' });
    insertDoc('b6aa00000000000000000005', { original_name: 'batch6-stale.pdf', document_type: 'Letter to IERB Chair' });
    insertDoc('b6aa00000000000000000006', { original_name: 'batch6-bulk-a.pdf', document_type: 'Form 11 Review Checklist', uploaded_by: 'Synthetic Adviser 2', uploaded_by_role: 'adviser' });
    insertDoc('b6aa00000000000000000007', { original_name: 'batch6-bulk-b.pdf', document_type: 'Form 12 Registration and Application Form' });
    insertDoc('b6aa00000000000000000008', { original_name: 'batch6-browser.pdf', document_type: 'Diagrammatic Workflow' });
    const preview = (ctx, id) => ctx.get('/documents_api.php?action=delete_preview&id=' + id).then(response);
    await expect(preview(s.student, 'b6aa00000000000000000002'), 403, tag + 'Student cannot open a staff deletion confirmation');
    const other = await h.login('adviser3@example.test');
    await expect(preview(other, 'b6aa00000000000000000002'), 403, tag + 'Unassigned Adviser cannot view deletion details');
    await expect(post(other, 'delete', { id: 'b6aa00000000000000000002' }, 'documents_api.php'), 403, tag + 'Unassigned Adviser cannot delete');
    const anonymous = await playwright.request.newContext({ baseURL: base });
    try { await expect(preview(anonymous, 'b6aa00000000000000000002'), 401, tag + 'Anonymous deletion preview is denied'); } finally { await anonymous.dispose(); }
    const p2 = (await expect(preview(s.adviser, 'b6aa00000000000000000002'), 200, tag + 'Assigned Adviser loads the authoritative deletion record')).body.document;
    check(p2.originalName === xssName + '-v2.pdf' && p2.uploadedBy === 'Synthetic <b>Student</b> 4' && p2.uploaderRole === 'student' && p2.versionNo === 2 && p2.isCurrent && p2.restoresPrevious && p2.documentType === 'Study Protocol' && /^[a-f0-9]{64}$/.test(p2.expectedVersion),
      tag + 'Preview returns exact filename, recorded uploader, type, version and previous-version restoration');
    check((await preview(s.admin, 'b6aa00000000000000000003')).body.document.uploadedBy === null, tag + 'Missing uploader metadata is reported as not recorded, never invented');
    const locked = (await preview(s.adviser, 'b6aa00000000000000000004')).body.document;
    check(locked.locked && !locked.canDelete && /RPMS administrator/.test(locked.blockedReason), tag + 'Formally submitted document is protected from Adviser deletion in the preview');
    await expect(post(s.adviser, 'delete', { id: 'b6aa00000000000000000004' }, 'documents_api.php'), 403, tag + 'Adviser deletion of a formally submitted document is rejected');
    const adminLocked = (await preview(s.admin, 'b6aa00000000000000000004')).body.document;
    check(adminLocked.canDelete && adminLocked.requiresReason, tag + 'RPMS deletion of a submitted document requires an audited reason');
    await expect(post(s.admin, 'delete', { id: 'b6aa00000000000000000004', reason: '', expectedVersion: adminLocked.expectedVersion }, 'documents_api.php'), 422, tag + 'Reason requirement is preserved for submitted documents');
    const stale = (await preview(s.admin, 'b6aa00000000000000000005')).body.document;
    sql("UPDATE documents SET notes='Changed after review' WHERE id='b6aa00000000000000000005'");
    const staleDelete = await expect(post(s.admin, 'delete', { id: 'b6aa00000000000000000005', expectedVersion: stale.expectedVersion }, 'documents_api.php'), 409, tag + 'A document changed after confirmation review is not deleted');
    check(!staleDelete.body.ok && sql("SELECT id FROM documents WHERE id='b6aa00000000000000000005'").length === 1, tag + 'Failed stale deletion does not report success and the record remains');
    await expect(post(s.admin, 'delete', { id: 'b6aa00000000000000000005', expectedVersion: 'not-a-fingerprint' }, 'documents_api.php'), 422, tag + 'Malformed reviewed-version binding is rejected');
    const before = sql('SELECT COUNT(*) n FROM documents')[0].n;
    sql("CREATE TRIGGER batch6_delete_failure BEFORE DELETE ON documents FOR EACH ROW BEGIN IF OLD.id='b6aa00000000000000000007' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic delete failure'; END IF; END");
    try {
      const fresh = (await preview(s.admin, 'b6aa00000000000000000007')).body.document;
      const failed = await expect(post(s.admin, 'delete', { id: 'b6aa00000000000000000007', expectedVersion: fresh.expectedVersion }, 'documents_api.php'), 500, tag + 'Persistence failure is reported as a failure');
      check(!failed.body.ok && sql('SELECT COUNT(*) n FROM documents')[0].n === before, tag + 'Failed deletion rolls back and never claims success');
    } finally { sql('DROP TRIGGER batch6_delete_failure'); }
    const bulk = await expect(post(s.admin, 'bulk_delete_preview', { ids: ['b6aa00000000000000000006', 'b6aa00000000000000000007'], expectedVersions: Object.fromEntries((await s.admin.get('/documents_api.php?action=list&perPage=100&q=batch6-bulk').then(response)).body.documents.map(d => [d.id, d.selectionVersion])) }, 'documents_api.php'), 200, tag + 'Bulk preview resolves the selected documents on the server');
    check(bulk.body.records.length === 2 && bulk.body.records.map(r => r.originalName).sort().join() === 'batch6-bulk-a.pdf,batch6-bulk-b.pdf'
      && bulk.body.records.find(r => r.originalName === 'batch6-bulk-a.pdf').uploadedBy === 'Synthetic Adviser 2' && bulk.body.records.every(r => r.documentType && r.versionNo === 1),
      tag + 'Bulk confirmation lists exactly the selected filenames, uploaders, types and versions');
    await expect(post(s.adviser, 'bulk_delete_preview', { ids: ['b6aa00000000000000000006'], expectedVersions: {} }, 'documents_api.php'), 403, tag + 'Adviser cannot use Admin bulk deletion');
    if (schema !== '11') {
      const enrolledResult = docsFixture({ action: 'enroll', studentId: 4 });
      enrolled = !!enrolledResult.ok;
      const v14 = (await preview(s.admin, 'b6aa00000000000000000003')).body.document;
      check(enrolled && !v14.canDelete && /retained as workflow evidence/.test(v14.blockedReason), tag + 'V14 workflow evidence is shown as protected in the confirmation');
      await expect(post(s.admin, 'delete', { id: 'b6aa00000000000000000003', expectedVersion: v14.expectedVersion }, 'documents_api.php'), 409, tag + 'V14 retained evidence cannot be deleted');
      unenroll(); enrolled = false;
    }

    // ------------------------------------------------------------- browser
    browser = await playwright.chromium.launch({ executablePath: process.env.PRISM_TEST_CHROME || 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const errors = [];
    const open = async (role, url, { width = 1440, theme = 'light', touch = false } = {}) => {
      const ctx = await browser.newContext({ viewport: { width, height: 1000 }, storageState: await s[role].storageState(), reducedMotion: 'reduce', hasTouch: touch });
      await ctx.addInitScript(({ theme, keys }) => { localStorage.setItem('prismTheme', theme); keys.forEach(k => localStorage.setItem(k, 'seen')); }, { theme, keys: guideKeys });
      await ctx.route(/^https:\/\/(?:fonts\.googleapis\.com|fonts\.gstatic\.com)\//, route => route.fulfill({ contentType: 'text/css', body: '' }));
      const page = await ctx.newPage(); page.on('pageerror', e => errors.push(url + ': ' + String(e)));
      await page.goto(base + '/' + url); await page.waitForLoadState('networkidle');
      if (role === 'admin') { await page.mouse.move(width - 20, 60); await page.keyboard.press('Escape'); } // Close the hover sidebar like a user.
      return { ctx, page };
    };
    const markers = page => page.evaluate(() => [...document.querySelectorAll('.prism-required-mark[data-prism-mark]')].filter(m => m.getClientRects().length).map(m => {
      const label = m.closest('label'); const control = label?.control || document.getElementById(label?.htmlFor);
      return control?.id || control?.name || label?.textContent.trim();
    }));
    // Search, let the list settle, then open the row's delete action (direct or inside its action menu).
    const deleteDialog = async (page, search, name) => {
      if (search !== null) { await page.fill('#documentSearch', search); }
      await page.waitForFunction(name => { const body = document.querySelector('#documentsTableBody'); return body && body.getAttribute('aria-busy') !== 'true' && body.innerText.includes(name); }, name);
      await page.waitForTimeout(250);
      for (let attempt = 0; attempt < 3; attempt++) {
        try {
          const row = page.locator('#documentsTableBody tr').filter({ hasText: name }).first();
          const trigger = row.locator('.prism-action-trigger');
          if (await trigger.count()) { await trigger.click(); await page.locator('[data-delete]:visible').first().click({ timeout: 4000 }); }
          else await row.locator('[data-delete]').click({ timeout: 4000 });
          const dialog = page.getByRole('dialog', { name: 'Delete Document?' }); await dialog.waitFor({ timeout: 8000 }); return dialog;
        } catch (error) { if (attempt === 2) throw error; await page.keyboard.press('Escape'); await page.waitForTimeout(300); }
      }
    };
    const overflow = page => page.evaluate(() => document.documentElement.scrollWidth > innerWidth + 1);
    // F02: the entry modal once stayed hidden after a delivered click (7R fx1232 run 01). Keep the evidence, then fail as before.
    const openEntry = async (page, label) => {
      await page.click('#addIerbEntry');
      try { await page.locator('#ierbEntryModal').waitFor({ state: 'visible' }); }
      catch (error) {
        await page.screenshot({ path: path.join(evidence, `ierb-entry-failure-${label}.png`) }).catch(() => {});
        fs.writeFileSync(path.join(evidence, `ierb-entry-failure-${label}.json`), JSON.stringify({ pageErrors: errors, state: await page.evaluate(() => {
          const b = document.getElementById('addIerbEntry'), m = document.getElementById('ierbEntryModal'), r = b?.getBoundingClientRect(), hit = r && document.elementFromPoint(r.x + r.width / 2, r.y + r.height / 2);
          return { url: location.href, readyState: document.readyState, navigation: performance.getEntriesByType('navigation').map(e => e.type), modal: m && { display: m.style.display, ariaHidden: m.getAttribute('aria-hidden') },
            button: r && { rect: r.toJSON(), hit: hit && (hit.id || String(hit.className) || hit.tagName) }, sidebar: document.querySelector('.prism-sidebar')?.className, active: document.activeElement?.id || document.activeElement?.tagName,
            dialogs: [...document.querySelectorAll('dialog[open],[aria-modal="true"]')].map(d => d.id || String(d.className)) };
        }).catch(e => String(e)) }, null, 2));
        throw error;
      }
    };
    const lum = c => { const [r, g, b] = c.match(/\d+(\.\d+)?/g).slice(0, 3).map(Number).map(v => { v /= 255; return v <= .03928 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4; }); return .2126 * r + .7152 * g + .0722 * b; };
    const contrast = (a, b) => { const [x, y] = [lum(a), lum(b)].sort((m, n) => n - m); return (x + .05) / (y + .05); };

    // IERB entry: markers, placeholders, numeric ID, paste, gating, Cancel, double submit.
    {
      const { ctx, page } = await open('admin', 'ierbprog.php');
      await openEntry(page, 'first');
      await page.waitForTimeout(150);
      const marked = await markers(page);
      check(['entryStudentName', 'entryStudentId', 'entryEmail', 'entryResearchTitle', 'entryGroupId'].every(id => marked.includes(id)) && !['entryRequirements', 'entrySubmissionDate', 'entryStage', 'entryStatus'].some(id => marked.includes(id)),
        'IERB entry marks exactly its server-required fields; optional and read-only fields have no asterisk');
      const markerStyle = await page.locator('label[for=entryStudentId] .prism-required-mark').evaluate(m => ({ color: getComputedStyle(m).color, hidden: m.getAttribute('aria-hidden') }));
      check(markerStyle.hidden === 'true' && /rgb\(198, 40, 40\)/.test(markerStyle.color), 'Required asterisk is red and hidden from the accessible name');
      check(await page.locator('#entryStudentId').evaluate(e => e.required && e.inputMode === 'numeric' && e.type === 'text'), 'Student ID is a required text field with numeric keyboard hint (leading zeros preserved)');
      check(await page.getByRole('textbox', { name: 'Student ID', exact: true }).count() === 1, 'Accessible name stays "Student ID" without the visual asterisk');
      const placeholders = await page.$$eval('#ierbEntryForm input:not([type=date]):not([type=hidden]), #ierbEntryForm textarea', els => els.filter(e => !e.disabled).map(e => [e.id, e.placeholder]));
      check(placeholders.every(([, p]) => p) && placeholders.find(([id]) => id === 'entryResearchTitle')[1] === 'Enter the complete research title', 'Every editable IERB text field has a context-appropriate placeholder');
      const save = page.locator('#ierbEntryForm [type=submit]');
      check(await save.isDisabled() && /required fields/.test(await page.locator('#ierbEntryForm .prism-gate-status').innerText()), 'Save is disabled with an explanation while required data is missing');
      await page.locator('#entryStudentId').pressSequentially('2026ABC');
      { const typed = { value: await page.locator('#entryStudentId').inputValue(), message: await page.locator('#entryStudentIdPrismMessage').textContent() };
      check(typed.value === '2026' && /numbers only/i.test(typed.message), 'Typed letters are blocked with an accessible numbers-only message' + (typed.value === '2026' && /numbers only/i.test(typed.message) ? '' : ' ' + JSON.stringify(typed))); }
      await page.locator('#entryStudentId').pressSequentially('123-');
      check(await page.locator('#entryStudentId').inputValue() === '2026123', 'Only digits are accepted while typing (2026ABC123- becomes 2026123)');
      await page.locator('#entryStudentId').fill('');
      const paste = async text => page.locator('#entryStudentId').evaluate((e, text) => { const dt = new DataTransfer(); dt.setData('text', text); e.focus(); e.dispatchEvent(new ClipboardEvent('paste', { clipboardData: dt, bubbles: true, cancelable: true })); }, text);
      await paste('STUDENT-123');
      check(await page.locator('#entryStudentId').inputValue() === '' && /pasted value/i.test(await page.locator('#entryStudentIdPrismMessage').innerText()), 'Invalid pasted Student ID is rejected, not silently rewritten');
      await paste('  0026600011 ');
      check(await page.locator('#entryStudentId').inputValue() === '0026600011', 'Valid pasted Student ID keeps leading zeros and only trims surrounding spaces');
      await page.locator('#entryStudentId').evaluate(e => { e.value = '12A'; e.dispatchEvent(new Event('input', { bubbles: true })); });
      check(await page.locator('#entryStudentId').evaluate(e => !e.validity.valid && e.getAttribute('aria-invalid') === 'true'), 'Programmatic/autofill non-digit values are marked invalid');
      const legacyState = await page.locator('#entryStudentId').evaluate(e => {
        const set = v => { e.value = v; e.dispatchEvent(new Event('input', { bubbles: true })); };
        window.PrismForms.setLegacyValue(e, 'S5'); set('S5');
        const kept = { valid: e.validity.valid, note: document.getElementById('entryStudentIdPrismMessage').textContent };
        set('S5X'); const changed = e.validity.valid;
        window.PrismForms.setLegacyValue(e, null); set('');
        return { kept, changed };
      });
      check(legacyState.kept.valid && /retained as recorded/.test(legacyState.kept.note) && !legacyState.changed, 'Editing a historical record keeps its unchanged alphanumeric ID valid; a changed ID must be numeric');
      emails.push('batch6-browser@example.test');
      const fillEntry = async (withGroup = true) => {
        await page.locator('#entryStudentId').fill('0026600011'); await page.fill('#entryStudentName', 'Batch6 Browser Student');
        await page.fill('#entryEmail', 'batch6-browser@example.test'); await page.fill('#entryResearchTitle', 'Batch6 browser research title');
        for (const id of ['entryAcademicUnit', 'entryCourse', 'entryYearLevel', 'entryAcademicYear', ...(withGroup ? ['entryGroupId'] : [])]) {
          await page.waitForFunction(id => [...document.getElementById(id).options].some(o => o.value && !o.disabled), id);
          const value = await page.$eval('#' + id, e => [...e.options].find(o => o.value && !o.disabled && o.value !== '__create__')?.value || [...e.options].find(o => o.value && !o.disabled).value);
          await page.selectOption('#' + id, value);
        }
      };
      await fillEntry(legacyGroups);
      if (legacyGroups) {
        await page.waitForFunction(() => !document.querySelector('#ierbEntryForm [type=submit]').disabled);
        check(await save.isEnabled(), 'Save becomes enabled once all required data is valid');
      } else check(await save.isDisabled(), tag + 'Save stays disabled while the required research group is not chosen');
      // Cancel never submits and closes the dialog.
      let posts = 0; page.on('request', r => { if (r.url().includes('ierb_api.php?action=save')) posts++; });
      await page.click('#cancelIerbEntry');
      check(!(await page.locator('#ierbEntryModal').isVisible()) && posts === 0, 'Cancel closes the IERB entry dialog without saving');
      await openEntry(page, 'reopen');
      check(await save.isDisabled(), 'Reopened Add dialog starts from an empty, disabled state');
      if (legacyGroups) {
      await fillEntry();
      await page.waitForFunction(() => !document.querySelector('#ierbEntryForm [type=submit]').disabled);
      await page.route('**/ierb_api.php?action=save', async route => { await h.sleep(500); await route.continue(); });
      await save.click(); await save.click({ force: true }).catch(() => {}); await page.locator('#entryResearchTitle').press('Enter').catch(() => {});
      check(await save.isDisabled() && /Saving/.test(await save.innerText()), 'Save shows a loading state and is disabled while the request is pending');
      await page.locator('#ierbEntryModal').waitFor({ state: 'hidden' });
      check(posts === 1 && sql("SELECT student_id FROM students WHERE email='batch6-browser@example.test'").map(r => r.student_id).join() === '0026600011',
        'Rapid clicks and Enter create exactly one record, preserving leading zeros');
      }
      await ctx.close();
    }

    // Admin document deletion dialog: authoritative details, escaping, Cancel, Escape, single request.
    {
      const { ctx, page } = await open('admin', 'documents.php');
      await page.fill('#documentSearch', 'batch6-'); await page.locator('#documentsTableBody[aria-busy="false"]').waitFor();
      await page.waitForFunction(() => document.querySelector('#documentsTableBody').innerText.includes('batch6-browser.pdf'));
      const openDelete = name => deleteDialog(page, null, name);
      let dialog = await openDelete(xssName + '-v2.pdf');
      const text = await dialog.innerText();
      check(text.includes(xssName + '-v2.pdf') && text.includes('Synthetic <b>Student</b> 4 (Student)') && text.includes('Version 2 (current version)') && text.includes('Study Protocol') && text.includes('cannot be undone') && text.includes('previous version will become the current'),
        'Confirmation shows exact filename, recorded uploader, type, version and permanent-deletion warning');
      check(await dialog.locator('img, b').count() === 0 && await page.evaluate(() => window.__b6 === undefined), 'Filename and uploader HTML is rendered as inert text');
      check(!/â€|Ã/.test(text) && await dialog.locator('[data-act="cancel"]').evaluate(e => e === document.activeElement), 'Confirmation text has correct encoding and focuses Cancel by default');
      await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Shift+Tab'); await page.keyboard.press('Tab');
      check(await dialog.evaluate(d => d.contains(document.activeElement)), 'Focus remains trapped inside the deletion dialog');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      check(sql("SELECT id FROM documents WHERE id='b6aa00000000000000000002'").length === 1, 'Cancel performs no deletion');
      dialog = await openDelete(xssName + '-v2.pdf'); await page.keyboard.press('Escape');
      check(!(await dialog.isVisible()) && sql("SELECT id FROM documents WHERE id='b6aa00000000000000000002'").length === 1, 'Escape closes the dialog without deleting');
      dialog = await openDelete('batch6-unattributed.pdf');
      check((await dialog.innerText()).includes('Uploaded by\nNot recorded') || /Uploaded by\s*Not recorded/.test(await dialog.innerText()), 'Missing uploader is shown as Not recorded');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      dialog = await openDelete('batch6-submitted.pdf');
      check(await dialog.locator('#prismReason').evaluate(e => e.required && e.placeholder === 'Explain the reason for this action') && await dialog.locator('label[for=prismReason] .prism-required-mark').count() === 1,
        'Submitted-document deletion reason is required, marked and has a placeholder');
      await dialog.getByRole('button', { name: 'Delete Document' }).click();
      check(/Add a reason/.test(await dialog.locator('.prism-field-error').innerText()) && sql("SELECT id FROM documents WHERE id='b6aa00000000000000000004'").length === 1, 'Missing reason keeps the dialog open and deletes nothing');
      check(await dialog.locator('#prismReason').evaluate(e => e.getAttribute('aria-invalid') === 'true' && /Add a reason/.test(document.getElementById(e.getAttribute('aria-describedby'))?.textContent || '')),
        'The missing-reason error marks the reason field invalid and is programmatically linked to it (F03)');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      let deletes = 0; page.on('request', r => { if (r.url().endsWith('documents_api.php?action=delete')) deletes++; });
      await page.route('**/documents_api.php?action=delete', async route => { await h.sleep(500); await route.continue(); });
      dialog = await openDelete('batch6-browser.pdf');
      const confirmButton = dialog.getByRole('button', { name: 'Delete Document' });
      await confirmButton.click(); await confirmButton.click({ force: true, timeout: 300 }).catch(() => {}); await page.keyboard.press('Enter');
      check(/Deleting/.test(await dialog.locator('[data-act=ok]').innerText()) && await dialog.locator('[data-act=cancel]').isDisabled(), 'Delete shows a pending state and blocks repeated actions');
      await dialog.waitFor({ state: 'hidden' });
      check(deletes === 1 && !sql("SELECT id FROM documents WHERE id='b6aa00000000000000000008'").length, 'Confirmed deletion sends exactly one request and removes only that document');
      await page.unroute('**/documents_api.php?action=delete');
      // Server-side failure is reported in place and the dialog can be cancelled safely.
      await page.route('**/documents_api.php?action=delete', route => route.fulfill({ status: 409, contentType: 'application/json', body: JSON.stringify({ ok: false, message: 'This document changed after you reviewed it. Nothing was deleted; review the current details and try again.' }) }));
      dialog = await openDelete('batch6-unattributed.pdf');
      await dialog.getByRole('button', { name: 'Delete Document' }).click();
      await dialog.locator('.prism-field-error').filter({ hasText: 'Nothing was deleted' }).waitFor();
      check(await dialog.isVisible() && await dialog.locator('[data-act=ok]').isDisabled() && sql("SELECT id FROM documents WHERE id='b6aa00000000000000000003'").length === 1, 'A rejected deletion is reported as a failure, not success, and cannot be blindly retried');
      await dialog.getByRole('button', { name: 'Cancel' }).click();
      await page.unroute('**/documents_api.php?action=delete');
      // Bulk review list.
      await page.fill('#documentSearch', 'batch6-bulk'); await page.waitForFunction(() => document.querySelector('[data-document-select=b6aa00000000000000000006]') && document.querySelector('[data-document-select=b6aa00000000000000000007]') && !document.querySelector('#documentsTableBody').innerText.includes('batch6-stale'));
      for (const id of ['b6aa00000000000000000006', 'b6aa00000000000000000007']) await page.locator(`[data-document-select=${id}]`).first().check();
      await page.waitForFunction(() => document.getElementById('documentSelectedCount').textContent.startsWith('2'));
      await page.click('#bulkDeleteDocuments');
      await page.locator('#bulkDeleteModal.show, #bulkDeleteModal[aria-hidden="false"]').first().waitFor();
      const items = await page.$$eval('#bulkDeleteRecords li', lis => lis.map(li => li.innerText));
      check(items.length === 2 && await page.locator('#bulkDeleteRecordTotal').innerText() === '2' && items.some(t => t.includes('batch6-bulk-a.pdf') && t.includes('Uploaded by: Synthetic Adviser 2 (Research Adviser)')),
        'Bulk confirmation shows a full count and each filename with recorded uploader');
      check(await page.locator('#confirmBulkDelete').isDisabled(), 'Bulk deletion stays disabled until the audited reason is entered');
      await page.locator('#bulkDeleteModal [data-close="bulkDeleteModal"]').last().click();
      check(sql("SELECT id FROM documents WHERE id IN ('b6aa00000000000000000006','b6aa00000000000000000007')").length === 2, 'Closing bulk confirmation deletes nothing');
      await page.screenshot({ path: path.join(evidence, 'documents-1440-light.png') });
      await ctx.close();
    }

    // Adviser deletion uses the same confirmation from the assigned queue.
    {
      const { ctx, page } = await open('adviser', 'documents.php');
      const dialog = await deleteDialog(page, 'batch6-stale', 'batch6-stale.pdf');
      check((await dialog.innerText()).includes('Synthetic Student 4 (Student)'), 'Adviser confirmation shows the recorded uploader');
      await dialog.screenshot({ path: path.join(evidence, 'delete-dialog-adviser-1440.png') });
      await dialog.getByRole('button', { name: 'Cancel' }).click(); await ctx.close();
    }

    // Account pages: dirty Save, password visibility/match, Enter duplicate prevention and error recovery.
    {
      const { ctx, page } = await open('adviser', 'account.php');
      await page.waitForFunction(() => document.getElementById('accountName').value !== '');
      const save = page.locator('#accountProfileForm [type=submit]');
      check(await save.isDisabled() && /Edit your name/.test(await page.locator('#accountProfileForm .prism-gate-status').innerText()), 'Account Save is disabled until an editable value changes');
      check(await page.locator('#accountEmail').evaluate(e => e.readOnly && getComputedStyle(e).borderStyle === 'dashed') && (await markers(page)).includes('accountName') && !(await markers(page)).includes('accountEmail'),
        'Read-only institutional fields are visually distinct and never marked required');
      const original = await page.inputValue('#accountName');
      await page.fill('#accountName', original + ' B6');
      check(await save.isEnabled(), 'Account Save enables after a real change');
      await page.fill('#accountName', original);
      check(await save.isDisabled(), 'Reverting the change disables Save again');
      await page.fill('#accountName', '');
      check(await save.isDisabled(), 'Blank required name keeps Save disabled');
      let updates = 0; page.on('request', r => { if (r.url().includes('action=update_profile')) updates++; });
      await page.route('**/profile_api.php?action=update_profile', route => route.fulfill({ status: 500, contentType: 'application/json', body: '{"ok":false,"message":"Synthetic profile failure"}' }));
      await page.fill('#accountName', original + ' B6');
      await save.click(); await page.getByText('Synthetic profile failure').waitFor();
      check(await save.isEnabled() && await page.inputValue('#accountName') === original + ' B6', 'Failure re-enables Save and preserves the entered value');
      await page.unroute('**/profile_api.php?action=update_profile'); updates = 0;
      await page.route('**/profile_api.php?action=update_profile', async route => { await h.sleep(400); await route.continue(); });
      await page.locator('#accountName').press('Enter'); await page.locator('#accountName').press('Enter');
      await page.getByText('Profile updated.').waitFor();
      check(updates === 1 && await save.isDisabled(), 'Repeated Enter sends one update; Save returns to disabled after success');
      await page.unroute('**/profile_api.php?action=update_profile');
      await page.fill('#accountName', original); await save.click(); await page.waitForFunction(() => document.querySelector('#accountProfileForm [type=submit]').disabled);
      const toggle = page.locator('button[aria-controls=accountNewPassword]');
      check(await toggle.getAttribute('aria-label') === 'Show new password' && await toggle.getAttribute('aria-pressed') === 'false', 'Password visibility control has an accessible name and state');
      await toggle.click();
      check(await page.locator('#accountNewPassword').getAttribute('type') === 'text' && await toggle.getAttribute('aria-pressed') === 'true', 'Password visibility toggles with announced pressed state');
      const change = page.locator('#accountPasswordForm [type=submit]');
      await page.fill('#accountCurrentPassword', 'SyntheticPass123!'); await page.fill('#accountNewPassword', 'SyntheticPass123!B6'); await page.fill('#accountConfirmPassword', 'SyntheticPass123!X');
      check(await change.isDisabled() && /do not match/.test(await page.locator('#accountConfirmPasswordPrismMessage').innerText()), 'Mismatched confirmation keeps Change password disabled with a field message');
      await page.fill('#accountConfirmPassword', 'SyntheticPass123!B6');
      check(await change.isEnabled(), 'Change password enables when complete and matching');
      await page.screenshot({ path: path.join(evidence, 'account-adviser-1440-light.png'), fullPage: true });
      await ctx.close();
    }

    // Role differentiation and Student profile.
    const roleColors = {};
    for (const theme of ['light', 'dark']) {
      const student = await open('student', 'role_portal.php', { theme });
      const adviser = await open('adviser', 'research_adviser.php', { theme });
      const read = page => page.evaluate(() => {
        const active = document.querySelector('.portal-nav-links [aria-current=page], .portal-nav-links li.active > button');
        const chip = document.querySelector('.prism-role-chip'); const nav = document.querySelector('.portal-navbar');
        return { active: getComputedStyle(active).backgroundColor, activeText: getComputedStyle(active).color, chip: chip?.innerText.trim(), chipColor: chip && getComputedStyle(chip).color,
          navBg: getComputedStyle(document.body).backgroundColor, border: getComputedStyle(nav).borderTopColor, focus: getComputedStyle(document.documentElement).getPropertyValue('--prism-focus').trim() };
      });
      const sc = await read(student.page), ac = await read(adviser.page); roleColors[theme] = { student: sc, adviser: ac };
      check(sc.chip === 'Student portal' && ac.chip === 'Adviser portal', theme + ': role is identified by visible text and distinct icons, not color alone');
      check(sc.active !== ac.active && sc.chipColor !== ac.chipColor, theme + ': Student and Adviser navigation accents are visibly distinct');
      check(contrast(sc.activeText, sc.active) >= 4.5 && contrast(ac.activeText, ac.active) >= 4.5, theme + ': active navigation text meets 4.5:1 contrast for both roles');
      check(contrast(sc.chipColor, sc.navBg) >= 4.5 && contrast(ac.chipColor, ac.navBg) >= 4.5, theme + ': role labels meet 4.5:1 contrast');
      await student.page.screenshot({ path: path.join(evidence, `student-dashboard-1440-${theme}.png`) });
      await student.page.goto(base + '/role_portal.php#profile'); await student.page.locator('#profileForm').waitFor({ state: 'visible' });
      const save = student.page.locator('#profileForm [type=submit]');
      check(await save.isDisabled() && await student.page.locator('#profileId').evaluate(e => e.readOnly) && !(await markers(student.page)).includes('profileId') && (await markers(student.page)).includes('profileName'),
        theme + ': Student profile Save is disabled until a change; read-only Student ID is not marked required');
      const ph = await student.page.$eval('#profileName', e => ({ text: e.placeholder, color: getComputedStyle(e, '::placeholder').color, bg: getComputedStyle(e).backgroundColor }));
      check(ph.text === 'Enter full name' && contrast(ph.color, ph.bg) >= 3, theme + ': placeholders are present and readable (' + contrast(ph.color, ph.bg).toFixed(2) + ':1)');
      const mark = await student.page.$eval('#currentPassword', e => { const m = e.closest('label').querySelector('.prism-required-mark'); return { color: getComputedStyle(m).color, bg: getComputedStyle(e.closest('.panel') || document.body).backgroundColor }; });
      check(contrast(mark.color, mark.bg) >= 3, theme + ': required asterisks remain visible (' + contrast(mark.color, mark.bg).toFixed(2) + ':1)');
      await student.page.screenshot({ path: path.join(evidence, `student-profile-1440-${theme}.png`) });
      await adviser.page.screenshot({ path: path.join(evidence, `adviser-dashboard-1440-${theme}.png`) });
      await student.ctx.close(); await adviser.ctx.close();
    }
    fs.writeFileSync(path.join(evidence, 'role-colors.json'), JSON.stringify(roleColors, null, 2));

    // Responsive matrix across roles.
    const matrix = [];
    for (const theme of ['light', 'dark']) for (const width of [390, 768, 1024, 1440, 1920]) {
      const entry = { width, theme };
      const st = await open('student', 'role_portal.php#profile', { width, theme, touch: width === 390 });
      await st.page.locator('#profileForm').waitFor({ state: 'visible' });
      entry.studentOverflow = await overflow(st.page);
      entry.studentChip = await st.page.locator('.prism-role-chip').isVisible() && await st.page.getByText('Student portal').count() === 1;
      if ([390, 1440].includes(width)) await st.page.screenshot({ path: path.join(evidence, `student-profile-${width}-${theme}.png`), fullPage: false });
      await st.ctx.close();
      const ad = await open('adviser', 'account.php', { width, theme, touch: width === 390 });
      entry.adviserOverflow = await overflow(ad.page);
      entry.adviserChip = await ad.page.locator('.prism-role-chip').isVisible() && await ad.page.getByText('Adviser portal').count() === 1;
      entry.adviserTargets = await ad.page.$$eval('#accountPasswordForm button', bs => bs.every(b => { const r = b.getBoundingClientRect(); return r.height >= 24 && r.width >= 24; }));
      if ([390, 1440].includes(width)) await ad.page.screenshot({ path: path.join(evidence, `account-adviser-${width}-${theme}.png`) });
      await ad.ctx.close();
      const am = await open('admin', 'ierbprog.php', { width, theme, touch: width === 390 });
      await openEntry(am.page, `${width}-${theme}`); await am.page.waitForTimeout(120);
      entry.adminOverflow = await overflow(am.page);
      entry.modal = await am.page.locator('.ierb-entry-dialog').evaluate(d => { const r = d.getBoundingClientRect(); return r.width <= innerWidth && d.scrollWidth <= d.clientWidth + 1; });
      entry.markerVisible = await am.page.locator('label[for=entryStudentId] .prism-required-mark').isVisible();
      await am.page.locator('.ierb-entry-dialog').screenshot({ path: path.join(evidence, `ierb-entry-${width}-${theme}.png`) });
      await am.page.click('#cancelIerbEntry');
      const dd = await open('admin', 'documents.php', { width, theme, touch: width === 390 });
      const dlg = await deleteDialog(dd.page, 'batch6-stale', 'batch6-stale.pdf');
      entry.deleteDialog = await dlg.evaluate(d => { const r = d.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && d.scrollWidth <= d.clientWidth + 1; });
      entry.deleteOverflow = await overflow(dd.page);
      await dlg.screenshot({ path: path.join(evidence, `delete-dialog-${width}-${theme}.png`) });
      await dlg.getByRole('button', { name: 'Cancel' }).click();
      await am.ctx.close(); await dd.ctx.close();
      matrix.push(entry);
      check(!entry.studentOverflow && !entry.adviserOverflow && !entry.adminOverflow && !entry.deleteOverflow && entry.modal && entry.deleteDialog && entry.markerVisible && entry.adviserTargets && entry.studentChip && entry.adviserChip,
        `${width} ${theme}: no page overflow; entry and delete dialogs fit; asterisks visible; targets usable; role identified ` + JSON.stringify(entry));
    }
    check(!errors.length, 'Batch 6 browser interactions have no JavaScript page errors: ' + errors.join('; '));
    fs.writeFileSync(path.join(evidence, `responsive-schema${schema}.json`), JSON.stringify({ status: 'PASS', browser: 'Chromium (headless)', matrix }, null, 2));
    fs.writeFileSync(path.join(evidence, `batch6-integration-schema${schema}.json`), JSON.stringify({ status: 'PASS', schema, assertions: h.checks.length - start, cases: h.checks.slice(start) }, null, 2));
  } finally {
    if (browser) await browser.close();
    if (enrolled) unenroll();
    sql("DELETE FROM documents WHERE id LIKE 'b6aa%'");
    const ids = sql(`SELECT id,user_id FROM students WHERE email LIKE 'batch6-%@example.test'`);
    for (const { id } of ids) { sql('DELETE FROM calendar_deadline_recipients WHERE student_id=?', [id]); sql('DELETE FROM ierb_history WHERE student_id=?', [id]); sql('DELETE FROM documents WHERE student_id=?', [id]); sql('DELETE FROM students WHERE id=?', [id]); }
    // Identity audits name the deleted record's ID; left behind, they make a later fixture that reuses the ID resolve a stale login (closure F02).
    for (const { id } of ids) sql("DELETE FROM activity_logs WHERE entity_type='student' AND entity_id=?", [String(id)]);
    const users = sql(`SELECT id FROM users WHERE email LIKE 'batch6-%@example.test'`);
    for (const { id } of users) {
      for (const table of ['account_invitations', 'password_resets', 'user_policy_acceptances']) sql(`DELETE FROM ${table} WHERE user_id=?`, [id]);
      sql('DELETE FROM notifications WHERE recipient_user_id=?', [id]);
    }
    sql(`DELETE FROM users WHERE email LIKE 'batch6-%@example.test'`);
    sql("DELETE FROM activity_logs WHERE details LIKE '%batch6%' OR entity_id LIKE 'b6aa%'");
  }
};
