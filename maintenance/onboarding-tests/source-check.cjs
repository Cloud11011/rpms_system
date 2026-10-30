const fs=require('node:fs'),path=require('node:path'),cp=require('node:child_process'),crypto=require('node:crypto');
const root=path.resolve(__dirname,'../..'),file=process.argv[3];
if(!file)throw Error('Usage: source-check.cjs capture|verify <local evidence JSON>');
const files=[...new Set(cp.execFileSync('git',['ls-files','--cached','--others','--exclude-standard','-z'],{cwd:root,encoding:'utf8'}).split('\0'))]
 .filter(f=>f&&(/^(assets|includes|maintenance)\//.test(f)||!f.includes('/'))&&/\.(php|js|cjs|css|json|ps1|py)$/.test(f)).sort();
const hashes=Object.fromEntries(files.map(f=>[f,crypto.createHash('sha256').update(fs.readFileSync(path.join(root,f))).digest('hex')]));
if(process.argv[2]==='capture'){fs.mkdirSync(path.dirname(file),{recursive:true});fs.writeFileSync(file,JSON.stringify(hashes,null,2));console.log('Captured '+files.length+' source hashes.');}
else {const before=JSON.parse(fs.readFileSync(file));if(JSON.stringify(before)!==JSON.stringify(hashes))throw Error('Source changed after validation began.');console.log('PASS: all '+files.length+' source hashes match the validated source.');}
