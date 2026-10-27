"""Render actual PDFs and check page bounds; install PyMuPDF in a workspace-local tools folder."""
from pathlib import Path
import json, sys
root=Path(__file__).resolve().parents[2]
sys.path.insert(0,str(root/'.batch04-tools'))
import pymupdf
evidence=root/'PRISM_V14_BATCH_04_EVIDENCE'
result=[]
for source in sorted(evidence.glob('*.pdf')):
    doc=pymupdf.open(source)
    pages=[]
    all_text=''
    for i,page in enumerate(doc):
        text=page.get_text()
        assert f'Page {i+1} / {len(doc)}' in text,(source.name,i,'footer')
        assert 'CEU RPMS' in text,(source.name,i,'header')
        for block in page.get_text('dict')['blocks']:
            for line in block.get('lines',[]):
                for span in line['spans']:
                    x0,y0,x1,y1=span['bbox']
                    assert x0>=45 and x1<=565 and y0>=12 and y1<=770,(source.name,i,span)
        image=evidence/f'{source.stem}-page-{i+1}.png'
        page.get_pixmap(matrix=pymupdf.Matrix(1.4,1.4)).save(str(image))
        pages.append(image.name)
        all_text+=text
    if 'summary' not in source.name and 'institutional' not in source.name:
        assert all_text.count('1st Resubmission')>=1,source.name
        assert '3rd Resubmission' in all_text,source.name
        assert '4th Resubmission' not in all_text and '5th Resubmission' not in all_text,source.name
        assert 'Principal Investigator' in all_text and 'Co-Investigators' in all_text,source.name
    if source.stem=='layout-stress': assert len(doc)>=3,'Stress fixture must span at least three pages'
    result.append({'pdf':source.name,'pages':len(doc),'images':pages,'bounds':'PASS','headersFooters':'PASS'})
assert result,'No PDFs rendered'
(evidence/'pdf-rendering.json').write_text(json.dumps({'status':'PASS','renderer':pymupdf.__version__,'reports':result},indent=2)+'\n',encoding='utf-8')
print(json.dumps({'status':'PASS','pdfs':len(result),'pages':sum(x['pages'] for x in result)}))
