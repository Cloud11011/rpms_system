const [dir] = process.argv.slice(2); const fs = require('fs');
const b = JSON.parse(fs.readFileSync(dir + '/fingerprint-before.json')), a = JSON.parse(fs.readFileSync(dir + '/fingerprint-after.json'));
const res = { preserved: [], changed: [], added: Object.keys(a).filter(k => !(k in b)).map(k => ({ table: k, rows: a[k].rows })) };
for (const k of Object.keys(b)) (b[k].sha256 === a[k]?.sha256 ? res.preserved : res.changed).push({ table: k, before: b[k].rows, after: a[k]?.rows });
fs.writeFileSync(dir + '/fingerprint-comparison.json', JSON.stringify(res, null, 2));
console.log('preserved', res.preserved.length, 'changed', JSON.stringify(res.changed), 'added', JSON.stringify(res.added));
