(() => {
    'use strict';
    const form=document.getElementById('completeProfileForm');
    const academic=document.getElementById('onboardingAcademics');
    const fields=academic?PrismAcademicFields.mount(academic):null;
    form.addEventListener('submit',async event=>{
        event.preventDefault();
        const button=form.querySelector('[type="submit"]'), result=document.getElementById('onboardingResult');
        button.disabled=true;result.textContent='Saving profile…';
        try {
            const payload=Object.fromEntries(new FormData(form));
            if(fields){const choices=fields.payload();delete choices.course;Object.assign(payload,choices);}
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
