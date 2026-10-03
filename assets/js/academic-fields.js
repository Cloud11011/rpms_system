/* Dependent controls consume the server-exported catalog; the API validates every save. */
(function () {
    'use strict';
    window.PrismAcademicFields = {
        appendSummary(host, record) {
            const values = [record.course, record.academicUnitKey, record.programKey, record.yearLevel, record.academicYear];
            if (!values.some(value => value !== null && value !== undefined && String(value).trim() !== '')) return;
            const catalog = window.PRISM_ACADEMIC_CATALOG;
            const summary = document.createElement('dl');
            summary.className = 'academic-record-summary';
            summary.setAttribute('aria-label', 'Academic information');
            const fields = [
                ['Program', record.course],
                ['Academic Unit (catalog grouping)', catalog.units[record.academicUnitKey]?.label || record.academicUnitKey],
                ['Year Level', record.yearLevel], ['Academic Year', record.academicYear]
            ];
            fields.forEach(([label, value]) => {
                const row = document.createElement('div');
                const term = document.createElement('dt'), description = document.createElement('dd');
                term.textContent = label + ': ';
                description.textContent = value === null || value === undefined || String(value).trim() === '' ? 'Not recorded' : String(value);
                row.append(term, description); summary.appendChild(row);
            });
            host.appendChild(summary);
        },
        mount(root) {
            const catalog = window.PRISM_ACADEMIC_CATALOG;
            const field = name => root.querySelector(`[data-academic="${name}"]`);
            const unit = field('unit'), program = field('program'), year = field('year'), ay = field('academic-year');
            const summary = field('summary'), legacy = field('legacy'), reset = field('reset');
            const group = root.closest('form').querySelector('[data-research-group]');
            let groupRequest = 0;
            let original = null, changed = false;
            const value = input => String(input ?? '');
            const options = (select, entries, placeholder, selected = '', preserve = false) => {
                select.replaceChildren(new Option(placeholder, ''));
                entries.forEach(([key, label]) => select.add(new Option(label, key)));
                if (preserve && selected && !entries.some(([key]) => key === selected)) select.add(new Option(selected + ' (existing value)', selected));
                select.value = selected;
            };
            const selectedProgram = () => catalog.programs[program.value];
            const years = key => {
                const entry = catalog.programs[key];
                return entry?.level === 'undergraduate'
                    ? Array.from({length: entry.duration}, (_, i) => `${i + 1}${['st', 'nd', 'rd'][i] || 'th'} Year`) : [];
            };
            function programOptions(selected = '', preserve = false) {
                const selectedUnit = unit.value === '__graduate__' ? null : unit.value;
                options(program, Object.entries(catalog.programs).filter(([, p]) => p.unit === selectedUnit).map(([key, p]) => [key, p.label]), 'Choose a program', selected, preserve);
            }
            function yearOptions(selected = '', preserve = false) {
                options(year, years(program.value).map(label => [label, label]), 'Choose a year level', selected, preserve);
                year.disabled = selectedProgram()?.level === 'graduate';
            }
            function originalGroupIsStandard() {
                const p = catalog.programs[original?.programKey];
                if (!p || !catalog.academicYears.includes(original?.academicYear)) return false;
                if (p.level !== 'graduate' && (p.unit !== original.academicUnitKey || !years(original.programKey).includes(original.yearLevel))) return false;
                const prefix = (p.level === 'graduate' ? 'GRAD' : p.unit.toUpperCase()) + '-'
                    + original.programKey.toUpperCase().replaceAll('_', '-')
                    + (p.level === 'graduate' ? '' : '-Y' + original.yearLevel[0]) + '-'
                    + original.academicYear.slice(2, 4) + original.academicYear.slice(7, 9) + '-G';
                const id = original.group ?? original.groupId ?? '';
                const suffix = id.slice(prefix.length);
                return id.startsWith(prefix) && /^[0-9]{2,9}$/.test(suffix) && Number(suffix) > 0 && String(Number(suffix)).padStart(2, '0') === suffix;
            }
            async function refreshGroups() {
                if (!group) return;
                const request = ++groupRequest;
                group.removeAttribute('aria-busy');
                const selected = group.value;
                const old = original?.group ?? original?.groupId ?? '';
                const preserve = old && !originalGroupIsStandard();
                group.replaceChildren(new Option(preserve ? 'Keep existing research group' : 'No research group', ''));
                if (preserve) group.add(new Option('Keep existing: ' + old, old));
                else if (old && !changed) group.add(new Option(old, old));
                group.value = [...group.options].some(o => o.value === selected) ? selected : '';
                const p = selectedProgram();
                if (!p || !catalog.academicYears.includes(ay.value) || (p.level !== 'graduate' && !years(program.value).includes(year.value))) return;
                group.setAttribute('aria-busy', 'true');
                try {
                    const params = new URLSearchParams({action: 'group_options', academicUnitKey: p.unit || '',
                        programKey: program.value, yearLevel: p.level === 'graduate' ? '' : year.value, academicYear: ay.value});
                    const data = await PrismUI.request('students_api.php?' + params);
                    if (request !== groupRequest) return;
                    group.replaceChildren(new Option(preserve ? 'Keep existing research group' : (data.groups.length ? 'No research group' : 'No existing groups in this cohort'), ''));
                    if (preserve) group.add(new Option('Keep existing: ' + old, old));
                    data.groups.forEach(id => group.add(new Option(id, id)));
                    group.add(new Option('+ Create New Group (ID assigned on save)', '__create__'));
                    group.value = [...group.options].some(o => o.value === selected) ? selected : '';
                    group.title = '';
                } catch (e) {
                    if (request === groupRequest) {
                        group.title = 'Could not load groups. Re-select an academic field to retry.';
                        PrismUI.toast(group.title, 'error');
                    }
                } finally {
                    if (request === groupRequest) group.removeAttribute('aria-busy');
                }
            }
            function refresh() {
                const validate = !original || changed;
                const entry = selectedProgram();
                unit.required = validate;
                program.required = validate;
                ay.required = validate;
                year.required = validate && entry?.level !== 'graduate';
                unit.setCustomValidity(validate && !catalog.units[unit.value] && unit.value !== '__graduate__' ? 'Choose an academic unit or the graduate catalog group.' : '');
                program.setCustomValidity(validate && !entry ? 'Choose a program from the catalog.' : '');
                year.setCustomValidity(validate && entry?.level !== 'graduate' && !years(program.value).includes(year.value) ? 'Choose a valid year level.' : '');
                ay.setCustomValidity(validate && !catalog.academicYears.includes(ay.value) ? 'Choose an academic year from the list.' : '');
                summary.textContent = entry ? entry.label + (entry.level === 'graduate' ? ' - Graduate unit and year level are not yet configured.' : ` - ${entry.duration}-year program.`) : (original && !changed ? 'Existing program: ' + (value(original.course) || 'Not recorded') : 'Select a program to see its full name and year levels.');
                legacy.hidden = !original;
                legacy.textContent = original ? 'Stored academic values: ' + [original.course, original.academicUnitKey, original.programKey, original.yearLevel, original.academicYear].filter(v => v !== null && v !== undefined && v !== '').join(' | ') + (changed ? '. Saving will validate the new selection.' : '. These values are preserved unless you change the academic controls.') : '';
                reset.hidden = !original || !changed;
                refreshGroups();
            }
            function setRecord(record) {
                original = record; changed = false;
                if (group) {
                    const storedGroup = record?.group ?? record?.groupId ?? '';
                    group.replaceChildren(new Option(storedGroup || 'No research group', storedGroup));
                }
                const existingProgram = catalog.programs[record?.programKey];
                options(unit, [...Object.entries(catalog.units).map(([key, data]) => [key, data.label]), ['__graduate__', 'Graduate programs (catalog grouping)']], 'Choose an academic unit', existingProgram?.level === 'graduate' ? '__graduate__' : value(record?.academicUnitKey), true);
                programOptions(value(record?.programKey), true);
                if (record && !record.programKey && record.course) {
                    program.add(new Option(value(record.course) + ' (existing course)', '__legacy__'));
                    program.value = '__legacy__';
                }
                yearOptions(value(record?.yearLevel), true);
                options(ay, catalog.academicYears.map(label => [label, label]), 'Choose an academic year', value(record?.academicYear), true);
                refresh();
            }
            unit.addEventListener('change', () => {
                changed = true;
                const key = program.value, oldYear = year.value;
                programOptions(key);
                yearOptions(oldYear);
                refresh();
            });
            program.addEventListener('change', () => { changed = true; yearOptions(year.value); refresh(); });
            [year, ay].forEach(select => select.addEventListener('change', () => { changed = true; refresh(); }));
            reset.addEventListener('click', () => { setRecord(original); unit.focus(); });
            setRecord(null);
            return {
                setRecord,
                payload() {
                    if (original && !changed) return {};
                    return {academicUnitKey: unit.value === '__graduate__' ? null : unit.value,
                        programKey: program.value, course: selectedProgram()?.label || '',
                        yearLevel: year.disabled ? null : year.value, academicYear: ay.value};
                }
            };
        }
    };
})();
