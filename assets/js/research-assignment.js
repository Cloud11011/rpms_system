/* Existing onboarding forms share the same assignment controls and server validation. */
(() => {
    'use strict';
    window.PrismResearchAssignment={mount({form,group,title,adviser,protocol,pi}) {
        let record=null;
        const help=document.createElement('p'); help.className='prism-sub'; help.setAttribute('role','status');
        const controls=document.createElement('div'); controls.className='research-assignment-confirmations';
        const label=(text,input)=>{const el=document.createElement('label');if(input.type==='checkbox')el.className='check-label lifecycle-check';el.append(input,document.createTextNode(text));return el;};
        const duplicate=document.createElement('input');duplicate.type='checkbox';
        const confirmed=document.createElement('input');confirmed.type='checkbox';
        const reason=document.createElement('input');reason.maxLength=1000;reason.minLength=5;reason.setAttribute('aria-label','Reason for adding a member to a group with evidence');
        const dupLabel=label(' I reviewed similar titles. Create a separate group if this title already exists.',duplicate);
        const confirmLabel=label(' Confirm adding this member to the group and its existing evidence.',confirmed);
        const reasonLabel=label('Membership change reason ',reason);
        controls.append(dupLabel,confirmLabel,reasonLabel); group.closest('label').after(help,controls);
        function update(){
            const canonical=!!record?.projectId;
            const pending=!!record?.researchAssignment&&!canonical,locked=canonical||pending;
            const selected=(group.researchGroups||[]).find(g=>'project:'+g.id===group.value);
            const create=group.value==='__create__';
            group.required=!record && document.body.dataset.userRole==='admin';
            group.disabled=locked;
            title.required=create;title.readOnly=locked||!!selected;
            adviser.required=create;adviser.disabled=locked||!!selected;
            if(protocol)protocol.readOnly=locked||!!selected;
            if(pi)pi.disabled=locked;
            if(selected){
                title.value=selected.research_title;adviser.value=String(selected.adviser_id);
                if(protocol)protocol.value=selected.protocol_code||'';
                help.textContent=`${selected.research_title}\n${selected.adviser_name} · ${selected.academic_year}\nProtocol Code: ${selected.protocol_code||'Not yet assigned'}. Year levels may differ.\nSubmission holder: ${selected.submission_holder_name} (${selected.submission_holder_number})`;
            }else help.textContent=pending?'Use Review research assignment in Student Records for pending research changes.':canonical?'Official group changes use the audited controls in Student Records.':create?(title.value?'Research title: '+title.value+'\n':'')+'PRISM assigns the internal group ID after secure onboarding.':'Select a program and academic year, then create or join a research group.';
            dupLabel.hidden=!create;
            const evidence=!!Number(selected?.document_count);
            confirmLabel.hidden=reasonLabel.hidden=!evidence; confirmed.required=evidence;reason.required=evidence;
            if(!evidence){reason.value='';confirmed.checked=false;}
            if(canonical&&protocol)protocol.value=record.protocolCode||'';
        }
        group.addEventListener('change',update);group.addEventListener('research-groups-loaded',update);
        return {setRecord(value){record=value;duplicate.checked=false;update();},payload(){return {
            duplicateTitleConfirmed:duplicate.checked,membershipConfirmed:confirmed.checked,
            ...(reason.value.trim()?{membershipReason:reason.value.trim()}: {})
        };}};
    }};
})();
