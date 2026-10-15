/* Presentation/navigation only. Mutations remain the existing protected POST forms. */
document.addEventListener('DOMContentLoaded', () => {
    document.getElementById('dismissPublishingGuide')?.addEventListener('click', event => event.currentTarget.closest('.legal-publishing-guide').remove());
    const tabs = [...document.querySelectorAll('[data-policy-tab]')];
    const panels = [...document.querySelectorAll('[data-policy-panel]')];
    const selected = document.getElementById('versionDetails')?.dataset.policy;
    const initial = selected || new URLSearchParams(location.search).get('policy') || 'privacy';
    function activate(policy, focus = false) {
        if (!['privacy','terms'].includes(policy)) policy = 'privacy';
        if(selected && selected!==policy){document.querySelector(`[data-legal-cancel][data-policy="${policy}"]`)?.click();return;}
        tabs.forEach(tab => { const active=tab.dataset.policyTab===policy;tab.setAttribute('aria-selected',String(active));tab.tabIndex=active?0:-1;if(active&&focus)tab.focus(); });
        panels.forEach(panel => { panel.hidden=panel.dataset.policyPanel!==policy; });
    }
    tabs.forEach((tab,index) => {
        tab.addEventListener('click',()=>activate(tab.dataset.policyTab));
        tab.addEventListener('keydown',event=>{if(!['ArrowLeft','ArrowRight','Home','End'].includes(event.key))return;event.preventDefault();const n=event.key==='Home'?0:event.key==='End'?tabs.length-1:(index+(event.key==='ArrowRight'?1:-1)+tabs.length)%tabs.length;activate(tabs[n].dataset.policyTab,true);});
    });
    activate(initial);
    const form=document.querySelector('[data-legal-draft]');
    if(!form)return;
    const fields=[...form.querySelectorAll('input:not([type=hidden]),textarea')];
    const snapshot=fields.map(field=>field.value);
    const dirty=()=>fields.some((field,index)=>field.value!==snapshot[index]);
    let leaving=false;
    window.addEventListener('beforeunload',event=>{if(!leaving&&dirty()){event.preventDefault();event.returnValue='';}});
    const confirm=document.getElementById('legalDiscardDialog');
    let destination=null,opener=null;
    document.querySelectorAll('[data-legal-cancel]').forEach(link=>link.addEventListener('click',event=>{
        if(!dirty())return;
        event.preventDefault();destination=link.href;opener=link;confirm.showModal();document.getElementById('legalContinueEditing').focus();
    }));
    document.getElementById('legalContinueEditing').addEventListener('click',()=>confirm.close());
    document.getElementById('legalDiscardChanges').addEventListener('click',()=>{leaving=true;location.assign(destination);});
    confirm.addEventListener('close',()=>opener?.focus());
    form.addEventListener('submit',()=>{leaving=true;});
});
