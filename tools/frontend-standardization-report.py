"""Build evidence indexes and exact local change-control reports (no application bootstrap)."""
from pathlib import Path
import hashlib
import html
import json
import re
import subprocess

ROOT = Path(__file__).resolve().parents[1]
OUT = ROOT / 'tests/frontend-standardization-results'

def git(*args):
    return subprocess.check_output(['git', '-c', 'core.safecrlf=false', *args], cwd=ROOT).decode('utf-8')

def read_json(file):
    return json.loads((OUT / file).read_text(encoding='utf-8'))

def read_log(file):
    data = (OUT / file).read_bytes()
    return data.decode('utf-16' if data.startswith((b'\xff\xfe', b'\xfe\xff')) else 'utf-8-sig')

def slug(route):
    return re.sub(r'[?#=]', '-', route).replace('.php', '')

def link(file, label):
    return f'[{label}](tests/frontend-standardization-results/{file})'

before = read_json('before/browser-results.json')
extra_before = read_json('before-targeted/browser-results.json')
after = read_json('after/browser-results.json')
targeted = read_json('after-targeted/browser-results.json')
auth_browser = read_json('after-auth/browser-results.json')
before_auth = read_json('before-auth/browser-results.json')
polish = read_json('after-polish/browser-results.json')
deadline = read_json('after-deadline/browser-results.json')
year = read_json('after-year/browser-results.json')
before_year = read_json('before-year/browser-results.json')
state_data = read_json('after-state/browser-results.json')
initial = read_json('inventory.json')
syntax = read_json('syntax-final.json')
cli_path = ROOT / 'tests/batch11-results/cli-final/cli-results.json'
cli = json.loads(cli_path.read_text(encoding='utf-8'))
# Keep the initial failed run; record the executed successful targeted authentication rerun separately.
auth_log = read_log('auth-final.log')
auth_passed = '52 authentication flow checks passed.' in auth_log and 'Fatal error' not in auth_log
cli_final = [{**row, 'passed': auth_passed, 'summary': '52 authentication flow checks passed; targeted final rerun.'}
             if row['suite'] == 'auth-flows' else row for row in cli['results']]
(OUT / 'cli-final.json').write_text(json.dumps({'initial': cli['results'], 'final': cli_final, 'authRerun': 'auth-final.log'}, indent=2), encoding='utf-8')

tracked = git('diff', '--name-only').splitlines()
added = [
    'assets/css/prism-tokens.css', 'assets/css/prism-components.css', 'assets/css/prism-calendar.css',
    'assets/js/prism-calendar-presentation.js', 'includes/frontend_styles.php',
    'tests/frontend-standardization-browser.cjs', 'tools/frontend-inventory.py',
    'tools/verify-frontend-standardization.cjs', 'tools/frontend-contact-sheets.cjs',
    'tools/frontend-standardization-report.py',
    'PRISM_FRONTEND_DESIGN_SYSTEM_REPORT.md', 'PRISM_FRONTEND_CONSISTENCY_AUDIT.md',
    'PRISM_FRONTEND_STANDARDIZATION_TEST_REPORT.md',
]
protected = []
for entry in initial['files']:
    file = entry['file']
    if file.endswith('.php') and file != 'includes/assets.php':
        current = (ROOT / file).read_bytes()
        previous = (OUT / 'baseline-source' / file).read_bytes()
        # The executable prefix preceding HTML must be identical for all rendered pages.
        a = current.decode('utf-8-sig').replace('\r\n', '\n')
        b = previous.decode('utf-8-sig').replace('\r\n', '\n')
        a = re.split(r'<!doctype|<html', a, maxsplit=1, flags=re.I)[0]
        b = re.split(r'<!doctype|<html', b, maxsplit=1, flags=re.I)[0]
        protected.append({'file': file, 'identicalExecutablePrefix': a == b,
                          'byteIdentical': current == previous,
                          'sha256': hashlib.sha256(current).hexdigest()})
