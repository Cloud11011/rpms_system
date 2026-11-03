(function () {
    'use strict';

    // Theme toggle (shared behavior with dashboard.php)
    const themeToggle = document.getElementById('themeToggle');
    if (themeToggle) {
        themeToggle.addEventListener('click', () => {
            const isDark = document.documentElement.classList.toggle('dark-theme');
            try { localStorage.setItem('prismTheme', isDark ? 'dark' : 'light'); } catch (_) {}
        });
    }

    const isAdviser = document.body.dataset.management === 'adviser';
    const archiveWorkspace = document.body.dataset.archiveWorkspace === 'true';
    const lifecyclePopulation = archiveWorkspace ? 'archived' : 'active';
    const apiUrl = isAdviser ? 'advisers_api.php' : 'students_api.php';
    // "loggedInRole" = the role of the person USING this page (admin or
    // adviser). Not to be confused with `isAdviser` above, which means
    // "this page instance manages the adviser roster".
    const loggedInRole = document.body.dataset.userRole || 'admin';
    const stageLabels = window.PRISM_STAGE_LABELS || {};
    const activeStageKeys = new Set(['initial_submission','resubmission_amendment','approval_data_gathering','ierb_completed']);
    const studentRecordsView = !isAdviser && !archiveWorkspace;

    function labelForStage(stageKey) {
        return stageLabels[stageKey] || stageKey;
    }

    // Populate the stage <select> option TEXT with the configured form/
    // document names, while keeping the underlying VALUE as "Stage 1" etc
    // so every other part of the app (filters, DB values) stays unchanged
    // (feature request 7: dynamic stage labels).
    const stageSelect = document.getElementById('stage');
    if (stageSelect) {
        stageSelect.querySelectorAll('option').forEach(opt => {
            if (opt.value) opt.textContent = labelForStage(opt.value);
        });
    }

    // Advisers can add/assign only their OWN students (feature request 4);
    // the adviser dropdown would be misleading to show since the server
    // ignores it and forces their own id anyway, so hide it entirely.
    const adviserFieldLabel = document.getElementById('adviserFieldLabel');
    if (!isAdviser && loggedInRole === 'adviser' && adviserFieldLabel) {
        adviserFieldLabel.style.display = 'none';
    }

    // Protocol Code / Principal Investigator are RPMS-office decisions
    // (feature requests 10-11), not something an adviser sets themselves.
    const protocolCodeField = document.getElementById('protocolCodeField');
    const principalField = document.getElementById('principalField');
    if (!isAdviser && loggedInRole === 'adviser') {
        if (protocolCodeField) protocolCodeField.style.display = 'none';
        if (principalField) principalField.style.display = 'none';
    }

    const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, char => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
    })[char]);

    const searchInput = document.getElementById('recordSearch');
    if (studentRecordsView) searchInput.placeholder = 'Search name, ID, research title or protocol code';
    const addBtn = document.getElementById('addRecord');
    const countEl = document.getElementById('recordCount');
    const rowsEl = document.getElementById('recordRows');

    const modal = document.getElementById('recordModal');
    const modalTitle = document.getElementById('modalTitle');
    const closeBtn = document.getElementById('closeRecord');
    const cancelBtn = document.getElementById('cancelRecord');
    const form = document.getElementById('recordForm');
    const idField = document.getElementById('recordId');
    const nameField = document.getElementById('recordName');
    const accountIdField = document.getElementById('accountId');
    const emailField = document.getElementById('recordEmail');

    const academicFields = isAdviser ? null : PrismAcademicFields.mount(document.getElementById('studentAcademicFields'));
    let researchAssignment=null;
    let editIntro=null;
    let studentProfileSteps=null;
    if(studentRecordsView) {
        const formGrid=form.querySelector('.management-form-grid');
        const section=(className,title,description,fields)=>{
            const panel=document.createElement('section');panel.className=`student-edit-section wide ${className}`;
            const heading=document.createElement('div');heading.className='student-edit-section-heading';
            const titleNode=document.createElement('h3');titleNode.textContent=title;
            const help=document.createElement('p');help.textContent=description;
            heading.append(titleNode,help);
            const fieldGrid=document.createElement('div');fieldGrid.className='student-edit-section-fields';
            fields.forEach(field=>{if(field)fieldGrid.append(field);});
            panel.append(heading,fieldGrid);return panel;
        };
        const label=id=>document.getElementById(id)?.closest('label');
        const academic=document.getElementById('studentAcademicFields');
        formGrid.replaceChildren(
            section('student-edit-personal','Personal information',"Contact details and the student's research title.",[label('recordName'),label('accountId'),label('recordEmail'),label('research')]),
            section('student-edit-academic','Academic details','Choose the program, year level, and academic year.',[academic]),
            section('student-edit-assignment','Research assignment','Choose a research project and keep the adviser assignment up to date.',[label('group'),label('adviser'),label('requirements')]),
            section('student-edit-ierb','IERB progress','Review the current ethics review stage and workflow status.',[label('stage'),label('recordStatus'),label('protocolCode'),label('isPrincipal')])
        );
        formGrid.classList.add('student-profile-sections');
        const ierb=formGrid.querySelector('.student-edit-ierb');
        const assignment=formGrid.querySelector('.student-edit-assignment');
        if(loggedInRole==='adviser') {
            ierb.hidden=true;
            assignment.querySelector('.student-edit-section-fields').append(label('isPrincipal'));
        }
        else assignment.querySelector('.student-edit-section-fields').append(ierb);
        const steps=[formGrid.querySelector('.student-edit-personal'),formGrid.querySelector('.student-edit-academic'),assignment];
        const wizard=document.createElement('div');wizard.className='student-profile-wizard';
        const nav=document.createElement('nav');nav.className='student-profile-step-nav';nav.setAttribute('role','tablist');nav.setAttribute('aria-label','Student profile sections');
        const content=document.createElement('div');content.className='student-profile-step-content';
        const labels=['Personal info','Academic details','Research assignment'];
        steps.forEach((panel,index)=>{
            const tab=document.createElement('button');tab.type='button';tab.className='student-profile-step';tab.setAttribute('role','tab');tab.setAttribute('aria-selected',index===0?'true':'false');tab.setAttribute('aria-controls',`student-profile-step-${index+1}`);
            tab.id=`student-profile-tab-${index+1}`;panel.setAttribute('aria-labelledby',tab.id);tab.setAttribute('tabindex',index===0?'0':'-1');
            tab.innerHTML=`<span class="student-profile-step-number">${index+1}</span><span>${labels[index]}</span>`;
            tab.addEventListener('click',()=>selectProfileStep(index));
            tab.addEventListener('keydown',event=>{if(event.key==='ArrowDown'||event.key==='ArrowRight'){event.preventDefault();nav.children[(index+1)%steps.length].focus();}else if(event.key==='ArrowUp'||event.key==='ArrowLeft'){event.preventDefault();nav.children[(index+steps.length-1)%steps.length].focus();}});nav.append(tab);
            panel.classList.add('student-profile-step-panel');panel.id=`student-profile-step-${index+1}`;panel.setAttribute('role','tabpanel');content.append(panel);
        });
        wizard.append(nav,content);formGrid.replaceWith(wizard);
        if(loggedInRole==='adviser') form.append(ierb);
        function selectProfileStep(index){
            steps.forEach((panel,i)=>{panel.hidden=i!==index;nav.children[i].setAttribute('aria-selected',i===index?'true':'false');nav.children[i].tabIndex=i===index?0:-1;});
            if(studentProfileSteps) studentProfileSteps.current=index;
        }
        studentProfileSteps={wizard,nav,steps,select:selectProfileStep,current:0};
        // Native validation runs before submit; reveal its first invalid field's panel.
        form.addEventListener('invalid', event => {
            const firstInvalid = [...form.elements].find(field => field.willValidate && !field.validity.valid);
            if (event.target !== firstInvalid) { event.preventDefault(); return; }
            const index = steps.indexOf(firstInvalid.closest('.student-profile-step-panel'));
            if (index >= 0) selectProfileStep(index);
        }, true);
        editIntro=document.createElement('p');editIntro.className='student-edit-intro';editIntro.textContent="Changes are saved to this student's record.";editIntro.hidden=true;
        modal.querySelector('.management-modal-head > div').append(editIntro);
        modal.querySelector('.management-modal-head > div > span').textContent='STUDENT PROFILE';
        const avatar=document.createElement('span');avatar.className='student-profile-avatar';avatar.setAttribute('aria-hidden','true');modal.querySelector('.management-modal-head').prepend(avatar);
        const actions=form.querySelector('.management-actions');actions.classList.add('student-profile-footer');
        const cancelAction=actions.querySelector('#cancelRecord');cancelAction.classList.add('student-profile-cancel');
        const submitAction=actions.querySelector('[type="submit"]');submitAction.classList.add('management-primary','student-profile-save');
        const saveContent=document.createElement('span');saveContent.className='student-profile-save-content';saveContent.innerHTML='<i class="fa-solid fa-floppy-disk" aria-hidden="true"></i> Save record';submitAction.replaceChildren(saveContent);
        const syncStudentAvatar=()=>{
            if(!studentProfileSteps)return;
            const name=nameField.value.trim();
            modal.querySelector('.student-profile-avatar').textContent=name?name.split(/\s+/).map(part=>part[0]).slice(0,2).join('').toUpperCase():'?';
        };
        nameField.addEventListener('input',syncStudentAvatar);
        nameField.addEventListener('change',syncStudentAvatar);
        actions.querySelector('#cancelRecord').textContent='Cancel';
    }
    let returnFocus = null;
    let records = [];
    let loadError = false;
    let deletionAvailability = {available:false,message:'Checking permanent-deletion availability...'};
    async function loadDeletionAvailability() {
        if(loggedInRole!=='admin') return;
        try {
            const data=await PrismUI.request('account_lifecycle_api.php?action=availability');
            deletionAvailability={available:data.available===true,message:data.message || 'Permanent deletion is unavailable.'};
        } catch(_) {
            deletionAvailability={available:false,message:'Permanent deletion is unavailable because deployment verification is required. Contact the system operator.'};
        }
        const availabilityMessage=document.getElementById('permanentDeleteAvailability');
        if(availabilityMessage) {
            availabilityMessage.dataset.available=String(deletionAvailability.available);
            const copy=document.createElement('span'),lead=document.createElement('strong');
            lead.textContent=deletionAvailability.available?'Permanent deletion verification is available. ':'Permanent deletion is locked. ';
            const explanation=deletionAvailability.message.replace(/^Permanent deletion is unavailable because\s+/i,'');
                copy.append(lead,document.createTextNode(explanation.charAt(0).toUpperCase()+explanation.slice(1)));
            availabilityMessage.innerHTML=archiveIcon(deletionAvailability.available?'check':'lock');availabilityMessage.append(copy);
        }
        render();
    }
    const deletionAvailabilityReady=loadDeletionAvailability();

    const definitions = isAdviser ? [['department','Departments'],['status','Account statuses'],['group','Research groups']]
      : [['academicUnitKey','Academic units'],['programKey','Programs'],['academicYear','Academic years'],['yearLevel','Year levels'],['group','Research groups'],['stage','Stages'],['status','Statuses'],['protocol','Protocol status']];
    if (!isAdviser && loggedInRole === 'admin') definitions.push(['adviserId','Research advisers']);
    const filters = PrismUI.recordFilters(document.getElementById('recordFilters'), definitions);
    const statusSelect=document.getElementById('recordFilters_status');
    const stageFilter=document.getElementById('recordFilters_stage');
    const statusPills=document.createElement('div');document.querySelector('.management-controls').after(statusPills);
    let stageCounts=null,statusCounts=null,archiveStatusCounts=null;
    function syncStatusPills(){
        if(archiveWorkspace) {
            const entries=archiveStatusEntries();
            PrismUI.statusPills(statusPills,entries,statusSelect,null);
            [...statusPills.querySelectorAll('button')].forEach((button,index)=>{
                const text=document.createElement('span');text.textContent=entries[index][1];
                const number=document.createElement('span');number.className='archive-count';
                const count=archiveStatusCounts?.[entries[index][0]];number.textContent=Number.isInteger(count)?String(count):'—';
                button.replaceChildren(text,number);
            });
            return;
        }

        if(studentRecordsView && stageFilter) {
            statusPills.classList.add('student-stage-filters');
            const stages=[...stageFilter.options].filter(option=>activeStageKeys.has(option.value));
            PrismUI.statusPills(statusPills,[['','All'],...stages.map(option=>[
                option.value,
                option.textContent.replace(/^Stage \d+\s*-\s*/,'')
            ])],stageFilter,stageCounts);
            return;
        }
        PrismUI.statusPills(statusPills,[['','All'],...[...statusSelect.options].filter(option=>option.value).map(option=>[option.value,option.textContent])],statusSelect,isAdviser?statusCounts:null);
    }
    const sort = PrismUI.recordFilters(document.getElementById('recordFilters'), [['sortBy','Sort by'],['direction','Direction']]);
    const sortOptions = isAdviser
      ? [['name','Name'],['employeeId','Employee ID'],['email','Email'],['department','Academic unit / Department'],['status','Account status'],...(archiveWorkspace ? [['archivedAt','Archived date']] : [])]
      : [...(studentRecordsView ? [['updated','Last updated'],['created','Date added']] : []),['name','Name'],['studentId','Student ID'],['email','Email'],['group','Research group'],['adviser','Research adviser'],['stage','IERB stage'],['status','Status'],['academicYear','Academic year'],...(archiveWorkspace ? [['archivedAt','Archived date']] : [])];
    sort.update({sortBy:sortOptions.map(([value,label])=>({value,label})),direction:[{value:'ASC',label:'Ascending / Oldest first'},{value:'DESC',label:'Descending / Newest first'}]});
    sort.controls[0].options[0].textContent = studentRecordsView ? 'Newest updated (default)' : 'Name (default)';
    sort.controls[1].options[0].textContent = studentRecordsView ? 'Default direction' : 'Ascending (default)';
    if(studentRecordsView) {
        // Student Records list the most recently updated record first unless another order is chosen (B7-F13).
        const chosen=sort.query;
        sort.query=()=>{ const q=chosen(); return q.sortBy ? {...q,direction:q.direction||(['updated','created'].includes(q.sortBy)?'DESC':'ASC')} : {sortBy:'updated',direction:'DESC'}; };
    }
    if (loggedInRole === 'admin') {
        const lifecycleFilters=PrismUI.recordFilters(document.getElementById('recordFilters'), [...(archiveWorkspace ? [['retention','Retention status']] : []),['profile','Profile status']]);
        filters.controls.push(...lifecycleFilters.controls);
        const baseQuery=filters.query, baseUpdate=filters.update;
        filters.query=()=>({...baseQuery(),...lifecycleFilters.query()});
        filters.update=options=>{baseUpdate(options);lifecycleFilters.update(options);};
    }
    const filterControls = [...filters.controls,...sort.controls];
    const pager = PrismUI.recordPager(rowsEl.closest('table').parentElement, countEl, [searchInput,...filterControls], loadRecords,
        {host:document.getElementById('recordFilters'),toolbar:document.querySelector('.management-controls')});
    let pageActionsHost = null;
    if(studentRecordsView || isAdviser || archiveWorkspace) {
        const toolbar=document.querySelector('.management-controls');
        const actions=document.createElement('div');
        actions.className='student-page-actions';
        actions.setAttribute('role','group');
        actions.setAttribute('aria-label',isAdviser?'Adviser record actions':'Student record actions');
        pageActionsHost = actions;
        [[document.getElementById('inviteAccount'),isAdviser?'Invite adviser':'Invite student','fa-regular fa-envelope'],[addBtn,isAdviser?'Add adviser':'Add student','fa-solid fa-user-plus']].forEach(([button,label,icon])=>{
            if(!button)return;
            button.classList.add('student-icon-action');
            button.classList.add('student-local-tooltip');
            button.setAttribute('aria-label',label);
            button.innerHTML=`<i class="${icon}" aria-hidden="true"></i>`;
            actions.append(button);
        });
        const archiveLink=toolbar?.querySelector('a[href^="admin_archived_accounts.php"],a[href^="admin_students.php"],a[href^="admin_advisers.php"]');
        if(archiveLink) {
            archiveLink.classList.add('student-archive-link','student-icon-action','student-local-tooltip');
            archiveLink.innerHTML=archiveWorkspace
                ? '<i class="fa-solid fa-users" aria-hidden="true"></i>'
                : '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>';
            archiveLink.setAttribute('aria-label',archiveWorkspace?'View current records':'View archived accounts');
            actions.append(archiveLink);
        }
        const filterDisclosure=toolbar?.querySelector('.prism-filter-disclosure');
        const separator=document.createElement('span');
        separator.className='student-toolbar-separator';
        separator.setAttribute('aria-hidden','true');
        if(filterDisclosure) {
            filterDisclosure.after(separator,actions);
        } else toolbar?.append(separator,actions);
        const filterButton=toolbar?.querySelector('.prism-filter-disclosure>button');
        if(filterButton) {
            filterButton.classList.add('student-filter-action');
            filterButton.setAttribute('aria-label','Filters');
            filterButton.dataset.prismTooltip='';
        }
    }
    document.querySelectorAll('.student-local-tooltip').forEach(control=>{
        const position=()=>{ const rect=control.getBoundingClientRect(); control.style.setProperty('--tooltip-left',Math.max(12,Math.min(rect.left,innerWidth-232))+'px'); control.style.setProperty('--tooltip-top',Math.min(rect.bottom+8,innerHeight-70)+'px'); };
        control.addEventListener('pointerenter',position); control.addEventListener('focus',position);
        window.addEventListener('resize',position); window.addEventListener('scroll',position,{passive:true});
    });
    const retentionUI=loggedInRole==='admin'?PrismRetentionAdmin.mount({
        type:isAdviser?'adviser':'student', rows:rowsEl, archived:archiveWorkspace, getScope:()=>({q:searchInput.value.trim(),...filters.query(),lifecycle:lifecyclePopulation}),
        getRecords:()=>records, getAvailability:()=>deletionAvailability, ensureDeletionAvailability:()=>deletionAvailabilityReady, refresh:loadRecords,
        openBulk:openBulkDelete, openRecovery,
        reviewCleanup:async()=>{document.getElementById('recordFilters_retention').value='cleanup';pager.reset();await loadRecords();}
    }):null;
    if(studentRecordsView || isAdviser || archiveWorkspace) {
        const selectionMode=document.getElementById('retentionSelectionMode');
        if(selectionMode) {
            selectionMode.classList.add('student-selection-toggle');
            selectionMode.dataset.prismTooltip='';
            const filterPanel=document.getElementById('recordFilters');
            const clearFilters=filterPanel?.querySelector('.prism-filter-clear');
            if(filterPanel && clearFilters) {
                const selectionControls=document.createElement('div');
                selectionControls.className='prism-filter-selection-controls';
                const divider=document.createElement('span');
                divider.className='prism-filter-selection-divider';
                divider.setAttribute('aria-hidden','true');
                divider.textContent='|';
                filterPanel.insertBefore(selectionControls,clearFilters);
                selectionControls.append(clearFilters,divider,selectionMode);
            } else filterPanel?.append(selectionMode);
        }
    }
    function archiveIcon(name) {
        const paths={filter:'<path d="M4 6h16M4 12h16M4 18h16"/><circle cx="8" cy="6" r="2" fill="currentColor"/><circle cx="16" cy="12" r="2" fill="currentColor"/><circle cx="10" cy="18" r="2" fill="currentColor"/>',search:'<circle cx="11" cy="11" r="6.5"/><path d="m20 20-4-4"/>',
            student:'<path d="m2 9 10-5 10 5-10 5zM6 11.5V16c0 1.5 3 3 6 3s6-1.5 6-3v-4.5"/>',
            adviser:'<circle cx="12" cy="8" r="4"/><path d="M4 20a8 8 0 0 1 16 0"/>',
            lock:'<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
            check:'<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
            archive:'<path d="M3 7h18v4H3zM5 11v8h14v-8M10 15h4"/>',
            restore:'<path d="M4 12a8 8 0 1 0 2.6-5.9M4 4v5h5"/>',
            chevron:'<path d="m6 9 6 6 6-6"/>'};
        return '<svg class="archive-ui-icon" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">'+(paths[name]||'')+'</svg>';
    }
    function archiveStatusEntries() {
        const defaults=isAdviser?[['Active','Active'],['Inactive','Inactive']]:[['On Track','On track'],['Pending','Pending'],['Delayed','Delayed']];
        const seen=new Set(defaults.map(([value])=>value));
        const extra=[...statusSelect.options].filter(option=>option.value&&!seen.has(option.value)).map(option=>[option.value,option.textContent]);
        return [['','All'],...defaults,...extra];
    }
    async function refreshArchiveOverview(data,request) {
        const query={action:'list',preview:1,lifecycle:'archived',q:searchInput.value.trim(),...filters.query(),status:''};
        const permitted=new Set((data.filterOptions?.status||[]).map(option=>String(option.value)));
        const count=async status=>{
            if(status&&!permitted.has(status))return 0;
            if(!status&&!statusSelect.value)return data.total;
            if(status&&isAdviser&&Number.isInteger(data.statusCounts?.[status]))return data.statusCounts[status];
            const response=await PrismUI.request(apiUrl+'?'+new URLSearchParams({...query,status}));
            if(!Number.isInteger(response.total))throw new Error('Archive count unavailable.');
            return response.total;
        };
        const result=await Promise.allSettled([
            Promise.all(archiveStatusEntries().map(async([status])=>[status,await count(status)])),
            ...['student','adviser'].map(async type=>{
                const response=await PrismUI.request((type==='student'?'students_api.php':'advisers_api.php')+'?'+new URLSearchParams({action:'list',lifecycle:'archived',preview:1}));
                if(!Number.isInteger(response.total))throw new Error('Archive count unavailable.');
                return response.total;
            })
        ]);
        if(request!==requestSequence)return;
        archiveStatusCounts=result[0].status==='fulfilled'?Object.fromEntries(result[0].value):null;
        ['student','adviser'].forEach((type,index)=>{
            const node=document.querySelector('[data-archive-count="'+type+'"]');
            if(node){node.textContent=result[index+1].status==='fulfilled'?String(result[index+1].value):'—';node.removeAttribute('aria-busy');}
        });
    }
    function archiveDate(value,full=false) {
        const raw=String(value||'').trim().replace(' ','T');
        const date=new Date(/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d+)?$/.test(raw)?raw+'+08:00':raw);
        if(Number.isNaN(date.getTime()))return null;
        return {date,text:new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',month:'short',day:'numeric',year:'numeric',...(full?{hour:'numeric',minute:'2-digit'}:{})}).format(date)};
    }
    function archiveRelative(value) {
        const parsed=archiveDate(value);if(!parsed)return '';
        const day=date=>{const parts=new Intl.DateTimeFormat('en-US',{timeZone:'Asia/Manila',year:'numeric',month:'numeric',day:'numeric'}).formatToParts(date);const get=type=>Number(parts.find(part=>part.type===type).value);return Date.UTC(get('year'),get('month')-1,get('day'));};
        const difference=Math.round((day(parsed.date)-day(new Date()))/86400000);
        return difference===0?'Today':difference>0?'in '+difference+' day'+(difference===1?'':'s'):Math.abs(difference)+' day'+(difference===-1?'':'s')+' ago';
    }
    if(archiveWorkspace) {
        const toolbar=document.querySelector('.management-controls'),tabs=document.querySelector('.archive-tabs');
        if(tabs) {
            [...tabs.querySelectorAll('a')].forEach((link,index)=>{
                const type=index?'adviser':'student',label=document.createElement('span'),count=document.createElement('span');
                label.textContent=index?'Research advisers':'Students';count.className='archive-count';count.dataset.archiveCount=type;count.setAttribute('aria-busy','true');count.textContent='—';
                link.className='archive-type-tab';link.innerHTML=archiveIcon(type);link.append(label,count);
            });
            toolbar.append(tabs);
        }
        const searchIcon=toolbar.querySelector('.management-search > i');if(searchIcon)searchIcon.outerHTML=archiveIcon('search');
        const statusBar=document.createElement('div');statusBar.className='archive-statusbar';toolbar.after(statusBar);statusBar.append(statusPills,countEl);
        const disclosure=toolbar.querySelector('.prism-filter-disclosure');if(disclosure){statusBar.append(disclosure);const icon=disclosure.querySelector('button > i');if(icon)icon.outerHTML=archiveIcon('filter');}
        const selectToggle=document.getElementById('retentionSelectionMode');if(selectToggle)statusBar.append(selectToggle);
        toolbar.querySelectorAll('.student-toolbar-separator').forEach(node=>node.remove());
        toolbar.querySelector('.student-page-actions')?.remove();
    }

    const invitationUI=archiveWorkspace ? null : PrismInvitations.mount({type:isAdviser?'adviser':'student',role:loggedInRole,refresh:loadRecords});
    const dirty = PrismUI.dirtyForm(form);
    let requestSequence = 0;
    // Read the current assignment API; project identifiers, not member rows or titles, are the identity.
    async function loadAdviserAssignments(items, request) {
        const assigned=new Map(items.map(record=>[String(record.id),new Map()]));
        const collect=students=>{
            if(!Array.isArray(students))throw new Error('Could not load research group assignments.');
            students.forEach(student=>{
                if(student.archivedAt || student.profileStatus==='Pending')return;
                const identifier=String(student.projectIdentifier || student.group || '').trim();
                const projects=assigned.get(String(student.adviserId));
                if(identifier&&projects) {
                    const title=[student.projectTitle,student.research,student.groupName].find(value=>typeof value==='string'&&value.trim())?.trim()||'Research title not recorded';
                    if(!projects.has(identifier)||projects.get(identifier)==='Research title not recorded')projects.set(identifier,title);
                }
            });
        };
        try {
            const scope=await PrismUI.request('students_api.php?'+new URLSearchParams({action:'list',lifecycle:'active',perPage:100,sortBy:'studentId',direction:'ASC'}));
            if(request!==requestSequence)return;
            collect(scope.students);
            if(!Number.isSafeInteger(scope.total) || !Number.isSafeInteger(scope.limit) || scope.limit<1)throw new Error('Could not verify assignment totals.');
            if(scope.total>scope.limit) {
                if(!Array.isArray(scope.filterOptions?.adviserId))throw new Error('Could not verify the adviser assignment scope.');
                const permitted=new Set((scope.filterOptions?.adviserId || []).map(option=>String(option.value)));
                await Promise.all(items.filter(record=>permitted.has(String(record.id))).map(async record=>{
                    assigned.set(String(record.id),new Map());
                    for(let page=1;request===requestSequence;page++) {
                        const data=await PrismUI.request('students_api.php?'+new URLSearchParams({action:'list',lifecycle:'active',adviserId:record.id,page,perPage:100,sortBy:'studentId',direction:'ASC'}));
                        if(request!==requestSequence)return;
                        collect(data.students);
                        if(!Number.isSafeInteger(data.total) || !Number.isSafeInteger(data.limit) || data.limit<1)throw new Error('Could not verify assignment totals.');
                        if(page*data.limit>=data.total)break;
                    }
                }));
            }
            items.forEach(record=>{
                record.assignmentProjects=[...assigned.get(String(record.id))].map(([identifier,title])=>({identifier,title}))
                    .sort((a,b)=>a.title.localeCompare(b.title)||a.identifier.localeCompare(b.identifier));
                record.groups=record.assignmentProjects.map(project=>project.identifier);
                record.assignmentCount=record.assignmentProjects.length;
            });
        } catch (_) {
            // An unavailable count must never be presented as an adviser having no groups.
            items.forEach(record=>{record.assignmentCount=null;});
        }
    }

    let activeAdviserGroups=null;
    function adviserGroupPopover(trigger,record) {
        const panel=document.createElement('div');panel.className='adviser-groups-popover';
        panel.id='adviserGroups-'+record.id+'-'+requestSequence;panel.setAttribute('popover','manual');
        panel.setAttribute('role','dialog');panel.setAttribute('aria-label','Assigned research groups for '+(record.name||'this adviser'));
        const heading=document.createElement('div');heading.className='adviser-groups-heading';
        const title=document.createElement('h3');title.textContent='Assigned research groups';
        const dismiss=document.createElement('button');dismiss.type='button';dismiss.setAttribute('aria-label','Close assigned research groups');
        dismiss.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true"><path d="m6 6 12 12M18 6 6 18"/></svg>';
        heading.append(title,dismiss);
        const list=document.createElement('ul');list.className='adviser-groups-list';list.tabIndex=0;list.setAttribute('aria-label','Research titles');
        (record.assignmentProjects||[]).forEach(project=>{const item=document.createElement('li');item.textContent=project.title;list.append(item);});
        panel.append(heading,list);
        trigger.setAttribute('aria-controls',panel.id);trigger.setAttribute('aria-expanded','false');trigger.setAttribute('aria-haspopup','dialog');
        let pinned=false,closeTimer,suppressFocus=false;
        const isOpen=()=>panel.matches(':popover-open');
        const position=()=>{
            if(!isOpen())return;
            const margin=12,anchor=trigger.getBoundingClientRect(),box=panel.getBoundingClientRect();
            const left=Math.max(margin,Math.min(anchor.left,innerWidth-box.width-margin));
            const below=anchor.bottom+8,above=anchor.top-box.height-8;
            const preferred=below+box.height<=innerHeight-margin?below:above;
            const top=Math.max(margin,Math.min(preferred,innerHeight-box.height-margin));
            panel.style.left=left+'px';panel.style.top=top+'px';
        };
        const observer=new ResizeObserver(position);
        const outside=event=>{if(!panel.contains(event.target)&&!trigger.contains(event.target))close();};
        const escape=event=>{if(event.key==='Escape'){event.preventDefault();event.stopPropagation();close(panel.contains(document.activeElement)||document.activeElement===trigger);}};
        const cleanup=()=>{
            clearTimeout(closeTimer);pinned=false;trigger.setAttribute('aria-expanded','false');
            observer.disconnect();window.removeEventListener('resize',position);document.removeEventListener('scroll',position,true);
            document.removeEventListener('pointerdown',outside,true);document.removeEventListener('click',outside,true);document.removeEventListener('keydown',escape,true);
            if(activeAdviserGroups?.panel===panel)activeAdviserGroups=null;
        };
        const close=(restoreFocus=false)=>{
            if(isOpen())panel.hidePopover();cleanup();
            if(restoreFocus){suppressFocus=true;trigger.focus({preventScroll:true});queueMicrotask(()=>{suppressFocus=false;});}
        };
        const open=()=>{
            clearTimeout(closeTimer);
            if(!trigger.isConnected)return;
            if(activeAdviserGroups&&activeAdviserGroups.panel!==panel)activeAdviserGroups.close();
            if(!isOpen())panel.showPopover();
            trigger.setAttribute('aria-expanded','true');activeAdviserGroups={panel,close};
            observer.observe(panel);window.addEventListener('resize',position);document.addEventListener('scroll',position,true);
            document.addEventListener('pointerdown',outside,true);document.addEventListener('click',outside,true);document.addEventListener('keydown',escape,true);position();
        };
        const scheduleClose=(fromFocus=false)=>{
            clearTimeout(closeTimer);
            closeTimer=setTimeout(()=>{
                if(pinned&&!fromFocus)return;
                if(!panel.matches(':hover')&&!trigger.matches(':hover')&&!panel.contains(document.activeElement)&&document.activeElement!==trigger)close();
            },180);
        };
        trigger.addEventListener('pointerenter',event=>{if(event.pointerType==='mouse')open();});
        trigger.addEventListener('pointerleave',()=>scheduleClose());
        trigger.addEventListener('focus',()=>{if(!suppressFocus&&trigger.matches(':focus-visible'))open();});
        trigger.addEventListener('blur',()=>scheduleClose(true));
        trigger.addEventListener('click',()=>{if(isOpen()&&pinned)close();else{open();pinned=true;}});
        trigger.addEventListener('keydown',event=>{if(event.key==='ArrowDown'){event.preventDefault();open();list.focus();}});
        panel.addEventListener('pointerenter',()=>clearTimeout(closeTimer));
        panel.addEventListener('pointerleave',()=>scheduleClose());
        panel.addEventListener('pointerdown',()=>{pinned=true;});
        panel.addEventListener('focusout',()=>scheduleClose(true));
        panel.addEventListener('toggle',()=>{if(isOpen())position();else cleanup();});
        dismiss.addEventListener('click',()=>close(true));
        return panel;
    }

    async function loadRecords() {
        filters.reconcile();
        retentionUI?.syncScope();
        searchReload.cancel();
        const request = ++requestSequence;
        pager.loading();
        loadError = false;
        try {
            const data = await PrismUI.request(apiUrl + '?' + new URLSearchParams({action:'list',page:pager.page,perPage:pager.pageSize,q:searchInput.value.trim(),...filters.query(),...sort.query(),lifecycle:lifecyclePopulation}));
            const items = isAdviser ? data.advisers : data.students;
            if (!Array.isArray(items)) throw new Error('Could not load records.');
            if (request !== requestSequence) return;
            if(isAdviser&&!archiveWorkspace)await loadAdviserAssignments(items,request);
            if (request !== requestSequence) return;
            records = items;
            statusCounts=isAdviser&&data.statusCounts&&typeof data.statusCounts==='object'?data.statusCounts:null;
            filters.update(data.filterOptions);
            if(studentRecordsView && stageFilter) {
                [...stageFilter.options].forEach(option=>{
                    if(option.value && !activeStageKeys.has(option.value)) option.remove();
                });
                if(!activeStageKeys.has(stageFilter.value)) stageFilter.value='';
            }
            if(archiveWorkspace)await refreshArchiveOverview(data,request);
            if(request!==requestSequence)return;
            stageCounts=studentRecordsView&&data.stageCounts&&typeof data.stageCounts==='object'?data.stageCounts:null;
            syncStatusPills();
            pager.render({ ...data, total: data.total ?? records.length });
            retentionUI?.onPage(data.total ?? records.length);
        } catch (e) {
            if (request !== requestSequence) return;
            records = [];
            stageCounts=null; statusCounts=null; archiveStatusCounts=null; syncStatusPills();
            loadError = true;
            pager.error();
            PrismUI.toast(e.message || 'Could not load records.', 'error');
        }
        if (!isAdviser) {
            await loadAdviserOptions();
        }
        render();
    }

    async function loadAdviserOptions() {
        const select = document.getElementById('adviser');
        if (!select) return;
        try {
            const data = await PrismUI.request('students_api.php?action=adviser_options');
            const options = data.ok ? data.advisers : [];
            select.querySelectorAll('option:not(:first-child)').forEach(o => o.remove());
            options.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.full_name;
                select.appendChild(opt);
            });
        } catch (_) { /* keep default */ }
    }

    function matchesSearch(record, term) {
        if (!term) return true;
        const haystack = isAdviser
            ? [record.name, record.employeeId, record.email, record.department, record.groups]
            : [record.name, record.studentId, record.email, record.research, record.group, record.adviserName];
        return haystack.some(v => String(v ?? '').toLowerCase().includes(term));
    }

    function render() {
        if(isAdviser&&!archiveWorkspace)activeAdviserGroups?.close();
        const term = (searchInput.value || '').trim().toLowerCase();
        const filtered = records;
        rowsEl.replaceChildren();

        if (loadError) {
            countEl.textContent = 'Records unavailable';
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = (archiveWorkspace ? 7 : (isAdviser ? 6 : studentRecordsView ? 5 : 7))+(retentionUI?1:0);
            cell.className = 'empty-state';
            const message = document.createElement('p');
            message.setAttribute('role', 'alert');
            message.textContent = 'Could not load records. Please try again.';
            const retry = document.createElement('button');
            retry.type = 'button';
            retry.className = 'prism-btn';
            retry.textContent = 'Retry loading records';
            retry.addEventListener('click', async () => {
                retry.disabled = true;
                await loadRecords();
            });
            cell.append(message, retry);
            row.append(cell);
            rowsEl.append(row);
            return;
        }
        if (!filtered.length) {
            const tr = document.createElement('tr');
            tr.innerHTML = `<td colspan="${(archiveWorkspace ? 7 : (isAdviser ? 6 : studentRecordsView ? 5 : 7))+(retentionUI?1:0)}" class="empty-state">No ${isAdviser ? 'adviser' : 'student'} records found.</td>`;
            rowsEl.appendChild(tr);
            return;
        }

        filtered.forEach(record => {
            const tr = document.createElement('tr');
            if (archiveWorkspace) {
                const lc=record.lifecycle || {};
                const status=!deletionAvailability.available ? deletionAvailability.message : lc.purgeBlockReason || 'Eligible for permanent deletion';
                const retentionStatus=lc.retentionHold ? 'Retention Hold' : lc.unresolvedWorkflow ? 'Unresolved workflow' : lc.cleanupEligible ? 'Eligible for retention cleanup' : lc.approachingRetention ? 'Approaching retention cleanup' : 'Archived';
                tr.innerHTML=`<td><strong>${escapeHtml(record.name || 'Profile incomplete')}</strong><br><small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.studentId || record.employeeId || 'Not recorded')}</td>
                    <td>${escapeHtml(record.department || record.course || 'Not recorded')}</td>
                    <td>${escapeHtml(isAdviser ? (record.groups || []).join(', ') || 'No current assigned groups' : record.group || 'No group')}<br><small>${escapeHtml(record.adviserName || (isAdviser ? '' : 'Unassigned'))}</small></td>
                    <td>${escapeHtml(record.archivedAt)}</td>
                    <td>${escapeHtml(retentionStatus)}<br><small>${escapeHtml(status)}</small><br><small>Retention date: ${escapeHtml(lc.retentionAt || 'Not recorded')}</small>${lc.holdReason ? '<br><small>'+escapeHtml(lc.holdReason)+'</small>' : ''}</td><td class="row-actions"></td>`;
            } else if (isAdviser) {
                tr.innerHTML = `
                    <td><strong>${escapeHtml(record.name || 'Profile incomplete')}</strong>${record.profileStatus==='Pending'?' <span class="prism-badge">Pending Profile</span>':''}${record.archivedAt ? ' <span class="prism-badge">Archived</span>' : ''}<small>${escapeHtml(record.email)}</small></td>
                    <td>${escapeHtml(record.employeeId || '—')}</td>
                    <td>${escapeHtml(record.department || 'N/A')}</td>
                    <td class="adviser-groups"></td>
                    <td>${PrismUI.badge(record.projectStatus||record.status)}</td>
                    <td class="row-actions"></td>`;
            } else {
                const protocolBadge = record.protocolCode
                    ? `<span class="protocol-badge" title="Protocol Code">${escapeHtml(record.protocolCode)}</span>`
                    : '<span class="muted">Not yet assigned</span>';
                const piBadge = record.isPrincipalInvestigator ? ' <span class="pi-badge" title="Principal Investigator">PI</span>' : '';
                if(studentRecordsView) {
                    const nameParts=String(record.name||'').trim().split(/\s+/).filter(Boolean);
                    const initials=((nameParts[0]||'S')[0]+(nameParts.length>1?nameParts[nameParts.length-1][0]:'')).toUpperCase();
                    tr.innerHTML=`
                        <td><div class="student-name-layout"><span class="student-avatar" aria-hidden="true">${escapeHtml(initials)}</span><span class="student-name-copy"><strong>${escapeHtml(record.name || 'Profile incomplete')}</strong>${record.profileStatus==='Pending'?' <span class="prism-badge">Pending Profile</span>':''}${record.archivedAt ? ' <span class="prism-badge">Archived</span>' : ''}<small>${escapeHtml(record.email)}</small></span><div class="row-actions"></div></div></td>
                        <td>${escapeHtml(record.studentId || '—')}</td>
                        <td><span class="student-group-name">${escapeHtml(record.research || record.researchAssignment?.intent?.title || 'Not set')}</span><small class="student-research-name">Protocol Code: ${escapeHtml(record.protocolCode || 'Not yet assigned')}${record.researchAssignment && record.researchAssignment.state!=='activated'?' ? Assignment: '+escapeHtml(record.researchAssignment.state):''}</small></td>
                        <td>${escapeHtml(record.adviserName || 'Unassigned')}</td>
                        <td>${record.profileStatus==='Pending'?'Complete profile first':`<div class="prism-pill-row"><span class="stage-tag" title="${escapeHtml(record.projectStage||record.stage)}">${escapeHtml(record.stageLabel || labelForStage(record.stage))}</span>${PrismUI.badge(record.projectStatus||record.status)}</div><small class="student-protocol-meta">${protocolBadge}${piBadge}</small>`}</td>`;
                } else {
                    tr.innerHTML = `
                        <td><strong>${escapeHtml(record.name || 'Profile incomplete')}</strong>${record.profileStatus==='Pending'?' <span class="prism-badge">Pending Profile</span>':''}${record.archivedAt ? ' <span class="prism-badge">Archived</span>' : ''}<br><small>${escapeHtml(record.email)}</small></td>
                        <td>${escapeHtml(record.studentId || '—')}</td>
                        <td>${escapeHtml(record.research || 'Not set')}<br><small>${escapeHtml(record.group || 'No group')}</small></td>
                        <td>${escapeHtml(record.adviserName || 'Unassigned')}</td>
                        <td>${record.profileStatus==='Pending'?'Complete profile first':`<span class="student-record-field-label">IERB status</span><div class="prism-pill-row"><span class="stage-tag" title="${escapeHtml(record.projectStage||record.stage)}">${escapeHtml(record.stageLabel || labelForStage(record.stage))}</span>${PrismUI.badge(record.projectStatus||record.status)}</div>`}</td>
                        <td><div class="prism-pill-row">${protocolBadge}${piBadge}</div></td>
                        <td class="row-actions"></td>`;
                }
            }
            if (isAdviser && !archiveWorkspace) {
                const groups = Array.isArray(record.groups) ? record.groups : [];
                const cell = tr.querySelector('.adviser-groups');
                if (!groups.length) cell.textContent = 'No assigned research groups';
                const groupRow = document.createElement('div');
                groupRow.className = 'prism-pill-row';
                if (groups.length) cell.append(groupRow);
                groups.forEach(group => {
                    const chip = document.createElement('span');
                    chip.className = 'prism-badge adviser-group-chip';
                    chip.textContent = group;
                    groupRow.append(chip);
                });
            } else if (!isAdviser) PrismAcademicFields.appendSummary(tr.cells[2], record);
            const actions = document.createElement('div');
            actions.className='lifecycle-actions';
            tr.querySelector('.row-actions').append(actions);
            const accountLabel=isAdviser?'Adviser':'Student';
            const archived=!!record.archivedAt;
            const editBtn = document.createElement('button');
            editBtn.type='button';
            editBtn.className = 'icon-btn lifecycle-action';
            editBtn.title = isAdviser ? 'Edit research adviser profile' : 'Edit student profile';
            editBtn.setAttribute('aria-label',editBtn.title);
            editBtn.innerHTML = '<i class="fa-solid fa-pen" aria-hidden="true"></i>';
            editBtn.addEventListener('click', () => openModal(record));
            const delBtn = document.createElement('button');
            delBtn.type='button';
            delBtn.className = 'icon-btn lifecycle-action lifecycle-archive';
            delBtn.title = isAdviser ? 'Archive adviser' : 'Archive Student';
            if(archived) delBtn.title=accountLabel+' is already archived';
            delBtn.disabled=archived;
            delBtn.setAttribute('aria-label',delBtn.title);
            delBtn.innerHTML = '<i class="fa-solid fa-box-archive" aria-hidden="true"></i>';
            delBtn.addEventListener('click', e => PrismUI.runAction(e.currentTarget,'Processing...',() => deleteRecord(record)));
            if(!archiveWorkspace && record.profileStatus!=='Pending') actions.append(editBtn);
            invitationUI?.addActions(actions,record);
            if(record.projectId && loggedInRole==='admin' && !archived) {
                const change=document.createElement('button');change.type='button';change.className='icon-btn lifecycle-action';
                change.title='Edit official group information';change.setAttribute('aria-label',change.title);change.innerHTML='<i class="fa-solid fa-users-gear" aria-hidden="true"></i>';
                change.addEventListener('click',async()=>{
                    let choices;try{choices=await PrismUI.request('students_api.php?action=project_options&projectId='+record.projectId);}catch(error){PrismUI.toast(error.message,'error');return;}
                    const options=list=>list.map(item=>'<option value="'+item.id+'">'+escapeHtml(item.full_name)+'</option>').join('');
                    const decision=PrismUI.confirm({title:'Official group change',confirmText:'Save group change',message:record.research+' — changes apply to the entire group and require a reason.',
                        extraHtml:'<label>Change<select id="groupChangeOperation"><option value="protocol">Protocol Code</option><option value="title">Research title</option><option value="adviser">Assigned Adviser</option><option value="pi">Principal Investigator</option></select></label><label>New value<input id="groupChangeValue" maxlength="255" value="'+escapeHtml(record.protocolCode||'')+'"></label><label>Adviser<select id="groupChangeAdviser">'+options(choices.advisers)+'</select></label><label>Principal Investigator<select id="groupChangePi">'+options(choices.members)+'</select></label><label>Audit reason<textarea id="groupChangeReason" required minlength="5" maxlength="1000"></textarea></label>',
                        collect:()=>({operation:document.getElementById('groupChangeOperation').value,value:document.getElementById('groupChangeValue').value,reason:document.getElementById('groupChangeReason').value,adviserId:Number(document.getElementById('groupChangeAdviser').value),studentId:Number(document.getElementById('groupChangePi').value)}),validate:values=>values.reason.trim().length<5?'Give a reason of at least five characters.':null});
                    const operationField=document.getElementById('groupChangeOperation'),valueField=document.getElementById('groupChangeValue');
                    const adviserField=document.getElementById('groupChangeAdviser'),piField=document.getElementById('groupChangePi');
                    function syncGroupChange(){
                        valueField.closest('label').hidden=!['title','protocol'].includes(operationField.value);
                        adviserField.closest('label').hidden=operationField.value!=='adviser';piField.closest('label').hidden=operationField.value!=='pi';
                        valueField.value=operationField.value==='title'?record.research:record.protocolCode||'';
                        adviserField.value=String(record.adviserId||'');piField.value=String(record.id);
                    }
                    operationField.addEventListener('change',syncGroupChange);syncGroupChange();
                    const accepted=await decision;
                    if(!accepted)return;
                    try {
                        const data=accepted.values||{};
                        const operation=data.operation||document.getElementById('groupChangeOperation')?.value;
                        const value=data.value??document.getElementById('groupChangeValue')?.value;
                        const reason=data.reason??document.getElementById('groupChangeReason')?.value;
                        await PrismUI.postJson('students_api.php?action=project_update',{operation,projectId:record.projectId,expectedRevision:record.projectRevision,reason,
                            protocolCode:operation==='protocol'?value:undefined,research:operation==='title'?value:undefined,adviserId:operation==='adviser'?data.adviserId:undefined,studentId:operation==='pi'?data.studentId:undefined});
                        await loadRecords();PrismUI.toast('Official group information updated.','success');
                    }catch(error){PrismUI.toast(error.message,'error');}
                });actions.append(change);
            }
            if (loggedInRole === 'admin') {
                if (!archiveWorkspace) actions.append(delBtn);
                const hardDelete=document.createElement('button');
                hardDelete.type='button';
                hardDelete.className='icon-btn lifecycle-action lifecycle-danger';
                const disabledReason=!archived?'Archive '+accountLabel+' before permanent deletion.':
                    (!deletionAvailability.available?deletionAvailability.message:(record.lifecycle?.manualEligible?'':(record.lifecycle?.purgeBlockReason || 'Server eligibility must be checked.')));
                hardDelete.title=disabledReason || 'Permanently Delete '+accountLabel;
                hardDelete.setAttribute('aria-label','Permanently Delete '+accountLabel+(disabledReason?'. '+disabledReason:''));
                // Keep blocked actions keyboard-focusable so their explanation is discoverable.
                hardDelete.setAttribute('aria-disabled',String(!!disabledReason));
                hardDelete.innerHTML='<i class="fa-solid fa-trash" aria-hidden="true"></i>';
                hardDelete.addEventListener('click',()=>{if(!disabledReason)openPermanentDelete(record,hardDelete);});
                if (archiveWorkspace) actions.append(hardDelete);
                if (archived) {
                    const restore=document.createElement('button');restore.type='button';restore.className='icon-btn lifecycle-action';restore.title='Restore '+accountLabel;restore.setAttribute('aria-label',restore.title);restore.innerHTML='<i class="fa-solid fa-rotate-left" aria-hidden="true"></i>';restore.addEventListener('click',()=>retentionUI.singleAction(record,'restore',restore));actions.append(restore);
                    const hold=document.createElement('button');hold.type='button';hold.className='icon-btn lifecycle-action';const held=record.lifecycle?.retentionHold;hold.title=(held?'Remove':'Place')+' Retention Hold';hold.setAttribute('aria-label',hold.title);hold.innerHTML='<i class="fa-solid fa-pause" aria-hidden="true"></i>';hold.addEventListener('click',()=>retentionUI.singleAction(record,held?'remove_hold':'hold',hold));actions.append(hold);
                    if(record.lifecycle?.overrideAvailable) {
                        const override=document.createElement('button');override.type='button';override.className='icon-btn lifecycle-action lifecycle-danger';override.title='Delete Now ? Override Grace Period';override.setAttribute('aria-label',override.title);override.setAttribute('aria-disabled',String(!deletionAvailability.available));override.innerHTML='<i class="fa-solid fa-clock" aria-hidden="true"></i>';override.addEventListener('click',()=>{if(deletionAvailability.available)openPermanentDelete(record,override,'grace_period_override');});actions.append(override);
                    }
                }
                retentionUI.attachRow(tr,record);
            }
            PrismUI.actionMenu(actions,{label:'More actions for '+(record.name||'student')});
            actions.querySelector('.prism-action-panel')?.classList.add('student-record-actions-panel');
            actions.querySelector('.prism-action-item[aria-label^="Archive"]')?.classList.add('student-record-archive-action');
            rowsEl.appendChild(tr);
            if(studentRecordsView) {
                const cells=[...tr.cells].filter(cell=>!cell.classList.contains('retention-select'));
                const card=document.createElement('article');
                const statusClass=record.status==='On Track'?'is-on-track':record.status==='Delayed'?'is-delayed':'is-pending';
                card.className=`student-result-card ${statusClass}`;
                card.setAttribute('aria-label',record.name||'Student record');
                const identity=cells[0].querySelector('.student-name-layout');
                identity.querySelector('.row-actions')?.remove();
                const avatar=identity.querySelector('.student-avatar');
                if(avatar)avatar.textContent=PrismUI.recordInitials(record.name,'Student');
                const id=document.createElement('small');id.textContent='ID '+(record.studentId||'Not recorded');
                identity.querySelector('.student-name-copy').insertBefore(id,identity.querySelector('.student-name-copy small'));
                const block=(title,html)=>{const el=document.createElement('div');const label=document.createElement('small');label.className='student-result-label';label.textContent=title;el.append(label);const body=document.createElement('div');body.innerHTML=html;el.append(body);return el;};
                const academic=cells[2].querySelector('.academic-record-summary');
                const details=document.createElement('details');details.className='student-result-details';
                const summary=document.createElement('summary');
                summary.setAttribute('aria-expanded','false');
                const summaryText=document.createElement('span');summaryText.textContent='Show details';
                summary.append(summaryText);
                summary.insertAdjacentHTML('beforeend','<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>');
                details.append(summary);
                details.addEventListener('toggle',()=>{summaryText.textContent=details.open?'Hide details':'Show details';summary.setAttribute('aria-expanded',String(details.open));});
                const academicGrid=document.createElement('div');academicGrid.className='student-academic-details-grid';
                const researchGroup=block('Research group',escapeHtml(record.group||'Not recorded'));
                academicGrid.append(researchGroup);
                if(academic) {
                    academic.querySelectorAll('dt').forEach(term=>{
                        if(term.textContent.includes('Academic Unit')) {
                            term.parentElement.remove();
                            return;
                        }
                        term.textContent=term.textContent.replace(/:\s*$/, '');
                    });
                    academicGrid.append(academic);
                }
                details.append(academicGrid);
                const toolbar=document.createElement('div');toolbar.className='student-result-actions';
                const actionMenu=actions.querySelector('.prism-action-menu');
                if(actionMenu)toolbar.append(actionMenu);
                const researchInfo=document.createElement('div');researchInfo.className='student-result-research-info';
                const researchLabel=document.createElement('span');researchLabel.className='student-result-label';researchLabel.textContent='Research title';
                const researchTitle=document.createElement('strong');researchTitle.className='student-result-research-title';
                const researchValue=String(record.research||record.researchAssignment?.intent?.title||'').trim();
                researchTitle.textContent=researchValue || 'Title not recorded';
                if(!researchValue)researchTitle.classList.add('is-unavailable');
                const protocolCode=document.createElement('p');protocolCode.className='student-result-protocol-code';
                const protocolLabel=document.createElement('span');protocolLabel.textContent='Protocol code:';
                const protocolText=String(record.protocolCode||'').trim();
                const protocolValue=document.createElement('span');protocolValue.className=protocolText?'student-protocol-value':'is-unavailable';
                protocolValue.textContent=protocolText || 'Not assigned';
                protocolCode.append(protocolLabel,protocolValue);
                researchInfo.append(researchLabel,researchTitle,protocolCode);

                const ierbStatus=document.createElement('div');ierbStatus.className='student-result-ierb-status';
                const statusLabel=document.createElement('span');statusLabel.className='student-result-label';statusLabel.textContent='IERB status';
                const stageKey=record.projectStage||record.stage;
                const stageNumber=({initial_submission:1,resubmission_amendment:2,approval_data_gathering:3,ierb_completed:4})[stageKey] || (activeStageKeys.has(stageKey)?Number(stageKey.replace('Stage ','')):0);
                const stageText=stageNumber?`Stage ${stageNumber}`:'Research setup pending';
                const chips=document.createElement('div');chips.className='student-result-status-chips';
                const stageBadge=document.createElement('span');stageBadge.className='student-result-chip student-result-stage-chip';
                stageBadge.innerHTML='<i class="fa-solid fa-layer-group" aria-hidden="true"></i>';
                const stageBadgeText=document.createElement('span');stageBadgeText.textContent=stageText;stageBadge.append(stageBadgeText);
                const statusText=record.projectStatus||record.status||'Pending';
                const statusIcon=statusText==='On Track'?'fa-circle-check':statusText==='Delayed'?'fa-triangle-exclamation':'fa-clock';
                const statusBadge=document.createElement('span');statusBadge.className='student-result-chip '+(statusText==='On Track'?'is-on-track':statusText==='Delayed'?'is-delayed':'is-pending');
                statusBadge.innerHTML=`<i class="fa-solid ${statusIcon}" aria-hidden="true"></i>`;
                const statusBadgeText=document.createElement('span');statusBadgeText.textContent=statusText;statusBadge.append(statusBadgeText);
                chips.append(stageBadge,statusBadge);
                ierbStatus.append(statusLabel,chips);

                const adviserName=String(record.adviserName||'').trim();
                const adviserAssigned=adviserName!==''&&adviserName.toLowerCase()!=='unassigned';
                const adviser=document.createElement('div');adviser.className='student-result-adviser';
                const adviserLabel=document.createElement('span');adviserLabel.className='student-result-label';adviserLabel.textContent='Assigned adviser';
                const adviserCopy=document.createElement('div');adviserCopy.className='student-result-adviser-copy'+(adviserAssigned?'':' is-unassigned');
                const adviserAvatar=document.createElement('span');adviserAvatar.className='student-result-adviser-avatar';
                adviserAvatar.setAttribute('aria-hidden','true');
                adviserAvatar.textContent=adviserAssigned?PrismUI.recordInitials(adviserName,''): '!';
                const adviserText=document.createElement('span');adviserText.textContent=adviserAssigned?adviserName:'Not assigned';
                adviserCopy.append(adviserAvatar,adviserText);adviser.append(adviserLabel,adviserCopy);
                card.append(identity,researchInfo,ierbStatus,adviser,toolbar,details);
                const host=document.createElement('td');host.colSpan=tr.cells.length;
                const selection=tr.querySelector('.retention-select');if(selection){const wrapper=document.createElement('span');wrapper.className='retention-select';wrapper.hidden=selection.hidden;while(selection.firstChild)wrapper.append(selection.firstChild);card.insertBefore(wrapper,identity);}
                host.append(card);tr.replaceChildren(host);
            } else if(archiveWorkspace) {
                const lc=record.lifecycle || {};
                const card=document.createElement('article');card.className='student-result-card archive-result-card';
                const identity=document.createElement('div');identity.className='student-name-layout';
                const avatar=document.createElement('span');avatar.className='student-avatar';avatar.setAttribute('aria-hidden','true');avatar.textContent=PrismUI.recordInitials(record.name,isAdviser?'Adviser':'Student');
                const copy=document.createElement('div');copy.className='student-name-copy';
                const name=document.createElement('strong');name.textContent=record.name || 'Profile incomplete';copy.append(name);
                [record.studentId || record.employeeId || 'ID not recorded',record.email || 'Email not recorded'].forEach(value=>{const line=document.createElement('small');line.textContent=value;copy.append(line);});
                if(record.profileStatus==='Pending')copy.insertAdjacentHTML('beforeend',PrismUI.badge('Pending Profile'));
                identity.append(avatar,copy);
                const block=(label,value)=>{const field=document.createElement('div');field.className='archive-fact';const heading=document.createElement('span');heading.className='student-result-label';heading.textContent=label;const content=document.createElement('div');content.className='archive-value';content.textContent=value || 'Not recorded';field.append(heading,content);return field;};
                const dated=(label,value)=>{const parsed=archiveDate(value),field=block(label,parsed?.text || 'Date not recorded');field.title=[archiveDate(value,true)?.text,archiveRelative(value)].filter(Boolean).join(' · ');return field;};
                const facts=document.createElement('div');facts.className='archive-record-fields archive-identity-fields';
                facts.append(dated('Archived',record.archivedAt));
                const metadata=document.createElement('div');metadata.className='archive-record-fields archive-retention-fields';
                const retention=dated('Keep until',lc.retentionAt);metadata.append(retention);
                const retentionLabel=lc.retentionHold?'Retention hold':lc.unresolvedWorkflow?'Unresolved workflow':lc.cleanupEligible?'Eligible for retention cleanup':lc.approachingRetention?'Approaching retention cleanup':'Archived';
                const retentionBadge=document.createElement('span');retentionBadge.className='archive-status-pill '+(lc.retentionHold?'is-held':lc.unresolvedWorkflow||lc.approachingRetention?'is-warning':lc.cleanupEligible?'is-eligible':'is-neutral');retentionBadge.textContent=retentionLabel;retention.append(retentionBadge);
                const details=document.createElement('details');details.className='student-result-details';
                const summary=document.createElement('summary');
                const fields=document.createElement('div');fields.className='student-academic-details-grid';
                const projectName=isAdviser?(record.groups||[]).join(', ')||'No current assigned groups':record.projectTitle||record.research||record.groupName||'Research title not recorded';
                const academic=tr.querySelector('.academic-record-summary');
                const academicValues=new Map(academic?[...academic.children].map(row=>[row.querySelector('dt')?.textContent.replace(/:\s*$/,'').trim(),row.querySelector('dd')?.textContent]):[]);
                const detailCard=(title,rows)=>{const section=document.createElement('section');section.className='archive-detail-card';const heading=document.createElement('h4');heading.textContent=title;section.append(heading);rows.filter(Boolean).forEach(([label,value])=>{const row=document.createElement('div');const term=document.createElement('span');term.className='archive-detail-label';term.textContent=label;const content=document.createElement('strong');content.textContent=value||'Not recorded';row.append(term,content);section.append(row);});return section;};
                const unit=academicValues.get('Academic Unit (catalog grouping)');
                const level=[academicValues.get('Year Level')||record.yearLevel,academicValues.get('Academic Year')||record.academicYear].filter(Boolean).join(', ').replace(/(\d{4})-(\d{4})/,'$1\u2013$2');
                const archivedOn=archiveDate(record.archivedAt)?.text,keptUntil=archiveDate(lc.retentionAt)?.text,relative=archiveRelative(lc.retentionAt);
                fields.append(
                    detailCard('Research',isAdviser?[['Assigned groups',projectName]]:[['Study',projectName],['Previous adviser',record.adviserName||'Unassigned'],['Still pending',record.requirements||'None']]),
                    detailCard('Academic',isAdviser?[['Department',record.department]]:[['Program',academicValues.get('Program')||record.course],unit?['Academic unit',unit]:null,['Level',level]]),
                    detailCard('Retention',[['Archived on',archivedOn||'Date not recorded'],['Kept until',keptUntil?keptUntil+(relative?' ('+relative+')':''):'Date not recorded'],[lc.retentionHold||lc.unresolvedWorkflow||lc.cleanupEligible||lc.approachingRetention?'Needs attention':'Status',retentionLabel],lc.holdReason?['Hold reason',lc.holdReason]:null])
                );
                const warning=block('Permanent deletion',!deletionAvailability.available?deletionAvailability.message:lc.purgeBlockReason||'Eligible for permanent deletion');warning.classList.add('archive-deletion-notice');
                if(deletionAvailability.available&&!lc.purgeBlockReason)warning.classList.add('is-available');fields.append(warning);
                details.append(summary,fields);
                const toolbar=document.createElement('div');toolbar.className='student-result-actions';
                const restore=actions.querySelector('[title="Restore '+accountLabel+'"]');
                if(restore){restore.classList.remove('prism-action-item');restore.classList.add('archive-restore-action');restore.innerHTML=archiveIcon('restore')+'<span>Restore access</span>';restore.title='Restore access';restore.setAttribute('aria-label','Restore access for '+(record.name||record.email));delete restore.dataset.prismTooltip;toolbar.append(restore);}
                const menu=actions.querySelector('.prism-action-menu');if(menu)menu.querySelector('.prism-action-panel')?.classList.add('archive-record-actions-panel');
                while(actions.firstChild)toolbar.append(actions.firstChild);
                const selection=tr.querySelector('.retention-select');if(selection){const wrapper=document.createElement('span');wrapper.className='retention-select';wrapper.hidden=selection.hidden;while(selection.firstChild)wrapper.append(selection.firstChild);identity.prepend(wrapper);}
                card.append(identity,facts,metadata,toolbar,details);
                const host=document.createElement('td');host.colSpan=tr.cells.length;host.append(card);tr.replaceChildren(host);
            } else if(isAdviser && !archiveWorkspace) {
                const cells=[...tr.cells].filter(cell=>!cell.classList.contains('retention-select'));
                const card=document.createElement('article');card.className='student-result-card adviser-result-card';
                const identity=document.createElement('div');identity.className='student-name-layout';
                const avatar=document.createElement('span');avatar.className='student-avatar';avatar.setAttribute('aria-hidden','true');avatar.textContent=PrismUI.recordInitials(record.name,'Adviser');
                const copy=document.createElement('div');copy.className='student-name-copy';while(cells[0].firstChild)copy.append(cells[0].firstChild);identity.append(avatar,copy);
                const block=(label,cell)=>{const div=document.createElement('div');const heading=document.createElement('span');heading.className='student-result-label';heading.textContent=label;div.append(heading);while(cell.firstChild)div.append(cell.firstChild);return div;};
                const departmentField=block('Department',cells[2]);departmentField.classList.add('adviser-department-field');
                const toolbar=document.createElement('div');toolbar.className='student-result-actions';
                if(actions.contains(editBtn)) {
                    const profileEdit=document.createElement('button');profileEdit.type='button';profileEdit.className='adviser-profile-edit';
                    profileEdit.setAttribute('aria-label',editBtn.title);
                    profileEdit.innerHTML='<svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m16 3 5 5M3 21l4-1L21 6l-4-4L3 16v5Z"/></svg><span>Edit profile</span>';
                    profileEdit.addEventListener('click',()=>openModal(record));
                    toolbar.append(profileEdit);
                }
                const menu=tr.querySelector('.prism-action-menu');
                if(menu) {
                    menu.querySelector('.prism-action-panel')?.classList.add('adviser-record-actions-panel');
                    toolbar.append(menu);
                }
                const selection=tr.querySelector('.retention-select');let selectionControl=null;
                if(selection){selectionControl=document.createElement('span');selectionControl.className='retention-select';selectionControl.hidden=selection.hidden;while(selection.firstChild)selectionControl.append(selection.firstChild);}
                const groupCount=record.assignmentCount;
                const countUnavailable=groupCount===null;
                const assignment=document.createElement(groupCount?'button':'span');assignment.className='adviser-assignment-chip'+(groupCount?'':' is-empty');
                if(groupCount)assignment.type='button';
                if(groupCount)assignment.innerHTML='<svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></svg>';
                assignment.append(document.createTextNode(countUnavailable?'Unavailable':groupCount?groupCount+' research group'+(groupCount===1?'':'s'):'No groups yet'));
                if(countUnavailable)assignment.title='Research group assignments could not be loaded. Refresh to try again.';
                const assignmentCell=document.createElement('div');assignmentCell.append(assignment);
                if(groupCount)assignmentCell.append(adviserGroupPopover(assignment,record));
                const assignmentField=block('Assignments',assignmentCell);assignmentField.classList.add('adviser-assignment-field');
                const employeeField=block('Employee ID',cells[1]);employeeField.classList.add('adviser-employee-field');
                const statusField=block('Account',cells[4]);statusField.classList.add('adviser-status-field');
                if(selectionControl)card.append(selectionControl);
                card.append(identity,employeeField,departmentField,assignmentField,statusField,toolbar);
                const host=document.createElement('td');host.colSpan=tr.cells.length;host.append(card);tr.replaceChildren(host);
            } else {
                PrismUI.compactRecord(tr,retentionUI?1:0,archiveWorkspace?(retentionUI?6:5):(retentionUI?5:4),tr.cells.length-1,
                    isAdviser ? [] : [['Group identifier',record.group],['Pending requirements',record.requirements],['Principal Investigator',record.isPrincipalInvestigator?'Yes':'No']],
                    (archiveWorkspace?[1,3,4]:isAdviser?[1,3]:[2,5]).map(index=>index+(retentionUI?1:0)));
            }
            const recordCard=tr.querySelector('article');
            if(recordCard){
                recordCard.classList.add('prism-record-shell');
                recordCard.querySelectorAll('details').forEach(details=>{
                    if(!(studentRecordsView&&details.classList.contains('student-result-details')))PrismUI.recordDetails(details);
                    if(archiveWorkspace){
                        const summary=details.querySelector('summary'),label=summary.querySelector('span');
                        summary.querySelector('i')?.remove();summary.insertAdjacentHTML('afterbegin',archiveIcon('chevron'));
                        const update=()=>{label.textContent=details.open?'Hide details':'Show details';};details.addEventListener('toggle',update);update();
                    }
                });
                recordCard.querySelectorAll('.student-academic-details-grid').forEach(grid=>grid.classList.add('prism-details-grid'));
            }
        });
        retentionUI?.syncSelection();
    }

    function openModal(record) {
        returnFocus = document.activeElement;
        form.reset();
        academicFields?.setRecord(record);
        idField.value = record ? record.id : '';
        modalTitle.textContent = studentRecordsView
            ? (record ? 'Edit student profile' : 'Add student profile')
            : (record ? `Edit ${isAdviser ? 'Research Adviser' : 'Student'} Record` : `Add ${isAdviser ? 'Research Adviser' : 'Student'}`);
        if(editIntro) {
            editIntro.textContent=record ? "Changes are saved to this student's record." : '';
            editIntro.hidden=!record;
        }
        if(studentRecordsView) form.classList.toggle('is-adding-student',!record);
        studentProfileSteps?.select(0);

        if (record) {
            nameField.value = record.name || '';
            accountIdField.value = isAdviser ? record.employeeId : record.studentId;
            emailField.value = record.email || '';
            if (isAdviser) {
                document.getElementById('department').value = record.department || '';
                document.getElementById('accountStatus').value = record.status || 'Active';
            } else {
                document.getElementById('research').value = record.research || '';
                document.getElementById('requirements').value = record.requirements || '';
                const adviserSelect = document.getElementById('adviser');
                if (record.adviserId && ![...adviserSelect.options].some(option => option.value === String(record.adviserId))) {
                    adviserSelect.add(new Option((record.adviserName || 'Existing adviser') + ' (retained assignment)', String(record.adviserId)));
                }
                adviserSelect.value = record.adviserId || '';
                const stageField=document.getElementById('stage');
                stageField.value = record.stage || '';
                document.getElementById('recordStatus').value = record.status || 'On Track';
                const pcField = document.getElementById('protocolCode');
                const piField = document.getElementById('isPrincipal');
                if (pcField) pcField.value = record.protocolCode || '';
                if (piField) piField.checked = !!record.isPrincipalInvestigator;
            }
        }
        if (!isAdviser) {
            researchAssignment?.setRecord(record);
            document.getElementById('stage').disabled=true;
            document.getElementById('recordStatus').disabled=!!record || loggedInRole==='adviser';
            document.getElementById('studentOfficialProgressHelp')?.remove();
        }
        if (!isAdviser && loggedInRole === 'adviser') {
            [nameField, accountIdField, emailField].forEach(field => { field.readOnly = !!record; });
            ['stage', 'recordStatus'].forEach(id => { const field = document.getElementById(id); field.disabled = true; field.closest('label').style.display = record ? '' : 'none'; });
            document.getElementById('adviser').disabled = true;
            adviserFieldLabel.style.display = record ? '' : 'none';
            protocolCodeField.style.display = record ? '' : 'none';
            principalField.style.display = '';
            document.getElementById('protocolCode').readOnly = true;
            document.getElementById('isPrincipal').disabled = !!record;
        }
        if (isAdviser) {
            document.getElementById('accountStatus').disabled=!!record;
            const department = document.getElementById('department');
            department.querySelector('[data-legacy]')?.remove();
            const value = record?.department || '';
            if (value && ![...department.options].some(option=>option.value===value)) {
                const option = new Option(value + ' (existing legacy value)',value);option.dataset.legacy='1';department.add(option);
            }
            department.value=value;department.required=!record;
        }
        // New records need valid required fields; edits additionally need an actual change.
        form.dataset.prismGate = record ? 'dirty' : 'valid';
        if (!isAdviser) window.PrismForms?.setLegacyValue(accountIdField, record ? record.studentId : null);
        dirty.clean();
        if(studentRecordsView){
            const displayName=nameField.value.trim();
            const initials=displayName?displayName.split(/\s+/).map(part=>part[0]).slice(0,2).join('').toUpperCase():'JD';
            modal.querySelector('.student-profile-avatar').textContent=initials;
            if(editIntro) editIntro.hidden=!record;
            studentProfileSteps.select(0);
        }
        modal.classList.add('show');
        modal.style.display = 'flex';
        (nameField.readOnly ? document.getElementById('research') : nameField).focus();
    }

    function closeModal() {
        dirty.clean();
        modal.classList.remove('show');
        modal.style.display = 'none';
        returnFocus?.focus();
    }

    async function deleteRecord(record) {
        try {
            const fresh=await PrismUI.request('account_lifecycle_api.php?'+new URLSearchParams({action:'account_state',accountType:isAdviser?'adviser':'student',targetId:record.id}));
            const answer=await PrismUI.confirm({title:isAdviser?'Archive Adviser':'Archive Student',icon:'fa-box-archive',tone:'danger',confirmText:'Archive',dialogClass:'student-archive-confirmation',
                reasonRequired:!!record.projectId,reasonLabel:record.projectId?'Reviewed member archival reason':undefined,minReason:5,maxReason:500,
                message:isAdviser?`This Adviser currently has ${fresh.assignedStudents} assigned Students. They will become Unassigned. Archive ${record.name}?`:record.projectId?`Archive ${record.name} and deactivate their login while retaining shared membership, documents and project progress? The PI or submission holder requires a reviewed transition first.`:`Archive ${record.name} and deactivate their login? This starts a 7-day grace period and a 6-month retention countdown. History stays available during archive.`});
            if(!answer)return;
            const data=await PrismUI.postJson('account_lifecycle_api.php',{action:'archive',accountType:isAdviser?'adviser':'student',targetId:record.id,expectedAssignedStudents:fresh.assignedStudents,confirmed:true,reason:answer.reason||'',expectedProjectRevision:record.projectRevision});
            await loadRecords();PrismUI.toast(data.message,'success');
        } catch(e) { PrismUI.toast(e.message,'error'); }
    }

    const permanentDialog=document.getElementById('permanentDeleteDialog');
    const permanentForm=document.getElementById('permanentDeleteForm');
    let permanentRecord=null, permanentReturnFocus=null, permanentContext=null;
    function preparePermanentDialog(button,title,identity,label,warning,phrase='',blockingMessage='') {
        permanentReturnFocus=PrismUI.actionOrigin(button || document.activeElement);permanentForm.reset();
        const bulkAction=permanentContext?.mode==='bulk'?permanentContext.preview?.bulkAction:null;
        const bulkArchive=studentRecordsView&&bulkAction==='archive';
        const bulkDirectDelete=studentRecordsView&&bulkAction==='direct_delete';
        document.getElementById('permanentDeleteTitle').textContent=title;
        document.getElementById('permanentDeleteIdentity').textContent=identity;
        document.getElementById('permanentDeleteConfirmationLabel').textContent=label;
        permanentForm.elements.confirmation.placeholder=phrase || 'Type the exact account ID';
        document.getElementById('permanentDeleteWarning').textContent=warning;
        const phraseOutput=document.getElementById('permanentDeletePhrase');
        phraseOutput.textContent=phrase;
        phraseOutput.hidden=!phrase;
        const availabilityNotice=document.getElementById('permanentDeleteAvailabilityNotice');
        availabilityNotice.hidden=!blockingMessage;
        document.getElementById('permanentDeleteAvailabilityTitle').textContent=bulkArchive?'Archiving is unavailable':'Deletion is unavailable';
        document.getElementById('permanentDeleteAvailabilityMessage').textContent=blockingMessage;
        document.getElementById('permanentDeleteVerification').hidden=Boolean(blockingMessage);
        permanentForm.querySelectorAll('#permanentDeleteVerification input, #permanentDeleteVerification textarea')
            .forEach(input=>{input.disabled=Boolean(blockingMessage);});
        document.getElementById('permanentDeleteResult').textContent='';
        const cancelButton=document.getElementById('cancelPermanentDelete');
        const override=permanentContext?.action==='grace_period_override';
        document.getElementById('permanentDeleteReasonGroup').hidden=!override;
        permanentForm.elements.reason.required=override;
        const submitButton=permanentForm.querySelector('[type="submit"]');
        cancelButton.textContent=bulkArchive||bulkDirectDelete||permanentContext?.preview?.directDelete?'Cancel':'No, cancel';
        submitButton.disabled=Boolean(blockingMessage);
        submitButton.classList.toggle('lifecycle-danger',!bulkArchive);
        submitButton.classList.toggle('management-primary',bulkArchive);
        submitButton.textContent=blockingMessage
            ? (bulkArchive?'Archive unavailable':'Deletion unavailable')
            : permanentContext?.mode==='recovery'
            ? 'Run recovery'
            : bulkArchive
                ? 'Confirm Archive'
                : bulkDirectDelete
                    ? 'Confirm Delete'
            : permanentContext?.mode==='bulk'
                ? (permanentContext.preview?.directDelete ? 'Delete permanently' : permanentContext.preview?.purge ? 'Yes, permanently delete eligible' : 'Yes, apply batch')
                : 'Yes, permanently delete';
        permanentDialog.showModal();
    }
    async function openPermanentDelete(record,button,action='permanent_delete') {
        if(!deletionAvailability.available || !record.archivedAt)return;
        try {
            const fresh=await PrismUI.request('account_lifecycle_api.php?'+new URLSearchParams({action:'account_state',accountType:isAdviser?'adviser':'student',targetId:record.id}));
            if(!(action==='grace_period_override'?fresh.lifecycle.overrideAvailable:fresh.lifecycle.manualEligible))throw new Error(fresh.lifecycle.purgeBlockReason || 'Purge is unavailable.');
            permanentRecord=record;permanentContext={mode:'single',action};
            preparePermanentDialog(button,action==='grace_period_override'?'Delete Now ? Override Grace Period':`Permanently Delete ${isAdviser?'Adviser':'Student'}`,
                (record.name || 'Profile incomplete')+' — '+((isAdviser?record.employeeId:record.studentId) || record.email),'Type the exact '+((isAdviser?record.employeeId:record.studentId)?(isAdviser?'Employee ID':'Student ID'):'invited email'),
                isAdviser?'This permanently removes this Adviser account and credentials. Student assignments become Unassigned. Historical Adviser display attribution and workflow evidence may remain. This action cannot be undone.':'This permanently removes this Student account and applicable Student-specific PRISM records and uploaded files. This action cannot be undone.');
        } catch(error) { PrismUI.toast(error.message,'error'); }
    }
    function openBulkDelete(preview,button) {
        permanentRecord=null;permanentContext={mode:'bulk',preview};
        const selectedId=preview.selected===1?Number(Object.keys(preview.states)[0]):null;
        const selectedRecord=selectedId?records.find(record=>record.id===selectedId):null;
        const selectedIdentifier=selectedRecord?.studentId || selectedRecord?.email || preview.states[selectedId]?.identifier;
        const archiveAction=studentRecordsView&&preview.bulkAction==='archive';
        const directStudentDelete=studentRecordsView&&preview.bulkAction==='direct_delete';
        let directTitle=archiveAction
            ? 'Archive Selected Records?'
            : directStudentDelete
                ? 'Delete Selected Records?'
                : preview.directDelete
            ? preview.selected===1?'Delete this student permanently?':`Delete ${preview.eligible} students permanently?`
            : 'Confirm bulk lifecycle action';
        const selectedNames=Array.isArray(preview.selectedRecords)?preview.selectedRecords:[];
        const stateLabels=Object.values(preview.states||{}).map(state=>state.identifier).filter(Boolean);
        const reviewLines=(selectedNames.length?selectedNames:stateLabels).map(label=>`- ${label}`);
        const identity=archiveAction||directStudentDelete
            ? `${preview.eligible} eligible of ${preview.selected} selected.\nSelected student records:\n${reviewLines.join('\n')}`
            : preview.directDelete && preview.selected===1
            ? `${selectedRecord?.name || 'Student'} — ${selectedIdentifier}`
            : `${preview.selected} selected; ${preview.eligible} eligible; ${preview.skipped.length} skipped. ${preview.unassignedStudents} assigned Students will become Unassigned.`;
        const warning=archiveAction
            ? `You are about to archive ${preview.eligible} of ${preview.selected} selected student records. Archived accounts will no longer be active, but their existing research records, documents, and activity history will be preserved.`
            : directStudentDelete
                ? `You are about to delete ${preview.eligible} of ${preview.selected} selected student records. This action may permanently remove eligible records. Records with protected research documents, project memberships, legal consent evidence, or required audit history will be rejected by eligibility checks.`
                : preview.directDelete
            ? 'This immediately and permanently removes the selected Student and applicable records and files, without an archive grace period. Retention Holds, unresolved workflows, and recovery jobs still block deletion.'
            : preview.purge?'This permanently removes eligible accounts and applicable owned PRISM records and files. Historical aggregate reports and Adviser attribution may remain. This action cannot be undone.':'Each eligible account will be rechecked and updated independently. Review the selection and assignment impact before confirming.';
        const deletionAction=Boolean(preview.directDelete || preview.purge);
        const skippedDetails=preview.skipped.map(item=>`${item.identifier}: ${item.reason}`).join('\n');
        const blockingMessage=deletionAction && preview.deletionUnavailable
            ? `${preview.deletionUnavailable} Contact the system operator or resolve the blockers listed below, then review the selection again.`
            : deletionAction && preview.eligible===0
                ? `None of the ${preview.selected} selected account${preview.selected===1?' is':'s are'} currently eligible. Review the listed blockers before trying again.`
                : archiveAction && preview.eligible===0
                    ? `None of the ${preview.selected} selected student records can be archived. Review the eligibility results below.`
                : '';
        if(blockingMessage) directTitle='Resolve deletion blockers';
        permanentDialog.classList.toggle('student-direct-delete-confirmation',Boolean(preview.directDelete));
        permanentDialog.classList.toggle('student-bulk-confirmation',archiveAction||directStudentDelete);
        preparePermanentDialog(button,directTitle,identity,preview.directDelete?'Confirmation phrase':'Type the exact confirmation phrase',
            warning,preview.phrase,blockingMessage);
        const result=document.getElementById('permanentDeleteResult');
        result.textContent=preview.skipped.length
            ? `${preview.eligible} eligible · ${preview.skipped.length} skipped\n${skippedDetails}`
            : `${preview.eligible} account${preview.eligible===1?'':'s'} eligible for this action.`;
    }
    function openRecovery(job,button) {
        permanentRecord=null;permanentContext={mode:'recovery',job};
        preparePermanentDialog(button,'Complete purge recovery',job.jobId,'Type: RECOVER '+job.jobId,
            'Recovery checks the database commit evidence. Committed jobs finalize quarantined files; rolled-back jobs restore files to their original owned records.');
    }
    function closePermanentDelete() { permanentDialog.close();permanentDialog.classList.remove('student-direct-delete-confirmation','student-bulk-confirmation');permanentForm.reset();permanentRecord=null;permanentContext=null;permanentReturnFocus?.focus(); }
    document.getElementById('cancelPermanentDelete')?.addEventListener('click',closePermanentDelete);
    permanentDialog?.addEventListener('cancel',event=>{event.preventDefault();closePermanentDelete();});
    permanentForm?.addEventListener('submit',async event=>{
        event.preventDefault();if(!permanentContext)return;
        const button=permanentForm.querySelector('[type="submit"]'),release=PrismUI.busy(button,'Processing...');if(!release)return;
        try {
            const credentials={currentPassword:permanentForm.elements.currentPassword.value,confirmation:permanentForm.elements.confirmation.value,confirmed:permanentForm.elements.confirmed.checked};
            let data;
            if(permanentContext.mode==='bulk') data=await retentionUI.executeBulk(permanentContext.preview,credentials,text=>{document.getElementById('permanentDeleteResult').textContent=text;});
            else if(permanentContext.mode==='recovery') {
                if(credentials.confirmation!=='RECOVER '+permanentContext.job.jobId)throw new Error('Type the exact recovery phrase.');
                data=await PrismUI.postJson('account_lifecycle_api.php',{action:'recover',jobId:permanentContext.job.jobId,...credentials});
            } else data=await PrismUI.postJson('account_lifecycle_api.php',{accountType:isAdviser?'adviser':'student',action:permanentContext.action,targetId:permanentRecord.id,...credentials,reason:permanentForm.elements.reason.value.trim()});
            closePermanentDelete();await loadRecords();await retentionUI.refreshSummary();PrismUI.toast(data.message || 'Lifecycle batch completed. Review committed outcomes below.',data.recoveryRequired?'info':'success');
        } catch(error) { document.getElementById('permanentDeleteResult').textContent=error.message;permanentForm.elements.currentPassword.value='';PrismLifecycleForms.resetPassword(permanentForm);await retentionUI.refreshSummary(); }
        finally { release(); }
    });

    const recordNameForMessage = record => record?.name || 'this student';

    addBtn?.addEventListener('click', () => openModal(null));
    closeBtn.addEventListener('click', closeModal);
    cancelBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', e => { if (e.target === modal) closeModal(); });
    modal.addEventListener('keydown', event => {
        if (event.key === 'Escape') { event.preventDefault(); event.stopPropagation(); closeModal(); }
        if (event.key !== 'Tab') return;
        const controls = [...modal.querySelectorAll('button,input,select,textarea,[tabindex]')].filter(e => !e.disabled && e.tabIndex >= 0 && e.getClientRects().length);
        const first = controls[0], last = controls[controls.length - 1];
        if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last.focus(); }
        else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first.focus(); }
    });
    const searchReload = PrismUI.debounce(() => { pager.reset(); loadRecords(); });
    if(studentRecordsView) {
        const clearSearch=document.getElementById('clearRecordSearch');
        const shortcut=document.getElementById('studentSearchShortcut');
        const syncSearchAffordances=()=>{
            const hasQuery=!!searchInput.value;
            clearSearch.hidden=!hasQuery;
            shortcut.hidden=hasQuery;
        };
        searchInput.addEventListener('input',syncSearchAffordances);
        clearSearch.addEventListener('click',()=>{
            searchInput.value='';
            searchInput.dispatchEvent(new Event('input',{bubbles:true}));
            searchInput.focus();
        });
        document.addEventListener('keydown',event=>{
            const target=event.target;
            if(event.key==='/' && !event.altKey && !event.ctrlKey && !event.metaKey &&
                !target.matches('input,textarea,select,[contenteditable="true"]')) {
                event.preventDefault();
                searchInput.focus();
            }
        });
        syncSearchAffordances();
    }
    searchInput.addEventListener('input', () => { ++requestSequence; retentionUI?.syncScope(); pager.reset(); searchReload(); });
    filterControls.forEach(control=>control.addEventListener('change',()=>{pager.reset();loadRecords();}));

    form.addEventListener('submit', async event => {
        event.preventDefault();
        if(studentRecordsView){
            const required=[nameField,accountIdField,emailField];
            const invalid=required.find(field=>!field.checkValidity());
            if(invalid){
                studentProfileSteps?.select(0);
                invalid.reportValidity();invalid.focus();return;
            }
        }
        const submitBtn = form.querySelector('[type="submit"]');
        const release = PrismUI.busy(submitBtn,'Saving...');
        if(!release)return;
        try {
            const payload = {
                id: idField.value ? Number(idField.value) : 0,
                name: nameField.value.trim(),
                email: emailField.value.trim(),
            };
            if (isAdviser) {
                payload.employeeId = accountIdField.value.trim();
                payload.department = document.getElementById('department').value.trim();
                payload.status = document.getElementById('accountStatus').value;
            } else {
                payload.studentId = accountIdField.value.trim();
                payload.research = document.getElementById('research').value.trim();
                payload.group = document.getElementById('group').value.trim();
                Object.assign(payload, academicFields.payload(), researchAssignment?.payload()||{});
                payload.requirements = document.getElementById('requirements').value.trim();
                payload.adviserId = document.getElementById('adviser').value || null;
                // Official progress is read-only; the server retains storage buckets independently.
                payload.status = document.getElementById('recordStatus').value;
                const pcField = document.getElementById('protocolCode');
                const piField = document.getElementById('isPrincipal');
                if (pcField) payload.protocolCode = pcField.value.trim();
                if (piField) payload.isPrincipalInvestigator = piField.checked;
            }

            const data = await PrismUI.postJson(`${apiUrl}?action=save`, payload);
            closeModal();
            await loadRecords();
            PrismUI.toast(data.message || 'Record saved.', 'success');
            if (data.setupPending && !data.temporaryPassword) {
                PrismUI.toast(data.setupMessage || 'Account created. Contact the RPMS office to arrange password setup.', 'info');
            }
            if (data.temporaryPassword) {
                await PrismUI.confirm({
                    title:'Account setup required',
                    icon:'fa-key',
                    confirmText:'I copied it',
                    message:'Live account-setup email is not available. Give this one-time temporary password to the user securely. They will be required to change it after signing in.',
                    extraHtml:'<label>Temporary password</label><input id="prismTempPassword" readonly value="' + escapeHtml(data.temporaryPassword) + '" onclick="this.select()">'
                });
            }
        } catch (e) {
            PrismUI.toast(e.message, 'error');
        } finally {
            release();
        }
    });

    if(isAdviser&&!archiveWorkspace) {
        const refreshAssignments=PrismUI.debounce(()=>{
            if(document.visibilityState==='visible'&&!modal.classList.contains('show')&&!document.querySelector('dialog[open],.prism-modal'))loadRecords();
        },200);
        window.addEventListener('focus',refreshAssignments);
        document.addEventListener('visibilitychange',()=>{if(document.visibilityState==='visible')refreshAssignments();});
        window.addEventListener('pageshow',event=>{if(event.persisted)refreshAssignments();});
    }

    if(!isAdviser && loggedInRole==='admin') researchAssignment=PrismResearchAssignment.mount({form,group:document.getElementById('group'),title:document.getElementById('research'),adviser:document.getElementById('adviser'),protocol:document.getElementById('protocolCode'),pi:document.getElementById('isPrincipal')});

    loadRecords();
})();
