(() => {
    'use strict';
    window.PrismInvitations={mount({type,role,refresh}) {
        const dialog=document.getElementById('inviteDialog'),form=document.getElementById('inviteForm');
        const email=document.getElementById('inviteEmail'),assignment=document.getElementById('inviteAdviser');
        const result=document.getElementById('inviteResult'),title=document.getElementById('inviteTitle');
        const emailError=document.getElementById('inviteEmailError');
        const domains=window.PRISM_INVITATION_DOMAINS || [];
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
        let target=null,returnFocus=null;
        async function open(button,record=null) {
            returnFocus=button;target=record;form.reset();result.textContent='';
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
        }
        document.getElementById('inviteAccount').addEventListener('click',event=>open(event.currentTarget));
        document.getElementById('cancelInvite').addEventListener('click',()=>dialog.close());
        dialog.addEventListener('close',()=>{email.disabled=false;(returnFocus?.isConnected?returnFocus:document.getElementById('inviteAccount')).focus();});
        form.addEventListener('submit',async event=>{
            event.preventDefault();
            if(!target) {email.value=email.value.trim();if(!validateEmail() || !email.reportValidity()){email.focus();return;}}
            const button=form.querySelector('[type="submit"]');if(button.disabled)return;button.disabled=true;
            try {
                const payload=target?{targetId:target.id,adviserId:assignment.value || null}:{accountType:type,email:email.value.trim()};
                if(!target&&assignment)payload.adviserId=assignment.value || null;
                const data=await PrismUI.postJson('account_invitation_api.php?action='+(target?'assign':'invite'),payload);
                result.textContent=data.message;
                await refresh();
                if(data.emailSent!==false || target){dialog.close();PrismUI.toast(data.message,'success');}
            } catch(error){result.textContent=error.message;result.focus();}
            finally{button.disabled=false;}
        });
        return {addActions(actions,record) {
            if(record.profileStatus!=='Pending'||record.archivedAt)return;
            const resend=document.createElement('button');resend.type='button';resend.className='prism-btn';resend.textContent='Resend Invitation';
            resend.addEventListener('click',()=>PrismUI.runAction(resend,'Sending…',async()=>{
                try{const data=await PrismUI.postJson('account_invitation_api.php?action=resend',{accountType:type,targetId:record.id});PrismUI.toast(data.message,data.emailSent?'success':'error');}
                catch(error){PrismUI.toast(error.message,'error');}
            }));actions.append(resend);
            if(role==='admin'&&type==='student'){const assign=document.createElement('button');assign.type='button';assign.className='prism-btn';assign.textContent='Assign Adviser';assign.addEventListener('click',()=>open(assign,record));actions.append(assign);}
        }};
    }};
})();
