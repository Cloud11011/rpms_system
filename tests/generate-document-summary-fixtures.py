"""Build non-sensitive format/security fixtures; the real PLOS PDF is retained separately."""
from pathlib import Path
from zipfile import ZipFile, ZIP_DEFLATED
from xml.sax.saxutils import escape
import json, hashlib, zlib

dest = Path(__file__).parent / 'fixtures' / 'document-summary'
dest.mkdir(parents=True, exist_ok=True)
paragraphs = [
    'Student name: Maria Santos', 'Student ID: 2026-12345',
    'Protocol code: CEU-IERB-2026-071', 'Email: maria@example.test',
    'Purpose: This research protocol examines access to digital library services among university students.',
    'Methodology: The study uses an anonymous questionnaire and descriptive analysis of responses.',
    'Sampling: Recruitment is voluntary and participants may decline any question without penalty.',
    'Ethics: Participants will provide informed consent before completing the questionnaire.',
    'Privacy: The team will store de-identified responses in an access-controlled university repository.',
    'Submission information: The protocol and participant information sheet are supplied for IERB review.',
    'Approval date: Not recorded. The protocol does not assert that ethical approval has been granted.',
    'Limitations: Convenience sampling may limit applicability beyond the participating university.',
    'Data handling: The research team will delete contact information after recruitment.',
    'Analysis: Counts and percentages will describe access patterns; no causal claims are planned.',
    'Résumé: café resources are available. A participant may withdraw before anonymization.'
]
(dest / 'research-protocol.txt').write_text('\n'.join(paragraphs), encoding='utf-8')
body = ''.join('<w:p><w:r><w:t xml:space="preserve">' + escape(p) + '</w:t></w:r></w:p>' for p in paragraphs)
body += '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Table methodology</w:t></w:r></w:p></w:tc><w:tc><w:p><w:r><w:t>Anonymous questionnaire</w:t></w:r></w:p></w:tc></w:tr></w:tbl>'
xml = '<?xml version="1.0" encoding="UTF-8"?><w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>' + body + '</w:body></w:document>'
def docx(name, document):
    with ZipFile(dest / name, 'w', ZIP_DEFLATED) as z:
        z.writestr('[Content_Types].xml', '<?xml version="1.0"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/></Types>')
        z.writestr('_rels/.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/></Relationships>')
        z.writestr('word/document.xml', document)
docx('research-protocol.docx', xml)
docx('external-entity.docx', '<?xml version="1.0"?><!DOCTYPE foo [<!ENTITY secret SYSTEM "file:///never-read-this">]>' + xml.split('?>', 1)[1].replace('Table methodology', '&secret;'))
with ZipFile(dest / 'invalid-container.docx', 'w') as z: z.writestr('not-a-document', 'invalid')
rtf = '{\\rtf1\\ansi\\ansicpg1252\\uc1{\\fonttbl{\\f0 Times New Roman;}}{\\info Hidden metadata}{\\*\\private Hidden identifier}'
for p in paragraphs[:-1]: rtf += p.replace('\\','\\\\').replace('{','\\{').replace('}','\\}') + '\\par '
rtf += "R\\u233?sum\\u233?: caf\\'e9 resources. {\\object hidden executable content}Participant withdrawal is allowed.}"
(dest / 'research-protocol.rtf').write_text(rtf, encoding='ascii')

def pdf(name, objects, trailer_extra=''):
    data = b'%PDF-1.4\n'; offsets = [0]
    for i, obj in enumerate(objects, 1):
        offsets.append(len(data)); data += f'{i} 0 obj\n'.encode() + obj + b'\nendobj\n'
    start = len(data); data += f'xref\n0 {len(offsets)}\n0000000000 65535 f \n'.encode()
    for offset in offsets[1:]: data += f'{offset:010d} 00000 n \n'.encode()
    data += f'trailer\n<< /Size {len(offsets)} /Root 1 0 R {trailer_extra} >>\nstartxref\n{start}\n%%EOF'.encode()
    (dest / name).write_bytes(data)
image = zlib.compress(b'\xff\xff\xff')
content = b'q 100 0 0 100 50 500 cm /Im0 Do Q'
pdf('scanned-image.pdf', [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /Im0 5 0 R >> >> /Contents 4 0 R >>',
    f'<< /Length {len(content)} >>\nstream\n'.encode()+content+b'\nendstream',
    f'<< /Type /XObject /Subtype /Image /Width 1 /Height 1 /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /FlateDecode /Length {len(image)} >>\nstream\n'.encode()+image+b'\nendstream'])
(dest / 'malformed.pdf').write_bytes(b'%PDF-1.4\nThis is not a valid PDF object tree.')
cmap = b'/CIDInit /ProcSet findresource begin 12 dict begin begincmap\n1 beginbfrange\n<00000000><ffffffff><0000> endbfrange\nendcmap end end'
text_stream = b'BT /F1 12 Tf 50 700 Td <0001> Tj ET'
pdf('font-budget.pdf', [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 5 0 R >> >> /Contents 4 0 R >>',
    f'<< /Length {len(text_stream)} >>\nstream\n'.encode()+text_stream+b'\nendstream',
    b'<< /Type /Font /Subtype /Type0 /BaseFont /Fixture /Encoding /Identity-H /ToUnicode 6 0 R >>',
    f'<< /Length {len(cmap)} >>\nstream\n'.encode()+cmap+b'\nendstream'])
pdf('encrypted.pdf', [b'<< /Type /Catalog /Pages 2 0 R >>', b'<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
    b'<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] >>',
    b'<< /Filter /Standard /V 1 /R 2 /Length 40 >>'], '/Encrypt 4 0 R')
(dest / 'unsupported.doc').write_text('Legacy format', encoding='ascii')
manifest = {'academic-research.pdf': {
    'title': 'Registered report: How open do you want your science? An international investigation into knowledge and attitudes of psychology students',
    'authors': 'Jarke H, Jakob L, Bojanic L, Garcia-Garzon E, Mareva S, Mutak A, Gjorgjiovska J (2022)',
    'doi': '10.1371/journal.pone.0261260', 'license': 'Creative Commons Attribution',
    'source': 'https://journals.plos.org/plosone/article/file?id=10.1371/journal.pone.0261260&type=printable',
    'modified': False}, 'other_fixtures': 'Original PRISM test data with fictional identities; generated by this script.'}
sample = dest / 'academic-research.pdf'
if sample.exists(): manifest['academic-research.pdf']['sha256'] = hashlib.sha256(sample.read_bytes()).hexdigest()
(dest / 'sources.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
print('Format fixtures generated.')