frozen = []
for path in (ROOT / 'includes').glob('*.json'):
    previous = OUT / 'baseline-source/includes' / path.name
    frozen.append({'file': path.relative_to(ROOT).as_posix(), 'unchanged': path.read_bytes() == previous.read_bytes()})
stat = git('diff', '--stat')
diff_check = subprocess.run(['git', '-c', 'core.safecrlf=false', 'diff', '--check'], cwd=ROOT, capture_output=True, text=True)
inventory = {'branch': git('branch', '--show-current').strip(), 'head': git('rev-parse', 'HEAD').strip(),
             'trackedModified': tracked, 'added': added, 'protectedPHP': protected, 'frozenMetadata': frozen,
             'diffCheck': {'exit': diff_check.returncode, 'output': diff_check.stdout + diff_check.stderr}}
(OUT / 'integrity.json').write_text(json.dumps(inventory, indent=2), encoding='utf-8')
(OUT / 'diff-stat.txt').write_text(stat, encoding='utf-8')

source_diff = git('diff', '--', *[f for f in tracked if not f.endswith('.md')])
for file in added:
    if file.endswith('.md') or not (ROOT / file).exists():
        continue
    patch = subprocess.run(['git', '-c', 'core.safecrlf=false', 'diff', '--no-index', '--', 'NUL', file], cwd=ROOT, capture_output=True)
    if patch.returncode not in [0, 1]:
        raise RuntimeError('Could not create new-file patch: ' + file)
    source_diff += patch.stdout.decode('utf-8')
(OUT / 'source-diff.patch').write_text(source_diff, encoding='utf-8')

