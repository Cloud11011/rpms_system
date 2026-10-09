'use strict';
const retention=require('./account-retention-ui.cjs');
const matrix=[[1920,1080],[1366,768],[1024,768],[768,1024],[390,844],[375,812]];
let type='student';
let completionSuccess=false,scriptUnavailable=false;
function mockList(file,query) {
    type=file==='advisers_api.php'?'adviser':'student';
    const data=retention.mockList(file,query),rows=data[type==='student'?'students':'advisers'];
    rows.slice(0,2).forEach((r,i)=>{r.profileStatus='Pending';r.name=null;r.studentId=null;r.employeeId=null;r.email='long'+String(i)+'a'.repeat(145)+'@example.invalid';r.group=null;r.research=null;r.department=null;r.adviserName=null;});
    rows[1].lifecycle.manualEligible=true;rows[1].lifecycle.overrideAvailable=false;
    data.filterOptions.profile=[{value:'pending',label:'Pending Profile'},{value:'complete',label:'Complete Profile'}];
    return data;
}
function mockApi(file,action,request) {
    if(file==='account_invitation_api.php')return action==='complete'?(completionSuccess?{ok:true,redirect:JSON.parse(request.body).employeeId?'ierbprog.php':'student.php'}:{ok:false,message:'Choose a valid academic year. <img src=x onerror="window.__fixtureXss=1">'}):{ok:true,emailSent:true,message:'Invitation sent. Profile is Pending.'};
    if(file==='account_lifecycle_api.php'&&action==='account_state')return {ok:true,lifecycle:{manualEligible:true,overrideAvailable:false},assignedStudents:0};
    return retention.mockApi(file,action,request);
}
async function run({check,evaluate,waitFor,navigate,command,keyPress,setManagement,getRequests,errors}) {
    for(const [width,height] of matrix)for(const dark of [false,true])for(const [page,viewer,kind] of [
        ['admin_students.php','admin','student'],['admin_advisers.php','admin','adviser'],['admin_students.php','adviser','student']]) {
        setManagement(kind);await navigate(page,width,dark,'populated',viewer);
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        await waitFor('document.querySelector("#recordRows tr").textContent.includes("Pending Profile")');
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),`${page}/${viewer}/${width}/${dark}: Pending table fits`);
        check(await evaluate('document.querySelector("#recordRows tr").textContent.includes("Profile incomplete") && !document.querySelector("#recordRows tr:first-child button[aria-label^=Edit]")'),'Pending state explicit and incomplete identity cannot be edited as complete');
        check(await evaluate('document.querySelector("#recordRows tr").textContent.includes("—")'),'Missing ID uses dash');
        await evaluate('document.getElementById("inviteAccount").click()');await waitFor('document.getElementById("inviteDialog").open');
        check(await evaluate('document.activeElement.id==="inviteEmail"'),'Invitation opens with email focus');
        check(await evaluate(`!!document.getElementById('inviteAdviser')===${viewer==='admin'&&kind==='student'}`),'Only Admin Student invitation shows assignment selector');
        check(await evaluate('document.getElementById("inviteDialog").getBoundingClientRect().right<=innerWidth+1'),'Invitation dialog fits viewport');
        await keyPress('Tab','Tab',9);
        check(await evaluate('document.getElementById("inviteDialog").contains(document.activeElement)'),'Tab keeps keyboard focus inside invitation dialog');
        await keyPress('Escape','Escape',27);await waitFor('document.activeElement.id==="inviteAccount"');check(await evaluate('document.activeElement.id==="inviteAccount"'),'Escape restores invitation trigger focus');
        await evaluate('document.getElementById("inviteAccount").click();document.getElementById("inviteEmail").value="new@example.invalid";document.getElementById("inviteForm").requestSubmit()');
        await waitFor('!document.getElementById("inviteDialog").open');
        const req=getRequests().findLast(r=>r.file==='account_invitation_api.php'&&r.action==='invite'),payload=JSON.parse(req.body);
        check(req.method==='POST'&&payload.email==='new@example.invalid'&&payload.accountType===kind,'Invite submits email with explicit permitted target role');
        check(Object.keys(payload).every(k=>['email','accountType',...(viewer==='admin'&&kind==='student'?['adviserId']:[])].includes(k)),'Invitation submits no profile/workflow/privilege fields');
        if(viewer==='admin') {
            await evaluate('document.querySelector("#recordRows tr:nth-child(2) .lifecycle-danger").click()');
            await waitFor('document.getElementById("permanentDeleteDialog").open');
            check(await evaluate('document.getElementById("permanentDeleteConfirmationLabel").textContent.includes("invited email") && document.getElementById("permanentDeleteConfirmation").maxLength===190'),'Pending destructive confirmation uses full email');
            await keyPress('Escape','Escape',27);
        }
    }
    for(const [width,height] of matrix)for(const dark of [false,true])for(const viewer of ['student','adviser']) {
        await navigate('complete_profile.php',width,dark,'populated',viewer);
        await command('Emulation.setDeviceMetricsOverride',{width,height,deviceScaleFactor:1,mobile:false});
        const prefix=`Complete ${viewer}/${width}/${dark}`;
        check(await evaluate('document.documentElement.scrollWidth<=innerWidth+1'),prefix+': form fits');
        check(await evaluate('[...document.querySelectorAll("input:not([type=hidden]),select")].every(e=>e.labels?.length>0)'),prefix+': controls labeled');
        await evaluate('document.getElementById("onboardingName").focus()');
        check(await evaluate('parseFloat(getComputedStyle(document.activeElement).outlineWidth)>=2'),prefix+': visible keyboard focus');
        check(await evaluate('!document.querySelector("[name=email],[name=adviserId],[name=role],[name=stage],[name=group]")'),prefix+': only personal/academic fields editable');
        check(await evaluate('!document.querySelector("a[href=\\"change_password_required.php\\"]") && !document.body.textContent.includes("Password security")'),prefix+': no looping password-security action');
        await evaluate('document.getElementById("onboardingName").value="Long Name ".repeat(16);document.getElementById("onboardingId").value="SELF-001"');
        if(viewer==='student') {
            await evaluate('for(const [id,value] of [["onboardingUnit","amt"],["onboardingProgram","bsit"],["onboardingYear","2nd Year"],["onboardingAcademicYear","2026-2027"]]){const e=document.getElementById(id);e.value=value;e.dispatchEvent(new Event("change"));}');
            check(await evaluate('document.getElementById("onboardingYear").options.length===5'),'Year options use program duration');
        } else await evaluate('document.getElementById("onboardingDepartment").selectedIndex=1');
        await evaluate('document.getElementById("completeProfileForm").requestSubmit()');
        await waitFor('document.getElementById("onboardingResult").textContent.includes("Choose a valid")');
        check(await evaluate('document.activeElement.id==="onboardingResult" && !document.querySelector("#onboardingResult img") && !window.__fixtureXss'),prefix+': accessible literal validation');
        check(await evaluate('!document.querySelector("#completeProfileForm [type=submit]").disabled && document.getElementById("onboardingName").value.length>0'),prefix+': validation preserves values and retry');
        if(width===375&&dark) {
            completionSuccess=true;
            try {
                await evaluate('document.getElementById("completeProfileForm").requestSubmit()');
                await waitFor(`location.pathname==="/${viewer==='student'?'student.php':'ierbprog.php'}"`);
                const sent=getRequests().findLast(r=>r.file==='account_invitation_api.php'&&r.action==='complete');
                check(sent.method==='POST'&&JSON.parse(sent.body).name.length>0,'Ready JS completion sends profile in JSON POST');
                check(await evaluate('!location.search && !location.href.includes("SELF-001")'),'Successful completion redirects without profile PII');
            } finally {completionSuccess=false;}
        }
    }
    for(const viewer of ['student','adviser'])for(const noScript of [true,false]) {
        scriptUnavailable=!noScript;
        await command('Emulation.setScriptExecutionDisabled',{value:noScript});
        try {
            await navigate('complete_profile.php',375,false,'empty',viewer,noScript);
            check(await evaluate('document.querySelector("#completeProfileForm [type=submit]").disabled'),'Unavailable JS leaves submit disabled');
            check(await evaluate('document.getElementById("onboardingResult").textContent.includes("enable JavaScript")'),'Unavailable JS has a clear failure state');
            if(noScript)check(await evaluate('!!document.querySelector("noscript p") && document.querySelector("noscript p").getClientRects().length>0'),'No-JS notice visible');
            check(await evaluate('document.getElementById("completeProfileForm").method==="post" && new URL(document.getElementById("completeProfileForm").action).search===""'),'Native fallback is POST to clean action');
            await evaluate('document.getElementById("onboardingName").value="Private Browser Name";document.getElementById("onboardingId").value="PRIVATE-BROWSER-ID";document.getElementById("completeProfileForm").submit()');
            await waitFor('document.readyState==="complete" && location.search===""');
            const fallback=getRequests().findLast(r=>r.file==='complete_profile.php'&&r.method==='POST');
            check(!!fallback&&Object.keys(fallback.query).length===0&&fallback.body.includes('PRIVATE-BROWSER-ID'),'Forced native fallback sends PII only in POST body');
            check(await evaluate('!location.href.includes("PRIVATE-BROWSER-ID") && !document.documentElement.innerHTML.includes("Private Browser Name")'),'Fallback neither exposes nor reflects submitted PII');
            check(!getRequests().findLast(r=>r.file==='complete_profile.php'&&r.method==='GET'&&Object.keys(r.query).some(k=>['name','studentId','employeeId','research'].includes(k))),'No PII-bearing GET fallback');
        } finally {scriptUnavailable=false;await command('Emulation.setScriptExecutionDisabled',{value:false});}
    }
    await navigate('account_setup.php',375,false,'empty','student');
    await evaluate('location.hash="a".repeat(64)');
    // Script consumes the fragment at load; reload the fragment URL directly.
    const base=await evaluate('location.href.split("#")[0]');await command('Page.navigate',{url:base+'&reload='+Date.now()+'#'+'a'.repeat(64)});
    await waitFor('document.querySelector("[name=token]")?.value.length===64');
    check(await evaluate('location.hash===""'),'Setup removes browser fragment after transferring token to form');
    check(errors.length===0,'No onboarding browser exceptions',errors.join(' | '));
}
module.exports={mockList,mockApi,run,isScriptUnavailable:()=>scriptUnavailable};
