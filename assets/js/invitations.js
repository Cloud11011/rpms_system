(() => {
    'use strict';
    window.PrismInvitations={mount({type,role,refresh}) {
        const dialog=document.getElementById('inviteDialog'),form=document.getElementById('inviteForm');
        const email=document.getElementById('inviteEmail'),assignment=document.getElementById('inviteAdviser');
        const result=document.getElementById('inviteResult'),title=document.getElementById('inviteTitle');
        const emailError=document.getElementById('inviteEmailError');
        const domains=window.PRISM_INVITATION_DOMAINS || [];
        const academicRoot=document.getElementById('inviteAcademicFields');
        const academics=academicRoot?PrismAcademicFields.mount(academicRoot):null;
        const group=document.getElementById('inviteGroup'),research=document.getElementById('inviteResearch'),protocol=document.getElementById('inviteProtocol'),pi=document.getElementById('invitePi');
        const researchAssignment=group?PrismResearchAssignment.mount({form,group,title:research,adviser:assignment,protocol,pi}):null;
        const cancelAssignment=document.getElementById('cancelAssignment');
        cancelAssignment?.addEventListener('change',()=>{
            form.querySelectorAll('input,select').forEach(el=>{if(el===cancelAssignment||el===email)return;if(cancelAssignment.checked){el.dataset.wasDisabled=String(el.disabled);el.disabled=true;}else el.disabled=el.dataset.wasDisabled==='true';});
        });
        function validateEmail() {
            email.setCustomValidity('');
            const normalized=email.value.trim();
            let message='';
            if(!email.disabled && normalized) {
                if(email.validity.typeMismatch || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(normalized)) message='Enter a valid email address.';
                else if(!domains.includes(normalized.slice(normalized.lastIndexOf('@')+1).toLowerCase())) message='Please use an approved institutional email address.';
            }
            emailError.textContent=message;
            email.setAttribute('aria-invalid',message?'true':'false');
            email.setCustomValidity(message);
            return !message;
        }
        email.addEventListener('input',validateEmail);
        email.addEventListener('blur',()=>{email.value=email.value.trim();validateEmail();});
        let target=null,returnFocus=null,pendingSelection=null;
        async function open(button,record=null) {
            if(pendingSelection){group.removeEventListener('research-groups-loaded',pendingSelection);pendingSelection=null;}
            returnFocus=PrismUI.actionOrigin(button);target=record;form.reset();result.textContent='';
            form.querySelectorAll('[data-was-disabled]').forEach(el=>{el.disabled=el.dataset.wasDisabled==='true';delete el.dataset.wasDisabled;});
            const intended=record?.researchAssignment?.intent;
            academics?.setRecord(intended?{programKey:intended.academic.program_key,academicUnitKey:intended.academic.academic_unit_key,academicYear:intended.academic.academic_year,yearLevel:intended.academic.year_level,course:intended.academic.course}:record?.profileStatus==='Complete'?record:null);
            researchAssignment?.setRecord(null);
            const cancelLabel=document.getElementById('cancelAssignmentLabel');if(cancelLabel)cancelLabel.hidden=!record?.researchAssignment;
            title.textContent=record?'Assign Pending Student':'Invite '+(type==='student'?'Student':'Adviser');
            email.value=record?.email || '';email.disabled=!!record;
            validateEmail();
            form.querySelector('[type="submit"]').textContent=record?'Save assignment':'Send invitation';
            dialog.showModal();(record&&assignment?assignment:email).focus();
            if(assignment) {
                assignment.replaceChildren(new Option('Unassigned',''));
                try {const data=await PrismUI.request('students_api.php?action=adviser_options');data.advisers.forEach(a=>assignment.add(new Option(a.full_name,a.id)));assignment.value=record?.adviserId || '';}
                catch(error){result.textContent=error.message;}
            }
            if(intended && group){research.value=intended.title;protocol.value=intended.protocolCode||'';assignment.value=intended.adviserId;pi.checked=!!intended.pi;
                const select=()=>{group.value=intended.selection;group.dispatchEvent(new Event('change'));pendingSelection=null;};
                if([...group.options].some(o=>o.value===intended.selection))select();else {pendingSelection=select;group.addEventListener('research-groups-loaded',select,{once:true});}
                result.textContent='Assignment: '+record.researchAssignment.state+'. Verify the intended academic program, year and research group.';
            }
        }
        document.getElementById('inviteAccount').addEventListener('click',event=>open(event.currentTarget));
        document.getElementById('cancelInvite').addEventListener('click',()=>dialog.close());
        dialog.addEventListener('close',()=>{if(pendingSelection){group.removeEventListener('research-groups-loaded',pendingSelection);pendingSelection=null;}email.disabled=false;(returnFocus?.isConnected?returnFocus:document.getElementById('inviteAccount')).focus();});
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            if(!target) {email.value=email.value.trim();if(!validateEmail() || !email.reportValidity()){email.focus();return;}}
            const button=form.querySelector('[type="submit"]');if(button.disabled)return;button.disabled=true;
            try {
                const payload=target?{targetId:target.id,adviserId:assignment.value || null}:{accountType:type,email:email.value.trim()};
                if(!target&&assignment)payload.adviserId=assignment.value || null;
                if(group){Object.assign(payload,academics.payload(true),researchAssignment.payload(),{
                    group:group.value,research:research.value,protocolCode:protocol.value,isPrincipalInvestigator:pi.checked,
                    adviserId:assignment.value||null,
                    ...(target?{expectedRevision:target.researchAssignment?.revision||0,cancelAssignment:document.getElementById('cancelAssignment').checked}: {})
                });delete payload.course;}
                const data=await PrismUI.postJson('account_invitation_api.php?action='+(target?'assign':'invite'),payload);
                result.textContent=data.message;
                await refresh();
                if(data.emailSent!==false || target){dialog.close();PrismUI.toast(data.message,'success');}
            } catch(error){result.textContent=error.message;result.focus();}
            finally{button.disabled=false;}
        });
        return {addActions(actions,record) {
            if(record.archivedAt || (record.profileStatus!=='Pending' && !(role==='admin'&&type==='student'&&!record.projectId)))return;
            const resend=document.createElement('button');resend.type='button';resend.className='prism-btn is-icon prism-invitation-action';resend.title='Resend Invitation';resend.setAttribute('aria-label',resend.title);resend.dataset.prismTooltip='';resend.innerHTML='<i class="fa-regular fa-envelope" aria-hidden="true"></i>';
            resend.addEventListener('click',()=>PrismUI.runAction(resend,'Sending…',async()=>{
                try{const data=await PrismUI.postJson('account_invitation_api.php?action=resend',{accountType:type,targetId:record.id});PrismUI.toast(data.message,data.emailSent?'success':'error');}
                catch(error){PrismUI.toast(error.message,'error');}
            }));if(record.profileStatus==='Pending')actions.append(resend);
            if(role==='admin'&&type==='student'){const assign=document.createElement('button');assign.type='button';assign.className='prism-btn is-icon prism-invitation-action';assign.title=group?'Review research assignment':'Assign Adviser';assign.setAttribute('aria-label',assign.title);assign.dataset.prismTooltip='';assign.innerHTML='<i class="fa-solid fa-user-plus" aria-hidden="true"></i>';assign.addEventListener('click',()=>open(assign,record));actions.append(assign);}
        }};
    }};
})();
