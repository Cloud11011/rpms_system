(function () {
    'use strict';
    const host=document.querySelector('[data-communication-settings]');
    if(!host)return;
    const templateForm=host.querySelector('[data-template-form]');
    const templateSelect=templateForm.elements.templateKey;
    const templateInput=templateForm.elements.template;
    const reminderForm=host.querySelector('[data-reminder-form]');
    const intervalsInput=reminderForm.elements.intervals;
    const status=host.querySelector('[data-communication-status]');
    let templates={};
    let canManage=false;

    function setStatus(message,state='ready'){
        status.textContent=message;
        status.dataset.state=state;
    }
    function renderTemplate(){
        templateInput.value=templates[templateSelect.value]||'';
    }
    function enableControls(enabled){
        canManage=enabled;
        host.querySelectorAll('input,textarea,select,button').forEach(control=>{control.disabled=!enabled;});
    }
    async function load(){
        const data=await PrismUI.request('admin_settings_api.php?action=list');
        if(!data||typeof data.settingsAvailable!=='boolean'||!data.templates||!Array.isArray(data.reminderIntervals)){
            throw new Error('The communication settings response was incomplete. Refresh to try again.');
        }
        templates=data.templates;
        intervalsInput.value=data.reminderIntervals.join(', ');
        enableControls(data.settingsAvailable);
        renderTemplate();
        setStatus(data.settingsAvailable?'Changes apply to subsequent workflow notifications and scheduled deadline reminders.':data.message,'notice');
    }
    templateSelect.addEventListener('change',renderTemplate);
    templateForm.addEventListener('submit',async event=>{
        event.preventDefault();
        if(!canManage||!templateForm.reportValidity())return;
        const button=templateForm.querySelector('[type=submit]');
        await PrismUI.runAction(button,'Saving…',async()=>{
            try{
                const data=await PrismUI.postJson('admin_settings_api.php?action=save-template',{
                    templateKey:templateSelect.value,template:templateInput.value
                });
                templates=data.templates;
                renderTemplate();
                setStatus(data.message,'success');
            }catch(error){setStatus(error.message||'The template could not be saved.','error');}
        });
    });
    reminderForm.addEventListener('submit',async event=>{
        event.preventDefault();
        if(!canManage||!reminderForm.reportValidity())return;
        const values=intervalsInput.value.split(',').map(value=>value.trim());
        if(values.some(value=>!/^[1-9][0-9]*$/.test(value))){
            setStatus('Enter whole-number day intervals separated by commas.','error');
            intervalsInput.focus();
            return;
        }
        const intervals=values.map(Number);
        const button=reminderForm.querySelector('[type=submit]');
        await PrismUI.runAction(button,'Saving…',async()=>{
            try{
                const data=await PrismUI.postJson('admin_settings_api.php?action=save-reminders',{intervals});
                intervalsInput.value=data.reminderIntervals.join(', ');
                setStatus(data.message,'success');
            }catch(error){setStatus(error.message||'Reminder settings could not be saved.','error');}
        });
    });
    load().catch(error=>{
        enableControls(false);
        setStatus(error.message||'Communication settings could not be loaded. Refresh to try again.','error');
    });
})();
