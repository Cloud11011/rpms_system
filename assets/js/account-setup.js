(() => {
    'use strict';
    try{if(localStorage.getItem('prismTheme')==='dark')document.documentElement.classList.add('dark-theme');}catch(_){/* Storage is optional. */}
    const token=location.hash.slice(1),field=document.querySelector('[name="token"]');
    if(field && /^[a-f0-9]{64}$/.test(token))field.value=token;
    if(location.hash)history.replaceState(null,'',location.pathname);
    document.querySelectorAll('.setup-card .toggle-password').forEach(button=>{
        const input=document.getElementById(button.getAttribute('aria-controls'));
        if(!input)return;
        button.addEventListener('click',()=>{
            const visible=input.type==='password';
            input.type=visible?'text':'password';
            const label=input.id==='setupConfirm'?'confirm password':'password';
            button.textContent=visible?'Hide':'Show';
            button.setAttribute('aria-label',(visible?'Hide ':'Show ')+label);
            button.setAttribute('aria-pressed',String(visible));
        });
    });
    const pw=document.getElementById('setupPassword'),pw2=document.getElementById('setupConfirm'),btn=document.getElementById('setupSubmit'),count=document.getElementById('setupCount'),lenRule=document.getElementById('ruleLength'),matchRule=document.getElementById('ruleMatch'),matchText=document.getElementById('matchText');
    if(!pw||!pw2||!btn)return;
    const update=()=>{
        const n=[...pw.value].length,bytes=new TextEncoder().encode(pw.value).length,lenOk=n>=12&&bytes<=200,matchOk=pw2.value.length>0&&pw.value===pw2.value;
        count.textContent=n+' characters, '+bytes+' bytes';
        lenRule.className='setup-rule'+(lenOk?' ok':(n>0?' bad':''));
        pw.classList.toggle('is-bad',n>0&&!lenOk);
        matchRule.hidden=pw2.value.length===0;
        matchRule.className='setup-rule'+(matchOk?' ok':' bad');
        matchText.textContent=matchOk?'Passwords match':'Passwords do not match yet';
        pw2.classList.toggle('is-bad',pw2.value.length>0&&!matchOk);
        btn.disabled=!(lenOk&&matchOk);
    };
    pw.addEventListener('input',update);pw2.addEventListener('input',update);
    update();
})();
