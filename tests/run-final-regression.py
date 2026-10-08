"""Run local CLI fixtures and retain exact output; never load production configuration."""
from pathlib import Path
import subprocess, json, sys
root=Path(__file__).resolve().parent.parent
dest=root/'tests'/'final-regression-results'
dest.mkdir(exist_ok=True)
suites=['auth-audit','auth-flows','session-idle-audit','security-audit','rate-limit-audit','logout-audit',
'crud-audit','document-workflow-audit','notification-delivery-audit','research-group-audit','academic-audit',
'academic-api-audit','alignment-audit','report-format-audit','backend-audit','tonight-polish-audit','readiness-audit',
'email-format-audit','cache-audit','final-regression-audit','hardening-audit','calendar-deadlines-audit',
'document-extraction-audit','document-summary-audit','document-summary-transport-audit','archive-operational-audit','csv-export-audit']
results=[]
for name in suites:
    command=['C:/xampp/php/php.exe','-d','extension=zip',f'tests/{name}.php']
    r=subprocess.run(command,cwd=root,capture_output=True,text=True,encoding='utf-8',errors='replace')
    output=r.stdout+r.stderr
    (dest/f'{name}.log').write_text(output,encoding='utf-8')
    ok=r.returncode==0 and ('PASS' in output or 'passed' in output.lower()) and not r.stderr and not any(x in output for x in ['Fatal error:','Warning:','FAIL:'])
    passing_lines=[line for line in output.splitlines() if 'PASS' in line or 'passed' in line.lower()]
    summary=passing_lines[-1] if passing_lines else '(no success marker)'
    results.append(dict(command=' '.join(command),passed=ok,exit=r.returncode,summary=summary))
    print(('PASS' if ok else 'FAIL')+': '+name+' - '+summary,flush=True)
    if not ok: print(output[-2500:],flush=True)
(dest/'cli-results.json').write_text(json.dumps(results,indent=2),encoding='utf-8')
sys.exit(0 if all(r['passed'] for r in results) else 1)
