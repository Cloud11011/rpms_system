'use strict';
// Additive API coverage. Invoked only with PRISM_TEST_FRONTEND_COMPATIBILITY=1.
module.exports=async h=>{
 const {check,sessions:s,base,playwright,sql,response}=h;
 const start=h.checks.length;
 const anonymous=await playwright.request.newContext({baseURL:base});
 try{
  check((await anonymous.get('/ierb_api.php?action=monitor_requirements&id=4')).status()===401,'Unauthenticated requests cannot read monitor requirement details');
 }finally{await anonymous.dispose();}
 check((await s.student.get('/ierb_api.php?action=monitor_requirements&id=4')).status()===403,'Students cannot use staff monitor detail endpoints');
 const before=sql('SELECT COUNT(*) AS n FROM activity_logs')[0].n;
 const own=await response(await s.adviser.get('/ierb_api.php?action=monitor_requirements&id=4'));
 check(own.status===200&&own.body.record.studentRecordId===4,'An assigned Adviser can inspect their own monitor requirement record');
 check((await s.adviser.get('/ierb_api.php?action=monitor_requirements&id=5')).status()===422,'An Adviser cannot inspect another Adviser’s monitor requirement record');
 const admin=await response(await s.admin.get('/ierb_api.php?action=monitor_requirements&id=5'));
 check(admin.status===200&&admin.body.record.studentRecordId===5,'RPMS can inspect an authorized record across Adviser assignments');
 check((await s.admin.get('/ierb_api.php?action=monitor_requirements&id=0')).status()===422,'Invalid record identities are rejected');
 check((await s.admin.get('/ierb_api.php?action=monitor_requirements&id=99999999')).status()===422,'Unknown monitor records are rejected');
 check(sql('SELECT COUNT(*) AS n FROM activity_logs')[0].n===before,'Read-only monitor requirement requests create no workflow audit mutations');
 const cards=await response(await s.admin.get('/ierb_api.php?action=monitor&layout=cards&perPage=5'));
 check(cards.status===200&&cards.body.limit===5,'The card view accepts its explicitly bounded five-row page size');
 check(cards.body.records.length<=5&&Array.isArray(cards.body.statusCounts),'Card pagination returns bounded records and scoped status counts');
 check(cards.body.records.every(r=>Object.hasOwn(r,'protocolCode')),'Monitor records retain the authoritative protocol-code field');
 const table=await response(await s.admin.get('/ierb_api.php?action=monitor&perPage=5'));
 check(table.status===200&&table.body.limit===10,'The default table retains its original bounded page sizes');
 const oversize=await response(await s.admin.get('/ierb_api.php?action=monitor&layout=cards&perPage=999999'));
 check(oversize.status===200&&oversize.body.limit===10,'Unbounded page-size requests fall back to the safe default');
 const adviser=await response(await s.adviser.get('/ierb_api.php?action=monitor&layout=cards&perPage=5'));
 check(adviser.status===200&&!Object.hasOwn(adviser.body,'dashboardMetrics'),'Adviser responses do not receive RPMS-wide dashboard metrics');
 check(!adviser.body.records.some(r=>r.studentRecordId===5),'Card layout preserves the existing Adviser assignment scope');
 const wildcard=await response(await s.admin.get('/ierb_api.php?action=monitor&layout=cards&q=%25'));
 check(wildcard.status===200&&wildcard.body.total===0,'Monitor search treats percent signs as literal text');
 check(h.checks.length-start===16,'All additive compatibility assertions executed');
};