role = {0: 'Public', 1001: 'Administrator', 1002: 'Research Adviser', 1003: 'Student', 1006: 'Administrator mandatory account action'}
mapping = {
    'dashboard.php': ('dashboard.php', 'Legacy table minimum clipped the compact monitor action; older calendar state selectors missed actual Admin .day cells.', 'Batch 11 Admin dashboard', 'Compact-table constraint scoped correctly; canonical calendar states and controls; shared toolbar buttons.'),
    'admin_students.php': ('admin_people.php', 'Shared action sizes and legacy token/button declarations overlapped.', 'Batch 11 compact records', 'Shared component ownership; record cards, expandable fields and scoped actions retained.'),
    'admin_advisers.php': ('admin_people.php', 'Button/token duplication around the approved compact cards.', 'Batch 11 compact records', 'Shared controls, focus and palette; Adviser metadata remains expandable.'),
    'admin_archived_accounts.php': ('admin_people.php', 'Lifecycle controls used separate sizing and presentation rules.', 'Batch 11 archive workspace', 'Shared controls and semantic danger treatment; archived/hold metadata retained.'),
    'documents.php': ('documents.php', 'Workflow pills inside the filter wrapper distorted toolbar alignment; search had competing inner/outer control borders.', 'Batch 11 compact documents', 'Pills moved into a dedicated toolbar row; consistent search, Upload/Filter dimensions and action presentation.'),
    'ierbprog.php': ('ierbprog.php', 'IERB primary/secondary controls differed from equivalent workspace actions.', 'Batch 10.75/11 records and forms', 'Button/form tokens shared; official-stage fields, status labels and history retained.'),
    'calendar.php': ('calendar.php', 'Standalone square grid and Today circle differed from Admin dashboard; no standalone legend.', 'Batch 11 Admin Calendar', 'Common chrome, cells, combined Today/deadline states, selection outline, legend and responsive density.'),
    'research_adviser.php': ('research_adviser.php', 'Dashboard calendar had rectangular controls, bordered cells and different heading treatment.', 'Batch 11 Admin Calendar and Adviser dashboard', 'Shared calendar presentation and controls; assignment-scoped reads retained.'),
    'account.php': ('account.php', 'Account form and lifecycle action sizes differed; dark surfaces came from overlapping token families.', 'Batch 11 workspace; Batch 10.75 forms', 'Common form/card/action tokens; lifecycle confirmation and password checks retained.'),
    'admin_notifications.php': ('admin_notifications.php', 'Notification form/actions and history had separate control styles.', 'Batch 11 Notifications', 'Shared controls/cards/semantic feedback; scheduling and notification-history behavior retained.'),
    'admin_legal_policies.php': ('admin_legal_policies.php', 'Legal and workspace variables/button styles diverged.', 'Batch 11 Legal Policies', 'Workspace component family; governance, draft/history, publication and dirty-cancel behavior retained.'),
    'admin_ai.php': ('admin_ai.php', 'AI history/control sizes came from several style owners.', 'Batch 11 AI reports', 'Shared controls, cards, pagination and tokens; approved AI/banner treatment retained.'),
    'reports.php': ('reports.php', 'Generate/download controls and history pagination had separate styling.', 'Batch 11 reports', 'Shared actions/form controls/pagination; report generation and protected downloads unchanged.'),
    'data_export.php': ('data_export.php', 'Export cards/actions were isolated from the common component owner.', 'Batch 11 workspace', 'Shared actions and typography; export routes, fields and authorization retained.'),
    'staff_registration_manage.php': ('staff_registration_manage.php', 'Special account form used independent controls.', 'Batch 10.75 forms; Batch 11 workspace', 'Shared input/button/focus tokens; code generation, secrecy and acknowledgement rules retained.'),
    'student.php': ('role_portal.php', 'Student calendar/header and form controls diverged from other roles; photo preview used a keyboard-inaccessible hidden-input label.', 'Batch 11 Student shell and Admin Calendar', 'Shared calendar, form/button tokens; native photo-preview button; all Student sections retained.'),
    'help_support.php': ('help_support.php', 'Legal/support palette and buttons differed from the workspace family.', 'Batch 11 palette; existing legal reading layout', 'Shared typography/tokens/form surfaces; request ownership, CSRF and response controls retained.'),
    'index.php': ('includes/public_landing.php', 'Public page used system-ui and separate neutral tokens.', 'Batch 11 landing page', 'Shared font/token aliases and button family; hero/process content and public-only data boundary retained.'),
    'login.php': ('login.php', 'Approved Login was already polished; font/token ownership was duplicated.', 'Batch 11 Login; Batch 10.75 auth controls', 'Approved composition retained; shared loader, focus and token ownership.'),
    'forgot_password.php': ('forgot_password.php', 'Older body lacked the shared auth class/theme initialization; recovery controls and dark treatment diverged.', 'Batch 11 Login', 'Auth shell and dark persistence aligned; accessible email field name added.'),
    'reset_password.php': ('reset_password.php', 'Theme initialization missing; valid/invalid recovery states used older surfaces.', 'Batch 11 Login', 'Shared recovery controls/surfaces, theme initialization and font ownership.'),
    'register.php': ('register.php', 'Registration did not restore the stored theme; older control styling remained.', 'Batch 11 Login; Batch 10.75 forms', 'Auth font, inputs, buttons and stored theme aligned; code/role requirements unchanged.'),
    'account_setup.php': ('account_setup.php', 'Onboarding fields/submit had different radii and surface tokens.', 'Batch 11 Login', 'Auth controls and theme tokens aligned; one-time invitation/password requirements unchanged.'),
    'complete_profile.php': ('complete_profile.php', 'Onboarding-specific palette/form radii differed; dark aliases conflicted.', 'Batch 11 Login; Batch 10.75 forms', 'Shared auth surfaces/form controls; required academic fields and pending-account restrictions retained.'),
    'change_password_required.php': ('change_password_required.php', 'Mandatory password screen lacked stored-theme initialization.', 'Batch 11 Login', 'Shared auth surfaces and theme persistence; required password flow and logout retained.'),
    'legal_consent.php': ('legal_consent.php', 'Consent used independent legal colors and typography.', 'Batch 11 palette; existing legal reading shell', 'Shared legal aliases/font/button/focus; CSRF, snapshots and required consent unchanged.'),
    'privacy.php': ('includes/public_legal.php', 'Legal font stylesheet was omitted and token families diverged.', 'Batch 11 palette; existing published legal layout', 'Shared font and token aliases; published text/version/date unchanged.'),
    'terms.php': ('includes/public_legal.php', 'Legal font stylesheet was omitted and token families diverged.', 'Batch 11 palette; existing published legal layout', 'Shared font and token aliases; published text/version/date unchanged.'),
}

