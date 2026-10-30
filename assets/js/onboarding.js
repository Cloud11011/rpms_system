(() => {
    'use strict';
    const form=document.getElementById('completeProfileForm');
    const academic=document.getElementById('onboardingAcademics');
    const fields=academic?PrismAcademicFields.mount(academic):null;
    if(fields) fetch('account_invitation_api.php?action=me').then(r=>r.json()).then(data=>{
        const intent=data.researchAssignment?.intent;
        if(!intent)return;
        const title=form.querySelector('[name="research"]');
        if(title){title.value=intent.title;title.readOnly=true;title.setAttribute('aria-describedby','assignedResearchHelp');}
        const help=document.createElement('p');help.id='assignedResearchHelp';help.className='prism-sub';
        help.textContent='RPMS assigned: '+intent.title+'. Program and academic year must match the intended group. Differences require Admin review.';
        academic.before(help);
        fields.setRecord({programKey:intent.academic.program_key,academicUnitKey:intent.academic.academic_unit_key,academicYear:intent.academic.academic_year,yearLevel:intent.academic.year_level,course:intent.academic.course});
    }).catch(()=>{});
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        const button=form.querySelector('[type="submit"]'), result=document.getElementById('onboardingResult');
        button.disabled=true;result.textContent='Saving profile…';
        try {
            const payload=Object.fromEntries(new FormData(form));
            if(fields){const choices=fields.payload(true);delete choices.course;Object.assign(payload,choices);}
            const response=await fetch('account_invitation_api.php?action=complete',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
            const data=await response.json();
            if(!response.ok || !data.ok)throw new Error(data.message || 'Profile could not be completed.');
            window.location.assign(data.redirect);
        } catch(error){result.textContent=error.message;result.focus();button.disabled=false;}
    });
    // Enable submission only after the catalog and protected JSON handler are ready.
    document.getElementById('onboardingResult').textContent='';
    form.querySelector('[type="submit"]').disabled=false;
})();
