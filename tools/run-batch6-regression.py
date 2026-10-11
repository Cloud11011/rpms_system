"""Run isolated CLI fixtures and syntax checks; never bootstrap installed application services."""
from pathlib import Path
import subprocess,json,sys
root=Path(__file__).resolve().parent.parent
out=root/'tests/v10-batch6-results';out.mkdir(exist_ok=True)
php='C:/xampp/php/php.exe'
suites=['auth-flows','crud-audit','document-workflow-audit','notification-delivery-audit','research-group-audit','academic-api-audit','alignment-audit','report-format-audit','backend-audit','tonight-polish-audit','hardening-audit','calendar-deadlines-audit','document-extraction-audit','document-summary-audit','document-summary-transport-audit','archive-operational-audit','csv-export-audit','v10-batch2-readonly','v10-batch3-filters','v10-batch3-invitations','v10-batch3-deadlines','v10-batch4-security','v10-batch45-security','v10-batch5a-routing','v10-batch5b-session','v10-batch5b-upload-validation','v10-batch6-upload-validation']
results=[]
for name in ([] if '--syntax-only' in sys.argv else suites):
    cmd=[php,'-d','extension=zip','tests/'+name+'.php'];r=subprocess.run(cmd,cwd=root,capture_output=True,text=True,encoding='utf-8',errors='replace')
    text=r.stdout+r.stderr;(out/(name+'.log')).write_text(text,encoding='utf-8')
    ok=r.returncode==0 and not r.stderr and not any(x in text for x in ['Fatal error:','Warning:','FAIL:'])
    results.append(dict(suite=name,passed=ok,exit=r.returncode,summary=text.strip().splitlines()[-1] if text.strip() else ''))
    print(('PASS' if ok else 'FAIL')+': '+name,flush=True)
    if not ok: print(text[-1800:],flush=True)
if results: (out/'regression-results.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
syntax=[]
paths=list(root.glob('*.php'))+list((root/'includes').rglob('*.php'))+list((root/'tools').glob('*.php'))+list((root/'tests').glob('*.php'))
for p in paths:
    if p.name=='config.local.php':continue
    r=subprocess.run([php,'-l',str(p)],cwd=root,capture_output=True,text=True);syntax.append({'file':str(p.relative_to(root)), 'passed':r.returncode==0})
paths=list((root/'assets/js').glob('*.js'))+list((root/'tests').glob('*.cjs'))+list((root/'tools').glob('*.cjs'))
js=[]
for p in paths:
    r=subprocess.run(['node','--check',str(p)],cwd=root,capture_output=True,text=True);js.append({'file':str(p.relative_to(root)), 'passed':r.returncode==0})
(out/'syntax-results.json').write_text(json.dumps({'php':syntax,'js_cjs':js},indent=2),encoding='utf-8')
print(f'Syntax: {len(syntax)} PHP, {len(js)} JS/CJS',flush=True)
sys.exit(0 if all(r['passed'] for r in results+syntax+js) else 1)