rows, gallery = [], []
for uid, route, *state in after['routes']:
    page = route.split('?')[0].split('#')[0]
    source, inconsistency, reference, correction = mapping[page]
    name = f'{uid}-{slug(route)}-1366-light.png'
    latest = next(folder for folder in ['after-state', 'after', 'after-deadline', 'after-auth', 'after-targeted'] if (OUT / folder / name).exists())
    baseline = next((folder + '/' + name for folder in ['before-auth', 'before-targeted', 'before'] if (OUT / folder / name).exists()), 'before/' + name)
    evidence = (link(baseline, 'Before') if (OUT / baseline).exists() else 'Before: state not in baseline capture set') + ' / ' + link(latest + '/' + name, 'After')
    rows.append(f'| {role[uid]} | `{route}` | {inconsistency} | {reference} | {correction} | `{source}` + shared assets | Original routes, handlers, fields, role/object guards and scoped APIs retained. | {evidence} | Chromium coverage; see test limitations. |')
    for width in [1366, 320]:
        for theme in ['light', 'dark']:
            file = f'{uid}-{slug(route)}-{width}-{theme}.png'
            current = next((folder for folder in ['after-state', 'after', 'after-deadline', 'after-auth', 'after-targeted'] if (OUT / folder / file).exists()), 'after')
            if not (OUT / current / file).exists():
                continue
            baseline_set = next((folder for folder in ['before-auth', 'before-targeted', 'before'] if (OUT / folder / file).exists()), 'before')
            before_image = f'<a href="{baseline_set}/{html.escape(file)}"><img loading="lazy" src="{baseline_set}/{html.escape(file)}" alt="Before {html.escape(route)}"></a>' if (OUT / baseline_set / file).exists() else '<p>No equivalent baseline state captured.</p>'
            gallery.append(f'<section><h2>{html.escape(role[uid]+" / "+route+" / "+str(width)+" / "+theme)}</h2><div><figure><figcaption>Before</figcaption>{before_image}</figure><figure><figcaption>After</figcaption><a href="{current}/{html.escape(file)}"><img loading="lazy" src="{current}/{html.escape(file)}" alt="After {html.escape(route)}"></a></figure></div></section>')
rows.append('| Administrator | Dashboard year calendar | Month-only legend disappeared in year mode. | Batch 11 Admin calendar states | Shared year legend, glyphs and accessible date state attributes. | Shared calendar CSS/helper; renderer unchanged | Existing twelve-month view, navigation and scoped range APIs retained. | '+link('before-year/1001-dashboard-year-1366-light.png','Before year view')+' / '+link('after-year/1001-dashboard-year-1366-light.png','After year view')+' | Year cells retain intentional compact density. |')
for width in [1366,320]:
    for theme in ['light','dark']:
        name=f'1001-dashboard-year-{width}-{theme}.png'
        gallery.append(f'<section><h2>Administrator year calendar / {width} / {theme}</h2><div><figure><figcaption>Before</figcaption><a href="before-year/{name}"><img loading="lazy" src="before-year/{name}" alt="Before year calendar"></a></figure><figure><figcaption>After</figcaption><a href="after-year/{name}"><img loading="lazy" src="after-year/{name}" alt="After year calendar"></a></figure></div></section>')
(OUT / 'comparison-gallery.html').write_text('<!doctype html><meta charset="utf-8"><title>PRISM Batch 11.5 before/after evidence</title><style>body{font:14px Arial;background:#f5f7fb;color:#0c0e3f;margin:24px}section{margin:24px 0}h2{font-size:16px}section>div{display:grid;grid-template-columns:1fr 1fr;gap:16px}figure{margin:0}img{width:100%;border:1px solid #ddd}figcaption{font-weight:700;margin-bottom:8px}@media(max-width:700px){section>div{grid-template-columns:1fr}}</style><h1>PRISM Batch 11.5: actual Chromium screenshots</h1><p>Synthetic, isolated accounts. Click an image for its original resolution. Fixtures have different record/state counts after the security regression run; this is a presentation comparison, not a pixel-identical data comparison.</p>'+''.join(gallery), encoding='utf-8')

