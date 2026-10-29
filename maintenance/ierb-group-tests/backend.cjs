module.exports = async h => {
  const { sql, fixture, post, response, expect, check, sessions: s, child, locked, sleep } = h;
  sql("INSERT IGNORE INTO users(id,username,full_name,email,role,ref_id,password_hash,status,created_at) SELECT 6,'S6','Synthetic Student 6','student6@example.test','student','S6',password_hash,'Active',created_at FROM users WHERE id=4");
  sql("INSERT IGNORE INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at) SELECT 6,6,'S6','Synthetic Student 6','student6@example.test',2,'Stage 1','Pending','AMT-BSIT-Y4-2627-G01',course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at,created_at FROM students WHERE id=4");
  sql('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT 6,policy_version_id FROM user_policy_acceptances WHERE user_id=4');
  function reset(ids = [4, 5], stage = 'Stage 1') {
    sql("UPDATE users SET status='Active',must_change_password=0 WHERE id IN (4,5,6)");
    sql("UPDATE students SET stage=?,status=IF(id=4,'On Track',IF(id=5,'Pending','Delayed')),research_group=CONCAT('AMT-BSIT-Y4-2627-G0',id),academic_unit_key='amt',program_key='bsit',year_level='4th Year',academic_year='2026-2027',archived_at=NULL,profile_completed_at='2026-01-01',adviser_id=IF(id=5,3,2) WHERE id IN (4,5,6)", [stage]);
    sql('UPDATE students SET research_group=? WHERE id IN (' + ids.join(',') + ')', ['AMT-BSIT-Y4-2627-G01']);
  }
  const groups = async ctx => (await response(await ctx.get('/ierb_api.php?action=group_advance_list'))).body.groups;
  const key = async () => (await groups(s.admin)).find(g => g.identity.research_group === 'AMT-BSIT-Y4-2627-G01' && g.identity.academic_year === '2026-2027').key;
  const preview = async () => (await expect(post(s.admin, 'group_advance_preview', { groupKey: await key() }), 200, 'Authoritative group preview succeeds')).body;
  const payload = p => ({ confirmationToken: p.confirmationToken, confirmed: true, reason: 'RPMS reviewed readiness for the next official stage.' });
  const state = () => JSON.stringify(Object.fromEntries(['students', 'ierb_history', 'activity_logs', 'notifications', 'notification_campaigns'].map(t => [t, sql('SELECT * FROM ' + t + ' ORDER BY id')])));
  h.group = { reset, groups, key, preview, payload, state };
  reset();
  if (h.phase === 'ui') return;
  for (const ctx of [s.adviser, s.student]) {
    for (const action of ['group_advance_list', 'group_advance_preview', 'group_advance', 'group_advance_cancel']) {
      await expect(action.endsWith('list') ? response(await ctx.get('/ierb_api.php?action=' + action)) : post(ctx, action, {}), 403, 'Non-Admin forbidden from ' + action);
    }
  }
  await expect(response(await s.admin.get('/ierb_api.php?action=group_advance')), 405, 'Confirmation requires POST');
  await expect(response(await s.admin.post('/ierb_api.php?action=group_advance_preview', { data: {}, headers: { origin: 'https://other.example.test' } })), 403, 'Cross-origin preview rejected');
  await expect(response(await s.admin.post('/ierb_api.php?action=group_advance_preview', { data: {}, headers: { origin: h.base } })), 409, 'New group workflow requires session-generation marker');
  await expect(response(await s.admin.post('/ierb_api.php?action=group_advance_preview', { data: {}, headers: { origin: h.base, 'X-Prism-Generation': 'stale' } })), 409, 'Stale browser session generation rejected');
  for (const stage of ['Stage 1', 'Stage 3', 'Stage 5']) {
    for (const ids of [[4, 5], [4, 5, 6]]) {
      reset(ids, stage); const before = state(), p = await preview();
      check(state() === before, 'Preview is read-only for ' + ids.length + ' members at ' + stage);
      check(p.eligible && p.memberCount === ids.length && p.members.map(m => m.id).join(',') === ids.join(','), 'Exact complete roster for ' + ids.length + '-member group');
      const next = stage === 'Stage 1' ? 'Stage 2' : stage === 'Stage 3' ? 'Stage 4' : 'Completed';
      check(p.currentStage === stage && p.nextStage === next, 'Canonical sequence proposes ' + stage + ' to ' + next);
      const result = await expect(post(s.admin, 'group_advance', payload(p)), 200, 'Atomic ' + ids.length + '-member advancement ' + stage + ' to ' + next);
      const rows = sql('SELECT id,stage,status FROM students WHERE id IN (' + ids.join(',') + ') ORDER BY id');
      check(rows.every(r => r.stage === next) && rows.map(r => r.status).join(',') === ['On Track', 'Pending', 'Delayed'].slice(0, ids.length).join(','), 'Every member advances exactly once and retains its own status');
      const history = sql('SELECT * FROM ierb_history WHERE note LIKE ? ORDER BY student_id', ['%' + result.body.correlationId + '%']);
      check(history.length === ids.length && history.every(r => r.stage === next && r.actor.includes('Synthetic Admin 1') && r.note.includes(stage + ' -> ' + next) && r.note.includes(payload(p).reason)), 'Individual history records previous/new stage, Admin, reason and correlation');
      const audit = sql('SELECT * FROM activity_logs WHERE details LIKE ? ORDER BY student_id', ['%' + result.body.correlationId + '%']);
      check(audit.length === ids.length && audit.every(r => r.actor_role === 'admin' && r.user_email === 'admin@example.test' && r.reason === payload(p).reason && r.before_value.startsWith(stage) && r.after_value.startsWith(next) && !r.is_override), 'Mandatory individual audits retain real Admin identity and decision evidence');
      await expect(post(s.admin, 'group_advance', payload(p)), 409, 'Consumed confirmation cannot be replayed');
    }
  }
  for (const [name, mutate, reason] of [
    ['Completed group', () => reset([4,5], 'Completed'), 'final IERB stage'],
    ['Mixed official stages', () => sql("UPDATE students SET stage='Stage 3' WHERE id=5"), 'different official IERB stages'],
    ['Inactive Student', () => sql("UPDATE users SET status='Inactive' WHERE id=5"), 'ineligible associated'],
    ['Archived Student', () => sql('UPDATE students SET archived_at=NOW() WHERE id=5'), 'ineligible associated'],
    ['Incomplete profile', () => sql('UPDATE students SET profile_completed_at=NULL WHERE id=5'), 'ineligible associated'],
    ['Temporary password restriction', () => sql('UPDATE users SET must_change_password=1 WHERE id=5'), 'ineligible associated'],
    ['One member', () => reset([4]), 'At least two'],
  ]) {
    reset(); mutate(); const p = await preview();
    check(!p.eligible && p.confirmationToken === null && p.reasons.some(r => r.includes(reason)), name + ' disclosed and blocks group confirmation');
    if (!name.includes('One')) check(p.members.length === 2, name + ' remains associated and visible rather than silently excluded');
  }
  reset(); sql("UPDATE students SET research_group='Group 01' WHERE id IN (4,5)");
  let list = await groups(s.admin), legacy = list.find(g => g.identity.research_group === 'Group 01');
  let p = (await post(s.admin, 'group_advance_preview', { groupKey: legacy.key })).body;
  check(!legacy.standard && !p.eligible && p.members.length === 2 && !p.confirmationToken, 'Legacy group is disclosed but cannot advance');
  reset([4,5,6]); sql("UPDATE students SET academic_year='2025-2026' WHERE id=6"); p = await preview();
  check(p.members.map(m => m.id).join(',') === '4,5' && (await groups(s.admin)).filter(g => g.identity.research_group === 'AMT-BSIT-Y4-2627-G01').length === 2, 'Same group code in another academic cohort has a distinct identity and is never included');
  reset([4,5,6]); sql("UPDATE students SET research_group=LOWER(research_group) WHERE id=6"); p = await preview();
  check(p.members.length === 2, 'Exact case-sensitive standardized membership excludes similar labels');
  reset([4,5,6]);
  const table = await response(await s.admin.get('/ierb_api.php?action=list&perPage=1&page=2&q=S4'));
  p = await preview(); check(p.members.length === 3 && table.body.records.length <= 1, 'Group preview includes all members independently of table pages and search filters');
  const extraIds = Array.from({ length: 8 }, (_,i) => i + 20);
  for (const id of extraIds) {
    sql("INSERT IGNORE INTO users(id,username,full_name,email,role,ref_id,password_hash,status) SELECT ?,?,?,?,'student',?,password_hash,'Active' FROM users WHERE id=4", [id,'S'+id,'Synthetic Student '+id,'student'+id+'@example.test','S'+id]);
    sql("INSERT IGNORE INTO students(id,user_id,student_id,full_name,email,adviser_id,stage,status,research_group,course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at) SELECT ?,?,?,?, ?,2,'Stage 1','Pending','AMT-BSIT-Y4-2627-G90',course,academic_unit_key,program_key,year_level,academic_year,profile_completed_at FROM students WHERE id=4", [id,id,'S'+id,'Synthetic Student '+id,'student'+id+'@example.test']);
    sql('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT ?,policy_version_id FROM user_policy_acceptances WHERE user_id=4', [id]);
  }
  sql("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G01',stage='Stage 1' WHERE id IN (" + extraIds.join(',') + ')');
  const pages = [];
  for (const page of [1,2]) pages.push((await response(await s.admin.get('/ierb_api.php?action=list&perPage=10&group=AMT-BSIT-Y4-2627-G01&page='+page))).body.records);
  p = await preview(); check(pages[0].length === 10 && pages[1].length === 1 && p.memberCount === 11, 'Eleven eligible group members genuinely span two ten-row table pages');
  await expect(post(s.admin, 'group_advance', payload(p)), 200, 'One confirmed operation advances the complete eleven-member cross-page group');
  check(sql("SELECT id FROM students WHERE research_group='AMT-BSIT-Y4-2627-G01' AND stage='Stage 2'").length === 11, 'Cross-page group advancement omits no off-page members');
  sql("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G90' WHERE id IN ("+extraIds.join(',')+')');
  reset(); p = await preview();
  for (const bad of ['', 'no', '!'.repeat(6), 'x'.repeat(501), [], null]) await expect(post(s.admin, 'group_advance', { ...payload(p), reason: bad }), 422, 'Invalid mandatory reason rejected: ' + JSON.stringify(bad).slice(0,50));
  await expect(post(s.admin, 'group_advance', { ...payload(p), confirmed: false }), 422, 'Unconfirmed decision rejected');
  await expect(post(s.admin, 'group_advance', { ...payload(p), ids: [4,6] }), 422, 'Client cannot substitute member IDs');
  const other = await h.login('admin@example.test'); await expect(post(other, 'group_advance', payload(p)), 409, 'Preview is bound to the original authenticated session');
  await expect(post(s.admin, 'group_advance_cancel', { confirmationToken: p.confirmationToken }), 200, 'Explicit cancellation consumes confirmation without writes');
  const beforeCancel = state(); await expect(post(s.admin, 'group_advance', payload(p)), 409, 'Canceled token cannot advance'); check(state() === beforeCancel, 'Cancellation and canceled confirmation leave progress/evidence/queue unchanged');
  const prep = fixture({ action: 'prepare', key: await key() });
  const changedGeneration = structuredClone(prep); changedGeneration.session.login_generation = 'changed-generation';
  check(fixture({ action: 'confirm', session: changedGeneration.session, data: payload(prep.preview) }).status === 409, 'Confirmation snapshot is bound to the original session generation');
  prep.session.ierb_group_confirmations[prep.preview.confirmationToken].expires = 1;
  check(fixture({ action: 'confirm', session: prep.session, data: payload(prep.preview) }).status === 409, 'Expired ten-minute confirmation cannot advance');
  for (const [label, mutate, restore] of [
    ['individual stage', () => sql("UPDATE students SET stage='Stage 2' WHERE id=4"), () => {}],
    ['progress status', () => sql("UPDATE students SET status='Delayed' WHERE id=4"), () => {}],
    ['history version', () => sql("INSERT INTO ierb_history(student_id,stage,status,note,actor) VALUES (4,'Stage 1','On Track','Concurrent note','Synthetic Admin')"), () => {}],
    ['reassignment', () => sql("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G09' WHERE id=5"), () => {}],
    ['newly associated member', () => sql("UPDATE students SET research_group='AMT-BSIT-Y4-2627-G01' WHERE id=6"), () => {}],
    ['archive', () => sql('UPDATE students SET archived_at=NOW() WHERE id=5'), () => {}],
    ['account eligibility', () => sql("UPDATE users SET status='Inactive' WHERE id=5"), () => {}],
    ['legal acceptance', () => sql('DELETE FROM user_policy_acceptances WHERE user_id=5'), () => sql('INSERT IGNORE INTO user_policy_acceptances(user_id,policy_version_id) SELECT 5,policy_version_id FROM user_policy_acceptances WHERE user_id=4')],
  ]) {
    reset(); p = await preview(); mutate();
    // Restore even when an assertion fails, so one failure cannot contaminate later cases or runs (B7-F11).
    try {
      const before = state();
      const r = await expect(post(s.admin, 'group_advance', payload(p)), 409, 'Changed ' + label + ' rejects stale confirmation');
      check(r.body.message.includes('Research group information changed after your preview') && state() === before, 'Stale ' + label + ' produces the required conflict and no extra writes');
    } finally { restore(); }
  }
  reset();
  sql("UPDATE advisers SET status='Inactive' WHERE id=3");
  try { const p = await preview(); check(!p.eligible && p.members.find(m=>m.id===5).issues.some(i=>i.includes('Adviser notification')), 'Unavailable required Adviser recipient is disclosed before confirmation rather than failing midway'); }
  finally { sql("UPDATE advisers SET status='Active' WHERE id=3"); }
  sql("INSERT INTO account_purge_jobs(id,account_type,account_id,identifier,actor_id,method,status,manifest_sha256) VALUES (REPEAT('a',32),'student',5,'S5',1,'synthetic','files_pending',REPEAT('0',64))");
  try { const blocked = await preview(); check(!blocked.eligible && blocked.members.find(m=>m.id===5).issues.some(i=>i.includes('recovery')), 'Unresolved database lifecycle recovery remains visible and blocks the group'); }
  finally { sql("DELETE FROM account_purge_jobs WHERE id=REPEAT('a',32)"); }
  reset(); p = await preview(); sql("UPDATE users SET full_name='' WHERE id=1");
  try { await expect(post(s.admin, 'group_advance', payload(p)), 403, 'Administrator eligibility revalidated inside transaction'); } finally { sql("UPDATE users SET full_name='Synthetic Admin 1' WHERE id=1"); }
  for (const [tableName, timing, condition] of [
    ['students', 'UPDATE', 'NEW.id=5'], ['ierb_history', 'INSERT', 'NEW.student_id=5'],
    ['activity_logs', 'INSERT', "NEW.action='ierb_group_stage_advanced' AND NEW.student_id=5"], ['notifications', 'INSERT', "NEW.recipient_type='student' AND NEW.recipient_id=5"],
  ]) {
    reset([4,5,6]); p = await preview(); const before = state();
    sql('CREATE TRIGGER prism_group_fault BEFORE ' + timing + ' ON ' + tableName + " FOR EACH ROW BEGIN IF " + condition + " THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Synthetic midway failure'; END IF; END");
    try {
      await expect(post(s.admin, 'group_advance', payload(p)), 500, 'Injected midway ' + tableName + ' failure rejects whole operation');
      check(state() === before, 'Real MariaDB rollback removes every stage/history/audit/notification/campaign change after ' + tableName + ' failure');
      await expect(post(s.admin, 'group_advance', payload(p)), 409, 'Failed write token is consumed and cannot replay');
    } finally { sql('DROP TRIGGER prism_group_fault'); }
  }
  reset([4,5,6]); sql('UPDATE students SET adviser_id=2 WHERE id IN (4,5,6)');
  const mailPrep = fixture({ action: 'prepare', key: await key() });
  const noticeStart = sql('SELECT COALESCE(MAX(id),0) n FROM notifications')[0].n;
  const mail = fixture({ action: 'confirm', session: mailPrep.session, data: payload(mailPrep.preview), providerFailure: true });
  check(mail.status === 200 && mail.providerCalls === 4 && sql("SELECT id FROM students WHERE id IN (4,5,6) AND stage='Stage 2'").length === 3, 'Simulated Gmail failure occurs only after commit and does not roll back official progress');
  const notices = sql('SELECT * FROM notifications WHERE id>? ORDER BY id', [noticeStart]);
  check(notices.filter(n => n.recipient_type === 'student').length === 3 && notices.filter(n => n.recipient_type === 'adviser').length === 1, 'Three individual Student notifications and one deduplicated assigned Adviser notification');
  check(notices.every(n => n.status === 'Failed') && notices.every(n => n.message.includes('Stage 2')), 'Failed external delivery retains durable in-app notices, new stage and retry metadata');
  // Actual races: committed writers hold uncommitted membership/progress until confirmation waits on them.
  for (const [label, query] of [
    ['individual progress', "UPDATE students SET stage='Stage 2' WHERE id=4"],
    ['group reassignment', "UPDATE students SET research_group='AMT-BSIT-Y4-2627-G09' WHERE id=5"],
    ['member insertion', "INSERT INTO students(student_id,full_name,email,stage,status,research_group,academic_unit_key,program_key,year_level,academic_year) VALUES ('RACE-S','Synthetic new member','race@example.test','Stage 1','Pending','AMT-BSIT-Y4-2627-G01','amt','bsit','4th Year','2026-2027')"],
  ]) {
    reset(); const prepared = fixture({ action: 'prepare', key: await key() });
    const writer = child({ action: 'hold', sql: query }); await locked(writer);
    const started = Date.now(); const confirming = child({ action: 'confirm', session: prepared.session, data: payload(prepared.preview) });
    const result = JSON.parse(await confirming.done); await writer.done;
    check(result.status === 409 && Date.now() - started >= 600, 'Independent concurrent ' + label + ' is waited for and rejects stale roster/state');
    check(sql("SELECT stage FROM students WHERE id=5")[0].stage === 'Stage 1', 'Concurrent ' + label + ' produces no partial group advancement');
    if (label === 'member insertion') sql("DELETE FROM students WHERE student_id='RACE-S'");
  }
  reset();
  const groupA = fixture({ action: 'prepare', key: await key() }), groupB = fixture({ action: 'prepare', key: await key() });
  const groupRaces = await Promise.all([groupA,groupB].map(item => child({ action: 'confirm', session: item.session, data: payload(item.preview) }).done.then(JSON.parse)));
  check(groupRaces.map(r=>r.status).sort().join(',') === '200,409', 'Concurrent independently confirmed group decisions produce one commit and one stale conflict');
  reset();
  const singleRace = fixture({ action: 'prepare', key: await key() });
  const singleVersion = String(sql('SELECT COALESCE(MAX(id),0) v FROM ierb_history WHERE student_id=4')[0].v);
  const singleResults = await Promise.all([
    child({ action: 'confirm', session: singleRace.session, data: payload(singleRace.preview) }).done.then(JSON.parse),
    child({ action: 'decide', operation: 'advance', data: { id: 4, confirmed: true, reason: 'Concurrent actual individual stage decision.', expectedStage: 'Stage 1', expectedStatus: 'On Track', expectedVersion: singleVersion } }).done.then(JSON.parse)
  ]);
  check(singleResults.map(r=>r.status).sort().join(',') === '200,409', 'Actual individual advancement and group transaction race safely: one commit and one conflict');
  // Verify the existing-schema primary range fence also blocks changes arriving AFTER the locking read.
  for (const [label, query] of [
    ['insert', "INSERT INTO students(student_id,full_name,email,research_group) VALUES ('FENCE-S','Synthetic fence member','fence@example.test','AMT-BSIT-Y4-2627-G01')"],
    ['move into group', "UPDATE students SET research_group='AMT-BSIT-Y4-2627-G01' WHERE id=6"],
  ]) {
    reset(); const fence = child({ action: 'fence' }); await locked(fence);
    const started = Date.now(); const writer = child({ action: 'sql', sql: query }); await writer.done; await fence.done;
    check(Date.now() - started >= 600, 'Second independent connection cannot ' + label + ' through the exact confirmation membership fence');
    if (label === 'insert') sql("DELETE FROM students WHERE student_id='FENCE-S'");
  }
  reset(); p = await preview(); await expect(post(s.admin, 'group_advance', payload(p)), 200, 'Group decision succeeds before single-student regression');
  const expectedVersion = String(sql('SELECT COALESCE(MAX(id),0) v FROM ierb_history WHERE student_id=4')[0].v);
  await expect(post(s.admin, 'advance', { id: 4, confirmed: true, reason: 'Individual readiness reviewed after the group decision.', expectedStage: 'Stage 2', expectedStatus: 'On Track', expectedVersion }), 200, 'Existing individual endpoint advances a member after group advancement');
  check(sql('SELECT stage,status FROM students WHERE id=4')[0].stage === 'Stage 3' && sql('SELECT stage FROM students WHERE id=5')[0].stage === 'Stage 2', 'Individual decision remains independent after coordinated group decision');
  reset([4,5,6]);
};