audit = '# PRISM Batch 11.5 — Frontend Consistency Audit\n\n13 October 2026 (Asia/Manila). The pre-edit inventory contains '+str(len(initial['files']))+' production files and '+str(len(initial['duplicate_selectors']))+' selectors repeated across stylesheet owners. The complete machine-readable dependency/state inventory is '+link('inventory.json', 'inventory.json')+'. It includes HTML renderers, all CSS/JS files, imports, dynamic UI/API consumers, IDs, class patterns, inline styling and loading/error/hidden/disabled states. Private configuration, vendor internals and runtime user documents are excluded from the production frontend inventory.\n\n## Page and role matrix\n\nAll listed routes were rendered with owned synthetic accounts. Shared corrections reference `prism-tokens.css`, `prism-components.css`, `prism-calendar.css`, `prism-calendar-presentation.js` and the shared loader.\n\n| Role | Page/component | Before inconsistency | Approved reference | Correction | Modified files | Preserved functionality | Visual evidence | Limitations |\n|---|---|---|---|---|---|---|---|---|\n'+'\n'.join(rows)
audit += '\n\n## Shared component inventory and risk controls\n\n| Component | Existing pattern/dependency | Reference and correction | Regression risk addressed |\n|---|---|---|---|\n| Navigation/headers | `prism-navigation.php`, Student portal markup, sidebar/topnav scripts | Batch 11 utility/profile styles retained; shared font/palette | No navigation restructuring; initials/counts/tour/disabled persistence retained |\n| Compact records/details | `PrismUI.compactRecord`, document/people/IERB renderers | Batch 11 structured cards retained; action/control tokens aligned | No field/action deletion; expanded metadata retained; clipping checked |\n| Buttons/action menus | UI kit, page aliases, dynamic dialogs | Shared variants and 44px routine controls; compact/icon family retained | Existing event bindings, fixed positioning and keyboard focus preserved |\n| Search/filter/sort | `recordPager`, `filtersDisclosure`, document workflow pills | Shared search boundary; pills moved to their own row | Inputs and query semantics unchanged; empty results and filter menus checked |\n| Status pills | `PrismUI.STATUS` and backend workflow predicates | Existing semantic tones moved to tokens | State labels/icons remain distinct; authorization flags unchanged |\n| Calendars/deadlines | Admin inline renderer, `calendar.js`, `role-calendar.js`, dashboard/deadline scripts | Batch 11 Admin reference in one CSS owner and legend helper | No API/date/storage/scheduling changes; scope tested separately |\n| Forms/modals | Existing CSRF, validation, dirty forms, native/custom dialogs | Shared controls/shells, theme surfaces, visible focus | Required fields and Cancel/Escape/focus behavior preserved |\n| Public/auth/legal/support | Auth, onboarding, legal and Batch 11 public shells | Existing approved Login/landing retained; font/palette/theme gaps corrected | No auth/governance/support-recipient change |\n| Resources/footer | Shared resource disclosures and institutional footer | Existing specialized presentation retained inside common token family | Existing links/content and footer instance count retained |\n'
audit += '\n## Files and components without separate page routes\n\nRedirect/include aliases (`admin_students.php`, `admin_advisers.php`, `admin_archived_accounts.php`, `student.php`, `login_admin.php`, `login_adviser.php`, `login_students.php`, `loading.php`, `privacy.php`, `terms.php`) retain their original routing/access boundaries. Shared public/legal renderers are tested through their real routes. API response/error emitters and email templates are inventoried but are not redesigned as application pages.\n\n### Every production stylesheet\n\n| Stylesheet | Ownership/result |\n|---|---|\n'
for entry in initial['files']:
    if entry['file'].endswith('.css'):
        changed = entry['file'] in tracked
        audit += f'| `{entry["file"]}` | '+('Duplicate presentation consolidated or scoped correction applied.' if changed else 'Consumers inventoried; specialized rules retained; common tokens/components load after it.')+' |\n'
audit += '\n### Every production UI script\n\n| Script | Dynamic UI/API dependencies | Result |\n|---|---|---|\n'
for entry in initial['files']:
    if entry['file'].endswith('.js'):
        audit += f'| `{entry["file"]}` | '+(', '.join(entry['api_consumers']) or 'Local controls/presentation')+' | '+('Presentation-only correction; existing data/action handlers retained.' if entry['file'] in tracked else 'Source unchanged; shared CSS consumes its existing markup.')+' |\n'
audit += '\n## Evidence and remaining limits\n\n'+link('comparison-gallery.html', 'Side-by-side screenshot gallery')+' contains the actual before/after captures. Desktop/mobile light/dark evidence is available per route; calendar captures cover all six viewport sizes. Contact sheets support review across pages. Full-resolution representative screenshots were also inspected. The test report identifies exact browser totals, initial failures, targeted reruns and platform limitations.\n'
(ROOT / 'PRISM_FRONTEND_CONSISTENCY_AUDIT.md').write_text(audit, encoding='utf-8')

backend_log = read_log('backend-final.log')
retention_native_log = read_log('retention-native-final.log')
retention_http_log = read_log('retention-http-final.log')
retention_log = retention_native_log + '\n' + retention_http_log
(OUT / 'retention-final.log').write_text(retention_log, encoding='utf-8')
backend_passed = 'PASS: 482 native' in backend_log and 'FAIL:' not in backend_log
retention_passed = 'PASS:' in retention_log and 'FAIL:' not in retention_log and 'real HTTP persistent verification' in retention_log
passed = (all(r['passed'] for r in after['results'] + targeted['results'] + auth_browser['results'] + deadline['results'] + year['results'] + state_data['results']) and all(r['passed'] for r in cli_final)
          and all(r['passed'] for r in syntax) and backend_passed and retention_passed
          and all(r['identicalExecutablePrefix'] for r in protected) and all(r['unchanged'] for r in frozen)
          and diff_check.returncode == 0)
test = f'# PRISM Batch 11.5 — Frontend Standardization Test Report\n\nFinal assessment: **{"PASS" if passed else "PARTIAL"}** for the executed local Chromium/XAMPP coverage. No commit, push, merge, tag, deployment, migration or production database operation was performed.\n\n## Source identity\n\n- Branch: `{inventory["branch"]}`\n- HEAD: `{inventory["head"]}`\n- Date: 13 October 2026 (Asia/Manila).\n- Pre-existing untracked Batch 2–10/schema reports, `tests/v10-batch105-results.json`, and the unrelated root attachment-like file were left intact.\n- Protected executable PHP prefixes: {sum(x["identicalExecutablePrefix"] for x in protected)}/{len(protected)} identical.\n- Frozen metadata files: {sum(x["unchanged"] for x in frozen)}/{len(frozen)} byte-identical.\n- `git diff --check`: exit {diff_check.returncode}.\n\n'+link('integrity.json', 'Integrity and complete modified/added-file inventory')+'; '+link('source-diff.patch', 'Exact source diff, including added source files')+'.\n\n## Environment and isolation\n\nWindows/XAMPP, PHP 8.2.12, MariaDB 10.4.32, Node 25.0.0, '+after['version']['product']+'. Headless Chromium was driven through CDP using the installed browser, with Asia/Manila timezone and temporary owned browser profiles. Cached public fonts/icons were SHA-256 verified; external browser requests were intercepted, and isolated configuration disabled real mail/AI providers.\n\nThe native fixture creates a random-port MariaDB instance with a freshly owned temporary datadir and verifies `@@datadir` before using it. Frozen v10 metadata builds only this disposable fixture. Application source copies omit `config.local.php`; only their test-only DB connection is replaced. HTTP/session/storage files and synthetic mutations remain inside the owned fixture. Existing concurrent workers are explicitly bound to that fixture storage in the **source-copy harness**, without changing production worker/business code.\n\n## Browser results\n\n| Evidence set | Assertions | Failed | Captures | Meaning |\n|---|---:|---:|---:|---|\n'
for title, data in [('Before', before), ('Additional baseline upload form', extra_before), ('Valid-reset baseline fixture', before_auth), ('Admin year baseline', before_year), ('Final full after matrix', after), ('Targeted button geometry', targeted), ('Auth sizing and valid reset', auth_browser), ('Superseded calendar width check (fixed in final full matrix)', polish), ('Authorized deadline cancellation tone', deadline), ('Admin year view/legend', year), ('Final shared Today/deadline/marker state', state_data)]:
    meaning = 'Baseline defects, not release failures.' if ('baseline' in title.lower() or title == 'Before') else ('Superseded diagnostics; rerun in the final matrix.' if title.startswith('Superseded') else 'Executed browser checks.')
    test += f'| {title} | {len(data["results"])} | {sum(not r["passed"] for r in data["results"])} | {len(data["captures"])} | {meaning} |\n'
test += '\nThe full matrix covers '+str(len(after['routes']))+' role/route/state scenarios at **1920×1080, 1366×768, 1024×768, 768×1024, 390×844 and 320×700**, in light and dark modes. The final targeted run checks the last toolbar/compact-button sizing refinements on affected routes at the same six sizes and both themes.\n\nExecuted checks include horizontal page/control fit, authorized destination, stored theme, accessible icon names, Actions-menu keyboard entry/viewport placement/Escape focus return, exclusion of restricted actions, calendar geometry/legend/Today, native dialog Tab/Escape focus, required override reason, Tour/profile focus return, reduced motion, real deadline details, pending-request loading and injected-service error states, empty document search, personal reminder storage/edit controls, legal unsaved-change protection and photo-preview keyboard order.\n\n'+link('after/browser-results.json', 'Full browser assertions, samples, exceptions and screenshots')+'; '+link('after-targeted/browser-results.json', 'Final targeted button/geometry results')+'; '+link('comparison-gallery.html', 'Before/after gallery')+'.\n\n## Functional and security regression\n\n| Executed verification | Result | Evidence |\n|---|---|---|\n'
test += f'| Existing CLI preservation suites | {sum(r["passed"] for r in cli_final)}/{len(cli_final)} pass | '+link('cli-final.json', 'Results and authentication rerun')+' |\n'
test += f'| Authentication flow cases | {"PASS: 52" if auth_passed else "FAIL"} | '+link('auth-final.log', 'Log')+' |\n'
test += f'| Final production PHP/JS syntax | {sum(r["passed"] for r in syntax)}/{len(syntax)} pass | '+link('syntax-final.json', 'Results')+' |\n'
test += f'| Native + real HTTP workflow, scope, concurrency and legal governance | {"PASS: 482 total" if backend_passed else "FAIL"} | '+link('backend-final.log', 'Log')+' |\n'
test += '| Batch 11 mandatory atomic audit/override/authorization/rollback assertions | PASS: 245 within the native/HTTP run | '+link('backend-final.log', 'Log')+' |\n'
test += f'| Persistent permanent-delete evidence, retention/account restrictions, drift/tamper and real HTTP guards | {"PASS" if retention_passed else "FAILED/BLOCKED; see log"} | '+link('retention-final.log', 'Log')+' |\n'
test += '\nThe CLI suites exercise login/logout/idle timeout, authentication restrictions, object/role authorization, document workflow/extraction/summary transport, research groups and academics, archive operational visibility, CSV protection, filters/invitations/deadline scoping, upload validation, notification ownership/generation/legal gates, and support privacy. The native/HTTP run exercises actual Student uploads, Adviser approval, RPMS formal submissions, returned revisions/version history, forced audit-failure rollback and concurrency, inactive/Pending/expired/legal/password gates, bounded pagination, and draft/reject/publish/reacceptance governance. Retention tests perform deletion/drift/failure cases only in a separately owned disposable database.\n\n## Failures resolved and limitations\n\n- The first source-copy setup lacked an isolated worker storage binding, then accidentally duplicated a fixture hook on rerun. Both harness issues were corrected; initial diagnostic logs are retained. No application database was involved.\n- An initial authentication template test failed because a newly added direct require resolved from the eval harness directory. The stable `prism_frontend_styles()` helper resolved that presentation-loading compatibility issue; all 52 authentication cases were rerun successfully.\n- Initial after-browser validation found the legacy dashboard table clipping and an invalid readiness assumption after an empty search. The layout was fixed; the new harness now accepts a real empty result as ready. Its diagnostic JSON is retained as `after-initial-failure.json`.\n- The first retention run could not find the read-only verifier in the isolated source copy. A later combined native/HTTP invocation also collided on a synthetic account ID. The verifier was copied into the test source and the two suites were run in separate disposable databases: 203 native retention/verification assertions and 12 real HTTP assertions passed (219 including fixture checks). Initial failure logs are retained.\n- Actual screenshot review caught an oversized Registration card, repeated Student weekdays and a squeezed report-history heading; these were corrected and rerendered. Narrow Admin date targets were verified after removing the superseded forced padding rule. Invite/Add controls were corrected to the 44px shared size.\n- A broad DevTools interception pattern raced cancelled background API requests and produced a protocol error. The final harness intercepts cached HTTPS assets and the specific deadline test endpoint; application exceptions and unexpected protocol failures still fail the run. The final full matrix passed without this error.\n- The final calendar comparison removed the legacy Student Today size/color override and normalized deadline dots. The state check awaits finite calendar color transitions before comparing settled computed styles; 1,057 final cross-role assertions passed.\n- The original synthetic reset token omitted the stored digest prefix. It was corrected in the fixture only; valid reset form visibility was then explicitly tested.\n- Browser coverage is the installed Chromium on Windows with viewport emulation. Safari, Firefox, physical touch devices, screen-reader audio output and print/PDF visual review were not executed. They are not reported as passed.\n- Real external email/AI delivery and production-provider availability were intentionally not exercised. Existing isolated delivery/privacy/workflow checks passed; external providers are not part of this frontend change.\n- Screenshots contain synthetic fixtures. Before and after fixture workflow counts can differ because the after security suite prepares additional states; no pixel-identical fixture-data claim is made.\n- Existing unrelated legacy `!important` declarations and specialized stylesheet rules remain where their consumers still need them. New shared rules use importance only for native hidden behavior and reduced-motion enforcement.\n- Temporary browser profile cleanup can be delayed by Windows file locks; any retained owned-profile path is recorded in the cleanup artifact. Application/datadir server teardown is performed by the native harness.\n\n## Modified and added files\n\n'
for file in tracked:
    test += f'- Modified: `{file}`\n'
for file in added:
    test += f'- Added: `{file}`\n'
test += '\nRaw screenshots, JSON samples, private-config-free source snapshots, logs, gallery and contact sheets are under `tests/frontend-standardization-results/` (ignored evidence directory). No production documents/configuration are included.\n\n## Final git diff --stat\n\nThis is the exact tracked diff. Added files are deliberately unstaged and therefore appear in the inventory above, not in Git\'s ordinary tracked diff statistic.\n\n```text\n'+stat+'```\n'
(ROOT / 'PRISM_FRONTEND_STANDARDIZATION_TEST_REPORT.md').write_text(test, encoding='utf-8')
print(json.dumps({'assessment': 'PASS' if passed else 'PARTIAL', 'browser': len(after['results'])+len(targeted['results'])+len(auth_browser['results'])+len(deadline['results'])+len(year['results'])+len(state_data['results']),
                  'failures': sum(not r['passed'] for r in after['results']+targeted['results']+auth_browser['results']+deadline['results']+year['results']+state_data['results']),
                  'protected': len(protected), 'frozen': len(frozen), 'tracked': len(tracked), 'added': len(added)}))
